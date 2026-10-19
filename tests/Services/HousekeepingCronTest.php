#!/usr/bin/env php
<?php
/**
 * Housekeeping and scheduled-backup cron test (DB-free)
 *
 * Until 2026-10-19 cron/housekeeping.php and cron/scheduled_backup.php used
 * the database without bootstrapping App, so every run died on its first
 * database call: housekeeping never cleaned anything and no scheduled backup
 * was ever made. Fixing that makes their steps run for the first time, so this
 * also checks those steps are safe:
 *   a. every CLI entry point constructs App before it uses the app;
 *   b. the bulk export cleanup deletes only the export's own files (not SDS
 *      Archive ZIPs or a running bulk publish's files);
 *   c. the audit purge only deletes routine rows, never the raw-material
 *      review records that publishing depends on;
 *   d. temp and killed-run leftover sweeps;
 *   e. the scheduled-backup time window (once per day, wraps midnight);
 *   f. both scripts report a failure instead of dying silently.
 *
 * Run:
 *   php tests/Services/HousekeepingCronTest.php
 *
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);

require_once $basePath . '/vendor/autoload.php';

use SDS\Controllers\ExportController;
use SDS\Services\BackupService;
use SDS\Services\HousekeepingService;

$failures = 0;
$checks   = 0;

function check(bool $ok, string $label, $actual = null): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  ok   {$label}\n";
        return;
    }
    $failures++;
    echo "  FAIL {$label}\n";
    if ($actual !== null) {
        echo '       actual: ' . var_export($actual, true) . "\n";
    }
}

/** PHP source without comments, so commented-out code never counts. */
function code_only(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $out .= str_repeat("\n", substr_count($t[1], "\n"));
            continue;
        }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

function rm_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

function make_file(string $path, int $mtime, string $content = 'x'): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $content);
    touch($path, $mtime);
}

$now   = time();
$token = 'a1b2c3d4e5f60718';
$other = '0f1e2d3c4b5a6978';
$tmp   = sys_get_temp_dir() . '/housekeeping_test_' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);

// ---------------------------------------------------------------------
echo "a. Every CLI entry point constructs App before using the app\n";
$needsApp = '/\bDatabase::getInstance\s*\(|\bApp::(?:config|basePath|db|session)\s*\(|\b[A-Z]\w*(?:Service|Controller|Queue|Publisher|Renderer|Generator|Importer|Clock)::\w+\s*\(|\bnew\s+\\\\?(?:SDS\\\\\w+\\\\)*[A-Z]\w*(?:Service|Importer|Publisher|Renderer|Generator|Controller)\s*\(/';
$bootsApp = '/\bnew\s+\\\\?(?:SDS\\\\Core\\\\)?App\s*\(|class_exists\(\s*\\\\?SDS\\\\Core\\\\App::class/';
// Entry points that legitimately need no App.
$noAppNeeded = [
    'scripts/build-legacy-override-texts.php' => 'only the pure TextOverrideService::normalize()',
];
$entryPoints = array_merge(
    glob($basePath . '/cron/*.php') ?: [],
    glob($basePath . '/scripts/*.php') ?: [],
    glob($basePath . '/seeds/*.php') ?: []
);
check(count($entryPoints) > 30, 'found the cron, scripts and seeds entry points', count($entryPoints));
foreach ($entryPoints as $file) {
    $rel  = substr($file, strlen($basePath) + 1);
    $code = code_only((string) file_get_contents($file));
    if (isset($noAppNeeded[$rel]) || !preg_match($needsApp, $code, $use, PREG_OFFSET_CAPTURE)) {
        continue;
    }
    $line = substr_count(substr($code, 0, $use[0][1]), "\n") + 1;
    $ok   = preg_match($bootsApp, $code, $boot, PREG_OFFSET_CAPTURE) && $boot[0][1] < $use[0][1];
    check($ok, "{$rel}: App constructed before line {$line} (" . trim($use[0][0]) . ')');
}

foreach (['cron/housekeeping.php', 'cron/scheduled_backup.php'] as $rel) {
    $code = code_only((string) file_get_contents($basePath . '/' . $rel));
    check(str_contains($code, 'catch (\Throwable $e)'), "{$rel}: failures are caught and echoed (App sets error_reporting(0))");
}

