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
  due_date DATETIME NULL,
  reminder_at DATETIME NULL,
  creator_reminder TINYINT(1) NOT NULL DEFAULT 0,
  recurrence_rule VARCHAR(20) NOT NULL DEFAULT 'none',
  recurrence_parent_id CHAR(32) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tasks_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
  CONSTRAINT fk_tasks_assignee FOREIGN KEY (assignee_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_tasks_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
  ,CONSTRAINT fk_tasks_recurrence_parent FOREIGN KEY (recurrence_parent_id) REFERENCES tasks(id) ON DELETE SET NULL
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

CREATE TABLE IF NOT EXISTS time_entries (
  id CHAR(32) PRIMARY KEY,
  task_id CHAR(32) NOT NULL,
  user_id CHAR(32) NOT NULL,
  minutes INT UNSIGNED NOT NULL,
  note VARCHAR(500) NULL,
  logged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_time_entries_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_time_entries_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
