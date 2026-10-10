<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * SARA313Service — SARA Title III Section 313 / TRI supplier notification
 * (40 CFR 372.45) analysis for SDS Section 15.
 *
 * A constituent is a listed toxic chemical when its CAS is a sara313_list
 * row, or (finding #26) when it belongs to a TRI category whose members are
 * not individual rows: the metal compound categories by element and N230
 * certain glycol ethers by explicit member list (RegulatoryCategoryService).
 * When both apply, the stricter de minimis and the PBT flag of either win.
 *
 * Thresholds: the list row's / category's de minimis (1.0 %, 0.1 % for OSHA
 * carcinogens). PBT chemicals (40 CFR 372.28, chemicals of special concern)
 * have NO de minimis for supplier notification since the 2023 TRI rule
 * (88 FR 74360): they are reported at any concentration above 0.
 * The same rule added the TRI-listed PFAS (40 CFR 372.29) to the chemicals
 * of special concern: sara313_list.is_special_concern (migration 058, set on
 * the PFAS rows and every PBT row) also removes the de minimis. is_pbt stays
 * the PBT designation only (Section 12 names PBTs; PFAS are not PBTs).
 * sara313_list.pbt_threshold_pct is no longer read.
 */
class SARA313Service
{
    /**
     * @param  array $composition  Output from Formula::getExpandedComposition()
     * Only listed components are returned (audit #42). 'below_threshold' is kept
     * because SDSGenerator::section12() names PBT-flagged listed components from
     * it (audit #24); a PBT component is never below threshold.
     *
     * @return array {
     *   reportable: array[],      // cas_number, chemical_name, concentration_pct,
     *                             // threshold_pct (0.0 for PBT), is_pbt, category_code, sara_name, status
     *   below_threshold: array[], // listed non-PBT chemicals below their de minimis (same shape)
     * }
     */
    public static function analyse(array $composition): array
    {
        $names = [];
        foreach ($composition as $c) {
            $cas = (string) ($c['cas_number'] ?? '');
            if ($cas === '' || $cas === 'TRADE_SECRET' || (float) ($c['concentration_pct'] ?? 0) <= 0) {
                continue;
            }
            $names[$cas] = [(string) ($c['chemical_name'] ?? '')];
        }
        if ($names === []) {
            return self::evaluate($composition, [], []);   // DB-free
        }

        $db   = Database::getInstance();
        $keys = array_map('strval', array_keys($names));
        $ph   = implode(',', array_fill(0, count($keys), '?'));
        $direct = [];
        foreach ($db->fetchAll("SELECT * FROM sara313_list WHERE cas_number IN ({$ph})", $keys) as $r) {
            $direct[(string) $r['cas_number']] = $r;
        }

        return self::evaluate(
            $composition,
            $direct,
            RegulatoryCategoryService::matchForCas(RegulatoryCategoryService::LIST_SARA, $names)
        );
    }

    /**
     * DB-free core of analyse().
     * @param array $direct          cas => sara313_list row
     * @param array $categoryMatches cas => list of regulatory_categories rows (RegulatoryCategoryService::resolve())
     */
    public static function evaluate(array $composition, array $direct, array $categoryMatches): array
    {
        $reportable     = [];
        $belowThreshold = [];

        foreach ($composition as $component) {
            $cas  = (string) ($component['cas_number'] ?? '');
            $conc = (float) ($component['concentration_pct'] ?? 0);
            if ($cas === '' || $cas === 'TRADE_SECRET' || $conc <= 0) {
                continue;
            }
            $row  = $direct[$cas] ?? null;
            $cats = $categoryMatches[$cas] ?? [];
            if ($row === null && $cats === []) {
                continue;
            }

            $isPbt     = $row !== null && (int) ($row['is_pbt'] ?? 0) === 1;
            $isSpecial = $row !== null && (int) ($row['is_special_concern'] ?? 0) === 1;   // 40 CFR 372.28 (TRI PFAS)
            $threshold = $row !== null ? (float) ($row['deminimis_pct'] ?? 1.0) : null;
            $catCode   = ($row !== null && trim((string) ($row['category_code'] ?? '')) !== '') ? (string) $row['category_code'] : null;
            $saraName  = $row !== null ? (string) ($row['chemical_name'] ?? '') : '';
            foreach ($cats as $cat) {
                if ((int) ($cat['is_pbt'] ?? 0) === 1) {
                    $isPbt = true;
                }
                $catThreshold = (float) ($cat['deminimis_pct'] ?? 1.0);
                $threshold    = $threshold === null ? $catThreshold : min($threshold, $catThreshold);
                $catCode      = $catCode ?? (string) ($cat['category_code'] ?? '');
                if ($saraName === '') {
                    $saraName = (string) ($cat['category_name'] ?? '');
                }
            }
            // EPA list names carry footnote markers ("Lead ††"); never print them (same strip as section12()).
            $saraName = trim((string) preg_replace('/[\s\x{2020}\x{2021}*]+$/u', '', $saraName));
            $noDeminimis = $isPbt || $isSpecial;
            if ($noDeminimis) {
                $threshold = 0.0;   // 2023 TRI rule: no de minimis for chemicals of special concern (PBT, PFAS)
            }

            $entry = [
                'cas_number'        => $cas,
                'chemical_name'     => (string) ($component['chemical_name'] ?? ''),
                'concentration_pct' => $conc,
                'threshold_pct'     => (float) $threshold,
                'is_pbt'            => $isPbt,
                // Chemical of special concern that is not a PBT (TRI PFAS):
                // Section 15 prints the special-concern wording instead of "PBT".
                'is_special_concern' => $noDeminimis,
                'category_code'     => $catCode,
                'sara_name'         => $saraName,
            ];

            if ($noDeminimis || $conc >= (float) $threshold) {
                $entry['status'] = 'reportable';
                $reportable[] = $entry;
            } else {
                $entry['status'] = 'below_threshold';
                $belowThreshold[] = $entry;
            }
        }

        return [
            'reportable'      => $reportable,
            'below_threshold' => $belowThreshold,
        ];
    }

    /**
     * Get the full SARA 313 list from the database.
     */
    public static function getList(array $filters = []): array
    {
        $db = Database::getInstance();

        $where  = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]  = '(cas_number LIKE ? OR chemical_name LIKE ?)';
            $term     = '%' . $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
        }
        if (isset($filters['is_pbt'])) {
            $where[]  = 'is_pbt = ?';
            $params[] = (int) $filters['is_pbt'];
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        return $db->fetchAll(
            "SELECT * FROM sara313_list {$whereSQL} ORDER BY chemical_name ASC",
            $params
        );
    }

    /**
     * Refresh the SARA 313 list from a CSV data source.
     *
     * @param  string $csvPath  Path to the EPA TRI chemical list CSV
     * @return array  ['inserted' => int, 'updated' => int, 'errors' => string[]]
     */
    public static function importFromCsv(string $csvPath): array
    {
        $db = Database::getInstance();

        if (!file_exists($csvPath) || !is_readable($csvPath)) {
            return ['inserted' => 0, 'updated' => 0, 'errors' => ['File not found or not readable: ' . $csvPath]];
        }

        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            return ['inserted' => 0, 'updated' => 0, 'errors' => ['Could not open file']];
        }

        $header   = fgetcsv($handle);
        $inserted = 0;
        $updated  = 0;
        $errors   = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 2) {
                continue;
            }

            $cas  = trim($row[0] ?? '');
            $name = trim($row[1] ?? '');

            if ($cas === '' || !preg_match('/^\d+-\d+-\d+$/', $cas)) {
                continue;
            }

            $data = [
                'cas_number'    => $cas,
                'chemical_name' => $name,
                'category_code' => trim($row[2] ?? '') ?: null,
                'deminimis_pct' => isset($row[3]) && $row[3] !== '' ? (float) $row[3] : 1.0,
                'is_pbt'        => isset($row[4]) && strtolower(trim($row[4])) === 'yes' ? 1 : 0,
                'pbt_threshold_pct' => isset($row[5]) && $row[5] !== '' ? (float) $row[5] : null,
                'last_updated_at'   => date('Y-m-d H:i:s'),
            ];
            // Optional 7th column special_concern (migration 058; PBT / TRI PFAS).
            // Absent or blank (e.g. a raw EPA download): the stored flag is kept.
            if (isset($row[6]) && trim((string) $row[6]) !== '') {
                $data['is_special_concern'] = strtolower(trim((string) $row[6])) === 'yes' ? 1 : 0;
            }

            try {
                $existing = $db->fetch("SELECT id FROM sara313_list WHERE cas_number = ?", [$cas]);
                if ($existing) {
                    unset($data['cas_number']);
                    $db->update('sara313_list', $data, 'cas_number = ?', [$cas]);
                    $updated++;
                } else {
                    $db->insert('sara313_list', $data);
                    $inserted++;
                }
            } catch (\Throwable $e) {
                $errors[] = "CAS {$cas}: " . $e->getMessage();
            }
        }

        fclose($handle);

        return ['inserted' => $inserted, 'updated' => $updated, 'errors' => $errors];
    }
}
