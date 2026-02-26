<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\CSRF;
use SDS\Models\FinishedGood;
use SDS\Models\Formula;
use SDS\Models\RawMaterial;
use SDS\Services\AuditService;

class FinishedGoodController
{
    public function index(): void
    {
        $filters = [
            'search'    => $_GET['search'] ?? '',
            'family'    => $_GET['family'] ?? '',
            'is_active' => isset($_GET['is_active']) ? (int) $_GET['is_active'] : null,
            'page'      => (int) ($_GET['page'] ?? 1),
            'per_page'  => 25,
            'sort'      => $_GET['sort'] ?? 'product_code',
            'dir'       => $_GET['dir'] ?? 'asc',
        ];

        $items    = FinishedGood::all($filters);
        $total    = FinishedGood::count($filters);
        $families = FinishedGood::getFamilies();

        view('finished-goods/index', [
            'pageTitle' => 'Finished Goods',
            'items'     => $items,
            'total'     => $total,
            'filters'   => $filters,
            'families'  => $families,
            'pages'     => (int) ceil($total / $filters['per_page']),
        ]);
    }

    public function create(): void
    {
        if (!is_editor()) {
            $_SESSION['_flash']['error'] = 'You do not have permission to create finished goods.';
            redirect('/finished-goods');
        }

        $families     = $this->loadProductFamilies();
        $rawMaterials = RawMaterial::all(['per_page' => 999, 'sort' => 'internal_code', 'dir' => 'asc']);

        view('finished-goods/form', [
            'pageTitle'    => 'Add Finished Good',
            'item'         => null,
            'mode'         => 'create',
            'families'     => $families,
            'rawMaterials' => $rawMaterials,
            'formula'      => null,
        ]);
    }

    public function store(): void
    {
        if (!is_editor()) {
            redirect('/finished-goods');
        }

        CSRF::validateRequest();

        $data = $_POST;
        $data['created_by'] = current_user_id();

        try {
            $id = FinishedGood::create($data);
            AuditService::log('finished_good', $id, 'create', $data);

            // Save formula if lines were provided
            $this->saveFormulaFromPost($id);

            $_SESSION['_flash']['success'] = 'Finished good created successfully.';
            redirect('/finished-goods');
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
            $_SESSION['_flash']['_old_input'] = $data;
            redirect('/finished-goods/create');
        }
    }

    public function edit(string $id): void
    {
        $item = FinishedGood::findById((int) $id);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'Finished good not found.';
            redirect('/finished-goods');
        }

        $families     = $this->loadProductFamilies();
        $rawMaterials = RawMaterial::all(['per_page' => 999, 'sort' => 'internal_code', 'dir' => 'asc']);
        $formula      = Formula::findCurrentByFinishedGood((int) $id);

        view('finished-goods/form', [
            'pageTitle'    => 'Edit: ' . $item['product_code'],
            'item'         => $item,
            'mode'         => 'edit',
            'families'     => $families,
            'rawMaterials' => $rawMaterials,
            'formula'      => $formula,
        ]);
    }

    public function update(string $id): void
    {
        if (!is_editor()) {
            redirect('/finished-goods');
        }

        CSRF::validateRequest();

        $item = FinishedGood::findById((int) $id);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'Finished good not found.';
            redirect('/finished-goods');
        }

        try {
            $diff = AuditService::diff($item, $_POST);
            FinishedGood::update((int) $id, $_POST);
            AuditService::log('finished_good', $id, 'update', $diff);

            // Save formula if lines were provided
            $this->saveFormulaFromPost((int) $id);

            $_SESSION['_flash']['success'] = 'Finished good updated.';
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
        }

        redirect('/finished-goods/' . $id . '/edit');
    }

    /**
     * Parse formula lines from POST and create a new formula version if lines exist.
     */
    private function saveFormulaFromPost(int $fgId): void
    {
        $rmIds = $_POST['raw_material_id'] ?? [];
        $pcts  = $_POST['pct'] ?? [];
        $lines = [];

        foreach ($rmIds as $i => $rmId) {
            $rmId = (int) $rmId;
            $pct  = (float) ($pcts[$i] ?? 0);

            if ($rmId <= 0 || $pct <= 0) {
                continue;
            }

            $lines[] = [
                'raw_material_id' => $rmId,
                'pct'             => $pct,
                'sort_order'      => $i + 1,
            ];
        }

        // Only save if lines were actually provided
        if (empty($lines)) {
            return;
        }

        $notes = trim($_POST['formula_notes'] ?? '');

        $formulaId = Formula::create(
            $fgId,
            $lines,
            $notes ?: null,
            current_user_id()
        );

        AuditService::log('formula', $formulaId, 'create', [
            'finished_good_id' => $fgId,
            'line_count'       => count($lines),
        ]);
    }

    /**
     * Load product families from admin settings, falling back to distinct DB values.
     *
     * @return string[]
     */
    private function loadProductFamilies(): array
    {
        $db  = \SDS\Core\Database::getInstance();
        $row = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'sds.product_families'");
        if ($row && !empty($row['value'])) {
            return array_filter(array_map('trim', explode("\n", $row['value'])));
        }
        // Fallback to distinct families already in use
        return FinishedGood::getFamilies();
    }
}