// ---------------------------------------------------------------------
echo "\nb. Bulk export cleanup deletes only the export's own files\n";
$exp  = $tmp . '/exports';
$old  = $now - ExportController::EXPORT_TTL_SECONDS - 600;
$new  = $now - 600;
$files = [
    // name => [mtime, expected to expire]
    'SDS_Export_EN_20261019_010203.zip'        => [$old, true],
    'SDS_Export_EN_part_02_20261019_010203.zip' => [$old, true],
    'SDS_Export_2026-10-19.zip'                => [$old, true],
    "progress_{$token}_en.json"                => [$old, true],
    "progress_{$token}.json"                   => [$old, true],
    "manifest_{$token}.json"                   => [$old, true],
    "worker_{$token}_de.log"                   => [$old, true],
    'SDS_Export_EN_20261019_010203.zip.a1B2c3' => [$old, true],    // libzip temp of a killed worker
    'SDS_Export_EN_20261019_010203.zip.x9Y8z7.part' => [$old, true],
    'SDS_Export_DE_20261019_230000.zip.q1W2e3' => [$new, false],   // close() still writing
    'SDS_Export_FR_20261019_230000.zip'        => [$new, false],
    'sds-archive-2026-10-01_120000.zip'        => [$old, false],
    "publish_batch_{$token}_0.json"            => [$old, false],
    "publish_worker_{$token}_3.json"           => [$old, false],
    "publish_stop_{$token}.flag"               => [$old, false],
    'notes.txt'                                => [$old, false],
];
foreach ($files as $name => [$mtime]) {
    make_file($exp . '/' . $name, $mtime);
}
$expired = array_map('basename', ExportController::expiredExportFiles($exp, $now));
foreach ($files as $name => [, $expires]) {
    check(in_array($name, $expired, true) === $expires, ($expires ? 'expires: ' : 'kept:    ') . $name);
}
$deleted = ExportController::cleanupExpiredExports($exp);
check($deleted === 9, 'cleanupExpiredExports deletes exactly the 9 expired export files', $deleted);
check(is_file($exp . '/sds-archive-2026-10-01_120000.zip') && is_file($exp . "/publish_worker_{$token}_3.json"), 'archive ZIP and bulk publish progress file survive');
check(ExportController::expiredExportFiles($tmp . '/missing') === [], 'missing export directory: nothing to do');

// ---------------------------------------------------------------------
echo "\nc. Audit purge: routine rows only, never review records\n";
check(HousekeepingService::auditRetentionDays(365) === 365, 'retention 365 used');
check(HousekeepingService::auditRetentionDays('400') === 400, "retention '400' (string from config) used");
foreach ([0, -5, 30, 89, null, '', 'abc'] as $bad) {
    check(HousekeepingService::auditRetentionDays($bad) === null, 'retention ' . var_export($bad, true) . ' disables the purge');
}
check(HousekeepingService::effectiveAuditRetentionDays(365, '2555') === 2555, 'Settings value longer than config.php: the Settings value wins');
check(HousekeepingService::effectiveAuditRetentionDays(365, '30') === 365, 'Settings value shorter than config.php: config.php wins (the page can only lengthen)');
check(HousekeepingService::effectiveAuditRetentionDays(365, null) === 365, 'no Settings row: config.php');
check(HousekeepingService::effectiveAuditRetentionDays(null, '400') === 400, 'no config value: Settings value');
check(HousekeepingService::effectiveAuditRetentionDays(0, '0') === null, 'both zero: purge off');
check(str_contains($hk_src = code_only((string) file_get_contents($basePath . '/cron/housekeeping.php')), "WHERE `key` = 'cron.log_retention_days'") && str_contains($hk_src, 'effectiveAuditRetentionDays('), 'housekeeping reads the Settings → Maintenance retention too');
check(str_contains($hk_src, "\$diff['incomplete'] = true;") && str_contains($hk_src, 'purgeAuditLog($db, $days, false, $deleted)'), 'a purge that fails part-way still records the rows it deleted');
[$where, $params] = HousekeepingService::auditPurgeWhere(365);
check(substr_count($where, '?') === count($params), 'placeholders match bind values', [substr_count($where, '?'), count($params)]);
check($params[0] === 365, 'first bind value is the retention in days');
check(str_starts_with($where, '`timestamp` < UTC_TIMESTAMP() - INTERVAL ? DAY AND ('), 'cutoff computed in SQL in UTC (audit_log.timestamp is UTC)', $where);
check(!in_array('raw_material', $params, true) && !str_contains($where, 'raw_material'), 'raw_material rows (the review records) are never purgeable');
foreach (['raw_material', 'finished_good', 'competent_determination', 'permission_group', 'settings', 'user', 'backup', 'sds_archive'] as $kept) {
    check(!array_key_exists($kept, HousekeepingService::AUDIT_PURGEABLE), "{$kept} history is kept");
}
check(HousekeepingService::AUDIT_PURGEABLE['sds_version'] === ['bulk_publish', 'bulk_publish_alias', 'bulk_publish_resale', 'bulk_publish_resale_alias', 'download'], 'sds_version: only bulk publish and download rows (not publish, republish_update, soft_delete, restore)');
$hk = code_only((string) file_get_contents($basePath . '/cron/housekeeping.php'));
check(str_contains($hk, 'HousekeepingService::purgeAuditLog(') && !preg_match("/delete\\(\\s*'audit_log'/", $hk), 'housekeeping purges only through purgeAuditLog');
// The audit actions in the allowlist are the ones the code writes.
$writers = [
    'scripts/publish-worker.php'                  => ['bulk_publish_resale_alias', 'bulk_publish_resale', 'bulk_publish_alias', 'bulk_publish'],
    'src/Controllers/LookupController.php'        => ['download'],
    'src/Controllers/PrivateLabelController.php'  => ['download'],
    'src/Controllers/AuthController.php'          => ['login_failed', 'login', 'session_timeout', 'logout'],
];
foreach ($writers as $rel => $actions) {
    $src = (string) file_get_contents($basePath . '/' . $rel);
    foreach ($actions as $a) {
        check(str_contains($src, "'{$a}'"), "{$rel} writes '{$a}'");
    }
}

