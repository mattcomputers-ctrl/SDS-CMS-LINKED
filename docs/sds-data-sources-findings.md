# SDS data sources audit — findings for review (2026-10-09)

Places where the current code differs from a recorded decision (docs/sds-content-audit.md), or where a sheet can print wrong or contradictory content. Raised by the per-section verifiers, re-read against the code by a consolidation pass. **Nothing has been changed.** Severity high = a sheet can print a wrong or contradictory regulatory statement.

| # | Severity | Decision | Finding |
|---|---|---|---|
| 1 | high | #36 (also #9-#15, #19-#24, #26-#29) | Old frozen text edits survive the cleanup and still override the new automatic text in every section |
| 2 | high | #27 | Unknown flash point plus any unrelated H-code prints 'Not regulated' and publishes |
| 3 | high | #27 | Class 8 and 6.1 packing groups never read the classification |
| 4 | high | #27 | Transport classes other than 3, 8, 6.1 and 9 are never recognised |
| 5 | high | #27, #11 | Packing Group III printed beside H225 when another raw material has a higher flash point |
| 6 | high | #27, #26 | Transport publish gate checks only the first language, but the overrides it depends on are stored per language |
| 7 | high | #27 | A partial Section 14 override lifts the block while other lines still print 'Not determined' or derived values |
| 8 | high | #11, #26, #27 | Flash point edit without a degree sign is read as °C; a non-numeric edit is ignored |
| 9 | high | #11, #13, #19, #26, #27 | Flammability is decided two ways: Sections 5, 7 and 10 use H-codes, Sections 13 and 14 use the flash point |
| 10 | high | #26 | Section 13 D001 says 'flash point below 60 °C' when Section 9 shows 60 °C or higher |
| 11 | high | #26 | D001 ignores flammable gases, aerosols and solids |
| 12 | high | #26 | Toxicity-characteristic metals present as compounds are missed, and the sheet then denies any TC constituent |
| 13 | high | #7, #8, #30 | Trade-secret identity printed in Sections 8, 11, 13 and 15 |
| 14 | high | #4, #7 | Declared trade-secret hazards vanish when the raw material sits inside a finished-good component |
| 15 | high | #20, #23 | Trade-secret hazards are summed at 100 % in the ATEmix and the aquatic summation |
| 16 | high | #8, #42 | Printed concentration band can understate the real amount (Sections 3, 8, 11, 12 and 15) |
| 17 | high | #7, #8 | 'Non-hazardous' flag hides an ingredient that still drives Section 2 and prints in Section 8 |
| 18 | high | #28, #22 (with #5, #15) | Liquid carbon black / TiO2 strip leaves WARNING and carcinogen P-codes, and can delete another ingredient's Carc. 2 |
| 19 | high | none (pre-existing) | PubChem substance-level hazard lists are imported whole onto every class row |
| 20 | high | none (pre-existing) | Bare PubChem category tokens break most-severe consolidation |
| 21 | high | #21, #22 | IARC Group 3 ('not classifiable') listings trigger carcinogen statements |
| 22 | high | #22 | Seed loader collapses carcinogen rows: acetaldehyde and Disperse Blue 1 stored as IARC Group 1 |
| 23 | high | #20 | Wrong default inhalation-vapour ATE values over-classify; the dust route is never used |
| 24 | high | #12 | H412 and H413 products get the H402 sentence 'Harmful to aquatic life.' in Section 6 |
| 25 | high | #23 | Section 12 credits 'the GHS summation method' for a classification that came from the finished-good override |
| 26 | high | #30, #42 | SARA 313: PBT chemicals given a 0.1 % de minimis for supplier notification |
| 27 | high | #29 | TSCA 'all components listed' can print while some ingredients were never checked |
| 28 | medium | #40 | Missing-hazard-data block skipped by SDS Updates and Bulk Publish, although admin help says it applies |
| 29 | high | #15, #35 | UV acrylate PPE sentences contradict 'No special … required' on unclassified UV products |
| 30 | medium | #9 | Section 4 Notes to Physician says 'treat corrosive burns' on products that are only Eye Dam. 1 (H318) |
| 31 | medium | #10 | Severe first-aid paragraphs replace the base text and drop the added hazard sentences |
| 32 | medium | #12 | Section 6 acute-toxicity spill paragraph drops the corrosive wording |
| 33 | medium | #13, #5 | Section 7 omits 'Store locked up' for carcinogens, mutagens, reproductive toxicants and H305 |
| 34 | medium | #13, #19 | Oxidizer sheets have no heat or ignition advice in Section 7; Section 10 ignores oxidizer, explosive and self-heating classes |
| 35 | medium | #11 | A Section 5 Specific Hazards edit freezes the flash point |
| 36 | medium | #6, #7 | Section 3 composition gaps: one trade-secret source masks a disclosed CAS, a FG component without a formula vanishes, Substance sheets have no identity |
| 37 | medium | #7 | Section 3 H-code cell and 'no hazardous ingredients' note can disagree with Section 2 |
| 38 | medium | #8 | Resale and finished-good sheets band the same raw material differently |
| 39 | medium | #5 | Withdrawn P281 still prints from source data |
| 40 | medium |  | H-codes with no class line never print as text in Section 2 |
| 41 | medium | #20, #22 | Section 11 acute category can print without a supporting ATEmix; negative carcinogen sentence with carbon black present |
| 42 | medium | #17, #18(b), #18(d), #18(e) | Physical state and appearance: blank state defaults to 'Liquid', appearance can contradict state, 'Negligible' scores as insoluble |
| 43 | medium |  | HazCom Appendix D content not printed (Section 9 properties, Section 11 routes and symptoms, Section 14(f)/(g)) |
| 44 | medium | #16, #27, #3 | Section 14 extras: 60 °C exactly is not Class 3, boiling-point edit ignored, family name changes the shipping name |
| 45 | medium | #27 | Resale and private-label sheets: no transport override route, no preview warning, misleading block message |
| 46 | medium | #29 | TSCA review edge cases: 'not listed' prints 'not verified'; inactive inventory entries count as listed |
| 47 | medium | #42 | Prop 65 type match is case-sensitive; manual HAP % uses only the last formula line |
| 48 | medium | #3, #37 | Legacy 'Default Product Use' settings pre-fill products and block family and translated text |
| 49 | medium | #3 | Resale raw-material sheets print the family's product text instead of the resale default |
| 50 | medium | #35 | UV text depends on name or CAS acrylate detection, not on family membership |
| 51 | medium | #27, #37 | '≤' prints as '?' in the Section 14 viscous-liquid note |
| 52 | medium | #33 | IATA and IMDG print on every sheet but are never defined in Section 16 |
| 53 | medium | #32, #41, #1 | Emailed and report alias PDFs are rebuilt from the base sheet's stored snapshot |
| 54 | medium | #32 | Bulk private-label publish can duplicate or split a version number |
| 55 | medium | #1 | Manufacturer name can be saved blank, dropping the Company line from private-label sheets |
| 56 | low | #36, #4 | Blank or empty text-edit rows silently hide a field |
| 57 | low | #36 | Editor hints and the cleanup script ignore cross-fed fields |
| 58 | low | #36 | Text edits, hazard overrides and settings changes do not mark published SDSs stale |
| 59 | low | #32 | Bulk publish stamps the UTC date captured at worker start; other publishers use local time |
| 60 | low | #41, #33 | Alias pack suffix and abbreviations handled inconsistently |
| 61 | low | #3, #19, #35 | Inactive or legacy-manual families still drive Section 1 text and the UV flag |
| 62 | low | #37 | Mixed-language output on ES/FR/DE sheets |
| 63 | low | #28, #25 | Old snapshots re-render with outdated or contradictory content |
| 64 | low | #10, #12, #13, #14 | Remaining Section 4-8 wording gaps |
| 65 | low | #35, #36 | UV notes in Sections 5-7 cannot be edited and can contradict the surrounding text |
| 66 | low | #20, #22, #23, #26 | Section 11-13 secondary wording issues |
| 67 | low |  | 'Replace' hazard override does not fully replace the classification |
| 68 | low | #1, #2 | Supplier and emergency phone warnings incomplete |
| 69 | low | #40, #34, #32 | Other publish-gate and admin loose ends |
| 70 | low | #40, #42 | Dead keys, dead code and unprinted snapshot payloads |
| 71 | low | #18(a) | VOC is not normalised and missing data is silent |

## 1. Old frozen text edits survive the cleanup and still override the new automatic text in every section

- **Severity:** high
- **Decision:** #36 (also #9-#15, #19-#24, #26-#29)
- **Where:** `scripts/cleanup-default-overrides.php:88-119; src/Services/TextOverrideService.php:72-75; src/Services/SDSGenerator.php:3226-3232, 3196-3210, 2697-2698, 3127-3143`

