-- Run before deploying the limited-access email verification flow.
-- Databases that already have email_verified_at keep every existing value.
-- If upgrading a legacy database without the column, existing users keep
-- access. Only accounts registered after this migration start unverified.
SET @had_verification_column = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verified_at'
);
SET @add_verification_column = IF(
  @had_verification_column = 0,
  'ALTER TABLE users ADD email_verified_at DATETIME NULL',
  'SELECT 1'
);
PREPARE add_verification_column_stmt FROM @add_verification_column;
EXECUTE add_verification_column_stmt;
DEALLOCATE PREPARE add_verification_column_stmt;
SET @backfill_legacy_users = IF(
  @had_verification_column = 0,
  'UPDATE users SET email_verified_at = COALESCE(created_at, NOW()) WHERE email_verified_at IS NULL',
  'SELECT 1'
);
PREPARE backfill_legacy_users_stmt FROM @backfill_legacy_users;
EXECUTE backfill_legacy_users_stmt;
DEALLOCATE PREPARE backfill_legacy_users_stmt;

CREATE TABLE IF NOT EXISTS email_verification_tokens (
  id CHAR(32) PRIMARY KEY,
  user_id CHAR(32) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  verified_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_email_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
