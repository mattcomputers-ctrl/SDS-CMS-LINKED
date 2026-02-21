<?php

declare(strict_types=1);

namespace SDS\Services\FederalData\Connectors;

use SDS\Core\Database;
use SDS\Services\FederalData\FederalDataInterface;

/**
 * NIOSHConnector — NIOSH Pocket Guide to Chemical Hazards.
 *
 * Provides occupational exposure limits (REL, IDLH, STEL) from the
 * NIOSH Pocket Guide (NPG). Data is cached locally in hazard_source_records
 * and exposure_limits tables.
 *
 * Primary source: CDC/NIOSH NPG dataset
 * Fallback: locally cached JSON data file
 */
class NIOSHConnector implements FederalDataInterface
{
    private const SOURCE_NAME = 'NIOSH';
    private const HTTP_TIMEOUT = 30;
    private const MAX_RETRIES = 2;

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
        // Check if we have any cached NIOSH data
        $row = $this->db->fetch(
            "SELECT COUNT(*) AS cnt FROM hazard_source_records WHERE source_name = ?",
            [self::SOURCE_NAME]
        );
        return ((int) ($row['cnt'] ?? 0)) > 0;
    }

    /**
     * Look up NIOSH exposure limit data for a CAS number.
     *
     * Returns structured data including REL-TWA, IDLH, STEL values.
     */
    public function lookupCas(string $cas): ?array
    {
        $cas = trim($cas);
        if ($cas === '') {
            return null;
        }

        try {
            // Check local cache first
            $cached = $this->getCachedData($cas);
            if ($cached !== null) {
                return $cached;
            }

            // Attempt to fetch from NIOSH NPG web resource
            $data = $this->fetchFromNIOSH($cas);
            if ($data !== null) {
                $this->storeResult($cas, $data);
                return $data;
            }

            return null;
        } catch (\Throwable $e) {
            $this->errors[] = ['cas' => $cas, 'error' => $e->getMessage()];
            return null;
        }
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
     * Import NIOSH data from a local JSON file.
     *
     * Expected format: array of objects with cas_number, chemical_name,
     * rel_twa, rel_stel, rel_ceiling, idlh, synonyms, etc.
     */
    public function importFromJson(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return ['imported' => 0, 'errors' => ['File not found: ' . $filePath]];
        }

        $json = file_get_contents($filePath);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return ['imported' => 0, 'errors' => ['Invalid JSON format']];
        }

        $imported = 0;
        $errors   = [];

        foreach ($data as $entry) {
            $cas = trim($entry['cas_number'] ?? '');
            if ($cas === '') {
                continue;
            }

            try {
                $result = [
                    'source'        => self::SOURCE_NAME,
                    'cas'           => $cas,
                    'chemical_name' => $entry['chemical_name'] ?? '',
                    'exposure_limits' => [],
                    'retrieved_at'  => gmdate('Y-m-d\TH:i:s\Z'),
                ];

                // Parse exposure limits from the entry.
                // The JSON uses suffixed field names: rel_twa_mgm3, rel_twa_ppm, etc.
                // Prefer mg/m3 values; fall back to ppm if mg/m3 is not available.
                $limitFields = [
                    'rel_twa'     => 'REL-TWA',
                    'rel_stel'    => 'REL-STEL',
                    'rel_ceiling' => 'REL-Ceiling',
                    'idlh'        => 'IDLH',
                    'pel_twa'     => 'PEL-TWA',
                ];

                foreach ($limitFields as $field => $type) {
                    $mgm3Key = $field . '_mgm3';
                    $ppmKey  = $field . '_ppm';

                    if (!empty($entry[$mgm3Key])) {
                        $result['exposure_limits'][] = [
                            'type'  => $type,
                            'value' => (string) $entry[$mgm3Key],
                            'units' => 'mg/m3',
                        ];
                    } elseif (!empty($entry[$ppmKey])) {
                        $result['exposure_limits'][] = [
                            'type'  => $type,
                            'value' => (string) $entry[$ppmKey],
                            'units' => 'ppm',
                        ];
                    } elseif (!empty($entry[$field])) {
                        // Legacy format: plain field name
                        $result['exposure_limits'][] = [
                            'type'  => $type,
                            'value' => (string) $entry[$field],
                            'units' => $entry[$field . '_units'] ?? 'mg/m3',
                        ];
                    }
                }

                $this->storeResult($cas, $result);
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "CAS {$cas}: " . $e->getMessage();
            }
        }

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

        if ($row === null) {
            return null;
        }

        return json_decode($row['payload_json'], true);
    }

    private function fetchFromNIOSH(string $cas): ?array
    {
        // NIOSH NPG does not have a clean REST API.
        // In production, this would scrape or use a pre-downloaded dataset.
        // Return null to indicate data should be loaded via importFromJson.
        return null;
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

        // Mark old records as non-current
        $this->db->query(
            "UPDATE hazard_source_records SET is_current = 0 WHERE cas_number = ? AND source_name = ?",
            [$cas, self::SOURCE_NAME]
        );

        $this->db->beginTransaction();
        try {
            $recordId = $this->db->insert('hazard_source_records', [
                'cas_number'   => $cas,
                'source_name'  => self::SOURCE_NAME,
                'source_ref'   => 'NIOSH Pocket Guide to Chemical Hazards',
                'retrieved_at' => gmdate('Y-m-d H:i:s'),
                'payload_hash' => $payloadHash,
                'payload_json' => $payloadJson,
                'is_current'   => 1,
            ]);

            // Store exposure limits
            foreach ($result['exposure_limits'] ?? [] as $limit) {
                $this->db->insert('exposure_limits', [
                    'hazard_source_record_id' => $recordId,
                    'cas_number'  => $cas,
                    'limit_type'  => $limit['type'],
                    'value'       => $limit['value'],
                    'units'       => $limit['units'] ?? 'mg/m3',
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
