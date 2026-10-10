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
     * #37: carcinogen_list.classification is an English registry value
     * (agency + class). It is stored as is and translated at render time
     * via section11.carcinogen_class_<suffix> (and, on non-EN sheets, the
     * gloss section11.carcinogen_desc_<suffix>). Lookup keys are the
     * upper-cased, whitespace-collapsed stored values. An unmapped value
     * (e.g. ACGIH A3) prints as stored. Agency acronyms stay English.
     */
    private const CLASSIFICATION_KEYS = [
        'IARC' => [
            'GROUP 1'  => 'iarc_group_1',  '1'  => 'iarc_group_1',
            'GROUP 2A' => 'iarc_group_2a', '2A' => 'iarc_group_2a',
            'GROUP 2B' => 'iarc_group_2b', '2B' => 'iarc_group_2b',
            'GROUP 3'  => 'iarc_group_3',  '3'  => 'iarc_group_3',
        ],
        'NTP' => [
            'KNOWN'                  => 'ntp_known',
            'RAHC'                   => 'ntp_rahc',
            'REASONABLY ANTICIPATED' => 'ntp_rahc',
        ],
        'OSHA' => [
            'LISTED' => 'osha_listed',
        ],
    ];

    /**
     * #21: registry values that are carcinogen listings for HazCom App. D
     * Section 11(vi) — IARC Groups 1 / 2A / 2B, NTP Known / Reasonably
     * anticipated, OSHA-regulated. IARC Group 3 ("not classifiable") and any
     * value outside these enums (e.g. ACGIH A3) are not reported.
     */
    private const REPORTABLE_KEYS = ['iarc_group_1', 'iarc_group_2a', 'iarc_group_2b', 'ntp_known', 'ntp_rahc', 'osha_listed'];

    public static function isReportableListing(string $agency, string $classification): bool
    {
        return in_array(self::classificationKey($agency, $classification), self::REPORTABLE_KEYS, true);
    }

    /**
     * Translation-key suffix for a registry (agency, classification) pair,
     * or null when the pair is not a known enum value.
     */
    private static function classificationKey(string $agency, string $classification): ?string
    {
        $a = strtoupper(trim($agency));
        $c = strtoupper((string) preg_replace('/\s+/', ' ', trim($classification)));
        return self::CLASSIFICATION_KEYS[$a][$c] ?? null;
    }

    /**
     * Sheet-language text for a registry classification (#37), e.g.
     * IARC "Group 2B" -> ES "Grupo 2B"; NTP "RAHC" -> "Reasonably anticipated
     * to be a human carcinogen". Unknown values are returned as stored.
     */
    public static function classificationText(string $agency, string $classification, ?TranslationService $t = null): string
    {
        $suffix = self::classificationKey($agency, $classification);
        if ($suffix === null) {
            return $classification;
        }
        $t ??= new TranslationService('en');
        return $t->get('section11.carcinogen_class_' . $suffix);
    }

    /**
     * Section 11 component-block copy of a finding's agency rows (#37):
     * classification translated into the sheet language. The registry's
     * free-text description is English data, so it is kept on EN sheets
     * only; ES/FR/DE sheets print the per-class gloss
     * (section11.carcinogen_desc_<suffix>) or nothing for an unmapped class.
     *
     * @param  array<int,array{agency?:string,classification?:string,description?:string}> $agencies
     * @return array<int,array>
     */
    public static function localiseListings(array $agencies, TranslationService $t): array
    {
        $isEn = $t->getLanguage() === 'en';
        $out  = [];
        foreach ($agencies as $a) {
            $agency = (string) ($a['agency'] ?? '');
            $class  = (string) ($a['classification'] ?? '');
            $suffix = self::classificationKey($agency, $class);
            $a['classification'] = $suffix !== null ? $t->get('section11.carcinogen_class_' . $suffix) : $class;
            if (!$isEn) {
                $gloss = $suffix !== null ? $t->get('section11.carcinogen_desc_' . $suffix) : '';
                // A gloss that only repeats the classification (NTP RAHC) is dropped.
                if (mb_strtolower(rtrim($gloss, '. ')) === mb_strtolower(rtrim($a['classification'], '. '))) {
                    $gloss = '';
                }
                $a['description'] = $gloss;
            }
            $out[] = $a;
        }
        return $out;
    }

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
                // #21: IARC Group 3 never makes a component a listed carcinogen.
                if (!self::isReportableListing((string) ($row['agency'] ?? ''), (string) ($row['classification'] ?? ''))) {
                    continue;
                }
                $agencies[] = [
                    'agency'         => $row['agency'],
                    'classification' => $row['classification'],
                    'description'    => $row['description'] ?? '',
                ];
            }
            if ($agencies === []) {
                continue;
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
                    'classification' => self::classificationText(
                        (string) ($a['agency'] ?? ''),
                        (string) ($a['classification'] ?? ''),
                        $t
                    ),
                ]);
            }

            // Audit #13: masked trade-secret findings all carry the TRADE SECRET
            // label as CAS; keep one line each instead of overwriting.
            $key = isset($texts[$cas]) ? $cas . '#' . count($texts) : $cas;
            $line = $t->get('section11.carcinogenicity_listed_line', [
                'name'     => (string) ($f['chemical_name'] ?? ''),
                'cas'      => $cas,
                'range'    => (string) ($f['concentration_range']
                    ?? (round((float) ($f['concentration_pct'] ?? 0), 2) . '%')),
                'listings' => implode('; ', $parts),
            ]);
            // Audit #41(2): inhalation-only substance bound in this product.
            if (!empty($f['inhalable_dust_only'])) {
                $line .= ' ' . $t->get('section11.carcinogenicity_inhalable_dust_note');
            }
            $texts[$key] = $line;
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