Before #36 the editor filled every box with the text generated at that time and saved every non-empty box as a text_overrides row. This included 9.flash_point, 14.un_number/hazard_class/packing_group, 15.osha_status and 15.tsca_status. scripts/cleanup-default-overrides.php deletes a row only when it equals TODAY's automatic text. Rows holding the OLD automatic text are therefore kept as 'custom' and still win. Worst cases: (1) an unclassified product prints 'Not a hazardous substance or mixture' in Section 2 and 'This product is classified as hazardous under OSHA HazCom 2012' in Section 15. (2) The old TSCA sentence 'All components are listed on or exempt' prints, marks the status as overridden and suppresses the TSCA warning. (3) An H302 product prints 'criteria are not met' in Section 11. (4) An old single-ingredient UN number or class in Section 14 overrides the #27 classifier and lifts the Not-determined gate. (5) An H242/H261 product prints 'Stable' in Section 10. (6) Old D001 disposal text sits above an RCRA line that says the opposite. (7) Old English text prints on ES/FR/DE sheets. The same pattern freezes the pre-#9/#10/#11/#12/#13/#14/#23 wording in Sections 4-8 and 12. Nothing in the code catches this; only a manual step in post-update-checklist.md covers it.

**Suggested fix:** Add a second cleanup pass that also deletes rows equal to any earlier automatic text (the old translation strings from git 9d8de70^/b3a51ed^ in all four languages, the old hard-coded derivePPE strings, old DOT values). Run it as a dry run, review the leftover rows for sections 9, 14 and 15 with the owner, apply it, then republish. Consider taking 15.osha_status out of the editable fields, because it must follow Section 2.

## 2. Unknown flash point plus any unrelated H-code prints 'Not regulated' and publishes

- **Severity:** high
- **Decision:** #27
- **Where:** `src/Services/TransportClassifier.php:154-162`

TransportClassifier returns 'Not determined' (which blocks publishing) only when there is no flash point AND no H-codes at all. A solvent product whose raw materials have no flash point but which carries, for example, H317 or H319 prints 'Not regulated' / 'Not applicable' / 'Marine pollutant: No' in Section 14 and publishes. #27 was meant to remove exactly this false negative.

**Suggested fix:** Return not_determined whenever the flash point is null and no class triggers, whatever H-codes are present (solids can be exempted). Update the gate message to match.

## 3. Class 8 and 6.1 packing groups never read the classification

- **Severity:** high
- **Decision:** #27
- **Where:** `src/Services/TransportClassifier.php:376, :394, :431; src/Services/GHSHazardClass.php:50-53; src/Services/HazardEngine.php:1333-1340`

corrosivePackingGroup, toxicPackingGroup and isInhalationPgI compare hazard_classes[].canonical with display names ('Skin Corrosion/Irritation', 'Acute Toxicity…'). The engine emits snake-case constants ('skin_corrosion_irritation', 'acute_toxicity_inhalation'), as canonicalToAteRoute confirms. So Class 8 is always PG II (Skin Corr. 1A should be PG I), Acute Tox. 1 gives PG II or III, and the 173.2a(a) rule that ranks 6.1 PG I by inhalation above Class 3 never fires. The unit tests pass because they feed in display strings.

**Suggested fix:** Compare against the GHSHazardClass constants, and change TransportClassifierTest to use real engine output.

## 4. Transport classes other than 3, 8, 6.1 and 9 are never recognised

- **Severity:** high
- **Decision:** #27
- **Where:** `src/Services/TransportClassifier.php:82-85, :154-163`

Only H224-H226, H314, H300/301/310/311/330/331 and H400/410/411 are read. Aerosols and gases (H220-H223, H229, H280), flammable solids (H228), pyrophoric and self-heating materials (H250-H252), water-reactives (H260/H261), oxidisers (H270-H272) and organic peroxides such as catalysts and hardeners (H240-H242) print 'Not regulated', or Class 3 when a raw material has a low flash point. Other sections of the same sheet treat these codes as hazardous.

**Suggested fix:** When any of these H-codes is present, return not_determined so publishing is blocked until an operator enters the classification. Alternatively, add the classes to the classifier.

## 5. Packing Group III printed beside H225 when another raw material has a higher flash point

- **Severity:** high
- **Decision:** #27, #11
- **Where:** `src/Services/TransportClassifier.php:174-182`

H225 (flash point below 23 °C) sets PG II only when no raw material has any flash point at all. If the H225 solvent's raw material has no flash_point_c but another raw material has, say, 40 °C, Section 14 prints Class 3 PG III while Section 2 shows H225.

**Suggested fix:** Assign PG II whenever H225 is present (PG I if the boiling point is 35 °C or lower), whatever the formula flash point. Warn when a constituent with a flammable code comes from a raw material with no flash point.

## 6. Transport publish gate checks only the first language, but the overrides it depends on are stored per language

- **Severity:** high
- **Decision:** #27, #26
- **Where:** `src/Controllers/SDSController.php:366-368, 753-755, 381, 768; src/Controllers/SDSUpdateController.php:322-327; src/Services/PrivateLabelPublisher.php:206-208; src/Services/SDSReadinessService.php:376-382`

Manual FG publish, manual resale publish, SDS Update and private-label republish run transportNotDeterminedError on languages[0] only. Section 14 overrides and the Section 9 flash-point override are stored per language. An EN-only override lifts the block, and the ES/FR/DE sheets publish under the same version with 'Not determined' on all five Section 14 lines. An ES-only flash-point edit changes D001 and Class 3 on the ES sheet only. The bulk worker checks every language, so one product can pass in EN and fail in ES there. The TSCA warning has the same first-language-only check, and the readiness query ignores language.

**Suggested fix:** Run the transport gate and the TSCA warning on every generated language. Alternatively, make the Section 14 and 9.flash_point overrides language-independent (save once, apply to all languages).

## 7. A partial Section 14 override lifts the block while other lines still print 'Not determined' or derived values

- **Severity:** high
- **Decision:** #27
- **Where:** `src/Services/SDSGenerator.php:3119-3143; src/Services/SDSReadinessService.php:401-411`

The status becomes 'override' as soon as un_number OR hazard_class is non-blank, and the gate checks only that status. The other fields fall back one by one to their automatic values. On a not_determined product, following the block message ('enter the UN number and hazard class') publishes a sheet whose shipping name, packing group and environmental hazards read 'Not determined'. On other products a UN-number-only override can sit beside a derived 'Not regulated' class. The derived notes ('Combustible liquid…', the viscous PG III sentence) still print under an overridden classification.

**Suggested fix:** Require a complete set (UN number, shipping name, class, packing group) before status becomes 'override', or block when any printed Section 14 value equals labels.not_determined. When the status is 'override', print only the base note.

## 8. Flash point edit without a degree sign is read as °C; a non-numeric edit is ignored

- **Severity:** high
- **Decision:** #11, #26, #27
- **Where:** `src/Services/SDSGenerator.php:3037-3056; src/Views/sds/edit.php:172-173`

resolveFlashPointNumeric recognises °C and °F only when the '°' sign is present, and otherwise takes the first number as °C. An edit typed '75 F' (23.9 °C, flammable) prints '75 F' in Sections 5 and 9 but is classified as 75 °C: Section 13 has no D001 and Section 14 says 'Not regulated' with the combustible note. An edit with no number ('None — water based') prints in Section 9 while Sections 13 and 14 silently use the formula's lowest raw-material flash point, so Section 14 can show UN1210 Class 3. The editor does not say that this field drives transport.

**Suggested fix:** Make the degree sign optional (e.g. /(-?\d+(?:\.\d+)?)\s*°?\s*F\b/i). Validate the field on save (number plus unit), or treat a non-numeric edit as needing a Section 14 determination. Add a hint that the field drives Sections 5, 13 and 14.

## 9. Flammability is decided two ways: Sections 5, 7 and 10 use H-codes, Sections 13 and 14 use the flash point

- **Severity:** high
- **Decision:** #11, #13, #19, #26, #27
- **Where:** `src/Services/SDSGenerator.php:1376-1390, 1431-1435, 1713-1728, 2147, 2190-2192; src/Services/TransportClassifier.php:123`

HazardEngine never classifies Flammable Liquids from the flash point. Sections 5, 7 and 10 choose their flammable wording from H220-H228 only. Sections 13 (D001) and 14 (Class 3) use the resolved flash point. A product with a 30 °C flash point and no H22x (constituent data missing, or each solvent below the 1 % cut-off) prints UN1210 Class 3 and D001, while Section 2 is not flammable and Sections 5, 7 and 10 give no flammability, ignition-source or flash point wording. Within Section 5, the printed category comes from H-codes and the flash point from the raw materials, unchecked: for example 'Flammable Liquids, Category 3 … Flash point: -4 °C'. No operator warning flags this.

**Suggested fix:** Derive the Flammable Liquids category from the resolved flash point and boiling point in the engine when one is known (mixture test data prevail), so Sections 2, 5, 7, 10, 13 and 14 agree. At minimum, warn at preview and publish when the flash point category and the H-codes disagree.

## 10. Section 13 D001 says 'flash point below 60 °C' when Section 9 shows 60 °C or higher

- **Severity:** high
- **Decision:** #26
- **Where:** `src/Services/SDSGenerator.php:2902-2903`

rcra_reason_flash_point ('flash point below 60 °C (140 °F)') prints whenever H224/H225/H226 is present, before the resolved flash point is checked. With a Section 9 edit of '> 93 °C', or a formula flash point of 60 °C or higher, Section 13 states the opposite of Section 9.

**Suggested fix:** Print 'below 60 °C' only when the resolved flash point is below 60 °C with no '>' flag. When only the H-code applies, use hazard-based wording ('classified as a flammable liquid').

