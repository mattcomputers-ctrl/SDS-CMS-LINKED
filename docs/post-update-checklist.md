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

## 1. Pre-flight (before deploying)

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

```bash
sudo -u www-data php /var/www/sds-system/scripts/seed-cas-element-flags.php > ~/element-flags-dryrun.txt   # dry run
sudo -u www-data php /var/www/sds-system/scripts/seed-cas-element-flags.php --confirm                      # apply
```

- [ ] `tail -12 ~/element-flags-dryrun.txt` shows the "=== Summary ===" block and "DRY-RUN: no DB writes". Rows in the file whose basis is a name keyword rather than a molecular formula have been reviewed for false positives.
- [ ] The `--confirm` run reports the same "Changed" count, plus non-zero "RMs bumped" / "SDSs queued". Add `--no-queue` if the SDS Updates page would be flooded: the raw materials are still bumped, so bulk publish still picks the products up.
- [ ] A second dry run reports "Changed: 0".
- [ ] False positives corrected with the "Flags" button on CAS Determinations → CAS Descriptions. Those rows become `manual`, and later seed runs skip them unless `--force` is given.

### 3.3 TSCA inventory import (#29)

Download the non-confidential TSCA Inventory zip from
<https://www.epa.gov/tsca-inventory/how-access-tsca-inventory>, extract the
CSV (e.g. `TSCAINV_022025.csv`) and copy it to `/tmp` on the server.

```bash
sudo -u www-data php /var/www/sds-system/scripts/import-tsca-inventory.php /tmp/TSCAINV_022025.csv             # dry run
sudo -u www-data php /var/www/sds-system/scripts/import-tsca-inventory.php /tmp/TSCAINV_022025.csv --confirm   # apply
```

- [ ] Dry run prints the detected columns ("Columns: cas=…, name=…") and "Parsed n data rows → m unique CAS". Exit code 2 means the header row was not recognised; the script prints the headers it found.
- [ ] `--confirm` summary: "Inserted" ≈ unique CAS. On a first import "CAS in use changed", "RMs bumped" and "SDSs queued" are non-zero, because every listed component changes its Section 15 sentence.
- [ ] TSCA Inventory page (`/tsca`) shows the row count and the latest import.
- [ ] CAS Determinations → TSCA Review lists the components still unresolved (confidential-inventory substances, polymers, crossover CAS). Each one has an override ("Listed — on TSCA (crossover / confidential CAS)", "Exempt from the TSCA inventory" or "Not listed (verified absent)") with the required note.

### 3.4 Override cleanup (#36)

```bash
sudo -u www-data php /var/www/sds-system/scripts/cleanup-default-overrides.php           # dry run
sudo -u www-data php /var/www/sds-system/scripts/cleanup-default-overrides.php --apply   # delete
```

- [ ] Dry run prints "Equal to automatic (delete): n". Any "Generation failed (kept)" groups are products that do not generate today; they have been noted.
- [ ] `--apply` prints "Deleted n row(s)." with the same n, and a second dry run reports "Equal to automatic (delete): 0". No republish is needed because printed output is unchanged.
- [ ] The kept rows have been reviewed. Overrides that froze the **pre-update** automatic text no longer equal today's automatic text, so the script counts them as "Custom text (kept)". They keep masking the new derived wording. The same stock sentence repeated across many products is the tell:

  ```bash
  sdsq 'SELECT section_number, field_key, language, COUNT(*) AS n, LEFT(override_text, 80) FROM text_overrides WHERE sds_version_id IS NULL GROUP BY section_number, field_key, language, override_text HAVING n >= 5 ORDER BY n DESC'
  ```

  Clear those in the SDS editor (`/sds/{fg}/edit?lang=xx` → "Reset to automatic").

### 3.5 Product families (#3)

- [ ] Settings → Product Families (`/admin/product-families`) lists the families migration 053 seeded: the old `sds.product_families` list plus every family name already on a finished good, with those finished goods linked as manual picks.
- [ ] UV/LED flags reviewed. 053 ticked every family whose name contains "UV" or "LED" as a word. The flag gates the UV acrylate rule pack and the Section 10 UV condition.
- [ ] Per-language Recommended Use / Restrictions defaults filled in for each family. A blank ES/FR/DE value falls back to the EN text, which then prints in English on that sheet.
- [ ] Membership rules added (code prefix, e.g. `VEC47` for UV raw materials; description contains; specific codes). Rules on raw materials propagate: a product inherits the family with the largest wt% share of its expanded formula.
- [ ] "Recompute now" shows the preview with a count of items that would change family. After Apply, the reassigned products with a published SDS appear on SDS Updates.

