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

        // Carbon Black CAS# 1333-86-4 special logic:
        // Apply Carcinogen Category 2 (H351) only if Carbon Black is the only
        // ingredient OR all other ingredients are powders. If mixed with any
        // non-powder material, do not apply the carcinogen classification.
        $this->applyCarbonBlackLogic($hazardResult, $calcResult);

        // Translate GHS data (H/P statements, signal word, pictograms) for target language
        $hazardResult = GHSStatements::translateHazardResult($hazardResult, $language);

        // Run SARA 313 analysis
        $saraResult = SARA313Service::analyse($calcResult['composition']);

        // Gather manual regulatory data from raw materials in this formula
        $formulaLines = $calcResult['formula']['lines'] ?? [];
        $manualProp65 = $this->getManualProp65($formulaLines);
        $manualHaps   = $this->getManualHaps($formulaLines);

        // Run Prop 65 analysis (CAS-level + manual raw material flags)
        $prop65Result = Prop65Service::analyse($calcResult['composition'], $manualProp65);

        // Run carcinogen analysis (IARC/NTP/OSHA)
        $carcinogenResult = CarcinogenService::analyse($calcResult['composition']);

        // Run HAP analysis (Clean Air Act Section 112(b) + manual entries)
        $hapResult = HAPService::analyse($calcResult['composition'], $manualHaps);

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
                'document'         => $this->getDocumentStrings(),
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
                15 => $this->section15($saraResult, $prop65Result, $hapResult, $overrides),
                16 => $this->section16($calcResult, $overrides),
            ],
            'hazard_result'       => $hazardResult,
            'voc_result'          => $calcResult['voc'],
            'sara_result'         => $saraResult,
            'prop65_result'       => $prop65Result,
            'carcinogen_result'   => $carcinogenResult,
            'hap_result'          => $hapResult,
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
            'recommended_use'       => $overrides[1]['recommended_use'] ?? ($fg['recommended_use'] ?? '') ?: $this->t->get('section1.recommended_use'),
            'restrictions'          => $overrides[1]['restrictions'] ?? ($fg['restrictions_on_use'] ?? '') ?: $this->t->get('section1.restrictions'),
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
        // PPE: use derived PPE from hazard codes, falling back to Section 8 overrides
        $derivedPpe = $hazard['ppe_recommendations'] ?? [];
        $ppe = [
            'respiratory'     => $derivedPpe['respiratory'] ?? ($overrides[8]['respiratory'] ?? null),
            'hand_protection' => $derivedPpe['hand_protection'] ?? ($overrides[8]['hand_protection'] ?? null),
            'eye_protection'  => $derivedPpe['eye_protection'] ?? ($overrides[8]['eye_protection'] ?? null),
            'skin_protection' => $derivedPpe['skin_protection'] ?? ($overrides[8]['skin_protection'] ?? null),
        ];

        $customOtherHazards = $overrides[2]['other_hazards'] ?? null;

        return [
            'title'               => $this->t->get('section2.title'),
            'signal_word'         => $hazard['signal_word'],
            'signal_word_en'      => $hazard['signal_word_en'] ?? $hazard['signal_word'],
            'pictograms'          => $hazard['pictograms'],
            'hazard_classes'      => $hazard['hazard_classes'],
            'h_statements'        => $hazard['h_statements'],
            'p_statements'        => $hazard['p_statements'],
            'ppe_recommendations' => $ppe,
            'other_hazards'       => $customOtherHazards ?? $this->t->get('section2.other_hazards'),
            'has_other_hazards'   => $customOtherHazards !== null,
        ];
    }

    private function section3(array $composition, array $hazardResult, array $overrides): array
    {
        // Only disclose CAS numbers that are classified as hazardous
        $hazardousCas = array_flip($hazardResult['hazardous_cas'] ?? []);

        $disclosed      = [];
        $tradeSecretBuckets = []; // group trade secrets by description

        foreach ($composition as $c) {
            $cas  = $c['cas_number'] ?? '';
            $conc = (float) ($c['concentration_pct'] ?? 0);

            // Skip non-hazardous constituents
            if (!empty($c['is_non_hazardous'])) {
                continue;
            }

            // Must be hazardous and above disclosure threshold
            if ($cas === '' || !isset($hazardousCas[$cas]) || $conc < 0.1) {
                continue;
            }

            // Trade secret items: group by description and merge
            if (!empty($c['is_trade_secret'])) {
                $desc = $c['trade_secret_description'] ?? '';
                if (!isset($tradeSecretBuckets[$desc])) {
                    $tradeSecretBuckets[$desc] = [
                        'cas_number'        => 'TRADE SECRET',
                        'chemical_name'     => $desc ?: 'Trade Secret',
                        'concentration_pct' => 0.0,
                    ];
                }
                $tradeSecretBuckets[$desc]['concentration_pct'] += $conc;
                continue;
            }

            $disclosed[] = [
                'cas_number'          => $cas,
                'chemical_name'       => $c['chemical_name'],
                'concentration_pct'   => $conc,
                'concentration_range' => $this->concentrationRange($conc),
            ];
        }

        // Add merged trade secret lines
        foreach ($tradeSecretBuckets as $bucket) {
            $disclosed[] = [
                'cas_number'          => 'TRADE SECRET',
                'chemical_name'       => $bucket['chemical_name'],
                'concentration_pct'   => round($bucket['concentration_pct'], 4),
                'concentration_range' => $this->concentrationRange($bucket['concentration_pct']),
            ];
        }

        // Sort by concentration descending
        usort($disclosed, fn($a, $b) => $b['concentration_pct'] <=> $a['concentration_pct']);

        return [
            'title'                => $this->t->get('section3.title'),
            'substance_or_mixture' => $this->t->get('labels.mixture'),
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
        $voc   = $calcResult['voc'];
        $props = $calcResult['formula_props'] ?? [];

        $notDetermined = $this->t->get('labels.not_determined');

        // Flash point: auto-derive from formula, allow override
        $flashPoint = $overrides[9]['flash_point'] ?? null;
        if ($flashPoint === null || $flashPoint === '') {
            $fpC = $props['flash_point_c'] ?? null;
            if ($fpC !== null) {
                $fpF     = round($fpC * 9 / 5 + 32, 1);
                $prefix  = !empty($props['flash_point_greater_than']) ? '> ' : '';
                $flashPoint = "{$prefix}{$fpC} °C ({$fpF} °F)";
            } else {
                $flashPoint = $notDetermined;
            }
        }

        // VOC wt%: if all materials are <1%, display "<1%"
        $vocWtPctDisplay = round((float) ($voc['total_voc_wt_pct'] ?? 0), 2);
        if (!empty($props['all_voc_less_than_one'])) {
            $vocWtPctDisplay = '<1';
        }

        // Solubility: auto-derive from formula
        $solubility = $overrides[9]['solubility'] ?? ($props['solubility'] ?? '');

        return [
            'title'                => $this->t->get('section9.title'),
            'appearance'           => $overrides[9]['appearance'] ?? '',
            'odor'                 => $overrides[9]['odor'] ?? '',
            'boiling_point'        => $overrides[9]['boiling_point'] ?? $notDetermined,
            'flash_point'          => $flashPoint,
            'solubility'           => $solubility,
            'specific_gravity'     => round((float) ($voc['mixture_sg'] ?? 0), 3) ?: $notDetermined,
            'voc_lb_per_gal'       => round((float) ($voc['voc_lb_per_gal'] ?? 0), 2),
            'voc_less_water_exempt' => round((float) ($voc['voc_lb_per_gal_less_water_exempt'] ?? 0), 2),
            'solids_wt_pct'        => round((float) ($voc['solids_wt_pct'] ?? 0), 1),
            'solids_vol_pct'       => $voc['solids_vol_pct'] !== null ? round((float) $voc['solids_vol_pct'], 1) : $notDetermined,
            'voc_wt_pct'           => $vocWtPctDisplay,
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
        $notRegulated  = $this->t->get('labels.not_regulated');
        $notApplicable = $this->t->get('labels.not_applicable');

        return [
            'title'               => $this->t->get('section14.title'),
            'un_number'           => $dotInfo['un_number'] ?? $overrides[14]['un_number'] ?? $notRegulated,
            'proper_shipping_name' => $dotInfo['proper_shipping_name'] ?? $overrides[14]['proper_shipping_name'] ?? $notRegulated,
            'hazard_class'        => $dotInfo['hazard_class'] ?? $overrides[14]['hazard_class'] ?? $notRegulated,
            'packing_group'       => $dotInfo['packing_group'] ?? $overrides[14]['packing_group'] ?? $notApplicable,
            'note'                => $this->t->get('section14.note'),
        ];
    }

    private function section15(array $saraResult, array $prop65Result, array $hapResult, array $overrides): array
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
            'hap'            => $hapResult,
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
            // Section 1
            'product_identifier', 'product_family', 'recommended_use', 'restrictions',
            'manufacturer_info', 'company', 'address', 'phone', 'emergency',
            // Section 2
            'pictograms', 'ghs_classification', 'hazard_statements',
            'precautionary_statements', 'ppe_recommendations', 'other_hazards',
            'ppe_wear_eye', 'ppe_wear_gloves', 'ppe_wear_respiratory', 'ppe_wear_skin',
            // Section 3
            'type', 'cas_number', 'chemical_name', 'concentration',
            'hazardous_only_note', 'no_hazardous_note', 'mixture',
            // Section 4
            'inhalation', 'skin_contact', 'eye_contact', 'ingestion', 'notes_to_physician',
            // Section 5
            'suitable_media', 'unsuitable_media', 'specific_hazards', 'firefighter_advice',
            // Section 6
            'personal_precautions', 'environmental_precautions', 'containment_cleanup',
            // Section 7
            'handling', 'storage',
            // Section 8
            'engineering_controls', 'respiratory_protection', 'hand_protection',
            'eye_protection', 'skin_protection', 'respiratory', 'skin_body',
            'el_cas', 'el_chemical', 'el_type', 'el_value', 'el_units', 'el_conc_pct', 'el_notes',
            // Section 9
            'appearance', 'odor', 'boiling_point', 'flash_point', 'solubility',
            'specific_gravity', 'voc_lb_gal', 'voc_less_we', 'voc_wt_pct',
            'solids_wt_pct', 'solids_vol_pct',
            // Section 10
            'reactivity', 'chemical_stability', 'conditions_avoid',
            'incompatible_materials', 'decomposition_products',
            // Section 11
            'acute_toxicity', 'chronic_effects', 'carcinogenicity',
            'component_tox_data', 'health_hazard',
            // Section 12
            'ecotoxicity', 'persistence', 'bioaccumulation',
            // Section 13
            'disposal_methods',
            // Section 14
            'un_number', 'proper_shipping_name', 'transport_hazard_class', 'packing_group',
            // Section 15
            'osha_status', 'tsca_status', 'sara_313_title',
            'hap_title', 'hap_triggering', 'hap_wt_pct', 'hap_total', 'hap_none',
            'prop65_title', 'prop65_none', 'state_regulations',
            // Section 16
            'revision_date', 'abbreviations', 'disclaimer',
            // Generic
            'not_determined', 'not_regulated', 'not_applicable', 'note',
            // PPE pictogram labels
            'ppe_wear_eye', 'ppe_wear_gloves', 'ppe_wear_respiratory', 'ppe_wear_skin',
        ];

        $labels = [];
        foreach ($keys as $key) {
            $labels[$key] = $this->t->get('labels.' . $key);
        }
        return $labels;
    }

    /**
     * Get translated document-level strings (header, footer, section banner).
     */
    private function getDocumentStrings(): array
    {
        return [
            'title'           => $this->t->get('document.title'),
            'section_prefix'  => $this->t->get('document.section_prefix'),
            'page'            => $this->t->get('document.page'),
            'page_of'         => $this->t->get('document.page_of'),
            'revision_prefix' => $this->t->get('document.revision_prefix'),
        ];
    }

    /**
     * Apply Carbon Black CAS# 1333-86-4 carcinogen logic.
     *
     * Carbon Black is classified as Carcinogen Category 2 (H351) only when:
     *  - It is the sole ingredient, OR
     *  - All other ingredients have a physical_state of 'Powder'
     *
     * If mixed with any non-powder material, the Carcinogen Category 2
     * classification is removed (but Carbon Black is still listed in Section 3).
     */
    private function applyCarbonBlackLogic(array &$hazardResult, array $calcResult): void
    {
        $carbonBlackCas = '1333-86-4';

        // Check if Carbon Black is in the composition
        $hasCarbonBlack = false;
        foreach ($calcResult['composition'] as $c) {
            if (($c['cas_number'] ?? '') === $carbonBlackCas) {
                $hasCarbonBlack = true;
                break;
            }
        }

        if (!$hasCarbonBlack) {
            return;
        }

        // Check enriched lines: is Carbon Black the only material,
        // or are all OTHER materials Powder?
        $enrichedLines = $calcResult['formula_props']['enriched_lines'] ?? [];
        $hasNonPowder = false;
        $lineCount = count($enrichedLines);

        foreach ($enrichedLines as $line) {
            // Check if this line contains Carbon Black by looking at its constituents
            $isCarbonBlackLine = false;
            foreach ($line['constituents'] ?? [] as $constituent) {
                if (($constituent['cas_number'] ?? '') === $carbonBlackCas) {
                    $isCarbonBlackLine = true;
                    break;
                }
            }

            // Skip the Carbon Black line itself — we check OTHER materials
            if ($isCarbonBlackLine) {
                continue;
            }

            // If any other material is NOT powder, flag it
            $state = $line['physical_state'] ?? null;
            if ($state === null || strtolower($state) !== 'powder') {
                $hasNonPowder = true;
                break;
            }
        }

        // Determine if carcinogen classification should apply
        $onlyPowders = !$hasNonPowder; // true if CB is only ingredient or all others are powder

        if ($onlyPowders) {
            // Add Carcinogen Category 2 + H351 if not already present
            $hasH351 = false;
            foreach ($hazardResult['h_statements'] as $stmt) {
                if (($stmt['code'] ?? '') === 'H351') {
                    $hasH351 = true;
                    break;
                }
            }

            if (!$hasH351) {
                $hazardResult['h_statements'][] = [
                    'code' => 'H351',
                    'text' => GHSStatements::hText('H351'),
                ];

                $hazardResult['hazard_classes'][] = [
                    'class'             => 'Carcinogenicity',
                    'category'          => 'Category 2',
                    'cas'               => $carbonBlackCas,
                    'chemical'          => 'Carbon Black',
                    'concentration_pct' => 0,
                    'cutoff_pct'        => 0,
                    'source'            => 'Carbon Black powder logic',
                ];

                // Ensure GHS08 (Health Hazard) pictogram is present
                if (!in_array('GHS08', $hazardResult['pictograms'])) {
                    $hazardResult['pictograms'][] = 'GHS08';
                }

                // Ensure 'Warning' signal word at minimum
                if ($hazardResult['signal_word'] === null) {
                    $hazardResult['signal_word'] = 'Warning';
                }

                // Add P-statements for Carcinogenicity Cat 2
                $carcinPCodes = ['P201', 'P202', 'P281', 'P308+P313', 'P405', 'P501'];
                $existingPCodes = array_map(fn($s) => $s['code'] ?? '', $hazardResult['p_statements']);
                foreach ($carcinPCodes as $pCode) {
                    if (!in_array($pCode, $existingPCodes)) {
                        $hazardResult['p_statements'][] = [
                            'code' => $pCode,
                            'text' => GHSStatements::pText($pCode),
                        ];
                    }
                }
            }
        } else {
            // Remove Carcinogen Cat 2 for Carbon Black if it was added by HazardEngine
            $hazardResult['h_statements'] = array_values(array_filter(
                $hazardResult['h_statements'],
                function ($stmt) use ($hazardResult, $carbonBlackCas) {
                    if (($stmt['code'] ?? '') !== 'H351') {
                        return true;
                    }
                    // Only remove H351 if Carbon Black is the sole source
                    foreach ($hazardResult['hazard_classes'] as $hc) {
                        if (($hc['cas'] ?? '') !== $carbonBlackCas
                            && stripos($hc['class'] ?? '', 'Carcinogen') !== false
                            && stripos($hc['category'] ?? '', '2') !== false) {
                            return true; // Another CAS also has Cat 2 carcinogen — keep H351
                        }
                    }
                    return false;
                }
            ));

            // Remove Carbon Black carcinogenicity from hazard_classes
            $hazardResult['hazard_classes'] = array_values(array_filter(
                $hazardResult['hazard_classes'],
                fn($hc) => !(
                    ($hc['cas'] ?? '') === $carbonBlackCas
                    && stripos($hc['class'] ?? '', 'Carcinogen') !== false
                )
            ));
        }
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

    /**
     * Gather manual Prop 65 flags from raw materials used in the formula.
     *
     * Returns an array of entries with chemical name, toxicity types, and
     * the effective concentration in the finished good.
     */
    private function getManualProp65(array $formulaLines): array
    {
        $db = Database::getInstance();
        $rmIds = array_column($formulaLines, 'raw_material_id');
        if (empty($rmIds)) {
            return [];
        }

        // Build raw_material_id => formula pct lookup
        $pctByRm = [];
        foreach ($formulaLines as $line) {
            $pctByRm[(int) $line['raw_material_id']] = (float) ($line['pct'] ?? 0);
        }

        $placeholders = implode(',', array_fill(0, count($rmIds), '?'));
        $rows = $db->fetchAll(
            "SELECT id, internal_code, prop65_chemical_name, prop65_toxicity_types
             FROM raw_materials
             WHERE id IN ({$placeholders}) AND is_prop65 = 1
             AND prop65_chemical_name IS NOT NULL AND prop65_chemical_name != ''",
            $rmIds
        );

        $result = [];
        foreach ($rows as $row) {
            $rmPct = $pctByRm[(int) $row['id']] ?? 0;
            $result[] = [
                'chemical_name'     => $row['prop65_chemical_name'],
                'cas_number'        => '',
                'concentration_pct' => $rmPct,
                'toxicity_type'     => array_map('trim', explode(',', $row['prop65_toxicity_types'] ?? '')),
                'source'            => 'manual',
                'raw_material_code' => $row['internal_code'],
            ];
        }

        return $result;
    }

    /**
     * Gather manual HAP entries from raw materials used in the formula.
     *
     * Each raw material may have a haps_data JSON column containing
     * individual HAP chemicals with their weight percent within the RM.
     * The effective concentration in the FG is calculated from the formula line pct.
     */
    private function getManualHaps(array $formulaLines): array
    {
        $db = Database::getInstance();
        $rmIds = array_column($formulaLines, 'raw_material_id');
        if (empty($rmIds)) {
            return [];
        }

        $pctByRm = [];
        foreach ($formulaLines as $line) {
            $pctByRm[(int) $line['raw_material_id']] = (float) ($line['pct'] ?? 0);
        }

        $placeholders = implode(',', array_fill(0, count($rmIds), '?'));
        $rows = $db->fetchAll(
            "SELECT id, internal_code, haps_data
             FROM raw_materials
             WHERE id IN ({$placeholders}) AND haps_data IS NOT NULL AND haps_data != '[]' AND haps_data != ''",
            $rmIds
        );

        $result = [];
        foreach ($rows as $row) {
            $haps = json_decode($row['haps_data'] ?? '[]', true);
            if (!is_array($haps)) {
                continue;
            }
            $rmPct = $pctByRm[(int) $row['id']] ?? 0;
            foreach ($haps as $hap) {
                $hapWtPct = (float) ($hap['weight_pct'] ?? 0);
                if ($hapWtPct <= 0) {
                    continue;
                }
                // Effective HAP concentration in finished good
                $effectivePct = $hapWtPct * $rmPct / 100;
                $result[] = [
                    'chemical_name'     => $hap['chemical_name'] ?? '',
                    'cas_number'        => $hap['cas_number'] ?? '',
                    'weight_pct_in_rm'  => $hapWtPct,
                    'concentration_pct' => round($effectivePct, 4),
                    'source'            => 'manual',
                    'raw_material_code' => $row['internal_code'],
                ];
            }
        }

        return $result;
    }
}
