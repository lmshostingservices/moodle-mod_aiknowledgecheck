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
 * Small helpers shared by this plugin's modules.
 *
 * @module     mod_aiknowledgecheck/util
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    'use strict';

    return {
        /**
         * Substitute a Moodle language-string placeholder.
         *
         * Mirrors what the server does for {$a} and {$a->name}, so a string can be fetched once
         * and reused with different values without another round trip.
         *
         * @param {string} template The raw language string.
         * @param {Object|string|number} a The placeholder value, or an object of named values.
         * @return {string} The string with placeholders substituted.
         */
        fmt: function(template, a) {
            if (template === undefined || template === null) {
                return '';
            }
            var out = String(template);
            if (a === undefined || a === null) {
                return out;
            }
            if (typeof a === 'object') {
                Object.keys(a).forEach(function(key) {
                    out = out.split('{$a->' + key + '}').join(String(a[key]));
                });
                return out;
            }
            return out.split('{$a}').join(String(a));
        }
    };
});
