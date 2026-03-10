-- Migration 014: Add alias_id column to sds_versions table.
--
-- When an SDS is published for an alias, alias_id references the aliases table.
-- For the primary finished good SDS, alias_id is NULL.

SET NAMES utf8mb4;

ALTER TABLE `sds_versions`
    ADD COLUMN `alias_id` INT UNSIGNED DEFAULT NULL AFTER `finished_good_id`,
    ADD INDEX `idx_sds_versions_alias` (`alias_id`);

-- ============================================================
-- Record this migration
-- ============================================================
INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('014_add_alias_id_to_sds_versions');
