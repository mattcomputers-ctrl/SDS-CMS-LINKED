<?php

declare(strict_types=1);

namespace SDS\Services;

/**
 * TransportClassifier — SDS Section 14 (US DOT, 49 CFR 172.101) classification
 * derived from product data (SDS content audit item #27). Pure and DB-free:
 * SDSGenerator::section14() builds the input from formula properties, the
 * hazard engine result and the finished good row, then translates the result;
 * the publish gates read the returned 'status'.
 *
 * Rules (Decisions log #27):
 *   Class 3   flash point < 60 °C or Flam. Liq. H224/H225/H226. A "> n" flash
 *             point with n < 60 is still treated as flammable (conservative;
 *             Section 13 D001 reads the same value the same way).
 *             PG I   initial boiling point <= 35 °C (H224 — Cat 1 means
 *                    IBP <= 35 °C — stands in when no IBP is known),
 *             PG II  flash point < 23 °C (H225 when no flash point is known;
 *                    H224 with a measured IBP > 35 °C still implies fp < 23),
 *             PG III otherwise.
 *             Entry by product type: UN1210 Printing ink (related material),
 *             UN1263 Paint (related material), UN1993 Flammable liquids, n.o.s.
 *             With a subsidiary 8 / 6.1 the n.o.s. combination entry is used
 *             (UN2924 / UN1992 / UN3286) — UN1210 and UN1263 are Class 3 only.
 *   Class 8   Skin Corr. 1 (H314): PG I / II / III from sub-category 1A / 1B
 *             (or unknown) / 1C. Primary (UN1760 liquid / UN1759 solid; UN2922 /
 *             UN2923 when also toxic) unless the precedence table says otherwise.
 *   Class 6.1 acute toxicity cat. 1-3 by any route (H300 H301 H310 H311 H330
 *             H331): PG from the category. Primary (UN2810 / UN2811; UN2927 /
 *             UN2928 when also corrosive) unless the precedence table says otherwise.
 *   Precedence (49 CFR 173.2a(b), the 3 / 8 / 6.1 cells):
 *             3 vs 8:   8 wins when 3 is PG III and 8 is PG I or II, or 3 is
 *                       PG II and 8 is PG I → UN2920 "Corrosive liquids,
 *                       flammable, n.o.s." 8 (3). Otherwise 3 (UN2924).
 *             3 vs 6.1: 6.1 wins when 3 is PG III and 6.1 is PG I or II, or
 *                       6.1 is PG I by inhalation (173.2a(a)) → UN2929 "Toxic
 *                       liquids, flammable, organic, n.o.s." 6.1 (3). Otherwise 3.
 *             3 + 8 + 6.1 with 8 or 6.1 taking precedence: no generic entry
 *                       fits → Not determined (publish gate; operator override).
 *             8 vs 6.1 (no Class 3): 6.1 wins for 6.1 PG I unless 8 is PG I
 *                       liquid; for 6.1 PG II (inhalation / dermal) unless 8 is
 *                       PG I or II liquid; for 6.1 PG II (oral) only against
 *                       8 PG III → UN2927 / UN2928 6.1 (8). Otherwise 8.
 *             The packing group is the most stringent of all classes (Note 1).
 *   Class 9   Aquatic Acute 1 (H400) or Chronic 1/2 (H410/H411) with no other
 *             class: UN3082 liquid / UN3077 solid, PG III. "Marine pollutant"
 *             is reported whenever those codes are present.
 *   Not regulated: none of the above and (flash point known or engine data
 *             present); 60 <= fp < 93 °C (not a "> n" value) adds the
 *             'combustible' note.
 *   Not determined: no flash point AND no classification data.
 *   Class 3 PG II with no subsidiary adds the 'viscous' note: 49 CFR
 *             173.121(b)(1) lets a viscous PG II liquid be ASSIGNED PG III
 *             (noted, not decided). PG III sheets gain nothing from it.
 *   n.o.s. entries carry up to two technical names (49 CFR 172.203(k)); when
 *             none can be derived 'technical_names_missing' is set so the
 *             generator can warn the operator.
 */
