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
 * Proof-of-concept filter condition for a single question custom field.
 *
 * @package    qbank_cffpoc
 * @copyright  2026 Thomas <thomas@example.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qbank_cffpoc;

use core\output\datafilter;
use core_question\local\bank\condition;
use qbank_customfields\customfield\question_handler;

/**
 * Filters questions by the value of a single checkbox/select custom field.
 *
 * Modeled on {@see \qbank_tagquestion\tag_condition}:
 * - Non-static methods (constructor, get_initial_values) read context from $qbank.
 * - The static build_query_from_filter() reads everything from $filter and emits
 *   a "q.id IN (subquery)" clause, so it needs no instance state.
 *
 * Checkbox and select values are stored in {customfield_data}.intvalue.
 */
class customfield_condition extends condition {

    /** @var int Default join type. */
    const JOINTYPE_DEFAULT = datafilter::JOINTYPE_ANY;

    /** @var \core_customfield\field_controller|null The configured field. */
    protected $field = null;

    /** @var array Selected values from the current filter. */
    protected array $selectedvalues = [];

    /**
     * Constructor.
     *
     * @param mixed $qbank The question bank view, or null.
     */
    public function __construct($qbank = null) {
        if (is_null($qbank)) {
            return;
        }
        parent::__construct($qbank);
        $this->field = self::get_configured_field();
        $this->selectedvalues = $this->filter->values ?? [];
    }

    /**
     * Resolve the admin-configured field_controller, or null if not found.
     *
     * @return \core_customfield\field_controller|null
     */
    public static function get_configured_field() {
        $shortname = get_config('qbank_cffpoc', 'fieldshortname');
        if (empty($shortname)) {
            return null;
        }
        $handler = question_handler::create();
        foreach ($handler->get_fields() as $field) {
            if ($field->get('shortname') === $shortname) {
                return $field;
            }
        }
        return null;
    }

    #[\Override]
    public static function get_condition_key() {
        // Single fixed key for the PoC. The full plugin needs one key per field.
        return 'cffpoc';
    }

    #[\Override]
    public function get_title() {
        if ($this->field) {
            return $this->field->get_formatted_name();
        }
        return get_string('pluginname', 'qbank_cffpoc');
    }

    #[\Override]
    public function get_initial_values() {
        $field = $this->field ?? self::get_configured_field();
        if (!$field) {
            return [];
        }

        $type = $field->get('type');
        if ($type === 'checkbox') {
            return [
                ['value' => 1, 'title' => get_string('yes'), 'selected' => in_array(1, $this->selectedvalues)],
                ['value' => 0, 'title' => get_string('no'), 'selected' => in_array(0, $this->selectedvalues)],
            ];
        }

        // Select field: options come from configdata, 1-based index.
        $options = $field->get_configdata_property('options') ?? '';
        $values = [];
        $lines = preg_split('/\r\n|\r|\n/', (string) $options);
        foreach ($lines as $idx => $label) {
            $label = trim($label);
            if ($label === '') {
                continue;
            }
            $values[] = [
                'value' => $idx + 1,
                'title' => $label,
                'selected' => in_array($idx + 1, $this->selectedvalues),
            ];
        }
        return $values;
    }

    /**
     * Build the WHERE clause from the selected filter values.
     *
     * Static, per the base class. Reads the configured field id afresh because
     * static context has no access to $this.
     *
     * @param array $filter ['values' => int[], 'jointype' => int].
     * @return array [string $where, array $params].
     */
    #[\Override]
    public static function build_query_from_filter(array $filter): array {
        global $DB;

        $values = array_filter($filter['values'] ?? [], static fn($v) => $v !== '' && $v !== null);
        if (empty($values)) {
            return ['', []];
        }

        $field = self::get_configured_field();
        if (!$field) {
            return ['', []];
        }
        $fieldid = (int) $field->get('id');

        $jointype = $filter['jointype'] ?? self::JOINTYPE_DEFAULT;
        $operator = ((int) $jointype === datafilter::JOINTYPE_NONE) ? 'NOT ' : '';

        [$insql, $params] = $DB->get_in_or_equal($values, SQL_PARAMS_NAMED, 'cffpoc');
        $params['cffpocfield'] = $fieldid;

        // Checkbox and select store their value in intvalue.
        $where = "q.id {$operator}IN (
                    SELECT cfd.instanceid
                      FROM {customfield_data} cfd
                     WHERE cfd.fieldid = :cffpocfield
                       AND cfd.intvalue {$insql})";

        return [$where, $params];
    }

    #[\Override]
    public function allow_custom() {
        return false;
    }
}
