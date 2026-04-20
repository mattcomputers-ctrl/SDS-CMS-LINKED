-- ============================================================
-- Migration 038: ATE (Acute Toxicity Estimate) values on
-- hazard_classifications for the Phase 3c acute-toxicity mixture
-- calculation.
--
-- Columns are nullable. When present, they hold the vendor-supplied
-- or federally-sourced LD50/LC50 values for the substance at the
-- given CAS + jurisdiction row. When absent, HazardEngine falls back
-- to the GHS Table 3.1.2 category-default ATE values at runtime.
--
--   ate_oral_mg_kg                    Oral LD50 (rat) in mg/kg
--   ate_dermal_mg_kg                  Dermal LD50 (rat/rabbit) in mg/kg
--   ate_inhalation_vapor_mg_l_4h      Inhalation LC50 for vapours in
--                                     mg/L over 4 hours
--   ate_inhalation_dust_mg_l_4h       Inhalation LC50 for dust/mist in
--                                     mg/L over 4 hours
--   ate_inhalation_gas_ppm_4h         Inhalation LC50 for gases in
--                                     ppmV over 4 hours (included for
--                                     future use — ink/coating catalogs
--                                     rarely need this route)
--
-- CPD-sourced ATE values live on determination_json inside
-- competent_person_determinations and require no schema change
-- (JSON column). The engine reads both sources with identical
-- fallback logic.
--
-- Rollback:
--   ALTER TABLE `hazard_classifications`
--     DROP COLUMN `ate_oral_mg_kg`,
--     DROP COLUMN `ate_dermal_mg_kg`,
--     DROP COLUMN `ate_inhalation_vapor_mg_l_4h`,
--     DROP COLUMN `ate_inhalation_dust_mg_l_4h`,
--     DROP COLUMN `ate_inhalation_gas_ppm_4h`;
-- ============================================================

ALTER TABLE `hazard_classifications`
    ADD COLUMN `ate_oral_mg_kg`              DECIMAL(12,4) NULL
        COMMENT 'Oral LD50 mg/kg — used for ATE mixture calc per GHS 3.1.3.6',
    ADD COLUMN `ate_dermal_mg_kg`            DECIMAL(12,4) NULL
        COMMENT 'Dermal LD50 mg/kg — used for ATE mixture calc per GHS 3.1.3.6',
    ADD COLUMN `ate_inhalation_vapor_mg_l_4h` DECIMAL(12,4) NULL
        COMMENT 'Inhalation LC50 vapour mg/L over 4 h — GHS 3.1.3.6',
    ADD COLUMN `ate_inhalation_dust_mg_l_4h`  DECIMAL(12,4) NULL
        COMMENT 'Inhalation LC50 dust/mist mg/L over 4 h — GHS 3.1.3.6',
    ADD COLUMN `ate_inhalation_gas_ppm_4h`    DECIMAL(12,4) NULL
        COMMENT 'Inhalation LC50 gas ppm over 4 h — reserved, ink/coatings catalogs rarely use this route';

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('038_add_ate_values');
