<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\CSRF;
use SDS\Models\Customer;
use SDS\Services\AuditService;
use SDS\Services\MailService;
use SDS\Services\SDSAutoSendService;

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
        // NOTE: alias is `slog` (not `ssl`) — MariaDB interprets `ssl` as a
        // reserved keyword tied to SSL connection syntax and throws a parse
        // error when it appears as a table alias immediately after the table
        // name on some versions.
        $sendHistory = $db->fetchAll(
            "SELECT slog.item_identifier, slog.language, slog.sent_at, slog.shipment_date,
                    sv.version AS sds_version, fg.product_code
             FROM sds_send_log slog
             LEFT JOIN sds_versions sv ON sv.id = slog.sds_version_id
             LEFT JOIN finished_goods fg ON fg.id = slog.finished_good_id
             WHERE slog.customer_id = ?
             ORDER BY slog.sent_at DESC
             LIMIT 500",
            [(int) $id]
        );

        $shipmentOrders = [];
        $shipments = $db->fetchAll(
            "SELECT sd.order_number, sd.date_shipped, sd.item_code, sd.item_name,
                    sd.item_description, sd.item_name_description, sd.qty_shipped
             FROM shipment_detail sd
             WHERE sd.ship_to = ?
             ORDER BY sd.date_shipped DESC, sd.order_number",
            [$item['ship_to']]
        );
        foreach ($shipments as $s) {
            $key = ($s['order_number'] ?? '') . '::' . ($s['date_shipped'] ?? '');
            if (!isset($shipmentOrders[$key])) {
                $shipmentOrders[$key] = [
                    'order_number' => $s['order_number'] ?? '',
                    'date_shipped' => $s['date_shipped'] ?? '',
                    'items'        => [],
                ];
            }
            $shipmentOrders[$key]['items'][] = $s;
        }

        view('customers/form', [
            'pageTitle'      => 'Edit: ' . ($item['ship_to_name'] ?: $item['ship_to']),
            'item'           => $item,
            'mode'           => 'edit',
            'sendHistory'    => $sendHistory,
            'shipmentOrders' => $shipmentOrders,
        ]);
    }

    public function update(string $id): void
    {
        if (!can_edit('customers')) {
            redirect('/customers');
        }

        CSRF::validateRequest();

        try {
            $old = Customer::findById((int) $id);
            Customer::update((int) $id, $_POST);
            AuditService::log('customer', $id, 'update', $_POST);

            $newDate = trim($_POST['sds_send_active_since'] ?? '');
            $oldDate = $old['sds_send_active_since'] ?? '';
            $shouldCatchUp = $newDate !== ''
                && ($oldDate === '' || $oldDate === null || $newDate < $oldDate);

            if ($shouldCatchUp) {
                $svc = new SDSAutoSendService();
                $catchUp = $svc->catchUpCustomer((int) $id);
                $msg = 'Customer updated.';
                if ($catchUp['emails_sent'] > 0) {
                    $msg .= " Catch-up: {$catchUp['emails_sent']} email(s) sent.";
                }
                if ($catchUp['queued'] > 0) {
                    $msg .= " {$catchUp['queued']} item(s) queued for review.";
                }
                if ($catchUp['emails_sent'] === 0 && $catchUp['queued'] === 0) {
                    $msg .= ' Catch-up: all SDSs already sent for shipments since ' . $newDate . '.';
                }
                $_SESSION['_flash']['success'] = $msg;
            } else {
                $_SESSION['_flash']['success'] = 'Customer updated.';
            }
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = $e->getMessage();
        }

        redirect('/customers/' . $id . '/edit');
    }

    public function sendForOrders(string $id): void
    {
        if (!can_edit('customers')) {
            redirect('/customers');
        }

        CSRF::validateRequest();

        $orderKeys = $_POST['orders'] ?? [];
        if (empty($orderKeys)) {
            $_SESSION['_flash']['error'] = 'No orders selected.';
            redirect('/customers/' . $id . '/edit');
            return;
        }

        if (!MailService::isConfigured()) {
            $_SESSION['_flash']['error'] = 'Mail is not configured. Check SMTP settings.';
            redirect('/customers/' . $id . '/edit');
            return;
        }

        $svc = new SDSAutoSendService();
        $result = $svc->sendForOrderKeys((int) $id, $orderKeys);

        if (!empty($result['errors'])) {
            $_SESSION['_flash']['error'] = implode('; ', $result['errors']);
        } elseif ($result['emails_sent'] > 0) {
            $msg = "{$result['emails_sent']} email(s) sent.";
            if ($result['queued'] > 0) {
                $msg .= " {$result['queued']} item(s) queued for review (no published SDS).";
            }
            $_SESSION['_flash']['success'] = $msg;
        } else {
            $msg = 'No SDSs were sent for the selected orders.';
            if ($result['skipped'] > 0) {
                $msg .= " {$result['skipped']} item(s) skipped.";
            }
            if ($result['queued'] > 0) {
                $msg .= " {$result['queued']} item(s) queued for review.";
            }
            if (!empty($result['skip_reasons'])) {
                $msg .= ' Details: ' . implode('; ', $result['skip_reasons']);
            }
            $_SESSION['_flash']['error'] = $msg;
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
