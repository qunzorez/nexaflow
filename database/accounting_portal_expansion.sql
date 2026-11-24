-- Add an optional payment confirmation file to the existing portal notices.
SET @portal_proof_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounting_payment_notices' AND COLUMN_NAME = 'proof_document_id');
SET @portal_proof_sql := IF(@portal_proof_exists = 0, 'ALTER TABLE accounting_payment_notices ADD COLUMN proof_document_id CHAR(32) NULL', 'SELECT 1');
PREPARE portal_proof_stmt FROM @portal_proof_sql;
EXECUTE portal_proof_stmt;
DEALLOCATE PREPARE portal_proof_stmt;
