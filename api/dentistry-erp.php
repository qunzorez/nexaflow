<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_once __DIR__ . '/plans.php';
session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);

$pdo = db();
$userId = (string)$_SESSION['user_id'];
$userQuery = $pdo->prepare('SELECT id, role FROM users WHERE id = ? LIMIT 1');
$userQuery->execute([$userId]);
$user = $userQuery->fetch();
if (!$user) jsonResponse(false, 'Користувача не знайдено.', [], 401);
$context = workspacePlan($pdo, $userId, (string)$user['role']);
$ownerId = (string)$context['owner_id'];
if (($context['module'] ?? '') !== 'dentistry') jsonResponse(false, 'ERP доступна лише для стоматологічного простору.', [], 403);

$careSchema = file_get_contents(__DIR__ . '/../database/dentistry_care.sql');
if ($careSchema === false) jsonResponse(false, 'Не вдалося завантажити схему стоматологічної картки.', [], 500);
foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $careSchema) ?: [])) as $statement) $pdo->exec($statement);

$schema = file_get_contents(__DIR__ . '/../database/dentistry_erp.sql');
if ($schema === false) jsonResponse(false, 'Не вдалося завантажити схему стоматологічної ERP.', [], 500);
require_once __DIR__ . '/dentistry-erp-schema.php';
dentalErpApplySchema($pdo, $schema);
$operationsSchema = file_get_contents(__DIR__ . '/../database/dentistry_erp_operations.sql');
if ($operationsSchema === false) jsonResponse(false, 'Не вдалося завантажити складські операції.', [], 500);
foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $operationsSchema) ?: [])) as $statement) $pdo->exec($statement);
$seedCategories = ['Анестетики', 'Пломбувальні матеріали', 'Засоби захисту', 'Одноразові матеріали', 'Дезінфекція', 'Інструменти', 'Обладнання', 'Медикаменти', 'Інше'];
$seedCategory = $pdo->prepare('INSERT IGNORE INTO dental_erp_inventory_categories (id, owner_id, name) VALUES (?, ?, ?)');
foreach ($seedCategories as $name) $seedCategory->execute([md5($ownerId . '|dental-category|' . $name), $ownerId, $name]);
$seedUnits = [['Штука', 'шт', 0], ['Упаковка', 'уп', 0], ['Грам', 'г', 3], ['Мілілітр', 'мл', 3], ['Набір', 'набір', 0], ['Літр', 'л', 3]];
$seedUnit = $pdo->prepare('INSERT IGNORE INTO dental_erp_inventory_units (id, owner_id, name, symbol, decimal_places) VALUES (?, ?, ?, ?, ?)');
foreach ($seedUnits as [$name, $symbol, $places]) $seedUnit->execute([md5($ownerId . '|dental-unit|' . $symbol), $ownerId, $name, $symbol, $places]);
$pdo->prepare('INSERT IGNORE INTO dental_erp_inventory_locations (id, owner_id, name) VALUES (?, ?, ?)')->execute([md5($ownerId . '|dental-location|main'), $ownerId, 'Основний склад']);
$seedExpenseCategories = ['Оренда', 'Комунальні послуги', 'Зарплати', 'Маркетинг', 'Інтернет і зв’язок', 'Обслуговування обладнання', 'Ремонт', 'Програмне забезпечення', 'Податки', 'Інші витрати'];
$seedExpenseCategory = $pdo->prepare('INSERT IGNORE INTO dental_erp_expense_categories (id, owner_id, name) VALUES (?, ?, ?)');
foreach ($seedExpenseCategories as $name) $seedExpenseCategory->execute([md5($ownerId . '|dental-expense|' . $name), $ownerId, $name]);
require_once __DIR__ . '/dentistry-erp-ledger.php';
$ledgerAccounts = dentalErpLedgerAccounts($pdo, $ownerId);

$canManage = in_array((string)($context['workspace_role'] ?? ''), ['manager', 'admin', 'owner'], true)
    || ($ownerId === $userId && in_array((string)$user['role'], ['manager', 'admin', 'owner'], true));
