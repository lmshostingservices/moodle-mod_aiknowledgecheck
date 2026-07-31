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
 * Restore task for mod_aiknowledgecheck.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/aiknowledgecheck/backup/moodle2/restore_aiknowledgecheck_stepslib.php');

/**
 * Restore task that provides all the settings and steps to perform one complete restore of the activity.
 */
class restore_aiknowledgecheck_activity_task extends restore_activity_task {

    protected function define_my_settings() {
        // No particular settings for this activity.
    }

    protected function define_my_steps() {
        $this->add_step(new restore_aiknowledgecheck_activity_structure_step('aiknowledgecheck_structure', 'aiknowledgecheck.xml'));
    }

    public static function define_decode_contents() {
        $contents = [];
        $contents[] = new restore_decode_content('aiknowledgecheck', ['intro'], 'aiknowledgecheck');
        return $contents;
    }

    public static function define_decode_rules() {
        $rules = [];

        $rules[] = new restore_decode_rule('AIKNOWLEDGECHECKVIEWBYID', '/mod/aiknowledgecheck/view.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('AIKNOWLEDGECHECKINDEX', '/mod/aiknowledgecheck/index.php?id=$1', 'course');

        return $rules;
    }

    public static function define_restore_log_rules() {
        $rules = [];
        $rules[] = new restore_log_rule('aiknowledgecheck', 'add', 'view.php?id={course_module}', '{aiknowledgecheck}');
        $rules[] = new restore_log_rule('aiknowledgecheck', 'update', 'view.php?id={course_module}', '{aiknowledgecheck}');
        $rules[] = new restore_log_rule('aiknowledgecheck', 'view', 'view.php?id={course_module}', '{aiknowledgecheck}');
        return $rules;
    }

    public static function define_restore_log_rules_for_course() {
        $rules = [];
        $rules[] = new restore_log_rule('aiknowledgecheck', 'view all', 'index.php?id={course}', null);
        return $rules;
    }
}
