<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\CSRF;
use SDS\Core\Database;
use SDS\Models\Customer;
use SDS\Models\FinishedGood;
use SDS\Services\AliasPublisher;
use SDS\Services\MailService;
use SDS\Services\SDSAutoSendService;
use SDS\Services\SdsNotRequiredException;

class SDSSendQueueController
{
    /** Why findPublishedSds() returned null for an alias (audit #53 / Q9). */
    private ?string $aliasError = null;

    /** findPublishedSds() returned null because the alias resolves to a raw material marked No SDS required (migration 064). */
    private bool $aliasNotRequired = false;

    /** RawMaterial::sdsNotRequiredBases() for this request (migration 064). */
    private ?array $noSdsBases = null;

    public function index(): void
    {
        $db = Database::getInstance();

        // Migration 064: pending rows for items marked No SDS required (non-
        // chemicals) are dismissed before the list is shown. This also runs
        // without mail configured, when the cron auto-send never retries.
        try {
            $dismissed = SDSAutoSendService::dismissNotRequiredPending($db);
            if ($dismissed > 0) {
                $_SESSION['_flash']['info'] = "{$dismissed} pending item(s) dismissed: they are marked No SDS required (not a chemical).";
            }
        } catch (\Throwable $e) {
            // Never break the queue page over the clean-up.
        }

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
        $alias = null;
        if (!$fg) {
            $alias = $db->fetch("SELECT internal_code_base FROM aliases WHERE customer_code = ? LIMIT 1", [$itemIdentifier])
                ?? $db->fetch("SELECT internal_code_base FROM aliases WHERE customer_code = ? LIMIT 1", [$stripped]);
            if ($alias) {
                $fg = FinishedGood::findByProductCode($alias['internal_code_base']);
            }
        }

        if (!$fg) {
            // Migration 064: a non-chemical is dismissed, not failed.
            if ($this->isNoSdsBase((string) ($alias['internal_code_base'] ?? $stripped), $db)) {
                $this->dismissNoSds($item, $itemIdentifier, $db);
            }
            $_SESSION['_flash']['error'] = "Could not resolve product code for '{$itemIdentifier}'.";
            redirect('/sds-send-queue');
        }

        // Find published SDS
        $sdsVersion = $this->findPublishedSds((int) $fg['id'], $itemIdentifier, $db);
        if (!$sdsVersion && $this->aliasNotRequired) {
            $this->dismissNoSds($item, $itemIdentifier, $db);
        }
        if (!$sdsVersion) {
            $_SESSION['_flash']['error'] = $this->aliasError !== null
                ? "Alias SDS for '{$itemIdentifier}' could not be published: {$this->aliasError}"
                : "No published SDS found for '{$itemIdentifier}'. Please publish the SDS first.";
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
        $notRequired = 0;

        foreach ($items as $item) {
            $itemIdentifier = (!empty($item['item_name']) && $item['item_name'] !== $item['item_code'])
                ? $item['item_name'] : $item['item_code'];

            $stripped = str_contains($itemIdentifier, '-')
                ? substr($itemIdentifier, 0, strpos($itemIdentifier, '-'))
                : $itemIdentifier;

            $fg = FinishedGood::findByProductCode($stripped);
            $alias = null;
            if (!$fg) {
                $alias = $db->fetch("SELECT internal_code_base FROM aliases WHERE customer_code = ? LIMIT 1", [$itemIdentifier])
                    ?? $db->fetch("SELECT internal_code_base FROM aliases WHERE customer_code = ? LIMIT 1", [$stripped]);
                if ($alias) {
                    $fg = FinishedGood::findByProductCode($alias['internal_code_base']);
                }
            }

            if (!$fg) {
                // Migration 064: a non-chemical is dismissed, not counted as failed.
                if ($this->isNoSdsBase((string) ($alias['internal_code_base'] ?? $stripped), $db)) {
                    if (SDSAutoSendService::dismissQueueItemNoSds($db, (int) $item['id'], (string) ($item['reason'] ?? ''), current_user_id())) {
                        $notRequired++;
                    }
                    continue;
                }
                $failed++;
                continue;
            }

            $sdsVersion = $this->findPublishedSds((int) $fg['id'], $itemIdentifier, $db);
            if (!$sdsVersion) {
                if ($this->aliasNotRequired) {
                    if (SDSAutoSendService::dismissQueueItemNoSds($db, (int) $item['id'], (string) ($item['reason'] ?? ''), current_user_id())) {
                        $notRequired++;
                    }
                    continue;
                }
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
            . ($failed > 0 ? " {$failed} could not be sent." : '')
            . ($notRequired > 0 ? " {$notRequired} dismissed: marked No SDS required (not a chemical)." : '');
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

    /**
     * Migration 064: is $base (the code this controller's own resolution
     * produced when it found no finished good) a base whose representative
     * raw material is marked No SDS required? Loaded once per request.
     */
    private function isNoSdsBase(string $base, Database $db): bool
    {
        $this->noSdsBases ??= \SDS\Models\RawMaterial::sdsNotRequiredBases($db);
        return isset($this->noSdsBases[\SDS\Models\RawMaterial::baseKey($base)]);
    }

    /** Migration 064: dismiss one queue row whose item needs no SDS, flash why, back to the queue. */
    private function dismissNoSds(array $item, string $itemIdentifier, Database $db): never
    {
        SDSAutoSendService::dismissQueueItemNoSds($db, (int) $item['id'], (string) ($item['reason'] ?? ''), current_user_id());
        $_SESSION['_flash']['success'] = "Nothing to send for '{$itemIdentifier}': it is marked No SDS required (not a chemical). The queue item was dismissed.";
        redirect('/sds-send-queue');
    }

    private function findPublishedSds(int $fgId, string $itemIdentifier, Database $db): ?array
    {
        $this->aliasError = null;
        $this->aliasNotRequired = false;
        $alias = $db->fetch("SELECT id FROM aliases WHERE customer_code = ? LIMIT 1", [$itemIdentifier]);

        if ($alias) {
            // Audit #53 / Q9: an alias is sent as ITS OWN published sheet
            // (pack variants share one), published first when it has none.
            // Never the base sheet re-branded from a snapshot.
            try {
                $rows = AliasPublisher::ensurePublished((int) $alias['id'], current_user_id(), 'Published for the SDS send queue', $db, ['en']);
                return $rows['en'] ?? null;
            } catch (SdsNotRequiredException $e) {
                // Migration 064: the alias resolves to a non-chemical.
                $this->aliasNotRequired = true;
                $this->aliasError = $e->getMessage();
                return null;
            } catch (\Throwable $e) {
                $this->aliasError = $e->getMessage();
                return null;
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

        // Audit #53 / Q9: the attachment is always the PDF of the row passed
        // in (an alias's own published sheet for alias items) — never a
        // snapshot re-render.

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
