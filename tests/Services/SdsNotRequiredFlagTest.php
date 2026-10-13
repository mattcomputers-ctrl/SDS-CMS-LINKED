#!/usr/bin/env php
<?php
/**
 * "No SDS required" flag test (migration 064; DB-free)
 *
 * Owner request (2026-10-13): non-chemicals the CMS sync imports from the
 * Resale GL group (pails, lids, colour guides, tools) are flagged as not
 * needing an SDS and then ignored, instead of being deleted. Checks:
 *   - the pure helpers: RawMaterial::baseKey(), isSdsNotRequired(),
 *     sdsNotRequiredMessage(), SDSReadinessService::resaleAutoPublishBlock()
 *     (flag checked first; the 4-argument calls are unchanged), the
 *     endpoint's same-site return_to check;
 *   - migration 064: guarded, re-runnable, DDL only, records itself;
 *   - the flag is metadata: never in RawMaterial::update()'s $allowed,
 *     written only by setSdsNotRequired() with updated_at = updated_at;
 *   - flagging is not a data review (loadReviewedRmIds and the worklists'
 *     "saved by a user" checks ignore the flag's audit actions);
 *   - cron-path code never names the column (works before 064 runs);
 *   - wiring: bulk candidates, publish worker, AliasPublisher, the manual
 *     resale actions, GHS labels, auto-send, the send queue, the
 *     worklists, reports, lookups, the flag endpoints, routes, the
 *     permission key, the forms and the quick "Not a chemical" buttons;
 *   - formula behaviour is unchanged (the formula engine never reads it).
 *
 * Run:
 *   php tests/Services/SdsNotRequiredFlagTest.php
 *
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);

require_once $basePath . '/vendor/autoload.php';

use SDS\Models\RawMaterial as RM;
use SDS\Services\AliasResolver;
use SDS\Services\PermissionService;
use SDS\Services\SDSReadinessService as RS;
use SDS\Services\SdsNotRequiredException;

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

/** strpos that never returns false: a missing needle sorts last. */
function pos_of(string $hay, string $needle): int
{
    $p = strpos($hay, $needle);
    return $p === false ? PHP_INT_MAX : $p;
}

/** Both present and $first before $then. */
function before(string $hay, string $first, string $then): bool
{
    return str_contains($hay, $first) && str_contains($hay, $then) && strpos($hay, $first) < strpos($hay, $then);
}

$src = static fn(string $rel): string => (string) @file_get_contents($basePath . '/' . $rel);

/* ======================================================================
 *  Pure helpers
 * ====================================================================*/

echo "baseKey(): the key sdsNotRequiredBases() uses\n";
foreach ([
    ['5562-5', '5562'],
    ['5562', '5562'],
    ['  misc ', 'MISC'],
    ['gg1004-a', 'GG1004'],
    ['PINK PLASTIC INK KNIFE', 'PINK PLASTIC INK KNIFE'],
    ['IR LASER PEN-EA', 'IR LASER PEN'],
    ['AB -5', 'AB'],
    ['-5', ''],
    ['', ''],
] as [$in, $want]) {
    check(RM::baseKey($in) === $want, "baseKey('{$in}') = '{$want}'", RM::baseKey($in));
}
foreach (['5562-5', 'GFI 4945', 'cta1074-5gwr', 'E3377-5GWR'] as $c) {
    check(RM::baseKey($c) === strtoupper(trim(AliasResolver::stripPack($c))), "baseKey('{$c}') agrees with AliasResolver::stripPack()");
}

echo "\nisSdsNotRequired(): DB-free row check\n";
check(RM::isSdsNotRequired(['sds_not_required' => 1]) === true, 'int 1 -> flagged');
check(RM::isSdsNotRequired(['sds_not_required' => '1']) === true, "PDO string '1' -> flagged");
check(RM::isSdsNotRequired(['sds_not_required' => 0]) === false, 'int 0 -> not flagged');
check(RM::isSdsNotRequired(['sds_not_required' => '0']) === false, "string '0' -> not flagged");
check(RM::isSdsNotRequired(['sds_not_required' => null]) === false, 'null -> not flagged');
check(RM::isSdsNotRequired(['id' => 5, 'internal_code' => 'X']) === false, 'missing key (row read before migration 064) -> not flagged');

echo "\nsdsNotRequiredMessage(): the one refusal message\n";
$msg = RM::sdsNotRequiredMessage('5562');
check(str_starts_with($msg, 'Raw material 5562 is marked "No SDS required" (not a chemical)'), 'names the code and the flag', $msg);
check(str_contains($msg, 'no resale SDS, alias SDS or GHS label is made for it'), 'says what is not made', $msg);
check(str_contains($msg, 'or for an alias that points at it'), 'covers aliases', $msg);
check(str_contains($msg, 'its shipments are not queued for SDS review'), 'covers sends', $msg);
check(str_contains($msg, 'clear "No SDS required" under Raw Materials > Edit'), 'says how to undo it', $msg);
check(str_starts_with(RM::sdsNotRequiredMessage('  MISC '), 'Raw material MISC is marked'), 'code is trimmed');
check(str_starts_with(RM::sdsNotRequiredMessage('   '), 'This raw material is marked "No SDS required"'), 'blank code -> generic subject');

echo "\nAudit actions\n";
check(RM::SDS_NOT_REQUIRED_AUDIT_ACTIONS === ['sds_not_required_set', 'sds_not_required_cleared'], 'SDS_NOT_REQUIRED_AUDIT_ACTIONS', RM::SDS_NOT_REQUIRED_AUDIT_ACTIONS);

