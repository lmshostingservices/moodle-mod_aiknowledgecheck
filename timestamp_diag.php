<?php
// AI Knowledge Check — Timestamp Accuracy Diagnostic v1.0
// Standalone deep-dive into timestamp alignment only.
// Access: /mod/aiknowledgecheck/timestamp_diag.php?cmid=<cmid>
// Requires: moodle/site:config capability.
// Safe — no data is modified, no external requests are made.
//
// This file is the definitive answer to "timestamps feel too far forward/backward."
// It replicates the EXACT server-side algorithms (parseTranscriptSegments +
// findBestTimestampForQuestion) in PHP and shows, for every stored question:
//   ① Which transcript segment the server SHOULD have assigned (algorithm simulation)
//   ② Which segment is ACTUALLY stored in the DB (the seek target the student gets)
//   ③ The delta: how many seconds and segments off the stored value is
//   ④ The transcript text at the stored position vs the ideal position
//
// Failure modes detected:
//   (A) Transcript format mix — MM:SS and HH:MM:SS in same transcript (parse-error bug)
//   (B) Format A vs B segment count — which parser the server chose and why
//   (C) Stored timestamp vs algorithm ideal — question-level delta
//   (D) Non-ascending order — backward-going timestamps in question sequence
//   (E) Duplicate timestamps — multiple questions pinned to same video point
//   (F) Position-based fallback — detects questions where keyword matching scored <10%
//       so the server fell back to evenly-distributed position assignment
//   (G) AMD seekTo integrity — parseInt, allowSeekAhead=true, playVideo after seek

require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$cmid = optional_param('cmid', 0, PARAM_INT) ?: optional_param('id', 0, PARAM_INT);

