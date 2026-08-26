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
 * Language strings for AI Knowledge Check.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Module info.
$string['modulename'] = 'AI Knowledge Check';
$string['modulenameplural'] = 'AI Knowledge Checks';
$string['modulename_help'] = 'AI Knowledge Check generates topic-by-topic multiple-choice quizzes with AI voiceover explanations spoken aloud for every answer option, including why wrong answers are incorrect.

Teachers enter a list of topics (one per line) and the number of questions per topic. The AI generates 4-option MCQ questions with clear explanations for each option. With voiceover enabled, every explanation is spoken using Google Chirp 3 HD text-to-speech in the teacher\'s selected language (52 languages supported).

A Video Gate lets teachers paste a YouTube URL and require students to watch a set number of seconds — or the entire video — before the quiz start button unlocks. An Audio Gate similarly accepts a direct audio file URL (MP3 or similar) and gates the quiz until the student has listened for the required duration. Both gates show a real-time progress indicator and unlock automatically when the requirement is met.

For competency mapping, teachers enter Performance Criteria codes (one per line, aligned to the topic list above). The Excel export maps each student\'s per-topic scores to their corresponding criteria codes, supporting ASQA compliance reporting and training matrix documentation.

Teachers set attempt limits with optional CC email notifications to nominated addresses when a student reaches their limit. Gradebook integration, passing grades, and completion conditions are all configurable. Credit cost: 1 credit per question, or 2 credits per question with voiceover enabled.';
$string['modulename_link'] = 'mod/aiknowledgecheck/view';
$string['pluginname'] = 'AI Knowledge Check';
$string['pluginadministration'] = 'AI Knowledge Check administration';
$string['knowledgecheckname'] = 'Name';

// Capabilities.
$string['aiknowledgecheck:addinstance'] = 'Add a new AI Knowledge Check';
$string['aiknowledgecheck:view'] = 'View AI Knowledge Check';
$string['aiknowledgecheck:create'] = 'Generate knowledge checks with AI';
$string['aiknowledgecheck:viewreports'] = 'View AI Knowledge Check reports';
$string['aiknowledgecheck:manageoverrides'] = 'Manage attempt overrides';

// Settings.
$string['apiurl'] = 'API URL';
$string['apiurl_desc'] = 'The Essay Grader AI API URL. Default: https://lms-labs.com';
$string['siteid'] = 'Site ID';
$string['siteid_desc'] = 'Your Moodle site ID from lms-labs.com. This should match your registered site domain.';
$string['apikey'] = 'API Key';
$string['apikey_desc'] = 'Your API key from lms-labs.com. This is used to authenticate requests.';
$string['credits_heading'] = 'Credits';
$string['credits_info'] = 'AI Knowledge Check uses 1 credit per question (10¢ each), or 2 credits per question with voiceover enabled. Purchase credits at <a href="https://lms-labs.com" target="_blank">lms-labs.com</a>.';

// Page content.
$string['page_title'] = 'AI Knowledge Check';
$string['page_heading'] = 'AI Knowledge Check Generator';
$string['page_intro'] = 'Generate interactive multiple-choice quizzes with AI-powered questions and voice explanations.';
$string['credits_label'] = 'Credits Available';
$string['not_configured'] = 'Plugin not configured. Please set Site ID and API Key in the plugin settings.';
$string['students_view_message'] = 'This activity allows teachers to create AI-generated knowledge checks. Check back later for available quizzes.';

// Attempt settings.
$string['aftercompletion'] = 'After Completion';
$string['aftercompletion_help'] = 'Choose what happens once a student reaches a terminal state — either 100% correct answers or the attempt limit is reached. "Lock this activity" prevents any further attempts (the student can still view and download their results). "Restart" shows a Start Again button that resets progress and begins a fresh attempt.';
$string['aftercompletion_lock'] = 'Lock this activity';
$string['aftercompletion_restart'] = 'Restart';
$string['activity_locked_notice'] = 'This activity is now locked. No further attempts are permitted.';
$string['startAgain'] = 'Start Again';
$string['attemptsettings'] = 'Attempt Settings';
$string['attemptlimit'] = 'Maximum attempts';
$string['attemptlimit_help'] = 'Set the maximum number of attempts allowed for this knowledge check. Enter 0 to allow unlimited attempts.';
$string['unlimited'] = 'Unlimited';
$string['attempt'] = 'Attempt';
$string['attemptslimitreached'] = 'You have reached the attempt limit ({$a}).';

