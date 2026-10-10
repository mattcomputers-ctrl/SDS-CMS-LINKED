<?php

declare(strict_types=1);

namespace SDS\Services;

/**
 * TransportClassifier — SDS Section 14 (US DOT, 49 CFR 172.101) classification
 * derived from product data (SDS content audit item #27). Pure and DB-free:
 * SDSGenerator::section14() builds the input from formula properties, the
 * hazard engine result and the finished good row, translates the result and
 * applies the per-product overrides (statuses 'override' /
 * 'override_incomplete', findings #7); the publish gates read the returned
 * 'status' (SDSReadinessService::transportNotDeterminedError, every language,
 * finding #6).
 *
 * Rules (Decisions log #27; owner decisions Q1-Q3, 2026-10-09):
 *   Unsupported classes (Q3, finding #4): any UNSUPPORTED_CODES H-code
 *             (explosives, gases / aerosols, flammable solids, self-reactives,
 *             pyrophoric / self-heating / water-reactive, oxidizers, organic
 *             peroxides) or the Gas physical state → Not determined (reason
 *             unsupported_class, reason_codes = the codes). Classes 1, 2, 4.x
 *             and 5.x are never derived; the operator enters the full override.
 *   Class 3   Flam. Liq. 1-3 (H224/H225/H226) in the engine result. HazardEngine
 *             derives them from the product flash point and initial boiling
 *             point (Cat 1-3 = FP <= 60 °C, the 49 CFR 173.120(a) limit, #44(1);
 *             a "> n" value reads as just above n), so Section 14 never
 *             disagrees with Sections 2, 5, 7, 10 and 13 (decision Q3, #9).
 *             The flash point number is not re-tested here.
 *             PG I   initial boiling point <= 35 °C (H224 — Cat 1 means
 *                    IBP <= 35 °C — stands in when no IBP is known),
 *             PG II  H224 / H225 (Cat 1 / 2 = flash point < 23 °C) — always (#5),
 *             PG III H226 (Cat 3, 23-60 °C).
 *             Entry by product type: UN1210 Printing ink (related material),
 *             UN1263 Paint (related material), UN1993 Flammable liquids, n.o.s.
 *             With a subsidiary 8 / 6.1 the n.o.s. combination entry is used
 *             (UN2924 / UN1992 / UN3286) — UN1210 and UN1263 are Class 3 only.
 *   Class 8   Skin Corr. 1 (H314): PG I / II / III from the most stringent
 *             hazard_classes row with canonical GHSHazardClass::SKIN_CORROSION_IRRITATION
 *             and category_canonical 'Cat 1A' / 'Cat 1B' or 'Cat 1' / 'Cat 1C'
 *             (finding #3: engine output shape; irritation categories never
 *             count; none → PG II). Primary (UN1760 liquid / UN1759 solid; UN2922 /
 *             UN2923 when also toxic) unless the precedence table says otherwise.
 *             Met. Corr. 1 (H290) on a liquid (not Solid / Powder / Paste) is
 *             also Class 8: 49 CFR 173.136(a)(2) / 173.137(c)(2), PG III, the
 *             same steel / aluminium test as GHS Met. Corr. 1 — so Section 14
 *             agrees with the Section 13 D002 'corrosive to metals' reason. With
 *             H314 present the Skin Corr. PG applies (always at least III).
 *   Class 6.1 acute toxicity cat. 1-3 by any route (H300 H301 H310 H311 H330
 *             H331): PG I / II / III from rows with canonical GHSHazardClass::
 *             ACUTE_TOXICITY_ORAL / _DERMAL / _INHALATION and 'Cat 1' / 'Cat 2' /
 *             'Cat 3' (finding #3). Primary (UN2810 / UN2811; UN2927 /
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
 *   Not regulated: none of the above. A product with no flash point is
 *             treated as having none (owner decision Q2: not flammable),
 *             whatever other H-codes it carries; H227 (Flam. Liq. 4) on a
 *             liquid adds the 'combustible' note when the flash point is below
 *             93 °C (49 CFR 173.120(b)) or unknown (H227 from the FG override).
 *             A "> n" value counts as n (Q1), so "> 65 °C" earns the note,
 *             agreeing with the H227 the engine assigns to it.
 *   Not determined (reason): unsupported_class (above) or
 *             three_class_precedence (3 + 8 + 6.1 with 8 or 6.1 outranking 3)
 *             — never for a missing flash point (Q2).
 *   Class 3 PG II with no subsidiary adds the 'viscous' note: 49 CFR
 *             173.121(b)(1) lets a viscous PG II liquid be ASSIGNED PG III
 *             (noted, not decided). PG III sheets gain nothing from it.
 *   n.o.s. entries carry up to two technical names (49 CFR 172.203(k)):
 *             constituents carrying the primary hazard's codes; for Class 3,
 *             then the disclosed constituents of raw materials with fp <= 60 °C
 *             ('flammable_cas'); then any transport hazard. None →
 *             'technical_names_missing' (operator warning). Trade secrets are
 *             never named.
 *   Product type: finished_goods.transport_product_type, else whole-word
 *             keywords in the DESCRIPTION only (finding #44(3): the family
 *             name no longer switches the entry).
 */
