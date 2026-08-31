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
 * Tests for the mod_aiknowledgecheck external services.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_aiknowledgecheck;

use externallib_advanced_testcase;
use external_api;
use mod_aiknowledgecheck\external\start_attempt;
use mod_aiknowledgecheck\external\save_answer;
use mod_aiknowledgecheck\external\finish_attempt;
use mod_aiknowledgecheck\external\get_questions;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');
require_once($CFG->dirroot . '/mod/aiknowledgecheck/lib.php');

/**
 * Tests the attempt lifecycle services and the boundary rules they depend on.
 *
 * The plugin's external classes extend the legacy global external_api, which lib/externallib.php
 * provides through class_alias(). Those aliases are a global side effect, so Moodle requires any
 * test that loads that file to run in an isolated process.
 *
 * @runTestsInSeparateProcesses
 * @covers \mod_aiknowledgecheck\external\start_attempt
 * @covers \mod_aiknowledgecheck\external\save_answer
 * @covers \mod_aiknowledgecheck\external\finish_attempt
 * @covers \mod_aiknowledgecheck\external\get_questions
 */
final class external_test extends externallib_advanced_testcase {
    /** @var \stdClass The test course. */
    private $course;

    /** @var \stdClass The activity instance. */
    private $activity;

    /** @var \stdClass The course module. */
    private $cm;

    /** @var \stdClass A student. */
    private $student;

    /** @var \stdClass A second student. */
    private $other;

    /** @var \stdClass A teacher. */
    private $teacher;

    /** @var \mod_aiknowledgecheck_generator The plugin generator. */
    private $generator;

    /** @var array Question IDs, in order. */
    private $questionids;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');

