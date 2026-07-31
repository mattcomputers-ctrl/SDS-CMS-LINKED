<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\CSRF;
use SDS\Core\Database;
use SDS\Services\BulkPublishQueue;
use SDS\Services\CMSDatabase;
use SDS\Services\CMSImportService;
use SDS\Services\MailService;
use SDS\Services\SDSAutoSendService;

class CMSImportController
{
    /**
     * GET /cms-import — Show available CMS items and import controls.
     */
    public function index(): void
    {
        if (!can_edit('cms_import')) {
            $_SESSION['_flash']['error'] = 'You do not have permission to import items.';
            redirect('/');
        }

        $syncInfo = $this->getSyncInfo();

        if (!CMSDatabase::isConfigured()) {
            view('cms-import/index', [
                'pageTitle'     => 'CMS Formula Import',
                'configured'    => false,
                'items'         => [],
                'incomplete'    => [],
                'syncInfo'      => $syncInfo,
            ]);
            return;
        }

        try {
            $service = new CMSImportService();
            $items      = $service->getAvailableItems();
            $incomplete = $service->getIncompleteRawMaterials();
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'Could not connect to CMS database: ' . $e->getMessage();
            $items = [];
            $incomplete = [];
        }

        view('cms-import/index', [
            'pageTitle'     => 'CMS Formula Import',
            'configured'    => true,
            'items'         => $items,
            'incomplete'    => $incomplete,
            'syncInfo'      => $syncInfo,
        ]);
    }

    /**
     * Gather last sync info and shipment stats for the index page.
     */
    private function getSyncInfo(): array
    {
        $db = Database::getInstance();

        $rows = $db->fetchAll(
            "SELECT `key`, `value` FROM settings
             WHERE `key` IN ('cms_sync.last_completed_at', 'cms_sync.last_trigger', 'cms_sync.last_triggered_by')"
        );
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }

        $countRow = $db->fetch("SELECT COUNT(*) AS cnt FROM shipment_detail");
        $recentRow = $db->fetch(
            "SELECT date_shipped, ship_to, ship_to_name
             FROM shipment_detail
             WHERE date_shipped IS NOT NULL
             ORDER BY date_shipped DESC LIMIT 1"
        );