final class TransportClassifier
{
    public const STATUS_REGULATED      = 'regulated';
    public const STATUS_NOT_REGULATED  = 'not_regulated';
    public const STATUS_NOT_DETERMINED = 'not_determined';

    /** Why classify() returned STATUS_NOT_DETERMINED (result 'reason'; audit #45 gate message). */
    public const REASON_NO_DATA           = 'no_data';                 // legacy only: no longer returned (owner decision Q2, no flash point = not flammable); kept for stored snapshots
    public const REASON_THREE_CLASS       = 'three_class_precedence';  // 3 + 8 + 6.1 with 8 or 6.1 outranking 3
    public const REASON_UNSUPPORTED_CLASS = 'unsupported_class';       // Q3 safety net: aerosol / gas / flam. solid / pyrophoric / self-heating / water-reactive / oxidizer / peroxide codes

    /** Set by SDSGenerator::section14() (findings #7), never by classify(). */
    public const STATUS_OVERRIDE              = 'override';             // complete operator determination
    public const STATUS_OVERRIDE_INCOMPLETE   = 'override_incomplete';  // publish block
    public const REASON_OVERRIDE_INCOMPLETE   = 'override_incomplete';

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
    public const METAL_CORR_CODES = ['H290'];   // Class 8 PG III on liquids (173.137(c)(2))
    public const TOX_CODES     = ['H300', 'H301', 'H310', 'H311', 'H330', 'H331'];
    public const AQUATIC_CODES = ['H400', 'H410', 'H411'];

    /**
     * Owner decision Q3 (finding #4): the company ships no explosives, gases,
     * aerosols, flammable solids, self-reactives, pyrophoric / self-heating /
     * water-reactive materials, oxidizers or organic peroxides. Any of these
     * codes → not_determined (publish blocked) until an operator enters the full
     * Section 14 override.
     */
    public const UNSUPPORTED_CODES = [
        'H200', 'H201', 'H202', 'H203', 'H204', 'H205', 'H206', 'H207', 'H208', // explosives / desensitized explosives (Class 1)
        'H220', 'H221', 'H222', 'H223', 'H229', 'H230', 'H231', 'H232',         // flammable gases / aerosols (Class 2)
        'H280', 'H281', 'H282', 'H283', 'H284',                                 // gases / chemicals under pressure (Class 2)
        'H228',                                                                 // flammable solids (Div. 4.1)
        'H240', 'H241', 'H242',                                                 // self-reactives / organic peroxides (Div. 4.1 / 5.2)
        'H250', 'H251', 'H252',                                                 // pyrophoric / self-heating (Div. 4.2)
        'H260', 'H261',                                                         // water-reactive (Div. 4.3)
        'H270', 'H271', 'H272',                                                 // oxidizers (Div. 5.1 / 2.2)
    ];

    /** Physical states never given the combustible note (owner decision Q3: not liquids). */
    private const NOT_LIQUID_STATES = ['solid', 'powder', 'paste'];

    private const ACUTE_TOX_CANONICALS = [
        GHSHazardClass::ACUTE_TOXICITY_ORAL,
        GHSHazardClass::ACUTE_TOXICITY_DERMAL,
        GHSHazardClass::ACUTE_TOXICITY_INHALATION,
    ];

