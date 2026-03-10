-- Migration 013: Add aliases table for customer-facing product code mappings.
--
-- Aliases link a customer-facing code (with pack extension) to an internal
-- finished good product code. Used when generating SDS exports per alias.

SET NAMES utf8mb4;

-- -----------------------------------------------------------
-- Product Aliases
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `aliases` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `customer_code`     VARCHAR(100) NOT NULL COMMENT 'Customer-facing code with pack extension',
    `description`       VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'Customer-facing description',
    `internal_code`     VARCHAR(100) NOT NULL COMMENT 'Internal finished good code (with pack extension)',
    `internal_code_base` VARCHAR(100) NOT NULL COMMENT 'Internal code without pack extension',
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX `idx_alias_customer_code` (`customer_code`),
    INDEX `idx_alias_internal_base` (`internal_code_base`),
    INDEX `idx_alias_internal_code` (`internal_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Record this migration
-- ============================================================
INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('013_add_aliases_table');
