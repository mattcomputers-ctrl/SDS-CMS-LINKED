<?php
/**
 * DB-free checks for audit #59 — one clock:
 *
 *   - PublishClock: stored timestamps are UTC (nowUtc), the printed effective
 *     date is today in the admin time zone (todayLocal), and stored UTC values
 *     are shown in the admin time zone (display / localDateOfUtc / parseUtc).
 *   - Source assertions: App sets the MySQL session time zone to UTC; the
 *     bulk worker, the manual / SDS Updates publishers and the private-label
 *     publisher no longer stamp published_at with the PHP local clock or take
 *     the effective date from a UTC string.
 *
 * Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/PublishClockTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);

require_once $basePath . '/vendor/autoload.php';

use SDS\Services\PublishClock;

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

echo "PublishClock (admin time zone America/Chicago)\n";
date_default_timezone_set('America/Chicago');

// 20:30 CDT on 10/09/2026 = 01:30 UTC on 10/10/2026.
$ts = gmmktime(1, 30, 0, 10, 10, 2026);

check(PublishClock::nowUtc($ts) === '2026-10-10 01:30:00', 'nowUtc() is UTC', PublishClock::nowUtc($ts));
check(PublishClock::todayLocal($ts) === '2026-10-09', 'todayLocal(): an evening CDT run prints today, not tomorrow', PublishClock::todayLocal($ts));
check(PublishClock::localDateOfUtc('2026-10-10 01:30:00') === '2026-10-09', 'localDateOfUtc() converts a stored UTC time to the local date', PublishClock::localDateOfUtc('2026-10-10 01:30:00'));
check(PublishClock::display('2026-10-10 01:30:00', 'm/d/Y H:i') === '10/09/2026 20:30', 'display() shows a stored UTC time in the admin time zone', PublishClock::display('2026-10-10 01:30:00', 'm/d/Y H:i'));
check(PublishClock::display(null) === '', 'display(null) is empty');
check(PublishClock::display('') === '', "display('') is empty");
check(PublishClock::display('0000-00-00 00:00:00') === '', 'display(zero date) is empty');
check(PublishClock::display('not a date') === '', 'display(unparseable) is empty');
check(PublishClock::parseUtc('2026-10-10 01:30:00') === $ts, 'parseUtc() reads the value as UTC', PublishClock::parseUtc('2026-10-10 01:30:00'));
check(PublishClock::parseUtc('  ') === null, 'parseUtc(blank) is null');
check(PublishClock::todayLocal(gmmktime(3, 0, 0, 1, 15, 2027)) === '2027-01-14', 'CST (winter): 03:00 UTC is the previous local day', PublishClock::todayLocal(gmmktime(3, 0, 0, 1, 15, 2027)));
check(PublishClock::localDateOfUtc('') === PublishClock::todayLocal(), 'localDateOfUtc(blank) falls back to today');
check(PublishClock::DB_TIME_ZONE === '+00:00', 'DB_TIME_ZONE is UTC');

echo "\nSource assertions\n";
$src = static function (string $rel) use ($basePath): string {
    $s = @file_get_contents($basePath . '/' . $rel);
    return $s === false ? '' : $s;
};

$app = $src('src/Core/App.php');
check($app !== '', 'App.php readable');
check(strpos($app, 'PublishClock::DB_TIME_ZONE') !== false, 'App.php: MySQL session time zone is PublishClock::DB_TIME_ZONE');
check(strpos($app, "date('P')") === false, "App.php: no date('P') session offset");

$worker = $src('scripts/publish-worker.php');
check($worker !== '', 'publish-worker.php readable');
check(strpos($worker, "gmdate('Y-m-d')") === false, "publish-worker.php: no gmdate('Y-m-d') effective date");
check(strpos($worker, 'PublishClock::todayLocal()') !== false, 'publish-worker.php: effective date via PublishClock::todayLocal()');
check(strpos($worker, 'PublishClock::nowUtc()') !== false, 'publish-worker.php: published_at via PublishClock::nowUtc()');

foreach ([
    'src/Controllers/SDSController.php',
    'src/Controllers/SDSUpdateController.php',
    'src/Services/PrivateLabelPublisher.php',
] as $rel) {
    $s = $src($rel);
    check($s !== '', "{$rel} readable");
    foreach (["\$now = date('Y-m-d H:i:s');", "\$now      = date('Y-m-d H:i:s');", 'substr($now, 0, 10)'] as $needle) {
        check(strpos($s, $needle) === false, "{$rel}: no {$needle}");
    }
}

// ---------------------------------------------------------------------------
echo "\n{$checks} checks, {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
