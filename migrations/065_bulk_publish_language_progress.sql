-- ============================================================
-- Migration 065: bulk_publish_jobs.language_progress (2026-10-19)
--
-- Bulk publish runs language by language, English first (commit cfa9849).
-- The runner records each language pass here so the Bulk Publish Queue
-- page and the Bulk SDS Publish page can show "English done" while the
-- rest of the run carries on. JSON, written only by cron/bulk-publish.php
-- (BulkPublishQueue::updateLanguageProgress):
--   {"order": ["en","es","de","fr"],
--    "passes": {"en": {"items": 11720, "processed": 11720, "done_at": "2026-10-20 14:05:00"}, ...}}
-- done_at is UTC (audit #59), like every job timestamp. Older jobs keep NULL.
-- The runner checks for the column first, so it keeps working before this
-- migration has run.
--
-- Re-runnable: INFORMATION_SCHEMA + PREPARE guard (045/046 pattern).
-- ============================================================

SET NAMES utf8mb4;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bulk_publish_jobs' AND COLUMN_NAME = 'language_progress');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `bulk_publish_jobs` ADD COLUMN `language_progress` TEXT NULL COMMENT ''JSON: per language pass items, processed, done_at (UTC); written by cron/bulk-publish.php'' AFTER `failed_count`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('065_bulk_publish_language_progress');
