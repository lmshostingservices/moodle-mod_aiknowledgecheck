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
 * AI Knowledge Check - main JavaScript module.
 *
 * @module     mod_aiknowledgecheck/knowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// MIGRATE-EXTERNAL-SERVICES (v1.5.144-152): 'core/ajax' is Moodle's client for declared
// External Services. Every endpoint has now been migrated off the legacy ajax.php action
// dispatcher, which has been deleted; there are no remaining $.ajax calls to it. jQuery is
// still required here for DOM work.
//
// I18N (v1.5.161): 'core/str' supplies the interface text, which used to be hardcoded English,
// and 'core/notification' supplies the modal dialogs that replaced window.alert and
// window.confirm. Native dialogs block the whole page, cannot be themed, are unreachable to
// assistive technology, and are suppressed outright in some embedded contexts.
define('mod_aiknowledgecheck/knowledgecheck',
        ['jquery', 'core/ajax', 'core/str', 'core/notification',
         'mod_aiknowledgecheck/util', 'mod_aiknowledgecheck/mediagates'],
        function($, Ajax, Str, Notification, Util, MediaGates) {
    'use strict';

    /**
     * Localised interface strings, filled in by loadStrings() before the interface is built.
     *
     * Values are read synchronously from here so the rendering code stays straightforward; the
     * one asynchronous step is the single fetch in loadStrings().
     *
     * @type {Object}
     */
    var S = {};

    /** @type {Function} Language-string placeholder substitution, shared with the other modules. */
    var fmt = Util.fmt;

    /**
     * Show a message in a modal dialog.
     *
     * @param {string} message The message to show.
     * @param {string} [title] Dialog title; the generic plugin title is used when omitted.
     * @return {void}
     */
    function kcAlert(message, title) {
        Notification.alert(title || S.dialogtitle, message, S.dialogcontinue);
    }

    /**
     * Ask the user to confirm an action, then run one of two continuations.
     *
     * window.confirm() returned a boolean, so callers could branch inline. The modal is
     * asynchronous, so every call site had to be restructured into these callbacks.
     *
     * @param {string} message The question to ask.
     * The fourth argument is the decline-button label. Moodle 4.3 and later ignore it and always
     * label that button "Cancel"; it is still passed because this plugin supports Moodle 4.0,
     * where the label is honoured.
     *
     * @param {Function} onYes Called when the user confirms.
     * @param {Function} [onNo] Called when the user declines.
     * @return {void}
     */
    function kcConfirm(message, onYes, onNo) {
        Notification.confirm(S.dialogtitle, message, S.dialogyes, S.dialogno, onYes, onNo);
    }

    /**
     * Write an informational diagnostic to the browser console, when there is one.
     *
     * Moodle's eslint configuration forbids the bare `console` global, so these helpers reach
     * it through `window`. They keep the log levels this module has always used and pass their
     * arguments through unchanged.
     *
     * @returns {void}
     */
    function kcLog() {
        if (window.console && window.console.log) {
            window.console.log.apply(window.console, arguments);
        }
    }

    /**
     * Write a warning diagnostic to the browser console, when there is one.
     *
     * @returns {void}
     */
    function kcWarn() {
        if (window.console && window.console.warn) {
            window.console.warn.apply(window.console, arguments);
        }
    }

    /**
     * Write an error diagnostic to the browser console, when there is one.
     *
     * @returns {void}
     */
    function kcError() {
        if (window.console && window.console.error) {
            window.console.error.apply(window.console, arguments);
        }
    }

    /**
     * Whether a value is neither null nor undefined.
     *
     * FIX-KC-REGEN-TIMESTAMP-NULL (v1.5.109): a timestamp of 0 is a real position, so these
     * checks must not use truthiness. This preserves that behaviour without the loose
     * `!=` comparison Moodle's eslint configuration disallows.
     *
     * @param {*} value The value to test.
     * @returns {boolean} True when the value is neither null nor undefined.
     */
    function hasValue(value) {
        return value !== null && value !== undefined;
    }

    let config = {};
    let currentJobId = null;
    let statusPollingInterval = null;
    let statusPollFailures = 0;
    const MAX_POLL_FAILURES = 15;
    let quizData = null;
    let currentQuestionIndex = 0;
    let score = 0;
    let selectedAnswer = null;
    let audioElement = null;
    let audioPreloadCache = {}; // Pre-decoded Audio elements keyed by 'qi_ai' for zero-delay playback
    let audioContext = null;
    let currentAttemptId = null;
    let resumeFromIndex = 0; // Question index to restore when continuing an attempt.
    let resumeAnswers = null; // BUG-SCORE-RESUME fix: saved server answers for score reconstruction.
    let quizAnswerLog = []; // Per-question record for the results download feature.
    let currentAttemptNum = 1; // Tracks which attempt number we are currently recording (1 = first, 2 = first retry, ...).
    // FIX-RACE-FINISH: track in-flight saveanswer calls so finishAttempt waits for them.
    let pendingSaves = 0;
    let pendingFinishAttempt = false;
    // M4: remember answers whose save failed, so we can retry them before finishing rather
    // than silently grading a student on answers the server never received.
    let failedSaves = {}; // Keyed by questionId -> {answerIndex, freetextValue}
    let textSources = []; // Array of {text, questionCount}
    let regenerationCount = 0; // Track regeneration count for free/paid logic (first 3 free)
    let selectedKcJobLevels = []; // Multi-select job levels (pill buttons)
    let selectedKcJobRoles = []; // Multi-select job roles (chips input)
    let isAddingMore = false; // "Add More Questions" mode flag
    let existingQuizData = null; // Preserved questions while adding more

    // Initialize Web Audio API for sound effects
    /**
     * Lazily create (and reuse) the Web Audio context used for the feedback sounds.
     *
     * @return {AudioContext|null} The shared audio context, or null when the browser has no Web Audio support.
     */
    function getAudioContext() {
        if (!audioContext) {
            audioContext = new (window.AudioContext || window.webkitAudioContext)();
        }
        return audioContext;
    }

    // Play a success "ding" sound for correct answers
    /**
     * Play the short rising chime that marks a correct answer.
     *
     * @return {void}
     */
    function playCorrectSound() {
        try {
            var ctx = getAudioContext();
            var oscillator = ctx.createOscillator();
            var gainNode = ctx.createGain();

            oscillator.connect(gainNode);
            gainNode.connect(ctx.destination);

            oscillator.frequency.setValueAtTime(880, ctx.currentTime); // A5
            oscillator.frequency.setValueAtTime(1108.73, ctx.currentTime + 0.1); // C#6
            oscillator.type = 'sine';

            gainNode.gain.setValueAtTime(0.3, ctx.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.3);

            oscillator.start(ctx.currentTime);
            oscillator.stop(ctx.currentTime + 0.3);
        } catch (e) {
            kcLog('[KC] Audio not supported:', e);
        }
    }

    // Play an incorrect "buzz" sound for wrong answers
    /**
     * Play the short falling tone that marks an incorrect answer.
     *
     * @return {void}
     */
    function playIncorrectSound() {
        try {
            var ctx = getAudioContext();
            var oscillator = ctx.createOscillator();
            var gainNode = ctx.createGain();

            oscillator.connect(gainNode);
            gainNode.connect(ctx.destination);

            oscillator.frequency.setValueAtTime(200, ctx.currentTime);
            oscillator.frequency.setValueAtTime(150, ctx.currentTime + 0.1);
            oscillator.type = 'sawtooth';

            gainNode.gain.setValueAtTime(0.2, ctx.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.25);

            oscillator.start(ctx.currentTime);
            oscillator.stop(ctx.currentTime + 0.25);
        } catch (e) {
            kcLog('[KC] Audio not supported:', e);
        }
    }

    // Play a level complete fanfare for perfect score
    /**
     * Play the fanfare used when a quiz is completed.
     *
     * @return {void}
     */
    function playLevelCompleteSound() {
        try {
            var ctx = getAudioContext();
            var notes = [523.25, 659.25, 783.99, 1046.50]; // C5, E5, G5, C6
            var delay = 0;

            notes.forEach(function(freq) {
                var oscillator = ctx.createOscillator();
                var gainNode = ctx.createGain();

                oscillator.connect(gainNode);
                gainNode.connect(ctx.destination);

                oscillator.frequency.setValueAtTime(freq, ctx.currentTime + delay);
                oscillator.type = 'sine';

                gainNode.gain.setValueAtTime(0, ctx.currentTime + delay);
                gainNode.gain.linearRampToValueAtTime(0.3, ctx.currentTime + delay + 0.05);
                gainNode.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + delay + 0.4);

                oscillator.start(ctx.currentTime + delay);
                oscillator.stop(ctx.currentTime + delay + 0.4);

                delay += 0.15;
            });

            // Final chord
            setTimeout(function() {
                [523.25, 659.25, 783.99, 1046.50].forEach(function(freq) {
                    var osc = ctx.createOscillator();
                    var gain = ctx.createGain();
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.frequency.setValueAtTime(freq, ctx.currentTime);
                    osc.type = 'sine';
                    gain.gain.setValueAtTime(0.15, ctx.currentTime);
                    gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.8);
                    osc.start(ctx.currentTime);
                    osc.stop(ctx.currentTime + 0.8);
                });
            }, 700);
        } catch (e) {
            kcLog('[KC] Audio not supported:', e);
        }
    }

    // Create confetti animation
    /**
     * Drop a burst of confetti elements over the page and clean them up afterwards.
     *
     * @return {void}
     */
    function createConfetti() {
        var container = document.createElement('div');
        container.className = 'kc-confetti-container';
        container.id = 'kc-confetti';
        document.body.appendChild(container);

        var colors = ['#667eea', '#764ba2', '#10b981', '#f59e0b', '#ef4444', '#06b6d4', '#8b5cf6'];
        var confettiCount = 150;

        for (var i = 0; i < confettiCount; i++) {
            var confetti = document.createElement('div');
            confetti.className = 'kc-confetti';
            confetti.style.left = Math.random() * 100 + '%';
            confetti.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
            confetti.style.animationDelay = Math.random() * 3 + 's';
            confetti.style.animationDuration = (Math.random() * 2 + 2) + 's';

            // Random shapes
            if (Math.random() > 0.5) {
                confetti.style.borderRadius = '50%';
            }

            container.appendChild(confetti);
        }

        // Remove confetti after animation
        setTimeout(function() {
            if (container.parentNode) {
                container.parentNode.removeChild(container);
            }
        }, 5000);
    }

    /**
     * Extract a YouTube video ID from any standard YouTube URL.
     *
     * ADD-KC-MEDIAPER-Q (v1.5.120): handles watch?v=, youtu.be/, /embed/ and /v/ formats.
     *
     * @param {string} url The URL to parse.
     * @return {string} The 11-character video ID, or an empty string when the URL does not match.
     */
    function extractYouTubeId(url) {
        if (!url) {
            return '';
        }
        var m = url.match(/(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|v\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/);
        return m ? m[1] : '';
    }

    /**
     * Download a CSV question-mapping file for Excel.
     *
     * Columns: [Criteria,] [Topic,] Question Number, Question Text, Option A-E,
     * Correct Answer, Correct Option Text, Explanation. The Topic and Criteria
     * columns are included only when at least one question carries that data.
     *
     * @return {void}
     */
    function downloadExcelMapping() {
        if (!quizData || quizData.length === 0) {
            kcAlert(S.errornogeneratedquestions);
            return;
        }

        var labels = ['A', 'B', 'C', 'D'];

        // Determine which optional columns to include.
        var hasTopics = quizData.some(function(q) {
            return q.mappingTopic && q.mappingTopic.trim();
        });
        var hasCriteria = quizData.some(function(q) {
            return q.mappingCriteria && q.mappingCriteria.trim();
        });

        // BOM for Excel UTF-8 compatibility.
        var bom = '\uFEFF';
        var csvRows = [];

        // Header row.
        var headers = [];
        if (hasCriteria) {
            headers.push('Criteria');
        }
        if (hasTopics) {
            headers.push('Topic');
        }
        headers = headers.concat([
            'Question Number',
            'Question Text',
            'Option A',
            'Option B',
            'Option C',
            'Option D',
            'Option E',
            'Correct Answer',
            'Correct Option Text',
            'Explanation'
        ]);
        csvRows.push(headers.map(function(h) {
            return '"' + h + '"';
        }).join(','));

        // Data rows.
        quizData.forEach(function(q, index) {
            var correctIdx = q.correctAnswer || 0;
            var correctLabel = labels[correctIdx] || 'A';
            var correctText = (q.options && q.options[correctIdx]) ? q.options[correctIdx] : '';
            var explanation = (q.explanations && q.explanations[correctIdx]) ? q.explanations[correctIdx] : '';

            var row = [];
            if (hasCriteria) {
                row.push('"' + (q.mappingCriteria || '').replace(/"/g, '""') + '"');
            }
            if (hasTopics) {
                row.push('"' + (q.mappingTopic || '').replace(/"/g, '""') + '"');
            }
            row = row.concat([
                index + 1,
                '"' + (q.question || '').replace(/"/g, '""') + '"',
                '"' + ((q.options && q.options[0]) || '').replace(/"/g, '""') + '"',
                '"' + ((q.options && q.options[1]) || '').replace(/"/g, '""') + '"',
                '"' + ((q.options && q.options[2]) || '').replace(/"/g, '""') + '"',
                '"' + ((q.options && q.options[3]) || '').replace(/"/g, '""') + '"',
                // FIX-KC-EDIT-SURVEY (v1.5.139): 5-point scales have a 5th option.
                '"' + ((q.options && q.options[4]) || '').replace(/"/g, '""') + '"',
                '"' + correctLabel + '"',
                '"' + correctText.replace(/"/g, '""') + '"',
                '"' + explanation.replace(/"/g, '""') + '"'
            ]);
            csvRows.push(row.join(','));
        });

        var csvContent = bom + csvRows.join('\n');
        var blob = new Blob([csvContent], {type: 'text/csv;charset=utf-8;'});
        var url = window.URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'question_mapping.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
    }

    const countryStates = {
        'Australia': [
            {value: 'Western Australia', label: 'Western Australia'},
            {value: 'Queensland', label: 'Queensland'},
            {value: 'New South Wales', label: 'New South Wales'},
            {value: 'Victoria', label: 'Victoria'},
            {value: 'South Australia', label: 'South Australia'},
            {value: 'Tasmania', label: 'Tasmania'},
            {value: 'Northern Territory', label: 'Northern Territory'},
            {value: 'Australian Capital Territory', label: 'ACT'}
        ],
        'New Zealand': [
            {value: 'Auckland', label: 'Auckland'},
            {value: 'Wellington', label: 'Wellington'},
            {value: 'Canterbury', label: 'Canterbury'},
            {value: 'Waikato', label: 'Waikato'},
            {value: 'Otago', label: 'Otago'}
        ],
        'United Kingdom': [
            {value: 'England', label: 'England'},
            {value: 'Scotland', label: 'Scotland'},
            {value: 'Wales', label: 'Wales'},
            {value: 'Northern Ireland', label: 'Northern Ireland'}
        ],
        'United States': [
            {value: 'California', label: 'California'},
            {value: 'Texas', label: 'Texas'},
            {value: 'Florida', label: 'Florida'},
            {value: 'New York', label: 'New York'},
            {value: 'Other US State', label: 'Other'}
        ],
        'Canada': [
            {value: 'Ontario', label: 'Ontario'},
            {value: 'Quebec', label: 'Quebec'},
            {value: 'British Columbia', label: 'British Columbia'},
            {value: 'Alberta', label: 'Alberta'}
        ],
        'Singapore': []
    };

    // Current selected industry for job title lookup
    let currentIndustry = '';


    /**
     * The language strings this module needs, as core/str request objects.
     *
     * @type {Array}
     */
    var STRING_REQUESTS = [
        {key: 'pluginname', component: 'mod_aiknowledgecheck'},
        {key: 'continue', component: 'core'},
        {key: 'yes', component: 'core'},
        {key: 'no', component: 'core'},
        {key: 'js_attemptsused', component: 'mod_aiknowledgecheck'},
        {key: 'js_correct', component: 'mod_aiknowledgecheck'},
        {key: 'js_eta_1min', component: 'mod_aiknowledgecheck'},
        {key: 'js_eta_detail', component: 'mod_aiknowledgecheck'},
        {key: 'js_eta_hourunit_many', component: 'mod_aiknowledgecheck'},
        {key: 'js_eta_hourunit_one', component: 'mod_aiknowledgecheck'},
        {key: 'js_eta_hoursmins', component: 'mod_aiknowledgecheck'},
        {key: 'js_eta_label', component: 'mod_aiknowledgecheck'},
        {key: 'js_eta_minutes', component: 'mod_aiknowledgecheck'},
        {key: 'js_eta_under1min', component: 'mod_aiknowledgecheck'},
        {key: 'js_eta_withaudio', component: 'mod_aiknowledgecheck'},
        {key: 'js_incorrect', component: 'mod_aiknowledgecheck'},
        {key: 'js_missingaudio_summary', component: 'mod_aiknowledgecheck'},
        {key: 'js_questionsready', component: 'mod_aiknowledgecheck'},
        {key: 'js_questionword_many', component: 'mod_aiknowledgecheck'},
        {key: 'js_questionword_one', component: 'mod_aiknowledgecheck'},
        {key: 'js_regenserverfailed', component: 'mod_aiknowledgecheck'},
        {key: 'js_retakequiz', component: 'mod_aiknowledgecheck'},
        {key: 'js_statuscheckfailed', component: 'mod_aiknowledgecheck'},
        {key: 'js_voiceoverdisabled_summary', component: 'mod_aiknowledgecheck'},
        {key: 'js_warn_languagechange', component: 'mod_aiknowledgecheck'},
        {key: 'js_warn_novoiceover', component: 'mod_aiknowledgecheck'},
        {key: 'js_warn_voicesaved', component: 'mod_aiknowledgecheck'},
        {key: 'js_audioupdatefailed', component: 'mod_aiknowledgecheck'},
        {key: 'js_continueattempt', component: 'mod_aiknowledgecheck'},
        {key: 'js_finishquiz', component: 'mod_aiknowledgecheck'},
        {key: 'js_generateaudio', component: 'mod_aiknowledgecheck'},
        {key: 'js_generateimage_cost', component: 'mod_aiknowledgecheck'},
        {key: 'js_generating', component: 'mod_aiknowledgecheck'},
        {key: 'js_generatingaudio', component: 'mod_aiknowledgecheck'},
        {key: 'js_generatingimage', component: 'mod_aiknowledgecheck'},
        {key: 'js_imagegenerated', component: 'mod_aiknowledgecheck'},
        {key: 'js_loading', component: 'mod_aiknowledgecheck'},
        {key: 'js_next', component: 'mod_aiknowledgecheck'},
        {key: 'js_nextquestion', component: 'mod_aiknowledgecheck'},
        {key: 'question_of', component: 'mod_aiknowledgecheck'},
        {key: 'js_regensavedwithsettings', component: 'mod_aiknowledgecheck'},
        {key: 'js_savingremovingaudio', component: 'mod_aiknowledgecheck'},
        {key: 'js_score', component: 'mod_aiknowledgecheck'},
        {key: 'js_selectsector', component: 'mod_aiknowledgecheck'},
        {key: 'js_selectstate', component: 'mod_aiknowledgecheck'},
        {key: 'js_settingssaved', component: 'mod_aiknowledgecheck'},
        {key: 'js_startingeneration', component: 'mod_aiknowledgecheck'},
        {key: 'js_startquiz', component: 'mod_aiknowledgecheck'},
        {key: 'js_submitsurvey', component: 'mod_aiknowledgecheck'},
        {key: 'voice_aoede', component: 'mod_aiknowledgecheck'},
        {key: 'voice_charon', component: 'mod_aiknowledgecheck'},
        {key: 'voice_fenrir', component: 'mod_aiknowledgecheck'},
        {key: 'voice_kore', component: 'mod_aiknowledgecheck'},
        {key: 'voice_leda', component: 'mod_aiknowledgecheck'},
        {key: 'voice_orus', component: 'mod_aiknowledgecheck'},
        {key: 'js_voiceoverdisabled', component: 'mod_aiknowledgecheck'},
        {key: 'js_voiceovergenerated', component: 'mod_aiknowledgecheck'},
        {key: 'js_voiceovergenfailed', component: 'mod_aiknowledgecheck'},
        {key: 'voice_puck', component: 'mod_aiknowledgecheck'},
        {key: 'js_voicesettingsupdated', component: 'mod_aiknowledgecheck'},
        {key: 'voice_zephyr', component: 'mod_aiknowledgecheck'},
        {key: 'js_confirmdeletequestion', component: 'mod_aiknowledgecheck'},
        {key: 'js_confirmdiscardchanges', component: 'mod_aiknowledgecheck'},
        {key: 'js_confirmeditinprogress_many', component: 'mod_aiknowledgecheck'},
        {key: 'js_confirmeditinprogress_one', component: 'mod_aiknowledgecheck'},
        {key: 'js_confirmpaidregeneration', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_audiogenfailed', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_audiogenfaileddetail', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_cannotdeletelastquestion', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_continueattemptfailed', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_continueattemptreload', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_emptyoption', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_emptyquestiontext', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_existingpreserved', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_generationfailed', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_generationstartfailed', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_insufficientcredits', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_loadquestionsfailed', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_lostconnectiongenerating', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_lostconnectionpreserved', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_maxtextsources', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_nocorrectanswerselected', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_noeditforms', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_noexistingquestions', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_nogeneratedquestions', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_noquestionsentered', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_noquestionsstudent', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_noquestionstoedit', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_noquestionstoregenerate', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_noresultstodownload', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_notextsourcecontent', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_notopicsentered', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_popupblocked', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_regenconnectionfailed', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_regenfaileddetail', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_regenfailedretry', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_regensavefailed', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_regenzeroquestions', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_requestfailed', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_saveclicksavechanges', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_saveconnectionlost', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_savequestionsfailed', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_savetomoodlefailed', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_startattemptfailed', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_startquizfailed', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_unknown', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_zeroquestions', component: 'mod_aiknowledgecheck'},
        {key: 'js_error_zeroquestionspreserved', component: 'mod_aiknowledgecheck'},
        {key: 'js_success_audiogenerated', component: 'mod_aiknowledgecheck'},
        {key: 'js_success_regenfree', component: 'mod_aiknowledgecheck'},
        {key: 'js_success_regenpaid', component: 'mod_aiknowledgecheck'},
        {key: 'js_unsavedanswers_message', component: 'mod_aiknowledgecheck'},
        {key: 'js_unsavedanswers_title', component: 'mod_aiknowledgecheck'}
    ];

    /**
     * The names those strings are stored under in S, in the same order.
     *
     * @type {Array}
     */
    var STRING_NAMES = [
        'dialogtitle',
        'dialogcontinue',
        'dialogyes',
        'dialogno',
        'attemptsused',
        'correct',
        'eta1min',
        'etadetail',
        'etahourunitmany',
        'etahourunitone',
        'etahoursmins',
        'etalabel',
        'etaminutes',
        'etaunder1min',
        'etawithaudio',
        'incorrect',
        'missingaudiosummary',
        'questionsready',
        'questionwordmany',
        'questionwordone',
        'regenserverfailed',
        'retakequiz',
        'statuscheckfailed',
        'voiceoverdisabledsummary',
        'warnlanguagechange',
        'warnnovoiceover',
        'warnvoicesaved',
        'audioupdatefailed',
        'continueattempt',
        'finishquiz',
        'generateaudio',
        'generateimagecost',
        'generating',
        'generatingaudio',
        'generatingimage',
        'imagegenerated',
        'loading',
        'next',
        'nextquestion',
        'questionof',
        'regensavedwithsettings',
        'savingremovingaudio',
        'score',
        'selectsector',
        'selectstate',
        'settingssaved',
        'startingeneration',
        'startquiz',
        'submitsurvey',
        'voiceaoede',
        'voicecharon',
        'voicefenrir',
        'voicekore',
        'voiceleda',
        'voiceorus',
        'voiceoverdisabled',
        'voiceovergenerated',
        'voiceovergenfailed',
        'voicepuck',
        'voicesettingsupdated',
        'voicezephyr',
        'confirmdeletequestion',
        'confirmdiscardchanges',
        'confirmeditinprogressmany',
        'confirmeditinprogressone',
        'confirmpaidregeneration',
        'erroraudiogenfailed',
        'erroraudiogenfaileddetail',
        'errorcannotdeletelastquestion',
        'errorcontinueattemptfailed',
        'errorcontinueattemptreload',
        'erroremptyoption',
        'erroremptyquestiontext',
        'errorexistingpreserved',
        'errorgenerationfailed',
        'errorgenerationstartfailed',
        'errorinsufficientcredits',
        'errorloadquestionsfailed',
        'errorlostconnectiongenerating',
        'errorlostconnectionpreserved',
        'errormaxtextsources',
        'errornocorrectanswerselected',
        'errornoeditforms',
        'errornoexistingquestions',
        'errornogeneratedquestions',
        'errornoquestionsentered',
        'errornoquestionsstudent',
        'errornoquestionstoedit',
        'errornoquestionstoregenerate',
        'errornoresultstodownload',
        'errornotextsourcecontent',
        'errornotopicsentered',
        'errorpopupblocked',
        'errorregenconnectionfailed',
        'errorregenfaileddetail',
        'errorregenfailedretry',
        'errorregensavefailed',
        'errorregenzeroquestions',
        'errorrequestfailed',
        'errorsaveclicksavechanges',
        'errorsaveconnectionlost',
        'errorsavequestionsfailed',
        'errorsavetomoodlefailed',
        'errorstartattemptfailed',
        'errorstartquizfailed',
        'errorunknown',
        'errorzeroquestions',
        'errorzeroquestionspreserved',
        'successaudiogenerated',
        'successregenfree',
        'successregenpaid',
        'unsavedanswersmessage',
        'unsavedanswerstitle'
    ];

    /**
     * Fetch every interface string in one request, then run a callback.
     *
     * core/str caches per page, so this is a single round trip at worst and free on a warm
     * cache. Everything after this point can read S synchronously.
     *
     * @param {Function} onReady Called once the strings are in place.
     * @return {void}
     */
    function loadStrings(onReady) {
        Str.get_strings(STRING_REQUESTS).then(function(values) {
            STRING_NAMES.forEach(function(name, i) {
                S[name] = values[i];
            });
            onReady();
            return values;
        }).catch(function(error) {
            // Without strings the interface cannot be drawn correctly, so surface the failure
            // rather than rendering a half-translated page.
            kcError('[KC] Could not load language strings', error);
            Notification.exception(error);
        });
    }

    /**
     * Entry point called from the page: load the strings, then wire up the interface.
     *
     * @param {Object} cfg Configuration passed in from view.php (cmid, isTeacher, and friends).
     * @return {void}
     */
    function init(cfg) {
        config = cfg;
        kcLog('[KC] init called, isTeacher:', config.isTeacher);

        loadStrings(function() {
            start();
        });
    }

    /**
     * Wire up the interface, once the language strings are available.
     *
     * @return {void}
     */
    function start() {
        bindEvents();

        // ADD-SURVEY-FREETEXT (v1.5.127): Show free-text questions textarea in survey mode.
        // SURVEY-MODE-UI (v1.5.128): Hide voiceover — surveys don't generate audio explanations.
        // Also pre-open the use-own-questions panel (most survey teachers supply their own questions)
        // and update the sublabel so teachers know the scale is applied automatically.
        if (config.surveyMode) {
            $('#freetext-questions-group').show();
            // Hide voiceover section — not applicable to surveys.
            $('#voiceover-toggle').prop('checked', false);
            $('#voice-settings-section').hide();
            if ($('#voiceover-section').length) {
                $('#voiceover-section').hide();
            }
        }

        // Apply persisted voiceover settings from server config
        if (typeof config.voiceoverEnabled !== 'undefined') {
            var voEnabled = !!config.voiceoverEnabled;
            $('#voiceover-toggle').prop('checked', voEnabled);
            if (voEnabled) {
                $('#voice-settings-section').show();
            } else {
                $('#voice-settings-section').hide();
            }
        }
        if (config.voiceLanguage) {
            $('#voice-language').val(config.voiceLanguage);
        }
        if (config.voiceGender) {
            $('#voice-gender').val(config.voiceGender);
            handleGenderChange();
        }
        if (config.voiceStyle) {
            setTimeout(function() {
                $('#voice-style').val(config.voiceStyle);
            }, 50);
        }

        // Teacher-only initialization (credits, form dropdowns)
        if (config.isTeacher) {
            kcLog('[KC] Teacher mode - fetching credits and industries');
            fetchCredits();
            fetchIndustries();

            // Check if questions already exist in the database
            checkExistingQuestions();
        } else {
            kcLog('[KC] Student mode - skipping credits fetch');
        }
    }

    /**
     * Ask the server whether this activity already has saved questions, and show the matching screen.
     *
     * @return {void}
     */
    function checkExistingQuestions() {
        kcLog('[KC] Checking for existing questions...');

        // MIGRATE-EXTERNAL-SERVICES (v1.5.148): getquestions now runs through the declared
        // mod_aiknowledgecheck_get_questions service. Ajax.call resolves with the payload
        // directly, so the old jQuery success/error pair becomes done/fail.
        Ajax.call([{
            methodname: 'mod_aiknowledgecheck_get_questions',
            args: {cmid: parseInt(config.cmid, 10)}
        }])[0].done(function(response) {
                if (response.ok && response.questions && response.questions.length > 0) {
                    kcLog('[KC] Found existing questions:', response.questions.length);

                    // Transform database format to quiz format
                    quizData = response.questions.map(function(q) {
                        // ADD-SURVEY-FREETEXT (v1.5.127): guard against empty options arrays for freetext questions.
                        var opts = q.options || [];
                        return {
                            id: q.id,
                            question: q.question,
                            options: opts.map(function(o) {
                                return o.text || '';
                            }),
                            explanations: opts.map(function(o) {
                                return o.explanation || '';
                            }),
                            correctAnswer: q.correctIndex,
                            audioData: q.audioData || null,
                            mappingTopic: q.mappingTopic || '',
                            mappingCriteria: q.mappingCriteria || '',
                            timestamp_seconds: (q.timestamp_seconds !== undefined && q.timestamp_seconds !== null)
                                ? q.timestamp_seconds
                                : null,
                            // ADD-KC-IMAGEGATE (v1.5.115): Map per-question image data.
                            imageUrl: q.imageUrl || '',
                            imageEnabled: q.imageEnabled ? true : false,
                            // ADD-KC-MEDIAPER-Q (v1.5.120): Map per-question video and audio data.
                            questionVideoUrl: q.questionVideoUrl || '',
                            questionVideoEnabled: q.questionVideoEnabled ? true : false,
                            questionAudioUrl: q.questionAudioUrl || '',
                            questionAudioEnabled: q.questionAudioEnabled ? true : false,
                            // ADD-SURVEY-FREETEXT (v1.5.127): Preserve question type.
                            questionType: q.questionType || 'scale'
                        };
                    });

                    // Check if any questions are missing audio (only relevant if voiceover is enabled)
                    var voiceoverOn = $('#voiceover-toggle').is(':checked');
                    var missingAudio = voiceoverOn && quizData.some(function(q) {
                        return !q.audioData || q.audioData.length === 0;
                    });

                    // Show the ready state with existing questions
                    $('#kc-form-section').hide();
                    $('#kc-ready-section').show();
                    var summaryText = fmt(S.questionsready, quizData.length);
                    if (!voiceoverOn) {
                        summaryText += S.voiceoverdisabledsummary;
                    } else if (missingAudio) {
                        summaryText += S.missingaudiosummary;
                    }
                    $('#ready-summary').text(summaryText);
                    var kcTeacherEta = document.getElementById('kc-teacher-eta');
                    if (kcTeacherEta) {
                        var kcSecPerQ = voiceoverOn ? 120 : 90;
                        var kcTotalSec = quizData.length * kcSecPerQ;
                        var kcMin = Math.ceil(kcTotalSec / 60);
                        var kcHours = Math.floor(kcMin / 60);
                        var kcTimeStr;
                        if (kcMin < 1) {
                            kcTimeStr = S.etaunder1min;
                        } else if (kcMin === 1) {
                            kcTimeStr = S.eta1min;
                        } else if (kcMin < 60) {
                            kcTimeStr = fmt(S.etaminutes, kcMin);
                        } else {
                            kcTimeStr = fmt(S.etahoursmins, {
                                hours: kcHours,
                                hourunit: kcHours === 1 ? S.etahourunitone : S.etahourunitmany,
                                mins: kcMin % 60
                            });
                        }
                        var kcDetailStr = fmt(S.etadetail, {
                            count: quizData.length,
                            questionword: quizData.length !== 1 ? S.questionwordmany : S.questionwordone,
                            audio: voiceoverOn ? S.etawithaudio : ''
                        });
                        var kcClockSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="current' +
                            'Color" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10' +
                            '"/><polyline points="12 6 12 12 16 14"/></svg>';
                        kcTeacherEta.innerHTML = '<div class="kc-eta-banner">' +
                            '<div class="kc-eta-icon-wrap">' + kcClockSvg + '</div>' +
                            '<div class="kc-eta-body">' +
                            '<span class="kc-eta-label">' + escapeHtml(S.etalabel) + '</span>' +
                            '<span class="kc-eta-time">' + kcTimeStr + '</span>' +
                            '<span class="kc-eta-detail">' + kcDetailStr + '</span>' +
                            '</div></div>';
                    }

                    // Add regenerate-audio button if needed (generate audio is missing)
                    if (!$('#regenerate-audio-btn').length && missingAudio) {
                        var audioBtnHtml = '<button type="button" id="regenerate-audio-btn" class="kc-btn kc-btn-primary" style="' +
                            'margin-left: 10px;">Generate Audio</button>';
                        $('#kc-ready-section .kc-ready-actions').append(audioBtnHtml);
                        $('#regenerate-audio-btn').on('click', function() {
                            regenerateAudio();
                        });
                    }
                } else {
                    kcLog('[KC] No existing questions found - showing form');
                }
        }).fail(function(err) {
            kcError('[KC] Check existing questions failed:',
                err && err.message ? err.message : err);
        });
    }

    /**
     * Attach every delegated event handler used by the activity.
     *
     * @return {void}
     */
    function bindEvents() {
        $('#topics-input').on('input', updateStats);
        $('#questions-per-topic').on('change', updateStats);
        $('#country-select').on('change', handleCountryChange);
        $('#voice-gender').on('change', handleGenderChange);
        $('#voiceover-toggle').on('change', function() {
            var isChecked = $(this).is(':checked');
            if (isChecked) {
                $('#voice-settings-section').slideDown(200);
            } else {
                $('#voice-settings-section').slideUp(200);
            }
            updateStats();
        });
        $('#kc-form').on('submit', handleGenerate);
        $('#take-quiz-btn').on('click', startQuiz);
        $('#add-more-questions-btn').on('click', handleAddMoreQuestions);
        $('#edit-questions-btn').on('click', showEditMode);
        $('#download-excel-btn').on('click', downloadExcelMapping);
        $('#save-edits-btn').on('click', saveEdits);
        $('#cancel-edits-btn').on('click', cancelEdits);
        $('#edit-settings-btn').on('click', openSettingsModal);
        $('#close-settings-btn').on('click', closeSettingsModal);
        $('#settings-cancel-btn').on('click', closeSettingsModal);
        $('#ready-regenerate-btn').off('click').on('click', function() {
            handleRegenerateWithInstructions('ready');
        });
        $('#edit-regenerate-btn').off('click').on('click', function() {
            handleRegenerateWithInstructions('edit');
        });
        $('#settings-save-btn').on('click', saveSettings);
        $('#settings-voiceover-toggle').on('change', function() {
            var isChecked = $(this).is(':checked');
            if (isChecked) {
                $('#settings-voice-options').slideDown(200);
            } else {
                $('#settings-voice-options').slideUp(200);
            }
            updateSettingsWarning();
        });
        $('#settings-voice-language').on('change', function() {
            updateSettingsWarning();
        });
        $('#settings-voice-gender').on('change', function() {
            var gender = $(this).val();
            fillVoiceOptions($('#settings-voice-style'), gender);
        });
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $('#kc-settings-overlay').is(':visible') && !$('#settings-save-btn').prop('disabled')) {
                closeSettingsModal();
            }
        });
        $('#kc-settings-overlay').on('click', function(e) {
            if (e.target === this) {
                closeSettingsModal();
            }
        });
        $('#check-answer-btn').on('click', checkAnswer);
        $('#next-question-btn').on('click', nextQuestion);
        // Play button removed - voiceover now auto-plays on answer check
        $('#play-audio-btn').hide();
        $('#retake-btn').on('click', retakeQuiz);
        $('#retake-quiz-btn').on('click', retakeQuiz);

        // Student buttons - start/continue attempt
        $('#start-attempt-btn').on('click', handleStartAttempt);
        $('#continue-attempt-btn').on('click', handleContinueAttempt);

        // User questions toggle.
        $('#user-questions-toggle').on('change', function() {
            var isChecked = $(this).is(':checked');
            if (isChecked) {
                $('#user-questions-fields').slideDown(200);
                // When using own questions, hide topics, criteria and questions per topic
                $('#topics-input').closest('.kc-form-group').slideUp(200);
                $('#criteria-input-group').slideUp(200);
                $('#questions-per-topic-group').slideUp(200);
            } else {
                $('#user-questions-fields').slideUp(200);
                $('#topics-input').closest('.kc-form-group').slideDown(200);
                $('#criteria-input-group').slideDown(200);
                $('#questions-per-topic-group').slideDown(200);
            }
            updateStats();
        });

        // User questions input change.
        $('#user-questions-input').on('input', updateStats);

        // Paste content toggle.
        $('#paste-content-toggle').on('change', function() {
            var isChecked = $(this).is(':checked');
            if (isChecked) {
                $('#paste-content-fields').slideDown(200);
                $('#topics-input').closest('.kc-form-group').hide();
                $('#criteria-input-group').hide();
                $('#questions-per-topic').closest('.kc-form-group').hide();
                $('#user-questions-toggle').closest('.kc-context-section').hide();
                if (textSources.length === 0) {
                    addTextSource();
                }
            } else {
                $('#paste-content-fields').slideUp(200);
                $('#topics-input').closest('.kc-form-group').show();
                $('#criteria-input-group').show();
                $('#questions-per-topic').closest('.kc-form-group').show();
                $('#user-questions-toggle').closest('.kc-context-section').show();
                textSources = [];
                $('#text-sources-container').empty();
            }
            updateStats();
        });

        $('#add-text-source-btn').on('click', function() {
            addTextSource();
        });

        $('#text-sources-container').on('click', '.kc-text-source-remove', function(e) {
            e.preventDefault();
            var idx = parseInt($(this).data('index'), 10);
            textSources.splice(idx, 1);
            renderTextSources();
            updateStats();
        });

        $('#text-sources-container').on('change', '.kc-text-source-questions', function() {
            var idx = parseInt($(this).data('index'), 10);
            textSources[idx].questionCount = parseInt($(this).val(), 10);
            updateStats();
        });

        $('#text-sources-container').on('input', '.kc-text-source-textarea', function() {
            var idx = parseInt($(this).data('index'), 10);
            textSources[idx].text = $(this).val();
            updateStats();
        });

        // Workplace context toggle.
        $('#workplace-context-toggle').on('change', function() {
            var isChecked = $(this).is(':checked');
            if (isChecked) {
                $('#context-fields').slideDown(200);
            } else {
                $('#context-fields').slideUp(200);
            }
        });

        // Industry change  -  update current industry and populate the sector dropdown.
        $('#industry-select').on('change', function() {
            currentIndustry = $(this).val();
            var $sectorSelect = $('#industry-sector');
            $sectorSelect.empty().append($('<option>').val('').text(S.selectsector));
            getIndustrySectors(currentIndustry).forEach(function(s) {
                $sectorSelect.append($('<option>').val(s).text(s));
            });
            $sectorSelect.prop('disabled', !currentIndustry);
        });

        // Job level pills  -  multi-select toggle.
        $('#kc-job-level-pills').on('click', '.kc-level-pill', function() {
            var val = $(this).data('value');
            var idx = selectedKcJobLevels.indexOf(val);
            if (idx > -1) {
                selectedKcJobLevels.splice(idx, 1);
                $(this).removeClass('kc-level-active');
            } else {
                selectedKcJobLevels.push(val);
                $(this).addClass('kc-level-active');
            }
            kcLog('[KC] Selected job levels:', selectedKcJobLevels);
        });

        // Job role chips  -  press Enter or comma to add, max 5.
        $('#kc-job-role-input').on('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                var val = $(this).val().trim().replace(/,$/, '');
                if (val && selectedKcJobRoles.indexOf(val) === -1 && selectedKcJobRoles.length < 5) {
                    selectedKcJobRoles.push(val);
                    renderKcJobRoleChips();
                }
                $(this).val('');
            }
        });

        // Education type change - toggle VET/Academic/General.
        $('#education-type-select').on('change', function() {
            var type = $(this).val();
            if (type === 'vet') {
                $('#vet-level-field').show();
                $('#academic-level-field').hide();
                $('#vet-info-card').show();
                $('#academic-info-card').hide();
                $('#general-info-card').hide();
            } else if (type === 'academic') {
                $('#vet-level-field').hide();
                $('#academic-level-field').show();
                $('#vet-info-card').hide();
                $('#academic-info-card').show();
                $('#general-info-card').hide();
            } else {
                // General
                $('#vet-level-field').hide();
                $('#academic-level-field').hide();
                $('#vet-info-card').hide();
                $('#academic-info-card').hide();
                $('#general-info-card').show();
            }
        });
    }

    /**
     * Redraw the job-role chips from the current selection.
     *
     * @return {void}
     */
    function renderKcJobRoleChips() {
        var container = document.getElementById('kc-job-role-chips');
        if (!container) {
            return;
        }
        container.innerHTML = selectedKcJobRoles.map(function(role, idx) {
            return '<div class="kc-role-chip">' +
                '<span>' + $('<span>').text(role).html() + '</span>' +
                '<button type="button" class="kc-chip-remove" data-idx="' +
                     idx +
                     '" aria-label="Remove ' +
                     escapeAttr(role) +
                     '">\u00d7</button>' +
                '</div>';
        }).join('');
        $(container).find('.kc-chip-remove').on('click', function() {
            selectedKcJobRoles.splice(parseInt($(this).data('idx'), 10), 1);
            renderKcJobRoleChips();
        });
        var input = document.getElementById('kc-job-role-input');
        if (input) {
            input.disabled = selectedKcJobRoles.length >= 5;
        }
    }

    /**
     * Show or hide the gender-specific context fields when the gender select changes.
     *
     * @return {void}
     */
    function handleGenderChange() {
        var gender = $('#voice-gender').val();
        var $voiceStyle = $('#voice-style');

        // Clear and rebuild the voice style dropdown based on gender
        fillVoiceOptions($voiceStyle, gender);
    }

    /**
     * Fetch the account's remaining credit balance and display it.
     *
     * @return {void}
     */
    function fetchCredits() {
        kcLog('[KC] fetchCredits called, isTeacher:', config.isTeacher);

        var showCredits = function(value) {
            $('#credits-value').text(value);
            $('#kc-balance-amount').text(value);
            $('#kc-progress-balance').text(value);
        };

        // MIGRATE-EXTERNAL-SERVICES (v1.5.144): first endpoint moved off ajax.php. Moodle
        // validates the arguments against the service declaration before the PHP runs, and
        // handles the sesskey itself, so neither is passed here.
        Ajax.call([{
            methodname: 'mod_aiknowledgecheck_get_credits',
            args: {cmid: parseInt(config.cmid, 10)}
        }])[0].done(function(response) {
            if (response && response.ok) {
                showCredits(Number(response.credits).toLocaleString());
            } else {
                kcLog('[KC] credits error:', (response && response.error) || 'Unknown error');
                showCredits('--');
            }
        }).fail(function(err) {
            kcLog('[KC] credits service error:', err && err.message ? err.message : err);
            showCredits('--');
        });
    }

    // -- Industry & Sector Data  -  kept in sync with Content Creator --------------
    var INDUSTRIES = [
        'Aged Care', 'Agriculture', 'Automotive', 'Aviation', 'Building & Construction',
        'Business Services', 'Childcare', 'Community Services', 'Education', 'Electrical',
        'Engineering', 'Finance', 'Food Processing', 'Government', 'Healthcare',
        'Hospitality', 'Information Technology', 'Logistics', 'Manufacturing', 'Mining',
        'Plumbing', 'Retail', 'Security', 'Sport & Recreation', 'Tourism', 'Transport',
        'Utilities', 'Warehousing', 'Other'
    ];
    var INDUSTRY_SUBCATEGORIES = {
        'Aged Care': [
            'Residential Aged Care', 'Home Care Services', 'Dementia Care', 'Palliative Care', 'Community Aged Care',
            'Retirement Living', 'Respite Care', 'Allied Health in Aged Care'
        ],
        'Agriculture': [
            'Cropping & Grain', 'Livestock & Cattle', 'Dairy Farming', 'Horticulture', 'Viticulture & Wine', 'Aquaculture',
            'Poultry', 'Shearing & Wool', 'Agricultural Contracting', 'Irrigation & Water Management'
        ],
        'Automotive': [
            'Light Vehicle Mechanical', 'Heavy Vehicle Mechanical', 'Auto Electrical', 'Panel Beating & Spray Painting',
            'Motorcycle Technician', 'Marine Mechanical', 'Automotive Parts & Accessories', 'Vehicle Sales', 'Tyre Fitting'
        ],
        'Aviation': [
            'Commercial Aviation', 'General Aviation', 'Aircraft Maintenance', 'Ground Operations', 'Air Traffic Control',
            'Cabin Crew', 'Aviation Security', 'Helicopter Operations'
        ],
        'Building & Construction': [
            'Residential Construction', 'Commercial Construction', 'Civil Construction', 'Mining Construction',
            'Industrial Construction', 'High-Rise Construction', 'Renovation & Refurbishment', 'Demolition', 'Scaffolding',
            'Formwork', 'Concreting', 'Steel Fixing', 'Carpentry', 'Bricklaying', 'Tiling', 'Painting & Decorating', 'Plastering',
            'Roofing', 'Glazing', 'Waterproofing'
        ],
        'Business Services': [
            'Accounting & Bookkeeping', 'Human Resources', 'Marketing & Advertising', 'Legal Services', 'Consulting', 'Recruitment',
            'Training & Development', 'Property Management', 'Cleaning Services', 'Security Services'
        ],
        'Childcare': [
            'Long Day Care', 'Family Day Care', 'Outside School Hours Care', 'Kindergarten/Preschool', 'Occasional Care',
            'In-Home Care', 'Special Needs Support', 'Early Intervention'
        ],
        'Community Services': [
            'Disability Support', 'Mental Health Support', 'Youth Work', 'Family Services', 'Homelessness Services',
            'Drug & Alcohol Services', 'Aboriginal & Torres Strait Islander Services', 'Refugee & Migrant Services',
            'Domestic Violence Support', 'Case Management'
        ],
        'Education': [
            'Primary Education', 'Secondary Education', 'Vocational Education (VET)', 'Higher Education/University', 'TAFE',
            'Adult Education', 'Special Education', 'Early Childhood Education', 'Online/Distance Education', 'Education Support',
            'Training Administration', 'School Administration', 'Private Training Provider (RTO)'
        ],
        'Electrical': [
            'Domestic Electrical', 'Commercial Electrical', 'Industrial Electrical', 'Instrumentation',
            'Refrigeration & Air Conditioning', 'Solar Installation', 'Data & Communications', 'Fire Protection Systems',
            'Lift Installation'
        ],
        'Engineering': [
            'Mechanical Engineering', 'Civil Engineering', 'Structural Engineering', 'Electrical Engineering',
            'Chemical Engineering', 'Mining Engineering', 'Environmental Engineering', 'Project Engineering',
            'Maintenance Engineering'
        ],
        'Finance': [
            'Banking', 'Insurance', 'Financial Planning', 'Mortgage Broking', 'Credit & Lending', 'Superannuation',
            'Investment Management', 'Payroll', 'Accounts Payable/Receivable', 'Auditing'
        ],
        'Food Processing': [
            'Meat Processing', 'Seafood Processing', 'Dairy Processing', 'Bakery', 'Beverage Manufacturing', 'Confectionery',
            'Fruit & Vegetable Processing', 'Ready Meals & Convenience Foods', 'Quality Assurance', 'Food Safety'
        ],
        'Government': [
            'Local Government', 'State Government', 'Federal Government', 'Emergency Services', 'Regulatory & Compliance',
            'Policy & Planning', 'Customer Service', 'Parks & Recreation', 'Infrastructure', 'Community Engagement'
        ],
        'Healthcare': [
            'Acute Care/Hospital', 'Primary Care/GP', 'Allied Health', 'Mental Health', 'Community Health', 'Dental', 'Pharmacy',
            'Pathology', 'Radiology', 'Emergency Services', 'Surgical', 'Rehabilitation', 'Infection Control', 'Aged Care Nursing',
            'Midwifery', 'Disability Health', 'Aboriginal Health'
        ],
        'Hospitality': [
            'Hotels & Accommodation', 'Restaurants & Cafes', 'Bars & Pubs', 'Catering', 'Events & Functions',
            'Fast Food & Quick Service', 'Clubs & Gaming', 'Commercial Cookery', 'Patisserie', 'Front Office', 'Housekeeping'
        ],
        'Information Technology': [
            'Software Development', 'Network Administration', 'Cybersecurity', 'Cloud Computing', 'Database Administration',
            'IT Support/Help Desk', 'Web Development', 'Data Analytics', 'Systems Administration', 'IT Project Management'
        ],
        'Logistics': [
            'Supply Chain Management', 'Freight Forwarding', 'Customs & Border', 'Inventory Management', 'Distribution',
            'Third-Party Logistics (3PL)', 'Last Mile Delivery', 'Cold Chain Logistics', 'Dangerous Goods'
        ],
        'Manufacturing': [
            'Food & Beverage Manufacturing', 'Pharmaceutical Manufacturing', 'Chemical Manufacturing', 'Metal Fabrication',
            'Plastics & Rubber', 'Textiles', 'Furniture Manufacturing', 'Electronics Manufacturing', 'Printing', 'Packaging',
            'Process Manufacturing'
        ],
        'Mining': [
            'Open Cut Mining', 'Underground Mining', 'Coal Mining', 'Iron Ore', 'Gold Mining', 'Mineral Processing', 'Exploration',
            'Drilling', 'Mine Site Services', 'Tailings Management', 'Mine Rehabilitation'
        ],
        'Plumbing': [
            'Domestic Plumbing', 'Commercial Plumbing', 'Industrial Plumbing', 'Gas Fitting', 'Roofing & Drainage',
            'Fire Protection Plumbing', 'Irrigation', 'Water Treatment', 'Mechanical Services'
        ],
        'Retail': [
            'Supermarkets & Grocery', 'Fashion & Apparel', 'Electronics & Technology', 'Hardware & Building', 'Pharmacy Retail',
            'Furniture & Homewares', 'Automotive Retail', 'Sporting Goods', 'Online/E-commerce', 'Luxury Retail'
        ],
        'Security': [
            'Static Security', 'Mobile Patrol', 'Event Security', 'Close Protection', 'Loss Prevention', 'Corporate Security',
            'Cash in Transit', 'CCTV & Monitoring', 'Access Control', 'Cybersecurity Operations'
        ],
        'Sport & Recreation': [
            'Fitness & Personal Training', 'Aquatics', 'Outdoor Recreation', 'Sports Coaching', 'Sports Administration',
            'Community Recreation', 'Event Management', 'Golf & Turf Management', 'Sports Medicine Support'
        ],
        'Tourism': [
            'Travel Agencies', 'Tour Operations', 'Attractions & Theme Parks', 'Eco-Tourism', 'Adventure Tourism',
            'Cultural Tourism', 'Cruise Operations', 'Tourism Marketing', 'Visitor Information Services', 'Indigenous Tourism'
        ],
        'Transport': [
            'Road Transport', 'Rail Transport', 'Maritime Transport', 'Air Transport', 'Public Transport', 'Taxi & Rideshare',
            'Courier Services', 'Bus Operations', 'Heavy Vehicle Operations', 'Transport Administration'
        ],
        'Utilities': [
            'Electricity Generation', 'Electricity Distribution', 'Gas Distribution', 'Water Supply', 'Wastewater Treatment',
            'Renewable Energy', 'Smart Grid', 'Meter Reading', 'Network Maintenance'
        ],
        'Warehousing': [
            'General Warehousing', 'Cold Storage', 'Distribution Centres', 'Cross-Docking', 'Hazardous Goods Storage',
            'Automated Warehousing', 'Order Fulfillment', 'Returns Processing', 'Inventory Control'
        ],
        'Other': ['General Industry', 'Cross-Industry', 'Emerging Industry']
    };
    /**
     * Look up the sector list for an industry.
     *
     * @param {string} industry The industry name.
     * @return {Array} The sectors for that industry, or an empty array when it has none.
     */
    function getIndustrySectors(industry) {
        return INDUSTRY_SUBCATEGORIES[industry] || [];
    }
    // ----------------------------------------------------------------------------

    /**
     * Populate the industry select from the built-in industry list.
     *
     * @return {void}
     */
    function fetchIndustries() {
        var $select = $('#industry-select');
        INDUSTRIES.forEach(function(ind) {
            $select.append($('<option>').val(ind).text(ind));
        });
        currentIndustry = $select.val() || '';
    }

    /**
     * Repopulate the state/region select when the country changes.
     *
     * @return {void}
     */
    function handleCountryChange() {
        var country = $('#country-select').val();
        var $stateSelect = $('#state-select');

        $stateSelect.empty().append($('<option>').val('').text(S.selectstate));

        if (country && countryStates[country]) {
            countryStates[country].forEach(function(state) {
                $stateSelect.append($('<option>').val(state.value).text(state.label));
            });
            $stateSelect.prop('disabled', countryStates[country].length === 0);
        } else {
            $stateSelect.prop('disabled', true);
        }
    }

    /**
     * Append an empty text source, up to the limit of ten.
     *
     * @return {void}
     */
    function addTextSource() {
        if (textSources.length >= 10) {
            kcAlert(S.errormaxtextsources);
            return;
        }
        textSources.push({
            text: '',
            questionCount: 10
        });
        renderTextSources();
    }

    /**
     * Mark one answer option as chosen, keeping the ARIA state and tab order in step.
     *
     * The options are a custom radio group, so the checked state has to be published through
     * aria-checked, and exactly one option stays in the tab order (roving tabindex) so that a
     * keyboard user tabs into the group once rather than through every option.
     *
     * @param {Object} $option The jQuery option element being selected.
     * @return {void}
     */
    function selectOption($option) {
        var $group = $option.closest('.kc-options');
        $group.find('.kc-option')
            .removeClass('selected')
            .attr('aria-checked', 'false')
            .attr('tabindex', '-1');
        $option.addClass('selected')
            .attr('aria-checked', 'true')
            .attr('tabindex', '0');
    }

    /**
     * Give an options container the keyboard behaviour expected of a radio group.
     *
     * Arrow keys move between options and select as they go, which is what a radio group does;
     * Space and Enter select the focused option. Without this the options were mouse-only.
     *
     * @param {Function} onSelect Called with the selected option element after any change.
     * @return {void}
     */
    function bindOptionKeyboard(onSelect) {
        $('#options-container').off('keydown.kc').on('keydown.kc', '.kc-option', function(e) {
            var $options = $('#options-container .kc-option').not('.disabled');
            var index = $options.index(this);
            if (index === -1) {
                return;
            }
            // Older browsers, and synthetic events, may not populate KeyboardEvent.key, so
            // fall back to the numeric code.
            var code = e.which || e.keyCode;
            var isNext = e.key === 'ArrowDown' || e.key === 'ArrowRight' || code === 40 || code === 39;
            var isPrev = e.key === 'ArrowUp' || e.key === 'ArrowLeft' || code === 38 || code === 37;
            var isPick = e.key === ' ' || e.key === 'Enter' || code === 32 || code === 13;
            var next = null;
            if (isNext) {
                next = $options.eq((index + 1) % $options.length);
            } else if (isPrev) {
                next = $options.eq((index - 1 + $options.length) % $options.length);
            } else if (isPick) {
                next = $options.eq(index);
            } else {
                return;
            }
            e.preventDefault();
            next.trigger('focus');
            selectOption(next);
            onSelect(next);
        });
    }

    /**
     * Record the chosen option and enable whichever button comes next.
     *
     * @param {Object} $option The chosen option element.
     * @return {void}
     */
    function applyOptionSelection($option) {
        selectedAnswer = parseInt($option.data('index'), 10);
        if (config.surveyMode) {
            $('#next-question-btn').prop('disabled', false);
        } else {
            $('#check-answer-btn').prop('disabled', false);
        }
    }

    /**
     * Move focus to the question heading so a screen reader announces the new question.
     *
     * Without this the focus stays on the button that was just pressed and the reader says
     * nothing about the question that replaced it.
     *
     * @return {void}
     */
    function focusQuestion() {
        var heading = document.getElementById('question-text');
        if (heading) {
            heading.setAttribute('tabindex', '-1');
            heading.focus({preventScroll: true});
        }
    }

    /**
     * The selectable narrator voices for a gender, as value/label pairs.
     *
     * The same list used to be written out in three places, which is how the labels drifted
     * apart from the ones view.php renders. Both now come from the language pack.
     *
     * @param {string} gender 'female' or anything else for male.
     * @return {Array} Objects with value and label.
     */
    function voiceOptions(gender) {
        if (gender === 'female') {
            return [
                {value: 'Aoede', label: S.voiceaoede},
                {value: 'Kore', label: S.voicekore},
                {value: 'Leda', label: S.voiceleda},
                {value: 'Zephyr', label: S.voicezephyr}
            ];
        }
        return [
            {value: 'Puck', label: S.voicepuck},
            {value: 'Charon', label: S.voicecharon},
            {value: 'Fenrir', label: S.voicefenrir},
            {value: 'Orus', label: S.voiceorus}
        ];
    }

    /**
     * Replace a select's options with the voices available for a gender.
     *
     * @param {Object} $select The jQuery select element.
     * @param {string} gender 'female' or anything else for male.
     * @return {void}
     */
    function fillVoiceOptions($select, gender) {
        $select.empty();
        voiceOptions(gender).forEach(function(voice) {
            $select.append($('<option>').val(voice.value).text(voice.label));
        });
    }

    /**
     * Build the 1-30 question-count <option> markup for a text source select.
     *
     * @param {number} selected The currently selected question count.
     * @return {string} The option elements as an HTML string.
     */
    function questionCountOptions(selected) {
        var opts = '';
        for (var q = 1; q <= 30; q++) {
            opts += '<option value="' + q + '"' + (selected === q ? ' selected' : '') + '>' + q + ' Qs</option>';
        }
        return opts;
    }

    /**
     * Render the text-source cards from the current textSources array.
     *
     * @return {void}
     */
    function renderTextSources() {
        var $container = $('#text-sources-container');
        $container.empty();

        for (var i = 0; i < textSources.length; i++) {
            var source = textSources[i];
            var sourceNum = i + 1;
            var charCount = source.text ? source.text.length : 0;
            var html = '<div class="kc-text-source-item">' +
                '<div class="kc-text-source-header">' +
                    '<span class="kc-text-source-label">Text source ' + sourceNum + '</span>' +
                    '<div class="kc-text-source-controls">' +
                        '<select class="kc-select kc-text-source-questions" data-index="' + i + '">' +
                            questionCountOptions(source.questionCount) +
                        '</select>' +
                        (textSources.length > 1
                            ? '<button type="button" class="kc-text-source-remove" data-index="' + i + '" title="Remove">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" strok' +
                                'e="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                                '<line x1="18" y1="6" x2="6" y2="18"/>' +
                                '<line x1="6" y1="6" x2="18" y2="18"/>' +
                            '</svg>' +
                        '</button>' : '') +
                    '</div>' +
                '</div>' +
                '<textarea class="kc-text-source-textarea" data-index="' +
                     i +
                     '" rows="6" placeholder="Paste your text content here...">' +
                     escapeHtml(source.text) +
                     '</textarea>' +
                '<div class="kc-text-source-footer">' +
                    '<span class="kc-text-source-charcount">' + charCount.toLocaleString() + ' characters</span>' +
                    '<span class="kc-text-source-limit">Max 50,000</span>' +
                '</div>' +
            '</div>';
            $container.append(html);
        }
    }

    /**
     * Recalculate the question count and credit cost and update the summary line.
     *
     * @return {void}
     */
    function updateStats() {
        var useTextSources = $('#paste-content-toggle').is(':checked');
        var useOwnQuestions = $('#user-questions-toggle').is(':checked');
        var totalQuestions = 0;

        if (useTextSources && textSources.length > 0) {
            totalQuestions = 0;
            for (var p = 0; p < textSources.length; p++) {
                if (textSources[p].text && textSources[p].text.trim().length > 0) {
                    totalQuestions += textSources[p].questionCount;
                }
            }
        } else if (useOwnQuestions) {
            var userQuestions = $('#user-questions-input').val().trim().split('\n').filter(function(q) {
                return q.trim();
            });
            totalQuestions = userQuestions.length;
        } else {
            var topics = $('#topics-input').val().trim().split('\n').filter(function(t) {
                return t.trim();
            });
            var questionsPerTopic = parseInt($('#questions-per-topic').val(), 10);
            totalQuestions = topics.length * questionsPerTopic;
        }

        var voiceoverOn = $('#voiceover-toggle').is(':checked');
        var creditsPerQuestion = voiceoverOn ? 2 : 1;
        var credits = totalQuestions * creditsPerQuestion;
        var audAmount = (credits / 10).toFixed(2);

        var formulaHtml = '<strong>' +
             totalQuestions +
             ' questions</strong> x ' +
             creditsPerQuestion +
             ' credit' +
             (creditsPerQuestion > 1 ? 's' : '') +
             ' = <strong>' +
             credits.toLocaleString() +
             ' credits</strong> ($' +
             audAmount +
             ' AUD)';
        $('#kc-credit-formula').html(formulaHtml);
        $('#kc-progress-credit-formula').html(formulaHtml);

        if (totalQuestions > 0) {
            $('#preview-stats').show();
            $('#generate-btn').prop('disabled', false);
        } else {
            $('#preview-stats').hide();
            $('#generate-btn').prop('disabled', true);
        }
    }

    /**
     * Validate the generation form and submit the generate request.
     *
     * @param {Event} e The submit event.
     * @return {void}
     */
    function handleGenerate(e) {
        e.preventDefault();

        var useTextSources = $('#paste-content-toggle').is(':checked');
        var useOwnQuestions = $('#user-questions-toggle').is(':checked');
        var topics = '';
        var userQuestions = '';

        if (useTextSources) {
            var validSources = textSources.filter(function(s) {
                return s.text && s.text.trim().length > 0;
            });
            if (validSources.length === 0) {
                kcAlert(S.errornotextsourcecontent);
                return;
            }
            topics = 'Pasted content';
        } else if (useOwnQuestions) {
            userQuestions = $('#user-questions-input').val().trim();
            if (!userQuestions) {
                kcAlert(S.errornoquestionsentered);
                return;
            }
            topics = 'User-provided questions';
        } else {
            topics = $('#topics-input').val().trim();
            if (!topics) {
                kcAlert(S.errornotopicsentered);
                return;
            }
        }

        // Get workplace context if enabled.
        var workplaceContextEnabled = $('#workplace-context-toggle').is(':checked') ? 1 : 0;

        // Get education settings.
        var educationType = $('#education-type-select').val();
        var vetLevel = educationType === 'vet' ? $('#vet-level-select').val() : '';
        var academicLevel = educationType === 'academic' ? $('#academic-level-select').val() : '';

        $('#kc-form-section').hide();
        $('#kc-progress-section').show();
        $('#progress-fill').css('width', '5%');
        $('#progress-message').text(S.startingeneration);

        var data = {
            cmid: parseInt(config.cmid, 10),
            topics: topics,
            questionsPerTopic: useOwnQuestions ? 1 : (parseInt($('#questions-per-topic').val(), 10) || 5),
            useOwnQuestions: useOwnQuestions ? 1 : 0,
            userQuestions: userQuestions,
            useTextSources: useTextSources ? 1 : 0,
            textSources: '',
            workplaceContextEnabled: workplaceContextEnabled,
            country: workplaceContextEnabled ? ($('#country-select').val() || '') : '',
            state: workplaceContextEnabled ? ($('#state-select').val() || '') : '',
            industry: workplaceContextEnabled ? ($('#industry-select').val() || '') : '',
            industryDetails: workplaceContextEnabled ? ($('#industry-sector').val() || '') : '',
            jobLevel: workplaceContextEnabled ? selectedKcJobLevels.join(', ') : '',
            jobTitle: workplaceContextEnabled ? selectedKcJobRoles.join(', ') : '',
            educationType: educationType,
            vetLevel: vetLevel,
            academicLevel: academicLevel,
            extraInstructions: $('#extra-instructions').val() || '',
            voiceoverEnabled: $('#voiceover-toggle').is(':checked') ? 1 : 0,
            voiceLanguage: $('#voice-language').val() || 'en-AU',
            voiceGender: $('#voice-gender').val() || 'female',
            voiceId: $('#voice-style').val() || 'Zephyr',
            // ADD-SURVEY-MODE (v1.5.126): Forward survey params to the generation service.
            surveyMode: config.surveyMode ? 1 : 0,
            // FIX-KC-SURVEY-SCALE (v1.5.140): read the scale from the activity config, not from
            // a '#survey-scale' element. The teacher picks the Response Scale in the activity
            // settings form (mod_form.php); no such element has ever existed on the view page,
            // so .val() always returned undefined and the '|| likert5agree' fallback fired on
            // every generation. Every scale other than the first silently produced Agreement
            // questions. view.php already supplies the real value as config.surveyScale.
            surveyScale: config.surveyMode ? (config.surveyScale || 'likert5agree') : 'likert5agree',
            // ADD-SURVEY-FREETEXT (v1.5.127): Forward free-text questions (one per line).
            freetextQuestions: config.surveyMode
                ? JSON.stringify(($('#freetext-questions-input').val() || '').split('\n').map(function(s) {
                    return s.trim();
                    }).filter(function(s) {
                    return s.length > 0;
                }))
                : '[]'
        };

        if (useTextSources) {
            var validSources = textSources.filter(function(s) {
                return s.text && s.text.trim().length > 0;
            });
            data.textSources = JSON.stringify(validSources.map(function(s) {
                return {text: s.text.trim().substring(0, 50000), questionCount: s.questionCount};
            }));
            kcLog('[KC] Text sources mode - sending through the generate service');
            kcLog('[KC] Text sources:', validSources.length);
            validSources.forEach(function(s, i) {
                kcLog('[KC] Source ' + (i + 1) + ': ' + s.text.length + ' chars, questions: ' + s.questionCount);
            });
        } else {
            kcLog('[KC] Topics mode - sending through the generate service');
            kcLog('[KC] Topics: "' + topics.substring(0, 100) + '", questionsPerTopic: ' +
                (useOwnQuestions ? 1 : $('#questions-per-topic').val()));
        }

        // MIGRATE-EXTERNAL-SERVICES (v1.5.152): generate now runs through the declared
        // mod_aiknowledgecheck_generate service. unwrapService() restores the generation
        // service's own document, so handleGenerateSuccess is unchanged.
        Ajax.call([{
            methodname: 'mod_aiknowledgecheck_generate',
            args: data
        }])[0].done(function(response) {
            handleGenerateSuccess(unwrapService(response));
        }).fail(handleGenerateError);
    }

    /**
     * Handle the response to a generate request: start polling, or report why it failed.
     *
     * @param {Object} response The service response.
     * @return {void}
     */
    function handleGenerateSuccess(response) {
        kcLog('[KC] Generate response:', JSON.stringify(response));
        if (response.ok && response.jobId) {
            kcLog('[KC] Job started: ' + response.jobId + ', credits: ' + response.creditsRequired +
                ', questions: ' + response.totalQuestions);
            currentJobId = response.jobId;
            startStatusPolling();
        } else if (response.error === 'INSUFFICIENT_CREDITS') {
            kcWarn('[KC] Insufficient credits - has: ' + response.credits + ', needs: ' + response.required);
            kcAlert(S.errorinsufficientcredits);
            $('#kc-progress-section').hide();
            $('#kc-form-section').show();
        } else {
            kcError('[KC] Generation failed:', response.error || 'Unknown error');
            kcAlert(response.error || S.errorgenerationstartfailed);
            $('#kc-progress-section').hide();
            $('#kc-form-section').show();
        }
    }

    /**
     * Failure handler for the generate call.
     *
     * MIGRATE-EXTERNAL-SERVICES (v1.5.152): this took jQuery's (xhr, status, error) triple.
     * A core/ajax rejection passes ONE Moodle exception object instead — {message, errorcode,
     * ...} — so every branch that read `status` or `xhr.responseText` was unreachable and the
     * user always saw the generic fallback, with the server's own message discarded. It now
     * reads that object, and keeps the timeout and connection cases by matching on errorcode.
     *
     * @param {Object} err the Moodle exception object from core/ajax.
     */
    function handleGenerateError(err) {
        kcError('[KC] Generate request failed:', err);
        var msg = S.errorrequestfailed;
        var code = (err && err.errorcode) ? String(err.errorcode) : '';
        if (code === 'servicerequirestimeout' || code.indexOf('timeout') !== -1) {
            msg = 'Request timed out. The source content may be too large. Please try a smaller file.';
        } else if (code === 'servicerequireslogin' || code === 'sessionerror' || code === 'requireloginerror') {
            msg = 'Your session has expired. Please reload the page and sign in again.';
        } else if (err && err.message) {
            msg = err.message;
        }
        if (isAddingMore && existingQuizData) {
            quizData = existingQuizData;
            existingQuizData = null;
            isAddingMore = false;
            $('#kc-add-more-banner').hide();
            kcAlert(fmt(S.errorexistingpreserved, msg));
            $('#kc-progress-section').hide();
            showQuizReady();
        } else {
            kcAlert(msg);
            $('#kc-progress-section').hide();
            $('#kc-form-section').show();
        }
    }

    /**
     * Begin polling the generation job for progress.
     *
     * @return {void}
     */
    function startStatusPolling() {
        statusPollFailures = 0;
        statusPollingInterval = setInterval(checkStatus, 2000);
    }

    /**
     * MIGRATE-EXTERNAL-SERVICES (v1.5.148): poll a generation job via the declared
     * External Service. The service returns the upstream document untouched as a JSON
     * string (see the design note in classes/external/get_generation_status.php), so it
     * is parsed here. onOk receives the parsed status document exactly as the legacy
     * ajax.php endpoint delivered it, keeping both callers unchanged in shape.
     *
     * @param {string} jobId Generation job identifier.
     * @param {Function} onOk Called with the parsed status document.
     * @param {Function} onErr Called with an Error on transport or parse failure.
     */
    function pollGenerationStatus(jobId, onOk, onErr) {
        Ajax.call([{
            methodname: 'mod_aiknowledgecheck_get_generation_status',
            args: {cmid: parseInt(config.cmid, 10), jobid: jobId}
        }])[0].done(function(res) {
            if (!res || !res.ok) {
                onErr(new Error((res && res.error) || S.statuscheckfailed));
                return;
            }
            var parsed;
            try {
                parsed = JSON.parse(res.payload);
            } catch (e) {
                onErr(new Error('Could not parse the generation status response'));
                return;
            }
            onOk(parsed || {});
        }).fail(function(err) {
            onErr(new Error((err && err.message) ? err.message : S.statuscheckfailed));
        });
    }

    /**
     * Poll the generation job once and act on its current status.
     *
     * @return {void}
     */
    function checkStatus() {
        pollGenerationStatus(currentJobId, function(response) {
            {
                statusPollFailures = 0;
                if (response.ok) {
                    $('#progress-fill').css('width', response.progress + '%');
                    $('#progress-message').text(response.progressMessage);

                    if (response.status === 'completed') {
                        clearInterval(statusPollingInterval);
                        var newQuestions = response.questions || [];

                        // FIX-KC-ZERO-Q-GUARD: if the server completes but returns an empty
                        // question list (e.g. large audio payload caused PHP json_encode to fail
                        // silently before the streaming fix), show a meaningful error instead
                        // of displaying "0 questions generated with voiceover!".
                        if (newQuestions.length === 0) {
                            kcError('[KC] Job completed but returned 0 questions  -  possible server-side error. Check server log' +
                                's.');
                            if (isAddingMore && existingQuizData) {
                                quizData = existingQuizData;
                                existingQuizData = null;
                                isAddingMore = false;
                                $('#kc-add-more-banner').hide();
                                kcAlert(S.errorzeroquestionspreserved);
                                $('#kc-progress-section').hide();
                                showQuizReady();
                            } else {
                                kcAlert(S.errorzeroquestions);
                                $('#kc-progress-section').hide();
                                $('#kc-form-section').show();
                            }
                            return;
                        }

                        // Tag each new question with its topic and criteria from the form
                        // so they are persisted to DB and appear in the Excel mapping.
                        var topicLines = $('#topics-input').val().trim().split('\n').filter(function(l) {
                            return l.trim();
                        });
                        var criteriaLines = $('#criteria-input').val().trim().split('\n');
                        var qpt = parseInt($('#questions-per-topic').val(), 10) || 1;
                        newQuestions.forEach(function(q, idx) {
                            var topicIdx = Math.floor(idx / qpt);
                            if (!q.mappingTopic) {
                                q.mappingTopic = (topicLines[topicIdx] || '').trim();
                            }
                            if (!q.mappingCriteria) {
                                q.mappingCriteria = (criteriaLines[topicIdx] || '').trim();
                            }
                        });

                        if (isAddingMore && existingQuizData) {
                            kcLog('[KC] Add More  -  appending ' + newQuestions.length +
                                ' new questions to ' + existingQuizData.length + ' existing');
                            quizData = existingQuizData.concat(newQuestions);
                            existingQuizData = null;
                            isAddingMore = false;
                            $('#kc-add-more-banner').hide();
                        } else {
                            quizData = newQuestions;
                        }

                        showQuizReady();
                    } else if (response.status === 'failed') {
                        clearInterval(statusPollingInterval);
                        if (isAddingMore && existingQuizData) {
                            quizData = existingQuizData;
                            existingQuizData = null;
                            isAddingMore = false;
                            $('#kc-add-more-banner').hide();
                            kcAlert(fmt(S.errorexistingpreserved, response.error || S.errorgenerationfailed));
                            $('#kc-progress-section').hide();
                            showQuizReady();
                        } else {
                            kcAlert(response.error || S.errorgenerationfailed);
                            $('#kc-progress-section').hide();
                            $('#kc-form-section').show();
                        }
                    }
                }
            }
        }, function(err) {
            {
                statusPollFailures++;
                kcError(
                    'Status check failed (attempt ' + statusPollFailures + '/' + MAX_POLL_FAILURES + '):',
                    err && err.message ? err.message : err
                );
                if (statusPollFailures >= MAX_POLL_FAILURES) {
                    clearInterval(statusPollingInterval);
                    kcError('[KC] Status polling stopped after ' + MAX_POLL_FAILURES + ' consecutive failures');
                    if (isAddingMore && existingQuizData) {
                        quizData = existingQuizData;
                        existingQuizData = null;
                        isAddingMore = false;
                        $('#kc-add-more-banner').hide();
                        kcAlert(S.errorlostconnectionpreserved);
                        $('#kc-progress-section').hide();
                        showQuizReady();
                    } else {
                        kcAlert(S.errorlostconnectiongenerating);
                        $('#kc-progress-section').hide();
                        $('#kc-form-section').show();
                    }
                }
            }
        });
    }

    /**
     * Switch the teacher view to the quiz-ready screen.
     *
     * @return {void}
     */
    function showQuizReady() {
        // FIX-KC-GUARD-SHOWQUIZREADY: Only persist to DB when there are actually questions.
        // Calling saveQuestionsToDatabase() with an empty quizData would DELETE all existing
        // DB questions (the savequestions action does DELETE then INSERT).
        if (quizData && quizData.length > 0) {
            saveQuestionsToDatabase();
        } else {
            kcWarn('[KC] showQuizReady called with empty quizData  -  skipping DB save to protect existing questions.');
        }

        $('#kc-progress-section').hide();
        $('#kc-ready-section').show();
        var voiceoverOn = $('#voiceover-toggle').is(':checked');
        var qCount = quizData ? quizData.length : 0;
        if (voiceoverOn) {
            $('#ready-summary').text(qCount + ' questions generated with voiceover!');
        } else {
            $('#ready-summary').text(qCount + ' questions generated successfully!');
        }
        var initialInstructions = $('#extra-instructions').val() || '';
        $('#ready-extra-instructions').val(initialInstructions);
        $('#edit-extra-instructions').val(initialInstructions);
        updateRegenCountDisplay();
        fetchCredits();
    }

    /**
     * Return to the generation form with the existing questions preserved, to add more.
     *
     * @return {void}
     */
    function handleAddMoreQuestions() {
        if (!quizData || quizData.length === 0) {
            kcAlert(S.errornoexistingquestions);
            return;
        }
        kcLog('[KC] Add More Questions  -  preserving ' + quizData.length + ' existing questions');
        existingQuizData = quizData.slice();
        isAddingMore = true;
        $('#kc-ready-section').hide();
        $('#kc-form-section').show();
        var $banner = $('#kc-add-more-banner');
        if (!$banner.length) {
            var bannerHtml = '<div id="kc-add-more-banner" class="kc-add-more-info">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentCo' +
                    'lor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>' +
                '</svg>' +
                '<span>Adding to your existing <strong>' +
                     existingQuizData.length +
                     ' question' +
                     (existingQuizData.length !== 1 ? 's' : '') +
                     '</strong>. New questions will be appended to the current set.</span>' +
                '</div>';
            $('#kc-form').prepend(bannerHtml);
        } else {
            $banner.find('span').html('Adding to your existing <strong>' + existingQuizData.length +
                ' question' + (existingQuizData.length !== 1 ? 's' : '') +
                '</strong>. New questions will be appended to the current set.');
            $banner.show();
        }
    }

    // FIX-KC-SAVE-SILENT: v1.5.68  -  show visible alert to teacher when save fails,
    // so they know to retry rather than thinking "Quiz Ready!" means questions are live.
    /**
     * Persist the generated questions to the activity.
     *
     * @return {void}
     */
    function saveQuestionsToDatabase() {
        // Transform quizData to match database schema
        // FIX-KC-SURVEY-SAVE (v1.5.138): three faults lived in this one mapping.
        //
        // 1. It read q.options[n] as a STRING. Freshly generated questions arrive straight from
        //    the generation service through the 'status' passthrough, which emits options as
        //    {text, explanation} OBJECTS -- the same shape this file sends back in the
        //    regenerate payload, described there as "the API's expected input format". So
        //    { text: q.options[0] } nested an object inside .text, PHP received an array where
        //    it expected a string, and the insert died inside mysqli with
        //    "real_escape_string(): Argument #1 ($string) must be of type string, array given".
        //
        // 2. It hardcoded exactly four options. A five-point survey scale silently lost its
        //    fifth, and a freetext question -- which has none -- gained four empty ones.
        //
        // 3. It never sent questionType at all, so the server fell back to 'scale' and every
        //    freetext question was stored as a scale question.
        //
        // normaliseOption() accepts either shape, so generated, reloaded and hand-edited
        // questions all save the same way.
        /**
         * Normalise one answer option into KC's flat internal shape.
         *
         * @param {Object|string|null} opt The option as returned by the API.
         * @param {string} fallbackExplanation Explanation to use when the option carries none.
         * @return {Object|null} The normalised option, or null when opt was empty.
         */
        function normaliseOption(opt, fallbackExplanation) {
            if (opt === null || opt === undefined) {
                return null;
            }
            if (typeof opt === 'object') {
                return {
                    text: typeof opt.text === 'string' ? opt.text : String(opt.text || ''),
                    explanation: typeof opt.explanation === 'string'
                        ? opt.explanation
                        : (fallbackExplanation || '')
                };
            }
            return {text: String(opt), explanation: fallbackExplanation || ''};
        }

        var questionsForDb = quizData.map(function(q) {
            // Debug: log audio data being saved
            if (q.audioData) {
                kcLog('[KC] Saving question with audio data:', Object.keys(q.audioData).length, 'tracks');
            } else {
                kcLog('[KC] Saving question without audio data');
            }

            var isFreetext = q.questionType === 'freetext';
            var rawOptions = (!isFreetext && Array.isArray(q.options)) ? q.options : [];
            var options = [];
            for (var oi = 0; oi < rawOptions.length; oi++) {
                var normalised = normaliseOption(
                    rawOptions[oi],
                    (q.explanations && q.explanations[oi]) ? q.explanations[oi] : ''
                );
                if (normalised !== null) {
                    options.push(normalised);
                }
            }

            return {
                question: typeof q.question === 'string' ? q.question : String(q.question || ''),
                options: options,
                questionType: isFreetext ? 'freetext' : 'scale',
                correctIndex: (typeof q.correctAnswer === 'number') ? q.correctAnswer : 0,
                audioData: q.audioData || null,
                mappingTopic: q.mappingTopic || '',
                mappingCriteria: q.mappingCriteria || '',
                timestamp_seconds: (q.timestamp_seconds !== undefined && q.timestamp_seconds !== null) ? q.timestamp_seconds : null,
                // ADD-KC-IMAGEGATE (v1.5.115): Include per-question image data.
                imageUrl: q.imageUrl || '',
                imageEnabled: q.imageEnabled ? 1 : 0,
                // ADD-KC-MEDIAPER-Q (v1.5.120): Include per-question video and audio data.
                questionVideoUrl: q.questionVideoUrl || '',
                questionVideoEnabled: q.questionVideoEnabled ? 1 : 0,
                questionAudioUrl: q.questionAudioUrl || '',
                questionAudioEnabled: q.questionAudioEnabled ? 1 : 0
            };
        });

        // MIGRATE-EXTERNAL-SERVICES (v1.5.152): savequestions now runs through the declared
        // mod_aiknowledgecheck_save_questions service.
        Ajax.call([{
            methodname: 'mod_aiknowledgecheck_save_questions',
            args: {
                cmid: parseInt(config.cmid, 10),
                questions: JSON.stringify(questionsForDb),
                voiceoverEnabled: $('#voiceover-toggle').is(':checked') ? 1 : 0,
                voiceLanguage: $('#voice-language').val() || '',
                voiceGender: $('#voice-gender').val() || '',
                voiceStyle: $('#voice-style').val() || ''
            }
        }])[0].done(function(response) {
                if (response.ok) {
                    kcLog('[KC] Questions saved to database:', response.saved);
                } else {
                    kcError('[KC] Failed to save questions:', response.error);
                    kcAlert(fmt(S.errorsavetomoodlefailed, response.error || S.errorunknown));
                }
        }).fail(function(err) {
            kcError('[KC] Save questions request failed:', err);
            kcAlert(S.errorsaveconnectionlost);
        });
    }

    // Regenerate audio for existing questions (FREE - no credit cost)
    /**
     * Regenerate the explanation audio for every question in the activity.
     *
     * @return {void}
     */
    function regenerateAudio() {
        kcLog('[KC] Regenerating audio for existing questions');

        // Get current voice settings
        var voiceLanguage = $('#voice-language').val() || 'en-AU';
        var voiceId = $('#voice-style').val() || 'Aoede';

        // Show progress
        $('#regenerate-audio-btn').prop('disabled', true).text(S.generatingaudio);

        // Prepare questions data for the API
        var questionsForApi = quizData.map(function(q) {
            return {
                id: q.id,
                question: q.question,
                options: q.options,
                explanations: q.explanations,
                correctAnswer: q.correctAnswer
            };
        });

        // MIGRATE-EXTERNAL-SERVICES (v1.5.152): regenerateaudio now runs through the declared
        // mod_aiknowledgecheck_regenerate_audio service.
        Ajax.call([{
            methodname: 'mod_aiknowledgecheck_regenerate_audio',
            args: {
                cmid: parseInt(config.cmid, 10),
                questions: JSON.stringify(questionsForApi),
                voiceLanguage: voiceLanguage,
                voiceId: voiceId
            }
        }])[0].done(function(envelope) {
            var response = unwrapService(envelope);
                if (response.ok && response.questions) {
                    kcLog('[KC] Audio regenerated successfully for', response.questions.length, 'questions');

                    // Update quizData with new audio
                    for (var i = 0; i < response.questions.length; i++) {
                        if (quizData[i] && response.questions[i].audioData) {
                            quizData[i].audioData = response.questions[i].audioData;
                            kcLog('[KC] Question', i, 'now has', response.questions[i].audioData.length, 'audio tracks');
                        }
                    }

                    // Save updated questions to database
                    saveQuestionsToDatabase();

                    // Update UI
                    $('#regenerate-audio-btn').remove();
                    $('#ready-summary').text(quizData.length + ' questions ready with voiceover audio!');
                    kcAlert(S.successaudiogenerated);
                } else {
                    kcError('[KC] Audio regeneration failed:', response.error);
                    kcAlert(fmt(S.erroraudiogenfaileddetail, response.error || S.errorunknown));
                    $('#regenerate-audio-btn').prop('disabled', false).text(S.generateaudio);
                }
        }).fail(function(err) {
            kcError('[KC] Audio regeneration request failed:', err);
            kcAlert(S.erroraudiogenfailed);
            $('#regenerate-audio-btn').prop('disabled', false).text(S.generateaudio);
        });
    }

    // ==========================================
    // STUDENT FUNCTIONS - Start/Continue Attempt
    // ==========================================

    /**
     * Start (or resume) a student attempt and open the first question.
     *
     * @return {void}
     */
    function handleStartAttempt() {
        kcLog('[KC] Starting new attempt');
        pendingSaves = 0;
        pendingFinishAttempt = false;
        $('#start-attempt-btn').prop('disabled', true).text(S.loading);

        // MIGRATE-EXTERNAL-SERVICES (v1.5.152): startattempt now runs through the declared
        // mod_aiknowledgecheck_start_attempt service.
        Ajax.call([{
            methodname: 'mod_aiknowledgecheck_start_attempt',
            args: {cmid: parseInt(config.cmid, 10)}
        }])[0].done(function(response) {
            if (response.ok) {
                currentAttemptId = response.attemptid;
                kcLog('[KC] Attempt started:', currentAttemptId);
                loadQuestionsFromDatabase();
            } else {
                kcAlert(response.error || S.errorstartattemptfailed);
                $('#start-attempt-btn').prop('disabled', false).text(S.startquiz);
            }
        }).fail(function(err) {
            kcError('[KC] Start attempt failed:', err);
            kcAlert(S.errorstartquizfailed);
            $('#start-attempt-btn').prop('disabled', false).text(S.startquiz);
        });
    }

    /**
     * Resume the student's in-progress attempt at the question they had reached.
     *
     * @return {void}
     */
    function handleContinueAttempt() {
        kcLog('[KC] Continuing attempt');
        $('#continue-attempt-btn').prop('disabled', true).text(S.loading);

        // Call startattempt to get the authoritative server-side progress.
        // This returns the existing in-progress attempt with the answers dict.
        // We derive resumeFromIndex from the number of answered questions, which
        // correctly reflects shuffled position regardless of original questionnumber.
        // MIGRATE-EXTERNAL-SERVICES (v1.5.152): startattempt now runs through the declared
        // mod_aiknowledgecheck_start_attempt service.
        Ajax.call([{
            methodname: 'mod_aiknowledgecheck_start_attempt',
            args: {cmid: parseInt(config.cmid, 10)}
        }])[0].done(function(response) {
                if (response.ok) {
                    currentAttemptId = response.attemptid;
                    kcLog('[KC] Continue attempt ID confirmed:', currentAttemptId, 'resumed:', response.resumed);

                    // MIGRATE-EXTERNAL-SERVICES (v1.5.152): the answers map is keyed by
                    // question ID, which no external_*_structure can describe, so it crosses
                    // as a JSON string and is parsed here.
                    var serverAnswers = parseAnswersJson(response.answersjson);

                    // Determine resume position: answers.length = number of questions
                    // already answered, which is the correct 0-based index to restart from.
                    var serverAnswerCount = Object.keys(serverAnswers).length;

                    // Also check localStorage in case the student advanced past the last save point.
                    var storageKey = 'kc_progress_' + config.cmid + '_' + currentAttemptId;
                    var saved = localStorage.getItem(storageKey);
                    var localIdx = (saved !== null) ? parseInt(saved, 10) : 0;
                    if (isNaN(localIdx) || localIdx < 0) {
                        localIdx = 0;
                    }

                    // Use whichever is further along.
                    resumeFromIndex = Math.max(serverAnswerCount, localIdx);
                    kcLog(
                        '[KC] Resume index  -  server answers:',
                        serverAnswerCount,
                        'localStorage:',
                        localIdx,
                        'using:',
                        resumeFromIndex
                    );

                    // BUG-SCORE-RESUME fix: save the server's answers dict so
                    // startStudentQuiz() can reconstruct the score from previously
                    // answered questions instead of always resetting score to 0.
                    resumeAnswers = (Object.keys(serverAnswers).length > 0) ? serverAnswers : null;

                    loadQuestionsFromDatabase();
                } else {
                    kcError('[KC] Continue attempt failed:', response.error);
                    kcAlert(response.error || S.errorcontinueattemptreload);
                    $('#continue-attempt-btn').prop('disabled', false).text(S.continueattempt);
                }
        }).fail(function(err) {
            kcError('[KC] Continue attempt request failed:', err);
            kcAlert(S.errorcontinueattemptfailed);
            $('#continue-attempt-btn').prop('disabled', false).text(S.continueattempt);
        });
    }

    /**
     * Parse the answers map returned by mod_aiknowledgecheck_start_attempt.
     *
     * MIGRATE-EXTERNAL-SERVICES (v1.5.152): the map is keyed by question ID, so its keys vary
     * per activity and no external_*_structure can describe it — external_single_structure
     * needs a fixed key set and external_multiple_structure would throw the keys away. It
     * therefore crosses as a JSON string. PHP encodes an empty map as '[]' rather than '{}';
     * both parse to something Object.keys() reports as empty, so callers see no difference.
     *
     * @param {string} raw the JSON string from the service.
     * @returns {Object} the answers map, or an empty object if absent or malformed.
     */
    function parseAnswersJson(raw) {
        if (typeof raw !== 'string' || raw === '') {
            return {};
        }
        try {
            var parsed = JSON.parse(raw);
            return (parsed && typeof parsed === 'object') ? parsed : {};
        } catch (e) {
            kcError('[KC] Could not parse answers payload:', e);
            return {};
        }
    }

    /**
     * Unwrap the {ok, error, resultjson} envelope the generation-proxy services return.
     *
     * MIGRATE-EXTERNAL-SERVICES (v1.5.152): the generation service's own responses are
     * free-form documents whose shape it owns and can extend, so no external_*_structure can
     * describe them without pinning a shape the plugin does not control. They cross verbatim
     * as a JSON string, which also preserves the FIX-KC-REGEN-STREAM (v1.5.90) property that
     * a large base64 audioData array is never decoded and re-encoded on the way through.
     * This restores the inner document, so every handler downstream is unchanged.
     *
     * @param {Object} response the service envelope.
     * @returns {Object} the generation service's own response document.
     */
    function unwrapService(response) {
        if (!response || !response.ok) {
            return {ok: false, error: (response && response.error) || 'Request failed'};
        }
        try {
            var parsed = JSON.parse(response.resultjson);
            return (parsed && typeof parsed === 'object') ? parsed : {ok: false, error: 'Invalid response'};
        } catch (e) {
            kcError('[KC] Could not parse service payload:', e);
            return {ok: false, error: 'Invalid response'};
        }
    }

    // Fisher-Yates shuffle algorithm - returns shuffled indices
    /**
     * Build a Fisher-Yates shuffled list of indices.
     *
     * @param {number} length How many indices to produce.
     * @return {Array} The indices 0..length-1 in random order.
     */
    function getShuffledIndices(length) {
        var indices = [];
        for (var i = 0; i < length; i++) {
            indices.push(i);
        }
        // Fisher-Yates shuffle
        for (var i = length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var temp = indices[i];
            indices[i] = indices[j];
            indices[j] = temp;
        }
        return indices;
    }

    /**
     * Load the saved questions for this activity from the server.
     *
     * @return {void}
     */
    function loadQuestionsFromDatabase() {
        kcLog('[KC] Loading questions from database');

        // MIGRATE-EXTERNAL-SERVICES (v1.5.148): getquestions now runs through the declared
        // mod_aiknowledgecheck_get_questions service. Ajax.call resolves with the payload
        // directly, so the old jQuery success/error pair becomes done/fail.
        Ajax.call([{
            methodname: 'mod_aiknowledgecheck_get_questions',
            args: {cmid: parseInt(config.cmid, 10)}
        }])[0].done(function(response) {
                if (response.ok && response.questions && response.questions.length > 0) {
                    kcLog('[KC] Loaded questions:', response.questions.length);

                    // Transform database format to quiz format with shuffled answers
                    quizData = response.questions.map(function(q) {
                        // Debug: log audio data availability
                        if (q.audioData) {
                            kcLog('[KC] Question', q.id, 'has audio data for', Object.keys(q.audioData).length, 'answers');
                        } else {
                            kcLog('[KC] Question', q.id, 'has no audio data');
                        }

                        // ADD-SURVEY-FREETEXT (v1.5.127): Freetext questions have no options — bypass shuffle.
                        if (q.questionType === 'freetext') {
                            return {
                                id: q.id,
                                question: q.question,
                                options: [],
                                explanations: [],
                                correctAnswer: 0,
                                originalCorrectIndex: 0,
                                shuffledToOriginal: [],
                                audioData: null,
                                timestamp_seconds: null,
                                imageUrl: '',
                                imageEnabled: false,
                                questionVideoUrl: '',
                                questionVideoEnabled: false,
                                questionAudioUrl: '',
                                questionAudioEnabled: false,
                                questionType: 'freetext'
                            };
                        }

                        // Quiz answers are shuffled. Survey scales retain their authored order
                        // (for example Strongly Agree through Strongly Disagree), and may have
                        // two, three, four, or five options.
                        var optionCount = Array.isArray(q.options) ? q.options.length : 0;
                        var shuffledIndices = [];
                        if (config.surveyMode) {
                            for (var optionIndex = 0; optionIndex < optionCount; optionIndex++) {
                                shuffledIndices.push(optionIndex);
                            }
                        } else {
                            shuffledIndices = getShuffledIndices(optionCount);
                        }

                        // Build shuffled arrays and mapping from shuffled position to original
                        var shuffledOptions = [];
                        var shuffledExplanations = [];
                        var shuffledAudioData = q.audioData ? [] : null;
                        var shuffledToOriginal = []; // Maps shuffled index -> original index
                        // SECURITY (C2): students receive correctIndex === null. Keep correctAnswer
                        // null in that case so checkAnswer resolves it from the server at check time
                        // (rather than defaulting to 0 and revealing/marking the wrong option).
                        var answerWithheld = (q.correctIndex === null || q.correctIndex === undefined);
                        var newCorrectIndex = answerWithheld ? null : 0;

                        for (var i = 0; i < optionCount; i++) {
                            var origIndex = shuffledIndices[i];
                            shuffledOptions.push(q.options[origIndex].text);
                            shuffledExplanations.push(q.options[origIndex].explanation);
                            shuffledToOriginal.push(origIndex); // Store original index for this position
                            if (shuffledAudioData && q.audioData[origIndex]) {
                                shuffledAudioData.push(q.audioData[origIndex]);
                            } else if (shuffledAudioData) {
                                shuffledAudioData.push(null);
                            }
                            // Track where the correct answer ended up (only when it was provided).
                            if (!answerWithheld && origIndex === q.correctIndex) {
                                newCorrectIndex = i;
                            }
                        }

                        return {
                            id: q.id,
                            question: q.question,
                            options: shuffledOptions,
                            explanations: shuffledExplanations,
                            correctAnswer: newCorrectIndex,
                            originalCorrectIndex: q.correctIndex, // Keep original for database
                            shuffledToOriginal: shuffledToOriginal, // Mapping for answer submission
                            audioData: shuffledAudioData,
                            timestamp_seconds: (q.timestamp_seconds !== undefined && q.timestamp_seconds !== null)
                                ? q.timestamp_seconds
                                : null,
                            // ADD-KC-IMAGEGATE (v1.5.115): Map per-question image data (not shuffled — always tied to question).
                            imageUrl: q.imageUrl || '',
                            imageEnabled: q.imageEnabled ? true : false,
                            // ADD-KC-MEDIAPER-Q (v1.5.120): Map per-question video and audio data (not shuffled).
                            questionVideoUrl: q.questionVideoUrl || '',
                            questionVideoEnabled: q.questionVideoEnabled ? true : false,
                            questionAudioUrl: q.questionAudioUrl || '',
                            questionAudioEnabled: q.questionAudioEnabled ? true : false,
                            // ADD-SURVEY-FREETEXT (v1.5.127): Preserve question type.
                            questionType: q.questionType || 'scale'
                        };
                    });

                    // Start the quiz
                    startStudentQuiz();
                } else {
                    kcError('[KC] No questions found');
                    kcAlert(S.errornoquestionsstudent);
                    location.reload();
                }
        }).fail(function(err) {
            kcError('[KC] Load questions failed:', err && err.message ? err.message : err);
            kcAlert(S.errorloadquestionsfailed);
            location.reload();
        });
    }

    /**
     * Prepare the loaded questions and hand over to the student quiz runner.
     *
     * @return {void}
     */
    function startStudentQuiz() {
        // Restore question position for Continue Attempt, or start from Q1 for fresh attempts.
        var totalQs = quizData ? quizData.length : 0;
        if (resumeFromIndex >= totalQs && totalQs > 0) {
            // Student answered every question but did not click Finish (e.g. closed browser
            // after the last answer). Reconstruct score + log from resumeAnswers, then show
            // results directly so the student sees the correct percentage and can download.
            // BUG-KC-RESUME-ALLCOMPLETE fix: old code hard-coded score=0 and left
            // quizAnswerLog=[]  -  results showed 0% and download buttons failed.
            currentAttemptNum = 1;
            quizAnswerLog = [];
            var computedScore = 0;
            if (resumeAnswers) {
                (quizData || []).forEach(function(q, idx) {
                    var savedAns = q.id ? resumeAnswers[String(q.id)] : null;
                    var isCorrect = savedAns ? !!savedAns.iscorrect : null;
                    if (isCorrect) {
                        computedScore++;
                    }
                    var savedOrigIdx = (savedAns && savedAns.answer !== undefined && savedAns.answer !== null)
                        ? parseInt(savedAns.answer, 10) : -1;
                    var shuffledSelectedIdx = (q.shuffledToOriginal && savedOrigIdx >= 0)
                        ? q.shuffledToOriginal.indexOf(savedOrigIdx) : -1;
                    var expIdx = isCorrect ? q.correctAnswer
                        : (shuffledSelectedIdx >= 0 ? shuffledSelectedIdx : q.correctAnswer);
                    quizAnswerLog.push({
                        questionNum:   idx + 1,
                        question:      q.question,
                        options:       q.options ? q.options.slice() : [],
                        correctIndex:  q.correctAnswer,
                        selectedIndex: shuffledSelectedIdx,
                        isCorrect:     isCorrect,
                        attemptNum:    currentAttemptNum,
                        explanation:   q.explanations ? (q.explanations[expIdx] || q.explanations[q.correctAnswer] || '') : ''
                    });
                });
            }
            score = computedScore;
            resumeAnswers = null;
            resumeFromIndex = 0;
            selectedAnswer = null;
            $('#kc-start-section').hide();
            // FIX-KC-LOADING-RETAKE (v1.5.66): reset button text (same as below).
            $('#start-attempt-btn').prop('disabled', false).text(config.retakeQuizStr || S.retakequiz);
            // V1.5.52 FIX-VIDEO-GATE: hide video/audio/eta sections when quiz player takes over.
            $('.kc-eta-banner').hide();
            // V1.5.56: show or hide video section during quiz based on teacher setting.
            if (config.showVideoDuringQuiz) {
                $('#kc-video-status').hide(); // Hide the gate-progress message; video stays visible
            } else {
                $('#kc-video-section').hide();
            }
            $('#kc-audio-section').hide();
            $('#kc-quiz-player').show();
            showResults();
            return;
        }
        var isResuming = (resumeFromIndex > 0 && resumeFromIndex < totalQs);
        currentQuestionIndex = isResuming ? resumeFromIndex : 0;
        resumeFromIndex = 0; // Reset so fresh retakes always start from Q1.

        // BUG-SCORE-RESUME fix: reconstruct previously-earned score from the
        // server's answers dict instead of always resetting to 0.
        //
        // BUG-SCORE-RESUME-V2 (v1.5.0): The server's saveanswer handler stores
        // each answer as {answer: N, iscorrect: bool}.  The old code cast savedOrig
        // (an object) with Number() which always yields NaN, so the comparison
        // `Number(savedOrig) === Number(correctOrig)` was always false  ->  score 0.
        // Fix: read savedAns.iscorrect directly from the server's answer object.
        //
        // BUG-DOWNLOAD-RESUME fix (v1.5.11): quizAnswerLog was never pre-populated
        // for previously-answered questions on Continue Attempt. Only questions answered
        // in the current session were pushed to the log (via checkAnswer()), so the
        // Download PDF / Download Text export omitted all questions before the resume
        // point  -  showing e.g. only Q9-Q10 when the student resumed at Q9.
        // Fix: iterate quizData[0..resumeFromIndex-1] and reconstruct a log entry for
        // each pre-answered question using resumeAnswers.  savedAns.answer is the
        // original (unshuffled) index; convert it to the shuffled display index via
        // shuffledToOriginal.indexOf() so the correct option letter appears in export.
        if (isResuming && resumeAnswers) {
            quizAnswerLog = [];
            currentAttemptNum = 1;
            var computedScore = 0;
            (quizData || []).slice(0, currentQuestionIndex).forEach(function(q, idx) {
                if (!q.id) {
                    // V1.5.13 FIX-DOWNLOAD-MISSING: questions without an ID were silently
                    // dropped from the log. Now include them as placeholders so the download
                    // export contains ALL questions, not just those with saved DB answers.
                    quizAnswerLog.push({
                        questionNum:  idx + 1,
                        question:     q.question,
                        options:      q.options ? q.options.slice() : [],
                        correctIndex: q.correctAnswer,
                        selectedIndex: -1, // Not recoverable  -  no ID
                        isCorrect:    null,
                        attemptNum:   currentAttemptNum,
                        explanation:  q.explanations ? (q.explanations[q.correctAnswer] || '') : ''
                    });
                    return;
                }
                var savedAns = resumeAnswers[String(q.id)];
                // SavedAns = {answer: N, iscorrect: bool} from the server's saveanswer handler.
                if (!savedAns) {
                    // V1.5.13 FIX-DOWNLOAD-MISSING: answer not in DB (network failure at save
                    // time). Previously skipped with `return`  ->  question absent from download.
                    // Now include as placeholder with selectedIndex: -1 so all Qs appear in export.
                    quizAnswerLog.push({
                        questionNum:  idx + 1,
                        question:     q.question,
                        options:      q.options ? q.options.slice() : [],
                        correctIndex: q.correctAnswer,
                        selectedIndex: -1, // Not recoverable  -  save failed
                        isCorrect:    null,
                        attemptNum:   currentAttemptNum,
                        explanation:  q.explanations ? (q.explanations[q.correctAnswer] || '') : ''
                    });
                    return;
                }
                if (savedAns.iscorrect) {
                    computedScore++;
                }
                // Convert the stored original index back to its shuffled display position.
                var savedOrigIdx = (savedAns.answer !== undefined && savedAns.answer !== null)
                    ? parseInt(savedAns.answer, 10) : -1;
                var shuffledSelectedIdx = (q.shuffledToOriginal && savedOrigIdx >= 0)
                    ? q.shuffledToOriginal.indexOf(savedOrigIdx) : -1;
                // V1.5.13 FIX-EXPLANATION-FIELD: use the selected answer's explanation when
                // incorrect (each option has its own explanation; wrong-option explanations
                // include "Incorrect... Remember:..." phrasing that the student needs to see).
                var expIdx = savedAns.iscorrect ? q.correctAnswer
                    : (shuffledSelectedIdx >= 0 ? shuffledSelectedIdx : q.correctAnswer);
                quizAnswerLog.push({
                    questionNum:  idx + 1,
                    question:     q.question,
                    options:      q.options ? q.options.slice() : [],
                    correctIndex:  q.correctAnswer,
                    selectedIndex: shuffledSelectedIdx,
                    isCorrect:    !!savedAns.iscorrect,
                    attemptNum:   currentAttemptNum,
                    explanation:  q.explanations ? (q.explanations[expIdx] || q.explanations[q.correctAnswer] || '') : ''
                });
            });
            score = computedScore;
            kcLog('[KC] Resume score reconstructed:', score, '/', (quizData || []).length,
                ' -  quizAnswerLog pre-populated with', quizAnswerLog.length, 'prior answers');
        } else if (isResuming) {
            // V1.5.13 FIX-DOWNLOAD-MISSING: resuming but resumeAnswers is null/empty
            // (server returned no saved answers). Previously fell into else  ->  quizAnswerLog=[]
            // so only questions answered in this session appeared in the download.
            // Fix: create question-only placeholders for all pre-resume questions.
            quizAnswerLog = [];
            currentAttemptNum = 1;
            (quizData || []).slice(0, currentQuestionIndex).forEach(function(q, idx) {
                quizAnswerLog.push({
                    questionNum:  idx + 1,
                    question:     q.question,
                    options:      q.options ? q.options.slice() : [],
                    correctIndex: q.correctAnswer,
                    selectedIndex: -1,
                    isCorrect:    null,
                    attemptNum:   currentAttemptNum,
                    explanation:  q.explanations ? (q.explanations[q.correctAnswer] || '') : ''
                });
            });
            score = 0; // Can't reconstruct score without saved answers
            kcLog('[KC] Resume without saved answers  -  placeholders for', quizAnswerLog.length, 'prior questions');
        } else {
            quizAnswerLog = [];
            currentAttemptNum = 1;
            score = 0;
        }
        resumeAnswers = null; // Consumed  -  clear so retakes don't inherit.

        selectedAnswer = null;

        $('#kc-start-section').hide();
        // FIX-KC-LOADING-RETAKE (v1.5.66): The start button text was set to S.loading
        // by handleStartAttempt() earlier.  The start section is now hidden, but the
        // button persists in the DOM.  For activities with a video/audio gate, gate.reset()
        // will re-show this section on the next retake  -  if the text is still S.loading
        // the button will look frozen once the gate unlocks.  Reset it to 'Retake Quiz'
        // here (inside the hidden section) so it reads correctly when it re-appears.
        $('#start-attempt-btn').prop('disabled', false).text(config.retakeQuizStr || S.retakequiz);
        // V1.5.52 FIX-VIDEO-GATE: hide video/audio/eta sections when quiz player takes over.
        $('.kc-eta-banner').hide();
        // V1.5.56: show or hide video section during quiz based on teacher setting.
        if (config.showVideoDuringQuiz) {
            $('#kc-video-status').hide(); // Hide the gate-progress message; video stays visible
        } else {
            $('#kc-video-section').hide();
        }
        $('#kc-audio-section').hide();
        $('#kc-quiz-player').show();

        showQuestion();
    }

    /**
     * Save one answer against the current attempt.
     *
     * @param {number} questionId ID of the question being answered.
     * @param {number} answerIndex Index of the chosen option, or -1 for a free-text answer.
     * @param {string} freetextValue The free-text answer, empty for multiple choice.
     * @param {Function} onResult Called with the service response, or null when the save was skipped.
     * @return {void}
     */
    function saveAnswerToDatabase(questionId, answerIndex, freetextValue, onResult) {
        if (!currentAttemptId) {
            kcLog('[KC] No attempt ID, skipping answer save');
            if (typeof onResult === 'function') {
                onResult(null);
            }
            return;
        }

        // FIX-RACE-FINISH: track in-flight saves so finishAttempt waits for them all.
        pendingSaves++;

        // ADD-SURVEY-FREETEXT (v1.5.127): Include freetext value when answerIndex === -1.
        var saveArgs = {
            attemptid: parseInt(currentAttemptId, 10),
            questionid: parseInt(questionId, 10),
            answerindex: answerIndex
        };
        if (answerIndex === -1 && typeof freetextValue === 'string') {
            saveArgs.freetextvalue = freetextValue;
        }

        kcLog(
            '[KC] Saving answer:',
            {attemptId: currentAttemptId, questionId: questionId, answerIndex: answerIndex, freetext: answerIndex === -1}
        );

        // MIGRATE-EXTERNAL-SERVICES (v1.5.152): saveanswer now runs through the declared
        // mod_aiknowledgecheck_save_answer service. There is no jQuery `complete` hook on a
        // core/ajax promise, so the pending-save bookkeeping that releases a deferred
        // finishAttempt is duplicated into both the done and fail paths via settle().
        var settled = false;
        var settle = function() {
            if (settled) {
                return;
            }
            settled = true;
            pendingSaves--;
            if (pendingSaves === 0 && pendingFinishAttempt) {
                pendingFinishAttempt = false;
                kcLog('[KC] All saves complete  -  executing deferred finishAttempt');
                doFinishAttempt();
            }
        };

        Ajax.call([{
            methodname: 'mod_aiknowledgecheck_save_answer',
            args: saveArgs
        }])[0].done(function(response) {
            if (response.ok) {
                kcLog('[KC] Answer saved successfully');
                if (failedSaves[questionId]) {
                    delete failedSaves[questionId];
                }
            } else {
                kcError('[KC] Failed to save answer:', response.error);
                failedSaves[questionId] = {answerIndex: answerIndex, freetextValue: freetextValue};
            }
            if (typeof onResult === 'function') {
                onResult(response);
            }
            settle();
        }).fail(function(err) {
            kcError('[KC] Save answer request failed:', err);
            // M4: record the failed save so finishAttempt can retry it.
            failedSaves[questionId] = {answerIndex: answerIndex, freetextValue: freetextValue};
            if (typeof onResult === 'function') {
                onResult(null);
            }
            settle();
        });
    }

    /**
     * SECURITY (C2): students are not sent the correct answer up-front. When a student checks an
     * answer, persist it and read the authoritative correct index + explanations back from the
     * server, then patch this question object (mapping the server's original-order values back
     * into the client's shuffled order) so the normal reveal logic can run unchanged.
     *
     * @param {Object} q the question object (shuffled) being answered.
     * @param {number} originalIndex the selected answer mapped back to original option order.
     * @param {Function} cb invoked once q has been patched (or left as-is on failure).
     */
    function resolveCorrectAnswer(q, originalIndex, cb) {
        saveAnswerToDatabase(q.id, originalIndex, undefined, function(resp) {
            q._answerSaved = true; // Don't double-save on the re-run
            if (resp && typeof resp.correctanswer === 'number') {
                if (q.shuffledToOriginal && q.shuffledToOriginal.length) {
                    var origToShuf = {};
                    for (var i = 0; i < q.shuffledToOriginal.length; i++) {
                        origToShuf[q.shuffledToOriginal[i]] = i;
                    }
                    q.correctAnswer = (origToShuf[resp.correctanswer] !== undefined)
                        ? origToShuf[resp.correctanswer] : resp.correctanswer;
                    if (Array.isArray(resp.explanations)) {
                        var shufExp = [];
                        for (var j = 0; j < q.shuffledToOriginal.length; j++) {
                            shufExp.push(resp.explanations[q.shuffledToOriginal[j]] || '');
                        }
                        q.explanations = shufExp;
                    }
                } else {
                    q.correctAnswer = resp.correctanswer;
                    if (Array.isArray(resp.explanations)) {
                        q.explanations = resp.explanations;
                    }
                }
            } else {
                // Graceful fallback: server gave nothing usable. Keep the quiz functional —
                // treat as "no highlight" rather than throwing. Scoring stays server-side.
                if (q.correctAnswer === null || q.correctAnswer === undefined) {
                    q.correctAnswer = -1;
                }
                if (!q.explanations) {
                    q.explanations = [];
                }
            }
            if (typeof cb === 'function') {
                cb();
            }
        });
    }

    /**
     * M4: best-effort resend of any answers whose save previously failed, so the server has
     * the full answer set before it grades. Each resend increments pendingSaves, so the
     * defer-until-saved logic in finishAttempt naturally waits for them.
     */
    function retryFailedSaves() {
        var ids = Object.keys(failedSaves);
        if (!ids.length) {
            return;
        }
        kcLog('[KC] Retrying', ids.length, 'failed answer save(s) before finishing');
        ids.forEach(function(qid) {
            var info = failedSaves[qid];
            saveAnswerToDatabase(parseInt(qid, 10), info.answerIndex, info.freetextValue);
        });
    }

    /**
     * Confirm with the student, then finish the attempt.
     *
     * @return {void}
     */
    function finishAttempt() {
        // M4: resend any previously-failed answer saves first (best effort, one pass).
        retryFailedSaves();
        // FIX-RACE-FINISH: if any saveanswer calls are still in-flight, defer the finish
        // until they all complete so the server sees the full answers JSON.
        if (pendingSaves > 0) {
            kcLog('[KC] Deferring finishAttempt  -  ' + pendingSaves + ' save(s) in-flight');
            pendingFinishAttempt = true;
            return;
        }
        doFinishAttempt();
    }

    /**
     * Finish the current attempt and show the results screen.
     *
     * @return {void}
     */
    function doFinishAttempt() {
        // M-4: don't finish SILENTLY when answers are still unsaved. finishAttempt() runs one
        // retry pass first (retryFailedSaves); if any save STILL failed, tell the student so a
        // lost answer isn't a silent surprise on their score. Grading counts an unsaved answer
        // as unanswered (i.e. wrong), so this only ever under-scores — but the learner deserves
        // to know and can reconnect + Retake.
        var unsavedCount = Object.keys(failedSaves).length;
        if (unsavedCount > 0) {
            kcError('[KC] Finishing with ' + unsavedCount + ' unsaved answer(s)');
            try {
                kcAlert(fmt(S.unsavedanswersmessage, unsavedCount), S.unsavedanswerstitle);
            } catch (e) {
                kcWarn('[KC] core/notification unavailable for the unsaved-answers warning');
            }
        }

        if (!currentAttemptId) {
            kcLog('[KC] No attempt ID, skipping finish');
            // Retake buttons were left disabled  -  enable them now so the student can act.
            $('#retake-quiz-btn').prop('disabled', false);
            $('#retry-wrong-btn').prop('disabled', false);
            return;
        }

        // Capture the attempt ID now. If the student clicks Retake quickly, handleStartAttempt
        // may overwrite currentAttemptId before our AJAX callback fires. We capture it here so
        // we only null out currentAttemptId if it hasn't changed (race condition guard).
        var attemptBeingFinished = currentAttemptId;
        var progressKey = 'kc_progress_' + config.cmid + '_' + attemptBeingFinished;

        kcLog('[KC] Finishing attempt:', attemptBeingFinished);

        // MIGRATE-EXTERNAL-SERVICES (v1.5.152): finishattempt now runs through the declared
        // mod_aiknowledgecheck_finish_attempt service.
        Ajax.call([{
            methodname: 'mod_aiknowledgecheck_finish_attempt',
            args: {attemptid: parseInt(attemptBeingFinished, 10)}
        }])[0].done(function(response) {
                if (response.ok) {
                    kcLog('[KC] Attempt finished successfully:', response);
                    // Clear saved progress for this attempt from localStorage.
                    localStorage.removeItem(progressKey);
                    // Use server-authoritative counts so the client never drifts out of sync.
                    if (typeof response.attemptsUsed !== 'undefined') {
                        config.attemptsUsed = response.attemptsUsed;
                    } else {
                        config.attemptsUsed = (config.attemptsUsed || 0) + 1;
                    }
                    if (typeof response.canAttempt !== 'undefined') {
                        config.canAttempt = response.canAttempt;
                    }
                    updateAttemptsBadge();
                    // Only clear currentAttemptId if no new attempt has been started
                    // (guards against the race where handleStartAttempt set a new ID first).
                    if (currentAttemptId === attemptBeingFinished) {
                        currentAttemptId = null;
                    }
                } else {
                    kcError('[KC] Failed to finish attempt:', response.error);
                }
                // Always enable the retake buttons once the finish round-trip is complete.
                $('#retake-quiz-btn').prop('disabled', false);
                $('#retry-wrong-btn').prop('disabled', false);
        }).fail(function(err) {
            kcError('[KC] Finish attempt request failed:', err);
            $('#retake-quiz-btn').prop('disabled', false);
            $('#retry-wrong-btn').prop('disabled', false);
        });
    }

    /**
     * Update the remaining-attempts badge from the current attempt counts.
     *
     * @return {void}
     */
    function updateAttemptsBadge() {
        var used = config.attemptsUsed || 0;
        var max = config.maxAttempts || 0;
        var usedStr = config.attemptsUsedStr || S.attemptsused;
        var unlimitedStr = config.attemptsUnlimitedStr || 'Unlimited';
        var label = usedStr + ': ' + used + (max > 0 ? ' / ' + max : ' (' + unlimitedStr + ')');
        // Use innerHTML with an explicit-sized SVG instead of cloneNode.
        // cloneNode clones whatever SVG is currently in the badge  -  if that SVG
        // has no width/height attributes (e.g. the JS-rendered results-screen badge),
        // the browser renders it at the SVG default of 300 x 150 px, making the icon
        // appear enormously enlarged. An inline HTML string with explicit dimensions
        // guarantees a correctly-sized 14 x 14 px icon every time.
        var svgHtml = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" ' +
            'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
            'stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;vertical-align:-2px">' +
            '<path d="M1 4v6h6"></path>' +
            '<path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>' +
            '</svg>';
        document.querySelectorAll('.kc-attempts-badge').forEach(function(el) {
            el.innerHTML = svgHtml + ' ' + label;
        });
    }

    /**
     * Reset the per-attempt state and show the first question.
     *
     * @return {void}
     */
    function startQuiz() {
        currentQuestionIndex = 0;
        score = 0;
        selectedAnswer = null;
        quizAnswerLog = [];
        currentAttemptNum = 1;

        $('#kc-ready-section').hide();
        // V1.5.60 FIX-START-QUIZ-VIDEO: hide video/audio/eta when teacher preview quiz starts
        // (matches the same logic in handleStartAttempt for student-mode)
        $('.kc-eta-banner').hide();
        if (config.showVideoDuringQuiz) {
            $('#kc-video-status').hide();
        } else {
            $('#kc-video-section').hide();
        }
        $('#kc-audio-section').hide();
        $('#kc-quiz-player').show();

        showQuestion();
    }

    /**
     * Render the current question, its options and any attached media.
     *
     * @return {void}
     */
    function showQuestion() {
        var q = quizData[currentQuestionIndex];

        $('#question-counter').text(fmt(S.questionof, {current: currentQuestionIndex + 1, total: quizData.length}));
        // ADD-SURVEY-MODE (v1.5.126): Hide score in survey mode.
        if (config.surveyMode) {
            $('#quiz-score').hide();
        } else {
            $('#quiz-score').text(fmt(S.score, {correct: score, total: quizData.length}));
        }
        $('#question-text').text(q.question);

        // ADD-KC-MEDIAPER-Q (v1.5.120): Unified per-question media gate (image + video + audio).
        // All media types share the acknowledgedQuestions[index] flag. If any media is present
        // and not yet acknowledged, answer options and the check button are locked until the
        // student clicks "I've reviewed this content — Continue".
        $('#kc-question-media').remove();
        var hasQImage = !!(q.imageEnabled && q.imageUrl);
        var hasQVideo = !!(q.questionVideoEnabled && q.questionVideoUrl);
        var hasQAudio = !!(q.questionAudioEnabled && q.questionAudioUrl);
        var hasQMedia = hasQImage || hasQVideo || hasQAudio;
        var qMediaAcked = acknowledgedQuestions[currentQuestionIndex] === true;
        var needsQMediaGate = hasQMedia && !qMediaAcked;

        if (hasQMedia) {
            var qMediaHtml = '<div id="kc-question-media" style="margin-bottom: 14px;">';
            if (hasQImage) {
                qMediaHtml += '<div style="text-align: center; margin-bottom: 10px;">' +
                    '<img src="' + q.imageUrl.replace(/"/g, '&quot;') + '" alt="Question image" ' +
                        'style="max-width: 100%; max-height: 400px; border-radius: 8px; ' +
                        'object-fit: contain; display: inline-block;">' +
                    '</div>';
            }
            if (hasQVideo) {
                var qVidId = extractYouTubeId(q.questionVideoUrl);
                if (qVidId) {
                    qMediaHtml += '<div style="text-align: center; margin-bottom: 10px;">' +
                        '<div style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; max-width: 640px; m' +
                            'argin: 0 auto; border-radius: 8px;">' +
                        '<iframe src="https://www.youtube.com/embed/' + qVidId + '" style="position: absolute; top: 0; left: 0; w' +
                            'idth: 100%; height: 100%; border-radius: 8px;" frameborder="0" allow="accelerometer; autoplay; clipb' +
                            'oard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>' +
                        '</div></div>';
                }
            }
            if (hasQAudio) {
                qMediaHtml += '<div style="margin-bottom: 10px; text-align: center;">' +
                    '<audio controls preload="auto" style="width: 100%; max-width: 500px;">' +
                    '<source src="' + q.questionAudioUrl.replace(/"/g, '&quot;') + '">' +
                    '</audio></div>';
            }
            if (needsQMediaGate) {
                qMediaHtml += '<div id="kc-q-media-gate" style="text-align: center; margin-top: 10px;">' +
                    '<button id="kc-q-media-ack-btn" class="kc-btn kc-btn-primary" type="button">' +
                    'I\'ve reviewed this content &#8212; Continue' +
                    '</button></div>';
            } else {
                qMediaHtml += '<div style="text-align: center; margin-top: 6px; font-size: 12px; color: #28a745;">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#28a7' +
                        '45" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;margin-ri' +
                        'ght:3px;"><polyline points="20 6 9 17 4 12"></polyline></svg>' +
                    'Content reviewed</div>';
            }
            qMediaHtml += '</div>';
            $('#question-text').before(qMediaHtml);
        }

        // Chapter timestamp link  -  show clickable "Jump to X:XX" if enabled and question has a timestamp.
        $('#kc-chapter-stamp').remove();
        if (config.showChapterStamps && hasValue(q.timestamp_seconds) && config.hasVideo) {
            var stampSecs = parseInt(q.timestamp_seconds, 10);
            if (!isNaN(stampSecs) && stampSecs >= 0) {
                var kcStampMins = Math.floor(stampSecs / 60);
                var kcStampRem = stampSecs % 60;
                var kcStampTimeStr = kcStampMins + ':' + (kcStampRem < 10 ? '0' : '') + kcStampRem;
                var stampBtn = $('<button id="kc-chapter-stamp" class="kc-chapter-stamp-btn" type="button" data-testid="button-ch' +
                    'apter-stamp">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="curre' +
                        'ntColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/' +
                        '><polyline points="12 6 12 12 16 14"/></svg>' +
                    ' Jump to ' + kcStampTimeStr + '</button>');
                stampBtn.on('click', function() {
                    var kcPlayer = window.kcYtPlayer;
                    if (kcPlayer && kcPlayer.seekTo) {
                        kcPlayer.seekTo(stampSecs, true);
                        if (kcPlayer.playVideo) {
                            kcPlayer.playVideo();
                        }
                        // Ensure video section is visible.
                        var videoSection = document.getElementById('kc-video-section');
                        if (videoSection && videoSection.style.display === 'none') {
                            videoSection.style.display = 'block';
                        }
                        var videoContainer = document.getElementById('kc-video-section');
                        if (videoContainer) {
                            videoContainer.scrollIntoView({behavior: 'smooth', block: 'start'});
                        }
                    }
                });
                $('#question-text').after(stampBtn);
            }
        }

        // ADD-SURVEY-FREETEXT (v1.5.127): Freetext questions show a textarea instead of options.
        if (q.questionType === 'freetext') {
            $('#options-container').html(
                '<textarea id="kc-freetext-answer" class="kc-freetext-input" rows="5" ' +
                'aria-label="' + escapeAttr(S.freetextlabel) + '" ' +
                'placeholder="' + escapeAttr(S.freetextplaceholder) + '"></textarea>'
            );
            $('#feedback-container').hide();
            $('#check-answer-btn').hide();
            if (currentQuestionIndex < quizData.length - 1) {
                $('#next-question-btn').text(S.next).show().prop('disabled', false);
            } else {
                $('#next-question-btn').text(S.submitsurvey).show().prop('disabled', false);
            }
            selectedAnswer = -1; // Mark as ready (freetext questions are always submittable).
            return;
        }

        var optionsHtml = '';
        var letters = ['A', 'B', 'C', 'D', 'E'];
        q.options.forEach(function(option, index) {
            var optionText = (option || '').replace(/\.\s*$/, '').trim();
            // V1.5.52 FIX-OPTION-CAPITALISE: ensure first letter is always uppercase
            // regardless of how the AI or editor stored the option text.
            if (optionText.length > 0) {
                optionText = optionText.charAt(0).toUpperCase() + optionText.slice(1);
            }
            optionsHtml += '<div class="kc-option" data-index="' + index + '"' +
                ' role="radio" aria-checked="false" tabindex="' + (index === 0 ? '0' : '-1') + '">';
            optionsHtml += '<span class="kc-option-letter">' + letters[index] + '</span>';
            optionsHtml += '<span class="kc-option-text">' + escapeHtml(optionText) + '</span>';
            optionsHtml += '</div>';
        });

        $('#options-container')
            .html(optionsHtml)
            .attr('role', 'radiogroup')
            .attr('aria-labelledby', 'question-text');
        $('#feedback-container').hide();
        if (config.surveyMode) {
            // Survey scale questions have no correct answer, so they must never expose
            // the quiz-only "Check Answer" step. Selection enables the direct
            // Next/Submit Survey action, matching free-text survey questions.
            $('#check-answer-btn').hide();
            $('#next-question-btn')
                .text(currentQuestionIndex < quizData.length - 1 ? S.next : S.submitsurvey)
                .show()
                .prop('disabled', true);
        } else {
            $('#check-answer-btn').show().prop('disabled', true);
            $('#next-question-btn').hide();
        }
        selectedAnswer = null;

        // ADD-KC-MEDIAPER-Q (v1.5.120): Lock options + check button until all media acknowledged.
        if (needsQMediaGate) {
            $('#options-container').css({'visibility': 'hidden', 'pointer-events': 'none'});
            $('#check-answer-btn').hide();
            $('#next-question-btn').hide();
            $('#kc-q-media-ack-btn').on('click', function() {
                acknowledgedQuestions[currentQuestionIndex] = true;
                $('#kc-q-media-gate').replaceWith(
                    '<div style="text-align: center; margin-top: 6px; font-size: 12px; color: #28a745;">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#28a7' +
                        '45" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;margin-ri' +
                        'ght:3px;"><polyline points="20 6 9 17 4 12"></polyline></svg>' +
                    'Content reviewed</div>'
                );
                $('#options-container').css({'visibility': 'visible', 'pointer-events': ''});
                if (config.surveyMode) {
                    $('#next-question-btn')
                        .text(currentQuestionIndex < quizData.length - 1 ? S.next : S.submitsurvey)
                        .show()
                        .prop('disabled', true);
                } else {
                    $('#check-answer-btn').show().prop('disabled', true);
                }
            });
        }

        // Bind option click
        $('.kc-option').on('click', function() {
            if ($(this).hasClass('disabled')) {
                return;
            }

            selectOption($(this));
            applyOptionSelection($(this));
        });

        bindOptionKeyboard(applyOptionSelection);

        focusQuestion();

        // Pre-buffer audio for this question and the next one so voiceover
        // plays with zero delay when the student clicks "Check Answer".
        if (!config.surveyMode) {
            preloadCurrentQuestionAudio(currentQuestionIndex);
            if (currentQuestionIndex + 1 < quizData.length) {
                preloadCurrentQuestionAudio(currentQuestionIndex + 1);
            }
        }
    }

    /**
     * Pre-decode and buffer all audio answers for question at index qi.
     * Stores fully-loaded Audio objects in audioPreloadCache keyed by 'qi_ai'
     * so playExplanationAudio can play them instantly without any decode work.
     *
     * @param {number} qi Index of the question in quizData.
     * @return {void}
     */
    function preloadCurrentQuestionAudio(qi) {
        var q = quizData[qi];
        if (!q || !q.audioData || !Array.isArray(q.audioData)) {
            return;
        }
        q.audioData.forEach(function(b64, ai) {
            var cacheKey = qi + '_' + ai;
            if (!b64 || audioPreloadCache[cacheKey]) { // Already cached
                return;
            }
            try {
                var raw = atob(b64);
                var arr = new Uint8Array(raw.length);
                for (var i = 0; i < raw.length; i++) {
                    arr[i] = raw.charCodeAt(i);
                }
                var blob = new Blob([arr], {type: 'audio/ogg'});
                var url = URL.createObjectURL(blob);
                var aud = new Audio(url);
                aud._blobUrl = url;
                aud.preload = 'auto';
                aud.load(); // Start buffering immediately
                audioPreloadCache[cacheKey] = aud;
            } catch (e) {}
        });
    }

    /**
     * Grade the selected answer, show feedback and record it against the attempt.
     *
     * @return {void}
     */
    function checkAnswer() {
        if (retryWrongOnly) {
            checkAnswerWrongOnly();
            return;
        }
        // ADD-SURVEY-FREETEXT (v1.5.127): Freetext questions bypass checkAnswer entirely
        // (they set selectedAnswer = -1 and show Next immediately in showQuestion).
        // selectedAnswer === -1 can only reach here if something unexpected fires checkAnswer
        // for a freetext question — skip gracefully.
        if (selectedAnswer === -1) {
            return;
        }
        if (selectedAnswer === null) {
            return;
        }

        // Survey questions advance through nextQuestion(); this handler is quiz-only.
        if (config.surveyMode) {
            return;
        }

        var q = quizData[currentQuestionIndex];

        // SECURITY (C2): for students the correct answer was withheld at load time. Resolve it
        // from the server (authoritative), patch this question, then re-run to reveal as normal.
        if (q.correctAnswer === null || q.correctAnswer === undefined) {
            if (q._resolvingAnswer) {
                return;
            }
            q._resolvingAnswer = true;
            $('#check-answer-btn').prop('disabled', true);
            var origIdxResolve = q.shuffledToOriginal ? q.shuffledToOriginal[selectedAnswer] : selectedAnswer;
            resolveCorrectAnswer(q, origIdxResolve, function() {
                q._resolvingAnswer = false;
                checkAnswer();
            });
            return;
        }

        var isCorrect = selectedAnswer === q.correctAnswer;

        // Record per-question result for the results download.
        quizAnswerLog.push({
            questionNum:  currentQuestionIndex + 1,
            question:     q.question,
            options:      q.options ? q.options.slice() : [],
            correctIndex: q.correctAnswer,
            selectedIndex: selectedAnswer,
            isCorrect:    isCorrect,
            attemptNum:   currentAttemptNum,
            // V1.5.13 FIX-EXPLANATION-FIELD: use the selected answer's explanation when
            // incorrect  -  each option in q.explanations[] has its own text; wrong-option
            // explanations include "Incorrect... Remember:..." that the student needs.
            explanation:  q.explanations ? (isCorrect
                ? (q.explanations[q.correctAnswer] || '')
                : (q.explanations[selectedAnswer] || q.explanations[q.correctAnswer] || '')) : ''
        });

        // Save answer to database (for student attempts)
        // CRITICAL: Send the ORIGINAL index, not the shuffled one, so the database can correctly compare.
        // (When the answer was resolved from the server just above, q._answerSaved is set so we don't
        // save it a second time here.)
        if (q.id && !q._answerSaved) {
            var originalIndex = q.shuffledToOriginal ? q.shuffledToOriginal[selectedAnswer] : selectedAnswer;
            saveAnswerToDatabase(q.id, originalIndex);
        }

        if (isCorrect) {
            score++;
            // Play success sound for correct answer
            playCorrectSound();
        } else {
            // Play incorrect sound for wrong answer
            playIncorrectSound();
        }

        // Disable options
        $('.kc-option').addClass('disabled').attr('aria-disabled', 'true').attr('tabindex', '-1');

        // Show correct/incorrect
        $('.kc-option').each(function() {
            var index = parseInt($(this).data('index'), 10);
            if (index === q.correctAnswer) {
                $(this).addClass('correct');
            } else if (index === selectedAnswer && !isCorrect) {
                $(this).addClass('incorrect');
            }
        });

        // Show feedback
        // FIX-KC-SELECTED-AUDIO: v1.5.74  -  play the SELECTED option's audio/explanation when wrong,
        // and the correct option's audio/explanation when right.  The previous v1.5.68 approach
        // always played the correct answer's audio, causing students to hear "Correct. ..." while the
        // UI displayed "Incorrect"  -  a confusing and misleading experience.
        // audioData[] and explanations[] are permuted in lockstep by shuffleQuestionAnswers and
        // redistributeCorrectAnswers, so audioData[i] always matches explanations[i] post-shuffle.
        var explanationIdx = isCorrect ? q.correctAnswer : selectedAnswer;
        var explanationToShow = (q.explanations && q.explanations[explanationIdx]) || '';
        $('#feedback-result').text(isCorrect ? S.correct : S.incorrect)
            .removeClass('correct incorrect')
            .addClass(isCorrect ? 'correct' : 'incorrect');
        $('#feedback-explanation').text(explanationToShow);
        $('#feedback-container').show();

        // Hide play button - voiceover auto-plays
        $('#play-audio-btn').hide();

        $('#check-answer-btn').hide();

        var voiceoverOn = $('#voiceover-toggle').is(':checked') || !!config.voiceoverEnabled;
        var audioIdx = isCorrect ? q.correctAnswer : selectedAnswer; // FIX-KC-SELECTED-AUDIO
        var hasAudioForAnswer = q.audioData && q.audioData[audioIdx];
        var shouldGate = !isCorrect && voiceoverOn && hasAudioForAnswer;

        if (currentQuestionIndex < quizData.length - 1) {
            $('#next-question-btn').text(S.nextquestion).show().prop('disabled', shouldGate);
        } else {
            $('#next-question-btn').text(S.finishquiz).show().prop('disabled', shouldGate);
        }

        if (voiceoverOn && hasAudioForAnswer) {
            playExplanationAudio(q, audioIdx, shouldGate);
        }

        if (!config.surveyMode) {
            $('#quiz-score').text(fmt(S.score, {correct: score, total: quizData.length}));
        }
    }

    /**
     * Play the explanation audio for one answer.
     *
     * @param {Object} question The question being answered.
     * @param {number} answerIndex Index of the answer whose explanation should play.
     * @param {boolean} gateNextButton True to keep the Next button disabled until playback ends.
     * @return {void}
     */
    function playExplanationAudio(question, answerIndex, gateNextButton) {
        kcLog('[KC] playExplanationAudio called, answerIndex:', answerIndex, 'gateNextButton:', gateNextButton);

        // Double-check audio data exists (caller should have verified, but be safe)
        if (!question.audioData || !question.audioData[answerIndex]) {
            kcLog('[KC] No audio data for answer index:', answerIndex);
            if (gateNextButton) {
                $('#next-question-btn').prop('disabled', false);
            }
            return;
        }

        var audioBase64 = question.audioData[answerIndex];
        if (!audioBase64) {
            kcLog('[KC] Empty audio data for answer index:', answerIndex);
            if (gateNextButton) {
                $('#next-question-btn').prop('disabled', false);
            }
            return;
        }

        // Stop any existing audio
        if (audioElement) {
            audioElement.pause();
            audioElement = null;
        }

        // --- Fast path: use pre-buffered Audio object if available ---
        var qi = quizData ? quizData.indexOf(question) : -1;
        var cacheKey = qi + '_' + answerIndex;
        var cachedAud = (qi >= 0 && audioPreloadCache[cacheKey]) ? audioPreloadCache[cacheKey] : null;

        if (cachedAud) {
            cachedAud.currentTime = 0;
            cachedAud.onended = null;
            cachedAud.onerror = null;
            audioElement = cachedAud;

            audioElement.onended = function() {
                if (gateNextButton) {
                    $('#next-question-btn').prop('disabled', false);
                }
            };
            audioElement.onerror = function() {
                if (gateNextButton) {
                    $('#next-question-btn').prop('disabled', false);
                }
            };
            if (gateNextButton) {
                setTimeout(function() {
                    if ($('#next-question-btn').prop('disabled')) {
                        $('#next-question-btn').prop('disabled', false);
                    }
                }, 90000);
            }
            audioElement.play().catch(function() {
                if (gateNextButton) {
                    $('#next-question-btn').prop('disabled', false);
                }
            });
            return;
        }

        // --- Fallback: decode on demand (cache miss) ---
        try {
            var byteCharacters = atob(audioBase64);
            var byteNumbers = new Array(byteCharacters.length);
            for (var i = 0; i < byteCharacters.length; i++) {
                byteNumbers[i] = byteCharacters.charCodeAt(i);
            }
            var byteArray = new Uint8Array(byteNumbers);
            var blob = new Blob([byteArray], {type: 'audio/ogg'});
            var audioUrl = URL.createObjectURL(blob);

            audioElement = new Audio(audioUrl);

            audioElement.onended = function() {
                URL.revokeObjectURL(audioUrl);
                if (gateNextButton) {
                    $('#next-question-btn').prop('disabled', false);
                }
            };

            audioElement.onerror = function() {
                URL.revokeObjectURL(audioUrl);
                if (gateNextButton) {
                    $('#next-question-btn').prop('disabled', false);
                }
            };

            // Safety timeout: 90 seconds max wait if audio events never fire
            if (gateNextButton) {
                setTimeout(function() {
                    if ($('#next-question-btn').prop('disabled')) {
                        $('#next-question-btn').prop('disabled', false);
                    }
                }, 90000);
            }

            audioElement.play().catch(function() {
                URL.revokeObjectURL(audioUrl);
                if (gateNextButton) {
                    $('#next-question-btn').prop('disabled', false);
                }
            });
        } catch (err) {
            if (gateNextButton) {
                $('#next-question-btn').prop('disabled', false);
            }
        }
    }

    /**
     * Advance to the next question, or finish when the last one has been answered.
     *
     * @return {void}
     */
    function nextQuestion() {
        if (retryWrongOnly) {
            nextQuestionWrongOnly();
            return;
        }
        stopAudio();

        // Survey questions bypass checkAnswer entirely. Save scale and free-text
        // responses here when the student clicks Next/Submit Survey, without any
        // correct/incorrect grading or feedback phase.
        var cq = quizData[currentQuestionIndex];
        if (config.surveyMode && cq) {
            if (cq.questionType === 'freetext') {
                var ftVal = $('#kc-freetext-answer').val() || '';
                if (cq.id) {
                    saveAnswerToDatabase(cq.id, -1, ftVal);
                }
                quizAnswerLog.push({
                    questionNum:   currentQuestionIndex + 1,
                    question:      cq.question,
                    options:       [],
                    correctIndex:  null,
                    selectedIndex: -1,
                    freetextValue: ftVal,
                    isCorrect:     null,
                    attemptNum:    currentAttemptNum,
                    explanation:   ''
                });
            } else {
                if (selectedAnswer === null) {
                    return;
                }
                $('#next-question-btn').prop('disabled', true);
                $('.kc-option').addClass('disabled').attr('aria-disabled', 'true').attr('tabindex', '-1');
                if (cq.id) {
                    var originalSurveyIndex = cq.shuffledToOriginal
                        ? cq.shuffledToOriginal[selectedAnswer]
                        : selectedAnswer;
                    saveAnswerToDatabase(cq.id, originalSurveyIndex);
                }
                quizAnswerLog.push({
                    questionNum:   currentQuestionIndex + 1,
                    question:      cq.question,
                    options:       cq.options ? cq.options.slice() : [],
                    correctIndex:  null,
                    selectedIndex: selectedAnswer,
                    isCorrect:     null,
                    attemptNum:    currentAttemptNum,
                    explanation:   ''
                });
            }
        }

        if (currentQuestionIndex < quizData.length - 1) {
            currentQuestionIndex++;
            // Save progress so Continue Attempt can resume from this question.
            if (currentAttemptId) {
                var storageKey = 'kc_progress_' + config.cmid + '_' + currentAttemptId;
                localStorage.setItem(storageKey, currentQuestionIndex);
            }
            showQuestion();
        } else {
            showResults();
        }
    }

    /**
     * Render the end-of-attempt results screen.
     *
     * @return {void}
     */
    function showResults() {
        stopAudio();
        $('#kc-quiz-player').hide();

        // Finish the attempt in the database
        finishAttempt();

        // ADD-SURVEY-MODE (v1.5.126): Survey mode — show a simple "thank you" screen
        // instead of the score ring.
        if (config.surveyMode) {
            var surveyHtml =
                '<div class="kc-results" style="text-align:center; padding: 40px 20px;">' +
                    '<div class="kc-encouragement excellent" style="margin-bottom:24px;">' +
                        '<div class="kc-encouragement-icon">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">' +
                                '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0' +
                                    ' 11-18 0 9 9 0 0118 0z" />' +
                            '</svg>' +
                        '</div>' +
                        '<div class="kc-encouragement-text">' +
                            '<h2 class="kc-result-title">Survey Complete</h2>' +
                            '<p class="kc-result-message">Thank you for completing the survey. Your responses have been recorded.' +
                                '</p>' +
                        '</div>' +
                    '</div>' +
                    '<div class="kc-result-actions">' +
                        '<button id="retake-quiz-btn" class="kc-btn-retake" disabled data-testid="button-retake-survey">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path ' +
                                'stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.' +
                                '001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>' +
                            'Retake Survey' +
                        '</button>' +
                    '</div>' +
                '</div>';
            // FIX-KC-SURVEY-BLANK (v1.5.142): render into '#kc-results', the container that
            // actually exists in view.php and that the quiz path uses. This previously targeted
            // '#kc-results-container', which is not present anywhere in the plugin's markup, so
            // jQuery matched nothing, the hidden '#kc-results' card was never revealed, and the
            // student was left staring at a blank screen after answering the last question —
            // with no indication their responses had been saved.
            $('#kc-results').html(surveyHtml).show();
            setTimeout(function() {
                $('#retake-quiz-btn').prop('disabled', false);
            }, 800);
            return;
        }

        var percentage = Math.round((score / quizData.length) * 100);
        var incorrect = quizData.length - score;
        var isPerfectScore = (percentage === 100);

        // Calculate grade-based pass/fail
        var gradePass = config.gradePass ? parseFloat(config.gradePass) : 0;
        var maxGrade = config.maxGrade ? parseInt(config.maxGrade, 10) : 100;
        var earnedGrade = Math.round((score / quizData.length) * maxGrade * 100) / 100;
        var hasPassingGrade = gradePass > 0;
        var hasPassed = hasPassingGrade && earnedGrade >= gradePass;

        // Play celebration when passing grade achieved or perfect score
        if (isPerfectScore || hasPassed) {
            playLevelCompleteSound();
            createConfetti();
        }

        // Determine performance tier
        var tier, title, message, encouragementClass, encouragementIcon;
        if (isPerfectScore) {
            tier = 'perfect';
            title = 'Perfect Score!';
            message = 'Outstanding! You\'ve mastered this topic completely.';
            encouragementClass = 'excellent';
            encouragementIcon = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><p' +
                'ath stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.8' +
                '57L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" /></svg>';
        } else if (hasPassed) {
            tier = 'excellent';
            title = 'Well Done!';
            message = 'You\'ve met the passing grade. Great work!';
            encouragementClass = 'excellent';
            encouragementIcon = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><p' +
                'ath stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 011' +
                '8 0z" /></svg>';
        } else if (percentage >= 80) {
            tier = 'excellent';
            title = 'Excellent Work!';
            message = 'You\'ve demonstrated strong understanding of this topic.';
            encouragementClass = 'excellent';
            encouragementIcon = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><p' +
                'ath stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 011' +
                '8 0z" /></svg>';
        } else if (percentage >= 60) {
            tier = 'good';
            title = 'Good Progress!';
            message = 'You\'re on the right track. Review the explanations to strengthen your knowledge.';
            encouragementClass = '';
            encouragementIcon = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><p' +
                'ath stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" /></svg>';
        } else {
            tier = 'needs-work';
            title = 'Keep Practicing!';
            message = 'Review the explanations and try again to improve your score.';
            encouragementClass = '';
            encouragementIcon = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><p' +
                'ath stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.' +
                '5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.' +
                '747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>';
        }

        // Build passing grade message
        var gradeMessage = '';
        if (hasPassingGrade) {
            var earnedDisplay = earnedGrade % 1 === 0 ? earnedGrade.toFixed(0) : earnedGrade.toFixed(1);
            var passDisplay = gradePass % 1 === 0 ? gradePass.toFixed(0) : gradePass.toFixed(1);
            if (hasPassed) {
                gradeMessage = '<div class="kc-grade-result kc-grade-passed">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-l' +
                        'inecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' +
                        '" /></svg>' +
                    '<span>Passing grade achieved: ' + earnedDisplay + '/' + maxGrade + ' (required: ' + passDisplay + ')</span>' +
                '</div>';
            } else {
                gradeMessage = '<div class="kc-grade-result kc-grade-failed">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-l' +
                        'inecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 011' +
                        '8 0z" /></svg>' +
                    '<span>Passing grade not reached: ' +
                         earnedDisplay +
                         '/' +
                         maxGrade +
                         ' (required: ' +
                         passDisplay +
                         ')</span>' +
                '</div>';
            }
        }

        // Calculate ring offset (circumference = 2 * PI * r = 2 * 3.14159 * 65 ~= 408)
        var circumference = 408;
        var offset = circumference - (circumference * percentage / 100);

        // -- After Completion logic ------------------------------------------------
        // "Terminal" state = student has answered every question correctly (isPerfectScore)
        // OR the attempt limit is exhausted (!config.canAttempt).
        // The afterCompletion setting only affects the UI in this terminal state.
        //   'lock'     ->  show a padlock notice; no further attempts.
        //   'restart'  ->  show a "Start Again" button that restarts from scratch.
        // In a non-terminal state (attempts remain AND not perfect), both settings
        // show the normal Retry Wrong / Retake Full Quiz controls  -  the student still
        // needs to work on improving their score.
        // Teachers always see the normal retake controls regardless.
        var isTerminal = isPerfectScore || !config.canAttempt;
        var afterCompletion = config.afterCompletion || 'restart';
        var lockedNotice = (config.strings && config.strings.activityLockedNotice)
            ? config.strings.activityLockedNotice
            : 'This activity is now locked. No further attempts are permitted.';
        var startAgainLabel = (config.strings && config.strings.startAgain)
            ? config.strings.startAgain
            : 'Start Again';

        var actionButtonsHtml;
        if (config.isTeacher) {
            // Teachers always see the full retake controls.
            actionButtonsHtml =
                (incorrect > 0 ?
                    '<button id="retry-wrong-btn" class="kc-btn-retake" disabled data-testid="button-retry-wrong">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stro' +
                            'ke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 00' +
                            '4.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>' +
                        'Retry Wrong Answers (' + incorrect + ')' +
                    '</button>'
                : '') +
                '<button id="retake-quiz-btn" class="kc-btn-retake kc-btn-retake-secondary" disabled data-testid="button-retake-q' +
                    'uiz">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-l' +
                        'inecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m' +
                        '0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>' +
                    'Retake Full Quiz' +
                '</button>';
        } else if (isTerminal && afterCompletion === 'lock') {
            // Activity locked: student reached 100% or exhausted attempts.
            actionButtonsHtml =
                '<p class="kc-activity-locked">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:1' +
                        '.1em;height:1.1em;vertical-align:-0.2em;margin-right:0.35em;">' +
                        '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6' +
                            'a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />' +
                    '</svg>' +
                    lockedNotice +
                '</p>';
        } else if (isTerminal && afterCompletion === 'restart') {
            // Student reached 100% or exhausted attempts  ->  offer a clean restart.
            actionButtonsHtml =
                '<button id="retake-quiz-btn" class="kc-btn-retake" disabled data-testid="button-start-again">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-l' +
                        'inecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m' +
                        '0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>' +
                    startAgainLabel +
                '</button>';
        } else if (config.canAttempt) {
            // Non-terminal: student still has attempts and didn't score 100%.
            // Show the normal retry/retake controls.
            actionButtonsHtml =
                (incorrect > 0 ?
                    '<button id="retry-wrong-btn" class="kc-btn-retake" disabled data-testid="button-retry-wrong">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stro' +
                            'ke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 00' +
                            '4.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>' +
                        'Retry Wrong Answers (' + incorrect + ')' +
                    '</button>'
                : '') +
                '<button id="retake-quiz-btn" class="kc-btn-retake kc-btn-retake-secondary" disabled data-testid="button-retake-q' +
                    'uiz">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-l' +
                        'inecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m' +
                        '0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>' +
                    'Retake Full Quiz' +
                '</button>';
        } else {
            // Attempts exhausted and no afterCompletion setting applies.
            actionButtonsHtml = '<p class="kc-attempts-exhausted">You have used all available attempts.</p>';
        }

        // Build the modern results card
        var html = '<div class="kc-results-card">' +
            '<div class="kc-results-header">' +
                '<span class="kc-results-badge">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-l' +
                        'inecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' +
                        '" /></svg>' +
                    'Quiz Complete' +
                '</span>' +
                // V1.3.96: Show attempts badge on results screen so the count is visible
                // between retakes (the start-section badge is hidden during retake flow).
                // BUG-KC-PILL fix: added margin-left:8px so the pills do not touch.
                '<span class="kc-attempts-badge kc-attempts-badge-sm" style="margin-left:8px;">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="curre' +
                        'ntColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12' +
                        'a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 ' +
                        '2" /></svg>' +
                '</span>' +
            '</div>' +
            '<div class="kc-results-body">' +
                '<div class="kc-score-ring">' +
                    '<svg viewBox="0 0 160 160">' +
                        '<defs>' +
                            '<linearGradient id="scoreGradient" x1="0%" y1="0%" x2="100%" y2="100%">' +
                                '<stop offset="0%" style="stop-color:#667eea" />' +
                                '<stop offset="100%" style="stop-color:#764ba2" />' +
                            '</linearGradient>' +
                            '<linearGradient id="perfectGradient" x1="0%" y1="0%" x2="100%" y2="100%">' +
                                '<stop offset="0%" style="stop-color:#f59e0b" />' +
                                '<stop offset="50%" style="stop-color:#ef4444" />' +
                                '<stop offset="100%" style="stop-color:#8b5cf6" />' +
                            '</linearGradient>' +
                        '</defs>' +
                        '<circle class="kc-score-ring-bg" cx="80" cy="80" r="65" />' +
                        '<circle class="kc-score-ring-fill ' +
                             tier +
                             '" cx="80" cy="80" r="65" data-target-offset="' +
                             offset +
                             '" />' +
                    '</svg>' +
                    '<div class="kc-score-center">' +
                        '<div class="kc-score-percent ' + tier + '" data-target-percent="' + percentage + '">0%</div>' +
                    '</div>' +
                '</div>' +
                '<h3 class="kc-results-title">' + title + '</h3>' +
                '<p class="kc-results-message">' + message + '</p>' +
                '<div class="kc-results-stats">' +
                    '<div class="kc-results-stat">' +
                        '<div class="kc-results-stat-value correct">' + score + '</div>' +
                        '<div class="kc-results-stat-label">Correct</div>' +
                    '</div>' +
                    '<div class="kc-results-stat">' +
                        '<div class="kc-results-stat-value incorrect">' + incorrect + '</div>' +
                        '<div class="kc-results-stat-label">Incorrect</div>' +
                    '</div>' +
                    '<div class="kc-results-stat">' +
                        '<div class="kc-results-stat-value">' + quizData.length + '</div>' +
                        '<div class="kc-results-stat-label">Questions</div>' +
                    '</div>' +
                '</div>' +
                gradeMessage +
                '<div class="kc-results-actions">' +
                    actionButtonsHtml +
                    '<button id="download-results-pdf-btn" class="kc-btn-download-results" data-testid="button-download-results-p' +
                        'df">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stro' +
                            'ke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H' +
                            '5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V' +
                            '5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" /></svg>' +
                        'Download PDF' +
                    '</button>' +
                    '<button id="download-results-text-btn" class="kc-btn-download-results" data-testid="button-download-results-' +
                        'text">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stro' +
                            'ke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2' +
                            ' 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>' +
                        'Download Text' +
                    '</button>' +
                '</div>' +
                (!hasPassed && !isPerfectScore ?
                    '<div class="kc-encouragement ' + encouragementClass + '">' +
                        encouragementIcon +
                        '<span class="kc-encouragement-text">' +
                            (hasPassingGrade
                                ? 'You need ' + (gradePass % 1 === 0 ? gradePass.toFixed(0) : gradePass.toFixed(1)) +
                                    '/' + maxGrade + ' to pass. Review and try again!'
                                : (percentage >= 60
                                    ? 'You\'re close! Focus on the areas you missed.'
                                    : 'Practice makes perfect. Review and try again!')) +
                        '</span>' +
                    '</div>'
                : '') +
            '</div>' +
        '</div>';

        // Replace the old results content
        $('#kc-results').html(html).show();

        // V1.3.96: Immediately populate the badge added to the results header.
        // finishAttempt() will call this again with the server-authoritative count
        // once its AJAX call resolves, keeping the display in sync.
        updateAttemptsBadge();

        // Animate the score ring and percentage counter after render
        setTimeout(function() {
            var ringFill = document.querySelector('.kc-score-ring-fill');
            var percentEl = document.querySelector('.kc-score-percent');
            if (ringFill) {
                var targetOffset = parseFloat(ringFill.getAttribute('data-target-offset'));
                ringFill.style.strokeDashoffset = targetOffset;
            }
            if (percentEl) {
                var targetPercent = parseInt(percentEl.getAttribute('data-target-percent'), 10);
                var duration = 1000;
                var startTime = null;
                var animateCount = function(timestamp) {
                    if (!startTime) {
                        startTime = timestamp;
                    }
                    var elapsed = timestamp - startTime;
                    var progress = Math.min(elapsed / duration, 1);
                    var eased = 1 - Math.pow(1 - progress, 3);
                    var current = Math.round(eased * targetPercent);
                    percentEl.textContent = current + '%';
                    if (progress < 1) {
                        requestAnimationFrame(animateCount);
                    }
                };
                requestAnimationFrame(animateCount);
            }
        }, 50);

        setTimeout(function() {
            var titleEl = document.querySelector('.kc-results-title');
            var msgEl = document.querySelector('.kc-results-message');
            if (titleEl) {
                titleEl.style.transition = 'opacity 0.8s ease';
                titleEl.style.opacity = '0';
                setTimeout(function() {
                    titleEl.style.display = 'none';
                }, 800);
            }
            if (msgEl) {
                msgEl.style.transition = 'opacity 0.8s ease';
                msgEl.style.opacity = '0';
                setTimeout(function() {
                    msgEl.style.display = 'none';
                }, 800);
            }
        }, 3000);

        // Rebind action buttons
        $('#retry-wrong-btn').on('click', retakeWrongOnly);
        $('#retake-quiz-btn').on('click', retakeQuiz);
        $('#download-results-pdf-btn').on('click', downloadResultsPDF);
        $('#download-results-text-btn').on('click', downloadResultsText);
    }

    /**
     * Open a print-ready window containing the student's full quiz results.
     * The browser's native print dialog handles PDF conversion (File > Print > Save as PDF).
     */
    function downloadResultsPDF() {
        if (!quizAnswerLog || quizAnswerLog.length === 0) {
            kcAlert(S.errornoresultstodownload);
            return;
        }

        var labels = ['A', 'B', 'C', 'D'];
        var percentage = Math.round((score / quizData.length) * 100);
        var titleEl = document.querySelector('h2.kc-header-title, h1, title');
        var quizTitle = titleEl ? titleEl.textContent.trim() : 'Knowledge Check';
        var dateStr = new Date().toLocaleDateString(undefined, {year: 'numeric', month: 'long', day: 'numeric'});

        // V1.5.21 ATTEMPT-GROUPED: Build questions HTML grouped by attempt number.
        // Each attempt gets a styled header. Single-attempt quizzes render with no header (backward-compat).
        /**
         * Build the results card markup for one answered question.
         *
         * @param {Object} a The answer-log entry for the question.
         * @return {string} The card markup.
         */
        function buildQuestionCard(a) {
            var optionsHtml = '';
            a.options.forEach(function(opt, i) {
                optionsHtml += '<div style="padding:3px 0;font-size:13px;">' +
                    '<strong>' + labels[i] + '.</strong> ' + escapeHtml(opt) +
                '</div>';
            });
            // V1.5.13 FIX-PLACEHOLDER-DISPLAY: handle entries where answer was not recorded.
            var selectedLetter = (a.selectedIndex >= 0 && a.selectedIndex < labels.length)
                ? labels[a.selectedIndex] : '\u2014';
            var answerLine = a.isCorrect === null
                ? '<span style="color:#6b7280;font-weight:700;">Your answer: ' + selectedLetter + ' (NOT RECORDED)</span>'
                : (a.isCorrect
                    ? '<span style="color:#16a34a;font-weight:700;">Your answer: ' + selectedLetter + ' (CORRECT)</span>'
                    : '<span style="color:#dc2626;font-weight:700;">Your answer: ' + selectedLetter + ' (INCORRECT)</span>');
            var borderColor = a.isCorrect === null ? '#9ca3af' : (a.isCorrect ? '#16a34a' : '#dc2626');
            return '<div style="border:1px solid ' +
                 borderColor +
                 ';border-radius:6px;padding:14px 16px;margin-bottom:16px;page-break-inside:avoid;">' +
                '<p style="margin:0 0 10px;font-weight:600;font-size:14px;">Q' +
                     a.questionNum +
                     '. ' +
                     escapeHtml(a.question) +
                     '</p>' +
                '<div style="margin-bottom:10px;">' + optionsHtml + '</div>' +
                '<p style="margin:0 0 6px;">' + answerLine + '</p>' +
                (a.explanation ? '<p style="margin:0;font-size:12px;color:#555;padding-top:4px;border-top:1px solid #e5e7eb;"><em' +
                    '>Explanation: ' + escapeHtml(a.explanation) + '</em></p>' : '') +
            '</div>';
        }

        // Group log entries by attempt number (preserves attempt order, sorts Qs within each attempt).
        // v1.5.22: Always show "Attempt N" heading  -  even for single-attempt quizzes. Sub-label removed.
        var attemptGroups = {};
        var attemptNums = [];
        quizAnswerLog.forEach(function(a) {
            var num = a.attemptNum || 1;
            if (!attemptGroups[num]) {
                attemptGroups[num] = []; attemptNums.push(num);
            }
            attemptGroups[num].push(a);
        });
        attemptNums.sort(function(x, y) {
            return x - y;
        });

        var questionsHtml = '';
        attemptNums.forEach(function(attemptNum) {
            var entries = attemptGroups[attemptNum].slice().sort(function(x, y) {
                return x.questionNum - y.questionNum;
            });
            var allIncorrect = entries.length > 0 && entries.every(function(a) {
                return a.isCorrect === false;
            });
            questionsHtml += '<div style="margin:' +
                 (attemptNum === 1 ? '0' : '28px') +
                 ' 0 14px;page-break-before:' +
                 (attemptNum === 1 ? 'auto' : 'avoid') +
                 ';">' +
                '<h2 style="margin:0 0 14px;font-size:16px;color:#1d4ed8;border-bottom:2px solid #dbeafe;padding-bottom:6px;">Att' +
                    'empt ' + attemptNum + '</h2>' +
                (allIncorrect ? '<p style="margin:0 0 12px;font-size:12px;color:#dc2626;font-style:italic;">No correct answers in' +
                    ' this attempt.</p>' : '') +
            '</div>';
            entries.forEach(function(a) {
                questionsHtml += buildQuestionCard(a);
            });
        });

        var safeTitle = escapeHtml(quizTitle);
        var html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">' +
            '<title>' + safeTitle + '  -  Results</title>' +
            '<style>' +
                'body{font-family:Arial,Helvetica,sans-serif;margin:0;padding:24px;color:#111;font-size:13px;}' +
                'h1{font-size:20px;margin:0 0 4px;}' +
                '.subtitle{color:#555;font-size:13px;margin:0 0 16px;}' +
                '.summary{display:flex;gap:24px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:12px 16px;m' +
                    'argin-bottom:24px;}' +
                '.summary-item{text-align:center;}' +
                '.summary-value{font-size:28px;font-weight:700;line-height:1;}' +
                '.summary-label{font-size:11px;color:#6b7280;margin-top:2px;}' +
                '.correct-val{color:#16a34a;} .incorrect-val{color:#dc2626;} .pct-val{color:#1d4ed8;}' +
                '@media print{body{padding:16px;} button{display:none;}}' +
            '</style>' +
            '</head><body>' +
            '<h1>' + safeTitle + '  -  Results</h1>' +
            '<p class="subtitle">Date completed: ' + dateStr + '</p>' +
            '<div class="summary">' +
                '<div class="summary-item"><div class="summary-value pct-val">' +
                     percentage +
                     '%</div><div class="summary-label">Score</div></div>' +
                '<div class="summary-item"><div class="summary-value correct-val">' +
                     score +
                     '</div><div class="summary-label">Correct</div></div>' +
                '<div class="summary-item"><div class="summary-value incorrect-val">' +
                     (quizData.length - score) +
                     '</div><div class="summary-label">Incorrect</div></div>' +
                '<div class="summary-item"><div class="summary-value">' +
                     quizData.length +
                     '</div><div class="summary-label">Questions</div></div>' +
            '</div>' +
            questionsHtml +
            '</body></html>';

        var win = window.open('', '_blank');
        if (!win) {
            kcAlert(S.errorpopupblocked);
            return;
        }
        win.document.write(html);
        win.document.close();
        win.focus();
        setTimeout(function() {
            win.print();
        }, 400);
    }

    /**
     * Download a plain-text file containing the student's quiz results.
     */
    function downloadResultsText() {
        if (!quizAnswerLog || quizAnswerLog.length === 0) {
            kcAlert(S.errornoresultstodownload);
            return;
        }

        var labels = ['A', 'B', 'C', 'D'];
        var percentage = Math.round((score / quizData.length) * 100);
        var dateStr = new Date().toLocaleDateString(undefined, {year: 'numeric', month: 'long', day: 'numeric'});
        var titleEl = document.querySelector('h2.kc-header-title, h1');
        var quizTitle = titleEl ? titleEl.textContent.trim() : 'Knowledge Check';

        var lines = [];
        lines.push(quizTitle + '  -  Results');
        lines.push('Date completed: ' + dateStr);
        lines.push('Score: ' + score + '/' + quizData.length + ' (' + percentage + '%)');
        lines.push('');
        lines.push('================================================================');
        lines.push('');

        // V1.5.22: Always show "ATTEMPT N" heading  -  even for single-attempt quizzes. Sub-label removed.
        var attemptGroupsTxt = {};
        var attemptNumsTxt = [];
        quizAnswerLog.forEach(function(a) {
            var num = a.attemptNum || 1;
            if (!attemptGroupsTxt[num]) {
                attemptGroupsTxt[num] = []; attemptNumsTxt.push(num);
            }
            attemptGroupsTxt[num].push(a);
        });
        attemptNumsTxt.sort(function(x, y) {
            return x - y;
        });

        attemptNumsTxt.forEach(function(attemptNum) {
            var entries = attemptGroupsTxt[attemptNum].slice().sort(function(x, y) {
                return x.questionNum - y.questionNum;
            });
            var allIncorrectTxt = entries.length > 0 && entries.every(function(a) {
                return a.isCorrect === false;
            });

            lines.push('ATTEMPT ' + attemptNum);
            lines.push('----------------------------------------------------------------');
            if (allIncorrectTxt) {
                lines.push('No correct answers in this attempt.');
            }
            lines.push('');

            entries.forEach(function(a) {
                lines.push('Q' + a.questionNum + '. ' + a.question);
                a.options.forEach(function(opt, i) {
                    lines.push(labels[i] + '. ' + opt);
                });
                // V1.5.13 FIX-PLACEHOLDER-DISPLAY: handle not-recorded placeholder entries.
                var selectedLetter = (a.selectedIndex >= 0 && a.selectedIndex < labels.length)
                    ? labels[a.selectedIndex] : '\u2014';
                var answerStatus = a.isCorrect === null ? 'NOT RECORDED' : (a.isCorrect ? 'CORRECT' : 'INCORRECT');
                lines.push('Your answer: ' + selectedLetter + ' (' + answerStatus + ')');
                if (a.explanation) {
                    lines.push('Explanation: ' + a.explanation);
                }
                lines.push('');
            });

            lines.push('================================================================');
            lines.push('');
        });

        var content = lines.join('\n');
        var blob = new Blob([content], {type: 'text/plain;charset=utf-8;'});
        var url = window.URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = 'quiz-results.txt';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.URL.revokeObjectURL(url);
    }

    var retryWrongOnly = false;
    var wrongQuestionIndices = [];
    var retryCorrectCarryOver = 0;
    // FIX-KC-IMAGEGATE-HARDGATE (v1.5.119): tracks which question indices the student has
    // acknowledged the per-question image for. Reset on retake so each new attempt re-gates.
    var acknowledgedQuestions = {};

    /**
     * Restart the whole quiz as a fresh attempt.
     *
     * @return {void}
     */
    function retakeQuiz() {
        retryWrongOnly = false;
        wrongQuestionIndices = [];
        retryCorrectCarryOver = 0;
        acknowledgedQuestions = {};
        $('#kc-results').hide();
        if (config.isTeacher) {
            startQuiz();
        } else if (MediaGates.hasLocks()) {
            // FIX-KC-VIDEO-GATE: re-show video/audio sections and re-lock the gate so
            // the student must re-watch before starting their next attempt.
            MediaGates.reset();
            $('#kc-video-section').show();
            $('#kc-audio-section').show();
            // S.startquiz button is re-disabled by kcGate.reset(); student will click it
            // after the gate unlocks, which calls handleStartAttempt() via the bound handler.
        } else {
            handleStartAttempt();
        }
    }

    /**
     * Start a new attempt containing only the questions answered incorrectly.
     *
     * @return {void}
     */
    function retakeWrongOnly() {
        wrongQuestionIndices = [];
        retryCorrectCarryOver = 0;

        // BUG-MISSING-ATTEMPT-HISTORY (v1.5.24): quizAnswerLog now preserves ALL incorrect
        // entries across attempts (so the PDF shows every attempt). A simple forEach would
        // double-count questions that appear multiple times (e.g. Q3x in attempt 1 AND
        // attempt 2). Fix: find the LATEST answer per question, then use only that to decide
        // correct/wrong status.
        var latestByQNum = {};
        quizAnswerLog.forEach(function(entry) {
            var qn = entry.questionNum;
            if (!latestByQNum[qn] || (entry.attemptNum || 1) >= (latestByQNum[qn].attemptNum || 1)) {
                latestByQNum[qn] = entry;
            }
        });
        var qNums = Object.keys(latestByQNum);
        for (var ki = 0; ki < qNums.length; ki++) {
            var latest = latestByQNum[qNums[ki]];
            if (latest.isCorrect) {
                retryCorrectCarryOver++;
            } else {
                wrongQuestionIndices.push(latest.questionNum - 1);
            }
        }
        if (wrongQuestionIndices.length === 0) {
            retakeQuiz();
            return;
        }
        retryWrongOnly = true;
        $('#kc-results').hide();
        if (config.isTeacher) {
            startQuizWrongOnly();
        } else {
            handleStartAttemptWrongOnly();
        }
    }

    /**
     * Open a new attempt for the wrong-only retake.
     *
     * @return {void}
     */
    function handleStartAttemptWrongOnly() {
        pendingSaves = 0;
        pendingFinishAttempt = false;
        $('#start-attempt-btn').prop('disabled', true).text(S.loading);
        // MIGRATE-EXTERNAL-SERVICES (v1.5.152): startattempt now runs through the declared
        // mod_aiknowledgecheck_start_attempt service.
        Ajax.call([{
            methodname: 'mod_aiknowledgecheck_start_attempt',
            args: {cmid: parseInt(config.cmid, 10)}
        }])[0].done(function(response) {
            if (response.ok) {
                currentAttemptId = response.attemptid;
                preSaveCorrectAnswers(function() {
                    startQuizWrongOnly();
                });
            } else {
                kcAlert(response.error || S.errorstartattemptfailed);
                retryWrongOnly = false;
            }
        }).fail(function(err) {
            kcError('[KC] Start wrong-only attempt failed:', err);
            kcAlert(S.errorstartquizfailed);
            retryWrongOnly = false;
        });
    }

    /**
     * Record the already-correct answers against the new attempt before the retake starts.
     *
     * @param {Function} callback Called once every correct answer has been saved.
     * @return {void}
     */
    function preSaveCorrectAnswers(callback) {
        var correctQs = [];
        quizData.forEach(function(q, idx) {
            if (wrongQuestionIndices.indexOf(idx) === -1 && q.id) {
                correctQs.push(q);
            }
        });
        if (correctQs.length === 0) {
            callback();
            return;
        }
        // FIX-RACE-PRESAVE: save carry-forward answers SEQUENTIALLY to avoid the
        // PHP read-modify-write race condition.  The saveanswer handler does:
        //   READ answers JSON  ->  merge one entry  ->  WRITE back.
        // Firing all requests in parallel means every request reads '{}'
        // simultaneously and the last writer wins  -  only 1 of N carry-forward
        // answers actually persists.  Sequential saves guarantee each write is
        // visible to the next reader.
        /**
         * Save the correct answers one at a time, in order.
         *
         * @param {number} i Index into the correct-answer list.
         * @return {void}
         */
        function saveNext(i) {
            if (i >= correctQs.length) {
                callback();
                return;
            }
            var q = correctQs[i];
            var origIdx = q.correctAnswer;
            if (q.shuffledToOriginal) {
                origIdx = q.shuffledToOriginal[q.correctAnswer];
            }
            // MIGRATE-EXTERNAL-SERVICES (v1.5.152): saveanswer now runs through the declared
            // mod_aiknowledgecheck_save_answer service. The sequential chain must advance
            // whether the call succeeds or fails, so both handlers call saveNext — the old
            // jQuery `complete` hook has no equivalent on a core/ajax promise.
            var advance = function() {
                saveNext(i + 1);
            };
            Ajax.call([{
                methodname: 'mod_aiknowledgecheck_save_answer',
                args: {
                    attemptid: parseInt(currentAttemptId, 10),
                    questionid: parseInt(q.id, 10),
                    answerindex: origIdx
                }
            }])[0].done(advance).fail(function(err) {
                kcError('[KC] Carry-forward save failed:', err);
                advance();
            });
        }
        saveNext(0);
    }

    /**
     * Reset the per-attempt state for a wrong-only retake and show the first question.
     *
     * @return {void}
     */
    function startQuizWrongOnly() {
        currentQuestionIndex = 0;
        score = retryCorrectCarryOver;
        selectedAnswer = null;

        // V1.5.21 ATTEMPT-TRACKING: snapshot the current log so carry-forward entries
        // can preserve their original attempt number in the rebuilt log.
        var previousLog = quizAnswerLog.slice();
        currentAttemptNum++; // New attempt number for wrong-only retry questions
        kcLog('[KC] Starting wrong-only retry  -  attempt', currentAttemptNum,
            ' -  wrong Q indices:', wrongQuestionIndices);

        quizAnswerLog = [];

        // Step 1: carry-forward one correct entry per already-correct question,
        // preserving the attemptNum of the LATEST correct answer in the snapshot
        // (iterate in reverse so the first match found is the most recent one).
        quizData.forEach(function(q, idx) {
            if (wrongQuestionIndices.indexOf(idx) === -1) {
                var prevEntry = null;
                for (var pi = previousLog.length - 1; pi >= 0; pi--) {
                    if (previousLog[pi].questionNum === idx + 1 && previousLog[pi].isCorrect) {
                        prevEntry = previousLog[pi];
                        break;
                    }
                }
                quizAnswerLog.push({
                    questionNum:  idx + 1,
                    question:     q.question,
                    options:      q.options ? q.options.slice() : [],
                    correctIndex: q.correctAnswer,
                    selectedIndex: q.correctAnswer,
                    isCorrect:    true,
                    attemptNum:   prevEntry ? (prevEntry.attemptNum || (currentAttemptNum - 1)) : (currentAttemptNum - 1),
                    explanation:  q.explanations ? (q.explanations[q.correctAnswer] || '') : ''
                });
            }
        });

        // Step 2: BUG-MISSING-ATTEMPT-HISTORY (v1.5.24): preserve ALL incorrect entries
        // from previous attempts so the PDF/text download shows every attempt including
        // ones where the student answered wrong. Without this, the entry for attempt N
        // where a question was answered incorrectly was silently discarded on the next
        // rebuild, leaving a gap (e.g. "Attempt 3 is missing") in the exported file.
        previousLog.forEach(function(prevEntry) {
            if (!prevEntry.isCorrect) {
                quizAnswerLog.push(prevEntry);
            }
        });

        $('#kc-ready-section').hide();
        $('#kc-quiz-player').show();

        showQuestionWrongOnly();
    }

    /**
     * Render the current question of a wrong-only retake.
     *
     * @return {void}
     */
    function showQuestionWrongOnly() {
        if (currentQuestionIndex >= wrongQuestionIndices.length) {
            retryWrongOnly = false;
            showResults();
            return;
        }
        var realIdx = wrongQuestionIndices[currentQuestionIndex];
        var q = quizData[realIdx];

        $('#question-counter').text('Question ' + (currentQuestionIndex + 1) + ' of ' + wrongQuestionIndices.length + ' (retry)');
        $('#quiz-score').text(fmt(S.score, {correct: score, total: quizData.length}));
        $('#question-text').text(q.question);

        // ADD-KC-MEDIAPER-Q (v1.5.120): Unified per-question media gate in wrong-only retry.
        // Keyed by realIdx so media already acknowledged in round 1 stays unlocked in retry round.
        $('#kc-question-media').remove();
        var hasWQImage = !!(q.imageEnabled && q.imageUrl);
        var hasWQVideo = !!(q.questionVideoEnabled && q.questionVideoUrl);
        var hasWQAudio = !!(q.questionAudioEnabled && q.questionAudioUrl);
        var hasWQMedia = hasWQImage || hasWQVideo || hasWQAudio;
        var wqMediaAcked = acknowledgedQuestions[realIdx] === true;
        var needsWQMediaGate = hasWQMedia && !wqMediaAcked;

        if (hasWQMedia) {
            var wqMediaHtml = '<div id="kc-question-media" style="margin-bottom: 14px;">';
            if (hasWQImage) {
                wqMediaHtml += '<div style="text-align: center; margin-bottom: 10px;">' +
                    '<img src="' + q.imageUrl.replace(/"/g, '&quot;') + '" alt="Question image" ' +
                        'style="max-width: 100%; max-height: 400px; border-radius: 8px; ' +
                        'object-fit: contain; display: inline-block;">' +
                    '</div>';
            }
            if (hasWQVideo) {
                var wqVidId = extractYouTubeId(q.questionVideoUrl);
                if (wqVidId) {
                    wqMediaHtml += '<div style="text-align: center; margin-bottom: 10px;">' +
                        '<div style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; max-width: 640px; m' +
                            'argin: 0 auto; border-radius: 8px;">' +
                        '<iframe src="https://www.youtube.com/embed/' + wqVidId + '" style="position: absolute; top: 0; left: 0; ' +
                            'width: 100%; height: 100%; border-radius: 8px;" frameborder="0" allow="accelerometer; autoplay; clip' +
                            'board-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>' +
                        '</div></div>';
                }
            }
            if (hasWQAudio) {
                wqMediaHtml += '<div style="margin-bottom: 10px; text-align: center;">' +
                    '<audio controls style="width: 100%; max-width: 500px;">' +
                    '<source src="' + q.questionAudioUrl.replace(/"/g, '&quot;') + '">' +
                    '</audio></div>';
            }
            if (needsWQMediaGate) {
                wqMediaHtml += '<div id="kc-q-media-gate" style="text-align: center; margin-top: 10px;">' +
                    '<button id="kc-q-media-ack-btn" class="kc-btn kc-btn-primary" type="button">' +
                    'I\'ve reviewed this content &#8212; Continue' +
                    '</button></div>';
            } else {
                wqMediaHtml += '<div style="text-align: center; margin-top: 6px; font-size: 12px; color: #28a745;">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#28a7' +
                        '45" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;margin-ri' +
                        'ght:3px;"><polyline points="20 6 9 17 4 12"></polyline></svg>' +
                    'Content reviewed</div>';
            }
            wqMediaHtml += '</div>';
            $('#question-text').before(wqMediaHtml);
        }

        var optionsHtml = '';
        var letters = ['A', 'B', 'C', 'D', 'E'];
        q.options.forEach(function(option, index) {
            var optionText = (option || '').replace(/\.\s*$/, '').trim();
            // V1.5.52 FIX-OPTION-CAPITALISE: ensure first letter is always uppercase.
            if (optionText.length > 0) {
                optionText = optionText.charAt(0).toUpperCase() + optionText.slice(1);
            }
            optionsHtml += '<div class="kc-option" data-index="' + index + '"' +
                ' role="radio" aria-checked="false" tabindex="' + (index === 0 ? '0' : '-1') + '">';
            optionsHtml += '<span class="kc-option-letter">' + letters[index] + '</span>';
            optionsHtml += '<span class="kc-option-text">' + escapeHtml(optionText) + '</span>';
            optionsHtml += '</div>';
        });

        $('#options-container').html(optionsHtml);
        $('#feedback-container').hide();
        $('#check-answer-btn').show().prop('disabled', true);
        $('#next-question-btn').hide();
        selectedAnswer = null;

        // ADD-KC-MEDIAPER-Q (v1.5.120): Lock options + check button until all media acknowledged.
        if (needsWQMediaGate) {
            $('#options-container').css({'visibility': 'hidden', 'pointer-events': 'none'});
            $('#check-answer-btn').hide();
            $('#kc-q-media-ack-btn').on('click', function() {
                acknowledgedQuestions[realIdx] = true;
                $('#kc-q-media-gate').replaceWith(
                    '<div style="text-align: center; margin-top: 6px; font-size: 12px; color: #28a745;">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#28a7' +
                        '45" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;margin-ri' +
                        'ght:3px;"><polyline points="20 6 9 17 4 12"></polyline></svg>' +
                    'Content reviewed</div>'
                );
                $('#options-container').css({'visibility': 'visible', 'pointer-events': ''});
                $('#check-answer-btn').show().prop('disabled', true);
            });
        }

        $('.kc-option').on('click', function() {
            if ($(this).hasClass('disabled')) {
                return;
            }
            selectOption($(this));
            applyOptionSelectionWrongOnly($(this));
        });

        bindOptionKeyboard(applyOptionSelectionWrongOnly);
        focusQuestion();
    }

    /**
     * Record the chosen option during a wrong-only retake and enable the check button.
     *
     * @param {Object} $option The chosen option element.
     * @return {void}
     */
    function applyOptionSelectionWrongOnly($option) {
        selectedAnswer = parseInt($option.data('index'), 10);
        $('#check-answer-btn').prop('disabled', false);
    }

    /**
     * Grade the selected answer during a wrong-only retake.
     *
     * @return {void}
     */
    function checkAnswerWrongOnly() {
        if (selectedAnswer === null) {
            return;
        }

        var realIdx = wrongQuestionIndices[currentQuestionIndex];
        var q = quizData[realIdx];

        // SECURITY (C2): resolve the withheld correct answer from the server, then re-run (retry mode).
        if (q.correctAnswer === null || q.correctAnswer === undefined) {
            if (q._resolvingAnswer) {
                return;
            }
            q._resolvingAnswer = true;
            $('#check-answer-btn').prop('disabled', true);
            var origIdxResolveWO = q.shuffledToOriginal ? q.shuffledToOriginal[selectedAnswer] : selectedAnswer;
            resolveCorrectAnswer(q, origIdxResolveWO, function() {
                q._resolvingAnswer = false;
                checkAnswerWrongOnly();
            });
            return;
        }

        var isCorrect = selectedAnswer === q.correctAnswer;

        quizAnswerLog.push({
            questionNum:  realIdx + 1,
            question:     q.question,
            options:      q.options ? q.options.slice() : [],
            correctIndex: q.correctAnswer,
            selectedIndex: selectedAnswer,
            isCorrect:    isCorrect,
            attemptNum:   currentAttemptNum,
            explanation:  q.explanations ? (isCorrect
                ? (q.explanations[q.correctAnswer] || '')
                : (q.explanations[selectedAnswer] || q.explanations[q.correctAnswer] || '')) : ''
        });

        if (q.id && !q._answerSaved) {
            var originalIndex = q.shuffledToOriginal ? q.shuffledToOriginal[selectedAnswer] : selectedAnswer;
            saveAnswerToDatabase(q.id, originalIndex);
        }

        if (isCorrect) {
            score++;
            playCorrectSound();
        } else {
            playIncorrectSound();
        }

        $('.kc-option').addClass('disabled').attr('aria-disabled', 'true').attr('tabindex', '-1');
        $('.kc-option').each(function() {
            var index = parseInt($(this).data('index'), 10);
            if (index === q.correctAnswer) {
                $(this).addClass('correct');
            } else if (index === selectedAnswer && !isCorrect) {
                $(this).addClass('incorrect');
            }
        });

        // FIX-KC-SELECTED-AUDIO: v1.5.74  -  play selected option's audio/explanation (retry mode).
        var explanationIdxWO = isCorrect ? q.correctAnswer : selectedAnswer;
        var explanationToShowWO = (q.explanations && q.explanations[explanationIdxWO]) || '';
        $('#feedback-result').text(isCorrect ? S.correct : S.incorrect)
            .removeClass('correct incorrect')
            .addClass(isCorrect ? 'correct' : 'incorrect');
        $('#feedback-explanation').text(explanationToShowWO);
        $('#feedback-container').show();
        $('#play-audio-btn').hide();
        $('#check-answer-btn').hide();

        var voiceoverOn = $('#voiceover-toggle').is(':checked') || !!config.voiceoverEnabled;
        var audioIdxWO = isCorrect ? q.correctAnswer : selectedAnswer; // FIX-KC-SELECTED-AUDIO
        var hasAudioForAnswer = q.audioData && q.audioData[audioIdxWO];
        var shouldGate = !isCorrect && voiceoverOn && hasAudioForAnswer;

        if (currentQuestionIndex < wrongQuestionIndices.length - 1) {
            $('#next-question-btn').text(S.nextquestion).show().prop('disabled', shouldGate);
        } else {
            $('#next-question-btn').text(S.finishquiz).show().prop('disabled', shouldGate);
        }

        if (voiceoverOn && hasAudioForAnswer) {
            playExplanationAudio(q, audioIdxWO, shouldGate);
        }

        $('#quiz-score').text(fmt(S.score, {correct: score, total: quizData.length}));
    }

    /**
     * Advance to the next question of a wrong-only retake.
     *
     * @return {void}
     */
    function nextQuestionWrongOnly() {
        stopAudio();
        if (currentQuestionIndex < wrongQuestionIndices.length - 1) {
            currentQuestionIndex++;
            if (currentAttemptId) {
                var storageKey = 'kc_progress_' + config.cmid + '_' + currentAttemptId;
                localStorage.setItem(storageKey, currentQuestionIndex);
            }
            showQuestionWrongOnly();
        } else {
            retryWrongOnly = false;
            showResults();
        }
    }

    /**
     * Stop and reset any explanation audio that is currently playing.
     *
     * @return {void}
     */
    function stopAudio() {
        if (audioElement) {
            audioElement.pause();
            audioElement = null;
        }
    }

    // ==========================================
    // EDIT MODE FUNCTIONS
    // ==========================================

    var originalQuizData = null; // Store original data for cancel

    /**
     * Switch the teacher view into question-editing mode.
     *
     * @return {void}
     */
    function showEditMode() {
        // FIX-KC-GUARD-EDITMODE: Abort if quizData is empty to prevent showing blank edit
        // forms which, when saved, would wipe all questions from the database.
        if (!quizData || quizData.length === 0) {
            kcAlert(S.errornoquestionstoedit);
            return;
        }

        kcLog('[KC] Entering edit mode with', quizData.length, 'questions');

        // Warn the teacher when students are mid-attempt, then continue in the callback. The
        // modal is asynchronous, so the rest of this function moved into enterEditMode().
        if (config.inProgressAttempts && config.inProgressAttempts > 0) {
            var warning = config.inProgressAttempts === 1
                ? S.confirmeditinprogressone
                : fmt(S.confirmeditinprogressmany, config.inProgressAttempts);
            kcConfirm(warning, enterEditMode);
            return;
        }

        enterEditMode();
    }

    /**
     * Switch the interface into edit mode, once any warning has been accepted.
     *
     * @return {void}
     */
    function enterEditMode() {
        // Store original data for cancel
        originalQuizData = JSON.parse(JSON.stringify(quizData));

        var readyInstructions = $('#ready-extra-instructions').val() || '';
        $('#edit-extra-instructions').val(readyInstructions);
        updateRegenCountDisplay();

        // Hide ready section, show edit section
        $('#kc-ready-section').hide();
        $('#kc-edit-section').show();

        // Build edit forms for each question
        buildEditForms();
    }

    /**
     * Build the editable form markup for every question.
     *
     * @return {void}
     */
    function buildEditForms() {
        var container = $('#edit-questions-container');
        container.empty();

        quizData.forEach(function(q, idx) {
            var correctAnswer = q.correctAnswer !== undefined ? q.correctAnswer : 0;
            // FIX-KC-EDIT-SURVEY (v1.5.139): the editor was written for 4-option quizzes and
            // was never updated for survey mode.
            var isFreeText = (q.questionType === 'freetext');
            var isSurvey = !!config.surveyMode;

            var html = '<div class="kc-edit-question" data-question-index="' + idx + '"' +
                ' data-question-type="' + (isFreeText ? 'freetext' : 'scale') + '">' +
                '<div class="kc-edit-question-header">' +
                    '<span class="kc-edit-question-number">Question ' + (idx + 1) + '</span>' +
                    '<div class="kc-edit-question-actions">' +
                        '<button type="button" class="kc-btn-delete-question" data-index="' +
                             idx +
                             '" title="Delete this question">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" strok' +
                                'e="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2' +
                                ' 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>' +
                        '</button>' +
                    '</div>' +
                '</div>' +
                '<div class="kc-edit-field">' +
                    '<label>Question Text</label>' +
                    '<textarea class="kc-edit-question-text" data-index="' +
                         idx +
                         '" rows="3">' +
                         escapeHtml(q.question) +
                         '</textarea>' +
                '</div>';

            if (isFreeText) {
                // Free-text questions have no answer options — the student types a response.
                // Rendering them as multiple choice previously made them unsaveable ("Option A
                // cannot be empty") and reset them to scale questions on save.
                html += '<div class="kc-edit-options kc-edit-options-freetext">' +
                    '<label>Answer Format</label>' +
                    '<div style="padding: 10px 12px; background: #f8f9fa; border: 1px dashed #ced4da; border-radius: 6px; font-si' +
                        'ze: 13px; color: #6c757d;">' +
                        '<strong>Free text response.</strong> Students type their own answer, so this ' +
                        'question has no answer options and no correct answer.' +
                    '</div>' +
                '</div>';
            } else {
                html += '<div class="kc-edit-options">' +
                    '<label>Answer Options' +
                        (isSurvey ? '' : ' <span class="kc-edit-hint">(select the correct answer)</span>') +
                    '</label>';

                // Render exactly as many options as the question actually has, rather than a
                // hardcoded 4. Survey scales may be 2, 3, 4 or 5 points; forcing a floor of 4
                // would render blank boxes that then fail the non-empty validation on save.
                // Quiz questions keep the historic 4-option minimum.
                var optionLabels = ['A', 'B', 'C', 'D', 'E'];
                var optCount = (q.options && q.options.length) ? q.options.length : 4;
                if (!isSurvey && optCount < 4) {
                    optCount = 4;
                }
                if (optCount < 1) {
                    optCount = 1;
                }
                if (optCount > 5) {
                    optCount = 5;
                }

                for (var i = 0; i < optCount; i++) {
                    var optionText = q.options && q.options[i] ? q.options[i] : '';
                    var isCorrect = (!isSurvey && correctAnswer === i);
                    var explanation = q.explanations && q.explanations[i] ? q.explanations[i] : '';

                html += '<div class="kc-edit-option ' + (isCorrect ? 'kc-edit-option-correct' : '') + '">' +
                    '<div class="kc-edit-option-header">' +
                        '<label class="kc-edit-option-radio">' +
                            (isSurvey ? '' :
                             '<input type="radio" name="correct-' +
                                  idx +
                                  '" value="' +
                                  i +
                                  '" ' +
                                  (isCorrect ? 'checked' : '') +
                                  '>') +
                            '<span class="kc-option-label">' + optionLabels[i] + '</span>' +
                        '</label>' +
                        '<input type="text" class="kc-edit-option-text" data-question="' +
                             idx +
                             '" data-option="' +
                             i +
                             '" value="' +
                             escapeAttr(optionText) +
                             '" placeholder="Option ' +
                             optionLabels[i] +
                             '">' +
                    '</div>' +
                    '<div class="kc-edit-explanation"' + (isSurvey ? ' style="display:none;"' : '') + '>' +
                        '<textarea class="kc-edit-explanation-text" data-question="' +
                             idx +
                             '" data-option="' +
                             i +
                             '" rows="2" placeholder="Explanation for this option...">' +
                             escapeHtml(explanation) +
                             '</textarea>' +
                    '</div>' +
                '</div>';
                }
            }

            // ADD-KC-IMAGEGATE (v1.5.115): Per-question image controls.
            var imgEnabled = q.imageEnabled ? true : false;
            var imgUrl = q.imageUrl || '';
            html += '<div class="kc-edit-imagegate" style="border-top: 1px solid #eee; margin-top: 10px; padding-top: 10px;">' +
                '<label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin-bottom: 8px; font-size: 13px' +
                    ';">' +
                    '<input type="checkbox" class="kc-edit-image-enabled" data-index="' +
                         idx +
                         '"' +
                         (imgEnabled ? ' checked' : '') +
                         '>' +
                    '<span>Show image with this question</span>' +
                '</label>' +
                '<div class="kc-edit-image-fields" style="' + (imgEnabled ? '' : 'display:none;') + '">' +
                    '<div style="display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 6px;">' +
                        '<input type="url" class="kc-edit-image-url" data-index="' + idx + '" value="' + escapeAttr(imgUrl) + '" ' +
                            'placeholder="https://example.com/image.jpg" style="flex: 1; min-width: 200px; padding: 5px 8px; bord' +
                            'er: 1px solid #ced4da; border-radius: 4px; font-size: 12px;">' +
                        '<button type="button" class="kc-btn kc-btn-secondary kc-question-imagegen-btn" data-index="' +
                             idx +
                             '" style="font-size: 12px; white-space: nowrap;">Generate (5 credits)</button>' +
                    '</div>' +
                    '<div class="kc-edit-image-preview" style="' + (imgUrl && imgEnabled ? '' : 'display:none;') + '">' +
                        '<img class="kc-edit-image-preview-img" src="' + escapeAttr(imgUrl) + '" alt="Preview" style="max-width: ' +
                            '200px; max-height: 150px; border-radius: 6px; object-fit: contain; display: block; margin-bottom: 4p' +
                            'x;">' +
                    '</div>' +
                    '<div class="kc-question-imagegen-status" data-index="' +
                         idx +
                         '" style="font-size: 11px; color: #6c757d; display: none;"></div>' +
                '</div>' +
            '</div>';

            // ADD-KC-MEDIAPER-Q (v1.5.120): Per-question YouTube video controls.
            var vidEnabled = q.questionVideoEnabled ? true : false;
            var vidUrl = q.questionVideoUrl || '';
            var vidId = extractYouTubeId(vidUrl);
            var vidThumbHtml = (vidUrl && vidEnabled && vidId)
                ? '<div class="kc-edit-video-preview" style="margin-bottom:6px;"><img src="https://img.youtube.com/vi/' +
                     vidId +
                     '/hqdefault.jpg" alt="Thumbnail" style="max-width:200px;border-radius:6px;display:block;"></div>'
                : '<div class="kc-edit-video-preview" style="display:none;margin-bottom:6px;"><img class="kc-edit-video-thumb-img' +
                    '" alt="Thumbnail" src="" style="max-width:200px;border-radius:6px;display:block;"></div>';
            html += '<div class="kc-edit-videomedia" style="border-top:1px solid #eee;margin-top:8px;padding-top:8px;">' +
                '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:8px;font-size:13px;">' +
                    '<input type="checkbox" class="kc-edit-video-enabled" data-index="' +
                         idx +
                         '"' +
                         (vidEnabled ? ' checked' : '') +
                         '>' +
                    '<span>Show YouTube video with this question</span>' +
                '</label>' +
                '<div class="kc-edit-video-fields" style="' + (vidEnabled ? '' : 'display:none;') + '">' +
                    '<input type="url" class="kc-edit-video-url" data-index="' + idx + '" value="' + escapeAttr(vidUrl) + '" plac' +
                        'eholder="https://www.youtube.com/watch?v=..." style="width:100%;box-sizing:border-box;padding:5px 8px;bo' +
                        'rder:1px solid #ced4da;border-radius:4px;font-size:12px;margin-bottom:6px;">' +
                    vidThumbHtml +
                '</div>' +
            '</div>';

            // ADD-KC-MEDIAPER-Q (v1.5.120): Per-question audio controls.
            var audEnabled = q.questionAudioEnabled ? true : false;
            var audUrl = q.questionAudioUrl || '';
            var audPlayerHtml = (audUrl && audEnabled)
                ? '<div class="kc-edit-audio-player" style="margin-bottom:6px;"><audio controls style="width:100%;max-width:340px' +
                    ';display:block;"><source src="' + escapeAttr(audUrl) + '"></audio></div>'
                : '<div class="kc-edit-audio-player" style="display:none;margin-bottom:6px;"><audio controls style="width:100%;ma' +
                    'x-width:340px;display:block;"></audio></div>';
            html += '<div class="kc-edit-audiomedia" style="border-top:1px solid #eee;margin-top:8px;padding-top:8px;">' +
                '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:8px;font-size:13px;">' +
                    '<input type="checkbox" class="kc-edit-audio-enabled" data-index="' +
                         idx +
                         '"' +
                         (audEnabled ? ' checked' : '') +
                         '>' +
                    '<span>Play audio clip with this question</span>' +
                '</label>' +
                '<div class="kc-edit-audio-fields" style="' + (audEnabled ? '' : 'display:none;') + '">' +
                    '<input type="url" class="kc-edit-audio-url" data-index="' + idx + '" value="' + escapeAttr(audUrl) + '" plac' +
                        'eholder="https://example.com/audio.mp3" style="width:100%;box-sizing:border-box;padding:5px 8px;border:1' +
                        'px solid #ced4da;border-radius:4px;font-size:12px;margin-bottom:6px;">' +
                    audPlayerHtml +
                '</div>' +
            '</div>';

            html += '</div></div>';
            container.append(html);
        });

        // Bind events
        // ADD-KC-IMAGEGATE: toggle image fields visibility when checkbox changes.
        container.find('.kc-edit-image-enabled').on('change', function() {
            var $fields = $(this).closest('.kc-edit-imagegate').find('.kc-edit-image-fields');
            if ($(this).is(':checked')) {
                $fields.show();
            } else {
                $fields.hide();
            }
        });

        // ADD-KC-IMAGEGATE: bind per-question image generation buttons.
        container.find('.kc-question-imagegen-btn').on('click', function() {
            var qIdx = parseInt($(this).data('index'));
            var $btn = $(this);
            var $urlInput = $(this).closest('.kc-edit-image-fields').find('.kc-edit-image-url');
            var $statusDiv = $(this).closest('.kc-edit-imagegate').find('.kc-question-imagegen-status[data-index="' + qIdx + '"]');
            var $previewDiv = $(this).closest('.kc-edit-image-fields').find('.kc-edit-image-preview');
            var $previewImg = $previewDiv.find('.kc-edit-image-preview-img');
            var promptText = quizData[qIdx] ? quizData[qIdx].question : ('Question ' + (qIdx + 1));
            $btn.prop('disabled', true).text(S.generating);
            $statusDiv.show().text(S.generatingimage).css('color', '#6c757d');
            // MIGRATE-EXTERNAL-SERVICES (v1.5.152): generateimage now runs through the
            // declared mod_aiknowledgecheck_generate_image service.
            Ajax.call([{
                methodname: 'mod_aiknowledgecheck_generate_image',
                args: {
                    cmid: parseInt(config.cmid, 10),
                    prompt: promptText
                }
            }])[0].done(function(resp) {
                    $btn.prop('disabled', false).text(S.generateimagecost);
                    if (resp.ok && resp.imageDataUrl) {
                        $urlInput.val(resp.imageDataUrl);
                        $previewImg.attr('src', resp.imageDataUrl);
                        $previewDiv.show();
                        $statusDiv.text(S.imagegenerated).css('color', '#28a745');
                    } else {
                        $statusDiv.text(resp.error || S.errorgenerationfailed).css('color', '#dc3545');
                    }
            }).fail(function() {
                $btn.prop('disabled', false).text(S.generateimagecost);
                $statusDiv.text(S.errorrequestfailed).css('color', '#dc3545');
            });
        });

        // ADD-KC-IMAGEGATE: live-preview image URL when pasted/changed.
        container.find('.kc-edit-image-url').on('change', function() {
            var url = $(this).val().trim();
            var $previewDiv = $(this).closest('.kc-edit-image-fields').find('.kc-edit-image-preview');
            var $previewImg = $previewDiv.find('.kc-edit-image-preview-img');
            if (url) {
                $previewImg.attr('src', url);
                $previewDiv.show();
            } else {
                $previewDiv.hide();
            }
        });

        // ADD-KC-MEDIAPER-Q (v1.5.120): Video checkbox — show/hide URL field and thumbnail.
        container.find('.kc-edit-video-enabled').on('change', function() {
            var $fields = $(this).closest('.kc-edit-videomedia').find('.kc-edit-video-fields');
            if ($(this).is(':checked')) {
                $fields.show();
            } else {
                $fields.hide();
            }
        });

        // ADD-KC-MEDIAPER-Q (v1.5.120): Video URL change — update YouTube thumbnail preview.
        container.find('.kc-edit-video-url').on('change', function() {
            var url = $(this).val().trim();
            var $prev = $(this).closest('.kc-edit-video-fields').find('.kc-edit-video-preview');
            var $thumb = $prev.find('img');
            var vid = extractYouTubeId(url);
            if (vid) {
                $thumb.attr('src', 'https://img.youtube.com/vi/' + vid + '/hqdefault.jpg');
                $prev.show();
            } else {
                $thumb.attr('src', '');
                $prev.hide();
            }
        });

        // ADD-KC-MEDIAPER-Q (v1.5.120): Audio checkbox — show/hide URL field and player.
        container.find('.kc-edit-audio-enabled').on('change', function() {
            var $fields = $(this).closest('.kc-edit-audiomedia').find('.kc-edit-audio-fields');
            if ($(this).is(':checked')) {
                $fields.show();
            } else {
                $fields.hide();
            }
        });

        // ADD-KC-MEDIAPER-Q (v1.5.120): Audio URL change — refresh HTML5 player source.
        container.find('.kc-edit-audio-url').on('change', function() {
            var url = $(this).val().trim();
            var $player = $(this).closest('.kc-edit-audio-fields').find('.kc-edit-audio-player');
            var $audio = $player.find('audio');
            if (url) {
                $audio.find('source').remove();
                $audio.append('<source src="' + url.replace(/"/g, '&quot;') + '">');
                if ($audio[0]) {
                    $audio[0].load();
                }
                $player.show();
            } else {
                $audio.find('source').remove();
                $player.hide();
            }
        });

        $('.kc-edit-option input[type="radio"]').on('change', function() {
            var $option = $(this).closest('.kc-edit-option');
            var $question = $(this).closest('.kc-edit-question');
            $question.find('.kc-edit-option').removeClass('kc-edit-option-correct');
            $option.addClass('kc-edit-option-correct');
        });

        $('.kc-btn-delete-question').on('click', function() {
            var idx = parseInt($(this).data('index'));
            if (quizData.length <= 1) {
                kcAlert(S.errorcannotdeletelastquestion);
                return;
            }
            kcConfirm(fmt(S.confirmdeletequestion, idx + 1), function() {
                quizData.splice(idx, 1);
                buildEditForms();
            });
        });

    }

    /**
     * Escape a string for safe use as HTML text.
     *
     * @param {string} text The raw text.
     * @return {string} The escaped text.
     */
    function escapeHtml(text) {
        if (!text) {
            return '';
        }
        return text.replace(/&/g, '&amp;')
                   .replace(/</g, '&lt;')
                   .replace(/>/g, '&gt;')
                   .replace(/"/g, '&quot;')
                   .replace(/'/g, '&#039;');
    }

    /**
     * Escape a string for safe use inside an HTML attribute value.
     *
     * @param {string} text The raw text.
     * @return {string} The escaped text.
     */
    function escapeAttr(text) {
        if (!text) {
            return '';
        }
        return text.replace(/&/g, '&amp;')
                   .replace(/</g, '&lt;')
                   .replace(/>/g, '&gt;')
                   .replace(/"/g, '&quot;')
                   .replace(/'/g, '&#039;');
    }

    /**
     * Validate the edit forms and save the edited questions.
     *
     * @return {void}
     */
    function saveEdits() {
        kcLog('[KC] Saving edited questions');

        // Collect edited data from forms
        var editedQuestions = [];
        var hasErrors = false;

        $('#edit-questions-container .kc-edit-question').each(function() {
            var $q = $(this);
            var idx = parseInt($q.data('question-index'));

            var questionText = $q.find('.kc-edit-question-text').val().trim();
            if (!questionText) {
                hasErrors = true;
                kcAlert(fmt(S.erroremptyquestiontext, idx + 1));
                return false;
            }

            var options = [];
            var explanations = [];
            var correctAnswer = 0;

            var hasCorrectSelected = false;
            // FIX-KC-EDIT-SURVEY (v1.5.139): free-text questions have no options and no correct
            // answer, so option/correct-answer validation must not run for them.
            var isFreeText = ($q.attr('data-question-type') === 'freetext') ||
                             (quizData[idx] && quizData[idx].questionType === 'freetext');
            var isSurvey = !!config.surveyMode;

            $q.find('.kc-edit-option').each(function(optIdx) {
                var optionText = $(this).find('.kc-edit-option-text').val().trim();
                var explanationText = $(this).find('.kc-edit-explanation-text').val().trim();

                if (!optionText) {
                    hasErrors = true;
                    kcAlert(fmt(S.erroremptyoption, {number: idx + 1, letter: String.fromCharCode(65 + optIdx)}));
                    return false;
                }

                options.push(optionText);
                explanations.push(explanationText);

                if ($(this).find('input[type="radio"]').is(':checked')) {
                    correctAnswer = optIdx;
                    hasCorrectSelected = true;
                }
            });

            if (hasErrors) {
                return false;
            }

            // Validate that a correct answer is selected. Skipped for free-text questions
            // (no options) and in survey mode (no correct answer by definition).
            if (!isFreeText && !isSurvey && !hasCorrectSelected) {
                hasErrors = true;
                kcAlert(fmt(S.errornocorrectanswerselected, idx + 1));
                return false;
            }

            editedQuestions.push({
                question: questionText,
                options: options,
                explanations: explanations,
                correctAnswer: correctAnswer,
                // FIX-KC-EDIT-SURVEY (v1.5.139): carry question type through the edit round
                // trip; it was omitted, so ajax.php fell back to its 'scale' default and every
                // free-text question was converted to multiple choice on save.
                questionType: isFreeText ? 'freetext' : ((quizData[idx] && quizData[idx].questionType) || 'scale'),
                audioData: ($('#voiceover-toggle').is(':checked') && quizData[idx]) ? quizData[idx].audioData : null,
                // FIX-KC-SAVEEDITS-TIMESTAMP (v1.5.111): Preserve timestamp_seconds,
                // mappingTopic, and mappingCriteria from the original quizData entry.
                // saveEdits() previously omitted these fields, so any save from the
                // Edit Questions section silently wiped them from quizData in memory.
                // On the next regeneration the server received timestamp_seconds=null,
                // the preserve step never fired, and the DB stored null for every
                // question — making Jump-to chapter-stamp links permanently disappear
                // until the teacher regenerated from a freshly-loaded page (no edits).
                mappingTopic:       quizData[idx] ? (quizData[idx].mappingTopic || '') : '',
                mappingCriteria:    quizData[idx] ? (quizData[idx].mappingCriteria || '') : '',
                timestamp_seconds:  quizData[idx]
                    ? (quizData[idx].timestamp_seconds !== undefined ? quizData[idx].timestamp_seconds : null)
                    : null,
                // ADD-KC-IMAGEGATE (v1.5.115): Read per-question image from DOM; fall back
                // to existing quizData values if the DOM fields are not present.
                imageUrl:           $q.find('.kc-edit-image-url').length
                    ? $q.find('.kc-edit-image-url').val().trim()
                    : (quizData[idx] ? (quizData[idx].imageUrl || '') : ''),
                imageEnabled:       $q.find('.kc-edit-image-enabled').length
                    ? $q.find('.kc-edit-image-enabled').is(':checked')
                    : (quizData[idx] ? !!quizData[idx].imageEnabled : false),
                // ADD-KC-MEDIAPER-Q (v1.5.120): Read per-question video and audio from DOM.
                questionVideoUrl:     $q.find('.kc-edit-video-url').length
                    ? $q.find('.kc-edit-video-url').val().trim()
                    : (quizData[idx] ? (quizData[idx].questionVideoUrl || '') : ''),
                questionVideoEnabled: $q.find('.kc-edit-video-enabled').length
                    ? $q.find('.kc-edit-video-enabled').is(':checked')
                    : (quizData[idx] ? !!quizData[idx].questionVideoEnabled : false),
                questionAudioUrl:     $q.find('.kc-edit-audio-url').length
                    ? $q.find('.kc-edit-audio-url').val().trim()
                    : (quizData[idx] ? (quizData[idx].questionAudioUrl || '') : ''),
                questionAudioEnabled: $q.find('.kc-edit-audio-enabled').length
                    ? $q.find('.kc-edit-audio-enabled').is(':checked')
                    : (quizData[idx] ? !!quizData[idx].questionAudioEnabled : false)
            });
        });

        if (hasErrors) {
            return;
        }

        // FIX-KC-GUARD-SAVEEDITS: If no questions were collected and no validation errors
        // fired, the edit container must have been empty  -  abort rather than wiping the DB.
        if (editedQuestions.length === 0) {
            kcAlert(S.errornoeditforms);
            return;
        }

        // Detect whether any question content actually changed vs. what was loaded into the
        // edit form (originalQuizData). If nothing changed we should NOT regenerate TTS audio  -
        // it is already valid and re-generating wastes credits (common after a failed regen
        // where the teacher clicks Save Changes without editing anything).
        var questionsContentChanged = false;
        if (!originalQuizData || editedQuestions.length !== originalQuizData.length) {
            questionsContentChanged = true;
        } else {
            for (var ci = 0; ci < editedQuestions.length; ci++) {
                var _eq = editedQuestions[ci];
                var _oq = originalQuizData[ci];
                if (_eq.question !== _oq.question ||
                    JSON.stringify(_eq.options) !== JSON.stringify(_oq.options) ||
                    JSON.stringify(_eq.explanations) !== JSON.stringify(_oq.explanations) ||
                    _eq.correctAnswer !== _oq.correctAnswer) {
                    questionsContentChanged = true;
                    break;
                }
            }
        }

        // Update quizData
        quizData = editedQuestions;

        // Show saving indicator
        $('#save-edits-btn').prop('disabled', true).html(
            '<svg class="kc-spinner" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" st' +
                'roke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg> Saving...'
        );

        // Save to database with proper async handling
        saveEditedQuestions(function(success) {
            if (success) {
                var voiceoverOn = $('#voiceover-toggle').is(':checked');
                // Only regenerate audio when question content actually changed.
                // If nothing changed (e.g., teacher hit Save after a failed regen
                // without editing), the existing TTS audio is still valid.
                if (voiceoverOn && questionsContentChanged) {
                    $('#save-edits-btn').html(
                        '<svg class="kc-spinner" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fi' +
                            'll="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg> Generating v' +
                            'oiceover...'
                    );

                    regenerateAudioWithCallback(function(audioSuccess) {
                        $('#save-edits-btn').prop('disabled', false).html(
                            '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" strok' +
                                'e="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 ' +
                                '0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> S' +
                                'ave Changes'
                        );

                        $('#kc-edit-section').hide();
                        $('#kc-ready-section').show();

                        if (audioSuccess) {
                            $('#ready-summary').text(quizData.length + ' questions saved with updated voiceover!');
                        } else {
                            $('#ready-summary').text(quizData.length + ' questions saved. Voiceover generation failed - you can t' +
                                'ry regenerating later.');
                        }
                    });
                } else {
                    $('#save-edits-btn').prop('disabled', false).html(
                        '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="c' +
                            'urrentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 ' +
                            '2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save Changes'
                    );

                    $('#kc-edit-section').hide();
                    $('#kc-ready-section').show();
                    $('#ready-summary').text(quizData.length + ' questions saved successfully!');
                }
            } else {
                // Save failed
                $('#save-edits-btn').prop('disabled', false).html(
                    '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="curre' +
                        'ntColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><po' +
                        'lyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save Changes'
                );
                kcAlert(S.errorsavequestionsfailed);
            }
        });
    }

    // Save edited questions with callback for proper async handling
    /**
     * Send the edited questions to the server.
     *
     * @param {Function} callback Called once the save has completed.
     * @return {void}
     */
    function saveEditedQuestions(callback) {
        var questionsForDb = quizData.map(function(q) {
            // FIX-KC-EDIT-SURVEY (v1.5.139): this payload hardcoded options[0..3] and omitted
            // questionType, causing two silent data losses on every save from the editor:
            //   - ajax.php sets answer5 = null when options[4] is absent, deleting the 5th
            //     point of 5-point survey scales;
            //   - ajax.php defaults questiontype to 'scale' when the field is absent,
            //     converting free-text questions into blank multiple-choice questions.
            var qType = q.questionType || 'scale';
            var opts = [];
            if (qType !== 'freetext') {
                var srcOpts = q.options || [];
                for (var oi = 0; oi < srcOpts.length && oi < 5; oi++) {
                    opts.push({
                        text: srcOpts[oi],
                        explanation: q.explanations ? (q.explanations[oi] || '') : ''
                    });
                }
            }
            return {
                question: q.question,
                options: opts,
                questionType: qType,
                correctIndex: q.correctAnswer,
                audioData: q.audioData || null,
                mappingTopic: q.mappingTopic || '',
                mappingCriteria: q.mappingCriteria || '',
                // FIX-KC-TIMESTAMP-SAVE: preserve timestamp_seconds so "Show chapter
                // timestamp links" (Video Gate) buttons survive edit → save round-trip.
                timestamp_seconds: (q.timestamp_seconds !== undefined && q.timestamp_seconds !== null) ? q.timestamp_seconds : null
            };
        });

        // MIGRATE-EXTERNAL-SERVICES (v1.5.152): savequestions now runs through the declared
        // mod_aiknowledgecheck_save_questions service.
        Ajax.call([{
            methodname: 'mod_aiknowledgecheck_save_questions',
            args: {
                cmid: parseInt(config.cmid, 10),
                questions: JSON.stringify(questionsForDb),
                voiceoverEnabled: $('#voiceover-toggle').is(':checked') ? 1 : 0,
                voiceLanguage: $('#voice-language').val() || '',
                voiceGender: $('#voice-gender').val() || '',
                voiceStyle: $('#voice-style').val() || ''
            }
        }])[0].done(function(response) {
                if (response.ok) {
                    kcLog('[KC] Questions saved:', response.saved);
                    callback(true);
                } else {
                    kcError('[KC] Save failed:', response.error);
                    callback(false);
                }
        }).fail(function(err) {
            kcError('[KC] Save request failed:', err);
            callback(false);
        });
    }

    // Regenerate audio with callback
    /**
     * Regenerate the explanation audio, then run a callback.
     *
     * @param {Function} callback Called once regeneration has finished.
     * @return {void}
     */
    function regenerateAudioWithCallback(callback) {
        var voiceLanguage = $('#voice-language').val() || 'en-AU';
        var voiceId = $('#voice-style').val() || 'Aoede';

        var questionsForApi = quizData.map(function(q) {
            return {
                id: q.id,
                question: q.question,
                options: q.options,
                explanations: q.explanations,
                correctAnswer: q.correctAnswer
            };
        });

        // MIGRATE-EXTERNAL-SERVICES (v1.5.152): regenerateaudio now runs through the declared
        // mod_aiknowledgecheck_regenerate_audio service.
        Ajax.call([{
            methodname: 'mod_aiknowledgecheck_regenerate_audio',
            args: {
                cmid: parseInt(config.cmid, 10),
                questions: JSON.stringify(questionsForApi),
                voiceLanguage: voiceLanguage,
                voiceId: voiceId
            }
        }])[0].done(function(envelope) {
            var response = unwrapService(envelope);
                if (response.ok && response.questions) {
                    kcLog('[KC] Audio regenerated for', response.questions.length, 'questions');

                    // Update quizData with new audio
                    for (var i = 0; i < response.questions.length; i++) {
                        if (quizData[i] && response.questions[i].audioData) {
                            quizData[i].audioData = response.questions[i].audioData;
                        }
                    }

                    // Save updated audio to database with proper async handling
                    saveEditedQuestions(function(saveSuccess) {
                        if (saveSuccess) {
                            kcLog('[KC] Audio data saved to database');
                            callback(true);
                        } else {
                            kcError('[KC] Failed to save audio data to database');
                            callback(false);
                        }
                    });
                } else {
                    kcError('[KC] Audio regeneration failed:', response.error);
                    callback(false);
                }
        }).fail(function(err) {
            kcError('[KC] Audio regeneration request failed:', err);
            callback(false);
        });
    }

    /**
     * Discard any edits and return to the quiz-ready screen.
     *
     * @return {void}
     */
    function cancelEdits() {
        kcConfirm(S.confirmdiscardchanges, function() {
            if (originalQuizData) {
                quizData = originalQuizData;
            }
            $('#kc-edit-section').hide();
            $('#kc-ready-section').show();
        });
    }

    /**
     * Open the activity settings modal and load the current values into it.
     *
     * @return {void}
     */
    function openSettingsModal() {
        kcLog('[KC] Opening settings modal');
        $('#settings-voice-language').val($('#voice-language').val() || 'en-AU');
        var voiceoverEnabled = $('#voiceover-toggle').is(':checked');
        $('#settings-voiceover-toggle').prop('checked', voiceoverEnabled);
        if (voiceoverEnabled) {
            $('#settings-voice-options').show();
        } else {
            $('#settings-voice-options').hide();
        }
        var currentGender = $('#voice-gender').val() || 'female';
        var currentStyle = $('#voice-style').val() || 'Aoede';
        $('#settings-voice-gender').val(currentGender);
        var $style = $('#settings-voice-style');
        fillVoiceOptions($style, currentGender);
        $style.val(currentStyle);
        updateSettingsWarning();
        $('#kc-settings-overlay').fadeIn(200);
    }

    /**
     * Close the activity settings modal.
     *
     * @return {void}
     */
    function closeSettingsModal() {
        $('#kc-settings-overlay').fadeOut(200);
    }

    /**
     * Save the activity settings, regenerating questions when the settings require it.
     *
     * @return {void}
     */
    function saveSettings() {
        kcLog('[KC] Saving settings');
        var newLanguage = $('#settings-voice-language').val();
        var newVoiceoverEnabled = $('#settings-voiceover-toggle').is(':checked');
        var newGender = $('#settings-voice-gender').val();
        var newStyle = $('#settings-voice-style').val();

        var oldLanguage = $('#voice-language').val();
        var oldVoiceoverEnabled = $('#voiceover-toggle').is(':checked');

        $('#voice-language').val(newLanguage);
        $('#voiceover-toggle').prop('checked', newVoiceoverEnabled);
        if (newVoiceoverEnabled) {
            $('#voice-settings-section').show();
        } else {
            $('#voice-settings-section').hide();
        }
        $('#voice-gender').val(newGender);
        handleGenderChange();
        setTimeout(function() {
            $('#voice-style').val(newStyle);
        }, 50);

        closeSettingsModal();

        var editInfoEl = $('.kc-edit-info');
        var origInfo = editInfoEl.text();

        $('#save-edits-btn').prop('disabled', true);
        $('#cancel-edits-btn').prop('disabled', true);
        $('#edit-settings-btn').prop('disabled', true);

        var languageChanged = (newLanguage !== oldLanguage);
        var voiceoverTurnedOff = (oldVoiceoverEnabled && !newVoiceoverEnabled);
        var voiceoverTurnedOn = (!oldVoiceoverEnabled && newVoiceoverEnabled);

        // Always persist voice settings to database first.
        // MIGRATE-EXTERNAL-SERVICES (v1.5.147): second endpoint moved off ajax.php.
        Ajax.call([{
            methodname: 'mod_aiknowledgecheck_save_voice_settings',
            args: {
                cmid: parseInt(config.cmid, 10),
                voiceoverenabled: !!newVoiceoverEnabled,
                voicelanguage: newLanguage,
                voicegender: newGender,
                voicestyle: newStyle
            }
        }])[0].done(function() {
            kcLog('[KC] Voice settings saved to database');
        }).fail(function(err) {
            kcError('[KC] Failed to save voice settings to database',
                err && err.message ? err.message : err);
        });

        if (voiceoverTurnedOff) {
            // Voiceover turned OFF: strip audio from quizData and save, no AI call needed
            editInfoEl.html(S.savingremovingaudio);

            for (var i = 0; i < quizData.length; i++) {
                quizData[i].audioData = null;
            }
            buildEditForms();
            saveEditedQuestions(function(saveSuccess) {
                enableSettingsButtons();
                if (saveSuccess) {
                    editInfoEl.text(S.voiceoverdisabled);
                    setTimeout(function() {
                        editInfoEl.text(origInfo);
                    }, 3000);
                } else {
                    kcAlert(S.errorsaveclicksavechanges);
                }
            });
        } else if (languageChanged) {
            // Language changed: regenerate questions via OpenAI (costs credits)
            editInfoEl.html(
                '<svg class="kc-spinner" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none' +
                    '" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 6px;"><circle cx="12" ' +
                    'cy="12" r="10"/></svg>' +
                'Regenerating questions in new language... This may take a moment.'
            );

            var currentQuestions = [];
            $('#edit-questions-container .kc-edit-question').each(function() {
                var $q = $(this);
                var questionText = $q.find('.kc-edit-question-text').val().trim();
                var options = [];
                var explanations = [];
                var correctAnswer = 0;
                $q.find('.kc-edit-option').each(function(optIdx) {
                    options.push($(this).find('.kc-edit-option-text').val().trim());
                    explanations.push($(this).find('.kc-edit-explanation-text').val().trim());
                    if ($(this).find('input[type="radio"]').is(':checked')) {
                        correctAnswer = optIdx;
                    }
                });
                // FIX-KC-REGEN-PAYLOAD (v1.5.81): use {text, explanation} object format
                // and correctIndex to match the API's expected input format.
                currentQuestions.push({
                    type: 'mcq',
                    question: questionText,
                    options: [
                        {text: options[0] || '', explanation: explanations[0] || ''},
                        {text: options[1] || '', explanation: explanations[1] || ''},
                        {text: options[2] || '', explanation: explanations[2] || ''},
                        {text: options[3] || '', explanation: explanations[3] || ''}
                    ],
                    correctIndex: correctAnswer
                });
            });

            if (currentQuestions.length === 0) {
                currentQuestions = quizData.map(function(q) {
                    // FIX-KC-EDIT-SURVEY (v1.5.139): same fix as saveEditedQuestions — send
                    // every option the question has (up to 5) and carry questionType.
                    var qType2 = q.questionType || 'scale';
                    var opts2 = [];
                    if (qType2 !== 'freetext') {
                        var srcOpts2 = q.options || [];
                        for (var oj = 0; oj < srcOpts2.length && oj < 5; oj++) {
                            opts2.push({
                                text: srcOpts2[oj] || '',
                                explanation: (q.explanations && q.explanations[oj]) || ''
                            });
                        }
                    }
                    return {
                        type: q.type || 'mcq',
                        question: q.question,
                        options: opts2,
                        questionType: qType2,
                        correctIndex: q.correctAnswer
                    };
                });
            }

            // MIGRATE-EXTERNAL-SERVICES (v1.5.152): regeneratewithsettings now runs through
            // the declared mod_aiknowledgecheck_regenerate_with_settings service.
            Ajax.call([{
                methodname: 'mod_aiknowledgecheck_regenerate_with_settings',
                args: {
                    cmid: parseInt(config.cmid, 10),
                    questions: JSON.stringify(currentQuestions),
                    voiceLanguage: newLanguage,
                    voiceoverEnabled: newVoiceoverEnabled ? 1 : 0,
                    voiceGender: newGender,
                    voiceId: newStyle
                }
            }])[0].done(function(envelope) {
                var response = unwrapService(envelope);
                    /**
                     * Replace quizData with the questions returned after a settings change.
                     *
                     * @param {Array} questions The regenerated questions from the server.
                     * @return {void}
                     */
                    function applySettingsQuestions(questions) {
                        // FIX-KC-REGEN-STORE (v1.5.81): unpack {text,explanation} API format
                        // to KC's flat internal format (same fix as regenerateinstructions).
                        // FIX-KC-SETTINGS-TIMESTAMP (v1.5.104): Add index i so we can fall back
                        // to the original quizData[i].timestamp_seconds if the server omits the
                        // field — mirrors the double-fallback in the regenerateinstructions mapper.
                        var preRegenQuizData = quizData.slice();
                        quizData = questions.map(function(q, i) {
                            var opts = Array.isArray(q.options) ? q.options : [];
                            var isObjOpts = opts.length > 0 && typeof opts[0] === 'object' && opts[0] !== null;
                            // FIX-KC-REGEN-TIMESTAMP-NULL (v1.5.109): hasValue(Use) so an explicit
                            // null response also triggers the fallback to the original snapshot.
                            var preservedTs = (hasValue(q.timestamp_seconds))
                                ? q.timestamp_seconds
                                : (preRegenQuizData[i] && hasValue(preRegenQuizData[i].timestamp_seconds)
                                    ? preRegenQuizData[i].timestamp_seconds : null);
                            return {
                                type: q.type || 'mcq',
                                question: q.question,
                                options: isObjOpts ? opts.map(function(o) {
                                    return o.text || '';
                                }) : opts,
                                explanations: isObjOpts ? opts.map(function(o) {
                                    return o.explanation || '';
                                }) : (q.explanations || []),
                                correctAnswer: q.correctIndex !== undefined ? q.correctIndex : (q.correctAnswer || 0),
                                audioData: newVoiceoverEnabled ? (q.audioData || null) : null,
                                mappingTopic: q.mappingTopic || '',
                                mappingCriteria: q.mappingCriteria || '',
                                timestamp_seconds: preservedTs !== undefined ? preservedTs : null,
                                // ADD-KC-MEDIAPER-Q (v1.5.120): Preserve all teacher-configured
                                // per-question media across settings regeneration. The AI never
                                // returns these fields so we carry them over from preRegenQuizData.
                                // Also fixes the pre-existing bug where imageUrl/imageEnabled were
                                // silently dropped on every settings-triggered regeneration.
                                imageUrl:             preRegenQuizData[i] ? (preRegenQuizData[i].imageUrl || '') : '',
                                imageEnabled:         preRegenQuizData[i] ? !!preRegenQuizData[i].imageEnabled : false,
                                questionVideoUrl:     preRegenQuizData[i] ? (preRegenQuizData[i].questionVideoUrl || '') : '',
                                questionVideoEnabled: preRegenQuizData[i] ? !!preRegenQuizData[i].questionVideoEnabled : false,
                                questionAudioUrl:     preRegenQuizData[i] ? (preRegenQuizData[i].questionAudioUrl || '') : '',
                                questionAudioEnabled: preRegenQuizData[i] ? !!preRegenQuizData[i].questionAudioEnabled : false
                            };
                        });
                        buildEditForms();
                        saveEditedQuestions(function(saveSuccess) {
                            enableSettingsButtons();
                            if (saveSuccess) {
                                editInfoEl.text(S.regensavedwithsettings);
                                setTimeout(function() {
                                    editInfoEl.text(origInfo);
                                }, 3000);
                            } else {
                                kcAlert(S.errorregensavefailed);
                            }
                        });
                    }
                    if (response.ok && response.jobId) {
                        pollRegenJob(response.jobId, null, '', function(completed) {
                            var qs = completed.questions || [];
                            if (qs.length === 0) {
                                enableSettingsButtons();
                                kcAlert(S.errorregenzeroquestions);
                                return;
                            }
                            applySettingsQuestions(qs);
                        }, function(errMsg) {
                            enableSettingsButtons();
                            kcAlert(fmt(S.errorregenfaileddetail, errMsg));
                        });
                    } else if (response.ok && response.questions) {
                        kcLog('[KC] Settings regeneration complete:', response.questions.length, 'questions');
                        applySettingsQuestions(response.questions);
                    } else {
                        kcError('[KC] Settings regeneration failed:', response.error);
                        enableSettingsButtons();
                        kcAlert(fmt(S.errorregenfaileddetail, response.error || S.errorunknown));
                    }
            }).fail(function(err) {
                kcError('[KC] Settings regeneration request failed:', err);
                enableSettingsButtons();
                kcAlert(S.errorrequestfailed);
            });
        } else if (voiceoverTurnedOn) {
            // Voiceover turned ON: generate audio for existing questions
            editInfoEl.html(
                '<svg class="kc-spinner" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none' +
                    '" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 6px;"><circle cx="12" ' +
                    'cy="12" r="10"/></svg>' +
                'Generating voiceover audio...'
            );
            regenerateAudioWithCallback(function(audioSuccess) {
                enableSettingsButtons();
                if (audioSuccess) {
                    editInfoEl.text(S.voiceovergenerated);
                } else {
                    editInfoEl.text(S.voiceovergenfailed);
                }
                setTimeout(function() {
                    editInfoEl.text(origInfo);
                }, 3000);
            });
        } else {
            // Only voice style/gender changed (same language, voiceover still on): regenerate audio only
            if (newVoiceoverEnabled) {
                editInfoEl.html(
                    '<svg class="kc-spinner" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="' +
                        'none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 6px;"><circle ' +
                        'cx="12" cy="12" r="10"/></svg>' +
                    'Updating voiceover with new voice...'
                );
                regenerateAudioWithCallback(function(audioSuccess) {
                    enableSettingsButtons();
                    if (audioSuccess) {
                        editInfoEl.text(S.voicesettingsupdated);
                    } else {
                        editInfoEl.text(S.audioupdatefailed);
                    }
                    setTimeout(function() {
                        editInfoEl.text(origInfo);
                    }, 3000);
                });
            } else {
                // Nothing changed that needs processing
                enableSettingsButtons();
                editInfoEl.text(S.settingssaved);
                setTimeout(function() {
                    editInfoEl.text(origInfo);
                }, 3000);
            }
        }
    }

    /**
     * Update the counter showing how many regenerations remain.
     *
     * @return {void}
     */
    function updateRegenCountDisplay() {
        var remaining = Math.max(0, 3 - regenerationCount);
        var countText = '';
        if (regenerationCount === 0) {
            countText = '3 free regenerations remaining';
        } else if (remaining > 0) {
            countText = remaining + ' free regeneration' + (remaining !== 1 ? 's' : '') + ' remaining';
        } else {
            countText = 'Free regenerations used. Next regeneration will use credits.';
        }
        $('#ready-regen-count').text(countText).toggleClass('kc-regen-warning', remaining === 0);
        $('#edit-regen-count').text(countText).toggleClass('kc-regen-warning', remaining === 0);
    }

    // FIX-KC-REGEN-ASYNC (v1.5.89): The external API changed to an async job model for
    // regenerateinstructions and regeneratewithsettings: it returns {ok:true, jobId:"..."}
    // immediately rather than waiting for questions. All three regen handlers previously only
    // checked response.questions, so they always hit the else-branch, showed "Retrying…", and
    // then gave up with "The AI service is temporarily busy." Fix: poll the status action using
    // the same /api/knowledgecheck-status/{jobId} endpoint already used by initial generation.
    //
    // jobId       - the job ID returned by the API
    // $progressBtn - optional jQuery button to show live progress on (null = don't update)
    // spinnerSvg  - spinner HTML prefix for the progress label
    // onComplete  - callback(response) called when status==='completed' with the full response
    // onError     - callback(errorMessage) called on failure or timeout
    /**
     * Poll a regeneration job until it finishes, times out, or fails.
     *
     * @param {string} jobId The regeneration job ID.
     * @param {Object} $progressBtn The jQuery button showing progress.
     * @param {string} spinnerSvg Markup for the spinner shown while polling.
     * @param {Function} onComplete Called with the regenerated questions on success.
     * @param {Function} onError Called with a message when the job fails or times out.
     * @return {void}
     */
    function pollRegenJob(jobId, $progressBtn, spinnerSvg, onComplete, onError) {
        var polls = 0;
        var MAX_POLLS = 90; // 90 × 2s = 3 minutes max
        var regenPollInterval = setInterval(function() {
            polls++;
            if (polls > MAX_POLLS) {
                clearInterval(regenPollInterval);
                onError('Timed out waiting for regeneration. Please try again.');
                return;
            }
            // MIGRATE-EXTERNAL-SERVICES (v1.5.148): second 'status' caller, now via the
            // declared service. Individual poll failures are still ignored so a transient
            // blip does not abort a regeneration that is still running server-side.
            pollGenerationStatus(jobId, function(response) {
                if (!response.ok) {
                    clearInterval(regenPollInterval);
                    onError(response.error || 'Regeneration failed');
                    return;
                }
                if ($progressBtn && response.progress !== undefined) {
                    $progressBtn.html(spinnerSvg + 'Regenerating\u2026 ' + Math.round(response.progress) + '%');
                }
                if (response.status === 'completed') {
                    clearInterval(regenPollInterval);
                    onComplete(response);
                } else if (response.status === 'failed') {
                    clearInterval(regenPollInterval);
                    onError(response.error || S.regenserverfailed);
                }
                // 'processing' — keep polling
            }, function() {
                // Ignore individual poll failures — keep the interval running
            });
        }, 2000);
    }

    /**
     * Regenerate the questions using the teacher's extra instructions.
     *
     * @param {string} source Which form the instructions came from: 'ready' or 'edit'.
     * @return {void}
     */
    function handleRegenerateWithInstructions(source) {
        var extraInstructions = source === 'ready'
            ? $('#ready-extra-instructions').val()
            : $('#edit-extra-instructions').val();
        var $btn = source === 'ready' ? $('#ready-regenerate-btn') : $('#edit-regenerate-btn');

        if (!quizData || quizData.length === 0) {
            kcAlert(S.errornoquestionstoregenerate);
            return;
        }

        var isFree = regenerationCount < 3;
        if (!isFree) {
            var voiceoverOn = $('#voiceover-toggle').is(':checked') || !!config.voiceoverEnabled;
            var creditsNeeded = voiceoverOn ? quizData.length * 2 : quizData.length;
            // The modal is asynchronous, so the work continues in the callback rather than
            // after an inline boolean test.
            kcConfirm(fmt(S.confirmpaidregeneration, creditsNeeded), function() {
                runRegeneration(source, extraInstructions, $btn, false);
            });
            return;
        }

        runRegeneration(source, extraInstructions, $btn, true);
    }

    /**
     * Send the regeneration request and handle its outcome.
     *
     * Split out of handleRegenerateWithInstructions() when the paid-regeneration prompt became a
     * modal: everything below used to run straight after an inline confirm().
     *
     * @param {string} source Which form the instructions came from: 'ready' or 'edit'.
     * @param {string} extraInstructions The teacher's extra instructions.
     * @param {Object} $btn The jQuery regenerate button to show progress on.
     * @param {boolean} isFree True when this regeneration is one of the free allowance.
     * @return {void}
     */
    function runRegeneration(source, extraInstructions, $btn, isFree) {

        // FIX-KC-REGEN-BATCH (v1.5.88): Replace slow sequential per-question requests with a
        // single batch call. The server's regenerateinstructions endpoint calls Gemini once for
        // ALL questions — sending them one-at-a-time multiplied latency and caused the "Q{n} busy
        // — retrying…" stall (each retry waited 10 seconds). A batch call is both faster and
        // simpler: one round-trip, no per-question delays.
        var regenBtnRestoreHtml =
            '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"' +
                ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px; vertical-align: middl' +
                'e;">' +
                '<polyline points="1 4 1 10 7 10"/>' +
                '<polyline points="23 20 23 14 17 14"/>' +
                '<path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/>' +
            '</svg>Regenerate Questions';

        var spinnerSvg =
            '<svg class="kc-spinner" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" st' +
                'roke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 6px;"><circle cx="12" cy="12" ' +
                'r="10"/></svg>';

        /**
         * Restore the regenerate button to its idle state.
         *
         * @return {void}
         */
        function restoreBtn() {
            $btn.prop('disabled', false).html(regenBtnRestoreHtml);
        }

        var voiceoverEnabled = $('#voiceover-toggle').is(':checked') || !!config.voiceoverEnabled;
        var total = quizData.length;

        $btn.prop('disabled', true).html(
            spinnerSvg + 'Regenerating ' + total + ' question' + (total !== 1 ? 's' : '') + '\u2026'
        );

        // Build batch payload — all questions in one array.
        // FIX-KC-REGEN-TIMESTAMP (v1.5.92): Include timestamp_seconds in the payload so the
        // server can preserve it in the response. Without this the server always receives
        // undefined and the preservation branch never runs, dropping Jump-to links after regen.
        var allQuestions = quizData.map(function(q0) {
            return {
                type: q0.type || 'mcq',
                question: q0.question,
                options: [
                    {text: (q0.options && q0.options[0]) || '', explanation: (q0.explanations && q0.explanations[0]) || ''},
                    {text: (q0.options && q0.options[1]) || '', explanation: (q0.explanations && q0.explanations[1]) || ''},
                    {text: (q0.options && q0.options[2]) || '', explanation: (q0.explanations && q0.explanations[2]) || ''},
                    {text: (q0.options && q0.options[3]) || '', explanation: (q0.explanations && q0.explanations[3]) || ''}
                ],
                correctIndex: q0.correctAnswer,
                mappingTopic: q0.mappingTopic || '',
                mappingCriteria: q0.mappingCriteria || '',
                timestamp_seconds: (q0.timestamp_seconds !== undefined && q0.timestamp_seconds !== null)
                    ? q0.timestamp_seconds
                    : null
            };
        });

        /**
         * Send the regeneration request, retrying once on failure.
         *
         * @param {number} retriesLeft How many retries remain.
         * @return {void}
         */
        function doBatchRequest(retriesLeft) {
            // MIGRATE-EXTERNAL-SERVICES (v1.5.152): regenerateinstructions now runs through
            // the declared mod_aiknowledgecheck_regenerate_instructions service.
            Ajax.call([{
                methodname: 'mod_aiknowledgecheck_regenerate_instructions',
                args: {
                    cmid: parseInt(config.cmid, 10),
                    questions: JSON.stringify(allQuestions),
                    extraInstructions: extraInstructions || '',
                    voiceLanguage: $('#voice-language').val() || 'en-AU',
                    voiceoverEnabled: voiceoverEnabled ? 1 : 0,
                    voiceGender: $('#voice-gender').val() || 'female',
                    voiceId: $('#voice-style').val() || 'Aoede'
                }
            }])[0].done(function(envelope) {
                var response = unwrapService(envelope);
                    /**
                     * Merge the regenerated questions back into quizData.
                     *
                     * @param {Array} questions The regenerated questions from the server.
                     * @return {void}
                     */
                    function applyBatchQuestions(questions) {
                        var newQuizData = quizData.slice();
                        for (var i = 0; i < questions.length && i < newQuizData.length; i++) {
                            var rq = questions[i];
                            var rqOpts = Array.isArray(rq.options) ? rq.options : [];
                            var rqIsObj = rqOpts.length > 0 && typeof rqOpts[0] === 'object' && rqOpts[0] !== null;
                            // FIX-KC-REGEN-TIMESTAMP (v1.5.92): Preserve timestamp_seconds from
                            // server response so Jump-to links survive batch regeneration.
                            // Also fall back to the original quizData entry in case server omits it.
                            // FIX-KC-REGEN-TIMESTAMP-NULL (v1.5.109): hasValue(Use) (not !== undefined)
                            // so an explicit null in the server response also triggers the fallback.
                            // rq.timestamp_seconds !== undefined was TRUE for null, meaning a null
                            // server response silently overwrote the valid original timestamp.
                            var preservedTs = (hasValue(rq.timestamp_seconds))
                                ? rq.timestamp_seconds
                                : (quizData[i] && hasValue(quizData[i].timestamp_seconds) ? quizData[i].timestamp_seconds : null);
                            newQuizData[i] = {
                                type: rq.type || 'mcq',
                                question: rq.question,
                                options: rqIsObj ? rqOpts.map(function(o) {
                                    return o.text || '';
                                }) : rqOpts,
                                explanations: rqIsObj ? rqOpts.map(function(o) {
                                    return o.explanation || '';
                                }) : (rq.explanations || []),
                                correctAnswer: rq.correctIndex !== undefined ? rq.correctIndex : (rq.correctAnswer || 0),
                                audioData: voiceoverEnabled ? (rq.audioData || null) : null,
                                mappingTopic: rq.mappingTopic || '',
                                mappingCriteria: rq.mappingCriteria || '',
                                timestamp_seconds: preservedTs !== undefined ? preservedTs : null,
                                // ADD-KC-MEDIAPER-Q (v1.5.120): Preserve all teacher-configured
                                // per-question media across batch regeneration. The AI never returns
                                // these fields so we carry them forward from the pre-regen quizData[i].
                                // Also fixes the pre-existing bug where imageUrl/imageEnabled were
                                // silently dropped on every batch-triggered regeneration.
                                imageUrl:             quizData[i] ? (quizData[i].imageUrl || '') : '',
                                imageEnabled:         quizData[i] ? !!quizData[i].imageEnabled : false,
                                questionVideoUrl:     quizData[i] ? (quizData[i].questionVideoUrl || '') : '',
                                questionVideoEnabled: quizData[i] ? !!quizData[i].questionVideoEnabled : false,
                                questionAudioUrl:     quizData[i] ? (quizData[i].questionAudioUrl || '') : '',
                                questionAudioEnabled: quizData[i] ? !!quizData[i].questionAudioEnabled : false
                            };
                        }
                        quizData = newQuizData;
                        regenerationCount = regenerationCount + 1;
                        updateRegenCountDisplay();
                        $('#ready-extra-instructions').val(extraInstructions || '');
                        $('#edit-extra-instructions').val(extraInstructions || '');
                        saveQuestionsToDatabase();
                        fetchCredits();
                        if (source === 'edit') {
                            buildEditForms();
                        }
                        if (source === 'ready') {
                            $('#ready-summary').text(total + ' questions regenerated!');
                        }
                        restoreBtn();
                        kcAlert(isFree ? S.successregenfree : S.successregenpaid);
                    }
                    if (response.ok && response.jobId) {
                        pollRegenJob(response.jobId, $btn, spinnerSvg, function(completed) {
                            var qs = completed.questions || [];
                            if (qs.length === 0) {
                                restoreBtn();
                                kcAlert(S.errorregenzeroquestions);
                                return;
                            }
                            applyBatchQuestions(qs);
                        }, function(errMsg) {
                            restoreBtn();
                            kcAlert(fmt(S.errorregenfailedretry, errMsg));
                        });
                    } else if (response.ok && response.questions && response.questions.length > 0) {
                        applyBatchQuestions(response.questions);
                    } else {
                        var errorMsg = response.error || 'Unknown error';
                        kcWarn('[KC] Regen batch error (retriesLeft=' + retriesLeft + '):', errorMsg);
                        if (retriesLeft > 0) {
                            $btn.html(spinnerSvg + 'Retrying\u2026');
                            setTimeout(function() {
                                doBatchRequest(retriesLeft - 1);
                            }, 2000);
                        } else {
                            restoreBtn();
                            kcAlert(fmt(S.errorregenfailedretry, errorMsg));
                        }
                    }
            }).fail(function(err) {
                kcWarn('[KC] Regen batch request failed (retriesLeft=' + retriesLeft + '):', err);
                if (retriesLeft > 0) {
                    $btn.html(spinnerSvg + 'Retrying\u2026');
                    setTimeout(function() {
                        doBatchRequest(retriesLeft - 1);
                    }, 2000);
                } else {
                    restoreBtn();
                    kcAlert(S.errorregenconnectionfailed);
                }
            });
        }

        doBatchRequest(1); // Up to 2 total attempts (1 retry).
    }

    // REMOVED (v1.5.158): handleKCSingleRegenerate() lived here. The per-question regenerate
    // handler added in v1.5.77 (FIX-KC-PER-QUESTION-REGEN) was left unreachable when its
    // button was lost in a later edit, so the code is deleted rather than kept dormant.
    // See git history for the original implementation if the feature is rebuilt.

    /**
     * Re-enable the settings and edit buttons after an operation completes.
     *
     * @return {void}
     */
    function enableSettingsButtons() {
        $('#save-edits-btn').prop('disabled', false);
        $('#cancel-edits-btn').prop('disabled', false);
        $('#edit-settings-btn').prop('disabled', false);
        $('#settings-save-btn').prop('disabled', false).html(
            '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"' +
                ' stroke-width="2" style="margin-right: 4px; vertical-align: middle;"><polyline points="23 4 11.5 15.5 6 10"/></s' +
                'vg> Save Settings'
        );
    }

    /**
     * Show or hide the warning about settings changes that discard existing questions.
     *
     * @return {void}
     */
    function updateSettingsWarning() {
        var voiceoverOn = $('#settings-voiceover-toggle').is(':checked');
        var newLang = $('#settings-voice-language').val();
        var oldLang = $('#voice-language').val();
        var langChanged = (newLang !== oldLang);
        var msg = '';
        if (langChanged) {
            msg = S.warnlanguagechange;
        } else if (voiceoverOn) {
            msg = S.warnvoicesaved;
        } else {
            msg = S.warnnovoiceover;
        }
        $('#settings-warning-msg').text(msg);
    }

    return {
        init: init
    };
});