## 11. D001 ignores flammable gases, aerosols and solids

- **Severity:** high
- **Decision:** #26
- **Where:** `src/Services/SDSGenerator.php:2901-2916, 2975-2977`

D001 checks H224-H226, the flash point, H270-H272 and H250-H252. H220/H221, H222/H223 and H228 are ignored, although 40 CFR 261.21(a)(2)-(3) covers them. A flammable aerosol or solid with no other match prints 'No RCRA hazardous waste characteristic … has been identified'.

**Suggested fix:** Add D001 reasons for H220-H223 and H228, with new translation keys in all four languages.

## 12. Toxicity-characteristic metals present as compounds are missed, and the sheet then denies any TC constituent

- **Severity:** high
- **Decision:** #26
- **Where:** `src/Services/RCRAService.php:63; migrations/055_regulatory_lists_overrides.sql:107-147; src/Services/SDSGenerator.php:2975-2977`

rcra_waste_codes holds element CAS numbers only (for example Lead 7439-92-1), and RCRAService matches CAS numbers exactly. Lead chromate, cadmium pigments and chromium compounds never match, so rcra_none prints that no component is a toxicity characteristic constituent.

**Suggested fix:** Add a metal-compound category match (for example a cas_master element flag). At minimum, reword rcra_none so it does not positively deny TC constituents.

## 13. Trade-secret identity printed in Sections 8, 11, 13 and 15

- **Severity:** high
- **Decision:** #7, #8, #30
- **Where:** `src/Services/SDSGenerator.php:1833-1848, 2629-2683, 2955-2968, 2560-2617; src/Services/PDFService.php:1024-1034, 1061-1066, 1087-1093`

Section 3 and the Section 12 tables mask is_trade_secret constituents. Section 8 copies the exposure-limit rows with the real CAS and name. Section 11's component block and carcinogen line have no trade-secret check. The Section 15 SARA 313, HAP, SNUR and Prop 65 lines print the real name and CAS (SARA has been newly visible since #30). Section 13 prints the specific RCRA code and TCLP level beside 'Trade Secret', and 'D018 … 0.5 mg/L' identifies benzene. The same sheet can say 'Trade Secret' in one section and name the chemical in another, contrary to 29 CFR 1910.1200(i).

**Suggested fix:** Carry is_trade_secret into every section's rows and print labels.trade_secret_cas plus tradeSecretName() and the trade-secret band, as section12 does (:2735-2741). For RCRA, print a generic 'contains a toxicity characteristic constituent' phrase, or confirm with the owner that disclosing the code is acceptable.

## 14. Declared trade-secret hazards vanish when the raw material sits inside a finished-good component

- **Severity:** high
- **Decision:** #4, #7
- **Where:** `src/Models/Formula.php:550-594 (contrast :419-432)`

The sub-FG merge copies only the concentration, the trade-secret flags and the contributing materials into the parent's TRADE_SECRET bucket. It drops manual_hazard_json, so HazardEngine sees TRADE_SECRET as a CAS with no data. The declared hazards disappear from Section 2, no Section 3 row prints, and the Section 3 note says that unlisted ingredients are non-hazardous. Publishing is blocked only if the nested share reaches the missing-data threshold.

**Suggested fix:** Merge manual_hazard_json (and min/max) from sub-composition entries in the FG-component merge.

## 15. Trade-secret hazards are summed at 100 % in the ATEmix and the aquatic summation

- **Severity:** high
- **Decision:** #20, #23
- **Where:** `src/Services/HazardEngine.php:646-660, :1309-1321, :2392-2399`

The manual trade-secret hazard JSON is parsed at concentration 100.0 to bypass cut-offs, and the same 100 % feeds the ATE and aquatic buffers. A 5 % trade-secret raw material with Acute Tox. 4 oral prints 'ATEmix = 500 mg/kg' and Category 4; the true ATEmix is about 10,000 (not classified). Any Category 1 aquatic trade secret classifies the whole mixture, while its Section 12 row shows the real '1 - 5%' band under the summation lead-in.

**Suggested fix:** Feed the ATE and aquatic buffers with the raw material's actual contribution %. Keep 100 % only for the cut-off bypass.

## 16. Printed concentration band can understate the real amount (Sections 3, 8, 11, 12 and 15)

- **Severity:** high
- **Decision:** #8, #42
- **Where:** `src/Models/Formula.php:362-371, 549-594; src/Services/SDSGenerator.php:3408-3452`

concentration_min/max are summed only from direct constituents that have both pct_min and pct_max. Exact-% rows, one-sided rows and nested finished-good contributions add only to concentration_pct, yet formatConcentration prefers min/max whenever both are set. Example: CAS X at 1-3 % in a 1 % raw material plus exactly 50 % in a 50 % raw material is really 25 %, passes the cut-off, and prints '<0.1%'. The same band is reused in Sections 8, 11, 12 and 15, under a SARA note that says the upper end is the maximum present. A very wide range falls back to the midpoint (0.1-100 % prints '30 - 60%').

**Suggested fix:** Add exact contributions to both min and max and carry min/max through the sub-FG merge. Alternatively, use the min/max band only when every contribution is ranged. When no prescribed range contains [min, max], pick one whose upper end is at least max.

## 17. 'Non-hazardous' flag hides an ingredient that still drives Section 2 and prints in Section 8

- **Severity:** high
- **Decision:** #7, #8
- **Where:** `src/Services/SDSGenerator.php:1030-1033; src/Models/Formula.php:380-382; src/Services/HazardEngine.php:595-598`

raw_material_constituents.is_non_hazardous is read only by Section 3; HazardEngine ignores it. A flagged CAS can drive the Section 2 classification and print an exposure limit in Section 8 while missing from Section 3. The Section 3 note then says that ingredients with an exposure limit are listed and unlisted ones are non-hazardous. The policy docblock and operations.md also contradict each other on the OEL cut-off.

**Suggested fix:** Hide a flagged CAS only when the engine finds no class and no OEL for it, or apply the flag in HazardEngine too. At minimum, warn when a flagged CAS is hazardous or has an OEL. Then align the docblock and operations.md.

## 18. Liquid carbon black / TiO2 strip leaves WARNING and carcinogen P-codes, and can delete another ingredient's Carc. 2

- **Severity:** high
- **Decision:** #28, #22 (with #5, #15)
- **Where:** `src/Services/SDSGenerator.php:3715-3760; src/Services/HazardEngine.php:2609-2627`

In a non-powder product, applyCarbonBlackLogic removes H351, the Carcinogenicity rows and GHS08. It does not reset the signal word or the P-codes. A water-based ink whose only hazard is carbon black prints 'WARNING' and P201/P202/P280/P308+P313 with no class or pictogram. Section 15 then calls the product hazardous, while Section 8 says no special protection is needed. Consolidation also keeps only one Carcinogenicity entry. If carbon black's entry is the one kept, the H351 filter finds no other CAS's Cat 2 and also removes a genuine Carc. 2 from another ingredient, depending on formula order.

**Suggested fix:** Remove the inhalation-only CAS contributions before classify and consolidation, or rerun classify without them. Re-derive the signal word and P-codes from the remaining classes. Add a test for a carbon-black-only ink.

## 19. PubChem substance-level hazard lists are imported whole onto every class row

- **Severity:** high
- **Decision:** none (pre-existing)
- **Where:** `src/Services/FederalData/Connectors/PubChemConnector.php:671-683; src/Services/HazardEngine.php:876-916`

PubChemConnector writes the substance's overall signal word and its full H-code, P-code and pictogram lists onto every class row. classify merges all of them as soon as any one class of that CAS crosses its cut-off. A Carc. 1B row triggering at 0.1-1 % therefore brings in GHS06, 'Danger' and H301 from an Acute Tox. 3 class that is below its own cut-off. H301 has no class line, so it never prints in Section 2, but it drives the pictograms, PPE and Sections 4-13 (also Section 14 Class 6.1).

**Suggested fix:** Store H-codes, P-codes, pictograms and signal word per class, or filter PubChem codes to the triggered classes as filterCpdCodesByTriggeredClasses does for CPDs.

## 20. Bare PubChem category tokens break most-severe consolidation

- **Severity:** high
- **Decision:** none (pre-existing)
- **Where:** `src/Services/FederalData/Connectors/PubChemConnector.php:546-567; src/Services/HazardEngine.php:2669-2715`

parseHazardClassText stores PubChem categories as bare tokens ('2', '3'). categoryToSeverity matches only 'Category N' / 'Cat N', so every bare token scores 500 and the first row seen wins a tie. Against a 'Category N' value from another source, the bare token always loses even when it is more severe. Two solvents can print 'Flammable liquids (3)' with H226 beside a 'Danger' signal word.

**Suggested fix:** Normalise bare tokens to 'Category N' on import (or in categoryToSeverity), and backfill the existing rows.

## 21. IARC Group 3 ('not classifiable') listings trigger carcinogen statements

- **Severity:** high
- **Decision:** #21, #22
- **Where:** `src/Services/CarcinogenService.php:133-175; storage/data/seed/carcinogens.csv:437-443, 547`

