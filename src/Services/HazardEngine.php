<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * HazardEngine — GHS hazard determination for a finished-product composition.
 *
 * Takes the expanded CAS-level composition and applies GHS mixture
 * classification rules (OSHA HazCom 2012) to determine:
 *   - Overall hazard classes and categories
 *   - Aggregated H-statements, P-statements
 *   - Applicable pictograms
 *   - Signal word (Danger > Warning)
 *   - Exposure limits (PEL, TLV, REL)
 *   - Full decision trace for audit
 *
 * Classification follows the cut-off / concentration limit approach from
 * Annex I of GHS Rev.9 and 29 CFR 1910.1200 Appendix A.
 */
class HazardEngine
{
    /** GHS concentration cut-offs for health hazards (simplified). */
    private const HEALTH_CUTOFFS = [
        'Acute Toxicity'                => ['Cat 1' => 0.1, 'Cat 2' => 0.1, 'Cat 3' => 0.1, 'Cat 4' => 1.0],
        'Skin Corrosion/Irritation'     => ['Cat 1' => 1.0, 'Cat 2' => 10.0],
        'Serious Eye Damage/Irritation' => ['Cat 1' => 1.0, 'Cat 2A' => 10.0],
        'Skin Sensitization'            => ['Cat 1' => 0.1],
        'Respiratory Sensitization'     => ['Cat 1' => 0.1],
        'Germ Cell Mutagenicity'        => ['Cat 1' => 0.1, 'Cat 2' => 1.0],
        'Carcinogenicity'               => ['Cat 1A' => 0.1, 'Cat 1B' => 0.1, 'Cat 2' => 0.1],
        'Reproductive Toxicity'         => ['Cat 1' => 0.1, 'Cat 2' => 0.3],
        'STOT Single Exposure'          => ['Cat 1' => 1.0, 'Cat 2' => 10.0, 'Cat 3' => 20.0],
        'STOT Repeated Exposure'        => ['Cat 1' => 1.0, 'Cat 2' => 10.0],
        'Aspiration Hazard'             => ['Cat 1' => 10.0],
    ];

    /** Signal word hierarchy. */
    private const SIGNAL_HIERARCHY = ['Danger' => 2, 'Warning' => 1];

    /** Pictogram hierarchy (higher overrides lower in same hazard group). */
    private const PICTOGRAM_PRIORITY = [
        'GHS01' => 9, // Exploding bomb
        'GHS05' => 8, // Corrosion
        'GHS06' => 7, // Skull
        'GHS02' => 6, // Flame
        'GHS04' => 5, // Gas cylinder
        'GHS03' => 4, // Flame over circle
        'GHS08' => 3, // Health hazard
        'GHS07' => 2, // Exclamation mark
        'GHS09' => 1, // Environment
    ];

    private array $trace = [];