$canViewFinance = $canManage;
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$data = $method === 'POST' ? (str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') ? $_POST : body()) : [];
$action = trim((string)($_GET['action'] ?? $data['action'] ?? 'overview'));
$dentalFeatureByAction = [
    'save_payroll_rule' => 'dental_doctor_payroll', 'calculate_payroll' => 'dental_doctor_payroll', 'payroll_payment' => 'dental_doctor_payroll',
    'save_equipment' => 'dental_equipment', 'delete_equipment' => 'dental_equipment', 'save_equipment_maintenance' => 'dental_equipment', 'save_instrument_set' => 'dental_equipment', 'delete_instrument_set' => 'dental_equipment', 'complete_sterilization_cycle' => 'dental_equipment',
    'close_accounting_period' => 'dental_management_accounting', 'save_supplier' => 'dental_procurement',
    'create_purchase_order' => 'dental_procurement', 'approve_purchase_order' => 'dental_procurement', 'mark_purchase_ordered' => 'dental_procurement', 'receive_purchase_order' => 'dental_procurement',
    'create_supplier_invoice' => 'dental_procurement', 'pay_supplier_invoice' => 'dental_procurement',
    'save_expense' => 'dental_financial_analytics', 'mark_expense_paid' => 'dental_financial_analytics', 'cancel_expense' => 'dental_financial_analytics',
    'save_service_material_template' => 'dental_inventory', 'deactivate_service_material_template' => 'dental_inventory',
    'save_category' => 'dental_inventory', 'save_unit' => 'dental_inventory', 'save_inventory_item' => 'dental_inventory',
];
if (in_array($action, ['overview', 'alerts'], true)) requirePlanFeature($context, 'dental_inventory');
if ($action === 'set_active') {
    $activeFeature = match ((string)($data['kind'] ?? '')) { 'supplier' => 'dental_procurement', 'payroll_rule' => 'dental_doctor_payroll', default => 'dental_inventory' };
    requirePlanFeature($context, $activeFeature);
}
elseif (isset($dentalFeatureByAction[$action])) requirePlanFeature($context, $dentalFeatureByAction[$action]);
$reportFrom = trim((string)($_GET['from'] ?? date('Y-m-01')));
$reportTo = trim((string)($_GET['to'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportTo) || $reportTo < $reportFrom) jsonResponse(false, 'Перевірте дати звітного періоду.', [], 422);
$id = static fn(): string => bin2hex(random_bytes(16));
$text = static function (string $key, int $max, bool $required = false) use (&$data): ?string {
    $value = trim((string)($data[$key] ?? ''));
    if ($required && $value === '') jsonResponse(false, 'Заповніть обов’язкове поле.', ['field' => $key], 422);
    return $value === '' ? null : mb_substr($value, 0, $max);
};
$decimal = static function (string $key, bool $required = false) use (&$data): string {
    $value = trim((string)($data[$key] ?? '0'));
    if ($required && $value === '' || !is_numeric($value) || (float)$value < 0 || (float)$value > 9999999999.9999) jsonResponse(false, 'Введіть коректне невід’ємне число.', ['field' => $key], 422);
    return number_format((float)$value, 4, '.', '');
};
$manage = static function () use ($canManage): void {
    if (!$canManage) jsonResponse(false, 'Керувати довідниками може менеджер або власник стоматологічного простору.', [], 403);
};
$audit = static function (string $entityType, string $entityId, string $event, array $details = []) use ($pdo, $ownerId, $userId): void {
    $pdo->prepare('INSERT INTO dental_erp_audit (id, owner_id, actor_id, entity_type, entity_id, action, details) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([bin2hex(random_bytes(16)), $ownerId, $userId, $entityType, $entityId, $event, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
};

require __DIR__ . '/dentistry-erp-operations.php';

if ($method === 'GET' && $action === 'overview') {
    $categories = $pdo->prepare('SELECT id, name FROM dental_erp_inventory_categories WHERE owner_id = ? AND is_active = 1 ORDER BY name');
    $units = $pdo->prepare('SELECT id, name, symbol, decimal_places FROM dental_erp_inventory_units WHERE owner_id = ? AND is_active = 1 ORDER BY name');
    foreach ([$categories, $units] as $query) $query->execute([$ownerId]);
    $suppliers = $pdo->prepare('SELECT id, name, contact_person, phone, email, address, tax_details, payment_terms, notes, is_active, created_at FROM dental_erp_suppliers WHERE owner_id = ? ORDER BY is_active DESC, name');
    $suppliers->execute([$ownerId]);
    $items = $pdo->prepare('SELECT i.id, i.name, i.sku, i.item_type, i.purchase_unit_cost, i.minimum_stock, i.optimal_stock, i.is_active, i.category_id, i.unit_id, i.default_supplier_id, c.name AS category_name, u.name AS unit_name, u.symbol AS unit_symbol, s.name AS supplier_name, COALESCE((SELECT SUM(b.available_quantity) FROM dental_erp_inventory_batches b WHERE b.owner_id = i.owner_id AND b.item_id = i.id AND b.is_blocked = 0 AND (b.expiration_date IS NULL OR b.expiration_date >= CURRENT_DATE)), 0) AS available_stock, COALESCE((SELECT SUM(b.available_quantity) FROM dental_erp_inventory_batches b WHERE b.owner_id = i.owner_id AND b.item_id = i.id AND b.expiration_date < CURRENT_DATE), 0) AS expired_stock, (SELECT MIN(b.expiration_date) FROM dental_erp_inventory_batches b WHERE b.owner_id = i.owner_id AND b.item_id = i.id AND b.available_quantity > 0 AND b.is_blocked = 0 AND b.expiration_date >= CURRENT_DATE) AS nearest_expiration FROM dental_erp_inventory_items i JOIN dental_erp_inventory_categories c ON c.id = i.category_id AND c.owner_id = i.owner_id JOIN dental_erp_inventory_units u ON u.id = i.unit_id AND u.owner_id = i.owner_id LEFT JOIN dental_erp_suppliers s ON s.id = i.default_supplier_id AND s.owner_id = i.owner_id WHERE i.owner_id = ? ORDER BY i.is_active DESC, i.name');
    $items->execute([$ownerId]);
    $itemRows = $items->fetchAll();
    $orders = $pdo->prepare('SELECT o.*, s.name AS supplier_name, (SELECT COUNT(*) FROM dental_erp_purchase_order_items oi WHERE oi.owner_id = o.owner_id AND oi.purchase_order_id = o.id) AS item_count FROM dental_erp_purchase_orders o JOIN dental_erp_suppliers s ON s.owner_id = o.owner_id AND s.id = o.supplier_id WHERE o.owner_id = ? ORDER BY o.created_at DESC LIMIT 100');
    $orders->execute([$ownerId]);
    $orderItems = $pdo->prepare('SELECT i.*, p.name AS item_name, u.symbol AS unit_symbol FROM dental_erp_purchase_order_items i JOIN dental_erp_inventory_items p ON p.owner_id = i.owner_id AND p.id = i.inventory_item_id JOIN dental_erp_inventory_units u ON u.owner_id = p.owner_id AND u.id = p.unit_id WHERE i.owner_id = ? ORDER BY i.created_at');
    $orderItems->execute([$ownerId]);
    $orderItemsByOrder = [];
    foreach ($orderItems->fetchAll() as $orderItem) $orderItemsByOrder[(string)$orderItem['purchase_order_id']][] = $orderItem;
    $orderRows = $orders->fetchAll();
    foreach ($orderRows as &$orderRow) $orderRow['items'] = $orderItemsByOrder[(string)$orderRow['id']] ?? [];
    unset($orderRow);
    $supplierInvoices = $pdo->prepare('SELECT i.*, COALESCE((SELECT SUM(r.credit_amount) FROM dental_erp_supplier_returns r WHERE r.owner_id=i.owner_id AND r.supplier_invoice_id=i.id),0) credit_amount, COALESCE((SELECT SUM(r.amount) FROM dental_erp_supplier_refunds r WHERE r.owner_id=i.owner_id AND r.invoice_id=i.id),0) refunded_amount, s.name AS supplier_name, o.order_number FROM dental_erp_supplier_invoices i JOIN dental_erp_suppliers s ON s.owner_id = i.owner_id AND s.id = i.supplier_id LEFT JOIN dental_erp_purchase_orders o ON o.owner_id = i.owner_id AND o.id = i.purchase_order_id WHERE i.owner_id = ? ORDER BY i.invoice_date DESC, i.created_at DESC LIMIT 100');
    $supplierInvoices->execute([$ownerId]);
    $expenseCategories = $pdo->prepare('SELECT id, name FROM dental_erp_expense_categories WHERE owner_id = ? AND is_active = 1 ORDER BY name'); $expenseCategories->execute([$ownerId]);
    $expenses = $pdo->prepare('SELECT e.*, c.name AS category_name, s.name AS supplier_name FROM dental_erp_expenses e JOIN dental_erp_expense_categories c ON c.owner_id = e.owner_id AND c.id = e.category_id LEFT JOIN dental_erp_suppliers s ON s.owner_id = e.owner_id AND s.id = e.supplier_id WHERE e.owner_id = ? ORDER BY e.expense_date DESC, e.created_at DESC LIMIT 200'); $expenses->execute([$ownerId]);
    $services = [];
    $serviceTable = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_catalog'"); $serviceTable->execute();
    if ((int)$serviceTable->fetchColumn()) {
        $serviceColumns = array_column($pdo->query('SHOW COLUMNS FROM service_catalog')->fetchAll(), 'Field');
        if (in_array('module', $serviceColumns, true) && in_array('created_by', $serviceColumns, true)) {
            $serviceQuery = $pdo->prepare("SELECT id, name FROM service_catalog WHERE created_by = ? AND module = 'dentistry' AND active = 1 ORDER BY name"); $serviceQuery->execute([$ownerId]); $services = $serviceQuery->fetchAll();
        }
    }
    $templates = [];
    if ($services) {
        $templateQuery = $pdo->prepare('SELECT t.id, t.service_id, t.inventory_item_id, t.expected_quantity, i.name AS item_name, u.symbol AS unit_symbol, s.name AS service_name FROM dental_erp_service_material_templates t JOIN dental_erp_inventory_items i ON i.owner_id = t.owner_id AND i.id = t.inventory_item_id JOIN dental_erp_inventory_units u ON u.owner_id = i.owner_id AND u.id = i.unit_id JOIN service_catalog s ON s.id = t.service_id WHERE t.owner_id = ? AND t.is_active = 1 ORDER BY s.name, i.name'); $templateQuery->execute([$ownerId]); $templates = $templateQuery->fetchAll();
    }
    $movements = $pdo->prepare('SELECT m.id, m.movement_type, m.quantity, m.unit_cost, m.note, m.created_at, i.name AS item_name, u.symbol AS unit_symbol, l.name AS location_name, a.name AS actor_name FROM dental_erp_stock_movements m JOIN dental_erp_inventory_items i ON i.owner_id = m.owner_id AND i.id = m.item_id JOIN dental_erp_inventory_units u ON u.owner_id = i.owner_id AND u.id = i.unit_id JOIN dental_erp_inventory_locations l ON l.owner_id = m.owner_id AND l.id = m.location_id LEFT JOIN users a ON a.id = m.actor_id WHERE m.owner_id = ? ORDER BY m.created_at DESC LIMIT 200'); $movements->execute([$ownerId]);
    $equipment = $pdo->prepare('SELECT e.*, s.name AS supplier_name, (SELECT MAX(m.maintenance_date) FROM dental_erp_equipment_maintenance m WHERE m.owner_id = e.owner_id AND m.equipment_id = e.id) AS last_maintenance, (SELECT MIN(m.next_due_date) FROM dental_erp_equipment_maintenance m WHERE m.owner_id = e.owner_id AND m.equipment_id = e.id AND m.next_due_date >= CURRENT_DATE) AS next_maintenance FROM dental_erp_equipment e LEFT JOIN dental_erp_suppliers s ON s.owner_id = e.owner_id AND s.id = e.supplier_id WHERE e.owner_id = ? ORDER BY e.status, e.name'); $equipment->execute([$ownerId]);
    $maintenance = $pdo->prepare('SELECT m.*, e.name AS equipment_name FROM dental_erp_equipment_maintenance m JOIN dental_erp_equipment e ON e.owner_id = m.owner_id AND e.id = m.equipment_id WHERE m.owner_id = ? ORDER BY m.maintenance_date DESC LIMIT 100'); $maintenance->execute([$ownerId]);
    $instrumentSets = $pdo->prepare('SELECT s.*, i.name AS item_name, (SELECT COUNT(*) FROM dental_erp_sterilization_cycles c WHERE c.owner_id = s.owner_id AND c.instrument_set_id = s.id) AS sterilization_cycles, (SELECT MAX(c.completed_at) FROM dental_erp_sterilization_cycles c WHERE c.owner_id = s.owner_id AND c.instrument_set_id = s.id AND c.result = \'passed\') AS last_sterilized FROM dental_erp_instrument_sets s LEFT JOIN dental_erp_inventory_items i ON i.owner_id = s.owner_id AND i.id = s.inventory_item_id WHERE s.owner_id = ? ORDER BY s.status, s.name'); $instrumentSets->execute([$ownerId]);
    $sterilizationCycles = $pdo->prepare('SELECT c.*, s.name AS set_name, u.name AS performed_by_name FROM dental_erp_sterilization_cycles c JOIN dental_erp_instrument_sets s ON s.owner_id = c.owner_id AND s.id = c.instrument_set_id LEFT JOIN users u ON u.id = c.performed_by WHERE c.owner_id = ? ORDER BY c.started_at DESC LIMIT 100'); $sterilizationCycles->execute([$ownerId]);
    $users = [];
    $workspaceUserIds = array_values(array_unique(array_merge([$ownerId], array_map('strval', (array)($context['member_ids'] ?? [])))));
    if ($workspaceUserIds) { $userMarks = implode(',', array_fill(0, count($workspaceUserIds), '?')); $team = $pdo->prepare("SELECT id, name, email FROM users WHERE id IN ({$userMarks}) ORDER BY name"); $team->execute($workspaceUserIds); $users = $team->fetchAll(); }
    $payrollRules = $pdo->prepare("SELECT r.*, u.name AS user_name, u.email FROM dental_erp_payroll_rules r JOIN users u ON u.id = r.user_id WHERE r.owner_id = ? ORDER BY r.is_active DESC, u.name"); $payrollRules->execute([$ownerId]);
    $payrollPeriods = $pdo->prepare('SELECT * FROM dental_erp_payroll_periods WHERE owner_id = ? ORDER BY starts_on DESC LIMIT 36'); $payrollPeriods->execute([$ownerId]);
    $payrollAccruals = $pdo->prepare('SELECT a.*, u.name AS user_name, p.starts_on, p.ends_on, p.status AS period_status FROM dental_erp_payroll_accruals a JOIN users u ON u.id = a.user_id JOIN dental_erp_payroll_periods p ON p.owner_id = a.owner_id AND p.id = a.period_id WHERE a.owner_id = ? ORDER BY p.starts_on DESC, u.name LIMIT 300'); $payrollAccruals->execute([$ownerId]);
    $journalRows = $pdo->prepare('SELECT j.id, j.source_type, j.source_id, j.entry_date, j.description, j.status, p.starts_on, p.ends_on, COALESCE(SUM(e.debit),0) AS debit_total, COALESCE(SUM(e.credit),0) AS credit_total FROM dental_erp_accounting_journals j JOIN dental_erp_accounting_periods p ON p.owner_id = j.owner_id AND p.id = j.period_id LEFT JOIN dental_erp_accounting_entries e ON e.owner_id = j.owner_id AND e.journal_id = j.id WHERE j.owner_id = ? GROUP BY j.id ORDER BY j.entry_date DESC, j.created_at DESC LIMIT 300'); $journalRows->execute([$ownerId]);
    $accountingPeriods = $pdo->prepare('SELECT * FROM dental_erp_accounting_periods WHERE owner_id = ? ORDER BY starts_on DESC LIMIT 36'); $accountingPeriods->execute([$ownerId]);
    $journalLines = $pdo->prepare('SELECT e.journal_id, a.account_code, a.name AS account_name, e.debit, e.credit, e.memo FROM dental_erp_accounting_entries e JOIN dental_erp_accounting_accounts a ON a.owner_id = e.owner_id AND a.id = e.account_id JOIN dental_erp_accounting_journals j ON j.owner_id = e.owner_id AND j.id = e.journal_id WHERE e.owner_id = ? ORDER BY j.entry_date DESC, e.created_at'); $journalLines->execute([$ownerId]);
    $accountLines = []; foreach ($journalLines->fetchAll() as $line) $accountLines[(string)$line['journal_id']][] = $line;
    $journalResult = $journalRows->fetchAll();
    foreach ($journalResult as &$journalRow) $journalRow['entries'] = $accountLines[(string)$journalRow['id']] ?? []; unset($journalRow);
    $financial = ['revenue' => 0, 'collected' => 0, 'expenses' => 0, 'cash_expenses' => 0, 'payroll' => 0, 'material_cost' => 0, 'receivables' => 0, 'supplier_debt' => 0, 'inventory_value' => 0];
    $patientScope = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN DATE(i.created_at) BETWEEN ? AND ? THEN i.total_amount ELSE 0 END),0) revenue, COALESCE(SUM(i.remaining_amount),0) receivables FROM dental_invoices i JOIN clients c ON c.id = i.patient_id WHERE c.created_by = ? AND i.status <> 'cancelled'"); $patientScope->execute([$reportFrom, $reportTo, $ownerId]); $invoiceTotals = $patientScope->fetch();
    $paidScope = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN p.payment_type = 'refund' THEN -p.amount ELSE p.amount END),0) FROM dental_payments p JOIN clients c ON c.id = p.patient_id WHERE c.created_by = ? AND DATE(p.payment_date) BETWEEN ? AND ?"); $paidScope->execute([$ownerId, $reportFrom, $reportTo]);
    $financial['revenue'] = (float)($invoiceTotals['revenue'] ?? 0); $financial['receivables'] = (float)($invoiceTotals['receivables'] ?? 0); $financial['collected'] = (float)$paidScope->fetchColumn();
    $expenseTotals = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN expense_date BETWEEN ? AND ? THEN amount ELSE 0 END),0) expenses, COALESCE(SUM(CASE WHEN payment_status = 'paid' AND DATE(paid_at) BETWEEN ? AND ? THEN amount ELSE 0 END),0) cash_expenses FROM dental_erp_expenses WHERE owner_id = ? AND payment_status <> 'cancelled'"); $expenseTotals->execute([$reportFrom, $reportTo, $reportFrom, $reportTo, $ownerId]); $expenseRow = $expenseTotals->fetch(); $financial['expenses'] = (float)$expenseRow['expenses']; $financial['cash_expenses'] = (float)$expenseRow['cash_expenses'];
    $materialTotals = $pdo->prepare('SELECT COALESCE(SUM(m.total_cost),0) FROM dental_erp_performed_service_materials m JOIN dental_performed_services s ON s.id = m.performed_service_id JOIN clients c ON c.id = s.patient_id WHERE m.owner_id = ? AND c.created_by = ? AND DATE(s.performed_at) BETWEEN ? AND ?'); $materialTotals->execute([$ownerId, $ownerId, $reportFrom, $reportTo]); $financial['material_cost'] = (float)$materialTotals->fetchColumn();
    $payrollTotals = $pdo->prepare('SELECT COALESCE(SUM(a.accrued_amount),0) FROM dental_erp_payroll_accruals a JOIN dental_erp_payroll_periods p ON p.owner_id = a.owner_id AND p.id = a.period_id WHERE a.owner_id = ? AND p.starts_on BETWEEN ? AND ?'); $payrollTotals->execute([$ownerId, $reportFrom, $reportTo]); $financial['payroll'] = (float)$payrollTotals->fetchColumn();
    $debtTotals = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(0,i.amount-i.paid_amount-COALESCE((SELECT SUM(r.credit_amount) FROM dental_erp_supplier_returns r WHERE r.owner_id=i.owner_id AND r.supplier_invoice_id=i.id),0))),0) FROM dental_erp_supplier_invoices i WHERE i.owner_id=? AND i.status<>'cancelled'"); $debtTotals->execute([$ownerId]); $financial['supplier_debt'] = (float)$debtTotals->fetchColumn();
    $stockTotals = $pdo->prepare('SELECT COALESCE(SUM(b.available_quantity * b.purchase_unit_cost),0) FROM dental_erp_inventory_batches b WHERE b.owner_id = ? AND b.is_blocked = 0 AND (b.expiration_date IS NULL OR b.expiration_date >= CURRENT_DATE)'); $stockTotals->execute([$ownerId]); $financial['inventory_value'] = (float)$stockTotals->fetchColumn();
    $periodLedger = dentalErpRows($pdo,"SELECT a.account_code,SUM(e.debit-e.credit) net,SUM(e.credit) credit FROM dental_erp_accounting_entries e JOIN dental_erp_accounting_accounts a ON a.id=e.account_id AND a.owner_id=e.owner_id JOIN dental_erp_accounting_journals j ON j.id=e.journal_id AND j.owner_id=e.owner_id WHERE e.owner_id=? AND j.entry_date BETWEEN ? AND ? GROUP BY a.account_code",[$ownerId,$reportFrom,$reportTo]);
    $ledgerTotals = array_column($periodLedger,null,'account_code');
    $financial['material_cost'] = (float)($ledgerTotals['801']['net']??0);
    $financial['expenses'] = (float)($ledgerTotals['811']['net']??0);
    $financial['payroll'] = (float)($ledgerTotals['821']['net']??0);
    $financial['direct_expenses'] = (float)($ledgerTotals['831']['net']??0);
    $financial['depreciation'] = (float)($ledgerTotals['841']['net']??0);
    $financial['cash_outflows'] = (float)($ledgerTotals['101']['credit']??0);
    $financial['gross_margin'] = $financial['revenue']-$financial['material_cost']-$financial['direct_expenses'];
    $financial['operating_profit'] = $financial['gross_margin']-$financial['expenses']-$financial['payroll']-$financial['depreciation'];
    $recommendations = [];
    foreach ($itemRows as $stockItem) if ((int)$stockItem['is_active'] && (float)$stockItem['available_stock'] < (float)$stockItem['minimum_stock']) $recommendations[] = ['item_id' => $stockItem['id'], 'name' => $stockItem['name'], 'available' => $stockItem['available_stock'], 'minimum' => $stockItem['minimum_stock'], 'optimal' => $stockItem['optimal_stock'], 'suggested_quantity' => max(0, (float)$stockItem['optimal_stock'] - (float)$stockItem['available_stock']), 'unit_symbol' => $stockItem['unit_symbol'], 'supplier_id' => $stockItem['default_supplier_id'], 'supplier_name' => $stockItem['supplier_name']];
    $canProcure = !empty($context['plan']['dental_procurement']);
    $canPayroll = !empty($context['plan']['dental_doctor_payroll']);
    $canEquipment = !empty($context['plan']['dental_equipment']);
    $canFinance = $canViewFinance && !empty($context['plan']['dental_financial_analytics']);
    if (!$canProcure) foreach ($itemRows as &$itemRow) { $itemRow['default_supplier_id'] = null; $itemRow['supplier_name'] = null; } unset($itemRow);
    jsonResponse(true, 'Дані стоматологічної ERP завантажено.', [
        'categories' => $categories->fetchAll(), 'units' => $units->fetchAll(),
        'suppliers' => $canProcure ? $suppliers->fetchAll() : [], 'items' => $itemRows, 'purchase_orders' => $canProcure ? $orderRows : [],
        'supplier_invoices' => $canProcure ? $supplierInvoices->fetchAll() : [], 'expense_categories' => $canFinance ? $expenseCategories->fetchAll() : [], 'expenses' => $canFinance ? $expenses->fetchAll() : [],
        'services' => $services, 'service_material_templates' => $templates,
        'movements' => $movements->fetchAll(), 'equipment' => $canEquipment ? $equipment->fetchAll() : [], 'maintenance' => $canEquipment ? $maintenance->fetchAll() : [], 'instrument_sets' => $canEquipment ? $instrumentSets->fetchAll() : [], 'sterilization_cycles' => $canEquipment ? $sterilizationCycles->fetchAll() : [],
        'team' => $canPayroll ? $users : [], 'payroll_rules' => $canPayroll ? $payrollRules->fetchAll() : [], 'payroll_periods' => $canPayroll ? $payrollPeriods->fetchAll() : [], 'payroll_accruals' => $canPayroll ? $payrollAccruals->fetchAll() : [],
        'accounting_accounts' => $canFinance ? $pdo->query("SELECT id, account_code, name, account_type FROM dental_erp_accounting_accounts WHERE owner_id = " . $pdo->quote($ownerId) . " AND is_active = 1 ORDER BY account_code")->fetchAll() : [],
        'accounting_journals' => $canFinance ? $journalResult : [], 'accounting_periods' => $canFinance ? $accountingPeriods->fetchAll() : [], 'financial' => $canFinance ? $financial : ($canViewFinance && !empty($context['plan']['dental_basic_reports']) ? ['revenue' => $financial['revenue'], 'collected' => $financial['collected'], 'receivables' => $financial['receivables']] : null), 'purchase_recommendations' => $canProcure ? dentalErpRecommendations($pdo, $ownerId) : [],
        'financial_period' => ['from' => $reportFrom, 'to' => $reportTo],
        'permissions' => ['manage' => $canManage, 'view_finance' => $canFinance, 'features' => array_intersect_key($context['plan'], array_flip(dentalPlanFeatures()))],
    ]);
}

