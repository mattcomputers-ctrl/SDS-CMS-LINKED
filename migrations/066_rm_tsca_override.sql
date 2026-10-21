-- ============================================================
-- Migration 066: per-raw-material TSCA override (2026-10-21)
--
-- Section 15 prints "TSCA inventory status has not been verified for all
-- components" when a component cannot be checked by CAS: a whole
-- trade-secret raw material (hazardous_no_cas), a trade-secret constituent
-- without a CAS, a constituent without a CAS, or a raw material with no
-- constituents. A CAS override (cas_master.tsca_status, migration 055)
-- cannot reach those. This override can: 'listed' / 'exempt' means the
-- supplier certifies every component of the raw material is on the TSCA
-- inventory (or exempt), so all of its components count as covered
-- (TSCAService). A CAS-level 'not_listed' still wins. Set on CAS
-- Determinations > TSCA Review; a note is required.
--
-- DDL only: ADD COLUMN does not fire ON UPDATE CURRENT_TIMESTAMP, so no
-- raw material is bumped here. Saving an override bumps that raw material.
-- Re-runnable: INFORMATION_SCHEMA + PREPARE guard (045/046 pattern).
-- ============================================================

SET NAMES utf8mb4;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_materials' AND COLUMN_NAME = 'tsca_status');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `raw_materials` ADD COLUMN `tsca_status` ENUM(''auto'',''listed'',''exempt'') NOT NULL DEFAULT ''auto'' COMMENT ''Per-RM TSCA override: listed/exempt = supplier certifies every component; auto = check each component by CAS'' AFTER `sds_not_required`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_materials' AND COLUMN_NAME = 'tsca_note');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `raw_materials` ADD COLUMN `tsca_note` VARCHAR(500) NULL COMMENT ''Basis for the raw-material TSCA override (e.g. supplier SDS Section 15 certification)'' AFTER `tsca_status`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_materials' AND COLUMN_NAME = 'tsca_updated_by');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `raw_materials` ADD COLUMN `tsca_updated_by` INT UNSIGNED NULL AFTER `tsca_note`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_materials' AND COLUMN_NAME = 'tsca_updated_at');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `raw_materials` ADD COLUMN `tsca_updated_at` DATETIME NULL AFTER `tsca_updated_by`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('066_rm_tsca_override');
