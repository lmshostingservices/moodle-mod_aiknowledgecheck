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
 * Backup task for mod_aiknowledgecheck.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/aiknowledgecheck/backup/moodle2/backup_aiknowledgecheck_stepslib.php');

/**
 * Backup task that provides all the settings and steps to perform one complete backup of the activity.
 */
class backup_aiknowledgecheck_activity_task extends backup_activity_task {
    protected function define_my_settings() {
        // No particular settings for this activity.
    }

    protected function define_my_steps() {
        $this->add_step(new backup_aiknowledgecheck_activity_structure_step('aiknowledgecheck_structure', 'aiknowledgecheck.xml'));
    }

    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        // Link to the list of aiknowledgecheck instances.
        $search = '/(' . $base . '\/mod\/aiknowledgecheck\/index.php\?id=)([0-9]+)/';
        $content = preg_replace($search, '$@AIKNOWLEDGECHECKINDEX*$2@$', $content);

        // Link to aiknowledgecheck view by moduleid.
        $search = '/(' . $base . '\/mod\/aiknowledgecheck\/view.php\?id=)([0-9]+)/';
        $content = preg_replace($search, '$@AIKNOWLEDGECHECKVIEWBYID*$2@$', $content);

        return $content;
    }
}
