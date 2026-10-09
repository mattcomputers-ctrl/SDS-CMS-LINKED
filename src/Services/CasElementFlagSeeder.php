<?php
/**
 * CasElementFlagSeeder — the one implementation behind both
 * scripts/seed-cas-element-flags.php (CLI) and the "Seed element flags
 * (preview)" page on /determinations/element-flags
 * (AdminController@elementFlagsSeedPreview / applyElementFlagsSeed).
 *
 * Seeds the cas_master element flags (has_nitrogen / has_sulfur /
 * has_halogen) that drive the SDS Section 10 "Hazardous decomposition
 * products" sentence (content audit #19). For every cas_master row,
 * CasElementFlagger::infer() decides the flags:
 *   1. a parseable Hill molecular_formula is authoritative (N / S / F,Cl,Br,I);
 *   2. otherwise a conservative keyword scan of the preferred name, the
 *      synonyms_json entries and every constituent / Prop 65 / HAP list
 *      name recorded for that CAS.
 *
 * Two steps, so the dry-run / preview and the real run share every line of
 * logic:
 *
 *   plan($force, …)   DB-free when the row sets are injected. Classifies
 *                     every CAS as changed / unchanged / skipped-manual and
 *                     reports, per changed CAS, the current and proposed
 *                     flags plus the basis (formula, or the keywords that
 *                     fired) so false positives can be reviewed before
 *                     anything is written.
 *   apply($opts)      Writes. Re-plans against the live table, updates the
 *                     changed rows (element_flags_source = 'seed') AND bumps
 *                     the raw materials carrying a changed CAS
 *                     (raw_materials.updated_at = UTC, the bulk-publish
 *                     staleness signal) in one transaction, then queues
 *                     SDS-update rows unless queue=false. A queueing failure
 *                     after the commit is returned as 'postCommitError'.
 *
 * Rows corrected with the "Flags" button on /determinations (CAS
 * Descriptions tab) carry element_flags_source = 'manual' and are skipped
 * unless $force. A manual row whose inferred flags already match is counted
 * as unchanged, not skipped.
 *
 * NOTE: the FIRST run on an existing catalog bumps every RM that carries a
 * flagged CAS (Section 10 text changes for those products) — run it before
 * the next bulk publish, and consider queue=false if the SDS Updates page
 * would be flooded.
 *
 * Idempotent: a second run changes nothing (no bumps, no queue rows).
 */

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

final class CasElementFlagSeeder
{
    public const SOURCE_SEED   = 'seed';
    public const SOURCE_MANUAL = 'manual';

    public const QUEUE_REASON = 'Section 10 element flags seeded (audit #19)';

    /** Null is allowed for the DB-free step (plan with injected rows / tests). */
    public function __construct(private ?Database $db = null)
    {
    }

    private function db(): Database
    {
        return $this->db ??= Database::getInstance();
    }

    /* ------------------------------------------------------------------
     *  Loaders (DB) — plan() calls them when no row sets are injected
     * ----------------------------------------------------------------*/

    /**
     * @return array<int, array{cas_number:string, preferred_name:?string, synonyms_json:?string,
     *                          molecular_formula:?string, has_nitrogen:int, has_sulfur:int,
     *                          has_halogen:int, element_flags_source:?string}>
     */
    public function fetchRows(): array
    {
        return $this->db()->fetchAll(
            "SELECT cas_number, preferred_name, synonyms_json, molecular_formula,
                    has_nitrogen, has_sulfur, has_halogen, element_flags_source
             FROM cas_master
             ORDER BY cas_number"
        );
    }

    /**
     * Every extra name recorded for a CAS outside cas_master: constituent
     * chemical names, Prop 65 and HAP list names.
     *
     * @return array<string, string[]> cas → names
     */
    public function fetchNamesByCas(): array
    {
        $namesByCas  = [];
        $nameSources = [
            "SELECT DISTINCT cas_number, chemical_name FROM raw_material_constituents
             WHERE cas_number <> '' AND chemical_name <> ''",
            "SELECT cas_number, chemical_name FROM prop65_list WHERE chemical_name <> ''",
            "SELECT cas_number, chemical_name FROM hap_list WHERE chemical_name <> ''",
        ];
        foreach ($nameSources as $sql) {
            foreach ($this->db()->fetchAll($sql) as $r) {
                $namesByCas[(string) $r['cas_number']][] = (string) $r['chemical_name'];
            }
        }
        return $namesByCas;
    }

    /**
     * Raw materials carrying each CAS, so the plan can say how many RMs a
     * run would bump.
     *
     * @return array<string, int[]> cas → raw_material_id[]
     */
    public function fetchRmsByCas(): array
    {
        $rmsByCas = [];
        $rows = $this->db()->fetchAll(
            "SELECT DISTINCT cas_number, raw_material_id FROM raw_material_constituents WHERE cas_number <> ''"
        );
        foreach ($rows as $r) {
            $rmsByCas[(string) $r['cas_number']][] = (int) $r['raw_material_id'];
        }
        return $rmsByCas;
    }

    /* ------------------------------------------------------------------
     *  Pure helpers
     * ----------------------------------------------------------------*/

