-- ============================================================
-- Migration 046 — Add 'finished_good' to sds_update_queue source_type
-- ============================================================
-- Allows the scan to record when a downstream FG needs updating
-- because an upstream finished good component changed.

SET NAMES utf8mb4;

SELECT COUNT(*) INTO @has_fg FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sds_update_queue'
    AND COLUMN_NAME = 'source_type'
    AND COLUMN_TYPE LIKE '%finished_good%';

SET @sql = IF(@has_fg > 0,
  'DO 0',
  "ALTER TABLE `sds_update_queue` MODIFY COLUMN `source_type` ENUM('raw_material','constituent','finished_good') NOT NULL DEFAULT 'raw_material'"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('046_add_finished_good_source_type');