// CC Email notifications.
$string['ccemail'] = 'CC Email for Notifications';
$string['ccemail_help'] = 'Enter email addresses (comma-separated) to receive notifications when students reach their maximum attempts. This is in addition to teacher notifications.';

// Form labels.
$string['topics_label'] = 'Topics to Assess';
$string['topics_help'] = 'Enter the topics, learning outcomes, or competency criteria you want to test. The AI will create multiple-choice questions based on each topic. One topic per line.';
$string['topics_placeholder'] = 'e.g., Manual handling procedures
Fire safety and evacuation
Personal protective equipment';
$string['questions_per_topic'] = 'Questions Per Topic';
$string['questions_per_topic_help'] = 'Choose how many questions to generate for each topic. More questions provide better assessment coverage but use more credits.';

// User questions.
$string['use_own_questions'] = 'Use Your Own Questions';
$string['use_own_questions_help'] = 'Already have questions written? Paste them here and the AI will create 4 answer options (1 correct, 3 distractors) plus voiceover explanations for each.';
$string['use_own_questions_help_survey'] = 'Already have questions written? Paste one question stem per line. The response scale for this activity is applied automatically to each question — do not include answer options, they are added by the system.';
$string['your_questions'] = 'Your Questions';
$string['your_questions_placeholder'] = 'e.g., What is the correct procedure for manual handling?
When should you report a workplace hazard?';
$string['your_questions_placeholder_survey'] = 'e.g., Did the learning meet your expectations?
Was the course content relevant to your role?
Was the training delivery method effective for your learning style?';
$string['your_questions_help'] = 'Enter your questions exactly as you want them to appear. The AI will generate professional answer options and audio explanations for correct and incorrect answers.';
$string['your_questions_help_survey'] = 'Enter one question stem per line. Do not include answer options — the response scale ({$a}) is applied automatically to every question in this box. Use the Free Text Questions box below for any open-ended questions that need a typed response.';
$string['survey_mode_notice_title'] = 'Survey Mode is active';
$string['survey_mode_notice_body'] = 'This activity collects responses — there is no right/wrong scoring. Questions use the {$a} scale. Students see a completion message instead of a score.';

// Context settings.
$string['context_settings'] = 'Context Settings';
$string['context_settings_help'] = 'Add workplace context to generate questions with realistic scenarios and industry-specific terminology.';
$string['add_workplace_context'] = 'Add Workplace Context';
$string['workplace_context_help'] = 'Enable this for questions set in realistic workplace situations. Questions will reference your specific industry, job roles, and location.';
$string['country'] = 'Country';
$string['country_help'] = 'Select your country for region-specific regulations, terminology, and examples.';
$string['select_country'] = 'Select country...';
$string['state'] = 'State/Region';
$string['state_help'] = 'Optional. Select for state-specific legislation references.';
$string['select_state'] = 'Select state/region...';
$string['industry'] = 'Industry';
$string['industry_help'] = 'Select your industry. Questions will include scenarios and terminology relevant to your sector.';
$string['select_industry'] = 'Select industry...';
$string['industry_sector'] = 'Sector';
$string['industry_details'] = 'Industry Details';
$string['industry_details_help'] = 'Add specific details about your workplace for more targeted questions.';
$string['industry_details_placeholder'] = 'e.g., Commercial construction, Aged care facility, Mining site';
$string['job_level'] = 'Job Level';
$string['job_level_help'] = 'Select the target job level. Questions will be pitched at the appropriate complexity.';
$string['select_job_level'] = 'Select job level...';
$string['level_entry'] = 'Entry Level';
$string['level_intermediate'] = 'Intermediate';
$string['level_senior'] = 'Senior';
$string['level_supervisor'] = 'Supervisor';
$string['level_manager'] = 'Manager';
$string['level_executive'] = 'Executive';
$string['job_title'] = 'Job Title';
$string['job_title_help'] = 'Select the target job role for questions that reference specific duties and responsibilities.';
$string['select_job_title'] = 'Select job title...';
$string['job_title_other'] = 'Other';
$string['job_role'] = 'Job Role';
$string['job_role_placeholder'] = 'e.g., Construction Worker, Nurse, Office Manager';

