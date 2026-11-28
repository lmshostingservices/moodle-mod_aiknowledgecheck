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
 * Tests for the responsible AI controls (EU AI Act alignment, v1.8.0).
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_aiknowledgecheck;

use externallib_advanced_testcase;
use external_api;
use mod_aiknowledgecheck\external\approve_questions;
use mod_aiknowledgecheck\external\get_questions;
use mod_aiknowledgecheck\external\save_questions;
use mod_aiknowledgecheck\external\start_attempt;
use mod_aiknowledgecheck\local\responsibleai;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');
require_once($CFG->dirroot . '/mod/aiknowledgecheck/lib.php');

/**
 * Teacher approval, provenance, the site-wide pause and the AI activity log.
 *
 * @runTestsInSeparateProcesses
 * @covers \mod_aiknowledgecheck\local\responsibleai
 * @covers \mod_aiknowledgecheck\external\approve_questions
 * @covers \mod_aiknowledgecheck\external\save_questions
 */
final class responsibleai_test extends externallib_advanced_testcase {
    /** @var \stdClass The test course. */
    private $course;

    /** @var \stdClass An editing teacher. */
    private $teacher;

    /** @var \stdClass A student. */
    private $student;

    /** @var \stdClass The activity. */
    private $activity;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->activity = $this->getDataGenerator()->get_plugin_generator('mod_aiknowledgecheck')
            ->create_instance(['course' => $this->course->id]);
        $this->setUser($this->teacher);
    }

    /**
     * A question document as the client sends it.
     *
     * @param string $text Question wording.
     * @return array
     */
    private function question(string $text): array {
        return [
            'question' => $text,
            'options' => [
                ['text' => 'Between 5°C and 60°C', 'explanation' => 'Bacteria grow fastest in this range.'],
                ['text' => 'Between 0°C and 5°C', 'explanation' => 'That is cold storage.'],
                ['text' => 'Between 60°C and 75°C', 'explanation' => 'That is hot holding.'],
                ['text' => 'Below 0°C', 'explanation' => 'That is frozen storage.'],
            ],
            'correctIndex' => 0,
            'questionType' => 'scale',
        ];
    }

    /**
     * Save questions through the service.
     *
     * @param array $questions Question documents.
     * @param string $source ai or editor.
     * @return array Cleaned response.
     */
    private function save(array $questions, string $source = 'ai'): array {
        $result = save_questions::execute(
            $this->activity->cmid,
            json_encode($questions),
            -1,
            '',
            '',
            '',
            $source === 'ai' ? 1 : 0,
            $source
        );
        return external_api::clean_returnvalue(save_questions::execute_returns(), $result);
    }

    /**
     * Current activity record.
     *
     * @return \stdClass
     */
    private function record(): \stdClass {
        global $DB;
        return $DB->get_record('aiknowledgecheck', ['id' => $this->activity->id]);
    }

    public function test_ai_save_marks_questions_and_waits_for_approval(): void {
        global $DB;
        $result = $this->save([$this->question('What is the danger zone?'), $this->question('When do you wash hands?')]);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['released']);
        $this->assertSame(2, $result['aiquestions']);
        $rows = $DB->get_records('aiknowledgecheck_questions', ['aiknowledgecheckid' => $this->activity->id]);
        foreach ($rows as $row) {
            $this->assertEquals(1, $row->aigenerated);
            $this->assertSame(responsibleai::content_hash($row), $row->aihash);
        }
        $this->assertTrue($DB->record_exists('aiknowledgecheck_ailog', [
            'aiknowledgecheckid' => $this->activity->id,
            'action' => responsibleai::ACTION_GENERATED,
        ]));
    }

    public function test_learner_cannot_start_or_read_questions_until_approved(): void {
        $this->save([$this->question('What is the danger zone?')]);

        $this->setUser($this->student);
        $start = external_api::clean_returnvalue(start_attempt::execute_returns(), start_attempt::execute($this->activity->cmid));
        $this->assertFalse($start['ok']);
        $this->assertSame(get_string('notreleased_student', 'mod_aiknowledgecheck'), $start['error']);
        $read = external_api::clean_returnvalue(get_questions::execute_returns(), get_questions::execute($this->activity->cmid));
        $this->assertSame([], $read['questions']);

        $this->setUser($this->teacher);
        approve_questions::execute($this->activity->cmid, 1, 1);

        $this->setUser($this->student);
        $start = external_api::clean_returnvalue(start_attempt::execute_returns(), start_attempt::execute($this->activity->cmid));
        $this->assertTrue($start['ok']);
        $read = external_api::clean_returnvalue(get_questions::execute_returns(), get_questions::execute($this->activity->cmid));
        $this->assertCount(1, $read['questions']);
    }

    public function test_approval_needs_confirmation_and_names_the_approver(): void {
        $this->save([$this->question('What is the danger zone?')]);

        $refused = approve_questions::execute($this->activity->cmid, 1, 0);
        $this->assertFalse($refused['ok']);
        $this->assertEmpty($this->record()->timeapproved);

        $done = external_api::clean_returnvalue(
            approve_questions::execute_returns(),
            approve_questions::execute($this->activity->cmid, 1, 1)
        );
        $this->assertTrue($done['ok']);
        $this->assertTrue($done['approved']);
        $this->assertEquals($this->teacher->id, $this->record()->approvedby);
        $this->assertStringContainsString(fullname($this->teacher), $done['status']);

        $withdrawn = approve_questions::execute($this->activity->cmid, 0);
        $this->assertTrue($withdrawn['ok']);
        $this->assertFalse($withdrawn['approved']);
        $this->assertEmpty($this->record()->timeapproved);
    }

    public function test_student_cannot_approve(): void {
        $this->save([$this->question('What is the danger zone?')]);
        $this->setUser($this->student);
        $this->expectException(\required_capability_exception::class);
        approve_questions::execute($this->activity->cmid, 1, 1);
    }

    public function test_new_ai_output_withdraws_approval_but_teacher_edits_do_not(): void {
        $this->save([$this->question('What is the danger zone?'), $this->question('When do you wash hands?')]);
        approve_questions::execute($this->activity->cmid, 1, 1);
        $this->assertNotEmpty($this->record()->timeapproved);

        // A teacher edits question two and adds a third of their own: approval stays, provenance is kept.
        $edited = [
            $this->question('What is the danger zone?'),
            $this->question('When must food handlers wash and dry their hands?'),
            $this->question('Which foods need a use-by date?'),
        ];
        $result = $this->save($edited, 'editor');
        $this->assertTrue($result['released']);
        $this->assertSame(2, $result['aiquestions']);
        $this->assertSame(1, $result['changed']);
        $this->assertSame(1, $result['teacherwritten']);
        $this->assertNotEmpty($this->record()->timeapproved);

        // The AI rewrites the questions: approval is withdrawn.
        $result = $this->save([$this->question('A new AI question')]);
        $this->assertFalse($result['released']);
        $this->assertEmpty($this->record()->timeapproved);
    }

    public function test_sites_that_do_not_require_approval_release_at_once(): void {
        set_config('requireapproval', 0, 'mod_aiknowledgecheck');
        $this->save([$this->question('What is the danger zone?')]);
        $this->setUser($this->student);
        $start = start_attempt::execute($this->activity->cmid);
        $this->assertTrue($start['ok']);
    }

    public function test_pause_refuses_ai_work_and_logs_it(): void {
        global $DB;
        set_config('aipaused', 1, 'mod_aiknowledgecheck');
        try {
            responsibleai::require_not_paused($this->record(), 'generate');
            $this->fail('Expected AI work to be refused while paused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('aipaused_error', $e->errorcode);
        }
        $this->assertTrue($DB->record_exists('aiknowledgecheck_ailog', ['action' => responsibleai::ACTION_BLOCKED]));

        // Editing and approving keep working while AI is paused.
        $this->save([$this->question('Written by the teacher')], 'editor');
        $result = approve_questions::execute($this->activity->cmid, 1, 1);
        $this->assertTrue($result['ok']);
    }

    public function test_pause_applies_to_the_generation_service(): void {
        set_config('aipaused', 1, 'mod_aiknowledgecheck');
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('aipaused_error', 'mod_aiknowledgecheck'));
        \mod_aiknowledgecheck\external\generate_image::execute($this->activity->cmid, 'A chef washing hands');
    }

    public function test_log_retention_never_drops_below_six_months(): void {
        global $DB;
        set_config('logretention', 30, 'mod_aiknowledgecheck');
        $this->assertSame(responsibleai::MIN_RETENTION_DAYS, responsibleai::retention_days());

        $recent = responsibleai::log((int)$this->activity->id, responsibleai::ACTION_APPROVED);
        $old = responsibleai::log((int)$this->activity->id, responsibleai::ACTION_GENERATED);
        $DB->set_field('aiknowledgecheck_ailog', 'timecreated', time() - 200 * DAYSECS, ['id' => $old]);

        $this->assertSame(1, responsibleai::purge_old_log());
        $this->assertTrue($DB->record_exists('aiknowledgecheck_ailog', ['id' => $recent]));
        $this->assertFalse($DB->record_exists('aiknowledgecheck_ailog', ['id' => $old]));
    }
}
