#!/usr/bin/env php
<?php
/**
 * Bulk SDS Publish — runnable standalone, or required from cms-sync.php.
 *
 * Uses the same eligibility rules as the Bulk SDS Publish admin page
 * (BulkPublishController::computeEligibleFinishedGoods + computeEligibleResaleItems)
 * so the cron run and the button-click run always agree on what to
 * publish. Work items are fed to the existing parallel worker
 * (scripts/publish-worker.php); this script waits for every worker
 * to finish before returning, so downstream steps in the CMS sync
 * cron (customer auto-send) see the new SDSs.
 *
 * Usage (standalone):
 *   php cron/bulk-publish.php
 *
 * Usage (required from another cron script):
 *   require __DIR__ . '/bulk-publish.php';
 *
 * Exit codes (standalone mode):
 *   0 = ran to completion, even if some items failed
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

$bp_start   = microtime(true);
$bp_basePath = App::basePath();
$bp_db      = Database::getInstance();

// Respect the admin toggle — off means "skip this cron-driven publish
// entirely, but still allow the button-click flow on the admin page."
$bp_row = $bp_db->fetch("SELECT `value` FROM settings WHERE `key` = 'cms_sync.auto_bulk_publish'");
$bp_autoEnabled = $bp_row === null || ((string) $bp_row['value']) !== '0';
if (!$bp_autoEnabled) {
    echo "[" . date('Y-m-d H:i:s') . "] Auto bulk publish disabled via admin settings — skipping.\n";
    return;
}

echo "\n[" . date('Y-m-d H:i:s') . "] Bulk SDS Publish starting...\n";

// ─────────────────────────────────────────────────────────────────────
// Eligibility
// ─────────────────────────────────────────────────────────────────────
$bp_eligibility      = BulkPublishController::computeEligibleFinishedGoods($bp_db);
$bp_resaleEligibility = BulkPublishController::computeEligibleResaleItems($bp_db);

$bp_fgs    = $bp_eligibility['eligible'];
$bp_resale = $bp_resaleEligibility['eligible'];

echo "  Eligible finished goods: " . count($bp_fgs) . " (blocked: " . count($bp_eligibility['blocked']) . ")\n";
echo "  Eligible resale items:   " . count($bp_resale) . " (blocked: " . count($bp_resaleEligibility['blocked']) . ")\n";

if (empty($bp_fgs) && empty($bp_resale)) {
    echo "  Nothing to publish.\n";
    return;
}

// ─────────────────────────────────────────────────────────────────────
// Work items
// ─────────────────────────────────────────────────────────────────────
$bp_languages = App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);
$bp_workItems = BulkPublishController::buildWorkItems($bp_db, $bp_fgs, $bp_resale, $bp_languages);

$bp_totalItems  = count($bp_workItems);
$bp_workerCount = BulkPublishController::getWorkerCount($bp_totalItems);

echo "  Work items:       {$bp_totalItems} (" . count($bp_languages) . " languages × "
    . (count($bp_fgs) + count($bp_resale)) . " items + alias variants)\n";
echo "  Worker processes: {$bp_workerCount}\n";

// ─────────────────────────────────────────────────────────────────────
// Spawn workers
// ─────────────────────────────────────────────────────────────────────
$bp_progressDir = $bp_basePath . '/storage/exports';
if (!is_dir($bp_progressDir)) {
    mkdir($bp_progressDir, 0755, true);
}
$bp_logDir = $bp_basePath . '/storage/logs';
if (!is_dir($bp_logDir)) {
    mkdir($bp_logDir, 0755, true);
}

$bp_token   = bin2hex(random_bytes(8));
$bp_batches = array_chunk($bp_workItems, (int) ceil($bp_totalItems / $bp_workerCount));

$bp_progressFiles = [];
$bp_logFiles      = [];
$bp_batchFiles    = [];

foreach ($bp_batches as $i => $batch) {
    $batchFile    = $bp_progressDir . "/publish_batch_{$bp_token}_{$i}.json";
    $progressFile = $bp_progressDir . "/publish_worker_{$bp_token}_{$i}.json";
    $logFile      = $bp_logDir . "/publish_worker_{$bp_token}_{$i}.log";

    file_put_contents($batchFile, json_encode($batch));
    file_put_contents($progressFile, json_encode([
        'total'     => count($batch),
        'processed' => 0,
        'published' => 0,
        'failed'    => 0,
        'errors'    => [],
        'complete'  => false,
    ]), LOCK_EX);

    $bp_batchFiles[]    = $batchFile;
    $bp_progressFiles[] = $progressFile;
    $bp_logFiles[]      = $logFile;

    // Worker is backgrounded (&) so all N workers run in parallel;
    // this script then polls progress files below until every worker
    // writes complete=true.
    $cmd = sprintf(
        '%s %s %s %s %s > %s 2>&1 &',
        escapeshellarg(php_cli_binary()),
        escapeshellarg($bp_basePath . '/scripts/publish-worker.php'),
        escapeshellarg($batchFile),
        escapeshellarg($progressFile),
        escapeshellarg('0'),                // cron-user marker (0 = system)
        escapeshellarg($logFile)
    );
    exec($cmd);
}

// ─────────────────────────────────────────────────────────────────────
// Poll progress
// ─────────────────────────────────────────────────────────────────────
$bp_lastReportAt = 0;
while (true) {
    $allComplete    = true;
    $totalPublished = 0;
    $totalFailed    = 0;
    $totalProcessed = 0;
    $totalTotal     = 0;
    $errors         = [];

    foreach ($bp_progressFiles as $pf) {
        $content = @file_get_contents($pf);
        if ($content === false) {
            $allComplete = false;
            continue;
        }
        $progress = json_decode($content, true);
        if (!is_array($progress)) {
            $allComplete = false;
            continue;
        }
        $totalPublished += (int) ($progress['published'] ?? 0);
        $totalFailed    += (int) ($progress['failed']    ?? 0);
        $totalProcessed += (int) ($progress['processed'] ?? 0);
        $totalTotal     += (int) ($progress['total']     ?? 0);
        if (!empty($progress['errors'])) {
            $errors = array_merge($errors, $progress['errors']);
        }
        if (empty($progress['complete'])) {
            $allComplete = false;
        }
    }

    if ($allComplete) {
        break;
    }

    if (time() - $bp_lastReportAt >= 30) {
        echo "  [" . date('H:i:s') . "] Progress: {$totalProcessed}/{$totalTotal} "
            . "({$totalPublished} published, {$totalFailed} failed)\n";
        $bp_lastReportAt = time();
    }

    sleep(2);
}

// ─────────────────────────────────────────────────────────────────────
// Summary + cleanup
// ─────────────────────────────────────────────────────────────────────
$bp_elapsed = round(microtime(true) - $bp_start, 1);
echo "  Completed in {$bp_elapsed}s: {$totalPublished} published, {$totalFailed} failed.\n";

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

// Clean up transient progress + batch files so storage/exports doesn't grow
// unbounded. Log files stay for postmortem debugging.
foreach (array_merge($bp_batchFiles, $bp_progressFiles) as $file) {
    @unlink($file);
}
