<?php
// phpcs:disable moodle.Files.LineLength
// phpcs:disable moodle.Commenting.MissingDocblock.File
// phpcs:disable moodle.Commenting.InlineComment
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
 * Attempts report page for AI Knowledge Check.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/aiknowledgecheck/lib.php');

$id = required_param('id', PARAM_INT); // Course module id.
$userid = optional_param('userid', 0, PARAM_INT); // Optional user filter.

$cm = get_coursemodule_from_id('aiknowledgecheck', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$knowledgecheck = $DB->get_record('aiknowledgecheck', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aiknowledgecheck:viewreports', $context);

// ADD-SURVEY-REPORT (v1.5.127): CSV export for survey mode — output before any page headers.
$iscsvexport = optional_param('export', '', PARAM_ALPHA) === 'csv';
if ($iscsvexport && !empty($knowledgecheck->surveymode)) {
    // Load questions.
    $csvquestions = $DB->get_records(
        'aiknowledgecheck_questions',
        ['aiknowledgecheckid' => $knowledgecheck->id],
        'id ASC',
        'id, questiontext AS question, answer1, answer2, answer3, answer4, answer5, questiontype'
    );

    // Load all completed attempts with user details.
    $csvsql = "SELECT a.id AS attemptid, a.userid, a.answers, a.timestarted, a.timeended,
                      u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                      u.middlename, u.alternatename
                 FROM {aiknowledgecheck_attempts} a
                 JOIN {user} u ON u.id = a.userid
                WHERE a.aiknowledgecheckid = :kid AND a.status = 1
             ORDER BY u.lastname, u.firstname, u.id, a.id ASC";
    $csvattempts = $DB->get_records_sql($csvsql, ['kid' => $knowledgecheck->id]);

    // Build CSV.
    $csvrows = [];
    // Header row.
    $csvheader = ['Student', 'Date Completed', 'Time Spent (seconds)'];
    foreach ($csvquestions as $cq) {
        $csvheader[] = $cq->question;
    }
    $csvrows[] = $csvheader;

    foreach ($csvattempts as $ca) {
        $answers = json_decode($ca->answers, true) ?: [];
        $studentname = fullname($ca);
        $datecompleted = $ca->timeended ? date('Y-m-d H:i', $ca->timeended) : '';
        $timespent = ($ca->timestarted && $ca->timeended && $ca->timeended >= $ca->timestarted)
            ? ($ca->timeended - $ca->timestarted) : '';

        $row = [$studentname, $datecompleted, $timespent];
        foreach ($csvquestions as $cq) {
            $ans = $answers[$cq->id] ?? null;
            if ($cq->questiontype === 'freetext') {
                $row[] = is_array($ans) ? ($ans['freetext'] ?? '') : '';
            } else {
                $idx = is_array($ans) ? ($ans['answer'] ?? null) : $ans;
                if ($idx !== null && $idx >= 0) {
                    $optfield = 'answer' . ((int)$idx + 1);
                    $row[] = isset($cq->$optfield) ? $cq->$optfield : '';
                } else {
                    $row[] = '';
                }
            }
        }
        $csvrows[] = $row;
    }

    // Output CSV.
    $filename = clean_filename($knowledgecheck->name) . '_survey_responses_' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $out = fopen('php://output', 'w');
    // BOM for Excel UTF-8 compatibility.
    fwrite($out, "\xEF\xBB\xBF");
    // H-2: neutralise CSV formula injection. fputcsv quotes for CSV structure but does NOT
    // stop a spreadsheet from evaluating a cell that begins with = + - @ (or a leading tab/CR)
    // as a formula. Student free-text responses are written into these cells, so prefix any
    // such value with an apostrophe before writing.
    $csvsafe = function ($v) {
        $s = (string)$v;
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $s;
        }
        return $s;
    };
    foreach ($csvrows as $r) {
        fputcsv($out, array_map($csvsafe, $r));
    }
    fclose($out);
    die();
}

// If filtering by user, load user (ensure it exists).
// L-4: only load a user who actually has an attempt in THIS activity. Previously any global
// userid could be loaded with MUST_EXIST — an existence oracle + cross-course full-name leak
// for anyone with viewreports here. Validate against this activity's attempts first.
$user = null;
if ($userid) {
    $hasattempt = $DB->record_exists(
        'aiknowledgecheck_attempts',
        ['aiknowledgecheckid' => $knowledgecheck->id, 'userid' => $userid]
    );
    if ($hasattempt) {
        $user = $DB->get_record(
            'user',
            ['id' => $userid, 'deleted' => 0],
            'id,firstname,lastname,alternatename,firstnamephonetic,lastnamephonetic,middlename,email',
            MUST_EXIST
        );
    } else {
        // Not a participant of this activity — ignore the filter rather than leaking existence.
        $userid = 0;
    }
}

// Page setup.
$urlparams = ['id' => $id];
if ($userid) {
    $urlparams['userid'] = $userid;
}
$PAGE->set_url(new moodle_url('/mod/aiknowledgecheck/report.php', $urlparams));
$title = format_string($knowledgecheck->name) . ' - ' . get_string('attemptsreport', 'mod_aiknowledgecheck');
if ($user) {
    $title .= ' — ' . fullname($user, true);
}
$PAGE->set_title($title);
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('attemptsreport', 'mod_aiknowledgecheck'));

