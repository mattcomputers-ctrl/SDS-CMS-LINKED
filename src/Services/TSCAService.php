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
 * Raw-material override (migration 066, 2026-10-21): raw_materials.tsca_status
 * 'listed' / 'exempt' (the supplier certifies every component of that raw
 * material is on the inventory or exempt) covers all of that raw material's
 * components: its constituents without a CAS (trade secrets, a whole
 * trade-secret raw material, missing constituent data) and any of its CAS
 * that resolve 'not_verified'. A CAS-level 'not_listed' still wins.
 * Section 15 prints the "all listed/exempt" sentence only when every CAS
 * is listed or exempt and nothing is unverifiable (a trade-secret bucket
 * with no CAS at all cannot be checked); otherwise the "not verified"
 * sentence prints and warningText() yields an operator warning (never a
 * publish block). Trade-secret constituents that carry a real CAS are
 * resolved by that CAS (the composition keeps it, is_trade_secret = true).
 *
 * analyseResolved() and rollUp() are DB-free; analyse() is DB-free only for
 * a composition with no CAS, no contributing raw materials and no raw
 * material ids, so section15() stays unit-testable without a database.
 * CAS numbers are compared in canonical form (normaliseCas) everywhere:
 * Section 15, the TSCA Review lists and the bump / republish queue.
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

    /** raw_materials.tsca_status values (migration 066) → operator labels. 'auto' = no override. */
    public const RM_OVERRIDE_OPTIONS = [
        'auto'   => 'Auto — check each component by CAS',
        'listed' => 'Listed — the supplier certifies every component is on the TSCA inventory',
        'exempt' => 'Exempt — the supplier certifies every component is on the inventory or exempt',
    ];

    public const CAS_PATTERN = '/^\d{1,7}-\d{2}-\d$/';

    /** Whether raw_materials.tsca_status exists (migration 066); checked once per process. */
    private static ?bool $hasRmOverrideColumns = null;

    /**
     * Canonical spelling of a CAS number: drop EPA-style zero padding of the
     * first segment (0000050-00-0 → 50-00-0). Pasted CAS numbers can carry
     * characters the database collation ignores but PHP does not (2026-10-21):
     * non-breaking / zero-width spaces and BOMs, typographic dashes and
     * full-width digits are folded to plain ASCII first, so an override or
     * inventory row matches whatever way the constituent CAS was typed.
     */
    public static function normaliseCas(string $cas): string
    {
        // Every Unicode space and format character (\p{Z}, \p{Cf}: no-break
        // and zero-width spaces, BOM, soft hyphen, text-direction marks ...)
        // plus combining grapheme joiner and variation selectors.
        $folded = preg_replace('/[\s\p{Z}\p{Cf}\x{034F}\x{180B}-\x{180D}\x{FE00}-\x{FE0F}]+/u', '', $cas);
        $cas    = $folded ?? trim($cas);   // invalid UTF-8: plain trim
        $cas    = strtr($cas, [
            "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2013}" => '-', "\u{2014}" => '-',
            "\u{2015}" => '-', "\u{2212}" => '-', "\u{FE63}" => '-', "\u{FF0D}" => '-',
            "\u{FF10}" => '0', "\u{FF11}" => '1', "\u{FF12}" => '2', "\u{FF13}" => '3', "\u{FF14}" => '4',
            "\u{FF15}" => '5', "\u{FF16}" => '6', "\u{FF17}" => '7', "\u{FF18}" => '8', "\u{FF19}" => '9',
        ]);
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

        // Keyed by the canonical spelling (2026-10-21): the SQL IN() also finds
        // rows stored with an invisible character (the collation ignores it),
        // and those must still match the canonical CAS looked up below.
        $overrides = [];
        $inventory = [];
        foreach (array_chunk($casList, 1000) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            foreach ($db->fetchAll(
                "SELECT cas_number, preferred_name, tsca_status, tsca_note
                 FROM cas_master
                 WHERE cas_number IN ({$ph}) AND tsca_status <> 'auto'",
                $chunk
            ) as $r) {
                $overrides[self::normaliseCas((string) $r['cas_number'])] = $r;
            }
            foreach ($db->fetchAll(
                "SELECT cas_number, chemical_name, is_active_inventory
                 FROM tsca_inventory
                 WHERE cas_number IN ({$ph})",
                $chunk
            ) as $r) {
                $inventory[self::normaliseCas((string) $r['cas_number'])] = $r;
            }
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
     * $coveredWithoutCas: components without a CAS that a raw-material
     * override covers (they count towards "every component is covered").
     * @return array{all_covered:bool,total:int,listed_count:int,exempt_count:int,unverified:array<string,string>,not_listed:array<string,string>,inactive:array<string,string>,no_cas_count:int,covered_without_cas:int,overridden:bool,incomplete_raw_materials?:array}
     */
    public static function rollUp(array $resolved, int $noCasCount = 0, array $names = [], int $coveredWithoutCas = 0): array
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
            'all_covered'         => ($total + $coveredWithoutCas) > 0 && $unverified === [] && $notListed === [] && $noCasCount === 0,
            'total'               => $total,
            'listed_count'        => $listed,
            'exempt_count'        => $exempt,
            'unverified'          => $unverified,
            'not_listed'          => $notListed,
            'inactive'            => $inactive,
            'no_cas_count'        => $noCasCount,
            'covered_without_cas' => $coveredWithoutCas,
            'overridden'          => false,
        ];
    }

    /**
     * DB-free: a CAS that resolves 'not_verified' is covered when every raw
     * material it comes from carries a raw-material override; it then counts
     * as 'exempt' if one of them is exempt, else 'listed'. Nothing else
     * changes (a CAS-level not_listed / listed / exempt stays as resolved).
     *
     * @param array<string, int[]> $casRms cas => contributing raw material ids
     * @param array<int, array{status:string}> $rmOverrides rawMaterialOverrides()
     */
    public static function applyRawMaterialOverrides(array $resolved, array $casRms, array $rmOverrides): array
    {
        if ($rmOverrides === []) {
            return $resolved;
        }
        foreach ($resolved as $cas => $r) {
            if (($r['status'] ?? self::STATUS_NOT_VERIFIED) !== self::STATUS_NOT_VERIFIED) {
                continue;
            }
            $rms = array_values(array_unique(array_map('intval', $casRms[$cas] ?? [])));
            if ($rms === [] || array_diff($rms, array_keys($rmOverrides)) !== []) {
                continue;
            }
            $exempt = false;
            foreach ($rms as $id) {
                $exempt = $exempt || ($rmOverrides[$id]['status'] ?? '') === self::STATUS_EXEMPT;
            }
            $resolved[$cas]['status'] = $exempt ? self::STATUS_EXEMPT : self::STATUS_LISTED;
            $resolved[$cas]['source'] = 'raw_material_override';
        }
        return $resolved;
    }

    /**
     * Roll-up for an expanded composition (FormulaCalcService shape).
     * Components with no CAS ('' or the TRADE_SECRET bucket) are counted
     * as unverifiable unless a raw-material override covers them.
     * $rawMaterialIds: every raw material in the formula tree (finding #27).
     * Reads the database for the CAS, the contributing raw materials'
     * overrides and incomplete data; analyseResolved() is the DB-free core.
     */
    public static function analyse(array $composition, array $rawMaterialIds = []): array
    {
        $cas   = [];
        $rmIds = array_map('intval', $rawMaterialIds);
        foreach ($composition as $c) {
            $n = self::normaliseCas((string) ($c['cas_number'] ?? ''));
            if ($n !== '' && $n !== 'TRADE_SECRET') {
                $cas[$n] = true;
            }
            foreach ((array) ($c['contributing_materials'] ?? []) as $cm) {
                $rmIds[] = (int) ($cm['raw_material_id'] ?? 0);
            }
        }
        $rmIds = array_values(array_unique(array_filter($rmIds, static fn (int $i): bool => $i > 0)));

        // No DB for an empty / CAS-less composition without raw materials
        // (tests call section15() with an empty composition).
        $resolved    = $cas === [] ? [] : self::resolve(array_keys($cas));
        $rmOverrides = $rmIds === [] ? [] : self::rawMaterialOverrides($rmIds);
        // #27: formula raw materials whose constituents cannot be checked. Empty list = no DB.
        $incomplete  = $rawMaterialIds === [] ? null : self::incompleteRawMaterials($rawMaterialIds);
        return self::analyseResolved($composition, $resolved, $rmOverrides, $incomplete);
    }

    /**
     * DB-free core of analyse(). Components with no CAS ('' or the
     * TRADE_SECRET bucket) are unverifiable unless every raw material they
     * come from carries a raw-material override; those raw materials' codes
     * are listed for the operator warning. $incomplete null = not checked.
     *
     * @param array<int, array{status:string}> $rmOverrides rawMaterialOverrides()
     */
    public static function analyseResolved(array $composition, array $resolved, array $rmOverrides, ?array $incomplete): array
    {
        $names        = [];
        $casRms       = [];
        $noCas        = 0;
        $coveredNoCas = 0;
        $noCasRms     = [];
        foreach ($composition as $c) {
            $n       = self::normaliseCas((string) ($c['cas_number'] ?? ''));
            $contrib = [];
            foreach ((array) ($c['contributing_materials'] ?? []) as $cm) {
                $id = (int) ($cm['raw_material_id'] ?? 0);
                if ($id > 0) {
                    $contrib[$id] = (string) ($cm['internal_code'] ?? '');
                }
            }
            if ($n === '' || $n === 'TRADE_SECRET') {
                if ($contrib !== [] && array_diff_key($contrib, $rmOverrides) === []) {
                    $coveredNoCas++;
                    continue;
                }
                $noCas++;
                foreach (array_diff_key($contrib, $rmOverrides) as $code) {
                    if ($code !== '') {
                        $noCasRms[$code] = true;
                    }
                }
                continue;
            }
            if (!isset($names[$n])) {
                $names[$n] = (string) ($c['chemical_name'] ?? '');
            }
            foreach (array_keys($contrib) as $id) {
                $casRms[$n][] = $id;
            }
        }

        // A raw material with incomplete constituent data (e.g. none entered)
        // but a raw-material override no longer blocks TSCA, and one with a
        // blocking gap is itself a covered component. The full list is kept
        // in 'incomplete_raw_materials' for the composition warning (#27:
        // the hazard classification still misses that data); the TSCA
        // warning uses 'tsca_open_incomplete'.
        $openIncomplete = [];
        $blocking       = [self::REASON_NO_CONSTITUENTS, self::REASON_BLANK_CAS, self::REASON_TRADE_SECRET_NO_CAS];
        foreach ($incomplete ?? [] as $row) {
            if (isset($rmOverrides[(int) ($row['id'] ?? 0)])) {
                if (array_intersect((array) ($row['reasons'] ?? []), $blocking) !== []) {
                    $coveredNoCas++;
                }
            } else {
                $openIncomplete[] = $row;
            }
        }

        $resolved = self::applyRawMaterialOverrides(array_intersect_key($resolved, $names), $casRms, $rmOverrides);
        $tsca     = self::rollUp($resolved, $noCas, $names, $coveredNoCas);
        $tsca['no_cas_raw_materials'] = array_keys($noCasRms);
        if ($incomplete === null) {
            return $tsca;
        }
        $tsca = self::applyIncomplete($tsca, $openIncomplete);
        $tsca['tsca_open_incomplete']     = $tsca['incomplete_raw_materials'];
        $tsca['incomplete_raw_materials'] = array_values($incomplete);
        return $tsca;
    }

    /**
     * Raw-material TSCA overrides in effect (migration 066) for these ids:
     * id => [status (listed|exempt), note, internal_code]. Empty before 066
     * has run, so cron paths keep working.
     *
     * @param int[] $rmIds
     * @return array<int, array{status:string, note:?string, internal_code:string}>
     */
    public static function rawMaterialOverrides(array $rmIds): array
    {
        $rmIds = array_values(array_unique(array_filter(array_map('intval', $rmIds), static fn (int $i): bool => $i > 0)));
        if ($rmIds === [] || !self::hasRawMaterialOverrideColumns()) {
            return [];
        }
        $db  = Database::getInstance();
        $out = [];
        foreach (array_chunk($rmIds, 1000) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            foreach ($db->fetchAll(
                "SELECT id, internal_code, tsca_status, tsca_note FROM raw_materials
                 WHERE id IN ({$ph}) AND tsca_status IN ('listed', 'exempt')",
                $chunk
            ) as $r) {
                $out[(int) $r['id']] = [
                    'status'        => (string) $r['tsca_status'],
                    'note'          => $r['tsca_note'],
                    'internal_code' => (string) $r['internal_code'],
                ];
            }
        }
        return $out;
    }

    /** Whether migration 066 (raw_materials.tsca_status) has run. */
    public static function hasRawMaterialOverrideColumns(): bool
    {
        if (self::$hasRmOverrideColumns === null) {
            $row = Database::getInstance()->fetch(
                "SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_materials' AND COLUMN_NAME = 'tsca_status'"
            );
            self::$hasRmOverrideColumns = (int) ($row['n'] ?? 0) > 0;
        }
        return self::$hasRmOverrideColumns;
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

        // Same composition shape analyseResolved() takes: one row per
        // constituent CAS, no-CAS rows for trade secrets, with the raw
        // material each comes from (raw-material overrides, migration 066).
        $composition = [];
        foreach ($db->fetchAll(
            "SELECT rmc.raw_material_id, rm.internal_code, rmc.cas_number, rmc.chemical_name,
                    rmc.is_trade_secret, rmc.trade_secret_h_codes
             FROM raw_material_constituents rmc
             JOIN raw_materials rm ON rm.id = rmc.raw_material_id
             WHERE rmc.raw_material_id IN ({$ph})
               AND (rm.hazardous_no_cas IS NULL OR rm.hazardous_no_cas = 0)",
            $rmIds
        ) as $r) {
            $n = self::normaliseCas((string) $r['cas_number']);
            if ($n === '' && !((int) $r['is_trade_secret'] === 1 && !empty($r['trade_secret_h_codes']))) {
                continue;   // other blank-CAS rows: incompleteRawMaterials() reports them
            }
            $composition[] = [
                'cas_number'             => $n === '' ? 'TRADE_SECRET' : $n,
                'chemical_name'          => (string) $r['chemical_name'],
                'contributing_materials' => [['raw_material_id' => (int) $r['raw_material_id'], 'internal_code' => (string) $r['internal_code']]],
            ];
        }
        foreach ($db->fetchAll(
            "SELECT id, internal_code FROM raw_materials WHERE id IN ({$ph}) AND hazardous_no_cas = 1",
            $rmIds
        ) as $r) {
            $composition[] = [
                'cas_number'             => 'TRADE_SECRET',
                'chemical_name'          => '',   // no-CAS row: the name is never used
                'contributing_materials' => [['raw_material_id' => (int) $r['id'], 'internal_code' => (string) $r['internal_code']]],
            ];
        }

        $cas = [];
        foreach ($composition as $c) {
            if ($c['cas_number'] !== 'TRADE_SECRET') {
                $cas[$c['cas_number']] = true;
            }
        }
        return self::analyseResolved(
            $composition,
            $cas === [] ? [] : self::resolve(array_keys($cas)),
            self::rawMaterialOverrides($rmIds),
            self::incompleteRawMaterials($rmIds)   // #27
        );
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
                    COALESCE(SUM(rmc.id IS NOT NULL AND " . self::sqlBlankCas('rmc.cas_number') . " AND COALESCE(rmc.is_trade_secret, 0) = 0), 0) AS n_blank_cas,
                    COALESCE(SUM(rmc.id IS NOT NULL AND " . self::sqlBlankCas('rmc.cas_number') . " AND rmc.is_trade_secret = 1
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

    /**
     * SQL test for a blank constituent CAS: empty after removing spaces,
     * tabs and line breaks (SQL TRIM strips spaces only), the same verdict
     * normaliseCas() gives in PHP for those characters.
     */
    public static function sqlBlankCas(string $col): string
    {
        return "TRIM(REPLACE(REPLACE(REPLACE(COALESCE({$col}, ''), CHAR(9), ''), CHAR(13), ''), CHAR(10), '')) = ''";
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
            $codes = (array) ($tsca['no_cas_raw_materials'] ?? []);
            $parts[] = $noCas . ' trade-secret component' . ($noCas === 1 ? '' : 's') . ' with no CAS cannot be checked'
                . ($codes !== [] ? ' (raw material' . (count($codes) === 1 ? ' ' : 's ') . implode(', ', $codes)
                    . '; if the supplier certifies its components, set a raw-material TSCA override)' : '');
        }
        $rms = [];
        foreach ((array) ($tsca['tsca_open_incomplete'] ?? $tsca['incomplete_raw_materials'] ?? []) as $row) {
            $r = array_values(array_diff((array) ($row['reasons'] ?? []), [self::REASON_NO_PERCENTAGE]));
            if ($r !== []) {
                $rms[] = (string) ($row['internal_code'] ?? '') . ' (' . implode(', ', array_map([self::class, 'reasonLabel'], $r)) . ')';
            }
        }
        if ($rms !== []) {
            $parts[] = 'raw material constituents that cannot be checked: ' . implode(', ', $rms)
                . ' (enter the constituents, or set a raw-material TSCA override if the supplier certifies them)';
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
     * TSCA Review list: every constituent CAS in use that resolve() does not
     * cover (not on the inventory, no CAS override) and that no
     * raw-material override covers. Built on resolve() itself (2026-10-21),
     * so the list and Section 15 cannot disagree over how a CAS was typed.
     * Raw materials marked "No SDS required" (migration 064) are left out
     * unless a current formula uses them (their data still feeds that
     * finished good).
     * Also listed (2026-10-21): a CAS on the inventory but INACTIVE, which
     * prints as listed although a Notice of Activity is required before it
     * is manufactured or imported again (row 'status' => 'inactive').
     * $scopeRmIds (BulkPublishController::rawMaterialsOnClearedSheets()):
     * only raw materials on a sheet bulk publish is cleared to publish; the
     * others that would have been listed go to $notCleared (id => code).
     *
     * @param array<int,true>|null    $scopeRmIds  null = no scope
     * @param array<int,string>|null  $notCleared
     */
    public static function reviewCandidates(?array $scopeRmIds = null, ?array &$notCleared = null): array
    {
        $db       = Database::getInstance();
        $needsSds = \SDS\Models\RawMaterial::sqlNeedsSds('rm');
        $rows     = $db->fetchAll(
            "SELECT rmc.cas_number, rmc.chemical_name, rm.id AS rm_id, rm.internal_code,
                    (EXISTS (
                        SELECT 1 FROM formula_lines fl
                        JOIN formulas f ON f.id = fl.formula_id AND f.is_current = 1
                        WHERE fl.raw_material_id = rm.id
                    )) AS in_formula
             FROM raw_material_constituents rmc
             JOIN raw_materials rm ON rm.id = rmc.raw_material_id
             WHERE NOT " . self::sqlBlankCas('rmc.cas_number') . "
               AND ({$needsSds} OR EXISTS (
                   SELECT 1 FROM formula_lines fl2
                   JOIN formulas f2 ON f2.id = fl2.formula_id AND f2.is_current = 1
                   WHERE fl2.raw_material_id = rm.id
               ))"
        );
        $rmOverrides = self::rawMaterialOverrides(array_column($rows, 'rm_id'));

        $byCas = [];
        foreach ($rows as $r) {
            $cas = self::normaliseCas((string) $r['cas_number']);
            // A value that is not a CAS ('Proprietary', 'N/A') cannot take a
            // CAS override: noCasReviewCandidates() lists its raw material.
            if ($cas === '' || !preg_match(self::CAS_PATTERN, $cas)) {
                continue;
            }
            // A raw-material override clears a CAS that is not verified
            // (applyRawMaterialOverrides()), not an INACTIVE one, which
            // still warns: such rows are kept aside for an INACTIVE CAS.
            $p = isset($rmOverrides[(int) $r['rm_id']]) ? 'ovr_' : '';
            if ($scopeRmIds !== null && !isset($scopeRmIds[(int) $r['rm_id']])) {
                $byCas[$cas][$p . 'waiting'][(int) $r['rm_id']] = (string) $r['internal_code'];
                continue;
            }
            $byCas[$cas][$p . 'names'][(string) $r['chemical_name']] = true;
            $byCas[$cas][$p . 'rms'][(string) $r['internal_code']]   = true;
            $byCas[$cas][$p . 'in_formula'] = max((int) ($byCas[$cas][$p . 'in_formula'] ?? 0), (int) $r['in_formula']);
        }
        if ($byCas === []) {
            return [];
        }

        $preferred = [];
        foreach (array_chunk(array_keys($byCas), 1000) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            foreach ($db->fetchAll("SELECT cas_number, preferred_name FROM cas_master WHERE cas_number IN ({$ph})", $chunk) as $r) {
                $preferred[self::normaliseCas((string) $r['cas_number'])] = (string) ($r['preferred_name'] ?? '');
            }
        }

        $out = [];
        foreach (self::resolve(array_keys($byCas)) as $cas => $res) {
            if ($res['status'] === self::STATUS_INACTIVE) {
                foreach (['waiting', 'names', 'rms'] as $k) {
                    $byCas[$cas][$k] = ($byCas[$cas][$k] ?? []) + ($byCas[$cas]['ovr_' . $k] ?? []);
                }
                $byCas[$cas]['in_formula'] = max((int) ($byCas[$cas]['in_formula'] ?? 0), (int) ($byCas[$cas]['ovr_in_formula'] ?? 0));
            } elseif ($res['status'] !== self::STATUS_NOT_VERIFIED) {
                continue;
            }
            foreach ($byCas[$cas]['waiting'] ?? [] as $rmId => $code) {
                $notCleared[$rmId] = $code;
            }
            if (empty($byCas[$cas]['rms'])) {
                continue;
            }
            $names = array_keys($byCas[$cas]['names']);
            rsort($names);
            $codes = array_keys($byCas[$cas]['rms']);
            sort($codes);
            $out[] = [
                'cas_number'         => (string) $cas,
                'chemical_name'      => ($preferred[$cas] ?? '') !== '' ? $preferred[$cas] : (string) ($names[0] ?? ''),
                'raw_material_count' => count($codes),
                'raw_material_codes' => implode(', ', $codes),
                'in_formula'         => (int) $byCas[$cas]['in_formula'],
                'status'             => (string) $res['status'],
            ];
        }
        usort($out, static fn (array $a, array $b): int =>
            [$b['in_formula'], $b['raw_material_count'], $a['cas_number']] <=> [$a['in_formula'], $a['raw_material_count'], $b['cas_number']]);
        return $out;
    }

    /**
     * TSCA Review, second list (2026-10-21): raw materials whose components
     * cannot be checked by CAS, so no CAS override can clear them — a whole
     * trade-secret raw material, a trade-secret constituent without a CAS, a
     * constituent without a CAS or with a value that is not a CAS number,
     * or no constituents at all — in a current formula or with a sheet of
     * their own (any version), without a raw-material override. Others can
     * be set on the raw material's edit page. Empty before migration 066.
     * $scopeRmIds / $notCleared: as in reviewCandidates(); with a scope it
     * replaces the formula-or-own-sheet condition (No SDS required items are
     * still left out unless a current formula uses them, as in list 1).
     *
     * @param array<int,true>|null    $scopeRmIds  null = no scope
     * @param array<int,string>|null  $notCleared
     * @return list<array{id:int, internal_code:string, name:string, reasons:string[], in_formula:int}>
     */
    public static function noCasReviewCandidates(?array $scopeRmIds = null, ?array &$notCleared = null): array
    {
        if (!self::hasRawMaterialOverrideColumns()) {
            return [];
        }
        $db       = Database::getInstance();
        $needsSds = \SDS\Models\RawMaterial::sqlNeedsSds('rm');
        $rows = $db->fetchAll(
            "SELECT rm.id, rm.internal_code, rm.supplier_product_name, rm.hazardous_no_cas, rm.tsca_status,
                    COUNT(rmc.id) AS n_constituents,
                    COALESCE(SUM(rmc.id IS NOT NULL AND " . self::sqlBlankCas('rmc.cas_number') . " AND COALESCE(rmc.is_trade_secret, 0) = 0), 0) AS n_blank_cas,
                    COALESCE(SUM(rmc.id IS NOT NULL AND " . self::sqlBlankCas('rmc.cas_number') . " AND rmc.is_trade_secret = 1
                                 AND TRIM(COALESCE(rmc.trade_secret_h_codes, '')) = ''), 0) AS n_ts_no_cas,
                    COALESCE(SUM(rmc.id IS NOT NULL AND " . self::sqlBlankCas('rmc.cas_number') . " AND rmc.is_trade_secret = 1
                                 AND TRIM(COALESCE(rmc.trade_secret_h_codes, '')) <> ''), 0) AS n_ts_no_cas_h,
                    (EXISTS (
                        SELECT 1 FROM formula_lines fl
                        JOIN formulas f ON f.id = fl.formula_id AND f.is_current = 1
                        WHERE fl.raw_material_id = rm.id
                    )) AS in_formula
             FROM raw_materials rm
             LEFT JOIN raw_material_constituents rmc ON rmc.raw_material_id = rm.id
             WHERE rm.tsca_status = 'auto'" . ($scopeRmIds !== null ? "
               AND ({$needsSds} OR EXISTS (
                        SELECT 1 FROM formula_lines fl3
                        JOIN formulas f3 ON f3.id = fl3.formula_id AND f3.is_current = 1
                        WHERE fl3.raw_material_id = rm.id
                    ))" : "
               AND (EXISTS (
                        SELECT 1 FROM formula_lines fl2
                        JOIN formulas f2 ON f2.id = fl2.formula_id AND f2.is_current = 1
                        WHERE fl2.raw_material_id = rm.id
                    )
                    OR EXISTS (
                        SELECT 1 FROM sds_versions sv
                        WHERE sv.raw_material_id = rm.id AND sv.alias_id IS NULL AND sv.finished_good_id IS NULL
                          AND sv.is_deleted = 0
                    ))") . "
             GROUP BY rm.id, rm.internal_code, rm.supplier_product_name, rm.hazardous_no_cas, rm.tsca_status
             ORDER BY rm.internal_code"
        );
        // Constituent values that are not a CAS number ('Proprietary', 'N/A').
        $invalid = [];
        foreach ($db->fetchAll(
            "SELECT DISTINCT raw_material_id, cas_number FROM raw_material_constituents WHERE NOT " . self::sqlBlankCas('cas_number')
        ) as $c) {
            $n = self::normaliseCas((string) $c['cas_number']);
            if ($n !== '' && !preg_match(self::CAS_PATTERN, $n)) {
                $invalid[(int) $c['raw_material_id']] = true;
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $reasons = (int) $r['hazardous_no_cas'] === 1
                ? ['whole_trade_secret']
                : array_values(array_diff(self::incompleteReasons($r), [self::REASON_NO_PERCENTAGE]));
            if ((int) $r['hazardous_no_cas'] !== 1 && (int) $r['n_ts_no_cas_h'] > 0) {
                $reasons[] = 'trade_secret_no_cas_with_hazards';
            }
            if ((int) $r['hazardous_no_cas'] !== 1 && isset($invalid[(int) $r['id']])) {
                $reasons[] = 'invalid_cas';
            }
            if ($reasons === []) {
                continue;
            }
            if ($scopeRmIds !== null && !isset($scopeRmIds[(int) $r['id']])) {
                $notCleared[(int) $r['id']] = (string) $r['internal_code'];
                continue;
            }
            $out[] = [
                'id'            => (int) $r['id'],
                'internal_code' => (string) $r['internal_code'],
                'name'          => (string) ($r['supplier_product_name'] ?? ''),
                'reasons'       => $reasons,
                'in_formula'    => (int) $r['in_formula'],
            ];
        }
        return $out;
    }

    /**
     * Third TSCA Review list: at least this share (%) of a raw material must
     * be unaccounted for by its entered constituents. Smaller gaps are
     * supplier rounding and impurities (not listed on the inventory).
     */
    public const UNDISCLOSED_MIN_PCT = 1.0;

    /**
     * DB-free: the share (%) of a raw material its entered constituents do
     * not account for, or null when it is below UNDISCLOSED_MIN_PCT or cannot
     * be told ($openRows: constituents with neither an exact value nor a
     * maximum). $maxTotal sums each constituent's exact value or maximum.
     */
    public static function undisclosedPct(float $maxTotal, int $openRows): ?float
    {
        if ($openRows > 0) {
            return null;
        }
        $rest = 100.0 - $maxTotal;
        return $rest >= self::UNDISCLOSED_MIN_PCT - 1e-9 ? round($rest, 2) : null;
    }

    /**
     * TSCA Review, third list (2026-10-21): raw materials whose entered
     * constituents add up to less than 100%, so part of the composition is
     * undisclosed — often a trade secret, or ingredients the supplier did not
     * list. Section 15 checks only the entered constituents, so products
     * using them can still print that all components are listed; a
     * raw-material override (the supplier certifies the whole product) or
     * the missing constituents settle it.
     * Only raw materials on a sheet Bulk SDS Publish is cleared to publish
     * ($scopeRmIds, BulkPublishController::rawMaterialsOnClearedSheets()).
     * Left out: those already on the second list ($listedRmIds), a
     * raw-material override, a whole trade secret. Empty before 066.
     *
     * @param array<int,true> $scopeRmIds
     * @param int[]           $listedRmIds
     * @return list<array{id:int, internal_code:string, name:string, entered_pct:float, undisclosed_pct:float, trade_secret_count:int, in_formula:int}>
     */
    public static function undisclosedCompositionCandidates(array $scopeRmIds, array $listedRmIds = []): array
    {
        if (!self::hasRawMaterialOverrideColumns()) {
            return [];
        }
        $rows = Database::getInstance()->fetchAll(
            "SELECT rm.id, rm.internal_code, rm.supplier_product_name, c.n_open, c.max_total, c.n_trade_secret,
                    (EXISTS (
                        SELECT 1 FROM formula_lines fl
                        JOIN formulas f ON f.id = fl.formula_id AND f.is_current = 1
                        WHERE fl.raw_material_id = rm.id
                    )) AS in_formula
             FROM (
                 SELECT raw_material_id,
                        SUM(pct_exact IS NULL AND pct_max IS NULL)  AS n_open,
                        SUM(COALESCE(pct_exact, pct_max, 0))        AS max_total,
                        SUM(COALESCE(is_trade_secret, 0) = 1)       AS n_trade_secret
                 FROM raw_material_constituents
                 GROUP BY raw_material_id
             ) c
             JOIN raw_materials rm ON rm.id = c.raw_material_id
             WHERE rm.tsca_status = 'auto' AND COALESCE(rm.hazardous_no_cas, 0) = 0
               AND c.n_open = 0 AND c.max_total <= ?",
            [100.0 - self::UNDISCLOSED_MIN_PCT + 1e-6]
        );

        $listed = array_flip(array_map('intval', $listedRmIds));
        $out    = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if (!isset($scopeRmIds[$id]) || isset($listed[$id])) {
                continue;
            }
            $rest = self::undisclosedPct((float) $r['max_total'], (int) $r['n_open']);
            if ($rest === null) {
                continue;
            }
            $out[] = [
                'id'                 => $id,
                'internal_code'      => (string) $r['internal_code'],
                'name'               => (string) ($r['supplier_product_name'] ?? ''),
                'entered_pct'        => round((float) $r['max_total'], 2),
                'undisclosed_pct'    => $rest,
                'trade_secret_count' => (int) $r['n_trade_secret'],
                'in_formula'         => (int) $r['in_formula'],
            ];
        }
        usort($out, static fn (array $a, array $b): int =>
            [$b['in_formula'], $b['undisclosed_pct'], $a['internal_code']] <=> [$a['in_formula'], $a['undisclosed_pct'], $b['internal_code']]);
        return $out;
    }

    /** Operator label of a noCasReviewCandidates() reason. */
    public static function noCasReasonLabel(string $reason): string
    {
        switch ($reason) {
            case 'whole_trade_secret':               return 'whole raw material is a trade secret (no CAS)';
            case 'trade_secret_no_cas_with_hazards': return 'trade-secret constituent with no CAS';
            case 'invalid_cas':                      return 'constituent CAS is not a CAS number (e.g. "Proprietary")';
            default:                                 return self::reasonLabel($reason);
        }
    }

    /**
     * Validate and write one per-CAS TSCA override on cas_master (audited
     * as 'tsca_override'); no bump, no flash. Used by the single-row save
     * and the TSCA Review batch save (AdminController).
     *
     * @return array{cas:string}|array{error:string}
     */
    public static function writeCasOverride(Database $db, string $casIn, string $status, string $note): array
    {
        $cas  = self::normaliseCas($casIn);
        $note = mb_substr(trim($note), 0, 500);
        if ($cas === '' || !preg_match(self::CAS_PATTERN, $cas)) {
            return ['error' => 'A valid CAS number is required.'];
        }
        if (!array_key_exists($status, self::OVERRIDE_OPTIONS)) {
            return ['error' => "Unknown TSCA status for CAS {$cas}."];
        }
        if ($status !== 'auto' && $note === '') {
            return ['error' => "A note explaining the TSCA override for CAS {$cas} is required (e.g. crossover CAS, polymer exemption)."];
        }

        // cas_master is the override's home; create the registry row if the
        // CAS is not there yet (same as saveCasDescription), naming it from
        // the most common constituent description.
        if (!$db->fetch("SELECT cas_number FROM cas_master WHERE cas_number = ?", [$cas])) {
            $nameRow = $db->fetch(
                "SELECT chemical_name FROM raw_material_constituents
                 WHERE cas_number = ? AND chemical_name <> ''
                 GROUP BY chemical_name ORDER BY COUNT(*) DESC LIMIT 1",
                [$cas]
            );
            $db->insert('cas_master', ['cas_number' => $cas, 'preferred_name' => (string) ($nameRow['chemical_name'] ?? '')]);
        }
        $db->update('cas_master', [
            'tsca_status'     => $status,
            'tsca_note'       => $status === 'auto' ? null : $note,
            'tsca_updated_by' => current_user_id(),
            'tsca_updated_at' => gmdate('Y-m-d H:i:s'),
        ], 'cas_number = ?', [$cas]);

        AuditService::log('tsca_override', $cas, 'update', ['status' => $status, 'note' => $note]);
        return ['cas' => $cas];
    }

    /**
     * Validate and write one raw-material TSCA override (migration 066;
     * audited as 'tsca_rm_override', not 'raw_material', which would count
     * as a review); no bump, no flash. The caller checks the migration.
     *
     * @return array{code:string}|array{error:string}
     */
    public static function writeRawMaterialOverride(Database $db, int $rmId, string $status, string $note): array
    {
        $note = mb_substr(trim($note), 0, 500);
        $rm   = $rmId > 0 ? $db->fetch("SELECT id, internal_code FROM raw_materials WHERE id = ?", [$rmId]) : null;
        if (!$rm) {
            return ['error' => 'Raw material not found.'];
        }
        $code = (string) $rm['internal_code'];
        if (!array_key_exists($status, self::RM_OVERRIDE_OPTIONS)) {
            return ['error' => "Unknown TSCA status for raw material {$code}."];
        }
        if ($status !== 'auto' && $note === '') {
            return ['error' => "A note explaining the TSCA override for {$code} is required (e.g. \"Supplier SDS Section 15: all components listed on or exempt from TSCA\")."];
        }
        $db->update('raw_materials', [
            'tsca_status'     => $status,
            'tsca_note'       => $status === 'auto' ? null : $note,
            'tsca_updated_by' => current_user_id(),
            'tsca_updated_at' => gmdate('Y-m-d H:i:s'),
        ], 'id = ?', [$rmId]);

        AuditService::log('tsca_rm_override', $rmId, 'update', ['internal_code' => $code, 'status' => $status, 'note' => $note]);
        return ['code' => $code];
    }

    /** Raw-material overrides currently in effect (migration 066). */
    public static function rawMaterialOverridesInEffect(): array
    {
        if (!self::hasRawMaterialOverrideColumns()) {
            return [];
        }
        return Database::getInstance()->fetchAll(
            "SELECT rm.id, rm.internal_code, rm.supplier_product_name, rm.tsca_status, rm.tsca_note, rm.tsca_updated_at,
                    u.display_name AS tsca_updated_by_name
             FROM raw_materials rm
             LEFT JOIN users u ON u.id = rm.tsca_updated_by
             WHERE rm.tsca_status <> 'auto'
             ORDER BY rm.internal_code"
        );
    }

    /**
     * Raw materials whose constituents carry this CAS in any spelling
     * (zero padding, invisible characters): the ones a CAS override changes.
     *
     * @return int[]
     */
    public static function rawMaterialIdsForCas(string $cas): array
    {
        return self::rawMaterialIdsForCasMany([$cas]);
    }

    /**
     * rawMaterialIdsForCas() for many CAS numbers in one pass over the
     * constituents, compared in canonical form (any dash, space, invisible
     * character or zero padding), the way Section 15 compares them.
     *
     * @param string[] $casList
     * @return int[]
     */
    public static function rawMaterialIdsForCasMany(array $casList): array
    {
        $want = [];
        foreach ($casList as $c) {
            $n = self::normaliseCas((string) $c);
            if ($n !== '' && preg_match(self::CAS_PATTERN, $n)) {
                $want[$n] = true;
            }
        }
        if ($want === []) {
            return [];
        }
        $ids = [];
        foreach (Database::getInstance()->fetchAll(
            "SELECT DISTINCT raw_material_id, cas_number FROM raw_material_constituents WHERE NOT " . self::sqlBlankCas('cas_number')
        ) as $r) {
            if (isset($want[self::normaliseCas((string) $r['cas_number'])])) {
                $ids[(int) $r['raw_material_id']] = true;
            }
        }
        return array_keys($ids);
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