final class TransportClassifier
{
    public const STATUS_REGULATED      = 'regulated';
    public const STATUS_NOT_REGULATED  = 'not_regulated';
    public const STATUS_NOT_DETERMINED = 'not_determined';

    public const TYPE_INK           = 'ink';
    public const TYPE_INK_RELATED   = 'ink_related';
    public const TYPE_PAINT         = 'paint';
    public const TYPE_PAINT_RELATED = 'paint_related';
    public const TYPE_NOS           = 'nos';

    /** finished_goods.transport_product_type values => finished-good form labels. */
    public const PRODUCT_TYPE_LABELS = [
        self::TYPE_INK           => 'Printing ink (UN1210)',
        self::TYPE_INK_RELATED   => 'Printing ink related material (UN1210)',
        self::TYPE_PAINT         => 'Paint / coating / varnish (UN1263)',
        self::TYPE_PAINT_RELATED => 'Paint related material (UN1263)',
        self::TYPE_NOS           => 'Flammable liquid, n.o.s. (UN1993)',
    ];

    public const FLAM_CODES    = ['H224', 'H225', 'H226'];
    public const CORR_CODES    = ['H314'];
    public const TOX_CODES     = ['H300', 'H301', 'H310', 'H311', 'H330', 'H331'];
    public const AQUATIC_CODES = ['H400', 'H410', 'H411'];

    /** Keyword rule on UPPER(description + ' ' + family); checked in this order. */
    private const RELATED_KEYWORDS = ['ADDITIVE', 'REDUCER', 'THINNER', 'EXTENDER', 'RETARDER', 'DILUENT', 'CATALYST', 'HARDENER'];
    private const PAINT_KEYWORDS   = ['COATING', 'VARNISH', 'OPV', 'LACQUER', 'PRIMER', 'PAINT', 'ENAMEL'];
    private const NOS_KEYWORDS     = ['WASH', 'CLEANER', 'FOUNTAIN', 'DEGREASER'];   // NOT 'SOLVENT': solvent inks are inks

    private const PG_RANK = ['I' => 1, 'II' => 2, 'III' => 3];