// Show user filter info.
if ($user) {
    echo html_writer::div(
        html_writer::span(fullname($user, true)) . ' ' .
        html_writer::span('·') . ' ' .
        html_writer::link(
            new moodle_url('/mod/aiknowledgecheck/report.php', ['id' => $id]),
            get_string('allparticipants')
        ),
        'mb-3'
    );
}

// Build user picker - include all name fields required by fullname().
$coursecontext = context_course::instance($course->id);
$namefields = 'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email';
$enrolled = get_enrolled_users($coursecontext, '', 0, $namefields, 'u.lastname, u.firstname, u.id');

// Also include users who have attempts (in case they're not currently enrolled).
$attemptuserids = $DB->get_records_sql_menu(
    "SELECT DISTINCT u.id, u.id
       FROM {aiknowledgecheck_attempts} a
       JOIN {user} u ON u.id = a.userid
      WHERE a.aiknowledgecheckid = :kid AND u.deleted = 0",
    ['kid' => $knowledgecheck->id]
);

$picker = [];
foreach ($enrolled as $eu) {
    $picker[$eu->id] = $eu;
}
if (!empty($attemptuserids)) {
    [$insql, $inparams] = $DB->get_in_or_equal(array_keys($attemptuserids), SQL_PARAMS_NAMED);
    $extrausers = $DB->get_records_select(
        'user',
        "id $insql AND deleted = 0",
        $inparams,
        'lastname, firstname, id',
        'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename, email'
    );
    foreach ($extrausers as $xu) {
        if (!isset($picker[$xu->id])) {
            $picker[$xu->id] = $xu;
        }
    }
}

// Sort picker list.
usort(
    $picker,
    function ($a, $b) {
        $al = core_text::strtolower($a->lastname . ' ' . $a->firstname);
        $bl = core_text::strtolower($b->lastname . ' ' . $b->firstname);
        if ($al === $bl) {
            return $a->id <=> $b->id;
        }
        return $al <=> $bl;
    }
);

// Prepare options for user picker.
$useroptions = [];
foreach ($picker as $pu) {
    $label = fullname($pu, true);
    $url = (new moodle_url('/mod/aiknowledgecheck/report.php', ['id' => $cm->id, 'userid' => $pu->id]))->out(false);
    $useroptions[] = ['id' => (int)$pu->id, 'label' => $label, 'url' => $url];
}

$currentlabel = ($userid && $user) ? fullname($user, true) : '';
$allurl = new moodle_url('/mod/aiknowledgecheck/report.php', ['id' => $cm->id]);

