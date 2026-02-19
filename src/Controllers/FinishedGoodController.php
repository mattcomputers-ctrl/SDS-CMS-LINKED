<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\CSRF;
use SDS\Models\FinishedGood;
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

        $families = FinishedGood::getFamilies();

        view('finished-goods/form', [
            'pageTitle' => 'Add Finished Good',
            'item'      => null,
            'mode'      => 'create',
            'families'  => $families,
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
            $_SESSION['_flash']['success'] = 'Finished good created successfully.';
            redirect('/finished-goods/' . $id . '/edit');
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

        $families = FinishedGood::getFamilies();

        view('finished-goods/form', [
            'pageTitle' => 'Edit: ' . $item['product_code'],
            'item'      => $item,
            'mode'      => 'edit',
            'families'  => $families,
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
            $_SESSION['_flash']['success'] = 'Finished good updated.';
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
        }

        redirect('/finished-goods/' . $id . '/edit');
    }
}
