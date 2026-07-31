-- ============================================================
-- Migration 049: Canonical CAS descriptions
-- cas_master.preferred_name becomes the single source of truth
-- for CAS descriptions (with prop65_list.chemical_name taking
-- precedence for Prop 65 listed substances). Backfills missing
-- registry entries from the most common constituent name, then
-- propagates the canonical name into every constituent row so
-- all appearances of a CAS share one description.
-- ============================================================

-- 1. Backfill cas_master with the most common constituent name
--    for CAS numbers not yet in the registry (or with empty names).
INSERT INTO cas_master (cas_number, preferred_name)
SELECT t.cas_number,
       SUBSTRING_INDEX(GROUP_CONCAT(t.chemical_name ORDER BY t.cnt DESC SEPARATOR '\n'), '\n', 1)
FROM (
    SELECT cas_number, chemical_name, COUNT(*) AS cnt
    FROM raw_material_constituents
    WHERE cas_number != '' AND chemical_name != ''
    GROUP BY cas_number, chemical_name
) t
GROUP BY t.cas_number
ON DUPLICATE KEY UPDATE preferred_name = IF(preferred_name IS NULL OR preferred_name = '', VALUES(preferred_name), preferred_name);

-- 2. Propagate the canonical description into all constituent rows.
--    Prop 65 name wins, then the registry name.
UPDATE raw_material_constituents rmc
LEFT JOIN prop65_list p ON p.cas_number = rmc.cas_number AND p.chemical_name != ''
LEFT JOIN cas_master cm ON cm.cas_number = rmc.cas_number
    AND cm.preferred_name IS NOT NULL AND cm.preferred_name != ''
SET rmc.chemical_name = COALESCE(p.chemical_name, cm.preferred_name, rmc.chemical_name)
WHERE rmc.cas_number != '';

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('049_canonical_cas_descriptions');
