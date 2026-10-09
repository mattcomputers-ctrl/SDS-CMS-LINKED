<?php

declare(strict_types=1);

namespace SDS\Services;

/**
 * TextOverrideService — pure decision logic for per-product SDS text
 * overrides (audit #36). No DB access: SDSController::saveEdits() and
 * scripts/cleanup-default-overrides.php feed it the posted / stored rows
 * and the sections of a generation run made WITHOUT overrides
 * (SDSGenerator::ignoreOverrides()->generate()).
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
        9  => ['appearance', 'odor', 'boiling_point', 'flash_point', 'solubility'],
        10 => ['reactivity', 'stability', 'conditions_avoid', 'incompatible', 'decomposition'],
        11 => ['acute_toxicity', 'chronic_effects', 'carcinogenicity'],
        12 => ['ecotoxicity', 'persistence', 'bioaccumulation', 'mobility'],
        13 => ['methods'],
        14 => ['un_number', 'proper_shipping_name', 'hazard_class', 'packing_group', 'environmental_hazards'],
        15 => ['osha_status', 'tsca_status', 'state_regs'],
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

                if ($text === '' || self::equalsDefault($text, $default)) {
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

        return ['upsert' => $upsert, 'delete' => $delete, 'counts' => $counts];
    }
}
