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
 * Upgrade steps for qbank_cffpoc.
 *
 * @package    qbank_cffpoc
 * @copyright  2026 Thomas Korner <thomas.korner@edu.zh.ch>
 * @author     Thomas Korner <https://github.com/tkorner>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade steps for qbank_cffpoc.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_qbank_cffpoc_upgrade(int $oldversion): bool {
    if ($oldversion < 2026070401) {
        // 'fieldshortname' was the single-field PoC's settings.php config; the combined filter
        // covers every field automatically and never reads it, but removing settings.php alone
        // does not clean up a value already saved by earlier installs.
        unset_config('fieldshortname', 'qbank_cffpoc');

        upgrade_plugin_savepoint(true, 2026070401, 'qbank', 'cffpoc');
    }

    return true;
}
