-- ============================================================
-- SDS System — Migration 021
-- Expand backup system: section-based backups, FTP scheduled
-- uploads, and comprehensive backup/restore coverage.
-- ============================================================

-- Widen backup_type to support section-based backups
ALTER TABLE `backups`
    MODIFY COLUMN `backup_type` VARCHAR(50) NOT NULL DEFAULT 'full'
        COMMENT 'full, product_data, settings, sds_history, regulatory';

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('021_expand_backup_system');
