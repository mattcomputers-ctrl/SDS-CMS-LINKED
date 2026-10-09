<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;
use SDS\Models\Formula;
use SDS\Models\RawMaterial;

/**
 * FormulaCalcService — Orchestrates formula expansion + VOC calculation.
 *
 * Given a finished good ID, this service loads the current formula,
 * expands the CAS-level composition, runs the VOC calculator, and
 * returns a single unified result set ready for the SDS generator.
 */
class FormulaCalcService
{
    /** @var array<string, string>|null Cached exempt VOC list (shared across instances). */
    private static ?array $exemptVocCache = null;

    /**
     * #18(d) Soluble-fraction weight per raw material solubility value.
     * Keys are the exact option strings from src/Views/raw-materials/form.php.
     * Any other non-blank value counts in the denominator with weight 0.
     */
    public const SOLUBILITY_WEIGHTS = [
        'Soluble in water'               => 1.0,
        'Partially soluble in water'     => 0.5,
        'Negligible solubility in water' => 0.0,
        'Insoluble in water'             => 0.0,
    ];

    /** #18(d) Soluble-fraction thresholds (percent of the raws that have a value). */
    public const SOLUBLE_PCT    = 90.0;
    public const PARTIAL_PCT    = 5.0;
    public const NEGLIGIBLE_PCT = 1.0;

    /**
     * Run the full calculation pipeline for a finished good's current formula.
     *
     * @param  int    $finishedGoodId
     * @param  string $vocMode  'method24_standard' or 'method24_less_water_exempt'
     * @return array  {
     *   formula: array,
     *   composition: array,
     *   voc: array,
     *   warnings: string[],
     * }
     * @throws \RuntimeException if no current formula exists.
     */
    public function calculate(int $finishedGoodId, string $vocMode = 'method24_standard'): array
    {
        $formula = Formula::findCurrentByFinishedGood($finishedGoodId);
        if ($formula === null) {
            throw new \RuntimeException('No current formula found for finished good #' . $finishedGoodId);
        }

        $warnings = [];

        // Load the admin-managed exempt VOC CAS list
        $exemptVocCasList = $this->loadExemptVocList();

        // Build enriched formula lines for the VOC calculator
        $enrichedLines = $this->enrichFormulaLines($formula['lines'], $warnings, $exemptVocCasList);

        // Run VOC calculation
        $vocCalc   = new VOCCalculator($enrichedLines, $vocMode);
        $vocResult = $vocCalc->calculate();

        // Get expanded CAS-level composition
        $composition = Formula::getExpandedComposition((int) $formula['id']);

        // Derive formula-level properties from enriched lines
        $formulaProps = $this->deriveFormulaProperties($enrichedLines);

        return [
            'formula'        => $formula,
            'composition'    => $composition,
            'voc'            => $vocResult,
            'formula_props'  => $formulaProps,
            'warnings'       => $warnings,
        ];
    }

    /**
     * Run calculations for a specific formula version (not necessarily current).
     */
    public function calculateForFormula(int $formulaId, string $vocMode = 'method24_standard'): array
    {
        $formula = Formula::findById($formulaId);
        if ($formula === null) {
            throw new \RuntimeException('Formula #' . $formulaId . ' not found.');
        }

        $warnings      = [];
        $exemptVocCasList = $this->loadExemptVocList();
        $enrichedLines = $this->enrichFormulaLines($formula['lines'], $warnings, $exemptVocCasList);
        $vocCalc       = new VOCCalculator($enrichedLines, $vocMode);
        $vocResult     = $vocCalc->calculate();
        $composition   = Formula::getExpandedComposition($formulaId);
        $formulaProps  = $this->deriveFormulaProperties($enrichedLines);

        return [
            'formula'        => $formula,
            'composition'    => $composition,
            'voc'            => $vocResult,
            'formula_props'  => $formulaProps,
            'warnings'       => $warnings,
        ];
    }

