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
 * More attempts management page for AI Knowledge Check.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/aiknowledgecheck/lib.php');

$id = optional_param('id', 0, PARAM_INT); // Course module id.
$n = optional_param('n', 0, PARAM_INT); // Instance id (fallback).
$action = optional_param('action', '', PARAM_ALPHA);
$userid = optional_param('userid', 0, PARAM_INT);

// Resolve cm, course, instance.
if ($id) {
    $cm = get_coursemodule_from_id('aiknowledgecheck', $id, 0, false, MUST_EXIST);
    $kc = $DB->get_record('aiknowledgecheck', ['id' => $cm->instance], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
} else if ($n) {
    $kc = $DB->get_record('aiknowledgecheck', ['id' => $n], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('aiknowledgecheck', $kc->id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
} else {
    throw new moodle_exception('invalidcoursemodule');
}

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aiknowledgecheck:manageoverrides', $context);

$PAGE->set_url('/mod/aiknowledgecheck/moreattempts.php', ['id' => $cm->id]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('moreattempts', 'mod_aiknowledgecheck'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('report');

// Actions: Grant +1 for a user, or bulk +1.
if ($action === 'plusone' && confirm_sesskey() && $userid) {
    $now = time();
    $rec = $DB->get_record('aiknowledgecheck_overrides', ['aiknowledgecheckid' => $kc->id, 'userid' => $userid]);
    if ($rec) {
        $rec->extraattempts = max(0, (int)$rec->extraattempts) + 1;
        $rec->timemodified = $now;
        $DB->update_record('aiknowledgecheck_overrides', $rec);
    } else {
        $DB->insert_record('aiknowledgecheck_overrides', (object)[
            'aiknowledgecheckid' => $kc->id,
            'userid' => $userid,
            'extraattempts' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }
    redirect(new moodle_url($PAGE->url), get_string('changessaved'));
}

if ($action === 'bulkplusone' && confirm_sesskey()) {
    $selected = optional_param_array('selected', [], PARAM_INT);
    if ($selected) {
        $transaction = $DB->start_delegated_transaction();
        $now = time();
        list($inSql, $params) = $DB->get_in_or_equal($selected, SQL_PARAMS_NAMED);
        $existing = $DB->get_records_select('aiknowledgecheck_overrides',
            'aiknowledgecheckid = :kcid AND userid ' . $inSql,
            ['kcid' => $kc->id] + $params, '', 'id, userid, extraattempts');
        $map = [];
        foreach ($existing as $rec) {
            $map[$rec->userid] = $rec;
        }
        foreach ($selected as $uid) {
            if (isset($map[$uid])) {
                $rec = $map[$uid];
                $rec->extraattempts = max(0, (int)$rec->extraattempts) + 1;
                $rec->timemodified = $now;
                $DB->update_record('aiknowledgecheck_overrides', $rec);
            } else {
                $DB->insert_record('aiknowledgecheck_overrides', (object)[
                    'aiknowledgecheckid' => $kc->id,
                    'userid' => $uid,
                    'extraattempts' => 1,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            }
        }
        $transaction->allow_commit();
    }
    redirect(new moodle_url($PAGE->url), get_string('changessaved'));
}

// Enrolled users who can attempt - include all name fields for fullname().
$namefields = 'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email';
$users = get_enrolled_users($context, 'mod/aiknowledgecheck:view', 0,
    $namefields, 'u.lastname, u.firstname');

$userids = array_map(function ($u) {
    return $u->id;
}, $users);

// Attempts used (completed) per user.
$attemptsused = [];
if ($userids) {
    list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
    $sql = "SELECT userid, COUNT(1) AS used
              FROM {aiknowledgecheck_attempts}
             WHERE aiknowledgecheckid = :kcid AND userid $insql AND status = 1
          GROUP BY userid";
    $attemptsused = $DB->get_records_sql_menu($sql, ['kcid' => $kc->id] + $inparams);
}

// Overrides per user.
$overridesmap = [];
if ($userids) {
    list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
    $ovrs = $DB->get_records_select('aiknowledgecheck_overrides',
        'aiknowledgecheckid = :kcid AND userid ' . $insql,
        ['kcid' => $kc->id] + $inparams, '', 'userid, extraattempts');
    foreach ($ovrs as $o) {
        $overridesmap[$o->userid] = (int)$o->extraattempts;
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('moreattemptsheading', 'mod_aiknowledgecheck'));

// Bulk +1 form.
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url]);
echo html_writer::input_hidden_params($PAGE->url);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'bulkplusone']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

$table = new html_table();
$table->head = [
    '', // Checkbox for bulk.
    get_string('user'),
    get_string('userattempts', 'mod_aiknowledgecheck'),
    get_string('attemptsused', 'mod_aiknowledgecheck'),
    get_string('attemptsallowed', 'mod_aiknowledgecheck'),
    get_string('additionalattempts', 'mod_aiknowledgecheck'),
    get_string('totalallowed', 'mod_aiknowledgecheck'),
    get_string('actions'),
];

$basemax = (int)$kc->maxattempts;

foreach ($users as $u) {
    $used = (int)($attemptsused[$u->id] ?? 0);
    $extra = (int)($overridesmap[$u->id] ?? 0);

    // Effective = base + extra; if base == 0 (unlimited), show "Unlimited".
    $effective = ($basemax === 0) ? 0 : ($basemax + $extra);

    $checkbox = html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'name' => 'selected[]',
        'value' => $u->id,
    ]);
    $name = fullname($u) . html_writer::empty_tag('br') . s($u->email);

    $basecell = ($basemax === 0) ? get_string('unlimited', 'mod_aiknowledgecheck') : $basemax;
    $effcell = ($effective === 0) ? get_string('unlimited', 'mod_aiknowledgecheck') : $effective;

    // "User attempts" column → link to the report filtered by this user.
    $reporturl = new moodle_url('/mod/aiknowledgecheck/report.php', [
        'id' => $cm->id,
        'userid' => $u->id,
    ]);
    $userattemptslink = html_writer::link($reporturl, get_string('view', 'mod_aiknowledgecheck'));

    // Actions.
    $plusoneurl = new moodle_url($PAGE->url, [
        'action' => 'plusone',
        'userid' => $u->id,
        'sesskey' => sesskey(),
    ]);
    $actions = html_writer::link($plusoneurl, get_string('grantplusone', 'mod_aiknowledgecheck'));

    $table->data[] = new html_table_row([
        new html_table_cell($checkbox),
        new html_table_cell($name),
        new html_table_cell($userattemptslink),
        new html_table_cell($used),
        new html_table_cell($basecell),
        new html_table_cell($extra),
        new html_table_cell($effcell),
        new html_table_cell($actions),
    ]);
}

echo html_writer::table($table);
echo html_writer::tag('button', get_string('bulkgrantplusone', 'mod_aiknowledgecheck'),
    ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
