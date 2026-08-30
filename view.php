<?php
// phpcs:disable moodle.Files.LineLength
// phpcs:disable moodle.Commenting.MissingDocblock.File
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
    echo $OUTPUT->notification(get_string('not_configured', 'mod_aiknowledgecheck'), 'warning');
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
// Pre-rendered button attributes, so the markup below carries one statement per line.
$gatedclass = $takegated ? ' kc-gated-btn' : '';
$gateddisabled = $takegated ? ' disabled' : '';

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
        get_string('attemptsreport', 'mod_aiknowledgecheck'),
        ['class' => 'btn btn-secondary mr-2']
    );
    if ($canoverride) {
        $moreattemptsurl = new moodle_url('/mod/aiknowledgecheck/moreattempts.php', ['id' => $cm->id]);
        echo html_writer::link(
            $moreattemptsurl,
            get_string('moreattempts', 'mod_aiknowledgecheck'),
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
            <svg class="kc-credits-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/>
                <path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/>
                <path d="M12 18V6"/>
            </svg>
            <span id="credits-value">--</span>
            <span class="kc-credits-label"><?php echo get_string('credits_label', 'mod_aiknowledgecheck'); ?></span>
        </div>

        <!-- Main Form -->
        <div id="kc-form-section" class="kc-card">
            <h3 class="kc-card-title"><?php echo get_string('page_heading', 'mod_aiknowledgecheck'); ?></h3>
            <p class="kc-intro"><?php echo get_string('page_intro', 'mod_aiknowledgecheck'); ?></p>

            <form id="kc-form">
                <!-- Topics Input -->
                <div class="kc-form-group">
                    <label for="topics-input" class="kc-label"><?php echo get_string('topics_label', 'mod_aiknowledgecheck'); ?></label>
                    <textarea id="topics-input" class="kc-textarea" rows="6" 
                        placeholder="<?php echo get_string('topics_placeholder', 'mod_aiknowledgecheck'); ?>"></textarea>
                    <small class="kc-help"><?php echo get_string('topics_help', 'mod_aiknowledgecheck'); ?></small>
                </div>

                <!-- Performance Criteria (optional, one per line aligned with topics) -->
                <div class="kc-form-group" id="criteria-input-group">
                    <label for="criteria-input" class="kc-label"><?php echo get_string('criteria_label', 'mod_aiknowledgecheck'); ?></label>
                    <textarea id="criteria-input" class="kc-textarea" rows="4"
                        placeholder="<?php echo get_string('criteria_placeholder', 'mod_aiknowledgecheck'); ?>"></textarea>
                    <small class="kc-help"><?php echo get_string('criteria_help', 'mod_aiknowledgecheck'); ?></small>
                </div>

                <!-- Questions Per Topic -->
                <div class="kc-form-group" id="questions-per-topic-group">
                    <label for="questions-per-topic" class="kc-label"><?php echo get_string('questions_per_topic', 'mod_aiknowledgecheck'); ?></label>
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
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
                        </svg>
                    </div>
                    <div class="kc-survey-notice-body">
                        <strong><?php echo get_string('survey_mode_notice_title', 'mod_aiknowledgecheck'); ?></strong>
                        <span><?php echo get_string('survey_mode_notice_body', 'mod_aiknowledgecheck', htmlspecialchars($surveyscaledisplay)); ?></span>
                    </div>
                </div>
                <?php endif; ?>

                <!-- User Questions Toggle -->
                <div class="kc-context-section">
                    <div class="kc-context-header">
                        <label class="kc-toggle-label">
                            <input type="checkbox" id="user-questions-toggle" class="kc-toggle-checkbox">
                            <span class="kc-toggle-switch"></span>
                            <span class="kc-toggle-text"><?php echo get_string('use_own_questions', 'mod_aiknowledgecheck'); ?></span>
                        </label>
                        <p class="kc-sublabel"><?php echo $issurveymode
                            ? get_string('use_own_questions_help_survey', 'mod_aiknowledgecheck')
                            : get_string('use_own_questions_help', 'mod_aiknowledgecheck'); ?></p>
                    </div>
                    <div id="user-questions-fields" class="kc-context-fields" style="display: none;">
                        <div class="kc-form-group">
                            <label for="user-questions-input" class="kc-label"><?php echo get_string('your_questions', 'mod_aiknowledgecheck'); ?></label>
                            <textarea id="user-questions-input" class="kc-textarea" rows="8" 
                                placeholder="<?php echo $issurveymode
                                    ? get_string('your_questions_placeholder_survey', 'mod_aiknowledgecheck')
                                    : get_string('your_questions_placeholder', 'mod_aiknowledgecheck'); ?>"></textarea>
                            <small class="kc-help"><?php echo $issurveymode
                                ? get_string('your_questions_help_survey', 'mod_aiknowledgecheck', htmlspecialchars($surveyscaledisplay))
                                : get_string('your_questions_help', 'mod_aiknowledgecheck'); ?></small>
                        </div>
                    </div>
                </div>

                <!-- Paste Content Toggle -->
                <div class="kc-context-section">
                    <div class="kc-context-header">
                        <label class="kc-toggle-label">
                            <input type="checkbox" id="paste-content-toggle" class="kc-toggle-checkbox">
                            <span class="kc-toggle-switch"></span>
                            <span class="kc-toggle-text"><?php echo get_string('paste_content_toggle', 'mod_aiknowledgecheck'); ?></span>
                        </label>
                        <p class="kc-sublabel"><?php echo get_string('paste_content_help', 'mod_aiknowledgecheck'); ?></p>
                    </div>
                    <div id="paste-content-fields" class="kc-context-fields" style="display: none;">
                        <div id="text-sources-container"></div>
                        <button type="button" id="add-text-source-btn" class="kc-add-source-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="5" x2="12" y2="19"/>
                                <line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                            <?php echo get_string('add_text_source', 'mod_aiknowledgecheck'); ?>
                        </button>
                    </div>
                </div>

                <!-- Free Text Questions (Survey Mode only — shown via JS when config.surveyMode) -->
                <div class="kc-form-group" id="freetext-questions-group" style="display: none;">
                    <label for="freetext-questions-input" class="kc-label"><?php echo get_string('freetext_questions_label', 'mod_aiknowledgecheck'); ?></label>
                    <textarea id="freetext-questions-input" class="kc-textarea" rows="4"
                        placeholder="What suggestions do you have for improving this training?&#10;Is there anything else you would like to share?"></textarea>
                    <small class="kc-help"><?php echo get_string('freetext_questions_help', 'mod_aiknowledgecheck'); ?></small>
                </div>

                <!-- Workplace Context Toggle -->
                <div class="kc-context-section">
                    <div class="kc-context-header">
                        <label class="kc-toggle-label">
                            <input type="checkbox" id="workplace-context-toggle" class="kc-toggle-checkbox">
                            <span class="kc-toggle-switch"></span>
                            <span class="kc-toggle-text"><?php echo get_string('add_workplace_context', 'mod_aiknowledgecheck'); ?></span>
                        </label>
                        <p class="kc-sublabel"><?php echo get_string('workplace_context_help', 'mod_aiknowledgecheck'); ?></p>
                    </div>
                    <div id="context-fields" class="kc-context-fields" style="display: none;">
                        <div class="kc-form-row">
                            <div class="kc-form-group kc-half">
                                <label for="country-select"><?php echo get_string('country', 'mod_aiknowledgecheck'); ?></label>
                                <select id="country-select" class="kc-select">
                                    <option value=""><?php echo get_string('select_country', 'mod_aiknowledgecheck'); ?></option>
                                    <option value="Australia" selected>Australia</option>
                                    <option value="New Zealand">New Zealand</option>
                                    <option value="United Kingdom">United Kingdom</option>
                                    <option value="United States">United States</option>
                                    <option value="Canada">Canada</option>
                                    <option value="Singapore">Singapore</option>
                                </select>
                            </div>
                            <div class="kc-form-group kc-half">
                                <label for="state-select"><?php echo get_string('state', 'mod_aiknowledgecheck'); ?></label>
                                <select id="state-select" class="kc-select">
                                    <option value=""><?php echo get_string('select_state', 'mod_aiknowledgecheck'); ?></option>
                                    <option value="Western Australia">Western Australia</option>
                                    <option value="Queensland">Queensland</option>
                                    <option value="New South Wales">New South Wales</option>
                                    <option value="Victoria">Victoria</option>
                                    <option value="South Australia">South Australia</option>
                                    <option value="Tasmania">Tasmania</option>
                                    <option value="Northern Territory">Northern Territory</option>
                                    <option value="Australian Capital Territory">ACT</option>
                                </select>
                            </div>
                        </div>
                        <div class="kc-form-row">
                            <div class="kc-form-group kc-half">
                                <label for="industry-select"><?php echo get_string('industry', 'mod_aiknowledgecheck'); ?></label>
                                <select id="industry-select" class="kc-select">
                                    <option value=""><?php echo get_string('select_industry', 'mod_aiknowledgecheck'); ?></option>
                                </select>
                            </div>
                            <div class="kc-form-group kc-half">
                                <label for="industry-sector"><?php echo get_string('industry_sector', 'mod_aiknowledgecheck'); ?></label>
                                <select id="industry-sector" class="kc-select" disabled>
                                    <option value="">Select industry first...</option>
                                </select>
                            </div>
                        </div>
                        <div class="kc-form-row">
                            <div class="kc-form-group">
                                <label><?php echo get_string('job_level', 'mod_aiknowledgecheck'); ?> <small class="kc-label-hint">(select one or more)</small></label>
                                <div class="kc-level-pills" id="kc-job-level-pills">
                                    <button type="button" class="kc-level-pill" data-value="Worker">Worker</button>
                                    <button type="button" class="kc-level-pill" data-value="Supervisor">Supervisor</button>
                                    <button type="button" class="kc-level-pill" data-value="Manager">Manager</button>
                                    <button type="button" class="kc-level-pill" data-value="Executive">Executive</button>
                                </div>
                            </div>
                        </div>
                        <div class="kc-form-row">
                            <div class="kc-form-group">
                                <label for="kc-job-role-input"><?php echo get_string('job_title', 'mod_aiknowledgecheck'); ?> <small class="kc-label-hint">(up to 5 — press Enter to add)</small></label>
                                <div class="kc-role-chips" id="kc-job-role-chips"></div>
                                <input type="text" id="kc-job-role-input" class="kc-input" placeholder="e.g. Site Supervisor, Project Manager...">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Education Settings -->
                <div class="kc-education-section">
                    <div class="kc-form-row">
                        <div class="kc-form-group kc-half">
                            <label for="education-type-select"><?php echo get_string('education_type', 'mod_aiknowledgecheck'); ?></label>
                            <select id="education-type-select" class="kc-select">
                                <option value="vet" selected><?php echo get_string('education_vet', 'mod_aiknowledgecheck'); ?></option>
                                <option value="academic"><?php echo get_string('education_academic', 'mod_aiknowledgecheck'); ?></option>
                                <option value="general"><?php echo get_string('education_general', 'mod_aiknowledgecheck'); ?></option>
                            </select>
                        </div>
                        <div class="kc-form-group kc-half" id="vet-level-field">
                            <label for="vet-level-select"><?php echo get_string('vet_level', 'mod_aiknowledgecheck'); ?></label>
                            <select id="vet-level-select" class="kc-select">
                                <option value=""><?php echo get_string('select_vet_level', 'mod_aiknowledgecheck'); ?></option>
                                <option value="cert1"><?php echo get_string('vet_cert1', 'mod_aiknowledgecheck'); ?></option>
                                <option value="cert2"><?php echo get_string('vet_cert2', 'mod_aiknowledgecheck'); ?></option>
                                <option value="cert3" selected><?php echo get_string('vet_cert3', 'mod_aiknowledgecheck'); ?></option>
                                <option value="cert4"><?php echo get_string('vet_cert4', 'mod_aiknowledgecheck'); ?></option>
                                <option value="diploma"><?php echo get_string('vet_diploma', 'mod_aiknowledgecheck'); ?></option>
                                <option value="adv_diploma"><?php echo get_string('vet_adv_diploma', 'mod_aiknowledgecheck'); ?></option>
                            </select>
                        </div>
                        <div class="kc-form-group kc-half" id="academic-level-field" style="display: none;">
                            <label for="academic-level-select"><?php echo get_string('academic_level', 'mod_aiknowledgecheck'); ?></label>
                            <select id="academic-level-select" class="kc-select">
                                <option value=""><?php echo get_string('select_academic_level', 'mod_aiknowledgecheck'); ?></option>
                                <option value="undergraduate"><?php echo get_string('academic_undergraduate', 'mod_aiknowledgecheck'); ?></option>
                                <option value="postgraduate"><?php echo get_string('academic_postgraduate', 'mod_aiknowledgecheck'); ?></option>
                                <option value="masters"><?php echo get_string('academic_masters', 'mod_aiknowledgecheck'); ?></option>
                                <option value="phd"><?php echo get_string('academic_phd', 'mod_aiknowledgecheck'); ?></option>
                            </select>
                        </div>
                    </div>
                    <!-- Education Info Cards -->
                    <div class="kc-education-info">
                        <div class="kc-info-card kc-info-vet" id="vet-info-card">
                            <div class="kc-info-card-header">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                                <span class="kc-info-card-title"><?php echo get_string('vet_tooltip_title', 'mod_aiknowledgecheck'); ?></span>
                            </div>
                            <p class="kc-info-card-text"><?php echo get_string('vet_tooltip', 'mod_aiknowledgecheck'); ?></p>
                        </div>
                        <div class="kc-info-card kc-info-academic" id="academic-info-card" style="display: none;">
                            <div class="kc-info-card-header">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                                <span class="kc-info-card-title"><?php echo get_string('academic_tooltip_title', 'mod_aiknowledgecheck'); ?></span>
                            </div>
                            <p class="kc-info-card-text"><?php echo get_string('academic_tooltip', 'mod_aiknowledgecheck'); ?></p>
                        </div>
                        <div class="kc-info-card kc-info-general" id="general-info-card" style="display: none;">
                            <div class="kc-info-card-header">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
                                <span class="kc-info-card-title"><?php echo get_string('general_tooltip_title', 'mod_aiknowledgecheck'); ?></span>
                            </div>
                            <p class="kc-info-card-text"><?php echo get_string('general_tooltip', 'mod_aiknowledgecheck'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Extra AI Instructions -->
                <div class="kc-form-group">
                    <label for="extra-instructions" class="kc-label"><?php echo get_string('extra_instructions', 'mod_aiknowledgecheck'); ?></label>
                    <textarea id="extra-instructions" class="kc-textarea" rows="3" 
                        placeholder="<?php echo get_string('extra_instructions_placeholder', 'mod_aiknowledgecheck'); ?>"></textarea>
                    <small class="kc-help"><?php echo get_string('extra_instructions_help', 'mod_aiknowledgecheck'); ?></small>
                </div>

                <!-- Content Language (always visible - controls spelling/grammar of generated questions) -->
                <div class="kc-form-group">
                    <label for="voice-language" class="kc-label"><?php echo get_string('voice_language', 'mod_aiknowledgecheck'); ?></label>
                    <small class="kc-help"><?php echo get_string('language_help', 'mod_aiknowledgecheck'); ?></small>
                    <select id="voice-language" class="kc-select">
                        <!-- English variants -->
                        <optgroup label="English">
                            <option value="en-AU" selected><?php echo get_string('lang_en_au', 'mod_aiknowledgecheck'); ?></option>
                            <option value="en-GB"><?php echo get_string('lang_en_gb', 'mod_aiknowledgecheck'); ?></option>
                            <option value="en-IN"><?php echo get_string('lang_en_in', 'mod_aiknowledgecheck'); ?></option>
                            <option value="en-US"><?php echo get_string('lang_en_us', 'mod_aiknowledgecheck'); ?></option>
                        </optgroup>
                        <!-- Spanish variants -->
                        <optgroup label="Spanish">
                            <option value="es-ES"><?php echo get_string('lang_es_es', 'mod_aiknowledgecheck'); ?></option>
                            <option value="es-US"><?php echo get_string('lang_es_us', 'mod_aiknowledgecheck'); ?></option>
                        </optgroup>
                        <!-- French variants -->
                        <optgroup label="French">
                            <option value="fr-CA"><?php echo get_string('lang_fr_ca', 'mod_aiknowledgecheck'); ?></option>
                            <option value="fr-FR"><?php echo get_string('lang_fr_fr', 'mod_aiknowledgecheck'); ?></option>
                        </optgroup>
                        <!-- German -->
                        <optgroup label="German">
                            <option value="de-DE"><?php echo get_string('lang_de_de', 'mod_aiknowledgecheck'); ?></option>
                        </optgroup>
                        <!-- Portuguese -->
                        <optgroup label="Portuguese">
                            <option value="pt-BR"><?php echo get_string('lang_pt_br', 'mod_aiknowledgecheck'); ?></option>
                        </optgroup>
                        <!-- Dutch variants -->
                        <optgroup label="Dutch">
                            <option value="nl-BE"><?php echo get_string('lang_nl_be', 'mod_aiknowledgecheck'); ?></option>
                            <option value="nl-NL"><?php echo get_string('lang_nl_nl', 'mod_aiknowledgecheck'); ?></option>
                        </optgroup>
                        <!-- Nordic languages -->
                        <optgroup label="Nordic">
                            <option value="da-DK"><?php echo get_string('lang_da_dk', 'mod_aiknowledgecheck'); ?></option>
                            <option value="fi-FI"><?php echo get_string('lang_fi_fi', 'mod_aiknowledgecheck'); ?></option>
                            <option value="nb-NO"><?php echo get_string('lang_nb_no', 'mod_aiknowledgecheck'); ?></option>
                            <option value="sv-SE"><?php echo get_string('lang_sv_se', 'mod_aiknowledgecheck'); ?></option>
                        </optgroup>
                        <!-- Eastern European languages -->
                        <optgroup label="Eastern European">
                            <option value="bg-BG"><?php echo get_string('lang_bg_bg', 'mod_aiknowledgecheck'); ?></option>
                            <option value="cs-CZ"><?php echo get_string('lang_cs_cz', 'mod_aiknowledgecheck'); ?></option>
                            <option value="hr-HR"><?php echo get_string('lang_hr_hr', 'mod_aiknowledgecheck'); ?></option>
                            <option value="hu-HU"><?php echo get_string('lang_hu_hu', 'mod_aiknowledgecheck'); ?></option>
                            <option value="pl-PL"><?php echo get_string('lang_pl_pl', 'mod_aiknowledgecheck'); ?></option>
                            <option value="ro-RO"><?php echo get_string('lang_ro_ro', 'mod_aiknowledgecheck'); ?></option>
                            <option value="ru-RU"><?php echo get_string('lang_ru_ru', 'mod_aiknowledgecheck'); ?></option>
                            <option value="sk-SK"><?php echo get_string('lang_sk_sk', 'mod_aiknowledgecheck'); ?></option>
                            <option value="sl-SI"><?php echo get_string('lang_sl_si', 'mod_aiknowledgecheck'); ?></option>
                            <option value="sr-RS"><?php echo get_string('lang_sr_rs', 'mod_aiknowledgecheck'); ?></option>
                            <option value="uk-UA"><?php echo get_string('lang_uk_ua', 'mod_aiknowledgecheck'); ?></option>
                        </optgroup>
                        <!-- Baltic languages -->
                        <optgroup label="Baltic">
                            <option value="et-EE"><?php echo get_string('lang_et_ee', 'mod_aiknowledgecheck'); ?></option>
                            <option value="lt-LT"><?php echo get_string('lang_lt_lt', 'mod_aiknowledgecheck'); ?></option>
                            <option value="lv-LV"><?php echo get_string('lang_lv_lv', 'mod_aiknowledgecheck'); ?></option>
                        </optgroup>
                        <!-- Southern European languages -->
                        <optgroup label="Southern European">
                            <option value="el-GR"><?php echo get_string('lang_el_gr', 'mod_aiknowledgecheck'); ?></option>
                            <option value="it-IT"><?php echo get_string('lang_it_it', 'mod_aiknowledgecheck'); ?></option>
                        </optgroup>
                        <!-- East Asian languages -->
                        <optgroup label="East Asian">
                            <option value="cmn-CN"><?php echo get_string('lang_cmn_cn', 'mod_aiknowledgecheck'); ?></option>
                            <option value="ja-JP"><?php echo get_string('lang_ja_jp', 'mod_aiknowledgecheck'); ?></option>
                            <option value="ko-KR"><?php echo get_string('lang_ko_kr', 'mod_aiknowledgecheck'); ?></option>
                        </optgroup>
                        <!-- Southeast Asian languages -->
                        <optgroup label="Southeast Asian">
                            <option value="id-ID"><?php echo get_string('lang_id_id', 'mod_aiknowledgecheck'); ?></option>
                            <option value="th-TH"><?php echo get_string('lang_th_th', 'mod_aiknowledgecheck'); ?></option>
                            <option value="vi-VN"><?php echo get_string('lang_vi_vn', 'mod_aiknowledgecheck'); ?></option>
                        </optgroup>
                        <!-- South Asian languages -->
                        <optgroup label="South Asian">
                            <option value="bn-IN"><?php echo get_string('lang_bn_in', 'mod_aiknowledgecheck'); ?></option>
                            <option value="gu-IN"><?php echo get_string('lang_gu_in', 'mod_aiknowledgecheck'); ?></option>
                            <option value="hi-IN"><?php echo get_string('lang_hi_in', 'mod_aiknowledgecheck'); ?></option>
                            <option value="kn-IN"><?php echo get_string('lang_kn_in', 'mod_aiknowledgecheck'); ?></option>
                            <option value="ml-IN"><?php echo get_string('lang_ml_in', 'mod_aiknowledgecheck'); ?></option>
                            <option value="mr-IN"><?php echo get_string('lang_mr_in', 'mod_aiknowledgecheck'); ?></option>
                            <option value="ta-IN"><?php echo get_string('lang_ta_in', 'mod_aiknowledgecheck'); ?></option>
                            <option value="te-IN"><?php echo get_string('lang_te_in', 'mod_aiknowledgecheck'); ?></option>
                            <option value="ur-IN"><?php echo get_string('lang_ur_in', 'mod_aiknowledgecheck'); ?></option>
                        </optgroup>
                        <!-- Middle Eastern languages -->
                        <optgroup label="Middle Eastern">
                            <option value="ar-XA"><?php echo get_string('lang_ar_xa', 'mod_aiknowledgecheck'); ?></option>
                            <option value="he-IL"><?php echo get_string('lang_he_il', 'mod_aiknowledgecheck'); ?></option>
                            <option value="tr-TR"><?php echo get_string('lang_tr_tr', 'mod_aiknowledgecheck'); ?></option>
                        </optgroup>
                        <!-- African languages -->
                        <optgroup label="African">
                            <option value="sw-KE"><?php echo get_string('lang_sw_ke', 'mod_aiknowledgecheck'); ?></option>
                        </optgroup>
                    </select>
                </div>

                <!-- Voiceover Toggle -->
                <div class="kc-form-group">
                    <div class="kc-toggle-row">
                        <label class="kc-toggle-label" for="voiceover-toggle">
                            <input type="checkbox" id="voiceover-toggle" <?php echo (!empty($knowledgecheck->voiceoverenabled)) ? 'checked' : ''; ?>>
                            <span><?php echo get_string('voiceover_enabled', 'mod_aiknowledgecheck'); ?></span>
                        </label>
                        <small class="kc-help"><?php echo get_string('voiceover_enabled_help', 'mod_aiknowledgecheck'); ?></small>
                    </div>
                </div>

                <!-- Voice Settings (only visible when voiceover is enabled) -->
                <div id="voice-settings-section" <?php echo (empty($knowledgecheck->voiceoverenabled)) ? 'style="display:none;"' : ''; ?>>
                    <div class="kc-form-row">
                        <div class="kc-form-group kc-half">
                            <label for="voice-gender"><?php echo get_string('voice_gender', 'mod_aiknowledgecheck'); ?></label>
                            <select id="voice-gender" class="kc-select">
                                <option value="female"><?php echo get_string('voice_female', 'mod_aiknowledgecheck'); ?></option>
                                <option value="male"><?php echo get_string('voice_male', 'mod_aiknowledgecheck'); ?></option>
                            </select>
                        </div>
                        <div class="kc-form-group kc-half">
                            <label for="voice-style"><?php echo get_string('voice_style', 'mod_aiknowledgecheck'); ?></label>
                            <select id="voice-style" class="kc-select">
                                <!-- Populated dynamically by JavaScript based on gender selection -->
                                <option value="Zephyr">Zephyr (energetic, youthful)</option>
                                <option value="Aoede">Aoede (warm, friendly)</option>
                                <option value="Kore">Kore (clear, professional)</option>
                                <option value="Leda">Leda (soft, nurturing)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Credit Cost Banner (styled like Content Creator) -->
                <div id="preview-stats" class="kc-credit-cost-banner" style="display: none;">
                    <div class="kc-credit-cost-row">
                        <div class="kc-credit-cost-info">
                            <span class="kc-credit-cost-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 6v6l4 2"/>
                                </svg>
                            </span>
                            <span id="kc-credit-formula" class="kc-credit-formula" data-testid="text-credit-formula"></span>
                            <span class="kc-credit-cost-label">to generate content</span>
                        </div>
                        <div class="kc-credit-balance-box">
                            <span class="kc-balance-label">Your balance:</span>
                            <span id="kc-balance-amount" class="kc-balance-amount" data-testid="text-credit-balance">--</span>
                            <span class="kc-balance-unit">credits</span>
                            <a href="https://lms-labs.com/pricing" target="_blank" class="kc-buy-credits-link" data-testid="link-buy-credits">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 8v8M8 12h8"/>
                                </svg>
                                Buy more
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Generate Button -->
                <button type="submit" id="generate-btn" class="kc-btn kc-btn-primary" disabled>
                    <?php echo get_string('generate_btn', 'mod_aiknowledgecheck'); ?>
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
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 6v6l4 2"/>
                            </svg>
                        </span>
                        <span id="kc-progress-credit-formula" class="kc-credit-formula" data-testid="text-progress-credit-formula"></span>
                        <span class="kc-credit-cost-label">to generate content</span>
                    </div>
                    <div class="kc-credit-balance-box">
                        <span class="kc-balance-label">Your balance:</span>
                        <span id="kc-progress-balance" class="kc-balance-amount" data-testid="text-progress-balance">--</span>
                        <span class="kc-balance-unit">credits</span>
                        <a href="https://lms-labs.com/pricing" target="_blank" class="kc-buy-credits-link" data-testid="link-progress-buy-credits">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 8v8M8 12h8"/>
                            </svg>
                            Buy more
                        </a>
                    </div>
                </div>
            </div>
            <h3 class="kc-card-title"><?php echo get_string('generating', 'mod_aiknowledgecheck'); ?></h3>
            <div class="kc-progress-bar">
                <div id="progress-fill" class="kc-progress-fill"></div>
            </div>
            <p id="progress-message" class="kc-progress-message"><?php echo get_string('initializing', 'mod_aiknowledgecheck'); ?></p>
        </div>

        <!-- Quiz Ready Section (Hidden by default) -->
        <div id="kc-ready-section" class="kc-card kc-ready-card" style="display: none;">
            <div class="kc-ready-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
            </div>
            <h3 class="kc-ready-title"><?php echo get_string('quiz_ready', 'mod_aiknowledgecheck'); ?></h3>
            <p id="ready-summary" class="kc-ready-summary"></p>
            <div id="kc-teacher-eta"></div>
            <div class="kc-ready-regen-section">
                <div class="kc-form-group">
                    <label for="ready-extra-instructions" class="kc-label">Extra AI Instructions</label>
                    <textarea id="ready-extra-instructions" class="kc-textarea" rows="3" 
                        placeholder="Add or modify instructions for the AI to refine the generated questions..."></textarea>
                    <small class="kc-help">Edit these instructions and click Regenerate to refine your questions. First 3 regenerations are free.</small>
                </div>
                <div class="kc-regen-controls">
                    <button id="ready-regenerate-btn" class="kc-btn kc-btn-secondary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px; vertical-align: middle;">
                            <polyline points="1 4 1 10 7 10"/>
                            <polyline points="23 20 23 14 17 14"/>
                            <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/>
                        </svg>
                        Regenerate Questions
                    </button>
                    <span id="ready-regen-count" class="kc-regen-count"></span>
                </div>
            </div>
            <?php if ($hasaudio) : ?>
            <div id="kc-teacher-audio-gate" style="margin-top: 16px; padding: 14px; border-radius: 6px; border: 1px solid #dee2e6; background: #f8f9fa;">
                <strong style="display: block; margin-bottom: 8px; font-size: 14px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;">
                        <path d="M3 18v-6a9 9 0 0 1 18 0v6"></path>
                        <path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"></path>
                    </svg>
                    <?php echo get_string('audiogate_listenaudio', 'mod_aiknowledgecheck'); ?>
                </strong>
                <audio id="kc-audio-player" controls style="width: 100%; border-radius: 6px; display: block;">
                    <source src="<?php echo s($audiourl); ?>">
                </audio>
                <?php if ($audiogated) : ?>
                <div id="kc-audio-status" style="margin-top: 8px; padding: 8px 12px; border-radius: 6px; background: #fff3cd; border: 1px solid #ffeaa7; font-size: 13px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    <span id="kc-audio-status-text">
                    <?php
                    if ($audioreq === 'full') {
                        echo get_string('audiogate_listenfull', 'mod_aiknowledgecheck');
                    } else {
                        echo get_string('audiogate_listenseconds', 'mod_aiknowledgecheck', $audiominsec);
                    }
                    ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($hasimage) : ?>
            <div id="kc-teacher-image-gate" style="margin-top: 16px; padding: 14px; border-radius: 6px; border: 1px solid #dee2e6; background: #f8f9fa;">
                <strong style="display: block; margin-bottom: 8px; font-size: 14px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                        <polyline points="21 15 16 10 5 21"></polyline>
                    </svg>
                    <?php echo get_string('imagegate_viewimage', 'mod_aiknowledgecheck'); ?>
                </strong>
                <img src="<?php echo s($imageurlgate); ?>" alt="Image gate preview" style="max-width: 100%; max-height: 300px; border-radius: 6px; display: block; object-fit: contain; margin-bottom: 8px;">
                <small style="color: #6c757d; font-size: 12px;">Students must acknowledge this image before the quiz unlocks. Manage the image URL in the activity settings (Image Gate section).</small>
            </div>
            <?php endif; ?>
            <!-- AI Image Generator (teachers only) -->
            <div id="kc-imagegen-panel" style="margin-top: 16px; padding: 14px; border-radius: 6px; border: 1px solid #dee2e6; background: #f8f9fa;">
                <strong style="display: block; margin-bottom: 8px; font-size: 14px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;">
                        <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                    </svg>
                    <?php echo get_string('imagegate_generateimage', 'mod_aiknowledgecheck'); ?>
                    <span style="font-size: 11px; font-weight: normal; color: #6c757d; margin-left: 6px;"><?php echo get_string('imagegate_credits_cost', 'mod_aiknowledgecheck'); ?></span>
                </strong>
                <div style="margin-bottom: 8px; display: flex; gap: 8px; flex-wrap: wrap;">
                    <input type="text" id="kc-imagegen-prompt" placeholder="Describe the image to generate..." style="flex: 1; min-width: 200px; padding: 6px 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px;">
                    <button id="kc-imagegen-btn" type="button" class="kc-btn kc-btn-secondary" style="font-size: 13px; white-space: nowrap;">
                        Generate Image
                    </button>
                </div>
                <div id="kc-imagegen-status" style="font-size: 12px; color: #6c757d; margin-bottom: 8px; display: none;"></div>
                <div id="kc-imagegen-result" style="display: none;">
                    <img id="kc-imagegen-preview" alt="Generated image" style="max-width: 100%; max-height: 300px; border-radius: 6px; display: block; object-fit: contain; margin-bottom: 8px;">
                    <div style="font-size: 12px; color: #6c757d; margin-bottom: 6px;">Copy the URL below and paste it into the Image Gate field in Settings, or click "Set as Gate Image":</div>
                    <textarea id="kc-imagegen-url-output" rows="2" readonly style="width: 100%; font-size: 11px; font-family: monospace; padding: 6px; border: 1px solid #ced4da; border-radius: 4px; word-break: break-all; resize: vertical;"></textarea>
                    <div style="margin-top: 8px; display: flex; gap: 8px; flex-wrap: wrap;">
                        <button id="kc-imagegen-save-gate" type="button" class="kc-btn kc-btn-primary" style="font-size: 12px;">Set as Gate Image</button>
                        <button id="kc-imagegen-copy" type="button" class="kc-btn kc-btn-secondary" style="font-size: 12px;">Copy URL</button>
                    </div>
                    <div id="kc-imagegen-save-status" style="font-size: 12px; color: #6c757d; margin-top: 6px;"></div>
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
                ?>
                <button id="take-quiz-btn" class="kc-btn kc-btn-primary<?php echo $gatedclass; ?>"<?php echo $gateddisabled; ?>>
                    <?php echo get_string('review_questions_btn', 'mod_aiknowledgecheck'); ?>
                </button>
                <button id="add-more-questions-btn" class="kc-btn kc-btn-success">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px; vertical-align: middle;">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="16"/>
                        <line x1="8" y1="12" x2="16" y2="12"/>
                    </svg>
                    Add More Questions
                </button>
                <button id="edit-questions-btn" class="kc-btn kc-btn-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px; vertical-align: middle;">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                    Edit Questions
                </button>
                <button id="download-excel-btn" class="kc-btn kc-btn-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px; vertical-align: middle;">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                        <polyline points="10 9 9 9 8 9"/>
                    </svg>
                    Download Question Mapping
                </button>
            </div>
        </div>
        
        <!-- Edit Questions Section (Hidden by default) -->
        <div id="kc-edit-section" class="kc-card" style="display: none;">
            <div class="kc-edit-header">
                <h3 class="kc-card-title">Edit Questions</h3>
                <div class="kc-edit-actions">
                    <button id="edit-settings-btn" class="kc-btn kc-btn-outline" title="Settings">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px; vertical-align: middle;">
                            <circle cx="12" cy="12" r="3"/>
                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                        </svg>
                        Settings
                    </button>
                    <button id="save-edits-btn" class="kc-btn kc-btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px; vertical-align: middle;">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                            <polyline points="17 21 17 13 7 13 7 21"/>
                            <polyline points="7 3 7 8 15 8"/>
                        </svg>
                        Save Changes
                    </button>
                    <button id="cancel-edits-btn" class="kc-btn kc-btn-secondary">Cancel</button>
                </div>
            </div>
            <p class="kc-edit-info">Edit question text, answer options, correct answer, and explanations.</p>
            <div class="kc-edit-regen-section">
                <div class="kc-form-group">
                    <label for="edit-extra-instructions" class="kc-label">Extra AI Instructions</label>
                    <textarea id="edit-extra-instructions" class="kc-textarea" rows="3" 
                        placeholder="Add or modify instructions for the AI to refine the generated questions..."></textarea>
                    <small class="kc-help">Edit these instructions and click Regenerate to refine your questions. First 3 regenerations are free.</small>
                </div>
                <div class="kc-regen-controls">
                    <button id="edit-regenerate-btn" class="kc-btn kc-btn-secondary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px; vertical-align: middle;">
                            <polyline points="1 4 1 10 7 10"/>
                            <polyline points="23 20 23 14 17 14"/>
                            <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/>
                        </svg>
                        Regenerate Questions
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
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="3"/>
                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                        </svg>
                        Quiz Settings
                    </h3>
                    <button id="close-settings-btn" class="kc-settings-close" title="Close">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>

                <div class="kc-settings-modal-body">
                    <div class="kc-settings-section">
                        <h4 class="kc-settings-section-title">Content Language</h4>
                        <p class="kc-settings-section-desc">Controls the spelling and grammar of generated questions (e.g. colour vs color).</p>
                        <div class="kc-form-group" style="margin-bottom: 0;">
                            <select id="settings-voice-language" class="kc-select">
                                <optgroup label="English">
                                    <option value="en-AU" selected>English (Australia)</option>
                                    <option value="en-GB">English (UK)</option>
                                    <option value="en-IN">English (India)</option>
                                    <option value="en-US">English (US)</option>
                                </optgroup>
                                <optgroup label="Spanish">
                                    <option value="es-ES">Spanish (Spain)</option>
                                    <option value="es-US">Spanish (US)</option>
                                </optgroup>
                                <optgroup label="French">
                                    <option value="fr-CA">French (Canada)</option>
                                    <option value="fr-FR">French (France)</option>
                                </optgroup>
                                <optgroup label="German">
                                    <option value="de-DE">German</option>
                                </optgroup>
                                <optgroup label="Portuguese">
                                    <option value="pt-BR">Portuguese (Brazil)</option>
                                </optgroup>
                                <optgroup label="Dutch">
                                    <option value="nl-BE">Dutch (Belgium)</option>
                                    <option value="nl-NL">Dutch (Netherlands)</option>
                                </optgroup>
                                <optgroup label="Nordic">
                                    <option value="da-DK">Danish</option>
                                    <option value="fi-FI">Finnish</option>
                                    <option value="nb-NO">Norwegian</option>
                                    <option value="sv-SE">Swedish</option>
                                </optgroup>
                                <optgroup label="Eastern European">
                                    <option value="bg-BG">Bulgarian</option>
                                    <option value="cs-CZ">Czech</option>
                                    <option value="hr-HR">Croatian</option>
                                    <option value="hu-HU">Hungarian</option>
                                    <option value="pl-PL">Polish</option>
                                    <option value="ro-RO">Romanian</option>
                                    <option value="ru-RU">Russian</option>
                                    <option value="sk-SK">Slovak</option>
                                    <option value="sl-SI">Slovenian</option>
                                    <option value="sr-RS">Serbian</option>
                                    <option value="uk-UA">Ukrainian</option>
                                </optgroup>
                                <optgroup label="Baltic">
                                    <option value="et-EE">Estonian</option>
                                    <option value="lt-LT">Lithuanian</option>
                                    <option value="lv-LV">Latvian</option>
                                </optgroup>
                                <optgroup label="Southern European">
                                    <option value="el-GR">Greek</option>
                                    <option value="it-IT">Italian</option>
                                </optgroup>
                                <optgroup label="East Asian">
                                    <option value="cmn-CN">Chinese (Mandarin)</option>
                                    <option value="ja-JP">Japanese</option>
                                    <option value="ko-KR">Korean</option>
                                </optgroup>
                                <optgroup label="Southeast Asian">
                                    <option value="id-ID">Indonesian</option>
                                    <option value="th-TH">Thai</option>
                                    <option value="vi-VN">Vietnamese</option>
                                </optgroup>
                                <optgroup label="South Asian">
                                    <option value="bn-IN">Bengali</option>
                                    <option value="gu-IN">Gujarati</option>
                                    <option value="hi-IN">Hindi</option>
                                    <option value="kn-IN">Kannada</option>
                                    <option value="ml-IN">Malayalam</option>
                                    <option value="mr-IN">Marathi</option>
                                    <option value="ta-IN">Tamil</option>
                                    <option value="te-IN">Telugu</option>
                                    <option value="ur-IN">Urdu</option>
                                </optgroup>
                                <optgroup label="Middle Eastern">
                                    <option value="ar-XA">Arabic</option>
                                    <option value="he-IL">Hebrew</option>
                                    <option value="tr-TR">Turkish</option>
                                </optgroup>
                                <optgroup label="African">
                                    <option value="sw-KE">Swahili</option>
                                </optgroup>
                            </select>
                        </div>
                    </div>

                    <div class="kc-settings-divider"></div>

                    <div class="kc-settings-section">
                        <h4 class="kc-settings-section-title">Voiceover</h4>
                        <div class="kc-toggle-row" style="margin-bottom: 12px;">
                            <label class="kc-toggle-label" for="settings-voiceover-toggle">
                                <input type="checkbox" id="settings-voiceover-toggle" <?php echo (!empty($knowledgecheck->voiceoverenabled)) ? 'checked' : ''; ?>>
                                <span>Enable voiceover narration</span>
                            </label>
                            <small class="kc-help">AI-generated voice reads explanations aloud after each answer.</small>
                        </div>
                    </div>

                    <div id="settings-voice-options">
                        <div class="kc-settings-divider"></div>

                        <div class="kc-settings-section">
                            <h4 class="kc-settings-section-title">Voice Settings</h4>
                            <div class="kc-form-row">
                                <div class="kc-form-group kc-half">
                                    <label for="settings-voice-gender">Voice Gender</label>
                                    <select id="settings-voice-gender" class="kc-select">
                                        <option value="female">Female</option>
                                        <option value="male">Male</option>
                                    </select>
                                </div>
                                <div class="kc-form-group kc-half">
                                    <label for="settings-voice-style">Voice Style</label>
                                    <select id="settings-voice-style" class="kc-select">
                                        <option value="Zephyr">Zephyr (energetic, youthful)</option>
                                        <option value="Aoede">Aoede (warm, friendly)</option>
                                        <option value="Kore">Kore (clear, professional)</option>
                                        <option value="Leda">Leda (soft, nurturing)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="kc-settings-modal-footer">
                    <p id="settings-warning-text" class="kc-settings-warning">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <span id="settings-warning-msg">Changing language will regenerate questions and uses credits.</span>
                    </p>
                    <div class="kc-settings-footer-actions">
                        <button id="settings-cancel-btn" class="kc-btn kc-btn-secondary">Cancel</button>
                        <button id="settings-save-btn" class="kc-btn kc-btn-primary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 4px; vertical-align: middle;"><polyline points="23 4 11.5 15.5 6 10"/></svg>
                            Save Settings
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quiz Player (Hidden by default) -->
        <div id="kc-quiz-player" class="kc-card" style="display: none;">
            <div class="kc-quiz-header">
                <span id="question-counter" class="kc-question-counter"></span>
                <span id="quiz-score" class="kc-quiz-score"></span>
            </div>
            <div id="question-container" class="kc-question-container">
                <h4 id="question-text" class="kc-question-text"></h4>
                <div id="options-container" class="kc-options"></div>
            </div>
            <div id="feedback-container" class="kc-feedback" style="display: none;">
                <div id="feedback-result" class="kc-feedback-result"></div>
                <p id="feedback-explanation" class="kc-feedback-explanation"></p>
                <button id="play-audio-btn" class="kc-btn kc-btn-secondary">
                    <?php echo get_string('play_explanation', 'mod_aiknowledgecheck'); ?>
                </button>
            </div>
            <div class="kc-quiz-actions">
                <button id="check-answer-btn" class="kc-btn kc-btn-primary" disabled>
                    <?php echo get_string('check_answer', 'mod_aiknowledgecheck'); ?>
                </button>
                <button id="next-question-btn" class="kc-btn kc-btn-primary" style="display: none;">
                    <?php echo get_string('next_question', 'mod_aiknowledgecheck'); ?>
                </button>
            </div>
        </div>

        <!-- Quiz Results (Hidden by default) -->
        <div id="kc-results" class="kc-card" style="display: none;">
            <div id="results-icon" class="kc-results-icon">🎉</div>
            <h3 class="kc-card-title"><?php echo get_string('quiz_complete', 'mod_aiknowledgecheck'); ?></h3>
            <div id="results-score" class="kc-results-score"></div>
            <p id="results-message" class="kc-results-message"></p>
            <button id="retake-btn" class="kc-btn kc-btn-secondary">
                <?php echo get_string('retake_btn', 'mod_aiknowledgecheck'); ?>
            </button>
        </div>
    </div>
    <?php if ($hasaudio && $audiogated) : ?>
    <script>
    (function () {
        var audioEl = document.getElementById('kc-audio-player');
        var requirement = '<?php echo $audioreq; ?>';
        var minSeconds = <?php echo $audiominsec; ?>;
        var listenedSeconds = 0;
        var listenTimer = null;
        var unlocked = false;

        function unlockAudio() {
            if (unlocked) return;
            unlocked = true;
            clearInterval(listenTimer);
            listenTimer = null;
            var statusEl = document.getElementById('kc-audio-status');
            if (statusEl) {
                statusEl.style.background = '#d4edda';
                statusEl.style.borderColor = '#c3e6cb';
                document.getElementById('kc-audio-status-text').textContent = '<?php echo addslashes(get_string('audiogate_unlocked', 'mod_aiknowledgecheck')); ?>';
            }
            if (window.kcGate) { window.kcGate.unlock('audio'); }
        }

        if (audioEl) {
            audioEl.addEventListener('play', function () {
                if (unlocked) return;
                if (listenTimer) return;
                listenTimer = setInterval(function () {
                    if (unlocked) { clearInterval(listenTimer); return; }
                    listenedSeconds++;
                    if (requirement === 'seconds' && listenedSeconds >= minSeconds) {
                        unlockAudio();
                    }
                }, 1000);
            });
            audioEl.addEventListener('pause', function () {
                clearInterval(listenTimer);
                listenTimer = null;
            });
            audioEl.addEventListener('ended', function () {
                clearInterval(listenTimer);
                listenTimer = null;
                if (requirement === 'full') {
                    unlockAudio();
                }
            });
        }
    })();
    </script>
    <?php endif; ?>
    <?php

    // Check for any in-progress student attempts (for edit warning).
    $inprogresscount = $DB->count_records(
        'aiknowledgecheck_attempts',
        [
            'aiknowledgecheckid' => $knowledgecheck->id,
            'status' => 0, // In progress.
        ]
    );

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
    <script>
    // AI Image Generator — teacher-only inline script (ADD-KC-IMAGEGATE v1.5.115).
    (function () {
        var genBtn = document.getElementById('kc-imagegen-btn');
        var promptInput = document.getElementById('kc-imagegen-prompt');
        var statusEl = document.getElementById('kc-imagegen-status');
        var resultEl = document.getElementById('kc-imagegen-result');
        var previewEl = document.getElementById('kc-imagegen-preview');
        var urlOutput = document.getElementById('kc-imagegen-url-output');
        var saveGateBtn = document.getElementById('kc-imagegen-save-gate');
        var copyBtn = document.getElementById('kc-imagegen-copy');
        var saveStatusEl = document.getElementById('kc-imagegen-save-status');
        var lastGeneratedUrl = '';

        if (genBtn) {
            genBtn.addEventListener('click', function () {
                var prompt = promptInput ? promptInput.value.trim() : '';
                if (!prompt) {
                    alert('Please describe the image you want to generate.');
                    return;
                }
                genBtn.disabled = true;
                genBtn.textContent = '<?php echo addslashes(get_string('imagegate_generating', 'mod_aiknowledgecheck')); ?>';
                if (statusEl) { statusEl.style.display = 'block'; statusEl.textContent = '<?php echo addslashes(get_string('imagegate_generating', 'mod_aiknowledgecheck')); ?>'; statusEl.style.color = '#6c757d'; }
                if (resultEl) resultEl.style.display = 'none';

                // MIGRATE-EXTERNAL-SERVICES (v1.5.152): generateimage now runs through the
                // declared mod_aiknowledgecheck_generate_image service instead of a raw
                // XMLHttpRequest against ajax.php.
                require(['core/ajax'], function (Ajax) {
                    Ajax.call([{
                        methodname: 'mod_aiknowledgecheck_generate_image',
                        args: { cmid: <?php echo (int)$cm->id; ?>, prompt: prompt }
                    }])[0].done(function (resp) {
                        genBtn.disabled = false;
                        genBtn.textContent = 'Generate Image';
                        if (resp.ok && resp.imageDataUrl) {
                            lastGeneratedUrl = resp.imageDataUrl;
                            if (previewEl) previewEl.src = resp.imageDataUrl;
                            if (urlOutput) urlOutput.value = resp.imageDataUrl;
                            if (resultEl) resultEl.style.display = 'block';
                            if (statusEl) { statusEl.textContent = '<?php echo addslashes(get_string('imagegate_generated', 'mod_aiknowledgecheck')); ?>'; statusEl.style.color = '#28a745'; }
                        } else {
                            if (statusEl) { statusEl.textContent = (resp.error || '<?php echo addslashes(get_string('imagegate_error', 'mod_aiknowledgecheck')); ?>'); statusEl.style.color = '#dc3545'; }
                        }
                    }).fail(function () {
                        genBtn.disabled = false;
                        genBtn.textContent = 'Generate Image';
                        if (statusEl) { statusEl.textContent = '<?php echo addslashes(get_string('imagegate_error', 'mod_aiknowledgecheck')); ?>'; statusEl.style.color = '#dc3545'; }
                    });
                });
            });
        }

        if (saveGateBtn) {
            saveGateBtn.addEventListener('click', function () {
                if (!lastGeneratedUrl) return;
                saveGateBtn.disabled = true;
                if (saveStatusEl) { saveStatusEl.textContent = 'Saving...'; saveStatusEl.style.color = '#6c757d'; }
                // MIGRATE-EXTERNAL-SERVICES (v1.5.152): saveimageurl now runs through the
                // declared mod_aiknowledgecheck_save_image_url service.
                require(['core/ajax'], function (Ajax) {
                    Ajax.call([{
                        methodname: 'mod_aiknowledgecheck_save_image_url',
                        args: { cmid: <?php echo (int)$cm->id; ?>, imageurl: lastGeneratedUrl }
                    }])[0].done(function (resp2) {
                        saveGateBtn.disabled = false;
                        if (resp2.ok) {
                            if (saveStatusEl) { saveStatusEl.textContent = 'Saved! Refresh the page to see the image gate.'; saveStatusEl.style.color = '#28a745'; }
                        } else {
                            if (saveStatusEl) { saveStatusEl.textContent = 'Save failed: ' + (resp2.error || 'Unknown error'); saveStatusEl.style.color = '#dc3545'; }
                        }
                    }).fail(function () {
                        saveGateBtn.disabled = false;
                        if (saveStatusEl) { saveStatusEl.textContent = 'Save failed.'; saveStatusEl.style.color = '#dc3545'; }
                    });
                });
            });
        }

        if (copyBtn) {
            copyBtn.addEventListener('click', function () {
                if (urlOutput) {
                    urlOutput.select();
                    document.execCommand('copy');
                    copyBtn.textContent = 'Copied!';
                    setTimeout(function () { copyBtn.textContent = 'Copy URL'; }, 2000);
                }
            });
        }
    })();
    </script>
    <?php
} else {
    // Student view.
    if ($questioncount == 0) {
        // No questions yet.
        echo html_writer::div(get_string('students_view_message', 'mod_aiknowledgecheck'), 'alert alert-info');
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
            $attemptslabel = get_string('attemptsused', 'mod_aiknowledgecheck') . ': ' . $attemptsused . ' / ' . $maxattempts;
        } else {
            $attemptslabel = get_string('attemptsused', 'mod_aiknowledgecheck') . ': ' . $attemptsused . ' (' . get_string('unlimited', 'mod_aiknowledgecheck') . ')';
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
            echo html_writer::tag('summary', get_string('review', 'mod_aiknowledgecheck'));

            $table = new html_table();
            $table->head = [
                get_string('attempt', 'mod_aiknowledgecheck'),
                get_string('score', 'mod_aiknowledgecheck'),
                get_string('timeended', 'mod_aiknowledgecheck'),
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
                        <?php echo get_string('attemptslimitreached', 'mod_aiknowledgecheck', $maxattempts); ?>
                    </div>
                </div>
            <?php else : ?>
                <?php if ($hasvideo) : ?>
                <div id="kc-video-section" class="kc-card" style="margin-bottom: 16px;">
                    <h4 style="margin: 0 0 12px 0; font-weight: 600;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -3px; margin-right: 6px;">
                            <polygon points="23 7 16 12 23 17 23 7"></polygon>
                            <rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect>
                        </svg>
                        <?php echo get_string('videogate_watchvideo', 'mod_aiknowledgecheck'); ?>
                    </h4>
                    <div id="kc-video-container" style="position: relative; width: 100%; padding-bottom: 56.25%; height: 0; overflow: hidden; border-radius: 8px; background: #000;">
                        <div id="kc-yt-player" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;"></div>
                    </div>
                    <?php if ($videogated) : ?>
                    <div id="kc-video-status" style="margin-top: 12px; padding: 10px 14px; border-radius: 6px; background: #fff3cd; border: 1px solid #ffeaa7; font-size: 14px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                        <span id="kc-video-status-text">
                        <?php
                        if ($videoreq === 'full') {
                            echo get_string('videogate_watchfull', 'mod_aiknowledgecheck');
                        } else {
                            echo get_string('videogate_watchseconds', 'mod_aiknowledgecheck', $videominsec);
                        }
                        ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($hasaudio) : ?>
                <div id="kc-audio-section" class="kc-card" style="margin-bottom: 16px;">
                    <h4 style="margin: 0 0 12px 0; font-weight: 600;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -3px; margin-right: 6px;">
                            <path d="M3 18v-6a9 9 0 0 1 18 0v6"></path>
                            <path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"></path>
                        </svg>
                        <?php echo get_string('audiogate_listenaudio', 'mod_aiknowledgecheck'); ?>
                    </h4>
                    <audio id="kc-audio-player" controls style="width: 100%; border-radius: 6px; display: block;">
                        <source src="<?php echo s($audiourl); ?>">
                    </audio>
                    <?php if ($audiogated) : ?>
                    <div id="kc-audio-status" style="margin-top: 12px; padding: 10px 14px; border-radius: 6px; background: #fff3cd; border: 1px solid #ffeaa7; font-size: 14px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                        <span id="kc-audio-status-text">
                        <?php
                        if ($audioreq === 'full') {
                            echo get_string('audiogate_listenfull', 'mod_aiknowledgecheck');
                        } else {
                            echo get_string('audiogate_listenseconds', 'mod_aiknowledgecheck', $audiominsec);
                        }
                        ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($hasimage) : ?>
                <div id="kc-image-section" class="kc-card" style="margin-bottom: 16px;">
                    <h4 style="margin: 0 0 12px 0; font-weight: 600;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -3px; margin-right: 6px;">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                            <circle cx="8.5" cy="8.5" r="1.5"></circle>
                            <polyline points="21 15 16 10 5 21"></polyline>
                        </svg>
                        <?php echo get_string('imagegate_viewimage', 'mod_aiknowledgecheck'); ?>
                    </h4>
                    <img src="<?php echo s($imageurlgate); ?>" alt="Activity image" style="max-width: 100%; border-radius: 8px; display: block; margin: 0 auto 12px; max-height: 500px; object-fit: contain;">
                    <?php if ($imagegated && !$isstaff) : ?>
                    <div id="kc-image-status" style="margin-top: 8px; padding: 10px 14px; border-radius: 6px; background: #fff3cd; border: 1px solid #ffeaa7; font-size: 14px; text-align: center;">
                        <button id="kc-image-acknowledge-btn" class="kc-btn kc-btn-secondary" type="button">
                            <?php echo get_string('imagegate_acknowledge', 'mod_aiknowledgecheck'); ?>
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
                    $kcetadetail = $questioncount . ' question' . ($questioncount != 1 ? 's' : '') . ($kcvoiceenabled ? ' with audio explanations' : '');
                ?>
                <div class="kc-eta-banner"<?php echo $anygated ? ' style="display:none;"' : ''; ?>>
                    <div class="kc-eta-icon-wrap">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div class="kc-eta-body">
                        <span class="kc-eta-label">Estimated completion time</span>
                        <span class="kc-eta-time"><?php echo $kcetastr; ?></span>
                        <span class="kc-eta-detail"><?php echo $kcetadetail; ?></span>
                    </div>
                </div>

                <!-- Start/Continue Attempt -->
                <!-- FIX-KC-VIDEO-GATE: hidden initially when any gate is active; shown by gate coordinator on unlock -->
                <div id="kc-start-section" class="kc-start-card"<?php echo $anygated ? ' style="display:none;"' : ''; ?>>
                    <div class="kc-start-card-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                    </div>
                    <h3 class="kc-start-card-title"><?php echo format_string($knowledgecheck->name); ?></h3>
                    <div class="kc-start-card-meta">
                        <span class="kc-start-card-questions">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                                <line x1="12" y1="17" x2="12.01" y2="17"></line>
                            </svg>
                            <?php echo $questioncount . ' ' . get_string('total_questions', 'mod_aiknowledgecheck'); ?>
                        </span>
                        <span class="kc-attempts-badge">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 4v6h6"></path>
                                <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
                            </svg>
                            <?php echo $attemptslabel; ?>
                        </span>
                    </div>
                    
                    <?php if ($inprogress) : ?>
                        <button id="continue-attempt-btn" class="kc-btn kc-btn-primary kc-btn-lg<?php echo $gatedclass; ?>"<?php echo $gateddisabled; ?> data-attemptid="<?php echo $inprogress->id; ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polygon points="5 3 19 12 5 21 5 3"></polygon>
                            </svg>
                            Continue Attempt
                        </button>
                    <?php else : ?>
                        <button id="start-attempt-btn" class="kc-btn kc-btn-primary kc-btn-lg<?php echo $gatedclass; ?>"<?php echo $gateddisabled; ?>>
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
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
                        <span id="question-counter" class="kc-question-counter"></span>
                        <div class="kc-quiz-header-right">
                            <span class="kc-attempts-badge kc-attempts-badge-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 4v6h6"></path>
                                    <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
                                </svg>
                                <?php echo $attemptslabel; ?>
                            </span>
                            <span id="quiz-score" class="kc-quiz-score"></span>
                        </div>
                    </div>
                    <div id="question-container" class="kc-question-container">
                        <h4 id="question-text" class="kc-question-text"></h4>
                        <div id="options-container" class="kc-options"></div>
                    </div>
                    <div id="feedback-container" class="kc-feedback" style="display: none;">
                        <div id="feedback-result" class="kc-feedback-result"></div>
                        <p id="feedback-explanation" class="kc-feedback-explanation"></p>
                    </div>
                    <div class="kc-quiz-actions">
                        <button id="check-answer-btn" class="kc-btn kc-btn-primary" disabled>
                            <?php echo get_string('check_answer', 'mod_aiknowledgecheck'); ?>
                        </button>
                        <button id="next-question-btn" class="kc-btn kc-btn-primary" style="display: none;">
                            <?php echo get_string('next_question', 'mod_aiknowledgecheck'); ?>
                        </button>
                    </div>
                </div>

                <!-- Quiz Results (Hidden by default) -->
                <div id="kc-results" class="kc-card" style="display: none;">
                    <div id="results-icon" class="kc-results-icon">🎉</div>
                    <h3 class="kc-card-title"><?php echo get_string('quiz_complete', 'mod_aiknowledgecheck'); ?></h3>
                    <div id="results-score" class="kc-results-score"></div>
                    <p id="results-message" class="kc-results-message"></p>
                    <?php if ($canattempt || $maxattempts == 0) : ?>
                        <button id="retake-btn" class="kc-btn kc-btn-secondary">
                            <?php echo get_string('retake_btn', 'mod_aiknowledgecheck'); ?>
                        </button>
                    <?php endif; ?>
                    <a href="<?php echo (new moodle_url('/course/view.php', ['id' => $course->id]))->out(); ?>" class="kc-btn kc-btn-secondary">
                        <?php echo get_string('backtocourse', 'mod_aiknowledgecheck'); ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($anygated) : ?>
        <script>
        // Gate coordinator: all active gates must unlock before the start section is revealed.
        // FIX-KC-VIDEO-GATE: start section and eta banner are hidden on load (see PHP above).
        // When all locks clear they are shown here. reset() re-hides them for retake.
        window.kcGate = (function () {
            var locks = {};
            var originals = {};
            <?php // FIX-KC-NONEDITING-TEACHER (v1.5.137): course staff are exempt from all three
            // Media locks, not just the image one. The image gate already carried an exemption
            // and video and audio never did, so a non-editing teacher who reaches this branch
            // would have been freed from one gate and still held by the other two. The status
            // banners and the watcher scripts are untouched; with no lock registered there is
            // simply nothing for them to hold. ?>
            <?php if ($videogated && !$isstaff) :
?> locks['video'] = true; originals['video'] = true; <?php
            endif; ?>
            <?php if ($audiogated && !$isstaff) :
?> locks['audio'] = true; originals['audio'] = true; <?php
            endif; ?>
            <?php if ($imagegated && !$isstaff) :
?> locks['image'] = true; originals['image'] = true; <?php
            endif; ?>

            function showStart() {
                var s = document.getElementById('kc-start-section');
                if (s) s.style.display = '';
                var e = document.querySelector('.kc-eta-banner');
                if (e) e.style.display = '';
                // FIX-KC-VIDEO-SIMULTANEOUS (v1.5.62): hide media sections once all
                // gates unlock so video/audio and quiz start are never shown together.
                // v1.5.63 FIX-KC-SHOWVIDEO: only hide video section if showVideoDuringQuiz is off,
                // so the video remains visible alongside the quiz when the teacher enabled that option.
                <?php if (empty($knowledgecheck->showvideoduringquiz)) : ?>
                var v = document.getElementById('kc-video-section');
                if (v) v.style.display = 'none';
                <?php endif; ?>
                var a = document.getElementById('kc-audio-section');
                if (a) a.style.display = 'none';
                // ADD-KC-IMAGEGATE: hide image gate section once all gates unlock.
                var img = document.getElementById('kc-image-section');
                if (img) img.style.display = 'none';
            }

            return {
                unlock: function (name) {
                    delete locks[name];
                    if (Object.keys(locks).length === 0) {
                        var btns = document.querySelectorAll('.kc-gated-btn');
                        for (var i = 0; i < btns.length; i++) {
                            btns[i].disabled = false;
                            btns[i].classList.remove('kc-gated-btn');
                        }
                        showStart();
                    }
                },
                // Re-lock all gates and re-hide start section (used on retake).
                reset: function () {
                    for (var k in originals) { locks[k] = true; }
                    var s = document.getElementById('kc-start-section');
                    if (s) s.style.display = 'none';
                    var e = document.querySelector('.kc-eta-banner');
                    if (e) e.style.display = 'none';
                    // FIX-KC-VIDEO-SIMULTANEOUS (v1.5.62): re-show media sections on
                    // retake so the student must watch the video again before the quiz.
                    var v = document.getElementById('kc-video-section');
                    if (v) v.style.display = '';
                    var a = document.getElementById('kc-audio-section');
                    if (a) a.style.display = '';
                    var btn1 = document.getElementById('start-attempt-btn');
                    if (btn1) {
                        btn1.disabled = true;
                        btn1.classList.add('kc-gated-btn');
                        // FIX-KC-LOADING-RETAKE (v1.5.66): the button text was left as
                        // 'Loading...' by the previous handleStartAttempt() call.  When the
                        // gate re-locks here and then unlocks again after the student
                        // re-watches the video, the button would re-appear still saying
                        // 'Loading...' — making it look frozen.  Reset the text now so
                        // the student sees 'Retake Quiz' (or the locale equivalent) once
                        // the gate unlocks.
                        btn1.textContent = '<?php echo get_string("retakequiz", "mod_aiknowledgecheck"); ?>';
                    }
                    var btn2 = document.getElementById('continue-attempt-btn');
                    if (btn2) { btn2.disabled = true; btn2.classList.add('kc-gated-btn'); }
                    // ADD-KC-IMAGEGATE: re-show image gate section on retake.
                    var img2 = document.getElementById('kc-image-section');
                    if (img2) img2.style.display = '';
                    if (window.kcVideoGate) { window.kcVideoGate.resetLock(); }
                    if (window.kcAudioGate) { window.kcAudioGate.resetLock(); }
                    if (window.kcImageGate) { window.kcImageGate.resetLock(); }
                },
                hasLocks: function () {
                    return Object.keys(originals).length > 0;
                }
            };
        })();
        </script>
        <?php endif; ?>
        <?php if ($hasvideo) : ?>
        <script>
        (function () {
            var videoId = '<?php echo $videoid; ?>';
            var requirement = '<?php echo $videoreq; ?>';
            var minSeconds = <?php echo $videominsec; ?>;
            var gated = <?php echo $videogated ? 'true' : 'false'; ?>;

            if (!gated) {
                var iframe = document.createElement('iframe');
                iframe.src = 'https://www.youtube.com/embed/' + videoId + '?rel=0';
                iframe.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;border:0;';
                iframe.setAttribute('allowfullscreen', '');
                iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
                document.getElementById('kc-yt-player').appendChild(iframe);
                return;
            }

            var watchedSeconds = 0;
            var player = null;
            var watchTimer = null;
            var unlocked = false;

            function unlockButtons() {
                if (unlocked) return;
                unlocked = true;
                var statusEl = document.getElementById('kc-video-status');
                if (statusEl) {
                    statusEl.style.background = '#d4edda';
                    statusEl.style.borderColor = '#c3e6cb';
                    document.getElementById('kc-video-status-text').textContent = '<?php echo addslashes(get_string('videogate_unlocked', 'mod_aiknowledgecheck')); ?>';
                }
                window.kcGate.unlock('video');
            }

            function startTracking() {
                if (watchTimer) return;
                watchTimer = setInterval(function () {
                    if (unlocked) { clearInterval(watchTimer); return; }
                    watchedSeconds++;
                    if (requirement === 'seconds' && watchedSeconds >= minSeconds) {
                        unlockButtons();
                        clearInterval(watchTimer);
                    }
                }, 1000);
            }

            function stopTracking() {
                if (watchTimer) { clearInterval(watchTimer); watchTimer = null; }
            }

            // FIX-KC-VIDEO-GATE: expose reset function so retakeQuiz() can re-lock the gate.
            var kcOriginalVideoMsg = '<?php
            if ($videoreq === 'full') {
                echo addslashes(get_string('videogate_watchfull', 'mod_aiknowledgecheck'));
            } else {
                echo addslashes(get_string('videogate_watchseconds', 'mod_aiknowledgecheck', $videominsec));
            }
            ?>';
            window.kcVideoGate = {
                resetLock: function () {
                    unlocked = false;
                    watchedSeconds = 0;
                    maxWatchedTime = 0; // FIX-KC-SEEK-BLOCK: reset progress so seek guard starts fresh
                    stopTracking();
                    stopSeekBlocking();
                    var statusEl = document.getElementById('kc-video-status');
                    if (statusEl) {
                        statusEl.style.background = '#fff3cd';
                        statusEl.style.borderColor = '#ffeaa7';
                        var statusText = document.getElementById('kc-video-status-text');
                        if (statusText) { statusText.textContent = kcOriginalVideoMsg; }
                    }
                    if (player && player.seekTo) { player.seekTo(0); player.stopVideo(); }
                }
            };

            // FIX-KC-SEEK-BLOCK v1.5.55: Prevent seek-forward so students can't skip ahead.
            // maxWatchedTime tracks the furthest position actually played.
            // seekBlockTimer polls every 500 ms; if player jumps ahead of maxWatchedTime
            // by more than 1.5 s it seeks back — effectively making the video non-skippable.
            var maxWatchedTime = 0;
            var seekBlockTimer = null;

            function startSeekBlocking() {
                // Release v1.5.60 FIX-SEEK-BLOCK: only block seeking when the requirement is 'full watch'.
                // When requirement is 'seconds', students may freely seek after the timer unlocks.
                if (seekBlockTimer || unlocked || requirement !== 'full') return;
                seekBlockTimer = setInterval(function () {
                    if (unlocked || !player || !player.getCurrentTime) return;
                    var current = player.getCurrentTime();
                    if (current > maxWatchedTime + 1.5) {
                        // Student tried to seek forward — push them back.
                        player.seekTo(maxWatchedTime, true);
                    } else if (current > maxWatchedTime) {
                        maxWatchedTime = current;
                    }
                }, 500);
            }

            function stopSeekBlocking() {
                if (seekBlockTimer) { clearInterval(seekBlockTimer); seekBlockTimer = null; }
                // FIX-KC-SEEK-BYPASS (v1.5.72): Do NOT call getCurrentTime() here.
                // stopSeekBlocking() is called on both PAUSED and BUFFERING states.
                // YouTube fires BUFFERING when the student seeks, so calling getCurrentTime()
                // at BUFFERING would record the seek-target as maxWatchedTime — letting the
                // student seek forward to near the end, triggering ENDED, and bypassing the
                // full-watch requirement. The seekBlockTimer interval (500 ms) is precise
                // enough; the 5-second grace window in the ENDED check covers any timing gap.
            }

            window.onYouTubeIframeAPIReady = function () {
                player = new YT.Player('kc-yt-player', {
                    // NOTE: also stored on window.kcYtPlayer so AMD modules can access it.
                    videoId: videoId,
                    playerVars: { rel: 0, modestbranding: 1 },
                    events: {
                        onReady: function () {
                            // Expose player so AMD modules (knowledgecheck.js) can seek to timestamps.
                            window.kcYtPlayer = player;
                        },
                        onStateChange: function (e) {
                            if (e.data === YT.PlayerState.PLAYING) {
                                startTracking();
                                startSeekBlocking();
                            } else if (e.data === YT.PlayerState.PAUSED || e.data === YT.PlayerState.BUFFERING) {
                                stopTracking();
                                stopSeekBlocking();
                            } else if (e.data === YT.PlayerState.ENDED) {
                                stopTracking();
                                stopSeekBlocking();
                                if (requirement === 'full') {
                                    // Only unlock if student actually watched nearly all the video.
                                    // getDuration() returns 0 until metadata loads, so fall back to
                                    // maxWatchedTime check to guard against seek-to-end exploits.
                                    var duration = player.getDuration ? player.getDuration() : 0;
                                    var threshold = duration > 10 ? duration - 5 : duration;
                                    if (maxWatchedTime >= threshold) {
                                        unlockButtons();
                                    } else {
                                        // Student skipped to the end — seek back and don't unlock.
                                        player.seekTo(maxWatchedTime, true);
                                        player.playVideo();
                                    }
                                }
                            }
                        }
                    }
                });
            };

            var tag = document.createElement('script');
            tag.src = 'https://www.youtube.com/iframe_api';
            document.head.appendChild(tag);
        })();
        </script>
        <?php endif; ?>
        <?php if ($hasaudio && $audiogated) : ?>
        <script>
        (function () {
            var audioEl = document.getElementById('kc-audio-player');
            var requirement = '<?php echo $audioreq; ?>';
            var minSeconds = <?php echo $audiominsec; ?>;
            var listenedSeconds = 0;
            var listenTimer = null;
            var unlocked = false;

            function unlockAudio() {
                if (unlocked) return;
                unlocked = true;
                clearInterval(listenTimer);
                listenTimer = null;
                var statusEl = document.getElementById('kc-audio-status');
                if (statusEl) {
                    statusEl.style.background = '#d4edda';
                    statusEl.style.borderColor = '#c3e6cb';
                    document.getElementById('kc-audio-status-text').textContent = '<?php echo addslashes(get_string('audiogate_unlocked', 'mod_aiknowledgecheck')); ?>';
                }
                window.kcGate.unlock('audio');
            }

            if (audioEl) {
                audioEl.addEventListener('play', function () {
                    if (unlocked) return;
                    if (listenTimer) return;
                    listenTimer = setInterval(function () {
                        if (unlocked) { clearInterval(listenTimer); return; }
                        listenedSeconds++;
                        if (requirement === 'seconds' && listenedSeconds >= minSeconds) {
                            unlockAudio();
                        }
                    }, 1000);
                });

                audioEl.addEventListener('pause', function () {
                    clearInterval(listenTimer);
                    listenTimer = null;
                });

                audioEl.addEventListener('ended', function () {
                    clearInterval(listenTimer);
                    listenTimer = null;
                    if (requirement === 'full') {
                        unlockAudio();
                    }
                });
            }

            // FIX-KC-VIDEO-GATE: expose reset function for retake gate reset.
            var kcOriginalAudioMsg = '<?php
            if ($audioreq === 'full') {
                echo addslashes(get_string('audiogate_listenfull', 'mod_aiknowledgecheck'));
            } else {
                echo addslashes(get_string('audiogate_listenseconds', 'mod_aiknowledgecheck', $audiominsec));
            }
            ?>';
            window.kcAudioGate = {
                resetLock: function () {
                    unlocked = false;
                    listenedSeconds = 0;
                    if (listenTimer) { clearInterval(listenTimer); listenTimer = null; }
                    var statusEl = document.getElementById('kc-audio-status');
                    if (statusEl) {
                        statusEl.style.background = '#fff3cd';
                        statusEl.style.borderColor = '#ffeaa7';
                        var statusText = document.getElementById('kc-audio-status-text');
                        if (statusText) { statusText.textContent = kcOriginalAudioMsg; }
                    }
                    if (audioEl) { audioEl.pause(); audioEl.currentTime = 0; }
                }
            };
        })();
        </script>
        <?php endif; ?>
        <?php if ($hasimage && $imagegated && !$isstaff) : ?>
        <script>
        // ADD-KC-IMAGEGATE v1.5.115 — Image acknowledgment gate.
        (function () {
            var unlocked = false;
            function doUnlock() {
                if (unlocked) return;
                unlocked = true;
                var statusEl = document.getElementById('kc-image-status');
                if (statusEl) {
                    statusEl.style.background = '#d4edda';
                    statusEl.style.borderColor = '#c3e6cb';
                    statusEl.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#28a745" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;margin-right:4px;"><polyline points="20 6 9 17 4 12"></polyline></svg> <?php echo addslashes(get_string('imagegate_unlocked', 'mod_aiknowledgecheck')); ?>';
                }
                if (window.kcGate) { window.kcGate.unlock('image'); }
            }
            function bindAck() {
                var btn = document.getElementById('kc-image-acknowledge-btn');
                if (btn) { btn.addEventListener('click', doUnlock); }
            }
            bindAck();
            window.kcImageGate = {
                resetLock: function () {
                    unlocked = false;
                    var statusEl = document.getElementById('kc-image-status');
                    if (statusEl) {
                        statusEl.style.background = '#fff3cd';
                        statusEl.style.borderColor = '#ffeaa7';
                        statusEl.innerHTML = '<button id="kc-image-acknowledge-btn" class="kc-btn kc-btn-secondary" type="button"><?php echo addslashes(get_string('imagegate_acknowledge', 'mod_aiknowledgecheck')); ?></button>';
                        bindAck();
                    }
                }
            };
        })();
        </script>
        <?php endif; ?>
        <?php

        // Initialize JS module for student.
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
                'attemptsUsedStr' => get_string('attemptsused', 'mod_aiknowledgecheck'),
                'attemptsUnlimitedStr' => get_string('unlimited', 'mod_aiknowledgecheck'),
                'retakeQuizStr' => get_string('retakequiz', 'mod_aiknowledgecheck'),
                'gradePass' => $gradepass,
                'maxGrade' => $maxgrade,
                'voiceoverEnabled' => isset($knowledgecheck->voiceoverenabled) ? (int)$knowledgecheck->voiceoverenabled : 0,
                'voiceLanguage' => isset($knowledgecheck->voicelanguage) ? $knowledgecheck->voicelanguage : 'en-AU',
                'voiceGender' => isset($knowledgecheck->voicegender) ? $knowledgecheck->voicegender : 'female',
                'voiceStyle' => isset($knowledgecheck->voicestyle) ? $knowledgecheck->voicestyle : 'Zephyr',
                'afterCompletion' => isset($knowledgecheck->aftercompletion) ? $knowledgecheck->aftercompletion : 'restart',
                'showVideoDuringQuiz' => isset($knowledgecheck->showvideoduringquiz) ? (int)$knowledgecheck->showvideoduringquiz : 0,
                'showChapterStamps' => isset($knowledgecheck->showchapterstamps) ? (int)$knowledgecheck->showchapterstamps : 0,
                'hasVideo' => $hasvideo ? 1 : 0,
                'hasImage' => $hasimage ? 1 : 0,
                'surveyMode' => isset($knowledgecheck->surveymode) ? (int)$knowledgecheck->surveymode : 0,
                'surveyScale' => isset($knowledgecheck->surveyscale) ? $knowledgecheck->surveyscale : 'likert5agree',
                'strings' => [
                    'activityLockedNotice' => get_string('activity_locked_notice', 'mod_aiknowledgecheck'),
                    'startAgain'           => get_string('startAgain', 'mod_aiknowledgecheck'),
                ],
            ]]
        );
    }
}

echo $OUTPUT->footer();
