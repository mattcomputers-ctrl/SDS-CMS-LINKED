-- 059: SDS content audit, track T4 (overrides / publish gates).
-- T4a (audit #45, Q12): text_overrides keyed by raw material for resale SDSs.
--
-- Re-runnable: every DDL step is guarded by an INFORMATION_SCHEMA check
-- (045/046 pattern). DDL only: raw_materials.updated_at is not touched and
-- no SDS becomes stale. Later T4 units append their blocks ABOVE the footer.

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- #45 Resale SDS text overrides. A resale sheet has no finished good, so its
-- per-product Section 1-15 edits (incl. Section 14 transport overrides) are
-- keyed by the raw material the sheet is built from. A row carries EITHER
-- finished_good_id OR raw_material_id (enforced by SDSController; no CHECK,
-- older MariaDB/MySQL ignore it). Resale alias sheets use the raw material's rows.
-- ------------------------------------------------------------
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'text_overrides' AND COLUMN_NAME = 'raw_material_id');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `text_overrides` ADD COLUMN `raw_material_id` INT UNSIGNED NULL DEFAULT NULL COMMENT ''Audit #45: resale SDS override (finished_good_id NULL), NULL on finished-good rows'' AFTER `finished_good_id`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'text_overrides' AND INDEX_NAME = 'idx_to_rm');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `text_overrides` ADD INDEX `idx_to_rm` (`raw_material_id`, `section_number`, `language`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'text_overrides'
      AND CONSTRAINT_NAME = 'fk_to_rm' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `text_overrides` ADD CONSTRAINT `fk_to_rm` FOREIGN KEY (`raw_material_id`) REFERENCES `raw_materials`(`id`) ON DELETE CASCADE',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Q12: Section 15 OSHA Status / TSCA Status are no longer editable or read.
-- Their stored rows are deliberately NOT deleted here: the owner reviews the
-- dry run of scripts/cleanup-legacy-overrides.php (pass 2) first.

-- ------------------------------------------------------------
-- T4b #54 — UNIQUE (item_id, language, version) on private_label_sds
-- ------------------------------------------------------------
-- Planned since 051 ("follow-up 052") and never applied. Bulk publish now writes
-- private-label rows only through PrivateLabelPublisher::publishOne (version
-- re-checked inside a transaction, all languages or none), so this index is the
-- backstop: a concurrent duplicate insert fails and rolls back.
-- The index is added only when it is absent AND no duplicate group exists. With
-- duplicates it is NOT added, and the SELECT below reports how many groups block
-- it. Resolve them with scripts/check-pl-duplicates.php, then add the index by
-- hand (docs/post-update-checklist.md, step 3.8b). Legacy rows with item_id NULL
-- never collide: NULLs are distinct in a UNIQUE index.
-- DDL only: raw_materials.updated_at is not touched and no SDS becomes stale.
SET @pl_uq_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'private_label_sds'
      AND INDEX_NAME = 'uq_plsds_item_lang_ver');
SET @pl_dup_groups = (SELECT COUNT(*) FROM (
        SELECT 1
          FROM `private_label_sds`
         WHERE `item_id` IS NOT NULL
         GROUP BY `item_id`, `language`, `version`
        HAVING COUNT(*) > 1
    ) AS d);
