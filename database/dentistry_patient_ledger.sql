-- Minimal patient billing ledger schema. Independent of inventory and ERP stock tables.
CREATE TABLE IF NOT EXISTS dental_erp_accounting_accounts (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  account_code VARCHAR(24) NOT NULL,
  name VARCHAR(190) NOT NULL,
  account_type VARCHAR(24) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dental_erp_account_owner_id (owner_id, id),
  UNIQUE KEY uq_dental_erp_account_code (owner_id, account_code),
  CONSTRAINT chk_dental_erp_account_type CHECK (account_type IN ('asset','liability','equity','income','expense')),
  CONSTRAINT fk_dental_erp_account_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_accounting_periods (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  starts_on DATE NOT NULL,
  ends_on DATE NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'open',
  closed_by CHAR(32) NULL,
  closed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dental_erp_accounting_period_owner_id (owner_id, id),
  UNIQUE KEY uq_dental_erp_accounting_period_dates (owner_id, starts_on, ends_on),
  CONSTRAINT chk_dental_erp_accounting_period_dates CHECK (ends_on >= starts_on),
  CONSTRAINT chk_dental_erp_accounting_period_status CHECK (status IN ('open','closed')),
  CONSTRAINT fk_dental_erp_accounting_period_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_accounting_period_closer FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_accounting_journals (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  period_id CHAR(32) NOT NULL,
  source_type VARCHAR(40) NOT NULL,
  source_id CHAR(32) NOT NULL,
  entry_date DATE NOT NULL,
  description VARCHAR(500) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'posted',
  created_by CHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dental_erp_journal_owner_id (owner_id, id),
  UNIQUE KEY uq_dental_erp_journal_source (owner_id, source_type, source_id),
  KEY idx_dental_erp_journal_date (owner_id, entry_date),
  CONSTRAINT chk_dental_erp_journal_status CHECK (status IN ('posted','reversed')),
  CONSTRAINT fk_dental_erp_journal_period FOREIGN KEY (owner_id, period_id) REFERENCES dental_erp_accounting_periods(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_journal_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_accounting_entries (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  journal_id CHAR(32) NOT NULL,
  account_id CHAR(32) NOT NULL,
  debit DECIMAL(14,2) NOT NULL DEFAULT 0,
  credit DECIMAL(14,2) NOT NULL DEFAULT 0,
  memo VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dental_erp_entry_journal (owner_id, journal_id),
  CONSTRAINT chk_dental_erp_entry_side CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)),
  CONSTRAINT fk_dental_erp_entry_journal FOREIGN KEY (owner_id, journal_id) REFERENCES dental_erp_accounting_journals(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_entry_account FOREIGN KEY (owner_id, account_id) REFERENCES dental_erp_accounting_accounts(owner_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