if ($method !== 'POST') jsonResponse(false, 'Невідома дія.', [], 404);
$manage();

if ($action === 'save_payroll_rule') {
    $employeeId = trim((string)($data['user_id'] ?? ''));
    $allowedUsers = array_fill_keys(array_map('strval', array_merge([$ownerId], (array)($context['member_ids'] ?? []))), true);
    if (!isset($allowedUsers[$employeeId])) jsonResponse(false, 'Співробітника не знайдено у цьому стоматологічному просторі.', [], 422);
    $type = (string)($data['calculation_type'] ?? ''); $basis = (string)($data['calculation_basis'] ?? 'performed');
    if (!in_array($type, ['fixed', 'percentage', 'per_procedure', 'combined'], true) || !in_array($basis, ['performed', 'collected'], true)) jsonResponse(false, 'Перевірте модель та базу розрахунку.', [], 422);
    $fixed = $decimal('fixed_amount'); $percentage = $decimal('percentage'); $perProcedure = $decimal('per_procedure_amount');
    if ((float)$percentage > 100) jsonResponse(false, 'Відсоткова ставка не може перевищувати 100%.', [], 422);
    $ruleId = $id();
    $pdo->prepare('INSERT INTO dental_erp_payroll_rules (id, owner_id, user_id, calculation_type, calculation_basis, fixed_amount, percentage, per_procedure_amount, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE calculation_type = VALUES(calculation_type), calculation_basis = VALUES(calculation_basis), fixed_amount = VALUES(fixed_amount), percentage = VALUES(percentage), per_procedure_amount = VALUES(per_procedure_amount), is_active = 1')
        ->execute([$ruleId, $ownerId, $employeeId, $type, $basis, $fixed, $percentage, $perProcedure, $userId]);
    $query = $pdo->prepare('SELECT id FROM dental_erp_payroll_rules WHERE owner_id = ? AND user_id = ?'); $query->execute([$ownerId, $employeeId]); $ruleId = (string)$query->fetchColumn();
    $audit('payroll_rule', $ruleId, 'saved', ['user_id' => $employeeId, 'type' => $type, 'basis' => $basis]);
    jsonResponse(true, 'Правило винагороди збережено.');
}

