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
 * Privacy provider tests for mod_aiknowledgecheck.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_aiknowledgecheck;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use mod_aiknowledgecheck\privacy\provider;

/**
 * Checks that user data is found, exported and deleted for the right users only.
 *
 * @covers \mod_aiknowledgecheck\privacy\provider
 */
final class privacy_provider_test extends provider_testcase {
    /** @var \stdClass The test course. */
    private $course;

    /** @var \stdClass The activity instance. */
    private $activity;

    /** @var \context_module The activity context. */
    private $context;

    /** @var \stdClass A student with data. */
    private $student;

    /** @var \stdClass A second student with data. */
    private $other;

    /** @var \mod_aiknowledgecheck_generator The plugin generator. */
    private $generator;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $this->generator = $this->getDataGenerator()->get_plugin_generator('mod_aiknowledgecheck');
        $this->activity = $this->generator->create_instance(['course' => $this->course->id]);
        $cm = get_coursemodule_from_instance('aiknowledgecheck', $this->activity->id);
        $this->context = \context_module::instance($cm->id);

        $this->generator->create_questions($this->activity->id, 2);
        $this->generator->create_attempt($this->activity->id, $this->student->id, ['status' => 1]);
        $this->generator->create_attempt($this->activity->id, $this->other->id, ['status' => 1]);
        $this->generator->create_override($this->activity->id, $this->student->id, 2);
    }

    /**
     * Count rows in one of the plugin's tables for this activity.
     *
     * @param string $table The table, without the plugin prefix: attempts, overrides or questions.
     * @param int|null $userid Restrict to one user, or null for every user.
     * @return int The row count.
     */
    private function count_rows(string $table, ?int $userid = null): int {
        global $DB;
        $conditions = ['aiknowledgecheckid' => $this->activity->id];
        if ($userid !== null) {
            $conditions['userid'] = $userid;
        }
        return $DB->count_records('aiknowledgecheck_' . $table, $conditions);
    }

    /**
     * The metadata declares every table that holds user data.
     */
    public function test_metadata_covers_the_user_data_tables(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('mod_aiknowledgecheck'));
        $tables = [];
        foreach ($collection->get_collection() as $item) {
            $tables[] = $item->get_name();
        }

        $this->assertContains('aiknowledgecheck_attempts', $tables);
        $this->assertContains('aiknowledgecheck_overrides', $tables);
    }

    /**
     * A user with an attempt is found in the activity context.
     */
    public function test_contexts_are_found_for_a_user_with_data(): void {
        $contextlist = provider::get_contexts_for_userid($this->student->id);
        $this->assertEqualsCanonicalizing(
            [$this->context->id],
            $contextlist->get_contextids()
        );
    }

    /**
     * A user with no data in the activity is not returned.
     */
    public function test_no_contexts_for_a_user_without_data(): void {
        $bystander = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $contextlist = provider::get_contexts_for_userid($bystander->id);
        $this->assertCount(0, $contextlist->get_contextids());
    }

    /**
     * Both students with attempts are listed for the context, and nobody else.
     */
    public function test_users_in_context(): void {
        $bystander = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $userlist = new userlist($this->context, 'mod_aiknowledgecheck');
        provider::get_users_in_context($userlist);
        $userids = $userlist->get_userids();

        $this->assertContains((int)$this->student->id, $userids);
        $this->assertContains((int)$this->other->id, $userids);
        $this->assertNotContains((int)$bystander->id, $userids);
    }

    /**
     * Exporting writes something for the requesting user.
     */
    public function test_export_writes_data_for_the_user(): void {
        $contextlist = new approved_contextlist(
            \core_user::get_user($this->student->id),
            'mod_aiknowledgecheck',
            [$this->context->id]
        );
        provider::export_user_data($contextlist);

        $this->assertTrue(writer::with_context($this->context)->has_any_data());
    }

    /**
     * Deleting one user's data leaves every other user untouched.
     */
    public function test_delete_for_user_is_scoped_to_that_user(): void {
        $contextlist = new approved_contextlist(
            \core_user::get_user($this->student->id),
            'mod_aiknowledgecheck',
            [$this->context->id]
        );
        provider::delete_data_for_user($contextlist);

        $this->assertSame(0, $this->count_rows('attempts', (int)$this->student->id));
        $this->assertSame(0, $this->count_rows('overrides', (int)$this->student->id));
        $this->assertSame(
            1,
            $this->count_rows('attempts', (int)$this->other->id),
            "Another user's attempt must survive."
        );
    }

    /**
     * Deleting a whole context removes every user's attempts but keeps the questions.
     */
    public function test_delete_for_all_users_in_context(): void {
        provider::delete_data_for_all_users_in_context($this->context);

        $this->assertSame(0, $this->count_rows('attempts'));
        $this->assertSame(
            2,
            $this->count_rows('questions'),
            'Questions are activity content, not user data, and must not be deleted.'
        );
    }

    /**
     * Deleting an approved user list removes only the listed users.
     */
    public function test_delete_for_users_removes_only_the_listed_users(): void {
        $approved = new approved_userlist($this->context, 'mod_aiknowledgecheck', [$this->student->id]);
        provider::delete_data_for_users($approved);

        $this->assertSame(0, $this->count_rows('attempts', (int)$this->student->id));
        $this->assertSame(1, $this->count_rows('attempts', (int)$this->other->id));
    }

    /**
     * Data in one activity is not deleted when another activity's context is purged.
     */
    public function test_delete_does_not_reach_across_activities(): void {
        global $DB;

        $second = $this->generator->create_instance(['course' => $this->course->id]);
        $secondcm = get_coursemodule_from_instance('aiknowledgecheck', $second->id);
        $this->generator->create_attempt($second->id, $this->student->id, ['status' => 1]);

        provider::delete_data_for_all_users_in_context(\context_module::instance($secondcm->id));

        $this->assertSame(0, $DB->count_records('aiknowledgecheck_attempts', ['aiknowledgecheckid' => $second->id]));
        $this->assertSame(
            2,
            $this->count_rows('attempts'),
            'The other activity must be untouched.'
        );
    }
}
