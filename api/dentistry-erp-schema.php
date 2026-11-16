<?php
declare(strict_types=1);

function dentalErpApplySchema(PDO $pdo, string $schema): void
{
    foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $schema) ?: [])) as $statement) {
        if (str_contains($statement, 'CREATE TABLE IF NOT EXISTS dental_erp_payroll_payments')) {
            $index=$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dental_erp_payroll_accruals' AND INDEX_NAME='uq_dental_erp_payroll_accrual_owner_id'")->fetchColumn();
            if (!(int)$index) $pdo->exec('ALTER TABLE dental_erp_payroll_accruals ADD UNIQUE KEY uq_dental_erp_payroll_accrual_owner_id(owner_id,id)');
        }
        $pdo->exec($statement);
    }
}
