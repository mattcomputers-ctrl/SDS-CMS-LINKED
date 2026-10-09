<?php

declare(strict_types=1);

namespace SDS\Services;

/**
 * AbbreviationService — Section 16 "Abbreviations" line (audit #33).
 *
 * Builds the line from a per-language master table
 * (templates/translations/<lang>.php → section16.abbreviation_table),
 * keeping only the terms that actually print somewhere on the sheet.
 * The corpus mirrors what PDFService / preview.php render, so an
 * abbreviation is defined exactly when a reader can see it.
 *
 * Must be called AFTER all sections and meta.labels are assembled
 * (SDSGenerator::generate() / generateFromBase(), right before return).
 */
final class AbbreviationService
{
    /**
     * Terms whose printed form is not a plain whole-word token.
     * Everything else uses the default pattern built in pattern():
     * whole token, case-sensitive, optional trailing plural "s".
     * Keys must match the table keys in the translation files.
     */
    private const PATTERNS = [
        // Label "UN Number" / "UN-Nummer", or value "UN1210" / "UN 1210".
        'UN'     => '/(?<![\p{L}\p{N}])UN(?:(?![\p{L}\p{N}])|(?=\s?\d{4}(?![\p{N}])))/u',
        // GHS hazard / precautionary statement codes (P100 is the NIOSH
        // filter class, not a P-code — it has its own table entry).
        'Hxxx'   => '/(?<![\p{L}\p{N}])H\d{3}(?![\p{N}])/u',
        'Pxxx'   => '/(?<![\p{L}\p{N}])P(?!100(?![\p{N}]))\d{3}(?![\p{N}])/u',
        'n.o.s.' => '/(?<![\p{L}\p{N}])n\.o\.s\.?(?![\p{L}\p{N}])/iu',
        // Section 11 ATEmix / ETAmezcla / ETAmél (audit #20): the "mix"
        // suffix is glued to the term, so the whole-token default would miss it.
        'ATE'    => '/(?<![\p{L}\p{N}])ATE(?:mix)?(?![\p{L}\p{N}])/u',
        'ETA'    => '/(?<![\p{L}\p{N}])ETA(?:mezcla|mél)?(?![\p{L}\p{N}])/u',
    ];

    /**
     * Build the Section 16 abbreviations line for an assembled SDS array.
     * Returns '' when nothing matches or the table is missing (renderers
     * skip empty strings).
     */
    public static function build(array $sds, TranslationService $t): string
    {
        return self::format(self::filter(self::table($t), self::collectCorpus($sds)));
    }

    /**
     * Master table for the translator's language, falling back to EN.
     *
     * @return array<string,string> term => definition, in file order
     */
    public static function table(TranslationService $t): array
    {
        $table = $t->all()['section16']['abbreviation_table'] ?? null;
        if (!is_array($table) || $table === []) {
            $table = (new TranslationService('en'))->all()['section16']['abbreviation_table'] ?? [];
        }
        return is_array($table) ? $table : [];
    }

    /**
     * Keep the table entries whose term occurs in the corpus.
     *
     * @return array<string,string>
     */
    public static function filter(array $table, string $corpus): array
    {
        $used = [];
        foreach ($table as $term => $definition) {
            $term = (string) $term;
            if ($term === '' || !is_string($definition) || $definition === '') {
                continue;
            }
            if (preg_match(self::pattern($term), $corpus) === 1) {
                $used[$term] = $definition;
            }
        }
        return $used;
    }

    /**
     * "TERM = definition; TERM = definition." (same style as the old line).
     */
    public static function format(array $entries): string
    {
        if ($entries === []) {
            return '';
        }
        $pairs = [];
        foreach ($entries as $term => $definition) {
            $pairs[] = $term . ' = ' . $definition;
        }
        return implode('; ', $pairs) . '.';
    }

