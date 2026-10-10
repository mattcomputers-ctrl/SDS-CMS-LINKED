<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * SDSReadinessService — "Is an FG ready to publish an SDS?"
 *
 * Uses the same definition of "reviewed raw material" as the bulk-publish
 * eligibility check (BulkPublishController::computeEligibleFinishedGoods),
 * so the per-FG review page and the bulk job always agree on which FGs
 * are publishable.
 *
 * An RM is "reviewed" when either:
 *   (a) audit_log has a row for it with a real user_id (a human saved
 *       the RM at least once — counts as sign-off even if no hazard
 *       data was entered), OR
 *   (b) at least one of its constituent CAS numbers has an active
 *       competent_person_determinations row.
 *
 * An FG is ready when every RM in its transitive formula tree (walking
 * through finished_good_component_id sub-assemblies) is reviewed. If
 * any is not, the FG is blocked and the unreviewed RMs are what the
 * operator needs to work through.
 */
class SDSReadinessService
{
    /**
     * Return the set of raw_material ids that count as "reviewed".
     *
     * Shared with BulkPublishController so both code paths apply the
     * same rule — if the rule changes here, bulk publish and the review
     * page move together.
     *
     * @return array<int,true>  RM id => true
     */
    public static function loadReviewedRmIds(Database $db): array
    {
        $reviewed = [];

        foreach ($db->fetchAll(
            "SELECT DISTINCT entity_id
             FROM audit_log
             WHERE entity_type = 'raw_material' AND user_id IS NOT NULL"
        ) as $r) {
            $reviewed[(int) $r['entity_id']] = true;
        }

        foreach ($db->fetchAll(
            "SELECT DISTINCT rmc.raw_material_id
             FROM raw_material_constituents rmc
             INNER JOIN competent_person_determinations cpd
                 ON cpd.cas_number = rmc.cas_number AND cpd.is_active = 1"
        ) as $r) {
            $reviewed[(int) $r['raw_material_id']] = true;
        }

        return $reviewed;
    }

    /**
     * Compute the readiness report for a single finished good.
     *
     * @return array{
     *   fg: array,
     *   has_formula: bool,
     *   formula_version: int|null,
     *   total_rms: int,
     *   reviewed_count: int,
     *   unreviewed: array<int,array{id:int,internal_code:string,supplier:?string,supplier_product_name:?string,is_direct:bool,via_fg_codes:array<string>}>,
     *   company_emergency_phone_error: string|null,
     *   tsca_warning: string|null,
     *   incomplete_composition: list<array{id:int,internal_code:string,reasons:string[]}>,
     *   fg_component_formula_warning: string|null,
     *   trade_secret_prop65_error: string|null,
     *   supplier_warnings: string[]
     * }|null  Null if the FG doesn't exist.
     */
    public static function review(int $fgId): ?array
    {
        $db = Database::getInstance();

        $fg = $db->fetch(
            "SELECT id, product_code, description, family, is_active
             FROM finished_goods
             WHERE id = ?",
            [$fgId]
        );
        if ($fg === null) {
            return null;
        }

        $formula = $db->fetch(
            "SELECT id, version
             FROM formulas
             WHERE finished_good_id = ? AND is_current = 1
             LIMIT 1",
            [$fgId]
        );

        if ($formula === null) {
            return [
                'fg'              => $fg,
                'has_formula'     => false,
                'formula_version' => null,
                'total_rms'       => 0,
                'reviewed_count'  => 0,
                'unreviewed'      => [],
                'tsca_warning'    => null,
                'incomplete_composition' => [],   // finding #27
                'company_emergency_phone_error' => self::companyEmergencyPhoneErrorFromDb($db),
                'supplier_warnings' => self::companySupplierWarningsFromDb($db),   // audit #68
            ];
        }

        $missingFgCodes = []; // #36(2): finished-good components with no current formula
        $rmContext = self::walkFormula((int) $formula['id'], $db, $missingFgCodes);

        $reviewedRms = self::loadReviewedRmIds($db);

        $total      = count($rmContext);
        $reviewed   = 0;
        $unreviewed = [];

        if ($total > 0) {
            $rmIds        = array_keys($rmContext);
            $placeholders = implode(',', array_fill(0, count($rmIds), '?'));
            $rmRows       = $db->fetchAll(
                "SELECT id, internal_code, supplier, supplier_product_name
                 FROM raw_materials
                 WHERE id IN ({$placeholders})
                 ORDER BY internal_code ASC",
                $rmIds
            );

            foreach ($rmRows as $row) {
                $id = (int) $row['id'];
                if (isset($reviewedRms[$id])) {
                    $reviewed++;
                    continue;
                }

                $ctx = $rmContext[$id];
                $unreviewed[] = [
                    'id'                    => $id,
                    'internal_code'         => $row['internal_code'],
                    'supplier'              => $row['supplier'],
                    'supplier_product_name' => $row['supplier_product_name'],
                    'is_direct'             => $ctx['is_direct'],
                    'via_fg_codes'          => array_values(array_unique($ctx['via_fg_codes'])),
                ];
            }
        }

        return [
            'fg'              => $fg,
            'has_formula'     => true,
            'formula_version' => (int) $formula['version'],
            'total_rms'       => $total,
            'reviewed_count'  => $reviewed,
            'unreviewed'      => $unreviewed,
            'tsca_warning'    => self::tscaWarningForRawMaterialIds(array_keys($rmContext), $db, $fgId),
            'incomplete_composition' => TSCAService::incompleteRawMaterials(array_keys($rmContext)),   // finding #27
            'fg_component_formula_warning' => self::fgComponentsWithoutFormulaWarning(array_values($missingFgCodes)), // #36(2) warning only
            'trade_secret_prop65_error' => self::tradeSecretProp65ForRawMaterialIds(array_keys($rmContext), $db), // Q4
            'company_emergency_phone_error' => self::companyEmergencyPhoneErrorFromDb($db),
            'supplier_warnings' => self::companySupplierWarningsFromDb($db),   // audit #68
        ];
    }

