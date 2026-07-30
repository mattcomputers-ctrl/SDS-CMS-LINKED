#!/usr/bin/env php
<?php
/**
 * mark-repointed-formulas-stale.php — one-time follow-up to
 * fix-fg-as-raw-materials.php.
 *
 * That cleanup repointed formula lines from fake raw materials to real
 * finished-good components, changing the effective composition of the
 * affected formulas — but it touched no timestamps, so bulk publish's
 * staleness check (last publish vs formula created_at / RM updated_at)
 * never notices, and already-published SDSs stay outdated forever.
 *
 * This script finds every current formula with a finished-good
 * component line pointing at one of the affected base products and
 * bumps the formula's created_at to NOW. Bulk publish then treats the
 * product as changed and republishes it — still subject to the normal
 * eligibility rules (all RMs reviewed), so nothing incomplete gets
 * force-published.
 *
 * Usage:
 *   php scripts/mark-repointed-formulas-stale.php            # dry run
 *   php scripts/mark-repointed-formulas-stale.php --confirm  # apply
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SDS\Core\App;
use SDS\Core\Database;

new App();
$db = Database::getInstance();
$dryRun = !in_array('--confirm', $argv, true);

// Base products whose fake pack-extension RMs were removed by
// fix-fg-as-raw-materials.php on 2026-07-30.
$affectedCodes = [
    '2UW0178', '2UY0658', '3UC4764', '5545',
    'E1364', 'E1365', 'E1366', 'E1367', 'E1372',
    'E4402', 'E4403', 'E4404', 'E4405',
    'E4510', 'E4511', 'E4512', 'E4636', 'E4646',
    'E4839', 'E4840', 'E4841', 'E4842',
    'E4988', 'E4989', 'E5389',
    'E6452', 'E6453', 'E6455', 'E6456', 'E6457', 'E6458',
    'E6460', 'E6463', 'E6465',
    'E6573', 'E6574', 'E6575', 'E6576',
    'E6622', 'E6646', 'E7328', 'E7329', 'E7330',
    'UV2905',
];

if ($dryRun) {
    echo "=== DRY RUN — no changes will be made (pass --confirm to apply) ===\n\n";
}

$placeholders = implode(',', array_fill(0, count($affectedCodes), '?'));
$subFgs = $db->fetchAll(
    "SELECT id, product_code FROM finished_goods WHERE product_code IN ({$placeholders})",
    $affectedCodes
);
$subFgIds = array_map(fn($r) => (int) $r['id'], $subFgs);

if (empty($subFgIds)) {
    echo "None of the affected base products found. Nothing to do.\n";
    exit(0);
}

$idPlaceholders = implode(',', array_fill(0, count($subFgIds), '?'));

// Current formulas that reference one of the affected products as a
// sub-component, with their finished good and publish state.
$formulas = $db->fetchAll(
    "SELECT DISTINCT f.id AS formula_id, f.created_at, fg.id AS fg_id, fg.product_code,
            (SELECT MAX(sv.published_at) FROM sds_versions sv
             WHERE sv.finished_good_id = fg.id AND sv.alias_id IS NULL
               AND sv.status = 'published' AND sv.is_deleted = 0) AS last_published_at
     FROM formula_lines fl
     JOIN formulas f ON f.id = fl.formula_id AND f.is_current = 1
     JOIN finished_goods fg ON fg.id = f.finished_good_id
     WHERE fl.finished_good_component_id IN ({$idPlaceholders})
     ORDER BY fg.product_code",
    $subFgIds
);

if (empty($formulas)) {
    echo "No current formulas reference the affected products. Nothing to do.\n";
    exit(0);
}

$published = array_filter($formulas, fn($f) => $f['last_published_at'] !== null);
$unpublished = count($formulas) - count($published);

echo "Found " . count($formulas) . " current formula(s) referencing the affected base products:\n";
echo "  Already published (will republish once eligible): " . count($published) . "\n";
echo "  Never published (unaffected by this script):      {$unpublished}\n\n";

foreach ($formulas as $f) {
    $state = $f['last_published_at'] !== null
        ? "published {$f['last_published_at']}"
        : 'never published';
    echo "  - {$f['product_code']} (formula #{$f['formula_id']}, {$state})\n";
}

if ($dryRun) {
    echo "\nThis was a dry run. Pass --confirm to bump these formulas' timestamps.\n";
    exit(0);
}

$now = date('Y-m-d H:i:s');
$formulaIds = array_map(fn($f) => (int) $f['formula_id'], $formulas);
foreach (array_chunk($formulaIds, 200) as $chunk) {
    $ph = implode(',', array_fill(0, count($chunk), '?'));
    $db->query("UPDATE formulas SET created_at = ? WHERE id IN ({$ph})", array_merge([$now], $chunk));
}

echo "\nDone. " . count($formulaIds) . " formula timestamp(s) bumped to {$now}.\n";
echo "Run a Full Sync (or wait for the hourly cron) — bulk publish will\n";
echo "republish the eligible ones with the corrected composition.\n";
