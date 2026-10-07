# SDS Content Audit — data-driven vs prefilled
_Generated 2026-10-07 by a 58-agent audit workflow (19 section sweeps, 2 verification lenses per section, synthesis). Status: **review only — no code changed.** Decisions are pending a review session; nothing here is scheduled._
Companion file: [sds-content-audit-field-inventory.md](sds-content-audit-field-inventory.md) — every rendered field with its source classification and code location.
---
## SDS Generation — Data-Driven vs Prefilled Review

Scope: every field rendered on the generated SDS (PDF + HTML preview) for the US / OSHA HazCom 2012 (GHS) output, per the verified audit of `src/Services/SDSGenerator.php`, `PDFService.php`, `HazardEngine.php`, `src/Views/sds/preview.php` and `templates/translations/*.php`. No code was changed.

**How refutations were treated.** Many sweep claims were "refuted" only because the text varies by language or because a per-FG `text_overrides` row *could* replace it. Those fields are still canned defaults for every product that has no override, so they are kept in the findings below with that note. The Refuted appendix lists only the claims where verification showed a genuine data path.

**Concern scale.** `high` = can print an incorrect or missing regulatory statement for products realistically in an ink/coatings catalog (solvent inks, UV inks, resale RMs, private-label). `medium` = should be data-driven or admin-editable; current text is generic rather than wrong. `low` = fine as a constant; noted for completeness.

## Summary

Roughly a third of the visible sheet is genuinely data-driven, and that third is the right third: Section 2 classification, Section 3 composition, the Section 8 OEL table, most of Section 9, the Section 11 carcinogen/component block and the Section 15 HAP/SNUR/Prop 65 blocks. Sections 4, 5, 6, 7, 10, 12 and 13 are one-of-N canned paragraphs chosen by a few H-codes — for a typical non-flammable, non-corrosive ink every paragraph is the identical default. Section 8 Engineering Controls, Section 11 Acute/Chronic, Section 15 OSHA/TSCA status, Section 16 and the four "not required by OSHA" notes are pure constants; Section 14 is a single-ingredient DOT proxy that defaults to "Not regulated". Three constants are wrong for real products (S11 acute tox, S15 OSHA status, S14 transport), one regulatory block (SARA 313) never renders, and the override editor freezes generated text on save.

## Summary table

| # | Section | Field | Concern | Recommendation |
|---|---------|-------|---------|----------------|
| 1 | 1 | Manufacturer/supplier block (name, address, phone, email, website, logo) | low | Leave as setting; fix PDF parity (email/website), include country, DB name for PDF Author |
| 2 | 1 | Emergency phone on private-label variants | **high** | Fix `??` fallback (blank string) + publish warning for blank manufacturer numbers |
| 3 | 1 | Recommended Use / Restrictions fallback chain (+ Product Family) | **high** | Family-level defaults + per-FG override; resale-RM default; derive family on CMS import |
| 4 | 2 | Other hazards / HNOC + unknown-ATE statement | **high** | Always render default; engine emits unknown-ATE %; small HNOC rule table |
| 5 | 2 | Carcinogen P-statement list (P281) | medium | Move to GHSHazardData class defaults; drop P281 |
| 6 | 3 | Type: "Mixture" | **high** | Derive Substance vs Mixture from composition (resale path) |
| 7 | 3 | Canned notes (hazardous-only, no-hazardous, trade-secret) | medium | Keep constants; add to preview; reword hazardous-only note |
| 8 | 3 | 0.1% threshold + prescribed-range bands (and S8 Conc% leak) | medium | Keep policy, document it, band/drop Section 8 Conc% |
| 9 | 4 | Notes to Physician | medium | Hazard fragments (aspiration, corrosive, acute tox), default-text table |
| 10 | 4 | First-aid default paragraphs | medium | Widen H-code map; add 4(b) symptoms line from H-statements |
| 11 | 5 | Fire-fighting paragraphs | medium | Key off Flam. Liq. category; use recursive flash point; fix water-reactive media |
| 12 | 6 | Accidental release paragraphs | medium | Additive fragments; split aquatic wording; derive physical state |
| 13 | 7 | Handling / Storage | medium | Additive fragments; non-flammable default; fix H251 |
| 14 | 8 | Engineering Controls | medium | Hazard/state fragments (dust, explosion-proof, eyewash) |
| 15 | 2/8 | PPE sentences (derivePPE) | medium | Return translation keys; unify S2/S8 precedence; "no special PPE" tier |
| 16 | 9 | Boiling Point "Not determined" | medium | Add RM boiling point; derive lowest |
| 17 | 9 | Odor blank / RM odor unused | medium | Derive from dominant RM; fallback "Not determined" |
| 18 | 9 | Hidden numeric defaults (SG=1.0, VOC=0, solids) | **high** | Print "Not determined"/(estimated) when defaulted; surface assumptions; publish warning |
| 19 | 10 | All five paragraphs | medium | Branch reactivity/stability; implement N/S/halogen decomposition; UV condition |
| 20 | 11 | Acute Toxicity | **high** | Derive from hazard_classes + ATE |
| 21 | 11 | Chronic Effects | **high** | Health-class fragments (H317/H334/H372…); "none known" fallback |
| 22 | 11 | Carcinogenicity default + hardcoded positive summary | medium | One builder via translation keys; align 0.01/0.1 thresholds |
| 23 | 12 | Ecotoxicity | medium | Echo resolved aquatic H-statements; component aquatic table |
| 24 | 12 | Persistence / Bioaccumulation | low | Leave constant; later PBT line from sara313_list.is_pbt |
| 25 | 12–15 | "Not required by OSHA" notes | medium | One shared key + toggle, footnote style, fix S14 wording |
| 26 | 13 | Disposal Methods | medium | List all characteristics; recursive flash point; CAS-based D/P/U lookup |
| 27 | 14 | UN/PSN/Class/PG defaults | **high** | Derive from flash/BP + Skin Corr./Aquatic; n.o.s. entries; override wins; "Not determined" blocks publish |
| 28 | 15 | OSHA Status | **high** | Branch on is_classified |
| 29 | 15 | TSCA Status | **high** | Constituent TSCA flag rolled up like HAPs; interim admin-editable |
| 30 | 15 | SARA 313 block never renders | **high** | Fix renderer keys (`reportable`, `threshold_pct`); add "none" line; fix smoke test |
| 31 | 15 | State Regulations hidden under Prop 65 | medium | Always print override; drop dead fallback |
| 32 | 16 | Revision date / version / revision note | medium | Print version, effective date, change_summary, formula version; per-language date |
| 33 | 16 | Abbreviations | medium | Master table filtered to terms used |
| 34 | 16 | Legal disclaimer | medium | Per-language setting with translation fallback; seed default; private-label option |
| 35 | X | UV acrylate rule pack never rendered | medium | Render (translated) or remove |
| 36 | X | Override editor freezes defaults | **high** | Pre-fill from overrides; blank = auto; reset-to-auto; cleanup pass |
| 37 | X | Localization gaps | medium | Decide if non-EN is real; then route all strings through translations |
| 38 | X | Preview / PDF parity | medium | Shared label map + skip rules; consider PDF-based preview |
| 39 | X | Static labels / banners / document strings | low | Leave as constant; de-duplicate fallbacks |
| 40 | X | Dead settings / keys / seed mismatches | low | Housekeeping; implement missing `generateToFile()` |
| 41 | X | PDF furniture (metadata, TCPDF line, page-2 band) | low | Tidy |
| 42 | X | Computed-but-unrendered payloads | low | Surface or stop computing |

## What is genuinely data-driven (so the audit can be trusted)