    /** Keyword rule on UPPER(description) only (finding #44(3)), whole words + optional S/ES plural; checked in this order. */
    private const RELATED_KEYWORDS = ['ADDITIVE', 'REDUCER', 'THINNER', 'EXTENDER', 'RETARDER', 'DILUENT', 'CATALYST', 'HARDENER'];
    private const PAINT_KEYWORDS   = ['COATING', 'VARNISH', 'OPV', 'LACQUER', 'PRIMER', 'PAINT', 'ENAMEL'];
    private const NOS_KEYWORDS     = ['WASH', 'WASHUP', 'CLEANER', 'FOUNTAIN', 'DEGREASER'];   // NOT 'SOLVENT': solvent inks are inks

    private const PG_RANK = ['I' => 1, 'II' => 2, 'III' => 3];

    /**
     * @param array $in {
     *   flash_point_c: float|null, flash_point_greater_than: bool (accepted; no longer changes
     *     the result — "> n" counts as n, Q1),
     *   boiling_point_c: float|null (initial boiling point; null = unknown),
     *   h_codes: string[] (SDSGenerator::extractHCodes),
     *   hazard_classes: array ($hazardResult['hazard_classes']; refines PG for 8 / 6.1),
     *   cas_h_codes: array<string,string[]> (self::casHCodeMap; per-CAS, before consolidation),
     *   composition: array (rows: cas_number, chemical_name, concentration_pct, is_trade_secret),
     *   flammable_cas: string[] (CAS of constituents from raw materials with fp <= 60 °C; Class 3 technical-name fallback),
     *   physical_state: string|null, product_type: string|null (finished_goods.transport_product_type),
     *   description: string, family: string|null (ignored since finding #44(3)) }
     * @return array {status, reason (null | REASON_*), reason_codes (unsupported H-codes),
     *   un_number, psn_key, technical_names, technical_names_missing, primary_class, subsidiary,
     *   hazard_class, packing_group, marine_pollutant, notes, product_type,
     *   flammable, corrosive, toxic, aquatic}
     */
    public static function classify(array $in): array
    {
        $hCodes = array_values(array_unique(array_map('strval', $in['h_codes'] ?? [])));
        $has    = static fn(array $codes): bool => !empty(array_intersect($hCodes, $codes));

        $fp = (isset($in['flash_point_c']) && $in['flash_point_c'] !== '') ? (float) $in['flash_point_c'] : null;
        $bp = (isset($in['boiling_point_c']) && $in['boiling_point_c'] !== '') ? (float) $in['boiling_point_c'] : null;

        $state     = strtolower(trim((string) ($in['physical_state'] ?? '')));
        $isSolid   = in_array($state, ['solid', 'powder'], true);        // solid UN entries (UN3077 / UN1759 / UN2811 ...)
        $notLiquid = in_array($state, self::NOT_LIQUID_STATES, true);    // never a combustible liquid (Q3)

        // One flammability source (Q3, audit #9): Class 3 is read ONLY from the
        // engine's Flammable Liquids codes, which HazardEngine derives from the
        // product flash point / IBP (Cat 1-3 = FP <= 60 °C, #44(1); Solid /
        // Powder / Paste never); a finished-good hazard override that adds or
        // removes them is followed the same way. The flash point is read only
        // for the combustible note: H227 on a liquid with a flash point below
        // 93 °C (173.120(b)), or no flash point at all (H227 from the override).
        // A "> n" value counts as n (Q1), like the engine's H227.
        $flammable   = $has(self::FLAM_CODES);
        $combustible = !$flammable && !$notLiquid && $has(['H227']) && !($fp !== null && $fp >= 93.0);
        $skinCorr    = $has(self::CORR_CODES);
        $metalCorr   = !$notLiquid && $has(self::METAL_CORR_CODES);   // 173.136(a)(2): liquids
        $corrosive   = $skinCorr || $metalCorr;
        $toxic       = $has(self::TOX_CODES);
        $aquatic     = $has(self::AQUATIC_CODES);
        $unsupported = array_values(array_intersect($hCodes, self::UNSUPPORTED_CODES));
        $productType = self::resolveProductType(
            isset($in['product_type']) ? (string) $in['product_type'] : null,
            (string) ($in['description'] ?? '')
        );

        $result = [
            'status'                  => self::STATUS_NOT_REGULATED,
            'reason'                  => null,  // REASON_* while status is not_determined (audit #45)
            'reason_codes'            => [],    // H-codes behind REASON_UNSUPPORTED_CLASS
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

        // Owner decision Q3 (finding #4): classes this classifier does not derive
        // block publishing until the operator enters the full classification.
        if ($unsupported !== [] || $state === 'gas') {
            $result['status']       = self::STATUS_NOT_DETERMINED;
            $result['reason']       = self::REASON_UNSUPPORTED_CLASS;
            $result['reason_codes'] = $unsupported;
            return $result;
        }

        if (!$flammable && !$corrosive && !$toxic && !$aquatic) {
            // Q2: no flash point and no transport class → Not regulated (no
            // publish block); a missing flash point means "no flash point".
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
            // 49 CFR 173.121(a): PG I = IBP <= 35 °C (any Class 3 flash point). H224
            // (Flam. Liq. 1 = IBP <= 35 °C) stands in when no IBP is known; a
            // measured IBP > 35 °C wins. PG II = flash point < 23 °C, read from the
            // engine category (H224 / H225), so H225 is always at least PG II (#5);
            // PG III = H226 (23-60 °C).
            if ($bp !== null && $bp <= 35.0) {
                $pg3 = 'I';
            } elseif ($bp === null && in_array('H224', $hCodes, true)) {
                $pg3 = 'I';
            } elseif ($has(['H224', 'H225'])) {
                $pg3 = 'II';
            } else {
                $pg3 = 'III';
            }
        }
        if ($corrosive) {
            // H290 alone (no H314) → PG III (173.137(c)(2)); corrosivePackingGroup()'s
            // PG II default is for H314 without a Skin Corr. sub-category.
            $pg8 = $skinCorr ? self::corrosivePackingGroup($classes) : 'III';
        }
        $corrCodes = array_merge(self::CORR_CODES, self::METAL_CORR_CODES);   // Class 8 technical names
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
            $result['reason'] = self::REASON_THREE_CLASS;
            return $result;
        }

        if ($corrOver3 && !$toxic) {
            $primary      = '8';
            $sub          = ['3'];
            [$un, $key]   = ['UN2920', 'corrosive_liquid_flammable_nos'];
            $primaryCodes = $corrCodes;
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
            $primaryCodes = $corrCodes;
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
            if ($names === [] && $primary === '3') {
                // Q3: Class 3 comes from the product flash point, so no constituent
                // may carry a Flam. Liq. code (e.g. H226 from an FG override). Fall
                // back to the disclosed constituents of raw materials with fp <= 60 °C.
                $names = self::technicalNamesFromCas($in['composition'] ?? [], (array) ($in['flammable_cas'] ?? []));
            }
            if ($names === []) {
                // 172.203(k)(3): the components contributing most to ANY of the hazards
                // (e.g. Class 3 from the formula flash point alone, no constituent H22x).
                $names = self::technicalNames(
                    $in['composition'] ?? [],
                    $in['cas_h_codes'] ?? [],
                    array_merge(self::FLAM_CODES, $corrCodes, self::TOX_CODES, self::AQUATIC_CODES)
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
     * the DESCRIPTION only (finding #44(3): a family name no longer switches every
     * member's entry), whole words with an optional S / ES plural:
     * related-material keywords → *_related of the base type; paint keywords →
     * paint; wash/cleaner keywords → n.o.s.; else ink. $family is accepted for
     * call compatibility and ignored.
     */
    public static function resolveProductType(?string $flag, string $description, ?string $family = null): string
    {
        $flag = strtolower(trim((string) $flag));
        if ($flag !== '' && isset(self::PRODUCT_TYPE_LABELS[$flag])) {
            return $flag;
        }
        $text   = mb_strtoupper($description);
        $hasAny = static function (array $keywords) use ($text): bool {
            foreach ($keywords as $k) {
                if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($k, '/') . '(?:E?S)?(?![\p{L}\p{N}])/u', $text) === 1) {
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
     * Per-CAS H-codes (#37): HazardEngine's map recorded BEFORE consolidation
     * ($hazardResult['cas_h_codes'], so a second CAS of the same class keeps
     * its code) unioned with the final hazard_classes (MIXTURE entries credited
     * to their contributors; FG_OVERRIDE / TRADE_SECRET left unattributed;
     * entries the generator adds after the engine, e.g. carcinogen registry).
     * Read by SDSGenerator::section3() and section14(). Codes sorted.
     *
     * @return array<string,string[]>
     */
    public static function casHCodeMap(array $hazardResult): array
    {
        $map = [];
        foreach ((array) ($hazardResult['cas_h_codes'] ?? []) as $preCas => $preCodes) {
            $preCas = (string) $preCas;
            if ($preCas === '' || preg_match('/^[A-Z_]+$/', $preCas) === 1) {   // MIXTURE / FG_OVERRIDE / TRADE_SECRET / other pseudo-keys
                continue;
            }
            foreach ((array) $preCodes as $code) {
                $code = (string) $code;
                if ($code !== '') {
                    $map[$preCas][$code] = true;
                }
            }
        }
        foreach (($hazardResult['hazard_classes'] ?? []) as $hc) {
            $cas   = (string) ($hc['cas'] ?? '');
            $codes = array_filter(array_map('strval', (array) ($hc['h_codes'] ?? [])), static fn($c) => $c !== '');
            if ($cas === 'MIXTURE') {
                // Q3: the flash-point entry's code is the mixture's category,
                // never the contributing solvents' own (HazardEngine credits
                // their own codes in cas_h_codes).
                if (($hc['source'] ?? '') === 'flash_point') {
                    continue;
                }
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
        return array_map(static function (array $set): array {
            $codes = array_keys($set);
            sort($codes, SORT_STRING);
            return $codes;
        }, $map);
    }

    private static function corrosivePackingGroup(array $classes): string
    {
        // Finding #3: engine rows carry canonical = GHSHazardClass constant and
        // category_canonical = 'Cat 1A' / 'Cat 1B' / 'Cat 1' / 'Cat 1C' / 'Cat 2'
        // (HazardClassAliases::normalizeCategory). Most stringent Skin Corr. 1
        // row wins; irritation (Cat 2 / 3) never counts; none → PG II.
        $best = null;
        foreach ($classes as $hc) {
            if ((string) ($hc['canonical'] ?? '') !== GHSHazardClass::SKIN_CORROSION_IRRITATION) {
                continue;
            }
            $pg = match (strtoupper(trim((string) ($hc['category_canonical'] ?? '')))) {
                'CAT 1A'          => 'I',
                'CAT 1B', 'CAT 1' => 'II',
                'CAT 1C'          => 'III',
                default           => null,
            };
            if ($pg !== null && ($best === null || self::PG_RANK[$pg] < self::PG_RANK[$best])) {
                $best = $pg;
            }
        }
        return $best ?? 'II';
    }

    private static function toxicPackingGroup(array $classes, array $hCodes): string
    {
        $best = null;
        foreach ($classes as $hc) {
            if (!in_array((string) ($hc['canonical'] ?? ''), self::ACUTE_TOX_CANONICALS, true)) {
                continue;
            }
            $pg = match (strtoupper(trim((string) ($hc['category_canonical'] ?? '')))) {
                'CAT 1' => 'I',
                'CAT 2' => 'II',
                'CAT 3' => 'III',
                default => null,
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
            if ((string) ($hc['canonical'] ?? '') === GHSHazardClass::ACUTE_TOXICITY_INHALATION
                && strtoupper(trim((string) ($hc['category_canonical'] ?? ''))) === 'CAT 1') {
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

    /** Up to two disclosed constituents (highest wt% first) whose CAS is in $casList; water is never named. */
    private static function technicalNamesFromCas(array $composition, array $casList): array
    {
        $want = array_flip(array_map('strval', $casList));
        unset($want['7732-18-5']);
        $rows = [];
        foreach ($composition as $c) {
            $cas  = (string) ($c['cas_number'] ?? '');
            $name = trim((string) ($c['chemical_name'] ?? ''));
            if ($cas === '' || $cas === 'TRADE_SECRET' || $name === '' || !empty($c['is_trade_secret']) || !isset($want[$cas])) {
                continue;
            }
            $rows[$name] = max($rows[$name] ?? 0.0, (float) ($c['concentration_pct'] ?? 0));
        }
        arsort($rows);
        return array_slice(array_keys($rows), 0, 2);
    }
}
