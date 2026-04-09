<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\CSRF;
use SDS\Core\Database;
use SDS\Models\Customer;
use SDS\Models\FinishedGood;
use SDS\Services\MailService;
use SDS\Services\PDFService;
use SDS\Services\SDSGenerator;

class SDSSendQueueController
{
    public function index(): void
    {
        $db = Database::getInstance();

        $items = $db->fetchAll(
            "SELECT q.*, c.ship_to_name AS customer_name, c.regulatory_email
             FROM sds_send_queue q
             JOIN customers c ON c.id = q.customer_id
             WHERE q.status = 'pending'
             ORDER BY q.shipment_date DESC, q.ship_to_name"
        );

        $sentCount = $db->fetch(
            "SELECT COUNT(*) AS cnt FROM sds_send_queue WHERE status = 'sent'"
        );

        view('sds-send-queue/index', [
            'pageTitle'  => 'SDS Send Queue',
            'items'      => $items,
            'sentCount'  => (int) ($sentCount['cnt'] ?? 0),
        ]);
    }

    /**
     * Send SDS for a single queued item.
     */
    public function send(string $id): void
    {
        if (!can_edit('sds_send_queue')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/sds-send-queue');
        }

        CSRF::validateRequest();
        $db = Database::getInstance();

        $item = $db->fetch("SELECT * FROM sds_send_queue WHERE id = ?", [(int) $id]);
        if (!$item || $item['status'] !== 'pending') {
            $_SESSION['_flash']['error'] = 'Queue item not found or already processed.';
            redirect('/sds-send-queue');
        }

        $customer = Customer::findById((int) $item['customer_id']);
        if (!$customer || empty($customer['regulatory_email'])) {
            $_SESSION['_flash']['error'] = 'Customer has no regulatory email.';
            redirect('/sds-send-queue');
        }

        // Determine item identifier
        $itemIdentifier = (!empty($item['item_name']) && $item['item_name'] !== $item['item_code'])
            ? $item['item_name'] : $item['item_code'];

        // Resolve to FG
        $stripped = str_contains($itemIdentifier, '-')
            ? substr($itemIdentifier, 0, strpos($itemIdentifier, '-'))
            : $itemIdentifier;

        $fg = FinishedGood::findByProductCode($stripped);
        if (!$fg) {
            $alias = $db->fetch("SELECT internal_code_base FROM aliases WHERE customer_code = ? LIMIT 1", [$stripped]);
            if ($alias) {
                $fg = FinishedGood::findByProductCode($alias['internal_code_base']);
            }
        }

        if (!$fg) {
            $_SESSION['_flash']['error'] = "Could not resolve product code for '{$itemIdentifier}'.";
            redirect('/sds-send-queue');
        }

        // Find published SDS
        $sdsVersion = $this->findPublishedSds((int) $fg['id'], $itemIdentifier, $db);
        if (!$sdsVersion) {
            $_SESSION['_flash']['error'] = "No published SDS found for '{$itemIdentifier}'. Please publish the SDS first.";
            redirect('/sds-send-queue');
        }

        try {
            $this->sendEmail($customer, $fg, $sdsVersion, $item, $db);

            $db->update('sds_send_queue', [
                'status'      => 'sent',
                'resolved_by' => current_user_id(),
                'resolved_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [(int) $id]);

            $_SESSION['_flash']['success'] = "SDS sent to {$customer['regulatory_email']} for {$itemIdentifier}.";
        } catch (\Throwable $e) {
            $_SESSION['_flash']['error'] = 'Send failed: ' . $e->getMessage();
        }

        redirect('/sds-send-queue');
    }

    /**
     * Send SDS for all pending items for a customer.
     */
    public function sendForCustomer(): void
    {
        if (!can_edit('sds_send_queue')) {
            redirect('/sds-send-queue');
        }

        CSRF::validateRequest();
        $db = Database::getInstance();
        $customerId = (int) ($_POST['customer_id'] ?? 0);

        $customer = Customer::findById($customerId);
        if (!$customer || empty($customer['regulatory_email'])) {
            $_SESSION['_flash']['error'] = 'Customer not found or has no regulatory email.';
            redirect('/sds-send-queue');
        }

        $items = $db->fetchAll(
            "SELECT * FROM sds_send_queue WHERE customer_id = ? AND status = 'pending'",
            [$customerId]
        );

        $sent = 0;
        $failed = 0;

        foreach ($items as $item) {
            $itemIdentifier = (!empty($item['item_name']) && $item['item_name'] !== $item['item_code'])
                ? $item['item_name'] : $item['item_code'];

            $stripped = str_contains($itemIdentifier, '-')
                ? substr($itemIdentifier, 0, strpos($itemIdentifier, '-'))
                : $itemIdentifier;

            $fg = FinishedGood::findByProductCode($stripped);
            if (!$fg) {
                $alias = $db->fetch("SELECT internal_code_base FROM aliases WHERE customer_code = ? LIMIT 1", [$stripped]);
                if ($alias) {
                    $fg = FinishedGood::findByProductCode($alias['internal_code_base']);
                }
            }

            if (!$fg) {
                $failed++;
                continue;
            }

            $sdsVersion = $this->findPublishedSds((int) $fg['id'], $itemIdentifier, $db);
            if (!$sdsVersion) {
                $failed++;
                continue;
            }

            try {
                $this->sendEmail($customer, $fg, $sdsVersion, $item, $db);
                $db->update('sds_send_queue', [
                    'status'      => 'sent',
                    'resolved_by' => current_user_id(),
                    'resolved_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [(int) $item['id']]);
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        $_SESSION['_flash']['success'] = "{$sent} SDS sent to {$customer['regulatory_email']}."
            . ($failed > 0 ? " {$failed} could not be sent." : '');
        redirect('/sds-send-queue');
    }

    public function dismiss(string $id): void
    {
        if (!can_edit('sds_send_queue')) {
            redirect('/sds-send-queue');
        }

        CSRF::validateRequest();
        $db = Database::getInstance();

        $db->update('sds_send_queue', [
            'status'      => 'dismissed',
            'resolved_by' => current_user_id(),
            'resolved_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [(int) $id]);

        $_SESSION['_flash']['success'] = 'Queue item dismissed.';
        redirect('/sds-send-queue');
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    private function findPublishedSds(int $fgId, string $itemIdentifier, Database $db): ?array
    {
        $alias = $db->fetch("SELECT id FROM aliases WHERE customer_code = ? LIMIT 1", [$itemIdentifier]);

        if ($alias) {
            $version = $db->fetch(
                "SELECT * FROM sds_versions
                 WHERE alias_id = ? AND status = 'published' AND is_deleted = 0 AND language = 'en'
                 ORDER BY version DESC LIMIT 1",
                [(int) $alias['id']]
            );
            if ($version) {
                return $version;
            }
        }

        return $db->fetch(
            "SELECT * FROM sds_versions
             WHERE finished_good_id = ? AND alias_id IS NULL AND status = 'published' AND is_deleted = 0 AND language = 'en'
             ORDER BY version DESC LIMIT 1",
            [$fgId]
        );
    }

    private function sendEmail(array $customer, array $fg, array $sdsVersion, array $queueItem, Database $db): void
    {
        $basePath = \SDS\Core\App::basePath();
        $pdfPath = $basePath . '/' . ltrim($sdsVersion['pdf_path'] ?? '', '/');

        $itemIdentifier = (!empty($queueItem['item_name']) && $queueItem['item_name'] !== $queueItem['item_code'])
            ? $queueItem['item_name'] : $queueItem['item_code'];
        $itemDesc = $queueItem['item_description'] ?? '';

        // If alias and SDS is non-alias, generate alias PDF
        $tempPdf = null;
        $alias = $db->fetch("SELECT * FROM aliases WHERE customer_code = ? LIMIT 1", [$itemIdentifier]);

        if ($alias && empty($sdsVersion['alias_id'])) {
            $snapshot = json_decode($sdsVersion['snapshot_json'] ?? '{}', true);
            if ($snapshot) {
                $snapshot = SDSGenerator::createAliasVariant(
                    $snapshot,
                    $alias['customer_code'],
                    $alias['description']
                );
                $tempPdf = tempnam(sys_get_temp_dir(), 'sds_queue_') . '.pdf';
                $pdfService = new PDFService();
                $pdfService->generateToFile($snapshot, $tempPdf);
                $pdfPath = $tempPdf;
            }
        }

        $companyRow = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'company.name'");
        $companyName = $companyRow['value'] ?? \SDS\Core\App::config('company.name', 'SDS System');

        $subjectRow = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'mail.sds_subject'");
        $subject = !empty($subjectRow['value']) ? $subjectRow['value'] : 'Safety Data Sheets';

        $bodyRow = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'mail.sds_body'");
        if (!empty($bodyRow['value'])) {
            $bodyText = str_replace('{company_name}', htmlspecialchars($companyName), $bodyRow['value']);
            $body = '<p>' . nl2br(htmlspecialchars_decode($bodyText)) . '</p>';
        } else {
            $body = "<p>Hello,</p>"
                . "<p>Please see attached for Safety Data Sheets from \"{$companyName}\".</p>"
                . "<p>Best regards,<br>Regulatory Team<br>\"{$companyName}\"</p>";
        }

        $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $itemIdentifier);

        MailService::send(
            $customer['regulatory_email'],
            $subject,
            $body,
            [['path' => $pdfPath, 'name' => $safeCode . '_SDS.pdf']]
        );

        if ($tempPdf !== null) {
            @unlink($tempPdf);
        }

        // Log the send
        $db->insert('sds_send_log', [
            'customer_id'      => (int) $customer['id'],
            'finished_good_id' => (int) $fg['id'],
            'item_identifier'  => $itemIdentifier,
            'sds_version_id'   => (int) $sdsVersion['id'],
            'language'         => 'en',
            'shipment_date'    => $queueItem['shipment_date'],
        ]);
    }
}
