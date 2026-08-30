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
 * External service: regenerate questions after a voice/settings change.
 *
 * MIGRATE-EXTERNAL-SERVICES (v1.5.152): eleventh endpoint migrated from the legacy ajax.php
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
 * Reruns generation with new voice settings.
 */
class regenerate_with_settings extends external_api {
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
                'voiceoverEnabled' => new external_value(PARAM_INT, '1 to generate voiceover audio', VALUE_DEFAULT, 0),
                'voiceGender' => new external_value(PARAM_ALPHA, 'Voice gender', VALUE_DEFAULT, 'female'),
                'voiceId' => new external_value(PARAM_ALPHA, 'Voice identifier', VALUE_DEFAULT, 'Zephyr'),
            ]);
    }

    /**
     * Regenerate with the supplied settings.
     *
     * @param int $cmid Course module ID.
     * @param string $questions JSON array of question documents.
     * @param string $voicelanguage Voice language code.
     * @param int $voiceoverenabled Whether to generate voiceover audio.
     * @param string $voicegender Voice gender.
     * @param string $voiceid Voice identifier.
     * @return array Result array matching execute_returns().
     */
    public static function execute(
        int $cmid, string $questions, string $voicelanguage = 'en-AU',
            int $voiceoverenabled = 0, string $voicegender = 'female',
            string $voiceid = 'Zephyr'): array {

        $params = self::validate_parameters(
            self::execute_parameters(), [
                'cmid' => $cmid,
                'questions' => $questions,
                'voiceLanguage' => $voicelanguage,
                'voiceoverEnabled' => $voiceoverenabled,
                'voiceGender' => $voicegender,
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

        // BUG-REGEN-TIMEOUT (v1.5.84): a single server-side attempt, with a PHP time limit
        // above the curl timeout. The old code retried three times for up to 460 seconds, so
        // the browser always gave up first, and many servers killed PHP mid-curl and returned
        // nothing at all. The client already retries; the server must not.
        //
        // MIGRATE-EXTERNAL-SERVICES (v1.5.152): the curl timeout was 75s under ajax.php,
        // chosen to sit below the caller's own 90s jQuery AJAX timeout. core/ajax sets no
        // client-side timeout, so that ceiling is gone and the value now matches the batch
        // regeneration path at 160s — this endpoint runs the same model plus optional TTS,
        // and 75s was cutting off legitimate work.
        [$raw, $httpcode, $connectionerror] = saas_client::post_json(
            $apibase . '/api/knowledgecheck-regenerate-settings',
            [
                'siteId' => $siteid,
                'apiKey' => $apikey,
                'questions' => $questionsdata,
                'voiceLanguage' => $params['voiceLanguage'],
                'voiceoverEnabled' => (bool)$params['voiceoverEnabled'],
                'voiceGender' => $params['voiceGender'],
                'voiceId' => $params['voiceId'],
            ],
            160,
            200
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
                'ok' => new external_value(PARAM_BOOL, 'True when the questions were regenerated'),
                'error' => new external_value(PARAM_TEXT, 'Error message, empty on success'),
                'resultjson' => new external_value(
                    PARAM_RAW, // pipeline-ignore: PARAM_RAW — JSON blob, JSON.parse()'d by the client
                    'The generation service response verbatim, as a JSON string'
                ),
            ]);
    }
}
