#!/usr/bin/env php
<?php
/**
 * Dashboard review counts (2026-10-22) — DB-free.
 *
 *   - ReviewCounts::fromLists() counts the CAS Determinations lists, and
 *     StotTargetOrgans::needsOrgans() decides which STOT rows still need organs;
 *   - the cache: stored counts are read back while fresh (TTL), then not;
 *     a missing or broken file reads as nothing;
 *   - wiring: the dashboard shows the three counts only to users who can
 *     open CAS Determinations, no longer the published-SDS count; the
 *     determinations page stores the counts it computes.
 *
 * Run: php tests/Services/ReviewCountsTest.php
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';

use SDS\Services\ReviewCounts;
use SDS\Services\StotTargetOrgans as Stot;

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
        echo '       actual: ' . json_encode($actual) . "\n";
    }
}

echo "a. Counts\n";
check(Stot::needsOrgans(['has_se' => 1, 'stot_se_organs' => null]), 'classified for single exposure, no organs: needs organs');
check(!Stot::needsOrgans(['has_se' => 1, 'stot_se_organs' => 'eyes']), '... with organs: does not');
check(Stot::needsOrgans(['has_se' => 1, 'stot_se_organs' => 'eyes', 'has_re' => 1, 'stot_re_organs' => '']), 'classified for both, organs for one only: needs organs');
check(!Stot::needsOrgans(['has_se' => 0, 'has_re' => 0, 'stot_re_organs' => 'lungs']), 'organs entered but not classified: does not');
check(!Stot::needsOrgans(['has_re' => 1, 'stot_re_organs' => 'not_an_organ,lungs']), 'unknown organ keys are ignored, a valid one counts');
check(Stot::needsOrgans(['has_re' => 1, 'stot_re_organs' => 'not_an_organ']), '... only unknown keys: needs organs');
check(!Stot::needsOrgans(['has_re' => 1, 'stot_re_organs' => null, 'stot_re_not_specified' => 1]), 'organs marked "not specified on the vendor SDS": reviewed, not counted');
$c = ReviewCounts::fromLists([['cas_number' => 'a'], ['cas_number' => 'b']], [['cas_number' => 'c']], [
    ['has_se' => 1, 'stot_se_organs' => null], ['has_re' => 1, 'stot_re_organs' => 'lungs'], ['has_re' => 1, 'stot_re_organs' => null],
]);
check($c === ['needs' => 2, 'tsca' => 1, 'stot' => 2], 'fromLists: rows of each list, STOT rows needing organs', $c);

echo "\nb. Cache\n";
$dir = sys_get_temp_dir() . '/sds-review-counts-' . getmypid();
@mkdir($dir);
$now    = 1_800_000_000;
$stored = ReviewCounts::store(['needs' => 3, 'tsca' => 4, 'stot' => 5], $dir, $now);
check($stored['at'] === gmdate('Y-m-d H:i:s', $now) && $stored['needs'] === 3, 'store stamps the counts with the time (UTC)', $stored);
check(ReviewCounts::read($dir, $now + 60) === $stored, 'read back while fresh', ReviewCounts::read($dir, $now + 60));
check(ReviewCounts::read($dir, $now + ReviewCounts::TTL) === $stored, '... still at exactly TTL seconds');
check(ReviewCounts::read($dir, $now + ReviewCounts::TTL + 1) === null, '... older than TTL: not used (computed again)');
file_put_contents($dir . '/dashboard-review-counts.json', '{broken');
check(ReviewCounts::read($dir, $now) === null, 'a broken file reads as nothing');
@unlink($dir . '/dashboard-review-counts.json');
check(ReviewCounts::read($dir, $now) === null, 'no file reads as nothing');
$stored = ReviewCounts::store(['needs' => 1, 'tsca' => 0, 'stot' => 0], $dir . '/missing-subdir', $now);
check($stored['needs'] === 1 && ReviewCounts::read($dir . '/missing-subdir', $now) === null, 'an unwritable cache is ignored (counts still returned)');
check(glob($dir . '/*.tmp') === [], 'no temporary files left behind');
@rmdir($dir);

echo "\nc. Wiring\n";
$dash = (string) file_get_contents($basePath . '/src/Controllers/DashboardController.php');
$view = (string) file_get_contents($basePath . '/src/Views/dashboard/index.php');
$adm  = (string) file_get_contents($basePath . '/src/Controllers/AdminController.php');
check(str_contains($dash, "'reviewCounts' => can_read('cas_determinations') ? self::reviewCounts(isset(\$_GET['refresh'])) : null,"), 'counts only for users who can open CAS Determinations');
check(str_contains($dash, 'ReviewCounts::read()') && str_contains($dash, 'BulkPublishController::rawMaterialsOnClearedSheets($db)') && str_contains($dash, 'StotTargetOrgans::adminRows($db)'),
    'dashboard reads the stored counts, else computes them from the same lists as the page');
check(!str_contains($view, 'publishedCount') && !str_contains($view, 'Published SDSs</span>'), 'the published-SDS count is gone');
check(str_contains($view, "<?php elseif (!empty(\$reviewCounts)): // CAS Determinations access only (DashboardController) ?>")
    && str_contains($view, "'/determinations?tab=tsca'") && str_contains($view, "'/determinations?tab=stot'") && str_contains($view, "'/determinations',"),
    'three tiles, each linking to its tab, shown only with the counts');
check(str_contains($view, '<a href="/?refresh=1">Refresh</a>'), 'the counts show their time and can be refreshed');
check(str_contains($dash, "return ['unavailable' => true];") && str_contains($view, "<?php if (!empty(\$reviewCounts['unavailable'])): ?>"), 'a failure shows a note instead of breaking the dashboard');
check(str_contains($adm, '$needsDetermination = \SDS\Services\ReviewCounts::needsDeterminationRows($db);')
    && str_contains($adm, '\SDS\Services\ReviewCounts::store(\SDS\Services\ReviewCounts::fromLists($needsDetermination, $tscaReview, $stotRows));'),
    'CAS Determinations uses the same Needs Determination query and stores the counts it computed');
$det = (string) file_get_contents($basePath . '/src/Views/admin/determinations.php');
check(str_contains($det, '$missing = \SDS\Services\StotTargetOrgans::needsOrgans($r);'), 'the STOT tab filter uses the same rule as the dashboard');
check(str_contains($det, "\$stotOpen = count(array_filter(\$stotRows ?? [], [\\SDS\\Services\\StotTargetOrgans::class, 'needsOrgans']));"), '... and so does the STOT tab badge');
$both = ['has_se' => 1, 'stot_se_organs' => 'lungs', 'has_re' => 1, 'stot_re_organs' => null, 'rm_count' => 2];
check(ReviewCounts::fromLists([], [], [$both, ['has_re' => 1, 'stot_re_organs' => null, 'rm_count' => 1]])['stot'] === 2, 'classified both ways with organs for one only still counts (badge and tile alike)');

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