    /**
     * Readiness for a resale raw material (no formula, sold 100 % as-is).
     *
     * Same reviewed-RM rule as the FG path: the RM is ready when a user
     * has saved it at least once, or one of its constituent CAS numbers
     * has an active CAS determination. The report shape mirrors review()
     * so the view can render both without branching.
     *
     * @return array|null Null when the RM doesn't exist.
     */
    public static function reviewRawMaterial(int $rmId): ?array
    {
        $db = Database::getInstance();

        $rm = $db->fetch(
            "SELECT id, internal_code, supplier, supplier_product_name
             FROM raw_materials
             WHERE id = ?",
            [$rmId]
        );
        if ($rm === null) {
            return null;
        }

        $reviewedRms = self::loadReviewedRmIds($db);
        $isReviewed  = isset($reviewedRms[$rmId]);

        // Shape this like an FG review so the view can render one table.
        // Fake an FG-shaped row using the RM's details so existing
        // template paths ("product_code", "description", etc.) work.
        $baseCode = \SDS\Services\AliasResolver::stripPack((string) $rm['internal_code']);
        $fakeFg = [
            'id'          => null,
            'product_code' => $baseCode,
            'description'  => $rm['supplier_product_name'] ?? $baseCode,
            'family'       => null,
            'is_active'    => 1,
        ];

        // Published SDSs derived from this RM — both the RM's own base
        // SDS (alias_id NULL) and every alias-branded variant — so the
        // operator can confirm the publish landed and grab the PDFs
        // without hunting through FG SDS Lookup.
        $publishedVersions = $db->fetchAll(
            "SELECT sv.id, sv.version, sv.language, sv.published_at,
                    sv.alias_id, a.customer_code AS alias_code
             FROM sds_versions sv
             LEFT JOIN aliases a ON a.id = sv.alias_id
             WHERE sv.raw_material_id = ?
               AND sv.status = 'published'
               AND sv.is_deleted = 0
             ORDER BY sv.published_at DESC, sv.version DESC, sv.language ASC",
            [$rmId]
        );

        return [
            'fg'              => $fakeFg,
            'has_formula'     => true,          // No real formula, but the data is present — not a "missing formula" case
            'formula_version' => null,
            'is_resale'       => true,
            'resale_rm'       => [
                'id'                    => $rmId,
                'internal_code'         => $rm['internal_code'],
                'base_code'             => $baseCode,
                'supplier'              => $rm['supplier'],
                'supplier_product_name' => $rm['supplier_product_name'],
            ],
            'total_rms'       => 1,
            'reviewed_count'  => $isReviewed ? 1 : 0,
            'unreviewed'      => $isReviewed ? [] : [[
                'id'                    => $rmId,
                'internal_code'         => $rm['internal_code'],
                'supplier'              => $rm['supplier'],
                'supplier_product_name' => $rm['supplier_product_name'],
                'is_direct'             => true,
                'via_fg_codes'          => [],
            ]],
            'published_versions' => $publishedVersions,
            'tsca_warning'    => self::tscaWarningForRawMaterialIds([$rmId], $db),
            'incomplete_composition' => TSCAService::incompleteRawMaterials([$rmId]),   // finding #27
            'trade_secret_prop65_error' => self::tradeSecretProp65ForRawMaterialIds([$rmId], $db), // Q4
            'company_emergency_phone_error' => self::companyEmergencyPhoneErrorFromDb($db),
            'supplier_warnings' => self::companySupplierWarningsFromDb($db),   // audit #68
        ];
    }

