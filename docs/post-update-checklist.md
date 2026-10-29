# Post-update checklist — SDS content audit release (2026-10)

Runbook for deploying the SDS content audit release (all 42 items, batches
A–D, migrations 052–055) to `/var/www/sds-system`. Work top to bottom:
pre-flight (1), deploy (2), the one-time steps in the order given (3), then
the functional checks (4, A–F). Every check is a concrete observable; tick it
only when you have seen it. Finish section 3 before the next bulk publish:
most one-time steps bump raw materials, and one bulk publish should pick all
of them up together.

**SQL helper.** `www-data` has no MySQL socket auth, so ad-hoc SQL goes
through PHP and `config.php` (pattern in `docs/operations.md`). Define this
once per shell session; `sdsq '<SQL>'` then prints one tab-separated line per
row. Inside the single quotes use double quotes for SQL strings and
backticks for `key` / `value`:

```bash
sdsq() { sudo -u www-data php -r '
$c = require "/var/www/sds-system/config/config.php"; $d = $c["db"];
$pdo = new PDO("mysql:host=".$d["host"].";dbname=".$d["name"].";charset=utf8mb4", $d["user"], $d["password"]);
foreach ($pdo->query($argv[1], PDO::FETCH_ASSOC) as $r) { echo implode("\t", $r), "\n"; }
' -- "$1"; }
```

## 0. Fast path back to publishing

The shortest route from "update installed" to "SDSs publishing again". Each
step is expanded in sections 1–4 below; the section number is in brackets.

