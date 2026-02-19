-- ============================================================
-- SDS System — Migration 002
-- Adds Prop 65 list, carcinogen registry, and H/P statement
-- text reference tables.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------
-- California Proposition 65 Chemical List
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `prop65_list` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `cas_number`      VARCHAR(20) NOT NULL,
    `chemical_name`   VARCHAR(300) NOT NULL DEFAULT '',
    `toxicity_type`   VARCHAR(200) NOT NULL COMMENT 'cancer, developmental, female reproductive, male reproductive (comma-separated)',
    `listing_mechanism` VARCHAR(100) NULL COMMENT 'e.g. Labor Code, State qualified experts, Formally required',
    `nsrl_ug`         DECIMAL(10,4) NULL COMMENT 'No Significant Risk Level (cancer) µg/day',
    `madl_ug`         DECIMAL(10,4) NULL COMMENT 'Maximum Allowable Dose Level (reproductive) µg/day',
    `date_listed`     DATE NULL,
    `source_ref`      VARCHAR(500) NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX `idx_p65_cas` (`cas_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Carcinogen Registry — IARC, NTP, OSHA listings
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `carcinogen_list` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `cas_number`      VARCHAR(20) NOT NULL,
    `chemical_name`   VARCHAR(300) NOT NULL DEFAULT '',
    `agency`          VARCHAR(20) NOT NULL COMMENT 'IARC, NTP, or OSHA',
    `classification`  VARCHAR(100) NOT NULL COMMENT 'e.g. Group 1, Group 2A, Known, Reasonably Anticipated, Listed',
    `description`     TEXT NULL,
    `source_ref`      VARCHAR(500) NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_carc_cas` (`cas_number`),
    INDEX `idx_carc_agency` (`agency`),
    UNIQUE INDEX `idx_carc_cas_agency` (`cas_number`, `agency`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`version`) VALUES ('002_add_prop65_carcinogens');

SET FOREIGN_KEY_CHECKS = 1;
