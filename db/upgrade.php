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
 * Upgrade steps for AI Knowledge Check.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade function for mod_aiknowledgecheck.
 *
 * @param int $oldversion the version we are upgrading from
 * @return bool always true
 */
function xmldb_aiknowledgecheck_upgrade($oldversion) {
    if ($oldversion < 2026072800) {
        upgrade_mod_savepoint(true, 2026072800, 'aiknowledgecheck');
    }

    if ($oldversion < 2026081200) {
        upgrade_mod_savepoint(true, 2026081200, 'aiknowledgecheck');
    }

    if ($oldversion < 2026081201) {
        upgrade_mod_savepoint(true, 2026081201, 'aiknowledgecheck');
    }

    if ($oldversion < 2026081202) {
        upgrade_mod_savepoint(true, 2026081202, 'aiknowledgecheck');
    }

    return true;
}
