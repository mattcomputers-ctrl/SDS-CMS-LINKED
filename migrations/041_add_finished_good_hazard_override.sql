-- ============================================================
-- Migration 041: Finished-good hazard override (Phase 5).
--
-- Lets a qualified operator explicitly override the computed
-- classification for a specific finished good — intended for:
--
--   * Rare physical-hazard cases where the engine's conservative
--     flat-1 % "Flammable" fallback over-classifies a product
--     that's been lab-tested non-flammable.
--   * Lab-tested physical-hazard classifications (flash point,
--     aerosol, compressed gas, oxidiser) the engine can't derive
--     from composition alone.
--   * Competent-person corrections when the automated classification
--     doesn't match expert judgement for a specific product.
--
--   hazard_override_json        Override payload. Same shape as a CPD
--                               determination JSON: selected hazard
--                               classes, H-codes, P-codes, pictograms,
--                               signal word, optional physical_properties.
--   hazard_override_mode        ENUM('none','additive','replace'):
--                                 none      — no override, engine output used
--                                             as-is (default)
--                                 additive  — override merged on top of
--                                             computed classification
--                                 replace   — discard computed classification,
--                                             use only the override
--   hazard_override_set_by      User who last set the override
--   hazard_override_set_at      Timestamp of the last override update
--   hazard_override_rationale   Free-text justification (required when mode ≠ none)
--
-- Rollback:
--   ALTER TABLE `finished_goods`
--     DROP FOREIGN KEY `fk_fg_override_by`,
--     DROP COLUMN `hazard_override_json`,
--     DROP COLUMN `hazard_override_mode`,
--     DROP COLUMN `hazard_override_set_by`,
--     DROP COLUMN `hazard_override_set_at`,
--     DROP COLUMN `hazard_override_rationale`;
-- ============================================================

ALTER TABLE `finished_goods`
    ADD COLUMN `hazard_override_json` JSON NULL
        COMMENT 'Override payload — same shape as CPD determination JSON',
    ADD COLUMN `hazard_override_mode` ENUM('none','additive','replace') NOT NULL DEFAULT 'none'
        COMMENT 'Override application mode',
    ADD COLUMN `hazard_override_set_by` INT UNSIGNED NULL,
    ADD COLUMN `hazard_override_set_at` DATETIME NULL,
    ADD COLUMN `hazard_override_rationale` TEXT NULL
        COMMENT 'Competent-person justification — required when mode != none',
    ADD CONSTRAINT `fk_fg_override_by` FOREIGN KEY (`hazard_override_set_by`)
        REFERENCES `users`(`id`) ON DELETE SET NULL;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('041_add_finished_good_hazard_override');
