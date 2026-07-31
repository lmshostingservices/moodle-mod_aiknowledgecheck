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
 * Library of functions for AI Knowledge Check.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Returns the information on whether the module supports a feature.
 *
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed True if module supports feature, null if doesn't know
 */
function aiknowledgecheck_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_ASSESSMENT;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_GRADE_OUTCOMES:
            return false;
        default:
            return null;
    }
}

/**
 * Saves a new instance of the module into the database.
 *
 * @param stdClass $data Form data
 * @param mod_aiknowledgecheck_mod_form|null $mform The form
 * @return int The id of the newly inserted record
 */
function aiknowledgecheck_add_instance($data, ?object $mform = null) {
    global $DB;

    $data->timecreated = time();
    $data->timemodified = time();
    
    // Ensure optional fields have defaults.
    if (!isset($data->maxattempts)) {
        $data->maxattempts = 0;
    }
    if (!isset($data->completionallcorrect)) {
        $data->completionallcorrect = 0;
    }
    if (!isset($data->ccemail)) {
        $data->ccemail = '';
    }
    if (!isset($data->videourl)) {
        $data->videourl = '';
    }
    if (!isset($data->videorequirement)) {
        $data->videorequirement = 'none';
    }
    if (!isset($data->videominseconds)) {
        $data->videominseconds = 0;
    }
    if (!isset($data->audiourl)) {
        $data->audiourl = '';
    }
    if (!isset($data->audiorequirement)) {
        $data->audiorequirement = 'none';
    }
    if (!isset($data->audiominseconds)) {
        $data->audiominseconds = 0;
    }

    if (!isset($data->grade) || (int)$data->grade <= 0) {
        $data->grade = 100;
    }

    // imageurl will be populated after file save below; default to empty.
    if (!isset($data->imageurl)) {
        $data->imageurl = '';
    }

    $data->id = $DB->insert_record('aiknowledgecheck', $data);

    // Save image gate file from draft area to permanent filearea.
    if (!empty($data->imagegate_filemanager) && !empty($data->coursemodule)) {
        $fileoptions = ['subdirs' => 0, 'maxfiles' => 1, 'accepted_types' => ['image']];
        $context = context_module::instance($data->coursemodule);
        file_save_draft_area_files($data->imagegate_filemanager, $context->id,
            'mod_aiknowledgecheck', 'imagegate', 0, $fileoptions);
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_aiknowledgecheck', 'imagegate', 0,
            'sortorder DESC, id DESC', false);
        if (!empty($files)) {
            $file = reset($files);
            $imageurl = \moodle_url::make_pluginfile_url(
                $context->id, 'mod_aiknowledgecheck', 'imagegate', 0,
                $file->get_filepath(), $file->get_filename()
            )->out(false);
        } else {
            $imageurl = '';
        }
        $DB->set_field('aiknowledgecheck', 'imageurl', $imageurl, ['id' => $data->id]);
    }

    // Create grade item in gradebook with passing grade.
    // gradepass is set by data_postprocessing in mod_form.php as a numeric value.
    if (!isset($data->gradepass)) {
        $data->gradepass = (!empty($data->passinggrade) && (int)$data->passinggrade > 0)
            ? (float)$data->passinggrade : 0;
    }
    aiknowledgecheck_grade_item_update($data);

    return $data->id;
}

/**
 * Updates an instance of the module in the database.
 *
 * @param stdClass $data Form data
 * @param mod_aiknowledgecheck_mod_form|null $mform The form
 * @return bool Success/Failure
 */
