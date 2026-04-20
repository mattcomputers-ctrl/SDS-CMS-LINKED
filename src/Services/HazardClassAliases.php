<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\App;

/**
 * HazardClassAliases — Normalise vendor / translated / abbreviated hazard
 * class strings to canonical GHSHazardClass codes.
 *
 * Every class_name string that enters the engine should pass through
 * normalize() first. Sources include:
 *   - hazard_classifications.class_name (vendor / federal data imports)
 *   - competent_person_determinations.determination_json
 *   - raw_materials.manual_hazard_json (trade-secret rows)
 *   - snapshot_json classifications (diff harness)
 *
 * Accepted input forms:
 *   - Canonical English display names ("Carcinogenicity", "Skin Corrosion/Irritation")
 *   - GHS/CLP short forms ("Carc.", "Skin Irrit.", "STOT SE")
 *   - Simplified variants ("Skin Irritation", "Eye Dam.")
 *   - Translated forms in any of the four supported languages (de/es/fr/en)
 *     via reverse-lookup of templates/translations/ghs_*.php
 *
 * Also handles category strings ("Category 1A", "Catégorie 1A", "Cat. 2",
 * "1A") and signal words ("Warning", "Attention", "Advertencia", "Achtung").
 *
 * Unmapped strings return null so callers can log and either fall back to a
 * default cutoff or surface the unknown-input for alias-table maintenance.
 */
