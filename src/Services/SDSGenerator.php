<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\App;
use SDS\Core\Database;
use SDS\Models\FinishedGood;

/**
 * SDSGenerator — Assembles the complete 16-section SDS data structure.
 *
 * Combines product info, composition, hazard classification, VOC data,
 * company info, regulatory data, and text overrides into a single
 * structured array that can be rendered as HTML preview or fed to
 * PDFService for final document generation.
 *
 * Follows OSHA HazCom 2012 / GHS Rev.9 section ordering:
 *   1. Identification
 *   2. Hazard(s) Identification
 *   3. Composition/Information on Ingredients
 *   4. First-Aid Measures
 *   5. Fire-Fighting Measures
 *   6. Accidental Release Measures
 *   7. Handling and Storage
 *   8. Exposure Controls / PPE
 *   9. Physical and Chemical Properties
 *  10. Stability and Reactivity
 *  11. Toxicological Information
 *  12. Ecological Information *
 *  13. Disposal Considerations *
 *  14. Transport Information *
 *  15. Regulatory Information *
 *  16. Other Information
 *  (* Not enforced by OSHA but required by GHS)
 */
class SDSGenerator
{
    private TranslationService $t;

    public function __construct(?TranslationService $translator = null)
    {
        $this->t = $translator ?? new TranslationService('en');
    }

    /**
     * Generate the full SDS data structure for a finished good.
     *
     * @param  int    $finishedGoodId
     * @param  string $language
     * @return array  Complete SDS data with all 16 sections.
     */
    public function generate(int $finishedGoodId, string $language = 'en'): array
    {
        $this->t = new TranslationService($language);

        $fg = FinishedGood::findById($finishedGoodId);
        if ($fg === null) {
            throw new \RuntimeException('Finished good #' . $finishedGoodId . ' not found.');
        }

        // Run formula calculations
        $calcService = new FormulaCalcService();
        $calcResult  = $calcService->calculate($finishedGoodId);

        // Run hazard classification
        $hazardEngine = new HazardEngine();
        $hazardResult = $hazardEngine->classify($calcResult['composition']);

        // Run SARA 313 analysis
        $saraResult = SARA313Service::analyse($calcResult['composition']);

        // Run Prop 65 analysis
        $prop65Result = Prop65Service::analyse($calcResult['composition']);

        // Run carcinogen analysis (IARC/NTP/OSHA)
        $carcinogenResult = CarcinogenService::analyse($calcResult['composition']);

        // Load DOT transport info
        $dotInfo = $this->getDOTInfo($calcResult['composition']);

        // Load text overrides
        $overrides = $this->getOverrides($finishedGoodId, $language);

        // Company info from admin settings (DB), with config fallback
        $company = $this->getCompanySettings();

        // UV Acrylate Rule Pack — detect and append safe-handling language
        $uvWarnings = [];
        $uvSectionAppend = [];
        if (UVAcrylateRulePack::isApplicable($fg['family'] ?? null)) {
            $acrylates = UVAcrylateRulePack::detectAcrylates($calcResult['composition']);
            if (!empty($acrylates)) {
                $uvSectionAppend = UVAcrylateRulePack::getSafeHandlingLanguage($acrylates);
                $uvWarnings      = UVAcrylateRulePack::getFormulatorWarnings($acrylates);
            }
        }

        // Assemble all 16 sections
        $sds = [
            'meta' => [
                'finished_good_id' => $finishedGoodId,
                'product_code'     => $fg['product_code'],
                'description'      => $fg['description'],
                'family'           => $fg['family'],
                'language'         => $language,
                'generated_at'     => gmdate('Y-m-d\TH:i:s\Z'),
                'formula_version'  => $calcResult['formula']['version'] ?? null,
                'company_logo_path' => $company['logo_path'] ?? '',
                'labels'           => $this->getLabels(),
            ],
            'sections' => [
                1  => $this->section1($fg, $company, $overrides),
                2  => $this->section2($hazardResult, $overrides),
                3  => $this->section3($calcResult['composition'], $hazardResult, $overrides),
                4  => $this->section4($hazardResult, $overrides),
                5  => $this->section5($calcResult, $overrides),
                6  => $this->section6($overrides),
                7  => $this->section7($overrides),
                8  => $this->section8($hazardResult, $overrides),
                9  => $this->section9($calcResult, $overrides),
                10 => $this->section10($overrides),
                11 => $this->section11($hazardResult, $calcResult['composition'], $carcinogenResult, $overrides),
                12 => $this->section12($overrides),
                13 => $this->section13($overrides),
                14 => $this->section14($dotInfo, $overrides),
                15 => $this->section15($saraResult, $prop65Result, $overrides),
                16 => $this->section16($calcResult, $overrides),
            ],
            'hazard_result'       => $hazardResult,
            'voc_result'          => $calcResult['voc'],
            'sara_result'         => $saraResult,
            'prop65_result'       => $prop65Result,
            'carcinogen_result'   => $carcinogenResult,
            'warnings'            => array_merge($calcResult['warnings'], $uvWarnings),
            'legal_disclaimer'    => $company['legal_disclaimer'] ?? '',
        ];

        // Append UV acrylate safe-handling language to relevant sections
        foreach ($uvSectionAppend as $secNum => $appendText) {
            if (isset($sds['sections'][$secNum])) {
                $sds['sections'][$secNum]['uv_acrylate_note'] = $appendText;
            }
        }

        return $sds;
    }

