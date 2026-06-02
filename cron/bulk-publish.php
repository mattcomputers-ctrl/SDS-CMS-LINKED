#!/usr/bin/env php
<?php
/**
 * Bulk SDS Publish — runnable standalone, or required from cms-sync.php,
 * or spawned in the background by BulkPublishController::start.
 *
 * Serialized via BulkPublishQueue (MySQL GET_LOCK + bulk_publish_jobs
 * table). At every trigger:
 *
 *   1. Enqueue a `pending` job (coalesced — if a pending row already
 *      exists we reuse it; two pending rows would do identical work
 *      because eligibility is recomputed at run time).
 *   2. Try to acquire the runner lock non-blocking.
 *   3. Lock held elsewhere → our pending row stays queued for the
 *      active runner; this process exits cleanly.
 *   4. Lock acquired → drain pending jobs one at a time. For each:
 *      claim (UPDATE to running), run the eligibility → workers →
 *      poll cycle, mark completed/failed with stats. RELEASE_LOCK on
 *      exit.
 *
 * The `cms_sync.auto_bulk_publish` admin toggle gates the CRON-driven
 * path (when this script is `require`d from cms-sync.php) but does
 * NOT affect standalone invocations — so the admin button always
 * runs regardless of that setting.
 *
 * Usage (standalone):
 *   php cron/bulk-publish.php [--triggered-by=cron|manual|cli] [--user-id=N]
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
use SDS\Services\BulkPublishQueue;
use SDS\Services\SDSAutoSendService;

/**
 * Count publish-worker.php processes matching this run's token, so
 * the poll loop can detect "workers died mid-batch" (via external
 * pkill or crash) and stop waiting for a completion that will never
 * arrive. Returns 0 if pgrep finds nothing.
 */
function bp_countWorkerProcesses(string $token): int
{
    $out = @shell_exec("pgrep -cf " . escapeshellarg('publish-worker\.php.*' . $token) . " 2>/dev/null");
    return is_string($out) ? (int) trim($out) : 0;
}

/**
 * Execute a single bulk publish cycle: compute eligibility, fan work
 * items out to parallel workers, poll until complete, clean up. All
 * progress goes to stdout so cms-sync.log captures it. Live stats
 * are written to bulk_publish_jobs on each polling cycle so the
 * admin queue view can show real-time updates.
 */
