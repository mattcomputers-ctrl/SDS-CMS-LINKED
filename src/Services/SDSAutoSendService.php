<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\App;
use SDS\Core\Database;
use SDS\Models\Customer;
use SDS\Models\FinishedGood;
use SDS\Models\Formula;

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
 * The legacy autoPublishReady() method is retained on the class but no
 * longer called from processNewShipments(). The Bulk SDS Publish flow
 * (cron/bulk-publish.php) supersedes it with stricter eligibility rules
 * that require every RM to be user-reviewed.
 */
class SDSAutoSendService
{
    private Database $db;

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
     * @param  string|null $sessionStart When non-null, only shipments
     *                                   imported at or after this
     *                                   timestamp are considered — so a
     *                                   cron pass only emails customers
     *                                   for shipments that were synced
     *                                   in the same run. When null,
     *                                   falls back to the persisted
     *                                   auto_send.last_run_at marker
     *                                   (legacy behaviour).
     */
    public function processNewShipments(?string $sessionStart = null): array
    {
        $results = [
            'emails_sent' => 0,
            'queued'      => 0,
            'skipped'     => 0,
            'errors'      => [],
        ];

        // Phase A: Process new shipments for email sending
        $since = $sessionStart ?? $this->getLastRunTimestamp();
        $this->processShipmentsSince($since, $results);
        $this->setLastRunTimestamp();

        // Phase B: Notify regulatory staff if items were queued
        if ($results['queued'] > 0) {
            $this->notifyRegulatoryStaff($results['queued']);
        }

        return $results;
    }

    /* ------------------------------------------------------------------
     *  Phase A: Auto-Publish
     * ----------------------------------------------------------------*/

