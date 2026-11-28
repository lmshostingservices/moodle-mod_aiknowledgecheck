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
 * External service: approve the activity's AI-written questions for learners, or withdraw approval.
 *
 * Human oversight (EU AI Act Article 14): with approval required, learners see nothing the AI wrote until a named
 * teacher has checked it and confirmed that they have. Approval is withdrawn automatically whenever the AI replaces
 * the questions.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2026 LMS Hosting Services
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
use mod_aiknowledgecheck\local\responsibleai;

/**
 * Approves or withdraws the activity's questions.
 */
class approve_questions extends external_api {
    /**
     * Describes the parameters accepted by execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'cmid' => new external_value(PARAM_INT, 'Course module ID of the activity'),
                'approve' => new external_value(PARAM_INT, '1 to approve the current questions, 0 to withdraw approval'),
                'confirmed' => new external_value(
                    PARAM_INT,
                    '1 when the teacher has confirmed they checked every question, answer key and explanation',
                    VALUE_DEFAULT,
                    0
                ),
            ]
        );
    }

    /**
     * Approve or withdraw.
     *
     * @param int $cmid Course module id.
     * @param int $approve 1 to approve, 0 to withdraw.
     * @param int $confirmed 1 when the review was confirmed.
     * @return array
     */
    public static function execute(int $cmid, int $approve, int $confirmed = 0): array {
        global $DB, $USER;

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'approve' => $approve, 'confirmed' => $confirmed]
        );

        $cm = get_coursemodule_from_id('aiknowledgecheck', $params['cmid'], 0, false, MUST_EXIST);
        $knowledgecheck = $DB->get_record('aiknowledgecheck', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/aiknowledgecheck:approve', $context);

        $stats = responsibleai::oversight_stats((int)$knowledgecheck->id);

        if (!empty($params['approve'])) {
            if ($stats['total'] === 0) {
                return self::result(false, get_string('error:zeroquestions', 'mod_aiknowledgecheck'), $knowledgecheck, $stats);
            }
            if (empty($params['confirmed'])) {
                return self::result(false, get_string('approve_confirm_required', 'mod_aiknowledgecheck'), $knowledgecheck, $stats);
            }
            $now = time();
            $DB->update_record('aiknowledgecheck', (object)[
                'id' => $knowledgecheck->id,
                'approvedby' => $USER->id,
                'timeapproved' => $now,
            ]);
            $knowledgecheck->approvedby = $USER->id;
            $knowledgecheck->timeapproved = $now;
            responsibleai::log((int)$knowledgecheck->id, responsibleai::ACTION_APPROVED, [
                'questions' => $stats['total'],
                'aiquestions' => $stats['ai'],
                'changed' => $stats['changed'],
                'teacherwritten' => $stats['teacher'],
            ]);
        } else {
            $DB->update_record('aiknowledgecheck', (object)[
                'id' => $knowledgecheck->id,
                'approvedby' => 0,
                'timeapproved' => 0,
            ]);
            $knowledgecheck->approvedby = 0;
            $knowledgecheck->timeapproved = 0;
            responsibleai::log((int)$knowledgecheck->id, responsibleai::ACTION_WITHDRAWN, []);
        }

        return self::result(true, '', $knowledgecheck, $stats);
    }

    /**
     * Build the response.
     *
     * @param bool $ok Success.
     * @param string $error Error message.
     * @param \stdClass $knowledgecheck Activity record.
     * @param array $stats Oversight figures.
     * @return array
     */
    private static function result(bool $ok, string $error, \stdClass $knowledgecheck, array $stats): array {
        $approved = !empty($knowledgecheck->timeapproved);
        return [
            'ok' => $ok,
            'error' => $error,
            'approved' => $approved,
            'status' => $approved
                ? get_string('review_status_approved', 'mod_aiknowledgecheck', (object)[
                    'name' => responsibleai::approver_name($knowledgecheck) ?: get_string('review_someone', 'mod_aiknowledgecheck'),
                    'date' => userdate((int)$knowledgecheck->timeapproved, get_string('strftimedatetimeshort', 'langconfig')),
                ])
                : get_string('review_status_draft', 'mod_aiknowledgecheck'),
            'changed' => (int)$stats['changed'],
            'aiquestions' => (int)$stats['ai'],
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
                'ok' => new external_value(PARAM_BOOL, 'True when the change was stored'),
                'error' => new external_value(PARAM_TEXT, 'Error message, empty on success'),
                'approved' => new external_value(PARAM_BOOL, 'Whether the questions are now approved for learners'),
                'status' => new external_value(PARAM_TEXT, 'Status line to show the teacher'),
                'changed' => new external_value(PARAM_INT, 'AI-written questions a person changed before approval'),
                'aiquestions' => new external_value(PARAM_INT, 'Questions written by AI'),
            ]
        );
    }
}
