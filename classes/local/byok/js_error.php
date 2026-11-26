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

namespace mod_aiknowledgecheck\local\byok;

/**
 * An error the LMS Labs service code raises: a thrown Error, or a TypeError on unexpected AI output.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class js_error extends \Exception {
    /** @var bool Whether the service would have hit a TypeError here (unexpected shape in the AI answer). */
    public $typeerror = false;

    /**
     * A TypeError, as JavaScript raises on a value of the wrong type.
     *
     * @param string $what
     * @return js_error
     */
    public static function type(string $what): js_error {
        $e = new self('TypeError: ' . $what);
        $e->typeerror = true;
        return $e;
    }
}
