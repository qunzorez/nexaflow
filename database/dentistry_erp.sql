-- Additive foundation for the dentistry ERP. Existing CRM tables remain intact.
CREATE TABLE IF NOT EXISTS dental_erp_suppliers (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  name VARCHAR(190) NOT NULL,
  contact_person VARCHAR(190) NULL,
  phone VARCHAR(80) NULL,
  email VARCHAR(190) NULL,
  address VARCHAR(500) NULL,
  tax_details VARCHAR(500) NULL,
  payment_terms VARCHAR(500) NULL,
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by CHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dental_erp_supplier_owner_id (owner_id, id),
  KEY idx_dental_erp_supplier_owner_active (owner_id, is_active, name),
  CONSTRAINT fk_dental_erp_supplier_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_supplier_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_audit (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  actor_id CHAR(32) NOT NULL,
  entity_type VARCHAR(40) NOT NULL,
  entity_id CHAR(32) NOT NULL,
  action VARCHAR(40) NOT NULL,
  details JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dental_erp_audit_entity (owner_id, entity_type, entity_id, created_at),
  KEY idx_dental_erp_audit_actor (owner_id, actor_id, created_at),
  CONSTRAINT fk_dental_erp_audit_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_inventory_categories (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dental_erp_category_owner_id (owner_id, id),
  UNIQUE KEY uq_dental_erp_category_owner_name (owner_id, name),
  CONSTRAINT fk_dental_erp_category_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_inventory_units (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  name VARCHAR(80) NOT NULL,
  symbol VARCHAR(24) NOT NULL,
  decimal_places TINYINT UNSIGNED NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dental_erp_unit_owner_id (owner_id, id),
  UNIQUE KEY uq_dental_erp_unit_owner_symbol (owner_id, symbol),
  CONSTRAINT fk_dental_erp_unit_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_inventory_items (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  category_id CHAR(32) NOT NULL,
  unit_id CHAR(32) NOT NULL,
  default_supplier_id CHAR(32) NULL,
  name VARCHAR(190) NOT NULL,
  sku VARCHAR(80) NULL,
  item_type VARCHAR(32) NOT NULL DEFAULT 'consumable',
  purchase_unit_cost DECIMAL(14,4) NOT NULL DEFAULT 0,
  minimum_stock DECIMAL(14,4) NOT NULL DEFAULT 0,
  optimal_stock DECIMAL(14,4) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by CHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dental_erp_item_owner_id (owner_id, id),
  UNIQUE KEY uq_dental_erp_item_owner_sku (owner_id, sku),
  KEY idx_dental_erp_item_owner_active (owner_id, is_active, name),
  CONSTRAINT chk_dental_erp_item_type CHECK (item_type IN ('consumable','reusable_instrument','equipment','medication')),
  CONSTRAINT chk_dental_erp_item_cost CHECK (purchase_unit_cost >= 0),
  CONSTRAINT chk_dental_erp_item_stock CHECK (minimum_stock >= 0 AND optimal_stock >= minimum_stock),
  CONSTRAINT fk_dental_erp_item_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_item_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_item_category FOREIGN KEY (owner_id, category_id) REFERENCES dental_erp_inventory_categories(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_item_unit FOREIGN KEY (owner_id, unit_id) REFERENCES dental_erp_inventory_units(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_item_supplier FOREIGN KEY (owner_id, default_supplier_id) REFERENCES dental_erp_suppliers(owner_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_inventory_locations (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dental_erp_location_owner_id (owner_id, id),
  UNIQUE KEY uq_dental_erp_location_owner_name (owner_id, name),
  CONSTRAINT fk_dental_erp_location_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_inventory_batches (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  item_id CHAR(32) NOT NULL,
  location_id CHAR(32) NOT NULL,
  batch_number VARCHAR(100) NULL,
  expiration_date DATE NULL,
  received_quantity DECIMAL(14,4) NOT NULL,
  available_quantity DECIMAL(14,4) NOT NULL,
  purchase_unit_cost DECIMAL(14,4) NOT NULL,
  is_blocked TINYINT(1) NOT NULL DEFAULT 0,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dental_erp_batch_owner_id (owner_id, id),
  KEY idx_dental_erp_batch_fefo (owner_id, item_id, is_blocked, expiration_date, received_at),
  KEY idx_dental_erp_batch_location (owner_id, location_id, item_id),
  CONSTRAINT chk_dental_erp_batch_quantities CHECK (received_quantity >= 0 AND available_quantity >= 0 AND available_quantity <= received_quantity),
  CONSTRAINT chk_dental_erp_batch_cost CHECK (purchase_unit_cost >= 0),
  CONSTRAINT fk_dental_erp_batch_item FOREIGN KEY (owner_id, item_id) REFERENCES dental_erp_inventory_items(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_batch_location FOREIGN KEY (owner_id, location_id) REFERENCES dental_erp_inventory_locations(owner_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_stock_movements (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  item_id CHAR(32) NOT NULL,
  batch_id CHAR(32) NULL,
  location_id CHAR(32) NOT NULL,
  movement_type VARCHAR(32) NOT NULL,
  quantity DECIMAL(14,4) NOT NULL,
  unit_cost DECIMAL(14,4) NOT NULL DEFAULT 0,
  source_type VARCHAR(40) NULL,
  source_id CHAR(32) NULL,
  idempotency_key VARCHAR(100) NULL,
  note VARCHAR(1000) NULL,
  actor_id CHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dental_erp_movement_owner_id (owner_id, id),
  UNIQUE KEY uq_dental_erp_movement_idempotency (owner_id, idempotency_key),
  KEY idx_dental_erp_movement_item (owner_id, item_id, created_at),
  KEY idx_dental_erp_movement_source (owner_id, source_type, source_id),
  CONSTRAINT chk_dental_erp_movement_quantity CHECK (quantity > 0),
  CONSTRAINT chk_dental_erp_movement_type CHECK (movement_type IN ('receipt','consumption','return','transfer_in','transfer_out','adjustment_in','adjustment_out','write_off')),
  CONSTRAINT fk_dental_erp_movement_item FOREIGN KEY (owner_id, item_id) REFERENCES dental_erp_inventory_items(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_movement_batch FOREIGN KEY (owner_id, batch_id) REFERENCES dental_erp_inventory_batches(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_movement_location FOREIGN KEY (owner_id, location_id) REFERENCES dental_erp_inventory_locations(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_movement_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_purchase_orders (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  supplier_id CHAR(32) NOT NULL,
  order_number VARCHAR(40) NOT NULL,
  order_date DATE NOT NULL,
  expected_delivery_date DATE NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_by CHAR(32) NOT NULL,
  approved_by CHAR(32) NULL,
  approved_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dental_erp_purchase_owner_id (owner_id, id),
  UNIQUE KEY uq_dental_erp_purchase_number (owner_id, order_number),
  KEY idx_dental_erp_purchase_supplier (owner_id, supplier_id, order_date),
  CONSTRAINT chk_dental_erp_purchase_status CHECK (status IN ('draft','pending_approval','approved','ordered','partially_received','received','cancelled')),
  CONSTRAINT chk_dental_erp_purchase_amount CHECK (total_amount >= 0),
  CONSTRAINT fk_dental_erp_purchase_supplier FOREIGN KEY (owner_id, supplier_id) REFERENCES dental_erp_suppliers(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_purchase_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_purchase_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_purchase_order_items (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  purchase_order_id CHAR(32) NOT NULL,
  inventory_item_id CHAR(32) NOT NULL,
  quantity DECIMAL(14,4) NOT NULL,
  received_quantity DECIMAL(14,4) NOT NULL DEFAULT 0,
  unit_price DECIMAL(14,4) NOT NULL,
  total_price DECIMAL(14,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dental_erp_po_item_owner_id (owner_id, id),
  KEY idx_dental_erp_po_item_order (owner_id, purchase_order_id),
  CONSTRAINT chk_dental_erp_po_item_quantities CHECK (quantity > 0 AND received_quantity >= 0 AND received_quantity <= quantity),
  CONSTRAINT chk_dental_erp_po_item_price CHECK (unit_price >= 0 AND total_price >= 0),
  CONSTRAINT fk_dental_erp_po_item_order FOREIGN KEY (owner_id, purchase_order_id) REFERENCES dental_erp_purchase_orders(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_po_item_inventory FOREIGN KEY (owner_id, inventory_item_id) REFERENCES dental_erp_inventory_items(owner_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_goods_receipts (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  purchase_order_id CHAR(32) NOT NULL,
  receipt_number VARCHAR(40) NOT NULL,
  idempotency_key CHAR(32) NOT NULL,
  received_by CHAR(32) NOT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  note VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dental_erp_receipt_owner_id (owner_id, id),
  UNIQUE KEY uq_dental_erp_receipt_number (owner_id, receipt_number),
  UNIQUE KEY uq_dental_erp_receipt_idempotency (owner_id, idempotency_key),
  CONSTRAINT fk_dental_erp_receipt_order FOREIGN KEY (owner_id, purchase_order_id) REFERENCES dental_erp_purchase_orders(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_receipt_user FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_goods_receipt_items (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  receipt_id CHAR(32) NOT NULL,
  purchase_order_item_id CHAR(32) NOT NULL,
  batch_id CHAR(32) NOT NULL,
  quantity DECIMAL(14,4) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dental_erp_receipt_item_receipt (owner_id, receipt_id),
  CONSTRAINT chk_dental_erp_receipt_item_quantity CHECK (quantity > 0),
  CONSTRAINT fk_dental_erp_receipt_item_receipt FOREIGN KEY (owner_id, receipt_id) REFERENCES dental_erp_goods_receipts(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_receipt_item_order_item FOREIGN KEY (owner_id, purchase_order_item_id) REFERENCES dental_erp_purchase_order_items(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_receipt_item_batch FOREIGN KEY (owner_id, batch_id) REFERENCES dental_erp_inventory_batches(owner_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_supplier_invoices (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  supplier_id CHAR(32) NOT NULL,
  purchase_order_id CHAR(32) NULL,
  invoice_number VARCHAR(100) NOT NULL,
  invoice_date DATE NOT NULL,
  due_date DATE NULL,
  amount DECIMAL(14,2) NOT NULL,
  paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  status VARCHAR(24) NOT NULL DEFAULT 'issued',
  note VARCHAR(1000) NULL,
  created_by CHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dental_erp_supplier_invoice_owner_id (owner_id, id),
  UNIQUE KEY uq_dental_erp_supplier_invoice_number (owner_id, supplier_id, invoice_number),
  KEY idx_dental_erp_supplier_invoice_due (owner_id, status, due_date),
  CONSTRAINT chk_dental_erp_supplier_invoice_amount CHECK (amount > 0 AND paid_amount >= 0 AND paid_amount <= amount),
  CONSTRAINT chk_dental_erp_supplier_invoice_status CHECK (status IN ('issued','partially_paid','paid','cancelled')),
  CONSTRAINT fk_dental_erp_supplier_invoice_supplier FOREIGN KEY (owner_id, supplier_id) REFERENCES dental_erp_suppliers(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_supplier_invoice_order FOREIGN KEY (owner_id, purchase_order_id) REFERENCES dental_erp_purchase_orders(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_supplier_invoice_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_supplier_payments (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  supplier_invoice_id CHAR(32) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  payment_method VARCHAR(24) NOT NULL,
  payment_date DATETIME NOT NULL,
  idempotency_key CHAR(32) NOT NULL,
  comment VARCHAR(1000) NULL,
  created_by CHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dental_erp_supplier_payment_owner_id (owner_id, id),
  UNIQUE KEY uq_dental_erp_supplier_payment_idempotency (owner_id, idempotency_key),
  KEY idx_dental_erp_supplier_payment_invoice (owner_id, supplier_invoice_id, payment_date),
  CONSTRAINT chk_dental_erp_supplier_payment_amount CHECK (amount > 0),
  CONSTRAINT fk_dental_erp_supplier_payment_invoice FOREIGN KEY (owner_id, supplier_invoice_id) REFERENCES dental_erp_supplier_invoices(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_supplier_payment_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_service_material_templates (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  service_id CHAR(32) NOT NULL,
  inventory_item_id CHAR(32) NOT NULL,
  expected_quantity DECIMAL(14,4) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by CHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dental_erp_template_owner_id (owner_id, id),
  UNIQUE KEY uq_dental_erp_template_service_item (owner_id, service_id, inventory_item_id),
  KEY idx_dental_erp_template_service (owner_id, service_id),
  CONSTRAINT chk_dental_erp_template_qty CHECK (expected_quantity > 0),
  CONSTRAINT fk_dental_erp_template_item FOREIGN KEY (owner_id, inventory_item_id) REFERENCES dental_erp_inventory_items(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_template_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_performed_service_materials (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  performed_service_id CHAR(32) NOT NULL,
  template_id CHAR(32) NULL,
  inventory_item_id CHAR(32) NOT NULL,
  batch_id CHAR(32) NOT NULL,
  stock_movement_id CHAR(32) NOT NULL,
  quantity DECIMAL(14,4) NOT NULL,
  unit_cost DECIMAL(14,4) NOT NULL,
  total_cost DECIMAL(14,4) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dental_erp_performed_material_owner_id (owner_id, id),
  UNIQUE KEY uq_dental_erp_performed_material_movement (owner_id, stock_movement_id),
  KEY idx_dental_erp_performed_material_service (owner_id, performed_service_id),
  CONSTRAINT chk_dental_erp_performed_material_qty CHECK (quantity > 0 AND unit_cost >= 0 AND total_cost >= 0),
  CONSTRAINT fk_dental_erp_performed_material_item FOREIGN KEY (owner_id, inventory_item_id) REFERENCES dental_erp_inventory_items(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_performed_material_batch FOREIGN KEY (owner_id, batch_id) REFERENCES dental_erp_inventory_batches(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_performed_material_movement FOREIGN KEY (owner_id, stock_movement_id) REFERENCES dental_erp_stock_movements(owner_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_expense_categories (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dental_erp_expense_category_owner_id (owner_id, id),
  UNIQUE KEY uq_dental_erp_expense_category_owner_name (owner_id, name),
  CONSTRAINT fk_dental_erp_expense_category_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_expenses (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  category_id CHAR(32) NOT NULL,
  supplier_id CHAR(32) NULL,
  amount DECIMAL(14,2) NOT NULL,
  description VARCHAR(1000) NOT NULL,
  expense_date DATE NOT NULL,
  payment_status VARCHAR(20) NOT NULL DEFAULT 'paid',
  document_reference VARCHAR(255) NULL,
  paid_at DATETIME NULL,
  created_by CHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dental_erp_expense_date (owner_id, expense_date),
  CONSTRAINT chk_dental_erp_expense_amount CHECK (amount > 0),
  CONSTRAINT chk_dental_erp_expense_status CHECK (payment_status IN ('unpaid','paid','cancelled')),
  CONSTRAINT fk_dental_erp_expense_category FOREIGN KEY (owner_id, category_id) REFERENCES dental_erp_expense_categories(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_expense_supplier FOREIGN KEY (owner_id, supplier_id) REFERENCES dental_erp_suppliers(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_expense_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_payroll_rules (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  user_id CHAR(32) NOT NULL,
  calculation_type VARCHAR(24) NOT NULL,
  calculation_basis VARCHAR(24) NOT NULL DEFAULT 'performed',
  fixed_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  percentage DECIMAL(7,4) NOT NULL DEFAULT 0,
  per_procedure_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by CHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dental_erp_payroll_rule_owner_id (owner_id, id),
  UNIQUE KEY uq_dental_erp_payroll_rule_user (owner_id, user_id),
  CONSTRAINT chk_dental_erp_payroll_type CHECK (calculation_type IN ('fixed','percentage','per_procedure','combined')),
  CONSTRAINT chk_dental_erp_payroll_basis CHECK (calculation_basis IN ('performed','collected')),
  CONSTRAINT fk_dental_erp_payroll_rule_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_payroll_rule_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_payroll_rule_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_payroll_periods (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  starts_on DATE NOT NULL,
  ends_on DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  created_by CHAR(32) NOT NULL,
  closed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dental_erp_payroll_period_owner_id (owner_id, id),
  UNIQUE KEY uq_dental_erp_payroll_period_dates (owner_id, starts_on, ends_on),
  CONSTRAINT chk_dental_erp_payroll_period_dates CHECK (ends_on >= starts_on),
  CONSTRAINT chk_dental_erp_payroll_period_status CHECK (status IN ('open','calculated','closed')),
  CONSTRAINT fk_dental_erp_payroll_period_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_payroll_period_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_payroll_accruals (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  period_id CHAR(32) NOT NULL,
  user_id CHAR(32) NOT NULL,
  basis_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  procedures_count INT UNSIGNED NOT NULL DEFAULT 0,
  accrued_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  details JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dental_erp_payroll_accrual (owner_id, period_id, user_id),
  UNIQUE KEY uq_dental_erp_payroll_accrual_owner_id (owner_id, id),
  CONSTRAINT chk_dental_erp_payroll_accrual CHECK (basis_amount >= 0 AND accrued_amount >= 0 AND paid_amount >= 0 AND paid_amount <= accrued_amount),
  CONSTRAINT fk_dental_erp_payroll_accrual_period FOREIGN KEY (owner_id, period_id) REFERENCES dental_erp_payroll_periods(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_payroll_accrual_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_payroll_payments (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  accrual_id CHAR(32) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  payment_date DATETIME NOT NULL,
  payment_method VARCHAR(24) NOT NULL,
  idempotency_key CHAR(32) NOT NULL,
  comment VARCHAR(1000) NULL,
  created_by CHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dental_erp_payroll_payment_key (owner_id, idempotency_key),
  KEY idx_dental_erp_payroll_payment_accrual (owner_id, accrual_id, payment_date),
  CONSTRAINT chk_dental_erp_payroll_payment_amount CHECK (amount > 0),
  CONSTRAINT fk_dental_erp_payroll_payment_accrual FOREIGN KEY (owner_id, accrual_id) REFERENCES dental_erp_payroll_accruals(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_payroll_payment_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS dental_erp_equipment (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  name VARCHAR(190) NOT NULL,
  inventory_number VARCHAR(80) NULL,
  serial_number VARCHAR(120) NULL,
  purchase_date DATE NULL,
  purchase_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
  supplier_id CHAR(32) NULL,
  warranty_until DATE NULL,
  location VARCHAR(190) NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  useful_life_months SMALLINT UNSIGNED NULL,
  created_by CHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dental_erp_equipment_owner_id (owner_id, id),
  KEY idx_dental_erp_equipment_owner_status (owner_id, status),
  CONSTRAINT chk_dental_erp_equipment_status CHECK (status IN ('active','maintenance','retired','written_off')),
  CONSTRAINT chk_dental_erp_equipment_cost CHECK (purchase_cost >= 0),
  CONSTRAINT fk_dental_erp_equipment_supplier FOREIGN KEY (owner_id, supplier_id) REFERENCES dental_erp_suppliers(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_equipment_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_equipment_maintenance (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  equipment_id CHAR(32) NOT NULL,
  maintenance_date DATE NOT NULL,
  description VARCHAR(1000) NOT NULL,
  cost DECIMAL(14,2) NOT NULL DEFAULT 0,
  next_due_date DATE NULL,
  performed_by VARCHAR(190) NULL,
  created_by CHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dental_erp_maintenance_date (owner_id, equipment_id, maintenance_date),
  CONSTRAINT chk_dental_erp_maintenance_cost CHECK (cost >= 0),
  CONSTRAINT fk_dental_erp_maintenance_equipment FOREIGN KEY (owner_id, equipment_id) REFERENCES dental_erp_equipment(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_maintenance_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_instrument_sets (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  name VARCHAR(190) NOT NULL,
  inventory_item_id CHAR(32) NULL,
  set_code VARCHAR(80) NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'available',
  condition_note VARCHAR(500) NULL,
  location VARCHAR(190) NULL,
  created_by CHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dental_erp_instrument_set_owner_id (owner_id, id),
  UNIQUE KEY uq_dental_erp_instrument_set_code (owner_id, set_code),
  CONSTRAINT chk_dental_erp_instrument_set_status CHECK (status IN ('available','issued','sterilizing','repair','retired')),
  CONSTRAINT fk_dental_erp_instrument_item FOREIGN KEY (owner_id, inventory_item_id) REFERENCES dental_erp_inventory_items(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_instrument_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_erp_sterilization_cycles (
  id CHAR(32) NOT NULL PRIMARY KEY,
  owner_id CHAR(32) NOT NULL,
  instrument_set_id CHAR(32) NOT NULL,
  cycle_number VARCHAR(80) NULL,
  started_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  result VARCHAR(24) NOT NULL DEFAULT 'pending',
  performed_by CHAR(32) NOT NULL,
  note VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dental_erp_sterilization_set (owner_id, instrument_set_id, started_at),
  CONSTRAINT chk_dental_erp_sterilization_result CHECK (result IN ('pending','passed','failed')),
  CONSTRAINT fk_dental_erp_sterilization_set FOREIGN KEY (owner_id, instrument_set_id) REFERENCES dental_erp_instrument_sets(owner_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_dental_erp_sterilization_user FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
