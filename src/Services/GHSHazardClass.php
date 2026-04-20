<?php

declare(strict_types=1);

namespace SDS\Services;

/**
 * GHSHazardClass — Canonical identifiers for GHS hazard classes.
 *
 * The engine and data model work in terms of these lowercase_snake codes
 * internally; display strings (English and translated) are resolved separately
 * via GHSHazardData and the templates/translations/ghs_*.php files.
 *
 * Anywhere a class_name string enters the engine (vendor hazard_classifications
 * rows, CPD determination_json, trade-secret manual_hazard_json, snapshot
 * replays), it should first pass through HazardClassAliases::normalize() to
 * produce one of the constants defined here. That gives us exact equality
 * lookups throughout the classification pipeline instead of fuzzy stripos
 * matching against display strings.
 *
 * Each canonical code maps to:
 *   - An English display name (displayName() — matches GHSHazardData's
 *     existing 'class' field exactly, and is the key in the ghs_*.php
 *     translation files' hazard_class_names section)
 *   - A GHS hazard group: 'physical' | 'health' | 'environmental' (group())
 *
 * Codes are stable — they appear in the hazard_classifications.class_name_canonical
 * column post-backfill, and any change to a code requires a migration.
 */
final class GHSHazardClass
{
    // ── Physical hazards (GHS Chapter 2) ────────────────────────────────
    public const EXPLOSIVES                 = 'explosives';
    public const FLAMMABLE_GASES            = 'flammable_gases';
    public const FLAMMABLE_AEROSOLS         = 'flammable_aerosols';
    public const FLAMMABLE_LIQUIDS          = 'flammable_liquids';
    public const FLAMMABLE_SOLIDS           = 'flammable_solids';
    public const SELF_REACTIVE              = 'self_reactive';
    public const PYROPHORIC_LIQUIDS         = 'pyrophoric_liquids';
    public const PYROPHORIC_SOLIDS          = 'pyrophoric_solids';
    public const SELF_HEATING               = 'self_heating';
    public const WATER_REACTIVE             = 'water_reactive';
    public const OXIDIZING_LIQUIDS          = 'oxidizing_liquids';
    public const OXIDIZING_SOLIDS           = 'oxidizing_solids';
    public const OXIDIZING_GASES            = 'oxidizing_gases';
    public const GASES_UNDER_PRESSURE       = 'gases_under_pressure';
    public const CORROSIVE_TO_METALS        = 'corrosive_to_metals';

    // ── Health hazards (GHS Chapter 3) ──────────────────────────────────
    public const ACUTE_TOXICITY_ORAL        = 'acute_toxicity_oral';
    public const ACUTE_TOXICITY_DERMAL      = 'acute_toxicity_dermal';
    public const ACUTE_TOXICITY_INHALATION  = 'acute_toxicity_inhalation';
    public const SKIN_CORROSION_IRRITATION  = 'skin_corrosion_irritation';
    public const EYE_DAMAGE_IRRITATION      = 'eye_damage_irritation';
    public const RESPIRATORY_SENSITIZATION  = 'respiratory_sensitization';
    public const SKIN_SENSITIZATION         = 'skin_sensitization';
    public const GERM_CELL_MUTAGENICITY     = 'germ_cell_mutagenicity';
    public const CARCINOGENICITY            = 'carcinogenicity';
    public const REPRODUCTIVE_TOXICITY      = 'reproductive_toxicity';
    public const STOT_SINGLE                = 'stot_single';
    public const STOT_REPEATED              = 'stot_repeated';
    public const ASPIRATION_HAZARD          = 'aspiration_hazard';

    // ── Environmental hazards (GHS Chapter 4) ───────────────────────────
    public const AQUATIC_ACUTE              = 'aquatic_acute';
    public const AQUATIC_CHRONIC            = 'aquatic_chronic';
    public const OZONE_HAZARD               = 'ozone_hazard';