final class HazardClassAliases
{
    /**
     * Normalised raw-string → canonical code.
     *
     * Keys are the result of normKey() on every raw variant we accept for
     * each canonical class. Every canonical code in GHSHazardClass::all()
     * must have at least the normalised display name as an entry here so
     * round-trip coverage holds.
     *
     * Maintenance: when vendor data produces a new variant, add it here.
     * The backfill script's unmapped-rows log tells you what to add.
     */
    private const ALIASES = [
        // ── Physical hazards ───────────────────────────────────────────────
        'explosives'                           => GHSHazardClass::EXPLOSIVES,
        'expl.'                                => GHSHazardClass::EXPLOSIVES,
        'explosive'                            => GHSHazardClass::EXPLOSIVES,

        'flammable gases'                      => GHSHazardClass::FLAMMABLE_GASES,
        'flammable gas'                        => GHSHazardClass::FLAMMABLE_GASES,
        'flam. gas'                            => GHSHazardClass::FLAMMABLE_GASES,

        'flammable aerosols'                   => GHSHazardClass::FLAMMABLE_AEROSOLS,
        'flammable aerosol'                    => GHSHazardClass::FLAMMABLE_AEROSOLS,
        'flam. aerosol'                        => GHSHazardClass::FLAMMABLE_AEROSOLS,
        'aerosol'                              => GHSHazardClass::FLAMMABLE_AEROSOLS,

        'flammable liquids'                    => GHSHazardClass::FLAMMABLE_LIQUIDS,
        'flammable liquid'                     => GHSHazardClass::FLAMMABLE_LIQUIDS,
        'flam. liq.'                           => GHSHazardClass::FLAMMABLE_LIQUIDS,
        'flam liq'                             => GHSHazardClass::FLAMMABLE_LIQUIDS,

        'flammable solids'                     => GHSHazardClass::FLAMMABLE_SOLIDS,
        'flammable solid'                      => GHSHazardClass::FLAMMABLE_SOLIDS,
        'flam. sol.'                           => GHSHazardClass::FLAMMABLE_SOLIDS,

        'self-reactive substances'             => GHSHazardClass::SELF_REACTIVE,
        'self-reactive'                        => GHSHazardClass::SELF_REACTIVE,
        'self reactive'                        => GHSHazardClass::SELF_REACTIVE,
        'self-react.'                          => GHSHazardClass::SELF_REACTIVE,

        'pyrophoric liquids'                   => GHSHazardClass::PYROPHORIC_LIQUIDS,
        'pyrophoric liquid'                    => GHSHazardClass::PYROPHORIC_LIQUIDS,
        'pyr. liq.'                            => GHSHazardClass::PYROPHORIC_LIQUIDS,

        'pyrophoric solids'                    => GHSHazardClass::PYROPHORIC_SOLIDS,
        'pyrophoric solid'                     => GHSHazardClass::PYROPHORIC_SOLIDS,
        'pyr. sol.'                            => GHSHazardClass::PYROPHORIC_SOLIDS,

        'self-heating substances'              => GHSHazardClass::SELF_HEATING,
        'self-heating'                         => GHSHazardClass::SELF_HEATING,
        'self heating'                         => GHSHazardClass::SELF_HEATING,
        'self-heat.'                           => GHSHazardClass::SELF_HEATING,

        'substances which in contact with water emit flammable gases' => GHSHazardClass::WATER_REACTIVE,
        'water-react.'                         => GHSHazardClass::WATER_REACTIVE,
        'water reactive'                       => GHSHazardClass::WATER_REACTIVE,
        'water-reactive'                       => GHSHazardClass::WATER_REACTIVE,
        'contact with water'                   => GHSHazardClass::WATER_REACTIVE,

        'oxidizing liquids'                    => GHSHazardClass::OXIDIZING_LIQUIDS,
        'oxidizing liquid'                     => GHSHazardClass::OXIDIZING_LIQUIDS,
        'ox. liq.'                             => GHSHazardClass::OXIDIZING_LIQUIDS,
        'oxidising liquids'                    => GHSHazardClass::OXIDIZING_LIQUIDS,   // British spelling
        'oxidising liquid'                     => GHSHazardClass::OXIDIZING_LIQUIDS,

        'oxidizing solids'                     => GHSHazardClass::OXIDIZING_SOLIDS,
        'oxidizing solid'                      => GHSHazardClass::OXIDIZING_SOLIDS,
        'ox. sol.'                             => GHSHazardClass::OXIDIZING_SOLIDS,
        'oxidising solids'                     => GHSHazardClass::OXIDIZING_SOLIDS,
        'oxidising solid'                      => GHSHazardClass::OXIDIZING_SOLIDS,

        'oxidizing gases'                      => GHSHazardClass::OXIDIZING_GASES,
        'oxidizing gas'                        => GHSHazardClass::OXIDIZING_GASES,
        'ox. gas'                              => GHSHazardClass::OXIDIZING_GASES,
        'oxidising gases'                      => GHSHazardClass::OXIDIZING_GASES,
        'oxidising gas'                        => GHSHazardClass::OXIDIZING_GASES,

        'gases under pressure'                 => GHSHazardClass::GASES_UNDER_PRESSURE,
        'gas under pressure'                   => GHSHazardClass::GASES_UNDER_PRESSURE,
        'press. gas'                           => GHSHazardClass::GASES_UNDER_PRESSURE,
        'compressed gas'                       => GHSHazardClass::GASES_UNDER_PRESSURE,

        'corrosive to metals'                  => GHSHazardClass::CORROSIVE_TO_METALS,
        'met. corr.'                           => GHSHazardClass::CORROSIVE_TO_METALS,
        'corr. metal'                          => GHSHazardClass::CORROSIVE_TO_METALS,

        // ── Health hazards ─────────────────────────────────────────────────
        'acute toxicity (oral)'                => GHSHazardClass::ACUTE_TOXICITY_ORAL,
        'acute tox. oral'                      => GHSHazardClass::ACUTE_TOXICITY_ORAL,
        'acute toxicity oral'                  => GHSHazardClass::ACUTE_TOXICITY_ORAL,
        'acute tox oral'                       => GHSHazardClass::ACUTE_TOXICITY_ORAL,
        'oral toxicity'                        => GHSHazardClass::ACUTE_TOXICITY_ORAL,

        'acute toxicity (dermal)'              => GHSHazardClass::ACUTE_TOXICITY_DERMAL,
        'acute tox. dermal'                    => GHSHazardClass::ACUTE_TOXICITY_DERMAL,
        'acute toxicity dermal'                => GHSHazardClass::ACUTE_TOXICITY_DERMAL,
        'acute tox dermal'                     => GHSHazardClass::ACUTE_TOXICITY_DERMAL,
        'dermal toxicity'                      => GHSHazardClass::ACUTE_TOXICITY_DERMAL,

        'acute toxicity (inhalation)'          => GHSHazardClass::ACUTE_TOXICITY_INHALATION,
        'acute tox. inhalation'                => GHSHazardClass::ACUTE_TOXICITY_INHALATION,
        'acute toxicity inhalation'            => GHSHazardClass::ACUTE_TOXICITY_INHALATION,
        'acute tox inhalation'                 => GHSHazardClass::ACUTE_TOXICITY_INHALATION,
        'inhalation toxicity'                  => GHSHazardClass::ACUTE_TOXICITY_INHALATION,
        // Generic "acute toxicity" without a route is mapped to oral as a
        // conservative default so it still triggers; the engine applies
        // identical cutoffs to all three routes so this never changes a
        // classification outcome under the Phase 1 ruleset.
        'acute toxicity'                       => GHSHazardClass::ACUTE_TOXICITY_ORAL,
        'acute tox.'                           => GHSHazardClass::ACUTE_TOXICITY_ORAL,

        'skin corrosion/irritation'            => GHSHazardClass::SKIN_CORROSION_IRRITATION,
        'skin corrosion / irritation'          => GHSHazardClass::SKIN_CORROSION_IRRITATION,
        'skin corr./irrit.'                    => GHSHazardClass::SKIN_CORROSION_IRRITATION,
        'skin corr. / irrit.'                  => GHSHazardClass::SKIN_CORROSION_IRRITATION,
        'skin corr.'                           => GHSHazardClass::SKIN_CORROSION_IRRITATION,
        'skin corrosion'                       => GHSHazardClass::SKIN_CORROSION_IRRITATION,
        'skin irrit.'                          => GHSHazardClass::SKIN_CORROSION_IRRITATION,
        'skin irritation'                      => GHSHazardClass::SKIN_CORROSION_IRRITATION,
        'skin irrit'                           => GHSHazardClass::SKIN_CORROSION_IRRITATION,

        'serious eye damage/eye irritation'    => GHSHazardClass::EYE_DAMAGE_IRRITATION,
        'serious eye damage / eye irritation'  => GHSHazardClass::EYE_DAMAGE_IRRITATION,
        'serious eye damage/irritation'        => GHSHazardClass::EYE_DAMAGE_IRRITATION,   // legacy HazardEngine form
        'eye dam./irrit.'                      => GHSHazardClass::EYE_DAMAGE_IRRITATION,
        'eye dam. / irrit.'                    => GHSHazardClass::EYE_DAMAGE_IRRITATION,
        'eye dam.'                             => GHSHazardClass::EYE_DAMAGE_IRRITATION,
        'eye damage'                           => GHSHazardClass::EYE_DAMAGE_IRRITATION,
        'serious eye damage'                   => GHSHazardClass::EYE_DAMAGE_IRRITATION,
        'eye irrit.'                           => GHSHazardClass::EYE_DAMAGE_IRRITATION,
        'eye irritation'                       => GHSHazardClass::EYE_DAMAGE_IRRITATION,

        'respiratory sensitization'            => GHSHazardClass::RESPIRATORY_SENSITIZATION,
        'respiratory sensitisation'            => GHSHazardClass::RESPIRATORY_SENSITIZATION,  // British
        'resp. sens.'                          => GHSHazardClass::RESPIRATORY_SENSITIZATION,
        'resp sens'                            => GHSHazardClass::RESPIRATORY_SENSITIZATION,

        'skin sensitization'                   => GHSHazardClass::SKIN_SENSITIZATION,
        'skin sensitisation'                   => GHSHazardClass::SKIN_SENSITIZATION,
        'skin sens.'                           => GHSHazardClass::SKIN_SENSITIZATION,
        'skin sens'                            => GHSHazardClass::SKIN_SENSITIZATION,

        'germ cell mutagenicity'               => GHSHazardClass::GERM_CELL_MUTAGENICITY,
        'mutagenicity'                         => GHSHazardClass::GERM_CELL_MUTAGENICITY,
        'muta.'                                => GHSHazardClass::GERM_CELL_MUTAGENICITY,
        'mutagen'                              => GHSHazardClass::GERM_CELL_MUTAGENICITY,

        'carcinogenicity'                      => GHSHazardClass::CARCINOGENICITY,
        'carc.'                                => GHSHazardClass::CARCINOGENICITY,
        'carcinogen'                           => GHSHazardClass::CARCINOGENICITY,

        'reproductive toxicity'                => GHSHazardClass::REPRODUCTIVE_TOXICITY,
        'reproduction toxicity'                => GHSHazardClass::REPRODUCTIVE_TOXICITY,
        'repr.'                                => GHSHazardClass::REPRODUCTIVE_TOXICITY,
        'reproductive tox.'                    => GHSHazardClass::REPRODUCTIVE_TOXICITY,

        'stot - single exposure'               => GHSHazardClass::STOT_SINGLE,       // em-dash normalised to hyphen
        'stot single exposure'                 => GHSHazardClass::STOT_SINGLE,
        'stot-single exposure'                 => GHSHazardClass::STOT_SINGLE,
        'stot se'                              => GHSHazardClass::STOT_SINGLE,
        'stot-se'                              => GHSHazardClass::STOT_SINGLE,
        'specific target organ toxicity (single)' => GHSHazardClass::STOT_SINGLE,

        'stot - repeated exposure'             => GHSHazardClass::STOT_REPEATED,
        'stot repeated exposure'               => GHSHazardClass::STOT_REPEATED,
        'stot-repeated exposure'               => GHSHazardClass::STOT_REPEATED,
        'stot re'                              => GHSHazardClass::STOT_REPEATED,
        'stot-re'                              => GHSHazardClass::STOT_REPEATED,
        'specific target organ toxicity (repeated)' => GHSHazardClass::STOT_REPEATED,

        'aspiration hazard'                    => GHSHazardClass::ASPIRATION_HAZARD,
        'aspiration tox.'                      => GHSHazardClass::ASPIRATION_HAZARD,
        'asp. tox.'                            => GHSHazardClass::ASPIRATION_HAZARD,
        'aspiration'                           => GHSHazardClass::ASPIRATION_HAZARD,

        // ── Environmental hazards ──────────────────────────────────────────
        'hazardous to the aquatic environment (acute)' => GHSHazardClass::AQUATIC_ACUTE,
        'hazardous to the aquatic environment acute'   => GHSHazardClass::AQUATIC_ACUTE,
        'aquatic acute'                        => GHSHazardClass::AQUATIC_ACUTE,
        'aquatic tox. (acute)'                 => GHSHazardClass::AQUATIC_ACUTE,
        'aquatic acute 1'                      => GHSHazardClass::AQUATIC_ACUTE,   // some vendors put category in name

        'hazardous to the aquatic environment (chronic)' => GHSHazardClass::AQUATIC_CHRONIC,
        'hazardous to the aquatic environment chronic'   => GHSHazardClass::AQUATIC_CHRONIC,
        'aquatic chronic'                      => GHSHazardClass::AQUATIC_CHRONIC,
        'aquatic tox. (chronic)'               => GHSHazardClass::AQUATIC_CHRONIC,

        'hazardous to the ozone layer'         => GHSHazardClass::OZONE_HAZARD,
        'ozone'                                => GHSHazardClass::OZONE_HAZARD,
        'ozone layer'                          => GHSHazardClass::OZONE_HAZARD,
    ];