CarcinogenService::analyse keeps every carcinogen_list row. The seed holds 38 Group 3 rows, including toluene, xylenes, isopropanol, methanol, cyclohexane and phenol. Ordinary solvent inks print 'One or more components are listed as carcinogens…' and 'Toluene … IARC: Group 3' in Section 11, with no H350/H351 in Section 2.

**Suggested fix:** In analyse(), keep only IARC 1/2A/2B, NTP Known/RAHC and OSHA-listed findings, or drop the Group 3 rows from the seed.

## 22. Seed loader collapses carcinogen rows: acetaldehyde and Disperse Blue 1 stored as IARC Group 1

- **Severity:** high
- **Decision:** #22
- **Where:** `scripts/load-seed-data.php:296-307; storage/data/seed/carcinogens.csv:560, 668`

load-seed-data.php upserts on (cas_number, agency), so the last CSV row wins. Row 560 ('Acetaldehyde associated with consumption of alcoholic beverages', Group 1) overwrites acetaldehyde's 2B. Row 668 does the same to 2475-45-8. Both print 'IARC: Group 1' and become H350 Cat 1A where the CAS has no federal data. This applies only if the live database was loaded from this seed.

**Suggested fix:** Remove the context-specific Group 1 rows (or keep the most relevant row per CAS and agency), reload, and check the live carcinogen_list for those two CAS numbers.

## 23. Wrong default inhalation-vapour ATE values over-classify; the dust route is never used

- **Severity:** high
- **Decision:** #20
- **Where:** `src/Services/HazardEngine.php:209-214, 1333-1340`

CATEGORY_DEFAULT_ATES inhalation_vapor uses Cat 3 = 1.5 and Cat 4 = 10 mg/L; the GHS Table 3.1.2 point estimates are 3 and 11. A Cat 4 vapour component at 100 % gives ATEmix 10, which is Category 3 (H331). The wrong category flows to Sections 2 and 11 and to Section 14 Class 6.1. canonicalToAteRoute always maps inhalation to vapour, so dust ATE data is never read and powders print 'mg/L (4 h, vapors)'.

**Suggested fix:** Set the vapour defaults to 0.05/0.5/3/11. Use the dust route for solid/powder products or where dust ATE data exists.

## 24. H412 and H413 products get the H402 sentence 'Harmful to aquatic life.' in Section 6

- **Severity:** high
- **Decision:** #12
- **Where:** `src/Services/SDSGenerator.php:1518-1532; templates/translations/en.php:108`

One sentence serves H402, H412 and H413. An H413-only (Chronic 4) product is called 'Harmful to aquatic life', which contradicts its own H413 in Sections 2 and 12. H412 loses 'with long lasting effects'. A lower chronic tier is also dropped whenever an acute tier prints (H400+H412).

**Suggested fix:** Add separate translated H412 and H413 sentences, keep the H402 sentence for H402 only, and evaluate the chronic route independently of the acute route.

## 25. Section 12 credits 'the GHS summation method' for a classification that came from the finished-good override

- **Severity:** high
- **Decision:** #23
- **Where:** `src/Services/SDSGenerator.php:2764-2778; src/Services/HazardEngine.php:954-966, 1685-1691, 1741-1745`

The summation lead-in is chosen whenever the component table has rows, whatever the source of the aquatic H-code. An operator override is then attributed to table values that do not support it. Additive override mode only adds missing codes, so a summation H411 plus an override H410 both print. In replace mode the 'does not meet the criteria based on component data' sentence prints above a table that can show Category 1 (M = 10) at 30-60 %.

**Suggested fix:** When the aquatic class comes from fg_override, use an 'assessed by the manufacturer' lead-in (or suppress the table in replace mode). In additive mode, drop the less severe aquatic code of the same route.

## 26. SARA 313: PBT chemicals given a 0.1 % de minimis for supplier notification

- **Severity:** high
- **Decision:** #30, #42
- **Where:** `src/Services/SARA313Service.php:44-81, 151; storage/data/seed/sara313.csv; src/Services/HAPService.php:96-103`

SARA313Service uses pbt_threshold_pct (0.1 in all 33 seeded PBT rows), drops PBTs below it from Section 15, and prints '(de minimis threshold: 0.1%; PBT chemical)'. Since the 2023 TRI rule, PBT chemicals have no de minimis exemption from supplier notification. The sheet can therefore omit a notifiable PBT such as a lead compound, or state a threshold that does not apply. Category members are also matched by exact CAS only (glycol ethers, metal compounds), so the 'does not contain' sentence can be wrong.

**Suggested fix:** Report is_pbt rows at any concentration above 0 and print 'PBT chemical, no de minimis exemption'. Confirm the rule reading with regulatory staff. Add a CAS-to-category mapping for SARA and HAP.

## 27. TSCA 'all components listed' can print while some ingredients were never checked

- **Severity:** high
- **Decision:** #29
- **Where:** `src/Models/Formula.php:285-343; src/Services/TSCAService.php:174-193`

getExpandedComposition inner-joins raw_material_constituents and skips blank-CAS rows unless they are trade secrets with H-codes. A raw material with no constituent rows, or with blank-CAS constituents, never reaches TSCAService::analyse, so the sheet can say every component is listed. The same gap means such raw materials add nothing to the hazard calculation, and no gate fires.

**Suggested fix:** Pass formula raw materials with no constituents or with blank-CAS constituents to TSCAService as unverified ('not verified' plus a warning). Add a readiness check that every formula raw material has constituents with a CAS number and a percentage.

## 28. Missing-hazard-data block skipped by SDS Updates and Bulk Publish, although admin help says it applies

- **Severity:** medium
- **Decision:** #40
- **Where:** `src/Controllers/SDSUpdateController.php:291-330; scripts/publish-worker.php:150-195; src/Views/admin/settings.php:69`

Single FG publish, resale publish and private-label republish call the missing-data check. SDSUpdateController::republish and publish-worker.php check only transport and phone. An FG with a no-data CAS above the threshold can therefore publish through those paths, possibly printing 'Not a hazardous substance or mixture.' The settings page and post-update-checklist.md say the block covers bulk publish and auto-send.

**Suggested fix:** Call SDSReadinessService::missingHazardDataError in both places, and replace SDSController::checkMissingHazardData with it. Correct the help text and the checklist.

## 29. UV acrylate PPE sentences contradict 'No special … required' on unclassified UV products

- **Severity:** high
- **Decision:** #15, #35
- **Where:** `src/Services/SDSGenerator.php:1853-1866, 911-922; src/Services/UVAcrylateRulePack.php:49-58, 207-214`

section8() appends the UV respirator, glove and goggle sentences to each PPE field at any tier. An unclassified UV product prints 'No special respiratory protection required…' followed by '…use a NIOSH-approved air-purifying respirator…'. The eye and hand fields read the same way. Acrylate detection scans the whole composition at any concentration and matches 'acrylic'. Sections 4 and 11 guard their UV text on H317; Section 8 does not. Section 2 PPE leaves the supplement out, so Sections 2 and 8 differ on UV sheets.

**Suggested fix:** Apply the supplement only when the field's tier is hazard-driven (or H317 is present), or use *_unclassified variants. Move the supplement into resolvePPE so Sections 2 and 8 share one value.

## 30. Section 4 Notes to Physician says 'treat corrosive burns' on products that are only Eye Dam. 1 (H318)

- **Severity:** medium
- **Decision:** #9
- **Where:** `src/Services/SDSGenerator.php:1232-1238, 1204`

notes_corrosive fires on H314 or H318. A product classified only H318 is told 'Do not attempt to neutralize; treat corrosive burns as thermal burns', which does not match its classification. H305, which is not an HCS category, also triggers the aspiration notes.

**Suggested fix:** Trigger notes_corrosive on H314 only (add an eye-damage note for H318). Confirm or remove H305 in the aspiration triggers.

## 31. Severe first-aid paragraphs replace the base text and drop the added hazard sentences

- **Severity:** medium
- **Decision:** #10
- **Where:** `src/Services/SDSGenerator.php:1129-1214`

H330/H331, H314/H310/H311 and H304/H300/H301 select replacement paragraphs. The added sentences for H332, H312, H315 and H302 apply only in the base branch. H304 plus H301 prints only the aspiration paragraph and loses 'Call a poison center or physician immediately'. The code comment calls this deliberate, but #10 says fragments are added.

**Suggested fix:** Record replace-for-severe as an amendment to #10, or append the toxicity sentence after the aspiration paragraph.

## 32. Section 6 acute-toxicity spill paragraph drops the corrosive wording

- **Severity:** medium
- **Decision:** #12
- **Where:** `src/Services/SDSGenerator.php:1502-1508`

The acute and corrosive precautions are an if/elseif. A product with H301/H331 plus H314 gets the SCBA/evacuate paragraph but no chemical-resistant suit, face shield or 'avoid all contact' wording.

**Suggested fix:** Append a corrosive fragment when both apply, as the flammable add-on already does.

## 33. Section 7 omits 'Store locked up' for carcinogens, mutagens, reproductive toxicants and H305

- **Severity:** medium
- **Decision:** #13, #5
- **Where:** `src/Services/SDSGenerator.php:1724-1727, 1807-1809; src/Services/GHSHazardData.php:600-652, 730`

