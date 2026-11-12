-- Тестові користувачі NexaFlow, скопійовані з локальної бази.
-- Паролі не зберігаються у відкритому вигляді: нижче тільки password_hash.
-- Виконайте після auth.sql у базі nexaflow_srm.

INSERT IGNORE INTO users (id, name, email, password_hash, role, created_at, updated_at) VALUES
('345435d7b12156c6e86b9104ae0a0924', 'Admin', 'admin@gmail.com', '$2y$10$8z7oGr1PlVCztxmtmYALt.EvQD6dIC1mgm5vJriaf7agT9jTDYasW', 'admin', '2026-11-12 10:30:42', '2026-11-12 10:44:55'),
('900d5c0da48383ee16242862b64eb5a6', 'Test Member', 'test.member@nexaflow.local', '$2y$10$b6OpOhw6riTaPUfq3FDW9eMZqqZ7Uwk.nK/AVrXLksZ/OhQsMxoBC', 'user', '2026-11-12 10:50:09', '2026-11-12 10:50:09'),
('bdb60cd4e70c3712c93da97570c4444b', 'Test Admin', 'test.admin@nexaflow.local', '$2y$10$4jCN.q4.JUXrQ7r/UUDc9.dO7evLDwB4piVC/hPFwqGT6IZq6Oro2', 'admin', '2026-11-12 10:50:09', '2026-11-12 10:50:09'),
('d2cb205cc9447c8118ab9764a405916d', 'Test Viewer', 'test.viewer@nexaflow.local', '$2y$10$UJw4QmVBNIAiENk8lrXEDuMmIyhEprH1Gr8LOG6DH/HSz2HNGOzPG', 'user', '2026-11-12 10:50:09', '2026-11-12 10:50:09'),
('ecb5b91f69a2c4f3e5d185fa3328982e', 'Test Manager', 'test.manager@nexaflow.local', '$2y$10$iKUoaBaTafkYJBPvgwxINe0oUC0zFxHVIfU1IDdDs/XRO9t7HmVOW', 'user', '2026-11-12 10:50:09', '2026-11-12 10:50:09'),
('fb112da73d9260adb47722997397b677', 'Test Coordinator', 'test.coordinator@nexaflow.local', '$2y$10$k8S9/T69/pkiVdc01/sQYuaO4sm/PqB3SuuEfCKOLOuNuhpJSVqvG', 'manager', '2026-11-12 10:50:09', '2026-11-12 11:05:42');
