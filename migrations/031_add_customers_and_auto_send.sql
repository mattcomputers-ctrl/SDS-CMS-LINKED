-- ============================================================
-- Migration 031: Customer Manager & Auto-Send SDS Infrastructure
-- Adds customers table (keyed by Ship To location), SDS send
-- tracking log, send queue for regulatory review, and
-- is_regulatory flag on users.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Customers table — keyed by Ship To (shipping location)
-- Each shipping location may have its own regulatory team and email.
CREATE TABLE IF NOT EXISTS `customers` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ship_to`          VARCHAR(100) NOT NULL UNIQUE COMMENT 'Ship To code from CMS',
    `ship_to_name`     VARCHAR(200) NULL,
    `regulatory_email` VARCHAR(255) NULL COMMENT 'Regulatory contact email for this location',
    `sds_send_mode`    ENUM('osha','osha_6mo','every_order') NOT NULL DEFAULT 'osha',
    `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_cust_name` (`ship_to_name`),
    INDEX `idx_cust_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tracks which SDS has been sent to which customer for which item/alias
CREATE TABLE IF NOT EXISTS `sds_send_log` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `customer_id`      INT UNSIGNED NOT NULL,
    `finished_good_id` INT UNSIGNED NOT NULL,
    `item_identifier`  VARCHAR(100) NOT NULL COMMENT 'Alias code or item code — what the customer sees',
    `sds_version_id`   INT UNSIGNED NOT NULL,
    `language`         VARCHAR(5) NOT NULL DEFAULT 'en',
    `sent_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `shipment_date`    DATETIME NULL,
    INDEX `idx_ssl_customer` (`customer_id`),
    INDEX `idx_ssl_fg` (`finished_good_id`),
    INDEX `idx_ssl_item` (`item_identifier`),
    INDEX `idx_ssl_customer_item` (`customer_id`, `item_identifier`),
    CONSTRAINT `fk_ssl_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Queue for orders where SDS couldn't be auto-sent (missing RM data or CAS determinations)
CREATE TABLE IF NOT EXISTS `sds_send_queue` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `customer_id`      INT UNSIGNED NOT NULL,
    `ship_to`          VARCHAR(100) NULL,
    `ship_to_name`     VARCHAR(200) NULL,
    `shipment_date`    DATETIME NULL,
    `item_code`        VARCHAR(50) NULL,
    `item_name`        VARCHAR(50) NULL COMMENT 'Alias code if used on order',
    `item_description` VARCHAR(500) NULL,
    `reason`           VARCHAR(500) NULL COMMENT 'Why auto-send failed',
    `status`           ENUM('pending','sent','dismissed') NOT NULL DEFAULT 'pending',
    `resolved_by`      INT UNSIGNED NULL,
    `resolved_at`      DATETIME NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ssq_status` (`status`),
    INDEX `idx_ssq_customer` (`customer_id`),
    CONSTRAINT `fk_ssq_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add regulatory notification flag to users
ALTER TABLE `users` ADD COLUMN `is_regulatory` TINYINT(1) NOT NULL DEFAULT 0;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('031_add_customers_and_auto_send');

SET FOREIGN_KEY_CHECKS = 1;
