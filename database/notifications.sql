-- NexaFlow: внутрішні сповіщення користувачів.
CREATE TABLE IF NOT EXISTS notifications (
  id CHAR(32) PRIMARY KEY,
  user_id CHAR(32) NOT NULL,
  sender_id CHAR(32) NULL,
  type VARCHAR(40) NOT NULL DEFAULT 'system',
  title VARCHAR(180) NOT NULL,
  message TEXT NOT NULL,
  content_html TEXT NULL,
  entity_type VARCHAR(40) NULL,
  entity_id CHAR(32) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_notifications_user_created ON notifications(user_id, created_at);
CREATE INDEX idx_notifications_user_read ON notifications(user_id, is_read);
