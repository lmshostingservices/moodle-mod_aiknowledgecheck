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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * Upgrade helpers for AI Knowledge Check.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Restore media and survey columns skipped by historical version rebasing.
 *
 * Column positioning is deliberately omitted: MySQL rejects an otherwise valid
 * addition when its optional preceding column is also missing. Definitions match
 * install.xml. Existing fields and their data are never changed or removed.
 *
 * @return void
 */
function xmldb_aiknowledgecheck_repair_skipped_schema(): void {
    global $DB;

    $dbman = $DB->get_manager();
    $fields = [
        'aiknowledgecheck' => [
            new xmldb_field('imageurl', XMLDB_TYPE_TEXT),
            new xmldb_field('surveymode', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('surveyscale', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'likert5agree'),
            new xmldb_field('showcorrectanswer', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1'),
        ],
        'aiknowledgecheck_questions' => [
            new xmldb_field('imageurl', XMLDB_TYPE_TEXT),
            new xmldb_field('imageenabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('questionvideourl', XMLDB_TYPE_CHAR, '512'),
            new xmldb_field('questionvideoenabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('questionaudiourl', XMLDB_TYPE_CHAR, '512'),
            new xmldb_field('questionaudioenabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0'),
            new xmldb_field('answer5', XMLDB_TYPE_TEXT),
            new xmldb_field('questiontype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'scale'),
        ],
    ];
    foreach ($fields as $tablename => $definitions) {
        $table = new xmldb_table($tablename);
        foreach ($definitions as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
    }
}
