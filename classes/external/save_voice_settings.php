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
 * External service: save the voiceover settings for an activity.
 *
 * MIGRATE-EXTERNAL-SERVICES (v1.5.147): second endpoint migrated from the legacy
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

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use context_module;

/**
 * Saves the voiceover configuration for an AI Knowledge Check activity.
 */
class save_voice_settings extends external_api {
    /**
     * Describes the parameters accepted by execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'cmid' => new external_value(PARAM_INT, 'Course module ID of the activity'),
                'voiceoverenabled' => new external_value(PARAM_BOOL, 'Whether spoken explanations are enabled'),
                'voicelanguage' => new external_value(PARAM_TEXT, 'Voice language code', VALUE_DEFAULT, 'en-AU'),
                'voicegender' => new external_value(PARAM_ALPHA, 'Voice gender', VALUE_DEFAULT, 'female'),
                'voicestyle' => new external_value(PARAM_ALPHANUMEXT, 'Voice style name', VALUE_DEFAULT, 'Aoede'),
            ]);
    }

    /**
     * Persist the voiceover settings.
     *
     * @param int $cmid Course module ID.
     * @param bool $voiceoverenabled Whether voiceover is enabled.
     * @param string $voicelanguage Language code.
     * @param string $voicegender Voice gender.
     * @param string $voicestyle Voice style name.
     * @return array Result array matching execute_returns().
     */
    public static function execute(
        int $cmid,
        bool $voiceoverenabled,
        string $voicelanguage = 'en-AU',
        string $voicegender = 'female',
        string $voicestyle = 'Aoede'
    ): array {
        global $DB;

        $params = self::validate_parameters(
            self::execute_parameters(), [
                'cmid' => $cmid,
                'voiceoverenabled' => $voiceoverenabled,
                'voicelanguage' => $voicelanguage,
                'voicegender' => $voicegender,
                'voicestyle' => $voicestyle,
            ]);

        $cm = get_coursemodule_from_id('aiknowledgecheck', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);

        // Editing activity settings is an authoring action, matching the legacy endpoint.
        require_capability('mod/aiknowledgecheck:create', $context);

        $enabled = $params['voiceoverenabled'] ? 1 : 0;

        $record = (object)[
            'id' => $cm->instance,
            'voiceoverenabled' => $enabled,
            'voicelanguage' => $params['voicelanguage'],
            'voicegender' => $params['voicegender'],
            'voicestyle' => $params['voicestyle'],
            'timemodified' => time(),
        ];
        $DB->update_record('aiknowledgecheck', $record);

        // Turning voiceover off discards the generated audio, which is large and would
        // otherwise be served to students who can no longer hear it. A single set_field
        // across the activity replaces the previous row-by-row loop.
        $audiocleared = 0;
        if (!$enabled) {
            $audiocleared = $DB->count_records_select(
                'aiknowledgecheck_questions',
                'aiknowledgecheckid = :kcid AND audiodata IS NOT NULL',
                ['kcid' => $cm->instance]
            );
            if ($audiocleared > 0) {
                $DB->set_field_select(
                    'aiknowledgecheck_questions',
                    'audiodata',
                    null,
                    'aiknowledgecheckid = :kcid AND audiodata IS NOT NULL',
                    ['kcid' => $cm->instance]
                );
            }
        }

        return [
            'ok' => true,
            'audiocleared' => $audiocleared,
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
                'ok' => new external_value(PARAM_BOOL, 'True if the settings were saved'),
                'audiocleared' => new external_value(
                    PARAM_INT,
                    'Number of questions whose stored audio was discarded, 0 unless voiceover was turned off'
                ),
            ]);
    }
}
