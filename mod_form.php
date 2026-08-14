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
 * AI Knowledge Check instance add/edit form.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Module instance settings form.
 */
class mod_aiknowledgecheck_mod_form extends moodleform_mod {
    /**
     * Defines forms elements.
     */
    public function definition() {
        global $CFG;
        $mform = $this->_form;

        // General settings.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Name field.
        $mform->addElement('text', 'name', get_string('knowledgecheckname', 'mod_aiknowledgecheck'), ['size' => '64']);
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // Description.
        $this->standard_intro_elements();

        // ---------------------------------------------------------------
        // Survey Mode settings.
        // ---------------------------------------------------------------
        $mform->addElement('header', 'surveymodehdr', get_string('surveymode_header', 'mod_aiknowledgecheck'));
        $mform->setExpanded('surveymodehdr', false);

        $mform->addElement('advcheckbox', 'surveymode', get_string('surveymode', 'mod_aiknowledgecheck'), '', [], [0, 1]);
        $mform->setDefault('surveymode', 0);
        $mform->addHelpButton('surveymode', 'surveymode', 'mod_aiknowledgecheck');

        $scaleoptions = [
            'likert5agree'  => get_string('scale_likert5agree', 'mod_aiknowledgecheck'),
            'likert5sat'    => get_string('scale_likert5sat',   'mod_aiknowledgecheck'),
            'likert5freq'   => get_string('scale_likert5freq',  'mod_aiknowledgecheck'),
            'likert5qual'   => get_string('scale_likert5qual',  'mod_aiknowledgecheck'),
            'likert5imp'    => get_string('scale_likert5imp',   'mod_aiknowledgecheck'),
            'likert4agree'  => get_string('scale_likert4agree', 'mod_aiknowledgecheck'),
            'yesno'         => get_string('scale_yesno',        'mod_aiknowledgecheck'),
            'yesnounsure'   => get_string('scale_yesnounsure',  'mod_aiknowledgecheck'),
            'nps5'          => get_string('scale_nps5',         'mod_aiknowledgecheck'),
        ];
        $mform->addElement('select', 'surveyscale', get_string('surveyscale', 'mod_aiknowledgecheck'), $scaleoptions);
        $mform->setDefault('surveyscale', 'likert5agree');
        $mform->addHelpButton('surveyscale', 'surveyscale', 'mod_aiknowledgecheck');
        $mform->hideIf('surveyscale', 'surveymode', 'notchecked');

        // ---------------------------------------------------------------
        // Attempt settings header.
        // ---------------------------------------------------------------
        $mform->addElement('header', 'attemptsettings', get_string('attemptsettings', 'mod_aiknowledgecheck'));

        // Maximum attempts field.
        $mform->addElement('text', 'maxattempts', get_string('attemptlimit', 'mod_aiknowledgecheck'));
        $mform->setType('maxattempts', PARAM_INT);
        $mform->setDefault('maxattempts', 0);
        $mform->addHelpButton('maxattempts', 'attemptlimit', 'mod_aiknowledgecheck');

        // After completion behaviour.
        $aftercompletionoptions = [
            'restart' => get_string('aftercompletion_restart', 'mod_aiknowledgecheck'),
            'lock'    => get_string('aftercompletion_lock', 'mod_aiknowledgecheck'),
        ];
        $mform->addElement('select', 'aftercompletion', get_string('aftercompletion', 'mod_aiknowledgecheck'), $aftercompletionoptions);
        $mform->setDefault('aftercompletion', 'restart');
        $mform->addHelpButton('aftercompletion', 'aftercompletion', 'mod_aiknowledgecheck');

        // CC Email for notifications.
        $mform->addElement('text', 'ccemail', get_string('ccemail', 'mod_aiknowledgecheck'), ['size' => '64']);
        $mform->setType('ccemail', PARAM_TEXT);
        $mform->addHelpButton('ccemail', 'ccemail', 'mod_aiknowledgecheck');

        // Grade settings header.
        $mform->addElement('header', 'gradesettings', get_string('gradesettings', 'mod_aiknowledgecheck'));

        $mform->addElement('modgrade', 'grade', get_string('maximumgrade', 'mod_aiknowledgecheck'));
        $mform->setDefault('grade', 100);

        // Passing grade (numeric value, e.g. 80 out of 100).
        $mform->addElement('text', 'gradepass', get_string('gradetopass', 'mod_aiknowledgecheck'));
        $mform->setType('gradepass', PARAM_FLOAT);
        $mform->setDefault('gradepass', 0);
        $mform->addHelpButton('gradepass', 'passinggrade', 'mod_aiknowledgecheck');

        $mform->addElement('header', 'videogatehdr', get_string('videogate_header', 'mod_aiknowledgecheck'));

        $mform->addElement('text', 'videourl', get_string('videourl', 'mod_aiknowledgecheck'), ['size' => '80']);
        $mform->setType('videourl', PARAM_URL);
        $mform->addHelpButton('videourl', 'videourl', 'mod_aiknowledgecheck');

        $requirementoptions = [
            'none'    => get_string('videoreq_none', 'mod_aiknowledgecheck'),
            'seconds' => get_string('videoreq_seconds', 'mod_aiknowledgecheck'),
            'full'    => get_string('videoreq_full', 'mod_aiknowledgecheck'),
        ];
        $mform->addElement('select', 'videorequirement', get_string('videorequirement', 'mod_aiknowledgecheck'), $requirementoptions);
        $mform->setDefault('videorequirement', 'none');
        $mform->addHelpButton('videorequirement', 'videorequirement', 'mod_aiknowledgecheck');

        $mform->addElement('text', 'videominseconds', get_string('videominseconds', 'mod_aiknowledgecheck'));
        $mform->setType('videominseconds', PARAM_INT);
        $mform->setDefault('videominseconds', 0);
        $mform->hideIf('videominseconds', 'videorequirement', 'neq', 'seconds');
        $mform->addHelpButton('videominseconds', 'videominseconds', 'mod_aiknowledgecheck');

        $mform->addElement('advcheckbox', 'showvideoduringquiz', get_string('showvideoduringquiz', 'mod_aiknowledgecheck'), '', [], [0, 1]);
        $mform->setDefault('showvideoduringquiz', 0);
        $mform->addHelpButton('showvideoduringquiz', 'showvideoduringquiz', 'mod_aiknowledgecheck');
        $mform->hideIf('showvideoduringquiz', 'videourl', 'eq', '');

        // Show clickable chapter timestamp links per question.
        $mform->addElement('advcheckbox', 'showchapterstamps', get_string('showchapterstamps', 'mod_aiknowledgecheck'), '', [], [0, 1]);
        $mform->setDefault('showchapterstamps', 0);
        $mform->addHelpButton('showchapterstamps', 'showchapterstamps', 'mod_aiknowledgecheck');
        $mform->hideIf('showchapterstamps', 'videourl', 'eq', '');

        $mform->addElement('header', 'audiogatehdr', get_string('audiogate_header', 'mod_aiknowledgecheck'));

        $mform->addElement('text', 'audiourl', get_string('audiourl', 'mod_aiknowledgecheck'), ['size' => '80']);
        $mform->setType('audiourl', PARAM_URL);
        $mform->addHelpButton('audiourl', 'audiourl', 'mod_aiknowledgecheck');

        $audiorequirementoptions = [
            'none'    => get_string('audioreq_none', 'mod_aiknowledgecheck'),
            'seconds' => get_string('audioreq_seconds', 'mod_aiknowledgecheck'),
            'full'    => get_string('audioreq_full', 'mod_aiknowledgecheck'),
        ];
        $mform->addElement('select', 'audiorequirement', get_string('audiorequirement', 'mod_aiknowledgecheck'), $audiorequirementoptions);
        $mform->setDefault('audiorequirement', 'none');
        $mform->addHelpButton('audiorequirement', 'audiorequirement', 'mod_aiknowledgecheck');

        $mform->addElement('text', 'audiominseconds', get_string('audiominseconds', 'mod_aiknowledgecheck'));
        $mform->setType('audiominseconds', PARAM_INT);
        $mform->setDefault('audiominseconds', 0);
        $mform->hideIf('audiominseconds', 'audiorequirement', 'neq', 'seconds');
        $mform->addHelpButton('audiominseconds', 'audiominseconds', 'mod_aiknowledgecheck');

        $mform->addElement('header', 'imagegatehdr', get_string('imagegate_header', 'mod_aiknowledgecheck'));

        $fileoptions = ['subdirs' => 0, 'maxfiles' => 1, 'accepted_types' => ['image']];
        $mform->addElement('filemanager', 'imagegate_filemanager',
            get_string('imagegate_image', 'mod_aiknowledgecheck'), null, $fileoptions);
        $mform->addHelpButton('imagegate_filemanager', 'imagegate_image', 'mod_aiknowledgecheck');

        // Standard elements.
        $this->standard_coursemodule_elements();

        // Action buttons.
        $this->add_action_buttons();
    }