- **S1** product code + description (FG / CMS Item, alias customer_code + description); private-label company block from `manufacturers`.
- **S2** signal word, pictograms, class lines, H/P codes, PPE presence — `HazardEngine::classify` over hazard_classifications + hazard_source_records, CPD determinations, trade-secret manual JSON, GHS summation/ATE/aquatic rules, per-FG hazard override, carcinogen registry. Statement wording from GHSStatements regulatory tables (intentionally overwrites vendor text).
- **S3** rows from `raw_material_constituents` via `Formula::getExpandedComposition`, filtered by hazardous_cas / exposure_limits / CPD and the carbon-black / powder-in-liquid post-filters; banded ranges; per-CAS H-codes; trade-secret buckets.
- **S4–7, 10, 12, 13** branch *selection* is hazard-driven (`extractHCodes`; S5/S13 also direct-line flash point; S6 FG physical_state) — the *text* is canned.
- **S8** OEL table from `exposure_limits` + CPD + trade-secret JSON per composition CAS; PPE tiers from `derivePPE`.
- **S9** flash point (lowest RM, recursive, `>` flag), solubility, mixture SG, VOC lb/gal, VOC less W&E, VOC wt%, solids wt%/vol% — `FormulaCalcService` + `VOCCalculator`; physical state / color / appearance from FG master data.
- **S11** carcinogen summary + per-component IARC/NTP/OSHA listings and exposure limits.
- **S14** UN/PSN/class/PG from `dot_transport_info` for the first composition CAS with a UN number (pure-substance proxy, see #27).
- **S15** HAP table, SNUR bullets, Prop 65 names (trace threshold, inhalation-only CAS settings).
- Footer product code; PDF Title metadata.

## Findings by section

### Section 1 — Identification
- **#1 Manufacturer block** (`SDSGenerator.php:577-582`, `:1358-1388`; `PDFService.php:245-251`). Admin settings with config.php placeholder fallback for missing *or blank* keys. Correctly constant. Defects: email/website generated but not in PDF; `company.country`/`fax` never rendered; stray commas in address; PDF Author reads config not DB. → Leave as setting; one tidy pass.
- **#2 Private-label emergency phone** (`SDSGenerator.php:508`; `Manufacturer.php:149`). `??` never falls back because `toCompanyInfo` returns `''`; empty line is dropped silently. App D requires it. → Fix fallback + publish warning.
- **#3 Recommended Use / Restrictions** (`SDSGenerator.php:575-576`; `FinishedGoodController.php:405-415`; `CMSImportService.php:384-387`). Chain override → FG column → canned "Printing ink for commercial and industrial applications." The admin defaults only pre-fill the manual FG form; CMS-imported FGs and all resale-RM SDSs (`:299-300`) print the ink sentence. Product Family is also never set by import (and gates the UV rule pack). → Family-level defaults + per-FG override, resale-RM default, derive family on import.
- Static: banner, 8 field labels, "Manufacturer / Supplier Information" sub-heading (PDF only), document title, logo — constants (#39).

### Section 2 — Hazard(s) Identification
- **#4 Other hazards / HNOC** (`SDSGenerator.php:597-617`; `PDFService.php:435-439`). "None known." is assigned but only rendered when an override exists; no unknown-acute-toxicity statement anywhere. → Render default; engine emits unknown-ATE %.
- **#5 Carcinogen P-codes** (`SDSGenerator.php:1739, 2198`). Hardcoded list incl. withdrawn P281, duplicated. → Move to class defaults, drop P281.
- **#15 PPE sentences** — see Section 8.
- Static/constant and fine: "Not a hazardous substance or mixture.", group headings, pictogram captions, signal-word colours (hardcoded twice), untranslated literal "None" (#37).

### Section 3 — Composition
- **#6 Type: Mixture** (`SDSGenerator.php:774`). No Substance branch; wrong for single-substance resale RMs. → Derive.
- **#7 Three canned notes** (`PDFService.php:582-585, 622-632`; `SDSGenerator.php:776-778`). Correct by regulation (trade-secret statement cites 1910.1200(i)); preview omits all three; "only hazardous ingredients" is imprecise (OEL-only constituents are also listed). → Keep; add to preview; reword.
- **#8 Threshold + bands** (`SDSGenerator.php:709, 1436-1487`). Policy constants; exact % never shown — but the Section 8 PDF Conc% column prints it (`PDFService.php:656`). → Keep policy, fix the leak.
- Also noted: chemical-name precedence prop65_list > cas_master > RM (`Formula.php:290`) can substitute Prop 65 wording for the operator's name; FG hazard-override H-codes are never attributed to any row (S2/S3 can disagree); untranslated "H-Codes"/"TRADE SECRET" literals (#37).

### Section 4 — First-Aid Measures
- **#9 Notes to Physician** (`SDSGenerator.php:840`): pure constant. → Hazard fragments.
- **#10 Four paragraphs** (`SDSGenerator.php:782-842`): canned unless H330/H331, H314/H317, H314/H318, H304/H305/H300/H301; common ink codes (H319, H315, H302, H332, H335) change nothing; no 4(b) symptoms line; comment says "appended", code replaces. → Widen map + derive symptoms from H-statements.
- UV acrylate first-aid note computed but skipped (#35).

### Section 5 — Fire-Fighting Measures
- **#11 Four paragraphs** (`SDSGenerator.php:844-915`). Only RM-derived content is the <23 °C sentence from *direct* lines (sub-FGs ignored, `>` flag ignored, H226 gets nothing); water-reactive products still get "Water spray" as suitable; en.php:46 duplicated sentence; numeric `flash_point_c` leaks into preview. → Key off Flam. Liq. category + `formula_props.flash_point_c`.

### Section 6 — Accidental Release Measures
- **#12 Three paragraphs** (`SDSGenerator.php:917-966`). Personal precautions switch only for cat 1-2 acute tox / H314; "Toxic to aquatic life" printed for H402/H412/H413; containment keyed on hand-entered physical_state (Gas/Gel/blank → absorbent text); no ignition-source language for flammables. → Additive fragments; fix aquatic wording.

### Section 7 — Handling and Storage
- **#13 Two paragraphs** (`SDSGenerator.php:968-1020`). First-match chains, asymmetric (self-reactive vs self-heating), H251 treated as pyrophoric, fire wording on every non-flammable SDS, Section 10 incompatibles not reused. → Additive composition.

### Section 8 — Exposure Controls / Personal Protection
- **#14 Engineering Controls** (`SDSGenerator.php:1030`): the clearest pure prefill. → Fragments for powders, flammables, corrosives.
- **#15 PPE sentences** (`HazardEngine.php:1041-1155`; `SDSGenerator.php:591-594, 1031-1034`): hardcoded English, untranslated, precedence inverted between S2 and S8, P280 fallback duplicates defaults, respirator recommended for unclassified products, S2 sentences preview-only. → derivePPE returns keys; S2 consumes S8 values.
- OEL table is data-driven; no "no OELs established" sentence when empty; Conc% column leaks exact % (#8).

### Section 9 — Physical and Chemical Properties
- **#16 Boiling Point** always "Not determined" (`:1090`). → Add RM data.
- **#17 Odor** always blank (`:1089`); RM odor/appearance unused. → Derive, fallback "Not determined".
- **#18 Hidden defaults** (`VOCCalculator.php:31, 243-255, 584-604`): SG=1.0, VOC=0, solids back-calc; assumptions never printed; `voc_assumptions` dropped by renderers. → Mark estimated / "Not determined"; publish warning.
- Also: Appearance duplicates State+Color; enum values untranslated (#37); pH, melting point, vapor pressure, evaporation rate, flammability limits, auto-ignition, viscosity, partition coefficient are absent entirely (see Gaps); `sds.voc_calc_mode` dead (#40).

### Section 10 — Stability and Reactivity
- **#19 All five** (`SDSGenerator.php:1104-1152`). Reactivity/Stability/Decomposition constants (decomposition N/S/halogen keys exist but unused — unfinished feature); Conditions/Incompatibles vary for a handful of physical-hazard codes; oxidizers ignored for Conditions; UV "protect from light" belongs here but only lives in the dead UV note. → Branch + element flags + UV condition.

### Section 11 — Toxicological Information
- **#20 Acute Toxicity** (`:1208`): "criteria are not met" on every SDS incl. H302/H312/H332 products; `hazard_classes` passed in but unused and unrendered. → Derive + ATE.
- **#21 Chronic Effects** (`:1209`): defatting sentence on every SDS; ignores H317 (UV acrylates), H334, H372/H373, CMR. → Health-class fragments.
- **#22 Carcinogenicity** (`:1167, 1198-1204, 1925-1928`; `CarcinogenService.php:104-121`): negative default fine; positive summary hardcoded English ×3; 0.1% vs 0.01% thresholds; description/component_texts unrendered. → One builder; align thresholds.
- Section currently shows OELs (Section 8 data) rather than LD50/LC50/ATE endpoints.

### Section 12 — Ecological Information
- **#23 Ecotoxicity** (`:1219-1242`): canned; chronic and acute+chronic strings identical; severity misstated. → Echo resolved H-statements + component aquatic table.
- **#24 Persistence / Bioaccumulation** (`:1247-1248`): "No data available." → Leave; PBT line later.
- **#25 Note** → see 12-15 notes.

### Section 13 — Disposal Considerations
- **#26 Disposal Methods** (`:1253-1298`): single-pick canned, D002 inferred from H314, D004-D043/F/U/P not consulted, direct-line flash point only. → List all; CAS-based lookup; recursive flash point.

### Section 14 — Transport Information
- **#27 Four lines** (`:1300-1313, 1408-1424`): first CAS with a UN number sets the whole mixture; else "Not regulated" ×3 / "Not applicable"; override loses to auto data; edit form bakes "Not regulated" into overrides; no marine pollutant / IATA / IMDG / bulk rows; note is preview-only and says the section is not required (it is). → Derive from product data (UN1210/UN1263/UN1993, PG from flash/BP, Class 8/9 from engine), override wins, "Not determined" blocks publish.

### Section 15 — Regulatory Information
- **#28 OSHA Status** (`:1328`): "classified as hazardous" even when Section 2 says not classified. → Branch on `is_classified`.
- **#29 TSCA Status** (`:1329`): blanket claim, no data consulted (EPA payload has `tsca_listed`). → Constituent flag roll-up; interim admin setting.
- **#30 SARA 313** (`PDFService.php:776-789`; `preview.php:350-362`; `SARA313Service.php:62-96`): renderer reads `listed_chemicals`/`deminimis_pct`, service emits `reportable`/`threshold_pct` → block never prints; smoke test hard-codes the wrong shape. → Fix keys; add "none" line.
- **#31 State Regulations** (`:1317-1321`; `PDFService.php:885-888`): override hidden whenever Prop 65 fires; fallback dead. → Always print override.
- Prop 65 warning templates and "(trace)" suffix are English PHP constants; `rebuildProp65Warning` duplicates `Prop65Service::buildWarningText` (#37). HAP/Prop 65 "none" sentences and headings are correct constants; SNUR/SARA print nothing when absent (inconsistent presentation).

### Section 16 — Other Information + Disclaimer
- **#32 Revision date / version / note** (`:1343-1344`; `PDFService.php:121-122`; `SDSController.php:347-359`): `date('m/d/Y')` at generation; no version, supersedes, change_summary or formula version printed; previews always show today; revision_note is sticky per-FG with an untranslated label. → Plumb `sds_versions` + formula version.
- **#33 Abbreviations** (`:1345`): generic list, wrong coverage, ES/FR truncated. → Filter from master table.
- **#34 Legal disclaimer** (`:1364-1376`; `PDFService.php:962-981`): single unseeded, language-blind admin string; translated `section16.disclaimer` keys dead. → Per-language setting with translation fallback; seed; private-label option.

### Cross-cutting
- **#35 UV acrylate rule pack** (`UVAcrylateRulePack.php:117-150`; `PDFService.php:900`; `preview.php:471`): computed, stored, skipped by both renderers; English-only; disabled by blank family. → Render translated or remove.
- **#36 Override editor** (`edit.php:21-305`; `SDSController.php:195-267`): pre-fills resolved values and saves every non-empty field → one Save freezes all smart logic, flash point, DOT, "Not determined"/"Not regulated" for that FG+language; per-language only; no manufacturer scope; resale RMs have no path. → Pre-fill from overrides, blank = auto, reset-to-auto, cleanup pass.
- **#37 Localization**, **#38 Preview/PDF parity**, **#39 Static labels**, **#40 Dead settings/keys** (incl. missing `PDFService::generateToFile()` called by auto-send), **#41 PDF furniture**, **#42 Unrendered payloads** — see table.

## Structural recommendation

1. **Default-text table** (`sds_default_text`: section, field_key, variant, language, text) editable in admin, seeded from the current translation files, layered as *per-FG override → default-text table → translation file*. Every canned paragraph in Sections 4-13, 15 and 16 moves there; the "variant" column holds the smart-logic fragments (flammable, corrosive, aquatic…), so compliance can tune wording without a deploy.
2. **Additive fragment composition** instead of first-match `elseif` chains (S5, S6, S7, S10, S13), so combined hazards keep all their guidance.
3. **Override editor semantics**: store only operator-typed text; show computed text as a placeholder; add manufacturer scope for private label (ties into request #1).
4. **Fix the five accuracy bugs first** (#2, #28, #30, #27 precedence, #36) — they are small and independent of the table work.

## Appendix A — Refuted claims (sweep thought prefilled; verification found a data path)

- Recommended Use / Restrictions on Use — per-FG `finished_goods` columns + override exist (kept as #3 for the fallback chain only).
- Company logo / company block — admin setting that private-label variants replace per manufacturer.
- SARA 313 block — composition-driven (`SARA313Service`), not prefilled; it is simply never rendered (#30).
- Section 2 "Other Hazards: None known." — not printed at all by default; appears only with an override (#4).
- Section 16 Revision Date / footer "Rev." — runtime `date()` at generation, not a stored constant (#32).
- Legal disclaimer body — admin setting (blank → block omitted), not a code constant (#34).
- Preview "Preview — EN — Generated …" line and Warnings box — preview-only, varies by time and calc warnings.
- All other refutations were language-only or "override exists" technicalities and remain listed as canned defaults above.

## Appendix B — Possibly missed by the sweep

- **Absent regulatory sub-elements** (not prefilled — missing): S2 unknown-acute-toxicity %, S4 4(b) symptoms / 4(c) medical attention, S9 pH, melting/freezing point, evaporation rate, flammability limits, vapor pressure/density, auto-ignition, decomposition temperature, viscosity, partition coefficient (App D expects each listed or "not available"), S11 routes of exposure / symptoms / numeric ATE, S12 mobility / PBT / other adverse effects, S14 environmental hazards / bulk transport / special precautions, S8 "no OELs established" sentence.
- Section 8 PDF Conc% column prints exact formula % (undoes S3 banding).
- FG hazard-override H-codes (cas='FG_OVERRIDE') never attributed to S3 rows; all trade-secret buckets share one pooled H-code set.
- Chemical-name precedence prop65_list > cas_master > RM constituent (`Formula.php:290`).
- `strtoupper()` on accented banners (ASCII-only on PHP 8); Letter/Helvetica for all languages.
- `ReportController.php:1343-1347` alias PDF drops the description from the product identifier and writes a dead `product_name` key.
- `SDSAutoSendService.php:286` ignores the DB `sds.missing_threshold_pct`; `generateToFile()` does not exist (latent fatal on auto-send / send-queue paths).
- Dead translation keys: `section16.disclaimer`, `section2.other_hazards`, `section10.decomposition_*`, `labels.hazard_statements`, `labels.health_hazard`, `labels.revision_note`; dead seed keys in `seeds/seed.php:269-284`.

## Open decision questions

1. Are ES/FR/DE SDSs a real deliverable? (Determines whether #37 and per-language defaults are worth doing now.)
2. Should canned defaults live in an admin-editable table (recommended) or stay in translation files with per-FG overrides only?
3. Section 14: derive a true mixture transport classification (UN1210 etc.) from product data, or require an explicit per-FG/family determination? Should "Not determined" block publish?
4. Concentration display policy: keep prescribed ranges everywhere (and band Section 8), or allow exact % when no trade-secret claim exists?
5. Section 3 Substance vs Mixture threshold for resale RMs (99%? impurity handling?).
6. Keep or drop the "not required by OSHA" notes in 12-15 (one toggle)?
7. Hidden numeric defaults: print "Not determined", append "(estimated)", or block publish when SG/VOC inputs are missing?
8. Private label (request #1): should manufacturer-specific aliases also carry manufacturer-specific recommended use / restrictions, disclaimer, and text overrides, or inherit the base FG's?
9. UV acrylate rule pack: render it (needs family populated on CMS import) or remove it?
10. Override cleanup: run a one-off pass deleting `text_overrides` rows that equal the currently generated default before changing the editor semantics?

---

## Appendix C — Options and trade-offs per item
_Same numbering as the summary table. 'Recommended' is the synthesis agent's pick; nothing is decided._

### #1 — Section 1 — Identification: Manufacturer / supplier block (Company, Address, Phone, Email, Website, logo)
- **Concern:** low
- **Current behaviour:** settings company.* via getCompanySettings() (SDSGenerator.php:1358-1388) with config.php placeholder fallback for any missing OR blank key; rendered PDFService.php:245-251. Identical on every standard SDS; private-label swaps in manufacturers.* (SDSGenerator.php:494-518). email/website generated (:581-582) but never drawn in the PDF; company.country and company.fax collected (settings.php:16,18) but never rendered; address concat (:578) leaves stray commas when city/state blank; PDF Author metadata reads config.php not the setting (PDFService.php:104,161).
- **Where:** `src/Services/SDSGenerator.php:577-582, 1358-1388, 494-518; src/Services/PDFService.php:245-251, 104`
- **Why it matters:** Supplier identity is correctly a global setting (App D 1(c)/(d)); only the parity/tidiness defects matter.
- **Options:**
  - Leave as admin setting; add email/website to the PDF, include country in the address, drop fax or render it, use the DB name for PDF Author — _Small code change; keeps one source of truth._
  - Per-language company block — _Unnecessary for a US company; address does not translate._
- **Recommended:** Leave as constant; fix the parity/tidiness items in one pass (email/website in PDF, country in address, DB name for PDF Author).

### #2 — Section 1 — Identification: Emergency phone on private-label variants
- **Concern:** high
- **Current behaviour:** createManufacturerVariant uses `$manufacturerInfo['emergency_phone'] ?? existing` (SDSGenerator.php:508) but Manufacturer::toCompanyInfo always returns '' (Manufacturer.php:149; column NOT NULL DEFAULT ''), so the fallback to the company CHEMTREC number never fires and labelValue() drops the empty line (PDFService.php:926).
- **Where:** `src/Services/SDSGenerator.php:508; src/Models/Manufacturer.php:149`
- **Why it matters:** 29 CFR 1910.1200 App D requires an emergency phone number in Section 1; a manufacturer record with a blank number yields a PDF with no emergency line and no warning.
- **Options:**
  - Fix the fallback (`!== ''` check) so blank manufacturer numbers inherit the company emergency number — _One-line fix; assumes the base company's CHEMTREC contract covers private-label product, which is usually true._
  - Make emergency_phone required on the manufacturer form and block private-label publish when blank — _Stronger guarantee; adds a validation step for existing manufacturers._
- **Recommended:** Do both: fix the fallback now, and add a publish-gate warning for blank manufacturer emergency numbers as part of the private-label work.

### #3 — Section 1 — Identification: Recommended Use / Restrictions on Use fallback chain (and Product Family)
- **Concern:** high
- **Current behaviour:** override -> finished_goods column -> canned translation 'Printing ink for commercial and industrial applications.' / 'For professional/industrial use only. Not for household consumer use.' (SDSGenerator.php:575-576, en.php:8-9). The admin settings sds.default_recommended_use / sds.default_restrictions_on_use are read only by the FG create form (FinishedGoodController.php:405-415), never at generation, so every CMS-imported FG (CMSImportService.php:384-387 sets only code/description) and every resale-RM SDS (:299-300 force null) prints the ink sentence. Product Family is likewise never set by import (blank family also silently disables the UV acrylate rule pack gate, :132).
- **Where:** `src/Services/SDSGenerator.php:575-576, 299-300; src/Controllers/FinishedGoodController.php:405-415; src/Services/CMSImportService.php:384-387`
- **Why it matters:** Verification shows a per-FG data path exists, but in practice CMS-imported FGs land on the canned text, and 'Printing ink...' is simply wrong on a resale raw material (e.g. a solvent) SDS.
- **Options:**
  - Resolve at generation time: override -> FG column -> admin setting -> translation, and apply the admin defaults during CMS import — _Admin setting finally governs the catalog; still one default for all families._
  - Family-level defaults (sds.product_families carries use/restriction text) + per-FG override; resale RMs get an RM-type default ('Raw material for industrial formulation') — _More accurate per product line; needs a small family table/UI and a family on every FG (import must map CMS category to family)._
  - Leave as is — _Keeps printing ink wording on non-ink SDSs._
- **Recommended:** Option 2: family-level defaults with per-FG override, plus a distinct resale-RM default; derive family from CMS data on import so both this and the UV rule pack gate work.

### #4 — Section 2 — Hazard(s) Identification: Other Hazards / HNOC line (and 'unknown acute toxicity' statement)
- **Concern:** high
- **Current behaviour:** section2() sets other_hazards to 'None known.' but has_other_hazards is true only when a per-FG override exists (SDSGenerator.php:597,615-616); both renderers gate on that flag (PDFService.php:436, preview.php:192), so nothing is printed by default and the translation key is dead. No 'x% of the mixture consists of ingredient(s) of unknown acute toxicity' statement is generated anywhere.
- **Where:** `src/Services/SDSGenerator.php:597-617; src/Services/PDFService.php:435-439`
- **Why it matters:** App D 2(c) requires 'other hazards which do not result in classification' (HNOC) and 2(d) the unknown-acute-toxicity percentage where applicable; the sheet currently emits neither.
- **Options:**
  - Always print 'Other hazards: None known.' unless overridden, and have HazardEngine emit the unknown-ATE % from components lacking acute-tox data — _Closes the compliance gap; the ATE-unknown logic needs the engine to track which components had no acute data._
  - Derive HNOC text from product data (combustible dust for powders, static discharge for low-conductivity solvents, EUH066 defatting) with 'None known.' fallback — _More useful; requires a small rule table and physical-state input._
- **Recommended:** Option 1 immediately (render the default + unknown-ATE statement), then grow a small HNOC rule table for powders/solvent inks.

### #5 — Section 2 — Hazard(s) Identification: Carcinogen-triggered P-statement list
- **Concern:** medium
- **Current behaviour:** Hardcoded ['P201','P202','P281','P308+P313','P405','P501'] appended whenever the carcinogen registry fires (SDSGenerator.php:1739, 2198). P281 was withdrawn from GHS in Rev. 6 (merged into P280); OSHA's 2024 HazCom update aligns to Rev. 7.
- **Where:** `src/Services/SDSGenerator.php:1739, 2198`
- **Why it matters:** Constant list duplicated in two places and includes a code that is withdrawn under the revision OSHA now targets.
- **Options:**
  - Move the list into GHSHazardData::HAZARD_CLASSIFICATIONS for the Carcinogenicity class (where every other class's default P-codes live) and drop P281 — _Single source of truth; no behaviour change other than P281._
  - Leave as is — _Keeps a withdrawn code on every carcinogen-bearing SDS._
- **Recommended:** Option 1.

### #6 — Section 3 — Composition: Type: 'Mixture'
- **Concern:** high
- **Current behaviour:** section3() unconditionally sets substance_or_mixture = labels.mixture (SDSGenerator.php:774); no 'Substance' branch exists, so resale raw-material SDSs built by generateForResaleRawMaterial (:368) with one CAS at ~100% still say Mixture.
- **Where:** `src/Services/SDSGenerator.php:774`
- **Why it matters:** Substance vs mixture determines what Section 3 must contain; wrong on single-substance resale products.
- **Options:**
  - Derive: resale RM with a single non-trade-secret constituent >= ~99%, or FG composition with one CAS, -> 'Substance'; add labels.substance — _Simple heuristic; needs a threshold decision for impurities._
  - Add an explicit substance/mixture flag on raw_materials for the resale path — _Explicit and auditable; one more field to maintain._
- **Recommended:** Option 1 with the threshold exposed as a constant; FG products stay 'Mixture'.

### #7 — Section 3 — Composition: Canned notes: hazardous-only disclosure, 'no hazardous ingredients', trade-secret withholding statement
- **Concern:** medium
- **Current behaviour:** labels.hazardous_only_note and labels.no_hazardous_note printed directly by PDFService.php:582-585 / 622-625; section3.trade_secret_note (with embedded '29 CFR 1910.1200(i)') set only when a trade-secret constituent exists (SDSGenerator.php:776-778). preview.php renders none of the three. The hazardous-only wording is slightly inaccurate because OEL-only constituents are also disclosed (:621-633).
- **Where:** `src/Services/PDFService.php:582-585, 622-632; src/Services/SDSGenerator.php:776-778`
- **Why it matters:** Correct constants by regulation (the trade-secret statement is required by 1910.1200(i)); the only issues are preview/PDF mismatch and the imprecise 'only hazardous' wording.
- **Options:**
  - Leave as translation constants; add all three to preview.php; reword hazardous-only note to 'Hazardous ingredients and ingredients with an occupational exposure limit are listed' — _Minimal change._
  - Move to admin-editable default text — _Not needed for US-only; regulatory citation should not be casually editable._
- **Recommended:** Option 1.

### #8 — Section 3 — Composition: Disclosure threshold (0.1%) and prescribed-range band table
- **Concern:** medium
- **Current behaviour:** Row cut-off `$conc < 0.1` hardcoded (SDSGenerator.php:709); PRESCRIBED_RANGES constant (:1436-1439) with 'widest band containing the value' policy; exact percentages never shown even when no trade-secret claim exists; Section 8 PDF Conc% column prints the exact value anyway (PDFService.php:656,673).
- **Where:** `src/Services/SDSGenerator.php:709, 1436-1487; src/Services/PDFService.php:656`
- **Why it matters:** Deliberate policy choices that are not documented or configurable, and the Section 8 column undoes the Section 3 obfuscation.
- **Options:**
  - Keep constants; document the policy; band (or drop) the Section 8 Conc% column — _No new UI; fixes the leak._
  - Admin setting sds.concentration_display = exact \| prescribed_ranges and sds.disclosure_threshold_pct — _Flexibility for customer requests; more settings to govern._
- **Recommended:** Option 1 now (fix the Section 8 leak); revisit a setting only if customers ask for exact percentages.

### #9 — Section 4 — First-Aid Measures: Notes to Physician
- **Concern:** medium
- **Current behaviour:** 'Treat symptomatically. Show this SDS to medical personnel.' with no hazard logic (SDSGenerator.php:840, en.php:26); only a per-FG override changes it.
- **Where:** `src/Services/SDSGenerator.php:840`
- **Why it matters:** Acceptable generic statement, but App D 4(c) 'indication of immediate medical attention / special treatment' could be hazard-driven (aspiration, corrosives, methemoglobinemia agents).
- **Options:**
  - Hazard fragments: H304 aspiration warning, H314 'do not neutralise', H330/H331 observation period, else current text — _Same pattern Section 4 already uses; small translation additions._
  - Admin-editable default — _Editable without deploy but still one text for all._
  - Leave as constant — _Fine for most inks._
- **Recommended:** Option 1, bundled with the first-aid map widening below.

### #10 — Section 4 — First-Aid Measures: Inhalation / Skin / Eye / Ingestion default paragraphs
- **Concern:** medium
- **Current behaviour:** Canned en.php:22-25 unless H330/H331, H314/H317, H314/H318, H304/H305/H300/H301 are present (SDSGenerator.php:784-832). H319, H315, H302, H332, H335/H336, H310/H311 (all common in ink classifications) do not change the text, so most of the catalog prints the same four paragraphs. The en.php:27 comment says fragments are 'appended' but code replaces. No 4(b) 'most important symptoms/effects' line exists.
- **Where:** `src/Services/SDSGenerator.php:782-842; templates/translations/en.php:22-35`
- **Why it matters:** Text is not wrong, but the 'smart' logic rarely fires for real ink hazards and the required symptoms sub-element is absent.
- **Options:**
  - Widen the H-code map (H319 contact-lens removal, H302 poison-center, H315, H332/H335, H334) and add a 4(b) symptoms line derived from the H-statements already on the hazard result — _Modest; reuses existing translation pattern; symptoms line is nearly free._
  - Drive first-aid text from the P3xx response statements the engine already resolves — _Fully regulatory wording; less readable than prose._
  - Move all defaults to an admin-editable per-language table — _Editable without deploy; does not make it product-specific._
- **Recommended:** Option 1, plus the admin-editable default table as the home for the base sentences.

### #11 — Section 5 — Fire-Fighting Measures: Suitable / Unsuitable media, Specific hazards, Advice for firefighters
- **Concern:** medium
- **Current behaviour:** Canned en.php:39-42 switched only by H271/H272, H260/H261, H240-242, H200-205 (SDSGenerator.php:854-905). The only RM-derived content is the appended 'Highly flammable liquid and vapor' sentence when the lowest DIRECT formula-line flash point is < 23 C (:846-852, :891-894) — sub-FG lines are not walked (Section 9 uses the recursive formula_props value), the '>' flag is ignored, and H226 (23-60 C) products get no flammability sentence. A water-reactive product still gets 'Water spray' as suitable media. en.php:46 has a duplicated oxidizer sentence; the numeric flash_point_c leaks into the HTML preview only.
- **Where:** `src/Services/SDSGenerator.php:844-915; templates/translations/en.php:37-50`
- **Why it matters:** Generic text is defensible, but Sections 5 and 9 can disagree on flash point and Cat 3 flammable inks get no fire-specific language.
- **Options:**
  - Key Section 5 off the engine's Flam. Liq. category (H224/H225/H226) and use formula_props.flash_point_c; make the water-reactive branch also override suitable media; fix en.php:46 — _Consistent with Sections 2 and 9; small change._
  - Leave constants; only fix the flash-point source — _Minimal, still no H226 language._
- **Recommended:** Option 1, with the base paragraphs living in the admin-editable default table.

### #12 — Section 6 — Accidental Release Measures: Personal precautions, Environmental precautions, Containment
- **Concern:** medium
- **Current behaviour:** Canned en.php:53-61: personal precautions switch only on H300/H310/H330 or H314 (SDSGenerator.php:927-936); environmental says 'Toxic to aquatic life' for any H4xx including H402/H412/H413 'harmful' (:939-946); containment keyed on FG physical_state with Gas/Gel/blank/custom states falling to the generic absorbent text (:949-958). No ignition-source language for flammables.
- **Where:** `src/Services/SDSGenerator.php:917-966; templates/translations/en.php:51-62`
- **Why it matters:** Mostly identical boilerplate catalog-wide; the aquatic sentence overstates H412/H413 classifications; flammable inks lack spill ignition guidance.
- **Options:**
  - Additive fragments: flammable -> ignition/non-sparking; aquatic acute vs chronic/harmful wording split; derive physical state from RM data with FG override; map Gas/Gel explicitly — _More accurate; a few new translation keys._
  - Leave constants, fix only the aquatic overstatement — _Smallest change._
- **Recommended:** Option 1 (fragments), at minimum fix the aquatic wording.

### #13 — Section 7 — Handling and Storage: Handling and Storage paragraphs
- **Concern:** medium
- **Current behaviour:** Six canned variants each, first-match chain pyrophoric > water-reactive > self-reactive/self-heating > oxidizer > flammable > default (SDSGenerator.php:968-1020). Default handling text says 'Keep away from heat, sparks, and open flame' on every non-flammable SDS; chains are asymmetric (handling tests self-reactive, storage tests self-heating) and H251 is treated as pyrophoric; storage says 'away from incompatible materials' without naming Section 10's value; ES/FR defaults are shorter than EN/DE.
- **Where:** `src/Services/SDSGenerator.php:968-1020; templates/translations/en.php:63-78`
- **Why it matters:** Boilerplate for the bulk of the catalog; combined hazards lose guidance; a few logic slips.
- **Options:**
  - Compose fragments additively (flammable + corrosive both appear), fix H251, add a non-flammable default without fire wording, reuse Section 10 incompatibles — _Better accuracy; slightly longer paragraphs._
  - Leave as is — _Fire wording on aqueous inks._
- **Recommended:** Option 1.

### #14 — Section 8 — Exposure Controls: Engineering Controls
- **Concern:** medium
- **Current behaviour:** One canned sentence (SDSGenerator.php:1030, en.php:81) with zero hazard/physical-state logic; identical on every SDS unless overridden.
- **Where:** `src/Services/SDSGenerator.php:1030`
- **Why it matters:** The clearest pure prefill on the sheet; powders (dust), flammables (explosion-proof exhaust/grounding) and corrosives (eyewash/shower) warrant different controls.
- **Options:**
  - Hazard/state fragments: powder -> dust control; H224-226 -> explosion-proof ventilation & bonding; H314/H318 -> eyewash and safety shower; else current sentence — _Same pattern as other sections; needs physical state input._
  - Admin-editable default only — _Editable, still generic._
- **Recommended:** Option 1.

### #15 — Section 2 / 8 — PPE: PPE recommendation sentences (respiratory, hand, eye, skin)
- **Concern:** medium
- **Current behaviour:** HazardEngine::derivePPE (HazardEngine.php:1041-1155) returns ~12 hardcoded English sentences keyed on H3xx/P280; nothing translates them (translateHazardResult skips ppe_recommendations), so ES/FR/DE sheets mix English with translated defaults. Section 8 precedence is override ?? derived ?? default (SDSGenerator.php:1031-1034) while Section 2 is derived ?? override (:591-594), so one SDS can show two PPE statements. The P280 fallback strings duplicate the en.php defaults. For unclassified products the defaults still recommend respirator/gloves/goggles. Section 2 sentences show in preview but not the PDF.
- **Where:** `src/Services/HazardEngine.php:1041-1155; src/Services/SDSGenerator.php:591-594, 1031-1034`
- **Why it matters:** Hazard-keyed but hardcoded in PHP, untranslated, inconsistent between sections, and over-prescriptive for non-hazardous products.
- **Options:**
  - derivePPE returns translation KEYS; sentences move to templates (or the default-text table); section2() consumes the resolved Section 8 values; add a 'no special PPE required under normal use' tier when no H-codes — _Clean fix; touches engine output shape (snapshot_json consumers should be checked)._
  - Leave derivePPE; just unify precedence — _Keeps English-only strings._
- **Recommended:** Option 1.

### #16 — Section 9 — Physical and Chemical Properties: Boiling Point
- **Concern:** medium
- **Current behaviour:** Always labels.not_determined unless a per-FG override exists (SDSGenerator.php:1090); raw_materials has no boiling-point column, so no data path exists.
- **Where:** `src/Services/SDSGenerator.php:1090`
- **Why it matters:** App D requires the property (a 'not available' statement is acceptable), but RM boiling points are easy to capture and would make the line meaningful for solvent inks.
- **Options:**
  - Add boiling_point_c (+ '>' flag) to raw_materials and derive the lowest value like flash point — _Data entry for solvent RMs; most pigments/resins stay blank._
  - Keep 'Not determined' as an explicit, documented choice — _No work; weak line on solvent products._
- **Recommended:** Option 1, treating 'Not determined' as the fallback when no RM carries data.

### #17 — Section 9 — Physical and Chemical Properties: Odor (and unused RM appearance/odor)
- **Concern:** medium
- **Current behaviour:** 'odor' => $overrides[9]['odor'] ?? '' (SDSGenerator.php:1089): blank on every SDS unless typed, and the empty line is dropped entirely by both renderers. raw_materials.odor and .appearance exist (RawMaterial.php:319, form.php:337-344) but are never read.
- **Where:** `src/Services/SDSGenerator.php:1089; src/Models/RawMaterial.php:319`
- **Why it matters:** App D lists odor; omitting the line silently is worse than 'Not determined', and the data already exists.
- **Options:**
  - Derive from the dominant-by-weight RM odor (or the odor of the lowest-flash solvent), fallback 'Not determined' — _Uses existing data; heuristic may need a per-FG override._
  - Fallback to 'Not determined' only — _Trivial; closes the omission._
- **Recommended:** Option 1 with 'Not determined' fallback.

### #18 — Section 9 — Physical and Chemical Properties: Hidden numeric defaults (SG = 1.0, VOC = 0, solids back-calculated)
- **Concern:** high
- **Current behaviour:** VOCCalculator substitutes DEFAULT_SG = 1.0 for any RM lacking specific gravity (VOCCalculator.php:31, 243-255, 584-588), VOC = 0 for RMs with no VOC data, and back-fills solids_wt from 100 - VOC - exempt - water; the assumptions list is logged (addAssumption) but never printed, so Specific Gravity / VOC / Solids always show a number and the 'Not determined' branches are effectively unreachable. voc_assumptions is put in Section 16 but dropped by both renderers.
- **Where:** `src/Services/VOCCalculator.php:31, 243-255, 584-604; src/Services/SDSGenerator.php:1093-1097, 1346`
- **Why it matters:** A formula whose RMs lack SG/VOC data prints 'Specific Gravity: 1' and 'VOC: 0' as if measured; VOC lb/gal is a regulatory number.
- **Options:**
  - When any line used a default, print 'Not determined' (or append '(estimated)') and surface the assumptions as a 'Basis of calculation' note in Section 16 or as a publish warning — _Honest output; some SDSs will show fewer numbers until RM data is completed._
  - Block publish when VOC/SG inputs are missing above a threshold (like sds.missing_threshold_pct) — _Forces data completion; may stall publishing._
- **Recommended:** Option 1 now, with a publish warning (not block) listing the RMs that were defaulted.

### #19 — Section 10 — Stability and Reactivity: All five paragraphs (Reactivity, Stability, Conditions to Avoid, Incompatible Materials, Decomposition)
- **Concern:** medium
- **Current behaviour:** Reactivity, Stability and Decomposition are pure constants (SDSGenerator.php:1151-1152, 1142-1147; en.php:92-96) — the comment at :1143 promises N/S/halogen detection and decomposition_nitrogen/_sulfur/_halogen exist in all four languages but are never used. Conditions/Incompatibles vary only for H250/H260-261/H240-242/H271-272/H220-228 (:1104-1140); default text names 'sparks, open flames' on non-flammable products; oxidizers do not change Conditions to Avoid; no CAS/chemistry input (acids, amines, isocyanates, acrylates). 'Stable' is printed even on self-reactive/water-reactive products.
- **Where:** `src/Services/SDSGenerator.php:1104-1152; templates/translations/en.php:90-108`
- **Why it matters:** Constant text that can contradict Section 2 for reactive classes (rare in inks) and ignores the UV acrylate 'avoid UV light / premature polymerization' condition that belongs here.
- **Options:**
  - Branch Reactivity/Stability on H240-242/H250/H260-261; implement the decomposition element logic from constituent names/CAS (N, S, halogen flags on cas_master); add UV-family 'protect from UV/sunlight' to Conditions to Avoid; make chains additive — _Completes an unfinished feature; needs element flags on CAS master._
  - Leave constants; delete the dead decomposition keys — _Simplest; keeps generic text._
- **Recommended:** Option 1 (element flags are cheap to seed from chemical names), defaults in the admin-editable table.

### #20 — Section 11 — Toxicological Information: Acute Toxicity
- **Concern:** high
- **Current behaviour:** 'Based on available data, the classification criteria are not met.' on every SDS (SDSGenerator.php:1208, en.php:111) regardless of H300-H332 codes; section11() receives hazard_classes but never reads it and the key is not rendered (:1211; preview.php:471 skips it). ATE values the engine computes are not surfaced.
- **Where:** `src/Services/SDSGenerator.php:1208-1211`
- **Why it matters:** Directly contradicts Section 2 on any product classified Acute Tox. (H302/H312/H332 are common with ink solvents).
- **Options:**
  - Derive from hazard_classes: list acute-tox classes/categories with route and the mixture ATE (oral/dermal/inhalation) the engine already computes; fall back to the current sentence only when no acute class triggers — _Data already in scope; needs translation keys per route._
  - Admin-editable sentence only — _Still contradicts Section 2._
- **Recommended:** Option 1.

### #21 — Section 11 — Toxicological Information: Chronic Effects
- **Concern:** high
- **Current behaviour:** 'Prolonged or repeated exposure may cause skin drying or cracking.' (EUH066-style) on every SDS (SDSGenerator.php:1209, en.php:112), ignoring H317/H334 sensitisation, H372/H373 STOT RE, H340/H341, H350/H351, H360/H361 even when present.
- **Where:** `src/Services/SDSGenerator.php:1209`
- **Why it matters:** UV acrylate inks are H317 skin sensitizers and the sheet says nothing about it here; the defatting sentence is printed on powders and aqueous products where it is irrelevant.
- **Options:**
  - Hazard fragments per health class (sensitisation, STOT RE with target organs, mutagenicity, reproductive, carcinogenicity cross-ref) with 'No known chronic effects' fallback; keep the defatting line only when a solvent/EUH066 trigger exists — _Same pattern as other sections; the 'health_hazard' label already exists._
  - Leave as constant — _Incomplete for UV and STOT products._
- **Recommended:** Option 1.

### #22 — Section 11 — Toxicological Information: Carcinogenicity negative default and hardcoded positive summary
- **Concern:** medium
- **Current behaviour:** Negative case = translation constant 'No components are listed as carcinogens by IARC, NTP, or OSHA.' (en.php:113, fine). Positive summary text is hardcoded English in three places (CarcinogenService.php:107/118, SDSGenerator.php:1925-1928, removeInhalationOnlyFromResults ~:1990). Component inclusion thresholds disagree: section11() uses 0.1% (:1167) vs CarcinogenService/HazardEngine 0.01%, so a carcinogen between 0.01-0.1% appears in the paragraph but not the component block. carcinogen_list.description and component_texts are computed but never rendered.
- **Where:** `src/Services/SDSGenerator.php:1167, 1198-1204, 1925-1928; src/Services/CarcinogenService.php:104-121`
- **Why it matters:** The default is correct by regulation; the duplication and threshold mismatch are maintenance/consistency risks.
- **Options:**
  - One shared builder reading translation keys; align thresholds (single constant); optionally render carcinogen_list.description — _Small refactor._
  - Leave as is — _Three copies of wording to keep in sync._
- **Recommended:** Option 1; keep the negative sentence as a constant.

### #23 — Section 12 — Ecological Information: Ecotoxicity
- **Concern:** medium
- **Current behaviour:** Canned 'No data available on the mixture. Avoid release to the environment.' unless any H400-H413 code is present, then one of three paraphrases (SDSGenerator.php:1219-1242; en.php:117-125) — ecotoxicity_chronic and ecotoxicity_acute_chronic are identical in all four languages, and H400/H410 ('very toxic') and H402/H412 ('harmful') all render as 'Toxic'. No component ecotox values are shown although the engine holds per-component aquatic category + M-factor.
- **Where:** `src/Services/SDSGenerator.php:1219-1242; templates/translations/en.php:117-125`
- **Why it matters:** Paraphrase misstates severity; the resolved H-statement text already on the hazard result would be exact.
- **Options:**
  - Echo the actual translated aquatic H-statement(s) and add a component aquatic table (category, M-factor) like Section 11's component block — _Accurate and nearly free; table needs a renderer._
  - Leave constants; fix the duplicate/overstated strings — _Minimal._
- **Recommended:** Option 1.

### #24 — Section 12 — Ecological Information: Persistence and Degradability / Bioaccumulative Potential
- **Concern:** low
- **Current behaviour:** 'No data available.' on every SDS (SDSGenerator.php:1247-1248, en.php:118-119); no data path at all. Mobility in soil / PBT / other adverse effects sub-items are absent entirely.
- **Where:** `src/Services/SDSGenerator.php:1247-1248`
- **Why it matters:** 'No data available' is a legitimate statement when nothing is held; sections 12-15 are non-mandatory for OSHA enforcement.
- **Options:**
  - Leave as constant (move to the default-text table with the rest) — _No work._
  - Capture biodegradability / log Kow / BCF in CPD and derive; add a PBT line from sara313_list.is_pbt which already exists — _Real content for EU-style customers; data capture effort._
- **Recommended:** Leave as constant for now; the PBT line from sara313_list.is_pbt is a cheap later win.

### #25 — Section 12-15 — Notes: 'This section is not required by OSHA HazCom but is included per GHS guidelines.' (Sections 12, 13, 14, 15)
- **Concern:** medium
- **Current behaviour:** Four separate translation keys (en.php:120,130,140,146) printed on every SDS; not override-able, no setting. Section 14's version is factually off (Section 14 is a required section; OSHA merely does not enforce 12-15 content), the Section 14 note is preview-only (renderSection14 omits it), and ES/FR/DE wording differs from EN. Sections 12/13 render it as a bold 'Note:' label row, 15 as 7pt italic.
- **Where:** `src/Services/SDSGenerator.php:1249, 1296, 1311, 1335; src/Services/PDFService.php:763-769, 890-894`
- **Why it matters:** Author-facing regulatory commentary on a customer document, inconsistent across sections and languages, wrong for Section 14.
- **Options:**
  - Drop the notes (most commercial SDSs do) — _Cleanest; loses the carrier-verification sentence in 14 (keep that part)._
  - One shared key + admin on/off toggle, rendered in footnote style everywhere; keep 'Verify classification with carrier' as Section 14 text — _Keeps the practice, fixes inconsistency._
- **Recommended:** Option 2 (single toggle, consistent footnote style, correct Section 14 wording).

### #26 — Section 13 — Disposal Considerations: Disposal Methods
- **Concern:** medium
- **Current behaviour:** First-match canned pick: reactive (D003) > corrosive via H314 (D002) > acute-tox H-codes > ignitable if lowest DIRECT-line flash point < 60 C (D001) > aquatic > generic (SDSGenerator.php:1253-1298; en.php:129-136). Only one characteristic is named even when several apply; D002 is inferred from H314 rather than pH; RCRA toxicity characteristic (D004-D043) and F/U/P listings are not consulted; flash point ignores sub-FG components and the '>' flag (same scan as Section 5).
- **Where:** `src/Services/SDSGenerator.php:1253-1298`
- **Why it matters:** Generic sentence is correct; the 'may be classified as' hedges are fine, but the single-pick chain under-reports and the flash point source disagrees with Section 9.
- **Options:**
  - List every applicable characteristic; use formula_props.flash_point_c; add a CAS-based D004-D043 / P / U lookup table (admin-managed like SARA/HAP) — _Accurate waste guidance; a new regulatory list to maintain._
  - Fix flash point source only, keep single pick — _Minimal._
- **Recommended:** Option 1 (the regulatory-list pattern already exists for HAP/SARA/Prop 65).

### #27 — Section 14 — Transport Information: UN Number / Proper Shipping Name / Hazard Class / Packing Group defaults
- **Concern:** high
- **Current behaviour:** getDOTInfo() returns the whole dot_transport_info row of the FIRST composition CAS with a UN number (SDSGenerator.php:1408-1424; static 366-row pure-substance seed, no refresh), else labels.not_regulated x3 / not_applicable (:1302-1310). Precedence is dotInfo ?? override ?? default, so a per-FG override cannot correct a wrong auto-pick (:1307-1310). No use of the engine's Flam. Liq./Skin Corr./Aquatic classes or Section 9 flash/boiling point; no n.o.s. entries (UN1210 Printing ink, UN1993); no marine-pollutant/IATA/IMDG/bulk rows. The edit form pre-fills 'Not regulated' and persists it on save.
- **Where:** `src/Services/SDSGenerator.php:1300-1313, 1408-1424`
- **Why it matters:** A solvent ink is UN1210 Class 3; the sheet either tags it with one ingredient's pure-substance entry or asserts 'Not regulated' from absence of data — a positive regulatory claim made without a determination.
- **Options:**
  - Derive from product data: flash point + boiling point -> Class 3 PG I/II/III and UN1210/UN1263/UN1993 with technical names; Skin Corr. 1 -> Class 8; Aquatic Acute/Chronic 1 -> UN3082/UN3077 + marine pollutant; dot.csv only supplies technical names; override wins over auto — _Real mixture classification; needs a small rules module and review of edge cases (viscous-liquid exception, limited quantity)._
  - Add an explicit FG-level transport determination (fields or family default) with 'Not determined' blocking publish; invert precedence — _Manual but auditable; no false 'Not regulated'._
- **Recommended:** Option 1 with Option 2's 'Not determined blocks publish' as the fallback state and override-wins precedence.

### #28 — Section 15 — Regulatory Information: OSHA Status
- **Concern:** high
- **Current behaviour:** 'This product is classified as hazardous under OSHA HazCom 2012 (29 CFR 1910.1200).' on every SDS (SDSGenerator.php:1328, en.php:144); section15() never sees $isClassified computed at :599-602, so unclassified products say 'classified as hazardous' while Section 2 says 'Not a hazardous substance or mixture'.
- **Where:** `src/Services/SDSGenerator.php:1328, 599-602`
- **Why it matters:** Self-contradicting statement on water-based/unclassified products.
- **Options:**
  - Two translation strings (classified / not classified) selected by the hazard result; override still wins — _Trivial._
  - Admin-editable sentence — _Still not product-aware._
- **Recommended:** Option 1.

### #29 — Section 15 — Regulatory Information: TSCA Status
- **Concern:** high
- **Current behaviour:** 'All components are listed on or exempt from the TSCA inventory.' on every SDS (SDSGenerator.php:1329, en.php:145); no TSCA data is consulted anywhere. EPAConnector stores a tsca_listed flag in hazard_source_records payload_json (EPAConnector.php:95-153) that nothing reads; raw_materials/constituents have no TSCA field.
- **Where:** `src/Services/SDSGenerator.php:1329; src/Services/FederalData/Connectors/EPAConnector.php:95-153`
- **Why it matters:** Unverified compliance assertion printed as fact; inventory status is a legal statement.
- **Options:**
  - Roll up a constituent-level TSCA flag (seeded from the EPA payload, editable on the RM constituent) like HAPs: print 'All components listed/exempt' only when every CAS is flagged, otherwise 'TSCA status not verified for all components' + publish warning — _Real determination; needs the flag populated for the CAS universe._
  - Move the sentence to an admin setting so legal can own the wording — _Editable, still blanket._
- **Recommended:** Option 1, with the admin-editable sentence as the interim.

### #30 — Section 15 — Regulatory Information: SARA 313 / TRI block (never renders)
- **Concern:** high
- **Current behaviour:** SARA313Service::analyse returns 'reportable' / 'below_threshold' / 'not_listed' / 'summary' with 'threshold_pct' (SARA313Service.php:62-96), but PDFService.php:778/782 and preview.php:352/356 test and iterate $sara['listed_chemicals'] (never set) and read 'deminimis_pct' (:785/:359), so the heading and bullets never print; the hardcoded-English 'summary' is unused; no 'none' sentence exists (HAP/Prop 65 always print one). ReportController.php:1485 uses the right key; tests/smoke_pdf.php:194-197 hard-codes the wrong shape.
- **Where:** `src/Services/PDFService.php:776-789; src/Views/sds/preview.php:350-362; src/Services/SARA313Service.php:62-96`
- **Why it matters:** 40 CFR 372.45 supplier notification: toluene, xylene, MEK, glycol ethers etc. are SARA 313 chemicals common in solvent inks and are currently never disclosed on the SDS.
- **Options:**
  - Fix the renderer keys (reportable / threshold_pct), add a translated 'none' sentence, fix the smoke-test fixture — _Bug fix; immediate._
  - Also surface below-threshold components as informational — _Optional; more lines._
- **Recommended:** Option 1 immediately.

### #31 — Section 15 — Regulatory Information: State Regulations override visibility
- **Concern:** medium
- **Current behaviour:** state_regs = override ?? (prop65 warning_text if required) (SDSGenerator.php:1317-1321) but both renderers suppress the line whenever Prop 65 requires a warning (PDFService.php:886, preview.php:429), so the Prop 65 fallback is dead code and an operator-entered state note disappears on every Prop 65 product.
- **Where:** `src/Services/SDSGenerator.php:1317-1321; src/Services/PDFService.php:885-888`
- **Why it matters:** Silent loss of user-entered regulatory text.
- **Options:**
  - Always print the override when present; drop the Prop 65 fallback — _Trivial._
- **Recommended:** Fix as described.

### #32 — Section 16 — Other Information: Revision Date, version number and Revision Note
- **Concern:** medium
- **Current behaviour:** revision_date = date('m/d/Y') at generation (SDSGenerator.php:1343), copied into the every-page footer (PDFService.php:121-122); not read from sds_versions.effective_date/published_at; the version number (sds_versions.version) and publish-time change_summary (SDSController.php:357) are never printed; previews (SDSController.php:58-82, PrivateLabelController.php:440-451) regenerate live so they always show today; format is US m/d/Y in every language. revision_note is a sticky per-FG override (sds_version_id IS NULL) with an untranslated ucwords label because 'revision_note' is missing from FIELD_LABEL_MAP/getLabels.
- **Where:** `src/Services/SDSGenerator.php:1343-1344; src/Services/PDFService.php:121-122; src/Controllers/SDSController.php:347-359`
- **Why it matters:** App D 16 requires date of preparation/last revision — publish-date is acceptable, but no version or supersedes info is shown and the note mechanism is per-FG rather than per-version.
- **Options:**
  - Pass sds_versions.version, effective_date and change_summary into section16 (and footer 'Rev. 3 — 10/07/2026'); format date per language; keep revision_note as a manual supplement; add the label mapping — _Small plumbing change; previews should show 'DRAFT' instead of a date._
  - Leave as is — _No traceability to version/formula on the printed sheet._
- **Recommended:** Option 1; also print meta.formula_version so a reader can tell which formula version the SDS was built from.

### #33 — Section 16 — Other Information: Abbreviations
- **Concern:** medium
- **Current behaviour:** One canned sentence (SDSGenerator.php:1345, en.php:151) identical on every SDS: defines SARA/TSCA/IDLH even when absent, omits HAP, SNUR, Prop 65, IARC, NTP, STOT, DOT, UN, STEL, TWA, NIOSH that do appear; ES/FR lists have only 4 entries; not override-able.
- **Where:** `src/Services/SDSGenerator.php:1345; templates/translations/en.php:151`
- **Why it matters:** Harmless but visibly generic; cheap to make accurate.
- **Options:**
  - Master abbreviation table (per language) filtered to terms that occur in the rendered sections — _Accurate; needs a post-render scan or per-section term tags._
  - Admin-editable per-language list; reconcile the four files — _Editable, still static._
- **Recommended:** Option 1 (filter from a master table).

### #34 — Section 16 / Cross-cutting: Legal disclaimer block
- **Concern:** medium
- **Current behaviour:** Body = single admin setting sds.legal_disclaimer (settings.php:127; SDSGenerator.php:1366-1375; PDFService.php:962-981): not seeded by any migration/config so a fresh install prints no disclaimer; one English string printed on ES/FR/DE sheets under a translated heading; unchanged for private-label variants. Translated section16.disclaimer paragraphs exist in all four files (en.php:150) but are never read (dead key).
- **Where:** `src/Services/SDSGenerator.php:1364-1376; src/Services/PDFService.php:962-981; templates/translations/en.php:150`
- **Why it matters:** Legal text can silently vanish on a new install and is language-blind; private-label sheets carry the base company's disclaimer.
- **Options:**
  - Per-language setting (sds.legal_disclaimer.<lang>) falling back to the translation-file text when blank; seed the default; optional per-manufacturer override for private label — _Small; resolves the dead key._
  - Delete the translation keys and keep one global string — _Simplest; keeps English on all languages._
- **Recommended:** Option 1 (and decide whether private-label manufacturers get their own disclaimer as part of request #1).

### #35 — Section Cross-cutting: UV acrylate rule-pack appendices (uv_acrylate_note, Sections 4/5/6/7/8/11)
- **Concern:** medium
- **Current behaviour:** Setting uv_acrylate_rule_pack (seeded 'enabled', no admin UI) + family contains 'UV'/'LED' + acrylate detection -> hardcoded English paragraphs stored as sections[N]['uv_acrylate_note'] (UVAcrylateRulePack.php:117-150; SDSGenerator.php:182-187, 450-454). Both renderers explicitly skip the key (PDFService.php:900, preview.php:471); renderSection8/11 ignore it; only the Section 8 preview branch prints it with label 'Uv Acrylate Note'. Blank family (every CMS import) disables it anyway.
- **Where:** `src/Services/UVAcrylateRulePack.php:117-150; src/Services/PDFService.php:900; src/Views/sds/preview.php:471`
- **Why it matters:** The only formulation-aware guidance for UV inks (0.4 mm nitrile, premature polymerization, sensitizers) is computed and thrown away.
- **Options:**
  - Render it (translated) under labels.uv_acrylate_note in each target section, fold the PPE advice into Section 8 text, add the UV-light condition to Section 10, expose the setting in admin — _Turns dead work into value; needs translation keys and family populated on import._
  - Remove the pack — _Less code; loses useful UV content._
- **Recommended:** Option 1.

### #36 — Section Cross-cutting: Per-FG override editor freezes generated defaults
- **Concern:** high
- **Current behaviour:** edit.php pre-fills nearly every textarea with the RESOLVED value ($section[key]) rather than the stored override (only 11.carcinogenicity :243 and 15.state_regs :296 use $overrides), and saveEdits persists every non-empty posted value (SDSController.php:221-257). One 'Save' with no changes converts hazard-derived text, the formula flash point, DOT values, 'Not determined'/'Not regulated' and makes 'Other Hazards: None known.' appear, after which formula/hazard changes no longer flow through for that FG+language. Overrides are per language (EN edits do not reach ES/FR/DE); Section 14 precedence is inverted; 9.solubility override has no UI; resale-RM SDSs (fg id null) have no override path; private-label variants inherit the base FG's overrides with no manufacturer-specific scope.
- **Where:** `src/Views/sds/edit.php:21-305; src/Controllers/SDSController.php:195-267; src/Services/SDSGenerator.php:1390-1406`
- **Why it matters:** This mechanism silently defeats the data-driven parts of the sheet and is the main reason 'prefilled' text persists on individual products.
- **Options:**
  - Pre-fill from $overrides (blank = auto), show the computed value as placeholder/read-only hint, add 'reset to auto', flag overridden fields in the UI, and skip saving values equal to the computed default — _Clear semantics; existing frozen rows should be audited (delete rows equal to the current default)._
  - Add an 'append' mode and a 'copy to other languages' action; add manufacturer_id scope for private-label overrides — _Useful extensions for request #1._
- **Recommended:** Option 1 now (plus a one-off cleanup of overrides that equal the generated default), Option 2 alongside the private-label alias work.

### #37 — Section Cross-cutting: Localization gaps (English hardcoded in PHP, untranslated enum values)
- **Concern:** medium
- **Current behaviour:** Hardcoded English outside the translation files: derivePPE sentences (HazardEngine.php:1041-1155), carcinogen positive summary (3 places), Prop65Service WARNING_* templates and ' (trace)' suffix (Prop65Service.php:26-36, 311-319), UVAcrylateRulePack text, 'None' under empty hazard groups (PDFService.php:359, preview.php:109), 'H-Codes' header (PDFService.php:594, preview.php:204), 'TRADE SECRET'/'Trade Secret' literals (SDSGenerator.php:732-733,761), 'CAS'/'de minimis'/bullets in Section 15, Section 9 enum values and 'Partially soluble in water' (FormulaCalcService.php:454), strtoupper() on accented banners (ASCII-only), US m/d/Y dates, Letter page size, legal disclaimer. Preview labels for Section 1 and no-limits Section 8 are ucwords English.
- **Where:** `src/Services/HazardEngine.php:1041-1155; src/Services/Prop65Service.php:26-36; src/Services/PDFService.php:359, 594; src/Services/FormulaCalcService.php:454`
- **Why it matters:** Only matters if ES/FR/DE sheets are actually issued; today they would mix English and translated text.
- **Options:**
  - Route every sentence through TranslationService keys (or the default-text table) and mb_strtoupper; translate enum values via labels; leave regulatory acronyms/citations in English — _Mechanical but touches many files._
  - Declare non-EN output unsupported until needed — _Zero work; document the limitation._
- **Recommended:** Decide first whether non-English SDSs are a real deliverable; if yes, Option 1 as part of the default-text table migration.

### #38 — Section Cross-cutting: Preview / PDF parity
- **Concern:** medium
- **Current behaviour:** HTML preview shows things the PDF does not (Section 1 email/website; Section 2 PPE sentences; Section 5 'Flash Point C: n'; Section 8 uv_acrylate_note; Section 14 note) and omits things the PDF prints (Section 3 three notes; Section 8 Conc% column; Section 1 'Manufacturer / Supplier Information' sub-heading); labels differ ('Restrictions' vs 'Restrictions on Use', 'Manufacturer Name' vs 'Company', 'Engineering' vs 'Engineering Controls'); Section 11 exposure limits are a table in preview and inline lines in PDF.
- **Where:** `src/Views/sds/preview.php:196-217, 437-480, 475-477; src/Services/PDFService.php:238-252, 763-769`
- **Why it matters:** Reviewers approve what they see in the preview; customers receive something different.
- **Options:**
  - Render the preview from the same data with one shared section-to-label map and the same include/skip rules (or render the PDF to an image/HTML for preview) — _One-time alignment; PDF-as-preview is the most faithful._
  - Leave and document — _Ongoing review risk._
- **Recommended:** Option 1 — share FIELD_LABEL_MAP and skip rules between PDFService and preview.php; consider PDF-based preview for approval.

### #39 — Section Cross-cutting: Static labels, section banners and document strings (all sections)
- **Concern:** low
- **Current behaviour:** Section titles, 'SECTION N:' prefix, every bold field label (getLabels, SDSGenerator.php:1492-1553), 'SAFETY DATA SHEET', 'Page x of y', 'Rev.', 'DISCLAIMER', 'Not a hazardous substance or mixture.', PPE captions, HAP/Prop 65 'none' sentences, 'Not determined'/'Not regulated' placeholders are translation-file constants per language; fallback literals duplicated in SDSTcpdf.php:68,128,130,135, PDFService.php:193 and preview.php:8,22; signal-word colours hardcoded in both renderers.
- **Where:** `src/Services/SDSGenerator.php:1492-1567; src/Services/PDFService.php:27-63; src/Views/sds/preview.php:440-468`
- **Why it matters:** Correctly constant by regulation and format; only the duplicated fallbacks and the few untranslated literals (listed under Localization) need tidying.
- **Options:**
  - Leave as constants; de-duplicate fallback literals into one place — _Housekeeping only._
- **Recommended:** Leave as constant.

### #40 — Section Cross-cutting: Dead settings, dead keys and seed mismatches
- **Concern:** low
- **Current behaviour:** sds.voc_calc_mode shown in settings (settings.php:62-68) but never read — calculate() is called without a mode (SDSGenerator.php:68,211,282) and both VOC figures always print; sds.block_publish_missing has no UI; seeds/seed.php:269-284 writes un-prefixed keys (company_name, voc_calc_mode, sds_block_publish_missing, source_priority, sara_deminimis_default) nothing reads; uv_acrylate_rule_pack has no admin UI; section16.disclaimer, section2.other_hazards, section10.decomposition_* and labels.hazard_statements / health_hazard / revision_note are translation keys nothing renders; SDSAutoSendService.php:286 ignores the DB missing-threshold setting; PDFService::generateToFile() is called (SDSAutoSendService.php:218/337/383, SDSSendQueueController.php:253) but does not exist.
- **Where:** `src/Views/admin/settings.php:62-68; seeds/seed.php:269-284; src/Services/SDSAutoSendService.php:218, 286`
- **Why it matters:** Misleading controls and dead code; the missing generateToFile() is a latent fatal on the auto-send path (separate task).
- **Options:**
  - Remove sds.voc_calc_mode or wire it to choose the primary VOC line; fix seed keys; delete or wire dead translation keys; add UI for uv_acrylate_rule_pack; implement generateToFile() — _Housekeeping._
- **Recommended:** Clean up in one housekeeping pass; implement generateToFile() first.

### #41 — Section Cross-cutting: PDF furniture: metadata, 'Powered by TCPDF', page-2+ top band, page size
- **Concern:** low
- **Current behaviour:** Creator 'SDS System', Subject 'Safety Data Sheet' (generate() only; generateString() omits it), Title 'SDS - {code}', Author from config.php not the DB setting (PDFService.php:103-106, 160-162); TCPDF's default 1pt 'Powered by TCPDF' hyperlink on the last page ($tcpdflink not disabled in SDSTcpdf.php); header is page-1 only but MARGIN_TOP=30 mm applies to all pages leaving a blank band with no product identifier on pages 2+ (SDSTcpdf.php:48-51, PDFService.php:19); Letter/Helvetica hardcoded for all languages.
- **Where:** `src/Services/PDFService.php:19, 103-106, 160-162; src/Services/SDSTcpdf.php:13-51`
- **Why it matters:** Cosmetic/metadata; fine as constants, worth a small tidy.
- **Options:**
  - Set $tcpdflink=false, unify generate()/generateString() metadata, use DB company (or manufacturer) name for Author, add a compact repeating header (code + title) on pages 2+ — _Small; improves professionalism._
- **Recommended:** Tidy in the same housekeeping pass; leave page size as Letter unless non-US output is confirmed.

### #42 — Section Cross-cutting: Computed-but-never-rendered payloads
- **Concern:** low
- **Current behaviour:** Carried in snapshot_json but shown nowhere: Section 11 hazard_classes and carcinogen component_texts, SARA summary/below_threshold, HAP summary_text and category/source, Prop 65 listed_chemicals detail (NSRL/MADL, date_listed), SNUR concentration_pct, Section 16 voc_assumptions, meta.generated_at and meta.formula_version, Section 5 numeric flash_point_c (preview only).
- **Where:** `src/Services/SDSGenerator.php:148-149, 1211-1213, 1330-1333, 1346`
- **Why it matters:** Not harmful, but several of these are exactly the data the canned sections above should be using.
- **Options:**
  - Decide per payload: surface (hazard_classes -> Section 11 text, voc_assumptions -> basis note, formula_version -> Section 16) or stop computing/storing — _Reduces snapshot bloat and makes intent explicit._
- **Recommended:** Surface hazard_classes, voc_assumptions and formula_version via the items above; drop the rest from the snapshot.
