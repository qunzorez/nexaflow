-- Виконайте один раз у локальній базі srm_local та на production.
ALTER TABLE projects ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'active';
ALTER TABLE projects ADD COLUMN visibility VARCHAR(30) NOT NULL DEFAULT 'private';
ALTER TABLE project_groups ADD COLUMN role_name VARCHAR(120) NULL;
ALTER TABLE project_groups ADD COLUMN permissions TEXT NULL;
ALTER TABLE project_invitations ADD COLUMN group_id CHAR(32) NULL;
ALTER TABLE project_groups MODIFY COLUMN project_id CHAR(32) NULL;
ALTER TABLE user_invitations ADD COLUMN group_id CHAR(32) NULL;
