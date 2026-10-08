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
 *  - NTP: National Toxicology Program (Report on Carcinogens, latest edition)
 *     Known    = Known to be a human carcinogen
 *     RAHC     = Reasonably anticipated to be a human carcinogen
 *
 *  - OSHA: Occupational Safety and Health Administration
 *     Listed   = Regulated carcinogen per 29 CFR 1910.1003-1910.1016
 */
class CarcinogenService
{
    /**
     * Minimum concentration (wt %) at which a registry-listed carcinogen is
     * reported in Section 11. Matches the GHS/HazCom cut-off for
     * Carcinogenicity Cat 1A/1B/2 (29 CFR 1910.1200 App. A, Table A.6.1)
     * used by HazardEngine::HEALTH_CUTOFFS and SDSGenerator::applyCarcinogenFindings.
     */
    public const LISTING_THRESHOLD_PCT = 0.1;

    /**
     * Check a composition against the carcinogen registry.
     *
     * @param  array $composition  Expanded CAS-level composition
     * @return array {
     *   findings: array of per-component carcinogen listings,
     *   has_carcinogens: bool,
     *   summary_text: string  (English base text; SDSGenerator::section11 rebuilds per language via buildSummaryText),
     *   component_texts: array  (per-CAS English line; same builder, see buildComponentTexts),
     * }
     */
    public static function analyse(array $composition): array
    {
        $db = Database::getInstance();

        $findings = [];

        foreach ($composition as $component) {
            $cas  = $component['cas_number'] ?? '';
            $name = $component['chemical_name'] ?? '';
            $conc = (float) ($component['concentration_pct'] ?? 0);

            if ($cas === '' || $conc < self::LISTING_THRESHOLD_PCT) {
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
        }

        $result = ['findings' => $findings];
        self::resummarise($result);

        return $result;
    }

    /**
     * Recompute has_carcinogens, summary_text and component_texts from
     * $result['findings']. Called by analyse() and by SDSGenerator after it
     * filters findings (solid/powder-in-liquid, inhalation-only suppression)
     * so every copy of the text comes from the same builder.
     *
     * Text is built with $t (default: English). The sheet text shown in
     * Section 11 is rebuilt per language by SDSGenerator::section11().
     */
    public static function resummarise(array &$result, ?TranslationService $t = null): void
    {
        $findings = array_values($result['findings'] ?? []);
        $result['findings']        = $findings;
        $result['has_carcinogens'] = !empty($findings);
        $result['component_texts'] = self::buildComponentTexts($findings, $t);
        $result['summary_text']    = self::buildSummaryText($findings, $t);
    }

    /**
     * One line per listed component, keyed by CAS, e.g.
     *   "Ethylbenzene (CAS 100-41-4, 1 - 5%) — IARC: Group 2B; NTP: Reasonably Anticipated"
     *
     * The concentration prints as the finding's 'concentration_range' (the
     * Section 3 prescribed-range band, attached by SDSGenerator::section11()
     * before the sheet text is built — SDS content policy: exact percentages
     * are never printed). The rounded exact value is used only for the
     * unprinted English base data (analyse()/resummarise()), which carry no
     * band.
     */
    public static function buildComponentTexts(array $findings, ?TranslationService $t = null): array
    {
        $t ??= new TranslationService('en');
        $texts = [];

        foreach ($findings as $f) {
            $cas = (string) ($f['cas_number'] ?? '');
            if ($cas === '') {
                continue;
            }

            $parts = [];
            foreach ($f['agencies'] ?? [] as $a) {
                $parts[] = $t->get('section11.carcinogenicity_listing', [
                    'agency'         => (string) ($a['agency'] ?? ''),
                    'classification' => (string) ($a['classification'] ?? ''),
                ]);
            }

            $texts[$cas] = $t->get('section11.carcinogenicity_listed_line', [
                'name'     => (string) ($f['chemical_name'] ?? ''),
                'cas'      => $cas,
                'range'    => (string) ($f['concentration_range']
                    ?? (round((float) ($f['concentration_pct'] ?? 0), 2) . '%')),
                'listings' => implode('; ', $parts),
            ]);
        }

        return $texts;
    }

    /**
     * Section 11 carcinogenicity paragraph (29 CFR 1910.1200 App. D,
     * Section 11(vi): whether a component is listed by the NTP Report on
     * Carcinogens, the IARC Monographs, or OSHA).
     *
     * Negative case returns the translation constant section11.carcinogenicity.
     * Positive case = translated intro line + one buildComponentTexts() line
     * per listed component.
     */
    public static function buildSummaryText(array $findings, ?TranslationService $t = null): string
    {
        $t ??= new TranslationService('en');

        if (empty($findings)) {
            return $t->get('section11.carcinogenicity', [
                'threshold' => (string) self::LISTING_THRESHOLD_PCT,
            ]);
        }

        $intro = $t->get('section11.carcinogenicity_listed_intro', [
            'threshold' => (string) self::LISTING_THRESHOLD_PCT,
        ]);

        return $intro . "\n" . implode("\n", self::buildComponentTexts($findings, $t));
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

            if ($cas === '' || $conc < self::LISTING_THRESHOLD_PCT) {
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