// Education settings.
$string['education_type'] = 'Education Type';
$string['select_education_type'] = 'Select education type...';
$string['education_vet'] = 'VET (Vocational Education & Training)';
$string['education_academic'] = 'Academic (Higher Education)';
$string['education_general'] = 'General Training';
$string['vet_level'] = 'VET Level';
$string['select_vet_level'] = 'Select VET level...';
$string['vet_cert1'] = 'Certificate I';
$string['vet_cert2'] = 'Certificate II';
$string['vet_cert3'] = 'Certificate III';
$string['vet_cert4'] = 'Certificate IV';
$string['vet_diploma'] = 'Diploma';
$string['vet_adv_diploma'] = 'Advanced Diploma';
$string['academic_level'] = 'Academic Level';
$string['select_academic_level'] = 'Select academic level...';
$string['academic_undergraduate'] = 'Undergraduate';
$string['academic_postgraduate'] = 'Postgraduate';
$string['academic_masters'] = 'Masters';
$string['academic_phd'] = 'PhD/Doctorate';
$string['vet_tooltip_title'] = 'VET Training';
$string['vet_tooltip'] = 'Questions aligned to Australian Qualifications Framework (AQF) competency standards with practical workplace focus.';
$string['academic_tooltip_title'] = 'Academic Education';
$string['academic_tooltip'] = 'Questions aligned to higher education standards with theoretical depth and critical analysis focus.';
$string['general_tooltip_title'] = 'General Training';
$string['general_tooltip'] = 'Questions suitable for general workplace training, professional development, and non-accredited programs. No AQF level alignment required — practical, accessible questions for any audience.';

// Extra instructions.
$string['extra_instructions'] = 'Extra AI Instructions';
$string['extra_instructions_help'] = 'Optional. Add custom instructions to guide how the AI creates questions. Great for adjusting reading level, focusing on specific aspects, or adding special requirements.';
$string['extra_instructions_placeholder'] = 'e.g., "Use simple language at grade 6 reading level" or "Focus on safety procedures" or "Include specific regulatory references"';

// Voice settings.
$string['voiceover_enabled'] = 'Enable voiceover explanations';
$string['voiceover_enabled_help'] = 'When enabled, students hear spoken explanations after answering each question. Turn off to skip audio generation and speed up quiz creation.';
$string['voice_settings'] = 'Language & Voice Settings';
$string['voice_settings_help'] = 'Choose the language and voice for all question text and audio explanations. When students answer, they\'ll hear a spoken explanation of the correct answer.';
$string['voice_language'] = 'Language';
$string['language_help'] = 'Select the language for questions and voiceover explanations. All content will be generated in this language.';
$string['voice_gender'] = 'Voice Gender';
$string['voice_gender_help'] = 'Choose whether the AI narrator uses a male or female voice for the spoken explanations.';
$string['voice_female'] = 'Female';
$string['voice_male'] = 'Male';
$string['voice_style'] = 'Voice Style';
$string['voice_style_help'] = 'Choose a voice personality. Different styles suit different training contexts (e.g., professional for compliance, friendly for onboarding).';

// Languages - Complete Chirp 3 HD language list (51 languages).
// English variants.
$string['lang_en_au'] = 'English (Australia)';
$string['lang_en_gb'] = 'English (UK)';
$string['lang_en_in'] = 'English (India)';
$string['lang_en_us'] = 'English (US)';

// Spanish variants.
$string['lang_es_es'] = 'Spanish (Spain)';
$string['lang_es_us'] = 'Spanish (Latin America)';

// French variants.
$string['lang_fr_ca'] = 'French (Canada)';
$string['lang_fr_fr'] = 'French (France)';

// German.
$string['lang_de_de'] = 'German';

// Portuguese.
$string['lang_pt_br'] = 'Portuguese (Brazil)';

// Dutch variants.
$string['lang_nl_be'] = 'Dutch (Belgium)';
$string['lang_nl_nl'] = 'Dutch (Netherlands)';

// Nordic languages.
$string['lang_da_dk'] = 'Danish';
$string['lang_fi_fi'] = 'Finnish';
$string['lang_nb_no'] = 'Norwegian';
$string['lang_sv_se'] = 'Swedish';

