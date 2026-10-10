<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * TSCAService — audit #29. Resolves every constituent CAS against the EPA
 * TSCA Inventory (tsca_inventory, loaded by scripts/import-tsca-inventory.php)
 * and the per-CAS override on cas_master.tsca_status (edited on
 * /determinations > TSCA Review), then rolls the result up for Section 15.
 *
 * Resolution per CAS: override (listed / exempt / not_listed) if set,
 * else 'listed' ('inactive' for an INACTIVE entry) when the CAS is on the
 * inventory, else 'not_verified'.
 * Section 15 prints the "all listed/exempt" sentence only when every CAS
 * is listed or exempt and nothing is unverifiable (a trade-secret bucket
 * with no CAS at all cannot be checked); otherwise the "not verified"
 * sentence prints and warningText() yields an operator warning (never a
 * publish block). Trade-secret constituents that carry a real CAS are
 * resolved by that CAS (the composition keeps it, is_trade_secret = true).
 *
 * analyse() and rollUp() are DB-free for an empty CAS set so section15()
 * stays unit-testable without a database (same rule as analyseSnur()).
 */
class TSCAService
{
    public const STATUS_LISTED       = 'listed';
    public const STATUS_EXEMPT       = 'exempt';
    public const STATUS_NOT_LISTED   = 'not_listed';
    public const STATUS_NOT_VERIFIED = 'not_verified';
    /** #46: on the inventory but EPA ACTIVITY = INACTIVE (counts as listed; warning). */
    public const STATUS_INACTIVE     = 'inactive';

    /** #27: why a formula raw material's constituents cannot be checked (incompleteRawMaterials()). */
    public const REASON_NO_CONSTITUENTS     = 'no_constituents';
    public const REASON_BLANK_CAS           = 'blank_cas';
    public const REASON_TRADE_SECRET_NO_CAS = 'trade_secret_no_cas';
    public const REASON_NO_PERCENTAGE       = 'no_percentage';

    /** cas_master.tsca_status values → operator labels. 'auto' = no override. */
    public const OVERRIDE_OPTIONS = [
        'auto'       => 'Auto — resolve from the TSCA inventory',
        'listed'     => 'Listed — on TSCA (crossover / confidential CAS, or INACTIVE entry with a Notice of Activity filed)',
        'exempt'     => 'Exempt from the TSCA inventory',
        'not_listed' => 'Not listed (verified absent) — Section 15 states a component is not listed',
    ];

    public const CAS_PATTERN = '/^\d{1,7}-\d{2}-\d$/';

    /** Trim and drop EPA-style zero padding of the first segment (0000050-00-0 → 50-00-0). */
    public static function normaliseCas(string $cas): string
    {
        $cas = trim($cas);
        if ($cas === '') {
            return '';
        }
        if (preg_match('/^(\d+)-(\d{2})-(\d)$/', $cas, $m)) {
            $first = ltrim($m[1], '0');
            return ($first === '' ? '0' : $first) . '-' . $m[2] . '-' . $m[3];
        }
        return $cas;
    }

