CREATE TABLE IF NOT EXISTS accounting_client_invitations (
 id CHAR(32) PRIMARY KEY, owner_id CHAR(32) NOT NULL, client_id CHAR(32) NOT NULL,
 email VARCHAR(190) NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE,
 status VARCHAR(20) NOT NULL DEFAULT 'pending', invited_by CHAR(32) NOT NULL,
 accepted_by CHAR(32) NULL, expires_at DATETIME NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 accepted_at DATETIME NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_client_invites(owner_id,client_id,status), KEY idx_client_invites_email(email,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS accounting_client_invitation_audit (
 id CHAR(32) PRIMARY KEY, invitation_id CHAR(32) NOT NULL, actor_id CHAR(32) NULL,
 action VARCHAR(24) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 KEY idx_invitation_audit(invitation_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS accounting_client_registrants (user_id CHAR(32) PRIMARY KEY, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