echo "\nresaleAutoPublishBlock(): flag checked first, 4-argument calls unchanged\n";
check(RS::resaleAutoPublishBlock('CTA1074', true, false, false, true) === RM::sdsNotRequiredMessage('CTA1074'), 'flagged + reviewed + composition -> refused with the shared message');
check(RS::resaleAutoPublishBlock('CTA1074', true, true, true, true) === RM::sdsNotRequiredMessage('CTA1074'), 'flagged + published by hand -> still refused');
$b = RS::resaleAutoPublishBlock('CTA1074', false, true, false, true);
check($b === RM::sdsNotRequiredMessage('CTA1074') && !str_contains($b, 'not been reviewed'), 'flag beats "not reviewed" and "no composition"', $b);
check(RS::resaleAutoPublishBlock(' ', false, false, false, true) === RM::sdsNotRequiredMessage(''), 'blank code + flag -> generic message');
check(RS::resaleAutoPublishBlock('CTA1074', true, false, false) === null, '4 args: reviewed with composition -> allowed');
check(RS::resaleAutoPublishBlock('CTA1074', true, true, true) === null, '4 args: reviewed, empty, published by hand -> allowed');
check(str_contains((string) RS::resaleAutoPublishBlock('CTA1074', false, false, true), 'not been reviewed'), '4 args: unreviewed -> blocked');
check(str_contains((string) RS::resaleAutoPublishBlock('CTA1074', true, true, false), 'no composition'), '4 args: empty, never published -> blocked');
$same = true;
foreach ([true, false] as $r) {
    foreach ([true, false] as $e) {
        foreach ([true, false] as $p) {
            $same = $same && RS::resaleAutoPublishBlock('X1', $r, $e, $p) === RS::resaleAutoPublishBlock('X1', $r, $e, $p, false);
        }
    }
}
check($same, '5th argument false = the old 4-argument result, all 8 combinations');
$param = (new ReflectionMethod(RS::class, 'resaleAutoPublishBlock'))->getParameters()[4] ?? null;
check($param !== null && $param->getName() === 'sdsNotRequired' && $param->isOptional() && $param->getDefaultValue() === false
    && (string) $param->getType() === 'bool', '5th parameter is "bool $sdsNotRequired = false"');

echo "\nSdsNotRequiredException\n";
$rc = new ReflectionClass(SdsNotRequiredException::class);
check($rc->isFinal() && $rc->isSubclassOf(\RuntimeException::class), 'final, extends RuntimeException (catch (\Throwable) callers keep working)');

echo "\nPermission key\n";
check(isset(PermissionService::PAGE_KEYS['rm_sds_not_required']), "PAGE_KEYS has 'rm_sds_not_required'");
check(str_contains(PermissionService::PAGE_KEYS['rm_sds_not_required'] ?? '', 'No SDS required'), 'label names the flag', PermissionService::PAGE_KEYS['rm_sds_not_required'] ?? null);
check(PermissionService::canEdit(null, 'rm_sds_not_required') === false, 'no user -> no capability');
$authMap = (new ReflectionClass(\SDS\Middleware\AuthMiddleware::class))->getConstant('URI_TO_PAGE_KEY');
check(is_array($authMap) && !in_array('rm_sds_not_required', $authMap, true), 'capability, not a page: no URI maps to it');
$publicPaths = (new ReflectionClass(\SDS\Middleware\AuthMiddleware::class))->getConstant('PUBLIC_PATHS');
check(is_array($publicPaths) && !in_array('/raw-materials', $publicPaths, true), 'the /raw-materials endpoints need a login (not a public prefix)');

echo "\nEndpoint return_to: same-site paths only\n";
$safe = new ReflectionMethod(\SDS\Controllers\RawMaterialController::class, 'safeReturnTo');
$safe->setAccessible(true);
$def = '/raw-materials/7/edit';
foreach ([
    ['/bulk-publish', '/bulk-publish'],
    ['/cms-import/incomplete#inventory', '/cms-import/incomplete#inventory'],
    ['/raw-materials?sds=required&page=2', '/raw-materials?sds=required&page=2'],
    ['//evil.example', $def],
    ['https://evil.example/', $def],
    ['javascript:alert(1)', $def],
    ['/\\evil.example', $def],
    ["/x\r\nLocation: https://evil.example", $def],
    ['', $def],
    [null, $def],
    [['/bulk-publish'], $def],
] as [$in, $want]) {
    $got = $safe->invoke(null, $in, $def);
    check($got === $want, 'return_to ' . json_encode($in, JSON_UNESCAPED_SLASHES) . ' -> ' . $want, $got);
}

/* ======================================================================
 *  Migration 064
 * ====================================================================*/

echo "\nMigration 064\n";
$files = glob($basePath . '/migrations/064_*.sql') ?: [];
check(count($files) === 1, 'exactly one 064 migration', array_map('basename', $files));
$mig = (string) @file_get_contents($files[0] ?? '');
$ver = basename($files[0] ?? '', '.sql');
check($ver === '064_rm_sds_not_required' && strlen($ver) <= 50, 'version 064_rm_sds_not_required fits schema_migrations.version', $ver);
// Statements only: drop "-- ..." comment lines.
$sql = preg_replace('/^\s*--.*$/m', '', $mig);
check(str_contains($sql, "TABLE_NAME = 'raw_materials' AND COLUMN_NAME = 'sds_not_required'")
    && str_contains($sql, 'INFORMATION_SCHEMA.COLUMNS') && str_contains($sql, 'IF(@col_exists = 0,') && str_contains($sql, "'DO 0'"),
    'ALTER guarded by an INFORMATION_SCHEMA check (re-runnable)');
