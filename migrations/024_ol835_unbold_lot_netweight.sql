-- OL835: Make lot number (with code) and net weight not bold
-- Adds "bold":false to lot_item_code and net_weight fields in the field_layout JSON

UPDATE `label_templates`
SET `field_layout` = REPLACE(
    REPLACE(
        `field_layout`,
        '"lot_item_code":            {"x":0,  "y":0,  "width":25, "height":14, "font_size":7}',
        '"lot_item_code":            {"x":0,  "y":0,  "width":25, "height":14, "font_size":7, "bold":false}'
    ),
    '"net_weight":               {"x":25, "y":0,  "width":25, "height":14, "font_size":7}',
    '"net_weight":               {"x":25, "y":0,  "width":25, "height":14, "font_size":7, "bold":false}'
)
WHERE `name` = 'OL835';

-- Track migration
INSERT INTO `schema_migrations` (`version`) VALUES ('024_ol835_unbold_lot_netweight');
