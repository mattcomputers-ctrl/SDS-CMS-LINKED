-- ============================================================
-- SDS System — Migration 003
-- Add 'sds_book_only' role, make email optional, clear emails
-- ============================================================

-- Add the sds_book_only role to the ENUM
ALTER TABLE `users`
    MODIFY COLUMN `role` ENUM('admin','editor','readonly','sds_book_only') NOT NULL DEFAULT 'readonly';

-- Make email optional (nullable, remove NOT NULL)
ALTER TABLE `users`
    MODIFY COLUMN `email` VARCHAR(255) NULL DEFAULT NULL;

-- Clear all existing email addresses (they may contain real addresses
-- that should not be associated with this project)
UPDATE `users` SET `email` = NULL;

-- Add badge color mapping note for the new role
-- (handled in CSS: .badge-sds_book_only)

INSERT INTO `schema_migrations` (`version`) VALUES ('003_add_sds_book_role');
