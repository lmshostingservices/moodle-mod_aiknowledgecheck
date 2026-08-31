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
 * Tests for the mod_aiknowledgecheck library functions.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_aiknowledgecheck;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/aiknowledgecheck/lib.php');

/**
 * Tests for attempt limits, overrides, grading and the instance lifecycle.
 *
 * @covers ::aiknowledgecheck_effective_maxattempts
 * @covers ::aiknowledgecheck_count_attempts
 * @covers ::aiknowledgecheck_can_attempt
 */
final class lib_test extends \advanced_testcase {
    /** @var \stdClass The test course. */
    private $course;

    /** @var \stdClass The test user. */
    private $user;

    /** @var \mod_aiknowledgecheck_generator The plugin generator. */
    private $generator;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->generator = $this->getDataGenerator()->get_plugin_generator('mod_aiknowledgecheck');
    }

    /**
     * Create an activity in the test course.
     *
     * @param array $params Instance field overrides.
     * @return \stdClass The instance record.
     */
    private function make_activity(array $params = []): \stdClass {
        return $this->generator->create_instance(['course' => $this->course->id] + $params);
    }

    /**
     * A maxattempts of 0 means unlimited, whatever has been used.
     */
    public function test_maxattempts_zero_is_unlimited(): void {
        $activity = $this->make_activity(['maxattempts' => 0]);

        $this->assertSame(0, aiknowledgecheck_effective_maxattempts($activity, $this->user->id));
        $this->assertTrue(aiknowledgecheck_can_attempt($activity, $this->user->id));

        for ($i = 0; $i < 25; $i++) {
            $this->generator->create_attempt($activity->id, $this->user->id, ['status' => 1]);
        }
        $this->assertTrue(
            aiknowledgecheck_can_attempt($activity, $this->user->id),
            'Unlimited attempts must not be exhausted by usage.'
        );
    }

    /**
     * Only completed attempts count against the limit.
     */
    public function test_only_completed_attempts_are_counted(): void {
        $activity = $this->make_activity(['maxattempts' => 3]);

        $this->generator->create_attempt($activity->id, $this->user->id, ['status' => 0]);
        $this->generator->create_attempt($activity->id, $this->user->id, ['status' => 0]);
        $this->assertSame(0, aiknowledgecheck_count_attempts($activity->id, $this->user->id));

        $this->generator->create_attempt($activity->id, $this->user->id, ['status' => 1]);
        $this->assertSame(1, aiknowledgecheck_count_attempts($activity->id, $this->user->id));
    }

    /**
     * The limit blocks a further attempt exactly when it is reached, not before.
     */
    public function test_attempt_limit_blocks_at_the_boundary(): void {
        $activity = $this->make_activity(['maxattempts' => 3]);

        for ($used = 0; $used < 3; $used++) {
            $this->assertTrue(
                aiknowledgecheck_can_attempt($activity, $this->user->id),
                "Should still be allowed with $used completed attempts of 3."
            );
            $this->generator->create_attempt($activity->id, $this->user->id, ['status' => 1]);
        }

        $this->assertFalse(
            aiknowledgecheck_can_attempt($activity, $this->user->id),
            'Should be blocked once 3 of 3 attempts are used.'
        );
    }

    /**
     * An override adds to the base limit rather than replacing it.
     */
    public function test_override_extends_the_limit(): void {
        $activity = $this->make_activity(['maxattempts' => 2]);

        $this->generator->create_attempt($activity->id, $this->user->id, ['status' => 1]);
        $this->generator->create_attempt($activity->id, $this->user->id, ['status' => 1]);
        $this->assertFalse(aiknowledgecheck_can_attempt($activity, $this->user->id));

        $this->generator->create_override($activity->id, $this->user->id, 2);

        $this->assertSame(4, aiknowledgecheck_effective_maxattempts($activity, $this->user->id));
        $this->assertTrue(aiknowledgecheck_can_attempt($activity, $this->user->id));
    }

    /**
     * A negative extraattempts value must never reduce the base limit.
     */
    public function test_negative_override_cannot_reduce_the_limit(): void {
        $activity = $this->make_activity(['maxattempts' => 3]);
        $this->generator->create_override($activity->id, $this->user->id, -5);

        $this->assertSame(
            3,
            aiknowledgecheck_effective_maxattempts($activity, $this->user->id),
            'A negative override must be clamped to zero extra attempts.'
        );
    }

    /**
     * One user's override must not affect another user.
     */
    public function test_override_is_scoped_to_one_user(): void {
        $activity = $this->make_activity(['maxattempts' => 1]);
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $this->generator->create_override($activity->id, $this->user->id, 5);

        $this->assertSame(6, aiknowledgecheck_effective_maxattempts($activity, $this->user->id));
        $this->assertSame(1, aiknowledgecheck_effective_maxattempts($activity, $other->id));
    }

    /**
     * Attempts on one activity must not count against another.
     */
    public function test_attempts_are_scoped_to_one_activity(): void {
        $first = $this->make_activity(['maxattempts' => 2]);
        $second = $this->make_activity(['maxattempts' => 2]);

        $this->generator->create_attempt($first->id, $this->user->id, ['status' => 1]);
        $this->generator->create_attempt($first->id, $this->user->id, ['status' => 1]);

        $this->assertFalse(aiknowledgecheck_can_attempt($first, $this->user->id));
        $this->assertTrue(aiknowledgecheck_can_attempt($second, $this->user->id));
    }

    /**
     * Deleting an instance removes its questions, attempts and overrides.
     */
    public function test_delete_instance_removes_child_records(): void {
        global $DB;

        $activity = $this->make_activity();
        $this->generator->create_questions($activity->id, 3);
        $this->generator->create_attempt($activity->id, $this->user->id, ['status' => 1]);
        $this->generator->create_override($activity->id, $this->user->id, 1);

        $survivor = $this->make_activity();
        $this->generator->create_questions($survivor->id, 2);

        aiknowledgecheck_delete_instance($activity->id);

        $this->assertSame(0, $DB->count_records('aiknowledgecheck_questions', ['aiknowledgecheckid' => $activity->id]));
        $this->assertSame(0, $DB->count_records('aiknowledgecheck_attempts', ['aiknowledgecheckid' => $activity->id]));
        $this->assertSame(0, $DB->count_records('aiknowledgecheck_overrides', ['aiknowledgecheckid' => $activity->id]));
        $this->assertFalse($DB->record_exists('aiknowledgecheck', ['id' => $activity->id]));

        $this->assertSame(
            2,
            $DB->count_records('aiknowledgecheck_questions', ['aiknowledgecheckid' => $survivor->id]),
            'Deleting one instance must not touch another instance.'
        );
    }

    /**
     * The module declares the feature flags Moodle relies on.
     */
    public function test_supports_declares_expected_features(): void {
        $this->assertTrue((bool)aiknowledgecheck_supports(FEATURE_GRADE_HAS_GRADE));
        $this->assertTrue((bool)aiknowledgecheck_supports(FEATURE_BACKUP_MOODLE2));
        $this->assertTrue((bool)aiknowledgecheck_supports(FEATURE_COMPLETION_TRACKS_VIEWS));
        $this->assertNull(aiknowledgecheck_supports('an_unknown_feature_constant'));
    }
}
