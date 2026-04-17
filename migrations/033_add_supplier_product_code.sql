-- ============================================================
-- Migration 033: Supplier Product Code column on raw_materials
-- Stores the "Their Code" value from CMS PriceDetail
-- (preferred-vendor price version).
-- ============================================================

ALTER TABLE `raw_materials`
    ADD COLUMN `supplier_product_code` VARCHAR(100) NULL AFTER `supplier_product_name`;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('033_add_supplier_product_code');