    /**
     * Calculate the SDS-generation pipeline for a resale raw material,
     * i.e. "this alias is 100 % of this RM — no formula table row."
     *
     * Returns the same shape as calculate() so the SDS generator can
     * consume either result without branching on source. There is no
     * real formula row; a synthetic lines array with a single 100 %
     * entry feeds the existing enrichment + VOC pipeline. Composition
     * is computed directly from the RM's constituents (or a single
     * TRADE_SECRET bucket for hazardous_no_cas RMs), mirroring what
     * Formula::getExpandedComposition would produce for the same RM.
     *
     * @throws \RuntimeException if the RM doesn't exist.
     */
    public function calculateForRawMaterial(int $rawMaterialId, string $vocMode = 'method24_standard'): array
    {
        $rm = RawMaterial::findById($rawMaterialId);
        if ($rm === null) {
            throw new \RuntimeException('Raw material #' . $rawMaterialId . ' not found.');
        }

        $warnings = [];
        $exemptVocCasList = $this->loadExemptVocList();

        // Synthetic single-line "formula": pretend this RM is a one-
        // ingredient product at 100 %. Shape matches what getLines()
        // returns so the existing enrichment path works unchanged.
        $syntheticLines = [[
            'id'                         => null,
            'formula_id'                 => null,
            'raw_material_id'            => $rawMaterialId,
            'finished_good_component_id' => null,
            'pct'                        => 100.0,
            'sort_order'                 => 1,
            'internal_code'              => $rm['internal_code'],
            'supplier'                   => $rm['supplier'],
            'supplier_product_name'      => $rm['supplier_product_name'],
            'voc_wt'                     => $rm['voc_wt'],
            'exempt_voc_wt'              => $rm['exempt_voc_wt'],
            'water_wt'                   => $rm['water_wt'],
            'flash_point_c'              => $rm['flash_point_c'],
            'component_product_code'     => null,
            'component_description'      => null,
            'line_type'                  => 'raw_material',
        ]];

        $enrichedLines = $this->enrichFormulaLines($syntheticLines, $warnings, $exemptVocCasList);

        $vocCalc   = new VOCCalculator($enrichedLines, $vocMode);
        $vocResult = $vocCalc->calculate();

        $composition  = $this->buildResaleComposition($rm);
        $formulaProps = $this->deriveFormulaProperties($enrichedLines);

        // Synthetic formula header so downstream consumers that read
        // $calcResult['formula']['lines'] (e.g. manual Prop 65 / HAPs
        // collection) keep working.
        $syntheticFormula = [
            'id'              => null,
            'finished_good_id' => null,
            'version'         => 1,
            'is_current'      => 1,
            'product_code'    => $rm['internal_code'],
            'created_by_name' => null,
            'created_at'      => null,
            'updated_at'      => null,
            'lines'           => $syntheticLines,
        ];

        return [
            'formula'       => $syntheticFormula,
            'composition'   => $composition,
            'voc'           => $vocResult,
            'formula_props' => $formulaProps,
            'warnings'      => $warnings,
        ];
    }