SET @sql = IF(@pl_uq_exists = 0 AND @pl_dup_groups = 0,
    'ALTER TABLE `private_label_sds` ADD UNIQUE INDEX `uq_plsds_item_lang_ver` (`item_id`, `language`, `version`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Informational for the deployer: 0 = the index is present (or was just added).
SELECT IF(@pl_uq_exists = 0 AND @pl_dup_groups > 0, @pl_dup_groups, 0)
    AS private_label_duplicate_groups_blocking_unique_index;

-- ------------------------------------------------------------
-- Q13 / #61 follow-up: snapshot of the manual family links migration 053
-- (step 5c) created from the legacy family name. MUST run above the T4c
-- block below: T4c bumps finished_goods.updated_at, and ProductStaleness now
-- bumps it on SDS text / hazard-override edits, so "updated_at <= 053
-- applied_at" no longer identifies these links at click time. The "Reset
-- legacy manual picks to Auto" action (FamilyResolver::
-- legacyManualFinishedGoodIds) reads this table, matched on the same
-- family_id (a family re-picked on the product form is no longer legacy).
-- Filled once: only while 059 is not yet recorded in schema_migrations, so a
-- hand re-run never adds rows. Metadata only: no updated_at is touched.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `fg_legacy_family_picks` (
    `finished_good_id` INT UNSIGNED NOT NULL PRIMARY KEY,
    `family_id`        INT UNSIGNED NOT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_flfp_fg` FOREIGN KEY (`finished_good_id`) REFERENCES `finished_goods`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Q13: manual family links created by migration 053 (snapshot taken by 059)';

SET @m059_done = (SELECT COUNT(*) FROM `schema_migrations` WHERE `version` = '059_sds_audit_batch_t4');
SET @sql = IF(@m059_done = 0,
    'INSERT IGNORE INTO `fg_legacy_family_picks` (`finished_good_id`, `family_id`)
     SELECT fg.`id`, fg.`family_id`
       FROM `finished_goods` fg
       JOIN `schema_migrations` sm ON sm.`version` = ''053_product_families''
      WHERE fg.`family_source` = ''manual'' AND fg.`family_id` IS NOT NULL
        AND fg.`updated_at` <= sm.`applied_at`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- #45 / #58 follow-up: resale SDS text edits mark the resale sheet stale.
-- A resale sheet's override rows are keyed by raw material, and bumping
-- raw_materials.updated_at would republish every product that uses the raw.
-- SDSController::saveEditorPost stamps edited_at (UTC, like published_at)
-- when a resale save changes something; BulkPublishController::
-- computeEligibleResaleItems folds it into the RM's upstream timestamp.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `resale_sds_text_edits` (
    `raw_material_id` INT UNSIGNED NOT NULL PRIMARY KEY,
    `edited_at`       DATETIME NOT NULL COMMENT 'UTC; last resale SDS text edit that changed a stored row',
    CONSTRAINT `fk_rste_rm` FOREIGN KEY (`raw_material_id`) REFERENCES `raw_materials`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit #45/#58: resale SDS staleness signal for text edits';

-- ------------------------------------------------------------
-- T4c / audit #48: legacy "Default Product Use" settings removed.
-- FinishedGoodController::create no longer pre-fills Recommended Use /
-- Restrictions on Use from sds.default_recommended_use /
-- sds.default_restrictions_on_use, and the inputs are gone from Settings.
-- Values the old pre-fill copied onto finished goods are cleared where they
-- still equal the setting (trimmed), so those products print their family
-- text / the translated standard sentence in every language. Products with a
-- published SDS whose sheet changes get one pending SDS Updates row (no
-- duplicate pending row). finished_goods.updated_at is bumped with
-- UTC_TIMESTAMP() on the cleared rows: since audit #58 (ProductStaleness) it is
-- the per-product staleness signal bulk publish reads, so the change is a
-- content change for that product only (its raw materials are not touched).
-- Re-runnable: once the settings rows are deleted nothing matches.
-- (Q13 note, no DDL: product_families.is_active = 0 now also releases manual
-- picks and never prints family text / UV — the 053 column comment
-- "manual overrides pointing at it still print" is superseded.)
-- ------------------------------------------------------------
INSERT INTO `sds_update_queue` (`finished_good_id`, `reason`, `source_type`, `source_id`, `queued_by`)
SELECT fg.`id`, 'Section 1 use text: legacy default product use cleared (audit #48)', 'finished_good', NULL, NULL
  FROM `finished_goods` fg
  LEFT JOIN `settings` sr ON sr.`key` = 'sds.default_recommended_use'
  LEFT JOIN `settings` ss ON ss.`key` = 'sds.default_restrictions_on_use'
 WHERE fg.`is_active` = 1
   AND (   (TRIM(COALESCE(sr.`value`, '')) <> '' AND TRIM(COALESCE(fg.`recommended_use`, '')) = TRIM(sr.`value`))
        OR (TRIM(COALESCE(ss.`value`, '')) <> '' AND TRIM(COALESCE(fg.`restrictions_on_use`, '')) = TRIM(ss.`value`)))
   AND EXISTS (SELECT 1 FROM `sds_versions` sv
                WHERE sv.`finished_good_id` = fg.`id` AND sv.`alias_id` IS NULL
                  AND sv.`status` = 'published' AND sv.`is_deleted` = 0)
   AND NOT EXISTS (SELECT 1 FROM `sds_update_queue` q
                    WHERE q.`finished_good_id` = fg.`id` AND q.`status` = 'pending');

UPDATE `finished_goods` fg
  JOIN `settings` s ON s.`key` = 'sds.default_recommended_use'
   SET fg.`recommended_use` = NULL,
       fg.`updated_at`      = UTC_TIMESTAMP()
 WHERE TRIM(COALESCE(s.`value`, '')) <> ''
   AND TRIM(COALESCE(fg.`recommended_use`, '')) = TRIM(s.`value`);

UPDATE `finished_goods` fg
  JOIN `settings` s ON s.`key` = 'sds.default_restrictions_on_use'
   SET fg.`restrictions_on_use` = NULL,
       fg.`updated_at`          = UTC_TIMESTAMP()
 WHERE TRIM(COALESCE(s.`value`, '')) <> ''
   AND TRIM(COALESCE(fg.`restrictions_on_use`, '')) = TRIM(s.`value`);

DELETE FROM `settings`
 WHERE `key` IN ('sds.default_recommended_use', 'sds.default_restrictions_on_use');

-- (sibling blocks go above this line; keep exactly one version row)
INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('059_sds_audit_batch_t4');
