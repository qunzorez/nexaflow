-- Локальні тестові менеджери та окремі глобальні claims-групи.
-- Пароль усіх тестових менеджерів: Manager123!
START TRANSACTION;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT IGNORE INTO users
  (id, name, email, password_hash, role, requested_role, email_verified_at)
VALUES
  ('a1000000000000000000000000000001', 'Олена Менеджер', 'olena.manager@nexaflow.local', '$2y$10$SZCQXm/Uu2pPb9aK9A.i.uYIbww2FgkEnTpqEEGWXG0t2kgeAOMze', 'manager', 'manager', NOW()),
  ('a1000000000000000000000000000002', 'Максим Менеджер', 'maksym.manager@nexaflow.local', '$2y$10$SZCQXm/Uu2pPb9aK9A.i.uYIbww2FgkEnTpqEEGWXG0t2kgeAOMze', 'manager', 'manager', NOW()),
  ('a1000000000000000000000000000003', 'Ірина Менеджер', 'iryna.manager@nexaflow.local', '$2y$10$SZCQXm/Uu2pPb9aK9A.i.uYIbww2FgkEnTpqEEGWXG0t2kgeAOMze', 'manager', 'manager', NOW());

INSERT IGNORE INTO project_groups (id, project_id, name, role_name, permissions)
VALUES
  ('b1000000000000000000000000000001', NULL, 'Операційний менеджер', '', '{"version":1,"owner_id":"a1000000000000000000000000000001","claims":["read","update","edit"]}'),
  ('b1000000000000000000000000000002', NULL, 'Керівник проєктів', '', '{"version":1,"owner_id":"a1000000000000000000000000000002","claims":["read","update","edit","delete"]}'),
  ('b1000000000000000000000000000003', NULL, 'Фінансовий модератор', '', '{"version":1,"owner_id":"a1000000000000000000000000000003","claims":["read","edit"]}');

INSERT IGNORE INTO project_group_members (group_id, user_id)
VALUES
  ('b1000000000000000000000000000001', 'a1000000000000000000000000000001'),
  ('b1000000000000000000000000000002', 'a1000000000000000000000000000002'),
  ('b1000000000000000000000000000003', 'a1000000000000000000000000000003');

COMMIT;