    /**
     * Resolve a list of CAS numbers. Returns cas => [status, source
     * ('override'|'inventory'|'none'), name, note, is_active_inventory].
     */
    public static function resolve(array $casList): array
    {
        $casList = array_values(array_unique(array_filter(
            array_map(static fn($c): string => self::normaliseCas((string) $c), $casList),
            static fn(string $c): bool => $c !== '' && $c !== 'TRADE_SECRET'
        )));
        if ($casList === []) {
            return [];
        }

        $db = Database::getInstance();
        $ph = implode(',', array_fill(0, count($casList), '?'));

        $overrides = [];
        foreach ($db->fetchAll(
            "SELECT cas_number, preferred_name, tsca_status, tsca_note
             FROM cas_master
             WHERE cas_number IN ({$ph}) AND tsca_status <> 'auto'",
            $casList
        ) as $r) {
            $overrides[$r['cas_number']] = $r;
        }

        $inventory = [];
        foreach ($db->fetchAll(
            "SELECT cas_number, chemical_name, is_active_inventory
             FROM tsca_inventory
             WHERE cas_number IN ({$ph})",
            $casList
        ) as $r) {
            $inventory[$r['cas_number']] = $r;
        }

        $out = [];
        foreach ($casList as $cas) {
            if (isset($overrides[$cas])) {
                $out[$cas] = [
                    'status'              => (string) $overrides[$cas]['tsca_status'],
                    'source'              => 'override',
                    'name'                => (string) ($overrides[$cas]['preferred_name'] ?? ''),
                    'note'                => $overrides[$cas]['tsca_note'],
                    'is_active_inventory' => isset($inventory[$cas]) ? (int) $inventory[$cas]['is_active_inventory'] : null,
                ];
            } elseif (isset($inventory[$cas])) {
                // #46: an INACTIVE entry is still on the inventory (the sentence
                // holds) but needs a Notice of Activity (Form B, 40 CFR 710.30)
                // before it is manufactured or imported again: warning. An
                // operator override ('listed' + NOA note) clears it.
                $out[$cas] = [
                    'status'              => (int) $inventory[$cas]['is_active_inventory'] === 0 ? self::STATUS_INACTIVE : self::STATUS_LISTED,
                    'source'              => 'inventory',
                    'name'                => (string) $inventory[$cas]['chemical_name'],
                    'note'                => null,
                    'is_active_inventory' => (int) $inventory[$cas]['is_active_inventory'],
                ];
            } else {
                $out[$cas] = [
                    'status'              => self::STATUS_NOT_VERIFIED,
                    'source'              => 'none',
                    'name'                => '',
                    'note'                => null,
                    'is_active_inventory' => null,
                ];
            }
        }
        return $out;
    }

    /**
     * DB-free roll-up. $names: cas => composition chemical_name fallback.
     * @return array{all_covered:bool,total:int,listed_count:int,exempt_count:int,unverified:array<string,string>,not_listed:array<string,string>,inactive:array<string,string>,no_cas_count:int,overridden:bool,incomplete_raw_materials?:array}
     */
    public static function rollUp(array $resolved, int $noCasCount = 0, array $names = []): array
    {
        $unverified = [];
        $notListed  = [];
        $listed     = 0;
        $exempt     = 0;
        $inactive   = [];
        foreach ($resolved as $cas => $r) {
            $cas  = (string) $cas;
            $name = (string) ($r['name'] ?? '');
            if ($name === '') {
                $name = (string) ($names[$cas] ?? '');
            }
            switch ($r['status'] ?? self::STATUS_NOT_VERIFIED) {
                case self::STATUS_LISTED:
                    $listed++;
                    break;
                case self::STATUS_EXEMPT:
                    $exempt++;
                    break;
                case self::STATUS_INACTIVE:
                    $inactive[$cas] = $name;
                    $listed++;
                    break;
                case self::STATUS_NOT_LISTED:
                    $notListed[$cas] = $name;
                    break;
                default:
                    $unverified[$cas] = $name;
            }
        }
        $total = count($resolved);
        return [
            'all_covered'  => $total > 0 && $unverified === [] && $notListed === [] && $noCasCount === 0,
            'total'        => $total,
            'listed_count' => $listed,
            'exempt_count' => $exempt,
            'unverified'   => $unverified,
            'not_listed'   => $notListed,
            'inactive'     => $inactive,
            'no_cas_count' => $noCasCount,
            'overridden'   => false,
        ];
    }

    /**
     * Roll-up for an expanded composition (FormulaCalcService shape).
     * Components with no CAS ('' or the TRADE_SECRET bucket) are counted
     * as unverifiable. DB-free when the composition carries no CAS.
     * $rawMaterialIds: every raw material in the formula tree (finding #27).
     */
    public static function analyse(array $composition, array $rawMaterialIds = []): array
    {
        $cas   = [];
        $names = [];
        $noCas = 0;
        foreach ($composition as $c) {
            $n = self::normaliseCas((string) ($c['cas_number'] ?? ''));
            if ($n === '' || $n === 'TRADE_SECRET') {
                $noCas++;
                continue;
            }
            $cas[$n] = true;
            if (!isset($names[$n])) {
                $names[$n] = (string) ($c['chemical_name'] ?? '');
            }
        }
        $tsca = $cas === []
            ? self::rollUp([], $noCas)   // no DB (tests call section15() with an empty composition)
            : self::rollUp(self::resolve(array_keys($cas)), $noCas, $names);
        // #27: formula raw materials whose constituents cannot be checked. Empty list = no DB.
        return $rawMaterialIds === [] ? $tsca : self::applyIncomplete($tsca, self::incompleteRawMaterials($rawMaterialIds));
    }

