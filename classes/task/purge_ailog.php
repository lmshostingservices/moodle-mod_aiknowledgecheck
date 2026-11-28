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

namespace mod_aiknowledgecheck\task;

use mod_aiknowledgecheck\local\responsibleai;

/**
 * Delete AI activity log entries older than the retention period (never less than six months).
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purge_ailog extends \core\task\scheduled_task {
    /**
     * Task name shown to administrators.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_purgeailog', 'mod_aiknowledgecheck');
    }

    /**
     * Run the task.
     *
     * @return void
     */
    public function execute(): void {
        $deleted = responsibleai::purge_old_log();
        mtrace('AI activity log entries deleted: ' . $deleted);
    }
}