    /**
     * Check for missing federal hazard data above the configured threshold.
     *
     * The ONE missing-hazard-data gate (audit #28 / Q11): manual FG and resale
     * publish (SDSController), SDS Updates republish, private-label publish and
     * the bulk / cron worker all call it. Auto-send never publishes.
     *
     * @param  array    $sdsData  Generated SDS data for one language
     * @return string|null        User-facing blocking message, or null when publishing may proceed
     */
    public static function missingHazardDataError(array $sdsData, Database $db): ?string
    {
        $blockSetting = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'sds.block_publish_missing'");
        $blockEnabled = $blockSetting ? ($blockSetting['value'] !== '0') : \SDS\Core\App::config('sds.block_publish_missing', true);

        if (!$blockEnabled) {
            return null;
        }

        $thresholdRow = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'sds.missing_threshold_pct'");
        // Audit #69 — a stored blank / 0 / out-of-range value falls back to the
        // config default instead of 0 % (which blocked every no-data CAS).
        $threshold = self::normaliseMissingThreshold(
            $thresholdRow ? (string) $thresholdRow['value'] : null,
            (float) \SDS\Core\App::config('sds.missing_threshold_pct', 1.0)
        );

        $missingCas = [];
        foreach ($sdsData['hazard_result']['trace'] ?? [] as $step) {
            if (($step['step'] ?? '') === 'no_data') {
                $cas = $step['data']['cas'] ?? null;
                $conc = $step['data']['concentration_pct'] ?? 0;
                if ($cas !== null && (float) $conc >= $threshold) {
                    $cpd = $db->fetch(
                        "SELECT id FROM competent_person_determinations WHERE cas_number = ? AND is_active = 1 LIMIT 1",
                        [$cas]
                    );
                    if (!$cpd) {
                        $missingCas[] = $cas . ' (' . round((float) $conc, 2) . '%)';
                    }
                }
            }
        }

        if (!empty($missingCas)) {
            return 'Publishing blocked: missing federal hazard data for CAS numbers at or above '
                . $threshold . '% threshold: ' . implode(', ', $missingCas)
                . '. Create a Competent Person Determination for these CAS numbers or disable the threshold in Admin Settings.';
        }

        return null;
    }

    /* ------------------------------------------------------------------
     *  Emergency phone gate (audit #2)
     *
     *  29 CFR 1910.1200 Appendix D, Section 1(d) requires an emergency
     *  phone number on every SDS. A standard SDS prints the company
     *  number (settings company.emergency_phone); a private label SDS
     *  prints the manufacturer's own number (manufacturers.emergency_phone)
     *  with NO fallback to the company number. Both are required, so a
     *  blank one blocks publishing instead of silently dropping the line.
     * ----------------------------------------------------------------*/

    private const EMERGENCY_PHONE_RULE =
        '29 CFR 1910.1200 Appendix D (Section 1(d)) requires an emergency phone number in Section 1 of every SDS.';

    /**
     * Blocking message when the company emergency phone (the value printed
     * on every standard, resale and alias SDS) is blank, else null.
     * DB-free so it can be unit-tested; see companyEmergencyPhoneErrorFromDb().
     */
    public static function companyEmergencyPhoneError(?string $value): ?string
    {
        if (trim((string) $value) !== '') {
            return null;
        }
        return 'Publishing blocked: the company emergency phone number is blank or has never been saved. '
            . self::EMERGENCY_PHONE_RULE
            . ' Enter and save it under Admin > Settings > Manufacturer Information > Emergency Phone, then publish again.';
    }