// ---------------------------------------------------------------------
echo "\nd. Temp files and killed-run leftovers\n";
$storage = $tmp . '/site/storage';
make_file($storage . '/temp/pdf_input_old.json', $now - 2 * 86400);
make_file($storage . '/temp/pdf_input_new.json', $now - 3600);
mkdir($storage . '/temp/smoke_dir', 0777, true);
touch($storage . '/temp/smoke_dir', $now - 9 * 86400);
$site = $tmp . '/site';
$temp = array_map('basename', HousekeepingService::staleTempFiles($storage . '/temp', $site, $now) ?? []);
check($temp === ['pdf_input_old.json'], 'temp: only files older than a day (not new files, not directories)', $temp);
check(HousekeepingService::staleTempFiles($storage . '/../storage/temp', $site, $now) !== null, 'temp: same directory through a .. path is accepted');
make_file($tmp . '/elsewhere/important.txt', $now - 9 * 86400);
make_file($storage . '/backups/sds_backup_full_20261001_020000.tar.gz', $now - 9 * 86400);
make_file($storage . '/exports/sds-archive-2026-09-01_120000.zip', $now - 40 * 86400);
foreach (['outside storage/' => $tmp . '/elsewhere', 'storage/ itself' => $storage, 'storage/backups' => $storage . '/backups', 'storage/exports' => $storage . '/exports', 'missing' => $storage . '/missing'] as $what => $dir) {
    check(HousekeepingService::staleTempFiles($dir, $site, $now) === null, "paths.temp = {$what}: sweep refused (reported, not 'deleted 0')");
}
check(str_contains($hk_src, 'Temp sweep skipped: paths.temp'), 'housekeeping reports a refused temp sweep');

// libzip temp files of killed SDS Archive builds go after a day; finished archives never.
make_file($storage . '/exports/sds-archive-2026-09-01_120000.zip.k3J4l5', $now - 2 * 86400);
make_file($storage . '/exports/sds-archive-2026-09-02_120000.zip.m6N7o8.part', $now - 2 * 86400);
make_file($storage . '/exports/sds-archive-2026-10-19_120000.zip.p9Q0r1', $now - 600);
$arch = array_map('basename', HousekeepingService::staleArchiveTempFiles($site, $now));
sort($arch);
check($arch === ['sds-archive-2026-09-01_120000.zip.k3J4l5', 'sds-archive-2026-09-02_120000.zip.m6N7o8.part'], 'archive ZIP temp files older than a day only; finished archive ZIPs never', $arch);

