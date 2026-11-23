-- Centralized issuing requisites for accounting service invoices.
CREATE TABLE IF NOT EXISTS accounting_requisites (
  id CHAR(32) PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  display_name VARCHAR(190) NOT NULL,
  legal_form VARCHAR(80) NOT NULL,
  tax_identifier VARCHAR(20) NOT NULL,
  legal_address VARCHAR(500) NOT NULL,
  postal_address VARCHAR(500) NULL,
  iban VARCHAR(34) NOT NULL,
  bank_name VARCHAR(190) NOT NULL,
  bank_details VARCHAR(500) NULL,
  contact_phone VARCHAR(50) NULL,
  contact_email VARCHAR(190) NULL,
  tax_status VARCHAR(190) NULL,
  additional_info TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by CHAR(32) NOT NULL,
  updated_by CHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_accounting_requisites_owner (owner_id, active, display_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_requisites_history (
  id CHAR(32) PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  requisites_id CHAR(32) NOT NULL,
  user_id CHAR(32) NOT NULL,
  action VARCHAR(24) NOT NULL,
  old_values JSON NULL,
  new_values JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_accounting_requisites_history (owner_id, requisites_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Keep this migration safe to re-run on databases where the invoice table already exists.
SET @has_issuer_snapshot = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'accounting_service_invoices'
    AND COLUMN_NAME = 'issuer_snapshot'
);
SET @has_invoice_table = (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'accounting_service_invoices'
);
SET @add_issuer_snapshot = IF(
  @has_invoice_table = 1 AND @has_issuer_snapshot = 0,
  'ALTER TABLE accounting_service_invoices ADD COLUMN issuer_snapshot JSON NULL',
  'SELECT 1'
);
PREPARE add_issuer_snapshot_stmt FROM @add_issuer_snapshot;
EXECUTE add_issuer_snapshot_stmt;
DEALLOCATE PREPARE add_issuer_snapshot_stmt;