    /**
     * Run hazard classification for a composition.
     *
     * @param  array $composition  From Formula::getExpandedComposition()
     * @return array {
     *   hazard_classes: array,
     *   h_statements: array,
     *   p_statements: array,
     *   pictograms: string[],
     *   signal_word: string|null,
     *   exposure_limits: array,
     *   hazardous_cas: string[],
     *   ppe_recommendations: array,
     *   trace: array,
     * }
     */
    public function classify(array $composition): array
    {
        $this->trace = [];
        $db = Database::getInstance();

        $allHClasses   = [];
        $allHStmts     = [];
        $allPStmts     = [];
        $allPictograms = [];
        $signalWord    = null;
        $exposureLimits = [];
        $hazardousCas   = [];

        $this->traceStep('start', 'Beginning hazard classification', [
            'component_count' => count($composition),
        ]);

        foreach ($composition as $component) {
            $cas  = $component['cas_number'];
            $conc = (float) $component['concentration_pct'];
            $name = $component['chemical_name'];

            // Skip trace-level components
            if ($conc < 0.01) {
                continue;
            }

            // Look up hazard data from cached federal source records
            $hazardData = $db->fetchAll(
                "SELECT hc.*
                 FROM hazard_classifications hc
                 JOIN hazard_source_records hsr ON hsr.id = hc.hazard_source_record_id
                 WHERE hc.cas_number = ? AND hsr.is_current = 1
                 ORDER BY hsr.retrieved_at DESC",
                [$cas]
            );

            // Exposure limits
            $limits = $db->fetchAll(
                "SELECT el.*
                 FROM exposure_limits el
                 JOIN hazard_source_records hsr ON hsr.id = el.hazard_source_record_id
                 WHERE el.cas_number = ? AND hsr.is_current = 1",
                [$cas]
            );

            foreach ($limits as $limit) {
                $entry = [
                    'cas_number'        => $cas,
                    'chemical_name'     => $name,
                    'concentration_pct' => $conc,
                    'limit_type'        => $limit['limit_type'],
                    'value'             => $limit['value'],
                    'units'             => $limit['units'],
                ];
                if (!empty($limit['notes'])) {
                    $entry['notes'] = $limit['notes'];
                }
                $exposureLimits[] = $entry;
            }

            // Track CAS numbers that have any hazard data or exposure limits
            if (!empty($hazardData) || !empty($limits)) {
                $hazardousCas[$cas] = true;
            }

            if (empty($hazardData)) {
                $this->traceStep('no_data', "No hazard data for CAS {$cas} ({$name})", [
                    'cas' => $cas, 'concentration_pct' => $conc,
                ]);
                continue;
            }

            // Process each hazard classification
            foreach ($hazardData as $hc) {
                $className = $hc['class_name'];
                $category  = $hc['category'];

                // Check against GHS concentration cutoffs
                $cutoff = $this->getCutoff($className, $category);

                if ($conc >= $cutoff) {
                    $allHClasses[] = [
                        'class'    => $className,
                        'category' => $category,
                        'cas'      => $cas,
                        'chemical' => $name,
                        'concentration_pct' => $conc,
                        'cutoff_pct'        => $cutoff,
                    ];

                    // Signal word
                    $sw = $hc['signal_word'] ?? null;
                    if ($sw !== null) {
                        $currentPriority = self::SIGNAL_HIERARCHY[$signalWord] ?? 0;
                        $newPriority     = self::SIGNAL_HIERARCHY[$sw] ?? 0;
                        if ($newPriority > $currentPriority) {
                            $signalWord = $sw;
                        }
                    }

                    // H-statements
                    $hStmts = json_decode($hc['h_statements_json'] ?? '[]', true);
                    if (is_array($hStmts)) {
                        foreach ($hStmts as $stmt) {
                            if (is_string($stmt)) {
                                $allHStmts[$stmt] = ['code' => $stmt, 'text' => ''];
                            } elseif (is_array($stmt) && isset($stmt['code'])) {
                                $allHStmts[$stmt['code']] = $stmt;
                            }
                        }
                    }

                    // P-statements
                    $pStmts = json_decode($hc['p_statements_json'] ?? '[]', true);
                    if (is_array($pStmts)) {
                        foreach ($pStmts as $stmt) {
                            if (is_string($stmt)) {
                                $allPStmts[$stmt] = ['code' => $stmt, 'text' => ''];
                            } elseif (is_array($stmt) && isset($stmt['code'])) {
                                $allPStmts[$stmt['code']] = $stmt;
                            }
                        }
                    }

                    // Pictograms
                    $pictos = json_decode($hc['pictograms_json'] ?? '[]', true);
                    if (is_array($pictos)) {
                        foreach ($pictos as $p) {
                            $allPictograms[$p] = true;
                        }
                    }

                    $this->traceStep('classified', "CAS {$cas} triggers {$className} {$category}", [
                        'cas' => $cas, 'class' => $className, 'category' => $category,
                        'concentration' => $conc, 'cutoff' => $cutoff,
                    ]);
                } else {
                    $this->traceStep('below_cutoff', "CAS {$cas} below cutoff for {$className} {$category}", [
                        'cas' => $cas, 'class' => $className, 'concentration' => $conc,
                        'cutoff' => $cutoff,
                    ]);
                }
            }
        }

        // Apply pictogram precedence rules
        $finalPictograms = $this->applyPictogramPrecedence(array_keys($allPictograms));

        // Sort H and P statements by code
        $hStatements = array_values($allHStmts);
        $pStatements = array_values($allPStmts);
        usort($hStatements, fn($a, $b) => strcmp($a['code'], $b['code']));
        usort($pStatements, fn($a, $b) => strcmp($a['code'], $b['code']));

        // Resolve missing statement text from the GHS standard reference
        $hStatements = GHSStatements::resolveHStatements($hStatements);
        $pStatements = GHSStatements::resolvePStatements($pStatements);

        // Derive PPE recommendations from the classified H/P codes
        $ppeRecommendations = self::derivePPE($hStatements, $pStatements);

        $this->traceStep('complete', 'Hazard classification complete', [
            'hazard_class_count' => count($allHClasses),
            'h_statement_count'  => count($hStatements),
            'pictogram_count'    => count($finalPictograms),
            'signal_word'        => $signalWord,
            'hazardous_cas_count' => count($hazardousCas),
        ]);

        return [
            'hazard_classes'      => $allHClasses,
            'h_statements'        => $hStatements,
            'p_statements'        => $pStatements,
            'pictograms'          => $finalPictograms,
            'signal_word'         => $signalWord,
            'exposure_limits'     => $exposureLimits,
            'hazardous_cas'       => array_keys($hazardousCas),
            'ppe_recommendations' => $ppeRecommendations,
            'trace'               => $this->trace,
        ];
    }

