-- ============================================================
-- Migration 050: Per-supplier raw material SDSs.
--
-- Materials are often sourced from several suppliers, each with its
-- own SDS. Previously raw_material_sds rows carried no supplier and
-- the RM had a single supplier_sds_path / sds_last_confirmed_at, so
-- "current SDS" could only mean one file per material.
--
--   raw_material_sds.supplier
--     Free-text supplier name entered at upload. The current SDS is
--     now the newest upload PER supplier. Backfilled from the RM's
--     CMS-populated supplier for existing rows — the best available
--     signal, since most materials had one vendor when those were
--     uploaded. Rows that still end up NULL show as "(supplier not
--     recorded)" in the UI and can be corrected by re-uploading.
--
--   raw_material_sds.sds_last_confirmed_at
--     Per-row "supplier confirmed this is still current" date, so the
--     Supplier Confirmed Current action can be taken per supplier.
--     raw_materials.sds_last_confirmed_at remains the MAX across the
--     RM's rows for RM-level consumers (Stale RM SDS summary, etc.).
-- ============================================================

ALTER TABLE `raw_material_sds`
    ADD COLUMN `supplier` VARCHAR(200) NULL
        COMMENT 'Supplier this SDS came from (entered at upload); current SDS = newest per supplier'
        AFTER `raw_material_id`,
    ADD COLUMN `sds_last_confirmed_at` DATE NULL
        COMMENT 'Last date this supplier confirmed this SDS is still current'
        AFTER `sds_date_received`,
    ADD INDEX `idx_rmsds_rm_supplier` (`raw_material_id`, `supplier`);

-- Backfill supplier from the RM's (CMS-populated) supplier.
UPDATE `raw_material_sds` s
  JOIN `raw_materials` rm ON rm.id = s.raw_material_id
   SET s.`supplier` = NULLIF(TRIM(rm.`supplier`), '')
 WHERE s.`supplier` IS NULL;

-- The newest row per RM inherits the RM-level confirmed date (that is
-- what it referred to); older rows fall back to their own received date.
UPDATE `raw_material_sds` s
  JOIN (
        SELECT raw_material_id, MAX(id) AS max_id
          FROM raw_material_sds
         GROUP BY raw_material_id
       ) n ON n.max_id = s.id
  JOIN `raw_materials` rm ON rm.id = s.raw_material_id
   SET s.`sds_last_confirmed_at` = COALESCE(rm.`sds_last_confirmed_at`, s.`sds_date_received`, DATE(s.`uploaded_at`))
 WHERE s.`sds_last_confirmed_at` IS NULL;

UPDATE `raw_material_sds`
   SET `sds_last_confirmed_at` = COALESCE(`sds_date_received`, DATE(`uploaded_at`))
 WHERE `sds_last_confirmed_at` IS NULL;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('050_add_rm_sds_supplier');
