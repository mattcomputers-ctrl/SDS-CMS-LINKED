-- Add a product-identification "color block" field to the built-in label
-- templates. The block is a small filled square (or a black outline when
-- "No Color" is chosen) that shipping and production use to identify a
-- product at a glance. Position is stored in field_layout like every other
-- field, so it can be repositioned in the template editor.
--
-- JSON_MERGE_PATCH preserves each template's other field properties while
-- adding color_block and narrowing the neighbouring field so the two don't
-- overlap. Templates without a color_block field fall back to a default
-- top-right block at render time.
--
-- NOTE ON NAMES: migration 018 renamed the original 'OL575WR' to
-- 'Old Big Label — OL575WR' and added 'OL2097WR' as the new default Big
-- Label, so both of those names are matched below (not 'OL575WR').

-- OL2097WR (current default, 6"x2" wrap-around, 1x5): narrow net weight and
-- place the block in the top-right corner clear of it.
UPDATE `label_templates`
SET `field_layout` = JSON_MERGE_PATCH(
    `field_layout`,
    '{"net_weight": {"width": 33}, "color_block": {"x": 92, "y": 0, "width": 7, "height": 12}}'
)
WHERE `name` = 'OL2097WR';

-- Old Big Label — OL575WR (2x4): narrow net weight, block in the top-right.
UPDATE `label_templates`
SET `field_layout` = JSON_MERGE_PATCH(
    `field_layout`,
    '{"net_weight": {"width": 19}, "color_block": {"x": 90, "y": 1, "width": 9, "height": 12}}'
)
WHERE `name` = 'Old Big Label — OL575WR';

-- OL800WX (small, 3x6): same treatment, block sized for the shorter label.
UPDATE `label_templates`
SET `field_layout` = JSON_MERGE_PATCH(
    `field_layout`,
    '{"net_weight": {"width": 19}, "color_block": {"x": 90, "y": 1, "width": 9, "height": 15}}'
)
WHERE `name` = 'OL800WX';

-- OL835 (wrap-around, 1x6): the top band is full width, so shift the
-- right-aligned supplier block left and drop the color block between the
-- net weight and supplier fields.
UPDATE `label_templates`
SET `field_layout` = JSON_MERGE_PATCH(
    `field_layout`,
    '{"supplier_info": {"x": 58, "width": 42}, "color_block": {"x": 50, "y": 0, "width": 7, "height": 14}}'
)
WHERE `name` = 'OL835';

-- Track migration
INSERT INTO `schema_migrations` (`version`) VALUES ('047_add_label_color_block');