### 3.6 Private-label backfill review

- [ ] `/private-label` → each manufacturer: items whose notes read "Backfilled … migration 051" reviewed. One-off combinations are retired, and items that must not follow the base SDS are frozen (auto-republish off). Every base publish cascades a new version to the active, auto-republish items.

### 3.7 Private-label duplicate check

```bash
sudo -u www-data php /var/www/sds-system/scripts/check-pl-duplicates.php; echo "exit=$?"
```

- [ ] `exit=0` (no duplicate (item_id, language, version) groups) and zero rows with `item_id IS NULL`. The listed code mismatches (informational) have been reviewed and either republished individually or re-pointed on the item.

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

### 3.9 Finish

- [ ] Settings → CMS Sync Schedule: "Auto bulk publish" ticked again.
- [ ] One bulk publish run (Bulk SDS Publish → Start, or the next cron tick). It picks up every product bumped in 3.1–3.5, so expect a large job. Products stopped by a publish gate (section C, e.g. transport "Not determined") count as failed in the job and are worked through by hand.

## 4. Functional verification

### A. Admin → Settings

- [ ] Page loads with no notice/warning.
- [ ] "Default VOC Calc Mode" is gone from SDS Configuration.
- [ ] "Missing Hazard Data Gate" checkbox shows the stored state; "Missing Data Threshold (%)" shows the stored value.
- [ ] "UV Acrylate Rule Pack" checkbox (its own heading, #35) shows the stored state.
- [ ] Save round-trips all of them: `settings` rows `sds.block_publish_missing` = `0`/`1`, `sds.missing_threshold_pct` = the number entered, `uv_acrylate_rule_pack` = `enabled`/`disabled`.
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
- [ ] Open a snapshot published BEFORE this update (`sds_versions`): same banners and footer render (fallback path for snapshots without `meta.document`).
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
- [ ] Section 8: engineering controls from fragments (powders → dust control; H224–226 → explosion-proof ventilation/bonding; H314/H318 → eyewash and safety shower) (#14); PPE unified with Section 2, "no special PPE" tier on unclassified products (#15); UV FG gets UV-specific PPE sentences when the rule pack is on (#35).
- [ ] Section 9 (#16, #17, #18, #37):
  - Initial boiling point = the lowest raw-material boiling point.
  - Odor comes from the dominant raw material; a product override wins.
  - Physical state comes from the highest-% raw material.
  - VOC lb/gal and VOC wt% print; "VOC less W&E" and solids vol% are gone.
  - The solubility sentence comes from the soluble fraction (Soluble / Partially / Negligible / Not soluble).
  - On ES/FR/DE sheets the standard physical-state and colour words are translated.
- [ ] Section 10: "unstable" wording for reactive H-classes; decomposition products from the nitrogen/sulfur/halogen flags on the CAS master; "protect from UV light" on every UV product regardless of the rule-pack toggle (#19).
- [ ] Section 11: per-route acute toxicity with category statement + ATE value, "Not classified based on available data" otherwise (#20); chronic effects from H317/H334/H372-H373/CMR fragments with "none known" fallback (#21); one carcinogenicity block, 0.1% threshold (#22); UV rule-pack sentence present/absent per toggle (#35).
- [ ] Section 12: ecotoxicity echoes the aquatic H-statements + per-component aquatic table (#23); PBT line only from the SARA 313 PBT flag, otherwise "No data available" (#24).
- [ ] Section 13: every applicable RCRA characteristic listed; D codes from the admin table inside the "as sold" sentence (at any concentration); F/K/P/U listings in a separate "for reference (do not apply as sold)" sentence; D001 from the same flash point Sections 5/9/14 use (Section 9 override first; a "> n" value with n < 60 prints D001 with the "not determined to be at or above 60 °C" reason, matching Section 14 Class 3) (#26).
- [ ] Section 14: derived transport (Class 3 UN1210/UN1263/UN1993 + PG from flash point and boiling point — H224 = PG I when no IBP is known; Class 8 for Skin Corr. 1; 6.1 for acute tox 1–3; 49 CFR 173.2a precedence: UN2920 "Corrosive liquids, flammable" 8 (3) / UN2929 "Toxic liquids, flammable, organic" 6.1 (3) / UN2927-UN2928 6.1 (8) when 8 or 6.1 outranks 3 — 3+8+6.1 with 8 or 6.1 primary = Not determined; UN3082/UN3077 + marine pollutant for aquatic acute 1 / chronic 1–2; otherwise "Not regulated" with the ≥450 L combustible note (not for "> n" values)); per-product override wins; viscous-liquid note printed on Class 3 PG II sheets (173.121(b)(1) reassignment candidates), not PG III; an n.o.s. entry with no derivable technical names raises a preview warning (#27).
- [ ] Section 15: OSHA status sentence follows the classification result (#28); TSCA sentence only when every ingredient is listed/exempt, otherwise "TSCA status has not been verified for all components" (#29); SARA 313 reportable components with their Section 3 band and de minimis, "none reportable" line when empty (#30, #42); HAP components with their band, total HAP as one figure; Prop 65 warning text plus chemical name + listing type only (#42); state-regulations note always prints, no Prop 65 fallback, admin override honoured (#31).
- [ ] Section 16: version + effective date only; no generation timestamp, change summary, formula version or "Revision Note" line on new documents (#32); abbreviations limited to terms used on the sheet (#33); legal disclaimer per language (#34).
- [ ] Sections 12–15 footnote appears once when the toggle is on and disappears after turning it off and regenerating (#25).
- [ ] ES / FR / DE sheets contain no English sheet text (scan every section, PDF and preview, #37). Allowed English (by design):
  - regulatory citations and acronyms (OSHA, HazCom, 29 CFR 1910.1200, 49 CFR, 40 CFR 261, TSCA, SARA 313, RCRA codes);
  - H/P codes, CAS and UN numbers, DOT proper shipping names (49 CFR);
  - chemical names and other data-entered text (see caveats).

  Pay particular attention to: "None" in Section 2, the "H-Codes" and "TRADE SECRET" cells, the Prop 65 warning and its "(trace)" suffix, carcinogen classifications, the Section 9 values, the UV rule-pack text and the PDF Title/Subject metadata.
- [ ] UV FG with acrylates: rule-pack text present in Sections 4, 5, 6, 7, 8 and 11 when the toggle is on; absent after turning it off and regenerating; hazard classification unchanged either way (#35).
- [ ] Override editor (#36): computed text shown as a hint, only operator-typed text stored, "Reset to automatic" clears the override; `scripts/cleanup-default-overrides.php` dry run reports zero remaining overrides equal to the generated default (step 3.4).

### C. Publish gates

Pick one FG that contains a CAS ≥ 1% with no federal hazard data and no
Competent Person Determination.

- [ ] Gate ON, threshold 1.0: manual publish, bulk publish AND auto-send (cron dry run or send queue) are all blocked with the same missing-data message (`SDSReadinessService::missingHazardDataError`).
- [ ] Gate OFF: the same FG publishes on all three paths. **New behaviour:** auto-send previously ignored the DB setting and always gated; it now honours `sds.block_publish_missing` and `sds.missing_threshold_pct` from the settings table instead of `config.php` (#40).
- [ ] Threshold raised above that CAS's % → publishes on all three paths; restore the threshold afterwards.
- [ ] Adding a CPD for that CAS → publishes with gate on and threshold 1.0.
- [ ] Blank emergency phone (company or private-label manufacturer) blocks publishing with a clear message (#2).
- [ ] Transport "Not determined" (#27) blocks manual publish, bulk publish, SDS Updates republish, private-label publish and auto-send, with a message naming the product and the fix. Entering a flash point on a raw material, or a Section 14 override (UN number + hazard class), unblocks it.
- [ ] A component with unverified TSCA status (#29) raises the publish warning (a warning, not a block). Setting the CAS on CAS Determinations → TSCA Review to "Listed — on TSCA (crossover / confidential CAS)" or "Exempt from the TSCA inventory" clears it.
- [ ] Bulk publish picks up only products whose raw materials or families changed since the last publish (`raw_materials.updated_at` staleness signal). The #18 solubility reset, the element-flag seed, the TSCA import and any family reassignment (#3) show up as stale; a metadata-only edit does not.

### D. Auto-send / send queue

- [ ] One auto-send run completes without error; the generated PDF is written via `PDFService::generateToFile()` and attached; an `sds_send_log` row is created with the right customer/product/language.
- [ ] Send-queue manual send of the same document produces an identical attachment.
- [ ] A product blocked under C is skipped by auto-send and logged as skipped, not sent.

### E. Data & imports

- [ ] CMS import (`cron/cms-sync.php`) runs clean; product families are recomputed after import (#3); affected products bumped.
- [ ] Raw material form: boiling point (°C), solubility (incl. "Negligible solubility in water"), Substance flag, family override fields present and save (#16, #18, #6, #3).
- [ ] Finished-good form: Substance/Mixture override (Auto / Substance / Mixture), family override, transport override present and save (#6, #3, #27).
- [ ] One-time #18 data change applied: the only raw materials reading "Soluble in water" are the ones re-set by hand in step 3.1, none reads "Partially soluble in water", and the products using the reset materials were republished.
- [ ] CAS Determinations: the CAS Descriptions tab has editable nitrogen / sulfur / halogen flags with a "Flags" save button (#19), and the TSCA Review tab lists unresolved components with the per-CAS override (#29).
- [ ] `scripts/import-tsca-inventory.php` dry run reports the inventory row count. Re-running with the same CSV and `--version` changes nothing ("Inserted: 0", "Updated: 0").

### F. Config file

- [ ] `config/config.php` (not in the repo) may still contain `'sds' => ['voc_calc_mode' => ...]`; harmless, optionally delete the line.
- [ ] `config/config.php` `sds.block_publish_missing` / `sds.missing_threshold_pct` are now only fallbacks for a missing settings row (055 seeds both rows); the settings page values are authoritative.

## 5. Known caveats

These ship as designed or are open follow-ups. They are not deploy failures.

- **Family inheritance has no minimum share (#3).** A product inherits the family with the largest wt% share among the raw materials that have a family. Raw materials with no family contribute nothing, so a product with a 3% UV raw material and nothing else classified inherits the UV family. That brings its UV flag, the UV rule-pack text, the Section 10 UV condition and the family's Section 1 defaults. Fix individual products with a manual family pick on the product form or a direct rule.
- **TSCA stays "not verified" until the inventory is imported (#29).** Before step 3.3, every product prints "TSCA status has not been verified for all components" and every publish shows the TSCA warning. After the import, components missing from the public inventory (confidential-inventory substances, polymers, crossover CAS) still need a per-CAS override on CAS Determinations → TSCA Review.
- **Transport "Not determined" blocks publishing until flash points are entered (#27).** This applies when no raw material in the formula has a flash point and the classification returns no hazard data. It also applies to Class 3 + 8 + 6.1 products where 8 or 6.1 outranks 3. Every publish path is blocked, so expect these products in the failed count of the first bulk publish (step 3.9). Fix with a flash point on the raw material(s) or a Section 14 override.
- **Category 5 acute toxicity still shows in Section 2 on vendor data.** Category 5 (H303 / H313 / H333) is not adopted by HazCom. When it comes in on vendor or raw-material hazard data it still prints in Section 2; Section 11 already drops it. This is a hazard-engine follow-up, not fixed in this release.
- **Override cleanup only removes exact matches of today's automatic text (#36).** Overrides that froze the pre-update default wording are kept as "custom" and keep masking the new derived text until they are reset in the editor (step 3.4).
- **Data-entered text is printed as entered, in every language (#37).** The translation files cover every fixed sheet string, but the following print as typed on ES/FR/DE sheets:
  - chemical names;
  - raw-material odor;
  - custom physical-state or colour options (anything outside the standard list);
  - trade-secret descriptions;
  - a product's own Recommended Use / Restrictions columns;
  - a family default left blank for that language (falls back to the EN text).

  Per-language text overrides and per-language family defaults are the way to localise them.
