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
 * One-click repair for a version record stranded by legacy 13-digit builds.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());
require_sesskey();

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/mod/aiknowledgecheck/version_repair.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('versionrepair_title', 'mod_aiknowledgecheck'));
$PAGE->set_heading(get_string('versionrepair_title', 'mod_aiknowledgecheck'));

if (mod_aiknowledgecheck_version_is_stranded() === false) {
    redirect(
        new moodle_url('/admin/index.php'),
        get_string('versionrepair_notneeded', 'mod_aiknowledgecheck'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

$result = mod_aiknowledgecheck_repair_stranded_version();

echo $OUTPUT->header();
if (!empty($result['ok'])) {
    echo $OUTPUT->notification($result['message'], \core\output\notification::NOTIFY_SUCCESS);
    echo html_writer::tag('p', get_string('versionrepair_next', 'mod_aiknowledgecheck'));
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/admin/index.php'),
            get_string('versionrepair_gotonotifications', 'mod_aiknowledgecheck'),
            ['class' => 'btn btn-primary']
        ),
        '',
        ['style' => 'margin-top:12px;']
    );
} else {
    echo $OUTPUT->notification($result['message'], \core\output\notification::NOTIFY_WARNING);
}
echo $OUTPUT->footer();
