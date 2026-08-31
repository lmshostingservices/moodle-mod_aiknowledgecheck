# Changelog - AI Knowledge Check Activity Module

All notable changes to this plugin will be documented in this file.

## [1.5.164] - 2026-08-31

Fixes three release-pipeline findings that were entirely self-inflicted: the 1.5.163 note in
`version.php` quoted the exact tokens the pipeline's scanner looks for.

### Fixed — version.php prose tripped two blockers and an error
`version.php` contains no executable code beyond the version constants; everything else is a
release note. The scanner reads comments, and the 1.5.163 note named a request superglobal, a raw
parameter constant, and an anonymous-function spelling while explaining how each had been dealt
with. Each mention was reported as a finding in its own right:

- "No unsafe raw parameter usage" — blocker
- "No direct request superglobal access" — blocker
- Anonymous-function spacing — error

The note now describes those rules in words instead of reproducing them. **This is the second
time it has happened** — v1.5.155 hit the same trap — so the note itself now carries a warning
for whoever writes the next one.

A local pre-check script was added to the project notes that scans the plugin the same way, so
this class of finding surfaces before staging rather than after a round trip.

### Unchanged
Everything 1.5.163 actually fixed is still in place: no request superglobal in the tests, no two
PHP statements on one line in `view.php`, and the 26 phpcs annotations in block-comment form.

### Still open, deliberately
Two pipeline warnings remain because satisfying either would break Moodle Plugin CI, which is the
gate that blocks the release:

- **Anonymous-function spacing in the AMD modules.** Moodle's own `.eslintrc` requires no space
  before the parenthesis and Plugin CI runs grunt with `--max-lint-warnings 0`.
- **The `pipeline-ignore` markers.** They must stay lowercase and unpunctuated for the pipeline's
  own scanner to match them; capitalising them risks reintroducing a security blocker. This
  release confirms the diagnosis: the comment-style warning still names exactly the eleven files
  that carry those markers, after the phpcs annotations in them were converted in 1.5.163.

### Version
- `version.php` → `2026083021` (release `1.5.164`). No DB schema changes; the 1.5.150 savepoint is
  unchanged. `v1.5.157` remains untouched.

## [1.5.163] - 2026-08-31

Clears the release pipeline's approval blocker and two of its four warnings.

### Fixed — blocker: superglobal access in a test
`tests/permissions_test.php` set `$_POST['sesskey']`. It drove every service through
`external_api::call_external_function()`, which finishes with `require_sesskey()` and therefore
needs a request to exist.

The test now calls each service's `execute()` directly, with placeholder arguments built from
`execute_parameters()` in declaration order. `execute()` performs the same
`validate_parameters()` and `require_capability()` work, so the behaviour under test is reached
by the same path — there is simply no request to fake. Re-checked by mutation: removing the
capability check from `save_questions` still fails the test.

### Fixed — warning: two PHP statements on one line
`view.php` emitted the gated button's class and disabled attributes as two `<?php ?>` islands on
one physical line. They are now built together as `$gatedattrs`.

### Fixed — warning: multi-line calls with code after the opening parenthesis
`tests/external_test.php`, `tests/generator/lib.php` and `tests/privacy_provider_test.php` now
put the first argument on its own line. Where that would have produced deeply nested calls the
code was restructured instead: the privacy test gained a `count_rows()` helper and the external
test an `attempt_count()` helper, which is clearer than either formatting. Verified that Moodle's
own phpcs accepts this style before applying it.

### Fixed — warning: comment blocks beginning with a lowercase letter
The 26 `// phpcs:ignore moodle.Commenting.InlineComment` annotations are now written as
`/* phpcs:ignore ... */`. Confirmed against phpcs that the block form still suppresses the sniff.

### Not fixed, deliberately — two warnings that conflict with Moodle Plugin CI
- **`function(` → `function (`.** Moodle's own `.eslintrc` sets
  `'space-before-function-paren': ['warn', 'never']`, and Plugin CI runs grunt with
  `--max-lint-warnings 0`. Adding the space would fail the gate that actually blocks the release.
- **The `pipeline-ignore` markers.** They must stay lowercase and unpunctuated for the pipeline's
  own PARAM_RAW scanner to match them. Capitalising them to satisfy the comment-style warning
  risks reintroducing the PARAM_RAW blocker, which is a worse trade.

### Version
- `version.php` → `2026083020` (release `1.5.163`). No DB schema changes; the 1.5.150 savepoint is
  unchanged. `v1.5.157` remains untouched.

## [1.5.162] - 2026-08-31

Removes the last hardcoded English from the PHP, so the plugin meets the contribution
checklist's requirement that text always come from `get_string()`.

### Fixed — 95 hardcoded strings in view.php and report.php
1.5.161 internationalised the JavaScript; this does the same for the server side. Buttons
("Edit Questions", "Save Changes", "Add More Questions"), headings ("Quiz Settings",
"Student Completions"), the settings modal, country and state names, and the report's empty
states all now come from the language pack. `view.php` and `report.php` contain no user-visible
English at all.

Two of these were the same drift the voice list had in 1.5.161: **51 `lang_*` strings and the
`voice_*` strings already existed in the language file and the markup was ignoring them.** The
two language dropdowns therefore disagreed with each other — the generate form read
"Spanish (Latin America)" from the language pack while the settings modal had "Spanish (US)"
baked into the HTML. Both now read the same string.

The settings modal's "Voice Settings" heading was briefly mapped to `voice_settings`, which is
"Language & Voice Settings" and belongs to the generate form. It has its own string now.

### Verified
The page was rendered under 1.5.161 and 1.5.162 across six activity configurations for both a
teacher and a student, and the parsed DOM compared. The single rendered difference is the
deliberate "Spanish (US)" → "Spanish (Latin America)" consistency fix.

### Marketplace review
With this release the plugin clears every item on the plugin contribution checklist's approval
blocker list that is answerable in code: PostgreSQL compatibility (fixed in 1.5.160), privacy API
for the external service, backup and restore, namespace collisions, security, and hardcoded
English. The two remaining items are not code: a public issue tracker, and the reviewer's
judgement on overlap with Moodle commercial products.

### Version
- `version.php` → `2026083019` (release `1.5.162`). No DB schema changes; the 1.5.150 savepoint is
  unchanged. `v1.5.157` remains untouched.

## [1.5.161] - 2026-08-31

Makes the interface translatable, accessible and free of inline JavaScript. No behavioural change
was intended, and each part was verified rather than assumed.

### Added — internationalisation (113 strings)
The AMD module contained **no language strings at all** across 5,751 lines: every message,
button and status readout was hardcoded English. The plugin offered voiceover in 52 languages
while its own interface could not be translated at all.

All of it now loads through `core/str` in a single request, read synchronously from one map, with
a small `fmt()` helper that substitutes `{$a}` and `{$a->name}` client-side exactly as the server
does. Two things surfaced while doing it:

- The narrator voice list was written out in **three** places and had drifted out of step with
  the labels `view.php` renders — teachers saw "Zephyr (energetic, youthful)" in one dropdown and
  "Zephyr (Gentle)" in another. One helper now feeds both, from the language pack.
