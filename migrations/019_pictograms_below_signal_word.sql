-- Move pictograms below signal word in all built-in templates
-- This prevents layout breakage when there are many pictograms

-- OL575WR (Old Big Label): signal word full-width above pictograms
UPDATE `label_templates`
SET `field_layout` = JSON_REPLACE(
    JSON_REPLACE(
        `field_layout`,
        '$.signal_word', JSON_OBJECT('x',0,'y',9,'width',100,'height',6)
    ),
    '$.pictograms', JSON_OBJECT('x',0,'y',15,'width',100,'height',10)
)
WHERE `name` = 'Old Big Label — OL575WR';

-- OL800WX (Small Label): signal word full-width above pictograms
UPDATE `label_templates`
SET `field_layout` = JSON_REPLACE(
    JSON_REPLACE(
        `field_layout`,
        '$.signal_word', JSON_OBJECT('x',0,'y',11,'width',100,'height',6)
    ),
    '$.pictograms', JSON_OBJECT('x',0,'y',17,'width',100,'height',11)
)
WHERE `name` = 'OL800WX';

-- OL2097WR (Big Label): signal word above pictograms in left column
UPDATE `label_templates`
SET `field_layout` = JSON_REPLACE(
    JSON_REPLACE(
        `field_layout`,
        '$.signal_word', JSON_OBJECT('x',0,'y',13,'width',30,'height',7)
    ),
    '$.pictograms', JSON_OBJECT('x',0,'y',20,'width',30,'height',33)
)
WHERE `name` = 'OL2097WR';

-- Track migration
INSERT INTO `schema_migrations` (`version`) VALUES ('019_pictograms_below_signal_word');
