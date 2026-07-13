<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * Prop65Service — California Proposition 65 compliance checking.
 *
 * Checks a product composition against the Prop 65 chemical list to
 * determine if warning requirements apply. Generates the appropriate
 * warning text for Section 15 (Regulatory) and Section 2 (Hazards).
 *
 * Prop 65 requires warnings when products contain chemicals "known to
 * the State of California to cause cancer or reproductive toxicity"
 * above designated safe harbor levels (NSRL for carcinogens, MADL for
 * reproductive toxicants).
 */
class Prop65Service
{
    /**
     * Standard Prop 65 cancer warning (short form, effective 8/30/2018).
     */
    public const WARNING_CANCER = 'WARNING: This product can expose you to chemicals including %s, which is/are known to the State of California to cause cancer. For more information go to www.P65Warnings.ca.gov.';

    /**
     * Standard Prop 65 reproductive toxicity warning.
     */
    public const WARNING_REPRO = 'WARNING: This product can expose you to chemicals including %s, which is/are known to the State of California to cause birth defects or other reproductive harm. For more information go to www.P65Warnings.ca.gov.';

    /**
     * Standard Prop 65 combined warning (reproductive + cancer).
     */
    public const WARNING_COMBINED = 'WARNING: This product can expose you to chemicals including %s, which is/are known to the State of California to cause birth defects or other reproductive harm and chemicals including %s, which is/are known to the State of California to cause cancer. For more information go to www.P65Warnings.ca.gov.';

    /**
     * Prop 65 short-form warnings (amended 2023, effective 2025). Used on
     * product labels where space is limited — the full safe-harbor warning
     * above is still used in the SDS. Each names at least one chemical for
     * the relevant endpoint(s), per the amended short-form requirements.
     * The "%s" is the chemical name(s). The leading warning triangle symbol
     * is supplied by the label's Prop 65 pictogram, so it is not embedded
     * in the text.
     */
    public const WARNING_SHORT_CANCER   = 'WARNING: Risk of cancer from exposure to %s. See www.P65Warnings.ca.gov.';
    public const WARNING_SHORT_REPRO    = 'WARNING: Risk of reproductive harm from exposure to %s. See www.P65Warnings.ca.gov.';
    public const WARNING_SHORT_COMBINED = 'WARNING: Risk of cancer and reproductive harm from exposure to %s. See www.P65Warnings.ca.gov.';

    /**
     * Default auto-trace threshold (percent). Any CAS-matched Prop 65
     * chemical whose composition concentration is below this figure is
     * treated as "trace" — the "(trace)" suffix is appended to its name
     * in the warning text. Configurable via admin setting
     * `prop65.auto_trace_threshold_pct`. 0.1 % aligns with the usual
     * OSHA HazCom Section 3 disclosure threshold for CMR chemicals.
     */
    public const DEFAULT_AUTO_TRACE_THRESHOLD_PCT = 0.1;

    /**
     * Return the Prop 65 auto-trace threshold in percent as configured
     * by the admin, falling back to the class default.
     */
    public static function autoTraceThresholdPct(): float
    {
        $db  = Database::getInstance();
        $row = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'prop65.auto_trace_threshold_pct'");
        if ($row === null || $row['value'] === '' || $row['value'] === null) {
            return self::DEFAULT_AUTO_TRACE_THRESHOLD_PCT;
        }
        $v = (float) $row['value'];
        return $v > 0 ? $v : self::DEFAULT_AUTO_TRACE_THRESHOLD_PCT;
    }

    /**
     * Remove manual prop65_data entries whose CAS is already present
     * in the RM's constituents. With the new Auto section on the RM
     * form rendering those from prop65_list directly, keeping a manual
     * copy would duplicate the chemical in the generated SDS.
     *
     * Entries with no CAS (name-only) or a CAS that doesn't appear in
     * constituents are kept untouched. Shared between the on-save flow
     * (RawMaterialController) and the one-off migration script so both
     * apply identical rules.
     *
     * @param  array $constituents  list of rows, each with a 'cas_number' key
     * @param  array $prop65Data    list of manual entries, each with 'cas_number'
     * @return array{pruned: array, removed_count: int}
     */
    public static function pruneManualEntriesAgainstConstituents(array $constituents, array $prop65Data): array
    {
        $casInConstituents = [];
        foreach ($constituents as $c) {
            $cas = trim((string) ($c['cas_number'] ?? ''));
            if ($cas !== '') {
                $casInConstituents[$cas] = true;
            }
        }

        $kept    = [];
        $removed = 0;
        foreach ($prop65Data as $entry) {
            $cas = trim((string) ($entry['cas_number'] ?? ''));
            if ($cas !== '' && isset($casInConstituents[$cas])) {
                $removed++;
                continue;
            }
            $kept[] = $entry;
        }

        return ['pruned' => array_values($kept), 'removed_count' => $removed];
    }

