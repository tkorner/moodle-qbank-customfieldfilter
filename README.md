# qbank_customfieldfilter

A Moodle **Question Bank filter plugin** that makes every configured question
custom field filterable/searchable in the Question Bank UI at once, via a single
combined filter chip.

> **Field-type scope (current):** only `select` and `checkbox` custom fields are
> supported. `text`/`date`/other field types are not yet included — see
> "Known limitations" below.

- Depends on: `qbank_customfields`
- Status: Alpha, multi-field combined filter

---

## Upstream intent

This plugin is a **working prototype**, built with the *intent* to eventually
propose this functionality for inclusion directly in `qbank_customfields`
(rather than staying a separate standalone plugin) — but this has **not yet
been discussed with the Moodle qbank/customfields core developers**. All
design decisions below (one combined filter, the custom JS filtertype, the
join-type semantics, etc.) were made unilaterally to get to a working
prototype quickly, not because core developers requested or reviewed them.
Sharing this prototype with them for feedback is the logical next step.

- It's prototyped here in `qbank_customfieldfilter` first for fast iteration.
- Class/method names and structure mirror what already exists in
  `qbank_customfields` as closely as possible, on the assumption that this
  would make a later contribution closer to copy-paste — this assumption
  itself hasn't been validated with core developers yet.
- The code quality bar already targets **Moodle core contribution standards**
  (coding guidelines, full test coverage), not just "good enough for a single
  instance" — see "Coding standards" below.
- The actual core contribution (fork of `moodle/moodle`, MDL tracker issue,
  peer review) is a separate, later step.

---

## Install / environment

The plugin lives on the host at `plugins/qbank/customfieldfilter/` and is live-mounted into
the Moodle container via `docker-compose.yml`:

```yaml
- ./plugins/qbank/customfieldfilter:/var/www/html/public/question/bank/customfieldfilter
```

No manual copying or ownership fixes are needed — edits on the host are picked
up immediately (PHP changes still need a cache purge; structural changes need
an upgrade run):

```bash
docker compose exec moodle php /var/www/html/admin/cli/purge_caches.php
docker compose exec moodle php /var/www/html/admin/cli/upgrade.php --non-interactive
```

There is no plugin settings page — the filter automatically covers every
question custom field the current user is allowed to see. Nothing to configure
beyond creating the custom fields themselves under
*Site admin → Question bank → Custom fields* (component `qbank_customfields`,
area `question`).

---

## Architecture & key concepts

### Why NOT "one filter instance per field"

`core_question\local\bank\condition::get_condition_key()` is declared
**abstract and static**. Unlike
`qbank_customfields\custom_field_column::get_column_name()` (an *instance*
method that safely returns `$this->field->get('shortname')`), a static method
has no access to instance state (`$this`). This means every instance of one
`condition` subclass returns the **same** key — you cannot create N filter
chips (one per custom field) by instantiating the same class N times with
different `$field` arguments. This is a real API constraint, confirmed by
reading Moodle core's actual `condition.php` and `custom_field_column.php`.

**Decision made unilaterally to work around this constraint: build ONE
combined filter**, not one filter per field. Not yet reviewed by core
developers.

### How Moodle Question Bank filters work (since Moodle 4.3)

1. `plugin_feature.php` → `get_question_filters()` returns condition instances.
2. Each condition extends `core_question\local\bank\condition`.
3. Key methods:
   - `get_condition_key()` – **static**, fixed string for the whole plugin (`'customfields'`)
   - `get_title()` – label shown in the UI, e.g. "Custom fields"
   - `get_filter_class()` – **required here** (not optional), points to the
     custom JS filtertype module that keeps composite values intact — see
     "Why a custom JS filtertype is needed" below
   - `get_initial_values()` – **instance method**, returns the full flat option list
   - `build_query_from_filter(array $filter)` – static, returns `[$where, $params]`
   - `allow_custom()` – returns `false` (fixed options only)

### The combined-filter design