    /**
     * @param array $in {
     *   flash_point_c: float|null, flash_point_greater_than: bool,
     *   boiling_point_c: float|null (initial boiling point; null = unknown),
     *   h_codes: string[] (SDSGenerator::extractHCodes),
     *   hazard_classes: array ($hazardResult['hazard_classes']; refines PG for 8 / 6.1),
     *   cas_h_codes: array<string,string[]> (self::casHCodeMap),
     *   composition: array (rows: cas_number, chemical_name, concentration_pct, is_trade_secret),
     *   physical_state: string|null, product_type: string|null (finished_goods.transport_product_type),
     *   description: string, family: string|null }
     * @return array {status, un_number, psn_key, technical_names, primary_class, subsidiary,
     *   hazard_class, packing_group, marine_pollutant, notes, product_type,
     *   flammable, corrosive, toxic, aquatic}
     */
    public static function classify(array $in): array
    {
        $hCodes = array_values(array_unique(array_map('strval', $in['h_codes'] ?? [])));
        $has    = static fn(array $codes): bool => !empty(array_intersect($hCodes, $codes));

        $fp = (isset($in['flash_point_c']) && $in['flash_point_c'] !== '') ? (float) $in['flash_point_c'] : null;
        $bp = (isset($in['boiling_point_c']) && $in['boiling_point_c'] !== '') ? (float) $in['boiling_point_c'] : null;

        $gt = !empty($in['flash_point_greater_than']);

        // A "> n °C" value means the real flash point is at least n: n >= 60
        // excludes Class 3; n < 60 is still treated as flammable (conservative;
        // SDSGenerator::section13() prints D001 for the same value). The
        // combustible note is a positive statement (60 <= fp < 93), so a "> n"
        // value — the real flash point may be >= 93 °C — never earns it.
        $flammable   = $has(self::FLAM_CODES) || ($fp !== null && $fp < 60.0);
        $combustible = !$flammable && $fp !== null && !$gt && $fp >= 60.0 && $fp < 93.0;
        $corrosive   = $has(self::CORR_CODES);
        $toxic       = $has(self::TOX_CODES);
        $aquatic     = $has(self::AQUATIC_CODES);
        $isSolid     = in_array(strtolower(trim((string) ($in['physical_state'] ?? ''))), ['solid', 'powder'], true);
        $productType = self::resolveProductType(
            isset($in['product_type']) ? (string) $in['product_type'] : null,
            (string) ($in['description'] ?? ''),
            isset($in['family']) ? (string) $in['family'] : null
        );

        $result = [
            'status'                  => self::STATUS_NOT_REGULATED,
            'un_number'               => null,
            'psn_key'                 => null,
            'technical_names'         => [],
            'technical_names_missing' => false,   // n.o.s. entry with no derivable technical name (operator warning)
            'primary_class'           => null,
            'subsidiary'              => [],
            'hazard_class'            => null,
            'packing_group'           => null,
            'marine_pollutant'        => $aquatic,
            'notes'                   => [],
            'product_type'            => $productType,
            'flammable'               => $flammable,
            'corrosive'               => $corrosive,
            'toxic'                   => $toxic,
            'aquatic'                 => $aquatic,
        ];

        if (!$flammable && !$corrosive && !$toxic && !$aquatic) {
            if ($fp === null && empty($hCodes)) {
                $result['status'] = self::STATUS_NOT_DETERMINED;
                return $result;
            }
            if ($combustible) {
                $result['notes'][] = 'combustible';
            }
            return $result;
        }

        $classes = is_array($in['hazard_classes'] ?? null) ? $in['hazard_classes'] : [];

        // Per-class packing groups, kept apart: the 173.2a(b) precedence table
        // compares them pairwise before the most stringent one is applied.
        $pg3 = $pg8 = $pg61 = null;
        if ($flammable) {
            // 49 CFR 173.121(a): PG I = IBP <= 35 °C. H224 (Flam. Liq. 1 = IBP <= 35 °C
            // by definition) stands in when no IBP is known; a measured IBP > 35 °C
            // wins, and H224 then still implies fp < 23 °C (PG II).
            if ($bp !== null && $bp <= 35.0) {
                $pg3 = 'I';
            } elseif ($bp === null && in_array('H224', $hCodes, true)) {
                $pg3 = 'I';
            } elseif (($fp !== null && $fp < 23.0) || in_array('H224', $hCodes, true) || ($fp === null && in_array('H225', $hCodes, true))) {
                $pg3 = 'II';
            } else {
                $pg3 = 'III';
            }
        }
        if ($corrosive) {
            $pg8 = self::corrosivePackingGroup($classes);
        }
        if ($toxic) {
            $pg61 = self::toxicPackingGroup($classes, $hCodes);
        }
        $pgs = array_values(array_filter([$pg3, $pg8, $pg61]));
        if ($aquatic && $pgs === []) {
            $pgs[] = 'III';
        }

        // 49 CFR 173.2a(b) precedence (see the class docblock). 173.2a(a) puts
        // Division 6.1 PG I by inhalation ahead of Class 3 at any flash point.
        $corrOver3 = $flammable && $corrosive
            && (($pg3 === 'III' && in_array($pg8, ['I', 'II'], true)) || ($pg3 === 'II' && $pg8 === 'I'));
        $toxOver3  = $flammable && $toxic
            && (($pg3 === 'III' && in_array($pg61, ['I', 'II'], true)) || self::isInhalationPgI($classes));
        $toxOver8  = !$flammable && $corrosive && $toxic
            && self::toxicPrecedesCorrosive((string) $pg61, (string) $pg8, $isSolid, self::toxicRoute($hCodes));

        if ($flammable && $corrosive && $toxic && ($corrOver3 || $toxOver3)) {
            // Three hazards with 8 or 6.1 outranking 3: no generic HMT entry fits
            // (UN2920 is 8 (3), UN2929 is 6.1 (3), UN3286 is 3 (6.1, 8)). Leave it
            // to the operator (publish gate + per-product override) rather than
            // invent an entry.
            $result['status'] = self::STATUS_NOT_DETERMINED;
            return $result;
        }

        if ($corrOver3 && !$toxic) {
            $primary      = '8';
            $sub          = ['3'];
            [$un, $key]   = ['UN2920', 'corrosive_liquid_flammable_nos'];
            $primaryCodes = self::CORR_CODES;
        } elseif ($toxOver3 && !$corrosive) {
            $primary      = '6.1';
            $sub          = ['3'];
            [$un, $key]   = ['UN2929', 'toxic_liquid_flammable_organic_nos'];
            $primaryCodes = self::TOX_CODES;
        } elseif ($flammable) {
            $primary = '3';
            $sub     = [];
            if ($toxic) {
                $sub[] = '6.1';
            }
            if ($corrosive) {
                $sub[] = '8';
            }
            if ($toxic && $corrosive) {
                [$un, $key] = ['UN3286', 'flammable_liquid_toxic_corrosive_nos'];
            } elseif ($toxic) {
                [$un, $key] = ['UN1992', 'flammable_liquid_toxic_nos'];
            } elseif ($corrosive) {
                [$un, $key] = ['UN2924', 'flammable_liquid_corrosive_nos'];
            } else {
                [$un, $key] = match ($productType) {
                    self::TYPE_INK           => ['UN1210', 'printing_ink'],
                    self::TYPE_INK_RELATED   => ['UN1210', 'printing_ink_related'],
                    self::TYPE_PAINT         => ['UN1263', 'paint'],
                    self::TYPE_PAINT_RELATED => ['UN1263', 'paint_related'],
                    default                  => ['UN1993', 'flammable_liquid_nos'],
                };
            }
            $primaryCodes = self::FLAM_CODES;
        } elseif ($corrosive && !$toxOver8) {
            $primary = '8';
            $sub     = $toxic ? ['6.1'] : [];
            if ($toxic) {
                [$un, $key] = $isSolid ? ['UN2923', 'corrosive_solid_toxic_nos'] : ['UN2922', 'corrosive_liquid_toxic_nos'];
            } else {
                [$un, $key] = $isSolid ? ['UN1759', 'corrosive_solid_nos'] : ['UN1760', 'corrosive_liquid_nos'];
            }
            $primaryCodes = self::CORR_CODES;
        } elseif ($toxic) {
            $primary = '6.1';
            $sub     = $corrosive ? ['8'] : [];
            if ($corrosive) {
                [$un, $key] = $isSolid ? ['UN2928', 'toxic_solid_corrosive_organic_nos'] : ['UN2927', 'toxic_liquid_corrosive_organic_nos'];
            } else {
                [$un, $key] = $isSolid ? ['UN2811', 'toxic_solid_organic_nos'] : ['UN2810', 'toxic_liquid_organic_nos'];
            }
            $primaryCodes = self::TOX_CODES;
        } else {
            $primary = '9';
            $sub     = [];
            [$un, $key]   = $isSolid ? ['UN3077', 'env_hazardous_solid_nos'] : ['UN3082', 'env_hazardous_liquid_nos'];
            $primaryCodes = self::AQUATIC_CODES;
        }

        $pg = self::mostStringent($pgs);   // 173.2a(b) Note 1: most stringent of all classes

        $result['status']        = self::STATUS_REGULATED;
        $result['un_number']     = $un;
        $result['psn_key']       = $key;
        $result['primary_class'] = $primary;
        $result['subsidiary']    = $sub;
        $result['hazard_class']  = $primary . ($sub !== [] ? ' (' . implode(', ', $sub) . ')' : '');
        $result['packing_group'] = $pg;
        if (!in_array($un, ['UN1210', 'UN1263'], true)) {   // n.o.s. entries carry technical names (49 CFR 172.203(k))
            $names = self::technicalNames($in['composition'] ?? [], $in['cas_h_codes'] ?? [], $primaryCodes);
            if ($names === []) {
                // 172.203(k)(3): the components contributing most to ANY of the hazards
                // (e.g. Class 3 from the formula flash point alone, no constituent H22x).
                $names = self::technicalNames(
                    $in['composition'] ?? [],
                    $in['cas_h_codes'] ?? [],
                    array_merge(self::FLAM_CODES, self::CORR_CODES, self::TOX_CODES, self::AQUATIC_CODES)
                );
            }
            $result['technical_names']         = $names;
            $result['technical_names_missing'] = $names === [];
        }
        // 49 CFR 173.121(b)(1): a viscous Class 3 PG II liquid (fp < 23 °C) with no
        // 6.1 / 8 hazard may be ASSIGNED PG III — noted, not decided. A PG III sheet
        // gains nothing from it.
        if ($primary === '3' && $pg === 'II' && $sub === []) {
            $result['notes'][] = 'viscous';
        }
        return $result;
    }