    /**
     * Auto-publish SDS for all finished goods that:
     * - Have a formula but no published SDS (or SDS is outdated)
     * - All upstream raw materials have constituents
     * - No pending CAS determinations block publishing
     */
    private function autoPublishReady(): int
    {
        $count = 0;

        // 1. FGs with formulas where:
        //    - No published SDS exists, OR
        //    - Formula was updated after last publish, OR
        //    - Any upstream raw material was updated after last publish, OR
        //    - Any CAS determination was updated after last publish
        $candidates = $this->db->fetchAll(
            "SELECT fg.id, fg.product_code,
                    f.created_at AS formula_date,
                    (SELECT MAX(sv.published_at) FROM sds_versions sv
                     WHERE sv.finished_good_id = fg.id AND sv.status = 'published' AND sv.is_deleted = 0
                    ) AS last_published_at,
                    (SELECT MAX(rm.updated_at) FROM formula_lines fl2
                     JOIN raw_materials rm ON rm.id = fl2.raw_material_id
                     WHERE fl2.formula_id = f.id
                    ) AS rm_updated_at,
                    (SELECT MAX(rmc.updated_at) FROM formula_lines fl3
                     JOIN raw_material_constituents rmc ON rmc.raw_material_id = fl3.raw_material_id
                     WHERE fl3.formula_id = f.id
                    ) AS constituents_updated_at,
                    (SELECT MAX(cpd.updated_at) FROM competent_person_determinations cpd
                     WHERE cpd.is_active = 1
                     AND cpd.cas_number IN (
                         SELECT rmc2.cas_number FROM formula_lines fl4
                         JOIN raw_material_constituents rmc2 ON rmc2.raw_material_id = fl4.raw_material_id
                         WHERE fl4.formula_id = f.id
                     )
                    ) AS cpd_updated_at
             FROM finished_goods fg
             JOIN formulas f ON f.finished_good_id = fg.id AND f.is_current = 1
             WHERE fg.is_active = 1
             HAVING last_published_at IS NULL
                 OR last_published_at < formula_date
                 OR last_published_at < rm_updated_at
                 OR last_published_at < constituents_updated_at
                 OR last_published_at < cpd_updated_at"
        );

        foreach ($candidates as $candidate) {
            if ($this->canAutoPublish((int) $candidate['id'])) {
                $reason = 'Auto-published by CMS sync';
                if ($candidate['last_published_at'] !== null) {
                    if ($candidate['formula_date'] > $candidate['last_published_at']) {
                        $reason = 'Auto-republished: formula updated';
                    } elseif (($candidate['constituents_updated_at'] ?? '') > $candidate['last_published_at']) {
                        $reason = 'Auto-republished: raw material constituents updated';
                    } elseif (($candidate['rm_updated_at'] ?? '') > $candidate['last_published_at']) {
                        $reason = 'Auto-republished: raw material data updated';
                    } elseif (($candidate['cpd_updated_at'] ?? '') > $candidate['last_published_at']) {
                        $reason = 'Auto-republished: CAS determination updated';
                    }
                }
                try {
                    $this->publishSds((int) $candidate['id'], $reason);
                    $count++;
                } catch (\Throwable $e) {
                    // Silently skip — will be caught on next run or manual publish
                }
            }
        }

        // 2. New aliases for FGs that already have a published SDS but the
        //    alias doesn't have its own published version yet
        $newAliases = $this->db->fetchAll(
            "SELECT a.id AS alias_id, a.customer_code, a.description, a.internal_code_base,
                    fg.id AS fg_id, fg.product_code
             FROM aliases a
             JOIN finished_goods fg ON fg.product_code = a.internal_code_base
             WHERE EXISTS (
                 SELECT 1 FROM sds_versions sv
                 WHERE sv.finished_good_id = fg.id AND sv.alias_id IS NULL
                   AND sv.status = 'published' AND sv.is_deleted = 0
             )
             AND NOT EXISTS (
                 SELECT 1 FROM sds_versions sv2
                 WHERE sv2.alias_id = a.id AND sv2.status = 'published' AND sv2.is_deleted = 0
             )"
        );

        foreach ($newAliases as $aliasRow) {
            try {
                // Get the base FG's latest published SDS data per language
                $languages = App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);
                $pdfService = new PDFService();
                $pdfDir = App::basePath() . '/public/generated-pdfs';

                $aliasLastVer = $this->db->fetch(
                    "SELECT MAX(version) AS max_ver FROM sds_versions WHERE alias_id = ?",
                    [(int) $aliasRow['alias_id']]
                );
                $aliasNextVer = ((int) ($aliasLastVer['max_ver'] ?? 0)) + 1;
                $now = date('Y-m-d H:i:s');

                $published = false;
                foreach ($languages as $lang) {
                    $baseSds = $this->db->fetch(
                        "SELECT snapshot_json FROM sds_versions
                         WHERE finished_good_id = ? AND alias_id IS NULL AND language = ?
                           AND status = 'published' AND is_deleted = 0
                         ORDER BY version DESC LIMIT 1",
                        [(int) $aliasRow['fg_id'], $lang]
                    );

                    if (!$baseSds || empty($baseSds['snapshot_json'])) {
                        continue;
                    }

                    $sdsData = json_decode($baseSds['snapshot_json'], true);
                    if (!$sdsData) {
                        continue;
                    }

                    $aliasData = SDSGenerator::createAliasVariant(
                        $sdsData,
                        $aliasRow['customer_code'],
                        $aliasRow['description']
                    );

                    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $aliasRow['customer_code'])
                        . '_SDS_' . $lang . '_' . date('Ymd_His') . '.pdf';
                    $pdfPath = $pdfDir . '/' . $filename;
                    $pdfService->generateToFile($aliasData, $pdfPath);

                    $this->db->insert('sds_versions', [
                        'finished_good_id' => (int) $aliasRow['fg_id'],
                        'alias_id'         => (int) $aliasRow['alias_id'],
                        'language'         => $lang,
                        'version'          => $aliasNextVer,
                        'status'           => 'published',
                        'effective_date'   => date('Y-m-d'),
                        'published_at'     => $now,
                        'snapshot_json'    => json_encode($aliasData, JSON_UNESCAPED_UNICODE),
                        'pdf_path'         => 'public/generated-pdfs/' . $filename,
                        'change_summary'   => 'Auto-published for new alias',
                    ]);

                    $published = true;
                }

                if ($published) {
                    $count++;
                }
            } catch (\Throwable $e) {
                // Skip — will retry next run
            }
        }

        return $count;
    }

    /**
     * Check if a finished good's SDS can be auto-published.
     * Returns false if any upstream raw materials lack constituents
     * or if CAS numbers need determinations.
     */
    private function canAutoPublish(int $fgId): bool
    {
        $formula = Formula::findCurrentByFinishedGood($fgId);
        if (!$formula || empty($formula['lines'])) {
            return false;
        }

        // Check all raw materials in the formula have constituents
        $rmIds = array_filter(array_column($formula['lines'], 'raw_material_id'));
        if (!empty($rmIds)) {
            $placeholders = implode(',', array_fill(0, count($rmIds), '?'));
            $incomplete = $this->db->fetch(
                "SELECT COUNT(*) AS cnt FROM raw_materials rm
                 LEFT JOIN raw_material_constituents rmc ON rmc.raw_material_id = rm.id
                 WHERE rm.id IN ({$placeholders}) AND rmc.id IS NULL",
                array_values($rmIds)
            );
            if ((int) ($incomplete['cnt'] ?? 0) > 0) {
                return false;
            }
        }

        // Check for pending CAS determinations that block publishing
        // Use the same logic as SDSController::checkMissingHazardData
        try {
            $generator = new SDSGenerator();
            $baseData = $generator->computeBase($fgId);
            $sdsData = $generator->generateFromBase($baseData, 'en');

            // Check for CAS numbers with no hazard data and no determination
            foreach ($sdsData['hazard_result']['trace'] ?? [] as $step) {
                if (($step['step'] ?? '') === 'no_data') {
                    $cas = $step['data']['cas'] ?? null;
                    $conc = (float) ($step['data']['concentration_pct'] ?? 0);
                    $threshold = (float) App::config('sds.missing_threshold_pct', 1.0);

                    if ($cas !== null && $conc >= $threshold) {
                        $cpd = $this->db->fetch(
                            "SELECT id FROM competent_person_determinations WHERE cas_number = ? AND is_active = 1 LIMIT 1",
                            [$cas]
                        );
                        if (!$cpd) {
                            return false; // Missing data, no determination
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * Publish an SDS for a finished good (all configured languages + aliases).
     */
    private function publishSds(int $fgId, string $changeSummary): void
    {
        $languages = App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);
        $generator = new SDSGenerator();
        $baseData = $generator->computeBase($fgId);

        $langData = [];
        foreach ($languages as $lang) {
            $langData[$lang] = $generator->generateFromBase($baseData, $lang);
        }

        // Generate PDFs
        $pdfService = new PDFService();
        $fg = FinishedGood::findById($fgId);
        $basePath = App::basePath();
        $pdfDir = $basePath . '/public/generated-pdfs';

        $lastVersion = $this->db->fetch(
            "SELECT MAX(version) AS max_ver FROM sds_versions WHERE finished_good_id = ?",
            [$fgId]
        );
        $nextVersion = ((int) ($lastVersion['max_ver'] ?? 0)) + 1;
        $now = date('Y-m-d H:i:s');

        foreach ($langData as $lang => $sdsData) {
            $filename = ($fg['product_code'] ?? 'UNKNOWN') . '_SDS_' . $lang . '_' . date('Ymd_His') . '.pdf';
            $pdfPath = $pdfDir . '/' . $filename;

            $pdfService->generateToFile($sdsData, $pdfPath);
            $relativePath = 'public/generated-pdfs/' . $filename;

            $this->db->insert('sds_versions', [
                'finished_good_id' => $fgId,
                'language'         => $lang,
                'version'          => $nextVersion,
                'status'           => 'published',
                'effective_date'   => date('Y-m-d'),
                'published_by'     => null,
                'published_at'     => $now,
                'snapshot_json'    => json_encode($sdsData, JSON_UNESCAPED_UNICODE),
                'pdf_path'         => $relativePath,
                'change_summary'   => $changeSummary,
                'created_by'       => null,
            ]);
        }

        // Also publish for aliases
        $aliases = $this->db->fetchAll(
            "SELECT a.id, a.customer_code, a.description
             FROM aliases a
             WHERE a.internal_code_base = ?",
            [$fg['product_code'] ?? '']
        );

        foreach ($aliases as $alias) {
            $aliasLangData = [];
            foreach ($langData as $lang => $sdsData) {
                $aliasLangData[$lang] = SDSGenerator::createAliasVariant(
                    $sdsData,
                    $alias['customer_code'],
                    $alias['description']
                );
            }

            $aliasLastVer = $this->db->fetch(
                "SELECT MAX(version) AS max_ver FROM sds_versions WHERE alias_id = ?",
                [(int) $alias['id']]
            );
            $aliasNextVer = ((int) ($aliasLastVer['max_ver'] ?? 0)) + 1;

            foreach ($aliasLangData as $lang => $aliasData) {
                $aliasFilename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $alias['customer_code'])
                    . '_SDS_' . $lang . '_' . date('Ymd_His') . '.pdf';
                $aliasPdfPath = $pdfDir . '/' . $aliasFilename;
                $pdfService->generateToFile($aliasData, $aliasPdfPath);
                $aliasRelPath = 'public/generated-pdfs/' . $aliasFilename;

                $this->db->insert('sds_versions', [
                    'finished_good_id' => $fgId,
                    'alias_id'         => (int) $alias['id'],
                    'language'          => $lang,
                    'version'           => $aliasNextVer,
                    'status'            => 'published',
                    'effective_date'    => date('Y-m-d'),
                    'published_by'      => null,
                    'published_at'      => $now,
                    'snapshot_json'     => json_encode($aliasData, JSON_UNESCAPED_UNICODE),
                    'pdf_path'          => $aliasRelPath,
                    'change_summary'    => $changeSummary,
                    'created_by'        => null,
                ]);
            }
        }
    }

    /* ------------------------------------------------------------------
     *  Phase B: Process Shipments
     * ----------------------------------------------------------------*/

    private function processShipmentsSince(?string $since, array &$results): void
    {
        $where = "WHERE 1=1";
        $params = [];

        if ($since !== null) {
            // >= so shipments imported exactly at the session-start
            // second are included. CMSImportService stamps imported_at
            // at DB INSERT time, which is always after the PHP-side
            // session-start timestamp captured at script boot.
            $where .= " AND sd.imported_at >= ?";
            $params[] = $since;
        }

        $shipments = $this->db->fetchAll(
            "SELECT sd.* FROM shipment_detail sd {$where} ORDER BY sd.date_shipped",
            $params
        );

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

            $itemName = (!empty($row['item_name']) && $row['item_name'] !== $row['item_code'])
                ? $row['item_name']
                : $row['item_code'];

            // Resolve to finished good
            $fgProductCode = $this->resolveToProductCode($itemName);
            if ($fgProductCode === null) {
                $results['skipped']++;
                continue;
            }

            if (!isset($fgCache[$fgProductCode])) {
                $fgCache[$fgProductCode] = FinishedGood::findByProductCode($fgProductCode);
            }
            $fg = $fgCache[$fgProductCode];
            if ($fg === null) {
                $results['skipped']++;
                continue;
            }

            // Check if we need to send based on mode
            if (!$this->shouldSend($customer, (int) $fg['id'], $itemName)) {
                $results['skipped']++;
                continue;
            }

            // Look up the latest published SDS. We no longer auto-publish
            // here on-demand — the Bulk SDS Publish cron step (which runs
            // before us) already published everything eligible under the
            // strict review rules. If there's no SDS now, the item isn't
            // eligible and will get queued for regulatory review below.
            $sdsVersion = $this->getLatestPublishedSds((int) $fg['id'], $itemName);

            if ($sdsVersion === null) {
                $this->queueForReview($customer, $row, 'SDS not available — missing raw material data or CAS determination');
                $results['queued']++;
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

            // Deduplicate: don't add the same item identifier twice to an order
            $alreadyInOrder = false;
            foreach ($orders[$orderKey]['items'] as $existing) {
                if ($existing['item_identifier'] === $itemName) {
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
            }
        }
    }

    /**
     * Determine if an SDS should be sent based on the customer's send mode.
     */
    private function shouldSend(array $customer, int $fgId, string $itemIdentifier): bool
    {
        $mode = $customer['sds_send_mode'];

        if ($mode === 'every_order') {
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

        // Check if SDS has been updated since last send
        $latestVersion = $this->db->fetch(
            "SELECT MAX(sv.id) AS latest_id FROM sds_versions sv
             WHERE sv.finished_good_id = ? AND sv.status = 'published' AND sv.is_deleted = 0
               AND sv.language = 'en'",
            [$fgId]
        );

        if ($latestVersion && (int) ($latestVersion['latest_id'] ?? 0) > (int) $lastSend['sds_version_id']) {
            return true; // SDS was updated since last send
        }

        // OSHA + 6mo: also send if last send was > 6 months ago
        if ($mode === 'osha_6mo') {
            $sixMonthsAgo = date('Y-m-d H:i:s', strtotime('-6 months'));
            if ($lastSend['sent_at'] < $sixMonthsAgo) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the latest published SDS version for a specific item identifier.
     * If the item is an alias, looks for alias-specific SDS first.
     */
    private function getLatestPublishedSds(int $fgId, string $itemIdentifier): ?array
    {
        // Check if this identifier is an alias
        $alias = $this->db->fetch(
            "SELECT id FROM aliases WHERE customer_code = ? LIMIT 1",
            [$itemIdentifier]
        );

        if ($alias) {
            // Look for alias-specific published SDS
            $version = $this->db->fetch(
                "SELECT * FROM sds_versions
                 WHERE alias_id = ? AND status = 'published' AND is_deleted = 0 AND language = 'en'
                 ORDER BY version DESC LIMIT 1",
                [(int) $alias['id']]
            );
            if ($version) {
                return $version;
            }
        }

        // Fall back to the FG's published SDS
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

            foreach ($languages as $lang) {
                $langVersion = null;
                if ($alias) {
                    $langVersion = $this->db->fetch(
                        "SELECT * FROM sds_versions
                         WHERE alias_id = ? AND language = ? AND status = 'published' AND is_deleted = 0
                         ORDER BY version DESC LIMIT 1",
                        [(int) $alias['id'], $lang]
                    );
                }
                if (!$langVersion) {
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
                $this->queueForReview($customer, $row, 'SDS not available — missing raw material data or CAS determination');
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

            $alreadyInOrder = false;
            foreach ($orders[$key]['items'] as $existing) {
                if ($existing['item_identifier'] === $itemName) {
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

    private function getLastRunTimestamp(): ?string
    {
        $row = $this->db->fetch("SELECT `value` FROM settings WHERE `key` = 'auto_send.last_run_at'");
        return $row['value'] ?? null;
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
