-- Administrative audit trail for NexaFlow. Safe to run repeatedly.
CREATE TABLE IF NOT EXISTS admin_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_id CHAR(32) NOT NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(50) NOT NULL,
  entity_id VARCHAR(64) NOT NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  reason VARCHAR(500) NULL,
  result VARCHAR(20) NOT NULL DEFAULT 'success',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_admin_audit_created (created_at),
  INDEX idx_admin_audit_entity (entity_type, entity_id),
  INDEX idx_admin_audit_actor (actor_id),
  CONSTRAINT fk_admin_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