    /**
     * finished_goods.transport_product_type wins; otherwise the keyword rule on
     * description + family: related-material keywords → *_related of the base
     * type; paint keywords → paint; wash/cleaner keywords → n.o.s.; else ink.
     */
    public static function resolveProductType(?string $flag, string $description, ?string $family): string
    {
        $flag = strtolower(trim((string) $flag));
        if ($flag !== '' && isset(self::PRODUCT_TYPE_LABELS[$flag])) {
            return $flag;
        }
        $text   = mb_strtoupper($description . ' ' . (string) $family);
        $hasAny = static function (array $keywords) use ($text): bool {
            foreach ($keywords as $k) {
                if (str_contains($text, $k)) {
                    return true;
                }
            }
            return false;
        };
        $paint = $hasAny(self::PAINT_KEYWORDS);
        if ($hasAny(self::RELATED_KEYWORDS)) {
            return $paint ? self::TYPE_PAINT_RELATED : self::TYPE_INK_RELATED;
        }
        if ($paint) {
            return self::TYPE_PAINT;
        }
        if ($hasAny(self::NOS_KEYWORDS)) {
            return self::TYPE_NOS;
        }
        return self::TYPE_INK;
    }

    /**
     * Per-CAS H-codes from the consolidated hazard_classes — the same
     * attribution SDSGenerator::section3() uses (MIXTURE entries credited to
     * their contributors; FG_OVERRIDE / TRADE_SECRET left unattributed).
     *
     * @return array<string,string[]>
     */
    public static function casHCodeMap(array $hazardResult): array
    {
        $map = [];
        foreach (($hazardResult['hazard_classes'] ?? []) as $hc) {
            $cas   = (string) ($hc['cas'] ?? '');
            $codes = array_filter(array_map('strval', (array) ($hc['h_codes'] ?? [])), static fn($c) => $c !== '');
            if ($cas === 'MIXTURE') {
                foreach ((array) ($hc['contributors'] ?? []) as $contrib) {
                    $contrib = (string) $contrib;
                    if ($contrib === '' || $contrib === 'TRADE_SECRET') {
                        continue;
                    }
                    foreach ($codes as $code) {
                        $map[$contrib][$code] = true;
                    }
                }
                continue;
            }
            if ($cas === '' || $cas === 'FG_OVERRIDE' || $cas === 'TRADE_SECRET') {
                continue;
            }
            foreach ($codes as $code) {
                $map[$cas][$code] = true;
            }
        }
        return array_map('array_keys', $map);
    }

