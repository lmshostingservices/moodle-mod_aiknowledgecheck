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
 * AI Knowledge Check view page.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

// This page is a template: the markup below is written as HTML with short PHP
// interludes rather than as PHP string building. moodle-cs treats every re-opened
// <?php tag as the start of a new file, so it asks each one for its own file
// docblock and for the @copyright and @license tags that the real file docblock
// above already carries. Both sniffs therefore fire ~245 times on markup that is
// correctly documented. The long-term fix is to move this markup into Mustache
// templates rendered from a renderer class; until that refactor lands, the two
// sniffs are disabled here only.
// phpcs:disable moodle.Commenting.MissingDocblock.File
// phpcs:disable moodle.Commenting.FileExpectedTags

// Shorthand for this plugin's language strings, used throughout the markup below.
// Takes the string identifier and an optional placeholder value, and returns the
// localised string.
$str = function (string $key, $a = null): string {
    return get_string($key, 'mod_aiknowledgecheck', $a);
};

// FIX-KC-JSESCAPE (v1.5.161): emit a PHP value as a JavaScript literal, quotes included.
// This replaces addslashes(), which escapes quotes and backslashes but leaves newlines and a
// literal </script> intact -- either of which ends the inline script early and breaks the page.
// The HEX flags also stop < > & ' " reaching the parser raw, so a translated string can never
// close the script element.
$js = function ($value): string {
    return json_encode(
        (string)$value,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
    );
};

$id = required_param('id', PARAM_INT); // Course module ID.

