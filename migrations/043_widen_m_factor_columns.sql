-- ============================================================
-- Migration 043: Widen m_factor_acute / m_factor_chronic.
--
-- Migration 040 defined these as DECIMAL(10,4), which caps at
-- 999,999.9999. ECHA Annex VI publishes M-factor values up to
-- 10,000,000 for the most ecotoxic substances (GHS allows any power
-- of 10; no upper bound). Re-widen to DECIMAL(14,4) so the ECHA seed
-- imports without SQLSTATE[22003] overflow.
--
-- Existing values are preserved — MySQL copies them across on ALTER.
--
-- Rollback:
--   ALTER TABLE `hazard_classifications`
--     MODIFY COLUMN `m_factor_acute`   DECIMAL(10,4) NULL,
--     MODIFY COLUMN `m_factor_chronic` DECIMAL(10,4) NULL;
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `hazard_classifications`
    MODIFY COLUMN `m_factor_acute`   DECIMAL(14,4) NULL
        COMMENT 'Acute aquatic M-factor (powers of 10; up to 10,000,000 per ECHA)',
    MODIFY COLUMN `m_factor_chronic` DECIMAL(14,4) NULL
        COMMENT 'Chronic aquatic M-factor (powers of 10; up to 10,000,000 per ECHA)';

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('043_widen_m_factor_columns');
