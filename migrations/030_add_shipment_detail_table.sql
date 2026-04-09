-- ============================================================
-- Migration 030: Shipment Detail Table
-- Stores shipment data imported from the CMS ShipmentDetails view.
-- Replaces the session-based CSV upload approach.
-- ============================================================

CREATE TABLE IF NOT EXISTS `shipment_detail` (
    `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `bill_to`               VARCHAR(100) NULL,
    `ship_to`               VARCHAR(100) NULL,
    `ship_to_name`          VARCHAR(200) NULL,
    `date_shipped`          DATETIME NULL,
    `item_code`             VARCHAR(50) NOT NULL COMMENT 'Actual inventory item code',
    `item_description`      VARCHAR(500) NULL COMMENT 'Inventory item description from CMS',
    `item_name`             VARCHAR(50) NULL COMMENT 'Alias code used on the order',
    `item_name_description` VARCHAR(500) NULL COMMENT 'Alias description from CMS',
    `qty_shipped`           DECIMAL(12,4) NOT NULL DEFAULT 0,
    `invoice_number`        VARCHAR(50) NULL,
    `order_number`          VARCHAR(50) NULL,
    `cms_changeset`         INT NULL COMMENT 'CMS ChangeSet PK for dedup',
    `imported_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sd_date` (`date_shipped`),
    INDEX `idx_sd_ship_to` (`ship_to`),
    INDEX `idx_sd_bill_to` (`bill_to`),
    INDEX `idx_sd_item` (`item_code`),
    INDEX `idx_sd_item_name` (`item_name`),
    UNIQUE INDEX `idx_sd_dedup` (`cms_changeset`, `item_code`, `date_shipped`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('030_add_shipment_detail_table');
