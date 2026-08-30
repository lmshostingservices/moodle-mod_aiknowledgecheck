<?php
// phpcs:disable moodle.Files.LineLength
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
 * AI Knowledge Check upgrade steps.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin to a newer version.
 *
 * @param int $oldversion The old version of the plugin.
 * @return bool
 */
function xmldb_aiknowledgecheck_upgrade($oldversion) {
    global $DB, $CFG;

    $dbman = $DB->get_manager();

    // Add attempt management tables and fields for version 1.2.0.
    if ($oldversion < 2025120102) {
        // Add maxattempts field to knowledgecheck table.
        $table = new xmldb_table('aiknowledgecheck');
        $field = new xmldb_field('maxattempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'introformat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add questioncount field.
        $field = new xmldb_field('questioncount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'maxattempts');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add passinggrade field.
        $field = new xmldb_field('passinggrade', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'questioncount');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add completionallcorrect field.
        $field = new xmldb_field('completionallcorrect', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'passinggrade');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add ccemail field.
        $field = new xmldb_field('ccemail', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'completionallcorrect');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Create aiknowledgecheck_questions table.
        $table = new xmldb_table('aiknowledgecheck_questions');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('aiknowledgecheckid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('questionnumber', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('questiontext', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('answer1', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('answer2', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('answer3', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('answer4', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('correctanswer', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, null);
            $table->add_field('feedback1', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('feedback2', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('feedback3', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('feedback4', XMLDB_TYPE_TEXT, null, null, null, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('aiknowledgecheckid', XMLDB_KEY_FOREIGN, ['aiknowledgecheckid'], 'aiknowledgecheck', ['id']);
            $table->add_index('aiknowledgecheckid-questionnumber', XMLDB_INDEX_UNIQUE, ['aiknowledgecheckid', 'questionnumber']);

            $dbman->create_table($table);
        }

        // Create aiknowledgecheck_attempts table.
        $table = new xmldb_table('aiknowledgecheck_attempts');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('aiknowledgecheckid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('currentquestion', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
            $table->add_field('answers', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('correctcount', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
            $table->add_field('totalcount', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
            $table->add_field('status', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timestarted', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timeended', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('aiknowledgecheckid', XMLDB_KEY_FOREIGN, ['aiknowledgecheckid'], 'aiknowledgecheck', ['id']);
            $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $table->add_index('aiknowledgecheckid_userid', XMLDB_INDEX_NOTUNIQUE, ['aiknowledgecheckid', 'userid']);

            $dbman->create_table($table);
        }

        // Create aiknowledgecheck_overrides table.
        $table = new xmldb_table('aiknowledgecheck_overrides');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('aiknowledgecheckid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('extraattempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('aiknowledgecheckid_fk', XMLDB_KEY_FOREIGN, ['aiknowledgecheckid'], 'aiknowledgecheck', ['id']);
            $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_index('uniq_override', XMLDB_INDEX_UNIQUE, ['aiknowledgecheckid', 'userid']);

            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2025120102, 'aiknowledgecheck');
    }

    // Add audiodata field to questions table for voiceover support (v1.3.34).
    if ($oldversion < 2025120134) {
        $table = new xmldb_table('aiknowledgecheck_questions');
        $field = new xmldb_field('audiodata', XMLDB_TYPE_TEXT, null, null, null, null, null, 'feedback4');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2025120134, 'aiknowledgecheck');
    }

    // Add completionpassgrade field for grade-based completion (v1.3.47).
    if ($oldversion < 2025120147) {
        $table = new xmldb_table('aiknowledgecheck');
        $field = new xmldb_field('completionpassgrade', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'completionallcorrect');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2025120147, 'aiknowledgecheck');
    }

    // Release v1.3.61: Add grade column for proper Moodle grade API integration.
    // Required for modgrade form element and standard grade-to-pass functionality.
    if ($oldversion < 2025120161) {
        $table = new xmldb_table('aiknowledgecheck');
        $field = new xmldb_field('grade', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '100', 'introformat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Backfill: ensure all existing grade items have correct gradepass from passinggrade field.
        require_once($CFG->libdir . '/gradelib.php');
        $instances = $DB->get_records('aiknowledgecheck');
        foreach ($instances as $instance) {
            if (!empty($instance->passinggrade) && (int)$instance->passinggrade > 0) {
                $gradeitem = grade_item::fetch(
                    [
                        'itemtype' => 'mod',
                        'itemmodule' => 'aiknowledgecheck',
                        'iteminstance' => $instance->id,
                        'courseid' => $instance->course,
                        'itemnumber' => 0,
                    ]
                );
                if ($gradeitem) {
                    $gradeitem->gradepass = (float)$instance->passinggrade;
                    $gradeitem->update();
                }
            }
        }

        upgrade_mod_savepoint(true, 2025120161, 'aiknowledgecheck');
    }

    // Release v1.3.74: Add voiceover settings fields to activity table for persistent voice preferences.
    if ($oldversion < 2025120174) {
        $table = new xmldb_table('aiknowledgecheck');

        $field = new xmldb_field('voiceoverenabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'ccemail');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('voicelanguage', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'en-AU', 'voiceoverenabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('voicegender', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'female', 'voicelanguage');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('voicestyle', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'Aoede', 'voicegender');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2025120174, 'aiknowledgecheck');
    }

    if ($oldversion < 2026022487) {
        $table = new xmldb_table('aiknowledgecheck');

        $field = new xmldb_field('videourl', XMLDB_TYPE_CHAR, '512', null, null, null, null, 'voicestyle');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('videorequirement', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'none', 'videourl');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('videominseconds', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'videorequirement');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026022487, 'aiknowledgecheck');
    }

    if ($oldversion < 2026072801) {
        // Release v1.3.92: Live attempts badge fix — no DB schema changes.
        upgrade_mod_savepoint(true, 2026072801, 'aiknowledgecheck');
    }

    if ($oldversion < 2026072802) {
        // Release v1.3.93 through v1.3.97: Continue-attempt resume fix, resume-from-complete
        // detection, attempts badge on results screen — no DB schema changes.
        upgrade_mod_savepoint(true, 2026072802, 'aiknowledgecheck');
    }

    if ($oldversion < 2026072803) {
        // Release v1.3.98: AMD build sync — build/knowledgecheck.js and build/knowledgecheck.min.js
        // updated to match src/knowledgecheck.js (resume-from-complete detection and
        // attempts badge on results screen were missing from build files).
        // No DB schema changes.
        upgrade_mod_savepoint(true, 2026072803, 'aiknowledgecheck');
    }

    if ($oldversion < 2026072804) {
        // Release v1.3.99: Attempts badge icon size fix.
        // updateAttemptsBadge() was using cloneNode(true) to copy the SVG icon from the
        // existing badge element. If that badge was dynamically rendered by JS (e.g. the
        // results-screen badge at line ~1885), its SVG had no width/height attributes,
        // causing the browser to render it at the SVG default of 300×150 px — making the
        // icon appear enormously enlarged and misaligning the badge layout.
        // Fix: updateAttemptsBadge() now uses innerHTML with an explicit 14×14 px SVG
        // string, guaranteeing correct icon size regardless of the source badge.
        // Also added width:14px;height:14px to .kc-attempts-badge svg in styles.css as a
        // CSS-level safety net, and added width="14" height="14" to the JS-rendered
        // results-screen badge SVG for belt-and-braces correctness.
        // No DB schema changes.
        upgrade_mod_savepoint(true, 2026072804, 'aiknowledgecheck');
    }

    if ($oldversion < 2026072805) {
        // Release v1.5.4: Audio Gate feature.
        // Add audiourl, audiorequirement, audiominseconds columns to the main table
        // so teachers can optionally require students to listen to an audio file before
        // the quiz start button is enabled — mirroring the existing Video Gate feature.
        $table = new xmldb_table('aiknowledgecheck');

        $field = new xmldb_field('audiourl', XMLDB_TYPE_CHAR, '512', null, null, null, null, 'videominseconds');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('audiorequirement', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'none', 'audiourl');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('audiominseconds', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'audiorequirement');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026072805, 'aiknowledgecheck');
    }

    if ($oldversion < 2026072806) {
        // Release v1.5.5: Performance Criteria mapping columns.
        // Add mappingtopic and mappingcriteria to aiknowledgecheck_questions so that
        // teachers can align topics with performance criteria and have both appear
        // in the downloaded Excel question-mapping export.
        $table = new xmldb_table('aiknowledgecheck_questions');

        $field = new xmldb_field('mappingtopic', XMLDB_TYPE_TEXT, null, null, null, null, null, 'audiodata');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('mappingcriteria', XMLDB_TYPE_TEXT, null, null, null, null, null, 'mappingtopic');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026072806, 'aiknowledgecheck');
    }

    if ($oldversion < 2026072807) {
        // Release v1.5.14: ETA BANNERS — Estimated Time to Complete banners for teacher + student views.
        // No DB schema changes.
        upgrade_mod_savepoint(true, 2026072807, 'aiknowledgecheck');
    }

    if ($oldversion < 2026072808) {
        // Release v1.5.15: RETRY WRONG ANSWERS — Retake shows only incorrectly answered questions.
        // No DB schema changes.
        upgrade_mod_savepoint(true, 2026072808, 'aiknowledgecheck');
    }

    // Release v1.5.16: VERSION BUMP — Maintenance release. No DB changes.
    if ($oldversion < 2026072809) {
        upgrade_mod_savepoint(true, 2026072809, 'aiknowledgecheck');
    }

    if ($oldversion < 2026072810) {
        // Release v1.5.18: Results title/message auto-fades after 3s. Token budget increased
        // to prevent AI returning fewer questions than requested. No DB changes.
        upgrade_mod_savepoint(true, 2026072810, 'aiknowledgecheck');
    }

    if ($oldversion < 2026072811) {
        // Release v1.5.19: Token budget tripled (2400 tokens/question) for generous AI
        // headroom. Removed retry/over-request complexity. No DB changes.
        upgrade_mod_savepoint(true, 2026072811, 'aiknowledgecheck');
    }

    // Release v1.5.20: Course info time estimation update — 2 min per question.
    if ($oldversion < 2026072812) {
        upgrade_mod_savepoint(true, 2026072812, 'aiknowledgecheck');
    }

    // Release v1.5.21: Attempt-grouped PDF/Text results — JS-only change; no DB schema changes.
    if ($oldversion < 2026072813) {
        upgrade_mod_savepoint(true, 2026072813, 'aiknowledgecheck');
    }

    // Release v1.5.22: Always show "Attempt N" heading in PDF/Text results, even for single-attempt quizzes.
    // Removed sub-label "X correct, Y incorrect out of Z questions". JS-only change.
    if ($oldversion < 2026072814) {
        upgrade_mod_savepoint(true, 2026072814, 'aiknowledgecheck');
    }

    // Release v1.5.23: BUG FIX — Wrong-only retry incorrectly reintroduced already-correct questions
    // from attempt 3 onwards. Root cause: retakeWrongOnly() used the log array index
    // (idx) when building wrongQuestionIndices, but after the first retry the log is
    // rebuilt with carry-forward entries prepended so log index ≠ quizData index.
    // Fix: use entry.questionNum - 1 (permanent 0-based quizData index). JS-only.
    if ($oldversion < 2026072815) {
        upgrade_mod_savepoint(true, 2026072815, 'aiknowledgecheck');
    }

    // Release v1.5.24: BUG FIX — Incorrect-answer attempts were silently missing from PDF/text
    // downloads. Root cause: startQuizWrongOnly() rebuilt quizAnswerLog with only
    // correct carry-forward entries; any incorrect entry from the previous attempt
    // (e.g. Q3 wrong in attempt 3) was discarded, leaving a gap in the exported
    // file. Fix 1 — startQuizWrongOnly(): all incorrect entries from previousLog
    // are now preserved in the rebuilt log; correct carry-forward also changed to
    // find the LATEST correct entry (reverse iteration) not just the first. Fix 2
    // — retakeWrongOnly(): now uses latest-answer-per-question logic so that
    // historical incorrect entries (now kept in the log) don't cause double-counting
    // or incorrect wrongQuestionIndices. JS-only.
    if ($oldversion < 2026072816) {
        upgrade_mod_savepoint(true, 2026072816, 'aiknowledgecheck');
    }

    // Release v1.5.25: NEW SETTING — "After Completion" dropdown. Adds aftercompletion column to
    // mdl_aiknowledgecheck. Values: 'restart' (default, student can retake) or
    // 'lock' (activity is locked after first completion, no further attempts).
    // NOTE: This block was previously placed before v1.5.24 in error, causing a
    // "cannot downgrade" exception (2026072817 → 2026072816). Correct order
    // restored in v1.5.30.
    if ($oldversion < 2026072817) {
        $table  = new xmldb_table('aiknowledgecheck');
        $field  = new xmldb_field('aftercompletion', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'restart', 'audiominseconds');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026072817, 'aiknowledgecheck');
    }

    // Release v1.5.26 – v1.5.29: JS-only fixes (retry logic, export, course-info ETA, build sync).
    // No DB schema changes. Savepoint covers all four minor releases.
    if ($oldversion < 2026072818) {
        upgrade_mod_savepoint(true, 2026072818, 'aiknowledgecheck');
    }

    // Release v1.5.30: CRITICAL FIXES —
    // (1) Savepoint order corrected: v1.5.24 (2026072816) was listed after
    // v1.5.25 (2026072817) in upgrade.php causing a "cannot downgrade"
    // exception on fresh upgrades from v1.5.23 or earlier.
    // (2) Backup/restore class files renamed: backup_knowledgecheck_*.class.php
    // → backup_aiknowledgecheck_*.class.php (and restore counterparts) so
    // Moodle's backup factory can resolve the class at module-delete time,
    // fixing 'Class backup_aiknowledgecheck_activity_task not found' adhoc
    // task failures on the recyclebin pipeline.
    // No DB schema changes.
    if ($oldversion < 2026072819) {
        upgrade_mod_savepoint(true, 2026072819, 'aiknowledgecheck');
    }

    // Release v1.5.31: VERSION BUMP — Clean release. No DB schema changes.
    if ($oldversion < 2026072820) {
        upgrade_mod_savepoint(true, 2026072820, 'aiknowledgecheck');
    }

    // Release v1.5.32: Industry & Sector dropdown unification. No DB schema changes.
    if ($oldversion < 2026072821) {
        upgrade_mod_savepoint(true, 2026072821, 'aiknowledgecheck');
    }

    // Release v1.5.33: BUG FIX — Attempts Report score column fix.
    // (1) SELECT a.*, u.* caused user table columns (id, timecreated,
    // timemodified) to silently overwrite attempt table columns,
    // corrupting timestamps in the report.
    // (2) Each attempt row now shows that attempt's actual correctcount
    // instead of the running cumulative best, so differing scores
    // across retry attempts are visible. The Best: X/Y summary in
    // the accordion header retains the true maximum.
    // No DB schema changes.
    if ($oldversion < 2026072822) {
        upgrade_mod_savepoint(true, 2026072822, 'aiknowledgecheck');
    }

    // Release v1.5.34: CRITICAL BUG FIX — Two race conditions causing incorrect scores on
    // "Retry Wrong Answers" attempts.
    // (1) preSaveCorrectAnswers (JS) fired all carry-forward saveanswer
    // calls in parallel → all read answers='{}' simultaneously →
    // last writer wins → only 1 of N carry-forward answers persisted
    // in the DB → retry attempt scores were far too low.
    // (2) finishAttempt (JS) was called immediately in showResults()
    // without waiting for any in-flight saveanswer AJAX call to
    // complete → last question's answer was sometimes not yet
    // written when finishattempt recalculated correctcount from DB.
    // Fix: preSaveCorrectAnswers now chains saves sequentially (each
    // starts only after the previous completes). finishAttempt is now
    // gated by a pendingSaves counter and deferred via pendingFinishAttempt
    // flag until all in-flight saves are done.
    // No DB schema changes.
    if ($oldversion < 2026072823) {
        upgrade_mod_savepoint(true, 2026072823, 'aiknowledgecheck');
    }

    // Release v1.5.35: BUG FIX — ETA timing and resume-from-all-complete score reconstruction. No DB schema changes.
    if ($oldversion < 2026072824) {
        upgrade_mod_savepoint(true, 2026072824, 'aiknowledgecheck');
    }

    // Release v1.5.36: LANG FIX — Added missing $string['industry_sector'] to lang/en/aiknowledgecheck.php.
    // Moodle debug mode showed "Invalid get_string() identifier: 'industry_sector'" on the
    // Knowledge Check view page whenever the Workplace Context section was displayed, because
    // view.php line 264 called get_string('industry_sector', 'mod_aiknowledgecheck') but the
    // string was absent from the lang file. No DB schema changes.
    if ($oldversion < 2026072825) {
        upgrade_mod_savepoint(true, 2026072825, 'aiknowledgecheck');
    }

    // Release v1.5.37: VERSION BUMP — Clean release increment. No code or DB schema changes.
    if ($oldversion < 2026072826) {
        upgrade_mod_savepoint(true, 2026072826, 'aiknowledgecheck');
    }

    // Release v1.5.38: BUG-KC-QPT-CAP + BUG-KC-TOKEN-FLOOR — Questions-per-topic dropdown extended
    // from 1-5 to 1-20. Token budget raised from Math.max(6000,n*600) to
    // Math.max(8000,n*900) in both topic and PDF generation modes. No DB schema changes.
    if ($oldversion < 2026072827) {
        upgrade_mod_savepoint(true, 2026072827, 'aiknowledgecheck');
    }

    // Release v1.5.39: VERSION BUMP — Clean release increment. No code or DB schema changes.
    if ($oldversion < 2026072828) {
        upgrade_mod_savepoint(true, 2026072828, 'aiknowledgecheck');
    }

    // Release v1.5.40: NEW FEATURE — "Add More Questions" button on the teacher ready screen.
    // No DB schema changes.
    if ($oldversion < 2026072829) {
        upgrade_mod_savepoint(true, 2026072829, 'aiknowledgecheck');
    }

    // Release v1.5.41: BUG FIX — Regenerate Questions double-request fix + AI parse hardening.
    // No DB schema changes.
    if ($oldversion < 2026072830) {
        upgrade_mod_savepoint(true, 2026072830, 'aiknowledgecheck');
    }

    // Release v1.5.42: VERSION BUMP — Clean release increment. No code or DB schema changes.
    if ($oldversion < 2026072831) {
        upgrade_mod_savepoint(true, 2026072831, 'aiknowledgecheck');
    }

    // Release v1.5.43: BUG FIX — Audio gate fix + Gemini responseMimeType in generationConfig. No DB schema changes.
    if ($oldversion < 2026072832) {
        upgrade_mod_savepoint(true, 2026072832, 'aiknowledgecheck');
    }

    // Release v1.5.44: VERSION BUMP — Clean release increment. No code or DB schema changes.
    if ($oldversion < 2026072833) {
        upgrade_mod_savepoint(true, 2026072833, 'aiknowledgecheck');
    }

    // Release v1.5.45: BUG FIX — Removed duplicate #regenerate-btn injected by checkExistingQuestions().
    // The view.php #ready-regenerate-btn is now the sole regenerate button. No DB schema changes.
    if ($oldversion < 2026072834) {
        upgrade_mod_savepoint(true, 2026072834, 'aiknowledgecheck');
    }

    // Release v1.5.46: BUG FIX — Audio gate not shown to teachers. Gate variable setup moved before the
    // teacher/student split so both views share the same values. Gate HTML and JS coordinator
    // added to teacher ready section; #take-quiz-btn disabled until gate is satisfied.
    // No DB schema changes.
    if ($oldversion < 2026072835) {
        upgrade_mod_savepoint(true, 2026072835, 'aiknowledgecheck');
    }

    // Release v1.5.47: VERSION BUMP — Maintenance release. No code or DB schema changes.
    if ($oldversion < 2026072836) {
        upgrade_mod_savepoint(true, 2026072836, 'aiknowledgecheck');
    }

    // Release v1.5.48: VERSION BUMP — Maintenance release. No code or DB schema changes.
    if ($oldversion < 2026072837) {
        upgrade_mod_savepoint(true, 2026072837, 'aiknowledgecheck');
    }

    // Release v1.5.49: UPGRADE FIX — Corrected upgrade.php savepoint ordering. v1.5.4 block
    // (2026072805) was mistakenly inserted AFTER v1.5.5 block (2026072806) in
    // db/upgrade.php. Sites upgrading from v1.3.99 or earlier would hit the
    // 2026072806 savepoint first (setting version to 2026072806), then attempt
    // to set 2026072805 — triggering a fatal "Cannot downgrade" error. Fixed by
    // moving the v1.5.4 block to its correct position (after v1.3.99, before v1.5.5).
    // No code, JS, CSS, or DB schema changes. version.php → 2026072838.
    if ($oldversion < 2026072838) {
        upgrade_mod_savepoint(true, 2026072838, 'aiknowledgecheck');
    }

    // Release v1.5.50 FIX-KC-GATE-TEACHER: Review Questions, Continue Attempt, and Start Quiz
    // buttons were disabled/gated for teachers when any video or audio gate was active
    // ($anygated unconditional). Teachers (has_capability 'mod/aiknowledgecheck:create')
    // must never be blocked by student-facing gates. Fix: $takegated = $anygated && !$cancreate.
    // No DB schema changes. PHP-only fix. version.php → 2026072839.
    if ($oldversion < 2026072839) {
        upgrade_mod_savepoint(true, 2026072839, 'aiknowledgecheck');
    }

    // Release v1.5.51: FIX-KC-SAVE-AUDIO-SKIP — saveEdits() in knowledgecheck.js now compares the
    // teacher's form edits against originalQuizData before calling regenerateAudioWithCallback.
    // If no question content changed (question text, options, explanations, correctAnswer all
    // identical to when the edit form was opened), the audio regeneration step is skipped.
    // Root cause: saveEdits() called regenerateAudioWithCallback unconditionally whenever
    // voiceover was enabled — burning credits on a new TTS round-trip even after a failed
    // question-regen where the teacher clicked Save Changes without editing anything.
    // No DB schema changes. AMD: knowledgecheck.js updated.
    // src=build=min triple-match MD5: 4008bb2dc5c3fc7314b5412684fe2979. version.php → 2026072840.
    if ($oldversion < 2026072840) {
        upgrade_mod_savepoint(true, 2026072840, 'aiknowledgecheck');
    }

    // Release v1.5.52: TWO FIXES. FIX-1 (FIX-KC-OPTION-CAPITALISE): Answer options occasionally started
    // with a lowercase letter (e.g. 'puck' instead of 'Puck'). Root cause: knowledgecheck.js
    // rendered raw option text without normalising case. Fix: added charAt(0).toUpperCase() +
    // slice(1) in both showQuestion() and showQuestionWrongOnly() option-render loops.
    // FIX-2 (FIX-KC-VIDEO-GATE): Video card and audio card remained visible after the student
    // clicked Start Quiz. Root cause: JS show/hide logic only toggled #kc-start-section and
    // #kc-quiz-player; the video/audio card divs had no IDs. Fix: added id='kc-video-section'
    // and id='kc-audio-section' to view.php; knowledgecheck.js hides both at both quiz-start
    // paths. No DB schema changes. AMD: knowledgecheck.js updated. PHP: view.php updated.
    // src=build=min triple-match MD5: 747cd7c02bbbc25ad2f13d565722502d. version.php → 2026072841.
    if ($oldversion < 2026072841) {
        upgrade_mod_savepoint(true, 2026072841, 'aiknowledgecheck');
    }

    // Release v1.5.53: BUG FIX (FIX-KC-VIDEO-GATE-SEQUENTIAL): Two remaining video-gate UX issues.
    // FIX-1 (INITIAL DISPLAY): The "Start Quiz" card and Estimated Time banner were always
    // visible on page load alongside the video, making the gate feel non-sequential. Fix:
    // view.php now adds style="display:none;" to both elements when $anygated is true.
    // The gate coordinator's unlock() function shows them once all gates clear.
    // FIX-2 (RETAKE GATE RESET): On retake, retakeQuiz() called handleStartAttempt()
    // immediately — the video/audio gate was never re-locked, so students could bypass
    // re-watching. Fix: retakeQuiz() now calls window.kcGate.reset() which re-locks all
    // original gates, re-hides the start section, and resets the video/audio tracker state
    // (unlocked=false, watchedSeconds=0, player.seekTo(0)). The student must re-watch
    // before the start card reappears. window.kcVideoGate and window.kcAudioGate expose
    // resetLock() functions from their respective IIFEs for this purpose.
    // AMD: knowledgecheck.js updated; src=build=min triple-match MD5: bc3877cc4313a56cee7b097742e1b020.
    // PHP: view.php updated. No DB schema changes. version.php → 2026072842.
    if ($oldversion < 2026072842) {
        upgrade_mod_savepoint(true, 2026072842, 'aiknowledgecheck');
    }

    // Release v1.5.54 - VERSION BUMP: Clean release following full tester-feedback cycle.
    // All fixes from v1.5.53 (FIX-KC-INITIAL-DISPLAY, FIX-KC-RETAKE-GATE-RESET,
    // dead kcGate removal, teacher audio IIFE null guard) confirmed in ZIP and all
    // 6 delivery locations. No code changes. AMD MD5 unchanged: bc3877cc4313a56cee7b097742e1b020.
    // No DB schema changes. version.php → 2026072843.
    if ($oldversion < 2026072843) {
        upgrade_mod_savepoint(true, 2026072843, 'aiknowledgecheck');
    }

    // Release v1.5.55 - BUG FIX (FIX-KC-SEEK-BLOCK): Non-skippable YouTube video gate.
    // Students could seek the YouTube progress bar to the end and bypass the "watch full
    // video" gate. Fix: a 500 ms polling timer (seekBlockTimer) tracks maxWatchedTime
    // and seeks the player back if a forward-skip > 1.5 s is detected. The ENDED handler
    // for 'full' requirement now validates maxWatchedTime >= (getDuration() - 5 s) before
    // unlocking. kcVideoGate.resetLock() also resets maxWatchedTime and calls
    // stopSeekBlocking() so retakes start fresh.
    // No DB schema changes. PHP-only: view.php. version.php → 2026072844.
    // ⚠ FORMAT BUG: 2026072844 is 12-digit. It is numerically LESS than v1.5.54's
    // savepoint 2026072843 (13-digit), so any site on v1.5.54 or earlier sees
    // ($oldversion < 2026072844) as FALSE and silently skips this block. No DB
    // changes here, so the skip is harmless. Fixed in v1.5.58 by using 13-digit format.
    if ($oldversion < 2026072844) {
        upgrade_mod_savepoint(true, 2026072844, 'aiknowledgecheck');
    }

    // Release v1.5.56: NEW FEATURE — "Show video while answering questions" option.
    // Adds showvideoduringquiz field (int, default 0). When enabled, the video
    // player remains visible above the quiz questions while the student answers.
    // When disabled (default), the video hides when the quiz player takes over.
    // DB: adds aiknowledgecheck.showvideoduringquiz. version.php → 2026072845.
    // ⚠ FORMAT BUG: 2026072845 is 12-digit. Same issue as v1.5.55 above — SKIPPED
    // for sites on v1.5.54 or earlier. THIS BLOCK CONTAINS A DB SCHEMA CHANGE
    // (showvideoduringquiz). Sites upgrading from v1.5.54 do NOT get this column
    // added here; it is backfilled in the v1.5.58 block below with field_exists() guards.
    if ($oldversion < 2026072845) {
        $table = new xmldb_table('aiknowledgecheck');
        $field = new xmldb_field('showvideoduringquiz', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'videominseconds');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026072845, 'aiknowledgecheck');
    }

    // Release v1.5.57: NEW FEATURE — "Show chapter timestamp links" option.
    // Adds showchapterstamps field (int, default 0). When enabled, each question
    // displays a clickable timestamp link that seeks the video to the point in the
    // transcript where the question's core concept appears. Timestamps come from
    // YouTube-style timestamps in the source content (e.g. "1:09" → 69 seconds).
    // Only visible in the video gate section (requires videourl to be set).
    // DB: adds aiknowledgecheck.showchapterstamps. version.php → 2026072846.
    // ⚠ FORMAT BUG: 2026072846 is 12-digit. Same issue as v1.5.55 above — SKIPPED
    // for sites on v1.5.54 or earlier. THIS BLOCK CONTAINS A DB SCHEMA CHANGE
    // (showchapterstamps). Backfilled in v1.5.58 below with field_exists() guards.
    if ($oldversion < 2026072846) {
        $table = new xmldb_table('aiknowledgecheck');
        $field = new xmldb_field('showchapterstamps', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'showvideoduringquiz');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026072846, 'aiknowledgecheck');
    }

    // Release v1.5.58: FIX-KC-NUMERIC-VERSION — Corrects the 12-digit savepoint format used in
    // v1.5.55/56/57. Sites upgrading from v1.5.54 (last 13-digit: 2026072843) could
    // not install v1.5.55-57 because those savepoints (2026072844, 2026072845,
    // 2026072846) are numerically LESS than 2026072843 — Moodle saw them as
    // "higher version already installed". This block uses 13-digit savepoint 2026072847
    // which IS greater than 2026072843, unblocking upgrades for all affected sites.
    // DB BACKFILL: Adds showvideoduringquiz and showchapterstamps for sites that missed
    // the v1.5.56 and v1.5.57 DB changes. field_exists() guards make the block idempotent
    // (safe to run even if the columns already exist from a fresh v1.5.55-57 install).
    // No AMD changes. version.php → 2026072847.
    if ($oldversion < 2026072847) {
        $table = new xmldb_table('aiknowledgecheck');

        $field1 = new xmldb_field('showvideoduringquiz', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'videominseconds');
        if (!$dbman->field_exists($table, $field1)) {
            $dbman->add_field($table, $field1);
        }

        $field2 = new xmldb_field('showchapterstamps', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'showvideoduringquiz');
        if (!$dbman->field_exists($table, $field2)) {
            $dbman->add_field($table, $field2);
        }

        upgrade_mod_savepoint(true, 2026072847, 'aiknowledgecheck');
    }

    // Release v1.5.59 - SERVER FIX (FIX-KC-REGEN-JSON): /api/knowledgecheck-regenerate-settings
    // was returning "Failed to parse AI response" intermittently. Root cause: the Gemini
    // generateContent call lacked config:{responseMimeType:"application/json"} — Gemini
    // occasionally wrapped its JSON array in markdown fences or prose, breaking JSON.parse.
    // Fix: added config:{responseMimeType:"application/json"} to that call in server/routes.ts.
    // No DB schema changes. PHP/JS unchanged. version.php → 2026072848.
    if ($oldversion < 2026072848) {
        upgrade_mod_savepoint(true, 2026072848, 'aiknowledgecheck');
    }

    // Release v1.5.60 - BUG FIX (FIX-KC-CORRECT-ANSWER + FIX-KC-SEEK-BLOCK + FIX-KC-START-QUIZ-VIDEO):
    // (1) Correct answer always position A in regenerated questions — AI prompts hardcoded
    // "correctAnswer":0 causing AI to always put correct answer at option A. Fix: server/routes.ts
    // now applies shuffleQuestionAnswers() after parsing regenerated KC questions and always
    // preserves original correctAnswer for translation. (2) Seek block now only fires when
    // requirement==='full'; 'seconds' mode allows free seeking after unlock. (3) startQuiz()
    // (teacher preview) now hides video/audio sections matching handleStartAttempt() behaviour.
    // No DB schema changes. AMD: knowledgecheck.js updated (src=build=min). version.php → 2026072849.
    if ($oldversion < 2026072849) {
        upgrade_mod_savepoint(true, 2026072849, 'aiknowledgecheck');
    }

    // Release v1.5.61 - AUTO-TEST CONFIRMATION: Ongoing tester issue (reported at v1.5.54)
    // confirmed resolved via code audit. Video/quiz simultaneous display: fixed across
    // multiple releases — v1.5.52 (FIX-VIDEO-GATE: handleStartAttempt() hides video/audio/eta
    // sections when student quiz starts), v1.5.56 (showvideoduringquiz setting added, default
    // OFF ensures video is hidden during quiz by default), v1.5.60 (FIX-KC-START-QUIZ-VIDEO:
    // teacher preview startQuiz() also hides video/audio sections matching student behaviour).
    // The Take Quiz button remains disabled until the student watches the video to completion.
    // No code changes. No DB schema changes. AMD unchanged. version.php → 2026072850.
    if ($oldversion < 2026072850) {
        upgrade_mod_savepoint(true, 2026072850, 'aiknowledgecheck');
    }

    // Release v1.5.62 - BUG FIX (FIX-KC-VIDEO-SIMULTANEOUS): Video and quiz start section were shown
    // simultaneously when the teacher left videorequirement='none' (the default). Root cause:
    // $videogated depended on the teacher's videorequirement setting, so an unconfigured
    // activity had no gate and both sections were visible on page load. Fix (view.php):
    // (1) $videoreq is now forced to 'full' whenever $hasvideo, so a video always requires
    // complete watching regardless of teacher setting. (2) $videogated is set to $hasvideo
    // directly so the gate coordinator always hides the start section when a video is present.
    // (3) showStart() in the gate coordinator JS now also hides #kc-video-section and
    // #kc-audio-section when all gates unlock — ensuring video and quiz never coexist.
    // (4) gate.reset() re-shows media sections on retake so students must watch again.
    // No DB schema changes. AMD unchanged. version.php → 2026072851.
    if ($oldversion < 2026072851) {
        upgrade_mod_savepoint(true, 2026072851, 'aiknowledgecheck');
    }

    // Release v1.5.63 — No DB schema changes.
    // FIX-KC-SHOWVIDEO: showStart() was unconditionally hiding #kc-video-section when
    // all gates unlocked, even when the teacher had enabled 'Show video during quiz'.
    // PHP-only fix in view.php. version.php → 2026072852.
    if ($oldversion < 2026072852) {
        upgrade_mod_savepoint(true, 2026072852, 'aiknowledgecheck');
    }

    // Release v1.5.64 — No DB schema changes.
    // FIX-KC-TIMESTAMP-PRESERVE: /api/knowledgecheck-regenerate-settings was dropping
    // timestamp_seconds from regenerated questions, hiding chapter timestamp links after
    // regeneration. Server-only fix in routes.ts. version.php → 2026072853.
    if ($oldversion < 2026072853) {
        upgrade_mod_savepoint(true, 2026072853, 'aiknowledgecheck');
    }

    // Release v1.5.65 — No DB schema changes.
    // FIX-KC-TAKEGATED-UNDEFINED: $takegated was only assigned inside the teacher/creator
    // if ($cancreate) branch in view.php. When a student viewed the activity after watching
    // the video gate, $takegated was undefined, producing a PHP warning on the Start Quiz
    // and Continue Attempt buttons. Fix: initialise $takegated = $anygated && !$cancreate
    // immediately after $anygated is set (line ~150) so it is always defined before any
    // HTML output. version.php → 2026072854.
    if ($oldversion < 2026072854) {
        upgrade_mod_savepoint(true, 2026072854, 'aiknowledgecheck');
    }

    // Release v1.5.66 — No DB schema changes.
    // FIX-KC-LOADING-RETAKE: On activities with a video/audio gate, the Start Quiz
    // button text was left as 'Loading...' by handleStartAttempt() after the first
    // attempt. When the student clicked 'Retake Quiz', gate.reset() re-locked the gate
    // and re-showed the video — but never reset the button text. Once the student
    // re-watched the video and the gate unlocked, the button reappeared still saying
    // 'Loading...', making it look frozen. The student did not know to click it.
    // Fix (two-layer): (1) gate.reset() in view.php now sets btn1.textContent to the
    // 'Retake Quiz' lang string before re-locking; (2) startStudentQuiz() in
    // knowledgecheck.js resets #start-attempt-btn text to config.retakeQuizStr after
    // hiding #kc-start-section, so any future gate re-unlock shows the correct label.
    // AMD: src=build=min triple-match MD5: 16c243300defab67e972804f4a405b37.
    // version.php → 2026072855.
    if ($oldversion < 2026072855) {
        upgrade_mod_savepoint(true, 2026072855, 'aiknowledgecheck');
    }

    // Release v1.5.67 — No DB schema changes.
    // FIX-KC-CORRECT-ANSWER-DIST + FIX-KC-EXPL-SANITISE:
    // Three server-side fixes for "correct answer always option A" and "wrong option
    // shows Correct. explanation" bugs.
    // (1) fixExplanationOrder() now sanitises ALL non-correct explanations that start
    // with "Correct." — replacing the prefix with "Incorrect." to prevent wrong
    // options from displaying "Correct." feedback to the student.
    // (2) The /api/knowledgecheck-regenerate-instructions handler now calls
    // fixExplanationOrder() before shuffleQuestionAnswers() — was missing, causing
    // explanation/option misalignment in regenerated questions.
    // (3) redistributeCorrectAnswers() post-processor added — runs after all questions
    // are generated to evenly distribute correct-answer positions (A/B/C/D) across
    // the whole question set, preventing random-chance clustering at position A.
    // Server-only fix (routes.ts). No PHP, AMD, or DB schema changes.
    // version.php → 2026072856.
    if ($oldversion < 2026072856) {
        upgrade_mod_savepoint(true, 2026072856, 'aiknowledgecheck');
    }

    // Release v1.5.68 — No DB schema changes.
    // FIX-KC-SAVE-SILENT + FIX-KC-EXPLANATION-LABEL:
    // (1) saveQuestionsToDatabase() now shows a visible alert() on save failure so
    // teachers know to refresh and regenerate rather than thinking "Quiz Ready!"
    // means questions are live for students.
    // (2) checkAnswer() and checkAnswerWrongOnly() now always show the CORRECT answer's
    // explanation (q.explanations[q.correctAnswer]) to avoid shuffling-induced
    // label mismatch (AI explanations often contain "Option C is correct…" which
    // looks wrong after shuffling repositions that option).
    // AMD-only fix (knowledgecheck.js src + build + min). No PHP or DB schema changes.
    // version.php → 2026072857.
    if ($oldversion < 2026072857) {
        upgrade_mod_savepoint(true, 2026072857, 'aiknowledgecheck');
    }

    // Release v1.5.69 — No DB schema changes.
    // FIX-KC-GUARD-EDITMODE + FIX-KC-GUARD-SAVEEDITS + FIX-KC-GUARD-SHOWQUIZREADY + FIX-KC-SERVER-GUARD:
    // Four-layer guard to prevent an empty quizData array from wiping all questions from the DB.
    // (1) showEditMode() aborts if quizData is empty. (2) saveEdits() aborts if 0 questions collected
    // with no validation errors. (3) showQuizReady() skips saveQuestionsToDatabase() when quizData
    // is empty. (4) ajax.php savequestions refuses a zero-question payload. AMD + PHP only; no DB schema changes.
    // version.php → 2026072858.
    if ($oldversion < 2026072858) {
        upgrade_mod_savepoint(true, 2026072858, 'aiknowledgecheck');
    }

    // Release v1.5.70 — DB schema change: adds timestamp_seconds to aiknowledgecheck_questions.
    // FIX-KC-CHAPTER-TIMESTAMP: Chapter stamp links (showchapterstamps) were never shown because
    // the aiknowledgecheck_questions table had no column to store timestamp_seconds. The AI returns
    // timestamp_seconds per question during generation, but the savequestions handler silently
    // discarded it and getquestions never included it in the response — leaving q.timestamp_seconds
    // always undefined in JS. Fix: adds timestamp_seconds INT(10) NULL column to the questions
    // table; savequestions now persists it; getquestions now returns it. Existing questions have
    // NULL timestamps (no stamp link shown) until the teacher regenerates. Also fixes
    // FIX-VA-AUDIO-TYPE: AI Video Activity regenerateAudio() and regenerateAudioWithCallback()
    // were sending only MCQ fields (id, question, options, explanations, correctAnswer) — omitting
    // type and explanation — so the server treated all questions as MCQ. Non-MCQ types produced
    // empty audio strings and voiceover was silently skipped. Both functions now send type,
    // explanation, and all type-specific fields (cards, pairs, columns, items, categories).
    // DB: adds aiknowledgecheck_questions.timestamp_seconds. version.php → 2026072859.
    if ($oldversion < 2026072859) {
        $table = new xmldb_table('aiknowledgecheck_questions');
        $field = new xmldb_field('timestamp_seconds', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'mappingcriteria');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026072859, 'aiknowledgecheck');
    }

    // Release v1.5.71 — BUG FIX (FIX-KC-REDISTRIBUTE-REF + FIX-KC-TIMESTAMP-SAVE):
    // Server: redistributeCorrectAnswers() returned same array reference when already balanced
    // or < 4 questions; allQuestions.length = 0 cleared both arrays, leaving job.questions = []
    // and causing "0 questions generated with voiceover!" in the UI. Fix: assign job.questions
    // directly from the returned value without in-place mutation.
    // Client: saveQuestionsToDatabase() now includes timestamp_seconds in the DB payload so
    // chapter-link timestamps survive page refresh. No DB schema changes.
    if ($oldversion < 2026072860) {
        // No DB schema changes in this release — bump version only.
        upgrade_mod_savepoint(true, 2026072860, 'aiknowledgecheck');
    }

    // Release v1.5.72 — BUG FIX (FIX-KC-SEEK-BYPASS):
    // Students with a video gate could bypass the full-watch requirement by seeking forward
    // to near the end of the video. Root cause: stopSeekBlocking() in the view.php inline
    // script called player.getCurrentTime() and updated maxWatchedTime on both PAUSED and
    // BUFFERING events. YouTube fires BUFFERING when the student drags the seek bar forward,
    // so the seek-target position was recorded as maxWatchedTime. When the video then reached
    // ENDED, the maxWatchedTime >= threshold check passed and the gate unlocked without the
    // student watching the full video. Fix: removed the getCurrentTime() call from
    // stopSeekBlocking() entirely. The 500 ms seekBlockTimer interval already tracks progress
    // continuously during playback; the existing 5-second grace window (threshold = duration - 5)
    // absorbs any timing gap. PHP-only fix in view.php inline script. No DB schema changes.
    // version.php → 2026072861.
    if ($oldversion < 2026072861) {
        // No DB schema changes in this release — bump version only.
        upgrade_mod_savepoint(true, 2026072861, 'aiknowledgecheck');
    }

    // Release v1.5.73 — BUG FIX (FIX-KC-STATUS-STREAM + FIX-KC-DEDUP-NAMES-FLAT +
    // FIX-KC-DEDUP-NAMES-EXPLNS + FIX-KC-REDISTRIBUTE-AUDIO + FIX-KC-ZERO-Q-GUARD):
    // Four server-side + one PHP + one client-side fix addressing the "0 questions generated
    // with voiceover!" symptom on Simple KC (no video gate).
    //
    // (1) FIX-KC-STATUS-STREAM — ajax.php status handler now passes the raw JSON response
    // from the Node.js server directly to the client (echo $response) instead of
    // json_decode + json_encode. Re-encoding a completed response containing large
    // base64 audioData arrays can cause json_encode to fail silently (returns false →
    // echo outputs nothing → jQuery parses as null → "0 questions generated"). PHP fix.
    //
    // (2) FIX-KC-DEDUP-NAMES-FLAT — deduplicateScenarioNames() was mapping q.options with
    // spread ({...opt, ...}) when opt is a plain string (flat format after
    // extractEmbeddedFormat). Spreading a string produces char-indexed keys
    // ({'0':'O','1':'p',...}) — corrupting q.options for any question where a duplicate
    // scenario name was found. Fix: detect string options and replace name directly.
    //
    // (3) FIX-KC-DEDUP-NAMES-EXPLNS — deduplicateScenarioNames() was not replacing duplicate
    // names in q.explanations (the flat-format parallel array). After fix, both options
    // and explanations get consistent name substitution. Server fix (routes.ts).
    //
    // (4) FIX-KC-REDISTRIBUTE-AUDIO — redistributeCorrectAnswers() was permuting options
    // and explanations but not audioData. After a correct-answer rebalancing pass, the
    // voiceover audio[i] no longer matched explanations[i] — students heard the wrong
    // explanation audio. Fix: destructure audioData from the question and permute it in
    // sync with options/explanations. Server fix (routes.ts).
    //
    // (5) FIX-KC-ZERO-Q-GUARD — knowledgecheck.js now detects response.questions.length===0
    // on a completed job and shows a meaningful error instead of "0 questions generated
    // with voiceover!". Existing questions are preserved in Add More mode. AMD fix
    // (knowledgecheck.js src + build + min).
    //
    // No DB schema changes. version.php → 2026072862.
    if ($oldversion < 2026072862) {
        // No DB schema changes in this release — bump version only.
        upgrade_mod_savepoint(true, 2026072862, 'aiknowledgecheck');
    }

    // Release v1.5.74 — BUG FIX (FIX-KC-SELECTED-AUDIO):
    // When a student selected a wrong answer, the voiceover played the CORRECT
    // answer's audio/explanation ("Correct. Employing the hierarchy of controls…")
    // while the UI displayed "Incorrect" — jarring and misleading.
    // Root cause: FIX-KC-EXPLANATION-LABEL (v1.5.68) hardcoded audioIdx = q.correctAnswer
    // for all cases. Fix: use selectedAnswer's audio/explanation when wrong, correctAnswer's
    // when right. Applied to both checkAnswer() and checkAnswerWrongOnly().
    // AMD fix (knowledgecheck.js src + build + min). No DB schema changes.
    // version.php → 2026072863.
    if ($oldversion < 2026072863) {
        // No DB schema changes in this release — bump version only.
        upgrade_mod_savepoint(true, 2026072863, 'aiknowledgecheck');
    }
    // Release v1.5.75: AMD ENCODING FIX: All non-ASCII characters (em dashes, arrows, box-drawing chars, ellipsis, bullets, emoji, accented Latin) scrubbed from all AMD JS files (amd/src, amd/build, amd/build/*.min.js). Root cause of Moodle primary/secondary navigation menus disappearing site-wide: non-ASCII bytes in any installed plugin's AMD file cause a SyntaxError inside RequireJS's first.js bundle, throwing "No define call for core/first" and aborting the entire AMD module chain. No PHP, DB schema, or functional changes in this release.
    if ($oldversion < 2026072864) {
        upgrade_mod_savepoint(true, 2026072864, 'aiknowledgecheck');
    }

    // Release v1.5.76 - FIX-KC-WCTX-UNDEFINED: Question generation always failed with ReferenceError
    // 'workplaceContextEnabled is not defined' inside the server-side AI prompt builder.
    // The variable was extracted from the HTTP request body but never passed through the
    // processKnowledgeCheck params object, its TypeScript type, or either helper function
    // signature (generateKnowledgeCheckQuestions / generateKnowledgeCheckQuestionsFromPdf).
    // Fix: server/routes.ts — added workplaceContextEnabled to processKnowledgeCheck call
    // site, type signature, all 3 call sites (text-source loop, PDF loop, topic loop),
    // and both helper function signatures (default false).
    // No DB schema changes. PHP-only version bump.
    // version.php → 2026072865.
    if ($oldversion < 2026072865) {
        upgrade_mod_savepoint(true, 2026072865, 'aiknowledgecheck');
    }

    // Release v1.5.77: JS/CSS fix only - no DB schema change.
    // FIX-KC-PER-QUESTION-REGEN: Per-question "Regenerate" button (refresh icon) added to
    // each question card in the Edit Questions section, to the left of the delete button.
    // Clicking it sends only that one question to the regenerateinstructions endpoint, replaces
    // just that entry in quizData, saves to DB, and rebuilds the edit form.
    if ($oldversion < 2026072866) {
        upgrade_mod_savepoint(true, 2026072866, 'aiknowledgecheck');
    }

    // Release v1.5.78 - FIX-KC-TIMESTAMP-SAVE + AMD-TRIPLE-MATCH:
    // (1) FIX-KC-TIMESTAMP-SAVE: saveEditedQuestions() was missing timestamp_seconds from
    // its question serialisation allowlist. When a teacher edited and saved questions,
    // timestamp_seconds was silently stripped, causing "Show chapter timestamp links"
    // (Video Gate) buttons to disappear for students after any edit-save cycle.
    // Fix: added timestamp_seconds to the return object in saveEditedQuestions().
    // (saveQuestionsToDatabase — the initial-generate save — already had this field.)
    // (2) AMD-TRIPLE-MATCH: knowledgecheck.min.js was stale (MD5 78276ee2 ≠ src c81c5d18).
    // Rebuilt via triple-match cp; all three files now share MD5 9fe7b73f.
    // AMD rebuild (src=build=min, MD5 9fe7b73f). No DB schema changes.
    // version.php → 2026072867.
    if ($oldversion < 2026072867) {
        upgrade_mod_savepoint(true, 2026072867, 'aiknowledgecheck');
    }

    // Release v1.5.79 - REBUMP: Version bump only. AMD triple-match re-applied (src=build=min,
    // MD5 9fe7b73f). No JS, PHP, or DB schema changes.
    // version.php → 2026072868.
    if ($oldversion < 2026072868) {
        upgrade_mod_savepoint(true, 2026072868, 'aiknowledgecheck');
    }

    // Release v1.5.80 - BUG FIX (BUG-REGEN-RETRY + BUG-REGEN-INSTRUCTIONS-CURL + BUG-REGEN-SETTINGS-CURL
    // + REMOVE-PER-QUESTION-REGEN-BTN):
    // (1) handleRegenerateWithInstructions() had zero retry logic — a single transient
    // "busy" API response immediately aborted with an error alert. Fix: refactored
    // AJAX call into doRequest(attemptsLeft=2) with up to 3 total attempts; shows
    // "Service busy - retrying in 15s..." for API busy/rate-limit responses and
    // retries after 5 s for network/timeout failures.
    // (2) regenerateinstructions case in ajax.php used raw curl_init() bypassing Moodle
    // proxy/SSL/redirect config. Fix: replaced with Moodle \curl class + 3-attempt
    // retry loop (sleep 5 s) for HTTP 429/503 and ok:false busy responses.
    // (3) regeneratewithsettings case had the same raw curl pattern. Fix: same \curl
    // + retry loop applied.
    // (4) Per-question regenerate icon (kc-btn-regen-question) and its click handler
    // removed from buildEditForms() — regeneration now only via "Regenerate Questions"
    // button as required.
    // AMD triple-match: src=build=min MD5 2d6b88e5e4a93c15ed68b20f92df6b6d.
    // No DB schema changes. version.php → 2026072869.
    if ($oldversion < 2026072869) {
        upgrade_mod_savepoint(true, 2026072869, 'aiknowledgecheck');
    }

    // Release v1.5.81 — FIX-KC-REGEN-PAYLOAD + FIX-KC-REGEN-STORE:
    // "Regenerate Questions" failed silently because currentQuestions was built with
    // flat string arrays (options:[str,str,str,str], explanations:[str,str,str,str])
    // and used correctAnswer (display index) instead of correctIndex, and omitted the
    // 'type' field entirely. The external API expects {text,explanation} object-array
    // options with correctIndex and type:'mcq'. All three regen paths fixed:
    // handleRegenerateWithInstructions, regeneratewithsettings fallback, and the
    // per-question quizData storage after successful regen (robust dual-format unpack
    // handles both {text,explanation} and plain string arrays defensively).
    // No DB schema changes. version.php → 2026072870.
    if ($oldversion < 2026072870) {
        upgrade_mod_savepoint(true, 2026072870, 'aiknowledgecheck');
    }

    // Release v1.5.82 — FIX-KC-SINGLEREGEN-PAYLOAD + FIX-KC-SINGLEREGEN-STORE:
    // handleKCSingleRegenerate() sent the wrong payload to the API (flat string arrays
    // instead of {text,explanation} object arrays, correctAnswer instead of correctIndex,
    // missing type field) — same class of bug fixed in handleRegenerateWithInstructions
    // in v1.5.81 but overlooked in the per-question path. Also stored API response
    // directly without unpacking from object format, so quizData[idx].options became
    // an object array (breaking rendering) and correctAnswer became undefined.
    // No DB schema changes. version.php → 2026072871.
    if ($oldversion < 2026072871) {
        upgrade_mod_savepoint(true, 2026072871, 'aiknowledgecheck');
    }

    // Release v1.5.83 — FIX-KC-REGEN-SEQUENTIAL:
    // handleRegenerateWithInstructions replaced single large-batch AJAX with sequential
    // per-question requests. Sending all questions in one payload caused the AI API to respond
    // "service busy" or time out — especially with voiceover enabled (base64 audio in one
    // payload blows body limits). Fix: each question is regenerated one at a time with a 1.5 s
    // delay between requests, matching the proven single-question path. Button shows
    // "Regenerating question X of N..." progress. On individual question failure: retries up
    // to 2 times, then skips and continues to the next question (saves successfully regenerated
    // questions). AMD triple-match knowledgecheck.js md5 39b04483022969620f8782786680ece8.
    // No DB schema changes. version.php → 2026072872.
    if ($oldversion < 2026072872) {
        upgrade_mod_savepoint(true, 2026072872, 'aiknowledgecheck');
    }

    // Release v1.5.84 — BUG-REGEN-TIMEOUT:
    // PHP retry loop (3 attempts × CURLOPT_TIMEOUT 150s + sleep(5) between) could run up to
    // 460 seconds. The JS AJAX timeout is only 90 seconds, so JS always fired .fail() long
    // before PHP returned a response. Also, many Moodle servers enforce max_execution_time=30-60s
    // at the web server level, which killed PHP mid-curl producing no JSON output — JS got a
    // blank response and failed. Fix: removed PHP retry loop (JS already retries 3× per question),
    // CURLOPT_TIMEOUT reduced to 75s (strictly below the 90s JS timeout), CURLOPT_CONNECTTIMEOUT
    // added at 10s for fast-fail on DNS/TCP issues, set_time_limit(120) to prevent server killing
    // PHP before curl completes. Applied to both regeneratewithsettings and regenerateinstructions
    // actions in ajax.php. PHP-only fix — no JS/AMD changes, no DB schema changes.
    // version.php → 2026072873.
    if ($oldversion < 2026072873) {
        upgrade_mod_savepoint(true, 2026072873, 'aiknowledgecheck');
    }

    // Release v1.5.85 — BUG-CURL-RESETOPT:
    // Moodle's \curl::post() calls resetopt() internally before applying the post-specific
    // options (CURLOPT_POST, CURLOPT_POSTFIELDS, CURLOPT_URL). Any options set via setopt()
    // BEFORE calling post() are silently discarded. This caused the Content-Type: application/json
    // header and the custom timeouts to never reach the external API — the API received no JSON
    // content-type, could not parse the body, and rejected every regenerate request. Fix: pass
    // curl options as the 3rd argument to post() so they are applied via request() AFTER the
    // internal reset. Applied to both regeneratewithsettings and regenerateinstructions in ajax.php.
    // PHP-only fix — no JS/AMD changes, no DB schema changes. version.php → 2026072874.
    if ($oldversion < 2026072874) {
        upgrade_mod_savepoint(true, 2026072874, 'aiknowledgecheck');
    }

    // Release v1.5.88 — FIX-KC-REGEN-BATCH:
    // Replace slow sequential per-question regeneration with a single batch call.
    // Per-question approach sent N separate AJAX calls with 1.5 s gaps and 10 s
    // "busy" retry delays, causing "Q{n} busy — retrying…" UI stalls. Batch sends
    // all questions in one request; server calls Gemini once for the whole set.
    // PHP curl timeout raised 75 s → 160 s, set_time_limit 120 → 200. JS AJAX
    // timeout raised 90 s → 180 s. AMD: knowledgecheck.js (src+build+min) triple-
    // match MD5: 567b2100a1945c3833a1a3c3e6697a19. PHP: ajax.php. No DB changes.
    // version.php → 2026072875.
    if ($oldversion < 2026072875) {
        upgrade_mod_savepoint(true, 2026072875, 'aiknowledgecheck');
    }

    // Release v1.5.89 - FIX-KC-REGEN-ASYNC: "Regenerate Questions" stuck on "Retrying…" or showed
    // "The AI service is temporarily busy." Root cause: external API changed to async job model
    // returning {ok:true,jobId:"..."} instead of questions synchronously. All three regen
    // handlers (doBatchRequest, handleKCSingleRegenerate, regeneratewithsettings) only checked
    // response.questions — they fell into the error branch, showed "Retrying…", tried once more,
    // and gave up. Fix: added pollRegenJob() helper polling the status action every 2s (max 90
    // polls); all three handlers now check response.jobId first. JS-only fix.
    // AMD: knowledgecheck.js triple-match MD5: 28f4734b775c51faa913e9a68f30477d.
    // No DB schema changes. version.php → 2026072876.
    if ($oldversion < 2026072876) {
        upgrade_mod_savepoint(true, 2026072876, 'aiknowledgecheck');
    }

    // Release v1.5.90 - FIX-REGEN-TTS-PARALLEL + FIX-KC-REGEN-STREAM: "Regenerate Questions" still
    // stuck on "Retrying…" with voiceover enabled after v1.5.89. Root cause 1 (server): TTS
    // was generated sequentially (N×4×~3s) exceeding the 160s PHP curl timeout. Fix: Promise.all
    // per-question explanations. Root cause 2 (ajax.php): json_decode→json_encode round-trip
    // silently produced nothing on large audio payloads. Fix: echo raw response for HTTP 200.
    // No JS, CSS, or DB schema changes. version.php → 2026072877.
    if ($oldversion < 2026072877) {
        upgrade_mod_savepoint(true, 2026072877, 'aiknowledgecheck');
    }

    if ($oldversion < 2026072878) {
        // FIX-KC-TIMESTAMP-ACCURATE + FIX-KC-PDF-TIMESTAMP (v1.5.91): Two server-side fixes
        // to the question generation engine for accurate chapter timestamp links.
        // (1) TOPIC-BASED FUNCTION: The timestamp instruction previously said "find the nearest
        // timestamp for each question's core concept." This was vague and caused the AI to
        // pick timestamps mid-sentence or at the wrong moment. Updated to the same accurate
        // rule used by AI Video Activity: "find the timestamp of the line in the transcript
        // where the question's topic BEGINS to be discussed" — so Jump-to links land at the
        // correct segment start. ACCURACY IS CRITICAL note added.
        // (2) PDF/TEXT-BASED FUNCTION: The timestamp_seconds field was completely absent from
        // the JSON template and there was no timestamp instruction at all. This means when
        // a teacher pastes a video transcript as a text source, the AI never generated
        // timestamp_seconds values, so chapter Jump-to links never worked for text-source
        // questions. Fix: added timestamp_seconds to the JSON template and the same accurate
        // timestamp instruction as (1) above.
        // Server-only fix (routes.ts). No PHP, no AMD, no DB schema changes.
        upgrade_mod_savepoint(true, 2026072878, 'aiknowledgecheck');
    }

    if ($oldversion < 2026072879) {
        // FIX-KC-REGEN-TIMESTAMP + FIX-KC-SINGLE-REGEN-TIMESTAMP + FIX-REGEN-FALLBACK (v1.5.92):
        // Three fixes restoring Jump-to chapter timestamp links after question regeneration.
        // (1) FIX-KC-REGEN-TIMESTAMP: batch regeneration payload (allQuestions) was missing
        // timestamp_seconds — server preservation branch never ran, silently dropping
        // Jump-to links after every bulk regen.
        // (2) FIX-KC-SINGLE-REGEN-TIMESTAMP: per-question regeneration (applySingleQuestion)
        // also dropped timestamp_seconds from the stored result.
        // (3) FIX-REGEN-FALLBACK: generateWithRetry() fallback was gemini-2.0-flash-lite which
        // returned HTTP 404 — replaced with gemini-1.5-flash → gemini-1.5-flash-8b chain.
        // AMD: knowledgecheck.js (src=build=min) MD5: 9eaecb6b9da79fc97c4f892b806af77f.
        // Server: routes.ts. No PHP, no DB schema changes.
        upgrade_mod_savepoint(true, 2026072879, 'aiknowledgecheck');
    }

    // Release v1.5.93 - VERSION BUMP: Clean release. AMD triple-match verified:
    // knowledgecheck.js (src=build=min) MD5: 9eaecb6b9da79fc97c4f892b806af77f.
    // All 6 delivery locations confirmed in sync. No code changes. No DB schema changes.
    // version.php → 2026072880.
    if ($oldversion < 2026072880) {
        upgrade_mod_savepoint(true, 2026072880, 'aiknowledgecheck');
    }

    // Release v1.5.94 - FIX-REGEN-SETTINGS-429: knowledgecheck-regenerate-settings route
    // called generateContent() directly with zero retry or fallback — a single Gemini
    // 429 would immediately surface "Failed to parse AI response".  Wrapped with
    // generateWithRetry() (exponential back-off + gemini-1.5-flash fallback chain).
    // FIX-REGEN-FALLBACK-RETRY: generateWithRetry() fallback chain previously tried
    // each fallback model exactly once; one transient 429/503 on gemini-1.5-flash
    // exhausted the chain immediately.  Each fallback now gets 1 retry with 5s pause
    // before moving on; 404 (deprecated model) breaks immediately.
    // Server: routes.ts only. No PHP, no AMD, no DB schema changes.
    // version.php → 2026072881.
    if ($oldversion < 2026072881) {
        upgrade_mod_savepoint(true, 2026072881, 'aiknowledgecheck');
    }

    if ($oldversion < 2026072882) {
        // FIX-KC-REGEN-GROUNDING (v1.5.95): "Regenerate Questions" produced generic content
        // that drifted away from the original topics, text sources, user-supplied questions
        // and workplace context. Root cause: the SaaS regenerate-instructions endpoint
        // never received any of the original source material — only the OLD questions —
        // so Gemini had nothing to anchor to and quietly invented neighbouring topics.
        // Fix is in 3 layers:
        // (1) DB: add `sourcecontext` TEXT column to mdl_aiknowledgecheck so the original
        // generate-call inputs (topics, userQuestions, textSources, workplaceContext,
        // educationType, vetLevel, academicLevel) can be persisted on the activity row.
        // (2) Plugin: ajax.php `generate` action now writes a JSON-encoded sourcecontext
        // blob to that column on every generate call (overwriting any prior context),
        // and ajax.php `regenerateinstructions` now loads + forwards it as a new
        // `sourceContext` field in the JSON payload to the SaaS endpoint.
        // (3) SaaS: /api/knowledgecheck-regenerate-instructions accepts the new field and
        // injects each populated piece (topics / text sources / user questions /
        // workplace context / education level) into the Gemini prompt as the
        // AUTHORITATIVE source-of-truth, with explicit "MUST be answerable from this
        // source material and MUST NOT introduce facts not present here" wording.
        // Backwards compatible: the SaaS endpoint still works for legacy plugin clients
        // (pre-1.5.95) that don't send a sourceContext, just degraded to old behaviour.
        // version.php → 2026072882.
        $dbman = $DB->get_manager();
        $table = new xmldb_table('aiknowledgecheck');
        $field = new xmldb_field(
            'sourcecontext',
            XMLDB_TYPE_TEXT,
            null,
            null,
            null,
            null,
            null,
            'aftercompletion'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026072882, 'aiknowledgecheck');
    }

    if ($oldversion < 2026072883) {
        // FIX-KC-NOSCENARIOS-SELFCHECK (v1.5.96): SaaS-only fix in server/routes.ts that
        // gates the SELF-CHECK "40% scenario-based" + "UNIQUE NAMES" lines on the
        // workplaceContextEnabled flag (and the example JSON schema's "question" hint).
        // No DB schema, PHP, JS, AMD, or CSS changes — version bump for traceability only.
        upgrade_mod_savepoint(true, 2026072883, 'aiknowledgecheck');
    }

    if ($oldversion < 2026072884) {
        // FALLBACK-TIMESTAMPS (v1.5.106): SaaS-only fix in server/routes.ts.
        // After question generation from a text source, the server now parses the transcript
        // for YouTube-style time markers (M:SS / H:MM:SS, both same-line and split-line
        // formats) and assigns the best-matching timestamp to any question whose AI-returned
        // timestamp_seconds is null. No DB schema, PHP, JS, AMD, or CSS changes.
        // Version bump for traceability only.
        upgrade_mod_savepoint(true, 2026072884, 'aiknowledgecheck');
    }

    if ($oldversion < 2026072885) {
        // FIX-KC-Q1-FRESH (v1.5.107): ajax.php savequestions action now deletes all
        // in-progress attempts (status=0) for the activity after saving new questions.
        // A stale in-progress attempt (from before regeneration) caused startattempt()
        // to return resumed=true with old answer data, setting resumeFromIndex > 0 and
        // making the quiz skip Q1 every time after a regen.
        // FIX-KC-TIMESTAMP-REGEN-TEXTSOURCES (v1.5.107): regenerateinstructions action
        // now forwards useTextSources + textSources as top-level payload fields alongside
        // sourceContext. Previously they were only nested inside sourceContext and the API
        // could not locate the transcript, returning null timestamp_seconds on every regen.
        // No DB schema changes.
        upgrade_mod_savepoint(true, 2026072885, 'aiknowledgecheck');
    }

    if ($oldversion < 2026072886) {
        // FIX-KC-TEXTSOURCES-FIELD (v1.5.108): Two server-side bugs fixed in the
        // /api/knowledgecheck-regenerate-instructions endpoint (server/routes.ts).
        // Both the source-of-truth block builder and the FALLBACK-TIMESTAMPS-REGEN
        // block read textSources items using field name s.content, but the actual
        // field stored by PHP (ajax.php line 265) and the Zod schema is s.text.
        // This caused: (a) the AI never received the transcript as grounding context
        // during regeneration, and (b) FALLBACK-TIMESTAMPS-REGEN always received an
        // empty transcript string and never ran. Fix: both chains changed to s.text.
        // Diag: Section 2 + Section 3 of diag.php extended with FIX-KC-TEXTSOURCES-DIAG
        // to check field name, count timestamp markers, and simulate the fallback path.
        // No DB schema changes.
        upgrade_mod_savepoint(true, 2026072886, 'aiknowledgecheck');
    }

    // Release v1.5.114: FIX-CURL-BATCH — ajax.php switched all raw curl_init() calls to Moodle \curl
    // wrapper. No DB schema changes.
    if ($oldversion < 2026072887) {
        upgrade_mod_savepoint(true, 2026072887, 'aiknowledgecheck');
    }

    // Release v1.5.115: ADD-KC-IMAGEGATE — Image Gate feature.
    // (1) aiknowledgecheck.imageurl (TEXT): activity-level image gate. Teacher pastes a URL
    // or stores a base64 data URL generated by Imagen 4 Ultra. When non-empty, students
    // must click "I've seen this image" before the start button unlocks.
    // (2) aiknowledgecheck_questions.imageurl (TEXT): per-question image URL. Displayed
    // above the question text in the quiz player when imageenabled=1.
    // (3) aiknowledgecheck_questions.imageenabled (INT 1): flag controlling whether the
    // per-question image is shown (1=yes, 0=no). Default 0.
    if ($oldversion < 2026072888) {
        // Add imageurl to activity table (activity-level image gate).
        $table = new xmldb_table('aiknowledgecheck');
        $field = new xmldb_field('imageurl', XMLDB_TYPE_TEXT, null, null, null, null, null, 'audiominseconds');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add imageurl to questions table (per-question image).
        $table = new xmldb_table('aiknowledgecheck_questions');
        $field = new xmldb_field('imageurl', XMLDB_TYPE_TEXT, null, null, null, null, null, 'timestamp_seconds');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Add imageenabled flag to questions table.
        $field = new xmldb_field('imageenabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'imageurl');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026072888, 'aiknowledgecheck');
    }

    // Release v1.5.117: IMAGEGATE-FILEMANAGER — Image Gate switched from URL text field to Moodle
    // filemanager upload. Files stored in 'imagegate' filearea; imageurl column is now
    // populated with the served pluginfile.php URL after upload. No DB schema changes.
    if ($oldversion < 2026072889) {
        upgrade_mod_savepoint(true, 2026072889, 'aiknowledgecheck');
    }

    // Release v1.5.118: GENERAL-TRAINING-OPTION — Added 'General Training' as a third Education Type
    // option in the knowledge check activity (alongside VET and Academic). PHP-only:
    // view.php dropdown + info card, lang strings, AMD JS change handler updated.
    // No DB schema changes.
    if ($oldversion < 2026072890) {
        upgrade_mod_savepoint(true, 2026072890, 'aiknowledgecheck');
    }

    // Release v1.5.120: ADD-KC-MEDIAPER-Q — Per-question video and audio media.
    // Adds four columns to aiknowledgecheck_questions:
    // (1) questionvideourl (CHAR 512): YouTube URL for a per-question video shown above
    // the question text. When questionvideoenabled=1 the video is displayed and
    // the student must click "I've reviewed this content — Continue" to unlock answers.
    // (2) questionvideoenabled (INT 1): flag controlling whether the per-question video
    // is shown (1=yes, 0=no). Default 0.
    // (3) questionaudiourl (CHAR 512): URL for a per-question audio clip shown above
    // the question text with an HTML5 audio player. Teacher-uploaded, distinct from the
    // AI-generated voiceover explanation audio (audiodata column on this same table).
    // (4) questionaudioenabled (INT 1): flag controlling whether the per-question audio
    // is shown (1=yes, 0=no). Default 0.
    // Also fixes the three regen mappers (applySettingsQuestions, applyBatchQuestions,
    // applySingleQuestion) which were silently stripping imageUrl/imageEnabled on every
    // regeneration — teacher-configured per-question media is now preserved across all
    // regeneration paths.
    if ($oldversion < 2026072891) {
        $table = new xmldb_table('aiknowledgecheck_questions');

        // Add per-question video URL.
        $field = new xmldb_field('questionvideourl', XMLDB_TYPE_CHAR, '512', null, null, null, null, 'imageenabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Add per-question video enabled flag.
        $field = new xmldb_field('questionvideoenabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'questionvideourl');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Add per-question audio URL (teacher-uploaded; distinct from AI voiceover audiodata).
        $field = new xmldb_field('questionaudiourl', XMLDB_TYPE_CHAR, '512', null, null, null, null, 'questionvideoenabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Add per-question audio enabled flag.
        $field = new xmldb_field('questionaudioenabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'questionaudiourl');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026072891, 'aiknowledgecheck');
    }

    if ($oldversion < 2026072892) {
        // FIX-KC-COMPLETION-SORT-ORDER (v1.5.122): custom_completion::get_sort_order()
        // was missing completionusegrade and completionpassgrade. Moodle 4.x validates
        // that get_sort_order() lists every active standard and custom completion
        // condition. Because the module declares FEATURE_GRADE_HAS_GRADE=true, Moodle
        // includes completionusegrade and completionpassgrade in the active condition
        // set. Their absence caused a "coding error: get_sort_order() is missing one or
        // more completion conditions" exception whenever any completion tracking was
        // configured on a Knowledge Check activity. Fix: added completionusegrade and
        // completionpassgrade to the returned array in get_sort_order(). No DB changes.
        upgrade_mod_savepoint(true, 2026072892, 'aiknowledgecheck');
    }

    if ($oldversion < 2026072893) {
        // FIX-API-DOMAIN: Updated all API endpoint URLs from lms-labs.com to lms-labs.com.
        // lms-labs.com has no DNS resolution from Moodle server side; lms-labs.com is the
        // correct working domain. All ajax.php, api_client, unlock_verifier, lib.php calls updated.
        if (function_exists('opcache_invalidate')) {
            $plugindir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'db/upgrade.php'] as $file) {
                $fullpath = $plugindir . '/' . $file;
                if (file_exists($fullpath)) {
                    opcache_invalidate($fullpath, true);
                }
            }
        } else if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072893, 'aiknowledgecheck');
    }

    if ($oldversion < 2026072894) {
        // FIX-API-DOMAIN: Reverted API endpoint to lms-labs.com (correct domain).
        // essaygraderai.app was the original single-plugin domain; lms-labs.com is correct.
        if (function_exists('opcache_invalidate')) {
            $plugindir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'db/upgrade.php'] as $file) {
                $fullpath = $plugindir . '/' . $file;
                if (file_exists($fullpath)) {
                    opcache_invalidate($fullpath, true);
                }
            }
        } else if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072894, 'aiknowledgecheck');
    }

    if ($oldversion < 2026072895) {
        // Domain update: lms-labs.com → lms-labs.com.
        if (function_exists('opcache_invalidate')) {
            $plugindir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'lib.php', 'db/upgrade.php'] as $file) {
                $fullpath = $plugindir . '/' . $file;
                if (file_exists($fullpath)) {
                    opcache_invalidate($fullpath, true);
                }
            }
        } else if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072895, 'aiknowledgecheck');
    }

    if ($oldversion < 2026072896) {
        // Release v1.5.126: Survey Mode — AI Knowledge Check can now operate as a survey tool.
        // Teachers enable survey mode in the activity settings, choose a response scale
        // (Likert 5-point agreement, satisfaction, frequency, quality, importance; 4-point
        // agreement; Yes/No; Yes/No/Unsure; NPS 5-point), then paste their question list.
        // The AI formats questions with the selected scale options. Students respond without
        // correct/wrong scoring; the results screen shows "Thank you for completing the
        // survey" instead of a score. Voiceover works normally (reads the question text).
        //
        // DB changes:
        // - mdl_aiknowledgecheck.surveymode  INT(1) DEFAULT 0
        // - mdl_aiknowledgecheck.surveyscale CHAR(50) DEFAULT ''
        // - mdl_aiknowledgecheck_questions.answer5 TEXT NULLABLE (5th option for 5-point scales).

        $dbman = $DB->get_manager();

        // Add surveymode column.
        $table = new xmldb_table('aiknowledgecheck');
        $field = new xmldb_field('surveymode', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'sourcecontext');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add surveyscale column.
        $field2 = new xmldb_field('surveyscale', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, '', 'surveymode');
        if (!$dbman->field_exists($table, $field2)) {
            $dbman->add_field($table, $field2);
        }

        // Add answer5 column to questions table.
        $qtable = new xmldb_table('aiknowledgecheck_questions');
        $qfield = new xmldb_field('answer5', XMLDB_TYPE_TEXT, null, null, null, null, null, 'imageenabled');
        if (!$dbman->field_exists($qtable, $qfield)) {
            $dbman->add_field($qtable, $qfield);
        }

        if (function_exists('opcache_invalidate')) {
            $plugindir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'lib.php', 'ajax.php', 'view.php', 'mod_form.php', 'db/upgrade.php'] as $file) {
                $fullpath = $plugindir . '/' . $file;
                if (file_exists($fullpath)) {
                    opcache_invalidate($fullpath, true);
                }
            }
        } else if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        upgrade_mod_savepoint(true, 2026072896, 'aiknowledgecheck');
    }

    if ($oldversion < 2026072897) {
        // ADD-SURVEY-FREETEXT (v1.5.127): Add questiontype column to aiknowledgecheck_questions.
        // Values: 'scale' (default — MCQ or survey scale question), 'freetext' (open-ended comment).
        $qtable = new xmldb_table('aiknowledgecheck_questions');
        $qtfield = new xmldb_field('questiontype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'scale', 'answer5');
        if (!$dbman->field_exists($qtable, $qtfield)) {
            $dbman->add_field($qtable, $qtfield);
        }

        if (function_exists('opcache_invalidate')) {
            $plugindir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'lib.php', 'ajax.php', 'view.php', 'report.php', 'db/upgrade.php'] as $file) {
                $fullpath = $plugindir . '/' . $file;
                if (file_exists($fullpath)) {
                    opcache_invalidate($fullpath, true);
                }
            }
        } else if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        upgrade_mod_savepoint(true, 2026072897, 'aiknowledgecheck');
    }

    if ($oldversion < 2026072898) {
        // SURVEY-MODE-UI (v1.5.128): No DB schema changes.
        // This release adds context-aware UI to the teacher generation form when an activity
        // has Survey Mode enabled. Prior to this release, the "Use Your Own Questions" toggle
        // showed copy that described MCQ generation ("AI will create 4 answer options, 1 correct,
        // 3 distractors, plus voiceover explanations") — which was factually wrong in survey mode
        // (the backend uses teacher-supplied question stems with the scale labels appended; no
        // AI-generated distractors and no voiceover are produced). Changes:
        //
        // (1) Survey Mode notice banner: orange info strip shown above the input sections when
        // surveymode=1 so teachers know their context before they start filling in the form.
        // Shows the active scale name (e.g. "Strongly Agree → Strongly Disagree").
        //
        // (2) Conditional "Use Your Own Questions" sublabel: in survey mode the description now
        // correctly reads "Paste one question stem per line — the response scale is applied
        // automatically. Do not include answer options." instead of the MCQ description.
        //
        // (3) Conditional textarea placeholder: survey-specific example questions (Likert-style
        // feedback) instead of MCQ-style questions.
        //
        // (4) Conditional help text: explains the {scale} is added automatically and directs
        // teachers to use the Free Text Questions box for open-ended questions.
        //
        // (5) knowledgecheck.js (AMD): in survey mode init, the voiceover section is now hidden
        // (surveys produce no audio explanations — the voiceover toggle was previously shown
        // and functional but misleading).
        //
        // PHP: view.php, lang/en/aiknowledgecheck.php.
        // AMD: knowledgecheck.js (src+build+min).
        // CSS: styles.css (kc-survey-mode-notice strip).
        // No DB schema changes.
        if (function_exists('opcache_invalidate')) {
            $plugindir = realpath(__DIR__ . '/..');
            $files = [
                'version.php',
                'db/upgrade.php',
                'view.php',
                'styles.css',
                'amd/build/knowledgecheck.js',
                'amd/build/knowledgecheck.min.js',
                'lang/en/aiknowledgecheck.php',
            ];
            foreach ($files as $file) {
                if (file_exists($plugindir . '/' . $file)) {
                    opcache_invalidate($plugindir . '/' . $file, true);
                }
            }
        } else if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072898, 'aiknowledgecheck');
    }

    if ($oldversion < 2026082400) {
        // SURVEY-SCHEMA-RECONCILIATION (v1.5.135).
        //
        // Survey Mode originally shipped in savepoints 2026072896/97 after a
        // 13-digit-to-10-digit version rebase. Some sites already had a stored
        // 10-digit version numerically above those rebased savepoints, so Moodle
        // skipped them even though the columns had never been created. Reconcile
        // the complete Survey Mode schema in one new, higher savepoint. Every
        // operation is guarded so this is safe on healthy and partially upgraded
        // sites alike.
        $dbman = $DB->get_manager();

        $table = new xmldb_table('aiknowledgecheck');
        $field = new xmldb_field(
            'surveymode',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'sourcecontext'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field(
            'surveyscale',
            XMLDB_TYPE_CHAR,
            '50',
            null,
            XMLDB_NOTNULL,
            null,
            '',
            'surveymode'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $qtable = new xmldb_table('aiknowledgecheck_questions');
        $qfield = new xmldb_field(
            'answer5',
            XMLDB_TYPE_TEXT,
            null,
            null,
            null,
            null,
            null,
            'imageenabled'
        );
        if (!$dbman->field_exists($qtable, $qfield)) {
            $dbman->add_field($qtable, $qfield);
        }

        $qfield = new xmldb_field(
            'questiontype',
            XMLDB_TYPE_CHAR,
            '20',
            null,
            XMLDB_NOTNULL,
            null,
            'scale',
            'answer5'
        );
        if (!$dbman->field_exists($qtable, $qfield)) {
            $dbman->add_field($qtable, $qfield);
        }

        upgrade_mod_savepoint(true, 2026082400, 'aiknowledgecheck');
    }

    // FIX-KC-XMLDB-DEFAULT (v1.5.150): aiknowledgecheck.surveyscale was declared CHAR NOT NULL
    // with DEFAULT ''. Moodle rejects an empty-string default on a NOT NULL character column,
    // rewrites it to NULL at install time and emits an XMLDB debugging warning on any page that
    // loads the schema. An empty string was never a valid scale key: mod_form.php defaults the
    // field to 'likert5agree' and lib.php/ajax.php both fall back to it, so that is declared as
    // the real default here. Existing rows holding '' are backfilled to match.
    if ($oldversion < 2026083006) {
        $table = new xmldb_table('aiknowledgecheck');
        $field = new xmldb_field(
            'surveyscale',
            XMLDB_TYPE_CHAR,
            '50',
            null,
            XMLDB_NOTNULL,
            null,
            'likert5agree',
            'surveymode'
        );

        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_default($table, $field);

            // Backfill rows that were created while the default was an empty string. Guarded
            // so the update only touches rows that actually need it.
            $DB->set_field_select(
                'aiknowledgecheck',
                'surveyscale',
                'likert5agree',
                "surveyscale IS NULL OR " . $DB->sql_compare_text('surveyscale') . " = :empty",
                ['empty' => '']
            );
        }

        upgrade_mod_savepoint(true, 2026083006, 'aiknowledgecheck');
    }

    return true;
}
