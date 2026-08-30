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
$plugin->version = 2026083013;
$plugin->requires = 2022041900;
$plugin->supported = [400, 500];
$plugin->maturity = MATURITY_STABLE;
// PIPELINE-TEST (v1.5.156): version bump only, to exercise the release pipeline against a
// fresh artifact. No code, language-string or AMD changes from 1.5.155 -- the two builds are
// byte-identical apart from this file and CHANGELOG.md. See CHANGELOG.md.
$plugin->release = '1.5.156';