    /**
     * Add completion rules for this activity.
     *
     * @return array Array of completion rule elements.
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $suffix = $this->get_suffix();

        $mform->addElement('checkbox', 'completionallcorrect' . $suffix, 
            get_string('completionallcorrect', 'mod_aiknowledgecheck'));
        $mform->setDefault('completionallcorrect' . $suffix, 0);
        $mform->addHelpButton('completionallcorrect' . $suffix, 'completionallcorrect', 'mod_aiknowledgecheck');

        return ['completionallcorrect' . $suffix];
    }

    /**
     * Check if a completion rule is enabled.
     *
     * @param array $data Form data.
     * @return bool True if any completion rule is enabled.
     */
    public function completion_rule_enabled($data) {
        $suffix = $this->get_suffix();
        return !empty($data['completionallcorrect' . $suffix]);
    }

    /**
     * Post-process form data before saving.
     *
     * @param object $data Form data object.
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);
        $suffix = $this->get_suffix();
        $data->completionallcorrect = !empty($data->{"completionallcorrect$suffix"}) ? 1 : 0;

        // Normalise gradepass to a float for the gradebook.
        $data->gradepass = !empty($data->gradepass) ? round((float)$data->gradepass, 5) : 0.0;
        // Keep passinggrade column in sync for backwards compatibility (integer column).
        $data->passinggrade = (int)round($data->gradepass);
    }

    /**
     * Pre-process default values before displaying the form.
     *
     * @param array $defaultvalues Reference to default values array.
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);
        $suffix = $this->get_suffix();
        if (isset($defaultvalues['completionallcorrect'])) {
            $defaultvalues["completionallcorrect$suffix"] = $defaultvalues['completionallcorrect'];
        }

        // Load existing image gate file into draft area for filemanager.
        $fileoptions = ['subdirs' => 0, 'maxfiles' => 1, 'accepted_types' => ['image']];
        $draftitemid = file_get_submitted_draft_itemid('imagegate_filemanager');
        if (!empty($defaultvalues['coursemodule'])) {
            $context = context_module::instance($defaultvalues['coursemodule']);
            file_prepare_draft_area($draftitemid, $context->id, 'mod_aiknowledgecheck', 'imagegate', 0, $fileoptions);
        } else {
            file_prepare_draft_area($draftitemid, null, 'mod_aiknowledgecheck', 'imagegate', 0, $fileoptions);
        }
        $defaultvalues['imagegate_filemanager'] = $draftitemid;

        if (!isset($defaultvalues['grade']) || (int)$defaultvalues['grade'] <= 0) {
            $defaultvalues['grade'] = 100;
        }

        // Load gradepass from the gradebook grade_items table (authoritative source).
        if (isset($defaultvalues['instance'])) {
            global $CFG;
            require_once($CFG->libdir . '/gradelib.php');
            $gradeitem = grade_item::fetch([
                'itemtype' => 'mod',
                'itemmodule' => 'aiknowledgecheck',
                'iteminstance' => $defaultvalues['instance'],
                'courseid' => $defaultvalues['course'],
                'itemnumber' => 0,
            ]);
            if ($gradeitem && $gradeitem->gradepass > 0) {
                $defaultvalues['gradepass'] = format_float($gradeitem->gradepass, 5, true, true);
            } else {
                $defaultvalues['gradepass'] = 0;
            }
            // Sync passinggrade to prevent stale values.
            $defaultvalues['passinggrade'] = (int)round($gradeitem ? (float)$gradeitem->gradepass : 0);
        }
    }

    /**
     * Validate form data.
     *
     * @param array $data Form data.
     * @param array $files Uploaded files.
     * @return array Validation errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // Validate maxattempts is non-negative.
        if (isset($data['maxattempts']) && $data['maxattempts'] < 0) {
            $errors['maxattempts'] = get_string('error:negativeattempts', 'mod_aiknowledgecheck');
        }

        // Validate gradepass is a valid number within range.
        if (!empty($data['gradepass'])) {
            $gradepass = unformat_float($data['gradepass'], true);
            if ($gradepass === false || $gradepass < 0) {
                $errors['gradepass'] = get_string('error:invalidgradepass', 'mod_aiknowledgecheck');
            } else {
                $grademax = isset($data['grade']) ? (int)$data['grade'] : 100;
                if ($grademax > 0 && $gradepass > $grademax) {
                    $errors['gradepass'] = get_string('error:gradepasstoohigh', 'mod_aiknowledgecheck');
                }
            }
        }

        if (!empty($data['videourl'])) {
            $url = $data['videourl'];
            if (!preg_match('/^https?:\/\/(www\.)?(youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/|youtube\.com\/shorts\/)/', $url)) {
                $errors['videourl'] = get_string('error:invalidvideourl', 'mod_aiknowledgecheck');
            }
        }

        if (isset($data['videorequirement']) && $data['videorequirement'] === 'seconds') {
            if (empty($data['videominseconds']) || (int)$data['videominseconds'] <= 0) {
                $errors['videominseconds'] = get_string('error:videominseconds', 'mod_aiknowledgecheck');
            }
        }

        if (!empty($data['audiourl'])) {
            $aurl = $data['audiourl'];
            if (!preg_match('/^https?:\/\/.+/', $aurl)) {
                $errors['audiourl'] = get_string('error:invalidaudiourl', 'mod_aiknowledgecheck');
            }
        }

        if (isset($data['audiorequirement']) && $data['audiorequirement'] === 'seconds') {
            if (empty($data['audiominseconds']) || (int)$data['audiominseconds'] <= 0) {
                $errors['audiominseconds'] = get_string('error:audiominseconds', 'mod_aiknowledgecheck');
            }
        }

        // Validate CC email format (if provided).
        if (!empty($data['ccemail'])) {
            // Allow comma-separated emails.
            $emails = array_map('trim', explode(',', $data['ccemail']));
            foreach ($emails as $email) {
                if (!validate_email($email)) {
                    $errors['ccemail'] = get_string('error:invalidemail', 'mod_aiknowledgecheck');
                    break;
                }
            }
        }

        return $errors;
    }
}
