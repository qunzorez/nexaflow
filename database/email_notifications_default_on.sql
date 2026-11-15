-- Enable email notifications for existing users once, and make this the default.
-- Run once against each NexaFlow database after backing up user preferences.
ALTER TABLE users
  MODIFY email_notifications_enabled TINYINT(1) NOT NULL DEFAULT 1;

UPDATE users
SET email_notifications_enabled = 1
WHERE email_notifications_enabled = 0;
