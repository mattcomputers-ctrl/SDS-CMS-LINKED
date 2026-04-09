<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\CSRF;
use SDS\Models\Customer;
use SDS\Services\AuditService;

class CustomerController
{
    public function index(): void
    {
        $filters = [
            'search'   => $_GET['search'] ?? '',
            'page'     => (int) ($_GET['page'] ?? 1),
            'per_page' => 25,
            'sort'     => $_GET['sort'] ?? 'ship_to_name',
            'dir'      => $_GET['dir'] ?? 'asc',
        ];

        $items = Customer::all($filters);
        $total = Customer::count($filters);

        view('customers/index', [
            'pageTitle' => 'Customers',
            'items'     => $items,
            'total'     => $total,
            'filters'   => $filters,
            'pages'     => (int) ceil($total / $filters['per_page']),
        ]);
    }

    public function create(): void
    {
        if (!can_edit('customers')) {
            $_SESSION['_flash']['error'] = 'You do not have permission to create customers.';
            redirect('/customers');
        }

        view('customers/form', [
            'pageTitle' => 'Add Customer',
            'item'      => [],
            'mode'      => 'create',
        ]);
    }

    public function store(): void
    {
        if (!can_edit('customers')) {
            redirect('/customers');
        }

        CSRF::validateRequest();

        try {
            $id = Customer::create($_POST);
            AuditService::log('customer', $id, 'create', $_POST);
            $_SESSION['_flash']['success'] = 'Customer created.';
            redirect('/customers');
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
            $_SESSION['_flash']['_old_input'] = $_POST;
            redirect('/customers/create');
        }
    }

    public function edit(string $id): void
    {
        $item = Customer::findById((int) $id);
        if ($item === null) {
            $_SESSION['_flash']['error'] = 'Customer not found.';
            redirect('/customers');
        }

        // Load SDS send history for this customer
        $db = \SDS\Core\Database::getInstance();
        $sendHistory = $db->fetchAll(
            "SELECT ssl.item_identifier, ssl.language, ssl.sent_at, ssl.shipment_date,
                    sv.version AS sds_version, fg.product_code
             FROM sds_send_log ssl
             LEFT JOIN sds_versions sv ON sv.id = ssl.sds_version_id
             LEFT JOIN finished_goods fg ON fg.id = ssl.finished_good_id
             WHERE ssl.customer_id = ?
             ORDER BY ssl.sent_at DESC
             LIMIT 500",
            [(int) $id]
        );

        view('customers/form', [
            'pageTitle'   => 'Edit: ' . ($item['ship_to_name'] ?: $item['ship_to']),
            'item'        => $item,
            'mode'        => 'edit',
            'sendHistory' => $sendHistory,
        ]);
    }

    public function update(string $id): void
    {
        if (!can_edit('customers')) {
            redirect('/customers');
        }

        CSRF::validateRequest();

        try {
            Customer::update((int) $id, $_POST);
            AuditService::log('customer', $id, 'update', $_POST);
            $_SESSION['_flash']['success'] = 'Customer updated.';
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
        }

        redirect('/customers/' . $id . '/edit');
    }

    public function delete(string $id): void
    {
        if (!can_edit('customers')) {
            redirect('/customers');
        }

        CSRF::validateRequest();

        Customer::delete((int) $id);
        AuditService::log('customer', $id, 'delete');
        $_SESSION['_flash']['success'] = 'Customer deleted.';
        redirect('/customers');
    }
}
