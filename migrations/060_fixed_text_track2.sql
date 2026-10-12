-- 060: fixed-text review batch (owner decisions 2026-10-12) — track T2
-- (CAS / raw-material / product-family data). Each T2 unit adds its own
-- block ABOVE the footer. Re-runnable: every DDL step is guarded by an
-- INFORMATION_SCHEMA check (045/046 pattern).
SET NAMES utf8mb4;

-- ============================================================
-- T2a (A12a): cas_master acrylate flag.
-- is_acrylate: 1 = reactive acrylate / methacrylate monomer or oligomer,
-- 0 = explicitly not (manual decision on /determinations), NULL = not
-- determined -> UVAcrylateRulePack falls back to the legacy CAS list (one
-- release) or the name rule, i.e. exactly today's detection. Read by
-- UVAcrylateRulePack::detectAcrylates() for the Section 4 acrylate names on
-- UV sheets. Seeded by CasAcrylateFlagSeeder (CAS Determinations > CAS
-- Descriptions > "Seed acrylate flags (preview)" or
-- scripts/seed-cas-acrylate-flags.php). acrylate_source: 'seed' | 'manual'
-- | NULL; the seeder skips 'manual' rows unless --force.
-- DDL only: no seed rows and no raw_materials bump. NULL keeps today's
-- detection, so nothing prints differently until the seeder runs (it bumps
-- only the raw materials whose detection it changes).
-- ============================================================
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cas_master' AND COLUMN_NAME = 'is_acrylate');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `cas_master` ADD COLUMN `is_acrylate` TINYINT(1) NULL DEFAULT NULL COMMENT ''A12a: 1 = reactive acrylate/methacrylate monomer or oligomer (UV Section 4 names), 0 = not (manual), NULL = not determined (legacy list / name rule fallback)'' AFTER `tc_metals_source`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cas_master' AND COLUMN_NAME = 'acrylate_source');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `cas_master` ADD COLUMN `acrylate_source` VARCHAR(10) NULL DEFAULT NULL COMMENT ''A12a: seed | manual | NULL (never set); the seed script skips manual rows unless --force'' AFTER `is_acrylate`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- (end of T2a block; later T2 units append above the footer)
-- ============================================================

-- (file footer, exactly once per file)
INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('060_fixed_text_track2');
