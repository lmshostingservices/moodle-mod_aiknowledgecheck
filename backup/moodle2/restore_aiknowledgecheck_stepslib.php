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
 * Restore steps for mod_aiknowledgecheck.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Structure step to restore one knowledgecheck activity.
 */
class restore_aiknowledgecheck_activity_structure_step extends restore_activity_structure_step {
    /**
     * Define the structure of this step.
     *
     * @return mixed The structure this step defines.
     */
    protected function define_structure() {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('aiknowledgecheck', '/activity/aiknowledgecheck');
        $paths[] = new restore_path_element('aiknowledgecheck_question', '/activity/aiknowledgecheck/questions/question');

        if ($userinfo) {
            $paths[] = new restore_path_element('aiknowledgecheck_attempt', '/activity/aiknowledgecheck/attempts/attempt');
            $paths[] = new restore_path_element('aiknowledgecheck_override', '/activity/aiknowledgecheck/overrides/override');
        }

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restore one aiknowledgecheck activity record.
     *
     * @param mixed $data The data value.
     */
    protected function process_aiknowledgecheck($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        if (!isset($data->maxattempts)) {
            $data->maxattempts = 0;
        }
        if (!isset($data->questioncount)) {
            $data->questioncount = 0;
        }
        if (!isset($data->passinggrade)) {
            $data->passinggrade = 0;
        }
        if (!isset($data->completionallcorrect)) {
            $data->completionallcorrect = 0;
        }
        if (!isset($data->completionpassgrade)) {
            $data->completionpassgrade = 0;
        }
        if (!isset($data->ccemail)) {
            $data->ccemail = '';
        }

        $newitemid = $DB->insert_record('aiknowledgecheck', $data);
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Restore one question belonging to the activity.
     *
     * @param mixed $data The data value.
     */
    protected function process_aiknowledgecheck_question($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->aiknowledgecheckid = $this->get_new_parentid('aiknowledgecheck');

        $newitemid = $DB->insert_record('aiknowledgecheck_questions', $data);
        $this->set_mapping('aiknowledgecheck_question', $oldid, $newitemid);
    }

    /**
     * Restore one student attempt.
     *
     * @param mixed $data The data value.
     */
    protected function process_aiknowledgecheck_attempt($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->aiknowledgecheckid = $this->get_new_parentid('aiknowledgecheck');
        $data->userid = $this->get_mappingid('user', $data->userid);

        if (!empty($data->answers)) {
            $answers = json_decode($data->answers, true);
            if (is_array($answers)) {
                $newanswers = [];
                foreach ($answers as $oldqid => $answerdata) {
                    $newqid = $this->get_mappingid('aiknowledgecheck_question', $oldqid);
                    if ($newqid) {
                        $newanswers[$newqid] = $answerdata;
                    } else {
                        $newanswers[$oldqid] = $answerdata;
                    }
                }
                $data->answers = json_encode($newanswers);
            }
        }

        if (!empty($data->timecreated)) {
            $data->timecreated = $this->apply_date_offset($data->timecreated);
        }
        if (!empty($data->timemodified)) {
            $data->timemodified = $this->apply_date_offset($data->timemodified);
        }
        if (!empty($data->timestarted)) {
            $data->timestarted = $this->apply_date_offset($data->timestarted);
        }
        if (!empty($data->timeended)) {
            $data->timeended = $this->apply_date_offset($data->timeended);
        }

        $newitemid = $DB->insert_record('aiknowledgecheck_attempts', $data);
        $this->set_mapping('aiknowledgecheck_attempt', $oldid, $newitemid);
    }

    /**
     * Restore one attempt override.
     *
     * @param mixed $data The data value.
     */
    protected function process_aiknowledgecheck_override($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->aiknowledgecheckid = $this->get_new_parentid('aiknowledgecheck');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $newitemid = $DB->insert_record('aiknowledgecheck_overrides', $data);
        $this->set_mapping('aiknowledgecheck_override', $oldid, $newitemid);
    }

    /**
     * Run the tasks that must happen once the whole activity is restored.
     */
    protected function after_execute() {
        $this->add_related_files('mod_aiknowledgecheck', 'intro', null);
        // Restore the per-question image-gate images backed up above.
        $this->add_related_files('mod_aiknowledgecheck', 'imagegate', null);
    }
}