    /**
     * Normalised translated-value → English canonical display name.
     *
     * Lazy-loaded from templates/translations/ghs_{de,es,fr}.php on first
     * access. Values are the English canonical strings keyed in those files
     * (GHSHazardData-compatible display names), which then resolve via
     * ALIASES to a canonical code.
     *
     * Keys are prefixed to keep the three categories separate (same
     * translated phrase could in principle appear under multiple headings):
     *   ''          → hazard class names
     *   '__cat__'   → category names
     *   '__sig__'   → signal words
     *
     * @var array<string, string>|null
     */
    private static ?array $translationReverse = null;

    /**
     * Load (once) the reverse-lookup map from the existing ghs_*.php
     * translation files. Adding a fifth language is a single file addition —
     * no code changes needed here.
     */
    private static function loadTranslationReverse(): array
    {
        if (self::$translationReverse !== null) {
            return self::$translationReverse;
        }

        $map = [];
        $base = App::basePath() . '/templates/translations';

        foreach (['de', 'es', 'fr'] as $lang) {
            $file = $base . '/ghs_' . $lang . '.php';
            if (!is_file($file)) {
                continue;
            }
            $t = require $file;
            if (!is_array($t)) {
                continue;
            }

            foreach (($t['hazard_class_names'] ?? []) as $english => $translated) {
                if (is_string($english) && is_string($translated) && $translated !== '') {
                    $map[self::normKey($translated)] = $english;
                }
            }
            foreach (($t['category_names'] ?? []) as $english => $translated) {
                if (is_string($english) && is_string($translated) && $translated !== '') {
                    $map['__cat__' . self::normKey($translated)] = $english;
                }
            }
            foreach (($t['signal_words'] ?? []) as $english => $translated) {
                if (is_string($english) && is_string($translated) && $translated !== '') {
                    $map['__sig__' . self::normKey($translated)] = $english;
                }
            }
        }

        return self::$translationReverse = $map;
    }

