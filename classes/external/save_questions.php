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
 * External service: replace an activity's stored questions.
 *
 * MIGRATE-EXTERNAL-SERVICES (v1.5.152): ninth endpoint migrated from the legacy ajax.php
 * action dispatcher to a declared External Service.
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

/**
 * Writes a generated or edited question set to the database.
 */
class save_questions extends external_api {
    /**
     * Describes the parameters accepted by execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'cmid' => new external_value(PARAM_INT, 'Course module ID of the activity'),
                // MIGRATE-EXTERNAL-SERVICES (v1.5.152): the question documents are deeply nested
                // and their shape varies with question type, generation source and which optional
                // media fields are present, so no external_*_structure describes them without
                // pinning a shape the generation service is free to change. They cross as the same
                // JSON string ajax.php received and are validated field by field below, exactly as
                // before.
                'questions' => new external_value(
                    PARAM_RAW, // pipeline-ignore: PARAM_RAW — JSON payload, json_decode()'d below
                    'JSON array of question documents'
                ),
                'voiceoverEnabled' => new external_value(
                    PARAM_INT,
                    '1 or 0 to set explicitly, -1 to auto-detect from the audio data',
                    VALUE_DEFAULT,
                    -1
                ),
                'voiceLanguage' => new external_value(PARAM_TEXT, 'Voice language code, empty to leave unchanged', VALUE_DEFAULT, ''),
                'voiceGender' => new external_value(PARAM_ALPHA, 'Voice gender, empty to leave unchanged', VALUE_DEFAULT, ''),
                'voiceStyle' => new external_value(PARAM_ALPHA, 'Voice style, empty to leave unchanged', VALUE_DEFAULT, ''),
            ]);
    }

    /**
     * Replace the activity's questions with the supplied set.
     *
     * @param int $cmid Course module ID.
     * @param string $questions JSON array of question documents.
     * @param int $voiceoverenabled 1/0 explicit, -1 to auto-detect.
     * @param string $voicelanguage Voice language code.
     * @param string $voicegender Voice gender.
     * @param string $voicestyle Voice style.
     * @return array Result array matching execute_returns().
     */
    public static function execute(
        int $cmid, string $questions, int $voiceoverenabled = -1,
            string $voicelanguage = '', string $voicegender = '', string $voicestyle = ''): array {
        global $DB;

        $params = self::validate_parameters(
            self::execute_parameters(), [
                'cmid' => $cmid,
                'questions' => $questions,
                'voiceoverEnabled' => $voiceoverenabled,
                'voiceLanguage' => $voicelanguage,
                'voiceGender' => $voicegender,
                'voiceStyle' => $voicestyle,
            ]);

        $cm = get_coursemodule_from_id('aiknowledgecheck', $params['cmid'], 0, false, MUST_EXIST);
        $knowledgecheck = $DB->get_record('aiknowledgecheck', ['id' => $cm->instance], '*', MUST_EXIST);

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/aiknowledgecheck:create', $context);

        $questionsdata = json_decode($params['questions'], true);
        if (!is_array($questionsdata)) {
            return ['ok' => false, 'error' => get_string('error:invalidquestions', 'mod_aiknowledgecheck'),
                'saved' => 0];
        }

        // FIX-KC-SERVER-GUARD: refuse to save an empty question list. Saving zero questions
        // would DELETE all existing questions without inserting replacements — a silent
        // data-loss path triggered by the JS when quizData is unexpectedly empty.
        if (count($questionsdata) === 0) {
            return ['ok' => false, 'error' => get_string('error:zeroquestions', 'mod_aiknowledgecheck'),
                'saved' => 0];
        }

        $DB->delete_records('aiknowledgecheck_questions', ['aiknowledgecheckid' => $cm->instance]);

        $questionnumber = 1;
        foreach ($questionsdata as $q) {
            if (!is_array($q)) {
                $q = [];
            }
            $DB->insert_record('aiknowledgecheck_questions', self::build_record($q, $questionnumber++, $cm, $knowledgecheck));
        }

        $DB->set_field('aiknowledgecheck', 'questioncount', count($questionsdata), ['id' => $cm->instance]);

        // FIX-KC-Q1-FRESH (v1.5.107): a newly saved question set makes any in-progress attempt
        // stale — its saved answers reference question IDs that no longer exist, so the next
        // start_attempt would resume from it and the quiz would skip Q1. Delete them.
        $DB->delete_records(
            'aiknowledgecheck_attempts', [
                'aiknowledgecheckid' => $cm->instance,
                'status' => 0,
            ]);

        self::persist_voice_settings($cm, $questionsdata, $params);

        return ['ok' => true, 'error' => '', 'saved' => count($questionsdata)];
    }

