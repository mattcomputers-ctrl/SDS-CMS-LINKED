-- ============================================================
-- Migration 029: CMS Import Log
-- Tracks items imported from the CMS MSSQL database.
-- Stores the CMS recipe number so formula revisions can be
-- detected on subsequent import runs.
-- ============================================================

CREATE TABLE IF NOT EXISTS `cms_import_log` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `cms_item_code`     VARCHAR(30)  NOT NULL COMMENT 'ItemCode from CMS.dbo.Item',
    `cms_item_pk`       INT          NOT NULL COMMENT 'Item PK from CMS.dbo.Item',
    `cms_recipe_number` VARCHAR(20)  NULL     COMMENT 'RecipeNumber at time of import — used for revision detection',
    `cms_recipe_pk`     INT          NULL     COMMENT 'Recipe PK at time of import',
    `entity_type`       ENUM('finished_good','raw_material') NOT NULL,
    `entity_id`         INT UNSIGNED NOT NULL COMMENT 'PK in the SDS system table',
    `imported_by`       INT UNSIGNED NULL,
    `imported_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_cms_import_item_code` (`cms_item_code`),
    INDEX `idx_cms_import_entity` (`entity_type`, `entity_id`),
    INDEX `idx_cms_import_by` (`imported_by`),
    UNIQUE INDEX `idx_cms_import_unique_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('029_add_cms_import_log');
