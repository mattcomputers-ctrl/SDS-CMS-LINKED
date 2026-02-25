-- Migration 007: Add manual Prop 65, HAPs fields to raw_materials
--               and recommended_use / restrictions_on_use to finished_goods.
--
-- Safe to run multiple times (uses IF NOT EXISTS / column existence checks).

-- ============================================================
-- finished_goods: recommended use and restrictions
-- ============================================================

-- Check and add recommended_use column
SET @colExists = (SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'finished_goods'
      AND COLUMN_NAME  = 'recommended_use');

SET @sql = IF(@colExists = 0,
    'ALTER TABLE finished_goods ADD COLUMN recommended_use VARCHAR(500) DEFAULT NULL AFTER family',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add restrictions_on_use column
SET @colExists = (SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'finished_goods'
      AND COLUMN_NAME  = 'restrictions_on_use');

SET @sql = IF(@colExists = 0,
    'ALTER TABLE finished_goods ADD COLUMN restrictions_on_use VARCHAR(500) DEFAULT NULL AFTER recommended_use',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- raw_materials: manual Prop 65 marking
-- ============================================================

SET @colExists = (SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'raw_materials'
      AND COLUMN_NAME  = 'is_prop65');

SET @sql = IF(@colExists = 0,
    'ALTER TABLE raw_materials ADD COLUMN is_prop65 TINYINT(1) NOT NULL DEFAULT 0 AFTER notes',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @colExists = (SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'raw_materials'
      AND COLUMN_NAME  = 'prop65_chemical_name');

SET @sql = IF(@colExists = 0,
    'ALTER TABLE raw_materials ADD COLUMN prop65_chemical_name VARCHAR(255) DEFAULT NULL AFTER is_prop65',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @colExists = (SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'raw_materials'
      AND COLUMN_NAME  = 'prop65_toxicity_types');

SET @sql = IF(@colExists = 0,
    'ALTER TABLE raw_materials ADD COLUMN prop65_toxicity_types VARCHAR(255) DEFAULT NULL AFTER prop65_chemical_name',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- raw_materials: manual HAPs data (JSON)
-- ============================================================

SET @colExists = (SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'raw_materials'
      AND COLUMN_NAME  = 'haps_data');

SET @sql = IF(@colExists = 0,
    'ALTER TABLE raw_materials ADD COLUMN haps_data JSON DEFAULT NULL AFTER prop65_toxicity_types',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- Record this migration
-- ============================================================

INSERT IGNORE INTO schema_migrations (version, applied_at)
VALUES ('007_add_prop65_haps_recommended_use', NOW());
