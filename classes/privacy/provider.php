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

        $collection->add_database_table(
            'aiknowledgecheck_overrides',
            [
                'userid' => 'privacy:metadata:aiknowledgecheck_overrides:userid',
                'extraattempts' => 'privacy:metadata:aiknowledgecheck_overrides:extraattempts',
            ],
            'privacy:metadata:aiknowledgecheck_overrides'
        );

        // FIX-KC-PRIVACY-QUIZZES (v1.5.143): the aiknowledgecheck_quizzes table carries a
        // userid and must be declared here so user data can be exported and deleted. The
        // table is vestigial — nothing in the plugin writes to it any more — but it is still
        // created by db/install.xml, so any legacy rows must remain discoverable under the
        // Privacy API. The language strings for it already existed.
        $collection->add_database_table(
            'aiknowledgecheck_quizzes',
            [
                'userid' => 'privacy:metadata:aiknowledgecheck_quizzes:userid',
                'title' => 'privacy:metadata:aiknowledgecheck_quizzes:title',
                'timecreated' => 'privacy:metadata:aiknowledgecheck_quizzes:timecreated',
            ],
            'privacy:metadata:aiknowledgecheck_quizzes'
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

        // M-3: include contexts where the user has an OVERRIDE but no attempt, otherwise an
        // override-only user is invisible to export/delete.
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {aiknowledgecheck} kc ON kc.id = cm.instance
                  JOIN {aiknowledgecheck_attempts} ka ON ka.aiknowledgecheckid = kc.id
                 WHERE ka.userid = :userid
                 UNION
                SELECT ctx2.id
                  FROM {context} ctx2
                  JOIN {course_modules} cm2 ON cm2.id = ctx2.instanceid AND ctx2.contextlevel = :contextlevel2
                  JOIN {modules} m2 ON m2.id = cm2.module AND m2.name = :modname2
                  JOIN {aiknowledgecheck} kc2 ON kc2.id = cm2.instance
                  JOIN {aiknowledgecheck_overrides} ko ON ko.aiknowledgecheckid = kc2.id
                 WHERE ko.userid = :userid2";

        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'aiknowledgecheck',
            'userid' => $userid,
            'contextlevel2' => CONTEXT_MODULE,
            'modname2' => 'aiknowledgecheck',
            'userid2' => $userid,
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

        // M-3: include override-only users (userid present in overrides but not attempts).
        $sql = "SELECT ka.userid
                  FROM {aiknowledgecheck_attempts} ka
                  JOIN {aiknowledgecheck} kc ON kc.id = ka.aiknowledgecheckid
                  JOIN {course_modules} cm ON cm.instance = kc.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                 WHERE cm.id = :cmid
                 UNION
                SELECT ko.userid
                  FROM {aiknowledgecheck_overrides} ko
                  JOIN {aiknowledgecheck} kc2 ON kc2.id = ko.aiknowledgecheckid
                  JOIN {course_modules} cm2 ON cm2.instance = kc2.id
                  JOIN {modules} m2 ON m2.id = cm2.module AND m2.name = :modname2
                 WHERE cm2.id = :cmid2";

        $params = [
            'modname' => 'aiknowledgecheck',
            'cmid' => $context->instanceid,
            'modname2' => 'aiknowledgecheck',
            'cmid2' => $context->instanceid,
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

            $attempts = $DB->get_records(
                'aiknowledgecheck_attempts', [
                    'aiknowledgecheckid' => $cm->instance,
                    'userid' => $userid,
                ]);

            foreach ($attempts as $attempt) {
                // H-4: include the actual answers (selected options AND free-text responses).
                // This field is declared personal data but was previously omitted from export.
                $decodedanswers = null;
                if (!empty($attempt->answers)) {
                    $decodedanswers = json_decode($attempt->answers, true);
                }

                $data = (object) [
                    'correctcount' => $attempt->correctcount,
                    'totalcount' => $attempt->totalcount,
                    'status' => $attempt->status,
                    'answers' => $decodedanswers,
                    'timestarted' => transform::datetime($attempt->timestarted),
                    'timeended' => transform::datetime($attempt->timeended),
                ];

                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'mod_aiknowledgecheck'), 'attempts'],
                    $data
                );
            }

            // M-3: export the user's attempt-limit overrides for this activity.
            $overrides = $DB->get_records(
                'aiknowledgecheck_overrides', [
                    'aiknowledgecheckid' => $cm->instance,
                    'userid' => $userid,
                ]);
            foreach ($overrides as $override) {
                $odata = (object) [
                    'extraattempts' => $override->extraattempts,
                    'timecreated' => !empty($override->timecreated) ? transform::datetime($override->timecreated) : null,
                    'timemodified' => !empty($override->timemodified) ? transform::datetime($override->timemodified) : null,
                ];
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'mod_aiknowledgecheck'), 'overrides'],
                    $odata
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

        // FIX-KC-PRIVACY-QUIZZES (v1.5.143): the legacy quizzes table is guarded with
        // table_exists() because it is vestigial — some sites may not have it — and an
        // erasure request must not fail on a missing table.
        if ($DB->get_manager()->table_exists('aiknowledgecheck_quizzes')) {
            $DB->delete_records('aiknowledgecheck_quizzes', ['aiknowledgecheckid' => $cm->instance]);
        }
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

            $DB->delete_records(
                'aiknowledgecheck_attempts', [
                    'aiknowledgecheckid' => $cm->instance,
                    'userid' => $userid,
                ]);

            $DB->delete_records(
                'aiknowledgecheck_overrides', [
                    'aiknowledgecheckid' => $cm->instance,
                    'userid' => $userid,
                ]);

        // FIX-KC-PRIVACY-QUIZZES (v1.5.143): the legacy quizzes table is guarded with
        // table_exists() because it is vestigial — some sites may not have it — and an
        // erasure request must not fail on a missing table.
            if ($DB->get_manager()->table_exists('aiknowledgecheck_quizzes')) {
                $DB->delete_records(
                    'aiknowledgecheck_quizzes', [
                        'aiknowledgecheckid' => $cm->instance,
                        'userid' => $userid,
                    ]);
            }
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

        // FIX-KC-PRIVACY-QUIZZES (v1.5.143): the legacy quizzes table is guarded with
        // table_exists() because it is vestigial — some sites may not have it — and an
        // erasure request must not fail on a missing table.
        if ($DB->get_manager()->table_exists('aiknowledgecheck_quizzes')) {
            $DB->delete_records_select(
                'aiknowledgecheck_quizzes',
                "aiknowledgecheckid = :aiknowledgecheckid AND userid $insql",
                $params
            );
        }
    }
}