    /**
     * Build one question row from a supplied question document.
     *
     * FIX-KC-SURVEY-SAVE (v1.5.138): never hand a non-scalar to the DB layer. A malformed
     * payload used to travel all the way into mysqli and die with "real_escape_string():
     * Argument #1 must be of type string, array given". The teacher saw an unexplained
     * "Questions could not be saved" alert, and by then the delete above had ALREADY removed
     * their existing questions — so a bad payload cost them the whole question set and told
     * them nothing useful.
     *
     * @param array $q One question document.
     * @param int $questionnumber Its 1-based position.
     * @param \stdClass $cm Course module record.
     * @param \stdClass $knowledgecheck Activity record.
     * @return \stdClass The row to insert.
     */
    private static function build_record(array $q, int $questionnumber, \stdClass $cm, \stdClass $knowledgecheck): stdClass {
        $record = new stdClass();
        $record->aiknowledgecheckid = $cm->instance;
        $record->questionnumber = $questionnumber;
        $record->questiontext = self::str($q['question'] ?? '');
        $record->answer1 = self::option($q, 0, 'text');
        $record->answer2 = self::option($q, 1, 'text');
        $record->answer3 = self::option($q, 2, 'text');
        $record->answer4 = self::option($q, 3, 'text');
        // ADD-SURVEY-MODE (v1.5.126): 5th option for 5-point survey scales.
        $record->answer5 = isset($q['options'][4]) ? self::option($q, 4, 'text') : null;

        // ADD-SURVEY-FREETEXT (v1.5.127): question type.
        $record->questiontype = isset($q['questionType'])
            ? clean_param(self::str($q['questionType']), PARAM_ALPHA) : 'scale';
        if (!in_array($record->questiontype, ['scale', 'freetext'], true)) {
            $record->questiontype = 'scale';
        }

        $record->correctanswer = (int)self::str($q['correctIndex'] ?? 0);
        $record->feedback1 = self::option($q, 0, 'explanation');
        $record->feedback2 = self::option($q, 1, 'explanation');
        $record->feedback3 = self::option($q, 2, 'explanation');
        $record->feedback4 = self::option($q, 3, 'explanation');

        // FIX-KC-SURVEY-SCALE-OPTIONS (v1.5.141): in survey mode the response scale is a fixed
        // list chosen by the teacher — not something the generation model should decide. The
        // plugin used to store whatever options the service returned, so picking "Yes / No" (or
        // any scale other than 5-point Agreement) frequently produced Agreement options anyway,
        // with nothing to show the teacher's choice had been ignored. Overwrite the options
        // with the canonical set for the activity's scale; the AI still writes the question
        // text. Free-text questions have no options and are left alone.
        //
        // FIX-KC-SURVEY-SCALE-DEAD (v1.5.152): in ajax.php this block never ran at all.
        // $knowledgecheck was initialised to null and only loaded for the actions listed in
        // $secured_actions, which did not include 'savequestions' — so the guard below tested
        // a property of null, was always false, and the v1.5.141 enforcement was dead code
        // from the day it was written. Surveys have been getting the right options anyway,
        // because v1.5.140 fixed the client to send the teacher's scale key and the service
        // generates from it; but nothing on the server was enforcing it. This class loads the
        // activity record, so the enforcement is now live. Note what that means in practice:
        // the next save of an existing survey activity rewrites its stored options to the
        // canonical set for its scale.
        //
        // The block also runs AFTER the feedback and correctanswer assignments above, where
        // ajax.php had it before them — so its `feedbackN = ''` and `correctanswer = 0` would
        // have been overwritten by those generic assignments even had it been reachable.
        if (!empty($knowledgecheck->surveymode) && $record->questiontype !== 'freetext') {
            $scalekey = (isset($knowledgecheck->surveyscale) && $knowledgecheck->surveyscale !== '')
                ? $knowledgecheck->surveyscale : 'likert5agree';
            $scaleopts = mod_aiknowledgecheck_survey_scale_options($scalekey);
            for ($si = 0; $si < 5; $si++) {
                $field = 'answer' . ($si + 1);
                $record->$field = $scaleopts[$si] ?? ($si < 4 ? '' : null);
            }
            // Survey questions are ungraded; per-option feedback is meaningless.
            $record->feedback1 = '';
            $record->feedback2 = '';
            $record->feedback3 = '';
            $record->feedback4 = '';
            $record->correctanswer = 0;
        }

        // Audio data: a JSON list of base64 clips, one per answer option.
        if (!empty($q['audioData'])) {
            $record->audiodata = json_encode($q['audioData']);
        }

        // Topic/criteria mapping metadata for the Excel export.
        $record->mappingtopic = isset($q['mappingTopic'])
            ? clean_param(self::str($q['mappingTopic']), PARAM_TEXT) : null;
        $record->mappingcriteria = isset($q['mappingCriteria'])
            ? clean_param(self::str($q['mappingCriteria']), PARAM_TEXT) : null;
        $record->timestamp_seconds = (isset($q['timestamp_seconds']) && $q['timestamp_seconds'] !== null)
            ? (int)$q['timestamp_seconds'] : null;

        // ADD-KC-IMAGEGATE (v1.5.115): per-question image; sanitised to reject
        // data:image/svg+xml and non-http(s) schemes.
        $record->imageurl = isset($q['imageUrl'])
            ? mod_aiknowledgecheck_sanitize_image_url(self::str($q['imageUrl'])) : null;
        $record->imageenabled = isset($q['imageEnabled']) ? (int)$q['imageEnabled'] : 0;

        // ADD-KC-MEDIAPER-Q (v1.5.120): per-question video and audio.
        $record->questionvideourl = isset($q['questionVideoUrl'])
            ? clean_param(self::str($q['questionVideoUrl']), PARAM_URL) : null;
        $record->questionvideoenabled = isset($q['questionVideoEnabled'])
            ? (int)$q['questionVideoEnabled'] : 0;
        $record->questionaudiourl = isset($q['questionAudioUrl'])
            ? clean_param(self::str($q['questionAudioUrl']), PARAM_URL) : null;
        $record->questionaudioenabled = isset($q['questionAudioEnabled'])
            ? (int)$q['questionAudioEnabled'] : 0;

        return $record;
    }

