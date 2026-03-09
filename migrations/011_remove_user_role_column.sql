-- Migration 011: Remove legacy role column from users table
-- The role-based access control has been replaced by the Permission Group system.
-- Users are now assigned to a single permission group which controls all access.

ALTER TABLE `users` DROP COLUMN `role`;
