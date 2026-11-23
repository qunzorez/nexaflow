-- Online, repeatable index migration for notification retention cleanup.
-- Index creation is in-place and non-blocking on supported MySQL/InnoDB.
SET @has_notification_created_index = (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'notifications'
    AND INDEX_NAME = 'idx_notifications_created_at'
);
SET @has_notifications_table = (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'notifications'
);
SET @create_notification_created_index = IF(
  @has_notifications_table = 1 AND @has_notification_created_index = 0,
  'ALTER TABLE notifications ADD INDEX idx_notifications_created_at (created_at), ALGORITHM=INPLACE, LOCK=NONE',
  'SELECT 1'
);
PREPARE create_notification_created_index_stmt FROM @create_notification_created_index;
EXECUTE create_notification_created_index_stmt;
DEALLOCATE PREPARE create_notification_created_index_stmt;
