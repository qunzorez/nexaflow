CREATE TABLE IF NOT EXISTS user_invitations (
  id CHAR(32) PRIMARY KEY,
  invited_by CHAR(32) NOT NULL,
  email VARCHAR(190) NOT NULL,
  role VARCHAR(30) NOT NULL DEFAULT 'user',
  group_id CHAR(32) NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  expires_at DATETIME NOT NULL,
  accepted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_invitations_inviter FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
