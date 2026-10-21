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
 * Only CAS numbers that are provided are checked (2026-10-21): a component
 * without one — a trade secret, a raw material with no constituents, a
 * value with no digits such as "Proprietary", or whatever share the
 * constituents leave undisclosed — is assumed listed. A value with digits
 * that is not a CAS number ("13463677", "1330-20-7 / 100-41-4") is a
 * provided CAS that cannot be matched: it counts as not verified until it
 * is corrected. Raw-material override (migration 066): raw_materials.
 * tsca_status 'listed' / 'exempt' (the supplier certifies every component)
 * covers that raw material's CAS numbers that resolve 'not_verified'; a
 * CAS-level 'not_listed' still wins. Section 15 prints the "all
 * listed/exempt" sentence unless a provided CAS is not verified (then
 * warningText() yields an operator warning, never a publish block) or is
 * marked not listed. Trade-secret constituents that carry a real CAS are
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
            'all_covered'         => $unverified === [] && $notListed === [] && $noCasCount === 0,
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
     * Components without a provided CAS are assumed listed (see
     * analyseResolved()).
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
            if (preg_match(self::CAS_PATTERN, $n)) {
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
     * DB-free core of analyse(). Only CAS numbers that are provided are
     * checked (Matt's rule, 2026-10-21): a component without a CAS — a trade
     * secret, a blank or non-CAS value such as "Proprietary", a raw material
     * with no constituents, or whatever share the constituents leave
     * undisclosed — is assumed listed and counts as covered, so only a
     * provided CAS that is not on the inventory (no override) or one marked
     * Not listed changes the sentence. $incomplete null = not checked; else
     * 'incomplete_raw_materials' keeps it for the composition warning (#27:
     * the hazard classification still misses that data).
     *
     * @param array<int, array{status:string}> $rmOverrides rawMaterialOverrides()
     */
    public static function analyseResolved(array $composition, array $resolved, array $rmOverrides, ?array $incomplete): array
    {
        $names        = [];
        $casRms       = [];
        $coveredNoCas = 0;
        foreach ($composition as $c) {
            $n = self::normaliseCas((string) ($c['cas_number'] ?? ''));
            if (!preg_match(self::CAS_PATTERN, $n)) {
                if (!self::isMalformedCas($n)) {
                    $coveredNoCas++;   // no CAS provided: assumed listed
                    continue;
                }
                // A provided CAS that is not a CAS number: cannot be matched.
                $resolved[$n] = ['status' => self::STATUS_NOT_VERIFIED, 'source' => 'invalid_cas', 'name' => '', 'note' => null, 'is_active_inventory' => null];
            }
            if (!isset($names[$n])) {
                $names[$n] = (string) ($c['chemical_name'] ?? '');
            }
            foreach ((array) ($c['contributing_materials'] ?? []) as $cm) {
                $id = (int) ($cm['raw_material_id'] ?? 0);
                if ($id > 0) {
                    $casRms[$n][] = $id;
                }
            }
        }
        // A raw material whose constituents leave a component without a CAS
        // (none entered, a blank or trade-secret CAS) is assumed listed too.
        $noCasReasons = [self::REASON_NO_CONSTITUENTS, self::REASON_BLANK_CAS, self::REASON_TRADE_SECRET_NO_CAS];
        foreach ($incomplete ?? [] as $row) {
            if (array_intersect((array) ($row['reasons'] ?? []), $noCasReasons) !== []) {
                $coveredNoCas++;
            }
        }

        $resolved = self::applyRawMaterialOverrides(array_intersect_key($resolved, $names), $casRms, $rmOverrides);
        $tsca     = self::rollUp($resolved, 0, $names, $coveredNoCas);
        if ($incomplete !== null) {
            $tsca['incomplete_raw_materials'] = array_values($incomplete);
        }
        return $tsca;
    }

    /**
     * A constituent value with digits that is not a CAS number ("13463677",
     * "CAS 64-17-5", "1330-20-7 / 100-41-4"): a CAS was provided but cannot
     * be matched to the inventory, so it is flagged to be corrected. Values
     * without digits ("Proprietary", "N/A", the TRADE_SECRET bucket) are not
     * provided CAS numbers. $n: normaliseCas() output.
     */
    public static function isMalformedCas(string $n): bool
    {
        return $n !== '' && preg_match('/\d/', $n) === 1 && !preg_match(self::CAS_PATTERN, $n);
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
            if (preg_match(self::CAS_PATTERN, $c['cas_number'])) {
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
     * product is covered or the operator owns the Section 15 sentence. Only
     * provided CAS numbers are flagged: one not on the inventory without an
     * override, or one marked Not listed (2026-10-21).
     */
    public static function warningText(array $tsca): ?string
    {
        if ($tsca === [] || !empty($tsca['overridden']) || !empty($tsca['all_covered'])) {
            return null;
        }
        $unverified = (array) ($tsca['unverified'] ?? []);
        $invalid    = array_filter($unverified, static fn ($cas): bool => !preg_match(self::CAS_PATTERN, (string) $cas), ARRAY_FILTER_USE_KEY);
        $unverified = array_diff_key($unverified, $invalid);
        $parts = [];
        if ($unverified !== []) {
            $parts[] = 'not on the TSCA inventory and no override: ' . self::casListForMessage($unverified);
        }
        if ($invalid !== []) {
            $parts[] = 'not a valid CAS number (correct it under Raw Materials > Constituents): ' . self::casListForMessage($invalid);
        }
        if (!empty($tsca['not_listed'])) {
            $parts[] = 'marked NOT LISTED on the CAS determinations page: ' . self::casListForMessage($tsca['not_listed']);
        }
        if ($parts === []) {
            return null;
        }
        $sentence = [
            'section15.tsca_status'              => 'All components of this product are listed on or exempt from the TSCA inventory',
            'section15.tsca_status_not_listed'   => 'One or more components of this product are not listed on the TSCA inventory',
            'section15.tsca_status_not_verified' => 'TSCA inventory status has not been verified for all components',
        ][self::statusKey($tsca)];
        return 'TSCA warning: Section 15 prints "' . $sentence . '" — '
            . implode('; ', $parts)
            . '. Resolve under CAS Determinations > TSCA Review. Publishing is not blocked.';
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
            // A value without digits ('Proprietary', 'N/A') is not a provided
            // CAS (Section 15 assumes it listed); one with digits that is not
            // a CAS number is listed so it gets corrected ('invalid').
            if ($cas === '' || (!preg_match(self::CAS_PATTERN, $cas) && !self::isMalformedCas($cas)) || isset($rmOverrides[(int) $r['rm_id']])) {
                continue;
            }
            if ($scopeRmIds !== null && !isset($scopeRmIds[(int) $r['rm_id']])) {
                $byCas[$cas]['waiting'][(int) $r['rm_id']] = (string) $r['internal_code'];
                continue;
            }
            $byCas[$cas]['names'][(string) $r['chemical_name']] = true;
            $byCas[$cas]['rms'][(string) $r['internal_code']]   = (int) $r['rm_id'];
            $byCas[$cas]['in_formula'] = max((int) ($byCas[$cas]['in_formula'] ?? 0), (int) $r['in_formula']);
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
            if ($res['status'] !== self::STATUS_NOT_VERIFIED) {
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
            $rmsByCode = $byCas[$cas]['rms'];
            ksort($rmsByCode);
            $codes = array_keys($rmsByCode);
            $out[] = [
                'cas_number'         => (string) $cas,
                'chemical_name'      => ($preferred[$cas] ?? '') !== '' ? $preferred[$cas] : (string) ($names[0] ?? ''),
                'raw_material_count' => count($codes),
                'raw_material_codes' => implode(', ', $codes),
                'raw_materials'      => $rmsByCode,
                'in_formula'         => (int) $byCas[$cas]['in_formula'],
                'invalid'            => (int) !preg_match(self::CAS_PATTERN, (string) $cas),
            ];
        }
        usort($out, static fn (array $a, array $b): int =>
            [$b['in_formula'], $b['raw_material_count'], $a['cas_number']] <=> [$a['in_formula'], $a['raw_material_count'], $b['cas_number']]);
        return $out;
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
