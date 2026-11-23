-- Run after live_migration.sql and before deploying code that expects DATETIME.
-- Existing DATE values become midnight on the same date. On large tables this
-- ALTER may rebuild tasks; run during a maintenance window after a backup.
SET @due_date_type = (
  SELECT DATA_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND COLUMN_NAME = 'due_date'
);
SET @task_due_sql = IF(
  @due_date_type = 'date',
  'ALTER TABLE tasks MODIFY due_date DATETIME NULL',
  'SELECT 1'
);
PREPARE task_due_stmt FROM @task_due_sql;
EXECUTE task_due_stmt;
DEALLOCATE PREPARE task_due_stmt;
