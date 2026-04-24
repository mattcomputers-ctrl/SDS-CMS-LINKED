#!/usr/bin/env php
<?php
/**
 * Bulk SDS Publish — runnable standalone, or required from cms-sync.php.
 *
 * Serialized via a MySQL named lock + a `bulk_publish_jobs` queue
 * table (migration 044). Flow at every trigger:
 *
 *   1. Enqueue a `pending` job row (coalesced — if a pending row
 *      already exists we skip the insert, since bulk publish
 *      recomputes eligibility at run time so two pending rows would
 *      do identical work).
 *   2. Try `GET_LOCK('bulk_publish_runner', 0)` (non-blocking).
 *   3. If the lock is already held: another runner is actively
 *      draining the queue — leave our pending row for it and return.
 *   4. If we acquired the lock: drain the queue one job at a time
 *      (claim oldest pending → run → mark completed/failed) until
 *      no pending rows remain. Release the lock on exit.
 *
 * This makes double-runs impossible: a concurrent trigger (cron
 * overlapping a long run, manual admin click during cron, etc.)
 * adds a pending row if needed and the current runner picks it up
 * when it finishes the current job.
 *
 * Uses the same eligibility rules as the admin button
 * (BulkPublishController::computeEligibleFinishedGoods +
 * computeEligibleResaleItems), and fans work out to the existing
 * parallel workers (scripts/publish-worker.php). This script waits
 * for each job's workers to finish before starting the next job,
 * so downstream steps in cms-sync.php (customer auto-send) still
 * see the new SDSs when we return synchronously.
 *
 * Usage (standalone):
 *   php cron/bulk-publish.php
 *
 * Usage (required from another cron script):
 *   require __DIR__ . '/bulk-publish.php';
 *
 * Exit codes (standalone mode):
 *   0 = ran to completion (or deferred to active runner)
 *   1 = fatal error (no DB, etc.)
 */

declare(strict_types=1);

// Allow require from another script that's already bootstrapped the app.
if (!class_exists(\SDS\Core\App::class, false)) {
    require_once __DIR__ . '/../vendor/autoload.php';
    new \SDS\Core\App();
}

use SDS\Core\App;
use SDS\Core\Database;
use SDS\Controllers\BulkPublishController;

/**
 * Coalescing enqueue: insert a pending bulk_publish_jobs row only if
 * no pending row already exists. Returns the job id (either the new
 * one or the existing pending one we deferred to).
 */
function bp_enqueue(Database $db, string $triggeredBy, ?int $userId): int
{
    $existing = $db->fetch(
        "SELECT id FROM bulk_publish_jobs WHERE status = 'pending' ORDER BY queued_at ASC LIMIT 1"
    );
    if ($existing) {
        return (int) $existing['id'];
    }
    return (int) $db->insert('bulk_publish_jobs', [
        'status'               => 'pending',
        'triggered_by'         => $triggeredBy,
        'triggered_by_user_id' => $userId,
    ]);
}

/**
 * Claim the oldest pending job: mark it running + stamp started_at.
 * Returns the job id, or null if no pending jobs remain.
 */
function bp_claimNextPending(Database $db): ?int
{
    $row = $db->fetch(
        "SELECT id FROM bulk_publish_jobs WHERE status = 'pending' ORDER BY queued_at ASC LIMIT 1"
    );
    if (!$row) {
        return null;
    }
    $jobId = (int) $row['id'];
    $db->update('bulk_publish_jobs', [
        'status'     => 'running',
        'started_at' => gmdate('Y-m-d H:i:s'),
    ], 'id = ?', [$jobId]);
    return $jobId;
}

function bp_markCompleted(Database $db, int $jobId, array $stats): void
{
    $db->update('bulk_publish_jobs', array_merge($stats, [
        'status'       => 'completed',
        'completed_at' => gmdate('Y-m-d H:i:s'),
    ]), 'id = ?', [$jobId]);
}

function bp_markFailed(Database $db, int $jobId, string $error): void
{
    $db->update('bulk_publish_jobs', [
        'status'        => 'failed',
        'completed_at'  => gmdate('Y-m-d H:i:s'),
        'error_message' => mb_strimwidth($error, 0, 4000, '...'),
    ], 'id = ?', [$jobId]);
}