    /**
     * Same check against the settings table. Reads the stored setting only,
     * so the admin-entered value is what must exist. SDSGenerator::
     * getCompanySettings() likewise never falls back to the config.php
     * placeholder for this one key, so the preview and this gate agree.
     */
    public static function companyEmergencyPhoneErrorFromDb(Database $db): ?string
    {
        $row = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'company.emergency_phone'");
        return self::companyEmergencyPhoneError($row['value'] ?? null);
    }

    /**
     * Blocking message when a private label manufacturer has no emergency
     * phone, else null. Accepts a manufacturers row or the
     * Manufacturer::toCompanyInfo() array (both carry name + emergency_phone).
     */
    public static function manufacturerEmergencyPhoneError(array $manufacturer): ?string
    {
        if (trim((string) ($manufacturer['emergency_phone'] ?? '')) !== '') {
            return null;
        }
        $name = trim((string) ($manufacturer['name'] ?? ''));
        if ($name === '') {
            $name = 'the selected manufacturer';
        }
        return 'Publishing blocked: private label manufacturer "' . $name . '" has no emergency phone number. '
            . self::EMERGENCY_PHONE_RULE
            . ' Enter it on the manufacturer record (Manufacturers > ' . $name . ' > Emergency Phone), then publish again.';
    }

    /* ------------------------------------------------------------------
     *  Trade-secret Prop 65 gate (audit #13, owner decision Q4)
     *
     *  Vendor trade secrets are never Prop 65 chemicals (company policy): a
     *  Prop 65 chemical must be stated by name and a trade-secret identity is
     *  never printed. Prop65Service::analyse() keeps such a match off the
     *  sheet and lists it under trade_secret_conflicts; any entry blocks
     *  publishing. DB-free so it can be unit-tested.
     * ----------------------------------------------------------------*/

    /** @param array $prop65Result computeBase() 'prop65Result' or sdsData 'prop65_result' */
    public static function tradeSecretProp65Error(array $prop65Result): ?string
    {
        $conflicts = $prop65Result['trade_secret_conflicts'] ?? [];
        if (!is_array($conflicts) || $conflicts === []) {
            return null;
        }
        $parts = [];
        foreach ($conflicts as $c) {
            if (!is_array($c)) {
                continue;
            }
            $name = trim((string) ($c['chemical_name'] ?? ''));
            $cas  = trim((string) ($c['cas_number'] ?? ''));
            $rms  = array_values(array_filter(array_map('strval', (array) ($c['raw_materials'] ?? []))));
            $parts[] = ($name !== '' ? $name . ' ' : '') . '(CAS ' . $cas . ')'
                . ($rms !== [] ? ' in raw material ' . implode(', ', $rms) : '');
        }
        if ($parts === []) {
            return null;
        }
        return 'Publishing blocked: a trade-secret constituent is on the California Prop 65 list: '
            . implode('; ', $parts)
            . '. Vendor trade secrets may never be Prop 65 chemicals, and a Prop 65 chemical must be stated by name, so this sheet cannot be issued.'
            . ' Ask the vendor to disclose the chemical and clear its trade-secret box on the raw material (Raw Materials > Edit > composition), or correct the CAS number, then publish again.';
    }

    /**
     * Readiness-page / RM variant: trade-secret constituents of the given raw
     * materials whose CAS is on prop65_list, without generating the SDS.
     *
     * @param int[] $rmIds
     */
    public static function tradeSecretProp65ForRawMaterialIds(array $rmIds, Database $db): ?string
    {
        $rmIds = array_values(array_unique(array_map('intval', $rmIds)));
        if ($rmIds === []) {
            return null;
        }
        $placeholders = implode(',', array_fill(0, count($rmIds), '?'));
        $rows = $db->fetchAll(
            "SELECT rm.internal_code, rmc.cas_number, p65.chemical_name
             FROM raw_material_constituents rmc
             JOIN raw_materials rm ON rm.id = rmc.raw_material_id
             JOIN prop65_list p65 ON p65.cas_number = rmc.cas_number
             WHERE rmc.raw_material_id IN ({$placeholders})
               AND rmc.is_trade_secret = 1
               AND rmc.cas_number IS NOT NULL AND rmc.cas_number <> ''
               AND (rm.hazardous_no_cas IS NULL OR rm.hazardous_no_cas = 0)
             ORDER BY rmc.cas_number, rm.internal_code",
            $rmIds
        );
        $conflicts = [];
        foreach ($rows as $r) {
            $cas = (string) $r['cas_number'];
            $conflicts[$cas] ??= ['cas_number' => $cas, 'chemical_name' => (string) ($r['chemical_name'] ?? ''), 'raw_materials' => []];
            $conflicts[$cas]['raw_materials'][] = (string) $r['internal_code'];
        }
        return self::tradeSecretProp65Error(['trade_secret_conflicts' => array_values($conflicts)]);
    }