    /**
     * Derive PPE recommendations from classified H-statements and P-statements.
     *
     * Maps hazard codes to specific PPE requirements for respiratory,
     * hand, eye, and skin/body protection.
     *
     * @param  array $hStatements  [['code' => 'H225', 'text' => '...'], ...]
     * @param  array $pStatements  [['code' => 'P210', 'text' => '...'], ...]
     * @return array  PPE recommendations keyed by protection type
     */
    public static function derivePPE(array $hStatements, array $pStatements): array
    {
        // Extract individual H-codes (split combined codes like H300+H310+H330)
        $hCodes = [];
        foreach ($hStatements as $s) {
            $code = $s['code'] ?? '';
            if ($code === '') {
                continue;
            }
            // Add the full combined code
            $hCodes[] = $code;
            // Also split into individual codes
            foreach (explode('+', $code) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $hCodes[] = $part;
                }
            }
        }
        $hCodes = array_unique($hCodes);

        // Extract individual P-codes
        $pCodes = [];
        foreach ($pStatements as $s) {
            $code = $s['code'] ?? '';
            if ($code === '') {
                continue;
            }
            $pCodes[] = $code;
            foreach (explode('+', $code) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $pCodes[] = $part;
                }
            }
        }
        $pCodes = array_unique($pCodes);

        $respiratory = null;
        $hand = null;
        $eye = null;
        $skin = null;

        // ── Respiratory Protection ──
        $fatalInhalation = !empty(array_intersect($hCodes, ['H330']));
        $toxicInhalation = !empty(array_intersect($hCodes, ['H331']));
        $harmfulInhalation = !empty(array_intersect($hCodes, ['H332', 'H333']));
        $respSensitizer = !empty(array_intersect($hCodes, ['H334']));
        $respIrritant = !empty(array_intersect($hCodes, ['H335', 'H336']));
        $respPCode = !empty(array_intersect($pCodes, ['P284', 'P285']));

        if ($fatalInhalation || $toxicInhalation) {
            $respiratory = 'NIOSH-approved supplied-air respirator or self-contained breathing apparatus (SCBA). Do not use chemical cartridge respirators.';
        } elseif ($respSensitizer) {
            $respiratory = 'NIOSH-approved respirator with organic vapor/particulate combination cartridge (P100/OV). Supplied-air respirator if concentrations are high.';
        } elseif ($harmfulInhalation || $respIrritant || $respPCode) {
            $respiratory = 'NIOSH-approved respirator with appropriate cartridge if exposure limits are exceeded or if irritation is experienced.';
        }

        // ── Hand Protection ──
        $fatalDermal = !empty(array_intersect($hCodes, ['H310']));
        $corrosive = !empty(array_intersect($hCodes, ['H314']));
        $skinSensitizer = !empty(array_intersect($hCodes, ['H317']));
        $dermalToxic = !empty(array_intersect($hCodes, ['H311', 'H312', 'H313']));
        $skinIrritant = !empty(array_intersect($hCodes, ['H315', 'H316']));

        if ($fatalDermal || $corrosive) {
            $hand = 'Chemical-resistant gloves (butyl rubber or Viton recommended). Double gloving recommended for corrosive/highly toxic materials. Verify breakthrough time with glove manufacturer.';
        } elseif ($skinSensitizer) {
            $hand = 'Chemical-resistant gloves (nitrile recommended). Replace gloves frequently to prevent sensitization. Verify breakthrough time with glove manufacturer.';
        } elseif ($dermalToxic || $skinIrritant) {
            $hand = 'Chemical-resistant gloves (nitrile or neoprene recommended). Verify breakthrough time with glove manufacturer.';
        }

        // ── Eye Protection ──
        $severeEyeDamage = !empty(array_intersect($hCodes, ['H318']));
        $eyeCorrosive = !empty(array_intersect($hCodes, ['H314']));
        $seriousEyeIrritation = !empty(array_intersect($hCodes, ['H319']));
        $mildEyeIrritation = !empty(array_intersect($hCodes, ['H320']));

        if ($severeEyeDamage || $eyeCorrosive) {
            $eye = 'Chemical splash goggles and face shield required. Tightly fitting safety goggles per ANSI Z87.1.';
        } elseif ($seriousEyeIrritation) {
            $eye = 'Chemical splash goggles or safety glasses with side shields. Face shield if splash hazard exists.';
        } elseif ($mildEyeIrritation) {
            $eye = 'Safety glasses with side shields.';
        }

        // ── Skin / Body Protection ──
        if ($fatalDermal || $corrosive) {
            $skin = 'Full chemical-resistant suit. Impervious boots and chemical-resistant apron. Emergency shower and eyewash station should be accessible.';
        } elseif ($dermalToxic || $skinIrritant || $skinSensitizer) {
            $skin = 'Wear protective clothing to prevent skin contact. Impervious apron recommended. Launder contaminated clothing before reuse.';
        }

        // P280 fallback — if present but no specific PPE was derived from H-codes
        if (in_array('P280', $pCodes, true)) {
            if ($hand === null) {
                $hand = 'Chemical-resistant gloves (nitrile or neoprene recommended).';
            }
            if ($eye === null) {
                $eye = 'Safety glasses with side shields. Use chemical splash goggles if splash hazard exists.';
            }
            if ($skin === null) {
                $skin = 'Wear protective clothing to prevent skin contact.';
            }
        }

        return [
            'respiratory'     => $respiratory,
            'hand_protection' => $hand,
            'eye_protection'  => $eye,
            'skin_protection' => $skin,
        ];
    }

    /**
     * Get the concentration cutoff for a hazard class and category.
     */
    private function getCutoff(string $className, ?string $category): float
    {
        // Try to match against known cutoffs
        foreach (self::HEALTH_CUTOFFS as $class => $categories) {
            if (stripos($className, $class) !== false || stripos($class, $className) !== false) {
                if ($category !== null) {
                    // Normalize category: "Category 2" -> "Cat 2"
                    $normCat = preg_replace('/^Category\s*/i', 'Cat ', $category);
                    foreach ($categories as $catKey => $cutoff) {
                        if (strcasecmp($catKey, $normCat) === 0 || strcasecmp($catKey, $category) === 0) {
                            return $cutoff;
                        }
                    }
                }
                // Return the highest (most conservative) cutoff for the class
                return min($categories);
            }
        }

        // Physical hazards (flammable, oxidizing, etc.) — apply if >= 1%
        if (stripos($className, 'Flammable') !== false) {
            return 1.0;
        }

        // Default: 1% cutoff
        return 1.0;
    }

    /**
     * Apply GHS pictogram precedence rules.
     * GHS06 (skull) takes precedence over GHS07 (exclamation).
     * GHS05 (corrosion) takes precedence over GHS07 for skin/eye.
     */
    private function applyPictogramPrecedence(array $pictograms): array
    {
        // If GHS06 present, remove GHS07
        if (in_array('GHS06', $pictograms)) {
            $pictograms = array_filter($pictograms, fn($p) => $p !== 'GHS07');
        }
        // If GHS05 present, remove GHS07
        if (in_array('GHS05', $pictograms)) {
            $pictograms = array_filter($pictograms, fn($p) => $p !== 'GHS07');
        }
        // If GHS02 or GHS01 present with GHS04, remove GHS04 context overlap
        // (gas cylinder for compressed gases is separate, keep it)

        $result = array_values(array_unique($pictograms));
        usort($result, function ($a, $b) {
            return (self::PICTOGRAM_PRIORITY[$b] ?? 0) <=> (self::PICTOGRAM_PRIORITY[$a] ?? 0);
        });

        return $result;
    }

    private function traceStep(string $type, string $description, array $data): void
    {
        $this->trace[] = [
            'step'        => $type,
            'description' => $description,
            'data'        => $data,
        ];
    }
}
