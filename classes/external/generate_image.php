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
 * External service: generate a question image.
 *
 * MIGRATE-EXTERNAL-SERVICES (v1.5.152): thirteenth endpoint migrated from the legacy
 * ajax.php action dispatcher to a declared External Service.
 *
 * The reviewer's exclusion for file upload endpoints does not apply here: nothing is
 * uploaded. The client sends a text prompt and receives a data URL back, which is an
 * ordinary JSON round trip and migrates like any other action.
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
 * Generates a question image through the external service. Costs 5 credits per image.
 */
class generate_image extends external_api {
    /**
     * Describes the parameters accepted by execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'cmid' => new external_value(PARAM_INT, 'Course module ID of the activity'),
                'prompt' => new external_value(PARAM_TEXT, 'Image prompt'),
            ]
        );
    }

    /**
     * Generate one image.
     *
     * @param int $cmid Course module ID.
     * @param string $prompt Image prompt.
     * @return array Result array matching execute_returns().
     */
    public static function execute(int $cmid, string $prompt): array {

        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'cmid' => $cmid,
                'prompt' => $prompt,
            ]
        );

        $cm = get_coursemodule_from_id('aiknowledgecheck', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        // Image generation spends the site's credits, so it is teacher-only.
        require_capability('mod/aiknowledgecheck:create', $context);

        $prompt = trim($params['prompt']);
        if ($prompt === '') {
            return self::result(false, get_string('error:emptyprompt', 'mod_aiknowledgecheck'));
        }

        [$apibase, $siteid, $apikey] = saas_client::credentials();
        if ($siteid === '' || $apikey === '') {
            return self::result(false, get_string('error:notconfigured', 'mod_aiknowledgecheck'));
        }

        [$raw, $httpcode, $connectionerror] = saas_client::post_json(
            $apibase . '/api/knowledgecheck-generate-image',
            [
                'siteId' => $siteid,
                'apiKey' => $apikey,
                'prompt' => $prompt,
                'activityId' => (string)$cm->instance,
            ],
            90,
            130
        );

        if ($connectionerror !== '') {
            return self::result(false, get_string('error:connectionfailed', 'mod_aiknowledgecheck'));
        }

        $result = $raw !== null ? json_decode($raw, true) : null;
        if ($httpcode === 200 && is_array($result) && !empty($result['ok']) && !empty($result['imageDataUrl'])) {
            // Sanitise before handing the URL back: the same check the save path applies, so a
            // response carrying data:image/svg+xml (which can execute script) is rejected here
            // rather than being rendered in the editor preview first.
            $imagedataurl = mod_aiknowledgecheck_sanitize_image_url((string)$result['imageDataUrl']);
            if ($imagedataurl === null) {
                return self::result(false, get_string('error:invalidimageurl', 'mod_aiknowledgecheck'));
            }
            return self::result(true, '', $imagedataurl);
        }

        $message = (is_array($result) && isset($result['error']) && is_scalar($result['error']))
            ? (string)$result['error']
            : get_string('error:apihttp', 'mod_aiknowledgecheck', $httpcode);

        return self::result(false, $message);
    }

    /**
     * Build a return payload with every declared key present.
     *
     * @param bool $ok Whether an image was generated.
     * @param string $error Error message, empty on success.
     * @param string $imagedataurl The generated image data URL.
     * @return array
     */
    private static function result(bool $ok, string $error = '', string $imagedataurl = ''): array {
        // See saas_client::failure() — 'error' is PARAM_TEXT and clean_returnvalue()
        // validates rather than cleans, so an upstream message is cleaned here.
        return ['ok' => $ok, 'error' => clean_param($error, PARAM_TEXT), 'imageDataUrl' => $imagedataurl];
    }

    /**
     * Describes the value returned by execute().
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(
            [
                'ok' => new external_value(PARAM_BOOL, 'True when an image was generated'),
                'error' => new external_value(PARAM_TEXT, 'Error message, empty on success'),
                'imageDataUrl' => new external_value(
                    /* phpcs:ignore moodle.Commenting.InlineComment */
                    PARAM_RAW, // pipeline-ignore: PARAM_RAW — data:image URL; PARAM_URL rejects it. Sanitised on write
                    'The generated image as a data URL, empty on failure'
                ),
            ]
        );
    }
}
