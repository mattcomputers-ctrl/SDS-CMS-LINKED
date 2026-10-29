<?php
/**
 * SDS Archive page test (DB-free)
 *
 * Until 2026-10-29 /admin/sds-archive never found an old version: it only
 * matched pdf_path LIKE 'public/generated-pdfs/%', but the installer's
 * config ('generated_pdfs' => __DIR__ . '/../public/generated-pdfs') makes
 * publishers store 'config/../public/generated-pdfs/...'. The page also
 * rendered without the app layout (no menu, no styles, no flash messages),
 * and the Purge button's onclick called form.submit(), which skipped the
 * "Are you sure?" confirm.
 *
 * Checks the generated-PDF directory test, the view's layout / classes /
 * confirm, and that purge chunks its UPDATE (native prepares cap a
 * statement at 65,535 placeholders).
 *
 * Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/SdsArchivePageTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);

require_once $basePath . '/vendor/autoload.php';

use SDS\Controllers\SdsArchiveController;

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
        echo '       ' . (is_string($actual) ? $actual : var_export($actual, true)) . "\n";
    }
}

$inDir = static function (string $path, string $dir): bool {
    $m = new ReflectionMethod(SdsArchiveController::class, 'isInDirectory');
    $m->setAccessible(true);
    return $m->invoke(null, $path, $dir);
};

echo "Generated-PDF directory test\n";
$base = '/var/www/sds-system';
foreach ([
    'installer config (config/../public/generated-pdfs)' => $base . '/config/../public/generated-pdfs',
    'default (public/generated-pdfs)'                    => $base . '/public/generated-pdfs',
    'trailing slash'                                     => $base . '/public/generated-pdfs/',
] as $label => $dir) {
    check($inDir($base . '/config/../public/generated-pdfs/A_v1.pdf', $dir) === true, "{$label}: stored config/../ path is inside");
    check($inDir($base . '/public/generated-pdfs/A_v1.pdf', $dir) === true, "{$label}: stored public/ path is inside");
    check($inDir($base . '/public/uploads/supplier-sds/X.pdf', $dir) === false, "{$label}: supplier SDS upload is outside");
    check($inDir($base . '/config/../public/generated-pdfs/../uploads/X.pdf', $dir) === false, "{$label}: ../ escape is outside");
    check($inDir($base . '/public/generated-pdfs-old/X.pdf', $dir) === false, "{$label}: sibling dir with the same prefix is outside");
    check($inDir($base . '/public/generated-pdfs', $dir) === false, "{$label}: the directory itself is not a file inside it");
}

echo "\nOld-version query\n";
$ctl = str_replace("\r\n", "\n", (string) file_get_contents($basePath . '/src/Controllers/SdsArchiveController.php'));
check(!preg_match("/LIKE\\s+'public\\/generated-pdfs/", $ctl), "no anchored LIKE 'public/generated-pdfs/%' (misses config/../ paths)");
check(str_contains($ctl, "LIKE '%generated-pdfs/%'"), 'SQL pre-filter matches either stored form');
check(str_contains($ctl, 'self::isInDirectory('), 'rows are re-checked against the configured generated_pdfs directory');

echo "\nPurge / generate robustness\n";
preg_match('/function purge\(\).*?\n    }\n/s', $ctl, $purge);
preg_match('/function generate\(\).*?\n    }\n/s', $ctl, $generate);
check(isset($purge[0]) && str_contains($purge[0], 'array_chunk($ids'), 'purge UPDATE is chunked');
check(isset($purge[0]) && !preg_match('/array_fill\(0,\s*count\(\$ids\)/', $purge[0]), 'purge never binds every id in one statement');
check(isset($purge[0]) && str_contains($purge[0], 'set_time_limit(0)'), 'purge lifts the time limit');
check(isset($generate[0]) && str_contains($generate[0], 'set_time_limit(0)'), 'generate lifts the time limit');

echo "\nView\n";
$view = (string) file_get_contents($basePath . '/src/Views/admin/sds-archive.php');
check(str_contains($view, "dirname(__DIR__) . '/layouts/main.php'"), 'includes the app layout (menu, styles, flash messages)');
check(str_contains($view, "dirname(__DIR__) . '/layouts/footer.php'"), 'includes the layout footer');
check(!str_contains($view, '.submit()'), 'no script submits a form directly (that skips onsubmit confirms)');
check((bool) preg_match('/action="\/admin\/sds-archive\/purge"\s+onsubmit="if \(!confirm\(/', $view), 'purge form asks for confirmation before submitting');

$css = (string) file_get_contents($basePath . '/public/css/app.css');
preg_match_all('/class="([^"<]+)"/', $view, $m);
$missing = [];
foreach ($m[1] as $list) {
    foreach (preg_split('/\s+/', trim($list)) as $cls) {
        if ($cls !== '' && !preg_match('/\.' . preg_quote($cls, '/') . '(?![\w-])/', $css)) {
            $missing[$cls] = true;
        }
    }
}
check($missing === [], 'every class in the view exists in public/css/app.css', implode(', ', array_keys($missing)));

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
