<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * A12(b) / T4a (owner decisions 2026-10-12): STOT target organs and route of
 * exposure per CAS (cas_master.stot_se_organs / stot_se_route complete
 * H370/H371; stot_re_organs / stot_re_route complete H372/H373).
 *
 * HazardEngine::classify() calls resolve() with the per-CAS H-code map, so the
 * hazard result carries language-free 'stot_details' (code => organs, route,
 * cas) and 'stot_incomplete' (operator-warning input). SDSGenerator fills the
 * statement text per sheet language (statementText(), chronicText()); every
 * printed word comes from templates/translations/{lang}.php 'stot'. A code is
 * filled only when EVERY CAS behind it has organs for that exposure type and
 * no finished-good override / trade-secret entry also carries it; organs are
 * the union in ORGANS order; the route prints only when all those CAS state
 * the same single route (inhalation / oral / dermal). Pure except loadByCas(),
 * adminRows() and writeCasStot().
 */
final class StotTargetOrgans
{
    /** Stored organ keys, in print order => admin label (English admin UI only; printed text is stot.organ.<key>). */
    public const ORGANS = [
        'respiratory_tract'      => 'Respiratory tract',
        'lungs'                  => 'Lungs',
        'liver'                  => 'Liver',
        'kidneys'                => 'Kidneys',
        'central_nervous_system' => 'Central nervous system',
        'nervous_system'         => 'Nervous system',
        'blood'                  => 'Blood',
        'heart'                  => 'Heart',
        'eyes'                   => 'Eyes',
        'skin'                   => 'Skin',
        'gastrointestinal_tract' => 'Gastrointestinal tract',
        'bone_marrow'            => 'Bone marrow',
        'thyroid'                => 'Thyroid',
        'reproductive_organs'    => 'Reproductive organs',
        'hearing_organs'         => 'Hearing organs',
    ];

    /** Stored route keys => admin label. 'all' prints no route (GHS: state a route only when no other route causes the hazard). */
    public const ROUTES = [
        'inhalation' => 'Inhalation',
        'oral'       => 'Oral (ingestion)',
        'dermal'     => 'Dermal (skin contact)',
        'all'        => 'All routes (no route printed)',
    ];

    /** Routes that print (stot.route.<key>). */
    public const PRINTED_ROUTES = ['inhalation', 'oral', 'dermal'];

    /** H-code => exposure type ('se' / 're' columns). */
    public const CODE_EXPOSURE = ['H370' => 'se', 'H371' => 'se', 'H372' => 're', 'H373' => 're'];

    /** Valid organ keys from a CSV string or a POST array, deduplicated, in ORGANS order. */
    public static function parseOrgans(mixed $raw): array
    {
        $tokens = is_array($raw) ? $raw : (preg_split('/[\s,;]+/', (string) ($raw ?? '')) ?: []);
        $set = [];
        foreach ($tokens as $tok) {
            if (!is_scalar($tok)) {
                continue;
            }
            $tok = strtolower(trim((string) $tok));
            if (isset(self::ORGANS[$tok])) {
                $set[$tok] = true;
            }
        }
        return array_values(array_filter(array_keys(self::ORGANS), static fn(string $k): bool => isset($set[$k])));
    }

    public static function normaliseRoute(mixed $raw): ?string
    {
        if (!is_scalar($raw) && $raw !== null) {
            return null;
        }
        $r = strtolower(trim((string) ($raw ?? '')));
        return isset(self::ROUTES[$r]) ? $r : null;
    }

    /**
     * The four cas_master values as stored, from a DB row or the POST body
     * (stot_se_organs[] arrays or CSV): organs CSV|null, route|null. A route
     * without organs is dropped.
     *
     * @return array{stot_se_organs:?string,stot_se_route:?string,stot_re_organs:?string,stot_re_route:?string}
     */
    public static function columnValues(array $row): array
    {
        $out = [];
        foreach (['se', 're'] as $x) {
            $organs = self::parseOrgans($row['stot_' . $x . '_organs'] ?? null);
            $out['stot_' . $x . '_organs'] = $organs === [] ? null : implode(',', $organs);
            $out['stot_' . $x . '_route']  = $organs === [] ? null : self::normaliseRoute($row['stot_' . $x . '_route'] ?? null);
        }
        return $out;
    }

