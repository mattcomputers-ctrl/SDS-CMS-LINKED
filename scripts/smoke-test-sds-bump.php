#!/usr/bin/env php
<?php
/**
 * smoke-test-sds-bump.php — verify that SDS metadata writes preserve
 * raw_materials.updated_at, while content-field writes correctly bump
 * it.
 *
 * Exercises the three code paths the bulk-publish staleness signal
 * depends on:
 *
 *   A. RawMaterial::addSds()          — upload a vendor SDS only
 *   B. RawMaterial::markSdsConfirmed() — Supplier Confirmed Current
 *   C. RawMaterial::update() with voc_wt change
 *   D. addSds + update() with voc_wt change (combined)
 *   E. RawMaterial::setSdsNotRequired() — "No SDS required" toggled and
 *      toggled back (migration 064; skipped before the migration)
 *
 * Expected: A, B and E leave updated_at unchanged; C and D bump it.
 *
 * Mutates a single RM. Restores its original supplier_sds_path,
 * sds_last_confirmed_at, voc_wt, sds_not_required and updated_at at
 * the end, and removes the two raw_material_sds rows it creates
 * (matched by notes). The RM should be byte-identical to its starting
 * state after the script exits successfully. Test E calls the model
 * directly, so it writes no audit_log row; for the few seconds the
 * flag is flipped, bulk publish and auto-send treat the RM accordingly.
 *
 * Usage:
 *   php scripts/smoke-test-sds-bump.php [<raw_material_id>]
 *
 * If no ID is passed, the lowest-ID RM is used.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__);
require_once $basePath . '/vendor/autoload.php';
new \SDS\Core\App();

use SDS\Core\Database;
use SDS\Models\RawMaterial;

$db = Database::getInstance();

$rmId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($rmId === 0) {
    $row = $db->fetch("SELECT id FROM raw_materials ORDER BY id LIMIT 1");
    if ($row === null) { fwrite(STDERR, "No raw materials found.\n"); exit(1); }
    $rmId = (int) $row['id'];
}

// Migration 064 adds raw_materials.sds_not_required; Test E needs it.
$hasNrCol = RawMaterial::hasSdsNotRequiredColumn($db);
$nrSelect = $hasNrCol ? ', sds_not_required' : '';

$rm = $db->fetch(
    "SELECT id, internal_code, updated_at, sds_last_confirmed_at, supplier_sds_path, voc_wt{$nrSelect}
     FROM raw_materials WHERE id = ?",
    [$rmId]
);
if ($rm === null) { fwrite(STDERR, "RM #{$rmId} not found.\n"); exit(1); }

$orig = [
    'updated_at'            => $rm['updated_at'],
    'sds_last_confirmed_at' => $rm['sds_last_confirmed_at'],
    'supplier_sds_path'     => $rm['supplier_sds_path'],
    'voc_wt'                => $rm['voc_wt'],
];
if ($hasNrCol) {
    $orig['sds_not_required'] = (int) $rm['sds_not_required'];
}

echo "=== Smoke test: SDS bump suppression ===\n";
echo "RM #{$rm['id']} ({$rm['internal_code']})\n";
echo "Starting state:\n";
foreach ($orig as $k => $v) echo "  {$k} = " . var_export($v, true) . "\n";
echo "\n";

$passCount = 0;
$failCount = 0;
function pass(string $msg): void { global $passCount; echo "  PASS: {$msg}\n"; $passCount++; }
function fail(string $msg): void { global $failCount; echo "  FAIL: {$msg}\n"; $failCount++; }

function reload(int $rmId): array {
    global $nrSelect;
    return Database::getInstance()->fetch(
        "SELECT updated_at, sds_last_confirmed_at, voc_wt{$nrSelect} FROM raw_materials WHERE id = ?",
        [$rmId]
    );
}

$testMarker = 'smoke-' . bin2hex(random_bytes(4));

try {
    // MySQL TIMESTAMP is second-granular — sleep so any bump would be visible.
    sleep(1);

    // ── Test A ────────────────────────────────────────────────────
    echo "Test A: addSds alone (expect NO bump)\n";
    $before = reload($rmId);
    RawMaterial::addSds(
        $rmId,
        '/tmp/fake-sds-A-' . $testMarker . '.pdf',
        'fake-sds-A.pdf',
        1024,
        "{$testMarker} A",
        null,
        '2026-04-22'
    );
    $after = reload($rmId);
    echo "  updated_at before: {$before['updated_at']}\n";
    echo "  updated_at after:  {$after['updated_at']}\n";
    if ($before['updated_at'] === $after['updated_at']) pass('updated_at preserved');
    else                                                  fail('updated_at was bumped');
    if ($after['sds_last_confirmed_at'] === '2026-04-22') pass('sds_last_confirmed_at advanced to 2026-04-22');
    else                                                   fail('sds_last_confirmed_at not advanced (got ' . var_export($after['sds_last_confirmed_at'], true) . ')');
    echo "\n";

    sleep(1);

    // ── Test B ────────────────────────────────────────────────────
    echo "Test B: markSdsConfirmed (expect NO bump)\n";
    $before = reload($rmId);
    $today  = date('Y-m-d');
    RawMaterial::markSdsConfirmed($rmId, $today);
    $after = reload($rmId);
    echo "  updated_at before: {$before['updated_at']}\n";
    echo "  updated_at after:  {$after['updated_at']}\n";
    if ($before['updated_at'] === $after['updated_at']) pass('updated_at preserved');
    else                                                  fail('updated_at was bumped');
    if ($after['sds_last_confirmed_at'] === $today) pass("sds_last_confirmed_at advanced to {$today}");
    else                                             fail('sds_last_confirmed_at not advanced');
    echo "\n";

    sleep(1);

    // ── Test C ────────────────────────────────────────────────────
    echo "Test C: Model::update with voc_wt change (expect BUMP)\n";
    $before = reload($rmId);
    // Pick a distinctive voc_wt that won't collide with the original.
    $probe1 = (((float) ($orig['voc_wt'] ?? 0)) === 42.1234) ? 42.5678 : 42.1234;
    RawMaterial::update($rmId, [
        '_skip_lock_check' => true,
        'voc_wt'           => $probe1,
    ]);
    $after = reload($rmId);
    echo "  updated_at before: {$before['updated_at']}\n";
    echo "  updated_at after:  {$after['updated_at']}\n";
    if ($before['updated_at'] !== $after['updated_at']) pass('updated_at bumped');
    else                                                  fail('updated_at NOT bumped (regression)');
    echo "\n";

    sleep(1);

    // ── Test D ────────────────────────────────────────────────────
    echo "Test D: addSds + Model::update with voc_wt change (expect BUMP)\n";
    $before = reload($rmId);
    $probe2 = $probe1 + 1;
    RawMaterial::addSds(
        $rmId,
        '/tmp/fake-sds-D-' . $testMarker . '.pdf',
        'fake-sds-D.pdf',
        2048,
        "{$testMarker} D",
        null,
        '2026-04-23'
    );
    RawMaterial::update($rmId, [
        '_skip_lock_check' => true,
        'voc_wt'           => $probe2,
    ]);
    $after = reload($rmId);
    echo "  updated_at before: {$before['updated_at']}\n";
    echo "  updated_at after:  {$after['updated_at']}\n";
    if ($before['updated_at'] !== $after['updated_at']) pass('updated_at bumped');
    else                                                  fail('updated_at NOT bumped');
    echo "\n";

    // ── Test E ────────────────────────────────────────────────────
    echo "Test E: setSdsNotRequired toggle (expect NO bump)\n";
    if (!$hasNrCol) {
        echo "  SKIP: raw_materials.sds_not_required missing (migration 064 not applied)\n\n";
    } else {
        sleep(1);
        $target = $orig['sds_not_required'] !== 1;   // flip it
        $before = reload($rmId);
        $changed = RawMaterial::setSdsNotRequired($rmId, $target);
        $after = reload($rmId);
        echo "  sds_not_required:  {$before['sds_not_required']} -> {$after['sds_not_required']}\n";
        echo "  updated_at before: {$before['updated_at']}\n";
        echo "  updated_at after:  {$after['updated_at']}\n";
        if ($changed) pass('setSdsNotRequired reported a change');
        else          fail('setSdsNotRequired reported no change');
        if ((int) $after['sds_not_required'] === ($target ? 1 : 0)) pass('flag set to ' . ($target ? 1 : 0));
        else                                                          fail('flag not toggled');
        if ($before['updated_at'] === $after['updated_at']) pass('updated_at preserved');
        else                                                  fail('updated_at was bumped');

        sleep(1);
        $before = reload($rmId);
        $changed = RawMaterial::setSdsNotRequired($rmId, !$target);   // toggle back
        $after = reload($rmId);
        if ($changed && (int) $after['sds_not_required'] === $orig['sds_not_required']) pass('flag toggled back');
        else                                                                              fail('flag not toggled back');
        if ($before['updated_at'] === $after['updated_at']) pass('updated_at preserved on the way back');
        else                                                  fail('updated_at was bumped on the way back');
        if (!RawMaterial::setSdsNotRequired($rmId, !$target)) pass('setting the same value again reports no change');
        else                                                   fail('setting the same value again reported a change');
        echo "\n";
    }

} finally {
    // ── Restore ──────────────────────────────────────────────────
    echo "Restoring RM to original state...\n";
    $nrRestoreSql = $hasNrCol ? ",\n             sds_not_required      = ?" : '';
    $restoreParams = [
        $orig['supplier_sds_path'],
        $orig['sds_last_confirmed_at'],
        $orig['voc_wt'],
    ];
    if ($hasNrCol) {
        $restoreParams[] = $orig['sds_not_required'];
    }
    $restoreParams[] = $orig['updated_at'];
    $restoreParams[] = $rmId;
    $db->query(
        "UPDATE raw_materials
         SET supplier_sds_path     = ?,
             sds_last_confirmed_at = ?,
             voc_wt                = ?{$nrRestoreSql},
             updated_at            = ?
         WHERE id = ?",
        $restoreParams
    );
    $db->query(
        "DELETE FROM raw_material_sds WHERE raw_material_id = ? AND notes LIKE ?",
        [$rmId, $testMarker . '%']
    );
    $final = reload($rmId);
    echo "  Final updated_at: {$final['updated_at']} (original was {$orig['updated_at']})\n";
    if ($hasNrCol) {
        echo "  Final sds_not_required: {$final['sds_not_required']} (original was {$orig['sds_not_required']})\n";
    }
    echo "\n";
}

echo "=== Result: {$passCount} passed, {$failCount} failed ===\n";
exit($failCount === 0 ? 0 : 2);
