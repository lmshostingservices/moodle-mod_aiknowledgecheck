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
 * External service: poll the status of a question generation job.
 *
 * MIGRATE-EXTERNAL-SERVICES (v1.5.148): third endpoint migrated from the legacy
 * ajax.php action dispatcher to a declared External Service.
 *
 * DESIGN NOTE — why the payload is returned as an opaque JSON string.
 *
 * A completed generation job returns a variable-shaped document produced by the
 * external service: a question list whose fields differ by question type, plus
 * optional base64 audio arrays that can run to megabytes. Two problems rule out a
 * typed external_single_structure here:
 *
 *  1. The legacy endpoint deliberately streamed the upstream body through untouched
 *     (see FIX-KC-STATUS-STREAM in ajax.php). Decoding and re-encoding a large
 *     completed payload could make json_encode() fail silently, which surfaced to
 *     students as "0 questions generated". Declaring a typed structure would force
 *     exactly that decode/re-encode round trip back into the request path.
 *  2. The upstream shape is not owned by this plugin and may gain fields at any time.
 *     A typed structure would silently drop unknown keys, so an upstream addition
 *     would break generation with no error.
 *
 * The payload is therefore passed through as a single raw JSON string and parsed by
 * the caller, as the legacy endpoint did. The security benefits the migration is for
 * are unaffected: the request arguments are validated against the declared signature
 * before any plugin code runs, the session is handled by Moodle, and the capability
 * check below is enforced inside execute().
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_aiknowledgecheck\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use context_module;

/**
 * Returns the current status of an asynchronous question generation job.
 */
class get_generation_status extends external_api {
    /**
     * Describes the parameters accepted by execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'cmid' => new external_value(PARAM_INT, 'Course module ID of the activity'),
                'jobid' => new external_value(PARAM_ALPHANUMEXT, 'Generation job identifier'),
            ]
        );
    }

    /**
     * Poll the external service for the status of a generation job.
     *
     * @param int $cmid Course module ID.
     * @param string $jobid Generation job identifier.
     * @return array Result array matching execute_returns().
     */
    public static function execute(int $cmid, string $jobid): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'cmid' => $cmid,
                'jobid' => $jobid,
            ]
        );

        $cm = get_coursemodule_from_id('aiknowledgecheck', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);

        // SECURITY (M-1): a completed job payload contains the answer key, so this must
        // stay gated on the authoring capability rather than :view.
        require_capability('mod/aiknowledgecheck:create', $context);

        $apibase = trim((string)get_config('mod_aiknowledgecheck', 'apiurl'));
        if ($apibase === '') {
            $apibase = 'https://lms-labs.com';
        }
        // FIX-KC-APIBASE-SLASH (v1.5.152): ajax.php rtrim'd the configured base for every
        // action. These two classes did not, so an apiurl saved with a trailing slash built
        // a double-slashed request path.
        $apibase = rtrim($apibase, '/');

        // Polling call — release the session lock so it does not block other requests.
        \core\session\manager::write_close();

        $url = $apibase . '/api/knowledgecheck-status/' . urlencode($params['jobid']);

        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 30, 'CURLOPT_SSL_VERIFYPEER' => true]);
        $response = $curl->get($url);
        $httpcode = isset($curl->info['http_code']) ? (int)$curl->info['http_code'] : 0;

        if ($curl->error || $httpcode !== 200) {
            return [
                'ok' => false,
                'payload' => '',
                'error' => get_string('error:statuscheckfailed', 'mod_aiknowledgecheck'),
            ];
        }

        // Passed through verbatim — see the design note at the top of this file.
        return [
            'ok' => true,
            'payload' => (string)$response,
            'error' => '',
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
                'ok' => new external_value(PARAM_BOOL, 'True if the status was retrieved'),
                'payload' => new external_value(
                    /* phpcs:ignore moodle.Commenting.InlineComment */
                    PARAM_RAW, // pipeline-ignore: PARAM_RAW — JSON blob, JSON.parse()'d by the client
                    'Raw JSON status document from the generation service, empty string on failure'
                ),
                'error' => new external_value(PARAM_TEXT, 'Error message, empty string on success'),
            ]
        );
    }
}