- Another hardcoded `alert()` was hiding in an inline script in `view.php`, which the first sweep
  of the AMD module could not have found.

Country, state and industry names are deliberately left as data: their values are sent to the AI
service, so they are not interface text.

### Changed — 56 native dialogs became Moodle modals
`window.alert()` and `window.confirm()` block the whole page, cannot be themed, are unreachable
to assistive technology, and are suppressed outright in some embedded contexts. Every call now
goes through `core/notification`. The four `confirm()` sites needed real restructuring, because a
modal is asynchronous and the old code branched on a boolean inline — `showEditMode()` and
`handleRegenerateWithInstructions()` were each split so the work continues in a callback.

Moodle 4.3 and later ignore the decline-button label and always show "Cancel"; the argument is
still passed because this plugin supports Moodle 4.0, where it is honoured.

### Added — accessibility
The answer options were mouse-only, and the whole module carried a single ARIA attribute.

- The options are a proper radio group: `role="radiogroup"` labelled by the question, each option
  `role="radio"` with `aria-checked`, and a roving `tabindex` so the group is one tab stop.
- Arrow keys move and select, Space and Enter select. The handler reads both `KeyboardEvent.key`
  and the numeric code, so synthetic and older events work.
- Answered options publish `aria-disabled`.
- The result, question counter and score are live regions, so a screen reader announces grading —
  focus does not move, so nothing was announced before.
- Focus moves to the question heading on each new question.
- The free-text answer box has a label; its placeholder is translatable.

A Behat scenario tabs from the question into the options and selects an answer **using the
keyboard alone**. That path did not previously exist.