    /* ------------------------------------------------------------------
     *  Section builders
     * ----------------------------------------------------------------*/

    private function section1(array $fg, array $company, array $overrides): array
    {
        return [
            'title' => $this->t->get('section1.title', []),
            'product_identifier'    => $fg['product_code'] . ' — ' . $fg['description'],
            'product_family'        => $fg['family'] ?? '',
            'recommended_use'       => $overrides[1]['recommended_use'] ?? $this->t->get('section1.recommended_use'),
            'restrictions'          => $overrides[1]['restrictions'] ?? $this->t->get('section1.restrictions'),
            'manufacturer_name'     => $company['name'] ?? '',
            'manufacturer_address'  => trim(($company['address'] ?? '') . ', ' . ($company['city'] ?? '') . ', ' . ($company['state'] ?? '') . ' ' . ($company['zip'] ?? ''), ', '),
            'manufacturer_phone'    => $company['phone'] ?? '',
            'emergency_phone'       => $company['emergency_phone'] ?? '',
            'manufacturer_email'    => $company['email'] ?? '',
            'manufacturer_website'  => $company['website'] ?? '',
        ];
    }

    private function section2(array $hazard, array $overrides): array
    {
        return [
            'title'               => $this->t->get('section2.title'),
            'signal_word'         => $hazard['signal_word'],
            'pictograms'          => $hazard['pictograms'],
            'hazard_classes'      => $hazard['hazard_classes'],
            'h_statements'        => $hazard['h_statements'],
            'p_statements'        => $hazard['p_statements'],
            'ppe_recommendations' => $hazard['ppe_recommendations'],
            'other_hazards'       => $overrides[2]['other_hazards'] ?? $this->t->get('section2.other_hazards'),
        ];
    }

    private function section3(array $composition, array $hazardResult, array $overrides): array
    {
        // Only disclose CAS numbers that are classified as hazardous
        $hazardousCas = array_flip($hazardResult['hazardous_cas'] ?? []);

        $disclosed = [];
        foreach ($composition as $c) {
            $cas  = $c['cas_number'] ?? '';
            $conc = (float) ($c['concentration_pct'] ?? 0);

            // Must be hazardous, above disclosure threshold, and not trade secret
            if ($cas !== ''
                && isset($hazardousCas[$cas])
                && $conc >= 0.1
                && !($c['is_trade_secret'] ?? false)
            ) {
                $disclosed[] = [
                    'cas_number'          => $cas,
                    'chemical_name'       => $c['chemical_name'],
                    'concentration_pct'   => $conc,
                    'concentration_range' => $this->concentrationRange($conc),
                ];
            }
        }

        return [
            'title'                => $this->t->get('section3.title'),
            'substance_or_mixture' => 'Mixture',
            'components'           => $disclosed,
            'trade_secret_note'    => $this->hasTradeSecrets($composition)
                ? $this->t->get('section3.trade_secret_note')
                : null,
        ];
    }

    private function section4(array $hazard, array $overrides): array
    {
        return [
            'title'       => $this->t->get('section4.title'),
            'inhalation'  => $overrides[4]['inhalation'] ?? $this->t->get('section4.inhalation'),
            'skin'        => $overrides[4]['skin'] ?? $this->t->get('section4.skin'),
            'eyes'        => $overrides[4]['eyes'] ?? $this->t->get('section4.eyes'),
            'ingestion'   => $overrides[4]['ingestion'] ?? $this->t->get('section4.ingestion'),
            'notes'       => $overrides[4]['notes'] ?? $this->t->get('section4.notes'),
        ];
    }

