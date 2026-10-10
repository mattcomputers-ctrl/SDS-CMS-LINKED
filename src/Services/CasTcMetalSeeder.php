<?php
/**
 * CasTcMetalSeeder — audit #12. Seeds cas_master.tc_metals: the
 * 40 CFR 261.24 toxicity-characteristic metals (As, Ba, Cd, Cr, Pb, Hg, Se,
 * Ag) a substance contains, so RCRAService gives a metal compound (lead
 * chromate, cadmium pigments, chromium oxide, barium sulfate / lakes ...)
 * the D004–D011 code and TCLP limit of the element row in rcra_waste_codes.
 *
 * Same two-step shape as CasElementFlagSeeder (#19), whose name and RM
 * loaders it reuses. CasElementFlagger::inferTcMetals() decides:
 *   1. a parseable Hill molecular_formula is authoritative;
 *   2. otherwise conservative name patterns (incl. Colour Index pigment
 *      names) over the preferred name, synonyms and constituent / Prop 65 /
 *      HAP list names.
 * NULL and '' both mean "none", so the first run touches only CAS that
 * really contain a TC metal (no catalogue-wide bump).
 *
 * plan() is DB-free when the row sets are injected. apply() re-plans,
 * writes tc_metals + tc_metals_source='seed' and bumps the RMs carrying a
 * changed CAS in ONE transaction, then queues SDS updates unless
 * queue=false (a post-commit queue failure is returned in
 * 'postCommitError'). Rows set by hand on /determinations (CAS
 * Descriptions, "RCRA metals") carry tc_metals_source='manual' and are
 * skipped unless $force. Idempotent.
 */

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

final class CasTcMetalSeeder
{
    public const SOURCE_SEED   = 'seed';
    public const SOURCE_MANUAL = 'manual';

    public const QUEUE_REASON = 'Section 13 RCRA metal flags seeded (audit #12)';

    public function __construct(private ?Database $db = null)
    {
    }

    private function db(): Database
    {
        return $this->db ??= Database::getInstance();
    }

    /** @return array<int, array> cas_master rows with the TC-metal columns */
    public function fetchRows(): array
    {
        return $this->db()->fetchAll(
            "SELECT cas_number, preferred_name, synonyms_json, molecular_formula, tc_metals, tc_metals_source
             FROM cas_master
             ORDER BY cas_number"
        );
    }

    /** @return array<string, string[]> cas → extra names (same sources as #19) */
    public function fetchNamesByCas(): array
    {
        return (new CasElementFlagSeeder($this->db()))->fetchNamesByCas();
    }

    /** @return array<string, int[]> cas → raw_material_id[] */
    public function fetchRmsByCas(): array
    {
        return (new CasElementFlagSeeder($this->db()))->fetchRmsByCas();
    }

    /**
     * @param string[] $extraNames
     * @return array{cas:string, name:string, current:string[], proposed:string[],
     *               basis:string, source:?string, status:'changed'|'unchanged'|'manual'}
     */
    public static function classify(array $row, array $extraNames, bool $force): array
    {
        $cas     = (string) ($row['cas_number'] ?? '');
        $names   = CasElementFlagSeeder::namesFor($row, $extraNames);
        $formula = $row['molecular_formula'] ?? null;
        $formula = is_string($formula) ? $formula : null;

        $proposed = CasElementFlagger::inferTcMetals($formula, $names);
        $stored   = $row['tc_metals'] ?? null;
        $current  = CasElementFlagger::parseTcMetals(is_string($stored) ? $stored : null);

        $basis = CasElementFlagger::tcMetalsFromFormula($formula) !== null
            ? 'formula ' . $formula
            : 'names: ' . json_encode(CasElementFlagger::matchedTcMetalKeywords($names), JSON_UNESCAPED_UNICODE);

        $source = $row['tc_metals_source'] ?? null;
        $source = is_string($source) && $source !== '' ? $source : null;

        if ($current === $proposed) {
            $status = 'unchanged';
        } elseif ($source === self::SOURCE_MANUAL && !$force) {
            $status = 'manual';
        } else {
            $status = 'changed';
        }

        return [
            'cas'      => $cas,
            'name'     => (string) ($row['preferred_name'] ?? ''),
            'current'  => $current,
            'proposed' => $proposed,
            'basis'    => $basis,
            'source'   => $source,
            'status'   => $status,
        ];
    }

