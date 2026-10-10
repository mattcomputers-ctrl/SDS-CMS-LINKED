-- 058: SDS data-sources audit follow-up — track T3
SET NAMES utf8mb4;

-- ============================================================
-- #17 / owner decision Q5 (follow-up 6): the constituent "Non-Haz"
-- checkbox is removed from the raw-material form and no code reads
-- raw_material_constituents.is_non_hazardous any more. Section 3 listing
-- follows only the hazard-class / OEL / 0.1 % rules.
--
-- The column is deliberately KEPT and is now UNUSED (TINYINT(1) NOT NULL
-- DEFAULT 0, added by migration 008). Raw-material saves re-insert
-- constituent rows without it, so old flags fall back to 0 over time.
-- Do not drop it in this release. Rows still carrying the old flag:
--   SELECT COUNT(*) FROM raw_material_constituents WHERE is_non_hazardous = 1;
--
-- One-time bump: a product whose formula uses a previously flagged,
-- hazardous or OEL-bearing constituent now prints a new Section 3 row.
-- raw_materials.updated_at is the bulk-publish staleness signal, so every
-- raw material carrying the old flag is bumped with UTC_TIMESTAMP() (same
-- reason as 054). Guarded by a settings marker (not by the file name) so a
-- re-run bumps nothing; the marker holds the number of raws bumped:
--   SELECT `value` FROM `settings` WHERE `key` = 'sds.migration.058.non_hazardous_bump_count';
-- ============================================================

SET @m058_nh_col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'raw_material_constituents'
      AND COLUMN_NAME  = 'is_non_hazardous');
SET @m058_nh_done = (SELECT COUNT(*) FROM `settings`
    WHERE `key` = 'sds.migration.058.non_hazardous_bump_count');
SET @m058_nh_n = 0;

