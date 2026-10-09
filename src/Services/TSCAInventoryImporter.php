<?php
/**
 * TSCAInventoryImporter — the one implementation behind both
 * scripts/import-tsca-inventory.php (CLI) and the "Import EPA TSCA
 * inventory" card on /tsca (AdminController@importTsca / applyTscaImport).
 *
 * Loads the EPA non-confidential TSCA Inventory CSV into `tsca_inventory`
 * (audit #29). Section 15 of every SDS resolves each constituent CAS
 * against this table; a CAS on the table resolves as "listed", anything
 * else prints the "not verified" sentence and raises a publish warning.
 *
 * Three steps, so a dry-run / preview and the real import share every
 * line of logic:
 *
 *   parse($csvPath)                       DB-free. Finds the header row,
 *                                         maps the columns, normalises CAS,
 *                                         returns rows keyed by CAS.
 *   plan($parsed, $existing, $inUse, …)   DB-free. Classifies each CAS as
 *                                         insert / update / unchanged /
 *                                         manual-skip, estimates the prune
 *                                         set and lists the in-use CAS whose
 *                                         resolution would change.
 *   apply($parsed, $opts)                 Writes. Re-plans against the live
 *                                         table, upserts in one transaction,
 *                                         prunes, bumps the RMs carrying a
 *                                         changed CAS (raw_materials.updated_at
 *                                         = UTC, the bulk-publish signal) and
 *                                         queues SDS-update rows.
 *
 * fetchExisting() / fetchInUseCas() load the two maps plan() needs; the
 * CLI and the controller call them, tests pass literals.
 *
 * Column assumptions (detected by regex on the header row; the header row
 * is the first row, within the first 50, containing a CAS-like cell):
 *   - CAS column:   /^cas\s*(rn|#|no\.?|number)?$/i            (required)
 *   - name column:  /index\s*name|chemical\s*name|substance\s*name|^name$/i (required)
 *   - ACTIVITY:     /^activity$/i   (optional; ACTIVE / INACTIVE)
 *   - FLAG(S):      /^flags?$/i     (optional; S, XU, T, P, Y1, Y2 ...)
 *
 * Handling notes:
 *   - EPA sometimes zero-pads the first CAS segment (0000050-00-0);
 *     TSCAService::normaliseCas() strips that on import (and on lookup).
 *   - Rows whose CAS is not a valid CAS number (confidential-inventory
 *     accession numbers, blanks) are skipped and counted.
 *   - Duplicate CAS rows: the first wins; the rest are counted.
 *   - Rows tagged source_ref='manual' (saved through /tsca) are never touched.
 *   - ACTIVITY blank or ACTIVE → is_active_inventory = 1; INACTIVE → 0.
 *   - Non-UTF-8 cells are treated as Windows-1252 and converted.
 *
 * Staleness: every CAS whose resolution changed (inserted ∪ pruned) that
 * is used by a raw material constituent is bumped through
 * RegulatoryListBumper::bumpByCasMany() and queued on SDS Updates. Name /
 * activity / flag refreshes do not change the resolution and are not bumped.
 *
 * Idempotent: re-running with the same CSV and version label makes no row
 * changes (unchanged rows are only re-stamped with source_version /
 * imported_at so prune can tell current rows apart).
 */

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

final class TSCAInventoryImporter
{
    public const CAS_HEADER_RE      = '/^cas\s*(rn|#|no\.?|number)?$/i';
    // EPA's current download uses "ChemName" (no space); older/other exports use
    // "CA Index Name", "Chemical Name" or "Substance Name".
    public const NAME_HEADER_RE     = '/index[\s_]*name|chem(ical)?[\s_]*name|substance[\s_]*name|^name$/i';
    public const ACTIVITY_HEADER_RE = '/^activity$/i';
    public const FLAG_HEADER_RE     = '/^flags?$/i';
    public const UVCB_HEADER_RE     = '/^uvcb$/i';

    /** Rows scanned for the header before giving up. */
    public const HEADER_SCAN_LIMIT = 50;

    /** Max length of the version label (tsca_inventory.source_version). */
    public const VERSION_MAX = 100;

    private const CHUNK = 500;

