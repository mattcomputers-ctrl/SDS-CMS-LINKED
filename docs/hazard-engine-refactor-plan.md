# Hazard Engine Refactor Plan

Staged compliance fixes to `HazardEngine::classify()` and its supporting data model.
Each phase is self-contained, independently shippable, and verified before the next begins.

## Why this exists

The current `HazardEngine` applies GHS cutoffs per-component only. It lacks:

1. Summation/additivity across components sharing a hazard class (`#1`).
2. Real physical-hazard classification — flat 1% default for "Flammable", no handling for oxidizer / pyrophoric / explosives / compressed gas (`#2`).
3. M-factors for aquatic hazards (`#3`).
4. Dynamic trace threshold below 0.01% for high-M-factor substances (`#4`).
5. Threshold enforcement on CPD-sourced hazards — they fire at any concentration ≥ 0.01% (`#5`).
6. Canonical class-name matching — fuzzy `stripos` with silent naming inconsistencies (`#6`).

## Phase ordering

| Phase | Item | Rationale |
|---|---|---|
| 0 | Pre-flight | Build diff harness + confirm scope before touching engine |
| 1 | #6 Canonical names | Foundation — every later phase keys off clean class names |
| 2 | #5 CPD thresholds | Small, localized change; validates canonical-name layer |
| 3 | #1 Summation | Biggest compliance win; depends on canonical names + CPD threshold behavior |
| 4 | #3 + #4 M-factors + trace | Coupled; seeds ECHA data and replaces hardcoded 0.01% gate |
| 5 | #2 Physical-hazard override | Deferred; rare use case, implemented as FG-level override mirroring CPD pattern |

## Guiding principles

- **Additive, not replacing.** Each phase layers on top of existing behavior. Old engine stays callable until diff harness proves parity for non-affected cases.
- **Trace everything.** Every new rule logs a step in `HazardEngine::$trace` with rule name, inputs, outcome. Makes audits defensible and debugging trivial.
- **Feature-flag per phase.** Each phase behind a setting (`settings.hazard_engine.use_summation`, etc.) so rollback is one row change.
- **Diff before cutover.** The diff harness (Phase 0) runs after every phase, comparing the refactored engine's classification of every published SDS against the snapshot that's already on file. Any delta is reviewed before the flag flips.
- **Conservative defaults.** When data is missing (M-factor, ATE, category), bias toward over-classification rather than under. Log the assumption.

---

## Phase 0 — Diagnostics & test harness

**Goal:** Know what we're changing, prove we can detect regressions, avoid surprises.

### Tasks

1. **`scripts/classify-diff.php`** — CLI that iterates every `sds_versions` row where `status='published' AND is_deleted=0`, runs `HazardEngine::classify()` on its snapshot composition, and compares the result to `snapshot_json->hazards`. Outputs a CSV per run: `sds_version_id, product_code, diff_type, old_value, new_value`. Modes: `--engine-version=current|refactored`, `--phase=1|2|3|4|5`.

