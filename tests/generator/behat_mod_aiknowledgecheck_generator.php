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
 * Behat data generator for mod_aiknowledgecheck.
 *
 * @package    mod_aiknowledgecheck
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Lets feature files seed questions and attempts without calling the AI service.
 */
class behat_mod_aiknowledgecheck_generator extends behat_generator_base {
    /**
     * Describes the entities this generator can create from a feature file.
     *
     * @return array The entity definitions, keyed by the plural name used in feature files.
     */
    protected function get_creatable_entities(): array {
        return [
            'questions' => [
                'singular' => 'question',
                'datagenerator' => 'question',
                'required' => ['activity', 'questionnumber'],
            ],
            'attempts' => [
                'singular' => 'attempt',
                'datagenerator' => 'attempt',
                'required' => ['activity', 'user'],
            ],
            'overrides' => [
                'singular' => 'override',
                'datagenerator' => 'override',
                'required' => ['activity', 'user', 'extraattempts'],
            ],
        ];
    }

    /**
     * Create one question from a feature file row.
     *
     * @param array $data The row values.
     */
    protected function process_question(array $data): void {
        $instanceid = $this->get_instance_id($data['activity']);
        $number = (int)$data['questionnumber'];

        $overrides = [];
        foreach (
            ['questiontext', 'answer1', 'answer2', 'answer3', 'answer4', 'answer5',
                  'feedback1', 'feedback2', 'feedback3', 'feedback4', 'questiontype',
                  'mappingtopic', 'mappingcriteria'] as $field
        ) {
            if (isset($data[$field])) {
                $overrides[$field] = $data[$field];
            }
        }
        if (isset($data['correctanswer'])) {
            $overrides['correctanswer'] = (int)$data['correctanswer'];
        }

        $this->componentdatagenerator->create_question($instanceid, $number, $overrides);
    }

    /**
     * Create one attempt from a feature file row.
     *
     * @param array $data The row values.
     */
    protected function process_attempt(array $data): void {
        $instanceid = $this->get_instance_id($data['activity']);
        $userid = $this->get_user_id($data['user']);

        $overrides = [];
        foreach (['status', 'correctcount', 'totalcount', 'currentquestion'] as $field) {
            if (isset($data[$field])) {
                $overrides[$field] = (int)$data[$field];
            }
        }

        $this->componentdatagenerator->create_attempt($instanceid, $userid, $overrides);
    }

    /**
     * Create one attempt override from a feature file row.
     *
     * @param array $data The row values.
     */
    protected function process_override(array $data): void {
        $this->componentdatagenerator->create_override(
            $this->get_instance_id($data['activity']),
            $this->get_user_id($data['user']),
            (int)$data['extraattempts']
        );
    }

    /**
     * Resolve an activity idnumber or name to its aiknowledgecheck instance ID.
     *
     * @param string $idnumber The activity idnumber used in the feature file.
     * @return int The instance ID.
     */
    protected function get_instance_id(string $idnumber): int {
        $cm = $this->get_cm_by_activity_name('aiknowledgecheck', $idnumber);
        return (int)$cm->instance;
    }
}
