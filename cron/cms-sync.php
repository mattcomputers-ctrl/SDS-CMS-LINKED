#!/usr/bin/env php
<?php
/**
 * CMS Sync — Hourly cron job
 *
 * Imports/syncs items, formulas, aliases, and shipment data from the
 * CMS MSSQL database into the SDS system. The full post-sync chain is:
 *
 *   1. CMS import (this script body).
 *   2. Bulk SDS Publish (cron/bulk-publish.php, gated on
 *      cms_sync.auto_bulk_publish). Uses the Bulk SDS Publish page's
 *      eligibility rules: every RM user-reviewed, not already up to
 *      date. Waits for all workers to finish before continuing.
 *   3. SDS customer auto-send (SDSAutoSendService::processNewShipments).
 *      Emails regulatory contacts for any new shipment whose SDS is
 *      now published, or queues a missing-data review if it isn't.
 *
 * The whole chain is gated on cms_sync.enabled — when unchecked, the
 * cron starts, reads the setting, and exits without touching anything.
 *
 * Usage:
 *   php cron/cms-sync.php
 *
 * Crontab (hourly):
 *   7 * * * * cd /var/www/sds-system && /usr/bin/php cron/cms-sync.php >> storage/logs/cms-sync.log 2>&1
 */

require __DIR__ . '/../vendor/autoload.php';

use SDS\Core\App;
use SDS\Core\Database;
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

    $db = Database::getInstance();

    // Admin toggle — off means "do nothing at all." Checked by default
    // (when the row is missing, treat as enabled). Leaving this exit
    // high up in the script keeps the behaviour obvious from logs.
    $enabledRow = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'cms_sync.enabled'");
    $enabled = $enabledRow === null || ((string) $enabledRow['value']) !== '0';
    if (!$enabled) {
        echo "[{$timestamp}] CMS sync disabled via admin settings — exiting.\n";
        exit(0);
    }

    // Check if CMS database is configured
    if (!CMSDatabase::isConfigured()) {
        echo "[{$timestamp}] CMS database not configured. Skipping sync.\n";
        exit(0);
    }

    // Check sync interval — skip if it hasn't been long enough since last run
    $intervalRow = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'cms_sync.interval_hours'");
    $intervalHours = (int) ($intervalRow['value'] ?? 1);

    $lastRunRow = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'cms_sync.last_run_at'");
    $lastRunAt = $lastRunRow['value'] ?? null;

    if ($lastRunAt !== null && $intervalHours > 1) {
        $nextRunAfter = strtotime($lastRunAt) + ($intervalHours * 3600);
        if (time() < $nextRunAfter) {
            echo "[{$timestamp}] Skipping — next sync after " . date('Y-m-d H:i:s', $nextRunAfter) . " (interval: {$intervalHours}h)\n";
            exit(0);
        }
    }

    // Record this run time
    if ($lastRunRow) {
        $db->update('settings', ['value' => $timestamp], '`key` = ?', ['cms_sync.last_run_at']);
    } else {
        $db->insert('settings', ['key' => 'cms_sync.last_run_at', 'value' => $timestamp]);
    }

    $service = new CMSImportService();
    $results = $service->import(null); // null = system/cron user

    $elapsed = round(microtime(true) - $start, 2);

    // Log summary
    echo "[{$timestamp}] CMS Sync complete in {$elapsed}s:\n";
    echo "  Finished goods:  " . count($results['fg_created']) . " created, " . count($results['fg_skipped']) . " skipped\n";
    echo "  Raw materials:   " . count($results['rm_created']) . " created, " . count($results['rm_skipped']) . " skipped\n";
    echo "  RM metadata:     " . ($results['rm_refreshed'] ?? 0) . " refreshed\n";
    echo "  Formulas:        " . $results['formulas_created'] . " created, " . $results['formulas_updated'] . " updated, " . $results['formulas_skipped'] . " skipped\n";
    echo "  Aliases:         " . ($results['aliases_created'] ?? 0) . " created, " . ($results['aliases_updated'] ?? 0) . " updated\n";
    echo "  Shipments:       " . ($results['shipments_imported'] ?? 0) . " imported\n";
    echo "  Customers:       " . ($results['customers_created'] ?? 0) . " created\n";

    if (!empty($results['errors'])) {
        echo "  Errors (" . count($results['errors']) . "):\n";
        foreach ($results['errors'] as $err) {
            echo "    - {$err}\n";
        }
    }

    if (!empty($results['incomplete_materials'])) {
        echo "  Incomplete RMs:  " . count($results['incomplete_materials']) . " need details\n";
    }

    // ── Bulk SDS Publish ───────────────────────────────────────────
    // Replaces the old loose auto-publish inside SDSAutoSendService.
    // Uses the Bulk SDS Publish admin page's eligibility rules, so
    // the cron never publishes anything a user wouldn't get if they
    // clicked the button manually. Gated by cms_sync.auto_bulk_publish;
    // the script itself reads the setting and returns silently when off.
    require __DIR__ . '/bulk-publish.php';

    // ── SDS customer auto-send ─────────────────────────────────────
    // Runs AFTER the bulk publish so shipments whose SDS was just
    // published in this run can be emailed in the same tick. Requires
    // mail to be configured; without it there's no outbound channel.
    if (MailService::isConfigured()) {
        echo "\n[" . date('Y-m-d H:i:s') . "] SDS customer auto-send starting...\n";
        $autoSend = new SDSAutoSendService();
        // Pass the session start so auto-send only considers shipments
        // imported in THIS sync run — no emails for shipments that
        // arrived in an earlier run but weren't already handled.
        $sendResults = $autoSend->processNewShipments($timestamp);
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
        echo "\n  Mail not configured — skipping customer auto-send.\n";
    }

} catch (\Throwable $e) {
    $elapsed = round(microtime(true) - $start, 2);
    echo "[{$timestamp}] CMS Sync FAILED after {$elapsed}s: " . $e->getMessage() . "\n";
    exit(1);
}