    private function section5(array $calcResult, array $overrides): array
    {
        $flashPoint = null;
        foreach ($calcResult['formula']['lines'] ?? [] as $line) {
            $fp = $line['flash_point_c'] ?? null;
            if ($fp !== null && ($flashPoint === null || (float) $fp < $flashPoint)) {
                $flashPoint = (float) $fp;
            }
        }

        return [
            'title'                => $this->t->get('section5.title'),
            'suitable_media'       => $overrides[5]['suitable_media'] ?? $this->t->get('section5.suitable_media'),
            'unsuitable_media'     => $overrides[5]['unsuitable_media'] ?? $this->t->get('section5.unsuitable_media'),
            'specific_hazards'     => $overrides[5]['specific_hazards'] ?? $this->t->get('section5.specific_hazards'),
            'firefighter_advice'   => $overrides[5]['firefighter_advice'] ?? $this->t->get('section5.firefighter_advice'),
            'flash_point_c'        => $flashPoint,
        ];
    }

    private function section6(array $overrides): array
    {
        return [
            'title'                => $this->t->get('section6.title'),
            'personal_precautions' => $overrides[6]['personal_precautions'] ?? $this->t->get('section6.personal_precautions'),
            'environmental'        => $overrides[6]['environmental'] ?? $this->t->get('section6.environmental'),
            'containment'          => $overrides[6]['containment'] ?? $this->t->get('section6.containment'),
        ];
    }

    private function section7(array $overrides): array
    {
        return [
            'title'      => $this->t->get('section7.title'),
            'handling'   => $overrides[7]['handling'] ?? $this->t->get('section7.handling'),
            'storage'    => $overrides[7]['storage'] ?? $this->t->get('section7.storage'),
        ];
    }

    private function section8(array $hazard, array $overrides): array
    {
        // PPE: use overrides first, then auto-derived from hazard codes, then translation defaults
        $ppe = $hazard['ppe_recommendations'] ?? [];

        return [
            'title'            => $this->t->get('section8.title'),
            'exposure_limits'  => $hazard['exposure_limits'],
            'engineering'      => $overrides[8]['engineering'] ?? $this->t->get('section8.engineering'),
            'respiratory'      => $overrides[8]['respiratory'] ?? $ppe['respiratory'] ?? $this->t->get('section8.respiratory'),
            'hand_protection'  => $overrides[8]['hand_protection'] ?? $ppe['hand_protection'] ?? $this->t->get('section8.hand_protection'),
            'eye_protection'   => $overrides[8]['eye_protection'] ?? $ppe['eye_protection'] ?? $this->t->get('section8.eye_protection'),
            'skin_protection'  => $overrides[8]['skin_protection'] ?? $ppe['skin_protection'] ?? $this->t->get('section8.skin_protection'),
        ];
    }

    private function section9(array $calcResult, array $overrides): array
    {
        $voc = $calcResult['voc'];
        return [
            'title'           => $this->t->get('section9.title'),
            'appearance'      => $overrides[9]['appearance'] ?? '',
            'odor'            => $overrides[9]['odor'] ?? '',
            'ph'              => $overrides[9]['ph'] ?? 'Not applicable',
            'boiling_point'   => $overrides[9]['boiling_point'] ?? 'Not determined',
            'flash_point'     => $overrides[9]['flash_point'] ?? 'Not determined',
            'specific_gravity' => round((float) ($voc['mixture_sg'] ?? 0), 3) ?: 'Not determined',
            'voc_lb_per_gal'  => round((float) ($voc['voc_lb_per_gal'] ?? 0), 2),
            'voc_less_water_exempt' => round((float) ($voc['voc_lb_per_gal_less_water_exempt'] ?? 0), 2),
            'solids_wt_pct'   => round((float) ($voc['solids_wt_pct'] ?? 0), 1),
            'solids_vol_pct'  => $voc['solids_vol_pct'] !== null ? round((float) $voc['solids_vol_pct'], 1) : 'Not determined',
            'voc_wt_pct'      => round((float) ($voc['total_voc_wt_pct'] ?? 0), 2),
        ];
    }