    /**
     * Normalise a raw string into the lookup key used by ALIASES and the
     * translation reverse-map. Lowercase, collapse whitespace, convert
     * em/en dashes to plain hyphens, strip commas.
     */
    private static function normKey(string $raw): string
    {
        $key = mb_strtolower(trim($raw));
        $key = preg_replace('/[—–]/u', '-', $key);     // em/en dash → hyphen
        $key = preg_replace('/\s+/', ' ', $key ?? ''); // collapse whitespace
        $key = str_replace(',', '', $key);
        return $key;
    }

    /**
     * Normalise a raw class name to its canonical GHSHazardClass code.
     * Accepts English variants and the three supported translations (de/es/fr).
     * Returns null for unmappable input so the caller can decide how to
     * respond (log + default cutoff, or reject with an error).
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $key = self::normKey($trimmed);

        // 1. Direct English-alias match (fast path, covers the majority)
        if (isset(self::ALIASES[$key])) {
            return self::ALIASES[$key];
        }

        // 2. Translation fallback: reverse-map to English canonical, retry
        $rev = self::loadTranslationReverse();
        if (isset($rev[$key])) {
            $englishKey = self::normKey($rev[$key]);
            if (isset(self::ALIASES[$englishKey])) {
                return self::ALIASES[$englishKey];
            }
        }

        // 3. Stripped-trailing-category fallback: some vendor rows concatenate
        // the category into the class name ("Skin Corrosion Cat 1"). Strip
        // the category suffix and retry.
        $stripped = preg_replace(
            '/\s+cat(egory)?\.?\s*[0-9][a-d]?\s*$/i',
            '',
            $key
        );
        if (is_string($stripped) && $stripped !== $key && isset(self::ALIASES[$stripped])) {
            return self::ALIASES[$stripped];
        }

        return null;
    }

    /**
     * Normalise a raw category string to a canonical form.
     *
     * Output grammar (English canonical):
     *   - "Cat 1", "Cat 2", "Cat 2A", etc. for numbered GHS categories.
     *   - "Division 1.1" – "Division 1.6" for explosives.
     *   - "Type A" … "Type G" for self-reactive / organic peroxide classes.
     *   - "Compressed gas", "Liquefied gas", "Refrigerated liquefied gas",
     *     "Dissolved gas" for gases under pressure (preserved as-is).
     *   - "Lactation" passed through.
     *   - Translated forms ("Catégorie 1A" / "Kategorie 1A" / "Categoría 1A")
     *     are reverse-mapped to English before canonicalising.
     *   - Unknown formats are returned unchanged so downstream code can
     *     continue to match them by display string; the goal here is to
     *     normalise the common cases, not to be a strict validator.
     */
    public static function normalizeCategory(?string $raw): string
    {
        if ($raw === null) {
            return '';
        }
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        // Reverse-map translated category to English first (if present).
        $rev = self::loadTranslationReverse();
        $revKey = '__cat__' . self::normKey($raw);
        if (isset($rev[$revKey])) {
            $raw = $rev[$revKey]; // "Catégorie 1A" → "Category 1A"
        }

        $key = $raw;

        // Passthrough for special categories (keep original casing where
        // downstream templates expect it — translations reverse-mapped to
        // the English spelling above).
        if (preg_match('/^(Division|Type|Lactation|Compressed|Liquefied|Refrigerated|Dissolved)/i', $key)) {
            return $key;
        }

        // "Category 1A (1A/1B)" → capture first alphanumeric token → "Cat 1A"
        if (preg_match('/Category\s*(\d+[A-C]?)/i', $key, $m)) {
            return 'Cat ' . strtoupper($m[1]);
        }

        // "Cat. 2" / "Cat 2" / "cat2" → "Cat 2"
        if (preg_match('/Cat\.?\s*(\d+[A-C]?)/i', $key, $m)) {
            return 'Cat ' . strtoupper($m[1]);
        }

        // Bare "1A" / "2" / "3" → "Cat 1A" / "Cat 2" / "Cat 3"
        if (preg_match('/^(\d+[A-C]?)$/i', $key, $m)) {
            return 'Cat ' . strtoupper($m[1]);
        }

        return $key;  // Unrecognised — preserve raw for display-string matching.
    }

    /**
     * Normalise a raw signal word to English canonical ("Warning" / "Danger").
     * Returns null if the input isn't a recognised signal word.
     */
    public static function normalizeSignalWord(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $key = self::normKey(trim($raw));
        if ($key === '') {
            return null;
        }

        // Direct English fast-path
        if ($key === 'warning') return 'Warning';
        if ($key === 'danger')  return 'Danger';

        // Translation fallback
        $rev = self::loadTranslationReverse();
        return $rev['__sig__' . $key] ?? null;
    }

    /**
     * Reset the lazy-loaded translation cache. Used by tests that need to
     * assert on fresh state, and by admin operations that rewrite the
     * underlying ghs_*.php files at runtime.
     */
    public static function resetCache(): void
    {
        self::$translationReverse = null;
    }
}