        return [
            'last_completed_at' => $settings['cms_sync.last_completed_at'] ?? null,
            'last_trigger'      => $settings['cms_sync.last_trigger'] ?? null,
            'last_triggered_by' => $settings['cms_sync.last_triggered_by'] ?? null,
            'shipment_count'    => (int) ($countRow['cnt'] ?? 0),
            'recent_date'       => $recentRow['date_shipped'] ?? null,
            'recent_ship_to'    => $recentRow['ship_to'] ?? null,
            'recent_ship_name'  => $recentRow['ship_to_name'] ?? null,
        ];
    }

    /**
     * POST /cms-import/preview — Dry-run showing what would be imported.
     */
    public function preview(): void
    {
        CSRF::validateRequest();

        if (!can_edit('cms_import')) {
            $_SESSION['_flash']['error'] = 'You do not have permission to import items.';
            redirect('/cms-import');
        }

        try {
            $service = new CMSImportService();
            $preview = $service->preview();
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'Preview failed: ' . $e->getMessage();
            redirect('/cms-import');
        }

        view('cms-import/preview', [
            'pageTitle' => 'CMS Import Preview',
            'preview'   => $preview,
        ]);
    }

    /**
     * POST /cms-import/import — Execute the import.
     */
    public function import(): void
    {
        CSRF::validateRequest();

        if (!can_edit('cms_import')) {
            $_SESSION['_flash']['error'] = 'You do not have permission to import items.';
            redirect('/cms-import');
        }

        try {
            $service = new CMSImportService();
            $results = $service->import(current_user_id());
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'Import failed: ' . $e->getMessage();
            redirect('/cms-import');
        }

        view('cms-import/results', [
            'pageTitle' => 'CMS Import Results',
            'results'   => $results,
        ]);
    }

    /**
     * POST /cms-import/full-sync — Run the full CMS sync chain in background:
     * import → bulk publish → auto-send (same as the hourly cron).
     */
    public function fullSync(): void
    {
        CSRF::validateRequest();

        if (!can_edit('cms_import')) {
            $_SESSION['_flash']['error'] = 'You do not have permission to run a sync.';
            redirect('/cms-import');
        }

        if (!CMSDatabase::isConfigured()) {
            $_SESSION['_flash']['error'] = 'CMS database is not configured.';
            redirect('/cms-import');
        }

        // Pre-check the conditions the script would silently exit on, so
        // the user gets told why nothing will happen instead of a flash
        // that claims the sync started.
        $db = Database::getInstance();

        $enabledRow = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'cms_sync.enabled'");
        if ($enabledRow !== null && ((string) $enabledRow['value']) === '0') {
            $_SESSION['_flash']['error'] = 'CMS sync is disabled in Admin → Settings. Enable it there, then run the sync.';
            redirect('/cms-import');
        }

        require_once \SDS\Core\App::basePath() . '/cron/cron-helpers.php';
        if (cron_in_blackout($db)) {
            $_SESSION['_flash']['error'] = 'A cron blackout window is active right now (backups or other heavy jobs). Try again after it ends.';
            redirect('/cms-import');
        }

        $basePath = \SDS\Core\App::basePath();
        $logFile  = $basePath . '/storage/logs/cms-sync.log';
        $cmd = sprintf(
            'setsid %s %s %s < /dev/null >> %s 2>&1 &',
            escapeshellarg(php_cli_binary()),
            escapeshellarg($basePath . '/cron/cms-sync.php'),
            escapeshellarg('--manual'),
            escapeshellarg($logFile)
        );
        exec($cmd);

        $db = Database::getInstance();
        $userName = $_SESSION['_user']['display_name'] ?? $_SESSION['_user']['username'] ?? '';
        foreach ([
            'cms_sync.last_trigger'      => 'manual',
            'cms_sync.last_triggered_by' => $userName,
        ] as $key => $val) {
            $existing = $db->fetch("SELECT `key` FROM settings WHERE `key` = ?", [$key]);
            if ($existing) {
                $db->update('settings', ['value' => $val], "`key` = ?", [$key]);
            } else {
                $db->insert('settings', ['key' => $key, 'value' => $val]);
            }
        }

        $_SESSION['_flash']['success'] = 'Full sync started in background (import → publish → auto-send). Check back in a few minutes.';
        redirect('/cms-import');
    }

    /**
     * GET /cms-import/incomplete — Show raw materials needing details.
     */
    public function incomplete(): void
    {
        if (!can_read('raw_materials')) {
            $_SESSION['_flash']['error'] = 'You do not have permission to view raw materials.';
            redirect('/');
        }

        try {
            $service     = new CMSImportService();
            $incomplete  = $service->getIncompleteRawMaterials();
            $unblockers  = $service->getSingleBlockerImpact();
            $tradeSecret = $service->getTradeSecretRawMaterials();
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'Could not load incomplete materials: ' . $e->getMessage();
            $incomplete  = [];
            $unblockers  = [];
            $tradeSecret = [];
        }

        // Inventory gaps need the live CMS connection — degrade gracefully
        // when it's unreachable rather than blanking the whole page.
        $inventoryGaps = [];
        $inventoryError = null;
        try {
            $service = $service ?? new CMSImportService();
            $inventoryGaps = $service->getInventoryGaps();
        } catch (\Throwable $e) {
            $inventoryError = 'Could not load CMS inventory: ' . $e->getMessage();
        }

        view('cms-import/incomplete', [
            'pageTitle'      => 'Incomplete Raw Materials',
            'incomplete'     => $incomplete,
            'unblockers'     => $unblockers,
            'tradeSecret'    => $tradeSecret,
            'inventoryGaps'  => $inventoryGaps,
            'inventoryError' => $inventoryError,
        ]);
    }
}
