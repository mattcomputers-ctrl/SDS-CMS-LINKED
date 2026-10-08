# SDS System — Operations Cheat Sheet

Run from anywhere as user `matt`; the commands handle the `sudo -u www-data`
switching internally where needed.

---

## Deploy

```bash
# Standard deploy — code changes only (no migrations or composer)
sudo -u www-data git -C /var/www/sds-system fetch && \
sudo -u www-data git -C /var/www/sds-system reset --hard origin/main

# Full deploy — when migrations or composer.lock changed
sudo -u www-data git -C /var/www/sds-system fetch && \
sudo -u www-data git -C /var/www/sds-system reset --hard origin/main && \
sudo bash /var/www/sds-system/update.sh
```

---

## Bulk publish — status & control

```bash
# Is bulk publish running right now? How many workers?
pgrep -af 'publish-worker\.php' | wc -l        # 0 = idle, 1-20 = active

# More detail — elapsed time per worker + recent log lines
ps -eo pid,etime,cmd | grep -E 'publish-worker|bulk-publish' | grep -v grep | sort -k2 -r
tail -n 30 /var/www/sds-system/storage/logs/cms-sync.log

# Stop all workers gracefully (each finishes its current item, then exits)
sudo pkill -SIGTERM -f 'scripts/publish-worker.php'

# Check the job queue (last 10 jobs)
sudo -u www-data php -r '
$c = require "/var/www/sds-system/config/config.php"; $d=$c["db"];
$pdo = new PDO("mysql:host=".$d["host"].";dbname=".$d["name"].";charset=utf8mb4",$d["user"],$d["password"]);
foreach ($pdo->query("SELECT id, status, triggered_by, queued_at, started_at, completed_at,
                             eligible_fg_count, published_count, failed_count, error_message
                      FROM bulk_publish_jobs ORDER BY id DESC LIMIT 10") as $r) {
    printf("#%-4d %-10s by %-8s q=%s s=%s c=%s elig=%s pub=%s fail=%s%s\n",
        $r["id"], $r["status"], $r["triggered_by"],
        $r["queued_at"], $r["started_at"]??"—", $r["completed_at"]??"—",
        $r["eligible_fg_count"]??"—", $r["published_count"]??"—", $r["failed_count"]??"—",
        $r["error_message"] ? " err=".substr($r["error_message"],0,60) : "");
}
'
```

Admin UI for the queue: <https://sds-live/bulk-publish/queue> — Dismiss for
pending rows, Force fail for stuck running rows.

---

## CMS sync — status & settings

```bash
# Current sync settings (enabled, interval, last run, etc.)
sudo -u www-data php -r '
$c = require "/var/www/sds-system/config/config.php"; $d=$c["db"];
$pdo = new PDO("mysql:host=".$d["host"].";dbname=".$d["name"].";charset=utf8mb4",$d["user"],$d["password"]);
foreach ($pdo->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE \"cms_sync.%\"") as $r) {
    echo str_pad($r["key"], 32) . " = " . $r["value"] . "\n";
}
'

# Live tail the sync log
sudo tail -f /var/www/sds-system/storage/logs/cms-sync.log

# Cron entry (confirm schedule)
sudo -u www-data crontab -l | grep cms-sync
```

The sync runs at `7 * * * *` (every hour at HH:07). The
`cms_sync.interval_hours` setting only enforces the gate when set to a value
greater than 1, so `1` means "fire every cron tick".

---

## Wipe / regenerate

```bash
# Wipe all published SDSs (DB rows + PDFs; raw data untouched)
sudo -u www-data php /var/www/sds-system/scripts/wipe-all-sds.php --confirm

# Re-run regulatory seed (idempotent — only inserts missing rows)
sudo -u www-data php /var/www/sds-system/seeds/seed_regulatory.php

# Import OEHHA Prop 65 CSV (dry-run by default; --confirm to apply)
sudo -u www-data php /var/www/sds-system/scripts/import-prop65-list.php /tmp/p65chemicalslist.csv
sudo -u www-data php /var/www/sds-system/scripts/import-prop65-list.php /tmp/p65chemicalslist.csv --confirm

# Re-save all RMs with manual Prop 65 data (applies prune + freshness)
sudo -u www-data php /var/www/sds-system/scripts/resave-prop65-raw-materials.php           # dry-run
sudo -u www-data php /var/www/sds-system/scripts/resave-prop65-raw-materials.php --confirm

# Smoke test for SDS-bump suppression (mutates one RM, restores cleanly)
sudo -u www-data php /var/www/sds-system/scripts/smoke-test-sds-bump.php [<rm_id>]
```

---

## Download files past Incapsula (OEHHA / similar)

```bash
# OEHHA blocks default curl — pretend to be a browser
curl -A 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36' \
     -o /tmp/p65chemicalslist.csv \
     'https://oehha.ca.gov/sites/default/files/media/2025-01/p65chemicalslist.csv'
```

URL date slug (`2025-01/`) changes on each OEHHA release; grab the current
link from <https://oehha.ca.gov/proposition-65/proposition-65-list> if curl
404s in the future.

---

## Database query template

The SDS DB user has no socket auth, so you can't run `mysql` direct as
www-data. Wrap every ad-hoc query in this PHP-via-config.php pattern:

```bash
sudo -u www-data php -r '
$c = require "/var/www/sds-system/config/config.php"; $d=$c["db"];
$pdo = new PDO("mysql:host=".$d["host"].";dbname=".$d["name"].";charset=utf8mb4",$d["user"],$d["password"]);
foreach ($pdo->query("YOUR SQL HERE") as $r) { print_r($r); }
'
```

