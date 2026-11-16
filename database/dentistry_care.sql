-- Optional normalized care and finance records for the dentistry client module.
-- Patient identity remains in clients.id; appointments remain tasks.id.
CREATE TABLE IF NOT EXISTS dental_appointment_details (
  appointment_id CHAR(32) PRIMARY KEY,
  cabinet_id VARCHAR(80) NULL,
  reason VARCHAR(190) NULL,
  comment TEXT NULL,
  appointment_status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
  updated_by CHAR(32) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_medical_records (
  id CHAR(32) PRIMARY KEY,
  patient_id CHAR(32) NOT NULL,
  appointment_id CHAR(32) NULL,
  doctor_id CHAR(32) NOT NULL,
  complaints TEXT NULL,
  diagnosis TEXT NULL,
  examination TEXT NULL,
  recommendations TEXT NULL,
  tooth_numbers JSON NULL,
  created_by CHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_dental_records_patient (patient_id, created_at),
  INDEX idx_dental_records_appointment (appointment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_medical_record_audit (
  id CHAR(32) PRIMARY KEY,
  record_id CHAR(32) NOT NULL,
  changed_by CHAR(32) NOT NULL,
  old_values JSON NULL,
  new_values JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_dental_record_audit_record (record_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_treatment_plans (
  id CHAR(32) PRIMARY KEY,
  patient_id CHAR(32) NOT NULL,
  doctor_id CHAR(32) NOT NULL,
  title VARCHAR(190) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  estimated_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  comment TEXT NULL,
  created_by CHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_dental_plans_patient (patient_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_treatment_plan_items (
  id CHAR(32) PRIMARY KEY,
  plan_id CHAR(32) NOT NULL,
  service_id CHAR(32) NULL,
  service_name VARCHAR(190) NOT NULL,
  tooth_number SMALLINT UNSIGNED NULL,
  quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  planned_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  completed_quantity SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'planned',
  comment VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_dental_plan_items_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_performed_services (
  id CHAR(32) PRIMARY KEY,
  patient_id CHAR(32) NOT NULL,
  appointment_id CHAR(32) NOT NULL,
  doctor_id CHAR(32) NOT NULL,
  service_id CHAR(32) NULL,
  service_name VARCHAR(190) NOT NULL,
  treatment_plan_item_id CHAR(32) NULL,
  tooth_number SMALLINT UNSIGNED NULL,
  quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  performed_at DATETIME NOT NULL,
  created_by CHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_dental_performed_patient (patient_id, performed_at),
  INDEX idx_dental_performed_appointment (appointment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_invoices (
  id CHAR(32) PRIMARY KEY,
  invoice_number VARCHAR(40) NOT NULL UNIQUE,
  patient_id CHAR(32) NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  remaining_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'issued',
  created_by CHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_dental_invoices_patient (patient_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_invoice_items (
  id CHAR(32) PRIMARY KEY,
  invoice_id CHAR(32) NOT NULL,
  performed_service_id CHAR(32) NOT NULL UNIQUE,
  service_name VARCHAR(190) NOT NULL,
  tooth_number SMALLINT UNSIGNED NULL,
  quantity SMALLINT UNSIGNED NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  total_price DECIMAL(12,2) NOT NULL,
  INDEX idx_dental_invoice_items_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_payments (
  id CHAR(32) PRIMARY KEY,
  patient_id CHAR(32) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  payment_method VARCHAR(24) NOT NULL,
  payment_type VARCHAR(12) NOT NULL DEFAULT 'payment',
  payment_date DATETIME NOT NULL,
  idempotency_key CHAR(32) NOT NULL UNIQUE,
  created_by CHAR(32) NOT NULL,
  comment VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_dental_payments_patient (patient_id, payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_payment_allocations (
  id CHAR(32) PRIMARY KEY,
  payment_id CHAR(32) NOT NULL,
  invoice_id CHAR(32) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_dental_payment_alloc_invoice (invoice_id),
  INDEX idx_dental_payment_alloc_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_financial_audit (
  id CHAR(32) PRIMARY KEY,
  actor_id CHAR(32) NOT NULL,
  entity_type VARCHAR(24) NOT NULL,
  entity_id CHAR(32) NOT NULL,
  action VARCHAR(32) NOT NULL,
  details JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_dental_financial_audit_entity (entity_type, entity_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dental_documents (
  id CHAR(32) PRIMARY KEY,
  patient_id CHAR(32) NOT NULL,
  record_id CHAR(32) NULL,
  original_name VARCHAR(255) NOT NULL,
  description VARCHAR(1000) NULL,
  stored_name VARCHAR(80) NOT NULL UNIQUE,
  mime_type VARCHAR(80) NOT NULL,
  file_size INT UNSIGNED NOT NULL,
  uploaded_by CHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_dental_documents_patient (patient_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
