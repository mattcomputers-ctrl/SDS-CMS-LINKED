-- Add H codes column to raw_material_constituents for trade secret lines
-- where the vendor provides hazard codes but withholds the chemical identity.

ALTER TABLE raw_material_constituents
    ADD COLUMN trade_secret_h_codes VARCHAR(500) NULL AFTER trade_secret_description;

-- Track in schema_migrations
INSERT INTO schema_migrations (migration) VALUES ('045_add_trade_secret_h_codes') ON DUPLICATE KEY UPDATE migration = migration;
