CREATE TABLE IF NOT EXISTS system_module_settings (
  module_key VARCHAR(32) NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_by CHAR(32) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_system_module_enabled (enabled),
  CONSTRAINT fk_system_module_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO system_module_settings (module_key, enabled) VALUES
  ('accounting', 1), ('dentistry', 1)
ON DUPLICATE KEY UPDATE module_key = VALUES(module_key);
