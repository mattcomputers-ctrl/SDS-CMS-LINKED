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
- [ ] A product containing zinc oxide (or any metal-compound pigment except copper phthalocyanine blue/green and barium sulfate) lists "Zinc compounds (CAS 1314-13-2) — <band> (de minimis threshold: 1%)" under SARA 313 in the preview (?html=1) and the PDF.
- [ ] A product with a PBT chemical below 0.1 % (e.g. lead impurity) lists it with "(PBT chemical; no de minimis exemption)". Section 12 names the same PBT on its persistence line.
- [ ] TRI PFAS (2023 TRI rule: chemicals of special concern, no de minimis): `sdsq 'SELECT COUNT(*) FROM sara313_list WHERE is_special_concern = 1 AND is_pbt = 0'` returns about 202 on the shipped seed. Raw-material constituents that are TRI PFAS (each prints in Section 15 at any concentration with "(Chemical of special concern (40 CFR 372.28); no de minimis exemption)"):

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
for t in HazardEngineGoldenTest HazardClassAliasesTest CarbonBlackProp65Test SubFgProp65PropagationTest; do
  sudo -u www-data php /var/www/sds-system/tests/Services/$t.php > /tmp/$t.log 2>&1 \
    && echo "PASS $t" || echo "FAIL $t (see /tmp/$t.log)"
done
```

- [ ] All four print PASS. HazardEngineGoldenTest creates and removes its own fixtures. The two Prop 65 suites exit 0 with a SKIP line when the data they need is absent.
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
- [ ] Section 2: "Other hazards: None known." unless a per-product override exists (#4); PPE sentences match Section 8 (#15); carcinogen P-statements present, P281 absent (#5).
- [ ] Section 3: the three notes print (hazardous-only note reads "Hazardous ingredients and ingredients with an occupational exposure limit are listed."), 0.1% cut-off, prescribed-range bands (#7, #8); Section 8 Conc% column uses the same bands.
- [ ] Section 4: hazard fragments added, not substituted; 4(b) symptoms/effects line derived from the H-statements; notes to physician lead with H304 / H314 / H330-H331 fragments (#9, #10).
- [ ] Section 5: media keyed off Flam. Liq. category + Section 9 flash point; water-reactive products list no water spray (#11).
- [ ] Section 6: ignition-source / non-sparking wording only on flammables; containment wording matches the physical state (#12).
- [ ] Section 7: flammable + corrosive fragments both appear when both apply; storage names the Section 10 incompatible materials; H251 self-heating wording correct (#13).
- [ ] Section 8: engineering controls from fragments (powders → dust control; H224–226 → explosion-proof ventilation/bonding; H314/H318 → eyewash and safety shower) (#14); PPE unified with Section 2, "no special PPE" tier on unclassified products (#15); UV FG gets the UV PPE sentence only on PPE lines its hazard data drives (all four lines when it is H317); an unclassified UV FG prints the plain "No special …" lines; Section 2 shows the same text (#29). Products with no exposure-limit rows print "No occupational exposure limits apply …"; the dust sentence prints for Powder only (#64).
- [ ] Section 9 (#16, #17, #18, #37):
  - Initial boiling point = the lowest raw-material boiling point.
  - Flash point = the wt%-weighted average of the raws that carry one ("> n" when any of them is ticked ">"); "Not determined" when none does. A Section 9 Flash Point or Initial Boiling Point edit must be a temperature with its unit (degree sign optional: "24 °C", "75 F"); other text is refused on save, and an older stored edit without one is ignored with a preview warning.
  - Odor comes from the dominant raw material; a product override wins.
  - Physical state comes from the highest-% raw material.
  - VOC lb/gal and VOC wt% print; "VOC less W&E" and solids vol% are gone.
  - The solubility sentence comes from the soluble fraction (Soluble / Partially / Negligible / Not soluble).
  - On ES/FR/DE sheets the standard physical-state and colour words are translated.
- [ ] Section 10: "unstable" wording for reactive H-classes; decomposition products from the nitrogen/sulfur/halogen flags on the CAS master; "protect from UV light" on every UV product regardless of the rule-pack toggle (#19). Section 7 Storage prints "Store away from UV light sources …" under the same family condition (#65). Oxidizer / explosive / self-heating / unstable-gas fixtures get their own Reactivity/Stability wording; H240-H242 reads "self-reactive substance or organic peroxide" (#34).
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