// Render user picker.
echo html_writer::start_div('kc-userpicker mb-3');
echo html_writer::tag('label', get_string('user') . ':', ['for' => 'kc-userinput', 'class' => 'mr-2']);
echo html_writer::empty_tag(
    'input',
    [
        'type' => 'text',
        'id' => 'kc-userinput',
        'class' => 'form-control',
        'style' => 'max-width:520px; display:inline-block;',
        'list' => 'kc-userdatalist',
        'placeholder' => '',
        'value' => $currentlabel,
        'autocomplete' => 'off',
    ]
);
echo html_writer::start_tag('datalist', ['id' => 'kc-userdatalist']);
foreach ($useroptions as $opt) {
    echo html_writer::empty_tag('option', ['value' => $opt['label']]);
}
echo html_writer::end_tag('datalist');
echo ' ' . html_writer::link($allurl, get_string('allparticipants'), ['class' => 'btn btn-link p-1']);
// L-5: HEX-escape so a display name containing </script> or quotes can't break out of the
// inline JSON <script> block (defence-in-depth on top of Moodle's PARAM_NOTAGS on names).
echo html_writer::tag('script', json_encode($useroptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ['type' => 'application/json', 'id' => 'kc-user-map']);
echo html_writer::end_div();

// User picker JS.
$js = <<<JS
(function (){
  var input = document.getElementById('kc-userinput');
  var dataEl = document.getElementById('kc-user-map');
  if (!input || !dataEl) return;
  var map = [];
  try { map = JSON.parse(dataEl.textContent || '[]'); } catch(e){ map = []; }

  function gotoForValue(val){
    val = (val || '').trim();
    if (!val) { window.location = '{$allurl->out(false)}'; return true; }
    var lower = val.toLowerCase();

    // Exact match first.
    for (var i=0;i<map.length;i++){
      if ((map[i].label || '').toLowerCase() === lower) { window.location = map[i].url; return true; }
    }
    // Single partial match.
    var matches = map.filter(function (m){ return (m.label || '').toLowerCase().indexOf(lower) !== -1; });
    if (matches.length === 1) { window.location = matches[0].url; return true; }
    return false;
  }

  input.addEventListener('change', function (){ gotoForValue(input.value); });
  input.addEventListener('keydown', function (e){
    if (e.key === 'Enter') { if (gotoForValue(input.value)) { e.preventDefault(); } }
  });
})();
JS;
$PAGE->requires->js_init_code($js);

// ── Data loading ──────────────────────────────────────────────────────────────.

// Total quiz questions — used as fixed denominator for all attempt scores.
$totalqs = (int)$DB->count_records('aiknowledgecheck_questions', ['aiknowledgecheckid' => $knowledgecheck->id]);

// Build WHERE clause with optional user filter.
$params = ['kid' => $knowledgecheck->id];
$where = ['a.aiknowledgecheckid = :kid'];
if ($userid) {
    $where[] = 'a.userid = :userid';
    $params['userid'] = $userid;
}
$whereclause = implode(' AND ', $where);

// Get attempts ordered by user then attempt id.
// IMPORTANT: explicitly alias conflicting column names so that user table fields
// (id, timecreated, timemodified) do not silently overwrite attempt table fields.
$sql = "SELECT a.id            AS attemptid,
               a.aiknowledgecheckid,
               a.userid,
               a.currentquestion,
               a.answers,
               a.correctcount,
               a.totalcount,
               a.status,
               a.timecreated   AS attempt_timecreated,
               a.timemodified  AS attempt_timemodified,
               a.timestarted,
               a.timeended,
               u.firstname,
               u.lastname,
               u.firstnamephonetic,
               u.lastnamephonetic,
               u.middlename,
               u.alternatename
          FROM {aiknowledgecheck_attempts} a
          JOIN {user} u ON u.id = a.userid
         WHERE $whereclause
      ORDER BY u.lastname, u.firstname, u.id, a.id ASC";
$attempts = $DB->get_records_sql($sql, $params);

// Base max attempts for the activity.
$basemax = isset($knowledgecheck->maxattempts) ? (int)$knowledgecheck->maxattempts : 0;

// Load per-user overrides.
$extrabyuser = [];
if (!empty($attempts)) {
    $userids = array_values(
        array_unique(
            array_map(
                function ($a) {
                    return (int)$a->userid;
                },
                $attempts
            )
        )
    );
    if (!empty($userids)) {
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $ovrs = $DB->get_records_select(
            'aiknowledgecheck_overrides',
            'aiknowledgecheckid = :kcid AND userid ' . $insql,
            ['kcid' => $knowledgecheck->id] + $inparams,
            '',
            'userid, extraattempts'
        );
        foreach ($ovrs as $o) {
            $extrabyuser[(int)$o->userid] = (int)$o->extraattempts;
        }
    }
}

// Localised labels.
$timespentlabel = get_string_manager()->string_exists('timespent', 'mod_aiknowledgecheck')
    ? get_string('timespent', 'mod_aiknowledgecheck') : 'Time Spent';

// ── Group attempts by user ────────────────────────────────────────────────────.
// Each row shows THAT ATTEMPT'S actual score (not a running maximum).
// The "Best:" summary shown in the accordion header is the true maximum.
// The correctcount field already includes carry-forward answers for retry-mode attempts:
// the save-answer and finish-attempt services store the cumulative count each time.
// Keyed by user id; each entry holds the display name, the best correct count, and the
// list of that user's attempts.
$byuser   = [];
$counters = [];

foreach ($attempts as $a) {
    $uid = (int)$a->userid;
    if (!isset($byuser[$uid])) {
        $byuser[$uid] = [
            'name'        => fullname($a, true),
            'userid'      => $uid,
            'bestcorrect' => 0,
            'attempts'    => [],
        ];
        $counters[$uid] = 0;
    }
    $counters[$uid]++;

    // ── Per-attempt correct count ─────────────────────────────────────────────.
    // Use the stored correctcount directly. For "Retry Wrong Answers" mode,
    // ajax.php pre-saves carry-forward correct answers so correctcount already
    // reflects the cumulative total (not just newly answered questions).
    $correctcount = 0;
    if (isset($a->correctcount) && $a->correctcount !== null) {
        $correctcount = (int)$a->correctcount;
    } else if (!empty($a->answers)) {
        // Fallback: recompute from answers JSON + question map.
        $qmap = $qmap ?? $DB->get_records_menu(
            'aiknowledgecheck_questions',
            ['aiknowledgecheckid' => $knowledgecheck->id],
            '',
            'id,correctanswer'
        );
        $answers = json_decode($a->answers, true) ?: [];
        foreach ($answers as $qid => $ans) {
            $selected = is_array($ans) ? ($ans['answer'] ?? null) : $ans;
            if (isset($qmap[$qid]) && (int)$selected === (int)$qmap[$qid]) {
                $correctcount++;
            }
        }
    }

    // Show this attempt's actual score per row.
    $score = ($totalqs > 0) ? ($correctcount . '/' . $totalqs) : '-';

    // Track best score across all attempts for the accordion header summary.
    if ($correctcount > $byuser[$uid]['bestcorrect']) {
        $byuser[$uid]['bestcorrect'] = $correctcount;
    }

    // ── Times — use aliased attempt columns (avoid user-table collision) ───────.
    $startts = 0;
    $timestarted = '';
    if (!empty($a->timestarted)) {
        $startts = (int)$a->timestarted;
        $timestarted = userdate($a->timestarted);
    } else if (!empty($a->attempt_timecreated)) {
        $startts = (int)$a->attempt_timecreated;
        $timestarted = userdate($a->attempt_timecreated);
    }

    $endts = 0;
    $timeended = '';
    if (!empty($a->timeended)) {
        $endts = (int)$a->timeended;
        $timeended = userdate($a->timeended);
    } else if ($a->status == 1 && !empty($a->attempt_timemodified)) {
        $endts = (int)$a->attempt_timemodified;
        $timeended = userdate($a->attempt_timemodified);
    } else if ($a->status == 0) {
        $timeended = get_string('inprogress', 'mod_aiknowledgecheck');
    }

    $timespentstr = '-';
    if ($startts && $endts && $endts >= $startts) {
        $timespentstr = format_time($endts - $startts);
    } else if ($a->status == 0 && $startts) {
        $timespentstr = get_string('inprogress', 'mod_aiknowledgecheck');
    }

    // ── Attempt label (X/max) ─────────────────────────────────────────────────.
    $extra = $extrabyuser[$uid] ?? 0;
    $effectivemax = ($basemax > 0) ? ($basemax + max(0, (int)$extra)) : 0;
    $attemptno = $counters[$uid] . '/' . ($effectivemax > 0 ? $effectivemax : '∞');

    $byuser[$uid]['attempts'][] = [
        'attemptno'   => $attemptno,
        'score'       => $score,
        'timestarted' => $timestarted,
        'timeended'   => $timeended,
        'timespent'   => $timespentstr,
        'status'      => (int)$a->status,
    ];
}

// ── Render ────────────────────────────────────────────────────────────────────.

// ADD-SURVEY-REPORT (v1.5.127): For survey mode, show response distribution and freetext responses
// instead of the standard score accordion.
if (!empty($knowledgecheck->surveymode)) {
    // Load questions and collect responses from all completed attempts.
    $surveyqs = $DB->get_records(
        'aiknowledgecheck_questions',
        ['aiknowledgecheckid' => $knowledgecheck->id],
        'id ASC',
        'id, questiontext AS question, answer1, answer2, answer3, answer4, answer5, questiontype'
    );

    $surveysql = "SELECT a.id AS attemptid, a.userid, a.answers, a.timestarted, a.timeended, a.status,
                         u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                         u.middlename, u.alternatename
                    FROM {aiknowledgecheck_attempts} a
                    JOIN {user} u ON u.id = a.userid
                   WHERE a.aiknowledgecheckid = :kid AND a.status = 1
                ORDER BY u.lastname, u.firstname, u.id, a.id ASC";
    $surveyattempts = $DB->get_records_sql($surveysql, ['kid' => $knowledgecheck->id]);

    // Build response counts per question. Scale questions are counted per question id and
    // zero-based option index. Free-text questions instead collect one entry per response,
    // each holding the student name, the response text and the submission date.
    $responsecounts   = [];
    $freetextresponses = [];
    $studentrows      = []; // Per-student summary: name, completion date, time spent.
    foreach ($surveyqs as $sq) {
        $responsecounts[$sq->id]    = [];
        $freetextresponses[$sq->id] = [];
    }

    foreach ($surveyattempts as $sa) {
        $answers = json_decode($sa->answers, true) ?: [];
        $studentname = fullname($sa, true);
        $datecompleted = $sa->timeended ? userdate($sa->timeended) : '-';
        $timespentstr = '-';
        if ($sa->timestarted && $sa->timeended && $sa->timeended >= $sa->timestarted) {
            $timespentstr = format_time($sa->timeended - $sa->timestarted);
        }
        $studentrows[] = [
            'name'    => $studentname,
            'date'    => $datecompleted,
            'spent'   => $timespentstr,
        ];
        foreach ($surveyqs as $sq) {
            $ans = $answers[$sq->id] ?? null;
            if ($sq->questiontype === 'freetext') {
                $ftval = '';
                if (is_array($ans)) {
                    $ftval = $ans['freetext'] ?? '';
                }
                if ($ftval !== '') {
                    $freetextresponses[$sq->id][] = [
                        'name'     => $studentname,
                        'response' => $ftval,
                        'date'     => $datecompleted,
                    ];
                }
            } else {
                $idx = is_array($ans) ? ($ans['answer'] ?? null) : $ans;
                if ($idx !== null && $idx !== '' && (int)$idx >= 0) {
                    $iidx = (int)$idx;
                    if (!isset($responsecounts[$sq->id][$iidx])) {
                        $responsecounts[$sq->id][$iidx] = 0;
                    }
                    $responsecounts[$sq->id][$iidx]++;
                }
            }
        }
    }

    $totalresponses = count($surveyattempts);
    $csvurl = (new moodle_url('/mod/aiknowledgecheck/report.php', ['id' => $id, 'export' => 'csv']))->out(false);

    // Survey report styles.
    echo html_writer::tag(
        'style',
        '
        .kc-survey-report { max-width: 900px; }
        .kc-survey-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; align-items: center; }
        .kc-survey-stats { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 24px; }
        .kc-survey-stat-chip { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; padding: 6px 12px; font-size: 0.85em; color: #495057; }
        .kc-survey-stat-chip strong { color: #212529; }
        .kc-survey-q-block { border: 1px solid #dee2e6; border-radius: 6px; margin-bottom: 16px; overflow: hidden; }
        .kc-survey-q-header { background: #f8f9fa; padding: 12px 16px; font-weight: 600; font-size: 0.95em; border-bottom: 1px solid #dee2e6; }
        .kc-survey-q-num { display: inline-block; background: #667eea; color: #fff; border-radius: 50%; width: 22px; height: 22px; line-height: 22px; text-align: center; font-size: 0.78em; font-weight: 700; margin-right: 8px; vertical-align: middle; }
        .kc-survey-q-body { padding: 14px 16px; }
        .kc-survey-bar-row { display: flex; align-items: center; margin-bottom: 8px; font-size: 0.9em; }
        .kc-survey-bar-label { min-width: 180px; max-width: 220px; padding-right: 10px; color: #495057; word-break: break-word; }
        .kc-survey-bar-wrap { flex: 1; background: #e9ecef; border-radius: 3px; height: 18px; margin-right: 10px; overflow: hidden; }
        .kc-survey-bar-fill { height: 18px; background: #667eea; border-radius: 3px; transition: width 0.3s; min-width: 2px; }
        .kc-survey-bar-count { min-width: 60px; color: #6c757d; font-size: 0.85em; }
        .kc-survey-q-noresp { color: #9ca3af; font-size: 0.88em; font-style: italic; padding: 4px 0; }
        .kc-survey-freetext-section { margin-top: 24px; }
        .kc-survey-freetext-section h3 { font-size: 1em; font-weight: 700; margin-bottom: 12px; color: #374151; }
        .kc-survey-ft-block { border: 1px solid #dee2e6; border-radius: 6px; margin-bottom: 16px; overflow: hidden; }
        .kc-survey-ft-header { background: #fff8ee; padding: 10px 16px; font-weight: 600; font-size: 0.93em; border-bottom: 1px solid #dee2e6; }
        .kc-survey-ft-body { padding: 0; }
        .kc-survey-ft-row { padding: 10px 16px; border-bottom: 1px solid #f3f4f6; display: flex; flex-wrap: wrap; gap: 6px; }
        .kc-survey-ft-row:last-child { border-bottom: 0; }
        .kc-survey-ft-name { font-weight: 600; font-size: 0.85em; color: #6c757d; min-width: 150px; }
        .kc-survey-ft-text { flex: 1; font-size: 0.9em; color: #212529; white-space: pre-wrap; word-break: break-word; }
        .kc-survey-ft-date { font-size: 0.78em; color: #9ca3af; white-space: nowrap; }
        .kc-survey-students { margin-top: 28px; }
        .kc-survey-students h3 { font-size: 1em; font-weight: 700; margin-bottom: 10px; color: #374151; }
        @media print { .kc-survey-actions { display: none; } .kc-userpicker { display: none; } }
    '
    );

    echo html_writer::start_div('kc-survey-report');

    // Actions bar.
    echo html_writer::start_div('kc-survey-actions');
    echo html_writer::link(
        $csvurl,
        '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:5px;"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Export CSV',
        ['class' => 'btn btn-secondary btn-sm']
    );
    echo ' <button onclick="window.print()" class="btn btn-secondary btn-sm" style="margin-left:4px;">' .
        '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:5px;"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>Print / PDF' .
        '</button>';
    echo html_writer::end_div();

    // Summary stats.
    echo html_writer::start_div('kc-survey-stats');
    echo html_writer::tag(
        'div',
        '<strong>' . $totalresponses . '</strong> ' . ($totalresponses === 1 ? 'response' : 'responses'),
        ['class' => 'kc-survey-stat-chip']
    );
    $scaleqs = array_filter(
        (array)$surveyqs,
        function ($q) {
            return $q->questiontype !== 'freetext';
        }
    );
    $ftqs = array_filter(
        (array)$surveyqs,
        function ($q) {
            return $q->questiontype === 'freetext';
        }
    );
    echo html_writer::tag(
        'div',
        '<strong>' . count($scaleqs) . '</strong> scale ' . (count($scaleqs) === 1 ? 'question' : 'questions'),
        ['class' => 'kc-survey-stat-chip']
    );
    if (count($ftqs) > 0) {
        echo html_writer::tag(
            'div',
            '<strong>' . count($ftqs) . '</strong> open-ended ' . (count($ftqs) === 1 ? 'question' : 'questions'),
            ['class' => 'kc-survey-stat-chip']
        );
    }
    echo html_writer::end_div();

    if (empty($surveyattempts)) {
        echo html_writer::div(get_string('noattempts', 'mod_aiknowledgecheck'), 'text-muted mb-3');
    } else {
        // ── Scale questions: response distribution ────────────────────────────.
        $qnum = 0;
        foreach ($surveyqs as $sq) {
            $qnum++;
            if ($sq->questiontype === 'freetext') {
                continue; // Handled separately below.
            }

            // Build option labels.
            $optlabels = [];
            for ($oi = 1; $oi <= 5; $oi++) {
                $f = 'answer' . $oi;
                if (!empty($sq->$f)) {
                    $optlabels[] = $sq->$f;
                }
            }
            $counts = $responsecounts[$sq->id];
            $maxcount = !empty($counts) ? max($counts) : 0;

            echo html_writer::start_div('kc-survey-q-block');
            echo '<div class="kc-survey-q-header"><span class="kc-survey-q-num">' . $qnum . '</span>' .
                htmlspecialchars($sq->question, ENT_QUOTES) . '</div>';
            echo html_writer::start_div('kc-survey-q-body');

            if (empty($optlabels)) {
                echo '<div class="kc-survey-q-noresp">No options defined.</div>';
            } else {
                $totalanswered = array_sum($counts);
                foreach ($optlabels as $oidx => $olabel) {
                    $cnt  = $counts[$oidx] ?? 0;
                    $pct  = $maxcount > 0 ? round(($cnt / $maxcount) * 100) : 0;
                    $pctof = $totalanswered > 0 ? round(($cnt / $totalanswered) * 100) : 0;
                    echo '<div class="kc-survey-bar-row">';
                    echo '<div class="kc-survey-bar-label">' . htmlspecialchars($olabel, ENT_QUOTES) . '</div>';
                    echo '<div class="kc-survey-bar-wrap"><div class="kc-survey-bar-fill" style="width:' . $pct . '%;"></div></div>';
                    echo '<div class="kc-survey-bar-count">' . $cnt . ' (' . $pctof . '%)</div>';
                    echo '</div>';
                }
                if ($totalanswered === 0) {
                    echo '<div class="kc-survey-q-noresp">No responses yet.</div>';
                }
            }

            echo html_writer::end_div(); // .kc-survey-q-body.
            echo html_writer::end_div(); // .kc-survey-q-block.
        }

        // ── Free-text questions: collected responses ──────────────────────────.
        $ftqs = array_filter(
            (array)$surveyqs,
            function ($q) {
                return $q->questiontype === 'freetext';
            }
        );
        if (!empty($ftqs)) {
            echo html_writer::start_div('kc-survey-freetext-section');
            echo '<h3>Open-Ended Responses</h3>';
            $ftqnum = 0;
            foreach ($surveyqs as $sq) {
                if ($sq->questiontype !== 'freetext') {
                    continue;
                }
                $ftqnum++;
                $responses = $freetextresponses[$sq->id];

                echo html_writer::start_div('kc-survey-ft-block');
                echo '<div class="kc-survey-ft-header">' .
                    '<span class="kc-survey-q-num" style="background:#f59e0b;">' . $ftqnum . '</span>' .
                    htmlspecialchars($sq->question, ENT_QUOTES) . ' <span style="font-weight:normal;color:#9ca3af;font-size:0.85em;">(' . count($responses) . ' ' . (count($responses) === 1 ? 'response' : 'responses') . ')</span>' .
                    '</div>';
                echo html_writer::start_div('kc-survey-ft-body');
                if (empty($responses)) {
                    echo '<div style="padding:12px 16px;color:#9ca3af;font-style:italic;font-size:0.88em;">No responses yet.</div>';
                } else {
                    foreach ($responses as $r) {
                        echo '<div class="kc-survey-ft-row">';
                        echo '<div class="kc-survey-ft-name">' . htmlspecialchars($r['name'], ENT_QUOTES) . '</div>';
                        echo '<div class="kc-survey-ft-text">' . htmlspecialchars($r['response'], ENT_QUOTES) . '</div>';
                        echo '<div class="kc-survey-ft-date">' . htmlspecialchars($r['date'], ENT_QUOTES) . '</div>';
                        echo '</div>';
                    }
                }
                echo html_writer::end_div(); // .kc-survey-ft-body.
                echo html_writer::end_div(); // .kc-survey-ft-block.
            }
            echo html_writer::end_div(); // .kc-survey-freetext-section.
        }

        // ── Per-student completion table ──────────────────────────────────────.
        echo html_writer::start_div('kc-survey-students');
        echo '<h3>Student Completions</h3>';
        echo '<table class="generaltable" style="width:auto;">';
        echo '<thead><tr><th>Student</th><th>Completed</th><th>Time Spent</th></tr></thead><tbody>';
        foreach ($studentrows as $sr) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($sr['name'], ENT_QUOTES) . '</td>';
            echo '<td>' . htmlspecialchars($sr['date'], ENT_QUOTES) . '</td>';
            echo '<td>' . htmlspecialchars($sr['spent'], ENT_QUOTES) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo html_writer::end_div(); // .kc-survey-students.
    }

    echo html_writer::end_div(); // .kc-survey-report.
    echo $OUTPUT->footer();
    die();
}

if (empty($byuser)) {
    echo html_writer::div(get_string('noattempts', 'mod_aiknowledgecheck'));
} else {
    // Inline styles for the accordion (avoids requiring a separate CSS file update on the server).
    echo html_writer::tag(
        'style',
        '
        .kc-report-accordion { border: 1px solid #dee2e6; border-radius: 4px; margin-bottom: 8px; overflow: hidden; }
        .kc-report-accordion summary {
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 16px; cursor: pointer; background: #f8f9fa;
            font-weight: 600; list-style: none; user-select: none;
        }
        .kc-report-accordion summary::-webkit-details-marker { display: none; }
        .kc-report-accordion summary:hover { background: #e9ecef; }
        .kc-report-accordion[open] summary { border-bottom: 1px solid #dee2e6; background: #fff; }
        .kc-report-accordion-meta { font-weight: normal; font-size: 0.875em; color: #6c757d; margin-left: 12px; }
        .kc-report-accordion-chevron { transition: transform 0.2s; font-size: 0.8em; color: #6c757d; }
        .kc-report-accordion[open] .kc-report-accordion-chevron { transform: rotate(180deg); }
        .kc-report-accordion-body { padding: 0; }
        .kc-report-accordion-body table { margin: 0; width: 100%; border-radius: 0; }
        .kc-report-accordion-body table thead th { background: #fff; border-top: 0; font-size: 0.82em; text-transform: uppercase; letter-spacing: 0.03em; color: #6c757d; padding: 8px 16px; }
        .kc-report-accordion-body table tbody td { padding: 8px 16px; vertical-align: middle; }
        .kc-report-summary-bar { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        .kc-report-summary-chip { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; padding: 4px 10px; font-size: 0.85em; color: #495057; }
    '
    );

    // Summary bar: total students + total attempts.
    $totalstudents = count($byuser);
    $totalattempts = array_sum(
        array_map(
            function ($u) {
                return count($u['attempts']);
            },
            $byuser
        )
    );
    echo html_writer::start_div('kc-report-summary-bar');
    echo html_writer::tag('span', $totalstudents . ' ' . ($totalstudents === 1 ? 'student' : 'students'), ['class' => 'kc-report-summary-chip']);
    echo html_writer::tag('span', $totalattempts . ' total ' . ($totalattempts === 1 ? 'attempt' : 'attempts'), ['class' => 'kc-report-summary-chip']);
    if ($totalqs > 0) {
        echo html_writer::tag('span', $totalqs . ' ' . ($totalqs === 1 ? 'question' : 'questions') . ' in quiz', ['class' => 'kc-report-summary-chip']);
    }
    echo html_writer::end_div();

    // Column headers (reused in each accordion table).
    $colheads = [
        get_string('attemptno', 'mod_aiknowledgecheck'),
        get_string('score', 'mod_aiknowledgecheck'),
        get_string('timestarted', 'mod_aiknowledgecheck'),
        get_string('timeended', 'mod_aiknowledgecheck'),
        $timespentlabel,
    ];

    // One accordion per student.
    foreach ($byuser as $uid => $udata) {
        $nattempts = count($udata['attempts']);

        // Best score label for the summary line — use pre-computed value.
        $bestscore = '';
        if ($totalqs > 0) {
            $bestscore = 'Best: ' . $udata['bestcorrect'] . '/' . $totalqs;
        }

        // Auto-open when filtered to a single user.
        $openattr = ($userid && (int)$userid === $uid) ? ['open' => 'open'] : [];

        echo html_writer::start_tag('details', array_merge(['class' => 'kc-report-accordion'], $openattr));

        // Summary row — use raw HTML to avoid html_writer::span() class-arg ambiguity.
        $metaparts = $nattempts . ' ' . ($nattempts === 1 ? 'attempt' : 'attempts');
        if ($bestscore) {
            $metaparts .= ' &nbsp;&middot;&nbsp; ' . htmlspecialchars($bestscore, ENT_QUOTES);
        }
        echo html_writer::start_tag('summary');
        echo '<span>' . htmlspecialchars($udata['name'], ENT_QUOTES) . '</span>';
        echo '<span class="kc-report-accordion-meta">' . $metaparts . '</span>';
        echo '<span class="kc-report-accordion-chevron">&#9660;</span>';
        echo html_writer::end_tag('summary');

        // Body — attempts table.
        echo html_writer::start_div('kc-report-accordion-body');
        echo '<table class="generaltable">';
        echo '<thead><tr>';
        foreach ($colheads as $h) {
            echo '<th>' . htmlspecialchars($h, ENT_QUOTES) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($udata['attempts'] as $att) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($att['attemptno'], ENT_QUOTES) . '</td>';
            echo '<td>' . htmlspecialchars($att['score'], ENT_QUOTES) . '</td>';
            echo '<td>' . htmlspecialchars($att['timestarted'], ENT_QUOTES) . '</td>';
            echo '<td>' . htmlspecialchars($att['timeended'], ENT_QUOTES) . '</td>';
            echo '<td>' . htmlspecialchars($att['timespent'], ENT_QUOTES) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo html_writer::end_div(); // .kc-report-accordion-body.
        echo html_writer::end_tag('details');
    }
}

echo $OUTPUT->footer();
