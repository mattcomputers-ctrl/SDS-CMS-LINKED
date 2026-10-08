# Private Label Registry — design record

_Design produced 2026-10-07 by a judge-panel workflow (6 readers, 3 competing designs, 3 judges, synthesis, critic) and implemented the same day (migration 051). This file is the record of what was decided and why; the operational summary lives in [operations.md](operations.md). Sections DECISIONS_NEEDING_USER list the defaults that were taken and can be overruled._


## CHOSEN_BASE

Design 3 — "Private Label Registry: explicit per-manufacturer items with cascade-on-publish" (unanimous judges' winner). Kept as the skeleton: private_label_items registry with a STORED generated identity_key + DB UNIQUE, item_id as the new version-chain key on private_label_sds, frozen product_code/product_description + source_fg_version per published row, one PrivateLabelPublisher service with all-or-nothing-per-item publishing that never burns a version, hooks after the alias fan-out at the three live FG-publish sites plus a 'private_label' bulk work-item type, preview-from-snapshot, the fix for SDSUpdateController marking queue rows completed outside its try/catch, and the AuthMiddleware mapping. Grafts from Designs 1 and 2 are listed in grafted_ideas; every disagreement is resolved explicitly in rejected_ideas / final_design.


## FINAL_DESIGN

GOAL (user's words mapped to mechanisms)
- "go into a private label manufacturer and set up an alias specific to that manufacturer ... linked to a finished good" -> new registry table private_label_items (one row = manufacturer M sells finished good F under identity I), edited from /private-label/manufacturer/{id}. The manufacturer-specific alias lives in private_label_items.custom_code / custom_description, NOT in the CMS-owned global aliases table.
- "some share aliases from the main alias list, some use the base finished good name" -> three identity sources resolved by precedence (custom > shared alias > base FG), see resolution_rules.
- "anytime that finished good gets triggered to make a new SDS, the private label SDS should also get a new version" -> PrivateLabelPublisher::publishForFinishedGood() called right after the alias fan-out in SDSController::publish, SDSUpdateController::republish, and (via buildWorkItems + publish-worker.php) every bulk/cron publish. See generation_hook.
- "list of manufacturers -> open manufacturer -> items with a link to the most recent PDF by language" -> /private-label (manufacturers) -> /private-label/manufacturer/{id} (items x languages, latest-only). See ui_spec.

DATA MODEL (migration 051, full SQL in migration_sql)

1. NEW TABLE private_label_items
   id PK; manufacturer_id FK manufacturers RESTRICT; finished_good_id FK finished_goods RESTRICT;
   alias_id NULL FK aliases ON DELETE RESTRICT (shared alias from the main list; stores the dedupe-representative row id = first row by customer_code per base code, exactly what every other publish path stores in sds_versions.alias_id; pack extension stripped at publish time, never at save);
   custom_code VARCHAR(100) NULL (manufacturer-specific code, stored VERBATIM — trimmed, no pack stripping, no case change; '' is normalised to NULL before insert/update);
   custom_description VARCHAR(500) NULL ('' -> NULL);
   identity_key VARCHAR(120) GENERATED ALWAYS AS (CASE WHEN custom_code IS NOT NULL THEN CONCAT('code:',custom_code) WHEN alias_id IS NOT NULL THEN CONCAT('alias:',alias_id) ELSE CONCAT('fg:',finished_good_id) END) STORED — DB-enforced uniqueness, no app-maintained key to drift;
   is_active TINYINT(1) DEFAULT 1 (0 = retired: hidden by default, excluded from every republish, history still downloadable);
   auto_republish TINYINT(1) DEFAULT 1 (0 = frozen: still listed/current, skipped by the FG-publish cascade and bulk publish; only the per-item Republish button regenerates it) — grafted from Design 2;
   notes VARCHAR(500) NULL; created_by FK users SET NULL; created_at; updated_at ON UPDATE CURRENT_TIMESTAMP.
   UNIQUE uq_pli_mfg_identity (manufacturer_id, identity_key) => per manufacturer: one 'base' item per FG, one item per shared alias, one item per custom code. Same custom code under two different manufacturers is allowed. Same FG under two different codes for one manufacturer is allowed (user decision #2).
   INDEX idx_pli_fg_active (finished_good_id, is_active, auto_republish) for the cascade lookup; idx_pli_mfg_active (manufacturer_id, is_active) for the page; idx_pli_alias (alias_id).
   Staleness participation: private_label_items.updated_at is an input to the PL staleness rule (extends, does not change, the repo's "updated_at is the staleness signal" invariant). Consequently PrivateLabelItem::update() MUST write `updated_at = updated_at` when only non-identity fields (notes, is_active, auto_republish) change — the same trick raw_materials metadata-only writes use — so a notes edit does not flag "identity changed".

2. ALTER private_label_sds (all guarded with the 045/046 INFORMATION_SCHEMA + PREPARE/EXECUTE pattern so the file is re-runnable; no SET FOREIGN_KEY_CHECKS=0)
   + item_id INT UNSIGNED NULL AFTER alias_id, FK fk_plsds_item -> private_label_items(id) ON DELETE RESTRICT. New version-chain key: next version = MAX(version) WHERE item_id = ? + 1 (one number shared by all languages of a publish, unchanged semantics). NULL only for legacy rows the backfill could not map (expected 0; a count is printed by the migration).
   + product_code VARCHAR(100) NULL, product_description VARCHAR(500) NULL AFTER language: the identity actually printed, frozen at publish time. History, download filename and the flat documents view always show these and never re-resolve.
   + source_fg_version INT NULL AFTER version: the base finished-good sds_versions.version this PL version was derived from. Known up front on every path (manual publish, SDS-update republish, bulk work item). NULL = legacy/unknown (shown grey, never red).
   + INDEX idx_plsds_item_latest (item_id, language, version DESC): the manufacturer page's hot path.
   Existing columns finished_good_id / manufacturer_id / alias_id stay populated on every new row (copied from the item) so download(), Manufacturer::delete(), and the old flat view keep working. fk_plsds_alias (ON DELETE SET NULL) is deliberately left untouched: once the chain is item_id, a SET NULL on a history row no longer re-keys anything, and mutating a live FK makes the migration non-idempotent. No is_deleted added (retire via is_active). No UNIQUE on (item_id, language, version) in 051 — legacy data is unverified; add in a follow-up 052 after the duplicates check (implementation step 15).

3. Backfill (idempotent): one private_label_items row per historical DISTINCT (manufacturer_id, finished_good_id, alias_id|NULL) combo (this materialises the implicit registry SDSUpdateController.php:417-421 reads today), is_active=1, auto_republish=1, notes='Backfilled from private label SDS history (migration 051)' so they are recognisable for review (user decision #3); INSERT IGNORE guards the rare legacy case of one shared alias appearing under two FGs for the same manufacturer (uq_pli_mfg_identity collision) — those rows stay item_id NULL and are reported. Then link history rows (item_id), freeze product_code/product_description from snapshot_json meta.product_code / meta.description (COALESCE to alias base code / FG), and best-effort source_fg_version = the FG's highest published base version with published_at <= pl.published_at. Version sequences continue unbroken because each legacy combo maps to exactly one item.

4. Untouched: aliases (CMS upsert, global UNIQUE customer_code — manufacturer codes never go there), sds_versions, sds_update_queue, manufacturers schema (manufacturers.updated_at becomes a staleness input), settings, SDSGenerator, PDFService, pdf-worker.php, BulkPublishController eligibility rules, AliasResolver, auto-send/lookup/book/export (PL stays isolated by design).

CODE STRUCTURE

src/Models/PrivateLabelItem.php (new)
  findById(int $id): ?array — item joined with fg (fg_product_code, fg_description, fg_is_active), manufacturer (manufacturer_name, manufacturer_updated_at, logo_path), alias (alias_customer_code, alias_description, alias_internal_code_base).
  forManufacturer(int $mfgId, bool $includeRetired=false, string $search=''): array (same joins; search on custom_code / alias code / fg code / descriptions).
  forFinishedGood(int $fgId, bool $autoOnly=true): array — is_active=1 [AND auto_republish=1], same joins (used by the cascade, buildWorkItems, the SDS-update badge).
  create(array $data): int / update(int $id, array $data): int — allow-list finished_good_id (create only), alias_id, custom_code, custom_description, is_active, auto_republish, notes; normalises ''->NULL; update() adds `updated_at = updated_at` when none of alias_id/custom_code/custom_description changed.
  validate(array $data, array $fg, ?int $excludeId): ?string — rules in resolution_rules; pre-checks identity_key uniqueness in PHP for a friendly message (DB UNIQUE remains the guard; catch PDO 23000 and flash "This manufacturer already has an item with that code / alias / base product").
  latestByItem(array $itemIds): array — [item_id][lang] => {id, version, published_at, source_fg_version, product_code} via the ExportController-shaped query: SELECT pl.* FROM private_label_sds pl INNER JOIN (SELECT item_id, language, MAX(version) max_ver FROM private_label_sds WHERE item_id IN (...) GROUP BY item_id, language) mx ON mx.item_id=pl.item_id AND mx.language=pl.language AND mx.max_ver=pl.version.
  fgLatestBaseVersions(array $fgIds): array — fg_id => MAX(version) WHERE alias_id IS NULL AND status='published' AND is_deleted=0.
  status(array $item, array $latestLangs, ?int $fgLatest, array $languages): array{code,label,reason} — the staleness rule (see generation_hook, "Staleness").
  manufacturerSummaries(string $search=''): array — manufacturers LEFT JOIN items LEFT JOIN private_label_sds: item_count, active_count, published_count, last_published.

src/Services/PrivateLabelPublisher.php (new; seeded from SDSUpdateController::republishPrivateLabel 428-514, the best of the three copies)
  static resolveIdentity(array $item): array{code, description, source} (resolution_rules).
  publishForFinishedGood(int $fgId, array $baseLangData, int $sourceFgVersion, ?int $userId, string $changeSummary, string $trigger): array{published:int, failed:string[]} — iterates PrivateLabelItem::forFinishedGood($fgId, autoOnly=true); never throws for a per-item failure.
  republishItems(array $itemIds, ?int $userId, string $changeSummary, string $trigger): array{published, failed, skipped} — manual paths. Groups items by FG; per FG: refuse (skipped[]) when the FG has no published base SDS ("Publish the base SDS for CODE first", user decision #6) or fg.is_active=0; computeBase once; generateFromBase per App::config('sds.supported_languages'); run SDSReadinessService::missingHazardDataError() on the first language (lifted copy of checkMissingHazardData — the 4th duplicate is not created); sourceFgVersion = PrivateLabelItem::fgLatestBaseVersions; then publishOne per item (frozen/auto_republish=0 items ARE included here because the operator asked explicitly).
  private publishOne(array $item, array $baseLangData, int $sourceFgVersion, ?int $userId, string $changeSummary, string $trigger, ?int $preComputedVersion=null): array{ok, version?, error?}
    1. [$code,$desc,$source] = resolveIdentity($item); $mfgInfo = Manufacturer::toCompanyInfo(row) cached per manufacturer_id for the call (Design 1 graft).
    2. Per language: $variant[$lang] = SDSGenerator::createPrivateLabelVariant($baseLangData[$lang], $code, $desc, $mfgInfo) — ONE code path for all three sources (Design 1 graft; verified no-op for 'base': SDSGenerator.php:573 builds product_identifier as "code — description" exactly like createAliasVariant at :479, and meta.product_code/description at :144-145 carry the same values).
    3. PdfBatchRenderer::render($variant, 'plpdf_') — the proc_open/pdf-worker.php body lifted verbatim from PrivateLabelController::generatePdfsInParallel 654-723 into src/Services/PdfBatchRenderer.php. PDFs land in public/generated-pdfs/ named {code}_PL_{Manufacturer}_v{n}[_{lang}].pdf by PDFService::generate(); pdf_path stored relative to App::basePath() — the existing PL storage convention, reused unchanged.
    4. ALL-OR-NOTHING: if any language failed -> @unlink every PDF that did succeed, return ['ok'=>false,'error'=>"CODE / MFG: PDF failed for LANG: msg"], version NOT consumed.
    5. Else $db->beginTransaction(); $version = $preComputedVersion ?? MAX(version) WHERE item_id=? + 1; insert one private_label_sds row per language: item_id, finished_good_id, manufacturer_id, alias_id (from item), language, product_code=$code, product_description=$desc, version, source_fg_version, status 'published', effective_date gmdate('Y-m-d'), published_by/created_by=$userId (nullable), published_at gmdate('Y-m-d H:i:s') (UTC, matching publish-worker.php:62-73 and the CURRENT_TIMESTAMP-written updated_at columns the staleness rule compares against), snapshot_json=$variant[$lang], pdf_path relative, change_summary; commit; on exception rollback + unlink PDFs + return error.
    6. AuditService::log('private_label_sds', (string)$item['id'], 'publish', {manufacturer_id, finished_good_id, code, source, version, source_fg_version, languages, trigger}).

src/Services/PdfBatchRenderer.php (new): public static function render(array $langData, string $tempPrefix='pdf'): array — verbatim lift. SDSController / SDSUpdateController keep their private copies in this change (optional follow-up to delegate).
src/Services/SDSReadinessService.php: + public static function missingHazardDataError(array $sdsData, Database $db): ?string — body of PrivateLabelController::checkMissingHazardData 732-768.

src/Controllers/PrivateLabelController.php (rewritten): index (manufacturers), documents (old flat table moved), manufacturer, createItem, storeItem, editItem, updateItem, publishItem, retireItem, deleteItem, itemHistory, republishStale, aliasesForFg (JSON), livePreview (extended), download (+ audit, frozen code), preview (snapshot). Removed: create, generate, republish (dead), and the private stripPackExtension / deduplicateAliasesByBaseCode / generatePdfsInParallel / checkMissingHazardData copies (use global strip_pack_extension(), BulkPublishController::deduplicateAliasesByBaseCode(), PdfBatchRenderer, SDSReadinessService).

Permissions: existing 'private_label' key — can_read for index/manufacturer/documents/history/download/preview/live-preview/aliases-for-fg; can_edit for item CRUD/publish/retire/delete/republish-stale. Add '/private-label' => 'private_label' and '/manufacturers' => 'manufacturers' to AuthMiddleware::URI_TO_PAGE_KEY (verified read-only check at AuthMiddleware.php:137-145). No PermissionService change.

Timestamps/UTC: the new service and worker branch write UTC (gmdate). Legacy PL rows carry local-time published_at; only the "manufacturer changed" staleness reason (c) can be off by the TZ offset for legacy rows published within ~5h of a manufacturer edit; the primary signal (source_fg_version) is immune.

Manufacturer::delete(): add a second guard refusing when private_label_items rows exist ("retire or delete its private label items first"); the FK would refuse anyway, this gives the friendly message.

Resale (raw-material-based) products remain out of scope (private_label_sds.finished_good_id NOT NULL).


## MIGRATION_SQL

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
        COMMENT 'Staleness input: an item whose updated_at is newer than its latest PL publish shows as stale. Metadata-only writes must set updated_at = updated_at.',
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
--    on uq_pli_mfg_identity); those history rows stay item_id NULL
--    and are reported by the count at the end.
-- -----------------------------------------------------------
INSERT IGNORE INTO `private_label_items`
    (`manufacturer_id`, `finished_good_id`, `alias_id`, `is_active`, `auto_republish`, `notes`, `created_by`, `created_at`)
SELECT pl.`manufacturer_id`, pl.`finished_good_id`, pl.`alias_id`, 1, 1,
       'Backfilled from private label SDS history (migration 051)',
       MIN(pl.`created_by`), MIN(pl.`created_at`)
  FROM `private_label_sds` pl
  LEFT JOIN `private_label_items` i
         ON i.`manufacturer_id`  = pl.`manufacturer_id`
        AND i.`finished_good_id` = pl.`finished_good_id`
        AND (i.`alias_id` <=> pl.`alias_id`)
        AND i.`custom_code` IS NULL
 WHERE i.`id` IS NULL
 GROUP BY pl.`manufacturer_id`, pl.`finished_good_id`, pl.`alias_id`;

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
-- each legacy PL row was published. Rows with no match stay NULL and
-- show as "Unknown" (grey), never as stale.
UPDATE `private_label_sds` pl
   SET pl.`source_fg_version` = (
        SELECT MAX(sv.`version`)
          FROM `sds_versions` sv
         WHERE sv.`finished_good_id` = pl.`finished_good_id`
           AND sv.`alias_id` IS NULL
           AND sv.`status` = 'published'
           AND sv.`is_deleted` = 0
           AND sv.`published_at` <= pl.`published_at`)
 WHERE pl.`source_fg_version` IS NULL
   AND pl.`published_at` IS NOT NULL;

-- Informational for the deployer (expected 0). Any non-zero count means
-- legacy rows that could not be mapped to an item (see comment above):
-- they remain visible on /private-label/documents as "unlinked (legacy)".
SELECT COUNT(*) AS unlinked_private_label_rows FROM `private_label_sds` WHERE `item_id` IS NULL;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('051_private_label_items');

-- Follow-up (separate migration 052, after scripts/check-pl-duplicates.php
-- reports no duplicate (item_id, language, version) rows in production):
--   ALTER TABLE `private_label_sds`
--     ADD UNIQUE INDEX `uq_plsds_item_lang_ver` (`item_id`, `language`, `version`);


## RESOLUTION_RULES

PrivateLabelPublisher::resolveIdentity(array $item): array{code: string, description: string, source: 'custom'|'shared_alias'|'base'}
Evaluated at publish time (so a CMS alias description change flows into the NEXT version) and frozen into private_label_sds.product_code / product_description and snapshot_json. $item is the joined row from PrivateLabelItem (custom_code, custom_description, alias_customer_code, alias_description, fg_product_code, fg_description). "Set" means non-NULL and trim() !== ''.

PRODUCT CODE — first set value wins:
  1. item.custom_code -> source 'custom'. Printed VERBATIM (no pack stripping, no case change).
  2. strip_pack_extension(alias.customer_code) for item.alias_id -> source 'shared_alias' (same strip every alias path uses; global helper src/Helpers/functions.php:278).
  3. finished_goods.product_code -> source 'base'.

DESCRIPTION — first set value wins, evaluated INDEPENDENTLY of the code chain:
  1. item.custom_description
  2. aliases.description (only when item.alias_id is set)
  3. finished_goods.description

User cases -> stored shape:
  - manufacturer-specific alias: custom_code (+ optional custom_description; optional alias_id to borrow the shared alias wording)
  - shares an alias from the main list: alias_id only
  - base finished good name and description: alias_id NULL, custom_code NULL, custom_description NULL
  - mixed: alias_id + custom_description ("their code, our wording"); custom_code + alias_id ("our code, their wording"); custom_code alone ("our code, FG description"). All allowed.

VARIANT: always SDSGenerator::createPrivateLabelVariant($base[$lang], code, description, Manufacturer::toCompanyInfo(manufacturer)) for every source (rewrites meta.product_code, meta.description, sections[1].product_identifier = "code — description", then Section 1 manufacturer_* and meta.company_logo_path when the manufacturer has a logo). Everything else is byte-identical to the base FG SDS of the same version. meta.product_code also drives the PDF title/footer and the on-disk filename (PDFService.php:136 applies strip_pack_extension to the FILENAME only — a custom code containing '-' keeps its full form in the document and the download name).

VALIDATION on save (PrivateLabelItem::validate; redirect-back with _old_input on failure, haps pattern):
  - finished_good_id must exist and be active; immutable on edit (changing the base product is a new item — history must not be re-pointed).
  - identity mode radio drives the fields: base -> alias_id NULL, custom_code NULL; shared_alias -> alias_id required; custom -> custom_code required (after trim), alias_id optional ("borrow description from shared alias").
  - alias_id, when set, must satisfy aliases.internal_code_base = finished_goods.product_code (the aliases-for-fg endpoint only offers those; server re-checks). Stored id = the dedupe-representative row from BulkPublishController::deduplicateAliasesByBaseCode (first by customer_code per base), so it agrees with sds_versions.alias_id for that alias.
  - custom_code: trim, max 100, '' -> NULL (REQUIRED so the generated identity_key can never be 'code:'); custom_description: trim, max 500, '' -> NULL.
  - uniqueness: computed identity key (same CASE as the DB) checked against private_label_items for this manufacturer excluding $excludeId -> "This manufacturer already has an item with that code / alias / base product"; the DB UNIQUE uq_pli_mfg_identity is the real guard (catch PDO 23000 and show the same message).
  - delete: only when no private_label_sds rows reference the item (FK RESTRICT; controller pre-checks and offers Retire instead).

DISPLAY rules: the manufacturer page's Code/Description columns use resolveIdentity() (what the NEXT publish will print); the Latest column, history page, documents view and download filename use the frozen pl.product_code / pl.product_description of the row shown. When the live resolution differs from the latest frozen code, the row is "Stale — identity changed" by rule (b). The item form shows a live "Will print as: CODE — DESCRIPTION (source)" line computed in JS with the same precedence.

RENAME / DELETE semantics: editing identity bumps updated_at (-> Stale) and is audited with before/after; editing notes/auto_republish/is_active writes updated_at = updated_at (no staleness). Alias removal from CMS is blocked by fk_pli_alias RESTRICT (CMS import never deletes today; a future purge must re-point items first). Retire = is_active 0 (excluded from all cascades, hidden by default, history downloadable). Manufacturer delete blocked while items or PL rows exist. Manufacturer edits (address/logo) never auto-publish; all its items show Stale (manufacturer changed) and the page offers "Republish stale (n)". FG deactivated: items remain; bulk already skips inactive FGs; republishItems refuses with a clear message.


## GENERATION_HOOK

THE RULE: every code path that inserts a new base (alias_id IS NULL) row into sds_versions for a finished good also versions every active, auto_republish private_label_items row of that FG, from the SAME already-generated per-language base data ($langData / $baseDataCache), so the hazard engine runs once per FG. There are exactly three live such paths (verified: SDSController.php:347, SDSUpdateController.php:325, scripts/publish-worker.php:182; the SDSAutoSendService inserts are dormant per its docblock 30-33 and are left alone).

HOOK A — manual publish. File: src/Controllers/SDSController.php, inside publish(), immediately AFTER the alias fan-out at lines 382-384 (`$aliasCount = $this->publishAliases(...)`) and BEFORE `$msg = 'SDS v' ...` at 386:
    $pl = (new \SDS\Services\PrivateLabelPublisher())->publishForFinishedGood(
        (int) $fg['id'], $langData, $nextVersion, current_user_id(),
        $changeSummary ?: ('Base SDS v' . $nextVersion . ' published'), 'fg_publish'
    );
    if ($pl['published'] > 0) { $msg .= ' (+ ' . $pl['published'] . ' private label SDS)'; }
    if (!empty($pl['failed'])) { $_SESSION['_flash']['warning'] = 'Private label SDS not regenerated: ' . implode('; ', $pl['failed']); }
  $langData (296-311), $nextVersion (339) and $changeSummary (284) are already in scope. Base rows are already committed, so a PL failure never fails or rolls back the FG publish (user decision #5); the item shows Stale/Never published on the manufacturer page.

HOOK B — SDS Update Required republish. File: src/Controllers/SDSUpdateController.php, inside republish(), immediately AFTER `$this->publishAliasSDSs($fg, $langData, $now, $db, $userId);` at line 351:
    $pl = $publisher->publishForFinishedGood($fgId, $langData, $nextVersion, $userId, 'Republished via SDS Update Required', 'sds_update_republish');
    foreach ($pl['failed'] as $f) { $plWarnings[] = $f; }   // NOT $errors — a PL failure is not an FG failure; flash $plWarnings as 'warning' after the loop
  ($publisher = new PrivateLabelPublisher() once above the foreach.) ALSO move the queue-completion UPDATE (lines 364-369) inside the try block directly after `$publishedCount++` (line 358) so a failed republish leaves the queue row pending instead of silently clearing it (pre-existing bug, verified). This also closes the ordering trap: "Republish Selected" now regenerates private labels, so the separate PL button is no longer required for queued FGs.

HOOK C — bulk publish (admin button AND cron/cms-sync auto bulk publish; both call BulkPublishController::buildWorkItems and spawn scripts/publish-worker.php; cron/bulk-publish.php needs no logic change).
  C1. src/Controllers/BulkPublishController.php, buildWorkItems(), inside `foreach ($eligibleFgs as $fg)` right AFTER the alias loop closes at line 615 ($nextVersion from line 586 is in scope):
    foreach (\SDS\Models\PrivateLabelItem::forFinishedGood((int) $fg['id'], true) as $pli) {
        $identity = \SDS\Services\PrivateLabelPublisher::resolveIdentity($pli);
        $plLast   = $db->fetch("SELECT MAX(version) AS max_ver FROM private_label_sds WHERE item_id = ?", [(int) $pli['id']]);
        $plNext   = ((int) ($plLast['max_ver'] ?? 0)) + 1;   // pre-computed so parallel workers never race (same pattern as aliases, 598-602)
        foreach ($languages as $lang) {
            $workItems[] = [
                'type' => 'private_label', 'id' => $fg['id'], 'product_code' => $fg['product_code'],
                'language' => $lang, 'version' => $plNext, 'source_fg_version' => $nextVersion,
                'pl_item_id' => (int) $pli['id'], 'manufacturer_id' => (int) $pli['manufacturer_id'],
                'alias_id' => $pli['alias_id'] !== null ? (int) $pli['alias_id'] : null,
                'pl_code' => $identity['code'], 'pl_description' => $identity['description'], 'pl_source' => $identity['source'],
            ];
        }
    }
  C2. scripts/publish-worker.php: add `$isPrivateLabel = (($item['type'] ?? null) === 'private_label');` beside `$isResale` (line 108); $fgId stays (int)$item['id'] (109) so the existing $baseDataCache[$fgId] / generateFromBase path (133-136) is reused unchanged. After the alias branch (139-142):
    if ($isPrivateLabel) {
        $mfgRow = $mfgCache[(int) $item['manufacturer_id']] ??= \SDS\Models\Manufacturer::findById((int) $item['manufacturer_id']);
        if ($mfgRow === null) { throw new \RuntimeException('Manufacturer not found for private label item'); }
        $sdsData = SDSGenerator::createPrivateLabelVariant($sdsData, $item['pl_code'], $item['pl_description'], \SDS\Models\Manufacturer::toCompanyInfo($mfgRow));
    }
    then `$pdfPath = $pdfService->generate($sdsData)` as today (144). Branch the insert (163-191): for private_label, insert into private_label_sds {item_id=pl_item_id, finished_good_id=$fgId, manufacturer_id, alias_id, language, product_code=pl_code, product_description=pl_description, version, source_fg_version, status 'published', effective_date $today, published_by $userId (NULL for cron — FKs are nullable), published_at $now (gmdate), snapshot_json, pdf_path, change_summary 'Bulk publish (private label)', created_by $userId} and SKIP the sds_versions insert and the sds_generation_trace insert (its FK is to sds_versions). Audit: AuditService::log('private_label_sds', (string) $item['pl_item_id'], 'bulk_publish_private_label', {finished_good_id, manufacturer_id, product_code, language, version, source_fg_version}). $displayCode = $item['pl_code'] ?? $aliasCode ?? $code for error strings. Progress accounting unchanged (total = count(workItems)). Declare `$mfgCache = [];` next to the other caches (83-84).
  C3. Bulk page summary (BulkPublishController::page ~104-157 and src/Views/admin/bulk-publish.php:14-38): $plCount = COUNT(*) FROM private_label_items WHERE finished_good_id IN (eligible ids) AND is_active = 1 AND auto_republish = 1; add a "Private label documents (for eligible FGs)" row and include it in "Total PDFs to Generate". cron/bulk-publish.php:110 echo wording: "+ alias and private label variants".
  Deliberate trade-off (documented in the Stale tooltip): bulk items are per language, so a single failed-language PDF leaves that version missing one language — identical to today's base/alias bulk behaviour; staleness rule (d) flags it and the manufacturer page offers Republish. Hooks A/B are all-or-nothing per item.

MANUAL / ITEM PATHS — all via PrivateLabelPublisher::republishItems (recomputes base once per FG from live data, runs SDSReadinessService::missingHazardDataError on the first language, refuses when the FG has no published base SDS, uses source_fg_version = the FG's latest published base version):
  D1. storeItem with "Publish SDS now" checked (default ON on create): republishItems([$newId], user, 'Initial private label SDS', 'item_create').
  D2. updateItem with the checkbox checked (default OFF on edit): 'Republished after identity change', 'item_edit'. Unchecked: the identity edit bumps updated_at -> Stale (identity changed) until republished (user decision #4).
  D3. POST /private-label/items/{id}/publish: 'Republished from private label page', 'manual_item' (frozen auto_republish=0 items ARE republished here — explicit operator action).
  D4. POST /private-label/manufacturer/{id}/republish-stale: every active item of that manufacturer whose status is stale or never (regardless of auto_republish): 'Republished (stale) from private label page', 'manufacturer_republish_stale'. Items are grouped by FG so computeBase runs once per FG.
  D5. POST /sds-updates/republish-private-label — existing route KEPT as "Republish Private Labels Only" (user decision #1): body rewritten to collect PrivateLabelItem::forFinishedGood($fgId, autoOnly=true) ids for the selected FGs -> republishItems(..., 'Private label republished (re-brand) via SDS Update Required', 'sds_update_pl_only'). Touches neither sds_versions nor the queue. Confirm text: "Re-brand only — Republish Selected already regenerates private labels."
  The un-routed PrivateLabelController::republish() (478-615) and generate() are deleted.

STALENESS (PrivateLabelItem::status; computed in listing queries — no cron, no new signal tables)
  Inputs per item: latest PL rows per language (latestByItem), FG latest published base version (fgLatestBaseVersions), item.updated_at, manufacturer.updated_at, App::config('sds.supported_languages').
  - retired: is_active = 0.
  - never: no private_label_sds rows for the item.
  - Let V = max version across languages, P = max published_at among rows at V, S = source_fg_version of any row at V.
  - stale (first matching reason shown): (a) S !== NULL AND fgLatest > S -> "Base SDS v{fgLatest} is newer than v{S}"; (b) item.updated_at > P -> "Identity changed since last publish"; (c) manufacturer.updated_at > P -> "Manufacturer details changed since last publish"; (d) a configured language has no row at V -> "Missing {LANG} at v{V}".
  - unknown (grey, Design 2 graft): S === NULL and none of (b)-(d) -> "Legacy version — base SDS version unknown".
  - current otherwise.
  Nothing here writes raw_materials.updated_at, formulas, or any column BulkPublishController::computeEligibleFinishedGoods / SDSUpdateController::scan read, and PL rows never enter sds_versions, so FG eligibility and the update queue are unchanged. Bulk eligibility is deliberately NOT widened to PL staleness: PL documents are derived and regenerate whenever the base does; PL-only staleness is handled by the manufacturer page and the SDS-update PL-only button. SDSUpdateController::index badge query (89-96) becomes COUNT(*) FROM private_label_items WHERE finished_good_id = ? AND is_active = 1 AND auto_republish = 1 -> "N PL item(s)".


## UI_SPEC

All pages use the existing custom MVC: controller methods + view('private-label/...'), layouts/main.php + layouts/footer.php includes, flash + redirect, csrf_field(), old()/_old_input for redirect-back forms (haps-form.php conventions), can_read/can_edit('private_label') gates, class="pdf-link" on PDF anchors so the sidebar new-tab/download cookie toggle (layouts/footer.php:23-76) and the download controllers' sds_pdf_download handling work unchanged. The sidebar entry "Private Label SDS" (layouts/main.php:54-56) is unchanged; its str_starts_with('/private-label') active-class already covers every sub-route. Language columns iterate App::config('sds.supported_languages') (not hard-coded like lookup/index.php).

ROUTES (src/Core/App.php, replace block 313-318; literal segments registered BEFORE the {id} routes as a convention — the anchored per-segment regex cannot collide, but keep literals first):
  GET  /private-label                                       PrivateLabelController@index         (manufacturer list)
  GET  /private-label/documents                             @documents                           (flat cross-manufacturer audit view = old index moved)
  GET  /private-label/create                                @legacyCreate                        (redirect('/private-label') with flash "Pick a manufacturer, then Add item" — bookmark safety)
  GET  /private-label/aliases-for-fg                        @aliasesForFg                        (JSON)
  GET  /private-label/live-preview                          @livePreview                         (extended; same path as today)
  GET  /private-label/manufacturer/{id}                     @manufacturer                        (items -> latest PDF per language)
  GET  /private-label/manufacturer/{id}/items/create        @createItem
  POST /private-label/manufacturer/{id}/items               @storeItem
  POST /private-label/manufacturer/{id}/republish-stale     @republishStale
  GET  /private-label/items/{id}/edit                       @editItem
  POST /private-label/items/{id}                            @updateItem
  POST /private-label/items/{id}/publish                    @publishItem
  POST /private-label/items/{id}/retire                     @retireItem      (toggles is_active; audit 'retire'/'restore')
  POST /private-label/items/{id}/delete                     @deleteItem      (only when no history; else flash "Retire instead")
  GET  /private-label/items/{id}/history                    @itemHistory
  GET  /private-label/{id}/download                         @download        (kept; + audit_log row like LookupController.php:76-82; filename PL_SDS_{pl.product_code ?? alias base ?? fg code}_{Mfg}_v{n}_{lang}.pdf)
  GET  /private-label/{id}/preview                          @preview         (kept; now renders snapshot_json)
  Removed: POST /private-label/generate. Unchanged elsewhere: POST /sds-updates/republish-private-label (relabelled), manufacturers CRUD.

1. /private-label — MANUFACTURERS (src/Views/private-label/index.php rewritten)
   Toolbar: search (manufacturer name), link "All documents" -> /private-label/documents, "+ Add manufacturer" -> /manufacturers/create (can_edit('manufacturers')).
   Data: PrivateLabelItem::manufacturerSummaries($search) + one pass of PrivateLabelItem::status over all active items (one latestByItem query, one fgLatestBaseVersions query) to get per-manufacturer stale counts.
   Table: Logo thumb | Manufacturer (link to /private-label/manufacturer/{id}; city/state muted) | Items (active / total) | Published | Stale (red badge if >0) | Last published (format_date m/d/Y g:i A) | Actions: Open, Edit manufacturer (/manufacturers/{id}/edit, can_edit('manufacturers')).
   Manufacturers with zero items still appear so the user can open one and add the first item. Empty state links to /manufacturers/create.

2. /private-label/manufacturer/{id} — ITEMS (new src/Views/private-label/manufacturer.php) — THE LOOKUP PAGE
   Header card: logo, name, address line, phone / emergency, "Edit manufacturer" link.
   Toolbar: search (resolved code/description/FG code), checkbox "Show retired", "+ Add item" (/private-label/manufacturer/{id}/items/create, can_edit), POST "Republish stale (n)" (confirm dialog; disabled when n = 0), link "History for all items" -> /private-label/documents?manufacturer_id={id}.
   Data: PrivateLabelItem::forManufacturer($id, $showRetired, $search); latestByItem(ids); fgLatestBaseVersions(fg ids); status() per item; languages from config.
   One row per item, ordered by resolved code. Columns:
     Code — resolved, bold, plus a small badge Custom / Alias / Base (source);
     Description — resolved;
     Base product — fg.product_code (link /sds/{fg_id}) + muted fg description;
     Latest — "v{V} (mm/dd/yyyy)" + muted "from base v{S}" (or "base version unknown"); "—" when never published; if the frozen latest code differs from the resolved code show the frozen code muted ("printed as X");
     Status — badge: Current (green) / Stale (amber, reason in title attribute and as muted text) / Never published (grey) / Unknown (grey) / Retired (muted);
     one column per configured language — `<a href="/private-label/{pl_id}/download" class="badge badge-success pdf-link" title="{Language} SDS v{V}">PDF</a>` or "—" (markup copied from src/Views/lookup/index.php:50-61);
     Actions — Preview (latest EN snapshot: /private-label/{pl_id}/preview, target _blank), History (/private-label/items/{id}/history), Publish / Republish (POST form, confirm), Edit, Retire / Restore (POST), Delete (only when never published; confirm).
   Retired items render with class text-muted.

3. ITEM FORM — GET /private-label/manufacturer/{mid}/items/create, GET /private-label/items/{id}/edit (new src/Views/private-label/item-form.php; $mode create|edit, $item, $manufacturer, $finishedGoods, $languages, old-input flash, csrf_field, form-grid-2col, Back link to the manufacturer page)
   - Manufacturer: read-only header (hidden manufacturer_id on create).
   - Finished good: `<select name="finished_good_id" class="searchable-select" required>` of active FGs ("CODE — description"; public/js/searchable-select.js already enhances this class). On edit: read-only text + hidden input; help "Changing the base product = create a new item; history stays with this one".
   - Identity (radio name="identity_mode", drives which inputs are enabled via JS):
       (a) "Base product code and description" (default on create)
       (b) "Shared alias from the main alias list" -> `<select name="alias_id">` populated by fetch('/private-label/aliases-for-fg?fg_id=') on FG change; options "CODE — description" (deduped base codes); empty-state text "No aliases exist for this finished good".
       (c) "Manufacturer-specific code" -> `<input name="custom_code" maxlength="100">` (required in this mode; help "Printed exactly as typed — enter the base code without a pack-size suffix") + optional "Borrow description from shared alias" select (same endpoint, writes alias_id).
   - Description override: `<input name="custom_description" maxlength="500">` shown for all modes; help "Leave blank to use the alias / base product description".
   - Live line "Will print as: CODE — DESCRIPTION (source)" computed in JS with the same precedence; initial values from server-side resolveIdentity on edit.
   - Checkboxes: auto_republish (default checked; help "Create a new version of this document whenever the base product's SDS is republished (manual, SDS Update Required, or bulk publish)"); publish_now ("Publish SDS now after saving" — default checked on create, unchecked on edit; disabled with help "Publish the base SDS for this product first" when the FG has no published base SDS; server re-checks).
   - Notes (500).
   - Buttons: Save; Preview (opens /private-label/live-preview?finished_good_id&manufacturer_id&identity_mode&alias_id&custom_code&custom_description&lang={select next to the button, default en} in a new tab — finally passes lang); Cancel.
   - storeItem/updateItem: CSRF::validateRequest(); PrivateLabelItem::validate(); on error -> $_SESSION['_flash']['_old_input'] = $_POST, error flash, redirect back; on success -> AuditService::log('private_label_item', id, 'create'|'update', diff); optional publish via republishItems; flash "Item saved" + "(private label SDS v{n} published: EN, ES, FR, DE)" or the skipped/failed reason; redirect to /private-label/manufacturer/{mid}.

4. /private-label/items/{id}/history (new src/Views/private-label/history.php)
   Header: resolved identity, manufacturer, base product, status badge. Table grouped by version (the src/Views/sds/index.php:33-89 grouping pattern): Version | Printed code — description (frozen) | From base vS | Published by / at | Change summary | per-language `<a class="btn btn-sm pdf-link">{Language} PDF</a>` (or "— missing" when a language has no row at that version) | Preview (snapshot). Back link to the manufacturer page.

5. /private-label/documents (new src/Views/private-label/documents.php = the current index.php moved)
   The existing flat query (PrivateLabelController.php:56-69) plus pl.product_code, pl.product_description, pl.item_id, pl.source_fg_version; filters: search, manufacturer_id dropdown (as today), optional fg_id / item_id query params. Columns as today but Code = pl.product_code ?? alias base ?? fg code, Description = pl.product_description ?? ..., plus "Item" link to the history page (or "unlinked (legacy)" when item_id IS NULL). Kept for cross-manufacturer audit/search (Design 2 graft).

6. /private-label/{id}/preview — renders the stored snapshot_json (what the customer received) with page title "Private Label SDS v{n} ({LANG}) — {code} / {mfg} — published snapshot"; falls back to live regeneration only when snapshot_json IS NULL. Adds a "Live preview with current data" link -> /private-label/live-preview?item_id={item_id}&lang={lang}. src/Views/sds/preview.php: add an optional `$backUrl` branch before the existing finishedGood/privateLabelId branches (lines 11-15) so the back link returns to the manufacturer page.

7. /private-label/live-preview — accepts item_id (loads item -> resolveIdentity) OR the ad-hoc form fields (finished_good_id, manufacturer_id, identity_mode, alias_id, custom_code, custom_description) plus lang (default en); SDSGenerator::generate($fgId, $lang) -> createPrivateLabelVariant; renders sds/preview with backUrl. can_read('private_label').

8. /private-label/aliases-for-fg?fg_id=N — JSON [{id, code (base-stripped), description}] from SELECT id, customer_code, description FROM aliases WHERE internal_code_base = fg.product_code ORDER BY customer_code -> BulkPublishController::deduplicateAliasesByBaseCode(). can_read('private_label').

9. Cross-links: src/Views/manufacturers/index.php — new column "Private label" showing "N item(s)" linking to /private-label/manufacturer/{id} (counts via one GROUP BY query in ManufacturerController::index); src/Views/manufacturers/form.php (edit mode) — a small card under "Logo & Settings": "Private label items: N — Manage" linking to the same page. The manufacturer form itself stays a pure identity form.

10. src/Views/sds-updates/index.php — badge text "N PL item(s)"; "Republish Selected" tooltip "Republish standard SDS (+ aliases + private labels) for selected products"; second button relabelled "Republish Private Labels Only" with tooltip "Re-brand only (e.g. after a manufacturer address/logo change). Republish Selected already regenerates private labels."; confirm text in submitAction() updated accordingly.

11. src/Views/admin/bulk-publish.php — "Private label documents (for eligible FGs): N" row; Total PDFs includes it.


## IMPLEMENTATION_STEPS

1. 1. Pre-flight on the server (docs/operations.md PDO pattern): `SELECT VERSION()` — must be MariaDB >= 10.2.7 or MySQL >= 5.7.8 (JSON columns from 027 already require it; the generated identity_key needs 10.2 / 5.7.6). If older (not expected on TurnKey LAMP), fall back to an app-maintained identity_key VARCHAR(120) NOT NULL written by PrivateLabelItem::create/update with the same CASE logic — everything else is unchanged.
2. 2. Create C:\Claude Sessions\SDS-System\migrations\051_private_label_items.sql with the SQL in migration_sql (guarded ALTERs, idempotent backfills, INSERT IGNORE INTO schema_migrations).
3. 3. Create C:\Claude Sessions\SDS-System\src\Services\PdfBatchRenderer.php: `public static function render(array $langData, string $tempPrefix = 'pdf'): array` — verbatim lift of PrivateLabelController::generatePdfsInParallel (lines 654-723), temp files named {prefix}input_{lang}_{rand}.json / {prefix}result_{lang}_{rand}.json, spawning scripts/pdf-worker.php via php_cli_binary().
4. 4. Add `public static function missingHazardDataError(array $sdsData, Database $db): ?string` to C:\Claude Sessions\SDS-System\src\Services\SDSReadinessService.php — body of PrivateLabelController::checkMissingHazardData (732-768). Leave SDSController's private copy alone in this change.
5. 5. Create C:\Claude Sessions\SDS-System\src\Models\PrivateLabelItem.php: findById, forManufacturer, forFinishedGood(fgId, autoOnly), create/update (allow-list; ''->NULL for custom_code/custom_description/alias_id; `updated_at = updated_at` when no identity field changed), validate, latestByItem, fgLatestBaseVersions, status, manufacturerSummaries — queries exactly as specified in final_design / generation_hook.
6. 6. Create C:\Claude Sessions\SDS-System\src\Services\PrivateLabelPublisher.php: resolveIdentity, publishForFinishedGood, republishItems, private publishOne (PDFs via PdfBatchRenderer -> all-or-nothing -> transaction -> rows with item_id/product_code/product_description/source_fg_version, gmdate UTC timestamps -> AuditService::log). Seed from SDSUpdateController::republishPrivateLabel 428-514.
7. 7. Rewrite C:\Claude Sessions\SDS-System\src\Controllers\PrivateLabelController.php per ui_spec (index, documents, legacyCreate redirect, aliasesForFg, livePreview, manufacturer, createItem, storeItem, editItem, updateItem, publishItem, retireItem, deleteItem, itemHistory, republishStale, download + audit + frozen code, preview from snapshot). Delete create(), generate(), republish(), and the private stripPackExtension / deduplicateAliasesByBaseCode / generatePdfsInParallel / checkMissingHazardData copies (use strip_pack_extension(), BulkPublishController::deduplicateAliasesByBaseCode(), PdfBatchRenderer, SDSReadinessService).
8. 8. Views: rewrite C:\Claude Sessions\SDS-System\src\Views\private-label\index.php (manufacturer list); create src\Views\private-label\manufacturer.php, item-form.php, history.php; move the current index.php table into src\Views\private-label\documents.php (add frozen code / item link columns); delete src\Views\private-label\create.php; add the optional `$backUrl` branch at the top of src\Views\sds\preview.php (lines 11-15).
9. 9. Routes: replace the Private Label block in C:\Claude Sessions\SDS-System\src\Core\App.php lines 313-318 with the route list in ui_spec (literal routes first, then {id}/download and {id}/preview); remove POST /private-label/generate.
10. 10. Add `'/private-label' => 'private_label'` and `'/manufacturers' => 'manufacturers'` to URI_TO_PAGE_KEY in C:\Claude Sessions\SDS-System\src\Middleware\AuthMiddleware.php (lines 59-78).
11. 11. Hook A: C:\Claude Sessions\SDS-System\src\Controllers\SDSController.php publish() — insert the PrivateLabelPublisher::publishForFinishedGood call after publishAliases (lines 382-384) and extend the success flash / add the warning flash exactly as in generation_hook.
12. 12. Hook B: C:\Claude Sessions\SDS-System\src\Controllers\SDSUpdateController.php — republish(): call publishForFinishedGood after publishAliasSDSs (line 351), collect PL failures as warnings, and move the queue-completion UPDATE (364-369) inside the try after `$publishedCount++`; republishPrivateLabel(): replace lines 416-523 with the republishItems loop (PL-only re-brand); index(): replace the badge query at 89-96 with COUNT(*) FROM private_label_items WHERE finished_good_id = ? AND is_active = 1 AND auto_republish = 1. Delete the now-unused private stripPackExtension/deduplicateAliasesByBaseCode copies if nothing else in the file uses them (publishAliasSDSs still uses deduplicateAliasesByBaseCode — keep that one).
13. 13. Hook C: C:\Claude Sessions\SDS-System\src\Controllers\BulkPublishController.php buildWorkItems() — add the private_label work-item loop after line 615; page() — add $plCount and pass it to the view; C:\Claude Sessions\SDS-System\scripts\publish-worker.php — $isPrivateLabel flag, $mfgCache, variant branch after line 142, private_label_sds insert branch (skip sds_versions + trace), audit action 'bulk_publish_private_label'; C:\Claude Sessions\SDS-System\src\Views\admin\bulk-publish.php — PL row + total; C:\Claude Sessions\SDS-System\cron\bulk-publish.php line 110 — echo wording only.
14. 14. Supporting edits: C:\Claude Sessions\SDS-System\src\Models\Manufacturer.php delete() — second guard on private_label_items; C:\Claude Sessions\SDS-System\src\Controllers\ManufacturerController.php index() — per-manufacturer item counts; C:\Claude Sessions\SDS-System\src\Views\manufacturers\index.php — 'Private label' column/link; src\Views\manufacturers\form.php — 'Private label items: N — Manage' card (edit mode); C:\Claude Sessions\SDS-System\src\Views\sds-updates\index.php — badge wording, button relabel/tooltips/confirm text.
15. 15. Create C:\Claude Sessions\SDS-System\scripts\check-pl-duplicates.php (prints rows from `SELECT item_id, language, version, COUNT(*) c FROM private_label_sds WHERE item_id IS NOT NULL GROUP BY item_id, language, version HAVING c > 1` and `SELECT COUNT(*) FROM private_label_sds WHERE item_id IS NULL`); add a one-paragraph note to docs/operations.md (registry, cascade, re-brand button, the check script).
16. 16. Deploy per memory notes: `sudo -u www-data git -C /var/www/sds-system fetch && reset --hard origin/main`, then `sudo bash update.sh` (applies 051). Confirm the migration output shows unlinked_private_label_rows = 0 and run scripts/check-pl-duplicates.php.
17. 17. Post-deploy review: open /private-label, open each manufacturer, retire or freeze backfilled items that were one-off experiments (notes say 'Backfilled ...') BEFORE the next bulk publish (user decision #3).
18. 18. Smoke test on one FG that has >= 1 item: (a) create an item in each identity mode and check 'Will print as' + live preview per language; (b) 'Publish now' -> 4 PDF badges appear, History shows v1 with 'from base vN'; (c) /sds/{fg}/publish -> flash shows '(+ N private label SDS)', items go to v2 and status Current; (d) edit a manufacturer address -> items show Stale (manufacturer changed) -> 'Republish stale'; (e) /sds-updates: scan, Republish Selected cascades; Republish Private Labels Only re-brands without a new base version; (f) /bulk-publish on a test FG (or wait for cron) -> worker log shows private_label items, rows carry item_id and source_fg_version, published_by NULL for cron; (g) download filename uses the frozen code; preview shows the snapshot.
19. 19. Follow-up migration 052 (only after step 15 is clean): ADD UNIQUE INDEX uq_plsds_item_lang_ver (item_id, language, version) on private_label_sds; optionally tighten item_id to NOT NULL once unlinked rows are resolved. Optional cleanups: SDSController / SDSUpdateController delegate to PdfBatchRenderer and SDSReadinessService::missingHazardDataError.

## FILES_TO_CHANGE

1. C:\Claude Sessions\SDS-System\migrations\051_private_label_items.sql (NEW)
2. C:\Claude Sessions\SDS-System\src\Models\PrivateLabelItem.php (NEW)
3. C:\Claude Sessions\SDS-System\src\Services\PrivateLabelPublisher.php (NEW)
4. C:\Claude Sessions\SDS-System\src\Services\PdfBatchRenderer.php (NEW — generatePdfsInParallel lifted from PrivateLabelController 654-723)
5. C:\Claude Sessions\SDS-System\src\Services\SDSReadinessService.php (+ missingHazardDataError())
6. C:\Claude Sessions\SDS-System\src\Controllers\PrivateLabelController.php (rewrite)
7. C:\Claude Sessions\SDS-System\src\Views\private-label\index.php (rewrite: manufacturer list)
8. C:\Claude Sessions\SDS-System\src\Views\private-label\manufacturer.php (NEW: items -> latest PDF per language)
9. C:\Claude Sessions\SDS-System\src\Views\private-label\item-form.php (NEW)
10. C:\Claude Sessions\SDS-System\src\Views\private-label\history.php (NEW)
11. C:\Claude Sessions\SDS-System\src\Views\private-label\documents.php (NEW: old flat index moved, + frozen code / item link)
12. C:\Claude Sessions\SDS-System\src\Views\private-label\create.php (DELETE)
13. C:\Claude Sessions\SDS-System\src\Views\sds\preview.php (optional $backUrl back-link branch, lines 11-15)
14. C:\Claude Sessions\SDS-System\src\Core\App.php (route block 313-318)
15. C:\Claude Sessions\SDS-System\src\Middleware\AuthMiddleware.php (URI_TO_PAGE_KEY: /private-label, /manufacturers)
16. C:\Claude Sessions\SDS-System\src\Controllers\SDSController.php (publish(): hook after line 384 + flash)
17. C:\Claude Sessions\SDS-System\src\Controllers\SDSUpdateController.php (republish(): hook after line 351 + queue-completion moved into try; republishPrivateLabel(): rewritten over republishItems; index(): badge query 89-96)
18. C:\Claude Sessions\SDS-System\src\Controllers\BulkPublishController.php (buildWorkItems(): private_label items after line 615; page(): PL count)
19. C:\Claude Sessions\SDS-System\scripts\publish-worker.php (private_label branch: variant, private_label_sds insert, audit)
20. C:\Claude Sessions\SDS-System\cron\bulk-publish.php (line 110 echo wording only)
21. C:\Claude Sessions\SDS-System\src\Views\admin\bulk-publish.php (PL document count row + total)
22. C:\Claude Sessions\SDS-System\src\Models\Manufacturer.php (delete(): guard on private_label_items)
23. C:\Claude Sessions\SDS-System\src\Controllers\ManufacturerController.php (index(): item counts for the new column)
24. C:\Claude Sessions\SDS-System\src\Views\manufacturers\index.php ('Private label' column -> /private-label/manufacturer/{id})
25. C:\Claude Sessions\SDS-System\src\Views\manufacturers\form.php (edit mode: 'Private label items: N — Manage' link)
26. C:\Claude Sessions\SDS-System\src\Views\sds-updates\index.php (badge wording, 'Republish Private Labels Only' label/tooltip/confirm)
27. C:\Claude Sessions\SDS-System\scripts\check-pl-duplicates.php (NEW: pre-052 verification)
28. C:\Claude Sessions\SDS-System\docs\operations.md (short note on the registry, cascade, re-brand button, check script)

## GRAFTED_IDEAS

1. From Design 2: auto_republish flag separate from is_active (keep an item visible/current but frozen out of the cascade).
2. From Design 2 / judges: every ALTER in 051 guarded with INFORMATION_SCHEMA + PREPARE/EXECUTE (045/046 pattern), backfills written as no-ops on re-run, no SET FOREIGN_KEY_CHECKS=0 wrapper.
3. From Design 2: keep a cross-manufacturer flat 'All documents' view (/private-label/documents = the existing index moved) for audit/search, in addition to Design 3's per-item History page.
4. From Design 2: deferred 052 UNIQUE (item_id, language, version) behind scripts/check-pl-duplicates.php instead of risking a failed 051 on legacy data.
5. From Design 2: legacy rows with source_fg_version NULL show as 'Unknown' (grey), never 'Stale' (red).
6. From Design 2: GET /private-label/aliases-for-fg JSON endpoint so the item form does not embed every alias; identity radio group + 'Will print as' live line.
7. From Design 2: post-backfill diagnostic (reduced to one informational COUNT in the migration; the fuller duplicate/base-code checks live in the script).
8. From Design 1: always route all three identity sources through SDSGenerator::createPrivateLabelVariant (verified no-op for base at SDSGenerator.php:573/:144-145 vs :479) — one code path, the manufacturer-only branch disappears everywhere.
9. From Design 1: redirect the removed GET /private-label/create to /private-label with a flash instead of 404ing bookmarks.
10. From Design 1: delete the private stripPackExtension / deduplicateAliasesByBaseCode copies in PrivateLabelController and use global strip_pack_extension() + BulkPublishController::deduplicateAliasesByBaseCode(); cache Manufacturer::toCompanyInfo per manufacturer within a publish call.
11. From Design 1: 'Publish SDS now after saving' checkbox defaults — checked on create, unchecked on edit.
12. From Design 1 / judges: post-migration step to review backfilled combos (tagged in notes) and retire/freeze one-offs before the first bulk publish.
13. From the repo's own invariant (memory: raw_materials metadata-only writes use updated_at = updated_at): PrivateLabelItem::update() writes updated_at = updated_at when only notes / is_active / auto_republish change, so staleness reason (b) fires only on real identity edits.
14. From judges: normalise '' -> NULL on custom_code before insert; verify VERSION() before relying on the generated column; register literal routes before the {id} routes; keep the SDS-update PL-only button but re-scope and relabel it.

## REJECTED_IDEAS

1. Adding manufacturer_id to the aliases table: aliases.customer_code is globally UNIQUE (013:19) and is the ON DUPLICATE KEY target of the CMS upsert (CMSImportService.php:1407-1412); a hand-entered row would collide or be clobbered, and every alias consumer (publishAliases, buildWorkItems, lookupAllAliases, AliasResolver, auto-send, reports, labels) would need a manufacturer_id IS NULL guard. Manufacturer codes live in private_label_items.custom_code instead.
2. Folding private_label_sds into sds_versions: would leak private-label documents into lookup, SDS book, bulk export and auto-send; the isolation is deliberate (PrivateLabelController header comment 16-22).
3. Design 2's pack-stripping of custom_code on save: silently turns 'ACME-500' into 'ACME' for the very field the user asked for. Stored verbatim.
4. Design 2's rejection of custom_code + alias_id together: the 'our code, their wording' case is useful; it is allowed through an explicit 'Borrow description from shared alias' control, and identity_key uses the custom code so uniqueness stays unambiguous.
5. Design 2's DROP + re-ADD of fk_plsds_alias as RESTRICT: mutates a live FK, is not idempotent, and is unnecessary once the version chain is item_id (a SET NULL on a history row no longer re-keys anything). RESTRICT goes on the new registry FK only.
6. Design 2's app-maintained identity_key: any future write path that bypasses the model drifts the UNIQUE. DB-generated STORED column instead (fallback only if VERSION() is too old).
7. Design 2's removal of the /sds-updates private-label button: it is the only FG-level re-brand path after a manufacturer address/logo edit; kept, relabelled 'Republish Private Labels Only' (final call is user decision #1).
8. Design 1's insert-then-throw partial failure in publishItem: inserts rows for the languages that succeeded and then throws, which burns a version number with holes — the very bug in today's generate()/republishPrivateLabel. Replaced by all-or-nothing per item inside a transaction with orphan-PDF unlink.
9. Design 1's alias_id ON DELETE SET NULL on the registry: an item would silently degrade to base identity. RESTRICT.
10. Design 1's PHP-only duplicate check for non-custom items: the generated identity_key ('fg:<id>' / 'alias:<id>') makes the DB UNIQUE cover all three identity kinds.
11. Design 3's own SET FOREIGN_KEY_CHECKS = 0 wrapper and unguarded ALTER in the migration sketch: replaced by guarded statements.
12. A new permission key for 'define items' vs 'republish': reuse 'private_label' (read = browse/download, full = CRUD/publish); add one later only if the user asks.
13. Widening BulkPublishController::computeEligibleFinishedGoods / SDSUpdateController::scan to private-label staleness: PL documents are derived and regenerate whenever the base does; PL-only staleness is handled on the manufacturer page. Keeps the 'updated_at is the staleness signal' logic for FGs untouched.
14. Design 2's phase-2 standalone PL work items in bulk publish (regenerate stale items whose FG is not eligible): deferred — on-create 'Publish now' and the manufacturer page cover the practical cases.
15. aliases.updated_at as a staleness input: a CMS sync could mass-flag items; shared-alias description changes already flow into the next version at publish time.
16. A per-request cap on the inline cascade (Design 2 open question): not implemented; listed as a risk with the mitigation path if latency bites.
17. Making SDSController / SDSUpdateController delegate to PdfBatchRenderer and SDSReadinessService::missingHazardDataError in this change: optional follow-up to keep the diff focused.
18. A dedicated 'PL_' filename prefix / subfolder for private-label PDFs: would change the storage convention the task said to reuse.
19. Embedding the item list directly inside /manufacturers/{id}/edit: the manufacturer form stays a pure identity form; a count + link is enough.

## DECISIONS_NEEDING_USER

1. 1. /sds-updates 'Republish Private Labels' button: KEEP it as 'Republish Private Labels Only' (re-brand after a manufacturer address/logo change without bumping the base SDS) — recommended — or REMOVE it now that 'Republish Selected' cascades (Design 2). Keeping it leaves a double-version path open only if someone clicks PL-only and then Republish Selected on the same FG within minutes; the confirm text warns about it.
2. 2. May one manufacturer carry the same finished good under more than one private-label code (e.g. two brands)? Recommended: ALLOWED (uniqueness is per code / shared alias / base identity, as designed). If 'never', add UNIQUE (manufacturer_id, finished_good_id) and the item form gets simpler.
3. 3. Backfilled registry rows (one per historical manufacturer+FG+alias combo): default ACTIVE + auto-republish — recommended, because regenerating an unwanted document is cheaper than silently missing a required one; rows are tagged in notes for a one-time review on /private-label before the next bulk publish — or default inactive/frozen until someone activates each.
4. 4. Editing an item's code/description: FLAG STALE and wait for the operator (recommended; 'Publish SDS now' checkbox defaults on for create, off for edit, matching how RM edits never auto-publish FGs) — or auto-publish a new version on every identity save.
5. 5. When a private-label render fails during a base SDS publish: WARN AND CONTINUE (recommended; base rows are already committed, the item shows Stale/Never published) — or fail the whole publish so a branding problem blocks the regulatory update.
6. 6. Manual 'Publish now' on an item whose finished good has no published base SDS: REFUSE with 'publish the base SDS first' (recommended; keeps source_fg_version always known for new rows) — or allow from live data with source_fg_version NULL ('Unknown').
7. 7. Explicit out-of-scope confirmations (recommended: leave for a later change): resale (raw-material-based) products cannot have private labels (private_label_sds.finished_good_id is NOT NULL); a manufacturer with no logo keeps inheriting the house logo (SDSGenerator::createManufacturerVariant 513-515 — a one-line change if blank should mean no logo); private_label_sds keeps no soft-delete (retire the item instead).

## RISKS

1. Generated column: no GENERATED column exists anywhere in migrations/ today. It is in-bounds for the engine the schema already assumes (JSON columns since 027 => MariaDB >= 10.2.7 / MySQL >= 5.7.8), but step 1 must confirm VERSION() on production; fallback is an app-maintained identity_key. custom_code MUST be normalised '' -> NULL before every write or the key becomes 'code:' and the UNIQUE misfires.
2. Backfill collision: if legacy history has the same shared alias recorded under two FGs for one manufacturer, INSERT IGNORE skips the second combo and its rows stay item_id NULL (reported by the migration's count and visible as 'unlinked (legacy)' on /private-label/documents); they need a manual fix (create a custom-code item and UPDATE item_id) before they are republished by any cascade.
3. Publish latency: SDSController::publish and SDSUpdateController::republish are synchronous HTTP requests; each active item adds one 4-language parallel PDF render, items sequential. An FG with 10 private-label items adds roughly 10x one render batch to the request. Bulk/cron is unaffected (items spread across workers). If this bites, cap items per request and point the operator to 'Republish stale'.
4. First bulk publish after deploy regenerates every backfilled item for every eligible FG (user decision #3); review /private-label first.
5. Bulk-path holes: a single failed-language PDF in the worker leaves a PL version missing that language (same as base/alias today) while hooks A/B are all-or-nothing; both behaviours are deliberate but must be explained in the Stale tooltip ('Missing ES at v7').
6. No UNIQUE on (item_id, language, version) until 052: two concurrent manual publishes of the same item could still create duplicate version numbers (same exposure as sds_versions today). Run scripts/check-pl-duplicates.php, then add 052.
7. Timezone: new rows use gmdate() UTC; legacy PL rows carry local-time published_at (America/New_York), so staleness reason (c) 'manufacturer changed' can be spuriously true for legacy rows published within ~5h after a manufacturer edit. source_fg_version (primary signal) is immune; backfilled source_fg_version is best-effort so some legacy items show 'Unknown' until republished.
8. Preview semantics change: /private-label/{id}/preview now shows the stored snapshot instead of regenerating; anyone relying on 'preview shows current hazard data' must use the item's live preview link.
9. PDFService.php:136 strips everything after the first '-' for the on-disk FILENAME only; a custom code like 'ACME-500' is printed in full in the document, title and download name but lands on disk as ACME_SDS_en_... (cosmetic; PDFService deliberately untouched).
10. PL PDFs still share public/generated-pdfs with FG PDFs and SdsArchiveController only archives sds_versions files (pre-existing; the storage convention was a constraint).
11. fk_pli_alias RESTRICT will make any future 'delete stale aliases' tooling fail loudly on referenced rows — intended, but whoever builds that must re-point items first.
12. Removing GET /private-label/create and POST /private-label/generate changes a bookmarked workflow; the redirect + flash covers GET bookmarks only.
13. AuthMiddleware now enforces 'private_label' / 'manufacturers' at the middleware layer; confirm no group that actively uses those pages has access 'none' (the controllers already self-checked, so behaviour should be identical).
14. manufacturers.updated_at and private_label_items.updated_at are now staleness inputs: any future code that writes those tables for metadata must use the `updated_at = updated_at` convention or it will flag every item stale.
15. The hazard-data gate runs on manual item publishes but not in the cascade (the FG publish already gated) nor in bulk (never gated) — unchanged policy, worth knowing.
16. Version chain is per item: a second item for the same (manufacturer, FG) under a different code starts its own v1 — History and source_fg_version make the lineage explicit, but an operator expecting one number series per product may be surprised.


## CRITIC RESOLUTIONS (as implemented)

The design critic raised gaps that were resolved as follows before implementation:

- **R1** Staleness rules (b) "identity changed" and (c) "manufacturer changed" compare `private_label_items.updated_at` / `manufacturers.updated_at` against `private_label_sds.created_at` (all DB-written under the same session time zone), never against `published_at`. Backfilled items get `updated_at = MIN(pl.created_at)` so they are not stale on day one.
- **R2** The backfill `INSERT IGNORE ... SELECT` is ordered by `MAX(pl.published_at) DESC` so the most recently published combo wins a uniqueness collision deterministically.
- **R3** `PrivateLabelItem::update()` builds its own SET list so it can append `updated_at = updated_at` for metadata-only edits (notes, retire/restore, auto-republish).
- **R4** Publish-time alias re-validation: an item whose shared alias no longer belongs to its finished good fails with "Shared alias X no longer belongs to Y" on every path (publisher, bulk work items, live preview).
- **R5** `ManufacturerController::delete()` calls `Manufacturer::delete()` before unlinking the logo so a refused delete leaves the logo intact.
- **R6** The hooks in `SDSController::publish` and `SDSUpdateController::republish` wrap the publisher in their own try/catch and only ever add a warning flash.
- **R7** The bulk-publish summary adds `active auto-republish items × languages` to "Total PDFs to Generate".
- **R8** Default preview language is the first configured language, never a hard-coded "en".
- **R9** `custom_code` is stored and printed verbatim; the download filename is `PL_SDS_{code}_{manufacturer}_v{n}_{lang}.pdf` built from the frozen code.
- **R10** `republishItems` skips (with a message) finished goods that are inactive, have no published base SDS, fail `computeBase`, or fail the missing-hazard-data gate.
- **R11** All item CRUD/publish/retire/delete/republish-stale actions require edit access to `private_label`; "Republish Private Labels Only" on SDS Update Required keeps `sds_updates` edit access.
- **R12** The bulk worker sets its display code after the private-label branch so errors name the private-label code.
- **R13** The publisher and the worker's private-label branch stamp `published_at`/`effective_date` with app-local `date()` (matching every legacy row); the worker's `sds_versions` branch keeps `gmdate()`.
- **R14** `publishOne` is all-or-nothing per item: every language is rendered before the transaction opens; any failure unlinks the rendered PDFs and leaves the version number unconsumed.
- **R15** Audit entity types: `private_label_item` (create/update/retire/restore/delete) and `private_label_sds` (publish/download/bulk_publish_private_label).
- **R16** Never-published items carry the tooltip "Not generated by bulk publish until the base SDS is republished — use Publish".
- **R17** Nothing in the change writes `raw_materials.updated_at`, `finished_goods`, `formulas`, or `sds_versions` outside the pre-existing hooks.

## REVIEW FINDINGS FIXED BEFORE COMMIT

Three review rounds (per-file syntax readers, seven lenses, three adversarial verifiers per finding) were run over the change set. Beyond small UI and wording fixes, these were corrected:

- **PDF filename collision (critical).** `PDFService::generate()` named files `{code}_SDS_{lang}_{Ymd_His}.pdf`; the cascade renders a base-identity private label with the same code within the same second as the base SDS and TCPDF overwrites silently. Final form: every publisher stamps `meta.sds_version` before rendering, so a published base/alias SDS is `{code}_v{n}[_{lang}].pdf` and a private-label render is `{code}_PL_{Manufacturer}_v{n}[_{lang}].pdf` (`meta.filename_tag`, set by `SDSGenerator::createManufacturerVariant`, keeps a base-identity private label apart from the base SDS of the same code and version). Unversioned previews keep `{code}[_PL_{Manufacturer}]_SDS_{lang}_{Ymd_His}.pdf`. `PDFService::generate()` reserves its output path atomically (`fopen('x')`), appending `_2`, `_3`, … only if a file with that exact name already exists. No random suffixes, no timestamps in versioned names.
- **Admin Purge Data** now truncates `private_label_sds` and `private_label_items` with the finished goods; otherwise recycled finished-good ids would re-attach old registry rows and the cascade would emit private-label SDSs for the wrong product.
- **Staleness rule (a0):** a row whose `source_fg_version` is not (or no longer) a published base version shows as stale instead of current.
- `SDSUpdateController::republish` now marks queue rows completed inside the try block (a failed republish leaves the row pending) — pre-existing bug.
