# Changelog - AI Knowledge Check Activity Module

All notable changes to this plugin will be documented in this file.

## [1.5.139] - 2026-08-27

### Fixed
- **FIX-KC-EDIT-SURVEY — teacher Edit Questions screen destroyed survey data on save** (`amd/src/knowledgecheck.js`): the editor was written for 4-option quizzes and was never updated when survey mode added a 5th scale option (v1.5.126) and free-text questions (v1.5.127). Four defects, two of them destructive:
  - The option render loop was hardcoded to 4 (`optionLabels = ['A','B','C','D']`), so the 5th point of a 5-point scale (e.g. "Strongly Disagree") was never displayed, and 2- and 3-point scales were padded with blank option boxes.
  - Free-text questions were rendered as multiple choice with four empty option boxes. Because empty options fail validation, this also made the whole activity impossible to save ("Question N, Option A cannot be empty").
  - **Data loss:** both save paths hardcoded `options[0..3]`, and `ajax.php` sets `answer5 = null` when `options[4]` is absent — so saving from the editor permanently deleted the 5th scale option.
  - **Data loss:** neither save path sent `questionType`, and `ajax.php` defaults `questiontype` to `'scale'` when the field is absent — so saving converted every free-text question into a blank multiple-choice question. Since saving one question saves them all, editing any question corrupted every free-text question in the activity.

  The editor now renders the question's real option count (2–5 in survey mode; a minimum of 4 retained in quiz mode), renders free-text questions as free-text with no option fields, and skips option and correct-answer validation for them. Both save paths send every option the question has plus its `questionType`. Survey mode also hides the correct-answer radios, the "(select the correct answer)" hint and the per-option explanation boxes, none of which apply without a correct answer.
- **Question CSV export** gained an `Option E` column; 5-point scales previously exported only four options.

### Changed
- **VERSION BUMP**: `version.php` → `2026082700` (release `1.5.139`). No DB schema changes, no new upgrade savepoints. AMD artifacts rebuilt. Builds on v1.5.138, whose payload-coercion and survey-save fixes are retained unchanged.

### Note for existing surveys
Any survey saved from the Edit Questions screen on 1.5.126–1.5.138 may already have lost its 5th scale option and had its free-text questions converted to scale questions. This release stops further damage but cannot recover data already overwritten — check affected activities and repair them manually. Quiz-mode activities are unaffected.

## [1.5.138] - 2026-08-26

### Fixed - survey mode could not save its questions

- **"Questions could not be saved to Moodle — mysqli::real_escape_string(): Argument #1
  ($string) must be of type string, array given."** `saveQuestionsToDatabase()` read
  `q.options[n]` as a string and wrapped it as `{ text: q.options[n] }`. Freshly generated
  questions arrive straight from the generation service through the `status` passthrough, which
  emits options as `{text, explanation}` **objects** — the same shape this file sends back in
  its own regenerate payload. So `.text` held an object, PHP received an array where it expected
  a string, and the insert died inside mysqli.

  Worse than the error: `delete_records()` runs *before* the insert loop, so by the time the
  teacher saw the alert their existing questions were already gone.

- **A five-point survey scale lost its fifth option.** The same mapping hardcoded exactly four
  options, so `answer5` — added in 1.5.126 precisely for 5-point scales — was never populated
  from a fresh generation.

- **Freetext questions were stored as scale questions.** The payload never sent `questionType`
  at all, so the server's `?? 'scale'` fallback applied to every question, and a freetext
  question also arrived carrying four empty options.

  The mapping now normalises an option written either way, emits the true number of options
  (none for freetext), and sends `questionType`.