    /**
     * Everything PDFService / preview.php print for this sheet, joined by
     * newlines. Mirrors the renderers' skip rules so hidden payloads
     * (hazard_result trace, section 11 hazard_classes / carcinogen_result,
     * the Section 8 UV note, the Prop 65 warning when no warning is required, ...)
     * never trigger a definition.
     */
    public static function collectCorpus(array $sds): string
    {
        $sections = $sds['sections'] ?? [];
        $parts    = [];

        // 1. meta.labels — gated exactly where the renderers gate them.
        $gates = self::labelGates($sections);
        foreach ($sds['meta']['labels'] ?? [] as $key => $text) {
            if (is_string($text) && ($gates[$key] ?? true)) {
                $parts[] = $text;
            }
        }

        // 2. Section payloads.
        foreach ($sections as $num => $section) {
            if ((int) $num === 16 || !is_array($section)) {
                continue; // Section 16 holds the line being built
            }
            switch ((int) $num) {
                case 2: // renderSection2: only these fields print
                    $parts[] = (string) ($section['signal_word'] ?? '');
                    if (empty($section['is_classified'])) {
                        $parts[] = (string) ($section['not_classified_text'] ?? '');
                    }
                    foreach ($section['hazard_classes'] ?? [] as $hc) {
                        if (!is_array($hc)) {
                            continue;
                        }
                        $parts[] = (string) ($hc['class_translated'] ?? $hc['class'] ?? '');
                        $parts[] = (string) ($hc['category_translated'] ?? $hc['category'] ?? '');
                        $parts[] = implode(' ', array_map('strval', (array) ($hc['h_codes'] ?? [])));
                    }
                    foreach (['h_statements', 'p_statements'] as $k) {
                        foreach ($section[$k] ?? [] as $stmt) {
                            if (is_array($stmt)) {
                                $parts[] = (string) ($stmt['code'] ?? '');
                                $parts[] = (string) ($stmt['text'] ?? '');
                            }
                        }
                    }
                    self::walk($section['ppe_recommendations'] ?? [], $parts);
                    // Other hazards print whenever the text is non-empty
                    // (override or translated default), not on has_other_hazards.
                    $parts[] = trim((string) ($section['other_hazards'] ?? ''));
                    break;

                case 11: // only these keys are rendered (hazard_classes /
                         // carcinogen_result are never printed)
                    foreach (['acute_toxicity', 'chronic_effects', 'carcinogenicity', 'uv_acrylate_note'] as $k) { // uv_acrylate_note: audit #35
                        $parts[] = (string) ($section[$k] ?? '');
                    }
                    if (!empty($section['component_toxicology'])) {
                        $parts[] = 'CAS'; // renderer literal "(CAS n-n-n)"
                        self::walk($section['component_toxicology'], $parts);
                    }
                    break;

                case 14: // renderSection14 prints a fixed key list
                    foreach (['un_number', 'proper_shipping_name', 'hazard_class', 'packing_group', 'note', 'ghs_note'] as $k) {
                        $parts[] = (string) ($section[$k] ?? '');
                    }
                    break;

                case 15:
                    $copy   = $section;
                    $prop65 = is_array($copy['prop65'] ?? null) ? $copy['prop65'] : [];
                    $sara   = is_array($copy['sara_313'] ?? null) ? $copy['sara_313'] : [];
                    $snur   = is_array($copy['snur'] ?? null) ? $copy['snur'] : [];
                    unset($copy['prop65'], $copy['sara_313'], $copy['snur'], $copy['state_regs']);
                    // Prop 65 warning text prints only when required.
                    if (!empty($prop65['requires_warning'])) {
                        $parts[] = (string) ($prop65['warning_text'] ?? '');
                        self::walk($prop65['listed_lines'] ?? [], $parts); // audit #42 listing lines
                    }
                    // State regs line (audit #31) prints whenever present, except
                    // the pre-#31 snapshot case where it equals the Prop 65 warning.
                    $stateRegs = trim((string) ($section['state_regs'] ?? ''));
                    if ($stateRegs !== '' && $stateRegs !== trim((string) ($prop65['warning_text'] ?? ''))) {
                        $parts[] = $stateRegs;
                    }
                    // SARA: only reportable entries are listed (with "(CAS n)").
                    if (!empty($sara['reportable'])) {
                        $parts[] = 'CAS';
                        self::walk($sara['reportable'], $parts);
                    }
                    // SNUR: listed chemicals print only when has_snur.
                    if (!empty($snur['has_snur'])) {
                        $parts[] = 'CAS';
                        self::walk($snur['listed_chemicals'] ?? [], $parts);
                    }
                    self::walk($copy, $parts);
                    break;

                default:
                    $copy = $section;
                    if ((int) $num === 8) {
                        unset($copy['uv_acrylate_note']); // Section 8 never prints it: the PPE advice is folded into the fields (audit #35)
                    }
                    self::walk($copy, $parts);
            }
        }

        // 3. Legal disclaimer block (printed after Section 16).
        $parts[] = (string) ($sds['legal_disclaimer'] ?? '');

        return implode("\n", array_filter($parts, static fn ($p) => $p !== ''));
    }

