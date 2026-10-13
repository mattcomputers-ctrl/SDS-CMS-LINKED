-- ============================================================
-- Migration 063: CMS raw material import exclusions (2026-10-13)
--
-- The CMS sync now creates a raw material for every item in the CMS
-- 'Raw Material' and 'Resale' GL groups (CMSImportService::
-- importRawMaterialsByGLGroup), including non-chemicals that sit in the
-- Resale group (pails, lids, Pantone guides). Deleting such a raw material
-- used to be undone by the next hourly sync. Deleting one now records its
-- code here, and the GL-group import (and its preview) skips every code in
-- this table. Creating the raw material again by hand removes the row.
-- The formula-ingredient import ignores this table: a code that a recipe
-- uses is still created.
--
-- Re-runnable: CREATE TABLE IF NOT EXISTS. No seed rows.
-- ============================================================

CREATE TABLE IF NOT EXISTS `cms_rm_import_exclusions` (
    `item_code`  VARCHAR(50)  NOT NULL PRIMARY KEY COMMENT 'CMS ItemCode = raw_materials.internal_code',
    `reason`     VARCHAR(255) NOT NULL DEFAULT '',
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('063_cms_rm_import_exclusions');