    private static function corrosivePackingGroup(array $classes): string
    {
        foreach ($classes as $hc) {
            if ((string) ($hc['canonical'] ?? '') !== 'Skin Corrosion/Irritation') {
                continue;
            }
            $cat = strtoupper((string) ($hc['category_canonical'] ?? ''));
            if (str_contains($cat, '1A')) {
                return 'I';
            }
            if (str_contains($cat, '1C')) {
                return 'III';
            }
        }
        return 'II';
    }

    private static function toxicPackingGroup(array $classes, array $hCodes): string
    {
        $best = null;
        foreach ($classes as $hc) {
            if (!str_starts_with((string) ($hc['canonical'] ?? ''), 'Acute Toxicity')) {
                continue;
            }
            $cat = (string) ($hc['category_canonical'] ?? '');
            $pg  = match (true) {
                str_contains($cat, '1') => 'I',
                str_contains($cat, '2') => 'II',
                str_contains($cat, '3') => 'III',
                default                 => null,
            };
            if ($pg !== null && ($best === null || self::PG_RANK[$pg] < self::PG_RANK[$best])) {
                $best = $pg;
            }
        }
        if ($best !== null) {
            return $best;
        }
        // H300/H310/H330 cover categories 1 and 2 — without the class entry, PG II.
        return !empty(array_intersect($hCodes, ['H300', 'H310', 'H330'])) ? 'II' : 'III';
    }

