<?php

declare(strict_types=1);

namespace SDS\Services;

/**
 * TextOverrideService — pure decision logic for per-product SDS text
 * overrides (audit #36). No DB access: SDSController::saveEdits() and
 * scripts/cleanup-default-overrides.php feed it the posted / stored rows
 * and the sections of a generation run with only the product's OTHER overrides
 * applied (SDSGenerator::withOverrides()->generateFromBase(); hintDefaults(), audit #57).
 *
 * Semantics:
 *   - a text_overrides row exists only for operator-typed text;
 *   - blank, or text equal (after whitespace normalisation) to the
 *     automatically generated value, means "automatic" -> no row;
 *   - only EDITABLE_FIELDS keys are ever written.
 */
final class TextOverrideService
{
    /** @var array<int, string[]> section number => field keys the editor may store. */
    public const EDITABLE_FIELDS = [
        1  => ['recommended_use', 'restrictions'],
        2  => ['other_hazards'],
        4  => ['inhalation', 'skin', 'eyes', 'ingestion', 'symptoms', 'notes'],
        5  => ['suitable_media', 'unsuitable_media', 'specific_hazards', 'firefighter_advice'],
        6  => ['personal_precautions', 'environmental', 'containment'],
        7  => ['handling', 'storage'],
        8  => ['engineering', 'respiratory', 'hand_protection', 'eye_protection', 'skin_protection'],
        9  => ['appearance', 'odor', 'boiling_point', 'flash_point', 'solubility',
               // #43 / Q8 Appendix D lines with no formula data (product-level value, no new raw-material field)
               'odor_threshold', 'ph', 'melting_point', 'evaporation_rate', 'flammability_solid_gas',
               'flammability_limits', 'vapor_pressure', 'vapor_density', 'partition_coefficient',
               'auto_ignition_temp', 'decomposition_temp', 'viscosity'],
        10 => ['reactivity', 'stability', 'conditions_avoid', 'incompatible', 'decomposition'],
        11 => ['acute_toxicity', 'chronic_effects', 'carcinogenicity', 'uv_acrylate_note'], // uv_acrylate_note: #65
        12 => ['ecotoxicity', 'persistence', 'bioaccumulation', 'mobility'],
        13 => ['methods'],
        14 => ['un_number', 'proper_shipping_name', 'hazard_class', 'packing_group', 'environmental_hazards'],
        15 => ['state_regs'],   // Q12: osha_status / tsca_status retired (RETIRED_FIELDS)
    ];

    /**
     * Q12 / audit #1: fields the editor used to store but the generator no
     * longer reads. Section 15 OSHA Status follows the Section 2
     * classification and TSCA Status follows the inventory check. Stored rows
     * are ignored (effective()), removed on the next editor save (plan()) and
     * deleted in bulk by scripts/cleanup-legacy-overrides.php.
     */
    public const RETIRED_FIELDS = [15 => ['osha_status', 'tsca_status']];

    /**
     * Audit #57: fields whose stored override changes ANOTHER field's
     * automatic text. In SDSGenerator: 9.flash_point -> Sections 5, 13, 14;
     * 9.boiling_point -> Section 14 once it reads the override (#44);
     * 10.incompatible -> 7.storage; 12.persistence -> 12.bioaccumulation.
     * Keep this list in step whenever a section starts reading another
     * section's override.
     */
    public const CROSS_FED_SOURCES = [
        9  => ['flash_point', 'boiling_point'],
        10 => ['incompatible'],
        12 => ['persistence'],
    ];

    public static function isEditable(int $section, string $key): bool
    {
        return in_array($key, self::EDITABLE_FIELDS[$section] ?? [], true);
    }

    /** Text as stored: trimmed, line endings normalised to "\n". */
    public static function clean(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    /** Comparison form: clean() with every whitespace run collapsed to one space. */
    public static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', self::clean($text)));
    }

    /**
     * The automatically generated value of one field, or null when the
     * generator produces no printable scalar for it (unknown key, array, bool).
     *
     * @param array $sections generate()['sections'] computed with ignoreOverrides()
     */
    public static function defaultFor(array $sections, int $section, string $key): ?string
    {
        $v = $sections[$section][$key] ?? null;
        if ($v === null || !is_scalar($v) || is_bool($v)) {
            return null;
        }
        return (string) $v;
    }

    public static function equalsDefault(string $text, ?string $default): bool
    {
        return $default !== null && self::normalize($text) === self::normalize($default);
    }

    /** Section 14 fields that make up a full transport determination (finding #7). */
    public const SECTION14_CORE_FIELDS = ['un_number', 'proper_shipping_name', 'hazard_class', 'packing_group'];