function aiknowledgecheck_update_instance($data, ?object $mform = null) {
    global $DB;

    $data->timemodified = time();
    $data->id = $data->instance;

    // Ensure optional fields exist.
    if (!isset($data->maxattempts)) {
        $data->maxattempts = 0;
    }
    if (!isset($data->completionallcorrect)) {
        $data->completionallcorrect = 0;
    }
    if (!isset($data->ccemail)) {
        $data->ccemail = '';
    }
    if (!isset($data->videourl)) {
        $data->videourl = '';
    }
    if (!isset($data->videorequirement)) {
        $data->videorequirement = 'none';
    }
    if (!isset($data->videominseconds)) {
        $data->videominseconds = 0;
    }
    if (!isset($data->audiourl)) {
        $data->audiourl = '';
    }
    if (!isset($data->audiorequirement)) {
        $data->audiorequirement = 'none';
    }
    if (!isset($data->audiominseconds)) {
        $data->audiominseconds = 0;
    }

    if (!isset($data->grade) || (int)$data->grade <= 0) {
        $data->grade = 100;
    }

    // Save image gate file from draft area to permanent filearea.
    if (isset($data->imagegate_filemanager) && !empty($data->coursemodule)) {
        $fileoptions = ['subdirs' => 0, 'maxfiles' => 1, 'accepted_types' => ['image']];
        $context = context_module::instance($data->coursemodule);
        file_save_draft_area_files($data->imagegate_filemanager, $context->id,
            'mod_aiknowledgecheck', 'imagegate', 0, $fileoptions);
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_aiknowledgecheck', 'imagegate', 0,
            'sortorder DESC, id DESC', false);
        if (!empty($files)) {
            $file = reset($files);
            $data->imageurl = \moodle_url::make_pluginfile_url(
                $context->id, 'mod_aiknowledgecheck', 'imagegate', 0,
                $file->get_filepath(), $file->get_filename()
            )->out(false);
        } else {
            $data->imageurl = '';
        }
    } else if (!isset($data->imageurl)) {
        $data->imageurl = '';
    }

    $result = $DB->update_record('aiknowledgecheck', $data);

    // Update grade item in gradebook with passing grade.
    // gradepass is set by data_postprocessing in mod_form.php as a numeric value.
    if (!isset($data->gradepass)) {
        $data->gradepass = (!empty($data->passinggrade) && (int)$data->passinggrade > 0)
            ? (float)$data->passinggrade : 0;
    }
    aiknowledgecheck_grade_item_update($data);

    return $result;
}

/**
 * Removes an instance of the module from the database.
 *
 * @param int $id Id of the module instance
 * @return bool Success/Failure
 */
function aiknowledgecheck_delete_instance($id) {
    global $DB;

    $knowledgecheck = $DB->get_record('aiknowledgecheck', ['id' => $id]);
    if (!$knowledgecheck) {
        return false;
    }

    // Delete associated records.
    $DB->delete_records('aiknowledgecheck_quizzes', ['aiknowledgecheckid' => $id]);
    $DB->delete_records('aiknowledgecheck_questions', ['aiknowledgecheckid' => $id]);
    $DB->delete_records('aiknowledgecheck_attempts', ['aiknowledgecheckid' => $id]);
    $DB->delete_records('aiknowledgecheck_overrides', ['aiknowledgecheckid' => $id]);

    // Delete grade item from gradebook.
    aiknowledgecheck_grade_item_delete($knowledgecheck);

    // Delete the instance.
    $DB->delete_records('aiknowledgecheck', ['id' => $id]);

    return true;
}

/**
 * Returns all other caps used in the module.
 *
 * @return array
 */
function aiknowledgecheck_get_extra_capabilities() {
    return ['moodle/site:accessallgroups'];
}

/**
 * Create/update grade item for given knowledge check.
 *
 * @param stdClass $knowledgecheck Knowledge check object with extra cmidnumber
 * @param mixed $grades Optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok, error code otherwise
 */
function aiknowledgecheck_grade_item_update($knowledgecheck, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $grademax = isset($knowledgecheck->grade) ? (int)$knowledgecheck->grade : 100;
    if ($grademax <= 0) {
        $grademax = 100;
    }

    $passgrade = 0;
    if (!empty($knowledgecheck->gradepass)) {
        $passgrade = (float)$knowledgecheck->gradepass;
    } else if (!empty($knowledgecheck->passinggrade) && (int)$knowledgecheck->passinggrade > 0) {
        $passgrade = (float)$knowledgecheck->passinggrade;
    }

    $params = [
        'itemname' => $knowledgecheck->name,
        'idnumber' => isset($knowledgecheck->cmidnumber) ? $knowledgecheck->cmidnumber : null,
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax' => $grademax,
        'grademin' => 0,
        'gradepass' => $passgrade,
    ];

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    $result = grade_update('mod/aiknowledgecheck', $knowledgecheck->course, 'mod', 'aiknowledgecheck',
        $knowledgecheck->id, 0, $grades, $params);

    return $result;
}

/**
 * Delete grade item for given knowledge check.
 *
 * @param stdClass $knowledgecheck Knowledge check object
 * @return int
 */
function aiknowledgecheck_grade_item_delete($knowledgecheck) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update('mod/aiknowledgecheck', $knowledgecheck->course, 'mod', 'aiknowledgecheck',
        $knowledgecheck->id, 0, null, ['deleted' => 1]);
}