`get_initial_values()` iterates ALL configured custom fields **of a supported
type (`select`/`checkbox` only)**, reusing
`question_handler::create()->get_fields()` and `can_view_type($field,
$context)` — the exact same calls
`qbank_customfields\plugin_feature::get_question_columns()` already uses for
columns, so field iteration + permission checks are proven, reusable logic,
not new code. For each visible field, it emits one option per possible value:

```php
// Pseudocode
foreach ($fields as $field) {
    if (!$handler->can_view_type($field, $context)) continue;
    foreach ($field_options as $optionindex => $label) {
        $values[] = [
            'value' => $field->get('id') . ':' . $optionindex,   // composite key!
            'title' => $field->get_formatted_name() . ': ' . $label,  // e.g. "Bloom: Understand"
            'selected' => ...,
        ];
    }
}
```

- **Composite value** (`"fieldid:optionindex"`) is required because there's
  only one flat list, not a per-field list — this is how
  `build_query_from_filter()` later knows which field a selected value
  belongs to.
- **Title includes the field name** (`"Bloom: Understand"`, not just
  `"Understand"`) to avoid ambiguity when different fields happen to share
  option labels.
- A custom JS filtertype class is required for this to work at all — see
  below.

### Why a custom JS filtertype is needed

The default `core/datafilter/filtertype` JS class's `values` getter runs
every raw option value through `parseInt(value, 10)` before submitting the
filter:

```js
// lib/amd/src/datafilter/filtertype.js
get values() {
    return this.rawValues.map(option => parseInt(option, 10));
}
```

For plain integer values (tag ids, category ids — everything every other
core condition uses) this is harmless. For our composite
`"fieldid:optionindex"` string values it silently truncates them at the
first non-digit character (`"12:3"` becomes `12`), so the field id survives
but the option index is lost. The symptom in the browser: the dropdown
renders correctly and selections display correctly (that path uses the
PHP-side `get_initial_values()` `selected` flags, a different code path),
but applying the filter changes nothing — `build_query_from_filter()`
silently receives malformed/incomplete values and returns no WHERE clause.

**Fix:** override `get_filter_class()` to point at a small custom AMD module
that extends the base filtertype and overrides just the `values` getter to
return `this.rawValues` unparsed — exactly what core's own
`core/datafilter/filtertypes/keyword.js` does for free-text values:

```js
// amd/src/customfields_filtertype.js
import Filter from 'core/datafilter/filtertype';
export default class extends Filter {
    get values() {
        return this.rawValues;
    }
}
```

Then `get_filter_class()` returns
`'qbank_customfieldfilter/customfields_filtertype'`.

**Toolchain caveat:** `amd/build/customfields_filtertype.min.js` is
hand-built (no Node/grunt toolchain in the dev container used for this
plugin) to match the exact AMD-wrapper shape Moodle's own compiled modules
use (compare `lib/amd/build/datafilter/filtertypes/keyword.min.js`). If a
proper JS toolchain becomes available, regenerate it from `amd/src/` via
`grunt amd` instead of hand-editing the build file. After any change under
`amd/`, a full cache purge is required (JS is served with a cache-busting
`jsrev`), and the browser's own JS cache should be hard-refreshed too.

### Query logic — the join-type nuance

Selected composite values must be **grouped by field id** before building SQL:

- **Within the same field:** always OR (via a single `IN (...)`). A
  (typically single-select) custom field can only hold one value per
  question, so selecting `Bloom: Understand` + `Bloom: Apply` must mean
  "either of these", not "both" (which would be impossible and silently
  return zero results).
