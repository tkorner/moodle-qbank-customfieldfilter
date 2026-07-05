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
 * Combined filter condition for all configured question custom fields.
 *
 * @package    qbank_customfieldfilter
 * @copyright  2026 Thomas Korner <thomas.korner@edu.zh.ch>
 * @author     Thomas Korner <https://github.com/tkorner>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qbank_customfieldfilter;

use context;
use context_system;
use core\output\datafilter;
use core_customfield\field_controller;
use core_question\local\bank\condition;
use qbank_customfields\customfield\question_handler;

/**
 * Filters questions by the values of ALL visible checkbox/select custom fields at once.
 *
 * Modeled on {@see \qbank_tagquestion\tag_condition} for the constructor/filter pattern, and on
 * {@see \qbank_customfields\plugin_feature::get_question_columns()} for field iteration and
 * visibility checks.
 *
 * {@see get_condition_key()} is abstract and static on the base class, so a single instance of
 * this class must represent every field at once (one composite "fieldid:optionindex" value per
 * option) rather than one instance per field. See the plugin's CLAUDE.md for the full rationale.
 *
 * Checkbox and select values are stored in {customfield_data}.intvalue.
 */
class customfields_condition extends condition {
    /** @var int Default join type. */
    const JOINTYPE_DEFAULT = datafilter::JOINTYPE_ALL;

    /** @var context|null Context to check field visibility against. */
    protected ?context $context = null;

    /** @var array Selected composite ("fieldid:optionindex") values from the current filter. */
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
        $this->context = $qbank->get_most_specific_context();
        $this->selectedvalues = $this->filter->values ?? [];
    }

    /**
     * Return the unique condition key for this filter.
     *
     * @return string
     */
    #[\Override]
    public static function get_condition_key() {
        return 'customfields';
    }

    /**
     * Return title of the condition.
     *
     * @return string
     */
    #[\Override]
    public function get_title() {
        return get_string('filtertitle', 'qbank_customfieldfilter');
    }

    /**
     * Return the Javascript filter class to provide the UI for this condition.
     *
     * The default core/datafilter/filtertype JS parses every value with parseInt(), which
     * would truncate our composite "fieldid:optionvalue" values (e.g. "12:3" -> 12).
     *
     * @return string
     */
    #[\Override]
    public function get_filter_class() {
        return 'qbank_customfieldfilter/customfields_filtertype';
    }

    /**
     * Initial values of the condition: one option per visible value of every configured field.
     *
     * Only fields of a type supported by {@see get_field_options()} (currently 'select' and
     * 'checkbox') contribute options; other field types are silently skipped for now.
     *
     * @return array
     */
    #[\Override]
    public function get_initial_values() {
        $handler = question_handler::create();
        $context = $this->context ?? context_system::instance();

        $values = [];
        foreach ($handler->get_fields() as $field) {
            if (!$handler->can_view_type($field, $context)) {
                continue;
            }
            foreach (self::get_field_options($field) as $optionvalue => $label) {
                $compositevalue = $field->get('id') . ':' . $optionvalue;
                $values[] = [
                    'value' => $compositevalue,
                    'title' => $field->get_formatted_name() . ': ' . $label,
                    'selected' => in_array($compositevalue, $this->selectedvalues),
                ];
            }
        }
        return $values;
    }

    /**
     * Return the possible option values for a single field, keyed by the value stored in intvalue.
     *
     * Only 'select' and 'checkbox' field types are supported.
     *
     * @param field_controller $field
     * @return array int (stored intvalue) => string (option label)
     */
    protected static function get_field_options(field_controller $field): array {
        $type = $field->get('type');

        if ($type === 'checkbox') {
            return [
                1 => get_string('yes'),
                0 => get_string('no'),
            ];
        }

        if ($type === 'select') {
            $options = $field->get_configdata_property('options') ?? '';
            $lines = preg_split('/\r\n|\r|\n/', (string) $options);
            $values = [];
            foreach ($lines as $idx => $label) {
                $label = trim($label);
                if ($label === '') {
                    continue;
                }
                $values[$idx + 1] = $label;
            }
            return $values;
        }

        // Other field types (text, date, ...) are not supported by this combined filter yet.
        return [];
    }

    /**
     * Build the WHERE clause from the selected composite filter values.
     *
     * Selected values are always grouped by field id first: within the same field, values are
     * ORed (a question can only hold one value per field). How the per-field groups are then
     * combined depends on the filter's join type, matching {@see \qbank_tagquestion\tag_condition}:
     * - JOINTYPE_ALL (default): groups are ANDed (question must match every selected field).
     * - JOINTYPE_ANY: groups are ORed (question must match at least one selected field).
     * - JOINTYPE_NONE: each group's IN becomes NOT IN, groups stay ANDed (question must match
     *   none of the selected values in any field).
     *
     * @param array $filter ['values' => string[] "fieldid:optionvalue", 'jointype' => int].
     * @return array [string $where, array $params].
     */
    #[\Override]
    public static function build_query_from_filter(array $filter): array {
        global $DB;

        $composites = array_filter($filter['values'] ?? [], static fn($v) => $v !== '' && $v !== null);
        if (empty($composites)) {
            return ['', []];
        }

        $byfield = [];
        foreach ($composites as $composite) {
            if (!preg_match('/^(\d+):(\d+)$/', (string) $composite, $matches)) {
                continue;
            }
            $byfield[(int) $matches[1]][] = (int) $matches[2];
        }
        if (empty($byfield)) {
            return ['', []];
        }

        $jointype = $filter['jointype'] ?? self::JOINTYPE_DEFAULT;
        $operator = ((int) $jointype === datafilter::JOINTYPE_NONE) ? 'NOT ' : '';

        $wheres = [];
        $params = [];
        $groupindex = 0;
        foreach ($byfield as $fieldid => $optionvalues) {
            $groupindex++;
            [$insql, $inparams] = $DB->get_in_or_equal($optionvalues, SQL_PARAMS_NAMED, "cffval{$groupindex}_");
            $fieldparam = "cffield{$groupindex}";

            // Checkbox and select store their value in intvalue.
            $wheres[] = "q.id {$operator}IN (
                        SELECT cfd.instanceid
                          FROM {customfield_data} cfd
                         WHERE cfd.fieldid = :{$fieldparam}
                           AND cfd.intvalue {$insql})";
            $params[$fieldparam] = $fieldid;
            $params += $inparams;
        }

        // Different fields are ORed together for "match any", ANDed otherwise (see docblock above).
        $glue = ((int) $jointype === datafilter::JOINTYPE_ANY) ? ' OR ' : ' AND ';
        $where = implode($glue, $wheres);

        return [$where, $params];
    }

    /**
     * Whether customisation is allowed. Only the fixed options built from configured field
     * values are supported, so no.
     *
     * @return bool
     */
    #[\Override]
    public function allow_custom() {
        return false;
    }
}
