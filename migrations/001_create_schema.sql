-- ============================================================
-- SDS System Database Schema — Migration 001
-- OSHA HazCom 2012 / GHS SDS Authoring System
-- Target: MariaDB 10.x / MySQL 8.x with InnoDB
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------
-- Users and Authentication
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username`      VARCHAR(100) NOT NULL UNIQUE,
    `email`         VARCHAR(255) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `display_name`  VARCHAR(200) NOT NULL DEFAULT '',
    `role`          ENUM('admin','editor','readonly') NOT NULL DEFAULT 'readonly',
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `last_login`    DATETIME NULL,
    INDEX `idx_users_role` (`role`),
    INDEX `idx_users_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Audit Log
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_log` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NULL,
    `entity_type` VARCHAR(100) NOT NULL,
    `entity_id`   VARCHAR(100) NOT NULL DEFAULT '',
    `action`      VARCHAR(50) NOT NULL,
    `diff_json`   JSON NULL,
    `ip_address`  VARCHAR(45) NULL,
    `timestamp`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_audit_entity` (`entity_type`, `entity_id`),
    INDEX `idx_audit_user` (`user_id`),
    INDEX `idx_audit_time` (`timestamp`),
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Raw Materials
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `raw_materials` (
    `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `internal_code`         VARCHAR(50) NOT NULL UNIQUE,
    `supplier`              VARCHAR(200) NOT NULL DEFAULT '',
    `supplier_product_name` VARCHAR(200) NOT NULL DEFAULT '',
    `supplier_sds_path`     VARCHAR(500) NULL,
    `voc_wt`                DECIMAL(8,4) NULL COMMENT 'VOC wt% non-exempt',
    `exempt_voc_wt`         DECIMAL(8,4) NULL COMMENT 'Exempt VOC wt%',
    `water_wt`              DECIMAL(8,4) NULL COMMENT 'Water wt%',
    `specific_gravity`      DECIMAL(8,5) NULL,
    `density`               DECIMAL(8,5) NULL COMMENT 'g/mL or lb/gal depending on units field',
    `density_units`         VARCHAR(20) NOT NULL DEFAULT 'g/mL',
    `temp_ref_c`            DECIMAL(6,2) NULL COMMENT 'Temperature reference in Celsius',
    `solids_wt`             DECIMAL(8,4) NULL COMMENT 'Solids wt%',
    `solids_vol`            DECIMAL(8,4) NULL COMMENT 'Solids vol%',
    `flash_point_c`         DECIMAL(8,2) NULL COMMENT 'Flash point in Celsius',
    `physical_state`        VARCHAR(50) NULL,
    `appearance`            VARCHAR(200) NULL,
    `odor`                  VARCHAR(200) NULL,
    `notes`                 TEXT NULL,
    `created_by`            INT UNSIGNED NULL,
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_rm_supplier` (`supplier`),
    INDEX `idx_rm_code` (`internal_code`),
    CONSTRAINT `fk_rm_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Raw Material Constituents (CAS composition)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `raw_material_constituents` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `raw_material_id` INT UNSIGNED NOT NULL,
    `cas_number`      VARCHAR(20) NOT NULL,
    `chemical_name`   VARCHAR(300) NOT NULL DEFAULT '',
    `pct_min`         DECIMAL(8,4) NULL,
    `pct_max`         DECIMAL(8,4) NULL,
    `pct_exact`       DECIMAL(8,4) NULL,
    `is_trade_secret` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order`      INT NOT NULL DEFAULT 0,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_rmc_rm` (`raw_material_id`),
    INDEX `idx_rmc_cas` (`cas_number`),
    CONSTRAINT `fk_rmc_rm` FOREIGN KEY (`raw_material_id`) REFERENCES `raw_materials`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Finished Goods
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `finished_goods` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_code`  VARCHAR(50) NOT NULL UNIQUE,
    `description`   VARCHAR(500) NOT NULL DEFAULT '',
    `family`        VARCHAR(100) NULL COMMENT 'UV offset, UV flexo, aqueous, solvent, etc.',
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `created_by`    INT UNSIGNED NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_fg_code` (`product_code`),
    FULLTEXT INDEX `ft_fg_description` (`description`),
    INDEX `idx_fg_family` (`family`),
    CONSTRAINT `fk_fg_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Formulas
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `formulas` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `finished_good_id` INT UNSIGNED NOT NULL,
    `version`          INT NOT NULL DEFAULT 1,
    `is_current`       TINYINT(1) NOT NULL DEFAULT 1,
    `notes`            TEXT NULL,
    `created_by`       INT UNSIGNED NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_formula_fg` (`finished_good_id`),
    INDEX `idx_formula_current` (`finished_good_id`, `is_current`),
    CONSTRAINT `fk_formula_fg` FOREIGN KEY (`finished_good_id`) REFERENCES `finished_goods`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_formula_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Formula Lines (raw material + percent)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `formula_lines` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `formula_id`      INT UNSIGNED NOT NULL,
    `raw_material_id` INT UNSIGNED NOT NULL,
    `pct`             DECIMAL(8,4) NOT NULL COMMENT 'Weight percent of raw material in formula',
    `sort_order`      INT NOT NULL DEFAULT 0,
    INDEX `idx_fl_formula` (`formula_id`),
    CONSTRAINT `fk_fl_formula` FOREIGN KEY (`formula_id`) REFERENCES `formulas`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_fl_rm` FOREIGN KEY (`raw_material_id`) REFERENCES `raw_materials`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- CAS Master — identity resolution cache
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cas_master` (
    `cas_number`      VARCHAR(20) NOT NULL PRIMARY KEY,
    `preferred_name`  VARCHAR(300) NOT NULL DEFAULT '',
    `synonyms_json`   JSON NULL,
    `molecular_formula` VARCHAR(200) NULL,
    `molecular_weight`  DECIMAL(12,4) NULL,
    `pubchem_cid`     BIGINT UNSIGNED NULL,
    `last_resolved_at` DATETIME NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Hazard Source Records — raw data from federal sources
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hazard_source_records` (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `cas_number`    VARCHAR(20) NOT NULL,
    `source_name`   VARCHAR(100) NOT NULL COMMENT 'pubchem, niosh, epa, dot',
    `source_ref`    VARCHAR(500) NULL COMMENT 'Specific dataset or document reference',
    `source_url`    VARCHAR(1000) NULL,
    `retrieved_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `payload_hash`  VARCHAR(64) NULL COMMENT 'SHA-256 of payload_json for change detection',
    `payload_json`  JSON NOT NULL,
    `is_current`    TINYINT(1) NOT NULL DEFAULT 1,
    INDEX `idx_hsr_cas_source` (`cas_number`, `source_name`, `retrieved_at`),
    INDEX `idx_hsr_current` (`cas_number`, `source_name`, `is_current`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Hazard Classifications — parsed/normalized from source records
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hazard_classifications` (
    `id`                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `hazard_source_record_id` BIGINT UNSIGNED NOT NULL,
    `cas_number`              VARCHAR(20) NOT NULL,
    `jurisdiction`            VARCHAR(50) NOT NULL DEFAULT 'US' COMMENT 'US, EU, CA, etc.',
    `class_name`              VARCHAR(200) NOT NULL COMMENT 'e.g. Flammable Liquids',
    `category`                VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'e.g. Category 2',
    `h_statements_json`       JSON NULL COMMENT '["H225","H302"]',
    `p_statements_json`       JSON NULL COMMENT '["P210","P233"]',
    `pictograms_json`         JSON NULL COMMENT '["GHS02","GHS07"]',
    `signal_word`             VARCHAR(20) NULL COMMENT 'Danger or Warning',
    INDEX `idx_hc_cas` (`cas_number`),
    INDEX `idx_hc_source` (`hazard_source_record_id`),
    CONSTRAINT `fk_hc_source` FOREIGN KEY (`hazard_source_record_id`) REFERENCES `hazard_source_records`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Exposure Limits — OELs, PELs, TLVs from source records
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `exposure_limits` (
    `id`                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `hazard_source_record_id` BIGINT UNSIGNED NOT NULL,
    `cas_number`              VARCHAR(20) NOT NULL,
    `limit_type`              VARCHAR(50) NOT NULL COMMENT 'PEL-TWA, TLV-TWA, REL-TWA, IDLH, STEL, Ceiling',
    `value`                   VARCHAR(100) NOT NULL,
    `units`                   VARCHAR(50) NOT NULL DEFAULT 'mg/m3',
    `notes`                   TEXT NULL,
    INDEX `idx_el_cas` (`cas_number`),
    CONSTRAINT `fk_el_source` FOREIGN KEY (`hazard_source_record_id`) REFERENCES `hazard_source_records`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Dataset Refresh Log
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `dataset_refresh_log` (
    `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `source_name`  VARCHAR(100) NOT NULL,
    `started_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `finished_at`  DATETIME NULL,
    `status`       ENUM('running','success','partial','error') NOT NULL DEFAULT 'running',
    `records_processed` INT UNSIGNED NOT NULL DEFAULT 0,
    `records_updated`   INT UNSIGNED NOT NULL DEFAULT 0,
    `details_json` JSON NULL,
    INDEX `idx_drl_source` (`source_name`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- SARA 313 / TRI Chemical List
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sara313_list` (
    `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `cas_number`          VARCHAR(20) NOT NULL,
    `chemical_name`       VARCHAR(300) NOT NULL DEFAULT '',
    `category_code`       VARCHAR(50) NULL COMMENT 'Category for threshold determination',
    `deminimis_pct`       DECIMAL(8,4) NOT NULL DEFAULT 1.0 COMMENT 'De minimis threshold percent',
    `is_pbt`              TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Persistent bioaccumulative toxic',
    `pbt_threshold_pct`   DECIMAL(8,4) NULL COMMENT 'Lower threshold for PBT chemicals',
    `source_ref`          VARCHAR(500) NULL,
    `last_updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX `idx_sara_cas` (`cas_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Exempt VOC Library — admin-managed
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `exempt_voc_list` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `cas_number`    VARCHAR(20) NOT NULL UNIQUE,
    `chemical_name` VARCHAR(300) NOT NULL DEFAULT '',
    `regulation_ref` VARCHAR(500) NULL COMMENT 'e.g. 40 CFR 51.100(s)',
    `notes`         TEXT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Competent Person Determinations
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `competent_person_determinations` (
    `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `cas_number`          VARCHAR(20) NOT NULL,
    `jurisdiction`        VARCHAR(50) NOT NULL DEFAULT 'US',
    `determination_json`  JSON NOT NULL COMMENT 'Structured hazard determination',
    `rationale_text`      TEXT NOT NULL,
    `is_active`           TINYINT(1) NOT NULL DEFAULT 1,
    `created_by`          INT UNSIGNED NULL,
    `approved_by`         INT UNSIGNED NULL,
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_cpd_cas` (`cas_number`),
    CONSTRAINT `fk_cpd_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_cpd_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- SDS Versions — draft + published snapshots
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sds_versions` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `finished_good_id` INT UNSIGNED NOT NULL,
    `language`         VARCHAR(5) NOT NULL DEFAULT 'en',
    `version`          INT NOT NULL DEFAULT 1,
    `status`           ENUM('draft','published') NOT NULL DEFAULT 'draft',
    `effective_date`   DATE NULL,
    `published_by`     INT UNSIGNED NULL,
    `published_at`     DATETIME NULL,
    `snapshot_json`    JSON NULL COMMENT 'Immutable snapshot of all data at publish time',
    `pdf_path`         VARCHAR(500) NULL,
    `change_summary`   TEXT NULL,
    `is_deleted`       TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Soft delete (admin only)',
    `deleted_by`       INT UNSIGNED NULL,
    `deleted_at`       DATETIME NULL,
    `created_by`       INT UNSIGNED NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_sv_fg_status` (`finished_good_id`, `status`, `language`, `published_at`),
    INDEX `idx_sv_latest` (`finished_good_id`, `language`, `status`, `version` DESC),
    CONSTRAINT `fk_sv_fg` FOREIGN KEY (`finished_good_id`) REFERENCES `finished_goods`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_sv_published_by` FOREIGN KEY (`published_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_sv_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Text Overrides — per-section, per-language custom text
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `text_overrides` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `sds_version_id`  INT UNSIGNED NULL COMMENT 'NULL = product-level default',
    `finished_good_id` INT UNSIGNED NULL,
    `section_number`  INT NOT NULL,
    `field_key`       VARCHAR(100) NOT NULL,
    `language`        VARCHAR(5) NOT NULL DEFAULT 'en',
    `override_text`   TEXT NOT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_to_sds` (`sds_version_id`),
    INDEX `idx_to_fg` (`finished_good_id`, `section_number`, `language`),
    CONSTRAINT `fk_to_sds` FOREIGN KEY (`sds_version_id`) REFERENCES `sds_versions`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_to_fg` FOREIGN KEY (`finished_good_id`) REFERENCES `finished_goods`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- SDS Generation Trace — full decision trace
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sds_generation_trace` (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `sds_version_id`  INT UNSIGNED NOT NULL,
    `trace_json`      JSON NOT NULL COMMENT 'Full hazard decision trace',
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_sgt_sds` FOREIGN KEY (`sds_version_id`) REFERENCES `sds_versions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- DOT Transport Info Cache
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `dot_transport_info` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `cas_number`    VARCHAR(20) NOT NULL,
    `un_number`     VARCHAR(10) NULL,
    `proper_shipping_name` VARCHAR(500) NULL,
    `hazard_class`  VARCHAR(50) NULL,
    `packing_group` VARCHAR(10) NULL,
    `source_ref`    VARCHAR(500) NULL,
    `retrieved_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_dti_cas` (`cas_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Settings — key/value for admin-configurable parameters
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
    `key`        VARCHAR(100) NOT NULL PRIMARY KEY,
    `value`      TEXT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Schema version tracking
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `version`    VARCHAR(50) NOT NULL PRIMARY KEY,
    `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`version`) VALUES ('001_create_schema');

SET FOREIGN_KEY_CHECKS = 1;
