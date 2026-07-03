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
 * Tests for qbank_cffpoc.
 *
 * @package    qbank_cffpoc
 * @copyright  2026 Thomas <thomas@example.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qbank_cffpoc;

use advanced_testcase;
use core_customfield\field_controller;

/**
 * @covers \qbank_cffpoc\customfields_condition
 */
final class condition_test extends advanced_testcase {

    /**
     * Create a question custom field of type 'select' in the qbank_customfields/question area.
     *
     * @param int $categoryid
     * @param string $shortname
     * @param string[] $options
     * @param int $visibility question_handler::VISIBLETOALL/VISIBLETOTEACHERS/NOTVISIBLE.
     * @return field_controller
     */
    private function create_select_field(int $categoryid, string $shortname, array $options, int $visibility = 2): field_controller {
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        return $generator->create_field((object) [
            'categoryid' => $categoryid,
            'type' => 'select',
            'shortname' => $shortname,
            'name' => ucfirst($shortname),
            'configdata' => [
                'options' => implode("\n", $options),
                'visibility' => $visibility,
            ],
        ]);
    }

    /**
     * Set up two question custom fields (bloom, difficulty) and four questions with a mix of
     * field values, ready for the query-building tests.
     *
     * @return array [field_controller $bloom, field_controller $difficulty, stdClass[] $questions]
     */
    private function setup_fields_and_questions(): array {
        $cfgenerator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $cfcategory = $cfgenerator->create_category([
            'component' => 'qbank_customfields',
            'area' => 'question',
            'itemid' => 0,
        ]);

        $bloom = $this->create_select_field($cfcategory->get('id'), 'bloom', ['Erinnern', 'Verstehen', 'Anwenden']);
        $difficulty = $this->create_select_field($cfcategory->get('id'), 'difficulty', ['Easy', 'Hard']);

        $qgenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $qcategory = $qgenerator->create_question_category();

        // q1: bloom=Erinnern(1) only.
        $q1 = $qgenerator->create_question('truefalse', null, ['category' => $qcategory->id, 'name' => 'q1']);
        $cfgenerator->add_instance_data($bloom, $q1->id, 1);

        // q2: bloom=Verstehen(2), difficulty=Hard(2) -- matches both bloom:2 and difficulty:2.
        $q2 = $qgenerator->create_question('truefalse', null, ['category' => $qcategory->id, 'name' => 'q2']);
        $cfgenerator->add_instance_data($bloom, $q2->id, 2);
        $cfgenerator->add_instance_data($difficulty, $q2->id, 2);

        // q3: bloom=Verstehen(2), difficulty=Easy(1) -- matches bloom:2 but NOT difficulty:2.
        $q3 = $qgenerator->create_question('truefalse', null, ['category' => $qcategory->id, 'name' => 'q3']);
        $cfgenerator->add_instance_data($bloom, $q3->id, 2);
        $cfgenerator->add_instance_data($difficulty, $q3->id, 1);

        // q4: no custom field data at all.
        $q4 = $qgenerator->create_question('truefalse', null, ['category' => $qcategory->id, 'name' => 'q4']);

        return [$bloom, $difficulty, [$q1, $q2, $q3, $q4]];
    }

    /**
     * Run the given filter values through build_query_from_filter() and return matched question ids.
     *
     * @param array $values composite "fieldid:optionvalue" strings.
     * @param int|null $jointype
     * @return int[] matched question ids.
     */
    private function matched_question_ids(array $values, ?int $jointype = null): array {
        global $DB;

        $filter = ['values' => $values];
        if ($jointype !== null) {
            $filter['jointype'] = $jointype;
        }
        [$where, $params] = customfields_condition::build_query_from_filter($filter);
        if ($where === '') {
            return [];
        }
        $rows = $DB->get_records_sql("SELECT q.id FROM {question} q WHERE $where", $params);
        return array_map('intval', array_keys($rows));
    }

    /**
     * Empty values must produce no clause.
     */
    public function test_no_selection_produces_no_clause(): void {
        $this->resetAfterTest();
        [$where, $params] = customfields_condition::build_query_from_filter(['values' => []]);
        $this->assertSame('', $where);
        $this->assertSame([], $params);
    }

    /**
     * Selecting a single value on a single field matches only the questions holding that value.
     */
    public function test_single_field_single_value(): void {
        $this->resetAfterTest();
        [$bloom, , $questions] = $this->setup_fields_and_questions();
        [$q1] = $questions;

        $matched = $this->matched_question_ids(["{$bloom->get('id')}:1"]);

        $this->assertEqualsCanonicalizing([$q1->id], $matched);
    }

    /**
     * Selecting multiple values on the SAME field is OR'd: any matching value is enough.
     */
    public function test_multiple_values_same_field_are_ored(): void {
        $this->resetAfterTest();
        [$bloom, , $questions] = $this->setup_fields_and_questions();
        [$q1, $q2, $q3] = $questions;

        $matched = $this->matched_question_ids(["{$bloom->get('id')}:1", "{$bloom->get('id')}:2"]);

        // q1 (bloom=1) and q2+q3 (bloom=2) all match; q4 (no data) does not.
        $this->assertEqualsCanonicalizing([$q1->id, $q2->id, $q3->id], $matched);
    }

    /**
     * Selecting one value from each of TWO DIFFERENT fields is AND'd: both must match.
     */
    public function test_multiple_fields_are_anded(): void {
        $this->resetAfterTest();
        [$bloom, $difficulty, $questions] = $this->setup_fields_and_questions();
        [, $q2] = $questions;

        $matched = $this->matched_question_ids([
            "{$bloom->get('id')}:2",
            "{$difficulty->get('id')}:2",
        ]);

        // Only q2 has bloom=2 AND difficulty=2; q3 has bloom=2 but difficulty=1.
        $this->assertEqualsCanonicalizing([$q2->id], $matched);
    }

    /**
     * A field configured as not visible must not appear in get_initial_values().
     */
    public function test_field_with_no_visible_permission_is_excluded(): void {
        $this->resetAfterTest();
        $cfgenerator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $cfcategory = $cfgenerator->create_category([
            'component' => 'qbank_customfields',
            'area' => 'question',
            'itemid' => 0,
        ]);

        // VISIBLETOALL (2): should appear.
        $this->create_select_field($cfcategory->get('id'), 'visiblefield', ['A', 'B'], 2);
        // NOTVISIBLE (0): should never appear, regardless of the current user.
        $this->create_select_field($cfcategory->get('id'), 'hiddenfield', ['C', 'D'], 0);

        $condition = new customfields_condition();
        $titles = array_column($condition->get_initial_values(), 'title');

        $this->assertContains('Visiblefield: A', $titles);
        $this->assertContains('Visiblefield: B', $titles);
        $this->assertNotContains('Hiddenfield: C', $titles);
        $this->assertNotContains('Hiddenfield: D', $titles);
    }
}
