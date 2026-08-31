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
 * External service: start an AI question generation job.
 *
 * MIGRATE-EXTERNAL-SERVICES (v1.5.152): eighth endpoint migrated from the legacy ajax.php
 * action dispatcher to a declared External Service.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_aiknowledgecheck\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/mod/aiknowledgecheck/lib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use context_module;
use mod_aiknowledgecheck\saas_client;
use Throwable;

/**
 * Starts a generation job on the external service.
 */
class generate extends external_api {
    /**
     * Describes the parameters accepted by execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        // FIX-KC-PARAMTEXT-THROW (v1.5.152): the free-text fields below take the raw type
        // at the boundary and are cleaned inside execute(). external_api::validate_parameters()
        // REJECTS any value that clean_param would alter — it throws
        // invalid_parameter_exception rather than cleaning it, the opposite of the
        // optional_param() behaviour these fields had in ajax.php. A single '<' in a topic
        // or an instruction ("keep answers < 20 words") would otherwise abort generation
        // with a raw "Invalid parameter value detected". The select-backed fields keep
        // their strict types, since their values are enumerated by the plugin itself.
        return new external_function_parameters(
            [
                'cmid' => new external_value(PARAM_INT, 'Course module ID of the activity'),
                'topics' => new external_value(
                    /* phpcs:ignore moodle.Commenting.InlineComment */
                    PARAM_RAW, // pipeline-ignore: PARAM_RAW — cleaned with clean_param(PARAM_TEXT) in execute()
                    'Topics to generate questions from'
                ),
                'questionsPerTopic' => new external_value(PARAM_INT, 'Questions per topic, 1-20', VALUE_DEFAULT, 5),
                'workplaceContextEnabled' => new external_value(
                    PARAM_INT,
                    '1 to apply the workplace context fields',
                    VALUE_DEFAULT,
                    0
                ),
                'country' => new external_value(
                    /* phpcs:ignore moodle.Commenting.InlineComment */
                    PARAM_RAW, // pipeline-ignore: PARAM_RAW — cleaned with clean_param(PARAM_TEXT) in execute()
                    'Workplace country',
                    VALUE_DEFAULT,
                    ''
                ),
                'state' => new external_value(
                    /* phpcs:ignore moodle.Commenting.InlineComment */
                    PARAM_RAW, // pipeline-ignore: PARAM_RAW — cleaned with clean_param(PARAM_TEXT) in execute()
                    'Workplace state',
                    VALUE_DEFAULT,
                    ''
                ),
                'industry' => new external_value(
                    /* phpcs:ignore moodle.Commenting.InlineComment */
                    PARAM_RAW, // pipeline-ignore: PARAM_RAW — cleaned with clean_param(PARAM_TEXT) in execute()
                    'Workplace industry',
                    VALUE_DEFAULT,
                    ''
                ),
                'industryDetails' => new external_value(
                    /* phpcs:ignore moodle.Commenting.InlineComment */
                    PARAM_RAW, // pipeline-ignore: PARAM_RAW — cleaned with clean_param(PARAM_TEXT) in execute()
                    'Workplace industry detail',
                    VALUE_DEFAULT,
                    ''
                ),
                'jobLevel' => new external_value(
                    /* phpcs:ignore moodle.Commenting.InlineComment */
                    PARAM_RAW, // pipeline-ignore: PARAM_RAW — cleaned with clean_param(PARAM_TEXT) in execute()
                    'Workplace job level',
                    VALUE_DEFAULT,
                    ''
                ),
                'jobTitle' => new external_value(
                    /* phpcs:ignore moodle.Commenting.InlineComment */
                    PARAM_RAW, // pipeline-ignore: PARAM_RAW — cleaned with clean_param(PARAM_TEXT) in execute()
                    'Workplace job title',
                    VALUE_DEFAULT,
                    ''
                ),
                'educationType' => new external_value(PARAM_ALPHA, 'Education type, e.g. vet', VALUE_DEFAULT, 'vet'),
                'vetLevel' => new external_value(PARAM_ALPHANUMEXT, 'VET level', VALUE_DEFAULT, 'cert3'),
                'academicLevel' => new external_value(PARAM_ALPHA, 'Academic level', VALUE_DEFAULT, ''),
                'extraInstructions' => new external_value(
                    /* phpcs:ignore moodle.Commenting.InlineComment */
                    PARAM_RAW, // pipeline-ignore: PARAM_RAW — cleaned with clean_param(PARAM_TEXT) in execute()
                    'Extra AI instructions',
                    VALUE_DEFAULT,
                    ''
                ),
                'useOwnQuestions' => new external_value(PARAM_INT, '1 to use teacher-supplied questions', VALUE_DEFAULT, 0),
                'userQuestions' => new external_value(
                    /* phpcs:ignore moodle.Commenting.InlineComment */
                    PARAM_RAW, // pipeline-ignore: PARAM_RAW — cleaned with clean_param(PARAM_TEXT) in execute()
                    'Teacher-supplied questions',
                    VALUE_DEFAULT,
                    ''
                ),
                'useTextSources' => new external_value(PARAM_INT, '1 to generate from pasted text', VALUE_DEFAULT, 0),
                'textSources' => new external_value(
                    /* phpcs:ignore moodle.Commenting.InlineComment */
                    PARAM_RAW, // pipeline-ignore: PARAM_RAW — JSON payload, json_decode()'d below
                    'JSON array of {text, questionCount} objects',
                    VALUE_DEFAULT,
                    ''
                ),
                'voiceoverEnabled' => new external_value(PARAM_INT, '1 to generate voiceover audio', VALUE_DEFAULT, 0),
                'voiceLanguage' => new external_value(PARAM_TEXT, 'Voice language code', VALUE_DEFAULT, 'en-AU'),
                'voiceGender' => new external_value(PARAM_ALPHA, 'Voice gender', VALUE_DEFAULT, 'female'),
                'voiceId' => new external_value(PARAM_ALPHA, 'Voice identifier', VALUE_DEFAULT, 'Zephyr'),
                'surveyMode' => new external_value(PARAM_INT, '1 for survey mode', VALUE_DEFAULT, 0),
                'surveyScale' => new external_value(PARAM_ALPHANUMEXT, 'Survey response scale key', VALUE_DEFAULT, 'likert5agree'),
                'freetextQuestions' => new external_value(
                    /* phpcs:ignore moodle.Commenting.InlineComment */
                    PARAM_RAW, // pipeline-ignore: PARAM_RAW — JSON payload, json_decode()'d below
                    'JSON array of free-text question strings',
                    VALUE_DEFAULT,
                    '[]'
                ),
            ]
        );
    }

    /**
     * Start a generation job.
     *
     * @param int $cmid Course module ID.
     * @param string $topics Topics text.
     * @param int $questionspertopic Questions per topic.
     * @param int $workplacecontextenabled Whether the workplace context applies.
     * @param string $country Workplace country.
     * @param string $state Workplace state.
     * @param string $industry Workplace industry.
     * @param string $industrydetails Workplace industry detail.
     * @param string $joblevel Workplace job level.
     * @param string $jobtitle Workplace job title.
     * @param string $educationtype Education type.
     * @param string $vetlevel VET level.
     * @param string $academiclevel Academic level.
     * @param string $extrainstructions Extra AI instructions.
     * @param int $useownquestions Whether teacher-supplied questions apply.
     * @param string $userquestions Teacher-supplied questions.
     * @param int $usetextsources Whether text sources apply.
     * @param string $textsources JSON array of text sources.
     * @param int $voiceoverenabled Whether to generate voiceover audio.
     * @param string $voicelanguage Voice language code.
     * @param string $voicegender Voice gender.
     * @param string $voiceid Voice identifier.
     * @param int $surveymode Whether survey mode applies.
     * @param string $surveyscale Survey response scale key.
     * @param string $freetextquestions JSON array of free-text questions.
     * @return array Result array matching execute_returns().
     */
    public static function execute(
        int $cmid,
        string $topics,
        int $questionspertopic = 5,
        int $workplacecontextenabled = 0,
        string $country = '',
        string $state = '',
        string $industry = '',
        string $industrydetails = '',
        string $joblevel = '',
        string $jobtitle = '',
        string $educationtype = 'vet',
        string $vetlevel = 'cert3',
        string $academiclevel = '',
        string $extrainstructions = '',
        int $useownquestions = 0,
        string $userquestions = '',
        int $usetextsources = 0,
        string $textsources = '',
        int $voiceoverenabled = 0,
        string $voicelanguage = 'en-AU',
        string $voicegender = 'female',
        string $voiceid = 'Zephyr',
        int $surveymode = 0,
        string $surveyscale = 'likert5agree',
        string $freetextquestions = '[]'
    ): array {
        global $DB;

        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'cmid' => $cmid,
                'topics' => $topics,
                'questionsPerTopic' => $questionspertopic,
                'workplaceContextEnabled' => $workplacecontextenabled,
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
                'useOwnQuestions' => $useownquestions,
                'userQuestions' => $userquestions,
                'useTextSources' => $usetextsources,
                'textSources' => $textsources,
                'voiceoverEnabled' => $voiceoverenabled,
                'voiceLanguage' => $voicelanguage,
                'voiceGender' => $voicegender,
                'voiceId' => $voiceid,
                'surveyMode' => $surveymode,
                'surveyScale' => $surveyscale,
                'freetextQuestions' => $freetextquestions,
            ]
        );

        $cm = get_coursemodule_from_id('aiknowledgecheck', $params['cmid'], 0, false, MUST_EXIST);
        $knowledgecheck = $DB->get_record('aiknowledgecheck', ['id' => $cm->instance], '*', MUST_EXIST);

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/aiknowledgecheck:create', $context);

        $topics = trim(clean_param($params['topics'], PARAM_TEXT));
        if ($topics === '') {
            return saas_client::failure(get_string('error_no_topics', 'mod_aiknowledgecheck'));
        }
        // Limit topics length to prevent abuse.
        if (strlen($topics) > 10000) {
            return saas_client::failure(get_string('error:topicstoolong', 'mod_aiknowledgecheck'));
        }

        $questionspertopic = max(1, min(20, (int)$params['questionsPerTopic']));

        // Workplace context (only when enabled).
        $workplaceenabled = (bool)$params['workplaceContextEnabled'];
        $clean = function (string $key) use ($params, $workplaceenabled): string {
            return $workplaceenabled ? clean_param($params[$key], PARAM_TEXT) : '';
        };
        $country = $clean('country');
        $state = $clean('state');
        $industry = $clean('industry');
        $industrydetails = $clean('industryDetails');
        $joblevel = $clean('jobLevel');
        $jobtitle = $clean('jobTitle');

        $extrainstructions = substr(clean_param($params['extraInstructions'], PARAM_TEXT), 0, 2000);

        $useownquestions = (bool)$params['useOwnQuestions'];
        $userquestions = $useownquestions
            ? substr(clean_param($params['userQuestions'], PARAM_TEXT), 0, 10000)
            : '';

        // Text sources (optional) — pasted content for question generation.
        $usetextsources = (bool)$params['useTextSources'];
        $validatedtextsources = [];
        if ($usetextsources) {
            $textsourcesarray = json_decode($params['textSources'], true);
            if (empty($textsourcesarray) || !is_array($textsourcesarray)) {
                return saas_client::failure(get_string('error_text_empty', 'mod_aiknowledgecheck'));
            }
            if (count($textsourcesarray) > 10) {
                return saas_client::failure(get_string('error:toomanytextsources', 'mod_aiknowledgecheck'));
            }
            foreach ($textsourcesarray as $textitem) {
                $sourcetext = is_array($textitem) ? trim((string)($textitem['text'] ?? '')) : '';
                if ($sourcetext === '') {
                    return saas_client::failure(get_string('error_text_empty', 'mod_aiknowledgecheck'));
                }
                $validatedtextsources[] = [
                    'text' => substr($sourcetext, 0, 50000),
                    'questionCount' => max(1, min(30, (int)($textitem['questionCount'] ?? 10))),
                ];
            }
        }

        // ADD-SURVEY-FREETEXT (v1.5.127): free-text questions arrive as a JSON array.
        $freetext = json_decode($params['freetextQuestions'], true);
        if (!is_array($freetext)) {
            $freetext = [];
        }
        $freetext = array_values(
            array_filter(
                array_map(
                    function ($q) {
                        return is_scalar($q) ? clean_param(trim((string)$q), PARAM_TEXT) : '';
                    },
                    $freetext
                ),
                function ($q) {
                    return $q !== '' && strlen($q) <= 500;
                }
            )
        );
        if (count($freetext) > 20) {
            $freetext = array_slice($freetext, 0, 20);
        }

        [$apibase, $siteid, $apikey] = saas_client::credentials();
        if ($siteid === '' || $apikey === '') {
            return saas_client::failure(get_string('error:notconfigured', 'mod_aiknowledgecheck'));
        }

        $payload = [
            'siteId' => $siteid,
            'apiKey' => $apikey,
            'cmid' => (int)$cm->id,
            'knowledgecheckId' => (int)$knowledgecheck->id,
            'topics' => $topics,
            'questionsPerTopic' => $questionspertopic,
            'useOwnQuestions' => $useownquestions,
            'userQuestions' => $userquestions,
            'workplaceContextEnabled' => $workplaceenabled,
            'country' => $country,
            'state' => $state,
            'industry' => $industry,
            'industryDetails' => $industrydetails,
            'jobLevel' => $joblevel,
            'jobTitle' => $jobtitle,
            'educationType' => $params['educationType'],
            'vetLevel' => $params['vetLevel'],
            'academicLevel' => $params['academicLevel'],
            'extraInstructions' => $extrainstructions,
            'voiceoverEnabled' => (bool)$params['voiceoverEnabled'],
            'voiceLanguage' => $params['voiceLanguage'],
            'voiceGender' => $params['voiceGender'],
            'voiceId' => $params['voiceId'],
            // FIX-KC-TIMESTAMP-GENERATE (v1.5.96): forward showChapterStamps so the service
            // assigns timestamp_seconds to each generated question. Without it the service
            // never saw the teacher's preference and always returned null, making the
            // Jump-to links permanently invisible.
            'showChapterStamps' => isset($knowledgecheck->showchapterstamps)
                ? (int)$knowledgecheck->showchapterstamps : 0,
            // ADD-SURVEY-MODE (v1.5.126) / ADD-SURVEY-FREETEXT (v1.5.127).
            'surveyMode' => (bool)$params['surveyMode'],
            'surveyScale' => $params['surveyScale'],
            'freetextQuestions' => $freetext,
        ];
        if ($usetextsources && !empty($validatedtextsources)) {
            $payload['useTextSources'] = true;
            $payload['textSources'] = $validatedtextsources;
        }

        self::persist_source_context(
            $cm,
            [
                'topics' => $topics,
                'useOwnQuestions' => $useownquestions,
                'userQuestions' => $userquestions,
                'useTextSources' => $usetextsources,
                'textSources' => $validatedtextsources,
                'workplaceContextEnabled' => $workplaceenabled,
                'country' => $country,
                'state' => $state,
                'industry' => $industry,
                'industryDetails' => $industrydetails,
                'jobLevel' => $joblevel,
                'jobTitle' => $jobtitle,
                'educationType' => $params['educationType'],
                'vetLevel' => $params['vetLevel'],
                'academicLevel' => $params['academicLevel'],
                'showChapterStamps' => isset($knowledgecheck->showchapterstamps)
                    ? (int)$knowledgecheck->showchapterstamps : 0,
            ]
        );

        [$raw, $httpcode, $connectionerror] = saas_client::post_json(
            $apibase . '/api/generate-knowledgecheck',
            $payload,
            $usetextsources ? 120 : 60,
            $usetextsources ? 160 : 100
        );

        return saas_client::envelope($raw, $httpcode, $connectionerror);
    }

    /**
     * Persist the source context that produced this question set.
     *
     * FIX-KC-REGEN-GROUNDING (v1.5.95): regenerate_instructions forwards this blob to the
     * service as the authoritative source of truth. Without it, "Regenerate Questions" only
     * ever sees the OLD question text and drifts into generic content unrelated to the
     * original topics, text sources and workplace context. It is written on every generate
     * call, overwriting any prior context, so it always reflects the inputs behind the
     * current question set.
     *
     * @param \stdClass $cm Course module record.
     * @param array $sourcecontext The context blob to store.
     * @return void
     */
    private static function persist_source_context(\stdClass $cm, array $sourcecontext): void {
        global $DB;
        try {
            $DB->set_field('aiknowledgecheck', 'sourcecontext', json_encode($sourcecontext), ['id' => $cm->instance]);
        } catch (Throwable $e) {
            // Best-effort — never block generation because the column is missing (a site
            // upgrade that has not run yet) or the write failed.
            debugging('aiknowledgecheck: sourcecontext persist failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Describes the value returned by execute().
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(
            [
                'ok' => new external_value(PARAM_BOOL, 'True when the generation job was accepted'),
                'error' => new external_value(PARAM_TEXT, 'Error message, empty on success'),
                'resultjson' => new external_value(
                    /* phpcs:ignore moodle.Commenting.InlineComment */
                    PARAM_RAW, // pipeline-ignore: PARAM_RAW — JSON blob, JSON.parse()'d by the client
                    'The generation service response verbatim, as a JSON string'
                ),
            ]
        );
    }
}
