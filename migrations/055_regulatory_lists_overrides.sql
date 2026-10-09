-- 055: SDS content audit — track T3 (regulatory lists / overrides)
SET NAMES utf8mb4;

-- ============================================================
-- #29 TSCA — EPA public inventory + per-CAS override (track T3)
-- Re-runnable: CREATE TABLE IF NOT EXISTS + INFORMATION_SCHEMA guards
-- (045/046 pattern). No seed rows: the inventory is loaded by
-- scripts/import-tsca-inventory.php from the EPA CSV.
-- ============================================================

-- 29a. tsca_inventory — the EPA non-confidential TSCA Inventory (CAS-keyed).
--      Rows saved through /tsca carry source_ref='manual' and survive imports.
CREATE TABLE IF NOT EXISTS `tsca_inventory` (
    `cas_number`          VARCHAR(20)  NOT NULL PRIMARY KEY,
    `chemical_name`       VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'EPA CA Index Name (or operator-entered name)',
    `is_active_inventory` TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 = EPA ACTIVITY column ACTIVE; 0 = INACTIVE (still on the inventory)',
    `flags`               VARCHAR(50)  NULL COMMENT 'EPA FLAG column verbatim (e.g. S, XU, T, P, Y1, Y2)',
    `source_ref`          VARCHAR(100) NULL COMMENT 'manual = saved through /tsca (importer preserves); EPA = scripts/import-tsca-inventory.php',
    `source_version`      VARCHAR(100) NULL COMMENT 'Importer --version label (e.g. TSCAINV_022025) or CSV basename',
    `imported_at`         DATETIME     NULL,
    `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_tsca_name`   (`chemical_name`(100)),
    INDEX `idx_tsca_source` (`source_ref`, `source_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 29b. cas_master.tsca_status — per-CAS override edited on /determinations (TSCA Review tab).
--      'auto' = resolve from tsca_inventory; listed/exempt/not_listed = operator decision.
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cas_master' AND COLUMN_NAME = 'tsca_status');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `cas_master` ADD COLUMN `tsca_status` ENUM(''auto'',''listed'',''exempt'',''not_listed'') NOT NULL DEFAULT ''auto'' COMMENT ''Audit #29 per-CAS TSCA override; auto = resolve from tsca_inventory'' AFTER `last_resolved_at`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 29c. cas_master.tsca_note — required when tsca_status <> 'auto' (e.g. "crossover CAS 1234-56-7 listed", "polymer exemption 40 CFR 723.250").
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cas_master' AND COLUMN_NAME = 'tsca_note');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `cas_master` ADD COLUMN `tsca_note` VARCHAR(500) NULL COMMENT ''Audit #29: basis for the TSCA override'' AFTER `tsca_status`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 29d. cas_master.tsca_updated_by — users.id of the operator who last set the override (no FK: cas_master has none today).
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cas_master' AND COLUMN_NAME = 'tsca_updated_by');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `cas_master` ADD COLUMN `tsca_updated_by` INT UNSIGNED NULL AFTER `tsca_note`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 29e. cas_master.tsca_updated_at
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cas_master' AND COLUMN_NAME = 'tsca_updated_at');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `cas_master` ADD COLUMN `tsca_updated_at` DATETIME NULL AFTER `tsca_updated_by`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 29f. Index for the "overrides in effect" listing.
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cas_master' AND INDEX_NAME = 'idx_cas_master_tsca_status');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `cas_master` ADD INDEX `idx_cas_master_tsca_status` (`tsca_status`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- #26 EPA RCRA waste codes for Section 13 (track T3)
--
-- Re-runnable: CREATE TABLE IF NOT EXISTS + INSERT IGNORE against the
-- (cas_number, waste_code) unique key (045/046 guard discipline for a new
-- table). One row per CAS + code — a CAS can carry a toxicity
-- characteristic code (D004–D043, with its TCLP regulatory level in mg/L)
-- and one or more listed-waste codes (F / K / P / U). D001–D003 are NOT
-- rows: SDSGenerator::section13() derives them from the formula flash
-- point and the engine's H-codes. Seeds carry their CFR citation in
-- source_ref (never 'manual'); rows saved through /rcra are 'manual'.
-- ============================================================

CREATE TABLE IF NOT EXISTS `rcra_waste_codes` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `cas_number`      VARCHAR(20)  NOT NULL,
    `waste_code`      VARCHAR(10)  NOT NULL COMMENT 'D004-D043, Fxxx, Kxxx, Pxxx, Uxxx',
    `description`     VARCHAR(400) NOT NULL DEFAULT '' COMMENT 'Chemical / waste name as listed in 40 CFR 261 (admin label only)',
    `kind`            ENUM('D','F','K','P','U') NOT NULL COMMENT 'D = toxicity characteristic (261.24); F/K = listed (261.31/261.32); P/U = discarded commercial chemical products (261.33)',
    `limit_mg_l`      DECIMAL(10,3) NULL COMMENT 'TCLP regulatory level (mg/L) for D-codes; NULL for F/K/P/U',
    `source_ref`      VARCHAR(500) NULL COMMENT 'CFR citation for seeds; ''manual'' for rows saved via /rcra',
    `last_updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_rcra_cas_code` (`cas_number`, `waste_code`),
    KEY `idx_rcra_cas`  (`cas_number`),
    KEY `idx_rcra_code` (`waste_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 26a. Toxicity characteristic constituents — 40 CFR 261.24 Table 1 (40 rows)
-- ------------------------------------------------------------
INSERT IGNORE INTO `rcra_waste_codes` (`cas_number`, `waste_code`, `description`, `kind`, `limit_mg_l`, `source_ref`) VALUES
('7440-38-2', 'D004', 'Arsenic',                          'D',   5.000, '40 CFR 261.24 Table 1'),
('7440-39-3', 'D005', 'Barium',                           'D', 100.000, '40 CFR 261.24 Table 1'),
('7440-43-9', 'D006', 'Cadmium',                          'D',   1.000, '40 CFR 261.24 Table 1'),
('7440-47-3', 'D007', 'Chromium',                         'D',   5.000, '40 CFR 261.24 Table 1'),
('7439-92-1', 'D008', 'Lead',                             'D',   5.000, '40 CFR 261.24 Table 1'),
('7439-97-6', 'D009', 'Mercury',                          'D',   0.200, '40 CFR 261.24 Table 1'),
('7782-49-2', 'D010', 'Selenium',                         'D',   1.000, '40 CFR 261.24 Table 1'),
('7440-22-4', 'D011', 'Silver',                           'D',   5.000, '40 CFR 261.24 Table 1'),
('72-20-8',   'D012', 'Endrin',                           'D',   0.020, '40 CFR 261.24 Table 1'),
('58-89-9',   'D013', 'Lindane',                          'D',   0.400, '40 CFR 261.24 Table 1'),
('72-43-5',   'D014', 'Methoxychlor',                     'D',  10.000, '40 CFR 261.24 Table 1'),
('8001-35-2', 'D015', 'Toxaphene',                        'D',   0.500, '40 CFR 261.24 Table 1'),
('94-75-7',   'D016', '2,4-D',                            'D',  10.000, '40 CFR 261.24 Table 1'),
('93-72-1',   'D017', '2,4,5-TP (Silvex)',                'D',   1.000, '40 CFR 261.24 Table 1'),
('71-43-2',   'D018', 'Benzene',                          'D',   0.500, '40 CFR 261.24 Table 1'),
('56-23-5',   'D019', 'Carbon tetrachloride',             'D',   0.500, '40 CFR 261.24 Table 1'),
('57-74-9',   'D020', 'Chlordane',                        'D',   0.030, '40 CFR 261.24 Table 1'),
('108-90-7',  'D021', 'Chlorobenzene',                    'D', 100.000, '40 CFR 261.24 Table 1'),
('67-66-3',   'D022', 'Chloroform',                       'D',   6.000, '40 CFR 261.24 Table 1'),
('95-48-7',   'D023', 'o-Cresol',                         'D', 200.000, '40 CFR 261.24 Table 1'),
('108-39-4',  'D024', 'm-Cresol',                         'D', 200.000, '40 CFR 261.24 Table 1'),
('106-44-5',  'D025', 'p-Cresol',                         'D', 200.000, '40 CFR 261.24 Table 1'),
('1319-77-3', 'D026', 'Cresol (total)',                   'D', 200.000, '40 CFR 261.24 Table 1'),
('106-46-7',  'D027', '1,4-Dichlorobenzene',              'D',   7.500, '40 CFR 261.24 Table 1'),
('107-06-2',  'D028', '1,2-Dichloroethane',               'D',   0.500, '40 CFR 261.24 Table 1'),
('75-35-4',   'D029', '1,1-Dichloroethylene',             'D',   0.700, '40 CFR 261.24 Table 1'),
('121-14-2',  'D030', '2,4-Dinitrotoluene',               'D',   0.130, '40 CFR 261.24 Table 1'),
('76-44-8',   'D031', 'Heptachlor (and its epoxide)',     'D',   0.008, '40 CFR 261.24 Table 1'),
('118-74-1',  'D032', 'Hexachlorobenzene',                'D',   0.130, '40 CFR 261.24 Table 1'),
('87-68-3',   'D033', 'Hexachlorobutadiene',              'D',   0.500, '40 CFR 261.24 Table 1'),
('67-72-1',   'D034', 'Hexachloroethane',                 'D',   3.000, '40 CFR 261.24 Table 1'),
('78-93-3',   'D035', 'Methyl ethyl ketone',              'D', 200.000, '40 CFR 261.24 Table 1'),
('98-95-3',   'D036', 'Nitrobenzene',                     'D',   2.000, '40 CFR 261.24 Table 1'),
('87-86-5',   'D037', 'Pentachlorophenol',                'D', 100.000, '40 CFR 261.24 Table 1'),
('110-86-1',  'D038', 'Pyridine',                         'D',   5.000, '40 CFR 261.24 Table 1'),
('127-18-4',  'D039', 'Tetrachloroethylene',              'D',   0.700, '40 CFR 261.24 Table 1'),
('79-01-6',   'D040', 'Trichloroethylene',                'D',   0.500, '40 CFR 261.24 Table 1'),
('95-95-4',   'D041', '2,4,5-Trichlorophenol',            'D', 400.000, '40 CFR 261.24 Table 1'),
('88-06-2',   'D042', '2,4,6-Trichlorophenol',            'D',   2.000, '40 CFR 261.24 Table 1'),
('75-01-4',   'D043', 'Vinyl chloride',                   'D',   0.200, '40 CFR 261.24 Table 1');

-- ------------------------------------------------------------
-- 26b. Discarded commercial chemical products — U list, 40 CFR 261.33(f)
--      (common ink / coating solvents, monomers and chlorinated solvents)
-- ------------------------------------------------------------
INSERT IGNORE INTO `rcra_waste_codes` (`cas_number`, `waste_code`, `description`, `kind`, `limit_mg_l`, `source_ref`) VALUES
('108-88-3',  'U220', 'Toluene',                                        'U', NULL, '40 CFR 261.33(f)'),
('78-93-3',   'U159', 'Methyl ethyl ketone (2-Butanone)',               'U', NULL, '40 CFR 261.33(f)'),
('1330-20-7', 'U239', 'Xylene (mixed isomers)',                         'U', NULL, '40 CFR 261.33(f)'),
('67-56-1',   'U154', 'Methanol',                                       'U', NULL, '40 CFR 261.33(f)'),
('67-64-1',   'U002', 'Acetone',                                        'U', NULL, '40 CFR 261.33(f)'),
('141-78-6',  'U112', 'Ethyl acetate',                                  'U', NULL, '40 CFR 261.33(f)'),
('108-10-1',  'U161', 'Methyl isobutyl ketone (4-Methyl-2-pentanone)',  'U', NULL, '40 CFR 261.33(f)'),
('71-36-3',   'U031', 'n-Butyl alcohol',                                'U', NULL, '40 CFR 261.33(f)'),
('108-94-1',  'U057', 'Cyclohexanone',                                  'U', NULL, '40 CFR 261.33(f)'),
('110-82-7',  'U056', 'Cyclohexane',                                    'U', NULL, '40 CFR 261.33(f)'),
('110-80-5',  'U359', '2-Ethoxyethanol (ethylene glycol monoethyl ether)', 'U', NULL, '40 CFR 261.33(f)'),
('60-29-7',   'U117', 'Ethyl ether',                                    'U', NULL, '40 CFR 261.33(f)'),
('109-99-9',  'U213', 'Tetrahydrofuran',                                'U', NULL, '40 CFR 261.33(f)'),
('123-91-1',  'U108', '1,4-Dioxane',                                    'U', NULL, '40 CFR 261.33(f)'),
('71-43-2',   'U019', 'Benzene',                                        'U', NULL, '40 CFR 261.33(f)'),
('91-20-3',   'U165', 'Naphthalene',                                    'U', NULL, '40 CFR 261.33(f)'),
('108-95-2',  'U188', 'Phenol',                                         'U', NULL, '40 CFR 261.33(f)'),
('50-00-0',   'U122', 'Formaldehyde',                                   'U', NULL, '40 CFR 261.33(f)'),
('1319-77-3', 'U052', 'Cresols (mixed isomers)',                        'U', NULL, '40 CFR 261.33(f)'),
('140-88-5',  'U113', 'Ethyl acrylate',                                 'U', NULL, '40 CFR 261.33(f)'),
('97-63-2',   'U118', 'Ethyl methacrylate',                             'U', NULL, '40 CFR 261.33(f)'),
('80-62-6',   'U162', 'Methyl methacrylate',                            'U', NULL, '40 CFR 261.33(f)'),
('79-10-7',   'U008', 'Acrylic acid',                                   'U', NULL, '40 CFR 261.33(f)'),
('107-13-1',  'U009', 'Acrylonitrile',                                  'U', NULL, '40 CFR 261.33(f)'),
('75-09-2',   'U080', 'Methylene chloride (dichloromethane)',           'U', NULL, '40 CFR 261.33(f)'),
('127-18-4',  'U210', 'Tetrachloroethylene',                            'U', NULL, '40 CFR 261.33(f)'),
('79-01-6',   'U228', 'Trichloroethylene',                              'U', NULL, '40 CFR 261.33(f)'),
('71-55-6',   'U226', '1,1,1-Trichloroethane',                          'U', NULL, '40 CFR 261.33(f)'),
('56-23-5',   'U211', 'Carbon tetrachloride',                           'U', NULL, '40 CFR 261.33(f)'),
('67-66-3',   'U044', 'Chloroform',                                     'U', NULL, '40 CFR 261.33(f)'),
('108-90-7',  'U037', 'Chlorobenzene',                                  'U', NULL, '40 CFR 261.33(f)'),
('95-50-1',   'U070', 'o-Dichlorobenzene',                              'U', NULL, '40 CFR 261.33(f)'),
('106-46-7',  'U072', 'p-Dichlorobenzene',                              'U', NULL, '40 CFR 261.33(f)'),
('107-06-2',  'U077', '1,2-Dichloroethane',                             'U', NULL, '40 CFR 261.33(f)'),
('75-35-4',   'U078', '1,1-Dichloroethylene',                           'U', NULL, '40 CFR 261.33(f)'),
('121-14-2',  'U105', '2,4-Dinitrotoluene',                             'U', NULL, '40 CFR 261.33(f)'),
('98-95-3',   'U169', 'Nitrobenzene',                                   'U', NULL, '40 CFR 261.33(f)'),
('110-86-1',  'U196', 'Pyridine',                                       'U', NULL, '40 CFR 261.33(f)'),
('75-01-4',   'U043', 'Vinyl chloride',                                 'U', NULL, '40 CFR 261.33(f)');

-- ------------------------------------------------------------
-- 26c. Acutely hazardous discarded commercial chemical products — P list, 40 CFR 261.33(e)
-- ------------------------------------------------------------
INSERT IGNORE INTO `rcra_waste_codes` (`cas_number`, `waste_code`, `description`, `kind`, `limit_mg_l`, `source_ref`) VALUES
('75-15-0',   'P022', 'Carbon disulfide',   'P', NULL, '40 CFR 261.33(e)'),
('107-02-8',  'P003', 'Acrolein',           'P', NULL, '40 CFR 261.33(e)'),
('107-18-6',  'P005', 'Allyl alcohol',      'P', NULL, '40 CFR 261.33(e)'),
('624-83-9',  'P064', 'Methyl isocyanate',  'P', NULL, '40 CFR 261.33(e)');

-- ------------------------------------------------------------
-- 26d. Spent solvent listings — F list, 40 CFR 261.31
--      F003 non-halogenated; F005 non-halogenated (toxic); F001/F002 halogenated
-- ------------------------------------------------------------
INSERT IGNORE INTO `rcra_waste_codes` (`cas_number`, `waste_code`, `description`, `kind`, `limit_mg_l`, `source_ref`) VALUES
('1330-20-7', 'F003', 'Xylene — spent non-halogenated solvent',                 'F', NULL, '40 CFR 261.31'),
('67-64-1',   'F003', 'Acetone — spent non-halogenated solvent',                'F', NULL, '40 CFR 261.31'),
('141-78-6',  'F003', 'Ethyl acetate — spent non-halogenated solvent',          'F', NULL, '40 CFR 261.31'),
('100-41-4',  'F003', 'Ethyl benzene — spent non-halogenated solvent',          'F', NULL, '40 CFR 261.31'),
('60-29-7',   'F003', 'Ethyl ether — spent non-halogenated solvent',            'F', NULL, '40 CFR 261.31'),
('108-10-1',  'F003', 'Methyl isobutyl ketone — spent non-halogenated solvent', 'F', NULL, '40 CFR 261.31'),
('71-36-3',   'F003', 'n-Butyl alcohol — spent non-halogenated solvent',        'F', NULL, '40 CFR 261.31'),
('108-94-1',  'F003', 'Cyclohexanone — spent non-halogenated solvent',          'F', NULL, '40 CFR 261.31'),
('67-56-1',   'F003', 'Methanol — spent non-halogenated solvent',               'F', NULL, '40 CFR 261.31'),
('108-88-3',  'F005', 'Toluene — spent non-halogenated solvent',                'F', NULL, '40 CFR 261.31'),
('78-93-3',   'F005', 'Methyl ethyl ketone — spent non-halogenated solvent',    'F', NULL, '40 CFR 261.31'),
('75-15-0',   'F005', 'Carbon disulfide — spent non-halogenated solvent',       'F', NULL, '40 CFR 261.31'),
('78-83-1',   'F005', 'Isobutanol — spent non-halogenated solvent',             'F', NULL, '40 CFR 261.31'),
('110-86-1',  'F005', 'Pyridine — spent non-halogenated solvent',               'F', NULL, '40 CFR 261.31'),
('71-43-2',   'F005', 'Benzene — spent non-halogenated solvent',                'F', NULL, '40 CFR 261.31'),
('110-80-5',  'F005', '2-Ethoxyethanol — spent non-halogenated solvent',        'F', NULL, '40 CFR 261.31'),
('79-46-9',   'F005', '2-Nitropropane — spent non-halogenated solvent',         'F', NULL, '40 CFR 261.31'),
('127-18-4',  'F001', 'Tetrachloroethylene — spent halogenated degreasing solvent',   'F', NULL, '40 CFR 261.31'),
('79-01-6',   'F001', 'Trichloroethylene — spent halogenated degreasing solvent',     'F', NULL, '40 CFR 261.31'),
('75-09-2',   'F001', 'Methylene chloride — spent halogenated degreasing solvent',    'F', NULL, '40 CFR 261.31'),
('71-55-6',   'F001', '1,1,1-Trichloroethane — spent halogenated degreasing solvent', 'F', NULL, '40 CFR 261.31'),
('56-23-5',   'F001', 'Carbon tetrachloride — spent halogenated degreasing solvent',  'F', NULL, '40 CFR 261.31'),
('127-18-4',  'F002', 'Tetrachloroethylene — spent halogenated solvent',        'F', NULL, '40 CFR 261.31'),
('79-01-6',   'F002', 'Trichloroethylene — spent halogenated solvent',          'F', NULL, '40 CFR 261.31'),
('75-09-2',   'F002', 'Methylene chloride — spent halogenated solvent',         'F', NULL, '40 CFR 261.31'),
('71-55-6',   'F002', '1,1,1-Trichloroethane — spent halogenated solvent',      'F', NULL, '40 CFR 261.31'),
('108-90-7',  'F002', 'Chlorobenzene — spent halogenated solvent',              'F', NULL, '40 CFR 261.31');

-- ------------------------------------------------------------
-- #40 housekeeping: dead settings and mis-prefixed seed keys
-- ------------------------------------------------------------
-- Pure DML on `settings` (PRIMARY KEY `key`); re-runnable.

-- 40a. sds.voc_calc_mode is read nowhere (the second VOC line is gone, #18):
--      drop the admin-saved row and the legacy un-prefixed seed row.
DELETE FROM `settings` WHERE `key` IN ('sds.voc_calc_mode', 'voc_calc_mode');

-- 40b. seeds/seed.php used to write un-prefixed keys nothing reads. Move the
--      two that have a real counterpart (only when the real key is absent —
--      an admin-saved value always wins), then delete the legacy rows.
INSERT IGNORE INTO `settings` (`key`, `value`)
SELECT 'company.name', s.`value`
  FROM `settings` s
 WHERE s.`key` = 'company_name'
   AND TRIM(COALESCE(s.`value`, '')) <> '';

INSERT IGNORE INTO `settings` (`key`, `value`)
SELECT 'sds.block_publish_missing', s.`value`
  FROM `settings` s
 WHERE s.`key` = 'sds_block_publish_missing'
   AND s.`value` IN ('0', '1');

DELETE FROM `settings`
 WHERE `key` IN ('company_name', 'sds_block_publish_missing', 'source_priority', 'sara_deminimis_default');

-- 40c. Seed the three gate/toggle settings that now have admin UI so the
--      settings page shows stored state. Values equal the code defaults
--      (SDSReadinessService / SDSController / UVAcrylateRulePack treat a
--      missing row exactly the same way), so this changes no behaviour.
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES
    ('sds.block_publish_missing', '1'),
    ('sds.missing_threshold_pct', '1.0'),
    ('uv_acrylate_rule_pack', 'enabled');

-- (file footer, exactly once per file)
INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('055_regulatory_lists_overrides');