The lock-up trigger is a hard-coded H-code list that leaves out H340/H341/H350/H351/H360/H361 and H305, all of which carry P405 in GHSHazardData. A carbon-black or TiO2 powder prints P405 in Section 2 but no lock-up storage advice in Section 7.

**Suggested fix:** Derive the flag from the resolved P-codes (P405 present), or add the CMR codes and H305 and match on the code prefix.

## 34. Oxidizer sheets have no heat or ignition advice in Section 7; Section 10 ignores oxidizer, explosive and self-heating classes

- **Severity:** medium
- **Decision:** #13, #19
- **Where:** `src/Services/SDSGenerator.php:1716-1728, 2145-2198`

In Section 7, the $ignition flag excludes H270-H272, so oxidizers get no heat wording even though Section 2 prints P210. In Section 10, only H240-H242, H250 and H260/H261 change Reactivity/Stability. H270-H272, H200-H205, H251/H252 and H230-H232 products print 'No dangerous reaction known' and 'Stable', and organic peroxides are called 'Self-reactive'. Water-reactive products get no ignition condition, although the reactivity line says the gases may ignite.

**Suggested fix:** Include oxidizers in $ignition. Add reactivity and stability fragments for the missing classes, use 'self-reactive or organic peroxide' wording, and include water-reactives in the ignition condition.

## 35. A Section 5 Specific Hazards edit freezes the flash point

- **Severity:** medium
- **Decision:** #11
- **Where:** `src/Services/SDSGenerator.php:1428-1450`

The edit replaces the whole paragraph, including the embedded flash point line. After a formula change or a new Section 9 edit, Section 5 keeps the old value while Sections 9 and 14 move on. No warning is raised.

**Suggested fix:** Append the flash point line as a separate fragment after an edit, or warn when an edit contains a flash point that differs from the current one.

## 36. Section 3 composition gaps: one trade-secret source masks a disclosed CAS, a FG component without a formula vanishes, Substance sheets have no identity

- **Severity:** medium
- **Decision:** #6, #7
- **Where:** `src/Models/Formula.php:373-378, 525-536; src/Services/SDSGenerator.php:1026-1121`

(1) If any contributing raw material flags a CAS as trade secret, the whole CAS row becomes 'TRADE SECRET', and its H-code cell shows the TRADE_SECRET union (blank if there are no trade-secret declarations). (2) A finished-good formula line expands only when that component has an is_current formula; otherwise its ingredients silently drop out of Section 3 and the hazard calculation. (3) A sheet typed Substance still applies the mixture filter, so a non-hazardous substance prints 'Type: Substance' and 'No hazardous ingredients…' with no name or CAS, which App. D 3 requires.

**Suggested fix:** Use the CAS's own H-codes for CAS-bearing trade-secret rows and warn when the CAS is disclosed elsewhere. Add a readiness warning for FG components with no current formula. For Substance, always print the identity row and suppress the mixture notes.

## 37. Section 3 H-code cell and 'no hazardous ingredients' note can disagree with Section 2

- **Severity:** medium
- **Decision:** #7
- **Where:** `src/Services/HazardEngine.php:2609-2660; src/Services/SDSGenerator.php:979-1008, 1043; src/Services/TransportClassifier.php:345-371`

consolidateHazardClasses keeps one entry per class, so a second CAS in the same class shows no code. FG override classes are never attributed to a CAS, and mixture summations can be driven by contributors below 0.1 %. Section 3 can therefore print 'No hazardous ingredients above disclosure thresholds' beside a classified Section 2. The same per-class consolidation makes Section 14 technical names one solvent chosen by category rather than the two largest contributors.

**Suggested fix:** Keep a per-CAS contributors list on consolidated entries (or build per-CAS codes before consolidation), and rank technical names by concentration. When Section 2 is classified and Section 3 is empty, print a note that the classification comes from the mixture or the product assessment.

## 38. Resale and finished-good sheets band the same raw material differently

- **Severity:** medium
- **Decision:** #8
- **Where:** `src/Services/FormulaCalcService.php:232-292`

The resale path uses the midpoint and never sets min/max, so a 10-30 % ingredient prints '10 - 30%' on a single-line FG and '15 - 40%' on its resale sheet. The resale path also takes the trade-secret and non-hazardous flags from the first row of a CAS only.

**Suggested fix:** Have buildResaleComposition carry min/max and use the same flag-merge rules as Formula::getExpandedComposition.

## 39. Withdrawn P281 still prints from source data

- **Severity:** medium
- **Decision:** #5
- **Where:** `src/Services/HazardEngine.php:899-908; src/Services/GHSStatements.php:158`

P281 is filtered only in the generator's carcinogen merge. It still arrives from PubChem p_statements_json, CPDs, trade-secret JSON and the FG override, and GHSStatements still holds its text, so 'P281: Use personal protective equipment as required' can print.

**Suggested fix:** Map P281 to P280 in classify or translateHazardResult, and flag it in the CPD and override editors.

## 40. H-codes with no class line never print as text in Section 2

- **Severity:** medium
- **Decision:** —
- **Where:** `src/Services/PDFService.php:430-458, 525-527; src/Views/sds/preview.php:111-130; src/Services/SDSGenerator.php:2373-2400`

Section 2 prints H-phrases only through each class line's default code. Codes from PubChem substance lists, FG-override H-codes entered without a class, or override classes with no code drive the pictograms, PPE and Sections 4-13 but never appear in Section 2. The same override shape also makes Section 11 print 'Not classified' for an H30x shown in Section 2.

**Suggested fix:** Print any h_statements code not covered by a class line in a 'Hazard Statements' list, and derive Section 11 route categories from the H-codes as well.

## 41. Section 11 acute category can print without a supporting ATEmix; negative carcinogen sentence with carbon black present

- **Severity:** medium
- **Decision:** #20, #22
- **Where:** `src/Services/HazardEngine.php:433-435, 858-871; src/Services/SDSGenerator.php:2410-2434, 3854-3938, 2684-2692`

(1) Acute toxicity is also classified by single-ingredient cut-off (Cat 1-3 at 0.1 %, Cat 4 at 1 %). A product with 0.2 % of a Cat 3 oral ingredient prints 'Category 3 — Toxic if swallowed (H301)' while its ATEmix (about 50,000 mg/kg) does not classify it, and the value is hidden. This also feeds Section 14 Class 6.1. (2) For a liquid with carbon black or TiO2, the finding is stripped, and Section 11 prints 'No components present at or above 0.1% are listed…' although an IARC 2B substance is present.

**Suggested fix:** Classify acute toxicity by ATE additivity where ATE data exists (App. A.1), using cut-offs only otherwise. Keep the carbon black listing with a note that the hazard applies only to inhalable dust.

## 42. Physical state and appearance: blank state defaults to 'Liquid', appearance can contradict state, 'Negligible' scores as insoluble

- **Severity:** medium
- **Decision:** #17, #18(b), #18(d), #18(e)
- **Where:** `src/Services/FormulaCalcService.php:28-38, 520-567; src/Services/SDSGenerator.php:1954-1962, 2084-2089; migrations/054_physical_props_transport.sql:91-99`

(1) If the product and its largest raw material have no state, the sheet prints 'Liquid' without trying the next raw material, and Sections 6 and 8 give liquid wording for a powder. (2) Appearance takes the dominant raw material's text when the product has no colour, so a Paste product can print 'Appearance: Clear liquid'. (3) Migration 054 reset Soluble/Partially raw materials (water included) to 'Negligible', which weighs 0, so waterborne formulas print 'Not soluble in water'.

**Suggested fix:** Use the largest raw material that has a state, and warn when the hard-coded default is used. Build Appearance from colour and the product's state. Re-mark water and glycols as Soluble and give 'Negligible' a small non-zero weight, after confirming with the owner.

## 43. HazCom Appendix D content not printed (Section 9 properties, Section 11 routes and symptoms, Section 14(f)/(g))

- **Severity:** medium
- **Decision:** —
- **Where:** `src/Services/SDSGenerator.php:2108-2121, 2696-2703, 3136-3146; src/Services/PDFService.php:841-861, 994-1004`

Section 9 prints 11 values. pH, melting point, evaporation rate, flammability limits, vapour pressure and density, auto-ignition and decomposition temperatures, viscosity and partition coefficient are missing, not even as 'Not determined'. Section 11 has no routes of exposure or immediate-effects text (11(a)-(c)). Section 14 has no 'transport in bulk' or 'special precautions' lines. No decision covers these; the first audit flagged them.

**Suggested fix:** Add the missing Appendix D lines, printing 'Not determined' where no data exists, and derive the Section 11 routes and effects from the H-codes.

## 44. Section 14 extras: 60 °C exactly is not Class 3, boiling-point edit ignored, family name changes the shipping name

- **Severity:** medium
- **Decision:** #16, #27, #3
- **Where:** `src/Services/TransportClassifier.php:123, 88-90, 310-336; src/Services/SDSGenerator.php:3087`

(1) The code tests fp < 60, but 49 CFR 173.120 defines Class 3 as a flash point of 60 °C or less. (2) section14 passes formula_props.boiling_point_c only, so a Section 9 boiling-point edit does not change PG I. (3) resolveProductType substring-matches COATING/VARNISH/OPV/PAINT/WASH in the description plus the family name, so a family name can switch every member from UN1210 to UN1263 or UN1993, and resale sheets cannot be corrected.

