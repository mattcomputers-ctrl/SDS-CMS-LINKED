-- ============================================================
-- Migration 036: Engine version stamp on sds_generation_trace.
--
-- Phase 0 of the Hazard Engine refactor (see docs/hazard-engine-refactor-plan.md).
--
--   sds_generation_trace.engine_version
--     Short identifier for the HazardEngine ruleset that produced this
--     classification (e.g. 'v1.0-baseline', 'v2.1-summation', 'v2.3-mfactor').
--     Set by SDSGenerator at trace-write time. Lets future audits determine
--     which ruleset produced any given SDS classification, and drives the
--     classify-diff harness's "engine versus data drift" attribution.
--
-- Backfill: existing rows are stamped 'v1.0-baseline' since they were
-- produced by the pre-refactor engine. New trace rows written after this
-- migration will carry whatever SDSGenerator reports (defaults to
-- 'v1.0-baseline' until Phase 1 lands).
-- ============================================================

ALTER TABLE `sds_generation_trace`
    ADD COLUMN `engine_version` VARCHAR(30) NOT NULL DEFAULT 'v1.0-baseline'
        COMMENT 'HazardEngine ruleset identifier — see docs/hazard-engine-refactor-plan.md'
        AFTER `sds_version_id`,
    ADD INDEX `idx_trace_engine_version` (`engine_version`);

-- Stamp existing rows explicitly (the default covers new INSERTs but we
-- want the historical rows to carry an explicit value rather than relying
-- on the column default changing in the future).
UPDATE `sds_generation_trace`
   SET `engine_version` = 'v1.0-baseline'
 WHERE `engine_version` = '' OR `engine_version` IS NULL;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('036_add_engine_version');
