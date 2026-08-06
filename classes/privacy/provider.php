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
 * Privacy provider for AI Knowledge Check.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_aiknowledgecheck\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\local\request\transform;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy provider implementation.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {
    /**
     * Get metadata about data stored by this plugin.
     *
     * @param collection $collection The metadata collection.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'aiknowledgecheck_attempts',
            [
                'userid' => 'privacy:metadata:aiknowledgecheck_attempts:userid',
                'answers' => 'privacy:metadata:aiknowledgecheck_attempts:answers',
                'timestarted' => 'privacy:metadata:aiknowledgecheck_attempts:timestarted',
                'timeended' => 'privacy:metadata:aiknowledgecheck_attempts:timeended',
            ],
            'privacy:metadata:aiknowledgecheck_attempts'
        );

        $collection->add_external_location_link(
            'essaygraderai',
            [
                'topicdata' => 'privacy:metadata:essaygraderai:topicdata',
            ],
            'privacy:metadata:essaygraderai'
        );

        return $collection;
    }

    /**
     * Get contexts that contain user data.
     *
     * @param int $userid The user ID.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {aiknowledgecheck} kc ON kc.id = cm.instance
                  JOIN {aiknowledgecheck_attempts} ka ON ka.aiknowledgecheckid = kc.id
                 WHERE ka.userid = :userid";

        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'aiknowledgecheck',
            'userid' => $userid,
        ];

        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Get users in a context.
     *
     * @param userlist $userlist The userlist.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        $sql = "SELECT ka.userid
                  FROM {aiknowledgecheck_attempts} ka
                  JOIN {aiknowledgecheck} kc ON kc.id = ka.aiknowledgecheckid
                  JOIN {course_modules} cm ON cm.instance = kc.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                 WHERE cm.id = :cmid";

        $params = [
            'modname' => 'aiknowledgecheck',
            'cmid' => $context->instanceid,
        ];

        $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Export user data.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_MODULE) {
                continue;
            }

            $cm = get_coursemodule_from_id('aiknowledgecheck', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $attempts = $DB->get_records('aiknowledgecheck_attempts', [
                'aiknowledgecheckid' => $cm->instance,
                'userid' => $userid,
            ]);

            foreach ($attempts as $attempt) {
                $data = (object) [
                    'correctcount' => $attempt->correctcount,
                    'totalcount' => $attempt->totalcount,
                    'status' => $attempt->status,
                    'timestarted' => transform::datetime($attempt->timestarted),
                    'timeended' => transform::datetime($attempt->timeended),
                ];

                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'mod_aiknowledgecheck'), 'attempts'],
                    $data
                );
            }

        }
    }

    /**
     * Delete data for all users in a context.
     *
     * @param \context $context The context.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }

        $cm = get_coursemodule_from_id('aiknowledgecheck', $context->instanceid);
        if (!$cm) {
            return;
        }

        $DB->delete_records('aiknowledgecheck_attempts', ['aiknowledgecheckid' => $cm->instance]);
        $DB->delete_records('aiknowledgecheck_overrides', ['aiknowledgecheckid' => $cm->instance]);
    }

    /**
     * Delete data for a specific user.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_MODULE) {
                continue;
            }

            $cm = get_coursemodule_from_id('aiknowledgecheck', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $DB->delete_records('aiknowledgecheck_attempts', [
                'aiknowledgecheckid' => $cm->instance,
                'userid' => $userid,
            ]);

            $DB->delete_records('aiknowledgecheck_overrides', [
                'aiknowledgecheckid' => $cm->instance,
                'userid' => $userid,
            ]);
        }
    }

    /**
     * Delete data for users in a context.
     *
     * @param approved_userlist $userlist The approved userlist.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();

        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }

        $cm = get_coursemodule_from_id('aiknowledgecheck', $context->instanceid);
        if (!$cm) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params = array_merge(['aiknowledgecheckid' => $cm->instance], $inparams);

        $DB->delete_records_select(
            'aiknowledgecheck_attempts',
            "aiknowledgecheckid = :aiknowledgecheckid AND userid $insql",
            $params
        );

        $DB->delete_records_select(
            'aiknowledgecheck_overrides',
            "aiknowledgecheckid = :aiknowledgecheckid AND userid $insql",
            $params
        );
    }
}
