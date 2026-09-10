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
$plugin->version = 2026091001;
$plugin->requires = 2022041900;
$plugin->supported = [400, 500];
$plugin->maturity = MATURITY_STABLE;
// Keep release notes for this file in CHANGELOG.md, not here. Two rules apply to any comment
// that does end up in version.php, because both have already cost a release. First, the release
// pipeline scans comments as well as code, so a note must describe a rule in words rather than
// reproduce the token the scanner searches for; that mistake was made in v1.5.155 and again in
// v1.5.163. Second, a line comment may not carry a hanging indent, because the Moodle inline
// comment sniff rejects more than one space after the slashes; that mistake was made in v1.5.164
// and was caught only by an audit after the release had shipped. Flat sentences avoid both.
$plugin->release = '1.5.166';
