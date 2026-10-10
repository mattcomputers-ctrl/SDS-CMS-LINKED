<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\App;
use SDS\Core\Database;
use SDS\Models\FinishedGood;
use SDS\Models\ProductFamily;

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
     * Audit #36: when true, getOverrides() returns [] so generate() /
     * generateFromBase() yield the fully automatic text for every field.
     * The override editor (SDSController::edit/saveEdits) and
     * scripts/cleanup-default-overrides.php use it to learn the computed
     * default. Never set on a preview or publish path.
     */
    private bool $ignoreOverrides = false;

    /** Fluent: `(new SDSGenerator())->ignoreOverrides()->generate($fg, $lang)`. */
    public function ignoreOverrides(bool $ignore = true): self
    {
        $this->ignoreOverrides = $ignore;
        return $this;
    }

    /**
     * #50/#65: UV state of the sheet being built — see setUvState().
     * $uvPack: rule pack on AND resolved family flagged UV/LED.
     * $uvFamily: family flagged UV/LED (pack setting ignored; Section 7
     * storage + Section 10 condition). $uvAcrylates: names for Section 4.
     */
    private bool $uvPack = false;
    private bool $uvFamily = false;
    /** @var array<string,string> */
    private array $uvAcrylates = [];

    /**
     * Audit #57: when not null, getOverrides() returns exactly this map
     * (filtered through TextOverrideService::effective()) instead of reading
     * text_overrides. Used only by the override editor
     * (SDSController::editorSds) and scripts/cleanup-default-overrides.php;
     * callers reset it with withOverrides(null). ignoreOverrides() still wins.
     */
    private ?array $presetOverrides = null;

    /** Fluent: `$gen->withOverrides([9 => ['flash_point' => '...']])->generateFromBase($base, $lang)`. */
    public function withOverrides(?array $overrides): self
    {
        $this->presetOverrides = $overrides === null ? null : TextOverrideService::effective($overrides);
        return $this;
    }

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

        $this->attachFamily($fg); // audit #3: Section 1 defaults + UV flag

        // Run formula calculations
        $calcService = new FormulaCalcService();
        $calcResult  = $calcService->calculate($finishedGoodId);

        // #18(b): one physical state for Sections 6, 8 and 9.
        // #42: preview-only warning when the hard-coded 'Liquid' default is used.
        if (self::physicalStateIsDefault($fg, $calcResult)) {
            $calcResult['warnings'][] = self::PHYSICAL_STATE_DEFAULT_WARNING;
        }
        $fg['physical_state'] = self::resolvePhysicalState($fg, $calcResult);

        // Audit #6: Section 3 "Type:" — the FG's own column, else Substance
        // only for a single-line formula whose RM is marked Substance.
        $substanceMixture = SubstanceMixtureResolver::resolveForFinishedGood(
            $fg['substance_mixture'] ?? null,
            $calcResult['formula']['lines'] ?? []
        );

        // Run hazard classification, passing any Phase-5 finished-good
        // hazard override the user has configured for this FG.
        // Audit #18: inhalation-only CAS (carbon black, TiO2) bound in a
        // non-powder product never reach classify().
        $hazardEngine = (new HazardEngine())->excludeInhalationOnlyCas(self::inhalationOnlyCasToExclude($calcResult));
        $fgOverride   = $this->buildFinishedGoodOverride($fg);
        $hazardResult = $hazardEngine->classify($calcResult['composition'], $fgOverride, self::flammabilityInputs($calcResult, $fg)); // Q3

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

        // RCRA toxicity-characteristic / listed-waste lookup for Section 13 (audit #26)
        $rcraResult = RCRAService::analyse($calcResult['composition']);

        // Solid/powder-in-liquid filtering: suppress carcinogen findings,
        // exposure controls, and Prop 65 (carbon black) for solid/powder
        // ingredients that are mixed with a liquid component.
        // Must run BEFORE applyCarcinogenFindings so suppressed findings
        // are not merged into hazard classifications.
        $this->applySolidPowderLiquidFiltering($carcinogenResult, $hazardResult, $prop65Result, $calcResult);

        // Inhalation-only powder rule (H351) + carcinogen registry merge so
        // Section 2 reflects carcinogenicity when federal GHS data is missing.
        // Skipped in 'replace' override mode (audit #67).
        $this->applyPostClassifyCarcinogenSteps($hazardResult, $calcResult, $carcinogenResult, $fgOverride);

        // Translate GHS data (H/P statements, signal word, pictograms) for target language
        $hazardResult = GHSStatements::translateHazardResult($hazardResult, $language);

        // Load text overrides
        $overrides = $this->getOverrides($finishedGoodId, $language);

        // Q3 (#9/#10/#44(2)): a Section 9 flash point / boiling point edit that
        // would contradict the classification is not printed (operator warning added below).
        $flashPointEditWarning   = $this->vetFlashPointEdit($overrides, $hazardResult, $calcResult, $fg);
        $boilingPointEditWarning = $this->vetBoilingPointEdit($overrides, $hazardResult);

        // Company info from admin settings (DB), with config fallback
        $company = $this->getCompanySettings();

        // UV Acrylate Rule Pack (audit #35, #50): the generic UV text applies
        // when the pack is enabled and the RESOLVED family is flagged UV/LED
        // (decision #3); acrylate detection only supplies the Section 4 names.
        // Sections 4-8 fold their UV fragments into the fields (#65); Section 7
        // storage and Section 10 use the family flag alone.
        $uvWarnings  = [];
        $uvAcrylates = [];
        $isUvProduct = UVAcrylateRulePack::familyIsUv($fg);
        $uvPack      = UVAcrylateRulePack::isApplicable($fg);
        if ($uvPack) {
            $uvAcrylates = UVAcrylateRulePack::detectAcrylates($calcResult['composition']);
            $uvWarnings  = UVAcrylateRulePack::getFormulatorWarnings($uvAcrylates);
        }
        $this->setUvState($uvPack, $isUvProduct, $uvAcrylates);
        // Section 11 keeps a separate note; sensitizer wording only with H317.
        $uvSectionAppend = $uvPack
            ? [11 => UVAcrylateRulePack::section11Note($this->t, in_array('H317', self::extractHCodes($hazardResult), true))]
            : [];

        // Assemble all 16 sections
        $sds = [
            'meta' => [
                'finished_good_id' => $finishedGoodId,
                'product_code'     => $fg['product_code'],
                'description'      => $fg['description'],
                'family'           => $fg['family'],
                'language'         => $language,
                'generated_at'     => gmdate('Y-m-d\TH:i:s\Z'),
                'company_logo_path' => $company['logo_path'] ?? '',
                'labels'           => $this->getLabels(),
                'document'         => $this->getDocumentStrings(),
            ],
            'sections' => [
                1  => $this->section1($fg, $company, $overrides),
                2  => $this->section2($hazardResult, $overrides),
                3  => $this->section3($calcResult['composition'], $hazardResult, $overrides, $substanceMixture),
                4  => $this->section4($hazardResult, $overrides),
                5  => $this->section5($calcResult, $hazardResult, $overrides),
                6  => $this->section6($hazardResult, $fg, $overrides),
                7  => $this->section7($hazardResult, $overrides),
                8  => $this->section8($hazardResult, $calcResult['composition'], $overrides, $fg, $this->uvPack),
                9  => $this->section9($fg, $calcResult, $overrides),
                10 => $this->section10($hazardResult, $overrides, $isUvProduct, $calcResult['composition']),
                11 => $this->section11($hazardResult, $calcResult['composition'], $carcinogenResult, $overrides),
                12 => $this->section12($hazardResult, $calcResult['composition'], $overrides, $saraResult, $substanceMixture),
                13 => $this->section13($hazardResult, $calcResult, $overrides, $rcraResult, $fg),
                14 => $this->section14($fg, $calcResult, $hazardResult, $overrides),
                15 => $this->section15($hazardResult, $saraResult, $prop65Result, $hapResult, $calcResult, $overrides),
                16 => $this->section16($calcResult, $overrides),
            ],
            'hazard_result'       => $hazardResult,
            'voc_result'          => array_diff_key($calcResult['voc'], ['assumptions' => true]), // audit #42: assumptions never stored or printed
            'sara_result'         => ['reportable' => $saraResult['reportable'] ?? []], // audit #42: below_threshold is Section 12's generation-time input only
            'prop65_result'       => array_diff_key($prop65Result, ['listed_chemicals' => true]), // audit #42: Section 15 carries listed_lines; labels read requires_warning/cancer_chemicals/repro_chemicals
            'carcinogen_result'   => $carcinogenResult,
            'hap_result'          => $hapResult,
            'warnings'            => array_merge($calcResult['warnings'], $uvWarnings),
            'legal_disclaimer'    => $this->resolveLegalDisclaimer($company, $language),
        ];

        // Section 11 UV note (#65) and override-link warning (#64)
        foreach ($uvSectionAppend as $secNum => $appendText) {
            if (isset($sds['sections'][$secNum])) {
                // #65: only Section 11 still carries a separate UV note; a
                // non-blank per-product override of it replaces the text.
                $noteOverride = $overrides[$secNum]['uv_acrylate_note'] ?? null;
                $sds['sections'][$secNum]['uv_acrylate_note'] = ($noteOverride !== null && trim((string) $noteOverride) !== '')
                    ? (string) $noteOverride
                    : $appendText;
            }
        }

        // #64: a Section 7 Storage override no longer names the Section 10
        // incompatible materials automatically -> operator warning (never printed).
        $storageWarning = self::storageOverrideWarning($overrides);
        if ($storageWarning !== null) {
            $sds['warnings'][] = $storageWarning;
        }

        // Audit #29: TSCA inventory not verified for every constituent →
        // operator warning (preview Warnings box / publish flash; never printed).
        $tscaWarning = TSCAService::warningText($sds['sections'][15]['tsca'] ?? []);
        if ($tscaWarning !== null) {
            $sds['warnings'][] = $tscaWarning;
        }
        // Finding #27: formula raw materials with incomplete constituent data → operator warning (never printed).
        $compositionWarning = SDSReadinessService::incompleteCompositionWarning($sds['sections'][15]['tsca']['incomplete_raw_materials'] ?? []);
        if ($compositionWarning !== null) {
            $sds['warnings'][] = $compositionWarning;
        }

        // Audit #27: an n.o.s. entry whose technical names (49 CFR 172.203(k))
        // could not be derived → operator warning (never printed; the flag
        // itself never reaches the snapshot). Publishing is not blocked.
        if (!empty($sds['sections'][14]['technical_names_missing'])) {
            $sds['warnings'][] = 'Transport warning: Section 14 prints an n.o.s. proper shipping name without the technical names 49 CFR 172.203(k) requires — none could be derived from the composition (no disclosed constituent carries the primary hazard; e.g. Class 3 from the formula flash point alone, or trade-secret constituents only). Enter them in the Section 14 Proper Shipping Name override. Publishing is not blocked.';
        }
        unset($sds['sections'][14]['technical_names_missing']);

        // #36(3): a Substance sheet with no CAS constituent cannot name the
        // substance → operator warning (never printed; the flag never reaches
        // the snapshot). Publishing is not blocked.
        if (!empty($sds['sections'][3]['identity_missing'])) {
            $sds['warnings'][] = 'Section 3 warning: this sheet is typed Substance but no constituent with a CAS number was found, so Section 3 cannot name the substance (29 CFR 1910.1200 Appendix D, Section 3). Add the CAS constituent on the raw material, or set the product to Mixture. Publishing is not blocked.';
        }
        unset($sds['sections'][3]['identity_missing']);

        // Audit #45: a "Not determined" Section 14 blocks publishing. Every
        // preview (finished good, resale, private label) shows the same
        // reason-coded message in its Warnings box. Operator-only, never printed.
        $transportError = SDSReadinessService::transportNotDeterminedError($sds);
        if ($transportError !== null) {
            $sds['warnings'][] = $transportError;
        }

        // Audit #13 / #36(1), owner decision Q4: trade-secret operator warnings
        // (preview Warnings box; never printed). A trade-secret constituent on the
        // Prop 65 list also blocks every publish path
        // (SDSReadinessService::tradeSecretProp65Error).
        foreach (self::tradeSecretDisclosureWarnings($calcResult['composition'] ?? []) as $tsWarning) {
            $sds['warnings'][] = $tsWarning;
        }
        $tsProp65Error = SDSReadinessService::tradeSecretProp65Error($prop65Result);
        if ($tsProp65Error !== null) {
            $sds['warnings'][] = $tsProp65Error;
        }

        // Findings #8 / #44(2): stored Section 9 temperature edits that are not
        // a temperature are ignored; tell the operator (never printed).
        foreach (self::temperatureOverrideWarnings($overrides) as $tempWarning) {
            $sds['warnings'][] = $tempWarning;
        }

        // Q3: flash point operator warnings (preview Warnings box / publish flash; never printed).
        foreach ($this->flammabilityWarnings($hazardResult, $flashPointEditWarning, $boilingPointEditWarning, $overrides, $calcResult, $fg) as $flamWarning) {
            $sds['warnings'][] = $flamWarning;
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

        $this->attachFamily($fg); // audit #3: Section 1 defaults + UV flag

        $calcService = new FormulaCalcService();
        $calcResult  = $calcService->calculate($finishedGoodId);

        // #18(b): one physical state for Sections 6, 8 and 9.
        // #42: preview-only warning when the hard-coded 'Liquid' default is used.
        if (self::physicalStateIsDefault($fg, $calcResult)) {
            $calcResult['warnings'][] = self::PHYSICAL_STATE_DEFAULT_WARNING;
        }
        $fg['physical_state'] = self::resolvePhysicalState($fg, $calcResult);

        // Audit #6 (language-independent; carried to generateFromBase via $base)
        $substanceMixture = SubstanceMixtureResolver::resolveForFinishedGood(
            $fg['substance_mixture'] ?? null,
            $calcResult['formula']['lines'] ?? []
        );

        $hazardEngine = (new HazardEngine())->excludeInhalationOnlyCas(self::inhalationOnlyCasToExclude($calcResult)); // audit #18
        $fgOverride   = $this->buildFinishedGoodOverride($fg);
        $hazardResult = $hazardEngine->classify($calcResult['composition'], $fgOverride, self::flammabilityInputs($calcResult, $fg)); // Q3

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
        $rcraResult       = RCRAService::analyse($calcResult['composition']); // Section 13 (audit #26)

        // Solid/powder-in-liquid filtering (before merging carcinogen findings)
        $this->applySolidPowderLiquidFiltering($carcinogenResult, $hazardResult, $prop65Result, $calcResult);
        $this->applyPostClassifyCarcinogenSteps($hazardResult, $calcResult, $carcinogenResult, $fgOverride); // audit #67

        $company = $this->getCompanySettings();

        // Audit #35: language-independent part only (detection + UV flag);
        // generateFromBase() builds the translated sentences per language.
        $uvWarnings  = [];
        $uvAcrylates = [];
        $isUvProduct = UVAcrylateRulePack::familyIsUv($fg);
        $uvPack      = UVAcrylateRulePack::isApplicable($fg); // #50: gate for the generic UV text
        if ($uvPack) {
            $uvAcrylates = UVAcrylateRulePack::detectAcrylates($calcResult['composition']);
            $uvWarnings  = UVAcrylateRulePack::getFormulatorWarnings($uvAcrylates);
        }

        return [
            'fg'               => $fg,
            'calcResult'       => $calcResult,
            'hazardResult'     => $hazardResult,
            'saraResult'       => $saraResult,
            'prop65Result'     => $prop65Result,
            'carcinogenResult' => $carcinogenResult,
            'hapResult'        => $hapResult,
            'rcraResult'       => $rcraResult,       // audit #26
            'company'          => $company,
            'uvWarnings'       => $uvWarnings,
            'uvAcrylates'      => $uvAcrylates,
            'isUvProduct'      => $isUvProduct,
            'uvPack'           => $uvPack,           // #50: pack enabled + UV family
            'substanceMixture' => $substanceMixture, // audit #6
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
        // resale RMs carry no FG hazard override; their text overrides are
        // keyed by the raw material (audit #45, getOverrides).
        $rm = $calcResult['formula']['lines'][0] ?? [];
        $rmModel = \SDS\Models\RawMaterial::findById($rawMaterialId);
        $baseCode = AliasResolver::stripPack((string) ($rmModel['internal_code'] ?? ''));
        // Audit #6: resale sheet prints Substance only when the RM itself is marked Substance.
        $substanceMixture = SubstanceMixtureResolver::resolveForRawMaterial($rmModel['substance_mixture'] ?? null);
        $fg = [
            'id'                         => null,
            'product_code'               => $baseCode,
            'description'                => trim((string) ($rmModel['supplier_product_name'] ?? '')) !== '' ? trim((string) $rmModel['supplier_product_name']) : $baseCode,   // #69: blank -> code (identifier prints the code alone)
            'family'                     => null,     // filled by attachFamily() from the RM's family
            'family_id'                  => isset($rmModel['family_id']) ? (int) $rmModel['family_id'] : null,
            'family_source'              => $rmModel['family_source'] ?? null,
            'is_resale'                  => true,     // audit #3: Section 1 falls back to the resale defaults
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
        $this->attachFamily($fg);

        // #18(b): blank RM physical state -> dominant RM (itself) -> 'Liquid'.
        // #42: preview-only warning when the hard-coded 'Liquid' default is used.
        if (self::physicalStateIsDefault($fg, $calcResult)) {
            $calcResult['warnings'][] = self::PHYSICAL_STATE_DEFAULT_WARNING;
        }
        $fg['physical_state'] = self::resolvePhysicalState($fg, $calcResult);

        $hazardEngine = (new HazardEngine())->excludeInhalationOnlyCas(self::inhalationOnlyCasToExclude($calcResult)); // audit #18
        $hazardResult = $hazardEngine->classify($calcResult['composition'], null, self::flammabilityInputs($calcResult, $fg)); // Q3

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
        $rcraResult   = RCRAService::analyse($calcResult['composition']); // Section 13 (audit #26)

        $this->applySolidPowderLiquidFiltering($carcinogenResult, $hazardResult, $prop65Result, $calcResult);
        $this->applyPostClassifyCarcinogenSteps($hazardResult, $calcResult, $carcinogenResult, null); // resale: no FG override

        $company = $this->getCompanySettings();

        // UV acrylate rule pack (audit #35): a resale RM belongs to a family
        // like any other item (decision #3), so the pack runs here as well.
        // Language-independent part only; generateFromBase() translates.
        $uvWarnings  = [];
        $uvAcrylates = [];
        $isUvProduct = UVAcrylateRulePack::familyIsUv($fg);
        $uvPack      = UVAcrylateRulePack::isApplicable($fg); // #50: gate for the generic UV text
        if ($uvPack) {
            $uvAcrylates = UVAcrylateRulePack::detectAcrylates($calcResult['composition']);
            $uvWarnings  = UVAcrylateRulePack::getFormulatorWarnings($uvAcrylates);
        }

        return [
            'fg'               => $fg,
            'calcResult'       => $calcResult,
            'hazardResult'     => $hazardResult,
            'saraResult'       => $saraResult,
            'prop65Result'     => $prop65Result,
            'carcinogenResult' => $carcinogenResult,
            'hapResult'        => $hapResult,
            'rcraResult'       => $rcraResult,       // audit #26
            'company'          => $company,
            'uvWarnings'       => $uvWarnings,
            'uvAcrylates'      => $uvAcrylates,
            'isUvProduct'      => $isUvProduct,
            'uvPack'           => $uvPack,           // #50: pack enabled + UV family
            'substanceMixture' => $substanceMixture, // audit #6
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
        $rcraResult       = $base['rcraResult'] ?? ['components' => [], 'has_matches' => false]; // Section 13 (audit #26); tolerant of hand-built bases
        $company          = $base['company'];
        $uvWarnings       = $base['uvWarnings'] ?? [];
        $uvAcrylates      = $base['uvAcrylates'] ?? [];
        $isUvProduct      = (bool) ($base['isUvProduct'] ?? false);
        $substanceMixture = $base['substanceMixture'] ?? SubstanceMixtureResolver::MIXTURE; // audit #6

        // Audit #35/#50/#65: UV state for the section builders (language-free
        // inputs from computeBase(); each section translates its fragment).
        // A pre-#50 base has no 'uvPack': fall back to the old gate.
        $uvPack = (bool) ($base['uvPack'] ?? ($uvAcrylates !== []));
        $this->setUvState($uvPack, $isUvProduct, $uvAcrylates);
        $uvSectionAppend  = $uvPack
            ? [11 => UVAcrylateRulePack::section11Note($this->t, in_array('H317', self::extractHCodes($hazardResult), true))]
            : [];

        // Language-specific: translate GHS data
        $hazardResult = GHSStatements::translateHazardResult($hazardResult, $language);

        // Language-specific: load text overrides. A resale base (fg id null)
        // reads the rows keyed by its raw material (audit #45).
        $overrides = $this->getOverrides(
            (int) ($fg['id'] ?? 0),
            $language,
            (int) ($base['resale_source']['raw_material_id'] ?? 0)
        );

        // Q3 (#9/#10/#44(2)): a Section 9 flash point / boiling point edit that
        // would contradict the classification is not printed (operator warning added below).
        $flashPointEditWarning   = $this->vetFlashPointEdit($overrides, $hazardResult, $calcResult, $fg);
        $boilingPointEditWarning = $this->vetBoilingPointEdit($overrides, $hazardResult);

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
                'company_logo_path' => $company['logo_path'] ?? '',
                'labels'           => $this->getLabels(),
                'document'         => $this->getDocumentStrings(),
            ],
            'sections' => [
                1  => $this->section1($fg, $company, $overrides),
                2  => $this->section2($hazardResult, $overrides),
                3  => $this->section3($calcResult['composition'], $hazardResult, $overrides, $substanceMixture),
                4  => $this->section4($hazardResult, $overrides),
                5  => $this->section5($calcResult, $hazardResult, $overrides),
                6  => $this->section6($hazardResult, $fg, $overrides),
                7  => $this->section7($hazardResult, $overrides),
                8  => $this->section8($hazardResult, $calcResult['composition'], $overrides, $fg, $this->uvPack),
                9  => $this->section9($fg, $calcResult, $overrides),
                10 => $this->section10($hazardResult, $overrides, $isUvProduct, $calcResult['composition']),
                11 => $this->section11($hazardResult, $calcResult['composition'], $carcinogenResult, $overrides),
                12 => $this->section12($hazardResult, $calcResult['composition'], $overrides, $saraResult, $substanceMixture),
                13 => $this->section13($hazardResult, $calcResult, $overrides, $rcraResult, $fg),
                14 => $this->section14($fg, $calcResult, $hazardResult, $overrides),
                15 => $this->section15($hazardResult, $saraResult, $prop65Result, $hapResult, $calcResult, $overrides),
                16 => $this->section16($calcResult, $overrides),
            ],
            'hazard_result'       => $hazardResult,
            'voc_result'          => array_diff_key($calcResult['voc'], ['assumptions' => true]), // audit #42: assumptions never stored or printed
            'sara_result'         => ['reportable' => $saraResult['reportable'] ?? []], // audit #42: below_threshold is Section 12's generation-time input only
            'prop65_result'       => array_diff_key($prop65Result, ['listed_chemicals' => true]), // audit #42: Section 15 carries listed_lines; labels read requires_warning/cancer_chemicals/repro_chemicals
            'carcinogen_result'   => $carcinogenResult,
            'hap_result'          => $hapResult,
            'warnings'            => array_merge($calcResult['warnings'], $uvWarnings),
            'legal_disclaimer'    => $this->resolveLegalDisclaimer($company, $language),
        ];

        foreach ($uvSectionAppend as $secNum => $appendText) {
            if (isset($sds['sections'][$secNum])) {
                // #65: only Section 11 still carries a separate UV note; a
                // non-blank per-product override of it replaces the text.
                $noteOverride = $overrides[$secNum]['uv_acrylate_note'] ?? null;
                $sds['sections'][$secNum]['uv_acrylate_note'] = ($noteOverride !== null && trim((string) $noteOverride) !== '')
                    ? (string) $noteOverride
                    : $appendText;
            }
        }

        // #64: a Section 7 Storage override no longer names the Section 10
        // incompatible materials automatically -> operator warning (never printed).
        $storageWarning = self::storageOverrideWarning($overrides);
        if ($storageWarning !== null) {
            $sds['warnings'][] = $storageWarning;
        }

        // Audit #29: TSCA inventory not verified for every constituent →
        // operator warning (preview Warnings box / publish flash; never printed).
        $tscaWarning = TSCAService::warningText($sds['sections'][15]['tsca'] ?? []);
        if ($tscaWarning !== null) {
            $sds['warnings'][] = $tscaWarning;
        }
        // Finding #27: formula raw materials with incomplete constituent data → operator warning (never printed).
        $compositionWarning = SDSReadinessService::incompleteCompositionWarning($sds['sections'][15]['tsca']['incomplete_raw_materials'] ?? []);
        if ($compositionWarning !== null) {
            $sds['warnings'][] = $compositionWarning;
        }

        // Audit #27: an n.o.s. entry whose technical names (49 CFR 172.203(k))
        // could not be derived → operator warning (never printed; the flag
        // itself never reaches the snapshot). Publishing is not blocked.
        if (!empty($sds['sections'][14]['technical_names_missing'])) {
            $sds['warnings'][] = 'Transport warning: Section 14 prints an n.o.s. proper shipping name without the technical names 49 CFR 172.203(k) requires — none could be derived from the composition (no disclosed constituent carries the primary hazard; e.g. Class 3 from the formula flash point alone, or trade-secret constituents only). Enter them in the Section 14 Proper Shipping Name override. Publishing is not blocked.';
        }
        unset($sds['sections'][14]['technical_names_missing']);

        // #36(3): a Substance sheet with no CAS constituent cannot name the
        // substance → operator warning (never printed; the flag never reaches
        // the snapshot). Publishing is not blocked.
        if (!empty($sds['sections'][3]['identity_missing'])) {
            $sds['warnings'][] = 'Section 3 warning: this sheet is typed Substance but no constituent with a CAS number was found, so Section 3 cannot name the substance (29 CFR 1910.1200 Appendix D, Section 3). Add the CAS constituent on the raw material, or set the product to Mixture. Publishing is not blocked.';
        }
        unset($sds['sections'][3]['identity_missing']);

        // Audit #45: a "Not determined" Section 14 blocks publishing. Every
        // preview (finished good, resale, private label) shows the same
        // reason-coded message in its Warnings box. Operator-only, never printed.
        $transportError = SDSReadinessService::transportNotDeterminedError($sds);
        if ($transportError !== null) {
            $sds['warnings'][] = $transportError;
        }

        // Audit #13 / #36(1), owner decision Q4: trade-secret operator warnings
        // (preview Warnings box; never printed). A trade-secret constituent on the
        // Prop 65 list also blocks every publish path
        // (SDSReadinessService::tradeSecretProp65Error).
        foreach (self::tradeSecretDisclosureWarnings($calcResult['composition'] ?? []) as $tsWarning) {
            $sds['warnings'][] = $tsWarning;
        }
        $tsProp65Error = SDSReadinessService::tradeSecretProp65Error($prop65Result);
        if ($tsProp65Error !== null) {
            $sds['warnings'][] = $tsProp65Error;
        }

        // Findings #8 / #44(2): stored Section 9 temperature edits that are not
        // a temperature are ignored; tell the operator (never printed).
        foreach (self::temperatureOverrideWarnings($overrides) as $tempWarning) {
            $sds['warnings'][] = $tempWarning;
        }

        // Q3: flash point operator warnings (preview Warnings box / publish flash; never printed).
        foreach ($this->flammabilityWarnings($hazardResult, $flashPointEditWarning, $boilingPointEditWarning, $overrides, $calcResult, $fg) as $flamWarning) {
            $sds['warnings'][] = $flamWarning;
        }

        // Section 16 abbreviations: master table filtered to the terms that
        // actually print on this sheet (audit #33). Runs last on purpose.
        $sds['sections'][16]['abbreviations'] = AbbreviationService::build($sds, $this->t);

        return $sds;
    }

    /** Finding #70: top-level generation payloads never printed (trace kept in sds_generation_trace). */
    private const SNAPSHOT_DROP_TOP = ['hazard_result', 'voc_result', 'carcinogen_result', 'sara_result', 'hap_result'];
    /** Finding #70: exact percentages, at any depth under 'sections'. */
    private const SNAPSHOT_DROP_KEYS = ['concentration_pct', 'concentration_min', 'concentration_max', 'total_hap_pct', 'weight_pct_in_rm', 'pct_in_rm', 'pct_in_formula'];

    /**
     * Finding #70 / audit #42: what sds_versions.snapshot_json and
     * private_label_sds.snapshot_json store. Snapshots are re-rendered
     * (alias variants, send queue, shipped-SDS report, private-label preview),
     * so everything a renderer prints stays. Removed: unprinted generation
     * payloads (hazard_result, voc_result, carcinogen_result, sara_result,
     * hap_result, Section 11 carcinogen_result, the Section 15 TSCA roll-up,
     * meta.generated_at) and every exact percentage. Pure, idempotent; the
     * caller's in-memory array (trace logging, publish gates) is untouched.
     */
    public static function snapshotPayload(array $sdsData): array
    {
        foreach (self::SNAPSHOT_DROP_TOP as $k) {
            unset($sdsData[$k]);
        }
        if (isset($sdsData['meta']) && is_array($sdsData['meta'])) {
            unset($sdsData['meta']['generated_at']);
        }
        if (isset($sdsData['sections']) && is_array($sdsData['sections'])) {
            if (isset($sdsData['sections'][11]) && is_array($sdsData['sections'][11])) {
                unset($sdsData['sections'][11]['carcinogen_result']);
            }
            if (isset($sdsData['sections'][15]) && is_array($sdsData['sections'][15])) {
                unset($sdsData['sections'][15]['tsca']);
            }
            $sdsData['sections'] = self::stripExactPercentages($sdsData['sections']);
        }
        return $sdsData;
    }

    /** json_encode(snapshotPayload()) — use for every snapshot_json write. */
    public static function snapshotJson(array $sdsData): string
    {
        return (string) json_encode(self::snapshotPayload($sdsData), JSON_UNESCAPED_UNICODE);
    }

    private static function stripExactPercentages(array $node): array
    {
        foreach ($node as $k => $v) {
            if (is_string($k) && in_array($k, self::SNAPSHOT_DROP_KEYS, true)) {
                unset($node[$k]);
                continue;
            }
            if (is_array($v)) {
                $node[$k] = self::stripExactPercentages($v);
            }
        }
        return $node;
    }

    /**
     * Create an alias-specific copy of SDS data.
     *
     * Replaces the product code and description in Section 1 and meta
     * while keeping all other sections identical to the parent finished good.
     * $aliasCode is printed verbatim (identifier, footer, PDF Title, file
     * name): alias callers pass AliasResolver::stripPack(customer_code)
     * (owner decision Q9: base code on every alias path); private label
     * passes its resolved code, which is never cut at a hyphen.
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
        $aliasSds['sections'][1]['product_identifier'] = self::productIdentifier($aliasCode, $aliasDescription);

        // Audit #60: the Section 16 abbreviations were filtered against the
        // BASE sheet; the alias code / description can carry terms the base
        // does not print (e.g. "LED"), so refilter against this sheet. DB-free
        // fixtures without a Section 16 are left alone (same guard as
        // createManufacturerVariant()).
        if (isset($aliasSds['sections'][16]) && is_array($aliasSds['sections'][16])) {
            $aliasSds['sections'][16]['abbreviations'] = AbbreviationService::build(
                $aliasSds,
                new TranslationService((string) ($aliasSds['meta']['language'] ?? 'en'))
            );
        }

        return $aliasSds;
    }

    /**
     * Section 1 product identifier (audit #69): "CODE — Description", or the
     * code alone when the description is blank or repeats the code (no
     * dangling "CODE — ").
     */
    public static function productIdentifier(string $code, string $description): string
    {
        $code        = trim($code);
        $description = trim($description);
        if ($description === '' || strcasecmp($description, $code) === 0) {
            return $code;
        }
        return $code . ' — ' . $description;
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
        // Audit #55 / #68 — a blank manufacturer name (a publish gate) and a
        // blank supplier phone (warning only) show in the preview Warnings box.
        foreach ([
            SDSReadinessService::manufacturerNameError($manufacturerInfo),
            SDSReadinessService::manufacturerSupplierPhoneWarning($manufacturerInfo),
        ] as $w) {
            if ($w !== null) {
                $variant['warnings'][] = $w;
            }
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
        // Audit #55: a blank name gives a bare "PL" tag (sanitize_filename('')
        // would return 'unnamed_file'); the publish gate refuses it anyway.
        $mfgNameForTag = substr(trim((string) ($manufacturerInfo['name'] ?? '')), 0, 40);
        $mfgSlug       = $mfgNameForTag !== '' ? sanitize_filename($mfgNameForTag) : '';
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

    /**
     * #33: individual P-codes on the hazard result (combined codes such as
     * "P403+P233" split) — exactly the list Section 2 prints.
     *
     * @return string[]
     */
    private static function resolvedPCodes(array $hazardResult): array
    {
        $codes = [];
        foreach ($hazardResult['p_statements'] ?? [] as $s) {
            $code = is_array($s) ? (string) ($s['code'] ?? '') : (string) $s;
            foreach (explode('+', strtoupper($code)) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $codes[$part] = true;
                }
            }
        }
        return array_keys($codes);
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
            'product_identifier'    => self::productIdentifier((string) $fg['product_code'], (string) ($fg['description'] ?? '')),
            // The product family is internal (Section 1 default text, UV logic);
            // it is never printed on the sheet (Matt, 2026-10-09).
            'recommended_use'       => $this->resolveUseText($fg, $overrides, 'recommended_use'),
            'restrictions'          => $this->resolveUseText($fg, $overrides, 'restrictions'),
            'manufacturer_name'     => $block['manufacturer_name'],
            'manufacturer_address'  => $block['manufacturer_address'],
            'manufacturer_phone'    => $block['manufacturer_phone'],
            'emergency_phone'       => $company['emergency_phone'] ?? '',
            'manufacturer_email'    => $block['manufacturer_email'],
            'manufacturer_website'  => $block['manufacturer_website'],
        ];
    }

    /**
     * Section 1 Recommended Use / Restrictions on Use fallback chain (audit #3):
     *   per-FG text override (text_overrides)
     *   > finished_goods.recommended_use / restrictions_on_use (per-product override)
     *   > product family default for this language — products only; resale sheets skip it (Q15).
     *     A blank language falls through to the translated default below,
     *     never to the family's English text, so an ES/FR/DE sheet never
     *     prints English (#62)
     *   > translation file: section1.<field> for products,
     *     section1.<field>_resale for resale raw-material SDSs.
     * $field is 'recommended_use' | 'restrictions'.
     */
    private function resolveUseText(array $fg, array $overrides, string $field): string
    {
        $text = trim((string) ($overrides[1][$field] ?? ''));
        if ($text !== '') {
            return $text;
        }
        $column = $field === 'restrictions' ? 'restrictions_on_use' : 'recommended_use';
        $text   = trim((string) ($fg[$column] ?? ''));
        if ($text !== '') {
            return $text;
        }
        // Audit #49 / Q15: resale raw-material sheets never take the family's
        // product text (a raw in a UV ink family is not that ink); they keep
        // the resale default below.
        $defaults = !empty($fg['is_resale']) ? [] : ($fg['family_defaults'][$field] ?? []);
        $text     = trim((string) ($defaults[$this->t->getLanguage()] ?? ''));
        if ($text !== '') {
            return $text;
        }
        return $this->t->get('section1.' . $field . (!empty($fg['is_resale']) ? '_resale' : ''));
    }

    /**
     * Audit #3: attach the resolved product family's name, UV/LED flag and
     * per-language Section 1 defaults to a finished-good (or synthesised
     * resale) row, so section1() and the UV gate stay DB-free afterwards.
     * Keys added: family (name, overwritten when a family is resolved),
     * family_is_uv (bool|null), family_defaults
     * (['recommended_use' => [lang => text], 'restrictions' => [lang => text]]).
     * An inactive family is ignored (Q13): it never drives Section 1 text or the UV flag.
     */
    private function attachFamily(array &$fg): void
    {
        $familyId = (int) ($fg['family_id'] ?? 0);
        $family   = $familyId > 0 ? ProductFamily::findById($familyId) : null;
        $fg       = array_merge($fg, self::familyFields($family, $fg['family'] ?? null));
    }

    /**
     * Pure (audit #61 / Q13): the keys attachFamily() adds for a
     * product_families row. A missing or INACTIVE family contributes nothing:
     * no Section 1 default text and no UV flag (family_is_uv null); the legacy
     * name column is left as it was.
     *
     * @return array{family:mixed,family_is_uv:?bool,family_defaults:array}
     */
    public static function familyFields(?array $family, $currentName = null): array
    {
        $out = [
            'family'          => $currentName,
            'family_is_uv'    => null,
            'family_defaults' => ['recommended_use' => [], 'restrictions' => []],
        ];
        if ($family === null || (int) ($family['is_active'] ?? 1) !== 1) {
            return $out;
        }
        $out['family']          = $family['name'];
        $out['family_is_uv']    = (int) ($family['is_uv'] ?? 0) === 1;
        $out['family_defaults'] = [
            'recommended_use' => ProductFamily::decodeLangJson($family['recommended_use_json'] ?? null),
            'restrictions'    => ProductFamily::decodeLangJson($family['restrictions_json'] ?? null),
        ];
        return $out;
    }

    /**
     * #50/#65: UV state of the sheet being built. generate() and
     * generateFromBase() call this before the sections are assembled;
     * DB-free tests call it through reflection.
     *
     * @param bool  $pack      UVAcrylateRulePack::isApplicable() (switch on + UV family)
     * @param bool  $family    UVAcrylateRulePack::familyIsUv() (switch ignored)
     * @param array $acrylates UVAcrylateRulePack::detectAcrylates() (names only)
     */
    private function setUvState(bool $pack, bool $family, array $acrylates): void
    {
        $this->uvPack      = $pack;
        $this->uvFamily    = $family;
        $this->uvAcrylates = $pack ? $acrylates : [];
    }

    /**
     * #64: preview-only operator warning when the Section 7 Storage text is a
     * per-product override (it then no longer repeats the Section 10
     * incompatible materials). Null when Storage is automatic.
     */
    private static function storageOverrideWarning(array $overrides): ?string
    {
        $storage = $overrides[7]['storage'] ?? null;
        if ($storage === null || trim((string) $storage) === '') {
            return null;
        }
        return 'Section 7 warning: the Storage text is a per-product override, so it no longer names the Section 10 incompatible materials automatically. Check that the override lists them, or reset Storage to automatic.';
    }

    /**
     * #64: the Section 10 override embedded after "(see Section 10): " in
     * Section 7 starts lower-case like the generated list. Untouched: German
     * sheets (nouns are capitalised) and a first word whose remaining letters
     * are not all lower-case (PVC, NaOH, EPDM).
     */
    private static function inlineCase(string $text, string $lang): string
    {
        if ($text === '' || $lang === 'de') {
            return $text;
        }
        $first = (string) (preg_split('/[\s,;:()]+/u', $text, 2)[0] ?? '');
        $rest  = mb_substr($first, 1);
        if ($rest !== mb_strtolower($rest)) {
            return $text;
        }
        return mb_strtolower(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    /**
     * HazCom classification outcome for the whole product, from the final
     * (post carbon-black / carcinogen-registry / FG-override, translated)
     * hazard result. Sections 2 and 15 both read this so the "Not a
     * hazardous substance or mixture." line and the Section 15 OSHA status
     * sentence can never disagree (audit #28). Exposure limits alone do not
     * classify a product under 29 CFR 1910.1200 App. A, so they are not
     * consulted here.
     */
    private static function isClassified(array $hazard): bool
    {
        return !empty($hazard['signal_word'])
            || !empty($hazard['pictograms'])
            || !empty($hazard['hazard_classes'])
            || !empty($hazard['h_statements']);
    }

    /**
     * Renderer predicate for a stored Section 2 payload (audit finding #63).
     * The stored is_classified wins. Snapshots published before 178cbd0 have
     * no is_classified key; for those the same test isClassified() applies to
     * the hazard result is applied to the payload's own signal_word,
     * pictograms, hazard_classes and h_statements, so a re-render never puts
     * "Not a hazardous substance or mixture." above a classified product's
     * hazards. PDFService::renderSection2(), sds/preview.php and
     * AbbreviationService all read this one predicate.
     */
    public static function section2IsClassified(array $s2): bool
    {
        if (array_key_exists('is_classified', $s2)) {
            return !empty($s2['is_classified']);
        }
        return self::isClassified($s2);
    }

    private function section2(array $hazard, array $overrides): array
    {
        // PPE: the same resolved values Section 8 prints (operator override,
        // else the translated sentence for the H-code-derived tier — see
        // resolvePPE()). Section 2 only shows the hazard-driven fields; the
        // 'general' / 'none' baselines stay in Section 8, so an unclassified
        // product gets no PPE pictograms here.
        $resolved = $this->resolvePPE($hazard, $overrides, $this->uvPack); // #29: same UV-supplemented text as Section 8
        $ppe = [];
        foreach (HazardEngine::PPE_FIELDS as $field) {
            $ppe[$field] = $resolved['hazard_driven'][$field] ? $resolved['text'][$field] : null;
        }

        $customOtherHazards = $overrides[2]['other_hazards'] ?? null;

        $isClassified = self::isClassified($hazard);

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
        ];
    }

    private function section3(
        array $composition,
        array $hazardResult,
        array $overrides,
        string $substanceMixture = SubstanceMixtureResolver::MIXTURE
    ): array {
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

        // Per-CAS H-codes for the composition table (#37). HazardEngine records
        // them BEFORE consolidating to one entry per hazard class
        // ($hazardResult['cas_h_codes']), so a second CAS in the same class keeps
        // its code. TransportClassifier::casHCodeMap() unions that with the final
        // hazard_classes (mixture entries credited to their contributors;
        // FG_OVERRIDE / TRADE_SECRET unattributed; carcinogen-registry and
        // powder entries added after the engine). Section 14 reads the same map.
        $casToHCodes = [];
        foreach (TransportClassifier::casHCodeMap($hazardResult) as $mapCas => $mapCodes) {
            $casToHCodes[(string) $mapCas] = array_fill_keys($mapCodes, true);
        }
        // Inhalation-only CAS (carbon black, TiO2): applyCarbonBlackLogic() strips
        // their carcinogen entries from a wet mixture after the engine ran, so
        // they keep only what the final hazard_classes still attribute to them.
        if ($casToHCodes !== []) {
            $finalAttribution = TransportClassifier::casHCodeMap(['hazard_classes' => $hazardResult['hazard_classes'] ?? []]);
            foreach (array_keys(self::getInhalationOnlyCas()) as $inhCas) {
                $inhCas = (string) $inhCas;
                if (isset($casToHCodes[$inhCas])) {
                    $casToHCodes[$inhCas] = array_fill_keys($finalAttribution[$inhCas] ?? [], true);
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

        // #36(3): a Substance sheet always names the substance (App. D 3(a)):
        // its largest constituent is listed even when unclassified, without an
        // OEL or below the 0.1 % cut-off. Other constituents follow the normal
        // rules (classified impurities / additives). Trade-secret masking
        // still applies to the identity row.
        $isSubstance = $substanceMixture === SubstanceMixtureResolver::SUBSTANCE;
        $identityCas = null;
        if ($isSubstance) {
            $identityPct = -1.0;
            foreach ($composition as $idRow) {
                $idCas = (string) ($idRow['cas_number'] ?? '');
                $idPct = (float) ($idRow['concentration_pct'] ?? 0);
                if ($idCas !== '' && $idPct > $identityPct) {
                    $identityCas = $idCas;
                    $identityPct = $idPct;
                }
            }
        }

        foreach ($composition as $c) {
            $cas  = $c['cas_number'] ?? '';
            $conc = (float) ($c['concentration_pct'] ?? 0);
            $isIdentity = $identityCas !== null && (string) $cas === $identityCas; // #36(3)

            // Must be disclosable and at/above the 0.1 % w/w disclosure
            // cut-off. 0.1 % is fixed policy, not a setting — see the
            // PRESCRIBED_RANGES docblock and docs/operations.md "SDS content
            // policy". It is the lowest ingredient cut-off in 29 CFR
            // 1910.1200 Appendix A (carcinogens, reproductive toxicants,
            // germ cell mutagens cat. 1, respiratory sensitisers), so no
            // constituent that can drive a classification is ever hidden.
            // Disclosable = classified as hazardous OR has an exposure limit.
            if ($cas === '' || ($conc < 0.1 && !$isIdentity)) {
                continue;
            }
            if (!$isIdentity && !isset($hazardousCas[$cas]) && !isset($casWithExposureLimit[$cas])) {
                continue;
            }

            // Airborne/unbound particles override: after wet-mixture
            // suppression, a listed CAS with no attributed H-codes and no
            // exposure limit triggers nothing on this SDS — omit it from
            // the composition table entirely. All-powder products keep
            // their H351 attribution, so they still list it.
            if (!$isIdentity
                && isset(self::getInhalationOnlyCas()[$cas])
                && empty($casToHCodes[$cas])
                && !isset($casWithExposureLimit[$cas])) {
                continue;
            }

            // Trade secret items: group by description and merge
            if (!empty($c['is_trade_secret'])) {
                $desc = $c['trade_secret_description'] ?? '';
                if (!isset($tradeSecretBuckets[$desc])) {
                    $tradeSecretBuckets[$desc] = [
                        'cas_number'        => $this->t->get('labels.trade_secret_cas'),
                        'chemical_name'     => $this->tradeSecretName($desc),
                        'concentration_pct' => 0.0,
                        'concentration_min' => null,
                        'concentration_max' => null,
                        'h_codes'           => [],
                    ];
                }
                $tradeSecretBuckets[$desc]['concentration_pct'] += $conc;
                if (isset($c['concentration_min']) && isset($c['concentration_max'])) {
                    $tradeSecretBuckets[$desc]['concentration_min'] =
                        ($tradeSecretBuckets[$desc]['concentration_min'] ?? 0) + $c['concentration_min'];
                    $tradeSecretBuckets[$desc]['concentration_max'] =
                        ($tradeSecretBuckets[$desc]['concentration_max'] ?? 0) + $c['concentration_max'];
                }
                // Audit #36(1): a CAS-bearing trade-secret row lists its OWN H-codes
                // (same attribution as a disclosed row); only the TRADE_SECRET
                // bucket carries the declared trade-secret union.
                $rowCodes = $cas === 'TRADE_SECRET' ? $tradeSecretHCodes : ($casToHCodes[$cas] ?? []);
                foreach (array_keys($rowCodes) as $code) {
                    $tradeSecretBuckets[$desc]['h_codes'][$code] = true;
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
                'cas_number'          => $bucket['cas_number'],
                'chemical_name'       => $bucket['chemical_name'],
                'concentration_pct'   => round($bucket['concentration_pct'], 4),
                'concentration_range' => $this->formatConcentration($bucket),
                'h_codes'             => array_keys($bucket['h_codes']),
            ];
        }

        // Sort by concentration descending
        usort($disclosed, fn($a, $b) => $b['concentration_pct'] <=> $a['concentration_pct']);

        return [
            'title'                => $this->t->get('section3.title'),
            // Audit #6: resolved by SubstanceMixtureResolver (FG/RM column, or a
            // single-line formula of a Substance RM); default Mixture. The
            // machine key is kept beside the printed label for renderers/tests.
            'substance_mixture'    => $substanceMixture,
            'substance_or_mixture' => $this->t->get(
                $substanceMixture === SubstanceMixtureResolver::SUBSTANCE ? 'labels.substance' : 'labels.mixture'
            ),
            // #36(3) / #37: the hazardous-only note and the empty-list note
            // describe a MIXTURE's ingredient list; not printed on a Substance
            // sheet. Renderers treat a missing key as true (older snapshots).
            'mixture_notes'        => !$isSubstance,
            // #37: printed in place of labels.no_hazardous_note when no row is
            // listed: a classified product says where its classification comes
            // from, so Section 3 never reads as contradicting Section 2.
            'empty_note'           => (empty($disclosed) && !$isSubstance)
                ? (self::isClassified($hazardResult)
                    ? $this->t->get('section3.classified_no_ingredients_note')
                    : $this->t->get('labels.no_hazardous_note'))
                : null,
            // #36(3): Substance sheet with no CAS constituent. Not printed;
            // generate()/generateFromBase() turn it into an operator warning and drop it.
            'identity_missing'     => $isSubstance && empty($disclosed),
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
        //     H317/H319/H332/H334/H335/H336) add route-specific advice. The
        //     same route's toxicity sentence is still appended after a
        //     corrosive or aspiration paragraph (#31, decision #10).
        //   - UV rule-pack products append the uncured-product skin sentence
        //     (#65; names from UVAcrylateRulePack::detectAcrylates()).
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
                if ($has(['H310', 'H311'])) {
                    $skin .= ' ' . $this->t->get('section4.poison_center_immediate'); // #31
                } elseif ($has(['H312'])) {
                    $skin .= ' ' . $this->t->get('section4.skin_harmful');            // #31
                }
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
            if ($this->uvPack) {
                $skin .= ' ' . UVAcrylateRulePack::section4SkinFragment($this->uvAcrylates, $this->t); // #65
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
            // #30: H305 (Asp. Tox. 2) is not adopted by HazCom 2024; only H304
            // selects the aspiration paragraph.
            if ($has(['H304'])) {
                $ingestion = $this->t->get('section4.ingestion_aspiration');
                if ($has(['H300', 'H301'])) {
                    $ingestion .= ' ' . $this->t->get('section4.poison_center_immediate'); // #31
                } elseif ($has(['H302'])) {
                    $ingestion .= ' ' . $this->t->get('section4.ingestion_harmful');       // #31
                }
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

        // 4(c) Notes to physician (audit #9): hazard-specific fragments are
        // printed BEFORE the default sentence so the treatment-critical advice
        // leads; the default ("Treat symptomatically. Show this SDS...") always
        // closes the field. Fragment order: aspiration (H304) -> corrosive
        // burns (H314) or, without H314, serious eye damage (H318, #30) ->
        // delayed inhalation effects (H330/H331). A per-FG override replaces
        // the whole field.
        $notes = $overrides[4]['notes'] ?? null;
        if ($notes === null) {
            $noteParts = [];
            if ($has(['H304'])) {
                $noteParts[] = $this->t->get('section4.notes_aspiration');
            }
            if ($has(['H314'])) {
                $noteParts[] = $this->t->get('section4.notes_corrosive');
            } elseif ($has(['H318'])) {
                $noteParts[] = $this->t->get('section4.notes_eye_damage'); // #30
            }
            if ($has(['H330', 'H331'])) {
                $noteParts[] = $this->t->get('section4.notes_inhalation_delayed');
            }
            $noteParts[] = $this->t->get('section4.notes');
            $notes = implode(' ', $noteParts);
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
            if ($text === '' && preg_match('/^H\d{3}[A-Z]+$/', $code) === 1) {
                // #64: sub-coded statements (H360D, H360FD, H350I) use the base code's text.
                $text = GHSStatements::hText(substr($code, 0, 4), $this->t->getLanguage());
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

    /**
     * Flash point display string shared by Sections 5 and 9 (audit #11):
     * the recursive formula_props value (Q1: wt%-weighted average of the raw
     * materials that carry a flash point, "> " when any of them is flagged),
     * formatted "38 °C (100.4 °F)" or "> 93 °C (199.4 °F)". Null when no
     * raw material in the formula carries a flash point.
     */
    private static function flashPointDisplay(array $props): ?string
    {
        $fpC = $props['flash_point_c'] ?? null;
        if ($fpC === null || $fpC === '') {
            return null;
        }
        $fpC    = (float) $fpC;
        $fpF    = round($fpC * 9 / 5 + 32, 1);
        $prefix = !empty($props['flash_point_greater_than']) ? '> ' : '';
        return "{$prefix}{$fpC} °C ({$fpF} °F)";
    }

    /**
     * #11: the ONE flash point string Sections 5 and 9 both print — the
     * per-product Section 9 override when one is stored, otherwise the
     * recursive formula_props value.
     * #8: an edit that is not a temperature (TemperatureParser) is ignored.
     * Null when neither exists.
     */
    private static function resolveFlashPointDisplay(array $props, array $overrides): ?string
    {
        $override = $overrides[9]['flash_point'] ?? null;
        // #8: only an edit that reads as a temperature is printed, so the
        // printed value is always the one Sections 13 and 14 classify from.
        if ($override !== null && TemperatureParser::parse((string) $override) !== null) {
            return (string) $override;
        }
        return self::flashPointDisplay($props);
    }

    /**
     * Initial boiling point display string for Section 9 (audit #16): the
     * recursive formula_props value (lowest boiling point among the raw
     * materials in the expanded composition that carry one; weight-
     * independent), formatted "78.4 °C (173.1 °F)" like the flash point.
     * Null when no raw material in the formula carries a boiling point.
     */
    private static function boilingPointDisplay(array $props): ?string
    {
        $bpC = $props['boiling_point_c'] ?? null;
        if ($bpC === null || $bpC === '') {
            return null;
        }
        $bpC = (float) $bpC;
        $bpF = round($bpC * 9 / 5 + 32, 1);
        return "{$bpC} °C ({$bpF} °F)";
    }

    /**
     * #16: the Section 9 initial boiling point — the per-product override
     * when a non-blank one is stored, otherwise the derived formula_props
     * value. Null when neither exists (Section 9 prints labels.not_determined).
     */
    private static function resolveBoilingPointDisplay(array $props, array $overrides): ?string
    {
        $override = $overrides[9]['boiling_point'] ?? null;
        // #44(2): parsed like the flash point; a non-temperature edit is ignored.
        if ($override !== null && TemperatureParser::parse((string) $override) !== null) {
            return (string) $override;
        }
        return self::boilingPointDisplay($props);
    }

    /**
     * #44(2) / Q3: the numeric initial boiling point of the Section 9 edit
     * (only vetBoilingPointEdit() reads it since Q3: Section 14 and the engine
     * classify from formula_props) — the Section 9 edit when it reads as a
     * temperature (an "< n" edit just below n), else formula_props.boiling_point_c
     * (lowest raw, #16). Null when neither exists.
     */
    private static function resolveBoilingPointNumeric(array $props, array $overrides): ?float
    {
        $ov = self::parsedTemperatureOverride($overrides, 'boiling_point');
        if ($ov !== null) {
            return TemperatureParser::thresholdValue($ov);
        }
        $bp = $props['boiling_point_c'] ?? null;
        return ($bp !== null && $bp !== '') ? (float) $bp : null;
    }

    /** #8 / #44(2): a Section 9 temperature edit that parses, else null (blank / absent / not a temperature). */
    private static function parsedTemperatureOverride(array $overrides, string $key): ?array
    {
        $raw = trim((string) ($overrides[9][$key] ?? ''));
        return $raw === '' ? null : TemperatureParser::parse($raw);
    }

    /**
     * #8 / #44(2): stored Section 9 Flash Point / Initial Boiling Point edits
     * that are not a temperature are neither printed nor classified from.
     * Operator warnings only (preview Warnings box); never printed.
     *
     * @return string[]
     */
    private static function temperatureOverrideWarnings(array $overrides): array
    {
        $out = [];
        foreach (['flash_point' => 'Flash Point', 'boiling_point' => 'Initial Boiling Point'] as $key => $label) {
            $raw = trim((string) ($overrides[9][$key] ?? ''));
            if ($raw !== '' && TemperatureParser::parse($raw) === null) {
                $out[] = 'Section 9 ' . $label . ' edit "' . $raw . '" is not a temperature (a number with °C or °F, e.g. "24 °C" or "75 °F"), so it is ignored: the sheet prints and classifies from the raw-material data instead. Correct it or reset it to automatic in SDS > Edit Text.';
            }
        }
        return $out;
    }

    /**
     * #35: an operator's Section 5 Specific Hazards edit must not freeze the
     * flash point. The "Flash point: …." sentence (section5.flash_point_line,
     * found by its translated lead-in, case-insensitive, within one line) is
     * replaced in place by the current one; an edit without it gets the
     * current sentence appended. $fpLine null (not flammable, or no flash
     * point) removes a stale sentence. Blank text is returned unchanged.
     */
    private function refreshFlashPointSentence(string $text, ?string $fpLine): string
    {
        if (trim($text) === '') {
            return $text;
        }
        $marker = '@@FP@@';
        $tpl    = $this->t->get('section5.flash_point_line', ['fp' => $marker]);
        $pos    = strpos($tpl, $marker);
        $lead   = $pos === false ? '' : rtrim(substr($tpl, 0, $pos));
        $tail   = $pos === false ? '' : substr($tpl, $pos + strlen($marker));
        $found  = false;
        if ($lead !== '') {
            $re  = '/' . preg_quote($lead, '/') . '\s*.*?' . preg_quote($tail, '/') . '(?=\s|$)/iu';
            $out = preg_replace_callback($re, static function () use (&$found, $fpLine): string {
                $first = !$found;
                $found = true;
                return $first ? (string) $fpLine : '';
            }, $text);
            if (is_string($out)) {
                $text = $out;
            }
        }
        if (!$found && $fpLine !== null) {
            $text = rtrim($text) . ' ' . $fpLine;
        }
        $out = preg_replace('/[ \t]{2,}/u', ' ', $text);
        return trim(is_string($out) ? $out : $text);
    }

    private function section5(array $calcResult, array $hazardResult, array $overrides): array
    {
        // #11: the same flash point Section 9 prints (Section 9 override first,
        // then formula_props: sub-FG components walked, ">" flag honoured) —
        // never the direct-line scan.
        $fpDisplay = self::resolveFlashPointDisplay($calcResult['formula_props'] ?? [], $overrides);

        $hCodes = self::extractHCodes($hazardResult);

        // Flammable Liquids category from the engine's resolved H-codes
        // (HazCom 2024 / GHS Rev. 7): H224 = Cat 1, H225 = Cat 2, H226 = Cat 3,
        // H227 = Cat 4 (combustible liquid). The most severe category present wins.
        $flamCat = 0;
        if (in_array('H224', $hCodes, true)) {
            $flamCat = 1;
        } elseif (in_array('H225', $hCodes, true)) {
            $flamCat = 2;
        } elseif (in_array('H226', $hCodes, true)) {
            $flamCat = 3;
        } elseif (in_array('H227', $hCodes, true)) {
            $flamCat = 4;
        }
        $flammable = $flamCat > 0;

        $waterReactiveH260 = in_array('H260', $hCodes, true);
        $waterReactive     = $waterReactiveH260 || in_array('H261', $hCodes, true);
        $oxidizer          = !empty(array_intersect($hCodes, ['H271', 'H272']));
        $organicPeroxide   = !empty(array_intersect($hCodes, ['H240', 'H241', 'H242']));
        $explosive         = !empty(array_intersect($hCodes, ['H200', 'H201', 'H202', 'H203', 'H204', 'H205']));

        // --- Suitable media: water-reactive > oxidizer > flammable > default ---
        // A water-reactive product must never list water spray or foam (#11).
        $suitableMedia = $overrides[5]['suitable_media'] ?? null;
        if ($suitableMedia === null) {
            if ($waterReactive) {
                $suitableMedia = $this->t->get('section5.suitable_water_reactive');
            } elseif ($oxidizer) {
                $suitableMedia = $this->t->get('section5.suitable_oxidizer');
            } elseif ($flammable) {
                $suitableMedia = $this->t->get('section5.suitable_flammable');
            } else {
                $suitableMedia = $this->t->get('section5.suitable_media');
            }
        }

        // --- Unsuitable media: water-reactive > flammable > default ---
        $unsuitableMedia = $overrides[5]['unsuitable_media'] ?? null;
        if ($unsuitableMedia === null) {
            if ($waterReactive) {
                $unsuitableMedia = $this->t->get('section5.unsuitable_water_reactive');
            } elseif ($flammable) {
                $unsuitableMedia = $this->t->get('section5.unsuitable_flammable');
            } else {
                $unsuitableMedia = $this->t->get('section5.unsuitable_media');
            }
        }

        // --- Specific hazards: additive fragments, flammability first ---
        // [flammable category sentence] [flash point line] [water-reactive]
        // [oxidizer | organic peroxide | default combustion sentence]
        // #35: an operator edit keeps its wording, but its "Flash point: …"
        // sentence is refreshed from Section 9 every time it is generated.
        $fpLine = ($flammable && $fpDisplay !== null)
            ? $this->t->get('section5.flash_point_line', ['fp' => $fpDisplay])
            : null;
        $specificHazards = $overrides[5]['specific_hazards'] ?? null;
        if ($specificHazards !== null) {
            $specificHazards = $this->refreshFlashPointSentence((string) $specificHazards, $fpLine);
        } else {
            $parts = [];
            if ($flammable) {
                $parts[] = $this->t->get('section5.specific_hazards_flammable_cat' . $flamCat);
                if ($fpLine !== null) {
                    $parts[] = $fpLine;
                }
            }
            if ($waterReactive) {
                $parts[] = $this->t->get($waterReactiveH260
                    ? 'section5.specific_hazards_water_reactive_h260'
                    : 'section5.specific_hazards_water_reactive');
            }
            if ($oxidizer) {
                $parts[] = $this->t->get('section5.specific_hazards_oxidizer');
            } elseif ($organicPeroxide) {
                $parts[] = $this->t->get('section5.specific_hazards_organic_peroxide');
            } else {
                $parts[] = $this->t->get('section5.specific_hazards');
            }
            if ($this->uvPack) {
                $parts[] = $this->t->get('section5.uv_specific_hazards'); // #65 (no repeated SCBA sentence)
            }
            $specificHazards = implode(' ', $parts);
        }

        // --- Firefighter advice: explosive > water-reactive > flammable > default ---
        $firefighterAdvice = $overrides[5]['firefighter_advice'] ?? null;
        if ($firefighterAdvice === null) {
            if ($explosive) {
                $firefighterAdvice = $this->t->get('section5.firefighter_advice_explosive');
                if ($waterReactive) {
                    $firefighterAdvice .= ' ' . $this->t->get('section5.firefighter_no_water'); // #64
                }
            } elseif ($waterReactive) {
                $firefighterAdvice = $this->t->get('section5.firefighter_advice_water_reactive');
            } elseif ($flammable) {
                $firefighterAdvice = $this->t->get('section5.firefighter_advice_flammable');
            } else {
                $firefighterAdvice = $this->t->get('section5.firefighter_advice');
            }
        }

        // No 'flash_point_c' key: the number was never printed on the PDF and
        // leaked into the HTML preview (#11/#42). Section 9 owns the printed
        // flash point; Section 5 embeds the identical string in its text.
        return [
            'title'              => $this->t->get('section5.title'),
            'suitable_media'     => $suitableMedia,
            'unsuitable_media'   => $unsuitableMedia,
            'specific_hazards'   => $specificHazards,
            'firefighter_advice' => $firefighterAdvice,
        ];
    }

    private function section6(array $hazardResult, array $fg, array $overrides): array
    {
        $hCodes = self::extractHCodes($hazardResult);
        $has    = static fn(array $codes): bool => !empty(array_intersect($hCodes, $codes));

        // Audit item #12: Section 6 is composed from fragments.
        //   - Personal precautions: a SEVERE paragraph (acute tox. 1-3 or
        //     skin corrosion) REPLACES the base text; the flammable fragment
        //     (H220-H226, H228: P210/P241/P242/P243 language) is APPENDED
        //     to whichever paragraph was chosen.
        //   - Environmental: the drains sentence always prints; then one
        //     acute sentence (H400 > H401 > H402) and, independently, one
        //     chronic sentence (H410 > H411 > H412 > H413), each echoing its
        //     own H-statement wording (GHS Rev. 7 ch. 4.1; audit #24: H412
        //     keeps "with long lasting effects", H413 is never called
        //     "Harmful to aquatic life"); the notify sentence prints for any
        //     aquatic classification.
        //   - Acute tox. 1-3 AND H314: the SCBA paragraph gets the corrosive
        //     PPE fragment appended (audit #32).
        //   - Containment is keyed on the finished good's physical_state
        //     (solid/powder -> sweep or vacuum; gel/paste -> scrape; every
        //     other value, including blank, -> liquid/absorbent).
        // A per-FG override replaces the whole field, fragments included.

        // --- Personal precautions ---
        $precautions = $overrides[6]['personal_precautions'] ?? null;
        if ($precautions === null) {
            $corrosive = $has(['H314']);
            if ($has(['H300', 'H301', 'H310', 'H311', 'H330', 'H331'])) {
                $precautions = $this->t->get('section6.precautions_acute_toxic');
                if ($corrosive) {
                    // #32: the SCBA paragraph has no skin / eye wording.
                    $precautions .= ' ' . $this->t->get('section6.precautions_corrosive_addon');
                }
            } elseif ($corrosive) {
                $precautions = $this->t->get('section6.precautions_corrosive');
            } else {
                $precautions = $this->t->get('section6.personal_precautions');
            }
            if ($has(['H220', 'H221', 'H222', 'H223', 'H224', 'H225', 'H226', 'H228'])) {
                $precautions .= ' ' . $this->t->get('section6.precautions_flammable');
            } elseif ($has(['H227', 'H250', 'H260', 'H261'])) {
                // #64: combustible liquids (H227, P210), pyrophorics and
                // water-reactives (the released gas may ignite).
                $precautions .= ' ' . $this->t->get('section6.precautions_ignition');
            }
        }

        // --- Environmental precautions ---
        $environmental = $overrides[6]['environmental'] ?? null;
        if ($environmental === null) {
            $environmental = $this->t->get('section6.environmental');
            $aquaticParts = [];
            // Acute route: H400 > H401 > H402.
            if ($has(['H400'])) {
                $aquaticParts[] = $this->t->get('section6.environmental_aquatic_acute');
            } elseif ($has(['H401'])) {
                $aquaticParts[] = $this->t->get('section6.environmental_aquatic_acute_toxic');
            } elseif ($has(['H402'])) {
                $aquaticParts[] = $this->t->get('section6.environmental_aquatic_harmful');
            }
            // Chronic route, evaluated independently of the acute route (#24):
            // H410 > H411 > H412 > H413, each with its own sentence.
            if ($has(['H410'])) {
                $aquaticParts[] = $this->t->get('section6.environmental_aquatic_chronic_very');
            } elseif ($has(['H411'])) {
                $aquaticParts[] = $this->t->get('section6.environmental_aquatic_chronic');
            } elseif ($has(['H412'])) {
                $aquaticParts[] = $this->t->get('section6.environmental_aquatic_chronic_harmful');
            } elseif ($has(['H413'])) {
                $aquaticParts[] = $this->t->get('section6.environmental_aquatic_chronic_may_harm');
            }
            if ($aquaticParts !== []) {
                $environmental .= ' ' . implode(' ', $aquaticParts)
                    . ' ' . $this->t->get('section6.environmental_notify');
            }
        }

        // --- Containment and cleanup (keyed on physical state) ---
        $containment = $overrides[6]['containment'] ?? null;
        if ($containment === null) {
            $physicalState = strtolower(trim((string) ($fg['physical_state'] ?? '')));
            if ($physicalState === 'solid' || $physicalState === 'powder') {
                $containment = $this->t->get('section6.containment_solid');
            } elseif ($physicalState === 'gel' || $physicalState === 'paste') {
                $containment = $this->t->get('section6.containment_paste');
            } elseif ($physicalState === 'gas') {
                $containment = $this->t->get('section6.containment_gas'); // #64
            } else {
                // Liquid, blank and custom states: liquid is the fallback (item #12).
                $containment = $this->t->get('section6.containment_liquid');
            }
            if ($this->uvPack) {
                $containment .= ' ' . $this->t->get('section6.uv_containment'); // #65: state-independent
            }
        }

        return [
            'title'                => $this->t->get('section6.title'),
            'personal_precautions' => $precautions,
            'environmental'        => $environmental,
            'containment'          => $containment,
        ];
    }

    /**
     * Canonical print order of the Section 10 "Conditions to avoid" items
     * (audit #19). Each key maps to translation key section10.cond_<key>;
     * natural case, composed additively exactly like INCOMPAT_ORDER.
     */
    private const COND_ORDER = [
        'heat', 'storage_temp', 'ignition', 'air', 'water', 'shock', 'oxidizers', 'combustibles', 'uv',
    ];

    /** Composition flag => translation suffix, in Section 10 print order (audit #19). */
    private const DECOMP_ELEMENTS = [
        'has_nitrogen' => 'nitrogen',
        'has_sulfur'   => 'sulfur',
        'has_halogen'  => 'halogen',
    ];

    /**
     * Elements present in the composition, from the cas_master has_* flags
     * carried by Formula::getExpandedComposition() / buildResaleComposition()
     * (audit #19), in DECOMP_ELEMENTS order. TRADE_SECRET buckets carry no
     * flags. Any flagged constituent counts, at any concentration.
     *
     * @return string[] subset of ['nitrogen', 'sulfur', 'halogen']
     */
    private static function decompositionElements(array $composition): array
    {
        $found = [];
        foreach ($composition as $c) {
            if (!is_array($c)) {
                continue;
            }
            foreach (self::DECOMP_ELEMENTS as $flag => $element) {
                if (!empty($c[$flag])) {
                    $found[$element] = true;
                }
            }
        }
        return array_values(array_filter(
            self::DECOMP_ELEMENTS,
            static fn(string $e): bool => isset($found[$e])
        ));
    }

    /**
     * Canonical print order of the Section 10 "Incompatible materials" items
     * (audit #13 / #19). Each key maps to translation key
     * section10.incompat_<key>; items are stored in natural case so the
     * list can be embedded mid-sentence (Section 7 storage) or printed as
     * its own sentence (Section 10) in all four languages.
     */
    private const INCOMPAT_ORDER = [
        'water', 'air', 'oxidizers', 'combustibles', 'reducing_agents', 'organics',
        'metal_powders', 'acids', 'bases', 'halogens', 'amines', 'metal_salts', 'metals',
    ];

    /**
     * Incompatible-material items derived additively from the physical
     * hazard classes (audit #13 / #19). Returns translated, natural-case
     * phrases in INCOMPAT_ORDER, de-duplicated. With no physical hazard the
     * result is ["strong oxidizing agents", "strong acids", "strong bases"]
     * — the historical Section 10 default. Oxidizers drop the "strong
     * oxidizing agents" item (the product is one) and add combustibles,
     * reducing agents, organics and metal powders instead.
     *
     * @return string[]
     */
    private function incompatibleMaterialItems(array $hazardResult): array
    {
        $hCodes = self::extractHCodes($hazardResult);
        $has    = static fn(array $codes): bool => !empty(array_intersect($hCodes, $codes));

        $flammable      = $has(['H220', 'H221', 'H222', 'H223', 'H224', 'H225', 'H226', 'H227', 'H228']);
        $oxidizer       = $has(['H270', 'H271', 'H272']);
        $selfReactive   = $has(['H240', 'H241', 'H242']);
        $pyrophoric     = $has(['H250']);
        $waterReactive  = $has(['H260', 'H261']);
        $corrosiveMetal = $has(['H290']);

        $selected = ['acids' => true, 'bases' => true];
        if (!$oxidizer) {
            $selected['oxidizers'] = true;
        }
        if ($oxidizer) {
            $selected += ['combustibles' => true, 'reducing_agents' => true, 'organics' => true, 'metal_powders' => true];
        }
        if ($flammable) {
            $selected['halogens'] = true;
        }
        if ($selfReactive) {
            $selected += ['reducing_agents' => true, 'amines' => true, 'metal_salts' => true];
        }
        if ($pyrophoric) {
            $selected += ['air' => true, 'water' => true];
        }
        if ($waterReactive) {
            $selected['water'] = true;
        }
        if ($corrosiveMetal) {
            $selected['metals'] = true;
        }

        $items = [];
        foreach (self::INCOMPAT_ORDER as $key) {
            if (isset($selected[$key])) {
                $items[] = $this->t->get('section10.incompat_' . $key);
            }
        }
        return $items;
    }

    /**
     * The Section 10 "Incompatible materials" sentence: the per-FG override
     * verbatim, else the generated items joined and capitalised. Section 7
     * storage embeds the same resolved value so the two sections always agree.
     */
    private function resolveIncompatibleMaterials(array $hazardResult, array $overrides): string
    {
        $override = $overrides[10]['incompatible'] ?? null;
        if ($override !== null) {
            return $override;
        }
        $list = implode(', ', $this->incompatibleMaterialItems($hazardResult));
        return mb_strtoupper(mb_substr($list, 0, 1)) . mb_substr($list, 1) . '.';
    }

    /**
     * The same list formatted for embedding after a colon in the Section 7
     * storage sentence: override text with its trailing full stop removed,
     * else the natural-case generated items.
     */
    private function incompatibleMaterialsInline(array $hazardResult, array $overrides): string
    {
        $override = $overrides[10]['incompatible'] ?? null;
        if ($override !== null) {
            // #64: lower-case first letter after "(see Section 10): " (inlineCase()).
            return self::inlineCase(rtrim(trim($override), '.'), $this->t->getLanguage());
        }
        return implode(', ', $this->incompatibleMaterialItems($hazardResult));
    }

    private function section7(array $hazardResult, array $overrides): array
    {
        $hCodes = self::extractHCodes($hazardResult);
        $has    = static fn(array $codes): bool => !empty(array_intersect($hCodes, $codes));

        // Audit #13: Section 7 is composed ADDITIVELY. One flag set drives
        // both the 7(a) handling and the 7(b) storage chains in the same
        // order, so combined hazards (e.g. flammable + corrosive) keep every
        // fragment. The base paragraphs carry no fire wording; ignition
        // language appears only for the flammable / combustible / pyrophoric
        // / self-reactive / oxidizer / explosive classes (P210, #34). H251/H252 are self-heating (GHS ch. 2.11),
        // never pyrophoric (H250, ch. 2.9/2.10). Storage ends by naming the
        // Section 10 incompatible materials via the shared helper.
        $flammable      = $has(['H220', 'H221', 'H222', 'H223', 'H224', 'H225', 'H226', 'H228']);
        $combustible    = $has(['H227']);
        $aerosol        = $has(['H222', 'H223', 'H229']);
        $oxidizer       = $has(['H270', 'H271', 'H272']);
        $selfReactive   = $has(['H240', 'H241', 'H242']);
        $selfHeating    = $has(['H251', 'H252']);
        $pyrophoric     = $has(['H250']);
        $waterReactive  = $has(['H260', 'H261']);
        $corrosive      = $has(['H314']);
        $corrosiveMetal = $has(['H290']);
        $sensitizer     = $has(['H317', 'H334']);
        $explosive      = $has(['H200', 'H201', 'H202', 'H203', 'H204', 'H205']);
        $gasPressure    = $has(['H280', 'H281']);
        $stotRe         = $has(['H372', 'H373']);
        // #33: "Store locked up" follows the resolved P405 — the P-codes
        // Section 2 prints (CMR, Acute Tox. 1-3, Asp. Tox., Skin Corr. 1,
        // STOT SE 1-3 per GHSHazardData, plus P405 from source data).
        $lockUp         = in_array('P405', self::resolvedPCodes($hazardResult), true);
        // #34: oxidizers and explosives carry P210 as well.
        $ignition       = $flammable || $combustible || $pyrophoric || $selfReactive || $oxidizer || $explosive;
        // #64: corrosive / STOT RE already say "Do not breathe ..."
        $breatheSaid    = $corrosive || $stotRe;

        // --- 7(a) Handling ---
        $handling = $overrides[7]['handling'] ?? null;
        if ($handling === null) {
            $parts = [$this->t->get('section7.handling_base')];
            if ($ignition) {
                $parts[] = $this->t->get('section7.handling_ignition');
            }
            if ($flammable) {
                $parts[] = $this->t->get('section7.handling_flammable_static');
            }
            if ($aerosol) {
                $parts[] = $this->t->get('section7.handling_aerosol');
            }
            if ($explosive) {
                $parts[] = $this->t->get('section7.handling_explosive');       // #64
            }
            if ($gasPressure) {
                $parts[] = $this->t->get('section7.handling_gas_pressure');    // #64
            }
            if ($oxidizer) {
                $parts[] = $this->t->get('section7.handling_oxidizer_combustibles');
            }
            if ($selfReactive) {
                $parts[] = $this->t->get('section7.handling_self_reactive');
            }
            if ($selfHeating) {
                $parts[] = $this->t->get('section7.handling_self_heating');
            }
            if ($pyrophoric && $waterReactive) {
                $parts[] = $this->t->get('section7.handling_pyrophoric_water_reactive'); // #64: "inert gas" once
            } elseif ($pyrophoric) {
                $parts[] = $this->t->get('section7.handling_pyrophoric_air');
            } elseif ($waterReactive) {
                $parts[] = $this->t->get('section7.handling_water_reactive_water');
            }
            if ($pyrophoric || $waterReactive) {
                $parts[] = $this->t->get('section7.handling_moisture');
            }
            if ($corrosive) {
                $parts[] = $this->t->get('section7.handling_corrosive');
            }
            if ($corrosiveMetal) {
                $parts[] = $this->t->get('section7.handling_corrosive_metals');
            }
            if ($stotRe && !$corrosive) {
                $parts[] = $this->t->get('section7.handling_stot_re');          // #64 (P260)
            }
            if ($sensitizer) {
                // #64: when a "Do not breathe" sentence already printed, add only the work-clothing half.
                $parts[] = $this->t->get($breatheSaid ? 'section7.handling_sensitizer_clothing' : 'section7.handling_sensitizer');
            }
            if ($this->uvPack) {
                $parts[] = $this->t->get('section7.uv_handling');               // #65
            }
            $handling = implode(' ', $parts);
        }

        // --- 7(b) Storage (same flags, same order, then incompatibles) ---
        $storage = $overrides[7]['storage'] ?? null;
        if ($storage === null) {
            $parts = [$this->t->get('section7.storage_base')];
            if ($ignition) {
                $parts[] = $this->t->get('section7.storage_ignition');
            }
            if ($flammable) {
                $parts[] = $this->t->get('section7.storage_flammable_area');
            }
            if ($aerosol) {
                $parts[] = $this->t->get('section7.storage_aerosol');
            }
            if ($explosive) {
                $parts[] = $this->t->get('section7.storage_explosive');        // #64
            }
            if ($gasPressure) {
                $parts[] = $this->t->get('section7.storage_gas_pressure');     // #64
            }
            if ($oxidizer) {
                $parts[] = $this->t->get('section7.storage_oxidizer_separate');
            }
            if ($selfReactive) {
                $parts[] = $this->t->get('section7.storage_self_reactive');
            }
            if ($selfHeating) {
                $parts[] = $this->t->get('section7.storage_self_heating_cool');
            }
            if ($pyrophoric) {
                $parts[] = $this->t->get('section7.storage_pyrophoric_air');
            }
            if ($waterReactive) {
                $parts[] = $this->t->get('section7.storage_water_reactive_water');
            }
            if ($pyrophoric || $waterReactive) {
                $parts[] = $this->t->get('section7.storage_inert_moisture');
            }
            if ($corrosiveMetal) {
                $parts[] = $this->t->get('section7.storage_corrosive_metals');
            }
            if ($this->uvFamily) {
                // #65: same condition as the Section 10 UV-light item (family flag).
                $parts[] = $this->t->get('section7.uv_storage');
            }
            if ($lockUp) {
                $parts[] = $this->t->get('section7.storage_locked');
            }
            $parts[] = $this->t->get('section7.storage_incompatible', [
                'list' => $this->incompatibleMaterialsInline($hazardResult, $overrides),
            ]);
            $storage = implode(' ', $parts);
        }

        return [
            'title'      => $this->t->get('section7.title'),
            'handling'   => $handling,
            'storage'    => $storage,
        ];
    }

    private function section8(array $hazard, array $composition, array $overrides, array $fg = [], bool $uvPack = false): array
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
            // Audit #13 (decision Q4): Section 3 withholds this identity, so Section 8
            // does too (also masks the literal 'TRADE_SECRET' CAS of manual-JSON limits).
            $el = $this->maskTradeSecretRow($el, $compByCas);
            $exposureLimits[] = $el;
        }

        // PPE: the same resolved values Section 2 prints — operator override,
        // else the tier sentence derivePPE selected, plus on UV rule-pack
        // products the UV sentence where the tier is hazard-driven or the
        // mixture carries H317 (resolvePPE(), audit #29).
        $ppe = $this->resolvePPE($hazard, $overrides, $uvPack)['text'];

        return [
            'title'            => $this->t->get('section8.title'),
            'exposure_limits'  => $exposureLimits,
            // #64: printed (italic) in place of the table when no limit row remains.
            'exposure_limits_none' => $exposureLimits === [] ? $this->t->get('section8.no_exposure_limits') : '',
            'engineering'      => $overrides[8]['engineering'] ?? $this->engineeringControls($hazard, $fg),
            'respiratory'      => $ppe['respiratory'],
            'hand_protection'  => $ppe['hand_protection'],
            'eye_protection'   => $ppe['eye_protection'],
            'skin_protection'  => $ppe['skin_protection'],
        ];
    }

    /**
     * Section 8(b) engineering controls (audit #14). The general local-exhaust
     * sentence always prints first, followed by additive fragments in a
     * fixed order:
     *   - dust control when the finished good's physical state is Powder
     *     (decision #14 "powders"; Solid, blank and other states are non-dusting, #64);
     *   - explosion-proof ventilation and bonding/grounding for Flam. Liq.
     *     category 1-3 (H224/H225/H226);
     *   - eyewash station and safety shower for Skin Corr. 1 (H314) or
     *     Eye Dam. 1 (H318) — 29 CFR 1910.151(c).
     * Fragments are joined with a single space, as section4() does. The
     * operator override for the field is applied by the caller.
     */
    private function engineeringControls(array $hazard, array $fg): string
    {
        $hCodes = self::extractHCodes($hazard);
        $state  = strtolower(trim((string) ($fg['physical_state'] ?? '')));

        $parts = [$this->t->get('section8.engineering')];
        if ($state === 'powder') {
            $parts[] = $this->t->get('section8.engineering_dust');
        }
        if (!empty(array_intersect($hCodes, ['H224', 'H225', 'H226']))) {
            $parts[] = $this->t->get('section8.engineering_flammable');
        }
        if (!empty(array_intersect($hCodes, ['H314', 'H318']))) {
            $parts[] = $this->t->get('section8.engineering_corrosive');
        }
        return implode(' ', $parts);
    }

    /**
     * Resolve the four PPE sentences once so Sections 2 and 8 print the
     * same text. Precedence per field: operator override (text_overrides,
     * section 8; replaces the whole field) → translated sentence for the tier
     * derivePPE selected, followed on UV rule-pack products ($uvPack) by the
     * field's UV acrylate sentence ONLY when that tier is hazard-driven or the
     * mixture carries H317 (audit #29) — an unclassified UV product keeps its
     * plain "No special … required" sentences.
     *
     * The tiers are re-derived here from the final H-statements rather
     * than read from $hazard['ppe_recommendations'], so every post-classify
     * mutation (carbon black logic, carcinogen registry, FG override) is
     * reflected and the engine's own copy is informational only.
     *
     * @return array{text: array<string,string>, hazard_driven: array<string,bool>}
     */
    private function resolvePPE(array $hazard, array $overrides, bool $uvPack = false): array
    {
        $derived  = HazardEngine::derivePPE($hazard['h_statements'] ?? [], $hazard['p_statements'] ?? []);
        $uv       = $uvPack ? UVAcrylateRulePack::getPpeSupplement($this->t) : [];
        $skinSens = in_array('H317', self::extractHCodes($hazard), true);

        $text = [];
        $hazardDriven = [];
        foreach (HazardEngine::PPE_FIELDS as $field) {
            $tier = $derived[$field]['tier'] ?? 'none';
            $key  = $derived[$field]['key'] ?? ('section8.ppe.' . $field . '.none');
            $hazardDriven[$field] = !in_array($tier, HazardEngine::PPE_BASELINE_TIERS, true);

            $override = $overrides[8][$field] ?? null;
            if ($override !== null && trim((string) $override) !== '') {
                $text[$field] = (string) $override;
                continue;
            }
            $text[$field] = $this->t->get($key);
            if (isset($uv[$field]) && ($hazardDriven[$field] || $skinSens)) {
                $text[$field] .= ' ' . $uv[$field];
            }
        }

        return ['text' => $text, 'hazard_driven' => $hazardDriven];
    }

    /**
     * #18(b) Physical state used by Sections 6, 8 and 9.
     *
     * The finished good's own physical_state wins when set; otherwise the
     * physical_state of the highest-wt% raw material that has one (#42) in the expanded
     * composition (FormulaCalcService::deriveFormulaProperties ->
     * formula_props.physical_state); otherwise 'Liquid'. generate(),
     * computeBase() and computeBaseForResaleRawMaterial() write the result
     * back into $fg['physical_state'] before any section is built so the
     * three sections can never disagree.
     */
    public static function resolvePhysicalState(array $fg, array $calcResult): string
    {
        $own = trim((string) ($fg['physical_state'] ?? ''));
        if ($own !== '') {
            return $own;
        }
        $derived = trim((string) ($calcResult['formula_props']['physical_state'] ?? ''));
        return $derived !== '' ? $derived : 'Liquid';
    }

    /** #42 Preview-only operator warning (never printed) when resolvePhysicalState() falls back to the hard-coded 'Liquid'. */
    public const PHYSICAL_STATE_DEFAULT_WARNING = 'Physical state: no physical state is recorded on this product or on any raw material in its formula, so the sheet uses the default "Liquid" (Sections 6, 8, 9 and 14). Set Physical State on the finished good (or on the raw material for a resale sheet).';

    /** #42 True when neither the product nor any raw material carries a physical state. */
    public static function physicalStateIsDefault(array $fg, array $calcResult): bool
    {
        return trim((string) ($fg['physical_state'] ?? '')) === ''
            && trim((string) ($calcResult['formula_props']['physical_state'] ?? '')) === '';
    }

    /**
     * #43 / Q8 HazCom Appendix D Section 9 properties the system holds no
     * formula data for, in printed order. Each prints the per-product
     * Section 9 override when one is stored, else labels.not_determined
     * (flammability (solid, gas) on a Liquid: labels.not_applicable).
     * Field key = labels.<key>.
     */
    public const SECTION9_APPENDIX_D_FIELDS = [
        'odor_threshold', 'ph', 'melting_point', 'evaporation_rate',
        'flammability_solid_gas', 'flammability_limits', 'vapor_pressure', 'vapor_density',
        'partition_coefficient', 'auto_ignition_temp', 'decomposition_temp', 'viscosity',
    ];

    /**
     * #18(d) Map FormulaCalcService's solubility_key to the printed term.
     * Returns '' when no raw material in the formula carries a solubility
     * value (the caller prints labels.not_determined).
     */
    private function solubilityText(?string $key): string
    {
        return match ($key) {
            'soluble'           => $this->t->get('section9.solubility_soluble'),
            'partially_soluble' => $this->t->get('section9.solubility_partially_soluble'),
            'negligible'        => $this->t->get('section9.solubility_negligible'),
            'not_soluble'       => $this->t->get('section9.solubility_not_soluble'),
            default             => '',
        };
    }

    /**
     * #37 Print a Section 9 enum value (physical state or colour) in the
     * sheet language. The stored value stays English — Sections 6/8/14
     * compare it — and only the printed copy is mapped through
     * section9.state_* / section9.color_*. Unknown (custom) values print as
     * entered; EN prints the stored value unchanged.
     *
     * @param string $kind 'state' | 'color'
     */
    private function localizeSection9Enum(string $value, string $kind): string
    {
        static $keys = [
            'state' => [
                'liquid' => 'state_liquid', 'solid' => 'state_solid', 'powder' => 'state_powder',
                'paste'  => 'state_paste',  'gel'   => 'state_gel',   'gas'    => 'state_gas',
            ],
            'color' => [
                'black'   => 'color_black',   'white'       => 'color_white',       'yellow'  => 'color_yellow',
                'cyan'    => 'color_cyan',    'magenta'     => 'color_magenta',     'transparent' => 'color_transparent',
                'various' => 'color_various',
            ],
        ];
        $key = $keys[$kind][mb_strtolower(trim($value))] ?? null;
        if ($key === null || $this->t->getLanguage() === 'en') {
            return $value;
        }
        return $this->t->get('section9.' . $key);
    }

    /**
     * #37 Printed name of a withheld (trade-secret) constituent: the
     * operator's trade_secret_description, else labels.trade_secret in the
     * sheet language. The upstream composition (Formula /
     * FormulaCalcService) stores the English placeholder 'Trade Secret' as
     * the description of a synthetic trade-secret bucket; that sentinel is
     * mapped to the translated label too.
     */
    private function tradeSecretName(?string $desc): string
    {
        $desc = trim((string) $desc);
        if ($desc === '' || strcasecmp($desc, 'Trade Secret') === 0) {
            return $this->t->get('labels.trade_secret');
        }
        return $desc;
    }

    /**
     * Audit #13 (owner decision Q4): withhold a trade-secret constituent's
     * identity in a Section 8 / 11 / 15 row exactly as Sections 3 and 12 do.
     * The $nameKeys get the trade-secret description (else the translated
     * "Trade Secret"), cas_number gets labels.trade_secret_cas, $dropKeys
     * (identifying extras: SNUR rule citation, SARA category code) are blanked
     * and is_trade_secret = true is set. A row is trade secret when its CAS is
     * the TRADE_SECRET sentinel or the composition row for its CAS is flagged.
     * Rows of disclosed constituents are returned unchanged. Call it AFTER any
     * band lookup that needs the real CAS.
     */
    private function maskTradeSecretRow(array $row, array $compByCas, array $nameKeys = ['chemical_name'], array $dropKeys = []): array
    {
        $cas  = (string) ($row['cas_number'] ?? '');
        $comp = $compByCas[$cas] ?? null;
        if ($cas !== 'TRADE_SECRET' && empty($comp['is_trade_secret'])) {
            return $row;
        }
        $name = $this->tradeSecretName($comp['trade_secret_description'] ?? '');
        foreach ($nameKeys as $key) {
            $row[$key] = $name;
        }
        foreach ($dropKeys as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = '';
            }
        }
        $row['cas_number']      = $this->t->get('labels.trade_secret_cas');
        $row['is_trade_secret'] = true;
        return $row;
    }

    private function section9(array $fg, array $calcResult, array $overrides): array
    {
        $voc   = $calcResult['voc'];
        $props = $calcResult['formula_props'] ?? [];

        $notDetermined = $this->t->get('labels.not_determined');

        // Physical state: already resolved by the caller (#18(b):
        // FG field -> dominant RM -> 'Liquid'); re-resolve here so a direct
        // call with an unresolved $fg (tests) prints the same thing.
        $physicalState = self::resolvePhysicalState($fg, $calcResult);
        $color = (string) ($fg['color'] ?? '');
        // #37 Printed copies in the sheet language (stored values stay English).
        $stateText = $this->localizeSection9Enum($physicalState, 'state');
        $colorText = $color !== '' ? $this->localizeSection9Enum($color, 'color') : '';

        // Flash point: override first, then auto-derived from the formula.
        // Shared resolver with Section 5 (#11) so both sections print one value.
        $flashPoint = self::resolveFlashPointDisplay($props, $overrides) ?? $notDetermined;

        // #16 Initial boiling point: override first, then the lowest raw
        // material boiling point in the expanded composition (formula_props,
        // recursive, weight-independent), else "Not determined" — App D
        // accepts a no-data statement.
        $boilingPoint = self::resolveBoilingPointDisplay($props, $overrides) ?? $notDetermined;

        // VOC wt%: if all materials are <1%, display "<1%"
        $vocWtPctDisplay = round((float) ($voc['total_voc_wt_pct'] ?? 0), 2);
        if (!empty($props['all_voc_less_than_one'])) {
            $vocWtPctDisplay = '<1';
        }

        // #18(d) Solubility: per-FG override wins; otherwise the formula's
        // soluble-fraction band (FormulaCalcService::deriveFormulaProperties
        // -> solubility_key); otherwise "Not determined".
        $solubility = trim((string) ($overrides[9]['solubility'] ?? ''));
        if ($solubility === '') {
            $solubility = $this->solubilityText($props['solubility_key'] ?? null);
        }
        if ($solubility === '') {
            $solubility = $notDetermined;
        }

        // Appearance (#42(2)): override; else FG colour + resolved state when a
        // colour is set; else the resolved state alone. The dominant raw
        // material's appearance text is not used on finished-good sheets (it
        // could contradict the product state, e.g. 'Clear liquid' on a Paste).
        // Resale sheets (one raw at 100 %, raws have no colour field) keep
        // that raw material's own appearance text.
        $appearance = trim((string) ($overrides[9]['appearance'] ?? ''));
        if ($appearance === '') {
            // #37 Word order and casing per language (section9.appearance_*);
            // mb_ so accented words (Líquido, Pâte, Weiß) lower-case correctly.
            $appearanceParts = [
                'lc_color' => mb_strtolower($colorText),
                'lc_state' => mb_strtolower($stateText),
                'color'    => $colorText,
                'state'    => $stateText,
            ];
            if ($color !== '') {
                $appearance = trim($this->t->get('section9.appearance_color_state', $appearanceParts));
            } elseif (!empty($fg['is_resale'])) {
                $appearance = trim((string) ($props['appearance'] ?? ''));
            }
            if ($appearance === '') {
                $appearance = trim($this->t->get('section9.appearance_state', $appearanceParts));
            }
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

        // #18(a)/#71: missing RM specific gravity (-> 1.0) and missing VOC (-> 0)
        // are applied inside VOCCalculator; nothing "estimated" is printed. The
        // operator gets one preview-only warning per raw material instead
        // (FormulaCalcService::dataGapWarnings). #18(c): "VOC less water &
        // exempts" and "solids vol%" are not part of the sheet.
        //
        // #43 / Q8: every HazCom Appendix D property prints. Properties with no
        // formula data print the per-product override, else "Not determined";
        // flammability (solid, gas) on a Liquid prints "Not applicable".
        // Key order = printed order: the preview iterates this array, and
        // PDFService::renderSection9() lists the same order.
        $appD = [];
        foreach (self::SECTION9_APPENDIX_D_FIELDS as $k) {
            $ov = trim((string) ($overrides[9][$k] ?? ''));
            if ($ov !== '') {
                $appD[$k] = $ov;
            } elseif ($k === 'flammability_solid_gas' && strcasecmp(trim($physicalState), 'Liquid') === 0) {
                $appD[$k] = $this->t->get('labels.not_applicable');
            } else {
                $appD[$k] = $notDetermined;
            }
        }

        return [
            'title'                  => $this->t->get('section9.title'),
            'physical_state'         => $stateText,
            'color'                  => $colorText,
            'appearance'             => $appearance,
            'odor'                   => $odor,
            'odor_threshold'         => $appD['odor_threshold'],
            'ph'                     => $appD['ph'],
            'melting_point'          => $appD['melting_point'],
            'boiling_point'          => $boilingPoint,
            'flash_point'            => $flashPoint,
            'evaporation_rate'       => $appD['evaporation_rate'],
            'flammability_solid_gas' => $appD['flammability_solid_gas'],
            'flammability_limits'    => $appD['flammability_limits'],
            'vapor_pressure'         => $appD['vapor_pressure'],
            'vapor_density'          => $appD['vapor_density'],
            'specific_gravity'       => round((float) ($voc['mixture_sg'] ?? 0), 3) ?: $notDetermined,
            'solubility'             => $solubility,
            'partition_coefficient'  => $appD['partition_coefficient'],
            'auto_ignition_temp'     => $appD['auto_ignition_temp'],
            'decomposition_temp'     => $appD['decomposition_temp'],
            'viscosity'              => $appD['viscosity'],
            'voc_lb_per_gal'         => round((float) ($voc['voc_lb_per_gal'] ?? 0), 2),
            'voc_wt_pct'             => $vocWtPctDisplay,
            'solids_wt_pct'          => round((float) ($voc['solids_wt_pct'] ?? 0), 1),
        ];
    }

    /**
     * Section 10 (audit #19). One flag set drives every paragraph so combined
     * classes keep every fragment (as Section 7, audit #13):
     *   - Reactivity / Stability branch on H200-205, H240-242 (self-reactive
     *     or organic peroxide), H250, H251/252, H260-261, H230-232; oxidizers
     *     (H270-272) change Reactivity only (#34): "Unstable under the
     *     following conditions: ..." instead of "Stable ...".
     *   - Conditions to avoid are additive items in COND_ORDER; ignition
     *     wording only for ignition classes; oxidizers swap "strong
     *     oxidizers" for combustibles/reducing materials; UV/LED families
     *     ($isUv — the resolved family flag, decision #3 / audit #35, fed by
     *     UVAcrylateRulePack::familyIsUv() at the call sites, not gated on
     *     the rule-pack setting) add the light / premature-polymerization
     *     condition.
     *   - Decomposition products come from the cas_master element flags on
     *     the composition (nitrogen oxides / sulfur oxides / hydrogen halides).
     * $isUv / $composition default so the DB-free tests that only care about
     * the incompatibles helper keep their two-argument calls.
     */
    private function section10(array $hazardResult, array $overrides, bool $isUv = false, array $composition = []): array
    {
        $hCodes = self::extractHCodes($hazardResult);
        $has    = static fn(array $codes): bool => !empty(array_intersect($hCodes, $codes));

        $flammable     = $has(['H220', 'H221', 'H222', 'H223', 'H224', 'H225', 'H226', 'H227', 'H228']);
        $oxidizer      = $has(['H270', 'H271', 'H272']);
        $selfReactive  = $has(['H240', 'H241', 'H242']);
        $selfHeating   = $has(['H251', 'H252']);
        $pyrophoric    = $has(['H250']);
        $waterReactive = $has(['H260', 'H261']);

        // #34: explosives and chemically unstable gases; H240-H242 cover both
        // self-reactive substances and organic peroxides (one wording).
        $explosive     = $has(['H200', 'H201', 'H202', 'H203', 'H204', 'H205']);
        $unstableGas   = $has(['H230', 'H231', 'H232']);

        // --- Reactivity / Stability: branch on the reactive classes (#34) ---
        // Reactivity: one fragment per class present, in this order. Stability:
        // "Unstable under ..." for every class except oxidizers (stable on
        // their own; they intensify a fire).
        $reactiveKeys = array_keys(array_filter([
            'explosive'      => $explosive,
            'self_reactive'  => $selfReactive,
            'pyrophoric'     => $pyrophoric,
            'self_heating'   => $selfHeating,
            'water_reactive' => $waterReactive,
            'unstable_gas'   => $unstableGas,
            'oxidizer'       => $oxidizer,
        ]));
        $unstableKeys = array_values(array_diff($reactiveKeys, ['oxidizer']));

        $reactivity = $overrides[10]['reactivity'] ?? null;
        if ($reactivity === null) {
            $reactivity = $reactiveKeys === []
                ? $this->t->get('section10.reactivity')
                : implode(' ', array_map(
                    fn(string $k): string => $this->t->get('section10.reactivity_' . $k),
                    $reactiveKeys
                ));
        }

        $stability = $overrides[10]['stability'] ?? null;
        if ($stability === null) {
            $stability = $unstableKeys === []
                ? $this->t->get('section10.stability')
                : $this->t->get('section10.stability_unstable', [
                    'conditions' => implode('; ', array_map(
                        fn(string $k): string => $this->t->get('section10.stability_cond_' . $k),
                        $unstableKeys
                    )),
                ]);
        }

        // --- Conditions to avoid: additive items in COND_ORDER ---
        $conditionsAvoid = $overrides[10]['conditions_avoid'] ?? null;
        if ($conditionsAvoid === null) {
            $selected = ['heat' => true];
            if ($selfReactive || $selfHeating) {
                $selected['storage_temp'] = true;
            }
            // #34: oxidizers and explosives (P210) and water-reactives (the
            // released gas may ignite) also avoid ignition sources.
            if ($flammable || $pyrophoric || $selfReactive || $oxidizer || $explosive || $waterReactive) {
                $selected['ignition'] = true;
            }
            if ($explosive) {
                $selected['shock'] = true;
            }
            if ($pyrophoric) {
                $selected += ['air' => true, 'water' => true];
            }
            if ($waterReactive) {
                $selected['water'] = true;
            }
            if ($selfReactive) {
                $selected['shock'] = true;
            }
            if ($oxidizer) {
                $selected['combustibles'] = true;
            } else {
                $selected['oxidizers'] = true;
            }
            if ($isUv) {
                $selected['uv'] = true;
            }
            $items = [];
            foreach (self::COND_ORDER as $key) {
                if (isset($selected[$key])) {
                    $items[] = $this->t->get('section10.cond_' . $key);
                }
            }
            $list = implode(', ', $items);
            $conditionsAvoid = mb_strtoupper(mb_substr($list, 0, 1)) . mb_substr($list, 1) . '.';
        }

        // --- Incompatible materials (shared with Section 7 storage, audit #13) ---
        $incompatible = $this->resolveIncompatibleMaterials($hazardResult, $overrides);

        // --- Decomposition products from the cas_master element flags ---
        $decomposition = $overrides[10]['decomposition'] ?? null;
        if ($decomposition === null) {
            $elements = self::decompositionElements($composition);
            if ($elements === []) {
                $decomposition = $this->t->get('section10.decomposition');
            } elseif (count($elements) === 1) {
                $decomposition = $this->t->get('section10.decomposition_' . $elements[0]);
            } else {
                $decomposition = $this->t->get('section10.decomposition_multi', [
                    'products' => implode(', ', array_map(
                        fn(string $e): string => $this->t->get('section10.decomp_' . $e),
                        $elements
                    )),
                ]);
            }
        }

        return [
            'title'            => $this->t->get('section10.title'),
            'reactivity'       => $reactivity,
            'stability'        => $stability,
            'conditions_avoid' => $conditionsAvoid,
            'incompatible'     => $incompatible,
            'decomposition'    => $decomposition,
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
     *
     * @param bool|null $listingsBelow true when Section 11 Carcinogenicity prints the registry listing (no operator override); null = has_carcinogens
     */
    private function buildChronicEffects(array $hazard, array $carcinogenResult, ?bool $listingsBelow = null): string
    {
        // #66: "see Carcinogenicity below" only when that paragraph lists registry components.
        $listingsBelow ??= !empty($carcinogenResult['has_carcinogens']);
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
        // flagged a listing but no H350/H351 survived (e.g. an inhalation-only
        // listing in a bound product, audit #41(2)), still point
        // the reader at the paragraph below.
        if ($has(['H350']) || $hasPrefix('H350')) {
            $fragments[] = $this->t->get($listingsBelow ? 'section11.chronic_carc_1' : 'section11.chronic_carc_1_no_ref');
        } elseif ($has(['H351'])) {
            $fragments[] = $this->t->get($listingsBelow ? 'section11.chronic_carc_2' : 'section11.chronic_carc_2_no_ref');
        } elseif (!empty($carcinogenResult['has_carcinogens'])) {
            $fragments[] = $this->t->get($listingsBelow ? 'section11.chronic_carc_listed' : 'section11.chronic_carc_listed_no_ref');
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
     * One line per route (oral, dermal, inhalation), derived from the
     * engine's classification so this block can never contradict Section 2
     * or the Section 4(b) symptoms line. A classified route prints its
     * category, the resolved H-statement and — whenever the engine's ATE
     * summation (HazardEngine::applyATECalculation) produced a value at
     * that same category — the calculated ATEmix with its unit. Q6: when no
     * ATEmix stands behind the printed category (cut-off, dominated or
     * not-classified ATE, trade-secret declaration), the line ends with
     * section11.acute_basis_concentration instead of a number; a category
     * set only by the Finished-good hazard override (or shown only as an
     * H-code, #40) gets neither. A route the engine did not classify prints
     * "Not classified based on available data." Admin
     * text_overrides[11]['acute_toxicity'] is applied by the
     * caller. No unknown-ingredient percentage is ever printed (decision #4).
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
        // Categories 1-4 only: 29 CFR 1910.1200 App. A.1 does not adopt
        // Category 5 (H303/H313/H333), so such an entry prints as not classified.
        $routeCodes = [
            'oral'       => [1 => 'H300', 2 => 'H300', 3 => 'H301', 4 => 'H302'],
            'dermal'     => [1 => 'H310', 2 => 'H310', 3 => 'H311', 4 => 'H312'],
            'inhalation' => [1 => 'H330', 2 => 'H330', 3 => 'H331', 4 => 'H332'],
        ];

        // Most severe category per route, keeping the ATE value (and the
        // engine route, which decides the inhalation unit) when present.
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
            if ($catNum < 1 || $catNum > 4) {
                continue;
            }
            $ate      = isset($hc['ate_mix']) && is_numeric($hc['ate_mix']) && (float) $hc['ate_mix'] > 0 ? (float) $hc['ate_mix'] : null;
            $ateRoute = $ate !== null ? (string) ($hc['route'] ?? '') : '';
            // Q6: a category set only by the Finished-good hazard override is
            // not "based on ingredient concentration".
            $isOverride = ($hc['source'] ?? '') === 'fg_override' || ($hc['cas'] ?? '') === 'FG_OVERRIDE';
            if (!isset($byRoute[$route]) || $catNum < $byRoute[$route]['cat']) {
                $byRoute[$route] = ['cat' => $catNum, 'ate' => $ate, 'ate_route' => $ateRoute, 'h_codes' => $hc['h_codes'] ?? [], 'override_only' => $isOverride];
            } elseif ($catNum === $byRoute[$route]['cat']) {
                if ($ate !== null && $byRoute[$route]['ate'] === null) {
                    $byRoute[$route]['ate']       = $ate;
                    $byRoute[$route]['ate_route'] = $ateRoute;
                }
                $byRoute[$route]['override_only'] = $byRoute[$route]['override_only'] && $isOverride;
            }
        }

        // #40: a route shown only as an H-code (FG-override H-codes entered
        // without a class, class lines with no category, combined codes)
        // still gets its category, so Section 11 never says "Not classified"
        // beside an H30x printed in Section 2. Categories 1-4 only (HazCom
        // 2024 does not adopt Cat 5). H300 / H310 / H330 cover Categories 1
        // and 2, so that line names no category (cat 2 is used only to rank).
        $codeRoutes = [
            'H300' => ['oral', 2, false],       'H301' => ['oral', 3, true],       'H302' => ['oral', 4, true],
            'H310' => ['dermal', 2, false],     'H311' => ['dermal', 3, true],     'H312' => ['dermal', 4, true],
            'H330' => ['inhalation', 2, false], 'H331' => ['inhalation', 3, true], 'H332' => ['inhalation', 4, true],
        ];
        foreach ($hazard['h_statements'] ?? [] as $s) {
            foreach (explode('+', strtoupper((string) ($s['code'] ?? ''))) as $part) {
                $part = trim($part);
                if (!isset($codeRoutes[$part])) {
                    continue;
                }
                [$codeRoute, $codeCat, $catKnown] = $codeRoutes[$part];
                if (isset($byRoute[$codeRoute]) && $byRoute[$codeRoute]['cat'] <= $codeCat) {
                    continue;
                }
                $byRoute[$codeRoute] = ['cat' => $codeCat, 'ate' => null, 'ate_route' => '', 'h_codes' => [$part], 'source' => 'h_code', 'cat_known' => $catKnown];
            }
        }

        // ATE summation results the engine computed without stamping an
        // ate_mixture entry (a per-component trigger had already classified
        // the route at the same category). Only a result at the PRINTED
        // category is used: a dominated or unclassified ATEmix would
        // contradict the category line above it.
        foreach ($hazard['ate_results'] ?? [] as $res) {
            $route = $routes[(string) ($res['canonical'] ?? '')] ?? null;
            if ($route === null || !isset($byRoute[$route]) || $byRoute[$route]['ate'] !== null || ($byRoute[$route]['source'] ?? '') === 'h_code') {
                continue;
            }
            if (!is_numeric($res['ate_mix'] ?? null) || (float) $res['ate_mix'] <= 0) {
                continue;
            }
            if (!preg_match('/(\d)/', (string) ($res['category'] ?? ''), $m) || (int) $m[1] !== $byRoute[$route]['cat']) {
                continue;
            }
            $byRoute[$route]['ate']       = (float) $res['ate_mix'];
            $byRoute[$route]['ate_route'] = (string) ($res['route'] ?? '');
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
            $routeLabel = $this->t->get('section11.acute_route_' . $route);
            if (!isset($byRoute[$route])) {
                $lines[] = $this->t->get('section11.acute_route_not_classified', ['route' => $routeLabel]);
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

            // #40: H300 / H310 / H330 alone cover Categories 1 and 2 — no category printed.
            $line = ($byRoute[$route]['cat_known'] ?? true) === false
                ? $this->t->get('section11.acute_route_line_no_category', [
                    'route'     => $routeLabel,
                    'statement' => $statement,
                    'code'      => $code,
                ])
                : $this->t->get('section11.acute_route_line', [
                    'route'     => $routeLabel,
                    'category'  => GHSStatements::categoryName('Category ' . $cat, $lang),
                    'statement' => $statement,
                    'code'      => $code,
                ]);
            if ($byRoute[$route]['ate'] !== null) {
                $unitKey = 'section11.acute_unit_' . $route;
                if ($route === 'inhalation' && in_array($byRoute[$route]['ate_route'], ['inhalation_vapor', 'inhalation_dust'], true)) {
                    $unitKey = 'section11.acute_unit_' . $byRoute[$route]['ate_route'];
                }
                $line .= ' ' . $this->t->get('section11.acute_ate', [
                    'value' => self::formatAte($byRoute[$route]['ate']),
                    'unit'  => $this->t->get($unitKey),
                ]);
            } elseif (!($byRoute[$route]['override_only'] ?? false) && ($byRoute[$route]['source'] ?? '') !== 'h_code') {
                // Q6: the printed category came from the ingredient cut-off
                // (or a trade-secret declaration), not from the ATEmix. A route
                // known only from an H-code (#40) has no traceable basis.
                $line .= ' ' . $this->t->get('section11.acute_basis_concentration');
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Section 11(a) likely routes of exposure (HazCom App. D; Q8). Skin and
     * eye contact are always listed (the product is handled as supplied);
     * inhalation when the product carries an inhalation-route H-code or any
     * component has an occupational exposure limit (Section 8); ingestion
     * for H300-H305. Order follows Section 4. Route names reuse the
     * Section 4 labels, so every language is translated.
     */
    private function deriveRoutesOfExposure(array $hazard): string
    {
        $hCodes = self::extractHCodes($hazard);
        $has    = static fn(array $codes): bool => !empty(array_intersect($hCodes, $codes));

        $routes = [];
        if ($has(['H330', 'H331', 'H332', 'H334', 'H335', 'H336']) || !empty($hazard['exposure_limits'])) {
            $routes[] = $this->t->get('labels.inhalation');
        }
        $routes[] = $this->t->get('labels.skin_contact');
        $routes[] = $this->t->get('labels.eye_contact');
        if ($has(['H300', 'H301', 'H302', 'H304', 'H305'])) {
            $routes[] = $this->t->get('labels.ingestion');
        }
        return implode(', ', $routes) . '.';
    }

    /**
     * ATEmix for print: three significant figures, no exponent, no trailing
     * zeros (1250, 326, 12.3, 2.5, 0.123, 0.0049). Decimal point in every
     * language, like every other number on the sheet.
     */
    private static function formatAte(float $v): string
    {
        if ($v <= 0) {
            return '';
        }
        if ($v >= 100) {
            $d = 0;
        } elseif ($v >= 10) {
            $d = 1;
        } elseif ($v >= 1) {
            $d = 2;
        } else {
            $d = 2 - (int) floor(log10($v));
        }
        $s = number_format(round($v, $d), $d, '.', '');
        // Only strip trailing zeros after a decimal point ("1250" stays "1250").
        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
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

    /**
     * Section 15 copies of SARA 313 / HAP entries (audit #42): each entry
     * carries the Section 3 prescribed-range band for its CAS and no exact
     * percentage, so no renderer can print one. Entries whose CAS is not in
     * the composition (manual HAP rows, no CAS) are banded from their own
     * concentration_pct.
     */
    private function bandRegulatoryEntries(array $entries, array $compByCas): array
    {
        $out = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $cas  = (string) ($entry['cas_number'] ?? '');
            $comp = $compByCas[$cas] ?? null;
            $entry['concentration_range'] = $this->formatConcentration(
                $comp ?? ['concentration_pct' => (float) ($entry['concentration_pct'] ?? 0)]
            );
            unset($entry['concentration_pct']);
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Prop 65 listed chemicals as printable lines (audit #42): chemical name
     * (with CAS when known) and the OEHHA listing type(s) — cancer,
     * developmental toxicity, female / male reproductive toxicity. The same
     * chemical reached through several raw materials is merged into one
     * line; NSRL / MADL / listing dates are never printed.
     *
     * @return string[]
     */
    private function buildProp65ListedLines(array $prop65Result): array
    {
        // Order here is the print order of the types on a line.
        $typeKeys = [
            'cancer'              => 'section15.prop65_type_cancer',
            'developmental'       => 'section15.prop65_type_developmental',
            'female reproductive' => 'section15.prop65_type_female_reproductive',
            'male reproductive'   => 'section15.prop65_type_male_reproductive',
            'reproductive'        => 'section15.prop65_type_reproductive',
        ];

        $merged = [];
        foreach ($prop65Result['listed_chemicals'] ?? [] as $chem) {
            if (!is_array($chem)) {
                continue;
            }
            $name = trim((string) ($chem['chemical_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $key = strtolower($name);
            if (!isset($merged[$key])) {
                $merged[$key] = ['name' => $name, 'cas' => '', 'types' => []];
            }
            $cas = trim((string) ($chem['cas_number'] ?? ''));
            if ($cas !== '' && $merged[$key]['cas'] === '') {
                $merged[$key]['cas'] = $cas;
            }
            $types = $chem['toxicity_type'] ?? [];
            if (is_string($types)) {
                $types = explode(',', $types);
            }
            foreach ((array) $types as $type) {
                $type = strtolower(trim((string) $type));
                if ($type !== '' && isset($typeKeys[$type])) {
                    $merged[$key]['types'][$type] = true;
                }
            }
        }

        $lines = [];
        foreach ($merged as $m) {
            $typeTexts = [];
            foreach ($typeKeys as $type => $trKey) {
                if (isset($m['types'][$type])) {
                    $typeTexts[] = $this->t->get($trKey);
                }
            }
            if ($typeTexts === []) {
                continue;
            }
            $lines[] = $this->t->get(
                $m['cas'] !== '' ? 'section15.prop65_listed_line' : 'section15.prop65_listed_line_no_cas',
                ['name' => $m['name'], 'cas' => $m['cas'], 'types' => implode(', ', $typeTexts)]
            );
        }
        return $lines;
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

            // Attach carcinogen findings (#37: registry classification in the
            // sheet language; the English registry description only on EN sheets)
            foreach ($carcinogenResult['findings'] as $f) {
                if ($f['cas_number'] === $cas) {
                    $entry['carcinogen_listings'] = CarcinogenService::localiseListings($f['agencies'] ?? [], $this->t);
                    if (!empty($f['inhalable_dust_only'])) {
                        $entry['carcinogen_note'] = $this->t->get('section11.carcinogenicity_inhalable_dust_note'); // audit #41(2)
                    }
                }
            }

            if (!empty($entry['exposure_limits']) || !empty($entry['carcinogen_listings'])) {
                // Audit #13 (decision Q4): the component block names a trade secret
                // by its trade-secret description only, never its name or CAS.
                $masked = $this->maskTradeSecretRow($entry, $compByCas);
                if (!empty($masked['is_trade_secret'])) {
                    $masked['exposure_limits'] = array_map(
                        fn (array $el): array => $this->maskTradeSecretRow($el, $compByCas),
                        $masked['exposure_limits']
                    );
                    foreach ($masked['carcinogen_listings'] as &$listing) {
                        $listing['description'] = ''; // EN registry description can name the chemical
                    }
                    unset($listing);
                }
                $componentTox[] = $masked;
            }
        }

        // #66: true only when the Carcinogenicity paragraph prints the registry
        // listing, so Chronic Effects may say "see Carcinogenicity below".
        $carcinogenListsBelow = false;

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
                    // Audit #13 (decision Q4): masked after banding (the band needs the real CAS).
                    $f = $this->maskTradeSecretRow($f, $compByCas);
                    $bandedFindings[] = $f;
                }
                $carcinogenText = CarcinogenService::buildSummaryText($bandedFindings, $this->t);
                $carcinogenListsBelow = true;
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
            // Q8 / HazCom App. D 11(a)-(c): likely routes, then the Section 4(b)
            // acute/delayed symptoms line (its override too, so 4 and 11 agree).
            'routes_of_exposure' => $this->deriveRoutesOfExposure($hazard),
            'symptoms'           => $overrides[4]['symptoms'] ?? $this->deriveSymptoms($hazard['h_statements'] ?? []),
            'acute_toxicity'     => $overrides[11]['acute_toxicity'] ?? $this->buildAcuteToxicity($hazard),
            'chronic_effects'    => $overrides[11]['chronic_effects'] ?? $this->buildChronicEffects($hazard, $carcinogenResult, $carcinogenListsBelow),
            'carcinogenicity'    => $carcinogenText,
            'hazard_classes'     => $hazard['hazard_classes'],
            'component_toxicology' => $componentTox,
            'carcinogen_result'  => $carcinogenResult,
        ];
    }

    private function section12(
        array $hazardResult,
        array $composition,
        array $overrides,
        array $saraResult = [],
        string $substanceMixture = SubstanceMixtureResolver::MIXTURE   // #66: Section 3 identity row
    ): array {
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
        // #66: only constituents Section 3 lists are named (same CAS set:
        // >= 0.1 %, classified or carrying an exposure limit, plus a
        // Substance's identity), so Section 12 never names an ingredient
        // Section 3 withholds.
        $listedCas        = self::section3ListedCas($composition, $hazardResult, $substanceMixture);
        $componentAquatic = [];
        foreach ($hazardResult['aquatic_components'] ?? [] as $row) {
            $cas  = (string) ($row['cas'] ?? '');
            if (!isset($listedCas[$cas])) {
                continue;
            }
            $name = (string) ($row['name'] ?? '');
            $comp = $compByCas[$cas] ?? null;
            if ($cas === 'TRADE_SECRET' || !empty($comp['is_trade_secret'])) {
                // Section 3 withholds this constituent's identity; do the same
                // here. A manual-JSON trade-secret row reaches the engine at a
                // nominal 100 % (HazardEngine), which is not a printable value.
                $range = $comp !== null ? $this->formatConcentration($comp) : '';
                $cas   = $this->t->get('labels.trade_secret_cas');
                $name  = $this->tradeSecretName($comp['trade_secret_description'] ?? '');
            } else {
                $range = $this->formatConcentration($comp ?? ['concentration_pct' => (float) ($row['conc'] ?? 0)]);
            }
            $componentAquatic[] = [
                'cas_number'          => $cas,
                'chemical_name'       => $name,
                'concentration_range' => $range,
                'acute'               => $this->formatAquaticCategory($row['acute_category'] ?? null, $row['acute_m_factor'] ?? null, $lang, $row['acute_m_factor_source'] ?? null),
                'chronic'             => $this->formatAquaticCategory($row['chronic_category'] ?? null, $row['chronic_m_factor'] ?? null, $lang, $row['chronic_m_factor_source'] ?? null),
            ];
        }

        // --- Ecotoxicity (audit items #23, #25) ---
        // #25: the lead-in names the real source of the printed aquatic codes
        // (HazardEngine 'aquatic_basis'):
        //   - replace-mode FG override, or any printed code that came only
        //     from the override -> "manufacturer's assessment" lead-in;
        //   - every printed code fired by the M-factor summation and a
        //     component table follows -> summation lead-in ("listed below");
        //   - otherwise (CAS determination / trade-secret JSON, or no table)
        //     -> neutral lead-in that claims no method.
        // Replace mode discards the composition classification, so the
        // component table is dropped: it can neither support nor contradict
        // the printed classification.
        $basis        = $hazardResult['aquatic_basis'] ?? [];
        $overrideMode = $basis['override_mode'] ?? null;
        if ($overrideMode === 'replace') {
            $componentAquatic = [];
        }
        $ecotoxicity = $overrides[12]['ecotoxicity'] ?? null;
        if ($ecotoxicity === null) {
            if (!empty($aquaticStatements)) {
                $parts = [];
                foreach ($aquaticStatements as $code => $text) {
                    $parts[] = $text !== ''
                        ? $code . ': ' . rtrim($text, '.') . '.'
                        : $code . '.';
                }
                $summationCodes = array_flip(array_map('strval', $basis['summation_codes'] ?? []));
                $overrideCodes  = array_flip(array_map('strval', $basis['override_codes'] ?? []));
                $fromOverride   = false;
                $allSummation   = true;
                foreach (array_keys($aquaticStatements) as $code) {
                    $code = (string) $code;
                    if (!isset($summationCodes[$code])) {
                        $allSummation = false;
                        if ($overrideMode !== null && isset($overrideCodes[$code])) {
                            $fromOverride = true;
                        }
                    }
                }
                if ($overrideMode === 'replace' || $fromOverride) {
                    $lead = $this->t->get('section12.ecotoxicity_classified_manufacturer');
                } elseif ($allSummation && $componentAquatic !== []) {
                    $lead = $this->t->get('section12.ecotoxicity_classified');
                } else {
                    $lead = $this->t->get('section12.ecotoxicity_classified_no_table');
                }
                $ecotoxicity = $lead . ' '
                    . implode(' ', $parts) . ' '
                    . $this->t->get('section12.environmental_warning');
            } elseif ($overrideMode === 'replace') {
                $ecotoxicity = $this->t->get('section12.ecotoxicity_not_classified_manufacturer');
            } elseif ($componentAquatic !== []) {
                // Components carry aquatic data but the summation did not
                // reach a mixture classification - say so rather than
                // "No data available", which the table below would contradict.
                $ecotoxicity = $this->t->get('section12.ecotoxicity_not_classified');
            } elseif (!empty($hazardResult['aquatic_components'])) {
                // #66: aquatic data exists, but no such component is listed
                // in Section 3, so no table follows.
                $ecotoxicity = $this->t->get('section12.ecotoxicity_not_classified_no_table');
            } else {
                $ecotoxicity = $this->t->get('section12.ecotoxicity');
            }
        }

        // --- Persistence / bioaccumulation / mobility (audit item #24) ---
        // The only persistence/bioaccumulation data the system holds is the
        // EPA PBT designation on the SARA 313 list (sara313_list.is_pbt;
        // 40 CFR 372.28 "chemicals of special concern"). Name the PBT-flagged
        // listed components present at any concentration above 0 — the same
        // set Section 15 reports under the 2023 TRI rule (no de minimis for
        // PBT chemicals), so the two sections agree — once, on the
        // persistence line; the bioaccumulation line then
        // cross-references it (or carries the full sentence when persistence
        // is overridden); otherwise both lines stay "No data available." Mobility in
        // soil has no data path (29 CFR 1910.1200 App. D, Section 12(d)).
        // No percentage is printed. Trade-secret constituents are masked
        // exactly as in the aquatic table above. Chemical names come from the
        // EPA list name (sara_name) like Section 15, minus the list's
        // footnote markers (e.g. "Lead ††").
        $pbtParts = [];
        foreach (array_merge($saraResult['reportable'] ?? [], $saraResult['below_threshold'] ?? []) as $entry) {
            $entryCas = (string) ($entry['cas_number'] ?? '');
            if ($entryCas === '' || empty($entry['is_pbt']) || (float) ($entry['concentration_pct'] ?? 0) <= 0 || isset($pbtParts[$entryCas])) {
                continue;
            }
            $comp = $compByCas[$entryCas] ?? null;
            if ($entryCas === 'TRADE_SECRET' || !empty($comp['is_trade_secret'])) {
                $pbtName = $this->tradeSecretName($comp['trade_secret_description'] ?? '');
                $pbtCas  = $this->t->get('labels.trade_secret_cas');
            } else {
                $pbtName = (string) ((($entry['sara_name'] ?? '') !== '') ? $entry['sara_name'] : ($entry['chemical_name'] ?? ''));
                $pbtName = trim((string) preg_replace('/[\s\x{2020}\x{2021}*]+$/u', '', $pbtName));
                $pbtCas  = $entryCas;
            }
            $pbtParts[$entryCas] = $this->t->get('section12.pbt_component_item', ['name' => $pbtName, 'cas' => $pbtCas]);
        }
        $pbtLine = $pbtParts !== []
            ? $this->t->get('section12.pbt_components', ['components' => implode('; ', $pbtParts)])
            : null;
        $persistence = $overrides[12]['persistence'] ?? ($pbtLine ?? $this->t->get('section12.persistence'));
        if ($pbtLine === null) {
            $bioaccumulation = $overrides[12]['bioaccumulation'] ?? $this->t->get('section12.bioaccumulation');
        } else {
            $bioaccumulation = $overrides[12]['bioaccumulation']
                ?? (isset($overrides[12]['persistence']) ? $pbtLine : $this->t->get('section12.pbt_see_persistence'));
        }

        return [
            'title'             => $this->t->get('section12.title'),
            'ecotoxicity'       => $ecotoxicity,
            'component_aquatic' => $componentAquatic,
            'persistence'       => $persistence,
            'bioaccumulation'   => $bioaccumulation,
            'mobility'          => $overrides[12]['mobility'] ?? $this->t->get('section12.mobility'),
            // The shared Sections 12-15 footnote is emitted once, on section15().
        ];
    }

    /**
     * Render a canonical aquatic category ('Cat 1' … 'Cat 4') for Section 12
     * in the SDS language, appending the M-factor for Category 1 only
     * (GHS Rev. 7 4.1.3.5.5.5 — M-factors apply to Category 1 substances).
     * An M-factor the engine defaulted to 1 because no vendor / CPD value is
     * on file (HazardEngine::resolveMFactor source 'default') is marked as a
     * default (audit #66) so it never reads as supplier data.
     * Returns '' when the component has no classification on that route.
     */
    private function formatAquaticCategory(?string $canonical, $mFactor, string $lang, ?string $mFactorSource = null): string
    {
        $canonical = trim((string) $canonical);
        if ($canonical === '') {
            return '';
        }
        $display = preg_replace('/^Cat\s+/i', 'Category ', $canonical);
        $text = GHSStatements::categoryName($display, $lang);
        if (strcasecmp($canonical, 'Cat 1') === 0 && $mFactor !== null) {
            $m = rtrim(rtrim(number_format((float) $mFactor, 2, '.', ''), '0'), '.');
            $text .= ' (' . ($mFactorSource === 'default'
                ? $this->t->get('section12.m_factor_default', ['value' => $m])
                : 'M = ' . $m) . ')';
        }
        return $text;
    }

    /**
     * Raw composition keys (real CAS, or the 'TRADE_SECRET' sentinel) that
     * section3() lists, so Section 12 never names a constituent Section 3
     * withholds (audit #66). Mirrors section3()'s filters: CAS present,
     * >= 0.1 %, classified (hazardous_cas) or carrying an exposure limit,
     * minus inhalation-only CAS with no H-code attributed by the final
     * hazard_classes and no exposure limit; a Substance sheet's identity
     * (largest constituent) is always listed (#36(3)). Trade-secret
     * constituents are included (Section 3 lists them, masked). No
     * non-hazardous flag is read (owner decision Q5).
     * SDSGeneratorEcologySpillsTest asserts parity with section3().
     *
     * @return array<string, true>
     */
    private static function section3ListedCas(
        array $composition,
        array $hazardResult,
        string $substanceMixture = SubstanceMixtureResolver::MIXTURE
    ): array {
        $hazardousCas = array_flip(array_map('strval', $hazardResult['hazardous_cas'] ?? []));
        $withLimit = [];
        foreach ($hazardResult['exposure_limits'] ?? [] as $el) {
            $limCas = (string) ($el['cas_number'] ?? '');
            if ($limCas !== '') {
                $withLimit[$limCas] = true;
            }
        }
        // Inhalation-only CAS keep only what the final hazard_classes
        // attribute to them, exactly as section3() (MIXTURE entries credit
        // their contributors; FG_OVERRIDE / TRADE_SECRET entries credit no CAS).
        $finalAttribution = TransportClassifier::casHCodeMap(['hazard_classes' => $hazardResult['hazard_classes'] ?? []]);
        $identityCas = null;
        if ($substanceMixture === SubstanceMixtureResolver::SUBSTANCE) {
            $identityPct = -1.0;
            foreach ($composition as $idRow) {
                $idCas = (string) ($idRow['cas_number'] ?? '');
                $idPct = (float) ($idRow['concentration_pct'] ?? 0);
                if ($idCas !== '' && $idPct > $identityPct) {
                    $identityCas = $idCas;
                    $identityPct = $idPct;
                }
            }
        }
        $listed = [];
        foreach ($composition as $c) {
            if (!is_array($c)) {
                continue;
            }
            $cas = (string) ($c['cas_number'] ?? '');
            if ($cas === '') {
                continue;
            }
            if ($identityCas !== null && $cas === $identityCas) {
                $listed[$cas] = true;
                continue;
            }
            if ((float) ($c['concentration_pct'] ?? 0) < 0.1) {
                continue;
            }
            if (!isset($hazardousCas[$cas]) && !isset($withLimit[$cas])) {
                continue;
            }
            if (isset(self::getInhalationOnlyCas()[$cas]) && empty($finalAttribution[$cas]) && !isset($withLimit[$cas])) {
                continue;
            }
            $listed[$cas] = true;
        }
        return $listed;
    }

    /**
     * Section 13 (audit #26). The generic disposal sentence stays (override
     * wins). A second, computed-only line lists EVERY applicable RCRA code:
     *   D001 ignitable  — follows the Section 2 classification (decision Q3,
     *                     audit #9/#10; d001FlashReasonKey()): only with Flam.
     *                     Liq. 1–3 (H224–H226) in the final codes. Reason = the
     *                     product flash point the engine classified from
     *                     (hazardResult['flammability'], never the Section 9
     *                     edit) when below 60 °C (40 CFR 261.21(a)(1)); a "> n"
     *                     value with n < 60 prints the "not determined to be at
     *                     or above 60 °C" reason. Exactly 60 °C is Cat 3 /
     *                     Class 3 but not D001. Flam. Liq. codes that did not
     *                     come from the flash point (finished-good override)
     *                     print the hazard-based reason. Oxidizers (H270–H272) and
     *                     pyrophoric / self-heating solids (H250–H252) are
     *                     D001 under 40 CFR 261.21(a)(2)/(a)(4)
     *   D002 corrosive  — H314 (stands in for the pH criterion) and/or H290
     *                     (steel corrosion), each named as a reason; never for
     *                     a Solid / Powder / Paste product: 261.22 covers
     *                     aqueous and liquid wastes only (#66)
     *   D001 safety net — H220–H223 (ignitable compressed gas / aerosol) and
     *                     H228 (flammable solid) add reasons (#11); these codes
     *                     also make Section 14 "Not determined" (publish blocked)
     *   D003 reactive   — water-reactive (H260/H261), explosive (H200–H205),
     *                     self-reactive / organic peroxide (H240–H242)
     *   D004–D043       — rcra_waste_codes matches by component CAS, plus
     *                     D004–D011 for metal compounds via cas_master.tc_metals (#12)
     *                     (RCRAService, any concentration); name only, never a
     *                     percentage; inside the "as sold" sentence
     *   F / K / P / U   — listed wastes never apply to the product as sold
     *                     (261.31 spent solvents; 261.33(d) only the pure
     *                     chemical or a sole-active-ingredient formulation):
     *                     printed in a separate "for reference" sentence and
     *                     never the reason for "may be regulated"
     * The line is never overridden (like the #24 PBT line) and always ends
     * with the generator-responsibility sentence.
     */
    private function section13(array $hazardResult, array $calcResult, array $overrides, array $rcraResult = [], array $fg = []): array
    {
        $hCodes = self::extractHCodes($hazardResult);
        $has    = static fn (array $codes): bool => array_intersect($hCodes, $codes) !== [];

        $items  = [];
        $listed = [];   // F / K / P / U component fragments (reference sentence)

        // D001 (Q3, #9/#10): Flam. Liq. 1–3 in the final codes, reason from the
        // product flash point the engine classified from (never the Section 9 edit).
        $d001    = [];
        $flamKey = self::d001FlashReasonKey($hCodes, self::flammabilityOf($hazardResult, $calcResult, []));
        if ($flamKey !== null) {
            $d001[] = $this->t->get($flamKey);
        }
        if ($has(['H270', 'H271', 'H272'])) {
            $d001[] = $this->t->get('section13.rcra_reason_oxidizer');
        }
        if ($has(['H250', 'H251', 'H252'])) {
            $d001[] = $this->t->get('section13.rcra_reason_ignitable_solid');
        }
        // #11: ignitable compressed gas / flammable aerosol (261.21(a)(3)) and flammable
        // solid (261.21(a)(2)). Safety net only: none are sold, and any of these codes
        // already makes Section 14 "Not determined", which blocks publishing.
        if ($has(['H220', 'H221', 'H222', 'H223'])) {
            $d001[] = $this->t->get('section13.rcra_reason_flammable_gas');
        }
        if ($has(['H228'])) {
            $d001[] = $this->t->get('section13.rcra_reason_flammable_solid');
        }
        if ($d001 !== []) {
            $items[] = $this->t->get('section13.rcra_d001', ['reasons' => implode(', ', $d001)]);
        }

        // #66: D002 (40 CFR 261.22) applies to aqueous / liquid wastes only — never to a
        // Solid, Powder or Paste product (same state rule as the flammable-liquid
        // classification). H314 stands in for the pH criterion, H290 for steel corrosion.
        if (!self::rcraIsNonLiquidState($fg, $calcResult)) {
            $d002 = [];
            if ($has(['H314'])) {
                $d002[] = $this->t->get('section13.rcra_reason_skin_corrosion');
            }
            if ($has(['H290'])) {
                $d002[] = $this->t->get('section13.rcra_reason_metal_corrosion');
            }
            if ($d002 !== []) {
                $items[] = $this->t->get('section13.rcra_d002', ['reasons' => implode(', ', $d002)]);
            }
        }

        $d003 = [];
        if ($has(['H260', 'H261'])) {
            $d003[] = $this->t->get('section13.rcra_reason_water_reactive');
        }
        if ($has(['H200', 'H201', 'H202', 'H203', 'H204', 'H205'])) {
            $d003[] = $this->t->get('section13.rcra_reason_explosive');
        }
        if ($has(['H240', 'H241', 'H242'])) {
            $d003[] = $this->t->get('section13.rcra_reason_unstable');
        }
        if ($d003 !== []) {
            $items[] = $this->t->get('section13.rcra_d003', ['reasons' => implode(', ', $d003)]);
        }

        // Toxicity-characteristic constituents (D codes: "as sold" sentence) and
        // listed wastes (F / K / P / U: reference sentence) from rcra_waste_codes.
        foreach ($rcraResult['components'] ?? [] as $comp) {
            $asSold = [];
            $ref    = [];
            foreach ($comp['codes'] ?? [] as $code) {
                if (!is_array($code) || ($code['waste_code'] ?? '') === '') {
                    continue;
                }
                $kind = strtoupper((string) (($code['kind'] ?? '') ?: substr((string) $code['waste_code'], 0, 1)));
                if ($kind === 'D') {
                    $asSold[] = $this->formatRcraCode($code);
                } else {
                    $ref[] = $this->formatRcraCode($code);
                }
            }
            if ($asSold === [] && $ref === []) {
                continue;
            }
            if (!empty($comp['is_trade_secret'])) {
                // Audit #13 (decision Q4): a trade-secret constituent is never
                // identified. Generic wording only: no waste code, no TCLP level
                // ("D018 … 0.5 mg/L" alone names benzene). Same description twice
                // prints one fragment.
                $tsName = $this->tradeSecretName($comp['trade_secret_description'] ?? '');
                if ($asSold !== []) {
                    $frag = $this->t->get('section13.rcra_component_ts_tc', ['name' => $tsName]);
                    if (!in_array($frag, $items, true)) {
                        $items[] = $frag;
                    }
                }
                if ($ref !== []) {
                    $frag = $this->t->get('section13.rcra_component_ts_listed', ['name' => $tsName]);
                    if (!in_array($frag, $listed, true)) {
                        $listed[] = $frag;
                    }
                }
                continue;
            }
            $name = (string) ($comp['chemical_name'] ?? '');
            if ($name === '') {
                $name = (string) ($comp['cas_number'] ?? '');
            }
            if ($asSold !== []) {
                $items[] = $this->t->get('section13.rcra_component', ['name' => $name, 'list' => implode('; ', $asSold)]);
            }
            if ($ref !== []) {
                $listed[] = $this->t->get('section13.rcra_component', ['name' => $name, 'list' => implode('; ', $ref)]);
            }
        }

        // Assembly: [as-sold sentence | "no characteristic" | rcra_none] + [reference sentence] + generator.
        $parts = [];
        if ($items !== []) {
            $parts[] = $this->t->get('section13.rcra_intro') . ' ' . implode('; ', $items) . '.';
        } else {
            // rcra_none also covers listed components ("no component has been identified as …"), so it only prints when nothing matched at all.
            $parts[] = $this->t->get($listed !== [] ? 'section13.rcra_none_characteristic' : 'section13.rcra_none');
        }
        if ($listed !== []) {
            $parts[] = $this->t->get('section13.rcra_listed_intro') . ' ' . implode('; ', $listed) . '.';
        }
        $parts[] = $this->t->get('section13.rcra_generator');
        $rcra = implode(' ', $parts);

        return [
            'title'               => $this->t->get('section13.title'),
            'methods'             => $overrides[13]['methods'] ?? $this->t->get('section13.methods'),
            'rcra_classification' => $rcra,
            // The shared Sections 12-15 footnote is emitted once, on section15().
        ];
    }

    /**
     * One "<code> <condition>" fragment for a rcra_waste_codes row (audit #26).
     * D-codes carry the TCLP regulatory level (mg/L, trailing zeros trimmed);
     * F / K / P / U get their listing condition. Kind defaults to the code's
     * first letter for rows saved without one.
     */
    private function formatRcraCode(array $code): string
    {
        $wc   = (string) ($code['waste_code'] ?? '');
        $kind = strtoupper((string) (($code['kind'] ?? '') ?: substr($wc, 0, 1)));
        switch ($kind) {
            case 'D':
                $limit = $code['limit_mg_l'] ?? null;
                if ($limit === null || $limit === '') {
                    return $this->t->get('section13.rcra_code_d_nolimit', ['code' => $wc]);
                }
                $limitText = rtrim(rtrim(number_format((float) $limit, 3, '.', ''), '0'), '.');
                return $this->t->get('section13.rcra_code_d', ['code' => $wc, 'limit' => $limitText]);
            case 'F':
                return $this->t->get('section13.rcra_code_f', ['code' => $wc]);
            case 'K':
                return $this->t->get('section13.rcra_code_k', ['code' => $wc]);
            case 'P':
                return $this->t->get('section13.rcra_code_p', ['code' => $wc]);
            default:
                return $this->t->get('section13.rcra_code_u', ['code' => $wc]);
        }
    }

    /**
     * #66: true when the resolved physical state (resolvePhysicalState: the
     * product's own state > highest-wt% raw material > 'Liquid') is Solid,
     * Powder or Paste — RCRA D002 (40 CFR 261.22) covers aqueous and liquid
     * wastes only.
     */
    private static function rcraIsNonLiquidState(array $fg, array $calcResult): bool
    {
        $state = strtolower(trim(self::resolvePhysicalState($fg, $calcResult)));
        return in_array($state, ['solid', 'powder', 'paste'], true);
    }

    /**
     * Q3 (#9/#10): Sections 13 and 14 no longer classify from this value —
     * they read the engine's flammability block (the formula flash point);
     * this parse only feeds vetFlashPointEdit(), which drops an edit that
     * would contradict the classification before Sections 5 and 9 print it.
     * Precedence (#11, resolveFlashPointDisplay): the Section 9 edit when it reads as a
     * temperature (TemperatureParser, #8: degree sign optional, "75 F" =
     * 23.9 °C, an explicit °C wins wherever it sits, ">" sets gt, an "< n"
     * edit counts just below n), else the Q1 formula_props value (wt%-weighted
     * average) with its ">" flag. An edit that is not a temperature is
     * ignored here AND in the printed value. No value at all = no flash point
     * (Q2: not flammable).
     *
     * @return array{fp: ?float, gt: bool}
     */
    private static function resolveFlashPointNumeric(array $props, array $overrides): array
    {
        $ov = self::parsedTemperatureOverride($overrides, 'flash_point');
        if ($ov !== null) {
            return ['fp' => TemperatureParser::thresholdValue($ov), 'gt' => $ov['gt']];
        }
        if (isset($props['flash_point_c']) && $props['flash_point_c'] !== '' && $props['flash_point_c'] !== null) {
            return ['fp' => (float) $props['flash_point_c'], 'gt' => !empty($props['flash_point_greater_than'])];
        }
        return ['fp' => null, 'gt' => false];
    }

    /**
     * Q3: the inputs HazardEngine::classify() derives the Flammable Liquids
     * category from — formula_props flash point (wt%-weighted, Q1) with its
     * ">" flag, the initial boiling point (lowest raw), and the resolved
     * physical state (FG field, else dominant raw).
     */
    private static function flammabilityInputs(array $calcResult, array $fg): array
    {
        $props = $calcResult['formula_props'] ?? [];
        $state = trim((string) ($fg['physical_state'] ?? ''));
        if ($state === '') {
            $state = trim((string) ($props['physical_state'] ?? ''));
        }
        return [
            'flash_point_c'            => $props['flash_point_c'] ?? null,
            'flash_point_greater_than' => !empty($props['flash_point_greater_than']),
            'boiling_point_c'          => $props['boiling_point_c'] ?? null,
            'physical_state'           => $state,
        ];
    }

    /** Q3: the engine's flammability block, else derived from the same inputs (tests / hand-built bases). */
    private static function flammabilityOf(array $hazardResult, array $calcResult, array $fg): array
    {
        $flam = $hazardResult['flammability'] ?? null;
        return is_array($flam) && isset($flam['basis'])
            ? $flam
            : HazardEngine::flammabilityFromProps(self::flammabilityInputs($calcResult, $fg));
    }

    /** Q3: Flammable Liquids category printed in Section 2 (most severe of H224-H227), 0 when none. */
    private static function flammableCategoryFromHCodes(array $hCodes): int
    {
        foreach (['H224' => 1, 'H225' => 2, 'H226' => 3, 'H227' => 4] as $code => $cat) {
            if (in_array($code, $hCodes, true)) {
                return $cat;
            }
        }
        return 0;
    }

    /**
     * Q3 / #10: the Section 13 flammable-liquid D001 reason key, or null.
     * Only with Flam. Liq. 1–3 in the final codes. Flash point below 60 °C
     * (liquid) -> 'below 60 °C' ("> n": the "reported only as a minimum value" reason). Codes that
     * did not come from the flash point (engine category not 1–3: FG override)
     * -> hazard-based reason. Engine Cat 3 at exactly 60 °C -> null (Class 3
     * is FP <= 60, D001 is FP < 60).
     */
    private static function d001FlashReasonKey(array $hCodes, array $flam): ?string
    {
        if (array_intersect($hCodes, ['H224', 'H225', 'H226']) === []) {
            return null;
        }
        $fp = $flam['flash_point_c'] ?? null;
        if (($flam['basis'] ?? '') !== HazardEngine::FLAMMABILITY_NOT_LIQUID && $fp !== null && (float) $fp < 60.0) {
            return !empty($flam['flash_point_greater_than'])
                ? 'section13.rcra_reason_flash_point_gt'
                : 'section13.rcra_reason_flash_point';
        }
        $engineCat = (int) ($flam['category'] ?? 0);
        return ($engineCat >= 1 && $engineCat <= 3) ? null : 'section13.rcra_reason_flammable_liquid';
    }

    /**
     * Q3 (#9/#10): drop the Section 9 Flash Point text edit for this language
     * when it would contradict the classification. Sections 5 and 9 then print
     * the formula value. An edit with a number is kept only when it gives the
     * same Flammable Liquids category as the final H-codes and the same D001
     * outcome; an edit without a number is kept only when no raw material has
     * a flash point (#8 then ignores it as not a temperature and warns). Returns
     * the operator warning, or null when the edit is blank or kept.
     */
    private function vetFlashPointEdit(array &$overrides, array $hazardResult, array $calcResult, array $fg): ?string
    {
        $edit = trim((string) ($overrides[9]['flash_point'] ?? ''));
        if ($edit === '') {
            return null;
        }
        $flam     = self::flammabilityOf($hazardResult, $calcResult, $fg);
        $hCodes   = self::extractHCodes($hazardResult);
        $finalCat = self::flammableCategoryFromHCodes($hCodes);
        ['fp' => $editFp, 'gt' => $editGt] = self::resolveFlashPointNumeric([], $overrides);
        if ($editFp === null) {
            if ($flam['flash_point_c'] === null) {
                return null;
            }
            $agree = false;
        } else {
            $isLiquid = ($flam['basis'] ?? '') !== HazardEngine::FLAMMABILITY_NOT_LIQUID;
            $editCat  = $isLiquid ? HazardEngine::flammableLiquidCategory($editFp, $editGt, $flam['boiling_point_c'], $flam['physical_state']) : 0;
            $agree    = $editCat === $finalCat
                && ($isLiquid && $editFp < 60.0) === (self::d001FlashReasonKey($hCodes, $flam) !== null);
        }
        if ($agree) {
            return null;
        }
        unset($overrides[9]['flash_point']);
        $fpText  = $flam['flash_point_c'] === null
            ? 'none on any raw material'
            : (($flam['flash_point_greater_than'] ? '> ' : '') . round((float) $flam['flash_point_c'], 1) . ' °C');
        $catText = $finalCat > 0 ? 'Flammable Liquids Category ' . $finalCat : 'not a flammable liquid';
        return 'Flash point warning: the Section 9 Flash Point text edit "' . $edit . '" is not printed because it contradicts the classification on this sheet ('
            . $catText . '; formula flash point ' . $fpText . '). Sections 5 and 9 print the formula value instead. Correct the raw-material flash points, or use a finished-good hazard override if the mixture was tested. Publishing is not blocked.';
    }

    /**
     * Q3 (#44(2)): the Section 9 Initial Boiling Point text edit is display
     * only — Section 14 (PG I) and the engine (Cat 1 vs 2) both read the
     * formula boiling point. An edit that reads as a temperature but would
     * put the product on the other side of 35 °C while the sheet is Flam.
     * Liq. Category 1 or 2 is not printed (Section 9 prints the formula value).
     * Returns the operator warning, or null when the edit is blank, kept, or
     * not a temperature (#44(2) already ignores and warns about those).
     */
    private function vetBoilingPointEdit(array &$overrides, array $hazardResult): ?string
    {
        $edit = trim((string) ($overrides[9]['boiling_point'] ?? ''));
        if ($edit === '' || self::parsedTemperatureOverride($overrides, 'boiling_point') === null) {
            return null;
        }
        $finalCat = self::flammableCategoryFromHCodes(self::extractHCodes($hazardResult));
        if ($finalCat !== 1 && $finalCat !== 2) {
            return null;
        }
        $editBp  = self::resolveBoilingPointNumeric([], $overrides);
        $editCat = ($editBp !== null && $editBp <= 35.0) ? 1 : 2;
        if ($editCat === $finalCat) {
            return null;
        }
        unset($overrides[9]['boiling_point']);
        return 'Boiling point warning: the Section 9 Initial Boiling Point text edit "' . $edit . '" is not printed because it contradicts the classification on this sheet (Flammable Liquids Category '
            . $finalCat . ', decided by the formula boiling point). Section 9 prints the formula value instead; correct the raw-material boiling points. Publishing is not blocked.';
    }

    /**
     * Q3 operator warnings (never printed): the vetted Section 9 edits, and a
     * flash point below 23 °C with no initial boiling point on any raw
     * material (Category 2 / PG II assumed). Also:
     *  - a finished-good hazard override that sets a Flammable Liquids
     *    category (or removes it) the printed formula flash point does not
     *    support, with no kept Section 9 Flash Point edit — Sections 5 and 9
     *    would contradict Sections 2, 13 and 14;
     *  - raw materials that carry a constituent classified as a flammable
     *    liquid but have no flash point (left out of the Q1 average, so the
     *    product may be under-classified). Neither blocks publishing (Q2).
     * $overrides must be the vetted set (after vetFlashPointEdit()).
     * @return string[]
     */
    private function flammabilityWarnings(array $hazardResult, ?string $editWarning, ?string $bpEditWarning = null, array $overrides = [], array $calcResult = [], array $fg = []): array
    {
        $out = array_values(array_filter([$editWarning, $bpEditWarning], static fn ($w) => $w !== null));
        if (!empty($hazardResult['flammability']['ibp_assumed'])) {
            $out[] = 'Flash point warning: the product flash point is below 23 °C but no raw material carries an initial boiling point, so the product is classified Flammable Liquids Category 2 (H225) and Section 14 Packing Group II on the assumption that the boiling point is above 35 °C. Enter boiling points on the solvent raw materials to confirm. Publishing is not blocked.';
        }
        $flam = self::flammabilityOf($hazardResult, $calcResult, $fg);
        $basis = (string) ($flam['basis'] ?? '');

        // Override category vs the printed formula flash point (Q3: Sections
        // 2, 5, 9, 13 and 14 must agree). A kept Section 9 edit already agrees
        // with the final codes (vetFlashPointEdit()).
        if ($basis === HazardEngine::FLAMMABILITY_FLASH_POINT && ($flam['flash_point_c'] ?? null) !== null
            && self::parsedTemperatureOverride($overrides, 'flash_point') === null) {
            $finalCat  = self::flammableCategoryFromHCodes(self::extractHCodes($hazardResult));
            $engineCat = (int) ($flam['category'] ?? 0);
            if ($finalCat !== $engineCat) {
                $fpText = (!empty($flam['flash_point_greater_than']) ? '> ' : '') . round((float) $flam['flash_point_c'], 1) . ' °C';
                $out[] = 'Flash point warning: the finished-good hazard override gives '
                    . ($finalCat > 0 ? 'Flammable Liquids Category ' . $finalCat : 'no Flammable Liquids class')
                    . ', but Sections 5 and 9 print the formula flash point ' . $fpText . ', which gives '
                    . ($engineCat > 0 ? 'Category ' . $engineCat : 'not a flammable liquid')
                    . '. Enter the tested flash point as a Section 9 Flash Point edit (SDS > Edit Text) so every section agrees. Publishing is not blocked.';
            }
        }

        // Raw materials that carry a flammable-liquid constituent but have no
        // flash point (Q1/Q2: left out of the product average).
        if ($basis === HazardEngine::FLAMMABILITY_FLASH_POINT || $basis === HazardEngine::FLAMMABILITY_NO_FLASH_POINT) {
            $flamCas = array_map('strval', array_keys((array) ($hazardResult['flammable_ingredients'] ?? [])));
            if ($flamCas !== []) {
                $rmFp = [];
                foreach (($calcResult['formula_props']['enriched_lines'] ?? []) as $line) {
                    $id = (int) ($line['raw_material_id'] ?? 0);
                    if ($id > 0) {
                        $v = $line['flash_point_c'] ?? null;
                        $rmFp[$id] = ($v !== null && $v !== '' && is_numeric($v));
                    }
                }
                $blank = [];
                foreach (($calcResult['composition'] ?? []) as $c) {
                    if (!in_array((string) ($c['cas_number'] ?? ''), $flamCas, true)) {
                        continue;
                    }
                    foreach ((array) ($c['contributing_materials'] ?? []) as $cm) {
                        $id = (int) ($cm['raw_material_id'] ?? 0);
                        if ($id > 0 && isset($rmFp[$id]) && $rmFp[$id] === false) {
                            $blank[(string) ($cm['internal_code'] ?? ('#' . $id))] = true;
                        }
                    }
                }
                if ($blank !== []) {
                    $codes = array_keys($blank);
                    sort($codes, SORT_STRING);
                    $out[] = 'Flash point warning: raw material(s) ' . implode(', ', $codes)
                        . ' contain an ingredient classified as a flammable liquid but have no flash point, so they are left out of the product flash point (owner decisions Q1/Q2) and the product may be under-classified in Sections 2, 5, 7, 9, 10, 13 and 14. Enter the flash point on those raw materials. Publishing is not blocked.';
                }
            }
        }
        return $out;
    }

    /**
     * CAS numbers of disclosed constituents contributed by a raw material whose
     * flash point is <= 60 °C. This is the Class 3 technical-name fallback
     * (49 CFR 172.203(k)), used when no constituent carries a Flam. Liq. code
     * (owner decision Q3: the class comes from the product flash point).
     */
    private static function flammableConstituentCas(array $calcResult): array
    {
        $flamRm = [];
        foreach (($calcResult['formula_props']['enriched_lines'] ?? []) as $line) {
            $v = $line['flash_point_c'] ?? null;
            if ($v !== null && $v !== '' && is_numeric($v) && (float) $v <= 60.0) {
                $flamRm[(int) ($line['raw_material_id'] ?? 0)] = true;
            }
        }
        if ($flamRm === []) {
            return [];
        }
        $cas = [];
        foreach (($calcResult['composition'] ?? []) as $c) {
            foreach ((array) ($c['contributing_materials'] ?? []) as $cm) {
                if (isset($flamRm[(int) ($cm['raw_material_id'] ?? 0)])) {
                    $cas[] = (string) ($c['cas_number'] ?? '');
                    break;
                }
            }
        }
        return array_values(array_unique(array_filter($cas, static fn ($v) => $v !== '')));
    }

    /**
     * Section 14 — Transport Information (audit #27).
     *
     * Derived by TransportClassifier from the engine's Flammable Liquids
     * codes (Q3: derived from the product flash point / IBP — the
     * hazardResult['flammability'] block, Q1 wt%-weighted average, ">" flag,
     * none = no flash point, Q2; never the Section 9 edits, which
     * vetFlashPointEdit / vetBoilingPointEdit drop when they contradict it;
     * that block's flash point feeds only the combustible note), the initial
     * boiling point (the same block, #16), the
     * engine's resolved H-codes and the finished good's
     * transport_product_type / description / family.
     * Per-product text_overrides (section 14) WIN over the derivation.
     * 'status' is not printed: the publish gates read it
     * (SDSReadinessService::transportNotDeterminedError).
     * 'status_reason' / 'status_codes' (only while not determined or
     * override_incomplete) feed the gate message (audit #45).
     * 'technical_names_missing' (present only when true) is not printed
     * either: generate() turns it into an operator warning and drops it.
     * Findings #7: a UN-number or hazard-class override needs all four core
     * fields (UN number, PSN, class, PG). A PSN and/or PG override may stand
     * alone only when the classifier itself returned 'regulated'. Anything less
     * is status 'override_incomplete' (publish block). Under an override, only
     * the carrier note prints.
     * Finding #43: 'transport_in_bulk' and 'special_precautions' (HazCom
     * App. D 14(f)/(g)) print on every sheet.
     */
    private function section14(array $fg, array $calcResult, array $hazardResult, array $overrides): array
    {
        $props = $calcResult['formula_props'] ?? [];

        // Q3 (#9): the flash point / IBP the engine classified from — never the
        // Section 9 text edits. Class 3 itself is read from the engine's H224-H226.
        $flam = self::flammabilityOf($hazardResult, $calcResult, $fg);

        $physicalState = trim((string) ($fg['physical_state'] ?? ''));
        if ($physicalState === '') {
            $physicalState = (string) ($props['physical_state'] ?? '');
        }

        $r = TransportClassifier::classify([
            'flash_point_c'            => $flam['flash_point_c'],
            'flash_point_greater_than' => $flam['flash_point_greater_than'],
            'boiling_point_c'          => $flam['boiling_point_c'], // Q3: same IBP as the engine's Cat 1 / 2
            'h_codes'                  => self::extractHCodes($hazardResult),
            'hazard_classes'           => $hazardResult['hazard_classes'] ?? [],
            'cas_h_codes'              => TransportClassifier::casHCodeMap($hazardResult),
            'composition'              => $calcResult['composition'] ?? [],
            'flammable_cas'            => self::flammableConstituentCas($calcResult),
            'physical_state'           => $physicalState,
            'product_type'             => $fg['transport_product_type'] ?? null,
            'description'              => (string) ($fg['description'] ?? ''),
            'family'                   => $fg['family'] ?? null,   // ignored since finding #44(3)
        ]);

        $notRegulated  = $this->t->get('labels.not_regulated');
        $notApplicable = $this->t->get('labels.not_applicable');
        $notDetermined = $this->t->get('labels.not_determined');
        $marine        = $this->t->get($r['marine_pollutant'] ? 'section14.marine_pollutant_yes' : 'section14.marine_pollutant_no');

        if ($r['status'] === TransportClassifier::STATUS_NOT_DETERMINED) {
            $un = $psn = $class = $pg = $env = $notDetermined;
        } elseif ($r['status'] === TransportClassifier::STATUS_NOT_REGULATED) {
            $un = $psn = $class = $notRegulated;
            $pg  = $notApplicable;
            $env = $marine;
        } else {
            $un    = (string) $r['un_number'];
            $psn   = $this->t->get('section14.psn_' . $r['psn_key']);
            if (!empty($r['technical_names'])) {
                $psn .= ' (' . implode(', ', $r['technical_names']) . ')';
            }
            $class = (string) $r['hazard_class'];
            $pg    = (string) $r['packing_group'];
            $env   = $marine;
        }

        // Per-product override wins (inverted precedence, #27). Findings #7: the
        // operator's determination needs UN number, proper shipping name, hazard
        // class and packing group. On a product the classifier itself regulated,
        // the packing group (173.121(b)(1) viscous reassignment) and/or the
        // proper shipping name (technical names) may be overridden alone, because
        // the derived UN / class are real values (TextOverrideService never
        // stores text equal to them). A UN-number or hazard-class override needs
        // all four. Anything less → 'override_incomplete' (publish block).
        $ovText = static fn (string $key): string => trim((string) ($overrides[14][$key] ?? ''));
        $ov     = static fn (string $key, string $auto): string => $ovText($key) !== '' ? $ovText($key) : $auto;
        $core   = ['un_number', 'proper_shipping_name', 'hazard_class', 'packing_group'];
        $typed  = array_values(array_filter($core, static fn (string $k): bool => $ovText($k) !== ''));
        $status = $r['status'];
        if ($typed !== []) {
            $complete = count($typed) === count($core)
                || ($r['status'] === TransportClassifier::STATUS_REGULATED
                    && !in_array('un_number', $typed, true)
                    && !in_array('hazard_class', $typed, true));
            $status = $complete ? TransportClassifier::STATUS_OVERRIDE : TransportClassifier::STATUS_OVERRIDE_INCOMPLETE;
            if ($env === $notDetermined) {
                $env = $marine;   // the operator's determination: marine pollutant from the H-codes
            }
        }

        // Findings #7: the derived notes (combustible / viscous) explain the derived
        // classification only. Under an override, only the carrier note prints.
        $noteParts = [];
        if ($status !== TransportClassifier::STATUS_OVERRIDE && $status !== TransportClassifier::STATUS_OVERRIDE_INCOMPLETE) {
            foreach ($r['notes'] as $noteKey) {
                $noteParts[] = $this->t->get('section14.note_' . $noteKey);
            }
        }
        $noteParts[] = $this->t->get('section14.note');

        $out = [
            'title'                 => $this->t->get('section14.title'),
            'un_number'             => $ov('un_number', $un),
            'proper_shipping_name'  => $ov('proper_shipping_name', $psn),
            'hazard_class'          => $ov('hazard_class', $class),
            'packing_group'         => $ov('packing_group', $pg),
            'environmental_hazards' => $ov('environmental_hazards', $env),
            // HazCom App. D 14(f) / 14(g) (finding #43): standard wording on every sheet.
            'transport_in_bulk'     => $this->t->get('section14.transport_in_bulk_text'),
            'special_precautions'   => $this->t->get('section14.special_precautions_text'),
            'note'                  => implode(' ', $noteParts),
            'status'                => $status,   // not printed; publish gate
            // The shared Sections 12-15 footnote is emitted once, on section15().
        ];
        // Audit #45: why the sheet cannot be issued. Not printed; the
        // publish-gate message (SDSReadinessService::transportNotDeterminedError)
        // reads it. Present only while publishing is blocked, so the regular
        // key set is unchanged.
        if ($status === TransportClassifier::STATUS_NOT_DETERMINED) {
            $out['status_reason'] = $r['reason'] ?? null;
            if (!empty($r['reason_codes'])) {
                $out['status_codes'] = array_values(array_map('strval', (array) $r['reason_codes']));
            }
        } elseif ($status === TransportClassifier::STATUS_OVERRIDE_INCOMPLETE) {
            $out['status_reason'] = TransportClassifier::REASON_OVERRIDE_INCOMPLETE;
        }
        // n.o.s. entry with no derivable technical names (49 CFR 172.203(k)) and
        // no PSN override: generate() turns this into an operator warning and
        // drops the key before the snapshot. Present only when true.
        if (!empty($r['technical_names_missing']) && $ovText('proper_shipping_name') === '') {
            $out['technical_names_missing'] = true;
        }
        return $out;
    }

    /**
     * Finding #27: every raw material id in the formula tree (FormulaCalcService
     * enriched_lines expand sub-assemblies; the resale path has one synthetic
     * line), for the TSCA constituent-completeness check. [] = no DB.
     *
     * @return int[]
     */
    private static function formulaRawMaterialIds(array $calcResult): array
    {
        $ids = [];
        foreach ((array) ($calcResult['formula_props']['enriched_lines'] ?? $calcResult['formula']['lines'] ?? []) as $line) {
            $id = (int) ($line['raw_material_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        return array_keys($ids);
    }

    private function section15(array $hazardResult, array $saraResult, array $prop65Result, array $hapResult, array $calcResult, array $overrides): array
    {
        // State regulations (audit #31). The operator's per-product note
        // (text_overrides, section 15 / state_regs) is the only source and
        // prints whenever present, independently of the Prop 65 block, which
        // has its own heading and warning text. There is no admin-settings
        // default for this line, so '' means the renderers omit it.
        $stateRegs = trim((string) ($overrides[15]['state_regs'] ?? ''));

        // SNUR analysis — check formula components against snur_list + manual flags
        $snurResult = $this->analyseSnur($calcResult);

        // Audit #42: Section 15 prints regulatory detail, never an exact
        // percentage. SARA 313 reportable entries and HAP entries carry the
        // Section 3 prescribed-range band for their CAS (concentration_pct is
        // removed from these Section 15 copies; the SARA copy keeps only
        // 'reportable' — below_threshold is Section 12's PBT input, not
        // Section 15 content); the Prop 65 block lists each chemical with its
        // OEHHA listing type(s). The top-level *_result copies in the
        // snapshot are slimmed the same way at the assembly points.
        $compByCas = self::compositionByCas($calcResult['composition'] ?? []);
        $saraResult = ['reportable' => $this->bandRegulatoryEntries($saraResult['reportable'] ?? [], $compByCas)];
        $hapResult['hap_chemicals']   = $this->bandRegulatoryEntries($hapResult['hap_chemicals'] ?? [], $compByCas);
        // Finding #70: the HAP total prints as a prescribed-range band like every
        // other percentage (an exact total under banded rows allowed back-calculation).
        $hapResult['total_hap_range'] = !empty($hapResult['has_haps'])
            ? $this->formatConcentration(['concentration_pct' => (float) ($hapResult['total_hap_pct'] ?? 0)])
            : '';
        unset($hapResult['total_hap_pct']);
        // Audit #13 (decision Q4): Section 3 withholds trade-secret identities; the
        // SARA 313, HAP and SNUR lines print the trade-secret name and the masked
        // CAS instead (bands were attached above from the real CAS). The SARA
        // category code and the SNUR rule citation identify the chemical, so they
        // are blanked; SNUR gets the generic translated description.
        $saraResult['reportable'] = array_map(
            fn (array $e): array => $this->maskTradeSecretRow($e, $compByCas, ['chemical_name', 'sara_name'], ['category_code']),
            $saraResult['reportable']
        );
        $hapResult['hap_chemicals'] = array_map(
            fn (array $e): array => $this->maskTradeSecretRow($e, $compByCas, ['chemical_name', 'hap_name']),
            $hapResult['hap_chemicals']
        );
        foreach ($snurResult['listed_chemicals'] ?? [] as $i => $chem) {
            $masked = $this->maskTradeSecretRow($chem, $compByCas, ['chemical_name'], ['rule_citation']);
            if (!empty($masked['is_trade_secret'])) {
                $masked['description'] = $this->t->get('section15.snur_trade_secret');
            }
            $snurResult['listed_chemicals'][$i] = $masked;
        }
        $prop65Result['listed_lines'] = $this->buildProp65ListedLines($prop65Result);
        unset($prop65Result['listed_chemicals'], $prop65Result['trade_secret_conflicts']); // Q4: operator data, never printed
        // #37 The printed warning follows the sheet language: the computed
        // base (and Prop65Service::analyse) carries English text.
        if (!empty($prop65Result['requires_warning'])
            && (!empty($prop65Result['cancer_chemicals']) || !empty($prop65Result['repro_chemicals']))) {
            $prop65Result['warning_text'] = $this->rebuildProp65Warning(
                $prop65Result['cancer_chemicals'] ?? [],
                $prop65Result['repro_chemicals'] ?? []
            );
        }

        // TSCA (audit #29): every constituent CAS is resolved against the EPA
        // inventory + the per-CAS override on cas_master (TSCAService). The
        // "all listed/exempt" sentence prints only when every CAS is covered
        // and nothing is unverifiable; otherwise the "not verified" sentence
        // prints and TSCAService::warningText() raises an operator warning
        // (never a publish block). Q12: the sentence is not editable per
        // product; a stored 15.tsca_status row is ignored (pass 2 of the
        // override cleanup deletes it). DB-free for an empty composition.
        $tsca = TSCAService::analyse($calcResult['composition'] ?? [], self::formulaRawMaterialIds($calcResult));   // #27

        return [
            'title'          => $this->t->get('section15.title'),
            'osha_status'    => $this->resolveOshaStatus($hazardResult, $overrides),
            'tsca_status'    => $this->t->get(TSCAService::statusKey($tsca)),
            'tsca'           => $tsca,
            'sara_313'       => $saraResult,
            'prop65'         => $prop65Result,
            'hap'            => $hapResult,
            'snur'           => $snurResult,
            'state_regs'     => $stateRegs,
            'ghs_note'       => $this->ghsSectionNote(),
        ];
    }

    /**
     * Section 15 OSHA status sentence (audit #28). Q12: not editable per
     * product. It always follows the same classification outcome Section 2
     * prints; a stored 15.osha_status row is ignored. $overrides is kept for
     * call compatibility.
     */
    private function resolveOshaStatus(array $hazard, array $overrides): string
    {
        return $this->t->get(self::isClassified($hazard)
            ? 'section15.osha_status'
            : 'section15.osha_status_not_classified');
    }

    private function section16(array $calcResult, array $overrides): array
    {
        // 'version' / 'effective_date' start as the draft placeholders. Every
        // publisher replaces them through stampPublishedVersion() right before
        // rendering, with the same version number and effective date it writes
        // to sds_versions / private_label_sds (29 CFR 1910.1200 App. D §16:
        // date of preparation or last revision). Nothing else about the
        // revision is printed: no generation timestamp, change summary or
        // formula version. VOC calculation assumptions are applied silently
        // and never printed (audit #42).
        return [
            'title'          => $this->t->get('section16.title'),
            'version'        => $this->t->get('section16.draft'),
            'effective_date' => '',
            'abbreviations'  => '', // filled by AbbreviationService::build() once every section exists (audit #33)
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

    /**
     * Per-product text overrides for one language: finished-good rows
     * (finished_good_id) or, for a resale sheet, raw-material rows
     * (raw_material_id, finished_good_id NULL; audit #45). Blank rows and
     * retired / unknown keys are never applied (audit #56, Q12:
     * TextOverrideService::effective()).
     */
    private function getOverrides(int $fgId, string $language, int $rawMaterialId = 0): array
    {
        if ($this->ignoreOverrides) {
            return []; // audit #36: caller wants the automatic text
        }
        if ($this->presetOverrides !== null) {
            return $this->presetOverrides; // audit #57: editor hints / cleanup defaults
        }
        if ($fgId <= 0 && $rawMaterialId <= 0) {
            return [];
        }
        $db   = Database::getInstance();
        $rows = $fgId > 0
            ? $db->fetchAll(
                "SELECT section_number, field_key, override_text
                 FROM text_overrides
                 WHERE finished_good_id = ? AND language = ? AND sds_version_id IS NULL
                 ORDER BY section_number, field_key",
                [$fgId, $language]
            )
            : $db->fetchAll(
                "SELECT section_number, field_key, override_text
                 FROM text_overrides
                 WHERE raw_material_id = ? AND finished_good_id IS NULL AND language = ? AND sds_version_id IS NULL
                 ORDER BY section_number, field_key",
                [$rawMaterialId, $language]
            );

        $overrides = [];
        foreach ($rows as $row) {
            $overrides[(int) $row['section_number']][$row['field_key']] = $row['override_text'];
        }
        return TextOverrideService::effective($overrides);
    }

    /**
     * SDS content policy — concentration disclosure (audit item #8).
     * Fixed by code, not by settings. Keep this comment and
     * docs/operations.md ("SDS content policy") in step.
     *
     * 1. Disclosure cut-off: 0.1 % w/w of the finished good. A constituent
     *    is listed in Section 3 (and in the Section 11 component block) only
     *    at >= 0.1 % (section3(): `$conc < 0.1`; section11():
     *    CarcinogenService::LISTING_THRESHOLD_PCT), and only if it is
     *    classified as hazardous or has an occupational exposure limit on
     *    file (29 CFR 1910.1200 Appendix D, Section 3(c)). No per-ingredient
     *    flag can hide a row (the raw-material "non-hazardous" checkbox was
     *    removed, audit #17). 0.1 % is the lowest ingredient cut-off in
     *    Appendix A, so nothing that can drive a classification is hidden.
     *    Section 8 lists the OELs of every constituent HazardEngine loads
     *    (>= 0.01 %); rows below 0.1 % print "<0.1%".
     *
     * 2. Prescribed-range bands: exact percentages are never printed. Every
     *    Section 3 row, the Section 8 Conc% column, the Section 11
     *    carcinogenicity line and component block, and the Section 12
     *    component aquatic table (all of which reuse the Section 3 band for
     *    the same CAS via section8()/section11()/section12()) show the WIDEST
     *    range below that fully contains the constituent's [min, max]
     *    (Formula::getExpandedComposition() sums every contribution's lower
     *    and upper bound through nested finished goods, exact contributions
     *    adding the same value to both; resale sheets use the same bounds);
     *    if no single range contains it, the range containing max with the
     *    lowest lower end, so the upper end is never below the real maximum
     *    (audit #16). The table is the set of prescribed
     *    concentration ranges in 29 CFR 1910.1200(i)(1) as amended by the
     *    May 2024 HazCom final rule (89 FR 44144), identical to Canada's
     *    HPR s. 5.7(1). Section 15 SARA 313 / HAP components print the same
     *    band (audit #42; the band's upper end is the 40 CFR 372.45(f)
     *    upper-bound concentration), and so does the HAP total (finding #70).
     */
    private const PRESCRIBED_RANGES = [
        [0.1, 1], [0.5, 1.5], [1, 5], [3, 7], [5, 10], [7, 13],
        [10, 30], [15, 40], [30, 60], [45, 70], [60, 80], [65, 85], [80, 100],
    ];

    /**
     * Format a concentration for Section 3 and Section 8 display.
     * Maps [concentration_min, concentration_max] (every composition row
     * carries both since audit #16; a hand-built row with only
     * concentration_pct counts as [pct, pct]) onto PRESCRIBED_RANGES per the
     * policy above: the widest range containing [min, max]; otherwise the
     * range containing max with the lowest lower end, so the printed upper
     * end is never below the real maximum. Returns e.g. "10 - 30%" or "<0.1%".
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
        if ($min > $max) {
            [$min, $max] = [$max, $min];   // supplier min/max entered the wrong way round
        }

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

        // #16: no single range covers [min, max] (very wide supplier range, or
        // min below 0.1 %). Take the range containing max with the lowest lower
        // end: the upper end is never below the maximum present (Section 15
        // states the band's upper end is the upper-bound concentration).
        if ($best === null) {
            $bestLo = INF;
            foreach (self::PRESCRIBED_RANGES as [$lo, $hi]) {
                if ($lo <= $max && $max <= $hi && $lo < $bestLo) {
                    $best = [$lo, $hi];
                    $bestLo = $lo;
                }
            }
        }

        if ($best === null) {
            return '80 - 100%';   // max above 100 % (rounding): the top band
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
            'pictograms', 'ghs_classification', 'hazard_statements',   // #40 Section 2 list of H-codes without a class line
            'physical_hazards', 'health_hazards', 'environmental_hazards',
            'precautionary_statements', 'ppe_recommendations', 'other_hazards',
            'ppe_wear_eye', 'ppe_wear_gloves', 'ppe_wear_respiratory', 'ppe_wear_skin',
            // Section 3
            'type', 'cas_number', 'chemical_name', 'concentration',
            'hazardous_only_note', 'no_hazardous_note', 'mixture', 'substance',
            'h_codes',   // #37 composition table header
            // Sections 4-7 and 11 UV acrylate rule-pack note heading (audit #35; #37: was never loaded)
            'uv_acrylate_note',
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
            // Section 9 (#18(c): voc_less_we / solids_vol_pct dropped)
            'physical_state', 'color',
            'appearance', 'odor', 'boiling_point', 'flash_point', 'solubility',
            'specific_gravity', 'voc_lb_gal', 'voc_wt_pct',
            'solids_wt_pct',
            // #43 / Q8 Appendix D lines (SECTION9_APPENDIX_D_FIELDS)
            'odor_threshold', 'ph', 'melting_point', 'evaporation_rate', 'flammability_solid_gas',
            'flammability_limits', 'vapor_pressure', 'vapor_density', 'partition_coefficient',
            'auto_ignition_temp', 'decomposition_temp', 'viscosity',
            // Section 10
            'reactivity', 'chemical_stability', 'conditions_avoid',
            'incompatible_materials', 'decomposition_products',
            // Section 11
            'acute_toxicity', 'chronic_effects', 'carcinogenicity',
            'component_tox_data',
            'routes_of_exposure', // Q8 (Section 11 symptoms reuse 'symptoms_effects', listed under Section 4)
            // Section 12
            'ecotoxicity', 'persistence', 'bioaccumulation', 'mobility',
            'component_ecotox_data', 'aquatic_acute', 'aquatic_chronic', 'm_factor',
            // Section 13
            'disposal_methods', 'rcra_classification',
            // Section 14
            'un_number', 'proper_shipping_name', 'transport_hazard_class', 'packing_group',   // #27: 'environmental_hazards' label is shared with Section 2 (listed above)
            'transport_in_bulk', 'special_precautions',   // finding #43: App. D 14(f) / 14(g)
            // Section 15
            'osha_status', 'tsca_status', 'sara_313_title',
            'sara_313_statement', 'sara_313_none', 'sara_313_threshold', 'sara_313_pbt_no_deminimis',   // #26 (labels.sara_313_pbt is no longer printed)
            'sara_313_special_concern_no_deminimis',   // TRI PFAS: chemical of special concern, not PBT
            'hap_title', 'hap_triggering', 'hap_wt_pct', 'hap_total', 'hap_none',
            'prop65_title', 'prop65_none', 'prop65_listed', 'sara_313_range_note', 'snur_title', 'state_regulations',
            // Section 16
            'version', 'effective_date', 'abbreviations', 'disclaimer',
            // Generic
            'not_determined', 'not_regulated', 'not_applicable', 'note',
            'none', 'company_logo_alt', 'prop65_pictogram_alt',   // #37 renderer strings
            'uv_acrylate_note', 'trade_secret', 'trade_secret_cas', // #37 uv_acrylate_note was never carried (renderers fell back to English)
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
        // Key list lives in SDSDocumentStrings::DEFAULTS (audit #39): one
        // place for the document strings and their fallbacks.
        $strings = [];
        foreach (array_keys(SDSDocumentStrings::DEFAULTS) as $key) {
            $strings[$key] = $this->t->get('document.' . $key);
        }
        return $strings;
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

    /**
     * Audit #18: true when any raw-material line (formula_props.enriched_lines)
     * is not Solid/Powder — the particulate of an inhalation-only CAS is then
     * bound and not airborne. A blank physical state counts as bound (same
     * rule as getSolidPowderCasInLiquidMixture / shouldSuppressInhalationOnlyProp65).
     */
    private static function isBoundMixture(array $calcResult): bool
    {
        foreach (($calcResult['formula_props']['enriched_lines'] ?? []) as $line) {
            $state = strtolower(trim((string) ($line['physical_state'] ?? '')));
            if ($state !== 'powder' && $state !== 'solid') {
                return true;
            }
        }
        return false;
    }

    /**
     * Audit #18: the inhalation-only CAS (Settings) present in a bound product,
     * cas => name, for HazardEngine::excludeInhalationOnlyCas(). Any
     * concentration. Empty for an all-powder/solid product, where the dry
     * particulate is a hazard and applyCarbonBlackLogic() applies.
     *
     * @return array<string,string>
     */
    private static function inhalationOnlyCasToExclude(array $calcResult): array
    {
        if (!self::isBoundMixture($calcResult)) {
            return [];
        }
        $inhalationOnly = self::getInhalationOnlyCas();
        $out = [];
        foreach (($calcResult['composition'] ?? []) as $c) {
            $cas = trim((string) ($c['cas_number'] ?? ''));
            if ($cas !== '' && isset($inhalationOnly[$cas])) {
                $out[$cas] = $inhalationOnly[$cas];
            }
        }
        return $out;
    }

    /**
     * Post-classify carcinogen steps: the inhalation-only powder rule, then
     * the carcinogen-registry merge (order matters — the registry merge skips
     * CAS that already carry a hazard class).
     *
     * Audit #67: a 'replace' FG hazard override is the whole Section 2
     * classification ("use only the override, discard computed"), so neither
     * step runs and nothing (H350/H351, GHS08, signal word, P-codes) is added
     * back. Registry listings still print in Section 11.
     */
    private function applyPostClassifyCarcinogenSteps(array &$hazardResult, array $calcResult, array $carcinogenResult, ?array $fgOverride): void
    {
        if (($fgOverride['mode'] ?? null) === 'replace') {
            return;
        }
        $this->applyCarbonBlackLogic($hazardResult, $calcResult);
        $this->applyCarcinogenFindings($hazardResult, $carcinogenResult);
    }

    /**
     * Inhalation-only CAS (Settings > Airborne/Unbound Particles Override;
     * default carbon black 1333-86-4, TiO2 13463-67-7) in an all-powder /
     * all-solid product: the dry particulate is an inhalation hazard, so each
     * such CAS at or above the 0.1 % carcinogen cut-off gets Carcinogenicity
     * Category 2 (H351, GHS08, Warning, class-default P-codes) unless the
     * classification already carries H350/H351.
     *
     * A bound product needs nothing here: inhalationOnlyCasToExclude() kept
     * these CAS out of HazardEngine::classify() (audit #18), so no H351,
     * signal word, P-code or exposure limit of theirs exists to strip and
     * another ingredient's Carc. 2 is never touched. Called only through
     * applyPostClassifyCarcinogenSteps() (not in replace mode, audit #67).
     */
    private function applyCarbonBlackLogic(array &$hazardResult, array $calcResult): void
    {
        if (self::isBoundMixture($calcResult)) {
            return;
        }

        $inhalationOnly = self::getInhalationOnlyCas();
        $presentCas = []; // cas => ['name' => string, 'conc' => float]
        foreach (($calcResult['composition'] ?? []) as $c) {
            $cas  = trim((string) ($c['cas_number'] ?? ''));
            $conc = (float) ($c['concentration_pct'] ?? 0);
            if ($cas === '' || !isset($inhalationOnly[$cas]) || $conc < CarcinogenService::LISTING_THRESHOLD_PCT) {
                continue;
            }
            $presentCas[$cas] = ['name' => $inhalationOnly[$cas], 'conc' => $conc];
        }
        if (empty($presentCas)) {
            return;
        }

        // Already classified as a carcinogen (engine data, CPD, another
        // ingredient or an additive override): add nothing.
        foreach (($hazardResult['h_statements'] ?? []) as $stmt) {
            if (preg_match('/^H35[01]/', (string) ($stmt['code'] ?? ''))) {
                return;
            }
        }

        $hazardResult['h_statements'][] = [
            'code' => 'H351',
            'text' => GHSStatements::hText('H351'),
        ];

        foreach ($presentCas as $cas => $info) {
            $hazardResult['hazard_classes'][] = [
                'class'              => 'Carcinogenicity',
                'category'           => 'Category 2',
                'canonical'          => GHSHazardClass::CARCINOGENICITY,
                'category_canonical' => 'Cat 2',
                'h_codes'            => ['H351'],
                'cas'                => $cas,
                'chemical'           => $info['name'],
                'concentration_pct'  => $info['conc'],
                'cutoff_pct'         => CarcinogenService::LISTING_THRESHOLD_PCT,
                'source'             => $info['name'] . ' powder logic',
            ];
            // Section 3 discloses classified constituents (hazardous_cas).
            if (!in_array($cas, $hazardResult['hazardous_cas'] ?? [], true)) {
                $hazardResult['hazardous_cas'][] = $cas;
            }
        }

        if (!in_array('GHS08', $hazardResult['pictograms'] ?? [], true)) {
            $hazardResult['pictograms'][] = 'GHS08';
        }

        if (($hazardResult['signal_word'] ?? null) === null) {
            $hazardResult['signal_word'] = 'Warning';
        }

        // Class-default P-codes for Carcinogenicity Cat 2 (GHS Rev. 7
        // Annex 3): P201, P202, P280, P308+P313, P405, P501.
        $this->appendClassDefaultPStatements($hazardResult, 'Carcinogenicity - Category 2');
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
        // Audit #41(2): inhalation-only CAS keep their Section 11 listing —
        // flagged inhalable_dust_only by removeInhalationOnlyFromResults()
        // above; their exposure limits never left HazardEngine (audit #18).
        $suppressedSet = array_diff_key($suppressedSet, self::getInhalationOnlyCas());
        if (empty($suppressedSet)) {
            return;
        }

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
     * from the Prop 65 result and flag their carcinogen findings
     * inhalable_dust_only (audit #41(2)). Called when the
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
            ? $this->rebuildProp65Warning(
                $prop65Result['cancer_chemicals'],
                $prop65Result['repro_chemicals']
            )
            : '';

        // ── Carcinogen findings (Section 11) — audit #41(2) ──
        // Kept (App. D 11 asks whether a component is listed by IARC/NTP/
        // OSHA) and flagged: Section 11 adds the inhalable-dust note and
        // applyCarcinogenFindings() never turns the listing into a class.
        foreach (($carcinogenResult['findings'] ?? []) as $i => $f) {
            if (isset($casSet[$f['cas_number'] ?? ''])) {
                $carcinogenResult['findings'][$i]['inhalable_dust_only'] = true;
            }
        }
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
     * Build the Prop 65 safe-harbor warning in the sheet language (#37):
     * section15.prop65_warning_cancer / _repro / _combined, the same text as
     * Prop65Service::WARNING_* in EN. Called after inhalation-only filtering
     * and again by section15() so the printed warning always follows
     * $this->t (computeBase() is language-free, Prop65Service::analyse()
     * builds English). A name carrying Prop65Service's ' (trace)' suffix is
     * re-rendered through section15.prop65_trace_name.
     */
    private function rebuildProp65Warning(array $cancerChems, array $reproChems): string
    {
        $localize = function (array $names): string {
            $out = [];
            foreach ($names as $name) {
                $name = (string) $name;
                if (str_ends_with($name, ' (trace)')) {
                    $name = $this->t->get('section15.prop65_trace_name', ['name' => substr($name, 0, -strlen(' (trace)'))]);
                }
                $out[] = $name;
            }
            return implode(', ', $out);
        };

        $hasCancer = !empty($cancerChems);
        $hasRepro  = !empty($reproChems);

        if ($hasCancer && $hasRepro) {
            return $this->t->get('section15.prop65_warning_combined', [
                'repro'  => $localize($reproChems),
                'cancer' => $localize($cancerChems),
            ]);
        }
        if ($hasCancer) {
            return $this->t->get('section15.prop65_warning_cancer', ['cancer' => $localize($cancerChems)]);
        }
        return $this->t->get('section15.prop65_warning_repro', ['repro' => $localize($reproChems)]);
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
            // Audit #41(2): an inhalation-only listing in a bound product is
            // reported in Section 11 only; it never classifies the mixture.
            if (!empty($finding['inhalable_dust_only'])) {
                continue;
            }
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
                // #37 Display form 'Category 1A' (a GHSStatements::categoryName
                // key, so ES/FR/DE translate it, and EN matches the engine);
                // the short machine form is carried as category_canonical.
                'category'          => str_replace('Cat ', 'Category ', $category),
                'category_canonical' => $category,
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

    /**
     * Audit #36(1): operator warnings (never printed, never a block) for a CAS
     * that one raw material declares trade secret while another discloses it.
     * The composition row is then withheld in every section; the raw materials
     * should agree. Uses the per-material is_trade_secret flag from
     * Formula::getExpandedComposition(); entries without the flag are ignored.
     *
     * @return string[]
     */
    private static function tradeSecretDisclosureWarnings(array $composition): array
    {
        $out = [];
        foreach ($composition as $c) {
            $cas = (string) ($c['cas_number'] ?? '');
            if ($cas === '' || $cas === 'TRADE_SECRET' || empty($c['is_trade_secret'])) {
                continue;
            }
            $ts   = [];
            $open = [];
            foreach ($c['contributing_materials'] ?? [] as $m) {
                if (!is_array($m) || !array_key_exists('is_trade_secret', $m)) {
                    continue;
                }
                $code = (string) ($m['internal_code'] ?? '');
                if (!empty($m['is_trade_secret'])) {
                    $ts[$code] = true;
                } else {
                    $open[$code] = true;
                }
            }
            if ($ts !== [] && $open !== []) {
                $out[] = 'Trade-secret warning: CAS ' . $cas . ' is marked as a trade secret on raw material(s) '
                    . implode(', ', array_keys($ts)) . ' but disclosed on ' . implode(', ', array_keys($open))
                    . '. The sheet withholds its identity in every section. Make the raw materials agree (clear the trade-secret box, or tick it on the others). Publishing is not blocked.';
            }
        }
        return $out;
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

        // raw_material_id => formula pct, summed over every line carrying it (finding #47)
        $pctByRm = self::pctByRawMaterial($formulaLines);

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

        $pctByRm = self::pctByRawMaterial($formulaLines);   // finding #47: summed per raw material

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
     * Finding #47: raw_material_id => summed formula % over every line that
     * carries it (a raw material on two lines, or on a line and inside a
     * sub-assembly, counts once with its total). Lines without one are ignored.
     *
     * @return array<int,float>
     */
    private static function pctByRawMaterial(array $formulaLines): array
    {
        $pct = [];
        foreach ($formulaLines as $line) {
            $rmId = (int) ($line['raw_material_id'] ?? 0);
            if ($rmId <= 0) {
                continue;
            }
            $pct[$rmId] = ($pct[$rmId] ?? 0.0) + (float) ($line['pct'] ?? 0);
        }
        return $pct;
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
        $composition = $calcResult['composition'] ?? [];

        if (empty($composition)) {
            return ['has_snur' => false, 'listed_chemicals' => []];
        }

        $casNumbers = array_filter(array_column($composition, 'cas_number'));
        if (empty($casNumbers)) {
            return ['has_snur' => false, 'listed_chemicals' => []];
        }

        // Deferred past the early returns so section15() is unit-testable
        // without a DB when the formula is empty (tests/Services/SDSGeneratorSection15StateRegsTest.php).
        $db = Database::getInstance();

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
