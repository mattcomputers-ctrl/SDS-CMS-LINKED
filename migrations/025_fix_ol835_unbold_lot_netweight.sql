-- Fix OL835: Ensure lot_item_code and net_weight have bold:false
-- Re-applies the change in case migration 024 failed on the UPDATE but was tracked

UPDATE `label_templates`
SET `field_layout` = JSON_MERGE_PATCH(
    `field_layout`,
    '{"lot_item_code": {"bold": false}, "net_weight": {"bold": false}}'
)
WHERE `name` = 'OL835';

-- Track migration
INSERT INTO `schema_migrations` (`version`) VALUES ('025_fix_ol835_unbold_lot_netweight');