// Eastern European languages.
$string['lang_bg_bg'] = 'Bulgarian';
$string['lang_cs_cz'] = 'Czech';
$string['lang_hr_hr'] = 'Croatian';
$string['lang_hu_hu'] = 'Hungarian';
$string['lang_pl_pl'] = 'Polish';
$string['lang_ro_ro'] = 'Romanian';
$string['lang_ru_ru'] = 'Russian';
$string['lang_sk_sk'] = 'Slovak';
$string['lang_sl_si'] = 'Slovenian';
$string['lang_sr_rs'] = 'Serbian';
$string['lang_uk_ua'] = 'Ukrainian';

// Baltic languages.
$string['lang_et_ee'] = 'Estonian';
$string['lang_lt_lt'] = 'Lithuanian';
$string['lang_lv_lv'] = 'Latvian';

// Southern European languages.
$string['lang_el_gr'] = 'Greek';
$string['lang_it_it'] = 'Italian';

// East Asian languages.
$string['lang_cmn_cn'] = 'Chinese (Mandarin)';
$string['lang_ja_jp'] = 'Japanese';
$string['lang_ko_kr'] = 'Korean';

// Southeast Asian languages.
$string['lang_id_id'] = 'Indonesian';
$string['lang_th_th'] = 'Thai';
$string['lang_vi_vn'] = 'Vietnamese';

// South Asian languages.
$string['lang_bn_in'] = 'Bengali';
$string['lang_gu_in'] = 'Gujarati';
$string['lang_hi_in'] = 'Hindi';
$string['lang_kn_in'] = 'Kannada';
$string['lang_ml_in'] = 'Malayalam';
$string['lang_mr_in'] = 'Marathi';
$string['lang_ta_in'] = 'Tamil';
$string['lang_te_in'] = 'Telugu';
$string['lang_ur_in'] = 'Urdu';

// Middle Eastern languages.
$string['lang_ar_xa'] = 'Arabic';
$string['lang_he_il'] = 'Hebrew';
$string['lang_tr_tr'] = 'Turkish';

// African languages.
$string['lang_sw_ke'] = 'Swahili (Kenya)';

// Voice styles (Chirp 3 HD).
$string['voice_aoede'] = 'Aoede (Warm)';
$string['voice_kore'] = 'Kore (Professional)';
$string['voice_leda'] = 'Leda (Clear)';
$string['voice_zephyr'] = 'Zephyr (Gentle)';
$string['voice_puck'] = 'Puck (Friendly)';
$string['voice_charon'] = 'Charon (Deep)';
$string['voice_fenrir'] = 'Fenrir (Strong)';
$string['voice_orus'] = 'Orus (Authoritative)';

// Preview section.
$string['preview_section'] = 'Preview';
$string['total_topics'] = 'Topics';
$string['total_questions'] = 'Questions';
$string['estimated_credits'] = 'Credits Required';

// Buttons.
$string['generate_btn'] = 'Generate Knowledge Check';
$string['take_quiz_btn'] = 'Take Quiz';
$string['review_questions_btn'] = 'Review Questions';
$string['retake_btn'] = 'Retake Quiz';

// Progress.
$string['generating'] = 'Generating Knowledge Check...';
$string['initializing'] = 'Initializing...';
$string['quiz_ready'] = 'Knowledge Check Ready!';

// Quiz player.
$string['question_of'] = 'Question {$a->current} of {$a->total}';
$string['check_answer'] = 'Check Answer';
$string['next_question'] = 'Next Question';
$string['finish_quiz'] = 'Finish Quiz';
$string['correct'] = 'Correct!';
$string['incorrect'] = 'Incorrect';
$string['your_score'] = 'Your Score';
$string['questions_correct'] = '{$a->correct} of {$a->total} correct';
$string['play_explanation'] = 'Play Explanation';
$string['pause_explanation'] = 'Pause';

// Results.
$string['quiz_complete'] = 'Quiz Complete!';
$string['score_excellent'] = 'Excellent work!';
$string['score_good'] = 'Good job!';
$string['score_needs_improvement'] = 'Keep practicing!';

// Reports and attempts.
$string['attemptsreport'] = 'Attempts Report';
$string['noattempts'] = 'No attempts yet.';
$string['username'] = 'User';
$string['attemptno'] = 'Attempt #';
$string['score'] = 'Score';
$string['timestarted'] = 'Time Started';
$string['timeended'] = 'Time Ended';
$string['timespent'] = 'Time Spent';
$string['inprogress'] = 'In Progress';
$string['review'] = 'Review';
$string['startquiz'] = 'Start Quiz';
$string['retakequiz'] = 'Retake Quiz';
$string['startnewattempt'] = 'Retake';
$string['backtocourse'] = 'Back to Course';