---

## System health

```bash
# RAM (look at "available", not "used" — Linux hoards cache)
free -h

# Top RAM consumers
ps aux --sort=-%mem | head -10

# Stuck / zombie processes related to the SDS pipeline
ps -eo pid,etime,cmd | grep -E 'cms-sync|publish-worker|bulk-publish' | grep -v grep

# Trace what a stuck process is waiting on (replace 12345)
sudo strace -p 12345 -s 100 -tt 2>&1 | head -40
sudo ls -l /proc/12345/fd/                # open file descriptors / sockets
```

---

## Common gotchas

- **`www-data` cannot run `mysql` directly** — always use the PHP-via-config
  pattern above.
- **Old crontab entries persist after package upgrades** — re-check
  `crontab -l` if scheduling looks off.
- **Workers killed mid-batch leave progress files saying `complete:false`**
  — the runner will detect this and abort within ~10s now (post-`ef6abf7`),
  but if you ever see a stuck runner from before that fix, kill the parent
  cms-sync.php process to release `GET_LOCK('bulk_publish_runner')`.
- **The "Auto bulk publish" admin toggle (`cms_sync.auto_bulk_publish`) only
  gates the cron-from-cms-sync path** — the admin Start button still works
  when it's off.

---

## Private label SDS (registry)

- `private_label_items` is the registry: one row = "manufacturer M sells
  finished good F under identity I", edited from `/private-label/manufacturer/{id}`.
- Identity resolves at publish time: `custom_code` (verbatim) → shared alias
  (`alias_id`, pack suffix stripped) → base FG code; description follows the
  same chain independently. The printed values are frozen into
  `private_label_sds.product_code` / `product_description`.
- Every base FG publish (manual, SDS Update Required, bulk + cron) cascades a
  new version to that FG's active `auto_republish = 1` items. The
  `/sds-updates` "Republish Private Labels Only" button is a re-brand only
  (manufacturer address/logo change) — it never bumps the base SDS.
- Migration 051 backfills one item per historical (manufacturer, FG, alias)
  combo, tagged in `notes` ("Backfilled ... migration 051"). Review them on
  `/private-label` and retire one-offs BEFORE the next bulk publish.
- On-disk PDF names carry the version: a published base/alias SDS is
  `{code}_v{n}.pdf` in the default language (`sds.default_language`, en —
  e.g. `UVNG009_v1.pdf`) and `{code}_v{n}_{lang}.pdf` for other languages
  (`UVNG009_v1_es.pdf`); a private label render is
  `{code}_PL_{Manufacturer}_v{n}[_{lang}].pdf`; only unversioned previews
  keep the `{code}_SDS_{lang}_{Ymd_His}.pdf` timestamp form. An exact-name
  clash gets `_2`, `_3`, … rather than overwriting. No random suffixes.
- Before adding the 052 unique index on `private_label_sds`, run
  `sudo -u www-data php /var/www/sds-system/scripts/check-pl-duplicates.php`
  and confirm it reports no duplicate (item_id, language, version) rows.
- `private_label_items.updated_at` and `manufacturers.updated_at` are
  staleness inputs: any metadata-only write to those tables must use
  `updated_at = updated_at` or every item will show as stale.

---

## SDS content policy

Fixed in code, not in admin settings (audit item #8). The constants and the
policy comment live in `src/Services/SDSGenerator.php` next to
`PRESCRIBED_RANGES` / `formatConcentration()`; change them, this section and
`docs/sds-content-audit.md` together.

- **Disclosure cut-off: 0.1 % w/w.** A constituent appears in Section 3 (and
  its OELs in Sections 8 and 11) only at >= 0.1 % of the finished good, and
  only if it is classified as hazardous or has an occupational exposure
  limit on file (29 CFR 1910.1200 App. D, Section 3(c)). 0.1 % is the lowest
  ingredient cut-off in App. A, so nothing that can drive a classification
  is hidden. OEL rows between 0.01 % and 0.1 % print `<0.1%` in Section 8.
- **Exact percentages are never printed.** Section 3, the Section 8
  "Conc%" column, the Section 11 carcinogenicity line and component
  toxicology block, and the Section 12 component aquatic table show the
  widest prescribed band that fully contains the actual concentration (or
  the supplier min–max range when known); if no single band contains it,
  the widest band containing the midpoint. Sections 8, 11 and 12 reuse the
  Section 3 band for the same CAS, so the sections cannot disagree. Bands are the prescribed concentration ranges of
  29 CFR 1910.1200(i)(1) (May 2024 final rule, 89 FR 44144; same table as
  Canada HPR s. 5.7(1)): 0.1–1, 0.5–1.5, 1–5, 3–7, 5–10, 7–13, 10–30,
  15–40, 30–60, 45–70, 60–80, 65–85, 80–100 %.
- Because exact percentages are withheld on every row, the 29 CFR
  1910.1200(i)(1) withholding statement (`section3.trade_secret_note`)
  prints on every SDS that lists components, not only when a raw material
  is flagged as a trade secret.
- Section 15 SARA 313 and HAP weight percentages are exact on purpose
  (40 CFR 372.45(b)(2) supplier notification requires percent by weight).
- SDS snapshots generated before Section 8/11/12 banding carry no band; a
  re-render from such a snapshot (send queue, private-label re-brand)
  shows an empty Conc% cell / no concentration after the CAS, never the
  exact value. Republish to refresh.
