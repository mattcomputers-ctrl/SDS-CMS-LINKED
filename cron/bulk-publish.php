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
 * Language order (owner request 2026-10-19): the work list is built once
 * per job, each source stays on one worker, and every worker publishes its
 * English sheets first, then Spanish, German and French
 * (BulkPublishController::planWorkerBatches). The log says when each
 * language pass is complete. A run stopped part-way leaves some sources'
 * current version in some languages only; the next run publishes the rest
 * at the same version (BulkPublishController::missingLanguages).
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

/** English name of a publish language for the log ("Spanish"); other codes upper-cased. */
function bp_languageName(string $lang): string
{
    return ['en' => 'English', 'es' => 'Spanish', 'de' => 'German', 'fr' => 'French'][$lang] ?? strtoupper($lang);
}

/** "1h 05m" / "12m 30s" for the log. */
function bp_elapsed(float $seconds): string
{
    $s = (int) round($seconds);
    return $s >= 3600
        ? sprintf('%dh %02dm', intdiv($s, 3600), intdiv($s % 3600, 60))
        : sprintf('%dm %02ds', intdiv($s, 60), $s % 60);
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

    // Audit #2 / #68 — every standard, alias and resale SDS prints the company
    // emergency phone. With it blank, buildWorkItems() emits private-label items
    // only (they print the manufacturer's number). The job is marked failed at
    // the end, so the queue shows why the base sheets were not published.
    $phoneError = \SDS\Services\SDSReadinessService::companyEmergencyPhoneErrorFromDb($db);
    if ($phoneError !== null) {
        echo "  " . $phoneError . "\n  Only private-label items will be published in this job.\n";
    }

    // ── Eligibility ──────────────────────────────────────────────
    $eligibility       = BulkPublishController::computeEligibleFinishedGoods($db);
    $resaleEligibility = BulkPublishController::computeEligibleResaleItems($db);

    $fgs    = $eligibility['eligible'];
    $resale = $resaleEligibility['eligible'];

    // Fresh sources whose current version is missing languages (a stopped or
    // killed run): those languages are published at the same version.
    $incompleteFgs    = $eligibility['incomplete'] ?? [];
    $incompleteResale = $resaleEligibility['incomplete'] ?? [];

    echo "  Eligible finished goods: " . count($fgs) . " (blocked: " . count($eligibility['blocked']) . ")\n";
    echo "  Eligible resale items:   " . count($resale) . " (blocked: " . count($resaleEligibility['blocked']) . ")\n";
    if ($incompleteFgs !== [] || $incompleteResale !== []) {
        echo "  Missing languages to complete: " . count($incompleteFgs) . " finished goods, "
            . count($incompleteResale) . " resale items\n";
    }

    $stats = [
        'eligible_fg_count'     => count($fgs),
        'eligible_resale_count' => count($resale),
        'work_items_count'      => 0,
        'published_count'       => 0,
        'failed_count'          => 0,
    ];
    BulkPublishQueue::updateProgress($jobId, $stats);

    if (empty($fgs) && empty($resale) && $incompleteFgs === [] && $incompleteResale === []) {
        echo "  Nothing to publish.\n";
        return $stats;
    }

    // ── Work items ───────────────────────────────────────────────
    // Built once per job, never per language: a list rebuilt after the
    // English sheets landed would see those sources as current.
    $languages     = App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);
    $publishOrder  = BulkPublishController::publishLanguageOrder($languages);
    $workItems     = BulkPublishController::buildWorkItems($db, $fgs, $resale, $languages, $incompleteFgs, $incompleteResale);

    $totalItems  = count($workItems);
    $workerCount = BulkPublishController::getWorkerCount($totalItems);
    $stats['work_items_count'] = $totalItems;
    BulkPublishQueue::updateProgress($jobId, $stats);

    if ($totalItems === 0) {
        echo "  No work items.\n";
        if ($phoneError !== null) {
            throw new \RuntimeException($phoneError . ' No private-label items were due, so nothing was published.');
        }
        return $stats;
    }

    // Owner request 2026-10-19: each source stays on one worker and every
    // worker publishes its English sheets first, then Spanish, German, French.
    $batches = BulkPublishController::planWorkerBatches($workItems, $workerCount, $publishOrder);

    $perLanguage = [];
    $completion  = 0;
    foreach ($workItems as $item) {
        $lang = (string) ($item['language'] ?? '');
        $perLanguage[$lang] = ($perLanguage[$lang] ?? 0) + 1;
        if (!empty($item['completion'])) {
            $completion++;
        }
    }
    $langSummary = [];
    foreach (array_merge($publishOrder, array_diff(array_keys($perLanguage), $publishOrder)) as $lang) {
        if (isset($perLanguage[$lang])) {
            $langSummary[] = ($lang === 'all' ? 'private label' : strtoupper($lang)) . ' ' . $perLanguage[$lang];
        }
    }

    echo "  Work items:       {$totalItems} (" . implode(', ', $langSummary) . ")"
        . ($completion > 0 ? ", {$completion} completing missing languages" : '') . "\n";
    echo "  Publish order:    " . strtoupper(implode(', ', $publishOrder)) . " (private label documents with English)\n";
    echo "  Worker processes: " . count($batches) . "\n";

    // ── Spawn workers ────────────────────────────────────────────
    $progressDir = $basePath . '/storage/exports';
    if (!is_dir($progressDir)) { mkdir($progressDir, 0755, true); }
    $logDir = $basePath . '/storage/logs';
    if (!is_dir($logDir)) { mkdir($logDir, 0755, true); }

    $token = bin2hex(random_bytes(8));

    // Per batch, how many of its items belong to each language pass and the
    // ones before it (batches are sorted by language), so the poll loop can
    // tell when every worker has finished, e.g., its English items.
    $passEnds = BulkPublishController::passEnds($batches, $publishOrder);
    $passDone = [];

    // Language passes on the job row (migration 065) for the queue and
    // publish pages: "English done 14:05". Only languages with sheets of
    // their own; private-label documents count in the first pass, but a run
    // of private labels only (blank company phone) shows no language.
    $langProgress = ['order' => [], 'passes' => []];
    foreach (BulkPublishController::passProgress($passEnds, [], $publishOrder) as $lang => $p) {
        if (($perLanguage[$lang] ?? 0) > 0) {
            $langProgress['order'][]       = $lang;
            $langProgress['passes'][$lang] = ['items' => $p['items'], 'processed' => 0, 'done_at' => null];
        }
    }
    $langProgressAt = 0;
    $recordLanguages = static function (array $processedBy, bool $force) use ($jobId, $passEnds, $publishOrder, &$langProgress, &$langProgressAt): void {
        if (!$force && time() - $langProgressAt < 15) {
            return;
        }
        foreach (BulkPublishController::passProgress($passEnds, $processedBy, $publishOrder) as $lang => $p) {
            if (isset($langProgress['passes'][$lang])) {
                $langProgress['passes'][$lang]['processed'] = $p['processed'];
            }
        }
        try {
            BulkPublishQueue::updateLanguageProgress($jobId, $langProgress);
        } catch (\Throwable $e) {
            // Display only: never let it stop a publish run.
        }
        $langProgressAt = time();
    };
    $recordLanguages([], true);

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
    // Last good processed count per batch: a progress file read mid-write
    // (empty or torn JSON) keeps the previous count instead of dropping to 0.
    $processedBy        = array_fill_keys(array_keys($progressFiles), 0);

    while (true) {
        $allComplete    = true;
        $totalPublished = 0;
        $totalFailed    = 0;
        $totalProcessed = 0;
        $totalTotal     = 0;
        $errors         = [];

        foreach ($progressFiles as $idx => $pf) {
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
            // A worker that stopped early also writes complete=true; only a
            // full count says its items of a language pass were all processed.
            $processedBy[$idx] = (int) ($progress['processed'] ?? 0);
        }

        // A language pass is complete once every one of its items has been
        // processed, counted on its own: in a small run, split by language,
        // Spanish can finish on its own workers before English does.
        $currentPass = null;
        foreach (BulkPublishController::passProgress($passEnds, $processedBy, $publishOrder) as $lang => $p) {
            if (isset($passDone[$lang]) || $p['items'] === 0) {
                continue;
            }
            if ($p['processed'] < $p['items']) {
                $currentPass ??= $lang;
                continue;
            }
            $passDone[$lang] = true;
            if (($perLanguage[$lang] ?? 0) === 0) {
                continue;   // no items in this language (e.g. a private-label-only run)
            }
            echo "  [" . date('H:i:s') . "] " . bp_languageName($lang) . " pass complete after "
                . bp_elapsed(microtime(true) - $start) . " ({$totalProcessed}/{$totalTotal} items processed, "
                . "{$totalPublished} published, {$totalFailed} failed so far)\n";
            if (isset($langProgress['passes'][$lang])) {
                $langProgress['passes'][$lang]['done_at'] = \SDS\Services\PublishClock::nowUtc();
                $langProgressAt = 0;   // write now
            }
        }
        $recordLanguages($processedBy, false);

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
            $recordLanguages($processedBy, true);
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
            $recordLanguages($processedBy, true);
            foreach (array_merge($batchFiles, $progressFiles) as $file) { @unlink($file); }
            throw new \RuntimeException(sprintf(
                'Stalled: no progress for %ds with %d workers alive. Remaining work will be '
                . 'picked up on the next bulk publish trigger.',
                $stalledFor, $workersAlive
            ));
        }

        if (time() - $lastReportAt >= 30) {
            echo "  [" . date('H:i:s') . "] Progress: {$totalProcessed}/{$totalTotal} "
                . "({$totalPublished} published, {$totalFailed} failed)"
                . ($currentPass !== null ? ', ' . bp_languageName($currentPass) . ' pass' : '') . "\n";
            $lastReportAt = time();
        }
        sleep(2);
    }

    $recordLanguages($processedBy, true);

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
    if ($phoneError !== null) {
        throw new \RuntimeException($phoneError . ' Only private-label items were published in this job ('
            . $totalPublished . ' published, ' . $totalFailed . ' failed).');
    }
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
        $held    = (int) ($r['held']        ?? 0);
        $errors  = (int) count($r['errors'] ?? []);
        $errTail = $errors > 0 ? ", {$errors} errors" : '';
        $heldTail = $held > 0 ? ", {$held} waiting for languages" : '';
        echo "  Auto-send: {$sent} sent, {$queued} queued, {$skipped} skipped{$heldTail}{$errTail}\n";
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

    // When running inside cms-sync, auto-send follows right after this
    // file returns — but it must not run until publishing is done. Wait
    // for the active runner to finish our queued job (max 30 minutes);
    // on timeout, flag the parent to skip auto-send this run. Shipments
    // stay unprocessed (sds_processed_at NULL) so the next run sends them.
    if ($bp_requiredFromCmsSync) {
        $bp_waitStart = time();
        $bp_maxWait   = 1800;
        while (true) {
            $bp_status = BulkPublishQueue::getStatus($bp_jobId);
            if ($bp_status === 'completed' || $bp_status === 'failed' || $bp_status === null) {
                echo "[" . date('Y-m-d H:i:s') . "] Queued job #{$bp_jobId} finished with status: " . ($bp_status ?? 'gone') . ".\n";
                break;
            }
            if (time() - $bp_waitStart >= $bp_maxWait) {
                echo "[" . date('Y-m-d H:i:s') . "] Job #{$bp_jobId} still {$bp_status} after 30 minutes — skipping auto-send this run; next run will pick the shipments up.\n";
                $bp_publishIncomplete = true;
                break;
            }
            if ((time() - $bp_waitStart) % 60 < 10) {
                echo "[" . date('Y-m-d H:i:s') . "] Waiting for bulk publish job #{$bp_jobId} ({$bp_status})...\n";
            }
            sleep(10);
        }
    }
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

        // Shipment lines whose product is still missing some of the
        // customer's languages at its English version (a stopped run) wait
        // in SDSAutoSendService, at most a day; see languagesCatchingUp().

        // Post-job auto-send only when bulk-publish runs standalone
        // (admin button). When require'd from cms-sync.php, the parent
        // script calls processNewShipments with the correct $timestamp
        // watermark right after this file returns — running it here too
        // causes duplicate sends because the null watermark falls back
        // to auto_send.last_run_at which may be stale.
        if (!$bp_requiredFromCmsSync) {
            bp_runPostJobSteps($bp_db);
        }
    }
} finally {
    BulkPublishQueue::releaseLock();
}
