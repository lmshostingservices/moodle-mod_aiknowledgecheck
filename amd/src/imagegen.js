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
 * The teacher's AI image generator for the image gate.
 *
 * EXTRACT-INLINE-JS (v1.5.161): moved out of an inline <script> in view.php. Along the way its
 * window.alert() became a Moodle modal and its hardcoded English moved to the language pack.
 *
 * @module     mod_aiknowledgecheck/imagegen
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/ajax', 'core/str', 'core/notification', 'mod_aiknowledgecheck/util'],
        function(Ajax, Str, Notification, Util) {
    'use strict';

    /** @type {Object} Localised strings. */
    var S = {};

    /**
     * Set a status element's message and colour.
     *
     * @param {HTMLElement|null} el The status element.
     * @param {string} message The message to show.
     * @param {string} colour A CSS colour.
     * @return {void}
     */
    function setStatus(el, message, colour) {
        if (!el) {
            return;
        }
        el.textContent = message;
        el.style.color = colour;
    }

    return {
        /**
         * Wire up the generator controls.
         *
         * @param {number} cmid The course module ID.
         * @return {void}
         */
        init: function(cmid) {
            var genBtn = document.getElementById('kc-imagegen-btn');
            var promptInput = document.getElementById('kc-imagegen-prompt');
            var statusEl = document.getElementById('kc-imagegen-status');
            var resultEl = document.getElementById('kc-imagegen-result');
            var previewEl = document.getElementById('kc-imagegen-preview');
            var urlOutput = document.getElementById('kc-imagegen-url-output');
            var saveGateBtn = document.getElementById('kc-imagegen-save-gate');
            var copyBtn = document.getElementById('kc-imagegen-copy');
            var saveStatusEl = document.getElementById('kc-imagegen-save-status');
            var lastGeneratedUrl = '';

            Str.get_strings([
                {key: 'pluginname', component: 'mod_aiknowledgecheck'},
                {key: 'js_imagegen_describe', component: 'mod_aiknowledgecheck'},
                {key: 'js_imagegen_button', component: 'mod_aiknowledgecheck'},
                {key: 'imagegate_generating', component: 'mod_aiknowledgecheck'},
                {key: 'imagegate_generated', component: 'mod_aiknowledgecheck'},
                {key: 'imagegate_error', component: 'mod_aiknowledgecheck'},
                {key: 'js_imagegen_saving', component: 'mod_aiknowledgecheck'},
                {key: 'js_imagegen_saved', component: 'mod_aiknowledgecheck'},
                {key: 'js_imagegen_savefaileddetail', component: 'mod_aiknowledgecheck'},
                {key: 'js_imagegen_savefailed', component: 'mod_aiknowledgecheck'},
                {key: 'js_imagegen_copied', component: 'mod_aiknowledgecheck'},
                {key: 'js_imagegen_copyurl', component: 'mod_aiknowledgecheck'},
                {key: 'js_error_unknown', component: 'mod_aiknowledgecheck'}
            ]).then(function(v) {
                S.title = v[0];
                S.describe = v[1];
                S.button = v[2];
                S.generating = v[3];
                S.generated = v[4];
                S.error = v[5];
                S.saving = v[6];
                S.saved = v[7];
                S.savefaileddetail = v[8];
                S.savefailed = v[9];
                S.copied = v[10];
                S.copyurl = v[11];
                S.unknown = v[12];

                if (genBtn) {
                    genBtn.addEventListener('click', function() {
                        var prompt = promptInput ? promptInput.value.trim() : '';
                        if (!prompt) {
                            Notification.alert(S.title, S.describe);
                            return;
                        }
                        genBtn.disabled = true;
                        genBtn.textContent = S.generating;
                        if (statusEl) {
                            statusEl.style.display = 'block';
                            setStatus(statusEl, S.generating, '#6c757d');
                        }
                        if (resultEl) {
                            resultEl.style.display = 'none';
                        }

                        Ajax.call([{
                            methodname: 'mod_aiknowledgecheck_generate_image',
                            args: {cmid: cmid, prompt: prompt}
                        }])[0].done(function(resp) {
                            genBtn.disabled = false;
                            genBtn.textContent = S.button;
                            if (resp.ok && resp.imageDataUrl) {
                                lastGeneratedUrl = resp.imageDataUrl;
                                if (previewEl) {
                                    previewEl.src = resp.imageDataUrl;
                                }
                                if (urlOutput) {
                                    urlOutput.value = resp.imageDataUrl;
                                }
                                if (resultEl) {
                                    resultEl.style.display = 'block';
                                }
                                setStatus(statusEl, S.generated, '#28a745');
                            } else {
                                setStatus(statusEl, resp.error || S.error, '#dc3545');
                            }
                        }).fail(function() {
                            genBtn.disabled = false;
                            genBtn.textContent = S.button;
                            setStatus(statusEl, S.error, '#dc3545');
                        });
                    });
                }

                if (saveGateBtn) {
                    saveGateBtn.addEventListener('click', function() {
                        if (!lastGeneratedUrl) {
                            return;
                        }
                        saveGateBtn.disabled = true;
                        setStatus(saveStatusEl, S.saving, '#6c757d');

                        Ajax.call([{
                            methodname: 'mod_aiknowledgecheck_save_image_url',
                            args: {cmid: cmid, imageurl: lastGeneratedUrl}
                        }])[0].done(function(resp) {
                            saveGateBtn.disabled = false;
                            if (resp.ok) {
                                setStatus(saveStatusEl, S.saved, '#28a745');
                            } else {
                                setStatus(saveStatusEl,
                                    Util.fmt(S.savefaileddetail, resp.error || S.unknown), '#dc3545');
                            }
                        }).fail(function() {
                            saveGateBtn.disabled = false;
                            setStatus(saveStatusEl, S.savefailed, '#dc3545');
                        });
                    });
                }

                if (copyBtn) {
                    copyBtn.addEventListener('click', function() {
                        if (!urlOutput) {
                            return;
                        }
                        urlOutput.select();
                        document.execCommand('copy');
                        copyBtn.textContent = S.copied;
                        setTimeout(function() {
                            copyBtn.textContent = S.copyurl;
                        }, 2000);
                    });
                }
                return v;
            }).catch(Notification.exception);
        }
    };
});
