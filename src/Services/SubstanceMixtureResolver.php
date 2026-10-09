<?php

declare(strict_types=1);

namespace SDS\Services;

/**
 * SubstanceMixtureResolver — SDS Section 3 "Type:" line (audit #6).
 *
 * Decision (docs/sds-content-audit.md, Decisions log #6): default Mixture.
 * A sheet prints "Substance" only when
 *   - the finished good's own substance_mixture column says 'substance'; or
 *   - that column says 'auto' AND the current formula has exactly one line
 *     AND that line is a raw material whose own column says 'substance'; or
 *   - (resale path) the raw material's own column says 'substance'.
 * 'auto' on a raw material means Mixture. 'mixture' on a finished good
 * forces Mixture even for a single Substance line.
 *
 * Aliases and private label variants copy Section 3 from the base sheet
 * (SDSGenerator::createAliasVariant / createPrivateLabelVariant), so they
 * inherit without any code here.
 *
 * Pure functions, no DB access, so the rules run in the DB-free test suite.
 * Callers: SDSGenerator::generate() / computeBase() /
 * computeBaseForResaleRawMaterial(); FinishedGood / RawMaterial models
 * validate posted values with isValid() + normalize().
 */
final class SubstanceMixtureResolver
{
    public const AUTO      = 'auto';
    public const SUBSTANCE = 'substance';
    public const MIXTURE   = 'mixture';

    /** Exactly the values the finished_goods / raw_materials ENUM accepts. */
    public const VALUES = [self::AUTO, self::SUBSTANCE, self::MIXTURE];

    /**
     * Normalise a stored or posted value to one of VALUES.
     * Blank, null, unknown and non-string input all become 'auto'.
     */
    public static function normalize($value): string
    {
        if (!is_scalar($value)) {
            return self::AUTO;
        }
        $v = strtolower(trim((string) $value));
        return in_array($v, self::VALUES, true) ? $v : self::AUTO;
    }

    /**
     * True when a posted value is blank (treated as 'auto') or one of VALUES.
     * The form save paths reject anything else so garbage never reaches the ENUM.
     */
    public static function isValid($value): bool
    {
        if ($value === null) {
            return true;
        }
        if (!is_scalar($value)) {
            return false;
        }
        $v = strtolower(trim((string) $value));
        return $v === '' || in_array($v, self::VALUES, true);
    }

    /**
     * A raw material on its own (resale SDS) or as the sole formula line:
     * 'substance' only when its column says so; 'auto' and 'mixture' are Mixture.
     */
    public static function resolveForRawMaterial($rmSetting): string
    {
        return self::normalize($rmSetting) === self::SUBSTANCE ? self::SUBSTANCE : self::MIXTURE;
    }

    /**
     * Finished good: its own column wins; 'auto' derives from the CURRENT
     * formula's direct lines in Formula::getLines() shape (line_type,
     * raw_material_id, substance_mixture). A single line that is a finished
     * good component is a Mixture (decision #6 names the raw material only).
     *
     * @param mixed $fgSetting     finished_goods.substance_mixture (null for a synthetic FG row)
     * @param array $formulaLines  $calcResult['formula']['lines']
     */
    public static function resolveForFinishedGood($fgSetting, array $formulaLines): string
    {
        $own = self::normalize($fgSetting);
        if ($own !== self::AUTO) {
            return $own;
        }
        if (count($formulaLines) !== 1) {
            return self::MIXTURE;
        }
        $line = reset($formulaLines);
        if (!is_array($line)
            || ($line['line_type'] ?? 'raw_material') !== 'raw_material'
            || (int) ($line['raw_material_id'] ?? 0) <= 0) {
            return self::MIXTURE;
        }
        return self::resolveForRawMaterial($line['substance_mixture'] ?? null);
    }
}