    /**
     * Build the expanded composition for a single RM at 100 %, matching
     * the shape Formula::getExpandedComposition returns. Handles both
     * CAS-constituent RMs and trade-secret (hazardous_no_cas) RMs.
     */
    private function buildResaleComposition(array $rm): array
    {
        $rmId = (int) $rm['id'];

        // Trade-secret RM: vendor disclosed GHS classifications but no
        // CAS constituents — emit a single synthetic bucket, matching
        // Formula::getExpandedComposition's TRADE_SECRET convention.
        if ((int) ($rm['hazardous_no_cas'] ?? 0) === 1) {
            $manual = [];
            if (!empty($rm['manual_hazard_json'])) {
                $decoded = is_string($rm['manual_hazard_json'])
                    ? json_decode($rm['manual_hazard_json'], true)
                    : $rm['manual_hazard_json'];
                if (is_array($decoded)) {
                    $manual[] = $decoded;
                }
            }
            return [[
                'cas_number'               => 'TRADE_SECRET',
                'chemical_name'            => 'Trade Secret',
                'concentration_pct'        => 100.0,
                'is_trade_secret'          => true,
                'is_non_hazardous'         => false,
                'trade_secret_description' => 'Trade Secret',
                'manual_hazard_json'       => $manual,
                'contributing_materials'   => [[
                    'raw_material_id' => $rmId,
                    'internal_code'   => $rm['internal_code'],
                    'pct_in_rm'       => 100.0,
                    'pct_in_formula'  => 100.0,
                ]],
            ]];
        }

        $buckets = [];
        foreach ($rm['constituents'] ?? [] as $c) {
            $cas = $c['cas_number'];
            if ($cas === null || $cas === '') {
                continue;
            }

            $pct = $this->resolveConstituentPct($c);

            if (!isset($buckets[$cas])) {
                $buckets[$cas] = [
                    'cas_number'               => $cas,
                    'chemical_name'            => $c['chemical_name'],
                    'concentration_pct'        => 0.0,
                    'is_trade_secret'          => (int) ($c['is_trade_secret'] ?? 0) === 1,
                    'is_non_hazardous'         => (int) ($c['is_non_hazardous'] ?? 0) === 1,
                    'trade_secret_description' => $c['trade_secret_description'] ?? null,
                    // Audit #19: cas_master element flags for Section 10 decomposition products
                    'has_nitrogen'             => (int) ($c['has_nitrogen'] ?? 0) === 1,
                    'has_sulfur'               => (int) ($c['has_sulfur'] ?? 0) === 1,
                    'has_halogen'              => (int) ($c['has_halogen'] ?? 0) === 1,
                    'contributing_materials'   => [],
                ];
            }

            // For a 100 % RM the in-formula contribution equals the
            // constituent's percentage in the RM.
            $buckets[$cas]['concentration_pct'] += $pct;
            $buckets[$cas]['contributing_materials'][] = [
                'raw_material_id' => $rmId,
                'internal_code'   => $rm['internal_code'],
                'pct_in_rm'       => $pct,
                'pct_in_formula'  => $pct,
            ];
        }

        foreach ($buckets as &$bucket) {
            $bucket['concentration_pct'] = round($bucket['concentration_pct'], 4);
        }
        unset($bucket);

        $result = array_values($buckets);
        usort($result, fn(array $a, array $b): int => $b['concentration_pct'] <=> $a['concentration_pct']);
        return $result;
    }

    private function resolveConstituentPct(array $c): float
    {
        if (($c['pct_exact'] ?? null) !== null) {
            return (float) $c['pct_exact'];
        }
        if (($c['pct_min'] ?? null) !== null && ($c['pct_max'] ?? null) !== null) {
            return ((float) $c['pct_min'] + (float) $c['pct_max']) / 2.0;
        }
        if (($c['pct_min'] ?? null) !== null) {
            return (float) $c['pct_min'];
        }
        if (($c['pct_max'] ?? null) !== null) {
            return (float) $c['pct_max'];
        }
        return 0.0;
    }