// More attempts management.
$string['moreattempts'] = 'More Attempts';
$string['moreattemptsheading'] = 'User Extra Attempts';
$string['userattempts'] = 'User Attempts';
$string['attemptsused'] = 'Attempts Used';
$string['attemptsallowed'] = 'Attempts Allowed';
$string['additionalattempts'] = 'Additional Attempts';
$string['totalallowed'] = 'Total Allowed';
$string['grantplusone'] = 'Grant +1';
$string['bulkgrantplusone'] = 'Grant +1 to Selected';
$string['view'] = 'View';
$string['actions'] = 'Actions';

// Notifications.
$string['messageprovider:allattemptsused'] = 'Notification when all attempts are used';
$string['allattemptsused_subject'] = 'All attempts used: {$a->activityname}';
$string['allattemptsused_body'] = 'User {$a->fullname} has used all {$a->limit} attempts for "{$a->activityname}" in the course "{$a->coursename}". Please review and, if appropriate, grant additional attempts at: {$a->overrideurl}';

// Completion.
$string['completionallcorrect'] = 'All answers must be correct';
$string['completionallcorrect_help'] = 'When enabled, the activity will only be marked as complete when the student answers all questions correctly in a single attempt.';
$string['completiondetail:completionallcorrect'] = 'Answer all questions correctly';
$string['completionpassgrade'] = 'Achieve passing grade';
$string['completionpassgrade_help'] = 'When enabled, the activity will be marked as complete when the student achieves the passing grade percentage set in the Grade settings section. Their best attempt score is used.';
$string['completiondetail:completionpassgrade'] = 'Achieve passing grade';

// Errors.
$string['error_nocredits'] = 'Insufficient credits. Please purchase more credits at lms-labs.com.';
$string['error_generation'] = 'An error occurred while generating the knowledge check. Please try again.';
$string['error_invalid_session'] = 'Invalid session. Please refresh the page and try again.';
$string['error_no_topics'] = 'Please enter at least one topic to generate questions.';
$string['error:negativeattempts'] = 'Maximum attempts cannot be negative.';
$string['error:invalidemail'] = 'Invalid email address format. Use comma-separated valid emails.';

// Activity list.
$string['noknowledgechecks'] = 'No AI Knowledge Check activities in this course.';

// Privacy.
$string['privacy:metadata:aiknowledgecheck_quizzes'] = 'Information about quizzes generated by users.';
$string['privacy:metadata:aiknowledgecheck_quizzes:userid'] = 'The ID of the user who generated the quiz.';
$string['privacy:metadata:aiknowledgecheck_quizzes:title'] = 'The title of the generated quiz.';
$string['privacy:metadata:aiknowledgecheck_quizzes:timecreated'] = 'The time when the quiz was generated.';
$string['privacy:metadata:aiknowledgecheck_attempts'] = 'Information about user attempts.';
$string['privacy:metadata:aiknowledgecheck_attempts:userid'] = 'The ID of the user who made the attempt.';
$string['privacy:metadata:aiknowledgecheck_overrides'] = 'Per-user overrides for a knowledge check (e.g. extra attempts).';
$string['privacy:metadata:aiknowledgecheck_overrides:userid'] = 'The ID of the user the override applies to.';
$string['privacy:metadata:aiknowledgecheck_overrides:extraattempts'] = 'The number of extra attempts granted to the user.';
$string['privacy:metadata:aiknowledgecheck_attempts:answers'] = 'The answers provided by the user.';
$string['privacy:metadata:aiknowledgecheck_attempts:timestarted'] = 'The time when the attempt was started.';
$string['privacy:metadata:aiknowledgecheck_attempts:timeended'] = 'The time when the attempt was completed.';
$string['privacy:metadata:essaygraderai'] = 'Topic data is sent to the Essay Grader AI service at lms-labs.com to generate quiz questions.';
$string['privacy:metadata:essaygraderai:topicdata'] = 'The topic text submitted for AI question generation.';