    /**
     * Roll-up straight from the raw materials of a formula tree (readiness
     * page; no SDS generation). Mirrors Formula::getExpandedComposition():
     * hazardous_no_cas RMs and no-CAS trade-secret constituents with H codes
     * are unverifiable buckets; other blank-CAS rows are ignored.
     *
     * @param int[] $rmIds
     */
    public static function rollUpForRawMaterialIds(array $rmIds): array
    {
        $rmIds = array_values(array_unique(array_map('intval', $rmIds)));
        if ($rmIds === []) {
            return self::rollUp([], 0);
        }
        $db = Database::getInstance();
        $ph = implode(',', array_fill(0, count($rmIds), '?'));

        $cas   = [];
        $names = [];
        $noCas = 0;
        foreach ($db->fetchAll(
            "SELECT rmc.cas_number, rmc.chemical_name, rmc.is_trade_secret, rmc.trade_secret_h_codes
             FROM raw_material_constituents rmc
             JOIN raw_materials rm ON rm.id = rmc.raw_material_id
             WHERE rmc.raw_material_id IN ({$ph})
               AND (rm.hazardous_no_cas IS NULL OR rm.hazardous_no_cas = 0)",
            $rmIds
        ) as $r) {
            $n = self::normaliseCas((string) $r['cas_number']);
            if ($n === '') {
                if ((int) $r['is_trade_secret'] === 1 && !empty($r['trade_secret_h_codes'])) {
                    $noCas++;
                }
                continue;
            }
            $cas[$n] = true;
            if (!isset($names[$n])) {
                $names[$n] = (string) $r['chemical_name'];
            }
        }
        $row = $db->fetch(
            "SELECT COUNT(*) AS n FROM raw_materials WHERE id IN ({$ph}) AND hazardous_no_cas = 1",
            $rmIds
        );
        $noCas += (int) ($row['n'] ?? 0);

        $tsca = $cas === []
            ? self::rollUp([], $noCas)
            : self::rollUp(self::resolve(array_keys($cas)), $noCas, $names);
        return self::applyIncomplete($tsca, self::incompleteRawMaterials($rmIds));   // #27
    }

    /**
     * #27: formula raw materials (hazardous_no_cas RMs excluded: they are the
     * TRADE_SECRET bucket) whose constituent data cannot be fully checked.
     * @param int[] $rmIds
     * @return list<array{id:int,internal_code:string,reasons:string[]}>
     */
    public static function incompleteRawMaterials(array $rmIds): array
    {
        $rmIds = array_values(array_unique(array_filter(array_map('intval', $rmIds), static fn (int $i): bool => $i > 0)));
        if ($rmIds === []) {
            return [];
        }
        $db = Database::getInstance();
        $ph = implode(',', array_fill(0, count($rmIds), '?'));
        $out = [];
        foreach ($db->fetchAll(
            "SELECT rm.id, rm.internal_code,
                    COUNT(rmc.id) AS n_constituents,
                    COALESCE(SUM(rmc.id IS NOT NULL AND TRIM(COALESCE(rmc.cas_number, '')) = '' AND COALESCE(rmc.is_trade_secret, 0) = 0), 0) AS n_blank_cas,
                    COALESCE(SUM(rmc.id IS NOT NULL AND TRIM(COALESCE(rmc.cas_number, '')) = '' AND rmc.is_trade_secret = 1
                                 AND TRIM(COALESCE(rmc.trade_secret_h_codes, '')) = ''), 0) AS n_ts_no_cas,
                    COALESCE(SUM(rmc.id IS NOT NULL AND rmc.pct_exact IS NULL AND rmc.pct_min IS NULL AND rmc.pct_max IS NULL), 0) AS n_no_pct
             FROM raw_materials rm
             LEFT JOIN raw_material_constituents rmc ON rmc.raw_material_id = rm.id
             WHERE rm.id IN ({$ph})
               AND (rm.hazardous_no_cas IS NULL OR rm.hazardous_no_cas = 0)
             GROUP BY rm.id, rm.internal_code
             ORDER BY rm.internal_code",
            $rmIds
        ) as $r) {
            $reasons = self::incompleteReasons($r);
            if ($reasons !== []) {
                $out[] = ['id' => (int) $r['id'], 'internal_code' => (string) $r['internal_code'], 'reasons' => $reasons];
            }
        }
        return $out;
    }