- **Short survey scales showed blank options in the player.** `getquestions` filtered only the
  explicit null in the `answer5` slot, so `answer1`–`answer4` always came back as four options
  even when the scale is shorter — a two- or three-point survey rendered empty radio choices.
  `report.php` has always compacted with `if (!empty($sq->$f))`, so the player and the report
  disagreed about the same question. Trailing blanks are now trimmed by
  `mod_aiknowledgecheck_trim_options()` and the two agree.

  Trailing blanks only. `correctanswer` and every stored student answer are positional indexes
  into this list, so compacting around a gap in the middle would silently repoint them at a
  different option. A blank in the middle means a broken question and stays visible.

- **The PHP save path no longer lets a non-scalar reach the database.** Every text field is
  coerced to a string and options are accepted as a plain string or a `{text, explanation}`
  object, so a malformed payload produces an empty field rather than a fatal mysqli TypeError
  after the delete has already run.

Verified with two harnesses: the PHP record-builder against the real generated payload and five
hostile shapes, and the shipped `amd/src` mapping extracted and run against generated, reloaded
and hostile question sets. The pre-fix code fails exactly three assertions — the array, the lost
fifth option and the wrong question type — and the post-fix code passes all of them.

AMD bundle rebuilt; `amd/build/knowledgecheck.js` matches `amd/src`, and the minified bundle
carries the fix.

## [1.5.137] - 2026-08-26

### Fixed - non-editing teachers could not reach the attempts report

- **The Attempts report link was nested inside the authoring branch of `view.php`.** The page
  chose between a teacher view and a student view on `mod/aiknowledgecheck:create`, whose
  archetypes are `editingteacher` and `manager`. A non-editing teacher fails that, landed in the
  student branch, and the report button — rendered only inside the branch they never entered —
  did not exist for them.

  `mod/aiknowledgecheck:viewreports` already lists `teacher`, and `report.php` requires only
  that capability, so the report page would have served them the whole time. There was simply no
  link to it. The capability was defined correctly; the page asked the wrong question.

  The staff navigation is now rendered for anyone holding `:viewreports`, outside the authoring
  branch. The "More attempts" link is gated separately on `mod/aiknowledgecheck:manageoverrides`,
  which is what `moreattempts.php` requires — offering a link to a page that will reject you is
  worse than not offering it.

- **Media gates held course staff on the learner's acknowledge screen.** `$takegated` and the
  image gate both tested `!$cancreate`, so a non-editing teacher had to sit through the video,
  audio or image gate before reaching an activity they are there to mark. Both now test
  `$cancreate || $canviewreports`.

  The video and audio locks carried no staff exemption at all, while the image lock did — so
  fixing only the image gate would have freed a marker from one gate and left them held by the
  other two. All three now exempt course staff. The gate banners and the watcher scripts are
  untouched; with no lock registered there is nothing for them to hold.

`mod/aiknowledgecheck:create` is unchanged as the authoring gate: it still decides whether the
builder, the credits badge and the generation controls are rendered. Only the staff-versus-learner
question moved.

## [1.5.136] - 2026-08-26

### Changed
- Release-plumbing version bump only, with no functional changes from 1.5.135. This release uses a new version because the immutable `v1.5.135` Git tag already existed.

## [1.5.135] - 2026-08-24

### Fixed
- Survey scale questions now preserve their authored option order (including all five choices), then use a direct **Next** / **Submit Survey** flow after selection. They never show **Check Answer**, correct/incorrect styling, grading feedback, answer keys, or answer audio; the server stores Survey Mode responses without calculating a correctness verdict.
- Fixed the Survey Attempts Report and CSV query to select the real `questiontext` database column (aliased as `question`) instead of the nonexistent `question` column.
- Added a guarded, idempotent reconciliation migration for all Survey Mode columns: `surveymode`, `surveyscale`, `answer5`, and `questiontype`. This repairs sites whose rebased savepoints were skipped despite a newer stored plugin version.
- Added the stranded-version repair path for sites whose legacy 13-digit recorded version is higher than the plugin's 10-digit version.

## [1.5.57] - 2026-04-09

