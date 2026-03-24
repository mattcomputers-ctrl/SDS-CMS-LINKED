-- Migration 020: Add performance indexes to the aliases table.
--
-- These indexes improve query performance for:
--   1. Search queries that filter on description (AliasController::index)
--   2. The lookupAllAliases GROUP BY on the base customer code prefix
--   3. Covering index for the CSV upsert lookup pattern

SET NAMES utf8mb4;

-- Index for description search (LIKE queries in alias listing)
ALTER TABLE `aliases`
    ADD INDEX `idx_alias_description` (`description`(100));

-- Index for updated_at to support keyset pagination
ALTER TABLE `aliases`
    ADD INDEX `idx_alias_updated_at` (`updated_at`);

-- Composite index for the sort+paginate pattern (customer_code ASC with id)
-- Supports keyset pagination: WHERE (customer_code, id) > (?, ?)
ALTER TABLE `aliases`
    ADD INDEX `idx_alias_keyset_cc` (`customer_code`, `id`);

-- ============================================================
-- Record this migration
-- ============================================================
INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('020_add_aliases_performance_indexes');
