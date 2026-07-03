# qbank_cffpoc — Proof of Concept

**Goal:** verify the question-bank filter API on a real Moodle 5.2 instance before
building the full dynamic plugin. This PoC adds **one** filter for **one**
admin-chosen custom field (checkbox or select).

- Install path: `{moodle_root}/public/question/bank/cffpoc/`  (note: `/public/` on 5.1+)
- Depends on: `qbank_customfields`

---

## What we are verifying

This PoC exists to confirm three uncertain API points against your instance,
because the code was written from core source, not from a running system:

1. **The condition lifecycle.** Does `plugin_feature::get_question_filters()`
   correctly surface a filter, and does `customfield_condition` extend
   `core_question\local\bank\condition` without fatal errors?
2. **The value column.** Checkbox/select values are assumed to live in
   `{customfield_data}.intvalue`. This must be confirmed with a real row.
3. **The select option indexing.** Options are assumed 1-based. Confirm against
   a real select field's stored value.

---

## Setup on your instance

Container: `moodle-test-moodle-1`, webroot `/var/www/html`, code under `/public`.

```bash
# 1. Copy the plugin in (from the host, adjust source path):
docker cp ./qbank_cffpoc moodle-test-moodle-1:/var/www/html/public/question/bank/cffpoc

# 2. Fix ownership (image runs as nobody):
docker exec --user root moodle-test-moodle-1 chown -R nobody:nobody \
  /var/www/html/public/question/bank/cffpoc

# 3. Run the upgrade:
docker exec moodle-test-moodle-1 php /var/www/html/public/admin/cli/upgrade.php --non-interactive
```

Then in the UI:
1. *Site admin → Plugins → Question custom fields* — create a **checkbox** field,
   e.g. shortname `reviewed`. Add a couple of questions, tick it on some.
2. *Site admin → Plugins → Question bank plugins* — enable "Custom field filter (PoC)".
3. *Site admin → Plugins → Question bank plugins → Custom field filter (PoC) settings*
   — set "Custom field shortname" to `reviewed`.
4. Open a course question bank → Filters → you should see a "Reviewed" filter
   offering Yes/No.

---

## The three verification commands

Run these and check the results against the assumptions:

```bash
# A. Confirm the value column. After ticking the checkbox on a question,
#    inspect the stored row. Expect intvalue = 1, value/charvalue empty.
docker exec moodle-test-moodle-1 php /var/www/html/public/admin/cli/cfg.php 2>/dev/null; \
docker exec moodle-test-moodle-1 sh -c 'php -r "
define(\"CLI_SCRIPT\", true);
require(\"/var/www/html/public/config.php\");
\$rows = \$DB->get_records(\"customfield_data\", null, \"\", \"id,fieldid,instanceid,intvalue,value\", 0, 5);
foreach (\$rows as \$r) { echo implode(\" | \", (array)\$r), PHP_EOL; }
"'
```

```bash
# B. Run the PoC unit tests (confirms no fatal errors in the class structure).
#    Requires the phpunit test environment (see note below).
docker exec moodle-test-moodle-1 sh -c 'cd /var/www/html && vendor/bin/phpunit --filter qbank_cffpoc' 2>&1 | tail -20
```

```bash
# C. Watch the live filter SQL. Enable full debugging in the UI first
#    (Site admin → Development → Debugging → DEVELOPER), then apply the filter
#    and check that questions filter correctly. If results are wrong, the
#    intvalue assumption (A) is the first thing to check.
```

---

## PHPUnit environment note

This Bitnami-style/Alpine image may not have the phpunit test DB initialised.
If command B fails with "PHPUnit is not configured", initialise it once:

```bash
# Requires a SECOND empty database + $CFG->phpunit_* in config.php.
docker exec moodle-test-moodle-1 php /var/www/html/public/admin/tool/phpunit/cli/init.php
```

If that is too heavy for the PoC, skip B and rely on A + C — the manual UI test
plus the DB inspection is sufficient to validate the three API points.

---

## What to report back

After running A and the UI test, tell me:

1. **Column:** Is the value in `intvalue` (as assumed) or in `value`/`charvalue`?
2. **Select indexing:** For a select field, what integer is stored for the 2nd option?
3. **Did the filter appear and filter correctly?** Any PHP errors with debugging on?

With those three answers I will either confirm the design and build the full
dynamic plugin, or correct the value-column / indexing logic first.

---

## Known PoC limitations (by design)

- One field only, chosen by admin setting. The full plugin iterates all fields.
- `get_condition_key()` returns a single fixed key `cffpoc`. The full plugin
  needs a unique key per field (one condition subclass per field).
- No JS filtertype override — uses the core autocomplete. Fine for verification.
- Unit test covers only the no-field path; the positive SQL path is verified
  manually (command A + C) because it needs a real `field_controller`.
