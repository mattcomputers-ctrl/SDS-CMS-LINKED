-- ============================================================
-- Migration 006: Raw Material SDS History
--
-- Creates a dedicated table for storing all supplier SDS uploads
-- per raw material. Old SDSs are never deleted; the newest one
-- (highest id / most recent uploaded_at) is considered current.
-- ============================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------
-- Raw Material SDS History
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `raw_material_sds` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `raw_material_id` INT UNSIGNED NOT NULL,
    `file_path`       VARCHAR(500) NOT NULL COMMENT 'Relative path under public/uploads/',
    `original_filename` VARCHAR(300) NOT NULL DEFAULT '' COMMENT 'Original uploaded filename',
    `file_size`       INT UNSIGNED NULL COMMENT 'File size in bytes',
    `notes`           VARCHAR(500) NULL COMMENT 'Optional notes about this SDS revision',
    `uploaded_by`     INT UNSIGNED NULL,
    `uploaded_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_rmsds_rm` (`raw_material_id`),
    INDEX `idx_rmsds_date` (`raw_material_id`, `uploaded_at` DESC),
    CONSTRAINT `fk_rmsds_rm` FOREIGN KEY (`raw_material_id`) REFERENCES `raw_materials`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rmsds_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate existing supplier_sds_path data into the new table
-- so no existing SDS references are lost.
INSERT INTO `raw_material_sds` (`raw_material_id`, `file_path`, `original_filename`, `uploaded_at`)
SELECT `id`, `supplier_sds_path`, SUBSTRING_INDEX(`supplier_sds_path`, '/', -1), `updated_at`
FROM `raw_materials`
WHERE `supplier_sds_path` IS NOT NULL AND `supplier_sds_path` != '';

-- Record this migration
INSERT INTO `schema_migrations` (`version`) VALUES ('006_add_raw_material_sds_history');