check(str_contains($sql, 'PREPARE stmt FROM @sql;') && str_contains($sql, 'EXECUTE stmt;') && str_contains($sql, 'DEALLOCATE PREPARE stmt;'), 'PREPARE / EXECUTE / DEALLOCATE');
check(str_contains($sql, 'ALTER TABLE `raw_materials` ADD COLUMN `sds_not_required` TINYINT(1) NOT NULL DEFAULT 0'), 'column: TINYINT(1) NOT NULL DEFAULT 0');
check(substr_count(strtoupper($sql), 'ALTER TABLE') === 1, 'one ALTER TABLE');
check(!preg_match('/\bON\s+UPDATE\b/i', $sql), 'no ON UPDATE clause');
check(!preg_match('/\b(UPDATE\s+`?\w+`?\s+SET|DELETE\s+FROM|TRUNCATE|REPLACE\s+INTO|DROP\s+(TABLE|COLUMN))\b/i', $sql), 'DDL only: no UPDATE / DELETE / TRUNCATE / REPLACE / DROP (nothing bumped, nothing flagged)');
preg_match_all('/\bINSERT\b(?:\s+IGNORE)?\s+INTO\s+`?(\w+)`?/i', $sql, $ins);
check($ins[1] === ['schema_migrations'], 'only INSERT is into schema_migrations', $ins[1]);
check(substr_count($sql, "INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('{$ver}');") === 1, 'records itself once, version = file name');
check(!str_contains($sql, 'sds_not_required = 1') && !str_contains($sql, 'sds_not_required` = 1'), 'flags no item');

/* ======================================================================
 *  The flag is metadata
 * ====================================================================*/

echo "\nMetadata write (never a staleness bump)\n";
$model = $src('src/Models/RawMaterial.php');
$upd = fn_body($model, 'public static function update(int $id, array $data)');
preg_match('/\$allowed = \[(.*?)\];/s', $upd, $am);
check(($am[1] ?? '') !== '' && str_contains($am[1], "'combustible_dust'"), 'found RawMaterial::update() $allowed');
check(!str_contains($am[1] ?? "'sds_not_required'", "'sds_not_required'"), 'update() $allowed does not contain sds_not_required');
$set = fn_body($model, 'public static function setSdsNotRequired(int $id, bool $notRequired)');
check(str_contains($set, 'UPDATE raw_materials SET sds_not_required = ?, updated_at = updated_at WHERE id = ? AND sds_not_required <> ?'), 'setSdsNotRequired(): updated_at = updated_at, only when the value changes');
check(str_contains($set, '->rowCount() > 0'), 'setSdsNotRequired() reports whether it changed');
$create = fn_body($model, 'public static function create(array $data)');
check(str_contains($create, "if (!empty(\$data['sds_not_required'])) {") && str_contains($create, "\$insertData['sds_not_required'] = 1;"), 'create() writes the column only when set (CMS inserts work before 064)');
// Nothing but setSdsNotRequired() writes the column (src/ and cron/).
$writers = [];
foreach (['src', 'cron'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basePath . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() === 'php' && preg_match('/\bsds_not_required\s*=\s*\?/', (string) file_get_contents($f->getPathname()))) {
            $writers[] = str_replace('\\', '/', substr($f->getPathname(), strlen($basePath) + 1));
        }
    }
}
check($writers === ['src/Models/RawMaterial.php'] && substr_count($model, 'sds_not_required = ?') === 1, 'only RawMaterial::setSdsNotRequired() writes the column', $writers);
$rcSrc = $src('src/Controllers/RawMaterialController.php');
$ctlUpd = fn_body($rcSrc, 'public function update(string $id)');
check(before($ctlUpd, "unset(\$data['sds_not_required']);", 'RawMaterial::update((int) $id, $data);'), 'the main edit save drops a posted flag (it re-writes constituents)');

/* ======================================================================
 *  Flagging is not a data review
 * ====================================================================*/

echo "\nFlagging is not a review\n";
$rsSrc = $src('src/Services/SDSReadinessService.php');
$lr = fn_body($rsSrc, 'public static function loadReviewedRmIds(Database $db)');
check(str_contains($lr, 'AND action NOT IN (\'" . implode("\',\'", \SDS\Models\RawMaterial::SDS_NOT_REQUIRED_AUDIT_ACTIONS) . "\')'), 'loadReviewedRmIds() ignores the flag\'s audit actions');
check(!str_contains($lr, 'sds_not_required = 1') && !str_contains($lr, 'isSdsNotRequired'), 'loadReviewedRmIds() does not count flagged items as reviewed');
$apply = fn_body($rcSrc, 'private function applySdsRequirement(');
check(str_contains($apply, "\$flag ? 'sds_not_required_set' : 'sds_not_required_cleared'") && str_contains($apply, "AuditService::log('raw_material'"), 'the endpoint audits with exactly those actions');
$cis = $src('src/Services/CMSImportService.php');
$actSql = fn_body($cis, 'private static function sdsNotRequiredAuditActionsSql()');
check(str_contains($actSql, 'RawMaterial::SDS_NOT_REQUIRED_AUDIT_ACTIONS'), 'CMSImportService builds the list from the same constant');
$inc = fn_body($cis, 'public function getIncompleteRawMaterials()');
check(str_contains($inc, 'AND a.action NOT IN (" . self::sdsNotRequiredAuditActionsSql() . ")'), 'Needs Details: the flag is not "saved by a user"');
$gaps = fn_body($cis, 'public function getInventoryGaps()');
check(str_contains($gaps, 'AND a.action NOT IN (" . self::sdsNotRequiredAuditActionsSql() . ")) AS user_reviewed'), 'In Inventory: the flag is not "user_reviewed"');

/* ======================================================================
 *  Shared helpers
 * ====================================================================*/

