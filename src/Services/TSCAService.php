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
 * else 'listed' when the CAS is on the inventory, else 'not_verified'.
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

    /** cas_master.tsca_status values → operator labels. 'auto' = no override. */
    public const OVERRIDE_OPTIONS = [
        'auto'       => 'Auto — resolve from the TSCA inventory',
        'listed'     => 'Listed — on TSCA (crossover / confidential CAS)',
        'exempt'     => 'Exempt from the TSCA inventory',
        'not_listed' => 'Not listed (verified absent)',
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
                $out[$cas] = [
                    'status'              => self::STATUS_LISTED,
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
     * @return array{all_covered:bool,total:int,listed_count:int,exempt_count:int,unverified:array<string,string>,not_listed:array<string,string>,no_cas_count:int,overridden:bool}
     */
    public static function rollUp(array $resolved, int $noCasCount = 0, array $names = []): array
    {
        $unverified = [];
        $notListed  = [];
        $listed     = 0;
        $exempt     = 0;
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
            'no_cas_count' => $noCasCount,
            'overridden'   => false,
        ];
    }

    /**
     * Roll-up for an expanded composition (FormulaCalcService shape).
     * Components with no CAS ('' or the TRADE_SECRET bucket) are counted
     * as unverifiable. DB-free when the composition carries no CAS.
     */
    public static function analyse(array $composition): array
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
        if ($cas === []) {
            return self::rollUp([], $noCas);   // no DB (tests call section15() with an empty composition)
        }
        return self::rollUp(self::resolve(array_keys($cas)), $noCas, $names);
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

        if ($cas === []) {
            return self::rollUp([], $noCas);
        }
        return self::rollUp(self::resolve(array_keys($cas)), $noCas, $names);
    }

    /**
     * Operator warning (English, never printed on the SDS) or null when the
     * product is fully covered or the operator owns the Section 15 sentence.
     */
    public static function warningText(array $tsca): ?string
    {
        if ($tsca === [] || !empty($tsca['overridden']) || !empty($tsca['all_covered'])) {
            return null;
        }
        $parts = [];
        if (!empty($tsca['unverified'])) {
            $parts[] = 'not on the TSCA inventory and no override: ' . self::casListForMessage($tsca['unverified']);
        }
        if (!empty($tsca['not_listed'])) {
            $parts[] = 'marked NOT LISTED on the CAS determinations page: ' . self::casListForMessage($tsca['not_listed']);
        }
        $noCas = (int) ($tsca['no_cas_count'] ?? 0);
        if ($noCas > 0) {
            $parts[] = $noCas . ' trade-secret component' . ($noCas === 1 ? '' : 's') . ' with no CAS cannot be checked';
        }
        if ($parts === []) {
            $parts[] = 'no CAS-level composition to check';
        }
        return 'TSCA warning: Section 15 prints "TSCA inventory status has not been verified for all components" — '
            . implode('; ', $parts)
            . '. Resolve under CAS Determinations > TSCA Review, or set a Section 15 TSCA Status override for this product. Publishing is not blocked.';
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
