<?php

declare(strict_types=1);

namespace SDS\Services\FederalData\Connectors;

use SDS\Core\Database;
use SDS\Services\FederalData\FederalDataInterface;

/**
 * DOTConnector — DOT (49 CFR) transport classification data.
 *
 * Provides UN numbers, proper shipping names, hazard classes, and
 * packing groups from the DOT Hazardous Materials Table (49 CFR 172.101).
 *
 * Data is loaded from a local dataset and cached in dot_transport_info.
 */
class DOTConnector implements FederalDataInterface
{
    private const SOURCE_NAME = 'DOT';

    private Database $db;
    private array $errors = [];

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function getSourceName(): string
    {
        return self::SOURCE_NAME;
    }

    public function isAvailable(): bool
    {
        $row = $this->db->fetch(
            "SELECT COUNT(*) AS cnt FROM dot_transport_info",
            []
        );
        return ((int) ($row['cnt'] ?? 0)) > 0;
    }

    /**
     * Look up DOT transport classification for a CAS number.
     */
    public function lookupCas(string $cas): ?array
    {
        $cas = trim($cas);
        if ($cas === '') {
            return null;
        }

        // Check dot_transport_info table
        $row = $this->db->fetch(
            "SELECT * FROM dot_transport_info WHERE cas_number = ? ORDER BY retrieved_at DESC LIMIT 1",
            [$cas]
        );

        if ($row !== null) {
            return [
                'source'              => self::SOURCE_NAME,
                'cas'                 => $cas,
                'un_number'           => $row['un_number'],
                'proper_shipping_name' => $row['proper_shipping_name'],
                'hazard_class'        => $row['hazard_class'],
                'packing_group'       => $row['packing_group'],
                'source_ref'          => $row['source_ref'],
            ];
        }

        return null;
    }

    public function getLastRefresh(): ?string
    {
        $row = $this->db->fetch("SELECT MAX(retrieved_at) AS lr FROM dot_transport_info");
        return $row['lr'] ?? null;
    }

    public function refreshAll(array $casList, callable $progressCallback = null): array
    {
        $success = [];
        $failed  = [];
        $total   = count($casList);

        foreach (array_values($casList) as $index => $cas) {
            if ($progressCallback !== null) {
                $progressCallback($cas, $index, $total);
            }

            $result = $this->lookupCas($cas);
            if ($result !== null) {
                $success[] = $cas;
            } else {
                $failed[] = $cas;
            }
        }

        return ['success' => $success, 'failed' => $failed];
    }

    /**
     * Import DOT transport data from a CSV file.
     *
     * Expected columns: cas_number, un_number, proper_shipping_name,
     * hazard_class, packing_group
     */
    public function importFromCsv(string $filePath): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return ['imported' => 0, 'errors' => ['File not found: ' . $filePath]];
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return ['imported' => 0, 'errors' => ['Could not open file']];
        }

        fgetcsv($handle); // skip header
        $imported = 0;
        $errors   = [];

        while (($row = fgetcsv($handle)) !== false) {
            $cas = trim($row[0] ?? '');
            if ($cas === '') {
                continue;
            }

            try {
                $data = [
                    'un_number'            => trim($row[1] ?? '') ?: null,
                    'proper_shipping_name' => trim($row[2] ?? '') ?: null,
                    'hazard_class'         => trim($row[3] ?? '') ?: null,
                    'packing_group'        => trim($row[4] ?? '') ?: null,
                    'source_ref'           => '49 CFR 172.101',
                    'retrieved_at'         => gmdate('Y-m-d H:i:s'),
                ];

                // Non-destructive upsert: update if exists, insert if new
                $existing = $this->db->fetch(
                    "SELECT id FROM dot_transport_info WHERE cas_number = ?",
                    [$cas]
                );

                if ($existing) {
                    $this->db->update('dot_transport_info', $data, 'cas_number = ?', [$cas]);
                } else {
                    $data['cas_number'] = $cas;
                    $this->db->insert('dot_transport_info', $data);
                }

                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "CAS {$cas}: " . $e->getMessage();
            }
        }

        fclose($handle);
        return ['imported' => $imported, 'errors' => $errors];
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
