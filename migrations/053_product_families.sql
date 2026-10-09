-- ============================================================
-- Migration 053 — Product families (SDS content audit #3, track T1)
-- ============================================================
-- Why:
--   * product_families: one row per family (name, UV/LED flag, per-language
--     Section 1 Recommended Use / Restrictions defaults). Replaces the
--     newline-separated setting sds.product_families, which only fed the
--     finished-good form dropdown.
--   * product_family_rules: membership rules (code prefix / description
--     contains / exact code) applying to raw materials, products or both.
--     Aliases are matched too (FamilyResolver).
--   * finished_goods.family_id / family_source and raw_materials.family_id /
--     family_source: the resolved family and how it was resolved
--     (manual > rule > content). finished_goods.family (legacy name column)
--     is kept in sync by FamilyResolver for the existing readers.
--   * Seeds: every name in sds.product_families (one per line) and every
--     distinct finished_goods.family already in use; FGs whose legacy name
--     matches are linked as a manual choice (that is what the dropdown was).
--
-- Re-runnable: CREATE TABLE IF NOT EXISTS, INFORMATION_SCHEMA-guarded ALTERs
-- (045/046 pattern), INSERT IGNORE seeds, backfills guarded so a second run
-- is a no-op. The §5d UV/LED heuristic runs once per schema_migrations row
-- (054 pattern): a hand re-run must not re-flag a family an admin un-flagged.
-- Requires MariaDB >= 10.2.7 / MySQL >= 5.7.8 (as 051).
-- ============================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------
-- 1. Families
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_families` (
    `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`                 VARCHAR(100) NOT NULL,
    `is_uv`                TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'UV/LED-curable family: gates the UV acrylate rule pack and the UV raw-material logic (audit #19/#35)',
    `recommended_use_json` TEXT NULL
        COMMENT 'Section 1 Recommended Use default per language {"en":"...","es":"..."}; blank language = en text; blank en = translation file',
    `restrictions_json`    TEXT NULL
        COMMENT 'Section 1 Restrictions on Use default per language, same shape',
    `sort_order`           INT NOT NULL DEFAULT 0,
    `is_active`            TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '0 = hidden from pickers; its rules and content contributions are ignored by FamilyResolver; manual overrides pointing at it still print',
    `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX `uq_pf_name` (`name`),
    INDEX `idx_pf_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- 2. Membership rules
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_family_rules` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `family_id`  INT UNSIGNED NOT NULL,
    `rule_type`  ENUM('code_prefix','description_contains','exact_code') NOT NULL,
    `pattern`    VARCHAR(200) NOT NULL
        COMMENT 'Codes stored upper-cased; all comparisons case-insensitive. exact_code also matches the pack-stripped base code',
    `applies_to` ENUM('raw_material','product','both') NOT NULL DEFAULT 'both',
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX `uq_pfr_rule` (`family_id`, `rule_type`, `pattern`, `applies_to`),
    INDEX `idx_pfr_type` (`rule_type`),
    CONSTRAINT `fk_pfr_family`     FOREIGN KEY (`family_id`)  REFERENCES `product_families`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pfr_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)            ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- 3. finished_goods.family_id / family_source (guarded)
-- -----------------------------------------------------------
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finished_goods' AND COLUMN_NAME = 'family_id');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `finished_goods`
        ADD COLUMN `family_id` INT UNSIGNED NULL
            COMMENT ''Resolved product family (FamilyResolver); NULL = unresolved. finished_goods.family keeps the name for legacy readers''
            AFTER `family`,
        ADD COLUMN `family_source` ENUM(''manual'',''rule'',''content'') NULL
            COMMENT ''How family_id was resolved: manual override > direct rule match > inherited from formula content''
            AFTER `family_id`,
        ADD INDEX `idx_fg_family_id` (`family_id`),
        ADD CONSTRAINT `fk_fg_family` FOREIGN KEY (`family_id`) REFERENCES `product_families`(`id`) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------
-- 4. raw_materials.family_id / family_source (guarded)
-- -----------------------------------------------------------
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_materials' AND COLUMN_NAME = 'family_id');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `raw_materials`
        ADD COLUMN `family_id` INT UNSIGNED NULL
            COMMENT ''Resolved product family (FamilyResolver); NULL = unclassified. A reassignment is written with updated_at = UTC_TIMESTAMP() (bulk-publish staleness signal)''
            AFTER `notes`,
        ADD COLUMN `family_source` ENUM(''manual'',''rule'') NULL
            COMMENT ''manual override > direct rule match (raw materials have no content to inherit from)''
            AFTER `family_id`,
        ADD INDEX `idx_rm_family_id` (`family_id`),
        ADD CONSTRAINT `fk_rm_family` FOREIGN KEY (`family_id`) REFERENCES `product_families`(`id`) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------