        $this->generator = $this->getDataGenerator()->get_plugin_generator('mod_aiknowledgecheck');
        $this->activity = $this->generator->create_instance(
            [
                'course' => $this->course->id,
                'maxattempts' => 2,
            ]
        );
        $this->cm = get_coursemodule_from_instance('aiknowledgecheck', $this->activity->id);
        $this->questionids = $this->generator->create_questions($this->activity->id, 3);
    }

    /**
     * Count this student's attempts on the test activity.
     *
     * @return int The number of attempt rows.
     */
    private function attempt_count(): int {
        global $DB;
        return $DB->count_records(
            'aiknowledgecheck_attempts',
            [
                'aiknowledgecheckid' => $this->activity->id,
                'userid' => $this->student->id,
            ]
        );
    }

    /**
     * Start an attempt as the given user and return the validated response.
     *
     * @param \stdClass $user The user to act as.
     * @return array The cleaned return value.
     */
    private function start_as(\stdClass $user): array {
        $this->setUser($user);
        $result = start_attempt::execute($this->cm->id);
        return external_api::clean_returnvalue(start_attempt::execute_returns(), $result);
    }

    /**
     * A first call creates an attempt; a second returns the same one as resumed.
     */
    public function test_start_attempt_creates_then_resumes(): void {
        global $DB;

        $first = $this->start_as($this->student);

        $this->assertTrue($first['ok']);
        $this->assertFalse($first['resumed']);
        $this->assertGreaterThan(0, $first['attemptid']);
        $this->assertSame('', $first['error']);

        $second = $this->start_as($this->student);
        $this->assertTrue($second['ok']);
        $this->assertTrue($second['resumed'], 'A second call must resume, not create a duplicate.');
        $this->assertSame($first['attemptid'], $second['attemptid']);

        // Assert the stored state, not just the response. Returning the existing attempt ID while
        // also inserting a second row would satisfy every assertion above and still corrupt the
        // student's attempt count.
        $this->assertSame(
            1,
            $this->attempt_count(),
            'Resuming must not leave a second attempt row behind.'
        );
    }

    /**
     * Repeated start calls never accumulate attempt rows.
     */
    public function test_start_attempt_is_idempotent_while_an_attempt_is_open(): void {
        global $DB;

        for ($i = 0; $i < 5; $i++) {
            $this->start_as($this->student);
        }

        $this->assertSame(
            1,
            $this->attempt_count()
        );
    }

    /**
     * answersjson must always be valid JSON, including for a brand new attempt.
     *
     * MIGRATE-EXTERNAL-SERVICES: the answers map is passed as a JSON string because its keys are
     * question IDs. An empty PHP array encodes to '[]', which JSON.parse turns into an array the
     * client can still call Object.keys() on.
     */
    public function test_start_attempt_answersjson_is_always_parseable(): void {
        $result = $this->start_as($this->student);

        $this->assertJson($result['answersjson']);
        $this->assertSame([], json_decode($result['answersjson'], true));
    }

    /**
     * Running out of attempts is reported as a failure, not an exception.
     */
    public function test_start_attempt_refuses_once_the_limit_is_reached(): void {
        $this->generator->create_attempt($this->activity->id, $this->student->id, ['status' => 1]);
        $this->generator->create_attempt($this->activity->id, $this->student->id, ['status' => 1]);

        $result = $this->start_as($this->student);

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $result['attemptid']);
        $this->assertNotSame('', $result['error'], 'A refusal must explain itself to the student.');
    }

    /**
     * A user who is not enrolled cannot start an attempt.
     */
    public function test_start_attempt_requires_enrolment(): void {
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);

        $this->expectException(\require_login_exception::class);
        start_attempt::execute($this->cm->id);
    }

    /**
     * A correct answer is graded correct and recorded against the attempt.
     */
    public function test_save_answer_grades_a_correct_choice(): void {
        $attempt = $this->start_as($this->student)['attemptid'];

        $result = save_answer::execute($attempt, $this->questionids[0], 0);
        $result = external_api::clean_returnvalue(save_answer::execute_returns(), $result);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['iscorrect']);
    }

    /**
     * A wrong answer is graded incorrect.
     */
    public function test_save_answer_grades_an_incorrect_choice(): void {
        $attempt = $this->start_as($this->student)['attemptid'];

        $result = save_answer::execute($attempt, $this->questionids[0], 2);
        $result = external_api::clean_returnvalue(save_answer::execute_returns(), $result);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['iscorrect']);
    }

    /**
     * A student must not be able to write into another student's attempt.
     */
    public function test_save_answer_rejects_someone_elses_attempt(): void {
        $victim = $this->start_as($this->student)['attemptid'];

        $this->setUser($this->other);
        $result = save_answer::execute($victim, $this->questionids[0], 0);
        $result = external_api::clean_returnvalue(save_answer::execute_returns(), $result);

        $this->assertFalse($result['ok'], 'Writing into another user\'s attempt must be refused.');
    }

    /**
     * A question from a different activity must not be accepted.
     */
    public function test_save_answer_rejects_a_question_from_another_activity(): void {
        $foreign = $this->generator->create_instance(['course' => $this->course->id]);
        $foreignquestion = $this->generator->create_question($foreign->id, 1);

        $attempt = $this->start_as($this->student)['attemptid'];
        $result = save_answer::execute($attempt, $foreignquestion, 0);
        $result = external_api::clean_returnvalue(save_answer::execute_returns(), $result);

        $this->assertFalse($result['ok']);
    }

    /**
     * A free-text answer index on a non-free-text question is a forged payload.
     */
    public function test_save_answer_rejects_freetext_index_on_a_scale_question(): void {
        $attempt = $this->start_as($this->student)['attemptid'];

        $result = save_answer::execute($attempt, $this->questionids[0], -1, 'not allowed here');
        $result = external_api::clean_returnvalue(save_answer::execute_returns(), $result);

        $this->assertFalse($result['ok']);
    }

    /**
     * Free text containing characters PARAM_TEXT would strip must still be accepted.
     *
     * FIX-KC-PARAMTEXT-THROW: validate_parameters() rejects rather than cleans, so the parameter
     * is declared raw at the boundary and cleaned inside execute(). Before that fix, a student
     * typing "a < b" silently lost their answer.
     */
    public function test_save_answer_accepts_free_text_with_angle_brackets(): void {
        $freetextid = $this->generator->create_question(
            $this->activity->id,
            4,
            ['questiontype' => 'freetext']
        );
        $attempt = $this->start_as($this->student)['attemptid'];

        $result = save_answer::execute($attempt, $freetextid, -1, 'a < b and 3 > 2 <3');
        $result = external_api::clean_returnvalue(save_answer::execute_returns(), $result);

        $this->assertTrue($result['ok'], 'Angle brackets in a typed answer must not abort the save.');
    }

    /**
     * A very long free-text answer is capped rather than stored unbounded.
     */
    public function test_save_answer_caps_free_text_length(): void {
        global $DB;

        $freetextid = $this->generator->create_question(
            $this->activity->id,
            4,
            ['questiontype' => 'freetext']
        );
        $attempt = $this->start_as($this->student)['attemptid'];

        save_answer::execute($attempt, $freetextid, -1, str_repeat('x', 5000));

        $answers = json_decode($DB->get_field('aiknowledgecheck_attempts', 'answers', ['id' => $attempt]), true);
        $this->assertLessThanOrEqual(2000, \core_text::strlen($answers[$freetextid]['freetext']));
    }

    /**
     * Answers cannot be written to an attempt that has been finished.
     */
    public function test_save_answer_rejects_a_finished_attempt(): void {
        $attempt = $this->start_as($this->student)['attemptid'];
        save_answer::execute($attempt, $this->questionids[0], 0);
        finish_attempt::execute($attempt);

        $result = save_answer::execute($attempt, $this->questionids[1], 0);
        $result = external_api::clean_returnvalue(save_answer::execute_returns(), $result);

        $this->assertFalse($result['ok']);
    }

    /**
     * Finishing marks the attempt complete and reports the score.
     */
    public function test_finish_attempt_completes_and_scores(): void {
        global $DB;

        $attempt = $this->start_as($this->student)['attemptid'];
        save_answer::execute($attempt, $this->questionids[0], 0);
        save_answer::execute($attempt, $this->questionids[1], 0);
        save_answer::execute($attempt, $this->questionids[2], 3);

        $result = finish_attempt::execute($attempt);
        $result = external_api::clean_returnvalue(finish_attempt::execute_returns(), $result);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, (int)$DB->get_field('aiknowledgecheck_attempts', 'status', ['id' => $attempt]));
        $this->assertSame(2, (int)$DB->get_field('aiknowledgecheck_attempts', 'correctcount', ['id' => $attempt]));
    }

    /**
     * A student must not be able to finish another student's attempt.
     */
    public function test_finish_attempt_rejects_someone_elses_attempt(): void {
        $victim = $this->start_as($this->student)['attemptid'];

        $this->setUser($this->other);
        $result = finish_attempt::execute($victim);
        $result = external_api::clean_returnvalue(finish_attempt::execute_returns(), $result);

        $this->assertFalse($result['ok']);
    }

    /**
     * get_questions returns every question and survives return-value validation.
     *
     * FIX-KC-RETURNTYPE-CLEAN: clean_returnvalue() validates rather than cleans, so text is
     * cleaned at build time and then declared with the strict type.
     */
    public function test_get_questions_returns_all_questions(): void {
        $this->setUser($this->student);

        $result = get_questions::execute($this->cm->id);
        $result = external_api::clean_returnvalue(get_questions::execute_returns(), $result);

        $this->assertTrue($result['ok']);
        $this->assertCount(3, $result['questions']);
        $this->assertSame('Question 1?', $result['questions'][0]['question']);
        $this->assertCount(4, $result['questions'][0]['options']);
    }

    /**
     * Question text containing markup must not break return-value validation.
     */
    public function test_get_questions_survives_markup_in_question_text(): void {
        $this->generator->create_question(
            $this->activity->id,
            4,
            [
                'questiontext' => 'Is 3 < 5 & "true"?',
                'feedback1' => 'Yes — 3 < 5 is <strong>true</strong>.',
            ]
        );
        $this->setUser($this->student);

        $result = get_questions::execute($this->cm->id);
        $cleaned = external_api::clean_returnvalue(get_questions::execute_returns(), $result);

        $this->assertTrue($cleaned['ok']);
        $this->assertCount(4, $cleaned['questions']);
    }

    /**
     * An activity with no questions returns an empty list, never null.
     *
     * external_multiple_structure rejects null outright with "Only arrays accepted".
     */
    public function test_get_questions_returns_an_empty_array_when_there_are_none(): void {
        $empty = $this->generator->create_instance(['course' => $this->course->id]);
        $emptycm = get_coursemodule_from_instance('aiknowledgecheck', $empty->id);
        $this->setUser($this->student);

        $result = get_questions::execute($emptycm->id);
        $cleaned = external_api::clean_returnvalue(get_questions::execute_returns(), $result);

        $this->assertIsArray($cleaned['questions']);
        $this->assertCount(0, $cleaned['questions']);
    }

    /**
     * A non-integer course module ID is rejected at the service boundary.
     *
     * Called the way the web service layer calls it, so validate_parameters() does the checking
     * rather than PHP's own int type hint on execute().
     */
    public function test_parameters_are_type_checked(): void {
        $this->setUser($this->student);

        $response = \core_external\external_api::call_external_function(
            'mod_aiknowledgecheck_start_attempt',
            ['cmid' => 'not-an-integer'],
            false
        );

        $this->assertTrue($response['error'], 'A non-integer cmid must be rejected.');
        $this->assertContains(
            $response['exception']->errorcode,
            ['invalid_parameter_exception', 'missingparam'],
            'Rejection must come from parameter validation, not from a later failure.'
        );
    }

    /**
     * A course module ID that does not exist is rejected rather than silently ignored.
     */
    public function test_unknown_course_module_is_rejected(): void {
        $this->setUser($this->student);

        $this->expectException(\dml_missing_record_exception::class);
        start_attempt::execute(99999999);
    }
}
