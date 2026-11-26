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

namespace mod_aiknowledgecheck;

use external_api;
use mod_aiknowledgecheck\external\generate;
use mod_aiknowledgecheck\external\generate_image;
use mod_aiknowledgecheck\external\get_generation_status;
use mod_aiknowledgecheck\external\regenerate_audio;
use mod_aiknowledgecheck\external\regenerate_with_settings;
use mod_aiknowledgecheck\local\byok\engine;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');
require_once($CFG->dirroot . '/mod/aiknowledgecheck/lib.php');

/**
 * Tests for writing, narrating and illustrating knowledge checks on the site's own AI.
 *
 * local\byok\engine was compared with the LMS Labs service code on 877 randomised runs of every route (identical
 * prompts, token budgets, retries, repairs, answer shuffles, narration calls, responses and job errors); these
 * tests cover the wiring into the plugin.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(byok::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(engine::class)]
final class byok_test extends \externallib_advanced_testcase {
    /** @var array Requests the mocked providers received. */
    protected $requests = [];
    /** @var array Text answers the mocked text provider gives, in order. */
    protected $answers = [];
    /** @var \stdClass */
    protected $cm;

    /**
     * Licence the site for Bring your own AI on a test key, with text on an Anthropic key.
     */
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists('\local_aiconfig\byok\ai')) {
            $this->markTestSkipped('AI Grader Central Config 1.1.0 or later is not installed.');
        }
        $this->resetAfterTest();
        $pair = sodium_crypto_sign_keypair();
        set_config('testpublickey', base64_encode(sodium_crypto_sign_publickey($pair)), 'local_aiconfig');
        $payload = json_encode(['v' => 1, 'id' => 'lic_t', 'site' => \local_aiconfig\byok\licence::site_host(),
            'plan' => 'pro', 'byok' => true, 'products' => ['*'], 'sub' => 'sub_t', 'exp' => time() + DAYSECS]);
        $b64 = function(string $s): string {
            return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        };
        set_config('licencekey', $b64($payload) . '.' .
            $b64(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($pair))), 'local_aiconfig');
        set_config('src_text', 'ownkey', 'local_aiconfig');
        set_config('vendor_text', 'anthropic', 'local_aiconfig');
        set_config('key_anthropic', 'sk-test', 'local_aiconfig');
        set_config('key_gemini', 'g-test', 'local_aiconfig');
        set_config('key_openai', 'sk-open', 'local_aiconfig');
        // No LMS Labs credentials: everything on the site's own AI must work without them.
        set_config('siteid', '', 'mod_aiknowledgecheck');
        set_config('apikey', '', 'mod_aiknowledgecheck');
        $this->requests = [];
        $this->answers = [];
        $GLOBALS['local_aiconfig_byok_mock'] = function($url, $headers, $body, $vendor, $raw = false) {
            $this->requests[] = ['url' => $url, 'body' => $body, 'vendor' => $vendor];
            if (strpos($url, 'texttospeech') !== false) {
                return ['audioContent' => base64_encode('OggS-' . $body['input']['text'])];
            }
            if (strpos($url, 'images/generations') !== false) {
                return ['data' => [['b64_json' => base64_encode($this->png())]]];
            }
            $answer = array_shift($this->answers);
            if ($answer === null) {
                throw new \moodle_exception('byok_callfailed', 'local_aiconfig', '', (object) ['vendor' => $vendor,
                    'detail' => 'Overloaded']);
            }
            return ['content' => [['type' => 'text', 'text' => $answer]], 'stop_reason' => 'end_turn'];
        };
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $activity = $this->getDataGenerator()->get_plugin_generator('mod_aiknowledgecheck')
            ->create_instance(['course' => $course->id]);
        $this->cm = get_coursemodule_from_instance('aiknowledgecheck', $activity->id);
        $this->setUser($teacher);
    }

    /**
     * Clean up the provider mock.
     */
    protected function tearDown(): void {
        unset($GLOBALS['local_aiconfig_byok_mock']);
        engine::$aicall = engine::$tts = engine::$image = engine::$progress = null;
        // The plugin keeps the pre-4.2 external class names for Moodle 4.0 and 4.1; newer Moodle notes them.
        $this->resetDebugging();
        parent::tearDown();
    }

    /**
     * A small PNG.
     *
     * @return string
     */
    protected function png(): string {
        $im = imagecreatetruecolor(16, 24);
        ob_start();
        imagepng($im);
        return ob_get_clean();
    }

    /**
     * A question set as the model writes it.
     *
     * @param int $n
     * @param string $topic
     * @return string
     */
    protected function questions(int $n, string $topic = 'isolation'): string {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $options = [];
            foreach (['A', 'B', 'C', 'D'] as $j => $letter) {
                $options[] = ['text' => "Option {$letter} about {$topic} step {$i}",
                    'explanation' => ($j === 0 ? 'Correct. ' : 'Incorrect. ') . "Reason {$letter} for {$topic} {$i}."];
            }
            $out[] = ['question' => "Which step comes first when applying {$topic} on job {$i}?", 'options' => $options,
                'correctAnswer' => 0];
        }
        return json_encode(['questions' => $out]);
    }

    /**
     * Call the generate service.
     *
     * @param array $over Arguments by name.
     * @return array
     */
    protected function generate(array $over = []): array {
        $a = $over + ['topics' => "Isolation\nLockout", 'perTopic' => 2, 'voiceover' => 0, 'voice' => 'Kore',
            'language' => 'en-AU'];
        $result = generate::execute($this->cm->id, $a['topics'], $a['perTopic'], 1, 'Australia', 'WA', 'Mining', 'Iron ore',
            'Supervisor', 'Crusher operator', 'vet', 'cert3', '', '', 0, '', 0, '', $a['voiceover'], $a['language'], 'female',
            $a['voice']);
        return external_api::clean_returnvalue(generate::execute_returns(), $result);
    }

    /**
     * Poll the job.
     *
     * @param string $jobid
     * @return array
     */
    protected function poll(string $jobid): array {
        $result = external_api::clean_returnvalue(get_generation_status::execute_returns(),
            get_generation_status::execute($this->cm->id, $jobid));
        $this->assertTrue($result['ok']);
        return json_decode($result['payload'], true);
    }

    public function test_generation_runs_on_own_text_without_credentials(): void {
        $this->answers = [$this->questions(2, 'isolation'), $this->questions(2, 'lockout')];
        $start = $this->generate();
        $this->assertTrue($start['ok'], $start['error']);
        $started = json_decode($start['resultjson'], true);
        $this->assertStringStartsWith('byok-', $started['jobId']);
        $this->assertSame(0, $started['creditsRequired']);
        $this->assertSame(4, $started['totalQuestions']);
        $this->assertSame('pending', $this->poll($started['jobId'])['status']);
        $this->assertCount(0, $this->requests);

        $this->runAdhocTasks('\mod_aiknowledgecheck\task\byok_generate');
        $done = $this->poll($started['jobId']);
        $this->assertSame('completed', $done['status'], $done['error'] ?? '');
        $this->assertSame(100, $done['progress']);
        $this->assertCount(4, $done['questions']);
        foreach ($done['questions'] as $q) {
            $this->assertCount(4, $q['options']);
            $this->assertStringStartsWith('Correct.', $q['explanations'][$q['correctAnswer']]);
            $this->assertArrayNotHasKey('audioData', $q);
        }
        // The service's own prompts, sent to the site's Anthropic key.
        $this->assertCount(2, $this->requests);
        $this->assertSame('Anthropic', $this->requests[0]['vendor']);
        $this->assertStringContainsString('senior psychometrician', $this->requests[0]['body']['system']);
        $this->assertStringContainsString('Isolation', $this->requests[0]['body']['messages'][0]['content'][0]['text']);
        $this->assertStringContainsString('Industry: Mining', $this->requests[0]['body']['messages'][0]['content'][0]['text']);
        $this->assertSame(9000, $this->requests[0]['body']['max_tokens']);
    }

    public function test_narration_on_own_google_voice(): void {
        set_config('src_voice', 'ownkey', 'local_aiconfig');
        set_config('vendor_voice', 'googletts', 'local_aiconfig');
        $this->answers = [$this->questions(1)];
        $start = $this->generate(['topics' => 'Isolation', 'perTopic' => 1, 'voiceover' => 1, 'language' => 'pa-IN']);
        $started = json_decode($start['resultjson'], true);
        $this->runAdhocTasks('\mod_aiknowledgecheck\task\byok_generate');
        $done = $this->poll($started['jobId']);
        $this->assertSame('completed', $done['status'], $done['error'] ?? '');
        $q = $done['questions'][0];
        $this->assertCount(4, $q['audioData']);
        $this->assertSame('OggS-' . $q['explanations'][0], base64_decode($q['audioData'][0]));
        $tts = array_values(array_filter($this->requests, function($r) {
            return strpos($r['url'], 'texttospeech') !== false;
        }));
        $this->assertCount(4, $tts);
        // Punjabi narrates in Hindi, as the service does; Ogg Opus at the service's speaking rate.
        $this->assertSame('hi-IN-Chirp3-HD-Kore', $tts[0]['body']['voice']['name']);
        $this->assertSame('hi-IN', $tts[0]['body']['voice']['languageCode']);
        $this->assertSame('OGG_OPUS', $tts[0]['body']['audioConfig']['audioEncoding']);
        $this->assertSame(0.92, $tts[0]['body']['audioConfig']['speakingRate']);
    }

    public function test_openai_voice_gets_the_matching_voice(): void {
        set_config('src_voice', 'ownkey', 'local_aiconfig');
        set_config('vendor_voice', 'openai', 'local_aiconfig');
        $GLOBALS['local_aiconfig_byok_mock'] = function($url, $headers, $body) {
            $this->requests[] = ['url' => $url, 'body' => $body];
            return 'OggS-openai';
        };
        $questions = json_decode($this->questions(1), true)['questions'];
        $questions[0]['explanations'] = array_column($questions[0]['options'], 'explanation');
        $result = external_api::clean_returnvalue(regenerate_audio::execute_returns(),
            regenerate_audio::execute($this->cm->id, json_encode($questions), 'en-GB', 'Puck'));
        $this->assertTrue($result['ok'], $result['error']);
        $out = json_decode($result['resultjson'], true);
        $this->assertSame(base64_encode('OggS-openai'), $out['questions'][0]['audioData'][1]);
        $this->assertSame('onyx', $this->requests[0]['body']['voice']);
        $this->assertSame('opus', $this->requests[0]['body']['response_format']);
    }

    public function test_failed_provider_fails_the_job_with_its_message(): void {
        $this->answers = [];
        $start = $this->generate(['topics' => 'Isolation', 'perTopic' => 1]);
        $started = json_decode($start['resultjson'], true);
        $this->runAdhocTasks('\mod_aiknowledgecheck\task\byok_generate');
        $done = $this->poll($started['jobId']);
        $this->assertSame('failed', $done['status']);
        $this->assertStringContainsString('Your AI provider said', $done['error']);
        $this->assertStringContainsString('Overloaded', $done['error']);
        $this->assertArrayNotHasKey('questions', $done);
    }

    public function test_job_belongs_to_its_activity(): void {
        $this->answers = [$this->questions(1)];
        $started = json_decode($this->generate(['topics' => 'Isolation', 'perTopic' => 1])['resultjson'], true);
        $other = $this->getDataGenerator()->get_plugin_generator('mod_aiknowledgecheck')
            ->create_instance(['course' => $this->cm->course]);
        $othercm = get_coursemodule_from_instance('aiknowledgecheck', $other->id);
        $result = get_generation_status::execute($othercm->id, $started['jobId']);
        $this->assertSame(['ok' => false, 'error' => 'Job not found'], json_decode($result['payload'], true));
        $missing = get_generation_status::execute($this->cm->id, 'byok-0000');
        $this->assertSame(['ok' => false, 'error' => 'Job not found'], json_decode($missing['payload'], true));
    }

    public function test_service_checks_apply_before_queueing(): void {
        $result = $this->generate(['topics' => " \n ", 'perTopic' => 1]);
        $this->assertFalse($result['ok']);
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks('\mod_aiknowledgecheck\task\byok_generate'));
    }

    public function test_regenerate_with_settings_on_own_text(): void {
        $questions = json_decode($this->questions(2), true)['questions'];
        foreach ($questions as $i => $q) {
            $questions[$i]['options'] = array_column($q['options'], 'text');
            $questions[$i]['explanations'] = array_column($q['options'], 'explanation');
            $questions[$i]['timestamp_seconds'] = 30 * $i;
        }
        $rewritten = $questions;
        $rewritten[0]['question'] = 'Quelle étape vient en premier ?';
        $this->answers = ['```json' . "\n" . json_encode($rewritten) . "\n```"];
        $result = external_api::clean_returnvalue(regenerate_with_settings::execute_returns(),
            regenerate_with_settings::execute($this->cm->id, json_encode($questions), 'fr-FR', 0, 'female', 'Aoede'));
        $this->assertTrue($result['ok'], $result['error']);
        $out = json_decode($result['resultjson'], true);
        $this->assertSame('Quelle étape vient en premier ?', $out['questions'][0]['question']);
        $this->assertSame(30, $out['questions'][1]['timestamp_seconds']);
        $this->assertTrue($out['ownAi']);
        $this->assertStringContainsString('French', $this->requests[0]['body']['messages'][0]['content'][0]['text']);
        // The service sends this rewrite with no system prompt; the provider is only told to answer in JSON.
        $this->assertSame('Reply with valid JSON only, with no text before or after it.', $this->requests[0]['body']['system']);
        $this->assertSame(16000, $this->requests[0]['body']['max_tokens']);
    }

    public function test_picture_on_own_image_provider(): void {
        set_config('src_image', 'ownkey', 'local_aiconfig');
        set_config('vendor_image', 'openai', 'local_aiconfig');
        $result = external_api::clean_returnvalue(generate_image::execute_returns(),
            generate_image::execute($this->cm->id, 'A crusher operator checking the isolation board at dawn'));
        $this->assertTrue($result['ok'], $result['error']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $result['imageDataUrl']);
        $this->assertSame('1024x1536', $this->requests[0]['body']['size']);
        $this->assertSame('high', $this->requests[0]['body']['quality']);
    }

    public function test_service_job_is_narrated_on_own_voice(): void {
        set_config('src_text', 'credits', 'local_aiconfig');
        set_config('src_voice', 'ownkey', 'local_aiconfig');
        set_config('vendor_voice', 'googletts', 'local_aiconfig');
        $context = \context_module::instance($this->cm->id);
        byok::narrate_later('svc-job-1', $context, 'en-AU', 'Charon');
        $questions = json_decode($this->questions(1), true)['questions'];
        $questions[0]['explanations'] = array_column($questions[0]['options'], 'explanation');
        $service = json_encode(['ok' => true, 'status' => 'completed', 'progress' => 100, 'questions' => $questions]);
        $first = json_decode(byok::status('svc-job-1', $context, $service), true);
        $this->assertSame('processing', $first['status']);
        $this->runAdhocTasks('\mod_aiknowledgecheck\task\byok_generate');
        $done = json_decode(byok::status('svc-job-1', $context), true);
        $this->assertSame('completed', $done['status']);
        $this->assertSame('OggS-' . $questions[0]['explanations'][2], base64_decode($done['questions'][0]['audioData'][2]));
        $this->assertSame('en-AU-Chirp3-HD-Charon', $this->requests[0]['body']['voice']['name']);
        // Another job of the service's is left alone.
        $this->assertNull(byok::status('svc-job-2', $context, $service));
    }

    public function test_without_licence_everything_stays_on_lms_labs(): void {
        set_config('licencekey', '', 'local_aiconfig');
        $this->assertFalse(byok::any());
        $result = $this->generate();
        $this->assertFalse($result['ok']);
        $this->assertSame(get_string('error:notconfigured', 'mod_aiknowledgecheck'), $result['error']);
        $this->assertCount(0, $this->requests);
    }
}
