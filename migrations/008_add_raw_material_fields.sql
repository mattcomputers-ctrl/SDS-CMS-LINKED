-- Migration 008: Add raw material fields for VOC <1%, flash point "greater than",
-- solubility, non-hazardous CAS, and trade secret descriptions.
--
-- Safe to run multiple times (uses column existence checks).

-- ============================================================
-- raw_materials: voc_less_than_one
-- ============================================================
SET @colExists = (SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'raw_materials'
      AND COLUMN_NAME  = 'voc_less_than_one');

SET @sql = IF(@colExists = 0,
    'ALTER TABLE raw_materials ADD COLUMN voc_less_than_one TINYINT(1) NOT NULL DEFAULT 0 AFTER voc_wt',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- raw_materials: flash_point_greater_than
-- ============================================================
SET @colExists = (SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'raw_materials'
      AND COLUMN_NAME  = 'flash_point_greater_than');

SET @sql = IF(@colExists = 0,
    'ALTER TABLE raw_materials ADD COLUMN flash_point_greater_than TINYINT(1) NOT NULL DEFAULT 0 AFTER flash_point_c',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- raw_materials: solubility
-- ============================================================
SET @colExists = (SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'raw_materials'
      AND COLUMN_NAME  = 'solubility');

SET @sql = IF(@colExists = 0,
    'ALTER TABLE raw_materials ADD COLUMN solubility VARCHAR(50) NULL AFTER physical_state',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- raw_material_constituents: is_non_hazardous
-- ============================================================
SET @colExists = (SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'raw_material_constituents'
      AND COLUMN_NAME  = 'is_non_hazardous');

SET @sql = IF(@colExists = 0,
    'ALTER TABLE raw_material_constituents ADD COLUMN is_non_hazardous TINYINT(1) NOT NULL DEFAULT 0 AFTER is_trade_secret',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- raw_material_constituents: trade_secret_description
-- ============================================================
SET @colExists = (SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'raw_material_constituents'
      AND COLUMN_NAME  = 'trade_secret_description');

SET @sql = IF(@colExists = 0,
    'ALTER TABLE raw_material_constituents ADD COLUMN trade_secret_description VARCHAR(200) NULL AFTER is_non_hazardous',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- Record this migration
-- ============================================================
INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('008_add_raw_material_fields');
