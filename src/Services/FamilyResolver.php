<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * FamilyResolver — resolves raw materials, intermediates, finished goods and
 * resale items to a product family (SDS content audit #3).
 *
 * Resolution per item:
 *   manual override (family_source = 'manual', ACTIVE family only — Q13)
 *   > direct rule match on the item (code prefix / description contains /
 *     exact code; aliases matched too: alias code for prefix/exact, alias
 *     description for contains)
 *   > inherited from content (products only): expand the current formula
 *     recursively, sum wt% per family across the raw materials, highest total
 *     wins, no minimum share; raws with no family contribute nothing;
 *     intermediates are always expanded down to raws (Decision #3);
 *     UV/LED families are pooled: when their total share beats the largest non-UV family (ties to UV) the largest UV family wins (Q13 / audit #61)
 *   > NULL (unresolved).
 *
 * matchRules() and resolveFromData() are pure (arrays in, arrays out) and are
 * unit-tested without a database. recompute() is the DB wrapper used by the
 * admin page, the CMS import and the product / raw-material save paths.
 *
 * Staleness contract — bulk publish reads raw_materials.updated_at only
 * (BulkPublishController::computeEligibleFinishedGoods):
 *   - a raw material whose family_id changes is written with
 *     updated_at = UTC_TIMESTAMP() (content change: every product containing
 *     it republishes — the same pattern as RegulatoryListBumper);
 *   - a finished good whose family_id changes is written with
 *     updated_at = updated_at (fg.updated_at is the form's optimistic lock,
 *     not a staleness signal); when it already has a published SDS that bulk
 *     publish would otherwise consider fresh, ONE raw material of its formula
 *     tree — the least-shared one — is bumped so the next bulk publish
 *     regenerates it, and an sds_update_queue row is added;
 *   - source-only and name-sync writes use updated_at = updated_at.
 */
final class FamilyResolver
{
    public const SOURCE_MANUAL  = 'manual';
    public const SOURCE_RULE    = 'rule';
    public const SOURCE_CONTENT = 'content';

    /* ------------------------------------------------------------------
     *  Pure core
     * ----------------------------------------------------------------*/

    /**
     * Pick the family for one item from the rule list.
     *
     * @param array  $item  ['code' => string, 'description' => string,
     *                       'alias_codes' => string[], 'alias_descriptions' => string[]]
     * @param array  $rules list of ['id','family_id','rule_type','pattern','applies_to']
     *                      (caller has already dropped rules of inactive families)
     * @param string $kind  'raw_material' | 'product'
     *
     * Precedence when several rules match: exact_code > code_prefix >
     * description_contains; within a type the longest pattern wins; the
     * item's own code/description beats an alias; then the lowest rule id.
     * exact_code matches the full code or its pack-stripped base.
     */
    public static function matchRules(array $item, array $rules, string $kind): ?int
    {
        $ownCode    = strtoupper(trim((string) ($item['code'] ?? '')));
        $ownCodes   = self::codeVariants($ownCode);
        $aliasCodes = [];
        foreach ($item['alias_codes'] ?? [] as $c) {
            foreach (self::codeVariants(strtoupper(trim((string) $c))) as $v) {
                $aliasCodes[] = $v;
            }
        }
        $ownDesc    = mb_strtolower(trim((string) ($item['description'] ?? '')));
        $aliasDescs = [];
        foreach ($item['alias_descriptions'] ?? [] as $d) {
            $d = mb_strtolower(trim((string) $d));
            if ($d !== '') {
                $aliasDescs[] = $d;
            }
        }

        $bestScore  = null;
        $bestFamily = null;
        foreach ($rules as $rule) {
            $applies = (string) ($rule['applies_to'] ?? 'both');
            if ($applies !== 'both' && $applies !== $kind) {
                continue;
            }
            $type    = (string) ($rule['rule_type'] ?? '');
            $pattern = trim((string) ($rule['pattern'] ?? ''));
            if ($pattern === '') {
                continue;
            }
            $hit = null; // 0 = own identifier, 1 = alias
            if ($type === 'exact_code') {
                $tier = 1;
                $p    = strtoupper($pattern);
                if (in_array($p, $ownCodes, true)) {
                    $hit = 0;
                } elseif (in_array($p, $aliasCodes, true)) {
                    $hit = 1;
                }
            } elseif ($type === 'code_prefix') {
                $tier = 2;
                $p    = strtoupper($pattern);
                if ($ownCode !== '' && str_starts_with($ownCode, $p)) {
                    $hit = 0;
                } else {
                    foreach ($aliasCodes as $c) {
                        if (str_starts_with($c, $p)) {
                            $hit = 1;
                            break;
                        }
                    }
                }
            } elseif ($type === 'description_contains') {
                $tier = 3;
                $p    = mb_strtolower($pattern);
                if ($ownDesc !== '' && mb_strpos($ownDesc, $p) !== false) {
                    $hit = 0;
                } else {
                    foreach ($aliasDescs as $d) {
                        if (mb_strpos($d, $p) !== false) {
                            $hit = 1;
                            break;
                        }
                    }
                }
            } else {
                continue;
            }
            if ($hit === null) {
                continue;
            }
            $score = [$tier, -mb_strlen($pattern), $hit, (int) $rule['id']];
            if ($bestScore === null || self::scoreLess($score, $bestScore)) {
                $bestScore  = $score;
                $bestFamily = (int) $rule['family_id'];
            }
        }
        return $bestFamily;
    }

    /**
     * Resolve the whole catalog from preloaded arrays (pure).
     *
     * @param array $d {
     *   families:         array<int, array{id:int,name:string,is_uv:int,is_active:int,sort_order:int}>
     *   rules:            list<array{id:int,family_id:int,rule_type:string,pattern:string,applies_to:string}>
     *   raw_materials:    array<int, array{id:int,internal_code:string,description:string,family_id:?int,family_source:?string}>
     *   finished_goods:   array<int, array{id:int,product_code:string,description:string,family_id:?int,family_source:?string}>
     *   aliases_by_base:  array<string, array{codes:string[],descriptions:string[]}>  (key: UPPER(internal_code_base))
     *   formula_by_fg:    array<int,int>   finished good id => current formula id
     *   lines_by_formula: array<int, list<array{rm:?int,fg:?int,pct:float}>>  (current formulas only)
     * }
     * @return array{
     *   raw_materials:  array<int, array{family_id:?int, source:?string}>,
     *   finished_goods: array<int, array{family_id:?int, source:?string, shares:array<int,float>}>
     * }
     */
    public static function resolveFromData(array $d): array
    {
        $families = $d['families'] ?? [];
        $active   = [];
        foreach ($families as $id => $f) {
            if ((int) ($f['is_active'] ?? 1) === 1) {
                $active[(int) $id] = true;
            }
        }
        $rules = array_values(array_filter(
            $d['rules'] ?? [],
            static fn(array $r): bool => isset($active[(int) $r['family_id']])
        ));
        $aliases = $d['aliases_by_base'] ?? [];

        // 1. Raw materials: manual > rule > null
        $rmOut = [];
        foreach ($d['raw_materials'] ?? [] as $id => $rm) {
            $id = (int) $id;
            if (($rm['family_source'] ?? null) === self::SOURCE_MANUAL && !empty($rm['family_id']) && isset($active[(int) $rm['family_id']])) { // Q13: a manual pick of an inactive family is released (falls through to rule / content)
                $rmOut[$id] = ['family_id' => (int) $rm['family_id'], 'source' => self::SOURCE_MANUAL];
                continue;
            }
            $base = strtoupper(self::baseCode((string) $rm['internal_code']));
            $fid  = self::matchRules([
                'code'               => (string) $rm['internal_code'],
                'description'        => (string) ($rm['description'] ?? ''),
                'alias_codes'        => $aliases[$base]['codes'] ?? [],
                'alias_descriptions' => $aliases[$base]['descriptions'] ?? [],
            ], $rules, 'raw_material');
            $rmOut[$id] = ['family_id' => $fid, 'source' => $fid === null ? null : self::SOURCE_RULE];
        }

        // 2. Re-routing: RM lines whose code is really a finished good with a
        //    current formula are expanded as that FG (same rule as
        //    BulkPublishController::computeEligibleFinishedGoods).
        $fgIdByCode = [];
        foreach ($d['finished_goods'] ?? [] as $id => $fg) {
            if (isset($d['formula_by_fg'][(int) $id])) {
                $fgIdByCode[strtoupper((string) $fg['product_code'])] = (int) $id;
            }
        }
        $rmCodes = [];
        foreach ($d['raw_materials'] ?? [] as $id => $rm) {
            $rmCodes[(int) $id] = strtoupper((string) $rm['internal_code']);
        }

        // 3. Content expansion, memoised per formula: family_id => wt% of that formula
        $memo   = [];
        $expand = function (int $formulaId, array $visiting) use (&$expand, &$memo, $d, $rmOut, $rmCodes, $fgIdByCode, $active): array {
            if (isset($memo[$formulaId])) {
                return $memo[$formulaId];
            }
            if (isset($visiting[$formulaId])) {
                return []; // cycle guard (Formula::create rejects cycles; defensive)
            }
            $visiting[$formulaId] = true;
            $sum = [];
            foreach ($d['lines_by_formula'][$formulaId] ?? [] as $line) {
                $pct = (float) ($line['pct'] ?? 0);
                if ($pct <= 0) {
                    continue;
                }
                $subFg = null;
                if (!empty($line['fg'])) {
                    $subFg = (int) $line['fg'];
                } elseif (!empty($line['rm'])) {
                    $code  = $rmCodes[(int) $line['rm']] ?? '';
                    $subFg = $fgIdByCode[$code] ?? ($fgIdByCode[strtoupper(self::baseCode($code))] ?? null);
                    if ($subFg === null) {
                        $fid = $rmOut[(int) $line['rm']]['family_id'] ?? null;
                        if ($fid !== null && isset($active[$fid])) {
                            $sum[$fid] = ($sum[$fid] ?? 0.0) + $pct;
                        }
                        continue;
                    }
                }
                if ($subFg === null) {
                    continue;
                }
                $subFormula = $d['formula_by_fg'][$subFg] ?? null;
                if ($subFormula === null) {
                    continue;
                }
                foreach ($expand($subFormula, $visiting) as $fid => $wt) {
                    $sum[$fid] = ($sum[$fid] ?? 0.0) + $wt * $pct / 100.0;
                }
            }
            $memo[$formulaId] = $sum;
            return $sum;
        };

        // 4. Finished goods: manual > rule > content > null
        $fgOut = [];
        foreach ($d['finished_goods'] ?? [] as $id => $fg) {
            $id        = (int) $id;
            $formulaId = $d['formula_by_fg'][$id] ?? null;
            $shares    = $formulaId !== null ? $expand($formulaId, []) : [];
            if (($fg['family_source'] ?? null) === self::SOURCE_MANUAL && !empty($fg['family_id']) && isset($active[(int) $fg['family_id']])) { // Q13: inactive manual pick falls through
                $fgOut[$id] = ['family_id' => (int) $fg['family_id'], 'source' => self::SOURCE_MANUAL, 'shares' => $shares];
                continue;
            }
            $base = strtoupper((string) $fg['product_code']);
            $fid  = self::matchRules([
                'code'               => (string) $fg['product_code'],
                'description'        => (string) ($fg['description'] ?? ''),
                'alias_codes'        => $aliases[$base]['codes'] ?? [],
                'alias_descriptions' => $aliases[$base]['descriptions'] ?? [],
            ], $rules, 'product');
            if ($fid !== null) {
                $fgOut[$id] = ['family_id' => $fid, 'source' => self::SOURCE_RULE, 'shares' => $shares];
                continue;
            }
            $fid = self::pickDominant($shares, $families);
            $fgOut[$id] = ['family_id' => $fid, 'source' => $fid === null ? null : self::SOURCE_CONTENT, 'shares' => $shares];
        }

        return ['raw_materials' => $rmOut, 'finished_goods' => $fgOut];
    }

    /**
     * Content resolution (audit #61 / Q13). UV/LED families are pooled: when
     * the TOTAL share of UV families is >= the largest single non-UV family,
     * the largest UV family wins (so a 20 % + 20 % UV split beats 30 %
     * Solvent); otherwise the largest non-UV family wins. Within a pool:
     * highest share, then lower sort_order, then lower family id. Families
     * missing from $families count as non-UV. Empty / zero shares -> null.
     */
    public static function pickDominant(array $shares, array $families): ?int
    {
        $better = static function (?array $best, int $fid, float $wt, int $sort): bool {
            return $best === null
                || $wt > $best[1]
                || ($wt == $best[1] && ($sort < $best[2] || ($sort === $best[2] && $fid < $best[0])));
        };
        $bestUv = null;
        $bestOther = null;
        $uvTotal = 0.0;
        foreach ($shares as $fid => $wt) {
            $wt = (float) $wt;
            if ($wt <= 0) {
                continue;
            }
            $fid  = (int) $fid;
            $sort = (int) ($families[$fid]['sort_order'] ?? 0);
            if ((int) ($families[$fid]['is_uv'] ?? 0) === 1) {
                $uvTotal += $wt;
                if ($better($bestUv, $fid, $wt, $sort)) {
                    $bestUv = [$fid, $wt, $sort];
                }
            } elseif ($better($bestOther, $fid, $wt, $sort)) {
                $bestOther = [$fid, $wt, $sort];
            }
        }
        if ($bestUv === null) {
            return $bestOther === null ? null : $bestOther[0];
        }
        if ($bestOther === null || $uvTotal >= $bestOther[1]) {
            return $bestUv[0];
        }
        return $bestOther[0];
    }

    /** Everything before the first '-' (AliasResolver::stripPack semantics). */
    public static function baseCode(string $code): string
    {
        $dash = strpos($code, '-');
        return $dash === false ? $code : substr($code, 0, $dash);
    }

    /** @return string[] distinct non-empty [code, base] */
    private static function codeVariants(string $code): array
    {
        if ($code === '') {
            return [];
        }
        $base = self::baseCode($code);
        return $base === $code ? [$code] : [$code, $base];
    }

    /** Lexicographic compare of equal-length numeric arrays. */
    private static function scoreLess(array $a, array $b): bool
    {
        foreach ($a as $i => $v) {
            if ($v === $b[$i]) {
                continue;
            }
            return $v < $b[$i];
        }
        return false;
    }

    /* ------------------------------------------------------------------
     *  DB wrapper
     * ----------------------------------------------------------------*/

    /** Load the arrays resolveFromData() needs (7 small SELECTs, whole catalog). */
    public static function loadData(): array
    {
        $db = Database::getInstance();
        $d  = ['families' => [], 'rules' => [], 'raw_materials' => [], 'finished_goods' => [], 'aliases_by_base' => [], 'formula_by_fg' => [], 'lines_by_formula' => []];

        foreach ($db->fetchAll('SELECT id, name, is_uv, is_active, sort_order FROM product_families') as $r) {
            $d['families'][(int) $r['id']] = $r;
        }
        $d['rules'] = $db->fetchAll('SELECT id, family_id, rule_type, pattern, applies_to FROM product_family_rules');
        foreach ($db->fetchAll('SELECT id, internal_code, supplier_product_name AS description, family_id, family_source, updated_at FROM raw_materials') as $r) {
            $d['raw_materials'][(int) $r['id']] = $r;
        }
        foreach ($db->fetchAll('SELECT id, product_code, description, family, family_id, family_source, is_active FROM finished_goods') as $r) {
            $d['finished_goods'][(int) $r['id']] = $r;
        }
        foreach ($db->fetchAll('SELECT internal_code_base, customer_code, description FROM aliases') as $r) {
            $k = strtoupper((string) $r['internal_code_base']);
            $d['aliases_by_base'][$k]['codes'][]        = (string) $r['customer_code'];
            $d['aliases_by_base'][$k]['descriptions'][] = (string) $r['description'];
        }
        foreach ($db->fetchAll('SELECT id, finished_good_id FROM formulas WHERE is_current = 1') as $r) {
            $d['formula_by_fg'][(int) $r['finished_good_id']] = (int) $r['id'];
        }
        foreach ($db->fetchAll(
            'SELECT fl.formula_id, fl.raw_material_id, fl.finished_good_component_id, fl.pct
             FROM formula_lines fl
             JOIN formulas f ON f.id = fl.formula_id AND f.is_current = 1'
        ) as $r) {
            $d['lines_by_formula'][(int) $r['formula_id']][] = [
                'rm'  => $r['raw_material_id'] !== null ? (int) $r['raw_material_id'] : null,
                'fg'  => $r['finished_good_component_id'] !== null ? (int) $r['finished_good_component_id'] : null,
                'pct' => (float) $r['pct'],
            ];
        }
        return $d;
    }

    /**
     * Compute the diff between stored families and the resolved ones and,
     * when $apply, write it and flag reassigned items for republish.
     *
     * @param bool       $apply  false = preview only
     * @param int|null   $userId for sds_update_queue.queued_by
     * @param string     $reason queue reason / audit text
     * @param array|null $scope  null = whole catalog; or
     *                           ['raw_material_ids' => int[], 'finished_good_ids' => int[]]:
     *                           only those items plus every finished good whose
     *                           formula tree contains them are diffed/applied.
     * @return array{
     *   applied: bool,
     *   changes: array{raw_materials: list<array>, finished_goods: list<array>},
     *   counts:  array{rm_changed:int, fg_changed:int, reassigned:int, metadata:int},
     *   bumped_rms: int, queued: int
     * }
     * Each change row: kind, id, code, description, old_family_id, old_family,
     * new_family_id, new_family, old_source, new_source, reassigned (bool).
     */
    public static function recompute(bool $apply, ?int $userId = null, string $reason = 'Product family recompute', ?array $scope = null): array
    {
        $d   = self::loadData();
        $res = self::resolveFromData($d);
        $nameOf = static fn(?int $fid) => $fid === null ? null : ($d['families'][$fid]['name'] ?? null);

        $rmScope = null;
        $fgScope = null;
        if ($scope !== null) {
            $rmScope = array_fill_keys(array_map('intval', $scope['raw_material_ids'] ?? []), true);
            $fgScope = self::ancestorsOf($d, array_keys($rmScope), array_map('intval', $scope['finished_good_ids'] ?? []));
        }

        $rmChanges = [];
        foreach ($d['raw_materials'] as $id => $rm) {
            if ($rmScope !== null && !isset($rmScope[$id])) {
                continue;
            }
            $new   = $res['raw_materials'][$id];
            $oldId = $rm['family_id'] !== null ? (int) $rm['family_id'] : null;
            if ($oldId === $new['family_id'] && ($rm['family_source'] ?? null) === $new['source']) {
                continue;
            }
            $rmChanges[] = [
                'kind' => 'raw_material', 'id' => $id, 'code' => $rm['internal_code'], 'description' => $rm['description'],
                'old_family_id' => $oldId, 'old_family' => $nameOf($oldId),
                'new_family_id' => $new['family_id'], 'new_family' => $nameOf($new['family_id']),
                'old_source' => $rm['family_source'], 'new_source' => $new['source'],
                'reassigned' => $oldId !== $new['family_id'],
            ];
        }

        $fgChanges = [];
        foreach ($d['finished_goods'] as $id => $fg) {
            if ($fgScope !== null && !isset($fgScope[$id])) {
                continue;
            }
            $new     = $res['finished_goods'][$id];
            $oldId   = $fg['family_id'] !== null ? (int) $fg['family_id'] : null;
            $newName = $nameOf($new['family_id']);
            if ($oldId === $new['family_id'] && ($fg['family_source'] ?? null) === $new['source'] && (string) ($fg['family'] ?? '') === (string) ($newName ?? '')) {
                continue;
            }
            $fgChanges[] = [
                'kind' => 'finished_good', 'id' => $id, 'code' => $fg['product_code'], 'description' => $fg['description'],
                'old_family_id' => $oldId, 'old_family' => $fg['family'] ?? $nameOf($oldId),
                'new_family_id' => $new['family_id'], 'new_family' => $newName,
                'old_source' => $fg['family_source'], 'new_source' => $new['source'],
                'reassigned' => $oldId !== $new['family_id'],
            ];
        }

        $reassigned = 0;
        foreach ([$rmChanges, $fgChanges] as $list) {
            foreach ($list as $c) {
                if ($c['reassigned']) {
                    $reassigned++;
                }
            }
        }
        $out = [
            'applied'    => false,
            'changes'    => ['raw_materials' => $rmChanges, 'finished_goods' => $fgChanges],
            'counts'     => [
                'rm_changed' => count($rmChanges),
                'fg_changed' => count($fgChanges),
                'reassigned' => $reassigned,
                'metadata'   => count($rmChanges) + count($fgChanges) - $reassigned,
            ],
            'bumped_rms' => 0,
            'queued'     => 0,
        ];
        if (!$apply || ($rmChanges === [] && $fgChanges === [])) {
            return $out;
        }

        $db = Database::getInstance();
        foreach ($rmChanges as $c) {
            // Reassignment is a content change for every product containing
            // this RM -> explicit updated_at bump (RegulatoryListBumper pattern).
            // Source-only changes are metadata -> updated_at preserved.
            $db->query(
                'UPDATE raw_materials SET family_id = ?, family_source = ?, updated_at = '
                    . ($c['reassigned'] ? 'UTC_TIMESTAMP()' : 'updated_at') . ' WHERE id = ?',
                [$c['new_family_id'], $c['new_source'], $c['id']]
            );
        }
        $reassignedFgIds = [];
        foreach ($fgChanges as $c) {
            // fg.updated_at is the form's optimistic lock, not the staleness
            // signal: always preserved. Legacy name column kept in sync.
            $db->query(
                'UPDATE finished_goods SET family_id = ?, family = ?, family_source = ?, updated_at = updated_at WHERE id = ?',
                [$c['new_family_id'], $c['new_family'], $c['new_source'], $c['id']]
            );
            if ($c['reassigned']) {
                $reassignedFgIds[] = $c['id'];
            }
        }
        $out['bumped_rms'] += count(array_filter($rmChanges, static fn(array $c): bool => $c['reassigned']));
        $flag = self::flagFinishedGoods($reassignedFgIds, $userId, $reason);
        $out['bumped_rms'] += $flag['bumped_rms'];
        $out['queued']      = $flag['queued'];
        $out['applied']     = true;
        return $out;
    }

    /**
     * Mark finished goods for republish after a family (or family text) change.
     * - Bulk publish: bump ONE raw material per FG (the least-shared one in its
     *   formula tree) only when the FG has a published SDS and its current
     *   formula is not already newer than that SDS (otherwise it is stale anyway).
     * - SDS Updates page: one pending sds_update_queue row per published FG.
     *
     * @param int[] $fgIds
     * @return array{bumped_rms:int, queued:int}
     */
    public static function flagFinishedGoods(array $fgIds, ?int $userId, string $reason): array
    {
        $fgIds = array_values(array_unique(array_map('intval', $fgIds)));
        if ($fgIds === []) {
            return ['bumped_rms' => 0, 'queued' => 0];
        }
        $db = Database::getInstance();
        $ph = implode(',', array_fill(0, count($fgIds), '?'));

        $lastPub = [];
        foreach ($db->fetchAll(
            "SELECT finished_good_id, MAX(published_at) AS last FROM sds_versions
             WHERE status = 'published' AND is_deleted = 0 AND alias_id IS NULL AND finished_good_id IN ({$ph})
             GROUP BY finished_good_id", $fgIds
        ) as $r) {
            $lastPub[(int) $r['finished_good_id']] = $r['last'];
        }
        if ($lastPub === []) {
            return ['bumped_rms' => 0, 'queued' => 0]; // never published: nothing to republish
        }

        $formulaByFg = [];
        $createdAt   = [];
        foreach ($db->fetchAll('SELECT id, finished_good_id, created_at FROM formulas WHERE is_current = 1') as $r) {
            $formulaByFg[(int) $r['finished_good_id']] = (int) $r['id'];
            $createdAt[(int) $r['id']]                 = $r['created_at'];
        }
        $lines = [];
        foreach ($db->fetchAll(
            'SELECT fl.formula_id, fl.raw_material_id, fl.finished_good_component_id
             FROM formula_lines fl JOIN formulas f ON f.id = fl.formula_id AND f.is_current = 1'
        ) as $r) {
            $lines[(int) $r['formula_id']][] = [
                'rm' => $r['raw_material_id'] !== null ? (int) $r['raw_material_id'] : null,
                'fg' => $r['finished_good_component_id'] !== null ? (int) $r['finished_good_component_id'] : null,
            ];
        }
        $usage = [];
        foreach ($db->fetchAll(
            'SELECT fl.raw_material_id, COUNT(DISTINCT fl.formula_id) AS n
             FROM formula_lines fl JOIN formulas f ON f.id = fl.formula_id AND f.is_current = 1
             WHERE fl.raw_material_id IS NOT NULL GROUP BY fl.raw_material_id'
        ) as $r) {
            $usage[(int) $r['raw_material_id']] = (int) $r['n'];
        }

        // Least-shared RM of a formula tree: direct RM lines first, then one level deeper, etc.
        $pick = function (int $formulaId, array $visited) use (&$pick, $lines, $formulaByFg, $usage): ?int {
            if (isset($visited[$formulaId])) {
                return null;
            }
            $visited[$formulaId] = true;
            $best = null;
            foreach ($lines[$formulaId] ?? [] as $l) {
                if ($l['rm'] === null) {
                    continue;
                }
                $n = $usage[$l['rm']] ?? 0;
                if ($best === null || $n < $best[1] || ($n === $best[1] && $l['rm'] < $best[0])) {
                    $best = [$l['rm'], $n];
                }
            }
            if ($best !== null) {
                return $best[0];
            }
            foreach ($lines[$formulaId] ?? [] as $l) {
                if ($l['fg'] !== null && isset($formulaByFg[$l['fg']])) {
                    $rm = $pick($formulaByFg[$l['fg']], $visited);
                    if ($rm !== null) {
                        return $rm;
                    }
                }
            }
            return null;
        };

        $bump = [];
        foreach ($fgIds as $fgId) {
            if (!isset($lastPub[$fgId]) || !isset($formulaByFg[$fgId])) {
                continue;
            }
            $fid = $formulaByFg[$fgId];
            if (($createdAt[$fid] ?? '') > $lastPub[$fgId]) {
                continue; // formula newer than the SDS: bulk publish already sees it as stale
            }
            $rm = $pick($fid, []);
            if ($rm !== null) {
                $bump[$rm] = true;
            }
        }
        $bumped = 0;
        if ($bump !== []) {
            $ids = array_keys($bump);
            $db->query('UPDATE raw_materials SET updated_at = UTC_TIMESTAMP() WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
            $bumped = count($ids);
        }

        $queued = 0;
        foreach ($db->fetchAll("SELECT id FROM finished_goods WHERE is_active = 1 AND id IN ({$ph})", $fgIds) as $r) {
            $fgId = (int) $r['id'];
            if (!isset($lastPub[$fgId])) {
                continue;
            }
            if ($db->fetch("SELECT id FROM sds_update_queue WHERE finished_good_id = ? AND status = 'pending'", [$fgId])) {
                continue;
            }
            $db->insert('sds_update_queue', [
                'finished_good_id' => $fgId,
                'reason'           => mb_substr($reason, 0, 500),
                'source_type'      => 'finished_good',
                'source_id'        => null,
                'queued_by'        => $userId,
            ]);
            $queued++;
        }
        return ['bumped_rms' => $bumped, 'queued' => $queued];
    }

    /**
     * A family's default text changed: every raw material resolved to it is
     * bumped (content change) and every product resolved to it is flagged.
     *
     * @return array{raw_materials:int, finished_goods:int, bumped_rms:int, queued:int}
     */
    public static function flagFamilyTextChange(int $familyId, ?int $userId, string $reason): array
    {
        $db    = Database::getInstance();
        $rmIds = array_map(static fn(array $r): int => (int) $r['id'], $db->fetchAll('SELECT id FROM raw_materials WHERE family_id = ?', [$familyId]));
        $fgIds = array_map(static fn(array $r): int => (int) $r['id'], $db->fetchAll('SELECT id FROM finished_goods WHERE family_id = ?', [$familyId]));
        $bumped = 0;
        if ($rmIds !== []) {
            $db->query('UPDATE raw_materials SET updated_at = UTC_TIMESTAMP() WHERE id IN (' . implode(',', array_fill(0, count($rmIds), '?')) . ')', $rmIds);
            $bumped = count($rmIds);
        }
        $flag = self::flagFinishedGoods($fgIds, $userId, $reason);
        return ['raw_materials' => count($rmIds), 'finished_goods' => count($fgIds), 'bumped_rms' => $bumped + $flag['bumped_rms'], 'queued' => $flag['queued']];
    }

    /** One-line summary for flash messages (mirrors AdminController::bumpedTail wording). */
    public static function summaryLine(array $r): string
    {
        $c = $r['counts'];
        if ($c['rm_changed'] + $c['fg_changed'] === 0) {
            return ' Product families: no changes.';
        }
        $s = " Product families: {$c['reassigned']} item(s) reassigned ({$c['rm_changed']} raw material row(s), {$c['fg_changed']} finished good row(s) written).";
        if (($r['bumped_rms'] ?? 0) > 0) {
            $s .= ' ' . $r['bumped_rms'] . ' raw material(s) flagged for re-publish.';
        }
        if (($r['queued'] ?? 0) > 0) {
            $s .= ' ' . $r['queued'] . ' SDS update(s) queued.';
        }
        return $s;
    }

    /**
     * Audit #61 / Q13: finished goods still carrying the manual family link
     * migration 053 (step 5c) created from the legacy family name. The set is
     * the snapshot migration 059 took (fg_legacy_family_picks) BEFORE anything
     * bumped finished_goods.updated_at (059's #48 block, ProductStaleness on
     * SDS text / hazard-override edits), so those bumps no longer hide a pick.
     * A pick still counts while the product is manual on the SAME family; a
     * family re-picked (or set to Auto) on the product form drops out.
     *
     * @return int[]
     */
    public static function legacyManualFinishedGoodIds(Database $db): array
    {
        $rows = $db->fetchAll(
            "SELECT fg.id
               FROM finished_goods fg
               JOIN fg_legacy_family_picks lp
                 ON lp.finished_good_id = fg.id AND lp.family_id = fg.family_id
              WHERE fg.family_source = 'manual' AND fg.family_id IS NOT NULL
              ORDER BY fg.id"
        );
        return array_map(static fn(array $r): int => (int) $r['id'], $rows);
    }

    /**
     * Q13: pure form of the legacy-pick rule (DB-free tests): manual, linked,
     * and still on the family 053 linked ($snapshot: finished_good_id =>
     * family_id from fg_legacy_family_picks). updated_at plays no part.
     */
    public static function isLegacyManualPick(array $fgRow, array $snapshot): bool
    {
        $id  = (int) ($fgRow['id'] ?? 0);
        $fam = (int) ($fgRow['family_id'] ?? 0);
        return ($fgRow['family_source'] ?? null) === 'manual'
            && $fam > 0
            && isset($snapshot[$id])
            && (int) $snapshot[$id] === $fam;
    }

    /**
     * Finished goods whose formula tree contains any of the given RMs / FGs
     * (plus the given FGs themselves), following finished_good_component_id
     * lines and RM-as-FG re-routed lines upward to a fixpoint.
     *
     * @return array<int,true>
     */
    private static function ancestorsOf(array $d, array $rmIds, array $fgIds): array
    {
        $fgOfFormula = array_flip($d['formula_by_fg']);          // formula id => fg id
        $fgIdByCode  = [];
        foreach ($d['finished_goods'] as $id => $fg) {
            if (isset($d['formula_by_fg'][(int) $id])) {
                $fgIdByCode[strtoupper((string) $fg['product_code'])] = (int) $id;
            }
        }
        $parentsOfFg = [];
        $parentsOfRm = [];
        foreach ($d['lines_by_formula'] as $formulaId => $lines) {
            $parent = $fgOfFormula[$formulaId] ?? null;
            if ($parent === null) {
                continue;
            }
            foreach ($lines as $l) {
                if ($l['fg'] !== null) {
                    $parentsOfFg[$l['fg']][$parent] = true;
                } elseif ($l['rm'] !== null) {
                    $parentsOfRm[$l['rm']][$parent] = true;
                    $code = strtoupper((string) ($d['raw_materials'][$l['rm']]['internal_code'] ?? ''));
                    $as   = $fgIdByCode[$code] ?? ($fgIdByCode[strtoupper(self::baseCode($code))] ?? null);
                    if ($as !== null) {
                        $parentsOfFg[$as][$parent] = true;
                    }
                }
            }
        }
        $set   = array_fill_keys(array_map('intval', $fgIds), true);
        $queue = array_keys($set);
        foreach ($rmIds as $rmId) {
            foreach ($parentsOfRm[(int) $rmId] ?? [] as $p => $_) {
                if (!isset($set[$p])) {
                    $set[$p] = true;
                    $queue[] = $p;
                }
            }
        }
        while ($queue !== []) {
            $fg = array_pop($queue);
            foreach ($parentsOfFg[$fg] ?? [] as $p => $_) {
                if (!isset($set[$p])) {
                    $set[$p] = true;
                    $queue[] = $p;
                }
            }
        }
        return $set;
    }
}
