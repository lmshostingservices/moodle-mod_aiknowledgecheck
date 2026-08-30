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
 * List of AI Knowledge Check instances in the course.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT); // Course ID.

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);

require_login($course);

// The list itself carries no activity data beyond names and links, and
// get_all_instances_in_course() below already filters by each activity's own visibility and
// availability. This states the read requirement explicitly at the course context rather
// than leaving it implied by require_login().
require_capability('mod/aiknowledgecheck:view', context_course::instance($course->id));

$PAGE->set_url('/mod/aiknowledgecheck/index.php', ['id' => $id]);
$PAGE->set_pagelayout('incourse');

$strname = get_string('modulenameplural', 'mod_aiknowledgecheck');
$PAGE->set_title($course->shortname . ': ' . $strname);
$PAGE->navbar->add($strname);

echo $OUTPUT->header();
echo $OUTPUT->heading($strname);

// Get all instances.
$knowledgechecks = get_all_instances_in_course('aiknowledgecheck', $course);

if (empty($knowledgechecks)) {
    notice(get_string('noknowledgechecks', 'mod_aiknowledgecheck'), new moodle_url('/course/view.php', ['id' => $course->id]));
    exit;
}

// Build table.
$usesections = course_format_uses_sections($course->format);

$table = new html_table();
$table->head = [];
$table->align = [];

if ($usesections) {
    $table->head[] = get_string('sectionname', 'format_' . $course->format);
    $table->align[] = 'center';
}

$table->head[] = get_string('name');
$table->head[] = get_string('description');
$table->align[] = 'left';
$table->align[] = 'left';

foreach ($knowledgechecks as $knowledgecheck) {
    $row = [];
    
    if ($usesections) {
        $row[] = get_section_name($course, $knowledgecheck->section);
    }
    
    $link = html_writer::link(
        new moodle_url('/mod/aiknowledgecheck/view.php', ['id' => $knowledgecheck->coursemodule]),
        format_string($knowledgecheck->name),
        ['class' => $knowledgecheck->visible ? '' : 'dimmed']
    );
    $row[] = $link;
    
    $intro = format_module_intro('aiknowledgecheck', $knowledgecheck, $knowledgecheck->coursemodule);
    $row[] = $intro;
    
    $table->data[] = $row;
}

echo html_writer::table($table);
echo $OUTPUT->footer();
