<?php

declare(strict_types=1);

namespace SDS\Services;

/**
 * TemperatureParser — reads an operator-typed Section 9 temperature edit
 * (Flash Point, Initial Boiling Point) as degrees Celsius. Pure, DB-free.
 * Findings #8 and #44(2) of docs/sds-data-sources-findings.md.
 *
 * A valid edit is a number followed by a temperature unit: C / F, or the
 * words Celsius / Fahrenheit, the degree sign optional ("24 °C", "24°C",
 * "24 C", "75 F", "200 deg F"). A decimal comma is accepted ("23,5 °C").
 * The unit must stand alone, so "24 cP" or "ASTM D93" never read as a
 * temperature; a number glued to a letter ("D93") is never taken.
 *   - an explicit Celsius value wins wherever it sits ("75 °F (24 °C)" = 24);
 *   - otherwise the first Fahrenheit value is converted (not rounded);
 *   - '>' or '≥' anywhere sets gt (the real value is at least n);
 *   - '<' or '≤' (and no '>' / '≥') sets lt (the real value is below n).
 * Anything else — no number, or a number without a unit — is not a
 * temperature (null).
 */
final class TemperatureParser
{
    /** Section 9 editor fields that must hold a temperature (text_overrides keys). */
    public const VALIDATED_FIELDS = [9 => ['flash_point', 'boiling_point']];

    /** An "< n" edit is classified just below n so strict "< n" thresholds trip (22.95 < 23). */
    public const LESS_THAN_OFFSET = 0.05;

    private const NUMBER = '(?<![\w.,])([-\x{2212}]?\d+(?:[.,]\d+)?)';
    private const GAP    = '\s*(?:°|º|˚|deg(?:rees?)?\.?)?\s*';

    /** @return array{c: float, gt: bool, lt: bool}|null */
    public static function parse(?string $text): ?array
    {
        $s = trim((string) $text);
        if ($s === '') {
            return null;
        }
        $c = null;
        if (preg_match('/' . self::NUMBER . self::GAP . '(?:C|Celsius)(?![a-z])/iu', $s, $m)) {
            $c = self::toFloat($m[1]);
        } elseif (preg_match('/' . self::NUMBER . self::GAP . '(?:F|Fahrenheit)(?![a-z])/iu', $s, $m)) {
            $c = (self::toFloat($m[1]) - 32) * 5 / 9;
        }
        if ($c === null) {
            return null;
        }
        $gt = (bool) preg_match('/[>≥]/u', $s);
        $lt = !$gt && (bool) preg_match('/[<≤]/u', $s);
        return ['c' => $c, 'gt' => $gt, 'lt' => $lt];
    }

    /** The value thresholds compare against: n, or just below n for an "< n" edit. */
    public static function thresholdValue(array $parsed): float
    {
        return !empty($parsed['lt']) ? (float) $parsed['c'] - self::LESS_THAN_OFFSET : (float) $parsed['c'];
    }

    /**
     * Drop Section 9 temperature edits that are not a temperature from a
     * TextOverrideService::plan() result. A rejected field keeps whatever is
     * stored (its row is neither updated nor deleted).
     *
     * @return array{plan: array, rejected: list<array{section:int, key:string, text:string}>}
     */
    public static function filterPlan(array $plan): array
    {
        $rejected = [];
        $keep     = [];
        foreach ($plan['upsert'] ?? [] as $u) {
            $validated = in_array($u['key'], self::VALIDATED_FIELDS[$u['section']] ?? [], true);
            if ($validated && self::parse((string) $u['text']) === null) {
                $rejected[] = $u;
                continue;
            }
            $keep[] = $u;
        }
        $plan['upsert']             = $keep;
        $plan['counts']['stored']   = max(0, (int) ($plan['counts']['stored'] ?? 0) - count($rejected));
        $plan['counts']['rejected'] = count($rejected);
        return ['plan' => $plan, 'rejected' => $rejected];
    }

    private static function toFloat(string $n): float
    {
        return (float) str_replace([',', "\u{2212}"], ['.', '-'], $n);
    }
}