1. **Install pigz** so the pre-update backup is not single-threaded: `sudo apt install pigz` [1].
2. **Pause automatic bulk publish** (Settings → CMS Sync Schedule) and record the solubility pre-flight count [1].
3. **Deploy**: fetch, reset, `update.sh`, answer **Y** to the backup; confirm migrations 052–055 applied, plus 056–059 for the findings batch [2, 3.8c].
4. **Emergency phone numbers** — publishing is blocked without them: Settings → Company, and every private-label manufacturer (Manufacturers → Edit) [4.A].
5. **Solubility re-check** — set the genuinely water-soluble raws (water, glycols, lower alcohols, amines) back to "Soluble in water" [3.1].
6. **Flash points** — the product flash point is now the wt%-weighted average of the raw materials that carry one; raws left blank are left out. Enter a high flash point (e.g. 100 °C) on water and the other non-flammable liquid raws so they count, and a flash point on every solvent. A product with no flash point data prints "Not determined" in Section 9 and is treated as not flammable (Section 14 "Not regulated" unless another class applies; no publish block) [4.C].
7. **Product families** — review the seeded families and UV/LED flags, fill the per-language default text, add the rules (e.g. prefix `VEC47` → UV), Recompute and Apply [3.5].
8. **Element flags** for Section 10: CAS Determinations → CAS Descriptions → "Seed element flags (preview)", review the table, then Apply (CLI fallback: seed script dry run, then `--confirm`) [3.2]. Then the same for **RCRA metal flags** (Section 13): "Seed RCRA metal flags (preview)" → review → Apply [3.2b].
9. **TSCA inventory** — download the EPA CSV or ZIP and upload it on Regulatory Data → TSCA Inventory → "Import EPA TSCA inventory" (preview, then Apply); without it every sheet prints "TSCA status has not been verified" (a warning, not a block) [3.3].
10. **Override cleanup**: pass 1 (`cleanup-default-overrides.php`) and pass 2 (`cleanup-legacy-overrides.php`), each a dry run then `--apply`; review pass 2's kept Section 9/14/15 rows with the owner [3.4].
11. **Private label** — retire or freeze unwanted backfilled items; run `check-pl-duplicates.php` [3.6, 3.7].
12. **Findings batch (migrations 056–059)** — migrations applied, override cleanup pass 2 done (step 10), carcinogen correction check, non-hazardous flag count, per-language legal disclaimers, full-republish bump [3.8c].
12b. **Batch F (migrations 060–062)** — acrylate flag seed, family SDS profiles, STOT target organs, combustible-dust determinations on powder raws, Section 9 flash point edit review, then Bump ALL [3.8d, 3.2c].
12c. **Resale import (migrations 063–064)** — deploy with `update.sh` (064 adds a column); the first CMS sync creates a raw material for every Resale item; mark the non-chemicals **No SDS required** (Raw Materials list, admins only until a group is given the permission) instead of deleting them, then enter composition for the resale items you sell [3.8e].
12d. **Tables and label layout (code only, 2026-10-19)** — Section 11 carcinogenicity and component toxicological data, and Section 15 SARA 313, print as tables; long labels keep their value on the same line; Section 2 prints "Signal Word: Danger" in plain black text like every other field (2026-10-20); table cells no longer drop their last wrapped line or overprint across a page break. Press **Bump ALL** so every sheet republishes, then spot-check [3.8f].
12e. **Housekeeping and scheduled backups (code only, 2026-10-19)** — neither cron script had ever run (both used the database before starting the app). Check for SDS Archive ZIPs you still need, run housekeeping once with `--dry-run`, confirm the cron lines, and check the log the next morning [3.8g].
12f. **Bulk publish language order (migration 065, 2026-10-19)** — deploy with `update.sh` (065 adds a column). Every bulk publish now does English first, then Spanish, German, French; the Bulk Publish Queue shows "English done" with the time; an interrupted run's missing languages are completed by the next run at the same version. Check the first run [3.8h].
12g. **TSCA overrides (migration 066, 2026-10-21)** — deploy with `update.sh`. Section 15 now checks only the CAS numbers that are provided: a component without a CAS (a trade secret, a raw material with no constituents, constituents adding up to less than 100%) is assumed listed, so CAS Determinations > TSCA Review lists only provided CAS numbers not on the inventory. A raw-material override (Listed / Exempt, with the supplier's certification as the note) is set on the raw material's edit page (card "TSCA inventory status") and covers that raw material's CAS numbers. The rule changes the TSCA sentence of sheets already published without any data change, so nothing queues them: after deploying, press Settings → Maintenance → **Bump ALL unblocked SDSs** once (one bump also covers 12d if its run has not happened yet), then run Bulk SDS Publish. A CAS is now compared the same way everywhere, whatever way it was typed (hidden characters, typographic dashes, internal spaces, zero padding), so a CAS override reaches every raw material carrying it and queues its products for republish [3.8i].
12h. **STOT Target Organs tab (code only, 2026-10-21)** — CAS Determinations > STOT Target Organs: each substance shows its organs as text; **Edit** opens tick boxes for every organ (single and repeated exposure) and the route. Unticking every organ now clears it (it used to be refused while a route was set, and the old list needed Ctrl-click). Save one substance, or edit several and use **Save all changes** in the bar at the bottom. "Only substances still without organs" narrows the list. Spot-check: untick an organ, save, and the summary shows the change.
12i. **Dashboard review counts (code only, 2026-10-22)** — the Published SDSs tile is gone. Users who can open CAS Determinations see three tiles instead, each linking to its tab: CAS determinations pending (the Needs Determination count), TSCA review items (on sheets Bulk SDS Publish is cleared to publish) and STOT target organs needed (classified H370-H373, no organs). The counts are kept for 10 minutes in `storage/cache/dashboard-review-counts.json` (every visit to CAS Determinations refreshes them; **Refresh** under the tiles recomputes). Spot-check: the three numbers match the tab badges on CAS Determinations, and a user without CAS Determinations access sees no tiles.
12j. **STOT "Not specified" (migration 067, 2026-10-22)** — run `update.sh` so 067 adds `cas_master.stot_se_not_specified` / `stot_re_not_specified`; until it runs the boxes are hidden and the tab works as before. On CAS Determinations > STOT Target Organs, **Edit** now has a **Not specified on the vendor SDS** box for each exposure type. Ticking it unticks and locks that exposure type's organs and route. The sheet prints the generic statement (as with no organs). The substance stops counting in "Only substances still without organs", the tab badge and the dashboard tile. Where another CAS behind the same code has organs, the generic statement prints with no preview warning. Ticking an organ later clears the mark. A mark alone changes no printed text, so it is saved and audited but bumps nothing and queues no SDS Updates. Nothing to press after deploying. Spot-check: tick Not specified on one substance, save, and the summary reads "Not specified on the vendor SDS (generic statement)" and the STOT badge drops by one.
12k. **Language in SDS file names (code only, 2026-10-22)** — every SDS PDF a user gets is named with the language after the version: nothing for English, `_ESP` Spanish, `_FRA` French, `_DEU` German (e.g. `SDS_UVNG009_v3.pdf`, `SDS_UVNG009_v3_ESP.pdf`). That covers downloads from the SDS page, Lookup (which used to leave the language out) and private label, previews, the Bulk SDS Export and SDS Book export ZIP entries, the shipped-SDS report ZIP, auto-send email attachments and SDS Archive entries. English downloads no longer end `_en`. Files stored before the deploy keep `_es` on disk; nothing needs renaming. ZIP names keep `EN` for English (`SDS_Export_EN_…`, `SDS_Export_ESP_…`). Vendor-supplied SDS uploads keep their own names. Spot-check: download a Spanish sheet from Lookup and the file is `SDS_{code}_v{n}_ESP.pdf`.
12l. **Incomplete Raw Materials: supplier columns (code only, 2026-10-26)** — on CMS Import → Incomplete Raw Materials every tab (Needs Details, Single-RM Unblock, Trade Secret, In Inventory) shows **Supplier** and **Supplier Code**, the supplier's own code for the item (CMS "Their Code" for the preferred vendor, also on Raw Materials → Edit as Supplier Product Code). Needs Details gained the Supplier column. On Trade Secret the code moved out from under the product name into its own column. Click **Supplier** to sort a tab A to Z, again for Z to A, and a third time to return to the tab's own order (impact, leverage, trade-secret % or quantity). Items from one supplier keep that order, and items with no supplier go last. The sort is on the page only and resets on reload. Spot-check: on Needs Details click Supplier and the rows group by supplier.
12m. **CAS determination signal word stays on Auto (engine v1.9, code only, 2026-10-28) — then Bump ALL** — saving a determination with **Signal Word Override** on Auto used to store the word the selections gave where the form reads the override. Reopened, the list showed Danger or Warning as an override, and the next save froze that word even when the hazards changed. Now:
   - The save records the manual choice separately (`signal_word_override` in the determination JSON; `signal_word` is still saved).
   - Every determination saved before this has no recorded choice and is Auto (the owner confirmed none was a manual override), so the form opens on Auto.
   - The hazard engine (v1.9-cpd-auto-signal-word) no longer uses a stored word for a determination on Auto. On every SDS it works the word out from the determination's selected hazard classes that trigger at the component's concentration, so a word can never stick when the hazards change. The same goes for raw-material trade-secret hazards, which have no override. A manual override, if one is ever set, still applies. The word also covers every H-code that still prints: a code ticked by hand, or left from an earlier category, sets a floor (H350, H314, H318, H301, H300+H310 and H200 need Danger; H231, EUH066 and codes GHS data does not know set none). Very old determinations with free-text classes only (no ticked hazards) keep their stored word, because a free-text class cannot always be worked out. Opening one ticks the hazards whose class and category match exactly. Any other class is named in a warning, because saving without ticking it clears it (as before) and that CAS would then print no hazards.
   - On the form, an older hand-added P281 now ticks (and saves as) P280 instead of being dropped, and a new determination sent back with an error no longer fails to open when codes were ticked.
   - The form used to re-tick every saved H- and P-code as a hand-added code, so a hazard's codes stayed after the hazard was deselected. Now only codes the selected hazards do not give are ticked, so changing a hazard changes its codes too. A hand-ticked H-code that GHS gives only to a class you have selected (another category, e.g. H350 next to Carcinogenicity Category 2) is named in a note above Additional H-Codes. It is probably left from an earlier category: untick it if it no longer applies.
   - Printed SDSs can change where a stuck word was wrong, or where the stored Danger came only from a class that does not trigger at that concentration (now Warning or none). Press **Bump ALL** once after deploying so bulk publish re-issues them. Saving a determination again unchanged does not mark products stale.
   - Spot-check: open a determination and the list says Auto. For a product with a CAS determination, the preview's signal word matches the hazard classes in Section 2.
12n. **Hand-ticked P280 on a CAS determination prints (engine v1.10, code only, 2026-10-28) — then Bump ALL** — since the flash-point change (Q3), a determination's Flammable Liquids P-codes were dropped unless another of its triggered hazards carried them, even on a determination with no Flammable Liquids hazard. That dropped P280 ticked by hand next to Acute Toxicity Oral 3 or Inhalation 4, and the P281 that 12m now saves as P280, as well as a hand-ticked P501 or P303+P361+P353. Now:
   - P280, P501 and P303+P361+P353, which health hazards carry too, are dropped only when the determination has a Flammable Liquids hazard (ignored, as the product flash point decides) and no other triggered hazard carries them, since they may have come from it. A stored H224-H227 with no Flammable Liquids hazard ticked (left after the hazard was deselected) counts as one. There an old P281 is treated as P280 too, as after a resave.
   - Without a Flammable Liquids hazard, these three also print when they come with a hazard of the same determination that is below its cut-off at that concentration, as that hazard's other P-codes already did (P-codes are not filtered by cut-off).
   - Very old determinations with free-text classes only: a Flammable Liquids class spelt in a way the class list does not recognise ("Flam. Liq. 2", "Flammable Liquids - Category 2") used to count as a hazard of its own. It is now ignored like the usual spelling: its P-codes are dropped as above. When no other class of the determination applies at that concentration, the determination now contributes nothing; before, the misspelt class counted as a hazard that applies, so the stored signal word and the P-codes printed on their own. As with the usual spelling, Section 3 then lists the ingredient for it only when the product itself is flammable, and the preview warns when the raw material that holds it has no flash point. With other classes, the stored signal word prints as before.
   - Flammability-only P-codes (P210, P233, P240-P243, P370+P378, P403+P235) are still dropped unless a triggered hazard carries them (e.g. Flammable Solids), as before. The product flash point brings its own when the product is flammable.
   - The same applies to raw-material trade-secret hazards. One press of **Bump ALL** covers 12m, 12n and 12o together.
   - Spot-check: a product with a determination that has Acute Toxicity Oral 3 and a hand-ticked P280 shows P280 in Section 2.
12o. **Signal word of very old free-text determinations with a Flammable Liquids class (engine v1.11, code only, 2026-10-28) — then Bump ALL** — this concerns only very old determinations with free-text classes (no ticked hazards; the text box was replaced by tick boxes in February 2026) that include a Flammable Liquids class written the usual way: exactly "Flammable Liquids", "Flammable Liquid", "Flam. Liq." (both dots) or "Flam Liq" (no dots), or the plural French, Spanish or German name ("Liquides inflammables"), optionally followed by a space and "Cat" or "Category" with a number ("Flammable Liquids Cat 2", "Flam Liq Category 3"), which may in turn be followed by a bracket ("Flammable Liquids Category 2 (H225)"). Any other English form (a dash or bracket straight after the name, a bare number, other dots: "Flam. Liq. 2", "Flammable Liquids - Category 2", "Flammable Liquids (Category 2)", "Flam. Liq") is one of the unusual ones of 12n and does not change. A translated name with its category in its own language or in brackets ("Liquides inflammables (Catégorie 2)") is not recognised as Flammable Liquids at all; it counts as a class of its own, as before. The signal word of these determinations is worked out from their other classes, because the stored word may be the Flammable Liquids class's, which is ignored (the product flash point decides). A class that cannot be worked out from the stored H-codes, because the class list does not recognise its name (CLP short names such as "Acute Tox. 3", "Carc. 1B", "STOT SE 1") or because none of its codes is stored, used to lose the stored word as soon as another class could be worked out. So "Flammable Liquids, Eye Irritation, Acute Tox. 3" stored as Danger printed Warning. Now:
   - Such a class keeps the stored word when its name shows it needs that word at the concentration, at its own cut-off. "Acute Tox. 3", "Carc. 1B", "Skin Corr. 1B", "STOT SE 1" and "Serious Eye Damage" need Danger; "Eye Irrit. 2", "STOT SE 3", "Skin Sensitization" and "Carcinogenicity" (which may be Category 2) need Warning; "Aquatic Chronic 2" and "Lactation" need none. "Asp. Tox. 1" needs Danger only from 10 %.
   - A short name such as "Carc. 1B" also counts from its own cut-off (0.1 % for Carc. 1B) up to 1 %, where it was missed before. This changes the signal word only, and only when another class of the same determination applies at that concentration. On its own below 1 % the determination still contributes nothing.
   - "Acute Toxicity" with no route keeps the stored Danger when one of its stored dermal or inhalation codes needs Danger: H311 or H331 next to an H302 used to give Warning.
   - The word only ever goes up to the stored word, never above it and never down. Every other result is as before, including a stored Danger that prints because no class can be worked out ("Flammable Liquids, Skin Sensitization" without H317 still prints Danger, which may be the Flammable Liquids class's). A name in which no hazard class can be recognised, such as "Toxic", a "Category 2" split off from the class before it by a comma, or "Serious Eye Damage (H319)", is handled as before.
   - Only the signal word changes. The H-codes and pictograms of a short name the class list does not recognise (H301 and GHS06 of "Acute Tox. 3", H350 and GHS08 of "Carc. 1B") still do not print, at any concentration, as before; that is a separate fix. So a sheet can show Danger next to only the other classes' codes (H319 and GHS07 for the example above).
   - Nothing else changes. Determinations with ticked hazards, free-text ones without a Flammable Liquids class, and ones with an unusual spelling print as before. P-codes do not change. Raw-material trade-secret hazards always have ticked hazards, so they are not affected.
   - To list the very old free-text determinations: `sdsq 'SELECT id, cas_number, JSON_UNQUOTE(JSON_EXTRACT(determination_json, "$.hazard_classes")) AS classes, JSON_UNQUOTE(JSON_EXTRACT(determination_json, "$.signal_word")) AS word FROM competent_person_determinations WHERE is_active = 1 AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(determination_json, "$.selected_hazards")), "") IN ("", "[]", "null") AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(determination_json, "$.hazard_classes")), "") <> ""'`. Only those with a Flammable Liquids class written the usual way (above) and a stored word of Danger or Warning can change. If there are none, no sheet changes.
   - One press of **Bump ALL** covers 12m, 12n and 12o together. If you already pressed it for 12m / 12n, press it once more.
   - Spot-check (only if the query lists one that can change): pick a product that holds that CAS above its cut-off, is not flammable itself (no flash point, or one above 93 °C) and has no other ingredient with a more severe word, and compare its preview signal word with the rules above. On a flammable product the word is at least that of its own flash-point category (Danger for Category 1 or 2), so a Danger kept by the determination cannot be seen there.
13. **Live-DB test suites** on the server [3.8].
14. **Spot-check three sheets** (solvent ink, water-based, UV with acrylates) in EN and ES against the Section checklist [4.B].
15. **Resume**: tick "Auto bulk publish" again and run one bulk publish; work through the failures it reports (each one is a publish gate from step 4 or 6) [3.9].

## 1. Pre-flight (before deploying)

- [ ] `pigz` installed for a multi-core pre-update backup: `sudo apt install pigz` (`update.sh` falls back to gzip and warns if it is missing; the admin Backups page uses it too).
- [ ] No bulk publish is running: `pgrep -af 'publish-worker\.php' | wc -l` prints `0` (a running worker keeps executing the old code).
- [ ] Settings → CMS Sync Schedule: "Auto bulk publish" unticked and saved, so the hourly CMS sync (HH:07) cannot start a bulk publish halfway through section 3. It is turned back on in step 3.9.
- [ ] Count recorded of the raw materials migration 054 will reset (#18), and their list saved for step 3.1:

  ```bash
  sdsq 'SELECT COUNT(*) FROM raw_materials WHERE solubility IN ("Soluble in water", "Partially soluble in water")'
  sdsq 'SELECT id, internal_code, supplier_product_name, solubility FROM raw_materials WHERE solubility IN ("Soluble in water", "Partially soluble in water") ORDER BY internal_code' > ~/solubility-before-054.tsv
  ```

  Count: ________

## 2. Deploy

```bash
sudo -u www-data git -C /var/www/sds-system fetch && \
sudo -u www-data git -C /var/www/sds-system reset --hard origin/main && \
sudo bash /var/www/sds-system/update.sh
```

`update.sh` asks for the installation directory (Enter =
`/var/www/sds-system`), asks for the MySQL root password only if the
`config.php` credentials fail, and offers a database backup: answer **Y**.
Migration 054 rewrites raw-material data, and the pre-update dump in
`storage/backups/` is the only way back. `update.sh` applies 052–055 itself
through the `mysql` client and records them in `schema_migrations`; do
**not** also run `php migrations/migrate.php`.

Opcache: `update.sh` restarts Apache (its step 11), which empties opcache for
the web app (mod_php). CLI scripts and cron jobs start a fresh PHP process
each run and need nothing. If a page still behaves like the old code, run
`sudo systemctl restart apache2`.

- [ ] The `update.sh` migration step shows "Applying …" for each of `052_sds_audit_batch_a`, `053_product_families`, `054_physical_props_transport` and `055_regulatory_lists_overrides` that was not already applied, and no "Failed to apply" line. The loop carries on past a failed migration, so read the output.
- [ ] `sdsq 'SELECT version, applied_at FROM schema_migrations WHERE version >= "052" ORDER BY version'` lists all four.
- [ ] `update.sh` reports Apache restarted, and its post-update verification block shows no errors.

## 3. One-time steps (in this order)

### 3.1 Solubility re-check (#18, migration 054)

By decision, migration 054 set every "Soluble in water" / "Partially soluble
in water" raw material to "Negligible solubility in water" and bumped it.
Materials that really are water-soluble must be set back by hand, otherwise
water-based products print the wrong Section 9 solubility.

- [ ] `` sdsq 'SELECT `value` FROM settings WHERE `key` = "sds.migration.054.solubility_reset_count"' `` equals the pre-flight count.
- [ ] `sdsq 'SELECT COUNT(*) FROM raw_materials WHERE solubility IN ("Soluble in water", "Partially soluble in water")'` prints `0`.
- [ ] Shortlist printed by name. It only matches names, so also walk `~/solubility-before-054.tsv`:

  ```bash
  sdsq 'SELECT id, internal_code, supplier_product_name FROM raw_materials WHERE solubility = "Negligible solubility in water" AND CONCAT(internal_code, " ", supplier_product_name) REGEXP "WATER|GLYCOL|GLYCERIN|ALCOHOL|ETHANOL|METHANOL|PROPANOL|AMINE|AMMONI" ORDER BY internal_code'
  ```

- [ ] Every material that genuinely is soluble, confirmed against the supplier SDS Section 9, set back to "Soluble in water" (Raw Materials → Edit → Solubility → Save). Typical cases are water itself, glycols and water-miscible glycol ethers, lower alcohols, and neutralising amines / ammonia. The save bumps the material, so its products republish with the corrected solubility.

### 3.2 Element flags for Section 10 (#19)

**Preferred: the preview page.** CAS Determinations (`/determinations`) →
CAS Descriptions tab → **Seed element flags (preview)**
(`/determinations/element-flags`). The page runs the seed as a dry run
and lists every CAS whose flags would change: current → proposed N / S /
Hal, the basis (`formula …` or `names: {…}` = the keyword stems that
fired) and the number of raw materials carrying the CAS. Use the filter
box to review the keyword-based rows for false positives. Nothing is
written until you click **Apply seed**; the confirm dialog repeats the
counts. Afterwards the page reloads and shows "Changed: 0".

- Tick **Do not queue SDS-update rows** if the SDS Updates page would be
  flooded: the raw materials are still bumped, so bulk publish still picks
  the products up.
- Manually set rows (`element_flags_source = manual`, saved with the
  *Flags* button) are skipped and counted; "preview with them included"
  shows what **Also overwrite manually set flags (`--force`)** would do.

**Fallback: the command line** (same code, same rows and counts):

```bash
sudo -u www-data php /var/www/sds-system/scripts/seed-cas-element-flags.php > ~/element-flags-dryrun.txt   # dry run
sudo -u www-data php /var/www/sds-system/scripts/seed-cas-element-flags.php --confirm                      # apply
```

  The script itself writes no file: the `> ~/element-flags-dryrun.txt` redirect is done by your login shell (not www-data), so the file lands in the home directory of the account you SSH'd in as. In a dry run "RMs bumped" / "SDSs queued" are always 0; only "Changed" is meaningful.

- [ ] The preview page (or `tail -12 ~/element-flags-dryrun.txt`, which shows the "=== Summary ===" block and "DRY-RUN: no DB writes") has been reviewed: rows whose basis is a name keyword rather than a molecular formula have been checked for false positives.
- [ ] The Apply (or `--confirm`) run reports the same "Changed" count, plus non-zero "raw materials bumped" / "SDSs queued" (unless *Do not queue* / `--no-queue` was used).
- [ ] Reloading the preview page (or a second dry run) reports "Changed: 0".
- [ ] False positives corrected with the "Flags" button on CAS Determinations → CAS Descriptions. Those rows become `manual`, and later seed runs skip them unless *Also overwrite manually set flags* / `--force` is given.

### 3.2b RCRA metal flags for Section 13 (#12, migration 057)

CAS Determinations → CAS Descriptions tab → **Seed RCRA metal flags (preview)** (`/determinations/tc-metals`). Same workflow as 3.2.

The dry run lists every CAS whose RCRA metals (As, Ba, Cd, Cr, Pb, Hg, Se, Ag) would change. For each one it shows the basis: `formula …`, or `names: {…}` for the name patterns that fired, including Colour Index names such as "Pigment Yellow 34".

A flagged metal compound gets the D004–D011 code and TCLP limit of the element row in Section 13. Examples are lead chromate, cadmium pigments, chromium oxide, barium sulfate and barium lakes.

Expect barium sulfate and barium-lake pigments to add a conditional D005 line to every product that contains them. Review the name-based rows for false positives, for example "silver" used for an aluminium pigment. Then click **Apply seed**.

```bash
sudo -u www-data php /var/www/sds-system/scripts/seed-cas-tc-metals.php > ~/tc-metals-dryrun.txt   # dry run
sudo -u www-data php /var/www/sds-system/scripts/seed-cas-tc-metals.php --confirm                  # apply
```

- [ ] Preview reviewed; name-based rows checked for false positives.
- [ ] Apply reports the same "Changed" count; reloading the preview reports "Changed: 0".
- [ ] Wrong or missing metals corrected in the **RCRA metals** box on CAS Descriptions (those rows become `manual`; later seed runs skip them unless forced).
- [ ] Spot check: a sheet with a lead/chromium/barium pigment lists it in Section 13 with its D codes; a sheet with no metals ends "…no component has been identified as a toxicity characteristic constituent or a listed hazardous waste."

### 3.2c Acrylate flags for UV sheets (A12a, migration 060)

CAS Determinations → CAS Descriptions tab → **Seed acrylate flags (preview)** (`/determinations/acrylate-flags`). Same workflow as 3.2.

The dry run lists every CAS whose acrylate flag would be written: CAS on the old 14-entry list, and CAS with any name that looks like a reactive acrylate or methacrylate ("…acrylate", "(meth)acrylic acid"; acrylic polymers and resins never count). Rows with "Sheet text: yes" change the Section 4 acrylate names on UV sheets and bump their raw materials. Rows with "no" only record what the sheets already detect, and bump nothing.

```bash
sudo -u www-data php /var/www/sds-system/scripts/seed-cas-acrylate-flags.php > ~/acrylate-flags-dryrun.txt   # dry run
sudo -u www-data php /var/www/sds-system/scripts/seed-cas-acrylate-flags.php --confirm                      # apply
```

- [ ] Preview reviewed. Rows marked "check name" (on the old list, but no name looks like an acrylate) have their CAS description corrected on CAS Descriptions. Check 2223-82-7, 28961-43-5, 52408-84-1 and 55818-57-0 in particular: the old hard-coded labels for these four were wrong, and sheets print the registry description.
- [ ] Apply reports the same "Changed" count; reloading the preview reports "Changed: 0".
- [ ] "Legacy CAS not in registry" is empty, or those CAS have been added to the registry (the old list detects them for one more release only).
- [ ] False positives unticked in the **Acrylate** column on CAS Descriptions. Those rows become `manual`, and later seed runs skip them unless forced.
- [ ] Spot check: a UV sheet containing HDDA prints "…containing acrylate monomers/oligomers (1,6-Hexanediol Diacrylate (HDDA)): remove uncured product…" in Section 4 Skin.

### 3.3 TSCA inventory import (#29)

Download the non-confidential TSCA Inventory zip from
<https://www.epa.gov/tsca-inventory/how-access-tsca-inventory>.

**Preferred: the upload page.** Regulatory Data → TSCA Inventory (`/tsca`) →
"Import EPA TSCA inventory": choose the ZIP (or the extracted CSV), keep the
version label it fills in from the filename, click Upload. The preview shows
the detected columns, rows parsed, unique CAS, insert/update/prune counts and
the CAS in use whose status would change; nothing is written until you click
**Apply import**. Discard throws the upload away. The checks below apply to
the preview and the success message exactly as they do to the CLI output.

**Fallback: the command line.** Extract the CSV (e.g. `TSCAINV_022025.csv`)
and copy it to `/tmp` on the server, then:

```bash
sudo -u www-data php /var/www/sds-system/scripts/import-tsca-inventory.php /tmp/TSCAINV_022025.csv             # dry run
sudo -u www-data php /var/www/sds-system/scripts/import-tsca-inventory.php /tmp/TSCAINV_022025.csv --confirm   # apply
```

- [ ] Dry run prints the detected columns ("Columns: cas=…, name=…") and "Parsed n data rows → m unique CAS". Exit code 2 means the header row was not recognised; the script prints the headers it found.
- [ ] `--confirm` summary: "Inserted" ≈ unique CAS. On a first import "CAS in use changed", "RMs bumped" and "SDSs queued" are non-zero, because every listed component changes its Section 15 sentence.
- [ ] TSCA Inventory page (`/tsca`) shows the row count and the latest import.
- [ ] CAS Determinations → TSCA Review lists the components still unresolved (confidential-inventory substances, polymers, crossover CAS). Each one has an override ("Listed — on TSCA (crossover / confidential CAS)", "Exempt from the TSCA inventory" or "Not listed (verified absent)") with the required note.

### 3.4 Override cleanup (#36, #1, Q12)

Pass 1 deletes rows equal to today's automatic text. Pass 2 deletes blank rows, the retired Section 15 OSHA Status / TSCA Status rows (no longer editable) and rows holding OLD automatic text the pre-#36 editor saved. Migration 059 must be applied first.

```bash
sudo -u www-data php /var/www/sds-system/scripts/cleanup-default-overrides.php            # pass 1 dry run
sudo -u www-data php /var/www/sds-system/scripts/cleanup-default-overrides.php --apply    # pass 1 delete
sudo -u www-data php /var/www/sds-system/scripts/cleanup-legacy-overrides.php             # pass 2 dry run
sudo -u www-data php /var/www/sds-system/scripts/cleanup-legacy-overrides.php --apply     # pass 2 delete + queue republish
```

- [ ] Pass 1 dry run prints "Equal to automatic (delete): n". Any "Generation failed (kept)" groups are products that do not generate today; they have been noted.
- [ ] Pass 1 `--apply` prints "Deleted n row(s)." with the same n, and a second dry run reports "Equal to automatic (delete): 0". "Printed SDS changes" is normally 0; any products it reports are queued on SDS Updates or listed for resale republish.
- [ ] Pass 2 dry run prints counts per reason (blank, retired_field, legacy_flash_point, legacy_dot, legacy_text, legacy_composite) and "Saved after cutoff (kept)". The kept Section 9, 14 and 15 rows it lists have been reviewed with the owner. If #36 was deployed later than 2026-10-08 23:31, re-run with `--before="YYYY-MM-DD HH:MM:SS"` (the deploy time).
- [ ] Pass 2 `--apply` prints "Deleted n row(s). Queued m finished good(s)". The listed resale raw materials have been republished from the SDS Creation Readiness Check, and a second dry run reports 0 to delete.
- [ ] No retired rows remain: `sdsq 'SELECT COUNT(*) FROM text_overrides WHERE sds_version_id IS NULL AND section_number = 15 AND field_key IN ("osha_status", "tsca_status")'` prints 0.
- [ ] The remaining rows have been reviewed. The same stock sentence repeated across many products is the tell:

  ```bash
  sdsq 'SELECT section_number, field_key, language, COUNT(*) AS n, LEFT(override_text, 80) FROM text_overrides WHERE sds_version_id IS NULL GROUP BY section_number, field_key, language, override_text HAVING n >= 5 ORDER BY n DESC'
  ```

  Clear them in the SDS editor (`/sds/{fg}/edit?lang=xx`, or `/sds/resale/{rm}/edit?lang=xx` for resale items) with "Reset to automatic".

### 3.5 Product families (#3)

- [ ] Settings → Product Families (`/admin/product-families`) lists the families migration 053 seeded: the old `sds.product_families` list plus every family name already on a finished good, with those finished goods linked as manual picks.
- [ ] UV/LED flags reviewed. 053 ticked every family whose name contains "UV" or "LED" as a word. The flag gates the UV acrylate rule pack and the Section 10 UV condition.
- [ ] Per-language Recommended Use / Restrictions defaults filled in for each family. A blank ES/FR/DE value prints that language's standard translated sentence, never the EN family text (#62), so fill all four languages when the family needs specific wording.
- [ ] Membership rules added (code prefix, e.g. `VEC47` for UV raw materials; description contains; specific codes). Rules on raw materials propagate: a product inherits the family with the largest wt% share of its expanded formula.
- [ ] "Recompute now" shows the preview with a count of items that would change family. After Apply, the reassigned products with a published SDS appear on SDS Updates.
- [ ] Legacy manual picks (#61): Settings → Product Families shows "N product(s) still carry the manual family pick migration 053 copied…". Press **Reset legacy manual picks to Auto**. Afterwards this prints `0`:

  ```bash
  sdsq 'SELECT COUNT(*) FROM finished_goods fg JOIN fg_legacy_family_picks lp ON lp.finished_good_id = fg.id AND lp.family_id = fg.family_id WHERE fg.family_source = "manual"'
  ```
- [ ] UV share and inactive families (#61): run "Recompute now" once after this update. UV/LED families are now pooled (20 % + 20 % UV beats 30 % Solvent), and manual picks of an inactive family are released. Apply. The reassigned products appear on SDS Updates.
- [ ] Legacy Default Product Use (#48): Settings no longer shows "Default Product Use", and migration 059 cleared the copied values. This prints `0`:

  ```bash
  sdsq 'SELECT COUNT(*) FROM settings WHERE `key` IN ("sds.default_recommended_use", "sds.default_restrictions_on_use")'
  ```
  Products whose Section 1 text changed are on SDS Updates with the reason "legacy default product use cleared (audit #48)". Republish them there.

### 3.5b Regulatory lists: categories, PBT, TSCA, Prop 65 legacy entries (#26, #27, #46, #47, migration 058)

Migration 058 adds the SARA 313 / HAP compound categories (metal compounds by element, glycol ethers by member list), flags the 40 CFR 372.28 PBT chemicals, turns legacy name-only Prop 65 entries into explicit entries, and bumps every raw material whose Section 15 can change. The next bulk publish picks those raw materials up.

- [ ] `sdsq 'SELECT list_code, COUNT(*) FROM regulatory_categories GROUP BY list_code'` prints `hap 12` and `sara313 17`.
- [ ] `sdsq 'SELECT list_code, member_type, COUNT(*) FROM regulatory_category_members GROUP BY list_code, member_type'` prints `hap include 20`, `sara313 exclude 5`, `sara313 include 21`.
- [ ] `sdsq 'SELECT COUNT(*) FROM sara313_list WHERE is_pbt = 1'` returns about 63 when the list was loaded from the shipped seed (it was 33 before).
- [ ] `sdsq 'SELECT COUNT(*) FROM raw_materials WHERE is_prop65 = 1 AND TRIM(COALESCE(prop65_chemical_name, "")) <> "" AND (prop65_data IS NULL OR TRIM(prop65_data) IN ("", "[]", "null"))'` returns `0`.
- [ ] A product containing zinc oxide (or any metal-compound pigment except copper phthalocyanine blue/green and barium sulfate) has a row "Zinc compounds | 1314-13-2 | <band> | 1%" in the SARA 313 table (Chemical Name | CAS Number | Concentration | De Minimis Threshold) in the preview (?html=1) and the PDF.
- [ ] A product with a PBT chemical below 0.1 % (e.g. lead impurity) shows "PBT chemical; no de minimis exemption" in its De Minimis Threshold cell. Section 12 names the same PBT on its persistence line.
- [ ] TRI PFAS (2023 TRI rule: chemicals of special concern, no de minimis): `sdsq 'SELECT COUNT(*) FROM sara313_list WHERE is_special_concern = 1 AND is_pbt = 0'` returns about 202 on the shipped seed. Raw-material constituents that are TRI PFAS (each prints in the Section 15 SARA table at any concentration, with "Chemical of special concern (40 CFR 372.28); no de minimis exemption" in its De Minimis Threshold cell):

  ```bash
  sdsq 'SELECT rm.internal_code, c.cas_number, c.chemical_name FROM raw_material_constituents c JOIN raw_materials rm ON rm.id = c.raw_material_id JOIN sara313_list s ON s.cas_number = c.cas_number WHERE s.is_special_concern = 1 AND s.is_pbt = 0 ORDER BY rm.internal_code'
  ```
- [ ] Copper phthalocyanines substituted only with H / Cl / Br (PB 15, 15:1, 15:2, 15:3, PG 7, PG 36) never print as SARA 313 "Copper compounds"; a sulfonated copper phthalocyanine dye still does.
- [ ] The HAP table total reads as a band (e.g. "1 - 5%"), never as a two-decimal number.
- [ ] SDS Creation Readiness for a product that uses a raw material with no constituents shows "Constituent data incomplete (warning only)" naming that raw material, and its preview Warnings box carries the "Composition warning".
- [ ] A newly published version's snapshot holds no exact percentages: `sdsq 'SELECT id, (snapshot_json LIKE "%concentration_pct%") + (snapshot_json LIKE "%hazard_result%") + (snapshot_json LIKE "%total_hap_pct%") FROM sds_versions ORDER BY id DESC LIMIT 5'` prints 0 in the second column for every row published after the update.
- [ ] Categories and exclusions are data, not code. To carve a CAS out of a metal category: `INSERT INTO regulatory_category_members (list_code, category_code, cas_number, member_type, chemical_name, source_ref) VALUES ("sara313", "N740", "<cas>", "exclude", "<name>", "manual")`. Confirm the seeded glycol-ether members and category de minimis values with regulatory staff.

### 3.6 Private-label backfill review

- [ ] `/private-label` → each manufacturer: items whose notes read "Backfilled … migration 051" reviewed. One-off combinations are retired, and items that must not follow the base SDS are frozen (auto-republish off). Every base publish cascades a new version to the active, auto-republish items.

### 3.7 Private-label duplicate check

```bash
sudo -u www-data php /var/www/sds-system/scripts/check-pl-duplicates.php; echo "exit=$?"
```

- [ ] `exit=0` (no duplicate (item_id, language, version) groups) and zero rows with `item_id IS NULL`. The listed code mismatches (informational) have been reviewed and either republished individually or re-pointed on the item.

### 3.7b Hazard data clean-up (batch E #19-#22, #39)

Migration 056 normalised bare PubChem categories, corrected two carcinogen rows and bumped every raw material whose ingredient hazard text changes (PubChem-sourced CAS, IARC Group 3 listings, acetaldehyde / Disperse Blue 1, P281 in a determination or trade-secret entry), so the 3.9 bulk publish picks them up.

- [ ] Canonical columns refreshed: `sudo -u www-data php /var/www/sds-system/scripts/backfill-canonical-class-names.php --force; echo "exit=$?"` → `exit=0`, or `exit=2` with the unmapped class names listed (the engine still reads those rows at run time; add the aliases later).
- [ ] `sdsq 'SELECT COUNT(*) FROM hazard_classifications WHERE category REGEXP "^[0-9]+[A-Ca-c]?$"'` prints `0`.
- [ ] `sdsq 'SELECT cas_number, classification FROM carcinogen_list WHERE agency = "IARC" AND cas_number IN ("75-07-0", "2475-45-8")'` prints `Group 2B` for both.
- [ ] `` sdsq 'SELECT `value` FROM settings WHERE `key` = "sds.migration.056.t2a_bump_count"' `` recorded: ________
- [ ] Finished-good hazard overrides that still carry the withdrawn P281 (printed as P280 from now on, but not bumped): `sdsq 'SELECT id, product_code FROM finished_goods WHERE hazard_override_json LIKE "%P281%"'` — republish each listed product by hand, or re-save its override (it is stored as P280).

### 3.8 Live-DB test suites (server)

```bash
for t in HazardEngineGoldenTest HazardClassAliasesTest CarbonBlackProp65Test SubFgProp65PropagationTest BulkPublishCompletionLiveTest TscaOverrideLiveTest StotTargetOrgansLiveTest ReviewCountsLiveTest; do
  sudo -u www-data php /var/www/sds-system/tests/Services/$t.php > /tmp/$t.log 2>&1 \
    && echo "PASS $t" || echo "FAIL $t (see /tmp/$t.log)"
done
```

- [ ] All eight print PASS. HazardEngineGoldenTest creates and removes its own fixtures; BulkPublishCompletionLiveTest, TscaOverrideLiveTest, StotTargetOrgansLiveTest and ReviewCountsLiveTest work inside one transaction that they always roll back (TscaOverrideLiveTest prints SKIP until migration 066 has run). The two Prop 65 suites exit 0 with a SKIP line when the data they need is absent.
- [ ] Optional, on the server's PHP build: `tests/smoke_pdf.php` and every DB-free suite (including `TranslationCompletenessTest`) pass too:
  `for f in /var/www/sds-system/tests/smoke_pdf.php /var/www/sds-system/tests/Services/*Test.php; do sudo -u www-data php "$f" > /dev/null 2>&1 && echo "PASS $(basename $f)" || echo "FAIL $(basename $f)"; done`

### 3.8b Batch E — publish paths, clock and gates (#28, #54, #55, #58, #59, #68, #69)

- [ ] One clock (#59). Publish one FG by hand, then `sdsq 'SELECT published_at, effective_date FROM sds_versions ORDER BY id DESC LIMIT 1'`: published_at is UTC (≈ `date -u`) and effective_date is today's LOCAL date. The SDS page shows the local time. Rows written before this release were stored in local time and now display a few hours early; nothing to fix.
- [ ] Run "Bump ALL unblocked SDSs" once (Settings → Maintenance). Batch E changes most sheets anyway, and this clears any staleness the old mixed clocks hid.
- [ ] Private-label unique index (#54): `sdsq 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "private_label_sds" AND INDEX_NAME = "uq_plsds_item_lang_ver"'` prints ≥ 1. If it prints 0, migration 059 found duplicate groups. Run 3.7, resolve them, then `sdsq 'ALTER TABLE private_label_sds ADD UNIQUE INDEX uq_plsds_item_lang_ver (item_id, language, version)'`.
- [ ] Missing-data threshold (#69): `` sdsq 'SELECT `value` FROM settings WHERE `key` = "sds.missing_threshold_pct"' `` is a number from 0.01 to 100. A stored blank or 0 is now read as the config default (1.0); re-save Settings to store a real value.
- [ ] Disclaimers (#69, migration 052): `` sdsq 'SELECT `key`, LEFT(`value`, 60) FROM settings WHERE `key` LIKE "sds.legal_disclaimer%"' ``. Since 052, ES/FR/DE sheets print the translation-file default unless `sds.legal_disclaimer.<lang>` is set. If the company's old single disclaimer was custom text, enter its ES/FR/DE translations in Settings. EN is a frozen copy of the old text (or of the default); blank it if it should follow future translation updates. A disclaimer or company change saved while a bulk job is running applies from the next job (workers cache settings).
- [ ] Manufacturer names (#55): `sdsq 'SELECT id FROM manufacturers WHERE TRIM(name) = ""'` prints nothing; give each listed id a name.
- [ ] Dead auto-send code gone (#69): `grep -c "function autoPublishReady\|function canAutoPublish\|function publishSds" /var/www/sds-system/src/Services/SDSAutoSendService.php` prints 0.

### 3.8c Findings batch (migrations 056–059)

Only for the release that fixes `docs/sds-data-sources-findings.md`. Several of its steps live in the sections above; this list ties them together so nothing is missed. Do it after 3.1–3.8b and before 3.9. The last step makes every product stale, so the single bulk publish in 3.9 picks everything up.

1. **Migrations.**
   - [ ] The `update.sh` migration step shows "Applying …" for each of the 056–059 files (`ls /var/www/sds-system/migrations/05[6-9]_*.sql`) that was not already applied, and no "Failed to apply" line.
   - [ ] `sdsq 'SELECT version, applied_at FROM schema_migrations WHERE version >= "056" ORDER BY version'` lists all four.
2. **Override cleanup, pass 2 (#36 follow-up, Q12).** Done in step 3.4 (`cleanup-legacy-overrides.php`, dry run then `--apply`). Pass 2 deletions change printed text, so it must run before the bump in item 6.
   - [ ] 3.4 pass 2 applied, and a second pass 2 dry run reports 0 to delete.
   - [ ] The 3.4 stock-sentence query (`... HAVING n >= 5`) no longer lists pre-update default wording.
3. **Carcinogen list correction (#21, #22).** In addition to the 3.7b IARC check:

   ```bash
   sdsq 'SELECT cas_number, agency, classification FROM carcinogen_list WHERE cas_number IN ("75-07-0", "2475-45-8") ORDER BY cas_number, agency'
   ```

   - [ ] Acetaldehyde (75-07-0) and Disperse Blue 1 (2475-45-8) show IARC "Group 2B" (and NTP "RAHC"); neither has an IARC "Group 1" row. If Group 1 still shows, the live table was loaded from the old seed and was not corrected. Report it before publishing.
   - [ ] A product with an IARC Group 3 ingredient (e.g. isopropanol 67-63-0 or methanol 67-56-1) prints no carcinogen line for it in Section 11.
4. **Non-hazardous constituent flag (removed, Q5).** The "Non-hazardous" checkbox is gone from the raw-material form and `raw_material_constituents.is_non_hazardous` is no longer read: Section 3 lists a constituent only by the hazard-class / OEL rules. The column stays in the database, unused.
   - [ ] Count of rows still carrying the old flag recorded (query in 4.E, "Raw material form: the constituent table has no Non-Haz column").
   - [ ] One product that used a flagged constituent spot-checked: Section 3 lists it only if it is hazardous or has an OEL.
5. **Legal disclaimer per language (migration 052, #34).** 052 copied the old single disclaimer, or the stock English text, into `sds.legal_disclaimer.en` only. ES / FR / DE were deliberately not seeded and print the translation-file disclaimer. A custom English disclaimer is therefore NOT what the other three languages say, and the seeded EN row no longer follows later changes to the stock EN text. The settings check is in 3.8b ("Disclaimers").
   - [ ] If `sds.legal_disclaimer.en` is custom text, Settings → legal disclaimer ES / FR / DE are filled with its translation (blank = the stock translated text).
   - [ ] Private-label manufacturers with their own disclaimer (Manufacturers → Edit) checked the same way.
6. **Expect a full republish.** This release changes derived text on nearly every sheet (weighted flash point and the Sections 2/5/7/10/13/14 that follow it, the Section 9 Appendix D lines, the Section 11 and 14 additions, the override cleanup) without editing every raw material. Bulk publish only picks up products whose raw materials changed since their last publish (`raw_materials.updated_at`).

   ```bash
   sdsq 'SELECT COUNT(*) AS total, SUM(updated_at >= "<deploy start, UTC, YYYY-MM-DD HH:MM:SS>") AS bumped FROM raw_materials'
   ```

   - [ ] `bumped` equals `total`. If it does not (the 3.8b "Bump ALL unblocked SDSs" was skipped, or ran before pass 2), press Settings → Maintenance → **Bump ALL unblocked SDSs** once now, after items 2–5 (it runs `UPDATE raw_materials SET updated_at = UTC_TIMESTAMP()`).
   - [ ] Bulk SDS Publish → "Total PDFs to Generate" ≈ (eligible products + aliases + private-label documents) × 4. Plan for a long 3.9 job; products stopped by a publish gate count as failed (section C).
7. **Gates that are new in this batch** — find the products they will stop before the 3.9 run.
   - [ ] Partial Section 14 overrides (#7): a UN-number or hazard-class override now needs UN number, shipping name, class and packing group in that language, otherwise the sheet is `override_incomplete` and publishing is blocked. This lists them (complete each in the SDS editor, or "Reset to automatic"):

     ```bash
     sdsq 'SELECT finished_goods.product_code, text_overrides.raw_material_id, text_overrides.language, GROUP_CONCAT(text_overrides.field_key ORDER BY text_overrides.field_key) FROM text_overrides LEFT JOIN finished_goods ON finished_goods.id = text_overrides.finished_good_id WHERE text_overrides.sds_version_id IS NULL AND text_overrides.section_number = 14 AND text_overrides.field_key IN ("un_number", "proper_shipping_name", "hazard_class", "packing_group") AND TRIM(text_overrides.override_text) <> "" GROUP BY text_overrides.finished_good_id, text_overrides.raw_material_id, text_overrides.language, finished_goods.product_code HAVING SUM(text_overrides.field_key IN ("un_number", "hazard_class")) > 0 AND COUNT(DISTINCT text_overrides.field_key) < 4'
     ```
   - [ ] Trade-secret Prop 65 conflicts (Q4): SDS Creation Readiness shows no red "trade-secret constituent is on the California Prop 65 list" alert. Each one names the raw material; correct its constituent data with the vendor (policy: vendor trade secrets are never Prop 65 chemicals). Those products cannot publish until it is fixed.
   - [ ] Transport "Not determined" (Q2/Q3): only the 3 + 8 + 6.1 precedence case and unsupported classes (gases, aerosols, flammable solids, pyrophoric / self-heating / water-reactive, oxidizers, organic peroxides, explosives) block now. A product that used to block for "no flash point" publishes as "Not regulated" unless another class applies.

### 3.8d Batch F — fixed-text review (owner approval 2026-10-12, migrations 060–062)

Items A1–A12 of the fixed-text review. Do this after 3.8c and before 3.9. Most of this batch is wording that lives in code (Sections 2, 4, 5, 6, 8, 9, 10, 13, 14, 15, 16), and code changes do not bump `raw_materials.updated_at`, so item 8 is required.

1. **Migrations.**
   - [ ] `update.sh` shows "Applying …" for `060_fixed_text_track2`, `061_sds_text_batch_t3` and `062_sds_fixed_text_t4`, with no "Failed to apply" line. 062 prints `powder_solid_raws_not_assessed` and a count. Count: ________
   - [ ] `sdsq 'SELECT version FROM schema_migrations WHERE version >= "060" ORDER BY version'` lists all three.
   - [ ] `` sdsq 'SELECT `value` FROM settings WHERE `key` = "sds.water_based_threshold_pct"' `` prints `20` (Settings → Water-Based Threshold (%); 1–100).
   - All three are DDL plus one settings seed. Nothing is bumped and sheets print as before until the steps below.
2. **Acrylate flags (A12a).** Run 3.2c: preview, then Apply (or the CLI dry run, then `--confirm`).
   - [ ] Done, and the four CAS with wrong old labels (2223-82-7, 28961-43-5, 52408-84-1, 55818-57-0) have correct registry descriptions.
   - [ ] 42978-66-5: the registry description is checked by hand. The fresh-install seed CSV may label it DPGDA, and because the name still looks like an acrylate, the preview will not flag it.
3. **Product-family SDS profiles (A11).** Product Families → Edit → **SDS profile** for each active family: storage temperature min/max (°C, both or neither), Water-based (auto / yes / no), Default physical state (e.g. offset and UV paste inks = Paste), typical pH min/max (water-based families only).
   - [ ] Every active family reviewed: `sdsq 'SELECT name, storage_temp_min_c, storage_temp_max_c, water_based, default_physical_state, ph_min, ph_max FROM product_families WHERE is_active = 1 ORDER BY name'`.
   - [ ] Each save that changes the profile reports raw materials bumped / SDSs queued (same as a default-text edit).
   - [ ] Water-based families: leave "auto" unless the water rule (water CAS 7732-18-5 ≥ the threshold % of the formula) gets a family wrong. Only the "Protect from freezing" sentences (Section 7, Section 10) depend on it.
   - [ ] Preview one member per family: a family default state that differs from the largest raw shows the preview note "Physical state: this product has no Physical State of its own, so the sheet uses its product family default …". Set Physical State on the product where the family default is wrong for it.
4. **STOT target organs (A12b).** CAS Determinations → **STOT Target Organs** tab lists every CAS behind H370–H373. Fill organs and route from the vendor SDS or the EU CLP Annex VI entry (e.g. crystalline silica 14808-60-7: lungs, inhalation). When the vendor SDS names no organs and none can be found, tick **Not specified** for that exposure type (migration 067): the generic statement prints and the substance no longer counts as needing organs.
   - [ ] Every listed CAS reviewed. A blank row keeps today's generic statement. When only some of the CAS behind a product's code have organs, the sheet stays generic and the preview shows a warning.
   - [ ] Spot check: a product with H372 from that CAS prints "Causes damage to organs (lungs) through prolonged or repeated exposure by inhalation." in Section 2, and Section 11 names the organs.
5. **Combustible dust (A12c).** Raw Materials → Edit → **Combustible Dust** (Not assessed / Yes / No) from the vendor SDS Section 2, for every Powder/Solid raw:
   `sdsq 'SELECT internal_code, supplier_product_name, physical_state FROM raw_materials WHERE combustible_dust = "not_assessed" AND LOWER(TRIM(COALESCE(physical_state, ""))) IN ("powder", "solid") ORDER BY internal_code'`
   - [ ] List worked through. Yes bumps the raw material. Not assessed ↔ No does not.
   - [ ] A Powder/Solid product containing a Yes raw prints the "Combustible Dust: May form combustible dust concentrations in air." line in Section 2 (signal word at least Warning), plus the dust fragments in Sections 5 and 7. Products with unassessed powder raws only get a preview / SDS Creation Readiness warning; publishing is never blocked.
6. **Section 9 flash point edits (A10).** The automatic flash point now prints rounded with "(calculated)". A kept Section 9 Flash Point edit is treated as a measured value and prints exactly as typed, without the marker.
   `sdsq 'SELECT COALESCE(finished_goods.product_code, CONCAT("RM ", text_overrides.raw_material_id)), text_overrides.language, text_overrides.override_text FROM text_overrides LEFT JOIN finished_goods ON finished_goods.id = text_overrides.finished_good_id WHERE text_overrides.sds_version_id IS NULL AND text_overrides.section_number = 9 AND text_overrides.field_key = "flash_point" AND TRIM(text_overrides.override_text) <> ""'`
   - [ ] Each row is a genuinely measured value. Otherwise "Reset to automatic" in the SDS editor.
7. **Translation review (not a release blocker).** The new ES / FR / DE sentences are house translations unless they come from the GHS phrase source. Send them for native-speaker review: Section 5 combustion products, Section 6 / 2 French H410–H412 ("… entraîne des effets néfastes à long terme"), Section 8 skin notation and no-limits ventilation, Section 13 packaging and cure residue, the Section 14 special-precaution fragments, the Section 15 Prop 65 clauses, the UV polymerization sentences (Sections 2 and 10), the family-profile sentences (Sections 7, 9 and 10), STOT organ and route words, and the combustible-dust sentences.
8. **Bump ALL.** After items 2–7, press Settings → Maintenance → **Bump ALL unblocked SDSs** once, so the bulk publish in 3.9 regenerates every sheet with the Batch F wording.
   - [ ] Pressed after items 2–7. The 3.8c item 6 query shows `bumped` = `total` for a timestamp taken just before pressing it.
9. **Live-DB suites.** Re-run 3.8. `HazardEngineGoldenTest` may report the two new result keys (`stot_details`, `stot_incomplete`). If that is the only difference, refresh its expected output; anything else is a regression.
10. **Spot checks (EN + one other language, PDF and `?html=1` preview).**
    - [ ] UV ink: Section 2 Other hazards "Uncured product may polymerize …"; the Section 2 and Section 8 glove and eyewear lines are the UV sentences alone (no nitrile-vs-butyl or glasses-then-goggles pair); Section 4 Notes "If exposed or concerned: Get medical advice/attention." when H340–H373 apply; Section 7 lists "peroxides and free-radical initiators" and, with a family storage range, "Store between X and Y °C (A–B °F)."; Section 10 "Hazardous polymerization may occur …" plus the inhibitor sentence only when acrylates are present; Section 13 "Small amounts of non-hazardous residue may be cured before disposal where permitted." (clean RCRA, complete composition only); Section 14 "UV/EB-curable product: protect from heat and direct sunlight …".
    - [ ] Water-based ink: Section 7 "Protect from freezing."; Section 10 conditions include freezing and "temperatures above Y °C"; Section 9 pH "8.0 – 9.5 (typical)" when the family has one.
    - [ ] Solvent ink: Section 5 "Combustion may produce carbon monoxide, carbon dioxide, [nitrogen oxides / sulfur oxides / hydrogen halides,] and other toxic gases." names the same elements as Section 10; Section 5 and Section 9 print the identical "NN °C (NNN °F) (calculated)" flash point; Section 14 adds the Class 3 (and marine pollutant) fragments.
    - [ ] Every sheet: Section 13 "Contaminated Packaging" line; Section 6 aquatic sentence word-for-word equal to the Section 2 H400–H413 statement; Section 8 skin-notation line names the components whose limit Notes carry "Skin" (trade secrets by description); a product with no exposure-limit rows starts Engineering Controls with a sentence that does not mention exposure limits; Section 15 Prop 65 reads "… which is known …" / "… which are known …" by count, cancer clause first; Section 16 defines SDS/HDS/FDS, ASTM, ISEA and PBT where they appear.

### 3.8e Resale items imported from CMS (2026-10-13, migrations 063–064)

The CMS sync now creates a raw material for every item in the CMS **Resale** GL group, not only the ones a recipe uses, so product family rules can place them (e.g. every `CTA…` code in its family) and they can get a resale SDS. Pack sizes of one resale product share one raw material (the pack code CMS aliases point at), and a recipe that uses another pack size of it maps to that raw material too. Expect about 206 new raw materials on the first sync: 229 Resale codes, of which about 23 are extra pack sizes (the sync log reports them as "resale pack size(s) share an existing raw material"). Non-chemicals among them are marked **No SDS required** (migration 064) rather than deleted. Do this after 3.8d and before 3.9.

**Deploy note.** This release needs `update.sh` (section 2), not the code-only deploy in `docs/operations.md`: migration 064 adds `raw_materials.sds_not_required`, and the raw-material edit page and the SDS Creation Readiness resale search read that column directly, so they fail with "Unknown column" until 064 is applied. The hourly CMS sync, bulk publish and auto-send check for the column first, so a sync that runs between the code update and the migration step still completes.

1. **Migrations.**
   - [ ] `update.sh` shows "Applying …" for `063_cms_rm_import_exclusions` and `064_rm_sds_not_required`, with no "Failed to apply" line.
   - [ ] `sdsq 'SELECT version FROM schema_migrations WHERE version >= "063" ORDER BY version'` lists both.
   - [ ] `sdsq 'SELECT COUNT(*) FROM cms_rm_import_exclusions'` prints `0`.
   - [ ] `sdsq 'SELECT COUNT(*) FROM raw_materials WHERE sds_not_required = 1'` prints `0`. 064 only adds the column: it flags nothing and bumps nothing.
2. **First sync.** CMS Import → Preview lists them under "New Raw Materials (from the CMS Raw Material and Resale GL groups)". Run the import, or wait for the hourly sync; its log line reads "RM (GL group): N new from CMS GL groups (… Resale)".
   - [ ] New raw materials: ________
   - [ ] Families: `sdsq 'SELECT rm.internal_code, COALESCE(pf.name, "(no family)"), COALESCE(rm.family_source, "-") FROM raw_materials rm LEFT JOIN product_families pf ON pf.id = rm.family_id WHERE rm.internal_code LIKE "CTA%" ORDER BY rm.internal_code'` lists every CTA code in its family.
3. **Flag the non-chemicals.** The Resale group also holds items that never get an SDS: 5562 / 5563 (pails and lids), AL30005K, PINK PLASTIC INK KNIFE, GFI 4945, the Pantone guides (GG…, GP…, PAGPS207), IR DETECTOR, IR LASER PEN, WEDGEL and MISC. Mark them **No SDS required** instead of deleting them. Every imported item starts as needing an SDS; the sync never sets this flag, so it is only ever set by hand (owner decision 2026-10-13), and a non-chemical CMS adds later arrives unflagged until someone marks it. List the candidates that are not flagged yet:

   ```bash
   sdsq 'SELECT id, internal_code, supplier_product_name FROM raw_materials WHERE sds_not_required = 0 AND (SUBSTRING_INDEX(internal_code, "-", 1) IN ("5562", "5563", "AL30005K", "PINK PLASTIC INK KNIFE", "GFI 4945", "PAGPS207", "IR DETECTOR", "IR LASER PEN", "WEDGEL", "MISC") OR internal_code LIKE "GG%" OR internal_code LIKE "GP%") ORDER BY internal_code'
   ```

   Read each description first: `GG%` and `GP%` are prefixes, and a real chemical could share one. Then flag them on **Raw Materials**: filter "Needs an SDS", search for the code, tick the rows and press **Mark No SDS required**. One at a time, use the **SDS requirement** card at the top of Raw Materials → Edit (tick, optional note such as "5-gallon pail", **Save SDS requirement**). The bulk-publish blocked list, CMS Import → Incomplete (Needs Details, In Inventory) and Stale RM SDS also have a **Not a chemical** button for items found later.

   Permission: only admins can flag until a group is given **Raw Materials: mark "No SDS required" (non-chemicals)** = Full Access (Admin → Groups → Edit). Other users see the card read-only and no buttons.

   A flagged item stays in the system and is ignored by: resale SDS, alias SDS (for every alias that resolves to it) and GHS labels (manual preview, edit and publish refuse with one message; the label picker leaves it out); bulk publish (not a candidate, so neither eligible nor listed as blocked); auto-send and the SDS send queue (shipments are skipped, not queued, and pending queue rows are dismissed with "Dismissed: no SDS required. Was: …"); the shipped-SDS ZIP (no PDF and no `_MISSING_ITEMS.csv` row); the HAP/VOC and Prop 65 reports and the Ross report's direct consumption; and the Needs Details, In Inventory, Trade Secret, Stale RM SDS, CAS Determinations and TSCA Review worklists. Lookup and Product Aliases show a "No SDS required" badge. Flagging is a metadata write: it bumps nothing, and resale sheets published earlier stay on file as history but are not sent. Formulas are unchanged: if a current formula uses a flagged item, its data still feeds that finished good's SDS (and the Ross formula expansion), it stays on the formula-related worklists (badged where the list shows badges), it still needs a normal data review before it stops blocking that finished good (flagging is not a review), and CMS Import → Incomplete lists it under "used in a current formula". Aliases, labels and shipments of a base follow its first raw material (lowest id); the edit card says so when you open a later pack sibling.

   Deleting (admin, Danger Zone) stays for codes that should not exist at all. A delete sticks: the sync does not recreate the code (or, for a resale product, any of its other pack sizes) unless a formula uses it, and creating it again by hand lifts that. A non-chemical deleted before this release can be brought back as flagged: create it by hand with **No SDS required** ticked on the create form.
   - [ ] The candidate query above prints nothing, or only codes you checked are real chemicals.
   - [ ] `sdsq 'SELECT internal_code, supplier_product_name FROM raw_materials WHERE sds_not_required = 1 ORDER BY internal_code'` lists every non-chemical. Count: ________
   - [ ] `sdsq 'SELECT item_code, reason FROM cms_rm_import_exclusions ORDER BY item_code'` lists only codes that should not exist.
   - [ ] CMS Import → Incomplete shows no "marked No SDS required are used in a current formula" warning, or each item it lists really belongs in that formula.
   - [ ] `sudo -u www-data php /var/www/sds-system/scripts/test-resale-pipeline.php` passes. Its "Flagged non-chemical" section checks that a flagged item is refused by the resale gate and is neither eligible nor blocked in bulk publish.
4. **Enter data for the resale items you sell.** A new resale raw material is empty and unreviewed. Until it is reviewed AND has its composition (or a first resale SDS published by hand), the automatic paths refuse it: bulk publish counts it as blocked, the shipped-SDS ZIP lists its aliases in `_MISSING_ITEMS.csv` with the reason, and GHS Labels shows an error instead of printing a label with no hazards. Enter the constituents from the vendor SDS (Raw Materials → Edit), then publish from SDS Creation Readiness Check (`/sds-review?rm_id=…`). A reviewed item that really has no hazardous ingredients can be published there by hand once; after that, bulk publish keeps it current. Items flagged No SDS required in step 3 are left out of all of this.
   - [ ] Resale items shipped in the last year have data and a published sheet (items flagged No SDS required excepted).
5. **Existing resale raw materials.** The same rule now applies to resale raw materials that were already in the system. These reviewed-but-empty ones have never been published and are now blocked (flagged items are left out):
   `sdsq 'SELECT rm.internal_code FROM raw_materials rm WHERE rm.sds_not_required = 0 AND (rm.hazardous_no_cas IS NULL OR rm.hazardous_no_cas = 0) AND NOT EXISTS (SELECT 1 FROM raw_material_constituents c WHERE c.raw_material_id = rm.id) AND NOT EXISTS (SELECT 1 FROM sds_versions sv WHERE sv.raw_material_id = rm.id AND sv.alias_id IS NULL AND sv.finished_good_id IS NULL AND sv.status = "published" AND sv.is_deleted = 0) AND EXISTS (SELECT 1 FROM aliases a WHERE a.internal_code_base = SUBSTRING_INDEX(rm.internal_code, "-", 1)) ORDER BY rm.internal_code'`
   - [ ] Each one has constituents entered, or was published by hand as non-hazardous.

   Older bulk runs only checked "reviewed", so some empty resale raw materials may already carry an automatic "Not a hazardous substance or mixture" sheet. Only sheets published by hand now count, so these are blocked as well (flagged items are left out: their old sheets stay as history and are never sent):
   `sdsq 'SELECT rm.internal_code FROM raw_materials rm WHERE rm.sds_not_required = 0 AND (rm.hazardous_no_cas IS NULL OR rm.hazardous_no_cas = 0) AND NOT EXISTS (SELECT 1 FROM raw_material_constituents c WHERE c.raw_material_id = rm.id) AND EXISTS (SELECT 1 FROM sds_versions sv WHERE sv.raw_material_id = rm.id AND sv.alias_id IS NULL AND sv.finished_good_id IS NULL AND sv.status = "published" AND sv.is_deleted = 0) AND NOT EXISTS (SELECT 1 FROM sds_versions sv WHERE sv.raw_material_id = rm.id AND sv.alias_id IS NULL AND sv.finished_good_id IS NULL AND sv.status = "published" AND sv.is_deleted = 0 AND COALESCE(sv.change_summary, "") <> "Bulk publish (resale)") ORDER BY rm.internal_code'`
   - [ ] Each one has constituents entered, or was confirmed non-hazardous by publishing it by hand once. Its existing sheets stay published until then; retire wrong ones on Admin → SDS Versions, which now lists resale and resale-alias sheets.

### 3.8f Tables, label layout and the cell-height fix (2026-10-19, code only)

No migration. Code changes do not mark sheets out of date, so this needs a full republish.

1. **Bump ALL.** Settings → Maintenance → **Bump ALL unblocked SDSs**, once. Run the bulk publish early in the day so it ends before the 06:00–07:00 UTC Ubuntu update window (an Apache restart there kills a run started from the web page).
2. **Spot-check, PDF and `?html=1`, EN and one other language:**
   - [ ] Section 2: "Signal Word: Danger" (or Warning) in the same font, size and black as the other fields (2026-10-20: no longer large, red or orange).
   - [ ] Section 9: long labels ("Upper/Lower Flammability or Explosive Limits", "Initial Boiling Point and Boiling Range") keep the value on the same line; a long value wraps under itself, never back at the left margin.
   - [ ] Section 11: Carcinogenicity table (IARC / NTP / OSHA columns) for a product with a listed carcinogen; Component Toxicological Data table with every exposure limit line; "*" footnote for titanium dioxide / carbon black (inhalable dust); an operator Carcinogenicity edit still prints as text.
   - [ ] Section 15: SARA 313 table with the De Minimis Threshold column.
   - [ ] Sections 3, 8 and 12: a long chemical name prints in full (before this release the last wrapped line could be missing), and a table that crosses a page repeats its header with no overprinted row.

### 3.8g Housekeeping and scheduled backups (2026-10-19, code only)

`cron/housekeeping.php` and `cron/scheduled_backup.php` used the database without starting the app, so every run stopped with "Database has not been initialised". Housekeeping has never cleaned anything, and no scheduled backup has ever been made. From this release both work. What housekeeping now deletes each night:

- Routine audit rows older than the retention: bulk publish rows, SDS and private-label downloads, logins and logouts. The retention is the longer of "Audit Log Retention (days)" on Settings → Maintenance and `cron.log_retention_days` in `config/config.php` (365), so the page can only make it longer; the purge is skipped below 90 days. Review records (a raw material counts as reviewed only through its audit rows), determinations, hazard and regulatory-list edits, permissions and settings history are never purged. Nothing is old enough until about February 2027.
- Dataset refresh log rows beyond the newest 100.
- Files in `storage/temp` older than a day (e.g. `classify-diff` reports: copy any you want to keep). If `paths.temp` in `config/config.php` points anywhere else, the sweep is skipped and the log says so.
- Bulk SDS Export files older than 2 hours. Only the export's own files: SDS Archive ZIPs (`sds-archive-*.zip`) stay until deleted on Admin → SDS Archive, and a running bulk publish's files are never touched. Half-written ZIPs left by a killed export or archive build also go. Until this release, starting a bulk export deleted every file in `storage/exports` older than 2 hours, archive ZIPs included.
- Files left by killed bulk publish runs (such as job 2235) after 3 days; empty worker logs after 3 days, all worker logs after 30. Never while a bulk publish is running.

1. - [ ] **SDS Archive ZIPs** still on the server (any made more than 2 hours before a bulk export was started have already gone):
   `ls -la /var/www/sds-system/storage/exports/sds-archive-*.zip`
2. - [ ] **Dry run** — lists what each step would delete, deletes nothing; it should end with "Housekeeping complete.":
   `cd /var/www/sds-system && sudo -u www-data php cron/housekeeping.php --dry-run`
3. - [ ] **Cron line** — `sudo crontab -u www-data -l | grep housekeeping`. If nothing prints, the next `sudo bash update.sh` adds it (step 11c), or add it now (one `sudo`, so the existing lines are kept):
   `sudo sh -c '( crontab -u www-data -l 2>/dev/null; echo "0 4 * * * cd /var/www/sds-system && /usr/bin/php cron/housekeeping.php >> storage/logs/cron-housekeeping.log 2>&1" ) | crontab -u www-data -'`
   Then `sudo crontab -u www-data -l | grep -c 'cms-sync\|housekeeping'` should print 2.
4. - [ ] **Next morning** — `sudo tail -n 20 /var/www/sds-system/storage/logs/cron-housekeeping.log` ends with "Housekeeping complete." ("finished with errors" names the failed step.)
5. - [ ] **Scheduled backups** — only if "Enable Scheduled Backups" is ticked on Admin → Backups. Check the hourly line exists: `sudo crontab -u www-data -l | grep scheduled_backup` (the page shows the line to add; neither the installers nor `update.sh` add it). The first scheduled backup is made in the hour after the scheduled time, so check free space for a full backup first: `df -h /tmp /var/www/sds-system`. A :30 schedule now runs once, not twice, schedules from 23:31 to 23:59 now run (just after midnight), the daylight-saving days make exactly one backup, and a second run never starts while a backup is still being made.

### 3.8h Bulk publish language order (2026-10-19, migration 065)

Deploy with `update.sh`: migration 065 adds `bulk_publish_jobs.language_progress`, where the runner records each language pass for the queue page (until it has run, the runner simply skips recording). No setting. Every bulk publish (Start button, the run after Bump ALL, the hourly CMS-sync run) publishes English first, then Spanish, German and French. Each worker keeps a product's items together and does all its English sheets before its Spanish ones, so English is out well before the run ends (the English pass is a bit longer than a quarter of the run: it also computes each product's data and publishes the private-label documents). If a run is stopped or killed part-way, the next run (the hourly CMS sync, or Publish All on the Bulk SDS Publish page) publishes just the languages each product is missing, at the same version. Until then the customer auto-send holds shipment lines for those products whose customers use one of the missing languages ("Waiting for languages" in the log), at most 24 hours. A product is up to date only from its current version's first sheet, so a raw material changed while a run was writing a product's other languages still gets that product a full new version.

1. - [ ] **Bulk SDS Publish page** — "Languages (publish order)" shows EN, ES, DE, FR. On the first visit after deploying, "Products missing languages of their current version" may show a one-time count: products an earlier run left with only some languages (for example job 2235, killed at 06:34 UTC). The next run completes them.
2. - [ ] **First run** — the Bulk Publish Queue's **Languages** column shows "✓ English done" with the time, well before the job ends, then Spanish, German and French (the Bulk SDS Publish page's progress line also says "English done."). The log has the same: `grep -E 'Publish order|pass complete|Missing languages' /var/www/sds-system/storage/logs/cms-sync.log | tail -n 8` shows `Publish order: EN, ES, DE, FR` and an "English pass complete after …" line.
3. - [ ] **Spot-check** — while a full run is on its Spanish pass, open a product it has already done: its English sheet is the new version, the other languages follow later in the run. After the run every language shows the same version.

### 3.8i TSCA overrides (2026-10-21, migration 066)

Deploy with `update.sh`: migration 066 adds `raw_materials.tsca_status`, `tsca_note`, `tsca_updated_by` and `tsca_updated_at`. Rule (2026-10-21): Section 15 checks only the CAS numbers that are provided. A component without a CAS is assumed listed: a raw material that is wholly a trade secret, a trade-secret constituent without a CAS, a constituent with a blank or non-CAS value ("Proprietary"), a raw material with no constituents, and whatever share the constituents leave undisclosed when they add up to less than 100%. So the sentence reads "not verified" only for a provided CAS that is not on the inventory and has no override, and "not listed" for one marked Not listed. CAS numbers are compared the same way everywhere, however they were typed (hidden characters, typographic dashes, internal spaces, zero padding).

1. - [ ] **CAS Determinations > TSCA Review** lists the provided constituent CAS numbers that are not on the inventory and have no override, on a sheet Bulk SDS Publish is cleared to publish: a product it does not skip (fresh ones included), with that product's alias and private-label sheets, or a resale item it does not block. CAS numbers only on other sheets wait: a collapsed line, "Not listed yet: N raw materials with a CAS not on the inventory, only on sheets Bulk SDS Publish is not cleared to publish", names their raw materials.
2. - [ ] For each CAS: set **Listed** (crossover / confidential-inventory CAS), **Exempt** (e.g. polymer exemption) or **Not listed** (verified absent) with a note. To save several at once, tick them (changing a row's status or note ticks it) and use **Save selected** in the bar at the bottom of the tab; the bar can also apply one status and note to every ticked row shown. Saving bumps the raw materials carrying the CAS and queues their products on SDS Updates.
3. - [ ] When the supplier's SDS certifies that every component of a raw material is listed on or exempt from TSCA, a raw-material override (raw material edit page, card "TSCA inventory status": Listed / Exempt with that basis as the note) covers its CAS numbers that are not on the inventory; a CAS marked Not listed still wins. The overrides in effect are listed at the bottom of the TSCA Review tab.
4. - [ ] **Bump ALL.** Press Settings → Maintenance → **Bump ALL unblocked SDSs** once and run Bulk SDS Publish (early in the day, see 3.8f): the rule change alone bumps nothing, so neither bulk publish nor SDS Updates would republish a sheet that said "not verified" only because of a component without a CAS.
5. - [ ] Open the **published** PDF (not only the preview, which always regenerates) of one product that showed "not verified": Section 15 reads "All components of this product are listed on or exempt from the TSCA inventory." If it still says not verified, the preview's Warnings box names the CAS numbers left (a value that is not a CAS number, such as "13463677", is listed on the TSCA Review to be corrected on the raw material).

### 3.9 Finish

- [ ] Settings → CMS Sync Schedule: "Auto bulk publish" ticked again.
- [ ] One bulk publish run (Bulk SDS Publish → Start, or the next cron tick). It picks up every product bumped in 3.1–3.5 and 3.8b–3.8c. After the findings batch that is every eligible product, alias and private-label document in four languages, so expect a long job. Products stopped by a publish gate (section C, e.g. transport "Not determined") count as failed in the job and are worked through by hand.

## 4. Functional verification

### A. Admin → Settings

- [ ] Page loads with no notice/warning.
- [ ] "Default VOC Calc Mode" is gone from SDS Configuration.
- [ ] "Missing Hazard Data Gate" checkbox shows the stored state; "Missing Data Threshold (%)" shows the stored value.
- [ ] "UV Acrylate Rule Pack" checkbox (its own heading, #35) shows the stored state.
- [ ] Save round-trips all of them: `settings` rows `sds.block_publish_missing` = `0`/`1`, `sds.missing_threshold_pct` = the number entered, `uv_acrylate_rule_pack` = `enabled`/`disabled`.
- [ ] Missing Data Threshold accepts 0.01–100 only: a blank or 0 is refused with "Settings not saved: Missing Data Threshold (%) must be …" (#69).
- [ ] `settings` table: no rows named `voc_calc_mode`, `sds.voc_calc_mode`, `source_priority`, `sara_deminimis_default`, `company_name`, `sds_block_publish_missing` (migration 055 deleted/moved them); `company.name` unchanged.
- [ ] Per-language legal disclaimer textareas (#34) show the translation default as placeholder; a saved text prints in Section 16 of that language only.
- [ ] Company block (#1): name, address incl. country, phone, email, website and logo editable; no fax field.
- [ ] Emergency phone (#2): clearing it and saving shows the required-field error (or the next publish is blocked, see C).
- [ ] Sections 12–15 footnote toggle (#25) present, default on.
- [ ] Settings → Product Families (#3) shows each family's UV/LED flag, its per-language default Recommended Use / Restrictions, and the three rule types (code prefix, description contains, specific codes). "Recompute now" shows a preview count before anything is applied.
- [ ] Regulatory list pages load and allow add/edit/delete: Prop 65, HAPs, SARA 313, TSCA Inventory (`/tsca`, #29; EPA rows refreshed by the import script, manual rows survive it) and RCRA Waste Codes (`/rcra`, #26).

### B. Generated SDS

Use at least one solvent-based FG, one UV/LED FG with acrylates, one resale
raw material and one private-label alias. Generate EN and ES for each, and FR
and DE for one of them. Check the PDF and the `?html=1` preview side by side.

- [ ] Header shows "SAFETY DATA SHEET" (EN) or the translated title (ES/FR/DE). Every section banner reads "SECTION n:" or the translated prefix. The footer shows the product code, "Page x of y" (translated) and "Rev. n — date" (translated). Document strings come via `SDSDocumentStrings` (#39/#40).
- [ ] Upper-cased banners and titles keep their accents (ES "SECCIÓN", FR "FICHE DE DONNÉES DE SÉCURITÉ"), and the FR footer reads "Page x sur y" (#37).
- [ ] Open a snapshot published BEFORE this update (`sds_versions`): same banners and footer render (fallback path for snapshots without `meta.document`); on an ES/FR/DE snapshot the banner, footer and PDF Title/Subject are in that language (#62). A classified product's old snapshot does not print "Not a hazardous substance or mixture." above its hazards, and no Section 13 or Section 15 "This section is not required by OSHA HazCom…" note prints (#63).
- [ ] PDF and `?html=1` preview agree on every section's content (#38: the preview renders the real PDF).
- [ ] No "Powered by TCPDF" link; PDF metadata Author = company name (or the private-label manufacturer); no blank band at the top of pages 2+; no repeating header (#41).
- [ ] No exact percentages anywhere — only bands; no assumption notes ("assumed", "not provided", "default used") anywhere (#42).
- [ ] Section 1: manufacturer block from company settings (standard) or from the private-label manufacturer (alias); emergency phone is the one that belongs to that block (#1, #2); Recommended Use / Restrictions fall back to the resolved product family default (#3); "Substance" prints only for a single-line formula whose raw is marked Substance or a resale raw marked Substance, otherwise "Mixture" (#6).
- [ ] Section 2: "Other hazards: None known." unless a per-product override exists (#4); UV/LED-family sheets print "Uncured product may polymerize when exposed to UV light, elevated temperatures or contaminants." instead (A9); PPE sentences match Section 8 (#15); carcinogen P-statements present, P281 absent (#5).
- [ ] Section 3: the three notes print (hazardous-only note reads "Hazardous ingredients and ingredients with an occupational exposure limit are listed."), 0.1% cut-off, prescribed-range bands (#7, #8); Section 8 Conc% column uses the same bands.
- [ ] Section 4: hazard fragments added, not substituted; 4(b) symptoms/effects line derived from the H-statements; notes to physician lead with H304 / H314 / H330-H331 fragments (#9, #10).
- [ ] Section 5: media keyed off Flam. Liq. category + Section 9 flash point; water-reactive products list no water spray (#11).
- [ ] Section 6: ignition-source / non-sparking wording only on flammables; containment wording matches the physical state (#12).
- [ ] Section 7: flammable + corrosive fragments both appear when both apply; storage names the Section 10 incompatible materials; H251 self-heating wording correct (#13).
- [ ] Section 8: engineering controls from fragments (powders → dust control; H224–226 → explosion-proof ventilation/bonding; H314/H318 → eyewash and safety shower) (#14); PPE unified with Section 2, "no special PPE" tier on unclassified products (#15); UV FG: the UV glove/eyewear sentence replaces the tier sentence (impervious gloves / goggles+face shield stand alone), the UV respiratory/clothing sentence is appended, only on lines its hazard data drives (all four when H317); an unclassified UV FG prints the plain "No special …" lines; Section 2 shows the same text (#29). Products with no exposure-limit rows print "No occupational exposure limits apply …"; the dust sentence prints for Powder only (#64). Skin-notation sentence under the limits table; no limit rows → ventilation sentence without exposure limits (A4).
- [ ] Section 9 (#16, #17, #18, #37):
  - Initial boiling point = the lowest raw-material boiling point.
  - Flash point = the wt%-weighted average of the raws that carry one ("> n" when any of them is ticked ">"); "Not determined" when none does. A Section 9 Flash Point or Initial Boiling Point edit must be a temperature with its unit (degree sign optional: "24 °C", "75 F"); other text is refused on save, and an older stored edit without one is ignored with a preview warning. The automatic value prints rounded to a whole degree with "(calculated)" (e.g. "74 °C (165 °F) (calculated)"), never up onto or across 23 / 60 / 93 °C and never down onto 60 / 93 °C (59.6 prints 59, 60.4 prints 61; 23.4 prints 23, as Category 3 starts at 23 °C); Section 5 prints the identical string; a kept Section 9 edit prints exactly as typed, without the marker (A10).
  - Odor comes from the dominant raw material; a product override wins.
  - Physical state comes from the highest-% raw material.
  - VOC lb/gal and VOC wt% print; "VOC less W&E" and solids vol% are gone.
  - The solubility sentence comes from the soluble fraction (Soluble / Partially / Negligible / Not soluble).
  - On ES/FR/DE sheets the standard physical-state and colour words are translated.
- [ ] Section 10: "unstable" wording for reactive H-classes; decomposition products from the nitrogen/sulfur/halogen flags on the CAS master; "protect from UV light" on every UV product regardless of the rule-pack toggle (#19). Section 7 Storage prints "Store away from UV light sources …" under the same family condition (#65). Oxidizer / explosive / self-heating / unstable-gas fixtures get their own Reactivity/Stability wording; H240-H242 reads "self-reactive substance or organic peroxide" (#34). UV/LED family (A9): Reactivity "Hazardous polymerization may occur ..."; incompatibles end with "peroxides and free-radical initiators" and Section 7 Storage repeats them; the inhibitor/oxygen Stability sentence prints only when acrylates are detected; all of this also with the rule-pack toggle off.
- [ ] Section 11: per-route acute toxicity with category statement + ATE value, "Not classified based on available data" otherwise (#20); chronic effects from H317/H334/H372-H373/CMR fragments with "none known" fallback (#21); one carcinogenicity block, 0.1% threshold (#22); UV rule-pack sentence present/absent per toggle (#35).
- [ ] Section 12: ecotoxicity echoes the aquatic H-statements + per-component aquatic table (#23); PBT line only from the SARA 313 PBT flag, otherwise "No data available" (#24).
- [ ] Section 13: every applicable RCRA characteristic listed; D codes from the admin table inside the "as sold" sentence (at any concentration); F/K/P/U listings in a separate "for reference (do not apply as sold)" sentence; D001 only with Flam. Liq. 1–3 (H224–H226) in Section 2; the reason comes from the engine's product flash point (the Q1 weighted average), never from a Section 9 edit (Section 9 edits are display-only and are dropped with a preview warning when they contradict the classification): below 60 °C prints "flash point below 60 °C", a "> n" value with n < 60 prints "flash point reported only as a minimum value below 60 °C (140 °F)", exactly 60 °C is Class 3 but not D001, no flash point means no D001; H224–H226 from a finished-good override prints the hazard-based reason (#26).
- [ ] Section 14: derived transport (Class 3 UN1210/UN1263/UN1993 + PG from flash point and boiling point — H224 = PG I when no IBP is known; Class 8 for Skin Corr. 1 (PG from the Skin Corr. sub-category) and for Met. Corr. 1 (H290) on a liquid (PG III, 49 CFR 173.137(c)(2); H226 + H290 = UN2924 3 (8)); 6.1 for acute tox 1–3; 49 CFR 173.2a precedence: UN2920 "Corrosive liquids, flammable" 8 (3) / UN2929 "Toxic liquids, flammable, organic" 6.1 (3) / UN2927-UN2928 6.1 (8) when 8 or 6.1 outranks 3 — 3+8+6.1 with 8 or 6.1 primary = Not determined; UN3082/UN3077 + marine pollutant for aquatic acute 1 / chronic 1–2; otherwise "Not regulated", with the ≥450 L combustible note on H227 liquids (not Solid / Powder / Paste) whose flash point is below 93 °C ("> n" counted as n, so "> 65 °C" gets it) or unknown (H227 from a finished-good override)); per-product override wins; viscous-liquid note printed on Class 3 PG II sheets (173.121(b)(1) reassignment candidates), not PG III; an n.o.s. entry with no derivable technical names raises a preview warning (#27).
- [ ] Section 15: OSHA status sentence follows the classification result (#28); TSCA sentence only when every ingredient is listed/exempt, otherwise "TSCA status has not been verified for all components" (#29); SARA 313 reportable components with their Section 3 band and de minimis, "none reportable" line when empty (#30, #42); HAP components with their band, total HAP as one figure; Prop 65 warning text plus chemical name + listing type only (#42); state-regulations note always prints, no Prop 65 fallback, admin override honoured (#31).
- [ ] Section 16: version + effective date only; no generation timestamp, change summary, formula version or "Revision Note" line on new documents (#32); abbreviations limited to terms used on the sheet (#33); legal disclaimer per language (#34).
- [ ] Sections 12–15 footnote appears once when the toggle is on and disappears after turning it off and regenerating (#25).
- [ ] ES / FR / DE sheets contain no English sheet text (scan every section, PDF and preview, #37). Allowed English (by design):
  - regulatory citations and acronyms (OSHA, HazCom, 29 CFR 1910.1200, 49 CFR, 40 CFR 261, TSCA, SARA 313, RCRA codes);
  - H/P codes, CAS and UN numbers, DOT proper shipping names (49 CFR);
  - chemical names and other data-entered text (see caveats).

  Pay particular attention to: "None" in Section 2, the "H-Codes" and "TRADE SECRET" cells, the Prop 65 warning and its "(trace)" suffix, carcinogen classifications, the Section 9 values, the UV rule-pack text and the PDF Title/Subject metadata.
  Findings batch (#62): Section 1 Recommended Use / Restrictions of a family with no text for that language print the translated default, never the English family text; ES Section 10 decomposition reads "La descomposición térmica puede liberar …"; FR/DE Section 12 component-table headers wrap inside their cells; numbers keep "." decimals and dates print m/d/Y in every language (owner decision).
- [ ] UV/LED-family FG, toggle on: UV sentences inside Section 4 Skin (acrylates ≥ 0.1 % named; none named when only trade names), Section 5 Specific Hazards, Section 6 Containment, Section 7 Handling, Section 8 PPE (hazard-driven lines / H317), plus the separate Section 11 note (editable in the SDS editor). Toggle off and regenerate: those disappear; the Section 7 UV storage sentence and Section 10 UV item remain. Hazard classification unchanged either way (#35, #50, #65).
- [ ] Section 7 "Store locked up." prints exactly when Section 2 lists P405 (e.g. carbon-black powder with H351) (#33). Section 4 Notes: an H318-only product prints the eye-damage note, never "treat corrosive burns"; an H304 + H301 product keeps "Immediately call a poison center or physician." after the aspiration paragraph (#30, #31).
- [ ] Override editor (#36): computed text shown as a hint, only operator-typed text stored, "Reset to automatic" clears the override; both cleanup dry runs report 0 to delete (step 3.4); Section 15 OSHA / TSCA status are shown read-only; each hint reflects the product's other saved edits (#57); resale items open the same editor from the Readiness page (#45).

### C. Publish gates

Pick one FG that contains a CAS ≥ 1% with no federal hazard data and no
Competent Person Determination.

- [ ] Gate ON, threshold 1.0: manual publish, resale publish, SDS Updates "Republish Selected", private-label publish and bulk publish (FG, alias, resale and private-label items; the scheduled run too) are all blocked with the same missing-data message (`SDSReadinessService::missingHazardDataError`). The bulk job lists the product once per work item.
- [ ] Gate OFF: the same FG publishes on every path. Auto-send never publishes: it only emails PDFs that are already published, so it has no missing-data gate of its own. The old `canAutoPublish()` gate was dead code and was removed in batch E (#69).
- [ ] Threshold raised above that CAS's % → publishes on every path; restore the threshold afterwards.
- [ ] Adding a CPD for that CAS → publishes with gate on and threshold 1.0.
- [ ] Blank emergency phone (company or private-label manufacturer) blocks publishing with a clear message (#2).
- [ ] Transport "Not determined" (#27) blocks manual publish, bulk publish, SDS Updates republish and private-label publish (auto-send only sends what is already published), with a message naming the product and the fix. It fires only when no single DOT entry fits (e.g. Class 3 + 8 + 6.1 with 8 or 6.1 outranking 3); a Section 14 override unblocks it. A product with no flash point data publishes with Section 14 "Not regulated" (or its other class) — Q2.
- [ ] A Not-determined Section 14 shows a reason-coded message (three-class 3/8/6.1, unsupported class) in every preview's Warnings box (finished good, resale, private-label live preview) and on publish; resale messages point to the resale editor (#45).
- [ ] Stored Section 9 temperature edits the new parser ignores (expected 0 after fixing them in SDS > Edit Text): `SELECT finished_good_id, language, field_key, override_text FROM text_overrides WHERE section_number = 9 AND field_key IN ('flash_point','boiling_point') AND sds_version_id IS NULL AND TRIM(COALESCE(override_text,'')) <> '' AND LOWER(override_text) NOT REGEXP '[0-9][[:space:]]*(°|º|deg(rees?)?[.]?)?[[:space:]]*(c|f|celsius|fahrenheit)([^a-z]|$)';`
- [ ] A component with unverified TSCA status (#29) raises the publish warning (a warning, not a block). Setting the CAS on CAS Determinations → TSCA Review to "Listed — on TSCA (crossover / confidential CAS)" or "Exempt from the TSCA inventory" clears it.
- [ ] Bulk publish picks up only products whose raw materials or families changed since the last publish (`raw_materials.updated_at`, plus `finished_goods.updated_at` for product-level edits — #58). The #18 solubility reset, the element-flag seed, the TSCA import and any family reassignment (#3) show up as stale; a metadata-only edit does not.
- [ ] Inactive FG (#69): set Status = Inactive, then Publish → "Publishing blocked: finished good … is inactive"; SDS Updates "Republish Selected" lists the same error.
- [ ] SDS text edit / hazard override (#58): saving a Section 7 edit lists the product on SDS Updates ("SDS text edited (EN): 7.handling") and the next bulk publish republishes that product only, not the products that share its raws.
- [ ] Settings bump offer (#58): changing Company → Phone and saving shows the "print on every SDS" warning naming `company.phone`.
- [ ] Blank company emergency phone (#68): the bulk job ends "failed" with "… Only private-label items were published in this job (n published …)". Private-label items still get new versions (source_fg_version = the latest published base version); SDS Updates "Republish Selected" points to "Republish Private Labels Only".
- [ ] Blank manufacturer name (#55): the manufacturer edit form refuses a whitespace-only name; a manufacturer blanked by SQL blocks private-label publishing with "has no name", and the preview Warnings box shows it.

### D. Auto-send / send queue

- [ ] One auto-send run completes without error; the generated PDF is written via `PDFService::generateToFile()` and attached; an `sds_send_log` row is created with the right customer/product/language.
- [ ] Send-queue manual send of the same document produces an identical attachment.
- [ ] A product blocked under C gets no new published SDS. If it never had one, auto-send queues the shipment for review (Send Queue, reason "SDS not available …") instead of sending. If an older version is published, auto-send sends that older PDF (known caveat).
- [ ] Alias sends (#53): send a queued item shipped under an alias pack code (e.g. BK1080-5G). The attachment is the alias's own sheet: Section 1 shows "BK1080 — <alias description>", the footer shows BK1080, and the version and date are those of the alias's sds_versions row. An alias with no published sheet is published first (it appears under the product's alias versions), then sent. If that publish is refused (e.g. a publish gate), the shipment is queued with the reason "Alias SDS could not be published — …" and no base sheet is sent.

### E. Data & imports

- [ ] CMS import (`cron/cms-sync.php`) runs clean; product families are recomputed after import (#3); affected products bumped.
- [ ] Raw material form: boiling point (°C), solubility (incl. "Negligible solubility in water"), Substance flag, family override fields present and save (#16, #18, #6, #3).
- [ ] Raw material form: the constituent table has no "Non-Haz" column (#17, decision Q5). `raw_material_constituents.is_non_hazardous` stays in the database unused (migration 058; a raw-material save clears it to 0). Rows still carrying the old flag: `sdsq 'SELECT COUNT(*) FROM raw_material_constituents WHERE is_non_hazardous = 1'` Count: ________
- [ ] Finished-good form: Substance/Mixture override (Auto / Substance / Mixture), family override, transport override present and save (#6, #3, #27).
- [ ] One-time #18 data change applied: the only raw materials reading "Soluble in water" are the ones re-set by hand in step 3.1, none reads "Partially soluble in water", and the products using the reset materials were republished.
- [ ] CAS Determinations: the CAS Descriptions tab has editable nitrogen / sulfur / halogen flags with a "Flags" save button (#19), and the TSCA Review tab lists unresolved components with the per-CAS override (#29).
- [ ] `scripts/import-tsca-inventory.php` dry run reports the inventory row count. Re-running with the same CSV and `--version` changes nothing ("Inserted: 0", "Updated: 0").

### F. Config file

- [ ] `config/config.php` (not in the repo) may still contain `'sds' => ['voc_calc_mode' => ...]`; harmless, optionally delete the line.
- [ ] `config/config.php` `sds.block_publish_missing` / `sds.missing_threshold_pct` are now only fallbacks for a missing settings row (055 seeds both rows); the settings page values are authoritative.

## 5. Known caveats

These ship as designed or are open follow-ups. They are not deploy failures.

- **Ingredient classes implied from PubChem H-codes (#19).** PubChem rows with no readable class are classified from their H-codes. Where one statement covers two categories (H300 = Acute Tox. 1 or 2, H350 = Carc. 1A or 1B), the more severe category is printed; a CAS determination overrides it.
- **Family inheritance has no minimum share (#3).** A product inherits the family with the largest wt% share among the raw materials that have a family. Raw materials with no family contribute nothing, so a product with a 3% UV raw material and nothing else classified inherits the UV family. That brings its UV flag, the UV rule-pack text, the Section 10 UV condition and the family's Section 1 defaults. Fix individual products with a manual family pick on the product form or a direct rule.
- **TSCA stays "not verified" until the inventory is imported (#29).** Before step 3.3, every product prints "TSCA status has not been verified for all components" and every publish shows the TSCA warning. After the import, components missing from the public inventory (confidential-inventory substances, polymers, crossover CAS) still need a per-CAS override on CAS Determinations → TSCA Review.
- **Transport "Not determined" blocks publishing only where no DOT entry fits (#27, Q2).** Class 3 + 8 + 6.1 products where 8 or 6.1 outranks 3 (and any unsupported class) need a Section 14 override before any publish path accepts them. A missing flash point no longer blocks: such a product is treated as not flammable, so a solvent raw without a flash point lowers the product's classification. The preview / publish warnings now name every raw material that carries a flammable-liquid constituent but has no flash point ("Flash point warning: raw material(s) …"); enter those flash points. A finished-good hazard override that sets a Flammable Liquids category the printed formula flash point does not support also raises a preview warning: enter the tested flash point as a Section 9 Flash Point edit.
- **Category 5 acute toxicity still shows in Section 2 on vendor data.** Category 5 (H303 / H313 / H333) is not adopted by HazCom. When it comes in on vendor or raw-material hazard data it still prints in Section 2; Section 11 already drops it. This is a hazard-engine follow-up, not fixed in this release.
- **Override cleanup recognises old automatic text heuristically (#36, #1).** Pass 1 removes exact matches of today's automatic text; pass 2 removes old translation / hard-coded strings (and joins of them), old lowest-raw flash points and old DOT table values saved before the #36 cutoff. Old wording it does not recognise stays "kept" and keeps masking the new derived text until it is reset in the editor (step 3.4).
- **Data-entered text is printed as entered, in every language (#37).** The translation files cover every fixed sheet string, but the following print as typed on ES/FR/DE sheets:
  - chemical names;
  - raw-material odor;
  - custom physical-state or colour options (anything outside the standard list);
  - trade-secret descriptions;
  - a product's own Recommended Use / Restrictions columns.

  A family default left blank for a language prints that language's translated default sentence, not the EN family text (#62).

  Per-language text overrides and per-language family defaults are the way to localise them.
- **Numbers and dates are not localised (owner decision, #62).** Every language prints "." decimals (bands, flash point, SG, VOC, TCLP limits, SARA/HAP figures) and m/d/Y dates. This is by design, not a translation gap.

- **Flammability now comes from the product flash point only (Q3, #9).** The hazard engine derives Flammable Liquids Category 1-4 (H224-H227) from the formula flash point and initial boiling point; ingredient H224-H227 no longer classify the mixture, and Sections 5, 7, 10, 13 (D001) and 14 (Class 3, PG) follow Section 2. Products whose solvent ingredient used to trigger H225/H226 at the 1 % cut-off change on republish (water-based inks typically become Category 4 / combustible or not flammable). Review with `php scripts/classify-diff.php --mode=live` before the bulk republish. A Section 9 Flash Point or Initial Boiling Point text edit that contradicts the classification is no longer printed (preview warning). Ingredients whose only hazard is flammability are no longer listed in Section 3 unless the product itself is flammable (App. D requires health-hazard ingredients).