/**
 * Update grades in the gradebook.
 *
 * @param stdClass $knowledgecheck Knowledge check object
 * @param int $userid Specific user only, 0 means all users
 * @param bool $nullifnone If true and student has no grade, create a null grade
 * @return void
 */
function aiknowledgecheck_update_grades($knowledgecheck, $userid = 0, $nullifnone = true) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    // First, ensure grade item exists.
    aiknowledgecheck_grade_item_update($knowledgecheck);

    // Get the best completed attempt for each user (highest percentage).
    $params = ['aiknowledgecheckid' => $knowledgecheck->id, 'status' => 1];
    $userwhere = '';
    if ($userid) {
        $userwhere = ' AND userid = :userid';
        $params['userid'] = $userid;
    }

    // Get best score per user (highest percentage correct).
    $sql = "SELECT userid, MAX(CASE WHEN totalcount > 0 THEN (correctcount * 100.0 / totalcount) ELSE 0 END) as bestgrade
              FROM {aiknowledgecheck_attempts}
             WHERE aiknowledgecheckid = :aiknowledgecheckid
               AND status = :status
               $userwhere
          GROUP BY userid";

    $usersgrades = $DB->get_records_sql($sql, $params);

    $grades = [];
    foreach ($usersgrades as $usergrade) {
        $grade = new stdClass();
        $grade->userid = $usergrade->userid;
        $grade->rawgrade = round($usergrade->bestgrade, 2);
        $grades[$usergrade->userid] = $grade;
    }

    if (empty($grades) && $nullifnone && $userid) {
        // Create null grade for this user.
        $grade = new stdClass();
        $grade->userid = $userid;
        $grade->rawgrade = null;
        $grades[$userid] = $grade;
    }

    if (!empty($grades)) {
        grade_update('mod/aiknowledgecheck', $knowledgecheck->course, 'mod', 'aiknowledgecheck',
            $knowledgecheck->id, 0, $grades);
    }
}

/**
 * Return grade for given user or all users.
 *
 * @param stdClass $knowledgecheck Knowledge check object
 * @param int $userid Optional user id, 0 means all users
 * @return array Array of grades, false if none
 */
function aiknowledgecheck_get_user_grades($knowledgecheck, $userid = 0) {
    global $DB;

    $params = ['aiknowledgecheckid' => $knowledgecheck->id, 'status' => 1];
    $userwhere = '';
    if ($userid) {
        $userwhere = ' AND userid = :userid';
        $params['userid'] = $userid;
    }

    // Get best score per user.
    $sql = "SELECT userid, MAX(CASE WHEN totalcount > 0 THEN (correctcount * 100.0 / totalcount) ELSE 0 END) as rawgrade
              FROM {aiknowledgecheck_attempts}
             WHERE aiknowledgecheckid = :aiknowledgecheckid
               AND status = :status
               $userwhere
          GROUP BY userid";

    return $DB->get_records_sql($sql, $params);
}

/**
 * Get icon mapping for font-awesome.
 *
 * @return array
 */
function mod_aiknowledgecheck_get_fontawesome_icon_map() {
    return [
        'mod_aiknowledgecheck:icon' => 'fa-question-circle',
    ];
}

/**
 * Given a course_module object, this function returns any
 * "extra" information that may be needed when printing this activity.
 *
 * @param cm_info $coursemodule The course module info
 * @return cached_cm_info|null Cached course module info or null if not available
 */
function aiknowledgecheck_get_coursemodule_info($coursemodule) {
    global $DB;

    if (!$knowledgecheck = $DB->get_record('aiknowledgecheck', ['id' => $coursemodule->instance],
            'id, name, intro, introformat, completionallcorrect')) {
        return null;
    }

    $info = new cached_cm_info();
    $info->name = $knowledgecheck->name;

    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('aiknowledgecheck', $knowledgecheck, $coursemodule->id, false);
    }

    // Populate custom completion rules.
    if ($knowledgecheck->completionallcorrect) {
        $info->customdata['customcompletionrules']['completionallcorrect'] = $knowledgecheck->completionallcorrect;
    }

    return $info;
}

/**
 * Mark the activity completed (if required) and trigger the course_module_viewed event.
 *
 * @param stdClass $knowledgecheck The knowledgecheck object
 * @param stdClass $course The course object
 * @param stdClass $cm The course module object
 * @param context_module $context The context object
 */
