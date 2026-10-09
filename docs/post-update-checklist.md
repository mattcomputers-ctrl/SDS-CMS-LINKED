# Post-update functionality checklist (SDS content audit, 2026-10)

Walk this after deploying the SDS content audit update (batches A, B and the
lists/overrides/housekeeping batch). Every line is a concrete observable; tick
it only when you have seen it.

**Deploy:** `update.sh` (git reset --hard to the release commit) →
`php migrations/migrate.php` (053 product families, 054 physical props /
transport, 055 regulatory lists / overrides / #40 housekeeping) →
`php -l` on the changed files → in Docker run `tests/smoke_pdf.php` and every
DB-free `tests/Services/*.php` → clear opcache if enabled (restart php-fpm or
hit the opcache reset endpoint).

- [ ] `php migrations/migrate.php` prints 053, 054 and 055 as applied and `schema_migrations` holds all three.
- [ ] `tests/smoke_pdf.php` and the DB-free suites exit 0 on the server's PHP build.

## A. Admin > Settings

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
- [ ] Settings → Product Families page (#3) lists families with UV/LED flag, per-language default Recommended Use / Restrictions, and the three rule types (code prefix, description contains, specific codes); "Recompute" shows a preview count before applying.
- [ ] Regulatory list pages: Prop 65, HAPs, SARA 313, TSCA inventory (#29, refreshable import) and RCRA waste codes (#26) all load and allow add/edit/delete.

## B. Generated SDS

Use at least: one solvent-based FG, one UV/LED FG with acrylates, one resale
raw material, one private-label alias. Generate EN and ES for each (FR/DE for
one of them). Check the PDF and the `?html=1` preview side by side.

- [ ] Header shows "SAFETY DATA SHEET" (EN) / translated title (ES/FR/DE); every section banner reads "SECTION n:"/translated prefix; footer shows product code, "Page x of y"/translated, and "Rev. n — date"/translated (document strings via `SDSDocumentStrings`, #39/#40).
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
- [ ] Section 9: initial boiling point = lowest raw-material boiling point (#16); odor from the dominant raw, product override wins (#17); physical state from the highest-% raw; VOC lb/gal and VOC wt% print; "VOC less W&E" and solids vol% are gone; solubility sentence from the soluble fraction (Soluble / Partially / Negligible / Not soluble) (#18).
- [ ] Section 10: "unstable" wording for reactive H-classes; decomposition products from the nitrogen/sulfur/halogen flags on the CAS master; "protect from UV light" on every UV product regardless of the rule-pack toggle (#19).
- [ ] Section 11: per-route acute toxicity with category statement + ATE value, "Not classified based on available data" otherwise (#20); chronic effects from H317/H334/H372-H373/CMR fragments with "none known" fallback (#21); one carcinogenicity block, 0.1% threshold (#22); UV rule-pack sentence present/absent per toggle (#35).
- [ ] Section 12: ecotoxicity echoes the aquatic H-statements + per-component aquatic table (#23); PBT line only from the SARA 313 PBT flag, otherwise "No data available" (#24).
- [ ] Section 13: every applicable RCRA characteristic listed; D codes from the admin table inside the "as sold" sentence (at any concentration); F/K/P/U listings in a separate "for reference (do not apply as sold)" sentence; D001 from the same flash point Sections 5/9/14 use (Section 9 override first; a "> n" value with n < 60 prints D001 with the "not determined to be at or above 60 °C" reason, matching Section 14 Class 3) (#26).
- [ ] Section 14: derived transport (Class 3 UN1210/UN1263/UN1993 + PG from flash point and boiling point — H224 = PG I when no IBP is known; Class 8 for Skin Corr. 1; 6.1 for acute tox 1–3; 49 CFR 173.2a precedence: UN2920 "Corrosive liquids, flammable" 8 (3) / UN2929 "Toxic liquids, flammable, organic" 6.1 (3) / UN2927-UN2928 6.1 (8) when 8 or 6.1 outranks 3 — 3+8+6.1 with 8 or 6.1 primary = Not determined; UN3082/UN3077 + marine pollutant for aquatic acute 1 / chronic 1–2; otherwise "Not regulated" with the ≥450 L combustible note (not for "> n" values)); per-product override wins; viscous-liquid note printed on Class 3 PG II sheets (173.121(b)(1) reassignment candidates), not PG III; an n.o.s. entry with no derivable technical names raises a preview warning (#27).
- [ ] Section 15: OSHA status sentence follows the classification result (#28); TSCA sentence only when every ingredient is listed/exempt, otherwise "TSCA status has not been verified for all components" (#29); SARA 313 reportable components with % and de minimis, "none reportable" line when empty (#30, #42); HAP components with %; Prop 65 chemical name + listing type only (#42); state-regulations note always prints, no Prop 65 fallback, admin override honoured (#31).
- [ ] Section 16: version + effective date only; no generation timestamp, change summary, formula version or "Revision Note" line on new documents (#32); abbreviations limited to terms used on the sheet (#33); legal disclaimer per language (#34).
- [ ] Sections 12–15 footnote appears once when the toggle is on and disappears after turning it off and regenerating (#25).
- [ ] ES / FR / DE sheets contain no English (scan every section; #37).
- [ ] UV FG with acrylates: rule-pack text present in Sections 4, 5, 6, 7, 8 and 11 when the toggle is on; absent after turning it off and regenerating; hazard classification unchanged either way (#35).
- [ ] Override editor (#36): computed text shown as a hint, only operator-typed text stored, "Reset to automatic" clears the override; `scripts/cleanup-default-overrides.php` dry run reports zero remaining overrides equal to the generated default.

## C. Publish gates

Pick one FG that contains a CAS ≥ 1% with no federal hazard data and no
Competent Person Determination.

- [ ] Gate ON, threshold 1.0: manual publish, bulk publish AND auto-send (cron dry run or send queue) are all blocked with the same missing-data message (`SDSReadinessService::missingHazardDataError`).
- [ ] Gate OFF: the same FG publishes on all three paths. **New behaviour:** auto-send previously ignored the DB setting and always gated; it now honours `sds.block_publish_missing` and `sds.missing_threshold_pct` from the settings table instead of `config.php` (#40).
- [ ] Threshold raised above that CAS's % → publishes on all three paths; restore the threshold afterwards.
- [ ] Adding a CPD for that CAS → publishes with gate on and threshold 1.0.
- [ ] Blank emergency phone (company or private-label manufacturer) blocks publishing with a clear message (#2).
- [ ] Transport "Not determined" blocks publishing (#27); setting the per-product override unblocks it.
- [ ] TSCA unverified component shows the publish warning (#29); marking the CAS "on TSCA — crossover/confidential" or "exempt" on the determinations page clears it.
- [ ] Bulk publish picks up only products whose raw materials / families changed since the last publish (`raw_materials.updated_at` staleness signal): the #18 solubility data change and any family reassignment (#3) show up as stale; a metadata-only edit does not.

## D. Auto-send / send queue

- [ ] One auto-send run completes without error; the generated PDF is written via `PDFService::generateToFile()` and attached; an `sds_send_log` row is created with the right customer/product/language.
- [ ] Send-queue manual send of the same document produces an identical attachment.
- [ ] A product blocked under C is skipped by auto-send and logged as skipped, not sent.

## E. Data & imports

- [ ] CMS import (`cron/cms-sync.php`) runs clean; product families are recomputed after import (#3); affected products bumped.
- [ ] Raw material form: boiling point (°C), solubility (incl. "Negligible solubility in water"), Substance flag, family override fields present and save (#16, #18, #6, #3).
- [ ] Finished-good form: Substance/Mixture override (Auto / Substance / Mixture), family override, transport override present and save (#6, #3, #27).
- [ ] One-time #18 data change applied: no raw material still reads "Soluble" / "Partially soluble" unless re-entered by hand; the affected products were republished.
- [ ] CAS determinations page: nitrogen/sulfur/halogen flags editable (#19); TSCA review queue and per-CAS override present (#29).
- [ ] `scripts/import-tsca-inventory.php` dry run reports the inventory row count; re-run is idempotent.

## F. Config file

- [ ] `config/config.php` (not in the repo) may still contain `'sds' => ['voc_calc_mode' => ...]`; harmless, optionally delete the line.
- [ ] `config/config.php` `sds.block_publish_missing` / `sds.missing_threshold_pct` are now only fallbacks for a missing settings row (055 seeds both rows); the settings page values are authoritative.
