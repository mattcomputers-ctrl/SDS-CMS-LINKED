-- ============================================================
-- Migration 011: Fix group_permissions access_level column
-- Ensures access_level is ENUM('none','read','full') to match
-- the application code expectations.
-- ============================================================

ALTER TABLE `group_permissions`
    MODIFY COLUMN `access_level` ENUM('none','read','full') NOT NULL DEFAULT 'none';

INSERT INTO `schema_migrations` (`version`) VALUES ('011_fix_group_permissions_access_level');
