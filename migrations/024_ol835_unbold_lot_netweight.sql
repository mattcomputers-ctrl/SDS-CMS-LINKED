-- OL835: Make lot number (with code) and net weight not bold
-- Uses JSON_MERGE_PATCH to add "bold":false to both fields while preserving all other properties

UPDATE `label_templates`
SET `field_layout` = JSON_MERGE_PATCH(
    `field_layout`,
    '{"lot_item_code": {"bold": false}, "net_weight": {"bold": false}}'
)
WHERE `name` = 'OL835';

-- Track migration
INSERT INTO `schema_migrations` (`version`) VALUES ('024_ol835_unbold_lot_netweight');
