<?php
declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * A11 (owner 2026-10-12): the ONE shared "water-based" rule. Every
 * water-triggered sentence uses it, so a sheet cannot contradict itself.
 * In this batch only the freezing sentences use it (Sections 7 and 10).
 *   family override 'yes' / 'no' wins;
 *   'auto' (or no / inactive family) = water (CAS 7732-18-5) in the expanded
 *   composition (Formula::getExpandedComposition: through intermediates,
 *   concentration_pct = the conservative figure HazardEngine uses) at or above
 *   settings sds.water_based_threshold_pct (default 20) % of the formula.
 * Water held only in raw_materials.water_wt (no 7732-18-5 constituent) is not
 * counted; set the family to 'yes' for such lines. Pure apart from threshold().
 */
final class WaterBasedRule
{
    public const CAS_WATER             = '7732-18-5';
    public const SETTING_KEY           = 'sds.water_based_threshold_pct';
    public const DEFAULT_THRESHOLD_PCT = 20.0;
    public const OVERRIDES             = ['auto', 'yes', 'no'];

    /** Summed concentration_pct of CAS 7732-18-5 rows (composition is CAS-aggregated; summing is defensive). */
    public static function waterPct(array $composition): float
    {
        $sum = 0.0;
        foreach ($composition as $row) {
            if (is_array($row) && trim((string) ($row['cas_number'] ?? '')) === self::CAS_WATER) {
                $sum += (float) ($row['concentration_pct'] ?? 0);
            }
        }
        return round($sum, 6);
    }

    /** 'yes' | 'no' | 'auto' (anything else = 'auto'). */
    public static function normaliseOverride($value): string
    {
        $v = strtolower(trim((string) $value));
        return in_array($v, self::OVERRIDES, true) ? $v : 'auto';
    }

    public static function isWaterBased($override, array $composition, float $thresholdPct): bool
    {
        $o = self::normaliseOverride($override);
        if ($o === 'yes') {
            return true;
        }
        if ($o === 'no') {
            return false;
        }
        return self::waterPct($composition) >= $thresholdPct;
    }

    /** For a sheet row after SDSGenerator::attachFamily() (reads family_profile.water_based). */
    public static function forProduct(array $fg, array $composition, float $thresholdPct): bool
    {
        return self::isWaterBased($fg['family_profile']['water_based'] ?? 'auto', $composition, $thresholdPct);
    }

    /** Settings-form validation: a number from 1 to 100. Null = valid. */
    public static function thresholdInputError(string $raw): ?string
    {
        $v = trim($raw);
        if ($v === '' || !is_numeric($v)) {
            return 'Water-Based Threshold (%) must be a number between 1 and 100.';
        }
        $f = (float) $v;
        if ($f < 1 || $f > 100) {
            return 'Water-Based Threshold (%) must be between 1 and 100 (entered ' . $v . ').';
        }
        return null;
    }

    /** Stored value, else DEFAULT_THRESHOLD_PCT when missing / blank / invalid. */
    public static function normaliseThreshold(?string $stored): float
    {
        if ($stored === null || self::thresholdInputError($stored) !== null) {
            return self::DEFAULT_THRESHOLD_PCT;
        }
        return (float) trim($stored);
    }

    /** Current threshold from settings; default on any DB failure. Not cached (long-running publish workers). */
    public static function threshold(?Database $db = null): float
    {
        try {
            $db  = $db ?? Database::getInstance();
            $row = $db->fetch('SELECT `value` FROM settings WHERE `key` = ?', [self::SETTING_KEY]);
            return self::normaliseThreshold($row ? (string) $row['value'] : null);
        } catch (\Throwable $e) {
            return self::DEFAULT_THRESHOLD_PCT;
        }
    }
}