echo "\nShared helpers\n";
$bases = fn_body($model, 'public static function sdsNotRequiredBases(');
check(str_contains($bases, 'SELECT MIN(id) AS rep_id') && str_contains($bases, "GROUP BY SUBSTRING_INDEX(internal_code, '-', 1)") && str_contains($bases, 'rep.rep_id = rm.id'), 'sdsNotRequiredBases(): representative = lowest id per base (AliasResolver rule)');
check(str_contains($bases, 'WHERE rm.sds_not_required = 1'), 'sdsNotRequiredBases(): the representative\'s own flag');
check(str_contains($bases, 'AND NOT EXISTS (') && str_contains($bases, 'f.is_current = 1') && str_contains($bases, "fg.product_code = SUBSTRING_INDEX(rm.internal_code, '-', 1)"), 'sdsNotRequiredBases(): never a finished good with a current formula');
check(str_contains($bases, 'catch (\Throwable $e)') && str_contains($bases, 'return [];') && str_contains($bases, 'self::baseKey('), 'sdsNotRequiredBases(): [] before 064, keys from baseKey()');
check(str_contains(fn_body($model, 'public static function sqlNeedsSds('), "? \"{\$alias}.sds_not_required = 0\" : '1=1'"), "sqlNeedsSds(): '1=1' before 064");
check(str_contains(fn_body($model, 'public static function sqlNoSdsRequired('), "? \"{\$alias}.sds_not_required = 1\" : '1=0'"), "sqlNoSdsRequired(): '1=0' before 064");
$hasCol = fn_body($model, 'public static function hasSdsNotRequiredColumn(');
check(str_contains($hasCol, 'INFORMATION_SCHEMA.COLUMNS') && str_contains($hasCol, 'static $has = null;') && str_contains($hasCol, 'catch (\Throwable $e)'), 'hasSdsNotRequiredColumn(): cached INFORMATION_SCHEMA check');
$blocks = fn_body($rsSrc, 'public static function resaleAutoPublishBlocks(');
check(str_contains($blocks, 'SELECT * FROM raw_materials WHERE id IN ({$ph})') && str_contains($blocks, '\SDS\Models\RawMaterial::isSdsNotRequired($r)'), 'resaleAutoPublishBlocks() reads the flag (SELECT *, works before 064)');
check(str_contains($blocks, 'isset($published[$id]), isset($notRequired[$id]));'), 'resaleAutoPublishBlocks() passes the flag (so AliasPublisher::publish, the worker re-check and labels refuse)');
$rrm = fn_body($rsSrc, 'public static function reviewRawMaterial(int $rmId)');
check(str_contains($rrm, '\SDS\Models\RawMaterial::isSdsNotRequired($rm)') && str_contains($rrm, "'sds_not_required'         => \$notRequired,")
    && str_contains($rrm, "'unreviewed'      => (\$isReviewed || \$notRequired) ? [] :"), 'reviewRawMaterial(): flag, shared message, no review step');

echo "\nCron path never names the column (runs before 064)\n";
$cronRe = '/\b[a-z_][a-z0-9_]*\.sds_not_required\b|\bsds_not_required\s*(?:=|<>|!=|IN\b)/i';
foreach ([
    'src/Services/CMSImportService.php',
    'src/Services/SDSAutoSendService.php',
    'src/Services/SDSReadinessService.php',
    'src/Services/AliasPublisher.php',
    'src/Services/AliasResolver.php',
    'src/Services/TSCAService.php',
    'scripts/publish-worker.php',
    'cron/cms-sync.php',
] as $f) {
    preg_match_all($cronRe, $src($f), $mm);
    check($mm[0] === [], "{$f}: no direct column reference", $mm[0]);
}
$bp = $src('src/Controllers/BulkPublishController.php');
$elig = fn_body($bp, 'public static function computeEligibleResaleItems(Database $db)');
preg_match_all($cronRe, $elig, $mm);
check($elig !== '' && $mm[0] === [], 'computeEligibleResaleItems(): no direct column reference', $mm[0]);

/* ======================================================================
 *  Wiring: publishing paths (task A)
 * ====================================================================*/

echo "\nBulk publish candidates\n";
$needs = 'WHERE " . \SDS\Models\RawMaterial::sqlNeedsSds(\'rm\') . "';
check(str_contains($elig, $needs), 'outer WHERE excludes flagged raw materials');
check(before($elig, ') rep ON rep.rep_id = rm.id', $needs) && before($elig, $needs, 'AND (rep.rep_id IS NOT NULL'), 'filter sits in the outer WHERE, not the alias-representative subquery');
$repSub = substr($elig, pos_of($elig, 'LEFT JOIN ('), max(0, pos_of($elig, ') rep ON rep.rep_id = rm.id') - pos_of($elig, 'LEFT JOIN (')));
check($repSub !== '' && !str_contains($repSub, 'sqlNeedsSds') && !str_contains($repSub, 'sds_not_required'), 'alias-representative subquery is flag-agnostic (a flagged representative keeps its base suppressed)');
check(before($elig, $needs, 'resaleAutoPublishBlocks($rmIds, $db)'), 'filtered before the gate, so a flagged item is neither eligible nor blocked');
$bv = $src('src/Views/admin/bulk-publish.php');
check(str_contains($bv, 'marked <strong>No SDS required</strong>') && str_contains($bv, 'not listed as blocked'), 'bulk page says flagged items are skipped');

echo "\nPublish worker\n";
$wk = $src('scripts/publish-worker.php');
$nrStart = pos_of($wk, '$nrKey = \'nr\' . $rmId;');
$nrEnd   = $nrStart === PHP_INT_MAX ? false : strpos($wk, 'try {', $nrStart);
$nrBlock = ($nrStart === PHP_INT_MAX || $nrEnd === false) ? '' : substr($wk, $nrStart, $nrEnd - $nrStart);
check($nrStart !== PHP_INT_MAX && str_contains($nrBlock, "\$db->fetch('SELECT * FROM raw_materials WHERE id = ?', [\$rmId])")
    && str_contains($nrBlock, '\SDS\Models\RawMaterial::isSdsNotRequired($nrRow)'), 'resale item re-checked (SELECT *, isSdsNotRequired, cached)');
