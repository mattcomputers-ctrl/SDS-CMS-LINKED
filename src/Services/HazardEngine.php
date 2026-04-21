<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * HazardEngine — GHS hazard determination for a finished-product composition.
 *
 * Takes the expanded CAS-level composition and applies GHS mixture
 * classification rules (OSHA HazCom 2012) to determine:
 *   - Overall hazard classes and categories
 *   - Aggregated H-statements, P-statements
 *   - Applicable pictograms
 *   - Signal word (Danger > Warning)
 *   - Exposure limits (PEL, TLV, REL)
 *   - Full decision trace for audit
 *
 * Classification follows the cut-off / concentration limit approach from
 * Annex I of GHS Rev.9 and 29 CFR 1910.1200 Appendix A.
 */
class HazardEngine
{
    /**
     * Ruleset identifier stamped onto every sds_generation_trace row.
     * Bumped each time the engine's classification logic changes so audits
     * can identify which version produced any given classification.
     * See docs/hazard-engine-refactor-plan.md for the version timeline.
     *
     * v1.1-canonical-names (Phase 1): class names and categories are now
     *   normalised via HazardClassAliases. Cutoffs and precedence rules
     *   unchanged — behaviour-identical for every cleanly-normalised input.
     *
     * v1.2-cpd-cutoffs (Phase 2): competent-person determinations now
     *   participate in the GHS cutoff check. A CPD-sourced hazard only
     *   contributes at concentrations ≥ its canonical class cutoff; the
     *   entire CPD contribution (hazard classes, H/P-codes, pictograms,
     *   signal word) is suppressed when no individual class triggers.
     *   Exposure limits are unaffected — they flow regardless for
     *   Section 8 reporting.
     *
     * v1.3-summation (Phase 3): additivity rules per GHS Annex I /
     *   29 CFR 1910.1200 App A. Multiple sub-threshold components of
     *   the same hazard class sum to trigger the mixture classification
     *   once their combined concentration clears the summation
     *   threshold (e.g. three Cat 1A carcinogens at 0.04% each sum to
     *   0.12% and trigger Carcinogenicity Cat 1A). Applies to health
     *   hazards only in this phase — physical hazards are test-based,
     *   aquatic hazards need M-factors (Phase 4), acute toxicity needs
     *   ATE formula (deferred). Per-component triggers still fire
     *   independently; summation is purely additive.
     *
     * v1.3b-cross-category (Phase 3b): cross-category summation per
     *   GHS Annex I 3.2.3 (Skin Corr/Irrit), 3.3.3 (Eye Dam/Irrit),
     *   3.8.3 (STOT-SE), 3.9.3 (STOT-RE). Sub-threshold Cat 1
     *   contributors count 10× toward Cat 2 (or Cat 2A) classification.
     *   Closes the gap where products with 1-5 % of Cat 1 skin
     *   corrosives previously escaped both Cat 1 and Cat 2 classification
     *   despite being legitimately hazardous per GHS rules.
     *
     * v1.3c-ate (Phase 3c): acute-toxicity mixture classification via
     *   the GHS Annex I 3.1.3.6 ATE harmonic-mean formula. For each
     *   of three routes (oral, dermal, inhalation) the engine computes
     *   100 / ATE_mix = Σ(Ci / ATE_i), falls the result back through
     *   the GHS Table 3.1.1 category ranges, and stamps the resulting
     *   acute-toxicity category on the mixture. ATE_i comes from
     *   vendor data when present, otherwise from the GHS Table 3.1.2
     *   category-default conversion values. Every contributor's ATE
     *   source (vendor / cpd / category_default) is logged in the trace.
     *
     * v1.4-aquatic-mfactor (Phase 4): aquatic-hazard summation with
     *   M-factor weighting per GHS Annex I 4.1.3.5.5. Each aquatic
     *   Cat 1 component contributes M × concentration to the mixture
     *   summation, so an M=100 substance at 0.5 % weighs as much as a
     *   standard Cat 1 substance at 50 %. Chronic categories 2 and 3
     *   chain Cat 1 contributors through 10× and 100× weights per the
     *   standard aquatic cross-category formulas. M-factors come from
     *   hazard_classifications.m_factor_{acute,chronic} (vendor /
     *   ECHA seed) or the CPD JSON; NULL is treated as 1.0 per GHS.
     *
     * v1.5-fg-override (Phase 5): finished-good hazard override support.
     *   classify() accepts an optional override descriptor sourced from
     *   finished_goods.hazard_override_json. Two modes:
     *     - additive:  merge override hazards, H/P-codes, pictograms,
     *                  signal word into the computed classification
     *     - replace:   discard composition-derived classification
     *                  entirely; use only the override payload
     *   The engine always applies pictogram precedence, consolidation,
     *   and PPE derivation on the merged result so downstream rendering
     *   sees a valid classification regardless of mode.
     *
     * v1.6-aquatic-summation-only: corrects a pre-refactor bug where
     *   aquatic classes were able to trigger per-component via the 1 %
     *   default cutoff fallback. GHS Annex I 4.1.3 specifies that
     *   aquatic mixture classification is purely additive via the
     *   M-factor-weighted summation formulas — there is no
     *   "component-at-X% triggers mixture" rule. The per-component
     *   trigger path is now skipped for aquatic classes, leaving
     *   applyAquaticSummation() as the only classifier for GHS09
     *   hazards. Net effect: some SDSs whose only aquatic exposure was
     *   a single Cat 2/3 component above 1 % will now correctly not
     *   classify as aquatic-hazardous unless the summation threshold
     *   is met.
     *
     * v1.6.1-cpd-code-filter: when a CPD declares multiple hazard
     *   classes with a single shared h_statements / pictograms list,
     *   and only some of those classes trigger at the component's
     *   concentration, the engine now filters the companion H-codes
     *   and pictograms to only those whose GHS-declared class is in
     *   the triggered set. Fixes SDSs that carried H411 + GHS09 with
     *   no environmental hazard class listed because the CPD declared
     *   Acute Tox + Aquatic but only Acute Tox fired. P-codes are left
     *   alone (many are cross-cutting across classes).
     */
    public const ENGINE_VERSION = 'v1.6.1-cpd-code-filter';

    /**
     * GHS summation thresholds per canonical class + category.
     * When the combined concentration of all components that share a
     * (canonical_class, category) classification meets or exceeds the
     * threshold, the mixture is classified at that (class, category) even
     * though no single component triggered its per-component cutoff.
     *
     * Values sourced from GHS Annex I / 29 CFR 1910.1200 App A. Physical
     * hazards (flammable, oxidizer, etc.) are test-based and not subject
     * to additivity. Aquatic hazards need M-factor weighting — Phase 4.
     * Acute toxicity uses the ATE formula — deferred to a follow-up.
     */
    private const SUMMATION_RULES = [
        GHSHazardClass::SKIN_CORROSION_IRRITATION => [
            'Cat 1'  => 5.0,
            'Cat 2'  => 10.0,
        ],
        GHSHazardClass::EYE_DAMAGE_IRRITATION => [
            'Cat 1'  => 3.0,
            'Cat 2A' => 10.0,
        ],
        GHSHazardClass::CARCINOGENICITY => [
            'Cat 1A' => 0.1,
            'Cat 1B' => 0.1,
            'Cat 2'  => 0.1,  // OSHA HazCom uses 0.1%; EU CLP uses 1.0%. OSHA stricter.
        ],
        GHSHazardClass::GERM_CELL_MUTAGENICITY => [
            'Cat 1' => 0.1,
            'Cat 2' => 1.0,
        ],
        GHSHazardClass::REPRODUCTIVE_TOXICITY => [
            'Cat 1' => 0.3,
            'Cat 2' => 3.0,
        ],
        GHSHazardClass::SKIN_SENSITIZATION => [
            'Cat 1' => 0.1,   // Conservative (0.1% for potent, 1.0% for others)
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
            'Cat 1' => 10.0,  // Full GHS rule also requires kinematic viscosity
                              // ≤ 20.5 mm²/s at 40 °C; not modelled here.
        ],
    ];

    /**
     * GHS Table 3.1.2 category-default ATE values per route.
     *
     * Used by the ATE mixture calculation when a component is known to be
     * acute-toxic at a given category but no vendor LD50/LC50 value is
     * available. The engine resolves ATE_i in this order:
     *
     *   1. Explicit vendor value on hazard_classifications.ate_* (if present)
     *   2. Explicit CPD value in determination_json.ate_*               (if present)
     *   3. Category-default from this table                            (fallback)
     *   4. Component skipped from the route's mixture calc
     *
     * Units: oral/dermal mg/kg; inhalation vapour & dust/mist mg/L over 4 h.
     * Cat 5 has no GHS-defined default for dermal / inhalation — entries
     * left out so callers know to skip those components from the calc.
     */
    private const CATEGORY_DEFAULT_ATES = [
        'oral' => [
            'Cat 1' => 0.5,
            'Cat 2' => 5.0,
            'Cat 3' => 100.0,
            'Cat 4' => 500.0,
            'Cat 5' => 2500.0,
        ],
        'dermal' => [
            'Cat 1' => 5.0,
            'Cat 2' => 50.0,
            'Cat 3' => 300.0,
            'Cat 4' => 1100.0,
        ],
        'inhalation_vapor' => [
            'Cat 1' => 0.05,
            'Cat 2' => 0.5,
            'Cat 3' => 1.5,
            'Cat 4' => 10.0,
        ],
        'inhalation_dust' => [
            'Cat 1' => 0.005,
            'Cat 2' => 0.05,
            'Cat 3' => 0.5,
            'Cat 4' => 1.5,
        ],
    ];

    /**
     * GHS Table 3.1.1 ATE → category ranges per route.
     *
     * Mapping (exclusive lower, inclusive upper bound):
     *   ATE_mix ≤ upper → category whose upper is smallest satisfying bound.
     *
     * Iterate in order (Cat 1 first) and pick the first category where
     * ATE_mix ≤ upper.
     */
    private const ATE_CATEGORY_RANGES = [
        'oral' => [
            ['category' => 'Cat 1', 'upper' => 5.0],
            ['category' => 'Cat 2', 'upper' => 50.0],
            ['category' => 'Cat 3', 'upper' => 300.0],
            ['category' => 'Cat 4', 'upper' => 2000.0],
            ['category' => 'Cat 5', 'upper' => 5000.0],
        ],
        'dermal' => [
            ['category' => 'Cat 1', 'upper' => 50.0],
            ['category' => 'Cat 2', 'upper' => 200.0],
            ['category' => 'Cat 3', 'upper' => 1000.0],
            ['category' => 'Cat 4', 'upper' => 2000.0],
            ['category' => 'Cat 5', 'upper' => 5000.0],
        ],
        'inhalation_vapor' => [
            ['category' => 'Cat 1', 'upper' => 0.5],
            ['category' => 'Cat 2', 'upper' => 2.0],
            ['category' => 'Cat 3', 'upper' => 10.0],
            ['category' => 'Cat 4', 'upper' => 20.0],
        ],
        'inhalation_dust' => [
            ['category' => 'Cat 1', 'upper' => 0.05],
            ['category' => 'Cat 2', 'upper' => 0.5],
            ['category' => 'Cat 3', 'upper' => 1.0],
            ['category' => 'Cat 4', 'upper' => 5.0],
        ],
    ];

    /**
     * Canonical class code per ATE route.
     */
    private const ATE_ROUTE_TO_CANONICAL = [
        'oral'             => GHSHazardClass::ACUTE_TOXICITY_ORAL,
        'dermal'           => GHSHazardClass::ACUTE_TOXICITY_DERMAL,
        'inhalation_vapor' => GHSHazardClass::ACUTE_TOXICITY_INHALATION,
        'inhalation_dust'  => GHSHazardClass::ACUTE_TOXICITY_INHALATION,
    ];