    /* ------------------------------------------------------------------
     *  Batch E publish gates and supplier warnings (audit #55, #68, #69)
     * ----------------------------------------------------------------*/

    private const SUPPLIER_PHONE_RULE =
        '29 CFR 1910.1200 Appendix D (Section 1(c)) requires the telephone number of the responsible party in Section 1.';

    /** Audit #69 — an inactive finished good is not published on any path. DB-free. */
    public static function inactiveFinishedGoodError(array $fg): ?string
    {
        if ((int) ($fg['is_active'] ?? 1) === 1) {
            return null;
        }
        $code = trim((string) ($fg['product_code'] ?? ''));
        return 'Publishing blocked: finished good ' . ($code !== '' ? $code : '#' . (int) ($fg['id'] ?? 0))
            . ' is inactive. Set its Status to Active (Finished Goods > Edit), then publish again. Bulk publish skips inactive products too.';
    }

    /** Audit #69 — settings-form check for the Missing Data Threshold (%). DB-free. */
    public static function missingThresholdInputError(string $raw): ?string
    {
        $v = trim($raw);
        if ($v === '' || !is_numeric($v)) {
            return 'Missing Data Threshold (%) must be a number between 0.01 and 100.';
        }
        $f = (float) $v;
        if ($f < 0.01 || $f > 100) {
            return 'Missing Data Threshold (%) must be between 0.01 and 100 (entered ' . $v . ').';
        }
        return null;
    }

    /** Audit #69 — stored threshold, or $fallback when missing / blank / out of range. DB-free. */
    public static function normaliseMissingThreshold(?string $stored, float $fallback): float
    {
        if ($stored === null || self::missingThresholdInputError($stored) !== null) {
            return $fallback;
        }
        return (float) trim($stored);
    }

    /**
     * Audit #55 — a private label manufacturer with no name prints no Section 1
     * Company line (and the PDF Author falls back). Publish gate. Accepts a
     * manufacturers row or Manufacturer::toCompanyInfo() (+ optional 'id'). DB-free.
     */
    public static function manufacturerNameError(array $manufacturer): ?string
    {
        if (trim((string) ($manufacturer['name'] ?? '')) !== '') {
            return null;
        }
        $id = (int) ($manufacturer['id'] ?? 0);
        return 'Publishing blocked: the private label manufacturer' . ($id > 0 ? ' #' . $id : '')
            . ' has no name, so Section 1 would print no Company line.'
            . ' 29 CFR 1910.1200 Appendix D (Section 1(c)) requires the name of the responsible party.'
            . ' Enter it on the manufacturer record (Manufacturers > Edit > Name), then publish again.';
    }

    /** Audit #68 — blank private label manufacturer phone: warning only. DB-free. */
    public static function manufacturerSupplierPhoneWarning(array $manufacturer): ?string
    {
        if (trim((string) ($manufacturer['phone'] ?? '')) !== '') {
            return null;
        }
        $name = trim((string) ($manufacturer['name'] ?? ''));
        if ($name === '') {
            $name = 'the selected manufacturer';
        }
        return 'Warning: private label manufacturer "' . $name . '" has no phone number, so Section 1 prints no Phone line. '
            . self::SUPPLIER_PHONE_RULE
            . ' Enter it on the manufacturer record (Manufacturers > ' . $name . ' > Phone). Publishing is not blocked.';
    }

