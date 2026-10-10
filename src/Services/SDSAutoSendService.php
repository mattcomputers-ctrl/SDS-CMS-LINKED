<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\App;
use SDS\Core\Database;
use SDS\Models\Customer;
use SDS\Models\FinishedGood;

/**
 * SDSAutoSendService — sends SDS documents to customer regulatory
 * contacts based on shipment data.
 *
 * Called after each CMS sync, AFTER the bulk SDS publish step in
 * cron/cms-sync.php — by then any SDS that could have been published
 * already has been, so this service only has to match shipments to
 * published SDSs and send the emails (or queue missing-data items
 * for regulatory review).
 *
 * The flow is:
 *   1. Identify new shipments since last run.
 *   2. For each shipment to a customer with a regulatory email:
 *      - Determine if an SDS needs to be sent (based on send mode)
 *      - If SDS is published → send email with PDF
 *      - If SDS can't be published (missing data) → queue for regulatory review
 *
 * Auto-send never publishes a base sheet. The bulk publish (cron/bulk-publish.php)
 * runs first and applies every publish gate (SDSReadinessService). The one
 * exception is an alias with no published sheet of its own (audit #53 / Q9):
 * AliasPublisher::ensurePublished() publishes it first, through the same gates. The old
 * autoPublishReady()/canAutoPublish()/publishSds() path was dead code and
 * was removed (audit #69).
 */
class SDSAutoSendService
{
    private Database $db;

    /** Why getLatestPublishedSds() returned null for an alias (audit #53 / Q9). */
    private ?string $aliasPublishError = null;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Main entry point — called after CMS sync + bulk publish.
     *
     * No longer auto-publishes SDSs itself; cron/bulk-publish.php
     * handles that using the Bulk SDS Publish page's eligibility
     * rules, running before this service is invoked.
     *
     * @param  string|null $sessionStart Retained for call-site
     *                                   compatibility; no longer used.
     *                                   Shipment selection is driven by
     *                                   the sds_processed_at marker —
     *                                   time-window comparisons silently
     *                                   missed rows whenever the PHP and
     *                                   MySQL clocks disagreed.
     */
    public function processNewShipments(?string $sessionStart = null): array
    {
        $results = [
            'emails_sent' => 0,
            'queued'      => 0,
            'skipped'     => 0,
            'errors'      => [],
            'retried'     => 0,
        ];

        // Phase A: Process shipments not yet examined by auto-send
        $this->processUnsentShipments($results);
        $this->setLastRunTimestamp();

        // Phase B: Retry pending queue items whose SDS may now be available
        $this->retryPendingQueue($results);

        // Phase C: Notify regulatory staff if items were queued
        if ($results['queued'] > 0) {
            $this->notifyRegulatoryStaff($results['queued']);
        }

        return $results;
    }

    /* ------------------------------------------------------------------
     *  Phase B: Process Shipments
     * ----------------------------------------------------------------*/

