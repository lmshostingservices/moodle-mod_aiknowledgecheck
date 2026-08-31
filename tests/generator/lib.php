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
 * Test data generator for mod_aiknowledgecheck.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Creates activities, questions and attempts without calling the remote AI service.
 */
class mod_aiknowledgecheck_generator extends testing_module_generator {
    /**
     * Create a new activity instance, filling in every column install.xml requires.
     *
     * @param array|stdClass|null $record Values to override the defaults.
     * @param array|null $options Standard module generator options.
     * @return stdClass The created instance record.
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object)(array)$record;

        $defaults = [
            'name' => 'Knowledge check',
            'intro' => 'Test activity',
            'introformat' => FORMAT_HTML,
            'grade' => 100,
            'maxattempts' => 3,
            'questioncount' => 0,
            'passinggrade' => 50,
            'completionallcorrect' => 0,
            'completionpassgrade' => 0,
            'ccemail' => '',
            'voiceoverenabled' => 0,
            'voicelanguage' => 'en-AU',
            'voicegender' => 'FEMALE',
            'voicestyle' => 'zephyr',
            'videourl' => '',
            'videorequirement' => 'seconds',
            'videominseconds' => 0,
            'showvideoduringquiz' => 0,
            'showchapterstamps' => 0,
            'audiourl' => '',
            'audiorequirement' => 'seconds',
            'audiominseconds' => 0,
            'imageurl' => '',
            'aftercompletion' => 'restart',
            'sourcecontext' => '',
            'surveymode' => 0,
            'surveyscale' => 'likert5agree',
        ];
        foreach ($defaults as $field => $value) {
            if (!isset($record->{$field})) {
                $record->{$field} = $value;
            }
        }

        return parent::create_instance($record, (array)$options);
    }

    /**
     * Add one question to an activity.
     *
     * @param int $instanceid The aiknowledgecheck instance ID.
     * @param int $number 1-based question number.
     * @param array $overrides Column values to override.
     * @return int The new question record's ID.
     */
    public function create_question(int $instanceid, int $number, array $overrides = []): int {
        global $DB;

        $defaults = [
            'aiknowledgecheckid' => $instanceid,
            'questionnumber' => $number,
            'questiontext' => "Question $number?",
            'answer1' => "A$number",
            'answer2' => "B$number",
            'answer3' => "C$number",
            'answer4' => "D$number",
            'answer5' => '',
            'correctanswer' => 0,
            'feedback1' => "A$number is correct.",
            'feedback2' => "B$number is wrong.",
            'feedback3' => "C$number is wrong.",
            'feedback4' => "D$number is wrong.",
            'audiodata' => '',
            'mappingtopic' => "Topic $number",
            'mappingcriteria' => "PC1.$number",
            'timestamp_seconds' => null,
            'imageurl' => '',
            'imageenabled' => 0,
            'questiontype' => 'mcq',
            'questionvideourl' => '',
            'questionvideoenabled' => 0,
            'questionaudiourl' => '',
            'questionaudioenabled' => 0,
        ];
        $record = (object)array_merge($defaults, $overrides);

        $id = $DB->insert_record('aiknowledgecheck_questions', $record);
        $questioncount = $DB->count_records(
            'aiknowledgecheck_questions',
            ['aiknowledgecheckid' => $instanceid]
        );
        $DB->set_field('aiknowledgecheck', 'questioncount', $questioncount, ['id' => $instanceid]);
        return $id;
    }

    /**
     * Add several questions at once.
     *
     * @param int $instanceid The aiknowledgecheck instance ID.
     * @param int $count How many questions to create.
     * @return array The created question IDs, in order.
     */
    public function create_questions(int $instanceid, int $count): array {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = $this->create_question($instanceid, $i);
        }
        return $ids;
    }

    /**
     * Add an attempt row directly, bypassing the external services.
     *
     * @param int $instanceid The aiknowledgecheck instance ID.
     * @param int $userid The attempting user.
     * @param array $overrides Column values to override.
     * @return int The new attempt record's ID.
     */
    public function create_attempt(int $instanceid, int $userid, array $overrides = []): int {
        global $DB;

        $now = time();
        $defaults = [
            'aiknowledgecheckid' => $instanceid,
            'userid' => $userid,
            'currentquestion' => 0,
            'answers' => '{}',
            'correctcount' => 0,
            'totalcount' => 0,
            'status' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'timestarted' => $now,
            'timeended' => null,
        ];
        $record = (object)array_merge($defaults, $overrides);

        return $DB->insert_record('aiknowledgecheck_attempts', $record);
    }

    /**
     * Grant a user extra attempts on an activity.
     *
     * @param int $instanceid The aiknowledgecheck instance ID.
     * @param int $userid The user receiving the override.
     * @param int $extraattempts How many extra attempts to grant.
     * @return int The new override record's ID.
     */
    public function create_override(int $instanceid, int $userid, int $extraattempts): int {
        global $DB;

        $now = time();
        $record = (object)[
            'aiknowledgecheckid' => $instanceid,
            'userid' => $userid,
            'extraattempts' => $extraattempts,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        return $DB->insert_record('aiknowledgecheck_overrides', $record);
    }
}
