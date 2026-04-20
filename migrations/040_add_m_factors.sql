-- ============================================================
-- Migration 040: M-factor columns on hazard_classifications for
-- the Phase 4 aquatic-hazard summation logic.
--
-- Per GHS Annex I 4.1.3.4, acutely- or chronically-toxic aquatic
-- substances carry multiplier (M) factors that weight their
-- contribution in the mixture-level summation rule. A substance
-- with M_acute = 100 at 1 % in a mixture counts as if it were
-- at 100 % for the 25 % summation threshold check, so even very
-- small fractions of high-M substances can correctly classify a
-- mixture as aquatic-hazardous.
--
--   m_factor_acute    Acute aquatic M-factor (1, 10, 100, 1 000, …)
--                     per CLP Annex VI Table 3.1. NULL treated as
--                     1.0 by the engine.
--   m_factor_chronic  Chronic aquatic M-factor. NULL treated as 1.0.
--
-- Values are populated from ECHA CLP Annex VI Table 3.1 (harmonised
-- classifications) via an optional import script. Substances not
-- on the harmonised list use the GHS Annex I default of M = 1,
-- which is the fall-through behaviour when both columns are NULL.
-- CPDs may carry their own per-CAS M-factors via determination_json.
--
-- Rollback:
--   ALTER TABLE `hazard_classifications`
--     DROP COLUMN `m_factor_acute`,
--     DROP COLUMN `m_factor_chronic`;
-- ============================================================

ALTER TABLE `hazard_classifications`
    ADD COLUMN `m_factor_acute` DECIMAL(10,4) NULL
        COMMENT 'Aquatic acute M-factor per GHS Annex I 4.1.3.4 / CLP Annex VI Table 3.1. NULL = 1.0.',
    ADD COLUMN `m_factor_chronic` DECIMAL(10,4) NULL
        COMMENT 'Aquatic chronic M-factor per GHS Annex I 4.1.3.4 / CLP Annex VI Table 3.1. NULL = 1.0.';

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('040_add_m_factors');