### Added
- **Show chapter timestamp links** — New activity setting (`showchapterstamps`). When enabled, a clickable "Jump to X:XX" button appears above each quiz question. Clicking seeks the YouTube video to the AI-identified timestamp nearest to the chapter that question covers and resumes playback. The YouTube player is now exposed as `window.kcYtPlayer` (via the `onReady` event in `view.php`) so the AMD module can seek without needing direct access to the inline-scoped `player` variable. The AI prompt now returns `timestamp_seconds` (int|null) per question.

All notable changes to this plugin will be documented in this file.

## [1.5.56] - 2026-04-10

### Added
- **"Show video while answering questions" activity setting**: New checkbox in the Video Gate section of the activity settings form. When enabled, the video player remains visible above the question panel while the student is answering questions — useful when the video content is needed as reference material during the quiz. When disabled (the default), the video hides as soon as the student starts answering, preserving the existing behaviour. DB: `showvideoduringquiz` (int, default 0). version.php → 202604100056.

## [1.5.48] - 2026-03-28

### Changed
- **VERSION BUMP**: Maintenance release. No code or DB schema changes. version.php → 2026032800148.

## [1.5.47] - 2026-03-28

### Changed
- **VERSION BUMP**: Maintenance release. No code or DB schema changes. version.php → 2026032800147.

## [1.5.46] - 2026-03-28

### Fixed
- **BUG-KC-AUDIOGATE-TEACHER** (`view.php`): Teachers visiting the Knowledge Check always entered the teacher-creator view which never rendered the audio/video gate HTML or the JS gate coordinator — the Take Quiz button was always enabled and no audio player was visible. Root cause: gate variable setup (`$hasaudio`, `$audiogated`, `$anygated`, etc.) was inside the student `else` block only. Fix: all gate variables now computed once before the teacher/student split. Teacher ready-section now includes the audio player card, a disabled Take Quiz button (`kc-gated-btn`) when a gate requirement is set, and the same `kcGate` JS coordinator and audio listener as the student view. No DB schema changes.

## [1.5.45] - 2026-03-27

### Fixed
- **BUG-KC-REGEN-DUPE** — `checkExistingQuestions()` was dynamically injecting a second `#regenerate-btn` into the DOM. Clicking it called `regenerateQuestions()` which reset the form and set `quizData=null` instead of invoking `handleRegenerateWithInstructions()`. Removed the duplicate button injection. The `view.php` `#ready-regenerate-btn` is now the sole regenerate button.

## [1.5.40] - 2026-03-26

### Added
- **Add More Questions** — New "Add More Questions" button on the teacher ready screen. Teachers can append additional questions to an existing question set without regenerating all questions from scratch. Clicking the button preserves the current questions, returns to the generation form with an info banner showing how many existing questions will be kept, and upon successful generation the new questions are concatenated to the existing set. If generation fails for any reason (API error, polling timeout, or generation failure), the existing questions are automatically restored and the teacher is returned to the ready screen. The combined question set is saved to the database using the existing save mechanism (delete + re-insert all).

## [1.5.38] - 2026-03-26

### Fixed
- **BUG-KC-QPT-CAP: Question count capped at 5** — The "Questions Per Topic" dropdown in the teacher generation form was hardcoded with only options 1-5. Teachers could never select more than 5 questions per topic regardless of how many topics they entered. Dropdown now includes options 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 12, 15, 20 (default remains 5). The server already supported up to 20 — only the UI was limiting it.
- **BUG-KC-TOKEN-FLOOR: Token budget causes JSON truncation at 10+ questions** — The token budget formula `Math.min(15000, Math.max(6000, count * 600))` allocated only 6000 output tokens for a 10-question batch. The 3-part mandatory explanation format (Correct: principle + why it matters; Incorrect: cognitive error + why it fails + correction) requires ~700-900 tokens per question, meaning 10 questions need 7000-9000 tokens. With 6000 max_tokens the AI response was truncated mid-JSON, causing `safeParseAIResponse` to return a partial object. The `validateQuestionSetStructure` error was caught per-topic, silently producing 0 questions for affected topics. Formula raised to `Math.min(15000, Math.max(8000, count * 900))` — floor of 8000 for all batch sizes, 9000 for 10 questions (25% headroom above worst-case token usage).
- **BUG-KC-TOKEN-PDF: Same token truncation in PDF/text-source mode** — Identical formula fix applied to `generateKnowledgeCheckQuestionsFromPdf` which handles text-paste and PDF content sources.

