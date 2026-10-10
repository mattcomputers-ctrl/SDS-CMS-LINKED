<?php

declare(strict_types=1);

namespace SDS\Services;

/**
 * LegacyOverrideMatcher: second override-cleanup pass (audit #1, owner
 * decision Q12). Pure and DB-free: scripts/cleanup-legacy-overrides.php
 * passes one stored text_overrides row plus per-product context. The result
 * is a REASON_* code saying why the row is NOT operator text, or null to keep it.
 *
 * Before #36 the editor pre-filled every field and saved every non-empty
 * field. Those rows froze OLD automatic text that no longer equals today's
 * default, so pass 1 (cleanup-default-overrides.php) keeps them as "custom".
 *   blank              trimmed-empty text (#56)
 *   retired_field      key outside TextOverrideService::EDITABLE_FIELDS
 *                      (15.osha_status / 15.tsca_status since Q12, or never read)
 *   legacy_flash_point 9.flash_point in the generator's own format "[> ]n °C (m °F)"
 *                      equal to a raw material's flash point in this product
 *                      (the pre-Q1 "lowest raw" rule)
 *   legacy_dot         14.un_number / proper_shipping_name / hazard_class /
 *                      packing_group equal to the dot_transport_info value of a
 *                      constituent CAS of this product (pre-#27 lookup)
 *   legacy_text        equal to an old translation string (row language or the
 *                      English fallback) or a sentence hard-coded in
 *                      SDSGenerator / HazardEngine (e.g. old derivePPE)
 *   legacy_composite   two or more such strings joined by space / comma /
 *                      semicolon; ":placeholder" values accepted
 * Comparison: TextOverrideService::normalize() (whitespace-insensitive,
 * case-sensitive). 15.state_regs never had automatic text, so only the
 * blank / retired rules apply to it.
 */
final class LegacyOverrideMatcher
{
    public const REASON_BLANK            = 'blank';
    public const REASON_RETIRED_FIELD    = 'retired_field';
    public const REASON_LEGACY_FLASH     = 'legacy_flash_point';
    public const REASON_LEGACY_DOT       = 'legacy_dot';
    public const REASON_LEGACY_TEXT      = 'legacy_text';
    public const REASON_LEGACY_COMPOSITE = 'legacy_composite';

    /** Reasons that apply whatever the row's age (the editor never writes them today). */
    public const AGE_INDEPENDENT = [self::REASON_BLANK, self::REASON_RETIRED_FIELD];

    /** Shortest literal accepted as one composite segment (keeps 'None' + 'Note' joins out). */
    private const MIN_SEGMENT_LEN = 8;

    /** Fields that never had automatic text. */
    private const OPERATOR_ONLY = [15 => ['state_regs']];

    /** Section 14 field => dot_transport_info column. */
    private const DOT_FIELDS = [
        'un_number'            => 'un_number',
        'proper_shipping_name' => 'proper_shipping_name',
        'hazard_class'         => 'hazard_class',
        'packing_group'        => 'packing_group',
    ];

    /** @var array<string, list<string>> lang => strings */
    private array $translations;
    /** @var list<string> */
    private array $code;
    /** @var array<string, array{exact: array<string,bool>, plain: list<string>, patterns: list<list<string>>}> */
    private array $cache = [];

    /** @param array $data scripts/data/legacy-override-texts.php: ['translations' => [lang => list], 'code' => list] */
    public function __construct(array $data)
    {
        $this->translations = is_array($data['translations'] ?? null) ? $data['translations'] : [];
        $this->code         = array_values(array_filter((array) ($data['code'] ?? []), 'is_string'));
    }

    /**
     * @param array $context {raw_flash_points?: list<array{c: float, gt: bool}>, dot?: array<string, list<string>>}
     * @return string|null REASON_* or null (operator text: keep)
     */
    public function reason(int $section, string $key, string $language, string $text, array $context = []): ?string
    {
        $norm = TextOverrideService::normalize($text);
        if ($norm === '') {
            return self::REASON_BLANK;
        }
        if (!TextOverrideService::isEditable($section, $key)) {
            return self::REASON_RETIRED_FIELD;
        }
        if (in_array($key, self::OPERATOR_ONLY[$section] ?? [], true)) {
            return null;
        }
        if ($section === 9 && $key === 'flash_point'
            && self::isRawFlashPoint($norm, (array) ($context['raw_flash_points'] ?? []))) {
            return self::REASON_LEGACY_FLASH;
        }
        if ($section === 14 && isset(self::DOT_FIELDS[$key])) {
            foreach ((array) ($context['dot'][self::DOT_FIELDS[$key]] ?? []) as $v) {
                if (is_scalar($v) && TextOverrideService::normalize((string) $v) === $norm) {
                    return self::REASON_LEGACY_DOT;
                }
            }
        }
        $c = $this->candidates($language);
        if (isset($c['exact'][$norm])) {
            return self::REASON_LEGACY_TEXT;
        }
        return self::isComposite($norm, $c) ? self::REASON_LEGACY_COMPOSITE : null;
    }

