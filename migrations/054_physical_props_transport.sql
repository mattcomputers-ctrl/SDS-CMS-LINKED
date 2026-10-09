-- 054: SDS content audit — batch C (track T2: physical properties / transport / substance-mixture)
--
-- Re-runnable: every DDL step is guarded by an INFORMATION_SCHEMA check
-- (045/046 pattern). DDL does not fire ON UPDATE CURRENT_TIMESTAMP, so
-- the DDL steps do not bump raw_materials.updated_at. The ONE exception is
-- the #18(e) data block near the end, which deliberately bumps updated_at
-- on the raws it rewrites (content change -> affected products republish)
-- and is guarded on the schema_migrations row so it runs exactly once.

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- Audit item 6: Substance vs mixture — explicit Auto / Substance / Mixture
-- on finished goods and raw materials (SDS Section 3 "Type:" line)
-- ------------------------------------------------------------
-- DDL only. ALTER TABLE ... ADD COLUMN does not fire ON UPDATE
-- CURRENT_TIMESTAMP, so raw_materials.updated_at is not bumped and no
-- SDS becomes stale. DEFAULT 'auto' keeps every existing sheet printing
-- "Mixture" (resolver: auto on a raw material = Mixture; auto on a
-- finished good = Substance only for a single-line formula whose raw
-- material is marked substance). No index: the column is never filtered on.

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finished_goods' AND COLUMN_NAME = 'substance_mixture');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `finished_goods` ADD COLUMN `substance_mixture` ENUM(''auto'',''substance'',''mixture'') NOT NULL DEFAULT ''auto'' COMMENT ''Audit item 6 - SDS Section 3 Type line. auto = Substance only when the current formula is a single raw material line marked substance, otherwise Mixture''',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_materials' AND COLUMN_NAME = 'substance_mixture');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `raw_materials` ADD COLUMN `substance_mixture` ENUM(''auto'',''substance'',''mixture'') NOT NULL DEFAULT ''auto'' COMMENT ''Audit item 6 - substance = single-substance material (its resale SDS and 100 pct formulas print Substance). auto and mixture both print Mixture''',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- #16 Initial boiling point: raw_materials.boiling_point_c
-- ------------------------------------------------------------
-- Section 9 prints the LOWEST boiling point among the expanded
-- composition's raw materials that carry one (FormulaCalcService
-- formula_props.boiling_point_c; recursive, weight-independent);
-- NULL = not determined. Adding a NULL column changes no content,
-- so raw_materials.updated_at is deliberately NOT bumped here.
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_materials' AND COLUMN_NAME = 'boiling_point_c');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `raw_materials` ADD COLUMN `boiling_point_c` DECIMAL(6,1) NULL COMMENT ''Initial boiling point in Celsius (audit #16); NULL = not determined'' AFTER `flash_point_greater_than`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Before #16 the Section 9 override form pre-filled the RESOLVED string
-- into override[9][boiling_point], so saving it persisted the literal
-- "Not determined" as a product-level override that would mask the
-- derived value. Drop exactly those rows (idempotent; real user-entered
-- boiling-point overrides are untouched).
DELETE FROM `text_overrides`
 WHERE `section_number` = 9
   AND `field_key` = 'boiling_point'
   AND `sds_version_id` IS NULL
   AND TRIM(`override_text`) IN ('Not determined', 'No determinado', 'Non déterminé', 'Nicht bestimmt');

-- ------------------------------------------------------------
-- #18(e) One-time data change: every raw material currently
-- "Soluble in water" or "Partially soluble in water" becomes
-- "Negligible solubility in water". The strings are the exact option
-- values of src/Views/raw-materials/form.php. updated_at is bumped
-- deliberately (content change): raw_materials.updated_at is the
-- bulk-publish staleness signal (SDSAutoSendService), so every product
-- using one of these raws republishes on the next bulk publish.
-- ------------------------------------------------------------

-- Guard: strictly once. After this file is recorded, an operator may set a
-- raw back to Soluble on purpose; a hand re-run must not clobber it.
SET @m054_done = (SELECT COUNT(*) FROM `schema_migrations`
    WHERE `version` = '054_physical_props_transport');

-- Affected count, captured BEFORE the update. migrate.php runs this file
-- through PDO::exec and prints nothing, so the number is persisted in
-- settings (INSERT IGNORE keeps the first run's value). Read it back with:
--   SELECT `value` FROM `settings` WHERE `key` = 'sds.migration.054.solubility_reset_count';
-- Pre-flight (before migrating):
--   SELECT COUNT(*) FROM raw_materials WHERE solubility IN ('Soluble in water','Partially soluble in water');
SET @m054_sol_n = (SELECT COUNT(*) FROM `raw_materials`
    WHERE `solubility` IN ('Soluble in water', 'Partially soluble in water'));

-- The bump is written with UTC_TIMESTAMP(), like RegulatoryListBumper /
-- FamilyResolver: this file runs under the server's global time zone (mysql
-- CLI in update.sh, plain PDO in migrate.php), and publish-worker stamps
-- sds_versions.published_at in UTC — a local CURRENT_TIMESTAMP behind UTC
-- would leave products published in the preceding hours looking fresh.
SET @sql = IF(@m054_done = 0,
    'UPDATE `raw_materials` SET `solubility` = ''Negligible solubility in water'', `updated_at` = UTC_TIMESTAMP() WHERE `solubility` IN (''Soluble in water'', ''Partially soluble in water'')',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(@m054_done = 0,
    'INSERT IGNORE INTO `settings` (`key`, `value`) VALUES (''sds.migration.054.solubility_reset_count'', @m054_sol_n)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- #27 Section 14 transport: finished_goods.transport_product_type
-- NULL = automatic (TransportClassifier keyword rule on description /
-- family); a value forces the DOT entry family used for Class 3 products:
--   ink           UN1210 Printing ink, flammable
--   ink_related   UN1210 Printing ink related material, flammable
--   paint         UN1263 Paint
--   paint_related UN1263 Paint related material
--   nos           UN1993 Flammable liquids, n.o.s.
-- Re-runnable: guarded by INFORMATION_SCHEMA (045/046 pattern). DDL only:
-- raw_materials.updated_at is not bumped (NULL column = no content change).
-- ------------------------------------------------------------
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finished_goods' AND COLUMN_NAME = 'transport_product_type');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `finished_goods` ADD COLUMN `transport_product_type` ENUM(''ink'',''ink_related'',''paint'',''paint_related'',''nos'') NULL DEFAULT NULL COMMENT ''Audit #27: DOT entry family for SDS Section 14; NULL = derive from description/family keywords (TransportClassifier)'' AFTER `color`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- #27: before this item the Section 14 override form pre-filled the RESOLVED
-- values ("Not regulated" / "Not applicable") into override[14][*], so saving
-- persisted them as product-level overrides. Overrides now WIN over the
-- derivation, so those baked-in rows would mask a real UN1210 classification.
-- Drop exactly those rows (idempotent; operator-entered UN numbers untouched).
DELETE FROM `text_overrides`
 WHERE `section_number` = 14
   AND `sds_version_id` IS NULL
   AND TRIM(`override_text`) IN ('Not regulated', 'Not applicable', 'No regulado', 'No aplica', 'Non réglementé', 'Non applicable', 'Nicht reguliert', 'Nicht zutreffend');

-- (sibling blocks go above this line; keep exactly one version row)
INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('054_physical_props_transport');
