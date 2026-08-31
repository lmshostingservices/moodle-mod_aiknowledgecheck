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
 * External service: record a single answer during an attempt.
 *
 * MIGRATE-EXTERNAL-SERVICES (v1.5.152): sixth endpoint migrated from the legacy
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
use external_multiple_structure;
use external_single_structure;
use external_value;
use context_module;
use core_text;

/**
 * Records one answer against an in-progress attempt and grades it server-side.
 */
class save_answer extends external_api {
    /**
     * Describes the parameters accepted by execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'attemptid' => new external_value(PARAM_INT, 'ID of the in-progress attempt'),
                'questionid' => new external_value(PARAM_INT, 'ID of the question being answered'),
                'answerindex' => new external_value(
                    PARAM_INT,
                    'Selected option index in original option order, or -1 for a free-text response'
                ),
                // FIX-KC-PARAMTEXT-THROW (v1.5.152): raw at the boundary, cleaned below.
                // external_api::validate_parameters() REJECTS a value that clean_param would
                // alter — it throws invalid_parameter_exception rather than cleaning it, which is
                // the opposite of optional_param()'s behaviour in ajax.php. This is a student's
                // typed survey answer, so a '<' in it ("a < b", "<3") would have aborted the save
                // and silently lost the response.
                'freetextvalue' => new external_value(
                    /* phpcs:ignore moodle.Commenting.InlineComment */
                    PARAM_RAW, // pipeline-ignore: PARAM_RAW — cleaned with clean_param(PARAM_TEXT) in execute()
                    'Typed response for a free-text question',
                    VALUE_DEFAULT,
                    ''
                ),
            ]
        );
    }

    /**
     * Save one answer.
     *
     * @param int $attemptid Attempt ID.
     * @param int $questionid Question ID.
     * @param int $answerindex Selected option index, or -1 for free text.
     * @param string $freetextvalue Free-text response, when answerindex is -1.
     * @return array Result array matching execute_returns().
     */
    public static function execute(int $attemptid, int $questionid, int $answerindex, string $freetextvalue = ''): array {
        global $DB, $USER;

        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'attemptid' => $attemptid,
                'questionid' => $questionid,
                'answerindex' => $answerindex,
                'freetextvalue' => $freetextvalue,
            ]
        );

        $attempt = $DB->get_record('aiknowledgecheck_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $knowledgecheck = $DB->get_record('aiknowledgecheck', ['id' => $attempt->aiknowledgecheckid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('aiknowledgecheck', $knowledgecheck->id, 0, false, MUST_EXIST);

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/aiknowledgecheck:view', $context);

        // Verify the caller owns this attempt.
        if ((int)$attempt->userid !== (int)$USER->id) {
            return self::result(false, 'Invalid attempt');
        }

        if ((int)$attempt->status !== 0) {
            return self::result(false, 'Attempt already completed');
        }

        // Get the question and verify it belongs to the same activity as the attempt.
        $question = $DB->get_record('aiknowledgecheck_questions', ['id' => $params['questionid']], '*', MUST_EXIST);
        if ((int)$question->aiknowledgecheckid !== (int)$attempt->aiknowledgecheckid) {
            return self::result(false, 'Question does not belong to this activity');
        }

        $answerindex = (int)$params['answerindex'];

        // ADD-SURVEY-FREETEXT (v1.5.127): answerindex = -1 signals a free-text response.
        if ($answerindex === -1) {
            // Hardening (L-3): only accept the free-text branch for questions that are actually
            // free-text — a -1 on a scale question is an invalid/forged payload.
            if (($question->questiontype ?? 'scale') !== 'freetext') {
                return self::result(false, 'Invalid answer index');
            }
            // Cap the length (L-3) so the attempt's answers JSON can't be inflated without bound.
            $freetextclean = core_text::substr(clean_param($params['freetextvalue'], PARAM_TEXT), 0, 2000);
            $answers = self::decode_answers($attempt);
            $answers[$question->id] = [
                'answer'   => -1,
                'freetext' => $freetextclean,
            ];
            $attempt->answers = json_encode($answers);
            $attempt->currentquestion = max((int)$attempt->currentquestion, (int)$question->questionnumber);
            $attempt->timemodified = time();
            $DB->update_record('aiknowledgecheck_attempts', $attempt);

            return self::result(true);
        }

        // Scale / MCQ question: clamp answerindex to valid range (0-4 for 5-point scales).
        if ($answerindex < 0 || $answerindex > 4) {
            return self::result(false, 'Invalid answer index');
        }

        // Decode existing answers once (used for the first-answer-wins guard and the recount).
        $answers = self::decode_answers($attempt);

        // Survey scale response: store the selected option without evaluating or returning
        // correctness, the answer key, or feedback. Survey Mode is an ungraded response flow
        // end-to-end, not merely a quiz with hidden feedback.
        if (!empty($knowledgecheck->surveymode)) {
            $optionfield = 'answer' . ($answerindex + 1);
            if (empty($question->$optionfield)) {
                return self::result(false, 'Invalid answer index');
            }

            // Preserve first-answer-wins/idempotent retry behaviour without exposing any quiz
            // verdict or answer key.
            if (self::already_answered($answers, $question->id)) {
                return self::result(true, '', null, null, [], true);
            }

            $answers[$question->id] = ['answer' => $answerindex];
            $totalcount = 0;
            foreach ($answers as $ans) {
                if (isset($ans['answer']) && (int)$ans['answer'] !== -1) {
                    $totalcount++;
                }
            }

            $attempt->answers = json_encode($answers);
            $attempt->currentquestion = max((int)$attempt->currentquestion, (int)$question->questionnumber);
            $attempt->correctcount = 0;
            $attempt->totalcount = $totalcount;
            $attempt->timemodified = time();
            $DB->update_record('aiknowledgecheck_attempts', $attempt);

            return self::result(true);
        }

        // Per-option explanations (original option order); built here so both the
        // first-answer-wins path and the normal path can return them for feedback.
        $explanations = [
            (string)($question->feedback1 ?? ''),
            (string)($question->feedback2 ?? ''),
            (string)($question->feedback3 ?? ''),
            (string)($question->feedback4 ?? ''),
        ];
        if (!empty($question->answer5)) {
            $explanations[] = '';
        }

        // SECURITY (C-1): FIRST-ANSWER-WINS. If this scale question already has a recorded
        // answer in this attempt, do NOT re-grade or overwrite it. Return the ORIGINAL verdict
        // (idempotent for the legitimate retry-resend path) plus the key/explanations for
        // feedback. Without this a student could saveanswer(guess) -> read correctanswer from
        // the response -> saveanswer(correct) -> repeat, for a guaranteed 100%. The normal UI
        // only advances forward and wrong-only retry uses a NEW attempt id, so no legitimate
        // flow re-saves an already-answered scale question.
        if (self::already_answered($answers, $question->id)) {
            return self::result(
                true,
                '',
                !empty($answers[$question->id]['iscorrect']),
                (int)$question->correctanswer,
                $explanations,
                true
            );
        }

        $iscorrect = ($answerindex == $question->correctanswer);

        // Record this (first) answer for the question — freetext entries excluded from counts.
        $answers[$question->id] = [
            'answer' => $answerindex,
            'iscorrect' => $iscorrect,
        ];

        // Recalculate correct/total counts — exclude freetext answers (answer === -1).
        $correctcount = 0;
        $totalcount = 0;
        foreach ($answers as $ans) {
            if (isset($ans['answer']) && (int)$ans['answer'] === -1) {
                continue; // Free text — not counted.
            }
            $totalcount++;
            if (!empty($ans['iscorrect'])) {
                $correctcount++;
            }
        }

        $attempt->answers = json_encode($answers);
        // Track progress using question number (sequential index), not database ID.
        $attempt->currentquestion = max((int)$attempt->currentquestion, (int)$question->questionnumber);
        $attempt->correctcount = $correctcount;
        $attempt->totalcount = $totalcount;
        $attempt->timemodified = time();
        $DB->update_record('aiknowledgecheck_attempts', $attempt);

        // SECURITY (C2): the student has now answered, so it is safe to return the correct
        // index + explanations for feedback rendering.
        return self::result(true, '', $iscorrect, (int)$question->correctanswer, $explanations);
    }

    /**
     * Decode an attempt's stored answers map, tolerating an empty or malformed value.
     *
     * @param \stdClass $attempt The attempt record.
     * @return array Answers keyed by question ID.
     */
    private static function decode_answers(\stdClass $attempt): array {
        $answers = json_decode($attempt->answers, true);
        return is_array($answers) ? $answers : [];
    }

    /**
     * Whether this question already holds a non-free-text answer in the attempt.
     *
     * @param array $answers The decoded answers map.
     * @param int $questionid The question ID.
     * @return bool
     */
    private static function already_answered(array $answers, int $questionid): bool {
        return isset($answers[$questionid]['answer'])
            && (int)$answers[$questionid]['answer'] !== -1;
    }

    /**
     * Build a return payload with every declared key present.
     *
     * @param bool $ok Whether the answer was recorded.
     * @param string $error User-facing error message, empty on success.
     * @param bool|null $iscorrect The verdict, or null when withheld (survey/free text).
     * @param int|null $correctanswer The answer key index, or null when withheld.
     * @param array $explanations Per-option explanations in original option order.
     * @param bool $locked True when a previously recorded answer was returned unchanged.
     * @return array
     */
    private static function result(
        bool $ok,
        string $error = '',
        ?bool $iscorrect = null,
        ?int $correctanswer = null,
        array $explanations = [],
        bool $locked = false
    ): array {
        return [
            'ok' => $ok,
            'error' => $error,
            // Note: external_value honours null, so "no verdict was issued" stays distinct from
            // "the answer was wrong" and "option 0 is correct". The client tests
            // `typeof resp.correctanswer === 'number'` before using it, so null is ignored.
            'iscorrect' => $iscorrect,
            'correctanswer' => $correctanswer,
            // Note: external_multiple_structure rejects null outright (v1.5.151), so this is always
            // an array — empty whenever the key is withheld.
            'explanations' => $explanations,
            'locked' => $locked,
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
                'ok' => new external_value(PARAM_BOOL, 'True when the answer was recorded'),
                'error' => new external_value(PARAM_TEXT, 'User-facing reason the answer was rejected; empty when ok is true'),
                'iscorrect' => new external_value(
                    PARAM_BOOL,
                    'Whether the answer was correct, or null in survey mode and for free text'
                ),
                'correctanswer' => new external_value(
                    PARAM_INT,
                    'Index of the correct option in original order, or null when withheld'
                ),
                'explanations' => new external_multiple_structure(
                    new external_value(PARAM_TEXT, 'Explanation for the option at this index'),
                    'Per-option explanations in original option order; empty when withheld'
                ),
                'locked' => new external_value(PARAM_BOOL, 'True when a previously recorded answer was returned unchanged'),
            ]
        );
    }
}
