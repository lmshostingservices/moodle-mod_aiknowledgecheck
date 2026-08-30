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
 * External service: start or resume a student attempt.
 *
 * MIGRATE-EXTERNAL-SERVICES (v1.5.152): fifth endpoint migrated from the legacy
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

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use context_module;
use stdClass;
use Exception;

/**
 * Starts a new attempt, or returns the caller's existing in-progress attempt.
 */
class start_attempt extends external_api {
    /**
     * Describes the parameters accepted by execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'cmid' => new external_value(PARAM_INT, 'Course module ID of the activity'),
            ]);
    }

    /**
     * Start or resume the calling user's attempt at this activity.
     *
     * @param int $cmid Course module ID.
     * @return array Result array matching execute_returns().
     */
    public static function execute(int $cmid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        $cm = get_coursemodule_from_id('aiknowledgecheck', $params['cmid'], 0, false, MUST_EXIST);
        $knowledgecheck = $DB->get_record('aiknowledgecheck', ['id' => $cm->instance], '*', MUST_EXIST);

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/aiknowledgecheck:view', $context);

        $userid = $USER->id;

        // Use a transaction to prevent race conditions (duplicate in-progress attempts).
        //
        // FIX-KC-TXN-DISPOSED (v1.5.152): the transaction now covers ONLY the database work,
        // and every return value is built after it has been committed. In ajax.php the
        // response building sat inside the try block after allow_commit(), so a throw from
        // any of it — a DB read in aiknowledgecheck_effective_maxattempts(), a get_string()
        // miss — reached a catch that called rollback() on an already-disposed transaction.
        // Moodle answers that with "Transactions already disposed", which would have replaced
        // the real error with a misleading one.
        $inprogress = null;
        $attemptid = 0;
        $transaction = $DB->start_delegated_transaction();
        try {
            $inprogress = $DB->get_record(
                'aiknowledgecheck_attempts', [
                    'aiknowledgecheckid' => $knowledgecheck->id,
                    'userid' => $userid,
                    'status' => 0,
                ]);

            if (!$inprogress && aiknowledgecheck_can_attempt($knowledgecheck, $userid)) {
                $now = time();
                $attempt = new stdClass();
                $attempt->aiknowledgecheckid = $knowledgecheck->id;
                $attempt->userid = $userid;
                $attempt->currentquestion = 0;
                $attempt->answers = '{}';
                $attempt->correctcount = 0;
                $attempt->totalcount = 0;
                $attempt->status = 0;
                $attempt->timecreated = $now;
                $attempt->timemodified = $now;
                $attempt->timestarted = $now;
                $attempt->timeended = null;

                $attemptid = (int)$DB->insert_record('aiknowledgecheck_attempts', $attempt);
            }

            $transaction->allow_commit();
        } catch (Exception $e) {
            $transaction->rollback($e);
            // Unreachable: rollback() rethrows. Kept for static analysis.
            return self::result(false, 'Failed to start attempt. Please try again.');
        }

        if ($inprogress) {
            $answers = json_decode($inprogress->answers, true);
            if (!is_array($answers)) {
                $answers = [];
            }
            return self::result(true, '', (int)$inprogress->id, true, (int)$inprogress->currentquestion, $answers);
        }

        if ($attemptid === 0) {
            // No in-progress attempt and no new one created: the user is out of attempts.
            $maxattempts = aiknowledgecheck_effective_maxattempts($knowledgecheck, $userid);
            return self::result(false, get_string('attemptslimitreached', 'mod_aiknowledgecheck', $maxattempts));
        }

        return self::result(true, '', $attemptid, false, 0, []);
    }

    /**
     * Build a return payload with every declared key present.
     *
     * @param bool $ok Whether the call succeeded.
     * @param string $error User-facing error message, empty on success.
     * @param int $attemptid The attempt ID, 0 when none was created.
     * @param bool $resumed True when an existing in-progress attempt was returned.
     * @param int $currentquestion Highest question number reached so far.
     * @param array $answers The attempt's answers map, keyed by question ID.
     * @return array
     */
    private static function result(
        bool $ok, string $error = '', int $attemptid = 0,
            bool $resumed = false, int $currentquestion = 0, array $answers = []): array {
        return [
            'ok' => $ok,
            'error' => $error,
            'attemptid' => $attemptid,
            'resumed' => $resumed,
            'currentquestion' => $currentquestion,
            // MIGRATE-EXTERNAL-SERVICES (v1.5.152): the answers map is keyed by question ID,
            // so its keys vary per activity. external_single_structure describes a fixed set
            // of named keys and cannot express that, and external_multiple_structure would
            // discard the keys — which are the question IDs the resume path needs. The map is
            // therefore passed across as a JSON string and parsed client-side, exactly as it
            // is stored in the attempt row. json_encode of an empty PHP array yields '[]',
            // which JSON.parse turns into an array; the client only ever calls Object.keys()
            // on it and iterates by question ID, so an empty array behaves identically to an
            // empty object there.
            'answersjson' => json_encode($answers),
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
                'ok' => new external_value(PARAM_BOOL, 'True when an attempt was started or resumed'),
                'error' => new external_value(PARAM_TEXT, 'User-facing reason the attempt could not be started; empty when ok is true'),
                'attemptid' => new external_value(PARAM_INT, 'ID of the started or resumed attempt, 0 on failure'),
                'resumed' => new external_value(PARAM_BOOL, 'True when an existing in-progress attempt was returned'),
                'currentquestion' => new external_value(PARAM_INT, 'Highest question number reached so far'),
                'answersjson' => new external_value(
                    PARAM_RAW, // pipeline-ignore: PARAM_RAW — JSON blob, JSON.parse()'d by the client
                    'The attempt answers map, keyed by question ID, as a JSON string'
                ),
            ]);
    }
}
