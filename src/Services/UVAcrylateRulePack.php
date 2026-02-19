<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * UVAcrylateRulePack — Conservative warnings and safe-handling language
 * for UV-curable acrylate/monomer-containing products.
 *
 * This rule pack does NOT invent hazard classifications. Hazards still
 * come from federal CAS data + GHS mixture rules only. This pack:
 *
 *  1. Detects acrylate/monomer CAS numbers via synonym matching
 *  2. Appends conservative safe-handling language to SDS Sections 4–8 and 11
 *  3. Adds tooltip-style warnings for formulators
 *  4. Can be toggled per product family via admin settings
 *
 * Toggle: enabled globally via setting `uv_acrylate_rule_pack` = 'enabled'
 * and per product family (applies to families containing "UV" by default).
 */
class UVAcrylateRulePack
{
    /** Known acrylate/monomer CAS fragments for synonym matching. */
    private const ACRYLATE_CAS_LIST = [
        '57472-68-1'  => 'DPGDA (Dipropylene Glycol Diacrylate)',
        '15625-89-5'  => 'TMPTA (Trimethylolpropane Triacrylate)',
        '4986-89-4'   => 'PETIA / PETA (Pentaerythritol Tetraacrylate)',
        '42978-66-5'  => 'DPGDA (Dipropylene Glycol Diacrylate)',
        '1680-21-3'   => 'TEGDA (Triethylene Glycol Diacrylate)',
        '13048-33-4'  => 'HDDA (1,6-Hexanediol Diacrylate)',
        '28961-43-5'  => 'IBOA (Isobornyl Acrylate)',
        '2223-82-7'   => 'EO-TMPTA',
        '96-33-3'     => 'Methyl Acrylate',
        '141-32-2'    => 'n-Butyl Acrylate',
        '818-61-1'    => 'HEA (2-Hydroxyethyl Acrylate)',
        '999-61-1'    => 'HPA (2-Hydroxypropyl Acrylate)',
        '55818-57-0'  => 'PO-TMPTA',
        '52408-84-1'  => 'DPHA',
    ];

    /** Chemical name substrings that indicate acrylate content. */
    private const ACRYLATE_NAME_PATTERNS = [
        'acrylate',
        'methacrylate',
        'acrylic',
        'diacrylate',
        'triacrylate',
        'tetraacrylate',
        'pentaacrylate',
        'hexaacrylate',
    ];

    /**
     * Check if the rule pack should be applied for a given product family.
     */
    public static function isApplicable(?string $family): bool
    {
        // Check global setting
        $db = Database::getInstance();
        $setting = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'uv_acrylate_rule_pack'");
        if ($setting && $setting['value'] !== 'enabled') {
            return false;
        }

        // Apply to UV product families by default
        if ($family === null) {
            return false;
        }
        return stripos($family, 'UV') !== false || stripos($family, 'LED') !== false;
    }

    /**
     * Detect acrylate/monomer content in a composition.
     *
     * @param  array $composition  Expanded CAS composition.
     * @return array  List of detected acrylate CAS entries with names.
     */
    public static function detectAcrylates(array $composition): array
    {
        $found = [];

        foreach ($composition as $component) {
            $cas  = $component['cas_number'] ?? '';
            $name = strtolower($component['chemical_name'] ?? '');

            // Match by known CAS
            if (isset(self::ACRYLATE_CAS_LIST[$cas])) {
                $found[$cas] = $component['chemical_name'] ?? self::ACRYLATE_CAS_LIST[$cas];
                continue;
            }

            // Match by name pattern
            foreach (self::ACRYLATE_NAME_PATTERNS as $pattern) {
                if (str_contains($name, $pattern)) {
                    $found[$cas] = $component['chemical_name'] ?? $cas;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Return conservative safe-handling language to append to SDS sections.
     *
     * These do NOT override federal hazard data. They supplement Sections
     * 4–8 and 11 with UV-specific handling advice recognized in the
     * coatings industry.
     *
     * @param  array $acrylates  CAS => name pairs from detectAcrylates()
     * @return array  Keyed by section number, each value is text to append.
     */
    public static function getSafeHandlingLanguage(array $acrylates): array
    {
        if (empty($acrylates)) {
            return [];
        }

        $names = implode(', ', array_values($acrylates));

        return [
            4 => "UV/EB curable product containing acrylate monomers/oligomers ({$names}). "
               . "In case of skin contact, wash immediately with soap and water. "
               . "Acrylates may cause sensitization; seek medical attention if skin reaction develops. "
               . "If in eyes, rinse cautiously with water for several minutes; remove contact lenses if present.",

            5 => "UV-curable formulations may generate acrid smoke if involved in a fire. "
               . "Use self-contained breathing apparatus (SCBA) and full protective gear.",

            6 => "Avoid release to drains. Uncured acrylate monomers should not enter waterways. "
               . "Absorb spills with inert material and dispose of in accordance with local regulations.",

            7 => "Store away from UV light sources, direct sunlight, and heat to prevent premature polymerization. "
               . "Keep containers tightly closed. Use in well-ventilated areas. "
               . "Avoid prolonged or repeated skin contact with uncured product.",

            8 => "Wear chemical-resistant gloves (nitrile recommended, minimum 0.4 mm thickness). "
               . "Safety goggles or face shield required. "
               . "Use local exhaust ventilation or respiratory protection if mist/vapor is generated. "
               . "Barrier cream recommended for exposed skin areas.",

            11 => "Contains acrylate monomers that are known skin sensitizers. "
                . "Repeated exposure may cause allergic contact dermatitis. "
                . "Based on component data; no additional toxicological testing on the mixture has been performed.",
        ];
    }

    /**
     * Return formulators' warnings (for UI tooltips and preview display).
     */
    public static function getFormulatorWarnings(array $acrylates): array
    {
        if (empty($acrylates)) {
            return [];
        }

        return [
            'This product contains UV-curable acrylate monomers/oligomers which are known skin sensitizers.',
            'Ensure adequate ventilation and PPE during handling of uncured product.',
            'Acrylate content detected: ' . implode(', ', array_values($acrylates)) . '.',
        ];
    }
}
