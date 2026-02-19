<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * SARA313Service — SARA Title III Section 313 / TRI reporting analysis.
 *
 * Checks whether a finished product's composition triggers SARA 313
 * reporting requirements based on de minimis concentration thresholds.
 *
 * Key rules:
 *   - Standard threshold: 1.0% by weight
 *   - PBT (persistent bioaccumulative toxic) chemicals: 0.1% threshold
 *   - Category-specific thresholds may apply
 */
class SARA313Service
{
    /**
     * Analyse a composition for SARA 313 reportable chemicals.
     *
     * @param  array $composition  Output from Formula::getExpandedComposition()
     * @return array {
     *   reportable: array[],    // Chemicals above threshold
     *   below_threshold: array[], // Listed chemicals below threshold
     *   not_listed: array[],    // Chemicals not on SARA 313 list
     *   summary: string,
     * }
     */
    public static function analyse(array $composition): array
    {
        $db = Database::getInstance();

        $reportable     = [];
        $belowThreshold = [];
        $notListed      = [];

        foreach ($composition as $component) {
            $cas  = $component['cas_number'];
            $conc = (float) $component['concentration_pct'];
            $name = $component['chemical_name'];

            // Look up in SARA 313 list
            $saraEntry = $db->fetch(
                "SELECT * FROM sara313_list WHERE cas_number = ?",
                [$cas]
            );

            if ($saraEntry === null) {
                $notListed[] = [
                    'cas_number'        => $cas,
                    'chemical_name'     => $name,
                    'concentration_pct' => $conc,
                ];
                continue;
            }

            // Determine applicable threshold
            $threshold = (float) $saraEntry['deminimis_pct'];
            if ((int) $saraEntry['is_pbt'] && $saraEntry['pbt_threshold_pct'] !== null) {
                $threshold = (float) $saraEntry['pbt_threshold_pct'];
            }

            $entry = [
                'cas_number'        => $cas,
                'chemical_name'     => $name,
                'concentration_pct' => $conc,
                'threshold_pct'     => $threshold,
                'is_pbt'            => (bool) $saraEntry['is_pbt'],
                'category_code'     => $saraEntry['category_code'],
                'sara_name'         => $saraEntry['chemical_name'],
            ];

            if ($conc >= $threshold) {
                $entry['status'] = 'reportable';
                $reportable[] = $entry;
            } else {
                $entry['status'] = 'below_threshold';
                $belowThreshold[] = $entry;
            }
        }

        // Build summary
        $summary = count($reportable) === 0
            ? 'No SARA 313 reportable chemicals above de minimis thresholds.'
            : count($reportable) . ' chemical(s) exceed SARA 313 de minimis thresholds and must be reported.';

        return [
            'reportable'      => $reportable,
            'below_threshold' => $belowThreshold,
            'not_listed'      => $notListed,
            'summary'         => $summary,
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