    /**
     * Conditions under which abbreviation-bearing labels print
     * (PDFService::renderSection2/8/11/15 and the matching preview
     * branches). Labels not listed print unconditionally.
     *
     * @return array<string,bool>
     */
    private static function labelGates(array $sections): array
    {
        $s2  = is_array($sections[2]  ?? null) ? $sections[2]  : [];
        $s8  = is_array($sections[8]  ?? null) ? $sections[8]  : [];
        $s11 = is_array($sections[11] ?? null) ? $sections[11] : [];
        $s15 = is_array($sections[15] ?? null) ? $sections[15] : [];
        $s16 = is_array($sections[16] ?? null) ? $sections[16] : [];

        $ppe    = is_array($s2['ppe_recommendations'] ?? null) ? $s2['ppe_recommendations'] : [];
        $hasPpe = !empty($ppe['respiratory']) || !empty($ppe['hand_protection'])
               || !empty($ppe['eye_protection']) || !empty($ppe['skin_protection']);
        $hasEl  = !empty($s8['exposure_limits']);
        $sara   = is_array($s15['sara_313'] ?? null) ? $s15['sara_313'] : [];
        $hap    = is_array($s15['hap'] ?? null) ? $s15['hap'] : [];
        $snur   = is_array($s15['snur'] ?? null) ? $s15['snur'] : [];
        $prop65 = is_array($s15['prop65'] ?? null) ? $s15['prop65'] : [];

        // SARA block (audit #30): heading + statement/none sentence print
        // whenever SARA313Service emitted a 'reportable' array; the
        // statement and per-entry labels only when it is non-empty.
        $saraBlock = isset($sara['reportable']) && is_array($sara['reportable']);
        $saraList  = $saraBlock && $sara['reportable'] !== [];

        // labels.uv_acrylate_note prints wherever a section carries the note (audit #35).
        $hasUvNote = false;
        foreach ([4, 5, 6, 7, 11] as $n) {
            if (is_array($sections[$n] ?? null) && trim((string) ($sections[$n]['uv_acrylate_note'] ?? '')) !== '') {
                $hasUvNote = true;
                break;
            }
        }

        return [
            'ghs_classification'   => !empty($s2['hazard_classes']),
            'physical_hazards'     => !empty($s2['hazard_classes']),
            'health_hazards'       => !empty($s2['hazard_classes']),
            'environmental_hazards' => !empty($s2['hazard_classes']),
            'precautionary_statements' => !empty($s2['p_statements']),
            'ppe_recommendations'  => $hasPpe,
            'ppe_wear_eye'         => $hasPpe,
            'ppe_wear_gloves'      => $hasPpe,
            'ppe_wear_respiratory' => $hasPpe,
            'ppe_wear_skin'        => $hasPpe,
            'other_hazards'        => trim((string) ($s2['other_hazards'] ?? '')) !== '',
            'el_cas'               => $hasEl,
            'el_chemical'          => $hasEl,
            'el_type'              => $hasEl,
            'el_value'             => $hasEl,
            'el_units'             => $hasEl,
            'el_conc_pct'          => $hasEl,
            'el_notes'             => $hasEl,
            'component_tox_data'   => !empty($s11['component_toxicology']),
            'sara_313_title'       => $saraBlock,
            'sara_313_statement'   => $saraList,
            'sara_313_threshold'   => $saraList,
            'sara_313_pbt'         => $saraList,
            'sara_313_range_note'  => $saraList,
            'sara_313_none'        => $saraBlock && !$saraList,
            'hap_title'            => array_key_exists('has_haps', $hap),
            'hap_triggering'       => !empty($hap['has_haps']),
            'hap_wt_pct'           => !empty($hap['has_haps']),
            'hap_total'            => !empty($hap['has_haps']),
            'hap_none'             => array_key_exists('has_haps', $hap) && empty($hap['has_haps']),
            'snur_title'           => !empty($snur['has_snur']),
            'prop65_none'          => empty($prop65['requires_warning']),
            'prop65_listed'        => !empty($prop65['requires_warning']) && !empty($prop65['listed_lines']),
            'state_regulations'    => trim((string) ($s15['state_regs'] ?? '')) !== ''
                                      && trim((string) ($s15['state_regs'] ?? '')) !== trim((string) ($prop65['warning_text'] ?? '')),
            'revision_note'        => !empty($s16['revision_note']),
            'uv_acrylate_note'     => $hasUvNote,
        ];
    }

    /** Whole-token, case-sensitive, optional plural "s" (HAPs, VOCs, PELs). */
    private static function pattern(string $term): string
    {
        return self::PATTERNS[$term]
            ?? '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . 's?(?![\p{L}\p{N}])/u';
    }

    /** Collect every string leaf of a nested array. */
    private static function walk($value, array &$parts): void
    {
        if (is_string($value)) {
            $parts[] = $value;
            return;
        }
        if (is_array($value)) {
            foreach ($value as $v) {
                self::walk($v, $parts);
            }
        }
    }
}
