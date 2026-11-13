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
 * Tests how the save service treats a survey's answer options.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_aiknowledgecheck;

use externallib_advanced_testcase;
use external_api;
use mod_aiknowledgecheck\external\save_questions;
use mod_aiknowledgecheck\external\get_questions;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');
require_once($CFG->dirroot . '/mod/aiknowledgecheck/lib.php');

/**
 * Survey option handling in save_questions.
 *
 * A survey's options are normally forced to the labels of the activity's chosen scale, so the
 * generation service cannot decide a scale the teacher did not pick. That enforcement used to
 * run on every save, which meant it also discarded a teacher's own wording the moment they
 * saved the question editor. These tests pin both halves: generation still gets the scale, and
 * an edit keeps what was typed.
 *
 * @runTestsInSeparateProcesses
 * @covers \mod_aiknowledgecheck\external\save_questions
 */
final class save_questions_test extends externallib_advanced_testcase {
    /** @var \stdClass The test course. */
    private $course;

    /** @var \stdClass A teacher. */
    private $teacher;

    /** @var \mod_aiknowledgecheck_generator The plugin generator. */
    private $generator;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->generator = $this->getDataGenerator()->get_plugin_generator('mod_aiknowledgecheck');
        $this->setUser($this->teacher);
    }

    /**
     * The wording a teacher types into the question editor.
     *
     * Deliberately nothing like a Likert label, so a test that passes cannot be passing because
     * the two happen to coincide.
     *
     * @return array Five option labels.
     */
    private function custom_options(): array {
        return [
            'Strong Knowledge - I have a deep understanding and can confidently apply the principles',
            'Good Knowledge - I have a solid understanding and can apply them',
            'Developing Knowledge - I understand the main ideas and can apply them in some situations',
            'Basic Awareness - I am aware of the concept but have limited understanding',
            'Very Limited Knowledge - I have little or no understanding',
        ];
    }

    /**
     * Build the payload the client sends for one scale question.
     *
     * @param array $options Option labels.
     * @return string The JSON payload.
     */
    private function payload(array $options): string {
        return json_encode([
            [
                'question' => 'Before attending this workshop, how would you rate your understanding?',
                'options' => array_map(
                    function (string $text): array {
                        return ['text' => $text, 'explanation' => ''];
                    },
                    $options
                ),
                'correctIndex' => 0,
                'questionType' => 'scale',
            ],
        ]);
    }

    /**
     * Read the stored option labels back for the activity's single question.
     *
     * @param int $instanceid The activity instance ID.
     * @return array The stored labels, in order, blanks dropped.
     */
    private function stored_options(int $instanceid): array {
        global $DB;

        $row = $DB->get_record('aiknowledgecheck_questions', ['aiknowledgecheckid' => $instanceid], '*', MUST_EXIST);

        return array_values(array_filter([
            $row->answer1, $row->answer2, $row->answer3, $row->answer4, $row->answer5,
        ], function ($v): bool {
            return $v !== null && $v !== '';
        }));
    }

    /**
     * Create an activity and return its course module ID.
     *
     * @param array $params Instance overrides.
     * @return int The cmid.
     */
    private function activity(array $params = []): int {
        $activity = $this->generator->create_instance(['course' => $this->course->id] + $params);

        return (int)get_coursemodule_from_instance('aiknowledgecheck', $activity->id)->id;
    }

    /**
     * Editing a survey keeps the teacher's wording. This is the reported defect.
     */
    public function test_a_survey_edit_keeps_the_teachers_option_wording(): void {
        $cmid = $this->activity(['surveymode' => 1, 'surveyscale' => 'likert5agree']);

        $result = save_questions::execute($cmid, $this->payload($this->custom_options()), -1, '', '', '', 0);
        $result = external_api::clean_returnvalue(save_questions::execute_returns(), $result);
        $this->assertTrue($result['ok']);

        $instanceid = (int)get_coursemodule_from_id('aiknowledgecheck', $cmid)->instance;

        $this->assertSame(
            $this->custom_options(),
            $this->stored_options($instanceid),
            'A teacher saving the editor must not have their options replaced by the scale labels.'
        );
    }

    /**
     * Generation still has its options forced to the activity's scale.
     */
    public function test_generation_still_gets_the_scale_labels(): void {
        $cmid = $this->activity(['surveymode' => 1, 'surveyscale' => 'likert5agree']);

        // Default enforceScale, as every generation caller sends it.
        save_questions::execute($cmid, $this->payload($this->custom_options()));

        $instanceid = (int)get_coursemodule_from_id('aiknowledgecheck', $cmid)->instance;

        $this->assertSame(
            mod_aiknowledgecheck_survey_scale_options('likert5agree'),
            $this->stored_options($instanceid),
            'Generated survey questions must still be forced onto the chosen scale.'
        );
    }

    /**
     * The enforcement follows the activity's own scale, not just the default one.
     */
    public function test_generation_uses_the_activitys_chosen_scale(): void {
        $cmid = $this->activity(['surveymode' => 1, 'surveyscale' => 'likert5freq']);

        save_questions::execute($cmid, $this->payload($this->custom_options()));

        $instanceid = (int)get_coursemodule_from_id('aiknowledgecheck', $cmid)->instance;

        $this->assertSame(
            ['Always', 'Often', 'Sometimes', 'Rarely', 'Never'],
            $this->stored_options($instanceid)
        );
    }

    /**
     * A quiz is unaffected either way — the enforcement is survey-only.
     */
    public function test_a_quiz_keeps_its_options_whatever_the_flag_says(): void {
        foreach ([0, 1] as $flag) {
            $cmid = $this->activity(['surveymode' => 0]);
            $options = array_slice($this->custom_options(), 0, 4);

            save_questions::execute($cmid, $this->payload($options), -1, '', '', '', $flag);

            $instanceid = (int)get_coursemodule_from_id('aiknowledgecheck', $cmid)->instance;

            $this->assertSame(
                $options,
                $this->stored_options($instanceid),
                "A quiz must keep its options with enforceScale = $flag."
            );
        }
    }

    /**
     * A survey stays ungraded whichever way the options were saved: no per-option feedback and
     * no answer key, so an edit cannot turn a survey into something that marks people.
     */
    public function test_a_survey_stays_ungraded_when_the_teacher_edits_it(): void {
        global $DB;

        $cmid = $this->activity(['surveymode' => 1]);
        $payload = json_encode([
            [
                'question' => 'How confident do you feel?',
                'options' => array_map(
                    function (string $text): array {
                        return ['text' => $text, 'explanation' => 'This should not be stored'];
                    },
                    $this->custom_options()
                ),
                'correctIndex' => 3,
                'questionType' => 'scale',
            ],
        ]);

        save_questions::execute($cmid, $payload, -1, '', '', '', 0);

        $instanceid = (int)get_coursemodule_from_id('aiknowledgecheck', $cmid)->instance;
        $row = $DB->get_record('aiknowledgecheck_questions', ['aiknowledgecheckid' => $instanceid], '*', MUST_EXIST);

        $this->assertSame(0, (int)$row->correctanswer, 'A survey has no answer key.');
        foreach (['feedback1', 'feedback2', 'feedback3', 'feedback4'] as $field) {
            $this->assertSame('', (string)$row->$field, "A survey must not store $field.");
        }
    }

    /**
     * What the teacher saved is what the activity serves back, which is the part the student
     * view reads. The reported symptom was the editor and the student view disagreeing.
     */
    public function test_the_saved_wording_is_what_gets_served_back(): void {
        $cmid = $this->activity(['surveymode' => 1]);

        save_questions::execute($cmid, $this->payload($this->custom_options()), -1, '', '', '', 0);

        $served = get_questions::execute($cmid);
        $served = external_api::clean_returnvalue(get_questions::execute_returns(), $served);

        $labels = array_map(
            function (array $o): string {
                return $o['text'];
            },
            $served['questions'][0]['options']
        );

        $this->assertSame($this->custom_options(), $labels);
    }
}
