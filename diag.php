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
 * mod_aiknowledgecheck file.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

// AI Knowledge Check — Diagnostic Tool v1.5.112
// Access: /mod/aiknowledgecheck/diag.php?cmid=<cmid>
// Requires: site admin or moodle/site:config capability.
// Safe to ship — no data is modified and no external requests are made.
//
// ════════════════════════════════════════════════════════════════════════════
// DIAG COVERAGE RULE — Every diagnostic section must cover exactly one layer
// of the stack. When adding a new feature, a corresponding diag section MUST
// be added for each layer that feature touches. Layers are:
//
//  LAYER 1 — INPUT DATA (Section 7)
//      What the user provides: transcript, video URL, topic text.
//      Check: does the source data contain the fields the AI needs?
//      e.g. Does the transcript have timestamp markers for the AI to extract?
//
//  LAYER 2 — PHP → API PAYLOAD (Sections 1, 3)
//      What ajax.php sends to the SaaS /api endpoint.
//      Check: does the outbound payload include every required field?
//      e.g. Is showChapterStamps forwarded so the AI knows to include timestamps?
//
//  LAYER 3 — DB PERSISTENCE (Sections 2, 4)
//      What is stored in the DB after generation / regeneration.
//      Check: are the right values actually persisted?
//      e.g. Are timestamp_seconds values non-null in aiknowledgecheck_questions?
//
//  LAYER 4 — PHP → JS CONFIG (Section 5)
//      What view.php injects into the JS config object (M.util.js_init_call).
//      Check: does every setting the JS needs appear in the config object?
//      e.g. Are showChapterStamps and hasVideo both true?
//
//  LAYER 5 — JS MAPPER (Section 8)
//      The response.questions.map(...) transforms in knowledgecheck.js that
//      convert the ajax.php API response into the internal quizData array.
//      Check: does every mapper pass every field through to the return object?
//      e.g. Does each mapper include timestamp_seconds in its return {}?
//
//  LAYER 6 — JS RENDERER (Section 9)   ← THE BLIND SPOT FIXED HERE
//      The showQuestion() function that reads quizData and builds the DOM.
//      Check: does the renderer read the field and build the expected UI element?
//      e.g. Does showQuestion() check q.timestamp_seconds and build kc-chapter-stamp?
//
//  LAYER 7 — AMD BUILD (Section 6)
//      The compiled knowledgecheck.min.js served to the browser.
//      Check: does the built file actually contain the renderer code?
//      e.g. Is kc-chapter-stamp-btn present in the minified output?
//
// RULE: If a bug is found that "ALL CHECKS PASSED" didn't catch, a new check
// must be added to the appropriate layer section before the fix is released.
// A passing diag must mean the feature works end-to-end, not just that the
// data exists somewhere in the stack.
// ════════════════════════════════════════════════════════════════════════════

require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

// Accept either ?cmid=X or ?id=X (Moodle activity URLs use ?id=).
$cmid = optional_param('cmid', 0, PARAM_INT) ?: optional_param('id', 0, PARAM_INT);
if (!$cmid) {
    print_error('missingparam', 'error', '', 'cmid or id');
}

