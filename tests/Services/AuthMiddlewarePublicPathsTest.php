#!/usr/bin/env php
<?php
/**
 * AuthMiddleware public-path test (DB-free)
 *
 * Until 2026-10-13 the whole '/raw-materials' prefix was public, so the raw
 * material list, edit pages (compositions, CAS numbers, percentages), the
 * constituents page and the CAS lookup opened without a login, and signed-in
 * users skipped the raw_materials permission check. Only the supplier SDS
 * PDFs that the public RM SDS Book links to may stay public, and only for
 * GET/HEAD. Checks isPublicPath(), isSdsBookPath() and resolvePageKey().
 *
 * Run:
 *   php tests/Services/AuthMiddlewarePublicPathsTest.php
 *
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);

require_once $basePath . '/vendor/autoload.php';

use SDS\Middleware\AuthMiddleware;

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

$mw  = (new ReflectionClass(AuthMiddleware::class))->newInstanceWithoutConstructor();
$call = static function (string $method, string $uri, string $httpMethod = 'GET') use ($mw) {
    $_SERVER['REQUEST_METHOD'] = $httpMethod;
    $m = new ReflectionMethod(AuthMiddleware::class, $method);
    $m->setAccessible(true);
    return $m->invoke($mw, $uri);
};

echo "Still public\n";
foreach (['/login', '/logout', '/rm-sds-book', '/css/app.css', '/raw-materials/12/sds', '/raw-materials/sds-version/345'] as $uri) {
    check($call('isPublicPath', $uri) === true, "{$uri} is public");
}
check($call('isPublicPath', '/raw-materials/12/sds', 'HEAD') === true, 'HEAD on a supplier SDS PDF is public');

echo "\nNeeds a login\n";
foreach ([
    '/raw-materials',
    '/raw-materials/create',
    '/raw-materials/12/edit',
    '/raw-materials/12/constituents',
    '/raw-materials/cas-lookup',
    '/raw-materials/12/sds/',
    '/raw-materials/12/sds/extra',
    '/raw-materials/abc/sds',
    '/raw-materials/sds-version/345/confirm-current',
    '/raw-materials-export',
] as $uri) {
    check($call('isPublicPath', $uri) === false, "{$uri} is not public");
}
foreach ([
    ['POST', '/raw-materials'],
    ['POST', '/raw-materials/12'],
    ['POST', '/raw-materials/12/delete'],
    ['POST', '/raw-materials/12/constituents'],
    ['POST', '/raw-materials/12/sds'],
    ['POST', '/raw-materials/sds-version/345'],
] as [$httpMethod, $uri]) {
    check($call('isPublicPath', $uri, $httpMethod) === false, "{$httpMethod} {$uri} is not public");
}

echo "\nSDS-Book-only users\n";
check($call('isSdsBookPath', '/sds-book') === true, '/sds-book allowed');
check($call('isSdsBookPath', '/sds/version/7/download') === true, '/sds/version/* allowed');
foreach (['/raw-materials', '/raw-materials/12/edit', '/raw-materials/cas-lookup'] as $uri) {
    check($call('isSdsBookPath', $uri) === false, "{$uri} not in the SDS-Book-only area");
}

echo "\nPermission key still applies to raw material pages\n";
check($call('resolvePageKey', '/raw-materials/12/edit') === 'raw_materials', 'edit page maps to raw_materials');
check($call('resolvePageKey', '/raw-materials') === 'raw_materials', 'list maps to raw_materials');

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