    /** Null is allowed for the DB-free steps (parse / plan / tests). */
    public function __construct(private ?Database $db = null)
    {
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    public static function toUtf8(string $s): string
    {
        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        return (string) mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }

    /** Default version label for a file: basename without extension, capped. */
    public static function defaultVersion(string $path): string
    {
        return self::normaliseVersion(pathinfo($path, PATHINFO_FILENAME));
    }

    public static function normaliseVersion(?string $label, ?string $fallbackPath = null): string
    {
        $label = trim((string) $label);
        if ($label === '' && $fallbackPath !== null) {
            $label = pathinfo($fallbackPath, PATHINFO_FILENAME);
        }
        return mb_substr($label, 0, self::VERSION_MAX);
    }

    private function db(): Database
    {
        return $this->db ??= Database::getInstance();
    }

    /* ------------------------------------------------------------------
     *  1. parse — DB-free
     * ----------------------------------------------------------------*/

    /**
     * Parse the EPA CSV.
     *
     * @return array{
     *   ok: bool,
     *   headerError: ?string,
     *   headerFound: string[],
     *   columns: array<string,?string>,   cas / name / activity / flag / uvcb → header label (null when absent)
     *   rows: int,                        data rows after the header (blank lines excluded)
     *   uniqueCas: int,
     *   skippedNoCas: int,
     *   dupes: int,
     *   warnings: string[],
     *   parsed: array<string, array{name:string, active:int, flags:?string}>  keyed by normalised CAS
     * }
     */
    public function parse(string $csvPath): array
    {
        $result = [
            'ok'           => false,
            'headerError'  => null,
            'headerFound'  => [],
            'columns'      => ['cas' => null, 'name' => null, 'activity' => null, 'flag' => null, 'uvcb' => null],
            'rows'         => 0,
            'uniqueCas'    => 0,
            'skippedNoCas' => 0,
            'dupes'        => 0,
            'warnings'     => [],
            'parsed'       => [],
        ];

        if (!is_file($csvPath) || !is_readable($csvPath)) {
            $result['headerError'] = 'Cannot open CSV: ' . $csvPath;
            return $result;
        }
        $fh = fopen($csvPath, 'r');
        if ($fh === false) {
            $result['headerError'] = 'Cannot open CSV: ' . $csvPath;
            return $result;
        }

        // Header row = first row with a CAS-like header cell (EPA files may
        // carry preamble lines). Strip a UTF-8 BOM from the first cell.
        $header     = null;
        $headerScan = 0;
        $firstCells = null;
        while (($row = fgetcsv($fh)) !== false) {
            $headerScan++;
            if ($headerScan > self::HEADER_SCAN_LIMIT) {
                break;
            }
            if ($row === [null] || $row === []) {
                continue;
            }
            $cells = array_map(static fn($c): string => trim(self::toUtf8((string) $c)), $row);
            if (isset($cells[0])) {
                $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]) ?? $cells[0];
            }
            $firstCells ??= $cells;
            foreach ($cells as $c) {
                if (preg_match(self::CAS_HEADER_RE, $c)) {
                    $header = $cells;
                    break 2;
                }
            }
        }
        if ($header === null) {
            fclose($fh);
            $result['headerFound'] = $firstCells ?? [];
            $result['headerError'] = 'Could not find header row (expected a CASRN / CAS No / CAS Number column in the first '
                . self::HEADER_SCAN_LIMIT . ' rows)';
            return $result;
        }
        $result['headerFound'] = $header;

        $colIdx = [];
        foreach ($header as $i => $h) {
            if (!isset($colIdx['cas'])      && preg_match(self::CAS_HEADER_RE, $h))      { $colIdx['cas'] = $i; continue; }
            if (!isset($colIdx['name'])     && preg_match(self::NAME_HEADER_RE, $h))     { $colIdx['name'] = $i; continue; }
            if (!isset($colIdx['activity']) && preg_match(self::ACTIVITY_HEADER_RE, $h)) { $colIdx['activity'] = $i; continue; }
            if (!isset($colIdx['flag'])     && preg_match(self::FLAG_HEADER_RE, $h))     { $colIdx['flag'] = $i; continue; }
            if (!isset($colIdx['uvcb'])     && preg_match(self::UVCB_HEADER_RE, $h))     { $colIdx['uvcb'] = $i; continue; }
        }
        foreach (['cas', 'name'] as $req) {
            if (!isset($colIdx[$req])) {
                fclose($fh);
                $result['headerError'] = "Header missing required column: {$req}";
                return $result;
            }
        }
        foreach ($colIdx as $key => $i) {
            $result['columns'][$key] = $header[$i];
        }
        if (!isset($colIdx['activity'])) {
            $result['warnings'][] = 'No ACTIVITY column found; every row will be imported as ACTIVE.';
        }
        if (!isset($colIdx['flag'])) {
            $result['warnings'][] = 'No FLAG column found; flags will be left blank.';
        }