$run = [
    // relative path => [mtime, size, expected stale]
    "storage/exports/publish_batch_{$other}_0.json"  => [$now - 4 * 86400, 1, true],
    "storage/exports/publish_worker_{$other}_7.json" => [$now - 4 * 86400, 1, true],
    "storage/exports/publish_stop_{$other}.flag"     => [$now - 4 * 86400, 1, true],
    "storage/exports/publish_worker_{$other}_8.json" => [$now - 3600, 1, false],
    "storage/exports/publish_worker_{$token}_1.json" => [$now - 9 * 86400, 1, false],   // live run
    'storage/exports/sds-archive-2026-01-01_000000.zip' => [$now - 90 * 86400, 1, false],
    "storage/logs/publish_worker_{$other}_0.log"     => [$now - 4 * 86400, 0, true],    // empty, 4 days
    "storage/logs/publish_worker_{$other}_1.log"     => [$now - 4 * 86400, 1, false],   // has output, 4 days
    "storage/logs/publish_worker_{$other}_2.log"     => [$now - 31 * 86400, 1, true],   // has output, 31 days
    "storage/logs/publish_worker_{$token}_0.log"     => [$now - 31 * 86400, 0, false],  // live run
    'storage/logs/cms-sync.log'                      => [$now - 90 * 86400, 1, false],
];
foreach ($run as $rel => [$mtime, $size]) {
    make_file($site . '/' . $rel, $mtime, $size > 0 ? 'output' : '');
}
$live  = "12345 php scripts/publish-worker.php /x/storage/exports/publish_batch_{$token}_1.json /x/storage/exports/publish_worker_{$token}_1.json\n";
$stale = array_map(static fn (string $f): string => substr($f, strlen($site) + 1), HousekeepingService::staleBulkPublishFiles($site, $live, $now));
foreach ($run as $rel => [, , $expected]) {
    check(in_array($rel, $stale, true) === $expected, ($expected ? 'stale: ' : 'kept:  ') . $rel);
}
check(str_contains($hk, "IS_USED_LOCK(?)") && str_contains($hk, 'BulkPublishQueue::LOCK_NAME'), 'leftover sweep is skipped while a runner holds the bulk publish lock');
[$d, $f] = HousekeepingService::unlinkAll([$site . "/storage/exports/publish_stop_{$other}.flag", $site . '/storage/exports/no-such-file']);
check($d === 1 && $f === 1, 'unlinkAll counts only real deletions', [$d, $f]);

