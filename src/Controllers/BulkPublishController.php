<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\App;
use SDS\Core\CSRF;
use SDS\Core\Database;
use SDS\Services\AuditService;

/**
 * BulkPublishController — Publish new SDS versions for all finished goods (admin only).
 *
 * Spawns parallel PHP CLI worker processes (one per CPU core) to generate
 * SDS data and PDFs across all supported languages with progress tracking.
 */
class BulkPublishController
{
    /** Directory for progress/batch files, relative to project root */
    private const PROGRESS_DIR = '/storage/exports';

    /**
     * Detect the number of available CPU cores.
     */
    private static function getCpuCount(): int
    {
        // Linux: /proc/cpuinfo
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            if ($cpuinfo !== false) {
                $count = substr_count($cpuinfo, 'processor');
                if ($count > 0) {
                    return $count;
                }
            }
        }

        // macOS / BSD: sysctl
        $result = @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
        if ($result !== null && ($cores = (int) trim($result)) > 0) {
            return $cores;
        }

        // nproc (Linux fallback)
        $result = @shell_exec('nproc 2>/dev/null');
        if ($result !== null && ($cores = (int) trim($result)) > 0) {
            return $cores;
        }

        return 1;
    }

    /**
     * Determine worker count for bulk publishing.
     *
     * Workers are I/O-bound (DB + TCPDF + disk), so we use a minimum of 8
     * workers regardless of detected CPU cores, scaling up to 4× cores on
     * larger machines.  Override via admin settings or config sds.publish_workers.
     */
    private static function getWorkerCount(int $totalItems): int
    {
        // Check admin settings (DB) first, fall back to config file
        $db = Database::getInstance();
        $row = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'sds.publish_workers'");
        $configured = $row ? (int) $row['value'] : (int) App::config('sds.publish_workers', 0);

        if ($configured > 0) {
            return min($configured, $totalItems);
        }

        // Auto: 4× CPU cores with a floor of 8 (I/O-bound workload benefits
        // from many concurrent workers even on single-core machines)
        $workers = max(8, self::getCpuCount() * 4);

        return min($workers, $totalItems);
    }

    /**
     * GET /admin/bulk-publish — Show the bulk publish page.
     */
    public function page(): void
    {
        if (!can_read('bulk_publish')) {
            http_response_code(403);
            include dirname(__DIR__) . '/Views/errors/403.php';
            exit;
        }

        $db = Database::getInstance();

        // Compute eligible FGs with full recursion through FG sub-components.
        // Rule 1: every upstream raw material (through any depth of FG nesting)
        //         must have been reviewed by a user OR covered by an active CAS
        //         determination on one of its constituents.
        // Rule 2: the FG's most recent non-alias published SDS must be older
        //         than the newest upstream change (formula, raw material,
        //         constituent, or CAS determination) — otherwise skipped.
        $eligibility = self::computeEligibleFinishedGoods($db);
        $eligibleFgs = $eligibility['eligible'];
        $blockedFgs  = $eligibility['blocked'];

        // Count unique aliases (by base customer code) across eligible FGs only.
        $aliasCount = 0;
        if (!empty($eligibleFgs)) {
            $eligibleIds = array_column($eligibleFgs, 'id');
            $placeholders = implode(',', array_fill(0, count($eligibleIds), '?'));
            $aliasStats = $db->fetch(
                "SELECT COALESCE(SUM(sub.alias_cnt), 0) AS alias_count
                 FROM (
                     SELECT fg.id,
                            COUNT(DISTINCT SUBSTRING_INDEX(a.customer_code, '-', 1)) AS alias_cnt
                     FROM aliases a
                     INNER JOIN finished_goods fg ON fg.product_code = a.internal_code_base
                     WHERE fg.id IN ({$placeholders})
                     GROUP BY fg.id
                 ) sub",
                $eligibleIds
            );
            $aliasCount = (int) ($aliasStats['alias_count'] ?? 0);
        }

        // Resale items — purchased raw materials sold as-is under an
        // alias (the alias's internal_code_base points at an RM, not an
        // FG with a formula). Same review-ready rule as FGs: the RM
        // must be reviewed.
        $resaleEligibility = self::computeEligibleResaleItems($db);
        $eligibleResale    = $resaleEligibility['eligible'];
        $blockedResale     = $resaleEligibility['blocked'];

        $resaleAliasCount = 0;
        if (!empty($eligibleResale)) {
            $eligibleResaleBases = array_column($eligibleResale, 'base_code');
            $placeholders = implode(',', array_fill(0, count($eligibleResaleBases), '?'));
            $resaleAliasStats = $db->fetch(
                "SELECT COUNT(DISTINCT SUBSTRING_INDEX(customer_code, '-', 1)) AS cnt
                 FROM aliases
                 WHERE internal_code_base IN ({$placeholders})",
                $eligibleResaleBases
            );
            $resaleAliasCount = (int) ($resaleAliasStats['cnt'] ?? 0);
        }

        $languages = App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);

        view('admin/bulk-publish', [
            'pageTitle'           => 'Bulk SDS Publish',
            'fgCount'             => count($eligibleFgs),
            'blockedCount'        => count($blockedFgs),
            'aliasCount'          => $aliasCount,
            'resaleCount'         => count($eligibleResale),
            'resaleBlockedCount'  => count($blockedResale),
            'resaleAliasCount'    => $resaleAliasCount,
            'langCount'           => count($languages),
            'languages'           => $languages,
        ]);
    }

    /**
     * Compute the set of finished goods that bulk-publish should process.
     *
     * Walks the formula tree recursively through FG sub-components so that
     * nested intermediates are checked, not just top-level raw materials.
     * A finished good is eligible only when:
     *
     *   1. EVERY raw material in the transitive closure of its formula tree
     *      is user-reviewed. An RM counts as reviewed if either:
     *        (a) it has an audit_log entry with a real user_id, OR
     *        (b) an active competent_person_determinations row exists for
     *            any of its constituent CAS numbers.
     *   2. The FG's latest non-alias published SDS is older than the most
     *      recent upstream change (formula created_at, raw material
     *      updated_at, constituent updated_at, or active CPD updated_at)
     *      — OR the FG has no published SDS yet.
     *
     * All data is batch-loaded up front; recursion runs in memory with
     * cycle detection and per-formula memoization.
     *
     * @return array{eligible: array<int,array{id:int,product_code:string}>,
     *               blocked:  array<int,array{id:int,product_code:string}>}
     */
    private static function computeEligibleFinishedGoods(Database $db): array
    {
        // All active FGs with a current formula — the candidate set.
        $fgs = $db->fetchAll(
            "SELECT fg.id, fg.product_code, f.id AS formula_id, f.created_at
             FROM finished_goods fg
             INNER JOIN formulas f ON f.finished_good_id = fg.id AND f.is_current = 1
             WHERE fg.is_active = 1
             ORDER BY fg.product_code ASC"
        );

        if (empty($fgs)) {
            return ['eligible' => [], 'blocked' => []];
        }

        // ── Batch-load every table needed for the recursion & rule checks ──

        // formula_id → list of lines (raw_material_id OR finished_good_component_id)
        $linesByFormula = [];
        foreach ($db->fetchAll(
            "SELECT formula_id, raw_material_id, finished_good_component_id FROM formula_lines"
        ) as $r) {
            $linesByFormula[(int) $r['formula_id']][] = [
                'rm' => $r['raw_material_id'] !== null ? (int) $r['raw_material_id'] : null,
                'fg' => $r['finished_good_component_id'] !== null ? (int) $r['finished_good_component_id'] : null,
            ];
        }

        // finished_good_id → current formula_id (for recursion into sub-components)
        $fgToFormulaId       = [];
        $formulaCreatedAt    = [];
        foreach ($db->fetchAll("SELECT id, finished_good_id, created_at FROM formulas WHERE is_current = 1") as $r) {
            $fgToFormulaId[(int) $r['finished_good_id']] = (int) $r['id'];
            $formulaCreatedAt[(int) $r['id']]            = $r['created_at'];
        }

        // Reviewed RM ids (Rule 1) — shared with the per-FG SDS Review page
        // so both views agree on what "reviewed" means.
        $reviewedRms = \SDS\Services\SDSReadinessService::loadReviewedRmIds($db);

        // Per-RM max upstream timestamp = MAX(rm.updated_at, constituents.updated_at, CPDs.updated_at)
        $rmMaxUpstream = [];
        foreach ($db->fetchAll("SELECT id, updated_at FROM raw_materials") as $r) {
            $rmMaxUpstream[(int) $r['id']] = $r['updated_at'];
        }
        foreach ($db->fetchAll(
            "SELECT raw_material_id, MAX(updated_at) AS max_upd
             FROM raw_material_constituents GROUP BY raw_material_id"
        ) as $r) {
            $rmId = (int) $r['raw_material_id'];
            if (!isset($rmMaxUpstream[$rmId]) || $r['max_upd'] > $rmMaxUpstream[$rmId]) {
                $rmMaxUpstream[$rmId] = $r['max_upd'];
            }
        }
        $cpdByCas = [];
        foreach ($db->fetchAll(
            "SELECT cas_number, MAX(updated_at) AS max_upd
             FROM competent_person_determinations WHERE is_active = 1
             GROUP BY cas_number"
        ) as $r) {
            $cpdByCas[$r['cas_number']] = $r['max_upd'];
        }
        if (!empty($cpdByCas)) {
            foreach ($db->fetchAll("SELECT raw_material_id, cas_number FROM raw_material_constituents") as $r) {
                $cpdTs = $cpdByCas[$r['cas_number']] ?? null;
                if ($cpdTs === null) {
                    continue;
                }
                $rmId = (int) $r['raw_material_id'];
                if (!isset($rmMaxUpstream[$rmId]) || $cpdTs > $rmMaxUpstream[$rmId]) {
                    $rmMaxUpstream[$rmId] = $cpdTs;
                }
            }
        }

        // Last published SDS timestamp per FG (non-alias, published, not deleted)
        $lastPublishedByFg = [];
        foreach ($db->fetchAll(
            "SELECT finished_good_id, MAX(published_at) AS last
             FROM sds_versions
             WHERE status = 'published' AND is_deleted = 0 AND alias_id IS NULL
             GROUP BY finished_good_id"
        ) as $r) {
            $lastPublishedByFg[(int) $r['finished_good_id']] = $r['last'];
        }

        // ── Recursive walker (memoized) returning upstream RM set + max formula timestamp ──
        $cache = [];
        $walk = function (int $formulaId, array $visited) use (&$walk, $linesByFormula, $fgToFormulaId, $formulaCreatedAt, &$cache) {
            if (isset($cache[$formulaId])) {
                return $cache[$formulaId];
            }
            if (isset($visited[$formulaId])) {
                // Cycle guard — return an empty contribution
                return ['rms' => [], 'max_ts' => null];
            }
            $visited[$formulaId] = true;

            $rms   = [];
            $maxTs = $formulaCreatedAt[$formulaId] ?? null;

            foreach ($linesByFormula[$formulaId] ?? [] as $line) {
                if ($line['rm'] !== null) {
                    $rms[$line['rm']] = true;
                } elseif ($line['fg'] !== null) {
                    $subFormulaId = $fgToFormulaId[$line['fg']] ?? null;
                    if ($subFormulaId !== null) {
                        $sub = $walk($subFormulaId, $visited);
                        foreach ($sub['rms'] as $rmId => $_) {
                            $rms[$rmId] = true;
                        }
                        if ($sub['max_ts'] !== null && ($maxTs === null || $sub['max_ts'] > $maxTs)) {
                            $maxTs = $sub['max_ts'];
                        }
                    }
                }
            }

            $cache[$formulaId] = ['rms' => $rms, 'max_ts' => $maxTs];
            return $cache[$formulaId];
        };

        // ── Evaluate each FG against the two rules ──
        $eligible = [];
        $blocked  = [];

        foreach ($fgs as $fg) {
            $result      = $walk((int) $fg['formula_id'], []);
            $upstreamRms = $result['rms'];
            $maxTs       = $result['max_ts'];

            // Rule 1: every upstream RM must be reviewed
            $allReviewed = true;
            foreach ($upstreamRms as $rmId => $_) {
                if (!isset($reviewedRms[$rmId])) {
                    $allReviewed = false;
                    break;
                }
                if (isset($rmMaxUpstream[$rmId]) && ($maxTs === null || $rmMaxUpstream[$rmId] > $maxTs)) {
                    $maxTs = $rmMaxUpstream[$rmId];
                }
            }

            $entry = ['id' => (int) $fg['id'], 'product_code' => $fg['product_code']];

            if (!$allReviewed) {
                $blocked[] = $entry;
                continue;
            }

            // Rule 2: skip FGs whose SDS is still fresh
            $lastPub = $lastPublishedByFg[(int) $fg['id']] ?? null;
            if ($lastPub !== null && $maxTs !== null && $lastPub >= $maxTs) {
                continue;
            }

            $eligible[] = $entry;
        }

        return ['eligible' => $eligible, 'blocked' => $blocked];
    }

    /**
     * Compute the set of resale raw materials bulk-publish should process.
     *
     * A resale RM is a raw material whose base internal_code has at least
     * one alias pointing at it AND no matching finished_goods.product_code
     * with a current formula (otherwise the formula path wins). Eligibility
     * rules mirror computeEligibleFinishedGoods:
     *
     *   1. The RM itself must be reviewed (audit_log entry with a real
     *      user_id OR active CPD on one of its constituents).
     *   2. The RM's latest non-alias published SDS (sds_versions.raw_material_id
     *      = rmId, alias_id IS NULL) must be older than the most recent
     *      upstream change (RM updated_at, constituent updated_at, or CPD
     *      updated_at) — or no SDS exists yet.
     *
     * @return array{eligible: array<int,array{rm_id:int,base_code:string,description:string}>,
     *               blocked:  array<int,array{rm_id:int,base_code:string,description:string}>}
     */
    private static function computeEligibleResaleItems(Database $db): array
    {
        // Candidate RMs: those whose base internal_code matches at least
        // one alias's internal_code_base AND whose base does NOT match
        // any finished_goods.product_code with a current formula. One
        // row per RM (we want to publish per RM, not per alias).
        $candidates = $db->fetchAll(
            "SELECT rm.id AS rm_id,
                    SUBSTRING_INDEX(rm.internal_code, '-', 1) AS base_code,
                    rm.updated_at,
                    rm.supplier_product_name
             FROM raw_materials rm
             INNER JOIN (
                 SELECT DISTINCT internal_code_base
                 FROM aliases
             ) al ON al.internal_code_base = SUBSTRING_INDEX(rm.internal_code, '-', 1)
             WHERE NOT EXISTS (
                 SELECT 1 FROM finished_goods fg
                 INNER JOIN formulas f ON f.finished_good_id = fg.id AND f.is_current = 1
                 WHERE fg.product_code = SUBSTRING_INDEX(rm.internal_code, '-', 1)
             )
             GROUP BY SUBSTRING_INDEX(rm.internal_code, '-', 1)"
        );

        if (empty($candidates)) {
            return ['eligible' => [], 'blocked' => []];
        }

        // Shared reviewed-RM rule — identical to FG eligibility.
        $reviewedRms = \SDS\Services\SDSReadinessService::loadReviewedRmIds($db);

        // Per-RM upstream timestamps for Rule 2.
        $rmIds = array_map(fn($c) => (int) $c['rm_id'], $candidates);
        $placeholders = implode(',', array_fill(0, count($rmIds), '?'));

        $rmMaxUpstream = [];
        foreach ($db->fetchAll("SELECT id, updated_at FROM raw_materials WHERE id IN ({$placeholders})", $rmIds) as $r) {
            $rmMaxUpstream[(int) $r['id']] = $r['updated_at'];
        }
        foreach ($db->fetchAll(
            "SELECT raw_material_id, MAX(updated_at) AS max_upd
             FROM raw_material_constituents
             WHERE raw_material_id IN ({$placeholders})
             GROUP BY raw_material_id",
            $rmIds
        ) as $r) {
            $rmId = (int) $r['raw_material_id'];
            if (!isset($rmMaxUpstream[$rmId]) || $r['max_upd'] > $rmMaxUpstream[$rmId]) {
                $rmMaxUpstream[$rmId] = $r['max_upd'];
            }
        }
        // Pull CPDs active on constituents of these RMs and compare.
        foreach ($db->fetchAll(
            "SELECT rmc.raw_material_id, MAX(cpd.updated_at) AS max_upd
             FROM raw_material_constituents rmc
             INNER JOIN competent_person_determinations cpd
                 ON cpd.cas_number = rmc.cas_number AND cpd.is_active = 1
             WHERE rmc.raw_material_id IN ({$placeholders})
             GROUP BY rmc.raw_material_id",
            $rmIds
        ) as $r) {
            $rmId = (int) $r['raw_material_id'];
            if (!isset($rmMaxUpstream[$rmId]) || $r['max_upd'] > $rmMaxUpstream[$rmId]) {
                $rmMaxUpstream[$rmId] = $r['max_upd'];
            }
        }

        // Latest non-alias resale SDS per RM (raw_material_id set,
        // alias_id NULL).
        $lastPublished = [];
        foreach ($db->fetchAll(
            "SELECT raw_material_id, MAX(published_at) AS last
             FROM sds_versions
             WHERE status = 'published'
               AND is_deleted = 0
               AND alias_id IS NULL
               AND raw_material_id IN ({$placeholders})
             GROUP BY raw_material_id",
            $rmIds
        ) as $r) {
            $lastPublished[(int) $r['raw_material_id']] = $r['last'];
        }

        $eligible = [];
        $blocked  = [];

        foreach ($candidates as $c) {
            $rmId = (int) $c['rm_id'];
            $entry = [
                'rm_id'       => $rmId,
                'base_code'   => $c['base_code'],
                'description' => (string) ($c['supplier_product_name'] ?? $c['base_code']),
            ];

            if (!isset($reviewedRms[$rmId])) {
                $blocked[] = $entry;
                continue;
            }

            // Rule 2 freshness check.
            $maxTs   = $rmMaxUpstream[$rmId] ?? null;
            $lastPub = $lastPublished[$rmId] ?? null;
            if ($lastPub !== null && $maxTs !== null && $lastPub >= $maxTs) {
                continue; // SDS is already up to date — skip, not blocked.
            }

            $eligible[] = $entry;
        }

        return ['eligible' => $eligible, 'blocked' => $blocked];
    }

    /**
     * POST /admin/bulk-publish/start — Begin bulk publish with parallel workers.
     *
     * Work is split by (finished-good, language) pairs so that each PDF can
     * be generated in its own worker, maximising parallelism.
     */
    public function start(): void
    {
        if (!can_edit('bulk_publish')) {
            $this->jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }
        CSRF::validateRequest();

        $db = Database::getInstance();

        // Compute eligible FGs with full recursion through FG sub-components.
        // See computeEligibleFinishedGoods() for the full rule set.
        $eligibility = self::computeEligibleFinishedGoods($db);
        $finishedGoods = $eligibility['eligible'];

        // Resale items: purchased RMs sold as-is. Same review rule as FGs,
        // but the work items point at an RM id with type='resale' so the
        // worker knows to take the resale composition path.
        $resaleEligibility = self::computeEligibleResaleItems($db);
        $resaleItems       = $resaleEligibility['eligible'];

        if (empty($finishedGoods) && empty($resaleItems)) {
            $this->jsonResponse(['error' => 'No eligible finished goods or resale items to publish. Every raw material in the formula (through all FG sub-components) must have been reviewed by a user, or have an active CAS determination on one of its constituents. If a finished good or resale item already has an up-to-date SDS, it is also skipped.']);
            return;
        }

        // Create progress directory
        $basePath    = App::basePath();
        $progressDir = $basePath . self::PROGRESS_DIR;
        if (!is_dir($progressDir)) {
            mkdir($progressDir, 0755, true);
        }

        $languages = App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);

        // Build (FG, language) work items with pre-computed version numbers
        // so concurrent workers don't race on version selection.
        // Also include alias work items for each finished good.
        $workItems = [];
        foreach ($finishedGoods as $fg) {
            // Add alias work items for this finished good (deduplicated by base customer code)
            $aliases = self::deduplicateAliasesByBaseCode($db->fetchAll(
                "SELECT id, customer_code, description FROM aliases WHERE internal_code_base = ? ORDER BY customer_code",
                [$fg['product_code']]
            ));

            // Always generate an SDS under the internal FG code
            $lastVersion = $db->fetch(
                "SELECT MAX(version) AS max_ver FROM sds_versions WHERE finished_good_id = ? AND alias_id IS NULL",
                [$fg['id']]
            );
            $nextVersion = ((int) ($lastVersion['max_ver'] ?? 0)) + 1;

            foreach ($languages as $lang) {
                $workItems[] = [
                    'id'           => $fg['id'],
                    'product_code' => $fg['product_code'],
                    'language'     => $lang,
                    'version'      => $nextVersion,
                ];
            }

            // Also generate SDSs under each alias code
            foreach ($aliases as $alias) {
                $aliasLastVersion = $db->fetch(
                    "SELECT MAX(version) AS max_ver FROM sds_versions WHERE alias_id = ?",
                    [(int) $alias['id']]
                );
                $aliasNextVersion = ((int) ($aliasLastVersion['max_ver'] ?? 0)) + 1;

                foreach ($languages as $lang) {
                    $workItems[] = [
                        'id'                => $fg['id'],
                        'product_code'      => $fg['product_code'],
                        'language'          => $lang,
                        'version'           => $aliasNextVersion,
                        'alias_id'          => (int) $alias['id'],
                        'alias_code'        => $alias['customer_code'],
                        'alias_description' => $alias['description'],
                    ];
                }
            }
        }

        // Resale items: each eligible RM gets a base SDS under its own
        // code plus one alias-branded variant per alias. Same alias-
        // deduplication as the FG path so pack variants share one SDS.
        foreach ($resaleItems as $resale) {
            $rmId     = (int) $resale['rm_id'];
            $baseCode = (string) $resale['base_code'];

            $lastVersion = $db->fetch(
                "SELECT MAX(version) AS max_ver FROM sds_versions
                 WHERE raw_material_id = ? AND alias_id IS NULL",
                [$rmId]
            );
            $nextVersion = ((int) ($lastVersion['max_ver'] ?? 0)) + 1;

            foreach ($languages as $lang) {
                $workItems[] = [
                    'type'         => 'resale',
                    'rm_id'        => $rmId,
                    'product_code' => $baseCode,
                    'language'     => $lang,
                    'version'      => $nextVersion,
                ];
            }

            $resaleAliases = self::deduplicateAliasesByBaseCode($db->fetchAll(
                "SELECT id, customer_code, description FROM aliases
                 WHERE internal_code_base = ?
                 ORDER BY customer_code",
                [$baseCode]
            ));

            foreach ($resaleAliases as $alias) {
                $aliasLastVersion = $db->fetch(
                    "SELECT MAX(version) AS max_ver FROM sds_versions WHERE alias_id = ?",
                    [(int) $alias['id']]
                );
                $aliasNextVersion = ((int) ($aliasLastVersion['max_ver'] ?? 0)) + 1;

                foreach ($languages as $lang) {
                    $workItems[] = [
                        'type'              => 'resale',
                        'rm_id'             => $rmId,
                        'product_code'      => $baseCode,
                        'language'          => $lang,
                        'version'           => $aliasNextVersion,
                        'alias_id'          => (int) $alias['id'],
                        'alias_code'        => $alias['customer_code'],
                        'alias_description' => $alias['description'],
                    ];
                }
            }
        }

        $token       = bin2hex(random_bytes(8));
        $totalItems  = count($workItems);
        $workerCount = self::getWorkerCount($totalItems);
        $userId      = current_user_id();

        // Write the master progress file (used by progress endpoint)
        $masterFile = $progressDir . '/publish_progress_' . $token . '.json';
        file_put_contents($masterFile, json_encode([
            'current' => 0, 'total' => $totalItems, 'percent' => 0,
            'message' => 'Starting bulk publish with ' . $workerCount . ' workers...',
            'complete' => false, 'error' => false, 'workers' => $workerCount,
        ]), LOCK_EX);

        // Split work items into batches and write batch files
        $batches = array_chunk($workItems, (int) ceil($totalItems / $workerCount));
        $workerProgressFiles = [];
        $batchFiles = [];

        for ($w = 0; $w < count($batches); $w++) {
            $batchFile    = $progressDir . '/publish_batch_' . $token . '_' . $w . '.json';
            $workerFile   = $progressDir . '/publish_worker_' . $token . '_' . $w . '.json';

            file_put_contents($batchFile, json_encode($batches[$w]));
            file_put_contents($workerFile, json_encode([
                'total' => count($batches[$w]), 'processed' => 0,
                'published' => 0, 'failed' => 0, 'errors' => [], 'complete' => false,
            ]), LOCK_EX);

            $batchFiles[] = $batchFile;
            $workerProgressFiles[] = $workerFile;
        }

        // Write manifest so progress endpoint knows the worker + log files
        $manifestFile = $progressDir . '/publish_manifest_' . $token . '.json';
        $logDir       = $basePath . '/storage/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $logFiles = [];
        for ($w = 0; $w < count($batches); $w++) {
            $logFiles[] = $logDir . '/publish_worker_' . $token . '_' . $w . '.log';
        }
        file_put_contents($manifestFile, json_encode([
            'total'          => $totalItems,
            'worker_files'   => $workerProgressFiles,
            'log_files'      => $logFiles,
            'master_file'    => $masterFile,
        ]), LOCK_EX);

        // Flush JSON response to browser
        $this->jsonResponse([
            'token' => $token,
            'total' => $totalItems,
        ]);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // Spawn parallel worker processes (stderr → log file for debugging)
        $workerScript = $basePath . '/scripts/publish-worker.php';
        $phpBin       = php_cli_binary();

        for ($w = 0; $w < count($batches); $w++) {
            $cmd = sprintf(
                '%s %s %s %s %s > %s 2>&1 &',
                escapeshellarg($phpBin),
                escapeshellarg($workerScript),
                escapeshellarg($batchFiles[$w]),
                escapeshellarg($workerProgressFiles[$w]),
                escapeshellarg((string) $userId),
                escapeshellarg($logFiles[$w])
            );
            exec($cmd);
        }
    }

    /**
     * POST /admin/bulk-publish/stop/{token} — signal all in-flight workers
     * to stop after their current item. Writes a stop-flag file that every
     * worker polls at the top of each iteration; on observing it the
     * worker writes its final progress and exits cleanly.
     *
     * Already-completed items stay — this is a soft cancel, not a rollback.
     */
    public function stop(string $token): void
    {
        if (!can_edit('bulk_publish')) {
            $this->jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }
        CSRF::validateRequest();

        $token = preg_replace('/[^a-f0-9]/', '', $token);
        if ($token === '') {
            $this->jsonResponse(['error' => 'Invalid token.'], 400);
            return;
        }

        $flagPath = App::basePath() . self::PROGRESS_DIR . '/publish_stop_' . $token . '.flag';
        if (!is_dir(dirname($flagPath))) {
            @mkdir(dirname($flagPath), 0755, true);
        }
        @file_put_contents($flagPath, date('c') . ' stop requested by user ' . (int) current_user_id() . "\n");

        AuditService::log('sds_bulk_publish', $token, 'stop_requested', [
            'user_id' => current_user_id(),
        ]);

        $this->jsonResponse(['stopped' => true]);
    }

    /** Seconds without progress before we declare workers crashed. */
    private const STALL_TIMEOUT = 60;

    /**
     * GET /admin/bulk-publish/progress/{token} — Aggregate worker progress.
     */
    public function progress(string $token): void
    {
        if (!can_edit('bulk_publish')) {
            $this->jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $token       = preg_replace('/[^a-f0-9]/', '', $token);
        $progressDir = App::basePath() . self::PROGRESS_DIR;
        $manifestFile = $progressDir . '/publish_manifest_' . $token . '.json';

        if (!file_exists($manifestFile)) {
            // Fall back to master file for initial poll before manifest is written
            $masterFile = $progressDir . '/publish_progress_' . $token . '.json';
            if (file_exists($masterFile)) {
                $data = json_decode(file_get_contents($masterFile), true);
                $this->jsonResponse($data ?: ['error' => 'Invalid progress data.']);
                return;
            }
            $this->jsonResponse(['error' => 'Progress not found.'], 404);
            return;
        }

        $manifest = json_decode(file_get_contents($manifestFile), true);
        if (!$manifest) {
            $this->jsonResponse(['error' => 'Invalid manifest.']);
            return;
        }

        $totalItems     = (int) $manifest['total'];
        $totalProcessed = 0;
        $totalPublished = 0;
        $totalFailed    = 0;
        $allErrors      = [];
        $allComplete    = true;
        $workerFiles    = $manifest['worker_files'] ?? [];
        $logFiles       = $manifest['log_files'] ?? [];
        $stalledWorkers = 0;
        $now            = time();

        foreach ($workerFiles as $idx => $workerFile) {
            if (!file_exists($workerFile)) {
                $allComplete = false;
                // Worker never wrote progress — check if its log exists (crashed on startup)
                if (isset($logFiles[$idx]) && file_exists($logFiles[$idx])
                    && filesize($logFiles[$idx]) > 0
                    && $now - filemtime($logFiles[$idx]) > self::STALL_TIMEOUT) {
                    $stalledWorkers++;
                }
                continue;
            }
            $wData = json_decode(file_get_contents($workerFile), true);
            if (!$wData) {
                $allComplete = false;
                continue;
            }

            $totalProcessed += (int) ($wData['processed'] ?? 0);
            $totalPublished += (int) ($wData['published'] ?? 0);
            $totalFailed    += (int) ($wData['failed'] ?? 0);
            $allErrors       = array_merge($allErrors, $wData['errors'] ?? []);

            if (empty($wData['complete'])) {
                $allComplete = false;
                // Detect stalled worker: progress file not updated recently
                if ($now - filemtime($workerFile) > self::STALL_TIMEOUT) {
                    $stalledWorkers++;
                }
            }
        }

        $percent = $totalItems > 0 ? round(($totalProcessed / $totalItems) * 100, 1) : 0;

        // Workers crashed — treat as finished with errors
        $crashed = !$allComplete && $stalledWorkers > 0
            && $stalledWorkers >= count(array_filter($workerFiles, function ($f) {
                if (!file_exists($f)) return true;
                $d = json_decode(file_get_contents($f), true);
                return !$d || empty($d['complete']);
            }));

        if ($allComplete || $crashed) {
            // Collect worker log output for display
            $workerLogs = self::collectWorkerLogs($logFiles);

            $summary = $totalPublished . ' PDFs published';
            if ($totalFailed > 0) {
                $summary .= ', ' . $totalFailed . ' failed';
            }
            if ($crashed) {
                $summary .= ' (' . $stalledWorkers . ' worker(s) crashed)';
            }

            $response = [
                'current'     => $allComplete ? $totalItems : $totalProcessed,
                'total'       => $totalItems,
                'percent'     => $allComplete ? 100 : $percent,
                'message'     => ($crashed ? 'Bulk publish stopped — ' : 'Bulk publish complete! ') . $summary . '.',
                'complete'    => true,
                'error'       => $crashed,
                'published'   => $totalPublished,
                'failed'      => $totalFailed,
                'errors'      => $allErrors,
                'worker_logs' => $workerLogs,
            ];

            // Clean up progress/manifest/stop-flag files (keep logs when
            // there were problems so operators can diagnose).
            foreach ($workerFiles as $wf) {
                @unlink($wf);
            }
            @unlink($manifestFile);
            @unlink($manifest['master_file'] ?? '');
            @unlink(App::basePath() . self::PROGRESS_DIR . '/publish_stop_' . $token . '.flag');

            // Clean up log files only if everything succeeded
            if (!$crashed && $totalFailed === 0) {
                foreach ($logFiles as $lf) {
                    @unlink($lf);
                }
            }

            AuditService::log('sds_bulk_publish', '0', $crashed ? 'crashed' : 'complete', [
                'published' => $totalPublished,
                'failed'    => $totalFailed,
                'crashed'   => $stalledWorkers,
            ]);

            $this->jsonResponse($response);
            return;
        }

        $this->jsonResponse([
            'current'  => $totalProcessed,
            'total'    => $totalItems,
            'percent'  => $percent,
            'message'  => 'Publishing... ' . $totalProcessed . '/' . $totalItems
                . ' (' . count($workerFiles) . ' workers)',
            'complete' => false,
            'error'    => false,
        ]);
    }

    /**
     * Read non-empty worker log files and return their contents keyed by worker index.
     */
    private static function collectWorkerLogs(array $logFiles): array
    {
        $logs = [];
        foreach ($logFiles as $idx => $logFile) {
            if (!file_exists($logFile)) {
                continue;
            }
            $content = file_get_contents($logFile);
            if ($content !== false && trim($content) !== '') {
                // Cap per-worker log at 4 KB to keep the JSON response reasonable
                if (strlen($content) > 4096) {
                    $content = '...(truncated)...' . "\n" . substr($content, -4096);
                }
                $logs['Worker ' . $idx] = $content;
            }
        }
        return $logs;
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    /**
     * Strip the pack extension from a code (everything after the first "-").
     */
    private static function stripPackExtension(string $code): string
    {
        $pos = strpos($code, '-');
        return $pos !== false ? substr($code, 0, $pos) : $code;
    }

    /**
     * Deduplicate alias rows by base customer code (pack extension stripped).
     *
     * Returns one row per unique base code, using the first occurrence's id
     * and description, with customer_code set to the base (no pack extension).
     */
    private static function deduplicateAliasesByBaseCode(array $rows): array
    {
        $seen = [];
        $result = [];

        foreach ($rows as $row) {
            $baseCode = self::stripPackExtension($row['customer_code']);
            if (isset($seen[$baseCode])) {
                continue;
            }
            $seen[$baseCode] = true;
            $row['customer_code'] = $baseCode;
            $result[] = $row;
        }

        return $result;
    }

    private function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}
