-- Локальні демонстраційні дані для NexaFlow.
-- Виконувати тільки в базі srm_local.
START TRANSACTION;

SET @demo_owner_id = '345435d7b12156c6e86b9104ae0a0924';

INSERT INTO projects (id, name, description, owner_id, status, visibility)
WITH RECURSIVE numbers(n) AS (
  SELECT 1
  UNION ALL
  SELECT n + 1 FROM numbers WHERE n < 40
)
SELECT
  REPLACE(UUID(), '-', ''),
  CONCAT('Демо проєкт ', LPAD(n, 2, '0')),
  CONCAT('Тестовий проєкт для перевірки списків, фільтрів і навігації — №', n),
  @demo_owner_id,
  CASE MOD(n, 4)
    WHEN 0 THEN 'active'
    WHEN 1 THEN 'active'
    WHEN 2 THEN 'draft'
    ELSE 'paused'
  END,
  CASE WHEN MOD(n, 3) = 0 THEN 'team' ELSE 'private' END
FROM numbers;

INSERT INTO clients (
  id, name, legal_form, tax_system, has_vat, has_excise,
  has_employees, employee_count, special_taxes, module, created_by
)
WITH RECURSIVE numbers(n) AS (
  SELECT 1
  UNION ALL
  SELECT n + 1 FROM numbers WHERE n < 150
)
SELECT
  REPLACE(UUID(), '-', ''),
  CONCAT('Демо клієнт ', LPAD(n, 3, '0')),
  CASE MOD(n, 4)
    WHEN 0 THEN 'ТОВ'
    WHEN 1 THEN 'ФОП'
    WHEN 2 THEN 'ПП'
    ELSE 'АТ'
  END,
  CASE MOD(n, 3)
    WHEN 0 THEN 'Загальна система'
    WHEN 1 THEN 'Єдиний податок, 3 група'
    ELSE 'Єдиний податок, 2 група'
  END,
  IF(MOD(n, 2) = 0, 1, 0),
  IF(MOD(n, 10) = 0, 1, 0),
  IF(MOD(n, 3) <> 0, 1, 0),
  IF(MOD(n, 3) <> 0, 5 + MOD(n, 46), NULL),
  CASE
    WHEN MOD(n, 12) = 0 THEN JSON_ARRAY('Туристичний збір', 'Земельний податок')
    WHEN MOD(n, 7) = 0 THEN JSON_ARRAY('Земельний податок')
    WHEN MOD(n, 5) = 0 THEN JSON_ARRAY('Туристичний збір')
    ELSE JSON_ARRAY()
  END,
  'accounting',
  @demo_owner_id
FROM numbers;

COMMIT;

SELECT 'projects_added' AS metric, 40 AS value
UNION ALL
SELECT 'clients_added', 150;
