-- Add OL835 label template from onlinelabels.com
-- 6" x 1.5" wrap-around labels, 6 per sheet (1 col x 6 rows)
-- Layout: LOT and Net Wt at 7pt on the left, manufacturer info top-right aligned

INSERT INTO `label_templates` (`name`, `description`, `label_width`, `label_height`, `cols`, `rows`, `margin_left`, `margin_top`, `h_spacing`, `v_spacing`, `default_font_size`, `field_layout`, `is_default`) VALUES
(
    'OL835',
    'Wrap-around — 6" x 1.5", 6 per sheet (1 col x 6 rows)',
    152.4, 38.1, 1, 6, 31.75, 17.4625, 0, 3.175, 7.0,
    '{
        "lot_item_code":            {"x":0,  "y":0,  "width":25, "height":14, "font_size":7},
        "net_weight":               {"x":25, "y":0,  "width":25, "height":14, "font_size":7},
        "supplier_info":            {"x":50, "y":0,  "width":50, "height":14, "font_size":6, "align":"R", "divider_top":false},
        "signal_word":              {"x":0,  "y":15, "width":22, "height":8},
        "pictograms":               {"x":0,  "y":23, "width":22, "height":35},
        "hazard_statements":        {"x":23, "y":15, "width":38, "height":43},
        "precautionary_statements": {"x":62, "y":15, "width":38, "height":43},
        "prop65_warning":           {"x":0,  "y":59, "width":100,"height":22}
    }',
    0
);

-- Track migration
INSERT INTO `schema_migrations` (`version`) VALUES ('023_add_ol835_label_template');
