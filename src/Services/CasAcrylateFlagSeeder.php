<?php
/**
 * CasAcrylateFlagSeeder — A12(a) (fixed-text review, owner decisions
 * 2026-10-12). Seeds cas_master.is_acrylate, the reactive acrylate /
 * methacrylate monomer-or-oligomer flag UVAcrylateRulePack::detectAcrylates()
 * reads for the Section 4 acrylate names on UV sheets.
 *
 * Same two-step shape as CasTcMetalSeeder / CasElementFlagSeeder (#12/#19),
 * whose name and RM loaders it reuses. UVAcrylateRulePack::seedFlagFor()
 * decides: 1 when the CAS is on the legacy list or any known name (preferred
 * name, synonyms, constituent / Prop 65 / HAP list names) passes the name
 * rule; otherwise NULL ("not determined": the runtime fallback applies).
 * The seeder never writes 0 — "not an acrylate" is a manual decision on
 * /determinations (CAS Descriptions, "Acrylate").
 *
 * Bumps only what changes a sheet: a written row bumps the RMs carrying it
 * only when the EFFECTIVE detection (stored flag, else legacy list / name
 * rule on any name a sheet tests: Prop 65 / registry name or a constituent
 * name, detectionChanges()) differs before and after. Writing 1
 * where the fallback already detects the CAS is metadata only.
 *
 * plan() is DB-free when the row sets are injected. apply() re-plans, writes
 * is_acrylate + acrylate_source and bumps the RMs of bumpCas in ONE
 * transaction, then queues SDS updates for bumpCas unless queue=false (a
 * post-commit queue failure is returned in 'postCommitError'). Rows set by
 * hand carry acrylate_source='manual' and are skipped unless $force.
 * Idempotent.
 */

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

final class CasAcrylateFlagSeeder
{
    public const SOURCE_SEED   = 'seed';
    public const SOURCE_MANUAL = 'manual';

    public const QUEUE_REASON = 'Acrylate flags seeded (A12a)';

    public function __construct(private ?Database $db = null)
    {
    }

    private function db(): Database
    {
        return $this->db ??= Database::getInstance();
    }