- **Across different fields:** depends on the filter's join type, exactly
  like `qbank_tagquestion\tag_condition` (every `condition` subclass
  inherits a None/Any/All selector from `get_join_list()`):
  - `JOINTYPE_ALL`: AND. Selecting `Bloom: Understand` + `Difficulty: Hard`
    means the question must match both.
  - `JOINTYPE_ANY`: OR. The question must match at least one of the
    selected fields.
  - `JOINTYPE_NONE`: each field-group's `IN` becomes `NOT IN`, groups stay
    ANDed together (a question must match none of the selected values in
    any field — AND-of-NOT-INs is a NOR, which is the correct "matches
    none of the selected options" semantics).

**UI caveat:** this class declares `JOINTYPE_DEFAULT = datafilter::JOINTYPE_ALL`,
but that constant is only the PHP-side fallback used when `$filter['jointype']`
is entirely absent from the request (e.g. a programmatic/API caller). The
live UI never omits it —
`lib/templates/datafilter/filter_row.mustache` (the shared template for
every newly-added filter row, ours included) **hardcodes**
`<option selected value="1">Any</option>`, so a freshly-added "Custom
fields" row always submits `jointype=1` (Any) the first time "Apply
filters" is pressed, regardless of this class's `JOINTYPE_DEFAULT`
override. Core's own `tag_condition` has the exact same override and is
subject to the same template, so this isn't specific to this plugin — just
something to know when manually testing or writing Behat coverage: don't
assume "All" is pre-selected, set the filter's own "Match" dropdown
explicitly.

Resulting SQL shape (pseudocode, one subquery per distinct field in the
selection, combined per the join type above; values within each subquery's
`IN (...)` are always ORed by SQL semantics):

```sql
q.id IN (
    SELECT instanceid FROM {customfield_data}
     WHERE fieldid = :field1 AND intvalue IN (:val1a, :val1b)   -- OR within field 1
)
AND -- or OR, if jointype is ANY
q.id IN (
    SELECT instanceid FROM {customfield_data}
     WHERE fieldid = :field2 AND intvalue IN (:val2a)           -- OR within field 2
)
```

### Custom field data storage

- Custom field values for questions are stored in `{customfield_data}`.
- Select fields store the **1-based index** of the chosen option in `intvalue`.
- Field options are stored as newline-separated text in
  `{customfield_field}.configdata`.
- The field is resolved via `qbank_customfields\customfield\question_handler`.
- `get_condition_key()`'s return value (`'customfields'`) is a single fixed
  string for the whole plugin. This is intentional, not a shortcut — see
  "Why NOT one filter instance per field" above.

---

## Coding standards

Follows **Moodle coding standards** — the quality bar targets core
contribution grade:

- Namespace: `qbank_customfieldfilter`
- PHP: no short tags, no `var`, always `defined('MOODLE_INTERNAL') || die();`
  in non-class files
- DocBlocks: `@package qbank_customfieldfilter`, `@copyright`, `@license`
- Uses the Moodle DB API (`$DB->get_records()`, `$DB->get_in_or_equal()`
  etc.) — never raw SQL outside `build_query_from_filter`
- Uses `get_string('key', 'qbank_customfieldfilter')` for all user-visible
  strings, added to `lang/en/qbank_customfieldfilter.php`
- Uses the `#[\Override]` attribute on overridden methods (PHP 8.3+,
  Moodle 5.x standard)
- Full PHPUnit test coverage, not just a starter test

---

## Testing

### Automated (PHPUnit)

```bash
docker compose exec moodle sh -c 'cd /var/www/html && vendor/bin/phpunit --testsuite qbank_customfieldfilter_testsuite'
```

`tests/condition_test.php` covers: a single field/value, multiple values on
the same field (OR), multiple fields under each join type (All/Any/None), no
selection, and a field with no view permission being excluded from the option
list. Fixtures are built with the real `core_customfield` and `core_question`
generators, no mocking.

If the PHPUnit test environment hasn't been initialised yet in this container,
set it up once (needs `$CFG->phpunit_prefix` / `$CFG->phpunit_dataroot` in
`config.php`):

```bash
docker compose exec moodle php /var/www/html/admin/tool/phpunit/cli/init.php
```

### Automated (Behat)

`tests/behat/filter_customfields.feature` automates the manual UI steps below:
a single value, multiple values on the same field (OR), and the All/Any/None
join type across two fields. Not runnable in a dev container without
Selenium/Chrome set up — it runs via `moodle-plugin-ci behat` in CI. Tagged
`@qbank @qbank_customfieldfilter` (`moodle-plugin-ci behat`'s `--tags` option
defaults to the component name when not passed explicitly, and separately
`moodle-plugin-ci validate` checks for a tag matching the plugin type too).

### Manual (UI)

Uses a `bloom` custom field (select type) for the Bloom's Taxonomy level of
a question, with options `Remember`, `Understand`, `Apply`, `Analyze`,
`Evaluate`, `Create` (values stored as 1-based integers in
`{customfield_data}.intvalue`), plus a second select/checkbox field for
cross-field testing.

0. After any change under `amd/`, run a full cache purge AND hard-refresh
   the browser (the JS filtertype module is cached client-side too) —
   otherwise you'll be testing stale JS.
1. Configure at least 2 question custom fields (*Site admin → Question bank →
   Custom fields*), e.g. `bloom` (select) and a second select/checkbox field.
2. Open the **Question Bank** in any course.
3. A single **"Custom fields"** filter should appear in the filter bar,
   listing every option from every configured field (e.g. "Bloom: Understand",
   "Difficulty: Hard").
4. Select two values from the SAME field (e.g. two Bloom levels) → should
   return questions matching EITHER value (OR).
5. Select one value from each of TWO DIFFERENT fields. The filter's own
   "Match" selector shows "Any" pre-selected the first time (a core UI
   quirk, not this plugin's default — see the "UI caveat" note above), so
   switch it to All → should return only questions matching BOTH (AND);
   switch to Any → should return questions matching EITHER field; switch to
   None → should return only questions matching NEITHER selected value.
6. Select a value, then remove a question's custom field data entirely →
   question should drop out of the filtered results.

Note: the dropdown rendering/selecting correctly is **not** sufficient
evidence that filtering works — that part is driven by
`get_initial_values()`'s `selected` flags, a separate code path from the
actual value submission. Always confirm the question LIST actually changes
after clicking "Apply filters".

---

## Known limitations

- Only `select` and `checkbox` field types are handled. `text`/`date`/other
  types need a UI design decision before they can join the same combined
  filter (free text vs. fixed options).
- No column implementation needed here — `qbank_customfields` already
  provides a column per field; only the filter was missing.
- `get_condition_key()` is a single fixed string (`'customfields'`) for the
  whole plugin. This is intentional, not a shortcut — see "Why NOT one
  filter instance per field" above.
- `amd/build/customfields_filtertype.min.js` is hand-built rather than
  generated by `grunt amd` (no Node/grunt toolchain available where this
  plugin is developed) — see the toolchain caveat above.

---

## Continuous integration

GitHub Actions (`.github/workflows/ci.yml`) runs
[`moodle-plugin-ci`](https://github.com/moodlehq/moodle-plugin-ci) on every
push/PR to `main`: linting, the Moodle code checker, PHPDoc checks, Mustache
lint, Grunt, PHPUnit and Behat, against Moodle's minimum-supported branch
(`MOODLE_501_STABLE`, matching `version.php`'s `5.1+` requirement) and `main`
for forward-compatibility.

The Grunt step is non-blocking (`continue-on-error: true`) for now: the CI
runner's own `grunt amd` run confirms the hand-built
`customfields_filtertype.min.js` doesn't byte-for-byte match what it would
generate (a formatting/build-process difference, not a functional one — the
JS logic itself is correct, confirmed by composite filter values reaching
the server unparsed in real test runs). Revisit once the hand-built file is
regenerated via a real toolchain.

---

## References

- [Moodle Question Bank plugins dev docs](https://moodledev.io/docs/apis/plugintypes/qbank)
- `qbank_customfields/classes/plugin_feature.php` – shows the
  field-iteration + `can_view_type()` pattern reused for the filter
- `qbank_customfields/classes/custom_field_column.php` – shows the
  per-field instance pattern (columns), and why it doesn't directly
  transfer to filters (static vs. instance methods)
- `question/classes/local/bank/condition.php` – the abstract base class;
  confirms `get_condition_key()` is static
- [qbank_tagquestion](https://github.com/moodle/moodle/tree/main/question/bank/tagquestion) –
  reference for a single, simpler filter implementation

---

## Author

Thomas Korner — [github.com/tkorner](https://github.com/tkorner)
