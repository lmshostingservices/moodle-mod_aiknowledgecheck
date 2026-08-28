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
 * AJAX handler for AI Knowledge Check.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$action = required_param('action', PARAM_ALPHA);
$sesskey = required_param('sesskey', PARAM_ALPHANUM);

// Validate session.
if (!confirm_sesskey($sesskey)) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid session']);
    exit;
}

// Actions that require cmid and capability check.
$secured_actions = ['generate', 'status', 'getcredits', 'getindustries', 'regenerateaudio', 'regeneratewithsettings', 'regenerateinstructions', 'savevoicesettings', 'generateimage', 'saveimageurl'];

// Get cmid for secured actions.
$cmid = 0;
$cm = null;
$course = null;
$knowledgecheck = null;
$context = null;

if (in_array($action, $secured_actions)) {
    $cmid = required_param('cmid', PARAM_INT);
    $cm = get_coursemodule_from_id('aiknowledgecheck', $cmid, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $knowledgecheck = $DB->get_record('aiknowledgecheck', ['id' => $cm->instance], '*', MUST_EXIST);
    
    require_login($course, false, $cm);
    $context = context_module::instance($cm->id);
    
    // All of these are teacher-authoring actions and require the create capability.
    // SECURITY (M-1/L-2): 'status', 'getcredits' and 'getindustries' were previously reachable
    // by any student holding :view. 'status' proxies the raw SaaS generation payload (which
    // contains the answer key), and getcredits/getindustries expose org billing/config — none
    // are needed by students. Gate them all on :create; nothing student-facing goes through
    // $secured_actions (saveanswer/finishattempt/startattempt do their own per-attempt checks).
    require_capability('mod/aiknowledgecheck:create', $context);
}

// Release session lock before long-running API calls to prevent blocking other requests.
\core\session\manager::write_close();

// Get configuration.
$apibase = get_config('mod_aiknowledgecheck', 'apiurl');
if (empty($apibase)) {
    $apibase = 'https://lms-labs.com';
}
// Remove trailing slash if present.
$apibase = rtrim($apibase, '/');

// Explicitly include aiconfig lib.php if available
$aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
if (file_exists($aiconfiglib)) {
    require_once($aiconfiglib);
}

// Priority 1: Central Config (recommended for multi-plugin setups)
$siteid = '';
$apikey = '';
if (function_exists('local_aiconfig_get_siteid')) {
    $siteid = trim(local_aiconfig_get_siteid() ?? '');
}
if (function_exists('local_aiconfig_get_apikey')) {
    $apikey = trim(local_aiconfig_get_apikey() ?? '');
}

// Priority 2: Plugin settings as fallback
if (empty($siteid)) {
    $siteid = trim(get_config('mod_aiknowledgecheck', 'siteid') ?? '');
}
if (empty($apikey)) {
    $apikey = trim(get_config('mod_aiknowledgecheck', 'apikey') ?? '');
}

header('Content-Type: application/json');

switch ($action) {
    case 'getcredits':
        // Debug: Check if credentials are configured (including whitespace-only check).
        if (strlen($siteid) === 0 || strlen($apikey) === 0) {
            echo json_encode([
                'ok' => false, 
                'error' => 'Plugin not configured: Missing Site ID or API Key. Go to Site admin → Plugins → Activity modules → AI Knowledge Check.',
                'debug' => [
                    'hasSiteId' => strlen($siteid) > 0,
                    'hasApiKey' => strlen($apikey) > 0
                ]
            ]);
            break;
        }

        // Fetch credits from API (GET request with query parameters).
        // Note: Must specify '&' as separator - some PHP configs default to '&amp;' which breaks URLs
        $url = $apibase . '/api/credits?' . http_build_query([
            'siteId' => $siteid,
            'apiKey' => $apikey,
        ], '', '&');

        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 30, 'CURLOPT_SSL_VERIFYPEER' => true, 'CURLOPT_FOLLOWLOCATION' => true]);
        $response = $curl->get($url);
        $curlerror = $curl->error;
        $httpcode = $curl->info['http_code'];

        if ($curlerror) {
            echo json_encode([
                'ok' => false, 
                'error' => 'Connection failed: ' . $curlerror,
                'debug' => ['httpCode' => $httpcode, 'curlError' => $curlerror]
            ]);
            break;
        }

        if ($httpcode === 200) {
            $result = json_decode($response, true);
            if (isset($result['credits'])) {
                echo json_encode(['ok' => true, 'credits' => $result['credits']]);
            } else {
                echo json_encode([
                    'ok' => false,
                    'error' => 'Invalid API response format',
                    'debug' => ['httpCode' => $httpcode]
                ]);
            }
        } else {
            $result = json_decode($response, true);
            // Do not echo the Site ID, API base, or raw upstream response back to the
            // browser — these are credentials/config and were visible to any student.
            echo json_encode([
                'ok' => false,
                'error' => isset($result['error']) ? $result['error'] : 'API returned HTTP ' . $httpcode,
                'debug' => ['httpCode' => $httpcode]
            ]);
        }
        break;

    case 'generate':
        // Start knowledge check generation.
        // Get and validate topics.
        $topicsraw = required_param('topics', PARAM_TEXT);
        $topics = clean_param($topicsraw, PARAM_TEXT);
        
        // Validate topics not empty.
        if (empty(trim($topics))) {
            echo json_encode(['ok' => false, 'error' => get_string('error_no_topics', 'mod_aiknowledgecheck')]);
            break;
        }
        
        // Limit topics length to prevent abuse.
        if (strlen($topics) > 10000) {
            echo json_encode(['ok' => false, 'error' => 'Topics text too long (max 10,000 characters)']);
            break;
        }
        
        $questionspertopic = optional_param('questionsPerTopic', 5, PARAM_INT);
        $questionspertopic = max(1, min(20, $questionspertopic)); // Clamp between 1-20.
        
        // Workplace context (only if enabled).
        $workplacecontextenabled = optional_param('workplaceContextEnabled', 0, PARAM_INT);
        $country = '';
        $state = '';
        $industry = '';
        $industrydetails = '';
        $joblevel = '';
        $jobtitle = '';
        
        if ($workplacecontextenabled) {
            $country = clean_param(optional_param('country', '', PARAM_TEXT), PARAM_TEXT);
            $state = clean_param(optional_param('state', '', PARAM_TEXT), PARAM_TEXT);
            $industry = clean_param(optional_param('industry', '', PARAM_TEXT), PARAM_TEXT);
            $industrydetails = clean_param(optional_param('industryDetails', '', PARAM_TEXT), PARAM_TEXT);
            $joblevel = clean_param(optional_param('jobLevel', '', PARAM_TEXT), PARAM_TEXT);
            $jobtitle = clean_param(optional_param('jobTitle', '', PARAM_TEXT), PARAM_TEXT);
        }
        
        // Education settings.
        $educationtype = optional_param('educationType', 'vet', PARAM_ALPHA);
        $vetlevel = optional_param('vetLevel', 'cert3', PARAM_ALPHANUMEXT);
        $academiclevel = optional_param('academicLevel', '', PARAM_ALPHA);
        
        // Extra AI instructions (sanitized).
        $extrainstructions = clean_param(optional_param('extraInstructions', '', PARAM_TEXT), PARAM_TEXT);
        if (strlen($extrainstructions) > 2000) {
            $extrainstructions = substr($extrainstructions, 0, 2000);
        }
        
        // User-provided questions (optional).
        $useownquestions = optional_param('useOwnQuestions', 0, PARAM_INT);
        $userquestions = '';
        if ($useownquestions) {
            $userquestionsraw = optional_param('userQuestions', '', PARAM_TEXT);
            $userquestions = clean_param($userquestionsraw, PARAM_TEXT);
            if (strlen($userquestions) > 10000) {
                $userquestions = substr($userquestions, 0, 10000);
            }
        }
        
        // Text sources (optional) - pasted content for question generation.
        $usetextsources = optional_param('useTextSources', 0, PARAM_INT);
        $validatedtextsources = [];
        if ($usetextsources) {
            $textsourcesjson = optional_param('textSources', '', PARAM_RAW); // pipeline-ignore: PARAM_RAW — JSON payload, json_decode()'d on the next line
            $textsourcesarray = json_decode($textsourcesjson, true);
            if (empty($textsourcesarray) || !is_array($textsourcesarray)) {
                echo json_encode(['ok' => false, 'error' => get_string('error_text_empty', 'mod_aiknowledgecheck')]);
                break;
            }
            if (count($textsourcesarray) > 10) {
                echo json_encode(['ok' => false, 'error' => 'Maximum 10 text sources allowed.']);
                break;
            }
            foreach ($textsourcesarray as $textitem) {
                $sourcetext = trim($textitem['text'] ?? '');
                $sourcequestioncount = max(1, min(30, (int)($textitem['questionCount'] ?? 10)));
                if (empty($sourcetext)) {
                    echo json_encode(['ok' => false, 'error' => get_string('error_text_empty', 'mod_aiknowledgecheck')]);
                    break 2;
                }
                if (strlen($sourcetext) > 50000) {
                    $sourcetext = substr($sourcetext, 0, 50000);
                }
                $validatedtextsources[] = [
                    'text' => $sourcetext,
                    'questionCount' => $sourcequestioncount,
                ];
            }
        }
        
        // Voice settings.
        $voiceoverenabled = optional_param('voiceoverEnabled', 0, PARAM_INT);
        $voicelanguage = optional_param('voiceLanguage', 'en-AU', PARAM_TEXT);
        $voicegender = optional_param('voiceGender', 'female', PARAM_ALPHA);
        $voiceid = optional_param('voiceId', 'Zephyr', PARAM_ALPHA);
        // ADD-SURVEY-MODE (v1.5.126): Survey mode params.
        $surveymode  = optional_param('surveyMode',  0, PARAM_INT);
        $surveyscale = optional_param('surveyScale', 'likert5agree', PARAM_ALPHANUMEXT);
        // ADD-SURVEY-FREETEXT (v1.5.127): Free-text questions passed as JSON array.
        $freetextquestionsraw = optional_param('freetextQuestions', '[]', PARAM_RAW); // pipeline-ignore: PARAM_RAW — JSON payload, json_decode()'d on the next line
        $freetextquestions = json_decode($freetextquestionsraw, true);
        if (!is_array($freetextquestions)) {
            $freetextquestions = [];
        }
        $freetextquestions = array_values(array_filter(array_map('trim', $freetextquestions), function ($q) {
            return strlen($q) > 0 && strlen($q) <= 500;
        }));
        if (count($freetextquestions) > 20) {
            $freetextquestions = array_slice($freetextquestions, 0, 20);
        }

        $url = $apibase . '/api/generate-knowledgecheck';
        $payload = [
            'siteId' => $siteid,
            'apiKey' => $apikey,
            'cmid' => $cmid,
            'knowledgecheckId' => $knowledgecheck->id,
            'topics' => $topics,
            'questionsPerTopic' => $questionspertopic,
            'useOwnQuestions' => (bool)$useownquestions,
            'userQuestions' => $userquestions,
            'workplaceContextEnabled' => (bool)$workplacecontextenabled,
            'country' => $country,
            'state' => $state,
            'industry' => $industry,
            'industryDetails' => $industrydetails,
            'jobLevel' => $joblevel,
            'jobTitle' => $jobtitle,
            'educationType' => $educationtype,
            'vetLevel' => $vetlevel,
            'academicLevel' => $academiclevel,
            'extraInstructions' => $extrainstructions,
            'voiceoverEnabled' => (bool)$voiceoverenabled,
            'voiceLanguage' => $voicelanguage,
            'voiceGender' => $voicegender,
            'voiceId' => $voiceid,
            // FIX-KC-TIMESTAMP-GENERATE (v1.5.96): Forward the showChapterStamps setting so
            // the AI endpoint knows to assign timestamp_seconds to each generated question.
            // Without this flag the API never received the teacher's preference and always
            // returned timestamp_seconds=null, making the Jump-to links permanently invisible.
            'showChapterStamps' => isset($knowledgecheck->showchapterstamps) ? (int)$knowledgecheck->showchapterstamps : 0,
            // ADD-SURVEY-MODE (v1.5.126): Forward survey mode and scale to SaaS API.
            'surveyMode'  => (bool)$surveymode,
            'surveyScale' => $surveyscale,
            // ADD-SURVEY-FREETEXT (v1.5.127): Forward free-text questions to SaaS API.
            'freetextQuestions' => $freetextquestions,
        ];
        if ($usetextsources && !empty($validatedtextsources)) {
            $payload['useTextSources'] = true;
            $payload['textSources'] = $validatedtextsources;
        }
        $data = json_encode($payload);

        // FIX-KC-REGEN-GROUNDING (v1.5.95): Persist a JSON-encoded source-context blob to
        // mdl_aiknowledgecheck.sourcecontext so the regenerateinstructions action can later
        // forward it to the SaaS endpoint as the authoritative source-of-truth. Without
        // this, "Regenerate Questions" only ever sees the OLD question text and drifts into
        // generic content unrelated to the original topics / text sources / workplace
        // context. We persist on every generate call (overwriting any prior context) so the
        // sourcecontext always reflects the inputs that produced the current question set.
        try {
            $kc_sourcecontext = [
                'topics' => $topics,
                'useOwnQuestions' => (bool)$useownquestions,
                'userQuestions' => $userquestions,
                'useTextSources' => (bool)$usetextsources,
                'textSources' => $validatedtextsources,
                'workplaceContextEnabled' => (bool)$workplacecontextenabled,
                'country' => $country,
                'state' => $state,
                'industry' => $industry,
                'industryDetails' => $industrydetails,
                'jobLevel' => $joblevel,
                'jobTitle' => $jobtitle,
                'educationType' => $educationtype,
                'vetLevel' => $vetlevel,
                'academicLevel' => $academiclevel,
                // FIX-KC-TIMESTAMP-GENERATE (v1.5.96): Persist showChapterStamps so the
                // regenerateinstructions action can forward it to the API as a top-level
                // field, enabling timestamp generation on every regeneration call too.
                'showChapterStamps' => isset($knowledgecheck->showchapterstamps) ? (int)$knowledgecheck->showchapterstamps : 0,
            ];
            $DB->set_field(
                'aiknowledgecheck',
                'sourcecontext',
                json_encode($kc_sourcecontext),
                ['id' => $cm->instance]
            );
        } catch (Throwable $kc_sc_e) {
            // Sourcecontext persistence is best-effort — never block generation if the
            // column is missing (e.g. site upgrade hasn't run yet) or if the DB write fails.
            error_log('[mod_aiknowledgecheck] sourcecontext persist failed: ' . $kc_sc_e->getMessage());
        }

        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => $usetextsources ? 120 : 60]);
        $curl->setHeader(['Content-Type: application/json']);
        $response = $curl->post($url, $data);
        $curlerror = $curl->error;
        $httpcode = $curl->info['http_code'];

        if ($curlerror) {
            echo json_encode(['ok' => false, 'error' => 'Connection error: ' . $curlerror]);
            break;
        }

        if ($httpcode === 200) {
            $result = json_decode($response, true);
            if ($result === null) {
                echo json_encode(['ok' => false, 'error' => 'Invalid API response']);
            } else {
                echo json_encode($result);
            }
        } else {
            $result = json_decode($response, true);
            $error = isset($result['error']) ? $result['error'] : 'API request failed (HTTP ' . $httpcode . ')';
            echo json_encode(['ok' => false, 'error' => $error]);
        }
        break;

    case 'status':
        // Check generation status.
        $jobid = required_param('jobId', PARAM_ALPHANUMEXT);

        $url = $apibase . '/api/knowledgecheck-status/' . urlencode($jobid);

        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 30]);
        $response = $curl->get($url);
        $httpcode = $curl->info['http_code'];

        if ($httpcode === 200) {
            // FIX-KC-STATUS-STREAM: pass the raw JSON through directly without a
            // json_decode → json_encode round-trip.  Re-encoding a completed response
            // that contains large base64 audioData arrays can fail silently (json_encode
            // returns false → echo outputs nothing → jQuery parse error → "0 questions").
            echo $response;
        } else {
            echo json_encode(['ok' => false, 'error' => 'Failed to check status']);
        }
        break;

    case 'getindustries':
        // Fetch industries list.
        $url = $apibase . '/api/industries';

        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 30]);
        $response = $curl->get($url);
        $httpcode = $curl->info['http_code'];

        if ($httpcode === 200) {
            $result = json_decode($response, true);
            echo json_encode(['ok' => true, 'industries' => $result['industries'] ?? []]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Failed to fetch industries']);
        }
        break;

    case 'savequestions':
        // Save generated questions to the database.
        $cmid = required_param('cmid', PARAM_INT);
        $questions = required_param('questions', PARAM_RAW); // pipeline-ignore: PARAM_RAW — JSON question array, json_decode()'d below
        $voiceoverenabled = optional_param('voiceoverEnabled', -1, PARAM_INT);
        $voicelanguage = optional_param('voiceLanguage', '', PARAM_TEXT);
        $voicegender = optional_param('voiceGender', '', PARAM_ALPHA);
        $voicestyle = optional_param('voiceStyle', '', PARAM_ALPHA);

        $cm = get_coursemodule_from_id('aiknowledgecheck', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        require_login($course, false, $cm);
        $context = context_module::instance($cm->id);
        require_capability('mod/aiknowledgecheck:create', $context);

        $questionsdata = json_decode($questions, true);
        if (!is_array($questionsdata)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid questions data']);
            break;
        }

        // FIX-KC-SERVER-GUARD: Refuse to save an empty question list.  Saving zero questions
        // would DELETE all existing questions from the DB without inserting any replacements —
        // a silent data-loss path triggered by the JS when quizData is unexpectedly empty.
        if (count($questionsdata) === 0) {
            echo json_encode(['ok' => false, 'error' => 'Cannot save zero questions — this would delete all existing questions. Reload the page and try again.']);
            break;
        }

        // Clear existing questions.
        $DB->delete_records('aiknowledgecheck_questions', ['aiknowledgecheckid' => $cm->instance]);

        // FIX-KC-SURVEY-SAVE (v1.5.138): never hand a non-scalar to the DB layer.
        //
        // A malformed payload used to travel all the way into mysqli and die with
        // "real_escape_string(): Argument #1 ($string) must be of type string, array given".
        // The teacher saw an unexplained "Questions could not be saved" alert, and by then the
        // delete_records() above had ALREADY removed their existing questions -- so a bad
        // payload cost them the whole question set and told them nothing useful.
        //
        // These two helpers coerce every text field to a string and accept an option written
        // either as a plain string or as a {text, explanation} object, which is the shape the
        // generation service returns.
        $kcstring = function ($value) {
            if (is_array($value) || is_object($value)) {
                return '';
            }
            return is_scalar($value) ? (string)$value : '';
        };
        $kcoption = function ($q, $index, $key) use ($kcstring) {
            if (!isset($q['options'][$index])) {
                return '';
            }
            $opt = $q['options'][$index];
            if (is_array($opt)) {
                return $kcstring($opt[$key] ?? '');
            }
            // A bare string is the option text and carries no explanation.
            return $key === 'text' ? $kcstring($opt) : '';
        };

        // Insert new questions.
        $questionnumber = 1;
        foreach ($questionsdata as $q) {
            $record = new stdClass();
            $record->aiknowledgecheckid = $cm->instance;
            $record->questionnumber = $questionnumber++;
            $record->questiontext = $kcstring($q['question'] ?? '');
            $record->answer1 = $kcoption($q, 0, 'text');
            $record->answer2 = $kcoption($q, 1, 'text');
            $record->answer3 = $kcoption($q, 2, 'text');
            $record->answer4 = $kcoption($q, 3, 'text');
            // ADD-SURVEY-MODE (v1.5.126): 5th option for 5-point survey scales.
            $record->answer5 = isset($q['options'][4]) ? $kcoption($q, 4, 'text') : null;
            // ADD-SURVEY-FREETEXT (v1.5.127): save question type (scale or freetext).
            $record->questiontype = isset($q['questionType']) ? clean_param($kcstring($q['questionType']), PARAM_ALPHA) : 'scale';
            if (!in_array($record->questiontype, ['scale', 'freetext'])) {
                $record->questiontype = 'scale';
            }

            // FIX-KC-SURVEY-SCALE-OPTIONS (v1.5.141): in survey mode the response scale is a
            // fixed, known list chosen by the teacher — it is not something the generation
            // model should be deciding. Previously the plugin stored whatever options the API
            // returned, so picking "Yes / No" (or any scale other than 5-point Agreement)
            // frequently produced Agreement options anyway, with nothing to indicate the
            // teacher's choice had been ignored.
            //
            // Overwrite the options with the canonical set for the activity's scale. The AI
            // still writes the question text; it no longer determines the answer options.
            // Free-text questions have no options and are left alone.
            if (!empty($knowledgecheck->surveymode) && $record->questiontype !== 'freetext') {
                $scalekey = isset($knowledgecheck->surveyscale) && $knowledgecheck->surveyscale !== ''
                    ? $knowledgecheck->surveyscale
                    : 'likert5agree';
                $scaleopts = mod_aiknowledgecheck_survey_scale_options($scalekey);
                for ($si = 0; $si < 5; $si++) {
                    $field = 'answer' . ($si + 1);
                    $record->$field = $scaleopts[$si] ?? ($si < 4 ? '' : null);
                }
                // Survey questions are ungraded; per-option feedback is meaningless and the
                // AI's explanations refer to options that no longer exist.
                $record->feedback1 = '';
                $record->feedback2 = '';
                $record->feedback3 = '';
                $record->feedback4 = '';
                $record->correctanswer = 0;
            }
            $record->correctanswer = (int)$kcstring($q['correctIndex'] ?? 0);
            $record->feedback1 = $kcoption($q, 0, 'explanation');
            $record->feedback2 = $kcoption($q, 1, 'explanation');
            $record->feedback3 = $kcoption($q, 2, 'explanation');
            $record->feedback4 = $kcoption($q, 3, 'explanation');
            // Save audio data if available (JSON array of base64 audio for each answer).
            if (!empty($q['audioData'])) {
                $record->audiodata = json_encode($q['audioData']);
            }
            // Save topic/criteria mapping metadata for Excel export.
            $record->mappingtopic    = isset($q['mappingTopic'])    ? clean_param($kcstring($q['mappingTopic']),    PARAM_TEXT) : null;
            $record->mappingcriteria = isset($q['mappingCriteria']) ? clean_param($kcstring($q['mappingCriteria']), PARAM_TEXT) : null;
            // Save timestamp_seconds for chapter stamp links.
            $record->timestamp_seconds = isset($q['timestamp_seconds']) && $q['timestamp_seconds'] !== null ? (int)$q['timestamp_seconds'] : null;
            // ADD-KC-IMAGEGATE (v1.5.115): Save per-question image data.
            // LOW-FIX: sanitise (reject data:image/svg+xml + non http(s) schemes).
            $record->imageurl = isset($q['imageUrl']) ? mod_aiknowledgecheck_sanitize_image_url($kcstring($q['imageUrl'])) : null;
            $record->imageenabled = isset($q['imageEnabled']) ? (int)$q['imageEnabled'] : 0;
            // ADD-KC-MEDIAPER-Q (v1.5.120): Save per-question video and audio data.
            $record->questionvideourl     = isset($q['questionVideoUrl'])     ? clean_param($kcstring($q['questionVideoUrl']),     PARAM_URL) : null;
            $record->questionvideoenabled = isset($q['questionVideoEnabled']) ? (int)$q['questionVideoEnabled']                   : 0;
            $record->questionaudiourl     = isset($q['questionAudioUrl'])     ? clean_param($kcstring($q['questionAudioUrl']),     PARAM_URL) : null;
            $record->questionaudioenabled = isset($q['questionAudioEnabled']) ? (int)$q['questionAudioEnabled']                   : 0;
            $DB->insert_record('aiknowledgecheck_questions', $record);
        }

        // Update question count.
        $DB->set_field('aiknowledgecheck', 'questioncount', count($questionsdata), ['id' => $cm->instance]);

        // FIX-KC-Q1-FRESH (v1.5.107): When new questions are saved (after any generate or
        // regenerate call), any in-progress attempt is now stale — its saved answers reference
        // question IDs that no longer exist. The next startattempt() call would resume from
        // that stale attempt and set resumeFromIndex > 0, causing the quiz to skip Q1.
        // Delete all in-progress attempts for this activity so the student always starts at Q1.
        $DB->delete_records('aiknowledgecheck_attempts', [
            'aiknowledgecheckid' => $cm->instance,
            'status' => 0,
        ]);

        // Persist voiceover setting so student view gets correct config.
        // If voiceoverEnabled was sent explicitly, use it. Otherwise auto-detect from audioData.
        if ($voiceoverenabled >= 0) {
            $DB->set_field('aiknowledgecheck', 'voiceoverenabled', $voiceoverenabled ? 1 : 0, ['id' => $cm->instance]);
        } else {
            // Auto-detect: if any question has audioData, voiceover must have been enabled.
            $hasaudio = false;
            foreach ($questionsdata as $q) {
                if (!empty($q['audioData'])) {
                    $hasaudio = true;
                    break;
                }
            }
            if ($hasaudio) {
                $DB->set_field('aiknowledgecheck', 'voiceoverenabled', 1, ['id' => $cm->instance]);
            }
        }

        // Persist voice settings if provided.
        if ($voicelanguage !== '') {
            $DB->set_field('aiknowledgecheck', 'voicelanguage', $voicelanguage, ['id' => $cm->instance]);
        }
        if ($voicegender !== '') {
            $DB->set_field('aiknowledgecheck', 'voicegender', $voicegender, ['id' => $cm->instance]);
        }
        if ($voicestyle !== '') {
            $DB->set_field('aiknowledgecheck', 'voicestyle', $voicestyle, ['id' => $cm->instance]);
        }

        echo json_encode(['ok' => true, 'saved' => count($questionsdata)]);
        break;

    case 'startattempt':
        // Start a new attempt for a student.
        $cmid = required_param('cmid', PARAM_INT);

        $cm = get_coursemodule_from_id('aiknowledgecheck', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $knowledgecheck = $DB->get_record('aiknowledgecheck', ['id' => $cm->instance], '*', MUST_EXIST);

        require_login($course, false, $cm);
        $context = context_module::instance($cm->id);
        require_capability('mod/aiknowledgecheck:view', $context);

        $userid = $USER->id;

        // Use transaction to prevent race conditions (duplicate in-progress attempts).
        $transaction = $DB->start_delegated_transaction();
        try {
            // Check if there's an in-progress attempt (inside transaction for consistency).
            $inprogress = $DB->get_record('aiknowledgecheck_attempts', [
                'aiknowledgecheckid' => $knowledgecheck->id,
                'userid' => $userid,
                'status' => 0,
            ]);

            if ($inprogress) {
                $transaction->allow_commit();
                echo json_encode([
                    'ok' => true,
                    'attemptid' => $inprogress->id,
                    'resumed' => true,
                    'currentquestion' => (int)$inprogress->currentquestion,
                    'answers' => json_decode($inprogress->answers, true) ?: [],
                ]);
                break;
            }

            // Check if user can start a new attempt.
            if (!aiknowledgecheck_can_attempt($knowledgecheck, $userid)) {
                $transaction->allow_commit();
                $maxattempts = aiknowledgecheck_effective_maxattempts($knowledgecheck, $userid);
                echo json_encode([
                    'ok' => false,
                    'error' => get_string('attemptslimitreached', 'mod_aiknowledgecheck', $maxattempts),
                ]);
                break;
            }

            // Create new attempt.
            $now = time();
            $attempt = new stdClass();
            $attempt->aiknowledgecheckid = $knowledgecheck->id;
            $attempt->userid = $userid;
            $attempt->currentquestion = 0;
            $attempt->answers = '{}';
            $attempt->correctcount = 0;
            $attempt->totalcount = 0;
            $attempt->status = 0;
            $attempt->timecreated = $now;
            $attempt->timemodified = $now;
            $attempt->timestarted = $now;
            $attempt->timeended = null;

            $attemptid = $DB->insert_record('aiknowledgecheck_attempts', $attempt);
            $transaction->allow_commit();

            echo json_encode([
                'ok' => true,
                'attemptid' => $attemptid,
                'resumed' => false,
                'currentquestion' => 0,
                'answers' => [],
            ]);
        } catch (Exception $e) {
            $transaction->rollback($e);
            echo json_encode(['ok' => false, 'error' => 'Failed to start attempt. Please try again.']);
        }
        break;

    case 'saveanswer':
        // Save a single answer during an attempt.
        $attemptid = required_param('attemptid', PARAM_INT);
        $questionid = required_param('questionid', PARAM_INT);
        $answerindex = required_param('answerindex', PARAM_INT);
        // ADD-SURVEY-FREETEXT (v1.5.127): optional free text value for open-ended questions.
        $freetextvalue = optional_param('freetextvalue', '', PARAM_TEXT);

        $attempt = $DB->get_record('aiknowledgecheck_attempts', ['id' => $attemptid], '*', MUST_EXIST);

        // Authenticate user against the course.
        $knowledgecheck = $DB->get_record('aiknowledgecheck', ['id' => $attempt->aiknowledgecheckid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('aiknowledgecheck', $knowledgecheck->id, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        require_login($course, false, $cm);

        // Verify user owns this attempt.
        if ($attempt->userid != $USER->id) {
            echo json_encode(['ok' => false, 'error' => 'Invalid attempt']);
            break;
        }

        if ($attempt->status != 0) {
            echo json_encode(['ok' => false, 'error' => 'Attempt already completed']);
            break;
        }

        // Get question and verify it belongs to the same knowledge check as the attempt.
        $question = $DB->get_record('aiknowledgecheck_questions', ['id' => $questionid], '*', MUST_EXIST);
        if ((int)$question->aiknowledgecheckid !== (int)$attempt->aiknowledgecheckid) {
            echo json_encode(['ok' => false, 'error' => 'Question does not belong to this activity']);
            break;
        }

        // ADD-SURVEY-FREETEXT (v1.5.127): answerindex = -1 signals a free-text response.
        $isFreetext = ($answerindex === -1);

        if ($isFreetext) {
            // Hardening (L-3): only accept the free-text branch for questions that are actually
            // free-text — a -1 on a scale question is an invalid/forged payload.
            if (($question->questiontype ?? 'scale') !== 'freetext') {
                echo json_encode(['ok' => false, 'error' => 'Invalid answer index']);
                break;
            }
            // Free-text question: store the typed response, no correct/wrong scoring.
            // Cap the length (L-3) so the attempt's answers JSON can't be inflated without bound.
            $freetextclean = core_text::substr(clean_param($freetextvalue, PARAM_TEXT), 0, 2000);
            $answers = json_decode($attempt->answers, true) ?: [];
            $answers[$questionid] = [
                'answer'   => -1,
                'freetext' => $freetextclean,
            ];
            $attempt->answers = json_encode($answers);
            $attempt->currentquestion = max((int)$attempt->currentquestion, (int)$question->questionnumber);
            $attempt->timemodified = time();
            $DB->update_record('aiknowledgecheck_attempts', $attempt);
            echo json_encode(['ok' => true, 'iscorrect' => null, 'correctanswer' => null]);
            break;
        }

        // Scale / MCQ question: clamp answerindex to valid range (0-4 for 5-point scales).
        if ($answerindex < 0 || $answerindex > 4) {
            echo json_encode(['ok' => false, 'error' => 'Invalid answer index']);
            break;
        }

        // Decode existing answers once (used for the first-answer-wins guard and the recount).
        $answers = json_decode($attempt->answers, true) ?: [];

        // Survey scale response: store the selected option without evaluating or
        // returning correctness, the answer key, or feedback. Survey Mode is an
        // ungraded response flow end-to-end, not merely a quiz with hidden feedback.
        if (!empty($knowledgecheck->surveymode)) {
            $optionfield = 'answer' . ($answerindex + 1);
            if (empty($question->$optionfield)) {
                echo json_encode(['ok' => false, 'error' => 'Invalid answer index']);
                break;
            }

            // Preserve first-answer-wins/idempotent retry behavior without exposing
            // any quiz verdict or answer key.
            if (isset($answers[$questionid]) && isset($answers[$questionid]['answer'])
                    && (int)$answers[$questionid]['answer'] !== -1) {
                echo json_encode([
                    'ok' => true,
                    'iscorrect' => null,
                    'correctanswer' => null,
                    'locked' => true,
                ]);
                break;
            }

            $answers[$questionid] = ['answer' => $answerindex];
            $totalcount = 0;
            foreach ($answers as $ans) {
                if (isset($ans['answer']) && (int)$ans['answer'] !== -1) {
                    $totalcount++;
                }
            }

            $attempt->answers = json_encode($answers);
            $attempt->currentquestion = max(
                (int)$attempt->currentquestion,
                (int)$question->questionnumber
            );
            $attempt->correctcount = 0;
            $attempt->totalcount = $totalcount;
            $attempt->timemodified = time();
            $DB->update_record('aiknowledgecheck_attempts', $attempt);

            echo json_encode([
                'ok' => true,
                'iscorrect' => null,
                'correctanswer' => null,
            ]);
            break;
        }

        // Per-option explanations (original option order); built here so both the
        // first-answer-wins path and the normal path can return them for feedback.
        $explanations = [
            $question->feedback1 ?? '',
            $question->feedback2 ?? '',
            $question->feedback3 ?? '',
            $question->feedback4 ?? '',
        ];
        if (!empty($question->answer5)) {
            $explanations[] = '';
        }

        // SECURITY (C-1): FIRST-ANSWER-WINS. If this scale question already has a recorded
        // answer in this attempt, do NOT re-grade or overwrite it. Return the ORIGINAL verdict
        // (idempotent for the legitimate retry-resend path) plus the key/explanations for
        // feedback. Without this a student could saveanswer(guess) -> read correctanswer from
        // the response -> saveanswer(correct) -> repeat, for a guaranteed 100%. The normal UI
        // only advances forward and wrong-only retry uses a NEW attempt id, so no legitimate
        // flow re-saves an already-answered scale question.
        if (isset($answers[$questionid]) && isset($answers[$questionid]['answer'])
                && (int)$answers[$questionid]['answer'] !== -1) {
            echo json_encode([
                'ok' => true,
                'iscorrect' => !empty($answers[$questionid]['iscorrect']),
                'correctanswer' => (int)$question->correctanswer,
                'explanations' => $explanations,
                'locked' => true,
            ]);
            break;
        }

        $iscorrect = ($answerindex == $question->correctanswer);

        // Record this (first) answer for the question — freetext entries excluded from counts.
        $answers[$questionid] = [
            'answer' => $answerindex,
            'iscorrect' => $iscorrect,
        ];

        // Recalculate correct/total counts — exclude freetext answers (answer === -1).
        $correctcount = 0;
        $totalcount = 0;
        foreach ($answers as $qid => $ans) {
            if (isset($ans['answer']) && (int)$ans['answer'] === -1) {
                continue; // freetext — not counted
            }
            $totalcount++;
            if (!empty($ans['iscorrect'])) {
                $correctcount++;
            }
        }

        $attempt->answers = json_encode($answers);
        // Track progress using question number (sequential index), not database ID.
        $attempt->currentquestion = max((int)$attempt->currentquestion, (int)$question->questionnumber);
        $attempt->correctcount = $correctcount;
        $attempt->totalcount = $totalcount;
        $attempt->timemodified = time();

        $DB->update_record('aiknowledgecheck_attempts', $attempt);

        // SECURITY (C2): the student has now answered, so it is safe to return the correct
        // index + explanations for feedback rendering.
        echo json_encode([
            'ok' => true,
            'iscorrect' => $iscorrect,
            'correctanswer' => (int)$question->correctanswer,
            'explanations' => $explanations,
        ]);
        break;

    case 'finishattempt':
        // Finish an attempt.
        $attemptid = required_param('attemptid', PARAM_INT);

        $attempt = $DB->get_record('aiknowledgecheck_attempts', ['id' => $attemptid], '*', MUST_EXIST);

        // Get knowledge check and authenticate user against the course.
        $knowledgecheck = $DB->get_record('aiknowledgecheck', ['id' => $attempt->aiknowledgecheckid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('aiknowledgecheck', $knowledgecheck->id, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        require_login($course, false, $cm);

        if ($attempt->userid != $USER->id) {
            echo json_encode(['ok' => false, 'error' => 'Invalid attempt']);
            break;
        }

        if ($attempt->status != 0) {
            echo json_encode(['ok' => false, 'error' => 'Attempt already completed']);
            break;
        }

        // Calculate score — skip freetext answers (answer === -1).
        $answers = json_decode($attempt->answers, true) ?: [];
        $correctcount = 0;
        $totalcount = 0;

        foreach ($answers as $qid => $ans) {
            if (isset($ans['answer']) && (int)$ans['answer'] === -1) {
                continue; // freetext question — not scored
            }
            if (!empty($ans['iscorrect'])) {
                $correctcount++;
            }
        }

        // The denominator is the activity's TOTAL scale questions, not just the
        // number answered — otherwise answering a single question correctly and
        // finishing would score 100% (and satisfy "all correct" completion).
        // Unanswered scale questions therefore count as incorrect.
        $totalcount = $DB->count_records_select('aiknowledgecheck_questions',
            'aiknowledgecheckid = :kcid AND (questiontype IS NULL OR questiontype <> :ft)',
            ['kcid' => (int)$attempt->aiknowledgecheckid, 'ft' => 'freetext']);
        if ($totalcount < $correctcount) {
            $totalcount = $correctcount; // safety — never fewer than the correct count
        }

        // Update attempt.
        $now = time();
        $attempt->status = 1; // Completed.
        $attempt->correctcount = $correctcount;
        $attempt->totalcount = $totalcount;
        $attempt->timemodified = $now;
        $attempt->timeended = $now;

        $DB->update_record('aiknowledgecheck_attempts', $attempt);

        // Update grade in gradebook FIRST (before completion check).
        // Completion may depend on "Require passing grade" which reads from gradebook.
        aiknowledgecheck_update_grades($knowledgecheck, $USER->id);

        // Now update completion - grade is already written so passing grade check works.
        $completion = new completion_info($course);
        if ($completion->is_enabled($cm)) {
            $completion->update_state($cm, COMPLETION_UNKNOWN, $USER->id);
        }

        // Check if user has now used all attempts, send notification.
        if (!aiknowledgecheck_can_attempt($knowledgecheck, $USER->id)) {
            // User just used their last attempt.
            $user = $DB->get_record('user', ['id' => $USER->id]);
            aiknowledgecheck_send_attempts_notification($knowledgecheck, $course, $cm, $user);
        }

        // Return authoritative attempt counts so the client never drifts out of sync.
        $attemptsused_now = aiknowledgecheck_count_attempts($knowledgecheck->id, $USER->id);
        $canattempt_now   = aiknowledgecheck_can_attempt($knowledgecheck, $USER->id);

        // Trigger AI Quiz Remedial Learning for wrong answers if that plugin is installed and enabled.
        if (get_config('local_aiquizremedial', 'enabled')) {
            try {
                $dbman = $DB->get_manager();
                if ($dbman->table_exists('local_aiqr_job') && $dbman->field_exists('local_aiqr_job', 'sourcetype')) {
                    // Create one umbrella job per attempt. The cron task will expand it
                    // into per-question jobs for each incorrect answer.
                    if (!$DB->record_exists('local_aiqr_job', [
                        'attemptid'  => $attemptid,
                        'sourcetype' => 'knowledgecheck',
                        'questionid' => null,
                    ])) {
                        $DB->insert_record('local_aiqr_job', (object) [
                            'userid'       => $USER->id,
                            'courseid'     => (int) $course->id,
                            'quizid'       => null,
                            'kcid'         => (int) $knowledgecheck->id,
                            'attemptid'    => (int) $attemptid,
                            'questionid'   => null,
                            'sourcetype'   => 'knowledgecheck',
                            'status'       => 'pending',
                            'errormsg'     => null,
                            'timecreated'  => time(),
                            'timemodified' => time(),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                // Remediation job creation is optional — never let it break the KC attempt.
            }
        }

        echo json_encode([
            'ok' => true,
            'correctcount' => $correctcount,
            'totalcount' => $totalcount,
            'attemptsUsed' => $attemptsused_now,
            'canAttempt' => $canattempt_now,
        ]);
        break;

    case 'getquestions':
        // Get questions for the activity.
        $cmid = required_param('cmid', PARAM_INT);

        $cm = get_coursemodule_from_id('aiknowledgecheck', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

        require_login($course, false, $cm);
        $context = context_module::instance($cm->id);
        require_capability('mod/aiknowledgecheck:view', $context);

        // SECURITY (C2): the correct-answer key and per-option explanations must NOT be sent
        // to students at attempt start (they were readable from the Network/console before
        // answering). Only users who can author/report on the activity may receive them.
        // Students receive the correct answer + explanation for a question ONLY in the
        // saveanswer response, i.e. after they have answered it. Grading is server-side and
        // authoritative regardless, so withholding the key here does not affect scoring.
        $canseeanswers = has_capability('mod/aiknowledgecheck:create', $context)
            || has_capability('mod/aiknowledgecheck:viewreports', $context)
            || has_capability('mod/aiknowledgecheck:addinstance', $context);

        $questions = $DB->get_records('aiknowledgecheck_questions',
            ['aiknowledgecheckid' => $cm->instance],
            'questionnumber ASC'
        );

        $result = [];
        foreach ($questions as $q) {
            // Parse audio data if available (stored as JSON array of base64 strings).
            $audioData = null;
            if (!empty($q->audiodata)) {
                $audioData = json_decode($q->audiodata, true);
            }
            
            // For students, the per-option explanation is blanked here and delivered later
            // via saveanswer; teachers/reporters keep it for the editor and preview.
            $result[] = [
                'id' => (int)$q->id,
                'questionnumber' => (int)$q->questionnumber,
                'question' => $q->questiontext,
                // FIX-KC-SHORT-SCALE (v1.5.138): trim TRAILING empty options.
                //
                // The filter here only dropped the explicit null in the answer5 slot, so
                // answer1..4 always came back as four options even when the scale is shorter.
                // A two- or three-point survey scale therefore rendered blank radio choices in
                // the player, while report.php -- which builds its labels with
                // `if (!empty($sq->$f))` -- showed only the real ones. Player and report now
                // agree on the option count.
                //
                // TRAILING only, never a hole in the middle: correctanswer and every stored
                // answer are positional indexes, so compacting around a gap would silently
                // repoint them at the wrong option. A blank in the middle is a broken question
                // and is left visible rather than quietly reindexed.
                'options' => mod_aiknowledgecheck_trim_options([
                    ['text' => $q->answer1, 'explanation' => $canseeanswers ? ($q->feedback1 ?? '') : ''],
                    ['text' => $q->answer2, 'explanation' => $canseeanswers ? ($q->feedback2 ?? '') : ''],
                    ['text' => $q->answer3, 'explanation' => $canseeanswers ? ($q->feedback3 ?? '') : ''],
                    ['text' => $q->answer4, 'explanation' => $canseeanswers ? ($q->feedback4 ?? '') : ''],
                    // ADD-SURVEY-MODE (v1.5.126): Include 5th option when present (5-point scales).
                    (!empty($q->answer5)) ? ['text' => $q->answer5, 'explanation' => ''] : null,
                ], ($q->questiontype ?? 'scale')),
                // SECURITY (C2): null for students; the real index is only returned by saveanswer.
                'correctIndex' => $canseeanswers ? (int)$q->correctanswer : null,
                'audioData' => $audioData,
                'mappingTopic' => $q->mappingtopic ?? '',
                'mappingCriteria' => $q->mappingcriteria ?? '',
                'timestamp_seconds' => isset($q->timestamp_seconds) && $q->timestamp_seconds !== null ? (int)$q->timestamp_seconds : null,
                // ADD-KC-IMAGEGATE (v1.5.115): Return per-question image data.
                'imageUrl'            => $q->imageurl            ?? '',
                'imageEnabled'        => isset($q->imageenabled)        ? (int)$q->imageenabled        : 0,
                // ADD-KC-MEDIAPER-Q (v1.5.120): Return per-question video and audio data.
                'questionVideoUrl'     => $q->questionvideourl     ?? '',
                'questionVideoEnabled' => isset($q->questionvideoenabled) ? (int)$q->questionvideoenabled : 0,
                'questionAudioUrl'     => $q->questionaudiourl     ?? '',
                'questionAudioEnabled' => isset($q->questionaudioenabled) ? (int)$q->questionaudioenabled : 0,
                // ADD-SURVEY-FREETEXT (v1.5.127): Return question type.
                'questionType'        => !empty($q->questiontype) ? $q->questiontype : 'scale',
            ];
        }

        echo json_encode(['ok' => true, 'questions' => $result]);
        break;

    case 'getattemptinfo':
        // Get attempt info for student view.
        $cmid = required_param('cmid', PARAM_INT);

        $cm = get_coursemodule_from_id('aiknowledgecheck', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $knowledgecheck = $DB->get_record('aiknowledgecheck', ['id' => $cm->instance], '*', MUST_EXIST);

        require_login($course, false, $cm);
        $context = context_module::instance($cm->id);
        require_capability('mod/aiknowledgecheck:view', $context);

        $userid = $USER->id;

        // Get attempt counts.
        $used = aiknowledgecheck_count_attempts($knowledgecheck->id, $userid);
        $max = aiknowledgecheck_effective_maxattempts($knowledgecheck, $userid);
        $canattempt = aiknowledgecheck_can_attempt($knowledgecheck, $userid);

        // Check for in-progress attempt.
        $inprogress = $DB->get_record('aiknowledgecheck_attempts', [
            'aiknowledgecheckid' => $knowledgecheck->id,
            'userid' => $userid,
            'status' => 0,
        ]);

        // Get previous attempts.
        $attempts = $DB->get_records('aiknowledgecheck_attempts', [
            'aiknowledgecheckid' => $knowledgecheck->id,
            'userid' => $userid,
            'status' => 1,
        ], 'id ASC');

        $attemptlist = [];
        $attemptnum = 1;
        foreach ($attempts as $a) {
            $attemptlist[] = [
                'id' => (int)$a->id,
                'number' => $attemptnum++,
                'score' => $a->correctcount . '/' . $a->totalcount,
                'timestarted' => userdate($a->timestarted),
                'timeended' => $a->timeended ? userdate($a->timeended) : '',
            ];
        }

        echo json_encode([
            'ok' => true,
            'attemptsused' => $used,
            'maxattempts' => $max,
            'canattempt' => $canattempt,
            'inprogress' => $inprogress ? (int)$inprogress->id : null,
            'attempts' => $attemptlist,
        ]);
        break;

    case 'regenerateaudio':
        // Regenerate voiceover audio for existing questions.
        $questionsjson = required_param('questions', PARAM_RAW); // pipeline-ignore: PARAM_RAW — JSON question array, json_decode()'d below
        $voicelanguage = optional_param('voiceLanguage', 'en-AU', PARAM_TEXT);
        $voiceid = optional_param('voiceId', 'Zephyr', PARAM_ALPHA);

        $questionsdata = json_decode($questionsjson, true);
        if (!is_array($questionsdata) || empty($questionsdata)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid questions data']);
            break;
        }

        $url = $apibase . '/api/knowledgecheck-regenerate-audio';
        $payload = json_encode([
            'siteId' => $siteid,
            'apiKey' => $apikey,
            'questions' => $questionsdata,
            'voiceLanguage' => $voicelanguage,
            'voiceId' => $voiceid,
        ]);

        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 120]);
        $curl->setHeader(['Content-Type: application/json']);
        $response = $curl->post($url, $payload);
        $curlerror = $curl->error;
        $httpcode = $curl->info['http_code'];

        if ($curlerror) {
            echo json_encode(['ok' => false, 'error' => 'Connection error: ' . $curlerror]);
            break;
        }

        if ($httpcode === 200) {
            $result = json_decode($response, true);
            if ($result === null) {
                echo json_encode(['ok' => false, 'error' => 'Invalid API response']);
            } else {
                echo json_encode($result);
            }
        } else {
            $result = json_decode($response, true);
            $error = isset($result['error']) ? $result['error'] : 'API request failed (HTTP ' . $httpcode . ')';
            echo json_encode(['ok' => false, 'error' => $error]);
        }
        break;

    case 'regeneratewithsettings':
        $questionsjson = required_param('questions', PARAM_RAW); // pipeline-ignore: PARAM_RAW — JSON question array, json_decode()'d below
        $voicelanguage = optional_param('voiceLanguage', 'en-AU', PARAM_TEXT);
        $voiceoverenabled = optional_param('voiceoverEnabled', 0, PARAM_INT);
        $voicegender = optional_param('voiceGender', 'female', PARAM_ALPHA);
        $voiceid = optional_param('voiceId', 'Zephyr', PARAM_ALPHA);

        $questionsdata = json_decode($questionsjson, true);
        if (!is_array($questionsdata) || empty($questionsdata)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid questions data']);
            break;
        }

        $url = $apibase . '/api/knowledgecheck-regenerate-settings';
        $payload = json_encode([
            'siteId' => $siteid,
            'apiKey' => $apikey,
            'questions' => $questionsdata,
            'voiceLanguage' => $voicelanguage,
            'voiceoverEnabled' => (bool)$voiceoverenabled,
            'voiceGender' => $voicegender,
            'voiceId' => $voiceid,
        ]);

        // BUG-REGEN-TIMEOUT (v1.5.84): The previous code had a PHP retry loop (3 attempts ×
        // CURLOPT_TIMEOUT 150s + sleep(5) between) that could run for up to 460 seconds. The JS
        // AJAX timeout is only 90 seconds, so JS always fired .fail() long before PHP returned.
        // Additionally many Moodle servers enforce max_execution_time = 30-60s at the web server
        // level, which killed the PHP process mid-curl producing no JSON — JS got a blank response.
        // Fix:
        //   1. set_time_limit(120): reset server execution limit for this request so PHP is not
        //      killed before curl completes.
        //   2. Remove the PHP retry loop — JS already retries up to 3× per question. PHP retrying
        //      just multiplies latency and guarantees JS timeout.
        //   3. CURLOPT_TIMEOUT => 75: strictly below the 90s JS AJAX timeout so PHP always
        //      returns (success or failure) before JS abandons the request.
        //   4. CURLOPT_CONNECTTIMEOUT => 10: fast-fail on DNS/TCP problems.
        // BUG-CURL-RESETOPT (v1.5.85): Moodle's \curl::post() calls resetopt() internally before
        // applying the post-specific options (CURLOPT_POST, CURLOPT_POSTFIELDS, CURLOPT_URL). Any
        // options set via setopt() BEFORE calling post() are silently discarded. This caused the
        // Content-Type: application/json header and the custom timeouts to never reach the external
        // API — the API received no JSON content-type, could not parse the body, and rejected every
        // request. Fix: pass curl options as the 3rd argument to post() so they are applied via
        // request() AFTER the internal reset, not before it.
        set_time_limit(120);
        $rws_ch  = new \curl();
        $rws_raw = $rws_ch->post($url, $payload, [
            'CURLOPT_TIMEOUT'        => 75,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_HTTPHEADER'     => ['Content-Type: application/json'],
        ]);
        $rws_error    = $rws_ch->error;
        $rws_info     = $rws_ch->get_info();
        $rws_httpcode = (int)$rws_info['http_code'];

        if ($rws_error) {
            echo json_encode(['ok' => false, 'error' => 'Connection error: ' . $rws_error]);
            break;
        }

        if ($rws_httpcode === 200) {
            // FIX-KC-REGEN-STREAM (v1.5.90): Pass raw JSON directly to avoid json_decode→json_encode
            // round-trip. Re-encoding large base64 audioData arrays can silently produce nothing
            // (json_encode returns false), making jQuery fire .error('parseerror') instead of
            // .success() — same root-cause bug already fixed for the 'status' action.
            echo empty($rws_raw) ? json_encode(['ok' => false, 'error' => 'Empty API response']) : $rws_raw;
        } else {
            $rws_result = json_decode($rws_raw, true);
            $rws_errmsg = isset($rws_result['error']) ? $rws_result['error'] : 'API request failed (HTTP ' . $rws_httpcode . ')';
            echo json_encode(['ok' => false, 'error' => $rws_errmsg]);
        }
        break;

    case 'regenerateinstructions':
        $questionsjson = required_param('questions', PARAM_RAW); // pipeline-ignore: PARAM_RAW — JSON question array, json_decode()'d below
        $extrainstructions = optional_param('extraInstructions', '', PARAM_TEXT);
        $voicelanguage = optional_param('voiceLanguage', 'en-AU', PARAM_TEXT);
        $voiceoverenabled = optional_param('voiceoverEnabled', 0, PARAM_INT);
        $voicegender = optional_param('voiceGender', 'female', PARAM_ALPHA);
        $voiceid = optional_param('voiceId', 'Zephyr', PARAM_ALPHA);

        $questionsdata = json_decode($questionsjson, true);
        if (!is_array($questionsdata) || empty($questionsdata)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid questions data']);
            break;
        }

        // FIX-KC-REGEN-GROUNDING (v1.5.95): Load the persisted source context (topics,
        // text sources, user questions, workplace context, education settings) from the
        // activity row and forward it to the SaaS endpoint so regenerated questions stay
        // grounded in the same source material the original generate call used. Falls back
        // gracefully when the column doesn't exist yet (legacy upgrade not run) or when no
        // context was persisted (activity created on a pre-1.5.95 plugin and never
        // re-generated since). The SaaS endpoint accepts a missing/empty sourceContext and
        // degrades to the previous "questions only" behaviour.
        $kc_sourcecontext_obj = null;
        if (isset($knowledgecheck->sourcecontext) && !empty($knowledgecheck->sourcecontext)) {
            $kc_decoded = json_decode($knowledgecheck->sourcecontext, true);
            if (is_array($kc_decoded)) {
                $kc_sourcecontext_obj = $kc_decoded;
            }
        }

        $url = $apibase . '/api/knowledgecheck-regenerate-instructions';
        $ri_payload = [
            'siteId' => $siteid,
            'apiKey' => $apikey,
            'activityId' => (string)$cm->instance,
            'questions' => $questionsdata,
            'extraInstructions' => $extrainstructions,
            'voiceLanguage' => $voicelanguage,
            'voiceoverEnabled' => (bool)$voiceoverenabled,
            'voiceGender' => $voicegender,
            'voiceId' => $voiceid,
            // FIX-KC-REGEN-EDLEVEL (v1.5.96): Promote educationType/vetLevel/academicLevel
            // to top-level fields so the regeneration API endpoint can apply the same
            // VET-level language constraints as the initial generate call. Without these as
            // top-level fields the API received no level guidance and defaulted to a generic
            // professional register — causing regenerated questions to become lengthy and
            // scenario-based instead of the direct, concise style appropriate for the VET level.
            'educationType' => $kc_sourcecontext_obj['educationType'] ?? 'vet',
            'vetLevel' => $kc_sourcecontext_obj['vetLevel'] ?? 'cert3',
            'academicLevel' => $kc_sourcecontext_obj['academicLevel'] ?? '',
            // FIX-KC-TIMESTAMP-REGEN (v1.5.96): Forward showChapterStamps so the regeneration
            // API also assigns timestamp_seconds to regenerated questions when enabled.
            'showChapterStamps' => $kc_sourcecontext_obj['showChapterStamps'] ?? 0,
            // FIX-KC-TIMESTAMP-REGEN-TEXTSOURCES (v1.5.107): The generate endpoint receives
            // useTextSources + textSources as top-level fields, which the API uses to locate
            // the transcript and assign timestamp_seconds. The regenerateinstructions endpoint
            // previously only received these inside sourceContext (nested), so the API could
            // not find the transcript and always returned null timestamps on regeneration.
            // Fix: also forward them as top-level fields, mirroring the generate call.
            'useTextSources' => !empty($kc_sourcecontext_obj['useTextSources']),
            'textSources'    => $kc_sourcecontext_obj['textSources'] ?? [],
        ];
        if ($kc_sourcecontext_obj !== null) {
            $ri_payload['sourceContext'] = $kc_sourcecontext_obj;
        }
        $payload = json_encode($ri_payload);

        // BUG-REGEN-TIMEOUT (v1.5.84): Same fix as regeneratewithsettings above.
        // PHP retry loop (3×150s) ran far longer than the 90s JS AJAX timeout, causing JS to
        // always fire .fail() before PHP returned. Server max_execution_time (30-60s) also killed
        // PHP mid-curl producing no JSON output. Fix: single attempt, 75s curl timeout, connect
        // timeout 10s, set_time_limit(120) so the server does not kill PHP before curl returns.
        // BUG-CURL-RESETOPT (v1.5.85): Same fix as regeneratewithsettings above — pass curl
        // options as 3rd argument to post() so they survive the internal resetopt() call.
        // FIX-KC-REGEN-BATCH (v1.5.88): JS now sends all questions in a single batch call with a
        // 180s AJAX timeout. Raised curl timeout to 160s (below JS) and set_time_limit to 200s
        // (above curl) so PHP does not get killed before the batch response arrives.
        set_time_limit(200);
        $ri_ch  = new \curl();
        $ri_raw = $ri_ch->post($url, $payload, [
            'CURLOPT_TIMEOUT'        => 160,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_HTTPHEADER'     => ['Content-Type: application/json'],
        ]);
        $ri_error    = $ri_ch->error;
        $ri_info     = $ri_ch->get_info();
        $ri_httpcode = (int)$ri_info['http_code'];

        if ($ri_error) {
            echo json_encode(['ok' => false, 'error' => 'Connection error: ' . $ri_error]);
            break;
        }

        if ($ri_httpcode === 200) {
            // FIX-KC-REGEN-STREAM (v1.5.90): Same raw-passthrough fix as regeneratewithsettings.
            echo empty($ri_raw) ? json_encode(['ok' => false, 'error' => 'Empty API response']) : $ri_raw;
        } else {
            $ri_result = json_decode($ri_raw, true);
            $ri_errmsg = isset($ri_result['error']) ? $ri_result['error'] : 'API request failed (HTTP ' . $ri_httpcode . ')';
            echo json_encode(['ok' => false, 'error' => $ri_errmsg]);
        }
        break;

    case 'savevoicesettings':
        $cmid = required_param('cmid', PARAM_INT);
        $voiceoverenabled = required_param('voiceoverEnabled', PARAM_INT);
        $voicelanguage = optional_param('voiceLanguage', 'en-AU', PARAM_TEXT);
        $voicegender = optional_param('voiceGender', 'female', PARAM_ALPHA);
        $voicestyle = optional_param('voiceStyle', 'Aoede', PARAM_ALPHANUMEXT);

        $cm = get_coursemodule_from_id('aiknowledgecheck', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        require_login($course, false, $cm);
        $context = context_module::instance($cm->id);
        require_capability('mod/aiknowledgecheck:create', $context);

        $DB->set_field('aiknowledgecheck', 'voiceoverenabled', $voiceoverenabled ? 1 : 0, ['id' => $cm->instance]);
        $DB->set_field('aiknowledgecheck', 'voicelanguage', $voicelanguage, ['id' => $cm->instance]);
        $DB->set_field('aiknowledgecheck', 'voicegender', $voicegender, ['id' => $cm->instance]);
        $DB->set_field('aiknowledgecheck', 'voicestyle', $voicestyle, ['id' => $cm->instance]);
        $DB->set_field('aiknowledgecheck', 'timemodified', time(), ['id' => $cm->instance]);

        // If voiceover was disabled, strip audio data from all questions.
        if (!$voiceoverenabled) {
            $questions = $DB->get_records('aiknowledgecheck_questions', ['aiknowledgecheckid' => $cm->instance]);
            foreach ($questions as $q) {
                if (!empty($q->audiodata)) {
                    $DB->set_field('aiknowledgecheck_questions', 'audiodata', null, ['id' => $q->id]);
                }
            }
        }

        echo json_encode(['ok' => true, 'message' => 'Voice settings saved']);
        break;

    case 'generateimage':
        // ADD-KC-IMAGEGATE (v1.5.115): Generate image via Google Imagen 4 Ultra.
        // Costs 5 credits per image. Teacher-only (require_capability enforced above).
        $prompt = required_param('prompt', PARAM_TEXT);

        if (empty($prompt)) {
            echo json_encode(['ok' => false, 'error' => 'Prompt is required']);
            break;
        }

        if (empty($siteid) || empty($apikey)) {
            echo json_encode(['ok' => false, 'error' => 'Plugin not configured. Set Site ID and API Key in plugin settings.']);
            break;
        }

        // Call SaaS image generation endpoint (deducts 5 credits server-side).
        $imageurl_payload = json_encode([
            'siteId' => $siteid,
            'apiKey' => $apikey,
            'prompt' => $prompt,
            'activityId' => (string)$cm->instance,
        ]);

        $imgcurl = new \curl();
        $imgcurl->setopt([
            'CURLOPT_TIMEOUT' => 90,
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_HTTPHEADER' => ['Content-Type: application/json'],
        ]);
        $imgresponse = $imgcurl->post($apibase . '/api/knowledgecheck-generate-image', $imageurl_payload);
        $imgcurlerror = $imgcurl->error;
        $imghttpcode = $imgcurl->info['http_code'];

        if ($imgcurlerror) {
            echo json_encode(['ok' => false, 'error' => 'Connection failed: ' . $imgcurlerror]);
            break;
        }

        $imgresult = json_decode($imgresponse, true);
        if ($imghttpcode === 200 && !empty($imgresult['ok']) && !empty($imgresult['imageDataUrl'])) {
            echo json_encode([
                'ok' => true,
                'imageDataUrl' => $imgresult['imageDataUrl'],
            ]);
        } else {
            $errmsg = $imgresult['error'] ?? 'Image generation failed (HTTP ' . $imghttpcode . ')';
            echo json_encode(['ok' => false, 'error' => $errmsg]);
        }
        break;

    case 'saveimageurl':
        // ADD-KC-IMAGEGATE (v1.5.115): Save an image URL (or data URL) to the activity
        // record as the image gate URL. Teacher-only (require_capability enforced above).
        $newimagedataurl = required_param('imageurl', PARAM_RAW); // pipeline-ignore: PARAM_RAW — data:image URL, validated by mod_aiknowledgecheck_sanitize_image_url() below

        // Validate + sanitise: accept http(s) URLs and safe raster data URLs only.
        // data:image/svg+xml is rejected (SVG can carry script). LOW-FIX.
        $newimagedataurl = mod_aiknowledgecheck_sanitize_image_url($newimagedataurl);
        if ($newimagedataurl === null) {
            echo json_encode(['ok' => false, 'error' => 'Invalid image URL format. Use https:// or a data:image/(png|jpg|gif|webp) URL.']);
            break;
        }

        $DB->set_field('aiknowledgecheck', 'imageurl', $newimagedataurl, ['id' => $cm->instance]);
        $DB->set_field('aiknowledgecheck', 'timemodified', time(), ['id' => $cm->instance]);

        echo json_encode(['ok' => true, 'message' => 'Image URL saved']);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
