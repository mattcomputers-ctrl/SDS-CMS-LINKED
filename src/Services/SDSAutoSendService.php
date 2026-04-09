<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\App;
use SDS\Core\Database;
use SDS\Models\Customer;
use SDS\Models\FinishedGood;
use SDS\Models\Formula;

/**
 * SDSAutoSendService — auto-publishes SDS documents and sends them
 * to customer regulatory contacts based on shipment data.
 *
 * Called after each CMS sync. The flow is:
 *   1. Auto-publish SDS for products with ready data (new formulas, revised formulas, new aliases)
 *   2. Identify new shipments since last run
 *   3. For each shipment to a customer with a regulatory email:
 *      - Determine if an SDS needs to be sent (based on send mode)
 *      - If SDS is published → send email with PDF
 *      - If SDS can't be published (missing data) → queue for regulatory review
 */
class SDSAutoSendService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Main entry point — called after CMS sync.
     */
    public function processNewShipments(): array
    {
        $results = [
            'auto_published'     => 0,
            'emails_sent'        => 0,
            'queued'             => 0,
            'skipped'            => 0,
            'errors'             => [],
        ];

        // Phase A: Auto-publish all SDS that can be published
        $results['auto_published'] = $this->autoPublishReady();

        // Phase B: Process new shipments for email sending
        $lastRun = $this->getLastRunTimestamp();
        $this->processShipmentsSince($lastRun, $results);
        $this->setLastRunTimestamp();

        // Phase C: Notify regulatory staff if items were queued
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

        // Find FGs with formulas but no published SDS, or where the formula
        // was updated after the last published SDS
        $candidates = $this->db->fetchAll(
            "SELECT fg.id, fg.product_code, f.id AS formula_id, f.created_at AS formula_date,
                    (SELECT MAX(sv.published_at) FROM sds_versions sv
                     WHERE sv.finished_good_id = fg.id AND sv.status = 'published' AND sv.is_deleted = 0
                    ) AS last_published_at
             FROM finished_goods fg
             JOIN formulas f ON f.finished_good_id = fg.id AND f.is_current = 1
             WHERE fg.is_active = 1
             HAVING last_published_at IS NULL OR last_published_at < formula_date"
        );

        foreach ($candidates as $candidate) {
            if ($this->canAutoPublish((int) $candidate['id'])) {
                try {
                    $this->publishSds((int) $candidate['id'], 'Auto-published by CMS sync');
                    $count++;
                } catch (\Throwable $e) {
                    // Silently skip — will be caught on next run or manual publish
                }
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
            $where .= " AND sd.imported_at > ?";
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

        foreach ($shipments as $row) {
            $shipTo = $row['ship_to'] ?? '';
            $customer = $customerMap[$shipTo] ?? null;

            if ($customer === null) {
                $results['skipped']++;
                continue;
            }

            // Determine item identifier (alias or item code)
            $itemName = (!empty($row['item_name']) && $row['item_name'] !== $row['item_code'])
                ? $row['item_name']
                : $row['item_code'];

            $itemDesc = (!empty($row['item_name']) && $row['item_name'] !== $row['item_code'])
                ? ($row['item_name_description'] ?? '')
                : ($row['item_description'] ?? '');

            // Resolve to finished good
            $fgProductCode = $this->resolveToProductCode($itemName);
            if ($fgProductCode === null) {
                $results['skipped']++;
                continue;
            }

            $fg = FinishedGood::findByProductCode($fgProductCode);
            if ($fg === null) {
                $results['skipped']++;
                continue;
            }

            // Check if we need to send based on mode
            if (!$this->shouldSend($customer, (int) $fg['id'], $itemName)) {
                $results['skipped']++;
                continue;
            }

            // Find the latest published SDS for this item
            $sdsVersion = $this->getLatestPublishedSds((int) $fg['id'], $itemName);

            if ($sdsVersion === null) {
                // No published SDS — check if we can publish
                if ($this->canAutoPublish((int) $fg['id'])) {
                    try {
                        $this->publishSds((int) $fg['id'], 'Auto-published for shipment');
                        $results['auto_published']++;
                        $sdsVersion = $this->getLatestPublishedSds((int) $fg['id'], $itemName);
                    } catch (\Throwable $e) {
                        // Fall through to queue
                    }
                }
            }

            if ($sdsVersion === null) {
                // Can't publish — queue for regulatory review
                $this->queueForReview($customer, $row, 'SDS not available — missing raw material data or CAS determination');
                $results['queued']++;
                continue;
            }

            // Generate PDF and send email
            try {
                $this->sendSdsEmail($customer, $fg, $sdsVersion, $itemName, $itemDesc, $row['date_shipped']);
                $results['emails_sent']++;
            } catch (\Throwable $e) {
                $results['errors'][] = "Send to {$customer['ship_to']}: " . $e->getMessage();
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
            "SELECT ssl.sent_at, ssl.sds_version_id
             FROM sds_send_log ssl
             WHERE ssl.customer_id = ? AND ssl.item_identifier = ?
             ORDER BY ssl.sent_at DESC LIMIT 1",
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
    private function sendSdsEmail(
        array $customer,
        array $fg,
        array $sdsVersion,
        string $itemIdentifier,
        string $itemDescription,
        ?string $shipmentDate
    ): void {
        if (!MailService::isConfigured()) {
            throw new \RuntimeException('Mail not configured');
        }

        $basePath = App::basePath();
        $pdfPath = $basePath . '/' . ltrim($sdsVersion['pdf_path'] ?? '', '/');

        // If item is an alias and we have a non-alias SDS, generate alias PDF on the fly
        $tempPdf = null;
        $alias = $this->db->fetch(
            "SELECT * FROM aliases WHERE customer_code = ? LIMIT 1",
            [$itemIdentifier]
        );

        if ($alias && empty($sdsVersion['alias_id'])) {
            $snapshot = json_decode($sdsVersion['snapshot_json'] ?? '{}', true);
            if ($snapshot) {
                $snapshot = SDSGenerator::createAliasVariant(
                    $snapshot,
                    $alias['customer_code'],
                    $alias['description']
                );
                $tempPdf = tempnam(sys_get_temp_dir(), 'sds_send_') . '.pdf';
                $pdfService = new PDFService();
                $pdfService->generateToFile($snapshot, $tempPdf);
                $pdfPath = $tempPdf;
            }
        }

        $companyName = App::config('company.name', 'SDS System');
        $subject = "Safety Data Sheet: {$itemIdentifier}" . ($itemDescription ? " — {$itemDescription}" : '');

        $body = "<p>Please find attached the Safety Data Sheet (SDS) for:</p>"
            . "<p><strong>{$itemIdentifier}</strong>"
            . ($itemDescription ? " — " . htmlspecialchars($itemDescription) : '')
            . "</p>"
            . ($shipmentDate ? "<p>Shipment date: " . htmlspecialchars($shipmentDate) . "</p>" : '')
            . "<p>This SDS is provided in accordance with OSHA Hazard Communication Standard 29 CFR 1910.1200.</p>"
            . "<p>— {$companyName}</p>";

        $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $itemIdentifier);
        $attachName = $safeCode . '_SDS.pdf';

        MailService::send(
            $customer['regulatory_email'],
            $subject,
            $body,
            [['path' => $pdfPath, 'name' => $attachName]]
        );

        // Clean up temp PDF
        if ($tempPdf !== null) {
            @unlink($tempPdf);
        }

        // Log the send
        $this->db->insert('sds_send_log', [
            'customer_id'      => (int) $customer['id'],
            'finished_good_id' => (int) $fg['id'],
            'item_identifier'  => $itemIdentifier,
            'sds_version_id'   => (int) $sdsVersion['id'],
            'language'         => 'en',
            'shipment_date'    => $shipmentDate,
        ]);
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
        $stripped = str_contains($code, '-') ? substr($code, 0, strpos($code, '-')) : $code;

        $fg = $this->db->fetch("SELECT product_code FROM finished_goods WHERE product_code = ?", [$stripped]);
        if ($fg) {
            return $fg['product_code'];
        }

        $alias = $this->db->fetch("SELECT internal_code_base FROM aliases WHERE customer_code = ? LIMIT 1", [$stripped]);
        if ($alias) {
            return $alias['internal_code_base'];
        }

        return null;
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