check(str_contains($nrBlock, 'writeWorkerProgress($progressFile, $total, $i + 1, $published, $failed, $errors, false);') && str_contains($nrBlock, 'continue;')
    && !str_contains($nrBlock, '$failed++'), 'a flagged item is skipped quietly (progress advances, not failed)');
check(before($wk, '$nrKey = \'nr\' . $rmId;', 'SDSReadinessService::resaleAutoPublishError($rmId, $db)'), 'skip happens before the resale gate re-check');
check(before($wk, 'if ($isResale) {' . "\n" . '        $nrKey', '$nrKey = \'nr\' . $rmId;') || str_contains($wk, "if (\$isResale) {\r\n        \$nrKey"), 'skip applies to resale items only');

echo "\nAliasPublisher::ensurePublished\n";
$ap = $src('src/Services/AliasPublisher.php');
$ens = fn_body($ap, 'public static function ensurePublished(');
check(str_contains($ens, "\$res !== null && \$res['type'] === 'resale' && \\SDS\\Models\\RawMaterial::isSdsNotRequired((array) \$res['rm'])"), 'resale alias of a flagged raw material detected (formula aliases unaffected)');
check(before($ens, 'throw new SdsNotRequiredException(', '$have = self::onDisk(') && before($ens, 'throw new SdsNotRequiredException(', 'return $have;'), 'throws before existing sheets are returned (old sheets never sent or zipped)');
check(str_contains($ens, '\SDS\Models\RawMaterial::sdsNotRequiredMessage('), 'with the shared message');

echo "\nManual resale actions (SDSController)\n";
$sc = $src('src/Controllers/SDSController.php');
$refuse = fn_body($sc, 'private static function refuseIfSdsNotRequired(array $rm)');
check(str_contains($refuse, '\SDS\Models\RawMaterial::isSdsNotRequired($rm)') && str_contains($refuse, '\SDS\Models\RawMaterial::sdsNotRequiredMessage(')
    && str_contains($refuse, "redirect('/sds-review?rm_id=' . (int) \$rm['id']);"), 'refuseIfSdsNotRequired(): shared message, back to the readiness page');
foreach ([
    ['public function previewResale(string $rm_id)', '$generator = new SDSGenerator();'],
    ['public function editResale(string $rm_id)', '$generator = new SDSGenerator();'],
    ['public function saveResaleEdits(string $rm_id)', '$this->saveEditorPost('],
    ['public function publishResale(string $rm_id)', 'computeBaseForResaleRawMaterial($rmId)'],
] as [$sig, $work]) {
    $body = fn_body($sc, $sig);
    check(before($body, 'self::refuseIfSdsNotRequired($rm);', $work), preg_replace('/^public function (\w+).*$/', '$1', $sig) . '() refuses a flagged item before any work');
}
$pr = fn_body($sc, 'public function publishResale(string $rm_id)');
check(before($pr, 'self::refuseIfSdsNotRequired($rm);', 'loadReviewedRmIds($db)[$rmId]') && !str_contains($pr, 'resaleAutoPublishError'), 'publishResale(): flag before the review check; still no resaleAutoPublishError');
check(str_contains(fn_body($sc, 'private function publishResaleAliases('), "\$resolution['type'] !== 'resale' || (int) \$resolution['rm']['id'] !== \$rmId"), 'publishResaleAliases(): only aliases that resolve to this raw material');

echo "\nGHS labels\n";
$lc = $src('src/Controllers/LabelController.php');
$li = fn_body($lc, 'public function index()');
check(str_contains($li, '$noSdsBases = \SDS\Models\RawMaterial::sdsNotRequiredBases($db);'), 'picker loads sdsNotRequiredBases() once');
check(str_contains($li, "isset(\$noSdsBases[\\SDS\\Models\\RawMaterial::baseKey((string) \$a['internal_code_base'])])"), 'picker leaves out aliases whose base is flagged');
check(str_contains($li, 'isset($noSdsBases[\SDS\Models\RawMaterial::baseKey($code)])'), 'picker leaves out flagged raw materials');
check(substr_count($lc, "'rm_id'        => \$rmId,") === 2 && str_contains($lc, "resaleAutoPublishError((int) \$resolved['rm_id']"), 'generate() still refuses through the shared resale gate');