    private static function isRawFlashPoint(string $norm, array $raws): bool
    {
        if (!preg_match('/^(>\s?)?(-?\d+(?:\.\d+)?)\s?°C\s\((-?\d+(?:\.\d+)?)\s?°F\)$/u', $norm, $m)) {
            return false;
        }
        $gt = ($m[1] ?? '') !== '';
        $c  = (float) $m[2];
        foreach ($raws as $r) {
            if (!is_array($r) || !isset($r['c']) || !is_numeric($r['c'])) {
                continue;
            }
            if (abs((float) $r['c'] - $c) < 0.05 && (bool) ($r['gt'] ?? false) === $gt) {
                return true;
            }
        }
        return false;
    }

    private function candidates(string $language): array
    {
        if (isset($this->cache[$language])) {
            return $this->cache[$language];
        }
        $strings = array_merge(
            (array) ($this->translations[$language] ?? []),
            $language !== 'en' ? (array) ($this->translations['en'] ?? []) : [], // TranslationService falls back to EN
            $this->code
        );
        $exact    = [];
        $plain    = [];
        $patterns = [];
        foreach ($strings as $s) {
            if (!is_string($s)) {
                continue;
            }
            $n = TextOverrideService::normalize($s);
            if ($n === '') {
                continue;
            }
            if (preg_match('/:[a-z_]+/', $n)) {
                $parts = preg_split('/:[a-z_]+/', $n);
                if (is_array($parts) && strlen(implode('', $parts)) >= self::MIN_SEGMENT_LEN) {
                    $patterns[implode("\0", $parts)] = $parts;
                }
                continue;
            }
            $exact[$n] = true;
            if (strlen($n) >= self::MIN_SEGMENT_LEN) {
                $plain[$n] = true;
            }
        }
        return $this->cache[$language] = [
            'exact'    => $exact,
            'plain'    => array_map('strval', array_keys($plain)),
            'patterns' => array_values($patterns),
        ];
    }

    /** Does $t split entirely into candidate segments (space / comma / semicolon between)? */
    private static function isComposite(string $t, array $c): bool
    {
        $len   = strlen($t);
        $stack = [0];
        $seen  = [0 => true];
        while ($stack !== []) {
            $pos  = array_pop($stack);
            $ends = [];
            foreach ($c['plain'] as $s) {
                $s  = (string) $s;
                $sl = strlen($s);
                if ($pos + $sl <= $len && substr_compare($t, $s, $pos, $sl) === 0) {
                    $ends[] = $pos + $sl;
                }
            }
            foreach ($c['patterns'] as $parts) {
                foreach (self::patternEnds($t, $parts, $pos) as $e) {
                    $ends[] = $e;
                }
            }
            foreach ($ends as $e) {
                while ($e < $len && strpos(' ,;', $t[$e]) !== false) {
                    $e++;
                }
                if ($e >= $len) {
                    return true;
                }
                if (!isset($seen[$e])) {
                    $seen[$e] = true;
                    $stack[]  = $e;
                }
            }
        }
        return false;
    }

    /**
     * Every end offset at which a ":placeholder" string ($parts = its literal
     * pieces) matches $t from $pos. Each placeholder stands for >= 1 character.
     *
     * @return list<int>
     */
    private static function patternEnds(string $t, array $parts, int $pos): array
    {
        $len   = strlen($t);
        $first = (string) $parts[0];
        $fl    = strlen($first);
        if ($fl > 0 && ($pos + $fl > $len || substr_compare($t, $first, $pos, $fl) !== 0)) {
            return [];
        }
        $ends = [$pos + $fl];
        for ($i = 1, $n = count($parts); $i < $n; $i++) {
            $lit  = (string) $parts[$i];
            $ll   = strlen($lit);
            $next = [];
            foreach ($ends as $start) {
                $from = $start + 1;
                if ($ll === 0) {                       // trailing placeholder: up to any boundary
                    for ($e = $from; $e <= $len; $e++) {
                        if ($e === $len || strpos(' ,;', $t[$e]) !== false) {
                            $next[$e] = true;
                        }
                    }
                    continue;
                }
                for ($off = $from; $off <= $len - $ll; $off = $p + 1) {
                    $p = strpos($t, $lit, $off);
                    if ($p === false) {
                        break;
                    }
                    $next[$p + $ll] = true;
                }
            }
            if ($next === []) {
                return [];
            }
            $ends = array_keys($next);
        }
        return $ends;
    }
}
