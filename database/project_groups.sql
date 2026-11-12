CREATE TABLE IF NOT EXISTS project_groups (
  id CHAR(32) PRIMARY KEY,
  project_id CHAR(32) NULL,
  name VARCHAR(120) NOT NULL,
  role_name VARCHAR(120) NULL,
  permissions TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_project_groups_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_group_members (
  group_id CHAR(32) NOT NULL,
  user_id CHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (group_id, user_id),
  CONSTRAINT fk_project_group_members_group FOREIGN KEY (group_id) REFERENCES project_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_project_group_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