echo "\nAuto-send\n";
$as = $src('src/Services/SDSAutoSendService.php');
foreach (['private function processUnsentShipments(array &$results)', 'public function catchUpCustomer(int $customerId)'] as $sig) {
    $body = fn_body($as, $sig);
    $name = preg_replace('/^\w+ function (\w+).*$/', '$1', $sig);
    check(before($body, 'if ($this->isNoSdsItem($itemName, null)) {', 'could not be resolved to a finished good"'), "{$name}(): unresolved non-chemical skipped before queueing");
    check(before($body, 'if ($this->isNoSdsItem($itemName, $fgProductCode)) {', "\"Finished good '{\$fgProductCode}' not found in database\""), "{$name}(): base with no finished good checked before queueing");
    check(before($body, 'if ($this->aliasNotRequired) {', '$this->sdsUnavailableReason()'), "{$name}(): alias refused as No SDS required is skipped, not queued");
}
$sfo = fn_body($as, 'public function sendForOrderKeys(int $customerId, array $orderKeys)');
check(str_contains($sfo, 'if ($this->isNoSdsItem($itemName, null)) {') && str_contains($sfo, '$this->isNoSdsItem($itemName, $fgProductCode)') && str_contains($sfo, 'if ($this->aliasNotRequired) {'), 'sendForOrderKeys(): skips non-chemicals with a reason');
$gl = fn_body($as, 'private function getLatestPublishedSds(int $fgId, string $itemIdentifier)');
check(before($gl, 'catch (SdsNotRequiredException $e)', 'catch (\Throwable $e)') && str_contains($gl, '$this->aliasNotRequired  = true;'), 'getLatestPublishedSds(): catches SdsNotRequiredException first');
$noItem = fn_body($as, 'private function isNoSdsItem(string $itemCode, ?string $resolvedBase)');
check(str_contains($noItem, '\SDS\Models\RawMaterial::sdsNotRequiredBases($this->db)') && str_contains($noItem, '\SDS\Models\RawMaterial::baseKey($base)'), 'isNoSdsItem(): base set + baseKey()');
$retry = fn_body($as, 'private function retryPendingQueue(array &$results)');
check(before($retry, 'self::dismissNotRequiredPending($this->db);', 'SELECT q.*'), 'retryPendingQueue() dismisses flagged items before retrying');
$dis = fn_body($as, 'public static function dismissNotRequiredPending(Database $db)');
check(str_contains($dis, '\SDS\Models\RawMaterial::sdsNotRequiredBases($db)') && str_contains($dis, "WHERE status = 'pending'")
    && str_contains($dis, 'self::resolveProductCode($db, $itemId)') && str_contains($dis, 'self::dismissQueueItemNoSds('), 'dismissNotRequiredPending(): pending rows, same resolver, shared dismiss');
$one = fn_body($as, 'public static function dismissQueueItemNoSds(');
check(str_contains($one, "'status'      => 'dismissed'") && str_contains($one, "\"id = ? AND status = 'pending'\"")
    && str_contains($one, "self::clampReason('Dismissed: no SDS required. Was: ' . \$oldReason)"), 'dismissQueueItemNoSds(): dismissed, pending only, keeps the old reason');

echo "\nSDS send queue\n";
$sq = $src('src/Controllers/SDSSendQueueController.php');
check(before(fn_body($sq, 'public function index()'), 'SDSAutoSendService::dismissNotRequiredPending($db);', '$items = $db->fetchAll('), 'index() dismisses flagged items before listing');
$send = fn_body($sq, 'public function send(string $id)');
check(substr_count($send, '$this->dismissNoSds($item, $itemIdentifier, $db);') === 2 && str_contains($send, 'if (!$sdsVersion && $this->aliasNotRequired) {'), 'send() dismisses instead of failing (no finished good, or alias refused)');
$sfc = fn_body($sq, 'public function sendForCustomer()');
check(substr_count($sfc, 'SDSAutoSendService::dismissQueueItemNoSds($db, (int) $item[\'id\']') === 2 && str_contains($sfc, '$notRequired++;'), 'sendForCustomer() dismisses and counts them separately');
check(before(fn_body($sq, 'private function findPublishedSds('), 'catch (SdsNotRequiredException $e)', 'catch (\Throwable $e)'), 'findPublishedSds() catches SdsNotRequiredException first');

/* ======================================================================
 *  Wiring: worklists, import, reports, lookups (task B)
 * ====================================================================*/

echo "\nWorklists\n";
check(str_contains($inc, '(" . RawMaterial::sqlNeedsSds(\'rm\') . " OR " . self::sqlUsedInCurrentFormula(\'rm\') . ")'), 'Needs Details: flagged items dropped unless a current formula uses them');
check(str_contains(fn_body($cis, 'private static function sqlUsedInCurrentFormula('), 'f_nr.is_current = 1'), 'sqlUsedInCurrentFormula(): current formulas only');
check(str_contains($gaps, 'WHERE " . RawMaterial::sqlNeedsSds(\'rm\')'), 'In Inventory: flagged items dropped');
$ts = fn_body($cis, 'public function getTradeSecretRawMaterials()');
check(str_contains($ts, "\$r['sds_not_required'] === 0 || isset(\$inCurrentFormula[(int) \$r['id']])"), 'Trade Secret: flagged items dropped unless a current formula uses them');
$fif = fn_body($cis, 'public function getFlaggedRawMaterialsInFormulas()');
check(str_contains($fif, 'RawMaterial::sqlNoSdsRequired(\'rm\')') && str_contains($fif, 'f.is_current = 1'), 'getFlaggedRawMaterialsInFormulas(): flagged + current formula');
$cic = $src('src/Controllers/CMSImportController.php');
check(str_contains($cic, '$service->getFlaggedRawMaterialsInFormulas()') && str_contains($cic, "'flaggedInFormulas' => \$flaggedInFormulas,"), 'Incomplete page lists flagged items used in current formulas');
$stale = fn_body($src('src/Controllers/StaleRmSdsController.php'), 'public function index()');
check(str_contains($stale, "\$needsSds = \\SDS\\Models\\RawMaterial::sqlNeedsSds('rm');")
    && str_contains($stale, 'JOIN raw_materials rm ON rm.id = cur.raw_material_id AND {$needsSds}')
    && str_contains($stale, 'WHERE {$needsSds}'), 'Stale RM SDS: both branches drop flagged items (branch a in the JOIN)');
$tsca = fn_body($src('src/Services/TSCAService.php'), 'public static function reviewCandidates()');
check(str_contains($tsca, 'AND ({$needsSds} OR EXISTS (') && str_contains($tsca, 'f2.is_current = 1'), 'TSCA Review: flagged items dropped unless a current formula uses them');
$det = fn_body($src('src/Controllers/AdminController.php'), 'public function determinations()');
check(str_contains($det, "\$rmNeedsSds = \\SDS\\Models\\RawMaterial::sqlNeedsSds('rm');") && str_contains($det, 'AND ({$rmNeedsSds} OR EXISTS (') && str_contains($det, 'f2.is_current = 1'), 'CAS Determinations: flagged items dropped unless a current formula uses them');