/**
 * Execute a single bulk publish cycle: compute eligibility, fan work
 * items out to parallel workers, poll until complete, clean up. All
 * progress goes to stdout so cms-sync.log captures it identically to
 * the pre-queue behaviour. Returns a stats array suitable for
 * bp_markCompleted().
 */
function bp_runOne(Database $db, string $basePath, int $jobId): array
{
    $start = microtime(true);

    echo "\n[" . date('Y-m-d H:i:s') . "] Bulk SDS Publish starting (job #{$jobId})...\n";

    // ── Eligibility ──────────────────────────────────────────────
    $eligibility        = BulkPublishController::computeEligibleFinishedGoods($db);
    $resaleEligibility  = BulkPublishController::computeEligibleResaleItems($db);

    $fgs    = $eligibility['eligible'];
    $resale = $resaleEligibility['eligible'];

    echo "  Eligible finished goods: " . count($fgs) . " (blocked: " . count($eligibility['blocked']) . ")\n";
    echo "  Eligible resale items:   " . count($resale) . " (blocked: " . count($resaleEligibility['blocked']) . ")\n";

    $stats = [
        'eligible_fg_count'     => count($fgs),
        'eligible_resale_count' => count($resale),
        'work_items_count'      => 0,
        'published_count'       => 0,
        'failed_count'          => 0,
    ];

    if (empty($fgs) && empty($resale)) {
        echo "  Nothing to publish.\n";
        return $stats;
    }

    // ── Work items ───────────────────────────────────────────────
    $languages = App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);
    $workItems = BulkPublishController::buildWorkItems($db, $fgs, $resale, $languages);

    $totalItems  = count($workItems);
    $workerCount = BulkPublishController::getWorkerCount($totalItems);
    $stats['work_items_count'] = $totalItems;

    echo "  Work items:       {$totalItems} (" . count($languages) . " languages × "
        . (count($fgs) + count($resale)) . " items + alias variants)\n";
    echo "  Worker processes: {$workerCount}\n";

    // ── Spawn workers ────────────────────────────────────────────
    $progressDir = $basePath . '/storage/exports';
    if (!is_dir($progressDir)) { mkdir($progressDir, 0755, true); }
    $logDir = $basePath . '/storage/logs';
    if (!is_dir($logDir)) { mkdir($logDir, 0755, true); }

    $token   = bin2hex(random_bytes(8));
    $batches = array_chunk($workItems, (int) ceil($totalItems / $workerCount));

    $progressFiles = [];
    $batchFiles    = [];

    foreach ($batches as $i => $batch) {
        $batchFile    = $progressDir . "/publish_batch_{$token}_{$i}.json";
        $progressFile = $progressDir . "/publish_worker_{$token}_{$i}.json";
        $logFile      = $logDir      . "/publish_worker_{$token}_{$i}.log";

        file_put_contents($batchFile, json_encode($batch));
        file_put_contents($progressFile, json_encode([
            'total'     => count($batch),
            'processed' => 0,
            'published' => 0,
            'failed'    => 0,
            'errors'    => [],
            'complete'  => false,
        ]), LOCK_EX);

        $batchFiles[]    = $batchFile;
        $progressFiles[] = $progressFile;

        $cmd = sprintf(
            '%s %s %s %s %s > %s 2>&1 &',
            escapeshellarg(php_cli_binary()),
            escapeshellarg($basePath . '/scripts/publish-worker.php'),
            escapeshellarg($batchFile),
            escapeshellarg($progressFile),
            escapeshellarg('0'),
            escapeshellarg($logFile)
        );
        exec($cmd);
    }

    // ── Poll progress ────────────────────────────────────────────
    $lastReportAt   = 0;
    $totalPublished = 0;
    $totalFailed    = 0;
    $errors         = [];

    while (true) {
        $allComplete    = true;
        $totalPublished = 0;
        $totalFailed    = 0;
        $totalProcessed = 0;
        $totalTotal     = 0;
        $errors         = [];

        foreach ($progressFiles as $pf) {
            $content = @file_get_contents($pf);
            if ($content === false) { $allComplete = false; continue; }
            $progress = json_decode($content, true);
            if (!is_array($progress)) { $allComplete = false; continue; }
            $totalPublished += (int) ($progress['published'] ?? 0);
            $totalFailed    += (int) ($progress['failed']    ?? 0);
            $totalProcessed += (int) ($progress['processed'] ?? 0);
            $totalTotal     += (int) ($progress['total']     ?? 0);
            if (!empty($progress['errors'])) {
                $errors = array_merge($errors, $progress['errors']);
            }
            if (empty($progress['complete'])) { $allComplete = false; }
        }

        if ($allComplete) { break; }

        if (time() - $lastReportAt >= 30) {
            echo "  [" . date('H:i:s') . "] Progress: {$totalProcessed}/{$totalTotal} "
                . "({$totalPublished} published, {$totalFailed} failed)\n";
            $lastReportAt = time();
        }
        sleep(2);
    }

    // ── Summary + cleanup ────────────────────────────────────────
    $elapsed = round(microtime(true) - $start, 1);
    echo "  Job #{$jobId} completed in {$elapsed}s: {$totalPublished} published, {$totalFailed} failed.\n";

    if (!empty($errors)) {
        $shown = 10;
        echo "  Errors (" . count($errors) . "):\n";
        foreach (array_slice($errors, 0, $shown) as $err) {
            echo "    - {$err}\n";
        }
        if (count($errors) > $shown) {
            echo "    ... and " . (count($errors) - $shown) . " more (see worker log files in storage/logs).\n";
        }
    }

    foreach (array_merge($batchFiles, $progressFiles) as $file) {
        @unlink($file);
    }

    $stats['published_count'] = $totalPublished;
    $stats['failed_count']    = $totalFailed;
    return $stats;
}

