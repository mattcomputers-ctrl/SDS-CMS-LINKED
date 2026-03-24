-- OSHA requires minimum 6pt font on GHS labels.
-- Bump OL800WX default from 5pt to 7pt (was below minimum).
-- Increase OL2097WR vertical spacing by 0.1" (2.54mm).

UPDATE `label_templates`
SET `default_font_size` = 7.0
WHERE `name` = 'OL800WX'
  AND `default_font_size` = 5.0;

UPDATE `label_templates`
SET `v_spacing` = `v_spacing` + 2.54
WHERE `name` = 'OL2097WR';

-- Track migration
INSERT INTO `schema_migrations` (`version`) VALUES ('022_osha_6pt_minimum_font');
