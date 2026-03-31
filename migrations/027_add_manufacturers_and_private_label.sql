-- ============================================================
-- Migration 027 — Manufacturers & Private Label SDS
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------
-- Manufacturers — private-label / multi-brand support
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `manufacturers` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`          VARCHAR(300) NOT NULL,
    `address`       VARCHAR(500) NOT NULL DEFAULT '',
    `city`          VARCHAR(200) NOT NULL DEFAULT '',
    `state`         VARCHAR(100) NOT NULL DEFAULT '',
    `zip`           VARCHAR(20) NOT NULL DEFAULT '',
    `country`       VARCHAR(100) NOT NULL DEFAULT '',
    `phone`         VARCHAR(50) NOT NULL DEFAULT '',
    `emergency_phone` VARCHAR(50) NOT NULL DEFAULT '',
    `email`         VARCHAR(255) NOT NULL DEFAULT '',
    `website`       VARCHAR(500) NOT NULL DEFAULT '',
    `logo_path`     VARCHAR(500) NULL,
    `is_default`    TINYINT(1) NOT NULL DEFAULT 0,
    `created_by`    INT UNSIGNED NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_mfg_default` (`is_default`),
    CONSTRAINT `fk_mfg_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Private Label SDS — stored separately from main SDS versions
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `private_label_sds` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `finished_good_id` INT UNSIGNED NOT NULL,
    `manufacturer_id`  INT UNSIGNED NOT NULL,
    `alias_id`         INT UNSIGNED NULL COMMENT 'Optional alias for product code override',
    `language`         VARCHAR(5) NOT NULL DEFAULT 'en',
    `version`          INT NOT NULL DEFAULT 1,
    `status`           ENUM('draft','published') NOT NULL DEFAULT 'published',
    `effective_date`   DATE NULL,
    `published_by`     INT UNSIGNED NULL,
    `published_at`     DATETIME NULL,
    `snapshot_json`    JSON NULL COMMENT 'Immutable snapshot of all data at publish time',
    `pdf_path`         VARCHAR(500) NULL,
    `change_summary`   TEXT NULL,
    `created_by`       INT UNSIGNED NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_plsds_fg` (`finished_good_id`),
    INDEX `idx_plsds_mfg` (`manufacturer_id`),
    INDEX `idx_plsds_alias` (`alias_id`),
    INDEX `idx_plsds_latest` (`finished_good_id`, `manufacturer_id`, `language`, `version` DESC),
    CONSTRAINT `fk_plsds_fg` FOREIGN KEY (`finished_good_id`) REFERENCES `finished_goods`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_plsds_mfg` FOREIGN KEY (`manufacturer_id`) REFERENCES `manufacturers`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_plsds_alias` FOREIGN KEY (`alias_id`) REFERENCES `aliases`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_plsds_published_by` FOREIGN KEY (`published_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_plsds_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`version`) VALUES ('027_add_manufacturers_and_private_label');

SET FOREIGN_KEY_CHECKS = 1;
