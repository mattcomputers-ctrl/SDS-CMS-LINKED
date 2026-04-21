<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * AliasResolver — "Where does this alias's SDS data come from?"
 *
 * Aliases in the CMS are imported as plain text mappings: a
 * customer-facing code points at the company's own internal code. That
 * internal code may refer to:
 *
 *   - a finished good with a current formula (the normal case), OR
 *   - a raw material we buy and resell as-is.
 *
 * Rule:
 *   1. If the alias's internal_code_base matches a finished_goods.product_code
 *      AND that FG has a current formula, the alias is formula-based. The
 *      SDS / label / readiness check derives from that FG's formula.
 *   2. Otherwise, if a raw_material exists whose base internal_code matches,
 *      the alias is treated as 100 % of that raw material. The SDS derives
 *      from the raw material's hazard data.
 *   3. Otherwise the alias is unresolvable (no FG, no RM — return null).
 *
 * Pack extensions (everything after the first '-') never matter here —
 * pack size doesn't change hazard content. Resolution compares base codes
 * on both sides.
 *
 * The same resolver also powers label generation from a raw-material code
 * typed directly by the user (no alias involved): resolveByRawMaterialCode()
 * strips the pack extension and returns the first matching RM.
 */
class AliasResolver
{
    /**
     * Resolve an alias by its id.
     *
     * @return array{alias:array,type:string,fg:?array,rm:?array,display_code:string}|null
     *   Null when the alias doesn't exist or can't be resolved to an FG or RM.
     */
    public static function resolveByAliasId(int $aliasId): ?array
    {
        $db = Database::getInstance();
        $alias = $db->fetch(
            "SELECT id, customer_code, description, internal_code, internal_code_base
             FROM aliases
             WHERE id = ?",
            [$aliasId]
        );
        if ($alias === null) {
            return null;
        }
        return self::resolveAliasRow($alias, $db);
    }

    /**
     * Resolve an alias by its customer_code.
     *
     * Accepts both the full pack-variant code (e.g. MS1277-20) and the
     * pack-stripped base (MS1277). When a base is provided we return the
     * first matching alias row — pack size doesn't affect the data.
     */
    public static function resolveByCustomerCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $db = Database::getInstance();
        $base = self::stripPack($code);

        // Prefer an exact customer_code match so a user typing "MS1277-20"
        // gets that specific alias row; fall back to any alias with the
        // same base code.
        $alias = $db->fetch(
            "SELECT id, customer_code, description, internal_code, internal_code_base
             FROM aliases
             WHERE customer_code = ?
             LIMIT 1",
            [$code]
        );
        if ($alias === null) {
            $alias = $db->fetch(
                "SELECT id, customer_code, description, internal_code, internal_code_base
                 FROM aliases
                 WHERE SUBSTRING_INDEX(customer_code, '-', 1) = ?
                 LIMIT 1",
                [$base]
            );
        }

        if ($alias === null) {
            return null;
        }
        return self::resolveAliasRow($alias, $db);
    }

    /**
     * Resolve a raw-material code typed directly (no alias involved).
     *
     * Used by LabelController when the operator wants to print a label
     * for the resale item under its own code (e.g. DSS0100 or DSS0100-20),
     * not under an alias. Pack extension is stripped — any variant with
     * the same base code shares the same hazard data.
     *
     * @return array{alias:null,type:'resale',fg:null,rm:array,display_code:string}|null
     */
    public static function resolveByRawMaterialCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $db = Database::getInstance();
        $base = self::stripPack($code);

        $rm = self::findRawMaterialByBase($base, $db);
        if ($rm === null) {
            return null;
        }

        return [
            'alias'        => null,
            'type'         => 'resale',
            'fg'           => null,
            'rm'           => $rm,
            'display_code' => $base,
        ];
    }

    /**
     * Given an alias row, decide whether its SDS is formula- or resale-derived.
     */
    private static function resolveAliasRow(array $alias, Database $db): ?array
    {
        $base        = (string) $alias['internal_code_base'];
        $displayCode = self::stripPack((string) $alias['customer_code']);

        // Formula path: FG with a current formula takes precedence. An
        // FG row without a current formula falls through to the resale
        // check so a half-set-up item doesn't break label/SDS generation.
        $fg = $db->fetch(
            "SELECT fg.id, fg.product_code, fg.description, fg.family, fg.is_active,
                    fg.hazard_override_json, fg.hazard_override_mode,
                    fg.hazard_override_set_by, fg.hazard_override_set_at,
                    fg.hazard_override_rationale,
                    f.id AS formula_id, f.version AS formula_version
             FROM finished_goods fg
             INNER JOIN formulas f ON f.finished_good_id = fg.id AND f.is_current = 1
             WHERE fg.product_code = ?
             LIMIT 1",
            [$base]
        );

        if ($fg !== null) {
            return [
                'alias'        => $alias,
                'type'         => 'formula',
                'fg'           => $fg,
                'rm'           => null,
                'display_code' => $displayCode,
            ];
        }

        // Resale path: match on the base of the RM's internal_code.
        $rm = self::findRawMaterialByBase($base, $db);
        if ($rm !== null) {
            return [
                'alias'        => $alias,
                'type'         => 'resale',
                'fg'           => null,
                'rm'           => $rm,
                'display_code' => $displayCode,
            ];
        }

        return null;
    }

    /**
     * Find a raw material whose internal_code (pack-extension stripped)
     * matches the given base code. Returns the first match — pack
     * variants of the same base share hazard data.
     */
    private static function findRawMaterialByBase(string $base, Database $db): ?array
    {
        // SELECT * so we pick up whatever columns the schema actually
        // has — earlier attempts spelled out the column list and hit
        // a missing-column fatal because `color` lives on finished_goods,
        // not raw_materials. Callers use array access with defaulted
        // keys so a wider row here is harmless.
        return $db->fetch(
            "SELECT *
             FROM raw_materials
             WHERE SUBSTRING_INDEX(internal_code, '-', 1) = ?
             ORDER BY id ASC
             LIMIT 1",
            [$base]
        );
    }

    /**
     * Return the base code (everything before the first '-'). If the
     * input has no dash, it's returned unchanged.
     */
    public static function stripPack(string $code): string
    {
        $dash = strpos($code, '-');
        return $dash === false ? $code : substr($code, 0, $dash);
    }
}
