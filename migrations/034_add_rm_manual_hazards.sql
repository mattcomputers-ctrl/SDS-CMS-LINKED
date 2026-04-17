-- ============================================================
-- Migration 034: Raw-material-level manual GHS classifications
-- For trade-secret vendor materials where CAS numbers are not
-- disclosed but GHS hazard classifications are provided.
-- ============================================================

ALTER TABLE `raw_materials`
    ADD COLUMN `hazardous_no_cas`   TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Vendor provides GHS classifications without CAS numbers' AFTER `is_snur`,
    ADD COLUMN `manual_hazard_json` JSON NULL
        COMMENT 'Same structure as competent_person_determinations.determination_json'
        AFTER `hazardous_no_cas`;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('034_add_rm_manual_hazards');
