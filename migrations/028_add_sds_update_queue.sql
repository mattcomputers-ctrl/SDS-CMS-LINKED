-- ============================================================
-- Migration 028 — SDS Update Queue
-- ============================================================
-- Tracks which finished goods (and aliases) need SDS review/
-- regeneration after raw material or constituent changes.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `sds_update_queue` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `finished_good_id`  INT UNSIGNED NOT NULL,
    `reason`            VARCHAR(500) NOT NULL DEFAULT '',
    `source_type`       ENUM('raw_material', 'constituent') NOT NULL DEFAULT 'raw_material',
    `source_id`         INT UNSIGNED NULL COMMENT 'raw_material_id or constituent CAS ref',
    `status`            ENUM('pending', 'completed', 'dismissed') NOT NULL DEFAULT 'pending',
    `queued_by`         INT UNSIGNED NULL,
    `queued_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `resolved_by`       INT UNSIGNED NULL,
    `resolved_at`       DATETIME NULL,
    INDEX `idx_suq_status` (`status`),
    INDEX `idx_suq_fg` (`finished_good_id`),
    INDEX `idx_suq_source` (`source_type`, `source_id`),
    CONSTRAINT `fk_suq_fg` FOREIGN KEY (`finished_good_id`) REFERENCES `finished_goods`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_suq_queued_by` FOREIGN KEY (`queued_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_suq_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
