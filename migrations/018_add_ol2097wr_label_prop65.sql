-- Rename OL575WR to "Old Big Label" and add OL2097WR as the new default "Big Label"
-- Also adds prop65_warning field type support to the label system

-- Remove default status from OL575WR and rename it
UPDATE `label_templates`
SET `name` = 'Old Big Label — OL575WR',
    `description` = 'Old Big — 3.75" x 2.4375", 8 per sheet (2 cols x 4 rows)',
    `is_default` = 0
WHERE `name` = 'OL575WR';

-- Insert OL2097WR as the new Big Label (default)
-- 6" x 2" wrap-around labels, 5 per sheet (1 col x 5 rows)
-- Layout designed for easy lot#/net weight readability and Prop65 compliance
INSERT INTO `label_templates` (`name`, `description`, `label_width`, `label_height`, `cols`, `rows`, `margin_left`, `margin_top`, `h_spacing`, `v_spacing`, `default_font_size`, `field_layout`, `is_default`) VALUES
(
    'OL2097WR',
    'Big Label — 6" x 2", 5 per sheet (1 col x 5 rows), wrap-around',
    152.4, 50.8, 1, 5, 31.75, 12.7, 0, 0, 7.0,
    '{
        "lot_item_code":          {"x":0,  "y":0,  "width":58, "height":12, "font_size":11},
        "net_weight":             {"x":58, "y":0,  "width":42, "height":12, "font_size":11},
        "signal_word":            {"x":0,  "y":13, "width":30, "height":7},
        "pictograms":             {"x":0,  "y":20, "width":30, "height":33},
        "hazard_statements":      {"x":31, "y":13, "width":34, "height":40},
        "precautionary_statements":{"x":66,"y":13, "width":34, "height":40},
        "prop65_warning":         {"x":0,  "y":54, "width":100,"height":24},
        "supplier_info":          {"x":0,  "y":79, "width":100,"height":12}
    }',
    1
);

-- Track migration
INSERT INTO `schema_migrations` (`version`) VALUES ('018_add_ol2097wr_label_prop65');
