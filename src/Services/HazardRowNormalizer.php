<?php

declare(strict_types=1);

namespace SDS\Services;

/**
 * HazardRowNormalizer — per-class attribution of ingredient hazard rows
 * (findings #19 and #20, batch E track T2a).
 *
 * PubChem publishes one GHS label per substance: one signal word and one
 * list each of H-codes, P-codes and pictograms covering every notified
 * classification. PubChemConnector used to copy those substance-level lists
 * onto every hazard_classifications row, and HazardEngine::classify() merged
 * a row's lists as soon as that row's own class crossed its cut-off. A
 * Carc. 1B row triggering at 0.5 % therefore brought in the H301, GHS06 and
 * 'Danger' of an Acute Tox. 3 class that was below its own cut-off.
 *
 * This class keeps only what belongs to a row's own (class, category), with
 * GHSHazardData as the class -> code reference, and derives the classes a
 * row stands for when its class name cannot be read (PubChem's
 * 'Unclassified' fallback row, generic "Acute Toxicity" rows). Pure
 * functions, no database: PubChemConnector::storeResult() uses it at import
 * and HazardEngine::classify() at run time, so rows stored before this
 * change are corrected without a re-import.
 */
final class HazardRowNormalizer
{
    /** @var array<string, array<string, array<string, true>>>|null type => code => canonical => true */
    private static ?array $codeIndex = null;

    private const SIGNAL_RANK = ['Danger' => 2, 'Warning' => 1];

    /** Acute-toxicity canonicals (a generic "Acute Toxicity" row is split by route from its H-codes). */
    private const ACUTE_CANONICALS = [
        GHSHazardClass::ACUTE_TOXICITY_ORAL,
        GHSHazardClass::ACUTE_TOXICITY_DERMAL,
        GHSHazardClass::ACUTE_TOXICITY_INHALATION,
    ];