    /**
     * Classify every cas_master row. Nothing is written. Same result shape
     * as CasElementFlagSeeder::plan() (rows: cas, name, current, proposed,
     * basis, source, rmCount — only the CAS that change).
     */
    public function plan(bool $force, ?array $rows = null, ?array $namesByCas = null, ?array $rmsByCas = null): array
    {
        $rows       ??= $this->fetchRows();
        $namesByCas ??= $this->fetchNamesByCas();
        $rmsByCas   ??= $this->fetchRmsByCas();

        $scanned = $changed = $unchanged = $skippedManual = 0;
        $out        = [];
        $changedCas = [];
        $rmIds      = [];

        foreach ($rows as $row) {
            $scanned++;
            $cas = (string) ($row['cas_number'] ?? '');
            $c   = self::classify($row, $namesByCas[$cas] ?? [], $force);

            if ($c['status'] === 'unchanged') {
                $unchanged++;
                continue;
            }
            if ($c['status'] === 'manual') {
                $skippedManual++;
                continue;
            }

            $changed++;
            $changedCas[] = $cas;
            $rms = $rmsByCas[$cas] ?? [];
            foreach ($rms as $id) {
                $rmIds[(int) $id] = true;
            }
            unset($c['status']);
            $c['rmCount'] = count(array_unique($rms));
            $out[] = $c;
        }

        return [
            'force'         => $force,
            'scanned'       => $scanned,
            'changed'       => $changed,
            'unchanged'     => $unchanged,
            'skippedManual' => $skippedManual,
            'inUseRmCount'  => count($rmIds),
            'rows'          => $out,
            'changedCas'    => $changedCas,
        ];
    }

    /**
     * Seed tc_metals. Same transaction / post-commit contract as
     * CasElementFlagSeeder::apply().
     *
     * @param array{force?:bool, queue?:bool, userId?:?int} $opts
     * @throws \RuntimeException on a failed write (rolled back)
     */
    public function apply(array $opts = []): array
    {
        $force  = (bool) ($opts['force'] ?? false);
        $queue  = (bool) ($opts['queue'] ?? true);
        $userId = isset($opts['userId']) ? (int) $opts['userId'] : null;

        $db   = $this->db();
        $plan = $this->plan($force);

        $bumpedRms = 0;
        $queued    = 0;
        $postError = null;

        if ($plan['rows'] !== []) {
            $db->beginTransaction();
            try {
                foreach ($plan['rows'] as $r) {
                    $db->update(
                        'cas_master',
                        ['tc_metals' => implode(',', $r['proposed']), 'tc_metals_source' => self::SOURCE_SEED],
                        'cas_number = ?',
                        [$r['cas']]
                    );
                }
                // Section 13 content changes: bump every RM carrying a changed CAS.
                $bumpedRms = RegulatoryListBumper::bumpByCasMany($plan['changedCas']);
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollback();
                throw new \RuntimeException('RCRA metal flag seed failed, rolled back: ' . $e->getMessage(), 0, $e);
            }
        }

        if ($queue && $plan['changedCas'] !== []) {
            try {
                $queued = RegulatoryListBumper::queueSdsUpdatesByCas($plan['changedCas'], $userId, self::QUEUE_REASON);
            } catch (\Throwable $e) {
                $postError = 'SDS-update queueing failed after the RCRA metal flags and raw-material bumps were committed: '
                    . $e->getMessage();
            }
        }

        $plan['queue']           = $queue;
        $plan['rmsBumped']       = $bumpedRms;
        $plan['sdsQueued']       = $queued;
        $plan['postCommitError'] = $postError;
        return $plan;
    }

    /** Counts for an audit-log diff / flash summary (same keys as #19). */
    public static function summary(array $r): array
    {
        return [
            'force'          => (bool) ($r['force'] ?? false),
            'queue'          => (bool) ($r['queue'] ?? true),
            'scanned'        => (int) ($r['scanned'] ?? 0),
            'changed'        => (int) ($r['changed'] ?? 0),
            'unchanged'      => (int) ($r['unchanged'] ?? 0),
            'skipped_manual' => (int) ($r['skippedManual'] ?? 0),
            'rms_bumped'     => (int) ($r['rmsBumped'] ?? 0),
            'sds_queued'     => (int) ($r['sdsQueued'] ?? 0),
        ];
    }
}
