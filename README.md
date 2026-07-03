# qbank_cffpoc

A Moodle **Question Bank filter plugin** that makes every configured question
custom field filterable/searchable in the Question Bank UI at once, via a single
combined filter chip.

Working name; being prototyped here for eventual contribution into the
core-bundled `qbank_customfields` plugin (see "Upstream intent" in `CLAUDE.md`
for the full rationale and coordination context).

- Depends on: `qbank_customfields`
- Status: Alpha, multi-field combined filter (see `CLAUDE.md` for the full
  architecture writeup and the API constraints that shaped this design)

---

## Install / environment

The plugin lives on the host at `plugins/qbank/cffpoc/` and is live-mounted into
the Moodle container via `docker-compose.yml`:

```yaml
- ./plugins/qbank/cffpoc:/var/www/html/public/question/bank/cffpoc
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

## How it works

One combined filter ("Custom fields") lists every visible field's options as a
flat list, using a composite `fieldid:optionvalue` value per option (e.g.
`Bloom: Verstehen`). Selected values are grouped by field when building the
SQL: values within the same field are OR'd, different fields are AND'd
together. Full rationale — including why this is *one* filter instance
instead of one-per-field — is in `CLAUDE.md`.

Checkbox and select values are read from `{customfield_data}.intvalue` (select
options are 1-based indexes into the field's newline-separated option list).

---

## Testing

### Automated (PHPUnit)

```bash
docker compose exec moodle sh -c 'cd /var/www/html && vendor/bin/phpunit --testsuite qbank_cffpoc_testsuite'
```

`tests/condition_test.php` covers: a single field/value, multiple values on
the same field (OR), multiple fields (AND), no selection, and a field with no
view permission being excluded from the option list. Fixtures are built with
the real `core_customfield` and `core_question` generators, no mocking.

If the PHPUnit test environment hasn't been initialised yet in this container,
set it up once (needs `$CFG->phpunit_prefix` / `$CFG->phpunit_dataroot` in
`config.php`):

```bash
docker compose exec moodle php /var/www/html/admin/tool/phpunit/cli/init.php
```

### Manual (UI)

1. Configure at least 2 question custom fields (*Site admin → Question bank →
   Custom fields*), e.g. `bloom` (select) and a second select/checkbox field.
2. Open the **Question Bank** in any course.
3. A single **"Custom fields"** filter should appear in the filter bar,
   listing every option from every configured field (e.g. "Bloom: Verstehen",
   "Difficulty: Hard").
4. Select two values from the SAME field → should return questions matching
   EITHER value.
5. Select one value from each of TWO DIFFERENT fields → should return only
   questions matching BOTH.

---

## Known limitations

- Only `select` and `checkbox` field types are handled. `text`/`date`/other
  types need a UI design decision before they can join the same combined
  filter (free text vs. fixed options) — see the TODOs in `CLAUDE.md`.
- No column implementation needed here — `qbank_customfields` already
  provides a column per field; only the filter was missing.
- `get_condition_key()` is a single fixed string (`'customfields'`) for the
  whole plugin. This is intentional, not a shortcut: `condition::get_condition_key()`
  is abstract *and static*, so it can't return per-instance state — see
  "Why NOT one filter instance per field" in `CLAUDE.md`.
