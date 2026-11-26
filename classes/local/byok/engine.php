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

namespace mod_aiknowledgecheck\local\byok;

/**
 * The LMS Labs Knowledge Check service, ported to run on the site's own AI.
 *
 * This is the service's own logic, in PHP: the same prompts, token budgets, parsing, repairs,
 * de-duplication, answer shuffling and narration. Prompt texts are generated from the service
 * source, so they match it character for character. The AI, narration, picture and random-number
 * calls go through the hooks below, which byok.php points at the site's own AI.
 *
 * Values from the AI's JSON are kept as the service sees them: a JSON object is a PHP array with
 * string keys (or an empty \stdClass), a JSON array is a PHP list.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine {
    /** JavaScript's whitespace, for trim() and \s. */
    const WS = '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /** @var callable fn(string $system, string $user, array $options): string. Options: maxtokens, json, temperature. */
    public static $aicall = null;
    /** @var callable fn(string $text, array $voice): string audio bytes. Voice: languageCode, name, id. */
    public static $tts = null;
    /** @var callable fn(string $prompt): string data: URL. */
    public static $image = null;
    /** @var callable fn(): float in [0, 1). */
    public static $random = null;
    /** @var callable|null fn(int $progress, string $message), told how a generation is going. */
    public static $progress = null;
    /** @var string|null The last provider error during a generation, to say why nothing came back. */
    public static $providererror = null;

    /** The education types the service accepts. */
    const EDUCATION_TYPES = ['vet', 'academic', 'general', 'earlyyears', 'primary', 'secondary', 'seniorsecondary'];

    // Request handling: the generate route.

    /**
     * Check a generate request and work out the job, as the service's generate route does.
     *
     * @param array $body Request body, decoded from JSON.
     * @return array Keys: response (the route's JSON answer), params (the job to run).
     * @throws http_error
     */
    public static function generate_request(array $body): array {
        $issues = [];
        $d = [];
        $d['siteId'] = self::z_string($body, 'siteId', $issues, ['required' => true]);
        $d['apiKey'] = self::z_string($body, 'apiKey', $issues, ['required' => true]);
        $d['topics'] = self::z_string($body, 'topics', $issues, ['required' => true, 'min' => 1, 'max' => 10000]);
        $d['questionsPerTopic'] = self::z_number($body, 'questionsPerTopic', $issues, 5, 1, 20);
        $d['useOwnQuestions'] = self::z_bool($body, 'useOwnQuestions', $issues, false);
        $d['userQuestions'] = self::z_string($body, 'userQuestions', $issues, ['default' => '', 'max' => 10000]);
        $d['usePdf'] = self::z_bool($body, 'usePdf', $issues, false);
        $d['pdfFiles'] = self::z_array($body, 'pdfFiles', $issues, function($item, $path) use (&$issues) {
            if (!self::is_object_js($item)) {
                $issues[] = [$path, self::z_expected('object', $item)];
                return null;
            }
            $item = (array) $item;
            return ['name' => self::z_string($item, 'name', $issues, ['default' => 'document.pdf'], $path),
                'base64' => self::z_string($item, 'base64', $issues, ['required' => true], $path),
                'questionCount' => self::z_number($item, 'questionCount', $issues, 10, 1, 30, $path)];
        });
        $d['useTextSources'] = self::z_bool($body, 'useTextSources', $issues, false);
        $d['textSources'] = self::z_array($body, 'textSources', $issues, function($item, $path) use (&$issues) {
            if (!self::is_object_js($item)) {
                $issues[] = [$path, self::z_expected('object', $item)];
                return null;
            }
            $item = (array) $item;
            return ['text' => self::z_string($item, 'text', $issues, ['required' => true, 'min' => 1, 'max' => 50000], $path),
                'questionCount' => self::z_number($item, 'questionCount', $issues, 10, 1, 30, $path)];
        });
        $d['workplaceContextEnabled'] = self::z_bool($body, 'workplaceContextEnabled', $issues, false);
        foreach (['country', 'state', 'industry'] as $key) {
            $d[$key] = self::z_string($body, $key, $issues, []);
        }
        $d['industryDetails'] = self::z_string($body, 'industryDetails', $issues, ['default' => '', 'max' => 500]);
        $d['jobLevel'] = self::z_string($body, 'jobLevel', $issues, ['default' => '']);
        $d['jobTitle'] = self::z_string($body, 'jobTitle', $issues, ['default' => '']);
        $d['educationType'] = self::z_enum($body, 'educationType', $issues, self::EDUCATION_TYPES, 'vet');
        $d['vetLevel'] = self::z_string($body, 'vetLevel', $issues, ['default' => '']);
        $d['academicLevel'] = self::z_string($body, 'academicLevel', $issues, ['default' => '']);
        $d['extraInstructions'] = self::z_string($body, 'extraInstructions', $issues, ['default' => '', 'max' => 2000]);
        if (!array_key_exists('voiceoverEnabled', $body)) {
            $d['voiceoverEnabled'] = true;
        } else if (is_bool($body['voiceoverEnabled']) || is_int($body['voiceoverEnabled']) || is_float($body['voiceoverEnabled'])) {
            $d['voiceoverEnabled'] = $body['voiceoverEnabled'];
        } else {
            $issues[] = [['voiceoverEnabled'], 'Invalid input'];
        }
        $d['voiceLanguage'] = self::z_string($body, 'voiceLanguage', $issues, ['default' => 'en-AU']);
        $d['voiceGender'] = self::z_string($body, 'voiceGender', $issues, ['default' => 'female']);
        $d['voiceId'] = self::z_string($body, 'voiceId', $issues, ['default' => 'Aoede']);
        $d['surveyMode'] = self::z_bool($body, 'surveyMode', $issues, false);
        $d['surveyScale'] = self::z_string($body, 'surveyScale', $issues, ['default' => 'likert5agree']);
        $d['freetextQuestions'] = self::z_array($body, 'freetextQuestions', $issues, function($item, $path) use (&$issues) {
            if (!is_string($item)) {
                $issues[] = [$path, self::z_expected('string', $item)];
                return null;
            }
            return $item;
        });
        self::z_string($body, 'idempotencyKey', $issues, ['trim' => true, 'min' => 1, 'max' => 200]);
        if ($issues) {
            $text = implode(', ', array_map(function($issue) {
                return implode('.', $issue[0]) . ': ' . $issue[1];
            }, $issues));
            throw new http_error(400, 'Validation error: ' . $text);
        }

        $userquestionlist = [];
        $validatedtextsources = [];
        if ($d['useTextSources'] && $d['textSources']) {
            foreach ($d['textSources'] as $source) {
                $text = self::jtrim($source['text']);
                if (self::len($text) < 50) {
                    throw new http_error(400, 'Text source is too short. Please paste at least 50 characters of content.');
                }
                if (self::len($text) > 50000) {
                    $text = self::slice($text, 0, 50000);
                }
                $validatedtextsources[] = ['text' => $text, 'questionCount' => self::js_or($source['questionCount'], 10)];
            }
            $topiclist = [];
            foreach ($validatedtextsources as $i => $unused) {
                $topiclist[] = 'Text source ' . ($i + 1);
            }
            $totalquestions = 0;
            foreach ($validatedtextsources as $source) {
                $totalquestions = self::js_add($totalquestions, $source['questionCount']);
            }
        } else if ($d['usePdf'] && $d['pdfFiles']) {
            // The plugin never sends PDFs; reading them needs the service's PDF parser.
            throw new http_error(400, 'Failed to process PDF file. Please try a different document.');
        } else if ($d['useOwnQuestions'] && $d['userQuestions'] !== '') {
            $userquestionlist = array_values(array_filter(array_map([self::class, 'jtrim'],
                explode("\n", $d['userQuestions'])), function($q) {
                    return $q !== '';
                }));
            if (!$userquestionlist) {
                throw new http_error(400, 'At least one question is required');
            }
            $topiclist = ['User-provided questions'];
            $totalquestions = count($userquestionlist);
        } else {
            $topiclist = array_values(array_filter(array_map([self::class, 'jtrim'], explode("\n", $d['topics'])),
                function($t) {
                    return $t !== '';
                }));
            if (!$topiclist) {
                throw new http_error(400, 'At least one topic is required');
            }
            $totalquestions = count($topiclist) * $d['questionsPerTopic'];
        }
        $creditsrequired = self::js_truthy($d['voiceoverEnabled']) ? self::js_mul($totalquestions, 2) : $totalquestions;

        $params = [
            'topics' => $topiclist,
            'questionsPerTopic' => $d['questionsPerTopic'],
            'useOwnQuestions' => (bool) $d['useOwnQuestions'],
            'userQuestions' => $userquestionlist,
            'useTextSources' => (bool) $d['useTextSources'],
            'textSources' => $validatedtextsources,
            'country' => self::js_or($d['country'], ''),
            'state' => self::js_or($d['state'], ''),
            'industry' => self::js_or($d['industry'], ''),
            'industryDetails' => self::js_or($d['industryDetails'], ''),
            'jobLevel' => self::js_or($d['jobLevel'], ''),
            'jobTitle' => self::js_or($d['jobTitle'], ''),
            'educationType' => self::js_or($d['educationType'], 'vet'),
            'vetLevel' => self::js_or($d['vetLevel'], ''),
            'academicLevel' => self::js_or($d['academicLevel'], ''),
            'extraInstructions' => self::js_or($d['extraInstructions'], ''),
            'workplaceContextEnabled' => $d['workplaceContextEnabled'],
            'voiceoverEnabled' => self::js_truthy($d['voiceoverEnabled']),
            'voiceLanguage' => $d['voiceLanguage'],
            'voiceGender' => $d['voiceGender'],
            'voiceId' => $d['voiceId'],
            'creditsRequired' => $creditsrequired,
            'surveyMode' => $d['surveyMode'],
            'surveyScale' => $d['surveyScale'],
            'freetextQuestions' => $d['freetextQuestions'],
        ];
        return ['response' => ['ok' => true, 'creditsRequired' => $creditsrequired, 'totalQuestions' => $totalquestions],
            'params' => $params];
    }

    // The job: processKnowledgeCheck.

    /**
     * Run a generation job, as the service's processKnowledgeCheck does.
     *
     * @param array $params From generate_request().
     * @return array The questions.
     * @throws js_error When nothing could be generated; the message is the job's error.
     */
    public static function process(array $params): array {
        self::$providererror = null;
        $allquestions = [];
        self::progress(5, $params['surveyMode']
            ? 'Generating survey questions...'
            : ($params['useOwnQuestions'] ? 'Generating answer options for your questions...'
                : 'Generating knowledge check questions...'));

        $contextparts = [];
        foreach (['country' => 'Country', 'state' => 'State/Region', 'industry' => 'Industry',
                'industryDetails' => 'Industry Details', 'jobTitle' => 'Job Title', 'jobLevel' => 'Job Level'] as $key => $label) {
            if (self::js_truthy($params[$key])) {
                $contextparts[] = $label . ': ' . self::js_str($params[$key]);
            }
        }
        $education = $params['educationType'];
        if ($education === 'vet' && self::js_truthy($params['vetLevel'])) {
            $names = ['cert1' => 'Certificate I', 'cert2' => 'Certificate II', 'cert3' => 'Certificate III',
                'cert4' => 'Certificate IV', 'diploma' => 'Diploma', 'advdiploma' => 'Advanced Diploma'];
            $contextparts[] = 'Education Level: ' . self::js_str(self::js_or($names[$params['vetLevel']] ?? null,
                $params['vetLevel'])) . ' (VET)';
        } else if ($education === 'academic' && self::js_truthy($params['academicLevel'])) {
            $names = ['year10' => 'Year 10', 'year11' => 'Year 11', 'year12' => 'Year 12', 'undergraduate' => 'Undergraduate'];
            $contextparts[] = 'Education Level: ' . self::js_str(self::js_or($names[$params['academicLevel']] ?? null,
                $params['academicLevel']));
        } else if (in_array($education, ['earlyyears', 'primary', 'secondary', 'seniorsecondary'], true)) {
            $level = self::school_level($education, $params['academicLevel']);
            if ($level !== null && $level !== '') {
                $contextparts[] = 'Education Level: ' . $level;
            }
        } else if ($education === 'general') {
            $contextparts[] = 'Education Level: General Training (non-accredited professional development — write practical, '
                . 'accessible questions suitable for any adult learner; no AQF level alignment required)';
        }
        $context = implode(', ', $contextparts);
        $voice = self::voice_config($params['voiceLanguage'], $params['voiceId']);

        if ($params['surveyMode']) {
            $allquestions = self::survey($params);
        } else if ($params['useTextSources'] && $params['textSources']) {
            $offset = 0;
            $total = count($params['textSources']);
            foreach ($params['textSources'] as $si => $source) {
                self::progress(10 + (int) floor(($si / $total) * 50), 'Generating ' . self::js_str($source['questionCount'])
                    . ' questions from text source ' . ($si + 1) . '/' . $total . '...');
                try {
                    $questions = self::from_text($source['text'], $source['questionCount'], $context,
                        $params['extraInstructions'], $params['country'], $params['voiceLanguage'],
                        $params['workplaceContextEnabled']);
                    foreach ($questions as $j => $q) {
                        $q['topicIndex'] = $si;
                        $q['questionIndex'] = $offset + $j;
                        $allquestions[] = $q;
                    }
                    $offset += count($questions);
                } catch (js_error $e) {
                    self::progress(null, 'Text source ' . ($si + 1) . ' failed, continuing with remaining sources...');
                }
            }
            $allquestions = self::narrate_all($allquestions, $params, $voice);
        } else if ($params['useOwnQuestions'] && $params['userQuestions']) {
            $total = count($params['userQuestions']);
            for ($start = 0; $start < $total; $start += 5) {
                $end = min($start + 5, $total);
                self::progress(10 + (int) floor(($start / $total) * 60), 'Generating options for questions ' . ($start + 1)
                    . '-' . $end . '/' . $total . '...');
                for ($i = $start; $i < $end; $i++) {
                    try {
                        $result = self::options_for_question($params['userQuestions'][$i], $context,
                            $params['extraInstructions'], $params['country'], $params['voiceLanguage']);
                    } catch (js_error $e) {
                        continue;
                    }
                    $result['originalIndex'] = $i;
                    $result['_failed'] = false;
                    $result['topicIndex'] = 0;
                    $result['questionIndex'] = $i;
                    $allquestions[] = $result;
                }
            }
            $allquestions = self::narrate_all($allquestions, $params, $voice);
        } else {
            $total = count($params['topics']);
            foreach ($params['topics'] as $i => $topic) {
                self::progress(10 + (int) floor(($i / $total) * 60), 'Generating questions for topic ' . ($i + 1) . '/'
                    . $total . '...');
                try {
                    $questions = self::from_topic($topic, $params['questionsPerTopic'], $context, $params['extraInstructions'],
                        $params['country'], $params['voiceLanguage'], $params['workplaceContextEnabled']);
                    foreach ($questions as $j => $q) {
                        $q['topicIndex'] = $i;
                        $q['questionIndex'] = $j;
                        $allquestions[] = $q;
                    }
                } catch (js_error $e) {
                    self::progress(null, 'Topic ' . ($i + 1) . ' failed, continuing with remaining topics...');
                }
            }
            $allquestions = self::narrate_all($allquestions, $params, $voice);
        }

        $allquestions = self::dedupe_names(self::dedupe($allquestions));
        if (!$allquestions) {
            $message = 'No questions could be generated. The AI may have returned invalid responses. Please try again or '
                . 'simplify your input.';
            if (self::$providererror !== null) {
                // Not in the service: on the site's own AI the usual cause is the provider refusing the call.
                $message .= ' Your AI provider said: ' . self::$providererror;
            }
            throw new js_error($message);
        }
        self::progress(90, 'Finalizing...');
        return self::redistribute($allquestions);
    }

    /**
     * Report progress.
     *
     * @param int|null $progress Percent, or null to keep it.
     * @param string $message
     */
    protected static function progress(?int $progress, string $message): void {
        if (self::$progress) {
            (self::$progress)($progress, $message);
        }
    }

    /**
     * Survey mode: scale questions, then the free-text ones.
     *
     * @param array $params
     * @return array
     */
    protected static function survey(array $params): array {
        $scales = [
            'likert5agree' => ['Strongly Agree', 'Agree', 'Uncertain', 'Disagree', 'Strongly Disagree'],
            'likert5sat' => ['Very Satisfied', 'Satisfied', 'Neutral', 'Dissatisfied', 'Very Dissatisfied'],
            'likert5freq' => ['Always', 'Often', 'Sometimes', 'Rarely', 'Never'],
            'likert5qual' => ['Excellent', 'Good', 'Average', 'Poor', 'Very Poor'],
            'likert5imp' => ['Very Important', 'Important', 'Neutral', 'Unimportant', 'Not Important at All'],
            'likert4agree' => ['Strongly Agree', 'Agree', 'Disagree', 'Strongly Disagree'],
            'yesno' => ['Yes', 'No'],
            'yesnounsure' => ['Yes', 'No', 'Unsure'],
            'nps5' => ['1 – Very Poor', '2 – Poor', '3 – Neutral', '4 – Good', '5 – Excellent'],
        ];
        $scale = self::js_or($params['surveyScale'], 'likert5agree');
        $labels = $scales[$scale] ?? $scales['likert5agree'];
        $scaledisplay = implode(' / ', $labels);
        $texts = [];
        if ($params['useOwnQuestions'] && $params['userQuestions']) {
            $texts = array_values(array_filter($params['userQuestions'], function($q) {
                return self::jtrim($q) !== '';
            }));
        } else {
            $surveysystemprompt = ('You are an expert survey designer. Return ONLY valid JSON: a flat array of strings, one survey question per string. No keys, no objects, no explanations, no markdown.');
            $take = function(string $raw) use (&$texts) {
                $obj = self::parse($raw);
                if (self::is_array_js($obj)) {
                    $arr = $obj;
                } else {
                    $questions = self::prop($obj, 'questions');
                    if (self::is_array_js($questions)) {
                        $arr = $questions;
                    } else {
                        $arr = [];
                        foreach (self::object_values($obj) as $v) {
                            if (self::is_array_js($v)) {
                                $arr = $v;
                                break;
                            }
                        }
                    }
                }
                foreach ($arr as $q) {
                    if (is_string($q) && self::jtrim($q) !== '') {
                        $texts[] = $q;
                    }
                }
            };
            if ($params['useTextSources'] && $params['textSources']) {
                foreach ($params['textSources'] as $source) {
                    $sourcequestioncount = $source['questionCount'];
                    $sourcetext8000 = self::slice($source['text'], 0, 8000);
                    $scaledisplay = implode(' / ', $labels);
                    try {
                        $take(self::call($surveysystemprompt, ('Based on the following content, write ' . self::js_str($sourcequestioncount) . ' concise survey questions suitable for learner feedback or post-training evaluation. Each question must be directly answerable on this scale: ' . self::js_str($scaledisplay) . '. Return ONLY a JSON array of strings.

CONTENT:
' . self::js_str($sourcetext8000)), 2000, true, 0.7));
                    } catch (js_error $e) {
                        continue;
                    }
                }
            } else {
                $questionspertopic = $params['questionsPerTopic'];
                foreach ($params['topics'] as $topic) {
                    try {
                        $take(self::call($surveysystemprompt, ('Write ' . self::js_str($questionspertopic) . ' concise survey questions about: "' . self::js_str($topic) . '". Each question must be directly answerable on this scale: ' . self::js_str($scaledisplay) . '. Return ONLY a JSON array of strings.'), 1000, true, 0.7));
                    } catch (js_error $e) {
                        continue;
                    }
                }
            }
        }
        $all = [];
        foreach ($texts as $text) {
            $all[] = ['question' => self::jtrim($text), 'options' => array_map(function($label) {
                return ['text' => $label, 'explanation' => ''];
            }, $labels), 'correctAnswer' => 0, 'explanations' => array_fill(0, count($labels), ''), 'audioData' => [],
                'timestamp_seconds' => null, 'isSurvey' => true];
        }
        foreach ($params['freetextQuestions'] as $text) {
            if (self::jtrim($text) === '') {
                continue;
            }
            $all[] = ['question' => self::jtrim($text), 'options' => [], 'correctAnswer' => 0, 'explanations' => [],
                'audioData' => [], 'timestamp_seconds' => null, 'isSurvey' => true, 'questionType' => 'freetext'];
        }
        self::progress(90, 'Saving ' . count($all) . ' survey questions...');
        return $all;
    }

    /**
     * Narrate every explanation, when narration is on.
     *
     * @param array $questions
     * @param array $params
     * @param array $voice
     * @return array
     */
    protected static function narrate_all(array $questions, array $params, array $voice): array {
        if (!$params['voiceoverEnabled']) {
            self::progress(90, '');
            return $questions;
        }
        foreach ($questions as $i => $q) {
            self::progress(70 + (int) floor((($i + 1) / count($questions)) * 20), 'Generating voiceover ' . ($i + 1) . '/'
                . count($questions) . '...');
            $q['audioData'] = array_map(function($explanation) use ($voice) {
                return self::narrate_or_blank($explanation, $voice, true);
            }, self::list_of($q['explanations'] ?? null));
            $questions[$i] = $q;
        }
        return $questions;
    }

    /**
     * One narration clip, base64, or '' when it cannot be made.
     *
     * @param mixed $explanation
     * @param array $voice
     * @param bool $lenient Whether a non-text explanation is just skipped (generation) or an error (regeneration).
     * @return string
     */
    protected static function narrate_or_blank($explanation, array $voice, bool $lenient): string {
        if (!is_string($explanation)) {
            if ($lenient || !self::js_truthy($explanation)) {
                return '';
            }
            throw js_error::type('explanation.trim is not a function');
        }
        $text = self::jtrim($explanation);
        if ($text === '') {
            return '';
        }
        try {
            $audio = (self::$tts)($text, $voice);
        } catch (\moodle_exception $e) {
            // A lapsed licence stops the job; anything else leaves this clip silent, as the service does.
            if ($e->errorcode === 'byok_notavailable') {
                throw $e;
            }
            return '';
        } catch (\Exception $e) {
            return '';
        }
        return $audio !== '' ? base64_encode($audio) : '';
    }

    // Question writers: generateKnowledgeCheckQuestions, ...FromPdf (pasted text) and generateOptionsForQuestion.

    /**
     * Questions on one topic.
     *
     * @param string $topic
     * @param mixed $count
     * @param string $context
     * @param mixed $extrainstructions
     * @param mixed $country
     * @param string $language
     * @param bool $workplacecontextenabled
     * @return array
     */
    protected static function from_topic(string $topic, $count, string $context, $extrainstructions, $country, string $language,
            bool $workplacecontextenabled): array {
        $effectivecountry = self::js_or($country, strpos($language, 'en-AU') === 0 ? 'Australia' : '');
        $spellinginstructions = self::spelling($effectivecountry);
        $languagename = self::language_name($language);
        $isnonenglish = strpos($language, 'en-') !== 0;
        $languageinstruction = (self::js_truthy($isnonenglish) ? ('

IMPORTANT: Generate ALL content (questions, options, and explanations) in ' . self::js_str($languagename) . '. The entire response must be in ' . self::js_str($languagename) . ', not English.') : '');
        $australianlegislationguardrail = (self::js_truthy(self::js_or(self::js_or(($effectivecountry === 'Australia'), self::js_includes($context, 'Australia')), self::js_includes($context, 'AU'))) ? ('
NOTE: If (and ONLY if) the topic naturally involves workplace health and safety legislation, use current Australian terminology:
- Use "Work Health and Safety Act" (WHS Act) not "Occupational Health and Safety Act"
- Use "PCBU" (Person Conducting a Business or Undertaking) not "Occupier"
- Use "WHS Regulations" not "OH&S Regulations"
Do NOT introduce WHS/safety content into questions unless the topic is specifically about workplace safety.') : '');
        $extrainstructionssection = (self::js_truthy(self::js_and($extrainstructions, self::jtrim(self::js_str($extrainstructions)))) ? ('=== MANDATORY TEACHER INSTRUCTIONS ===
The teacher has provided the following MANDATORY instructions that you MUST follow.
These instructions affect BOTH the content AND style of the questions you generate.
You MUST incorporate these instructions into every question - failure to follow them is unacceptable.

TEACHER INSTRUCTIONS:
' . self::js_str(self::jtrim(self::js_str($extrainstructions))) . '
=== END MANDATORY INSTRUCTIONS ===

') : '');
        $systemprompt = ('You are a senior psychometrician who writes high-stakes assessment items for national certification bodies. Your items consistently achieve discrimination indices above 0.35 and point-biserial correlations above 0.30.

NON-NEGOTIABLE RULES:
1. Always return valid JSON only. No commentary outside JSON.
2. JSON keys MUST remain in English. Only VALUES may be in the target language.
3. Avoid moral signalling or obvious "safe sounding" correct answers.
4. In any language, avoid vague corporate/marketing language. If the target language has common buzzword equivalents, do not use them.

CORE PRINCIPLES:
1. Every distractor must exploit a documented cognitive error - never filler.
2. The correct option must be unambiguously correct and defensible with evidence.
3. Explanations are micro-lessons: they diagnose the learner\'s thinking error and teach the correct mental model.
4. Question stems must be self-contained - a knowledgeable person could answer without seeing the options.
5. Option sets must be homogeneous: same grammatical structure, similar length, same level of specificity.
6. SINGLE DECISIVE ANSWER: The source must support exactly one correct option. Avoid broad, context-dependent stems such as when someone "should wear a mask" unless the question specifies the task, hazard, conditions and role that make one option uniquely correct. If two options could be justified from the topic, narrow the stem or choose a different fact; never resolve ambiguity by inventing a rule.

ANTI-PATTERNS TO AVOID:
- "All of the above" / "None of the above" options
- Absolute qualifiers ("always", "never") that signal the wrong answer
- Negatively phrased stems ("Which is NOT...")
- Options that overlap or are subsets of each other
- Correct answer that is noticeably longer or more detailed than distractors
- Trivial recall questions that test memorisation of definitions alone
- Using "Which of the following" when a direct question works better
- NEVER start a question stem with "According to the document", "Based on the document", "The document states", or any similar document-referencing phrase - ask the question directly
- NEVER end any answer option with a full stop / period. Answer options must NOT have trailing punctuation.

BANNED WORDS - NEVER use these anywhere in questions, options, or explanations:
"delve", "dive", "unpack", "explore", "journey", "landscape", "leverage", "utilize", "utilise", "foster", "holistic", "robust", "synergy", "paradigm", "stakeholder engagement", "navigate", "realm", "tapestry", "multifaceted", "nuanced", "pivotal", "cutting-edge", "game-changer", "empower", "streamline"
Use plain, direct language instead.
When generating in a non-English language, also ban the direct translations of these terms and any local equivalents of vague corporate buzzwords.

RECALL QUESTION BAN - NEVER write questions that start with or contain:
"What is...", "What are...", "What does...", "Which term...", "Which definition...", "Define...", "Name the...", "List the...", "Identify the definition of...", "What is the meaning of...", "What is the purpose of..."
These are Level 1 recall questions. Every question MUST require APPLYING knowledge to a situation.

Return only valid JSON.');
        $prompt = (self::js_str($extrainstructionssection) . 'Create ' . self::js_str($count) . ' production-quality multiple-choice knowledge check questions on this topic:

TOPIC: ' . self::js_str($topic) . '
' . self::js_str((self::js_truthy($context) ? ('BACKGROUND CONTEXT: ' . self::js_str($context) . '
- Education Level: calibrate vocabulary complexity, scenario sophistication, and Bloom\'s level distribution to match this level
- Industry/Country: use for spelling conventions and to ground examples in relevant scenarios
- These settings add flavour - they must NOT change the subject matter away from the topic') : '')) . self::js_str($spellinginstructions) . self::js_str($languageinstruction) . '

CRITICAL RULE: Every question must test knowledge of the TOPIC above. If the topic is "leadership", questions must be about leadership principles - not safety regulations, compliance, or any other unrelated domain.
' . self::js_str($australianlegislationguardrail) . '

ASSESSMENT DESIGN FRAMEWORK:

1. BLOOM\'S TAXONOMY - TARGET LEVEL 3 "APPLY" (WITH FLEXIBILITY):
   DEFAULT: All questions MUST be at Bloom\'s Level 3 ("Apply"). This means:
   - Every question requires the learner to USE their knowledge to solve a problem, make a decision, or determine the correct action in a given situation
   - No pure recall/memorisation questions (Level 1 "Remember")
   - The learner must APPLY a principle, rule, procedure, or concept to a concrete situation
   
   FLEXIBILITY CLAUSE: For purely conceptual or theoretical topics (e.g., philosophy, ethics, abstract theory) where scenario-based application is unnatural, you may use up to 30% Level 2 "Understand" questions that require comparison, classification, or inference. Flag these with bloomLevel: "understand" in the JSON.
   
   QUESTION STYLE MIX - QUOTA SYSTEM:
   For ' . self::js_str($count) . ' questions, use this distribution:
   ' . self::js_str((self::js_truthy($workplacecontextenabled) ? ('- At least 40% SCENARIO-BASED APPLICATION: Present a realistic workplace or professional situation with a named role and specific circumstance, then ask what the person should do.
     Example: "A new employee reports a near-miss incident involving a forklift in the warehouse. As the shift supervisor, what should you do FIRST?"') : ('- NO SCENARIO QUESTIONS: Workplace context is disabled. Do NOT include named characters, workplace roles, or situational framing. All questions must be direct application-of-knowledge.'))) . '

   - At least ' . self::js_str((self::js_truthy($workplacecontextenabled) ? ('40') : ('80'))) . '% DIRECT APPLICATION-OF-KNOWLEDGE: Ask the learner to apply a known principle, rule, or procedure to determine the correct course of action — without a long narrative scenario.
     Example: "When implementing a risk control hierarchy, which control measure should be considered BEFORE administrative controls?"
   - Remaining 20% flexible — choose whichever style fits the concept best.
   
   ALL questions must require the learner to APPLY knowledge — not just recall a definition or explain a concept.

   ' . self::js_str((self::js_truthy($workplacecontextenabled) ? ('UNIQUE SCENARIO NAMES — MANDATORY:
   If any question introduces a named person in a scenario, EVERY named person across ALL ' . self::js_str($count) . ' questions MUST be a DIFFERENT individual.
   Assign names in order from this diverse pool: Zara, Daniel, Priya, James, Leila, Tom, Amara, Wei, Sofia, Raj, Fatima, Chris, Ngozi, Ben, Yuki, Alex, Kezia, Jordan, Hana, Tyler, Nadia, Sam, Rosa.
   - Q1 gets the FIRST unused name, Q2 the SECOND, and so on — in strict sequence.
   - Each scenario must also have a DIFFERENT workplace setting, role, and situation — not just a different name on the same scenario.
   - This rule applies even if some questions have no scenario: skip the name for that question and continue from the next name in the pool.') : ('NO NAMED PERSONS — ABSOLUTE: Do NOT include any named persons, workers, characters, or roles in any question under any circumstances. Do NOT use Marcus, Zara, or any other person name. All questions must be entirely impersonal and concept-based.'))) . '

2. QUESTION STEM DESIGN:
   - Write focused stems that test ONE concept per question
   - Every stem must present a situation, decision point, or application context - never ask "What is the definition of..."
   - For scenario stems: Include only relevant details - avoid window dressing or irrelevant narrative
   - For direct application stems: Frame as "When X happens, what should you do?" or "Which approach is correct when applying Y?"
   - Place all common wording in the stem, not repeated across options
   - Stem must be answerable by a knowledgeable person WITHOUT seeing the options (the "cover test")
   - NEVER start with "According to the document", "Based on the text", or any document-referencing phrase - ask directly

3. ANSWER CLARITY & DISTRACTOR DESIGN (CRITICAL):
   
   CLARITY MANDATE - ZERO AMBIGUITY:
   - The correct answer must be OBVIOUSLY correct to someone who has studied the material - there must be no reasonable argument for any other option
   - Each wrong answer must be CLEARLY wrong for a specific, identifiable reason - not "partially correct" or "almost right"
   - A learner should NEVER be torn between two options - if two options seem plausible, the question is poorly designed
   - Each of the 4 options must address a DIFFERENT aspect or approach - never two variations of the same idea
   - Test the question mentally: "Could a knowledgeable person confidently eliminate 3 options?" If not, redesign
    - For each question, identify one specific source-backed condition that makes the keyed option correct and every other option wrong. If the source does not establish that distinction, rewrite the stem to include the necessary condition or choose a different question.
   
   DISTRACTOR DESIGN - Each distractor must exploit one of these cognitive error types:
   a) PARTIAL KNOWLEDGE: Learner knows something but applies it incompletely
   b) OVERGENERALISATION: Learner applies a correct rule to the wrong context
   c) CONCEPT CONFUSION: Learner confuses two related but distinct concepts
   d) SURFACE-LEVEL REASONING: Answer sounds right but misses deeper principle
   e) SEQUENCING ERROR: Right steps but in the wrong order — common in procedures and compliance tasks
   f) SCOPE ERROR: Correct rule applied too broadly or too narrowly — common in policy, risk, and theory application
   
   Homogeneity rules:
   - All 4 options must be the same part of speech and grammatical structure
   - All 4 options must be within 20% of each other in word count
   - All 4 options must be at the same level of specificity (all general or all specific)
   - No option should contain technical jargon unless ALL options do

4. EXPLANATION QUALITY - DIAGNOSTIC FEEDBACK MODEL:
   Each explanation must contain THREE cognitive components. You may phrase them naturally — the structure matters, not the exact wording.

   For CORRECT answer - MUST start with "Correct." then include:
   - The governing principle, rule, or procedure that makes this right
   - Why it matters in practice (real-world significance)

   For INCORRECT answers - MUST start with "Incorrect." then include ALL THREE parts:
   PART 1 - Name the thinking error: identify what cognitive mistake led to this choice (confusing X with Y, applying the wrong rule, skipping a step, applying too broadly/narrowly, surface-level reasoning)
   PART 2 - Explain why it fails: give the specific factual reason this answer is wrong
   PART 3 - Teach the correction: state the key principle or distinction the learner should remember

   QUALITY RULES:
   - Every incorrect explanation must diagnose a specific thinking error — not just say "this is wrong"
   - Every incorrect explanation must teach something the learner can use next time
   - NEVER write vague feedback like "This is not the best answer" or "This option is incorrect"
   - NEVER reference options by letter (A, B, C, D) or position (first, second, third, fourth) - options are shuffled after generation

5. ANSWER POSITIONING:
   - ALWAYS place the factually correct option at index 0 (the first element of your options array) and set "correctAnswer": 0 for every question
   - The server automatically shuffles positions after generation to ensure random variety for students
   - Do NOT try to vary correctAnswer values yourself — this causes the wrong option to be highlighted as correct

SELF-CHECK BEFORE RETURNING (Apply to EVERY question):
- Question requires the learner to APPLY knowledge (Bloom\'s Level 3) - not just recall or explain (Level 2 allowed only for conceptual topics, max 30%)
- Stem presents an application context, scenario, or decision point
- Correct answer is defensible and unambiguous - no reasonable argument for any other option
- Each wrong answer is CLEARLY distinguishable from the correct answer and from each other
- No two options could be confused with each other - each addresses a different aspect/approach
- A knowledgeable learner could confidently eliminate all 3 wrong answers
- Every distractor exploits a named cognitive error (partial knowledge, overgeneralisation, concept confusion, surface-level reasoning, sequencing error, or scope error)
- All 4 options are homogeneous in grammar, length, and specificity
- Every incorrect explanation names a thinking error, explains why it fails, and teaches the correction
- No two questions test the same concept from the same angle
' . self::js_str((self::js_truthy($workplacecontextenabled) ? ('- At least 40% scenario-based, at least 40% direct application
- UNIQUE NAMES: Every named person across all ' . self::js_str($count) . ' questions is different — scan all questions for name repetition before returning') : ('- ZERO scenario questions: 100% direct application — no named persons, no workplace settings, no situational framing whatsoever (workplace context is disabled)
- NO NAMES CHECK: scan all ' . self::js_str($count) . ' questions one final time and confirm not a single named person, worker, character, or role appears anywhere — if any name slipped in, rewrite that question impersonally before returning'))) . '
- correctAnswer is 0 for every question (server shuffles positions automatically after generation)
- No banned words appear anywhere in the output (including target-language equivalents)
- All option texts are within 20% word count of each other (count the words!)
- JSON keys are in English, content values in the target language
- No commentary or text outside the JSON object
- If source content was provided, all questions must be grounded in that content - do not invent facts not present in the source

Return JSON:
{
  "questions": [
    {
      "question": "Application-focused question stem (scenario or direct) requiring learner to USE knowledge?",
      "options": [
        {"text": "Option text (8-15 words)", "explanation": "Correct. [Principle/rule that makes this right]. [Why it matters in practice]."},
        {"text": "Option text (8-15 words)", "explanation": "Incorrect. This reflects confusing X with Y. [Why this fails specifically]. [The key principle to remember]."},
        {"text": "Option text (8-15 words)", "explanation": "Incorrect. This suggests applying the rule too broadly. [Factual reason it fails]. [What to do instead]."},
        {"text": "Option text (8-15 words)", "explanation": "Incorrect. This reflects skipping a required step. [Specific consequence]. [The correct sequence to follow]."}
      ],
      "correctAnswer": 0,
      "timestamp_seconds": 69,
      "bloomLevel": "apply",
      "cognitiveErrorsTested": "List the 3 cognitive errors the distractors exploit"
    }
  ]
}

TIMESTAMP FIELD (REQUIRED — non-negotiable): Include "timestamp_seconds" (integer or null) in EVERY question.

WHEN THE CONTENT CONTAINS TIMESTAMPS:
Transcripts come in two formats — both work the same way:
- Line-start format: "0:08
Hello and welcome to this session..."  (timestamp at start of line)
- Inline format: "0:01 psychosocial hazards 0:06 average work-related 0:08 injuries..."  (timestamps within a sentence)
For BOTH formats: scan the content for the timestamp that marks where the question\'s TOPIC BEGINS to be introduced. Find the nearest timestamp at or just before the relevant text. Convert to total seconds: "0:08" = 8, "1:09" = 69, "2:36" = 156. Set that integer as "timestamp_seconds".

SPREAD RULE (MANDATORY): Every question MUST reference a DIFFERENT part of the content — each question\'s topic comes from a different segment, so each timestamp_seconds value MUST be distinct. NEVER assign the same timestamp_seconds to two or more questions. If two questions cover topics near the same timestamp, use the nearest distinct timestamp for each.

SELF-CHECK: Before returning, verify (a) every question has "timestamp_seconds" set (not omitted, not null when timestamps exist in the content), (b) no two questions share the same timestamp_seconds value.

If the content contains NO timestamps at all, set "timestamp_seconds" to null for all questions.

CORRECT ANSWER PLACEMENT: Always place the factually correct option at index 0 (the first element of your options array) and set "correctAnswer": 0 for every question. The server will shuffle the options into random positions automatically. Do NOT try to vary correctAnswer values yourself — doing so causes the wrong option to be marked correct.');
        $budget = min($isnonenglish ? 18000 : 15000, max(9000, $count * ($isnonenglish ? 1300 : 900)));
        $label = 'topic "' . self::slice($topic, 0, 50) . '"';
        $parsed = self::safe_parse($systemprompt, $prompt, (int) $budget, $label);
        return self::finish_set(self::validate_set($parsed, $label), $count);
    }

    /**
     * Questions from one pasted text.
     *
     * @param string $pdftext
     * @param mixed $count
     * @param string $context
     * @param mixed $extrainstructions
     * @param mixed $country
     * @param string $language
     * @param bool $workplacecontextenabled
     * @return array
     */
    protected static function from_text(string $pdftext, $count, string $context, $extrainstructions, $country, string $language,
            bool $workplacecontextenabled): array {
        $effectivecountry = self::js_or($country, strpos($language, 'en-AU') === 0 ? 'Australia' : '');
        $spellinginstructions = self::spelling($effectivecountry);
        $languagename = self::language_name($language);
        $isnonenglish = strpos($language, 'en-') !== 0;
        $languageinstruction = (self::js_truthy($isnonenglish) ? ('

IMPORTANT: Generate ALL content (questions, options, and explanations) in ' . self::js_str($languagename) . '. The entire response must be in ' . self::js_str($languagename) . ', not English.') : '');
        $australianlegislationguardrail = (self::js_truthy(self::js_or(self::js_or(($effectivecountry === 'Australia'), self::js_includes($context, 'Australia')), self::js_includes($context, 'AU'))) ? ('
NOTE: If (and ONLY if) the document content naturally discusses workplace health and safety legislation, use current Australian terminology: "WHS Act" not "OH&S Act", "PCBU" not "Occupier", "WHS Regulations" not "OH&S Regulations". Do NOT introduce WHS/safety content unless it appears in the document.
') : '');
        $extrainstructionssection = (self::js_truthy(self::js_and($extrainstructions, self::jtrim(self::js_str($extrainstructions)))) ? ('=== MANDATORY TEACHER INSTRUCTIONS ===
The teacher has provided the following MANDATORY instructions that you MUST follow:

' . self::js_str(self::jtrim(self::js_str($extrainstructions))) . '
=== END MANDATORY INSTRUCTIONS ===

') : '');
        $systemprompt = ('You are a senior psychometrician who creates assessment items from source documents for national certification bodies. Your items consistently achieve discrimination indices above 0.35.

CORE PRINCIPLES:
1. Questions must be grounded EXCLUSIVELY in the provided document - never inject outside knowledge.
2. Every distractor must exploit a plausible misreading or misunderstanding of the document content.
3. Explanations must teach the correct understanding grounded in the document content - but NEVER use phrases like "According to the document" or "The document states".
4. Question stems must be self-contained and answerable without seeing the options. NEVER start stems with "According to the document" or similar - ask the question directly.
5. Option sets must be homogeneous: same grammar, similar length, same specificity level.
6. SINGLE DECISIVE ANSWER: The document must support exactly one correct option. Avoid broad, context-dependent stems unless the stem specifies conditions that make one answer uniquely correct. If two options could be justified from the source, narrow the stem or choose a different fact; do not add an outside rule to force a key.

ANTI-PATTERNS TO AVOID:
- "All of the above" / "None of the above" options
- Absolute qualifiers ("always", "never") that signal the wrong answer
- Negatively phrased stems ("Which is NOT...")
- Options that overlap or are subsets of each other
- Correct answer that is noticeably longer or more detailed than distractors
- Injecting topics, terminology, or concepts NOT present in the document
- NEVER start a question stem with "According to the document", "Based on the document", "The document states", or any similar document-referencing phrase - ask the question directly as if the learner should already know the content

BANNED WORDS - NEVER use these anywhere in questions, options, or explanations:
"delve", "dive", "unpack", "explore", "journey", "landscape", "leverage", "utilize", "utilise", "foster", "holistic", "robust", "synergy", "paradigm", "stakeholder engagement", "navigate", "realm", "tapestry", "multifaceted", "nuanced", "pivotal", "cutting-edge", "game-changer", "empower", "streamline"
Use plain, direct language instead.

RECALL QUESTION BAN - NEVER write questions that start with or contain:
"What is...", "What are...", "What does...", "Which term...", "Which definition...", "Define...", "Name the...", "List the...", "Identify the definition of...", "What is the meaning of...", "What is the purpose of..."
These are Level 1 recall questions. Every question MUST require APPLYING knowledge to a situation.

Return only valid JSON.');
        $truncatedpdf = self::len($pdftext) > 30000 ? self::slice($pdftext, 0, 30000) . "\n\n[Document truncated...]" : $pdftext;
        $prompt = (self::js_str($extrainstructionssection) . 'Create ' . self::js_str($count) . ' production-quality multiple-choice knowledge check questions based EXCLUSIVELY on the following document.

=== DOCUMENT CONTENT (THIS IS THE ONLY SOURCE FOR QUESTIONS) ===
' . self::js_str($truncatedpdf) . '
=== END DOCUMENT CONTENT ===

' . self::js_str((self::js_truthy($context) ? ('BACKGROUND CONTEXT: ' . self::js_str($context) . '
- Education Level: calibrate vocabulary complexity and Bloom\'s level distribution to match
- Industry/Country: use for spelling conventions only
- These settings must NOT change the subject matter away from the document content') : '')) . self::js_str($spellinginstructions) . self::js_str($languageinstruction) . '
' . self::js_str($australianlegislationguardrail) . '
ASSESSMENT DESIGN FRAMEWORK:

1. CONTENT COVERAGE & FIDELITY:
   - Every question must be answerable using ONLY information in the document
   - MANDATORY DISTRIBUTION: Mentally divide the document into ' . self::js_str($count) . ' approximately equal sections from start to finish. Each question must draw its core concept from a DIFFERENT section. You must not generate more than 2 questions from any single section or paragraph cluster. Questions must progress through the document — early questions from early content, later questions from later content.
   - Cover different sections and key concepts — do NOT cluster questions on the opening paragraphs
   - Prioritise high-value concepts: processes, relationships, cause-effect, key distinctions
   - Never inject outside knowledge, terminology, or concepts not in the document
   - If the document discusses topic X, questions must be about topic X
    - For each item, verify the source supports one keyed answer and clearly contradicts each distractor. If it does not, rewrite the stem with a source-backed condition or choose a different concept.

2. BLOOM\'S TAXONOMY - LEVEL 3 "APPLY" (MANDATORY):
   ALL questions MUST be at Bloom\'s Taxonomy Level 3 ("Apply"). This means:
   - Every question requires the learner to USE document knowledge to solve a problem, make a decision, or determine the correct action in a given situation
   - No pure recall/memorisation questions (Level 1 "Remember") - never ask "What does the document define as..."
   - No "explain the concept" questions (Level 2 "Understand") - never ask "Why is X important?"
   - The learner must APPLY a principle, rule, procedure, or concept from the document to a concrete situation
   
   QUESTION STYLE MIX - QUOTA SYSTEM:
   For ' . self::js_str($count) . ' questions, use this distribution:
   ' . self::js_str((self::js_truthy($workplacecontextenabled) ? ('- At least 40% SCENARIO-BASED APPLICATION: Present a realistic situation with a named role and specific circumstance grounded in the document content, then ask what the person should do.
     Example: "A worker notices a chemical spill near the loading dock. What should they do FIRST?"') : ('- NO SCENARIO QUESTIONS: Workplace context is disabled. Do NOT include named characters, workplace roles, or situational framing. All questions must be direct application-of-knowledge.'))) . '
   - At least ' . self::js_str((self::js_truthy($workplacecontextenabled) ? ('40') : ('80'))) . '% DIRECT APPLICATION-OF-KNOWLEDGE: Ask the learner to apply a known principle or procedure from the document to determine the correct course of action.
     Example: "When selecting PPE for a task involving airborne particulates, which factor should be assessed FIRST?"
   - Remaining 20% flexible — choose whichever style fits the concept best.
   
   ALL questions must require the learner to APPLY knowledge — not just recall a fact from the document.

   ' . self::js_str((self::js_truthy($workplacecontextenabled) ? ('UNIQUE SCENARIO NAMES — MANDATORY:
   If any question introduces a named person in a scenario, EVERY named person across ALL ' . self::js_str($count) . ' questions MUST be a DIFFERENT individual.
   Assign names in order from this diverse pool: Zara, Daniel, Priya, James, Leila, Tom, Amara, Wei, Sofia, Raj, Fatima, Chris, Ngozi, Ben, Yuki, Alex, Kezia, Jordan, Hana, Tyler, Nadia, Sam, Rosa.
   - Q1 gets the FIRST unused name, Q2 the SECOND, and so on — in strict sequence.
   - Each scenario must also have a DIFFERENT setting, role, and situation — not just a different name on the same scenario.') : ('NO NAMED PERSONS — ABSOLUTE: Do NOT include any named persons, workers, characters, or roles in any question under any circumstances. Do NOT use Marcus, Zara, or any other person name. All questions must be entirely impersonal and concept-based.'))) . '

3. QUESTION STEM DESIGN:
   - Write focused stems that test ONE concept per question
   - Every stem must present a situation, decision point, or application context - never ask "What is the definition of..."
   - For scenario stems: Include only relevant details grounded in document content
   - For direct application stems: Frame as "When X happens, what should you do?" or "Which approach is correct when applying Y?"
   - Stem must pass the "cover test" - answerable without seeing options
   - Place all common wording in the stem, not repeated across options
   - Avoid window dressing - every word should be necessary
   - NEVER start with "According to the document", "Based on the text", or any document-referencing phrase - ask directly as knowledge

4. ANSWER CLARITY & DISTRACTOR DESIGN (CRITICAL):
   
   CLARITY MANDATE - ZERO AMBIGUITY:
   - The correct answer must be OBVIOUSLY correct to someone who has studied the material - there must be no reasonable argument for any other option
   - Each wrong answer must be CLEARLY wrong for a specific, identifiable reason - not "partially correct" or "almost right"
   - A learner should NEVER be torn between two options - if two options seem plausible, the question is poorly designed
   - Each of the 4 options must address a DIFFERENT aspect or approach - never two variations of the same idea
   - Test the question mentally: "Could a knowledgeable person confidently eliminate 3 options?" If not, redesign
   
   DISTRACTOR DESIGN - Each distractor must exploit one of these cognitive errors:
   a) MISREADING: Plausible misinterpretation of a specific document passage
   b) PARTIAL RECALL: Remembering part of the information but not the critical detail
   c) CONCEPT CONFUSION: Confusing two related ideas both mentioned in the document
   d) OVERGENERALISATION: Applying a document principle beyond its stated scope
   e) SEQUENCING ERROR: Right steps but in the wrong order — common in procedures described in the document
   f) SCOPE ERROR: Correct principle applied too broadly or too narrowly based on the document content

   Homogeneity rules:
   - All 4 options: same grammar, within 20% word count, same specificity level
   - No "All of the above" / "None of the above"

5. EXPLANATION QUALITY - DIAGNOSTIC FEEDBACK:
   Each explanation must contain THREE cognitive components. You may phrase them naturally — the structure matters, not the exact wording.

   For CORRECT answer - MUST start with "Correct." then include:
   - The governing principle, key fact, or procedure that makes this right
   - Why it matters in practice (practical significance)

   For INCORRECT answers - MUST start with "Incorrect." then include ALL THREE parts:
   PART 1 - Name the thinking error: identify what cognitive mistake led to this choice (misreading a key passage, confusing two related ideas, partially recalling a procedure, overgeneralising a principle, sequencing error, scope error)
   PART 2 - Explain why it fails: give the specific factual reason this answer is wrong
   PART 3 - Teach the correction: state the key principle or distinction the learner should remember

   QUALITY RULES:
   - Every incorrect explanation must diagnose a specific thinking error — not just say "this is wrong"
   - Every incorrect explanation must teach something the learner can use next time
   - NEVER use "According to the document" or "The document states" - explain directly as knowledge
   - NEVER write vague feedback like "This is not the best answer"
   - NEVER reference options by letter (A, B, C, D) or position - options are shuffled after generation

6. ANSWER POSITIONING:
   - ALWAYS place the factually correct option at index 0 (the first element of your options array) and set "correctAnswer": 0 for every question
   - The server automatically shuffles positions after generation to ensure random variety for students
   - Do NOT try to vary correctAnswer values yourself — this causes the wrong option to be highlighted as correct

SELF-CHECK BEFORE RETURNING:
- Question requires the learner to APPLY document knowledge (Bloom\'s Level 3) - not just recall or explain
- Stem presents an application context, scenario, or decision point
- Every answer is verifiable from the document text
- Correct answer is defensible and unambiguous - no reasonable argument for any other option
- Each wrong answer is CLEARLY distinguishable from the correct answer and from each other
- No two options could be confused with each other - each addresses a different aspect/approach
- A knowledgeable learner could confidently eliminate all 3 wrong answers
- Every distractor exploits a named cognitive error (misreading, partial recall, concept confusion, overgeneralisation, sequencing error, or scope error)
- No two questions test the same concept from the same angle
- All option sets are homogeneous in grammar and length (within 20% word count)
- NO question stem or explanation contains "According to the document", "Based on the document", or similar phrases
- Every incorrect explanation names a thinking error, explains why it fails, and teaches the correction
' . self::js_str((self::js_truthy($workplacecontextenabled) ? ('- At least 40% scenario-based, at least 40% direct application
- UNIQUE NAMES: Every named person across all ' . self::js_str($count) . ' questions is different — scan all questions for name repetition before returning') : ('- ZERO scenario questions: 100% direct application — no named persons, no workplace settings, no situational framing whatsoever (workplace context is disabled)
- NO NAMES CHECK: scan all ' . self::js_str($count) . ' questions one final time and confirm not a single named person, worker, character, or role appears anywhere — if any name slipped in, rewrite that question impersonally before returning'))) . '
- correctAnswer is 0 for every question (server shuffles positions automatically after generation)
- No banned words appear anywhere in the output

Return JSON:
{
  "questions": [
    {
      "question": "' . self::js_str((self::js_truthy($workplacecontextenabled) ? 'Application-focused question stem (scenario or direct) requiring learner to USE document knowledge?' : 'Direct application-focused question stem (NO scenarios, NO named persons) requiring learner to USE document knowledge?')) . '",
      "options": [
        {"text": "Option text (8-15 words)", "explanation": "Correct. [Principle/key fact that makes this right]. [Why it matters in practice]."},
        {"text": "Option text (8-15 words)", "explanation": "Incorrect. This reflects misreading the section about X. [Why this fails specifically]. [The key principle to remember]."},
        {"text": "Option text (8-15 words)", "explanation": "Incorrect. This suggests confusing X with Y from the content. [Factual reason it fails]. [What to do instead]."},
        {"text": "Option text (8-15 words)", "explanation": "Incorrect. This reflects only partially recalling the procedure. [Specific consequence]. [The correct understanding]."}
      ],
      "correctAnswer": 0,
      "timestamp_seconds": 69,
      "bloomLevel": "apply",
      "cognitiveErrorsTested": "List the 3 cognitive errors the distractors exploit"
    }
  ]
}

TIMESTAMP FIELD (REQUIRED — non-negotiable): Include "timestamp_seconds" (integer or null) in EVERY question.

WHEN THE CONTENT CONTAINS TIMESTAMPS:
Transcripts come in two formats — both work the same way:
- Line-start format: "0:08
Hello and welcome to this session..."  (timestamp at start of line)
- Inline format: "0:01 psychosocial hazards 0:06 average work-related 0:08 injuries..."  (timestamps within a sentence)
For BOTH formats: scan the content for the timestamp that marks where the question\'s TOPIC BEGINS to be introduced. Find the nearest timestamp at or just before the relevant text. Convert to total seconds: "0:08" = 8, "1:09" = 69, "2:36" = 156. Set that integer as "timestamp_seconds".

SPREAD RULE (MANDATORY): Every question MUST reference a DIFFERENT part of the content — each question\'s topic comes from a different segment, so each timestamp_seconds value MUST be distinct. NEVER assign the same timestamp_seconds to two or more questions. If two questions cover topics near the same timestamp, use the nearest distinct timestamp for each.

SELF-CHECK: Before returning, verify (a) every question has "timestamp_seconds" set (not omitted, not null when timestamps exist in the content), (b) no two questions share the same timestamp_seconds value.

If the content contains NO timestamps at all, set "timestamp_seconds" to null for all questions.

CORRECT ANSWER PLACEMENT: Always place the factually correct option at index 0 (the first element of your options array) and set "correctAnswer": 0 for every question. The server will shuffle the options into random positions automatically. Do NOT try to vary correctAnswer values yourself — doing so causes the wrong option to be marked correct.');
        $budget = min($isnonenglish ? 18000 : 15000, max(9000, $count * ($isnonenglish ? 1300 : 900)));
        $parsed = self::safe_parse($systemprompt, $prompt, (int) $budget, 'document (' . self::len($pdftext) . ' chars)');
        $validated = self::finish_set(self::validate_set($parsed, 'document'), $count);
        return self::fill_timestamps($validated, self::transcript_segments($pdftext));
    }

    /**
     * Shared tail of the two set writers: extract, fix, shuffle, cut to size, repair.
     *
     * @param array $valid
     * @param mixed $count
     * @return array
     */
    protected static function finish_set(array $valid, $count): array {
        $processed = [];
        foreach ($valid as $q) {
            $processed[] = self::shuffle(self::fix_explanation_order(self::extract_embedded($q)));
        }
        return self::repair(self::slice_list($processed, 0, $count));
    }

    /**
     * Four options for a teacher's own question.
     *
     * @param string $question
     * @param string $context
     * @param mixed $extrainstructions
     * @param mixed $country
     * @param string $language
     * @return array
     */
    protected static function options_for_question(string $question, string $context, $extrainstructions, $country,
            string $language): array {
        $spellinginstructions = self::spelling($country);
        $languagename = self::language_name($language);
        $isnonenglish = strpos($language, 'en-') !== 0;
        $languageinstruction = (self::js_truthy($isnonenglish) ? ('

IMPORTANT: Generate ALL content (options and explanations) in ' . self::js_str($languagename) . '. The entire response must be in ' . self::js_str($languagename) . ', not English.') : '');
        $australianlegislationguardrail = (self::js_truthy(self::js_or(self::js_or(($country === 'Australia'), self::js_includes($context, 'Australia')), self::js_includes($context, 'AU'))) ? ('
NOTE: If (and ONLY if) the question involves workplace health and safety, use current Australian terminology: "WHS Act" not "OH&S Act", "PCBU" not "Occupier". Do NOT introduce WHS/safety content unless the question is about it.
') : '');
        $extrainstructionssection = (self::js_truthy(self::js_and($extrainstructions, self::jtrim(self::js_str($extrainstructions)))) ? ('=== MANDATORY TEACHER INSTRUCTIONS ===
The teacher has provided the following MANDATORY instructions that you MUST follow.
These instructions affect BOTH the content AND style of the answer options you generate.
You MUST incorporate these instructions - failure to follow them is unacceptable.

TEACHER INSTRUCTIONS:
' . self::js_str(self::jtrim(self::js_str($extrainstructions))) . '
=== END MANDATORY INSTRUCTIONS ===

') : '');
        $systemprompt = ('You are a senior psychometrician who designs answer option sets for assessment items. Your distractors consistently achieve selection rates above 5% each, proving they exploit real cognitive errors rather than being obvious filler.

CORE PRINCIPLES:
1. The correct option must be unambiguously correct and defensible.
2. Every distractor must exploit a specific, named cognitive error.
3. All 4 options must be homogeneous: same grammar, similar length, same specificity.
4. Explanations diagnose the specific thinking error and teach the correct mental model.
5. Options must be directly relevant to the question - never introduce unrelated subjects.

ANTI-PATTERNS TO AVOID:
- "All of the above" / "None of the above"
- Absolute qualifiers ("always", "never") that signal the wrong answer
- Correct answer noticeably longer or more detailed than distractors
- Options that overlap or are subsets of each other

BANNED WORDS - NEVER use these in any option or explanation:
"delve", "dive", "unpack", "explore", "journey", "landscape", "leverage", "utilize", "utilise", "foster", "holistic", "robust", "synergy", "paradigm", "stakeholder engagement"
Use plain, direct language instead.

Return only valid JSON.');
        $prompt = (self::js_str($extrainstructionssection) . 'Generate 4 production-quality answer options for this question:

QUESTION: ' . self::js_str($question) . '
' . self::js_str((self::js_truthy($context) ? ('BACKGROUND CONTEXT: ' . self::js_str($context) . '
- Education Level: calibrate vocabulary complexity and answer sophistication to match
- Industry/Country: use for spelling conventions only
- Do NOT change the subject away from the question') : '')) . self::js_str($spellinginstructions) . self::js_str($languageinstruction) . '
' . self::js_str($australianlegislationguardrail) . '

BLOOM\'S LEVEL 3 "APPLY" REQUIREMENT:
- The answer options must frame the question at Bloom\'s Level 3 (Apply)
- Options should represent different ACTIONS, APPROACHES, or DECISIONS - not definitions or explanations
- The correct answer must be the right thing to DO or APPLY in the given situation
- If the question stem is a pure recall question, rewrite it slightly to frame it as application

REQUIREMENTS:
1. ONE correct answer that directly and unambiguously answers the question
2. THREE plausible distractors, each exploiting a different cognitive error:
   a) PARTIAL KNOWLEDGE or CONCEPT CONFUSION
   b) OVERGENERALISATION or SURFACE-LEVEL REASONING  
   c) A common real-world mistake or misconception

ANSWER CLARITY - ZERO AMBIGUITY:
- The correct answer must be OBVIOUSLY correct to someone who knows the material - no reasonable argument for any other option
- Each wrong answer must be CLEARLY wrong for a specific reason - not "partially correct" or "almost right"
- A learner should NEVER be torn between two options - if two seem plausible, the options need redesigning
- Each of the 4 options must address a DIFFERENT aspect or approach - never two variations of the same idea

OPTION HOMOGENEITY:
- All 4 options: same part of speech, same grammatical structure
- All 4 options: within 20% of each other in word count
- All 4 options: same level of specificity (all general or all specific)

EXPLANATION QUALITY - DIAGNOSTIC FEEDBACK (STRICTLY ENFORCED):
For CORRECT answer - MUST start with "Correct.":
"Correct. [Name the principle/rule that makes this right]. This matters because [practical significance]."

For INCORRECT answers - MUST start with "Incorrect." and MUST include ALL THREE parts:
PART 1: "Incorrect. Choosing this suggests [NAME: \'confusing X with Y\' | \'overgeneralising the rule about Z\' | \'partial application of W\']."
PART 2: "This is wrong because [specific factual reason]."
PART 3: "The key distinction is [what the learner should remember]."

MANDATORY RULES:
- Every incorrect explanation MUST contain "suggests" or "reflects" followed by a named cognitive error
- Every incorrect explanation MUST contain "This is wrong because"
- Every incorrect explanation MUST contain "The key distinction is" or "Remember:" or "The correct principle is"
- NEVER write vague feedback like "This is not the best answer"
- NEVER reference options by letter (A, B, C, D) or position - options are shuffled

SELF-CHECK:
- Could a knowledgeable person confidently eliminate all 3 wrong answers? If not, redesign
- Are any two options confusingly similar? If so, make them more distinct
- Does the correct answer require APPLYING knowledge, not just recalling it?
- Do all incorrect explanations follow the 3-part structure with named cognitive errors?

Return JSON:
{
  "question": "' . self::js_str($question) . '",
  "options": [
    {"text": "Option text (8-15 words)", "explanation": "Correct. [Principle]. This matters because [significance]."},
    {"text": "Option text (8-15 words)", "explanation": "Incorrect. Choosing this suggests [confusing X with Y]. This is wrong because [reason]. The key distinction is [learning point]."},
    {"text": "Option text (8-15 words)", "explanation": "Incorrect. This reflects [overgeneralising Z]. This is wrong because [correct understanding]. Remember: [takeaway]."},
    {"text": "Option text (8-15 words)", "explanation": "Incorrect. Choosing this suggests [partial application of W]. This is wrong because [reason]. The key distinction is [correction]."}
  ],
  "correctAnswer": 0,
  "bloomLevel": "apply",
  "cognitiveErrorsTested": "List the 3 cognitive errors the distractors exploit"
}

CORRECT ANSWER PLACEMENT: Always place the correct answer at index 0 (first in the options array) and set "correctAnswer": 0. The server will shuffle the position automatically.');
        $parsed = self::safe_parse($systemprompt, $prompt, 2000, 'user-question "' . self::slice($question, 0, 50) . '"');
        $valid = self::validate_single($parsed, 'user-question');
        $shuffled = self::shuffle(self::fix_explanation_order(self::extract_embedded($valid)));
        return self::repair([$shuffled])[0];
    }

    // Parsing and checking the AI's answer.

    /**
     * One AI call through the hook. A provider failure is an error the service code catches like any other.
     *
     * @param string $system
     * @param string $user
     * @param int|null $maxtokens
     * @param bool $json
     * @param float|null $temperature
     * @return string
     */
    protected static function call(string $system, string $user, ?int $maxtokens, bool $json, ?float $temperature): string {
        try {
            return (string) (self::$aicall)($system, $user, ['maxtokens' => $maxtokens, 'json' => $json,
                'temperature' => $temperature]);
        } catch (js_error $e) {
            throw $e;
        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'byok_notavailable') {
                throw $e;
            }
            self::$providererror = \core_text::substr(clean_param($e->getMessage(), PARAM_TEXT), 0, 300);
            throw new js_error(self::$providererror);
        }
    }

    /**
     * Call the AI and read JSON, with one retry: safeParseAIResponse.
     *
     * @param string $system
     * @param string $user
     * @param int $budget
     * @param string $label
     * @return mixed
     */
    protected static function safe_parse(string $system, string $user, int $budget, string $label) {
        $last = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $trimmed = self::jtrim(self::call($system, $user, $budget, true, 0.7));
                try {
                    return self::parse($trimmed);
                } catch (js_error $e) {
                    if (preg_match('/\{[\s\S]*\}/', $trimmed, $m)) {
                        return self::parse($m[0]);
                    }
                    throw $e;
                }
            } catch (js_error $e) {
                $last = $e;
            }
        }
        throw new js_error($label . ': Failed to get valid JSON after 2 attempts. Last error: '
            . ($last ? $last->getMessage() : 'undefined'));
    }

    /**
     * validateQuestionSetStructure.
     *
     * @param mixed $parsed
     * @param string $label
     * @return array
     */
    protected static function validate_set($parsed, string $label): array {
        if (!self::js_truthy($parsed) || !self::is_array_js(self::prop($parsed, 'questions'))) {
            throw new js_error($label . ": AI response missing 'questions' array");
        }
        $questions = self::prop($parsed, 'questions');
        if (!$questions) {
            throw new js_error($label . ': AI returned empty questions array');
        }
        $valid = [];
        foreach ($questions as $q) {
            $text = self::prop($q, 'question');
            if (!self::js_truthy($text) || !is_string($text)) {
                continue;
            }
            $options = self::prop($q, 'options');
            if (!self::js_truthy($options) || !self::is_array_js($options) || count($options) < 4) {
                continue;
            }
            if (!self::has($q, 'correctAnswer') || self::js_lt($q['correctAnswer'], 0) || self::js_gt($q['correctAnswer'], 3)) {
                $q['correctAnswer'] = 0;
            }
            $valid[] = $q;
        }
        if (!$valid) {
            throw new js_error($label . ': No valid questions in AI response');
        }
        return $valid;
    }

    /**
     * validateSingleQuestionStructure.
     *
     * @param mixed $parsed
     * @param string $label
     * @return array
     */
    protected static function validate_single($parsed, string $label) {
        if (!self::js_truthy($parsed)) {
            throw new js_error($label . ': AI returned empty response');
        }
        $questions = self::prop($parsed, 'questions');
        if (self::js_truthy($questions) && self::is_array_js($questions) && count($questions) > 0) {
            $parsed = $questions[0];
        }
        self::prop($parsed, 'question');
        $options = self::prop($parsed, 'options');
        if (!self::js_truthy($options) || !self::is_array_js($options) || count($options) < 4) {
            $n = self::js_truthy($options) ? self::prop($options, 'length') : null;
            throw new js_error($label . ': AI response has ' . self::js_str(self::js_or($n, 0)) . ' options (need 4)');
        }
        if (!self::has($parsed, 'correctAnswer') || self::js_lt($parsed['correctAnswer'], 0)
                || self::js_gt($parsed['correctAnswer'], 3)) {
            if (!is_array($parsed)) {
                throw js_error::type('Cannot create property correctAnswer');
            }
            $parsed['correctAnswer'] = 0;
        }
        return $parsed;
    }

    /**
     * extractEmbeddedFormat: options given as {text, explanation} become two parallel lists.
     *
     * @param mixed $question
     * @return mixed
     */
    protected static function extract_embedded($question) {
        $options = self::prop($question, 'options');
        if (!self::js_truthy($options) || self::prop($options, 'length') === 0) {
            return $question;
        }
        $first = self::elem($options, 0);
        if (self::is_object_js($first) && !self::is_array_js($first) && self::has($first, 'text')) {
            $texts = [];
            $explanations = [];
            foreach ($options as $o) {
                $texts[] = self::js_or(self::prop($o, 'text', true), '');
            }
            foreach ($options as $o) {
                $explanations[] = self::js_or(self::prop($o, 'explanation', true), '');
            }
            $question['options'] = $texts;
            $question['explanations'] = $explanations;
        } else if ($first === null && is_array($options) && array_key_exists(0, $options)) {
            throw js_error::type("Cannot read properties of null (reading 'text')");
        }
        return $question;
    }

    /**
     * fixExplanationOrder: the "Correct." explanation goes with the correct option.
     *
     * @param mixed $question
     * @return mixed
     */
    protected static function fix_explanation_order($question) {
        $options = self::prop($question, 'options');
        $correct = self::prop($question, 'correctAnswer');
        $explanations = self::prop($question, 'explanations');
        if (!self::js_truthy($explanations) || !self::js_truthy($options) || self::prop($explanations, 'length') !== 4
                || self::prop($options, 'length') !== 4) {
            return $question;
        }
        $fixed = self::spread($explanations);
        $starts = function($e): bool {
            if ($e === null) {
                return false;
            }
            if (!is_string($e)) {
                throw js_error::type('e?.trim is not a function');
            }
            return strpos(self::lower(self::jtrim($e)), 'correct') === 0;
        };
        if (!$starts(self::index_get($fixed, $correct))) {
            $found = -1;
            foreach ($fixed as $i => $e) {
                if ($starts($e)) {
                    $found = $i;
                    break;
                }
            }
            if ($found !== -1) {
                $a = self::index_get($fixed, $correct);
                $b = $fixed[$found];
                self::index_set($fixed, $correct, $b);
                $fixed[$found] = $a;
            }
        }
        for ($i = 0; $i < 4; $i++) {
            if (self::strict_eq($i, $correct)) {
                continue;
            }
            $expl = self::js_or($fixed[$i] ?? null, '');
            if (!is_string($expl)) {
                throw js_error::type('expl.trim is not a function');
            }
            if (strpos(self::lower(self::jtrim($expl)), 'correct') === 0) {
                $body = self::jtrim(preg_replace('/^correct[.:!]?[' . self::WS . ']*/iu', '', $expl, 1));
                $fixed[$i] = 'Incorrect. ' . self::js_str(self::js_or($body, 'This is not the best answer for this question.'));
            }
        }
        $changed = false;
        foreach ($fixed as $i => $e) {
            if (!self::strict_eq($e, self::elem($explanations, $i))) {
                $changed = true;
            }
        }
        if (!$changed) {
            return $question;
        }
        $question['explanations'] = self::clean_list($fixed);
        return $question;
    }

    /**
     * shuffleQuestionAnswers: options and explanations move together; the correct index follows.
     *
     * @param mixed $question
     * @return array
     */
    protected static function shuffle($question): array {
        if (!is_array($question)) {
            throw js_error::type('Cannot destructure question');
        }
        $options = $question['options'] ?? null;
        $correct = $question['correctAnswer'] ?? null;
        $explanations = $question['explanations'] ?? null;
        $rest = $question;
        unset($rest['options'], $rest['correctAnswer'], $rest['explanations']);
        $indices = [0, 1, 2, 3];
        for ($i = 3; $i > 0; $i--) {
            $j = (int) floor(self::random() * ($i + 1));
            [$indices[$i], $indices[$j]] = [$indices[$j], $indices[$i]];
        }
        $newoptions = [];
        $newexplanations = [];
        foreach ($indices as $i) {
            $newoptions[] = self::elem($options, $i, true);
        }
        foreach ($indices as $i) {
            $newexplanations[] = self::elem($explanations, $i, true);
        }
        $newcorrect = -1;
        foreach ($indices as $pos => $i) {
            if (self::strict_eq($i, $correct)) {
                $newcorrect = $pos;
                break;
            }
        }
        return $rest + ['options' => self::clean_list($newoptions), 'correctAnswer' => $newcorrect,
            'explanations' => self::clean_list($newexplanations)];
    }

    /**
     * validateAndRepairQuestions: swap out banned words, strip full stops from options.
     *
     * @param array $questions
     * @return array
     */
    protected static function repair(array $questions): array {
        $out = [];
        foreach ($questions as $q) {
            $options = self::prop($q, 'options');
            if (!self::js_truthy($options) || self::prop($options, 'length') !== 4) {
                $out[] = $q;
                continue;
            }
            foreach ($options as $o) {
                if (self::js_truthy($o) && !is_string($o)) {
                    throw js_error::type('(o || "").split is not a function');
                }
            }
            $explanations = self::prop($q, 'explanations');
            if (self::js_truthy($explanations) && self::prop($explanations, 'length') === 4) {
                foreach (self::spread($explanations) as $e) {
                    if (self::js_truthy($e) && !is_string($e)) {
                        throw js_error::type('(expl || "").trim is not a function');
                    }
                }
            }
            if (self::has($q, 'question')) {
                $q['question'] = self::replace_banned($q['question']);
            }
            if (self::js_truthy($options)) {
                $q['options'] = array_map(function($opt) {
                    $text = self::replace_banned($opt);
                    if (!is_string($text)) {
                        throw js_error::type('replaceBanned(...).replace is not a function');
                    }
                    return self::jtrim(preg_replace('/\.[' . self::WS . ']*\z/u', '', $text, 1));
                }, $options);
            }
            if (self::js_truthy($explanations)) {
                if (!self::is_array_js($explanations)) {
                    throw js_error::type('q.explanations.map is not a function');
                }
                $q['explanations'] = array_map([self::class, 'replace_banned'], $explanations);
            }
            $out[] = $q;
        }
        return $out;
    }

    /**
     * The service's banned-word swap.
     *
     * @param mixed $text
     * @return mixed
     */
    protected static function replace_banned($text) {
        if (!self::js_truthy($text)) {
            return $text;
        }
        if (!is_string($text)) {
            throw js_error::type('text.replace is not a function');
        }
        $replacements = ['delve' => 'examine', 'dive' => 'look', 'unpack' => 'explain', 'explore' => 'examine',
            'journey' => 'process', 'landscape' => 'environment', 'leverage' => 'use', 'utilize' => 'use',
            'utilise' => 'use', 'foster' => 'support', 'holistic' => 'complete', 'robust' => 'strong',
            'synergy' => 'cooperation', 'paradigm' => 'approach', 'navigate' => 'manage', 'realm' => 'area',
            'tapestry' => 'mix', 'multifaceted' => 'complex', 'nuanced' => 'detailed', 'pivotal' => 'important',
            'cutting-edge' => 'modern', 'game-changer' => 'improvement', 'empower' => 'enable', 'streamline' => 'simplify',
            'best practice' => 'proven method', 'best practices' => 'proven methods',
            'key considerations' => 'main points', 'in today\'s workplace' => 'at work',
            'in the modern workplace' => 'at work', 'ensuring' => 'making sure', 'critical' => 'important'];
        $cleaned = preg_replace_callback('/\b(delve|dive|unpack|explore|journey|landscape|leverage|utilize|utilise|foster|'
            . 'holistic|robust|synergy|paradigm|navigate|realm|tapestry|multifaceted|nuanced|pivotal|cutting-edge|game-changer|'
            . 'empower|streamline|best practice|best practices|key considerations|in today\'s workplace|in the modern workplace|'
            . 'ensuring|critical)\b/i', function($m) use ($replacements) {
                return $replacements[strtolower($m[0])] ?? 'address';
            }, $text);
        return preg_replace('/[' . self::WS . ']{2,}/u', ' ', $cleaned);
    }

    // Whole-job passes.

    /**
     * deduplicateQuestions: drop a question whose first 80 normalised characters repeat an earlier one.
     *
     * @param array $questions
     * @return array
     */
    protected static function dedupe(array $questions): array {
        if (count($questions) <= 1) {
            return $questions;
        }
        $seen = [];
        $out = [];
        foreach ($questions as $q) {
            $text = self::js_or(self::prop($q, 'question'), '');
            if (!is_string($text)) {
                throw js_error::type('toLowerCase is not a function');
            }
            $norm = preg_replace('/[^a-z0-9' . self::WS . ']/u', '', self::lower($text));
            $norm = self::jtrim(preg_replace('/[' . self::WS . ']+/u', ' ', $norm));
            $key = self::slice($norm, 0, 80);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $q;
        }
        return $out;
    }

    /**
     * deduplicateScenarioNames: a name already used in an earlier question is swapped for an unused one.
     *
     * @param array $questions
     * @return array
     */
    protected static function dedupe_names(array $questions): array {
        if (count($questions) <= 1) {
            return $questions;
        }
        $pool = ['Marcus', 'Zara', 'Daniel', 'Priya', 'James', 'Leila', 'Tom', 'Amara', 'Wei', 'Sofia', 'Raj', 'Fatima', 'Chris',
            'Ngozi', 'Ben', 'Yuki', 'Alex', 'Kezia', 'Jordan', 'Hana', 'Tyler', 'Nadia', 'Sam', 'Rosa', 'Kevin', 'Ayasha', 'Luke',
            'Bianca', 'Omar', 'Chloe', 'Finn', 'Mei', 'Aaron', 'Layla', 'Ethan', 'Dani', 'Jack', 'Maya', 'Ryan', 'Sasha', 'Mia',
            'Leo', 'Isla', 'Hassan', 'Freya', 'Kofi', 'Tara', 'Ravi', 'Nina', 'Patrick', 'Yuna', 'Drew', 'Lena', 'Idris',
            'Phoebe', 'Cruz'];
        $available = $pool;
        $seen = [];
        foreach ($questions as $qi => $q) {
            $json = self::json($q);
            $found = [];
            foreach ($pool as $name) {
                if (preg_match('/\b' . $name . '\b/i', $json)) {
                    $found[] = $name;
                }
            }
            foreach ($found as $raw) {
                $key = strtolower($raw);
                if (!isset($seen[$key])) {
                    $seen[$key] = $qi;
                    $at = array_search($key, array_map('strtolower', $available), true);
                    if ($at !== false) {
                        array_splice($available, $at, 1);
                    }
                    continue;
                }
                $replacement = null;
                foreach ($available as $n) {
                    if (!isset($seen[strtolower($n)])) {
                        $replacement = $n;
                        break;
                    }
                }
                if ($replacement === null) {
                    continue;
                }
                $re = '/\b' . $raw . '\b/';
                $swap = function($v) use ($re, $replacement) {
                    return is_string($v) ? preg_replace($re, $replacement, $v) : $v;
                };
                if (is_array($q)) {
                    foreach (['question', 'questionText', 'stem', 'explanation', 'generalFeedback', 'blankSentence',
                            'sentenceWithGaps', 'clozeText', 'scenario'] as $field) {
                        if (isset($q[$field]) && is_string($q[$field])) {
                            $q[$field] = $swap($q[$field]);
                        }
                    }
                    if (isset($q['options']) && self::is_array_js($q['options'])) {
                        $q['options'] = array_map(function($opt) use ($swap) {
                            if (is_string($opt)) {
                                return $swap($opt);
                            }
                            if ($opt === null) {
                                throw js_error::type("Cannot read properties of null (reading 'text')");
                            }
                            $new = self::is_object_js($opt) ? (array) $opt : [];
                            foreach (['text', 'explanation', 'feedback'] as $k) {
                                if (array_key_exists($k, $new)) {
                                    $new[$k] = $swap($new[$k]);
                                }
                            }
                            return $new ?: new \stdClass();
                        }, $q['options']);
                    }
                    if (isset($q['explanations']) && self::is_array_js($q['explanations'])) {
                        $q['explanations'] = array_map($swap, $q['explanations']);
                    }
                    $questions[$qi] = $q;
                }
                $seen[strtolower($replacement)] = $qi;
                $at = array_search($replacement, $available, true);
                if ($at !== false) {
                    array_splice($available, $at, 1);
                }
            }
        }
        return array_values($questions);
    }

    /**
     * redistributeCorrectAnswers: balance where the correct option sits across the whole set.
     *
     * @param array $questions
     * @return array
     */
    protected static function redistribute(array $questions): array {
        if (count($questions) < 4) {
            return $questions;
        }
        $position = function($q): int {
            $c = self::prop($q, 'correctAnswer');
            return ((is_int($c) || is_float($c)) && $c >= 0 && $c <= 3) ? (int) $c : 0;
        };
        $distribution = [0, 0, 0, 0];
        foreach ($questions as $q) {
            $p = $position($q);
            // A fractional index (1.5) counts nowhere, as distribution[1.5]++ does.
            $c = self::prop($q, 'correctAnswer');
            if (is_float($c) && floor($c) != $c && $c >= 0 && $c <= 3) {
                continue;
            }
            $distribution[$p]++;
        }
        $n = count($questions);
        $base = intdiv($n, 4);
        $target = [$base, $base, $base, $base];
        for ($i = 0; $i < $n % 4; $i++) {
            $target[$i]++;
        }
        $toomany = [];
        $toofew = [];
        for ($pos = 0; $pos < 4; $pos++) {
            $delta = $distribution[$pos] - $target[$pos];
            for ($j = 0; $j < abs($delta); $j++) {
                if ($delta > 0) {
                    $toomany[] = $pos;
                } else {
                    $toofew[] = $pos;
                }
            }
        }
        if (!$toomany) {
            return $questions;
        }
        $reassigned = [];
        for ($k = 0; $k < count($toomany) && $k < count($toofew); $k++) {
            $from = $toomany[$k];
            $to = $toofew[$k];
            $index = -1;
            foreach ($questions as $i => $q) {
                if (!isset($reassigned[$i]) && self::strict_eq(self::prop($q, 'correctAnswer'), $from)) {
                    $index = $i;
                    break;
                }
            }
            if ($index === -1) {
                continue;
            }
            $q = $questions[$index];
            $options = self::prop($q, 'options');
            $explanations = self::prop($q, 'explanations');
            if (!self::js_truthy($options) || !self::js_truthy($explanations) || self::prop($options, 'length') !== 4
                    || self::prop($explanations, 'length') !== 4) {
                continue;
            }
            $correct = $q['correctAnswer'];
            $hasaudio = array_key_exists('audioData', $q);
            $audio = $q['audioData'] ?? null;
            $rest = $q;
            unset($rest['options'], $rest['correctAnswer'], $rest['explanations'], $rest['audioData']);
            $others = array_values(array_filter([0, 1, 2, 3], function($i) use ($correct) {
                return !self::strict_eq($i, $correct);
            }));
            for ($i = count($others) - 1; $i > 0; $i--) {
                $j = (int) floor(self::random() * ($i + 1));
                [$others[$i], $others[$j]] = [$others[$j], $others[$i]];
            }
            array_splice($others, $to, 0, [$correct]);
            $pick = function($list) use ($others) {
                return array_map(function($i) use ($list) {
                    return self::elem($list, $i, true);
                }, $others);
            };
            $new = $rest + ['options' => self::clean_list($pick($options)),
                'explanations' => self::clean_list($pick($explanations))];
            if ($hasaudio) {
                $new['audioData'] = (self::is_array_js($audio) && count($audio) === 4) ? self::clean_list($pick($audio)) : $audio;
            }
            $new['correctAnswer'] = $to;
            $questions[$index] = $new;
            $reassigned[$index] = true;
        }
        return $questions;
    }

    // Timestamps for transcripts.

    /**
     * parseTranscriptSegments: "0:08 text" lines, or inline "0:01 text 0:06 text".
     *
     * @param string $text
     * @return array List of [seconds, text].
     */
    protected static function transcript_segments(string $text): array {
        $a = [];
        $current = -1;
        $parts = [];
        $flush = function() use (&$a, &$current, &$parts) {
            if ($current >= 0) {
                $a[] = [$current, self::jtrim(implode(' ', $parts))];
                $parts = [];
            }
        };
        foreach (explode("\n", $text) as $line) {
            $trimmed = self::jtrim($line);
            if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?[' . self::WS . ']*(.*)/u', $trimmed, $m)) {
                $flush();
                $current = (isset($m[3]) && $m[3] !== '') ? (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3]
                    : (int) $m[1] * 60 + (int) $m[2];
                if (($m[4] ?? '') !== '') {
                    $parts[] = $m[4];
                }
            } else if ($current >= 0 && self::len($trimmed) > 0) {
                $parts[] = $trimmed;
            }
        }
        $flush();
        $b = [];
        preg_match_all('/\b(\d{1,2}):(\d{2})(?::(\d{2}))?\b/', $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $last = -1;
        $lastindex = 0;
        foreach ($all as $m) {
            $seconds = (isset($m[3]) && $m[3][1] >= 0) ? (int) $m[1][0] * 3600 + (int) $m[2][0] * 60 + (int) $m[3][0]
                : (int) $m[1][0] * 60 + (int) $m[2][0];
            if ($last >= 0) {
                $seg = self::jtrim(substr($text, $lastindex, $m[0][1] - $lastindex));
                if ($seg !== '') {
                    $b[] = [$last, $seg];
                }
            }
            $last = $seconds;
            $lastindex = $m[0][1] + strlen($m[0][0]);
        }
        if ($last >= 0) {
            $seg = self::jtrim(substr($text, $lastindex));
            if ($seg !== '') {
                $b[] = [$last, $seg];
            }
        }
        if (count($b) > count($a)) {
            return $b;
        }
        return $a;
    }

    /**
     * findBestTimestampForQuestion.
     *
     * @param mixed $question
     * @param array $options Option texts.
     * @param array $segments
     * @return int|null
     */
    protected static function best_timestamp($question, array $options, array $segments): ?int {
        if (!$segments) {
            return null;
        }
        $texts = array_map(function($o) {
            return is_string($o) ? $o : self::js_or(self::is_object_js($o) ? self::prop($o, 'text') : null, '');
        }, $options);
        $all = self::lower(implode(' ', array_map(function($v) {
            return $v === null ? '' : self::js_str($v);
        }, array_merge([$question], $texts))));
        $stop = array_flip(['that', 'this', 'with', 'from', 'have', 'been', 'when', 'what', 'which', 'they', 'their', 'will',
            'would', 'could', 'should', 'about', 'into', 'than', 'more', 'also', 'both', 'each', 'very', 'just', 'then', 'there',
            'some', 'such', 'only', 'most', 'over', 'other', 'after', 'before', 'does', 'your', 'these', 'those']);
        $words = array_values(array_filter(preg_split('/\W+/', $all), function($w) use ($stop) {
            return self::len($w) > 3 && !isset($stop[$w]);
        }));
        if (!$words) {
            return $segments[0][0];
        }
        $best = -1;
        $bestseconds = null;
        foreach ($segments as $i => $segment) {
            $window = self::lower(implode(' ', array_map(function($s) {
                return $s[1];
            }, array_slice($segments, $i, 5))));
            $count = 0;
            foreach ($words as $w) {
                if (strpos($window, $w) !== false) {
                    $count++;
                }
            }
            $score = $count / count($words);
            if ($score > $best) {
                $best = $score;
                $bestseconds = $segment[0];
            }
        }
        return $best >= 0.1 ? $bestseconds : null;
    }

    /**
     * Give questions without a timestamp one: best keyword match, then spread over the timeline.
     *
     * @param array $questions
     * @param array $segments
     * @return array
     */
    protected static function fill_timestamps(array $questions, array $segments): array {
        if (!$segments) {
            return $questions;
        }
        foreach ($questions as $i => $q) {
            if (self::prop($q, 'timestamp_seconds') === null) {
                $texts = array_map(function($o) {
                    return is_string($o) ? $o : self::js_or(self::is_object_js($o) ? self::prop($o, 'text') : null, '');
                }, self::list_of(self::js_or(self::prop($q, 'options'), [])));
                $best = self::best_timestamp(self::js_or(self::prop($q, 'question'), ''), $texts, $segments);
                if ($best !== null) {
                    $questions[$i]['timestamp_seconds'] = $best;
                }
            }
        }
        $stillnull = [];
        $claimed = [];
        foreach ($questions as $i => $q) {
            $ts = $q['timestamp_seconds'] ?? null;
            if ($ts === null) {
                $stillnull[] = $i;
            } else {
                $claimed[self::key_of($ts)] = true;
            }
        }
        $count = count($stillnull);
        foreach ($stillnull as $idx => $i) {
            $frac = $count > 1 ? $idx / ($count - 1) : 0.5;
            $ideal = self::js_round($frac * (count($segments) - 1));
            $assigned = null;
            for ($delta = 0; $delta < count($segments) && $assigned === null; $delta++) {
                foreach ([$ideal + $delta, $ideal - $delta] as $d) {
                    if ($d >= 0 && $d < count($segments) && !isset($claimed[self::key_of($segments[$d][0])])) {
                        $assigned = $segments[$d][0];
                        break;
                    }
                }
            }
            if ($assigned === null) {
                $assigned = $segments[$ideal][0];
            }
            $questions[$i]['timestamp_seconds'] = $assigned;
            $claimed[self::key_of($assigned)] = true;
        }
        return $questions;
    }

    // The other routes.

    /**
     * Rewrite questions in another language: the regenerate-settings route.
     *
     * @param array $body
     * @return array The route's JSON answer.
     * @throws http_error
     */
    public static function regenerate_settings(array $body): array {
        $questions = $body['questions'] ?? null;
        if (!self::js_truthy($questions) || !self::is_array_js($questions) || count($questions) === 0) {
            throw new http_error(400, 'Questions array is required');
        }
        if (count($questions) > 50) {
            throw new http_error(400, 'Maximum 50 questions allowed per regeneration');
        }
        $voiceoverenabled = $body['voiceoverEnabled'] ?? null;
        $langcode = self::js_or($body['voiceLanguage'] ?? null, 'en-AU');
        $languagename = self::js_or(self::REGEN_LANGUAGES[self::key_string($langcode)] ?? null, 'English (Australian)');
        $explanationprefixinstruction = (self::js_truthy((strpos(self::js_str($langcode), 'en-') === 0)) ? ('- Explanations for the correct answer should start with "Correct!" and explanations for incorrect answers should start with "Incorrect."') : ('- Explanations for the correct answer should start with the ' . self::js_str($languagename) . ' equivalent of "Correct!" and explanations for incorrect answers should start with the ' . self::js_str($languagename) . ' equivalent of "Incorrect."'));
        $questionsjson = self::json_pretty($questions);
        $prompt = ('You are an expert quiz question translator and editor. You are given existing quiz questions and must rewrite them in ' . self::js_str($languagename) . '.

IMPORTANT RULES:
- Translate/rewrite ALL question text, options, and explanations into ' . self::js_str($languagename) . '
- Use native ' . self::js_str($languagename) . ' spelling, grammar, vocabulary, and phrasing
- Keep the same meaning, difficulty level, and correct answer index
- Maintain exactly 4 answer options per question
- Each option must have an explanation
' . self::js_str($explanationprefixinstruction) . '
- Return valid JSON only

INPUT QUESTIONS:
' . self::js_str($questionsjson) . '

Return a JSON array with this exact structure:
[
  {
    "question": "translated question text",
    "options": ["option A", "option B", "option C", "option D"],
    "explanations": ["explanation for A", "explanation for B", "explanation for C", "explanation for D"],
    "correctAnswer": 0
  }
]

Return ONLY the JSON array, no markdown or other text.');
        try {
            $text = self::call('', $prompt, self::REGEN_TOKENS, true, null);
        } catch (js_error $e) {
            throw new http_error(500, 'Failed to regenerate with new settings');
        }
        $cleaned = self::jtrim(preg_replace('/```[' . self::WS . ']*/u', '', preg_replace('/```json[' . self::WS . ']*/u', '', $text)));
        if (strpos($cleaned, '[') !== 0 && preg_match('/\[[\s\S]*\]/', $cleaned, $m)) {
            $cleaned = $m[0];
        }
        try {
            $regenerated = self::parse($cleaned);
        } catch (js_error $e) {
            throw new http_error(500, 'Failed to parse AI response');
        }
        if (!self::is_array_js($regenerated) || count($regenerated) === 0) {
            throw new http_error(500, 'AI returned invalid questions');
        }
        $regenerated = array_slice($regenerated, 0, count($questions));
        foreach ($regenerated as $i => $q) {
            try {
                $bad = !self::js_truthy(self::prop($q, 'question')) || !self::is_array_js(self::prop($q, 'options'))
                    || count($q['options']) !== 4 || !self::is_array_js(self::prop($q, 'explanations'))
                    || count($q['explanations']) !== 4;
            } catch (js_error $e) {
                throw new http_error(500, 'Failed to regenerate with new settings');
            }
            if ($bad) {
                throw new http_error(500, 'AI returned malformed question ' . ($i + 1) . '. Please try again.');
            }
            $original = $questions[$i] ?? null;
            $q['correctAnswer'] = self::is_object_js($original) && isset($original['correctAnswer'])
                ? $original['correctAnswer'] : 0;
            if (self::is_object_js($original) && self::has($original, 'timestamp_seconds')) {
                $q['timestamp_seconds'] = $original['timestamp_seconds'];
            }
            $regenerated[$i] = $q;
        }
        if (self::js_truthy($voiceoverenabled)) {
            $voice = self::voice_config($langcode, self::js_or($body['voiceId'] ?? null, 'Aoede'));
            foreach ($regenerated as $i => $q) {
                try {
                    $regenerated[$i]['audioData'] = array_map(function($e) use ($voice) {
                        return self::narrate_or_blank($e, $voice, false);
                    }, self::list_of(self::js_or($q['explanations'], [])));
                } catch (js_error $e) {
                    throw new http_error(500, 'Failed to regenerate with new settings');
                }
            }
        }
        return ['ok' => true, 'questions' => $regenerated];
    }

    /**
     * Rewrite questions to the teacher's new instructions: the regenerate-instructions route.
     *
     * @param array $body
     * @return array The route's JSON answer.
     * @throws http_error
     */
    public static function regenerate_instructions(array $body): array {
        $questions = $body['questions'] ?? null;
        $sourcecontext = $body['sourceContext'] ?? null;
        $kc = (self::js_truthy($sourcecontext) && self::is_object_js($sourcecontext) && !self::is_array_js($sourcecontext))
            ? (array) $sourcecontext : null;
        $regenworkplaceenabled = $kc !== null && ($kc['workplaceContextEnabled'] ?? null) === true;
        if (!self::js_truthy($questions) || !self::is_array_js($questions) || count($questions) === 0) {
            throw new http_error(400, 'Questions array is required');
        }
        if (count($questions) > 50) {
            throw new http_error(400, 'Maximum 50 questions allowed per regeneration');
        }
        $extrainstructions = $body['extraInstructions'] ?? null;
        $voiceoverenabled = $body['voiceoverEnabled'] ?? null;
        $langcode = self::js_or($body['voiceLanguage'] ?? null, 'en-AU');
        $languagename = self::js_or(self::REGEN_LANGUAGES[self::key_string($langcode)] ?? null, 'English (Australian)');
        try {
            $explanationprefixinstruction = (self::js_truthy((strpos(self::js_str($langcode), 'en-') === 0)) ? ('- Explanations for the correct answer should start with "Correct!" and explanations for incorrect answers should start with "Incorrect."') : ('- Explanations for the correct answer should start with the ' . self::js_str($languagename) . ' equivalent of "Correct!" and explanations for incorrect answers should start with the ' . self::js_str($languagename) . ' equivalent of "Incorrect."'));
            $extrainstructionsblock = (self::js_truthy(self::js_and($extrainstructions, self::jtrim(self::js_str($extrainstructions)))) ? ('

ADDITIONAL INSTRUCTOR INSTRUCTIONS (apply these when regenerating):
' . self::js_str(self::jtrim(self::js_str($extrainstructions)))) : '');
            $sourceoftruthblock = self::source_of_truth($kc);
            $questionsjson = self::json_pretty($questions);
            $prompt = ('You are a senior psychometrician and expert quiz question editor. You are given existing quiz questions and must regenerate/improve them based on instructor instructions while enforcing world-class assessment design principles.' . self::js_str($sourceoftruthblock) . '

LANGUAGE RULES:
- All content must be in ' . self::js_str($languagename) . '
- Use native ' . self::js_str($languagename) . ' spelling, grammar, vocabulary, and phrasing
' . self::js_str($explanationprefixinstruction) . '

' . self::js_str((self::js_truthy($regenworkplaceenabled) ? ('QUESTION STYLE — WORKPLACE CONTEXT ENABLED:
- Questions may use brief (1–2 sentence) workplace scenario framing when it genuinely helps test the concept
- Keep question stems SHORT: 1–2 sentences maximum — avoid long narrative setups
- Mix scenario-based and direct application questions in roughly equal proportion
- Do not pad questions with unnecessary workplace backstory') : ('QUESTION STYLE — WORKPLACE CONTEXT IS DISABLED — NO SCENARIOS (ABSOLUTE):
- ZERO scenario-based questions. The teacher has NOT enabled "Add Workplace Context".
- ALL questions MUST be direct application questions: state a principle, rule, or concept and ask what the correct action/answer is
- NO named persons, NO named companies, NO workplace narratives, NO situational framing of any kind
- WRONG: "A small landscaping company is reviewing its safety procedures. Which approach..."
- RIGHT: "Which approach should be used to minimise the risk of harm from psychosocial hazards?"
- Keep question stems SHORT: 1–2 sentences maximum'))) . '

COGNITIVE LEVEL:
- Questions should require the learner to understand or apply knowledge — not pure rote recall
- Avoid: "What is the definition of..." or "Name the three types of..."

ANSWER CLARITY - ZERO AMBIGUITY (CRITICAL):
- The correct answer must be OBVIOUSLY correct to someone who has studied the material - no reasonable argument for any other option
- Each wrong answer must be CLEARLY wrong for a specific, identifiable reason - not "partially correct" or "almost right"
- A learner should NEVER be torn between two options - if two seem plausible, redesign them
- Each of the 4 options must address a DIFFERENT aspect or approach - never two variations of the same idea
- Test mentally: "Could a knowledgeable person confidently eliminate 3 wrong answers?" If not, redesign

DISTRACTOR QUALITY:
- Every wrong answer must exploit a specific cognitive error (partial knowledge, overgeneralisation, concept confusion, or surface-level reasoning)
- All 4 options must be homogeneous: same grammar, similar length (within 20%), same specificity level
- No "All of the above" / "None of the above"
- No absolute qualifiers ("always", "never") that signal the wrong answer

EXPLANATION QUALITY:
- For correct: "Correct. [Why right - name the principle]. This matters because [significance]."
- For incorrect: "Incorrect. Choosing this suggests [specific cognitive error]. This is wrong because [reason]. The key distinction is [learning point]."
- Each incorrect explanation must name the SPECIFIC thinking error - never generic feedback like "This is not the best answer"
- NEVER reference options by letter (A, B, C, D) or position - options are shuffled

ANTI-PATTERNS TO AVOID:
- Options that overlap or are subsets of each other
- Correct answer noticeably longer or more detailed than distractors
- Negatively phrased stems ("Which is NOT...")
- Starting stems with "According to the document" or similar
- Trivial recall questions that test memorisation alone

SELF-CHECK BEFORE RETURNING:
' . self::js_str((self::js_truthy($regenworkplaceenabled) ? ('- Confirm at least some questions use brief scenario framing — but no question stem exceeds 2 sentences
- Confirm NO question references options by letter (A, B, C, D) or position') : ('- SCENARIO CHECK: scan ALL questions and confirm ZERO contain a named person, named company, or workplace narrative — if ANY scenario or name slipped in, rewrite that question as a direct application question before returning
- Confirm 100% of questions are direct application style with NO situational framing
- Confirm NO question references options by letter (A, B, C, D) or position'))) . '
' . self::js_str($extrainstructionsblock) . '

INPUT QUESTIONS:
' . self::js_str($questionsjson) . '

Return a JSON array with this exact structure:
[
  {
    "question": "' . self::js_str((self::js_truthy($regenworkplaceenabled) ? 'application-focused question stem (scenario or direct)' : 'direct application question stem — NO scenarios, NO named persons')) . '",
    "options": ["option A", "option B", "option C", "option D"],
    "explanations": ["explanation for A", "explanation for B", "explanation for C", "explanation for D"],
    "correctAnswer": 0
  }
]

Return ONLY the JSON array, no markdown or other text.');
        } catch (js_error $e) {
            throw new http_error(500, 'Failed to regenerate with updated instructions');
        }
        try {
            $text = self::call('', $prompt, self::REGEN_TOKENS, true, 0.7);
        } catch (js_error $e) {
            throw new http_error(500, 'Failed to regenerate with updated instructions');
        }
        $stripped = self::jtrim(preg_replace('/```[' . self::WS . ']*/u', '', preg_replace('/```json[' . self::WS . ']*/iu', '', $text)));
        $parsed = null;
        $ok = false;
        try {
            $parsed = self::parse($stripped);
            $ok = true;
        } catch (js_error $e) {
            $ok = false;
        }
        if (!$ok || $parsed === null) {
            $parsed = null;
            if (preg_match('/\[[\s\S]*\]/', $stripped, $m)) {
                try {
                    $parsed = self::parse($m[0]);
                } catch (js_error $e) {
                    $parsed = null;
                }
            }
        }
        if ($parsed === null) {
            $s = strpos($text, '[');
            $e = strrpos($text, ']');
            if ($s !== false && $e !== false && $e > $s) {
                try {
                    $parsed = self::parse(substr($text, $s, $e - $s + 1));
                } catch (js_error $ex) {
                    $parsed = null;
                }
            }
        }
        if ($parsed !== null && !self::is_array_js($parsed)) {
            try {
                $candidate = self::js_or(self::js_or(self::js_or(self::prop($parsed, 'questions'), self::prop($parsed, 'data')),
                    self::prop($parsed, 'results')), self::first_array(self::object_values($parsed)));
            } catch (js_error $e) {
                throw new http_error(500, 'Failed to parse AI response');
            }
            if (self::is_array_js($candidate)) {
                $parsed = $candidate;
            }
        }
        if (!self::is_array_js($parsed) || count($parsed) === 0) {
            throw new http_error(500, 'Failed to parse AI response');
        }
        $regenerated = $parsed;
        foreach ($regenerated as $i => $q) {
            if (!self::js_truthy($q) || !self::is_object_js($q) || self::is_array_js($q) || !self::js_truthy($q['question'] ?? null)) {
                throw new http_error(500, 'AI returned malformed question ' . ($i + 1) . '. Please try again.');
            }
            $q = (array) $q;
            if (!self::is_array_js($q['options'] ?? null)) {
                $q['options'] = [];
            }
            while (count($q['options']) < 4) {
                $q['options'][] = '—';
            }
            $q['options'] = array_slice($q['options'], 0, 4);
            if (!self::is_array_js($q['explanations'] ?? null)) {
                $q['explanations'] = [];
            }
            while (count($q['explanations']) < 4) {
                $q['explanations'][] = 'Incorrect. This option is not the best answer.';
            }
            $q['explanations'] = array_slice($q['explanations'], 0, 4);
            if (is_string($q['correctAnswer'] ?? null)) {
                $q['correctAnswer'] = self::parse_int($q['correctAnswer']);
            }
            $c = $q['correctAnswer'] ?? null;
            if (!(is_int($c) || is_float($c)) || is_nan((float) $c) || $c < 0 || $c > 3) {
                $original = $questions[$i] ?? null;
                $q['correctAnswer'] = (self::is_object_js($original) && isset($original['correctAnswer']))
                    ? $original['correctAnswer'] : 0;
            }
            try {
                $q = self::shuffle(self::fix_explanation_order($q));
            } catch (js_error $e) {
                throw new http_error(500, 'Failed to regenerate with updated instructions');
            }
            $original = $questions[$i] ?? null;
            if (self::is_object_js($original) && isset($original['timestamp_seconds'])) {
                $q['timestamp_seconds'] = $original['timestamp_seconds'];
            }
            $regenerated[$i] = $q;
        }
        if ($kc !== null && self::is_array_js($kc['textSources'] ?? null)) {
            $regenerated = self::fill_timestamps_from($regenerated, $kc['textSources']);
        }
        $nulls = array_filter($regenerated, function($q) {
            return ($q['timestamp_seconds'] ?? null) === null;
        });
        if ($nulls) {
            $top = $body['textSources'] ?? null;
            $regenerated = self::fill_timestamps_from($regenerated, self::is_array_js($top) ? $top : []);
        }
        if (self::js_truthy($voiceoverenabled)) {
            $voice = self::voice_config($langcode, self::js_or($body['voiceId'] ?? null, 'Aoede'));
            foreach ($regenerated as $i => $q) {
                try {
                    $regenerated[$i]['audioData'] = array_map(function($e) use ($voice) {
                        return self::narrate_or_blank($e, $voice, false);
                    }, self::list_of(self::js_or($q['explanations'], [])));
                } catch (js_error $e) {
                    throw new http_error(500, 'Failed to regenerate with updated instructions');
                }
            }
        }
        return ['ok' => true, 'questions' => $regenerated, 'isFree' => true, 'regenerationCount' => 1];
    }

    /**
     * The block of source material the regenerated questions must stay within.
     *
     * @param array|null $kc The saved source context.
     * @return string
     */
    protected static function source_of_truth(?array $kc): string {
        if ($kc === null) {
            return '';
        }
        $parts = [];
        $str = function($v): string {
            return is_string($v) ? self::jtrim($v) : '';
        };
        if (self::is_array_js($kc['textSources'] ?? null) && $kc['textSources']) {
            $valid = [];
            foreach ($kc['textSources'] as $s) {
                if (self::js_truthy($s) && self::is_object_js($s) && !self::is_array_js($s) && is_string($s['text'] ?? null)
                        && self::jtrim($s['text']) !== '') {
                    $valid[] = $s;
                }
            }
            if ($valid) {
                $texts = [];
                foreach ($valid as $i => $s) {
                    $texts[] = '--- Source ' . ($i + 1) . " ---\n" . self::jtrim($s['text']);
                }
                $parts[] = 'TEXT SOURCES (the learner studied these — every regenerated question MUST be answerable from this '
                    . "material and MUST NOT introduce facts not present here):\n\n" . implode("\n\n", $texts);
            }
        }
        if (self::is_array_js($kc['userQuestions'] ?? null) && $kc['userQuestions'] && self::js_truthy($kc['useOwnQuestions'] ?? null)) {
            $valid = array_values(array_filter($kc['userQuestions'], function($q) {
                return is_string($q) && self::jtrim($q) !== '';
            }));
            if ($valid) {
                $lines = [];
                foreach ($valid as $i => $q) {
                    $lines[] = ($i + 1) . '. ' . self::jtrim($q);
                }
                $parts[] = 'INSTRUCTOR-AUTHORED REFERENCE QUESTIONS (these capture the instructor\'s original intent — keep '
                    . "regenerated questions on the SAME topics and at the SAME conceptual depth):\n" . implode("\n", $lines);
            }
        }
        if (self::is_array_js($kc['topics'] ?? null) && $kc['topics']) {
            $valid = array_values(array_filter($kc['topics'], function($t) {
                return is_string($t) && self::jtrim($t) !== '';
            }));
            if ($valid) {
                $parts[] = 'ORIGINAL TOPIC LIST (regenerated questions MUST stay within these topics — do not drift into '
                    . "adjacent or related topics):\n" . implode("\n", array_map(function($t) {
                        return '- ' . self::jtrim($t);
                    }, $valid));
            }
        }
        if (self::js_truthy($kc['workplaceContextEnabled'] ?? null)) {
            $lines = [];
            foreach (['country' => 'Country', 'state' => 'State/Region', 'industry' => 'Industry',
                    'industryDetails' => 'Industry context', 'jobLevel' => 'Job level', 'jobTitle' => 'Job title'] as $k => $label) {
                if ($str($kc[$k] ?? null) !== '') {
                    $lines[] = $label . ': ' . $str($kc[$k]);
                }
            }
            if ($lines) {
                $parts[] = 'WORKPLACE CONTEXT (frame every scenario in this context — use industry-appropriate roles, settings, '
                    . "terminology, and regulatory references where relevant):\n" . implode("\n", $lines);
            }
        }
        $edu = [];
        if ($str($kc['educationType'] ?? null) !== '') {
            $edu[] = 'Education type: ' . $str($kc['educationType']);
        }
        if ($str($kc['vetLevel'] ?? null) !== '') {
            $edu[] = 'VET level: ' . $str($kc['vetLevel'])
                . ' (calibrate question complexity, vocabulary, and scenario depth to this AQF level)';
        }
        if ($str($kc['academicLevel'] ?? null) !== '') {
            $edu[] = 'Academic level: ' . $str($kc['academicLevel'])
                . ' (calibrate question complexity, vocabulary, and scenario depth to this level)';
        }
        if ($edu) {
            $parts[] = "LEARNER LEVEL:\n" . implode("\n", $edu);
        }
        if (!$parts) {
            return '';
        }
        $partsjoined = implode("\n\n", $parts);
        return ('

---------- AUTHORITATIVE SOURCE MATERIAL (HIGHEST PRIORITY) ----------
The following is the ONLY source material the learner studied. Every regenerated question MUST be answerable from this material and MUST stay strictly within these topics. Do NOT introduce facts, scenarios, regulations, or terminology that are not supported by this material. If the existing OLD questions below have drifted away from this source material, correct that drift — the source material wins, the old questions are only a structural reference.

' . self::js_str($partsjoined) . '
---------- END AUTHORITATIVE SOURCE MATERIAL ----------
');
    }

    /**
     * Timestamps for regenerated questions from a list of text sources, when they hold a transcript.
     *
     * @param array $questions
     * @param array $sources
     * @return array
     */
    protected static function fill_timestamps_from(array $questions, array $sources): array {
        $texts = [];
        foreach ($sources as $s) {
            if (self::js_truthy($s) && self::is_object_js($s) && !self::is_array_js($s) && is_string($s['text'] ?? null)
                    && self::jtrim($s['text']) !== '') {
                $texts[] = self::jtrim($s['text']);
            }
        }
        $text = implode("\n\n", $texts);
        if ($text === '') {
            return $questions;
        }
        return self::fill_timestamps($questions, self::transcript_segments($text));
    }

    /**
     * Narrate existing questions again: the regenerate-audio route.
     *
     * @param array $body
     * @return array
     * @throws http_error
     */
    public static function regenerate_audio(array $body): array {
        $questions = $body['questions'] ?? null;
        if (!self::js_truthy($questions) || !self::is_array_js($questions) || count($questions) === 0) {
            throw new http_error(400, 'Questions array is required');
        }
        $voice = self::voice_config(self::js_or($body['voiceLanguage'] ?? null, 'en-AU'),
            self::js_or($body['voiceId'] ?? null, 'Aoede'));
        $out = [];
        try {
            foreach ($questions as $q) {
                $explanations = self::js_truthy(self::prop($q, 'explanations')) ? self::prop($q, 'explanations') : null;
                if ($explanations === null) {
                    $options = self::prop($q, 'options');
                    $explanations = [];
                    for ($i = 0; $i < 4; $i++) {
                        $o = self::js_truthy($options) ? self::elem($options, $i) : null;
                        $explanations[] = self::js_or($o === null ? null : self::prop($o, 'explanation'), '');
                    }
                }
                if (!self::is_array_js($explanations)) {
                    throw js_error::type('explanations.map is not a function');
                }
                $audio = array_map(function($e) use ($voice) {
                    return self::narrate_or_blank($e, $voice, false);
                }, $explanations);
                $out[] = (self::is_object_js($q) ? (array) $q : []) + ['audioData' => null];
                $out[count($out) - 1]['audioData'] = $audio;
            }
        } catch (js_error $e) {
            throw new http_error(500, 'Failed to regenerate audio');
        }
        return ['ok' => true, 'questions' => $out];
    }

    /**
     * One picture: the generate-image route.
     *
     * @param array $body
     * @return array
     * @throws http_error
     */
    public static function image(array $body): array {
        $prompt = $body['prompt'] ?? null;
        if (!self::js_truthy($prompt)) {
            throw new http_error(400, 'siteId, apiKey, and prompt are required');
        }
        if (!is_string($prompt) || self::jtrim($prompt) === '') {
            throw new http_error(500, 'Image generation returned no data');
        }
        try {
            $url = (string) (self::$image)($prompt);
        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'byok_notavailable') {
                throw $e;
            }
            throw new http_error(500, \core_text::substr(clean_param($e->getMessage(), PARAM_TEXT), 0, 300));
        }
        if ($url === '') {
            throw new http_error(500, 'Image generation returned no data');
        }
        return ['ok' => true, 'imageDataUrl' => $url];
    }

    /**
     * Narrate questions the service wrote without voiceover, as a generation narrates them.
     *
     * For a site whose text runs on LMS Labs credits and whose voice runs on its own AI.
     *
     * @param array $questions Questions, as the service returned them.
     * @param mixed $language Voice language code.
     * @param mixed $voiceid Voice name.
     * @return array The questions with audioData.
     */
    public static function narrate(array $questions, $language, $voiceid): array {
        $voice = self::voice_config($language, $voiceid);
        foreach ($questions as $i => $q) {
            if (!is_array($q) || array_is_list($q)) {
                continue;
            }
            $explanations = $q['explanations'] ?? null;
            if (!self::is_array_js($explanations)) {
                $explanations = array_map(function($o) {
                    return is_array($o) ? ($o['explanation'] ?? '') : '';
                }, self::is_array_js($q['options'] ?? null) ? $q['options'] : []);
            }
            self::progress(70 + (int) floor((($i + 1) / count($questions)) * 20), 'Generating voiceover ' . ($i + 1) . '/'
                . count($questions) . '...');
            $q['audioData'] = array_map(function($explanation) use ($voice) {
                return self::narrate_or_blank($explanation, $voice, true);
            }, $explanations);
            $questions[$i] = $q;
        }
        return $questions;
    }

    // Languages, voices, spelling.

    /** Tokens for a whole-set rewrite (the service leaves this to the model's own limit). */
    const REGEN_TOKENS = 16000;

    /** Language names the two regenerate routes use. */
    const REGEN_LANGUAGES = [
        'en-AU' => 'English (Australian)',
        'en-GB' => 'English (British)',
        'en-US' => 'English (American)',
        'en-IN' => 'English (Indian)',
        'es-ES' => 'Spanish (Spain)',
        'es-US' => 'Spanish (US)',
        'fr-CA' => 'French (Canada)',
        'fr-FR' => 'French (France)',
        'de-DE' => 'German',
        'pt-BR' => 'Portuguese (Brazilian)',
        'nl-BE' => 'Dutch (Belgium)',
        'nl-NL' => 'Dutch (Netherlands)',
        'da-DK' => 'Danish',
        'fi-FI' => 'Finnish',
        'nb-NO' => 'Norwegian',
        'sv-SE' => 'Swedish',
        'bg-BG' => 'Bulgarian',
        'cs-CZ' => 'Czech',
        'hr-HR' => 'Croatian',
        'hu-HU' => 'Hungarian',
        'pl-PL' => 'Polish',
        'ro-RO' => 'Romanian',
        'ru-RU' => 'Russian',
        'sk-SK' => 'Slovak',
        'sl-SI' => 'Slovenian',
        'sr-RS' => 'Serbian',
        'uk-UA' => 'Ukrainian',
        'et-EE' => 'Estonian',
        'lt-LT' => 'Lithuanian',
        'lv-LV' => 'Latvian',
        'el-GR' => 'Greek',
        'it-IT' => 'Italian',
        'cmn-CN' => 'Chinese (Mandarin)',
        'ja-JP' => 'Japanese',
        'ko-KR' => 'Korean',
        'id-ID' => 'Indonesian',
        'th-TH' => 'Thai',
        'vi-VN' => 'Vietnamese',
        'bn-IN' => 'Bengali',
        'gu-IN' => 'Gujarati',
        'hi-IN' => 'Hindi',
        'kn-IN' => 'Kannada',
        'ml-IN' => 'Malayalam',
        'mr-IN' => 'Marathi',
        'ta-IN' => 'Tamil',
        'te-IN' => 'Telugu',
        'ur-IN' => 'Urdu',
        'ar-XA' => 'Arabic',
        'he-IL' => 'Hebrew',
        'tr-TR' => 'Turkish',
        'sw-KE' => 'Swahili',
    ];

    /** Language names the question writers use. */
    const LANGUAGE_NAMES = [
        'en-AU' => 'English (Australian)',
        'en-GB' => 'English (British)',
        'en-IN' => 'English (Indian)',
        'en-US' => 'English (American)',
        'es-ES' => 'Spanish (Spain)',
        'es-US' => 'Spanish (US)',
        'fr-CA' => 'French (Canadian)',
        'fr-FR' => 'French (France)',
        'de-DE' => 'German',
        'pt-BR' => 'Portuguese (Brazilian)',
        'nl-BE' => 'Dutch (Belgian)',
        'nl-NL' => 'Dutch (Netherlands)',
        'da-DK' => 'Danish',
        'fi-FI' => 'Finnish',
        'nb-NO' => 'Norwegian',
        'sv-SE' => 'Swedish',
        'bg-BG' => 'Bulgarian',
        'cs-CZ' => 'Czech',
        'hr-HR' => 'Croatian',
        'hu-HU' => 'Hungarian',
        'pl-PL' => 'Polish',
        'ro-RO' => 'Romanian',
        'ru-RU' => 'Russian',
        'sk-SK' => 'Slovak',
        'sl-SI' => 'Slovenian',
        'sr-RS' => 'Serbian',
        'uk-UA' => 'Ukrainian',
        'et-EE' => 'Estonian',
        'lt-LT' => 'Lithuanian',
        'lv-LV' => 'Latvian',
        'el-GR' => 'Greek',
        'it-IT' => 'Italian',
        'cmn-CN' => 'Chinese (Mandarin)',
        'ja-JP' => 'Japanese',
        'ko-KR' => 'Korean',
        'id-ID' => 'Indonesian',
        'th-TH' => 'Thai',
        'vi-VN' => 'Vietnamese',
        'bn-IN' => 'Bengali',
        'gu-IN' => 'Gujarati',
        'hi-IN' => 'Hindi',
        'kn-IN' => 'Kannada',
        'ml-IN' => 'Malayalam',
        'mr-IN' => 'Marathi',
        'ta-IN' => 'Tamil',
        'te-IN' => 'Telugu',
        'ur-IN' => 'Urdu',
        'ar-XA' => 'Arabic',
        'he-IL' => 'Hebrew',
        'tr-TR' => 'Turkish',
        'sw-KE' => 'Swahili',
        'ms-MY' => 'Malay',
        'pa-IN' => 'Punjabi',
        'fil-PH' => 'Filipino',
        'cmn-TW' => 'Chinese (Traditional Mandarin)',
        'yue-HK' => 'Cantonese',
        'pt-PT' => 'Portuguese (Portugal)',
        'ca-ES' => 'Catalan',
        'is-IS' => 'Icelandic',
    ];

    /** Narration language codes with Chirp 3 HD voices. */
    const VOICE_LANGUAGES = [
        'en-AU' => 'en-AU',
        'en-GB' => 'en-GB',
        'en-IN' => 'en-IN',
        'en-US' => 'en-US',
        'es-ES' => 'es-ES',
        'es-US' => 'es-US',
        'fr-CA' => 'fr-CA',
        'fr-FR' => 'fr-FR',
        'de-DE' => 'de-DE',
        'pt-BR' => 'pt-BR',
        'nl-BE' => 'nl-BE',
        'nl-NL' => 'nl-NL',
        'da-DK' => 'da-DK',
        'fi-FI' => 'fi-FI',
        'nb-NO' => 'nb-NO',
        'sv-SE' => 'sv-SE',
        'bg-BG' => 'bg-BG',
        'cs-CZ' => 'cs-CZ',
        'hr-HR' => 'hr-HR',
        'hu-HU' => 'hu-HU',
        'pl-PL' => 'pl-PL',
        'ro-RO' => 'ro-RO',
        'ru-RU' => 'ru-RU',
        'sk-SK' => 'sk-SK',
        'sl-SI' => 'sl-SI',
        'sr-RS' => 'sr-RS',
        'uk-UA' => 'uk-UA',
        'et-EE' => 'et-EE',
        'lt-LT' => 'lt-LT',
        'lv-LV' => 'lv-LV',
        'el-GR' => 'el-GR',
        'it-IT' => 'it-IT',
        'cmn-CN' => 'cmn-CN',
        'ja-JP' => 'ja-JP',
        'ko-KR' => 'ko-KR',
        'id-ID' => 'id-ID',
        'th-TH' => 'th-TH',
        'vi-VN' => 'vi-VN',
        'bn-IN' => 'bn-IN',
        'gu-IN' => 'gu-IN',
        'hi-IN' => 'hi-IN',
        'kn-IN' => 'kn-IN',
        'ml-IN' => 'ml-IN',
        'mr-IN' => 'mr-IN',
        'ta-IN' => 'ta-IN',
        'te-IN' => 'te-IN',
        'ur-IN' => 'ur-IN',
        'ar-XA' => 'ar-XA',
        'he-IL' => 'he-IL',
        'tr-TR' => 'tr-TR',
        'sw-KE' => 'sw-KE',
    ];

    /**
     * getLanguageName.
     *
     * @param string $code
     * @return string
     */
    protected static function language_name(string $code): string {
        return self::LANGUAGE_NAMES[$code] ?? 'English (Australian)';
    }

    /**
     * getVoiceConfigWithId: the Google voice the service narrates with.
     *
     * @param mixed $language
     * @param mixed $voiceid
     * @return array languageCode, name, id (the voice's short name).
     */
    public static function voice_config($language, $voiceid): array {
        $valid = ['Aoede', 'Kore', 'Leda', 'Zephyr', 'Puck', 'Charon', 'Fenrir', 'Orus'];
        $voice = in_array($voiceid, $valid, true) ? $voiceid : 'Aoede';
        $fallbacks = [
            'ms-MY' => ['ms-MY', 'ms-MY-Standard-D'],
            'pa-IN' => ['hi-IN', 'hi-IN-Chirp3-HD-' . $voice],
            'fil-PH' => ['fil-PH', 'fil-PH-Standard-A'],
            'yue-HK' => ['yue-HK', 'yue-HK-Standard-D'],
            'cmn-TW' => ['cmn-CN', 'cmn-CN-Chirp3-HD-' . $voice],
            'pt-PT' => ['pt-BR', 'pt-BR-Chirp3-HD-' . $voice],
            'ca-ES' => ['es-ES', 'es-ES-Chirp3-HD-' . $voice],
            'is-IS' => ['is-IS', 'is-IS-Standard-A'],
        ];
        $key = self::key_string($language);
        if (isset($fallbacks[$key])) {
            return ['languageCode' => $fallbacks[$key][0], 'name' => $fallbacks[$key][1], 'id' => $voice];
        }
        $code = self::VOICE_LANGUAGES[$key] ?? 'en-AU';
        return ['languageCode' => $code, 'name' => $code . '-Chirp3-HD-' . $voice, 'id' => $voice];
    }

    /**
     * getSpellingInstructions.
     *
     * @param mixed $country
     * @return string
     */
    protected static function spelling($country): string {
        if (!self::js_truthy($country)) {
            return '';
        }
        $c = self::lower(self::js_str($country));
        if ($c === 'australia' || $c === 'au') {
            return ('

LANGUAGE REQUIREMENTS (CRITICAL - Australian English):
Use Australian English spelling and terminology throughout ALL content:
- organisation (not organization), behaviour (not behavior), colour (not color)
- centre (not center), licence (noun)/license (verb), defence (not defense)  
- programme (not program for courses), labour (not labor), honour (not honor)
- realise (not realize), analyse (not analyze), recognise (not recognize)
- Use Australian terminology where relevant (e.g., "superannuation" for retirement savings)');
        } else if ($c === 'united kingdom' || $c === 'uk') {
            return ('

LANGUAGE REQUIREMENTS (British English):
Use British English spelling throughout all content:
- organisation, behaviour, colour, centre, defence, programme, labour
- -ise endings (organise, realise, recognise) not -ize
- Use British English terminology');
        } else if ($c === 'united states' || $c === 'us' || $c === 'usa') {
            return ('

LANGUAGE REQUIREMENTS (American English):
Use American English spelling throughout all content:
- organization, behavior, color, center, defense, program, labor
- -ize endings (organize, realize, recognize)');
        } else if ($c === 'canada' || $c === 'ca') {
            return ('

LANGUAGE REQUIREMENTS (Canadian English):
Use Canadian English spelling throughout all content:
- Mixed British/American: colour, centre, but organization/organize is acceptable
- -ize or -ise endings both acceptable (organization/organisation)');
        } else if ($c === 'new zealand' || $c === 'nz') {
            return ('

LANGUAGE REQUIREMENTS (New Zealand English):
Use New Zealand English spelling throughout all content (similar to British/Australian):
- organisation, behaviour, colour, centre, defence, programme
- -ise endings (organise, realise, recognise)');
        } else if ($c === 'singapore' || $c === 'sg') {
            return ('

LANGUAGE REQUIREMENTS (Singapore English):
Use British English spelling throughout all content:
- organisation, behaviour, colour, centre, programme');
        }
        return '';
    }

    /**
     * describeSchoolAcademicLevel.
     *
     * @param mixed $type
     * @param mixed $level
     * @return string|null
     */
    protected static function school_level($type, $level): ?string {
        $names = ['earlyyears' => 'Early years', 'primary' => 'Primary school', 'secondary' => 'Secondary school',
            'seniorsecondary' => 'Senior secondary'];
        if (!is_string($type) || !isset($names[$type])) {
            return null;
        }
        $clean = '';
        if (is_string($level) || is_int($level) || is_float($level)) {
            $clean = self::slice(self::jtrim(preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}]/u', '',
                self::js_str($level))), 0, 80);
        }
        return $clean !== '' ? $names[$type] . ' — ' . $clean : $names[$type];
    }

    // JavaScript semantics.

    /**
     * Next random number in [0, 1).
     *
     * @return float
     */
    protected static function random(): float {
        if (self::$random) {
            return (float) (self::$random)();
        }
        return random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX;
    }

    /**
     * JSON.parse(): objects become string-keyed arrays (an empty one a \stdClass), arrays become lists.
     *
     * @param string $s
     * @return mixed
     * @throws js_error
     */
    public static function parse(string $s) {
        $v = json_decode($s);
        if ($v === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new js_error('SyntaxError: ' . json_last_error_msg());
        }
        return self::tree($v);
    }

    /**
     * Turn decoded JSON objects into arrays.
     *
     * @param mixed $v
     * @return mixed
     */
    protected static function tree($v) {
        if ($v instanceof \stdClass) {
            $vars = get_object_vars($v);
            if (!$vars) {
                return new \stdClass();
            }
            $out = [];
            foreach ($vars as $k => $x) {
                $out[$k] = self::tree($x);
            }
            return $out;
        }
        if (is_array($v)) {
            return array_map([self::class, 'tree'], $v);
        }
        return $v;
    }

    /**
     * A JavaScript array.
     *
     * @param mixed $v
     * @return bool
     */
    protected static function is_array_js($v): bool {
        return is_array($v) && array_is_list($v);
    }

    /**
     * A value whose typeof is "object", other than null.
     *
     * @param mixed $v
     * @return bool
     */
    protected static function is_object_js($v): bool {
        return is_array($v) || $v instanceof \stdClass;
    }

    /**
     * Whether an object has its own property.
     *
     * @param mixed $o
     * @param string $k
     * @return bool
     */
    protected static function has($o, string $k): bool {
        return is_array($o) && !array_is_list($o) && array_key_exists($k, $o);
    }

    /**
     * o.k as JavaScript reads it; undefined is null. Reading from null or undefined is a TypeError.
     *
     * @param mixed $o
     * @param string $k
     * @return mixed
     */
    protected static function prop($o, string $k) {
        if ($o === null) {
            throw js_error::type("Cannot read properties of null (reading '{$k}')");
        }
        if ($k === 'length') {
            if (is_string($o)) {
                return self::len($o);
            }
            if (self::is_array_js($o)) {
                return count($o);
            }
        }
        if (is_array($o)) {
            if (array_is_list($o)) {
                return preg_match('/^(0|[1-9]\d*)$/', $k) ? ($o[(int) $k] ?? null) : null;
            }
            return $o[$k] ?? null;
        }
        return null;
    }

    /**
     * list[i] for a list or a string; reading an index of null or undefined is a TypeError.
     *
     * @param mixed $v
     * @param int|float $i
     * @param bool $strict Throw on null.
     * @return mixed
     */
    protected static function elem($v, $i, bool $strict = false) {
        if ($v === null) {
            if ($strict) {
                throw js_error::type("Cannot read properties of undefined (reading '{$i}')");
            }
            return null;
        }
        $i = (int) $i;
        if (is_array($v)) {
            return array_is_list($v) ? ($v[$i] ?? null) : ($v[$i] ?? null);
        }
        if (is_string($v)) {
            $c = self::slice($v, $i, $i + 1);
            return $c === '' ? null : $c;
        }
        return null;
    }

    /**
     * A property key as JavaScript writes it, for an array index.
     *
     * @param mixed $k
     * @return int|null The index, or null when the key is not an array index.
     */
    protected static function index_of_key($k): ?int {
        if (is_int($k) && $k >= 0) {
            return $k;
        }
        if (is_float($k) && floor($k) === $k && $k >= 0 && $k < 4294967295) {
            return (int) $k;
        }
        if (is_string($k) && preg_match('/^(0|[1-9]\d{0,9})$/', $k)) {
            return (int) $k;
        }
        return null;
    }

    /**
     * list[k] with a key of any type.
     *
     * @param array $list
     * @param mixed $k
     * @return mixed
     */
    protected static function index_get(array $list, $k) {
        $i = self::index_of_key($k);
        return $i === null ? null : ($list[$i] ?? null);
    }

    /**
     * list[k] = v with a key of any type; a key that is not an index sets nothing JSON would keep.
     *
     * @param array $list
     * @param mixed $k
     * @param mixed $v
     */
    protected static function index_set(array &$list, $k, $v): void {
        $i = self::index_of_key($k);
        if ($i !== null) {
            for ($j = count($list); $j < $i; $j++) {
                $list[$j] = null;
            }
            $list[$i] = $v;
        }
    }

    /**
     * [...v].
     *
     * @param mixed $v
     * @return array
     */
    protected static function spread($v): array {
        if (is_array($v)) {
            return array_values($v);
        }
        if (is_string($v)) {
            return preg_split('//u', $v, -1, PREG_SPLIT_NO_EMPTY);
        }
        throw js_error::type('object is not iterable');
    }

    /**
     * A list, for map().
     *
     * @param mixed $v
     * @return array
     */
    protected static function list_of($v): array {
        if (self::is_array_js($v)) {
            return $v;
        }
        throw js_error::type('map is not a function');
    }

    /**
     * Re-index a list.
     *
     * @param array $v
     * @return array
     */
    protected static function clean_list(array $v): array {
        ksort($v);
        return array_values($v);
    }

    /**
     * list.slice(a, b).
     *
     * @param array $list
     * @param int $a
     * @param mixed $b
     * @return array
     */
    protected static function slice_list(array $list, int $a, $b): array {
        $n = count($list);
        $b = self::tonum($b);
        if (is_nan((float) $b)) {
            $b = 0;
        }
        $b = $b < 0 ? max(0, $n + (int) $b) : min((int) $b, $n);
        return array_slice($list, $a, max(0, $b - $a));
    }

    /**
     * a === b, with JavaScript's single number type.
     *
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    protected static function strict_eq($a, $b): bool {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }
        return $a === $b;
    }

    /**
     * Number(v) for a relational comparison.
     *
     * @param mixed $v
     * @return int|float
     */
    protected static function tonum($v) {
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        if ($v === null) {
            return 0;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if (is_array($v) && array_is_list($v)) {
            return self::tonum(implode(',', array_map(function($x) {
                return $x === null ? '' : self::js_str($x);
            }, $v)));
        }
        if (is_string($v)) {
            $t = self::jtrim($v);
            if ($t === '') {
                return 0;
            }
            if (preg_match('/^[+-]?(Infinity|\d+\.?\d*(e[+-]?\d+)?|\.\d+(e[+-]?\d+)?)$/i', $t) && stripos($t, 'infinity') === false) {
                return $t + 0;
            }
            if (preg_match('/^([+-]?)Infinity$/', $t, $m)) {
                return $m[1] === '-' ? -INF : INF;
            }
            if (preg_match('/^0x[0-9a-f]+$/i', $t)) {
                return hexdec($t);
            }
            return NAN;
        }
        return NAN;
    }

    /**
     * a < b.
     *
     * @param mixed $a
     * @param int|float $b
     * @return bool
     */
    protected static function js_lt($a, $b): bool {
        $a = self::tonum($a);
        return !is_nan((float) $a) && $a < $b;
    }

    /**
     * a > b.
     *
     * @param mixed $a
     * @param int|float $b
     * @return bool
     */
    protected static function js_gt($a, $b): bool {
        $a = self::tonum($a);
        return !is_nan((float) $a) && $a > $b;
    }

    /**
     * a * b.
     *
     * @param mixed $a
     * @param int|float $b
     * @return int|float
     */
    protected static function js_mul($a, $b) {
        return self::tonum($a) * $b;
    }

    /**
     * Math.round().
     *
     * @param float $x
     * @return int
     */
    protected static function js_round(float $x): int {
        return (int) floor($x + 0.5);
    }

    /**
     * parseInt(s, 10).
     *
     * @param string $s
     * @return int|float
     */
    protected static function parse_int(string $s) {
        if (preg_match('/^[' . self::WS . ']*([+-]?\d+)/u', $s, $m)) {
            return (int) $m[1];
        }
        return NAN;
    }

    /**
     * A Set key that keeps "69" and 69 apart, as SameValueZero does.
     *
     * @param mixed $v
     * @return string
     */
    protected static function key_of($v): string {
        if (is_int($v) || is_float($v)) {
            return 'n:' . self::js_str($v);
        }
        return gettype($v) . ':' . (is_scalar($v) ? (string) $v : self::json($v));
    }

    /**
     * A value used as an object key, as String() writes it.
     *
     * @param mixed $v
     * @return string
     */
    protected static function key_string($v): string {
        return self::js_str($v);
    }

    /**
     * Object.values().
     *
     * @param mixed $v
     * @return array
     */
    protected static function object_values($v): array {
        if (is_array($v)) {
            return array_values($v);
        }
        if (is_string($v)) {
            return preg_split('//u', $v, -1, PREG_SPLIT_NO_EMPTY);
        }
        return [];
    }

    /**
     * The first value that is an array, or null.
     *
     * @param array $values
     * @return mixed
     */
    protected static function first_array(array $values) {
        foreach ($values as $v) {
            if (self::is_array_js($v)) {
                return $v;
            }
        }
        return null;
    }

    /**
     * Length in UTF-16 code units.
     *
     * @param string $s
     * @return int
     */
    public static function len(string $s): int {
        return intdiv(strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * s.substring(a, b) in UTF-16 code units.
     *
     * @param string $s
     * @param int $a
     * @param int $b
     * @return string
     */
    public static function slice(string $s, int $a, int $b): string {
        $u = mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
        $n = intdiv(strlen($u), 2);
        $a = max(0, min($a, $n));
        $b = max(0, min($b, $n));
        return mb_convert_encoding(substr($u, $a * 2, max(0, $b - $a) * 2), 'UTF-8', 'UTF-16LE');
    }

    /**
     * String.prototype.trim().
     *
     * @param string $s
     * @return string
     */
    public static function jtrim(string $s): string {
        return preg_replace('/^[' . self::WS . ']+|[' . self::WS . ']+$/u', '', $s);
    }

    /**
     * String.prototype.toLowerCase().
     *
     * @param string $s
     * @return string
     */
    protected static function lower(string $s): string {
        return mb_strtolower($s, 'UTF-8');
    }

    /**
     * JSON.stringify(v).
     *
     * @param mixed $v
     * @return string
     */
    public static function json($v): string {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
    }

    /**
     * JSON.stringify(v, null, 2).
     *
     * @param mixed $v
     * @param string $indent
     * @return string
     */
    public static function json_pretty($v, string $indent = ''): string {
        $inner = $indent . '  ';
        if ($v instanceof \stdClass) {
            $v = get_object_vars($v);
            if (!$v) {
                return '{}';
            }
        } else if (is_array($v) && array_is_list($v)) {
            if (!$v) {
                return '[]';
            }
            $items = array_map(function($x) use ($inner) {
                return $inner . self::json_pretty($x, $inner);
            }, $v);
            return "[\n" . implode(",\n", $items) . "\n" . $indent . ']';
        }
        if (is_array($v)) {
            $items = [];
            foreach ($v as $k => $x) {
                $items[] = $inner . self::json((string) $k) . ': ' . self::json_pretty($x, $inner);
            }
            return "{\n" . implode(",\n", $items) . "\n" . $indent . '}';
        }
        return self::json($v);
    }

    // Request checks, as the service's zod schema makes them.

    /**
     * zod's name for a value's type.
     *
     * @param mixed $v
     * @return string
     */
    protected static function z_type($v): string {
        if ($v === null) {
            return 'null';
        }
        if (is_string($v)) {
            return 'string';
        }
        if (is_bool($v)) {
            return 'boolean';
        }
        if (is_int($v) || is_float($v)) {
            return is_nan((float) $v) ? 'nan' : 'number';
        }
        if (self::is_array_js($v)) {
            return 'array';
        }
        return 'object';
    }

    /**
     * zod's invalid-type message.
     *
     * @param string $expected
     * @param mixed $v
     * @return string
     */
    protected static function z_expected(string $expected, $v): string {
        return 'Expected ' . $expected . ', received ' . self::z_type($v);
    }

    /**
     * A string field.
     *
     * @param array $o
     * @param string $key
     * @param array $issues
     * @param array $rules required, default, min, max, trim.
     * @param array $path Path of the object.
     * @return mixed
     */
    protected static function z_string(array $o, string $key, array &$issues, array $rules, array $path = []) {
        $p = array_merge($path, [$key]);
        if (!array_key_exists($key, $o)) {
            if (!empty($rules['required'])) {
                $issues[] = [$p, 'Required'];
            }
            return $rules['default'] ?? null;
        }
        $v = $o[$key];
        if (!is_string($v)) {
            $issues[] = [$p, self::z_expected('string', $v)];
            return null;
        }
        if (!empty($rules['trim'])) {
            $v = self::jtrim($v);
        }
        if (isset($rules['min']) && self::len($v) < $rules['min']) {
            $issues[] = [$p, 'String must contain at least ' . $rules['min'] . ' character(s)'];
        }
        if (isset($rules['max']) && self::len($v) > $rules['max']) {
            $issues[] = [$p, 'String must contain at most ' . $rules['max'] . ' character(s)'];
        }
        return $v;
    }

    /**
     * A number field with a default and a range.
     *
     * @param array $o
     * @param string $key
     * @param array $issues
     * @param int|float $default
     * @param int $min
     * @param int $max
     * @param array $path
     * @return mixed
     */
    protected static function z_number(array $o, string $key, array &$issues, $default, int $min, int $max, array $path = []) {
        $p = array_merge($path, [$key]);
        if (!array_key_exists($key, $o)) {
            return $default;
        }
        $v = $o[$key];
        if (!is_int($v) && !is_float($v)) {
            $issues[] = [$p, self::z_expected('number', $v)];
            return null;
        }
        if ($v < $min) {
            $issues[] = [$p, 'Number must be greater than or equal to ' . $min];
        }
        if ($v > $max) {
            $issues[] = [$p, 'Number must be less than or equal to ' . $max];
        }
        return $v;
    }

    /**
     * A boolean field with a default.
     *
     * @param array $o
     * @param string $key
     * @param array $issues
     * @param bool $default
     * @return mixed
     */
    protected static function z_bool(array $o, string $key, array &$issues, bool $default) {
        if (!array_key_exists($key, $o)) {
            return $default;
        }
        if (!is_bool($o[$key])) {
            $issues[] = [[$key], self::z_expected('boolean', $o[$key])];
            return null;
        }
        return $o[$key];
    }

    /**
     * An enum field with a default.
     *
     * @param array $o
     * @param string $key
     * @param array $issues
     * @param array $options
     * @param string $default
     * @return mixed
     */
    protected static function z_enum(array $o, string $key, array &$issues, array $options, string $default) {
        if (!array_key_exists($key, $o)) {
            return $default;
        }
        $v = $o[$key];
        $list = implode(' | ', array_map(function($x) {
            return "'" . $x . "'";
        }, $options));
        if (!is_string($v)) {
            $issues[] = [[$key], 'Expected ' . $list . ', received ' . self::z_type($v)];
            return null;
        }
        if (!in_array($v, $options, true)) {
            $issues[] = [[$key], 'Invalid enum value. Expected ' . $list . ", received '" . $v . "'"];
            return null;
        }
        return $v;
    }

    /**
     * An optional array field (default empty) whose items are checked by a callback.
     *
     * @param array $o
     * @param string $key
     * @param array $issues
     * @param callable $item fn($value, array $path)
     * @return array
     */
    protected static function z_array(array $o, string $key, array &$issues, callable $item): array {
        if (!array_key_exists($key, $o)) {
            return [];
        }
        $v = $o[$key];
        if (!self::is_array_js($v)) {
            $issues[] = [[$key], self::z_expected('array', $v)];
            return [];
        }
        $out = [];
        foreach ($v as $i => $x) {
            $out[] = $item($x, [$key, $i]);
        }
        return $out;
    }

    // JavaScript semantics used by the ported prompt templates (generated with the port).

    /** @param mixed $v @return bool JavaScript truthiness. */
    private static function js_truthy($v): bool {
        if (is_array($v) || is_object($v)) {
            return true;
        }
        if (is_float($v) && is_nan($v)) {
            return false;
        }
        return $v !== null && $v !== false && $v !== '' && $v !== 0 && $v !== 0.0;
    }

    /** @param mixed $a @param mixed $b @return mixed a || b */
    private static function js_or($a, $b) {
        return self::js_truthy($a) ? $a : $b;
    }

    /** @param mixed $a @param mixed $b @return mixed a && b */
    private static function js_and($a, $b) {
        return self::js_truthy($a) ? $b : $a;
    }

    /** @param mixed $a @param mixed $b @return mixed a + b */
    private static function js_add($a, $b) {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a + $b;
        }
        return self::js_str($a) . self::js_str($b);
    }

    /** @param mixed $v @return string String(v) */
    private static function js_str($v): string {
        if ($v === null) {
            return 'null';
        }
        if ($v === true) {
            return 'true';
        }
        if ($v === false) {
            return 'false';
        }
        if (is_float($v)) {
            if (floor($v) == $v && abs($v) < 1e15) {
                return (string) (int) $v;
            }
            return rtrim(rtrim(sprintf('%.15F', $v), '0'), '.');
        }
        if (is_array($v)) {
            if ($v === [] || array_keys($v) === range(0, count($v) - 1)) {
                return implode(',', array_map(function($x) {
                    return $x === null ? '' : self::js_str($x);
                }, $v));
            }
            return '[object Object]';
        }
        return (string) $v;
    }

    /** @param mixed $v @return float Number(v) */
    private static function js_num($v) {
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        if ($v === null || $v === false || (is_string($v) && trim($v) === '')) {
            return 0;
        }
        if ($v === true) {
            return 1;
        }
        return is_numeric(trim((string) $v)) ? trim((string) $v) + 0 : NAN;
    }

    /** @param mixed $o @param mixed $k @return mixed o[k] or null */
    private static function js_get($o, $k) {
        if (is_array($o)) {
            return $o[$k] ?? null;
        }
        if (is_string($o) && is_int($k)) {
            return mb_substr($o, $k, 1);
        }
        return null;
    }

    /** @param mixed $v @return int .length */
    private static function js_len($v): int {
        if (is_array($v)) {
            return count($v);
        }
        return strlen(mb_convert_encoding(self::js_str($v), 'UTF-16LE', 'UTF-8')) / 2;
    }

    /** @param mixed $h @param mixed $n @return bool .includes() */
    private static function js_includes($h, $n): bool {
        if (is_array($h)) {
            return in_array($n, $h, true);
        }
        return strpos(self::js_str($h), self::js_str($n)) !== false;
    }

    /** @param mixed $v @param int $a @param int|null $b @return mixed .slice() on strings (UTF-16) and arrays */
    private static function js_slice($v, $a = 0, $b = null) {
        if (is_array($v)) {
            $n = count($v);
            $a = $a < 0 ? max(0, $n + $a) : min($a, $n);
            $b = $b === null ? $n : ($b < 0 ? max(0, $n + $b) : min($b, $n));
            return array_slice($v, $a, max(0, $b - $a));
        }
        $u = mb_convert_encoding(self::js_str($v), 'UTF-16LE', 'UTF-8');
        $n = strlen($u) / 2;
        $a = $a < 0 ? max(0, $n + $a) : min($a, $n);
        $b = $b === null ? $n : ($b < 0 ? max(0, $n + $b) : min($b, $n));
        return mb_convert_encoding(substr($u, $a * 2, max(0, $b - $a) * 2), 'UTF-8', 'UTF-16LE');
    }

    /** @param mixed $v @param callable $f @return array .map() */
    private static function js_map($v, callable $f): array {
        $out = [];
        foreach (array_values((array) $v) as $i => $x) {
            $out[] = $f($x, $i);
        }
        return $out;
    }

    /** @param mixed $v @param callable $f @return array .filter() */
    private static function js_filter($v, callable $f): array {
        $out = [];
        foreach (array_values((array) $v) as $i => $x) {
            if (self::js_truthy($f($x, $i))) {
                $out[] = $x;
            }
        }
        return $out;
    }

    /** @param mixed $v @return string typeof */
    private static function js_typeof($v): string {
        if (is_string($v)) {
            return 'string';
        }
        if (is_int($v) || is_float($v)) {
            return 'number';
        }
        if (is_bool($v)) {
            return 'boolean';
        }
        return $v === null ? 'undefined' : 'object';
    }

    /** @param mixed $s @param string $a @param string $b @return string .replace(string, string) */
    private static function js_replace_first($s, $a, $b): string {
        $s = self::js_str($s);
        $p = strpos($s, $a);
        return $p === false ? $s : substr_replace($s, $b, $p, strlen($a));
    }
}
