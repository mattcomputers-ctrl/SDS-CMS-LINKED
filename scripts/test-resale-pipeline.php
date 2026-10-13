#!/usr/bin/env php
<?php
/**
 * test-resale-pipeline.php — read-only smoke test for the resale-alias
 * pipeline (AliasResolver, FormulaCalcService, SDSGenerator,
 * SDSReadinessService).
 *
 * Picks real rows from the current database, resolves / computes /
 * generates without writing anything back, and reports PASS/FAIL per
 * check. Run on the server after deploying migration 042 (and again
 * after 064, "No SDS required"):
 *
 *   sudo -u www-data php /var/www/sds-system/scripts/test-resale-pipeline.php
 *
 * The resale pick skips raw materials marked "No SDS required" (migration
 * 064). A separate section checks that one flagged item is refused by the
 * resale gate and is neither eligible nor blocked in bulk publish; it is
 * skipped when no item is flagged.
 *
 * Exit code 0 on all-pass, 1 on any failure. Safe to run in production
 * (no DB writes, no PDF writes).
 */

declare(strict_types=1);

$basePath = dirname(__DIR__);
require_once $basePath . '/vendor/autoload.php';
new \SDS\Core\App();

use SDS\Controllers\BulkPublishController;
use SDS\Core\Database;
use SDS\Models\RawMaterial;
use SDS\Services\AliasResolver;
use SDS\Services\SDSReadinessService;
use SDS\Services\FormulaCalcService;
use SDS\Services\SDSGenerator;

$db  = Database::getInstance();
$pdo = $db->getPdo();

$failures = 0;
$passes   = 0;

function header_row(string $label): void {
    echo "\n=== {$label} ===\n";
}
function pass(string $msg): void {
    global $passes;
    $passes++;
    echo "  [PASS] {$msg}\n";
}
function fail(string $msg): void {
    global $failures;
    $failures++;
    echo "  [FAIL] {$msg}\n";
}

// ─────────────────────────────────────────────────────────────────────────
//  0. Schema sanity — migration 042 columns present
// ─────────────────────────────────────────────────────────────────────────
header_row('Schema check (migration 042)');

$rmCol = $db->fetch(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'sds_versions'
        AND COLUMN_NAME  = 'raw_material_id'"
);
$rmCol !== null
    ? pass('sds_versions.raw_material_id column exists')
    : fail('sds_versions.raw_material_id MISSING — migration 042 not applied');

$fgCol = $db->fetch(
    "SELECT IS_NULLABLE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'sds_versions'
        AND COLUMN_NAME  = 'finished_good_id'"
);
($fgCol !== null && $fgCol['IS_NULLABLE'] === 'YES')
    ? pass('sds_versions.finished_good_id is nullable')
    : fail('sds_versions.finished_good_id is still NOT NULL — migration 042 didn\'t run');

header_row('Schema check (migration 064)');

$nrCol = $db->fetch(
    "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'raw_materials'
        AND COLUMN_NAME  = 'sds_not_required'"
);
if ($nrCol === null) {
    fail('raw_materials.sds_not_required MISSING — migration 064 not applied (run update.sh)');
} else {
    pass('raw_materials.sds_not_required column exists');
    (stripos((string) $nrCol['COLUMN_TYPE'], 'tinyint') === 0 && $nrCol['IS_NULLABLE'] === 'NO' && trim((string) $nrCol['COLUMN_DEFAULT'], "'") === '0')
        ? pass('raw_materials.sds_not_required is TINYINT NOT NULL DEFAULT 0')
        : fail('raw_materials.sds_not_required has the wrong definition: ' . json_encode($nrCol));
}
$flaggedTotal = (int) ($db->fetch('SELECT COUNT(*) AS n FROM raw_materials rm WHERE ' . RawMaterial::sqlNoSdsRequired('rm'))['n'] ?? 0);
echo "  (raw materials marked No SDS required: {$flaggedTotal})\n";

// ─────────────────────────────────────────────────────────────────────────
//  1. Find a real resale alias (aliases pointing at an RM, no FG formula)
//     The RM must be the one AliasResolver picks for the base (lowest id)
//     and must not be marked "No SDS required" (migration 064): a flagged
//     base gets no resale SDS, so it cannot exercise the pipeline.
// ─────────────────────────────────────────────────────────────────────────
header_row('Pick a resale alias from the catalog');

