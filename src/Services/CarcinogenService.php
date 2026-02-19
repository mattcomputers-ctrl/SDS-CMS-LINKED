<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * CarcinogenService — IARC, NTP, and OSHA carcinogen registry lookups.
 *
 * Checks composition components against the carcinogen_list table
 * to determine if any ingredients are listed as carcinogens or have
 * other toxicological classifications by the three major agencies:
 *
 *  - IARC: International Agency for Research on Cancer
 *     Group 1   = Carcinogenic to humans
 *     Group 2A  = Probably carcinogenic to humans
 *     Group 2B  = Possibly carcinogenic to humans
 *     Group 3   = Not classifiable
 *
 *  - NTP: National Toxicology Program (14th Report on Carcinogens)
 *     Known    = Known to be a human carcinogen
 *     RAHC     = Reasonably anticipated to be a human carcinogen
 *
 *  - OSHA: Occupational Safety and Health Administration
 *     Listed   = Regulated carcinogen per 29 CFR 1910.1003-1910.1016
 */
class CarcinogenService
{
    /**
     * Check a composition against the carcinogen registry.
     *
     * @param  array $composition  Expanded CAS-level composition
     * @return array {
     *   findings: array of per-component carcinogen listings,
     *   has_carcinogens: bool,
     *   summary_text: string  (for Section 11),
     *   component_texts: array  (per-CAS text for Section 11),
     * }
     */
    public static function analyse(array $composition): array
    {
        $db = Database::getInstance();

        $findings       = [];
        $componentTexts = [];

        foreach ($composition as $component) {
            $cas  = $component['cas_number'] ?? '';
            $name = $component['chemical_name'] ?? '';
            $conc = (float) ($component['concentration_pct'] ?? 0);

            if ($cas === '' || $conc < 0.01) {
                continue;
            }

            $rows = $db->fetchAll(
                "SELECT * FROM carcinogen_list WHERE cas_number = ? ORDER BY agency",
                [$cas]
            );

            if (empty($rows)) {
                continue;
            }

            $agencies = [];
            foreach ($rows as $row) {
                $agencies[] = [
                    'agency'         => $row['agency'],
                    'classification' => $row['classification'],
                    'description'    => $row['description'] ?? '',
                ];
            }

            $displayName = $name ?: $rows[0]['chemical_name'];

            $finding = [
                'cas_number'        => $cas,
                'chemical_name'     => $displayName,
                'concentration_pct' => $conc,
                'agencies'          => $agencies,
            ];

            $findings[] = $finding;

            // Build per-component text
            $agencyParts = [];
            foreach ($agencies as $a) {
                $agencyParts[] = $a['agency'] . ': ' . $a['classification'];
            }
            $componentTexts[$cas] = $displayName . ' (CAS ' . $cas . '): Listed by ' . implode('; ', $agencyParts) . '.';
        }

        $hasCarcinogens = !empty($findings);
        $summaryText = self::buildSummaryText($findings);

        return [
            'findings'        => $findings,
            'has_carcinogens' => $hasCarcinogens,
            'summary_text'    => $summaryText,
            'component_texts' => $componentTexts,
        ];
    }

    /**
     * Build a summary text for Section 11 carcinogenicity.
     */
    private static function buildSummaryText(array $findings): string
    {
        if (empty($findings)) {
            return 'No components of this product are listed as carcinogens by IARC, NTP, or OSHA.';
        }

        $lines = [];
        foreach ($findings as $f) {
            $parts = [];
            foreach ($f['agencies'] as $a) {
                $parts[] = $a['agency'] . ' ' . $a['classification'];
            }
            $lines[] = $f['chemical_name'] . ' (CAS ' . $f['cas_number'] . ', '
                     . round($f['concentration_pct'], 2) . '%): '
                     . implode('; ', $parts);
        }

        return "The following component(s) are listed as carcinogens:\n" . implode("\n", $lines);
    }

    /**
     * Get exposure limits specifically for listed carcinogens in the composition.
     */
    public static function getExposureLimits(array $composition): array
    {
        $db = Database::getInstance();
        $limits = [];

        foreach ($composition as $component) {
            $cas  = $component['cas_number'] ?? '';
            $name = $component['chemical_name'] ?? '';
            $conc = (float) ($component['concentration_pct'] ?? 0);

            if ($cas === '' || $conc < 0.01) {
                continue;
            }

            // Only get limits for carcinogen-listed chemicals
            $isCarcinogen = $db->fetch(
                "SELECT id FROM carcinogen_list WHERE cas_number = ? LIMIT 1",
                [$cas]
            );

            if ($isCarcinogen === null) {
                continue;
            }

            $casLimits = $db->fetchAll(
                "SELECT el.*
                 FROM exposure_limits el
                 JOIN hazard_source_records hsr ON hsr.id = el.hazard_source_record_id
                 WHERE el.cas_number = ? AND hsr.is_current = 1",
                [$cas]
            );

            foreach ($casLimits as $limit) {
                $limits[] = [
                    'cas_number'    => $cas,
                    'chemical_name' => $name,
                    'concentration_pct' => $conc,
                    'limit_type'    => $limit['limit_type'],
                    'value'         => $limit['value'],
                    'units'         => $limit['units'],
                ];
            }
        }

        return $limits;
    }
}
