<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * RegulatoryCategoryService — finding #26. The SARA 313 (EPCRA 313 / TRI,
 * 40 CFR 372.65(c)) and Clean Air Act 112(b) HAP lists define CATEGORIES
 * (metal compounds, glycol ethers) whose members carry their own CAS numbers
 * and are not rows on sara313_list / hap_list. A constituent CAS belongs to a
 * category (regulatory_categories, migration 058) when:
 *   - it is an explicit 'include' row in regulatory_category_members; or
 *   - the category names an element (element_symbol) and the CAS's Hill
 *     formula (cas_master.molecular_formula) contains that element, or, with
 *     no formula on file, a constituent / cas_master name contains one of the
 *     category's whole-word name_keywords;
 * and it is not an 'exclude' row for that category (e.g. TRI copper
 * compounds exclude C.I. Pigment Blue 15 / Green 7 / Green 36; barium
 * compounds exclude barium sulfate). TRI N100 also excludes, by structure,
 * every copper phthalocyanine substituted only with hydrogen, chlorine and/or
 * bromine (40 CFR 372.65(c) as amended, 60 FR 18350, 1995 — e.g. the
 * chlorinated PB 15:1 / 15:2, 12239-87-1), whatever its CAS:
 * isExcludedCopperPhthalocyanine(). resolve() is DB-free (unit-tested).
 */
final class RegulatoryCategoryService
{
    public const LIST_SARA = 'sara313';
    public const LIST_HAP  = 'hap';

    /** Element symbols in a Hill formula: 'C32H16CuN8' -> ['C','H','Cu','N']; 'CO' -> ['C','O']. */
    public static function elementsInFormula(?string $formula): array
    {
        $formula = trim((string) $formula);
        if ($formula === '') {
            return [];
        }
        preg_match_all('/[A-Z][a-z]?/', $formula, $m);
        return array_values(array_unique($m[0]));
    }

    /** True when any name contains one of the comma-separated keywords as a whole word (case-insensitive). */
    public static function nameMatches(?string $keywords, array $names): bool
    {
        $words = array_values(array_filter(array_map('trim', explode(',', strtolower((string) $keywords)))));
        if ($words === []) {
            return false;
        }
        $re = '/\b(?:' . implode('|', array_map(static fn (string $w): string => preg_quote($w, '/'), $words)) . ')\b/iu';
        foreach ($names as $n) {
            if (preg_match($re, (string) $n) === 1) {
                return true;
            }
        }
        return false;
    }

    /** TRI copper compounds category code (N100). */
    public const COPPER_COMPOUNDS = 'N100';

    /**
     * 40 CFR 372.65(c) N100 (60 FR 18350, 1995): copper phthalocyanine
     * compounds substituted only with hydrogen, chlorine and/or bromine are
     * NOT copper compounds. With a Hill formula: a C32 / N8 core containing
     * Cu whose elements are a subset of {C, H, N, Cu, Cl, Br} (sulfonated or
     * other substituted dyes carry S / O / Na ... and stay in N100). With no
     * formula: a "phthalocyanin..." name with no sulfo / sulpho / amino /
     * methyl substituent wording.
     */
    public static function isExcludedCopperPhthalocyanine(?string $formula, array $names): bool
    {
        $elements = self::elementsInFormula($formula);
        if ($elements !== []) {
            $f = (string) $formula;
            return in_array('Cu', $elements, true)
                && array_diff($elements, ['C', 'H', 'N', 'Cu', 'Cl', 'Br']) === []
                && preg_match('/C32(?![0-9])/', $f) === 1
                && preg_match('/N8(?![0-9])/', $f) === 1;
        }
        $isPc = false;
        foreach ($names as $n) {
            $n = (string) $n;
            if (preg_match('/phthalocyanin/i', $n) === 1) {
                $isPc = true;
            }
            if (preg_match('/sulf|sulph|amin|methyl/i', $n) === 1) {
                return false;
            }
        }
        return $isPc;
    }

