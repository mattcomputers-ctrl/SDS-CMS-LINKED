-- Migration 015: Add prop65_data JSON column to raw_materials
--
-- Replaces the single prop65_chemical_name / prop65_toxicity_types fields
-- with a JSON array that supports multiple Prop 65 chemicals per raw material.
-- Also adds "reproductive" as a non-gender-specific toxicity type.
--
-- The old columns are kept for backward compatibility but the JSON column
-- is the primary source going forward.
--
-- Safe to run multiple times (uses column existence checks).

-- ============================================================
-- raw_materials: prop65_data JSON column
-- ============================================================

SET @colExists = (SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'raw_materials'
      AND COLUMN_NAME  = 'prop65_data');

SET @sql = IF(@colExists = 0,
    'ALTER TABLE raw_materials ADD COLUMN prop65_data JSON DEFAULT NULL AFTER prop65_toxicity_types',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- Migrate existing single-entry data into the new JSON column
-- ============================================================

UPDATE raw_materials
SET prop65_data = JSON_ARRAY(
    JSON_OBJECT(
        'chemical_name', prop65_chemical_name,
        'cas_number', '',
        'toxicity_types', prop65_toxicity_types
    )
)
WHERE is_prop65 = 1
  AND prop65_chemical_name IS NOT NULL
  AND prop65_chemical_name != ''
  AND (prop65_data IS NULL OR JSON_LENGTH(prop65_data) = 0);

-- ============================================================
-- Record this migration
-- ============================================================

INSERT IGNORE INTO schema_migrations (version, applied_at)
VALUES ('015_add_prop65_data_json', NOW());
