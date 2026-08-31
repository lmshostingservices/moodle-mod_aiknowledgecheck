<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Capability enforcement tests for every declared external service.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_aiknowledgecheck;

use externallib_advanced_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Drives every service listed in db/services.php so a new one cannot ship unguarded.
 *
 * @runTestsInSeparateProcesses
 * @coversNothing
 */
final class permissions_test extends externallib_advanced_testcase {
    /** @var \stdClass The test course. */
    private $course;

    /** @var \stdClass The activity instance. */
    private $activity;

    /** @var \stdClass The course module. */
    private $cm;

    /** @var \stdClass A student. */
    private $student;

    /** @var \mod_aiknowledgecheck_generator The plugin generator. */
    private $generator;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->generator = $this->getDataGenerator()->get_plugin_generator('mod_aiknowledgecheck');
        $this->activity = $this->generator->create_instance(['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('aiknowledgecheck', $this->activity->id);
        $this->generator->create_questions($this->activity->id, 2);
    }

    /**
     * Load the plugin's declared services.
     *
     * @return array The $functions array from db/services.php.
     */
    private function declared_services(): array {
        global $CFG;
        $functions = [];
        include($CFG->dirroot . '/mod/aiknowledgecheck/db/services.php');
        return $functions;
    }

    /**
     * Build a minimally valid argument list from a service's declared parameters.
     *
     * @param \core_external\external_function_parameters $params The declared parameters.
     * @return array Arguments keyed by parameter name.
     */
    private function dummy_args($params): array {
        $args = [];
        foreach ($params->keys as $name => $desc) {
            if ($name === 'cmid') {
                $args[$name] = (int)$this->cm->id;
                continue;
            }
            $args[$name] = $this->dummy_for($desc);
        }
        return $args;
    }

    /**
     * Produce a placeholder value matching a declared parameter description.
     *
     * @param mixed $desc An external_value, external_single_structure or external_multiple_structure.
     * @return mixed A value of the right shape.
     */
    private function dummy_for($desc) {
        if ($desc instanceof \core_external\external_multiple_structure) {
            return [];
        }
        if ($desc instanceof \core_external\external_single_structure) {
            $out = [];
            foreach ($desc->keys as $k => $d) {
                $out[$k] = $this->dummy_for($d);
            }
            return $out;
        }
        switch ($desc->type) {
            case PARAM_INT:
                return 1;
            case PARAM_BOOL:
                return false;
            case PARAM_FLOAT:
                return 0.0;
            default:
                return 'x';
        }
    }

    /**
     * Invoke a service's execute() with placeholder arguments in declaration order.
     *
     * The AJAX entry point, call_external_function(), finishes with require_sesskey(), which
     * reads the session key out of the request — driving the services that way meant writing to
     * a request superglobal from a test. execute() does the same validate_parameters() and
     * require_capability() work with no request involved, so the capability behaviour under
     * test is reached the same way and nothing has to be faked.
     *
     * @param string $classname The external service class.
     * @return mixed Whatever the service returns.
     */
    private function invoke(string $classname) {
        $args = $this->dummy_args($classname::execute_parameters());
        return call_user_func_array([$classname, 'execute'], array_values($args));
    }

    /**
     * Every service declares the capability it enforces.
     */
    public function test_every_service_declares_a_capability(): void {
        foreach ($this->declared_services() as $name => $definition) {
            $this->assertArrayHasKey('capabilities', $definition, "$name must declare a capability.");
            $this->assertNotEmpty($definition['capabilities'], "$name must declare a non-empty capability.");
            $this->assertArrayHasKey('classname', $definition, "$name must declare a classname.");
            $this->assertTrue(
                class_exists($definition['classname']),
                "$name points at a class that does not exist: {$definition['classname']}"
            );
        }
    }

    /**
     * A student is refused by every service that requires the teacher capability.
     *
     * The capability check runs before any call to the remote AI service, so no network access
     * is needed here — and if one of these ever stopped checking, this test would fail rather
     * than quietly reach out to the API as a student.
     */
    public function test_students_are_refused_by_every_teacher_service(): void {
        $this->setUser($this->student);
        $checked = 0;

        foreach ($this->declared_services() as $name => $definition) {
            if ($definition['capabilities'] !== 'mod/aiknowledgecheck:create') {
                continue;
            }
            $classname = $definition['classname'];

            try {
                $this->invoke($classname);
                $this->fail("$name must refuse a student, but it returned normally.");
            } catch (\required_capability_exception $e) {
                $checked++;
            }
        }

        $this->assertGreaterThanOrEqual(9, $checked, 'Expected the teacher-only services to be covered.');
    }

    /**
     * The student-facing services accept an enrolled student.
     */
    public function test_students_are_allowed_by_the_view_services(): void {
        $this->setUser($this->student);

        $result = \mod_aiknowledgecheck\external\get_questions::execute((int)$this->cm->id);

        $this->assertTrue($result['ok'], 'An enrolled student must be able to read questions.');
    }

    /**
     * A user with no enrolment is refused even by the student-facing services.
     */
    public function test_unenrolled_users_are_refused(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\require_login_exception::class);
        \mod_aiknowledgecheck\external\get_questions::execute((int)$this->cm->id);
    }
}