// PDF Upload.
$string['pdf_upload_toggle'] = 'Generate from PDF';
$string['pdf_upload_help'] = 'Upload one or more PDF documents and the AI will create questions based on the content. Great for turning course materials, textbooks, or policies into knowledge checks.';
$string['pdf_file_label'] = 'PDF Documents';
$string['pdf_drop_text'] = 'Click to select or drag a PDF file here';
$string['pdf_drop_text_multi'] = 'Click to select or drag PDF files here';
$string['pdf_max_size'] = 'Maximum file size: 10 MB per file';
$string['pdf_question_count'] = 'Number of Questions';
$string['pdf_question_count_help'] = 'Choose how many questions to generate from each PDF. More questions provide broader coverage but use more credits (1 credit per question, 2 with voiceover).';
$string['error_pdf_too_large'] = 'PDF file is too large. Maximum size is 10 MB.';
$string['error_pdf_invalid'] = 'Invalid file type. Please upload a PDF document.';
$string['error_pdf_empty'] = 'Could not extract any text from the PDF. Please try a different document.';
$string['pdf_apply_all'] = 'Same for all PDFs:';

// Paste content.
$string['paste_content_toggle'] = 'Generate from pasted content';
$string['paste_content_help'] = 'Paste text from documents, policies, or course materials and the AI will create questions based on the content.';
$string['add_text_source'] = 'Add another text source';
$string['text_source_placeholder'] = 'Paste your text content here...';
$string['text_source_label'] = 'Text source';
$string['error_text_empty'] = 'Please add at least one text source with content.';
$string['error_text_too_long'] = 'Text content is too long. Maximum 50,000 characters per source.';

// Grade settings.
$string['gradesettings'] = 'Grade settings';
$string['maximumgrade'] = 'Maximum grade';
$string['gradetopass'] = 'Grade to pass';
$string['passinggrade'] = 'Grade to pass';
$string['passinggrade_help'] = 'Set the minimum grade a student must achieve to pass this knowledge check. Enter a number between 0 and the maximum grade (e.g. 80 when max grade is 100). This is used by the Moodle gradebook and completion conditions to show pass/fail status. Set to 0 to disable.';
$string['nopassinggrade'] = 'No passing grade';
$string['error:invalidgradepass'] = 'Grade to pass must be a valid number (0 or above).';
$string['error:gradepasstoohigh'] = 'Grade to pass cannot be higher than the maximum grade.';

// Video Gate.
$string['videogate_header'] = 'Video Gate';
$string['videourl'] = 'YouTube video URL';
$string['videourl_help'] = 'Paste a YouTube video URL. Students must watch the video before they can start the quiz. Leave blank to disable the video gate.';
$string['videorequirement'] = 'Watch requirement';
$string['videorequirement_help'] = 'Choose how much of the video students must watch before they can proceed. "No requirement" shows the video but does not gate the start button.';
$string['videoreq_none'] = 'No requirement (video shown but not required)';
$string['videoreq_seconds'] = 'Watch for a minimum number of seconds';
$string['videoreq_full'] = 'Watch the entire video';
$string['videominseconds'] = 'Minimum seconds to watch';
$string['videominseconds_help'] = 'The number of seconds students must watch before the start button becomes available. Only used when the watch requirement is set to "minimum seconds".';
$string['error:invalidvideourl'] = 'Please enter a valid YouTube URL (e.g. https://www.youtube.com/watch?v=... or https://youtu.be/...).';
$string['error:videominseconds'] = 'Minimum seconds must be a positive number when watch requirement is set to seconds.';
$string['showvideoduringquiz'] = 'Show video while answering questions';
$string['showvideoduringquiz_help'] = 'When enabled, the video player remains visible above the questions while the student is completing the quiz. When disabled (the default), the video is hidden once the student starts answering.';
$string['showchapterstamps'] = 'Show chapter timestamp links';
$string['showchapterstamps_help'] = 'When enabled, each question displays a clickable timestamp link that jumps the video to the point in the transcript where the question topic is covered. Timestamps are automatically identified by the AI from YouTube-style timestamps in the source content (e.g. "1:09"). Requires a video gate URL to be set.';
$string['videogate_watchvideo'] = 'Watch this video before starting';
$string['videogate_watchfull'] = 'Watch the entire video to unlock the quiz';
$string['videogate_watchseconds'] = 'Watch at least {$a} seconds to unlock the quiz';
$string['videogate_unlocked'] = 'Video requirement met — you can now start the quiz';