function aiknowledgecheck_view($knowledgecheck, $course, $cm, $context) {
    // Trigger the course_module_viewed event.
    $event = \mod_aiknowledgecheck\event\course_module_viewed::create([
        'objectid' => $knowledgecheck->id,
        'context' => $context,
    ]);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('aiknowledgecheck', $knowledgecheck);
    $event->trigger();

    // Mark as viewed for completion.
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * Called when viewing course page.
 *
 * @param cm_info $cm Course-module object
 */
function aiknowledgecheck_cm_info_view(cm_info $cm) {
    // Nothing additional needed for display.
}

/**
 * Get the effective maximum attempts for a user (base + overrides).
 *
 * @param stdClass $knowledgecheck The knowledgecheck object
 * @param int $userid The user ID
 * @return int Effective max attempts (0 = unlimited)
 */
function aiknowledgecheck_effective_maxattempts($knowledgecheck, $userid) {
    global $DB;

    $base = (int)$knowledgecheck->maxattempts;
    if ($base === 0) {
        return 0; // Unlimited.
    }

    // Check for user override.
    $override = $DB->get_record('aiknowledgecheck_overrides', [
        'aiknowledgecheckid' => $knowledgecheck->id,
        'userid' => $userid,
    ]);

    $extra = $override ? max(0, (int)$override->extraattempts) : 0;
    return $base + $extra;
}

/**
 * Count completed attempts for a user.
 *
 * @param int $aiknowledgecheckid The knowledgecheck ID
 * @param int $userid The user ID
 * @return int Number of completed attempts
 */
function aiknowledgecheck_count_attempts($aiknowledgecheckid, $userid) {
    global $DB;

    return (int)$DB->count_records('aiknowledgecheck_attempts', [
        'aiknowledgecheckid' => $aiknowledgecheckid,
        'userid' => $userid,
        'status' => 1, // Completed.
    ]);
}

/**
 * Check if user can start a new attempt.
 *
 * @param stdClass $knowledgecheck The knowledgecheck object
 * @param int $userid The user ID
 * @return bool True if can attempt, false if limit reached
 */
function aiknowledgecheck_can_attempt($knowledgecheck, $userid) {
    $maxattempts = aiknowledgecheck_effective_maxattempts($knowledgecheck, $userid);
    if ($maxattempts === 0) {
        return true; // Unlimited.
    }

    $used = aiknowledgecheck_count_attempts($knowledgecheck->id, $userid);
    return $used < $maxattempts;
}

/**
 * Send notification when user reaches max attempts.
 * Includes throttling to prevent duplicate notifications.
 *
 * @param stdClass $knowledgecheck The knowledgecheck object
 * @param stdClass $course The course object
 * @param stdClass $cm The course module object
 * @param stdClass $user The user who used all attempts
 * @return bool True if notification was sent, false if throttled
 */
function aiknowledgecheck_send_attempts_notification($knowledgecheck, $course, $cm, $user) {
    global $DB, $CFG;

    // Check for throttling - only send notification once per user per activity per effective limit.
    // Use override record to track last notification timestamp.
    $override = $DB->get_record('aiknowledgecheck_overrides', [
        'aiknowledgecheckid' => $knowledgecheck->id,
        'userid' => $user->id,
    ]);

    $maxattempts = aiknowledgecheck_effective_maxattempts($knowledgecheck, $user->id);
    $attemptsused = aiknowledgecheck_count_attempts($knowledgecheck->id, $user->id);

    // Only notify when exactly at the limit (not above due to override grants).
    if ($attemptsused != $maxattempts) {
        return false;
    }

    // Check if we already notified for this limit level.
    // Store notification tracking in a config-style key.
    $notifykey = 'notify_' . $knowledgecheck->id . '_' . $user->id . '_' . $maxattempts;
    $lastnotified = get_config('mod_aiknowledgecheck', $notifykey);

    if ($lastnotified) {
        // Already notified for this limit level.
        return false;
    }

    // Mark as notified for this limit level.
    set_config($notifykey, time(), 'mod_aiknowledgecheck');

    $context = context_module::instance($cm->id);

    // Build message data.
    $a = new stdClass();
    $a->fullname = fullname($user);
    $a->activityname = format_string($knowledgecheck->name);
    $a->coursename = format_string($course->fullname);
    $a->limit = $maxattempts;
    $a->overrideurl = (new moodle_url('/mod/aiknowledgecheck/moreattempts.php', ['id' => $cm->id]))->out(false);

    $subject = get_string('allattemptsused_subject', 'mod_aiknowledgecheck', $a);
    $body = get_string('allattemptsused_body', 'mod_aiknowledgecheck', $a);

    // Get users with viewreports capability (teachers/managers).
    $teachers = get_users_by_capability($context, 'mod/aiknowledgecheck:viewreports', 'u.*', '', '', '', '', '', false);

    // Send notification to each teacher.
    $eventdata = new \core\message\message();
    $eventdata->courseid = $course->id;
    $eventdata->component = 'mod_aiknowledgecheck';
    $eventdata->name = 'allattemptsused';
    $eventdata->userfrom = core_user::get_noreply_user();
    $eventdata->subject = $subject;
    $eventdata->fullmessage = $body;
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml = nl2br($body);
    $eventdata->smallmessage = $subject;
    $eventdata->notification = 1;
    $eventdata->contexturl = new moodle_url('/mod/aiknowledgecheck/report.php', ['id' => $cm->id, 'userid' => $user->id]);
    $eventdata->contexturlname = get_string('attemptsreport', 'mod_aiknowledgecheck');

    foreach ($teachers as $teacher) {
        $eventdata->userto = $teacher;
        message_send($eventdata);
    }

    // Send to CC email addresses if configured.
    if (!empty($knowledgecheck->ccemail)) {
        require_once($CFG->dirroot . '/lib/moodlelib.php');
        
        $emails = array_map('trim', explode(',', $knowledgecheck->ccemail));
        
        foreach ($emails as $email) {
            if (validate_email($email)) {
                $ccuser = new stdClass();
                $ccuser->email = $email;
                $ccuser->id = -1;
                $ccuser->auth = 'manual';
                $ccuser->deleted = 0;
                $ccuser->suspended = 0;
                $ccuser->mailformat = 1;
                $ccuser->emailstop = 0;
                $ccuser->firstnamephonetic = '';
                $ccuser->lastnamephonetic = '';
                $ccuser->middlename = '';
                $ccuser->alternatename = '';
                $ccuser->firstname = 'Admin';
                $ccuser->lastname = 'Notification';
                $ccuser->username = 'cc_notification';

                email_to_user(
                    $ccuser,
                    core_user::get_noreply_user(),
                    $subject,
                    $body,
                    nl2br($body)
                );
            }
        }
    }

    return true;
}

/**
 * Extends the settings navigation with the report links.
 *
 * @param settings_navigation $settingsnav The settings navigation object
 * @param navigation_node $navref The navigation node
 */
function aiknowledgecheck_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $navref) {
    global $PAGE;

    $cm = $PAGE->cm;
    if (!$cm) {
        return;
    }

    $context = context_module::instance($cm->id);

    // Add Report link for users with viewreports capability.
    if (has_capability('mod/aiknowledgecheck:viewreports', $context)) {
        $reporturl = new moodle_url('/mod/aiknowledgecheck/report.php', ['id' => $cm->id]);
        $navref->add(
            get_string('attemptsreport', 'mod_aiknowledgecheck'),
            $reporturl,
            navigation_node::TYPE_SETTING
        );
    }

    // Add More Attempts link for users with manageoverrides capability.
    if (has_capability('mod/aiknowledgecheck:manageoverrides', $context)) {
        $moreattemptsurl = new moodle_url('/mod/aiknowledgecheck/moreattempts.php', ['id' => $cm->id]);
        $navref->add(
            get_string('moreattempts', 'mod_aiknowledgecheck'),
            $moreattemptsurl,
            navigation_node::TYPE_SETTING
        );
    }
}

