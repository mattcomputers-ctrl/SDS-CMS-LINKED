-- ============================================================
-- Migration 051 — Private label item registry + PL version provenance
-- ============================================================
-- Why:
--   * The set of private-label documents was never stored: it was inferred
--     from DISTINCT (manufacturer_id, alias_id) rows in private_label_sds.
--     private_label_items makes it explicit so every FG publish path can
--     fan out to it, and gives manufacturer-specific codes a home that the
--     CMS alias upsert (aliases.customer_code is globally UNIQUE) can never
--     clobber.
--   * private_label_sds gains item_id (the new version-chain key), the
--     identity that was actually printed (frozen), and the base FG SDS
--     version it was derived from (staleness signal).
--
-- Every ALTER is guarded with INFORMATION_SCHEMA + PREPARE/EXECUTE (same
-- pattern as 045/046) and every backfill is written to be a no-op on
-- re-run, so a partially applied file can be run again safely.
-- Requires MariaDB >= 10.2.7 / MySQL >= 5.7.8 (already required by the
-- JSON columns in 027; generated columns need 10.2 / 5.7.6).
-- ============================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------
-- 1. Registry: one row = "manufacturer M sells finished good F
--    under identity I" (identity precedence: custom_code, then
--    shared alias, then base FG — see PrivateLabelPublisher).
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `private_label_items` (
    `id`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `manufacturer_id`    INT UNSIGNED NOT NULL,
    `finished_good_id`   INT UNSIGNED NOT NULL,
    `alias_id`           INT UNSIGNED NULL
        COMMENT 'Shared alias from the main (CMS-synced) list; dedupe-representative row; pack extension stripped at publish',
    `custom_code`        VARCHAR(100) NULL
        COMMENT 'Manufacturer-specific product code, stored verbatim; NULL = inherit alias / FG code',
    `custom_description` VARCHAR(500) NULL
        COMMENT 'Manufacturer-specific description; NULL = inherit alias description, then FG description',
    `identity_key`       VARCHAR(120) GENERATED ALWAYS AS (
                             CASE WHEN `custom_code` IS NOT NULL THEN CONCAT('code:',  `custom_code`)
                                  WHEN `alias_id`    IS NOT NULL THEN CONCAT('alias:', `alias_id`)
                                  ELSE                                 CONCAT('fg:',    `finished_good_id`)
                             END) STORED
        COMMENT 'DB-enforced uniqueness per manufacturer: one base item per FG, one per shared alias, one per custom code',
    `is_active`          TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '0 = retired: hidden by default, excluded from every republish, history kept',
    `auto_republish`     TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '0 = frozen: still listed, skipped by the FG-publish cascade and bulk publish; manual Republish only',
    `notes`              VARCHAR(500) NULL,
    `created_by`         INT UNSIGNED NULL,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        COMMENT 'Staleness input: an item whose updated_at is newer than its latest PL publish (private_label_sds.created_at) shows as stale. Metadata-only writes must set updated_at = updated_at.',
    UNIQUE INDEX `uq_pli_mfg_identity` (`manufacturer_id`, `identity_key`),
    INDEX `idx_pli_fg_active`  (`finished_good_id`, `is_active`, `auto_republish`),
    INDEX `idx_pli_mfg_active` (`manufacturer_id`, `is_active`),
    INDEX `idx_pli_alias`      (`alias_id`),
    CONSTRAINT `fk_pli_mfg`        FOREIGN KEY (`manufacturer_id`)  REFERENCES `manufacturers`(`id`)  ON DELETE RESTRICT,
    CONSTRAINT `fk_pli_fg`         FOREIGN KEY (`finished_good_id`) REFERENCES `finished_goods`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_pli_alias`      FOREIGN KEY (`alias_id`)         REFERENCES `aliases`(`id`)        ON DELETE RESTRICT,
    CONSTRAINT `fk_pli_created_by` FOREIGN KEY (`created_by`)       REFERENCES `users`(`id`)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- 2. private_label_sds: version-chain key, frozen identity,
--    provenance. Guarded so the file is re-runnable.
-- -----------------------------------------------------------
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'private_label_sds' AND COLUMN_NAME = 'item_id');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `private_label_sds`
        ADD COLUMN `item_id` INT UNSIGNED NULL
            COMMENT ''Registry row (private_label_items) this version belongs to; version chain key. NULL only for unlinked legacy rows''
            AFTER `alias_id`,
        ADD COLUMN `product_code` VARCHAR(100) NULL
            COMMENT ''Resolved product code printed on this version (frozen at publish)''
            AFTER `language`,
        ADD COLUMN `product_description` VARCHAR(500) NULL
            COMMENT ''Resolved description printed on this version (frozen at publish)''
            AFTER `product_code`,
        ADD COLUMN `source_fg_version` INT NULL
            COMMENT ''Base finished-good sds_versions.version this document was derived from; NULL = unknown (legacy)''
            AFTER `version`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'private_label_sds' AND INDEX_NAME = 'idx_plsds_item_latest');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `private_label_sds` ADD INDEX `idx_plsds_item_latest` (`item_id`, `language`, `version` DESC)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'private_label_sds'
      AND CONSTRAINT_NAME = 'fk_plsds_item' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `private_label_sds`
        ADD CONSTRAINT `fk_plsds_item` FOREIGN KEY (`item_id`) REFERENCES `private_label_items`(`id`) ON DELETE RESTRICT',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- fk_plsds_alias (ON DELETE SET NULL) is intentionally left as-is: the
-- version chain is item_id from now on, so a SET NULL on a history row
-- can no longer re-key a version sequence.

-- -----------------------------------------------------------
-- 3. Backfill the registry from history: one item per historical
--    DISTINCT (manufacturer, finished good, alias|NULL) combo — this
--    is exactly the implicit registry SDSUpdateController used to
--    read. Rows are tagged in notes so they can be reviewed.
--    INSERT IGNORE covers the rare legacy case of one shared alias
--    recorded under two FGs for the same manufacturer (would collide
--    on uq_pli_mfg_identity); the ORDER BY makes the most recently
--    published combo win that collision deterministically. The losing
--    history rows stay item_id NULL and are reported by the count at
--    the end.
--    updated_at is set explicitly to the earliest PL row's created_at
--    (a DB-written column) so backfilled items are not flagged
--    "identity changed since last publish" by the staleness rule.
-- -----------------------------------------------------------
INSERT IGNORE INTO `private_label_items`
    (`manufacturer_id`, `finished_good_id`, `alias_id`, `is_active`, `auto_republish`, `notes`, `created_by`, `created_at`, `updated_at`)
SELECT pl.`manufacturer_id`, pl.`finished_good_id`, pl.`alias_id`, 1, 1,
       'Backfilled from private label SDS history (migration 051)',
       MIN(pl.`created_by`), MIN(pl.`created_at`), MIN(pl.`created_at`)
  FROM `private_label_sds` pl
  LEFT JOIN `private_label_items` i
         ON i.`manufacturer_id`  = pl.`manufacturer_id`
        AND i.`finished_good_id` = pl.`finished_good_id`
        AND (i.`alias_id` <=> pl.`alias_id`)
        AND i.`custom_code` IS NULL
 WHERE i.`id` IS NULL
 GROUP BY pl.`manufacturer_id`, pl.`finished_good_id`, pl.`alias_id`
 ORDER BY MAX(pl.`published_at`) DESC;

-- Link history rows to their item (version sequences continue unbroken).
UPDATE `private_label_sds` pl
  JOIN `private_label_items` i
    ON i.`manufacturer_id`  = pl.`manufacturer_id`
   AND i.`finished_good_id` = pl.`finished_good_id`
   AND (i.`alias_id` <=> pl.`alias_id`)
   AND i.`custom_code` IS NULL
   SET pl.`item_id` = i.`id`
 WHERE pl.`item_id` IS NULL;

-- Freeze what was actually printed: the snapshot already carries the
-- post-variant identity in meta.product_code / meta.description.
UPDATE `private_label_sds` pl
  LEFT JOIN `aliases`        a  ON a.`id`  = pl.`alias_id`
  JOIN      `finished_goods` fg ON fg.`id` = pl.`finished_good_id`
   SET pl.`product_code` = COALESCE(
           NULLIF(JSON_UNQUOTE(JSON_EXTRACT(pl.`snapshot_json`, '$.meta.product_code')), ''),
           SUBSTRING_INDEX(a.`customer_code`, '-', 1),
           fg.`product_code`),
       pl.`product_description` = COALESCE(
           NULLIF(JSON_UNQUOTE(JSON_EXTRACT(pl.`snapshot_json`, '$.meta.description')), ''),
           NULLIF(a.`description`, ''),
           fg.`description`)
 WHERE pl.`product_code` IS NULL;

-- Best-effort provenance: the base FG version that was current when
-- each legacy PL row was published. Compares the DB-written created_at
-- columns on both tables (DEFAULT CURRENT_TIMESTAMP under the same
-- session time_zone; never set by any PHP insert path, and no draft ->
-- publish flow exists, so created_at is the publish instant) rather
-- than published_at: scripts/publish-worker.php stamps
-- sds_versions.published_at with gmdate() (UTC) while every legacy
-- private_label_sds row carries date() (app-local), so a published_at
-- comparison would exclude any base version bulk-published within the
-- UTC-offset window before the PL row and freeze source_fg_version one
-- version too low (a spurious "Stale — base SDS vN is newer" on day one).
-- Rows with no match stay NULL and show as "Unknown" (grey), never as
-- stale.
UPDATE `private_label_sds` pl
   SET pl.`source_fg_version` = (
        SELECT MAX(sv.`version`)
          FROM `sds_versions` sv
         WHERE sv.`finished_good_id` = pl.`finished_good_id`
           AND sv.`alias_id` IS NULL
           AND sv.`status` = 'published'
           AND sv.`is_deleted` = 0
           AND sv.`created_at` <= pl.`created_at`)
 WHERE pl.`source_fg_version` IS NULL;

-- Informational for the deployer (expected 0). Any non-zero count means
-- legacy rows that could not be mapped to an item (see comment above):
-- they remain visible on /private-label/documents as "unlinked (legacy)".
SELECT COUNT(*) AS unlinked_private_label_rows FROM `private_label_sds` WHERE `item_id` IS NULL;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('051_private_label_items');

-- Follow-up (separate migration 052, after scripts/check-pl-duplicates.php
-- reports no duplicate (item_id, language, version) rows in production):
--   ALTER TABLE `private_label_sds`
--     ADD UNIQUE INDEX `uq_plsds_item_lang_ver` (`item_id`, `language`, `version`);