// Criteria mapping.
$string['criteria_label'] = 'Performance Criteria (optional)';
$string['criteria_placeholder'] = "e.g. HLTWHS001-1.1, 1.2, 1.3\nHLTWHS001-2.1, 2.2\nHLTWHS001-3.1, 3.2, 3.3";
$string['criteria_help'] = 'Enter one criteria entry per line, aligned with your topics above. Each line will be mapped to the corresponding topic in the Excel export. Leave blank if not required.';

// Audio Gate.
$string['audiogate_header'] = 'Audio Gate';
$string['audiourl'] = 'Audio file URL';
$string['audiourl_help'] = 'Paste a direct URL to an MP3 or other audio file. Students must listen to the audio before they can start the quiz. Leave blank to disable the audio gate.';
$string['audiorequirement'] = 'Listen requirement';
$string['audiorequirement_help'] = 'Choose how much of the audio students must listen to before they can proceed. "No requirement" shows the player but does not gate the start button.';
$string['audioreq_none'] = 'No requirement (audio shown but not required)';
$string['audioreq_seconds'] = 'Listen for a minimum number of seconds';
$string['audioreq_full'] = 'Listen to the entire audio';
$string['audiominseconds'] = 'Minimum seconds to listen';
$string['audiominseconds_help'] = 'The number of seconds students must listen before the start button becomes available. Only used when the listen requirement is set to "minimum seconds".';
$string['error:invalidaudiourl'] = 'Please enter a valid URL starting with https:// (e.g. https://example.com/audio.mp3).';
$string['error:audiominseconds'] = 'Minimum seconds must be a positive number when listen requirement is set to seconds.';
$string['audiogate_listenaudio'] = 'Listen to this audio before starting';
$string['audiogate_listenfull'] = 'Listen to the entire audio to unlock the quiz';
$string['audiogate_listenseconds'] = 'Listen for at least {$a} seconds to unlock the quiz';
$string['audiogate_unlocked'] = 'Audio requirement met — you can now start the quiz';

// Image Gate.
$string['imagegate_header'] = 'Image Gate';
$string['imagegate_image'] = 'Image';
$string['imagegate_image_help'] = 'Upload an image (JPG, PNG, GIF, WebP) to enable the Image Gate. Students must click "I\'ve seen this image" before they can start the quiz. Leave empty to disable the image gate. You can also generate an AI image directly on the activity page (5 credits).';
$string['imageurl'] = 'Image URL';
$string['imageurl_help'] = 'Paste a direct URL to an image file (JPG, PNG, GIF, WebP) or leave blank to disable the image gate. Students must click "I\'ve seen this image" before they can start the quiz. You can also generate an image on the activity page using the AI Image Generator (5 credits). Data URLs from AI generation are also accepted here.';
$string['imagegate_viewimage'] = 'View this image before starting';
$string['imagegate_acknowledge'] = 'I\'ve seen this image — continue to quiz';
$string['imagegate_unlocked'] = 'Image acknowledged — you can now start the quiz';
$string['error:invalidimageurl'] = 'Please enter a valid URL starting with https:// or a data URL (data:image/...).';
$string['imagegate_generateimage'] = 'Generate Image with AI';
$string['imagegate_generating'] = 'Generating image...';
$string['imagegate_generated'] = 'Image generated — save it below or use it in the Image Gate setting.';
$string['imagegate_error'] = 'Image generation failed. Please try again or paste a URL.';
$string['imagegate_credits_cost'] = '5 credits per image (Imagen 4 Ultra)';
$string['imagegate_question_enable'] = 'Show image with this question';
$string['imagegate_question_url_placeholder'] = 'https://example.com/image.jpg';

// ADD-KC-MEDIAPER-Q (v1.5.120): Per-question video lang strings.
$string['perq_video_enable'] = 'Show YouTube video with this question';
$string['perq_video_url_placeholder'] = 'https://www.youtube.com/watch?v=...';
$string['perq_video_enable_help'] = 'When enabled, an embedded YouTube video appears above the question text. Students must click "I\'ve reviewed this content — Continue" before the answer options unlock. Paste the full YouTube watch URL (e.g. https://www.youtube.com/watch?v=XXXXXXXXXXX) or a short youtu.be URL.';