    /**
     * Audit #68 — operator warnings (never a block, never printed) for the
     * standard-sheet supplier block. $stored: settings key => value for
     * company.name / company.phone (a missing key means never saved, so
     * SDSGenerator::getCompanySettings() prints the config.php value);
     * $config: App::config('company'). DB-free.
     *
     * @return string[]
     */
    public static function companySupplierWarnings(array $stored, array $config): array
    {
        $out = [];

        $cfgName = trim((string) ($config['name'] ?? ''));
        if (!array_key_exists('company.name', $stored) && $cfgName !== '') {
            $out[] = 'Warning: the company name has never been saved in Admin > Settings, so Section 1 and the PDF Author use the config.php value "'
                . $cfgName . '". Enter and save it under Admin > Settings > Manufacturer Information > Company Name. Publishing is not blocked.';
        } elseif (trim((string) ($stored['company.name'] ?? $cfgName)) === '') {
            $out[] = 'Warning: the company name is blank, so Section 1 prints no Company line and the PDF Author reads "SDS System".'
                . ' 29 CFR 1910.1200 Appendix D (Section 1(c)) requires the name of the responsible party.'
                . ' Enter it under Admin > Settings > Manufacturer Information > Company Name. Publishing is not blocked.';
        }

        $cfgPhone = trim((string) ($config['phone'] ?? ''));
        if (!array_key_exists('company.phone', $stored) && $cfgPhone !== '') {
            $out[] = 'Warning: the company phone has never been saved in Admin > Settings, so Section 1 prints the config.php value "'
                . $cfgPhone . '". Enter and save it under Admin > Settings > Manufacturer Information > Phone. Publishing is not blocked.';
        } elseif (trim((string) ($stored['company.phone'] ?? $cfgPhone)) === '') {
            $out[] = 'Warning: the company phone is blank, so Section 1 prints no Phone line. '
                . self::SUPPLIER_PHONE_RULE
                . ' Enter it under Admin > Settings > Manufacturer Information > Phone. Publishing is not blocked.';
        }

        return $out;
    }

    /** @return string[] companySupplierWarnings() against the settings table. */
    public static function companySupplierWarningsFromDb(Database $db): array
    {
        $stored = [];
        foreach ($db->fetchAll("SELECT `key`, `value` FROM settings WHERE `key` IN ('company.name', 'company.phone')") as $r) {
            $stored[(string) $r['key']] = (string) ($r['value'] ?? '');
        }
        $config = \SDS\Core\App::config('company', []);
        return self::companySupplierWarnings($stored, is_array($config) ? $config : []);
    }

    /* ------------------------------------------------------------------
     *  TSCA inventory check (audit #29) — a WARNING, never a block
     * ----------------------------------------------------------------*/

    /** Warning from generated SDS data (sections[15]['tsca']); DB-free. */
    public static function tscaWarning(array $sdsData): ?string
    {
        return TSCAService::warningText($sdsData['sections'][15]['tsca'] ?? []);
    }

    /**
     * Readiness-page variant: resolve from the formula tree's raw materials
     * without generating the SDS. Q12: the TSCA sentence is not editable per
     * product, so a stored 15.tsca_status row never suppresses the warning;
     * $fgId is unused (kept for callers).
     *
     * @param int[] $rmIds
     */
    public static function tscaWarningForRawMaterialIds(array $rmIds, Database $db, ?int $fgId = null): ?string
    {
        if ($rmIds === []) {
            return null;
        }
        return TSCAService::warningText(TSCAService::rollUpForRawMaterialIds($rmIds));
    }

    /**
     * Finding #27 (DB-free): operator warning for formula raw materials whose
     * constituent data is incomplete (TSCAService::incompleteRawMaterials()
     * rows). A warning, never a publish block.
     */
    public static function incompleteCompositionWarning(array $incomplete): ?string
    {
        $items = [];
        foreach ($incomplete as $row) {
            $reasons = (array) ($row['reasons'] ?? []);
            if ($reasons === []) {
                continue;
            }
            $items[] = (string) ($row['internal_code'] ?? '') . ' (' . implode('; ', array_map([TSCAService::class, 'reasonLabel'], $reasons)) . ')';
        }
        if ($items === []) {
            return null;
        }
        $n = count($items);
        return 'Composition warning: ' . $n . ' raw material' . ($n === 1 ? ' has' : 's have') . ' incomplete constituent data — '
            . implode(', ', $items)
            . '. Ingredients without a constituent row, CAS number or percentage are left out of the hazard classification (Section 2), the composition table (Section 3) and the regulatory list checks (Section 15), and Section 15 cannot state that all components are on the TSCA inventory. Enter every constituent with its CAS number and percentage (Raw Materials > Constituents). Publishing is not blocked.';
    }

