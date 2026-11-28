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
 * Responsible AI review panel: approve AI-written questions for learners (EU AI Act Article 14).
 *
 * @module     mod_aiknowledgecheck/responsibleai
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/str', 'core/notification'], function($, Ajax, Str, Notification) {

    /**
     * Show the panel's state.
     *
     * @param {jQuery} panel The review panel.
     * @param {Boolean} approved Whether learners can see the questions.
     * @param {String} status Status line.
     */
    function setState(panel, approved, status) {
        panel.attr('data-state', approved ? 'approved' : 'draft');
        panel.find('[data-region="status"]').text(status);
        panel.find('[data-region="badge-draft"]').prop('hidden', approved);
        panel.find('[data-region="badge-approved"]').prop('hidden', !approved);
        panel.find('[data-action="approve"]').prop('hidden', approved);
        panel.find('[data-action="withdraw"]').prop('hidden', !approved);
        panel.find('[data-region="confirm"]').prop('hidden', approved);
        var confirm = panel.find('[data-region="confirm"] input');
        confirm.prop('checked', false);
        panel.find('[data-action="approve"]').prop('disabled', true);
    }

    /**
     * Show the oversight figures.
     *
     * @param {jQuery} panel The review panel.
     * @param {Object} data aiquestions, changed and teacherwritten counts.
     */
    function setStats(panel, data) {
        if (typeof data.aiquestions !== 'undefined') {
            panel.find('[data-region="stat-ai"]').text(data.aiquestions);
        }
        if (typeof data.changed !== 'undefined') {
            panel.find('[data-region="stat-changed"]').text(data.changed);
        }
        if (typeof data.teacherwritten !== 'undefined') {
            panel.find('[data-region="stat-teacher"]').text(data.teacherwritten);
        }
    }

    /**
     * Send approve or withdraw.
     *
     * @param {jQuery} panel The review panel.
     * @param {Number} cmid Course module id.
     * @param {Number} approve 1 or 0.
     */
    function send(panel, cmid, approve) {
        var buttons = panel.find('[data-action]');
        buttons.prop('disabled', true);
        Ajax.call([{
            methodname: 'mod_aiknowledgecheck_approve_questions',
            args: {
                cmid: cmid,
                approve: approve,
                confirmed: panel.find('[data-region="confirm"] input').is(':checked') ? 1 : 0
            }
        }])[0].then(function(response) {
            buttons.prop('disabled', false);
            if (!response.ok) {
                Notification.alert('', response.error);
                return;
            }
            setState(panel, response.approved, response.status);
            setStats(panel, response);
            return;
        }).catch(function(err) {
            buttons.prop('disabled', false);
            Notification.exception(err);
        });
    }

    return {
        /**
         * Wire up the panel.
         *
         * @param {Object} config cmid, required (approval required on this site), paused (AI paused on this site).
         */
        init: function(config) {
            var panel = $('#kc-review-panel');
            var cmid = parseInt(config.cmid, 10);

            if (config.paused) {
                // The server refuses AI work while paused; disable the controls that would ask for it.
                $('#kc-form button[type="submit"], #generate-btn, #ready-regenerate-btn, #kc-imagegen-btn, ' +
                    '[data-kc-ai-action]').prop('disabled', true).attr('aria-disabled', 'true');
            }

            if (!panel.length) {
                return;
            }

            panel.on('change', '[data-region="confirm"] input', function() {
                panel.find('[data-action="approve"]').prop('disabled', !this.checked);
            });
            panel.on('click', '[data-action="approve"]', function(e) {
                e.preventDefault();
                send(panel, cmid, 1);
            });
            panel.on('click', '[data-action="withdraw"]', function(e) {
                e.preventDefault();
                send(panel, cmid, 0);
            });

            $(document).on('kc:questionssaved', function(e, response, source) {
                panel.prop('hidden', false);
                setStats(panel, response || {});
                if (source === 'ai' && config.required) {
                    Str.get_string('review_status_draft', 'mod_aiknowledgecheck').then(function(text) {
                        setState(panel, false, text);
                        return;
                    }).catch(Notification.exception);
                }
            });
        }
    };
});
