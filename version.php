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
$plugin->version = 2026082700;
$plugin->requires = 2022041900;
$plugin->supported = [400, 500];
$plugin->maturity = MATURITY_STABLE;
$plugin->release = '1.5.139'; // EDITOR SURVEY FIXES (v1.5.139): FIX-KC-EDIT-SURVEY - the teacher Edit Questions screen was built for 4-option quizzes and never updated for survey mode. It rendered only 4 options (hiding the 5th point of 5-point scales) and rendered free-text questions as multiple choice, which also made them unsaveable. Both save paths hardcoded options[0..3] and omitted questionType, so ajax.php nulled answer5 and defaulted questiontype to 'scale' - deleting the 5th scale option and converting free-text questions to blank multiple choice on every save. Editor now renders the question's real option count (2-5 in survey mode, min 4 in quiz mode), renders free-text as free-text, hides correct-answer radios and explanations in survey mode, and both save paths carry all options plus questionType. Question CSV export gains an Option E column. No DB schema changes. AMD rebuilt.