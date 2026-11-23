CREATE TABLE IF NOT EXISTS system_maintenance (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  starts_at DATETIME NULL,
  title VARCHAR(160) NOT NULL DEFAULT 'Технічні роботи',
  message TEXT NOT NULL,
  estimated_end DATETIME NULL,
  note VARCHAR(255) NULL,
  updated_by CHAR(32) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_maintenance_updated_by (updated_by),
  CONSTRAINT fk_maintenance_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO system_maintenance (id, enabled, title, message)
VALUES (1, 0, 'Технічні роботи', 'Ми оновлюємо NexaFlow. Спробуйте зайти трохи пізніше.');

-- Existing installations: add the column only if it is missing.
SET @maintenance_column_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_maintenance' AND COLUMN_NAME = 'starts_at');
SET @maintenance_sql := IF(@maintenance_column_exists = 0, 'ALTER TABLE system_maintenance ADD COLUMN starts_at DATETIME NULL AFTER enabled', 'SELECT 1');
PREPARE maintenance_stmt FROM @maintenance_sql;
EXECUTE maintenance_stmt;
DEALLOCATE PREPARE maintenance_stmt;
