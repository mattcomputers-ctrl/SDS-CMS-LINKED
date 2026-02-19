<?php

declare(strict_types=1);

namespace SDS\Services\FederalData\Connectors;

use SDS\Core\Database;
use SDS\Services\FederalData\FederalDataInterface;

/**
 * EPAConnector — EPA regulatory data (TSCA inventory, CERCLA RQ, RCRA).
 *
 * Provides EPA regulatory status information for chemicals. Currently
 * operates primarily from locally cached data with the interface ready
 * for future API integration when EPA provides a stable REST endpoint.
 */
class EPAConnector implements FederalDataInterface
{
    private const SOURCE_NAME = 'EPA';

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
            "SELECT COUNT(*) AS cnt FROM hazard_source_records WHERE source_name = ?",
            [self::SOURCE_NAME]
        );
        return ((int) ($row['cnt'] ?? 0)) > 0;
    }

    public function lookupCas(string $cas): ?array
    {
        $cas = trim($cas);
        if ($cas === '') {
            return null;
        }

        // Check local cache
        $cached = $this->getCachedData($cas);
        if ($cached !== null) {
            return $cached;
        }

        // EPA does not currently provide a stable public REST API for
        // chemical lookups. Data is loaded via importFromCsv().
        return null;
    }

    public function getLastRefresh(): ?string
    {
        $row = $this->db->fetch(
            "SELECT MAX(retrieved_at) AS last_refresh FROM hazard_source_records WHERE source_name = ?",
            [self::SOURCE_NAME]
        );
        return $row['last_refresh'] ?? null;
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
     * Import EPA regulatory data from a CSV file.
     *
     * Expected columns: cas_number, chemical_name, tsca_listed, cercla_rq_lbs,
     * rcra_waste_code, rcra_characteristic, epcra_tpq_lbs
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
            if ($cas === '' || !preg_match('/^\d+-\d+-\d+$/', $cas)) {
                continue;
            }

            $result = [
                'source'        => self::SOURCE_NAME,
                'cas'           => $cas,
                'chemical_name' => trim($row[1] ?? ''),
                'tsca_listed'   => strtolower(trim($row[2] ?? '')) === 'yes',
                'cercla_rq_lbs' => !empty($row[3]) ? (float) $row[3] : null,
                'rcra_waste_code'    => trim($row[4] ?? '') ?: null,
                'rcra_characteristic' => trim($row[5] ?? '') ?: null,
                'epcra_tpq_lbs'      => !empty($row[6]) ? (float) $row[6] : null,
                'retrieved_at'  => gmdate('Y-m-d\TH:i:s\Z'),
            ];

            try {
                $this->storeResult($cas, $result);
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "CAS {$cas}: " . $e->getMessage();
            }
        }

        fclose($handle);
        return ['imported' => $imported, 'errors' => $errors];
    }

    private function getCachedData(string $cas): ?array
    {
        $row = $this->db->fetch(
            "SELECT payload_json FROM hazard_source_records
             WHERE cas_number = ? AND source_name = ? AND is_current = 1
             ORDER BY retrieved_at DESC LIMIT 1",
            [$cas, self::SOURCE_NAME]
        );

        return $row !== null ? json_decode($row['payload_json'], true) : null;
    }

    private function storeResult(string $cas, array $result): void
    {
        $payloadJson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payloadHash = hash('sha256', $payloadJson);

        $existing = $this->db->fetch(
            "SELECT id FROM hazard_source_records WHERE cas_number = ? AND source_name = ? AND payload_hash = ?",
            [$cas, self::SOURCE_NAME, $payloadHash]
        );

        if ($existing !== null) {
            $this->db->update('hazard_source_records', ['retrieved_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$existing['id']]);
            return;
        }

        $this->db->query(
            "UPDATE hazard_source_records SET is_current = 0 WHERE cas_number = ? AND source_name = ?",
            [$cas, self::SOURCE_NAME]
        );

        $this->db->insert('hazard_source_records', [
            'cas_number'   => $cas,
            'source_name'  => self::SOURCE_NAME,
            'source_ref'   => 'EPA Regulatory Data',
            'retrieved_at' => gmdate('Y-m-d H:i:s'),
            'payload_hash' => $payloadHash,
            'payload_json' => $payloadJson,
            'is_current'   => 1,
        ]);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