    /**
     * GHS Annex I 4.1.3.5.5 aquatic-hazard summation rules with
     * M-factor weighting. Each rule spells out per-category contributor
     * weights and the common 25 % summation threshold.
     *
     * Aquatic Acute Cat 1:
     *   Σ ( M_acute × C_Cat1 ) ≥ 25 %
     *
     * Aquatic Chronic Cat 1:
     *   Σ ( M_chronic × C_Cat1 ) ≥ 25 %
     *
     * Aquatic Chronic Cat 2 (cross-category):
     *   10 × Σ ( M_chronic × C_Cat1 )  +  Σ ( C_Cat2 )  ≥ 25 %
     *
     * Aquatic Chronic Cat 3 (cross-category):
     *   100 × Σ ( M_chronic × C_Cat1 )  +  10 × Σ ( C_Cat2 )  +  Σ ( C_Cat3 )
     *                                  ≥ 25 %
     *
     * Aquatic Chronic Cat 4 is not modelled — the GHS rule depends on
     * solubility and degradability data not tracked in the current
     * schema.
     */
    private const AQUATIC_SUMMATION_RULES = [
        'acute' => [
            'Cat 1' => [
                'threshold'    => 25.0,
                'contributors' => [
                    ['category' => 'Cat 1', 'weight' => 1.0, 'use_m_factor' => true],
                ],
            ],
        ],
        'chronic' => [
            'Cat 1' => [
                'threshold'    => 25.0,
                'contributors' => [
                    ['category' => 'Cat 1', 'weight' => 1.0, 'use_m_factor' => true],
                ],
            ],
            'Cat 2' => [
                'threshold'    => 25.0,
                'contributors' => [
                    ['category' => 'Cat 1', 'weight' => 10.0,  'use_m_factor' => true],
                    ['category' => 'Cat 2', 'weight' => 1.0,   'use_m_factor' => false],
                ],
            ],
            'Cat 3' => [
                'threshold'    => 25.0,
                'contributors' => [
                    ['category' => 'Cat 1', 'weight' => 100.0, 'use_m_factor' => true],
                    ['category' => 'Cat 2', 'weight' => 10.0,  'use_m_factor' => false],
                    ['category' => 'Cat 3', 'weight' => 1.0,   'use_m_factor' => false],
                ],
            ],
        ],
    ];

    /** Maps aquatic "route" to its canonical class code. */
    private const AQUATIC_ROUTE_TO_CANONICAL = [
        'acute'   => GHSHazardClass::AQUATIC_ACUTE,
        'chronic' => GHSHazardClass::AQUATIC_CHRONIC,
    ];

    /** DB column / CPD JSON field for M-factor per aquatic route. */
    private const AQUATIC_M_FACTOR_COLUMN = [
        'acute'   => 'm_factor_acute',
        'chronic' => 'm_factor_chronic',
    ];

    /**
     * Column name (on hazard_classifications / field name in CPD JSON) per
     * ATE route.
     */
    private const ATE_ROUTE_COLUMN = [
        'oral'             => 'ate_oral_mg_kg',
        'dermal'           => 'ate_dermal_mg_kg',
        'inhalation_vapor' => 'ate_inhalation_vapor_mg_l_4h',
        'inhalation_dust'  => 'ate_inhalation_dust_mg_l_4h',
    ];

    /**
     * GHS cross-category summation rules (Phase 3b).
     *
     * A handful of hazard classes let sub-threshold Cat 1 contributors
     * contribute to Cat 2 classification at a 10× weight. This is the
     * rule that catches "a product with 1-5 % of Cat 1 skin corrosive"
     * scenarios that would otherwise slip through both Cat 1 and Cat 2.
     *
     *   target_category triggers when
     *     sum over contributors of (weight × concentration) ≥ threshold
     *
     * Applies to:
     *   - Skin Corrosion/Irritation (GHS Annex I 3.2.3.3.2)
     *   - Eye Damage/Irritation (GHS Annex I 3.3.3.3.2)
     *   - STOT Single Exposure (GHS Annex I 3.8.3.4.5)
     *   - STOT Repeated Exposure (GHS Annex I 3.9.3.4.5)
     *
     * Carcinogenicity, Mutagenicity, Reproductive Toxicity, Sensitisation,
     * and Aspiration don't get cross-category treatment — their categories
     * are hierarchically independent or single-category.
     */
    private const CROSS_CATEGORY_SUMMATION_RULES = [
        GHSHazardClass::SKIN_CORROSION_IRRITATION => [
            'Cat 2' => [
                'threshold'    => 10.0,
                'contributors' => [
                    ['category' => 'Cat 1', 'weight' => 10.0],
                    ['category' => 'Cat 2', 'weight' => 1.0],
                ],
            ],
        ],
        GHSHazardClass::EYE_DAMAGE_IRRITATION => [
            'Cat 2A' => [
                'threshold'    => 10.0,
                'contributors' => [
                    ['category' => 'Cat 1',  'weight' => 10.0],
                    ['category' => 'Cat 2A', 'weight' => 1.0],
                ],
            ],
        ],
        GHSHazardClass::STOT_SINGLE => [
            'Cat 2' => [
                'threshold'    => 10.0,
                'contributors' => [
                    ['category' => 'Cat 1', 'weight' => 10.0],
                    ['category' => 'Cat 2', 'weight' => 1.0],
                ],
            ],
        ],
        GHSHazardClass::STOT_REPEATED => [
            'Cat 2' => [
                'threshold'    => 10.0,
                'contributors' => [
                    ['category' => 'Cat 1', 'weight' => 10.0],
                    ['category' => 'Cat 2', 'weight' => 1.0],
                ],
            ],
        ],
    ];

    /**
     * GHS concentration cut-offs for health hazards, keyed by canonical
     * GHSHazardClass code. Category keys match HazardClassAliases::normalizeCategory()
     * output ("Cat 1", "Cat 2A", etc.) so lookups are exact equality — no
     * more fuzzy stripos matching against display strings.
     *
     * Acute toxicity uses identical cutoffs for all three routes in Phase 1;
     * per-route ATE-weighted summation arrives in Phase 3b.
     */
    private const HEALTH_CUTOFFS = [
        GHSHazardClass::ACUTE_TOXICITY_ORAL       => ['Cat 1' => 0.1, 'Cat 2' => 0.1, 'Cat 3' => 0.1, 'Cat 4' => 1.0],
        GHSHazardClass::ACUTE_TOXICITY_DERMAL     => ['Cat 1' => 0.1, 'Cat 2' => 0.1, 'Cat 3' => 0.1, 'Cat 4' => 1.0],
        GHSHazardClass::ACUTE_TOXICITY_INHALATION => ['Cat 1' => 0.1, 'Cat 2' => 0.1, 'Cat 3' => 0.1, 'Cat 4' => 1.0],
        GHSHazardClass::SKIN_CORROSION_IRRITATION => ['Cat 1' => 1.0, 'Cat 2' => 10.0],
        GHSHazardClass::EYE_DAMAGE_IRRITATION     => ['Cat 1' => 1.0, 'Cat 2A' => 10.0],
        GHSHazardClass::SKIN_SENSITIZATION        => ['Cat 1' => 0.1],
        GHSHazardClass::RESPIRATORY_SENSITIZATION => ['Cat 1' => 0.1],
        GHSHazardClass::GERM_CELL_MUTAGENICITY    => ['Cat 1' => 0.1, 'Cat 2' => 1.0],
        GHSHazardClass::CARCINOGENICITY           => ['Cat 1A' => 0.1, 'Cat 1B' => 0.1, 'Cat 2' => 0.1],
        GHSHazardClass::REPRODUCTIVE_TOXICITY     => ['Cat 1' => 0.1, 'Cat 2' => 0.3],
        GHSHazardClass::STOT_SINGLE               => ['Cat 1' => 1.0, 'Cat 2' => 10.0, 'Cat 3' => 20.0],
        GHSHazardClass::STOT_REPEATED             => ['Cat 1' => 1.0, 'Cat 2' => 10.0],
        GHSHazardClass::ASPIRATION_HAZARD         => ['Cat 1' => 10.0],
    ];

    /** Signal word hierarchy. */
    private const SIGNAL_HIERARCHY = ['Danger' => 2, 'Warning' => 1];

    /** Pictogram hierarchy (higher overrides lower in same hazard group). */
    private const PICTOGRAM_PRIORITY = [
        'GHS01' => 9, // Exploding bomb
        'GHS05' => 8, // Corrosion
        'GHS06' => 7, // Skull
        'GHS02' => 6, // Flame
        'GHS04' => 5, // Gas cylinder
        'GHS03' => 4, // Flame over circle
        'GHS08' => 3, // Health hazard
        'GHS07' => 2, // Exclamation mark
        'GHS09' => 1, // Environment
    ];

    private array $trace = [];

    /**
     * Per-classify() summation accumulator.
     *
     * Structure:
     *   [canonical_class => [category_canonical => [
     *       ['cas' => ..., 'name' => ..., 'conc' => float,
     *        'source' => ..., 'triggered_directly' => bool], …
     *   ]]]
     *
     * Populated during the main component loop (and the CPD /
     * trade-secret fallbacks) with every hazard-class entry that
     * attaches to a component, whether or not it individually cleared
     * its per-component cutoff. applySummationRules() runs after the
     * loop and fires the mixture-level classification when the sum of
     * contributions for a (class, category) meets the GHS summation
     * threshold.
     */
    private array $summationBuffer = [];

    /**
     * Per-classify() acute-toxicity mixture accumulator.
     *
     * Structure:
     *   [route => [
     *       ['cas' => ..., 'name' => ..., 'conc' => float,
     *        'category' => ..., 'ate' => float|null,
     *        'ate_source' => 'vendor'|'cpd'|'category_default'], …
     *   ]]
     *
     * route ∈ {'oral', 'dermal', 'inhalation_vapor', 'inhalation_dust'}.
     * applyATECalculation() consumes this after the main loop, plugs the
     * contributors into the GHS Annex I 3.1.3.6 mixture formula, and
     * classifies the mixture per-route.
     */
    private array $ateBuffer = [];

    /**
     * Per-classify() aquatic-hazard accumulator.
     *
     * Structure:
     *   [route => [
     *       ['cas' => ..., 'name' => ..., 'conc' => float,
     *        'category' => 'Cat 1'|'Cat 2'|'Cat 3',
     *        'm_factor' => float, 'm_factor_source' => string,
     *        'source' => 'hazard_classification'|'cpd'|'manual_trade_secret'], …
     *   ]]
     *
     * route ∈ {'acute', 'chronic'}. applyAquaticSummation() consumes
     * this after the main loop and fires aquatic mixture classifications
     * via the GHS Annex I 4.1.3.5.5 weighted summation formulas.
     */
    private array $aquaticBuffer = [];

