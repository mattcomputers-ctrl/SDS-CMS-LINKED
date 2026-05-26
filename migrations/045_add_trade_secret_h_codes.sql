-- Add H codes column to raw_material_constituents for trade secret lines
-- where the vendor provides hazard codes but withholds the chemical identity.

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_material_constituents' AND COLUMN_NAME = 'trade_secret_h_codes');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE raw_material_constituents ADD COLUMN trade_secret_h_codes VARCHAR(500) NULL AFTER trade_secret_description',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Track in schema_migrations
INSERT INTO schema_migrations (`version`) VALUES ('045_add_trade_secret_h_codes') ON DUPLICATE KEY UPDATE `version` = `version`;
