-- ============================================================
-- 061: SDS fixed-text batch (owner approval 2026-10-12) — track T3
-- ============================================================
-- Re-runnable: every DDL step is guarded by an INFORMATION_SCHEMA check
-- (045/046 pattern); settings seeds are INSERT IGNORE. Later T3 units append
-- their blocks ABOVE the footer.
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- A11 (T3a) Product-family "SDS profile" on product_families.
-- All optional. A NULL / 'auto' profile prints what today's sheets print, so
-- this block is DDL + a settings seed only: raw_materials.updated_at is not
-- touched and no SDS becomes stale here. Editing a profile later bumps the
-- family's raw materials and flags its products
-- (AdminController::updateProductFamily -> FamilyResolver::flagFamilyTextChange).
--   storage_temp_min_c / storage_temp_max_c : both or neither, min < max, -50..100 C
--   water_based : auto (water CAS 7732-18-5 >= sds.water_based_threshold_pct of the
--                 formula, through intermediates) | yes | no   (Services\WaterBasedRule)
--   default_physical_state : Liquid | Paste | Gel | Solid | Powder | NULL
--                 (product field > family default > largest raw > 'Liquid')
--   ph_min / ph_max : both or neither, 0..14, min <= max (Section 9 "(typical)")
-- ------------------------------------------------------------
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_families' AND COLUMN_NAME = 'storage_temp_min_c');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `product_families` ADD COLUMN `storage_temp_min_c` DECIMAL(5,1) NULL DEFAULT NULL COMMENT ''A11 SDS profile: storage temperature minimum (C); set together with the maximum'' AFTER `restrictions_json`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_families' AND COLUMN_NAME = 'storage_temp_max_c');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `product_families` ADD COLUMN `storage_temp_max_c` DECIMAL(5,1) NULL DEFAULT NULL COMMENT ''A11 SDS profile: storage temperature maximum (C); Section 7 range, Section 10 temperatures above'' AFTER `storage_temp_min_c`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_families' AND COLUMN_NAME = 'water_based');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `product_families` ADD COLUMN `water_based` ENUM(''auto'',''yes'',''no'') NOT NULL DEFAULT ''auto'' COMMENT ''A11 SDS profile: water-based override; auto = water CAS 7732-18-5 >= setting sds.water_based_threshold_pct (WaterBasedRule)'' AFTER `storage_temp_max_c`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_families' AND COLUMN_NAME = 'default_physical_state');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `product_families` ADD COLUMN `default_physical_state` VARCHAR(20) NULL DEFAULT NULL COMMENT ''A11 SDS profile: Liquid|Paste|Gel|Solid|Powder; used when the product has no physical_state (beats the largest raw)'' AFTER `water_based`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_families' AND COLUMN_NAME = 'ph_min');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `product_families` ADD COLUMN `ph_min` DECIMAL(4,1) NULL DEFAULT NULL COMMENT ''A11 SDS profile: typical pH minimum (0-14); set together with ph_max'' AFTER `default_physical_state`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_families' AND COLUMN_NAME = 'ph_max');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `product_families` ADD COLUMN `ph_max` DECIMAL(4,1) NULL DEFAULT NULL COMMENT ''A11 SDS profile: typical pH maximum (0-14); Section 9 prints min - max (typical)'' AFTER `ph_min`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- A11 shared water-based rule threshold (% of the formula). INSERT IGNORE keeps an admin's value.
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES ('sds.water_based_threshold_pct', '20');

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('061_sds_text_batch_t3');