echo "\nCMS import (formulas unchanged, flagged ingredients reported)\n";
check(str_contains(fn_body($cis, 'public function import(?int $userId)'), "'rm_flagged_in_formula' => [],"), 'import() reports rm_flagged_in_formula');
$p2 = fn_body($cis, 'private function importRawMaterials(');
check(str_contains($p2, "'SELECT rm.id FROM raw_materials rm WHERE ' . RawMaterial::sqlNoSdsRequired('rm')") && str_contains($p2, 'catch (\Throwable $e)'), 'flagged ids loaded with the guarded helper');
check(str_contains($p2, "\$rmMap[\$code] = \$existingRms[\$code];") && str_contains($p2, '$noteFlagged($code, $existingRms[$code]);')
    && str_contains($p2, '$rmMap[$code] = $baseRm[$baseCode];') && str_contains($p2, '$noteFlagged($code, $baseRm[$baseCode]);'), 'a recipe still maps to a flagged raw material, which is noted');
check(str_contains($src('cron/cms-sync.php'), "\$results['rm_flagged_in_formula']") && str_contains($src('src/Views/cms-import/results.php'), "\$results['rm_flagged_in_formula']"), 'sync log and results page print them');

echo "\nReports\n";
$rp = $src('src/Controllers/ReportController.php');
$ross = fn_body($rp, 'private function buildRossData()');
check(str_contains($ross, '$noSdsBases = RawMaterial::sdsNotRequiredBases($db);') && before($ross, 'if (isset($noSdsBases[RawMaterial::baseKey($code)])) {', '$totalLbs += $lbs;'), 'Ross: flagged direct consumption left out before total lbs');
check(str_contains($ross, "if (\$rm !== null && \$rm['no_sds']) {"), 'Ross: a flagged formula ingredient with no data is not "missing data"');
$p65 = fn_body($rp, 'private function buildProp65Data()');
check(str_contains($p65, '$noSdsBases    = RawMaterial::sdsNotRequiredBases($db);') && before($p65, 'if (isset($noSdsBases[RawMaterial::baseKey($productCode)])) {', '$unevaluated[$itemCode] = $description;'), 'Prop 65: flagged items not listed as unevaluated');
$hap = fn_body($rp, 'private function buildReportData()');
check(str_contains($hap, '$noSdsBases    = RawMaterial::sdsNotRequiredBases($db);') && before($hap, 'if (isset($noSdsBases[RawMaterial::baseKey($productCode)])) {', '$totalShippedLbs += $qtyShipped;'), 'HAP/VOC: flagged items left out of lines and totals');
$zip = fn_body($rp, 'public function exportShippedSds()');
check(str_contains($zip, 'if (isset($noSdsBases[RawMaterial::baseKey($resolved ?? $this->stripPackExtension($itemName))])) {'), 'shipped-SDS ZIP: flagged items skipped (no _MISSING_ITEMS row)');
check(before($zip, 'catch (\SDS\Services\SdsNotRequiredException $e)', 'catch (\Throwable $e)'), 'shipped-SDS ZIP: alias refusal skipped silently, caught before \Throwable');
check(str_contains($src('src/Views/reports/index.php'), 'Items marked No SDS required (not chemicals) are left out'), 'Reports page says so');

echo "\nLookups and badges\n";
check(str_contains(fn_body($src('src/Models/FinishedGood.php'), 'public static function lookupAllAliases('), 'RawMaterial::sdsNotRequiredBases($db)'), 'Lookup badges aliases of flagged bases');
check(str_contains(fn_body($src('src/Controllers/AliasController.php'), 'public function index()'), '\SDS\Models\RawMaterial::sdsNotRequiredBases($db)'), 'Product Aliases badges aliases of flagged bases');
$srv = $src('src/Views/sds-review/index.php');
check(str_contains($srv, "\$noSds = !empty(\$review['sds_not_required']);") && str_contains($srv, "empty(\$review['unreviewed']) && !\$noSds && empty(\$review['company_emergency_phone_error'])"), 'readiness page hides the Publish form for a flagged item');
check(str_contains($src('src/Controllers/SDSReviewController.php'), '\SDS\Models\RawMaterial::isSdsNotRequired($r)'), 'readiness search badges flagged items');

/* ======================================================================
 *  Endpoints, routes, forms, quick buttons
 * ====================================================================*/

echo "\nFlag endpoints (RawMaterialController)\n";
foreach (['public function setSdsRequirement(string $id)', 'public function bulkSetSdsRequirement()'] as $sig) {
    $body = fn_body($rcSrc, $sig);
    $name = preg_replace('/^public function (\w+).*$/', '$1', $sig);
    check(str_contains($body, "self::safeReturnTo(\$_POST['return_to'] ?? null,"), "{$name}(): return_to validated");
    check(before($body, "if (!can_edit('rm_sds_not_required')) {", 'CSRF::validateRequest();') && before($body, 'CSRF::validateRequest();', '$this->applySdsRequirement('), "{$name}(): capability, then CSRF, then the write");
}
check(str_contains(fn_body($rcSrc, 'public function bulkSetSdsRequirement()'), "(array) (\$_POST['ids'] ?? [])"), 'bulk: ids[] sanitised to positive ints');
check(str_contains($apply, 'RawMaterial::setSdsNotRequired($rmId, $flag);') && str_contains($apply, 'RawMaterial::getCurrentFormulaProducts($rmId)')
    && str_contains($apply, '$this->dismissNotRequiredSends();'), 'applySdsRequirement(): metadata write, formula products audited, sends dismissed');