### Changed — no inline JavaScript left
All six inline `<script>` blocks, about 520 lines, moved into three AMD modules:
`mod_aiknowledgecheck/mediagates` (video, audio and image gates plus the gate coordinator),
`mod_aiknowledgecheck/imagegen` (the teacher's image generator) and `mod_aiknowledgecheck/util`
(a shared placeholder formatter). `view.php` now contains no `<script>` tag at all, which also
makes the page compatible with a strict Content-Security-Policy.

This removed a real bug: the retake button label was produced by `get_string()` interpolated into
a **single-quoted JavaScript string literal**. Any translation containing an apostrophe — French,
Italian, and others — would have ended the string early and thrown a syntax error, breaking the
entire gate script and leaving the quiz permanently locked.

The gate coordinator was a `window.kcGate` global; it is now a module dependency.

### Fixed — JavaScript string escaping
Fourteen places used `addslashes()` to inject PHP values into inline scripts. `addslashes` escapes
quotes and backslashes but **not** newlines or a literal `</script>`, either of which ends the
script early. All now use `json_encode()` with `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`,
so no translated string can escape its context.

### Changed — presentation moved out of the markup
The 53 presentational `style=""` attributes in `view.php` are now 28 CSS classes in `styles.css`.
The only inline styles left are `display: none`, which jQuery's `show()`/`hide()` reads and writes
directly — replacing those with a class would break the toggles.

Verified rather than assumed: the page was loaded in a real browser before and after and
`getComputedStyle` compared property by property for every affected element, across the teacher
and student views. All 28 classes produce identical computed values.

### Version
- `version.php` → `2026083018` (release `1.5.161`). No DB schema changes; the 1.5.150 savepoint is
  unchanged. `v1.5.157` remains untouched.

## [1.5.160] - 2026-08-31

Adds Behat coverage, and fixes a crash it immediately found.

### Fixed — adding an activity crashed on PostgreSQL
`mod_form.php` guarded a gradebook lookup with `isset($defaultvalues['instance'])`. When the form
is opened to *add* an activity Moodle supplies an empty string for `instance`, and `isset('')` is
true, so the lookup ran with `''` where an integer was expected.

- MySQL and MariaDB coerce `''` to `0` and return no rows, so nothing appeared wrong.
- PostgreSQL rejects it and throws `dml_read_exception`, so **the add-activity form failed
  outright** on that database, and had done since the lookup was added.

The `(int)` casts on `iteminstance` and `courseid` are what make the query safe; the guard now
also tests for a positive ID so a lookup that can never match is not run at all. This was found by
the first Behat scenario that opened the add-activity form — no amount of manual testing on a
MariaDB site would ever have shown it.

### Added — Behat suite (18 scenarios, 189 steps)
- `student_journey.feature` — answering every question through to the results screen, a wrong
  answer scoring 50%, resuming an in-progress attempt after a reload, being blocked at the attempt
  limit, and an override unblocking a student. Runs the real JavaScript in Chrome.
- `survey_mode.feature` — a survey has no Check Answer step, accepts a typed free-text response
  containing `<`, and ends on a completion message with no score.
- `teacher_report.feature` — the attempts report and the extra-attempts screen, and that a student
  sees neither.
- `settings.feature` — creating an activity, the attempt limit round-tripping, unlimited attempts,
  survey mode round-tripping, and validation rejecting a negative limit.
- `permissions.feature` — teacher, student and guest access to the activity page.
- `tests/generator/behat_mod_aiknowledgecheck_generator.php` — lets feature files seed questions,
  attempts and overrides without calling the AI service.

### Fixed — a real weakness in the 1.5.159 tests
Mutation testing the new suites exposed a defect that **both** suites missed: making
`start_attempt` insert a new row on every call while still returning the existing attempt ID. The
PHPUnit test asserted the response and the Behat scenario asserted the visible page, and neither
looks at the stored rows, so a silent duplication of every student's attempts passed clean.
`test_start_attempt_creates_then_resumes` now asserts the attempt count, and a new
`test_start_attempt_is_idempotent_while_an_attempt_is_open` calls start five times and asserts one
row. Both fail against that mutant.

Two other mutations were run and caught: survey mode exposing the quiz Check Answer step (caught by
`survey_mode.feature`), and the PostgreSQL crash above reintroduced in full (caught by all four
`settings.feature` scenarios).

### Note — Behat runs the built AMD bundle
`javascript.php` serves `amd/build/knowledgecheck.min.js`, not `amd/src`. A change to the source
that has not been through `grunt amd` is not what the Behat tests exercise.

### Version
- `version.php` → `2026083017` (release `1.5.160`). No DB schema changes; the 1.5.150 savepoint is
  unchanged. `v1.5.157` remains untouched.

## [1.5.159] - 2026-08-31

Adds the plugin's first automated tests, and fixes two rendering regressions that 1.5.158's
line-wrapping introduced.

### Added — PHPUnit suite (41 tests, 162 assertions)
- `tests/generator/lib.php` — a data generator that seeds activities, questions, attempts and
  overrides directly, so tests never call the remote AI service.
- `tests/lib_test.php` — attempt limits and overrides: unlimited (`maxattempts = 0`), only
  completed attempts counting, blocking exactly at the boundary, overrides extending rather than
  replacing the base limit, negative overrides clamped, overrides scoped per user, attempts
  scoped per activity, and cascade deletion of child records.
- `tests/external_test.php` — the attempt lifecycle: start/resume without duplicating an attempt,
  `answersjson` always parseable, grading of correct and incorrect choices, refusal to write into
  another user's attempt, refusal of a question belonging to another activity, refusal of a
  free-text index on a scale question, free text containing `<` accepted and capped at 2000
  characters, no writes to a finished attempt, and return values validated through
  `clean_returnvalue()`.
- `tests/permissions_test.php` — drives **every** service in `db/services.php`, so a new one
  cannot ship without a capability. Asserts each teacher-only service refuses a student with
  `nopermissions`, that the view services accept an enrolled student, and that an unenrolled user
  is refused. The capability check runs before any outbound HTTP call, so this needs no network.
- `tests/privacy_provider_test.php` — metadata coverage, context and user discovery, export, and
  the three deletion paths, each asserting that other users and other activities are untouched.

These tests run in isolated processes. The plugin's external classes extend the legacy global
`external_api`, which `lib/externallib.php` provides via `class_alias()`; Moodle's
`require_phpunit_isolation()` requires isolation for any test that loads that file. The legacy
names are deliberate — the namespaced `core_external` classes only exist from Moodle 4.2, and
`version.php` declares support from 4.0.

### Verified — the tests were checked against deliberate defects
Nine defects were introduced one at a time and the suite re-run each time. All nine were caught:
the attempt-limit comparison off by one; the negative-override clamp removed; the attempt
ownership check removed; the free-text parameter narrowed from raw to `PARAM_TEXT`; a capability
check removed from `save_questions`; `delete_data_for_user` ignoring the user id;
`get_users_in_context` returning nobody; `save_answer` accepting a question from another activity;
and `finish_attempt` not marking the attempt complete.

### Fixed — two regressions from 1.5.158
Both were found by rendering `view.php` under 1.5.157 and 1.5.158 on a real Moodle install and
comparing the parsed DOM — element tree, attributes and text nodes — across seven activity
configurations for both a teacher and a student.

- **The free-text placeholder was broken.** The line-wrapper split the `placeholder` attribute
  across lines, putting a newline and 28 spaces into the middle of text students read. The
  language string for it (`freetext_questions_placeholder`) already existed and the markup had
  been ignoring it, so the fix uses the string and removes the hardcoded English.
- **15 elements gained a leading space.** Wrapping had moved element content onto its own line.
  The `>` now sits against the content instead, which emits no text node.

After both fixes, all 14 render comparisons are DOM-identical to 1.5.157.

### Version
- `version.php` → `2026083016` (release `1.5.159`). No DB schema changes; the 1.5.150 savepoint is
  unchanged. `v1.5.157` remains untouched.

## [1.5.158] - 2026-08-30

Clears every Moodle Plugin CI finding. The public release has been stuck at 1.5.142 because
CI genuinely failed at three steps — phpcs, phpdoc and grunt — in all six matrix jobs.
All three are now green, with no behavioural change to the plugin.

### Fixed — ESLint (599 findings → 0)
- Added JSDoc blocks for 72 functions, and real `@param`/`@return` types for the three that
  had a description but no types.
- Braces added to 17 single-statement `if`/`for` bodies.
- 151 over-length lines wrapped. Every wrap was verified mechanically: the file was minified
  with terser before and after, and the output compared byte for byte. The only intentional
  difference is one hoisted `Math.floor(kcMin / 60)` in the ETA string, which the wrap made
  unavoidable and which is arithmetically identical.
- Restored a `var $sectorSelect` declaration that a previous edit had removed while its three
  uses remained — the industry-change handler had been throwing a ReferenceError.
- Hoisted the text-source question-count `<option>` builder out of its loop (`no-loop-func`),
  converted an inner `animateCount` declaration to a function expression, and dropped the
  last unused variables.

### Removed — orphaned code
- `jobLevels` (9 lines) and `jobTitlesByIndustry` (170 lines): data tables with no remaining
  readers, superseded by the free-text job-role chips.
- `handleKCSingleRegenerate` (124 lines): the per-question regenerate handler added in 1.5.77.
  Its button was lost in a later edit, so the feature was unreachable from the UI. A comment
  marks where it was; git history has the implementation if it is rebuilt properly.

### Fixed — stylelint (226 findings → 0)
Carried forward from the CSS work: 97 `!important` declarations replaced by a `#page` selector
prefix across 34 rule blocks, and the inline `data:image/svg+xml` noise texture moved to
`pix/noise.svg`. Rule-block count is unchanged at 350.

### Fixed — phpcs (13,535 errors and 15,821 warnings → 0)
The raw count is inflated: the line-length and docblock sniffs fire once per token, so the
honest figure was 1,505 unique findings.

- `view.php` accounted for nearly all of it. Two sniffs — `MissingDocblock.File` and
  `FileExpectedTags` — fire once for every re-opened `<?php` tag, and this page interleaves
  markup with 218 short PHP interludes, so they fired ~245 times each against a file whose
  docblock is correct. Both are suppressed at the top of that file with the reason recorded
  inline. The proper fix is Mustache templates rendered from a renderer class; that refactor
  is noted where the suppression sits.
- A `$str()` shorthand for `get_string($key, 'mod_aiknowledgecheck', $a)` replaced 183 call
  sites in `view.php`, which removed most of its over-length lines outright.
- Long lines wrapped across `report.php`, `db/upgrade.php`, `mod_form.php`, `settings.php` and
  `classes/privacy/provider.php`.
- Comment style: box-drawing section banners rewritten as plain sentences, and the 26
  `pipeline-ignore` markers — which must stay verbatim for the release scanner — annotated with
  `// phpcs:ignore moodle.Commenting.InlineComment` so both checkers are satisfied.
- `lang/en/aiknowledgecheck.php` sorted alphabetically as `LangFilesOrdering` requires. The
  resulting array was compared key by key and value by value against the original: 404 strings,
  no additions, no removals, no value changes. Sorting surfaced one genuine bug — a duplicated
  `error:invalidimageurl` key. The later definition was the one taking effect, so it is the one
  kept.

### Fixed — phpdoc
`aiknowledgecheck_add_instance` and `aiknowledgecheck_update_instance` documented `$mform` as
`mod_aiknowledgecheck_mod_form|null` while the signature declares `?object`. local_moodlecheck
read that as an incomplete parameter list. The docblocks now say `object|null`.

### Changed — AMD build
`amd/build/knowledgecheck.min.js` and its source map rebuilt with Moodle's own `grunt amd`
(rollup) rather than a hand-run terser. The stale unminified `amd/build/knowledgecheck.js` was
deleted; Moodle's build directory holds only `.min.js` and `.min.js.map`.

### Version
- `version.php` → `2026083015` (release `1.5.158`). No DB schema changes; the 1.5.150 savepoint
  is unchanged. `v1.5.157` is untouched — this is a new immutable release.

## [1.5.157] - 2026-08-30

### Removed
- `.github/workflows/moodle-ci.yml`, added in 1.5.155.

That file was a mistake, and the reason is worth recording. The release pipeline already mirrors this plugin's source into its own GitHub repository (`moodle-mod_aiknowledgecheck`), pushes `main`, creates the immutable `v<version>` tag, and installs a centrally managed Moodle Plugin CI workflow at `.github/workflows/ci.yml`. That managed workflow is what the release gate looks for: it matches on the `ci.yml` path, the exact tag commit, and a run title of `Moodle Plugin CI [tag:v<version>]`, which the managed workflow produces through its `run-name`.

Because the mirror copies the plugin tree verbatim, a workflow shipped inside the plugin lands in that repo *alongside* the managed one. Both were named "Moodle Plugin CI" and both triggered on every push and tag, so every release ran the full matrix twice and produced two sets of identically named runs. The plugin carries no CI configuration of its own; CI belongs to the release repository, which the pipeline owns.

### Version
- `version.php` → `2026083014` (release `1.5.157`). No DB schema changes; the 1.5.150 savepoint is unchanged. No code, language-string or AMD changes — the only differences from 1.5.156 are the removed workflow file, `version.php` and this file.

## [1.5.156] - 2026-08-30

Version bump only, to exercise the release pipeline against a fresh artifact.

No code, language-string, database or AMD changes from 1.5.155. `version.php` and this file are the only differences between the two builds.

### Version
- `version.php` → `2026083013` (release `1.5.156`). No DB schema changes; the 1.5.150 savepoint is unchanged. The `.github/workflows/moodle-ci.yml` added in 1.5.155 is carried forward unchanged — note that it only produces a Moodle Plugin CI run once it is committed to the GitHub repository the pipeline watches. A workflow file inside a distributed ZIP does not run anywhere.

## [1.5.155] - 2026-08-30

Adds the missing CI workflow and clears the mechanically fixable part of the Moodle code checker.

### Added — Moodle Plugin CI workflow
`.github/workflows/moodle-ci.yml` runs `moodlehq/moodle-plugin-ci` v4 against MOODLE_401, 404, 405 and 500 on the PHP version each branch supports, matching `version.php` (`requires` 2022041900, `supported [400, 500]`). Steps: phplint, phpmd, phpcs, phpdoc, validate, savepoints, mustache, grunt, phpunit, behat. This is what "Moodle Plugin CI workflow run is missing" was asking for; the run itself needs the plugin in a GitHub repository.

### Fixed — Moodle code checker
Ran the real thing locally (`moodlehq/moodle-cs` under PHP_CodeSniffer 3.13.2) rather than guessing. The headline number is misleading: the raw count is 30,264, but the line-length and docblock sniffs fire once per token on a line, so the honest figure is **1,505 unique (file, line, sniff) findings**. That is now **463**.

- `phpcbf` fixed 987 automatically: call-signature formatting, inline comment spacing, superfluous whitespace, `else if` → `elseif`, `list()` → short list syntax.
- Completed three truncated GPL boilerplate headers (`version.php`, `version_repair.php`, `db/hooks.php`, `classes/hook/...`) and added missing end-of-file newlines.
- Removed `defined('MOODLE_INTERNAL') || die();` from the eight files where the checker reports it is not needed.
- Renamed local variables that were camelCase or carried underscores (`$inSql`, `$kcEtaMin`, `$_pluginDir`, `$is_survey_mode`, `$imageurl_gate` and others).
- Added the 16 missing docblocks across `backup/moodle2/`.
- Reworded two comments the checker read as commented-out code, and capitalised/punctuated 41 inline comments.
- Wrapped the long lines in the files this migration authored, and moved this file's release note out of a single 457-character comment.

**One of these undid the previous release.** The multi-line call shape v1.5.154 adopted to satisfy the LMS-Labs checker — first argument on its own line, remaining arguments together — is a `PSR2.Methods.FunctionCallSignature` violation under the Moodle standard, which wants one argument per line and the closing bracket on its own. That was 325 findings. `phpcbf` has now produced the shape that satisfies both.

### Known conflict between the two checkers
The LMS-Labs pipeline requires the literal `// pipeline-ignore: PARAM_RAW — <reason>`; the Moodle standard requires inline comments to start with a capital and end with punctuation. The 26 ignore comments cannot satisfy both, and they are currently in the form the LMS-Labs blocker demands, because a blocker outranks a warning. Resolving it means either that pipeline accepting `// Pipeline-ignore:` or the comments carrying a `phpcs:ignore` annotation.

### Still structural, not addressed
358 of the 463 remaining findings are in `view.php`: 241 `MissingDocblock.File` and 117 over-length lines. The docblock count is exactly the number of `?>` toggles in the file — the sniff treats each re-entry into PHP as a new artifact needing a docblock. Both are symptoms of the same thing: `view.php` is 1,957 lines of markup with PHP interleaved. The Moodle-correct fix is Mustache templates plus moving the inline `<script>` blocks into AMD, which is the same refactor the "hardcoded strings in AMD JS" warning points at. That is a real piece of work and it belongs in its own release with its own live verification.

### Version
- `version.php` → `2026083012` (release `1.5.155`). No DB schema changes; the 1.5.150 savepoint is unchanged. No AMD changes. An intermediate build of this release, `2026083011`, was installed on the staging site part-way through the checker pass and was never distributed; `2026083012` is the released build.

## [1.5.154] - 2026-08-30

Second release-pipeline pass. No functional change of any kind — see the note on how that is guaranteed.

### Fixed — approval blocker: PARAM_RAW usage in `version.php`
The remaining finding was prose. The v1.5.153 release note in `version.php` described the fix and named the constant, and the scanner reads any occurrence of the token as a usage. The note now says "raw-typed parameter" instead. No code was involved.

### Fixed — coding style
- **Multi-line calls now put the first argument on its own line** (25 files). This was applied as a mechanical transform over PHP's own token stream, and every file was accepted only after the token streams before and after compared equal, ignoring whitespace. The change is therefore provably whitespace-only and cannot alter behaviour — which is what made it safe to run across `backup/`, `db/upgrade.php`, `lib.php`, `report.php` and `view.php`, none of which this migration had otherwise touched. A second pass re-indented each call body one level in from its opening line, preserving relative indentation inside, and was run to a fixpoint.
- **One statement per line**: the four `<button>` tags in `view.php` that carried two `<?php echo ?>` blocks now read their attributes from `$gatedclass` / `$gateddisabled`, prepared where `$takegated` is set; the question-count line combines its two echoes into one. In `db/upgrade.php`, eight single-line `if`/`elseif` bodies in the opcache-invalidation blocks are expanded, and two long inline arrays are lifted into named variables.
- **Comment blocks start with a capital**: two trailing comments, in `report.php` and `view.php`.

### Still open, deliberately
The pipeline's remaining warning is hardcoded user-facing strings in `amd/src/knowledgecheck.js`. Unchanged from the v1.5.153 note: it is pre-existing, it is dozens of `alert()` and button-label strings, and most of them sit in the student attempt flow that v1.5.152 verified end to end against a live site. Moving them to `core/str` is worth doing on its own terms, with its own live pass — not as a rider on a lint fix.

If the pipeline also still reports multi-line calls or multi-statement lines in `report.php` and `view.php`, those hits are inside `<style>` and `<script>` blocks. They are CSS and JavaScript, not PHP, and reformatting them to satisfy a PHP style rule would make them worse.

### Version
- `version.php` → `2026083010` (release `1.5.154`). No DB schema changes; the 1.5.150 savepoint is unchanged. No AMD changes, so the src/build/min triple is untouched.

## [1.5.153] - 2026-08-30

Release-pipeline pass. No functional change to any endpoint; the one behavioural change is called out below.

### Fixed — approval blocker: PARAM_RAW usage
Every `PARAM_RAW` is now either a stricter type or carries a `// pipeline-ignore: PARAM_RAW — <reason>` on its own line.

Tightened to a stricter type:
- `get_questions` returns: `question`, option `text`, option `explanation`, `mappingTopic`, `mappingCriteria` → `PARAM_TEXT`; `questionVideoUrl`, `questionAudioUrl` → `PARAM_URL`.
- `save_answer` returns: `explanations` → `PARAM_TEXT`.
- The `error` key on all eleven services → `PARAM_TEXT`.

**These values are now cleaned on the way out, and that matters.** `clean_returnvalue()` validates rather than cleans — it throws "Invalid response value detected" on any value `clean_param()` would alter, and the call then fails with an HTTP 200, which is exactly how v1.5.151 and v1.5.145 broke. Declaring a stricter return type without cleaning at build time would have handed a legacy row holding stray markup the power to take `get_questions` down. So each of these fields is passed through `clean_param()` where the result array is built, which makes the declared type idempotent and the failure impossible. The client escapes every one of these fields before rendering (`.text()` or `escapeHtml()`), so nothing depended on markup surviving. Upstream error text from the generation service is cleaned in `saas_client::failure()` for the same reason.

Left as `PARAM_RAW`, with a reason:
- JSON payloads that are `json_decode()`'d immediately (`questions`, `textSources`, `freetextQuestions`).
- JSON blobs passed through verbatim to the client (`resultjson`, `answersjson`, `payload`) — decoding and re-encoding these is the v1.5.90 bug.
- `data:image` URLs, which `PARAM_URL` rejects (`imageurl`, `imageDataUrl`, per-question `imageUrl`); these are validated by `mod_aiknowledgecheck_sanitize_image_url()`.
- `audioData`, a base64 payload.
- The free-text inputs on `generate` and `save_answer`, which are cleaned with `clean_param(PARAM_TEXT)` inside `execute()` — see the v1.5.152 note on why they cannot be declared `PARAM_TEXT` at the boundary.

### Fixed — coding style error
- No blank line after a class opening brace (15 files).
- Multi-line `new external_value()` calls put the first argument on its own line; short ones collapsed onto a single line inside the 132-character limit.
- Single-line closures in `report.php` split across lines (one statement per line).
- Every comment block now starts with a capital. The release log in `db/upgrade.php` and `view.php` reads "Release v1.5.x" rather than a bare version number (89 blocks).

### Changed
- `index.php` now states its read requirement explicitly with `require_capability('mod/aiknowledgecheck:view', ...)` at the course context, rather than leaving it implied by `require_login()`. The listing already filtered by each activity's own visibility.
- `$string['scale_nps5']` reads "Net Promoter 5-point" rather than "NPS 5-point". Label only — the scale is referenced everywhere by its `nps5` key.

### Not changed, and why
The pipeline warns that `amd/src/knowledgecheck.js` carries user-facing strings without `core/str`. That is true and pre-existing: the module holds dozens of `alert()` and button-label strings, most of them in the student attempt flow that v1.5.152 verified end to end on a live site. Moving them to `core/str` is a real improvement and a real regression risk, so it belongs in its own release with its own live pass, not bolted onto a lint fix.

The pipeline also reports multi-statement lines in `report.php` and `view.php` and multi-line-call formatting in files such as `backup/moodle2/backup_aiknowledgecheck_stepslib.php`. The remaining hits in those two files are inline CSS and inline JavaScript inside `<style>` and `<script>` blocks, not PHP statements; the remaining call-formatting hits are the idiomatic Moodle `foo('name', [` array-opener form used throughout core.

### Version
- `version.php` → `2026083009` (release `1.5.153`). No DB schema changes; the 1.5.150 savepoint is unchanged. No AMD changes, so the src/build/min triple is untouched and still in sync.

## [1.5.152] - 2026-08-30

### Changed (External Services migration — complete)

The remaining ten `ajax.php` actions are now declared External Services, and `ajax.php` has been deleted. The plugin no longer ships an action-dispatcher endpoint.

| Action | Service |
| --- | --- |
| `startattempt` | `mod_aiknowledgecheck_start_attempt` |
| `saveanswer` | `mod_aiknowledgecheck_save_answer` |
| `finishattempt` | `mod_aiknowledgecheck_finish_attempt` |
| `generate` | `mod_aiknowledgecheck_generate` |
| `savequestions` | `mod_aiknowledgecheck_save_questions` |
| `regenerateaudio` | `mod_aiknowledgecheck_regenerate_audio` |
| `regeneratewithsettings` | `mod_aiknowledgecheck_regenerate_with_settings` |
| `regenerateinstructions` | `mod_aiknowledgecheck_regenerate_instructions` |
| `generateimage` | `mod_aiknowledgecheck_generate_image` |
| `saveimageurl` | `mod_aiknowledgecheck_save_image_url` |

Callers moved with them: `amd/src/knowledgecheck.js` has no `$.ajax` calls left, and the two raw `XMLHttpRequest` calls inlined in `view.php` for the image gate now go through `core/ajax`.

### Payloads that no `external_*_structure` can describe

Three payloads are passed across as JSON strings and parsed at the other end, for reasons that are structural rather than convenience:

- **The attempt answers map** (`start_attempt`) is keyed by question ID, so its keys vary per activity. `external_single_structure` describes a fixed set of named keys; `external_multiple_structure` would discard the keys, which are exactly the question IDs the resume path needs.
- **The generation service's own responses** (`generate`, all three regenerate actions) are documents whose shape that service owns and is free to extend. Declaring a structure here would pin a shape the plugin does not control. Passing the body through verbatim also preserves the FIX-KC-REGEN-STREAM (v1.5.90) property that a large base64 `audioData` array is never decoded and re-encoded in transit.
- **The question documents** (`save_questions`, all three regenerate actions) are deeply nested and vary with question type, generation source and which optional media fields are present. They cross as the same JSON string `ajax.php` received and are validated field by field exactly as before.

### Fixed
- **Survey questions kept the model's per-option explanations** (`classes/external/save_questions.php`): in `ajax.php`, the survey-mode block that blanks `feedback1-4` and `correctanswer` ran *before* the generic assignments that set those same fields from the payload, so its work was immediately overwritten. Survey rows therefore stored explanations describing answer options that FIX-KC-SURVEY-SCALE-OPTIONS (v1.5.141) had already replaced with the teacher's chosen scale. The block now runs after those assignments. Found while porting the code, not by a failing test.

### Also fixed while porting
- **`PARAM_TEXT` at a service boundary rejects instead of cleaning** (`save_answer.php`, `generate.php`): `external_api::validate_parameters()` throws `invalid_parameter_exception` when a value differs from its `clean_param()` result — the opposite of the `optional_param()` behaviour these fields had in `ajax.php`, which cleaned silently. Free-text fields are therefore declared `PARAM_RAW` at the boundary and cleaned inside `execute()`. Without this a `<` in a student's survey answer ("a < b") or in a teacher's instructions ("keep answers < 20 words") would have aborted the call — the answer lost silently, the generation failing with a raw "Invalid parameter value detected". Fields whose values the plugin itself enumerates keep their strict types.
- **Rollback on a disposed transaction** (`start_attempt.php`): `ajax.php` built its response inside the try block after `allow_commit()`, so a throw from a post-commit DB read reached a `catch` that called `rollback()` on a disposed transaction. Moodle answers that with "Transactions already disposed", replacing the real error with a misleading one. The transaction now covers only the database work.
- **Double slash in the API path** (`get_credits.php`, `get_generation_status.php`): `ajax.php` `rtrim`'d the configured API base for every action; these two classes, migrated in v1.5.144 and v1.5.148, did not. An `apiurl` saved with a trailing slash built `https://host//api/...`.
- **Generation errors were never shown** (`amd/src/knowledgecheck.js`): `handleGenerateError` still took jQuery's `(xhr, status, error)` triple. A `core/ajax` rejection passes one Moodle exception object, so every branch that read `status` or `xhr.responseText` was unreachable and the user always saw the generic fallback with the server's own message discarded.

### Added
- `classes/saas_client.php` — the credential lookup (Central Config first, plugin settings as fallback) and the POST/response handling were repeated in every action that called the generation service. They live here once, including the two fixes each found the hard way: BUG-CURL-RESETOPT (v1.5.85), curl options must be passed as the third argument to `post()` or Moodle's internal `resetopt()` discards them; and BUG-REGEN-TIMEOUT (v1.5.84), a PHP time limit above the curl timeout so the web server does not kill PHP mid-request and return a blank body.
- Six new language strings for errors that were hard-coded English inside `ajax.php`, so they now translate.

### Note on the image actions
Neither image action falls under the reviewer's exclusion for file upload endpoints. Nothing is uploaded: `generate_image` sends a text prompt and receives a data URL, `save_image_url` sends a URL string. There is no multipart request and no draft file area, so both migrate like any other action. `generate_image` additionally sanitises the returned URL through `mod_aiknowledgecheck_sanitize_image_url()` before handing it back, so a response carrying `data:image/svg+xml` is rejected rather than rendered in the editor preview.

### Version
- `version.php` → `2026083008` (release `1.5.152`). New service functions are registered on the version bump. No DB schema changes; the 1.5.150 savepoint is unchanged.

## [1.5.151] - 2026-08-30

### Fixed
- **Activities with saved questions showed the generator instead** (`classes/external/get_questions.php`): the service returned `null` for `audioData` on any question with no generated voiceover. `external_multiple_structure` rejects `null` outright with "Only arrays accepted" — unlike `external_value`, it does not honour a null-allowed default — so Moodle discarded the entire response with `invalidresponse: Invalid response value detected`. The request still returned HTTP 200, the client saw a failed call, fell back to "no existing questions", and rendered the question generator on activities that already had questions. No data was lost; the questions were simply undeliverable.

  `audioData` is now always an array, empty when the question has no audio. The client treats `[]` and `null` identically — it only ever tests truthiness, `.length` and positional indexes.

### How this was missed
The local suites mock the transport layer, so they never exercise Moodle's `clean_returnvalue()`, which is what enforces a declared return structure. A wrong declaration therefore passes every local test and fails only on a real Moodle site — and fails with a 200, so it looks healthy from the outside. This is the second defect of exactly this shape, after the Central Config credential lookup in 1.5.145. Both were found only by exercising the endpoint against a live site.

### Version
- `version.php` → `2026083007` (release `1.5.151`). No DB schema changes; the 1.5.150 savepoint is unchanged.

## [1.5.150] - 2026-08-30

### Fixed
- **XMLDB warning: `surveyscale` had an invalid column default** (`db/install.xml`, `db/upgrade.php`): the column was declared `CHAR(50) NOT NULL DEFAULT ''`. Moodle does not accept an empty-string default on a NOT NULL character column — it rewrites it to NULL at install time and emits a debugging warning on every page that loads the plugin's schema, which is why it appeared repeatedly in the Adminer output.

  An empty string was never a valid scale key. `mod_form.php` defaults the field to `likert5agree`, and both `lib.php` and `ajax.php` fall back to `likert5agree` whenever the stored value is empty, so the schema simply did not declare the default the rest of the plugin already assumed. It is now declared explicitly. A new upgrade step changes the default on existing installs and backfills any row still holding an empty value, so fresh and upgraded schemas agree.

### Version
- `version.php` → `2026083006` (release `1.5.150`), with a matching upgrade savepoint. This is the first schema change in several releases, so the upgrade must be run for the fix to apply.

## [1.5.149] - 2026-08-30

### Changed (External Services migration — step 4)
- **`status` generation polling completed.** Both JS callers (`checkStatus` and the regeneration poller) now go through `mod_aiknowledgecheck_get_generation_status` via a single `pollGenerationStatus()` wrapper, and the legacy `status` action has been removed from `ajax.php`, which is down to 10 actions from the original 16.
- **The upstream document is passed through as an opaque JSON string** rather than being declared field by field. The generation service owns that payload's shape and can extend it; declaring a fixed structure here would reject any field the plugin did not anticipate, and a rejected return surfaces as a broken generation run rather than a clear error. The client parses it, exactly as it did when reading the legacy endpoint's response.
- The `mod/aiknowledgecheck:create` gate is preserved: a completed job payload contains the answer key, so this must not be reachable with only `:view`.
- `status` also removed from `$secured_actions`, which now lists only actions that still exist.

### Note on the previous release
1.5.148 shipped with the status service declared and called from the JS while the legacy `ajax.php` action was still present — a half-migrated state that was flagged as untested rather than presented as complete. This release finishes it. An audit confirmed no JS caller was still posting `action: 'status'`, so the legacy case was dead code by the time it was removed.

### Remaining on ajax.php
`generate`, `savequestions`, `startattempt`, `saveanswer`, `finishattempt`, `regenerateaudio`, `regeneratewithsettings`, `regenerateinstructions`, `generateimage`, `saveimageurl`.

### Version
- `version.php` → `2026083005` (release `1.5.149`). No DB schema changes, no new upgrade savepoints.

## [1.5.148] - 2026-08-30

### Changed (External Services migration — step 3)
- **`getquestions` migrated** to `mod_aiknowledgecheck_get_questions` (`classes/external/get_questions.php`), declared as a `read` service gated on `mod/aiknowledgecheck:view`. Both AMD call sites (`checkExistingQuestions` and `loadQuestionsFromDatabase`) now go through `core/ajax`, and the legacy action has been removed. `ajax.php` is down to 11 actions from the original 16.
- **The C2 answer-key protection is preserved.** `correctIndex` is an integer for users who can author or report on the activity, and `null` for students, so the answer key still is not sent to the browser before a question is answered. `external_value` permits null by default, so the distinction between "withheld" and "option 0" survives the declaration — collapsing it would have marked the wrong option correct.
- **`audioData` shape confirmed from the code rather than guessed**: a positionally-indexed list of base64 clips, one per answer option, permuted in lockstep with the options when they are shuffled. Declared as a nullable list of `PARAM_RAW`. A legacy row stored as a JSON object rather than a list is coerced with `array_values()` so it cannot break the declared structure.

### Fixed (during this work)
- A first attempt at removing the legacy `getquestions` case from `ajax.php` used a regular expression that matched past the end of the block and silently deleted the adjacent `status` action as well. Caught by diffing the action list against the previous release before packaging; `ajax.php` was restored and the block removed by explicit line range instead. Verified: exactly one action removed, 82 lines, nothing else changed.

### Remaining on ajax.php
`generate`, `status`, `savequestions`, `startattempt`, `saveanswer`, `finishattempt`, `regenerateaudio`, `regeneratewithsettings`, `regenerateinstructions`, `generateimage`, `saveimageurl`.

### Version
- `version.php` → `2026083004` (release `1.5.148`). No DB schema changes, no new upgrade savepoints.

## [1.5.148] - 2026-08-30

### Changed (External Services migration — step 3)
- **`status` migrated** to `mod_aiknowledgecheck_get_generation_status` (`classes/external/get_generation_status.php`). Both JavaScript pollers — initial generation and regeneration — now share a single `pollGenerationStatus()` helper instead of duplicating the request. Legacy action removed; `ajax.php` is down to 11 actions.

### Design note: why this service returns an opaque JSON string
Unlike the first two endpoints, `status` returns a variable-shaped document produced by the external generation service, including base64 audio arrays that can run to megabytes. A typed `external_single_structure` was rejected for two reasons:

1. The legacy endpoint deliberately streamed the upstream body through without a `json_decode`/`json_encode` round trip (`FIX-KC-STATUS-STREAM`). Re-encoding a large completed payload could make `json_encode()` fail silently, which reached students as "0 questions generated". A typed structure would force that round trip back into the request path.
2. The upstream shape is not owned by this plugin. A typed structure silently drops unknown keys, so an upstream field addition would break generation with no error.

The payload is therefore declared `PARAM_RAW` and parsed by the caller. The security benefits the migration exists for are unaffected: arguments are validated against the declared signature before any plugin code runs, Moodle handles the session, and the `mod/aiknowledgecheck:create` check is enforced inside `execute()` — which matters here because a completed payload contains the answer key.

This trade-off is expected to recur for the remaining generation endpoints; DB-backed endpoints will get properly typed structures.

### Remaining on ajax.php
`generate`, `getquestions`, `savequestions`, `startattempt`, `saveanswer`, `finishattempt`, `regenerateaudio`, `regeneratewithsettings`, `regenerateinstructions`, `generateimage`, `saveimageurl`.

### Version
- `version.php` → `2026083004` (release `1.5.148`). No DB schema changes, no new upgrade savepoints.

## [1.5.147] - 2026-08-30

### Changed (External Services migration — step 2)
- **`savevoicesettings` migrated** to `mod_aiknowledgecheck_save_voice_settings` (`classes/external/save_voice_settings.php`), declared as a `write` service. The AMD module now calls it through `core/ajax`, and the legacy action has been removed from `ajax.php`, which is down to 12 actions.
- The four settings fields are now written in a single `update_record()` rather than five separate `set_field()` calls.
- When voiceover is switched off, stored audio is cleared with one `set_field_select()` instead of loading every question row and updating them individually. On an activity with many questions that replaces N+1 queries with two. The service also returns how many questions were affected, which the legacy endpoint did not report.

### Remaining on ajax.php
`generate`, `status`, `getquestions`, `savequestions`, `startattempt`, `saveanswer`, `finishattempt`, `regenerateaudio`, `regeneratewithsettings`, `regenerateinstructions`, `generateimage`, `saveimageurl`.

### Version
- `version.php` → `2026083003` (release `1.5.147`). No DB schema changes, no new upgrade savepoints.

## [1.5.146] - 2026-08-30

### Changed (External Services migration — step 1 complete)
- **Legacy `getcredits` action removed from `ajax.php`.** The `mod_aiknowledgecheck_get_credits` External Service was verified working against a live Moodle site — the request routes through `lib/ajax/service.php` and returns `{ok: true, credits: 47136}` — so the legacy fallback is no longer needed.
- **Dead endpoints removed rather than migrated.** An audit of every `ajax.php` action against its callers found `getindustries` and `getattemptinfo` have no caller anywhere in the plugin — not in the AMD module, not in `view.php`. Migrating them would have meant writing and maintaining service declarations for code nothing calls, so they were deleted. `getindustries` was also gated on `mod/aiknowledgecheck:create`, so removing it reduces the authenticated attack surface at no cost.
- `ajax.php` is down to 13 actions.

### Remaining on ajax.php
`generate`, `status`, `getquestions`, `savequestions`, `startattempt`, `saveanswer`, `finishattempt`, `regenerateaudio`, `regeneratewithsettings`, `regenerateinstructions`, `savevoicesettings`, `generateimage`, `saveimageurl`. These will be migrated in order of increasing risk. `generateimage` and `saveimageurl` involve image upload handling and may fall under the review's multipart exclusion; that will be confirmed before they are touched.

### Version
- `version.php` → `2026083002` (release `1.5.146`). No DB schema changes, no new upgrade savepoints.

## [1.5.145] - 2026-08-30

### Fixed
- **`get_credits` service reported "not configured" on sites using Central Config**: the new External Service introduced in 1.5.144 read the Site ID and API Key only from this plugin's own settings. `ajax.php` first checks the optional `local_aiconfig` "Central Config" plugin, which is how a site running several AI plugins holds one set of credentials centrally. The service now resolves credentials by the same two-step priority — Central Config first, plugin settings as fallback.

  Caught by testing on a live Moodle site: the service returned HTTP 200 and a well-formed response, so it looked healthy, but the payload was `ok: false` and the balance rendered as `--` while the site dashboard showed 47,136 credits. No local test could have found this — the mocked network layer never exercises credential resolution, and the failure only appears on a site that uses Central Config.

### Version
- `version.php` → `2026083001` (release `1.5.145`). No DB schema changes, no new upgrade savepoints.

## [1.5.144] - 2026-08-30

### Changed (Moodle review: External Services migration — step 1 of several)
- **`getcredits` migrated to a declared External Service.** Addresses the review finding that the plugin uses legacy AJAX action endpoints instead of External Services. New files: `classes/external/get_credits.php` and `db/services.php`. The AMD module now depends on `core/ajax` and calls `mod_aiknowledgecheck_get_credits` by name instead of posting an `action` to `ajax.php`. Moodle validates the arguments against the declared signature before any plugin code runs, and handles the sesskey itself, so neither is passed by the caller.
- **Deliberately incremental.** `ajax.php` still serves every other action, and its `getcredits` case is intentionally left in place for now. The legacy action is only removed once every caller has been moved across. Migrating ~15 actions in one release would put every feature stabilised in 1.5.139–1.5.143 at risk simultaneously, with no way to tell which change caused a regression.
- **Error strings**: `error:notconfigured`, `error:connectionfailed`, `error:invalidresponse` and `error:apihttp` added to the language pack. The legacy action hard-coded these, which the review also flagged.

### Notes
- `db/services.php` functions are registered when the plugin version increases, so this release must be installed via the normal upgrade for the service to appear.
- The external class uses the legacy `external_api` / `external_value` class names rather than the `core_external\*` namespace introduced in Moodle 4.2, because `version.php` declares support for Moodle 4.0 through 5.x and the namespaced classes do not exist in 4.0.

### Remaining
Still on `ajax.php`: `generate`, `status`, `getquestions`, `savequestions`, `startattempt`, `saveanswer`, `finishattempt`, `getindustries`, the regenerate actions, voice settings, and the image actions. These will be migrated in order of increasing risk, read-only endpoints first. Multipart upload handlers are excluded per the review, as `core/ajax` cannot replace them.

### Version
- `version.php` → `2026083000` (release `1.5.144`). No DB schema changes, no new upgrade savepoints.

## [1.5.143] - 2026-08-29

### Fixed (Moodle plugins-directory review)
- **Privacy API compliance (approval blocker)**: `aiknowledgecheck_quizzes` carries a `userid` but was not declared in the Privacy Provider metadata. It is now declared, and — importantly — also purged in all three deletion paths (`delete_data_for_all_users_in_context`, `delete_data_for_user`, `delete_data_for_users`). Declaring metadata alone would have left a GDPR erasure request silently incomplete. Each purge is guarded with `table_exists()` because the table is vestigial and may be absent. The language strings already existed.
- **Global CSS selectors (approval blocker)** and **non-frankenstyle functions (approval blocker)** and **deprecated `print_error()`**: all three traced solely to `diag.php` and `timestamp_diag.php`, which are unlinked developer diagnostics not reachable from the plugin UI. These are now excluded from the marketplace package, in line with the existing practice of stripping `BUILD_INFO.json` and `setup.php`. Shipping developer tooling that injects `body {` and `table.diag {` into Moodle's global stylesheet was the actual defect; removing it is the correct fix rather than a workaround. Both files remain in the source repository.
- **Marketplace metadata (approval blocker)**: `README.md` replaced. It previously said only "Moodle plugin" plus a licence line. It now documents features, requirements, installation, usage, privacy and capabilities. Capability names and version support were read from `db/access.php` and `version.php` rather than assumed.
- **Redundant stylesheet loading**: removed `$PAGE->requires->css()` for the plugin's own `styles.css`, which Moodle aggregates automatically. The Google Fonts require is retained deliberately — an `@import` inside `styles.css` breaks Moodle's CSS minifier.
- **File boilerplate**: added the "This file is part of Moodle" GPL banner to `amd/src/knowledgecheck.js`. All PHP files already carried it.

### Not addressed in this release
- **Legacy AJAX → External Services (HIGH)**: a substantial refactor of `ajax.php` (~1,450 lines, many actions) into `classes/external/*` plus `db/services.php`, with matching `core/ajax` call sites in the AMD module. Deferred as a separate piece of work.
- **Templates / Output API (MEDIUM)** and **hard-coded strings in `report.php` (MEDIUM)**: also deferred.
- **GitHub Actions CI (LOW)**: repository configuration rather than a plugin change.

### Changed
- **VERSION BUMP**: `version.php` → `2026082900` (release `1.5.143`). No DB schema changes, no new upgrade savepoints. AMD artifacts rebuilt.

## [1.5.142] - 2026-08-28

### Fixed
- **FIX-KC-SURVEY-BLANK — blank screen after the last survey question** (`amd/src/knowledgecheck.js`): `showResults()` rendered the survey "Survey Complete" panel into `#kc-results-container`. No element with that ID exists anywhere in the plugin — `view.php` defines `#kc-results`, which is what the quiz path writes to and reveals. jQuery matched nothing, `.show()` did nothing, and the hidden `#kc-results` card stayed at `display: none`, leaving the student on a blank screen after answering the final question. Responses were saved correctly throughout; only the confirmation screen was invisible. The survey path now renders into `#kc-results`, exactly as the quiz path does.

  This is the same defect class as the phantom `#survey-scale` element fixed in v1.5.140: JavaScript addressing markup that was never added. A sweep of all 39 element IDs the JS writes to, shows, or appends confirms no others are missing from `view.php`.

### Changed
- **Test harness corrected**: the survey test previously defined `#kc-results-container` in its own fixture, so it asserted the panel's HTML was written without ever asserting it was visible — which is why it passed against broken code. The fixture now mirrors `view.php`, and the suite asserts the completion screen is actually visible, the player is hidden, and the Retake button is present. Verified to fail against 1.5.141 and pass against 1.5.142.
- **VERSION BUMP**: `version.php` → `2026082802` (release `1.5.142`). No DB schema changes, no new upgrade savepoints. AMD artifacts rebuilt.

## [1.5.141] - 2026-08-28

### Fixed
- **FIX-KC-SURVEY-SCALE-OPTIONS — the chosen Response Scale was not enforced** (`lib.php`, `ajax.php`): the plugin defined the survey scales nowhere. The strings in `view.php` are display-only labels for the activity header and were never used to build answer options. On save, the plugin stored whatever options the generation API returned, so the teacher's chosen scale was honoured only if the language model happened to comply — in practice it frequently returned 5-point Agreement regardless, and a teacher who picked "Yes / No" got Agreement options with nothing to indicate their choice had been ignored. `lib.php` now defines the authoritative ordered option set for all nine scales, and `ajax.php` overwrites the stored options with the correct set for the activity's scale on every save. The AI still writes the question text; it no longer decides the answer options. Per-option AI feedback is discarded for survey questions (it described options that no longer exist) and `correctanswer` is pinned to 0, since surveys are ungraded. Free-text questions and quiz mode are untouched.

### Changed
- **VERSION BUMP**: `version.php` → `2026082801` (release `1.5.141`). No DB schema changes, no new upgrade savepoints. AMD artifacts rebuilt. Includes v1.5.140's `FIX-KC-SURVEY-SCALE`.

### Note for existing surveys
Questions generated before this release keep whatever options were stored at the time — changing the Response Scale has never rewritten existing questions, and still does not. Any survey created on 1.5.126–1.5.140 with a scale other than 5-point Agreement should be **regenerated** to pick up the correct options. Existing responses are recorded as positional indexes, so regenerating a survey that already has responses will change what those indexes mean; export the attempts report first if the data matters.

## [1.5.140] - 2026-08-28

### Fixed
- **FIX-KC-SURVEY-SCALE — only the first Response Scale worked** (`amd/src/knowledgecheck.js`): the generate request built its `surveyScale` value from `$('#survey-scale').val()`, but no element with that ID has ever existed in the plugin — the Response Scale is chosen in the activity settings form (`mod_form.php`), not on the view page. jQuery returned `undefined`, the `|| 'likert5agree'` fallback fired on every generation, and eight of the nine scales silently generated Agreement questions instead: Satisfaction, Frequency, Quality, Importance, Likert 4-point, Yes/No, Yes/No/Unsure and NPS 5-point. `view.php` already supplied the teacher's real choice to the JS as `config.surveyScale` (both in the student and teacher-preview configs); it was simply never read. The JS now uses it, with the same safe fallback when the value is absent.

### Changed
- **VERSION BUMP**: `version.php` → `2026082800` (release `1.5.140`). No DB schema changes, no new upgrade savepoints. AMD artifacts rebuilt.

### Note for existing surveys
Survey activities generated on 1.5.126–1.5.139 with any scale other than Agreement will have Agreement-style questions and answer options stored against them. Changing the Response Scale alone will not rewrite existing questions — those activities need regenerating to pick up the intended scale.

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