**Suggested fix:** Use fp <= 60 for transport (keep < 60 for D001). Parse the boiling-point edit as the flash point is parsed. Drop the family name from the keyword text, or match on word boundaries.

## 45. Resale and private-label sheets: no transport override route, no preview warning, misleading block message

- **Severity:** medium
- **Decision:** #27
- **Where:** `src/Services/SDSGenerator.php:365-385, 3348-3367; src/Services/SDSReadinessService.php:401-411; src/Controllers/SDSController.php:117-181`

Resale sheets build $fg with id null, so getOverrides reads finished_good_id 0 and no Section 14 (or use-text) override can be stored. The resale and private-label previews do not show the Not-determined warning. The block message always blames a missing flash point and points to 'SDS > Edit Text', which is wrong for the flammable + corrosive + toxic case and for resale sheets.

**Suggested fix:** Key resale overrides by raw material, add the warning to every preview, and carry a reason code from the classifier into the message.

## 46. TSCA review edge cases: 'not listed' prints 'not verified'; inactive inventory entries count as listed

- **Severity:** medium
- **Decision:** #29
- **Where:** `src/Services/TSCAService.php:35-40, 97-112, 149-151`

The not_listed override value (verified absent) leads to 'TSCA inventory status has not been verified'. Entries with is_active_inventory = 0 are treated as listed with no warning.

**Suggested fix:** Add a distinct sentence for not_listed, and treat INACTIVE entries as a separate status that raises the warning.

## 47. Prop 65 type match is case-sensitive; manual HAP % uses only the last formula line

- **Severity:** medium
- **Decision:** #42
- **Where:** `src/Services/Prop65Service.php:200-203, 236-238; src/Services/SDSGenerator.php:4288-4295`

Prop65Service::analyse uses in_array('cancer'), so a 'Cancer' value leaves the chemical out of the warning, and legacy name-only entries are skipped. getManualHaps overwrites pctByRm for each line, so a raw material on several lines gets only the last line's %, which understates its HAP row and the Total HAP Content.

**Suggested fix:** Lower-case and trim the types in analyse(), and migrate the legacy entries. Sum the % per raw_material_id.

## 48. Legacy 'Default Product Use' settings pre-fill products and block family and translated text

- **Severity:** medium
- **Decision:** #3, #37
- **Where:** `src/Controllers/FinishedGoodController.php:51-52, 433-443; src/Views/admin/settings.php:83-94; src/Services/SDSGenerator.php:850-854`

FinishedGoodController::create pre-fills recommended_use and restrictions_on_use from the admin settings. Once saved, these language-less columns outrank the family default, so hand-created products never print their family's text and the English admin text prints on ES/FR/DE sheets. Migration 053 did not clear the values copied earlier.

**Suggested fix:** Remove the pre-fill and the two settings, and clear FG column values that equal the old defaults.

## 49. Resale raw-material sheets print the family's product text instead of the resale default

- **Severity:** medium
- **Decision:** #3
- **Where:** `src/Services/SDSGenerator.php:849-862, 369-391`

resolveUseText checks the family default before section1.*_resale. A raw material placed in a product family (for example a UV ink family) prints that ink's use text instead of 'Raw material for industrial formulation', and resale sheets have no override route to correct it.

**Suggested fix:** Skip the family tier for is_resale rows, or add a separate resale text on the family. Give resale sheets an override key.

## 50. UV text depends on name or CAS acrylate detection, not on family membership

- **Severity:** medium
- **Decision:** #35
- **Where:** `src/Services/UVAcrylateRulePack.php:49-58, 119-151; src/Services/SDSGenerator.php:174-182`

The Sections 4-7 and 11 notes and the Section 8 PPE sentences need detectAcrylates to hit one of 14 hard-coded CAS numbers or a name substring. A UV product whose reactive components have other names (urethane oligomer, trade names, the pooled 'Trade Secret' row) prints none of it, while Section 10 still prints the UV-light condition. 'acrylic' matches non-reactive acrylic resins. There is no concentration cut-off, so the Section 4 note can name acrylates that Section 3 does not list.

**Suggested fix:** Gate the generic UV text on the family flag, and use detection only for the names list (at the 0.1 % cut-off). Move the acrylate list into data, and narrow the 'acrylic' pattern.

## 51. '≤' prints as '?' in the Section 14 viscous-liquid note

- **Severity:** medium
- **Decision:** #27, #37
- **Where:** `templates/translations/en.php:429 (es/fr/de :402); src/Services/PDFService.php:273, 318`

The PDFs use the core Helvetica font, and TCPDF replaces characters outside cp1252 with '?'. Every Class 3 PG II sheet with the note reads '(packagings ? 450 L; ? 30 L passenger aircraft…)'.

**Suggested fix:** Replace '≤' with 'max.' or '<=' in all four translations, or embed a Unicode font.

## 52. IATA and IMDG print on every sheet but are never defined in Section 16

- **Severity:** medium
- **Decision:** #33
- **Where:** `src/Services/AbbreviationService.php:160-168; templates/translations/en.php:401, 460-508`

The Section 14 note always contains IATA and IMDG, and no abbreviation table defines them. collectCorpus case 14 also scans a non-existent 'ghs_note' instead of environmental_hazards, so abbreviations typed there are never defined.

**Suggested fix:** Add IATA and IMDG to all four abbreviation tables, and replace 'ghs_note' with 'environmental_hazards'.

## 53. Emailed and report alias PDFs are rebuilt from the base sheet's stored snapshot

- **Severity:** medium
- **Decision:** #32, #41, #1
- **Where:** `src/Controllers/SDSSendQueueController.php:243-253; src/Controllers/ReportController.php:1315-1352; src/Controllers/AdminController.php:906-957`

The send queue and the shipped-SDS ZIP report render the base FG's last snapshot under the alias code. They are not restamped, so the sheet shows the base FG's version and date, which identify no alias record. They print the raw code with its pack suffix (ABC123-5G) while the file name strips it. A base published before the audit brings back the old Sections 9 and 16 content, the English UV note and the old notes. The logo is not frozen with the snapshot, so a re-render prints today's logo or none.

**Suggested fix:** Send the alias's own published PDF (publish it first if needed), or regenerate and restamp with the alias's version. Apply strip_pack_extension consistently. Save logos under unique names.

## 54. Bulk private-label publish can duplicate or split a version number

- **Severity:** medium
- **Decision:** #32
- **Where:** `src/Controllers/BulkPublishController.php:671-677; scripts/publish-worker.php:194-245; migrations/051_private_label_items.sql:202`

Bulk publish computes MAX(version)+1 when it builds the work list. The worker then inserts each language row separately, with no re-check and no all-or-nothing rule, and the unique index is still commented out. A manual republish during a bulk run can produce two different 'Version n' sheets for one item and language. A failed language leaves a version that exists only in some languages.

**Suggested fix:** Route bulk PL items through PrivateLabelPublisher::publishOne (which re-checks the version in a transaction), run check-pl-duplicates.php, then add the unique index.

## 55. Manufacturer name can be saved blank, dropping the Company line from private-label sheets

- **Severity:** medium
- **Decision:** #1
- **Where:** `src/Models/Manufacturer.php:105-128; src/Services/SDSGenerator.php:701-702`

Manufacturer::create refuses a blank name, but update() checks only the emergency phone. A whitespace-only name passes the HTML 'required' attribute. The private-label sheet then omits the Section 1 Company line, the PDF Author becomes 'SDS System' and the file tag becomes 'PL_unnamed_file'.

**Suggested fix:** Add a non-blank name check to update(), make a blank name a private-label publish gate, and check trim(name) before sanitizing.

## 56. Blank or empty text-edit rows silently hide a field

- **Severity:** low
- **Decision:** #36, #4
- **Where:** `src/Services/SDSGenerator.php:924, 939, 1500, 1515, 1540, 1873, 3348-3367`

Sections 2 (Other Hazards), 5, 6 and 8 (Engineering) use '?? null' with no blank test. A stored '' row from the old editor blanks the field, and both renderers drop the label and value, while the editor shows the field as 'Automatic'. resolvePPE already trims, so the paths disagree. New saves never write blank rows.

**Suggested fix:** Ignore trimmed-empty rows in getOverrides, and have the cleanup script delete them.

## 57. Editor hints and the cleanup script ignore cross-fed fields

- **Severity:** low
- **Decision:** #36
- **Where:** `src/Controllers/SDSController.php:204-205, 278; src/Views/sds/edit.php:119, 159, 173, 181; scripts/cleanup-default-overrides.php:18-19`

edit() and saveEdits() generate with all overrides off, so the hints for 5.specific_hazards, 7.storage, 12.bioaccumulation and Section 14 can differ from what prints once a 9.flash_point, 10.incompatible or 12.persistence edit exists. Typing the hint text then stores nothing. The cleanup script's 'changes no printed SDS' claim is wrong for the same reason. The Section 2 hint describes automatic 'Other hazards' logic that does not exist. The Section 9 Flash Point hint says Section 5 always repeats the value.

**Suggested fix:** Compute each hint with all overrides except the field's own applied, use the same default in the cleanup, and correct the two hint texts.

## 58. Text edits, hazard overrides and settings changes do not mark published SDSs stale