function bp_runOne(Database $db, string $basePath, int $jobId): array
{
    $start = microtime(true);

    echo "\n[" . date('Y-m-d H:i:s') . "] Bulk SDS Publish starting (job #{$jobId})...\n";

    // ── Eligibility ──────────────────────────────────────────────
    $eligibility       = BulkPublishController::computeEligibleFinishedGoods($db);
    $resaleEligibility = BulkPublishController::computeEligibleResaleItems($db);

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
    BulkPublishQueue::updateProgress($jobId, $stats);

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
    BulkPublishQueue::updateProgress($jobId, $stats);

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

        // setsid + </dev/null so workers survive parent termination
        // (FPM child reap would otherwise SIGHUP them before logs flush).
        $cmd = sprintf(
            'setsid %s %s %s %s %s < /dev/null > %s 2>&1 &',
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
    // Deadness check: if every publish-worker process has exited and
    // some progress files still say complete=false, the workers died
    // mid-batch (most commonly from an external SIGTERM/SIGKILL via
    // pkill, or a segfault) and no one will ever mark them complete.
    // We also guard against workers that are alive but hung (no
    // progress advancing for a long time) so the runner can't spin
    // forever holding the queue's GET_LOCK.
    $lastReportAt       = 0;
    $totalPublished     = 0;
    $totalFailed        = 0;
    $errors             = [];
    $lastProgressKey    = '';
    $lastProgressChange = time();
    $stallTimeoutSec    = 180; // 3 min of zero progress with no live workers → give up

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

        // Live push to the job row so the admin queue view + progress
        // bar can tail without needing progress files.
        BulkPublishQueue::updateProgress($jobId, [
            'published_count' => $totalPublished,
            'failed_count'    => $totalFailed,
        ]);

        if ($allComplete) { break; }

        // Track whether we've seen any forward motion.
        $progressKey = "{$totalProcessed}:{$totalPublished}:{$totalFailed}";
        if ($progressKey !== $lastProgressKey) {
            $lastProgressKey    = $progressKey;
            $lastProgressChange = time();
        }

        // If no worker processes are alive AND counts aren't moving,
        // workers died mid-batch (pkill or crash). Throw so the caller
        // marks this job failed — partial published/failed counts
        // already went to the job row via updateProgress above, so
        // the audit is preserved.
        $workersAlive = bp_countWorkerProcesses($token);
        $stalledFor   = time() - $lastProgressChange;
        if ($workersAlive === 0 && $stalledFor >= 10) {
            echo "  [" . date('H:i:s') . "] WARNING: no publish-worker processes alive "
                . "(progress: {$totalProcessed}/{$totalTotal}) — aborting poll loop.\n";
            foreach (array_merge($batchFiles, $progressFiles) as $file) { @unlink($file); }
            throw new \RuntimeException(sprintf(
                'Workers died before marking complete: %d/%d processed (published=%d, failed=%d). '
                . 'Likely cause: external pkill or segfault. Remaining work will be picked up '
                . 'on the next bulk publish trigger.',
                $totalProcessed, $totalTotal, $totalPublished, $totalFailed
            ));
        }
        if ($stalledFor >= $stallTimeoutSec) {
            echo "  [" . date('H:i:s') . "] WARNING: no progress for {$stalledFor}s "
                . "(workers alive: {$workersAlive}) — aborting poll loop.\n";
            foreach (array_merge($batchFiles, $progressFiles) as $file) { @unlink($file); }
            throw new \RuntimeException(sprintf(
                'Stalled: no progress for %ds with %d workers alive. Remaining work will be '
                . 'picked up on the next bulk publish trigger.',
                $stalledFor, $workersAlive
            ));
        }

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

/**
 * Post-job steps that must run AFTER every bulk publish job, so
 * customer notifications reflect the latest published SDSs:
 *
 *   - SDSAutoSendService::processNewShipments(null)
 *     Picks up shipments past the persisted auto_send.last_run_at
 *     watermark. Idempotent via sds_send_log, so running it after
 *     every job (and redundantly from cms-sync.php) is safe.
 *
 * Failures here MUST NOT propagate — the publish already succeeded
 * and the job is marked completed by the caller. Email-sending errors
 * are logged to stdout (captured in cms-sync.log) for diagnosis.
 */
function bp_runPostJobSteps(Database $db): void
{
    try {
        $autoSend = new SDSAutoSendService();
        $r = $autoSend->processNewShipments(null);
        $sent    = (int) ($r['emails_sent'] ?? 0);
        $queued  = (int) ($r['queued']      ?? 0);
        $skipped = (int) ($r['skipped']     ?? 0);
        $errors  = (int) count($r['errors'] ?? []);
        $errTail = $errors > 0 ? ", {$errors} errors" : '';
        echo "  Auto-send: {$sent} sent, {$queued} queued, {$skipped} skipped{$errTail}\n";
    } catch (\Throwable $e) {
        echo "  Auto-send failed (non-fatal): " . $e->getMessage() . "\n";
    }
}

// ═════════════════════════════════════════════════════════════════════
//  Top-level dispatch
// ═════════════════════════════════════════════════════════════════════

$bp_db       = Database::getInstance();
$bp_basePath = App::basePath();

// Blackout window check
require_once __DIR__ . '/cron-helpers.php';
if (cron_in_blackout($bp_db)) {
    echo "[" . date('Y-m-d H:i:s') . "] In blackout window — skipping bulk publish.\n";
    return;
}

// Detect whether we're being run as a top-level script vs required
// from another script (cms-sync.php). Only the cms-sync path respects
// the `cms_sync.auto_bulk_publish` toggle — standalone / spawned
// invocations always run, so the admin button works even when the
// cms-sync auto-publish is disabled.
$bp_scriptName = basename($_SERVER['SCRIPT_FILENAME'] ?? ($_SERVER['SCRIPT_NAME'] ?? ''));
$bp_requiredFromCmsSync = ($bp_scriptName !== '' && $bp_scriptName !== 'bulk-publish.php');

if ($bp_requiredFromCmsSync) {
    $bp_row = $bp_db->fetch("SELECT `value` FROM settings WHERE `key` = 'cms_sync.auto_bulk_publish'");
    $bp_autoEnabled = $bp_row === null || ((string) $bp_row['value']) !== '0';
    if (!$bp_autoEnabled) {
        echo "[" . date('Y-m-d H:i:s') . "] Auto bulk publish disabled via admin settings — skipping.\n";
        return;
    }
}

// Parse optional --triggered-by / --user-id flags (used by the admin
// button's background spawn). Defaults match cron invocation.
$bp_triggeredBy       = 'cron';
$bp_triggeredByUserId = null;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--triggered-by=(.+)$/', $arg, $m)) {
        $bp_triggeredBy = $m[1];
    } elseif (preg_match('/^--user-id=(\d+)$/', $arg, $m)) {
        $bp_triggeredByUserId = (int) $m[1];
    }
}

// 1. Enqueue (coalesced).
$bp_jobId = BulkPublishQueue::enqueue($bp_triggeredBy, $bp_triggeredByUserId);

// 2. Try to acquire the runner lock (non-blocking).
if (!BulkPublishQueue::acquireLock()) {
    echo "[" . date('Y-m-d H:i:s') . "] Another bulk publish runner is active — job #{$bp_jobId} queued; the active runner will pick it up.\n";
    return;
}

// 3. Drain all pending jobs.
try {
    BulkPublishQueue::reapOrphanedRunning();

    while (($jobId = BulkPublishQueue::claimNextPending()) !== null) {
        try {
            $stats = bp_runOne($bp_db, $bp_basePath, $jobId);
            BulkPublishQueue::markCompleted($jobId, $stats);
        } catch (\Throwable $e) {
            BulkPublishQueue::markFailed($jobId, $e->getMessage());
            echo "  Job #{$jobId} failed: " . $e->getMessage() . "\n";
            // Keep draining — a single failure shouldn't block the queue.
        }

        // Post-job steps run regardless of publish success/failure — a
        // failed publish job might still have some items that need
        // customer notifications (e.g. earlier successful jobs), and
        // processNewShipments is idempotent via sds_send_log.
        bp_runPostJobSteps($bp_db);
    }
} finally {
    BulkPublishQueue::releaseLock();
}
