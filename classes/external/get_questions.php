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
 * External service: fetch the stored questions for an activity.
 *
 * MIGRATE-EXTERNAL-SERVICES (v1.5.148): third endpoint migrated from the legacy
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

/**
 * Returns the questions belonging to an AI Knowledge Check activity.
 */
class get_questions extends external_api {
    /**
     * Describes the parameters accepted by execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'cmid' => new external_value(PARAM_INT, 'Course module ID of the activity'),
            ]
        );
    }

    /**
     * Fetch the activity's questions, withholding the answer key from students.
     *
     * @param int $cmid Course module ID.
     * @return array Result array matching execute_returns().
     */
    public static function execute(int $cmid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        $cm = get_coursemodule_from_id('aiknowledgecheck', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/aiknowledgecheck:view', $context);

        // SECURITY (C2): the correct-answer key and per-option explanations must NOT be sent
        // to students at attempt start — they were readable from the Network tab before
        // answering. Only users who can author or report on the activity receive them.
        // Students get the correct answer and explanation for a question ONLY in the
        // saveanswer response, i.e. after they have answered it. Grading is server-side and
        // authoritative regardless, so withholding the key here does not affect scoring.
        $canseeanswers = has_capability('mod/aiknowledgecheck:create', $context)
            || has_capability('mod/aiknowledgecheck:viewreports', $context)
            || has_capability('mod/aiknowledgecheck:addinstance', $context);

        $questions = $DB->get_records(
            'aiknowledgecheck_questions',
            ['aiknowledgecheckid' => $cm->instance],
            'questionnumber ASC'
        );

        $result = [];
        foreach ($questions as $q) {
            // The audiodata column holds a JSON list of base64 strings, one per answer option,
            // indexed positionally: audioData[i] is the clip for option i. It is permuted in
            // lockstep with the options when they are shuffled client-side. Null when the
            // question has no generated audio; that distinction is preserved here because the
            // client uses it to decide whether audio needs generating.
            // FIX-KC-EXTERNAL-AUDIODATA (v1.5.151): this returned null when a question had no
            // generated audio. external_multiple_structure rejects null outright with
            // "Only arrays accepted" — unlike external_value, it does not honour a null-allowed
            // default — so the whole call failed with "Invalid response value detected" and the
            // activity fell back to showing the question generator as if no questions existed.
            // An empty array is returned instead. The client treats [] and null identically:
            // it only ever tests truthiness, .length and positional indexes, all of which
            // behave the same for an empty array.
            $audiodata = [];
            if (!empty($q->audiodata)) {
                $decoded = json_decode($q->audiodata, true);
                if (is_array($decoded)) {
                    // Using array_values() guards against a legacy row having been stored as a JSON
                    // object rather than a list, which would otherwise break the declared
                    // return structure.
                    $audiodata = array_map('strval', array_values($decoded));
                }
            }

            // FIX-KC-RETURNTYPE-CLEAN (v1.5.152): the text fields are declared PARAM_TEXT
            // rather than the raw type, and are cleaned HERE, on the way out. clean_returnvalue()
            // validates rather than cleans — it throws "Invalid response value detected" on any
            // value clean_param would alter, and the whole call fails with an HTTP 200. Cleaning
            // at build time makes the declared type idempotent, so a legacy row holding stray
            // markup can never take the endpoint down. The client escapes every one of these
            // fields before rendering (.text() or escapeHtml()), so nothing depended on the
            // markup surviving.
            $text = function ($value): string {
                return clean_param((string)$value, PARAM_TEXT);
            };

            $options = mod_aiknowledgecheck_trim_options(
                [
                    ['text' => $text($q->answer1), 'explanation' => $canseeanswers ? $text($q->feedback1 ?? '') : ''],
                    ['text' => $text($q->answer2), 'explanation' => $canseeanswers ? $text($q->feedback2 ?? '') : ''],
                    ['text' => $text($q->answer3), 'explanation' => $canseeanswers ? $text($q->feedback3 ?? '') : ''],
                    ['text' => $text($q->answer4), 'explanation' => $canseeanswers ? $text($q->feedback4 ?? '') : ''],
                    // ADD-SURVEY-MODE (v1.5.126): 5th option for 5-point scales.
                    (!empty($q->answer5)) ? ['text' => $text($q->answer5), 'explanation' => ''] : null,
                ],
                ($q->questiontype ?? 'scale')
            );

            $result[] = [
                'id' => (int)$q->id,
                'questionnumber' => (int)$q->questionnumber,
                'question' => $text($q->questiontext),
                'options' => $options,
                // SECURITY (C2): null for students. external_value allows null by default, so
                // the distinction between "no answer key supplied" and "the answer is option 0"
                // survives the migration — collapsing it to 0 would mark the wrong option.
                'correctIndex' => $canseeanswers ? (int)$q->correctanswer : null,
                'audioData' => $audiodata,
                'mappingTopic' => $text($q->mappingtopic ?? ''),
                'mappingCriteria' => $text($q->mappingcriteria ?? ''),
                'timestamp_seconds' => (isset($q->timestamp_seconds) && $q->timestamp_seconds !== null)
                    ? (int)$q->timestamp_seconds : null,
                // ADD-KC-IMAGEGATE (v1.5.115): per-question image data.
                'imageUrl' => (string)($q->imageurl ?? ''),
                'imageEnabled' => isset($q->imageenabled) ? (int)$q->imageenabled : 0,
                // ADD-KC-MEDIAPER-Q (v1.5.120): per-question video and audio data.
                'questionVideoUrl' => clean_param((string)($q->questionvideourl ?? ''), PARAM_URL),
                'questionVideoEnabled' => isset($q->questionvideoenabled) ? (int)$q->questionvideoenabled : 0,
                'questionAudioUrl' => clean_param((string)($q->questionaudiourl ?? ''), PARAM_URL),
                'questionAudioEnabled' => isset($q->questionaudioenabled) ? (int)$q->questionaudioenabled : 0,
                // ADD-SURVEY-FREETEXT (v1.5.127): question type.
                'questionType' => !empty($q->questiontype) ? (string)$q->questiontype : 'scale',
            ];
        }

        return ['ok' => true, 'questions' => $result];
    }

    /**
     * Describes the value returned by execute().
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(
            [
                'ok' => new external_value(PARAM_BOOL, 'Always true; failures raise an exception'),
                'questions' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'id' => new external_value(PARAM_INT, 'Question ID'),
                            'questionnumber' => new external_value(PARAM_INT, 'Position of the question in the activity'),
                            'question' => new external_value(PARAM_TEXT, 'Question text'),
                            'options' => new external_multiple_structure(
                                new external_single_structure(
                                    [
                                        'text' => new external_value(PARAM_TEXT, 'Answer option text'),
                                        'explanation' => new external_value(
                                            PARAM_TEXT,
                                            'Explanation for this option; empty string for students'
                                        ),
                                    ]
                                ),
                                'Answer options, 0 for free-text questions'
                            ),
                            'correctIndex' => new external_value(
                                PARAM_INT,
                                'Index of the correct option, or null when withheld from students'
                            ),
                            'audioData' => new external_multiple_structure(
                                new external_value(
                                    PARAM_RAW, // pipeline-ignore: PARAM_RAW — base64 audio payload
                                    'Base64 audio clip for the option at this index'
                                ),
                                'Spoken explanations indexed by option; empty when the question has no audio'
                            ),
                            'mappingTopic' => new external_value(PARAM_TEXT, 'Topic this question maps to'),
                            'mappingCriteria' => new external_value(PARAM_TEXT, 'Criteria this question maps to'),
                            'timestamp_seconds' => new external_value(
                                PARAM_INT,
                                'Video position this question relates to, or null'
                            ),
                            'imageUrl' => new external_value(
                                PARAM_RAW, // pipeline-ignore: PARAM_RAW — data:image URL, sanitised on write
                                'Per-question image URL'
                            ),
                            'imageEnabled' => new external_value(PARAM_INT, '1 if the image gate is enabled'),
                            'questionVideoUrl' => new external_value(PARAM_URL, 'Per-question video URL'),
                            'questionVideoEnabled' => new external_value(PARAM_INT, '1 if the video gate is enabled'),
                            'questionAudioUrl' => new external_value(PARAM_URL, 'Per-question audio URL'),
                            'questionAudioEnabled' => new external_value(PARAM_INT, '1 if the audio gate is enabled'),
                            'questionType' => new external_value(PARAM_ALPHA, 'Either "scale" or "freetext"'),
                        ]
                    ),
                    'Questions in activity order'
                ),
            ]
        );
    }
}