    /**
     * Run hazard classification for a composition.
     *
     * @param  array $composition  From Formula::getExpandedComposition()
     * @return array {
     *   hazard_classes: array,
     *   h_statements: array,
     *   p_statements: array,
     *   pictograms: string[],
     *   signal_word: string|null,
     *   exposure_limits: array,
     *   hazardous_cas: string[],
     *   ppe_recommendations: array,
     *   trace: array,
     * }
     */
    /**
     * @param array|null $finishedGoodOverride Optional Phase-5 FG-level
     *     override. Expected shape:
     *       [
     *         'mode'       => 'none' | 'additive' | 'replace',
     *         'hazards'    => [
     *             'hazard_classes' => [['class' => ..., 'category' => ...], …]
     *                                  // or ['selected_hazards' => [<GHSHazardData keys>]]
     *             'h_statements' => ['H226', …] | 'H226,H319,…',
     *             'p_statements' => ['P210', …] | 'P210,…',
     *             'pictograms'   => ['GHS02', …] | 'GHS02,…',
     *             'signal_word'  => 'Warning' | 'Danger' | null,
     *         ],
     *         'rationale'  => '…',  // informational; logged in trace
     *         'set_by'     => int|null,
     *         'set_at'     => 'YYYY-MM-DD HH:MM:SS'|null,
     *       ]
     */
    public function classify(array $composition, ?array $finishedGoodOverride = null): array
    {
        $this->trace = [];
        $this->summationBuffer = [];
        $this->ateBuffer = [];
        $this->aquaticBuffer = [];
        $db = Database::getInstance();

        $allHClasses   = [];
        $allHStmts     = [];
        $allPStmts     = [];
        $allPictograms = [];
        $signalWord    = null;
        $exposureLimits = [];
        $hazardousCas   = [];

        $this->traceStep('start', 'Beginning hazard classification', [
            'component_count' => count($composition),
        ]);

        // Batch-load all hazard classifications and exposure limits upfront
        // to avoid N+1 queries (one per CAS number).
        $casNumbers = [];
        foreach ($composition as $component) {
            if ((float) $component['concentration_pct'] >= 0.01) {
                $casNumbers[] = $component['cas_number'];
            }
        }
        $casNumbers = array_unique($casNumbers);

        $hazardByCas = [];
        $limitsByCas = [];

        if (!empty($casNumbers)) {
            $placeholders = implode(',', array_fill(0, count($casNumbers), '?'));

            $allHazardRows = $db->fetchAll(
                "SELECT hc.*
                 FROM hazard_classifications hc
                 JOIN hazard_source_records hsr ON hsr.id = hc.hazard_source_record_id
                 WHERE hc.cas_number IN ({$placeholders}) AND hsr.is_current = 1
                 ORDER BY hsr.retrieved_at DESC",
                $casNumbers
            );
            foreach ($allHazardRows as $row) {
                $hazardByCas[$row['cas_number']][] = $row;
            }

            $allLimitRows = $db->fetchAll(
                "SELECT el.*
                 FROM exposure_limits el
                 JOIN hazard_source_records hsr ON hsr.id = el.hazard_source_record_id
                 WHERE el.cas_number IN ({$placeholders}) AND hsr.is_current = 1",
                $casNumbers
            );
            foreach ($allLimitRows as $row) {
                $limitsByCas[$row['cas_number']][] = $row;
            }
        }

        foreach ($composition as $component) {
            $cas  = $component['cas_number'];
            $conc = (float) $component['concentration_pct'];
            $name = $component['chemical_name'];

            // Skip trace-level components
            if ($conc < 0.01) {
                continue;
            }

            // --- Trade-secret row: no CAS, manual hazard JSON(s) attached ---
            // The composition row was synthesized by Formula::getExpandedComposition
            // for one or more raw materials flagged `hazardous_no_cas = 1`.
            // Merge each contributing RM's hazard JSON the same way a CPD would.
            if (!empty($component['manual_hazard_json']) && is_array($component['manual_hazard_json'])) {
                $hasHazards = false;
                foreach ($component['manual_hazard_json'] as $detJson) {
                    if (!is_array($detJson) || empty($detJson)) {
                        continue;
                    }
                    $parsed = $this->parseDeterminationStructure(
                        $detJson,
                        $cas,        // '' for trade-secret rows
                        $name,       // 'Trade Secret'
                        $conc,
                        'manual (trade secret)'
                    );

                    if (empty($parsed['h_statements']) && empty($parsed['hazard_classes'])) {
                        continue;
                    }
                    $hasHazards = true;

                    foreach ($parsed['h_statements'] as $code => $stmt) {
                        $allHStmts[$code] = $stmt;
                    }
                    foreach ($parsed['p_statements'] as $code => $stmt) {
                        $allPStmts[$code] = $stmt;
                    }
                    foreach ($parsed['pictograms'] as $p) {
                        $allPictograms[$p] = true;
                    }
                    if ($parsed['signal_word'] !== null) {
                        $curPri = self::SIGNAL_HIERARCHY[$signalWord] ?? 0;
                        $newPri = self::SIGNAL_HIERARCHY[$parsed['signal_word']] ?? 0;
                        if ($newPri > $curPri) {
                            $signalWord = $parsed['signal_word'];
                        }
                    }
                    foreach ($parsed['hazard_classes'] as $hcEntry) {
                        $allHClasses[] = $hcEntry;
                    }
                    foreach ($parsed['exposure_limits'] as $el) {
                        $exposureLimits[] = $el;
                    }
                }

                if ($hasHazards) {
                    // Section 3 uses 'TRADE_SECRET' as the CAS key so grouping by
                    // trade_secret_description collapses multiple contributions.
                    $hazardousCas['TRADE_SECRET'] = true;
                    $this->traceStep('trade_secret_applied', 'Trade-secret RMs contributed manual GHS classifications', [
                        'concentration_pct' => $conc,
                        'rm_count'          => count($component['manual_hazard_json']),
                    ]);
                }
                continue; // Skip the CAS-based path — no CAS to look up
            }

            // Look up hazard data from pre-fetched batch results
            $hazardData = $hazardByCas[$cas] ?? [];

            // Exposure limits from pre-fetched batch results
            $limits = $limitsByCas[$cas] ?? [];

            foreach ($limits as $limit) {
                $entry = [
                    'cas_number'        => $cas,
                    'chemical_name'     => $name,
                    'concentration_pct' => $conc,
                    'limit_type'        => $limit['limit_type'],
                    'value'             => $limit['value'],
                    'units'             => $limit['units'],
                ];
                if (!empty($limit['notes'])) {
                    $entry['notes'] = $limit['notes'];
                }
                $exposureLimits[] = $entry;
            }

            // Note: CAS is only marked hazardous below when a GHS classification
            // actually triggers (concentration >= cutoff) or a CPD applies.

            // Fall back to CAS number determination if no federal data
            if (empty($hazardData)) {
                $cpdResult = $this->applyCASDetermination($db, $cas, $name, $conc);
                if ($cpdResult !== null) {
                    // Only mark as hazardous if the determination actually has
                    // hazard classifications; a CPD with nothing checked should
                    // not cause the CAS to appear in Section 3.
                    // As of Phase 2, parseDeterminationStructure already strips
                    // H/P-codes, pictograms, and signal word when no hazard
                    // class clears its cutoff — so hazard_classes is the sole
                    // authoritative "did anything trigger" flag. h_statements
                    // is no longer a valid tie-breaker.
                    $hasHazards = !empty($cpdResult['hazard_classes']);
                    if (!$hasHazards) {
                        $this->traceStep('cpd_no_hazards', "CAS {$cas} has determination but no hazard class cleared its cutoff", [
                            'cas' => $cas, 'concentration' => $conc,
                        ]);
                        // Still merge exposure limits even if not hazardous —
                        // Section 8 reporting is independent of Section 3
                        // classification.
                        foreach ($cpdResult['exposure_limits'] as $el) {
                            $exposureLimits[] = $el;
                        }
                        continue;
                    }

                    $hazardousCas[$cas] = true;
                    // Merge determination data
                    foreach ($cpdResult['h_statements'] as $code => $stmt) {
                        $allHStmts[$code] = $stmt;
                    }
                    foreach ($cpdResult['p_statements'] as $code => $stmt) {
                        $allPStmts[$code] = $stmt;
                    }
                    foreach ($cpdResult['pictograms'] as $p) {
                        $allPictograms[$p] = true;
                    }
                    if ($cpdResult['signal_word'] !== null) {
                        $curPri = self::SIGNAL_HIERARCHY[$signalWord] ?? 0;
                        $newPri = self::SIGNAL_HIERARCHY[$cpdResult['signal_word']] ?? 0;
                        if ($newPri > $curPri) {
                            $signalWord = $cpdResult['signal_word'];
                        }
                    }
                    foreach ($cpdResult['hazard_classes'] as $hcEntry) {
                        $allHClasses[] = $hcEntry;
                    }
                    // Exposure limits from determination
                    foreach ($cpdResult['exposure_limits'] as $el) {
                        $exposureLimits[] = $el;
                    }
                    $this->traceStep('cpd_applied', "CAS {$cas} uses CAS number determination", [
                        'cas' => $cas, 'h_count' => count($cpdResult['h_statements']),
                    ]);
                    continue;
                }

                $this->traceStep('no_data', "No hazard data for CAS {$cas} ({$name})", [
                    'cas' => $cas, 'concentration_pct' => $conc,
                ]);
                continue;
            }

            // Process each hazard classification
            foreach ($hazardData as $hc) {
                $className = (string) ($hc['class_name'] ?? '');
                $category  = (string) ($hc['category']   ?? '');

                // Prefer pre-backfilled canonical columns; fall back to runtime
                // normalisation for rows the backfill hasn't reached or that
                // arrived via a non-DB source.
                $canonical = $hc['class_name_canonical'] ?? null;
                if ($canonical === null || $canonical === '') {
                    $canonical = HazardClassAliases::normalize($className);
                }
                $categoryCanon = $hc['category_canonical'] ?? null;
                if ($categoryCanon === null || $categoryCanon === '') {
                    $categoryCanon = HazardClassAliases::normalizeCategory($category);
                }

                if ($canonical === null) {
                    $this->traceStep('class_name_unmapped', "CAS {$cas} class_name '{$className}' not in alias table; using default cutoff", [
                        'cas' => $cas, 'class_name' => $className, 'category' => $category,
                    ]);
                }

                // Check against GHS concentration cutoffs
                $cutoff = $this->getCutoff($canonical ?? '', $categoryCanon);

                // Feed the summation buffer regardless of whether this
                // component individually triggers — a sub-threshold entry
                // still counts toward the mixture-level summation check.
                $this->addToSummationBuffer(
                    $canonical, $categoryCanon ?? '', $cas, $name, $conc,
                    'hazard_classification', $conc >= $cutoff
                );

                // Phase 3c: feed the ATE buffer for acute-toxicity routes.
                // Acute tox contributes to the mixture calc regardless of
                // per-component trigger, using explicit vendor ATE when
                // present or category-defaults at resolve time.
                $ateRoute = $this->canonicalToAteRoute($canonical);
                if ($ateRoute !== null && $categoryCanon !== null && $categoryCanon !== '') {
                    $explicitAte = $this->resolveExplicitAte($hc, $ateRoute);
                    $this->addToAteBuffer(
                        $ateRoute, $cas, $name, $conc, $categoryCanon,
                        $explicitAte['value'], $explicitAte['source'],
                        'hazard_classification'
                    );
                }

                // Phase 4: feed the aquatic buffer with M-factor context.
                $aquaticRoute = $this->canonicalToAquaticRoute($canonical);
                if ($aquaticRoute !== null && $categoryCanon !== null && $categoryCanon !== '') {
                    $mFactor = $this->resolveMFactor($hc, $aquaticRoute);
                    $this->addToAquaticBuffer(
                        $aquaticRoute, $cas, $name, $conc, $categoryCanon,
                        $mFactor['value'], $mFactor['source'],
                        'hazard_classification'
                    );
                    // GHS Annex I 4.1.3: aquatic mixture classification is
                    // purely summation-based with M-factor weighting. There
                    // is no "single component at X% triggers mixture at this
                    // category" rule for aquatic like there is for health
                    // hazards — skip the per-component trigger path and let
                    // applyAquaticSummation() be the only path that can
                    // classify the mixture at an aquatic category.
                    $this->traceStep('aquatic_per_component_skipped', "CAS {$cas} aquatic — per-component trigger skipped; summation-only per GHS 4.1.3", [
                        'cas' => $cas, 'canonical' => $canonical, 'category' => $categoryCanon,
                        'concentration' => $conc,
                    ]);
                    continue;
                }

                if ($conc >= $cutoff) {
                    // Mark CAS as hazardous only when a GHS category actually triggers
                    $hazardousCas[$cas] = true;

                    $allHClasses[] = [
                        'class'              => $className,
                        'category'           => $category,
                        'canonical'          => $canonical,
                        'category_canonical' => $categoryCanon,
                        'cas'                => $cas,
                        'chemical'           => $name,
                        'concentration_pct'  => $conc,
                        'cutoff_pct'         => $cutoff,
                    ];

                    // Signal word
                    $sw = $hc['signal_word'] ?? null;
                    if ($sw !== null) {
                        $currentPriority = self::SIGNAL_HIERARCHY[$signalWord] ?? 0;
                        $newPriority     = self::SIGNAL_HIERARCHY[$sw] ?? 0;
                        if ($newPriority > $currentPriority) {
                            $signalWord = $sw;
                        }
                    }

                    // H-statements
                    $hStmts = json_decode($hc['h_statements_json'] ?? '[]', true);
                    if (is_array($hStmts)) {
                        foreach ($hStmts as $stmt) {
                            if (is_string($stmt)) {
                                $allHStmts[$stmt] = ['code' => $stmt, 'text' => ''];
                            } elseif (is_array($stmt) && isset($stmt['code'])) {
                                $allHStmts[$stmt['code']] = $stmt;
                            }
                        }
                    }

                    // P-statements
                    $pStmts = json_decode($hc['p_statements_json'] ?? '[]', true);
                    if (is_array($pStmts)) {
                        foreach ($pStmts as $stmt) {
                            if (is_string($stmt)) {
                                $allPStmts[$stmt] = ['code' => $stmt, 'text' => ''];
                            } elseif (is_array($stmt) && isset($stmt['code'])) {
                                $allPStmts[$stmt['code']] = $stmt;
                            }
                        }
                    }

                    // Pictograms
                    $pictos = json_decode($hc['pictograms_json'] ?? '[]', true);
                    if (is_array($pictos)) {
                        foreach ($pictos as $p) {
                            $allPictograms[$p] = true;
                        }
                    }

                    $this->traceStep('classified', "CAS {$cas} triggers {$className} {$category}", [
                        'cas' => $cas, 'class' => $className, 'category' => $category,
                        'canonical' => $canonical, 'category_canonical' => $categoryCanon,
                        'concentration' => $conc, 'cutoff' => $cutoff,
                    ]);
                } else {
                    $this->traceStep('below_cutoff', "CAS {$cas} below cutoff for {$className} {$category}", [
                        'cas' => $cas, 'class' => $className, 'category' => $category,
                        'canonical' => $canonical, 'category_canonical' => $categoryCanon,
                        'concentration' => $conc, 'cutoff' => $cutoff,
                    ]);
                }
            }
        }

        // Phase 3: apply GHS summation rules before precedence / consolidation.
        // Any (class, category) whose combined component concentrations clear
        // the summation threshold classifies the mixture, contributing default
        // H/P-codes / pictograms / signal word for that category.
        $this->applySummationRules(
            $allHClasses, $allHStmts, $allPStmts, $allPictograms, $signalWord, $hazardousCas
        );

        // Phase 3c: acute-toxicity mixture classification via ATE harmonic mean.
        // Runs per-route (oral, dermal, inhalation-vapour, inhalation-dust),
        // using vendor / CPD ATE values when present and GHS Table 3.1.2
        // category-default ATEs when not. Skipped per route when a
        // per-component trigger already classified the mixture on that route.
        $this->applyATECalculation(
            $allHClasses, $allHStmts, $allPStmts, $allPictograms, $signalWord, $hazardousCas
        );

        // Phase 4: aquatic-hazard mixture classification with M-factor
        // weighting. Each Cat 1 contributor's concentration is multiplied
        // by its M-factor before summation; cross-category Cat 2/3 chronic
        // rules chain Cat 1 through 10×/100× further multipliers.
        $this->applyAquaticSummation(
            $allHClasses, $allHStmts, $allPStmts, $allPictograms, $signalWord, $hazardousCas
        );

        // Phase 5: finished-good hazard override. Applied after every
        // composition-derived rule so additive mode layers on top and
        // replace mode can cleanly discard earlier contributions.
        if ($finishedGoodOverride !== null) {
            $this->applyFinishedGoodOverride(
                $finishedGoodOverride,
                $allHClasses, $allHStmts, $allPStmts, $allPictograms, $signalWord
            );
        }

        // Apply pictogram precedence rules
        $finalPictograms = $this->applyPictogramPrecedence(array_keys($allPictograms));

        // Consolidate hazard classes: keep only the most severe category per class,
        // then sort by GHS group order (physical > health > environmental) and severity
        $allHClasses = $this->consolidateHazardClasses($allHClasses);

        // Attach per-class H-codes to each surviving hazard_class entry so
        // renderers (PDF Section 2, HTML preview) can display the code(s)
        // inline with the class name — e.g. "Skin Sensitization (Category 1) — H317"
        // — rather than making operators cross-reference the flat H-statement
        // list at the bottom of the section.
        foreach ($allHClasses as &$hcRef) {
            $hcCanonical = (string) ($hcRef['canonical'] ?? '');
            $hcCategory  = (string) ($hcRef['category_canonical'] ?? '');
            if ($hcCanonical !== '' && $hcCategory !== '') {
                $defaults = $this->getDefaultsForClassCategory($hcCanonical, $hcCategory);
                $hcRef['h_codes'] = $defaults['h_codes'] ?? [];
            } else {
                $hcRef['h_codes'] = [];
            }
        }
        unset($hcRef);

        // Sort H and P statements by code
        $hStatements = array_values($allHStmts);
        $pStatements = array_values($allPStmts);
        usort($hStatements, fn($a, $b) => strcmp($a['code'], $b['code']));
        usort($pStatements, fn($a, $b) => strcmp($a['code'], $b['code']));

        // Resolve missing statement text from the GHS standard reference
        $hStatements = GHSStatements::resolveHStatements($hStatements);
        $pStatements = GHSStatements::resolvePStatements($pStatements);

        // Derive PPE recommendations from the classified H/P codes
        $ppeRecommendations = self::derivePPE($hStatements, $pStatements);

        $this->traceStep('complete', 'Hazard classification complete', [
            'hazard_class_count' => count($allHClasses),
            'h_statement_count'  => count($hStatements),
            'pictogram_count'    => count($finalPictograms),
            'signal_word'        => $signalWord,
            'hazardous_cas_count' => count($hazardousCas),
        ]);

        return [
            'hazard_classes'      => $allHClasses,
            'h_statements'        => $hStatements,
            'p_statements'        => $pStatements,
            'pictograms'          => $finalPictograms,
            'signal_word'         => $signalWord,
            'exposure_limits'     => $exposureLimits,
            'hazardous_cas'       => array_keys($hazardousCas),
            'ppe_recommendations' => $ppeRecommendations,
            'trace'               => $this->trace,
        ];
    }