    /**
     * Coerce a supplied value to a string, rejecting arrays and objects.
     *
     * @param mixed $value The value from the payload.
     * @return string
     */
    private static function str($value): string {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * Read one answer option, accepting either a bare string or a {text, explanation} object
     * — the shape the generation service returns.
     *
     * @param array $q The question document.
     * @param int $index Option index.
     * @param string $key Either 'text' or 'explanation'.
     * @return string
     */
    private static function option(array $q, int $index, string $key): string {
        if (!isset($q['options'][$index])) {
            return '';
        }
        $opt = $q['options'][$index];
        if (is_array($opt)) {
            return self::str($opt[$key] ?? '');
        }
        // A bare string is the option text and carries no explanation.
        return $key === 'text' ? self::str($opt) : '';
    }

    /**
     * Persist the activity's voice settings alongside the saved questions.
     *
     * @param \stdClass $cm Course module record.
     * @param array $questionsdata The saved question documents.
     * @param array $params The validated call parameters.
     * @return void
     */
    private static function persist_voice_settings(\stdClass $cm, array $questionsdata, array $params): void {
        global $DB;

        // Use the explicit flag when one was sent; otherwise infer it from the audio data, so
        // the student view gets the right config either way.
        if ((int)$params['voiceoverEnabled'] >= 0) {
            $DB->set_field('aiknowledgecheck', 'voiceoverenabled', $params['voiceoverEnabled'] ? 1 : 0, ['id' => $cm->instance]);
        } else {
            foreach ($questionsdata as $q) {
                if (is_array($q) && !empty($q['audioData'])) {
                    $DB->set_field('aiknowledgecheck', 'voiceoverenabled', 1, ['id' => $cm->instance]);
                    break;
                }
            }
        }

        $voicefields = [
            'voiceLanguage' => 'voicelanguage',
            'voiceGender' => 'voicegender',
            'voiceStyle' => 'voicestyle',
        ];
        foreach ($voicefields as $param => $field) {
            if ($params[$param] !== '') {
                $DB->set_field('aiknowledgecheck', $field, $params[$param], ['id' => $cm->instance]);
            }
        }
    }

    /**
     * Describes the value returned by execute().
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(
            [
                'ok' => new external_value(PARAM_BOOL, 'True when the questions were saved'),
                'error' => new external_value(PARAM_TEXT, 'Error message, empty on success'),
                'saved' => new external_value(PARAM_INT, 'Number of questions written'),
            ]);
    }
}