// ═════════════════════════════════════════════════════════════════════
//  Top-level dispatch
// ═════════════════════════════════════════════════════════════════════

$bp_db       = Database::getInstance();
$bp_basePath = App::basePath();

// Respect the admin toggle — off means "skip this cron-driven publish
// entirely, but still allow the button-click flow on the admin page."
$bp_row = $bp_db->fetch("SELECT `value` FROM settings WHERE `key` = 'cms_sync.auto_bulk_publish'");
$bp_autoEnabled = $bp_row === null || ((string) $bp_row['value']) !== '0';
if (!$bp_autoEnabled) {
    echo "[" . date('Y-m-d H:i:s') . "] Auto bulk publish disabled via admin settings — skipping.\n";
    return;
}

// 1. Enqueue (coalesced).
$bp_jobId = bp_enqueue($bp_db, 'cron', null);

// 2. Try to acquire the runner lock (non-blocking).
$bp_lockRow  = $bp_db->fetch("SELECT GET_LOCK('bulk_publish_runner', 0) AS g");
$bp_gotLock  = $bp_lockRow !== null && (int) ($bp_lockRow['g'] ?? 0) === 1;

if (!$bp_gotLock) {
    echo "[" . date('Y-m-d H:i:s') . "] Another bulk publish runner is active — job #{$bp_jobId} queued; the active runner will pick it up.\n";
    return;
}

// 3. Drain all pending jobs.
try {
    // If a previous runner crashed between claim and mark-done, its
    // 'running' rows would sit there forever and never be retried.
    // Because we just acquired the exclusive runner lock, any row
    // still in 'running' at this point is necessarily orphaned —
    // mark it failed so it's visible in the job history. Freshly
    // enqueued pending rows will still run normally below.
    $bp_db->query(
        "UPDATE bulk_publish_jobs
            SET status = 'failed',
                completed_at = UTC_TIMESTAMP(),
                error_message = CONCAT(
                    'Orphaned: previous runner crashed (auto-failed at ', UTC_TIMESTAMP(), ')'
                )
          WHERE status = 'running'"
    );

    while (($jobId = bp_claimNextPending($bp_db)) !== null) {
        try {
            $stats = bp_runOne($bp_db, $bp_basePath, $jobId);
            bp_markCompleted($bp_db, $jobId, $stats);
        } catch (\Throwable $e) {
            bp_markFailed($bp_db, $jobId, $e->getMessage());
            echo "  Job #{$jobId} failed: " . $e->getMessage() . "\n";
            // Keep draining — a single failure shouldn't block the queue.
        }
    }
} finally {
    $bp_db->query("SELECT RELEASE_LOCK('bulk_publish_runner')");
}
