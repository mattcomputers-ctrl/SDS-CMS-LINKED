<?php

namespace SDS\Services\FederalData;

/**
 * FederalDataInterface
 *
 * Contract that every federal hazard-data connector must implement.
 * Each connector wraps a single authoritative source (PubChem, NIOSH, EPA, DOT)
 * and provides a uniform lookup/refresh API to the orchestrator.
 */
interface FederalDataInterface
{
    /**
     * Return the human-readable source name (e.g. "PubChem", "NIOSH").
     */
    public function getSourceName(): string;

    /**
     * Check whether the data source is reachable / operational.
     */
    public function isAvailable(): bool;

    /**
     * Look up hazard data for a single CAS number.
     *
     * @param  string     $cas  CAS Registry Number (e.g. "67-63-0")
     * @return array|null       Structured result array, or null on miss/error
     */
    public function lookupCas(string $cas): ?array;

    /**
     * Return ISO-8601 timestamp of the most recent successful data retrieval,
     * or null if no data has been fetched yet.
     */
    public function getLastRefresh(): ?string;

    /**
     * Refresh data from this source for every CAS in the list.
     *
     * @param  array         $casList          Indexed array of CAS numbers
     * @param  callable|null $progressCallback fn(string $cas, int $index, int $total): void
     * @return array                           ['success' => [...], 'failed' => [...]]
     */
    public function refreshAll(array $casList, callable $progressCallback = null): array;
}