    /**
     * DB-free core.
     * @param array $categories regulatory_categories rows (category_code, category_name, element_symbol, name_keywords, deminimis_pct, is_pbt)
     * @param array $members    regulatory_category_members rows (category_code, cas_number, member_type)
     * @param array $casInfo    cas => ['formula' => ?string, 'names' => string[]]
     * @return array<string,array> cas => list of matching category rows (category order)
     */
    public static function resolve(array $categories, array $members, array $casInfo): array
    {
        $include = [];
        $exclude = [];
        foreach ($members as $m) {
            $code = (string) ($m['category_code'] ?? '');
            $cas  = trim((string) ($m['cas_number'] ?? ''));
            if ($code === '' || $cas === '') {
                continue;
            }
            if (($m['member_type'] ?? 'include') === 'exclude') {
                $exclude[$code][$cas] = true;
            } else {
                $include[$code][$cas] = true;
            }
        }

        $out = [];
        foreach ($casInfo as $cas => $info) {
            $cas = (string) $cas;
            if ($cas === '' || $cas === 'TRADE_SECRET') {
                continue;
            }
            $elements = self::elementsInFormula($info['formula'] ?? null);
            foreach ($categories as $cat) {
                $code = (string) ($cat['category_code'] ?? '');
                if ($code === '' || isset($exclude[$code][$cas])) {
                    continue;
                }
                $hit = isset($include[$code][$cas]);
                $sym = trim((string) ($cat['element_symbol'] ?? ''));
                if (!$hit && $sym !== '') {
                    $hit = $elements !== []
                        ? in_array($sym, $elements, true)                                   // formula is authoritative
                        : self::nameMatches($cat['name_keywords'] ?? null, (array) ($info['names'] ?? []));
                    if ($hit && $code === self::COPPER_COMPOUNDS
                        && self::isExcludedCopperPhthalocyanine($info['formula'] ?? null, (array) ($info['names'] ?? []))) {
                        $hit = false;   // H / Cl / Br-only copper phthalocyanine (1995 delisting)
                    }
                }
                if ($hit) {
                    $out[$cas][] = $cat;
                }
            }
        }
        return $out;
    }

    /**
     * DB wrapper. $namesByCas: composition CAS => list of names (constituent name).
     * @return array<string,array> as resolve()
     */
    public static function matchForCas(string $listCode, array $namesByCas): array
    {
        $cas = [];
        foreach ($namesByCas as $c => $names) {
            $c = (string) $c;
            if ($c !== '' && $c !== 'TRADE_SECRET') {
                $cas[$c] = array_values(array_filter(array_map('strval', (array) $names)));
            }
        }
        if ($cas === []) {
            return [];
        }
        $db = Database::getInstance();
        $categories = $db->fetchAll(
            "SELECT category_code, category_name, element_symbol, name_keywords, deminimis_pct, is_pbt
             FROM regulatory_categories
             WHERE list_code = ? AND is_active = 1
             ORDER BY category_code",
            [$listCode]
        );
        if ($categories === []) {
            return [];
        }
        $keys = array_map('strval', array_keys($cas));
        $ph   = implode(',', array_fill(0, count($keys), '?'));
        $members = $db->fetchAll(
            "SELECT category_code, cas_number, member_type
             FROM regulatory_category_members
             WHERE list_code = ? AND cas_number IN ({$ph})",
            array_merge([$listCode], $keys)
        );
        $info = [];
        foreach ($cas as $c => $names) {
            $info[$c] = ['formula' => null, 'names' => $names];
        }
        foreach ($db->fetchAll(
            "SELECT cas_number, molecular_formula, preferred_name FROM cas_master WHERE cas_number IN ({$ph})",
            $keys
        ) as $r) {
            $c = (string) $r['cas_number'];
            if (!isset($info[$c])) {
                continue;
            }
            $info[$c]['formula'] = $r['molecular_formula'];
            if (trim((string) ($r['preferred_name'] ?? '')) !== '') {
                $info[$c]['names'][] = (string) $r['preferred_name'];
            }
        }
        return self::resolve($categories, $members, $info);
    }
}