    private static function mostStringent(array $pgs): string
    {
        $best = 'III';
        foreach ($pgs as $pg) {
            if (isset(self::PG_RANK[$pg]) && self::PG_RANK[$pg] < self::PG_RANK[$best]) {
                $best = $pg;
            }
        }
        return $best;
    }

    /** Division 6.1 PG I by inhalation (Acute Tox. 1, inhalation): 173.2a(a) ranks it above Class 3. */
    private static function isInhalationPgI(array $classes): bool
    {
        foreach ($classes as $hc) {
            $canonical = (string) ($hc['canonical'] ?? '');
            if (!str_starts_with($canonical, 'Acute Toxicity') || stripos($canonical, 'inhalation') === false) {
                continue;
            }
            if (str_contains((string) ($hc['category_canonical'] ?? ''), '1')) {
                return true;
            }
        }
        return false;
    }

    /** Worst 6.1 route from the codes: inhalation (H330/H331) > dermal (H310/H311) > oral. */
    private static function toxicRoute(array $hCodes): string
    {
        if (!empty(array_intersect($hCodes, ['H330', 'H331']))) {
            return 'inhalation';
        }
        if (!empty(array_intersect($hCodes, ['H310', 'H311']))) {
            return 'dermal';
        }
        return 'oral';
    }

    /**
     * 49 CFR 173.2a(b), Division 6.1 rows against Class 8 columns (no Class 3):
     *   6.1 I (any route):        8 wins only against 8 PG I LIQUID; else 6.1.
     *   6.1 II inhalation/dermal: 8 wins against 8 PG I / II LIQUID; else 6.1.
     *   6.1 II oral:              6.1 wins only against 8 PG III.
     *   6.1 III:                  8 always.
     */
    private static function toxicPrecedesCorrosive(string $pg61, string $pg8, bool $isSolid, string $route): bool
    {
        if ($pg61 === 'I') {
            return !($pg8 === 'I' && !$isSolid);
        }
        if ($pg61 === 'II') {
            if ($pg8 === 'III') {
                return true;
            }
            return $route !== 'oral' && $isSolid;
        }
        return false;
    }

    /** Up to two constituent names (highest wt% first) carrying the primary hazard's codes. */
    private static function technicalNames(array $composition, array $casHCodes, array $primaryCodes): array
    {
        $rows = [];
        foreach ($composition as $c) {
            $cas  = (string) ($c['cas_number'] ?? '');
            $name = trim((string) ($c['chemical_name'] ?? ''));
            if ($cas === '' || $cas === 'TRADE_SECRET' || $name === '' || !empty($c['is_trade_secret'])) {
                continue;
            }
            if (empty(array_intersect($casHCodes[$cas] ?? [], $primaryCodes))) {
                continue;
            }
            $rows[$name] = max($rows[$name] ?? 0.0, (float) ($c['concentration_pct'] ?? 0));
        }
        arsort($rows);
        return array_slice(array_keys($rows), 0, 2);
    }
}
