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
 *  (* Headings required by 29 CFR 1910.1200(g)(2); content not enforced by OSHA — see item #25 footnote)
 */
class SDSGenerator
{
    private TranslationService $t;

    /** @var array|null Cached company settings (shared across instances within a request). */
    private static ?array $companySettingsCache = null;

    /**
     * H-code families whose effects are delayed (sensitisation, CMR,
     * lactation, STOT-RE). Sensitisation (H317/H334) needs an induction
     * phase before elicitation (GHS Rev. 7 ch. 3.4), so it is grouped with
     * the repeated-exposure effects, matching Section 11's treatment.
     * Prefix-matched against the first four characters so H360FD, H350i
     * etc. match. Every other H3xx code is treated as an acute effect in
     * the Section 4(b) symptoms line.
     */
    private const DELAYED_EFFECT_H_PREFIXES = ['H317', 'H334', 'H340', 'H341', 'H350', 'H351', 'H360', 'H361', 'H362', 'H372', 'H373'];

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

        // Run hazard classification, passing any Phase-5 finished-good
        // hazard override the user has configured for this FG.
        $hazardEngine = new HazardEngine();
        $fgOverride   = $this->buildFinishedGoodOverride($fg);
        $hazardResult = $hazardEngine->classify($calcResult['composition'], $fgOverride);

        // Carbon Black CAS# 1333-86-4 special logic:
        // Apply Carcinogen Category 2 (H351) only if Carbon Black is the only
        // ingredient OR all other ingredients are powders. If mixed with any
        // non-powder material, do not apply the carcinogen classification.
        // Titanium Dioxide (CAS 13463-67-7) follows the same rule.
        $this->applyCarbonBlackLogic($hazardResult, $calcResult);

        // Run carcinogen analysis (IARC/NTP/OSHA)
        $carcinogenResult = CarcinogenService::analyse($calcResult['composition']);

        // Run SARA 313 analysis
        $saraResult = SARA313Service::analyse($calcResult['composition']);

        // Gather manual regulatory data from raw materials in this formula
        // Use enriched_lines so RMs that live inside a sub-FG
        // component are walked too — the top-level formula.lines only
        // has direct RMs + sub-FG references, so manual Prop 65 / HAP
        // entries on an RM one level down were silently dropped before.
        $formulaLines = $calcResult['formula_props']['enriched_lines']
            ?? $calcResult['formula']['lines']
            ?? [];
        $manualProp65 = $this->getManualProp65($formulaLines);
        $manualHaps   = self::getManualHaps($formulaLines);

        // Run Prop 65 analysis (CAS-level + manual raw material flags)
        $prop65Result = Prop65Service::analyse($calcResult['composition'], $manualProp65);

        // Run HAP analysis (Clean Air Act Section 112(b) + manual entries)
        $hapResult = HAPService::analyse($calcResult['composition'], $manualHaps);

        // Solid/powder-in-liquid filtering: suppress carcinogen findings,
        // exposure controls, and Prop 65 (carbon black) for solid/powder
        // ingredients that are mixed with a liquid component.
        // Must run BEFORE applyCarcinogenFindings so suppressed findings
        // are not merged into hazard classifications.
        $this->applySolidPowderLiquidFiltering($carcinogenResult, $hazardResult, $prop65Result, $calcResult);

        // Merge carcinogen registry findings into hazard result so Section 2
        // reflects carcinogenicity when federal GHS data is missing
        $this->applyCarcinogenFindings($hazardResult, $carcinogenResult);

        // Translate GHS data (H/P statements, signal word, pictograms) for target language
        $hazardResult = GHSStatements::translateHazardResult($hazardResult, $language);

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
                5  => $this->section5($calcResult, $hazardResult, $overrides),
                6  => $this->section6($hazardResult, $fg, $overrides),
                7  => $this->section7($hazardResult, $overrides),
                8  => $this->section8($hazardResult, $calcResult['composition'], $overrides),
                9  => $this->section9($fg, $calcResult, $overrides),
                10 => $this->section10($hazardResult, $overrides),
                11 => $this->section11($hazardResult, $calcResult['composition'], $carcinogenResult, $overrides),
                12 => $this->section12($hazardResult, $calcResult['composition'], $overrides),
                13 => $this->section13($hazardResult, $calcResult, $overrides),
                14 => $this->section14($dotInfo, $overrides),
                15 => $this->section15($saraResult, $prop65Result, $hapResult, $calcResult, $overrides),
                16 => $this->section16($calcResult, $overrides),
            ],
            'hazard_result'       => $hazardResult,
            'voc_result'          => $calcResult['voc'],
            'sara_result'         => $saraResult,
            'prop65_result'       => $prop65Result,
            'carcinogen_result'   => $carcinogenResult,
            'hap_result'          => $hapResult,
            'warnings'            => array_merge($calcResult['warnings'], $uvWarnings),
            'legal_disclaimer'    => $this->resolveLegalDisclaimer($company, $language),
        ];

        // Append UV acrylate safe-handling language to relevant sections
        foreach ($uvSectionAppend as $secNum => $appendText) {
            if (isset($sds['sections'][$secNum])) {
                $sds['sections'][$secNum]['uv_acrylate_note'] = $appendText;
            }
        }

        // Section 16 abbreviations: master table filtered to the terms that
        // actually print on this sheet (audit #33). Needs every section and
        // meta.labels assembled, so it runs last.
        $sds['sections'][16]['abbreviations'] = AbbreviationService::build($sds, $this->t);

        return $sds;
    }

    /**
     * Compute all language-independent data for a finished good.
     *
     * Call this once per finished good, then pass the result to
     * generateFromBase() for each language. This avoids re-running
     * formula calculations, hazard classification, and regulatory
     * analyses for every language.
     *
     * @param  int   $finishedGoodId
     * @return array  Base data array to pass to generateFromBase().
     */
    public function computeBase(int $finishedGoodId): array
    {
        $fg = FinishedGood::findById($finishedGoodId);
        if ($fg === null) {
            throw new \RuntimeException('Finished good #' . $finishedGoodId . ' not found.');
        }

        $calcService = new FormulaCalcService();
        $calcResult  = $calcService->calculate($finishedGoodId);

        $hazardEngine = new HazardEngine();
        $fgOverride   = $this->buildFinishedGoodOverride($fg);
        $hazardResult = $hazardEngine->classify($calcResult['composition'], $fgOverride);

        $this->applyCarbonBlackLogic($hazardResult, $calcResult);

        $carcinogenResult = CarcinogenService::analyse($calcResult['composition']);

        $saraResult = SARA313Service::analyse($calcResult['composition']);

        // Use enriched_lines so RMs that live inside a sub-FG
        // component are walked too — the top-level formula.lines only
        // has direct RMs + sub-FG references, so manual Prop 65 / HAP
        // entries on an RM one level down were silently dropped before.
        $formulaLines = $calcResult['formula_props']['enriched_lines']
            ?? $calcResult['formula']['lines']
            ?? [];
        $manualProp65 = $this->getManualProp65($formulaLines);
        $manualHaps   = self::getManualHaps($formulaLines);

        $prop65Result     = Prop65Service::analyse($calcResult['composition'], $manualProp65);
        $hapResult        = HAPService::analyse($calcResult['composition'], $manualHaps);

        // Solid/powder-in-liquid filtering (before merging carcinogen findings)
        $this->applySolidPowderLiquidFiltering($carcinogenResult, $hazardResult, $prop65Result, $calcResult);
        $this->applyCarcinogenFindings($hazardResult, $carcinogenResult);

        $dotInfo = $this->getDOTInfo($calcResult['composition']);
        $company = $this->getCompanySettings();

        $uvWarnings      = [];
        $uvSectionAppend = [];
        if (UVAcrylateRulePack::isApplicable($fg['family'] ?? null)) {
            $acrylates = UVAcrylateRulePack::detectAcrylates($calcResult['composition']);
            if (!empty($acrylates)) {
                $uvSectionAppend = UVAcrylateRulePack::getSafeHandlingLanguage($acrylates);
                $uvWarnings      = UVAcrylateRulePack::getFormulatorWarnings($acrylates);
            }
        }

        return [
            'fg'               => $fg,
            'calcResult'       => $calcResult,
            'hazardResult'     => $hazardResult,
            'saraResult'       => $saraResult,
            'prop65Result'     => $prop65Result,
            'carcinogenResult' => $carcinogenResult,
            'hapResult'        => $hapResult,
            'dotInfo'          => $dotInfo,
            'company'          => $company,
            'uvWarnings'       => $uvWarnings,
            'uvSectionAppend'  => $uvSectionAppend,
        ];
    }

    /**
     * Compute language-independent base data for a resale raw material.
     *
     * Resale path: the alias (or an operator-typed RM code) maps to an
     * RM with no finished-good formula. The SDS is derived from the RM's
     * own constituents at 100 %. Output shape matches computeBase() so
     * generateFromBase() can build language variants without caring
     * which source produced the base.
     *
     * @throws \RuntimeException if the RM doesn't exist.
     */
    public function computeBaseForResaleRawMaterial(int $rawMaterialId): array
    {
        $calcService = new FormulaCalcService();
        $calcResult  = $calcService->calculateForRawMaterial($rawMaterialId);

        // Synthesise the "finished good" row the rest of the pipeline
        // expects. Physical state / color come from the RM so Section 9
        // has something to render. Hazard-override columns are null —
        // resale RMs don't carry FG-level overrides.
        $rm = $calcResult['formula']['lines'][0] ?? [];
        $rmModel = \SDS\Models\RawMaterial::findById($rawMaterialId);
        $baseCode = AliasResolver::stripPack((string) ($rmModel['internal_code'] ?? ''));
        $fg = [
            'id'                         => null,
            'product_code'               => $baseCode,
            'description'                => $rmModel['supplier_product_name'] ?? $baseCode,
            'family'                     => null,
            'is_active'                  => 1,
            'physical_state'             => $rmModel['physical_state'] ?? null,
            'color'                      => $rmModel['color'] ?? null,
            'recommended_use'            => null,
            'restrictions_on_use'        => null,
            'hazard_override_json'       => null,
            'hazard_override_mode'       => 'none',
            'hazard_override_set_by'     => null,
            'hazard_override_set_at'     => null,
            'hazard_override_rationale'  => null,
        ];

        $hazardEngine = new HazardEngine();
        $hazardResult = $hazardEngine->classify($calcResult['composition'], null);

        $this->applyCarbonBlackLogic($hazardResult, $calcResult);

        $carcinogenResult = CarcinogenService::analyse($calcResult['composition']);
        $saraResult       = SARA313Service::analyse($calcResult['composition']);

        // Use enriched_lines so RMs that live inside a sub-FG
        // component are walked too — the top-level formula.lines only
        // has direct RMs + sub-FG references, so manual Prop 65 / HAP
        // entries on an RM one level down were silently dropped before.
        $formulaLines = $calcResult['formula_props']['enriched_lines']
            ?? $calcResult['formula']['lines']
            ?? [];
        $manualProp65 = $this->getManualProp65($formulaLines);
        $manualHaps   = self::getManualHaps($formulaLines);

        $prop65Result = Prop65Service::analyse($calcResult['composition'], $manualProp65);
        $hapResult    = HAPService::analyse($calcResult['composition'], $manualHaps);

        $this->applySolidPowderLiquidFiltering($carcinogenResult, $hazardResult, $prop65Result, $calcResult);
        $this->applyCarcinogenFindings($hazardResult, $carcinogenResult);

        $dotInfo = $this->getDOTInfo($calcResult['composition']);
        $company = $this->getCompanySettings();

        // Resale RMs have no family so the UV acrylate rule pack is
        // skipped — it gates on FG family and we don't have one here.
        $uvWarnings      = [];
        $uvSectionAppend = [];

        return [
            'fg'               => $fg,
            'calcResult'       => $calcResult,
            'hazardResult'     => $hazardResult,
            'saraResult'       => $saraResult,
            'prop65Result'     => $prop65Result,
            'carcinogenResult' => $carcinogenResult,
            'hapResult'        => $hapResult,
            'dotInfo'          => $dotInfo,
            'company'          => $company,
            'uvWarnings'       => $uvWarnings,
            'uvSectionAppend'  => $uvSectionAppend,
            // Source tracking — consumers that insert into sds_versions
            // need to know the RM id and that finished_good_id is null.
            'resale_source'    => [
                'raw_material_id' => $rawMaterialId,
                'base_code'       => $baseCode,
            ],
        ];
    }

    /**
     * Generate a resale-alias or resale-RM SDS in a single call.
     *
     * Convenience wrapper equivalent to computeBaseForResaleRawMaterial()
     * + generateFromBase(). Use this for previews or ad-hoc generation
     * where you only need one language.
     */
    public function generateForResaleRawMaterial(int $rawMaterialId, string $language = 'en'): array
    {
        $base = $this->computeBaseForResaleRawMaterial($rawMaterialId);
        return $this->generateFromBase($base, $language);
    }

    /**
     * Generate the full SDS data structure from pre-computed base data.
     *
     * Only performs language-specific work: GHS translation, text overrides,
     * and section building with the TranslationService.
     *
     * @param  array  $base      From computeBase().
     * @param  string $language
     * @return array  Complete SDS data with all 16 sections.
     */
    public function generateFromBase(array $base, string $language = 'en'): array
    {
        $this->t = new TranslationService($language);

        $fg               = $base['fg'];
        $calcResult       = $base['calcResult'];
        $hazardResult     = $base['hazardResult'];
        $saraResult       = $base['saraResult'];
        $prop65Result     = $base['prop65Result'];
        $carcinogenResult = $base['carcinogenResult'];
        $hapResult        = $base['hapResult'];
        $dotInfo          = $base['dotInfo'];
        $company          = $base['company'];
        $uvWarnings       = $base['uvWarnings'];
        $uvSectionAppend  = $base['uvSectionAppend'];

        // Language-specific: translate GHS data
        $hazardResult = GHSStatements::translateHazardResult($hazardResult, $language);

        // Language-specific: load text overrides
        $overrides = $this->getOverrides((int) $fg['id'], $language);

        $finishedGoodId = (int) $fg['id'];

        // Assemble all 16 sections (uses TranslationService for language)
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
                5  => $this->section5($calcResult, $hazardResult, $overrides),
                6  => $this->section6($hazardResult, $fg, $overrides),
                7  => $this->section7($hazardResult, $overrides),
                8  => $this->section8($hazardResult, $calcResult['composition'], $overrides),
                9  => $this->section9($fg, $calcResult, $overrides),
                10 => $this->section10($hazardResult, $overrides),
                11 => $this->section11($hazardResult, $calcResult['composition'], $carcinogenResult, $overrides),
                12 => $this->section12($hazardResult, $calcResult['composition'], $overrides),
                13 => $this->section13($hazardResult, $calcResult, $overrides),
                14 => $this->section14($dotInfo, $overrides),
                15 => $this->section15($saraResult, $prop65Result, $hapResult, $calcResult, $overrides),
                16 => $this->section16($calcResult, $overrides),
            ],
            'hazard_result'       => $hazardResult,
            'voc_result'          => $calcResult['voc'],
            'sara_result'         => $saraResult,
            'prop65_result'       => $prop65Result,
            'carcinogen_result'   => $carcinogenResult,
            'hap_result'          => $hapResult,
            'warnings'            => array_merge($calcResult['warnings'], $uvWarnings),
            'legal_disclaimer'    => $this->resolveLegalDisclaimer($company, $language),
        ];

        foreach ($uvSectionAppend as $secNum => $appendText) {
            if (isset($sds['sections'][$secNum])) {
                $sds['sections'][$secNum]['uv_acrylate_note'] = $appendText;
            }
        }

        // Section 16 abbreviations: master table filtered to the terms that
        // actually print on this sheet (audit #33). Runs last on purpose.
        $sds['sections'][16]['abbreviations'] = AbbreviationService::build($sds, $this->t);

        return $sds;
    }

    /**
     * Create an alias-specific copy of SDS data.
     *
     * Replaces the product code and description in Section 1 and meta
     * while keeping all other sections identical to the parent finished good.
     *
     * @param  array  $sdsData        The original SDS data array.
     * @param  string $aliasCode      The alias customer code.
     * @param  string $aliasDescription The alias description.
     * @return array  Modified SDS data for the alias.
     */
    public static function createAliasVariant(array $sdsData, string $aliasCode, string $aliasDescription): array
    {
        $aliasSds = $sdsData;

        // Update meta
        $aliasSds['meta']['product_code'] = $aliasCode;
        $aliasSds['meta']['description']  = $aliasDescription;

        // Update Section 1 product identifier
        $aliasSds['sections'][1]['product_identifier'] = $aliasCode . ' — ' . $aliasDescription;

        return $aliasSds;
    }

    /**
     * Compose the Section 1 supplier address line from a company-info array.
     *
     * Accepts either getCompanySettings() (standard SDS, admin settings) or
     * Manufacturer::toCompanyInfo() (private label). Blank parts are skipped
     * so a missing city/state never leaves stray commas, and the country is
     * rendered when set:  "123 Industrial Blvd, Anytown, OH 44000, USA".
     *
     * @param array $info name/address/city/state/zip/country/... (any may be absent)
     */
    public static function formatManufacturerAddress(array $info): string
    {
        $street   = trim((string) ($info['address'] ?? ''));
        $city     = trim((string) ($info['city'] ?? ''));
        $stateZip = trim(trim((string) ($info['state'] ?? '')) . ' ' . trim((string) ($info['zip'] ?? '')));
        $country  = trim((string) ($info['country'] ?? ''));

        $parts = array_values(array_filter(
            [$street, $city, $stateZip, $country],
            static fn (string $p): bool => $p !== ''
        ));
        return implode(', ', $parts);
    }

    /**
     * Build the Section 1 manufacturer/supplier fields (29 CFR 1910.1200
     * App. D, 1(c)) from a company-info array. This is the single builder
     * used for BOTH a standard SDS (admin company.* settings) and a private
     * label SDS (Manufacturer::toCompanyInfo()), so the two document kinds
     * can never drift. It deliberately does NOT set emergency_phone (App. D
     * 1(d)): section1() prints the company number and
     * createManufacturerVariant() the manufacturer's own (no fallback, audit #2).
     *
     * @return array{manufacturer_name:string,manufacturer_address:string,manufacturer_phone:string,manufacturer_email:string,manufacturer_website:string}
     */
    public static function buildManufacturerBlock(array $info): array
    {
        return [
            'manufacturer_name'    => trim((string) ($info['name'] ?? '')),
            'manufacturer_address' => self::formatManufacturerAddress($info),
            'manufacturer_phone'   => trim((string) ($info['phone'] ?? '')),
            'manufacturer_email'   => trim((string) ($info['email'] ?? '')),
            'manufacturer_website' => trim((string) ($info['website'] ?? '')),
        ];
    }

    /**
     * Create a variant of SDS data with manufacturer info overridden.
     *
     * Used for private-label SDS generation where a different company
     * identity is placed on the document.
     *
     * @param array  $sdsData         Base SDS data array from generate()
     * @param array  $manufacturerInfo Manufacturer::toCompanyInfo() array (name, address, city, state, zip, country, phone, emergency_phone, email, website, logo_path, legal_disclaimers (lang => text))
     * @return array Modified SDS data with manufacturer overrides.
     */
    public static function createManufacturerVariant(array $sdsData, array $manufacturerInfo): array
    {
        $variant = $sdsData;

        // Override Section 1 manufacturer fields through the same builder a
        // standard SDS uses (name, address incl. country, phone, email,
        // website). array_merge keeps the existing key order, so the
        // emergency_phone line below stays where it is.
        $variant['sections'][1] = array_merge(
            $variant['sections'][1] ?? [],
            self::buildManufacturerBlock($manufacturerInfo)
        );
        // Private label SDSs print the MANUFACTURER's emergency number — never
        // the company CHEMTREC line (audit #2). A blank number is not a
        // fallback case: it is flagged here (preview Warnings box) and refused
        // by the publish gate (SDSReadinessService::manufacturerEmergencyPhoneError).
        $variant['sections'][1]['emergency_phone']      = trim((string) ($manufacturerInfo['emergency_phone'] ?? ''));
        $mfgPhoneError = SDSReadinessService::manufacturerEmergencyPhoneError($manufacturerInfo);
        if ($mfgPhoneError !== null) {
            $variant['warnings'][] = $mfgPhoneError;
        }

        // Logo comes from the manufacturer record only. A manufacturer with
        // no logo gets NO logo — a private label document must never carry
        // the base company's branding.
        $variant['meta']['company_logo_path'] = trim((string) ($manufacturerInfo['logo_path'] ?? ''));

        // Private-label disclaimer (audit #34): the manufacturer's own text for
        // this sheet's language wins; blank = inherit the base (admin per-language
        // setting, else translation default) already resolved in $sdsData.
        $lang    = (string) ($variant['meta']['language'] ?? 'en');
        $mfgText = trim((string) ($manufacturerInfo['legal_disclaimers'][$lang] ?? ''));
        if ($mfgText !== '') {
            $variant['legal_disclaimer'] = $mfgText;
        }

        // Tag the on-disk filename (PDFService::generate) so a manufacturer-
        // branded document never shares a name with the base or alias SDS of
        // the same product code: {code}_PL_{manufacturer}_v{n}[_{lang}].pdf
        // (or ..._SDS_{lang}_{stamp}.pdf for an unversioned preview).
        $mfgSlug = sanitize_filename(substr(trim((string) ($manufacturerInfo['name'] ?? '')), 0, 40));
        $variant['meta']['filename_tag'] = 'PL' . ($mfgSlug !== '' ? '_' . $mfgSlug : '');

        // Section 16 abbreviations (audit #33) were filtered against the BASE
        // sheet; the disclaimer and the Section 1 supplier block (and, via
        // createPrivateLabelVariant(), the alias identifier) have just
        // changed, so refilter against what this private label sheet prints.
        // DB-free fixtures without a Section 16 are left alone.
        if (isset($variant['sections'][16]) && is_array($variant['sections'][16])) {
            $variant['sections'][16]['abbreviations'] = AbbreviationService::build(
                $variant,
                new TranslationService($lang)
            );
        }

        return $variant;
    }

    /**
     * Create a variant with both alias and manufacturer overrides.
     */
    public static function createPrivateLabelVariant(
        array $sdsData,
        string $productCode,
        string $description,
        array $manufacturerInfo
    ): array {
        $variant = self::createAliasVariant($sdsData, $productCode, $description);
        $variant = self::createManufacturerVariant($variant, $manufacturerInfo);
        return $variant;
    }

    /**
     * Stamp the published version number and effective date on SDS data.
     *
     * Publishers call this once per language right before rendering, with the
     * SAME version number and effective date (Y-m-d) they are about to write
     * to sds_versions / private_label_sds. It sets:
     *   - meta.sds_version      (PDFService::generate() filename {code}_v{n})
     *   - meta.effective_date   (Y-m-d, machine form kept in the snapshot)
     *   - sections[16].version / sections[16].effective_date (printed lines,
     *     date as m/d/Y in every language — user decision)
     * so the PDF, the stored snapshot and the database row always agree.
     * Until this is called the document is a draft: section16() prints the
     * translated "Draft (not yet published)" text and no effective date.
     *
     * A decoded legacy base snapshot (published before these keys existed)
     * may still carry a generation-time revision_date and lack the two new
     * labels; both are handled here so a republished alias never prints a
     * stale date or a raw label key.
     *
     * @param  array  $sdsData        Output of generate()/generateFromBase() or a variant of it.
     * @param  int    $version        Version number being published (>= 1).
     * @param  string $effectiveDate  Effective date as Y-m-d (the value written to the row).
     * @return array  Stamped copy of $sdsData.
     */
    public static function stampPublishedVersion(array $sdsData, int $version, string $effectiveDate): array
    {
        $sdsData['meta']['sds_version']    = $version;
        $sdsData['meta']['effective_date'] = $effectiveDate;

        $sdsData['sections'][16]['version']        = (string) $version;
        $sdsData['sections'][16]['effective_date'] = format_date($effectiveDate, 'm/d/Y');
        unset($sdsData['sections'][16]['revision_date']);

        // Legacy base snapshots (published before labels.version /
        // labels.effective_date existed) lack these two keys; resolve them
        // for the sheet's own language, never a hard-coded English string.
        if (!isset($sdsData['meta']['labels']['version']) || !isset($sdsData['meta']['labels']['effective_date'])) {
            $t = new TranslationService((string) ($sdsData['meta']['language'] ?? 'en'));
            $sdsData['meta']['labels']['version']        ??= $t->get('labels.version');
            $sdsData['meta']['labels']['effective_date'] ??= $t->get('labels.effective_date');
        }

        return $sdsData;
    }

    /* ------------------------------------------------------------------
     *  H-code extraction helper (used by smart logic in sections 4–13)
     * ----------------------------------------------------------------*/

    /**
     * Extract individual H-codes from the hazard result's h_statements array.
     *
     * Combined codes like "H300+H310+H330" are split into individual codes.
     *
     * @param  array $hazardResult  The full hazard result from HazardEngine.
     * @return string[]  Unique H-code strings, e.g. ['H225', 'H304', 'H314'].
     */
    private static function extractHCodes(array $hazardResult): array
    {
        $hCodes = [];
        foreach ($hazardResult['h_statements'] ?? [] as $s) {
            $code = $s['code'] ?? '';
            if ($code === '') {
                continue;
            }
            $hCodes[] = $code;
            foreach (explode('+', $code) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $hCodes[] = $part;
                }
            }
        }
        return array_values(array_unique($hCodes));
    }

    /* ------------------------------------------------------------------
     *  Section builders
     * ----------------------------------------------------------------*/

    private function section1(array $fg, array $company, array $overrides): array
    {
        // Supplier block (App. D 1(c)) via the shared builder — same code
        // path createManufacturerVariant() uses for private label.
        $block = self::buildManufacturerBlock($company);
        return [
            'title' => $this->t->get('section1.title', []),
            'product_identifier'    => $fg['product_code'] . ' — ' . $fg['description'],
            'product_family'        => $fg['family'] ?? '',
            'recommended_use'       => $overrides[1]['recommended_use'] ?? ($fg['recommended_use'] ?? '') ?: $this->t->get('section1.recommended_use'),
            'restrictions'          => $overrides[1]['restrictions'] ?? ($fg['restrictions_on_use'] ?? '') ?: $this->t->get('section1.restrictions'),
            'manufacturer_name'     => $block['manufacturer_name'],
            'manufacturer_address'  => $block['manufacturer_address'],
            'manufacturer_phone'    => $block['manufacturer_phone'],
            'emergency_phone'       => $company['emergency_phone'] ?? '',
            'manufacturer_email'    => $block['manufacturer_email'],
            'manufacturer_website'  => $block['manufacturer_website'],
        ];
    }

    private function section2(array $hazard, array $overrides): array
    {
        // PPE: the same resolved values Section 8 prints (operator override,
        // else the translated sentence for the H-code-derived tier — see
        // resolvePPE()). Section 2 only shows the hazard-driven fields; the
        // 'general' / 'none' baselines stay in Section 8, so an unclassified
        // product gets no PPE pictograms here.
        $resolved = $this->resolvePPE($hazard, $overrides);
        $ppe = [];
        foreach (HazardEngine::PPE_FIELDS as $field) {
            $ppe[$field] = $resolved['hazard_driven'][$field] ? $resolved['text'][$field] : null;
        }

        $customOtherHazards = $overrides[2]['other_hazards'] ?? null;

        $isClassified = !empty($hazard['signal_word'])
            || !empty($hazard['pictograms'])
            || !empty($hazard['hazard_classes'])
            || !empty($hazard['h_statements']);

        return [
            'title'               => $this->t->get('section2.title'),
            'is_classified'       => $isClassified,
            'not_classified_text' => $this->t->get('section2.not_classified'),
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
        // Disclose CAS numbers that are either classified as hazardous
        // OR have an exposure limit on file. OSHA HazCom requires
        // Section 3 disclosure for any constituent with an exposure
        // limit even if it doesn't trigger a GHS category.
        $hazardousCas = array_flip($hazardResult['hazardous_cas'] ?? []);

        $casWithExposureLimit = [];
        foreach ($hazardResult['exposure_limits'] ?? [] as $el) {
            $limCas = $el['cas_number'] ?? '';
            if ($limCas !== '') {
                $casWithExposureLimit[$limCas] = true;
            }
        }

        // Build a per-CAS H-code list from the consolidated hazard_classes
        // so Section 3's component table can show each component alongside
        // the GHS hazard codes it contributed.
        //
        // Per-component entries contribute to their own CAS directly.
        // Mixture-level entries (aquatic summation, ATE mixture, cross-
        // category summation) fire at the mixture level — the CAS field
        // reads 'MIXTURE' — but each entry carries a contributors[] list
        // of the CAS numbers that drove the classification. We attribute
        // the mixture's h_codes back to every contributor so Section 3
        // and Section 2 don't disagree about which codes apply.
        //
        // FG_OVERRIDE / TRADE_SECRET stay unattributed — those H-codes
        // come from operator overrides or trade-secret declarations with
        // no individual CAS to credit.
        $casToHCodes = [];
        foreach (($hazardResult['hazard_classes'] ?? []) as $hc) {
            $hcCas = (string) ($hc['cas'] ?? '');

            if ($hcCas === 'MIXTURE') {
                $mixtureCodes      = $hc['h_codes']      ?? [];
                $mixtureContribs   = $hc['contributors'] ?? [];
                foreach ($mixtureContribs as $contribCas) {
                    $contribCas = (string) $contribCas;
                    if ($contribCas === '' || $contribCas === 'TRADE_SECRET') {
                        continue;
                    }
                    foreach ($mixtureCodes as $code) {
                        if ($code !== '') {
                            $casToHCodes[$contribCas][$code] = true;
                        }
                    }
                }
                continue;
            }

            if ($hcCas === '' || $hcCas === 'FG_OVERRIDE' || $hcCas === 'TRADE_SECRET') {
                continue;
            }

            foreach (($hc['h_codes'] ?? []) as $code) {
                if ($code !== '') {
                    $casToHCodes[$hcCas][$code] = true;
                }
            }
        }

        // Collect H codes attributed to TRADE_SECRET from hazard results
        $tradeSecretHCodes = [];
        foreach (($hazardResult['hazard_classes'] ?? []) as $hc) {
            if ((string) ($hc['cas'] ?? '') === 'TRADE_SECRET') {
                foreach (($hc['h_codes'] ?? []) as $code) {
                    if ($code !== '') {
                        $tradeSecretHCodes[$code] = true;
                    }
                }
            }
        }

        $disclosed      = [];
        $tradeSecretBuckets = []; // group trade secrets by description

        foreach ($composition as $c) {
            $cas  = $c['cas_number'] ?? '';
            $conc = (float) ($c['concentration_pct'] ?? 0);

            // Skip non-hazardous constituents
            if (!empty($c['is_non_hazardous'])) {
                continue;
            }

            // Must be disclosable and at/above the 0.1 % w/w disclosure
            // cut-off. 0.1 % is fixed policy, not a setting — see the
            // PRESCRIBED_RANGES docblock and docs/operations.md "SDS content
            // policy". It is the lowest ingredient cut-off in 29 CFR
            // 1910.1200 Appendix A (carcinogens, reproductive toxicants,
            // germ cell mutagens cat. 1, respiratory sensitisers), so no
            // constituent that can drive a classification is ever hidden.
            // Disclosable = classified as hazardous OR has an exposure limit.
            if ($cas === '' || $conc < 0.1) {
                continue;
            }
            if (!isset($hazardousCas[$cas]) && !isset($casWithExposureLimit[$cas])) {
                continue;
            }

            // Airborne/unbound particles override: after wet-mixture
            // suppression, a listed CAS with no attributed H-codes and no
            // exposure limit triggers nothing on this SDS — omit it from
            // the composition table entirely. All-powder products keep
            // their H351 attribution, so they still list it.
            if (isset(self::getInhalationOnlyCas()[$cas])
                && empty($casToHCodes[$cas])
                && !isset($casWithExposureLimit[$cas])) {
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
                        'concentration_min' => null,
                        'concentration_max' => null,
                    ];
                }
                $tradeSecretBuckets[$desc]['concentration_pct'] += $conc;
                if (isset($c['concentration_min']) && isset($c['concentration_max'])) {
                    $tradeSecretBuckets[$desc]['concentration_min'] =
                        ($tradeSecretBuckets[$desc]['concentration_min'] ?? 0) + $c['concentration_min'];
                    $tradeSecretBuckets[$desc]['concentration_max'] =
                        ($tradeSecretBuckets[$desc]['concentration_max'] ?? 0) + $c['concentration_max'];
                }
                continue;
            }

            $disclosed[] = [
                'cas_number'          => $cas,
                'chemical_name'       => $c['chemical_name'],
                'concentration_pct'   => $conc,
                'concentration_range' => $this->formatConcentration($c),
                'h_codes'             => array_keys($casToHCodes[$cas] ?? []),
            ];
        }

        // Add merged trade secret lines
        foreach ($tradeSecretBuckets as $bucket) {
            $disclosed[] = [
                'cas_number'          => 'TRADE SECRET',
                'chemical_name'       => $bucket['chemical_name'],
                'concentration_pct'   => round($bucket['concentration_pct'], 4),
                'concentration_range' => $this->formatConcentration($bucket),
                'h_codes'             => array_keys($tradeSecretHCodes),
            ];
        }

        // Sort by concentration descending
        usort($disclosed, fn($a, $b) => $b['concentration_pct'] <=> $a['concentration_pct']);

        return [
            'title'                => $this->t->get('section3.title'),
            'substance_or_mixture' => $this->t->get('labels.mixture'),
            'components'           => $disclosed,
            // Exact percentages are withheld on every row (prescribed-range
            // bands — see PRESCRIBED_RANGES), so the 1910.1200(i)(1)
            // withholding statement prints whenever components are listed.
            'trade_secret_note'    => !empty($disclosed)
                ? $this->t->get('section3.trade_secret_note')
                : null,
        ];
    }

    private function section4(array $hazard, array $overrides): array
    {
        $hCodes = self::extractHCodes($hazard);
        $has    = static fn(array $codes): bool => !empty(array_intersect($hCodes, $codes));

        // 4(a) Necessary measures by route of exposure (29 CFR 1910.1200 App. D).
        // Two kinds of hazard logic, deliberately different:
        //   - SEVERE fragments (acute tox. 1-3, skin corrosion, serious eye
        //     damage, aspiration) are complete paragraphs that REPLACE the
        //     base text, because the base advice ("...if irritation persists")
        //     is too weak for them;
        //   - ADDITIVE fragments are single sentences APPENDED to whichever
        //     paragraph was chosen, so the common ink codes (H302/H312/H315/
        //     H317/H319/H332/H334/H335/H336) add route-specific advice.
        // A per-FG override replaces the whole field, fragments included.

        // --- Inhalation ---
        $inhalation = $overrides[4]['inhalation'] ?? null;
        if ($inhalation === null) {
            if ($has(['H330'])) {
                $inhalation = $this->t->get('section4.inhalation_fatal');
            } elseif ($has(['H331'])) {
                $inhalation = $this->t->get('section4.inhalation_toxic');
            } else {
                $inhalation = $this->t->get('section4.inhalation');
                if ($has(['H332'])) {
                    $inhalation .= ' ' . $this->t->get('section4.inhalation_harmful');
                }
            }
            if ($has(['H334'])) {
                $inhalation .= ' ' . $this->t->get('section4.inhalation_resp_sensitizer');
            }
            if ($has(['H335'])) {
                $inhalation .= ' ' . $this->t->get('section4.inhalation_irritant');
            }
            if ($has(['H336'])) {
                $inhalation .= ' ' . $this->t->get('section4.inhalation_narcotic');
            }
        }

        // --- Skin ---
        $skin = $overrides[4]['skin'] ?? null;
        if ($skin === null) {
            if ($has(['H314'])) {
                $skin = $this->t->get('section4.skin_corrosive');
            } elseif ($has(['H310', 'H311'])) {
                $skin = $this->t->get('section4.skin_toxic');
            } else {
                $skin = $this->t->get('section4.skin');
                if ($has(['H312'])) {
                    $skin .= ' ' . $this->t->get('section4.skin_harmful');
                }
                if ($has(['H315'])) {
                    $skin .= ' ' . $this->t->get('section4.skin_irritant');
                }
            }
            if ($has(['H317'])) {
                $skin .= ' ' . $this->t->get('section4.skin_sensitizer');
            }
        }

        // --- Eyes ---
        $eyes = $overrides[4]['eyes'] ?? null;
        if ($eyes === null) {
            if ($has(['H314'])) {
                $eyes = $this->t->get('section4.eyes_corrosive');
            } elseif ($has(['H318'])) {
                $eyes = $this->t->get('section4.eyes_serious_damage');
            } else {
                $eyes = $this->t->get('section4.eyes');
            }
            if ($has(['H314', 'H318', 'H319'])) {
                $eyes .= ' ' . $this->t->get('section4.eyes_contact_lenses');
            }
        }

        // --- Ingestion ---
        $ingestion = $overrides[4]['ingestion'] ?? null;
        if ($ingestion === null) {
            if ($has(['H304', 'H305'])) {
                $ingestion = $this->t->get('section4.ingestion_aspiration');
            } elseif ($has(['H300', 'H301'])) {
                $ingestion = $this->t->get('section4.ingestion_toxic');
            } else {
                $ingestion = $this->t->get('section4.ingestion');
                if ($has(['H302'])) {
                    $ingestion .= ' ' . $this->t->get('section4.ingestion_harmful');
                }
            }
        }

        // 4(b) Most important symptoms/effects, acute and delayed — derived
        // from the health H-statements already on the hazard result (their
        // text was localised by GHSStatements::translateHazardResult()).
        $symptoms = $overrides[4]['symptoms'] ?? null;
        if ($symptoms === null) {
            $symptoms = $this->deriveSymptoms($hazard['h_statements'] ?? []);
        }

        // 4(c) Notes to physician: same base + appended-fragment pattern.
        $notes = $overrides[4]['notes'] ?? null;
        if ($notes === null) {
            $notes = $this->t->get('section4.notes');
            if ($has(['H304', 'H305'])) {
                $notes .= ' ' . $this->t->get('section4.notes_aspiration');
            }
            if ($has(['H314'])) {
                $notes .= ' ' . $this->t->get('section4.notes_corrosive');
            }
            if ($has(['H330', 'H331'])) {
                $notes .= ' ' . $this->t->get('section4.notes_inhalation_delayed');
            }
        }

        return [
            'title'       => $this->t->get('section4.title'),
            'inhalation'  => $inhalation,
            'skin'        => $skin,
            'eyes'        => $eyes,
            'ingestion'   => $ingestion,
            'symptoms'    => $symptoms,
            'notes'       => $notes,
        ];
    }

    /**
     * Build the Section 4(b) "most important symptoms/effects, acute and
     * delayed" line from the H3xx statements present. Physical (H2xx) and
     * environmental (H4xx) codes are not symptoms and are skipped. Texts are
     * de-duplicated and each is terminated with a period.
     *
     * @param  array $hStatements  [['code' => 'H315', 'text' => '...'], ...]
     */
    private function deriveSymptoms(array $hStatements): string
    {
        $acute   = [];
        $delayed = [];
        foreach ($hStatements as $s) {
            $code = strtoupper(trim((string) ($s['code'] ?? '')));
            if ($code === '' || strncmp($code, 'H3', 2) !== 0) {
                continue;
            }
            $text = trim((string) ($s['text'] ?? ''));
            if ($text === '') {
                $text = GHSStatements::hText($code, $this->t->getLanguage());
            }
            if ($text === '') {
                continue;
            }
            $text = rtrim($text, '.') . '.';
            if (in_array(substr($code, 0, 4), self::DELAYED_EFFECT_H_PREFIXES, true)) {
                $delayed[$text] = true;
            } else {
                $acute[$text] = true;
            }
        }

        if ($acute === [] && $delayed === []) {
            return $this->t->get('section4.symptoms_none');
        }

        $parts = [];
        if ($acute !== []) {
            $parts[] = $this->t->get('section4.symptoms_acute_prefix') . ' ' . implode(' ', array_keys($acute));
        }
        if ($delayed !== []) {
            $parts[] = $this->t->get('section4.symptoms_delayed_prefix') . ' ' . implode(' ', array_keys($delayed));
        }
        return implode(' ', $parts);
    }

    private function section5(array $calcResult, array $hazardResult, array $overrides): array
    {
        $flashPoint = null;
        foreach ($calcResult['formula']['lines'] ?? [] as $line) {
            $fp = $line['flash_point_c'] ?? null;
            if ($fp !== null && ($flashPoint === null || (float) $fp < $flashPoint)) {
                $flashPoint = (float) $fp;
            }
        }

        $hCodes = self::extractHCodes($hazardResult);

        $waterReactive = !empty(array_intersect($hCodes, ['H260', 'H261']));
        $oxidizer      = !empty(array_intersect($hCodes, ['H271', 'H272']));
        $organicPeroxide = !empty(array_intersect($hCodes, ['H240', 'H241', 'H242']));
        $explosive     = !empty(array_intersect($hCodes, ['H200', 'H201', 'H202', 'H203', 'H204', 'H205']));

        // --- Suitable media smart logic ---
        $suitableMedia = $overrides[5]['suitable_media'] ?? null;
        if ($suitableMedia === null) {
            if ($oxidizer) {
                $suitableMedia = $this->t->get('section5.suitable_oxidizer');
            } else {
                $suitableMedia = $this->t->get('section5.suitable_media');
            }
        }

        // --- Unsuitable media smart logic ---
        $unsuitableMedia = $overrides[5]['unsuitable_media'] ?? null;
        if ($unsuitableMedia === null) {
            if ($waterReactive) {
                $unsuitableMedia = $this->t->get('section5.unsuitable_water_reactive');
            } else {
                $unsuitableMedia = $this->t->get('section5.unsuitable_media');
            }
        }

        // --- Specific hazards smart logic ---
        $specificHazards = $overrides[5]['specific_hazards'] ?? null;
        if ($specificHazards === null) {
            if ($oxidizer) {
                $specificHazards = $this->t->get('section5.specific_hazards_oxidizer');
            } elseif ($organicPeroxide) {
                $specificHazards = $this->t->get('section5.specific_hazards_organic_peroxide');
            } else {
                $specificHazards = $this->t->get('section5.specific_hazards');
            }
            // Append low flash point warning if applicable
            if ($flashPoint !== null && $flashPoint < 23.0) {
                $specificHazards .= ' ' . $this->t->get('section5.flash_point_low_warning');
            }
        }

        // --- Firefighter advice smart logic ---
        $firefighterAdvice = $overrides[5]['firefighter_advice'] ?? null;
        if ($firefighterAdvice === null) {
            if ($explosive) {
                $firefighterAdvice = $this->t->get('section5.firefighter_advice_explosive');
            } else {
                $firefighterAdvice = $this->t->get('section5.firefighter_advice');
            }
        }

        return [
            'title'                => $this->t->get('section5.title'),
            'suitable_media'       => $suitableMedia,
            'unsuitable_media'     => $unsuitableMedia,
            'specific_hazards'     => $specificHazards,
            'firefighter_advice'   => $firefighterAdvice,
            'flash_point_c'        => $flashPoint,
        ];
    }

    private function section6(array $hazardResult, array $fg, array $overrides): array
    {
        $hCodes = self::extractHCodes($hazardResult);

        $corrosive  = !empty(array_intersect($hCodes, ['H314']));
        $acuteToxic = !empty(array_intersect($hCodes, ['H300', 'H310', 'H330']));
        $aquatic    = !empty(array_intersect($hCodes, ['H400', 'H401', 'H402', 'H410', 'H411', 'H412', 'H413']));
        $physicalState = strtolower($fg['physical_state'] ?? '');

        // --- Personal precautions smart logic ---
        $precautions = $overrides[6]['personal_precautions'] ?? null;
        if ($precautions === null) {
            if ($acuteToxic) {
                $precautions = $this->t->get('section6.precautions_acute_toxic');
            } elseif ($corrosive) {
                $precautions = $this->t->get('section6.precautions_corrosive');
            } else {
                $precautions = $this->t->get('section6.personal_precautions');
            }
        }

        // --- Environmental smart logic ---
        $environmental = $overrides[6]['environmental'] ?? null;
        if ($environmental === null) {
            if ($aquatic) {
                $environmental = $this->t->get('section6.environmental_aquatic');
            } else {
                $environmental = $this->t->get('section6.environmental');
            }
        }

        // --- Containment smart logic (based on physical state) ---
        $containment = $overrides[6]['containment'] ?? null;
        if ($containment === null) {
            if ($physicalState === 'solid' || $physicalState === 'powder') {
                $containment = $this->t->get('section6.containment_solid');
            } elseif ($physicalState === 'liquid' || $physicalState === 'paste') {
                $containment = $this->t->get('section6.containment_liquid');
            } else {
                $containment = $this->t->get('section6.containment');
            }
        }

        return [
            'title'                => $this->t->get('section6.title'),
            'personal_precautions' => $precautions,
            'environmental'        => $environmental,
            'containment'          => $containment,
        ];
    }

    private function section7(array $hazardResult, array $overrides): array
    {
        $hCodes = self::extractHCodes($hazardResult);

        $flammable    = !empty(array_intersect($hCodes, ['H220', 'H221', 'H222', 'H223', 'H224', 'H225', 'H226', 'H227', 'H228']));
        $oxidizer     = !empty(array_intersect($hCodes, ['H271', 'H272']));
        $waterReactive = !empty(array_intersect($hCodes, ['H260', 'H261']));
        $pyrophoric   = !empty(array_intersect($hCodes, ['H250', 'H251']));
        $selfReactive = !empty(array_intersect($hCodes, ['H240', 'H241', 'H242']));
        $selfHeating  = !empty(array_intersect($hCodes, ['H251', 'H252']));

        // --- Handling smart logic (pick the most hazardous applicable) ---
        $handling = $overrides[7]['handling'] ?? null;
        if ($handling === null) {
            if ($pyrophoric) {
                $handling = $this->t->get('section7.handling_pyrophoric');
            } elseif ($waterReactive) {
                $handling = $this->t->get('section7.handling_water_reactive');
            } elseif ($selfReactive) {
                $handling = $this->t->get('section7.handling_self_reactive');
            } elseif ($oxidizer) {
                $handling = $this->t->get('section7.handling_oxidizer');
            } elseif ($flammable) {
                $handling = $this->t->get('section7.handling_flammable');
            } else {
                $handling = $this->t->get('section7.handling');
            }
        }

        // --- Storage smart logic ---
        $storage = $overrides[7]['storage'] ?? null;
        if ($storage === null) {
            if ($pyrophoric) {
                $storage = $this->t->get('section7.storage_pyrophoric');
            } elseif ($waterReactive) {
                $storage = $this->t->get('section7.storage_water_reactive');
            } elseif ($selfHeating) {
                $storage = $this->t->get('section7.storage_self_heating');
            } elseif ($oxidizer) {
                $storage = $this->t->get('section7.storage_oxidizer');
            } elseif ($flammable) {
                $storage = $this->t->get('section7.storage_flammable');
            } else {
                $storage = $this->t->get('section7.storage');
            }
        }

        return [
            'title'      => $this->t->get('section7.title'),
            'handling'   => $handling,
            'storage'    => $storage,
        ];
    }

    private function section8(array $hazard, array $composition, array $overrides): array
    {
        // Conc% column: print the SAME prescribed-range band Section 3 shows
        // for this CAS (SDS content policy — see PRESCRIBED_RANGES and
        // docs/operations.md "SDS content policy"). Band the composition row
        // so a supplier min–max range yields the identical band Section 3
        // printed; fall back to the limit's own value when the CAS is not in
        // the composition (e.g. 0.01–0.1 % OEL rows → "<0.1%"). The exact
        // percentage is dropped from the Section 8 copy so no renderer can
        // print it.
        $compByCas = [];
        foreach ($composition as $c) {
            $cCas = (string) ($c['cas_number'] ?? '');
            if ($cCas !== '' && !isset($compByCas[$cCas])) {
                $compByCas[$cCas] = $c;
            }
        }
        $exposureLimits = [];
        foreach (($hazard['exposure_limits'] ?? []) as $el) {
            $elCas  = (string) ($el['cas_number'] ?? '');
            $source = $compByCas[$elCas]
                ?? ['concentration_pct' => (float) ($el['concentration_pct'] ?? 0)];
            $el['concentration_range'] = $this->formatConcentration($source);
            unset($el['concentration_pct']);
            $exposureLimits[] = $el;
        }

        // PPE: operator override, else the translated sentence for the tier
        // HazardEngine::derivePPE selected from the H-codes (see resolvePPE()).
        // derivePPE always yields a tier, so there is no third-level default.
        $ppe = $this->resolvePPE($hazard, $overrides)['text'];

        return [
            'title'            => $this->t->get('section8.title'),
            'exposure_limits'  => $exposureLimits,
            'engineering'      => $overrides[8]['engineering'] ?? $this->t->get('section8.engineering'),
            'respiratory'      => $ppe['respiratory'],
            'hand_protection'  => $ppe['hand_protection'],
            'eye_protection'   => $ppe['eye_protection'],
            'skin_protection'  => $ppe['skin_protection'],
        ];
    }

    /**
     * Resolve the four PPE sentences once so Sections 2 and 8 print the
     * same text. Precedence per field: operator override (text_overrides,
     * section 8) → translated sentence for the tier derivePPE selected.
     *
     * The tiers are re-derived here from the final H-statements rather
     * than read from $hazard['ppe_recommendations'], so every post-classify
     * mutation (carbon black logic, carcinogen registry, FG override) is
     * reflected and the engine's own copy is informational only.
     *
     * @return array{text: array<string,string>, hazard_driven: array<string,bool>}
     */
    private function resolvePPE(array $hazard, array $overrides): array
    {
        $derived = HazardEngine::derivePPE($hazard['h_statements'] ?? [], $hazard['p_statements'] ?? []);

        $text = [];
        $hazardDriven = [];
        foreach (HazardEngine::PPE_FIELDS as $field) {
            $tier = $derived[$field]['tier'] ?? 'none';
            $key  = $derived[$field]['key'] ?? ('section8.ppe.' . $field . '.none');

            $override = $overrides[8][$field] ?? null;
            $text[$field] = ($override !== null && trim((string) $override) !== '')
                ? (string) $override
                : $this->t->get($key);
            $hazardDriven[$field] = !in_array($tier, HazardEngine::PPE_BASELINE_TIERS, true);
        }

        return ['text' => $text, 'hazard_driven' => $hazardDriven];
    }

    private function section9(array $fg, array $calcResult, array $overrides): array
    {
        $voc   = $calcResult['voc'];
        $props = $calcResult['formula_props'] ?? [];

        $notDetermined = $this->t->get('labels.not_determined');

        // Physical state and color from the finished good record
        $physicalState = $fg['physical_state'] ?? '';
        $color = $fg['color'] ?? '';

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

        // Build appearance from physical state + color if not overridden
        $appearance = $overrides[9]['appearance'] ?? '';
        if ($appearance === '' && ($physicalState !== '' || $color !== '')) {
            $parts = [];
            if ($color !== '') {
                $parts[] = $color;
            }
            if ($physicalState !== '') {
                $parts[] = strtolower($physicalState);
            }
            $appearance = implode(' ', $parts);
        }
        // #17: only when neither an override nor FG colour/state gives an
        // appearance, fall back to the dominant raw material's appearance.
        // (Physical state / colour derivation itself is unchanged — see #18.)
        if ($appearance === '') {
            $appearance = (string) ($props['appearance'] ?? '');
        }

        // #17 Odor: per-FG override wins; otherwise the dominant (highest
        // wt%) raw material's odor (formula_props.odor); otherwise print the
        // "no applicable information" statement required by
        // 29 CFR 1910.1200(g)(3) instead of dropping the App D line.
        $odor = trim((string) ($overrides[9]['odor'] ?? ''));
        if ($odor === '') {
            $odor = (string) ($props['odor'] ?? '');
        }
        if ($odor === '') {
            $odor = $notDetermined;
        }

        return [
            'title'                => $this->t->get('section9.title'),
            'physical_state'       => $physicalState,
            'color'                => $color,
            'appearance'           => $appearance,
            'odor'                 => $odor,
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

    private function section10(array $hazardResult, array $overrides): array
    {
        $hCodes = self::extractHCodes($hazardResult);

        $flammable    = !empty(array_intersect($hCodes, ['H220', 'H221', 'H222', 'H223', 'H224', 'H225', 'H226', 'H227', 'H228']));
        $oxidizer     = !empty(array_intersect($hCodes, ['H271', 'H272']));
        $waterReactive = !empty(array_intersect($hCodes, ['H260', 'H261']));
        $pyrophoric   = !empty(array_intersect($hCodes, ['H250']));
        $selfReactive = !empty(array_intersect($hCodes, ['H240', 'H241', 'H242']));

        // --- Conditions to avoid smart logic ---
        $conditionsAvoid = $overrides[10]['conditions_avoid'] ?? null;
        if ($conditionsAvoid === null) {
            if ($pyrophoric) {
                $conditionsAvoid = $this->t->get('section10.conditions_avoid_pyrophoric');
            } elseif ($waterReactive) {
                $conditionsAvoid = $this->t->get('section10.conditions_avoid_water_reactive');
            } elseif ($selfReactive) {
                $conditionsAvoid = $this->t->get('section10.conditions_avoid_self_reactive');
            } else {
                $conditionsAvoid = $this->t->get('section10.conditions_avoid');
            }
        }

        // --- Incompatible materials smart logic ---
        $incompatible = $overrides[10]['incompatible'] ?? null;
        if ($incompatible === null) {
            if ($pyrophoric) {
                $incompatible = $this->t->get('section10.incompatible_pyrophoric');
            } elseif ($waterReactive) {
                $incompatible = $this->t->get('section10.incompatible_water_reactive');
            } elseif ($oxidizer) {
                $incompatible = $this->t->get('section10.incompatible_oxidizer');
            } elseif ($flammable) {
                $incompatible = $this->t->get('section10.incompatible_flammable');
            } else {
                $incompatible = $this->t->get('section10.incompatible');
            }
        }

        // --- Decomposition products smart logic ---
        // Look at classified hazard classes to detect nitrogen/sulfur/halogen-containing chemicals
        $decomposition = $overrides[10]['decomposition'] ?? null;
        if ($decomposition === null) {
            $decomposition = $this->t->get('section10.decomposition');
        }

        return [
            'title'             => $this->t->get('section10.title'),
            'reactivity'        => $overrides[10]['reactivity'] ?? $this->t->get('section10.reactivity'),
            'stability'         => $overrides[10]['stability'] ?? $this->t->get('section10.stability'),
            'conditions_avoid'  => $conditionsAvoid,
            'incompatible'      => $incompatible,
            'decomposition'     => $decomposition,
        ];
    }

    /**
     * Section 11 "Chronic Effects" (audit #21).
     *
     * Builds the sentence from health-hazard fragments keyed off the
     * classified H-codes instead of printing the EUH066-style defatting
     * sentence on every sheet. Fragment order mirrors the GHS Rev. 7 /
     * HazCom 2024 health-class order: sensitisation (respiratory, skin),
     * germ cell mutagenicity, carcinogenicity, reproductive toxicity
     * (incl. lactation), STOT-RE, then repeated-contact irritation.
     * Falls back to a "none known" statement when no chronic class fires.
     * Admin text_overrides[11]['chronic_effects'] is applied by the caller.
     */
    private function buildChronicEffects(array $hazard, array $carcinogenResult): string
    {
        $hCodes = self::extractHCodes($hazard);

        $has = static fn(array $codes): bool => !empty(array_intersect($hCodes, $codes));
        // Prefix match for sub-coded statements: H350i, H360F/D/FD/Fd/Df, H361f/d/fd.
        $hasPrefix = static function (string $prefix) use ($hCodes): bool {
            foreach ($hCodes as $c) {
                if (strncasecmp((string) $c, $prefix, strlen($prefix)) === 0) {
                    return true;
                }
            }
            return false;
        };

        $fragments = [];

        // Sensitisation (Resp. Sens. 1 / Skin Sens. 1)
        if ($has(['H334'])) {
            $fragments[] = $this->t->get('section11.chronic_resp_sens');
        }
        if ($has(['H317'])) {
            $fragments[] = $this->t->get('section11.chronic_skin_sens');
        }

        // Germ cell mutagenicity (Muta. 1A/1B vs 2)
        if ($has(['H340'])) {
            $fragments[] = $this->t->get('section11.chronic_muta_1');
        } elseif ($has(['H341'])) {
            $fragments[] = $this->t->get('section11.chronic_muta_2');
        }

        // Carcinogenicity (Carc. 1A/1B vs 2); cross-reference the
        // Carcinogenicity paragraph that follows. If the carcinogen registry
        // flagged a listing but no H350/H351 survived (e.g. inhalation-only
        // listings stripped by removeInhalationOnlyFromResults), still point
        // the reader at the paragraph below.
        if ($has(['H350']) || $hasPrefix('H350')) {
            $fragments[] = $this->t->get('section11.chronic_carc_1');
        } elseif ($has(['H351'])) {
            $fragments[] = $this->t->get('section11.chronic_carc_2');
        } elseif (!empty($carcinogenResult['has_carcinogens'])) {
            $fragments[] = $this->t->get('section11.chronic_carc_listed');
        }

        // Reproductive toxicity (Repr. 1A/1B vs 2) and lactation
        if ($hasPrefix('H360')) {
            $fragments[] = $this->t->get('section11.chronic_repr_1');
        } elseif ($hasPrefix('H361')) {
            $fragments[] = $this->t->get('section11.chronic_repr_2');
        }
        if ($has(['H362'])) {
            $fragments[] = $this->t->get('section11.chronic_lactation');
        }

        // STOT — repeated exposure (Cat 1 vs Cat 2)
        if ($has(['H372'])) {
            $fragments[] = $this->t->get('section11.chronic_stot_re_1');
        } elseif ($has(['H373'])) {
            $fragments[] = $this->t->get('section11.chronic_stot_re_2');
        }

        // Repeated-contact irritation (Skin Corr./Irrit., Eye Dam./Irrit.)
        if ($has(['H314', 'H315'])) {
            $fragments[] = $this->t->get('section11.chronic_repeated_skin');
        }
        if ($has(['H318', 'H319'])) {
            $fragments[] = $this->t->get('section11.chronic_repeated_eye');
        }

        if (empty($fragments)) {
            return $this->t->get('section11.chronic_none');
        }

        return implode(' ', $fragments);
    }

    /**
     * Section 11 "Acute Toxicity" (audit #20).
     *
     * Derived per route (oral, dermal, inhalation) from the engine's
     * classification so this line can never contradict Section 2 or the
     * Section 4(b) symptoms line: each classified route prints its category,
     * the resolved H-statement and, for an ATE-mixture classification, the
     * calculated ATEmix value. Falls back to the "criteria are not met"
     * constant only when no acute-toxicity route is classified. Admin
     * text_overrides[11]['acute_toxicity'] is applied by the caller.
     */
    private function buildAcuteToxicity(array $hazard): string
    {
        $lang   = $this->t->getLanguage();
        $routes = [
            GHSHazardClass::ACUTE_TOXICITY_ORAL       => 'oral',
            GHSHazardClass::ACUTE_TOXICITY_DERMAL     => 'dermal',
            GHSHazardClass::ACUTE_TOXICITY_INHALATION => 'inhalation',
        ];
        // Default statement per route and category when the class entry
        // carries no h_codes (per-component triggers); GHS Rev. 7 Table 3.1.3.
        $routeCodes = [
            'oral'       => [1 => 'H300', 2 => 'H300', 3 => 'H301', 4 => 'H302'],
            'dermal'     => [1 => 'H310', 2 => 'H310', 3 => 'H311', 4 => 'H312'],
            'inhalation' => [1 => 'H330', 2 => 'H330', 3 => 'H331', 4 => 'H332'],
        ];

        // Most severe category per route, keeping the ATE value when present.
        $byRoute = [];
        foreach ($hazard['hazard_classes'] ?? [] as $hc) {
            $canonical = (string) ($hc['canonical'] ?? '');
            $route     = $routes[$canonical] ?? null;
            if ($route === null) {
                // Entries without a canonical (e.g. finished-good override):
                // match on the display name "Acute Toxicity (Oral)" etc.
                $className = strtolower((string) ($hc['class'] ?? ''));
                if (!str_contains($className, 'acute tox')) {
                    continue;
                }
                foreach (['oral', 'dermal', 'inhalation'] as $r) {
                    if (str_contains($className, $r)) {
                        $route = $r;
                        break;
                    }
                }
                if ($route === null) {
                    continue;
                }
            }
            $catRaw = (string) ($hc['category_canonical'] ?? $hc['category'] ?? '');
            if (!preg_match('/(\d)/', $catRaw, $m)) {
                continue;
            }
            $catNum = (int) $m[1];
            if ($catNum < 1 || $catNum > 5) {
                continue;
            }
            $ate = isset($hc['ate_mix']) && is_numeric($hc['ate_mix']) ? (float) $hc['ate_mix'] : null;
            if (!isset($byRoute[$route]) || $catNum < $byRoute[$route]['cat']) {
                $byRoute[$route] = ['cat' => $catNum, 'ate' => $ate, 'h_codes' => $hc['h_codes'] ?? []];
            } elseif ($catNum === $byRoute[$route]['cat'] && $ate !== null && $byRoute[$route]['ate'] === null) {
                $byRoute[$route]['ate'] = $ate;
            }
        }

        if ($byRoute === []) {
            return $this->t->get('section11.acute_toxicity');
        }

        // Resolved (already translated) statement text keyed by code.
        $hText = [];
        foreach ($hazard['h_statements'] ?? [] as $s) {
            $code = strtoupper(trim((string) ($s['code'] ?? '')));
            $text = trim((string) ($s['text'] ?? ''));
            if ($code !== '' && $text !== '') {
                $hText[$code] = $text;
            }
        }

        $lines = [];
        foreach (['oral', 'dermal', 'inhalation'] as $route) {
            if (!isset($byRoute[$route])) {
                continue;
            }
            $cat  = $byRoute[$route]['cat'];
            $code = '';
            foreach ((array) $byRoute[$route]['h_codes'] as $c) {
                $c = strtoupper(trim((string) $c));
                if (preg_match('/^H3[0-3]\d$/', $c)) {
                    $code = $c;
                    break;
                }
            }
            if ($code === '') {
                $code = $routeCodes[$route][$cat] ?? '';
            }
            $statement = $hText[$code] ?? ($code !== '' ? GHSStatements::hText($code, $lang) : '');
            $statement = rtrim($statement, '.');

            $lines[] = $this->t->get('section11.acute_route_line', [
                'route'     => $this->t->get('section11.acute_route_' . $route),
                'category'  => GHSStatements::categoryName('Category ' . $cat, $lang),
                'statement' => $statement,
                'code'      => $code,
            ]);
            if ($byRoute[$route]['ate'] !== null) {
                $lines[] = $this->t->get('section11.acute_ate', [
                    'value' => rtrim(rtrim(number_format($byRoute[$route]['ate'], 2, '.', ''), '0'), '.'),
                    'unit'  => $this->t->get('section11.acute_unit_' . $route),
                ]);
            }
        }

        return implode(' ', $lines);
    }

    /**
     * First composition row per CAS, for the Section 3 band lookups in
     * Sections 11 and 12 (same map section8() builds inline).
     */
    private static function compositionByCas(array $composition): array
    {
        $compByCas = [];
        foreach ($composition as $c) {
            $cCas = (string) ($c['cas_number'] ?? '');
            if ($cCas !== '' && !isset($compByCas[$cCas])) {
                $compByCas[$cCas] = $c;
            }
        }
        return $compByCas;
    }

    private function section11(array $hazard, array $composition, array $carcinogenResult, array $overrides): array
    {
        // Concentrations print as the SAME prescribed-range band Section 3
        // shows for this CAS (SDS content policy — PRESCRIBED_RANGES). The
        // exact percentage is not carried on the Section 11 copies, so no
        // renderer can print it.
        $compByCas = self::compositionByCas($composition);

        // Build component-level toxicological detail
        $componentTox = [];
        foreach ($composition as $c) {
            $cas  = $c['cas_number'] ?? '';
            $name = $c['chemical_name'] ?? '';
            $conc = (float) ($c['concentration_pct'] ?? 0);
            if ($cas === '' || $conc < CarcinogenService::LISTING_THRESHOLD_PCT) {
                continue;
            }

            $entry = [
                'cas_number'    => $cas,
                'chemical_name' => $name,
                'concentration_range' => $this->formatConcentration($c),
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

        // Carcinogenicity text: manual override wins; otherwise build it in
        // the sheet language from the (already filtered) registry findings.
        // $carcinogenResult['summary_text'] is English base data computed
        // once per FG (computeBase) and is NOT printed.
        $carcinogenText = $overrides[11]['carcinogenicity'] ?? null;
        if ($carcinogenText === null) {
            if (!empty($carcinogenResult['has_carcinogens'])) {
                // Band each finding with the Section 3 range for its CAS so
                // the carcinogenicity line never states the exact percentage.
                $bandedFindings = [];
                foreach ($carcinogenResult['findings'] ?? [] as $f) {
                    $fCas = (string) ($f['cas_number'] ?? '');
                    $f['concentration_range'] = $this->formatConcentration(
                        $compByCas[$fCas] ?? ['concentration_pct' => (float) ($f['concentration_pct'] ?? 0)]
                    );
                    unset($f['concentration_pct']);
                    $bandedFindings[] = $f;
                }
                $carcinogenText = CarcinogenService::buildSummaryText($bandedFindings, $this->t);
            } else {
                // Negative sentence carries the same 0.1 % qualifier as the
                // positive intro: a listed carcinogen below the cut-off is
                // neither disclosed nor classified, so "no components" alone
                // would be untrue for the product.
                $carcinogenText = $this->t->get('section11.carcinogenicity', [
                    'threshold' => (string) CarcinogenService::LISTING_THRESHOLD_PCT,
                ]);
            }
        }

        return [
            'title'              => $this->t->get('section11.title'),
            'acute_toxicity'     => $overrides[11]['acute_toxicity'] ?? $this->buildAcuteToxicity($hazard),
            'chronic_effects'    => $overrides[11]['chronic_effects'] ?? $this->buildChronicEffects($hazard, $carcinogenResult),
            'carcinogenicity'    => $carcinogenText,
            'hazard_classes'     => $hazard['hazard_classes'],
            'component_toxicology' => $componentTox,
            'carcinogen_result'  => $carcinogenResult,
        ];
    }

    private function section12(array $hazardResult, array $composition, array $overrides): array
    {
        $lang = $this->t->getLanguage();

        // Conc% column: the SAME prescribed-range band Section 3 prints for
        // this CAS (SDS content policy — PRESCRIBED_RANGES). Exact
        // percentages are never emitted here, so no renderer can print them.
        $compByCas = self::compositionByCas($composition);

        // Echo the aquatic H-statements exactly as resolved by the engine.
        // h_statements text is already translated for $lang (see
        // GHSStatements::translateHazardResult in generate()/generateFromBase()).
        // GHS Rev. 7 Chapter 4.1: H400-H402 = acute, H410-H413 = chronic.
        $aquaticStatements = [];
        foreach ($hazardResult['h_statements'] ?? [] as $stmt) {
            $code = strtoupper(trim((string) ($stmt['code'] ?? '')));
            if (preg_match('/^H4(0[0-2]|1[0-3])$/', $code)) {
                $aquaticStatements[$code] = trim((string) ($stmt['text'] ?? ''));
            }
        }
        ksort($aquaticStatements);

        // Per-component aquatic classification + M-factor from the engine's
        // Phase 4 aquatic buffer (HazardEngine::classify() 'aquatic_components').
        $componentAquatic = [];
        foreach ($hazardResult['aquatic_components'] ?? [] as $row) {
            $cas  = (string) ($row['cas'] ?? '');
            $name = (string) ($row['name'] ?? '');
            $comp = $compByCas[$cas] ?? null;
            if ($cas === 'TRADE_SECRET' || !empty($comp['is_trade_secret'])) {
                // Section 3 withholds this constituent's identity; do the same
                // here. A manual-JSON trade-secret row reaches the engine at a
                // nominal 100 % (HazardEngine), which is not a printable value.
                $range = $comp !== null ? $this->formatConcentration($comp) : '';
                $cas   = 'TRADE SECRET';
                $name  = (string) (($comp['trade_secret_description'] ?? '') ?: 'Trade Secret');
            } else {
                $range = $this->formatConcentration($comp ?? ['concentration_pct' => (float) ($row['conc'] ?? 0)]);
            }
            $componentAquatic[] = [
                'cas_number'          => $cas,
                'chemical_name'       => $name,
                'concentration_range' => $range,
                'acute'               => $this->formatAquaticCategory($row['acute_category'] ?? null, $row['acute_m_factor'] ?? null, $lang),
                'chronic'             => $this->formatAquaticCategory($row['chronic_category'] ?? null, $row['chronic_m_factor'] ?? null, $lang),
            ];
        }

        // --- Ecotoxicity (audit item #23) ---
        $ecotoxicity = $overrides[12]['ecotoxicity'] ?? null;
        if ($ecotoxicity === null) {
            if (!empty($aquaticStatements)) {
                $parts = [];
                foreach ($aquaticStatements as $code => $text) {
                    $parts[] = $text !== ''
                        ? $code . ': ' . rtrim($text, '.') . '.'
                        : $code . '.';
                }
                // The "summation method ... listed below" lead-in is only true
                // when a component table follows. A finished-good hazard
                // override can add an aquatic H-code with no buffered rows;
                // then state the classification without claiming a method.
                $lead = $componentAquatic !== []
                    ? $this->t->get('section12.ecotoxicity_classified')
                    : $this->t->get('section12.ecotoxicity_classified_no_table');
                $ecotoxicity = $lead . ' '
                    . implode(' ', $parts) . ' '
                    . $this->t->get('section12.environmental_warning');
            } elseif (!empty($componentAquatic)) {
                // Components carry aquatic data but the summation did not
                // reach a mixture classification — say so rather than
                // "No data available", which the table below would contradict.
                $ecotoxicity = $this->t->get('section12.ecotoxicity_not_classified');
            } else {
                $ecotoxicity = $this->t->get('section12.ecotoxicity');
            }
        }

        return [
            'title'             => $this->t->get('section12.title'),
            'ecotoxicity'       => $ecotoxicity,
            'component_aquatic' => $componentAquatic,
            'persistence'       => $overrides[12]['persistence'] ?? $this->t->get('section12.persistence'),
            'bioaccumulation'   => $overrides[12]['bioaccumulation'] ?? $this->t->get('section12.bioaccumulation'),
            // The shared Sections 12-15 footnote is emitted once, on section15().
        ];
    }

    /**
     * Render a canonical aquatic category ('Cat 1' … 'Cat 4') for Section 12
     * in the SDS language, appending the M-factor for Category 1 only
     * (GHS Rev. 7 4.1.3.5.5.5 — M-factors apply to Category 1 substances).
     * Returns '' when the component has no classification on that route.
     */
    private function formatAquaticCategory(?string $canonical, $mFactor, string $lang): string
    {
        $canonical = trim((string) $canonical);
        if ($canonical === '') {
            return '';
        }
        $display = preg_replace('/^Cat\s+/i', 'Category ', $canonical);
        $text = GHSStatements::categoryName($display, $lang);
        if (strcasecmp($canonical, 'Cat 1') === 0 && $mFactor !== null) {
            $m = rtrim(rtrim(number_format((float) $mFactor, 2, '.', ''), '0'), '.');
            $text .= ' (M = ' . $m . ')';
        }
        return $text;
    }

    private function section13(array $hazardResult, array $calcResult, array $overrides): array
    {
        $hCodes = self::extractHCodes($hazardResult);

        // Determine flash point from formula lines
        $flashPoint = null;
        foreach ($calcResult['formula']['lines'] ?? [] as $line) {
            $fp = $line['flash_point_c'] ?? null;
            if ($fp !== null && ($flashPoint === null || (float) $fp < $flashPoint)) {
                $flashPoint = (float) $fp;
            }
        }

        $corrosive     = !empty(array_intersect($hCodes, ['H314']));
        $acuteToxic    = !empty(array_intersect($hCodes, ['H300', 'H301', 'H310', 'H311', 'H330', 'H331']));
        $waterReactive = !empty(array_intersect($hCodes, ['H260', 'H261']));
        $selfReactive  = !empty(array_intersect($hCodes, ['H240', 'H241', 'H242']));
        $aquatic       = !empty(array_intersect($hCodes, ['H400', 'H401', 'H402', 'H410', 'H411', 'H412', 'H413']));
        // EPA ignitable: flash point < 60°C (140°F)
        $ignitable     = $flashPoint !== null && $flashPoint < 60.0;

        // --- Disposal methods smart logic ---
        $methods = $overrides[13]['methods'] ?? null;
        if ($methods === null) {
            // Pick the most relevant disposal guidance (priority order)
            if ($waterReactive || $selfReactive) {
                $methods = $this->t->get('section13.methods_reactive');
            } elseif ($corrosive) {
                $methods = $this->t->get('section13.methods_corrosive');
            } elseif ($acuteToxic) {
                $methods = $this->t->get('section13.methods_toxic');
            } elseif ($ignitable) {
                $methods = $this->t->get('section13.methods_ignitable');
            } elseif ($aquatic) {
                $methods = $this->t->get('section13.methods_aquatic');
            } else {
                $methods = $this->t->get('section13.methods');
            }
        }

        return [
            'title'   => $this->t->get('section13.title'),
            'methods' => $methods,
            // The shared Sections 12-15 footnote is emitted once, on section15().
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
            // The shared Sections 12-15 footnote is emitted once, on section15().
        ];
    }

    private function section15(array $saraResult, array $prop65Result, array $hapResult, array $calcResult, array $overrides): array
    {
        // Build state regulations text with Prop 65 data
        $stateRegs = $overrides[15]['state_regs'] ?? '';
        if ($stateRegs === '' && $prop65Result['requires_warning']) {
            $stateRegs = $prop65Result['warning_text'];
        }

        // SNUR analysis — check formula components against snur_list + manual flags
        $snurResult = $this->analyseSnur($calcResult);

        return [
            'title'          => $this->t->get('section15.title'),
            'osha_status'    => $overrides[15]['osha_status'] ?? $this->t->get('section15.osha_status'),
            'tsca_status'    => $overrides[15]['tsca_status'] ?? $this->t->get('section15.tsca_status'),
            'sara_313'       => $saraResult,
            'prop65'         => $prop65Result,
            'hap'            => $hapResult,
            'snur'           => $snurResult,
            'state_regs'     => $stateRegs,
            'ghs_note'       => $this->ghsSectionNote(),
        ];
    }

    private function section16(array $calcResult, array $overrides): array
    {
        // 'version' / 'effective_date' start as the draft placeholders. Every
        // publisher replaces them through stampPublishedVersion() right before
        // rendering, with the same version number and effective date it writes
        // to sds_versions / private_label_sds (29 CFR 1910.1200 App. D §16:
        // date of preparation or last revision). Nothing else about the
        // revision is printed: no generation timestamp, change summary or
        // formula version.
        return [
            'title'          => $this->t->get('section16.title'),
            'version'        => $this->t->get('section16.draft'),
            'effective_date' => '',
            'abbreviations'  => '', // filled by AbbreviationService::build() once every section exists (audit #33)
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
        if (self::$companySettingsCache !== null) {
            return self::$companySettingsCache;
        }

        $db = Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT `key`, `value` FROM settings WHERE `key` LIKE 'company.%' OR `key` LIKE 'sds.legal_disclaimer.%'"
        );

        // legal_disclaimers: language => text (admin setting sds.legal_disclaimer.<lang>).
        // The legacy single key sds.legal_disclaimer is no longer read; migration 052
        // copied it into sds.legal_disclaimer.en. See resolveLegalDisclaimer().
        $settings = ['legal_disclaimers' => []];
        foreach ($rows as $row) {
            if (str_starts_with($row['key'], 'sds.legal_disclaimer.')) {
                $settings['legal_disclaimers'][substr($row['key'], 21)] = (string) $row['value'];
                continue;
            }
            // Strip the 'company.' prefix for company keys
            $settings[substr($row['key'], 8)] = $row['value'];
        }

        // The settings table is the source of truth for the supplier block.
        // config.php is consulted only for a key the table has never stored
        // (fresh install); a value the admin saved as blank is authoritative,
        // so a cleared website/email is not replaced by a config placeholder.
        // The emergency phone is the exception (audit #2): the publish gate
        // (SDSReadinessService::companyEmergencyPhoneErrorFromDb) accepts
        // only a number saved in Admin > Settings, never the config.php
        // placeholder, so the sheet must not print the placeholder either —
        // otherwise a fresh-install preview shows a number while every
        // publish path reports it as missing.
        $configCompany = App::config('company', []);
        foreach ($configCompany as $k => $v) {
            if ($k === 'emergency_phone') {
                continue;
            }
            if (!array_key_exists($k, $settings)) {
                $settings[$k] = (string) $v;
            }
        }

        self::$companySettingsCache = $settings;
        return $settings;
    }

    /**
     * Legal disclaimer for one language (audit #34):
     *   admin setting sds.legal_disclaimer.<lang>  →  translation file section16.disclaimer.
     * Private-label manufacturer text is layered on top in createManufacturerVariant().
     */
    private function resolveLegalDisclaimer(array $company, string $language): string
    {
        $text = trim((string) ($company['legal_disclaimers'][$language] ?? ''));
        if ($text !== '') {
            return $text;
        }
        return $this->t->get('section16.disclaimer');
    }

    /** @var bool|null Cached 'sds.show_ghs_section_note' setting (audit item #25). */
    private static ?bool $showGhsSectionNote = null;

    /**
     * Shared Sections 12-15 footnote (audit item #25).
     *
     * 29 CFR 1910.1200(g)(2) requires the Section 12-15 headings; OSHA does
     * not enforce their content (EPA/DOT jurisdiction). One sentence, one
     * translation key ('document.ghs_section_note'), one admin toggle.
     * Returns '' when settings.sds.show_ghs_section_note is '0'; a missing
     * row means ON. Renderers print it footnote-style and skip it when ''.
     * The sentence speaks about Sections 12-15 collectively, so only
     * section15() carries 'ghs_note': it prints once, after Section 15.
     */
    private function ghsSectionNote(): string
    {
        if (self::$showGhsSectionNote === null) {
            $row = Database::getInstance()->fetch(
                "SELECT `value` FROM settings WHERE `key` = 'sds.show_ghs_section_note'"
            );
            self::$showGhsSectionNote = !($row && (string) $row['value'] === '0');
        }

        return self::$showGhsSectionNote ? $this->t->get('document.ghs_section_note') : '';
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

    /**
     * SDS content policy — concentration disclosure (audit item #8).
     * Fixed by code, not by settings. Keep this comment and
     * docs/operations.md ("SDS content policy") in step.
     *
     * 1. Disclosure cut-off: 0.1 % w/w of the finished good. A constituent
     *    is listed in Section 3 (and its OELs in Sections 8 and 11) only at
     *    >= 0.1 % (section3()/section11(): `$conc < 0.1`), and only if it is
     *    classified as hazardous or has an occupational exposure limit on
     *    file (29 CFR 1910.1200 Appendix D, Section 3(c)). 0.1 % is the
     *    lowest ingredient cut-off in Appendix A, so nothing that can drive
     *    a classification is hidden. Rows below the cut-off that still
     *    reach Section 8 (OEL at 0.01–0.1 %) print "<0.1%".
     *
     * 2. Prescribed-range bands: exact percentages are never printed. Every
     *    Section 3 row, the Section 8 Conc% column, the Section 11
     *    carcinogenicity line and component block, and the Section 12
     *    component aquatic table (all of which reuse the Section 3 band for
     *    the same CAS via section8()/section11()/section12()) show the WIDEST
     *    range below that fully contains the actual concentration or the
     *    supplier min–max range; if no single range contains it, the widest
     *    range containing the midpoint. The table is the set of prescribed
     *    concentration ranges in 29 CFR 1910.1200(i)(1) as amended by the
     *    May 2024 HazCom final rule (89 FR 44144), identical to Canada's
     *    HPR s. 5.7(1). Section 15 SARA 313 / HAP weight percentages stay
     *    exact on purpose (40 CFR 372.45(b)(2) requires percent by weight).
     */
    private const PRESCRIBED_RANGES = [
        [0.1, 1], [0.5, 1.5], [1, 5], [3, 7], [5, 10], [7, 13],
        [10, 30], [15, 40], [30, 60], [45, 70], [60, 80], [65, 85], [80, 100],
    ];

    /**
     * Format a concentration for Section 3 and Section 8 display.
     * Uses the supplier min/max range when available, otherwise the exact
     * value, and maps it onto PRESCRIBED_RANGES per the policy above.
     * Returns e.g. "10 - 30%" or "<0.1%".
     */
    private function formatConcentration(array $component): string
    {
        $min = $component['concentration_min'] ?? null;
        $max = $component['concentration_max'] ?? null;
        if ($min === null || $max === null) {
            $v = (float) ($component['concentration_pct'] ?? 0);
            $min = $v;
            $max = $v;
        }
        $min = (float) $min;
        $max = (float) $max;

        if ($max < 0.1) {
            return '<0.1%';
        }

        $fmt = fn(float $n): string => rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');

        // Widest prescribed range fully containing the actual range
        $best = null;
        $bestWidth = -1.0;
        foreach (self::PRESCRIBED_RANGES as [$lo, $hi]) {
            if ($lo <= $min && $max <= $hi && ($hi - $lo) > $bestWidth) {
                $best = [$lo, $hi];
                $bestWidth = $hi - $lo;
            }
        }

        // No single range covers it (very wide actual range) — fall back
        // to the widest range containing the midpoint.
        if ($best === null) {
            $mid = ($min + $max) / 2.0;
            foreach (self::PRESCRIBED_RANGES as [$lo, $hi]) {
                if ($lo <= $mid && $mid <= $hi && ($hi - $lo) > $bestWidth) {
                    $best = [$lo, $hi];
                    $bestWidth = $hi - $lo;
                }
            }
        }

        if ($best === null) {
            return $min < 0.1 ? '<0.1%' : '80 - 100%';
        }

        return $fmt((float) $best[0]) . ' - ' . $fmt((float) $best[1]) . '%';
    }

    /**
     * Get translated labels for PDF and preview rendering.
     */
    private function getLabels(): array
    {
        $keys = [
            // Section 1
            'product_identifier', 'product_family', 'recommended_use', 'restrictions',
            'manufacturer_info', 'company', 'address', 'phone', 'emergency', 'email', 'website',
            // Section 2
            'pictograms', 'ghs_classification',
            'physical_hazards', 'health_hazards', 'environmental_hazards',
            'hazard_statements',
            'precautionary_statements', 'ppe_recommendations', 'other_hazards',
            'ppe_wear_eye', 'ppe_wear_gloves', 'ppe_wear_respiratory', 'ppe_wear_skin',
            // Section 3
            'type', 'cas_number', 'chemical_name', 'concentration',
            'hazardous_only_note', 'no_hazardous_note', 'mixture',
            // Section 4
            'inhalation', 'skin_contact', 'eye_contact', 'ingestion', 'symptoms_effects', 'notes_to_physician',
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
            'physical_state', 'color',
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
            'component_ecotox_data', 'aquatic_acute', 'aquatic_chronic', 'm_factor',
            // Section 13
            'disposal_methods',
            // Section 14
            'un_number', 'proper_shipping_name', 'transport_hazard_class', 'packing_group',
            // Section 15
            'osha_status', 'tsca_status', 'sara_313_title',
            'sara_313_statement', 'sara_313_none', 'sara_313_threshold', 'sara_313_pbt',
            'hap_title', 'hap_triggering', 'hap_wt_pct', 'hap_total', 'hap_none',
            'prop65_title', 'prop65_none', 'snur_title', 'state_regulations',
            // Section 16
            'version', 'effective_date', 'revision_note', 'abbreviations', 'disclaimer',
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
     * Build the Phase-5 override descriptor to pass to HazardEngine::classify().
     *
     * Returns null (engine leaves classification alone) when:
     *   - The finished_good row was loaded before migration 041 ran
     *   - hazard_override_mode is 'none' or missing
     *   - hazard_override_json is empty or malformed
     *
     * Otherwise returns a descriptor with:
     *   mode       — 'additive' | 'replace'
     *   hazards    — the decoded override payload
     *   rationale  — free-text justification (logged in the trace)
     *   set_by     — user id of the last editor (logged)
     *   set_at     — datetime of the last edit (logged)
     */
    private function buildFinishedGoodOverride(array $fg): ?array
    {
        $mode = (string) ($fg['hazard_override_mode'] ?? 'none');
        if ($mode === '' || $mode === 'none') {
            return null;
        }

        $rawJson = $fg['hazard_override_json'] ?? null;
        if ($rawJson === null || $rawJson === '') {
            return null;
        }
        $payload = is_array($rawJson) ? $rawJson : json_decode((string) $rawJson, true);
        if (!is_array($payload) || empty($payload)) {
            return null;
        }

        return [
            'mode'      => $mode,
            'hazards'   => $payload,
            'rationale' => $fg['hazard_override_rationale'] ?? null,
            'set_by'    => $fg['hazard_override_set_by']    ?? null,
            'set_at'    => $fg['hazard_override_set_at']    ?? null,
        ];
    }

    /**
     * If mixed with any non-powder material, the Carcinogen Category 2
     * classification is removed (but Carbon Black is still listed in Section 3).
     */
    private static ?array $inhalationOnlyCas = null;

    public static function getInhalationOnlyCas(): array
    {
        if (self::$inhalationOnlyCas !== null) {
            return self::$inhalationOnlyCas;
        }

        $defaults = [
            '1333-86-4'  => 'Carbon Black',
            '13463-67-7' => 'Titanium Dioxide',
        ];

        $db = \SDS\Core\Database::getInstance();
        $row = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'sds.inhalation_only_cas'");
        if (!$row || trim((string) $row['value']) === '') {
            self::$inhalationOnlyCas = $defaults;
            return self::$inhalationOnlyCas;
        }

        $casList = [];
        foreach (explode("\n", $row['value']) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            // Support both "CAS" and legacy "CAS | Name" format
            $cas = trim(explode('|', $line, 2)[0]);
            if ($cas !== '') {
                $casList[] = $cas;
            }
        }

        if (empty($casList)) {
            self::$inhalationOnlyCas = $defaults;
            return self::$inhalationOnlyCas;
        }

        // Resolve names from prop65_list
        $placeholders = implode(',', array_fill(0, count($casList), '?'));
        $p65Rows = $db->fetchAll(
            "SELECT cas_number, chemical_name FROM prop65_list WHERE cas_number IN ({$placeholders})",
            $casList
        );
        $p65Map = [];
        foreach ($p65Rows as $r) {
            $p65Map[$r['cas_number']] = $r['chemical_name'];
        }

        $map = [];
        foreach ($casList as $cas) {
            $map[$cas] = $p65Map[$cas] ?? $cas;
        }

        self::$inhalationOnlyCas = $map;
        return self::$inhalationOnlyCas;
    }

    private function applyCarbonBlackLogic(array &$hazardResult, array $calcResult): void
    {
        // Find which inhalation-only CAS numbers are present in the composition
        $presentCas = [];
        foreach ($calcResult['composition'] as $c) {
            $cas = $c['cas_number'] ?? '';
            if (isset(self::getInhalationOnlyCas()[$cas])) {
                $presentCas[$cas] = self::getInhalationOnlyCas()[$cas];
            }
        }

        if (empty($presentCas)) {
            return;
        }

        $enrichedLines = $calcResult['formula_props']['enriched_lines'] ?? [];
        $hasNonPowder = false;

        foreach ($enrichedLines as $line) {
            $state = strtolower((string) ($line['physical_state'] ?? ''));
            if ($state !== 'powder' && $state !== 'solid') {
                $hasNonPowder = true;
                break;
            }
        }

        $onlyPowders = !$hasNonPowder;

        if ($onlyPowders) {
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

                foreach ($presentCas as $cas => $name) {
                    $hazardResult['hazard_classes'][] = [
                        'class'             => 'Carcinogenicity',
                        'category'          => 'Category 2',
                        'h_codes'           => ['H351'],
                        'cas'               => $cas,
                        'chemical'          => $name,
                        'concentration_pct' => 0,
                        'cutoff_pct'        => 0,
                        'source'            => $name . ' powder logic',
                    ];
                }

                if (!in_array('GHS08', $hazardResult['pictograms'])) {
                    $hazardResult['pictograms'][] = 'GHS08';
                }

                if ($hazardResult['signal_word'] === null) {
                    $hazardResult['signal_word'] = 'Warning';
                }

                // Class-default P-codes for Carcinogenicity Cat 2 (GHS Rev. 7
                // Annex 3): P201, P202, P280, P308+P313, P405, P501.
                $this->appendClassDefaultPStatements($hazardResult, 'Carcinogenicity - Category 2');
            }
        } else {
            // Remove Carcinogen Cat 2 for these CAS numbers if added by HazardEngine
            $hazardResult['h_statements'] = array_values(array_filter(
                $hazardResult['h_statements'],
                function ($stmt) use ($hazardResult, $presentCas) {
                    if (($stmt['code'] ?? '') !== 'H351') {
                        return true;
                    }
                    // Only remove H351 if inhalation-only CAS are the sole source
                    foreach ($hazardResult['hazard_classes'] as $hc) {
                        if (!isset($presentCas[$hc['cas'] ?? ''])
                            && stripos($hc['class'] ?? '', 'Carcinogen') !== false
                            && stripos($hc['category'] ?? '', '2') !== false) {
                            return true;
                        }
                    }
                    return false;
                }
            ));

            $hazardResult['hazard_classes'] = array_values(array_filter(
                $hazardResult['hazard_classes'],
                fn($hc) => !(
                    isset($presentCas[$hc['cas'] ?? ''])
                    && stripos($hc['class'] ?? '', 'Carcinogen') !== false
                )
            ));

            $ghs08Codes = ['H334','H340','H341','H350','H351','H360','H361','H362',
                           'H370','H371','H372','H373','H304','H305'];
            $remainingHCodes = array_map(fn($s) => $s['code'] ?? '', $hazardResult['h_statements']);
            $stillNeedsGHS08 = !empty(array_intersect($ghs08Codes, $remainingHCodes));
            if (!$stillNeedsGHS08) {
                $hazardResult['pictograms'] = array_values(array_filter(
                    $hazardResult['pictograms'],
                    fn($p) => $p !== 'GHS08'
                ));
            }

            // Exposure limits for inhalation-only CAS are dust/respirable
            // limits — with the particulate bound in a wet mixture they do
            // not apply, matching the Prop 65 / carcinogen suppression above.
            $hazardResult['exposure_limits'] = array_values(array_filter(
                $hazardResult['exposure_limits'] ?? [],
                fn($el) => !isset($presentCas[$el['cas_number'] ?? ''])
            ));
        }
    }

    /**
     * Identify CAS numbers that originate exclusively from solid or powder
     * raw materials, when the formula also contains at least one liquid.
     *
     * Returns an empty array when the formula has no liquid component
     * (i.e. the solid/powder-in-liquid rule does not apply).
     *
     * @return string[]  CAS numbers to suppress from carcinogen / exposure / Prop 65 outputs.
     */
    private function getSolidPowderCasInLiquidMixture(array $calcResult): array
    {
        $enrichedLines = $calcResult['formula_props']['enriched_lines'] ?? [];

        if (empty($enrichedLines)) {
            return [];
        }

        // 1. Does the formula contain at least one raw material that is NOT
        //    a solid or a powder? A solid particulate becomes an inhalation
        //    hazard only in dry / airborne form — any liquid, paste, gel,
        //    gas, or unknown-state coincident material traps or disperses
        //    the particulate and drops the inhalation concern.
        $hasNonSolidNonPowder = false;
        foreach ($enrichedLines as $line) {
            $state = strtolower((string) ($line['physical_state'] ?? ''));
            if ($state !== 'solid' && $state !== 'powder') {
                $hasNonSolidNonPowder = true;
                break;
            }
        }

        if (!$hasNonSolidNonPowder) {
            return [];
        }

        // 2. Build a map: CAS → set of physical states from contributing lines.
        //    A CAS is "solid/powder only" when every raw material line that
        //    contains it has a physical_state of Solid or Powder.
        $casStates = []; // cas => ['solid' => true, ...]
        foreach ($enrichedLines as $line) {
            $state = strtolower((string) ($line['physical_state'] ?? ''));
            foreach ($line['constituents'] ?? [] as $constituent) {
                $cas = $constituent['cas_number'] ?? '';
                if ($cas === '') {
                    continue;
                }
                $casStates[$cas][$state] = true;
            }
        }

        $solidPowderCas = [];
        foreach ($casStates as $cas => $states) {
            // All contributing lines must be solid or powder (no liquid, paste, gas, or unknown)
            $allSolidPowder = true;
            foreach (array_keys($states) as $s) {
                if ($s !== 'solid' && $s !== 'powder') {
                    $allSolidPowder = false;
                    break;
                }
            }
            if ($allSolidPowder) {
                $solidPowderCas[] = $cas;
            }
        }

        return $solidPowderCas;
    }

    /**
     * Filter carcinogen findings, exposure limits, and Prop 65 results
     * for solid/powder CAS numbers that are mixed into a liquid formula.
     *
     * When a solid or powder ingredient is mixed with a liquid, inhalation
     * exposure is no longer a concern, so carcinogen listings and exposure
     * controls for that ingredient should not appear on the SDS.
     *
     * Carbon black (1333-86-4) additionally should not appear on Prop 65
     * when mixed with any liquid component.
     */
    private function applySolidPowderLiquidFiltering(
        array &$carcinogenResult,
        array &$hazardResult,
        array &$prop65Result,
        array $calcResult
    ): void {
        // Carbon Black is suppressed from Section 11 (carcinogen findings)
        // and Section 15 (Prop 65) whenever the finished formula contains
        // any non-solid-non-powder component — even when no other CAS
        // qualifies for the generic solid-powder filter. This check runs
        // first so it can fire independently of the early-return below,
        // which only concerns the generic suppression set.
        if ($this->shouldSuppressInhalationOnlyProp65($calcResult)) {
            $this->removeInhalationOnlyFromResults($carcinogenResult, $prop65Result);
        }

        $suppressedCas = $this->getSolidPowderCasInLiquidMixture($calcResult);

        if (empty($suppressedCas)) {
            return;
        }

        $suppressedSet = array_flip($suppressedCas);

        // --- Filter CarcinogenService findings ---
        $carcinogenResult['findings'] = array_values(array_filter(
            $carcinogenResult['findings'],
            fn($f) => !isset($suppressedSet[$f['cas_number']])
        ));

        // Recalculate has_carcinogens, summary_text and component_texts
        // from the filtered findings (single builder in CarcinogenService).
        CarcinogenService::resummarise($carcinogenResult);

        // --- Filter exposure limits from hazard result ---
        $hazardResult['exposure_limits'] = array_values(array_filter(
            $hazardResult['exposure_limits'],
            fn($el) => !isset($suppressedSet[$el['cas_number']])
        ));

        // Inhalation-only Prop 65 + Section 11 suppression ran at the top
        // of this method, independent of $suppressedCas.
    }

    /**
     * Remove inhalation-only CAS numbers (Carbon Black, Titanium Dioxide)
     * from the carcinogen and Prop 65 result arrays. Called when the
     * finished formula contains any non-solid-non-powder component —
     * these substances are only inhalation hazards in dry particulate form.
     */
    private function removeInhalationOnlyFromResults(array &$carcinogenResult, array &$prop65Result): void
    {
        $casSet = self::getInhalationOnlyCas();
        $namePatterns = array_map('strtolower', array_values($casSet));

        // ── Prop 65 ──
        $prop65Result['listed_chemicals'] = array_values(array_filter(
            $prop65Result['listed_chemicals'] ?? [],
            fn($lc) => !isset($casSet[$lc['cas_number'] ?? ''])
        ));
        $prop65Result['cancer_chemicals'] = array_values(array_filter(
            $prop65Result['cancer_chemicals'] ?? [],
            function ($name) use ($namePatterns) {
                $lower = strtolower((string) $name);
                foreach ($namePatterns as $pattern) {
                    if (str_contains($lower, $pattern)) return false;
                }
                return true;
            }
        ));
        $prop65Result['repro_chemicals'] = array_values(array_filter(
            $prop65Result['repro_chemicals'] ?? [],
            function ($name) use ($namePatterns) {
                $lower = strtolower((string) $name);
                foreach ($namePatterns as $pattern) {
                    if (str_contains($lower, $pattern)) return false;
                }
                return true;
            }
        ));
        $prop65Result['requires_warning'] = !empty($prop65Result['cancer_chemicals'])
                                          || !empty($prop65Result['repro_chemicals']);
        $prop65Result['warning_text'] = $prop65Result['requires_warning']
            ? self::rebuildProp65Warning(
                $prop65Result['cancer_chemicals'],
                $prop65Result['repro_chemicals']
            )
            : '';

        // ── Carcinogen findings (Section 11) ──
        $carcinogenResult['findings'] = array_values(array_filter(
            $carcinogenResult['findings'] ?? [],
            fn($f) => !isset($casSet[$f['cas_number'] ?? ''])
        ));
        CarcinogenService::resummarise($carcinogenResult);
    }

    /**
     * True iff the finished formula contains any inhalation-only CAS
     * AND at least one raw-material line is in a non-solid-non-powder
     * state. Used to decide whether to suppress Prop 65 listings for
     * those CAS numbers.
     */
    private function shouldSuppressInhalationOnlyProp65(array $calcResult): bool
    {
        $hasMatch = false;
        foreach (($calcResult['composition'] ?? []) as $c) {
            if (isset(self::getInhalationOnlyCas()[$c['cas_number'] ?? ''])) {
                $hasMatch = true;
                break;
            }
        }
        if (!$hasMatch) {
            return false;
        }

        foreach (($calcResult['formula_props']['enriched_lines'] ?? []) as $line) {
            $state = strtolower((string) ($line['physical_state'] ?? ''));
            if ($state !== 'powder' && $state !== 'solid') {
                return true;
            }
        }
        return false;
    }

    /**
     * Rebuild Prop 65 warning text after filtering chemicals.
     */
    private static function rebuildProp65Warning(array $cancerChems, array $reproChems): string
    {
        $hasCancer = !empty($cancerChems);
        $hasRepro  = !empty($reproChems);

        if ($hasCancer && $hasRepro) {
            return sprintf(
                Prop65Service::WARNING_COMBINED,
                implode(', ', $reproChems),
                implode(', ', $cancerChems)
            );
        }
        if ($hasCancer) {
            return sprintf(Prop65Service::WARNING_CANCER, implode(', ', $cancerChems));
        }
        return sprintf(Prop65Service::WARNING_REPRO, implode(', ', $reproChems));
    }

    /**
     * Append the default P-statements for a GHSHazardData classification key
     * (e.g. 'Carcinogenicity - Category 2') to $hazardResult['p_statements'],
     * skipping any code already present. Keeps GHSHazardData as the single
     * source of truth for class-default P-codes (GHS Rev. 7 Annex 3 /
     * OSHA 2024 HazCom Appendix C) instead of hardcoded lists here.
     */
    private function appendClassDefaultPStatements(array &$hazardResult, string $classificationKey): void
    {
        $pCodes = GHSHazardData::HAZARD_CLASSIFICATIONS[$classificationKey]['p_codes'] ?? [];
        if (empty($pCodes)) {
            return;
        }

        $existing = [];
        foreach ($hazardResult['p_statements'] ?? [] as $s) {
            $existing[(string) ($s['code'] ?? '')] = true;
        }

        foreach ($pCodes as $pCode) {
            if (isset($existing[$pCode])) {
                continue;
            }
            $hazardResult['p_statements'][] = [
                'code' => $pCode,
                'text' => GHSStatements::pText($pCode),
            ];
            $existing[$pCode] = true;
        }
    }

    /**
     * Merge carcinogen registry findings (IARC/NTP/OSHA) into the hazard result.
     *
     * When HazardEngine finds no GHS hazard data for a CAS number but
     * CarcinogenService identifies it as a listed carcinogen, this method
     * derives the appropriate GHS carcinogenicity classification and adds
     * it to Section 2.
     *
     * Mapping:
     *   IARC Group 1, NTP Known, OSHA Listed → Carcinogenicity Cat 1A → H350, Danger
     *   IARC Group 2A                        → Carcinogenicity Cat 1B → H350, Danger
     *   IARC Group 2B, NTP RAHC              → Carcinogenicity Cat 2  → H351, Warning
     */
    private function applyCarcinogenFindings(array &$hazardResult, array $carcinogenResult): void
    {
        if (empty($carcinogenResult['findings'])) {
            return;
        }

        // CAS numbers that already have hazard data (from HazardEngine or CPD)
        $existingCas = [];
        foreach ($hazardResult['hazard_classes'] as $hc) {
            if (!empty($hc['cas'])) {
                $existingCas[$hc['cas']] = true;
            }
        }

        // Carbon Black is handled by applyCarbonBlackLogic — skip here
        $existingCas['1333-86-4'] = true;

        $existingHCodes = array_map(fn($s) => $s['code'] ?? '', $hazardResult['h_statements']);

        foreach ($carcinogenResult['findings'] as $finding) {
            $cas  = $finding['cas_number'];
            $conc = (float) ($finding['concentration_pct'] ?? 0);
            $name = $finding['chemical_name'] ?? '';

            // Skip if below the GHS/HazCom carcinogenicity cutoff (0.1%)
            if ($conc < CarcinogenService::LISTING_THRESHOLD_PCT) {
                continue;
            }

            // Skip if this CAS already has hazard classes from HazardEngine/CPD
            if (isset($existingCas[$cas])) {
                continue;
            }

            // Determine GHS category from the strongest agency classification
            $category = null;
            $hCode    = null;
            $signal   = null;

            foreach ($finding['agencies'] as $a) {
                $agency = strtoupper($a['agency'] ?? '');
                $class  = strtoupper($a['classification'] ?? '');

                if ($agency === 'IARC') {
                    if (str_contains($class, 'GROUP 1') && !str_contains($class, '2')) {
                        // IARC Group 1 → Cat 1A (strongest)
                        $category = 'Cat 1A';
                        $hCode = 'H350';
                        $signal = 'Danger';
                        break; // Can't get stronger
                    } elseif (str_contains($class, '2A')) {
                        $category = $category !== 'Cat 1A' ? 'Cat 1B' : $category;
                        $hCode = $hCode ?? 'H350';
                        $signal = $signal ?? 'Danger';
                    } elseif (str_contains($class, '2B')) {
                        $category = $category ?? 'Cat 2';
                        $hCode = $hCode ?? 'H351';
                        $signal = $signal ?? 'Warning';
                    }
                } elseif ($agency === 'NTP') {
                    if (str_contains($class, 'KNOWN')) {
                        $category = $category !== 'Cat 1A' ? 'Cat 1A' : $category;
                        $hCode = in_array($hCode, ['H350', null]) ? 'H350' : $hCode;
                        $signal = $signal === 'Danger' ? 'Danger' : 'Danger';
                    } elseif (str_contains($class, 'RAHC') || str_contains($class, 'REASONABLY')) {
                        $category = $category ?? 'Cat 2';
                        $hCode = $hCode ?? 'H351';
                        $signal = $signal ?? 'Warning';
                    }
                } elseif ($agency === 'OSHA') {
                    if (str_contains($class, 'LISTED') || str_contains($class, 'REGULATED')) {
                        $category = $category !== 'Cat 1A' ? 'Cat 1A' : $category;
                        $hCode = in_array($hCode, ['H350', null]) ? 'H350' : $hCode;
                        $signal = $signal === 'Danger' ? 'Danger' : 'Danger';
                    }
                }
            }

            if ($category === null) {
                continue;
            }

            // Add hazard class
            $hazardResult['hazard_classes'][] = [
                'class'             => 'Carcinogenicity',
                'category'          => $category,
                'h_codes'           => [$hCode],
                'cas'               => $cas,
                'chemical'          => $name,
                'concentration_pct' => $conc,
                'cutoff_pct'        => CarcinogenService::LISTING_THRESHOLD_PCT,
                'source'            => 'Carcinogen registry',
            ];

            // Add H-statement if not already present
            if (!in_array($hCode, $existingHCodes)) {
                $hazardResult['h_statements'][] = [
                    'code' => $hCode,
                    'text' => GHSStatements::hText($hCode),
                ];
                $existingHCodes[] = $hCode;
            }

            // Add GHS08 pictogram if not already present
            if (!in_array('GHS08', $hazardResult['pictograms'])) {
                $hazardResult['pictograms'][] = 'GHS08';
            }

            // Upgrade signal word if needed
            $curPri = ['Danger' => 2, 'Warning' => 1][$hazardResult['signal_word'] ?? ''] ?? 0;
            $newPri = ['Danger' => 2, 'Warning' => 1][$signal] ?? 0;
            if ($newPri > $curPri) {
                $hazardResult['signal_word'] = $signal;
            }

            // Mark CAS as hazardous
            if (!in_array($cas, $hazardResult['hazardous_cas'])) {
                $hazardResult['hazardous_cas'][] = $cas;
            }

            // Add the class-default P-statements for the derived category
            // (GHS Rev. 7 Annex 3: P201, P202, P280, P308+P313, P405, P501).
            // $category is 'Cat 1A' | 'Cat 1B' | 'Cat 2' -> GHSHazardData key.
            $this->appendClassDefaultPStatements(
                $hazardResult,
                'Carcinogenicity - ' . str_replace('Cat ', 'Category ', $category)
            );
        }

        // Re-derive PPE if we added hazard data
        if (!empty($hazardResult['h_statements']) || !empty($hazardResult['p_statements'])) {
            $hazardResult['ppe_recommendations'] = HazardEngine::derivePPE(
                $hazardResult['h_statements'],
                $hazardResult['p_statements']
            );
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
            "SELECT id, internal_code, prop65_data, prop65_chemical_name, prop65_toxicity_types
             FROM raw_materials
             WHERE id IN ({$placeholders}) AND is_prop65 = 1",
            $rmIds
        );

        $result = [];
        foreach ($rows as $row) {
            $rmPct = $pctByRm[(int) $row['id']] ?? 0;

            // Prefer new JSON column with multiple entries
            $entries = !empty($row['prop65_data']) ? (json_decode($row['prop65_data'], true) ?: []) : [];

            // Backward compat: fall back to legacy single-entry fields
            if (empty($entries) && !empty($row['prop65_chemical_name'])) {
                $entries = [[
                    'chemical_name'  => $row['prop65_chemical_name'],
                    'cas_number'     => '',
                    'toxicity_types' => $row['prop65_toxicity_types'] ?? '',
                ]];
            }

            foreach ($entries as $entry) {
                $chemName   = trim($entry['chemical_name'] ?? '');
                $cas        = trim((string) ($entry['cas_number'] ?? ''));
                $isOverride = !empty($entry['is_override']);
                // Pass rows through even when chemical_name is empty as
                // long as there's a CAS — Prop65Service will derive the
                // name + toxicity from prop65_list when is_override is
                // false. Skipping here on empty name would hide legit
                // public-list entries.
                if ($chemName === '' && $cas === '') {
                    continue;
                }
                $result[] = [
                    'chemical_name'     => $chemName,
                    'cas_number'        => $cas,
                    'concentration_pct' => $rmPct,
                    'toxicity_type'     => array_map('trim', explode(',', $entry['toxicity_types'] ?? '')),
                    'is_trace'          => !empty($entry['is_trace']),
                    'is_override'       => $isOverride,
                    'source'            => 'manual',
                    'raw_material_code' => $row['internal_code'],
                ];
            }
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
    public static function getManualHaps(array $formulaLines): array
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

    /**
     * Analyse SNUR (Significant New Use Rule) applicability for the formula.
     *
     * Checks each CAS number in the composition against the snur_list table
     * (centrally managed SNUR CAS numbers, maintained via Admin > SNUR List).
     *
     * @return array{has_snur: bool, listed_chemicals: array}
     */
    private function analyseSnur(array $calcResult): array
    {
        $db = Database::getInstance();
        $composition = $calcResult['composition'] ?? [];

        if (empty($composition)) {
            return ['has_snur' => false, 'listed_chemicals' => []];
        }

        $casNumbers = array_filter(array_column($composition, 'cas_number'));
        if (empty($casNumbers)) {
            return ['has_snur' => false, 'listed_chemicals' => []];
        }

        // Build CAS-to-concentration map
        $casPctMap = [];
        foreach ($composition as $c) {
            $cas = $c['cas_number'] ?? '';
            if ($cas !== '') {
                $casPctMap[$cas] = (float) ($c['concentration_pct'] ?? 0);
            }
        }

        $listedChemicals = [];

        // 1. Check snur_list table
        $placeholders = implode(',', array_fill(0, count($casNumbers), '?'));
        $snurRows = $db->fetchAll(
            "SELECT cas_number, chemical_name, rule_citation, description
             FROM snur_list
             WHERE cas_number IN ({$placeholders})",
            $casNumbers
        );
        foreach ($snurRows as $row) {
            $listedChemicals[] = [
                'cas_number'        => $row['cas_number'],
                'chemical_name'     => $row['chemical_name'],
                'concentration_pct' => $casPctMap[$row['cas_number']] ?? 0,
                'rule_citation'     => $row['rule_citation'] ?? '',
                'description'       => $row['description'] ?? '',
                'source'            => 'snur_list',
            ];
        }

        return [
            'has_snur'         => !empty($listedChemicals),
            'listed_chemicals' => $listedChemicals,
        ];
    }
}