// ── CSS ──────────────────────────────────────────────────────────────────────
$css = '
<style>
* { box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
       margin: 0; background: #f0f2f5; color: #111; }
.page-wrap { max-width: 1200px; margin: 0 auto; padding: 1.5rem; }
h1 { font-size: 1.25rem; margin: 0 0 .25rem; }
h2 { font-size: .95rem; margin: 1.4rem 0 .4rem; background: #1a1a2e; color: #fff;
     padding: .4rem .8rem; border-radius: 4px; }
.meta { font-size: .78rem; color: #666; margin-bottom: 1rem; }
/* Summary box */
.summary { border-radius: 6px; padding: .9rem 1.2rem; margin-bottom: 1.2rem;
           font-size: .9rem; line-height: 1.6; }
.summary.pass { background: #d4edda; border: 1px solid #b8dab8; color: #155724; }
.summary.fail { background: #f8d7da; border: 1px solid #e8b4b4; color: #721c24; }
.summary.warn { background: #fff3cd; border: 1px solid #f0d090; color: #664d03; }
.summary strong { font-weight: 700; }
/* Standard diag table */
table.diag { width: 100%; border-collapse: collapse; background: #fff;
             margin-bottom: .8rem; border-radius: 6px; overflow: hidden;
             box-shadow: 0 1px 4px rgba(0,0,0,.09); }
table.diag td { padding: .4rem .7rem; font-size: .82rem; border-bottom: 1px solid #eee;
                vertical-align: top; }
table.diag td.lbl { width: 38%; font-weight: 500; }
table.diag td.status { width: 7%; font-weight: 700; white-space: nowrap; }
table.diag td.val { color: #444; word-break: break-word; }
.pass  { color: #1a7a3f; } .fail { color: #c0392b; } .info { color: #7a6000; }
.warn  { color: #7a6000; }
/* Segment table */
table.segs { width: 100%; border-collapse: collapse; background: #fff;
             margin-bottom: .8rem; border-radius: 6px; overflow: hidden;
             box-shadow: 0 1px 4px rgba(0,0,0,.09); font-size: .80rem; }
table.segs th { background: #f7f7f7; padding: .35rem .6rem; text-align: left;
                font-weight: 600; border-bottom: 2px solid #ddd; }
table.segs td { padding: .32rem .6rem; border-bottom: 1px solid #f0f0f0;
                vertical-align: top; }
.seg-stored { background: #fff0c0 !important; }
.seg-ideal  { background: #d4edda !important; }
.seg-both   { background: #c8e6c9 !important; }
.seg-no     { background: #fde8e8 !important; }
/* Per-question alignment card */
.qcard { background: #fff; border: 1px solid #e0e0e0; border-radius: 6px;
         margin-bottom: .8rem; overflow: hidden;
         box-shadow: 0 1px 3px rgba(0,0,0,.07); }
.qcard-head { padding: .55rem 1rem; font-weight: 600; font-size: .88rem;
              display: flex; align-items: center; gap: .7rem; }
.qcard-head.ok  { background: #d4edda; border-bottom: 1px solid #b8dab8; }
.qcard-head.bad { background: #f8d7da; border-bottom: 1px solid #e8b4b4; }
.qcard-head.warn{ background: #fff3cd; border-bottom: 1px solid #f0d090; }
.qcard-body { padding: .6rem 1rem; font-size: .83rem; }
.badge { display: inline-block; border-radius: 3px; padding: 1px 7px;
         font-size: .75rem; font-weight: 700; margin-right: .3rem; }
.badge.p { background:#d4edda; color:#155724; }
.badge.f { background:#f8d7da; color:#721c24; }
.badge.w { background:#fff3cd; color:#664d03; }
/* Score bar */
.score-row { display: flex; gap: .4rem; align-items: center; font-size: .8rem;
             margin: .25rem 0; }
.score-bar { height: 8px; border-radius: 3px; min-width: 4px; }
.score-bar.stored { background: #f39c12; }
.score-bar.ideal  { background: #27ae60; }
/* Transcript context block */
.ctx-block { background: #f8f8f8; border-left: 3px solid #ccc;
             padding: .4rem .7rem; margin: .3rem 0;
             font-size: .8rem; border-radius: 0 3px 3px 0; line-height: 1.4; }
.ctx-block.stored { border-left-color: #f39c12; }
.ctx-block.ideal  { border-left-color: #27ae60; }
.ctx-label { font-weight: 600; font-size: .75rem; text-transform: uppercase;
             letter-spacing: .04em; margin-bottom: .2rem; }
.ts-chip { display: inline-block; background: #2d3748; color: #fff;
           border-radius: 3px; padding: 1px 6px; font-size: .75rem;
           font-family: monospace; margin-right: .4rem; }
.delta { font-weight: 700; }
.delta.ok   { color: #155724; }
.delta.close{ color: #664d03; }
.delta.far  { color: #721c24; }
/* Keywords */
.kw-list { display: flex; flex-wrap: wrap; gap: .25rem; margin: .2rem 0; }
.kw { background: #e8f4fd; border: 1px solid #bee3f8; border-radius: 3px;
      padding: 1px 5px; font-size: .75rem; color: #2b6cb0; }
.kw.hit  { background: #c6f6d5; border-color: #9ae6b4; color: #276749; }
.kw.miss { background: #fff5f5; border-color: #fed7d7; color: #9b2c2c; }
a.back { font-size: .8rem; color: #0070f3; }
.no-data { color: #888; font-style: italic; padding: .5rem 0; }
</style>';

// ── CMID validation ──────────────────────────────────────────────────────────
if (!$cmid) {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>KC Timestamp Diag</title>' . $css . '</head><body><div class="page-wrap">';
    echo '<h1>AI Knowledge Check — Timestamp Diagnostic</h1>';
    echo '<div class="summary fail"><strong>No cmid supplied.</strong> Append <code>?cmid=&lt;cmid&gt;</code> to the URL.</div>';
    echo '</div></body></html>';
    exit;
}

$cm_raw = get_coursemodule_from_id('aiknowledgecheck', $cmid, 0, false, IGNORE_MISSING);
if (!$cm_raw) {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>KC Timestamp Diag</title>' . $css . '</head><body><div class="page-wrap">';
    echo '<h1>AI Knowledge Check — Timestamp Diagnostic</h1>';
    echo '<div class="summary fail"><strong>cmid=' . $cmid . ' is not a valid Knowledge Check activity.</strong></div>';
    echo '</div></body></html>';
    exit;
}

list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'aiknowledgecheck');
$kc = $DB->get_record('aiknowledgecheck', ['id' => $cm->instance], '*', MUST_EXIST);

// ══════════════════════════════════════════════════════════════════════════════
// ALGORITHM IMPLEMENTATIONS — exact PHP ports of the Node.js server functions
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Replicates server parseTranscriptSegments():
 * Runs BOTH Format A (line-start) and Format B (inline) parsers and returns
 * whichever produces more segments — exactly as the server does.
 *
 * @return array ['format_a' => [...], 'format_b' => [...], 'chosen' => 'A'|'B', 'segments' => [...]]
 */
function kcts_parse_transcript(string $text): array {
    // Format A: timestamp at start of each line
    $formatA = [];
    {
        $lines = explode("\n", $text);
        $curSecs = -1;
        $curParts = [];
        $flush = function() use (&$formatA, &$curSecs, &$curParts) {
            if ($curSecs >= 0) {
                $formatA[] = ['seconds' => $curSecs, 'text' => trim(implode(' ', $curParts))];
                $curParts = [];
            }
        };
        foreach ($lines as $line) {
            $t = trim($line);
            if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(.*)/', $t, $m)) {
                $flush();
                $curSecs = (isset($m[3]) && $m[3] !== '')
                    ? (int)$m[1]*3600 + (int)$m[2]*60 + (int)$m[3]
                    : (int)$m[1]*60  + (int)$m[2];
                if ($m[4] !== '') $curParts[] = $m[4];
            } elseif ($curSecs >= 0 && strlen($t) > 0) {
                $curParts[] = $t;
            }
        }
        $flush();
    }

    // Format B: timestamps anywhere inline (word-boundary)
    $formatB = [];
    {
        preg_match_all('/\b(\d{1,2}):(\d{2})(?::(\d{2}))?\b/', $text, $matches, PREG_OFFSET_CAPTURE);
        $lastIdx  = 0;
        $lastSecs = -1;
        $cnt = count($matches[0]);
        for ($i = 0; $i < $cnt; $i++) {
            $fullMatch = $matches[0][$i][0];
            $offset    = $matches[0][$i][1];
            $g1 = $matches[1][$i][0];
            $g2 = $matches[2][$i][0];
            $g3 = ($matches[3][$i][0] !== '') ? $matches[3][$i][0] : '';
            $secs = $g3 !== ''
                ? (int)$g1*3600 + (int)$g2*60 + (int)$g3
                : (int)$g1*60  + (int)$g2;
            if ($lastSecs >= 0) {
                $segText = trim(substr($text, $lastIdx, $offset - $lastIdx));
                if (strlen($segText) > 0) {
                    $formatB[] = ['seconds' => $lastSecs, 'text' => $segText];
                }
            }
            $lastSecs = $secs;
            $lastIdx  = $offset + strlen($fullMatch);
        }
        if ($lastSecs >= 0) {
            $segText = trim(substr($text, $lastIdx));
            if (strlen($segText) > 0) {
                $formatB[] = ['seconds' => $lastSecs, 'text' => $segText];
            }
        }
    }

    $chosen   = count($formatB) > count($formatA) ? 'B' : 'A';
    $segments = $chosen === 'B' ? $formatB : $formatA;
    return ['format_a' => $formatA, 'format_b' => $formatB, 'chosen' => $chosen, 'segments' => $segments];
}

/**
 * Replicates server findBestTimestampForQuestion().
 * Uses a 5-segment sliding window; requires ≥10% word overlap to return a match.
 * Returns all per-segment scores so we can show the full matrix.
 *
 * @return array [
 *   'ideal_seconds' => int|null,
 *   'ideal_idx'     => int|null,
 *   'best_score'    => float,
 *   'all_scores'    => float[],  // one per segment
 *   'search_words'  => string[],
 * ]
 */
function kcts_find_best_timestamp(string $questionText, array $options, array $segments): array {
    $stopWords = ['that','this','with','from','have','been','when','what',
                  'which','they','their','will','would','could','should','about','into','than',
                  'more','also','both','each','very','just','then','there','some','such','only',
                  'most','over','other','after','before','does','your','these','those'];

    $allText = strtolower($questionText);
    foreach ($options as $o) {
        $allText .= ' ' . strtolower(is_string($o) ? $o : ($o ?? ''));
    }

    $rawWords    = preg_split('/\W+/', $allText, -1, PREG_SPLIT_NO_EMPTY);
    $searchWords = array_values(array_unique(
        array_filter($rawWords, fn($w) => strlen($w) > 3 && !in_array($w, $stopWords, true))
    ));

    if (empty($searchWords)) {
        $allScores = array_fill(0, count($segments), 1.0);
        return ['ideal_seconds' => !empty($segments) ? $segments[0]['seconds'] : null,
                'ideal_idx' => 0, 'best_score' => 1.0,
                'all_scores' => $allScores, 'search_words' => []];
    }

    $bestScore = -1.0;
    $bestIdx   = null;
    $allScores = [];

    for ($i = 0; $i < count($segments); $i++) {
        $windowParts = array_slice($segments, $i, min(5, count($segments) - $i));
        $windowText  = strtolower(implode(' ', array_column($windowParts, 'text')));
        $matchCount  = 0;
        foreach ($searchWords as $w) {
            if (strpos($windowText, $w) !== false) $matchCount++;
        }
        $score = $matchCount / count($searchWords);
        $allScores[] = $score;
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestIdx   = $i;
        }
    }

    return [
        'ideal_seconds' => ($bestScore >= 0.1 && $bestIdx !== null) ? $segments[$bestIdx]['seconds'] : null,
        'ideal_idx'     => $bestIdx,
        'best_score'    => $bestScore,
        'all_scores'    => $allScores,
        'search_words'  => $searchWords,
    ];
}

/** Find which segment index contains a given timestamp (bracket lookup). */
function kcts_seg_for_seconds(array $segments, int $ts): ?int {
    if (empty($segments)) return null;
    $best = 0;
    foreach ($segments as $i => $seg) {
        if ($seg['seconds'] <= $ts) $best = $i;
        else break;
    }
    return $best;
}

/** Format seconds as m:ss */
function kcts_fmt(int $s): string {
    return sprintf('%d:%02d', intdiv($s, 60), $s % 60);
}

// ══════════════════════════════════════════════════════════════════════════════
// DATA GATHERING
// ══════════════════════════════════════════════════════════════════════════════

// --- Questions ---
$db_questions = $DB->get_records('aiknowledgecheck_questions',
    ['aiknowledgecheckid' => $kc->id], 'questionnumber ASC');

// --- Transcript from sourcecontext ---
$sc_raw      = isset($kc->sourcecontext) ? $kc->sourcecontext : '';
$sc          = ($sc_raw !== '') ? json_decode($sc_raw, true) : null;
$transcript  = '';
$has_transcript = false;

if (is_array($sc) && !empty($sc['textSources'])) {
    foreach ($sc['textSources'] as $src) {
        $t = $src['text'] ?? ($src['content'] ?? '');
        if (trim($t) !== '') $transcript .= "\n" . trim($t);
    }
    $transcript = trim($transcript);
    $has_transcript = $transcript !== '';
}

// --- Parse transcript (both formats) ---
$parse_result = $has_transcript ? kcts_parse_transcript($transcript) : null;
$segments     = $parse_result ? $parse_result['segments'] : [];

// --- Video ID ---
$videoid = '';
$hasvideo = false;
if (!empty($kc->videourl)) {
    foreach (['/[?&]v=([a-zA-Z0-9_-]{11})/', '/youtu\.be\/([a-zA-Z0-9_-]{11})/',
              '/embed\/([a-zA-Z0-9_-]{11})/', '/shorts\/([a-zA-Z0-9_-]{11})/'] as $rx) {
        if (preg_match($rx, $kc->videourl, $m)) { $videoid = $m[1]; $hasvideo = true; break; }
    }
}

// --- Per-question analysis ---
$q_analysis = [];
foreach ($db_questions as $q) {
    $options = [$q->answer1 ?? '', $q->answer2 ?? '', $q->answer3 ?? '', $q->answer4 ?? ''];
    $stored_ts = (isset($q->timestamp_seconds) && $q->timestamp_seconds !== null)
                  ? (int)$q->timestamp_seconds : null;

    $algo = kcts_find_best_timestamp($q->questiontext, $options, $segments);

    $stored_seg_idx = ($stored_ts !== null && !empty($segments))
                       ? kcts_seg_for_seconds($segments, $stored_ts) : null;
    $ideal_seg_idx  = $algo['ideal_idx'];

    $seg_delta = ($stored_seg_idx !== null && $ideal_seg_idx !== null)
                  ? $stored_seg_idx - $ideal_seg_idx : null;
    $sec_delta = ($stored_ts !== null && $algo['ideal_seconds'] !== null)
                  ? $stored_ts - $algo['ideal_seconds'] : null;

    $q_analysis[(int)$q->id] = [
        'q'             => $q,
        'stored_ts'     => $stored_ts,
        'stored_seg'    => $stored_seg_idx,
        'algo'          => $algo,
        'ideal_ts'      => $algo['ideal_seconds'],
        'ideal_seg'     => $ideal_seg_idx,
        'seg_delta'     => $seg_delta,
        'sec_delta'     => $sec_delta,
        'is_pos_based'  => ($algo['best_score'] < 0.1 && count($segments) > 0),
    ];
}

// ══════════════════════════════════════════════════════════════════════════════
// COMPUTE OVERALL VERDICT
// ══════════════════════════════════════════════════════════════════════════════
$issues = [];
if (!$has_transcript)                         $issues[] = 'No transcript stored — algorithm simulation impossible.';
if ($has_transcript && empty($segments))      $issues[] = 'No transcript segments parsed — transcript has no timestamps.';

$misaligned  = 0;
$pos_based   = 0;
$null_stored = 0;
$prev_ts     = -1;
$non_asc     = [];
$duplicates  = [];
$ts_seen     = [];

foreach ($q_analysis as $a) {
    $qn = $a['q']->questionnumber;
    if ($a['stored_ts'] === null) { $null_stored++; continue; }
    if ($a['stored_ts'] < $prev_ts) $non_asc[] = "Q{$qn}";
    $prev_ts = $a['stored_ts'];
    if (isset($ts_seen[$a['stored_ts']])) $duplicates[] = kcts_fmt($a['stored_ts']).'s';
    $ts_seen[$a['stored_ts']] = true;

    if ($a['is_pos_based']) $pos_based++;
    if ($a['seg_delta'] !== null && abs($a['seg_delta']) >= 2) $misaligned++;
    elseif ($a['sec_delta'] !== null && abs($a['sec_delta']) >= 10) $misaligned++;
}
if (!empty($non_asc))   $issues[] = 'Non-ascending timestamps: ' . implode(', ', $non_asc);
if (!empty($duplicates))$issues[] = 'Duplicate timestamps at: ' . implode(', ', array_unique($duplicates));
if ($pos_based > 0)     $issues[] = "$pos_based question(s) got position-based fallback (keyword match failed — evenly distributed).";
if ($misaligned > 0)    $issues[] = "$misaligned question(s) stored timestamp is ≥2 segments off the algorithm ideal.";
if ($null_stored > 0)   $issues[] = "$null_stored question(s) have timestamp_seconds = NULL.";

$overall_ok = empty($issues);

// ══════════════════════════════════════════════════════════════════════════════
// HTML OUTPUT
// ══════════════════════════════════════════════════════════════════════════════
$plugin_ver = '';
try { include(__DIR__ . '/version.php'); $plugin_ver = $plugin->release ?? ''; } catch(Exception $e) {}

echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
   . '<title>KC Timestamp Diag — cmid ' . $cmid . '</title>' . $css . '</head><body>';
echo '<div class="page-wrap">';
echo '<h1>AI Knowledge Check — Timestamp Accuracy Diagnostic</h1>';
echo '<p class="meta">';
echo 'Activity: <strong>' . htmlspecialchars($kc->name) . '</strong> &nbsp;|&nbsp; ';
echo 'cmid: <strong>' . $cmid . '</strong> &nbsp;|&nbsp; ';
echo 'Plugin: <strong>' . htmlspecialchars($plugin_ver) . '</strong> &nbsp;|&nbsp; ';
echo 'Video ID: <strong>' . ($hasvideo ? htmlspecialchars($videoid) : '(none)') . '</strong> &nbsp;|&nbsp; ';
echo 'Questions: <strong>' . count($db_questions) . '</strong> &nbsp;|&nbsp; ';
echo 'Segments: <strong>' . count($segments) . '</strong> &nbsp;|&nbsp; ';
echo 'Generated: <strong>' . date('Y-m-d H:i:s T') . '</strong>';
echo '</p>';

// Summary
if ($overall_ok) {
    echo '<div class="summary pass"><strong>ALL TIMESTAMP CHECKS PASSED.</strong> '
       . 'Every stored timestamp matches the algorithm\'s ideal segment. '
       . 'The seek targets are accurate.</div>';
} else {
    echo '<div class="summary fail"><strong>TIMESTAMP ACCURACY ISSUES DETECTED:</strong><ul style="margin:.4rem 0 0 1.2rem;padding:0">';
    foreach ($issues as $iss) echo '<li>' . htmlspecialchars($iss) . '</li>';
    echo '</ul></div>';
}

// ── SECTION 1: Activity config ───────────────────────────────────────────────
echo '<h2>1. Activity configuration</h2>';
echo '<table class="diag">';
$chap = isset($kc->showchapterstamps) ? (int)$kc->showchapterstamps : null;
echo '<tr><td class="lbl">Show chapter timestamp links</td>'
   . '<td class="status ' . ($chap ? 'pass' : 'fail') . '">' . ($chap ? 'ON' : 'OFF') . '</td>'
   . '<td class="val">' . ($chap ? 'showchapterstamps=1 — "Jump to X:XX" buttons will be rendered'
       : 'showchapterstamps=0 — buttons are disabled; timestamps stored but never shown') . '</td></tr>';
echo '<tr><td class="lbl">Video URL</td>'
   . '<td class="status ' . ($hasvideo ? 'pass' : 'fail') . '">' . ($hasvideo ? 'OK' : 'NONE') . '</td>'
   . '<td class="val">' . htmlspecialchars(!empty($kc->videourl) ? $kc->videourl : '(not set)') . '</td></tr>';
if ($hasvideo) {
    echo '<tr><td class="lbl">YouTube ID (parsed)</td><td class="status pass">PASS</td>'
       . '<td class="val"><a href="https://www.youtube.com/watch?v=' . htmlspecialchars($videoid)
       . '" target="_blank">' . htmlspecialchars($videoid) . '</a> — '
       . '<a href="https://www.youtube.com/watch?v=' . htmlspecialchars($videoid)
       . '&cc_load_policy=1" target="_blank">Open with captions →</a></td></tr>';
}
echo '</table>';

// ── SECTION 2: Stored questions ──────────────────────────────────────────────
echo '<h2>2. Stored questions — raw DB data</h2>';
if (empty($db_questions)) {
    echo '<p class="no-data">No questions generated for this activity.</p>';
} else {
    echo '<table class="segs">';
    echo '<tr><th>#</th><th>Stored timestamp</th><th>Question text (truncated)</th>'
       . '<th>Answer 1</th><th>Answer 2</th><th>Answer 3</th><th>Answer 4</th><th>Correct</th></tr>';
    foreach ($db_questions as $q) {
        $ts = (isset($q->timestamp_seconds) && $q->timestamp_seconds !== null)
               ? '<span class="ts-chip">' . kcts_fmt((int)$q->timestamp_seconds) . '</span> ' . $q->timestamp_seconds . 's'
               : '<span style="color:#c0392b">NULL</span>';
        echo '<tr>'
           . '<td>' . $q->questionnumber . '</td>'
           . '<td style="white-space:nowrap">' . $ts . '</td>'
           . '<td>' . htmlspecialchars(mb_substr($q->questiontext, 0, 90)) . '…</td>'
           . '<td style="font-size:.78rem">' . htmlspecialchars(mb_substr($q->answer1 ?? '', 0, 50)) . '</td>'
           . '<td style="font-size:.78rem">' . htmlspecialchars(mb_substr($q->answer2 ?? '', 0, 50)) . '</td>'
           . '<td style="font-size:.78rem">' . htmlspecialchars(mb_substr($q->answer3 ?? '', 0, 50)) . '</td>'
           . '<td style="font-size:.78rem">' . htmlspecialchars(mb_substr($q->answer4 ?? '', 0, 50)) . '</td>'
           . '<td>' . ($q->correctanswer ?? '?') . '</td>'
           . '</tr>';
    }
    echo '</table>';
}

// ── SECTION 3: Transcript parsing — Format A vs B ───────────────────────────
echo '<h2>3. Transcript parsing — Format A (line-start) vs Format B (inline)</h2>';
if (!$has_transcript) {
    echo '<div class="summary warn"><strong>No transcript found in sourcecontext.</strong> '
       . 'This activity was generated from topics or PDFs only. The server cannot use '
       . 'FALLBACK-TIMESTAMPS because there is no stored transcript to match against. '
       . 'Chapter stamp timestamps come entirely from the AI\'s initial response — if those '
       . 'are wrong, the only fix is to re-generate with a pasted YouTube transcript.</div>';
} else {
    $len_a = count($parse_result['format_a']);
    $len_b = count($parse_result['format_b']);
    $chosen = $parse_result['chosen'];

    echo '<table class="diag">';
    echo '<tr><td class="lbl">Transcript length</td><td class="status info">INFO</td>'
       . '<td class="val">' . mb_strlen($transcript) . ' characters</td></tr>';

    // Format mix check
    $has_hhmmss = (bool)preg_match('/\b\d{1,2}:\d{2}:\d{2}\b/', $transcript);
    $has_mmss   = (bool)preg_match('/\b\d{1,2}:\d{2}\b/', $transcript);
    if ($has_hhmmss && $has_mmss) {
        echo '<tr><td class="lbl">Timestamp format</td><td class="status fail">FAIL</td>'
           . '<td class="val"><strong>MIXED: transcript contains both MM:SS and HH:MM:SS markers.</strong> '
           . 'The two-group regex (\d{1,2}:\d{2}) interprets "1:23:45" as "1:23" (83 s) instead of 5025 s. '
           . 'All HH:MM:SS timestamps will be placed ~82+ minutes too early. '
           . 'FIX: re-paste the transcript using one consistent format throughout.</td></tr>';
    } elseif ($has_hhmmss) {
        echo '<tr><td class="lbl">Timestamp format</td><td class="status info">INFO</td>'
           . '<td class="val">All HH:MM:SS — consistent. Video is longer than 60 minutes.</td></tr>';
    } else {
        echo '<tr><td class="lbl">Timestamp format</td><td class="status pass">PASS</td>'
           . '<td class="val">All MM:SS — consistent, no format ambiguity.</td></tr>';
    }

    echo '<tr><td class="lbl">Format A segments (line-start)</td>'
       . '<td class="status ' . ($len_a > 0 ? 'pass' : 'info') . '">' . $len_a . '</td>'
       . '<td class="val">Each line begins with a timestamp then text (YouTube transcript panel format)</td></tr>';
    echo '<tr><td class="lbl">Format B segments (inline)</td>'
       . '<td class="status ' . ($len_b > 0 ? 'pass' : 'info') . '">' . $len_b . '</td>'
       . '<td class="val">Timestamps appear inline within a paragraph (YouTube auto-captions copy-paste format)</td></tr>';
    echo '<tr><td class="lbl">Parser chosen by server</td>'
       . '<td class="status info">FORMAT ' . $chosen . '</td>'
       . '<td class="val">Server picks whichever format yields <strong>more segments</strong> (finer-grained = better matching). '
       . ($chosen === 'B'
           ? "Format B won ($len_b > $len_a) — inline timestamps used. "
           : "Format A won ($len_a ≥ $len_b) — line-start timestamps used. ")
       . ($len_a === 0 && $len_b === 0 ? '<strong>WARNING: ZERO segments parsed — the transcript has no timestamp markers.</strong>' : '')
       . '</td></tr>';

    if ($len_a === 0 && $len_b === 0) {
        echo '<tr><td class="lbl">Timestamp markers found</td><td class="status fail">FAIL</td>'
           . '<td class="val">No MM:SS or HH:MM:SS patterns found in the stored transcript text. '
           . 'Without timestamp markers the server has no segments to match questions against. '
           . 'FIX: copy the transcript from YouTube\'s transcript panel (click ⋮ → "Show transcript") '
           . 'which includes time markers like "0:08 Hello and welcome…". Paste that version and regenerate.</td></tr>';
    }
    echo '</table>';

    // Transcript preview
    echo '<details style="margin-bottom:.8rem"><summary style="cursor:pointer;font-size:.82rem;color:#0070f3">Show raw transcript text (first 500 chars)</summary>';
    echo '<pre style="background:#f8f8f8;border:1px solid #e0e0e0;border-radius:4px;padding:.7rem;font-size:.78rem;white-space:pre-wrap;margin:.4rem 0">'
       . htmlspecialchars(mb_substr($transcript, 0, 500)) . (mb_strlen($transcript) > 500 ? '…' : '') . '</pre>';
    echo '</details>';
}

// ── SECTION 4: Full segment table ───────────────────────────────────────────
echo '<h2>4. All parsed transcript segments — the seek targets available to the AI</h2>';
if (empty($segments)) {
    echo '<p class="no-data">No segments parsed — see Section 3.</p>';
} else {
    // Build a set of stored timestamps and ideal timestamps for highlighting
    $stored_ts_set = [];
    $ideal_ts_set  = [];
    foreach ($q_analysis as $a) {
        if ($a['stored_ts']  !== null) $stored_ts_set[$a['stored_seg']]  = true;
        if ($a['ideal_ts']   !== null) $ideal_ts_set[$a['ideal_seg']]    = true;
    }

    echo '<p style="font-size:.8rem;margin:.3rem 0 .5rem"><span style="display:inline-block;width:12px;height:12px;background:#d4edda;border:1px solid #9ae6b4;border-radius:2px;margin-right:4px;vertical-align:middle"></span>Algorithm ideal &nbsp;'
       . '<span style="display:inline-block;width:12px;height:12px;background:#fff0c0;border:1px solid #f0d090;border-radius:2px;margin-right:4px;vertical-align:middle"></span>Stored in DB &nbsp;'
       . '<span style="display:inline-block;width:12px;height:12px;background:#c8e6c9;border:1px solid #9ae6b4;border-radius:2px;margin-right:4px;vertical-align:middle"></span>Both ideal & stored</p>';

    echo '<table class="segs">';
    echo '<tr><th>Idx</th><th>Timestamp</th><th>Seconds</th><th>Segment text</th></tr>';
    foreach ($segments as $i => $seg) {
        $is_stored = isset($stored_ts_set[$i]);
        $is_ideal  = isset($ideal_ts_set[$i]);
        $row_class = '';
        if ($is_stored && $is_ideal) $row_class = 'seg-both';
        elseif ($is_stored)          $row_class = 'seg-stored';
        elseif ($is_ideal)           $row_class = 'seg-ideal';

        echo '<tr' . ($row_class ? ' class="' . $row_class . '"' : '') . '>'
           . '<td>' . $i . '</td>'
           . '<td><span class="ts-chip">' . kcts_fmt($seg['seconds']) . '</span></td>'
           . '<td>' . $seg['seconds'] . '</td>'
           . '<td>' . htmlspecialchars(mb_substr($seg['text'], 0, 120)) . (mb_strlen($seg['text']) > 120 ? '…' : '') . '</td>'
           . '</tr>';
    }
    echo '</table>';
}

// ── SECTION 5: Per-question alignment analysis ───────────────────────────────
echo '<h2>5. Per-question alignment — stored timestamp vs algorithm ideal</h2>';

if (empty($q_analysis)) {
    echo '<p class="no-data">No questions to analyse.</p>';
} elseif (empty($segments)) {
    echo '<div class="summary warn">No transcript segments available — cannot run algorithm simulation. '
       . 'Stored timestamps came from the initial AI generation only (no server-side fallback possible). '
       . 'To diagnose accuracy: re-generate with a YouTube transcript pasted as the text source.</div>';
} else {
    foreach ($q_analysis as $a) {
        $q       = $a['q'];
        $qn      = $q->questionnumber;
        $stored  = $a['stored_ts'];
        $ideal   = $a['ideal_ts'];
        $sdelta  = $a['sec_delta'];
        $segdelta= $a['seg_delta'];
        $score   = $a['algo']['best_score'];
        $pos     = $a['is_pos_based'];

        // Determine card state
        if ($stored === null) {
            $card_class = 'bad';
            $headline   = "Q{$qn} — NULL timestamp — no seek target stored";
        } elseif ($pos) {
            $card_class = 'warn';
            $headline   = "Q{$qn} — Position-based fallback (keyword score " . number_format($score*100, 0) . "% < 10% threshold)";
        } elseif ($segdelta !== null && abs($segdelta) >= 2) {
            $card_class = 'bad';
            $headline   = "Q{$qn} — " . abs($segdelta) . " segment(s) " . ($segdelta > 0 ? 'too late' : 'too early')
                        . " — stored " . ($stored !== null ? kcts_fmt($stored).'('.$stored.'s)' : 'NULL')
                        . " vs ideal " . ($ideal !== null ? kcts_fmt($ideal).'('.$ideal.'s)' : 'N/A');
        } elseif ($segdelta !== null && abs($segdelta) === 1) {
            $card_class = 'warn';
            $headline   = "Q{$qn} — Off-by-one segment (" . ($segdelta > 0 ? 'one segment too late' : 'one segment too early')
                        . ") — stored " . ($stored !== null ? kcts_fmt($stored).'('.$stored.'s)' : 'NULL')
                        . " vs ideal " . ($ideal !== null ? kcts_fmt($ideal).'('.$ideal.'s)' : 'N/A');
        } elseif ($sdelta !== null && abs($sdelta) <= 5) {
            $card_class = 'ok';
            $headline   = "Q{$qn} — Accurate (Δ" . ($sdelta >= 0 ? '+' : '') . "{$sdelta}s, same segment)";
        } else {
            $card_class = 'ok';
            $headline   = "Q{$qn} — OK (Δ" . ($sdelta !== null ? ($sdelta >= 0 ? '+' : '') . $sdelta . 's' : '?') . ")";
        }

        echo '<div class="qcard">';
        echo '<div class="qcard-head ' . $card_class . '">';

        // Status badge
        if ($card_class === 'ok')   echo '<span class="badge p">ACCURATE</span>';
        elseif ($card_class === 'warn') echo '<span class="badge w">WARN</span>';
        else                            echo '<span class="badge f">WRONG SEGMENT</span>';

        echo htmlspecialchars($headline);
        echo '</div>';
        echo '<div class="qcard-body">';

        // Question text
        echo '<p style="margin:.3rem 0 .5rem;font-weight:500">"' . htmlspecialchars(mb_substr($q->questiontext, 0, 200)) . '"</p>';

        // Keywords used by the algorithm
        $words = $a['algo']['search_words'];
        if (!empty($words)) {
            // Which words actually hit in the ideal segment window?
            $ideal_window_text = '';
            if ($a['ideal_seg'] !== null) {
                $window = array_slice($segments, $a['ideal_seg'], min(5, count($segments) - $a['ideal_seg']));
                $ideal_window_text = strtolower(implode(' ', array_column($window, 'text')));
            }
            echo '<div class="kw-list">';
            foreach ($words as $w) {
                $hit = $ideal_window_text !== '' && strpos($ideal_window_text, $w) !== false;
                echo '<span class="kw ' . ($hit ? 'hit' : 'miss') . '">' . htmlspecialchars($w) . '</span>';
            }
            echo '</div>';
            echo '<p style="font-size:.77rem;color:#666;margin:.1rem 0 .4rem">'
               . count(array_filter($words, fn($w) => $ideal_window_text !== '' && strpos($ideal_window_text, $w) !== false))
               . '/' . count($words) . ' keywords matched in ideal segment window (score: '
               . number_format($score*100, 0) . '%)'
               . ($pos ? ' — <strong style="color:#7a6000">below 10% threshold → position-based fallback triggered</strong>' : '')
               . '</p>';
        }

        // Score visualisation for stored vs ideal
        if ($stored !== null && $ideal !== null && $a['stored_seg'] !== null && $a['ideal_seg'] !== null) {
            $score_stored_seg = $a['algo']['all_scores'][$a['stored_seg']] ?? 0.0;
            $score_ideal_seg  = $a['algo']['all_scores'][$a['ideal_seg']] ?? 0.0;
            $bar_ideal  = max(4, (int)($score_ideal_seg  * 200));
            $bar_stored = max(4, (int)($score_stored_seg * 200));

            echo '<div style="display:flex;gap:1.5rem;margin:.4rem 0">';

            // Stored
            echo '<div style="flex:1">';
            echo '<div class="ctx-label" style="color:#c07010">Stored in DB — what the student sees</div>';
            echo '<div class="score-row"><div class="score-bar stored" style="width:' . $bar_stored . 'px"></div>'
               . '<span style="color:#c07010">' . number_format($score_stored_seg*100,0) . '% match @ segment ' . $a['stored_seg'] . '</span></div>';
            $stored_seg_text = $segments[$a['stored_seg']]['text'] ?? '';
            echo '<div class="ctx-block stored">'
               . '<span class="ts-chip">' . kcts_fmt($stored) . '</span> '
               . htmlspecialchars(mb_substr($stored_seg_text, 0, 150)) . (mb_strlen($stored_seg_text) > 150 ? '…' : '')
               . '</div>';
            echo '</div>';

            // Ideal
            echo '<div style="flex:1">';
            echo '<div class="ctx-label" style="color:#276749">Algorithm ideal — where it should seek</div>';
            echo '<div class="score-row"><div class="score-bar ideal" style="width:' . $bar_ideal . 'px"></div>'
               . '<span style="color:#276749">' . number_format($score_ideal_seg*100,0) . '% match @ segment ' . $a['ideal_seg'] . '</span></div>';
            $ideal_seg_text = $segments[$a['ideal_seg']]['text'] ?? '';
            echo '<div class="ctx-block ideal">'
               . '<span class="ts-chip">' . kcts_fmt($ideal) . '</span> '
               . htmlspecialchars(mb_substr($ideal_seg_text, 0, 150)) . (mb_strlen($ideal_seg_text) > 150 ? '…' : '')
               . '</div>';
            echo '</div>';
            echo '</div>';

            // Delta summary
            $abs_sec  = abs($sdelta ?? 0);
            $abs_seg  = abs($segdelta ?? 0);
            $dc = $abs_sec <= 3 ? 'ok' : ($abs_sec <= 15 ? 'close' : 'far');
            echo '<p style="margin:.4rem 0 0;font-size:.82rem">'
               . 'Delta: <span class="delta ' . $dc . '">'
               . ($sdelta !== null ? ($sdelta >= 0 ? '+' : '') . $sdelta . 's' : '?')
               . ' (' . ($segdelta !== null ? ($segdelta >= 0 ? '+' : '') . $segdelta . ' segment(s)' : '?') . ')'
               . '</span>';
            if ($pos) {
                echo ' &mdash; <span style="color:#7a6000"><strong>Position-based fallback:</strong> '
                   . 'keyword matching scored below 10% threshold so the server distributed timestamps '
                   . 'evenly by question position. The stored timestamp is mathematically placed, not '
                   . 'semantically matched to this question\'s topic.</span>';
            } elseif ($abs_seg >= 2) {
                echo ' &mdash; <span style="color:#721c24">The stored timestamp is '
                   . $abs_seg . ' segment(s) '
                   . ($segdelta > 0 ? 'ahead of' : 'behind') . ' the best-match segment. '
                   . 'Student clicks "Jump to ' . kcts_fmt($stored) . '" but the relevant content '
                   . 'is at ' . kcts_fmt($ideal) . '.</span>';
            } elseif ($abs_seg === 1) {
                echo ' &mdash; <span style="color:#664d03">Off by one segment — '
                   . 'the seek lands ' . ($segdelta > 0 ? 'just after' : 'just before') . ' the relevant moment.</span>';
            }
            echo '</p>';

        } elseif ($stored !== null && $a['stored_seg'] !== null && ($ideal === null || $pos)) {
            // Position-based or no ideal match: still show the stored segment context so the
            // teacher can see exactly what video moment the student's seek lands on.
            $score_stored_seg = $a['algo']['all_scores'][$a['stored_seg']] ?? 0.0;
            $bar_stored = max(4, (int)($score_stored_seg * 200));
            echo '<div style="margin:.4rem 0">';
            echo '<div class="ctx-label" style="color:#c07010">Stored in DB — what the student sees (seek target)</div>';
            echo '<div class="score-row"><div class="score-bar stored" style="width:' . $bar_stored . 'px"></div>'
               . '<span style="color:#c07010">' . number_format($score_stored_seg*100,0) . '% keyword match @ segment ' . $a['stored_seg'] . '</span></div>';
            $stored_seg_text = $segments[$a['stored_seg']]['text'] ?? '';
            echo '<div class="ctx-block stored">'
               . '<span class="ts-chip">' . kcts_fmt($stored) . '</span> '
               . htmlspecialchars(mb_substr($stored_seg_text, 0, 200)) . (mb_strlen($stored_seg_text) > 200 ? '…' : '')
               . '</div>';
            echo '<p style="font-size:.8rem;color:#7a6000;margin:.3rem 0 0"><strong>No algorithm ideal available</strong> — '
               . 'keyword score (' . number_format($a['algo']['best_score']*100, 0) . '%) fell below the 10% threshold so '
               . 'the server assigned this timestamp by position (evenly distributed). '
               . 'The seek target shown above is what was stored; it may not align with the question topic.</p>';
            echo '</div>';
        } elseif ($stored === null) {
            echo '<div class="summary warn" style="margin:.4rem 0">No timestamp stored (NULL). '
               . 'The "Jump to" link will not appear for this question. '
               . 'Run Generate/Regenerate with a timestamped transcript to assign a timestamp.</div>';
        }

        echo '</div></div>'; // qcard-body + qcard
    }
}

// ── SECTION 6: Top-5 best segments per question (score matrix extract) ───────
echo '<h2>6. Top-5 scoring segments per question — where the algorithm found the best match</h2>';
if (empty($q_analysis) || empty($segments)) {
    echo '<p class="no-data">Requires questions and transcript segments.</p>';
} else {
    foreach ($q_analysis as $a) {
        $q    = $a['q'];
        $qn   = $q->questionnumber;
        $scores = $a['algo']['all_scores'];
        if (empty($scores)) { echo '<p class="no-data">Q' . $qn . ': no scores (no search words).</p>'; continue; }

        // Sort descending
        arsort($scores);
        $top5 = array_slice($scores, 0, 5, true);

        echo '<details style="margin-bottom:.5rem">';
        echo '<summary style="cursor:pointer;font-size:.83rem;color:#0070f3;padding:.3rem 0">';
        echo 'Q' . $qn . ' — "' . htmlspecialchars(mb_substr($q->questiontext, 0, 80)) . '…" — '
           . 'top ' . count($top5) . ' scoring segments</summary>';
        echo '<table class="segs" style="margin-top:.3rem">';
        echo '<tr><th>Seg idx</th><th>Timestamp</th><th>Score</th><th>Status</th><th>Segment text (first 100 chars)</th></tr>';
        foreach ($top5 as $idx => $sc) {
            $seg      = $segments[$idx] ?? null;
            $is_ideal = ($idx === $a['ideal_seg']);
            $is_stored_seg = ($a['stored_seg'] !== null && $idx === $a['stored_seg']);
            $row_class = $is_ideal && $is_stored_seg ? 'seg-both'
                       : ($is_ideal ? 'seg-ideal' : ($is_stored_seg ? 'seg-stored' : ''));
            $status = $is_ideal && $is_stored_seg ? '✓ ideal + stored'
                    : ($is_ideal ? '✓ ideal' : ($is_stored_seg ? '← stored' : ''));
            echo '<tr' . ($row_class ? ' class="' . $row_class . '"' : '') . '>'
               . '<td>' . $idx . '</td>'
               . '<td><span class="ts-chip">' . ($seg ? kcts_fmt($seg['seconds']) : '?') . '</span></td>'
               . '<td>' . number_format($sc*100, 1) . '%</td>'
               . '<td style="white-space:nowrap">' . htmlspecialchars($status) . '</td>'
               . '<td>' . htmlspecialchars(mb_substr($seg['text'] ?? '', 0, 100)) . '</td>'
               . '</tr>';
        }
        echo '</table></details>';
    }
}

// ── SECTION 7: Non-ascending order & duplicates ──────────────────────────────
echo '<h2>7. Timestamp ordering and uniqueness checks</h2>';
echo '<table class="diag">';
$prev_ts2 = -1;
$any_order_fail = false;
foreach ($q_analysis as $a) {
    $qn = $a['q']->questionnumber;
    if ($a['stored_ts'] === null) {
        echo '<tr><td class="lbl">Q' . $qn . ' ordering</td><td class="status info">N/A</td>'
           . '<td class="val">timestamp_seconds is NULL</td></tr>';
        continue;
    }
    $ts = $a['stored_ts'];
    if ($ts < $prev_ts2) {
        echo '<tr><td class="lbl">Q' . $qn . ' ordering</td><td class="status fail">FAIL</td>'
           . '<td class="val"><strong>OUT OF ORDER:</strong> Q' . $qn . ' timestamp ' . kcts_fmt($ts) . ' (' . $ts . 's) '
           . 'is BEFORE previous question\'s ' . kcts_fmt($prev_ts2) . ' (' . $prev_ts2 . 's). '
           . 'Questions should progress forward through the video.</td></tr>';
        $any_order_fail = true;
    } else {
        echo '<tr><td class="lbl">Q' . $qn . ' ordering</td><td class="status pass">PASS</td>'
           . '<td class="val">' . kcts_fmt($ts) . ' (' . $ts . 's) — ascending ✓</td></tr>';
    }
    $prev_ts2 = $ts;
}
if (!$any_order_fail) {
    echo '<tr><td class="lbl">Overall order</td><td class="status pass">PASS</td>'
       . '<td class="val">All timestamps are in ascending order.</td></tr>';
}
// Duplicates
$ts_dup = [];
foreach ($q_analysis as $a) {
    if ($a['stored_ts'] === null) continue;
    $ts_dup[$a['stored_ts']][] = $a['q']->questionnumber;
}
foreach ($ts_dup as $ts => $qnums) {
    if (count($qnums) > 1) {
        echo '<tr><td class="lbl">Duplicate at ' . kcts_fmt($ts) . '</td><td class="status fail">FAIL</td>'
           . '<td class="val">Questions ' . implode(', ', $qnums) . ' all seek to the same position ('
           . kcts_fmt($ts) . '). Only one question covers this moment correctly; the rest are wrong by '
           . 'the distance to the next distinct segment.</td></tr>';
    }
}
echo '</table>';

// ── SECTION 8: AMD seekTo integrity ─────────────────────────────────────────
echo '<h2>8. AMD seekTo integrity — is the seek call coded correctly?</h2>';
echo '<table class="diag">';
foreach (['source' => __DIR__ . '/amd/src/knowledgecheck.js',
          'build'  => __DIR__ . '/amd/build/knowledgecheck.min.js'] as $label => $path) {
    if (!file_exists($path)) {
        echo '<tr><td class="lbl">File (' . $label . ')</td><td class="status info">MISSING</td>'
           . '<td class="val">' . htmlspecialchars($path) . '</td></tr>';
        continue;
    }
    $src = file_get_contents($path);

    // Check parseInt
    $has_parseInt = strpos($src, 'parseInt(q.timestamp_seconds') !== false
                 || strpos($src, 'var stampSecs = parseInt') !== false
                 || strpos($src, 'parseInt(stampSecs') !== false;
    echo '<tr><td class="lbl">parseInt() coercion (' . $label . ')</td>'
       . '<td class="status ' . ($has_parseInt ? 'pass' : 'fail') . '">' . ($has_parseInt ? 'PASS' : 'FAIL') . '</td>'
       . '<td class="val">' . ($has_parseInt
           ? 'parseInt() applied before seekTo — integer seconds confirmed.'
           : 'parseInt() NOT found near seekTo. Float strings could cause keyframe-only seek on some YT API versions.') . '</td></tr>';

    // seekTo(x, true)
    if (preg_match('/seekTo\s*\([^,]+,\s*(true|false)\s*\)/', $src, $sm)) {
        $allow = $sm[1] === 'true';
        echo '<tr><td class="lbl">seekTo allowSeekAhead (' . $label . ')</td>'
           . '<td class="status ' . ($allow ? 'pass' : 'fail') . '">' . ($allow ? 'true' : 'false') . '</td>'
           . '<td class="val">' . ($allow
               ? 'seekTo(x, true) — precise seek to exact second enabled.'
               : 'seekTo(x, false) — keyframe-only seek. On long videos this can land 5–10 s off the intended position. FIX: change to seekTo(stampSecs, true).') . '</td></tr>';
    }

    // playVideo after seekTo
    $seekPos = strpos($src, 'seekTo(');
    if ($seekPos !== false) {
        $win = substr($src, $seekPos, 250);
        $has_play = strpos($win, 'playVideo') !== false;
        echo '<tr><td class="lbl">playVideo() after seekTo (' . $label . ')</td>'
           . '<td class="status ' . ($has_play ? 'pass' : 'info') . '">' . ($has_play ? 'PASS' : 'INFO') . '</td>'
           . '<td class="val">' . ($has_play
               ? 'playVideo() called after seekTo — video resumes from the sought position.'
               : 'playVideo() not found within 250 chars of seekTo. Seek moves the playhead but video stays paused.') . '</td></tr>';
    }
}
echo '</table>';

// ── SECTION 9: Recommendations ───────────────────────────────────────────────
echo '<h2>9. Recommendations — what to do next</h2>';
echo '<div class="summary ' . ($overall_ok ? 'pass' : 'warn') . '">';
if (!$has_transcript) {
    echo '<strong>ROOT CAUSE: No transcript stored.</strong><br>'
       . 'This activity was generated without pasting a video transcript as a text source. '
       . 'The AI cannot assign accurate timestamps without one. '
       . '<br><strong>FIX:</strong> Edit the activity, paste the YouTube transcript '
       . '(click ⋮ → "Show transcript" under the video, then "Toggle timestamps"), '
       . 'and click Generate again.';
} elseif (empty($segments)) {
    echo '<strong>ROOT CAUSE: Transcript has no timestamp markers.</strong><br>'
       . 'The stored transcript text does not contain MM:SS or HH:MM:SS markers. '
       . '<br><strong>FIX:</strong> When copying from YouTube\'s transcript panel, ensure "Toggle timestamps" '
       . 'is ON so each line starts with a timestamp (e.g. "0:08 Hello and welcome…"). '
       . 'Then re-Generate.';
} elseif (!empty($non_asc) || !empty(array_filter($q_analysis, fn($a) => $a['is_pos_based']))) {
    $pb_count = count(array_filter($q_analysis, fn($a) => $a['is_pos_based']));
    if ($pb_count > 0) {
        echo '<strong>ROOT CAUSE: ' . $pb_count . ' question(s) received position-based (evenly-distributed) timestamps</strong> '
           . 'because the keyword matching algorithm found < 10% word overlap between the question text '
           . 'and any transcript segment.<br>'
           . 'This happens when the transcript is very short, uses different vocabulary from the questions, '
           . 'or the questions were generated from a topic list rather than transcript text.<br>'
           . '<strong>FIX:</strong> Use a longer, more detailed transcript that includes the same '
           . 'terminology as the questions. Avoid generating from topic keywords only when chapter stamps are needed.';
    }
    if (!empty($non_asc)) {
        echo '<strong>ROOT CAUSE (additional): Non-ascending timestamps detected for ' . implode(', ', $non_asc) . '.</strong><br>'
           . 'Some questions reference video positions that are earlier than a previous question. '
           . '<strong>FIX:</strong> Regenerate questions — this typically resolves itself when the transcript '
           . 'has good coverage and the AI can find distinct segments for each question.';
    }
} elseif ($misaligned > 0) {
    echo '<strong>ROOT CAUSE: ' . $misaligned . ' question(s) have timestamps pointing to the wrong segment.</strong><br>'
       . 'The "Jump to" link seeks to a point in the video where the question\'s topic is not discussed.<br>'
       . '<strong>FIX:</strong> Regenerate questions. If the problem persists, the transcript may be too '
       . 'sparse (not enough distinct timestamps) — try pasting a denser timestamped transcript.';
} elseif ($null_stored > 0) {
    echo '<strong>ROOT CAUSE: ' . $null_stored . ' question(s) have NULL timestamp_seconds.</strong><br>'
       . 'See Sections 3 and 5 for the specific questions and the reason timestamps were not assigned.<br>'
       . '<strong>FIX:</strong> Regenerate questions with a timestamped transcript pasted.';
} else {
    echo '<strong>All timestamp checks passed.</strong> Stored timestamps match the algorithm\'s best-match '
       . 'segments. If students still report seek inaccuracy, check Section 8 (seekTo integrity) and '
       . 'consider whether the YouTube video itself has a playback offset.';
}
echo '</div>';

// Plugin version + back link
echo '<p style="font-size:.78rem;color:#888;margin-top:1rem">Diagnostic tool built into mod_aiknowledgecheck v' . ($plugin_ver ?: '?') . '. '
   . 'To run: /mod/aiknowledgecheck/timestamp_diag.php?cmid=' . $cmid . '</p>';
echo '<p><a class="back" href="' . (new moodle_url('/mod/aiknowledgecheck/diag.php', ['cmid' => $cmid]))->out() . '">&larr; Main diag</a> &nbsp;|&nbsp; ';
echo '<a class="back" href="' . (new moodle_url('/mod/aiknowledgecheck/view.php', ['id' => $cmid]))->out() . '">&larr; Back to activity</a></p>';
echo '</div></body></html>';
