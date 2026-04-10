<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\CSRF;
use SDS\Core\Database;
use SDS\Services\CMSDatabase;
use SDS\Services\CMSImportService;

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
     * GET /cms-import/incomplete — Show raw materials needing details.
     */
    public function incomplete(): void
    {
        if (!can_read('raw_materials')) {
            $_SESSION['_flash']['error'] = 'You do not have permission to view raw materials.';
            redirect('/');
        }

        try {
            $service = new CMSImportService();
            $incomplete = $service->getIncompleteRawMaterials();
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'Could not load incomplete materials: ' . $e->getMessage();
            $incomplete = [];
        }

        view('cms-import/incomplete', [
            'pageTitle'  => 'Incomplete Raw Materials',
            'incomplete' => $incomplete,
        ]);
    }
}
