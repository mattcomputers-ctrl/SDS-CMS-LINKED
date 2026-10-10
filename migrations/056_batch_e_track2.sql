-- 056: batch E — track T2
SET NAMES utf8mb4;

-- ============================================================
-- T2a (batch E): hazard data — findings #19, #20, #21, #22, #39
-- Data-only, re-runnable. The one-time staleness bump is guarded by
-- this file's own schema_migrations row (054 pattern): the version
-- string below must equal this file's basename.
-- ============================================================
SET @m056_t2a_done = (SELECT COUNT(*) FROM `schema_migrations`
    WHERE `version` = '056_batch_e_track2');

-- T2a-1 One-time bump of raw_materials.updated_at (bulk-publish staleness
-- signal) for every raw whose sheet text changes. This runs BEFORE the data
-- fixes so the WHERE clauses still see the old values:
--   (a) a constituent CAS with current PubChem hazard rows (#19 per-class
--       codes, #20 class/category display and consolidation);
--   (b) a constituent CAS with an IARC Group 3 row (#21 Section 11 text);
--   (c) 75-07-0 / 2475-45-8 while their IARC row is not Group 2B (#22);
--   (d) P281 in the raw's own trade-secret hazard JSON, or in an active
--       CPD of a constituent CAS (#39).
-- Content change => UTC_TIMESTAMP() (RegulatoryListBumper / 054 convention).
SET @sql = IF(@m056_t2a_done = 0,
    'UPDATE `raw_materials` rm SET rm.`updated_at` = UTC_TIMESTAMP()
      WHERE (rm.`manual_hazard_json` IS NOT NULL AND CAST(rm.`manual_hazard_json` AS CHAR) LIKE ''%P281%'')
         OR EXISTS (SELECT 1 FROM `raw_material_constituents` rmc
                     WHERE rmc.`raw_material_id` = rm.`id`
                       AND (EXISTS (SELECT 1 FROM `hazard_classifications` hc
                                      JOIN `hazard_source_records` hsr ON hsr.`id` = hc.`hazard_source_record_id`
                                     WHERE hc.`cas_number` = rmc.`cas_number`
                                       AND hsr.`is_current` = 1 AND hsr.`source_name` = ''PubChem'')
                         OR EXISTS (SELECT 1 FROM `carcinogen_list` cl
                                     WHERE cl.`cas_number` = rmc.`cas_number` AND cl.`agency` = ''IARC''
                                       AND (cl.`classification` IN (''Group 3'', ''3'')
                                            OR (cl.`cas_number` IN (''75-07-0'', ''2475-45-8'') AND cl.`classification` <> ''Group 2B'')))
                         OR EXISTS (SELECT 1 FROM `competent_person_determinations` cpd
                                     WHERE cpd.`cas_number` = rmc.`cas_number` AND cpd.`is_active` = 1
                                       AND CAST(cpd.`determination_json` AS CHAR) LIKE ''%P281%'')))',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
SET @m056_t2a_n = ROW_COUNT();
DEALLOCATE PREPARE stmt;

SET @sql = IF(@m056_t2a_done = 0,
    'INSERT IGNORE INTO `settings` (`key`, `value`) VALUES (''sds.migration.056.t2a_bump_count'', @m056_t2a_n)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- T2a-2 (#20) Bare PubChem category tokens -> 'Category N' (display) and
-- 'Cat N' (canonical, where not yet backfilled). Re-runnable: the
-- REGEXPs no longer match after the first run. class_name_canonical is
-- not SQL-derivable (alias table in PHP): run
-- scripts/backfill-canonical-class-names.php --force (checklist 3.7b).
UPDATE `hazard_classifications`
   SET `category` = CONCAT('Category ', UPPER(`category`))
 WHERE `category` REGEXP '^[0-9]+[A-Ca-c]?$';

UPDATE `hazard_classifications`
   SET `category_canonical` = CONCAT('Cat ', UPPER(SUBSTRING(`category`, 10)))
 WHERE `category` REGEXP '^Category [0-9]+[A-Ca-c]?$'
   AND (`category_canonical` IS NULL OR `category_canonical` = '' OR `category_canonical` REGEXP '^[0-9]+[A-Ca-c]?$');

-- T2a-3 (#22) The seed loader upserted on (cas_number, agency), so the
-- last CSV row won: context-specific "Group 1" rows replaced
-- acetaldehyde's and Disperse Blue 1's IARC Group 2B. Restore the seed's
-- own rows (storage/data/seed/carcinogens.csv, now one row per pair).
UPDATE `carcinogen_list`
   SET `chemical_name`  = 'Acetaldehyde',
       `classification` = 'Group 2B',
       `description`    = 'Possibly carcinogenic to humans based on inadequate human evidence',
       `source_ref`     = 'IARC carcinogen listing (seed data)'
 WHERE `cas_number` = '75-07-0' AND `agency` = 'IARC' AND `classification` <> 'Group 2B';

UPDATE `carcinogen_list`
   SET `chemical_name`  = 'Disperse Blue 1 (1,4,5,8-tetraaminoanthraquinone)',
       `classification` = 'Group 2B',
       `description`    = 'Possibly carcinogenic to humans based on sufficient animal evidence',
       `source_ref`     = 'IARC carcinogen listing (seed data)'
 WHERE `cas_number` = '2475-45-8' AND `agency` = 'IARC' AND `classification` <> 'Group 2B';

-- (#39 needs no data rewrite: HazardEngine::classify maps P281 -> P280 at
-- run time for PubChem, CPD, trade-secret and FG-override data.)

-- ============================================================
-- (end of T2a block; later T2 units append above the footer)
-- ============================================================

-- (file footer, exactly once per file)
INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('056_batch_e_track2');