    /**
     * Validate and write one CAS's STOT target organs and routes on
     * cas_master (audited as 'cas_stot'); no bump, no flash. $values uses
     * the column names (stot_se_organs as a list or CSV, stot_se_route, ...).
     * Unticking every organ of an exposure type clears it and drops its
     * route (columnValues()). Shared by the single-CAS save and the STOT
     * tab's batch save (AdminController, 2026-10-21).
     *
     * @return array{cas:string, changed:bool}|array{error:string}
     */
    public static function writeCasStot(Database $db, string $casIn, array $values): array
    {
        $cas = TSCAService::normaliseCas($casIn);
        if ($cas === '' || !preg_match(TSCAService::CAS_PATTERN, $cas)) {
            return ['error' => 'A valid CAS number is required.'];
        }
        $new      = self::columnValues($values);
        $existing = $db->fetch(
            "SELECT stot_se_organs, stot_se_route, stot_re_organs, stot_re_route FROM cas_master WHERE cas_number = ?",
            [$cas]
        );
        $old = self::columnValues($existing ?? []);
        if ($old === $new) {
            return ['cas' => $cas, 'changed' => false];
        }
        if ($existing === null) {
            // Same as TSCAService::writeCasOverride(): create the registry row,
            // named from the most common constituent description.
            $nameRow = $db->fetch(
                "SELECT chemical_name FROM raw_material_constituents
                 WHERE cas_number = ? AND chemical_name <> ''
                 GROUP BY chemical_name ORDER BY COUNT(*) DESC LIMIT 1",
                [$cas]
            );
            $db->insert('cas_master', ['cas_number' => $cas, 'preferred_name' => (string) ($nameRow['chemical_name'] ?? '')]);
        }
        $db->update('cas_master', $new, 'cas_number = ?', [$cas]);
        AuditService::log('cas_stot', $cas, 'update', ['before' => $old, 'after' => $new]);
        return ['cas' => $cas, 'changed' => true];
    }

    /** @return array{se: array{organs: string[], route: ?string}, re: array{organs: string[], route: ?string}} */
    public static function flagsFromRow(array $row): array
    {
        $v = self::columnValues($row);
        return [
            'se' => ['organs' => self::parseOrgans($v['stot_se_organs']), 'route' => $v['stot_se_route']],
            're' => ['organs' => self::parseOrgans($v['stot_re_organs']), 'route' => $v['stot_re_route']],
        ];
    }

    /** CAS of the per-CAS H-code map that carry any of H370-H373 (the only ones loadByCas() reads). */
    public static function casCarryingStot(array $casHCodes): array
    {
        $out = [];
        foreach ($casHCodes as $cas => $codes) {
            if (array_intersect(array_map('strval', (array) $codes), array_keys(self::CODE_EXPOSURE)) !== []) {
                $out[] = (string) $cas;
            }
        }
        return $out;
    }

