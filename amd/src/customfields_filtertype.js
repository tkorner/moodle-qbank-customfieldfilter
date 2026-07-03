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
 * Filter type for the combined custom fields filter.
 *
 * The base core/datafilter/filtertype class's `values` getter runs every raw option value
 * through parseInt(), which silently truncates our composite "fieldid:optionvalue" values
 * (e.g. "12:3" becomes 12). This override keeps the raw string values intact, the same way
 * core/datafilter/filtertypes/keyword does for free-text values.
 *
 * @module     qbank_cffpoc/customfields_filtertype
 * @copyright  2026 Thomas <thomas@example.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import Filter from 'core/datafilter/filtertype';

export default class extends Filter {
    /**
     * Composite "fieldid:optionvalue" values must be sent to the server unparsed.
     *
     * @returns {Array}
     */
    get values() {
        return this.rawValues;
    }
}