- **Severity:** low
- **Decision:** #36
- **Where:** `src/Controllers/SDSController.php:251-324; src/Controllers/BulkPublishController.php:240-260`

saveEdits, saveHazardOverride, FG column edits and admin settings changes do not bump staleness. Bulk publish and auto-send never look at text_overrides, so these changes reach PDFs only through a manual publish or the 'bump all' button.

**Suggested fix:** Bump staleness for the product on these saves, and offer a bump after relevant settings changes.

## 59. Bulk publish stamps the UTC date captured at worker start; other publishers use local time

- **Severity:** low
- **Decision:** #32
- **Where:** `scripts/publish-worker.php:75-76, 194, 211; src/Controllers/SDSController.php:395, 432; src/Services/RegulatoryListBumper.php:215`

publish-worker uses gmdate('Y-m-d'), taken once, for FG, alias and resale items, and date() for private-label items. An evening US bulk run prints tomorrow's date on base sheets and today's on their private-label copies. published_at and the updated_at bumps also mix clocks, so freshness can be misjudged by the UTC offset.

**Suggested fix:** Stamp the printed effective date per item with local date(), and use one clock (UTC) for every published_at and updated_at.

## 60. Alias pack suffix and abbreviations handled inconsistently

- **Severity:** low
- **Decision:** #41, #33
- **Where:** `src/Services/SDSGenerator.php:592-604; src/Controllers/SDSController.php:1018-1023; src/Helpers/functions.php:278-282`

FG alias publishers pass the raw aliases.customer_code, so the footer and Title read 'BK1080-5G' while the file is BK1080_v3.pdf. Resale aliases strip the suffix. strip_pack_extension also cuts private-label custom codes such as 'ABC-123' at the first hyphen in file names. createAliasVariant does not rerun AbbreviationService, so alias-only terms go undefined.

**Suggested fix:** Decide whether the pack code belongs on the sheet and apply one rule everywhere, without stripping PL custom codes. Call AbbreviationService::build in createAliasVariant.

## 61. Inactive or legacy-manual families still drive Section 1 text and the UV flag

- **Severity:** low
- **Decision:** #3, #19, #35
- **Where:** `src/Services/SDSGenerator.php:874-892; src/Services/FamilyResolver.php:182, 255-300; migrations/053_product_families.sql:150-160`

attachFamily uses ProductFamily::findById, which has no is_active filter, and FamilyResolver keeps manual picks, so deactivated families keep printing their text and UV flag. Migration 053 marked every pre-existing family link as manual, so those products never inherit UV from content. UV status follows only the single dominant family: 40 % UV material split across two UV families loses to 30 % Solvent.

**Suggested fix:** Ignore inactive families in attachFamily and recompute on deactivation. Offer a bulk reset of legacy manual picks to Auto. Base is_uv on the total UV share.

## 62. Mixed-language output on ES/FR/DE sheets

- **Severity:** low
- **Decision:** #37
- **Where:** `src/Services/SDSGenerator.php:844-864, 1319, 3009, 3424, 743, 764; src/Services/PDFService.php:951-956; templates/translations/es.php:222, 227-229, 249; src/Services/SDSDocumentStrings.php:33-40`

Untranslated or English-only output: family text falls back to English before the translated default; '.' decimals in bands, flash points, TCLP limits and SARA/HAP figures; PubChem class names; chemical, trade-secret and raw-material appearance and odor text; exposure-limit types and notes; the SNUR text. The ES decomposition sentences lack the release clause. FR/DE Section 12 table headers overflow their cells. The US m/d/Y date is not recorded as a decision. Legacy snapshots fall back to an English PDF Title and Subject.

**Suggested fix:** Fall back to the translated default before family English text, format numbers by locale, canonicalise PubChem class names, fix the ES strings, wrap the table headers with MultiCell, and record or change the date format.

## 63. Old snapshots re-render with outdated or contradictory content

- **Severity:** low
- **Decision:** #28, #25
- **Where:** `src/Services/PDFService.php:400-404, 1167-1182`

Snapshots made before commit 178cbd0 have no is_classified key, so a re-render adds 'Not a hazardous substance or mixture.' above their hazards. Snapshots made before 9d8de70 still print the removed Section 13 note.

**Suggested fix:** When is_classified is absent, fall back to checking signal_word, pictograms and classes. Skip the old Section 13 note, or republish the affected products.

## 64. Remaining Section 4-8 wording gaps

- **Severity:** low
- **Decision:** #10, #12, #13, #14
- **Where:** `src/Services/SDSGenerator.php:1274-1280, 1509-1511, 1547-1550, 1691-1698, 1713-1771, 1898-1902, 1455-1458; src/Services/HazardEngine.php:1050-1068; src/Services/PDFService.php:788`

Sub-coded H-statements (H360D, H350i) get blank text and drop out of 4(b). Section 6 has no ignition wording for H227 or pyrophoric and water-reactive classes, and Gas states get the liquid dam-and-absorb text. Section 7 has no fragments for explosives, gases under pressure or STOT RE, prints 'Handle under inert gas.' twice for pyrophoric plus water-reactive products, pastes the S10 edit with mid-sentence capitals, and its storage edit cuts the link to Section 10. The Section 8 dust sentence prints for any Solid, and nothing prints when there are no exposure limits. Identical NIOSH and OSHA limits can print twice. Firefighter advice for explosive plus water-reactive products omits 'no water'.

**Suggested fix:** Fall back to the base code's text for sub-codes. Add the missing fragments and de-duplicate sentences. Lower-case the inline S10 list. Limit the dust sentence to Powder. Add a 'No exposure limits established' line. Drop notes from the OEL dedupe key.

## 65. UV notes in Sections 5-7 cannot be edited and can contradict the surrounding text

- **Severity:** low
- **Decision:** #35, #36
- **Where:** `src/Services/SDSGenerator.php:225-230, 2207-2209; src/Services/TextOverrideService.php:27-28`

uv_acrylate_note is not in EDITABLE_FIELDS, so it prints even after an operator replaces the section text. It repeats SCBA and drains sentences, says 'Absorb spills' on Solid products told to sweep, and Section 10 adds 'UV light' for UV-family products where Section 7 gives no UV storage advice.

**Suggested fix:** Make the note editable, or merge it into the section fragments with state-aware wording, and gate Sections 7 and 10 on the same condition.

## 66. Section 11-13 secondary wording issues

- **Severity:** low
- **Decision:** #20, #22, #23, #26
- **Where:** `src/Services/SDSGenerator.php:2299-2301, 2373-2430, 2730-2856, 2918-2920; src/Services/HazardEngine.php:1229-1239; templates/translations/en.php:380, 383`

'See Carcinogenicity below' can point to the negative sentence. A replace-mode override still gets the composition ATEmix appended, and Cat 5 codes print in Section 2 but show 'Not classified' in Section 11. A default M-factor prints '(M = 1)' as if it were supplier data. Section 12 names constituents below 0.1 % that Section 3 withholds. D002 is declared from H314 alone (including solids) and H290 is ignored. The D002 and '>' flash-point wording states missing data. The aquatic disposal wording was dropped.

**Suggested fix:** Fix each wording in place: conditional cross-reference, clear ateResults in replace mode, mark default M-factors, filter Section 12 to the Section 3 CAS set, skip D002 for solids and add H290, reword the data-gap phrases.

## 67. 'Replace' hazard override does not fully replace the classification

- **Severity:** low
- **Decision:** —
- **Where:** `src/Services/SDSGenerator.php:113-114, 3562-3585`

applyCarcinogenFindings and applyCarbonBlackLogic run after classify(), so H350/H351 and GHS08 can be added back to a product whose override mode is 'replace'.

**Suggested fix:** Skip the post-classify carcinogen and carbon-black steps in replace mode, or document that they still apply.

## 68. Supplier and emergency phone warnings incomplete

- **Severity:** low
- **Decision:** #1, #2
- **Where:** `src/Services/SDSGenerator.php:645, 3291-3302; src/Controllers/SDSController.php:117-176; cron/bulk-publish.php:76-81`

A blank company or manufacturer phone silently omits the Phone line that App. D 1(c) requires. The resale preview does not warn about a blank emergency phone. A blank company phone fails private-label items in bulk and in SDS Update, although they print the manufacturer's number. A PDF Author can show the config placeholder company name.

**Suggested fix:** Add readiness warnings for a blank supplier phone and an unset company name, add the emergency-phone warning to previewResale, and apply the company gate only to non-PL items.

## 69. Other publish-gate and admin loose ends

- **Severity:** low
- **Decision:** #40, #34, #32
- **Where:** `src/Controllers/SDSController.php:326-520; src/Services/SDSAutoSendService.php:30-33, 94-310; migrations/052_sds_audit_batch_a.sql:20-33; src/Controllers/PrivateLabelController.php:958-979; src/Services/SDSGenerator.php:374, 601, 821`

Inactive FGs can be published manually or through SDS Update. A blank missing-data threshold saves as 0 %. autoPublishReady, canAutoPublish and publishSds in SDSAutoSendService are never called, so the #40 auto-send gate rewire has no effect and the checklist describes blocking that does not happen. Migration 052 replaced the company's ES/FR/DE disclaimer text with no notice, and seeded a frozen EN copy. Disclaimer settings are cached for a whole worker run. A published PL record with no snapshot previews as 'Draft'. Blank descriptions print a dangling 'CODE — '.

