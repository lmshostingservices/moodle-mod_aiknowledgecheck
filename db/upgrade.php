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
 * Upgrade steps for mod_aiknowledgecheck.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Execute mod_aiknowledgecheck upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_mod_aiknowledgecheck_upgrade($oldversion) {
    if ($oldversion < 2026072800) {
        upgrade_plugin_savepoint(true, 2026072800, 'mod', 'aiknowledgecheck');
    }
    return true;
}
