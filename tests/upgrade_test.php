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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * Regression tests for skipped database upgrade dependencies.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_aiknowledgecheck;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/upgradelib.php');
require_once($CFG->dirroot . '/mod/aiknowledgecheck/db/upgrade.php');
require_once($CFG->dirroot . '/mod/aiknowledgecheck/db/upgradelib.php');

/**
 * Exercise real XMLDB upgrades, including MySQL column-position dependencies.
 *
 * @covers ::xmldb_aiknowledgecheck_upgrade
 * @covers ::xmldb_aiknowledgecheck_repair_skipped_schema
 */
final class upgrade_test extends \advanced_testcase {
    /**
     * Previously released savepoints and incomplete schemas.
     *
     * @return array
     */
    public static function skipped_schema_provider(): array {
        return [
            'image gate skipped before original survey migration' => [2026072895, 'all'],
            'reported survey reconciliation failure' => [2026082300, 'all'],
            'missing imageenabled and answer5 together' => [2026082300, 'questions'],
            'only missing imageenabled' => [2026082300, 'imageenabled'],
            'already on previous latest release' => [2026091001, 'all'],
            'healthy previous release' => [2026091001, 'none'],
        ];
    }

    /**
     * Upgrade partial schemas without changing retained data, then run it again.
     *
     * @dataProvider skipped_schema_provider
     * @param int $from Installed version before the upgrade.
     * @param string $missing Fields to remove before running the upgrade.
     */
    public function test_repairs_skipped_columns_and_preserves_data(int $from, string $missing): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_aiknowledgecheck');
        $activity = $generator->create_instance([
            'course' => $course->id,
            'name' => 'Preserved knowledge check',
            'imageurl' => 'https://example.invalid/activity.png',
            'surveymode' => 1,
            'surveyscale' => 'yesno',
            'showcorrectanswer' => 0,
        ]);
        $questionid = $generator->create_question($activity->id, 1, [
            'questiontext' => 'Preserve this question',
            'imageurl' => 'https://example.invalid/question.png',
            'imageenabled' => 1,
            'questionvideourl' => 'https://example.invalid/video',
            'questionvideoenabled' => 1,
            'questionaudiourl' => 'https://example.invalid/audio.ogg',
            'questionaudioenabled' => 1,
            'answer5' => 'Preserve the fifth answer',
            'questiontype' => 'freetext',
        ]);
        $activitybefore = $DB->get_record('aiknowledgecheck', ['id' => $activity->id], '*', MUST_EXIST);
        $questionbefore = $DB->get_record('aiknowledgecheck_questions', ['id' => $questionid], '*', MUST_EXIST);
        $fields = [
            'aiknowledgecheck' => ['imageurl', 'surveymode', 'surveyscale', 'showcorrectanswer'],
            'aiknowledgecheck_questions' => [
                'imageurl', 'imageenabled', 'questionvideourl', 'questionvideoenabled',
                'questionaudiourl', 'questionaudioenabled', 'answer5', 'questiontype',
            ],
        ];
        $defaults = [
            'aiknowledgecheck' => [
                'imageurl' => null, 'surveymode' => 0, 'surveyscale' => 'likert5agree', 'showcorrectanswer' => 1,
            ],
            'aiknowledgecheck_questions' => [
                'imageurl' => null, 'imageenabled' => 0, 'answer5' => null, 'questiontype' => 'scale',
                'questionvideourl' => null, 'questionvideoenabled' => 0,
                'questionaudiourl' => null, 'questionaudioenabled' => 0,
            ],
        ];
        $removed = [];
        $dbman = $DB->get_manager();
        try {
            foreach ($fields as $tablename => $names) {
                foreach (array_reverse($names) as $name) {
                    if ($missing === 'all' || ($missing === 'questions' && $tablename === 'aiknowledgecheck_questions')
                            || ($missing === 'imageenabled' && $name === 'imageenabled')) {
                        $dbman->drop_field(new \xmldb_table($tablename), new \xmldb_field($name));
                        $removed[$tablename][$name] = true;
                    }
                }
            }
            set_config('version', $from, 'mod_aiknowledgecheck');
            $this->assertTrue(xmldb_aiknowledgecheck_upgrade($from));
            $this->assertEquals(2026102200, get_config('mod_aiknowledgecheck', 'version'));
            $records = [
                'aiknowledgecheck' => [$activity->id, $activitybefore],
                'aiknowledgecheck_questions' => [$questionid, $questionbefore],
            ];
            foreach ($records as $tablename => [$id, $before]) {
                $after = $DB->get_record($tablename, ['id' => $id], '*', MUST_EXIST);
                foreach ((array)$before as $name => $value) {
                    $expected = isset($removed[$tablename][$name]) ? $defaults[$tablename][$name] : $value;
                    $this->assertEquals($expected, $after->$name, "$tablename.$name");
                }
                foreach ($fields[$tablename] as $name) {
                    $this->assertTrue($dbman->field_exists(new \xmldb_table($tablename), new \xmldb_field($name)));
                }
            }
            $beforeagain = [
                $DB->get_record('aiknowledgecheck', ['id' => $activity->id]),
                $DB->get_record('aiknowledgecheck_questions', ['id' => $questionid]),
            ];
            xmldb_aiknowledgecheck_repair_skipped_schema();
            $this->assertTrue(xmldb_aiknowledgecheck_upgrade(2026102200));
            $this->assertEquals($beforeagain, [
                $DB->get_record('aiknowledgecheck', ['id' => $activity->id]),
                $DB->get_record('aiknowledgecheck_questions', ['id' => $questionid]),
            ]);
        } finally {
            // Moodle's test reset restores records, not schema changes.
            xmldb_aiknowledgecheck_repair_skipped_schema();
        }
    }
}