    private function section10(array $overrides): array
    {
        return [
            'title'             => $this->t->get('section10.title'),
            'reactivity'        => $overrides[10]['reactivity'] ?? $this->t->get('section10.reactivity'),
            'stability'         => $overrides[10]['stability'] ?? $this->t->get('section10.stability'),
            'conditions_avoid'  => $overrides[10]['conditions_avoid'] ?? $this->t->get('section10.conditions_avoid'),
            'incompatible'      => $overrides[10]['incompatible'] ?? $this->t->get('section10.incompatible'),
            'decomposition'     => $overrides[10]['decomposition'] ?? $this->t->get('section10.decomposition'),
        ];
    }

    private function section11(array $hazard, array $composition, array $carcinogenResult, array $overrides): array
    {
        // Build component-level toxicological detail
        $componentTox = [];
        foreach ($composition as $c) {
            $cas  = $c['cas_number'] ?? '';
            $name = $c['chemical_name'] ?? '';
            $conc = (float) ($c['concentration_pct'] ?? 0);
            if ($cas === '' || $conc < 0.1) {
                continue;
            }

            $entry = [
                'cas_number'    => $cas,
                'chemical_name' => $name,
                'concentration_pct' => $conc,
                'exposure_limits' => [],
                'carcinogen_listings' => [],
            ];

            // Attach relevant exposure limits
            foreach ($hazard['exposure_limits'] as $el) {
                if ($el['cas_number'] === $cas) {
                    $entry['exposure_limits'][] = $el;
                }
            }

            // Attach carcinogen findings
            foreach ($carcinogenResult['findings'] as $f) {
                if ($f['cas_number'] === $cas) {
                    $entry['carcinogen_listings'] = $f['agencies'];
                }
            }

            if (!empty($entry['exposure_limits']) || !empty($entry['carcinogen_listings'])) {
                $componentTox[] = $entry;
            }
        }

        // Override carcinogenicity text if we have actual data
        $carcinogenText = $overrides[11]['carcinogenicity'] ?? null;
        if ($carcinogenText === null) {
            $carcinogenText = $carcinogenResult['has_carcinogens']
                ? $carcinogenResult['summary_text']
                : $this->t->get('section11.carcinogenicity');
        }

        return [
            'title'              => $this->t->get('section11.title'),
            'acute_toxicity'     => $overrides[11]['acute_toxicity'] ?? $this->t->get('section11.acute_toxicity'),
            'chronic_effects'    => $overrides[11]['chronic_effects'] ?? $this->t->get('section11.chronic_effects'),
            'carcinogenicity'    => $carcinogenText,
            'hazard_classes'     => $hazard['hazard_classes'],
            'component_toxicology' => $componentTox,
            'carcinogen_result'  => $carcinogenResult,
        ];
    }

    private function section12(array $overrides): array
    {
        return [
            'title'     => $this->t->get('section12.title'),
            'ecotoxicity'   => $overrides[12]['ecotoxicity'] ?? $this->t->get('section12.ecotoxicity'),
            'persistence'   => $overrides[12]['persistence'] ?? $this->t->get('section12.persistence'),
            'bioaccumulation' => $overrides[12]['bioaccumulation'] ?? $this->t->get('section12.bioaccumulation'),
            'note'          => $this->t->get('section12.note'),
        ];
    }

    private function section13(array $overrides): array
    {
        return [
            'title'   => $this->t->get('section13.title'),
            'methods' => $overrides[13]['methods'] ?? $this->t->get('section13.methods'),
            'note'    => $this->t->get('section13.note'),
        ];
    }

    private function section14(array $dotInfo, array $overrides): array
    {
        return [
            'title'               => $this->t->get('section14.title'),
            'un_number'           => $dotInfo['un_number'] ?? $overrides[14]['un_number'] ?? 'Not regulated',
            'proper_shipping_name' => $dotInfo['proper_shipping_name'] ?? $overrides[14]['proper_shipping_name'] ?? 'Not regulated',
            'hazard_class'        => $dotInfo['hazard_class'] ?? $overrides[14]['hazard_class'] ?? 'Not regulated',
            'packing_group'       => $dotInfo['packing_group'] ?? $overrides[14]['packing_group'] ?? 'Not applicable',
            'note'                => $this->t->get('section14.note'),
        ];
    }

