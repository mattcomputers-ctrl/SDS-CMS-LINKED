<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * UVAcrylateRulePack — Conservative warnings and safe-handling language
 * for UV-curable acrylate/monomer-containing products.
 *
 * This rule pack does NOT invent hazard classifications. Hazards still
 * come from federal CAS data + GHS mixture rules only. This pack:
 *
 *  1. Detects acrylate/monomer CAS numbers via synonym matching
 *  2. Supplies translated safe-handling language for SDS Sections 4, 5, 6,
 *     7 and 11 (printed under labels.uv_acrylate_note) and PPE sentences
 *     folded into the Section 8 PPE fields (audit #35)
 *  3. Adds tooltip-style warnings for formulators
 *  4. Can be toggled globally in Admin Settings
 *
 * Toggle: setting `uv_acrylate_rule_pack` = 'enabled' (a missing row counts
 * as enabled). Scope: products whose RESOLVED product family is flagged
 * UV/LED (decision #3) — the FG / RM row carries `family_is_uv` = 1 after
 * SDSGenerator::attachFamily().
 */
class UVAcrylateRulePack
{
    /** Known acrylate/monomer CAS fragments for synonym matching. */
    private const ACRYLATE_CAS_LIST = [
        '57472-68-1'  => 'DPGDA (Dipropylene Glycol Diacrylate)',
        '15625-89-5'  => 'TMPTA (Trimethylolpropane Triacrylate)',
        '4986-89-4'   => 'PETIA / PETA (Pentaerythritol Tetraacrylate)',
        '42978-66-5'  => 'DPGDA (Dipropylene Glycol Diacrylate)',
        '1680-21-3'   => 'TEGDA (Triethylene Glycol Diacrylate)',
        '13048-33-4'  => 'HDDA (1,6-Hexanediol Diacrylate)',
        '28961-43-5'  => 'IBOA (Isobornyl Acrylate)',
        '2223-82-7'   => 'EO-TMPTA',
        '96-33-3'     => 'Methyl Acrylate',
        '141-32-2'    => 'n-Butyl Acrylate',
        '818-61-1'    => 'HEA (2-Hydroxyethyl Acrylate)',
        '999-61-1'    => 'HPA (2-Hydroxypropyl Acrylate)',
        '55818-57-0'  => 'PO-TMPTA',
        '52408-84-1'  => 'DPHA',
    ];

    /** Chemical name substrings that indicate acrylate content. */
    private const ACRYLATE_NAME_PATTERNS = [
        'acrylate',
        'methacrylate',
        'acrylic',
        'diacrylate',
        'triacrylate',
        'tetraacrylate',
        'pentaacrylate',
        'hexaacrylate',
    ];

    /**
     * #37: language-free placeholder detectAcrylates() returns for a
     * trade-secret acrylate with no trade_secret_description (also the
     * English placeholder the composition itself stores for synthetic
     * trade-secret rows). It is never printed as is: getSafeHandlingLanguage()
     * maps it to labels.trade_secret in the sheet language. The operator-only
     * formulator warnings (English UI text) show it unchanged.
     */
    public const TRADE_SECRET_NAME = 'Trade Secret';

    /**
     * Global on/off switch (Admin Settings): settings.uv_acrylate_rule_pack.
     * A missing row counts as enabled (seeds/seed.php writes 'enabled');
     * only an explicit value other than 'enabled' turns the pack off.
     */
    public static function isEnabled(): bool
    {
        $db = Database::getInstance();
        $setting = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'uv_acrylate_rule_pack'");
        return !($setting && $setting['value'] !== 'enabled');
    }

    /**
     * UV product test (audit #35 / decision #3): the product's RESOLVED
     * family is flagged UV/LED. The finished-good row (or the synthesised
     * resale row) carries `family_is_uv` (bool|null from
     * SDSGenerator::attachFamily(), or 0/1) from the product-family resolver.
     * The old "family name contains UV/LED" check is gone: an item with no
     * resolved family is not a UV product. DB-free.
     */
    public static function familyIsUv(array $fg): bool
    {
        return (int) ($fg['family_is_uv'] ?? 0) === 1;
    }

    /**
     * The pack applies when it is enabled AND the product is a UV product.
     * $fg is a finished_goods row (or the resale row synthesised by
     * SDSGenerator) after SDSGenerator::attachFamily().
     */
    public static function isApplicable(array $fg): bool
    {
        return self::isEnabled() && self::familyIsUv($fg);
    }

    /**
     * Alias of isApplicable() kept for the audit #3 call sites.
     */
    public static function isApplicableForItem(array $fg): bool
    {
        return self::isApplicable($fg);
    }

    /**
     * Detect acrylate/monomer content in a composition.
     *
     * @param  array $composition  Expanded CAS composition.
     * @return array  List of detected acrylate CAS entries with names.
     */
    public static function detectAcrylates(array $composition): array
    {
        $found = [];

        foreach ($composition as $component) {
            $cas  = $component['cas_number'] ?? '';
            $name = strtolower($component['chemical_name'] ?? '');

            // A trade-secret constituent keeps its real chemical_name in the
            // composition (only Section 3 masks it): never print that identity —
            // or its CAS — in the Section 4 note (29 CFR 1910.1200(i)). Detection
            // itself is unchanged so the generic sentences still fire.
            $display = !empty($component['is_trade_secret'])
                ? (trim((string) ($component['trade_secret_description'] ?? '')) ?: self::TRADE_SECRET_NAME)
                : null;

            // Match by known CAS
            if (isset(self::ACRYLATE_CAS_LIST[$cas])) {
                $found[$cas] = $display ?? ($component['chemical_name'] ?? self::ACRYLATE_CAS_LIST[$cas]);
                continue;
            }

            // Match by name pattern
            foreach (self::ACRYLATE_NAME_PATTERNS as $pattern) {
                if (str_contains($name, $pattern)) {
                    $found[$cas] = $display ?? ($component['chemical_name'] ?? $cas);
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Translated safe-handling language printed under labels.uv_acrylate_note
     * in Sections 4, 5, 6, 7 and 11 (audit #35). Section 8 is NOT in this
     * map: its advice is folded into the PPE fields via getPpeSupplement().
     * The Section 10 "protect from UV light" condition is appended by
     * SDSGenerator::section10() for every UV product, pack or not.
     *
     * These do NOT override federal hazard data.
     *
     * The Section 4 / 11 sentences that assert a skin sensitizer ("known skin
     * sensitizers", "may cause skin sensitization") print only when the mixture
     * itself carries H317 ($isSkinSens, from the engine result): below the Skin
     * Sens. 1 cut-off, or for unclassified oligomers / acrylic polymers matched
     * by name, the *_unclassified variants print instead so Sections 4 and 11
     * cannot contradict Section 2 (and Section 11's own "criteria not met").
     *
     * @param  array              $acrylates   CAS => name pairs from detectAcrylates()
     * @param  TranslationService $t           Sheet-language translator
     * @param  bool               $isSkinSens  H317 present in the mixture classification
     * @return array  Keyed by section number (4,5,6,7,11); [] when no acrylates.
     */
    public static function getSafeHandlingLanguage(array $acrylates, TranslationService $t, bool $isSkinSens = true): array
    {
        if (empty($acrylates)) {
            return [];
        }

        // #37: the trade-secret placeholder prints as labels.trade_secret in
        // the sheet language (detectAcrylates() runs language-free).
        $tradeSecret = $t->get('labels.trade_secret');
        $display = array_map(
            static fn($n): string => strcasecmp(trim((string) $n), self::TRADE_SECRET_NAME) === 0 ? $tradeSecret : (string) $n,
            array_values($acrylates)
        );

        // array_unique: several trade-secret acrylates all display as one label.
        $names = implode(', ', array_values(array_unique($display)));
        $sfx   = $isSkinSens ? '' : '_unclassified';

        return [
            4  => $t->get('section4.uv_acrylate_note' . $sfx, ['names' => $names]),
            5  => $t->get('section5.uv_acrylate_note'),
            6  => $t->get('section6.uv_acrylate_note'),
            7  => $t->get('section7.uv_acrylate_note'),
            11 => $t->get('section11.uv_acrylate_note' . $sfx),
        ];
    }

    /**
     * UV acrylate PPE sentences, one per HazardEngine::PPE_FIELDS entry, that
     * SDSGenerator::section8() appends to the resolved PPE text (audit #35).
     *
     * @return array<string,string>  field => translated sentence
     */
    public static function getPpeSupplement(TranslationService $t): array
    {
        $out = [];
        foreach (HazardEngine::PPE_FIELDS as $field) {
            $out[$field] = $t->get('section8.uv_' . $field);
        }
        return $out;
    }

    /**
     * Return formulators' warnings (for UI tooltips and preview display).
     */
    public static function getFormulatorWarnings(array $acrylates): array
    {
        if (empty($acrylates)) {
            return [];
        }

        return [
            'This product contains UV-curable acrylate monomers/oligomers which are known skin sensitizers.',
            'Ensure adequate ventilation and PPE during handling of uncured product.',
            'Acrylate content detected: ' . implode(', ', array_values(array_unique(array_values($acrylates)))) . '.',
        ];
    }
}