    /**
     * Derive PPE recommendations from classified H-statements and P-statements.
     *
     * Maps hazard codes to specific PPE requirements for respiratory,
     * hand, eye, and skin/body protection.
     *
     * @param  array $hStatements  [['code' => 'H225', 'text' => '...'], ...]
     * @param  array $pStatements  [['code' => 'P210', 'text' => '...'], ...]
     * @return array  PPE recommendations keyed by protection type
     */
    public static function derivePPE(array $hStatements, array $pStatements): array
    {
        // Extract individual H-codes (split combined codes like H300+H310+H330)
        $hCodes = [];
        foreach ($hStatements as $s) {
            $code = $s['code'] ?? '';
            if ($code === '') {
                continue;
            }
            // Add the full combined code
            $hCodes[] = $code;
            // Also split into individual codes
            foreach (explode('+', $code) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $hCodes[] = $part;
                }
            }
        }
        $hCodes = array_unique($hCodes);

        // Extract individual P-codes
        $pCodes = [];
        foreach ($pStatements as $s) {
            $code = $s['code'] ?? '';
            if ($code === '') {
                continue;
            }
            $pCodes[] = $code;
            foreach (explode('+', $code) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $pCodes[] = $part;
                }
            }
        }
        $pCodes = array_unique($pCodes);

        $respiratory = null;
        $hand = null;
        $eye = null;
        $skin = null;

        // ── Respiratory Protection ──
        $fatalInhalation = !empty(array_intersect($hCodes, ['H330']));
        $toxicInhalation = !empty(array_intersect($hCodes, ['H331']));
        $harmfulInhalation = !empty(array_intersect($hCodes, ['H332', 'H333']));
        $respSensitizer = !empty(array_intersect($hCodes, ['H334']));
        $respIrritant = !empty(array_intersect($hCodes, ['H335', 'H336']));
        $respPCode = !empty(array_intersect($pCodes, ['P284', 'P285']));

        if ($fatalInhalation || $toxicInhalation) {
            $respiratory = 'NIOSH-approved supplied-air respirator or self-contained breathing apparatus (SCBA). Do not use chemical cartridge respirators.';
        } elseif ($respSensitizer) {
            $respiratory = 'NIOSH-approved respirator with organic vapor/particulate combination cartridge (P100/OV). Supplied-air respirator if concentrations are high.';
        } elseif ($harmfulInhalation || $respIrritant || $respPCode) {
            $respiratory = 'NIOSH-approved respirator with appropriate cartridge if exposure limits are exceeded or if irritation is experienced.';
        }

        // ── Hand Protection ──
        $fatalDermal = !empty(array_intersect($hCodes, ['H310']));
        $corrosive = !empty(array_intersect($hCodes, ['H314']));
        $skinSensitizer = !empty(array_intersect($hCodes, ['H317']));
        $dermalToxic = !empty(array_intersect($hCodes, ['H311', 'H312', 'H313']));
        $skinIrritant = !empty(array_intersect($hCodes, ['H315', 'H316']));

        if ($fatalDermal || $corrosive) {
            $hand = 'Chemical-resistant gloves (butyl rubber or Viton recommended). Double gloving recommended for corrosive/highly toxic materials. Verify breakthrough time with glove manufacturer.';
        } elseif ($skinSensitizer) {
            $hand = 'Chemical-resistant gloves (nitrile recommended). Replace gloves frequently to prevent sensitization. Verify breakthrough time with glove manufacturer.';
        } elseif ($dermalToxic || $skinIrritant) {
            $hand = 'Chemical-resistant gloves (nitrile or neoprene recommended). Verify breakthrough time with glove manufacturer.';
        }

        // ── Eye Protection ──
        $severeEyeDamage = !empty(array_intersect($hCodes, ['H318']));
        $eyeCorrosive = !empty(array_intersect($hCodes, ['H314']));
        $seriousEyeIrritation = !empty(array_intersect($hCodes, ['H319']));
        $mildEyeIrritation = !empty(array_intersect($hCodes, ['H320']));

        if ($severeEyeDamage || $eyeCorrosive) {
            $eye = 'Chemical splash goggles and face shield required. Tightly fitting safety goggles per ANSI Z87.1.';
        } elseif ($seriousEyeIrritation) {
            $eye = 'Chemical splash goggles or safety glasses with side shields. Face shield if splash hazard exists.';
        } elseif ($mildEyeIrritation) {
            $eye = 'Safety glasses with side shields.';
        }

        // ── Skin / Body Protection ──
        if ($fatalDermal || $corrosive) {
            $skin = 'Full chemical-resistant suit. Impervious boots and chemical-resistant apron. Emergency shower and eyewash station should be accessible.';
        } elseif ($dermalToxic || $skinIrritant || $skinSensitizer) {
            $skin = 'Wear protective clothing to prevent skin contact. Impervious apron recommended. Launder contaminated clothing before reuse.';
        }

        // P280 fallback — if present but no specific PPE was derived from H-codes
        if (in_array('P280', $pCodes, true)) {
            if ($hand === null) {
                $hand = 'Chemical-resistant gloves (nitrile or neoprene recommended).';
            }
            if ($eye === null) {
                $eye = 'Safety glasses with side shields. Use chemical splash goggles if splash hazard exists.';
            }
            if ($skin === null) {
                $skin = 'Wear protective clothing to prevent skin contact.';
            }
        }

        return [
            'respiratory'     => $respiratory,
            'hand_protection' => $hand,
            'eye_protection'  => $eye,
            'skin_protection' => $skin,
        ];
    }

    /**
     * Get the concentration cutoff for a canonical hazard class code + category.
     *
     * Inputs are expected to be normalised via HazardClassAliases. Callers
     * that hand in NULL / unknown-code inputs get the conservative 1%
     * default — matches pre-Phase-1 behaviour for physical hazards and
     * any unrecognised classification.
     *
     * @param string      $canonical  GHSHazardClass::* code (or '' / unknown)
     * @param string|null $category   Canonicalised category ('Cat 1', 'Cat 2A', …)
     */
    private function getCutoff(string $canonical, ?string $category): float
    {
        if ($canonical === '' || !isset(self::HEALTH_CUTOFFS[$canonical])) {
            // Physical / environmental / unknown canonical codes → 1% default.
            // Preserves the pre-Phase-1 "Flammable and anything else lands at 1%"
            // behaviour. Phase 2+ may tighten this per-class.
            return 1.0;
        }

        $categories = self::HEALTH_CUTOFFS[$canonical];
        if ($category !== null && $category !== '' && isset($categories[$category])) {
            return $categories[$category];
        }

        // Return the most conservative (smallest) cutoff for the class when
        // the category is missing or unrecognised — matches pre-Phase-1 min()
        // fallback behaviour.
        return (float) min($categories);
    }

    /**
     * Map a canonical aquatic class code to its aquatic summation route key.
     * Returns null for non-aquatic codes.
     */
    private function canonicalToAquaticRoute(?string $canonical): ?string
    {
        return match ($canonical) {
            GHSHazardClass::AQUATIC_ACUTE   => 'acute',
            GHSHazardClass::AQUATIC_CHRONIC => 'chronic',
            default                         => null,
        };
    }

    /**
     * Pull the M-factor for the given aquatic route out of a
     * hazard_classifications row or CPD determination-JSON array.
     * Returns 1.0 and source='default' when no explicit value is present
     * (per GHS Annex I 4.1.3.4 default).
     */
    private function resolveMFactor(array $row, string $route, string $source = 'vendor'): array
    {
        $col = self::AQUATIC_M_FACTOR_COLUMN[$route] ?? null;
        if ($col !== null && isset($row[$col]) && $row[$col] !== null && $row[$col] !== '') {
            $val = (float) $row[$col];
            if ($val > 0) {
                return ['value' => $val, 'source' => $source];
            }
        }
        return ['value' => 1.0, 'source' => 'default'];
    }

    /**
     * Record an aquatic-hazard contribution into the aquaticBuffer.
     * Called for every component that carries an aquatic classification
     * — regardless of whether the per-component cutoff fires. The
     * summation formulas in GHS Annex I 4.1.3.5.5 weight contributors
     * by M-factor, so every contributor participates.
     */
    private function addToAquaticBuffer(
        string $route,
        string $cas,
        string $name,
        float $conc,
        string $category,
        float $mFactor,
        string $mFactorSource,
        string $source
    ): void {
        if (!isset(self::AQUATIC_SUMMATION_RULES[$route])) {
            return;
        }
        $this->aquaticBuffer[$route][] = [
            'cas'             => $cas,
            'name'            => $name,
            'conc'            => $conc,
            'category'        => $category,
            'm_factor'        => $mFactor,
            'm_factor_source' => $mFactorSource,
            'source'          => $source,
        ];
    }

    /**
     * Map a canonical acute-toxicity class code to its ATE route key.
     * Returns null for non-acute-tox codes. The inhalation class defaults
     * to the "vapor" route — ink & coatings catalogs are almost always
     * liquid, so vapour is the correct scale; when dust/mist inhalation
     * data is present on a row (via ate_inhalation_dust_mg_l_4h) we fall
     * back to that route instead, route-aware below.
     */
    private function canonicalToAteRoute(?string $canonical): ?string
    {
        return match ($canonical) {
            GHSHazardClass::ACUTE_TOXICITY_ORAL       => 'oral',
            GHSHazardClass::ACUTE_TOXICITY_DERMAL     => 'dermal',
            GHSHazardClass::ACUTE_TOXICITY_INHALATION => 'inhalation_vapor',
            default                                   => null,
        };
    }

    /**
     * Pull an explicit ATE value out of a hazard_classifications row (or
     * CPD determination JSON) for the given route. Returns
     *   ['value' => float|null, 'source' => 'vendor'|'cpd'|'unknown']
     * so the caller can feed the ATE buffer with accurate provenance.
     *
     * For the inhalation route we also probe the dust/mist column as a
     * fallback — rare for ink/coatings catalogs but correct when present.
     */
    private function resolveExplicitAte(array $row, string $route, string $source = 'vendor'): array
    {
        $col = self::ATE_ROUTE_COLUMN[$route] ?? null;
        if ($col !== null && isset($row[$col]) && $row[$col] !== null && $row[$col] !== '') {
            return ['value' => (float) $row[$col], 'source' => $source];
        }
        return ['value' => null, 'source' => 'unknown'];
    }

    /**
     * Record an acute-toxicity contribution into the ATE accumulator.
     *
     * Called for every component that carries an acute-toxicity
     * classification — regardless of whether the per-component cutoff
     * fired. The ATE mixture formula uses ALL acute-tox contributors,
     * not just the ones that triggered individually.
     *
     * $ate values arrive pre-resolved:
     *   null   — no explicit value; applyATECalculation falls back to
     *            the GHS Table 3.1.2 category-default before inclusion
     *   float  — explicit LD50/LC50 in the route's native units
     *
     * $ateSource is one of 'vendor', 'cpd', 'category_default'.
     */
    private function addToAteBuffer(
        string $route,
        string $cas,
        string $name,
        float $conc,
        string $category,
        ?float $ate,
        string $ateSource,
        string $source
    ): void {
        if (!isset(self::ATE_CATEGORY_RANGES[$route])) {
            return;
        }
        $this->ateBuffer[$route][] = [
            'cas'        => $cas,
            'name'       => $name,
            'conc'       => $conc,
            'category'   => $category,
            'ate'        => $ate,
            'ate_source' => $ateSource,
            'source'     => $source,
        ];
    }

    /**
     * Record a (class, category) contribution from a single component into
     * the summation accumulator. Safe to call for every hazard entry the
     * engine sees, triggered or not — entries for classes without a
     * SUMMATION_RULES entry are silently dropped so the buffer stays focused.
     */
    private function addToSummationBuffer(
        ?string $canonical,
        string $category,
        string $cas,
        string $name,
        float $conc,
        string $source,
        bool $triggeredDirectly
    ): void {
        if ($canonical === null || $canonical === '') {
            return;
        }
        if (!isset(self::SUMMATION_RULES[$canonical][$category])) {
            return;
        }
        $this->summationBuffer[$canonical][$category][] = [
            'cas'                => $cas,
            'name'               => $name,
            'conc'               => $conc,
            'source'             => $source,
            'triggered_directly' => $triggeredDirectly,
        ];
    }

    /**
     * After the main component loop, iterate every SUMMATION_RULES entry
     * and fire mixture-level classifications whose combined concentration
     * meets the GHS Annex I / 29 CFR 1910.1200 App A summation threshold.
     *
     * Skips any (class, category) already classified by a per-component
     * trigger — summation only fills gaps where no single component
     * reached its own cutoff but the combined mass does.
     */
    private function applySummationRules(
        array &$allHClasses,
        array &$allHStmts,
        array &$allPStmts,
        array &$allPictograms,
        ?string &$signalWord,
        array &$hazardousCas
    ): void {
        foreach (self::SUMMATION_RULES as $canonical => $categoryThresholds) {
            foreach ($categoryThresholds as $category => $threshold) {
                $contributors = $this->summationBuffer[$canonical][$category] ?? [];
                if (empty($contributors)) {
                    continue;
                }

                $sum = 0.0;
                foreach ($contributors as $c) {
                    $sum += (float) $c['conc'];
                }

                if ($sum < $threshold) {
                    continue;
                }

                if ($this->alreadyClassified($allHClasses, $canonical, $category)) {
                    continue;
                }

                $this->traceStep('summation_triggered', "Summation rule fired for {$canonical} {$category}", [
                    'canonical'    => $canonical,
                    'category'     => $category,
                    'sum_pct'      => $sum,
                    'threshold_pct' => $threshold,
                    'contributors' => array_map(fn($c) => [
                        'cas' => $c['cas'], 'conc' => $c['conc'], 'source' => $c['source'],
                        'triggered_directly' => $c['triggered_directly'],
                    ], $contributors),
                ]);

                $defaults = $this->getDefaultsForClassCategory($canonical, $category);

                $allHClasses[] = [
                    'class'              => GHSHazardClass::displayName($canonical),
                    'category'           => $defaults['category_display'] ?? $category,
                    'canonical'          => $canonical,
                    'category_canonical' => $category,
                    'cas'                => 'MIXTURE',
                    'chemical'           => 'Multiple components (summation)',
                    'concentration_pct'  => $sum,
                    'cutoff_pct'         => $threshold,
                    'source'             => 'summation',
                ];

                foreach ($defaults['h_codes'] as $hc) {
                    if (!isset($allHStmts[$hc])) {
                        $allHStmts[$hc] = ['code' => $hc, 'text' => ''];
                    }
                }
                foreach ($defaults['p_codes'] as $pc) {
                    if (!isset($allPStmts[$pc])) {
                        $allPStmts[$pc] = ['code' => $pc, 'text' => ''];
                    }
                }
                foreach ($defaults['pictograms'] as $pict) {
                    $allPictograms[$pict] = true;
                }
                if ($defaults['signal_word'] !== null) {
                    $curPri = self::SIGNAL_HIERARCHY[$signalWord] ?? 0;
                    $newPri = self::SIGNAL_HIERARCHY[$defaults['signal_word']] ?? 0;
                    if ($newPri > $curPri) {
                        $signalWord = $defaults['signal_word'];
                    }
                }

                foreach ($contributors as $c) {
                    $casId = (string) $c['cas'];
                    if ($casId === '' || $casId === 'TRADE_SECRET') {
                        continue;
                    }
                    $hazardousCas[$casId] = true;
                }
            }
        }

        // Phase 3b: cross-category summation rules. A Cat 1 contributor
        // counts 10× toward Cat 2 classification for certain classes
        // (skin/eye corrosion/irritation, STOT-SE, STOT-RE). Run after
        // the simple rules so alreadyClassified correctly sees any Cat 1
        // that was just stamped by the simple path.
        foreach (self::CROSS_CATEGORY_SUMMATION_RULES as $canonical => $targetCategories) {
            foreach ($targetCategories as $targetCategory => $rule) {
                // If a more-severe category is already classified for this
                // class, Cat 2 would be dropped by consolidation anyway —
                // skip the work.
                if ($this->moreSevereAlreadyClassified($allHClasses, $canonical, $targetCategory)) {
                    continue;
                }

                // Skip if the target category itself already fired (via
                // per-component trigger or the simple summation pass).
                if ($this->alreadyClassified($allHClasses, $canonical, $targetCategory)) {
                    continue;
                }

                $weightedSum     = 0.0;
                $contributorRows = [];
                foreach ($rule['contributors'] as $contribSpec) {
                    $bucket = $this->summationBuffer[$canonical][$contribSpec['category']] ?? [];
                    foreach ($bucket as $c) {
                        $contribution = (float) $c['conc'] * (float) $contribSpec['weight'];
                        $weightedSum += $contribution;
                        $contributorRows[] = [
                            'cas'               => $c['cas'],
                            'source_category'   => $contribSpec['category'],
                            'source_conc'       => $c['conc'],
                            'weight'            => $contribSpec['weight'],
                            'weighted_contrib'  => $contribution,
                        ];
                    }
                }

                if ($weightedSum < $rule['threshold']) {
                    continue;
                }

                $this->traceStep('cross_category_summation_triggered',
                    "Cross-category summation fired for {$canonical} -> {$targetCategory}",
                    [
                        'canonical'         => $canonical,
                        'target_category'   => $targetCategory,
                        'weighted_sum'      => $weightedSum,
                        'threshold'         => $rule['threshold'],
                        'contributors'      => $contributorRows,
                    ]
                );

                $defaults = $this->getDefaultsForClassCategory($canonical, $targetCategory);

                $allHClasses[] = [
                    'class'              => GHSHazardClass::displayName($canonical),
                    'category'           => $defaults['category_display'] ?? $targetCategory,
                    'canonical'          => $canonical,
                    'category_canonical' => $targetCategory,
                    'cas'                => 'MIXTURE',
                    'chemical'           => 'Multiple components (cross-category summation)',
                    'concentration_pct'  => $weightedSum,
                    'cutoff_pct'         => $rule['threshold'],
                    'source'             => 'cross_category_summation',
                ];

                foreach ($defaults['h_codes'] as $hc) {
                    if (!isset($allHStmts[$hc])) {
                        $allHStmts[$hc] = ['code' => $hc, 'text' => ''];
                    }
                }
                foreach ($defaults['p_codes'] as $pc) {
                    if (!isset($allPStmts[$pc])) {
                        $allPStmts[$pc] = ['code' => $pc, 'text' => ''];
                    }
                }
                foreach ($defaults['pictograms'] as $pict) {
                    $allPictograms[$pict] = true;
                }
                if ($defaults['signal_word'] !== null) {
                    $curPri = self::SIGNAL_HIERARCHY[$signalWord] ?? 0;
                    $newPri = self::SIGNAL_HIERARCHY[$defaults['signal_word']] ?? 0;
                    if ($newPri > $curPri) {
                        $signalWord = $defaults['signal_word'];
                    }
                }

                foreach ($contributorRows as $c) {
                    $casId = (string) $c['cas'];
                    if ($casId === '' || $casId === 'TRADE_SECRET') {
                        continue;
                    }
                    $hazardousCas[$casId] = true;
                }
            }
        }
    }

    /**
     * Phase 5: apply a finished-good hazard override on top of the
     * composition-derived classification.
     *
     * Override shape — see classify() docblock. Two modes:
     *
     *   - "additive": override's hazard classes, H-codes, P-codes,
     *     pictograms, and signal word are merged with whatever the
     *     composition produced. Duplicate (class, category) entries
     *     are deduplicated through alreadyClassified; H/P-codes and
     *     pictograms go through the same keyed-merge the rest of the
     *     engine uses.
     *
     *   - "replace": the composition-derived classification is
     *     discarded entirely (hazard_classes, H-codes, P-codes,
     *     pictograms, signal word). Only the override content remains.
     *     Exposure limits and the generation trace are preserved —
     *     Section 8 and audit trails still reflect the composition.
     *
     * Every invocation logs a fg_override_applied trace step with the
     * mode, rationale, and the full override payload for auditability.
     */
    private function applyFinishedGoodOverride(
        array $override,
        array &$allHClasses,
        array &$allHStmts,
        array &$allPStmts,
        array &$allPictograms,
        ?string &$signalWord
    ): void {
        $mode = (string) ($override['mode'] ?? 'none');
        if ($mode === 'none') {
            return;
        }
        if ($mode !== 'additive' && $mode !== 'replace') {
            $this->traceStep('fg_override_ignored_invalid_mode', "Unknown FG override mode '{$mode}' — ignored", [
                'mode' => $mode,
            ]);
            return;
        }

        $this->traceStep('fg_override_applied', "FG hazard override applied in '{$mode}' mode", [
            'mode'      => $mode,
            'rationale' => $override['rationale'] ?? null,
            'set_by'    => $override['set_by']    ?? null,
            'set_at'    => $override['set_at']    ?? null,
            'payload'   => $override['hazards']   ?? null,
        ]);

        if ($mode === 'replace') {
            $allHClasses    = [];
            $allHStmts      = [];
            $allPStmts      = [];
            $allPictograms  = [];
            $signalWord     = null;
        }

        $hazards = $override['hazards'] ?? [];
        if (empty($hazards)) {
            return;
        }

        // Hazard classes may come as either a list of display-form entries
        // or a list of GHSHazardData keys (selected_hazards shape).
        $hazardClassEntries = [];
        if (!empty($hazards['selected_hazards']) && is_array($hazards['selected_hazards'])) {
            $ghsData = GHSHazardData::HAZARD_CLASSIFICATIONS;
            foreach ($hazards['selected_hazards'] as $key) {
                if (!isset($ghsData[$key])) continue;
                $hazardClassEntries[] = [
                    'class'    => $ghsData[$key]['class'],
                    'category' => $ghsData[$key]['category'],
                ];
            }
        }
        if (!empty($hazards['hazard_classes']) && is_array($hazards['hazard_classes'])) {
            foreach ($hazards['hazard_classes'] as $hc) {
                if (!is_array($hc) || empty($hc['class'])) continue;
                $hazardClassEntries[] = [
                    'class'    => (string) $hc['class'],
                    'category' => (string) ($hc['category'] ?? ''),
                ];
            }
        }

        foreach ($hazardClassEntries as $entry) {
            $canonical     = HazardClassAliases::normalize($entry['class']);
            $categoryCanon = HazardClassAliases::normalizeCategory($entry['category']);
            if ($canonical !== null
                && $this->alreadyClassified($allHClasses, $canonical, $categoryCanon)) {
                continue;
            }
            $allHClasses[] = [
                'class'              => $entry['class'],
                'category'           => $entry['category'],
                'canonical'          => $canonical,
                'category_canonical' => $categoryCanon,
                'cas'                => 'FG_OVERRIDE',
                'chemical'           => 'Finished-good override',
                'concentration_pct'  => null,
                'cutoff_pct'         => null,
                'source'             => 'fg_override',
            ];
        }

        foreach ($this->parseOverrideCodeList($hazards['h_statements'] ?? []) as $code) {
            if (!isset($allHStmts[$code])) {
                $allHStmts[$code] = ['code' => $code, 'text' => ''];
            }
        }
        foreach ($this->parseOverrideCodeList($hazards['p_statements'] ?? []) as $code) {
            if (!isset($allPStmts[$code])) {
                $allPStmts[$code] = ['code' => $code, 'text' => ''];
            }
        }
        foreach ($this->parseOverrideCodeList($hazards['pictograms'] ?? []) as $pict) {
            $allPictograms[$pict] = true;
        }

        $sw = $hazards['signal_word'] ?? null;
        if (is_string($sw) && $sw !== '') {
            $normalised = HazardClassAliases::normalizeSignalWord($sw) ?? $sw;
            $curPri = self::SIGNAL_HIERARCHY[$signalWord] ?? 0;
            $newPri = self::SIGNAL_HIERARCHY[$normalised] ?? 0;
            if ($mode === 'replace' || $newPri > $curPri) {
                $signalWord = $normalised;
            }
        }
    }

    /**
     * Accept a list of statement / pictogram codes in either of two
     * forms a CPD or FG-override payload might carry:
     *   - a real array of strings
     *   - a single comma-separated string
     * Returns a cleaned list.
     */
    private function parseOverrideCodeList(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map(
                fn($c) => is_string($c) ? trim($c) : (string) $c,
                $raw
            ), fn($c) => $c !== ''));
        }
        if (is_string($raw) && $raw !== '') {
            return array_values(array_filter(
                array_map('trim', explode(',', $raw)),
                fn($c) => $c !== ''
            ));
        }
        return [];
    }

    /**
     * Phase 4: aquatic-hazard mixture classification per GHS Annex I
     * 4.1.3.5.5. Iterates each route (acute / chronic), evaluates
     * every AQUATIC_SUMMATION_RULES entry (Cat 1 for acute; Cat 1/2/3
     * for chronic), and fires the mixture classification when the
     * weighted summation meets the 25 % threshold.
     *
     * Weighting rules:
     *   - Cat 1 contributors always carry M-factor weighting (M × C).
     *   - Cat 2 / Cat 3 contributors use concentration only (M = 1).
     *   - Cross-category rules (Cat 2 / Cat 3) apply an additional 10× or
     *     100× multiplier to Cat 1 contributors per the standard GHS
     *     aquatic-cross-category formulas.
     *
     * Skip conditions:
     *   - A more-severe category for the same route is already
     *     classified (prevents default statements from leaking).
     *   - The target category itself is already classified.
     */
    private function applyAquaticSummation(
        array &$allHClasses,
        array &$allHStmts,
        array &$allPStmts,
        array &$allPictograms,
        ?string &$signalWord,
        array &$hazardousCas
    ): void {
        foreach (self::AQUATIC_SUMMATION_RULES as $route => $targetCategories) {
            $canonical = self::AQUATIC_ROUTE_TO_CANONICAL[$route] ?? null;
            if ($canonical === null) {
                continue;
            }
            $contributors = $this->aquaticBuffer[$route] ?? [];
            if (empty($contributors)) {
                continue;
            }

            foreach ($targetCategories as $targetCategory => $rule) {
                if ($this->moreSevereAlreadyClassified($allHClasses, $canonical, $targetCategory)) {
                    continue;
                }
                if ($this->alreadyClassified($allHClasses, $canonical, $targetCategory)) {
                    continue;
                }

                // Compute weighted sum across all contributor categories.
                $weightedSum = 0.0;
                $usedRows    = [];
                foreach ($rule['contributors'] as $spec) {
                    foreach ($contributors as $c) {
                        if ($c['category'] !== $spec['category']) {
                            continue;
                        }
                        $w = (float) $spec['weight'];
                        $m = $spec['use_m_factor'] ? (float) $c['m_factor'] : 1.0;
                        $contribution = (float) $c['conc'] * $w * $m;
                        $weightedSum += $contribution;
                        $usedRows[] = [
                            'cas'              => $c['cas'],
                            'source_category'  => $spec['category'],
                            'conc'             => $c['conc'],
                            'weight'           => $spec['weight'],
                            'm_factor'         => $m,
                            'm_factor_source'  => $c['m_factor_source'],
                            'weighted_contrib' => $contribution,
                        ];
                    }
                }

                if ($weightedSum < $rule['threshold']) {
                    continue;
                }

                $this->traceStep('aquatic_summation_triggered', "Aquatic {$route} {$targetCategory} summation fired", [
                    'route'           => $route,
                    'canonical'       => $canonical,
                    'target_category' => $targetCategory,
                    'weighted_sum'    => $weightedSum,
                    'threshold'       => $rule['threshold'],
                    'contributors'    => $usedRows,
                ]);

                $defaults = $this->getDefaultsForClassCategory($canonical, $targetCategory);

                $allHClasses[] = [
                    'class'              => GHSHazardClass::displayName($canonical),
                    'category'           => $defaults['category_display'] ?? $targetCategory,
                    'canonical'          => $canonical,
                    'category_canonical' => $targetCategory,
                    'cas'                => 'MIXTURE',
                    'chemical'           => 'Multiple components (aquatic summation)',
                    'concentration_pct'  => $weightedSum,
                    'cutoff_pct'         => $rule['threshold'],
                    'source'             => 'aquatic_summation',
                ];

                foreach ($defaults['h_codes'] as $hc) {
                    if (!isset($allHStmts[$hc])) {
                        $allHStmts[$hc] = ['code' => $hc, 'text' => ''];
                    }
                }
                foreach ($defaults['p_codes'] as $pc) {
                    if (!isset($allPStmts[$pc])) {
                        $allPStmts[$pc] = ['code' => $pc, 'text' => ''];
                    }
                }
                foreach ($defaults['pictograms'] as $pict) {
                    $allPictograms[$pict] = true;
                }
                if ($defaults['signal_word'] !== null) {
                    $curPri = self::SIGNAL_HIERARCHY[$signalWord] ?? 0;
                    $newPri = self::SIGNAL_HIERARCHY[$defaults['signal_word']] ?? 0;
                    if ($newPri > $curPri) {
                        $signalWord = $defaults['signal_word'];
                    }
                }

                foreach ($usedRows as $c) {
                    $casId = (string) $c['cas'];
                    if ($casId === '' || $casId === 'TRADE_SECRET') {
                        continue;
                    }
                    $hazardousCas[$casId] = true;
                }
            }
        }
    }

    /**
     * Phase 3c: compute the mixture's acute-toxicity category per route via
     * the GHS Annex I 3.1.3.6 ATE harmonic-mean formula:
     *
     *     100 / ATE_mix  =  Σ ( C_i / ATE_i )
     *
     * Iterate the ateBuffer for each route; resolve any null ATE_i from
     * the route's category-default table; skip components whose category
     * has no route-appropriate default (Cat 5 inhalation, etc.); compute
     * ATE_mix; look up the resulting category in ATE_CATEGORY_RANGES;
     * stamp the mixture with that classification.
     *
     * Skips a route entirely if it's already classified (per-component
     * trigger beat us to it) or if no contributors are available for
     * that route.
     */
    private function applyATECalculation(
        array &$allHClasses,
        array &$allHStmts,
        array &$allPStmts,
        array &$allPictograms,
        ?string &$signalWord,
        array &$hazardousCas
    ): void {
        foreach (self::ATE_CATEGORY_RANGES as $route => $categoryRanges) {
            $canonical   = self::ATE_ROUTE_TO_CANONICAL[$route] ?? null;
            $contributors = $this->ateBuffer[$route] ?? [];
            if ($canonical === null || empty($contributors)) {
                continue;
            }

            // Resolve any null ATEs via category defaults; compute
            // the running sum of Ci / ATE_i for the formula.
            $reciprocalSum   = 0.0;
            $usedContributors = [];
            foreach ($contributors as $c) {
                $ate = $c['ate'];
                $ateSource = $c['ate_source'];
                if ($ate === null) {
                    $defaultAte = self::CATEGORY_DEFAULT_ATES[$route][$c['category']] ?? null;
                    if ($defaultAte === null) {
                        // No explicit ATE and no category default — skip,
                        // but log so operators can see which components
                        // were left out of the mixture calc.
                        $this->traceStep('ate_contributor_skipped', "Component {$c['cas']} has no ATE and no default for {$route} {$c['category']}", [
                            'cas' => $c['cas'], 'route' => $route, 'category' => $c['category'],
                        ]);
                        continue;
                    }
                    $ate = $defaultAte;
                    $ateSource = 'category_default';
                }
                if ($ate <= 0) {
                    continue;  // Degenerate value; skip to avoid divide-by-zero
                }
                $reciprocalSum += (float) $c['conc'] / $ate;
                $usedContributors[] = array_merge($c, [
                    'ate_resolved'        => $ate,
                    'ate_source_resolved' => $ateSource,
                ]);
            }

            if ($reciprocalSum <= 0) {
                continue;
            }

            $ateMix = 100.0 / $reciprocalSum;

            // Find the category whose upper bound ATE_mix falls into.
            $mixCategory = null;
            foreach ($categoryRanges as $range) {
                if ($ateMix <= $range['upper']) {
                    $mixCategory = $range['category'];
                    break;
                }
            }
            if ($mixCategory === null) {
                // ATE_mix above the highest category's upper bound — not
                // classified as acute-toxic by this route. Log the result
                // for audit; move on.
                $this->traceStep('ate_mixture_not_classified', "ATE mix {$ateMix} for {$route} exceeds all category upper bounds — not classified", [
                    'route' => $route, 'ate_mix' => $ateMix,
                ]);
                continue;
            }

            if ($this->alreadyClassified($allHClasses, $canonical, $mixCategory)) {
                // Per-component or earlier summation already classified
                // at this (route, category) — don't duplicate.
                continue;
            }

            if ($this->moreSevereAlreadyClassified($allHClasses, $canonical, $mixCategory)) {
                // A more-severe category is already classified for this
                // route (per-component trigger or summation). Consolidation
                // would drop the ATE entry in favour of the severe one,
                // but the ATE entry's default H/P-codes / pictograms get
                // merged before consolidation — which would leak a Cat 3 /
                // Cat 4 H-statement into an SDS that's classified Cat 1,
                // producing an inconsistent output. Skip entirely.
                $this->traceStep('ate_mixture_dominated', "ATE mix for {$route} at {$mixCategory} dominated by existing more-severe classification", [
                    'route' => $route, 'ate_mix' => $ateMix, 'would_be_category' => $mixCategory,
                ]);
                continue;
            }

            $this->traceStep('ate_mixture_classified', "ATE mixture classified for {$route} at {$mixCategory}", [
                'route'             => $route,
                'canonical'         => $canonical,
                'category'          => $mixCategory,
                'ate_mix'           => $ateMix,
                'reciprocal_sum'    => $reciprocalSum,
                'contributor_count' => count($usedContributors),
                'contributors'      => array_map(fn($c) => [
                    'cas'        => $c['cas'],
                    'conc'       => $c['conc'],
                    'category'   => $c['category'],
                    'ate'        => $c['ate_resolved'],
                    'ate_source' => $c['ate_source_resolved'],
                ], $usedContributors),
            ]);

            $defaults = $this->getDefaultsForClassCategory($canonical, $mixCategory);

            $allHClasses[] = [
                'class'              => GHSHazardClass::displayName($canonical),
                'category'           => $defaults['category_display'] ?? $mixCategory,
                'canonical'          => $canonical,
                'category_canonical' => $mixCategory,
                'cas'                => 'MIXTURE',
                'chemical'           => 'Multiple components (ATE calculation)',
                'concentration_pct'  => null,
                'cutoff_pct'         => null,
                'source'             => 'ate_mixture',
                'ate_mix'            => $ateMix,
                'route'              => $route,
            ];

            foreach ($defaults['h_codes'] as $hc) {
                if (!isset($allHStmts[$hc])) {
                    $allHStmts[$hc] = ['code' => $hc, 'text' => ''];
                }
            }
            foreach ($defaults['p_codes'] as $pc) {
                if (!isset($allPStmts[$pc])) {
                    $allPStmts[$pc] = ['code' => $pc, 'text' => ''];
                }
            }
            foreach ($defaults['pictograms'] as $pict) {
                $allPictograms[$pict] = true;
            }
            if ($defaults['signal_word'] !== null) {
                $curPri = self::SIGNAL_HIERARCHY[$signalWord] ?? 0;
                $newPri = self::SIGNAL_HIERARCHY[$defaults['signal_word']] ?? 0;
                if ($newPri > $curPri) {
                    $signalWord = $defaults['signal_word'];
                }
            }

            foreach ($usedContributors as $c) {
                $casId = (string) $c['cas'];
                if ($casId === '' || $casId === 'TRADE_SECRET') {
                    continue;
                }
                $hazardousCas[$casId] = true;
            }
        }
    }

    /**
     * Filter CPD-declared companion codes (H-codes or pictograms) to only
     * those whose GHS associations include at least one canonical class
     * that actually triggered in this CPD contribution.
     *
     * Builds a lazy, cached reverse map from GHSHazardData once:
     *   h_codes:    H-code    → set of canonical classes that carry it
     *   pictograms: pictogram → set of canonical classes that carry it
     *
     * Items with no known class association are kept defensively (an
     * unmapped code could still be legitimate vendor-specific data).
     * Items whose every associated class is in the untriggered set are
     * dropped, with a trace step recording the reason for the drop.
     *
     * @param array  $items               Key-value map (H/P codes) or flat list (pictograms)
     * @param array  $triggeredCanonicals canonical_code => true (set of actually-fired classes)
     * @param string $codeType            'h_codes' | 'pictograms'
     */
    private function filterCpdCodesByTriggeredClasses(
        array $items,
        array $triggeredCanonicals,
        string $codeType,
        string $cas,
        string $source
    ): array {
        static $codeToCanonicalsCache = [];
        if (!isset($codeToCanonicalsCache[$codeType])) {
            $map = [];
            foreach (GHSHazardData::HAZARD_CLASSIFICATIONS as $entry) {
                $canonical = HazardClassAliases::normalize((string) ($entry['class'] ?? ''));
                if ($canonical === null) continue;
                foreach (($entry[$codeType] ?? []) as $code) {
                    $map[(string) $code][$canonical] = true;
                }
            }
            $codeToCanonicalsCache[$codeType] = $map;
        }
        $mapForType = $codeToCanonicalsCache[$codeType];

        $filtered = [];
        foreach ($items as $key => $value) {
            // H/P statement entries are keyed by code with array values;
            // pictograms are flat lists of code strings.
            $code = is_array($value) ? (string) ($value['code'] ?? $key) : (string) $value;
            if ($code === '') {
                continue;
            }

            $canonicalsForCode = $mapForType[$code] ?? null;
            if ($canonicalsForCode === null) {
                // Unmapped — not associated with any known class. Keep
                // defensively; custom / non-GHS codes shouldn't be silently dropped.
                $filtered[$key] = $value;
                continue;
            }

            $keep = false;
            foreach ($canonicalsForCode as $cls => $_) {
                if (isset($triggeredCanonicals[$cls])) {
                    $keep = true;
                    break;
                }
            }

            if ($keep) {
                $filtered[$key] = $value;
            } else {
                $this->traceStep('cpd_code_dropped_untriggered_class',
                    "CPD {$cas} {$codeType} '{$code}' dropped — only maps to classes that didn't trigger",
                    [
                        'cas' => $cas,
                        'code' => $code,
                        'code_type' => $codeType,
                        'associated_classes' => array_keys($canonicalsForCode),
                        'triggered_classes' => array_keys($triggeredCanonicals),
                        'source' => $source,
                    ]
                );
            }
        }
        return $filtered;
    }

    /**
     * True if any existing $hazardClasses entry for the same canonical class
     * has a strictly more-severe category than $targetCategory. Used by the
     * cross-category summation pass to skip a Cat 2 firing when Cat 1 has
     * already been stamped — it would only be dropped in consolidation.
     */
    private function moreSevereAlreadyClassified(array $hazardClasses, string $canonical, string $targetCategory): bool
    {
        $targetSeverity = $this->categoryToSeverity($targetCategory);
        foreach ($hazardClasses as $hc) {
            if (($hc['canonical'] ?? null) !== $canonical) continue;
            $cat = (string) ($hc['category_canonical'] ?? '');
            if ($cat === '') continue;
            $severity = $this->categoryToSeverity($cat);
            if ($severity < $targetSeverity) {  // lower severity number = more severe
                return true;
            }
        }
        return false;
    }

    /**
     * True if $hazardClasses already contains an entry for the given
     * canonical class + canonical category — used by applySummationRules
     * to avoid stamping a mixture-level duplicate when a per-component
     * trigger already classified the same (class, category).
     */
    private function alreadyClassified(array $hazardClasses, string $canonical, string $category): bool
    {
        foreach ($hazardClasses as $hc) {
            if (($hc['canonical'] ?? null) !== $canonical) continue;
            if (($hc['category_canonical'] ?? null) !== $category) continue;
            return true;
        }
        return false;
    }

    /**
     * Pull the default H-codes, P-codes, pictograms, and signal word for a
     * given canonical class + canonical category out of GHSHazardData.
     * The lookup is by matching entry['class'] to the canonical's display
     * name and normalising entry['category'] through HazardClassAliases.
     * Returns empty arrays if no match is found.
     */
    private function getDefaultsForClassCategory(string $canonical, string $category): array
    {
        $displayClass = GHSHazardClass::displayName($canonical);
        foreach (GHSHazardData::HAZARD_CLASSIFICATIONS as $entry) {
            if (($entry['class'] ?? null) !== $displayClass) {
                continue;
            }
            $entryCat = HazardClassAliases::normalizeCategory((string) ($entry['category'] ?? ''));
            if ($entryCat !== $category) {
                continue;
            }
            return [
                'h_codes'          => $entry['h_codes']    ?? [],
                'p_codes'          => $entry['p_codes']    ?? [],
                'pictograms'       => $entry['pictograms'] ?? [],
                'signal_word'      => $entry['signal_word'] ?? null,
                'category_display' => $entry['category']   ?? $category,
            ];
        }
        return [
            'h_codes'          => [],
            'p_codes'          => [],
            'pictograms'       => [],
            'signal_word'      => null,
            'category_display' => $category,
        ];
    }

    /**
     * Apply GHS pictogram precedence rules.
     * GHS06 (skull) takes precedence over GHS07 (exclamation).
     * GHS05 (corrosion) takes precedence over GHS07 for skin/eye.
     */
    private function applyPictogramPrecedence(array $pictograms): array
    {
        // If GHS06 present, remove GHS07
        if (in_array('GHS06', $pictograms)) {
            $pictograms = array_filter($pictograms, fn($p) => $p !== 'GHS07');
        }
        // If GHS05 present, remove GHS07
        if (in_array('GHS05', $pictograms)) {
            $pictograms = array_filter($pictograms, fn($p) => $p !== 'GHS07');
        }
        // If GHS02 or GHS01 present with GHS04, remove GHS04 context overlap
        // (gas cylinder for compressed gases is separate, keep it)

        $result = array_values(array_unique($pictograms));
        usort($result, function ($a, $b) {
            return (self::PICTOGRAM_PRIORITY[$b] ?? 0) <=> (self::PICTOGRAM_PRIORITY[$a] ?? 0);
        });

        return $result;
    }

    /**
     * Look up a CAS number determination and convert it to hazard data
     * that can be merged into the classification result.
     *
     * @return array|null  Null if no active determination exists.
     */
    private function applyCASDetermination(Database $db, string $cas, string $name, float $conc): ?array
    {
        $cpd = $db->fetch(
            "SELECT determination_json FROM competent_person_determinations
             WHERE cas_number = ? AND is_active = 1
             ORDER BY updated_at DESC LIMIT 1",
            [$cas]
        );

        if (!$cpd) {
            return null;
        }

        $det = json_decode($cpd['determination_json'] ?? '{}', true);
        if (empty($det)) {
            return null;
        }

        return $this->parseDeterminationStructure($det, $cas, $name, $conc, 'CAS determination');
    }

    /**
     * Parse a determination JSON structure (same shape as
     * competent_person_determinations.determination_json OR the
     * raw_materials.manual_hazard_json column) into a normalized
     * hazard contribution suitable for merging into classify() output.
     *
     * @param array  $det    Decoded determination JSON
     * @param string $cas    The CAS number (or '' for trade-secret rows)
     * @param string $name   Chemical name (or 'Trade Secret')
     * @param float  $conc   Concentration percent in the composition
     * @param string $source Label for the `source` field on hazard_classes
     * @return array  Same shape as applyCASDetermination() return value
     */
    private function parseDeterminationStructure(array $det, string $cas, string $name, float $conc, string $source): array
    {
        // Parse H-statements
        $hStmts = [];
        $hRaw = array_filter(array_map('trim', explode(',', $det['h_statements'] ?? '')));
        foreach ($hRaw as $code) {
            $hStmts[$code] = ['code' => $code, 'text' => GHSStatements::hText($code)];
        }

        // Parse P-statements
        $pStmts = [];
        $pRaw = array_filter(array_map('trim', explode(',', $det['p_statements'] ?? '')));
        foreach ($pRaw as $code) {
            $pStmts[$code] = ['code' => $code, 'text' => GHSStatements::pText($code)];
        }

        // Parse pictograms
        $pictograms = array_filter(array_map('trim', explode(',', $det['pictograms'] ?? '')));

        // Signal word
        $signalWord = !empty($det['signal_word']) ? $det['signal_word'] : null;

        // Hazard classes — use selected_hazards keys to get proper class/category
        $hazardClasses = [];
        $ghsData = GHSHazardData::HAZARD_CLASSIFICATIONS;
        $selectedHazards = json_decode($det['selected_hazards'] ?? '[]', true) ?: [];

        // Phase 2 (v1.2-cpd-cutoffs): each CPD-sourced hazard class must
        // clear its canonical GHS cutoff to contribute. Entries that fall
        // below the cutoff at the component's concentration are dropped and
        // logged as 'cpd_below_cutoff' in the trace. If no entry triggers,
        // the whole CPD contribution (H/P-codes, pictograms, signal word)
        // is suppressed — see below.
        $anyTriggered = false;

        if (!empty($selectedHazards)) {
            foreach ($selectedHazards as $key) {
                if (!isset($ghsData[$key])) continue;
                $entry         = $ghsData[$key];
                $canonical     = HazardClassAliases::normalize($entry['class']);
                $categoryCanon = HazardClassAliases::normalizeCategory($entry['category']);
                $cutoff        = $this->getCutoff($canonical ?? '', $categoryCanon);

                // Feed the summation buffer for every CPD-declared
                // (class, category), triggered or not. The buffer is
                // consulted by applySummationRules after the main loop.
                $this->addToSummationBuffer(
                    $canonical, $categoryCanon, $cas, $name, $conc,
                    $source, $conc >= $cutoff
                );

                // Phase 3c: feed the ATE buffer for acute-toxicity routes.
                // CPDs may carry explicit LD50/LC50 values via the ate_*
                // fields on determination_json; otherwise we fall back to
                // GHS Table 3.1.2 category-defaults at resolve time.
                $ateRoute = $this->canonicalToAteRoute($canonical);
                if ($ateRoute !== null && $categoryCanon !== '') {
                    $explicitAte = $this->resolveExplicitAte($det, $ateRoute, 'cpd');
                    $this->addToAteBuffer(
                        $ateRoute, $cas, $name, $conc, $categoryCanon,
                        $explicitAte['value'], $explicitAte['source'],
                        $source
                    );
                }

                // Phase 4: feed the aquatic buffer for GHS09 routes. CPDs
                // may carry explicit M-factors via the m_factor_* fields.
                $aquaticRoute = $this->canonicalToAquaticRoute($canonical);
                if ($aquaticRoute !== null && $categoryCanon !== '') {
                    $mFactor = $this->resolveMFactor($det, $aquaticRoute, 'cpd');
                    $this->addToAquaticBuffer(
                        $aquaticRoute, $cas, $name, $conc, $categoryCanon,
                        $mFactor['value'], $mFactor['source'],
                        $source
                    );
                    // Aquatic classification is summation-only per GHS 4.1.3.
                    // Don't let the CPD's aquatic entries trigger a
                    // per-component classification (which would bypass the
                    // M-factor summation entirely) — flag triggered=false so
                    // the summation pass is authoritative, and record the
                    // skip in the trace for audit.
                    $this->traceStep('aquatic_per_component_skipped', "CPD {$cas} aquatic — per-component trigger skipped; summation-only per GHS 4.1.3", [
                        'cas' => $cas, 'canonical' => $canonical, 'category' => $categoryCanon,
                        'concentration' => $conc, 'source' => $source,
                    ]);
                    continue;
                }

                if ($conc < $cutoff) {
                    $this->traceStep('cpd_below_cutoff', "CPD {$cas} {$entry['class']} {$entry['category']} below cutoff", [
                        'cas' => $cas, 'class' => $entry['class'], 'category' => $entry['category'],
                        'canonical' => $canonical, 'category_canonical' => $categoryCanon,
                        'concentration' => $conc, 'cutoff' => $cutoff, 'source' => $source,
                    ]);
                    continue;
                }

                $anyTriggered = true;
                $hazardClasses[] = [
                    'class'              => $entry['class'],
                    'category'           => $entry['category'],
                    'canonical'          => $canonical,
                    'category_canonical' => $categoryCanon,
                    'cas'                => $cas,
                    'chemical'           => $name,
                    'concentration_pct'  => $conc,
                    'cutoff_pct'         => $cutoff,
                    'source'             => $source,
                ];
            }
        } else {
            // Free-text hazard_classes path: no category supplied, so the
            // getCutoff() fallback uses the most conservative cutoff for
            // the canonical class. If the class string can't be normalised
            // at all, getCutoff returns the 1% default.
            $classRaw = array_filter(array_map('trim', explode(',', $det['hazard_classes'] ?? '')));
            foreach ($classRaw as $classStr) {
                $canonical = HazardClassAliases::normalize($classStr);
                $cutoff    = $this->getCutoff($canonical ?? '', null);

                // Free-text entries have no category, so they don't land in
                // SUMMATION_RULES (which is keyed by specific categories).
                // Still record the attempt — addToSummationBuffer silently
                // drops uncategorised entries so the buffer stays coherent.
                $this->addToSummationBuffer(
                    $canonical, '', $cas, $name, $conc,
                    $source, $conc >= $cutoff
                );

                if ($conc < $cutoff) {
                    $this->traceStep('cpd_below_cutoff', "CPD {$cas} {$classStr} below cutoff (free-text, no category)", [
                        'cas' => $cas, 'class' => $classStr,
                        'canonical' => $canonical,
                        'concentration' => $conc, 'cutoff' => $cutoff, 'source' => $source,
                    ]);
                    continue;
                }

                $anyTriggered = true;
                $hazardClasses[] = [
                    'class'              => $classStr,
                    'category'           => '',
                    'canonical'          => $canonical,
                    'category_canonical' => '',
                    'cas'                => $cas,
                    'chemical'           => $name,
                    'concentration_pct'  => $conc,
                    'cutoff_pct'         => $cutoff,
                    'source'             => $source,
                ];
            }
        }

        // If no CPD-sourced hazard class triggered, strip H/P-codes,
        // pictograms, and the signal word as well. The CPD's companion
        // statements are only valid contributions when at least one of
        // its declared hazard classes clears a cutoff — otherwise a
        // CPD-declared H350 would leak into the SDS even when the
        // underlying carcinogenicity classification doesn't fire.
        if (!$anyTriggered && (!empty($hStmts) || !empty($pStmts) || !empty($pictograms) || $signalWord !== null)) {
            $this->traceStep('cpd_all_below_cutoff', "CPD {$cas} ({$name}) had no hazard class clear cutoff; suppressing H/P-codes, pictograms, signal word", [
                'cas' => $cas, 'concentration' => $conc, 'source' => $source,
                'dropped_h' => array_keys($hStmts),
                'dropped_p' => array_keys($pStmts),
                'dropped_pictograms' => $pictograms,
                'dropped_signal_word' => $signalWord,
            ]);
            $hStmts     = [];
            $pStmts     = [];
            $pictograms = [];
            $signalWord = null;
        } elseif ($anyTriggered) {
            // When SOME classes fire but not all the CPD declared, filter
            // the companion H-codes and pictograms to drop those whose
            // only associated classes are among the non-triggered ones.
            // Without this, a CPD declaring both Acute Tox Oral Cat 4 and
            // Aquatic Chronic Cat 2 (with h_statements="H302,H411" and
            // pictograms="GHS07,GHS09") would leak H411+GHS09 onto every
            // SDS even when the aquatic class didn't trigger — producing
            // environmental pictograms on products with no environmental
            // hazard classes listed.
            //
            // P-codes are left alone: many (P210, P280, P304+P340, …) are
            // cross-cutting across multiple hazard classes and a strict
            // filter would drop legitimate P-code contributions.
            $triggeredCanonicals = [];
            foreach ($hazardClasses as $hc) {
                if (!empty($hc['canonical'])) {
                    $triggeredCanonicals[$hc['canonical']] = true;
                }
            }
            $hStmts     = $this->filterCpdCodesByTriggeredClasses($hStmts,     $triggeredCanonicals, 'h_codes',    $cas, $source);
            $pictograms = $this->filterCpdCodesByTriggeredClasses($pictograms, $triggeredCanonicals, 'pictograms', $cas, $source);
        }

        // Exposure limits from determination
        $exposureLimits = [];
        $detLimits = json_decode($det['exposure_limits'] ?? '[]', true) ?: [];
        foreach ($detLimits as $el) {
            if (empty($el['value'])) {
                continue;
            }
            $exposureLimits[] = [
                'cas_number'        => $cas,
                'chemical_name'     => $name,
                'concentration_pct' => $conc,
                'limit_type'        => $el['limit_type'] ?? '',
                'value'             => $el['value'] ?? '',
                'units'             => $el['units'] ?? 'mg/m3',
                'notes'             => $el['notes'] ?? '',
                'source'            => $source,
            ];
        }

        return [
            'h_statements'   => $hStmts,
            'p_statements'   => $pStmts,
            'pictograms'     => $pictograms,
            'signal_word'    => $signalWord,
            'hazard_classes' => $hazardClasses,
            'exposure_limits' => $exposureLimits,
        ];
    }

    /**
     * GHS hazard group classification for sort ordering.
     *
     * Group 1 = Physical hazards, Group 2 = Health hazards, Group 3 = Environmental hazards.
     */
    private const HAZARD_GROUP_ORDER = [
        // Physical hazards (Group 1)
        'Explosives'                         => 1,
        'Flammable Gases'                    => 1,
        'Flammable Aerosols'                 => 1,
        'Flammable Liquids'                  => 1,
        'Flammable Solids'                   => 1,
        'Self-Reactive Substances'           => 1,
        'Pyrophoric Liquids'                 => 1,
        'Pyrophoric Solids'                  => 1,
        'Self-Heating Substances'            => 1,
        'Oxidizing Liquids'                  => 1,
        'Oxidizing Solids'                   => 1,
        'Oxidizing Gases'                    => 1,
        'Gases Under Pressure'               => 1,
        'Corrosive to Metals'                => 1,
        'Substances which, in contact with water, emit flammable gases' => 1,

        // Health hazards (Group 2)
        'Acute Toxicity (Oral)'              => 2,
        'Acute Toxicity (Dermal)'            => 2,
        'Acute Toxicity (Inhalation)'        => 2,
        'Skin Corrosion/Irritation'          => 2,
        'Serious Eye Damage/Eye Irritation'  => 2,
        'Respiratory Sensitization'          => 2,
        'Skin Sensitization'                 => 2,
        'Germ Cell Mutagenicity'             => 2,
        'Carcinogenicity'                    => 2,
        'Reproductive Toxicity'              => 2,
        'STOT — Single Exposure'             => 2,
        'STOT — Repeated Exposure'           => 2,
        'Aspiration Hazard'                  => 2,

        // Environmental hazards (Group 3)
        'Hazardous to the Aquatic Environment (Acute)'   => 3,
        'Hazardous to the Aquatic Environment (Chronic)' => 3,
        'Hazardous to the Ozone Layer'                   => 3,
    ];

    /**
     * Consolidate hazard classes: for each unique hazard class, keep only the
     * most severe (lowest numbered) category. Then sort by GHS group order
     * (physical > health > environmental) with most severe categories first.
     *
     * @param  array $hazardClasses  Raw list from classification
     * @return array                 Consolidated and sorted list
     */
    private function consolidateHazardClasses(array $hazardClasses): array
    {
        // Group by class name, keeping only the most severe category per class
        $bestPerClass = [];
        foreach ($hazardClasses as $hc) {
            $class = $hc['class'] ?? '';
            if ($class === '') {
                continue;
            }

            $severity = $this->categoryToSeverity($hc['category'] ?? '');

            if (!isset($bestPerClass[$class])) {
                $bestPerClass[$class] = ['entry' => $hc, 'severity' => $severity];
            } elseif ($severity < $bestPerClass[$class]['severity']) {
                // Lower severity number = more severe (Category 1 < Category 2)
                $bestPerClass[$class] = ['entry' => $hc, 'severity' => $severity];
            }
        }

        // Extract the winning entries
        $consolidated = array_map(fn($item) => $item['entry'], $bestPerClass);

        // Sort by: 1) GHS group (physical=1, health=2, environmental=3)
        //          2) Severity within group (most severe first)
        usort($consolidated, function (array $a, array $b) {
            $groupA = self::HAZARD_GROUP_ORDER[$a['class'] ?? ''] ?? 2;
            $groupB = self::HAZARD_GROUP_ORDER[$b['class'] ?? ''] ?? 2;
            if ($groupA !== $groupB) {
                return $groupA <=> $groupB;
            }
            // Within same group, sort by severity (lower = more severe = listed first)
            $sevA = $this->categoryToSeverity($a['category'] ?? '');
            $sevB = $this->categoryToSeverity($b['category'] ?? '');
            if ($sevA !== $sevB) {
                return $sevA <=> $sevB;
            }
            // Tie-break by class name alphabetically
            return strcmp($a['class'] ?? '', $b['class'] ?? '');
        });

        $beforeCount = count($hazardClasses);
        $afterCount = count($consolidated);
        if ($beforeCount !== $afterCount) {
            $this->traceStep('consolidate', 'Consolidated hazard classes to most severe per class', [
                'before' => $beforeCount,
                'after'  => $afterCount,
            ]);
        }

        return $consolidated;
    }

    /**
     * Convert a GHS category string to a numeric severity value.
     * Lower number = more severe.
     *
     * Handles formats like: "Category 1", "Category 1A", "Category 1 (1A/1B)",
     * "Category 2A", "Division 1.1", "Type A", "Lactation", etc.
     */
    private function categoryToSeverity(string $category): int
    {
        $cat = trim($category);
        if ($cat === '') {
            return 999;
        }

        // Accept both the GHSHazardData display form ("Category 1A") and
        // the HazardClassAliases canonical form ("Cat 1A") so callers
        // don't need to know which representation they're dealing with.
        if (preg_match('/(?:Category|Cat\.?)\s+(\d+)/i', $cat, $m)) {
            $base = (int) $m[1] * 10; // Cat 1 = 10, Cat 2 = 20, etc.
            // Sub-categories: 1A < 1B < 1C (more specific = slightly more severe)
            if (preg_match('/(\d+)([A-C])/i', $cat, $sub)) {
                $base += ord(strtoupper($sub[2])) - ord('A'); // A=0, B=1, C=2
            }
            return $base;
        }

        // "Division 1.1", "Division 1.2", etc. (Explosives)
        if (preg_match('/Division\s+(\d+)\.(\d+)/i', $cat, $m)) {
            return (int) $m[1] * 10 + (int) $m[2];
        }

        // "Type A", "Type B", etc. (Self-Reactive)
        if (preg_match('/Type\s+([A-G])/i', $cat, $m)) {
            return ord(strtoupper($m[1])) - ord('A') + 1; // A=1, B=2, etc.
        }

        // Special categories
        if (stripos($cat, 'Lactation') !== false) {
            return 100;
        }
        if (stripos($cat, 'Compressed') !== false) {
            return 10;
        }
        if (stripos($cat, 'Liquefied') !== false) {
            return 20;
        }
        if (stripos($cat, 'Refrigerated') !== false) {
            return 30;
        }
        if (stripos($cat, 'Dissolved') !== false) {
            return 40;
        }

        return 500; // Unknown categories sort last
    }

    /**
     * Group hazard classes by GHS hazard type (physical, health, environmental).
     *
     * @param  array $hazardClasses  Consolidated hazard class list
     * @return array  ['physical' => [...], 'health' => [...], 'environmental' => [...]]
     */
    public static function groupByHazardType(array $hazardClasses): array
    {
        $groups = ['physical' => [], 'health' => [], 'environmental' => []];
        $groupNames = [1 => 'physical', 2 => 'health', 3 => 'environmental'];

        foreach ($hazardClasses as $hc) {
            $className = $hc['class'] ?? '';
            $group = self::HAZARD_GROUP_ORDER[$className] ?? 2; // default to health
            $groupKey = $groupNames[$group] ?? 'health';
            $groups[$groupKey][] = $hc;
        }

        return $groups;
    }

    private function traceStep(string $type, string $description, array $data): void
    {
        $this->trace[] = [
            'step'        => $type,
            'description' => $description,
            'data'        => $data,
        ];
    }
}
