#!/usr/bin/env php
<?php
/**
 * cms-inventory-probe.php — discover where quantity-on-hand lives in
 * the CMS database, so the RM inventory gaps feature can query it.
 *
 * Usage:
 *   php scripts/cms-inventory-probe.php            # schema overview
 *   php scripts/cms-inventory-probe.php RM123      # also sample a known item code
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SDS\Core\App;
use SDS\Services\CMSDatabase;

new App();

if (!CMSDatabase::isConfigured()) {
    fwrite(STDERR, "CMS database is not configured.\n");
    exit(1);
}
$cms = CMSDatabase::getInstance();

echo "=============================================================\n";
echo "  CMS Inventory Schema Probe\n";
echo "=============================================================\n\n";

// 1. All columns on the Item table
echo "Columns on CMS.dbo.Item:\n";
$cols = $cms->fetchAll(
    "SELECT COLUMN_NAME, DATA_TYPE
     FROM CMS.INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_NAME = 'Item'
     ORDER BY ORDINAL_POSITION"
);
foreach ($cols as $c) {
    echo "  - {$c['COLUMN_NAME']} ({$c['DATA_TYPE']})\n";
}

// 2. Tables that look inventory-related
echo "\nTables with inventory-ish names:\n";
$tables = $cms->fetchAll(
    "SELECT TABLE_NAME
     FROM CMS.INFORMATION_SCHEMA.TABLES
     WHERE TABLE_TYPE = 'BASE TABLE'
       AND (TABLE_NAME LIKE '%Lot%' OR TABLE_NAME LIKE '%Inv%' OR TABLE_NAME LIKE '%Stock%'
            OR TABLE_NAME LIKE '%Hand%' OR TABLE_NAME LIKE '%Qty%' OR TABLE_NAME LIKE '%Warehouse%'
            OR TABLE_NAME LIKE '%Balance%')
     ORDER BY TABLE_NAME"
);
if (empty($tables)) {
    echo "  (none matched)\n";
}
foreach ($tables as $t) {
    echo "  - {$t['TABLE_NAME']}\n";
    $tCols = $cms->fetchAll(
        "SELECT COLUMN_NAME, DATA_TYPE
         FROM CMS.INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_NAME = ?
         ORDER BY ORDINAL_POSITION",
        [$t['TABLE_NAME']]
    );
    foreach ($tCols as $c) {
        echo "      {$c['COLUMN_NAME']} ({$c['DATA_TYPE']})\n";
    }
}

// 3. Optional: sample a known item code
$code = trim($argv[1] ?? '');
if ($code !== '') {
    echo "\nSample Item row for '{$code}' (first 40 columns):\n";
    $row = $cms->fetch("SELECT * FROM CMS.dbo.Item WHERE ItemCode = ?", [$code]);
    if ($row === null) {
        echo "  Item not found.\n";
    } else {
        $i = 0;
        foreach ($row as $k => $v) {
            if (++$i > 40) { break; }
            $val = $v === null ? 'NULL' : (string) $v;
            if (strlen($val) > 60) { $val = substr($val, 0, 57) . '...'; }
            echo "  {$k} = {$val}\n";
        }
    }
}

echo "\nDone. Paste this output back so the inventory-gaps page can be\n";
echo "built against the correct quantity-on-hand source.\n";
