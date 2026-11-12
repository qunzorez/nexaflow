-- NexaFlow: ролі користувачів і ролі в проєктах.
-- Виконайте цей файл у phpMyAdmin для бази nexaflow_srm.

CREATE TABLE IF NOT EXISTS projects (
  id CHAR(32) PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  owner_id CHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_projects_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_members (
  project_id CHAR(32) NOT NULL,
  user_id CHAR(32) NOT NULL,
  role VARCHAR(30) NOT NULL DEFAULT 'member',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (project_id, user_id),
  CONSTRAINT fk_project_members_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_project_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_project_members_user ON project_members(user_id);

-- Після першої реєстрації власника системи призначте йому адміністратора:
-- UPDATE users SET role = 'admin' WHERE email = 'ваша-пошта@example.com';
