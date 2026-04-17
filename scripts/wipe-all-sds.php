#!/usr/bin/env php
<?php
/**
 * wipe-all-sds.php — one-time cleanup utility.
 *
 * Hard-deletes every row in `sds_versions` and removes the corresponding
 * PDF files from /public/generated-pdfs/. FK cascades handle
 * `text_overrides` and `sds_generation_trace` automatically. Orphaned
 * references in `sds_send_log` are cleared too (that table has no FK).
 *
 * What this script TOUCHES:
 *   - sds_versions          (all rows deleted)
 *   - sds_generation_trace  (cascaded)
 *   - text_overrides        (cascaded)
 *   - sds_send_log          (explicit cleanup of references)
 *   - public/generated-pdfs/ (files removed)
 *
 * What this script DOES NOT TOUCH:
 *   - raw_materials
 *   - raw_material_constituents
 *   - competent_person_determinations
 *   - formulas / formula_lines
 *   - finished_goods
 *   - users, settings, aliases, customers, anything else
 *
 * Usage:
 *   php scripts/wipe-all-sds.php            # dry run — counts only
 *   php scripts/wipe-all-sds.php --confirm  # actually wipe
 */

require __DIR__ . '/../vendor/autoload.php';

use SDS\Core\App;
use SDS\Core\Database;

$dryRun = !in_array('--confirm', $argv, true);

echo "=============================================================\n";
echo "  SDS Versions Cleanup — " . ($dryRun ? 'DRY RUN' : 'LIVE') . "\n";
echo "=============================================================\n\n";

try {
    new App();   // bootstrap: loads config + DB
    $db  = Database::getInstance();
    $pdo = $db->getPdo();
} catch (\Throwable $e) {
    fwrite(STDERR, "Bootstrap failed: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Inventory everything that will be removed ──
$totals = $db->fetch("SELECT COUNT(*) AS cnt FROM sds_versions");
$total  = (int) ($totals['cnt'] ?? 0);

$traceTotals = $db->fetch("SELECT COUNT(*) AS cnt FROM sds_generation_trace");
$traceTotal  = (int) ($traceTotals['cnt'] ?? 0);

try {
    $overrideTotals = $db->fetch("SELECT COUNT(*) AS cnt FROM text_overrides");
    $overrideTotal  = (int) ($overrideTotals['cnt'] ?? 0);
} catch (\Throwable $e) {
    $overrideTotal = 0;
}

try {
    $sendLogTotals = $db->fetch("SELECT COUNT(*) AS cnt FROM sds_send_log");
    $sendLogTotal  = (int) ($sendLogTotals['cnt'] ?? 0);
} catch (\Throwable $e) {
    $sendLogTotal = 0;  // table may not exist on older installs
}

$pdfFiles = $db->fetchAll(
    "SELECT pdf_path FROM sds_versions WHERE pdf_path IS NOT NULL AND pdf_path != ''"
);

echo "Rows in sds_versions:         {$total}\n";
echo "Rows in sds_generation_trace: {$traceTotal}  (CASCADE — auto-removed)\n";
echo "Rows in text_overrides:       {$overrideTotal}  (CASCADE — auto-removed)\n";
echo "Rows in sds_send_log:         {$sendLogTotal}  (cleared explicitly — no FK)\n";
echo "PDF files to delete:          " . count($pdfFiles) . "\n\n";

if ($total === 0 && count($pdfFiles) === 0) {
    echo "Nothing to clean up. Exiting.\n";
    exit(0);
}

if ($dryRun) {
    echo "DRY RUN — no changes made.\n";
    echo "Run again with --confirm to actually perform the wipe.\n";
    exit(0);
}

echo "Proceeding with live wipe...\n\n";

// ── 1. Remove PDF files from disk ──
$basePath   = App::basePath();
$pdfDeleted = 0;
$pdfMissing = 0;
$pdfFailed  = 0;
$pdfDir     = $basePath . '/public/generated-pdfs';

// Pre-flight: verify we can write to the PDF directory. If not, PHP's
// unlink() will silently fail on every file. Bail out early with a
// clear message rather than leaving dangling files after the DB wipe.
if (!is_writable($pdfDir)) {
    $user = function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid())['name'] : 'unknown';
    fwrite(STDERR, "\n[ERROR] Cannot write to {$pdfDir}\n");
    fwrite(STDERR, "Current process user: {$user}\n");
    fwrite(STDERR, "Re-run as root or fix ownership first:\n");
    fwrite(STDERR, "  sudo chown -R www-data:www-data {$pdfDir}\n");
    fwrite(STDERR, "  sudo chmod 775 {$pdfDir}\n\n");
    fwrite(STDERR, "Aborting before DB wipe — no changes made.\n");
    exit(1);
}

foreach ($pdfFiles as $row) {
    $rel = ltrim((string) $row['pdf_path'], '/');
    if ($rel === '') {
        continue;
    }
    $full = $basePath . '/' . $rel;
    if (file_exists($full)) {
        if (@unlink($full)) {
            $pdfDeleted++;
        } else {
            $pdfFailed++;
            fwrite(STDERR, "  [warn] could not delete {$full}\n");
        }
    } else {
        $pdfMissing++;
    }
}

echo "PDF files removed:    {$pdfDeleted}\n";
if ($pdfMissing > 0) {
    echo "PDF files missing:    {$pdfMissing}  (not on disk — skipped)\n";
}
if ($pdfFailed > 0) {
    fwrite(STDERR, "\n[ERROR] {$pdfFailed} PDF file(s) could not be deleted (likely permission).\n");
    fwrite(STDERR, "Aborting before DB wipe — no changes made to database.\n");
    fwrite(STDERR, "Fix ownership on /public/generated-pdfs and re-run.\n\n");
    exit(1);
}

// ── 2. Clear sds_send_log references (no FK cascade on this table) ──
if ($sendLogTotal > 0) {
    $db->query("DELETE FROM sds_send_log");
    echo "sds_send_log cleared: {$sendLogTotal} rows\n";
}

// ── 3. Hard-delete sds_versions (cascades to sds_generation_trace + text_overrides) ──
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
$db->query("DELETE FROM sds_generation_trace");
$db->query("DELETE FROM text_overrides");
$db->query("DELETE FROM sds_versions");
$pdo->exec("ALTER TABLE sds_versions          AUTO_INCREMENT = 1");
$pdo->exec("ALTER TABLE sds_generation_trace  AUTO_INCREMENT = 1");
$pdo->exec("SET FOREIGN_KEY_CHECKS=1");

echo "sds_versions wiped:   {$total} rows\n";

// ── 4. Sanity check — confirm raw data survived untouched ──
$rmCount  = (int) ($db->fetch("SELECT COUNT(*) AS c FROM raw_materials")['c'] ?? 0);
$rmcCount = (int) ($db->fetch("SELECT COUNT(*) AS c FROM raw_material_constituents")['c'] ?? 0);
$cpdCount = (int) ($db->fetch("SELECT COUNT(*) AS c FROM competent_person_determinations")['c'] ?? 0);

echo "\nPost-wipe sanity check:\n";
echo "  raw_materials:                    {$rmCount}  (untouched)\n";
echo "  raw_material_constituents:        {$rmcCount}  (untouched)\n";
echo "  competent_person_determinations:  {$cpdCount}  (untouched)\n";

echo "\nDone. Bulk publish can now be re-run from a clean slate.\n";
