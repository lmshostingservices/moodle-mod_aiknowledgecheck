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
 * External service: regenerate voiceover audio for existing questions.
 *
 * MIGRATE-EXTERNAL-SERVICES (v1.5.152): tenth endpoint migrated from the legacy ajax.php
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
use mod_aiknowledgecheck\saas_client;

/**
 * Asks the generation service for fresh voiceover audio.
 */
class regenerate_audio extends external_api {
    /**
     * Describes the parameters accepted by execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'cmid' => new external_value(PARAM_INT, 'Course module ID of the activity'),
                // See save_questions for why the question documents cross as a JSON string.
                'questions' => new external_value(
                    PARAM_RAW, // pipeline-ignore: PARAM_RAW — JSON payload, json_decode()'d below
                    'JSON array of question documents'
                ),
                'voiceLanguage' => new external_value(PARAM_TEXT, 'Voice language code', VALUE_DEFAULT, 'en-AU'),
                'voiceId' => new external_value(PARAM_ALPHA, 'Voice identifier', VALUE_DEFAULT, 'Zephyr'),
            ]);
    }

    /**
     * Regenerate the audio.
     *
     * @param int $cmid Course module ID.
     * @param string $questions JSON array of question documents.
     * @param string $voicelanguage Voice language code.
     * @param string $voiceid Voice identifier.
     * @return array Result array matching execute_returns().
     */
    public static function execute(
        int $cmid, string $questions, string $voicelanguage = 'en-AU',
            string $voiceid = 'Zephyr'): array {

        $params = self::validate_parameters(
            self::execute_parameters(), [
                'cmid' => $cmid,
                'questions' => $questions,
                'voiceLanguage' => $voicelanguage,
                'voiceId' => $voiceid,
            ]);

        $cm = get_coursemodule_from_id('aiknowledgecheck', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/aiknowledgecheck:create', $context);

        $questionsdata = json_decode($params['questions'], true);
        if (!is_array($questionsdata) || empty($questionsdata)) {
            return saas_client::failure(get_string('error:invalidquestions', 'mod_aiknowledgecheck'));
        }

        [$apibase, $siteid, $apikey] = saas_client::credentials();
        if ($siteid === '' || $apikey === '') {
            return saas_client::failure(get_string('error:notconfigured', 'mod_aiknowledgecheck'));
        }

        [$raw, $httpcode, $connectionerror] = saas_client::post_json(
            $apibase . '/api/knowledgecheck-regenerate-audio',
            [
                'siteId' => $siteid,
                'apiKey' => $apikey,
                'questions' => $questionsdata,
                'voiceLanguage' => $params['voiceLanguage'],
                'voiceId' => $params['voiceId'],
            ],
            120,
            170
        );

        return saas_client::envelope($raw, $httpcode, $connectionerror);
    }

    /**
     * Describes the value returned by execute().
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(
            [
                'ok' => new external_value(PARAM_BOOL, 'True when audio was regenerated'),
                'error' => new external_value(PARAM_TEXT, 'Error message, empty on success'),
                'resultjson' => new external_value(
                    PARAM_RAW, // pipeline-ignore: PARAM_RAW — JSON blob, JSON.parse()'d by the client
                    'The generation service response verbatim, as a JSON string'
                ),
            ]);
    }
}
