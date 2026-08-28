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
$plugin->version = 2026082802;
$plugin->requires = 2022041900;
$plugin->supported = [400, 500];
$plugin->maturity = MATURITY_STABLE;
$plugin->release = '1.5.142'; // SURVEY COMPLETION FIX (v1.5.142): FIX-KC-SURVEY-BLANK - after answering the last survey question the student saw a blank screen. showResults() rendered the survey completion panel into '#kc-results-container', an element that exists nowhere in the plugin's markup, so jQuery matched nothing and the hidden '#kc-results' card was never revealed. Responses were saved correctly; only the confirmation was invisible. Now renders into '#kc-results', the container the quiz path already uses. Same bug class as v1.5.140's phantom '#survey-scale'. Test harness corrected to mirror view.php so it asserts visibility rather than defining the missing element itself. No DB schema changes. AMD rebuilt. // SURVEY SCALE ENFORCEMENT (v1.5.141): FIX-KC-SURVEY-SCALE-OPTIONS - the plugin defined no canonical scale options anywhere and simply stored whatever answer options the generation API returned, so the teacher's chosen Response Scale was honoured only if the model complied. Picking Yes/No (or any scale other than 5-point Agreement) frequently produced Agreement options with no sign the choice had been ignored. lib.php now defines the authoritative option set for all nine scales, and ajax.php overwrites the stored options with the correct set for the activity's scale on every save. The AI still writes question text; it no longer decides the response options. Includes v1.5.140's FIX-KC-SURVEY-SCALE, which forwards the scale from config rather than a nonexistent DOM element. No DB schema changes. AMD rebuilt. // SURVEY SCALE FIX (v1.5.140): FIX-KC-SURVEY-SCALE - the Response Scale chosen in the activity settings was ignored for every scale except the first. The generate request read the scale from a '#survey-scale' DOM element that has never existed in the plugin (the setting lives in mod_form.php, not on the view page), so jQuery .val() returned undefined and the '|| likert5agree' fallback fired on every generation. Satisfaction, Frequency, Quality, Importance, Likert 4-point, Yes/No, Yes/No/Unsure and NPS all silently produced Agreement questions. view.php already passed the correct value as config.surveyScale; the JS now reads that. No DB schema changes. AMD rebuilt. // EDITOR SURVEY FIXES (v1.5.139): FIX-KC-EDIT-SURVEY - the teacher Edit Questions screen was built for 4-option quizzes and never updated for survey mode. It rendered only 4 options (hiding the 5th point of 5-point scales) and rendered free-text questions as multiple choice, which also made them unsaveable. Both save paths hardcoded options[0..3] and omitted questionType, so ajax.php nulled answer5 and defaulted questiontype to 'scale' - deleting the 5th scale option and converting free-text questions to blank multiple choice on every save. Editor now renders the question's real option count (2-5 in survey mode, min 4 in quiz mode), renders free-text as free-text, hides correct-answer radios and explanations in survey mode, and both save paths carry all options plus questionType. Question CSV export gains an Option E column. No DB schema changes. AMD rebuilt.