    /** @return array<string, array> CAS => flagsFromRow(); only CAS with organs; [] when none or 062 not applied. */
    public static function loadByCas(Database $db, array $casList): array
    {
        // Each CAS as typed and in canonical form (2026-10-21): the result is
        // keyed by the canonical CAS (resolve() looks it up that way), and the
        // row stored under the canonical CAS — what the STOT tab writes —
        // wins over one stored with another spelling (preferredRow()).
        $wanted = [];
        foreach ($casList as $c) {
            $c = trim((string) $c);
            if ($c !== '') {
                $wanted[$c] = true;
                $wanted[TSCAService::normaliseCas($c)] = true;
            }
        }
        unset($wanted['']);
        $casList = array_map('strval', array_keys($wanted));
        if ($casList === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($casList), '?'));
        try {
            $rows = $db->fetchAll(
                "SELECT cas_number, stot_se_organs, stot_se_route, stot_re_organs, stot_re_route
                   FROM cas_master
                  WHERE cas_number IN ({$ph})
                    AND (COALESCE(stot_se_organs, '') <> '' OR COALESCE(stot_re_organs, '') <> '')",
                $casList
            );
        } catch (\Throwable $e) {
            return []; // migration 062 not applied yet: generic statements, as before
        }
        $out    = [];
        $stored = [];
        foreach ($rows as $r) {
            $key = TSCAService::normaliseCas((string) $r['cas_number']);
            if (!isset($stored[$key]) || self::preferredRow((string) $r['cas_number'], $stored[$key], $key)) {
                $out[$key]    = self::flagsFromRow($r);
                $stored[$key] = (string) $r['cas_number'];
            }
        }
        return $out;
    }

    /**
     * Of two cas_master rows for one canonical CAS (one stored with another
     * spelling), whether $candidate beats $current: the row stored under
     * the canonical CAS wins, else the lowest stored spelling, so the STOT
     * tab (mergeAdminRows()) and the sheet (loadByCas()) agree.
     */
    public static function preferredRow(string $candidate, string $current, string $canonical): bool
    {
        if ($current === $canonical) {
            return false;
        }
        return $candidate === $canonical || strcmp($candidate, $current) < 0;
    }

    /**
     * @param string[]               $hCodes        the product's final H-codes (keys of HazardEngine $allHStmts)
     * @param array<string,string[]> $casHCodes     HazardEngine::buildCasHCodeMap() output (pseudo keys already skipped)
     * @param array                  $hazardClasses UNconsolidated class entries (finds FG_OVERRIDE / TRADE_SECRET sources)
     * @param array                  $flagsByCas    loadByCas() output (keyed by canonical CAS)
     * @return array{details: array<string, array{organs: string[], route: ?string, cas: string[]}>,
     *               incomplete: array<string, array{flagged: string[], unflagged: string[], other_source: bool}>}
     */
    public static function resolve(array $hCodes, array $casHCodes, array $hazardClasses, array $flagsByCas): array
    {
        $present = [];
        foreach ($hCodes as $c) {
            foreach (explode('+', strtoupper((string) $c)) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $present[$part] = true;
                }
            }
        }
        $details    = [];
        $incomplete = [];
        foreach (self::CODE_EXPOSURE as $code => $exp) {
            if (!isset($present[$code])) {
                continue;
            }
            $drivers = [];
            foreach ($casHCodes as $cas => $codes) {
                $cas = (string) $cas;
                if (!self::isPseudo($cas) && in_array($code, array_map('strval', (array) $codes), true)) {
                    $drivers[] = $cas;
                }
            }
            if ($drivers === []) {
                continue; // override-only / trade-secret-only code: generic statement
            }
            sort($drivers, SORT_STRING);
            $flagged = [];
            $unflagged = [];
            foreach ($drivers as $cas) {
                if (($flagsByCas[TSCAService::normaliseCas((string) $cas)][$exp]['organs'] ?? []) !== []) {
                    $flagged[] = $cas;
                } else {
                    $unflagged[] = $cas;
                }
            }
            if ($flagged === []) {
                continue; // nothing entered: today's generic statement, no warning
            }
            $other = self::hasOtherSource($code, $hazardClasses);
            if ($unflagged !== [] || $other) {
                $incomplete[$code] = ['flagged' => $flagged, 'unflagged' => $unflagged, 'other_source' => $other];
                continue;
            }
            $set    = [];
            $routes = [];
            foreach ($flagged as $cas) {
                $f = $flagsByCas[TSCAService::normaliseCas((string) $cas)][$exp];
                foreach ($f['organs'] as $o) {
                    $set[(string) $o] = true;
                }
                $routes[(string) ($f['route'] ?? '')] = true;
            }
            $route = count($routes) === 1 ? (string) array_key_first($routes) : '';
            $details[$code] = [
                'organs' => array_values(array_filter(array_keys(self::ORGANS), static fn(string $k): bool => isset($set[$k]))),
                'route'  => in_array($route, self::PRINTED_ROUTES, true) ? $route : null,
                'cas'    => $flagged,
            ];
        }
        return ['details' => $details, 'incomplete' => $incomplete];
    }

    /** H370-H373 statement with organs (and route) in the TranslationService language; '' when not fillable. No trailing period. */
    public static function statementText(string $code, array $detail, TranslationService $t): string
    {
        $code   = strtoupper(trim($code));
        $organs = self::organList((array) ($detail['organs'] ?? []), $t);
        if (!isset(self::CODE_EXPOSURE[$code]) || $organs === '') {
            return '';
        }
        return self::fill('stot.' . strtolower($code), $organs, $detail['route'] ?? null, $t);
    }

    /** Section 11 STOT RE chronic fragment (H372/H373) with organs; '' when not fillable. */
    public static function chronicText(string $code, array $detail, TranslationService $t): string
    {
        $key    = ['H372' => 'stot.chronic_re_1', 'H373' => 'stot.chronic_re_2'][strtoupper(trim($code))] ?? null;
        $organs = self::organList((array) ($detail['organs'] ?? []), $t);
        if ($key === null || $organs === '') {
            return '';
        }
        return self::fill($key, $organs, $detail['route'] ?? null, $t);
    }

    /** Operator warnings (preview Warnings box / publish flash; never printed, never blocking). */
    public static function warnings(array $hazardResult): array
    {
        $out = [];
        foreach ((array) ($hazardResult['stot_incomplete'] ?? []) as $code => $inc) {
            if (!is_array($inc)) {
                continue;
            }
            $also = [];
            if (!empty($inc['unflagged'])) {
                $also[] = 'CAS ' . implode(', ', (array) $inc['unflagged']) . ' (no target organs entered)';
            }
            if (!empty($inc['other_source'])) {
                $also[] = 'a finished-good hazard override or trade-secret declaration (no target organs can be entered there)';
            }
            $out[] = sprintf(
                'Section 2 warning: %s comes from CAS %s (target organs entered) and also from %s, so Sections 2, 4 and 11 print the generic statement without organs. Organs print only when every source of the statement has them (CAS Determinations > STOT Target Organs). Publishing is not blocked.',
                (string) $code,
                implode(', ', (array) ($inc['flagged'] ?? [])),
                implode(' and ', $also)
            );
        }
        return $out;
    }

    /**
     * Rows of the /determinations "STOT Target Organs" tab: every CAS whose
     * current hazard data or active determination carries H370-H373 and that
     * is used in a raw material, plus every CAS with organs entered.
     * [] when 062 is not applied.
     */
    public static function adminRows(Database $db): array
    {
        try {
            $rows = $db->fetchAll(
                "SELECT x.cas_number,
                        COALESCE(NULLIF(cm.preferred_name, ''),
                                 (SELECT rmc.chemical_name FROM raw_material_constituents rmc
                                   WHERE rmc.cas_number = x.cas_number AND rmc.chemical_name <> ''
                                   GROUP BY rmc.chemical_name ORDER BY COUNT(*) DESC LIMIT 1)) AS chemical_name,
                        cm.stot_se_organs, cm.stot_se_route, cm.stot_re_organs, cm.stot_re_route,
                        MAX(x.has_se) AS has_se, MAX(x.has_re) AS has_re,
                        (SELECT COUNT(DISTINCT rmc2.raw_material_id) FROM raw_material_constituents rmc2
                          WHERE rmc2.cas_number = x.cas_number) AS rm_count
                   FROM (
                         SELECT hc.cas_number,
                                ((hc.class_name_canonical = 'stot_single' AND hc.category_canonical IN ('Cat 1','Cat 2'))
                                  OR CAST(hc.h_statements_json AS CHAR) REGEXP 'H37[01]') AS has_se,
                                ((hc.class_name_canonical = 'stot_repeated' AND hc.category_canonical IN ('Cat 1','Cat 2'))
                                  OR CAST(hc.h_statements_json AS CHAR) REGEXP 'H37[23]') AS has_re
                           FROM hazard_classifications hc
                           JOIN hazard_source_records hsr ON hsr.id = hc.hazard_source_record_id AND hsr.is_current = 1
                          WHERE (hc.class_name_canonical IN ('stot_single','stot_repeated') AND hc.category_canonical IN ('Cat 1','Cat 2'))
                             OR CAST(hc.h_statements_json AS CHAR) REGEXP 'H37[0-3]'
                         UNION ALL
                         SELECT cpd.cas_number,
                                CAST(cpd.determination_json AS CHAR) REGEXP 'H37[01]',
                                CAST(cpd.determination_json AS CHAR) REGEXP 'H37[23]'
                           FROM competent_person_determinations cpd
                          WHERE cpd.is_active = 1 AND CAST(cpd.determination_json AS CHAR) REGEXP 'H37[0-3]'
                         UNION ALL
                         SELECT cm2.cas_number, 0, 0
                           FROM cas_master cm2
                          WHERE COALESCE(cm2.stot_se_organs, '') <> '' OR COALESCE(cm2.stot_re_organs, '') <> ''
                        ) x
                   LEFT JOIN cas_master cm ON cm.cas_number = x.cas_number
                  WHERE EXISTS (SELECT 1 FROM raw_material_constituents r WHERE r.cas_number = x.cas_number)
                     OR COALESCE(cm.stot_se_organs, '') <> '' OR COALESCE(cm.stot_re_organs, '') <> ''
                  GROUP BY x.cas_number, cm.preferred_name, cm.stot_se_organs, cm.stot_se_route, cm.stot_re_organs, cm.stot_re_route
                  ORDER BY (COALESCE(cm.stot_se_organs, '') = '' AND COALESCE(cm.stot_re_organs, '') = '') DESC, x.cas_number"
            );
        } catch (\Throwable $e) {
            return [];
        }
        return self::mergeAdminRows($rows);
    }

    /**
     * DB-free (2026-10-21): adminRows() rows merged per canonical CAS
     * (TSCAService::normaliseCas()), so a substance typed two ways ('67–64–1'
     * and '67-64-1') is one row and the tab saves the CAS the writer stores.
     * Organs and routes come from the row loadByCas() prints from
     * (preferredRow()); the classification flags are OR-ed and the
     * raw-material counts added (each spelling counts its own constituents).
     * Substances without organs first, then by CAS.
     */
    public static function mergeAdminRows(array $rows): array
    {
        $out    = [];
        $stored = [];
        foreach ($rows as $r) {
            $spelling = (string) ($r['cas_number'] ?? '');
            $key      = TSCAService::normaliseCas($spelling);
            if ($key === '') {
                continue;
            }
            if (!isset($out[$key])) {
                $out[$key] = ['cas_number' => $key, 'chemical_name' => null, 'stot_se_organs' => null, 'stot_se_route' => null,
                              'stot_re_organs' => null, 'stot_re_route' => null, 'has_se' => 0, 'has_re' => 0, 'rm_count' => 0];
            }
            $out[$key]['has_se']   = max((int) $out[$key]['has_se'], (int) ($r['has_se'] ?? 0));
            $out[$key]['has_re']   = max((int) $out[$key]['has_re'], (int) ($r['has_re'] ?? 0));
            $out[$key]['rm_count'] = (int) $out[$key]['rm_count'] + (int) ($r['rm_count'] ?? 0);
            if ((string) ($out[$key]['chemical_name'] ?? '') === '' && (string) ($r['chemical_name'] ?? '') !== '') {
                $out[$key]['chemical_name'] = $r['chemical_name'];
            }
            $hasOrgans = trim((string) ($r['stot_se_organs'] ?? '')) !== '' || trim((string) ($r['stot_re_organs'] ?? '')) !== '';
            if ($hasOrgans && (!isset($stored[$key]) || self::preferredRow($spelling, $stored[$key], $key))) {
                foreach (['stot_se_organs', 'stot_se_route', 'stot_re_organs', 'stot_re_route'] as $col) {
                    $out[$key][$col] = $r[$col] ?? null;
                }
                $stored[$key] = $spelling;
            }
        }
        $out = array_values($out);
        usort($out, static function (array $a, array $b): int {
            $ea = trim((string) $a['stot_se_organs']) === '' && trim((string) $a['stot_re_organs']) === '';
            $eb = trim((string) $b['stot_se_organs']) === '' && trim((string) $b['stot_re_organs']) === '';
            return [$eb, (string) $a['cas_number']] <=> [$ea, (string) $b['cas_number']];
        });
        return $out;
    }

    private static function fill(string $key, string $organs, mixed $route, TranslationService $t): string
    {
        $route = is_string($route) && in_array($route, self::PRINTED_ROUTES, true) ? $route : null;
        return $route === null
            ? $t->get($key, ['organs' => $organs])
            : $t->get($key . '_route', ['organs' => $organs, 'route' => $t->get('stot.route.' . $route)]);
    }

    private static function organList(array $organs, TranslationService $t): string
    {
        return implode(', ', array_map(static fn(string $k): string => $t->get('stot.organ.' . $k), self::parseOrgans($organs)));
    }

    /** A class entry with a pseudo source (FG_OVERRIDE, TRADE_SECRET, '', a MIXTURE entry with a pseudo contributor) carries $code. */
    private static function hasOtherSource(string $code, array $hazardClasses): bool
    {
        foreach ($hazardClasses as $hc) {
            if (!is_array($hc) || self::codeForClass($hc) !== $code) {
                continue;
            }
            $cas = (string) ($hc['cas'] ?? '');
            if ($cas === 'MIXTURE') {
                foreach ((array) ($hc['contributors'] ?? []) as $c) {
                    $c = is_array($c) ? (string) ($c['cas'] ?? '') : (string) $c;
                    if (self::isPseudo($c)) {
                        return true;
                    }
                }
                continue;
            }
            if (self::isPseudo($cas)) {
                return true;
            }
        }
        return false;
    }

    private static function codeForClass(array $hc): ?string
    {
        $canonical = (string) ($hc['canonical'] ?? '');
        if ($canonical === '') {
            $canonical = (string) (HazardClassAliases::normalize((string) ($hc['class'] ?? '')) ?? '');
        }
        $cat = (string) ($hc['category_canonical'] ?? '');
        if ($cat === '') {
            $cat = HazardClassAliases::normalizeCategory((string) ($hc['category'] ?? ''));
        }
        $map = [
            GHSHazardClass::STOT_SINGLE   => ['Cat 1' => 'H370', 'Cat 2' => 'H371'],
            GHSHazardClass::STOT_REPEATED => ['Cat 1' => 'H372', 'Cat 2' => 'H373'],
        ];
        return $map[$canonical][$cat] ?? null;
    }

    /** Same rule as HazardEngine::buildCasHCodeMap(). */
    private static function isPseudo(string $key): bool
    {
        return $key === '' || preg_match('/^[A-Z_]+$/', $key) === 1;
    }
}
