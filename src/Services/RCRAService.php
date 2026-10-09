<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * RCRAService — US EPA RCRA hazardous-waste code lookup for Section 13
 * (audit #26).
 *
 * Matches a CAS-level composition (FormulaCalcService, already flattened
 * across sub-FG components) against rcra_waste_codes (admin page /rcra,
 * migration 055): toxicity-characteristic constituents (D004–D043,
 * 40 CFR 261.24) and listed wastes (F / K / P / U, 40 CFR 261.31–261.33).
 * The characteristic codes D001–D003 are NOT table-driven —
 * SDSGenerator::section13() derives them from the formula flash point and
 * the engine's H-codes.
 *
 * Same shape as HAPService: analyse() is the DB entry point used by the
 * generator; match() is the pure core the DB-free tests exercise. The
 * result carries names and codes only — never a concentration (audit #42).
 */
final class RCRAService
{
    /**
     * Listed-waste (F / K / P / U) codes of a component below this total weight %
     * are not reported (same floor as the Section 11/12 component listings).
     * Toxicity-characteristic (D) codes are reported at ANY concentration: with
     * the 20:1 TCLP extraction the regulatory level can be exceeded from well
     * under 0.1 % (D006 cadmium 1 mg/L, D009 mercury 0.2 mg/L, D018 benzene
     * 0.5 mg/L), and the rcra_code_d wording is already conditional.
     */
    public const MIN_CONCENTRATION_PCT = 0.1;

    /** Print order of the code kinds inside one component's parenthesis. */
    private const KIND_ORDER = ['D' => 0, 'F' => 1, 'K' => 2, 'P' => 3, 'U' => 4];

    /**
     * @param  array $composition  Expanded CAS-level composition from FormulaCalcService
     * @return array{components: array, has_matches: bool}  see match()
     */
    public static function analyse(array $composition): array
    {
        $casList = [];
        foreach ($composition as $component) {
            $cas = trim((string) ($component['cas_number'] ?? ''));
            if ($cas === '' || $cas === 'TRADE_SECRET') {
                continue;
            }
            $casList[$cas] = true;
        }
        if ($casList === []) {
            return self::match($composition, []);
        }

        $db           = Database::getInstance();
        $placeholders = implode(',', array_fill(0, count($casList), '?'));
        $rows         = $db->fetchAll(
            "SELECT cas_number, waste_code, description, kind, limit_mg_l
             FROM rcra_waste_codes
             WHERE cas_number IN ({$placeholders})
             ORDER BY cas_number, waste_code",
            array_keys($casList)
        );

        return self::match($composition, $rows);
    }

    /**
     * Pure matcher.
     *
     * @param  array $composition  rows with cas_number / chemical_name / concentration_pct /
     *                             is_trade_secret / trade_secret_description
     * @param  array $rows         rcra_waste_codes rows: cas_number, waste_code, description, kind, limit_mg_l
     * @return array{
     *   components: list<array{cas_number:string, chemical_name:string, is_trade_secret:bool,
     *                          trade_secret_description:?string,
     *                          codes: list<array{waste_code:string, kind:string, description:string, limit_mg_l:?float}>}>,
     *   has_matches: bool
     * }
     * Components are ordered by total weight % descending (the % itself is
     * NOT returned), then CAS; codes inside a component D, F, K, P, U then
     * by code. The same CAS reached through several raw materials is one
     * component.
     */
    public static function match(array $composition, array $rows): array
    {
        $byCas = [];
        foreach ($rows as $row) {
            $cas  = trim((string) ($row['cas_number'] ?? ''));
            $code = strtoupper(trim((string) ($row['waste_code'] ?? '')));
            if ($cas === '' || $code === '' || $cas === 'TRADE_SECRET') {
                continue;
            }
            $kind = strtoupper(trim((string) ($row['kind'] ?? '')));
            if ($kind === '') {
                $kind = substr($code, 0, 1);
            }
            $limit = $row['limit_mg_l'] ?? null;
            $byCas[$cas][$code] = [
                'waste_code'  => $code,
                'kind'        => $kind,
                'description' => (string) ($row['description'] ?? ''),
                'limit_mg_l'  => ($limit === null || $limit === '') ? null : (float) $limit,
            ];
        }
        if ($byCas === []) {
            return ['components' => [], 'has_matches' => false];
        }

        // Total each CAS across the composition (a CAS can arrive through
        // several raw materials); keep the first-seen name / trade-secret flags.
        $totals = [];
        $first  = [];
        foreach ($composition as $component) {
            $cas = trim((string) ($component['cas_number'] ?? ''));
            if ($cas === '' || !isset($byCas[$cas])) {
                continue;
            }
            $totals[$cas] = ($totals[$cas] ?? 0.0) + (float) ($component['concentration_pct'] ?? 0);
            $first[$cas]  = $first[$cas] ?? $component;
        }

        $components = [];
        foreach ($totals as $cas => $total) {
            $codes = array_values($byCas[$cas]);
            if ($total < self::MIN_CONCENTRATION_PCT) {
                // Below the floor only the toxicity-characteristic (D) codes survive.
                $codes = array_values(array_filter($codes, static fn (array $c): bool => $c['kind'] === 'D'));
            }
            if ($codes === [] || $total <= 0) {
                continue;
            }
            usort($codes, static function (array $a, array $b): int {
                return [self::KIND_ORDER[$a['kind']] ?? 9, $a['waste_code']]
                    <=> [self::KIND_ORDER[$b['kind']] ?? 9, $b['waste_code']];
            });
            $comp = $first[$cas];
            $components[] = [
                'cas_number'               => (string) $cas,
                'chemical_name'            => (string) ($comp['chemical_name'] ?? ''),
                'is_trade_secret'          => !empty($comp['is_trade_secret']),
                'trade_secret_description' => $comp['trade_secret_description'] ?? null,
                'codes'                    => $codes,
                '_sort'                    => $total,
            ];
        }
        usort($components, static function (array $a, array $b): int {
            return ($b['_sort'] <=> $a['_sort']) ?: strcmp($a['cas_number'], $b['cas_number']);
        });
        foreach ($components as &$c) {
            unset($c['_sort']); // audit #42: no exact percentage leaves this service
        }
        unset($c);

        return ['components' => $components, 'has_matches' => $components !== []];
    }
}
