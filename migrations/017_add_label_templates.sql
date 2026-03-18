-- Label Templates: user-configurable label layouts with drag-and-drop field placement
CREATE TABLE IF NOT EXISTS `label_templates` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(100) NOT NULL,
    `description`   VARCHAR(255) NOT NULL DEFAULT '',

    -- Physical label dimensions (in mm)
    `label_width`   DECIMAL(8,4) NOT NULL,
    `label_height`  DECIMAL(8,4) NOT NULL,

    -- Sheet layout
    `cols`          TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `rows`          TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `margin_left`   DECIMAL(8,4) NOT NULL DEFAULT 0,
    `margin_top`    DECIMAL(8,4) NOT NULL DEFAULT 0,
    `h_spacing`     DECIMAL(8,4) NOT NULL DEFAULT 0,
    `v_spacing`     DECIMAL(8,4) NOT NULL DEFAULT 0,

    -- Default font size (pt) — the system auto-shrinks from this
    `default_font_size` DECIMAL(4,1) NOT NULL DEFAULT 7.0,

    -- Field positions stored as JSON
    -- Each field: { x: %, y: %, width: %, height: % } as percentages of label area
    `field_layout`  JSON NOT NULL,

    `is_default`    TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert the two built-in templates
INSERT INTO `label_templates` (`name`, `description`, `label_width`, `label_height`, `cols`, `rows`, `margin_left`, `margin_top`, `h_spacing`, `v_spacing`, `default_font_size`, `field_layout`, `is_default`) VALUES
(
    'OL575WR',
    'Big — 3.75\" x 2.4375\", 8 per sheet (2 cols x 4 rows)',
    95.25, 61.9125, 2, 4, 11.1125, 11.1125, 3.175, 3.175, 7.0,
    '{"lot_item_code":{"x":0,"y":0,"width":100,"height":8},"signal_word":{"x":0,"y":9,"width":100,"height":6},"pictograms":{"x":0,"y":15,"width":100,"height":10},"hazard_statements":{"x":0,"y":25,"width":100,"height":30},"precautionary_statements":{"x":0,"y":56,"width":100,"height":30},"net_weight":{"x":70,"y":0,"width":30,"height":8},"supplier_info":{"x":0,"y":87,"width":100,"height":13}}',
    1
),
(
    'OL800WX',
    'Small — 2.5\" x 1.5625\", 18 per sheet (3 cols x 6 rows)',
    63.5, 39.6875, 3, 6, 9.525, 12.7, 3.175, 3.175, 5.0,
    '{"lot_item_code":{"x":0,"y":0,"width":100,"height":10},"signal_word":{"x":0,"y":11,"width":100,"height":6},"pictograms":{"x":0,"y":17,"width":100,"height":11},"hazard_statements":{"x":0,"y":28,"width":100,"height":28},"precautionary_statements":{"x":0,"y":57,"width":100,"height":28},"net_weight":{"x":70,"y":0,"width":30,"height":10},"supplier_info":{"x":0,"y":86,"width":100,"height":14}}',
    0
);

-- Track migration
INSERT INTO `schema_migrations` (`version`) VALUES ('017_add_label_templates');
