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

        // Build enriched formula lines for the VOC calculator
        $enrichedLines = $this->enrichFormulaLines($formula['lines'], $warnings);

        // Run VOC calculation
        $vocCalc   = new VOCCalculator($enrichedLines, $vocMode);
        $vocResult = $vocCalc->calculate();

        // Get expanded CAS-level composition
        $composition = Formula::getExpandedComposition((int) $formula['id']);

        return [
            'formula'     => $formula,
            'composition' => $composition,
            'voc'         => $vocResult,
            'warnings'    => $warnings,
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
        $enrichedLines = $this->enrichFormulaLines($formula['lines'], $warnings);
        $vocCalc       = new VOCCalculator($enrichedLines, $vocMode);
        $vocResult     = $vocCalc->calculate();
        $composition   = Formula::getExpandedComposition($formulaId);

        return [
            'formula'     => $formula,
            'composition' => $composition,
            'voc'         => $vocResult,
            'warnings'    => $warnings,
        ];
    }

    /**
     * Enrich formula lines with full raw material data + constituents
     * for the VOC calculator.
     */
    private function enrichFormulaLines(array $lines, array &$warnings): array
    {
        $enriched = [];

        foreach ($lines as $line) {
            $rmId = (int) $line['raw_material_id'];
            $rm   = RawMaterial::findById($rmId);

            if ($rm === null) {
                $warnings[] = "Raw material #{$rmId} not found; skipped.";
                continue;
            }

            $enriched[] = [
                'raw_material_id'       => $rmId,
                'internal_code'         => $rm['internal_code'],
                'supplier_product_name' => $rm['supplier_product_name'],
                'pct'                   => (float) $line['pct'],
                'voc_wt'                => $rm['voc_wt'],
                'exempt_voc_wt'         => $rm['exempt_voc_wt'],
                'water_wt'              => $rm['water_wt'],
                'specific_gravity'      => $rm['specific_gravity'],
                'solids_wt'             => $rm['solids_wt'],
                'solids_vol'            => $rm['solids_vol'],
                'flash_point_c'         => $rm['flash_point_c'],
                'constituents'          => $rm['constituents'] ?? [],
            ];
        }

        if (empty($enriched)) {
            $warnings[] = 'Formula has no valid raw material lines.';
        }

        return $enriched;
    }
}