if ($action === 'calculate_payroll') {
    $starts = trim((string)($data['starts_on'] ?? '')); $ends = trim((string)($data['ends_on'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $starts) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ends) || $ends < $starts) jsonResponse(false, 'Перевірте дати розрахункового періоду.', [], 422);
    $periodId = $id();
    $pdo->beginTransaction();
    try {
        $workspaceLock = $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE'); $workspaceLock->execute([$ownerId]);
        $overlap = dentalErpRows($pdo,'SELECT id FROM dental_erp_payroll_periods WHERE owner_id=? AND starts_on<=? AND ends_on>=? AND NOT(starts_on=? AND ends_on=?) FOR UPDATE',[$ownerId,$ends,$starts,$starts,$ends]);
        if ($overlap) throw new DomainException('Розрахунковий період перетинається з уже створеним. Оберіть інші дати.');
        $pdo->prepare("INSERT IGNORE INTO dental_erp_payroll_periods (id, owner_id, starts_on, ends_on, status, created_by) VALUES (?, ?, ?, ?, 'open', ?)")->execute([$periodId, $ownerId, $starts, $ends, $userId]);
        $periodQuery = $pdo->prepare('SELECT id, status FROM dental_erp_payroll_periods WHERE owner_id = ? AND starts_on = ? AND ends_on = ? FOR UPDATE'); $periodQuery->execute([$ownerId, $starts, $ends]); $period = $periodQuery->fetch();
        if (!$period || $period['status'] !== 'open') throw new DomainException('Період уже розраховано або закрито. Створіть новий період для коригування.');
        $rules = $pdo->prepare('SELECT * FROM dental_erp_payroll_rules WHERE owner_id = ? AND is_active = 1 FOR UPDATE'); $rules->execute([$ownerId]);
        $performed = $pdo->prepare('SELECT COALESCE(SUM(s.total_price),0) amount, COUNT(*) procedures FROM dental_performed_services s JOIN clients c ON c.id = s.patient_id WHERE c.created_by = ? AND s.doctor_id = ? AND DATE(s.performed_at) BETWEEN ? AND ?');
        $collected = $pdo->prepare("SELECT COALESCE(SUM(ii.total_price * a.amount / NULLIF(i.subtotal,0) * CASE WHEN p.payment_type = 'refund' THEN -1 ELSE 1 END),0) amount, COUNT(DISTINCT s.id) procedures FROM dental_performed_services s JOIN clients c ON c.id = s.patient_id JOIN dental_invoice_items ii ON ii.performed_service_id = s.id JOIN dental_invoices i ON i.id = ii.invoice_id JOIN dental_payment_allocations a ON a.invoice_id = i.id JOIN dental_payments p ON p.id = a.payment_id WHERE c.created_by = ? AND s.doctor_id = ? AND DATE(p.payment_date) BETWEEN ? AND ? AND i.status <> 'cancelled'");
        $saveAccrual = $pdo->prepare('INSERT INTO dental_erp_payroll_accruals (id, owner_id, period_id, user_id, basis_amount, procedures_count, accrued_amount, details) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE basis_amount = VALUES(basis_amount), procedures_count = VALUES(procedures_count), accrued_amount = VALUES(accrued_amount), details = VALUES(details)');
        foreach ($rules->fetchAll() as $rule) {
            ($rule['calculation_basis'] === 'collected' ? $collected : $performed)->execute([$ownerId, $rule['user_id'], $starts, $ends]);
            $basisRow = ($rule['calculation_basis'] === 'collected' ? $collected : $performed)->fetch();
            $basisCents = max(0, (int)round((float)$basisRow['amount'] * 100)); $procedureCount = (int)$basisRow['procedures'];
            $fixedCents = (int)round((float)$rule['fixed_amount'] * 100);
            $percentageCents = (int)round($basisCents * (float)$rule['percentage'] / 100);
            $procedureCents = $procedureCount * (int)round((float)$rule['per_procedure_amount'] * 100);
            $amountCents = in_array($rule['calculation_type'], ['percentage', 'per_procedure'], true) ? ($rule['calculation_type'] === 'percentage' ? $percentageCents : $procedureCents) : ($rule['calculation_type'] === 'fixed' ? $fixedCents : $fixedCents + $percentageCents + $procedureCents);
            $accrualId = md5($ownerId . '|dental-payroll|' . $period['id'] . '|' . $rule['user_id']);
            $details = ['calculation_type' => $rule['calculation_type'], 'calculation_basis' => $rule['calculation_basis'], 'fixed_cents' => $fixedCents, 'percentage_cents' => $percentageCents, 'per_procedure_cents' => $procedureCents];
            $saveAccrual->execute([$accrualId, $ownerId, $period['id'], $rule['user_id'], number_format($basisCents / 100, 2, '.', ''), $procedureCount, number_format($amountCents / 100, 2, '.', ''), json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            if ($amountCents > 0) dentalErpPostJournal($pdo, $ownerId, $userId, 'payroll_accrual', $accrualId, $ends, 'Нарахування винагороди за ' . $starts . ' - ' . $ends, [
                ['account' => 'labor_expense', 'debit_cents' => $amountCents, 'credit_cents' => 0],
                ['account' => 'payroll_payable', 'debit_cents' => 0, 'credit_cents' => $amountCents],
            ]);
            $audit('payroll_accrual', $accrualId, 'calculated', ['basis_amount' => number_format($basisCents / 100, 2, '.', ''), 'procedures' => $procedureCount, 'amount' => number_format($amountCents / 100, 2, '.', '')]);
        }
        $pdo->prepare("UPDATE dental_erp_payroll_periods SET status = 'calculated' WHERE id = ? AND owner_id = ? AND status = 'open'")->execute([$period['id'], $ownerId]);
        $pdo->commit();
    } catch (DomainException $error) { if ($pdo->inTransaction()) $pdo->rollBack(); jsonResponse(false, $error->getMessage(), [], 422); }
    catch (Throwable $error) { error_log('Dentistry ERP ' . $action . ': ' . $error->getMessage()); if ($pdo->inTransaction()) $pdo->rollBack(); jsonResponse(false, 'Не вдалося розрахувати зарплату. Перевірте записи та правила оплати.', [], 500); }
    jsonResponse(true, 'Нарахування за період збережені та проведені у внутрішньому обліку.');
}

if ($action === 'payroll_payment') {
    $accrualId = trim((string)($data['accrual_id'] ?? '')); $amount = $decimal('amount', true); $key = trim((string)($data['idempotency_key'] ?? ''));
    if ((float)$amount <= 0 || !preg_match('/^[a-f0-9]{32}$/i', $key)) jsonResponse(false, 'Перевірте суму виплати та ключ операції.', [], 422);
    $method = (string)($data['payment_method'] ?? 'bank_transfer'); if (!in_array($method, ['cash', 'card', 'bank_transfer'], true)) jsonResponse(false, 'Оберіть спосіб виплати.', [], 422);
    $pdo->beginTransaction();
    try {
        $accrual = $pdo->prepare('SELECT a.*, u.name AS user_name FROM dental_erp_payroll_accruals a JOIN users u ON u.id = a.user_id WHERE a.id = ? AND a.owner_id = ? FOR UPDATE'); $accrual->execute([$accrualId, $ownerId]); $row = $accrual->fetch();
        if (!$row) throw new DomainException('Нарахування не знайдено.');
        $exists = $pdo->prepare('SELECT id,accrual_id,amount FROM dental_erp_payroll_payments WHERE owner_id = ? AND idempotency_key = ?'); $exists->execute([$ownerId, $key]); $previousPayment=$exists->fetch();
        if ($previousPayment && ($previousPayment['accrual_id']!==$accrualId || (int)round((float)$previousPayment['amount']*100)!==(int)round((float)$amount*100))) throw new DomainException('Ключ запиту вже використано для іншої виплати.');
        if (!$previousPayment) {
            $cents = (int)round((float)$amount * 100); $remaining = (int)round(((float)$row['accrued_amount'] - (float)$row['paid_amount']) * 100);
            if ($cents <= 0 || $cents > $remaining) throw new DomainException('Сума виплати перевищує залишок нарахування.');
            $paymentId = $id();
            $pdo->prepare('INSERT INTO dental_erp_payroll_payments (id, owner_id, accrual_id, amount, payment_date, payment_method, idempotency_key, comment, created_by) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?, ?, ?)')->execute([$paymentId, $ownerId, $accrualId, number_format($cents / 100, 2, '.', ''), $method, $key, $text('comment', 1000), $userId]);
            $paid = (int)round((float)$row['paid_amount'] * 100) + $cents;
            $pdo->prepare('UPDATE dental_erp_payroll_accruals SET paid_amount = ? WHERE id = ? AND owner_id = ?')->execute([number_format($paid / 100, 2, '.', ''), $accrualId, $ownerId]);
            dentalErpPostJournal($pdo, $ownerId, $userId, 'payroll_payment', $paymentId, date('Y-m-d'), 'Виплата · ' . $row['user_name'], [
                ['account' => 'payroll_payable', 'debit_cents' => $cents, 'credit_cents' => 0],
                ['account' => 'cash', 'debit_cents' => 0, 'credit_cents' => $cents],
            ]);
            $audit('payroll_payment', $paymentId, 'paid', ['accrual_id' => $accrualId, 'amount' => number_format($cents / 100, 2, '.', '')]);
        }
        $pdo->commit();
    } catch (DomainException $error) { if ($pdo->inTransaction()) $pdo->rollBack(); jsonResponse(false, $error->getMessage(), [], 422); }
    catch (Throwable $error) { error_log('Dentistry ERP ' . $action . ': ' . $error->getMessage()); if ($pdo->inTransaction()) $pdo->rollBack(); jsonResponse(false, 'Не вдалося записати виплату.', [], 500); }
    jsonResponse(true, 'Виплату записано.');
}

if ($action === 'save_equipment') {
    $equipmentId = trim((string)($data['id'] ?? '')); $name = $text('name', 190, true); $supplierId = $text('supplier_id', 32) ?: null;
    $status = (string)($data['status'] ?? 'active');
    if (!in_array($status, ['active', 'maintenance', 'retired', 'written_off'], true)) jsonResponse(false, 'Оберіть коректний стан обладнання.', [], 422);
    if ($supplierId !== null) { $supplier = $pdo->prepare('SELECT id FROM dental_erp_suppliers WHERE id = ? AND owner_id = ?'); $supplier->execute([$supplierId, $ownerId]); if (!$supplier->fetchColumn()) jsonResponse(false, 'Постачальника не знайдено у цьому просторі.', [], 422); }
    $purchaseDate = $text('purchase_date', 10); $warranty = $text('warranty_until', 10);
    foreach ([$purchaseDate, $warranty] as $date) if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) jsonResponse(false, 'Перевірте дати придбання та гарантії.', [], 422);
    $cost = $decimal('purchase_cost'); $life = trim((string)($data['useful_life_months'] ?? ''));
    if ($life !== '' && (!ctype_digit($life) || (int)$life < 1 || (int)$life > 1200)) jsonResponse(false, 'Строк корисного використання має бути від 1 до 1200 місяців.', [], 422);
    if ($equipmentId !== '') {
        $original = dentalErpRows($pdo,'SELECT * FROM dental_erp_equipment WHERE id=? AND owner_id=?',[$equipmentId,$ownerId])[0]??null;
        $hasDepreciation = dentalErpRows($pdo,'SELECT id FROM dental_erp_depreciation WHERE equipment_id=? AND owner_id=? LIMIT 1',[$equipmentId,$ownerId]);
        if ($original && $hasDepreciation && ((float)$original['purchase_cost'] !== (float)$cost || (string)$original['purchase_date'] !== (string)$purchaseDate || (string)$original['useful_life_months'] !== $life)) jsonResponse(false,'Після нарахування амортизації не можна змінювати первісну вартість, дату чи строк використання.',[],409);
        $stmt = $pdo->prepare('UPDATE dental_erp_equipment SET name = ?, inventory_number = ?, serial_number = ?, purchase_date = ?, purchase_cost = ?, supplier_id = ?, warranty_until = ?, location = ?, status = ?, useful_life_months = ? WHERE id = ? AND owner_id = ?');
        $stmt->execute([$name, $text('inventory_number', 80), $text('serial_number', 120), $purchaseDate, $cost, $supplierId, $warranty, $text('location', 190), $status, $life !== '' ? (int)$life : null, $equipmentId, $ownerId]);
        if (!$stmt->rowCount()) { $check = $pdo->prepare('SELECT id FROM dental_erp_equipment WHERE id = ? AND owner_id = ?'); $check->execute([$equipmentId, $ownerId]); if (!$check->fetchColumn()) jsonResponse(false, 'Обладнання не знайдено.', [], 404); }
    } else {
        $equipmentId = $id();
        $pdo->prepare('INSERT INTO dental_erp_equipment (id, owner_id, name, inventory_number, serial_number, purchase_date, purchase_cost, supplier_id, warranty_until, location, status, useful_life_months, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$equipmentId, $ownerId, $name, $text('inventory_number', 80), $text('serial_number', 120), $purchaseDate, $cost, $supplierId, $warranty, $text('location', 190), $status, $life !== '' ? (int)$life : null, $userId]);
    }
    $audit('equipment', $equipmentId, 'saved', ['name' => $name, 'status' => $status]);
    jsonResponse(true, 'Обладнання збережено.');
}

if ($action === 'delete_equipment') {
    $equipmentId=trim((string)($data['id']??''));
    $q=$pdo->prepare('SELECT id,name FROM dental_erp_equipment WHERE id=? AND owner_id=?'); $q->execute([$equipmentId,$ownerId]); $row=$q->fetch();
    if (!$row) jsonResponse(false,'Обладнання не знайдено.',[],404);
    $history=$pdo->prepare('SELECT (SELECT COUNT(*) FROM dental_erp_equipment_maintenance WHERE owner_id=? AND equipment_id=?) + (SELECT COUNT(*) FROM dental_erp_depreciation WHERE owner_id=? AND equipment_id=?)'); $history->execute([$ownerId,$equipmentId,$ownerId,$equipmentId]);
    if ((int)$history->fetchColumn()>0) jsonResponse(false,'Є історія сервісу або амортизації. Позначте обладнання як списане, щоб зберегти облік.',[],409);
    $pdo->prepare('DELETE FROM dental_erp_equipment WHERE id=? AND owner_id=?')->execute([$equipmentId,$ownerId]);
    $audit('equipment',$equipmentId,'deleted',['name'=>$row['name']]);
    jsonResponse(true,'Обладнання видалено.');
}

if ($action === 'save_equipment_maintenance') {
    $equipmentId = trim((string)($data['equipment_id'] ?? '')); $equipment = $pdo->prepare('SELECT id, name FROM dental_erp_equipment WHERE id = ? AND owner_id = ?'); $equipment->execute([$equipmentId, $ownerId]); $equipmentRow = $equipment->fetch();
    if (!$equipmentRow) jsonResponse(false, 'Обладнання не знайдено.', [], 422);
    $date = $text('maintenance_date', 10, true); $next = $text('next_due_date', 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ($next !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $next))) jsonResponse(false, 'Перевірте дату обслуговування.', [], 422);
    $cost = $decimal('cost'); $maintenanceId = $id();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO dental_erp_equipment_maintenance (id, owner_id, equipment_id, maintenance_date, description, cost, next_due_date, performed_by, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$maintenanceId, $ownerId, $equipmentId, $date, $text('description', 1000, true), $cost, $next, $text('performed_by', 190), $userId]);
        $pdo->prepare("UPDATE dental_erp_equipment SET status = 'active' WHERE id = ? AND owner_id = ? AND status = 'maintenance'")->execute([$equipmentId, $ownerId]);
        if ((float)$cost > 0) {
            $costCents = (int)round((float)$cost * 100);
            dentalErpPostJournal($pdo, $ownerId, $userId, 'equipment_maintenance', $maintenanceId, $date, 'Обслуговування обладнання · ' . $equipmentRow['name'], [
                ['account' => 'operating_expense', 'debit_cents' => $costCents, 'credit_cents' => 0],
                ['account' => 'cash', 'debit_cents' => 0, 'credit_cents' => $costCents],
            ]);
        }
        $audit('equipment_maintenance', $maintenanceId, 'created', ['equipment_id' => $equipmentId, 'cost' => $cost]);
        $pdo->commit();
    } catch (Throwable $error) { error_log('Dentistry ERP ' . $action . ': ' . $error->getMessage()); if ($pdo->inTransaction()) $pdo->rollBack(); jsonResponse(false, 'Не вдалося зберегти обслуговування обладнання.', [], 500); }
    jsonResponse(true, 'Обслуговування збережено.');
}

