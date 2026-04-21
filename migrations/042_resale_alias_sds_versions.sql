-- ============================================================
-- Migration 042: Resale-alias support on sds_versions.
--
-- Some purchased items are sold as-is under a customer-facing alias
-- (e.g. alias MS1277-20 points to raw material DSS0100-20, which has
-- no formula of its own). The SDS for such an alias is derived from
-- the raw material's hazard data at 100 %, not from a finished-good
-- formula. To version + publish those SDSs through the existing
-- sds_versions pipeline, we relax the finished_good_id constraint and
-- record the source raw material.
--
-- After this migration:
--   - finished_good_id is nullable. NULL means the SDS was generated
--     from a raw-material resale path rather than an FG formula.
--   - raw_material_id records the source RM when the SDS is resale-
--     derived. NULL for every formula-based SDS.
--
-- Application-level invariant: at least one of finished_good_id and
-- raw_material_id must be non-NULL. alias_id is set when the row is
-- the alias variant of either source.
--
-- Rollback:
--   ALTER TABLE `sds_versions`
--     DROP FOREIGN KEY `fk_sv_rm`,
--     DROP INDEX `idx_sv_rm`,
--     DROP COLUMN `raw_material_id`,
--     MODIFY COLUMN `finished_good_id` INT UNSIGNED NOT NULL;
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `sds_versions`
    MODIFY COLUMN `finished_good_id` INT UNSIGNED NULL,
    ADD COLUMN `raw_material_id` INT UNSIGNED NULL AFTER `alias_id`,
    ADD INDEX `idx_sv_rm` (`raw_material_id`),
    ADD CONSTRAINT `fk_sv_rm` FOREIGN KEY (`raw_material_id`)
        REFERENCES `raw_materials`(`id`) ON DELETE RESTRICT;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('042_resale_alias_sds_versions');
