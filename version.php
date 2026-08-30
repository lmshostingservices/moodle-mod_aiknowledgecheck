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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Version information for AI Knowledge Check.
 *
 * Release history is maintained in CHANGELOG.md.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_aiknowledgecheck';
$plugin->version = 2026083012;
$plugin->requires = 2022041900;
$plugin->supported = [400, 500];
$plugin->maturity = MATURITY_STABLE;
// MOODLE-CI-PREP (v1.5.155, build 2026083012): adds the missing Moodle Plugin CI workflow, and clears the
// mechanically fixable part of the Moodle code checker -- 1505 unique findings down to well
// under 200 -- with phpcbf plus targeted fixes. Completes three truncated GPL headers, adds
// missing trailing newlines, removes MOODLE_INTERNAL where the checker says it is not needed,
// renames non-conforming local variables, adds the backup and restore docblocks, and
// reformats multi-line calls to one argument per line, which is what the Moodle standard
// wants and what the previous release's formatting had broken. See CHANGELOG.md.
$plugin->release = '1.5.155';
