# CLAUDE.md – qbank_cffpoc Plugin Project

## Project Overview

This is a Moodle **Question Bank filter plugin** (proof of concept) that makes a custom field
filterable/searchable in the Question Bank UI.

- **Plugin type:** `qbank` (Question Bank plugin)
- **Component name:** `qbank_cffpoc`
- **Target custom field:** `bloom` (select/Auswahlfeld, shortname configured in plugin settings)
- **Status:** Alpha / PoC – functional filter for a single select-type custom field

---

## Environment

| Item | Value |
|---|---|
| Moodle version | 5.2.x |
| PHP | via Docker container `claude-moodle-1` |
| Database | MariaDB (`claude-mariadb-1`) |
| Moodle root (host) | `/Users/tkorner/Documents/claude/plugins/` (mounted into Docker) |
| Plugin path (host) | `/Users/tkorner/Documents/claude/plugins/qbank/cffpoc/` |
| Plugin path (container) | `/var/www/html/question/bank/cffpoc/` |
| Local plugins (host) | `/Users/tkorner/Documents/claude/plugins/local/` |

### Useful Docker commands

```bash
# Purge Moodle caches (after PHP changes)
docker exec claude-moodle-1 php /var/www/html/admin/cli/purge_caches.php

# Run DB upgrade (after version.php bump)
docker exec claude-moodle-1 php /var/www/html/admin/cli/upgrade.php --non-interactive

# Run unit tests for this plugin
docker exec claude-moodle-1 php /var/www/html/vendor/bin/phpunit \
  --testsuite qbank_cffpoc_testsuite

# Tail Apache error log
docker exec claude-moodle-1 tail -f /var/log/apache2/error.log

# Open a shell in the container
docker exec -it claude-moodle-1 bash
```

---

## Plugin File Structure

```
qbank/cffpoc/
├── classes/
│   ├── customfield_condition.php   ← Core filter logic (WHERE clause builder)
│   ├── plugin_feature.php          ← Registers the filter with Question Bank
│   └── privacy/
│       └── provider.php            ← GDPR: no personal data stored
├── lang/
│   └── en/
│       └── qbank_cffpoc.php        ← English language strings
├── tests/
│   └── condition_test.php          ← PHPUnit tests
├── settings.php                    ← Admin setting: fieldshortname
├── version.php                     ← Plugin metadata & dependencies
└── README.md
```

---

## Architecture & Key Concepts

### How Moodle Question Bank filters work (since Moodle 4.3)

1. `plugin_feature.php` → `get_question_filters()` returns condition instances
2. Each condition extends `core_question\local\bank\condition`
3. Key methods:
   - `get_condition_key()` – unique string key for this filter
   - `get_title()` – label shown in the UI
   - `get_initial_values()` – dropdown options (from custom field config)
   - `build_query_from_filter(array $filter)` – static, returns `[$where, $params]`
   - `allow_custom()` – return false (fixed options only)

### Custom field data storage

- Custom field values for questions are stored in `{customfield_data}`
- Select fields store the **1-based index** of the chosen option in `intvalue`
- Field options are stored as newline-separated text in `{customfield_field}.configdata`
- The field is resolved via `qbank_customfields\customfield\question_handler`

### Current filter logic (`customfield_condition.php`)

```
Admin sets fieldshortname = "bloom"
  → plugin_feature reads config → instantiates customfield_condition
  → get_initial_values() reads options from field configdata
  → UI renders dropdown with Bloom taxonomy levels
  → build_query_from_filter() emits:
       q.id IN (SELECT instanceid FROM {customfield_data}
                 WHERE fieldid = :cffpocfield AND intvalue IN (...))
```

---

## Coding Standards

Follow **Moodle coding standards** strictly:

- Namespace: `qbank_cffpoc`
- PHP: no short tags, no `var`, always `defined('MOODLE_INTERNAL') || die();` in non-class files
- DocBlocks: `@package qbank_cffpoc`, `@copyright`, `@license`
- Use Moodle DB API (`$DB->get_records()`, `$DB->get_in_or_equal()` etc.) – never raw SQL outside `build_query_from_filter`
- Use `get_string('key', 'qbank_cffpoc')` for all user-visible strings
- Add new strings to `lang/en/qbank_cffpoc.php`
- Use `#[\Override]` attribute on overridden methods (PHP 8.3+, Moodle 5.x standard)
- After any change: run `php admin/cli/purge_caches.php`
- After `version.php` bump: run `php admin/cli/upgrade.php`

---

## Current Known Issues / TODOs

- [ ] Plugin settings page (`settings.php`) uses a plain text field for `fieldshortname` –
      ideally replace with a dropdown of available question custom fields
- [ ] `condition_test.php` may need updating for Moodle 5.2 API changes
- [ ] Only supports `select` and `checkbox` field types – `text` fields not in scope for PoC
- [ ] `get_condition_key()` returns a fixed string `'cffpoc'` – fine for PoC, needs to be
      dynamic (per field) in the full plugin
- [ ] No column implementation yet (only filter) – a matching `customfield_column` class
      would show the field value as a column in the Question Bank table

---

## Custom Field: `bloom`

- **Shortname:** `bloom`
- **Type:** Select (Auswahlfeld)
- **Purpose:** Bloom's Taxonomy level for a question
- **Expected options (configure in Moodle Admin → Question Bank → Custom Fields):**
  ```
  Erinnern
  Verstehen
  Anwenden
  Analysieren
  Bewerten
  Erschaffen
  ```
- Values stored as 1-based integers in `{customfield_data}.intvalue`

---

## How to Test Manually

1. Go to **Moodle Admin → Plugins → Question bank → Custom field filter (PoC)**
2. Set `fieldshortname` = `bloom`
3. Open **Question Bank** in any course
4. The "Bloom" filter should appear in the filter bar
5. Select one or more Bloom levels → question list filters accordingly

---

## References

- [Moodle Question Bank plugins dev docs](https://moodledev.io/docs/apis/plugintypes/qbank)
- [qbank_tagquestion](https://github.com/moodle/moodle/tree/main/question/bank/tagquestion) – reference implementation for tag-based filtering
- [core_question\local\bank\condition](https://github.com/moodle/moodle/blob/main/question/engine/bank.php) – base class
- [qbank_customfields](https://github.com/moodle/moodle/tree/main/question/bank/customfields) – custom field handler
