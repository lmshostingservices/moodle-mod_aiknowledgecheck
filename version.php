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
 * AI Knowledge Check v1.5.96 - BUG FIX: Video Gate scenario questions appear when Workplace Context disabled.
 *
 * v1.5.96: BUG FIX (FIX-KC-NOSCENARIOS-SELFCHECK) — Knowledge Check (especially the
 *   Video Gate path that supplies the video transcript as a text source) was generating
 *   workplace-scenario questions with named characters even when the teacher had the
 *   "Use workplace context" toggle switched OFF.
 *
 *   Root cause: server/routes.ts contained TWO prompt builders for the multiple-choice
 *   generator — generateKnowledgeCheckQuestions (topic-only) and
 *   generateKnowledgeCheckQuestionsFromPdf (used whenever any text source is supplied,
 *   including Video Gate transcripts). Both prompts already conditioned the high-level
 *   "QUESTION TYPE MIX" instructions on workplaceContextEnabled and explicitly banned
 *   named persons when it was off. However the SELF-CHECK section near the end of each
 *   prompt contained an UNCONDITIONAL pair of lines:
 *       "- At least 40% scenario-based, at least 40% direct application"
 *       "- UNIQUE NAMES: Every named person across all N questions is different…"
 *   Long prompts have a recency bias — the model weights instructions near the end of
 *   the prompt more heavily than instructions at the start. So even though "NO NAMED
 *   PERSONS — ABSOLUTE" appeared earlier, the model obeyed the later "40% scenario" +
 *   "UNIQUE NAMES" SELF-CHECK directive and produced scenario questions with names.
 *
 *   Fix is SaaS-only (server/routes.ts):
 *
 *   (1) Both SELF-CHECK blocks are now ternary-gated on workplaceContextEnabled. When
 *       workplace context is OFF, those two lines are replaced with their inverse:
 *           "- ZERO scenario questions: 100% direct application — no named persons,
 *              no workplace settings, no situational framing whatsoever
 *              (workplace context is disabled)"
 *           "- NO NAMES CHECK: scan all N questions one final time and confirm not a
 *              single named person, worker, character, or role appears anywhere — if
 *              any name slipped in, rewrite that question impersonally before returning"
 *
 *   (2) The example JSON schema in the PDF/transcript prompt also had a hint reading
 *       "Application-focused question stem (scenario or direct)…" which has been
 *       conditionalised to "Direct application-focused question stem (NO scenarios,
 *       NO named persons)…" when workplace context is disabled.
 *
 *   No DB schema changes. No PHP, JS, AMD, or CSS changes. The plugin already correctly
 *   forwards workplaceContextEnabled and the related country/state/industry fields to
 *   /api/generate-knowledgecheck — the bug was purely in the SaaS prompt builders.
 *
 *   Plugin version is bumped purely for traceability so administrators can correlate
 *   the SaaS-side fix with a published Moodle plugin release.
 *
 *   Changes: server/routes.ts (two SELF-CHECK ternaries + one example-schema ternary).
 *   No plugin code changes. version.php → 2026050100096.
 *
 * v1.5.95: BUG FIX (FIX-KC-REGEN-GROUNDING) — "Regenerate Questions" produced generic
 *   content that drifted away from the original topics, text sources, instructor-supplied
 *   reference questions, workplace context and education level. After a regenerate the
 *   teacher would notice the new questions tested concepts that were never part of the
 *   original source material. Sometimes regenerated questions even contradicted the
 *   text sources the learner had been told to study.
 *
 *   Root cause: the SaaS endpoint /api/knowledgecheck-regenerate-instructions was only
 *   ever sent the OLD question text + extra instructor instructions. None of the original
 *   inputs that produced the question set in the first place — text sources, topics,
 *   user-authored reference questions, workplace context (industry/role/country),
 *   education type / VET level / academic level — were forwarded. With nothing to anchor
 *   to, Gemini quietly invented neighbouring topics that looked plausible alongside the
 *   old questions but had no relationship to the source material.
 *
 *   Fix is a 3-layer transcript-grounding architecture:
 *
 *   (1) DB layer — install.xml + db/upgrade.php savepoint 2026050100095:
 *       New TEXT column `mdl_aiknowledgecheck.sourcecontext` (nullable) holds a
 *       JSON-encoded blob of the original generate-call inputs. xmldb add_field guarded
 *       by field_exists for upgrade-safety.
 *
 *   (2) Plugin layer — ajax.php:
 *       (a) The 'generate' action now writes a JSON blob to sourcecontext on every
 *           generate call (overwriting any previous context), capturing topics,
 *           useOwnQuestions, userQuestions, useTextSources, textSources,
 *           workplaceContextEnabled + country/state/industry/industryDetails/jobLevel/
 *           jobTitle, and educationType/vetLevel/academicLevel.
 *       (b) The 'regenerateinstructions' action now loads $knowledgecheck->sourcecontext,
 *           json_decodes it, and forwards it as a new `sourceContext` field on the JSON
 *           payload sent to the SaaS endpoint. Gracefully degrades when the column is
 *           empty (legacy activity created on pre-1.5.95) or missing (upgrade not yet run).
 *
 *   (3) SaaS layer — server/routes.ts /api/knowledgecheck-regenerate-instructions:
 *       Accepts the new optional `sourceContext` field. Builds an "AUTHORITATIVE SOURCE
 *       MATERIAL (HIGHEST PRIORITY)" block at the top of the Gemini prompt containing
 *       text sources, instructor reference questions, original topic list, workplace
 *       context, and learner level — with explicit "MUST be answerable from this material
 *       and MUST NOT introduce facts not present here" wording. The block also tells
 *       Gemini that if the OLD questions have drifted away from the source material it
 *       must correct that drift — source material wins, old questions are only a
 *       structural reference. Backwards compatible: legacy plugin clients (pre-1.5.95)
 *       that don't send sourceContext still work, just degraded to old behaviour.
 *
 *   Mirrors FIX-VA-REGEN-GROUNDING (mod_aivideoactivity v1.0.99) which solved the same
 *   class of problem for video-grounded regenerate by forwarding the full transcript.
 *
 *   Changes: db/install.xml, db/upgrade.php, ajax.php (generate + regenerateinstructions),
 *   server/routes.ts. No JS, no AMD, no CSS changes.
 *   version.php → 2026050100095.
 *
 * v1.5.90: BUG FIX: Regenerate Questions still "Retrying" with voiceover.
 *
 * v1.5.90: BUG FIX (FIX-REGEN-TTS-PARALLEL + FIX-KC-REGEN-STREAM): "Regenerate Questions" was
 *   still permanently stuck on "Retrying…" after v1.5.89, specifically when voiceover was enabled.
 *
 *   Root cause 1 (server — routes.ts): The regenerateinstructions and regeneratewithsettings
 *   endpoints generated TTS audio for each question's 4 explanations sequentially. For 10
 *   questions that is 40 sequential TTS calls × ~3 s each = ~120 s total, exceeding the 160 s
 *   PHP curl timeout. PHP's curl timed out and returned nothing (or an error), so Moodle echoed
 *   {ok:false} → JS saw response.ok=false and fell into the "Retrying…" retry branch.
 *   Fix: use Promise.all over each question's explanations so all 4 run concurrently, reducing
 *   per-question TTS time from ~12 s to ~3–4 s (FIX-REGEN-TTS-PARALLEL). Questions are still
 *   processed sequentially (10 × 4 s = 40 s) to avoid overwhelming the TTS API with burst calls.
 *
 *   Root cause 2 (ajax.php): regenerateinstructions and regeneratewithsettings did a
 *   json_decode($raw) → json_encode($result) round-trip before echoing the response. On servers
 *   where json_encode silently returns false (e.g. invalid UTF-8 in audio bytes, memory pressure,
 *   or strict PHP builds), echo false outputs nothing → jQuery got a blank 200 body → parsed as
 *   parseerror → .error() handler fired → "Retrying…" then alert. The same issue was already
 *   fixed for the 'status' action (FIX-KC-STATUS-STREAM). Fix: echo $raw directly when HTTP 200;
 *   only json_decode for non-200 error extraction (FIX-KC-REGEN-STREAM).
 *
 *   Changes: server/routes.ts (TTS parallel), ajax.php (raw echo). No JS, CSS, or DB changes.
 *   version.php → 2026042900090.
 *
 * v1.5.69: BUG FIX (FIX-KC-GUARD-EDITMODE + FIX-KC-GUARD-SAVEEDITS +
 *          FIX-KC-GUARD-SHOWQUIZREADY + FIX-KC-SERVER-GUARD):
 *   Four complementary guards that prevent a teacher from accidentally wiping all
 *   Knowledge Check questions from the database when the JS quizData array is empty.
 *
 *   Root-cause scenario: under certain conditions (e.g. race condition on page load,
 *   AI returning an empty question list, or a prior failed save leaving quizData=[])
 *   the teacher could click "Edit Questions" and see a blank edit form. Clicking
 *   "Save Changes" on that blank form called savequestions with an empty array, which
 *   executed DELETE all + INSERT nothing — silently destroying all questions. Students
 *   in active attempts still saw their loaded questions, masking the data loss.
 *
 *   (1) FIX-KC-GUARD-EDITMODE — showEditMode() now aborts with a user-visible alert
 *       and returns early when !quizData || quizData.length === 0, preventing the
 *       teacher from opening a blank edit form.
 *
 *   (2) FIX-KC-GUARD-SAVEEDITS — saveEdits() now aborts with a user-visible alert
 *       if editedQuestions.length === 0 after the collection loop (with no validation
 *       errors), meaning the edit container was empty and saving would erase all DB data.
 *
 *   (3) FIX-KC-GUARD-SHOWQUIZREADY — showQuizReady() now only calls
 *       saveQuestionsToDatabase() when quizData.length > 0. If the AI returned an
 *       empty question list the DB save is skipped, preserving any previously stored
 *       questions.
 *
 *   (4) FIX-KC-SERVER-GUARD — ajax.php savequestions action now refuses a payload of
 *       zero questions with ok:false and an explanatory error message, providing a
 *       last-resort server-side safety net independent of any JS state.
 *
 *   AMD changes: knowledgecheck.js (src + build + min). PHP: ajax.php. No DB schema changes.
 *   version.php → 2026042100069.
 *
 * v1.5.68: BUG FIX (FIX-KC-SAVE-SILENT + FIX-KC-EXPLANATION-LABEL):
 *   Two bugs fixed.
 *
 *   (1) FIX-KC-SAVE-SILENT — saveQuestionsToDatabase() was silently swallowing
 *       save errors (console.error only). When saving failed (network, POST size,
 *       session, etc.) the teacher saw "Quiz Ready!" but no questions reached the
 *       database, so students saw "Check back later". Fix: both the success-with-error
 *       and network-error callbacks now show a prominent alert() explaining the failure
 *       and asking the teacher to refresh and regenerate.
 *
 *   (2) FIX-KC-EXPLANATION-LABEL — After shuffling, AI-generated explanations that
 *       contained option labels ("Option C is correct because…") appeared under the
 *       wrong visual option, making it look like the system showed "Option C's
 *       explanation" for "Option B". Fix: checkAnswer() and checkAnswerWrongOnly()
 *       now always show the CORRECT answer's explanation (q.explanations[q.correctAnswer])
 *       with a fallback to the selected answer's explanation. The voiceover audio track
 *       is also switched to the correct answer's audio for consistency.
 *
 *   AMD changes: knowledgecheck.js (src + build + min). No DB schema changes.
 *   version.php → 2026042000068.
 *
 * v1.5.67: BUG FIX (FIX-KC-CORRECT-ANSWER-DIST + FIX-KC-EXPL-SANITISE):
 *   Three root-causes fixed to resolve "correct answer always option A" and
 *   "wrong option shows Correct. explanation" reports.
 *
 *   (1) FIX-KC-EXPL-SANITISE — fixExplanationOrder() now performs a second pass after
 *       aligning the "Correct." explanation to correctAnswer. Any remaining non-correct
 *       explanation that still starts with "Correct." (AI generation error) has its prefix
 *       replaced with "Incorrect." — preventing wrong options from displaying "Correct."
 *       feedback to the student.
 *
 *   (2) FIX-KC-REGEN-EXPL-ORDER — The /api/knowledgecheck-regenerate-instructions handler
 *       was calling shuffleQuestionAnswers() without first calling fixExplanationOrder().
 *       This meant option↔explanation alignment was never corrected before shuffling,
 *       causing the wrong explanation to appear for regenerated questions.
 *
 *   (3) FIX-KC-CORRECT-ANSWER-DIST — A new redistributeCorrectAnswers() post-processor
 *       runs after all questions are generated. It ensures correct-answer positions are
 *       spread evenly across A, B, C, D across the whole question set — preventing random
 *       chance from clustering correct answers at position A.
 *
 *   Server-only fix (routes.ts). No DB schema changes. No PHP or AMD changes.
 *   version.php → 2026041700067.
 *
 * v1.5.66: BUG FIX (FIX-KC-LOADING-RETAKE): Start Quiz button stayed as 'Loading...'
 *   after retake on gated activities. Fix: gate.reset() resets button text; startStudentQuiz()
 *   resets button text after hiding start section. AMD: src=build=min triple-match MD5:
 *   16c243300defab67e972804f4a405b37. version.php → 2026041700066.
 *
 * v1.5.64: BUG FIX (FIX-KC-TIMESTAMP-PRESERVE): The knowledgecheck-regenerate-settings endpoint
 *   was dropping timestamp_seconds from regenerated questions. After regeneration the
 *   "Show chapter timestamp links" button disappeared even when timestamps existed.
 *   Fix: server/routes.ts now copies timestamp_seconds from the original question into the
 *   regenerated question after AI returns. Server-only fix. No DB schema changes.
 *   version.php → 2026041600064.
 *
 * v1.5.63: BUG FIX (FIX-KC-SHOWVIDEO): showStart() was unconditionally hiding #kc-video-section
 *   when all gates unlocked (video finished), even when the teacher had enabled "Show video during quiz".
 *   Fix: The video section is now only hidden on gate unlock if showvideoduringquiz is off; otherwise
 *   it remains visible alongside the quiz start section. PHP-only fix in view.php showStart() function.
 *   No DB schema changes. version.php → 2026041600063.
 *
 * v1.5.57: NEW FEATURE — "Show chapter timestamp links".
 *   When enabled, a clickable "Jump to X:XX" button appears above each quiz question.
 *   Clicking seeks the YouTube video to the timestamp nearest the chapter the question covers.
 *   The AI now returns timestamp_seconds (int|null) for each question in the stored JSON.
 *   The KC YouTube player is exposed as window.kcYtPlayer via the onReady event in view.php,
 *   so the AMD module (knowledgecheck.js) can call seekTo() without having direct access to
 *   the inline-scoped player variable.
 *   Setting: aiknowledgecheck.showchapterstamps (DB added in v1.5.57 upgrade).
 *   Changes: knowledgecheck.js (showQuestion), view.php (window.kcYtPlayer, hasVideo config,
 *   showChapterStamps config), styles.css, version.php → 202604100057.
 *
 * v1.5.55 - v1.5.56: See previous changelog entries below.
 *
 * v1.5.55: BUG FIX (FIX-KC-SEEK-BLOCK): Students could drag the YouTube progress bar forward to
 *   skip past the video and still trigger the ENDED event, bypassing the "watch full video" gate.
 *   Fix:
 *   (1) A 500ms polling timer (seekBlockTimer) runs while the video is playing. It tracks
 *       maxWatchedTime (rolling maximum of player.getCurrentTime()). If the player position jumps
 *       ahead by more than 1.5 s beyond maxWatchedTime the student is seeked back to maxWatchedTime.
 *   (2) The ENDED handler for 'full' requirement now checks maxWatchedTime >= (getDuration() - 5 s)
 *       before unlocking. If the student somehow triggers ENDED without actually watching, the player
 *       seeks back to maxWatchedTime and resumes — the quiz gate remains locked.
 *   (3) kcVideoGate.resetLock() now also resets maxWatchedTime = 0 and calls stopSeekBlocking()
 *       so retakes start with a fresh non-skippable session.
 *   No DB schema changes. PHP-only: view.php. version.php → 202604090055.
 *
 * v1.5.56: NEW FEATURE — "Show video while answering questions" activity setting.
 *   Adds a checkbox in the Video Gate section of the activity settings form.
 *   When enabled, the video player remains visible above the question panel while
 *   the student is answering (only the gate-status bar is hidden). When disabled
 *   (default), the video section hides as soon as the quiz player takes over —
 *   preserving the existing behaviour. DB: aiknowledgecheck.showvideoduringquiz (int1).
 *   version.php → 202604100056.
 *
 * v1.5.54: BUG FIX (FIX-KC-VIDEO-GATE-SEQUENTIAL): Two remaining video-gate
 *           UX issues. FIX-1 (INITIAL DISPLAY): Start Quiz card and Estimated Time banner were
 *           always visible on page load alongside the video — gate locked the button but left the
 *           section visible. Fix: view.php now adds style="display:none;" to both elements when
 *           $anygated is true; gate coordinator unlock() reveals them once all gates clear.
 *           FIX-2 (RETAKE GATE RESET): retakeQuiz() immediately called handleStartAttempt()
 *           on retake, bypassing the gate — students could skip re-watching. Fix: retakeQuiz()
 *           now calls window.kcGate.reset() which re-locks all original gates, re-hides start
 *           section and eta banner, and resets tracker state (unlocked=false, watchedSeconds=0,
 *           player.seekTo(0)). window.kcVideoGate and window.kcAudioGate expose resetLock()
 *           from their respective IIFEs. AMD: knowledgecheck.js updated; src=build=min
 *           triple-match MD5: bc3877cc4313a56cee7b097742e1b020. PHP: view.php updated.
 *           No DB schema changes. version.php → 2026040800153.
 *
 * v1.5.52 - BUG FIX (x2) (FIX-KC-OPTION-CAPITALISE + FIX-KC-VIDEO-GATE): see changelog.
 *           AMD: src=build=min triple-match MD5: 747cd7c02bbbc25ad2f13d565722502d.
 *           version.php → 2026040400152.
 *
 * v1.5.51 - BUG FIX (FIX-KC-SAVE-AUDIO-SKIP): saveEdits() in knowledgecheck.js
 *           now compares the teacher's form edits against originalQuizData before calling
 *           regenerateAudioWithCallback. If no question content changed (question text, options,
 *           explanations, correctAnswer all identical), the audio regeneration step is skipped —
 *           existing TTS audio remains valid and no credits are wasted. Previously saveEdits()
 *           called regenerateAudioWithCallback unconditionally whenever voiceover was enabled,
 *           burning credits on a new TTS round-trip even after a failed question-regen where
 *           the teacher clicked Save Changes without editing anything. AMD: knowledgecheck.js
 *           updated; src=build=min triple-match MD5: 4008bb2dc5c3fc7314b5412684fe2979.
 *           version.php → 2026040300151.
 *
 * v1.5.50 - BUG FIX (BUG-KC-GATE-TEACHER-BYPASS): Removed duplicate #regenerate-btn injected by
 *           checkExistingQuestions() that was resetting the form and nulling quizData instead
 *           of invoking handleRegenerateWithInstructions. The view.php #ready-regenerate-btn
 *           is now the sole regenerate button. version.php → 2026032701545.
 *
 * v1.5.44 - VERSION BUMP: Clean release following master release process.
 *           Gemini TTS (which can take 30–60 s) is no longer prematurely aborted. Gemini
 *           responseMimeType + temperature config forwarded correctly in knowledgecheck routes.
 *           version.php → 2026032701544.
 *
 * v1.5.42: VERSION BUMP — Clean release increment. No code or DB schema changes.
 *           version.php → 2026032601542.
 *
 * v1.5.41: BUG FIX — Two bugs fixed in the Regenerate Questions feature.
 *           (1) DOUBLE-REQUEST FIX: .off('click').on('click') prevents duplicate AJAX calls
 *           when AMD init() runs more than once. (2) AI PARSE HARDENING: 4-strategy JSON
 *           extraction on /api/knowledgecheck-regenerate-instructions (strip fences → direct
 *           parse → regex extract → substring). Self-healing normalisation pads/trims
 *           options/explanations to exactly 4 instead of hard-failing.
 *           version.php → 2026032601541.
 *
 * v1.5.40: NEW FEATURE — "Add More Questions" button on the teacher ready screen.
 *           Teachers can append additional questions to an existing set without
 *           regenerating all questions from scratch. Preserves existing quizData,
 *           returns to the generation form, and concatenates new questions on
 *           completion. If generation fails, existing questions are restored.
 *           New CSS class kc-btn-success and kc-add-more-info banner.
 *           version.php → 2026032601540.
 *
 * v1.5.39: VERSION BUMP — Clean release increment. No code or DB schema changes.
 *           version.php → 2026032601539.
 *
 * v1.5.38: BUG-KC-QPT-CAP FIX — Questions-per-topic dropdown was hardcoded 1-5 only,
 *           preventing teachers from ever selecting more than 5 questions regardless of
 *           topic count. Dropdown extended to 1-20 (5 default, adds 6,7,8,9,10,12,15,20).
 *           BUG-KC-TOKEN-FLOOR FIX — Token budget formula for generateKnowledgeCheckQuestions
 *           and generateKnowledgeCheckQuestionsFromPdf raised from Math.min(15000,Math.max(6000,n*600))
 *           to Math.min(15000,Math.max(8000,n*900)). The 3-part explanation format requires
 *           ~700-900 tokens/question; old floor of 6000 exactly matched a 10-question budget
 *           with no headroom, causing silent JSON truncation at 10+ questions. New floor gives
 *           8000 minimum and 9000 for a 10-question batch — 25% headroom. version.php → 2026032601538.
 *
 * v1.5.32: INDUSTRY UNIFICATION — Industry SELECT uses same 29-industry list as Content Creator. New #industry-sector SELECT auto-populates sub-sectors. Data collection reads from #industry-sector (key industryDetails retained). version.php → 2026032401532.
 *
 * v1.5.31: VERSION BUMP — Clean release. version.php → 2026032401531.
 *
 * v1.5.30: CRITICAL FIXES —
 *           (1) Backup/restore class files renamed from backup_knowledgecheck_*.class.php
 *               to backup_aiknowledgecheck_*.class.php so Moodle's backup factory can
 *               resolve 'backup_aiknowledgecheck_activity_task' at module-delete time.
 *               Fixes adhoc task failure: "Class backup_aiknowledgecheck_activity_task not
 *               found" which caused course module deletion to fail via the recyclebin.
 *           (2) Upgrade savepoint order corrected: v1.5.24 (2026032300524) was listed
 *               after v1.5.25 (2026032400525) in upgrade.php, triggering a
 *               "cannot downgrade" exception on any upgrade path from v1.5.23 or earlier.
 *
 * v1.5.21: NEW FEATURE — PDF and Text file downloads now group results by attempt.
 *           When a student uses "Retry Wrong Answers", the exported file clearly shows
 *           "Attempt 1", "Attempt 2", … sections with per-attempt correct/incorrect counts.
 *           Each attempt section lists only the questions that belong to that attempt
 *           (correct carry-forwards keep their original attempt number; retry questions appear
 *           under the new attempt). Single-attempt quizzes render identically to before
 *           (no header added — fully backward-compatible).
 *
 * v1.5.17: ETA RECALIBRATE — Increased question time estimates: 45s→90s without
 *           voiceover, 60s→120s with voiceover. Accounts for reading, thinking,
 *           answering, and reviewing explanation feedback.
 *
 * v1.5.16: VERSION BUMP — Maintenance release.
 *
 * v1.5.15: RETRY WRONG ANSWERS + BUSINESS SERVICES — Added "Estimated Time to Complete" banners to both teacher
 *           ready screen (after questions generated) and student start screen.
 *           Clock icon gradient banner with dark mode support.
 *
 * v1.5.1: NEW FEATURE — "Download Question Mapping" button on the ready screen. Exports all generated questions to a CSV/Excel file with columns: Question Number, Question Text, Option A, Option B, Option C, Option D, Correct Answer (A/B/C/D), Correct Option Text, Explanation. Identical pattern to AI Essay Maker's criteria mapping export. BOM-encoded for Excel UTF-8 compatibility.
 *
 * v1.5.0: RESUME SCORE FIX — resumeAnswers() returns {answer:N, iscorrect:bool} objects.
 *         Old code did Number(savedOrig) which always produced NaN so iscorrect was never
 *         set and all question scores reset to 0 on resume. Fixed: read savedAns.iscorrect
 *         directly instead of coercing the object to a number.
 *
 * AI Knowledge Check v1.3.98 - AMD Build Sync + Upgrade Savepoint Fix
 *
 * v1.3.99: ATTEMPTS BADGE ICON SIZE FIX — updateAttemptsBadge() was cloning the SVG
 * from the existing badge element. JS-rendered badges (e.g. results screen) have no
 * width/height attributes, so the browser rendered them at 300×150 px default — making
 * the icon appear enormous. Fix: use innerHTML with an explicit 14×14 px SVG string.
 * CSS belt-and-braces: .kc-attempts-badge svg { width:14px; height:14px } added.
 * Result-screen badge SVG now includes width="14" height="14" attributes.
 *
 * v1.3.94: CONTINUE ATTEMPT POSITION FIX — Continue Attempt now resumes from the last answered question.
 *          Progress is saved to localStorage (key: kc_progress_{cmid}_{attemptId}) after each question
 *          advance and cleared on finish. Server-side currentquestion used as cross-device fallback.
 *          RETAKE RACE CONDITION FIX — Retake Quiz button is disabled until finishAttempt AJAX
 *          completes, preventing a fast retake from running with a nulled currentAttemptId and silently
 *          dropping all answer saves and the final finish call (which caused attempts count to stick at 1).
 *
 * v1.3.93: JOB TITLE MANUAL ENTRY FIX — Added "Other (enter manually)" support to the Job Title field.
 *          A hidden text input (#job-title-manual) appears when the user selects "Other" from the job
 *          title dropdown (slideDown animation). On quiz start, if the dropdown value is "other", the
 *          manually entered text is used as the jobTitle context value instead of the select value.
 *          Prevents blank job-title context being sent to AI when no predefined title matches the user.
 *
 * v1.3.92: LIVE ATTEMPTS BADGE FIX — Attempts count badge now updates in real-time when an attempt is completed.
 *          Root cause: PHP rendered $attemptslabel as a static text node in .kc-attempts-badge at page load; JS never
 *          touched those spans. Also: attemptsUsed was not passed to the JS config at all, so JS had no counter to work
 *          with. Fix: (1) view.php now passes attemptsUsed, attemptsUsedStr, attemptsUnlimitedStr in JS config; (2)
 *          finishAttempt() success callback increments config.attemptsUsed and calls updateAttemptsBadge() which
 *          clones the SVG icon, rebuilds the label, and updates both .kc-attempts-badge spans (ready screen + quiz header).
 *
 * v1.3.91: STUDENT VOICEOVER FIX — voiceover now plays correctly in student view. Root cause: savequestions was not persisting voiceoverenabled to the main DB record, so students always got voiceoverEnabled=0 from page config. Fix: savequestions now saves voiceoverenabled (and voice settings) to aiknowledgecheck table. Also hardened all JS voiceover checks from strict === 1 to !!truthy to handle both integer and boolean values.
 * v1.3.89: CRITICAL FIX — Voiceover audio now works correctly. Changed TTS encoding from MP3 to OGG_OPUS (Chirp3-HD voices only support OGG_OPUS, MP3 was silently failing). Added retry/logging via shared synthesizeOneChunk. Fixed student view missing voiceLanguage/voiceGender/voiceStyle config. Fixed audio Blob MIME type from audio/mpeg to audio/ogg.
 * v1.3.88: UX IMPROVEMENT — Added "All Levels" option to Job Level dropdown and "All Job Titles" option to Job Title dropdown, enabling generic question sets not tied to a specific level or role.
 * v1.3.87: SESSION LOCK FIX — Added \core\session\manager::write_close() after auth checks to prevent blocking concurrent requests during AI generation.
 * v1.3.86: LIVE TEST VERIFIED - HLTAID009 CPR generation confirmed: Bloom's taxonomy, cognitive error testing, plausible distractors, 17-23 word explanations, scenario-based stems
 * v1.3.85: PROMPT ENGINE REWRITE - ChatGPT-recommended multilingual consistency, banned word enforcement, JSON schema drift elimination, Australian English spelling conventions
 * v1.3.84: MOODLE 5 NAVIGATION FIX
 * [FIX] Removed @import url() from styles.css - breaks Moodle PHP CSS minifier when concatenated with other plugin CSS
 * [FIX] Google Fonts now loaded via $PAGE->requires->css() in view.php instead
 * [FIX] Prefixed all @keyframes names with kc- to prevent collision with Moodle/Bootstrap animations
 * [FIX] Replaced CSS inset shorthand with explicit top/right/bottom/left for CSS minifier compatibility
 * [FIX] Removed accent-color property (not supported by Moodle CSS minifier)
 *
 * v1.3.83: PROMPT QUALITY OVERHAUL (ChatGPT Audit Implementation)
  * v1.5.3: MULTI-SELECT JOB LEVELS + JOB ROLES — Replaced single job-level and job-title dropdowns
 * with multi-select pill buttons (job level, 4 options) and a chips text input (job roles, up to 5).
 * Consistent with Content Creator and Essay Maker UX pattern.
 *
 * [FIX] KC explanation enforcement loosened from exact phrases to semantic components (reduces false failures)
 * [FIX] 2 new distractor error types added: Sequencing Error and Scope Error for richer wrong answers
 * [FIX] Question style mix changed from strict odd/even alternation to 40/40/20 quota (scenario/direct/flexible)
 * [FIX] 7 hard-ban filler replacements + 9 soft filler detections in validateAndRepairQuestions
 * [FIX] All repair prompts upgraded with option homogeneity, Bloom's level, and expanded banned word rules
 *
 * v1.3.82: Credit cost display fix
 * [FIX] Dollar amount in credit formula now shows correct cents (was rounding to whole dollars)
 * [FIX] 5 questions now correctly shows $0.50 instead of $1, 3 questions shows $0.30 instead of $0
 *
 * v1.3.81: Moodle 5.x compatibility
 * [FIX] Replaced deprecated get_string('grade','grades') and get_string('gradepass','grades') with plugin-owned lang strings
 * [FIX] Eliminates deprecation warnings on Moodle 4.5+ and 5.x
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_aiknowledgecheck';
// VERSION-FIX (2026-08-12): $plugin->version was reset from the old 13-digit
// savepoint scheme (e.g. 2026072400226) down to a plain 10-digit Moodle-style
// version (2026072800), which was numerically LOWER than what was already
// installed on the live sites (confirmed via DB query: config_plugins.version
// for mod_aiknowledgecheck = 2026080500 on S02/S03/S07), triggering Moodle's
// "cannot downgrade" guard on every upgrade attempt. Bumped strictly above the
// installed value and above today's date-stamp to restore forward progress.
$plugin->version   = 2026081200;
$plugin->requires = 2022041900; // Moodle 4.0
$plugin->supported  = [400, 500];  // Moodle 4.0 to 5.x
$plugin->maturity = MATURITY_STABLE;
$plugin->release   = '1.5.129'; // ADD-SURVEY-FREETEXT (v1.5.127): Survey mode gains free-text questions and a rich attempts report. Teachers paste scale questions + free-text questions in separate areas; scale questions go to AI, free-text questions are saved as-is (questiontype=freetext). Students answer free-text questions in a textarea with no scoring. Report page detects surveymode=1 and switches to survey view: per-question response distribution (count + visual bar), free-text responses section, per-student breakdown accordion, Export CSV, and Print PDF. DB: questiontype CHAR(20) DEFAULT scale on aiknowledgecheck_questions. upgrade.php savepoint 2026072400226. // ADD-SURVEY-MODE (v1.5.126): Activity can now run as a survey tool. Teacher enables Survey Mode in activity settings, selects a response scale (9 industry-standard scales: Likert 5-point agreement/satisfaction/frequency/quality/importance; 4-point agreement; Yes/No; Yes/No/Unsure; NPS 5-point), then paste-generates question text. The AI produces survey questions suited to the scale; answer options are set server-side from the scale labels (no AI option generation). Students respond without correct/wrong feedback; results screen shows "Thank you for completing the survey" with a completion icon instead of a score ring. DB: surveymode INT(1) + surveyscale CHAR(50) on aiknowledgecheck; answer5 TEXT NULLABLE on aiknowledgecheck_questions. upgrade.php savepoint 2026072400225. // PERF-KC-AUDIO-PRELOAD (v1.5.121): Pre-buffer all explanation audio when each question is shown so voiceover plays with zero delay on Check Answer. Added audioPreloadCache, preloadCurrentQuestionAudio(qi) called from showQuestion() for current+next question. playExplanationAudio fast-path reuses pre-loaded Audio objects (currentTime=0+play). Question audio HTML: added preload=auto. AMD: src+build+min updated. // FIX-KC-IMAGEGATE-HARDGATE (v1.5.119): Per-question image converted from soft display to hard gate. When imageEnabled=1 on a question, student must click "I've reviewed this image — Continue" before answer options unlock. acknowledgedQuestions{} tracks state by question index; reset on retake so each attempt re-gates. Works in both normal quiz flow (showQuestion) and wrong-only retry (showQuestionWrongOnly) — retry round keys by realIdx so already-acknowledged images stay unlocked across rounds. AMD-only change (knowledgecheck.js src+build+min). No DB schema changes (fold in existing imageurl/imageenabled columns). No PHP changes. // DEFAULT-VOICE-ZEPHYR (v1.5.116): Changed default voice from Aoede to Zephyr in view.php (JS config, both render paths, both HTML selects) and ajax.php (all voiceId optional_param defaults). PHP-only. No AMD, CSS, or DB schema changes. // FIX-CURL-BATCH: ajax.php switched all raw curl_init() calls to Moodle \curl wrapper. No DB schema changes. Savepoint 2026051200114. // ADD-KC-TIMESTAMP-DIAG-STANDALONE (v1.5.113): New dedicated standalone timestamp_diag.php. Replicates exact server-side algorithms in PHP (parseTranscriptSegments dual-format parser + findBestTimestampForQuestion 5-segment window). Shows per-question: ideal segment vs stored segment, delta in seconds and segments, side-by-side transcript context, keyword hit/miss breakdown, top-5 scoring segment matrix. Nine sections: activity config, raw DB questions, transcript format A vs B comparison, full segment table with colour highlights, per-question alignment cards (green/amber/red), scoring matrix extracts, ordering/uniqueness checks, AMD seekTo integrity, actionable root-cause recommendations. Pure PHP — no AMD, JS, CSS, or DB schema changes. version.php → 2026050901113. // ADD-KC-DIAG-ACCURACY (v1.5.112): diag.php Section 10 — Timestamp accuracy analysis. Detects 7 failure modes that cause "timestamps feel too far forward/backward": (A) mixed MM:SS/HH:MM:SS transcript format causing silent parse-to-wrong-seconds; (B) non-ascending question timestamp order; (C) duplicate timestamps across questions; (D) evenly-spaced or clustered timestamps indicating fixed-interval fallback instead of real segment matching; (E) low word-overlap between question text and the transcript segment at the stored timestamp; (F) off-by-one segment — adjacent segment scores higher than stored segment by >0.08 Jaccard (the most common cause of "one step too early/late"); (G) AMD seekTo integrity — confirms parseInt() coercion before seekTo, allowSeekAhead=true for precise (not keyframe-only) seek, and playVideo() called after seek. Pure PHP diag addition — no AMD, JS, CSS, or DB schema changes. version.php → 2026050900112. // FIX-KC-SAVEEDITS-TIMESTAMP (v1.5.111): saveEdits() in knowledgecheck.js was not preserving timestamp_seconds, mappingTopic, or mappingCriteria from the original quizData when rebuilding the question list from the DOM edit form. Any teacher who visited "Edit Questions" and clicked "Save Changes" (even without changing anything) silently wiped these three fields from the in-memory quizData. On the next "Regenerate Questions" the JS sent timestamp_seconds=null for every question, so the server-side preserve step (which copies origTs → regeneratedQuestions[i].timestamp_seconds) never fired — and the database received null for all timestamps. Fix: editedQuestions.push() in saveEdits() now copies timestamp_seconds, mappingTopic, and mappingCriteria from quizData[idx] (falling back to null/'') before overwriting quizData. FALLBACK-TIMESTAMPS-REGEN-TOPLEVEL (v1.5.111): Added a second-chance fallback on the server (/api/knowledgecheck-regenerate-instructions) that uses the top-level textSources field PHP sends alongside sourceContext. Previously, if kcSourceContext was null (e.g. sourcecontext missing in DB for a legacy activity) the server had no transcript to assign timestamps from — now it parses top-level textSources as a backup. LOG-REGEN-TIMESTAMPS (v1.5.111): Added runtime console.log lines before and after the preserve step and all fallbacks, logging incomingTs values, nullAfterPreserve count, kcSourceContext presence, textSources length, and final per-question timestamps — making the root cause immediately visible in server logs on the next regen. AMD changes: knowledgecheck.js src + build + min. SaaS changes: server/routes.ts. No DB schema changes. No PHP changes. version.php → 2026050700111. // FIX-KC-Q1-FRESH (v1.5.107): After questions are saved (generate or regen), all in-progress attempts are deleted so the student always starts at Q1 with the new question set — previously a stale attempt caused the quiz to skip Q1. FIX-KC-TIMESTAMP-REGEN-TEXTSOURCES: regenerateinstructions now forwards useTextSources + textSources as top-level fields alongside sourceContext so the API can find the transcript and assign timestamp_seconds — previously these were only nested under sourceContext and the API returned null timestamps on every regeneration. // VERSION BUMP (v1.5.105): FIX-KC-REGEN-NOSCENARIOS + FIX-KC-TIMESTAMP-REGEN — Two bugs fixed in server/routes.ts only (SaaS-only, no PHP/JS/AMD/CSS/DB changes). (1) FIX-KC-REGEN-NOSCENARIOS: "Regenerate Questions" was generating long workplace scenario-based questions (e.g. "A landscaping company is reviewing…") even when the teacher had "Add Workplace Context" toggle OFF. Root cause: the /api/knowledgecheck-regenerate-instructions prompt had no conditional gating on workplaceContextEnabled — it always allowed scenarios regardless of the setting. The initial generate prompts were fixed in v1.5.96 (SELF-CHECK ternary-gated) but regenerate-instructions was never updated to match. Fix: added const regenWorkplaceEnabled = kcSourceContext?.workplaceContextEnabled === true, then gated the QUESTION STYLE section of the prompt — when OFF: "ZERO scenario-based questions, ALL direct application, NO named persons/companies/narratives" with explicit right/wrong examples; when ON: brief scenario framing permitted. A SELF-CHECK block added at the end of the prompt (LLM recency bias fix) reinforces the constraint with a scan-and-rewrite instruction when workplace context is OFF. The return format JSON hint is also conditionalised. (2) FIX-KC-TIMESTAMP-REGEN: After "Regenerate Questions", all timestamp_seconds became NULL even though the transcript had timestamps and the original questions had them. Root cause: the regenerate-instructions endpoint did not copy timestamp_seconds from the input questions to the regenerated questions — the AI prompt doesn't include the transcript so the AI can't re-derive them. The identical preservation fix existed in regenerate-settings (v1.5.64) but was missing from regenerate-instructions. Fix: added origTs = questions[i]?.timestamp_seconds preservation inside the normalise/validate loop, after fixExplanationOrder+shuffleQuestionAnswers, mirroring the settings endpoint. Timestamps now survive every subsequent regeneration round because: JS sends timestamp_seconds in the allQuestions payload (v1.5.92), server copies them back (this fix), JS double-fallback also catches any server omission (v1.5.92), DB saves them (ajax.php line 495), and all four JS mappers return them (v1.5.102). version.php → 2026050500105. // VERSION BUMP (v1.5.104): FIX-KC-SETTINGS-TIMESTAMP + FIX-KC-DIAG-S8 — Two bugs fixed. (1) FIX-KC-SETTINGS-TIMESTAMP: The applySettingsQuestions mapper (regeneratewithsettings path — triggered when teacher changes voice language, gender, or style) was missing the double-fallback for timestamp_seconds. v1.5.102 added a single-check (q.timestamp_seconds !== null) but did not add the fallback to the original quizData[i].timestamp_seconds when the server omits the field. Result: if the server response for regeneratewithsettings omits timestamp_seconds, the Jump-to chapter button vanishes after any settings change. Fix: snapshot quizData before regen (preRegenQuizData), add index i to map callback, use same preservedTs double-fallback pattern as applyBatchQuestions (regenerateinstructions). (2) FIX-KC-DIAG-S8: diag.php Section 8 settings-regen mapper check used strpos($mapper_src, 'isObjOpts') which found the FIRST occurrence in the file (inside the teacher getquestions mapper) rather than the occurrence inside applySettingsQuestions. Fix: search for context marker starting from the primary marker position, not from the start of the file. Also widened window from 500 to 600 chars. AMD: src updated, build/min regenerated (src=build=min). version.php → 2026050400104. // VERSION BUMP (v1.5.102): FIX-KC-MAPPER-TIMESTAMP — Three out of four JS quizData mappers in knowledgecheck.js were stripping timestamp_seconds from the getquestions API response when transforming to internal quiz format. The server (ajax.php line 829) correctly returned the field but the teacher getquestions mapper (line 532), student getquestions mapper (line 1652), and settings-regen mapper (line 3652) all omitted it from their return objects. Result: q.timestamp_seconds was always undefined in showQuestion(), the condition `q.timestamp_seconds != null` was always false, and the Jump-to button never rendered — even though the DB, config, and build were all correct. The regenerate-instructions mapper (line 3914) was the only one that preserved the field. Fix: all three mappers now include `timestamp_seconds: (q.timestamp_seconds !== undefined && q.timestamp_seconds !== null) ? q.timestamp_seconds : null`. Diag Section 8 added: reads the knowledgecheck.js source and checks that every quizData mapper includes timestamp_seconds in its return object — catches this class of "mapper strips field" bug permanently. AMD: src updated, build/min regenerated. version.php → 2026050400102. // VERSION BUMP (v1.5.101): FIX-KC-DIAG-TABLE — diag.php Sections 4 and 5 were reading from $knowledgecheck->questions (a column that does NOT exist on the aiknowledgecheck table). Questions are stored in the separate aiknowledgecheck_questions table. This caused Section 4 to always show "No questions generated yet" and Section 5 Condition 2 to always FAIL, even when questions were present. Fix: both sections now query aiknowledgecheck_questions directly. Section 4 now shows per-question timestamp_seconds values with formatted timestamps. Section 7 added: inspects the pasted transcript (from sourcecontext) for YouTube-style timestamp markers (e.g. 0:08, 1:09). If the transcript has no time markers, the AI sets timestamp_seconds=null — this section explains exactly why and how to fix it. version.php → 2026050400101. // VERSION BUMP (v1.5.100): diag.php extended with two new diagnostic sections. Section 5 simulates exactly what view.php puts into the JS config object and evaluates each of the three conditions in if(config.showChapterStamps && q.timestamp_seconds!=null && config.hasVideo) — FAILing whichever specific condition is false so the root cause is data-driven. Section 6 checks whether knowledgecheck.min.js contains the kc-chapter-stamp rendering code (v1.5.97+ build). Also fixed two pre-existing bugs: (a) Section 4 used $knowledgecheck->videoid (non-existent column) instead of parsing videourl the same way view.php does. (b) Header line showed same non-existent column. Diag tool version header bumped to v1.5.100. version.php → 2026050400100. // VERSION BUMP (v1.5.99): diag.php now reads version dynamically from version.php instead of hardcoded string; Moodle bump so upgrade detection recognises the updated diag. version.php → 2026050400099. Bump so Moodle upgrade detection recognises updated diag.php (id= alias, course picker, graceful bad-ID handling). version.php → 2026050400098. Previous: FIX-KC-NOSCENARIOS-SELFCHECK: Knowledge Check Video Gate (and any other path supplying a text source) was generating workplace-scenario questions with named characters even when "Use workplace context" toggle was OFF. Root cause: server/routes.ts SELF-CHECK section in BOTH prompt builders (generateKnowledgeCheckQuestions topic-only and generateKnowledgeCheckQuestionsFromPdf transcript/PDF path) had UNCONDITIONAL "At least 40% scenario-based" + "UNIQUE NAMES across all N questions" lines that contradicted the earlier "NO NAMED PERSONS — ABSOLUTE" ban. LLM recency bias → late-prompt SELF-CHECK directive overrode the early ban → scenarios + names appeared. Fix is SaaS-only: both SELF-CHECK blocks are now ternary-gated on workplaceContextEnabled (when OFF: "ZERO scenario questions: 100% direct application — no named persons" + "NO NAMES CHECK: scan all N questions one final time"). The example JSON schema "question" hint in the PDF/transcript prompt is also conditionalised. No DB schema, PHP, JS, AMD, or CSS changes — plugin already correctly forwards workplaceContextEnabled. Plugin version bumped purely for traceability. Files: server/routes.ts (lines 16936, 17163, 17173). version.php → 2026050100096.