        $parsed       = [];
        $rowCount     = 0;
        $skippedNoCas = 0;
        $dupes        = 0;

        while (($row = fgetcsv($fh)) !== false) {
            if ($row === [null] || $row === [] || (count($row) === 1 && trim((string) $row[0]) === '')) {
                continue;
            }
            $rowCount++;

            $cas = TSCAService::normaliseCas((string) ($row[$colIdx['cas']] ?? ''));
            if ($cas === '' || !preg_match(TSCAService::CAS_PATTERN, $cas)) {
                $skippedNoCas++;
                continue;
            }
            if (isset($parsed[$cas])) {
                $dupes++;
                continue;
            }

            $name   = mb_substr(self::toUtf8(trim((string) ($row[$colIdx['name']] ?? ''))), 0, 500);
            $active = 1;
            if (isset($colIdx['activity'])) {
                $active = strtoupper(trim((string) ($row[$colIdx['activity']] ?? ''))) === 'INACTIVE' ? 0 : 1;
            }
            $flags = null;
            if (isset($colIdx['flag'])) {
                $f     = mb_substr(self::toUtf8(trim((string) ($row[$colIdx['flag']] ?? ''))), 0, 50);
                $flags = $f !== '' ? $f : null;
            }

            $parsed[$cas] = ['name' => $name, 'active' => $active, 'flags' => $flags];
        }
        fclose($fh);

