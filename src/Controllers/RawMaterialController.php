<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\CSRF;
use SDS\Models\RawMaterial;
use SDS\Services\AuditService;

class RawMaterialController
{
    public function index(): void
    {
        $filters = [
            'search'   => $_GET['search'] ?? '',
            'supplier' => $_GET['supplier'] ?? '',
            'page'     => (int) ($_GET['page'] ?? 1),
            'per_page' => 25,
            'sort'     => $_GET['sort'] ?? 'internal_code',
            'dir'      => $_GET['dir'] ?? 'asc',
        ];

        $items = RawMaterial::all($filters);
        $total = RawMaterial::count($filters);

        view('raw-materials/index', [
            'pageTitle' => 'Raw Materials',
            'items'     => $items,
            'total'     => $total,
            'filters'   => $filters,
            'pages'     => (int) ceil($total / $filters['per_page']),
        ]);
    }

    public function create(): void
    {
        if (!is_editor()) {
            $_SESSION['_flash']['error'] = 'You do not have permission to create raw materials.';
            redirect('/raw-materials');
        }

        view('raw-materials/form', [
            'pageTitle' => 'Add Raw Material',
            'item'      => null,
            'mode'      => 'create',
        ]);
    }

    public function store(): void
    {
        if (!is_editor()) {
            redirect('/raw-materials');
        }

        CSRF::validateRequest();

        $data = $_POST;
        $data['created_by'] = current_user_id();

        try {
            $id = RawMaterial::create($data);
            AuditService::log('raw_material', $id, 'create', $data);
            $_SESSION['_flash']['success'] = 'Raw material created successfully.';
            redirect('/raw-materials/' . $id . '/edit');
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
            $_SESSION['_flash']['_old_input'] = $data;
            redirect('/raw-materials/create');
        }
    }

    public function edit(string $id): void
    {
        $item = RawMaterial::findById((int) $id);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'Raw material not found.';
            redirect('/raw-materials');
        }

        view('raw-materials/form', [
            'pageTitle' => 'Edit: ' . $item['internal_code'],
            'item'      => $item,
            'mode'      => 'edit',
        ]);
    }

    public function update(string $id): void
    {
        if (!is_editor()) {
            redirect('/raw-materials');
        }

        CSRF::validateRequest();

        $item = RawMaterial::findById((int) $id);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'Raw material not found.';
            redirect('/raw-materials');
        }

        $data = $_POST;
        $data['expected_updated_at'] = $data['updated_at'] ?? null;

        try {
            $diff = AuditService::diff($item, $data);
            RawMaterial::update((int) $id, $data);
            AuditService::log('raw_material', $id, 'update', $diff);
            $_SESSION['_flash']['success'] = 'Raw material updated.';
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
        }

        redirect('/raw-materials/' . $id . '/edit');
    }

    public function delete(string $id): void
    {
        if (!is_admin()) {
            $_SESSION['_flash']['error'] = 'Only administrators can delete raw materials.';
            redirect('/raw-materials');
        }

        CSRF::validateRequest();

        try {
            RawMaterial::delete((int) $id);
            AuditService::log('raw_material', $id, 'delete');
            $_SESSION['_flash']['success'] = 'Raw material deleted.';
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
        }

        redirect('/raw-materials');
    }

    public function constituents(string $id): void
    {
        $item = RawMaterial::findById((int) $id);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'Raw material not found.';
            redirect('/raw-materials');
        }

        view('raw-materials/constituents', [
            'pageTitle'    => 'Constituents: ' . $item['internal_code'],
            'item'         => $item,
            'constituents' => $item['constituents'] ?? [],
        ]);
    }

    public function saveConstituents(string $id): void
    {
        if (!is_editor()) {
            redirect('/raw-materials');
        }

        CSRF::validateRequest();

        $item = RawMaterial::findById((int) $id);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'Raw material not found.';
            redirect('/raw-materials');
        }

        $constituents = [];
        $casNumbers   = $_POST['cas_number'] ?? [];
        $chemNames    = $_POST['chemical_name'] ?? [];
        $pctMins      = $_POST['pct_min'] ?? [];
        $pctMaxs      = $_POST['pct_max'] ?? [];
        $pctExacts    = $_POST['pct_exact'] ?? [];
        $secrets      = $_POST['is_trade_secret'] ?? [];

        foreach ($casNumbers as $i => $cas) {
            $cas = trim($cas);
            if ($cas === '') {
                continue;
            }

            $constituents[] = [
                'cas_number'      => $cas,
                'chemical_name'   => trim($chemNames[$i] ?? ''),
                'pct_min'         => ($pctMins[$i] ?? '') !== '' ? (float) $pctMins[$i] : null,
                'pct_max'         => ($pctMaxs[$i] ?? '') !== '' ? (float) $pctMaxs[$i] : null,
                'pct_exact'       => ($pctExacts[$i] ?? '') !== '' ? (float) $pctExacts[$i] : null,
                'is_trade_secret' => isset($secrets[$i]) ? 1 : 0,
                'sort_order'      => $i + 1,
            ];
        }

        try {
            RawMaterial::saveConstituents((int) $id, $constituents);
            AuditService::log('raw_material', $id, 'update_constituents', [
                'count' => count($constituents),
            ]);
            $_SESSION['_flash']['success'] = 'Constituents saved (' . count($constituents) . ' entries).';
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
        }

        redirect('/raw-materials/' . $id . '/constituents');
    }
}