2. **`tests/Services/HazardEngineGoldenTest.php`** — 20–30 curated formulas with hand-verified expected classifications. Covers:
   - Single-component health hazard (triggers vs. below cutoff).
   - Multiple sub-threshold carcinogens (tests summation once #1 lands).
   - CPD-sourced classification at low + high concentration.
   - Aquatic substance with M-factor (once #3 lands).
   - Trade-secret RM with manual hazard JSON.
   - Flammable liquid (tests Phase 5 override once it lands).
   The golden set gets expanded each phase with new scenarios.

3. **Scope diagnostic: physical hazards currently firing?** Run a one-off query:
   ```sql
   SELECT COUNT(*) FROM sds_versions
   WHERE is_deleted = 0
     AND JSON_SEARCH(snapshot_json, 'one', 'Flammable%', NULL, '$.hazards.hazard_classes[*].class') IS NOT NULL;
   ```
   And similar for Oxidizing, Pyrophoric, Explosive. If zero: confirms Phase 5 is low priority.

4. **`engine_version` field.** Add to `sds_generation_trace` and stamp every new classification with the refactor-phase version (e.g., `"v2.1-summation"`). Lets future audits know which ruleset produced a classification.

### Deliverables
- `scripts/classify-diff.php`
- `tests/Services/HazardEngineGoldenTest.php` (initial 10 cases; expanded in every subsequent phase)
- `migrations/036_add_engine_version.sql` — add `engine_version VARCHAR(20)` to `sds_generation_trace`
- Diagnostic report (markdown) on physical-hazard incidence in published SDSs

**Risk:** Very low. Pure additions, no classification logic changes.

---

## Phase 1 — #6 Canonical hazard class names

**Goal:** One canonical identifier per GHS hazard class, used everywhere. No more `stripos` bidirectional matching.

### New files

**`src/Services/GHSHazardClass.php`** — constants only. Every class the engine touches:

```php
final class GHSHazardClass
{
    // Physical
    public const EXPLOSIVES = 'explosives';
    public const FLAMMABLE_GASES = 'flammable_gases';
    public const FLAMMABLE_AEROSOLS = 'flammable_aerosols';
    public const FLAMMABLE_LIQUIDS = 'flammable_liquids';
    public const FLAMMABLE_SOLIDS = 'flammable_solids';
    public const SELF_REACTIVE = 'self_reactive';
    public const PYROPHORIC_LIQUIDS = 'pyrophoric_liquids';
    public const PYROPHORIC_SOLIDS = 'pyrophoric_solids';
    public const SELF_HEATING = 'self_heating';
    public const OXIDIZING_LIQUIDS = 'oxidizing_liquids';
    public const OXIDIZING_SOLIDS = 'oxidizing_solids';
    public const OXIDIZING_GASES = 'oxidizing_gases';
    public const GASES_UNDER_PRESSURE = 'gases_under_pressure';
    public const CORROSIVE_TO_METALS = 'corrosive_to_metals';
    public const WATER_REACTIVE = 'water_reactive';

    // Health
    public const ACUTE_TOXICITY_ORAL = 'acute_toxicity_oral';
    public const ACUTE_TOXICITY_DERMAL = 'acute_toxicity_dermal';
    public const ACUTE_TOXICITY_INHALATION = 'acute_toxicity_inhalation';
    public const SKIN_CORROSION_IRRITATION = 'skin_corrosion_irritation';
    public const EYE_DAMAGE_IRRITATION = 'eye_damage_irritation';
    public const RESPIRATORY_SENSITIZATION = 'respiratory_sensitization';
    public const SKIN_SENSITIZATION = 'skin_sensitization';
    public const GERM_CELL_MUTAGENICITY = 'germ_cell_mutagenicity';
    public const CARCINOGENICITY = 'carcinogenicity';
    public const REPRODUCTIVE_TOXICITY = 'reproductive_toxicity';
    public const STOT_SINGLE = 'stot_single';
    public const STOT_REPEATED = 'stot_repeated';
    public const ASPIRATION_HAZARD = 'aspiration_hazard';

    // Environmental
    public const AQUATIC_ACUTE = 'aquatic_acute';
    public const AQUATIC_CHRONIC = 'aquatic_chronic';
    public const OZONE_HAZARD = 'ozone_hazard';

    public const ALL = [
        self::EXPLOSIVES, /* ... */
    ];

    /** Display name for a canonical key. */
    public static function displayName(string $canonical): string { /* ... */ }

    /** GHS group: 'physical' | 'health' | 'environmental'. */
    public static function group(string $canonical): string { /* ... */ }
}
```

**`src/Services/HazardClassAliases.php`** — normalize function + alias table. Also normalizes **category strings** in the same pass, since the same fuzzy-match problem applies to "Category 1A (1A/1B)" vs "Cat 1A" vs "1A":

```php
final class HazardClassAliases
{
    private const ALIASES = [
        // Skin corrosion / irritation
        'skin corrosion/irritation'        => GHSHazardClass::SKIN_CORROSION_IRRITATION,
        'skin corr./irrit.'                => GHSHazardClass::SKIN_CORROSION_IRRITATION,
        'skin corr'                        => GHSHazardClass::SKIN_CORROSION_IRRITATION,
        'skin irrit'                       => GHSHazardClass::SKIN_CORROSION_IRRITATION,
        'skin irritation'                  => GHSHazardClass::SKIN_CORROSION_IRRITATION,

        // Eye damage / irritation
        'serious eye damage/irritation'    => GHSHazardClass::EYE_DAMAGE_IRRITATION,
        'serious eye damage/eye irritation'=> GHSHazardClass::EYE_DAMAGE_IRRITATION,
        'eye dam./irrit.'                  => GHSHazardClass::EYE_DAMAGE_IRRITATION,
        'eye irrit.'                       => GHSHazardClass::EYE_DAMAGE_IRRITATION,

        // Carcinogenicity
        'carcinogenicity'                  => GHSHazardClass::CARCINOGENICITY,
        'carc.'                            => GHSHazardClass::CARCINOGENICITY,
        'carcinogen'                       => GHSHazardClass::CARCINOGENICITY,

        // STOT
        'stot - single exposure'           => GHSHazardClass::STOT_SINGLE,
        'stot — single exposure'           => GHSHazardClass::STOT_SINGLE,
        'stot single exposure'             => GHSHazardClass::STOT_SINGLE,
        'stot se'                          => GHSHazardClass::STOT_SINGLE,
        'stot - repeated exposure'         => GHSHazardClass::STOT_REPEATED,
        'stot re'                          => GHSHazardClass::STOT_REPEATED,

        // Acute toxicity (split by route)
        'acute toxicity (oral)'            => GHSHazardClass::ACUTE_TOXICITY_ORAL,
        'acute tox. oral'                  => GHSHazardClass::ACUTE_TOXICITY_ORAL,
        'acute toxicity oral'              => GHSHazardClass::ACUTE_TOXICITY_ORAL,
        // ... dermal, inhalation ...

        // Environmental
        'hazardous to the aquatic environment (acute)'   => GHSHazardClass::AQUATIC_ACUTE,
        'aquatic acute'                                  => GHSHazardClass::AQUATIC_ACUTE,
        'hazardous to the aquatic environment (chronic)' => GHSHazardClass::AQUATIC_CHRONIC,
        'aquatic chronic'                                => GHSHazardClass::AQUATIC_CHRONIC,

        // ... full coverage for all 34 classes ...
    ];

    /**
     * Normalize a raw class name string to its canonical key.
     * Returns null if unmappable — caller logs and falls through.
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') return null;

        $key = mb_strtolower(trim($raw));
        $key = preg_replace('/[—–]/u', '-', $key);   // em/en dash -> hyphen
        $key = preg_replace('/\s+/', ' ', $key);     // collapse whitespace
        $key = str_replace(',', '', $key);

        if (isset(self::ALIASES[$key])) return self::ALIASES[$key];

        // Fallback: strip trailing categories ("cat 1", "cat 2a") and retry
        $stripped = preg_replace('/\s+cat(egory)?\s*[0-9a-d]+[a-d]?\s*$/i', '', $key);
        return self::ALIASES[$stripped] ?? null;
    }

    /** Get all known raw inputs for a canonical key (for QA / backfill). */
    public static function inputsFor(string $canonical): array { /* ... */ }

    /**
     * Normalize a raw category string to a canonical form.
     * Handles: "Category 1A (1A/1B)" -> "Cat 1A", "1A" -> "Cat 1A",
     * "Category 2" -> "Cat 2", "Cat. 3" -> "Cat 3", etc.
     * Preserves unknown formats untouched (Division 1.1, Type A, Lactation).
     */
    public static function normalizeCategory(?string $raw): string
    {
        if ($raw === null) return '';
        $key = trim($raw);
        if ($key === '') return '';

        // Division 1.1 / Type A / Lactation / Compressed - leave alone
        if (preg_match('/^(Division|Type|Lactation|Compressed|Liquefied|Refrigerated|Dissolved)/i', $key)) {
            return $key;
        }

        // "Category 1A (1A/1B)" -> capture first alphanumeric category
        if (preg_match('/Category\s*(\d+[A-C]?)/i', $key, $m)) {
            return 'Cat ' . strtoupper($m[1]);
        }

        // "Cat. 2" / "Cat 2" / "cat2" -> "Cat 2"
        if (preg_match('/Cat\.?\s*(\d+[A-C]?)/i', $key, $m)) {
            return 'Cat ' . strtoupper($m[1]);
        }

        // Bare "1A" / "2" / "3" -> "Cat 1A" / "Cat 2" / "Cat 3"
        if (preg_match('/^(\d+[A-C]?)$/i', $key, $m)) {
            return 'Cat ' . strtoupper($m[1]);
        }

        return $key; // Unrecognized — leave for caller to handle / log
    }
}
```

### Schema changes

**`migrations/037_add_canonical_class_name.sql`:**
```sql
ALTER TABLE `hazard_classifications`
    ADD COLUMN `class_name_canonical` VARCHAR(100) NULL AFTER `class_name`,
    ADD COLUMN `category_canonical`   VARCHAR(50)  NULL AFTER `category`,
    ADD INDEX `idx_hc_canonical` (`class_name_canonical`, `category_canonical`);

-- Backfill by running scripts/backfill-canonical-class-names.php
-- (PHP script, not pure SQL, because normalization logic lives in HazardClassAliases)
```

**`scripts/backfill-canonical-class-names.php`:**
- Iterates every row of `hazard_classifications`.
- Calls `HazardClassAliases::normalize($row['class_name'])` → `class_name_canonical`.
- Calls `HazardClassAliases::normalizeCategory($row['category'])` → `category_canonical`.
- Logs any row where class `normalize()` returned `null` — these are new variants that need alias-table entries. Exits non-zero if any unmapped; user must add aliases before rerunning.
- Also logs any category that came out of `normalizeCategory()` unrecognized (e.g., new special cases beyond Division/Type/Lactation) for review.
- Also applies the same normalization pass to `competent_person_determinations.determination_json` — parses the JSON, normalizes `hazard_classes` entries and `selected_hazards` keys, writes back. This catches CPD-sourced variants that may not appear in `hazard_classifications`.

### Engine refactor

In `src/Services/HazardEngine.php`:

1. Replace `HEALTH_CUTOFFS` keys with `GHSHazardClass::*` constants.
2. Replace `HAZARD_GROUP_ORDER` keys with canonical constants.
3. Rewrite `getCutoff()`:
   ```php
   private function getCutoff(string $canonicalClass, ?string $category): float
   {
       if (!isset(self::HEALTH_CUTOFFS[$canonicalClass])) {
           // Physical / environmental / unknown -> 1% default for now
           return 1.0;
       }
       $categories = self::HEALTH_CUTOFFS[$canonicalClass];
       $normCat = $this->normalizeCategory($category);
       return $categories[$normCat] ?? min($categories);
   }
   ```
4. Classification loop reads `class_name_canonical` and `category_canonical` directly from the DB (no more in-engine `stripos`). For data sources that don't pre-normalize (CPDs parsed at runtime, trade-secret manual JSON), call `HazardClassAliases::normalize()` / `normalizeCategory()` at read time. If class returns null, log `class_name_unmapped` trace step and fall through with default cutoff.
5. `GHSHazardData::HAZARD_CLASSIFICATIONS` (used by CPDs) re-keyed by canonical constants.
6. `categoryToSeverity()` ([existing method, line 820](SDS-System/src/Services/HazardEngine.php:820)) can be simplified once categories are pre-normalized — keeps the regex fallback for Division/Type/Lactation cases but the common "Category N[A-C]" path becomes an exact lookup.

### Tests

**`tests/Services/HazardClassAliasesTest.php`:**
- 50+ `normalize()` cases: every alias in the table, plus real vendor-SDS variants (case variations, whitespace, punctuation, dashes).
- 20+ `normalizeCategory()` cases: "Category 1A (1A/1B)" → "Cat 1A", "cat. 2" → "Cat 2", "1A" → "Cat 1A", "Division 1.1" → "Division 1.1" (passthrough), "Lactation" → "Lactation" (passthrough), empty/null → "".
- `normalize('')` and `normalize(null)` return null.
- Unmapped strings return null.

**`tests/Services/HazardEngineTest.php`** (expand existing):
- Same classifications with raw class-name variations produce identical results.
- Golden-set cases still pass after refactor.

### Verification
- Diff harness run over all 14,616 SDSs. **Expected: zero diffs.** If there are diffs, they're rows where the old fuzzy match got it wrong; review each.
- Backfill script reports zero unmapped rows.

### Risk
Low. Pure refactor of lookup layer. Diff harness catches any behavior change.

### Files touched
- New: `src/Services/GHSHazardClass.php`, `src/Services/HazardClassAliases.php`
- Modified: `src/Services/HazardEngine.php`, `src/Services/GHSHazardData.php`
- New: `migrations/037_add_canonical_class_name.sql`, `scripts/backfill-canonical-class-names.php`
- Tests: `tests/Services/HazardClassAliasesTest.php`

---

## Phase 2 — #5 CPD threshold enforcement

**Goal:** CPDs contribute at or above their hazard-class cutoff, not at any concentration ≥ 0.01%.

### Engine changes

In `HazardEngine::parseDeterminationStructure()`:

1. After parsing `selected_hazards` into hazard-class entries, filter by cutoff:
   ```php
   foreach ($selectedHazards as $key) {
       if (!isset($ghsData[$key])) continue;
       $entry = $ghsData[$key];
       $canonical = HazardClassAliases::normalize($entry['class']); // canonical from Phase 1
       $cutoff = $this->getCutoff($canonical, $entry['category']);

       if ($conc < $cutoff) {
           $this->traceStep('cpd_below_cutoff', "CPD {$cas} below cutoff for {$entry['class']} {$entry['category']}", [
               'cas' => $cas, 'class' => $entry['class'], 'category' => $entry['category'],
               'concentration' => $conc, 'cutoff' => $cutoff,
           ]);
           continue;
       }

       $hazardClasses[] = [
           'class'             => $entry['class'],
           'category'          => $entry['category'],
           'canonical'         => $canonical,
           'cas'               => $cas,
           'chemical'          => $name,
           'concentration_pct' => $conc,
           'cutoff_pct'        => $cutoff,   // was always 0 — now real
           'source'            => $source,
       ];
       // ...
   }
   ```

2. **Categoryless CPD entries** (`hazard_classes` free-text without category): apply the most conservative cutoff for the canonical class (`min($categories)`). Log `cpd_conservative_cutoff` trace.

3. **H/P-statements and pictograms from the CPD are only added to the mixture output if at least one hazard-class entry triggered.** This prevents a CPD that declares `h_statements: "H350"` from contributing H350 to the SDS when the carcinogen cutoff isn't met.

### Schema changes
None.

### Tests

Add golden cases:
- CPD with Carc Cat 1A at 0.05% → no contribution (below 0.1% cutoff), `cpd_below_cutoff` trace.
- Same CPD at 0.15% → triggers, H350 and GHS08 appear in output.
- CPD with `hazard_classes: "Skin Corrosion"` (no category) at 0.5% → applies 1% most-conservative cutoff, no trigger.
- CPD contributing exposure limits still contributes them even when hazard below cutoff (exposure limits are for Section 8, independent of classification).

### Verification
- Diff harness. **Expected diffs:** SDSs where CPD-sourced CASes sit at low concentration will lose some H-codes / pictograms. Review the diff manually. Any SDS with surprising loss is a compliance *correction*, not a regression.
- Competent person reviews the diff list and signs off.

### Risk
Medium. Real classification changes. Diff harness is the safety net. Gate behind `settings.hazard_engine.enforce_cpd_cutoffs` so rollback is instant.

### Files touched
- Modified: `src/Services/HazardEngine.php` (parseDeterminationStructure, applyCASDetermination caller)
- Tests: expanded golden set in `tests/Services/HazardEngineGoldenTest.php`

---

## Phase 3 — #1 Summation / additivity

**Goal:** Multiple sub-threshold components of the same hazard class sum to trigger mixture classification per GHS Annex I / 29 CFR 1910.1200 App A.

### New data

**`SUMMATION_RULES` constant in `HazardEngine`**, keyed by canonical class:

```php
/** Summation thresholds: sum of concentrations of components in (class, category) ≥ threshold → mixture classified. */
private const SUMMATION_RULES = [
    GHSHazardClass::SKIN_CORROSION_IRRITATION => [
        'Cat 1' => 5.0,   // Sum Cat 1 >= 5% -> Cat 1
        'Cat 2' => 10.0,  // Sum Cat 1 1-5% OR Sum Cat 2 >= 10% -> Cat 2 (handled via rollup logic below)
    ],
    GHSHazardClass::EYE_DAMAGE_IRRITATION => [
        'Cat 1'  => 3.0,
        'Cat 2A' => 10.0,
    ],
    GHSHazardClass::CARCINOGENICITY => [
        'Cat 1A' => 0.1,
        'Cat 1B' => 0.1,
        'Cat 2'  => 0.1,  // OSHA HazCom — 1.0% under CLP; US compliance takes the stricter value
    ],
    GHSHazardClass::GERM_CELL_MUTAGENICITY => [
        'Cat 1' => 0.1,
        'Cat 2' => 1.0,
    ],
    GHSHazardClass::REPRODUCTIVE_TOXICITY => [
        'Cat 1' => 0.3,   // OSHA HazCom classification trigger; 0.1% for SDS disclosure
        'Cat 2' => 3.0,
    ],
    GHSHazardClass::SKIN_SENSITIZATION => [
        'Cat 1' => 0.1,   // Conservative (liquid 0.1%, solid 1%)
    ],
    GHSHazardClass::RESPIRATORY_SENSITIZATION => [
        'Cat 1' => 0.1,
    ],
    GHSHazardClass::STOT_SINGLE => [
        'Cat 1' => 10.0,
        'Cat 2' => 10.0,
        'Cat 3' => 20.0,
    ],
    GHSHazardClass::STOT_REPEATED => [
        'Cat 1' => 10.0,
        'Cat 2' => 10.0,
    ],
    GHSHazardClass::ASPIRATION_HAZARD => [
        'Cat 1' => 10.0,  // Plus kinematic viscosity gate — see below
    ],
    // Acute toxicity: ATE formula, handled separately in computeATEMixture()
];
```

### Engine refactor

Add a post-loop summation pass in `classify()`:

```php
// After the per-component loop, before consolidateHazardClasses()
$summationContributions = $this->computeSummationContributions($composition, $hazardByCas, $cpdContributions);

foreach (self::SUMMATION_RULES as $canonicalClass => $categoryThresholds) {
    foreach ($categoryThresholds as $category => $threshold) {
        $sum = $summationContributions[$canonicalClass][$category] ?? 0.0;
        if ($sum < $threshold) continue;

        // Check if already fired per-component; skip duplicate add
        if ($this->alreadyClassified($allHClasses, $canonicalClass, $category)) continue;

        $this->traceStep('summation_triggered', "Summation rule fired", [
            'class'    => $canonicalClass,
            'category' => $category,
            'sum_pct'  => $sum,
            'threshold'=> $threshold,
            'contributors' => $summationContributions[$canonicalClass]['_contributors_' . $category] ?? [],
        ]);

        $allHClasses[] = [
            'class'             => GHSHazardClass::displayName($canonicalClass),
            'category'          => $category,
            'canonical'         => $canonicalClass,
            'cas'               => 'MIXTURE',  // Not a single CAS
            'chemical'          => 'Multiple components (summation rule)',
            'concentration_pct' => $sum,
            'cutoff_pct'        => $threshold,
            'source'            => 'summation',
        ];

        // Merge H-codes, P-codes, pictograms, signal word for this class/category
        $this->mergeClassDefaults($canonicalClass, $category, $allHStmts, $allPStmts, $allPictograms, $signalWord);
    }
}
```

`computeSummationContributions()` iterates all components (composition + CPD-sourced + trade-secret manual JSON) and builds:
```
[canonical_class][category] => sum_of_concentrations
[canonical_class]['_contributors_' . category] => [list of (cas, conc)]
```

### Acute toxicity ATE formula

GHS 3.1.3.6 mixture acute tox uses ATE (Acute Toxicity Estimate):
```
100 / ATE_mix = Σ (Ci / ATE_i)
```

**Schema change** — `migrations/038_add_ate_values.sql`:
```sql
ALTER TABLE `hazard_classifications`
    ADD COLUMN `ate_oral_mg_kg`       DECIMAL(12,4) NULL AFTER `signal_word`,
    ADD COLUMN `ate_dermal_mg_kg`     DECIMAL(12,4) NULL,
    ADD COLUMN `ate_inhalation_vapor_mg_l_4h` DECIMAL(12,4) NULL,
    ADD COLUMN `ate_inhalation_dust_mg_l_4h`  DECIMAL(12,4) NULL,
    ADD COLUMN `ate_inhalation_gas_ppm_4h`    DECIMAL(12,4) NULL;
```

**Vendor SDSs rarely carry LD50/LC50 numbers consistently**, so in practice the engine will almost always derive ATE from category via the GHS Table 3.1.2 converted values. The `ate_*` columns exist for the occasional vendor that provides real data and for CPDs that want to be more precise, but the category-derived path is the default. Every ATE computation logs its source in the trace (`ate_source: 'vendor'|'cpd'|'category_default'`) so the degree of estimation is visible in every SDS audit.

Category-converted defaults per GHS Table 3.1.2:
```php
private const ATE_CATEGORY_DEFAULTS = [
    GHSHazardClass::ACUTE_TOXICITY_ORAL => [
        'Cat 1' => 0.5, 'Cat 2' => 5.0, 'Cat 3' => 100.0, 'Cat 4' => 500.0, 'Cat 5' => 2500.0,
    ],
    // ... dermal, inhalation variants
];
```

CPDs may also carry ATE values in `determination_json` — reused if present.

New method `computeATEMixture(array $composition, string $route): ?array` — returns `['ate' => ..., 'category' => ...]` or null if insufficient data. Called once per route; result added to `$allHClasses` if a category is derivable.

### Schema changes
- `migrations/038_add_ate_values.sql` (above)
- Consider `migrations/039_seed_ate_values.sql` with ATE values from ECHA CLP Annex VI for known substances (optional; most will come from vendor data or CPD).

### Tests

Golden set additions:
- Three Cat 1A carcinogens at 0.04% each (total 0.12%) → triggers carcinogenicity Cat 1A via summation; `summation_triggered` trace.
- Same three at 0.02% each (total 0.06%) → no trigger.
- Mixture of Cat 1 (0.5%) and Cat 2 (8%) skin corrosives → summation rule for Cat 2 triggers at 10% combined when treated as Cat 2 rollup.
- Oral ATE: 50% of ATE=500 mg/kg + 50% of ATE=2500 mg/kg → ATE_mix = 833, Cat 4.
- Oral ATE with both components using category-default ATE (no vendor LD50): 40% Cat 3 (default 100) + 40% Cat 4 (default 500) → derived mix, trace shows `ate_source: 'category_default'` for both contributors.
- Component with vendor-supplied LD50 takes precedence over the category default; CPD-supplied value takes precedence over vendor.
- CPD-sourced classifications participate in summation (e.g., two CPDs each contributing 0.05% Cat 2 mutagen → sum 0.1%, not below the 1% Cat 2 threshold, no trigger; bump to three CPDs at 0.5% each → 1.5%, triggers).

### Verification
- Diff harness. **Expected diffs:** SDSs where multiple small hazardous components exist will gain new classifications. Review and confirm each is a compliance improvement.
- Competent person sign-off on diff list before flipping `settings.hazard_engine.use_summation = true`.

### Risk
Medium-high. New classifications may appear on existing SDSs, which affects label content and customer-facing SDS PDFs. Republication strategy (see cross-cutting section) matters here.

### Files touched
- Modified: `src/Services/HazardEngine.php`
- Tests: expanded golden set
- New: `migrations/038_add_ate_values.sql`

---

## Phase 4 — #3 + #4 M-factors + dynamic trace threshold

**Goal:** Correctly classify aquatic hazards per GHS Annex I 4.1.3.5.5 using M-factors, without truncating high-potency substances at the 0.01% gate.

### Schema changes

**`migrations/040_add_m_factors.sql`:**
```sql
ALTER TABLE `hazard_classifications`
    ADD COLUMN `m_factor_acute`   DECIMAL(10,4) NULL
        COMMENT 'Aquatic acute M-factor per GHS Annex I 4.1.3.4; NULL treated as 1.0',
    ADD COLUMN `m_factor_chronic` DECIMAL(10,4) NULL
        COMMENT 'Aquatic chronic M-factor per GHS Annex I 4.1.3.4; NULL treated as 1.0';

-- ECHA harmonized M-factor seed (see scripts/import-echa-m-factors.php)
```

### ECHA seed

**`scripts/import-echa-m-factors.php`:**
- Input: ECHA Annex VI Table 3.1 extract (CSV or direct from ECHA CHEM). A public dataset of ~300 substances with harmonized M-factors. Committed as `seeds/echa_m_factors.csv`.
- For each row (CAS, M-acute, M-chronic), upsert into `hazard_classifications` rows for that CAS — set M-factors on matching aquatic-hazard classifications.
- Creates a synthetic `hazard_source_records` entry with `source_name='echa_annex_vi'`.
- Idempotent — safe to rerun when ECHA publishes updates.

**CPDs can override:** `determination_json.m_factor_acute` and `m_factor_chronic`. Parsed in `parseDeterminationStructure()`.

### Engine changes

**Aquatic summation (GHS Annex I 4.1.3.5.5):**

```php
private function applyAquaticSummation(array $composition, array $hazardByCas, array $cpdContributions): array
{
    $triggers = [];

    // Acute Cat 1: sum(M × conc of Cat 1 Acute) >= 25%
    $sumAcute1 = 0.0;
    foreach ($composition as $c) {
        $m = $this->mFactorFor($c['cas_number'], 'acute');
        $conc = (float)$c['concentration_pct'];
        if ($this->isAquaticCat($c, 'acute', 'Cat 1')) {
            $sumAcute1 += $m * $conc;
        }
    }
    if ($sumAcute1 >= 25.0) {
        $triggers[] = ['class' => GHSHazardClass::AQUATIC_ACUTE, 'category' => 'Cat 1', 'sum' => $sumAcute1];
    }

    // Chronic Cat 1: sum(M × conc of Cat 1 Chronic) >= 25%
    // Chronic Cat 2: 10 × sum(M × Cat 1) + sum(Cat 2) >= 25%
    // Chronic Cat 3: 100 × sum(M × Cat 1) + 10 × sum(Cat 2) + sum(Cat 3) >= 25%
    // Chronic Cat 4: rapidly degradable + poorly water-soluble ≥ 25% sum — treat as aux rule

    // ... similar for chronic categories

    return $triggers;
}
```

Each trigger gets a `summation_triggered` trace step with `sum`, `m_factors_used`, `contributors` metadata.

**Dynamic trace threshold:**

```php
// Replace line 96 of current engine:
// if ((float) $component['concentration_pct'] >= 0.01) { ... }

// With:
$maxM = $this->maxMFactorFor($component['cas_number']); // returns max(m_acute, m_chronic, 1.0)
$threshold = 0.01 / $maxM;
if ((float) $component['concentration_pct'] < $threshold) {
    continue; // still skip, but threshold scales with potency
}
```

So an M=1000 substance is considered down to 0.00001% (= 0.1 ppm). Performance cost: negligible — only runs on aquatic-flagged components.

### Tests

Golden set additions:
- Substance with M_acute=100 at 0.3% → sum = 30, triggers Aquatic Acute Cat 1.
- Same substance at 0.2% → sum = 20, no trigger.
- Two substances: M=10 at 2% + M=1 at 6% → sum = 26, triggers Aquatic Acute Cat 1.
- Substance with M=1000 at 0.005% (below old 0.01% gate, above new dynamic threshold) → considered.
- CPD-supplied M-factor overrides ECHA-seeded value.
- Missing M-factor in vendor data → default M=1 applied, `m_factor_default_assumed` trace step.

### Verification
- Diff harness. **Expected diffs:** any SDS containing aquatic-hazardous CASes may gain GHS09 / new aquatic classifications. Review.
- Spot check: run a known-aquatic formula through the engine and verify against a manually-computed expected result.
- Ensure trace for every aquatic classification cites the M-factor source (ECHA, CPD, or default).

### Risk
Medium. Logic is scoped to aquatic classes. Feature flag: `settings.hazard_engine.use_m_factors`.

### Files touched
- Modified: `src/Services/HazardEngine.php`
- New: `scripts/import-echa-m-factors.php`, `seeds/echa_m_factors.csv`
- New: `migrations/040_add_m_factors.sql`
- Tests: expanded golden set

---

## Phase 5 — #2 Finished-good hazard override

**Goal:** Rare cases (physical hazards or classification corrections) where a competent person needs to explicitly set hazard data on a finished good. Health hazards from composition remain unchanged unless the override says otherwise.

### Schema changes

**`migrations/041_add_finished_good_hazard_override.sql`:**
```sql
ALTER TABLE `finished_goods`
    ADD COLUMN `hazard_override_json` JSON NULL
        COMMENT 'Competent-person override of computed hazards — see docs for schema',
    ADD COLUMN `hazard_override_mode` ENUM('none','additive','replace') NOT NULL DEFAULT 'none'
        COMMENT 'none=no override, additive=merge with computed, replace=use override only',
    ADD COLUMN `hazard_override_set_by` INT UNSIGNED NULL,
    ADD COLUMN `hazard_override_set_at` DATETIME NULL,
    ADD COLUMN `hazard_override_rationale` TEXT NULL,
    ADD CONSTRAINT `fk_fg_override_by` FOREIGN KEY (`hazard_override_set_by`)
        REFERENCES `users`(`id`) ON DELETE SET NULL;
```

**`hazard_override_json` schema** (reuses CPD determination shape for consistency):
```json
{
  "hazard_classes": [
    {"class": "Flammable Liquids", "category": "Category 3"}
  ],
  "h_statements": ["H226"],
  "p_statements": ["P210","P233","P280","P370+P378"],
  "pictograms": ["GHS02"],
  "signal_word": "Warning",
  "physical_properties": {
    "flash_point_c": 55.0,
    "aerosol": false,
    "compressed_gas": false,
    "kinematic_viscosity_mm2_s_40c": null
  }
}
```

### Engine changes

In `HazardEngine::classify()`, accept an optional second argument:

```php
public function classify(array $composition, ?array $finishedGoodOverride = null): array
{
    // ... existing logic ...

    if ($finishedGoodOverride !== null && ($finishedGoodOverride['mode'] ?? 'none') !== 'none') {
        $this->applyFinishedGoodOverride($finishedGoodOverride, $allHClasses, $allHStmts, $allPStmts, $allPictograms, $signalWord);
    }

    // ... continue with pictogram precedence, consolidation, etc. ...
}
```

**`additive` mode:** merge override hazard classes, H/P-codes, pictograms into computed output. Signal word promoted to `Danger` if override specifies it.

**`replace` mode:** discard computed hazard classes entirely; use only override. Used when the competent person says "we tested this product; its classification is X, ignore component-level computation." Health hazards computed from composition get replaced too — intentional, competent-person call.

Trace step `override_applied` records mode, rationale, who set it.

The existing label and SDS-rendering pipeline reads from the returned hazard structure, so pictograms/H-codes/P-codes flow through automatically with zero UI changes downstream.

### UI work

On `src/Views/finished-goods/edit.php` (or whatever the edit view is):

- New section "Hazard Classification Override" (collapsed by default, admin / competent-person role only).
- Mode dropdown: None / Additive / Replace.
- Checkbox grid of all GHS hazard classes + categories (sourced from `GHSHazardData::HAZARD_CLASSIFICATIONS`, same pattern as CPD form).
- Free-text H-codes, P-codes, pictograms (with validation).
- Signal word dropdown.
- Rationale textarea (required when mode ≠ none).
- "Set by" / "Set at" auto-filled on save.

`FinishedGoodController::update()` serializes the form into `hazard_override_json`, audit-logs the change.

### Tests

Golden set additions:
- FG with additive override "Flammable Liquids Cat 3" on a composition with only health hazards → final classification has both flammable and health pictograms.
- FG with replace override → computed health hazards gone, only override hazards remain.
- Override empty / mode=none → identical to no-override classification.

### Verification
- Diff harness run includes a check that FGs without overrides are byte-identical to pre-Phase-5 behavior.
- Manual QA: set an override on a test FG, publish a draft SDS, verify pictograms and H-codes appear correctly on the PDF.

### Risk
Low. Scoped behind FG-level flag; opt-in, not automatic. Diff harness guarantees no-override FGs unchanged.

### Files touched
- Modified: `src/Services/HazardEngine.php`
- Modified: `src/Controllers/FinishedGoodController.php`, `src/Models/FinishedGood.php`
- Modified: `src/Views/finished-goods/edit.php` (or `form.php`)
- New: `migrations/041_add_finished_good_hazard_override.sql`
- Tests: expanded golden set + controller tests

---

## Cross-cutting concerns

### Republication strategy

After each phase lands, existing `sds_versions` snapshots are frozen — they carry the classification from whatever engine version produced them. Options:

**A. Leave historic SDSs alone; apply new engine only to new drafts.**
Simplest, legally defensible (published SDS is an artifact of its time). Downside: the SDS library shows inconsistent classifications for identical products until each is manually republished.

**B. Bulk regenerate all active SDSs after each phase.**
Most consistent, but 14,616 regenerations per phase is significant churn. Customers receiving SDSs may see classifications change between shipments.

**C. Regenerate on next natural trigger (formula edit, stale-SDS refresh, customer request).**
Natural cadence, minimal disruption. Most SDSs will catch up within a few months.

**Recommended: C, with a `republish:all` CLI available for customers who need consistency faster.** Every regeneration stamps `snapshot_json.engine_version` so there's a clear audit trail.

### Audit & traceability

- Every classification writes to `sds_generation_trace` (existing table) — add `engine_version VARCHAR(20)` column so we know which ruleset produced it.
- `snapshot_json` carries the full trace. When a customer or auditor asks "why is this pictogram here?", the trace answers it.
- Each feature flag change is audited via existing admin settings audit.

### Feature flags

Add to settings (existing `settings` table):
- `hazard_engine.canonical_names_enabled` (Phase 1) — defaults true once Phase 1 validated.
- `hazard_engine.enforce_cpd_cutoffs` (Phase 2)
- `hazard_engine.use_summation` (Phase 3)
- `hazard_engine.use_m_factors` (Phase 4)
- `hazard_engine.allow_fg_override` (Phase 5)

Each flag can be toggled independently. Rollback = flip flag, no redeploy.

### Testing posture

- **Unit**: every new helper (`HazardClassAliases::normalize`, `computeSummationContributions`, `applyAquaticSummation`, `computeATEMixture`) has focused unit tests.
- **Golden**: `tests/Services/HazardEngineGoldenTest.php` grows each phase. 50+ scenarios by Phase 5.
- **Diff harness**: run after each phase, before each flag flip. Output reviewed by competent person.
- **Regression**: CI fails if any golden case changes. Deliberate changes require updating golden fixtures AND a competent person code-review.

### Competent-person review

Every phase's diff-harness output needs review by the OSHA-qualified competent person before the feature flag flips. This is the human safety net between "the tests pass" and "we're legally comfortable publishing this classification to customers."

### Rollback plan

For any phase:
1. Flip the relevant `hazard_engine.*` feature flag off in settings.
2. All new SDS drafts revert to prior-phase behavior.
3. Historic SDSs are unaffected (they were snapshotted with the engine version that produced them).
4. If schema changes cause issues, each migration has a documented rollback query in its comment header.

### Timing estimate

| Phase | Work estimate | Gating factor |
|---|---|---|
| 0 | 1–2 days | diff harness build + golden test seed |
| 1 | 3–5 days | exhaustive alias table coverage |
| 2 | 1–2 days | small code change, thorough tests |
| 3 | 5–7 days | summation + ATE; biggest single phase |
| 4 | 3–5 days | ECHA import + aquatic summation |
| 5 | 2–4 days | UI + controller + engine plumbing |

Assumes one engineer, not accounting for competent-person review cycles between phases.

### Resolved design decisions

1. **ATE data sources.** Vendor SDSs don't consistently provide LD50/LC50 values. The engine will almost always derive ATE from category via GHS Table 3.1.2 converted values. `ate_*` columns exist for the rare vendor or CPD that supplies real numbers. Every ATE computation logs its source (`vendor` / `cpd` / `category_default`) in the trace.
2. **Category normalization.** Phase 1 normalizes categories alongside class names. `category_canonical` column added to `hazard_classifications` in the same migration. `HazardClassAliases::normalizeCategory()` handles "Category 1A (1A/1B)" → "Cat 1A", "Cat. 2" → "Cat 2", bare "1A" → "Cat 1A", while leaving non-numeric forms (Division, Type, Lactation, Compressed) as passthroughs.
3. **Jurisdiction.** US-only for the foreseeable future. The `jurisdiction` column exists on `hazard_classifications` and `competent_person_determinations` (default `'US'`) but no non-US rows exist in any data path today — it's schema-level placeholder, not a feature. The engine ignores the column; none of the refactor phases change that. If you ever expand to EU/CLP or WHMIS, the threshold tables in this plan would need jurisdiction variants, but that's a separate future initiative.
