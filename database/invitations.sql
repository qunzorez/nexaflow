-- NexaFlow: запрошення користувачів у конкретні проєкти.
CREATE TABLE IF NOT EXISTS project_invitations (
  id CHAR(32) PRIMARY KEY,
  project_id CHAR(32) NOT NULL,
  invited_by CHAR(32) NOT NULL,
  email VARCHAR(190) NOT NULL,
  role VARCHAR(30) NOT NULL DEFAULT 'member',
  group_id CHAR(32) NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  expires_at TIMESTAMP NOT NULL,
  accepted_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_invitations_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_invitations_inviter FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_invitations_email ON project_invitations(email);
CREATE INDEX idx_invitations_project ON project_invitations(project_id);
