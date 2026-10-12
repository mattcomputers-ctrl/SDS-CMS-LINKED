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
 *  1. Detects acrylate monomers/oligomers at >= 0.1 % by the cas_master
 *     acrylate flag (A12a; composition rows carry is_acrylate), falling back
 *     to the legacy CAS list (one release) or the name rule when the flag is
 *     not determined — used ONLY for the names in the Section 4 sentence
 *     (#50); the generic UV text is gated on isApplicable() (admin switch +
 *     family UV flag)
 *  2. Supplies the translated Section 4 skin sentence, the Section 8 PPE
 *     sentences (applied by SDSGenerator::resolvePPE(), #29) and the
 *     Section 11 note (labels.uv_acrylate_note). Sections 5, 6 and 7 read
 *     their UV fragments straight from the translation files (#65).
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
    /**
     * A12(a): the CAS the pre-flag release recognised (the former labelled
     * list; its labels are gone: four were wrong, for 2223-82-7, 28961-43-5,
     * 52408-84-1 and 55818-57-0, and names now come from the CAS records).
     * Used ONLY (1) as the runtime fallback for a composition row whose
     * cas_master.is_acrylate is not determined (NULL) and (2) by
     * CasAcrylateFlagSeeder to seed the flag. Keep for one release after the
     * seed has run, then delete (detection then relies on the flag plus the
     * name rule).
     */
    public const LEGACY_ACRYLATE_CAS = [
        '57472-68-1', '15625-89-5', '4986-89-4',  '42978-66-5', '1680-21-3',
        '13048-33-4', '28961-43-5', '2223-82-7',  '96-33-3',    '141-32-2',
        '818-61-1',   '999-61-1',   '55818-57-0', '52408-84-1',
    ];

    /** #50: Section 3 listing cut-off; acrylates below it are never named. */
    public const NAME_CUTOFF_PCT = 0.1;

    /**
     * #50: a reactive acrylate monomer/oligomer by name — "...acrylate",
     * "...acrylated..." or (meth)acrylic ACID. Plain "acrylic" (acrylic
     * resins/polymers) no longer matches.
     */
    private const ACRYLATE_NAME_REGEX = '/acrylate|\b(?:meth)?acrylic\s+acid\b/i';

    /** #50: non-reactive acrylic polymers that never count (prepolymers still do). */
    private const ACRYLIC_POLYMER_REGEX = '/(?<!pre)polymer|\bpoly\s*\(|poly(?:meth)?acryl|\bresin\b/i';

    /**
     * #37: language-free placeholder detectAcrylates() returns for a
     * trade-secret acrylate with no trade_secret_description (also the
     * English placeholder the composition itself stores for synthetic
     * trade-secret rows). It is never printed as is: section4SkinFragment()
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
     * A12(a): a stored / propagated is_acrylate value -> true / false, or
     * null when not determined (NULL, '' or absent). Composition rows carry
     * ?bool; DB rows carry '0' / '1' / NULL.
     */
    public static function flagValue(mixed $v): ?bool
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_bool($v)) {
            return $v;
        }
        return (int) $v === 1;
    }

    /** A12(a): on the legacy (pre-flag) CAS list. */
    public static function isLegacyAcrylateCas(string $cas): bool
    {
        $cas = trim($cas);
        return $cas !== '' && in_array($cas, self::LEGACY_ACRYLATE_CAS, true);
    }

    /**
     * #50 name rule: a reactive acrylate monomer/oligomer name ("...acrylate",
     * "...acrylated...", (meth)acrylic acid), never an acrylic polymer/resin.
     */
    public static function nameLooksLikeAcrylate(string $name): bool
    {
        $name = trim($name);
        return $name !== ''
            && preg_match(self::ACRYLATE_NAME_REGEX, $name) === 1
            && preg_match(self::ACRYLIC_POLYMER_REGEX, $name) !== 1;
    }

    /**
     * A12(a): is this component an acrylate? The cas_master flag wins
     * (1 = yes, 0 = a manual "no"); when it is not determined (NULL, no
     * registry row, CAS-less row, hand-built fixture) the pre-flag rule
     * applies: legacy CAS list (one release) or the name rule.
     */
    public static function effectiveFlag(mixed $flag, string $cas, string $name): bool
    {
        return self::flagValue($flag) ?? (self::isLegacyAcrylateCas($cas) || self::nameLooksLikeAcrylate($name));
    }

    /**
     * A12(a): the value CasAcrylateFlagSeeder proposes for a CAS: 1 when it
     * is on the legacy list or any of its names passes the name rule, else
     * null. Never 0: "not an acrylate" is a manual decision.
     *
     * @param string[] $names
     */
    public static function seedFlagFor(string $cas, array $names): ?int
    {
        if (self::isLegacyAcrylateCas($cas)) {
            return 1;
        }
        foreach ($names as $n) {
            if (self::nameLooksLikeAcrylate((string) $n)) {
                return 1;
            }
        }
        return null;
    }

    /**
     * Acrylate monomers/oligomers in a composition, CAS => display name (#50).
     * Used ONLY for the names in the Section 4 sentence; it never decides
     * whether UV text prints (isApplicable() does). Rows below
     * NAME_CUTOFF_PCT are skipped (rows without concentration_pct, e.g.
     * hand-built fixtures, count). A12(a): matched by the row's is_acrylate
     * flag (cas_master; 1 = yes, 0 = manual no); when that is not determined,
     * by the legacy CAS list (one release) or ACRYLATE_NAME_REGEX, never by
     * acrylic-polymer names. The display name is the composition name (Prop
     * 65 / CAS registry description); a nameless disclosed row shows its CAS.
     *
     * @param  array $composition  Expanded CAS composition.
     * @return array<string,string>
     */
    public static function detectAcrylates(array $composition): array
    {
        $found = [];

        foreach ($composition as $component) {
            if (!is_array($component)) {
                continue;
            }
            if (isset($component['concentration_pct'])
                && (float) $component['concentration_pct'] < self::NAME_CUTOFF_PCT) {
                continue;
            }
            $cas  = (string) ($component['cas_number'] ?? '');
            $name = trim((string) ($component['chemical_name'] ?? ''));

            // A trade-secret constituent keeps its real chemical_name in the
            // composition: never print that identity — or its CAS — (29 CFR
            // 1910.1200(i); owner decision Q4). Detection itself is unchanged.
            $display = !empty($component['is_trade_secret'])
                ? (trim((string) ($component['trade_secret_description'] ?? '')) ?: self::TRADE_SECRET_NAME)
                : null;

            // A12(a): the cas_master acrylate flag decides; not determined ->
            // legacy CAS list (one release) or the name rule.
            if (!self::effectiveFlag($component['is_acrylate'] ?? null, $cas, $name)) {
                continue;
            }
            $key = $cas !== '' ? $cas : $name;
            if ($key === '') {
                continue;
            }
            $found[$key] = $display ?? ($name !== '' ? $name : $cas);
        }

        return $found;
    }

    /**
     * #65: the Section 4 skin-contact sentence SDSGenerator::section4()
     * appends to the Skin field of a UV rule-pack product. Names (from
     * detectAcrylates(), >= 0.1 %) print only when some were recognised;
     * the trade-secret placeholder prints as labels.trade_secret.
     */
    public static function section4SkinFragment(array $acrylates, TranslationService $t): string
    {
        if ($acrylates === []) {
            return $t->get('section4.uv_skin');
        }
        $tradeSecret = $t->get('labels.trade_secret');
        $display = array_map(
            static fn($n): string => strcasecmp(trim((string) $n), self::TRADE_SECRET_NAME) === 0 ? $tradeSecret : (string) $n,
            array_values($acrylates)
        );
        // array_unique: several trade-secret acrylates all display as one label.
        return $t->get('section4.uv_skin_names', ['names' => implode(', ', array_values(array_unique($display)))]);
    }

    /**
     * #65: the Section 11 note — the only separate UV note left (printed under
     * labels.uv_acrylate_note after the component table; editable per
     * product). The sensitizer wording prints only when the mixture carries
     * H317, otherwise the *_unclassified variant.
     */
    public static function section11Note(TranslationService $t, bool $isSkinSens): string
    {
        return $t->get('section11.uv_acrylate_note' . ($isSkinSens ? '' : '_unclassified'));
    }

    /**
     * UV acrylate PPE sentences, one per HazardEngine::PPE_FIELDS entry, that
     * SDSGenerator::resolvePPE() appends to a field whose tier is hazard-driven or when the mixture carries H317 (audit #35, #29).
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
