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
 * External service: fetch the remaining generation credits for this site.
 *
 * MIGRATE-EXTERNAL-SERVICES (v1.5.144): first endpoint migrated from the legacy
 * ajax.php action dispatcher to a declared External Service, per Moodle plugins
 * directory review feedback. The equivalent 'getcredits' action remains in ajax.php
 * for now so the two can coexist while the remaining endpoints are migrated one at
 * a time; it will be removed once every caller has been moved across.
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
 * Returns the remaining AI generation credits for this site.
 */
class get_credits extends external_api {
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
     * Fetch the site's remaining generation credits from the external service.
     *
     * @param int $cmid Course module ID.
     * @return array Result array matching execute_returns().
     */
    public static function execute(int $cmid): array {
        global $DB;

        // Moodle validates the raw parameters against execute_parameters() before we
        // ever see them; this normalises them into the declared shape.
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        $cm = get_coursemodule_from_id('aiknowledgecheck', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        // Note: validate_context() performs the require_login() equivalent for web services.
        self::validate_context($context);

        // Credit balances are organisation billing data and are not student-facing. This
        // matches the capability the legacy ajax.php action required.
        require_capability('mod/aiknowledgecheck:create', $context);

        // Credentials resolve exactly as they do in ajax.php: the optional local_aiconfig
        // "Central Config" plugin takes priority, so a site running several AI plugins can
        // hold one set of credentials centrally, and this plugin's own settings are the
        // fallback. Verified on a live site: reading only this plugin's settings reports
        // "not configured" on any site that keeps its credentials centrally.
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
        // FIX-KC-APIBASE-SLASH (v1.5.152): ajax.php rtrim'd the configured base for every
        // action. These two classes did not, so an apiurl saved with a trailing slash built
        // a double-slashed request path.
        $apibase = rtrim($apibase, '/');

        if ($siteid === '' || $apikey === '') {
            return [
                'ok' => false,
                'credits' => 0,
                'error' => get_string('error:notconfigured', 'mod_aiknowledgecheck'),
            ];
        }

        // Long-running outbound call — release the session lock so other requests are
        // not blocked behind it, exactly as ajax.php does.
        \core\session\manager::write_close();

        // The '&' is passed explicitly because some PHP configurations default the separator.
        // to '&amp;', which produces a malformed query string.
        $url = $apibase . '/api/credits?' . http_build_query(
            [
                'siteId' => $siteid,
                'apiKey' => $apikey,
            ],
            '',
            '&'
        );

        $curl = new \curl();
        $curl->setopt(
            [
                'CURLOPT_TIMEOUT' => 30,
                'CURLOPT_SSL_VERIFYPEER' => true,
                'CURLOPT_FOLLOWLOCATION' => true,
            ]
        );
        $response = $curl->get($url);
        $httpcode = isset($curl->info['http_code']) ? (int)$curl->info['http_code'] : 0;

        if ($curl->error) {
            // The upstream error text may reference internal hosts, so it is not returned
            // to the browser verbatim.
            return [
                'ok' => false,
                'credits' => 0,
                'error' => get_string('error:connectionfailed', 'mod_aiknowledgecheck'),
            ];
        }

        if ($httpcode === 200) {
            $result = json_decode($response, true);
            if (is_array($result) && isset($result['credits'])) {
                return [
                    'ok' => true,
                    'credits' => (int)$result['credits'],
                    'error' => '',
                ];
            }
            return [
                'ok' => false,
                'credits' => 0,
                'error' => get_string('error:invalidresponse', 'mod_aiknowledgecheck'),
            ];
        }

        // Never echo the site ID, API base or raw upstream body back to the browser —
        // those are credentials and configuration.
        $result = json_decode($response, true);
        $message = (is_array($result) && isset($result['error']) && is_scalar($result['error']))
            ? (string)$result['error']
            : get_string('error:apihttp', 'mod_aiknowledgecheck', $httpcode);

        return [
            'ok' => false,
            'credits' => 0,
            'error' => $message,
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
                'ok' => new external_value(PARAM_BOOL, 'True if the credit balance was retrieved'),
                'credits' => new external_value(PARAM_INT, 'Remaining generation credits, 0 on failure'),
                'error' => new external_value(PARAM_TEXT, 'Error message, empty string on success'),
            ]
        );
    }
}