    /**
     * #20: a bare category token ('2', '1b') becomes 'Category 2' / 'Category 1B';
     * anything else is returned trimmed and unchanged.
     */
    public static function categoryDisplayFromToken(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^(\d+[A-C]?)$/i', $raw, $m)) {
            return 'Category ' . strtoupper($m[1]);
        }
        return $raw;
    }

    /**
     * Display category for a hazard_classes entry: the GHSHazardData wording
     * for (canonical, category) when it exists ('Category 1 (1A/1B)',
     * 'Category 3 (Narcotic Effects)'), else 'Category N' from the canonical
     * 'Cat N', else the canonical value, else the raw token normalised.
     */
    public static function categoryDisplay(?string $canonical, string $categoryCanon, string $raw): string
    {
        if ($canonical !== null && $canonical !== '') {
            $entry = self::entryFor($canonical, $categoryCanon, $raw);
            if ($entry !== null) {
                return (string) $entry['category'];
            }
        }
        if (preg_match('/^Cat (\d+[A-C]?)$/', $categoryCanon, $m)) {
            return 'Category ' . $m[1];
        }
        if ($categoryCanon !== '') {
            return $categoryCanon;
        }
        return self::categoryDisplayFromToken($raw);
    }

    /** H-code base used for class lookup: 'H350i' -> 'H350', 'H360FD' -> 'H360'. */
    public static function baseCode(string $code): string
    {
        $code = strtoupper(trim($code));
        return preg_match('/^(H\d{3})/', $code, $m) ? $m[1] : $code;
    }

    /** code => [canonical => true] for 'h_codes' | 'p_codes' | 'pictograms'. */
    private static function index(string $type): array
    {
        if (self::$codeIndex === null) {
            $idx = ['h_codes' => [], 'p_codes' => [], 'pictograms' => []];
            foreach (GHSHazardData::HAZARD_CLASSIFICATIONS as $entry) {
                $canonical = HazardClassAliases::normalize((string) ($entry['class'] ?? ''));
                if ($canonical === null) {
                    continue;
                }
                foreach (array_keys($idx) as $t) {
                    foreach (($entry[$t] ?? []) as $code) {
                        $key = $t === 'h_codes' ? self::baseCode((string) $code) : strtoupper(trim((string) $code));
                        $idx[$t][$key][$canonical] = true;
                    }
                }
            }
            self::$codeIndex = $idx;
        }
        return self::$codeIndex[$type] ?? [];
    }

    /**
     * Canonical classes GHSHazardData associates with a code ([] = unmapped).
     * H-codes are matched by base code and each '+' part counts.
     *
     * @return string[]
     */
    public static function canonicalsForCode(string $type, string $code): array
    {
        $idx = self::index($type);
        if ($type === 'h_codes') {
            $out = [];
            foreach (explode('+', $code) as $part) {
                foreach (array_keys($idx[self::baseCode($part)] ?? []) as $c) {
                    $out[$c] = true;
                }
            }
            return array_keys($out);
        }
        return array_keys($idx[strtoupper(trim($code))] ?? []);
    }

    /**
     * GHSHazardData entry for (canonical, canonical category). When several
     * entries share the pair (STOT SE 'Category 3 (Respiratory Irritation)'
     * and 'Category 3 (Narcotic Effects)' are both 'Cat 3'), the one whose
     * display category equals $categoryDisplay wins, then the one carrying one
     * of $hCodes, then the first listed (HazardEngine::getDefaultsForClassCategory()
     * always took the first).
     *
     * @param string[] $hCodes
     */
    public static function entryFor(string $canonical, string $categoryCanon, string $categoryDisplay = '', array $hCodes = []): ?array
    {
        if ($canonical === '' || $categoryCanon === '') {
            return null;
        }
        $matches = self::entriesFor($canonical, $categoryCanon);
        if (count($matches) <= 1) {
            return $matches[0] ?? null;
        }
        $wanted = mb_strtolower(trim($categoryDisplay));
        if ($wanted !== '') {
            foreach ($matches as $entry) {
                if (mb_strtolower((string) $entry['category']) === $wanted) {
                    return $entry;
                }
            }
        }
        $bases = [];
        foreach ($hCodes as $c) {
            foreach (explode('+', (string) $c) as $part) {
                $bases[self::baseCode($part)] = true;
            }
        }
        foreach ($matches as $entry) {
            foreach ((array) ($entry['h_codes'] ?? []) as $c) {
                if (isset($bases[self::baseCode((string) $c)])) {
                    return $entry;
                }
            }
        }
        return $matches[0];
    }

    /** Every GHSHazardData entry for (canonical, canonical category), in table order. */
    public static function entriesFor(string $canonical, string $categoryCanon): array
    {
        if ($canonical === '' || $categoryCanon === '') {
            return [];
        }
        $display = GHSHazardClass::displayName($canonical);
        $out = [];
        foreach (GHSHazardData::HAZARD_CLASSIFICATIONS as $entry) {
            if (($entry['class'] ?? null) === $display
                && HazardClassAliases::normalizeCategory((string) ($entry['category'] ?? '')) === $categoryCanon) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /** Rank of a canonical category: lower = more severe ('Cat 1' 10, 'Cat 1A' 10, 'Cat 1B' 11, 'Cat 2' 20 …). */
    private static function rank(string $categoryCanon): int
    {
        if (preg_match('/^Cat (\d+)([A-C])?$/', $categoryCanon, $m)) {
            return (int) $m[1] * 10 + (isset($m[2]) && $m[2] !== '' ? ord($m[2]) - ord('A') : 0);
        }
        return 500;
    }

    /**
     * (canonical, category) pairs implied by H-codes. Per H-code, its
     * GHSHazardData entries are the candidates; the first-listed class wins
     * when a code is shared by two classes (H250, H271, H272: liquids are
     * listed first). Within one class a plain integer category ('Cat 1') is
     * preferred when it is a candidate (H317, H334), else the most severe
     * (H300 -> Cat 1, H350 -> Cat 1A). Across codes the most severe category
     * per class wins. $within limits the classes; $prefix limits categories
     * to those starting with it ('Cat 2' -> 'Cat 2A' / 'Cat 2B').
     *
     * @param  string[]      $hCodes
     * @param  string[]|null $within
     * @return array<string, array{canonical:string, category_canonical:string, entry:array}> keyed by canonical
     */
    public static function impliedClasses(array $hCodes, ?array $within = null, string $prefix = ''): array
    {
        $out = [];
        foreach ($hCodes as $code) {
            foreach (explode('+', (string) $code) as $part) {
                $base = self::baseCode($part);
                if (!preg_match('/^H\d{3}$/', $base)) {
                    continue;
                }
                $byClass = [];
                foreach (GHSHazardData::HAZARD_CLASSIFICATIONS as $entry) {
                    if (!in_array($base, array_map([self::class, 'baseCode'], $entry['h_codes'] ?? []), true)) {
                        continue;
                    }
                    $canonical = HazardClassAliases::normalize((string) ($entry['class'] ?? ''));
                    if ($canonical === null || ($within !== null && !in_array($canonical, $within, true))) {
                        continue;
                    }
                    $cat = HazardClassAliases::normalizeCategory((string) ($entry['category'] ?? ''));
                    if ($prefix !== '' && !str_starts_with($cat, $prefix)) {
                        continue;
                    }
                    $byClass[$canonical][] = ['canonical' => $canonical, 'category_canonical' => $cat, 'entry' => $entry];
                }
                if ($byClass === []) {
                    continue;
                }
                $canonical  = array_key_first($byClass);
                $candidates = $byClass[$canonical];
                $pick = null;
                foreach ($candidates as $c) {
                    if (preg_match('/^Cat \d+$/', $c['category_canonical'])) {
                        $pick = $c;
                        break;
                    }
                }
                if ($pick === null) {
                    usort($candidates, fn($a, $b) => self::rank($a['category_canonical']) <=> self::rank($b['category_canonical']));
                    $pick = $candidates[0];
                }
                if (!isset($out[$canonical]) || self::rank($pick['category_canonical']) < self::rank($out[$canonical]['category_canonical'])) {
                    $out[$canonical] = $pick;
                }
            }
        }
        return $out;
    }

    /** Canonical class of a stored row: pre-backfilled column first, else runtime normalisation (as HazardEngine::classify()). */
    public static function rowCanonical(array $row): ?string
    {
        $c = $row['class_name_canonical'] ?? null;
        if ($c === null || $c === '') {
            $c = HazardClassAliases::normalize((string) ($row['class_name'] ?? ''));
        }
        return ($c === null || $c === '') ? null : (string) $c;
    }

    /** Canonical category of a stored row: pre-backfilled column first, else runtime normalisation. */
    public static function rowCategoryCanonical(array $row): string
    {
        $c = $row['category_canonical'] ?? null;
        if ($c === null || $c === '') {
            $c = HazardClassAliases::normalizeCategory((string) ($row['category'] ?? ''));
        }
        return (string) $c;
    }

    /** True for a class name that names acute toxicity without a route ("Acute Toxicity", "Acute Tox."). */
    private static function isGenericAcute(array $row): bool
    {
        $name = mb_strtolower(trim((string) ($row['class_name'] ?? '')));
        $name = (string) preg_replace('/\s+cat(egory)?\.?\s*\d.*$/', '', $name);
        return (bool) preg_match('/^acute\s+tox(icity|\.)?$/', trim($name));
    }

    /**
     * Run-time preparation of one CAS's stored rows for HazardEngine::classify():
     *   - a row whose class cannot be read becomes one row per class its
     *     H-codes imply, skipping classes the CAS already has a readable row
     *     for; a row that implies nothing is kept unchanged (legacy path,
     *     1 % default cut-off);
     *   - a generic "Acute Toxicity" row is split by route the same way
     *     (restricted to the three acute-toxicity classes);
     *   - a readable class whose category is blank, or has no GHSHazardData
     *     entry ('Cat 2' for eye irritation), takes the category its own
     *     H-codes imply within that class (and starting with the stored
     *     category number when there is one).
     * Returned rows carry class_name_canonical / category_canonical whenever
     * they could be determined; synthetic rows also carry 'implied_from'.
     */
    public static function expandRows(array $rows): array
    {
        $known = [];
        foreach ($rows as $r) {
            $c = self::rowCanonical($r);
            if ($c !== null && !self::isGenericAcute($r)) {
                $known[$c] = true;
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $canonical = self::rowCanonical($r);
            $hCodes    = array_keys(self::statementsFromJson($r['h_statements_json'] ?? null));
            $generic   = $canonical !== null && self::isGenericAcute($r);

            if ($canonical === null || $generic) {
                $implied = self::impliedClasses($hCodes, $generic ? self::ACUTE_CANONICALS : null);
                if ($implied === []) {
                    $out[] = $r;
                    continue;
                }
                foreach ($implied as $imp) {
                    if (isset($known[$imp['canonical']])) {
                        continue;
                    }
                    $known[$imp['canonical']] = true;
                    $syn = $r;
                    $syn['class_name']           = GHSHazardClass::displayName($imp['canonical']);
                    $syn['class_name_canonical'] = $imp['canonical'];
                    $syn['category']             = (string) $imp['entry']['category'];
                    $syn['category_canonical']   = $imp['category_canonical'];
                    $syn['implied_from']         = (string) ($r['class_name'] ?? '');
                    $out[] = $syn;
                }
                continue;
            }

            $catCanon = self::rowCategoryCanonical($r);
            if ($catCanon === '' || self::entryFor($canonical, $catCanon) === null) {
                $prefix   = preg_match('/^Cat \d+$/', $catCanon) ? $catCanon : '';
                $inferred = self::impliedClasses($hCodes, [$canonical], $prefix)[$canonical] ?? null;
                if ($inferred !== null) {
                    $r['class_name_canonical'] = $canonical;
                    $r['category_canonical']   = $inferred['category_canonical'];
                    $r['category']             = (string) $inferred['entry']['category'];
                }
            }
            $out[] = $r;
        }
        return $out;
    }

    /**
     * #19: what one triggered row contributes — the GHSHazardData defaults of
     * its own (class, category) plus those of the row's own codes that belong
     * to that class (to that exact category when GHSHazardData has it, else to
     * the class). A row H-code GHSHazardData maps to no class is kept when it
     * looks like a GHS H-code; unmapped P-codes are dropped (the class
     * defaults carry the precautionary statements, decision #5). Signal word:
     * the class default for an exact entry, else the strongest signal among the
     * class's entries whose H-code the row keeps, else null. A row without a
     * canonical class is returned unfiltered with its stored signal word.
     *
     * @return array{h: array<string, array>, p: array<string, array>, pictograms: string[], signal_word: ?string, dropped: string[]}
     */
    public static function contributionForRow(array $row, ?string $canonical, string $categoryCanon): array
    {
        $h   = self::statementsFromJson($row['h_statements_json'] ?? null);
        $p   = self::statementsFromJson($row['p_statements_json'] ?? null);
        $pic = self::listFromJson($row['pictograms_json'] ?? null);

        if ($canonical === null || $canonical === '') {
            $sw = $row['signal_word'] ?? null;
            return ['h' => $h, 'p' => $p, 'pictograms' => $pic, 'signal_word' => ($sw === '' ? null : $sw), 'dropped' => []];
        }

        $entry = self::entryFor($canonical, $categoryCanon, (string) ($row['category'] ?? ''), array_keys($h));
        // Codes of every entry sharing the row's (class, category) are its own
        // (STOT SE Cat 3 covers both H335 and H336); defaults come from $entry.
        $siblings = $entry !== null ? self::entriesFor($canonical, $categoryCanon) : [];
        $entryCodes = static function (string $type) use ($siblings): array {
            $set = [];
            foreach ($siblings as $sib) {
                foreach ((array) ($sib[$type] ?? []) as $c) {
                    $set[$type === 'h_codes' ? self::baseCode((string) $c) : strtoupper(trim((string) $c))] = true;
                }
            }
            return $set;
        };
        $belongs = static function (string $type, string $code) use ($canonical, $entry, $entryCodes): ?bool {
            $owners = self::canonicalsForCode($type, $code);
            if ($owners === []) {
                return null; // unmapped
            }
            if ($entry !== null) {
                $set = $entryCodes($type);
                if ($type === 'h_codes') {
                    foreach (explode('+', $code) as $part) {
                        if (isset($set[self::baseCode($part)])) {
                            return true;
                        }
                    }
                    return false;
                }
                return isset($set[strtoupper(trim($code))]);
            }
            return in_array($canonical, $owners, true);
        };

        $dropped = [];
        $keptH = [];
        foreach ($h as $code => $stmt) {
            $b = $belongs('h_codes', (string) $code);
            if ($b === true || ($b === null && preg_match('/^H[2-4]\d{2}/', (string) $code))) {
                $keptH[$code] = $stmt;
            } else {
                $dropped[] = (string) $code;
            }
        }
        $keptP = [];
        foreach ($p as $code => $stmt) {
            if ($belongs('p_codes', (string) $code) === true) {
                $keptP[$code] = $stmt;
            } else {
                $dropped[] = (string) $code;
            }
        }
        $keptPic = [];
        foreach ($pic as $code) {
            if ($belongs('pictograms', $code) === true) {
                $keptPic[$code] = true;
            } else {
                $dropped[] = $code;
            }
        }

        $signal = null;
        if ($entry !== null) {
            foreach ((array) ($entry['h_codes'] ?? []) as $c) {
                $keptH[$c] ??= ['code' => $c, 'text' => ''];
            }
            foreach ((array) ($entry['p_codes'] ?? []) as $c) {
                $keptP[$c] ??= ['code' => $c, 'text' => ''];
            }
            foreach ((array) ($entry['pictograms'] ?? []) as $c) {
                $keptPic[$c] = true;
            }
            $signal = $entry['signal_word'] ?? null;
        } else {
            $keptBases = [];
            foreach (array_keys($keptH) as $c) {
                foreach (explode('+', (string) $c) as $part) {
                    $keptBases[self::baseCode($part)] = true;
                }
            }
            $display = GHSHazardClass::displayName($canonical);
            foreach (GHSHazardData::HAZARD_CLASSIFICATIONS as $e) {
                if (($e['class'] ?? null) !== $display || empty($e['signal_word'])) {
                    continue;
                }
                foreach ((array) ($e['h_codes'] ?? []) as $c) {
                    if (isset($keptBases[self::baseCode((string) $c)])
                        && (self::SIGNAL_RANK[$e['signal_word']] ?? 0) > (self::SIGNAL_RANK[$signal] ?? 0)) {
                        $signal = $e['signal_word'];
                    }
                }
            }
        }

        return [
            'h'           => $keptH,
            'p'           => $keptP,
            'pictograms'  => array_keys($keptPic),
            'signal_word' => $signal,
            'dropped'     => array_values(array_unique($dropped)),
        ];
    }

    /**
     * Per-class rows for PubChemConnector::storeResult() (#19/#20): one row per
     * parsed class (or per class the H-codes imply when PubChem gave none),
     * each with its own codes and signal word and both canonical columns.
     *
     * @param  array $ghs PubChemConnector::parseGHSData() result
     * @return array<int, array{class_name:string, class_name_canonical:?string, category:string, category_canonical:string, signal_word:?string, h_statements:array, p_statements:array, pictograms:array}>
     */
    public static function rowsForStorage(array $ghs): array
    {
        $base = [
            'signal_word'       => $ghs['signal_word'] ?? null,
            'h_statements_json' => $ghs['hazard_statements'] ?? [],
            'p_statements_json' => $ghs['precautionary_statements'] ?? [],
            'pictograms_json'   => $ghs['pictogram_codes'] ?? [],
        ];
        $rows = [];
        foreach ($ghs['hazard_classes'] ?? [] as $hc) {
            $rows[] = $base + [
                'class_name' => (string) ($hc['class'] ?? ''),
                'category'   => self::categoryDisplayFromToken((string) ($hc['category'] ?? '')),
            ];
        }
        if ($rows === [] && !empty($ghs['hazard_statements'])) {
            $rows[] = $base + ['class_name' => 'Unclassified', 'category' => ''];
        }

        $out = [];
        foreach (self::expandRows($rows) as $r) {
            $canonical = self::rowCanonical($r);
            $catCanon  = self::rowCategoryCanonical($r);
            $c = self::contributionForRow($r, $canonical, $catCanon);
            $out[] = [
                'class_name'           => (string) ($r['class_name'] ?? ''),
                'class_name_canonical' => $canonical,
                'category'             => (string) ($r['category'] ?? ''),
                'category_canonical'   => $catCanon,
                'signal_word'          => $c['signal_word'],
                'h_statements'         => array_values($c['h']),
                'p_statements'         => array_values($c['p']),
                'pictograms'           => array_values($c['pictograms']),
            ];
        }
        return $out;
    }

    /** Decode an H/P list (JSON string or array of codes / {code,text}) keyed by the trimmed code. */
    private static function statementsFromJson(mixed $json): array
    {
        $list = is_string($json) ? json_decode($json, true) : $json;
        $out  = [];
        foreach (is_array($list) ? $list : [] as $s) {
            if (is_string($s) && trim($s) !== '') {
                $c = trim($s);
                $out[$c] = ['code' => $c, 'text' => ''];
            } elseif (is_array($s) && isset($s['code']) && trim((string) $s['code']) !== '') {
                $c = trim((string) $s['code']);
                $s['code'] = $c;
                $out[$c] = $s;
            }
        }
        return $out;
    }

    /** Decode a pictogram list (JSON string or array). */
    private static function listFromJson(mixed $json): array
    {
        $list = is_string($json) ? json_decode($json, true) : $json;
        $out  = [];
        foreach (is_array($list) ? $list : [] as $v) {
            if (is_string($v) && trim($v) !== '') {
                $out[strtoupper(trim($v))] = true;
            }
        }
        return array_keys($out);
    }
}
