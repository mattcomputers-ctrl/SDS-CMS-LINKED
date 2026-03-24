-- OL835: Make lot number (with code) and net weight not bold
-- Adds "bold":false to lot_item_code and net_weight fields in the field_layout JSON

UPDATE `label_templates`
SET `field_layout` = JSON_SET(
    JSON_SET(`field_layout`, '$.lot_item_code.bold', CAST('false' AS JSON)),
    '$.net_weight.bold', CAST('false' AS JSON)
)
WHERE `name` = 'OL835';

-- Track migration
INSERT INTO `schema_migrations` (`version`) VALUES ('024_ol835_unbold_lot_netweight');