if ($action === 'save_instrument_set') {
    $setId = trim((string)($data['id'] ?? '')); $name = $text('name', 190, true); $code = $text('set_code', 80); $itemId = $text('inventory_item_id', 32);
    $status = (string)($data['status'] ?? 'available');
    if (!in_array($status, ['available', 'issued', 'sterilizing', 'repair', 'retired'], true)) jsonResponse(false, 'Оберіть коректний стан набору.', [], 422);
    if ($itemId !== null) { $item = $pdo->prepare("SELECT id FROM dental_erp_inventory_items WHERE id = ? AND owner_id = ? AND item_type = 'reusable_instrument'"); $item->execute([$itemId, $ownerId]); if (!$item->fetchColumn()) jsonResponse(false, 'Оберіть багаторазовий інструмент цього простору.', [], 422); }
    try {
        if ($setId !== '') {
            $pdo->prepare('UPDATE dental_erp_instrument_sets SET name = ?, inventory_item_id = ?, set_code = ?, status = ?, condition_note = ?, location = ? WHERE id = ? AND owner_id = ?')->execute([$name, $itemId, $code, $status, $text('condition_note', 500), $text('location', 190), $setId, $ownerId]);
            $exists = $pdo->prepare('SELECT id FROM dental_erp_instrument_sets WHERE id = ? AND owner_id = ?'); $exists->execute([$setId, $ownerId]); if (!$exists->fetchColumn()) jsonResponse(false, 'Набір інструментів не знайдено.', [], 404);
        } else {
            $setId = $id();
            $pdo->prepare('INSERT INTO dental_erp_instrument_sets (id, owner_id, name, inventory_item_id, set_code, status, condition_note, location, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$setId, $ownerId, $name, $itemId, $code, $status, $text('condition_note', 500), $text('location', 190), $userId]);
        }
        $audit('instrument_set', $setId, 'saved', ['name' => $name, 'status' => $status]);
    } catch (PDOException $error) { jsonResponse(false, 'Код набору вже використовується або не вдалося зберегти набір.', [], 409); }
    jsonResponse(true, 'Набір інструментів збережено.');
}

if ($action === 'delete_instrument_set') {
    $setId=trim((string)($data['id']??''));
    $q=$pdo->prepare('SELECT id,name FROM dental_erp_instrument_sets WHERE id=? AND owner_id=?'); $q->execute([$setId,$ownerId]); $row=$q->fetch();
    if (!$row) jsonResponse(false,'Набір інструментів не знайдено.',[],404);
    $cycles=$pdo->prepare('SELECT COUNT(*) FROM dental_erp_sterilization_cycles WHERE owner_id=? AND instrument_set_id=?'); $cycles->execute([$ownerId,$setId]);
    if ((int)$cycles->fetchColumn()>0) jsonResponse(false,'Є журнал стерилізації. Виведіть набір з обігу, щоб зберегти історію.',[],409);
    $pdo->prepare('DELETE FROM dental_erp_instrument_sets WHERE id=? AND owner_id=?')->execute([$setId,$ownerId]);
    $audit('instrument_set',$setId,'deleted',['name'=>$row['name']]);
    jsonResponse(true,'Набір інструментів видалено.');
}

if ($action === 'complete_sterilization_cycle') {
    $setId = trim((string)($data['instrument_set_id'] ?? '')); $cycleNumber = $text('cycle_number', 80); $result = (string)($data['result'] ?? 'passed');
    if (!in_array($result, ['passed', 'failed'], true)) jsonResponse(false, 'Оберіть результат циклу.', [], 422);
    $started = trim((string)($data['started_at'] ?? date('Y-m-d H:i:s'))); $completed = trim((string)($data['completed_at'] ?? date('Y-m-d H:i:s')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?$/', $started) || !preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?$/', $completed) || strtotime($completed) < strtotime($started)) jsonResponse(false, 'Перевірте час початку та завершення стерилізації.', [], 422);
    $pdo->beginTransaction();
    try {
        $set = $pdo->prepare('SELECT id, name FROM dental_erp_instrument_sets WHERE id = ? AND owner_id = ? FOR UPDATE'); $set->execute([$setId, $ownerId]); $setRow = $set->fetch();
        if (!$setRow) throw new DomainException('Набір інструментів не знайдено.');
        $cycleId = $id();
        $pdo->prepare('INSERT INTO dental_erp_sterilization_cycles (id, owner_id, instrument_set_id, cycle_number, started_at, completed_at, result, performed_by, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$cycleId, $ownerId, $setId, $cycleNumber, str_replace('T', ' ', $started), str_replace('T', ' ', $completed), $result, $userId, $text('note', 1000)]);
        $pdo->prepare('UPDATE dental_erp_instrument_sets SET status = ?, condition_note = ? WHERE id = ? AND owner_id = ?')->execute([$result === 'passed' ? 'available' : 'repair', $result === 'passed' ? null : ($text('note', 1000) ?: 'Цикл стерилізації не пройдено'), $setId, $ownerId]);
        $audit('sterilization_cycle', $cycleId, $result, ['instrument_set_id' => $setId, 'cycle_number' => $cycleNumber]);
        $pdo->commit();
    } catch (DomainException $error) { if ($pdo->inTransaction()) $pdo->rollBack(); jsonResponse(false, $error->getMessage(), [], 422); }
    catch (Throwable $error) { error_log('Dentistry ERP ' . $action . ': ' . $error->getMessage()); if ($pdo->inTransaction()) $pdo->rollBack(); jsonResponse(false, 'Не вдалося зареєструвати цикл стерилізації.', [], 500); }
    jsonResponse(true, $result === 'passed' ? 'Цикл пройдено; набір доступний.' : 'Цикл не пройдено; набір позначено для ремонту.');
}

if ($action === 'close_accounting_period') {
    $periodId = trim((string)($data['period_id'] ?? ''));
    $pdo->beginTransaction();
    try {
        $period = $pdo->prepare('SELECT id, status FROM dental_erp_accounting_periods WHERE id = ? AND owner_id = ? FOR UPDATE'); $period->execute([$periodId, $ownerId]); $row = $period->fetch();
        if (!$row) throw new DomainException('Період не знайдено.');
        if ($row['status'] === 'open') $pdo->prepare("UPDATE dental_erp_accounting_periods SET status = 'closed', closed_by = ?, closed_at = CURRENT_TIMESTAMP WHERE id = ? AND owner_id = ?")->execute([$userId, $periodId, $ownerId]);
        $audit('accounting_period', $periodId, 'closed');
        $pdo->commit();
    } catch (DomainException $error) { if ($pdo->inTransaction()) $pdo->rollBack(); jsonResponse(false, $error->getMessage(), [], 422); }
    catch (Throwable $error) { error_log('Dentistry ERP ' . $action . ': ' . $error->getMessage()); if ($pdo->inTransaction()) $pdo->rollBack(); jsonResponse(false, 'Не вдалося закрити період.', [], 500); }
    jsonResponse(true, 'Період закрито від нових проведень.');
}

if ($action === 'save_supplier') {
    $supplierId = trim((string)($data['id'] ?? ''));
    $fields = [
        $text('name', 190, true), $text('contact_person', 190), $text('phone', 80),
        $text('email', 190), $text('address', 500), $text('tax_details', 500),
        $text('payment_terms', 500), $text('notes', 5000),
    ];
    if ($fields[3] !== null && !filter_var($fields[3], FILTER_VALIDATE_EMAIL)) jsonResponse(false, 'Перевірте формат email постачальника.', ['field' => 'email'], 422);
    if ($supplierId !== '') {
        if (!preg_match('/^[a-f0-9]{32}$/i', $supplierId)) jsonResponse(false, 'Некоректний постачальник.', [], 422);
        $query = $pdo->prepare('UPDATE dental_erp_suppliers SET name = ?, contact_person = ?, phone = ?, email = ?, address = ?, tax_details = ?, payment_terms = ?, notes = ? WHERE id = ? AND owner_id = ?');
        $query->execute([...$fields, $supplierId, $ownerId]);
        if (!$query->rowCount()) {
            $exists = $pdo->prepare('SELECT id FROM dental_erp_suppliers WHERE id = ? AND owner_id = ?'); $exists->execute([$supplierId, $ownerId]);
            if (!$exists->fetchColumn()) jsonResponse(false, 'Постачальника не знайдено у цьому просторі.', [], 404);
        }
    } else {
        $query = $pdo->prepare('INSERT INTO dental_erp_suppliers (id, owner_id, name, contact_person, phone, email, address, tax_details, payment_terms, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $query->execute([$id(), $ownerId, ...$fields, $userId]);
    }
    jsonResponse(true, 'Постачальника збережено.');
}

if ($action === 'save_category') {
    $name = $text('name', 120, true);
    $categoryId = $id();
    try { $pdo->prepare('INSERT INTO dental_erp_inventory_categories (id, owner_id, name) VALUES (?, ?, ?)')->execute([$categoryId, $ownerId, $name]); }
    catch (PDOException $error) { jsonResponse(false, 'Категорія з такою назвою вже існує.', [], 409); }
    jsonResponse(true, 'Категорію додано.', ['id' => $categoryId]);
}

if ($action === 'save_unit') {
    $name = $text('name', 80, true); $symbol = $text('symbol', 24, true);
    $places = filter_var($data['decimal_places'] ?? 0, FILTER_VALIDATE_INT);
    if ($places === false || $places < 0 || $places > 4) jsonResponse(false, 'Кількість десяткових знаків має бути від 0 до 4.', [], 422);
    $unitId = $id();
    try { $pdo->prepare('INSERT INTO dental_erp_inventory_units (id, owner_id, name, symbol, decimal_places) VALUES (?, ?, ?, ?, ?)')->execute([$unitId, $ownerId, $name, $symbol, $places]); }
    catch (PDOException $error) { jsonResponse(false, 'Одиниця з таким скороченням уже існує.', [], 409); }
    jsonResponse(true, 'Одиницю виміру додано.', ['id' => $unitId]);
}

if ($action === 'save_inventory_item') {
    $itemId = trim((string)($data['id'] ?? ''));
    $name = $text('name', 190, true); $sku = $text('sku', 80);
    $categoryId = trim((string)($data['category_id'] ?? '')); $unitId = trim((string)($data['unit_id'] ?? ''));
    $supplierId = trim((string)($data['default_supplier_id'] ?? '')) ?: null;
    $types = ['consumable', 'reusable_instrument', 'equipment', 'medication'];
    $itemType = (string)($data['item_type'] ?? 'consumable');
    if (!in_array($itemType, $types, true)) jsonResponse(false, 'Оберіть коректний тип обліку.', [], 422);
    foreach ([['dental_erp_inventory_categories', $categoryId, 'Категорію'], ['dental_erp_inventory_units', $unitId, 'Одиницю виміру']] as [$table, $value, $label]) {
        $query = $pdo->prepare("SELECT id FROM {$table} WHERE id = ? AND owner_id = ? AND is_active = 1"); $query->execute([$value, $ownerId]);
        if (!$query->fetchColumn()) jsonResponse(false, "{$label} не знайдено у цьому просторі.", [], 422);
    }
    if ($supplierId !== null) {
        $query = $pdo->prepare('SELECT id FROM dental_erp_suppliers WHERE id = ? AND owner_id = ? AND is_active = 1'); $query->execute([$supplierId, $ownerId]);
        if (!$query->fetchColumn()) jsonResponse(false, 'Постачальника не знайдено у цьому просторі.', [], 422);
    }
    $cost = $decimal('purchase_unit_cost'); $minimum = $decimal('minimum_stock'); $optimal = $decimal('optimal_stock');
    if ((float)$optimal < (float)$minimum) jsonResponse(false, 'Оптимальний залишок не може бути меншим за мінімальний.', [], 422);
    $fields = [$categoryId, $unitId, $supplierId, $name, $sku, $itemType, $cost, $minimum, $optimal];
    if ($itemId !== '') {
        if (!preg_match('/^[a-f0-9]{32}$/i', $itemId)) jsonResponse(false, 'Некоректна складська позиція.', [], 422);
        $original = dentalErpRows($pdo,'SELECT item_type,unit_id FROM dental_erp_inventory_items WHERE id=? AND owner_id=?',[$itemId,$ownerId])[0]??null;
        $hasHistory = dentalErpRows($pdo,'SELECT id FROM dental_erp_inventory_batches WHERE item_id=? AND owner_id=? LIMIT 1',[$itemId,$ownerId]);
        if ($original && $hasHistory && ($original['item_type']!==$itemType || $original['unit_id']!==$unitId)) jsonResponse(false,'Позиція вже має партії. Для іншої одиниці чи типу обліку створіть нову позицію.',[],409);
        $query = $pdo->prepare('UPDATE dental_erp_inventory_items SET category_id = ?, unit_id = ?, default_supplier_id = ?, name = ?, sku = ?, item_type = ?, purchase_unit_cost = ?, minimum_stock = ?, optimal_stock = ? WHERE id = ? AND owner_id = ?');
        $query->execute([...$fields, $itemId, $ownerId]);
        if (!$query->rowCount()) { $exists = $pdo->prepare('SELECT id FROM dental_erp_inventory_items WHERE id = ? AND owner_id = ?'); $exists->execute([$itemId, $ownerId]); if (!$exists->fetchColumn()) jsonResponse(false, 'Складську позицію не знайдено.', [], 404); }
    } else {
        $query = $pdo->prepare('INSERT INTO dental_erp_inventory_items (id, owner_id, category_id, unit_id, default_supplier_id, name, sku, item_type, purchase_unit_cost, minimum_stock, optimal_stock, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $query->execute([$id(), $ownerId, ...$fields, $userId]);
    }
    jsonResponse(true, 'Складську позицію збережено.');
}

if ($action === 'create_purchase_order') {
    $supplierId = trim((string)($data['supplier_id'] ?? ''));
    $supplier = $pdo->prepare('SELECT id FROM dental_erp_suppliers WHERE id = ? AND owner_id = ? AND is_active = 1'); $supplier->execute([$supplierId, $ownerId]);
    if (!$supplier->fetchColumn()) jsonResponse(false, 'Оберіть активного постачальника цього простору.', [], 422);
    $orderItemsData = $data['items'] ?? [];
    if (!is_array($orderItemsData) || !$orderItemsData) jsonResponse(false, 'Додайте хоча б одну позицію до замовлення.', [], 422);
    $prepared = []; $totalCents = 0; $seenItems = [];
    $itemLookup = $pdo->prepare('SELECT id FROM dental_erp_inventory_items WHERE id = ? AND owner_id = ? AND is_active = 1');
    foreach ($orderItemsData as $line) {
        if (!is_array($line)) jsonResponse(false, 'Некоректний рядок замовлення.', [], 422);
        $itemId = trim((string)($line['inventory_item_id'] ?? ''));
        if (isset($seenItems[$itemId])) jsonResponse(false, 'Не додавайте одну складську позицію двічі.', [], 422);
        $seenItems[$itemId] = true;
        $itemLookup->execute([$itemId, $ownerId]);
        if (!$itemLookup->fetchColumn()) jsonResponse(false, 'Складську позицію не знайдено або вона неактивна.', [], 422);
        $qty = (string)($line['quantity'] ?? ''); $price = (string)($line['unit_price'] ?? '');
        if (!is_numeric($qty) || (float)$qty <= 0 || (float)$qty > 9999999999.9999 || !is_numeric($price) || (float)$price < 0 || (float)$price > 9999999999.9999) jsonResponse(false, 'Перевірте кількість та ціну у замовленні.', [], 422);
        $qty = number_format((float)$qty, 4, '.', ''); $price = number_format((float)$price, 4, '.', '');
        $lineTotal = (int)round((float)$qty * (float)$price * 100); $totalCents += $lineTotal;
        $prepared[] = ['item_id' => $itemId, 'quantity' => $qty, 'unit_price' => $price, 'total_price' => number_format($lineTotal / 100, 2, '.', '')];
    }
    $expected = trim((string)($data['expected_delivery_date'] ?? ''));
    if ($expected !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expected)) jsonResponse(false, 'Некоректна очікувана дата поставки.', [], 422);
    $orderId = $id(); $orderNumber = 'DPO-' . date('Ymd') . '-' . strtoupper(substr($orderId, 0, 5));
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO dental_erp_purchase_orders (id, owner_id, supplier_id, order_number, order_date, expected_delivery_date, status, total_amount, notes, created_by) VALUES (?, ?, ?, ?, CURRENT_DATE, ?, 'pending_approval', ?, ?, ?)")
            ->execute([$orderId, $ownerId, $supplierId, $orderNumber, $expected ?: null, number_format($totalCents / 100, 2, '.', ''), $text('notes', 5000), $userId]);
        $insertLine = $pdo->prepare('INSERT INTO dental_erp_purchase_order_items (id, owner_id, purchase_order_id, inventory_item_id, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($prepared as $line) $insertLine->execute([$id(), $ownerId, $orderId, $line['item_id'], $line['quantity'], $line['unit_price'], $line['total_price']]);
        $pdo->commit();
    } catch (Throwable $error) { error_log('Dentistry ERP ' . $action . ': ' . $error->getMessage()); if ($pdo->inTransaction()) $pdo->rollBack(); jsonResponse(false, 'Не вдалося створити заявку на закупівлю.', [], 500); }
    jsonResponse(true, 'Заявку на закупівлю створено для погодження.', ['id' => $orderId, 'order_number' => $orderNumber]);
}

if ($action === 'approve_purchase_order') {
    $orderId = trim((string)($data['purchase_order_id'] ?? ''));
    $order = $pdo->prepare("UPDATE dental_erp_purchase_orders SET status = 'approved', approved_by = ?, approved_at = CURRENT_TIMESTAMP WHERE id = ? AND owner_id = ? AND status = 'pending_approval'");
    $order->execute([$userId, $orderId, $ownerId]);
    if (!$order->rowCount()) jsonResponse(false, 'Заявку не знайдено або її статус не дозволяє погодження.', [], 409);
    jsonResponse(true, 'Закупівлю погоджено. Після замовлення у постачальника позначте її як оформлену.');
}

if ($action === 'mark_purchase_ordered') {
    $orderId = trim((string)($data['purchase_order_id'] ?? ''));
    $order = $pdo->prepare("UPDATE dental_erp_purchase_orders SET status = 'ordered' WHERE id = ? AND owner_id = ? AND status = 'approved'");
    $order->execute([$orderId, $ownerId]);
    if (!$order->rowCount()) jsonResponse(false, 'Замовлення не знайдено або його ще не погоджено.', [], 409);
    jsonResponse(true, 'Замовлення постачальнику зафіксовано.');
}

if ($action === 'receive_purchase_order') {
    $orderId = trim((string)($data['purchase_order_id'] ?? '')); $receivedItems = $data['items'] ?? [];
    if (!is_array($receivedItems) || !$receivedItems) jsonResponse(false, 'Вкажіть фактично отримані позиції.', [], 422);
    $receiveKey = trim((string)($data['idempotency_key'] ?? ''));
    if (!preg_match('/^[a-f0-9]{32}$/i', $receiveKey)) jsonResponse(false, 'Некоректний ключ операції отримання товару.', [], 422);
    $locationId = trim((string)($data['location_id'] ?? md5($ownerId . '|dental-location|main')));
    $location = $pdo->prepare('SELECT id FROM dental_erp_inventory_locations WHERE id = ? AND owner_id = ? AND is_active = 1'); $location->execute([$locationId, $ownerId]);
    if (!$location->fetchColumn()) jsonResponse(false, 'Складську локацію не знайдено.', [], 422);
    $pdo->beginTransaction();
    try {
        $orderQuery = $pdo->prepare("SELECT id, status FROM dental_erp_purchase_orders WHERE id = ? AND owner_id = ? FOR UPDATE");
        $orderQuery->execute([$orderId, $ownerId]); $order = $orderQuery->fetch();
        if (!$order) throw new DomainException('Замовлення постачальнику не знайдено.');
        $previousReceipt = $pdo->prepare('SELECT id, receipt_number, purchase_order_id FROM dental_erp_goods_receipts WHERE owner_id = ? AND idempotency_key = ?'); $previousReceipt->execute([$ownerId, $receiveKey]);
        if ($previous = $previousReceipt->fetch()) {
            if ((string)$previous['purchase_order_id'] !== $orderId) throw new DomainException('Ключ операції вже використано для іншого замовлення.');
            $pdo->commit(); jsonResponse(true, 'Цю поставку вже було оприбутковано.', ['receipt_id' => $previous['id'], 'receipt_number' => $previous['receipt_number'], 'idempotent_replay' => true]);
        }
        if (!in_array((string)$order['status'], ['approved', 'ordered', 'partially_received'], true)) throw new DomainException('Отримувати можна лише погоджене замовлення постачальнику.');
        $inserted = 0; $lineQuery = $pdo->prepare('SELECT l.*, i.purchase_unit_cost FROM dental_erp_purchase_order_items l JOIN dental_erp_inventory_items i ON i.owner_id = l.owner_id AND i.id = l.inventory_item_id WHERE l.id = ? AND l.owner_id = ? AND l.purchase_order_id = ? FOR UPDATE');
        $newReceiptId = $id(); $receiptNumber = 'DR-' . date('Ymd') . '-' . strtoupper(substr($newReceiptId, 0, 5));
        $pdo->prepare('INSERT INTO dental_erp_goods_receipts (id, owner_id, purchase_order_id, receipt_number, idempotency_key, received_by, note) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$newReceiptId, $ownerId, $orderId, $receiptNumber, $receiveKey, $userId, $text('note', 1000)]);
        $batchInsert = $pdo->prepare('INSERT INTO dental_erp_inventory_batches (id, owner_id, item_id, location_id, batch_number, expiration_date, received_quantity, available_quantity, purchase_unit_cost, is_blocked) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $receiptInsert = $pdo->prepare('INSERT INTO dental_erp_goods_receipt_items (id, owner_id, receipt_id, purchase_order_item_id, batch_id, quantity) VALUES (?, ?, ?, ?, ?, ?)');
        $movementInsert = $pdo->prepare("INSERT INTO dental_erp_stock_movements (id, owner_id, item_id, batch_id, location_id, movement_type, quantity, unit_cost, source_type, source_id, idempotency_key, note, actor_id) VALUES (?, ?, ?, ?, ?, 'receipt', ?, ?, 'goods_receipt', ?, ?, ?, ?)");
        $lineUpdate = $pdo->prepare('UPDATE dental_erp_purchase_order_items SET received_quantity = received_quantity + ? WHERE id = ? AND owner_id = ?');
        $receiptTotalCents = 0;
        foreach ($receivedItems as $received) {
            if (!is_array($received)) throw new DomainException('Некоректні дані отримання товару.');
            $lineId = trim((string)($received['purchase_order_item_id'] ?? '')); $qty = (string)($received['quantity'] ?? '');
            if (!is_numeric($qty) || (float)$qty <= 0 || (float)$qty > 9999999999.9999) throw new DomainException('Вкажіть додатну кількість для отримання.');
            $lineQuery->execute([$lineId, $ownerId, $orderId]); $line = $lineQuery->fetch();
            if (!$line || (float)$line['quantity'] - (float)$line['received_quantity'] + 0.0000001 < (float)$qty) throw new DomainException('Кількість отримання перевищує невідвантажений залишок замовлення.');
            $qty = number_format((float)$qty, 4, '.', ''); $batchId = $id();
            $expiry = trim((string)($received['expiration_date'] ?? ''));
            if ($expiry !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) throw new DomainException('Некоректна дата завершення терміну придатності.');
            $blocked = $expiry !== '' && $expiry < date('Y-m-d') ? 1 : 0;
            $cost = number_format((float)$line['unit_price'], 4, '.', '');
            $receiptTotalCents += (int)round((float)$qty * (float)$cost * 100);
            $batchNumber = trim((string)($received['batch_number'] ?? ''));
            $batchInsert->execute([$batchId, $ownerId, $line['inventory_item_id'], $locationId, $batchNumber !== '' ? mb_substr($batchNumber, 0, 100) : null, $expiry ?: null, $qty, $qty, $cost, $blocked]);
            $receiptInsert->execute([$id(), $ownerId, $newReceiptId, $lineId, $batchId, $qty]);
            $movementInsert->execute([$id(), $ownerId, $line['inventory_item_id'], $batchId, $locationId, $qty, $cost, $newReceiptId, 'receipt:' . $newReceiptId . ':' . $lineId . ':' . $inserted, $blocked ? 'Отримано прострочену партію; заблокована для використання.' : null, $userId]);
            $lineUpdate->execute([$qty, $lineId, $ownerId]); $inserted++;
        }
        if (!$inserted) throw new DomainException('Вкажіть хоча б одну отриману позицію.');
        $totals = $pdo->prepare('SELECT COUNT(*) AS line_count, SUM(CASE WHEN received_quantity >= quantity THEN 1 ELSE 0 END) AS complete FROM dental_erp_purchase_order_items WHERE owner_id = ? AND purchase_order_id = ?');
        $totals->execute([$ownerId, $orderId]); $state = $totals->fetch();
        $nextStatus = (int)$state['complete'] === (int)$state['line_count'] ? 'received' : 'partially_received';
        $pdo->prepare('UPDATE dental_erp_purchase_orders SET status = ? WHERE id = ? AND owner_id = ?')->execute([$nextStatus, $orderId, $ownerId]);
        if ($receiptTotalCents > 0) dentalErpPostJournal($pdo, $ownerId, $userId, 'goods_receipt', $newReceiptId, date('Y-m-d'), 'Оприбуткування поставки ' . $receiptNumber, [
            ['account' => 'inventory', 'debit_cents' => $receiptTotalCents, 'credit_cents' => 0],
            ['account' => 'receipt_accrual', 'debit_cents' => 0, 'credit_cents' => $receiptTotalCents],
        ]);
        $audit('purchase_order', $orderId, 'goods_received', ['receipt_id' => $newReceiptId, 'amount_cents' => $receiptTotalCents, 'status' => $nextStatus]);
        $pdo->commit();
    } catch (DomainException $error) { if ($pdo->inTransaction()) $pdo->rollBack(); jsonResponse(false, $error->getMessage(), [], 422); }
    catch (Throwable $error) { error_log('Dentistry ERP ' . $action . ': ' . $error->getMessage()); if ($pdo->inTransaction()) $pdo->rollBack(); jsonResponse(false, 'Не вдалося оприбуткувати поставку.', [], 500); }
    jsonResponse(true, 'Отримання оприбутковано у партії складу. Рахунок постачальника та оплату потрібно внести окремо.', ['receipt_id' => $newReceiptId, 'receipt_number' => $receiptNumber]);
}

if ($action === 'create_supplier_invoice') {
    $supplierId = trim((string)($data['supplier_id'] ?? '')); $purchaseOrderId = trim((string)($data['purchase_order_id'] ?? '')) ?: null;
    $supplier = $pdo->prepare('SELECT id FROM dental_erp_suppliers WHERE id = ? AND owner_id = ?'); $supplier->execute([$supplierId, $ownerId]);
    if (!$supplier->fetchColumn()) jsonResponse(false, 'Постачальника не знайдено.', [], 422);
    if ($purchaseOrderId !== null) { $order = $pdo->prepare('SELECT id FROM dental_erp_purchase_orders WHERE id = ? AND owner_id = ? AND supplier_id = ?'); $order->execute([$purchaseOrderId, $ownerId, $supplierId]); if (!$order->fetchColumn()) jsonResponse(false, 'Замовлення не належить обраному постачальнику.', [], 422); }
    $number = $text('invoice_number', 100, true); $amount = $decimal('amount', true);
    if ((float)$amount <= 0) jsonResponse(false, 'Сума рахунку має бути більшою за нуль.', [], 422);
    $invoiceDate = trim((string)($data['invoice_date'] ?? date('Y-m-d'))); $dueDate = trim((string)($data['due_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate) || ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate))) jsonResponse(false, 'Перевірте дати рахунку.', [], 422);
    $invoiceId = $id(); $invoiceCents = (int)round((float)$amount * 100);
    $pdo->beginTransaction();
    try {
        $invoiceLines = $data['items'] ?? [];
        if (!is_array($invoiceLines)) throw new DomainException('Некоректні позиції рахунку.');
        $preparedLines = []; $lineTotalCents = 0;
        foreach ($invoiceLines as $line) {
            if (!is_array($line)) throw new DomainException('Некоректна позиція рахунку.');
            $itemId = (string)($line['inventory_item_id'] ?? '');
            $item = dentalErpRows($pdo, 'SELECT id FROM dental_erp_inventory_items WHERE id=? AND owner_id=?', [$itemId,$ownerId]);
            if (!$item) throw new DomainException('Матеріал не належить цьому простору.');
            $qty = dentalErpNumber($line['quantity'] ?? '',true); $price = dentalErpNumber($line['unit_price'] ?? '');
            $cents = (int)round((float)$qty*(float)$price*100); $lineTotalCents += $cents;
            $preparedLines[] = [$id(),$ownerId,$invoiceId,$itemId,$qty,$price,number_format($cents/100,2,'.','')];
        }
        if ($preparedLines && $lineTotalCents !== $invoiceCents) throw new DomainException('Сума позицій не збігається із сумою рахунку.');
        $pdo->prepare("INSERT INTO dental_erp_supplier_invoices (id, owner_id, supplier_id, purchase_order_id, invoice_number, invoice_date, due_date, amount, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([$invoiceId, $ownerId, $supplierId, $purchaseOrderId, $number, $invoiceDate, $dueDate ?: null, $amount, $text('note', 1000), $userId]);
        $lineInsert = $pdo->prepare('INSERT INTO dental_erp_supplier_invoice_items(id,owner_id,invoice_id,inventory_item_id,quantity,unit_price,total_price) VALUES(?,?,?,?,?,?,?)');
        foreach ($preparedLines as $line) $lineInsert->execute($line);
        dentalErpPostJournal($pdo, $ownerId, $userId, 'supplier_invoice', $invoiceId, $invoiceDate, 'Рахунок постачальника ' . $number, [
            ['account' => $purchaseOrderId !== null ? 'receipt_accrual' : 'goods_in_transit', 'debit_cents' => $invoiceCents, 'credit_cents' => 0],
            ['account' => 'payable', 'debit_cents' => 0, 'credit_cents' => $invoiceCents],
        ]);
        $audit('supplier_invoice', $invoiceId, 'registered', ['amount' => $amount, 'supplier_id' => $supplierId, 'purchase_order_id' => $purchaseOrderId]);
        $pdo->commit();
    } catch (DomainException $error) { if ($pdo->inTransaction()) $pdo->rollBack(); jsonResponse(false, $error->getMessage(), [], 422); }
    catch (PDOException $error) { if ($pdo->inTransaction()) $pdo->rollBack(); jsonResponse(false, 'Такий номер рахунку цього постачальника вже зареєстрований.', [], 409); }
    catch (Throwable $error) { error_log('Dentistry ERP ' . $action . ': ' . $error->getMessage()); if ($pdo->inTransaction()) $pdo->rollBack(); jsonResponse(false, 'Не вдалося зареєструвати рахунок постачальника.', [], 500); }
    jsonResponse(true, 'Рахунок постачальника зареєстровано як заборгованість.');
}

if ($action === 'save_expense') {
    $categoryId = trim((string)($data['category_id'] ?? '')); $supplierId = trim((string)($data['supplier_id'] ?? '')) ?: null;
    $category = $pdo->prepare('SELECT id FROM dental_erp_expense_categories WHERE id = ? AND owner_id = ? AND is_active = 1'); $category->execute([$categoryId, $ownerId]);
    if (!$category->fetchColumn()) jsonResponse(false, 'Оберіть категорію витрати.', [], 422);
    if ($supplierId !== null) { $supplier = $pdo->prepare('SELECT id FROM dental_erp_suppliers WHERE id = ? AND owner_id = ?'); $supplier->execute([$supplierId, $ownerId]); if (!$supplier->fetchColumn()) jsonResponse(false, 'Постачальника не знайдено.', [], 422); }
    $amount = $decimal('amount', true); if ((float)$amount <= 0) jsonResponse(false, 'Сума витрати має бути більшою за нуль.', [], 422);
    $description = $text('description', 1000, true); $expenseDate = trim((string)($data['expense_date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) jsonResponse(false, 'Некоректна дата витрати.', [], 422);
    $status = (string)($data['payment_status'] ?? 'paid');
    if (!in_array($status, ['paid', 'unpaid'], true)) jsonResponse(false, 'Некоректний статус витрати.', [], 422);
    $expenseId = $id();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO dental_erp_expenses (id, owner_id, category_id, supplier_id, amount, description, expense_date, payment_status, document_reference, paid_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$expenseId, $ownerId, $categoryId, $supplierId, number_format((float)$amount, 2, '.', ''), $description, $expenseDate, $status, $text('document_reference', 255), $status === 'paid' ? date('Y-m-d H:i:s') : null, $userId]);
        $expenseCents = (int)round((float)$amount * 100);
        dentalErpPostJournal($pdo, $ownerId, $userId, 'expense', $expenseId, $expenseDate, $description, [
            ['account' => 'operating_expense', 'debit_cents' => $expenseCents, 'credit_cents' => 0],
            ['account' => $status === 'paid' ? 'cash' : 'payable', 'debit_cents' => 0, 'credit_cents' => $expenseCents],
        ]);
        $audit('expense', $expenseId, 'created', ['amount' => $amount, 'payment_status' => $status, 'category_id' => $categoryId]);
        $pdo->commit();
    } catch (Throwable $error) { error_log('Dentistry ERP ' . $action . ': ' . $error->getMessage()); if ($pdo->inTransaction()) $pdo->rollBack(); jsonResponse(false, 'Не вдалося зберегти витрату.', [], 500); }
    jsonResponse(true, 'Витрату збережено.');
}

if ($action === 'mark_expense_paid') {
    $expenseId = trim((string)($data['expense_id'] ?? ''));
    $pdo->beginTransaction();
    try {
        $expense = $pdo->prepare('SELECT id, payment_status, amount FROM dental_erp_expenses WHERE id = ? AND owner_id = ? FOR UPDATE'); $expense->execute([$expenseId, $ownerId]); $row = $expense->fetch();
        if (!$row || $row['payment_status'] === 'cancelled') throw new DomainException('Витрату не знайдено або її скасовано.');
        if ($row['payment_status'] === 'unpaid') {
            $pdo->prepare("UPDATE dental_erp_expenses SET payment_status = 'paid', paid_at = CURRENT_TIMESTAMP WHERE id = ? AND owner_id = ?")->execute([$expenseId, $ownerId]);
            $expenseCents = (int)round((float)$row['amount'] * 100);
            dentalErpPostJournal($pdo, $ownerId, $userId, 'expense_payment', $expenseId, date('Y-m-d'), 'Оплата витрати', [
                ['account' => 'payable', 'debit_cents' => $expenseCents, 'credit_cents' => 0],
                ['account' => 'cash', 'debit_cents' => 0, 'credit_cents' => $expenseCents],
            ]);
            $audit('expense', $expenseId, 'paid', ['amount' => $row['amount']]);
        }
        $pdo->commit();
    } catch (DomainException $error) { if ($pdo->inTransaction()) $pdo->rollBack(); jsonResponse(false, $error->getMessage(), [], 422); }
    catch (Throwable $error) { error_log('Dentistry ERP ' . $action . ': ' . $error->getMessage()); if ($pdo->inTransaction()) $pdo->rollBack(); jsonResponse(false, 'Не вдалося позначити витрату оплаченою.', [], 500); }
    jsonResponse(true, 'Витрату позначено оплаченою.');
}

if ($action === 'cancel_expense') {
    $expenseId = trim((string)($data['expense_id'] ?? ''));
    $pdo->beginTransaction();
    try {
        $expenseQuery = $pdo->prepare('SELECT id,description,payment_status FROM dental_erp_expenses WHERE id=? AND owner_id=? FOR UPDATE');
        $expenseQuery->execute([$expenseId,$ownerId]); $expense = $expenseQuery->fetch();
        if (!$expense || $expense['payment_status'] === 'cancelled') throw new DomainException('Витрату не знайдено або її вже скасовано.');
        $journals = $pdo->prepare("SELECT id,entry_date FROM dental_erp_accounting_journals WHERE owner_id=? AND source_id=? AND source_type IN ('expense','expense_payment') AND status='posted' FOR UPDATE");
        $journals->execute([$ownerId,$expenseId]);
        foreach ($journals->fetchAll() as $journal) {
            $lines=[]; $accountLookup=array_flip(dentalErpLedgerAccounts($pdo,$ownerId));
            foreach (dentalErpRows($pdo,'SELECT account_id,debit,credit FROM dental_erp_accounting_entries WHERE owner_id=? AND journal_id=?',[$ownerId,$journal['id']]) as $line) {
                if (!isset($accountLookup[$line['account_id']])) throw new DomainException('Не вдалося сторнувати рахунок витрати. Зверніться до власника клініки.');
                $lines[]=['account'=>$accountLookup[$line['account_id']],'debit_cents'=>(int)round((float)$line['credit']*100),'credit_cents'=>(int)round((float)$line['debit']*100)];
            }
            if ($lines) dentalErpPostJournal($pdo,$ownerId,$userId,'expense_reversal',$id(),date('Y-m-d'),'Скасування витрати: '.$expense['description'],$lines);
        }
        $pdo->prepare("UPDATE dental_erp_expenses SET payment_status='cancelled' WHERE id=? AND owner_id=?")->execute([$expenseId,$ownerId]);
        $audit('expense',$expenseId,'cancelled',['reason'=>$text('reason',1000) ?? 'Скасовано менеджером']);
        $pdo->commit();
    } catch (DomainException $error) { if ($pdo->inTransaction()) $pdo->rollBack(); jsonResponse(false,$error->getMessage(),[],422); }
    catch (Throwable $error) { error_log('Dentistry ERP '.$action.': '.$error->getMessage()); if ($pdo->inTransaction()) $pdo->rollBack(); jsonResponse(false,'Не вдалося скасувати витрату. Перевірте відкритість облікового періоду.',[],500); }
    jsonResponse(true,'Витрату скасовано зі збереженням історії обліку.');
}

if ($action === 'save_service_material_template') {
    $serviceId = trim((string)($data['service_id'] ?? '')); $itemId = trim((string)($data['inventory_item_id'] ?? ''));
    $service = $pdo->prepare("SELECT id FROM service_catalog WHERE id = ? AND created_by = ? AND module = 'dentistry' AND active = 1"); $service->execute([$serviceId, $ownerId]);
    if (!$service->fetchColumn()) jsonResponse(false, 'Оберіть активну стоматологічну послугу з каталогу.', [], 422);
    $item = $pdo->prepare("SELECT id FROM dental_erp_inventory_items WHERE id = ? AND owner_id = ? AND is_active = 1 AND item_type IN ('consumable','medication')"); $item->execute([$itemId, $ownerId]);
    if (!$item->fetchColumn()) jsonResponse(false, 'Оберіть активний витратний матеріал або медикамент.', [], 422);
    $qty = $decimal('expected_quantity', true); if ((float)$qty <= 0) jsonResponse(false, 'Очікувана кількість має бути більшою за нуль.', [], 422);
    $templateId = trim((string)($data['id'] ?? ''));
    try {
        if ($templateId !== '') {
            $pdo->prepare('UPDATE dental_erp_service_material_templates SET service_id = ?, inventory_item_id = ?, expected_quantity = ?, is_active = 1 WHERE id = ? AND owner_id = ?')->execute([$serviceId, $itemId, $qty, $templateId, $ownerId]);
        } else {
            $templateId = $id();
            $pdo->prepare('INSERT INTO dental_erp_service_material_templates (id, owner_id, service_id, inventory_item_id, expected_quantity, created_by) VALUES (?, ?, ?, ?, ?, ?)')->execute([$templateId, $ownerId, $serviceId, $itemId, $qty, $userId]);
        }
        $audit('service_material_template', $templateId, 'saved', ['service_id' => $serviceId, 'inventory_item_id' => $itemId, 'expected_quantity' => $qty]);
    } catch (PDOException $error) { jsonResponse(false, 'Цей матеріал уже є у шаблоні послуги.', [], 409); }
    jsonResponse(true, 'Матеріал для послуги збережено.');
}

if ($action === 'deactivate_service_material_template') {
    $templateId = trim((string)($data['id'] ?? ''));
    $query = $pdo->prepare('UPDATE dental_erp_service_material_templates SET is_active = 0 WHERE id = ? AND owner_id = ?'); $query->execute([$templateId, $ownerId]);
    if (!$query->rowCount()) jsonResponse(false, 'Шаблон матеріалів не знайдено.', [], 404);
    $audit('service_material_template', $templateId, 'deactivated');
    jsonResponse(true, 'Матеріал прибрано із шаблону; історія використання збережена.');
}

if ($action === 'pay_supplier_invoice') {
    $invoiceId = trim((string)($data['supplier_invoice_id'] ?? '')); $amount = $decimal('amount', true);
    if ((float)$amount <= 0) jsonResponse(false, 'Сума оплати має бути більшою за нуль.', [], 422);
    $key = trim((string)($data['idempotency_key'] ?? ''));
    if (!preg_match('/^[a-f0-9]{32}$/i', $key)) jsonResponse(false, 'Некоректний ключ операції оплати.', [], 422);
    if (!in_array((string)($data['payment_method'] ?? ''), ['cash', 'card', 'bank_transfer'], true)) jsonResponse(false, 'Оберіть спосіб оплати.', [], 422);
    $pdo->beginTransaction();
    try {
        $invoice = $pdo->prepare('SELECT * FROM dental_erp_supplier_invoices WHERE id = ? AND owner_id = ? FOR UPDATE'); $invoice->execute([$invoiceId, $ownerId]); $invoiceRow = $invoice->fetch();
        if (!$invoiceRow || $invoiceRow['status'] === 'cancelled') throw new DomainException('Рахунок постачальника не знайдено або він скасований.');
        $exists = $pdo->prepare('SELECT id,supplier_invoice_id,amount FROM dental_erp_supplier_payments WHERE owner_id = ? AND idempotency_key = ?'); $exists->execute([$ownerId, $key]); $previousPayment=$exists->fetch();
        if ($previousPayment && ($previousPayment['supplier_invoice_id']!==$invoiceId || (int)round((float)$previousPayment['amount']*100)!==(int)round((float)$amount*100))) throw new DomainException('Ключ запиту вже використано для іншої оплати.');
        if (!$previousPayment) {
            $credited = dentalErpRows($pdo,'SELECT COALESCE(SUM(credit_amount),0) amount FROM dental_erp_supplier_returns WHERE owner_id=? AND supplier_invoice_id=?',[$ownerId,$invoiceId])[0]['amount'];
            if ((float)$invoiceRow['amount'] - (float)$invoiceRow['paid_amount'] - (float)$credited + 0.0000001 < (float)$amount) throw new DomainException('Оплата перевищує залишок заборгованості.');
            $pdo->prepare('INSERT INTO dental_erp_supplier_payments (id, owner_id, supplier_invoice_id, amount, payment_method, payment_date, idempotency_key, comment, created_by) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?, ?)')->execute([$id(), $ownerId, $invoiceId, $amount, $data['payment_method'], $key, $text('comment', 1000), $userId]);
            $paid = (float)$invoiceRow['paid_amount'] + (float)$amount;
            $status = $paid + (float)$credited + 0.0000001 >= (float)$invoiceRow['amount'] ? 'paid' : 'partially_paid';
            $pdo->prepare('UPDATE dental_erp_supplier_invoices SET paid_amount = ?, status = ? WHERE id = ? AND owner_id = ?')->execute([number_format($paid, 2, '.', ''), $status, $invoiceId, $ownerId]);
            $paymentLookup = $pdo->prepare('SELECT id FROM dental_erp_supplier_payments WHERE owner_id = ? AND idempotency_key = ?'); $paymentLookup->execute([$ownerId, $key]); $paymentId = (string)$paymentLookup->fetchColumn();
            $pdo->prepare('INSERT INTO dental_erp_supplier_payment_allocations(id,owner_id,payment_id,invoice_id,amount) VALUES(?,?,?,?,?)')->execute([$id(),$ownerId,$paymentId,$invoiceId,$amount]);
            $paymentCents = (int)round((float)$amount * 100);
            dentalErpPostJournal($pdo, $ownerId, $userId, 'supplier_payment', $paymentId, date('Y-m-d'), 'Оплата рахунку постачальника ' . $invoiceRow['invoice_number'], [
                ['account' => 'payable', 'debit_cents' => $paymentCents, 'credit_cents' => 0],
                ['account' => 'cash', 'debit_cents' => 0, 'credit_cents' => $paymentCents],
            ]);
            $audit('supplier_payment', $paymentId, 'paid', ['invoice_id' => $invoiceId, 'amount' => number_format($paymentCents / 100, 2, '.', '')]);
        }
        $pdo->commit();
    } catch (DomainException $error) { if ($pdo->inTransaction()) $pdo->rollBack(); jsonResponse(false, $error->getMessage(), [], 422); }
    catch (Throwable $error) { error_log('Dentistry ERP ' . $action . ': ' . $error->getMessage()); if ($pdo->inTransaction()) $pdo->rollBack(); jsonResponse(false, 'Не вдалося зафіксувати оплату постачальнику.', [], 500); }
    jsonResponse(true, 'Оплату постачальнику зареєстровано.');
}

if ($action === 'set_active') {
    $kind = (string)($data['kind'] ?? ''); $entityId = trim((string)($data['id'] ?? ''));
    $active = filter_var($data['active'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    $tables = ['supplier' => 'dental_erp_suppliers', 'item' => 'dental_erp_inventory_items', 'payroll_rule' => 'dental_erp_payroll_rules'];
    if (!isset($tables[$kind]) || !preg_match('/^[a-f0-9]{32}$/i', $entityId) || $active === null) jsonResponse(false, 'Некоректний запит.', [], 422);
    $query = $pdo->prepare("UPDATE {$tables[$kind]} SET is_active = ? WHERE id = ? AND owner_id = ?"); $query->execute([$active ? 1 : 0, $entityId, $ownerId]);
    if (!$query->rowCount()) { $exists = $pdo->prepare("SELECT id FROM {$tables[$kind]} WHERE id = ? AND owner_id = ?"); $exists->execute([$entityId, $ownerId]); if (!$exists->fetchColumn()) jsonResponse(false, 'Запис не знайдено у цьому просторі.', [], 404); }
    $audit($kind, $entityId, $active ? 'restored' : 'archived');
    jsonResponse(true, $active ? 'Запис повернуто до активного списку.' : 'Запис прибрано з активного списку; історію збережено.');
}

jsonResponse(false, 'Невідома дія.', [], 404);
