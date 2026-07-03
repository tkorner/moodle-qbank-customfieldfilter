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
 * Settings for qbank_cffpoc.
 *
 * For the proof of concept the admin selects ONE custom field (by shortname)
 * that the filter will operate on. The full plugin will iterate all fields.
 *
 * @package    qbank_cffpoc
 * @copyright  2026 Thomas <thomas@example.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings->add(new admin_setting_configtext(
        'qbank_cffpoc/fieldshortname',
        get_string('fieldshortname', 'qbank_cffpoc'),
        get_string('fieldshortname_desc', 'qbank_cffpoc'),
        '',
        PARAM_ALPHANUMEXT
    ));
}