$cm = get_coursemodule_from_id('aiknowledgecheck', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$knowledgecheck = $DB->get_record('aiknowledgecheck', ['id' => $cm->instance], '*', MUST_EXIST);

// Survey mode helpers — used throughout view.php for conditional copy.
$issurveymode = !empty($knowledgecheck->surveymode);
$surveyscalekey = isset($knowledgecheck->surveyscale) ? $knowledgecheck->surveyscale : 'likert5agree';
$surveyscalelabels = [
    'likert5agree' => 'Strongly Agree → Strongly Disagree',
    'likert5sat'   => 'Very Satisfied → Very Dissatisfied',
    'likert5freq'  => 'Always → Never',
    'likert5qual'  => 'Excellent → Very Poor',
    'likert5imp'   => 'Very Important → Not Important at All',
    'likert4agree' => 'Strongly Agree → Strongly Disagree (4-point)',
    'yesno'        => 'Yes / No',
    'yesnounsure'  => 'Yes / No / Unsure',
    'nps5'         => '1 (Very Poor) → 5 (Excellent)',
];
$surveyscaledisplay = $surveyscalelabels[$surveyscalekey] ?? 'Strongly Agree → Strongly Disagree';

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/aiknowledgecheck:view', $context);

// Check if user can create knowledge checks.
$cancreate = has_capability('mod/aiknowledgecheck:create', $context);
$canviewreports = has_capability('mod/aiknowledgecheck:viewreports', $context);

// FIX-KC-NONEDITING-TEACHER (v1.5.137): "can this person author" and "is this person course
// staff" are different questions and only the first is mod/aiknowledgecheck:create, whose
// archetypes are editingteacher and manager.
//
// :viewreports already lists 'teacher' -- the capability was defined correctly. The page then
// asked :create for everything, so a non-editing teacher fell into the student branch: the
// Attempts report link was nested INSIDE the authoring branch and never rendered for them,
// and every media gate below tested !$cancreate, locking a marker behind the learner's
// acknowledge-the-video gate on an activity they are there to mark.
//
// report.php itself requires only :viewreports, so the page always would have served them --
// there was simply no link to it.
$canoverride = has_capability('mod/aiknowledgecheck:manageoverrides', $context);
$isstaff = $cancreate || $canviewreports;

// Explicitly include aiconfig lib.php if available.
$aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
if (file_exists($aiconfiglib)) {
    require_once($aiconfiglib);
}

// Priority 1: Central Config (recommended for multi-plugin setups).
$siteid = '';
$apikey = '';
if (function_exists('local_aiconfig_get_siteid')) {
    $siteid = local_aiconfig_get_siteid();
}
if (function_exists('local_aiconfig_get_apikey')) {
    $apikey = local_aiconfig_get_apikey();
}

// Priority 2: Plugin settings as fallback.
if (empty($siteid)) {
    $siteid = get_config('mod_aiknowledgecheck', 'siteid');
}
if (empty($apikey)) {
    $apikey = get_config('mod_aiknowledgecheck', 'apikey');
}

// Page setup.
$PAGE->set_url('/mod/aiknowledgecheck/view.php', ['id' => $id]);
$PAGE->set_title(format_string($knowledgecheck->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');

// FIX-KC-REVIEW-CSS (v1.5.143): Moodle aggregates and caches each plugin's styles.css
// automatically, so requiring it here was redundant and could load the stylesheet twice.
// The Google Fonts stylesheet below is a genuine external resource and is still required
// explicitly, because an @import inside styles.css breaks Moodle's CSS minifier.

// Load Google Fonts via PHP (NOT via @import in styles.css which breaks Moodle CSS minifier).
$PAGE->requires->css(
    new moodle_url(
        'https://fonts.googleapis.com/css2',
        [
            'family' => 'Inter:wght@400;500;600;700',
            'display' => 'swap',
        ]
    )
);

// Trigger module viewed event.
aiknowledgecheck_view($knowledgecheck, $course, $cm, $context);

echo $OUTPUT->header();

// Check configuration.
if (empty($siteid) || empty($apikey)) {
    echo $OUTPUT->notification($str('not_configured'), 'warning');
    echo $OUTPUT->footer();
    exit;
}

// Check if there are questions saved.
$questioncount = $DB->count_records('aiknowledgecheck_questions', ['aiknowledgecheckid' => $knowledgecheck->id]);

// Load passing grade from gradebook (authoritative source).
$gradepass = 0;
$maxgrade = isset($knowledgecheck->grade) ? (int)$knowledgecheck->grade : 100;
if ($maxgrade <= 0) {
    $maxgrade = 100;
}
require_once($CFG->libdir . '/gradelib.php');
$gradeitem = grade_item::fetch(
    [
        'itemtype' => 'mod',
        'itemmodule' => 'aiknowledgecheck',
        'iteminstance' => $knowledgecheck->id,
        'courseid' => $course->id,
    ]
);
if ($gradeitem && $gradeitem->gradepass > 0) {
    $gradepass = (float)$gradeitem->gradepass;
}

// Gate setup (needed by both teacher preview and student view).
$videoid = '';
$hasvideo = false;
if (!empty($knowledgecheck->videourl)) {
    $vurl = $knowledgecheck->videourl;
    if (preg_match('/[?&]v=([a-zA-Z0-9_-]{11})/', $vurl, $m)) {
        $videoid = $m[1];
    } else if (preg_match('/youtu\.be\/([a-zA-Z0-9_-]{11})/', $vurl, $m)) {
        $videoid = $m[1];
    } else if (preg_match('/embed\/([a-zA-Z0-9_-]{11})/', $vurl, $m)) {
        $videoid = $m[1];
    } else if (preg_match('/shorts\/([a-zA-Z0-9_-]{11})/', $vurl, $m)) {
        $videoid = $m[1];
    }
    $hasvideo = !empty($videoid);
}
// FIX-KC-VIDEO-SIMULTANEOUS (v1.5.62): Always require full watch when a video is
// present. Previously, teachers who left videorequirement='none' (the default) saw
// the video and the quiz start section rendered simultaneously on the student page.
// Forcing 'full' + videogated=$hasvideo ensures the gate coordinator always hides
// the start section until the video ends, regardless of the teacher's setting.
$videoreq    = $hasvideo ? 'full' : ($knowledgecheck->videorequirement ?? 'none');
$videominsec = (int)($knowledgecheck->videominseconds ?? 0);
$videogated  = $hasvideo;
$audiourl = '';
$audioreq = $knowledgecheck->audiorequirement ?? 'none';
$audiominsec = (int)($knowledgecheck->audiominseconds ?? 0);
if (!empty($knowledgecheck->audiourl)) {
    $audiourl = $knowledgecheck->audiourl;
}
$hasaudio = !empty($audiourl);
$audiogated = $hasaudio && $audioreq !== 'none';
// Image gate setup (ADD-KC-IMAGEGATE v1.5.115).
$imageurlgate = '';
if (!empty($knowledgecheck->imageurl)) {
    $imageurlgate = $knowledgecheck->imageurl;
}
$hasimage   = !empty($imageurlgate);
$imagegated = $hasimage; // Image gate is always active when a URL is set.
$anygated  = $videogated || $audiogated || $imagegated;
// FIX-KC-TAKEGATED-UNDEFINED (v1.5.65): $takegated was only assigned inside the
// teacher/creator if ($cancreate) branch (line ~670). When a student lands on the
// page the variable was never set, causing "Undefined variable $takegated" PHP
// warnings on the Start Quiz / Continue Attempt buttons at the bottom of the
// student view. Initialise here so it is always defined before any HTML output.
$takegated = $anygated && !$isstaff;

// EXTRACT-INLINE-JS (v1.5.161): everything the media gates need, handed to the AMD module.
// This used to be PHP interpolated directly into inline <script> blocks.
$gatesconfig = [
    'videoid' => $videoid,
    'videorequirement' => $videoreq,
    'videominseconds' => $videominsec,
    'hasvideo' => $hasvideo,
    'videogated' => $videogated,
    'showvideoduringquiz' => !empty($knowledgecheck->showvideoduringquiz),
    'audiorequirement' => $audioreq,
    'audiominseconds' => $audiominsec,
    'hasaudio' => $hasaudio,
    'audiogated' => $audiogated,
    'hasimage' => $hasimage,
    // The acknowledge button is only rendered for non-staff, so the handler is only wanted there.
    'imagegated' => $imagegated && !$isstaff,
    'isstaff' => $isstaff,
];
// Pre-rendered button attributes, so the markup below carries one statement per line.
$gatedclass = $takegated ? ' kc-gated-btn' : '';
$gateddisabled = $takegated ? ' disabled' : '';
// One attribute fragment, so the markup below never carries two PHP statements on
// a single physical line.
$gatedattrs = $gatedclass . '"' . $gateddisabled;

// FIX-KC-NONEDITING-TEACHER (v1.5.137): the staff navigation is rendered for anyone holding
// :viewreports, whether or not they can author. It used to sit inside the if ($cancreate)
// branch below, so the one capability a non-editing teacher does hold led to nothing.
//
// The "More attempts" link is gated separately on :manageoverrides, because moreattempts.php
// requires it -- offering that link to someone the page will reject is worse than not
// offering it.
if ($canviewreports) {
    echo html_writer::start_div('kc-teacher-nav mb-3');
    $reporturl = new moodle_url('/mod/aiknowledgecheck/report.php', ['id' => $cm->id]);
    echo html_writer::link(
        $reporturl,
        $str('attemptsreport'),
        ['class' => 'btn btn-secondary mr-2']
    );
    if ($canoverride) {
        $moreattemptsurl = new moodle_url('/mod/aiknowledgecheck/moreattempts.php', ['id' => $cm->id]);
        echo html_writer::link(
            $moreattemptsurl,
            $str('moreattempts'),
            ['class' => 'btn btn-secondary']
        );
    }
    echo html_writer::end_div();
}

// Show different views based on capability.
if ($cancreate) {
    ?>
    <div id="kc-app" class="kc-container">
        <!-- Credits Badge (Teachers Only) -->
        <div class="kc-credits-badge">
            <svg class="kc-credits-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/>
                <path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/>
                <path d="M12 18V6"/>
            </svg>
            <span id="credits-value">--</span>
            <span class="kc-credits-label"><?php echo $str('credits_label'); ?></span>
        </div>

        <!-- Main Form -->
        <div id="kc-form-section" class="kc-card">
            <h3 class="kc-card-title"><?php echo $str('page_heading'); ?></h3>
            <p class="kc-intro"><?php echo $str('page_intro'); ?></p>

            <form id="kc-form">
                <!-- Topics Input -->
                <div class="kc-form-group">
                    <label for="topics-input" class="kc-label"
                        ><?php echo $str('topics_label'); ?></label>
                    <textarea id="topics-input" class="kc-textarea" rows="6" 
                        placeholder="<?php echo $str('topics_placeholder'); ?>"></textarea>
                    <small class="kc-help"><?php echo $str('topics_help'); ?></small>
                </div>

                <!-- Performance Criteria (optional, one per line aligned with topics) -->
                <div class="kc-form-group" id="criteria-input-group">
                    <label for="criteria-input" class="kc-label"
                        ><?php echo $str('criteria_label'); ?></label>
                    <textarea id="criteria-input" class="kc-textarea" rows="4"
                        placeholder="<?php echo $str('criteria_placeholder'); ?>"></textarea>
                    <small class="kc-help"><?php echo $str('criteria_help'); ?></small>
                </div>

                <!-- Questions Per Topic -->
                <div class="kc-form-group" id="questions-per-topic-group">
                    <label for="questions-per-topic" class="kc-label"
                        ><?php echo $str('questions_per_topic'); ?></label>
                    <select id="questions-per-topic" class="kc-select">
                        <option value="1">1 question</option>
                        <option value="2">2 questions</option>
                        <option value="3">3 questions</option>
                        <option value="4">4 questions</option>
                        <option value="5" selected>5 questions</option>
                        <option value="6">6 questions</option>
                        <option value="7">7 questions</option>
                        <option value="8">8 questions</option>
                        <option value="9">9 questions</option>
                        <option value="10">10 questions</option>
                        <option value="12">12 questions</option>
                        <option value="15">15 questions</option>
                        <option value="20">20 questions</option>
                    </select>
                </div>

                <?php if ($issurveymode) : ?>
                <!-- SURVEY-MODE-NOTICE (v1.5.128): Inform the teacher their context before they paste questions. -->
                <div class="kc-survey-mode-notice">
                    <div class="kc-survey-notice-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
                        </svg>
                    </div>
                    <div class="kc-survey-notice-body">
                        <strong><?php echo $str('survey_mode_notice_title'); ?></strong>
                        <span><?php echo $str('survey_mode_notice_body', htmlspecialchars($surveyscaledisplay)); ?></span>
                    </div>
                </div>
                <?php endif; ?>

                <!-- User Questions Toggle -->
                <div class="kc-context-section">
                    <div class="kc-context-header">
                        <label class="kc-toggle-label">
                            <input type="checkbox" id="user-questions-toggle" class="kc-toggle-checkbox">
                            <span class="kc-toggle-switch"></span>
                            <span class="kc-toggle-text"><?php echo $str('use_own_questions'); ?></span>
                        </label>
                        <p class="kc-sublabel"><?php echo $issurveymode
                            ? $str('use_own_questions_help_survey')
                            : $str('use_own_questions_help'); ?></p>
                    </div>
                    <div id="user-questions-fields" class="kc-context-fields" style="display: none;">
                        <div class="kc-form-group">
                            <label for="user-questions-input" class="kc-label"
                                ><?php echo $str('your_questions'); ?></label>
                            <textarea id="user-questions-input" class="kc-textarea" rows="8" 
                                placeholder="<?php echo $issurveymode
                                    ? $str('your_questions_placeholder_survey')
                                    : $str('your_questions_placeholder'); ?>"></textarea>
                            <small class="kc-help"><?php echo $issurveymode
                                ? $str('your_questions_help_survey', htmlspecialchars($surveyscaledisplay))
                                : $str('your_questions_help'); ?></small>
                        </div>
                    </div>
                </div>

                <!-- Paste Content Toggle -->
                <div class="kc-context-section">
                    <div class="kc-context-header">
                        <label class="kc-toggle-label">
                            <input type="checkbox" id="paste-content-toggle" class="kc-toggle-checkbox">
                            <span class="kc-toggle-switch"></span>
                            <span class="kc-toggle-text"><?php echo $str('paste_content_toggle'); ?></span>
                        </label>
                        <p class="kc-sublabel"><?php echo $str('paste_content_help'); ?></p>
                    </div>
                    <div id="paste-content-fields" class="kc-context-fields" style="display: none;">
                        <div id="text-sources-container"></div>
                        <button type="button" id="add-text-source-btn" class="kc-add-source-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="5" x2="12" y2="19"/>
                                <line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                            <?php echo $str('add_text_source'); ?>
                        </button>
                    </div>
                </div>

                <!-- Free Text Questions (Survey Mode only — shown via JS when config.surveyMode) -->
                <div class="kc-form-group" id="freetext-questions-group" style="display: none;">
                    <label for="freetext-questions-input" class="kc-label"
                        ><?php echo $str('freetext_questions_label'); ?></label>
                    <textarea id="freetext-questions-input" class="kc-textarea" rows="4"
                        placeholder="<?php echo $str('freetext_questions_placeholder'); ?>"></textarea>
                    <small class="kc-help"><?php echo $str('freetext_questions_help'); ?></small>
                </div>

                <!-- Workplace Context Toggle -->
                <div class="kc-context-section">
                    <div class="kc-context-header">
                        <label class="kc-toggle-label">
                            <input type="checkbox" id="workplace-context-toggle" class="kc-toggle-checkbox">
                            <span class="kc-toggle-switch"></span>
                            <span class="kc-toggle-text"><?php echo $str('add_workplace_context'); ?></span>
                        </label>
                        <p class="kc-sublabel"><?php echo $str('workplace_context_help'); ?></p>
                    </div>
                    <div id="context-fields" class="kc-context-fields" style="display: none;">
                        <div class="kc-form-row">
                            <div class="kc-form-group kc-half">
                                <label for="country-select"><?php echo $str('country'); ?></label>
                                <select id="country-select" class="kc-select">
                                    <option value=""><?php echo $str('select_country'); ?></option>
                                    <option value="Australia" selected><?php echo $str('country_australia'); ?></option>
                                    <option value="New Zealand"><?php echo $str('country_newzealand'); ?></option>
                                    <option value="United Kingdom"><?php echo $str('country_uk'); ?></option>
                                    <option value="United States"><?php echo $str('country_us'); ?></option>
                                    <option value="Canada">Canada</option>
                                    <option value="Singapore"><?php echo $str('country_singapore'); ?></option>
                                </select>
                            </div>
                            <div class="kc-form-group kc-half">
                                <label for="state-select"><?php echo $str('state'); ?></label>
                                <select id="state-select" class="kc-select">
                                    <option value=""><?php echo $str('select_state'); ?></option>
                                    <option value="Western Australia"><?php echo $str('state_wa'); ?></option>
                                    <option value="Queensland"><?php echo $str('state_qld'); ?></option>
                                    <option value="New South Wales"><?php echo $str('state_nsw'); ?></option>
                                    <option value="Victoria"><?php echo $str('state_vic'); ?></option>
                                    <option value="South Australia"><?php echo $str('state_sa'); ?></option>
                                    <option value="Tasmania"><?php echo $str('state_tas'); ?></option>
                                    <option value="Northern Territory"><?php echo $str('state_nt'); ?></option>
                                    <option value="Australian Capital Territory">ACT</option>
                                </select>
                            </div>
                        </div>
                        <div class="kc-form-row">
                            <div class="kc-form-group kc-half">
                                <label for="industry-select"><?php echo $str('industry'); ?></label>
                                <select id="industry-select" class="kc-select">
                                    <option value=""><?php echo $str('select_industry'); ?></option>
                                </select>
                            </div>
                            <div class="kc-form-group kc-half">
                                <label for="industry-sector"><?php echo $str('industry_sector'); ?></label>
                                <select id="industry-sector" class="kc-select" disabled>
                                    <option value=""><?php echo $str('select_industry_first'); ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="kc-form-row">
                            <div class="kc-form-group">
                                <label><?php echo $str('job_level'); ?>
                                    <small class="kc-label-hint">(select one or more)</small></label>
                                <div class="kc-level-pills" id="kc-job-level-pills">
                                    <button type="button" class="kc-level-pill" data-value="Worker">Worker</button>
                                    <button type="button" class="kc-level-pill" data-value="Supervisor">
                                        <?php echo $str('level_supervisor'); ?></button>
                                    <button type="button" class="kc-level-pill" data-value="Manager">Manager</button>
                                    <button type="button" class="kc-level-pill" data-value="Executive">
                                        <?php echo $str('level_executive'); ?></button>
                                </div>
                            </div>
                        </div>
                        <div class="kc-form-row">
                            <div class="kc-form-group">
                                <label for="kc-job-role-input"><?php echo $str('job_title'); ?>
                                    <small class="kc-label-hint">(up to 5 — press Enter to add)</small></label>
                                <div class="kc-role-chips" id="kc-job-role-chips"></div>
                                <input type="text" id="kc-job-role-input" class="kc-input"
                                    placeholder="e.g. Site Supervisor, Project Manager...">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Education Settings -->
                <div class="kc-education-section">
                    <div class="kc-form-row">
                        <div class="kc-form-group kc-half">
                            <label for="education-type-select"><?php echo $str('education_type'); ?></label>
                            <select id="education-type-select" class="kc-select">
                                <option value="vet" selected
                                    ><?php echo $str('education_vet'); ?></option>
                                <option value="academic"><?php echo $str('education_academic'); ?></option>
                                <option value="general"><?php echo $str('education_general'); ?></option>
                            </select>
                        </div>
                        <div class="kc-form-group kc-half" id="vet-level-field">
                            <label for="vet-level-select"><?php echo $str('vet_level'); ?></label>
                            <select id="vet-level-select" class="kc-select">
                                <option value=""><?php echo $str('select_vet_level'); ?></option>
                                <option value="cert1"><?php echo $str('vet_cert1'); ?></option>
                                <option value="cert2"><?php echo $str('vet_cert2'); ?></option>
                                <option value="cert3" selected
                                    ><?php echo $str('vet_cert3'); ?></option>
                                <option value="cert4"><?php echo $str('vet_cert4'); ?></option>
                                <option value="diploma"><?php echo $str('vet_diploma'); ?></option>
                                <option value="adv_diploma"><?php echo $str('vet_adv_diploma'); ?></option>
                            </select>
                        </div>
                        <div class="kc-form-group kc-half" id="academic-level-field" style="display: none;">
                            <label for="academic-level-select"><?php echo $str('academic_level'); ?></label>
                            <select id="academic-level-select" class="kc-select">
                                <option value=""><?php echo $str('select_academic_level'); ?></option>
                                <option value="undergraduate"><?php echo $str('academic_undergraduate'); ?></option>
                                <option value="postgraduate"><?php echo $str('academic_postgraduate'); ?></option>
                                <option value="masters"><?php echo $str('academic_masters'); ?></option>
                                <option value="phd"><?php echo $str('academic_phd'); ?></option>
                            </select>
                        </div>
                    </div>
                    <!-- Education Info Cards -->
                    <div class="kc-education-info">
                        <div class="kc-info-card kc-info-vet" id="vet-info-card">
                            <div class="kc-info-card-header">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2"><path
                                        d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94
                                            7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"
                                        /></svg>
                                <span class="kc-info-card-title"><?php echo $str('vet_tooltip_title'); ?></span>
                            </div>
                            <p class="kc-info-card-text"><?php echo $str('vet_tooltip'); ?></p>
                        </div>
                        <div class="kc-info-card kc-info-academic" id="academic-info-card" style="display: none;">
                            <div class="kc-info-card-header">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2">
                                    <path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                                <span class="kc-info-card-title"><?php echo $str('academic_tooltip_title'); ?></span>
                            </div>
                            <p class="kc-info-card-text"><?php echo $str('academic_tooltip'); ?></p>
                        </div>
                        <div class="kc-info-card kc-info-general" id="general-info-card" style="display: none;">
                            <div class="kc-info-card-header">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"
                                        ry="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
                                <span class="kc-info-card-title"><?php echo $str('general_tooltip_title'); ?></span>
                            </div>
                            <p class="kc-info-card-text"><?php echo $str('general_tooltip'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Extra AI Instructions -->
                <div class="kc-form-group">
                    <label for="extra-instructions" class="kc-label"
                        ><?php echo $str('extra_instructions'); ?></label>
                    <textarea id="extra-instructions" class="kc-textarea" rows="3" 
                        placeholder="<?php echo $str('extra_instructions_placeholder'); ?>"></textarea>
                    <small class="kc-help"><?php echo $str('extra_instructions_help'); ?></small>
                </div>

                <!-- Content Language (always visible - controls spelling/grammar of generated questions) -->
                <div class="kc-form-group">
                    <label for="voice-language" class="kc-label"
                        ><?php echo $str('voice_language'); ?></label>
                    <small class="kc-help"><?php echo $str('language_help'); ?></small>
                    <select id="voice-language" class="kc-select">
                        <!-- English variants -->
                        <optgroup label="English">
                            <option value="en-AU" selected><?php echo $str('lang_en_au'); ?></option>
                            <option value="en-GB"><?php echo $str('lang_en_gb'); ?></option>
                            <option value="en-IN"><?php echo $str('lang_en_in'); ?></option>
                            <option value="en-US"><?php echo $str('lang_en_us'); ?></option>
                        </optgroup>
                        <!-- Spanish variants -->
                        <optgroup label="Spanish">
                            <option value="es-ES"><?php echo $str('lang_es_es'); ?></option>
                            <option value="es-US"><?php echo $str('lang_es_us'); ?></option>
                        </optgroup>
                        <!-- French variants -->
                        <optgroup label="French">
                            <option value="fr-CA"><?php echo $str('lang_fr_ca'); ?></option>
                            <option value="fr-FR"><?php echo $str('lang_fr_fr'); ?></option>
                        </optgroup>
                        <!-- German -->
                        <optgroup label="German">
                            <option value="de-DE"><?php echo $str('lang_de_de'); ?></option>
                        </optgroup>
                        <!-- Portuguese -->
                        <optgroup label="Portuguese">
                            <option value="pt-BR"><?php echo $str('lang_pt_br'); ?></option>
                        </optgroup>
                        <!-- Dutch variants -->
                        <optgroup label="Dutch">
                            <option value="nl-BE"><?php echo $str('lang_nl_be'); ?></option>
                            <option value="nl-NL"><?php echo $str('lang_nl_nl'); ?></option>
                        </optgroup>
                        <!-- Nordic languages -->
                        <optgroup label="Nordic">
                            <option value="da-DK"><?php echo $str('lang_da_dk'); ?></option>
                            <option value="fi-FI"><?php echo $str('lang_fi_fi'); ?></option>
                            <option value="nb-NO"><?php echo $str('lang_nb_no'); ?></option>
                            <option value="sv-SE"><?php echo $str('lang_sv_se'); ?></option>
                        </optgroup>
                        <!-- Eastern European languages -->
                        <optgroup label="Eastern European">
                            <option value="bg-BG"><?php echo $str('lang_bg_bg'); ?></option>
                            <option value="cs-CZ"><?php echo $str('lang_cs_cz'); ?></option>
                            <option value="hr-HR"><?php echo $str('lang_hr_hr'); ?></option>
                            <option value="hu-HU"><?php echo $str('lang_hu_hu'); ?></option>
                            <option value="pl-PL"><?php echo $str('lang_pl_pl'); ?></option>
                            <option value="ro-RO"><?php echo $str('lang_ro_ro'); ?></option>
                            <option value="ru-RU"><?php echo $str('lang_ru_ru'); ?></option>
                            <option value="sk-SK"><?php echo $str('lang_sk_sk'); ?></option>
                            <option value="sl-SI"><?php echo $str('lang_sl_si'); ?></option>
                            <option value="sr-RS"><?php echo $str('lang_sr_rs'); ?></option>
                            <option value="uk-UA"><?php echo $str('lang_uk_ua'); ?></option>
                        </optgroup>
                        <!-- Baltic languages -->
                        <optgroup label="Baltic">
                            <option value="et-EE"><?php echo $str('lang_et_ee'); ?></option>
                            <option value="lt-LT"><?php echo $str('lang_lt_lt'); ?></option>
                            <option value="lv-LV"><?php echo $str('lang_lv_lv'); ?></option>
                        </optgroup>
                        <!-- Southern European languages -->
                        <optgroup label="Southern European">
                            <option value="el-GR"><?php echo $str('lang_el_gr'); ?></option>
                            <option value="it-IT"><?php echo $str('lang_it_it'); ?></option>
                        </optgroup>
                        <!-- East Asian languages -->
                        <optgroup label="East Asian">
                            <option value="cmn-CN"><?php echo $str('lang_cmn_cn'); ?></option>
                            <option value="ja-JP"><?php echo $str('lang_ja_jp'); ?></option>
                            <option value="ko-KR"><?php echo $str('lang_ko_kr'); ?></option>
                        </optgroup>
                        <!-- Southeast Asian languages -->
                        <optgroup label="Southeast Asian">
                            <option value="id-ID"><?php echo $str('lang_id_id'); ?></option>
                            <option value="th-TH"><?php echo $str('lang_th_th'); ?></option>
                            <option value="vi-VN"><?php echo $str('lang_vi_vn'); ?></option>
                        </optgroup>
                        <!-- South Asian languages -->
                        <optgroup label="South Asian">
                            <option value="bn-IN"><?php echo $str('lang_bn_in'); ?></option>
                            <option value="gu-IN"><?php echo $str('lang_gu_in'); ?></option>
                            <option value="hi-IN"><?php echo $str('lang_hi_in'); ?></option>
                            <option value="kn-IN"><?php echo $str('lang_kn_in'); ?></option>
                            <option value="ml-IN"><?php echo $str('lang_ml_in'); ?></option>
                            <option value="mr-IN"><?php echo $str('lang_mr_in'); ?></option>
                            <option value="ta-IN"><?php echo $str('lang_ta_in'); ?></option>
                            <option value="te-IN"><?php echo $str('lang_te_in'); ?></option>
                            <option value="ur-IN"><?php echo $str('lang_ur_in'); ?></option>
                        </optgroup>
                        <!-- Middle Eastern languages -->
                        <optgroup label="Middle Eastern">
                            <option value="ar-XA"><?php echo $str('lang_ar_xa'); ?></option>
                            <option value="he-IL"><?php echo $str('lang_he_il'); ?></option>
                            <option value="tr-TR"><?php echo $str('lang_tr_tr'); ?></option>
                        </optgroup>
                        <!-- African languages -->
                        <optgroup label="African">
                            <option value="sw-KE"><?php echo $str('lang_sw_ke'); ?></option>
                        </optgroup>
                    </select>
                </div>

                <!-- Voiceover Toggle -->
                <div class="kc-form-group">
                    <div class="kc-toggle-row">
                        <label class="kc-toggle-label" for="voiceover-toggle">
                            <input type="checkbox" id="voiceover-toggle"
                                <?php echo (!empty($knowledgecheck->voiceoverenabled)) ? 'checked' : ''; ?>>
                            <span><?php echo $str('voiceover_enabled'); ?></span>
                        </label>
                        <small class="kc-help"><?php echo $str('voiceover_enabled_help'); ?></small>
                    </div>
                </div>

                <!-- Voice Settings (only visible when voiceover is enabled) -->
                <div id="voice-settings-section"
                    <?php echo (empty($knowledgecheck->voiceoverenabled)) ? 'style="display: none;"' : ''; ?>>
                    <div class="kc-form-row">
                        <div class="kc-form-group kc-half">
                            <label for="voice-gender"><?php echo $str('voice_gender'); ?></label>
                            <select id="voice-gender" class="kc-select">
                                <option value="female"><?php echo $str('voice_female'); ?></option>
                                <option value="male"><?php echo $str('voice_male'); ?></option>
                            </select>
                        </div>
                        <div class="kc-form-group kc-half">
                            <label for="voice-style"><?php echo $str('voice_style'); ?></label>
                            <select id="voice-style" class="kc-select">
                                <!-- Populated dynamically by JavaScript based on gender selection -->
                                <option value="Zephyr"><?php echo $str('voice_zephyr'); ?></option>
                                <option value="Aoede"><?php echo $str('voice_aoede'); ?></option>
                                <option value="Kore"><?php echo $str('voice_kore'); ?></option>
                                <option value="Leda"><?php echo $str('voice_leda'); ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Credit Cost Banner (styled like Content Creator) -->
                <div id="preview-stats" class="kc-credit-cost-banner" style="display: none;">
                    <div class="kc-credit-cost-row">
                        <div class="kc-credit-cost-info">
                            <span class="kc-credit-cost-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 6v6l4 2"/>
                                </svg>
                            </span>
                            <span id="kc-credit-formula" class="kc-credit-formula" data-testid="text-credit-formula"></span>
                            <span class="kc-credit-cost-label">to generate content</span>
                        </div>
                        <div class="kc-credit-balance-box">
                            <span class="kc-balance-label"><?php echo $str('your_balance'); ?></span>
                            <span id="kc-balance-amount" class="kc-balance-amount" data-testid="text-credit-balance">--</span>
                            <span class="kc-balance-unit">credits</span>
                            <a href="https://lms-labs.com/pricing" target="_blank" class="kc-buy-credits-link"
                                data-testid="link-buy-credits">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 8v8M8 12h8"/>
                                </svg>
                                <?php echo $str('buy_more'); ?>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Generate Button -->
                <button type="submit" id="generate-btn" class="kc-btn kc-btn-primary" disabled>
                    <?php echo $str('generate_btn'); ?>
                </button>
            </form>
        </div>

        <!-- Progress Section (Hidden by default) -->
        <div id="kc-progress-section" class="kc-card" style="display: none;">
            <!-- Credit cost visible during generation -->
            <div id="kc-progress-credit-banner" class="kc-credit-cost-banner kc-credit-cost-banner--progress">
                <div class="kc-credit-cost-row">
                    <div class="kc-credit-cost-info">
                        <span class="kc-credit-cost-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 6v6l4 2"/>
                            </svg>
                        </span>
                        <span id="kc-progress-credit-formula" class="kc-credit-formula" data-testid="text-progress-credit-formula">
                            </span>
                        <span class="kc-credit-cost-label">to generate content</span>
                    </div>
                    <div class="kc-credit-balance-box">
                        <span class="kc-balance-label"><?php echo $str('your_balance'); ?></span>
                        <span id="kc-progress-balance" class="kc-balance-amount" data-testid="text-progress-balance">--</span>
                        <span class="kc-balance-unit">credits</span>
                        <a href="https://lms-labs.com/pricing" target="_blank" class="kc-buy-credits-link"
                            data-testid="link-progress-buy-credits">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 8v8M8 12h8"/>
                            </svg>
                            <?php echo $str('buy_more'); ?>
                        </a>
                    </div>
                </div>
            </div>
            <h3 class="kc-card-title"><?php echo $str('generating'); ?></h3>
            <div class="kc-progress-bar">
                <div id="progress-fill" class="kc-progress-fill"></div>
            </div>
            <p id="progress-message" class="kc-progress-message"
                ><?php echo $str('initializing'); ?></p>
        </div>

        <!-- Quiz Ready Section (Hidden by default) -->
        <div id="kc-ready-section" class="kc-card kc-ready-card" style="display: none;">
            <div class="kc-ready-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
            </div>
            <h3 class="kc-ready-title"><?php echo $str('quiz_ready'); ?></h3>
            <p id="ready-summary" class="kc-ready-summary"></p>
            <div id="kc-teacher-eta"></div>
            <div class="kc-ready-regen-section">
                <div class="kc-form-group">
                    <label for="ready-extra-instructions" class="kc-label"><?php echo $str('extra_instructions'); ?></label>
                    <textarea id="ready-extra-instructions" class="kc-textarea" rows="3" 
                        placeholder="Add or modify instructions for the AI to refine the generated questions..."></textarea>
                    <small class="kc-help">Edit these instructions and click Regenerate to refine your questions.
                        First 3 regenerations are free.</small>
                </div>
                <div class="kc-regen-controls">
                    <button id="ready-regenerate-btn" class="kc-btn kc-btn-secondary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="kc-icon-inline">
                            <polyline points="1 4 1 10 7 10"/>
                            <polyline points="23 20 23 14 17 14"/>
                            <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/>
                        </svg>
                        <?php echo $str('regenerate_questions'); ?>
                    </button>
                    <span id="ready-regen-count" class="kc-regen-count"></span>
                </div>
            </div>
            <?php if ($hasaudio) : ?>
            <div id="kc-teacher-audio-gate" class="kc-media-panel">
                <strong class="kc-panel-title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        class="kc-icon-baseline">
                        <path d="M3 18v-6a9 9 0 0 1 18 0v6"></path>
                        <path
                            d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2
                                0 0 0-2-2H3z"></path>
                    </svg>
                    <?php echo $str('audiogate_listenaudio'); ?>
                </strong>
                <audio id="kc-audio-player" controls class="kc-audio-player">
                    <source src="<?php echo s($audiourl); ?>">
                </audio>
                <?php if ($audiogated) : ?>
                <div id="kc-audio-status" class="kc-gate-status">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        class="kc-icon-baseline">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    <span id="kc-audio-status-text">
                    <?php
                    if ($audioreq === 'full') {
                        echo $str('audiogate_listenfull');
                    } else {
                        echo $str('audiogate_listenseconds', $audiominsec);
                    }
                    ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($hasimage) : ?>
            <div id="kc-teacher-image-gate" class="kc-media-panel">
                <strong class="kc-panel-title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        class="kc-icon-baseline">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                        <polyline points="21 15 16 10 5 21"></polyline>
                    </svg>
                    <?php echo $str('imagegate_viewimage'); ?>
                </strong>
                <img src="<?php echo s($imageurlgate); ?>" alt="Image gate preview" class="kc-preview-image">
                <small class="kc-hint-sm">Students must acknowledge this image before the quiz
                    unlocks. Manage the image URL in the activity settings (Image Gate section).</small>
            </div>
            <?php endif; ?>
            <!-- AI Image Generator (teachers only) -->
            <div id="kc-imagegen-panel" class="kc-media-panel">
                <strong class="kc-panel-title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        class="kc-icon-baseline">
                        <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                    </svg>
                    <?php echo $str('imagegate_generateimage'); ?>
                    <span class="kc-cost-hint"
                        ><?php echo $str('imagegate_credits_cost'); ?></span>
                </strong>
                <div class="kc-button-row">
                    <input type="text" id="kc-imagegen-prompt" placeholder="Describe the image to generate..."
                        class="kc-imagegen-prompt">
                    <button id="kc-imagegen-btn" type="button" class="kc-btn kc-btn-secondary kc-btn-sm">
                        <?php echo $str('generate_image'); ?>
                    </button>
                </div>
                <div id="kc-imagegen-status" class="kc-imagegen-status" style="display: none;"></div>
                <div id="kc-imagegen-result" style="display: none;">
                    <img id="kc-imagegen-preview" alt="Generated image" class="kc-preview-image">
                    <div class="kc-imagegen-caption"
                        >Copy the URL below and paste it into the Image Gate field in Settings, or click "Set as Gate Image":</div>
                    <textarea id="kc-imagegen-url-output" rows="2" readonly class="kc-imagegen-url"></textarea>
                    <div class="kc-button-row-top">
                        <button id="kc-imagegen-save-gate" type="button" class="kc-btn kc-btn-primary kc-btn-xs"
                            ><?php echo $str('set_as_gate_image'); ?></button>
                        <button id="kc-imagegen-copy" type="button" class="kc-btn kc-btn-secondary kc-btn-xs"
                            ><?php echo $str('copy_url'); ?></button>
                    </div>
                    <div id="kc-imagegen-save-status" class="kc-imagegen-save-status"></div>
                </div>
            </div>
            <div class="kc-ready-actions">
                <?php
                // Release v1.5.50 FIX-KC-GATE-TEACHER: Teachers/managers (who have the
                // 'create' capability) must never see the Review Questions button
                // gated. Previously $anygated applied unconditionally even for
                // teachers viewing their own activity, blocking teacher access
                // to their own quiz review flow.
                $takegated = $anygated && !$isstaff;
                $gatedclass = $takegated ? ' kc-gated-btn' : '';
                $gateddisabled = $takegated ? ' disabled' : '';
                $gatedattrs = $gatedclass . '"' . $gateddisabled;
                ?>
                <button id="take-quiz-btn" class="kc-btn kc-btn-primary<?php echo $gatedattrs; ?>>
                    <?php echo $str('review_questions_btn'); ?>
                </button>
                <button id="add-more-questions-btn" class="kc-btn kc-btn-success">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="kc-icon-inline"
                        >
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="16"/>
                        <line x1="8" y1="12" x2="16" y2="12"/>
                    </svg>
                    <?php echo $str('add_more_questions'); ?>
                </button>
                <button id="edit-questions-btn" class="kc-btn kc-btn-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="kc-icon-inline"
                        >
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                    <?php echo $str('edit_questions'); ?>
                </button>
                <button id="download-excel-btn" class="kc-btn kc-btn-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="kc-icon-inline"
                        >
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                        <polyline points="10 9 9 9 8 9"/>
                    </svg>
                    <?php echo $str('download_question_mapping'); ?>
                </button>
            </div>
        </div>
        
        <!-- Edit Questions Section (Hidden by default) -->
        <div id="kc-edit-section" class="kc-card" style="display: none;">
            <div class="kc-edit-header">
                <h3 class="kc-card-title"><?php echo $str('edit_questions'); ?></h3>
                <div class="kc-edit-actions">
                    <button id="edit-settings-btn" class="kc-btn kc-btn-outline" title="Settings">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="kc-icon-inline">
                            <circle cx="12" cy="12" r="3"/>
                            <path
                                d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0
                                    0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9
                                    19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0
                                    4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65
                                    1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9
                                    4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65
                                    0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65
                                    1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                        </svg>
                        <?php echo $str('settings_btn'); ?>
                    </button>
                    <button id="save-edits-btn" class="kc-btn kc-btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="kc-icon-inline">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                            <polyline points="17 21 17 13 7 13 7 21"/>
                            <polyline points="7 3 7 8 15 8"/>
                        </svg>
                        <?php echo $str('save_changes'); ?>
                    </button>
                    <button id="cancel-edits-btn" class="kc-btn kc-btn-secondary">Cancel</button>
                </div>
            </div>
            <p class="kc-edit-info"><?php echo $str('edit_questions_help'); ?></p>
            <div class="kc-edit-regen-section">
                <div class="kc-form-group">
                    <label for="edit-extra-instructions" class="kc-label"><?php echo $str('extra_instructions'); ?></label>
                    <textarea id="edit-extra-instructions" class="kc-textarea" rows="3" 
                        placeholder="Add or modify instructions for the AI to refine the generated questions..."></textarea>
                    <small class="kc-help">Edit these instructions and click Regenerate to refine your questions.
                        First 3 regenerations are free.</small>
                </div>
                <div class="kc-regen-controls">
                    <button id="edit-regenerate-btn" class="kc-btn kc-btn-secondary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="kc-icon-inline">
                            <polyline points="1 4 1 10 7 10"/>
                            <polyline points="23 20 23 14 17 14"/>
                            <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/>
                        </svg>
                        <?php echo $str('regenerate_questions'); ?>
                    </button>
                    <span id="edit-regen-count" class="kc-regen-count"></span>
                </div>
            </div>
            <div id="edit-questions-container"></div>
        </div>

        <!-- Settings Modal Overlay -->
        <div id="kc-settings-overlay" class="kc-settings-overlay" style="display: none;">
            <div class="kc-settings-modal">
                <div class="kc-settings-modal-header">
                    <h3 class="kc-settings-modal-title">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="3"/>
                            <path
                                d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0
                                    0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9
                                    19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0
                                    4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65
                                    1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9
                                    4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65
                                    0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65
                                    1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                        </svg>
                        <?php echo $str('quiz_settings'); ?>
                    </h3>
                    <button id="close-settings-btn" class="kc-settings-close" title="Close">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>

                <div class="kc-settings-modal-body">
                    <div class="kc-settings-section">
                        <h4 class="kc-settings-section-title"><?php echo $str('content_language'); ?></h4>
                        <p class="kc-settings-section-desc"
                            ><?php echo $str('content_language_help'); ?></p>
                        <div class="kc-form-group kc-flush-bottom">
                            <select id="settings-voice-language" class="kc-select">
                                <optgroup label="English">
                                    <option value="en-AU" selected><?php echo $str('lang_en_au'); ?></option>
                                    <option value="en-GB"><?php echo $str('lang_en_gb'); ?></option>
                                    <option value="en-IN"><?php echo $str('lang_en_in'); ?></option>
                                    <option value="en-US"><?php echo $str('lang_en_us'); ?></option>
                                </optgroup>
                                <optgroup label="Spanish">
                                    <option value="es-ES"><?php echo $str('lang_es_es'); ?></option>
                                    <option value="es-US"><?php echo $str('lang_es_us'); ?></option>
                                </optgroup>
                                <optgroup label="French">
                                    <option value="fr-CA"><?php echo $str('lang_fr_ca'); ?></option>
                                    <option value="fr-FR"><?php echo $str('lang_fr_fr'); ?></option>
                                </optgroup>
                                <optgroup label="German">
                                    <option value="de-DE"><?php echo $str('lang_de_de'); ?></option>
                                </optgroup>
                                <optgroup label="Portuguese">
                                    <option value="pt-BR"><?php echo $str('lang_pt_br'); ?></option>
                                </optgroup>
                                <optgroup label="Dutch">
                                    <option value="nl-BE"><?php echo $str('lang_nl_be'); ?></option>
                                    <option value="nl-NL"><?php echo $str('lang_nl_nl'); ?></option>
                                </optgroup>
                                <optgroup label="Nordic">
                                    <option value="da-DK"><?php echo $str('lang_da_dk'); ?></option>
                                    <option value="fi-FI"><?php echo $str('lang_fi_fi'); ?></option>
                                    <option value="nb-NO"><?php echo $str('lang_nb_no'); ?></option>
                                    <option value="sv-SE"><?php echo $str('lang_sv_se'); ?></option>
                                </optgroup>
                                <optgroup label="Eastern European">
                                    <option value="bg-BG"><?php echo $str('lang_bg_bg'); ?></option>
                                    <option value="cs-CZ"><?php echo $str('lang_cs_cz'); ?></option>
                                    <option value="hr-HR"><?php echo $str('lang_hr_hr'); ?></option>
                                    <option value="hu-HU"><?php echo $str('lang_hu_hu'); ?></option>
                                    <option value="pl-PL"><?php echo $str('lang_pl_pl'); ?></option>
                                    <option value="ro-RO"><?php echo $str('lang_ro_ro'); ?></option>
                                    <option value="ru-RU"><?php echo $str('lang_ru_ru'); ?></option>
                                    <option value="sk-SK"><?php echo $str('lang_sk_sk'); ?></option>
                                    <option value="sl-SI"><?php echo $str('lang_sl_si'); ?></option>
                                    <option value="sr-RS"><?php echo $str('lang_sr_rs'); ?></option>
                                    <option value="uk-UA"><?php echo $str('lang_uk_ua'); ?></option>
                                </optgroup>
                                <optgroup label="Baltic">
                                    <option value="et-EE"><?php echo $str('lang_et_ee'); ?></option>
                                    <option value="lt-LT"><?php echo $str('lang_lt_lt'); ?></option>
                                    <option value="lv-LV"><?php echo $str('lang_lv_lv'); ?></option>
                                </optgroup>
                                <optgroup label="Southern European">
                                    <option value="el-GR"><?php echo $str('lang_el_gr'); ?></option>
                                    <option value="it-IT"><?php echo $str('lang_it_it'); ?></option>
                                </optgroup>
                                <optgroup label="East Asian">
                                    <option value="cmn-CN"><?php echo $str('lang_cmn_cn'); ?></option>
                                    <option value="ja-JP"><?php echo $str('lang_ja_jp'); ?></option>
                                    <option value="ko-KR"><?php echo $str('lang_ko_kr'); ?></option>
                                </optgroup>
                                <optgroup label="Southeast Asian">
                                    <option value="id-ID"><?php echo $str('lang_id_id'); ?></option>
                                    <option value="th-TH"><?php echo $str('lang_th_th'); ?></option>
                                    <option value="vi-VN"><?php echo $str('lang_vi_vn'); ?></option>
                                </optgroup>
                                <optgroup label="South Asian">
                                    <option value="bn-IN"><?php echo $str('lang_bn_in'); ?></option>
                                    <option value="gu-IN"><?php echo $str('lang_gu_in'); ?></option>
                                    <option value="hi-IN"><?php echo $str('lang_hi_in'); ?></option>
                                    <option value="kn-IN"><?php echo $str('lang_kn_in'); ?></option>
                                    <option value="ml-IN"><?php echo $str('lang_ml_in'); ?></option>
                                    <option value="mr-IN"><?php echo $str('lang_mr_in'); ?></option>
                                    <option value="ta-IN"><?php echo $str('lang_ta_in'); ?></option>
                                    <option value="te-IN"><?php echo $str('lang_te_in'); ?></option>
                                    <option value="ur-IN"><?php echo $str('lang_ur_in'); ?></option>
                                </optgroup>
                                <optgroup label="Middle Eastern">
                                    <option value="ar-XA"><?php echo $str('lang_ar_xa'); ?></option>
                                    <option value="he-IL"><?php echo $str('lang_he_il'); ?></option>
                                    <option value="tr-TR"><?php echo $str('lang_tr_tr'); ?></option>
                                </optgroup>
                                <optgroup label="African">
                                    <option value="sw-KE">Swahili</option>
                                </optgroup>
                            </select>
                        </div>
                    </div>

                    <div class="kc-settings-divider"></div>

                    <div class="kc-settings-section">
                        <h4 class="kc-settings-section-title"><?php echo $str('voiceover_header'); ?></h4>
                        <div class="kc-toggle-row kc-spaced-bottom-sm">
                            <label class="kc-toggle-label" for="settings-voiceover-toggle">
                                <input type="checkbox" id="settings-voiceover-toggle"
                                    <?php echo (!empty($knowledgecheck->voiceoverenabled)) ? 'checked' : ''; ?>>
                                <span><?php echo $str('voiceover_toggle_label'); ?></span>
                            </label>
                            <small class="kc-help"><?php echo $str('voiceover_toggle_help'); ?></small>
                        </div>
                    </div>

                    <div id="settings-voice-options">
                        <div class="kc-settings-divider"></div>

                        <div class="kc-settings-section">
                            <h4 class="kc-settings-section-title"><?php echo $str('voice_settings_heading'); ?></h4>
                            <div class="kc-form-row">
                                <div class="kc-form-group kc-half">
                                    <label for="settings-voice-gender"><?php echo $str('voice_gender'); ?></label>
                                    <select id="settings-voice-gender" class="kc-select">
                                        <option value="female">Female</option>
                                        <option value="male">Male</option>
                                    </select>
                                </div>
                                <div class="kc-form-group kc-half">
                                    <label for="settings-voice-style"><?php echo $str('voice_style'); ?></label>
                                    <select id="settings-voice-style" class="kc-select">
                                        <option value="Zephyr"><?php echo $str('voice_zephyr'); ?></option>
                                        <option value="Aoede"><?php echo $str('voice_aoede'); ?></option>
                                        <option value="Kore"><?php echo $str('voice_kore'); ?></option>
                                        <option value="Leda"><?php echo $str('voice_leda'); ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="kc-settings-modal-footer">
                    <p id="settings-warning-text" class="kc-settings-warning">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8"
                                x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <span id="settings-warning-msg"><?php echo $str('settings_language_warning'); ?></span>
                    </p>
                    <div class="kc-settings-footer-actions">
                        <button id="settings-cancel-btn" class="kc-btn kc-btn-secondary">Cancel</button>
                        <button id="settings-save-btn" class="kc-btn kc-btn-primary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" class="kc-icon-inline">
                                <polyline points="23 4 11.5 15.5 6 10"/></svg>
                            <?php echo $str('save_settings'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quiz Player (Hidden by default) -->
        <div id="kc-quiz-player" class="kc-card" style="display: none;">
            <div class="kc-quiz-header">
                <span id="question-counter" class="kc-question-counter" aria-live="polite"></span>
                <span id="quiz-score" class="kc-quiz-score" aria-live="polite"></span>
            </div>
            <div id="question-container" class="kc-question-container">
                <h4 id="question-text" class="kc-question-text" tabindex="-1"></h4>
                <div id="options-container" class="kc-options"></div>
            </div>
            <!-- A11Y (v1.5.161): the result is announced as it appears. Without a live region a
                 screen-reader user hears nothing when the answer is graded, because focus has
                 not moved. -->
            <div id="feedback-container" class="kc-feedback" style="display: none;" role="status"
                aria-live="polite">
                <div id="feedback-result" class="kc-feedback-result"></div>
                <p id="feedback-explanation" class="kc-feedback-explanation"></p>
                <button id="play-audio-btn" class="kc-btn kc-btn-secondary">
                    <?php echo $str('play_explanation'); ?>
                </button>
            </div>
            <div class="kc-quiz-actions">
                <button id="check-answer-btn" class="kc-btn kc-btn-primary" disabled>
                    <?php echo $str('check_answer'); ?>
                </button>
                <button id="next-question-btn" class="kc-btn kc-btn-primary" style="display: none;">
                    <?php echo $str('next_question'); ?>
                </button>
            </div>
        </div>

        <!-- Quiz Results (Hidden by default) -->
        <div id="kc-results" class="kc-card" style="display: none;">
            <div id="results-icon" class="kc-results-icon">🎉</div>
            <h3 class="kc-card-title"><?php echo $str('quiz_complete'); ?></h3>
            <div id="results-score" class="kc-results-score"></div>
            <p id="results-message" class="kc-results-message"></p>
            <button id="retake-btn" class="kc-btn kc-btn-secondary">
                <?php echo $str('retake_btn'); ?>
            </button>
        </div>
    </div>
    <?php

    // Check for any in-progress student attempts (for edit warning).
    $inprogresscount = $DB->count_records(
        'aiknowledgecheck_attempts',
        [
            'aiknowledgecheckid' => $knowledgecheck->id,
            'status' => 0, // In progress.
        ]
    );

    $PAGE->requires->js_call_amd('mod_aiknowledgecheck/mediagates', 'init', [$gatesconfig]);
    $PAGE->requires->js_call_amd('mod_aiknowledgecheck/imagegen', 'init', [(int)$cm->id]);
    $PAGE->requires->js_call_amd(
        'mod_aiknowledgecheck/knowledgecheck',
        'init',
        [[
            'cmid' => $cm->id,
            'wwwroot' => $CFG->wwwroot,
            'sesskey' => sesskey(),
            'isTeacher' => true,
            'inProgressAttempts' => (int)$inprogresscount,
            'gradePass' => $gradepass,
            'maxGrade' => $maxgrade,
            'voiceoverEnabled' => isset($knowledgecheck->voiceoverenabled) ? (int)$knowledgecheck->voiceoverenabled : 0,
            'voiceLanguage' => isset($knowledgecheck->voicelanguage) ? $knowledgecheck->voicelanguage : 'en-AU',
            'voiceGender' => isset($knowledgecheck->voicegender) ? $knowledgecheck->voicegender : 'female',
            'voiceStyle' => isset($knowledgecheck->voicestyle) ? $knowledgecheck->voicestyle : 'Zephyr',
            'showChapterStamps' => isset($knowledgecheck->showchapterstamps) ? (int)$knowledgecheck->showchapterstamps : 0,
            'hasVideo' => $hasvideo ? 1 : 0,
            'hasImage' => $hasimage ? 1 : 0,
            'surveyMode' => isset($knowledgecheck->surveymode) ? (int)$knowledgecheck->surveymode : 0,
            'surveyScale' => isset($knowledgecheck->surveyscale) ? $knowledgecheck->surveyscale : 'likert5agree',
        ]]
    );
    ?>
    <?php
} else {
    // Student view.
    if ($questioncount == 0) {
        // No questions yet.
        echo html_writer::div($str('students_view_message'), 'alert alert-info');
    } else {
        // Get attempt info.
        $userid = $USER->id;
        $attemptsused = aiknowledgecheck_count_attempts($knowledgecheck->id, $userid);
        $maxattempts = aiknowledgecheck_effective_maxattempts($knowledgecheck, $userid);
        $canattempt = aiknowledgecheck_can_attempt($knowledgecheck, $userid);

        // Check for in-progress attempt.
        $inprogress = $DB->get_record(
            'aiknowledgecheck_attempts',
            [
                'aiknowledgecheckid' => $knowledgecheck->id,
                'userid' => $userid,
                'status' => 0,
            ]
        );

        // Build attempts label for use inside cards.
        if ($maxattempts > 0) {
            $attemptslabel = $str('attemptsused') . ': ' . $attemptsused . ' / ' . $maxattempts;
        } else {
            $attemptslabel = $str('attemptsused') . ': ' . $attemptsused . ' (' . $str('unlimited') . ')';
        }

        // Show previous attempts table.
        $attempts = $DB->get_records(
            'aiknowledgecheck_attempts',
            [
                'aiknowledgecheckid' => $knowledgecheck->id,
                'userid' => $userid,
                'status' => 1,
            ],
            'id ASC'
        );

        if ($attempts) {
            echo html_writer::start_tag('details', ['class' => 'kc-details mb-3']);
            echo html_writer::tag('summary', $str('review'));

            $table = new html_table();
            $table->head = [
                $str('attempt'),
                $str('score'),
                $str('timeended'),
            ];

            $num = 1;
            foreach ($attempts as $a) {
                $table->data[] = [
                    $num++,
                    $a->correctcount . '/' . $a->totalcount,
                    userdate($a->timeended),
                ];
            }

            echo html_writer::table($table);
            echo html_writer::end_tag('details');
        }

        // Gate variables already computed at top of file.
        ?>
        <div id="kc-app" class="kc-container">
            <?php if (!$canattempt && !$inprogress) : ?>
                <!-- Limit reached -->
                <div class="kc-card">
                    <div class="alert alert-warning">
                        <?php echo $str('attemptslimitreached', $maxattempts); ?>
                    </div>
                </div>
            <?php else : ?>
                <?php if ($hasvideo) : ?>
                <div id="kc-video-section" class="kc-card kc-spaced-bottom">
                    <h4 class="kc-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="kc-icon-heading">
                            <polygon points="23 7 16 12 23 17 23 7"></polygon>
                            <rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect>
                        </svg>
                        <?php echo $str('videogate_watchvideo'); ?>
                    </h4>
                    <div id="kc-video-container" class="kc-video-frame">
                        <div id="kc-yt-player" class="kc-video-frame-inner"></div>
                    </div>
                    <?php if ($videogated) : ?>
                    <div id="kc-video-status" class="kc-gate-status-lg">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="kc-icon-baseline">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                        <span id="kc-video-status-text">
                        <?php
                        if ($videoreq === 'full') {
                            echo $str('videogate_watchfull');
                        } else {
                            echo $str('videogate_watchseconds', $videominsec);
                        }
                        ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($hasaudio) : ?>
                <div id="kc-audio-section" class="kc-card kc-spaced-bottom">
                    <h4 class="kc-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="kc-icon-heading">
                            <path d="M3 18v-6a9 9 0 0 1 18 0v6"></path>
                            <path
                                d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0
                                    2-2v-3a2 2 0 0 0-2-2H3z"></path>
                        </svg>
                        <?php echo $str('audiogate_listenaudio'); ?>
                    </h4>
                    <audio id="kc-audio-player" controls class="kc-audio-player">
                        <source src="<?php echo s($audiourl); ?>">
                    </audio>
                    <?php if ($audiogated) : ?>
                    <div id="kc-audio-status" class="kc-gate-status-lg">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="kc-icon-baseline">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                        <span id="kc-audio-status-text">
                        <?php
                        if ($audioreq === 'full') {
                            echo $str('audiogate_listenfull');
                        } else {
                            echo $str('audiogate_listenseconds', $audiominsec);
                        }
                        ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($hasimage) : ?>
                <div id="kc-image-section" class="kc-card kc-spaced-bottom">
                    <h4 class="kc-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="kc-icon-heading">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                            <circle cx="8.5" cy="8.5" r="1.5"></circle>
                            <polyline points="21 15 16 10 5 21"></polyline>
                        </svg>
                        <?php echo $str('imagegate_viewimage'); ?>
                    </h4>
                    <img src="<?php echo s($imageurlgate); ?>" alt="Activity image" class="kc-activity-image">
                    <?php if ($imagegated && !$isstaff) : ?>
                    <div id="kc-image-status" class="kc-gate-status-centre">
                        <button id="kc-image-acknowledge-btn" class="kc-btn kc-btn-secondary" type="button">
                            <?php echo $str('imagegate_acknowledge'); ?>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Estimated Time Banner -->
                <?php
                    $kcvoiceenabled = !empty($knowledgecheck->voiceoverenabled);
                    $kcsecperq = $kcvoiceenabled ? 120 : 90;
                    $kctotalsec = $questioncount * $kcsecperq;
                    $kcetamin = (int)ceil($kctotalsec / 60);
                if ($kcetamin < 1) {
                    $kcetamin = 1;
                }
                if ($kcetamin < 60) {
                    $kcetastr = '~' . $kcetamin . ' minute' . ($kcetamin > 1 ? 's' : '');
                } else {
                    $kcetahrs = floor($kcetamin / 60);
                    $kcetarem = $kcetamin % 60;
                    $kcetastr = '~' . $kcetahrs . ($kcetahrs == 1 ? ' hr ' : ' hrs ') . $kcetarem . ' min';
                }
                    $kcetadetail = $questioncount . ' question' . ($questioncount != 1 ? 's' : '') .
                        ($kcvoiceenabled ? ' with audio explanations' : '');
                ?>
                <div class="kc-eta-banner"<?php echo $anygated ? ' style="display: none;"' : ''; ?>>
                    <div class="kc-eta-icon-wrap">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div class="kc-eta-body">
                        <span class="kc-eta-label"><?php echo $str('estimated_completion_time'); ?></span>
                        <span class="kc-eta-time"><?php echo $kcetastr; ?></span>
                        <span class="kc-eta-detail"><?php echo $kcetadetail; ?></span>
                    </div>
                </div>

                <!-- Start/Continue Attempt -->
                <!-- FIX-KC-VIDEO-GATE: hidden initially when any gate is active; shown by gate coordinator on unlock -->
                <div id="kc-start-section" class="kc-start-card"<?php echo $anygated ? ' style="display: none;"' : ''; ?>>
                    <div class="kc-start-card-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                    </div>
                    <h3 class="kc-start-card-title"><?php echo format_string($knowledgecheck->name); ?></h3>
                    <div class="kc-start-card-meta">
                        <span class="kc-start-card-questions">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                                <line x1="12" y1="17" x2="12.01" y2="17"></line>
                            </svg>
                            <?php echo $questioncount . ' ' . $str('total_questions'); ?>
                        </span>
                        <span class="kc-attempts-badge">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 4v6h6"></path>
                                <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
                            </svg>
                            <?php echo $attemptslabel; ?>
                        </span>
                    </div>
                    
                    <?php if ($inprogress) : ?>
                        <button id="continue-attempt-btn"
                            class="kc-btn kc-btn-primary kc-btn-lg<?php echo $gatedattrs; ?>
                            data-attemptid="<?php echo $inprogress->id; ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polygon points="5 3 19 12 5 21 5 3"></polygon>
                            </svg>
                            <?php echo $str('continue_attempt'); ?>
                        </button>
                    <?php else : ?>
                        <button id="start-attempt-btn"
                            class="kc-btn kc-btn-primary kc-btn-lg<?php echo $gatedattrs; ?>>
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polygon points="5 3 19 12 5 21 5 3"></polygon>
                            </svg>
                            <?php
                            // Show "Start Quiz" for first attempt, "Retake Quiz" for subsequent attempts.
                            $haspreviousattempts = $DB->record_exists(
                                'aiknowledgecheck_attempts',
                                [
                                    'aiknowledgecheckid' => $knowledgecheck->id,
                                    'userid' => $userid,
                                    'status' => 1,
                                ]
                            );
                            echo get_string($haspreviousattempts ? 'retakequiz' : 'startquiz', 'mod_aiknowledgecheck');
                            ?>
                        </button>
                    <?php endif; ?>
                </div>

                <!-- Quiz Player (Hidden by default) -->
                <div id="kc-quiz-player" class="kc-card" style="display: none;">
                    <div class="kc-quiz-header">
                        <span id="question-counter" class="kc-question-counter" aria-live="polite"></span>
                        <div class="kc-quiz-header-right">
                            <span class="kc-attempts-badge kc-attempts-badge-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 4v6h6"></path>
                                    <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
                                </svg>
                                <?php echo $attemptslabel; ?>
                            </span>
                            <span id="quiz-score" class="kc-quiz-score" aria-live="polite"></span>
                        </div>
                    </div>
                    <div id="question-container" class="kc-question-container">
                        <h4 id="question-text" class="kc-question-text" tabindex="-1"></h4>
                        <div id="options-container" class="kc-options"></div>
                    </div>
                    <!-- A11Y (v1.5.161): see the note on the teacher preview player above. -->
                    <div id="feedback-container" class="kc-feedback" style="display: none;" role="status"
                        aria-live="polite">
                        <div id="feedback-result" class="kc-feedback-result"></div>
                        <p id="feedback-explanation" class="kc-feedback-explanation"></p>
                    </div>
                    <div class="kc-quiz-actions">
                        <button id="check-answer-btn" class="kc-btn kc-btn-primary" disabled>
                            <?php echo $str('check_answer'); ?>
                        </button>
                        <button id="next-question-btn" class="kc-btn kc-btn-primary" style="display: none;">
                            <?php echo $str('next_question'); ?>
                        </button>
                    </div>
                </div>

                <!-- Quiz Results (Hidden by default) -->
                <div id="kc-results" class="kc-card" style="display: none;">
                    <div id="results-icon" class="kc-results-icon">🎉</div>
                    <h3 class="kc-card-title"><?php echo $str('quiz_complete'); ?></h3>
                    <div id="results-score" class="kc-results-score"></div>
                    <p id="results-message" class="kc-results-message"></p>
                    <?php if ($canattempt || $maxattempts == 0) : ?>
                        <button id="retake-btn" class="kc-btn kc-btn-secondary">
                            <?php echo $str('retake_btn'); ?>
                        </button>
                    <?php endif; ?>
                    <a href="<?php echo (new moodle_url('/course/view.php', ['id' => $course->id]))->out(); ?>"
                        class="kc-btn kc-btn-secondary">
                        <?php echo $str('backtocourse'); ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php

        // Initialize JS module for student.
        $PAGE->requires->js_call_amd('mod_aiknowledgecheck/mediagates', 'init', [$gatesconfig]);
        $PAGE->requires->js_call_amd(
            'mod_aiknowledgecheck/knowledgecheck',
            'init',
            [[
                'cmid' => $cm->id,
                'wwwroot' => $CFG->wwwroot,
                'sesskey' => sesskey(),
                'isTeacher' => false,
                'inProgressAttemptId' => $inprogress ? (int)$inprogress->id : null,
                'inProgressAttemptQuestion' => $inprogress ? (int)$inprogress->currentquestion : 0,
                'canAttempt' => $canattempt,
                'maxAttempts' => $maxattempts,
                'attemptsUsed' => $attemptsused,
                'attemptsUsedStr' => $str('attemptsused'),
                'attemptsUnlimitedStr' => $str('unlimited'),
                'retakeQuizStr' => $str('retakequiz'),
                'gradePass' => $gradepass,
                'maxGrade' => $maxgrade,
                'voiceoverEnabled' => isset($knowledgecheck->voiceoverenabled) ? (int)$knowledgecheck->voiceoverenabled : 0,
                'voiceLanguage' => isset($knowledgecheck->voicelanguage) ? $knowledgecheck->voicelanguage : 'en-AU',
                'voiceGender' => isset($knowledgecheck->voicegender) ? $knowledgecheck->voicegender : 'female',
                'voiceStyle' => isset($knowledgecheck->voicestyle) ? $knowledgecheck->voicestyle : 'Zephyr',
                'afterCompletion' => isset($knowledgecheck->aftercompletion) ? $knowledgecheck->aftercompletion : 'restart',
                'showVideoDuringQuiz' => isset($knowledgecheck->showvideoduringquiz)
                    ? (int)$knowledgecheck->showvideoduringquiz
                    : 0,
                'showChapterStamps' => isset($knowledgecheck->showchapterstamps) ? (int)$knowledgecheck->showchapterstamps : 0,
                'hasVideo' => $hasvideo ? 1 : 0,
                'hasImage' => $hasimage ? 1 : 0,
                'surveyMode' => isset($knowledgecheck->surveymode) ? (int)$knowledgecheck->surveymode : 0,
                'surveyScale' => isset($knowledgecheck->surveyscale) ? $knowledgecheck->surveyscale : 'likert5agree',
                'strings' => [
                    'activityLockedNotice' => $str('activity_locked_notice'),
                    'startAgain'           => $str('startAgain'),
                ],
            ]]
        );
    }
}

echo $OUTPUT->footer();
