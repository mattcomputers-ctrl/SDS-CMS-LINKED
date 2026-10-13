-- ============================================================
-- Migration 064: raw_materials.sds_not_required (2026-10-13)
--
-- The CMS sync imports every CMS Resale GL item (migration 063 release),
-- including non-chemicals: pails and lids (5562/5563), Pantone guides
-- (GG..., GP..., PAGPS207), IR LASER PEN, IR DETECTOR, WEDGEL, ink knives,
-- GFI 4945, MISC. They are flagged instead of deleted.
--
-- 1 = No SDS required: no resale SDS, alias sheet or GHS label is built for
-- the item (or for an alias that resolves to it), it is not a bulk-publish
-- resale candidate, shipments of it are not queued for SDS review, and it
-- drops out of the resale, vendor-SDS, inventory and needs-details
-- worklists. Formula behaviour is unchanged: if a recipe uses a flagged
-- item, its data still feeds that finished good's SDS.
--
-- Set on Raw Materials > Edit or the Raw Materials list (permission
-- "Raw Materials: mark No SDS required", admins by default). Toggling is a
-- metadata write (RawMaterial::setSdsNotRequired, updated_at = updated_at)
-- and never makes an SDS stale. DDL only: ADD COLUMN does not fire
-- ON UPDATE CURRENT_TIMESTAMP, so nothing is bumped here, and no item is
-- flagged here (flag them in the UI so each one is audited).
--
-- Re-runnable: INFORMATION_SCHEMA + PREPARE guard (045/046 pattern).
-- ============================================================

SET NAMES utf8mb4;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_materials' AND COLUMN_NAME = 'sds_not_required');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `raw_materials` ADD COLUMN `sds_not_required` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''No SDS required (not a chemical): no resale/alias SDS or label, left off resale worklists; formulas unchanged. Metadata: write with updated_at = updated_at'' AFTER `combustible_dust`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('064_rm_sds_not_required');
