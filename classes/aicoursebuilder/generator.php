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

namespace mod_aiknowledgecheck\aicoursebuilder;

/**
 * AI Course Builder adapter for AI Knowledge Check.
 *
 * @package mod_aiknowledgecheck
 * @copyright 2026 LMS Labs
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generator {
    /** @var string The frankenstyle component this adapter builds for. */
    private const COMPONENT = 'mod_aiknowledgecheck';
    /** @var int Questions requested for each learning outcome in the brief. */
    private const QUESTIONS_PER_OUTCOME = 5;

    /**
     * How many questions a brief will produce.
     *
     * @param array $brief The Course Builder brief.
     * @return int The number of questions.
     */
    public static function native_estimate(array $brief): int {
        return count(self::topics($brief)) * self::QUESTIONS_PER_OUTCOME;
    }

    /**
     * Start a generation job for a brief.
     *
     * @param int $cmid Course module ID of the activity to fill.
     * @param array $brief The Course Builder brief.
     * @return string The job ID to poll.
     */
    public static function native_generate(int $cmid, array $brief): string {
        $topics = self::topics($brief);
        if (!$topics) {
            throw new \moodle_exception('error_no_topics', 'mod_aiknowledgecheck');
        }
        $audience = self::audience($brief);
        $response = \mod_aiknowledgecheck\external\generate::execute(
            $cmid,
            implode("\n", $topics),
            self::QUESTIONS_PER_OUTCOME,
            $audience['workplace'],
            $audience['country'],
            $audience['state'],
            '',
            '',
            $audience['joblevel'],
            '',
            $audience['educationtype'],
            $audience['vetlevel'],
            $audience['academiclevel'],
            $audience['instructions']
        );
        if (empty($response['ok']) || empty($response['resultjson'])) {
            throw new \moodle_exception('generationfailed', 'local_aicoursebuilder', '', clean_param(
                (string)($response['error'] ?? 'Knowledge Check generation request failed.'),
                PARAM_TEXT
            ));
        }
        $payload = json_decode((string)$response['resultjson'], true);
        if (!is_array($payload) || empty($payload['jobId'])) {
            throw new \moodle_exception('generationfailed', 'local_aicoursebuilder', '', 'Knowledge Check returned no job id.');
        }
        if ((int)($payload['creditsRequired'] ?? -1) !== self::native_estimate($brief)) {
            throw new \moodle_exception('invalidestimate', 'local_aicoursebuilder');
        }
        return clean_param((string)$payload['jobId'], PARAM_ALPHANUMEXT);
    }

    /**
     * Poll a generation job and save its questions once it completes.
     *
     * @param int $cmid Course module ID.
     * @param string $jobid The job ID returned by native_generate().
     * @return array Status array with status, retryable and either result or error.
     */
    public static function native_status(int $cmid, string $jobid): array {
        global $DB;
        $status = \mod_aiknowledgecheck\external\get_generation_status::execute($cmid, $jobid);
        if (empty($status['ok'])) {
            return ['status' => 'blocked', 'retryable' => false, 'error' => clean_param(
                (string)($status['error'] ?? 'Knowledge Check status is unavailable.'),
                PARAM_TEXT
            )];
        }
        $payload = json_decode((string)$status['payload'], true);
        if (!is_array($payload)) {
            return ['status' => 'blocked', 'retryable' => false, 'error' => 'Knowledge Check returned invalid job status.'];
        }
        if (($payload['status'] ?? '') === 'failed') {
            return ['status' => 'failed', 'retryable' => false, 'error' => clean_param(
                (string)($payload['error'] ?? 'Knowledge Check generation failed.'),
                PARAM_TEXT
            )];
        }
        if (($payload['status'] ?? '') !== 'completed') {
            return ['status' => 'running', 'retryable' => false];
        }
        $questions = $payload['questions'] ?? null;
        if (!is_array($questions) || !$questions) {
            return ['status' => 'failed', 'retryable' => false, 'error' => 'Knowledge Check completed without questions.'];
        }
        $cm = get_coursemodule_from_id('aiknowledgecheck', $cmid, 0, false, MUST_EXIST);
        $questions = self::with_mapping($cmid, $questions);
        $existing = $DB->count_records('aiknowledgecheck_questions', ['aiknowledgecheckid' => $cm->instance]);
        if (!$existing) {
            $saved = \mod_aiknowledgecheck\external\save_questions::execute(
                $cmid,
                json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );
            if (empty($saved['ok']) || (int)($saved['saved'] ?? 0) !== count($questions)) {
                return ['status' => 'failed', 'retryable' => false, 'error' => clean_param(
                    (string)($saved['error'] ?? 'Knowledge Check could not save generated questions.'),
                    PARAM_TEXT
                )];
            }
        }
        return [
            'status' => 'complete',
            'retryable' => false,
            'result' => ['questioncount' => count($questions), 'jobid' => clean_param($jobid, PARAM_ALPHANUMEXT)],
        ];
    }

    /**
     * Fill each question's mapping topic and criteria from the AI Course Builder lesson,
     * so Knowledge Check's question mapping (used in audits) is never blank.
     *
     * @param int $cmid
     * @param array $questions
     * @return array
     */
    private static function with_mapping(int $cmid, array $questions): array {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_aicoursebuilder_job')) {
            return $questions;
        }
        $lesson = $DB->get_record_sql(
            'SELECT l.title, l.outcomesjson
               FROM {local_aicoursebuilder_job} j
               JOIN {local_aicoursebuilder_lesson} l ON l.id = j.lessonid
              WHERE j.cmid = ?',
            [$cmid],
            IGNORE_MULTIPLE
        );
        if (!$lesson) {
            return $questions;
        }
        $topic = trim(preg_replace('/^Lesson\s+\d+\s*·\s*/u', '', (string)$lesson->title));
        $criteria = [];
        foreach ((array)(json_decode((string)$lesson->outcomesjson, true) ?: []) as $outcome) {
            $code = is_array($outcome) ? trim((string)($outcome['code'] ?? '')) : '';
            $text = is_array($outcome) ? trim((string)($outcome['text'] ?? '')) : trim((string)$outcome);
            if ($text !== '') {
                $criteria[] = $code !== '' ? $code . ' ' . $text : $text;
            }
        }
        $criteria = \core_text::substr(implode('; ', $criteria), 0, 1000);
        foreach ($questions as &$question) {
            if (!is_array($question)) {
                continue;
            }
            if (trim((string)($question['mappingTopic'] ?? '')) === '' && $topic !== '') {
                $question['mappingTopic'] = $topic;
            }
            if (trim((string)($question['mappingCriteria'] ?? '')) === '' && $criteria !== '') {
                $question['mappingCriteria'] = $criteria;
            }
        }
        unset($question);
        return $questions;
    }

    /**
     * Record the outcome of a completed job against the activity.
     *
     * @param int $cmid Course module ID.
     * @param array $result The result payload from native_status().
     * @return void
     */
    public static function native_save_result(int $cmid, array $result): void {
        global $DB;
        $cm = get_coursemodule_from_id('aiknowledgecheck', $cmid, 0, false, MUST_EXIST);
        $saved = $DB->count_records('aiknowledgecheck_questions', ['aiknowledgecheckid' => $cm->instance]);
        if ($saved < 1 || $saved !== (int)($result['questioncount'] ?? 0)) {
            throw new \moodle_exception('emptygeneratorresult', 'local_aicoursebuilder');
        }
    }

    /**
     * Who the questions are for, from the course the teacher described.
     *
     * Without this every Course Builder check was written for a VET Certificate III
     * learner, whether the course was for a school, a university or a workplace.
     *
     * @param array $brief
     * @return array
     */
    public static function audience(array $brief): array {
        $program = is_array($brief['program'] ?? null) ? $brief['program'] : [];
        $text = static fn(string $key): string => trim(clean_param((string)($program[$key] ?? ''), PARAM_TEXT));
        $sector = \core_text::strtolower($text('sector'));
        $level = \core_text::strtolower($text('level'));
        $result = [
            'workplace' => 0, 'country' => '', 'state' => '', 'joblevel' => '',
            'educationtype' => 'general', 'vetlevel' => '', 'academiclevel' => '', 'instructions' => '',
        ];
        if (preg_match('/vet|rto|tafe|vocational|apprentic/', $sector)) {
            $result['educationtype'] = 'vet';
            $levels = [
                '/advanced diploma/' => 'adv_diploma', '/graduate (certificate|diploma)/' => 'adv_diploma',
                '/diploma/' => 'diploma', '/certificate iv|cert(ificate)? ?4/' => 'cert4',
                '/certificate iii|cert(ificate)? ?3/' => 'cert3', '/certificate ii|cert(ificate)? ?2/' => 'cert2',
                '/certificate i|cert(ificate)? ?1/' => 'cert1',
            ];
            $result['vetlevel'] = 'cert3';
            foreach ($levels as $pattern => $value) {
                if (preg_match($pattern, $level)) {
                    $result['vetlevel'] = $value;
                    break;
                }
            }
        } else if (preg_match('/higher|universit|tertiary|undergrad|postgrad/', $sector)) {
            $result['educationtype'] = 'academic';
            $result['academiclevel'] = preg_match('/doctor|phd/', $level) ? 'phd'
                : (preg_match('/master/', $level) ? 'masters'
                : (preg_match('/postgrad|graduate/', $level) ? 'postgraduate' : 'undergraduate'));
        }
        if (preg_match('/workplace|corporate|employ|staff|industry/', $sector)) {
            $result['workplace'] = 1;
            $result['country'] = $text('country');
            $result['state'] = $text('state') === 'National curriculum' ? '' : $text('state');
            $result['joblevel'] = $text('level');
        }
        $lines = [];
        $who = trim(implode(' ', array_filter([$text('level'), $text('subject')])));
        $where = implode(', ', array_filter([$text('sector'), $text('country')]));
        if ($who !== '' || $where !== '') {
            $lines[] = 'Learners: ' . trim($who . ($where !== '' ? ' (' . $where . ')' : '')) . '.';
        }
        if ($text('learnerProfile') !== '') {
            $lines[] = 'About the learners: ' . $text('learnerProfile');
        }
        $language = $text('language') !== '' ? $text('language') : 'English';
        $lines[] = 'Write in ' . $language . ($text('spelling') !== '' ? ' with ' . $text('spelling') . ' spelling' : '') . '.';
        $notes = trim(clean_param((string)($brief['teacherNotes'] ?? ''), PARAM_TEXT));
        if ($notes !== '') {
            $lines[] = 'Teacher instructions: ' . $notes;
        }
        $result['instructions'] = \core_text::substr(implode("\n", $lines), 0, 2000);
        return $result;
    }

    /**
     * Extract the topic list from a brief.
     *
     * @param array $brief The Course Builder brief.
     * @return array The topics, one per learning outcome.
     */
    private static function topics(array $brief): array {
        $lesson = is_array($brief['lesson'] ?? null) ? $brief['lesson'] : [];
        $topics = [];
        foreach (($lesson['outcomes'] ?? []) as $outcome) {
            $text = is_array($outcome) ? trim((string)($outcome['text'] ?? '')) : trim((string)$outcome);
            if ($text !== '') {
                $topics[] = clean_param($text, PARAM_TEXT);
            }
        }
        if (!$topics && !empty($lesson['title'])) {
            $topics[] = clean_param((string)$lesson['title'], PARAM_TEXT);
        }
        return array_values(array_unique(array_slice($topics, 0, 20)));
    }

    /**
     * Whether this adapter can run on the current site.
     *
     * @return bool True when AI Course Builder can build knowledge checks here.
     */
    public static function is_available(): bool {
        return class_exists('\local_aicoursebuilder\local\generator_runtime')
            && \local_aicoursebuilder\local\generator_runtime::component_available(self::COMPONENT);
    }

    /**
     * Adapter entry point for the question-count estimate.
     *
     * @param array $brief The Course Builder brief.
     * @return int The number of questions.
     */
    public static function estimate(array $brief): int {
        return \local_aicoursebuilder\local\generator_runtime::estimate(self::COMPONENT, $brief);
    }

    /**
     * Adapter entry point for starting generation.
     *
     * @param int $cmid Course module ID.
     * @param array $brief The Course Builder brief.
     * @return string The job ID to poll.
     */
    public static function generate(int $cmid, array $brief): string {
        return \local_aicoursebuilder\local\generator_runtime::generate(self::COMPONENT, $cmid, $brief);
    }

    /**
     * Adapter entry point for polling a job.
     *
     * @param int $cmid Course module ID.
     * @param string $jobid The job ID.
     * @return array Status array with status, retryable and either result or error.
     */
    public static function status(int $cmid, string $jobid): array {
        return \local_aicoursebuilder\local\generator_runtime::status(self::COMPONENT, $cmid, $jobid);
    }
}