$resaleAlias = $db->fetch(
    "SELECT a.id, a.customer_code, a.internal_code, a.internal_code_base
     FROM aliases a
     INNER JOIN raw_materials rm
         ON SUBSTRING_INDEX(rm.internal_code, '-', 1) = a.internal_code_base
     WHERE rm.id = (
         SELECT MIN(r2.id) FROM raw_materials r2
         WHERE SUBSTRING_INDEX(r2.internal_code, '-', 1) = a.internal_code_base
     )
       AND " . RawMaterial::sqlNeedsSds('rm') . "
       AND NOT EXISTS (
         SELECT 1 FROM finished_goods fg
         INNER JOIN formulas f ON f.finished_good_id = fg.id AND f.is_current = 1
         WHERE fg.product_code = a.internal_code_base
     )
     ORDER BY a.id
     LIMIT 1"
);

if ($resaleAlias === null) {
    fail('No resale alias found in the catalog. Skipping resale-path checks.');
    echo "  (A resale alias is one whose internal_code_base points to an RM but has no FG with a current formula.)\n";
} else {
    pass(sprintf(
        'Found resale alias: customer_code=%s, points at internal_code_base=%s',
        $resaleAlias['customer_code'],
        $resaleAlias['internal_code_base']
    ));

    // ─── 2. AliasResolver resolves to type=resale ───
    header_row('AliasResolver — resale path');

    $resolved = AliasResolver::resolveByAliasId((int) $resaleAlias['id']);
    if ($resolved === null) {
        fail('AliasResolver::resolveByAliasId returned null');
    } else {
        ($resolved['type'] === 'resale')
            ? pass("resolveByAliasId returned type=resale")
            : fail("resolveByAliasId returned type={$resolved['type']} (expected resale)");

        (($resolved['rm']['id'] ?? null) !== null)
            ? pass("rm.id populated: {$resolved['rm']['id']}")
            : fail('rm row missing on resale resolution');

        ($resolved['fg'] === null)
            ? pass('fg is null on resale resolution')
            : fail('fg should be null on resale resolution but is not');
    }

    // Resolve by customer_code should match
    $byCode = AliasResolver::resolveByCustomerCode($resaleAlias['customer_code']);
    ($byCode !== null && $byCode['type'] === 'resale')
        ? pass("resolveByCustomerCode({$resaleAlias['customer_code']}) → resale")
        : fail('resolveByCustomerCode failed or wrong type');

    // Pack-ext stripped should resolve to the same RM
    $baseCode = AliasResolver::stripPack($resaleAlias['customer_code']);
    if ($baseCode !== $resaleAlias['customer_code']) {
        $byBase = AliasResolver::resolveByCustomerCode($baseCode);
        ($byBase !== null && $byBase['type'] === 'resale' && $byBase['rm']['id'] === $resolved['rm']['id'])
            ? pass("resolveByCustomerCode({$baseCode}) (pack-ext stripped) → same RM")
            : fail('pack-ext stripped lookup did not return the same RM');
    }

    // ─── 3. Direct RM lookup by base code ───
    header_row('AliasResolver — direct RM lookup');

    $rmResolved = AliasResolver::resolveByRawMaterialCode($resaleAlias['internal_code_base']);
    if ($rmResolved === null) {
        fail("resolveByRawMaterialCode({$resaleAlias['internal_code_base']}) returned null");
    } else {
        ($rmResolved['type'] === 'resale')
            ? pass('resolveByRawMaterialCode → type=resale')
            : fail("resolveByRawMaterialCode type={$rmResolved['type']}");

        ($rmResolved['alias'] === null)
            ? pass('alias is null on direct RM lookup')
            : fail('alias should be null on direct RM lookup');
    }

    // ─── 4. SDSReadinessService resale review ───
    header_row('SDSReadinessService — resale review shape');

    $rmId = (int) $resolved['rm']['id'];
    $review = SDSReadinessService::reviewRawMaterial($rmId);
    if ($review === null) {
        fail('reviewRawMaterial returned null');
    } else {
        !empty($review['is_resale'])
            ? pass('review.is_resale flag set')
            : fail('review.is_resale flag missing');

        ($review['total_rms'] === 1)
            ? pass('review.total_rms = 1 (single RM)')
            : fail("review.total_rms = {$review['total_rms']} (expected 1)");

        (is_array($review['resale_rm']) && ($review['resale_rm']['id'] ?? 0) === $rmId)
            ? pass('review.resale_rm.id matches')
            : fail('review.resale_rm.id missing or mismatched');

        (array_key_exists('sds_not_required', $review) && $review['sds_not_required'] === false)
            ? pass('review.sds_not_required is false (picked item needs an SDS)')
            : fail('review.sds_not_required missing or not false: ' . var_export($review['sds_not_required'] ?? null, true));
    }

    // ─── 5. FormulaCalcService resale composition ───
    header_row('FormulaCalcService — resale composition');

    try {
        $calc = (new FormulaCalcService())->calculateForRawMaterial($rmId);
        pass('calculateForRawMaterial succeeded');

        is_array($calc['composition'])
            ? pass('calc.composition is an array')
            : fail('calc.composition missing');

        is_array($calc['voc'])
            ? pass('calc.voc is an array')
            : fail('calc.voc missing');

        (($calc['formula']['lines'][0]['pct'] ?? null) === 100.0)
            ? pass('synthetic line is 100 %')
            : fail("synthetic line pct = " . var_export($calc['formula']['lines'][0]['pct'] ?? null, true));
    } catch (\Throwable $e) {
        fail('calculateForRawMaterial threw: ' . $e->getMessage());
    }

    // ─── 6. SDSGenerator — resale SDS (in-memory, no PDF) ───
    header_row('SDSGenerator — resale SDS in memory');

    try {
        $sds = (new SDSGenerator())->generateForResaleRawMaterial($rmId, 'en');
        pass('generateForResaleRawMaterial succeeded');

        (isset($sds['sections'][1]) && isset($sds['sections'][3]) && isset($sds['sections'][16]))
            ? pass('all 16 sections present (spot-checked 1/3/16)')
            : fail('sections 1/3/16 missing — structure incomplete');

        ($sds['meta']['finished_good_id'] === null || $sds['meta']['finished_good_id'] === 0)
            ? pass('meta.finished_good_id is null/0 (resale, no FG)')
            : fail("meta.finished_good_id = {$sds['meta']['finished_good_id']} (expected null/0)");

        (!empty($sds['sections'][1]['product_identifier']))
            ? pass("Section 1 product_identifier: {$sds['sections'][1]['product_identifier']}")
            : fail('Section 1 product_identifier empty');
    } catch (\Throwable $e) {
        fail('generateForResaleRawMaterial threw: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────
//  6b. Flagged non-chemical is ignored (migration 064). Read-only: the
//      resale gate and the bulk-publish candidate list are computed, not
//      run. Skipped when no raw material is marked "No SDS required".
// ─────────────────────────────────────────────────────────────────────────
header_row('Flagged non-chemical is ignored (migration 064)');

$flagged = $db->fetch(
    'SELECT rm.id, rm.internal_code FROM raw_materials rm WHERE ' . RawMaterial::sqlNoSdsRequired('rm') . ' ORDER BY rm.id LIMIT 1'
);
if ($flagged === null) {
    echo "  [SKIP] No raw material is marked No SDS required" . ($nrCol === null ? ' (migration 064 not applied)' : '') . ".\n";
} else {
    $flId   = (int) $flagged['id'];
    $flCode = (string) $flagged['internal_code'];
    $expect = RawMaterial::sdsNotRequiredMessage($flCode);
    echo "  Flagged item: {$flCode} (id {$flId})\n";

    $flRow = RawMaterial::findById($flId);
    ($flRow !== null && RawMaterial::isSdsNotRequired($flRow))
        ? pass('RawMaterial::isSdsNotRequired() reads the flag')
        : fail('RawMaterial::isSdsNotRequired() is false for a flagged row');

    $err = SDSReadinessService::resaleAutoPublishError($flId, $db);
    ($err === $expect)
        ? pass('resale gate refuses it with the shared "No SDS required" message')
        : fail('resale gate did not refuse it with the shared message: ' . var_export($err, true));

    $flReview = SDSReadinessService::reviewRawMaterial($flId);
    ($flReview !== null && $flReview['sds_not_required'] === true && $flReview['unreviewed'] === []
        && ($flReview['sds_not_required_message'] ?? null) === $expect)
        ? pass('readiness review: sds_not_required = true, no unreviewed step, shared message')
        : fail('readiness review does not show the flag: ' . var_export([
            'sds_not_required' => $flReview['sds_not_required'] ?? null,
            'unreviewed'       => isset($flReview['unreviewed']) ? count($flReview['unreviewed']) : null,
        ], true));

    try {
        $bulk = BulkPublishController::computeEligibleResaleItems($db);
        $eligibleIds = array_map('intval', array_column($bulk['eligible'], 'rm_id'));
        $blockedIds  = array_map('intval', array_column($bulk['blocked'], 'rm_id'));
        !in_array($flId, $eligibleIds, true)
            ? pass('bulk publish: not eligible')
            : fail("bulk publish lists flagged {$flCode} as eligible");
        !in_array($flId, $blockedIds, true)
            ? pass('bulk publish: not listed as blocked')
            : fail("bulk publish lists flagged {$flCode} as blocked");
    } catch (\Throwable $e) {
        fail('computeEligibleResaleItems threw: ' . $e->getMessage());
    }

    // Aliases, labels and sends go by the base's representative (lowest id).
    $pack = RawMaterial::packSiblings($flId);
    $isFgBase = $db->fetch(
        "SELECT 1 FROM finished_goods fg JOIN formulas f ON f.finished_good_id = fg.id AND f.is_current = 1
          WHERE fg.product_code = ? LIMIT 1",
        [$pack['base']]
    ) !== null;
    $bases = RawMaterial::sdsNotRequiredBases($db);
    if ($pack['representative_id'] === $flId && !$isFgBase) {
        isset($bases[RawMaterial::baseKey($flCode)])
            ? pass("base {$pack['base']} is in sdsNotRequiredBases() (aliases, labels and sends ignore it)")
            : fail("base {$pack['base']} is missing from sdsNotRequiredBases()");
        $flAlias = $db->fetch('SELECT id, customer_code FROM aliases WHERE internal_code_base = ? ORDER BY id LIMIT 1', [$pack['base']]);
        if ($flAlias !== null) {
            $res = AliasResolver::resolveByAliasId((int) $flAlias['id']);
            ($res !== null && $res['type'] === 'resale' && (int) $res['rm']['id'] === $flId && RawMaterial::isSdsNotRequired((array) $res['rm']))
                ? pass("alias {$flAlias['customer_code']} resolves to the flagged raw material (AliasPublisher refuses it)")
                : fail("alias {$flAlias['customer_code']} does not resolve to the flagged raw material");
        }
    } else {
        echo "  [SKIP] {$flCode} is not its base's representative, or the base is a finished good: base-level checks do not apply.\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────
//  7. Regression — formula-based alias still routes to formula path
// ─────────────────────────────────────────────────────────────────────────
header_row('Regression — formula alias still works');

$formulaAlias = $db->fetch(
    "SELECT a.id, a.customer_code, a.internal_code_base, fg.id AS fg_id
     FROM aliases a
     INNER JOIN finished_goods fg ON fg.product_code = a.internal_code_base
     INNER JOIN formulas f ON f.finished_good_id = fg.id AND f.is_current = 1
     LIMIT 1"
);

if ($formulaAlias === null) {
    echo "  [SKIP] No formula-based alias in the catalog — skipping regression check.\n";
} else {
    $resolved = AliasResolver::resolveByAliasId((int) $formulaAlias['id']);
    if ($resolved === null) {
        fail('Formula alias failed to resolve');
    } else {
        ($resolved['type'] === 'formula')
            ? pass("Formula alias {$formulaAlias['customer_code']} → type=formula")
            : fail("Formula alias returned type={$resolved['type']} (expected formula)");

        (($resolved['fg']['id'] ?? null) === (int) $formulaAlias['fg_id'])
            ? pass('fg.id matches the expected FG')
            : fail('fg.id did not match');

        ($resolved['rm'] === null)
            ? pass('rm is null on formula resolution')
            : fail('rm should be null on formula resolution');
    }
}

// ─────────────────────────────────────────────────────────────────────────
//  Summary
// ─────────────────────────────────────────────────────────────────────────
echo "\n=== Summary ===\n";
echo "  Passed: {$passes}\n";
echo "  Failed: {$failures}\n";

exit($failures === 0 ? 0 : 1);