SET @sql = IF(@m058_nh_col = 1 AND @m058_nh_done = 0,
    'SELECT COUNT(DISTINCT `raw_material_id`) INTO @m058_nh_n FROM `raw_material_constituents` WHERE `is_non_hazardous` = 1',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(@m058_nh_col = 1 AND @m058_nh_done = 0,
    'UPDATE `raw_materials` SET `updated_at` = UTC_TIMESTAMP() WHERE `id` IN (SELECT `raw_material_id` FROM `raw_material_constituents` WHERE `is_non_hazardous` = 1)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(@m058_nh_done = 0,
    'INSERT IGNORE INTO `settings` (`key`, `value`) VALUES (''sds.migration.058.non_hazardous_bump_count'', @m058_nh_n)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- T3d — regulatory lists (findings #26, #27, #46, #47, #70)
-- Re-runnable: CREATE TABLE IF NOT EXISTS + INSERT IGNORE on unique keys;
-- the UPDATEs are no-ops on a second run except the final raw_materials
-- bump (harmless: it only re-marks products stale for bulk publish).
-- ============================================================

-- 26a. Compound categories of the SARA 313 (TRI, 40 CFR 372.65(c)) and
--      CAA 112(b) HAP lists whose members are not individual list rows.
CREATE TABLE IF NOT EXISTS `regulatory_categories` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `list_code`      ENUM('sara313','hap') NOT NULL COMMENT 'sara313 = EPCRA 313 / TRI; hap = Clean Air Act 112(b)',
    `category_code`  VARCHAR(20)  NOT NULL COMMENT 'TRI category code (N420 ...) or HAP_* key',
    `category_name`  VARCHAR(200) NOT NULL COMMENT 'Printed list name (Section 15)',
    `element_symbol` VARCHAR(3)   NULL COMMENT 'Member = any CAS whose cas_master Hill formula contains this element; NULL = explicit members only',
    `name_keywords`  VARCHAR(200) NULL COMMENT 'Comma-separated whole words matched against constituent / cas_master names when no molecular formula is on file',
    `deminimis_pct`  DECIMAL(8,4) NULL COMMENT 'SARA 313 de minimis (40 CFR 372.38(a)); NULL for HAP',
    `is_pbt`         TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '40 CFR 372.28 chemical of special concern: reported at any concentration (2023 TRI rule)',
    `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
    `source_ref`     VARCHAR(300) NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_regcat_list_code` (`list_code`, `category_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 26b. Explicit members (include) and carve-outs (exclude) per category.
CREATE TABLE IF NOT EXISTS `regulatory_category_members` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `list_code`     ENUM('sara313','hap') NOT NULL,
    `category_code` VARCHAR(20)  NOT NULL,
    `cas_number`    VARCHAR(20)  NOT NULL,
    `member_type`   ENUM('include','exclude') NOT NULL DEFAULT 'include' COMMENT 'include = explicit member; exclude = carved out of an element category',
    `chemical_name` VARCHAR(300) NOT NULL DEFAULT '',
    `source_ref`    VARCHAR(300) NULL COMMENT 'Citation for seeds; manual for operator rows',
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_regcat_member` (`list_code`, `category_code`, `cas_number`),
    KEY `idx_regcat_member_cas` (`cas_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 26c. SARA 313 / TRI categories. De minimis 0.1 for OSHA-carcinogen
--      categories; chromium compounds seeded at 0.1 (Cr(VI)) conservatively
--      (Cr(III) is 1.0). Lead / mercury compounds are PBT (40 CFR 372.28).
--      Cyanide compounds (N106) not seeded: no element rule fits; add members by hand.
INSERT IGNORE INTO `regulatory_categories` (`list_code`, `category_code`, `category_name`, `element_symbol`, `name_keywords`, `deminimis_pct`, `is_pbt`, `source_ref`) VALUES
('sara313', 'N010', 'Antimony compounds',    'Sb', 'antimony,antimonate',                     1.0, 0, 'EPCRA 313 / TRI category N010; 40 CFR 372.65(c)'),
('sara313', 'N020', 'Arsenic compounds',     'As', 'arsenic,arsenate,arsenite,arsine',        0.1, 0, 'EPCRA 313 / TRI category N020; 40 CFR 372.65(c)'),
('sara313', 'N040', 'Barium compounds',      'Ba', 'barium',                                  1.0, 0, 'EPCRA 313 / TRI category N040 (excludes barium sulfate); 40 CFR 372.65(c)'),
('sara313', 'N050', 'Beryllium compounds',   'Be', 'beryllium',                               0.1, 0, 'EPCRA 313 / TRI category N050; 40 CFR 372.65(c)'),
('sara313', 'N078', 'Cadmium compounds',     'Cd', 'cadmium',                                 0.1, 0, 'EPCRA 313 / TRI category N078; 40 CFR 372.65(c)'),
('sara313', 'N090', 'Chromium compounds',    'Cr', 'chromium,chromate,dichromate,chromic',    0.1, 0, 'EPCRA 313 / TRI category N090 (excludes Transvaal chromite ore); 40 CFR 372.65(c)'),
('sara313', 'N096', 'Cobalt compounds',      'Co', 'cobalt,cobaltous',                        0.1, 0, 'EPCRA 313 / TRI category N096; 40 CFR 372.65(c)'),
('sara313', 'N100', 'Copper compounds',      'Cu', 'copper,cupric,cuprous',                   1.0, 0, 'EPCRA 313 / TRI category N100 (excludes copper phthalocyanines substituted only with H, Cl and/or Br, e.g. C.I. Pigment Blue 15, 15:1, Green 7, Green 36; 60 FR 18350, 1995); 40 CFR 372.65(c)'),
('sara313', 'N420', 'Lead compounds',        'Pb', 'lead,plumbous,plumbic',                   0.1, 1, 'EPCRA 313 / TRI category N420; PBT 40 CFR 372.28'),
('sara313', 'N450', 'Manganese compounds',   'Mn', 'manganese,manganous,permanganate',        1.0, 0, 'EPCRA 313 / TRI category N450; 40 CFR 372.65(c)'),
('sara313', 'N458', 'Mercury compounds',     'Hg', 'mercury,mercuric,mercurous',              0.1, 1, 'EPCRA 313 / TRI category N458; PBT 40 CFR 372.28'),
('sara313', 'N495', 'Nickel compounds',      'Ni', 'nickel',                                  0.1, 0, 'EPCRA 313 / TRI category N495; 40 CFR 372.65(c)'),
('sara313', 'N725', 'Selenium compounds',    'Se', 'selenium,selenite,selenate',              1.0, 0, 'EPCRA 313 / TRI category N725; 40 CFR 372.65(c)'),
('sara313', 'N740', 'Silver compounds',      'Ag', 'silver',                                  1.0, 0, 'EPCRA 313 / TRI category N740; 40 CFR 372.65(c)'),
('sara313', 'N760', 'Thallium compounds',    'Tl', 'thallium',                                1.0, 0, 'EPCRA 313 / TRI category N760; 40 CFR 372.65(c)'),
('sara313', 'N982', 'Zinc compounds',        'Zn', 'zinc',                                    1.0, 0, 'EPCRA 313 / TRI category N982; 40 CFR 372.65(c)'),
('sara313', 'N230', 'Certain glycol ethers', NULL, NULL,                                      1.0, 0, 'EPCRA 313 / TRI category N230 (R-(OCH2CH2)n-OR'', n = 1-3); 40 CFR 372.65(c)');

-- 26d. CAA 112(b) HAP compound categories ("any unique chemical substance that
--      contains the named chemical as part of that chemical's infrastructure").
--      Cyanide compounds, POM, fine mineral fibers, radionuclides, coke oven
--      emissions are not seeded (no element rule fits; add members by hand).
INSERT IGNORE INTO `regulatory_categories` (`list_code`, `category_code`, `category_name`, `element_symbol`, `name_keywords`, `deminimis_pct`, `is_pbt`, `source_ref`) VALUES
('hap', 'HAP_SB', 'Antimony Compounds',                            'Sb', 'antimony,antimonate',                  NULL, 0, 'CAA 112(b)(1), 42 U.S.C. 7412(b)'),
('hap', 'HAP_AS', 'Arsenic Compounds (inorganic including arsine)', 'As', 'arsenic,arsenate,arsenite,arsine',     NULL, 0, 'CAA 112(b)(1), 42 U.S.C. 7412(b)'),
('hap', 'HAP_BE', 'Beryllium Compounds',                           'Be', 'beryllium',                            NULL, 0, 'CAA 112(b)(1), 42 U.S.C. 7412(b)'),
('hap', 'HAP_CD', 'Cadmium Compounds',                             'Cd', 'cadmium',                              NULL, 0, 'CAA 112(b)(1), 42 U.S.C. 7412(b)'),
('hap', 'HAP_CR', 'Chromium Compounds',                            'Cr', 'chromium,chromate,dichromate,chromic', NULL, 0, 'CAA 112(b)(1), 42 U.S.C. 7412(b)'),
('hap', 'HAP_CO', 'Cobalt Compounds',                              'Co', 'cobalt,cobaltous',                     NULL, 0, 'CAA 112(b)(1), 42 U.S.C. 7412(b)'),
('hap', 'HAP_PB', 'Lead Compounds',                                'Pb', 'lead,plumbous,plumbic',                NULL, 0, 'CAA 112(b)(1), 42 U.S.C. 7412(b)'),
('hap', 'HAP_MN', 'Manganese Compounds',                           'Mn', 'manganese,manganous,permanganate',     NULL, 0, 'CAA 112(b)(1), 42 U.S.C. 7412(b)'),
('hap', 'HAP_HG', 'Mercury Compounds',                             'Hg', 'mercury,mercuric,mercurous',           NULL, 0, 'CAA 112(b)(1), 42 U.S.C. 7412(b)'),
('hap', 'HAP_NI', 'Nickel Compounds',                              'Ni', 'nickel',                               NULL, 0, 'CAA 112(b)(1), 42 U.S.C. 7412(b)'),
('hap', 'HAP_SE', 'Selenium Compounds',                            'Se', 'selenium,selenite,selenate',           NULL, 0, 'CAA 112(b)(1), 42 U.S.C. 7412(b)'),
('hap', 'HAP_GE', 'Glycol ethers',                                 NULL, NULL,                                   NULL, 0, 'CAA 112(b)(1) footnote 5 (R-(OCH2CH2)n-OR'', n = 1-3); EGBE 111-76-2 delisted 69 FR 69320 (2004)');

-- 26e. Exclusions (TRI category definitions).
INSERT IGNORE INTO `regulatory_category_members` (`list_code`, `category_code`, `cas_number`, `member_type`, `chemical_name`, `source_ref`) VALUES
('sara313', 'N040', '7727-43-7',  'exclude', 'Barium sulfate',                 'TRI N040 definition'),
('sara313', 'N100', '147-14-8',   'exclude', 'C.I. Pigment Blue 15',           'TRI N100 definition'),
('sara313', 'N100', '1328-53-6',  'exclude', 'C.I. Pigment Green 7',           'TRI N100 definition'),
('sara313', 'N100', '14302-13-7', 'exclude', 'C.I. Pigment Green 36',          'TRI N100 definition'),
-- 12239-87-1: chlorinated copper phthalocyanine (PB 15:1 / 15:2), H/Cl-only, 1995
-- delisting. RegulatoryCategoryService also excludes every H/Cl/Br-only copper
-- phthalocyanine by structure. CONFIRM WITH REGULATORY STAFF before adding more CAS.
('sara313', 'N100', '12239-87-1', 'exclude', 'C.I. Pigment Blue 15:1 / 15:2 (chlorinated copper phthalocyanine)', '60 FR 18350 (1995), 40 CFR 372.65(c)'),
('sara313', 'N090', '1308-31-2',  'exclude', 'Chromite ore (Transvaal Region)', 'TRI N090 definition');

-- 26f. Glycol ether members (ethylene glycol series, n = 1-3). Propylene
--      glycol ethers (e.g. DPM 34590-94-8) are NOT members. 2-Butoxyethanol
--      (111-76-2) is a TRI N230 member but was delisted as a HAP.
--      CONFIRM WITH REGULATORY STAFF (finding #26).
INSERT IGNORE INTO `regulatory_category_members` (`list_code`, `category_code`, `cas_number`, `member_type`, `chemical_name`, `source_ref`) VALUES
('sara313', 'N230', '111-76-2',  'include', '2-Butoxyethanol',                               'TRI N230'),
('sara313', 'N230', '112-34-5',  'include', 'Diethylene glycol monobutyl ether',             'TRI N230'),
('sara313', 'N230', '111-90-0',  'include', 'Diethylene glycol monoethyl ether',             'TRI N230'),
('sara313', 'N230', '111-77-3',  'include', 'Diethylene glycol monomethyl ether',            'TRI N230'),
('sara313', 'N230', '112-35-6',  'include', 'Triethylene glycol monomethyl ether',           'TRI N230'),
('sara313', 'N230', '112-50-5',  'include', 'Triethylene glycol monoethyl ether',            'TRI N230'),
('sara313', 'N230', '143-22-6',  'include', 'Triethylene glycol monobutyl ether',            'TRI N230'),
('sara313', 'N230', '2807-30-9', 'include', '2-Propoxyethanol',                              'TRI N230'),
('sara313', 'N230', '122-99-6',  'include', '2-Phenoxyethanol',                              'TRI N230'),
('sara313', 'N230', '124-17-4',  'include', 'Diethylene glycol monobutyl ether acetate',     'TRI N230'),
('sara313', 'N230', '112-15-2',  'include', 'Diethylene glycol monoethyl ether acetate',     'TRI N230'),
('sara313', 'N230', '112-07-2',  'include', '2-Butoxyethyl acetate',                         'TRI N230'),
('sara313', 'N230', '109-86-4',  'include', '2-Methoxyethanol',                              'TRI N230'),
('sara313', 'N230', '110-80-5',  'include', '2-Ethoxyethanol',                               'TRI N230'),
('sara313', 'N230', '110-49-6',  'include', '2-Methoxyethyl acetate',                        'TRI N230'),
('sara313', 'N230', '111-15-9',  'include', '2-Ethoxyethyl acetate',                         'TRI N230'),
('sara313', 'N230', '112-25-4',  'include', '2-Hexyloxyethanol',                             'TRI N230'),
('sara313', 'N230', '629-14-1',  'include', '1,2-Diethoxyethane',                            'TRI N230'),
('sara313', 'N230', '110-71-4',  'include', '1,2-Dimethoxyethane',                           'TRI N230'),
('sara313', 'N230', '111-96-6',  'include', 'Diethylene glycol dimethyl ether',              'TRI N230'),
('sara313', 'N230', '112-49-2',  'include', 'Triethylene glycol dimethyl ether',             'TRI N230'),
('hap', 'HAP_GE', '112-34-5',  'include', 'Diethylene glycol monobutyl ether',             'CAA 112(b) glycol ethers'),
('hap', 'HAP_GE', '111-90-0',  'include', 'Diethylene glycol monoethyl ether',             'CAA 112(b) glycol ethers'),
('hap', 'HAP_GE', '111-77-3',  'include', 'Diethylene glycol monomethyl ether',            'CAA 112(b) glycol ethers'),
('hap', 'HAP_GE', '112-35-6',  'include', 'Triethylene glycol monomethyl ether',           'CAA 112(b) glycol ethers'),
('hap', 'HAP_GE', '112-50-5',  'include', 'Triethylene glycol monoethyl ether',            'CAA 112(b) glycol ethers'),
('hap', 'HAP_GE', '143-22-6',  'include', 'Triethylene glycol monobutyl ether',            'CAA 112(b) glycol ethers'),
('hap', 'HAP_GE', '2807-30-9', 'include', '2-Propoxyethanol',                              'CAA 112(b) glycol ethers'),
('hap', 'HAP_GE', '122-99-6',  'include', '2-Phenoxyethanol',                              'CAA 112(b) glycol ethers'),
('hap', 'HAP_GE', '124-17-4',  'include', 'Diethylene glycol monobutyl ether acetate',     'CAA 112(b) glycol ethers'),
('hap', 'HAP_GE', '112-15-2',  'include', 'Diethylene glycol monoethyl ether acetate',     'CAA 112(b) glycol ethers'),
('hap', 'HAP_GE', '112-07-2',  'include', '2-Butoxyethyl acetate',                         'CAA 112(b) glycol ethers'),
('hap', 'HAP_GE', '109-86-4',  'include', '2-Methoxyethanol',                              'CAA 112(b) glycol ethers'),
('hap', 'HAP_GE', '110-80-5',  'include', '2-Ethoxyethanol',                               'CAA 112(b) glycol ethers'),
('hap', 'HAP_GE', '110-49-6',  'include', '2-Methoxyethyl acetate',                        'CAA 112(b) glycol ethers'),
('hap', 'HAP_GE', '111-15-9',  'include', '2-Ethoxyethyl acetate',                         'CAA 112(b) glycol ethers'),
('hap', 'HAP_GE', '112-25-4',  'include', '2-Hexyloxyethanol',                             'CAA 112(b) glycol ethers'),
('hap', 'HAP_GE', '629-14-1',  'include', '1,2-Diethoxyethane',                            'CAA 112(b) glycol ethers'),
('hap', 'HAP_GE', '110-71-4',  'include', '1,2-Dimethoxyethane',                           'CAA 112(b) glycol ethers'),
('hap', 'HAP_GE', '111-96-6',  'include', 'Diethylene glycol dimethyl ether',              'CAA 112(b) glycol ethers'),
('hap', 'HAP_GE', '112-49-2',  'include', 'Triethylene glycol dimethyl ether',             'CAA 112(b) glycol ethers');

-- 26g. PBT chemicals of special concern (40 CFR 372.28) the seed left unflagged.
--      Since the 2023 TRI rule (88 FR 74360) they have no de minimis for supplier
--      notification; SARA313Service reports them at any concentration > 0 and
--      no longer reads sara313_list.pbt_threshold_pct (column kept, unused).
UPDATE `sara313_list`
   SET `is_pbt` = 1
 WHERE `is_pbt` = 0
   AND (`cas_number` IN ('191-24-2', '29082-74-4', '40487-42-1', '79-94-7', '1582-09-8')
        OR `category_code` IN ('N150', 'N270', 'N420', 'N458', 'N590'));

-- 26h. TRI PFAS are chemicals of special concern too (40 CFR 372.28 as amended
--      by the 2023 TRI rule, 88 FR 74360: the NDAA-listed PFAS of 40 CFR
--      372.29). The rule removed the supplier-notification de minimis for
--      EVERY chemical of special concern, not only PBTs, so a water-based ink
--      or coating with a listed fluorosurfactant at 0.05-0.5 % must report it.
--      Separate flag: is_pbt stays the PBT designation (Section 12 names PBTs
--      and Section 15 prints "PBT chemical"; PFAS print "chemical of special
--      concern"). SARA313Service reads is_pbt OR is_special_concern.
--      Explicit CAS list (no name pattern: the seed also holds HCFCs such as
--      1,1-dichloro-1-fluoroethane that are not PFAS): the 202 TRI PFAS rows of
--      storage/data/seed/sara313.csv (EPA Consolidated List of Lists, April
--      2025). CONFIRM WITH REGULATORY STAFF when EPA adds PFAS (each reporting
--      year); new rows: set is_special_concern = 1 by hand or via the CSV's
--      special_concern column.
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sara313_list' AND COLUMN_NAME = 'is_special_concern');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `sara313_list` ADD COLUMN `is_special_concern` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''40 CFR 372.28 chemical of special concern (PBT or TRI PFAS): no de minimis for supplier notification (2023 TRI rule)'' AFTER `is_pbt`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Content change for products using a newly flagged CAS (staleness invariant):
-- bump BEFORE flagging, only rows not yet flagged, so a re-run bumps nothing.
UPDATE `raw_materials` rm
   SET rm.`updated_at` = UTC_TIMESTAMP()
 WHERE EXISTS (SELECT 1 FROM `raw_material_constituents` c
                 JOIN `sara313_list` s ON s.`cas_number` = c.`cas_number`
                WHERE c.`raw_material_id` = rm.`id`
                  AND s.`is_special_concern` = 0
                  AND s.`is_pbt` = 0
                  AND s.`cas_number` IN (
    '82113-65-3', '27905-45-9', '17741-60-5', '34362-49-7', '34395-24-9', '148240-89-5',
    '148240-85-1', '148240-87-3', '1078142-10-5', '68515-62-8', '67906-42-7', '678-39-7',
    '27619-91-6', '865-86-1', '65104-65-6', '68555-76-0', '68957-62-0', '68259-07-4',
    '70225-15-9', '60270-55-5', '335-71-7', '60699-51-6', '68555-75-9', '68259-08-5',
    '70225-16-0', '3871-99-6', '17202-41-4', '65104-67-8', '24448-09-7', '31506-32-8',
    '178094-69-4', '67969-69-1', '61660-12-6', '29081-56-9', '70225-14-8', '68555-74-8',
    '68259-09-6', '70225-17-1', '3872-25-1', '70983-60-7', '38006-74-5', '1078715-61-3',
    '68555-81-7', '67584-58-1', '52166-82-2', '68957-58-4', '68957-55-1', '68957-57-3',
    '68187-47-3', '68758-57-6', '39239-77-5', '25268-77-3', '383-07-3', '423-82-5',
    '376-14-7', '68084-62-8', '68867-60-7', '68298-62-4', '59071-10-2', '67584-57-0',
    '67584-56-9', '150135-57-2', '196316-34-4', '68555-91-9', '68239-43-0', '1996-88-9',
    '2144-54-9', '65104-45-2', '6014-75-1', '4980-53-4', '65605-59-6', '203743-03-7',
    '142636-88-2', '200513-42-4', '68227-96-3', '65605-58-5', '1652-63-7', '27619-97-2',
    '59587-39-2', '425670-75-3', '59587-38-1', '27619-94-9', '2742694-36-4',
    '2738952-61-7', '2744262-09-5', '3030471-22-5', '68391-08-2', '2728655-42-1',
    '97659-47-7', '68188-12-5', '10495-86-0', '3108-42-7', '21615-47-4', '3825-26-1',
    '2816091-53-7', '68187-25-7', '68141-02-6', '67584-42-3', '68156-07-0', '68156-01-4',
    '3107-18-4', '2043-53-0', '118400-71-8', '2043-54-1', '56773-42-3', '65636-35-3',
    '182176-52-9', '65530-64-5', '65530-74-7', '65530-63-4', '72623-77-9', '72968-38-8',
    '178535-23-4', '55910-10-6', '2991-51-7', '67584-62-7', '67584-53-6', '67584-52-5',
    '65510-55-6', '13252-13-6', '62037-80-3', '135228-60-3', '29457-72-5', '90076-65-6',
    '376-27-2', '1691-99-2', '16517-11-6', '335-66-0', '71608-60-1', '377-73-1',
    '375-73-5', '45187-15-3', '45048-62-2', '375-22-4', '335-76-2', '307-55-1', '355-46-4',
    '307-24-4', '375-95-1', '1763-23-1', '335-67-1', '21652-58-4', '507-63-1', '307-35-7',
    '67905-19-5', '422-64-0', '376-06-7', '68412-69-1', '68412-68-0', '74499-44-8',
    '65530-62-3', '65530-70-3', '123171-68-6', '65530-83-8', '65530-69-0', '65530-59-8',
    '65605-56-3', '65605-57-4', '65530-61-2', '95144-12-0', '65530-72-5', '65530-71-4',
    '65605-73-4', '65530-65-6', '65530-66-7', '80010-37-3', '29117-08-6', '68958-61-2',
    '68298-81-7', '68958-60-1', '56372-23-7', '68298-80-6', '65545-80-4', '70983-59-4',
    '37338-48-0', '68259-39-2', '68259-38-1', '68310-17-8', '2966-54-3', '29420-49-3',
    '2795-39-3', '2395-00-8', '238420-80-9', '238420-68-3', '61798-68-3', '83048-65-1',
    '78560-44-8', '125476-71-3', '143372-54-7', '335-93-3', '2218-54-4', '3830-45-3',
    '2923-26-4', '335-95-5', '180582-79-0', '30046-31-2', '97553-95-2', '68140-21-6',
    '68140-18-1', '1078712-88-5', '68140-20-5', '70969-47-0'
                  ));

UPDATE `sara313_list`
   SET `is_special_concern` = 1
 WHERE `is_special_concern` = 0
   AND (`is_pbt` = 1
        OR `cas_number` IN (
    '82113-65-3', '27905-45-9', '17741-60-5', '34362-49-7', '34395-24-9', '148240-89-5',
    '148240-85-1', '148240-87-3', '1078142-10-5', '68515-62-8', '67906-42-7', '678-39-7',
    '27619-91-6', '865-86-1', '65104-65-6', '68555-76-0', '68957-62-0', '68259-07-4',
    '70225-15-9', '60270-55-5', '335-71-7', '60699-51-6', '68555-75-9', '68259-08-5',
    '70225-16-0', '3871-99-6', '17202-41-4', '65104-67-8', '24448-09-7', '31506-32-8',
    '178094-69-4', '67969-69-1', '61660-12-6', '29081-56-9', '70225-14-8', '68555-74-8',
    '68259-09-6', '70225-17-1', '3872-25-1', '70983-60-7', '38006-74-5', '1078715-61-3',
    '68555-81-7', '67584-58-1', '52166-82-2', '68957-58-4', '68957-55-1', '68957-57-3',
    '68187-47-3', '68758-57-6', '39239-77-5', '25268-77-3', '383-07-3', '423-82-5',
    '376-14-7', '68084-62-8', '68867-60-7', '68298-62-4', '59071-10-2', '67584-57-0',
    '67584-56-9', '150135-57-2', '196316-34-4', '68555-91-9', '68239-43-0', '1996-88-9',
    '2144-54-9', '65104-45-2', '6014-75-1', '4980-53-4', '65605-59-6', '203743-03-7',
    '142636-88-2', '200513-42-4', '68227-96-3', '65605-58-5', '1652-63-7', '27619-97-2',
    '59587-39-2', '425670-75-3', '59587-38-1', '27619-94-9', '2742694-36-4',
    '2738952-61-7', '2744262-09-5', '3030471-22-5', '68391-08-2', '2728655-42-1',
    '97659-47-7', '68188-12-5', '10495-86-0', '3108-42-7', '21615-47-4', '3825-26-1',
    '2816091-53-7', '68187-25-7', '68141-02-6', '67584-42-3', '68156-07-0', '68156-01-4',
    '3107-18-4', '2043-53-0', '118400-71-8', '2043-54-1', '56773-42-3', '65636-35-3',
    '182176-52-9', '65530-64-5', '65530-74-7', '65530-63-4', '72623-77-9', '72968-38-8',
    '178535-23-4', '55910-10-6', '2991-51-7', '67584-62-7', '67584-53-6', '67584-52-5',
    '65510-55-6', '13252-13-6', '62037-80-3', '135228-60-3', '29457-72-5', '90076-65-6',
    '376-27-2', '1691-99-2', '16517-11-6', '335-66-0', '71608-60-1', '377-73-1',
    '375-73-5', '45187-15-3', '45048-62-2', '375-22-4', '335-76-2', '307-55-1', '355-46-4',
    '307-24-4', '375-95-1', '1763-23-1', '335-67-1', '21652-58-4', '507-63-1', '307-35-7',
    '67905-19-5', '422-64-0', '376-06-7', '68412-69-1', '68412-68-0', '74499-44-8',
    '65530-62-3', '65530-70-3', '123171-68-6', '65530-83-8', '65530-69-0', '65530-59-8',
    '65605-56-3', '65605-57-4', '65530-61-2', '95144-12-0', '65530-72-5', '65530-71-4',
    '65605-73-4', '65530-65-6', '65530-66-7', '80010-37-3', '29117-08-6', '68958-61-2',
    '68298-81-7', '68958-60-1', '56372-23-7', '68298-80-6', '65545-80-4', '70983-59-4',
    '37338-48-0', '68259-39-2', '68259-38-1', '68310-17-8', '2966-54-3', '29420-49-3',
    '2795-39-3', '2395-00-8', '238420-80-9', '238420-68-3', '61798-68-3', '83048-65-1',
    '78560-44-8', '125476-71-3', '143372-54-7', '335-93-3', '2218-54-4', '3830-45-3',
    '2923-26-4', '335-95-5', '180582-79-0', '30046-31-2', '97553-95-2', '68140-21-6',
    '68140-18-1', '1078712-88-5', '68140-20-5', '70969-47-0'
        ));

-- 47a. Prop 65 list types stored lower-case (code also compares case-insensitively).
UPDATE `prop65_list`
   SET `toxicity_type` = LOWER(`toxicity_type`)
 WHERE BINARY `toxicity_type` <> BINARY LOWER(`toxicity_type`);

-- 47b. Legacy single-entry Prop 65 fields never copied into prop65_data:
--      copy them as an explicit (Override) entry so the typed name/types print.
UPDATE `raw_materials`
   SET `prop65_data` = JSON_ARRAY(JSON_OBJECT(
           'chemical_name', TRIM(`prop65_chemical_name`),
           'cas_number', '',
           'toxicity_types', LOWER(COALESCE(`prop65_toxicity_types`, '')),
           'is_trace', 0,
           'is_override', 1)),
       `updated_at` = UTC_TIMESTAMP()
 WHERE `is_prop65` = 1
   AND TRIM(COALESCE(`prop65_chemical_name`, '')) <> ''
   AND (`prop65_data` IS NULL OR TRIM(`prop65_data`) IN ('', '[]', 'null'));

-- 47c. Migration 015's single name-only copy (blank CAS, no is_override key):
--      mark it Override (SDS code treats any blank-CAS name-only entry the same way).
UPDATE `raw_materials`
   SET `prop65_data` = JSON_SET(`prop65_data`, '$[0].is_override', 1),
       `updated_at`  = UTC_TIMESTAMP()
 WHERE `is_prop65` = 1
   AND `prop65_data` IS NOT NULL
   AND JSON_VALID(`prop65_data`)
   AND JSON_LENGTH(`prop65_data`) = 1
   AND TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`prop65_data`, '$[0].cas_number')), '')) = ''
   AND TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`prop65_data`, '$[0].chemical_name')), '')) <> ''
   AND JSON_EXTRACT(`prop65_data`, '$[0].is_override') IS NULL;

-- Bump (content change, UTC per the staleness invariant): every raw material
-- whose products' printed Section 15 can change through #26 (PBT wording,
-- categories, banded HAP total), #27 (incomplete constituents -> TSCA not
-- verified), #46 (not_listed sentence) or #47 (manual Prop 65 / HAP data).
-- The metal-name test uses a portable word-boundary pattern (MySQL 8 ICU,
-- MariaDB PCRE and older engines all accept it); names compare under the
-- column's case-insensitive collation, formulas under utf8mb4_bin.
UPDATE `raw_materials` rm
   SET rm.`updated_at` = UTC_TIMESTAMP()
 WHERE (rm.`is_prop65` = 1 AND ((rm.`prop65_data` IS NOT NULL AND TRIM(rm.`prop65_data`) NOT IN ('', '[]', 'null'))
                                OR TRIM(COALESCE(rm.`prop65_chemical_name`, '')) <> ''))
    OR (rm.`haps_data` IS NOT NULL AND TRIM(rm.`haps_data`) NOT IN ('', '[]', 'null'))
    OR NOT EXISTS (SELECT 1 FROM `raw_material_constituents` c0 WHERE c0.`raw_material_id` = rm.`id`)
    OR EXISTS (SELECT 1 FROM `raw_material_constituents` c1
                WHERE c1.`raw_material_id` = rm.`id` AND TRIM(COALESCE(c1.`cas_number`, '')) = '')
    OR EXISTS (SELECT 1 FROM `raw_material_constituents` c2
                 JOIN `sara313_list` s ON s.`cas_number` = c2.`cas_number`
                WHERE c2.`raw_material_id` = rm.`id` AND s.`is_pbt` = 1)
    OR EXISTS (SELECT 1 FROM `raw_material_constituents` c3
                 JOIN `hap_list` h ON h.`cas_number` = c3.`cas_number` AND h.`cas_number` <> ''
                WHERE c3.`raw_material_id` = rm.`id`)
    OR EXISTS (SELECT 1 FROM `raw_material_constituents` c4
                 JOIN `regulatory_category_members` m ON m.`cas_number` = c4.`cas_number`
                WHERE c4.`raw_material_id` = rm.`id`)
    OR EXISTS (SELECT 1 FROM `raw_material_constituents` c5
                 JOIN `cas_master` cm ON cm.`cas_number` = c5.`cas_number`
                WHERE c5.`raw_material_id` = rm.`id`
                  AND (cm.`tsca_status` = 'not_listed'
                       OR cm.`molecular_formula` COLLATE utf8mb4_bin REGEXP '(Sb|As|Ba|Be|Cd|Cr|Co|Cu|Pb|Mn|Hg|Ni|Se|Ag|Tl|Zn)([^a-z]|$)'))
    OR EXISTS (SELECT 1 FROM `raw_material_constituents` c6
                WHERE c6.`raw_material_id` = rm.`id`
                  AND c6.`chemical_name` REGEXP '(^|[^a-z])(antimony|arsenic|barium|beryllium|cadmium|chromium|chromate|cobalt|copper|cupric|cuprous|lead|manganese|mercury|nickel|selenium|silver|thallium|zinc)([^a-z]|$)');

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('058_sds_audit_followup_t3');