    private function section15(array $saraResult, array $prop65Result, array $overrides): array
    {
        // Build state regulations text with Prop 65 data
        $stateRegs = $overrides[15]['state_regs'] ?? '';
        if ($stateRegs === '' && $prop65Result['requires_warning']) {
            $stateRegs = $prop65Result['warning_text'];
        }

        return [
            'title'          => $this->t->get('section15.title'),
            'osha_status'    => $overrides[15]['osha_status'] ?? $this->t->get('section15.osha_status'),
            'tsca_status'    => $overrides[15]['tsca_status'] ?? $this->t->get('section15.tsca_status'),
            'sara_313'       => $saraResult,
            'prop65'         => $prop65Result,
            'state_regs'     => $stateRegs,
            'note'           => $this->t->get('section15.note'),
        ];
    }

    private function section16(array $calcResult, array $overrides): array
    {
        return [
            'title'          => $this->t->get('section16.title'),
            'revision_date'  => date('m/d/Y'),
            'revision_note'  => $overrides[16]['revision_note'] ?? '',
            'disclaimer'     => $this->t->get('section16.disclaimer'),
            'abbreviations'  => $this->t->get('section16.abbreviations'),
            'voc_assumptions' => $calcResult['voc']['assumptions'] ?? [],
        ];
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    /**
     * Load manufacturer/company info from the settings table,
     * falling back to static config values.
     */
    private function getCompanySettings(): array
    {
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT `key`, `value` FROM settings WHERE `key` LIKE 'company.%' OR `key` = 'sds.legal_disclaimer'"
        );

        $settings = [];
        foreach ($rows as $row) {
            // Strip the 'company.' prefix for company keys
            $shortKey = str_starts_with($row['key'], 'company.')
                ? substr($row['key'], 8)
                : ($row['key'] === 'sds.legal_disclaimer' ? 'legal_disclaimer' : $row['key']);
            $settings[$shortKey] = $row['value'];
        }

        // Fall back to static config for any missing values
        $configCompany = App::config('company', []);
        foreach ($configCompany as $k => $v) {
            if (!isset($settings[$k]) || $settings[$k] === '') {
                $settings[$k] = $v;
            }
        }

        return $settings;
    }

    private function getOverrides(int $fgId, string $language): array
    {
        $db  = Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT section_number, field_key, override_text
             FROM text_overrides
             WHERE finished_good_id = ? AND language = ? AND sds_version_id IS NULL
             ORDER BY section_number, field_key",
            [$fgId, $language]
        );

        $overrides = [];
        foreach ($rows as $row) {
            $overrides[(int) $row['section_number']][$row['field_key']] = $row['override_text'];
        }
        return $overrides;
    }

    private function getDOTInfo(array $composition): array
    {
        $db = Database::getInstance();

        // Check each component for DOT data, return the most hazardous
        foreach ($composition as $c) {
            $dot = $db->fetch(
                "SELECT * FROM dot_transport_info WHERE cas_number = ? ORDER BY retrieved_at DESC LIMIT 1",
                [$c['cas_number']]
            );
            if ($dot && !empty($dot['un_number'])) {
                return $dot;
            }
        }

        return [];
    }

    private function concentrationRange(float $pct): string
    {
        if ($pct >= 10) {
            $low  = floor($pct / 5) * 5;
            $high = $low + 5;
            return "{$low} - {$high}%";
        }
        if ($pct >= 1) {
            $low  = floor($pct);
            $high = $low + 1;
            return "{$low} - {$high}%";
        }
        return '< 1%';
    }

    /**
     * Get translated labels for PDF and preview rendering.
     */
    private function getLabels(): array
    {
        $keys = [
            'product_identifier', 'product_family', 'recommended_use', 'restrictions',
            'manufacturer_info', 'company', 'address', 'phone', 'emergency',
            'pictograms', 'ghs_classification', 'hazard_statements',
            'precautionary_statements', 'ppe_recommendations', 'other_hazards',
            'type', 'cas_number', 'chemical_name', 'concentration',
            'hazardous_only_note', 'no_hazardous_note',
            'engineering_controls', 'respiratory_protection', 'hand_protection',
            'eye_protection', 'skin_protection', 'respiratory', 'skin_body',
            'disclaimer',
        ];

        $labels = [];
        foreach ($keys as $key) {
            $labels[$key] = $this->t->get('labels.' . $key);
        }
        return $labels;
    }

    private function hasTradeSecrets(array $composition): bool
    {
        foreach ($composition as $c) {
            if (!empty($c['is_trade_secret'])) {
                return true;
            }
        }
        return false;
    }
}
