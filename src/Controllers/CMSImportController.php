<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\CSRF;
use SDS\Services\CMSDatabase;
use SDS\Services\CMSImportService;

class CMSImportController
{
    /**
     * GET /cms-import — Show available CMS items and import controls.
     */
    public function index(): void
    {
        if (!can_edit('finished_goods')) {
            $_SESSION['_flash']['error'] = 'You do not have permission to import items.';
            redirect('/');
        }

        if (!CMSDatabase::isConfigured()) {
            view('cms-import/index', [
                'pageTitle'     => 'CMS Formula Import',
                'configured'    => false,
                'items'         => [],
                'incomplete'    => [],
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
        ]);
    }

    /**
     * POST /cms-import/preview — Dry-run showing what would be imported.
     */
    public function preview(): void
    {
        CSRF::validate();

        if (!can_edit('finished_goods')) {
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
        CSRF::validate();

        if (!can_edit('finished_goods')) {
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