    /**
     * Finding #7 round trip: true when at least one non-blank Section 14 core
     * field differs from its automatic value, i.e. the operator is replacing
     * the derived classification. Every non-blank core field of that set is
     * then kept even when it equals the derived value (e.g. UN1263 / Paint
     * with the derived class 3 / PG II): section14() needs all four stored to
     * lift the publish block.
     *
     * @param array $fields14 [key => text] for section 14 (posted or stored)
     * @param array $sections generator sections giving the automatic values
     */
    public static function section14CoreGroupActive(array $fields14, array $sections): bool
    {
        foreach (self::SECTION14_CORE_FIELDS as $key) {
            if (!array_key_exists($key, $fields14) || !is_scalar($fields14[$key])) {
                continue;
            }
            $text = self::clean((string) $fields14[$key]);
            if ($text !== '' && !self::equalsDefault($text, self::defaultFor($sections, 14, $key))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Audit #56 / Q12: the override map the generator applies. Trimmed-empty
     * rows (old editor) and keys outside EDITABLE_FIELDS (retired or unknown)
     * are dropped, so a blank row never hides a field and a retired row is
     * never read.
     *
     * @param array $stored [section => [key => text]]
     * @return array<int, array<string, string>> same shape
     */
    public static function effective(array $stored): array
    {
        $out = [];
        foreach ($stored as $sectionNum => $fields) {
            if (!is_array($fields)) {
                continue;
            }
            foreach ($fields as $key => $text) {
                $s = (int) $sectionNum;
                $k = (string) $key;
                if (!self::isEditable($s, $k) || !is_scalar($text) || is_bool($text) || trim((string) $text) === '') {
                    continue;
                }
                $out[$s][$k] = (string) $text;
            }
        }
        return $out;
    }

    /**
     * Audit #57: the automatic value of every field as the editor shows it,
     * i.e. what the sheet prints for that field with the product's OTHER
     * stored overrides applied. Only CROSS_FED_SOURCES overrides change
     * another field, so one generation with the stored cross-feeding
     * overrides applied gives every other field. Each stored cross-feeding
     * field then gets one more generation without its own override.
     *
     * @param array    $stored       [section => [key => text]] as stored (filtered here)
     * @param callable $sectionsWith fn(array $overrides): array, generate()['sections'] with exactly those overrides
     * @return array sections-shaped map (the first generation's sections, each stored
     *               cross-feeding field replaced by its own default); feed it to plan() / defaultFor()
     */
    public static function hintDefaults(array $stored, callable $sectionsWith): array
    {
        $effective = self::effective($stored);
        $feeders   = [];
        foreach (self::CROSS_FED_SOURCES as $s => $keys) {
            foreach ($keys as $k) {
                if (isset($effective[$s][$k])) {
                    $feeders[$s][$k] = $effective[$s][$k];
                }
            }
        }
        $defaults = $sectionsWith($feeders);
        foreach ($feeders as $s => $fields) {
            foreach (array_keys($fields) as $k) {
                $others = $feeders;
                unset($others[$s][$k]);
                if ($others[$s] === []) {
                    unset($others[$s]);
                }
                $defaults[$s][$k] = $sectionsWith($others)[$s][$k] ?? null;
            }
        }
        return $defaults;
    }

    /**
     * Decide what a posted editor form does to text_overrides.
     *
     * @param array $posted   $_POST['override']: [section => [key => text]]
     * @param array $sections generate()['sections'] computed WITHOUT overrides
     * @param array $stored   [section => [key => override_text]] currently stored for this FG + language
     * @return array{
     *   upsert: list<array{section:int, key:string, text:string}>,
     *   delete: list<array{section:int, key:string}>,
     *   counts: array{stored:int, unchanged:int, removed:int, auto:int, ignored:int}
     * }
     */
    public static function plan(array $posted, array $sections, array $stored): array
    {
        $upsert = [];
        $delete = [];
        $counts = ['stored' => 0, 'unchanged' => 0, 'removed' => 0, 'auto' => 0, 'ignored' => 0];
        $s14Group = is_array($posted[14] ?? null) && self::section14CoreGroupActive($posted[14], $sections);

        foreach ($posted as $sectionNum => $fields) {
            $sectionNum = (int) $sectionNum;
            if (!is_array($fields)) {
                continue;
            }
            foreach ($fields as $key => $value) {
                $key = (string) preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key);
                if (!self::isEditable($sectionNum, $key) || !is_scalar($value)) {
                    $counts['ignored']++;
                    continue;
                }
                $text    = self::clean((string) $value);
                $exists  = array_key_exists($key, $stored[$sectionNum] ?? []);
                $default = self::defaultFor($sections, $sectionNum, $key);

                $keepAsCore = $s14Group && $sectionNum === 14 && $text !== ''
                    && in_array($key, self::SECTION14_CORE_FIELDS, true);
                if (!$keepAsCore && ($text === '' || self::equalsDefault($text, $default))) {
                    // "Automatic": no row may remain for this field.
                    if ($exists) {
                        $delete[] = ['section' => $sectionNum, 'key' => $key];
                        $counts['removed']++;
                    } else {
                        $counts['auto']++;
                    }
                    continue;
                }

                if ($exists && self::clean((string) $stored[$sectionNum][$key]) === $text) {
                    $counts['unchanged']++;
                    continue;
                }

                $upsert[] = ['section' => $sectionNum, 'key' => $key, 'text' => $text];
                $counts['stored']++;
            }
        }

        // Q12 / #56: stored rows the generator never reads (retired keys, blank
        // text) are removed on any save. They count as "removed".
        $planned = [];
        foreach (array_merge($delete, $upsert) as $x) {
            $planned[$x['section'] . '.' . $x['key']] = true;
        }
        foreach ($stored as $s => $fields) {
            foreach ((array) $fields as $k => $text) {
                $s = (int) $s;
                $k = (string) $k;
                if (isset($planned[$s . '.' . $k])) {
                    continue;
                }
                if (!self::isEditable($s, $k) || trim((string) $text) === '') {
                    $delete[] = ['section' => $s, 'key' => $k];
                    $counts['removed']++;
                }
            }
        }

        return ['upsert' => $upsert, 'delete' => $delete, 'counts' => $counts];
    }
}
