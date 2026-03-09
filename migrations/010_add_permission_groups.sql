-- ============================================================
-- Migration 010: Group-based Permission System
-- Adds permission_groups, group_permissions, and user_group_members
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------
-- Permission Groups (e.g. "Editors", "Lab Techs", "Managers")
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `permission_groups` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(100) NOT NULL UNIQUE,
    `description` VARCHAR(500) NOT NULL DEFAULT '',
    `is_admin`    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Admin groups have full access',
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Group Permissions — per-page access level for each group
-- page_key matches a route/section identifier
-- access_level: none, read, full
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `group_permissions` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `group_id`     INT UNSIGNED NOT NULL,
    `page_key`     VARCHAR(100) NOT NULL COMMENT 'e.g. dashboard, raw_materials, finished_goods',
    `access_level` ENUM('none','read','full') NOT NULL DEFAULT 'none',
    UNIQUE INDEX `idx_gp_group_page` (`group_id`, `page_key`),
    CONSTRAINT `fk_gp_group` FOREIGN KEY (`group_id`) REFERENCES `permission_groups`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- User-Group Membership — users can belong to one or more groups
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_group_members` (
    `id`       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`  INT UNSIGNED NOT NULL,
    `group_id` INT UNSIGNED NOT NULL,
    UNIQUE INDEX `idx_ugm_user_group` (`user_id`, `group_id`),
    CONSTRAINT `fk_ugm_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ugm_group` FOREIGN KEY (`group_id`) REFERENCES `permission_groups`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`version`) VALUES ('010_add_permission_groups');

SET FOREIGN_KEY_CHECKS = 1;
