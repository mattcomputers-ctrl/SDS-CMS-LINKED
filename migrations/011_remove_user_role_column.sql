-- Migration 011: Remove legacy role column from users table
-- The role-based access control has been replaced by the Permission Group system.
-- Users are now assigned to a single permission group which controls all access.

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'users'
                     AND COLUMN_NAME = 'role');

SET @ddl = IF(@col_exists > 0,
              'ALTER TABLE `users` DROP COLUMN `role`',
              'SELECT 1 /* role column already removed */');

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO `schema_migrations` (`version`) VALUES ('011_remove_user_role_column');