    /* ------------------------------------------------------------------
     *  Section 14 transport gate (audit #27; findings #2, #4, #6, #7)
     *
     *  SDSGenerator::section14() derives the DOT classification from the
     *  engine's flammability codes and the hazard classification, and prints
     *  "Not determined" when TransportClassifier cannot decide (reason in
     *  sections[14].status_reason: three_class_precedence, unsupported_class,
     *  or legacy no_data) — a sheet that cannot be issued. It also blocks an
     *  incomplete operator override (status override_incomplete, findings
     *  #7). Overrides are stored per language, so every publisher calls this
     *  for EVERY generated language (finding #6); the message names the
     *  language from meta.language. A missing flash point never blocks (Q2).
     *  Messages are reason-coded and point resale sheets to the resale
     *  editor (audit #45). DB-free so it can be unit-tested.
     * ----------------------------------------------------------------*/

    public static function transportNotDeterminedError(array $sdsData): ?string
    {
        $s14    = $sdsData['sections'][14] ?? [];
        $status = (string) ($s14['status'] ?? '');
        if ($status !== TransportClassifier::STATUS_NOT_DETERMINED
            && $status !== TransportClassifier::STATUS_OVERRIDE_INCOMPLETE) {
            return null;
        }
        $code = trim((string) ($sdsData['meta']['product_code'] ?? ''));
        $lang = strtoupper(trim((string) ($sdsData['meta']['language'] ?? '')));
        $who  = ($code !== '' ? $code : 'this product') . ($lang !== '' ? ' (' . $lang . ' sheet)' : '');
        $what = 'Publishing blocked: the Section 14 transport classification for ' . $who . ' is "Not determined"';

        // Audit #45: a resale sheet (meta.finished_good_id 0) keeps its
        // Section 14 overrides on the raw material, not under SDS > Edit.
        $meta     = is_array($sdsData['meta'] ?? null) ? $sdsData['meta'] : [];
        $isResale = array_key_exists('finished_good_id', $meta) && (int) $meta['finished_good_id'] === 0;
        $where    = $isResale
            ? 'SDS Creation Readiness Check > "Edit SDS text" (EN/ES/FR/DE), Section 14'
            : 'SDS > Edit (EN/ES/FR/DE), Section 14';
        $record = 'record the determined UN number, proper shipping name, hazard class and packing group as Section 14 overrides ('
            . $where . ($lang !== '' ? ', ' . $lang . ' tab' : '') . '; overrides are stored per language, so repeat them on every language), then publish again.';

        if ($status === TransportClassifier::STATUS_OVERRIDE_INCOMPLETE) {
            return 'Publishing blocked: the Section 14 transport override for ' . $who . ' is incomplete.'
                . ' A UN number or hazard class override, or any override on a product the system does not itself classify as regulated, needs all four fields: '
                . $record . ' Alternatively clear the Section 14 override fields to print the derived classification.';
        }

        switch ((string) ($s14['status_reason'] ?? '')) {
            case TransportClassifier::REASON_THREE_CLASS:
                return $what . ': the product is flammable (Class 3), corrosive (Class 8) and toxic (Division 6.1), and Class 8 or 6.1 takes precedence over Class 3 (49 CFR 173.2a(b)), so no generic DOT entry fits. ' . ucfirst($record);
            case TransportClassifier::REASON_UNSUPPORTED_CLASS:
                $codes = array_values(array_filter(array_map('strval', (array) ($s14['status_codes'] ?? [])), static fn (string $c): bool => $c !== ''));
                return $what . ': the hazard classification contains '
                    . ($codes !== [] ? implode(', ', $codes) : 'the Gas physical state')
                    . ' (explosive, aerosol, gas, flammable solid, self-reactive, pyrophoric / self-heating / water-reactive, oxidizer or organic peroxide), which this system does not classify for transport (owner decision Q3). Such products are not sold, so check the raw-material hazard data first; otherwise have the DOT classification determined and '
                    . $record;
            case TransportClassifier::REASON_NO_DATA:
                return $what . ': no raw material in the formula carries a flash point and the hazard classification returned no data.'
                    . ' Enter the flash point on the raw material(s) (Raw Materials > Edit > Flash Point), or ' . $record;
            default:
                return $what . '. ' . ucfirst($record);
        }
    }