// ADD-KC-MEDIAPER-Q (v1.5.120): Per-question audio lang strings.
$string['perq_audio_enable'] = 'Play audio clip with this question';
$string['perq_audio_url_placeholder'] = 'https://example.com/audio.mp3';
$string['perq_audio_enable_help'] = 'When enabled, an HTML5 audio player appears above the question text. Students must click "I\'ve reviewed this content — Continue" before the answer options unlock. Paste a direct URL to any audio file (MP3, OGG, WAV, M4A, etc.) accessible over HTTPS. This is separate from the AI-generated voiceover explanation audio that plays after the student checks their answer.';
$string['imagegate_question_generate'] = 'Generate image (5 credits)';
$string['imagegate_not_configured'] = 'Image generation is not configured. Please set a Google API key in the plugin settings or site-wide AI config.';

// ADD-SURVEY-MODE (v1.5.126): Survey mode lang strings.
$string['surveymode_header']    = 'Survey Mode';
$string['surveymode']           = 'Enable Survey Mode';
$string['surveymode_help']      = 'When enabled, this activity acts as a survey rather than a knowledge check. Students respond using the selected response scale and receive no correct/wrong feedback. The results screen shows a completion message instead of a score. Use the AI generation tools as normal — just paste your question list and the AI will format the questions for the chosen scale.';
$string['surveyscale']          = 'Response Scale';
$string['surveyscale_help']     = 'The response scale presented to students for every question. Choose a scale that best fits the type of feedback you are collecting.';

// Survey scale display names.
$string['scale_likert5agree']   = 'Likert 5-point — Agreement (Strongly Agree … Strongly Disagree)';
$string['scale_likert5sat']     = 'Likert 5-point — Satisfaction (Very Satisfied … Very Dissatisfied)';
$string['scale_likert5freq']    = 'Likert 5-point — Frequency (Always … Never)';
$string['scale_likert5qual']    = 'Likert 5-point — Quality (Excellent … Very Poor)';
$string['scale_likert5imp']     = 'Likert 5-point — Importance (Very Important … Not Important at All)';
$string['scale_likert4agree']   = 'Likert 4-point — Agreement (Strongly Agree … Strongly Disagree)';
$string['scale_yesno']          = 'Yes / No';
$string['scale_yesnounsure']    = 'Yes / No / Unsure';
$string['scale_nps5']           = 'NPS 5-point (1-Very Poor … 5-Excellent)';

// Survey student-facing strings.
$string['startsurvey']          = 'Start Survey';
$string['retakesurvey']         = 'Retake Survey';
$string['surveycomplete_title'] = 'Survey Complete';
$string['surveycomplete_msg']   = 'Thank you for completing the survey. Your responses have been recorded.';
$string['freetext_questions_label']   = 'Free Text Questions (Optional)';
$string['freetext_questions_help']    = 'These open-ended questions appear after the scale questions. Students type their own response instead of selecting a scale option. Enter one question per line.';
$string['freetext_questions_placeholder'] = "What suggestions do you have for improving this training?\nIs there anything else you would like to share?";
$string['survey_report_heading']      = 'Survey Results';
$string['survey_report_responses']    = 'responses';
$string['survey_report_distribution'] = 'Response Distribution';
$string['survey_report_freetext']     = 'Open-Ended Responses';
$string['survey_report_bystudent']    = 'Responses by Student';
$string['survey_report_export_csv']   = 'Export CSV';
$string['survey_report_export_pdf']   = 'Print / Save PDF';
$string['survey_report_no_responses'] = 'No survey responses yet.';
$string['survey_report_question']     = 'Question';
$string['survey_report_student']      = 'Student';
$string['survey_report_submitted']    = 'Submitted';
$string['versionrepair_title'] = 'Repair AI Knowledge Check version';
$string['versionrepair_notneeded'] = 'The AI Knowledge Check version record does not need repair.';
$string['versionrepair_done'] = 'The recorded AI Knowledge Check version was repaired from {$a->from} to {$a->to}.';
$string['versionrepair_next'] = 'Continue to Site administration → Notifications so Moodle can run the normal upgrade and reconcile the Survey Mode database schema.';
$string['versionrepair_gotonotifications'] = 'Continue to Notifications';

// Events.
$string['eventcoursemoduleviewed'] = 'Course module viewed';