    /** #27 DB-free: reasons from the incompleteRawMaterials() counters. */
    public static function incompleteReasons(array $counts): array
    {
        if ((int) ($counts['n_constituents'] ?? 0) === 0) {
            return [self::REASON_NO_CONSTITUENTS];
        }
        $reasons = [];
        if ((int) ($counts['n_blank_cas'] ?? 0) > 0) {
            $reasons[] = self::REASON_BLANK_CAS;
        }
        if ((int) ($counts['n_ts_no_cas'] ?? 0) > 0) {
            $reasons[] = self::REASON_TRADE_SECRET_NO_CAS;
        }
        if ((int) ($counts['n_no_pct'] ?? 0) > 0) {
            $reasons[] = self::REASON_NO_PERCENTAGE;
        }
        return $reasons;
    }

    /**
     * #27 DB-free: fold incomplete raw materials into a roll-up. Any with no
     * constituents, a non-trade-secret constituent without CAS, or a
     * trade-secret constituent with neither CAS nor declared H-codes makes the
     * "all listed" sentence impossible; a missing percentage alone is kept for
     * the operator but does not affect TSCA.
     */
    public static function applyIncomplete(array $tsca, array $incomplete): array
    {
        $tsca['incomplete_raw_materials'] = array_values($incomplete);
        $blocking = [self::REASON_NO_CONSTITUENTS, self::REASON_BLANK_CAS, self::REASON_TRADE_SECRET_NO_CAS];
        foreach ($incomplete as $row) {
            if (array_intersect((array) ($row['reasons'] ?? []), $blocking) !== []) {
                $tsca['all_covered'] = false;
                break;
            }
        }
        return $tsca;
    }

    /** #46: translation key of the Section 15 TSCA sentence for a roll-up. */
    public static function statusKey(array $tsca): string
    {
        if (!empty($tsca['not_listed'])) {
            return 'section15.tsca_status_not_listed';
        }
        return !empty($tsca['all_covered']) ? 'section15.tsca_status' : 'section15.tsca_status_not_verified';
    }

    /** Operator-facing English label of an incomplete-constituent reason. */
    public static function reasonLabel(string $reason): string
    {
        switch ($reason) {
            case self::REASON_NO_CONSTITUENTS:     return 'no constituents';
            case self::REASON_BLANK_CAS:           return 'constituent without a CAS number';
            case self::REASON_TRADE_SECRET_NO_CAS: return 'trade-secret constituent with no CAS and no declared hazard codes';
            case self::REASON_NO_PERCENTAGE:       return 'constituent without a percentage';
            default:                               return $reason;
        }
    }

