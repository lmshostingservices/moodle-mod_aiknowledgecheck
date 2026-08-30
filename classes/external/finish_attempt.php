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
 * External service: complete an attempt and write the grade.
 *
 * MIGRATE-EXTERNAL-SERVICES (v1.5.152): seventh endpoint migrated from the legacy
 * ajax.php action dispatcher to a declared External Service.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_aiknowledgecheck\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/mod/aiknowledgecheck/lib.php');
require_once($CFG->libdir . '/completionlib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use context_module;
use completion_info;
use Throwable;

/**
 * Marks an attempt complete, scores it, and updates grades and completion.
 */
class finish_attempt extends external_api {
    /**
     * Describes the parameters accepted by execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'attemptid' => new external_value(PARAM_INT, 'ID of the in-progress attempt'),
            ]
        );
    }

    /**
     * Finish the attempt.
     *
     * @param int $attemptid Attempt ID.
     * @return array Result array matching execute_returns().
     */
    public static function execute(int $attemptid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['attemptid' => $attemptid]);
        $attemptid = (int)$params['attemptid'];

        $attempt = $DB->get_record('aiknowledgecheck_attempts', ['id' => $attemptid], '*', MUST_EXIST);
        $knowledgecheck = $DB->get_record('aiknowledgecheck', ['id' => $attempt->aiknowledgecheckid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('aiknowledgecheck', $knowledgecheck->id, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/aiknowledgecheck:view', $context);

        if ((int)$attempt->userid !== (int)$USER->id) {
            return self::result(false, 'Invalid attempt');
        }

        if ((int)$attempt->status !== 0) {
            return self::result(false, 'Attempt already completed');
        }

        // Calculate score — skip freetext answers (answer === -1).
        $answers = json_decode($attempt->answers, true);
        if (!is_array($answers)) {
            $answers = [];
        }
        $correctcount = 0;
        foreach ($answers as $ans) {
            if (isset($ans['answer']) && (int)$ans['answer'] === -1) {
                continue; // Free-text question — not scored.
            }
            if (!empty($ans['iscorrect'])) {
                $correctcount++;
            }
        }

        // The denominator is the activity's TOTAL scale questions, not just the number
        // answered — otherwise answering a single question correctly and finishing would
        // score 100% (and satisfy "all correct" completion). Unanswered scale questions
        // therefore count as incorrect.
        $totalcount = $DB->count_records_select(
            'aiknowledgecheck_questions',
            'aiknowledgecheckid = :kcid AND (questiontype IS NULL OR questiontype <> :ft)',
            ['kcid' => (int)$attempt->aiknowledgecheckid, 'ft' => 'freetext']
        );
        if ($totalcount < $correctcount) {
            $totalcount = $correctcount; // Safety — never fewer than the correct count.
        }

        // Update attempt.
        $now = time();
        $attempt->status = 1; // Completed.
        $attempt->correctcount = $correctcount;
        $attempt->totalcount = $totalcount;
        $attempt->timemodified = $now;
        $attempt->timeended = $now;
        $DB->update_record('aiknowledgecheck_attempts', $attempt);

        // Update the gradebook FIRST (before the completion check). Completion may depend on
        // "Require passing grade", which reads from the gradebook.
        aiknowledgecheck_update_grades($knowledgecheck, $USER->id);

        // Now update completion — the grade is already written, so the passing-grade check works.
        $completion = new completion_info($course);
        if ($completion->is_enabled($cm)) {
            $completion->update_state($cm, COMPLETION_UNKNOWN, $USER->id);
        }

        // Check whether the user has now used all attempts, and send the notification.
        if (!aiknowledgecheck_can_attempt($knowledgecheck, $USER->id)) {
            $user = $DB->get_record('user', ['id' => $USER->id]);
            aiknowledgecheck_send_attempts_notification($knowledgecheck, $course, $cm, $user);
        }

        // Return authoritative attempt counts so the client never drifts out of sync.
        $attemptsused = aiknowledgecheck_count_attempts($knowledgecheck->id, $USER->id);
        $canattempt = aiknowledgecheck_can_attempt($knowledgecheck, $USER->id);

        self::queue_remedial_job($attemptid, (int)$course->id, (int)$knowledgecheck->id);

        return self::result(true, '', $correctcount, $totalcount, $attemptsused, $canattempt);
    }

    /**
     * Queue an AI Quiz Remedial Learning job for this attempt, if that plugin is installed
     * and enabled. One umbrella job per attempt; the cron task expands it into per-question
     * jobs for each incorrect answer.
     *
     * @param int $attemptid Attempt ID.
     * @param int $courseid Course ID.
     * @param int $kcid Knowledge check instance ID.
     * @return void
     */
    private static function queue_remedial_job(int $attemptid, int $courseid, int $kcid): void {
        global $DB, $USER;

        if (!get_config('local_aiquizremedial', 'enabled')) {
            return;
        }

        try {
            $dbman = $DB->get_manager();
            if (!$dbman->table_exists('local_aiqr_job') || !$dbman->field_exists('local_aiqr_job', 'sourcetype')) {
                return;
            }
            if (
                $DB->record_exists(
                    'local_aiqr_job',
                    [
                    'attemptid'  => $attemptid,
                    'sourcetype' => 'knowledgecheck',
                    'questionid' => null,
                    ]
                )
            ) {
                return;
            }
            $DB->insert_record(
                'local_aiqr_job',
                (object) [
                    'userid'       => $USER->id,
                    'courseid'     => $courseid,
                    'quizid'       => null,
                    'kcid'         => $kcid,
                    'attemptid'    => $attemptid,
                    'questionid'   => null,
                    'sourcetype'   => 'knowledgecheck',
                    'status'       => 'pending',
                    'errormsg'     => null,
                    'timecreated'  => time(),
                    'timemodified' => time(),
                ]
            );
        } catch (Throwable $e) {
            // Remediation job creation is optional — never let it break the KC attempt.
            debugging('aiknowledgecheck: remedial job creation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Build a return payload with every declared key present.
     *
     * @param bool $ok Whether the attempt was finished.
     * @param string $error User-facing error message, empty on success.
     * @param int $correctcount Number of correct scale answers.
     * @param int $totalcount Total scale questions in the activity.
     * @param int $attemptsused Attempts used by this user after finishing.
     * @param bool $canattempt Whether the user may start another attempt.
     * @return array
     */
    private static function result(
        bool $ok,
        string $error = '',
        int $correctcount = 0,
        int $totalcount = 0,
        int $attemptsused = 0,
        bool $canattempt = false
    ): array {
        return [
            'ok' => $ok,
            'error' => $error,
            'correctcount' => $correctcount,
            'totalcount' => $totalcount,
            'attemptsUsed' => $attemptsused,
            'canAttempt' => $canattempt,
        ];
    }

    /**
     * Describes the value returned by execute().
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(
            [
                'ok' => new external_value(PARAM_BOOL, 'True when the attempt was finished'),
                'error' => new external_value(
                    PARAM_TEXT,
                    'User-facing reason the attempt could not be finished; empty when ok is true'
                ),
                'correctcount' => new external_value(PARAM_INT, 'Number of correct scale answers'),
                'totalcount' => new external_value(PARAM_INT, 'Total scale questions in the activity'),
                'attemptsUsed' => new external_value(PARAM_INT, 'Attempts used by this user after finishing'),
                'canAttempt' => new external_value(PARAM_BOOL, 'Whether the user may start another attempt'),
            ]
        );
    }
}
