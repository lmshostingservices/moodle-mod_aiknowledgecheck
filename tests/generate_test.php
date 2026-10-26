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
 * Tests the input limits the generate service enforces before it calls out.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_aiknowledgecheck;

use externallib_advanced_testcase;
use external_api;
use mod_aiknowledgecheck\external\generate;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');
require_once($CFG->dirroot . '/mod/aiknowledgecheck/lib.php');

/**
 * Limits applied to a generation request.
 *
 * Every limit asserted here is checked before saas_client::credentials() is consulted, so these
 * tests need no API key and make no outbound request. Where a request is expected to pass the
 * limits, it then stops at the "not configured" error — asserting that specific message is how
 * these tests show the request got past the limit rather than failing for some other reason.
 *
 * @runTestsInSeparateProcesses
 * @covers \mod_aiknowledgecheck\external\generate
 */
final class generate_test extends externallib_advanced_testcase {
    /** @var \stdClass The test course. */
    private $course;

    /** @var \stdClass The course module. */
    private $cm;

    /** @var \stdClass A teacher, who holds mod/aiknowledgecheck:create. */
    private $teacher;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_aiknowledgecheck');
        $activity = $generator->create_instance(['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('aiknowledgecheck', $activity->id);

        $this->setUser($this->teacher);
    }

    /**
     * Run the service and return its cleaned result.
     *
     * @param array $args Named arguments overriding the defaults.
     * @return array The cleaned return value.
     */
    private function generate(array $args = []): array {
        $defaults = [
            'topics' => "Fire safety\n",
            'questionspertopic' => 5,
            'useownquestions' => 0,
            'userquestions' => '',
            'usetextsources' => 0,
            'textsources' => '',
        ];
        $a = array_merge($defaults, $args);

        $result = generate::execute(
            $this->cm->id,
            $a['topics'],
            $a['questionspertopic'],
            0,
            '',
            '',
            '',
            '',
            '',
            '',
            'vet',
            'cert3',
            '',
            '',
            $a['useownquestions'],
            $a['userquestions'],
            $a['usetextsources'],
            $a['textsources']
        );

        return external_api::clean_returnvalue(generate::execute_returns(), $result);
    }

    /**
     * The message shown when a request clears every limit but the plugin has no API key.
     *
     * @return string
     */
    private function notconfigured(): string {
        return get_string('error:notconfigured', 'mod_aiknowledgecheck');
    }

    /**
     * Build a JSON text-sources payload.
     *
     * @param array $sources Pairs of [character length, questions requested].
     * @return string The JSON payload.
     */
    private function sources(array $sources): string {
        $out = [];
        foreach ($sources as [$length, $count]) {
            $out[] = ['text' => str_repeat('a', $length), 'questionCount' => $count];
        }

        return json_encode($out);
    }

    /**
     * A source over the character limit is refused rather than silently trimmed.
     */
    public function test_over_length_source_is_refused(): void {
        $result = $this->generate([
            'usetextsources' => 1,
            'textsources' => $this->sources([[50001, 5]]),
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(get_string('error_text_too_long', 'mod_aiknowledgecheck'), $result['error']);
    }

    /**
     * A source exactly on the limit is accepted — the check is "over", not "at".
     */
    public function test_source_at_the_limit_is_accepted(): void {
        $result = $this->generate([
            'usetextsources' => 1,
            'textsources' => $this->sources([[50000, 5]]),
        ]);

        $this->assertSame($this->notconfigured(), $result['error'], 'A source on the limit must clear the length check.');
    }

    /**
     * One over-length source spoils the request even when the others are fine, because the
     * teacher would otherwise lose that source's tail without being told.
     */
    public function test_one_over_length_source_among_several_is_refused(): void {
        $result = $this->generate([
            'usetextsources' => 1,
            'textsources' => $this->sources([[100, 2], [50001, 2], [100, 2]]),
        ]);

        $this->assertSame(get_string('error_text_too_long', 'mod_aiknowledgecheck'), $result['error']);
    }

    /**
     * Topics multiplied by the per-topic count is capped.
     */
    public function test_topics_over_the_total_cap_are_refused(): void {
        // 11 topics x 10 questions = 110.
        $topics = implode("\n", array_map(fn($i) => "Topic $i", range(1, 11)));

        $result = $this->generate(['topics' => $topics, 'questionspertopic' => 10]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('110', $result['error']);
        $this->assertStringContainsString('100', $result['error']);
    }

    /**
     * Exactly the cap is allowed.
     */
    public function test_topics_at_the_total_cap_are_accepted(): void {
        $topics = implode("\n", array_map(fn($i) => "Topic $i", range(1, 10)));

        $result = $this->generate(['topics' => $topics, 'questionspertopic' => 10]);

        $this->assertSame($this->notconfigured(), $result['error'], 'A request of exactly 100 must be allowed.');
    }

    /**
     * The cap is applied to the clamped per-topic count, not the number the caller sent, so a
     * caller cannot slip past it by asking for a per-topic value the service will reduce.
     */
    public function test_the_cap_uses_the_clamped_per_topic_count(): void {
        // 6 topics x 50 requested; 50 clamps to 20, giving 120 — over the cap.
        $topics = implode("\n", array_map(fn($i) => "Topic $i", range(1, 6)));

        $result = $this->generate(['topics' => $topics, 'questionspertopic' => 50]);

        $this->assertStringContainsString('120', $result['error'], 'The cap must count 6 x 20, not 6 x 50.');
    }

    /**
     * Blank lines in the topic list do not count towards the total.
     */
    public function test_blank_topic_lines_do_not_count(): void {
        $topics = "Topic 1\n\n   \nTopic 2\n\n";

        $result = $this->generate(['topics' => $topics, 'questionspertopic' => 20]);

        $this->assertSame($this->notconfigured(), $result['error'], 'Two real topics x 20 is 40, under the cap.');
    }

    /**
     * Text-source counts are added together and capped.
     */
    public function test_text_source_counts_are_summed_against_the_cap(): void {
        // 5 sources x 25 questions = 125.
        $result = $this->generate([
            'usetextsources' => 1,
            'textsources' => $this->sources([[100, 25], [100, 25], [100, 25], [100, 25], [100, 25]]),
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('125', $result['error']);
    }

    /**
     * Teacher-supplied questions are counted by line and capped.
     */
    public function test_own_questions_over_the_cap_are_refused(): void {
        $questions = implode("\n", array_map(fn($i) => "Question $i?", range(1, 101)));

        $result = $this->generate([
            'useownquestions' => 1,
            'userquestions' => $questions,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('101', $result['error']);
    }

    /**
     * A student must not be able to drive the generation service at all.
     */
    public function test_a_student_cannot_generate(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        $this->generate();
    }
}
