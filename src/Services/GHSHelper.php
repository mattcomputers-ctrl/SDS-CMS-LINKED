<?php

declare(strict_types=1);

namespace SDS\Services;

/**
 * GHSHelper — shared utilities for deriving a complete GHS
 * classification (signal word, P statements, pictograms) from a
 * set of selected hazard keys.
 *
 * Used by:
 *   - CAS Determinations (AdminController): admin assigns classifications per CAS
 *   - Raw Material manual hazards (RawMaterialController): vendor-disclosed
 *     GHS classifications without CAS numbers ("trade secret")
 */
class GHSHelper
{
    /**
     * Build a determination JSON structure from form input.
     *
     * @param array $input  Associative array with the same shape as $_POST:
     *                      - selected_hazards_json: JSON array of GHS hazard-class keys
     *                      - h_codes_manual:        array of manually-entered H codes
     *                      - p_codes_manual:        array of manually-entered P codes
     *                      - signal_word:           manual override ('Danger'|'Warning'|'' = Auto)
     *                      - exposure_limits_json:  JSON array of exposure limit objects
     *                      - basis:                 freeform basis/rationale text
     * @return array {
     *     selected_hazards: JSON string of hazard keys,
     *     hazard_classes:   comma-separated "Class Cat" strings,
     *     signal_word:      'Danger'|'Warning'|'' (the override, else the one the selections give),
     *     signal_word_override: 'Danger'|'Warning'|'' (the manual choice; '' = Auto) —
     *                       only when $input has signal_word (the CAS determination
     *                       form); the raw-material form offers no choice and its
     *                       JSON stays as before,
     *     h_statements:     comma-separated H codes,
     *     p_statements:     comma-separated P codes,
     *     pictograms:       comma-separated GHS pictogram codes,
     *     exposure_limits:  JSON string of exposure limits,
     *     basis:            freeform basis text
     * }
     */
    public static function buildDeterminationJson(array $input): array
    {
        // Selected hazard classifications — prefer the JSON-encoded hidden
        // field (populated by the CAS determination form's JS), fall back to
        // a raw hazard_selections[] array (simpler forms, like the RM page).
        $selectedHazards = json_decode($input['selected_hazards_json'] ?? '[]', true) ?: [];
        if (empty($selectedHazards) && !empty($input['hazard_selections']) && is_array($input['hazard_selections'])) {
            $selectedHazards = $input['hazard_selections'];
        }

        // Resolve auto-populated data from GHS hazard data
        $ghsData = GHSHazardData::all();
        $autoHCodes     = [];
        $autoPCodes     = [];
        $autoPictograms = [];
        $hazardClasses  = [];

        foreach ($selectedHazards as $key) {
            if (!isset($ghsData[$key])) {
                continue;
            }
            $entry = $ghsData[$key];
            $hazardClasses[] = $entry['class'] . ' ' . $entry['category'];

            foreach ($entry['h_codes'] as $code) {
                $autoHCodes[$code] = true;
            }
            foreach ($entry['p_codes'] as $code) {
                $autoPCodes[$code] = true;
            }
            foreach ($entry['pictograms'] as $pic) {
                $autoPictograms[$pic] = true;
            }
        }

        // Merge with manually-entered codes
        $manualHCodes = $input['h_codes_manual'] ?? [];
        $manualPCodes = $input['p_codes_manual'] ?? [];

        $allHCodes = array_keys($autoHCodes);
        foreach ($manualHCodes as $code) {
            $code = trim((string) $code);
            if ($code !== '' && !in_array($code, $allHCodes, true)) {
                $allHCodes[] = $code;
            }
        }
        sort($allHCodes);

        $allPCodes = array_keys($autoPCodes);
        foreach ($manualPCodes as $code) {
            $code = GHSStatements::normalisePCode((string) $code); // #39: P281 -> P280
            if ($code !== '' && !in_array($code, $allPCodes, true)) {
                $allPCodes[] = $code;
            }
        }
        sort($allPCodes);

        // Apply pictogram precedence: GHS06 or GHS05 removes GHS07
        $pictogramKeys = array_keys($autoPictograms);
        if (in_array('GHS06', $pictogramKeys, true) || in_array('GHS05', $pictogramKeys, true)) {
            $pictogramKeys = array_filter($pictogramKeys, fn($p) => $p !== 'GHS07');
        }
        sort($pictogramKeys);

        // Signal word: a manual override takes precedence. The choice is kept
        // apart (signal_word_override) so a determination saved on Auto
        // reopens on Auto and keeps following its selections (2026-10-28).
        $signalOverride = self::validSignalWord($input['signal_word'] ?? '');
        $signalWord     = $signalOverride !== '' ? $signalOverride : (self::autoSignalWord($selectedHazards) ?? '');

        $exposureLimits = json_decode($input['exposure_limits_json'] ?? '[]', true) ?: [];

        // Optional ATE (Phase 3c) and M-factor (Phase 4) fields. Empty
        // strings are preserved as null so the engine's fallback logic
        // (category-default ATEs, M=1.0 default) kicks in cleanly.
        $ateFields = [
            'ate_oral_mg_kg',
            'ate_dermal_mg_kg',
            'ate_inhalation_vapor_mg_l_4h',
            'ate_inhalation_dust_mg_l_4h',
            'm_factor_acute',
            'm_factor_chronic',
        ];
        $result = [
            'selected_hazards' => json_encode($selectedHazards),
            'hazard_classes'   => implode(', ', array_unique($hazardClasses)),
            'signal_word'      => $signalWord,
        ] + (array_key_exists('signal_word', $input) ? ['signal_word_override' => $signalOverride] : []) + [
            'h_statements'     => implode(', ', $allHCodes),
            'p_statements'     => implode(', ', $allPCodes),
            'pictograms'       => implode(', ', $pictogramKeys),
            'exposure_limits'  => json_encode($exposureLimits),
            'basis'            => trim((string) ($input['basis'] ?? '')),
        ];
        foreach ($ateFields as $field) {
            $raw = $input[$field] ?? '';
            if (is_string($raw)) $raw = trim($raw);
            $result[$field] = ($raw === '' || $raw === null) ? null : (float) $raw;
        }
        return $result;
    }

