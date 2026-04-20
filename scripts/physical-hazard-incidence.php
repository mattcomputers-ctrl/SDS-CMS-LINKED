#!/usr/bin/env php
<?php
/**
 * SDS System — Physical Hazard Incidence Report
 *
 * Scans published SDS snapshots and reports how many of them carry
 * physical-hazard classifications (Flammable, Oxidizing, Pyrophoric,
 * Explosive, Gas Under Pressure, Self-Reactive, Water-Reactive,
 * Corrosive to Metals, Self-Heating).
 *
 * Purpose: decide whether Phase 5 of the Hazard Engine refactor
 * (finished-good physical-hazard override) is worth building.
 *
 *   If zero SDSs carry physical-hazard classifications, the current
 *   1% "Flammable" default in HazardEngine::getCutoff() has never fired
 *   in anger — Phase 5 is low priority.
 *
 *   If many SDSs carry them, the 1% default is over-classifying in
 *   practice, and Phase 5 has real value.
 *
 * Usage:
 *   php scripts/physical-hazard-incidence.php [--output=<path>]
 *
 * Output:
 *   - Per-class count printed to stdout.
 *   - Optional CSV with one row per affected SDS: sds_version_id,
 *     product_code, class, category, pictograms, signal_word.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__);
require_once $basePath . '/vendor/autoload.php';

new \SDS\Core\App();

use SDS\Core\Database;

$outputPath = null;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--output=(.+)$/', $arg, $m)) {
        $outputPath = $m[1];
    } else {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(1);
    }
}

// The physical-hazard class-name prefixes we care about. Match is
// case-insensitive substring (same tolerance as the current engine).
$physicalPrefixes = [
    'Flammable',
    'Oxidizing',
    'Pyrophoric',
    'Explosive',
    'Self-Reactive',
    'Self-Heating',
    'Water-Reactive',
    'Gas Under Pressure',
    'Gases Under Pressure',
    'Corrosive to Metals',
];

$db = Database::getInstance();

$total = (int) ($db->fetch(
    "SELECT COUNT(*) AS cnt FROM sds_versions WHERE status = 'published' AND is_deleted = 0"
)['cnt'] ?? 0);

echo "=== Physical Hazard Incidence Report ===\n";
echo "Scanning {$total} published SDS version(s).\n\n";

$perClass = array_fill_keys($physicalPrefixes, 0);
$affectedSdsIds = [];
$csv = null;

if ($outputPath !== null) {
    $csv = fopen($outputPath, 'w');
    if ($csv === false) {
        fwrite(STDERR, "Failed to open {$outputPath}\n");
        exit(1);
    }
    fputcsv($csv, ['sds_version_id', 'product_code', 'language', 'class', 'category', 'pictograms', 'signal_word']);
}

$pdo = $db->getPdo();
$stmt = $pdo->prepare(
    "SELECT sv.id, sv.language, sv.snapshot_json, fg.product_code
       FROM sds_versions sv
       JOIN finished_goods fg ON fg.id = sv.finished_good_id
      WHERE sv.status = 'published' AND sv.is_deleted = 0
      ORDER BY sv.id"
);
$stmt->execute();

$processed = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $processed++;
    if ($processed % 500 === 0) {
        echo "  ...scanned {$processed}/{$total}\n";
    }

    $snap = json_decode((string) $row['snapshot_json'], true);
    if (!is_array($snap)) {
        continue;
    }

    $hazardClasses = $snap['sections'][2]['hazard_classes'] ?? [];
    if (!is_array($hazardClasses) || empty($hazardClasses)) {
        continue;
    }

    foreach ($hazardClasses as $hc) {
        $className = trim((string) ($hc['class'] ?? ''));
        if ($className === '') continue;

        foreach ($physicalPrefixes as $prefix) {
            if (stripos($className, $prefix) !== false) {
                $perClass[$prefix]++;
                $affectedSdsIds[(int) $row['id']] = true;

                if ($csv !== null) {
                    $picts = $snap['sections'][2]['pictograms'] ?? [];
                    fputcsv($csv, [
                        $row['id'],
                        $row['product_code'],
                        $row['language'],
                        $className,
                        (string) ($hc['category'] ?? ''),
                        implode(',', array_map('strval', is_array($picts) ? $picts : [])),
                        (string) ($snap['sections'][2]['signal_word'] ?? ''),
                    ]);
                }
                break; // Count each SDS once per category appearance
            }
        }
    }
}

if ($csv !== null) {
    fclose($csv);
}

echo "\n=== Results ===\n";
echo sprintf("Total SDSs scanned:              %6d\n", $processed);
echo sprintf("SDSs with any physical hazard:   %6d\n", count($affectedSdsIds));
echo "\nHits by class:\n";
foreach ($perClass as $cls => $n) {
    echo sprintf("  %-22s %6d\n", $cls, $n);
}

echo "\n";
if (count($affectedSdsIds) === 0) {
    echo "No physical-hazard classifications found in any published SDS.\n";
    echo "Phase 5 (finished-good physical-hazard override) is LOW PRIORITY.\n";
} else {
    echo "Physical-hazard classifications exist in published SDSs.\n";
    echo "Review affected SDSs — the current 1% default in HazardEngine\n";
    echo "may be over-classifying. Phase 5 has real value.\n";
}

if ($outputPath !== null) {
    echo "\nPer-hit CSV written to: {$outputPath}\n";
}