// Graceful cmid lookup — check if it's a course ID and offer a picker.
$cm_raw = get_coursemodule_from_id('aiknowledgecheck', $cmid, 0, false, IGNORE_MISSING);
if (!$cm_raw) {
    $css = '<style>body{font-family:sans-serif;margin:2rem;background:#f5f5f5;color:#111;}
h2{font-size:1.2rem;margin-bottom:.5rem;}
.box{background:#fff;border:1px solid #ddd;border-radius:6px;padding:1.2rem 1.5rem;max-width:750px;margin-bottom:1.5rem;}
.err{background:#f8d7da;color:#721c24;}.info{background:#d1ecf1;color:#0c5460;}
table{width:100%;border-collapse:collapse;margin-top:.5rem;}
td,th{padding:.4rem .7rem;font-size:.85rem;border-bottom:1px solid #eee;text-align:left;}
th{background:#f0f0f0;font-weight:600;}a{color:#0070f3;}code{background:#eee;padding:2px 5px;border-radius:3px;}</style>';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>KC Diag — Pick activity</title>' . $css . '</head><body>';
    echo '<h2>AI Knowledge Check Diagnostic — Choose an Activity</h2>';
    $course_check = $DB->get_record('course', ['id' => $cmid], 'id,fullname', IGNORE_MISSING);
    if ($course_check) {
        echo '<div class="box info"><p><strong>' . htmlspecialchars($course_check->fullname) . '</strong> (course id=' . $cmid . ') — that is a <strong>course</strong> id, not an activity id.</p><p>Pick a Knowledge Check activity from this course below:</p></div>';
        $cms = $DB->get_records_sql(
            "SELECT cm.id AS cmid, cm.instance FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module WHERE cm.course = :cid AND m.name = 'aiknowledgecheck' ORDER BY cm.id ASC",
            ['cid' => $cmid]
        );
        if ($cms) {
            echo '<div class="box"><table><tr><th>Activity</th><th>cmid</th><th>Action</th></tr>';
            foreach ($cms as $r) {
                $name = $DB->get_field('aiknowledgecheck', 'name', ['id' => $r->instance]);
                $url  = new moodle_url('/mod/aiknowledgecheck/diag.php', ['cmid' => $r->cmid]);
                echo '<tr><td>' . htmlspecialchars($name ?: '(unnamed)') . '</td><td>' . $r->cmid . '</td><td><a href="' . $url->out() . '">Run diag</a></td></tr>';
            }
            echo '</table></div>';
        } else {
            echo '<div class="box err"><p>No Knowledge Check activities found in this course.</p></div>';
        }
    } else {
        echo '<div class="box err"><p><strong>cmid=' . $cmid . ' is not a valid Knowledge Check activity or course id.</strong></p>';
        echo '<p>Navigate to a Knowledge Check activity in Moodle and copy the <code>id=</code> number from the URL.</p></div>';
    }
    echo '</body></html>';
    exit;
}

list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'aiknowledgecheck');
$knowledgecheck = $DB->get_record('aiknowledgecheck', ['id' => $cm->instance], '*', MUST_EXIST);

// ── Helpers ────────────────────────────────────────────────────────────────────

function diag_pass(string $label, string $value = ''): string {
    return '<tr><td class="label">' . htmlspecialchars($label) . '</td>'
         . '<td class="pass">PASS</td>'
         . '<td class="val">' . htmlspecialchars($value) . '</td></tr>';
}

function diag_fail(string $label, string $detail = ''): string {
    return '<tr><td class="label">' . htmlspecialchars($label) . '</td>'
         . '<td class="fail">FAIL</td>'
         . '<td class="val">' . htmlspecialchars($detail) . '</td></tr>';
}

function diag_info(string $label, string $value = ''): string {
    return '<tr><td class="label">' . htmlspecialchars($label) . '</td>'
         . '<td class="info">INFO</td>'
         . '<td class="val">' . htmlspecialchars($value) . '</td></tr>';
}

$rows_generate  = '';
$rows_regen     = '';
$rows_questions = '';
$rows_db        = '';
$rows_frontend  = '';
$rows_amd       = '';
$overall_pass   = true;

// ── Video URL parsing — same logic as view.php ─────────────────────────────────
// IMPORTANT: the DB column is 'videourl' (no separate 'videoid' column).
// We run the identical 4-regex block that view.php uses so config.hasVideo
// in the student page matches what we compute here.
$diag_videoid = '';
$diag_hasvideo = false;
if (!empty($knowledgecheck->videourl)) {
    $vurl = $knowledgecheck->videourl;
    if (preg_match('/[?&]v=([a-zA-Z0-9_-]{11})/', $vurl, $m)) {
        $diag_videoid = $m[1];
    } elseif (preg_match('/youtu\.be\/([a-zA-Z0-9_-]{11})/', $vurl, $m)) {
        $diag_videoid = $m[1];
    } elseif (preg_match('/embed\/([a-zA-Z0-9_-]{11})/', $vurl, $m)) {
        $diag_videoid = $m[1];
    } elseif (preg_match('/shorts\/([a-zA-Z0-9_-]{11})/', $vurl, $m)) {
        $diag_videoid = $m[1];
    }
    $diag_hasvideo = !empty($diag_videoid);
}

// ── SECTION 1: Generate payload fields ────────────────────────────────────────

// showChapterStamps (FIX-KC-TIMESTAMP-GENERATE)
$showstamps = isset($knowledgecheck->showchapterstamps) ? (int)$knowledgecheck->showchapterstamps : null;
if ($showstamps === null) {
    $rows_generate .= diag_fail(
        'showChapterStamps in generate payload',
        'Column showchapterstamps missing from DB record — upgrade may not have run'
    );
    $overall_pass = false;
} elseif ($showstamps === 0) {
    $rows_generate .= diag_info(
        'showChapterStamps in generate payload',
        'Value = 0 (setting is OFF for this activity) — timestamps correctly disabled'
    );
} else {
    $rows_generate .= diag_pass(
        'showChapterStamps in generate payload',
        'Value = ' . $showstamps . ' — will be forwarded to API'
    );
}

// Core education fields (already in generate since v1.5.90+, just confirming presence)
$fields_generate = ['educationtype' => 'educationType', 'vetlevel' => 'vetLevel', 'academiclevel' => 'academicLevel'];
foreach ($fields_generate as $col => $label) {
    $val = isset($knowledgecheck->$col) ? $knowledgecheck->$col : null;
    if ($val === null) {
        $rows_generate .= diag_info("$label in generate payload", 'Column absent — likely empty/null');
    } else {
        $rows_generate .= diag_pass("$label in generate payload", '"' . $val . '"');
    }
}

// ── SECTION 2: sourcecontext blob ─────────────────────────────────────────────

$sourcecontext_raw = isset($knowledgecheck->sourcecontext) ? $knowledgecheck->sourcecontext : null;
if ($sourcecontext_raw === null || $sourcecontext_raw === '') {
    $rows_db .= diag_fail(
        'sourcecontext column',
        'Empty or missing — generate has not been run since v1.5.95, or DB upgrade pending. '
      . 'Regeneration will fall back to old (no-context) behaviour.'
    );
    $overall_pass = false;
    $sc = null;
} else {
    $sc = json_decode($sourcecontext_raw, true);
    if (!is_array($sc)) {
        $rows_db .= diag_fail('sourcecontext column', 'Present but JSON is invalid: ' . $sourcecontext_raw);
        $overall_pass = false;
        $sc = null;
    } else {
        $rows_db .= diag_pass('sourcecontext column', 'Valid JSON (' . count($sc) . ' keys)');

        // Check showChapterStamps persisted into sourcecontext (FIX-KC-TIMESTAMP-GENERATE)
        if (array_key_exists('showChapterStamps', $sc)) {
            $rows_db .= diag_pass('showChapterStamps persisted in sourcecontext', 'Value = ' . $sc['showChapterStamps']);
        } else {
            $rows_db .= diag_fail(
                'showChapterStamps persisted in sourcecontext',
                'Key absent — generate was run on pre-v1.5.97. Regeneration cannot forward the timestamp flag until generate is run again.'
            );
            $overall_pass = false;
        }

        // Check educationType persisted
        foreach (['educationType', 'vetLevel', 'academicLevel'] as $key) {
            if (array_key_exists($key, $sc)) {
                $rows_db .= diag_pass("$key persisted in sourcecontext", '"' . $sc[$key] . '"');
            } else {
                $rows_db .= diag_info("$key persisted in sourcecontext", 'Key absent (pre-v1.5.95 sourcecontext — will use regen defaults)');
            }
        }

        // FIX-KC-TEXTSOURCES-DIAG (v1.5.108): Check textSources field shape in sourcecontext.
        // The server reads s.text (not s.content) — using the wrong field name caused the
        // FALLBACK-TIMESTAMPS-REGEN block to always receive an empty transcript string and
        // never assign timestamps. These checks expose that bug in the data layer.
        if (!array_key_exists('textSources', $sc) || !is_array($sc['textSources']) || count($sc['textSources']) === 0) {
            $rows_db .= diag_info(
                'textSources in sourcecontext',
                'No textSources stored (activity was generated from topics/questions, not a pasted transcript). '
              . 'FALLBACK-TIMESTAMPS-REGEN cannot run — timestamps must come from the AI directly.'
            );
        } else {
            $ts_count    = count($sc['textSources']);
            $first_src   = $sc['textSources'][0];
            $has_text    = array_key_exists('text', $first_src);
            $has_content = array_key_exists('content', $first_src);

            if ($has_text) {
                $combined_len = 0;
                $ts_marker_count = 0;
                foreach ($sc['textSources'] as $src) {
                    $t = $src['text'] ?? '';
                    $combined_len += strlen($t);
                    // Count YouTube-style timestamp markers: 0:08, 1:09, 12:34, 1:23:45
                    preg_match_all('/\b\d{1,2}:\d{2}(?::\d{2})?\b/', $t, $ts_matches);
                    $ts_marker_count += count($ts_matches[0]);
                }
                $rows_db .= diag_pass(
                    'textSources field name',
                    $ts_count . ' source(s) — field is "text" (correct). '
                  . 'Combined length: ' . $combined_len . ' chars. '
                  . 'Timestamp markers found: ' . $ts_marker_count . '.'
                );
                if ($ts_marker_count === 0) {
                    $rows_db .= diag_fail(
                        'Timestamp markers in textSources[].text',
                        'Zero YouTube-style timestamps found (e.g. "0:08", "1:09") in the stored transcript. '
                      . 'FALLBACK-TIMESTAMPS-REGEN will find no segments to match questions against. '
                      . 'Re-paste a timestamped transcript (copy from YouTube transcript panel) and run Generate.'
                    );
                    $overall_pass = false;
                } else {
                    $rows_db .= diag_pass(
                        'Timestamp markers in textSources[].text',
                        $ts_marker_count . ' marker(s) found — FALLBACK-TIMESTAMPS-REGEN has segments to work with.'
                    );
                }
            } elseif ($has_content) {
                $rows_db .= diag_fail(
                    'textSources field name',
                    $ts_count . ' source(s) — field is "content" (WRONG). '
                  . 'The server reads "s.text". This mismatch caused FALLBACK-TIMESTAMPS-REGEN to '
                  . 'always see an empty transcript and skip timestamp assignment. '
                  . 'FIX-KC-TEXTSOURCES-FIELD (v1.5.108) corrects the server side. '
                  . 'The stored sourcecontext was written by an older PHP build — run Generate once to re-save with correct shape.'
                );
                $overall_pass = false;
            } else {
                $rows_db .= diag_fail(
                    'textSources field name',
                    $ts_count . ' source(s) — first item has neither "text" nor "content" field. Keys: '
                  . implode(', ', array_keys($first_src)) . '. Cannot determine transcript content.'
                );
                $overall_pass = false;
            }
        }
    }
}

// ── SECTION 3: Simulated regenerateinstructions payload ───────────────────────

if ($sc !== null) {
    // Simulate what FIX-KC-REGEN-EDLEVEL now sends
    $educationtype_regen = $sc['educationType'] ?? 'vet';
    $vetlevel_regen      = $sc['vetLevel']       ?? 'cert3';
    $academiclevel_regen = $sc['academicLevel']  ?? '';
    $showstamps_regen    = $sc['showChapterStamps'] ?? 0;

    $rows_regen .= diag_pass('educationType (top-level)', '"' . $educationtype_regen . '"');
    $rows_regen .= diag_pass('vetLevel (top-level)',      '"' . $vetlevel_regen . '"');
    $rows_regen .= diag_pass('academicLevel (top-level)', '"' . $academiclevel_regen . '"');

    if (array_key_exists('showChapterStamps', $sc)) {
        $rows_regen .= diag_pass('showChapterStamps (top-level)', 'Value = ' . $showstamps_regen);
    } else {
        $rows_regen .= diag_fail(
            'showChapterStamps (top-level)',
            'Will default to 0 because sourcecontext pre-dates v1.5.97. Run generate once to fix.'
        );
        $overall_pass = false;
    }

    // FIX-KC-TEXTSOURCES-DIAG (v1.5.108): Simulate FALLBACK-TIMESTAMPS-REGEN path.
    // After FIX-KC-TIMESTAMP-REGEN (v1.5.97) copies originals, any question that was
    // ORIGINALLY null still needs server-side extraction from the transcript.
    // The server reads kcSourceContext.textSources[].text to build transcriptText,
    // then runs parseTranscriptSegments + findBestTimestampForQuestion.
    // Previously the server mistakenly read s.content (always empty) — fixed v1.5.108.
    $rows_regen .= diag_info(
        'FALLBACK-TIMESTAMPS-REGEN path',
        'After the AI regenerates questions, the server checks: does any question still have '
      . 'timestamp_seconds = null? If so it reads textSources[].text (the stored transcript), '
      . 'runs parseTranscriptSegments, and calls findBestTimestampForQuestion for each null question. '
      . 'v1.5.108 fixes the field-name bug (s.content → s.text) that always short-circuited this path.'
    );

    $sc_textsources = $sc['textSources'] ?? [];
    if (empty($sc_textsources) || !is_array($sc_textsources)) {
        $rows_regen .= diag_info(
            'FALLBACK-TIMESTAMPS-REGEN: textSources available',
            'No textSources in sourcecontext — fallback cannot run (generate used topics/questions not transcript). '
          . 'Timestamps must come directly from AI prompt output for this activity type.'
        );
    } else {
        $fallback_transcript = '';
        foreach ($sc_textsources as $src) {
            $t = $src['text'] ?? ($src['content'] ?? '');
            if (trim($t) !== '') {
                $fallback_transcript .= "\n\n" . trim($t);
            }
        }
        $fallback_transcript = trim($fallback_transcript);
        $fallback_len = strlen($fallback_transcript);
        preg_match_all('/\b\d{1,2}:\d{2}(?::\d{2})?\b/', $fallback_transcript, $fb_ts_m);
        $fallback_ts_markers = count($fb_ts_m[0]);

        if ($fallback_len === 0) {
            $rows_regen .= diag_fail(
                'FALLBACK-TIMESTAMPS-REGEN: transcript content',
                'Combined textSources transcript is empty after reading "text" field. '
              . 'Check Section 2 — the stored sourcecontext may use "content" (old PHP build) instead of "text". '
              . 'Run Generate once to re-save sourcecontext with the correct shape.'
            );
            $overall_pass = false;
        } elseif ($fallback_ts_markers === 0) {
            $rows_regen .= diag_fail(
                'FALLBACK-TIMESTAMPS-REGEN: transcript content',
                $fallback_len . ' chars available but ZERO timestamp markers found (e.g. "0:08"). '
              . 'parseTranscriptSegments will return 0 segments — fallback cannot assign timestamps. '
              . 'Paste a timestamped transcript (copy from YouTube transcript panel) and run Generate.'
            );
            $overall_pass = false;
        } else {
            $rows_regen .= diag_pass(
                'FALLBACK-TIMESTAMPS-REGEN: transcript content',
                $fallback_len . ' chars, ' . $fallback_ts_markers . ' timestamp marker(s). '
              . 'After v1.5.108 fix the fallback WILL run and assign timestamps to any null questions on regenerate.'
            );
        }
    }
} else {
    $rows_regen .= diag_fail('regenerateinstructions payload', 'Cannot simulate — sourcecontext is empty (see Section 2)');
    $overall_pass = false;
}

// ── SECTION 4: Stored questions — timestamp_seconds check ─────────────────────
// FIX-KC-DIAG-TABLE (v1.5.101): Read from aiknowledgecheck_questions table,
// NOT from $knowledgecheck->questions (that column does not exist on the main
// aiknowledgecheck table — questions are stored in a separate table).

$db_questions = $DB->get_records('aiknowledgecheck_questions',
    ['aiknowledgecheckid' => $knowledgecheck->id],
    'questionnumber ASC'
);

if (empty($db_questions)) {
    $rows_questions .= diag_info('Stored questions', 'No questions generated yet for this activity');
} else {
    $total   = count($db_questions);
    $with_ts = 0;
    $null_ts = 0;
    foreach ($db_questions as $q) {
        if (isset($q->timestamp_seconds) && $q->timestamp_seconds !== null) {
            $with_ts++;
        } else {
            $null_ts++;
        }
    }

    $hasvideo = $diag_hasvideo;

    $rows_questions .= diag_info(
        'Stored questions',
        "$total questions found in aiknowledgecheck_questions table"
    );

    if (!$hasvideo) {
        $rows_questions .= diag_info(
            'timestamp_seconds on stored questions',
            "Activity has no video — timestamps not applicable ($total questions stored)"
        );
    } elseif ($showstamps === 0) {
        $rows_questions .= diag_info(
            'timestamp_seconds on stored questions',
            "showChapterStamps is OFF — timestamps intentionally absent ($total questions)"
        );
    } elseif ($with_ts === $total) {
        $rows_questions .= diag_pass(
            'timestamp_seconds on stored questions',
            "All $total questions have timestamp_seconds — timestamps working correctly"
        );
    } elseif ($with_ts > 0) {
        $rows_questions .= diag_info(
            'timestamp_seconds on stored questions',
            "$with_ts / $total questions have timestamp_seconds. The $null_ts null values may have been generated without timestamp markers in the transcript. Regenerate with a timestamped transcript to fix."
        );
    } else {
        $rows_questions .= diag_fail(
            'timestamp_seconds on stored questions',
            "All $total questions have timestamp_seconds = null. The AI did not find YouTube-style timestamps (e.g. 0:08, 1:09, 2:36) in the pasted transcript. "
          . "Make sure the transcript includes time markers — copy it from YouTube's transcript panel (click '...' → 'Show transcript' under the video) which includes timestamps like '0:08 Hello and welcome...'."
        );
        $overall_pass = false;
    }

    // Show per-question details
    $qi = 0;
    foreach ($db_questions as $q) {
        $qi++;
        if ($qi > 5) {
            $rows_questions .= diag_info('...', ($total - 5) . ' more questions not shown');
            break;
        }
        $ts  = isset($q->timestamp_seconds) && $q->timestamp_seconds !== null
             ? $q->timestamp_seconds . 's (' . gmdate('i:s', (int)$q->timestamp_seconds) . ')'
             : 'NULL';
        $txt = mb_substr($q->questiontext, 0, 80);
        $rows_questions .= diag_info('Q' . $qi . ' timestamp_seconds', $ts . ' | "' . $txt . '..."');
    }
}

// ── SECTION 5: Frontend config simulation ─────────────────────────────────────
// Reproduces exactly what view.php puts into the JS config object and evaluates
// the three-part JS condition that controls timestamp link visibility:
//   if (config.showChapterStamps && q.timestamp_seconds != null && config.hasVideo)
// A FAIL on any part tells you exactly why timestamps are invisible.

$rows_frontend .= diag_info(
    'What this checks',
    'Simulates the config object passed to knowledgecheck.js. '
  . 'Timestamp links show only when ALL THREE are true: '
  . '(1) config.showChapterStamps=1  (2) q.timestamp_seconds != null  (3) config.hasVideo=1.'
);

// --- Condition 1: config.showChapterStamps ---
$cfg_showstamps = isset($knowledgecheck->showchapterstamps) ? (int)$knowledgecheck->showchapterstamps : 0;
if ($cfg_showstamps) {
    $rows_frontend .= diag_pass('Condition 1: config.showChapterStamps', 'Value = ' . $cfg_showstamps . ' → TRUE');
} else {
    $rows_frontend .= diag_fail(
        'Condition 1: config.showChapterStamps',
        'Value = 0 → FALSE. The "Show chapter timestamp links" setting is OFF for this activity. '
      . 'Edit the activity settings and enable it, then run Generate again.'
    );
    $overall_pass = false;
}

// --- Condition 2: q.timestamp_seconds != null ---
// FIX-KC-DIAG-TABLE (v1.5.101): Read from aiknowledgecheck_questions table.
$cond2_pass = false;
$cond2_detail = '';
if (!empty($db_questions)) {
    $first_q = reset($db_questions);
    $ts_val  = isset($first_q->timestamp_seconds) ? $first_q->timestamp_seconds : null;
    if ($ts_val !== null) {
        $cond2_pass = true;
        $cond2_detail = 'Q1 timestamp_seconds = ' . $ts_val . ' → TRUE (representative sample)';
    } else {
        $cond2_detail = 'Q1 timestamp_seconds = NULL → FALSE. The AI did not assign timestamps. '
                      . 'Ensure the pasted transcript includes YouTube-style time markers (e.g. "0:08 Hello..."). '
                      . 'Copy the transcript from YouTube\'s transcript panel, then regenerate.';
    }
} else {
    $cond2_detail = 'No questions stored yet → FALSE (generate first)';
}
if ($cond2_pass) {
    $rows_frontend .= diag_pass('Condition 2: q.timestamp_seconds != null', $cond2_detail);
} else {
    $rows_frontend .= diag_fail('Condition 2: q.timestamp_seconds != null', $cond2_detail);
    $overall_pass = false;
}

// --- Condition 3: config.hasVideo ---
$rows_frontend .= diag_info(
    'videourl in DB',
    !empty($knowledgecheck->videourl) ? htmlspecialchars($knowledgecheck->videourl) : '(empty — no video URL set)'
);
if ($diag_hasvideo) {
    $rows_frontend .= diag_pass(
        'Condition 3: config.hasVideo',
        'Extracted videoid = ' . htmlspecialchars($diag_videoid) . ' → TRUE'
    );
} else {
    if (empty($knowledgecheck->videourl)) {
        $rows_frontend .= diag_fail(
            'Condition 3: config.hasVideo',
            'videourl is empty — no video URL is set for this activity. '
          . 'Timestamps require a YouTube video. Add the video URL in activity settings.'
        );
    } else {
        $rows_frontend .= diag_fail(
            'Condition 3: config.hasVideo',
            'videourl = "' . htmlspecialchars($knowledgecheck->videourl) . '" '
          . 'but no YouTube video ID could be extracted. '
          . 'Supported formats: youtube.com/watch?v=ID  |  youtu.be/ID  |  /embed/ID  |  /shorts/ID. '
          . 'Check the URL format in activity settings.'
        );
    }
    $overall_pass = false;
}

// --- Overall verdict ---
if ($cfg_showstamps && $cond2_pass && $diag_hasvideo) {
    $rows_frontend .= diag_pass(
        'Timestamp link condition (all 3)',
        'ALL THREE conditions true — timestamp "Jump to X:XX" links SHOULD be visible. '
      . 'If they still do not appear, see Section 6 (AMD build check).'
    );
} else {
    $rows_frontend .= diag_fail(
        'Timestamp link condition (all 3)',
        'At least one condition is FALSE — timestamp links will NOT appear. Fix the FAIL rows above.'
    );
}

// ── SECTION 6: AMD build — does knowledgecheck.min.js contain timestamp code? ──
// The built JS must contain the kc-chapter-stamp code added in v1.5.97.
// If the build is stale (pre-v1.5.97 build shipped), stamps will never appear
// even if DB data is correct, because the rendering code isn't there.

$rows_amd .= diag_info(
    'What this checks',
    'The minified AMD build (knowledgecheck.min.js) must contain the "kc-chapter-stamp" '
  . 'button code from v1.5.97+. If the build is absent or stale, timestamp links '
  . 'will never appear regardless of DB/config state.'
);

$build_path = __DIR__ . '/amd/build/knowledgecheck.min.js';
$src_path   = __DIR__ . '/amd/src/knowledgecheck.js';

if (!file_exists($build_path)) {
    $rows_amd .= diag_fail('knowledgecheck.min.js exists', 'File not found at: ' . $build_path);
    $overall_pass = false;
} else {
    $build_content = file_get_contents($build_path);
    $rows_amd .= diag_pass('knowledgecheck.min.js exists', 'Size: ' . number_format(strlen($build_content)) . ' bytes');

    // Check for the unique stamp button ID added in v1.5.97.
    if (strpos($build_content, 'kc-chapter-stamp') !== false) {
        $rows_amd .= diag_pass(
            'kc-chapter-stamp in build',
            '"kc-chapter-stamp" found in minified build — timestamp link rendering code present (v1.5.97+)'
        );
    } else {
        $rows_amd .= diag_fail(
            'kc-chapter-stamp in build',
            '"kc-chapter-stamp" NOT found in minified build — AMD build is pre-v1.5.97. '
          . 'The timestamp link code exists in src but has not been compiled into the build file. '
          . 'Plugin upgrade may not have replaced the old build. Re-install or manually copy '
          . 'amd/build/knowledgecheck.min.js from the new ZIP.'
        );
        $overall_pass = false;
    }

    // Check for the three-condition guard itself.
    if (strpos($build_content, 'showChapterStamps') !== false) {
        $rows_amd .= diag_pass(
            'showChapterStamps guard in build',
            '"showChapterStamps" found in minified build — condition gate present'
        );
    } else {
        $rows_amd .= diag_fail(
            'showChapterStamps guard in build',
            '"showChapterStamps" NOT found — build predates the feature entirely'
        );
        $overall_pass = false;
    }
}

// Check src for completeness reference.
if (file_exists($src_path)) {
    $src_content = file_get_contents($src_path);
    $src_has_stamp = strpos($src_content, 'kc-chapter-stamp') !== false;
    $rows_amd .= $src_has_stamp
        ? diag_pass('kc-chapter-stamp in src/knowledgecheck.js', 'Present in source — build should match')
        : diag_fail('kc-chapter-stamp in src/knowledgecheck.js', 'Missing from source — code not written yet');
} else {
    $rows_amd .= diag_info('src/knowledgecheck.js', 'Not found at: ' . $src_path);
}

// ── SECTION 7: Transcript content — timestamp marker check ─────────────────────
// Checks the sourcecontext blob for the text source content that was sent to the
// AI. If the pasted transcript contains no YouTube-style time markers, the AI
// will set timestamp_seconds=null for every question because the prompt instructs
// it to do so when no timestamps are found in the source content.

$rows_transcript = '';
$rows_transcript .= diag_info(
    'What this checks',
    'Inspects the pasted transcript (from sourcecontext) for YouTube-style timestamp markers '
  . '(e.g. "0:08", "1:09", "12:36"). If the transcript has no time markers, the AI sets '
  . 'timestamp_seconds=null for every question — and timestamps will never appear.'
);

$sc_raw = isset($knowledgecheck->sourcecontext) ? $knowledgecheck->sourcecontext : null;
if (empty($sc_raw)) {
    $rows_transcript .= diag_info('sourcecontext', 'Empty — no source data saved (pre-v1.5.95 activity or not yet generated)');
} else {
    $sc = json_decode($sc_raw, true);
    if (!is_array($sc)) {
        $rows_transcript .= diag_fail('sourcecontext JSON', 'Cannot parse');
    } else {
        $has_text_sources = false;
        $transcript_text  = '';

        if (!empty($sc['textSources']) && is_array($sc['textSources'])) {
            $has_text_sources = true;
            foreach ($sc['textSources'] as $ts_src) {
                if (isset($ts_src['text'])) {
                    $transcript_text .= $ts_src['text'] . "\n";
                }
            }
        }

        if (!$has_text_sources) {
            $rows_transcript .= diag_info('Text sources in sourcecontext', 'No textSources found — questions were generated from topics or PDFs, not pasted content');
        } else {
            $char_count = mb_strlen($transcript_text);
            $rows_transcript .= diag_info('Pasted content size', $char_count . ' characters across ' . count($sc['textSources']) . ' text source(s)');

            // Check for YouTube-style timestamps: patterns like "0:08", "1:09", "12:36"
            $timestamp_pattern = '/\b\d{1,2}:\d{2}\b/';
            preg_match_all($timestamp_pattern, $transcript_text, $matches);
            $found_timestamps = count($matches[0]);

            if ($found_timestamps > 0) {
                $sample = array_slice($matches[0], 0, 5);
                $rows_transcript .= diag_pass(
                    'YouTube-style timestamps in transcript',
                    "Found $found_timestamps timestamp markers. Sample: " . implode(', ', $sample)
                  . ". The AI should be assigning timestamp_seconds to questions."
                );
            } else {
                $rows_transcript .= diag_fail(
                    'YouTube-style timestamps in transcript',
                    "NO timestamp markers found (e.g. 0:08, 1:09, 2:36). "
                  . "The AI prompt says: 'If no timestamps are present in the source content, set timestamp_seconds to null.' "
                  . "This is why all questions have timestamp_seconds = null. "
                  . "FIX: Copy the transcript from YouTube's built-in transcript panel (click '...' → 'Show transcript' below the video). "
                  . "That format includes time markers like '0:08 Hello and welcome to...'. Paste THAT version, then regenerate."
                );
                $overall_pass = false;
            }

            // Show first 200 chars of transcript for visual inspection
            $preview = mb_substr(trim($transcript_text), 0, 200);
            $rows_transcript .= diag_info('Transcript preview (first 200 chars)', '"' . $preview . '..."');
        }
    }
}

// ── SECTION 8: JS mapper integrity — timestamp_seconds pass-through check ────
// FIX-KC-MAPPER-TIMESTAMP (v1.5.102): The root cause of timestamps not appearing
// was NOT the DB, config, or build — it was the JS quizData mappers silently
// stripping timestamp_seconds from the API response before it reached showQuestion().
// This section reads the actual knowledgecheck.js source and verifies that every
// quizData mapper (teacher getquestions, student getquestions, settings-regen,
// regenerate-instructions) includes timestamp_seconds in its return object.

$rows_mapper = '';
$rows_mapper .= diag_info(
    'What this checks',
    'Reads knowledgecheck.js source code and verifies that every quizData = response.questions.map(...) '
  . 'mapper includes timestamp_seconds in its return object. If any mapper strips this field, '
  . 'the "Jump to X:XX" button will never render even though the DB and config are correct.'
);

if (file_exists($src_path)) {
    $mapper_src = file_get_contents($src_path);

    // Identify all quizData mapper blocks by finding "return {" objects inside .map(function (q)
    // We check for specific known mapper patterns and verify each includes timestamp_seconds.
    $mapper_checks = [
        'Teacher getquestions mapper' => [
            'marker' => "action: 'getquestions'",
            'context' => 'correctAnswer: q.correctIndex,',
            'desc' => 'Teacher path — loads questions for editing'
        ],
        'Student getquestions mapper' => [
            'marker' => 'getShuffledIndices(4)',
            'context' => 'shuffledToOriginal:',
            'desc' => 'Student path — loads questions for quiz attempt with answer shuffling'
        ],
        'Settings-regen mapper' => [
            'marker' => 'applySettingsQuestions',
            'context' => 'isObjOpts',
            'desc' => 'Settings regeneration path — rebuilds quizData after regen-with-settings'
        ],
    ];

    $mapper_all_ok = true;
    foreach ($mapper_checks as $name => $check) {
        // Step 1: find the primary marker (unique to this mapper's code region)
        $marker_pos = strpos($mapper_src, $check['marker']);
        if ($marker_pos === false) {
            $rows_mapper .= diag_info($name, 'Marker not found in source — code structure may have changed');
            continue;
        }

        // Step 2: find the context marker AFTER the primary marker (not from file start)
        // This prevents false matches when the same context word appears in earlier mappers.
        $context_pos = strpos($mapper_src, $check['context'], $marker_pos);
        if ($context_pos === false) {
            $rows_mapper .= diag_info($name, 'Context marker "' . htmlspecialchars($check['context']) . '" not found after primary marker');
            continue;
        }

        // Step 3: check a 600-char window after the context marker for timestamp_seconds
        $region = substr($mapper_src, $context_pos, 600);
        if (strpos($region, 'timestamp_seconds') !== false) {
            $rows_mapper .= diag_pass($name, $check['desc'] . ' — timestamp_seconds PRESENT in return object');
        } else {
            $rows_mapper .= diag_fail(
                $name,
                $check['desc'] . ' — timestamp_seconds MISSING from return object! '
              . 'The server sends this field but the mapper strips it. '
              . 'q.timestamp_seconds will be undefined → showQuestion() condition always false → no Jump-to button.'
            );
            $overall_pass = false;
            $mapper_all_ok = false;
        }
    }

    // Also check the regenerate-instructions mapper (known good — verify it stays good)
    $regen_marker_pos = strpos($mapper_src, 'preservedTs');
    if ($regen_marker_pos !== false) {
        $regen_region = substr($mapper_src, $regen_marker_pos, 500);
        if (strpos($regen_region, 'timestamp_seconds') !== false) {
            $rows_mapper .= diag_pass('Regenerate-instructions mapper', 'Regeneration path — timestamp_seconds PRESENT (preservedTs pattern)');
        } else {
            $rows_mapper .= diag_fail('Regenerate-instructions mapper', 'timestamp_seconds MISSING from regeneration mapper');
            $overall_pass = false;
            $mapper_all_ok = false;
        }
    } else {
        $rows_mapper .= diag_info('Regenerate-instructions mapper', 'preservedTs pattern not found — code structure may have changed');
    }

    if ($mapper_all_ok) {
        $rows_mapper .= diag_pass('Overall mapper check', 'All quizData mappers pass timestamp_seconds through correctly');
    }
} else {
    $rows_mapper .= diag_info('Source file', 'knowledgecheck.js source not found at: ' . $src_path . ' — cannot verify mappers');
}

// ── SECTION 9: JS renderer — showQuestion() reads and renders timestamp_seconds ─
// LAYER 6 of the DIAG COVERAGE RULE.
// Verifies that showQuestion() in knowledgecheck.js:
//   (a) defines the function at all
//   (b) cleans up any previous stamp element before rendering (#kc-chapter-stamp)
//   (c) applies the correct three-condition gate before building the button
//       (config.showChapterStamps && q.timestamp_seconds != null && config.hasVideo)
//   (d) actually builds an element with the kc-chapter-stamp-btn class
//   (e) labels it "Jump to" so the user sees the expected text
// If any of these patterns are missing, the button will silently not render
// even though every upstream layer (DB, config, mapper) is correct.

$rows_renderer = '';
$rows_renderer .= diag_info(
    'What this checks',
    'Reads knowledgecheck.js source and verifies that showQuestion() contains the '
  . 'expected timestamp rendering logic — that it checks the three-condition gate '
  . '(config.showChapterStamps, q.timestamp_seconds != null, config.hasVideo) and '
  . 'builds a kc-chapter-stamp-btn button with "Jump to" label. '
  . 'This is LAYER 6 of the diag coverage rule: the JS renderer layer. '
  . 'Even if the mapper (Layer 5) passes timestamp_seconds correctly, a broken '
  . 'renderer means the button is never built.'
);

$renderer_checks = [
    'showQuestion() function defined' => [
        'pattern' => 'function showQuestion()',
        'fail_msg' => 'showQuestion() function not found — the quiz renderer does not exist. '
                    . 'The entire question display is broken, not just timestamps.',
    ],
    'Stamp cleanup before render' => [
        'pattern' => "('#kc-chapter-stamp').remove()",
        'fail_msg' => 'The stamp element cleanup call is missing. Old stamps from previous questions '
                    . 'may stack up in the DOM, or the render block has been restructured.',
    ],
    'Three-condition visibility gate' => [
        'pattern' => 'config.showChapterStamps && q.timestamp_seconds != null && config.hasVideo',
        'fail_msg' => 'The three-condition gate is missing or changed. The renderer is not checking '
                    . 'all three required conditions before building the Jump-to button. '
                    . 'Expected: config.showChapterStamps && q.timestamp_seconds != null && config.hasVideo',
    ],
    'kc-chapter-stamp-btn element built' => [
        'pattern' => 'kc-chapter-stamp-btn',
        'fail_msg' => 'The kc-chapter-stamp-btn class is missing from the renderer. '
                    . 'The button element is not being constructed — it will never appear in the DOM.',
    ],
    '"Jump to" button label present' => [
        'pattern' => 'Jump to',
        'fail_msg' => '"Jump to" label text not found. The button may exist but show no meaningful label, '
                    . 'or the button construction block has been refactored.',
    ],
];

$renderer_all_ok = true;
if (file_exists($src_path)) {
    $renderer_src = file_get_contents($src_path);

    foreach ($renderer_checks as $name => $check) {
        if (strpos($renderer_src, $check['pattern']) !== false) {
            $rows_renderer .= diag_pass($name, 'Pattern found: <code>' . htmlspecialchars($check['pattern']) . '</code>');
        } else {
            $rows_renderer .= diag_fail($name, $check['fail_msg']);
            $overall_pass = false;
            $renderer_all_ok = false;
        }
    }

    // Extra check: confirm the three-condition gate is INSIDE showQuestion(), not elsewhere.
    $show_q_pos = strpos($renderer_src, 'function showQuestion()');
    $next_func_pos = strpos($renderer_src, "\n    function ", $show_q_pos + 20);
    if ($show_q_pos !== false && $next_func_pos !== false) {
        $show_q_body = substr($renderer_src, $show_q_pos, $next_func_pos - $show_q_pos);
        if (strpos($show_q_body, 'config.showChapterStamps && q.timestamp_seconds != null') !== false) {
            $rows_renderer .= diag_pass('Gate is inside showQuestion()', 'The visibility gate is confirmed to be within the showQuestion() function body');
        } else {
            $rows_renderer .= diag_fail(
                'Gate is inside showQuestion()',
                'The three-condition gate exists in the file but NOT inside showQuestion() — '
              . 'it may have been moved to a helper function or another path, meaning the main '
              . 'quiz question display path does not trigger it.'
            );
            $overall_pass = false;
            $renderer_all_ok = false;
        }
    } else {
        $rows_renderer .= diag_info('Gate placement check', 'Could not isolate showQuestion() body to verify gate placement');
    }

    if ($renderer_all_ok) {
        $rows_renderer .= diag_pass('Overall renderer check', 'showQuestion() correctly reads timestamp_seconds and builds the Jump-to button');
    }
} else {
    $rows_renderer .= diag_info('Source file', 'knowledgecheck.js not found at: ' . $src_path . ' — cannot verify renderer');
}

// ── SECTION 10: Timestamp accuracy — segment alignment analysis ──────────────
// FIX-KC-TIMESTAMP-ACCURACY (v1.5.112): Sections 1–9 prove timestamps EXIST and
// are wired up correctly. This section proves they point to the RIGHT place.
// Covers the "timestamps feel too far forward/backward" student complaint by
// detecting seven distinct accuracy failure modes in the stored data:
//
//  (A) TRANSCRIPT FORMAT MIX  — Transcript contains both MM:SS and HH:MM:SS
//      markers. The two-group regex (\d{1,2}:\d{2}) that many parsers use will
//      silently parse "1:23:45" as "1:23" (83 s) instead of "5025 s", making
//      every HH:MM:SS timestamp ~5000 s too early.
//
//  (B) NON-ASCENDING ORDER  — Q3 has an earlier timestamp than Q2. Timestamps
//      should climb monotonically through the video as questions cover later
//      material. Out-of-order values indicate the AI or fallback matched
//      questions to wrong segments.
//
//  (C) DUPLICATE TIMESTAMPS  — Multiple questions share an identical
//      timestamp_seconds. The AI couldn't find distinct segments so it
//      pinned several questions to the same point. One of them will be correct
//      and the rest will be wrong by the gap to the next distinct segment.
//
//  (D) SYSTEMATIC OFFSET  — The median gap between consecutive timestamps is
//      less than 5 s or greater than half the total video span. This pattern
//      appears when the AI always picks the segment BEFORE or AFTER the
//      relevant one — a consistent forward/backward drift.
//
//  (E) QUESTION ↔ SEGMENT TEXT MISMATCH  — For each question, find which
//      transcript segment bracket contains its timestamp and measure word-level
//      overlap between the question text and the segment text. An overlap
//      score below 10 % on a question whose transcript neighbours score higher
//      signals the AI assigned the wrong segment's timestamp.
//
//  (F) OFF-BY-ONE SEGMENT  — The ADJACENT segments (±1) score better against
//      the question text than the stored segment. This is the most common
//      "one step too early / one step too late" pattern.
//
//  (G) SEEK MECHANISM INTEGRITY  — Checks the AMD build for the parseInt()
//      call and the seekTo(stampSecs, true) call to confirm the JS is passing
//      integer seconds (not milliseconds or uncoerced floats) to the YouTube
//      player. A missing parseInt would send a float string and seek to the
//      wrong position on some YT API versions.

$rows_accuracy = '';
$rows_accuracy .= diag_info(
    'What this checks',
    'Checks that stored timestamp_seconds values point to the CORRECT video position. '
  . 'Sections 1–9 confirm timestamps exist and are wired up — this section confirms '
  . 'they are accurate. Detects 7 failure modes: (A) transcript format mix MM:SS vs HH:MM:SS, '
  . '(B) non-ascending question order, (C) duplicate timestamps, (D) systematic forward/backward offset, '
  . '(E) low question↔segment text overlap, (F) off-by-one segment pattern, (G) AMD seekTo integrity.'
);

// ── Helper: parse transcript into timed segments ────────────────────────────
// Returns array of ['ts' => int_seconds, 'text' => string, 'raw' => string,
//                   'format' => 'MM:SS'|'HH:MM:SS']
// We deliberately distinguish the two formats so we can detect mixing (failure A).
function kc_parse_transcript_segments(string $transcript): array {
    $segments  = [];
    $lines     = preg_split('/\r?\n/', $transcript);
    $cur_ts    = null;
    $cur_fmt   = null;
    $cur_text  = '';
    $cur_raw   = '';
    foreach ($lines as $line) {
        $line_trimmed = trim($line);
        if ($line_trimmed === '') continue;
        // Match HH:MM:SS first (3-group), then MM:SS (2-group).
        if (preg_match('/^(\d{1,2}):(\d{2}):(\d{2})\b/', $line_trimmed, $m)) {
            if ($cur_ts !== null) {
                $segments[] = ['ts' => $cur_ts, 'format' => $cur_fmt,
                               'raw' => $cur_raw, 'text' => trim($cur_text)];
            }
            $cur_ts   = (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3];
            $cur_fmt  = 'HH:MM:SS';
            $cur_raw  = $m[0];
            $cur_text = preg_replace('/^\d{1,2}:\d{2}:\d{2}\s*/', '', $line_trimmed);
        } elseif (preg_match('/^(\d{1,2}):(\d{2})\b/', $line_trimmed, $m)) {
            if ($cur_ts !== null) {
                $segments[] = ['ts' => $cur_ts, 'format' => $cur_fmt,
                               'raw' => $cur_raw, 'text' => trim($cur_text)];
            }
            $cur_ts   = (int)$m[1] * 60 + (int)$m[2];
            $cur_fmt  = 'MM:SS';
            $cur_raw  = $m[0];
            $cur_text = preg_replace('/^\d{1,2}:\d{2}\s*/', '', $line_trimmed);
        } else {
            // Continuation line for current segment
            if ($cur_ts !== null) {
                $cur_text .= ' ' . $line_trimmed;
            }
        }
    }
    if ($cur_ts !== null) {
        $segments[] = ['ts' => $cur_ts, 'format' => $cur_fmt,
                       'raw' => $cur_raw, 'text' => trim($cur_text)];
    }
    return $segments;
}

// Helper: find the segment index whose timestamp bracket contains $target_seconds.
// Returns ['idx' => int, 'seg' => array] or null when no segments.
function kc_find_segment_for_seconds(array $segments, int $target_seconds): ?array {
    if (empty($segments)) return null;
    $best_idx = 0;
    foreach ($segments as $i => $seg) {
        if ($seg['ts'] <= $target_seconds) {
            $best_idx = $i;
        } else {
            break;
        }
    }
    return ['idx' => $best_idx, 'seg' => $segments[$best_idx]];
}

// Helper: word-level Jaccard overlap between two strings (0.0 – 1.0).
// Stop-words are removed so "what is the" doesn't inflate the score.
function kc_word_overlap(string $a, string $b): float {
    static $stopwords = ['the','a','an','is','are','was','were','be','been','being',
                         'have','has','had','do','does','did','will','would','shall',
                         'should','may','might','must','can','could','and','or','but',
                         'if','in','on','at','to','for','of','with','by','from',
                         'this','that','these','those','it','its','which','what',
                         'how','when','where','why','who','not','no','all','any',
                         'so','as','about','during','when','than'];
    $tokenise = function (string $s) use ($stopwords): array {
        $words = preg_split('/[\s\W]+/', strtolower($s), -1, PREG_SPLIT_NO_EMPTY);
        return array_filter($words, fn($w) => strlen($w) > 2 && !in_array($w, $stopwords, true));
    };
    $wa = array_flip($tokenise($a));
    $wb = array_flip($tokenise($b));
    $inter = count(array_intersect_key($wa, $wb));
    $union = count(array_merge($wa, $wb));
    return $union > 0 ? round($inter / $union, 3) : 0.0;
}

// ── Re-build transcript from sourcecontext ──────────────────────────────────
$acc_sc_raw = isset($knowledgecheck->sourcecontext) ? $knowledgecheck->sourcecontext : '';
$acc_sc     = is_string($acc_sc_raw) && $acc_sc_raw !== '' ? json_decode($acc_sc_raw, true) : null;
$acc_transcript = '';
$acc_segments   = [];
$acc_has_transcript = false;

if (is_array($acc_sc) && !empty($acc_sc['textSources']) && is_array($acc_sc['textSources'])) {
    foreach ($acc_sc['textSources'] as $ts_src) {
        $t = $ts_src['text'] ?? ($ts_src['content'] ?? '');
        if (trim($t) !== '') {
            $acc_transcript .= "\n" . trim($t);
        }
    }
    $acc_transcript = trim($acc_transcript);
    if ($acc_transcript !== '') {
        $acc_has_transcript = true;
        $acc_segments = kc_parse_transcript_segments($acc_transcript);
    }
}

if (!$acc_has_transcript) {
    $rows_accuracy .= diag_info(
        'Transcript available for accuracy analysis',
        'No transcript text found in sourcecontext. '
      . 'Accuracy checks (A–F) require a pasted transcript — they cannot run for '
      . 'activities generated from topics or question lists only. '
      . 'Section (G) AMD seekTo integrity check will still run.'
    );
} else {
    $rows_accuracy .= diag_pass(
        'Transcript available for accuracy analysis',
        mb_strlen($acc_transcript) . ' chars, ' . count($acc_segments) . ' timed segment(s) parsed'
    );
}

// ── (A) Transcript format mix — MM:SS vs HH:MM:SS ──────────────────────────
if ($acc_has_transcript && !empty($acc_segments)) {
    $fmt_mmss   = 0;
    $fmt_hhmmss = 0;
    foreach ($acc_segments as $seg) {
        if ($seg['format'] === 'HH:MM:SS') { $fmt_hhmmss++; }
        else                               { $fmt_mmss++;   }
    }
    if ($fmt_hhmmss > 0 && $fmt_mmss > 0) {
        $rows_accuracy .= diag_fail(
            '(A) Transcript timestamp format',
            "MIXED FORMATS DETECTED: $fmt_mmss MM:SS segment(s) and $fmt_hhmmss HH:MM:SS segment(s). "
          . "Parsers that use the two-group regex \\d{1,2}:\\d{2} will mis-read HH:MM:SS as MM:SS "
          . "— e.g. '1:23:45' becomes 83 s instead of 5025 s, placing every HH:MM:SS question ~82 minutes too early. "
          . "FIX: re-paste the transcript making sure all markers use the same format, then regenerate."
        );
        $overall_pass = false;
    } elseif ($fmt_hhmmss > 0) {
        $rows_accuracy .= diag_info(
            '(A) Transcript timestamp format',
            "All $fmt_hhmmss segment(s) use HH:MM:SS — consistent. "
          . "Videos longer than 59:59 correctly need this format."
        );
    } else {
        $rows_accuracy .= diag_pass(
            '(A) Transcript timestamp format',
            "All $fmt_mmss segment(s) use MM:SS — consistent format, no parsing ambiguity."
        );
    }
} elseif ($acc_has_transcript) {
    $rows_accuracy .= diag_info('(A) Transcript timestamp format', 'No timed segments parsed — cannot check format consistency.');
}

// ── Load stored questions for accuracy checks ────────────────────────────────
$acc_questions = [];
if (!empty($db_questions)) {
    foreach ($db_questions as $q) {
        if (isset($q->timestamp_seconds) && $q->timestamp_seconds !== null) {
            $acc_questions[] = $q;
        }
    }
}
$acc_q_count = count($acc_questions);

if ($acc_q_count === 0) {
    $rows_accuracy .= diag_info(
        'Questions with timestamps for accuracy checks (B–F)',
        'No questions have timestamp_seconds — accuracy checks B–F require at least one timestamped question. '
      . 'See Section 4 for why timestamps may be null.'
    );
} else {
    $rows_accuracy .= diag_info(
        'Questions with timestamps for accuracy checks (B–F)',
        "$acc_q_count question(s) have timestamp_seconds — running accuracy checks."
    );

    // ── (B) Non-ascending order ──────────────────────────────────────────────
    $out_of_order = [];
    $prev_ts = -1;
    foreach ($acc_questions as $i => $q) {
        $ts = (int)$q->timestamp_seconds;
        if ($ts < $prev_ts) {
            $qnum = isset($q->questionnumber) ? $q->questionnumber : ($i + 1);
            $out_of_order[] = "Q$qnum: {$ts}s < prev {$prev_ts}s";
        }
        $prev_ts = $ts;
    }
    if (!empty($out_of_order)) {
        $rows_accuracy .= diag_fail(
            '(B) Timestamps in ascending order',
            'OUT OF ORDER: ' . implode('; ', $out_of_order) . '. '
          . 'Questions should map to progressively later points in the video. '
          . 'Out-of-order timestamps mean the AI matched some questions to unrelated segments. '
          . 'Re-paste a clean timestamped transcript and regenerate.'
        );
        $overall_pass = false;
    } else {
        $rows_accuracy .= diag_pass(
            '(B) Timestamps in ascending order',
            "All $acc_q_count timestamp(s) are in ascending order — no segment-swap detected."
        );
    }

    // ── (C) Duplicate timestamps ─────────────────────────────────────────────
    $ts_counts = [];
    foreach ($acc_questions as $q) {
        $ts = (int)$q->timestamp_seconds;
        $ts_counts[$ts] = ($ts_counts[$ts] ?? 0) + 1;
    }
    $duplicates = array_filter($ts_counts, fn($c) => $c > 1);
    if (!empty($duplicates)) {
        $dup_details = [];
        foreach ($duplicates as $ts => $cnt) {
            $dup_details[] = gmdate('i:s', $ts) . " ({$ts}s) × {$cnt} questions";
        }
        $rows_accuracy .= diag_fail(
            '(C) Duplicate timestamps',
            'DUPLICATES: ' . implode(', ', $dup_details) . '. '
          . 'Multiple questions share the same timestamp — the AI could not find distinct '
          . 'segments for each question. Only one of the duplicated questions will be at '
          . 'the correct position; the rest will land on the wrong frame. '
          . 'FIX: ensure the transcript has enough unique timestamp markers near each topic, '
          . 'then regenerate.'
        );
        $overall_pass = false;
    } else {
        $rows_accuracy .= diag_pass(
            '(C) Duplicate timestamps',
            "No duplicates — each of the $acc_q_count question(s) has a unique timestamp."
        );
    }

    // ── (D) Systematic forward/backward offset ───────────────────────────────
    // Compute consecutive gaps between sorted timestamps. If the median gap is
    // either: (i) < 5 s → all questions clustered at almost same point →
    // AI reused the first segment only, or (ii) there are ≥3 questions and
    // ALL gaps are equal (robotic even spacing) → fallback used fixed intervals.
    if ($acc_q_count >= 2) {
        $sorted_ts = array_map(fn($q) => (int)$q->timestamp_seconds, $acc_questions);
        sort($sorted_ts);
        $gaps = [];
        for ($gi = 1; $gi < count($sorted_ts); $gi++) {
            $gaps[] = $sorted_ts[$gi] - $sorted_ts[$gi - 1];
        }
        $span   = max($sorted_ts) - min($sorted_ts);
        $median = count($gaps) % 2 === 0
            ? ($gaps[intdiv(count($gaps),2)-1] + $gaps[intdiv(count($gaps),2)]) / 2
            : $gaps[intdiv(count($gaps),2)];

        // Check for artificially even spacing (all gaps within 2 s of each other)
        $gap_min = min($gaps);
        $gap_max = max($gaps);
        $evenly_spaced = ($gap_max - $gap_min) <= 2 && $acc_q_count >= 3;

        if ($evenly_spaced) {
            $rows_accuracy .= diag_fail(
                '(D) Systematic offset — evenly spaced timestamps',
                'ALL gaps between consecutive question timestamps are within 2 s of each other '
              . "(min={$gap_min}s, max={$gap_max}s, median={$median}s). "
              . 'This is the signature of a fixed-interval fallback rather than genuine segment matching — '
              . 'timestamps are mathematically evenly distributed regardless of where each topic appears. '
              . 'Questions will feel "too far forward" or "too far backward" because the intervals do '
              . 'not match the actual pacing of the video content. '
              . 'FIX: paste a YouTube-format timestamped transcript and regenerate so the AI picks real segment positions.'
            );
            $overall_pass = false;
        } elseif ($median < 5 && $span < 30) {
            $rows_accuracy .= diag_fail(
                '(D) Systematic offset — all timestamps clustered',
                "Median gap between consecutive timestamps is only {$median}s (total span={$span}s across $acc_q_count questions). "
              . 'All questions are pinned to almost the same video position. '
              . 'The AI likely found only one usable segment and assigned it to all questions. '
              . 'Check that the transcript covers the full video, not just the intro.'
            );
            $overall_pass = false;
        } else {
            $rows_accuracy .= diag_pass(
                '(D) Systematic offset — gap distribution',
                "Gaps look natural: min={$gap_min}s, median={$median}s, max={$gap_max}s across $acc_q_count question(s). "
              . "Total span: {$span}s. No fixed-interval or clustering pattern detected."
            );
        }
    } else {
        $rows_accuracy .= diag_info(
            '(D) Systematic offset',
            'Only 1 timestamped question — need ≥2 to check gap distribution.'
        );
    }

    // ── (E) & (F) Per-question: segment text vs question text alignment ──────
    if ($acc_has_transcript && !empty($acc_segments)) {
        $rows_accuracy .= diag_info(
            '(E)+(F) Per-question segment alignment',
            "Checking each question's timestamp against the transcript segment it falls in. "
          . "Shows the overlap score (word Jaccard, 0–1) between the question text and the "
          . "segment text at the stored timestamp vs the adjacent segments (±1). "
          . "FAIL: adjacent segment scores higher than stored segment by > 0.08 (off-by-one). "
          . "WARN: overlap with stored segment is below 0.05 for all three windows (AI may have "
          . "picked a completely unrelated segment)."
        );

        $misaligned_count = 0;
        $low_overlap_count = 0;
        $qi = 0;
        foreach ($acc_questions as $q) {
            $qi++;
            if ($qi > 10) {
                $rows_accuracy .= diag_info('...', (count($acc_questions) - 10) . ' more questions not shown');
                break;
            }
            $stored_ts  = (int)$q->timestamp_seconds;
            $qnum       = isset($q->questionnumber) ? $q->questionnumber : $qi;
            $qtext      = $q->questiontext ?? '';
            $ts_label   = gmdate('i:s', $stored_ts) . " ({$stored_ts}s)";

            $match = kc_find_segment_for_seconds($acc_segments, $stored_ts);
            if ($match === null) {
                $rows_accuracy .= diag_info("Q$qnum segment lookup", "No segments — cannot check alignment.");
                continue;
            }
            $seg_idx  = $match['idx'];
            $seg      = $match['seg'];
            $seg_text = $seg['text'];
            $seg_ts   = $seg['ts'];

            // Score: stored segment
            $score_stored = kc_word_overlap($qtext, $seg_text);

            // Score: segment before (−1)
            $score_prev = -1.0;
            if ($seg_idx > 0) {
                $score_prev = kc_word_overlap($qtext, $acc_segments[$seg_idx - 1]['text']);
            }

            // Score: segment after (+1)
            $score_next = -1.0;
            if ($seg_idx < count($acc_segments) - 1) {
                $score_next = kc_word_overlap($qtext, $acc_segments[$seg_idx + 1]['text']);
            }

            $seg_preview   = mb_substr(trim($seg_text), 0, 80);
            $stored_ts_raw = $seg['raw'] . '→' . $seg_ts . 's';

            // Detect (F) off-by-one: an adjacent segment scores substantially better
            $best_adj = max($score_prev, $score_next);
            $adj_label = $score_next > $score_prev ? '+1 (next)' : '-1 (prev)';
            if ($best_adj > $score_stored + 0.08) {
                $adj_seg_preview = '';
                if ($score_next > $score_prev && isset($acc_segments[$seg_idx + 1])) {
                    $adj_seg_preview = mb_substr($acc_segments[$seg_idx + 1]['text'], 0, 60);
                } elseif (isset($acc_segments[$seg_idx - 1])) {
                    $adj_seg_preview = mb_substr($acc_segments[$seg_idx - 1]['text'], 0, 60);
                }
                $rows_accuracy .= diag_fail(
                    "(F) Q$qnum off-by-one segment @ $ts_label",
                    "Stored segment score: {$score_stored} | Adjacent segment score ($adj_label): {$best_adj} — "
                  . "adjacent scores {$best_adj} vs stored {$score_stored}. "
                  . "The stored timestamp points to: \"$seg_preview...\" "
                  . "but the adjacent segment matches the question better: \"$adj_seg_preview...\" "
                  . "This is the off-by-one signature — timestamp is one segment too early or too late."
                );
                $overall_pass = false;
                $misaligned_count++;
            } elseif ($score_stored < 0.05 && $best_adj < 0.05) {
                // (E) Very low overlap with any nearby segment
                $rows_accuracy .= diag_info(
                    "(E) Q$qnum low overlap @ $ts_label",
                    "Stored segment score: {$score_stored} | Best adjacent: {$best_adj}. "
                  . "Low overlap with all nearby segments — the question may cover a topic "
                  . "that doesn't appear verbatim in the transcript. "
                  . "Transcript segment text: \"$seg_preview...\""
                );
                $low_overlap_count++;
            } else {
                $rows_accuracy .= diag_pass(
                    "(E)+(F) Q$qnum alignment @ $ts_label",
                    "Overlap: {$score_stored} (stored) vs {$best_adj} (best adjacent). "
                  . "No off-by-one detected. Segment: \"{$seg_preview}...\""
                );
            }
        }

        if ($misaligned_count === 0 && $low_overlap_count === 0) {
            $rows_accuracy .= diag_pass(
                'Overall E+F segment alignment',
                'No off-by-one or zero-overlap patterns detected — timestamps appear to reference the correct segments.'
            );
        } elseif ($misaligned_count > 0) {
            $rows_accuracy .= diag_fail(
                'Overall E+F segment alignment',
                "$misaligned_count off-by-one question(s) detected — some timestamps point to the wrong segment. "
              . 'This explains the "too far forward/backward" student report. '
              . 'If this affects all questions: regenerate after re-pasting a cleaner transcript. '
              . 'If only a few: the AI could not find a distinct segment for those topics.'
            );
        }
    } elseif (!$acc_has_transcript) {
        $rows_accuracy .= diag_info(
            '(E)+(F) Segment alignment',
            'No transcript stored — alignment checks require a pasted transcript text source.'
        );
    } else {
        $rows_accuracy .= diag_info(
            '(E)+(F) Segment alignment',
            'Transcript found but no timed segments parsed (no MM:SS or HH:MM:SS markers on transcript lines). '
          . 'Alignment cannot run. Ensure the transcript has YouTube-style timestamps at the start of each line.'
        );
    }
}

// ── (G) AMD seekTo integrity — is the value passed correctly to YouTube API ──
$rows_accuracy .= diag_info(
    '(G) AMD seekTo integrity — what this checks',
    'Reads knowledgecheck.js/min.js and verifies: (1) the stored integer is passed through '
  . 'parseInt() before seekTo() — without parseInt a float-string could seek to wrong position '
  . 'on some YouTube API versions; (2) seekTo(stampSecs, true) — the second arg true tells '
  . 'the player to seek as precisely as possible (false = seek to nearest keyframe only, '
  . 'which can be several seconds off on long videos); '
  . '(3) playVideo() is called after seek so the video starts from the new position.'
);

$amd_src_kc  = __DIR__ . '/amd/src/knowledgecheck.js';
$amd_min_kc  = __DIR__ . '/amd/build/knowledgecheck.min.js';

foreach (['source' => $amd_src_kc, 'minified build' => $amd_min_kc] as $amd_label => $amd_path) {
    if (!file_exists($amd_path)) {
        $rows_accuracy .= diag_info("(G) $amd_label", 'File not found — skipping seekTo check for this file.');
        continue;
    }
    $amd_src_text = file_get_contents($amd_path);

    // Check 1: parseInt wrapping before seekTo
    // Pattern: parseInt(q.timestamp_seconds, 10) or parseInt(stampSecs — either is fine.
    if (strpos($amd_src_text, 'parseInt(q.timestamp_seconds') !== false
        || strpos($amd_src_text, 'parseInt(stampSecs') !== false
        || (strpos($amd_src_text, 'var stampSecs = parseInt') !== false)) {
        $rows_accuracy .= diag_pass("(G) $amd_label — parseInt() before seekTo", 'parseInt() applied to timestamp_seconds before seekTo — safe integer coercion confirmed.');
    } else {
        $rows_accuracy .= diag_fail(
            "(G) $amd_label — parseInt() before seekTo",
            'Cannot confirm parseInt() is applied to timestamp_seconds before the seekTo() call. '
          . 'If a float string (e.g. "83.0") is passed, some YouTube iframe API versions '
          . 'seek to the nearest keyframe rather than the precise second — potentially '
          . 'shifting the seek point by several seconds on longer videos.'
        );
        $overall_pass = false;
    }

    // Check 2: seekTo(stampSecs, true) — second arg must be true
    if (preg_match('/seekTo\s*\(\s*\w+\s*,\s*true\s*\)/', $amd_src_text)) {
        $rows_accuracy .= diag_pass("(G) $amd_label — seekTo(x, true)", 'seekTo called with allowSeekAhead=true — precise seek enabled (not keyframe-only).');
    } elseif (preg_match('/seekTo\s*\(\s*\w+\s*,\s*false\s*\)/', $amd_src_text)) {
        $rows_accuracy .= diag_fail(
            "(G) $amd_label — seekTo(x, true)",
            'seekTo is called with allowSeekAhead=FALSE. On longer videos the YouTube player '
          . 'will seek only to the nearest keyframe, which can be 5–10 s away from the '
          . 'intended position. FIX: change to seekTo(stampSecs, true) in knowledgecheck.js.'
        );
        $overall_pass = false;
    } else {
        $rows_accuracy .= diag_info("(G) $amd_label — seekTo call", 'Could not match seekTo pattern — check JS manually.');
    }

    // Check 3: playVideo() called after seekTo
    // Find the seekTo region and check playVideo is within 3 lines
    $seek_pos = strpos($amd_src_text, 'seekTo(');
    if ($seek_pos !== false) {
        $seek_window = substr($amd_src_text, $seek_pos, 200);
        if (strpos($seek_window, 'playVideo') !== false) {
            $rows_accuracy .= diag_pass("(G) $amd_label — playVideo() after seekTo", 'playVideo() is called immediately after seekTo() — video resumes from sought position.');
        } else {
            $rows_accuracy .= diag_info(
                "(G) $amd_label — playVideo() after seekTo",
                'playVideo() not found within 200 chars of seekTo(). '
              . 'The seek will move the playhead but the video may remain paused if the student '
              . 'clicked the "Jump to" link while the video was paused. '
              . 'This is a UX issue, not a timestamp accuracy issue.'
            );
        }
    }
}

// ── Page output ───────────────────────────────────────────────────────────────

$overall_label = $overall_pass ? '<span class="pass-banner">ALL CHECKS PASSED</span>' : '<span class="fail-banner">ONE OR MORE CHECKS FAILED — see details below</span>';

echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<title>KC Diagnostic — cmid ' . $cmid . '</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 2rem; background: #f5f5f5; color: #111; }
  h1 { font-size: 1.3rem; margin-bottom: 0.25rem; }
  h2 { font-size: 1rem; margin: 1.5rem 0 0.5rem; background: #222; color: #fff; padding: 0.4rem 0.7rem; border-radius: 4px; }
  table { width: 100%; border-collapse: collapse; background: #fff; margin-bottom: 1rem; border-radius: 4px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
  td { padding: 0.45rem 0.7rem; font-size: 0.85rem; border-bottom: 1px solid #eee; vertical-align: top; }
  td.label { width: 42%; font-weight: 500; }
  td.pass  { width: 7%; color: #1a7a3f; font-weight: 700; white-space: nowrap; }
  td.fail  { width: 7%; color: #c0392b; font-weight: 700; white-space: nowrap; }
  td.info  { width: 7%; color: #7a6000; font-weight: 700; white-space: nowrap; }
  td.val   { color: #555; font-size: 0.82rem; word-break: break-word; }
  .overall { padding: 0.6rem 1rem; border-radius: 4px; margin-bottom: 1.5rem; font-weight: 600; font-size: 1rem; display: inline-block; }
  .pass-banner { background: #d4edda; color: #155724; padding: 0.5rem 1rem; border-radius: 4px; display: inline-block; }
  .fail-banner { background: #f8d7da; color: #721c24; padding: 0.5rem 1rem; border-radius: 4px; display: inline-block; }
  .meta { font-size: 0.8rem; color: #555; margin-bottom: 0.5rem; }
  a.back { font-size: 0.8rem; color: #0070f3; }
</style></head><body>';

echo '<h1>AI Knowledge Check — Diagnostic Report</h1>';
echo '<p class="meta">';
echo 'Activity: <strong>' . htmlspecialchars($knowledgecheck->name) . '</strong> &nbsp;|&nbsp; ';
echo 'cmid: <strong>' . $cmid . '</strong> &nbsp;|&nbsp; ';
echo 'Instance ID: <strong>' . $knowledgecheck->id . '</strong> &nbsp;|&nbsp; ';
$plugin = new stdClass(); include(__DIR__ . '/version.php');
echo 'Plugin version: <strong>' . ($plugin->release ?? '?') . '</strong> &nbsp;|&nbsp; ';
echo 'Video ID: <strong>' . ($diag_hasvideo ? htmlspecialchars($diag_videoid) : '(none)') . '</strong>';
echo '</p>';
echo '<div class="overall">' . $overall_label . '</div>';

echo '<h2>1. Generate payload — fields forwarded to /api/generate-knowledgecheck</h2>';
echo '<table>' . $rows_generate . '</table>';

echo '<h2>2. sourcecontext DB blob — what is persisted between generate and regenerate</h2>';
echo '<table>' . $rows_db . '</table>';

echo '<h2>3. Simulated regenerateinstructions payload — top-level fields (FIX-KC-REGEN-EDLEVEL + FIX-KC-TIMESTAMP-REGEN)</h2>';
echo '<table>' . $rows_regen . '</table>';

echo '<h2>4. Stored questions — timestamp_seconds values (FIX-KC-TIMESTAMP-GENERATE)</h2>';
echo '<table>' . $rows_questions . '</table>';

echo '<h2>5. Frontend config simulation — JS visibility condition check</h2>';
echo '<table>' . $rows_frontend . '</table>';

echo '<h2>6. AMD build — knowledgecheck.min.js timestamp code presence</h2>';
echo '<table>' . $rows_amd . '</table>';

echo '<h2>7. Transcript content — timestamp marker check (FIX-KC-DIAG-TABLE)</h2>';
echo '<table>' . $rows_transcript . '</table>';

echo '<h2>8. JS mapper integrity — timestamp_seconds pass-through [Layer 5: JS Mapper]</h2>';
echo '<table>' . $rows_mapper . '</table>';

echo '<h2>9. JS renderer — showQuestion() reads and renders timestamp_seconds [Layer 6: JS Renderer]</h2>';
echo '<table>' . $rows_renderer . '</table>';

echo '<h2>10. Timestamp accuracy — segment alignment analysis (A–G) [FIX-KC-TIMESTAMP-ACCURACY v1.5.112]</h2>';
echo '<table>' . $rows_accuracy . '</table>';

echo '<p class="meta">To reset: run Generate on this activity, then reload this page.</p>';
echo '<p><a class="back" href="' . (new moodle_url('/mod/aiknowledgecheck/view.php', ['id' => $cmid]))->out() . '">&larr; Back to activity</a></p>';
echo '</body></html>';
