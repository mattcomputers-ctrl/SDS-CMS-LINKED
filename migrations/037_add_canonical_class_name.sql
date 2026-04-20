-- ============================================================
-- Migration 037: Canonical hazard class name + category columns.
--
-- Phase 1 of the Hazard Engine refactor (see docs/hazard-engine-refactor-plan.md).
--
--   hazard_classifications.class_name_canonical
--     Lowercase snake_case identifier matching a GHSHazardClass::* constant
--     (e.g. 'carcinogenicity', 'skin_corrosion_irritation'). Populated by the
--     scripts/backfill-canonical-class-names.php Phase 1 backfill; populated
--     by the CMS/federal-data import pipeline going forward.
--
--   hazard_classifications.category_canonical
--     Canonical form of the category string: 'Cat 1', 'Cat 2A',
--     'Division 1.1', 'Type A', 'Compressed gas', 'Lactation', etc. Produced
--     by HazardClassAliases::normalizeCategory() so fuzzy case/punctuation
--     variants collapse to a single form.
--
-- Both columns are nullable until the backfill completes — the Phase 1
-- engine refactor falls back to runtime normalisation when either column
-- is NULL, so generation continues uninterrupted mid-backfill.
--
-- Rollback: ALTER TABLE hazard_classifications
--             DROP INDEX idx_hc_canonical,
--             DROP COLUMN class_name_canonical,
--             DROP COLUMN category_canonical;
-- ============================================================

ALTER TABLE `hazard_classifications`
    ADD COLUMN `class_name_canonical` VARCHAR(100) NULL
        COMMENT 'GHSHazardClass canonical code — lowercase snake_case (e.g. carcinogenicity). Backfilled by scripts/backfill-canonical-class-names.php'
        AFTER `class_name`,
    ADD COLUMN `category_canonical` VARCHAR(50) NULL
        COMMENT 'Normalised category (Cat 1 / Cat 2A / Division 1.1 / Type A / Compressed gas / etc.)'
        AFTER `category`,
    ADD INDEX `idx_hc_canonical` (`class_name_canonical`, `category_canonical`);

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('037_add_canonical_class_name');
