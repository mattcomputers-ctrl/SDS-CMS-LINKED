-- Migration 016: Add physical_state and color to finished_goods,
--               add SNUR (Significant New Use Rule) tracking.
--
-- Safe to run multiple times (uses column existence checks).

-- ============================================================
-- finished_goods: physical_state for SDS Section 9
-- ============================================================

SET @colExists = (SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'finished_goods'
      AND COLUMN_NAME  = 'physical_state');

SET @sql = IF(@colExists = 0,
    'ALTER TABLE finished_goods ADD COLUMN physical_state VARCHAR(100) DEFAULT NULL AFTER restrictions_on_use',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- finished_goods: color for SDS Section 9
-- ============================================================

SET @colExists = (SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'finished_goods'
      AND COLUMN_NAME  = 'color');

SET @sql = IF(@colExists = 0,
    'ALTER TABLE finished_goods ADD COLUMN color VARCHAR(100) DEFAULT NULL AFTER physical_state',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- SNUR List — EPA Significant New Use Rules by CAS number
-- ============================================================

CREATE TABLE IF NOT EXISTS `snur_list` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `cas_number`    VARCHAR(20) NOT NULL,
    `chemical_name` VARCHAR(300) NOT NULL DEFAULT '',
    `rule_citation` VARCHAR(200) NULL COMMENT 'e.g. 40 CFR 721.10XXX',
    `description`   TEXT NULL COMMENT 'Brief description of the SNUR requirements',
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX `idx_snur_cas` (`cas_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- raw_materials: manual SNUR flag
-- ============================================================

SET @colExists = (SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'raw_materials'
      AND COLUMN_NAME  = 'is_snur');

SET @sql = IF(@colExists = 0,
    'ALTER TABLE raw_materials ADD COLUMN is_snur TINYINT(1) NOT NULL DEFAULT 0 AFTER haps_data',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @colExists = (SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'raw_materials'
      AND COLUMN_NAME  = 'snur_description');

SET @sql = IF(@colExists = 0,
    'ALTER TABLE raw_materials ADD COLUMN snur_description VARCHAR(500) DEFAULT NULL AFTER is_snur',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- Record this migration
-- ============================================================

INSERT IGNORE INTO schema_migrations (version, applied_at)
VALUES ('016_add_physical_state_color_snur', NOW());