    private function processUnsentShipments(array &$results): void
    {
        // Rows are selected by the sds_processed_at marker, never by a
        // time window: imported_at is stamped by MySQL while cron session
        // timestamps come from PHP, and any clock/timezone disagreement
        // between the two made the old window match nothing — shipments
        // were then permanently skipped without ever being queued.
        $shipments = $this->db->fetchAll(
            "SELECT sd.* FROM shipment_detail sd
             WHERE sd.sds_processed_at IS NULL
             ORDER BY sd.date_shipped"
        );

        if (empty($shipments)) {
            return;
        }

        // Load all active customers with emails, keyed by ship_to
        $customerMap = [];
        foreach (Customer::getActiveWithEmail() as $cust) {
            $customerMap[$cust['ship_to']] = $cust;
        }

        // Pre-load FG map for performance
        $fgCache = [];

        // Group shipment lines into orders: key = "customer_id::order_number::date"
        // Each order collects its items that need SDS sent
        $orders = [];
        $orderQueued = []; // track orders with queued items

        foreach ($shipments as $row) {
            $shipTo = $row['ship_to'] ?? '';
            $customer = $customerMap[$shipTo] ?? null;

            if ($customer === null) {
                $results['skipped']++;
                continue;
            }

            if (empty($customer['sds_send_active_since']) || ($row['date_shipped'] ?? '') < $customer['sds_send_active_since']) {
                $results['skipped']++;
                continue;
            }

            $itemName = (!empty($row['item_name']) && $row['item_name'] !== $row['item_code'])
                ? $row['item_name']
                : $row['item_code'];

            // Resolve to finished good
            $fgProductCode = $this->resolveToProductCode($itemName);
            if ($fgProductCode === null) {
                $this->queueForReview($customer, $row, "Product not found in system — '{$itemName}' could not be resolved to a finished good");
                $results['queued']++;
                continue;
            }

            if (!isset($fgCache[$fgProductCode])) {
                $fgCache[$fgProductCode] = FinishedGood::findByProductCode($fgProductCode);
            }
            $fg = $fgCache[$fgProductCode];
            if ($fg === null) {
                $this->queueForReview($customer, $row, "Finished good '{$fgProductCode}' not found in database");
                $results['queued']++;
                continue;
            }

            // Look up the latest published SDS first — we need its ID
            // for the shouldSend version comparison.
            $sdsVersion = $this->getLatestPublishedSds((int) $fg['id'], $itemName);

            if ($sdsVersion === null) {
                $this->queueForReview($customer, $row, $this->sdsUnavailableReason());
                $results['queued']++;
                continue;
            }

            // Check if we need to send based on mode
            if (!$this->shouldSend($customer, (int) $fg['id'], $itemName, $row['date_shipped'] ?? null, (int) $sdsVersion['id'])) {
                $results['skipped']++;
                continue;
            }

            // Group by order: customer + order_number + shipment date
            $orderKey = $customer['id'] . '::' . ($row['order_number'] ?? '') . '::' . ($row['date_shipped'] ?? '');

            if (!isset($orders[$orderKey])) {
                $orders[$orderKey] = [
                    'customer'      => $customer,
                    'order_number'  => $row['order_number'] ?? '',
                    'date_shipped'  => $row['date_shipped'] ?? '',
                    'items'         => [],
                ];
            }

            // Deduplicate by alias without pack extension — different
            // pack sizes (Y1011-50, Y1011-84) map to the same SDS.
            $strippedName = str_contains($itemName, '-') ? substr($itemName, 0, strpos($itemName, '-')) : $itemName;
            $alreadyInOrder = false;
            foreach ($orders[$orderKey]['items'] as $existing) {
                $existingStripped = str_contains($existing['item_identifier'], '-')
                    ? substr($existing['item_identifier'], 0, strpos($existing['item_identifier'], '-'))
                    : $existing['item_identifier'];
                if ($existingStripped === $strippedName) {
                    $alreadyInOrder = true;
                    break;
                }
            }

            if (!$alreadyInOrder) {
                $orders[$orderKey]['items'][] = [
                    'item_identifier' => $itemName,
                    'fg'              => $fg,
                    'sds_version'     => $sdsVersion,
                ];
            }
        }

        // Send one email per order with all PDFs attached
        foreach ($orders as $order) {
            try {
                $this->sendOrderEmail($order['customer'], $order['items'], $order['date_shipped']);
                $results['emails_sent']++;
            } catch (\Throwable $e) {
                $results['errors'][] = "Send to {$order['customer']['ship_to']}: " . $e->getMessage();
                // Queue failed items so the retry phase picks them up next run
                foreach ($order['items'] as $orderItem) {
                    $fakeRow = [
                        'ship_to'               => $order['customer']['ship_to'] ?? '',
                        'ship_to_name'           => $order['customer']['ship_to_name'] ?? '',
                        'date_shipped'           => $order['date_shipped'],
                        'item_code'              => $orderItem['fg']['product_code'] ?? $orderItem['item_identifier'],
                        'item_name'              => $orderItem['item_identifier'],
                        'item_name_description'  => $orderItem['fg']['description'] ?? '',
                        'item_description'       => $orderItem['fg']['description'] ?? '',
                    ];
                    $this->queueForReview($order['customer'], $fakeRow, 'Email send failed — ' . $e->getMessage());
                    $results['queued']++;
                }
            }
        }

        // Mark every scanned row as examined — including skipped ones
        // (no customer, gated by active-since date, or already sent).
        // Stamped only after the send loop so a crash mid-run leaves
        // rows NULL for the next sweep; sds_send_log dedup in
        // shouldSend() keeps re-scanned rows from emailing twice.
        // Failed sends are queued above, so the retry phase owns them.
        $now = date('Y-m-d H:i:s');
        foreach (array_chunk(array_column($shipments, 'id'), 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $this->db->update(
                'shipment_detail',
                ['sds_processed_at' => $now],
                "id IN ({$placeholders})",
                array_map('intval', $chunk)
            );
        }
    }

    /**
     * Determine if an SDS should be sent based on the customer's send mode.
     *
     * @param int $currentSdsVersionId  The sds_versions.id that would be sent
     *                                  (from getLatestPublishedSds). Compared
     *                                  against the last-sent version to detect
     *                                  updates — avoids a MAX(id) query that
     *                                  miscompares across unrelated aliases.
     */
    private function shouldSend(array $customer, int $fgId, string $itemIdentifier, ?string $shipmentDate = null, ?int $currentSdsVersionId = null): bool
    {
        $mode = $customer['sds_send_mode'];

        if ($mode === 'every_order') {
            // Send once per shipment — check if we already sent for
            // this exact item + shipment date combination.
            if ($shipmentDate !== null) {
                $alreadySent = $this->db->fetch(
                    "SELECT id FROM sds_send_log
                     WHERE customer_id = ? AND item_identifier = ? AND shipment_date = ?
                     LIMIT 1",
                    [(int) $customer['id'], $itemIdentifier, $shipmentDate]
                );
                if ($alreadySent) {
                    return false;
                }
            }
            return true;
        }

        // Find the last send for this customer + item
        $lastSend = $this->db->fetch(
            // Alias `slog` (not `ssl`) — MariaDB parses `ssl` as a keyword
            "SELECT slog.sent_at, slog.sds_version_id
             FROM sds_send_log slog
             WHERE slog.customer_id = ? AND slog.item_identifier = ?
             ORDER BY slog.sent_at DESC LIMIT 1",
            [(int) $customer['id'], $itemIdentifier]
        );

        if ($lastSend === null) {
            return true; // Never sent — first shipment
        }

        // Check if SDS has been updated since last send by comparing
        // the version that would be sent now against what was last sent.
        if ($currentSdsVersionId !== null) {
            if ($currentSdsVersionId !== (int) $lastSend['sds_version_id']) {
                return true;
            }
        }

        // OSHA + 6mo: also send if last send was > 6 months ago
        if ($mode === 'osha_6mo') {
            $sixMonthsAgo = PublishClock::nowUtc(strtotime('-6 months'));   // sent_at is UTC (audit #59)
            if ($lastSend['sent_at'] < $sixMonthsAgo) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the latest published SDS version for a specific item identifier.
     * If the item is an alias, returns the alias's own published SDS (published first when missing) and never the base sheet.
     */
    private function getLatestPublishedSds(int $fgId, string $itemIdentifier): ?array
    {
        // Check if this identifier is an alias
        $alias = $this->db->fetch(
            "SELECT id, customer_code, internal_code_base FROM aliases WHERE customer_code = ? LIMIT 1",
            [$itemIdentifier]
        );

        $this->aliasPublishError = null;
        if ($alias) {
            // Audit #53 / Q9: an alias is always sent as its OWN published
            // sheet (alias base code + description); published first when it
            // has none. Never the base sheet, never another alias's sheet.
            try {
                $rows = AliasPublisher::ensurePublished((int) $alias['id'], null, 'Auto-published for shipment SDS send', $this->db);
                $default = strtolower((string) App::config('sds.default_language', 'en'));
                return $rows[$default] ?? (reset($rows) ?: null);
            } catch (\Throwable $e) {
                $this->aliasPublishError = $e->getMessage();
                return null;
            }
        }

        // Not an alias: the FG's published SDS
        return $this->db->fetch(
            "SELECT * FROM sds_versions
             WHERE finished_good_id = ? AND alias_id IS NULL AND status = 'published' AND is_deleted = 0 AND language = 'en'
             ORDER BY version DESC LIMIT 1",
            [$fgId]
        );
    }

    /**
     * Send an SDS email to a customer's regulatory contact.
     */
    /**
     * Send one email per order with all SDS PDFs for that order attached.
     *
     * @param array $customer  Customer record
     * @param array $items     Array of ['item_identifier', 'fg', 'sds_version']
     * @param string|null $shipmentDate
     */
    private function sendOrderEmail(array $customer, array $items, ?string $shipmentDate): void
    {
        if (!MailService::isConfigured()) {
            throw new \RuntimeException('Mail not configured');
        }

        $basePath = App::basePath();
        $languages = \SDS\Models\Customer::getLanguages($customer);

        $attachments = [];
        $tempFiles = [];
        $seenAttachNames = [];

        // For each item in the order, collect PDFs in all requested languages
        foreach ($items as $orderItem) {
            $itemIdentifier = $orderItem['item_identifier'];
            $fg = $orderItem['fg'];

            $alias = $this->db->fetch(
                "SELECT * FROM aliases WHERE customer_code = ? LIMIT 1",
                [$itemIdentifier]
            );

            // Strip pack extension for filename (R1055-84 → R1055)
            $displayCode = $alias
                ? (str_contains($itemIdentifier, '-') ? substr($itemIdentifier, 0, strpos($itemIdentifier, '-')) : $itemIdentifier)
                : $itemIdentifier;
            $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $displayCode);

            // Audit #53 / Q9: alias rows per language from the alias's own
            // sheet (published first when missing); null = not an alias.
            $aliasRows = null;
            if ($alias) {
                try {
                    $aliasRows = AliasPublisher::ensurePublished((int) $alias['id'], null, 'Auto-published for shipment SDS send', $this->db, $languages);
                } catch (\Throwable $e) {
                    $aliasRows = []; // not publishable: attach nothing for this alias (never the base sheet)
                }
            }

            foreach ($languages as $lang) {
                if ($aliasRows !== null) {
                    $langVersion = $aliasRows[strtolower((string) $lang)] ?? null;
                } else {
                    $langVersion = $this->db->fetch(
                        "SELECT * FROM sds_versions
                         WHERE finished_good_id = ? AND alias_id IS NULL AND language = ? AND status = 'published' AND is_deleted = 0
                         ORDER BY version DESC LIMIT 1",
                        [(int) $fg['id'], $lang]
                    );
                }

                if (!$langVersion) {
                    continue;
                }

                $pdfPath = $basePath . '/' . ltrim($langVersion['pdf_path'] ?? '', '/');

                if (!file_exists($pdfPath)) {
                    continue;
                }

                $langSuffix = ($lang !== 'en') ? '_' . strtoupper($lang) : '';
                $attachName = $safeCode . '_SDS' . $langSuffix . '.pdf';

                // Deduplicate attachments (same item in multiple shipment lines)
                if (isset($seenAttachNames[$attachName])) {
                    continue;
                }
                $seenAttachNames[$attachName] = true;

                $attachments[] = ['path' => $pdfPath, 'name' => $attachName];
            }
        }

        if (empty($attachments)) {
            foreach ($tempFiles as $f) { @unlink($f); }
            return; // Nothing to send for this order
        }

        // Build email
        $companyName = $this->getCompanyName();

        $subject = $this->getEmailSubject();
        $body = $this->getEmailBody($companyName);

        MailService::send(
            $customer['regulatory_email'],
            $subject,
            $body,
            $attachments
        );

        // Clean up temp PDFs
        foreach ($tempFiles as $f) { @unlink($f); }

        // Log the send — one entry per item
        foreach ($items as $orderItem) {
            $this->db->insert('sds_send_log', [
                'customer_id'      => (int) $customer['id'],
                'finished_good_id' => (int) $orderItem['fg']['id'],
                'item_identifier'  => $orderItem['item_identifier'],
                'sds_version_id'   => (int) $orderItem['sds_version']['id'],
                'language'         => implode(',', $languages),
                'shipment_date'    => $shipmentDate,
            ]);
        }
    }

    /**
     * Get the company name from admin settings.
     */
    private function getCompanyName(): string
    {
        $row = $this->db->fetch("SELECT `value` FROM settings WHERE `key` = 'company.name'");
        return $row['value'] ?? App::config('company.name', 'SDS System');
    }

    private function getEmailSubject(): string
    {
        $row = $this->db->fetch("SELECT `value` FROM settings WHERE `key` = 'mail.sds_subject'");
        return !empty($row['value']) ? $row['value'] : 'Safety Data Sheets';
    }

    private function getEmailBody(string $companyName): string
    {
        $row = $this->db->fetch("SELECT `value` FROM settings WHERE `key` = 'mail.sds_body'");

        if (!empty($row['value'])) {
            $text = str_replace('{company_name}', htmlspecialchars($companyName), $row['value']);
            // Convert line breaks to HTML
            return '<p>' . nl2br(htmlspecialchars_decode($text)) . '</p>';
        }

        // Default
        return "<p>Hello,</p>"
            . "<p>Please see attached for Safety Data Sheets from \"{$companyName}\".</p>"
            . "<p>Best regards,<br>Regulatory Team<br>\"{$companyName}\"</p>";
    }

    /* ------------------------------------------------------------------
     *  Retry Pending Queue
     * ----------------------------------------------------------------*/

    /**
     * Retry pending queue items whose SDS may now be available.
     *
     * Items land in sds_send_queue when auto-send can't resolve them
     * (product not found, SDS not published, etc.). Each cron run
     * retries all pending items — if the blocker has been fixed, the
     * SDS is sent and the queue entry marked 'sent'.
     */
    private function retryPendingQueue(array &$results): void
    {
        $pending = $this->db->fetchAll(
            "SELECT q.*, c.regulatory_email, c.sds_send_mode, c.sds_languages, c.sds_send_active_since
             FROM sds_send_queue q
             JOIN customers c ON c.id = q.customer_id
             WHERE q.status = 'pending' AND c.is_active = 1 AND c.regulatory_email IS NOT NULL AND c.regulatory_email != ''
               AND c.sds_send_active_since IS NOT NULL AND q.shipment_date >= c.sds_send_active_since
             ORDER BY q.shipment_date"
        );

        if (empty($pending)) {
            return;
        }

        $fgCache = [];
        $orders = [];

        foreach ($pending as $item) {
            $itemIdentifier = (!empty($item['item_name']) && $item['item_name'] !== $item['item_code'])
                ? $item['item_name'] : $item['item_code'];

            $fgProductCode = $this->resolveToProductCode($itemIdentifier);
            if ($fgProductCode === null) {
                continue;
            }

            if (!isset($fgCache[$fgProductCode])) {
                $fgCache[$fgProductCode] = FinishedGood::findByProductCode($fgProductCode);
            }
            $fg = $fgCache[$fgProductCode];
            if ($fg === null) {
                continue;
            }

            $sdsVersion = $this->getLatestPublishedSds((int) $fg['id'], $itemIdentifier);
            if ($sdsVersion === null) {
                continue;
            }

            $customer = [
                'id'             => $item['customer_id'],
                'ship_to'        => $item['ship_to'],
                'ship_to_name'   => $item['ship_to_name'],
                'regulatory_email' => $item['regulatory_email'],
                'sds_send_mode'  => $item['sds_send_mode'],
                'sds_languages'  => $item['sds_languages'] ?? 'en',
            ];

            if (!$this->shouldSend($customer, (int) $fg['id'], $itemIdentifier, $item['shipment_date'], (int) $sdsVersion['id'])) {
                $this->db->update('sds_send_queue', [
                    'status'      => 'sent',
                    'resolved_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [(int) $item['id']]);
                $results['retried']++;
                continue;
            }

            $orderKey = $item['customer_id'] . '::retry::' . $item['shipment_date'];

            if (!isset($orders[$orderKey])) {
                $orders[$orderKey] = [
                    'customer'      => $customer,
                    'date_shipped'  => $item['shipment_date'],
                    'items'         => [],
                    'queue_ids'     => [],
                ];
            }

            $strippedName = str_contains($itemIdentifier, '-') ? substr($itemIdentifier, 0, strpos($itemIdentifier, '-')) : $itemIdentifier;
            $alreadyInOrder = false;
            foreach ($orders[$orderKey]['items'] as $existing) {
                $existingStripped = str_contains($existing['item_identifier'], '-')
                    ? substr($existing['item_identifier'], 0, strpos($existing['item_identifier'], '-'))
                    : $existing['item_identifier'];
                if ($existingStripped === $strippedName) {
                    $alreadyInOrder = true;
                    break;
                }
            }

            if (!$alreadyInOrder) {
                $orders[$orderKey]['items'][] = [
                    'item_identifier' => $itemIdentifier,
                    'fg'              => $fg,
                    'sds_version'     => $sdsVersion,
                ];
            }
            $orders[$orderKey]['queue_ids'][] = (int) $item['id'];
        }

        foreach ($orders as $order) {
            if (empty($order['items'])) {
                continue;
            }
            try {
                $this->sendOrderEmail($order['customer'], $order['items'], $order['date_shipped']);
                $results['emails_sent']++;
            } catch (\Throwable $e) {
                $results['errors'][] = 'Queue retry: ' . $e->getMessage();
                continue;
            }

            $now = date('Y-m-d H:i:s');
            foreach ($order['queue_ids'] as $qId) {
                $this->db->update('sds_send_queue', [
                    'status'      => 'sent',
                    'resolved_at' => $now,
                ], 'id = ?', [$qId]);
            }
            $results['retried'] += count($order['queue_ids']);
        }
    }

    /* ------------------------------------------------------------------
     *  Catch-Up: backfill sends when active-since date is set/moved
     * ----------------------------------------------------------------*/

    /**
     * Process all shipments for a customer from their sds_send_active_since
     * date onward, sending any SDSs that haven't been sent yet.
     */
    public function catchUpCustomer(int $customerId): array
    {
        $customer = Customer::findById($customerId);
        if ($customer === null || empty($customer['regulatory_email']) || empty($customer['sds_send_active_since'])) {
            return ['emails_sent' => 0, 'queued' => 0, 'skipped' => 0, 'errors' => []];
        }

        $shipments = $this->db->fetchAll(
            "SELECT sd.* FROM shipment_detail sd
             WHERE sd.ship_to = ? AND sd.date_shipped >= ?
             ORDER BY sd.date_shipped",
            [$customer['ship_to'], $customer['sds_send_active_since']]
        );

        $results = ['emails_sent' => 0, 'queued' => 0, 'skipped' => 0, 'errors' => []];
        $fgCache = [];
        $orders = [];

        foreach ($shipments as $row) {
            $itemName = (!empty($row['item_name']) && $row['item_name'] !== $row['item_code'])
                ? $row['item_name']
                : $row['item_code'];

            $fgProductCode = $this->resolveToProductCode($itemName);
            if ($fgProductCode === null) {
                $this->queueForReview($customer, $row, "Product not found in system — '{$itemName}' could not be resolved to a finished good");
                $results['queued']++;
                continue;
            }

            if (!isset($fgCache[$fgProductCode])) {
                $fgCache[$fgProductCode] = FinishedGood::findByProductCode($fgProductCode);
            }
            $fg = $fgCache[$fgProductCode];
            if ($fg === null) {
                $this->queueForReview($customer, $row, "Finished good '{$fgProductCode}' not found in database");
                $results['queued']++;
                continue;
            }

            $sdsVersion = $this->getLatestPublishedSds((int) $fg['id'], $itemName);
            if ($sdsVersion === null) {
                $this->queueForReview($customer, $row, $this->sdsUnavailableReason());
                $results['queued']++;
                continue;
            }

            if (!$this->shouldSend($customer, (int) $fg['id'], $itemName, $row['date_shipped'] ?? null, (int) $sdsVersion['id'])) {
                $results['skipped']++;
                continue;
            }

            $orderKey = ($row['order_number'] ?? '') . '::' . ($row['date_shipped'] ?? '');
            if (!isset($orders[$orderKey])) {
                $orders[$orderKey] = [
                    'customer'     => $customer,
                    'order_number' => $row['order_number'] ?? '',
                    'date_shipped' => $row['date_shipped'] ?? '',
                    'items'        => [],
                ];
            }

            $strippedName = str_contains($itemName, '-') ? substr($itemName, 0, strpos($itemName, '-')) : $itemName;
            $alreadyInOrder = false;
            foreach ($orders[$orderKey]['items'] as $existing) {
                $existingStripped = str_contains($existing['item_identifier'], '-')
                    ? substr($existing['item_identifier'], 0, strpos($existing['item_identifier'], '-'))
                    : $existing['item_identifier'];
                if ($existingStripped === $strippedName) {
                    $alreadyInOrder = true;
                    break;
                }
            }

            if (!$alreadyInOrder) {
                $orders[$orderKey]['items'][] = [
                    'item_identifier' => $itemName,
                    'fg'              => $fg,
                    'sds_version'     => $sdsVersion,
                ];
            }
        }

        foreach ($orders as $order) {
            try {
                $this->sendOrderEmail($order['customer'], $order['items'], $order['date_shipped']);
                $results['emails_sent']++;
            } catch (\Throwable $e) {
                $results['errors'][] = "Order {$order['order_number']}: " . $e->getMessage();
                foreach ($order['items'] as $orderItem) {
                    $fakeRow = [
                        'ship_to'               => $customer['ship_to'] ?? '',
                        'ship_to_name'           => $customer['ship_to_name'] ?? '',
                        'date_shipped'           => $order['date_shipped'],
                        'item_code'              => $orderItem['fg']['product_code'] ?? $orderItem['item_identifier'],
                        'item_name'              => $orderItem['item_identifier'],
                        'item_name_description'  => $orderItem['fg']['description'] ?? '',
                        'item_description'       => $orderItem['fg']['description'] ?? '',
                    ];
                    $this->queueForReview($customer, $fakeRow, 'Email send failed — ' . $e->getMessage());
                    $results['queued']++;
                }
            }
        }

        return $results;
    }

    /* ------------------------------------------------------------------
     *  Queue & Notifications
     * ----------------------------------------------------------------*/

    private function queueForReview(array $customer, array $shipmentRow, string $reason): void
    {
        // Check if already queued for this shipment
        $existing = $this->db->fetch(
            "SELECT id FROM sds_send_queue
             WHERE customer_id = ? AND item_code = ? AND shipment_date = ? AND status = 'pending'",
            [(int) $customer['id'], $shipmentRow['item_code'], $shipmentRow['date_shipped']]
        );

        if ($existing) {
            return; // Already queued
        }

        $itemName = (!empty($shipmentRow['item_name']) && $shipmentRow['item_name'] !== $shipmentRow['item_code'])
            ? $shipmentRow['item_name'] : null;
        $desc = $itemName
            ? ($shipmentRow['item_name_description'] ?? '')
            : ($shipmentRow['item_description'] ?? '');

        // sds_send_queue.reason is VARCHAR(500) (migration 031). Alias
        // publish-gate messages (Section 14 / trade-secret Prop 65) can be
        // longer; under strict SQL mode an over-long value throws and
        // aborts the whole auto-send run before sds_processed_at is
        // stamped, so every later run fails on the same row.
        $reason = self::clampReason($reason);

        $this->db->insert('sds_send_queue', [
            'customer_id'      => (int) $customer['id'],
            'ship_to'          => $shipmentRow['ship_to'] ?? '',
            'ship_to_name'     => $shipmentRow['ship_to_name'] ?? '',
            'shipment_date'    => $shipmentRow['date_shipped'],
            'item_code'        => $shipmentRow['item_code'],
            'item_name'        => $itemName,
            'item_description' => $desc,
            'reason'           => $reason,
        ]);
    }

    /** Cut a queue reason to the sds_send_queue.reason width (500 chars). */
    public static function clampReason(string $reason, int $max = 500): string
    {
        if (mb_strlen($reason, 'UTF-8') <= $max) {
            return $reason;
        }
        return mb_substr($reason, 0, $max - 1, 'UTF-8') . '…';
    }

    private function notifyRegulatoryStaff(int $queuedCount): void
    {
        if (!MailService::isConfigured()) {
            return;
        }

        $emails = MailService::getRegulatoryEmails();
        if (empty($emails)) {
            return;
        }

        $serverUrl = App::config('app.url', 'http://localhost');
        $subject = "SDS System: {$queuedCount} shipment(s) need SDS attention";
        $body = "<p>{$queuedCount} shipment(s) could not have SDS automatically sent because the SDS data is not yet complete.</p>"
            . "<p>Please review and complete the required raw material data or CAS determinations, then send the SDSs from the queue:</p>"
            . "<p><a href=\"{$serverUrl}/sds-send-queue\">{$serverUrl}/sds-send-queue</a></p>"
            . "<p>— SDS System</p>";

        try {
            MailService::send($emails, $subject, $body);
        } catch (\Throwable $e) {
            // Non-fatal — regulatory notification is best-effort
        }
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    private function resolveToProductCode(string $code): ?string
    {
        // Try full code as an alias first (aliases store the pack extension)
        $alias = $this->db->fetch("SELECT internal_code_base FROM aliases WHERE customer_code = ? LIMIT 1", [$code]);
        if ($alias) {
            return $alias['internal_code_base'];
        }

        // Strip pack extension (e.g. R1055-84 → R1055) and try as product code
        $stripped = str_contains($code, '-') ? substr($code, 0, strpos($code, '-')) : $code;

        $fg = $this->db->fetch("SELECT product_code FROM finished_goods WHERE product_code = ?", [$stripped]);
        if ($fg) {
            return $fg['product_code'];
        }

        // Try stripped code as alias too
        if ($stripped !== $code) {
            $alias = $this->db->fetch("SELECT internal_code_base FROM aliases WHERE customer_code = ? LIMIT 1", [$stripped]);
            if ($alias) {
                return $alias['internal_code_base'];
            }
        }

        return null;
    }

    /**
     * Send SDSs for specific orders selected by the user.
     *
     * @param string[] $orderKeys  Each key is "order_number::date_shipped"
     */
    public function sendForOrderKeys(int $customerId, array $orderKeys): array
    {
        $customer = Customer::findById($customerId);
        if ($customer === null || empty($customer['regulatory_email'])) {
            return ['emails_sent' => 0, 'queued' => 0, 'skipped' => 0,
                    'errors' => ['Customer not found or has no regulatory email.']];
        }

        $results = ['emails_sent' => 0, 'queued' => 0, 'skipped' => 0, 'errors' => []];

        $shipments = $this->db->fetchAll(
            "SELECT sd.* FROM shipment_detail sd
             WHERE sd.ship_to = ?
             ORDER BY sd.date_shipped",
            [$customer['ship_to']]
        );

        $allowed = array_flip($orderKeys);
        $fgCache = [];
        $orders  = [];
        $skipReasons = [];

        foreach ($shipments as $row) {
            $key = ($row['order_number'] ?? '') . '::' . ($row['date_shipped'] ?? '');
            if (!isset($allowed[$key])) {
                continue;
            }

            $itemName = (!empty($row['item_name']) && $row['item_name'] !== $row['item_code'])
                ? $row['item_name']
                : $row['item_code'];

            $fgProductCode = $this->resolveToProductCode($itemName);
            if ($fgProductCode === null) {
                $stripped = str_contains($itemName, '-') ? substr($itemName, 0, strpos($itemName, '-')) : $itemName;
                $skipReasons[] = "{$itemName} (looked up '{$stripped}' from item_name='{$row['item_name']}', item_code='{$row['item_code']}'): no matching product";
                $results['skipped']++;
                continue;
            }

            if (!isset($fgCache[$fgProductCode])) {
                $fgCache[$fgProductCode] = FinishedGood::findByProductCode($fgProductCode);
            }
            $fg = $fgCache[$fgProductCode];
            if ($fg === null) {
                $skipReasons[] = "{$itemName} ({$fgProductCode}): product not in database";
                $results['skipped']++;
                continue;
            }

            $sdsVersion = $this->getLatestPublishedSds((int) $fg['id'], $itemName);

            if ($sdsVersion === null) {
                $this->queueForReview($customer, $row, $this->sdsUnavailableReason());
                $results['queued']++;
                continue;
            }

            if (!isset($orders[$key])) {
                $orders[$key] = [
                    'customer'     => $customer,
                    'order_number' => $row['order_number'] ?? '',
                    'date_shipped' => $row['date_shipped'] ?? '',
                    'items'        => [],
                ];
            }

            // Deduplicate by alias without pack extension — different
            // pack sizes (Y1011-50, Y1011-84) map to the same SDS.
            $strippedName = str_contains($itemName, '-') ? substr($itemName, 0, strpos($itemName, '-')) : $itemName;
            $alreadyInOrder = false;
            foreach ($orders[$key]['items'] as $existing) {
                $existingStripped = str_contains($existing['item_identifier'], '-')
                    ? substr($existing['item_identifier'], 0, strpos($existing['item_identifier'], '-'))
                    : $existing['item_identifier'];
                if ($existingStripped === $strippedName) {
                    $alreadyInOrder = true;
                    break;
                }
            }

            if (!$alreadyInOrder) {
                $orders[$key]['items'][] = [
                    'item_identifier' => $itemName,
                    'fg'              => $fg,
                    'sds_version'     => $sdsVersion,
                ];
            }
        }

        foreach ($orders as $order) {
            try {
                $this->sendOrderEmail($order['customer'], $order['items'], $order['date_shipped']);
                $results['emails_sent']++;
            } catch (\Throwable $e) {
                $results['errors'][] = "Order {$order['order_number']}: " . $e->getMessage();
            }
        }

        if (!empty($skipReasons)) {
            $results['skip_reasons'] = $skipReasons;
        }

        return $results;
    }

    /** Queue reason when getLatestPublishedSds() returned null (audit #53 / Q9). */
    private function sdsUnavailableReason(): string
    {
        return $this->aliasPublishError !== null
            ? 'Alias SDS could not be published — ' . $this->aliasPublishError
            : 'SDS not available — missing raw material data or CAS determination';
    }

    private function setLastRunTimestamp(): void
    {
        $now = date('Y-m-d H:i:s');
        $existing = $this->db->fetch("SELECT `key` FROM settings WHERE `key` = 'auto_send.last_run_at'");
        if ($existing) {
            $this->db->update('settings', ['value' => $now], "`key` = ?", ['auto_send.last_run_at']);
        } else {
            $this->db->insert('settings', ['key' => 'auto_send.last_run_at', 'value' => $now]);
        }
    }
}