    /**
     * #36(2) Readiness WARNING (never a block): finished-good components with
     * no current formula contribute nothing to Section 3 or the hazard
     * classification (Formula::getExpandedComposition skips them). DB-free.
     *
     * @param string[] $codes
     */
    public static function fgComponentsWithoutFormulaWarning(array $codes): ?string
    {
        $codes = array_values(array_unique(array_filter(
            array_map(static fn($c): string => trim((string) $c), $codes),
            static fn(string $c): bool => $c !== ''
        )));
        if ($codes === []) {
            return null;
        }
        sort($codes, SORT_STRING);
        $one = count($codes) === 1;
        return 'Finished-good component' . ($one ? '' : 's') . ' with no current formula: ' . implode(', ', $codes)
            . '. ' . ($one ? 'Its' : 'Their') . ' ingredients are left out of Section 3 and the hazard classification until a formula is entered for '
            . ($one ? 'it' : 'each') . '. Publishing is not blocked.';
    }

    /**
     * Walk the formula tree, returning per-RM context.
     *
     * For each RM encountered, track whether it appears directly on the
     * root formula (is_direct = true) and, if not, which sub-FGs carry
     * it into this formulation.
     *
     * @param array<int,string> $missingFgCodes  out: FG id => product code of components with no current formula (#36(2))
     * @return array<int,array{is_direct:bool,via_fg_codes:array<string>}>
     */
    private static function walkFormula(int $rootFormulaId, Database $db, array &$missingFgCodes = []): array
    {
        // Batch-load lines + formula/FG lookup maps once; recursion is
        // purely in-memory from here.
        $linesByFormula = [];
        foreach ($db->fetchAll(
            "SELECT formula_id, raw_material_id, finished_good_component_id
             FROM formula_lines"
        ) as $r) {
            $linesByFormula[(int) $r['formula_id']][] = [
                'rm' => $r['raw_material_id'] !== null ? (int) $r['raw_material_id'] : null,
                'fg' => $r['finished_good_component_id'] !== null ? (int) $r['finished_good_component_id'] : null,
            ];
        }

        $fgToFormulaId = [];
        foreach ($db->fetchAll(
            "SELECT id, finished_good_id FROM formulas WHERE is_current = 1"
        ) as $r) {
            $fgToFormulaId[(int) $r['finished_good_id']] = (int) $r['id'];
        }

        $fgCodes = [];
        foreach ($db->fetchAll("SELECT id, product_code FROM finished_goods") as $r) {
            $fgCodes[(int) $r['id']] = $r['product_code'];
        }

        $rmContext = [];

        // $rootSubCode = the product_code of the top-level sub-FG that
        // carried us off the root formula, or null while we're still on
        // the root itself. Pinning it to the first sub-FG (rather than
        // updating on each recursion) means the operator sees "via
        // FG_B" — the line they would edit in the root formula — instead
        // of some deeper intermediate code.
        $walk = function (int $formulaId, ?string $rootSubCode, array $path) use (
            &$walk, $linesByFormula, $fgToFormulaId, $fgCodes, &$rmContext, &$missingFgCodes
        ): void {
            if (in_array($formulaId, $path, true)) {
                return; // cycle guard
            }
            $nextPath = array_merge($path, [$formulaId]);

            foreach ($linesByFormula[$formulaId] ?? [] as $line) {
                if ($line['rm'] !== null) {
                    $rmId = $line['rm'];
                    if (!isset($rmContext[$rmId])) {
                        $rmContext[$rmId] = ['is_direct' => false, 'via_fg_codes' => []];
                    }
                    if ($rootSubCode === null) {
                        $rmContext[$rmId]['is_direct'] = true;
                    } else {
                        $rmContext[$rmId]['via_fg_codes'][] = $rootSubCode;
                    }
                } elseif ($line['fg'] !== null) {
                    $subFormulaId = $fgToFormulaId[$line['fg']] ?? null;
                    if ($subFormulaId !== null) {
                        $nextRootSub = $rootSubCode
                            ?? ($fgCodes[$line['fg']] ?? ('FG #' . $line['fg']));
                        $walk($subFormulaId, $nextRootSub, $nextPath);
                    } else {
                        // #36(2): no current formula → its ingredients silently drop out of the SDS.
                        $missingFgCodes[$line['fg']] = $fgCodes[$line['fg']] ?? ('FG #' . $line['fg']);
                    }
                }
            }
        };

        $walk($rootFormulaId, null, []);

        return $rmContext;
    }
}
