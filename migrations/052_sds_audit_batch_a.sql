-- 052: SDS content audit — batch A (track T3: company / manufacturer / raw material items)
--
-- Re-runnable: every DDL step is guarded by an INFORMATION_SCHEMA check
-- (045/046 pattern) and every seed uses INSERT IGNORE against a PRIMARY KEY.

-- ------------------------------------------------------------
-- #34 Legal disclaimer: per-language admin setting + per-manufacturer override
-- ------------------------------------------------------------
-- 3a. manufacturers.disclaimer_json — {"en": "...", "es": "..."}; a missing or
--     blank language inherits the admin per-language setting (sds.legal_disclaimer.<lang>).
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manufacturers' AND COLUMN_NAME = 'disclaimer_json');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `manufacturers` ADD COLUMN `disclaimer_json` TEXT NULL COMMENT ''Per-language legal disclaimer override for private label SDS: {"en":"...","es":"..."}; NULL/blank language = inherit admin setting sds.legal_disclaimer.<lang>'' AFTER `logo_path`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3b. Seed sds.legal_disclaimer.en from the legacy single setting when it has text ...
INSERT IGNORE INTO `settings` (`key`, `value`)
SELECT 'sds.legal_disclaimer.en', s.`value`
  FROM `settings` s
 WHERE s.`key` = 'sds.legal_disclaimer'
   AND TRIM(COALESCE(s.`value`, '')) <> '';

-- 3c. ... otherwise from the translation-file default (templates/translations/en.php section16.disclaimer).
--     No-op if 3b inserted. es/fr/de are intentionally NOT seeded: blank = translation-file text.
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES (
    'sds.legal_disclaimer.en',
    'The information provided in this Safety Data Sheet is correct to the best of our knowledge at the date of publication. It is intended as a guide for safe handling, use, processing, storage, transportation, disposal, and release. It should not be considered a warranty or quality specification. The information relates only to the specific material designated and may not be valid when used in combination with other materials or in any process.'
);

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('052_sds_audit_batch_a');