    /**
     * Every name known for a cas_master row: preferred name, synonyms_json
     * entries (a bare list or {"synonyms": [...]}) and the extra names.
     *
     * @param string[] $extraNames
     * @return string[]
     */
    public static function namesFor(array $row, array $extraNames = []): array
    {
        $names = [(string) ($row['preferred_name'] ?? '')];
        $syn   = $row['synonyms_json'] ?? null;
        if (is_string($syn) && $syn !== '') {
            $decoded = json_decode($syn, true);
            if (is_array($decoded)) {
                if (isset($decoded['synonyms']) && is_array($decoded['synonyms'])) {
                    $decoded = $decoded['synonyms'];
                }
                foreach ($decoded as $s) {
                    if (is_string($s)) {
                        $names[] = $s;
                    }
                }
            }
        }
        foreach ($extraNames as $n) {
            $names[] = (string) $n;
        }
        return $names;
    }

    /**
     * Classify one cas_master row.
     *
     * @param string[] $extraNames
     * @return array{
     *   cas:string, name:string,
     *   current: array{has_nitrogen:int, has_sulfur:int, has_halogen:int},
     *   proposed: array{has_nitrogen:int, has_sulfur:int, has_halogen:int},
     *   basis:string, source:?string, status:'changed'|'unchanged'|'manual'
     * }
     */
    public static function classify(array $row, array $extraNames, bool $force): array
    {
        $cas     = (string) ($row['cas_number'] ?? '');
        $names   = self::namesFor($row, $extraNames);
        $formula = $row['molecular_formula'] ?? null;
        $formula = is_string($formula) ? $formula : null;

        $inferred = CasElementFlagger::infer($formula, $names);
        $proposed = [];
        $current  = [];
        foreach (CasElementFlagger::FLAGS as $flag) {
            $proposed[$flag] = (int) $inferred[$flag];
            $current[$flag]  = (int) ($row[$flag] ?? 0);
        }

        $basis = CasElementFlagger::fromFormula($formula) !== null
            ? 'formula ' . $formula
            : 'names: ' . json_encode(CasElementFlagger::matchedKeywords($names), JSON_UNESCAPED_UNICODE);

        $source = $row['element_flags_source'] ?? null;
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

    /* ------------------------------------------------------------------
     *  1. plan — DB-free when the row sets are injected
     * ----------------------------------------------------------------*/

    /**
     * Classify every cas_master row. Nothing is written.
     *
     * @param bool                         $force      also rewrite rows whose element_flags_source = 'manual'
     * @param array<int, array>|null       $rows       cas_master rows (null = fetchRows())
     * @param array<string, string[]>|null $namesByCas cas → extra names (null = fetchNamesByCas())
     * @param array<string, int[]>|null    $rmsByCas   cas → raw_material_id[] (null = fetchRmsByCas())
     *
     * @return array{
     *   force:bool,
     *   scanned:int, changed:int, unchanged:int, skippedManual:int,
     *   inUseRmCount:int,
     *   rows: array<int, array{cas:string, name:string, current:array, proposed:array, basis:string, source:?string, rmCount:int}>,
     *   changedCas:string[]
     * }
     *   rows holds only the CAS that would change, in cas_master order;
     *   inUseRmCount is the number of distinct raw materials carrying a
     *   changed CAS (what apply() would bump).
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

    /* ------------------------------------------------------------------
     *  2. apply — writes
     * ----------------------------------------------------------------*/

    /**
     * Seed the flags. Re-plans against the live table so a stale preview can
     * never write the wrong rows.
     *
     * @param array{force?:bool, queue?:bool, userId?:?int} $opts
     *   force   also rewrite 'manual' rows (default false)
     *   queue   queue SDS-update rows for the affected products (default
     *           true); the raw materials are bumped either way
     *   userId  recorded on the queued SDS-update rows (null = system)
     *
     * The flag UPDATEs and the raw-material bump run in ONE transaction: a
     * failure in either rolls everything back, so a re-run (page or CLI)
     * plans the same rows again and repeats the whole seed including the
     * bump. The SDS-update queueing runs after the commit (it is a review
     * convenience, not SDS content); if it fails the flags and bumps are
     * already committed and a re-run would plan "Changed: 0", so the error
     * is RETURNED in 'postCommitError' (with the committed counts) instead of
     * thrown, and callers must surface it — the operator then re-queues via
     * the SDS Updates page scan rather than assuming nothing was written.
     *
     * @return array plan() result (actual) + queue, rmsBumped, sdsQueued,
     *               postCommitError (?string — set when queueing failed after
     *               the flags and bumps were committed)
     * @throws \RuntimeException on a failed write (transaction rolled back,
     *                           nothing changed)
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
                        $r['proposed'] + ['element_flags_source' => self::SOURCE_SEED],
                        'cas_number = ?',
                        [$r['cas']]
                    );
                }
                // A flag change is SDS content: bump every RM carrying a
                // changed CAS (bulk-publish staleness). Same Database
                // singleton / PDO connection, so this joins the transaction.
                $bumpedRms = RegulatoryListBumper::bumpByCasMany($plan['changedCas']);
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollback();
                throw new \RuntimeException('Element flag seed failed, rolled back: ' . $e->getMessage(), 0, $e);
            }
        }

        // Queue the published SDSs that use the bumped RMs. Post-commit: a
        // failure here must not look like "nothing was written".
        if ($queue && $plan['changedCas'] !== []) {
            try {
                $queued = RegulatoryListBumper::queueSdsUpdatesByCas($plan['changedCas'], $userId, self::QUEUE_REASON);
            } catch (\Throwable $e) {
                $postError = 'SDS-update queueing failed after the flags and raw-material bumps were committed: '
                    . $e->getMessage();
            }
        }

        $plan['queue']           = $queue;
        $plan['rmsBumped']       = $bumpedRms;
        $plan['sdsQueued']       = $queued;
        $plan['postCommitError'] = $postError;
        return $plan;
    }

    /** Counts suitable for an audit-log diff / flash summary. */
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