        $result['rows']         = $rowCount;
        $result['uniqueCas']    = count($parsed);
        $result['skippedNoCas'] = $skippedNoCas;
        $result['dupes']        = $dupes;
        $result['parsed']       = $parsed;
        $result['ok']           = $parsed !== [];
        if ($parsed === []) {
            $result['warnings'][] = 'No valid CAS rows parsed — nothing to do.';
        }
        return $result;
    }

    /* ------------------------------------------------------------------
     *  Maps plan() needs (DB)
     * ----------------------------------------------------------------*/

    /**
     * When $version is given, each row also carries is_stale (0/1): whether
     * its source_version differs from $version *as the DELETE in apply()
     * decides it*, i.e. under the column's case-insensitive collation. plan()
     * prefers that flag over a byte-exact PHP comparison so the prune
     * estimate matches what the real prune removes.
     *
     * @return array<string, array{cas_number:string, chemical_name:string, is_active_inventory:int, flags:?string, source_ref:?string, source_version:?string, is_stale?:int}> keyed by CAS
     */
    public function fetchExisting(?string $version = null): array
    {
        $existing = [];
        $rows = $version !== null
            ? $this->db()->fetchAll(
                "SELECT cas_number, chemical_name, is_active_inventory, flags, source_ref, source_version,
                        (source_version IS NULL OR source_version <> ?) AS is_stale
                 FROM tsca_inventory",
                [$version]
            )
            : $this->db()->fetchAll(
                "SELECT cas_number, chemical_name, is_active_inventory, flags, source_ref, source_version FROM tsca_inventory"
            );
        foreach ($rows as $r) {
            $existing[(string) $r['cas_number']] = $r;
        }
        return $existing;
    }

    /** @return array<string,string> normalised CAS → stored spelling (for the bumper's IN list) */
    public function fetchInUseCas(): array
    {
        $inUse = [];
        $rows = $this->db()->fetchAll("SELECT DISTINCT cas_number FROM raw_material_constituents WHERE cas_number <> ''");
        foreach ($rows as $r) {
            $inUse[TSCAService::normaliseCas((string) $r['cas_number'])] = (string) $r['cas_number'];
        }
        return $inUse;
    }

    /* ------------------------------------------------------------------
     *  2. plan — DB-free
     * ----------------------------------------------------------------*/

    /**
     * Classify parsed rows against the existing table.
     *
     * @param array<string, array{name:string, active:int, flags:?string}> $parsed    from parse()['parsed']
     * @param array<string, array>                                         $existing  from fetchExisting() (cas → row)
     * @param array<string, string>                                        $inUse     from fetchInUseCas() (normalised → stored)
     * @param string                                                       $version   label this run stamps
     * @param bool                                                         $prune     include the prune estimate in the change set
     *
     * @return array{
     *   inserted:int, updated:int, unchanged:int, skippedManual:int, pruned:int,
     *   insertedCas:string[], prunedCas:string[],
     *   toUpsert: array<int, array{0:string,1:string,2:int,3:?string}>, toRestamp:string[],
     *   changedCas:string[], affected:string[], affectedCount:int, existingCount:int, prune:bool, version:string
     * }
     *
     * prunedCas is always estimated (EPA rows whose source_version differs
     * from $version and that are not in the file) so a preview can show the
     * number either way; it only joins changedCas / affected when $prune.
     */
    public function plan(array $parsed, array $existing, array $inUse, string $version, bool $prune): array
    {
        $toUpsert    = [];
        $toRestamp   = [];
        $insertedCas = [];
        $inserted = $updated = $unchanged = $skippedManual = 0;

        foreach ($parsed as $cas => $d) {
            $cas = (string) $cas;
            $ex  = $existing[$cas] ?? null;
            if ($ex === null) {
                $inserted++;
                $insertedCas[] = $cas;
                $toUpsert[]    = [$cas, $d['name'], (int) $d['active'], $d['flags']];
                continue;
            }
            if (($ex['source_ref'] ?? null) === 'manual') {
                $skippedManual++;
                continue;
            }
            $changed = (string) $ex['chemical_name'] !== (string) $d['name']
                || (int) $ex['is_active_inventory'] !== (int) $d['active']
                || (string) ($ex['flags'] ?? '') !== (string) ($d['flags'] ?? '');
            if ($changed) {
                $updated++;
                $toUpsert[] = [$cas, $d['name'], (int) $d['active'], $d['flags']];
            } else {
                $unchanged++;
                $toRestamp[] = $cas;
            }
        }

        // Prune estimate: EPA rows not in this file whose stamp differs from
        // this run's label (rows in the file get re-stamped first on a real run).
        // fetchExisting($version) supplies is_stale from SQL so the comparison
        // uses the column's collation, exactly like the DELETE in apply();
        // hand-built rows (tests) fall back to a PHP comparison.
        $prunedCas = [];
        foreach ($existing as $cas => $ex) {
            $cas = (string) $cas;
            if (($ex['source_ref'] ?? null) !== 'EPA') {
                continue;
            }
            $sv    = $ex['source_version'] ?? null;
            $stale = array_key_exists('is_stale', $ex)
                ? (bool) (int) $ex['is_stale']
                : ($sv === null || (string) $sv !== $version);
            if ($stale && !isset($parsed[$cas])) {
                $prunedCas[] = $cas;
            }
        }

        $changedCas = $prune
            ? array_values(array_unique(array_merge($insertedCas, $prunedCas)))
            : $insertedCas;
        $affected = self::affectedInUse($changedCas, $inUse);

        return [
            'inserted'      => $inserted,
            'updated'       => $updated,
            'unchanged'     => $unchanged,
            'skippedManual' => $skippedManual,
            'pruned'        => $prune ? count($prunedCas) : 0,
            'pruneEstimate' => count($prunedCas),
            'insertedCas'   => $insertedCas,
            'prunedCas'     => $prunedCas,
            'toUpsert'      => $toUpsert,
            'toRestamp'     => $toRestamp,
            'changedCas'    => $changedCas,
            'affected'      => $affected,
            'affectedCount' => count($affected),
            'existingCount' => count($existing),
            'prune'         => $prune,
            'version'       => $version,
        ];
    }

    /** @return string[] stored spellings of the in-use CAS among $changedCas */
    private static function affectedInUse(array $changedCas, array $inUse): array
    {
        $affected = [];
        foreach ($changedCas as $cas) {
            if (isset($inUse[$cas])) {
                $affected[] = $inUse[$cas];
            }
        }
        return array_values(array_unique($affected));
    }

    /* ------------------------------------------------------------------
     *  3. apply — writes
     * ----------------------------------------------------------------*/

    /**
     * Apply the import. Re-plans against the live table so a stale preview
     * can never write the wrong rows.
     *
     * @param array<string, array{name:string, active:int, flags:?string}> $parsed from parse()['parsed']
     * @param array{version?:?string, prune?:bool, queue?:bool, userId?:?int} $opts
     *   version  label stamped on every EPA row this run touches (required, non-empty)
     *   prune    delete EPA rows whose source_version differs afterwards (default false)
     *   queue    bump RMs + queue SDS updates for in-use CAS whose resolution changed (default true)
     *   userId   recorded on the queued SDS-update rows (null = system)
     *
     * @return array plan() counts (actual) + importedAt, rmsBumped, sdsQueued
     * @throws \RuntimeException on a failed write (transaction rolled back) or bad input
     */
    public function apply(array $parsed, array $opts = []): array
    {
        if ($parsed === []) {
            throw new \RuntimeException('No valid CAS rows parsed — nothing to do.');
        }
        $version = self::normaliseVersion($opts['version'] ?? null);
        if ($version === '') {
            throw new \RuntimeException('A version label is required.');
        }
        $prune  = (bool) ($opts['prune'] ?? false);
        $queue  = (bool) ($opts['queue'] ?? true);
        $userId = isset($opts['userId']) ? (int) $opts['userId'] : null;

        $db    = $this->db();
        $inUse = $this->fetchInUseCas();
        $plan  = $this->plan($parsed, $this->fetchExisting($version), $inUse, $version, $prune);

        $importedAt = gmdate('Y-m-d H:i:s');
        $prunedCas  = [];

        $db->beginTransaction();
        try {
            foreach (array_chunk($plan['toUpsert'], self::CHUNK) as $chunk) {
                $values = [];
                $params = [];
                foreach ($chunk as [$cas, $name, $active, $flags]) {
                    $values[] = "(?, ?, ?, ?, 'EPA', ?, ?)";
                    array_push($params, $cas, $name, $active, $flags, $version, $importedAt);
                }
                $db->query(
                    "INSERT INTO tsca_inventory
                        (cas_number, chemical_name, is_active_inventory, flags, source_ref, source_version, imported_at)
                     VALUES " . implode(', ', $values) . "
                     ON DUPLICATE KEY UPDATE
                        chemical_name       = VALUES(chemical_name),
                        is_active_inventory = VALUES(is_active_inventory),
                        flags               = VALUES(flags),
                        source_ref          = 'EPA',
                        source_version      = VALUES(source_version),
                        imported_at         = VALUES(imported_at)",
                    $params
                );
            }
            foreach (array_chunk($plan['toRestamp'], self::CHUNK) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $db->query(
                    "UPDATE tsca_inventory SET source_version = ?, imported_at = ?
                     WHERE cas_number IN ({$ph}) AND (source_ref IS NULL OR source_ref <> 'manual')",
                    array_merge([$version, $importedAt], $chunk)
                );
            }

            if ($prune) {
                $stale = $db->fetchAll(
                    "SELECT cas_number FROM tsca_inventory
                     WHERE source_ref = 'EPA' AND (source_version IS NULL OR source_version <> ?)",
                    [$version]
                );
                $prunedCas = array_map(static fn(array $r): string => (string) $r['cas_number'], $stale);
                foreach (array_chunk($prunedCas, self::CHUNK) as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '?'));
                    $db->query("DELETE FROM tsca_inventory WHERE source_ref = 'EPA' AND cas_number IN ({$ph})", $chunk);
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw new \RuntimeException('Import failed, rolled back: ' . $e->getMessage(), 0, $e);
        }

        // Bump affected RMs: resolution changed = inserted ∪ actually pruned.
        $changedCas = array_values(array_unique(array_merge($plan['insertedCas'], $prunedCas)));
        $affected   = self::affectedInUse($changedCas, $inUse);

        $bumpedRms = 0;
        $queued    = 0;
        if ($queue && $affected !== []) {
            $bumpedRms = RegulatoryListBumper::bumpByCasMany($affected);
            $queued    = RegulatoryListBumper::queueSdsUpdatesByCas($affected, $userId, 'TSCA inventory import ' . $version);
        }

        $plan['pruned']        = count($prunedCas);
        $plan['prunedCas']     = $prunedCas;
        $plan['changedCas']    = $changedCas;
        $plan['affected']      = $affected;
        $plan['affectedCount'] = count($affected);
        $plan['importedAt']    = $importedAt;
        $plan['rmsBumped']     = $bumpedRms;
        $plan['sdsQueued']     = $queued;
        unset($plan['toUpsert'], $plan['toRestamp']);
        return $plan;
    }

    /** Counts suitable for an audit-log diff / flash summary. */
    public static function summary(array $r): array
    {
        return [
            'version'           => $r['version'] ?? null,
            'inserted'          => (int) ($r['inserted'] ?? 0),
            'updated'           => (int) ($r['updated'] ?? 0),
            'unchanged'         => (int) ($r['unchanged'] ?? 0),
            'skipped_manual'    => (int) ($r['skippedManual'] ?? 0),
            'pruned'            => (int) ($r['pruned'] ?? 0),
            'cas_in_use_changed' => (int) ($r['affectedCount'] ?? 0),
            'rms_bumped'        => (int) ($r['rmsBumped'] ?? 0),
            'sds_queued'        => (int) ($r['sdsQueued'] ?? 0),
        ];
    }
}
