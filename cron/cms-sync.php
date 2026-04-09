#!/usr/bin/env php
<?php
/**
 * CMS Sync — Hourly cron job
 *
 * Imports/syncs items, formulas, aliases, and shipment data from the
 * CMS MSSQL database into the SDS system.
 *
 * Usage:
 *   php cron/cms-sync.php
 *
 * Crontab (hourly):
 *   7 * * * * cd /var/www/sds-system && /usr/bin/php cron/cms-sync.php >> storage/logs/cms-sync.log 2>&1
 */

// Bootstrap the application
require __DIR__ . '/../vendor/autoload.php';

use SDS\Core\App;
use SDS\Services\CMSDatabase;
use SDS\Services\CMSImportService;
use SDS\Services\SDSAutoSendService;
use SDS\Services\MailService;

$start = microtime(true);
$timestamp = date('Y-m-d H:i:s');

echo "[{$timestamp}] CMS Sync starting...\n";

try {
    // Initialize the application (loads config, database, session)
    $app = new App();

    // Check if CMS database is configured
    if (!CMSDatabase::isConfigured()) {
        echo "[{$timestamp}] CMS database not configured. Skipping sync.\n";
        exit(0);
    }

    $service = new CMSImportService();
    $results = $service->import(null); // null = system/cron user

    $elapsed = round(microtime(true) - $start, 2);

    // Log summary
    echo "[{$timestamp}] CMS Sync complete in {$elapsed}s:\n";
    echo "  Finished goods:  " . count($results['fg_created']) . " created, " . count($results['fg_skipped']) . " skipped\n";
    echo "  Raw materials:   " . count($results['rm_created']) . " created, " . count($results['rm_skipped']) . " skipped\n";
    echo "  Formulas:        " . $results['formulas_created'] . " created, " . $results['formulas_updated'] . " updated, " . $results['formulas_skipped'] . " skipped\n";
    echo "  Aliases:         " . ($results['aliases_created'] ?? 0) . " created, " . ($results['aliases_updated'] ?? 0) . " updated\n";
    echo "  Shipments:       " . ($results['shipments_imported'] ?? 0) . " imported\n";

    if (!empty($results['errors'])) {
        echo "  Errors (" . count($results['errors']) . "):\n";
        foreach ($results['errors'] as $err) {
            echo "    - {$err}\n";
        }
    }

    if (!empty($results['incomplete_materials'])) {
        echo "  Incomplete RMs:  " . count($results['incomplete_materials']) . " need details\n";
    }

    // Auto-publish and auto-send SDS
    if (MailService::isConfigured()) {
        echo "\n[" . date('Y-m-d H:i:s') . "] Auto-publish & send starting...\n";
        $autoSend = new SDSAutoSendService();
        $sendResults = $autoSend->processNewShipments();
        echo "  Auto-published:  " . ($sendResults['auto_published'] ?? 0) . "\n";
        echo "  Emails sent:     " . ($sendResults['emails_sent'] ?? 0) . "\n";
        echo "  Queued:          " . ($sendResults['queued'] ?? 0) . "\n";
        echo "  Skipped:         " . ($sendResults['skipped'] ?? 0) . "\n";
        if (!empty($sendResults['errors'])) {
            echo "  Send errors (" . count($sendResults['errors']) . "):\n";
            foreach ($sendResults['errors'] as $err) {
                echo "    - {$err}\n";
            }
        }
    } else {
        echo "\n  Mail not configured — skipping auto-send.\n";
    }

} catch (\Throwable $e) {
    $elapsed = round(microtime(true) - $start, 2);
    echo "[{$timestamp}] CMS Sync FAILED after {$elapsed}s: " . $e->getMessage() . "\n";
    exit(1);
}
