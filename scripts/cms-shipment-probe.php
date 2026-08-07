#!/usr/bin/env php
<?php
/**
 * cms-shipment-probe.php — discover what the CMS ShipmentDetails view
 * exposes (PO number, unit price, UOM, invoice reversal indicators)
 * so the Order History report can be built against the real schema.
 *
 * Usage:
 *   php scripts/cms-shipment-probe.php            # schema overview
 *   php scripts/cms-shipment-probe.php CUSTCODE   # also sample recent rows for a Ship To code
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
echo "  CMS Shipment/Invoice Schema Probe\n";
echo "=============================================================\n\n";

// 1. All columns on the ShipmentDetails view
echo "Columns on CMS.dbo.ShipmentDetails:\n";
$cols = $cms->fetchAll(
    "SELECT COLUMN_NAME, DATA_TYPE
     FROM CMS.INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_NAME = 'ShipmentDetails'
     ORDER BY ORDINAL_POSITION"
);
foreach ($cols as $c) {
    echo "  - {$c['COLUMN_NAME']} ({$c['DATA_TYPE']})\n";
}

// 2. Tables/views that look invoice- or order-related
echo "\nTables/views with invoice/order/price-ish names:\n";
$tables = $cms->fetchAll(
    "SELECT TABLE_NAME, TABLE_TYPE
     FROM CMS.INFORMATION_SCHEMA.TABLES
     WHERE TABLE_NAME LIKE '%Invoice%' OR TABLE_NAME LIKE '%TransDoc%'
        OR TABLE_NAME LIKE '%OrdDetail%' OR TABLE_NAME LIKE '%OrderDetail%'
        OR TABLE_NAME LIKE '%Revers%' OR TABLE_NAME LIKE '%Price%'
     ORDER BY TABLE_NAME"
);
if (empty($tables)) {
    echo "  (none matched)\n";
}
foreach ($tables as $t) {
    echo "  - {$t['TABLE_NAME']} ({$t['TABLE_TYPE']})\n";
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

// 3. Optional: sample recent shipment rows for a Ship To code
$shipTo = trim($argv[1] ?? '');
if ($shipTo !== '') {
    echo "\nMost recent 3 ShipmentDetails rows for ShipTo '{$shipTo}' (all columns):\n";
    $rows = $cms->fetchAll(
        "SELECT TOP 3 * FROM CMS.dbo.ShipmentDetails WHERE ShipTo = ? ORDER BY DateShipped DESC",
        [$shipTo]
    );
    if (empty($rows)) {
        echo "  No rows found.\n";
    }
    foreach ($rows as $i => $row) {
        echo "  --- Row " . ($i + 1) . " ---\n";
        foreach ($row as $k => $v) {
            $val = $v === null ? 'NULL' : (string) $v;
            if (strlen($val) > 60) { $val = substr($val, 0, 57) . '...'; }
            echo "  {$k} = {$val}\n";
        }
    }
}

echo "\nDone. Paste this output back so the Order History report can be\n";
echo "built against the correct PO / price / UOM / reversal columns.\n";
