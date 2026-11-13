-- Normalize the legacy typo used by older local/demo groups.
UPDATE project_groups
SET name = 'Бухгалтер'
WHERE name = 'Бугалтер';