check(before(fn_body($rcSrc, 'private function dismissNotRequiredSends()'), 'try {', 'SDSAutoSendService::dismissNotRequiredPending(Database::getInstance())'), 'send-queue clean-up never breaks the save');
check(str_contains(fn_body($rcSrc, 'public function store()'), "\$data['sds_not_required'] = can_edit('rm_sds_not_required') && (int) (\$data['sds_not_required'] ?? 0) === 1 ? 1 : 0;"), 'create: only with the capability');

echo "\nRoutes\n";
$app = $src('src/Core/App.php');
check(str_contains($app, "\$router->post('/raw-materials/sds-requirement/bulk', 'RawMaterialController@bulkSetSdsRequirement');"), 'POST /raw-materials/sds-requirement/bulk');
check(str_contains($app, "\$router->post('/raw-materials/{id}/sds-requirement', 'RawMaterialController@setSdsRequirement');"), 'POST /raw-materials/{id}/sds-requirement');
check(before($app, "'/raw-materials/sds-requirement/bulk'", "'/raw-materials/{id}/sds-requirement'"), 'bulk route registered first');

echo "\nForms\n";
$form = $src('src/Views/raw-materials/form.php');
check(str_contains($form, "<?php if (!\$isEdit && can_edit('rm_sds_not_required')): ?>"), 'create-form checkbox gated by the capability');
check(str_contains($form, "<?php if (\$nr['can_set']): ?>") && str_contains($form, 'action="/raw-materials/<?= (int) $item[\'id\'] ?>/sds-requirement"'), 'edit card posts to the endpoint, only with the capability');
check(before($form, 'id="sds-requirement"', 'id="rawMaterialForm"'), 'edit card is its own form, outside the main form');
$list = $src('src/Views/raw-materials/index.php');
check(str_contains($list, "\$canFlag = can_edit('rm_sds_not_required');") && str_contains($list, 'action="/raw-materials/sds-requirement/bulk"')
    && str_contains($list, 'csrf_field()') && str_contains($list, 'confirm('), 'list bulk action gated, CSRF, confirm');

echo "\nQuick \"Not a chemical\" buttons\n";
foreach ([
    'src/Views/admin/bulk-publish.php'   => '/bulk-publish',
    'src/Views/cms-import/incomplete.php' => '/cms-import/incomplete',
    'src/Views/stale-rm-sds/index.php'   => '/stale-rm-sds',
] as $file => $ret) {
    $v = $src($file);
    $form = substr($v, pos_of($v, '/sds-requirement"'), 1200);
    check(str_contains($v, "can_edit('rm_sds_not_required')"), "{$file}: gated by the capability");
    check(str_contains($v, '/sds-requirement"') && str_contains($form, 'csrf_field()')
        && str_contains($form, '<input type="hidden" name="sds_not_required" value="1">'), "{$file}: POST with CSRF and sds_not_required=1");
    check(str_contains($v, "'Mark ' . ") && str_contains($form, 'confirm('), "{$file}: confirm() names the code");
    check(str_contains($v, $ret), "{$file}: comes back to {$ret}");
}

/* ======================================================================
 *  Formula behaviour unchanged
 * ====================================================================*/

echo "\nFormula behaviour unchanged\n";
foreach (['src/Services/FormulaCalcService.php', 'src/Services/SDSGenerator.php', 'src/Services/HazardEngine.php', 'src/Models/Formula.php'] as $f) {
    $s = $src($f);
    check($s !== '' && !preg_match('/sds_not_required|SdsNotRequired|sdsNotRequired/', $s), "{$f} never reads the flag");
}

echo "\nImported items always start as needing an SDS (owner decision 2026-10-13)\n";
$mig064 = (string) file_get_contents($basePath . '/migrations/064_rm_sds_not_required.sql');
check(str_contains($mig064, '`sds_not_required` TINYINT(1) NOT NULL DEFAULT 0'), 'column defaults to 0 = SDS required');
check(!preg_match('/\bUPDATE\b/i', preg_replace('/^--.*$/m', '', $mig064)), 'migration 064 flags nothing');
$cisAll = $src('src/Services/CMSImportService.php');
preg_match_all('/RawMaterial::create\(\[(.*?)\]\);/s', $cisAll, $creates);
check(count($creates[1]) >= 2, 'found the CMS import RawMaterial::create() calls (recipe and GL-group paths)', count($creates[1]));
$createsPassFlag = array_filter($creates[1], static fn(string $c): bool => str_contains($c, 'sds_not_required'));
check(!str_contains($cisAll, 'setSdsNotRequired') && $createsPassFlag === [], 'the CMS import never sets the flag (its creates never pass it, nothing toggles it)');
check(!preg_match('/UPDATE\s+raw_materials\s+SET[^"]*sds_not_required/i', $cisAll), 'the CMS metadata refresh never writes the flag');
$autoWriters = [];
foreach (['src/Services', 'cron', 'scripts'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basePath . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() === 'php' && str_contains((string) file_get_contents($f->getPathname()), 'setSdsNotRequired(')) {
            $autoWriters[] = str_replace('\\', '/', substr($f->getPathname(), strlen($basePath) + 1));
        }
    }
}
check($autoWriters === ['scripts/smoke-test-sds-bump.php'], 'no service, cron job or script sets the flag (the smoke test toggles and restores it)', $autoWriters);
$storeSrc = fn_body($rcSrc, 'public function store()');
check(str_contains($storeSrc, "can_edit('rm_sds_not_required') && (int) (\$data['sds_not_required'] ?? 0) === 1 ? 1 : 0"), 'a hand-created item is flagged only when a permitted user ticks the box');

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