    /**
     * Canonical code → English display name.
     *
     * The display name matches GHSHazardData's existing 'class' field
     * exactly, and is the key in the ghs_*.php translation files under
     * 'hazard_class_names'. Changing a display string requires updating
     * GHSHazardData, the translation files, and any UI that renders it.
     */
    public const DISPLAY_NAMES = [
        self::EXPLOSIVES                 => 'Explosives',
        self::FLAMMABLE_GASES            => 'Flammable Gases',
        self::FLAMMABLE_AEROSOLS         => 'Flammable Aerosols',
        self::FLAMMABLE_LIQUIDS          => 'Flammable Liquids',
        self::FLAMMABLE_SOLIDS           => 'Flammable Solids',
        self::SELF_REACTIVE              => 'Self-Reactive Substances',
        self::PYROPHORIC_LIQUIDS         => 'Pyrophoric Liquids',
        self::PYROPHORIC_SOLIDS          => 'Pyrophoric Solids',
        self::SELF_HEATING               => 'Self-Heating Substances',
        self::WATER_REACTIVE             => 'Substances which, in contact with water, emit flammable gases',
        self::OXIDIZING_LIQUIDS          => 'Oxidizing Liquids',
        self::OXIDIZING_SOLIDS           => 'Oxidizing Solids',
        self::OXIDIZING_GASES            => 'Oxidizing Gases',
        self::GASES_UNDER_PRESSURE       => 'Gases Under Pressure',
        self::CORROSIVE_TO_METALS        => 'Corrosive to Metals',

        self::ACUTE_TOXICITY_ORAL        => 'Acute Toxicity (Oral)',
        self::ACUTE_TOXICITY_DERMAL      => 'Acute Toxicity (Dermal)',
        self::ACUTE_TOXICITY_INHALATION  => 'Acute Toxicity (Inhalation)',
        self::SKIN_CORROSION_IRRITATION  => 'Skin Corrosion/Irritation',
        self::EYE_DAMAGE_IRRITATION      => 'Serious Eye Damage/Eye Irritation',
        self::RESPIRATORY_SENSITIZATION  => 'Respiratory Sensitization',
        self::SKIN_SENSITIZATION         => 'Skin Sensitization',
        self::GERM_CELL_MUTAGENICITY     => 'Germ Cell Mutagenicity',
        self::CARCINOGENICITY            => 'Carcinogenicity',
        self::REPRODUCTIVE_TOXICITY      => 'Reproductive Toxicity',
        self::STOT_SINGLE                => 'STOT — Single Exposure',
        self::STOT_REPEATED              => 'STOT — Repeated Exposure',
        self::ASPIRATION_HAZARD          => 'Aspiration Hazard',

        self::AQUATIC_ACUTE              => 'Hazardous to the Aquatic Environment (Acute)',
        self::AQUATIC_CHRONIC            => 'Hazardous to the Aquatic Environment (Chronic)',
        self::OZONE_HAZARD               => 'Hazardous to the Ozone Layer',
    ];

    /**
     * Canonical code → GHS hazard group: 'physical' | 'health' | 'environmental'.
     * Used for section ordering on SDSs and label layout decisions.
     */
    public const GROUPS = [
        self::EXPLOSIVES                 => 'physical',
        self::FLAMMABLE_GASES            => 'physical',
        self::FLAMMABLE_AEROSOLS         => 'physical',
        self::FLAMMABLE_LIQUIDS          => 'physical',
        self::FLAMMABLE_SOLIDS           => 'physical',
        self::SELF_REACTIVE              => 'physical',
        self::PYROPHORIC_LIQUIDS         => 'physical',
        self::PYROPHORIC_SOLIDS          => 'physical',
        self::SELF_HEATING               => 'physical',
        self::WATER_REACTIVE             => 'physical',
        self::OXIDIZING_LIQUIDS          => 'physical',
        self::OXIDIZING_SOLIDS           => 'physical',
        self::OXIDIZING_GASES            => 'physical',
        self::GASES_UNDER_PRESSURE       => 'physical',
        self::CORROSIVE_TO_METALS        => 'physical',

        self::ACUTE_TOXICITY_ORAL        => 'health',
        self::ACUTE_TOXICITY_DERMAL      => 'health',
        self::ACUTE_TOXICITY_INHALATION  => 'health',
        self::SKIN_CORROSION_IRRITATION  => 'health',
        self::EYE_DAMAGE_IRRITATION      => 'health',
        self::RESPIRATORY_SENSITIZATION  => 'health',
        self::SKIN_SENSITIZATION         => 'health',
        self::GERM_CELL_MUTAGENICITY     => 'health',
        self::CARCINOGENICITY            => 'health',
        self::REPRODUCTIVE_TOXICITY      => 'health',
        self::STOT_SINGLE                => 'health',
        self::STOT_REPEATED              => 'health',
        self::ASPIRATION_HAZARD          => 'health',

        self::AQUATIC_ACUTE              => 'environmental',
        self::AQUATIC_CHRONIC            => 'environmental',
        self::OZONE_HAZARD               => 'environmental',
    ];

    /** All canonical codes in display/section order (physical → health → environmental). */
    public static function all(): array
    {
        return array_keys(self::DISPLAY_NAMES);
    }

    /** English display name for a canonical code, or the code itself if unknown. */
    public static function displayName(string $canonical): string
    {
        return self::DISPLAY_NAMES[$canonical] ?? $canonical;
    }

    /**
     * GHS hazard group for a canonical code: 'physical' | 'health' | 'environmental'.
     * Returns 'health' for unknown codes (conservative default).
     */
    public static function group(string $canonical): string
    {
        return self::GROUPS[$canonical] ?? 'health';
    }

    /** True if the given string is a recognised canonical code. */
    public static function isValid(string $canonical): bool
    {
        return isset(self::DISPLAY_NAMES[$canonical]);
    }
}