/**
 * Returns the lists of all browsable file areas within the given module context.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $context
 * @return array
 */
function aiknowledgecheck_get_file_areas($course, $cm, $context) {
    return [
        'imagegate' => get_string('imagegate_header', 'mod_aiknowledgecheck'),
    ];
}

/**
 * Serves files stored in the module.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool false if file not found
 */
function aiknowledgecheck_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $CFG;

    if ($filearea !== 'imagegate') {
        return false;
    }

    require_login($course, true, $cm);

    $itemid = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_aiknowledgecheck', 'imagegate',
        $itemid, $filepath, $filename);

    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, null, 0, $forcedownload, $options);
}

/**
 * Callback function that returns the completion rule descriptions relative to $cm.
 *
 * @param cm_info|stdClass $cm course-module object
 * @return array $descriptions
 */
function mod_aiknowledgecheck_get_completion_active_rule_descriptions($cm) {
    global $DB;

    $descriptions = [];
    $knowledgecheck = $DB->get_record('aiknowledgecheck', ['id' => $cm->instance]);

    if ($knowledgecheck && !empty($knowledgecheck->completionallcorrect)) {
        $descriptions[] = get_string('completiondetail:completionallcorrect', 'mod_aiknowledgecheck');
    }

    return $descriptions;
}
