-- MySQL migration for the clients module.
CREATE TABLE IF NOT EXISTS clients (
  id CHAR(36) PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  legal_form VARCHAR(80) NOT NULL,
  tax_system VARCHAR(120) NOT NULL,
  has_vat TINYINT(1) NOT NULL DEFAULT 0,
  has_excise TINYINT(1) NOT NULL DEFAULT 0,
  has_employees TINYINT(1) NOT NULL DEFAULT 0,
  employee_count INT NULL,
  special_taxes JSON NULL,
  module VARCHAR(40) NOT NULL DEFAULT 'accounting',
  module_data JSON NULL,
  created_by CHAR(36) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_clients_created_by (created_by),
  INDEX idx_clients_name (name)
);
