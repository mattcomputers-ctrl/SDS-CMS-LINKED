-- ============================================================
-- Migration 048: Track auto-send processing per shipment row
-- Replaces the fragile "imported_at >= session start" window in
-- SDS auto-send. That window compared a MySQL-stamped timestamp
-- against a PHP-generated one — when the two clocks disagreed,
-- newly imported shipments were silently never examined. Auto-send
-- now scans rows where sds_processed_at IS NULL and stamps them
-- once handled, so any missed run self-heals on the next sweep.
-- ============================================================

ALTER TABLE `shipment_detail`
    ADD COLUMN `sds_processed_at` DATETIME NULL DEFAULT NULL
        COMMENT 'When auto-send examined this row; NULL = pending'
        AFTER `imported_at`,
    ADD INDEX `idx_sd_processed` (`sds_processed_at`);

-- Backfill: mark historical rows as processed EXCEPT shipments for
-- active auto-send customers dated on/after their active-since date.
-- Those are left NULL so the next cron sweeps them — anything the old
-- window logic missed gets sent, while sds_send_log deduplication in
-- shouldSend() keeps already-sent items from emailing twice.
UPDATE `shipment_detail`
SET `sds_processed_at` = `imported_at`
WHERE NOT EXISTS (
    SELECT 1 FROM `customers` c
    WHERE c.`ship_to` = `shipment_detail`.`ship_to`
      AND c.`is_active` = 1
      AND c.`regulatory_email` IS NOT NULL AND c.`regulatory_email` != ''
      AND c.`sds_send_active_since` IS NOT NULL
      AND `shipment_detail`.`date_shipped` >= c.`sds_send_active_since`
);

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('048_add_shipment_sds_processed_flag');
