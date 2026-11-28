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

namespace mod_aiknowledgecheck\local;

use stdClass;

/**
 * Responsible AI controls: teacher approval, the site-wide pause, the AI activity log and oversight figures.
 *
 * These support the duties of the school or RTO running the plugin under the EU AI Act (human oversight, logs,
 * informing learners) and the provider's transparency duties. See docs/eu-ai-act.md.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class responsibleai {
    /** Log action: questions written by AI (generate). */
    const ACTION_GENERATED = 'generated';
    /** Log action: questions rewritten by AI (regenerate). */
    const ACTION_REGENERATED = 'regenerated';
    /** Log action: explanations rewritten by AI. */
    const ACTION_INSTRUCTIONS = 'instructions';
    /** Log action: a picture made by AI. */
    const ACTION_IMAGE = 'image';
    /** Log action: narration made by AI. */
    const ACTION_VOICE = 'voice';
    /** Log action: questions saved from the teacher's editor. */
    const ACTION_EDITED = 'edited';
    /** Log action: questions approved for learners. */
    const ACTION_APPROVED = 'approved';
    /** Log action: approval withdrawn. */
    const ACTION_WITHDRAWN = 'withdrawn';
    /** Log action: an AI request refused because AI is paused on the site. */
    const ACTION_BLOCKED = 'blocked';

    /** Shortest log retention allowed, in days (Article 26(6): at least six months). */
    const MIN_RETENTION_DAYS = 183;

    /**
     * Whether an administrator has paused new AI work on the site.
     *
     * @return bool
     */
    public static function is_paused(): bool {
        return !empty(get_config('mod_aiknowledgecheck', 'aipaused'));
    }

    /**
     * Whether learners only see questions after a teacher approves them.
     *
     * @return bool
     */
    public static function approval_required(): bool {
        $value = get_config('mod_aiknowledgecheck', 'requireapproval');
        return $value === false ? true : !empty($value);
    }

    /**
     * Whether learners are told the activity uses AI-generated content.
     *
     * @return bool
     */
    public static function learner_notice_enabled(): bool {
        $value = get_config('mod_aiknowledgecheck', 'learnernotice');
        return $value === false ? true : !empty($value);
    }

    /**
     * How long the AI activity log is kept, in days (never less than six months).
     *
     * @return int
     */
    public static function retention_days(): int {
        $value = (int)get_config('mod_aiknowledgecheck', 'logretention');
        if ($value <= 0) {
            $value = 365;
        }
        return max(self::MIN_RETENTION_DAYS, $value);
    }

    /**
     * Throw if AI work is paused, after logging the refused request.
     *
     * @param stdClass $knowledgecheck Activity record.
     * @param string $what The kind of AI work that was asked for.
     * @return void
     * @throws \moodle_exception When paused.
     */
    public static function require_not_paused(stdClass $knowledgecheck, string $what): void {
        if (!self::is_paused()) {
            return;
        }
        self::log($knowledgecheck->id, self::ACTION_BLOCKED, ['request' => $what]);
        throw new \moodle_exception('aipaused_error', 'mod_aiknowledgecheck');
    }

    /**
     * Where this kind of AI work runs: LMS Labs credits or the site's own AI provider.
     *
     * @param string $kind text, voice or image.
     * @return string credits or ownai.
     */
    public static function runs_on(string $kind): string {
        try {
            $state = \mod_aiknowledgecheck\byok::state();
        } catch (\Throwable $e) {
            return 'credits';
        }
        return !empty($state[$kind]) ? 'ownai' : 'credits';
    }

    /**
     * Record one event in the AI activity log (Article 12, Article 26(6)).
     *
     * @param int $knowledgecheckid Activity instance id.
     * @param string $action One of the ACTION_ constants.
     * @param array $detail Small, non-personal facts about the event (counts, provider route, request id).
     * @param int|null $userid Who did it; defaults to the current user.
     * @return int New log id.
     */
    public static function log(int $knowledgecheckid, string $action, array $detail = [], ?int $userid = null): int {
        global $DB, $USER;
        $record = (object)[
            'aiknowledgecheckid' => $knowledgecheckid,
            'userid' => $userid ?? (int)($USER->id ?? 0),
            'action' => substr($action, 0, 30),
            'runson' => substr((string)($detail['runson'] ?? ''), 0, 10),
            'detail' => $detail ? json_encode($detail) : null,
            'timecreated' => time(),
        ];
        return (int)$DB->insert_record('aiknowledgecheck_ailog', $record);
    }

    /**
     * Fingerprint of a question's wording, options, answer key and explanations.
     *
     * Used to tell whether a teacher changed what the AI wrote before approving it.
     *
     * @param stdClass $q Question record (or a record built for insert).
     * @return string 40-character hash.
     */
    public static function content_hash(stdClass $q): string {
        $parts = [trim((string)($q->questiontext ?? ''))];
        for ($i = 1; $i <= 5; $i++) {
            $parts[] = trim((string)($q->{'answer' . $i} ?? ''));
        }
        $parts[] = (string)(int)($q->correctanswer ?? 0);
        for ($i = 1; $i <= 4; $i++) {
            $parts[] = trim((string)($q->{'feedback' . $i} ?? ''));
        }
        $parts[] = (string)($q->questiontype ?? 'scale');
        return sha1(implode("\x1f", $parts));
    }

    /**
     * Whether learners may see and attempt the activity's questions.
     *
     * @param stdClass $knowledgecheck Activity record.
     * @return bool
     */
    public static function is_released(stdClass $knowledgecheck): bool {
        if (!self::approval_required()) {
            return true;
        }
        return !empty($knowledgecheck->timeapproved);
    }

    /**
     * Withdraw approval because the AI has replaced the questions.
     *
     * @param stdClass $knowledgecheck Activity record (updated in place).
     * @return void
     */
    public static function reset_approval(stdClass $knowledgecheck): void {
        global $DB;
        if (empty($knowledgecheck->timeapproved) && empty($knowledgecheck->approvedby)) {
            return;
        }
        $DB->update_record('aiknowledgecheck', (object)[
            'id' => $knowledgecheck->id,
            'approvedby' => 0,
            'timeapproved' => 0,
        ]);
        $knowledgecheck->approvedby = 0;
        $knowledgecheck->timeapproved = 0;
    }

    /**
     * Oversight figures for the activity: how much of the AI's work a person changed.
     *
     * A share of 0% changed across many activities can be a sign of rubber-stamping (Article 14(4)(b)).
     *
     * @param int $knowledgecheckid Activity instance id.
     * @return array total, ai, changed, teacher, changedpct (null when no AI questions have a fingerprint).
     */
    public static function oversight_stats(int $knowledgecheckid): array {
        global $DB;
        $rows = $DB->get_records('aiknowledgecheck_questions', ['aiknowledgecheckid' => $knowledgecheckid]);
        $stats = ['total' => count($rows), 'ai' => 0, 'changed' => 0, 'teacher' => 0, 'tracked' => 0, 'changedpct' => null];
        foreach ($rows as $row) {
            if (empty($row->aigenerated)) {
                $stats['teacher']++;
                continue;
            }
            $stats['ai']++;
            if (!empty($row->aihash)) {
                $stats['tracked']++;
                if ($row->aihash !== self::content_hash($row)) {
                    $stats['changed']++;
                }
            }
        }
        if ($stats['tracked'] > 0) {
            $stats['changedpct'] = (int)round(100 * $stats['changed'] / $stats['tracked']);
        }
        return $stats;
    }

    /**
     * Name of the person who approved the questions, or '' for none / approval carried over on upgrade.
     *
     * @param stdClass $knowledgecheck Activity record.
     * @return string
     */
    public static function approver_name(stdClass $knowledgecheck): string {
        global $DB;
        if (empty($knowledgecheck->approvedby)) {
            return '';
        }
        $user = $DB->get_record('user', ['id' => $knowledgecheck->approvedby, 'deleted' => 0]);
        return $user ? fullname($user) : '';
    }

    /**
     * Delete log entries older than the retention period.
     *
     * @return int Number of entries deleted.
     */
    public static function purge_old_log(): int {
        global $DB;
        $cutoff = time() - self::retention_days() * DAYSECS;
        $count = $DB->count_records_select('aiknowledgecheck_ailog', 'timecreated < ?', [$cutoff]);
        if ($count) {
            $DB->delete_records_select('aiknowledgecheck_ailog', 'timecreated < ?', [$cutoff]);
        }
        return $count;
    }
}