## [1.5.15] - 2026-03-22

### Added
- **Retry Wrong Answers Only** — when retaking the quiz, students now see a primary "Retry Wrong Answers (N)" button that only presents the questions they got wrong. Correct answers are pre-saved to the new attempt for accurate gradebook scoring. A secondary "Retake Full Quiz" button remains available for a complete retake.

## [1.5.14] - 2026-03-22

### Added
- **ETA Banners** — "Estimated Time to Complete" banners added to teacher ready screen and student start screen. Formula: ~45s per question (60s with voiceover).

## [1.5.3] - 2026-03-19

### Added
- **AI Quiz Remedial Learning integration** — after a student finishes a Knowledge Check attempt, `ajax.php finishattempt` now creates an umbrella `local_aiqr_job` record (sourcetype `knowledgecheck`) for that attempt when `local_aiquizremedial` is installed and enabled. The Remedial Learning cron task subsequently generates one AI explanation module per wrong answer. The hook is wrapped in a full try-catch so that any error never interrupts the KC attempt submission.

## [1.5.0] - 2026-03-18

### Fixed
- **BUG-KC-RESUME**: Score reset to 0 on resume. resumeAnswers() returns {answer:N, iscorrect:bool} objects — old code coerced the object with Number() producing NaN, so iscorrect was never read and all previously-earned points were wiped. Fix: read savedAns.iscorrect directly.


## [1.3.94] - 2026-03-12

### Fixed
- Continue Attempt now resumes from the last answered question instead of always restarting at Q1. Progress is saved to localStorage after each question advance and restored when the student continues. Server-side `currentquestion` is used as a cross-device fallback for the first load.
- Attempts count race condition: clicking Retake Quiz before the finish AJAX completes no longer causes the new attempt to run without an attempt ID (which silently dropped all answer saves and the final finish call, leaving the count stuck at 1). The Retake Quiz button is now disabled until the finish round-trip completes. An attempt-ID capture guard prevents the finish callback from clearing a new attempt's ID.

## [1.3.93] - 2026-03-12

### Fixed
- Added manual text input for Job Title "Other" option. When a user selects "Other (enter manually)" from the job title dropdown, a text field slides into view so they can type their actual job title. The manually entered value is sent as the AI context job title instead of the blank select value.

## [1.3.34] - 2026-02-05

### Fixed
- Fixed voiceover audio not playing - added missing `audiodata` column to `aiknowledgecheck_questions` table
- Database upgrade step automatically adds the column for existing installations
- Teachers must regenerate audio after upgrading for existing knowledge checks

## [1.3.29] - 2025-12-22

### Fixed
- Fixed PHP 8.4 implicit nullable parameter deprecation warnings in add_instance and update_instance functions

## [1.3.28] - 2025-12-22

### Changed
- Added official Moodle 5.x compatibility declaration (`$plugin->supported = [400, 500]`)



## [1.3.26] - 2025-12-20

### Changed
- Migrated to centralized download architecture
- Updated versioned ZIP filename

## [1.3.0] - 2025-12-01

### Added
- Enhanced question generation
- Improved feedback system
- Mobile-responsive design

## [1.0.0] - 2025-06-01

### Added
- Initial release
- AI-powered knowledge check generation
- Multiple question types
- Moodle 4.0+ compatibility
