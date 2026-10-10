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
    /*
     * #37: the SDS safe-harbor warning text now comes from
     * templates/translations/{lang}.php — section15.prop65_warning_cancer /
     * _repro / _combined (:cancer / :repro = chemical names) and
     * section15.prop65_trace_name (':name (trace)'). The three WARNING_*
     * constants below are the English reference wording only (EN keys carry
     * the same text verbatim); no sheet text is built from them.
     */

    /**
     * Standard Prop 65 cancer warning (short form, effective 8/30/2018).
     * @deprecated #37 reference only — use section15.prop65_warning_cancer.
     */
    public const WARNING_CANCER = 'WARNING: This product can expose you to chemicals including %s, which is/are known to the State of California to cause cancer. For more information go to www.P65Warnings.ca.gov.';

    /**
     * Standard Prop 65 reproductive toxicity warning.
     * @deprecated #37 reference only — use section15.prop65_warning_repro.
     */
    public const WARNING_REPRO = 'WARNING: This product can expose you to chemicals including %s, which is/are known to the State of California to cause birth defects or other reproductive harm. For more information go to www.P65Warnings.ca.gov.';

    /**
     * Standard Prop 65 combined warning (reproductive + cancer).
     * @deprecated #37 reference only — use section15.prop65_warning_combined.
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

    /** OEHHA reproductive listing types (normalised vocabulary). */
    private const REPRO_TYPES = ['developmental', 'reproductive', 'female reproductive', 'male reproductive'];

    /**
     * Finding #47: listing types compared case-insensitively. Accepts the
     * list's comma string or an array; lower-cases, trims, collapses spaces,
     * strips a trailing " toxicity", maps OEHHA's short 'female' / 'male',
     * drops blanks and duplicates.
     *
     * @param string|array $types
     * @return string[]
     */
    public static function normaliseTypes($types): array
    {
        if (is_string($types)) {
            $types = explode(',', $types);
        }
        $out = [];
        foreach ((array) $types as $t) {
            $t = strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $t)));
            $t = (string) preg_replace('/ toxicity$/', '', $t);
            if ($t === 'female') {
                $t = 'female reproductive';
            } elseif ($t === 'male') {
                $t = 'male reproductive';
            }
            if ($t !== '' && !in_array($t, $out, true)) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * Finding #47: a manual entry with a chemical name but no CAS and no
     * Override tick (migration 015's copy of the legacy single-entry fields,
     * or a row typed before the CAS-driven form) has nothing to look up on the
     * public list, so its typed name and types are used as given.
     */
    public static function isLegacyNameOnly(array $manual): bool
    {
        return empty($manual['is_override'])
            && trim((string) ($manual['cas_number'] ?? '')) === ''
            && trim((string) ($manual['chemical_name'] ?? '')) !== '';
    }

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
     *   listed_chemicals: array of matched chemicals (cas_number, chemical_name,
     *                     concentration_pct, toxicity_type[; is_trace, is_override,
     *                     source on manual entries] — no NSRL/MADL/date, audit #42),
     *   cancer_chemicals: string[] names of cancer-listed chemicals,
     *   repro_chemicals: string[] names of repro-listed chemicals,
     *   requires_warning: bool,
     *   warning_text: string,
     *   trade_secret_conflicts: list of {cas_number, chemical_name, raw_materials[]} (Q4; never printed),
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

        // Audit #13 / owner decision Q4: vendor trade secrets are never Prop 65
        // chemicals, and a Prop 65 chemical must be named. A trade-secret
        // constituent matching the list is kept OUT of every printed key and
        // recorded in trade_secret_conflicts (operator data; any entry blocks
        // publishing via SDSReadinessService::tradeSecretProp65Error()).
        $tradeSecretCas = self::tradeSecretCasSet($composition);
        $tsConflicts    = [];

        // Check CAS-level composition against the Prop 65 database
        foreach ($composition as $component) {
            $cas  = $component['cas_number'] ?? '';
            $name = $component['chemical_name'] ?? '';
            $conc = (float) ($component['concentration_pct'] ?? 0);

            // A trade-secret constituent is checked at any concentration (Q4).
            if ($cas === '' || ($conc < 0.01 && !isset($tradeSecretCas[$cas]))) {
                continue;
            }

            $row = $db->fetch(
                "SELECT * FROM prop65_list WHERE cas_number = ?",
                [$cas]
            );

            if ($row === null) {
                continue;
            }

            if (isset($tradeSecretCas[$cas])) {
                $tsConflicts[$cas] = self::tradeSecretConflictEntry($cas, (string) ($row['chemical_name'] ?? ''), $tradeSecretCas[$cas]);
                continue; // never listed, never in the warning text
            }

            $types = self::normaliseTypes((string) $row['toxicity_type']);   // #47 case-insensitive

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
            ];

            $listedChemicals[] = $entry;

            // Auto-trace: a CAS-matched Prop 65 chemical is considered
            // trace if its effective concentration in the composition
            // is below the admin-configured threshold (default 0.1 %).
            // Trace status is merged across all occurrences in the
            // formula — see updateTraceStatus().
            $autoIsTrace = $conc < $autoTraceLimit;
            self::updateTraceStatus($traceStatus, $displayName, $autoIsTrace);

            if (in_array('cancer', $types, true)) {
                $cancerChemicals[] = $displayName;
            }
            if (array_intersect(self::REPRO_TYPES, $types)) {
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
            // #47: a legacy name-only entry (no CAS, no Override tick) is used as typed.
            $isOverride = !empty($manual['is_override']) || self::isLegacyNameOnly($manual);
            $isTrace    = !empty($manual['is_trace']);
            if ($cas !== '' && isset($tradeSecretCas[$cas])) {
                $tsConflicts[$cas] = $tsConflicts[$cas]
                    ?? self::tradeSecretConflictEntry($cas, (string) ($manual['chemical_name'] ?? ''), $tradeSecretCas[$cas]);
                continue;
            }

            $chemName = '';
            $types    = [];

            if ($isOverride) {
                // Use exactly what the operator stored.
                $chemName = trim((string) ($manual['chemical_name'] ?? ''));
                $types    = self::normaliseTypes($manual['toxicity_type'] ?? []);
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
                $types    = self::normaliseTypes((string) $row['toxicity_type']);
            }

            if ($chemName === '') {
                continue;
            }

            $listedChemicals[] = [
                'cas_number'        => $cas,
                'chemical_name'     => $chemName,
                'concentration_pct' => (float) ($manual['concentration_pct'] ?? 0),
                'toxicity_type'     => $types,
                'is_trace'          => $isTrace,
                'is_override'       => $isOverride,
                'source'            => 'manual',
            ];

            self::updateTraceStatus($traceStatus, $chemName, $isTrace);

            if (in_array('cancer', $types, true)) {
                $cancerChemicals[] = $chemName;
            }
            if (array_intersect(self::REPRO_TYPES, $types)) {
                $reproChemicals[] = $chemName;
            }
        }

        $cancerChemicals = array_values(array_unique($cancerChemicals));
        $reproChemicals  = array_values(array_unique($reproChemicals));
        $requiresWarning = !empty($cancerChemicals) || !empty($reproChemicals);

        // #37: analyse() is language-free (computeBase() reuses it for every
        // sheet language), so names and warning are built in English from the
        // EN translation keys; SDSGenerator::rebuildProp65Warning() re-renders
        // the printed warning (trace marker included) in the sheet language.
        $en = new TranslationService('en');

        // Apply trace suffix: only if ALL occurrences of a chemical are trace
        $cancerChemicals = self::applyTraceSuffix($cancerChemicals, $traceStatus, $en);
        $reproChemicals  = self::applyTraceSuffix($reproChemicals, $traceStatus, $en);

        $warningText = '';
        if ($requiresWarning) {
            $warningText = self::buildWarningText($cancerChemicals, $reproChemicals, $en);
        }

        return [
            'listed_chemicals'  => $listedChemicals,
            'cancer_chemicals'  => $cancerChemicals,
            'repro_chemicals'   => $reproChemicals,
            'requires_warning'  => $requiresWarning,
            'warning_text'      => $warningText,
            'trade_secret_conflicts' => array_values($tsConflicts),  // Q4: operator data only
        ];
    }

    /**
     * Audit #13 / owner decision Q4: trade-secret constituents of a composition
     * that carry a real CAS, keyed by CAS (the TRADE_SECRET placeholder bucket
     * has no CAS and is excluded). Pure; used by analyse() and the tests.
     *
     * @return array<string, array>
     */
    public static function tradeSecretCasSet(array $composition): array
    {
        $out = [];
        foreach ($composition as $component) {
            $cas = trim((string) ($component['cas_number'] ?? ''));
            if ($cas === '' || $cas === 'TRADE_SECRET' || empty($component['is_trade_secret'])) {
                continue;
            }
            $out[$cas] = $component;
        }
        return $out;
    }

    /**
     * One trade_secret_conflicts entry: list name (else the composition name),
     * CAS, and the raw materials that declare it trade secret (all
     * contributing raw materials when the row has no per-material flag).
     * Operator data only; never printed on the sheet.
     */
    public static function tradeSecretConflictEntry(string $cas, string $listName, array $component): array
    {
        $flagged = [];
        $all     = [];
        foreach ($component['contributing_materials'] ?? [] as $m) {
            $code = trim((string) ($m['internal_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $all[$code] = true;
            if (!empty($m['is_trade_secret'])) {
                $flagged[$code] = true;
            }
        }
        return [
            'cas_number'    => $cas,
            'chemical_name' => $listName !== '' ? $listName : (string) ($component['chemical_name'] ?? ''),
            'raw_materials' => array_keys($flagged !== [] ? $flagged : $all),
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
     * Mark chemical names where all occurrences are trace via
     * section15.prop65_trace_name (#37; EN ':name (trace)' — the English form
     * analyse() returns, which SDSGenerator::rebuildProp65Warning() and the
     * label short-form warning read).
     */
    private static function applyTraceSuffix(array $chemNames, array $traceStatus, TranslationService $t): array
    {
        return array_map(function (string $name) use ($traceStatus, $t) {
            if (!empty($traceStatus[$name])) {
                return $t->get('section15.prop65_trace_name', ['name' => $name]);
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
     * Build the appropriate Prop 65 safe-harbor warning text in $t's language
     * (#37: section15.prop65_warning_cancer / _repro / _combined; the
     * combined text lists the reproductive toxicants first, as before).
     * Names are printed as given.
     */
    private static function buildWarningText(array $cancerChems, array $reproChems, TranslationService $t): string
    {
        $hasCancer = !empty($cancerChems);
        $hasRepro  = !empty($reproChems);

        if ($hasCancer && $hasRepro) {
            return $t->get('section15.prop65_warning_combined', [
                'repro'  => implode(', ', $reproChems),
                'cancer' => implode(', ', $cancerChems),
            ]);
        }

        if ($hasCancer) {
            return $t->get('section15.prop65_warning_cancer', ['cancer' => implode(', ', $cancerChems)]);
        }

        return $t->get('section15.prop65_warning_repro', ['repro' => implode(', ', $reproChems)]);
    }
}