// ---------------------------------------------------------------------
echo "\ne. Scheduled backup window\n";
date_default_timezone_set('UTC');
$at = static fn (string $s): int => (int) strtotime($s . ' UTC');
check(BackupService::scheduleSkipReason('daily', '02:00', $at('2026-10-20 02:00')) === null, 'daily 02:00 runs at 02:00');
check(BackupService::scheduleSkipReason('daily', '02:00', $at('2026-10-20 02:59')) === null, 'daily 02:00 still due at 02:59');
check(BackupService::scheduleSkipReason('daily', '02:00', $at('2026-10-20 03:00')) !== null, 'daily 02:00 not at 03:00');
check(BackupService::scheduleSkipReason('daily', '02:00', $at('2026-10-20 01:59')) !== null, 'daily 02:00 not at 01:59');
check(BackupService::scheduleSkipReason('daily', '02:30', $at('2026-10-20 02:00')) !== null, 'daily 02:30 not at 02:00 (ran twice before)');
check(BackupService::scheduleSkipReason('daily', '02:30', $at('2026-10-20 03:00')) === null, 'daily 02:30 runs at 03:00');
check(BackupService::scheduleSkipReason('daily', '23:45', $at('2026-10-21 00:00')) === null, 'daily 23:45 runs at 00:00 (never ran before)');
check(BackupService::scheduleSkipReason('weekly', '23:45', $at('2026-10-19 00:00')) === null, 'weekly 23:45: Monday 00:00 run counts as Sunday');
check(BackupService::scheduleSkipReason('weekly', '23:45', $at('2026-10-18 00:00')) !== null, 'weekly 23:45: Sunday 00:00 run is Saturday\'s slot');
check(BackupService::scheduleSkipReason('weekly', '02:00', $at('2026-10-18 02:00')) === null, 'weekly 02:00 runs on Sunday');
check(BackupService::scheduleSkipReason('monthly', '23:45', $at('2026-11-02 00:00')) === null, 'monthly 23:45: 2nd 00:00 run counts as the 1st');
check(BackupService::scheduleSkipReason('monthly', '23:45', $at('2026-11-01 00:00')) !== null, 'monthly 23:45: 1st 00:00 run is the previous month');
// An hourly cron (minute 0) makes exactly one daily backup for any schedule time.
$badTimes = [];
for ($m = 0; $m < 1440; $m += 15) {
    $time = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    $runs = 0;
    for ($h = 0; $h < 24; $h++) {
        if (BackupService::scheduleSkipReason('daily', $time, $at(sprintf('2026-10-20 %02d:00', $h))) === null) {
            $runs++;
        }
    }
    if ($runs !== 1) {
        $badTimes[$time] = $runs;
    }
}
check($badTimes === [], 'hourly cron: exactly one backup per day for every schedule time (15-minute steps)', $badTimes);
// The same in a US zone across both DST changes: the server runs the cron on
// UTC hours, the schedule is a wall-clock time in the app time zone.
date_default_timezone_set('America/Chicago');
foreach (['2027-03-14' => 'spring forward', '2026-11-01' => 'fall back', '2026-10-20' => 'normal day'] as $day => $label) {
    $start = (new DateTimeImmutable($day . ' 00:00:00'))->getTimestamp();
    $end   = (new DateTimeImmutable($day . ' 00:00:00'))->modify('+1 day')->getTimestamp();
    $bad   = [];
    for ($m = 0; $m < 1440; $m += 15) {
        $time = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        $runs = 0;
        for ($t = $start; $t < $end; $t += 3600) {
            if (BackupService::scheduleSkipReason('daily', $time, $t) === null) {
                $runs++;
            }
        }
        if ($runs !== 1) {
            $bad[$time] = $runs;
        }
    }
    check($bad === [], "America/Chicago {$label} ({$day}): exactly one backup for every schedule time", $bad);
}
check(BackupService::scheduleSkipReason('weekly', '02:00', (new DateTimeImmutable('2027-03-14 03:00:00'))->getTimestamp()) === null, 'weekly 02:00 on the spring-forward Sunday runs at 03:00');
date_default_timezone_set('UTC');
$sb = code_only((string) file_get_contents($basePath . '/cron/scheduled_backup.php'));
check(str_contains($sb, 'BackupService::scheduleSkipReason(') && str_contains($sb, "notes = 'Scheduled backup' AND created_at > UTC_TIMESTAMP() - INTERVAL 2 HOUR"), 'scheduled_backup.php uses the window and skips a second backup within 2 hours');
check(str_contains($sb, 'flock($lock, LOCK_EX | LOCK_NB)'), 'scheduled_backup.php will not start while another scheduled backup is still running');

// ---------------------------------------------------------------------
echo "\nf. A failed start is reported, not silent\n";
// Run the scripts in a sandbox with its own copy of src/Core (App takes its
// base path from its own file) and its own autoloader, so App finds no
// config and the real database is never reached, even when this suite runs
// on the server. The script must say it failed and exit 1.
$sandbox = $tmp . '/sandbox';
mkdir($sandbox . '/cron', 0777, true);
mkdir($sandbox . '/src/Core', 0777, true);
mkdir($sandbox . '/vendor', 0777, true);
foreach (glob($basePath . '/src/Core/*.php') ?: [] as $f) {
    copy($f, $sandbox . '/src/Core/' . basename($f));
}
file_put_contents($sandbox . '/vendor/autoload.php', '<?php
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, "SDS\\\\")) {
        return;
    }
    $rel  = str_replace("\\\\", "/", substr($class, 4)) . ".php";
    $file = str_starts_with($class, "SDS\\\\Core\\\\") ? __DIR__ . "/../src/" . $rel : ' . var_export($basePath . '/src/', true) . ' . $rel;
    if (is_file($file)) {
        require $file;
    }
});
');
foreach (['housekeeping.php', 'scheduled_backup.php', 'cron-helpers.php'] as $f) {
    copy($basePath . '/cron/' . $f, $sandbox . '/cron/' . $f);
}
check(!is_file($sandbox . '/config/config.php'), 'sandbox has no config (the real database cannot be reached)');
foreach (['housekeeping.php' => 'Housekeeping FAILED to start', 'scheduled_backup.php' => 'Scheduled backup FAILED'] as $script => $marker) {
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($sandbox . '/cron/' . $script) . ' --dry-run 2>&1', $out, $code);
    $text = implode("\n", $out);
    check($code === 1 && str_contains($text, $marker), "{$script}: no config -> '{$marker}' and exit 1", [$code, $text]);
}

rm_tree($tmp);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
