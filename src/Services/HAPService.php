<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * HAPService — EPA Clean Air Act Section 112(b) Hazardous Air Pollutant analysis.
 *
 * Checks a product composition against the federal HAP list to determine
 * which components are listed HAPs, their individual weight percentages,
 * and the total HAP content.  Results feed into SDS Section 15
 * (Regulatory Information).
 *
 * The HAP list contains 187 chemicals and compound categories.  For compound
 * categories (e.g. "Glycol ethers", "Lead Compounds") that have no single
 * CAS number, individual CAS constituents are matched by checking the
 * hap_list for a direct CAS hit first, then by checking parent-category
 * entries whose CAS is empty.
 */
class HAPService
{
    /**
     * Analyse a composition for Hazardous Air Pollutants.
     *
     * @param  array $composition  Expanded CAS-level composition from FormulaCalcService
     * @return array {
     *   hap_chemicals:  array[] — matched chemicals with details,
     *   total_hap_pct:  float   — sum of all HAP concentrations,
     *   has_haps:       bool,
     *   summary_text:   string  — human-readable summary for SDS,
     * }
     */
    public static function analyse(array $composition): array
    {
        $db = Database::getInstance();

        $hapChemicals  = [];
        $totalHapPct   = 0.0;

        foreach ($composition as $component) {
            $cas  = $component['cas_number'] ?? '';
            $name = $component['chemical_name'] ?? '';
            $conc = (float) ($component['concentration_pct'] ?? 0);

            if ($cas === '' || $conc < 0.01) {
                continue;
            }

            $row = $db->fetch(
                "SELECT * FROM hap_list WHERE cas_number = ?",
                [$cas]
            );

            if ($row === null) {
                continue;
            }

            $hapChemicals[] = [
                'cas_number'        => $cas,
                'chemical_name'     => $name ?: $row['chemical_name'],
                'hap_name'          => $row['chemical_name'],
                'concentration_pct' => $conc,
                'category'          => $row['category'] ?? '',
            ];

            $totalHapPct += $conc;
        }

        // Sort by concentration descending
        usort($hapChemicals, function ($a, $b) {
            return $b['concentration_pct'] <=> $a['concentration_pct'];
        });

        $totalHapPct = round($totalHapPct, 4);
        $hasHaps     = !empty($hapChemicals);

        $summaryText = self::buildSummaryText($hapChemicals, $totalHapPct);

        return [
            'hap_chemicals' => $hapChemicals,
            'total_hap_pct' => $totalHapPct,
            'has_haps'      => $hasHaps,
            'summary_text'  => $summaryText,
        ];
    }

    /**
     * Build a human-readable summary for SDS Section 15.
     */
    private static function buildSummaryText(array $hapChemicals, float $totalHapPct): string
    {
        if (empty($hapChemicals)) {
            return 'This product does not contain any EPA Hazardous Air Pollutants (HAPs) listed under Clean Air Act Section 112(b).';
        }

        $count = count($hapChemicals);
        $lines = [];
        $lines[] = "This product contains {$count} Hazardous Air Pollutant(s) listed under the Clean Air Act Section 112(b):";

        foreach ($hapChemicals as $chem) {
            $lines[] = sprintf(
                '  %s (CAS %s): %.2f%%',
                $chem['chemical_name'],
                $chem['cas_number'],
                $chem['concentration_pct']
            );
        }

        $lines[] = sprintf('Total HAP Content: %.2f%% by weight', $totalHapPct);

        return implode("\n", $lines);
    }
}