    /**
     * Enrich formula lines with full raw material data + constituents
     * for the VOC calculator.
     *
     * Handles both raw material lines and finished good component lines.
     * Finished good components are recursively expanded: their current
     * formula's lines are enriched and scaled by the component's percentage.
     *
     * When a raw material's constituents contain CAS numbers on the
     * admin-managed exempt VOC list, the exempt_voc_wt field is
     * auto-adjusted upward to account for that exempt content.
     */
    private function enrichFormulaLines(array $lines, array &$warnings, array $exemptVocCasList = [], array $ancestorFgIds = []): array
    {
        $enriched = [];

        // Batch-fetch all raw materials needed by this level's lines
        $rmIds = [];
        foreach ($lines as $line) {
            if (($line['line_type'] ?? 'raw_material') === 'raw_material') {
                $rmId = (int) ($line['raw_material_id'] ?? 0);
                if ($rmId > 0) {
                    $rmIds[] = $rmId;
                }
            }
        }

        $rmCache = [];
        if (!empty($rmIds)) {
            $rmIds = array_unique($rmIds);
            foreach ($rmIds as $id) {
                $rm = RawMaterial::findById($id);
                if ($rm !== null) {
                    $rmCache[$id] = $rm;
                }
            }
        }

        foreach ($lines as $line) {
            $lineType = $line['line_type'] ?? 'raw_material';

            // --- Finished Good component line ---
            if ($lineType === 'finished_good') {
                $compFgId = (int) ($line['finished_good_component_id'] ?? 0);
                if ($compFgId <= 0) {
                    $warnings[] = "Finished good component line with no ID; skipped.";
                    continue;
                }

                // Cycle guard
                if (in_array($compFgId, $ancestorFgIds, true)) {
                    $warnings[] = "Circular reference detected for finished good #{$compFgId}; skipped.";
                    continue;
                }

                $compFormula = Formula::findCurrentByFinishedGood($compFgId);
                if ($compFormula === null) {
                    $warnings[] = "Finished good component #{$compFgId} has no current formula; skipped.";
                    continue;
                }

                // Recursively enrich the sub-formula's lines
                $subAncestors = array_merge($ancestorFgIds, [$compFgId]);
                $subEnriched = $this->enrichFormulaLines(
                    $compFormula['lines'],
                    $warnings,
                    $exemptVocCasList,
                    $subAncestors
                );

                // Scale each sub-line by this component's percentage
                $scaleFactor = (float) $line['pct'] / 100.0;
                foreach ($subEnriched as $subLine) {
                    $subLine['pct'] = $subLine['pct'] * $scaleFactor;
                    $enriched[] = $subLine;
                }

                continue;
            }

            // --- Raw Material line ---
            $rmId = (int) $line['raw_material_id'];
            $rm   = $rmCache[$rmId] ?? null;

            if ($rm === null) {
                $warnings[] = "Raw material #{$rmId} not found; skipped.";
                continue;
            }

            // Check constituents against the exempt VOC list and
            // auto-calculate additional exempt VOC weight if applicable.
            $exemptVocWt = (float) ($rm['exempt_voc_wt'] ?? 0);
            $autoExempt  = 0.0;
            foreach ($rm['constituents'] ?? [] as $constituent) {
                $cas = $constituent['cas_number'] ?? '';
                if ($cas !== '' && isset($exemptVocCasList[$cas])) {
                    $pct = $constituent['pct_exact']
                        ?? (($constituent['pct_min'] !== null && $constituent['pct_max'] !== null)
                            ? (((float) $constituent['pct_min'] + (float) $constituent['pct_max']) / 2.0)
                            : (float) ($constituent['pct_min'] ?? $constituent['pct_max'] ?? 0));
                    $autoExempt += (float) $pct;
                }
            }
            if ($autoExempt > 0 && $autoExempt > $exemptVocWt) {
                $warnings[] = "{$rm['internal_code']}: exempt VOC auto-adjusted from {$exemptVocWt}% to {$autoExempt}% based on exempt VOC list.";
                $exemptVocWt = $autoExempt;
            }

            $enriched[] = [
                'raw_material_id'          => $rmId,
                'internal_code'            => $rm['internal_code'],
                'supplier_product_name'    => $rm['supplier_product_name'],
                'pct'                      => (float) $line['pct'],
                'voc_wt'                   => $rm['voc_wt'],
                'voc_less_than_one'        => (int) ($rm['voc_less_than_one'] ?? 0),
                'exempt_voc_wt'            => $exemptVocWt,
                'water_wt'                 => $rm['water_wt'],
                'specific_gravity'         => $rm['specific_gravity'],
                'solids_wt'                => $rm['solids_wt'],
                'solids_vol'               => $rm['solids_vol'],
                'flash_point_c'            => $rm['flash_point_c'],
                'flash_point_greater_than' => (int) ($rm['flash_point_greater_than'] ?? 0),
                'boiling_point_c'          => $rm['boiling_point_c'] ?? null,
                'physical_state'           => $rm['physical_state'] ?? null,
                'solubility'               => $rm['solubility'] ?? null,
                'appearance'               => $rm['appearance'] ?? null,
                'odor'                     => $rm['odor'] ?? null,
                'constituents'             => $rm['constituents'] ?? [],
            ];
        }

        if (empty($enriched)) {
            $warnings[] = 'Formula has no valid raw material lines.';
        }

        return $enriched;
    }

