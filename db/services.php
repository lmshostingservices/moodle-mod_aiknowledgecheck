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
 * External service function declarations.
 *
 * MIGRATE-EXTERNAL-SERVICES (v1.5.144-152): the plugin has been migrated from the legacy
 * ajax.php action dispatcher to declared External Services, per Moodle plugins directory
 * review feedback. Endpoints were moved one at a time, each legacy action removed only
 * once every caller had been switched across. The migration is complete as of v1.5.152 and
 * ajax.php has been deleted; two actions ('getindustries', 'getattemptinfo') were dead code
 * with no caller anywhere in the plugin and were removed rather than migrated.
 *
 * Functions are registered when the plugin version number increases, so any addition
 * here must be accompanied by a version bump in version.php.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [

    'mod_aiknowledgecheck_get_credits' => [
        'classname'   => 'mod_aiknowledgecheck\external\get_credits',
        'methodname'  => 'execute',
        'description' => 'Get the remaining AI generation credits for this site.',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => 'mod/aiknowledgecheck:create',
    ],

    'mod_aiknowledgecheck_save_voice_settings' => [
        'classname'   => 'mod_aiknowledgecheck\\external\\save_voice_settings',
        'methodname'  => 'execute',
        'description' => 'Save the voiceover settings for an AI Knowledge Check activity.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/aiknowledgecheck:create',
    ],

    'mod_aiknowledgecheck_get_generation_status' => [
        'classname'   => 'mod_aiknowledgecheck\\external\\get_generation_status',
        'methodname'  => 'execute',
        'description' => 'Poll the status of an AI question generation job.',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => 'mod/aiknowledgecheck:create',
    ],

    'mod_aiknowledgecheck_get_questions' => [
        'classname'   => 'mod_aiknowledgecheck\\external\\get_questions',
        'methodname'  => 'execute',
        'description' => 'Get the questions for an AI Knowledge Check activity.',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => 'mod/aiknowledgecheck:view',
    ],

    'mod_aiknowledgecheck_start_attempt' => [
        'classname'   => 'mod_aiknowledgecheck\\external\\start_attempt',
        'methodname'  => 'execute',
        'description' => 'Start a new attempt, or resume the caller\'s in-progress attempt.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/aiknowledgecheck:view',
    ],

    'mod_aiknowledgecheck_save_answer' => [
        'classname'   => 'mod_aiknowledgecheck\\external\\save_answer',
        'methodname'  => 'execute',
        'description' => 'Record a single answer during an attempt.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/aiknowledgecheck:view',
    ],

    'mod_aiknowledgecheck_finish_attempt' => [
        'classname'   => 'mod_aiknowledgecheck\\external\\finish_attempt',
        'methodname'  => 'execute',
        'description' => 'Complete an attempt, write the grade and update completion.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/aiknowledgecheck:view',
    ],

    'mod_aiknowledgecheck_generate' => [
        'classname'   => 'mod_aiknowledgecheck\\external\\generate',
        'methodname'  => 'execute',
        'description' => 'Start an AI question generation job for an activity.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/aiknowledgecheck:create',
    ],

    'mod_aiknowledgecheck_save_questions' => [
        'classname'   => 'mod_aiknowledgecheck\\external\\save_questions',
        'methodname'  => 'execute',
        'description' => 'Replace the stored questions for an activity.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/aiknowledgecheck:create',
    ],

    'mod_aiknowledgecheck_regenerate_audio' => [
        'classname'   => 'mod_aiknowledgecheck\\external\\regenerate_audio',
        'methodname'  => 'execute',
        'description' => 'Regenerate voiceover audio for existing questions.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/aiknowledgecheck:create',
    ],

    'mod_aiknowledgecheck_regenerate_with_settings' => [
        'classname'   => 'mod_aiknowledgecheck\\external\\regenerate_with_settings',
        'methodname'  => 'execute',
        'description' => 'Regenerate questions after a voice or settings change.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/aiknowledgecheck:create',
    ],

    'mod_aiknowledgecheck_regenerate_instructions' => [
        'classname'   => 'mod_aiknowledgecheck\\external\\regenerate_instructions',
        'methodname'  => 'execute',
        'description' => 'Regenerate questions from extra teacher instructions.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/aiknowledgecheck:create',
    ],

    'mod_aiknowledgecheck_generate_image' => [
        'classname'   => 'mod_aiknowledgecheck\\external\\generate_image',
        'methodname'  => 'execute',
        'description' => 'Generate a question image through the AI service.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/aiknowledgecheck:create',
    ],

    'mod_aiknowledgecheck_save_image_url' => [
        'classname'   => 'mod_aiknowledgecheck\\external\\save_image_url',
        'methodname'  => 'execute',
        'description' => 'Store the image gate URL for an activity.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/aiknowledgecheck:create',
    ],

];
