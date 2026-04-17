-- ============================================================
-- Migration 035: Raw Material SDS staleness tracking.
--
--   raw_material_sds.sds_date_received
--     Immutable "as of" date printed on the supplier SDS or the date
--     the user received it — entered at upload time. Separate from
--     uploaded_at which records when the file hit our system.
--
--   raw_materials.sds_last_confirmed_at
--     The most recent date the supplier confirmed this SDS is still
--     current. Starts equal to the newest upload's sds_date_received;
--     the user can advance it via a "Mark Current" action without
--     uploading a new PDF. Powers the Stale RM SDS page.
-- ============================================================

ALTER TABLE `raw_material_sds`
    ADD COLUMN `sds_date_received` DATE NULL
        COMMENT 'User-entered supplier SDS revision/receipt date (required at upload)'
        AFTER `uploaded_at`;

ALTER TABLE `raw_materials`
    ADD COLUMN `sds_last_confirmed_at` DATE NULL
        COMMENT 'Last date the supplier confirmed this SDS is current (advances via Mark Current button)';

-- Seed the new columns for already-imported data: treat uploaded_at as
-- the best available "received" proxy so existing rows have a baseline.
UPDATE `raw_material_sds`
   SET `sds_date_received` = DATE(`uploaded_at`)
 WHERE `sds_date_received` IS NULL;

UPDATE `raw_materials` rm
  LEFT JOIN (
       SELECT raw_material_id, MAX(sds_date_received) AS max_date
         FROM raw_material_sds
        GROUP BY raw_material_id
  ) newest ON newest.raw_material_id = rm.id
   SET rm.`sds_last_confirmed_at` = newest.max_date
 WHERE rm.`sds_last_confirmed_at` IS NULL
   AND newest.max_date IS NOT NULL;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('035_add_rm_sds_staleness');
