-- 062: SDS fixed-text batch (owner decisions 2026-10-12), track T4 (CAS and
-- raw-material flags). Shared by every T4 unit: later units add their blocks
-- ABOVE the sibling marker at the end and keep exactly one version row.
--
-- Re-runnable: every DDL step is guarded by an INFORMATION_SCHEMA check
-- (045/046 pattern).

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- T4a / A12(b) (owner decisions 2026-10-12): STOT target organs + route on
-- cas_master. stot_se_* complete H370/H371, stot_re_* complete H372/H373
-- (Section 2 statement, Sections 4/11 symptoms, Section 11 STOT RE line) via
-- SDS\Services\StotTargetOrgans. Organs: comma-separated keys of
-- StotTargetOrgans::ORGANS; NULL/'' = not specified -> generic GHS statement,
-- exactly as before. Route: inhalation | oral | dermal | all | NULL ('all' and
-- NULL print no route). Edited on /determinations (STOT Target Organs tab);
-- each edit bumps the raw materials carrying the CAS
-- (RegulatoryListBumper::bumpByCas) and queues SDS updates.
-- No data rows: all NULL = today's sheets, so nothing is bumped here.
-- Re-runnable: one INFORMATION_SCHEMA guard per column (045/046 pattern).
-- ------------------------------------------------------------
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cas_master' AND COLUMN_NAME = 'stot_se_organs');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `cas_master` ADD COLUMN `stot_se_organs` VARCHAR(255) NULL DEFAULT NULL COMMENT ''A12(b): STOT single exposure (H370/H371) target organ keys, comma-separated; NULL = not specified''',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cas_master' AND COLUMN_NAME = 'stot_se_route');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `cas_master` ADD COLUMN `stot_se_route` VARCHAR(12) NULL DEFAULT NULL COMMENT ''A12(b): STOT SE route inhalation|oral|dermal|all; NULL = not stated''',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cas_master' AND COLUMN_NAME = 'stot_re_organs');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `cas_master` ADD COLUMN `stot_re_organs` VARCHAR(255) NULL DEFAULT NULL COMMENT ''A12(b): STOT repeated exposure (H372/H373) target organ keys, comma-separated; NULL = not specified''',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cas_master' AND COLUMN_NAME = 'stot_re_route');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `cas_master` ADD COLUMN `stot_re_route` VARCHAR(12) NULL DEFAULT NULL COMMENT ''A12(b): STOT RE route inhalation|oral|dermal|all; NULL = not stated''',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- T4b / A12(c) (owner decisions 2026-10-12): combustible-dust determination
-- on raw materials, from the vendor SDS Section 2.
--   not_assessed (default) = no determination yet;
--   yes = the vendor classifies the material as an OSHA combustible dust;
--   no  = assessed, not a combustible dust.
-- A Powder/Solid product containing a 'yes' raw is classified Combustible
-- Dust (SDS Sections 2, 5, 7; Section 15 follows) by
-- SDSGenerator::applyCombustibleDust(). not_assessed powder/solid raws in a
-- Powder/Solid product only raise a preview / readiness warning.
-- DDL only: every existing row becomes not_assessed, which prints nothing,
-- so raw_materials.updated_at is not touched and no SDS becomes stale.
-- Later edits on the raw-material form: a change to or from 'yes' bumps
-- updated_at (ON UPDATE, UTC session); not_assessed <-> no is written with
-- updated_at = updated_at (RawMaterial::setCombustibleDustMetadataOnly).
-- ------------------------------------------------------------
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_materials' AND COLUMN_NAME = 'combustible_dust');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `raw_materials` ADD COLUMN `combustible_dust` ENUM(''not_assessed'',''yes'',''no'') NOT NULL DEFAULT ''not_assessed'' COMMENT ''A12(c): vendor SDS Section 2 combustible-dust determination'' AFTER `physical_state`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Informational for the deployer: Powder/Solid raw materials still to assess.
SELECT COUNT(*) AS `powder_solid_raws_not_assessed`
  FROM `raw_materials`
 WHERE `combustible_dust` = 'not_assessed'
   AND LOWER(TRIM(COALESCE(`physical_state`, ''))) IN ('powder', 'solid');

-- (sibling blocks go above this line; keep exactly one version row)
INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('062_sds_fixed_text_t4');
