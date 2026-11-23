-- Normalize the legacy typo used by older local/demo groups.
UPDATE project_groups
SET name = 'Бухгалтер'
WHERE name = 'Бугалтер';

-- Repair legacy double-encoded UTF-8 labels once instead of on every request.
UPDATE project_groups
SET name = 'Бухгалтер'
WHERE project_id IS NULL
  AND (HEX(name) LIKE 'C390C291C391C283C391C285C390C2B3C390C2B0C390C2BB%'
    OR HEX(name) LIKE 'C390C2B1C391C283C391C285C390C2B3C390C2B0C390C2BB%');
