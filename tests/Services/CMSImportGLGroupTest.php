#!/usr/bin/env php
<?php
/**
 * CMS import GL group test (DB-free, no CMS connection)
 *
 * Every CMS item in the Raw Material and Resale GL groups is imported as a
 * raw material, even when no formula uses it (2026-10-13: resale items that
 * were not recipe ingredients never reached the SDS system, so product
 * family rules could not place them). Checks:
 *   - RM_GL_GROUPS and the shared CMS query (both groups; costing-recipe
 *     items and alias rows skipped; alias targets listed first);
 *   - planGlGroupRawMaterials(): existing codes, exclusions (migration 063),
 *     the finished-good guard, one raw material per resale product (pack
 *     sizes share one row), Raw Material rows never collapsed;
 *   - import and preview share the query and the planner;
 *   - resaleAutoPublishBlock(): reviewed + (composition or hand-published);
 *   - the gate is wired into every automatic resale path (AliasPublisher,
 *     bulk publish eligibility, publish worker, GHS labels) and the manual
 *     resale publish enforces "reviewed" server-side;
 *   - bulk publish keeps hand-published resale sheets current and gives
 *     alias sheets only to the alias representative;
 *   - delete records an import exclusion, a manual create lifts it;
 *   - a GL-imported raw material that a recipe starts using gets an
 *     import-log row (Needs Details worklist).
 *
 * Run:
 *   php tests/Services/CMSImportGLGroupTest.php
 *
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);

require_once $basePath . '/vendor/autoload.php';

use SDS\Services\CMSImportService as CIS;
use SDS\Services\SDSReadinessService as RS;

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

function fn_body(string $src, string $signature): string
{
    preg_match('/' . preg_quote($signature, '/') . '.*?\R    }\R/s', $src, $m);
    return $m[0] ?? '';
}

echo "GL groups imported as raw materials\n";
check(CIS::RM_GL_GROUPS === ['Raw Material', 'Resale'], 'RM_GL_GROUPS is Raw Material + Resale', CIS::RM_GL_GROUPS);

$sql = preg_replace('/\s+/', ' ', CIS::glGroupRawMaterialSql());
check(str_contains($sql, "g.Description IN ('Raw Material', 'Resale')"), 'query selects both GL groups', $sql);
check(str_contains($sql, 'i.CostingRecipe IS NULL'), 'query skips items with a costing recipe', $sql);
check(str_contains($sql, 'i.ReplacedBy IS NULL'), 'query skips alias rows', $sql);
check(str_contains($sql, 'i.ItemCode IS NOT NULL'), 'query skips items without a code', $sql);
check(str_contains($sql, 'i.ItemCode, i.Description, g.Description AS GLGroupName'), 'query returns code, description and group name', $sql);
check(str_contains($sql, 'ORDER BY CASE WHEN EXISTS (SELECT 1 FROM CMS.dbo.Item a WHERE a.ReplacedBy = i.Item) THEN 0 ELSE 1 END, i.ItemCode'), 'alias targets are listed first', $sql);
check(!str_contains($sql, "= 'Raw Material'"), 'no leftover single-group filter', $sql);

echo "\nPlanner\n";
$row = static fn(string $code, string $group, string $desc = 'X'): array => ['ItemCode' => $code, 'Description' => $desc, 'GLGroupName' => $group];
$plan = CIS::planGlGroupRawMaterials(
    [
        $row('E3377-5GWR', 'Resale', 'DULL'),   // alias target, listed first
        $row('E3377-30F', 'Resale'),             // pack sibling -> skipped
        $row('CTA1074', 'Resale', 'COATING'),
        $row('CTA1084', 'Resale'),               // already a raw material
        $row('E5413-1GT', 'Resale'),             // base E5413 already exists (E5413-20)
        $row('E6350-1G-DEADCODE', 'Resale'),     // base is a finished good
        $row('E4931', 'Resale'),                 // code is a finished good
        $row('5562', 'Resale'),                  // excluded (deleted by an admin)
        $row('  ', 'Resale'),                    // blank
        $row('2AB0200-10', 'Raw Material'),
        $row('2AB0200-20', 'Raw Material'),      // Raw Material rows are never collapsed
        $row('CTA1074', 'Resale'),               // duplicate row
        $row(' CTU1135 ', 'Resale', 'UV GLOSS'),  // trimmed
    ],
    ['CTA1084' => true, 'E5413-20' => true],
    ['E6350' => true, 'E4931' => true],
    ['5562' => true]
);
$created = array_column($plan['create'], 'code');
check($created === ['E3377-5GWR', 'CTA1074', '2AB0200-10', '2AB0200-20', 'CTU1135'], 'creates the right rows in order', $created);
check(array_column($plan['skipped_siblings'], 'code') === ['E3377-30F', 'E5413-1GT'], 'resale pack siblings skipped (new and existing base)', $plan['skipped_siblings']);
check($plan['excluded'] === ['5562'], 'excluded code reported', $plan['excluded']);
check($plan['create'][0] === ['code' => 'E3377-5GWR', 'description' => 'DULL', 'group' => 'Resale'], 'row carries description and group', $plan['create'][0]);
check($plan['create'][4]['description'] === 'UV GLOSS', 'trimmed code keeps its description', $plan['create'][4]);
check(CIS::planGlGroupRawMaterials([], [], [], []) === ['create' => [], 'skipped_siblings' => [], 'excluded' => []], 'empty input -> empty plan');
$plan2 = CIS::planGlGroupRawMaterials(
    [$row('E3145-55F', 'Resale'), $row('E3145-5GWR', 'Resale'), $row('99% IPA-5GL', 'Raw Material'), $row('CTA1225', 'Resale')],
    [],
    [],
    ['E3145-30F' => true, '99% IPA-55M' => true]
);
check($plan2['excluded'] === ['E3145-55F', 'E3145-5GWR'], 'a deleted resale pack size excludes its other pack sizes', $plan2);
check(array_column($plan2['create'], 'code') === ['99% IPA-5GL', 'CTA1225'], 'Raw Material rows keep exact-code exclusion', $plan2['create']);

echo "\nImport and preview share the query and the planner\n";
$src = (string) file_get_contents($basePath . '/src/Services/CMSImportService.php');
check(substr_count($src, 'fetchAll(self::glGroupRawMaterialSql())') === 3, 'preview, recipe import and GL import all use glGroupRawMaterialSql()');
check(substr_count($src, 'self::planGlGroupRawMaterials(') === 2, 'import and preview both call planGlGroupRawMaterials()');
check(substr_count($src, '$this->loadImportExclusions()') === 2, 'import and preview both honour exclusions');
check(!preg_match("/Description\\s*=\\s*'Raw Material'/", $src), 'no other query filters on the Raw Material group alone');
$gl = fn_body($src, 'private function importRawMaterialsByGLGroup');
check(str_contains($gl, "rm_gl_group_created_by_group"), 'GL import counts new rows per group');
check(!str_contains($src, 'AuditService::log'), 'CMS import writes no audit_log rows (new raw materials stay unreviewed)');
$p2 = fn_body($src, 'private function importRawMaterials(');
check(str_contains($p2, 'loggedRmIds') && str_contains($p2, "\$this->upsertImportLog(\$code, (int) \$ing['ingredient_item_pk'], null, null, 'raw_material', \$existingRms[\$code], \$userId)"), 'an existing raw material a recipe uses gets an import-log row');
check(str_contains($p2, 'isset($resaleCodes[$code]) && isset($baseRm[$baseCode])') && str_contains($p2, '$rmMap[$code] = $baseRm[$baseCode];'), 'a recipe pack size of an existing resale product maps to its raw material');
check(str_contains($p2, '$baseRm[$baseCode] ??= $rmId;'), 'a raw material created in the recipe pass becomes the row for its base');
check(strpos($p2, 'if ($fgMatch)') < strpos($p2, 'isset($resaleCodes[$code])'), 'the finished-good guard runs before the pack-size mapping');
$pv = fn_body($src, 'public function preview(');
check(str_contains($pv, '(isset($previewResaleCodes[$code]) && isset($previewBases[$baseCode]))'), 'preview mirrors the pack-size mapping');

echo "\nResale automatic-publish rule\n";
check(RS::resaleAutoPublishBlock('CTA1074', true, false, false) === null, 'reviewed with composition -> allowed');
$rsSrc = (string) file_get_contents($basePath . '/src/Services/SDSReadinessService.php');
check(str_contains(fn_body($rsSrc, 'public static function resaleAutoPublishBlocks'), "AND COALESCE(change_summary, '') <> ?"), '"published by hand" ignores sheets bulk publish wrote');
check(RS::BULK_RESALE_CHANGE_SUMMARY === 'Bulk publish (resale)', 'bulk resale change summary constant');
check(RS::resaleAutoPublishBlock('CTA1074', true, true, true) === null, 'reviewed, empty, published by hand -> allowed');
$b = RS::resaleAutoPublishBlock('CTA1074', false, false, true);
check($b !== null && str_contains($b, 'CTA1074') && str_contains($b, 'not been reviewed'), 'unreviewed -> blocked (even with a sheet)', $b);
$b = RS::resaleAutoPublishBlock('CTA1074', true, true, false);
check($b !== null && str_contains($b, 'no composition'), 'reviewed but empty and never published -> blocked', $b);
check(str_contains((string) RS::resaleAutoPublishBlock('', false, false, false), 'this resale item'), 'blank code falls back to a generic name');

echo "\nGate wired into every resale path\n";
$ap = (string) file_get_contents($basePath . '/src/Services/AliasPublisher.php');
$pub = fn_body($ap, 'public static function publish(');
check(str_contains($pub, 'SDSReadinessService::resaleAutoPublishError((int) $res[\'rm\'][\'id\'], $db)'), 'AliasPublisher (shipped-SDS export / send queue) checks the resale rule');
check(strpos($pub, 'resaleAutoPublishError') < strpos($pub, 'computeBaseForResaleRawMaterial'), 'AliasPublisher checks before rendering');
$bp = (string) file_get_contents($basePath . '/src/Controllers/BulkPublishController.php');
$elig = fn_body($bp, 'public static function computeEligibleResaleItems');
check(str_contains($elig, 'SDSReadinessService::resaleAutoPublishBlocks($rmIds, $db, $reviewed)') && str_contains($elig, 'isset($resaleBlocks[$rmId])'), 'bulk eligibility blocks by the resale rule');
check(str_contains($elig, "sv.raw_material_id = rm.id") && str_contains($elig, 'rep.rep_id IS NOT NULL'), 'bulk candidates: alias representative OR own published resale sheet');
check(str_contains($elig, 'SELECT MIN(r2.id) AS rep_id'), 'alias representative = lowest id per base (AliasResolver rule)');
check(str_contains($bp, "(\$resale['alias_representative'] ?? true) ? self::deduplicateAliasesByBaseCode("), 'only the alias representative emits alias work items');
$wk = (string) file_get_contents($basePath . '/scripts/publish-worker.php');
check(str_contains($wk, 'SDSReadinessService::resaleAutoPublishError($rmId, $db)'), 'publish worker re-checks the resale rule');
check(str_contains($wk, 'SDSReadinessService::BULK_RESALE_CHANGE_SUMMARY'), 'publish worker stamps resale sheets with the shared constant');
check(str_contains($bp, "'resaleBlocked'       => array_slice(\$blockedResale, 0, 200),"), 'bulk page receives blocked resale items with reasons');
$bv = (string) file_get_contents($basePath . '/src/Views/admin/bulk-publish.php');
check(str_contains($bv, "e((string) (\$b['reason'] ?? ''))") && str_contains($bv, 'unreviewed, or no composition'), 'bulk page shows why resale items are blocked');
$lc = (string) file_get_contents($basePath . '/src/Controllers/LabelController.php');
check(str_contains($lc, "resaleAutoPublishError((int) \$resolved['rm_id']") && substr_count($lc, "'rm_id'        => \$rmId,") === 2, 'GHS labels check the resale rule for both resale branches');
check(strpos($lc, 'resaleAutoPublishError') < strpos($lc, "\$sdsData     = \$resolved['sds_builder']();"), 'labels check before building the sheet');
$sc = (string) file_get_contents($basePath . '/src/Controllers/SDSController.php');
$pr = fn_body($sc, 'public function publishResale(');
check(str_contains($pr, 'loadReviewedRmIds($db)[$rmId]'), 'manual resale publish enforces "reviewed" server-side');
check(!str_contains($pr, 'resaleAutoPublishError'), 'manual resale publish stays open for a reviewed, genuinely non-hazardous item');

echo "\nDelete sticks (migration 063)\n";
$mig = (string) @file_get_contents($basePath . '/migrations/063_cms_rm_import_exclusions.sql');
check(str_contains($mig, 'CREATE TABLE IF NOT EXISTS `cms_rm_import_exclusions`'), 'migration 063 creates the table, re-runnably');
check(str_contains($mig, "INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('063_cms_rm_import_exclusions');"), 'migration 063 records itself');
$rc = (string) file_get_contents($basePath . '/src/Controllers/RawMaterialController.php');
check(str_contains(fn_body($rc, 'public function delete('), 'INSERT IGNORE INTO cms_rm_import_exclusions'), 'deleting a raw material records an exclusion');
check(str_contains($rc, 'DELETE FROM cms_rm_import_exclusions WHERE item_code = ?'), 'creating a raw material by hand lifts the exclusion');
check(str_contains($src, 'SELECT item_code FROM cms_rm_import_exclusions'), 'import reads the exclusions');

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
