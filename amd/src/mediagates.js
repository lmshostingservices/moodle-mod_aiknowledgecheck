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
 * Video, audio and image gates that hold the quiz closed until the student engages.
 *
 * EXTRACT-INLINE-JS (v1.5.161): this was four inline <script> blocks in view.php, with PHP
 * interpolated into JavaScript string literals. That is fragile -- a translated string
 * containing an apostrophe broke the script -- untestable, and incompatible with a strict
 * Content-Security-Policy. The behaviour is unchanged; only its home has moved.
 *
 * @module     mod_aiknowledgecheck/mediagates
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/str', 'core/notification', 'mod_aiknowledgecheck/util'], function(Str, Notification, Util) {
    'use strict';

    /** @type {Object} Localised strings, filled in by init(). */
    var S = {};

    /** @type {Object} Gates still holding the quiz closed, keyed by name. */
    var locks = {};

    /** @type {Object} The gates that were locked at page load, so retake can restore them. */
    var originals = {};

    /** @type {Object} The configuration passed in from view.php. */
    var config = {};

    /** @type {Object|null} Per-gate reset handlers, registered as each gate is set up. */
    var resetters = {};

    /** @type {Object|null} The YouTube player, once the iframe API has created it. */
    var player = null;

    /**
     * Reveal the start section once every gate has been cleared.
     *
     * @return {void}
     */
    function showStart() {
        var start = document.getElementById('kc-start-section');
        if (start) {
            start.style.display = '';
        }
        var eta = document.querySelector('.kc-eta-banner');
        if (eta) {
            eta.style.display = '';
        }
        // FIX-KC-VIDEO-SIMULTANEOUS (v1.5.62): hide the media sections once all gates unlock so
        // the media and the quiz start are never shown together. FIX-KC-SHOWVIDEO (v1.5.63):
        // unless the teacher chose to keep the video visible during the quiz.
        if (!config.showvideoduringquiz) {
            var video = document.getElementById('kc-video-section');
            if (video) {
                video.style.display = 'none';
            }
        }
        ['kc-audio-section', 'kc-image-section'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) {
                el.style.display = 'none';
            }
        });
    }

    /**
     * Clear one gate, and open the quiz when it was the last one.
     *
     * @param {string} name The gate name: video, audio or image.
     * @return {void}
     */
    function unlock(name) {
        delete locks[name];
        if (Object.keys(locks).length === 0) {
            document.querySelectorAll('.kc-gated-btn').forEach(function(btn) {
                btn.disabled = false;
                btn.classList.remove('kc-gated-btn');
            });
            showStart();
        }
    }

    /**
     * Put a status banner into its unlocked (green) state.
     *
     * @param {string} id The status element's ID.
     * @param {string} textid The ID of the element holding the message, if separate.
     * @param {string} message The message to show.
     * @return {void}
     */
    function markUnlocked(id, textid, message) {
        var statusEl = document.getElementById(id);
        if (!statusEl) {
            return;
        }
        statusEl.style.background = '#d4edda';
        statusEl.style.borderColor = '#c3e6cb';
        var target = textid ? document.getElementById(textid) : statusEl;
        if (target) {
            target.textContent = message;
        }
    }

    /**
     * Put a status banner back into its locked (amber) state.
     *
     * @param {string} id The status element's ID.
     * @param {string} textid The ID of the element holding the message, if separate.
     * @param {string} message The message to restore.
     * @return {void}
     */
    function markLocked(id, textid, message) {
        var statusEl = document.getElementById(id);
        if (!statusEl) {
            return;
        }
        statusEl.style.background = '#fff3cd';
        statusEl.style.borderColor = '#ffeaa7';
        var target = textid ? document.getElementById(textid) : statusEl;
        if (target) {
            target.textContent = message;
        }
    }

    /**
     * Watch the audio element and clear the audio gate when the requirement is met.
     *
     * @return {void}
     */
    function setupAudioGate() {
        var audioEl = document.getElementById('kc-audio-player');
        var listened = 0;
        var timer = null;
        var unlocked = false;
        var lockedMessage = config.audiorequirement === 'full'
            ? S.audiolistenfull
            : Util.fmt(S.audiolistenseconds, config.audiominseconds);

        /**
         * Clear the audio gate.
         *
         * @return {void}
         */
        function unlockAudio() {
            if (unlocked) {
                return;
            }
            unlocked = true;
            clearInterval(timer);
            timer = null;
            markUnlocked('kc-audio-status', 'kc-audio-status-text', S.audiounlocked);
            unlock('audio');
        }

        if (audioEl) {
            audioEl.addEventListener('play', function() {
                if (unlocked || timer) {
                    return;
                }
                timer = setInterval(function() {
                    if (unlocked) {
                        clearInterval(timer);
                        return;
                    }
                    listened++;
                    if (config.audiorequirement === 'seconds' && listened >= config.audiominseconds) {
                        unlockAudio();
                    }
                }, 1000);
            });
            audioEl.addEventListener('pause', function() {
                clearInterval(timer);
                timer = null;
            });
            audioEl.addEventListener('ended', function() {
                clearInterval(timer);
                timer = null;
                if (config.audiorequirement === 'full') {
                    unlockAudio();
                }
            });
        }

        resetters.audio = function() {
            unlocked = false;
            listened = 0;
            if (timer) {
                clearInterval(timer);
                timer = null;
            }
            markLocked('kc-audio-status', 'kc-audio-status-text', lockedMessage);
            if (audioEl) {
                audioEl.pause();
                audioEl.currentTime = 0;
            }
        };
    }

    /**
     * Embed the YouTube player and, when gated, clear the video gate once watched.
     *
     * @return {void}
     */
    function setupVideoGate() {
        var host = document.getElementById('kc-yt-player');
        if (!host) {
            return;
        }

        if (!config.videogated) {
            var iframe = document.createElement('iframe');
            iframe.src = 'https://www.youtube.com/embed/' + encodeURIComponent(config.videoid) + '?rel=0';
            iframe.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;border:0;';
            iframe.setAttribute('allowfullscreen', '');
            iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
            host.appendChild(iframe);
            return;
        }

        var watched = 0;
        var watchTimer = null;
        var unlocked = false;
        // FIX-KC-SEEK-BLOCK (v1.5.55): the furthest position actually played, so a student
        // cannot skip ahead.
        var maxWatched = 0;
        var seekTimer = null;
        var lockedMessage = config.videorequirement === 'full'
            ? S.videowatchfull
            : Util.fmt(S.videowatchseconds, config.videominseconds);

        /**
         * Clear the video gate.
         *
         * @return {void}
         */
        function unlockVideo() {
            if (unlocked) {
                return;
            }
            unlocked = true;
            markUnlocked('kc-video-status', 'kc-video-status-text', S.videounlocked);
            unlock('video');
        }

        /**
         * Start counting watched seconds.
         *
         * @return {void}
         */
        function startTracking() {
            if (watchTimer) {
                return;
            }
            watchTimer = setInterval(function() {
                if (unlocked) {
                    clearInterval(watchTimer);
                    return;
                }
                watched++;
                if (config.videorequirement === 'seconds' && watched >= config.videominseconds) {
                    unlockVideo();
                    clearInterval(watchTimer);
                }
            }, 1000);
        }

        /**
         * Stop counting watched seconds.
         *
         * @return {void}
         */
        function stopTracking() {
            if (watchTimer) {
                clearInterval(watchTimer);
                watchTimer = null;
            }
        }

        /**
         * Poll for seek-forward attempts and push the student back.
         *
         * Only applies to the full-watch requirement; with a seconds requirement students may
         * seek freely once the timer has unlocked the gate.
         *
         * @return {void}
         */
        function startSeekBlocking() {
            if (seekTimer || unlocked || config.videorequirement !== 'full') {
                return;
            }
            seekTimer = setInterval(function() {
                if (unlocked || !player || !player.getCurrentTime) {
                    return;
                }
                var current = player.getCurrentTime();
                if (current > maxWatched + 1.5) {
                    player.seekTo(maxWatched, true);
                } else if (current > maxWatched) {
                    maxWatched = current;
                }
            }, 500);
        }

        /**
         * Stop the seek guard.
         *
         * FIX-KC-SEEK-BYPASS (v1.5.72): deliberately does NOT read getCurrentTime(). This runs on
         * both PAUSED and BUFFERING, and YouTube fires BUFFERING on a seek -- recording the seek
         * target as watched progress would let a student jump to the end and satisfy the
         * full-watch requirement.
         *
         * @return {void}
         */
        function stopSeekBlocking() {
            if (seekTimer) {
                clearInterval(seekTimer);
                seekTimer = null;
            }
        }

        resetters.video = function() {
            unlocked = false;
            watched = 0;
            maxWatched = 0;
            stopTracking();
            stopSeekBlocking();
            markLocked('kc-video-status', 'kc-video-status-text', lockedMessage);
            if (player && player.seekTo) {
                player.seekTo(0);
                player.stopVideo();
            }
        };

        window.onYouTubeIframeAPIReady = function() {
            player = new window.YT.Player('kc-yt-player', {
                videoId: config.videoid,
                playerVars: {rel: 0, modestbranding: 1},
                events: {
                    onReady: function() {
                        // Exposed so the main module can seek to a question's timestamp.
                        window.kcYtPlayer = player;
                    },
                    onStateChange: function(e) {
                        var state = window.YT.PlayerState;
                        if (e.data === state.PLAYING) {
                            startTracking();
                            startSeekBlocking();
                        } else if (e.data === state.PAUSED || e.data === state.BUFFERING) {
                            stopTracking();
                            stopSeekBlocking();
                        } else if (e.data === state.ENDED) {
                            stopTracking();
                            stopSeekBlocking();
                            if (config.videorequirement === 'full') {
                                // getDuration() returns 0 until metadata loads, so fall back to
                                // the watched-progress check to guard against seek-to-end.
                                var duration = player.getDuration ? player.getDuration() : 0;
                                var threshold = duration > 10 ? duration - 5 : duration;
                                if (maxWatched >= threshold) {
                                    unlockVideo();
                                } else {
                                    player.seekTo(maxWatched, true);
                                    player.playVideo();
                                }
                            }
                        }
                    }
                }
            });
        };

        var tag = document.createElement('script');
        tag.src = 'https://www.youtube.com/iframe_api';
        document.head.appendChild(tag);
    }

    /**
     * Clear the image gate when the student acknowledges the image.
     *
     * @return {void}
     */
    function setupImageGate() {
        var unlocked = false;

        /**
         * Clear the image gate.
         *
         * @return {void}
         */
        function doUnlock() {
            if (unlocked) {
                return;
            }
            unlocked = true;
            var statusEl = document.getElementById('kc-image-status');
            if (statusEl) {
                statusEl.style.background = '#d4edda';
                statusEl.style.borderColor = '#c3e6cb';
                statusEl.textContent = S.imageunlocked;
            }
            unlock('image');
        }

        /**
         * Attach the click handler to the acknowledge button.
         *
         * @return {void}
         */
        function bindAck() {
            var btn = document.getElementById('kc-image-acknowledge-btn');
            if (btn) {
                btn.addEventListener('click', doUnlock);
            }
        }

        bindAck();

        resetters.image = function() {
            unlocked = false;
            var statusEl = document.getElementById('kc-image-status');
            if (!statusEl) {
                return;
            }
            statusEl.style.background = '#fff3cd';
            statusEl.style.borderColor = '#ffeaa7';
            statusEl.textContent = '';
            var btn = document.createElement('button');
            btn.id = 'kc-image-acknowledge-btn';
            btn.className = 'kc-btn kc-btn-secondary';
            btn.type = 'button';
            btn.textContent = S.imageacknowledge;
            statusEl.appendChild(btn);
            bindAck();
        };
    }

    return {
        /**
         * Set up whichever gates this activity uses.
         *
         * @param {Object} cfg Gate configuration from view.php.
         * @return {void}
         */
        init: function(cfg) {
            config = cfg || {};

            Str.get_strings([
                {key: 'audiogate_unlocked', component: 'mod_aiknowledgecheck'},
                {key: 'audiogate_listenfull', component: 'mod_aiknowledgecheck'},
                {key: 'audiogate_listenseconds', component: 'mod_aiknowledgecheck'},
                {key: 'videogate_unlocked', component: 'mod_aiknowledgecheck'},
                {key: 'videogate_watchfull', component: 'mod_aiknowledgecheck'},
                {key: 'videogate_watchseconds', component: 'mod_aiknowledgecheck'},
                {key: 'imagegate_unlocked', component: 'mod_aiknowledgecheck'},
                {key: 'imagegate_acknowledge', component: 'mod_aiknowledgecheck'},
                {key: 'js_retakequiz', component: 'mod_aiknowledgecheck'}
            ]).then(function(v) {
                S.audiounlocked = v[0];
                S.audiolistenfull = v[1];
                S.audiolistenseconds = v[2];
                S.videounlocked = v[3];
                S.videowatchfull = v[4];
                S.videowatchseconds = v[5];
                S.imageunlocked = v[6];
                S.imageacknowledge = v[7];
                S.retakequiz = v[8];

                // FIX-KC-NONEDITING-TEACHER (v1.5.137): course staff are exempt from all three
                // media locks. Before this, the image gate carried an exemption and video and
                // audio did not, so a non-editing teacher was freed from one and held by two.
                if (!config.isstaff) {
                    ['video', 'audio', 'image'].forEach(function(name) {
                        if (config[name + 'gated']) {
                            locks[name] = true;
                            originals[name] = true;
                        }
                    });
                }

                if (config.hasaudio) {
                    setupAudioGate();
                }
                if (config.hasvideo) {
                    setupVideoGate();
                }
                if (config.hasimage && config.imagegated) {
                    setupImageGate();
                }
                return v;
            }).catch(Notification.exception);
        },

        /**
         * Whether this page has any gates at all.
         *
         * @return {boolean} True when at least one gate was locked on load.
         */
        hasLocks: function() {
            return Object.keys(originals).length > 0;
        },

        /**
         * Re-lock every gate and hide the start section, for a retake.
         *
         * @return {void}
         */
        reset: function() {
            Object.keys(originals).forEach(function(name) {
                locks[name] = true;
            });

            var start = document.getElementById('kc-start-section');
            if (start) {
                start.style.display = 'none';
            }
            var eta = document.querySelector('.kc-eta-banner');
            if (eta) {
                eta.style.display = 'none';
            }
            ['kc-video-section', 'kc-audio-section', 'kc-image-section'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) {
                    el.style.display = '';
                }
            });

            var startBtn = document.getElementById('start-attempt-btn');
            if (startBtn) {
                startBtn.disabled = true;
                startBtn.classList.add('kc-gated-btn');
                // FIX-KC-LOADING-RETAKE (v1.5.66): the previous attempt left this reading
                // "Loading...", which made the button look frozen when the gate unlocked again.
                startBtn.textContent = S.retakequiz;
            }
            var continueBtn = document.getElementById('continue-attempt-btn');
            if (continueBtn) {
                continueBtn.disabled = true;
                continueBtn.classList.add('kc-gated-btn');
            }

            Object.keys(resetters).forEach(function(name) {
                resetters[name]();
            });
        }
    };
});
