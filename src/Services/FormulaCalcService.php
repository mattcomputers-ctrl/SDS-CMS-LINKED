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
     * Enrich formula lines with full raw material data + constituents
     * for the VOC calculator.
     *
     * When a raw material's constituents contain CAS numbers on the
     * admin-managed exempt VOC list, the exempt_voc_wt field is
     * auto-adjusted upward to account for that exempt content.
     */
    private function enrichFormulaLines(array $lines, array &$warnings, array $exemptVocCasList = []): array
    {
        $enriched = [];

        foreach ($lines as $line) {
            $rmId = (int) $line['raw_material_id'];
            $rm   = RawMaterial::findById($rmId);

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
                'physical_state'           => $rm['physical_state'] ?? null,
                'solubility'               => $rm['solubility'] ?? null,
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
     *  - solubility: formula-level solubility string
     *  - has_non_powder_material: true if any RM is not Powder physical state
     */
    private function deriveFormulaProperties(array $enrichedLines): array
    {
        $allVocLessThanOne = true;
        $lowestFp          = null;
        $lowestFpGt        = false;
        $solubilities      = [];

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

            // Solubility: collect all non-empty values
            $sol = $line['solubility'] ?? null;
            if ($sol !== null && $sol !== '') {
                $solubilities[] = $sol;
            }
        }

        // Determine formula-level solubility
        $solubility = '';
        if (!empty($solubilities)) {
            $unique = array_unique($solubilities);
            if (count($unique) === 1) {
                $solubility = $unique[0]; // All same
            } else {
                $solubility = 'Partially soluble in water'; // Mixed
            }
        }

        return [
            'all_voc_less_than_one'    => $allVocLessThanOne,
            'flash_point_c'            => $lowestFp,
            'flash_point_greater_than' => $lowestFpGt,
            'solubility'               => $solubility,
            'enriched_lines'           => $enrichedLines,
        ];
    }

    /**
     * Load the admin-managed exempt VOC list as a CAS-keyed lookup array.
     *
     * @return array<string, string>  CAS number => chemical name
     */
    private function loadExemptVocList(): array
    {
        $db   = Database::getInstance();
        $rows = $db->fetchAll("SELECT cas_number, chemical_name FROM exempt_voc_list");

        $map = [];
        foreach ($rows as $row) {
            $map[$row['cas_number']] = $row['chemical_name'];
        }
        return $map;
    }
}
