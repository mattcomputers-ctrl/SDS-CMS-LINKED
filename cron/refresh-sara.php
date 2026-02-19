#!/usr/bin/env php
<?php
/**
 * Cron: Refresh SARA 313 / TRI chemical list from CSV.
 *
 * Place the EPA TRI chemical list CSV in storage/data/sara313.csv
 * Then run this script to import/update the database.
 *
 * Schedule: weekly or as needed after EPA updates
 *   0 3 * * 0 /usr/bin/php /path/to/sds-system/cron/refresh-sara.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SDS\Services\SARA313Service;

echo "[" . date('Y-m-d H:i:s') . "] Starting SARA 313 list refresh...\n";

$csvPath = __DIR__ . '/../storage/data/sara313.csv';

if (!file_exists($csvPath)) {
    echo "SARA 313 CSV not found at: {$csvPath}\n";
    echo "Download from: https://www.epa.gov/toxics-release-inventory-tri-program/tri-listed-chemicals\n";
    exit(1);
}

$result = SARA313Service::importFromCsv($csvPath);

echo "Inserted: {$result['inserted']}\n";
echo "Updated: {$result['updated']}\n";

if (!empty($result['errors'])) {
    echo "Errors:\n";
    foreach (array_slice($result['errors'], 0, 20) as $err) {
        echo "  - {$err}\n";
    }
    if (count($result['errors']) > 20) {
        echo "  ... and " . (count($result['errors']) - 20) . " more.\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] SARA 313 refresh complete.\n";
