-- Migration 008: Add raw material fields for VOC <1%, flash point "greater than",
-- solubility, non-hazardous CAS, and trade secret descriptions.

-- Raw material level fields
ALTER TABLE raw_materials
    ADD COLUMN voc_less_than_one TINYINT(1) NOT NULL DEFAULT 0 AFTER voc_wt,
    ADD COLUMN flash_point_greater_than TINYINT(1) NOT NULL DEFAULT 0 AFTER flash_point_c,
    ADD COLUMN solubility VARCHAR(50) NULL AFTER physical_state;

-- Constituent level fields
ALTER TABLE raw_material_constituents
    ADD COLUMN is_non_hazardous TINYINT(1) NOT NULL DEFAULT 0 AFTER is_trade_secret,
    ADD COLUMN trade_secret_description VARCHAR(200) NULL AFTER is_non_hazardous;

-- Record migration
INSERT INTO schema_migrations (version, name) VALUES (8, '008_add_raw_material_fields');