-- 5a. Seed names from the legacy setting (one per line, CR/LF tolerated,
--     up to 60 lines). INSERT IGNORE + UNIQUE(name) dedupes and makes
--     re-runs no-ops. Line number becomes sort_order.
-- -----------------------------------------------------------
INSERT IGNORE INTO `product_families` (`name`, `sort_order`)
SELECT x.`name`, x.`n`
FROM (
    SELECT n.`n`,
           LEFT(TRIM(REPLACE(
               SUBSTRING_INDEX(SUBSTRING_INDEX(s.`value`, CHAR(10 USING utf8mb4), n.`n`), CHAR(10 USING utf8mb4), -1),
               CHAR(13 USING utf8mb4), '')), 100) AS `name`
    FROM `settings` s
    JOIN (
        SELECT 1 AS `n` UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
        UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10
        UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15
        UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19 UNION ALL SELECT 20
        UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23 UNION ALL SELECT 24 UNION ALL SELECT 25
        UNION ALL SELECT 26 UNION ALL SELECT 27 UNION ALL SELECT 28 UNION ALL SELECT 29 UNION ALL SELECT 30
        UNION ALL SELECT 31 UNION ALL SELECT 32 UNION ALL SELECT 33 UNION ALL SELECT 34 UNION ALL SELECT 35
        UNION ALL SELECT 36 UNION ALL SELECT 37 UNION ALL SELECT 38 UNION ALL SELECT 39 UNION ALL SELECT 40
        UNION ALL SELECT 41 UNION ALL SELECT 42 UNION ALL SELECT 43 UNION ALL SELECT 44 UNION ALL SELECT 45
        UNION ALL SELECT 46 UNION ALL SELECT 47 UNION ALL SELECT 48 UNION ALL SELECT 49 UNION ALL SELECT 50
        UNION ALL SELECT 51 UNION ALL SELECT 52 UNION ALL SELECT 53 UNION ALL SELECT 54 UNION ALL SELECT 55
        UNION ALL SELECT 56 UNION ALL SELECT 57 UNION ALL SELECT 58 UNION ALL SELECT 59 UNION ALL SELECT 60
    ) n ON n.`n` <= 1 + CHAR_LENGTH(s.`value`) - CHAR_LENGTH(REPLACE(s.`value`, CHAR(10 USING utf8mb4), ''))
    WHERE s.`key` = 'sds.product_families'
      AND s.`value` IS NOT NULL
) x
WHERE x.`name` <> '';

-- -----------------------------------------------------------
-- 5b. Seed every family name already stored on a finished good
--     (names typed/picked before this migration), after the setting's names.
-- -----------------------------------------------------------
INSERT IGNORE INTO `product_families` (`name`, `sort_order`)
SELECT DISTINCT TRIM(fg.`family`), 1000
FROM `finished_goods` fg
WHERE fg.`family` IS NOT NULL AND TRIM(fg.`family`) <> '';

-- -----------------------------------------------------------
-- 5c. Link legacy picks (name -> id) as manual overrides. Metadata-only
--     write on the FG row (updated_at preserved). Guarded by family_id IS NULL.
-- -----------------------------------------------------------
UPDATE `finished_goods` fg
JOIN `product_families` pf ON pf.`name` = TRIM(fg.`family`)
   SET fg.`family_id`     = pf.`id`,
       fg.`family_source` = 'manual',
       fg.`updated_at`    = fg.`updated_at`
 WHERE fg.`family_id` IS NULL
   AND fg.`family` IS NOT NULL AND TRIM(fg.`family`) <> '';

-- -----------------------------------------------------------
-- 5d. UV/LED flag seeded from the name heuristic the UV acrylate rule pack
--     used until now (UVAcrylateRulePack::isApplicable: name contains UV/LED),
--     as whole tokens so "Sealed" does not match. Admin can flip it later —
--     so this runs strictly once: guarded on the schema_migrations row (054
--     pattern); a hand re-run of this file must not re-flag a family an
--     admin deliberately un-flagged (that would silently re-enable the UV
--     rule pack text on every member sheet with no republish signal).
-- -----------------------------------------------------------
SET @m053_done = (SELECT COUNT(*) FROM `schema_migrations`
    WHERE `version` = '053_product_families');
SET @sql = IF(@m053_done = 0,
    'UPDATE `product_families` SET `is_uv` = 1 WHERE `is_uv` = 0 AND UPPER(`name`) REGEXP ''(^|[^A-Z])(UV|LED)([^A-Z]|$)''',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 6. Audit #19 (Section 10 decomposition products) — element flags on cas_master.
-- Hosted in 053_product_families.sql by track agreement. Independent of the
-- product_families DDL above (touches cas_master only).
-- has_nitrogen / has_sulfur / has_halogen drive the Section 10 "Hazardous
-- decomposition products" sentence (nitrogen oxides / sulfur oxides /
-- hydrogen halides). Seeded by scripts/seed-cas-element-flags.php (formula
-- first, conservative name keywords second); editable on /determinations
-- (CAS Descriptions tab). element_flags_source: 'seed' | 'manual' | NULL
-- (never set); the seed script leaves 'manual' rows alone unless --force.
-- Re-runnable: every step is guarded by an INFORMATION_SCHEMA check
-- (045/046 pattern). No seed rows here — MariaDB REGEXP is case-insensitive,
-- so element-symbol matching on molecular_formula is done in PHP.
-- ------------------------------------------------------------
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cas_master' AND COLUMN_NAME = 'has_nitrogen');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `cas_master` ADD COLUMN `has_nitrogen` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Audit #19: substance contains nitrogen -> SDS Section 10 lists nitrogen oxides'' AFTER `pubchem_cid`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cas_master' AND COLUMN_NAME = 'has_sulfur');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `cas_master` ADD COLUMN `has_sulfur` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Audit #19: substance contains sulfur -> SDS Section 10 lists sulfur oxides'' AFTER `has_nitrogen`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cas_master' AND COLUMN_NAME = 'has_halogen');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `cas_master` ADD COLUMN `has_halogen` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Audit #19: substance contains F/Cl/Br/I -> SDS Section 10 lists hydrogen halides'' AFTER `has_sulfur`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cas_master' AND COLUMN_NAME = 'element_flags_source');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `cas_master` ADD COLUMN `element_flags_source` VARCHAR(10) NULL COMMENT ''Audit #19: seed | manual | NULL (never set); seed script skips manual rows'' AFTER `has_halogen`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('053_product_families');
