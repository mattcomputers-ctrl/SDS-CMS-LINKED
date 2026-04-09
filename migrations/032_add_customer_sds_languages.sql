-- ============================================================
-- Migration 032: Add SDS language preferences to customers
-- Stores which language(s) of SDS PDFs to email to each customer.
-- Default is 'en' (English only).
-- ============================================================

ALTER TABLE `customers` ADD COLUMN `sds_languages` VARCHAR(50) NOT NULL DEFAULT 'en'
    COMMENT 'Comma-separated language codes (e.g. en,es,fr,de)' AFTER `sds_send_mode`;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('032_add_customer_sds_languages');
