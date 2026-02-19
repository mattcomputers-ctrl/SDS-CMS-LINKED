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
     * Standard Prop 65 combined warning (cancer + reproductive).
     */
    public const WARNING_COMBINED = 'WARNING: This product can expose you to chemicals including %s, which is/are known to the State of California to cause cancer, and %s, which is/are known to the State of California to cause birth defects or other reproductive harm. For more information go to www.P65Warnings.ca.gov.';

    /**
     * Analyse a composition against the California Prop 65 list.
     *
     * @param  array $composition  Expanded CAS-level composition
     * @return array {
     *   listed_chemicals: array of matched chemicals with details,
     *   cancer_chemicals: string[] names of cancer-listed chemicals,
     *   repro_chemicals: string[] names of repro-listed chemicals,
     *   requires_warning: bool,
     *   warning_text: string,
     * }
     */
    public static function analyse(array $composition): array
    {
        $db = Database::getInstance();

        $listedChemicals = [];
        $cancerChemicals = [];
        $reproChemicals  = [];

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

            $entry = [
                'cas_number'    => $cas,
                'chemical_name' => $name ?: $row['chemical_name'],
                'concentration_pct' => $conc,
                'toxicity_type' => $types,
                'nsrl_ug'       => $row['nsrl_ug'],
                'madl_ug'       => $row['madl_ug'],
                'date_listed'   => $row['date_listed'],
            ];

            $listedChemicals[] = $entry;

            $displayName = $name ?: $row['chemical_name'];

            if (in_array('cancer', $types)) {
                $cancerChemicals[] = $displayName;
            }
            if (array_intersect(['developmental', 'female reproductive', 'male reproductive'], $types)) {
                $reproChemicals[] = $displayName;
            }
        }

        $cancerChemicals = array_unique($cancerChemicals);
        $reproChemicals  = array_unique($reproChemicals);
        $requiresWarning = !empty($cancerChemicals) || !empty($reproChemicals);

        $warningText = '';
        if ($requiresWarning) {
            $warningText = self::buildWarningText($cancerChemicals, $reproChemicals);
        }

        return [
            'listed_chemicals'  => $listedChemicals,
            'cancer_chemicals'  => array_values($cancerChemicals),
            'repro_chemicals'   => array_values($reproChemicals),
            'requires_warning'  => $requiresWarning,
            'warning_text'      => $warningText,
        ];
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
                implode(', ', $cancerChems),
                implode(', ', $reproChems)
            );
        }

        if ($hasCancer) {
            return sprintf(self::WARNING_CANCER, implode(', ', $cancerChems));
        }

        return sprintf(self::WARNING_REPRO, implode(', ', $reproChems));
    }
}
