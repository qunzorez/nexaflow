-- One-time migration for account blocking. Run after live_migration.sql.
ALTER TABLE users
  ADD COLUMN is_blocked TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN blocked_at DATETIME NULL,
  ADD COLUMN blocked_reason VARCHAR(500) NULL;

CREATE TABLE IF NOT EXISTS auth_event_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id CHAR(32) NULL,
  email VARCHAR(190) NULL,
  event_type VARCHAR(40) NOT NULL,
  result VARCHAR(20) NOT NULL,
  source VARCHAR(40) NOT NULL DEFAULT 'web',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_auth_event_created (created_at),
  INDEX idx_auth_event_user (user_id, created_at),
  INDEX idx_auth_event_type (event_type, result),
  CONSTRAINT fk_auth_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_access_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id CHAR(32) NULL,
  email VARCHAR(190) NULL,
  path VARCHAR(190) NOT NULL DEFAULT '/admin',
  result VARCHAR(20) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_admin_access_created (created_at),
  INDEX idx_admin_access_user (user_id, created_at),
  CONSTRAINT fk_admin_access_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