    /**
     * Operator warning (English, never printed on the SDS) or null when the
     * product is fully covered (and has no INACTIVE inventory entry) or the
     * operator owns the Section 15 sentence.
     */
    public static function warningText(array $tsca): ?string
    {
        if ($tsca === [] || !empty($tsca['overridden'])) {
            return null;
        }
        $inactive = (array) ($tsca['inactive'] ?? []);
        if (!empty($tsca['all_covered']) && $inactive === []) {
            return null;
        }
        $parts = [];
        if (!empty($tsca['unverified'])) {
            $parts[] = 'not on the TSCA inventory and no override: ' . self::casListForMessage($tsca['unverified']);
        }
        if (!empty($tsca['not_listed'])) {
            $parts[] = 'marked NOT LISTED on the CAS determinations page: ' . self::casListForMessage($tsca['not_listed']);
        }
        if ($inactive !== []) {
            $parts[] = 'on the TSCA inventory but designated INACTIVE — a Notice of Activity (Form B, 40 CFR 710.30) is required before it is manufactured or imported again; once confirmed, set its CAS override to Listed with the NOA reference as the note: '
                . self::casListForMessage($inactive);
        }
        $noCas = (int) ($tsca['no_cas_count'] ?? 0);
        if ($noCas > 0) {
            $parts[] = $noCas . ' trade-secret component' . ($noCas === 1 ? '' : 's') . ' with no CAS cannot be checked';
        }
        $rms = [];
        foreach ((array) ($tsca['incomplete_raw_materials'] ?? []) as $row) {
            $r = array_values(array_diff((array) ($row['reasons'] ?? []), [self::REASON_NO_PERCENTAGE]));
            if ($r !== []) {
                $rms[] = (string) ($row['internal_code'] ?? '') . ' (' . implode(', ', array_map([self::class, 'reasonLabel'], $r)) . ')';
            }
        }
        if ($rms !== []) {
            $parts[] = 'raw material constituents that cannot be checked: ' . implode(', ', $rms);
        }
        if ($parts === []) {
            $parts[] = 'no CAS-level composition to check';
        }
        $sentence = [
            'section15.tsca_status'              => 'All components of this product are listed on or exempt from the TSCA inventory',
            'section15.tsca_status_not_listed'   => 'One or more components of this product are not listed on the TSCA inventory',
            'section15.tsca_status_not_verified' => 'TSCA inventory status has not been verified for all components',
        ][self::statusKey($tsca)];
        return 'TSCA warning: Section 15 prints "' . $sentence . '" — '
            . implode('; ', $parts)
            . '. Resolve under CAS Determinations > TSCA Review (Raw Materials > Constituents for incomplete raw materials). Publishing is not blocked.';
    }

    private static function casListForMessage(array $map): string
    {
        $items = [];
        $i     = 0;
        foreach ($map as $cas => $name) {
            if ($i++ >= 8) {
                $items[] = '… (+' . (count($map) - 8) . ' more)';
                break;
            }
            $items[] = $cas . ($name !== '' ? ' (' . $name . ')' : '');
        }
        return implode(', ', $items);
    }

    /**
     * TSCA Review list: every constituent CAS in use that is not on the
     * inventory and has no override.
     */
    public static function reviewCandidates(): array
    {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT rmc.cas_number,
                    COALESCE(NULLIF(cm.preferred_name, ''), MAX(rmc.chemical_name)) AS chemical_name,
                    COUNT(DISTINCT rm.id) AS raw_material_count,
                    GROUP_CONCAT(DISTINCT rm.internal_code ORDER BY rm.internal_code SEPARATOR ', ') AS raw_material_codes,
                    MAX(CASE WHEN EXISTS (
                        SELECT 1 FROM formula_lines fl
                        JOIN formulas f ON f.id = fl.formula_id AND f.is_current = 1
                        WHERE fl.raw_material_id = rm.id
                    ) THEN 1 ELSE 0 END) AS in_formula
             FROM raw_material_constituents rmc
             JOIN raw_materials rm ON rm.id = rmc.raw_material_id
             LEFT JOIN cas_master cm ON cm.cas_number = rmc.cas_number
             LEFT JOIN tsca_inventory ti ON ti.cas_number = rmc.cas_number
             WHERE rmc.cas_number <> ''
               AND ti.cas_number IS NULL
               AND (cm.tsca_status IS NULL OR cm.tsca_status = 'auto')
             GROUP BY rmc.cas_number, cm.preferred_name
             ORDER BY in_formula DESC, raw_material_count DESC, rmc.cas_number ASC"
        );
    }

    /** Overrides currently in effect (cas_master.tsca_status <> 'auto'). */
    public static function overridesInEffect(): array
    {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT cm.cas_number, cm.preferred_name, cm.tsca_status, cm.tsca_note, cm.tsca_updated_at,
                    u.display_name AS tsca_updated_by_name,
                    (ti.cas_number IS NOT NULL) AS on_inventory,
                    (SELECT COUNT(DISTINCT rmc.raw_material_id)
                     FROM raw_material_constituents rmc WHERE rmc.cas_number = cm.cas_number) AS rm_count
             FROM cas_master cm
             LEFT JOIN users u ON u.id = cm.tsca_updated_by
             LEFT JOIN tsca_inventory ti ON ti.cas_number = cm.cas_number
             WHERE cm.tsca_status <> 'auto'
             ORDER BY cm.cas_number"
        );
    }
}
