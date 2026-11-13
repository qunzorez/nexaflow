-- NexaFlow production bootstrap/migration for MySQL 5.7+/MariaDB.
-- Safe to run repeatedly: every table is created only when it is missing.
-- Run this file in the live NexaFlow database before publishing the PHP files.

CREATE TABLE IF NOT EXISTS users (
  id CHAR(32) PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  avatar_url MEDIUMTEXT NULL,
  phone VARCHAR(40) NULL,
  telegram_tag VARCHAR(80) NULL,
  telegram_chat_id VARCHAR(80) NULL,
  telegram_notifications_enabled TINYINT(1) NOT NULL DEFAULT 0,
  bio TEXT NULL,
  email_notifications_enabled TINYINT(1) NOT NULL DEFAULT 0,
  role VARCHAR(30) NOT NULL DEFAULT 'user',
  requested_role VARCHAR(30) NOT NULL DEFAULT 'user',
  email_verified_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_seen_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_verification_tokens (
  id CHAR(32) PRIMARY KEY,
  user_id CHAR(32) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  verified_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_email_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id CHAR(32) PRIMARY KEY,
  user_id CHAR(32) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projects (
  id CHAR(32) PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  owner_id CHAR(32) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  visibility VARCHAR(30) NOT NULL DEFAULT 'private',
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

CREATE TABLE IF NOT EXISTS tasks (
  id CHAR(32) PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  description MEDIUMTEXT NULL,
  client_name VARCHAR(180) NULL,
  project_id CHAR(32) NULL,
  assignee_id CHAR(32) NULL,
  created_by CHAR(32) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'todo',
  priority VARCHAR(20) NOT NULL DEFAULT 'normal',
  due_date DATE NULL,
  reminder_at DATETIME NULL,
  creator_reminder TINYINT(1) NOT NULL DEFAULT 0,
  recurrence_rule VARCHAR(20) NOT NULL DEFAULT 'none',
  recurrence_parent_id CHAR(32) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tasks_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
  CONSTRAINT fk_tasks_assignee FOREIGN KEY (assignee_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_tasks_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_tasks_recurrence_parent FOREIGN KEY (recurrence_parent_id) REFERENCES tasks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_projects (
  task_id CHAR(32) NOT NULL,
  project_id CHAR(32) NOT NULL,
  PRIMARY KEY (task_id, project_id),
  CONSTRAINT fk_task_projects_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_task_projects_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_assignees (
  task_id CHAR(32) NOT NULL,
  user_id CHAR(32) NOT NULL,
  PRIMARY KEY (task_id, user_id),
  CONSTRAINT fk_task_assignees_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_task_assignees_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_clients (
  task_id CHAR(32) NOT NULL,
  client_id CHAR(32) NOT NULL,
  PRIMARY KEY (task_id, client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_history (
  id CHAR(32) PRIMARY KEY,
  task_id CHAR(32) NOT NULL,
  user_id CHAR(32) NULL,
  action VARCHAR(40) NOT NULL DEFAULT 'updated',
  message TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_task_history_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_task_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_attachments (
  id CHAR(32) PRIMARY KEY,
  task_id CHAR(32) NOT NULL,
  user_id CHAR(32) NULL,
  file_name VARCHAR(180) NOT NULL,
  file_url TEXT NOT NULL,
  mime_type VARCHAR(100) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_task_attachments_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_task_attachments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id CHAR(32) PRIMARY KEY,
  user_id CHAR(32) NOT NULL,
  sender_id CHAR(32) NULL,
  type VARCHAR(40) NOT NULL DEFAULT 'system',
  title VARCHAR(180) NOT NULL,
  message TEXT NOT NULL,
  content_html TEXT NULL,
  is_banner TINYINT(1) NOT NULL DEFAULT 0,
  banner_dismissed_at DATETIME NULL,
  banner_view_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  telegram_sent_at DATETIME NULL,
  email_sent_at DATETIME NULL,
  entity_type VARCHAR(40) NULL,
  entity_id CHAR(32) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clients (
  id CHAR(32) PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  legal_form VARCHAR(40) NOT NULL,
  tax_system VARCHAR(80) NOT NULL,
  has_vat TINYINT(1) NOT NULL DEFAULT 0,
  has_excise TINYINT(1) NOT NULL DEFAULT 0,
  has_employees TINYINT(1) NOT NULL DEFAULT 0,
  employee_count INT NULL,
  special_taxes TEXT NULL,
  services TEXT NULL,
  module VARCHAR(40) NOT NULL DEFAULT 'accounting',
  module_data TEXT NULL,
  created_by CHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_clients_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_members (
  client_id CHAR(32) NOT NULL,
  user_id CHAR(32) NOT NULL,
  assigned_by CHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (client_id, user_id),
  INDEX idx_client_members_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