    /**
     * The determination JSON to store when a CAS determination is edited
     * (2026-10-28). An older one, saved before signal_word_override, keeps
     * its stored text when the only difference is that key recording the
     * choice it already implied: a save that changes nothing must not
     * change the row, or bulk publish would count its products stale.
     */
    public static function determinationJsonForUpdate(array $new, ?string $stored): string
    {
        $old = json_decode((string) $stored, true);
        if (is_array($old) && !array_key_exists('signal_word_override', $old) && array_key_exists('signal_word_override', $new)) {
            $without = $new;
            unset($without['signal_word_override']);
            if (json_encode($without) === $stored && $new['signal_word_override'] === self::signalWordOverride($old)) {
                return (string) $stored;
            }
        }
        return (string) json_encode($new);
    }

    /** The signal word the selected hazard keys give: the highest of them (Danger over Warning), null when none has one. */
    public static function autoSignalWord(array $selectedHazards): ?string
    {
        $ghsData   = GHSHazardData::all();
        $hierarchy = ['Danger' => 2, 'Warning' => 1];
        $auto      = null;
        foreach ($selectedHazards as $key) {
            $word = is_string($key) ? ($ghsData[$key]['signal_word'] ?? null) : null;
            if ($word !== null && ($hierarchy[$word] ?? 0) > ($auto !== null ? ($hierarchy[$auto] ?? 0) : 0)) {
                $auto = $word;
            }
        }
        return $auto;
    }

    /** 'Danger' or 'Warning' as given, anything else ''. */
    public static function validSignalWord(mixed $word): string
    {
        $word = is_scalar($word) ? trim((string) $word) : '';
        return in_array($word, ['Danger', 'Warning'], true) ? $word : '';
    }

    /**
     * The manual signal-word override of a saved determination, '' for Auto
     * (2026-10-28), for the form's Signal Word Override list. Saved since
     * then as signal_word_override. Older determinations kept only the
     * signal word in effect: it counts as an override only where it differs
     * from the one their selected hazards give, so one saved on Auto
     * reopens on Auto instead of turning into an override.
     */
    public static function signalWordOverride(array $det): string
    {
        if (array_key_exists('signal_word_override', $det)) {
            return self::validSignalWord($det['signal_word_override']);
        }
        $saved    = self::validSignalWord($det['signal_word'] ?? '');
        $selected = $det['selected_hazards'] ?? [];
        if (is_string($selected)) {
            $selected = json_decode($selected, true);
        }
        $auto = self::autoSignalWord(is_array($selected) ? $selected : []) ?? '';
        return $saved !== $auto ? $saved : '';
    }
}
