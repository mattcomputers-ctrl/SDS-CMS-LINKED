-- OL835: Revert lot_item_code and net_weight back to bold (remove bold:false)

UPDATE `label_templates`
SET `field_layout` = JSON_MERGE_PATCH(
    `field_layout`,
    '{"lot_item_code": {"bold": true}, "net_weight": {"bold": true}}'
)
WHERE `name` = 'OL835';

-- Track migration
INSERT INTO `schema_migrations` (`version`) VALUES ('026_ol835_rebold_lot_netweight');
