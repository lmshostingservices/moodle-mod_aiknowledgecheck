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
 * Shared client for the plugin's outbound generation API calls.
 *
 * MIGRATE-EXTERNAL-SERVICES (v1.5.152): the credential lookup and the POST/response
 * handling were repeated verbatim in every ajax.php action that talked to the generation
 * service. Each migrated External Service would have copied them again, so they live here
 * once. The behaviour is a straight lift of the ajax.php code, including the two details
 * that were each found the hard way on a live site and are called out below.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_aiknowledgecheck;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves credentials for, and posts JSON to, the generation service.
 */
class saas_client {
    /**
     * Resolve the API base URL and credentials.
     *
     * Credentials resolve exactly as they do in ajax.php: the optional local_aiconfig
     * "Central Config" plugin takes priority, so a site running several AI plugins can hold
     * one set of credentials centrally, and this plugin's own settings are the fallback.
     * Verified on a live site: reading only this plugin's settings reports "not configured"
     * on any site that keeps its credentials centrally.
     *
     * @return array [apibase, siteid, apikey]
     */
    public static function credentials(): array {
        global $CFG;

        $aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
        if (file_exists($aiconfiglib)) {
            require_once($aiconfiglib);
        }

        $siteid = '';
        $apikey = '';
        if (function_exists('local_aiconfig_get_siteid')) {
            $siteid = trim(local_aiconfig_get_siteid() ?? '');
        }
        if (function_exists('local_aiconfig_get_apikey')) {
            $apikey = trim(local_aiconfig_get_apikey() ?? '');
        }
        if ($siteid === '') {
            $siteid = trim((string)get_config('mod_aiknowledgecheck', 'siteid'));
        }
        if ($apikey === '') {
            $apikey = trim((string)get_config('mod_aiknowledgecheck', 'apikey'));
        }

        $apibase = trim((string)get_config('mod_aiknowledgecheck', 'apiurl'));
        if ($apibase === '') {
            $apibase = 'https://lms-labs.com';
        }
        $apibase = rtrim($apibase, '/');

        return [$apibase, $siteid, $apikey];
    }

    /**
     * POST a JSON payload to the generation service and return the raw response body.
     *
     * @param string $url Absolute endpoint URL.
     * @param array $payload Payload to JSON-encode and send.
     * @param int $timeout Curl timeout in seconds.
     * @param int|null $timelimit set_time_limit() value; pass null to leave it alone. Must be
     *                            comfortably above $timeout so the web server does not kill
     *                            PHP mid-request and return a blank body.
     * @return array [raw response body or null, http status code, connection error message]
     */
    public static function post_json(string $url, array $payload, int $timeout = 60, ?int $timelimit = null): array {
        if ($timelimit !== null) {
            // BUG-REGEN-TIMEOUT (v1.5.84): many servers enforce max_execution_time of 30-60s,
            // which killed PHP mid-curl and produced no JSON at all — the browser saw a blank
            // response rather than an error it could show.
            \core_php_time_limit::raise($timelimit);
        }

        // Release the session lock before the outbound call so other requests from the same
        // user are not blocked behind it.
        \core\session\manager::write_close();

        $curl = new \curl();
        // BUG-CURL-RESETOPT (v1.5.85): Moodle's curl::post() calls resetopt() internally before
        // applying the post-specific options, so anything set with setopt() beforehand is
        // silently discarded — including the Content-Type header, without which the service
        // could not parse the body and rejected every request. Options must be passed as the
        // third argument to post() so they are applied after that reset.
        $raw = $curl->post(
            $url, json_encode($payload), [
                'CURLOPT_TIMEOUT'        => $timeout,
                'CURLOPT_CONNECTTIMEOUT' => 10,
                'CURLOPT_SSL_VERIFYPEER' => true,
                'CURLOPT_HTTPHEADER'     => ['Content-Type: application/json'],
            ]);

        $info = $curl->get_info();
        $httpcode = isset($info['http_code']) ? (int)$info['http_code'] : 0;

        if ($curl->error) {
            return [null, $httpcode, (string)$curl->error];
        }

        return [$raw, $httpcode, ''];
    }

    /**
     * Turn a raw service response into the {ok, error, resultjson} envelope the migrated
     * External Services return.
     *
     * FIX-KC-REGEN-STREAM (v1.5.90): the body is passed through as a string and never
     * decoded and re-encoded. Re-encoding a large base64 audioData array can silently make
     * json_encode() return false, which reached the browser as a parse error rather than a
     * response — the same root cause already fixed once for the 'status' action.
     *
     * @param string|null $raw Raw response body, or null when the connection failed.
     * @param int $httpcode HTTP status code.
     * @param string $connectionerror Connection error message, empty when there was none.
     * @return array {ok, error, resultjson}
     */
    public static function envelope(?string $raw, int $httpcode, string $connectionerror): array {
        if ($connectionerror !== '') {
            // The upstream error text can name internal hosts, so it is not returned verbatim.
            return self::failure(get_string('error:connectionfailed', 'mod_aiknowledgecheck'));
        }

        if ($httpcode === 200) {
            if ($raw === null || $raw === '') {
                return self::failure(get_string('error:invalidresponse', 'mod_aiknowledgecheck'));
            }
            // Validate that the body parses without keeping the decoded copy — an unparseable
            // body would otherwise reach the client and fail there instead, with no
            // server-side trace.
            if (json_decode($raw, true) === null && trim($raw) !== 'null') {
                return self::failure(get_string('error:invalidresponse', 'mod_aiknowledgecheck'));
            }
            return ['ok' => true, 'error' => '', 'resultjson' => $raw];
        }

        $decoded = $raw !== null ? json_decode($raw, true) : null;
        $message = (is_array($decoded) && isset($decoded['error']) && is_scalar($decoded['error']))
            ? (string)$decoded['error']
            : get_string('error:apihttp', 'mod_aiknowledgecheck', $httpcode);

        return self::failure($message);
    }

    /**
     * Build a failed {ok, error, resultjson} envelope.
     *
     * @param string $message User-facing error message.
     * @return array
     */
    public static function failure(string $message): array {
        // FIX-KC-RETURNTYPE-CLEAN (v1.5.152): 'error' is declared PARAM_TEXT, and
        // clean_returnvalue() validates rather than cleans — it throws on any value
        // clean_param would alter. Upstream messages are not ours, so they are cleaned here
        // and the declared type becomes idempotent.
        return ['ok' => false, 'error' => clean_param($message, PARAM_TEXT), 'resultjson' => ''];
    }
}
