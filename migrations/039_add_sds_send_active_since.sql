-- ============================================================
-- Migration 039: Add SDS Send Active Since date to customers
-- Tracks from which date the system should ensure SDSs have
-- been sent for this customer's shipments.
-- ============================================================

ALTER TABLE `customers` ADD COLUMN `sds_send_active_since` DATE NULL DEFAULT NULL
    COMMENT 'Auto-send SDSs for shipments on or after this date'
    AFTER `sds_send_mode`;

-- Existing active customers with a regulatory email get today's date
-- so their current behavior is preserved.
UPDATE `customers`
SET `sds_send_active_since` = CURRENT_DATE
WHERE `is_active` = 1
  AND `regulatory_email` IS NOT NULL
  AND `regulatory_email` != '';

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('039_add_sds_send_active_since');