    /** @return array<int, array> cas_master rows with the acrylate columns and the Prop 65 name */
    public function fetchRows(): array
    {
        return $this->db()->fetchAll(
            "SELECT cm.cas_number, cm.preferred_name, cm.synonyms_json, cm.is_acrylate, cm.acrylate_source,
                    (SELECT p.chemical_name FROM prop65_list p
                      WHERE p.cas_number = cm.cas_number AND p.chemical_name <> '' LIMIT 1) AS prop65_name
             FROM cas_master cm
             ORDER BY cm.cas_number"
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
     * @return array{cas:string, name:string, current:?int, proposed:?int, basis:string,
     *               source:?string, status:'changed'|'unchanged'|'manual', bump:bool, nameCheck:bool}
     */
    public static function classify(array $row, array $extraNames, bool $force): array
    {
        $cas      = (string) ($row['cas_number'] ?? '');
        $names    = CasElementFlagSeeder::namesFor($row, $extraNames);
        $proposed = UVAcrylateRulePack::seedFlagFor($cas, $names);
        $flag     = UVAcrylateRulePack::flagValue($row['is_acrylate'] ?? null);
        $current  = $flag === null ? null : (int) $flag;

        $fired = [];
        foreach ($names as $n) {
            $n = trim((string) $n);
            if ($n !== '' && !in_array($n, $fired, true) && UVAcrylateRulePack::nameLooksLikeAcrylate($n)) {
                $fired[] = $n;
            }
        }
        $parts = [];
        if (UVAcrylateRulePack::isLegacyAcrylateCas($cas)) {
            $parts[] = 'legacy list';
        }
        if ($fired !== []) {
            $parts[] = 'names: ' . json_encode($fired, JSON_UNESCAPED_UNICODE);
        }
        $basis = $parts === [] ? 'no acrylate name' : implode('; ', $parts);

        $source = $row['acrylate_source'] ?? null;
        $source = is_string($source) && $source !== '' ? $source : null;

        if ($current === $proposed) {
            $status = 'unchanged';
        } elseif ($source === self::SOURCE_MANUAL && !$force) {
            $status = 'manual';
        } else {
            $status = 'changed';
        }

        // The name a finished-good sheet prints for this CAS
        // (Formula::getExpandedComposition: Prop 65 name, then the registry name),
        // plus the constituent / list names (resale sheets test the constituent's own
        // chemical_name): bump when the detection flips for any of them.
        $sheetName = trim((string) ($row['prop65_name'] ?? ''));
        if ($sheetName === '') {
            $sheetName = trim((string) ($row['preferred_name'] ?? ''));
        }
        $bump = self::detectionChanges($current, $proposed, $cas, array_merge([$sheetName], $extraNames));

        return [
            'cas'       => $cas,
            'name'      => (string) ($row['preferred_name'] ?? ''),
            'current'   => $current,
            'proposed'  => $proposed,
            'basis'     => $basis,
            'source'    => $source,
            'status'    => $status,
            'bump'      => $bump,
            // On the legacy list but no known name looks like an acrylate:
            // the registry description may be wrong (check it before publishing).
            'nameCheck' => $proposed === 1 && $fired === [],
        ];
    }

    /**
     * Whether changing the stored flag $from -> $to changes what a sheet detects for
     * $cas. With the flag NULL and the CAS off the legacy list, detection falls back to
     * the name rule, and sheets test different names: a finished-good sheet the Prop 65
     * name, else the registry name, else the constituent name; a resale sheet the
     * constituent's own chemical_name. So the change counts (bump + queue) when the
     * effective detection flips for ANY of $names (sheet name, constituent names, ...).
     * A save that confirms what every name already detects stays metadata only.
     *
     * @param string[] $names
     */
    public static function detectionChanges(?int $from, ?int $to, string $cas, array $names): bool
    {
        if ($from === $to) {
            return false;
        }
        $seen = [];
        foreach ($names as $n) {
            $n = trim((string) $n);
            if (isset($seen[$n])) {
                continue;
            }
            $seen[$n] = true;
            if (UVAcrylateRulePack::effectiveFlag($from, $cas, $n) !== UVAcrylateRulePack::effectiveFlag($to, $cas, $n)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Classify every cas_master row. Nothing is written.
     *
     * @return array{force:bool, scanned:int, changed:int, unchanged:int, skippedManual:int,
     *   textChanges:int, inUseRmCount:int, rows:array, changedCas:string[], bumpCas:string[],
     *   legacyNotInRegistry:string[]}
     *   rows: only the CAS that would be written (cas, name, current, proposed,
     *   basis, source, bump, nameCheck, rmCount); bumpCas ⊆ changedCas are the
     *   CAS whose detection changes; inUseRmCount counts the distinct RMs of
     *   bumpCas (what apply() bumps).
     */
    public function plan(bool $force, ?array $rows = null, ?array $namesByCas = null, ?array $rmsByCas = null): array
    {
        $rows       ??= $this->fetchRows();
        $namesByCas ??= $this->fetchNamesByCas();
        $rmsByCas   ??= $this->fetchRmsByCas();

        $scanned = $changed = $unchanged = $skippedManual = 0;
        $out        = [];
        $changedCas = [];
        $bumpCas    = [];
        $rmIds      = [];
        $seen       = [];

        foreach ($rows as $row) {
            $scanned++;
            $cas = (string) ($row['cas_number'] ?? '');
            $seen[$cas] = true;
            $c = self::classify($row, $namesByCas[$cas] ?? [], $force);

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
            if ($c['bump']) {
                $bumpCas[] = $cas;
                foreach ($rms as $id) {
                    $rmIds[(int) $id] = true;
                }
            }
            unset($c['status']);
            $c['rmCount'] = count(array_unique($rms));
            $out[] = $c;
        }

        $legacyMissing = array_values(array_filter(
            UVAcrylateRulePack::LEGACY_ACRYLATE_CAS,
            static fn (string $cas): bool => !isset($seen[$cas])
        ));

        return [
            'force'               => $force,
            'scanned'             => $scanned,
            'changed'             => $changed,
            'unchanged'           => $unchanged,
            'skippedManual'       => $skippedManual,
            'textChanges'         => count($bumpCas),
            'inUseRmCount'        => count($rmIds),
            'rows'                => $out,
            'changedCas'          => $changedCas,
            'bumpCas'             => $bumpCas,
            'legacyNotInRegistry' => $legacyMissing,
        ];
    }

    /**
     * Seed the flags. Same transaction / post-commit contract as
     * CasTcMetalSeeder::apply(), except that only bumpCas is bumped / queued.
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
                        [
                            'is_acrylate'     => $r['proposed'],
                            'acrylate_source' => $r['proposed'] === null ? null : self::SOURCE_SEED,
                        ],
                        'cas_number = ?',
                        [$r['cas']]
                    );
                }
                // Only a change in what a sheet detects is SDS content: bump the
                // RMs of bumpCas (bulk-publish staleness). A flag that confirms
                // what the fallback already detects is metadata: no RM write.
                $bumpedRms = RegulatoryListBumper::bumpByCasMany($plan['bumpCas']);
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollback();
                throw new \RuntimeException('Acrylate flag seed failed, rolled back: ' . $e->getMessage(), 0, $e);
            }
        }

        if ($queue && $plan['bumpCas'] !== []) {
            try {
                $queued = RegulatoryListBumper::queueSdsUpdatesByCas($plan['bumpCas'], $userId, self::QUEUE_REASON);
            } catch (\Throwable $e) {
                $postError = 'SDS-update queueing failed after the acrylate flags and raw-material bumps were committed: '
                    . $e->getMessage();
            }
        }

        $plan['queue']           = $queue;
        $plan['rmsBumped']       = $bumpedRms;
        $plan['sdsQueued']       = $queued;
        $plan['postCommitError'] = $postError;
        return $plan;
    }

    /** Counts for an audit-log diff / flash summary (#19 keys + text_changes). */
    public static function summary(array $r): array
    {
        return [
            'force'          => (bool) ($r['force'] ?? false),
            'queue'          => (bool) ($r['queue'] ?? true),
            'scanned'        => (int) ($r['scanned'] ?? 0),
            'changed'        => (int) ($r['changed'] ?? 0),
            'text_changes'   => (int) ($r['textChanges'] ?? 0),
            'unchanged'      => (int) ($r['unchanged'] ?? 0),
            'skipped_manual' => (int) ($r['skippedManual'] ?? 0),
            'rms_bumped'     => (int) ($r['rmsBumped'] ?? 0),
            'sds_queued'     => (int) ($r['sdsQueued'] ?? 0),
        ];
    }
}
