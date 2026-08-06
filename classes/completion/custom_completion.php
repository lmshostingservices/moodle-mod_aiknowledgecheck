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
 * Custom activity completion for AI Knowledge Check.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_aiknowledgecheck\completion;

use core_completion\activity_custom_completion;

/**
 * Custom activity completion class for Knowledge Check.
 */
class custom_completion extends activity_custom_completion {
    /**
     * Fetches the completion state for a given completion rule.
     *
     * @param string $rule The completion rule.
     * @return int The completion state.
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);

        switch ($rule) {
            case 'completionallcorrect':
                $kid = (int)$this->cm->instance;
                $userid = (int)$this->userid;

                // Check if ANY completed attempt has all correct.
                $attempt = $DB->get_record_sql(
                    "SELECT *
                       FROM {aiknowledgecheck_attempts}
                      WHERE aiknowledgecheckid = :kid
                        AND userid = :userid
                        AND status = 1
                        AND correctcount > 0
                        AND correctcount = totalcount
                   ORDER BY timeended DESC, id DESC",
                    ['kid' => $kid, 'userid' => $userid],
                    IGNORE_MULTIPLE
                );

                if ($attempt) {
                    return COMPLETION_COMPLETE;
                }

                // Fallback: check the most recent completed attempt via answers JSON.
                $attempt = $DB->get_record_sql(
                    "SELECT *
                       FROM {aiknowledgecheck_attempts}
                      WHERE aiknowledgecheckid = :kid2
                        AND userid = :userid2
                        AND status = 1
                   ORDER BY timeended DESC, timemodified DESC, id DESC",
                    ['kid2' => $kid, 'userid2' => $userid],
                    IGNORE_MULTIPLE
                );

                if (!$attempt) {
                    return COMPLETION_INCOMPLETE;
                }

                if (property_exists($attempt, 'correctcount') && property_exists($attempt, 'totalcount')
                    && $attempt->totalcount !== null && $attempt->totalcount !== '') {
                    $total = (int)$attempt->totalcount;
                    $correct = (int)$attempt->correctcount;
                    return ($total > 0 && $correct === $total) ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
                }

                $answers = [];
                if (!empty($attempt->answers)) {
                    $decoded = json_decode($attempt->answers, true);
                    if (is_array($decoded)) {
                        $answers = $decoded;
                    }
                }

                $totalquestions = (int)$DB->count_records('aiknowledgecheck_questions', ['aiknowledgecheckid' => $kid]);
                if ($totalquestions <= 0) {
                    return COMPLETION_INCOMPLETE;
                }

                $allcorrect = true;
                $answeredcount = 0;
                foreach ($answers as $qid => $ainfo) {
                    $answeredcount++;
                    $iscorrect = is_array($ainfo)
                        ? (!empty($ainfo['iscorrect']) || (isset($ainfo['iscorrect']) && (int)$ainfo['iscorrect'] === 1))
                        : (bool)$ainfo;
                    if (!$iscorrect) {
                        $allcorrect = false;
                        break;
                    }
                }

                if ($answeredcount !== $totalquestions) {
                    $allcorrect = false;
                }

                return $allcorrect ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;

            default:
                return COMPLETION_UNKNOWN;
        }
    }

    /**
     * Fetch the list of custom completion rules that this module defines.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array {
        return [
            'completionallcorrect',
        ];
    }

    /**
     * Returns an associative array of the descriptions of custom completion rules.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        return [
            'completionallcorrect' => get_string('completiondetail:completionallcorrect', 'mod_aiknowledgecheck'),
        ];
    }

    /**
     * Returns an array of all completion rules, in the order they should be displayed to users.
     *
     * @return array
     */
    public function get_sort_order(): array {
        return [
            'completionview',
            'completionusegrade',
            'completionpassgrade',
            'completionallcorrect',
        ];
    }
}
