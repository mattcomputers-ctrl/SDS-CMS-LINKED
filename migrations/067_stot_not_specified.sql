-- ============================================================
-- Migration 067: STOT target organs "not specified" (2026-10-22)
--
-- Some vendor SDSs classify a substance H370-H373 without naming the target
-- organs. cas_master.stot_se_not_specified / stot_re_not_specified = 1 marks
-- that the vendor SDS was checked and names none: the substance prints the
-- generic statement (as with nothing entered) but no longer counts as
-- needing organs on CAS Determinations > STOT Target Organs, its tab badge
-- or the dashboard. Only meaningful while no organs are entered for that
-- exposure type (StotTargetOrgans::columnValues()). When several
-- substances drive one statement and one is "not specified", the generic
-- statement prints with no operator warning.
--
-- DDL only: nothing is bumped. Re-runnable: INFORMATION_SCHEMA + PREPARE
-- guard (045/046 pattern). Until it runs the STOT code reads and writes
-- the organs and routes as before (StotTargetOrgans::hasNotSpecifiedColumns()).
-- ============================================================

SET NAMES utf8mb4;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cas_master' AND COLUMN_NAME = 'stot_se_not_specified');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `cas_master` ADD COLUMN `stot_se_not_specified` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = vendor SDS names no single-exposure target organs (generic H370/H371 statement)'' AFTER `stot_se_route`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cas_master' AND COLUMN_NAME = 'stot_re_not_specified');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `cas_master` ADD COLUMN `stot_re_not_specified` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = vendor SDS names no repeated-exposure target organs (generic H372/H373 statement)'' AFTER `stot_re_route`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('067_stot_not_specified');
