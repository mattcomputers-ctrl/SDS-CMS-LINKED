-- ============================================================
-- Migration 012: Allow finished goods as formula line components
--
-- Adds finished_good_component_id to formula_lines so a formula
-- can reference another finished good (sub-assembly) in addition
-- to raw materials. Each line must have exactly one of
-- raw_material_id or finished_good_component_id set.
-- ============================================================

-- Make raw_material_id nullable (lines can now be FG references instead)
ALTER TABLE `formula_lines`
    MODIFY `raw_material_id` INT UNSIGNED NULL;

-- Add finished good component reference
ALTER TABLE `formula_lines`
    ADD COLUMN `finished_good_component_id` INT UNSIGNED NULL AFTER `raw_material_id`,
    ADD INDEX `idx_fl_fg_component` (`finished_good_component_id`),
    ADD CONSTRAINT `fk_fl_fg_component`
        FOREIGN KEY (`finished_good_component_id`)
        REFERENCES `finished_goods`(`id`) ON DELETE RESTRICT;

INSERT INTO `schema_migrations` (`version`) VALUES ('012_add_finished_good_formula_lines');
