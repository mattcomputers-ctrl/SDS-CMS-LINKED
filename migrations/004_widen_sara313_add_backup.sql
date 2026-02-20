-- ============================================================
-- SDS System — Migration 004
-- Widen SARA 313 chemical_name column for long PFAS polymer
-- names, and add backups tracking table.
-- ============================================================

-- Some SARA 313 TRI chemicals (especially PFAS polymers) have
-- IUPAC names exceeding 500 characters.  Widen from 300 to 600.
ALTER TABLE `sara313_list`
    MODIFY COLUMN `chemical_name` VARCHAR(600) NOT NULL DEFAULT '';

-- ── Backup tracking table ──────────────────────────────────
CREATE TABLE IF NOT EXISTS `backups` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `filename`      VARCHAR(255) NOT NULL,
    `backup_type`   ENUM('full','content') NOT NULL DEFAULT 'full'
                    COMMENT 'full = DB + files; content = data tables only',
    `file_size`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `tables_json`   TEXT NULL COMMENT 'JSON list of tables included',
    `notes`         VARCHAR(500) NULL,
    `created_by`    INT UNSIGNED NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_backups_user` FOREIGN KEY (`created_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL,
    INDEX `idx_backups_type` (`backup_type`),
    INDEX `idx_backups_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`version`) VALUES ('004_widen_sara313_add_backup');