**Suggested fix:** Check is_active on both paths. Validate the threshold (0.01-100). Delete the dead auto-send methods and correct the checklist. Add a checklist item for the disclaimers. Stamp regenerated PL previews. Fall back to the code when the description is blank.

## 70. Dead keys, dead code and unprinted snapshot payloads

- **Severity:** low
- **Decision:** #40, #42
- **Where:** `templates/translations/en.php:74, 95, 99, 116-128, 235-240, 355-357, 505-506; src/Services/SDSGenerator.php:192, 216-220, 544-548, 940, 1086, 1097; src/Controllers/SDSController.php:1152-1155`

Unused translation keys remain in sections 5, 6, 7 (eleven keys), 10, 12 and 16. has_other_hazards, hasTradeSecrets() and the unused $composition loop remain in the code. Snapshots still store exact concentration_pct, the full hazard_result, voc_result extras, the carcinogen_result text, SARA/HAP exact % and the HAP total, and meta.generated_at. The exact HAP total under banded rows allows partial back-calculation.

**Suggested fix:** Use the old keys once in the second cleanup pass, then delete them. Strip the exact percentages and unprinted payloads from snapshots. Remove the dead code.

## 71. VOC is not normalised and missing data is silent

- **Severity:** low
- **Decision:** #18(a)
- **Where:** `src/Services/VOCCalculator.php:181-197, 582-641`

The VOC weight sum is not divided by the total line %, so a formula summing to 98 % understates VOC. A missing VOC counts as 0 and a missing SG as 1.0, with no warning.

**Suggested fix:** Normalise by the total %, and warn on raw materials with no VOC or SG.

## Verification follow-up ledger (fixer pass, 2026-10-09)

Defects confirmed by the post-implementation verifiers and fixed in the working tree (not committed). Each line: what was wrong, what changed, the DB-free test.

| Item | Status | Change | Test |
|---|---|---|---|
| ENGINE_VERSION 31 chars > sds_generation_trace.engine_version VARCHAR(30): every manual / resale / SDS Update / alias publish failed after the first language row (critical) | FIXED | `HazardEngine::ENGINE_VERSION` = `v1.8-flash-point-flammability` (29 chars) | HazardEngineFlammabilityTest (length guard); HazardEngineGoldenTest literal updated (live DB, lint only) |
| Additive FG override bringing a Flammable Liquids class / H224-H227 kept the flash-point-derived code (H225 + H227 on one sheet) (#9, Q3) | FIXED | `applyFinishedGoodOverride()` drops the source `flash_point` MIXTURE row and its codes when the override brings a Flammable Liquids class or H224-H227, adds the override class's default H/P-codes, pictogram and signal-word floor; new `dropLessSevereFlammableCodes()` keeps one of H224 > H225 > H226 > H227 | FlammableOverrideConsistencyTest §1 |
| Override category vs printed formula flash point (Sections 5 / 9 vs 2 / 13 / 14), additive or replace, no warning (#9, Q3) | FIXED (warning, not a block) | `SDSGenerator::flammabilityWarnings()` compares the final H-code category with the engine flammability block when no Section 9 Flash Point edit is kept and a formula flash point prints; preview / publish warning names both values | FlammableOverrideConsistencyTest §4 |
| Raw material with a flammable-liquid constituent but no flash point silently lowers the classification (#5, Q1/Q2) | FIXED (warning, publishing not blocked) | `classify()` returns `flammable_ingredients`; `flammabilityWarnings()` names every contributing raw with a blank flash point (liquids only) | FlammableOverrideConsistencyTest §4 |
| Section 3 credited the mixture flash-point code to contributing solvents (ethanol shown as H227) (#37, Q3) | FIXED | `buildCasHCodeMap()` and `TransportClassifier::casHCodeMap()` skip the source `flash_point` MIXTURE entry; each flammable ingredient gets its OWN H224-H227 in the per-CAS map only (never Section 2) | FlammableOverrideConsistencyTest §2 |
| CPD / trade-secret signal word fell back to the ignored Flammable Liquids entry's word (Lactation-only or free-text classes) (Q3) | FIXED | `parseDeterminationStructure()`: no fallback when every triggered class re-derives; free-text classes derive from the kept H-codes; declared word kept only when nothing can be derived (legacy) | FlammableOverrideConsistencyTest §3 |
| TRI PFAS not treated as chemicals of special concern (no de minimis since the 2023 TRI rule) (#26) | FIXED; CONFIRM PFAS LIST WITH REGULATORY STAFF | Migration 058 §26h: guarded `sara313_list.is_special_concern`, explicit 202-CAS TRI PFAS list + every PBT row, RM bump before flagging; `SARA313Service` drops the de minimis for is_pbt OR is_special_concern and returns `is_special_concern`; Section 15 prints new label `sara_313_special_concern_no_deminimis` (en/es/fr/de, PDF + preview); seed CSV column `special_concern` read by load-seed-data.php and importFromCsv (blank = flag kept); checklist query | SARA313ServiceTest, SDSGeneratorSection15Test |
| TRI N100 copper compounds excluded only three CAS; chlorinated PB 15:1 / 15:2 printed as "Copper compounds" (#26) | FIXED | `RegulatoryCategoryService::isExcludedCopperPhthalocyanine()` (H/Cl/Br-only C32/N8 Cu core, or name-only phthalocyanine without sulfo/amino/methyl wording) applied to N100 element / keyword hits; 058 exclude row 12239-87-1 and corrected N100 source_ref | RegulatoryCategoryServiceTest |
| H290 (Met. Corr. 1) never made Class 8: Section 14 "Not regulated" next to Section 13 D002 (#27, Q3) | FIXED | `TransportClassifier`: `METAL_CORR_CODES = ['H290']`; H290 on a liquid (not Solid / Powder / Paste) is Class 8, PG III when no H314 (173.137(c)(2)); H290 constituents eligible as Class 8 technical names | TransportClassifierTest §w |
| #7: a complete Section 14 override could not be saved when class / PG equalled the derived values (publish blocked) | FIXED | `TextOverrideService::section14CoreGroupActive()`; `plan()` keeps every non-blank core field while any core field differs from its default; pass-1 cleanup keeps them too; editor help text | SDSGeneratorSection14Test (plan → section14 round trip) |
| #6: bulk worker gated transport per item language, so one language could publish alone and the source then looked current | FIXED | `publish-worker.php`: per source (cached), every configured language generated and gated; any failure fails every item of that source | PublishGatesTest (source check) |
| Q13 "Reset legacy manual picks to Auto" keyed on `fg.updated_at <= 053 applied_at`; 059 #48 and ProductStaleness bumps hid legacy picks (4 duplicate findings + test gap) | FIXED | Migration 059: `fg_legacy_family_picks` snapshot taken ABOVE the #48 updated_at bump (once, before 059 is recorded); `legacyManualFinishedGoodIds()` joins it on the same family_id; reset clears its rows; `FamilyResolver::isLegacyManualPick()`; checklist count query | FamilyResolverTest (Q13 block) |
| Alias publish gate message overflowed `sds_send_queue.reason` VARCHAR(500) and stalled auto-send | FIXED | `SDSAutoSendService::clampReason()` applied in `queueForReview()` (multibyte safe, 500 chars) | PublishGatesTest |
| Resale SDS text edits (incl. Section 14) never marked the resale sheet stale (#45 / #58) | FIXED | Migration 059 table `resale_sds_text_edits` (UTC); `saveEditorPost('rm')` stamps it when the plan changes something; `computeEligibleResaleItems()` folds it in; resale flash note | PublishGatesTest (source checks) |
| Section 12 omitted PBTs below 0.1 % that Section 15 reports | FIXED | Section 12 PBT line names every is_pbt entry with conc > 0 | SDSGeneratorSections2_11_12Test (fixture now the real `reportable` shape) |
| PDF Section 3 clipped the translated trade-secret CAS label (ES/FR/DE) | FIXED | `renderSection3()` row height measures the CAS (and concentration) column | PDFSection3RowHeightTest |
| Resale composition lacked per-material `is_trade_secret` (#36(1) parity) | FIXED | `buildResaleComposition()` CAS path carries the flag (TRADE_SECRET bucket unchanged, as in Formula) | CompositionBandsTest |
| #70 dead translation keys (22 keys, Sections 5, 6, 7, 10, 12) | FIXED | Deleted from en/es/fr/de; tests compare literal legacy texts. Pass 2 reads scripts/data/legacy-override-texts.php only. Note: the ES/FR/DE texts of 6-8 of these keys as of HEAD are not in that data file (they were dead before they last changed, so never printed) | SDSGeneratorSection7Test, SDSGeneratorSection10Test, TranslationCompletenessTest |
| Checklist described the old D001 reason / combustible-note rules | FIXED | docs/post-update-checklist.md Section 13 / 14 items rewritten (also H290 Class 8, missing-flash-point warning); SDSGenerator d001 docblock | — |

Not changed: Section 14 "Not determined" blocking rules, owner decisions Q1-Q15. Live-DB suites (HazardEngineGoldenTest etc.) were linted only.
