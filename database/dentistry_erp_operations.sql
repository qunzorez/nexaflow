-- Additive operational workflows; safe to apply repeatedly to existing clinics.
CREATE TABLE IF NOT EXISTS dental_erp_operations (
 id CHAR(32) PRIMARY KEY, owner_id CHAR(32) NOT NULL, action VARCHAR(60) NOT NULL,
 request_key CHAR(32) NOT NULL, result JSON NULL, created_by CHAR(32) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_erp_operation_key(owner_id,request_key),
 FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS dental_erp_stock_reservations (
 id CHAR(32) PRIMARY KEY, owner_id CHAR(32) NOT NULL, batch_id CHAR(32) NOT NULL,
 appointment_id CHAR(32) NOT NULL, quantity DECIMAL(14,4) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'active', created_by CHAR(32) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_erp_reservation_batch(owner_id,batch_id,status),
 KEY idx_erp_reservation_visit(owner_id,appointment_id,status),
 CHECK(quantity>0), CHECK(status IN ('active','released','consumed')),
 FOREIGN KEY(owner_id,batch_id) REFERENCES dental_erp_inventory_batches(owner_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS dental_erp_inventory_counts (
 id CHAR(32) PRIMARY KEY, owner_id CHAR(32) NOT NULL, batch_id CHAR(32) NOT NULL,
 expected_quantity DECIMAL(14,4) NOT NULL, counted_quantity DECIMAL(14,4) NOT NULL,
 reason VARCHAR(1000) NOT NULL, created_by CHAR(32) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CHECK(expected_quantity>=0 AND counted_quantity>=0),
 FOREIGN KEY(owner_id,batch_id) REFERENCES dental_erp_inventory_batches(owner_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS dental_erp_supplier_returns (
 id CHAR(32) PRIMARY KEY, owner_id CHAR(32) NOT NULL, supplier_invoice_id CHAR(32) NOT NULL,
 batch_id CHAR(32) NOT NULL, quantity DECIMAL(14,4) NOT NULL, credit_amount DECIMAL(14,2) NOT NULL,
 reason VARCHAR(1000) NOT NULL, created_by CHAR(32) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CHECK(quantity>0 AND credit_amount>=0),
 FOREIGN KEY(owner_id,batch_id) REFERENCES dental_erp_inventory_batches(owner_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(owner_id,supplier_invoice_id) REFERENCES dental_erp_supplier_invoices(owner_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS dental_erp_supplier_invoice_items (
 id CHAR(32) PRIMARY KEY, owner_id CHAR(32) NOT NULL, invoice_id CHAR(32) NOT NULL,
 inventory_item_id CHAR(32) NOT NULL, quantity DECIMAL(14,4) NOT NULL,
 unit_price DECIMAL(14,4) NOT NULL, total_price DECIMAL(14,2) NOT NULL,
 CHECK(quantity>0 AND unit_price>=0 AND total_price>=0),
 FOREIGN KEY(owner_id,invoice_id) REFERENCES dental_erp_supplier_invoices(owner_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(owner_id,inventory_item_id) REFERENCES dental_erp_inventory_items(owner_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS dental_erp_supplier_refunds (
 id CHAR(32) PRIMARY KEY, owner_id CHAR(32) NOT NULL, invoice_id CHAR(32) NOT NULL,
 amount DECIMAL(14,2) NOT NULL, reason VARCHAR(1000) NOT NULL, created_by CHAR(32) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CHECK(amount>0),
 FOREIGN KEY(owner_id,invoice_id) REFERENCES dental_erp_supplier_invoices(owner_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS dental_erp_supplier_payment_allocations (
 id CHAR(32) PRIMARY KEY, owner_id CHAR(32) NOT NULL, payment_id CHAR(32) NOT NULL,
 invoice_id CHAR(32) NOT NULL, amount DECIMAL(14,2) NOT NULL,
 UNIQUE KEY uq_erp_supplier_allocation(owner_id,payment_id,invoice_id), CHECK(amount>0),
 FOREIGN KEY(owner_id,payment_id) REFERENCES dental_erp_supplier_payments(owner_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(owner_id,invoice_id) REFERENCES dental_erp_supplier_invoices(owner_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS dental_erp_recurring_expenses (
 id CHAR(32) PRIMARY KEY, owner_id CHAR(32) NOT NULL, category_id CHAR(32) NOT NULL,
 supplier_id CHAR(32) NULL, description VARCHAR(1000) NOT NULL, amount DECIMAL(14,2) NOT NULL,
 next_due DATE NOT NULL, interval_months SMALLINT UNSIGNED NOT NULL DEFAULT 1,
 is_active TINYINT NOT NULL DEFAULT 1, created_by CHAR(32) NOT NULL,
 CHECK(amount>0 AND interval_months BETWEEN 1 AND 12),
 UNIQUE KEY uq_erp_recurring_owner(owner_id,id),
 FOREIGN KEY(owner_id,category_id) REFERENCES dental_erp_expense_categories(owner_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(owner_id,supplier_id) REFERENCES dental_erp_suppliers(owner_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS dental_erp_expense_requests (
 id CHAR(32) PRIMARY KEY, owner_id CHAR(32) NOT NULL, category_id CHAR(32) NOT NULL,
 supplier_id CHAR(32) NULL, description VARCHAR(1000) NOT NULL, amount DECIMAL(14,2) NOT NULL,
 expense_date DATE NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending',
 recurring_id CHAR(32) NULL, expense_id CHAR(32) NULL, approved_by CHAR(32) NULL,
 created_by CHAR(32) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_erp_expense_request_owner(owner_id,id),
 UNIQUE KEY uq_erp_expense_occurrence(owner_id,recurring_id,expense_date),
 CHECK(amount>0), CHECK(status IN ('pending','approved','rejected')),
 FOREIGN KEY(owner_id,category_id) REFERENCES dental_erp_expense_categories(owner_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(owner_id,supplier_id) REFERENCES dental_erp_suppliers(owner_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(owner_id,recurring_id) REFERENCES dental_erp_recurring_expenses(owner_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS dental_erp_expense_documents (
 id CHAR(32) PRIMARY KEY, owner_id CHAR(32) NOT NULL, expense_id CHAR(32) NOT NULL,
 original_name VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL,
 storage_name CHAR(32) NOT NULL, file_size INT UNSIGNED NOT NULL, created_by CHAR(32) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_erp_expense_document(owner_id,expense_id),
 FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS dental_erp_procedure_costs (
 id CHAR(32) PRIMARY KEY, owner_id CHAR(32) NOT NULL, performed_service_id CHAR(32) NOT NULL,
 laboratory_cost DECIMAL(14,2) NOT NULL DEFAULT 0, labor_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
 other_cost DECIMAL(14,2) NOT NULL DEFAULT 0, note VARCHAR(1000) NOT NULL, created_by CHAR(32) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_erp_procedure_cost(owner_id,performed_service_id),
 CHECK(laboratory_cost>=0 AND labor_cost>=0 AND other_cost>=0),
 FOREIGN KEY(performed_service_id) REFERENCES dental_performed_services(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS dental_erp_depreciation (
 id CHAR(32) PRIMARY KEY, owner_id CHAR(32) NOT NULL, equipment_id CHAR(32) NOT NULL,
 period_date DATE NOT NULL, amount DECIMAL(14,2) NOT NULL, created_by CHAR(32) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_erp_depreciation_month(owner_id,equipment_id,period_date), CHECK(amount>0),
 FOREIGN KEY(owner_id,equipment_id) REFERENCES dental_erp_equipment(owner_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS dental_erp_procedure_cost_payments (
 id CHAR(32) PRIMARY KEY, owner_id CHAR(32) NOT NULL, cost_id CHAR(32) NOT NULL,
 amount DECIMAL(14,2) NOT NULL, created_by CHAR(32) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CHECK(amount>0),
 FOREIGN KEY(cost_id) REFERENCES dental_erp_procedure_costs(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS dental_erp_payroll_adjustments (
 id CHAR(32) PRIMARY KEY, owner_id CHAR(32) NOT NULL, accrual_id CHAR(32) NOT NULL,
 adjustment_type VARCHAR(20) NOT NULL, amount DECIMAL(14,2) NOT NULL,
 reason VARCHAR(1000) NOT NULL, created_by CHAR(32) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CHECK(amount>0), CHECK(adjustment_type IN ('bonus','deduction')),
 FOREIGN KEY(accrual_id) REFERENCES dental_erp_payroll_accruals(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS dental_erp_procurement_settings (
 owner_id CHAR(32) PRIMARY KEY, auto_drafts TINYINT NOT NULL DEFAULT 0,
 lead_days SMALLINT UNSIGNED NOT NULL DEFAULT 7, updated_by CHAR(32) NOT NULL,
 CHECK(lead_days BETWEEN 0 AND 365),
 FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO dental_erp_supplier_payment_allocations(id,owner_id,payment_id,invoice_id,amount)
SELECT MD5(CONCAT(owner_id,'|supplier-payment-allocation|',id)),owner_id,id,supplier_invoice_id,amount
FROM dental_erp_supplier_payments;
