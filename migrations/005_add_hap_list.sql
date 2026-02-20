-- ============================================================
-- SDS System — Migration 005
-- Add Hazardous Air Pollutants (HAP) registry table
-- Clean Air Act Section 112(b)
-- ============================================================

CREATE TABLE IF NOT EXISTS `hap_list` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `cas_number`      VARCHAR(20) NOT NULL,
    `chemical_name`   VARCHAR(400) NOT NULL DEFAULT '',
    `category`        VARCHAR(100) NULL COMMENT 'e.g. VOC, Metal, Particulate, Organic',
    `source_ref`      VARCHAR(500) NULL,
    `last_updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX `idx_hap_cas` (`cas_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`version`) VALUES ('005_add_hap_list');
