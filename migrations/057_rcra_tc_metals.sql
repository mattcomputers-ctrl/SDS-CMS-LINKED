-- 057: SDS content audit — track T1 (flammability / transport / RCRA)
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- Audit #12 (track T1, unit T1d): RCRA toxicity-characteristic metals present
-- as compounds. cas_master.tc_metals lists the 40 CFR 261.24 metals a
-- substance contains as comma-separated element symbols in the order
-- As,Ba,Cd,Cr,Pb,Hg,Se,Ag ('' or NULL = none). RCRAService gives such a
-- component every D code (D004-D011) of the matching element rows in
-- rcra_waste_codes, so lead chromate prints D007 + D008 with the TCLP limits.
-- Seeded by CasTcMetalSeeder (CAS Determinations > CAS Descriptions >
-- "Seed RCRA metal flags (preview)" or scripts/seed-cas-tc-metals.php):
-- molecular formula first, conservative name / Colour Index patterns second,
-- like the #19 element flags. tc_metals_source: 'seed' | 'manual' | NULL;
-- the seeder skips 'manual' rows unless --force.
-- No seed rows and no raw_materials bump here: NULL already means "none", so
-- nothing prints differently until the seeder runs (it bumps what it changes).
-- Re-runnable: INFORMATION_SCHEMA guards (045/046 pattern).
-- ------------------------------------------------------------
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cas_master' AND COLUMN_NAME = 'tc_metals');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `cas_master` ADD COLUMN `tc_metals` VARCHAR(40) NULL DEFAULT NULL COMMENT ''Audit #12: RCRA TC metals contained (As,Ba,Cd,Cr,Pb,Hg,Se,Ag comma-separated; empty/NULL = none) -> Section 13 D004-D011'' AFTER `element_flags_source`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cas_master' AND COLUMN_NAME = 'tc_metals_source');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `cas_master` ADD COLUMN `tc_metals_source` VARCHAR(10) NULL DEFAULT NULL COMMENT ''Audit #12: seed | manual | NULL (never set); seeder skips manual rows'' AFTER `tc_metals`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('057_rcra_tc_metals');
