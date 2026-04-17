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
     *                      - signal_word:           manual override ('Danger'|'Warning'|'')
     *                      - exposure_limits_json:  JSON array of exposure limit objects
     *                      - basis:                 freeform basis/rationale text
     * @return array {
     *     selected_hazards: JSON string of hazard keys,
     *     hazard_classes:   comma-separated "Class Cat" strings,
     *     signal_word:      'Danger'|'Warning'|'',
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
        $autoSignalWord = null;
        $hazardClasses  = [];
        $signalHierarchy = ['Danger' => 2, 'Warning' => 1];

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
            if ($entry['signal_word'] !== null) {
                $newPri = $signalHierarchy[$entry['signal_word']] ?? 0;
                $curPri = $autoSignalWord ? ($signalHierarchy[$autoSignalWord] ?? 0) : 0;
                if ($newPri > $curPri) {
                    $autoSignalWord = $entry['signal_word'];
                }
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
            $code = trim((string) $code);
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

        // Signal word: manual override takes precedence
        $signalWord = trim((string) ($input['signal_word'] ?? ''));
        if ($signalWord === '') {
            $signalWord = $autoSignalWord ?? '';
        }

        $exposureLimits = json_decode($input['exposure_limits_json'] ?? '[]', true) ?: [];

        return [
            'selected_hazards' => json_encode($selectedHazards),
            'hazard_classes'   => implode(', ', array_unique($hazardClasses)),
            'signal_word'      => $signalWord,
            'h_statements'     => implode(', ', $allHCodes),
            'p_statements'     => implode(', ', $allPCodes),
            'pictograms'       => implode(', ', $pictogramKeys),
            'exposure_limits'  => json_encode($exposureLimits),
            'basis'            => trim((string) ($input['basis'] ?? '')),
        ];
    }
}
