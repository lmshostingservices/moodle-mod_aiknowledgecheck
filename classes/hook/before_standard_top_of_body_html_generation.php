<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace mod_aiknowledgecheck\hook;

defined('MOODLE_INTERNAL') || die();

/**
 * Shows administrators a repair action for a stranded plugin version.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class before_standard_top_of_body_html_generation {
    /**
     * Add the repair banner without affecting CLI, AJAX, or web-service output.
     *
     * @param \core\hook\output\before_standard_top_of_body_html_generation $hook
     */
    public static function callback(
        \core\hook\output\before_standard_top_of_body_html_generation $hook
    ): void {
        global $CFG;

        if ((defined('CLI_SCRIPT') && CLI_SCRIPT)
            || (defined('AJAX_SCRIPT') && AJAX_SCRIPT)
            || (defined('WS_SERVER') && WS_SERVER)) {
            return;
        }
        if (!isloggedin() || isguestuser()) {
            return;
        }

        require_once($CFG->dirroot . '/mod/aiknowledgecheck/lib.php');
        $html = mod_aiknowledgecheck_version_banner();
        if ($html !== '') {
            $hook->add_html($html);
        }
    }
}