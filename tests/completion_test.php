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
 * Tests the custom completion rules, including the survey submission rule.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_aiknowledgecheck;

use advanced_testcase;
use cm_info;
use mod_aiknowledgecheck\completion\custom_completion;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/aiknowledgecheck/lib.php');

/**
 * The activity's custom completion rules.
 *
 * @covers \mod_aiknowledgecheck\completion\custom_completion
 */
final class completion_test extends advanced_testcase {
    /** @var \stdClass The test course. */
    private $course;

    /** @var \stdClass A student. */
    private $student;

    /** @var \stdClass A second student, who never attempts anything. */
    private $other;

    /** @var \mod_aiknowledgecheck_generator The plugin generator. */
    private $generator;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->generator = $this->getDataGenerator()->get_plugin_generator('mod_aiknowledgecheck');
    }

    /**
     * Build an activity and return its cm_info.
     *
     * @param array $params Instance field overrides.
     * @return cm_info The course module.
     */
    private function make(array $params = []): cm_info {
        $activity = $this->generator->create_instance(
            ['course' => $this->course->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC] + $params
        );

        return cm_info::create(get_coursemodule_from_instance('aiknowledgecheck', $activity->id));
    }

    /**
     * Evaluate one rule for one user.
     *
     * @param cm_info $cm The course module.
     * @param int $userid The user.
     * @param string $rule The rule name.
     * @return int The completion state.
     */
    private function state(cm_info $cm, int $userid, string $rule): int {
        return (new custom_completion($cm, $userid))->get_state($rule);
    }

    /**
     * The rule is unmet until the learner has a completed attempt.
     */
    public function test_submitted_rule_needs_a_completed_attempt(): void {
        $cm = $this->make(['surveymode' => 1, 'completionsubmitted' => 1]);

        $this->assertSame(
            COMPLETION_INCOMPLETE,
            $this->state($cm, (int)$this->student->id, 'completionsubmitted')
        );

        $this->generator->create_attempt($cm->instance, (int)$this->student->id, ['status' => 1]);

        $this->assertSame(
            COMPLETION_COMPLETE,
            $this->state($cm, (int)$this->student->id, 'completionsubmitted')
        );
    }

    /**
     * An attempt still in progress is not a submission.
     */
    public function test_an_unfinished_attempt_does_not_complete_the_survey(): void {
        $cm = $this->make(['surveymode' => 1, 'completionsubmitted' => 1]);
        $this->generator->create_attempt($cm->instance, (int)$this->student->id, ['status' => 0]);

        $this->assertSame(
            COMPLETION_INCOMPLETE,
            $this->state($cm, (int)$this->student->id, 'completionsubmitted'),
            'Only a completed attempt counts as submitted.'
        );
    }

    /**
     * One learner's submission must not complete the activity for anybody else.
     */
    public function test_the_rule_is_scoped_to_the_user(): void {
        $cm = $this->make(['surveymode' => 1, 'completionsubmitted' => 1]);
        $this->generator->create_attempt($cm->instance, (int)$this->student->id, ['status' => 1]);

        $this->assertSame(
            COMPLETION_INCOMPLETE,
            $this->state($cm, (int)$this->other->id, 'completionsubmitted')
        );
    }

    /**
     * An attempt on a different activity must not satisfy this one.
     */
    public function test_the_rule_is_scoped_to_the_activity(): void {
        $cm = $this->make(['surveymode' => 1, 'completionsubmitted' => 1]);
        $elsewhere = $this->make(['surveymode' => 1, 'completionsubmitted' => 1]);

        $this->generator->create_attempt($elsewhere->instance, (int)$this->student->id, ['status' => 1]);

        $this->assertSame(
            COMPLETION_INCOMPLETE,
            $this->state($cm, (int)$this->student->id, 'completionsubmitted')
        );
    }

    /**
     * The quiz rule is unchanged: a completed attempt is not enough, every answer must be right.
     *
     * This is the regression guard for removing the survey reinterpretation from
     * completionallcorrect — that branch must not have taken the quiz behaviour with it.
     */
    public function test_allcorrect_still_requires_every_answer_to_be_correct(): void {
        $cm = $this->make(['completionallcorrect' => 1]);
        $this->generator->create_questions($cm->instance, 4);

        $partial = $this->generator->create_attempt(
            $cm->instance,
            (int)$this->student->id,
            ['status' => 1, 'correctcount' => 3, 'totalcount' => 4]
        );
        $this->assertSame(
            COMPLETION_INCOMPLETE,
            $this->state($cm, (int)$this->student->id, 'completionallcorrect'),
            '3 of 4 correct must not complete the activity.'
        );

        global $DB;
        $DB->set_field('aiknowledgecheck_attempts', 'correctcount', 4, ['id' => $partial]);

        $this->assertSame(
            COMPLETION_COMPLETE,
            $this->state($cm, (int)$this->student->id, 'completionallcorrect')
        );
    }

    /**
     * Both rules are declared, so Moodle will accept either from the form and render its
     * description on the course page. A rule missing here is silently inert.
     */
    public function test_both_rules_are_declared(): void {
        $declared = custom_completion::get_defined_custom_rules();

        $this->assertContains('completionsubmitted', $declared);
        $this->assertContains('completionallcorrect', $declared);

        $cm = $this->make(['surveymode' => 1, 'completionsubmitted' => 1]);
        $descriptions = (new custom_completion($cm, (int)$this->student->id))->get_custom_rule_descriptions();

        foreach ($declared as $rule) {
            $this->assertArrayHasKey($rule, $descriptions, "Rule $rule has no description.");
            $this->assertNotEmpty($descriptions[$rule]);
        }
    }

    /**
     * The course-page cache advertises the rule the activity actually carries.
     */
    public function test_coursemodule_info_publishes_the_active_rule(): void {
        $survey = $this->make(['surveymode' => 1, 'completionsubmitted' => 1]);
        $info = aiknowledgecheck_get_coursemodule_info(
            get_coursemodule_from_instance('aiknowledgecheck', $survey->instance)
        );
        $rules = $info->customdata['customcompletionrules'];

        $this->assertArrayHasKey('completionsubmitted', $rules);
        $this->assertArrayNotHasKey('completionallcorrect', $rules);

        $quiz = $this->make(['completionallcorrect' => 1]);
        $quizinfo = aiknowledgecheck_get_coursemodule_info(
            get_coursemodule_from_instance('aiknowledgecheck', $quiz->instance)
        );

        $this->assertArrayHasKey('completionallcorrect', $quizinfo->customdata['customcompletionrules']);
        $this->assertArrayNotHasKey('completionsubmitted', $quizinfo->customdata['customcompletionrules']);
    }

    /**
     * A survey keeps only its own rule, and a quiz only its own, however the row arrived.
     */
    public function test_update_instance_stores_only_the_rule_that_matches_the_mode(): void {
        global $DB;

        $survey = $this->make(['surveymode' => 1, 'completionsubmitted' => 1]);
        $this->assertSame(
            0,
            (int)$DB->get_field('aiknowledgecheck', 'completionallcorrect', ['id' => $survey->instance])
        );
        $this->assertSame(
            1,
            (int)$DB->get_field('aiknowledgecheck', 'completionsubmitted', ['id' => $survey->instance])
        );
    }
    /**
     * The v1.6.0 upgrade step moves a survey off the reinterpreted rule and onto the real one,
     * and leaves quiz activities alone.
     *
     * The install path builds the table from install.xml, so it never runs this branch. Without
     * this test the migration — the riskiest part of the change, because it rewrites settings on
     * live activities — would ship unexercised.
     */
    public function test_the_upgrade_migrates_surveys_and_leaves_quizzes_alone(): void {
        global $CFG, $DB;
        // Moodle's upgradelib.php provides upgrade_mod_savepoint(), which the upgrade steps
        // call and which is not loaded during a normal test bootstrap. The savepoint also refuses to run
        // when the recorded version already matches the target, so wind it back first — the test
        // site was installed from install.xml and is therefore already at the new version.
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/aiknowledgecheck/db/upgrade.php');
        set_config('version', 2026091001, 'mod_aiknowledgecheck');

        // A survey in the pre-upgrade state: relying on completionallcorrect being reinterpreted.
        $survey = $this->generator->create_instance(['course' => $this->course->id, 'surveymode' => 1]);
        $DB->set_field('aiknowledgecheck', 'completionallcorrect', 1, ['id' => $survey->id]);
        $DB->set_field('aiknowledgecheck', 'completionsubmitted', 0, ['id' => $survey->id]);

        // A quiz using the same rule legitimately. It must come through untouched.
        $quiz = $this->generator->create_instance(['course' => $this->course->id, 'surveymode' => 0]);
        $DB->set_field('aiknowledgecheck', 'completionallcorrect', 1, ['id' => $quiz->id]);

        // A survey with no completion rule at all must not gain one.
        $plain = $this->generator->create_instance(['course' => $this->course->id, 'surveymode' => 1]);

        $this->assertTrue(xmldb_aiknowledgecheck_upgrade(2026091001));

        $after = function (int $id, string $field): int {
            global $DB;
            return (int)$DB->get_field('aiknowledgecheck', $field, ['id' => $id]);
        };

        $this->assertSame(1, $after($survey->id, 'completionsubmitted'), 'The survey must gain the real rule.');
        $this->assertSame(0, $after($survey->id, 'completionallcorrect'), 'The reinterpreted rule must be cleared.');

        $this->assertSame(1, $after($quiz->id, 'completionallcorrect'), 'A quiz must keep its rule.');
        $this->assertSame(0, $after($quiz->id, 'completionsubmitted'), 'A quiz must not gain the survey rule.');

        $this->assertSame(0, $after($plain->id, 'completionsubmitted'), 'A survey with no rule must not gain one.');
        $this->assertSame(0, $after($plain->id, 'completionallcorrect'));
    }

    /**
     * Running the upgrade twice must not change anything the first run did not.
     */
    public function test_the_migration_is_idempotent(): void {
        global $CFG, $DB;
        // Moodle's upgradelib.php provides upgrade_mod_savepoint(), which the upgrade steps
        // call and which is not loaded during a normal test bootstrap. The savepoint also refuses to run
        // when the recorded version already matches the target, so wind it back first — the test
        // site was installed from install.xml and is therefore already at the new version.
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/aiknowledgecheck/db/upgrade.php');
        set_config('version', 2026091001, 'mod_aiknowledgecheck');

        $survey = $this->generator->create_instance(['course' => $this->course->id, 'surveymode' => 1]);
        $DB->set_field('aiknowledgecheck', 'completionallcorrect', 1, ['id' => $survey->id]);

        xmldb_aiknowledgecheck_upgrade(2026091001);
        $first = $DB->get_record('aiknowledgecheck', ['id' => $survey->id], 'completionallcorrect, completionsubmitted');

        // The first run advanced the savepoint, so wind it back to run the branch again.
        set_config('version', 2026091001, 'mod_aiknowledgecheck');
        xmldb_aiknowledgecheck_upgrade(2026091001);
        $second = $DB->get_record('aiknowledgecheck', ['id' => $survey->id], 'completionallcorrect, completionsubmitted');

        $this->assertEquals($first, $second);
    }
}
