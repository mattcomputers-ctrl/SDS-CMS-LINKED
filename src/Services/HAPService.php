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
 * The HAP list contains 187 chemicals and compound categories. A constituent
 * is a HAP when its CAS is a hap_list row or (finding #26) when it belongs
 * to a 112(b) compound category — metal compounds by element, glycol ethers
 * by explicit member list (RegulatoryCategoryService, migration 058); a
 * category member is reported under the category name.
 */
class HAPService
{
    /**
     * Remove manual haps_data entries whose CAS is already present in the
     * RM's constituents. Mirror of Prop65Service::pruneManualEntriesAgainst
     * Constituents — the RM form's Auto-detected HAPs section renders
     * those CAS matches from hap_list directly, so keeping a manual copy
     * would duplicate the chemical in the generated SDS.
     *
     * Entries with no CAS (name-only, e.g. "Glycol ethers — mixed") or a
     * CAS that doesn't appear in constituents are kept untouched.
     *
     * @param  array $constituents  list of rows, each with 'cas_number'
     * @param  array $hapsData      list of manual entries, each with 'cas_number'
     * @return array{pruned: array, removed_count: int}
     */
    public static function pruneManualEntriesAgainstConstituents(array $constituents, array $hapsData): array
    {
        $casInConstituents = [];
        foreach ($constituents as $c) {
            $cas = trim((string) ($c['cas_number'] ?? ''));
            if ($cas !== '') {
                $casInConstituents[$cas] = true;
            }
        }

        $kept    = [];
        $removed = 0;
        foreach ($hapsData as $entry) {
            $cas = trim((string) ($entry['cas_number'] ?? ''));
            if ($cas !== '' && isset($casInConstituents[$cas])) {
                $removed++;
                continue;
            }
            $kept[] = $entry;
        }

        return ['pruned' => array_values($kept), 'removed_count' => $removed];
    }

    /**
     * Analyse a composition for Hazardous Air Pollutants.
     *
     * @param  array $composition    Expanded CAS-level composition from FormulaCalcService
     * @param  array $manualEntries  Optional manual HAP entries from raw materials
     * @return array { hap_chemicals: array[], total_hap_pct: float, has_haps: bool }
     * Entries carry cas_number / chemical_name / hap_name / concentration_pct
     * (+ hap_category for a category member, whose hap_name is the category
     * name). Section 15 bands every percentage, the total included (#42, #70).
     */
    public static function analyse(array $composition, array $manualEntries = []): array
    {
        $names = [];
        foreach ($composition as $c) {
            $cas = (string) ($c['cas_number'] ?? '');
            if ($cas === '' || $cas === 'TRADE_SECRET' || (float) ($c['concentration_pct'] ?? 0) < 0.01) {
                continue;
            }
            $names[$cas] = [(string) ($c['chemical_name'] ?? '')];
        }
        $direct     = [];
        $categories = [];
        if ($names !== []) {
            $db   = Database::getInstance();
            $keys = array_map('strval', array_keys($names));
            $ph   = implode(',', array_fill(0, count($keys), '?'));
            foreach ($db->fetchAll("SELECT cas_number, chemical_name FROM hap_list WHERE cas_number IN ({$ph})", $keys) as $r) {
                $direct[(string) $r['cas_number']] = $r;
            }
            $categories = RegulatoryCategoryService::matchForCas(RegulatoryCategoryService::LIST_HAP, $names);
        }
        return self::evaluate($composition, $manualEntries, $direct, $categories);
    }

    /**
     * DB-free core of analyse().
     * @param array $direct          cas => hap_list row
     * @param array $categoryMatches cas => list of regulatory_categories rows
     */
    public static function evaluate(array $composition, array $manualEntries, array $direct, array $categoryMatches): array
    {
        $hapChemicals = [];
        $totalHapPct  = 0.0;

        foreach ($composition as $component) {
            $cas  = (string) ($component['cas_number'] ?? '');
            $name = (string) ($component['chemical_name'] ?? '');
            $conc = (float) ($component['concentration_pct'] ?? 0);
            if ($cas === '' || $cas === 'TRADE_SECRET' || $conc < 0.01) {
                continue;
            }
            $row = $direct[$cas] ?? null;
            $cat = $categoryMatches[$cas][0] ?? null;
            if ($row === null && $cat === null) {
                continue;
            }
            $hapName = $row !== null ? (string) $row['chemical_name'] : (string) $cat['category_name'];
            $entry = [
                'cas_number'        => $cas,
                'chemical_name'     => $name !== '' ? $name : $hapName,
                'hap_name'          => $hapName,
                'concentration_pct' => $conc,
            ];
            if ($row === null) {
                $entry['hap_category'] = (string) $cat['category_code'];
            }
            $hapChemicals[] = $entry;
            $totalHapPct += $conc;
        }

        // Include manual HAP entries from raw materials
        foreach ($manualEntries as $manual) {
            $chemName = $manual['chemical_name'] ?? '';
            if ($chemName === '') {
                continue;
            }
            $conc = (float) ($manual['concentration_pct'] ?? 0);
            if ($conc <= 0) {
                continue;
            }
            $hapChemicals[] = [
                'cas_number'        => $manual['cas_number'] ?? '',
                'chemical_name'     => $chemName,
                'hap_name'          => $chemName,
                'concentration_pct' => $conc,
            ];
            $totalHapPct += $conc;
        }

        usort($hapChemicals, static fn ($a, $b) => $b['concentration_pct'] <=> $a['concentration_pct']);

        return [
            'hap_chemicals' => $hapChemicals,
            'total_hap_pct' => round($totalHapPct, 4),
            'has_haps'      => $hapChemicals !== [],
        ];
    }
}
