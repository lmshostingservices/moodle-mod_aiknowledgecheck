<?php
// phpcs:disable moodle.Files.LineLength
// phpcs:disable moodle.Commenting.InlineComment
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
 * External service: store the activity's image gate URL.
 *
 * MIGRATE-EXTERNAL-SERVICES (v1.5.152): fourteenth and final endpoint migrated from the
 * legacy ajax.php action dispatcher to a declared External Service.
 *
 * The reviewer's exclusion for file upload endpoints does not apply here: nothing is
 * uploaded. The client sends an http(s) or data URL as a JSON string; there is no
 * multipart request and no draft file area involved.
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

/**
 * Saves an image URL against the activity.
 */
class save_image_url extends external_api {
    /**
     * Describes the parameters accepted by execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'cmid' => new external_value(PARAM_INT, 'Course module ID of the activity'),
                // Raw because a data:image URL is not a PARAM_URL; it is validated by
                // mod_aiknowledgecheck_sanitize_image_url() below, which accepts only http(s)
                // URLs and safe raster data URLs.
                'imageurl' => new external_value(
                    PARAM_RAW, // pipeline-ignore: PARAM_RAW — data:image URL; PARAM_URL rejects it. Sanitised on write
                    'An http(s) URL or a data:image URL'
                ),
            ]
        );
    }

    /**
     * Save the image URL.
     *
     * @param int $cmid Course module ID.
     * @param string $imageurl The URL to store.
     * @return array Result array matching execute_returns().
     */
    public static function execute(int $cmid, string $imageurl): array {
        global $DB;

        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'cmid' => $cmid,
                'imageurl' => $imageurl,
            ]
        );

        $cm = get_coursemodule_from_id('aiknowledgecheck', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/aiknowledgecheck:create', $context);

        // Validate and sanitise: http(s) URLs and safe raster data URLs only.
        // data:image/svg+xml is rejected because SVG can carry script.
        $clean = mod_aiknowledgecheck_sanitize_image_url($params['imageurl']);
        if ($clean === null) {
            return ['ok' => false, 'error' => get_string('error:invalidimageurl', 'mod_aiknowledgecheck')];
        }

        $DB->set_field('aiknowledgecheck', 'imageurl', $clean, ['id' => $cm->instance]);
        $DB->set_field('aiknowledgecheck', 'timemodified', time(), ['id' => $cm->instance]);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Describes the value returned by execute().
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(
            [
                'ok' => new external_value(PARAM_BOOL, 'True when the URL was stored'),
                'error' => new external_value(PARAM_TEXT, 'Error message, empty on success'),
            ]
        );
    }
}