    /**
     * Analyse a composition against the California Prop 65 list.
     *
     * @param  array $composition    Expanded CAS-level composition
     * @param  array $manualEntries  Optional manual Prop 65 entries from raw materials
     * @return array {
     *   listed_chemicals: array of matched chemicals with details,
     *   cancer_chemicals: string[] names of cancer-listed chemicals,
     *   repro_chemicals: string[] names of repro-listed chemicals,
     *   requires_warning: bool,
     *   warning_text: string,
     * }
     */
    public static function analyse(array $composition, array $manualEntries = []): array
    {
        $db              = Database::getInstance();
        $autoTraceLimit  = self::autoTraceThresholdPct();

        $listedChemicals = [];
        $cancerChemicals = [];
        $reproChemicals  = [];

        // Track trace status per chemical name: true = all occurrences are trace,
        // false = at least one non-trace occurrence exists
        $traceStatus = [];

        // Check CAS-level composition against the Prop 65 database
        foreach ($composition as $component) {
            $cas  = $component['cas_number'] ?? '';
            $name = $component['chemical_name'] ?? '';
            $conc = (float) ($component['concentration_pct'] ?? 0);

            if ($cas === '' || $conc < 0.01) {
                continue;
            }

            $row = $db->fetch(
                "SELECT * FROM prop65_list WHERE cas_number = ?",
                [$cas]
            );

            if ($row === null) {
                continue;
            }

            $types = array_map('trim', explode(',', $row['toxicity_type']));

            // The Prop 65 list is the authoritative source for the chemical's
            // display name in warnings — prefer it over the composition /
            // constituent name so edits to the list (e.g. removing stray
            // footnote text) flow through. Fall back to the component name
            // only when the list entry has no name of its own.
            $displayName = ($row['chemical_name'] ?? '') !== '' ? $row['chemical_name'] : $name;

            $entry = [
                'cas_number'    => $cas,
                'chemical_name' => $displayName,
                'concentration_pct' => $conc,
                'toxicity_type' => $types,
                'nsrl_ug'       => $row['nsrl_ug'],
                'madl_ug'       => $row['madl_ug'],
                'date_listed'   => $row['date_listed'],
            ];

            $listedChemicals[] = $entry;

            // Auto-trace: a CAS-matched Prop 65 chemical is considered
            // trace if its effective concentration in the composition
            // is below the admin-configured threshold (default 0.1 %).
            // Trace status is merged across all occurrences in the
            // formula — see updateTraceStatus().
            $autoIsTrace = $conc < $autoTraceLimit;
            self::updateTraceStatus($traceStatus, $displayName, $autoIsTrace);

            if (in_array('cancer', $types)) {
                $cancerChemicals[] = $displayName;
            }
            if (array_intersect(['developmental', 'reproductive', 'female reproductive', 'male reproductive'], $types)) {
                $reproChemicals[] = $displayName;
            }
        }

        // Include manual Prop 65 entries from raw materials.
        //
        // Default behaviour: ignore whatever name / toxicity the operator
        // typed and pull them from prop65_list by CAS — matches the Auto
        // section's rule that the public list is the source of truth. A
        // per-entry `is_override` flag flips that back: operator-typed
        // name + toxicity are used verbatim. Use override for CASes the
        // public list doesn't cover or when you deliberately want a
        // different classification.
        foreach ($manualEntries as $manual) {
            $cas        = trim((string) ($manual['cas_number'] ?? ''));
            $isOverride = !empty($manual['is_override']);
            $isTrace    = !empty($manual['is_trace']);

            $chemName = '';
            $types    = [];

            if ($isOverride) {
                // Use exactly what the operator stored.
                $chemName = trim((string) ($manual['chemical_name'] ?? ''));
                $types    = $manual['toxicity_type'] ?? [];
                if (is_string($types)) {
                    $types = array_map('trim', explode(',', $types));
                }
                $types = array_values(array_filter($types));
            } else {
                // Derive from the public list. If the CAS isn't on the
                // list, skip — the operator's typed data isn't trusted
                // without the override box checked.
                if ($cas === '') {
                    continue;
                }
                $row = $db->fetch(
                    "SELECT chemical_name, toxicity_type FROM prop65_list WHERE cas_number = ?",
                    [$cas]
                );
                if ($row === null) {
                    continue;
                }
                $chemName = (string) $row['chemical_name'];
                $types    = array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) $row['toxicity_type'])
                )));
            }

            if ($chemName === '') {
                continue;
            }

            $listedChemicals[] = [
                'cas_number'        => $cas,
                'chemical_name'     => $chemName,
                'concentration_pct' => (float) ($manual['concentration_pct'] ?? 0),
                'toxicity_type'     => $types,
                'nsrl_ug'           => null,
                'madl_ug'           => null,
                'date_listed'       => null,
                'is_trace'          => $isTrace,
                'is_override'       => $isOverride,
                'source'            => 'manual',
            ];

            self::updateTraceStatus($traceStatus, $chemName, $isTrace);

            if (in_array('cancer', $types, true)) {
                $cancerChemicals[] = $chemName;
            }
            if (array_intersect(['developmental', 'reproductive', 'female reproductive', 'male reproductive'], $types)) {
                $reproChemicals[] = $chemName;
            }
        }

        $cancerChemicals = array_values(array_unique($cancerChemicals));
        $reproChemicals  = array_values(array_unique($reproChemicals));
        $requiresWarning = !empty($cancerChemicals) || !empty($reproChemicals);

        // Apply trace suffix: only if ALL occurrences of a chemical are trace
        $cancerChemicals = self::applyTraceSuffix($cancerChemicals, $traceStatus);
        $reproChemicals  = self::applyTraceSuffix($reproChemicals, $traceStatus);

        $warningText = '';
        if ($requiresWarning) {
            $warningText = self::buildWarningText($cancerChemicals, $reproChemicals);
        }

        return [
            'listed_chemicals'  => $listedChemicals,
            'cancer_chemicals'  => $cancerChemicals,
            'repro_chemicals'   => $reproChemicals,
            'requires_warning'  => $requiresWarning,
            'warning_text'      => $warningText,
        ];
    }

    /**
     * Update the trace status tracker for a chemical name.
     *
     * A chemical is only considered trace if ALL of its occurrences
     * (across all raw materials in the formula) are marked as trace.
     */
    private static function updateTraceStatus(array &$traceStatus, string $chemName, bool $isTrace): void
    {
        if (!isset($traceStatus[$chemName])) {
            $traceStatus[$chemName] = $isTrace;
        } elseif (!$isTrace) {
            // Any non-trace occurrence removes the trace designation
            $traceStatus[$chemName] = false;
        }
    }

    /**
     * Append " (trace)" to chemical names where all occurrences are trace.
     */
    private static function applyTraceSuffix(array $chemNames, array $traceStatus): array
    {
        return array_map(function (string $name) use ($traceStatus) {
            if (!empty($traceStatus[$name])) {
                return $name . ' (trace)';
            }
            return $name;
        }, $chemNames);
    }

    /**
     * Build the Prop 65 short-form warning for product labels.
     *
     * Names at least one chemical for the applicable endpoint(s). Returns
     * an empty string when neither endpoint applies. Combined warnings list
     * the union of cancer- and reproductive-toxicant names.
     */
    public static function shortFormWarning(array $cancerChems, array $reproChems): string
    {
        $hasCancer = !empty($cancerChems);
        $hasRepro  = !empty($reproChems);

        if (!$hasCancer && !$hasRepro) {
            return '';
        }

        if ($hasCancer && $hasRepro) {
            $chems = array_values(array_unique(array_merge($cancerChems, $reproChems)));
            return sprintf(self::WARNING_SHORT_COMBINED, implode(', ', $chems));
        }

        if ($hasCancer) {
            return sprintf(self::WARNING_SHORT_CANCER, implode(', ', $cancerChems));
        }

        return sprintf(self::WARNING_SHORT_REPRO, implode(', ', $reproChems));
    }

    /**
     * Build the appropriate Prop 65 warning text.
     */
    private static function buildWarningText(array $cancerChems, array $reproChems): string
    {
        $hasCancer = !empty($cancerChems);
        $hasRepro  = !empty($reproChems);

        if ($hasCancer && $hasRepro) {
            return sprintf(
                self::WARNING_COMBINED,
                implode(', ', $reproChems),
                implode(', ', $cancerChems)
            );
        }

        if ($hasCancer) {
            return sprintf(self::WARNING_CANCER, implode(', ', $cancerChems));
        }

        return sprintf(self::WARNING_REPRO, implode(', ', $reproChems));
    }
}