    /**
     * Derive formula-level properties from enriched lines.
     *
     * Returns:
     *  - all_voc_less_than_one: true if every RM has the <1% VOC flag
     *  - flash_point_c: lowest flash point across all RMs (null if none set)
     *  - flash_point_greater_than: true only if the lowest-FP RM has the ">" flag
     *  - boiling_point_c: lowest boiling point across all RMs that carry one, weight-independent (null if none) (#16)
     *  - solubility_key: 'soluble' | 'partially_soluble' | 'negligible' | 'not_soluble' | null
     *    (#18(d): F = (soluble wt% + 0.5 x partially-soluble wt%) / (wt% of raws with any
     *    solubility value); >=90 soluble, 5-90 partially, 1-5 negligible, <1 not soluble;
     *    null when no raw carries a value)
     *  - soluble_fraction_pct: F as a percentage (null when no raw carries a value)
     *  - physical_state: physical_state of the dominant raw material ('' if blank) (#18(b))
     *  - odor: odor of the dominant (highest summed wt%) raw material ('' if blank)
     *  - appearance: appearance of the dominant raw material ('' if blank)
     *  - dominant_raw_material_id / dominant_raw_material_pct: which RM was used (null if no lines)
     */
    private function deriveFormulaProperties(array $enrichedLines): array
    {
        $allVocLessThanOne = true;
        $lowestFp          = null;
        $lowestFpGt        = false;
        $lowestBp          = null; // #16 initial boiling point
        $solWithValuePct   = 0.0; // #18(d) wt% of raws that carry any solubility value
        $solWeightedPct    = 0.0; // #18(d) soluble wt% + 0.5 x partially-soluble wt%
        $byRm              = []; // raw_material_id => ['pct', 'odor', 'appearance', 'physical_state'] (#17/#18)

        foreach ($enrichedLines as $line) {
            // VOC <1% logic: all lines must have the flag set
            if ((int) ($line['voc_less_than_one'] ?? 0) === 0) {
                $allVocLessThanOne = false;
            }

            // Flash point: find the lowest across all RMs
            $fp = $line['flash_point_c'] ?? null;
            if ($fp !== null && $fp !== '') {
                $fpVal = (float) $fp;
                if ($lowestFp === null || $fpVal < $lowestFp) {
                    $lowestFp   = $fpVal;
                    $lowestFpGt = (bool) ($line['flash_point_greater_than'] ?? false);
                }
            }

            // Initial boiling point (#16): the lowest across all RMs that
            // carry one. Lines are already flattened through sub-FGs, so
            // this is recursive; wt% is ignored (weight-independent) and
            // RMs with no value are skipped. No weighted average.
            $bp = $line['boiling_point_c'] ?? null;
            if ($bp !== null && $bp !== '') {
                $bpVal = (float) $bp;
                if ($lowestBp === null || $bpVal < $lowestBp) {
                    $lowestBp = $bpVal;
                }
            }

            // #18(d) Solubility: soluble fraction over the raws that have a value.
            // Lines are already scaled through sub-FGs, so pct is the share of
            // the finished good. Negligible / Insoluble / unknown strings weigh 0.
            $sol = trim((string) ($line['solubility'] ?? ''));
            if ($sol !== '') {
                $linePct          = (float) ($line['pct'] ?? 0);
                $solWithValuePct += $linePct;
                $solWeightedPct  += $linePct * (self::SOLUBILITY_WEIGHTS[$sol] ?? 0.0);
            }

            // Dominant RM (#17): lines are already scaled through sub-FGs and
            // the same RM can appear on several lines, so sum wt% per RM
            // before ranking. Odor/appearance are per-RM, so first line wins.
            $rmId = (int) ($line['raw_material_id'] ?? 0);
            if (!isset($byRm[$rmId])) {
                $byRm[$rmId] = [
                    'pct'            => 0.0,
                    'odor'           => trim((string) ($line['odor'] ?? '')),
                    'appearance'     => trim((string) ($line['appearance'] ?? '')),
                    'physical_state' => trim((string) ($line['physical_state'] ?? '')), // #18(b)
                ];
            }
            $byRm[$rmId]['pct'] += (float) ($line['pct'] ?? 0);
        }

        // #18(d) Formula-level solubility band from the soluble fraction.
        $solubleFractionPct = null;
        $solubilityKey      = null;
        if ($solWithValuePct > 0) {
            $solubleFractionPct = 100.0 * $solWeightedPct / $solWithValuePct;
            $solubilityKey      = self::solubilityKeyForFraction($solubleFractionPct);
        }

        // Dominant (highest summed wt%) raw material. Strict ">" keeps the
        // first-seen RM on a tie so the result is stable for a given line order.
        $dominantId  = null;
        $dominantPct = -1.0;
        foreach ($byRm as $rmId => $agg) {
            if ($agg['pct'] > $dominantPct) {
                $dominantPct = $agg['pct'];
                $dominantId  = $rmId;
            }
        }
        $dominant = $dominantId !== null
            ? $byRm[$dominantId]
            : ['pct' => 0.0, 'odor' => '', 'appearance' => '', 'physical_state' => ''];

        return [
            'all_voc_less_than_one'     => $allVocLessThanOne,
            'flash_point_c'             => $lowestFp,
            'flash_point_greater_than'  => $lowestFpGt,
            'boiling_point_c'           => $lowestBp,
            'solubility_key'            => $solubilityKey,
            'soluble_fraction_pct'      => $solubleFractionPct !== null ? round($solubleFractionPct, 4) : null,
            'physical_state'            => $dominant['physical_state'],
            'odor'                      => $dominant['odor'],
            'appearance'                => $dominant['appearance'],
            'dominant_raw_material_id'  => $dominantId,
            'dominant_raw_material_pct' => $dominantId !== null ? round($dominantPct, 4) : null,
            'enriched_lines'            => $enrichedLines,
        ];
    }

    /**
     * #18(d) Band for a soluble fraction (percent). Pure, so the thresholds
     * are unit-testable without a DB.
     */
    public static function solubilityKeyForFraction(float $fractionPct): string
    {
        if ($fractionPct >= self::SOLUBLE_PCT) {
            return 'soluble';
        }
        if ($fractionPct >= self::PARTIAL_PCT) {
            return 'partially_soluble';
        }
        if ($fractionPct >= self::NEGLIGIBLE_PCT) {
            return 'negligible';
        }
        return 'not_soluble';
    }

    /**
     * Load the admin-managed exempt VOC list as a CAS-keyed lookup array.
     *
     * @return array<string, string>  CAS number => chemical name
     */
    private function loadExemptVocList(): array
    {
        if (self::$exemptVocCache !== null) {
            return self::$exemptVocCache;
        }

        $db   = Database::getInstance();
        $rows = $db->fetchAll("SELECT cas_number, chemical_name FROM exempt_voc_list");

        $map = [];
        foreach ($rows as $row) {
            $map[$row['cas_number']] = $row['chemical_name'];
        }

        self::$exemptVocCache = $map;
        return $map;
    }
}
