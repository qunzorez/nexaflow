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
$inventoryEnabled = !empty($context['plan']['dental_inventory']);
$canManageFinance = in_array((string)($context['workspace_role'] ?? ''), ['manager', 'admin', 'owner'], true) || in_array((string)$user['role'], ['admin', 'owner'], true);
if (($context['module'] ?? '') !== 'dentistry') jsonResponse(false, 'Дані доступні лише для стоматологічного модуля.', [], 403);
$taskColumns = array_column($pdo->query("SHOW COLUMNS FROM tasks")->fetchAll(), 'Field');
if (!in_array('is_client_appointment', $taskColumns, true)) {
    $pdo->exec("ALTER TABLE tasks ADD is_client_appointment TINYINT(1) NOT NULL DEFAULT 0");
    $pdo->exec("UPDATE tasks SET is_client_appointment = 1 WHERE title LIKE 'Запис клієнта ·%'");
}
$pdo->exec("CREATE TABLE IF NOT EXISTS task_clients (task_id CHAR(32) NOT NULL, client_id CHAR(32) NOT NULL, PRIMARY KEY (task_id, client_id), INDEX idx_task_clients_client (client_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS dental_appointment_details (appointment_id CHAR(32) PRIMARY KEY, cabinet_id VARCHAR(80) NULL, reason VARCHAR(190) NULL, comment TEXT NULL, appointment_status VARCHAR(20) NOT NULL DEFAULT 'scheduled', updated_by CHAR(32) NOT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$appointmentStatusColumn = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dental_appointment_details' AND COLUMN_NAME = 'appointment_status'")->fetchColumn();
if (!(int)$appointmentStatusColumn) $pdo->exec("ALTER TABLE dental_appointment_details ADD appointment_status VARCHAR(20) NOT NULL DEFAULT 'scheduled'");
$pdo->exec("UPDATE dental_appointment_details d JOIN tasks t ON t.id = d.appointment_id SET d.appointment_status = CASE WHEN t.status = 'cancelled' THEN 'cancelled' WHEN t.status = 'archived' THEN 'archived' ELSE 'completed' END WHERE t.is_client_appointment = 1 AND t.status IN ('done', 'archived', 'cancelled') AND d.appointment_status <> CASE WHEN t.status = 'cancelled' THEN 'cancelled' WHEN t.status = 'archived' THEN 'archived' ELSE 'completed' END");
$isMultipart = str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data');
$data = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? ($isMultipart ? $_POST : body()) : [];
$action = trim((string)($_GET['action'] ?? $data['action'] ?? 'patient'));
if (in_array($action, ['create_treatment_plan', 'update_treatment_plan', 'perform_treatment_plans'], true)) requirePlanFeature($context, 'dental_treatment_plans');
if (in_array($action, ['upload_document', 'delete_document', 'download_document', 'preview_document'], true)) requirePlanFeature($context, 'dental_documents');
if (in_array($action, ['save_medical_record', 'perform_service'], true)) {
    $toothValues = $data['tooth_numbers'] ?? $data['teeth'] ?? [];
    if (is_string($toothValues)) { $decodedTeeth = json_decode($toothValues, true); if (is_array($decodedTeeth)) $toothValues = $decodedTeeth; }
    if (!empty($toothValues)) requirePlanFeature($context, 'dental_tooth_chart');
}

function dentalId(): string { return bin2hex(random_bytes(16)); }
function dentalMoney(int $cents): string { return number_format($cents / 100, 2, '.', ''); }
function dentalCents($amount): int
{
    if (!is_numeric($amount) || (float)$amount < 0 || (float)$amount > 9999999999.99) jsonResponse(false, 'Некоректна сума.', [], 422);
    return (int)round((float)$amount * 100);
}
function dentalJson($value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
function dentalTeeth($values): array
{
    $allowed = [11,12,13,14,15,16,17,18,21,22,23,24,25,26,27,28,31,32,33,34,35,36,37,38,41,42,43,44,45,46,47,48];
    return array_values(array_unique(array_intersect($allowed, array_map('intval', (array)$values))));
}
function dentalAudit(PDO $pdo, string $actor, string $type, string $id, string $action, array $details = []): void
{
    $pdo->prepare('INSERT INTO dental_financial_audit (id, actor_id, entity_type, entity_id, action, details) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([dentalId(), $actor, $type, $id, $action, dentalJson($details)]);
}
function dentalConsumeServiceMaterials(PDO $pdo, string $ownerId, string $userId, string $serviceId, string $planItemId, string $performedId, array $provided): float
{
    if ($serviceId === '') return 0.0;
    $lock = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE'); $lock->execute([$ownerId]);
    $appointment = $pdo->prepare('SELECT appointment_id FROM dental_performed_services WHERE id = ?'); $appointment->execute([$performedId]); $appointmentId = (string)$appointment->fetchColumn();
    $templateQuery = $pdo->prepare('SELECT t.id, t.inventory_item_id, t.expected_quantity, i.name AS item_name FROM dental_erp_service_material_templates t JOIN dental_erp_inventory_items i ON i.owner_id = t.owner_id AND i.id = t.inventory_item_id WHERE t.owner_id = ? AND t.service_id = ? AND t.is_active = 1 ORDER BY t.created_at FOR UPDATE');
    $templateQuery->execute([$ownerId, $serviceId]);
    $templates = $templateQuery->fetchAll();
    if (!$templates) return 0.0;
    $usage = [];
    foreach ($provided as $line) {
        if (!is_array($line)) throw new RuntimeException('Некоректні фактичні матеріали процедури.');
        $linePlanItemId = trim((string)($line['treatment_plan_item_id'] ?? ''));
        $lineServiceId = trim((string)($line['service_id'] ?? ''));
        $matches = $planItemId !== '' ? $linePlanItemId === $planItemId : ($linePlanItemId === '' && $lineServiceId === $serviceId);
        if (!$matches) continue;
        $itemId = trim((string)($line['inventory_item_id'] ?? ''));
        if (isset($usage[$itemId])) throw new RuntimeException('Матеріал повторюється у списку фактичного використання.');
        $quantity = (string)($line['quantity'] ?? '');
        if (!is_numeric($quantity) || (float)$quantity < 0 || (float)$quantity > 9999999999.9999) throw new RuntimeException('Вкажіть коректну фактичну кількість матеріалу.');
        $usage[$itemId] = (float)$quantity;
    }
    $requiredIds = array_fill_keys(array_map(static fn(array $row): string => (string)$row['inventory_item_id'], $templates), true);
    foreach ($usage as $itemId => $_) if (!isset($requiredIds[$itemId])) throw new RuntimeException('У фактичних матеріалах є позиція, якої немає у шаблоні процедури.');
    foreach ($templates as $template) if (!array_key_exists((string)$template['inventory_item_id'], $usage)) throw new RuntimeException('Підтвердьте фактичне використання матеріалів для кожної послуги.');

    $locationQuery = $pdo->prepare('SELECT id FROM dental_erp_inventory_locations WHERE owner_id = ? AND is_active = 1 ORDER BY (name = \'Основний склад\') DESC, created_at LIMIT 1');
    $locationQuery->execute([$ownerId]);
    $locationId = (string)($locationQuery->fetchColumn() ?: '');
    if ($locationId === '') throw new RuntimeException('Для списання матеріалів не налаштовано складське місце.');
    $batchQuery = $pdo->prepare("SELECT b.id,b.location_id,b.purchase_unit_cost,GREATEST(0,b.available_quantity-COALESCE((SELECT SUM(r.quantity) FROM dental_erp_stock_reservations r WHERE r.owner_id=b.owner_id AND r.batch_id=b.id AND r.status='active' AND r.appointment_id<>?),0)) AS available_quantity FROM dental_erp_inventory_batches b JOIN dental_erp_inventory_locations l ON l.id=b.location_id AND l.owner_id=b.owner_id WHERE b.owner_id=? AND b.item_id=? AND l.is_active=1 AND b.is_blocked=0 AND b.available_quantity>0 AND (b.expiration_date IS NULL OR b.expiration_date>=CURRENT_DATE) ORDER BY (b.expiration_date IS NULL),b.expiration_date,b.received_at,b.id FOR UPDATE");
    $batchUpdate = $pdo->prepare('UPDATE dental_erp_inventory_batches SET available_quantity = available_quantity - ? WHERE id = ? AND owner_id = ? AND available_quantity >= ?');
    $movementInsert = $pdo->prepare("INSERT INTO dental_erp_stock_movements (id, owner_id, item_id, batch_id, location_id, movement_type, quantity, unit_cost, source_type, source_id, idempotency_key, note, actor_id) VALUES (?, ?, ?, ?, ?, 'consumption', ?, ?, 'performed_service', ?, ?, ?, ?)");
    $materialInsert = $pdo->prepare('INSERT INTO dental_erp_performed_service_materials (id, owner_id, performed_service_id, template_id, inventory_item_id, batch_id, stock_movement_id, quantity, unit_cost, total_cost) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $totalCost = 0.0;
    foreach ($templates as $template) {
        $itemId = (string)$template['inventory_item_id']; $remaining = (float)$usage[$itemId];
        if ($remaining <= 0) continue;
        $batchQuery->execute([$appointmentId, $ownerId, $itemId]); $batches = $batchQuery->fetchAll();
        $available = array_sum(array_map(static fn(array $batch): float => (float)$batch['available_quantity'], $batches));
        if ($available + 0.0000001 < $remaining) throw new RuntimeException('Недостатній придатний залишок матеріалу «' . $template['item_name'] . '». Перевірте партії або отримайте товар.');
        foreach ($batches as $batch) {
            if ($remaining <= 0.0000001) break;
            $used = min($remaining, (float)$batch['available_quantity']);
            if ($used <= 0) continue;
            $usedText = number_format($used, 4, '.', ''); $unitCost = number_format((float)$batch['purchase_unit_cost'], 4, '.', '');
            $lineCost = round($used * (float)$unitCost, 4); $movementId = dentalId();
            $batchUpdate->execute([$usedText, $batch['id'], $ownerId, $usedText]);
            if ($batchUpdate->rowCount() !== 1) throw new RuntimeException('Залишок партії змінився. Оновіть картку та повторіть підтвердження.');
            $movementKey = 'service:' . $performedId . ':' . $batch['id'];
            $movementInsert->execute([$movementId, $ownerId, $itemId, $batch['id'], $batch['location_id'], $usedText, $unitCost, $performedId, $movementKey, 'Матеріал списано під час підтвердження послуги.', $userId]);
            $reservations = $pdo->prepare("SELECT id,quantity FROM dental_erp_stock_reservations WHERE owner_id=? AND batch_id=? AND appointment_id=? AND status='active' ORDER BY id FOR UPDATE"); $reservations->execute([$ownerId,$batch['id'],$appointmentId]);
            $toConsume = $used;
            foreach ($reservations->fetchAll() as $reservation) {
                if ($toConsume <= 0) break;
                $take = min($toConsume,(float)$reservation['quantity']);
                if ($take >= (float)$reservation['quantity']) $pdo->prepare("UPDATE dental_erp_stock_reservations SET status='consumed' WHERE id=? AND owner_id=?")->execute([$reservation['id'],$ownerId]);
                else $pdo->prepare('UPDATE dental_erp_stock_reservations SET quantity=quantity-? WHERE id=? AND owner_id=?')->execute([number_format($take,4,'.',''),$reservation['id'],$ownerId]);
                $toConsume -= $take;
            }
            $materialInsert->execute([dentalId(), $ownerId, $performedId, $template['id'], $itemId, $batch['id'], $movementId, $usedText, $unitCost, number_format($lineCost, 4, '.', '')]);
            $totalCost += $lineCost; $remaining -= $used;
        }
    }
    $pdo->prepare('INSERT INTO dental_erp_audit (id, owner_id, actor_id, entity_type, entity_id, action, details) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([dentalId(), $ownerId, $userId, 'performed_service', $performedId, 'materials_consumed', dentalJson(['cost' => number_format($totalCost, 4, '.', '')])]);
    return round($totalCost, 4);
}
function dentalPatient(PDO $pdo, array $context, array $user, string $patientId, string $claim = 'read'): array
{
    if (!preg_match('/^[a-f0-9]{32}$/i', $patientId)) jsonResponse(false, 'Некоректний ID пацієнта.', [], 422);
    $query = $pdo->prepare('SELECT id, name, created_by, module, module_data FROM clients WHERE id = ? LIMIT 1');
    $query->execute([$patientId]);
    $patient = $query->fetch();
    if (!$patient || $patient['module'] !== 'dentistry') jsonResponse(false, 'Стоматологічного пацієнта не знайдено.', [], 404);
    $isPlatformAdmin = in_array((string)$user['role'], ['admin', 'owner'], true);
    if (!$isPlatformAdmin && !in_array((string)$patient['created_by'], array_map('strval', $context['member_ids'] ?? []), true)) jsonResponse(false, 'Пацієнта не знайдено в активному просторі.', [], 404);
    $claims = resourceClaims($pdo, (string)$user['id'], 'client', $patientId);
    requireClaim($claims, $claim);
    return $patient;
}
function dentalDoctor(PDO $pdo, array $context, array $user, string $doctorId): string
{
    if (!preg_match('/^[a-f0-9]{32}$/i', $doctorId)) jsonResponse(false, 'Оберіть лікаря з команди.', [], 422);
    $query = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
    $query->execute([$doctorId]);
    if (!$query->fetchColumn()) jsonResponse(false, 'Лікаря не знайдено.', [], 422);
    if (!in_array((string)$user['role'], ['admin', 'owner'], true) && !in_array($doctorId, array_map('strval', $context['member_ids'] ?? []), true)) jsonResponse(false, 'Лікар має належати до команди цього простору.', [], 422);
    return $doctorId;
}
function dentalAppointment(PDO $pdo, string $appointmentId, string $patientId, bool $allowCompleted = false): array
{
    if (!preg_match('/^[a-f0-9]{32}$/i', $appointmentId)) jsonResponse(false, 'Оберіть прийом пацієнта.', [], 422);
    $query = $pdo->prepare('SELECT t.id, t.status, t.due_date, t.assignee_id, t.title FROM tasks t JOIN task_clients tc ON tc.task_id = t.id WHERE t.id = ? AND tc.client_id = ? AND t.is_client_appointment = 1 LIMIT 1');
    $query->execute([$appointmentId, $patientId]);
    $appointment = $query->fetch();
    if (!$appointment) jsonResponse(false, 'Прийом не належить цьому пацієнту.', [], 422);
    if (in_array((string)$appointment['status'], ['cancelled'], true) || (!$allowCompleted && in_array((string)$appointment['status'], ['done', 'archived'], true))) jsonResponse(false, 'Не можна змінювати цей прийом у його поточному статусі.', [], 422);
    return $appointment;
}
function dentalCatalogService(PDO $pdo, string $ownerId, string $serviceId): array
{
    if (!preg_match('/^[a-f0-9]{32}$/i', $serviceId)) jsonResponse(false, 'Оберіть послугу з каталогу.', [], 422);
    $query = $pdo->prepare("SELECT id, name, client_price FROM service_catalog WHERE id = ? AND created_by = ? AND module = 'dentistry' AND active = 1 LIMIT 1");
    $query->execute([$serviceId, $ownerId]);
    $service = $query->fetch();
    if (!$service) jsonResponse(false, 'Послугу не знайдено в активному стоматологічному каталозі.', [], 404);
    return $service;
}
function dentalRecalculateInvoice(PDO $pdo, string $invoiceId): void
{
    $query = $pdo->prepare('SELECT total_amount, COALESCE((SELECT SUM(CASE WHEN p.payment_type = \'refund\' THEN -a.amount ELSE a.amount END) FROM dental_payment_allocations a JOIN dental_payments p ON p.id = a.payment_id WHERE a.invoice_id = i.id), 0) AS paid FROM dental_invoices i WHERE i.id = ? FOR UPDATE');
    $query->execute([$invoiceId]);
    $invoice = $query->fetch();
    if (!$invoice) return;
    $total = (int)round((float)$invoice['total_amount'] * 100);
    $paid = max(0, min($total, (int)round((float)$invoice['paid'] * 100)));
    $remaining = $total - $paid;
    $status = $paid === 0 ? 'issued' : ($remaining === 0 ? 'paid' : 'partially_paid');
    $pdo->prepare('UPDATE dental_invoices SET paid_amount = ?, remaining_amount = ?, status = ? WHERE id = ?')
        ->execute([dentalMoney($paid), dentalMoney($remaining), $status, $invoiceId]);
}

$schema = file_get_contents(__DIR__ . '/../database/dentistry_care.sql');
if ($schema === false) jsonResponse(false, 'Не вдалося завантажити схему стоматологічних даних.', [], 500);
foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $schema) ?: [])) as $statement) $pdo->exec($statement);
$patientLedgerSchema = file_get_contents(__DIR__ . '/../database/dentistry_patient_ledger.sql');
if ($patientLedgerSchema === false) jsonResponse(false, 'Не вдалося завантажити схему рахунків пацієнтів.', [], 500);
foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $patientLedgerSchema) ?: [])) as $statement) $pdo->exec($statement);
require_once __DIR__ . '/dentistry-erp-ledger.php';
$documentColumns = array_column($pdo->query('SHOW COLUMNS FROM dental_documents')->fetchAll(), 'Field');
if (!in_array('description', $documentColumns, true)) $pdo->exec('ALTER TABLE dental_documents ADD description VARCHAR(1000) NULL AFTER original_name');
$planItemColumns = array_column($pdo->query('SHOW COLUMNS FROM dental_treatment_plan_items')->fetchAll(), 'Field');
if (!in_array('completed_quantity', $planItemColumns, true)) {
    $pdo->exec('ALTER TABLE dental_treatment_plan_items ADD completed_quantity SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER quantity');
    $pdo->exec("UPDATE dental_treatment_plan_items i JOIN (SELECT treatment_plan_item_id, SUM(quantity) AS completed FROM dental_performed_services WHERE treatment_plan_item_id IS NOT NULL GROUP BY treatment_plan_item_id) s ON s.treatment_plan_item_id = i.id SET i.completed_quantity = LEAST(i.quantity, s.completed), i.status = CASE WHEN s.completed >= i.quantity THEN 'completed' ELSE 'in_progress' END");
}

if (in_array($action, ['download_document', 'preview_document'], true)) {
    $documentId = trim((string)($_GET['id'] ?? ''));
    $documentQuery = $pdo->prepare('SELECT id, patient_id, original_name, stored_name, mime_type FROM dental_documents WHERE id = ? LIMIT 1');
    $documentQuery->execute([$documentId]); $document = $documentQuery->fetch();
    if (!$document) jsonResponse(false, 'Документ не знайдено.', [], 404);
    dentalPatient($pdo, $context, $user, (string)$document['patient_id']);
    if ($action === 'preview_document' && !in_array((string)$document['mime_type'], ['image/jpeg', 'image/png', 'image/webp'], true)) jsonResponse(false, 'Попередній перегляд доступний лише для зображень.', [], 415);
    $documentPath = dirname(__DIR__, 2) . '/storage/nexaflow/dentistry/' . basename((string)$document['stored_name']);
    if (!is_file($documentPath)) jsonResponse(false, 'Файл документа відсутній у сховищі.', [], 404);
    header('Content-Type: ' . $document['mime_type']);
    header('Content-Length: ' . (string)filesize($documentPath));
    header('Content-Disposition: ' . ($action === 'preview_document' ? 'inline' : 'attachment') . '; filename*=UTF-8\'\'' . rawurlencode((string)$document['original_name']));
    header('X-Content-Type-Options: nosniff');
    readfile($documentPath); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $patientId = trim((string)($_GET['patient_id'] ?? ''));
    $patient = dentalPatient($pdo, $context, $user, $patientId);
    $records = $pdo->prepare('SELECT r.*, u.name AS doctor_name FROM dental_medical_records r LEFT JOIN users u ON u.id = r.doctor_id WHERE r.patient_id = ? ORDER BY r.created_at DESC');
    $records->execute([$patientId]);
    $plans = $pdo->prepare('SELECT p.*, u.name AS doctor_name FROM dental_treatment_plans p LEFT JOIN users u ON u.id = p.doctor_id WHERE p.patient_id = ? ORDER BY p.created_at DESC');
    $plans->execute([$patientId]);
    $planRows = $plans->fetchAll();
    $items = $pdo->prepare('SELECT i.* FROM dental_treatment_plan_items i JOIN dental_treatment_plans p ON p.id = i.plan_id WHERE p.patient_id = ? ORDER BY i.created_at');
    $items->execute([$patientId]);
    $itemsByPlan = [];
    foreach ($items->fetchAll() as $item) $itemsByPlan[(string)$item['plan_id']][] = $item;
    foreach ($planRows as &$plan) $plan['items'] = $itemsByPlan[(string)$plan['id']] ?? [];
    unset($plan);
    $performed = $pdo->prepare('SELECT s.*, u.name AS doctor_name, ii.invoice_id FROM dental_performed_services s LEFT JOIN users u ON u.id = s.doctor_id LEFT JOIN dental_invoice_items ii ON ii.performed_service_id = s.id WHERE s.patient_id = ? ORDER BY s.performed_at DESC');
    $performed->execute([$patientId]);
    $invoices = $pdo->prepare('SELECT i.* FROM dental_invoices i WHERE i.patient_id = ? ORDER BY i.created_at DESC');
    $invoices->execute([$patientId]);
    $invoiceRows = $invoices->fetchAll();
    $invoiceItems = $pdo->prepare('SELECT ii.* FROM dental_invoice_items ii JOIN dental_invoices i ON i.id = ii.invoice_id WHERE i.patient_id = ? ORDER BY ii.id');
    $invoiceItems->execute([$patientId]);
    $itemsByInvoice = [];
    foreach ($invoiceItems->fetchAll() as $item) $itemsByInvoice[(string)$item['invoice_id']][] = $item;
    foreach ($invoiceRows as &$invoice) $invoice['items'] = $itemsByInvoice[(string)$invoice['id']] ?? [];
    unset($invoice);
    $payments = $pdo->prepare('SELECT p.*, COALESCE((SELECT SUM(CASE WHEN p2.payment_type = \'refund\' THEN -a.amount ELSE a.amount END) FROM dental_payment_allocations a JOIN dental_payments p2 ON p2.id = a.payment_id WHERE a.payment_id = p.id), 0) AS allocated_amount FROM dental_payments p WHERE p.patient_id = ? ORDER BY p.payment_date DESC');
    $payments->execute([$patientId]);
    $documents = [];
    if (!empty($context['plan']['dental_documents'])) {
        $documentsQuery = $pdo->prepare('SELECT id, patient_id, record_id, original_name, description, mime_type, file_size, uploaded_by, created_at FROM dental_documents WHERE patient_id = ? ORDER BY created_at DESC');
        $documentsQuery->execute([$patientId]);
        $documents = $documentsQuery->fetchAll();
    }
    $visitQuery = $pdo->prepare('SELECT t.id, t.title, t.status, d.appointment_status, t.due_date, t.duration_minutes, d.cabinet_id, d.reason, d.comment, GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR \', \') AS doctor_name FROM tasks t JOIN task_clients tc ON tc.task_id = t.id LEFT JOIN task_assignees ta ON ta.task_id = t.id LEFT JOIN users u ON u.id = ta.user_id LEFT JOIN dental_appointment_details d ON d.appointment_id = t.id WHERE tc.client_id = ? AND t.is_client_appointment = 1 GROUP BY t.id ORDER BY t.due_date DESC');
    $visitQuery->execute([$patientId]);
    $invoiceTotal = 0; $paidTotal = 0; $debtTotal = 0;
    foreach ($invoiceRows as $invoice) { if ((string)$invoice['status'] === 'cancelled') continue; $invoiceTotal += (int)round((float)$invoice['total_amount'] * 100); $paidTotal += (int)round((float)$invoice['paid_amount'] * 100); $debtTotal += (int)round((float)$invoice['remaining_amount'] * 100); }
    $paymentTotal = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN payment_type = 'refund' THEN -amount ELSE amount END), 0) FROM dental_payments WHERE patient_id = ?");
    $paymentTotal->execute([$patientId]);
    $allocationTotal = $pdo->prepare('SELECT COALESCE(SUM(CASE WHEN p.payment_type = \'refund\' THEN -a.amount ELSE a.amount END), 0) FROM dental_payment_allocations a JOIN dental_payments p ON p.id = a.payment_id WHERE p.patient_id = ?');
    $allocationTotal->execute([$patientId]);
    $advances = (int)round((float)$paymentTotal->fetchColumn() * 100) - (int)round((float)$allocationTotal->fetchColumn() * 100);
    jsonResponse(true, 'Дані пацієнта завантажено.', [
        'patient' => $patient,
        'appointments' => $visitQuery->fetchAll(),
        'medical_records' => $records->fetchAll(),
        'treatment_plans' => $planRows,
        'performed_services' => $performed->fetchAll(),
        'invoices' => $canManageFinance ? $invoiceRows : [],
        'payments' => $canManageFinance ? $payments->fetchAll() : [],
        'documents' => $documents,
        'financial_access' => $canManageFinance,
        'summary' => $canManageFinance ? ['invoiced' => dentalMoney($invoiceTotal), 'paid' => dentalMoney($paidTotal), 'debt' => dentalMoney($debtTotal), 'advance' => dentalMoney(max(0, $advances))] : null,
    ]);
}

$patientId = trim((string)($data['patient_id'] ?? ''));
$patient = dentalPatient($pdo, $context, $user, $patientId, 'edit');
$allowedTeeth = [11,12,13,14,15,16,17,18,21,22,23,24,25,26,27,28,31,32,33,34,35,36,37,38,41,42,43,44,45,46,47,48];
$dateTime = static fn($value): string => preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', (string)$value) ? str_replace('T', ' ', (string)$value) : date('Y-m-d H:i:s');

if ($action === 'upload_document') {
    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) jsonResponse(false, 'Оберіть файл для завантаження.', [], 422);
    if ((int)$file['size'] < 1 || (int)$file['size'] > 10 * 1024 * 1024) jsonResponse(false, 'Максимальний розмір документа - 10 МБ.', [], 422);
    $finfo = new finfo(FILEINFO_MIME_TYPE); $mime = (string)$finfo->file((string)$file['tmp_name']);
    $allowedMimes = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowedMimes[$mime])) jsonResponse(false, 'Дозволені формати: PDF, JPG, PNG, WEBP.', [], 422);
    $recordId = trim((string)($data['record_id'] ?? ''));
    if ($recordId !== '') { $recordCheck = $pdo->prepare('SELECT id FROM dental_medical_records WHERE id = ? AND patient_id = ?'); $recordCheck->execute([$recordId, $patientId]); if (!$recordCheck->fetchColumn()) jsonResponse(false, 'Медичний запис не належить пацієнту.', [], 422); }
    $folder = dirname(__DIR__, 2) . '/storage/nexaflow/dentistry';
    if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) jsonResponse(false, 'Не вдалося створити сховище документів.', [], 500);
    $documentId = dentalId(); $storedName = $documentId . '.' . $allowedMimes[$mime];
    if (!move_uploaded_file((string)$file['tmp_name'], $folder . '/' . $storedName)) jsonResponse(false, 'Не вдалося зберегти документ.', [], 500);
    $originalName = mb_substr(basename((string)($file['name'] ?? 'Документ')), 0, 255);
    $description = mb_substr(trim((string)($data['description'] ?? '')), 0, 1000);
    try {
        $pdo->prepare('INSERT INTO dental_documents (id, patient_id, record_id, original_name, description, stored_name, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$documentId, $patientId, $recordId ?: null, $originalName, $description ?: null, $storedName, $mime, (int)$file['size'], $userId]);
    } catch (Throwable $error) {
        @unlink($folder . '/' . $storedName);
        jsonResponse(false, 'Не вдалося зберегти дані документа.', [], 500);
    }
    jsonResponse(true, 'Документ завантажено.', ['id' => $documentId]);
}

if ($action === 'delete_document') {
    $documentId = trim((string)($data['document_id'] ?? ''));
    $documentQuery = $pdo->prepare('SELECT id, stored_name FROM dental_documents WHERE id = ? AND patient_id = ? LIMIT 1');
    $documentQuery->execute([$documentId, $patientId]);
    $document = $documentQuery->fetch();
    if (!$document) jsonResponse(false, 'Документ не знайдено.', [], 404);
    $pdo->prepare('DELETE FROM dental_documents WHERE id = ? AND patient_id = ?')->execute([$documentId, $patientId]);
    $documentPath = dirname(__DIR__, 2) . '/storage/nexaflow/dentistry/' . basename((string)$document['stored_name']);
    if (is_file($documentPath)) @unlink($documentPath);
    jsonResponse(true, 'Документ видалено.');
}

if ($action === 'save_appointment_details') {
    $appointmentId = trim((string)($data['appointment_id'] ?? ''));
    dentalAppointment($pdo, $appointmentId, $patientId);
    $cabinet = mb_substr(trim((string)($data['cabinet_id'] ?? '')), 0, 80);
    $reason = mb_substr(trim((string)($data['reason'] ?? '')), 0, 190);
    $comment = mb_substr(trim((string)($data['comment'] ?? '')), 0, 5000);
    $pdo->prepare('INSERT INTO dental_appointment_details (appointment_id, cabinet_id, reason, comment, updated_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE cabinet_id = VALUES(cabinet_id), reason = VALUES(reason), comment = VALUES(comment), updated_by = VALUES(updated_by)')
        ->execute([$appointmentId, $cabinet ?: null, $reason ?: null, $comment ?: null, $userId]);
    jsonResponse(true, 'Деталі прийому збережено.');
}

if ($action === 'delete_appointment_details') {
    $appointmentId = trim((string)($data['appointment_id'] ?? ''));
    dentalAppointment($pdo, $appointmentId, $patientId);
    $pdo->prepare('DELETE FROM dental_appointment_details WHERE appointment_id = ?')->execute([$appointmentId]);
    jsonResponse(true, 'Деталі прийому очищено.');
}

if ($action === 'save_medical_record') {
    $recordId = trim((string)($data['id'] ?? ''));
    $appointmentId = trim((string)($data['appointment_id'] ?? ''));
    if ($appointmentId !== '') dentalAppointment($pdo, $appointmentId, $patientId, true);
    $doctorId = dentalDoctor($pdo, $context, $user, trim((string)($data['doctor_id'] ?? '')) ?: $userId);
    $record = [
        'appointment_id' => $appointmentId ?: null, 'doctor_id' => $doctorId,
        'complaints' => trim((string)($data['complaints'] ?? '')), 'diagnosis' => trim((string)($data['diagnosis'] ?? '')),
        'examination' => trim((string)($data['examination'] ?? '')), 'recommendations' => trim((string)($data['recommendations'] ?? '')),
        'tooth_numbers' => dentalTeeth($data['tooth_numbers'] ?? []),
    ];
    $pdo->beginTransaction();
    try {
        $old = null;
        if ($recordId !== '') {
            $query = $pdo->prepare('SELECT * FROM dental_medical_records WHERE id = ? AND patient_id = ? FOR UPDATE');
            $query->execute([$recordId, $patientId]); $old = $query->fetch();
            if (!$old) throw new RuntimeException('Медичний запис не знайдено.');
            $pdo->prepare('UPDATE dental_medical_records SET appointment_id = ?, doctor_id = ?, complaints = ?, diagnosis = ?, examination = ?, recommendations = ?, tooth_numbers = ? WHERE id = ?')
                ->execute([$record['appointment_id'], $record['doctor_id'], $record['complaints'], $record['diagnosis'], $record['examination'], $record['recommendations'], dentalJson($record['tooth_numbers']), $recordId]);
        } else {
            $recordId = dentalId();
            $pdo->prepare('INSERT INTO dental_medical_records (id, patient_id, appointment_id, doctor_id, complaints, diagnosis, examination, recommendations, tooth_numbers, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$recordId, $patientId, $record['appointment_id'], $record['doctor_id'], $record['complaints'], $record['diagnosis'], $record['examination'], $record['recommendations'], dentalJson($record['tooth_numbers']), $userId]);
        }
        $pdo->prepare('INSERT INTO dental_medical_record_audit (id, record_id, changed_by, old_values, new_values) VALUES (?, ?, ?, ?, ?)')
            ->execute([dentalId(), $recordId, $userId, $old ? dentalJson($old) : null, dentalJson($record)]);
        $pdo->commit();
        jsonResponse(true, 'Медичний запис збережено.', ['id' => $recordId]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(false, $error->getMessage(), [], 422);
    }
}

if ($action === 'create_treatment_plan') {
    $title = trim((string)($data['title'] ?? ''));
    $items = array_values((array)($data['items'] ?? []));
    if ($title === '' || !$items) jsonResponse(false, 'Вкажіть назву плану та хоча б одну послугу.', [], 422);
    $doctorId = dentalDoctor($pdo, $context, $user, trim((string)($data['doctor_id'] ?? '')) ?: $userId);
    $planId = dentalId(); $estimatedCents = 0;
    $pdo->beginTransaction();
    try {
        $preparedItems = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $packageId = trim((string)($item['package_id'] ?? ''));
            if ($packageId !== '') {
                if (!preg_match('/^[a-f0-9]{32}$/i', $packageId)) throw new RuntimeException('Некоректний пакет послуг.');
                $packageQuery = $pdo->prepare("SELECT id, name, client_price FROM service_packages WHERE id = ? AND created_by = ? AND module = 'dentistry' AND active = 1 LIMIT 1");
                $packageQuery->execute([$packageId, $ownerId]);
                $package = $packageQuery->fetch();
                if (!$package) throw new RuntimeException('Пакет не знайдено в активному стоматологічному каталозі.');
                $catalog = ['id' => null, 'name' => (string)$package['name'], 'client_price' => $package['client_price']];
            } else {
                $catalog = dentalCatalogService($pdo, $ownerId, (string)($item['service_id'] ?? ''));
            }
            $quantity = min(100, max(1, (int)($item['quantity'] ?? 1)));
            $tooth = $packageId === '' && isset($item['tooth_number']) && (int)$item['tooth_number'] > 0 ? (int)$item['tooth_number'] : null;
            if ($tooth !== null && !in_array($tooth, $allowedTeeth, true)) throw new RuntimeException('Некоректний номер зуба.');
            $unitCents = dentalCents($catalog['client_price']);
            $lineCents = $unitCents * $quantity; $estimatedCents += $lineCents;
            $preparedItems[] = [dentalId(), $planId, $catalog['id'], $catalog['name'], $tooth, $quantity, dentalMoney($unitCents), 'planned', trim((string)($item['comment'] ?? ''))];
        }
        if (!$preparedItems) throw new RuntimeException('У плані немає коректних послуг.');
        $pdo->prepare("INSERT INTO dental_treatment_plans (id, patient_id, doctor_id, title, status, estimated_total, comment, created_by) VALUES (?, ?, ?, ?, 'active', ?, ?, ?)")
            ->execute([$planId, $patientId, $doctorId, $title, dentalMoney($estimatedCents), trim((string)($data['comment'] ?? '')), $userId]);
        $insertItem = $pdo->prepare('INSERT INTO dental_treatment_plan_items (id, plan_id, service_id, service_name, tooth_number, quantity, planned_price, status, comment) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($preparedItems as $item) $insertItem->execute($item);
        dentalAudit($pdo, $userId, 'treatment_plan', $planId, 'created', ['estimated_total' => dentalMoney($estimatedCents)]);
        $pdo->commit();
        jsonResponse(true, 'План лікування створено. Заплановані послуги не додані до боргу.', ['id' => $planId]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(false, $error->getMessage(), [], 422);
    }
}

if ($action === 'update_treatment_plan') {
    $planId = trim((string)($data['id'] ?? ''));
    $status = trim((string)($data['status'] ?? ''));
    if (!in_array($status, ['active', 'completed', 'cancelled'], true)) jsonResponse(false, 'Некоректний статус плану лікування.', [], 422);
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('UPDATE dental_treatment_plans SET status = ? WHERE id = ? AND patient_id = ?');
        $query->execute([$status, $planId, $patientId]);
        $exists = $pdo->prepare('SELECT id FROM dental_treatment_plans WHERE id = ? AND patient_id = ?');
        $exists->execute([$planId, $patientId]);
        if (!$exists->fetchColumn()) throw new RuntimeException('План лікування не знайдено.');
        dentalAudit($pdo, $userId, 'treatment_plan', $planId, 'status_updated', ['status' => $status]);
        $pdo->commit();
        jsonResponse(true, 'Статус плану оновлено.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(false, $error->getMessage(), [], 422);
    }
}

if ($action === 'perform_treatment_plans') {
    $appointmentId = trim((string)($data['appointment_id'] ?? ''));
    $planIds = array_values(array_unique(array_filter(array_map(static fn($value): string => trim((string)$value), (array)($data['treatment_plan_ids'] ?? [])))));
    $materialUsageProvided = $inventoryEnabled && array_key_exists('material_usage', $data);
    $materialUsage = $materialUsageProvided ? ($data['material_usage'] ?? []) : [];
    if ($materialUsageProvided && !is_array($materialUsage)) jsonResponse(false, 'Некоректні дані фактичного використання матеріалів.', [], 422);
    if (!$planIds) jsonResponse(false, 'Оберіть один або кілька планів лікування.', [], 422);
    foreach ($planIds as $planId) if (!preg_match('/^[a-f0-9]{32}$/i', $planId)) jsonResponse(false, 'Некоректний список планів лікування.', [], 422);
    $appointment = dentalAppointment($pdo, $appointmentId, $patientId, true);
    $pdo->beginTransaction();
    $appointmentLock = $pdo->prepare('SELECT t.id, t.status, t.assignee_id FROM tasks t JOIN task_clients tc ON tc.task_id = t.id WHERE t.id = ? AND tc.client_id = ? AND t.is_client_appointment = 1 FOR UPDATE');
    try {
        $appointmentLock->execute([$appointmentId, $patientId]);
        $lockedAppointment = $appointmentLock->fetch();
        if (!$lockedAppointment || in_array((string)$lockedAppointment['status'], ['cancelled', 'archived'], true)) throw new RuntimeException('Цей прийом заархівований або недоступний.');
        $appointment = $lockedAppointment;
        $appointmentWasCompleted = (string)$appointment['status'] === 'done';
        $marks = implode(',', array_fill(0, count($planIds), '?'));
        $selectedPlanQuery = $pdo->prepare("SELECT id FROM dental_treatment_plans WHERE patient_id = ? AND status = 'active' AND id IN ({$marks}) FOR UPDATE");
        $selectedPlanQuery->execute(array_merge([$patientId], $planIds));
        if (count($selectedPlanQuery->fetchAll()) !== count($planIds)) throw new RuntimeException('Один із вибраних планів уже закритий або недоступний.');
        $planQuery = $pdo->prepare("SELECT i.id, i.plan_id, i.service_id, i.service_name, i.tooth_number, i.quantity, i.completed_quantity, i.planned_price FROM dental_treatment_plan_items i JOIN dental_treatment_plans p ON p.id = i.plan_id WHERE p.patient_id = ? AND p.status = 'active' AND p.id IN ({$marks}) AND i.completed_quantity < i.quantity ORDER BY p.created_at, i.created_at FOR UPDATE");
        $planQuery->execute(array_merge([$patientId], $planIds));
        $items = $planQuery->fetchAll();
        if (!$items) throw new RuntimeException('У вибраних планах немає послуг, що очікують виконання.');

        $performedIds = [];
        $totalCents = 0;
        $doctorId = (string)($appointment['assignee_id'] ?: $userId);
        $performedAt = date('Y-m-d H:i:s');
        $insertPerformed = $pdo->prepare('INSERT INTO dental_performed_services (id, patient_id, appointment_id, doctor_id, service_id, service_name, treatment_plan_item_id, tooth_number, quantity, unit_price, total_price, performed_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($items as $item) {
            $quantity = max(0, (int)$item['quantity'] - (int)$item['completed_quantity']);
            if ($quantity < 1) continue;
            $unitCents = dentalCents($item['planned_price']);
            $lineCents = $unitCents * $quantity;
            $performedId = dentalId();
            $insertPerformed->execute([$performedId, $patientId, $appointmentId, $doctorId, $item['service_id'], $item['service_name'], $item['id'], $item['tooth_number'], $quantity, dentalMoney($unitCents), dentalMoney($lineCents), $performedAt, $userId]);
        $materialCost = $materialUsageProvided ? dentalConsumeServiceMaterials($pdo, $ownerId, $userId, (string)($item['service_id'] ?? ''), (string)$item['id'], $performedId, $materialUsage) : 0.0;
        if ($materialCost > 0) {
            $costCents = (int)round($materialCost * 100);
            if ($costCents > 0) dentalErpPostJournal($pdo, $ownerId, $userId, 'procedure_materials', $performedId, $performedAt, 'Списання матеріалів · ' . $item['service_name'], [
                ['account' => 'material_expense', 'debit_cents' => $costCents, 'credit_cents' => 0],
                ['account' => 'inventory', 'debit_cents' => 0, 'credit_cents' => $costCents],
            ]);
        }
            $performedIds[] = $performedId;
            $totalCents += $lineCents;
            $pdo->prepare("UPDATE dental_treatment_plan_items SET completed_quantity = quantity, status = 'completed' WHERE id = ?")->execute([$item['id']]);
        }
        if (!$performedIds || $totalCents <= 0) throw new RuntimeException('Сума вибраних послуг має бути більшою за нуль.');

        foreach ($planIds as $planId) {
            $remaining = $pdo->prepare("SELECT COUNT(*) FROM dental_treatment_plan_items WHERE plan_id = ? AND status <> 'completed'");
            $remaining->execute([$planId]);
            if ((int)$remaining->fetchColumn() === 0) $pdo->prepare("UPDATE dental_treatment_plans SET status = 'completed' WHERE id = ? AND patient_id = ?")->execute([$planId, $patientId]);
        }

        $invoiceId = dentalId();
        $invoiceNumber = 'DN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $amount = dentalMoney($totalCents);
        $pdo->prepare("INSERT INTO dental_invoices (id, invoice_number, patient_id, subtotal, total_amount, remaining_amount, status, created_by) VALUES (?, ?, ?, ?, ?, ?, 'issued', ?)")
            ->execute([$invoiceId, $invoiceNumber, $patientId, $amount, $amount, $amount, $userId]);
        dentalErpPostJournal($pdo, $ownerId, $userId, 'patient_invoice', $invoiceId, date('Y-m-d'), 'Рахунок пацієнта ' . $invoiceNumber, [
            ['account' => 'receivable', 'debit_cents' => $totalCents, 'credit_cents' => 0],
            ['account' => 'service_income', 'debit_cents' => 0, 'credit_cents' => $totalCents],
        ]);
        $insertInvoiceItem = $pdo->prepare('INSERT INTO dental_invoice_items (id, invoice_id, performed_service_id, service_name, tooth_number, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $performedQuery = $pdo->prepare('SELECT id, service_name, tooth_number, quantity, unit_price, total_price FROM dental_performed_services WHERE id = ? AND patient_id = ?');
        foreach ($performedIds as $performedId) {
            $performedQuery->execute([$performedId, $patientId]);
            $performedRow = $performedQuery->fetch();
            $insertInvoiceItem->execute([dentalId(), $invoiceId, $performedRow['id'], $performedRow['service_name'], $performedRow['tooth_number'], $performedRow['quantity'], $performedRow['unit_price'], $performedRow['total_price']]);
        }

        $pdo->prepare("UPDATE tasks SET status = 'done' WHERE id = ? AND is_client_appointment = 1 AND status NOT IN ('cancelled', 'done', 'archived')")->execute([$appointmentId]);
        $pdo->prepare("INSERT INTO dental_appointment_details (appointment_id, appointment_status, updated_by) VALUES (?, 'completed', ?) ON DUPLICATE KEY UPDATE appointment_status = 'completed', updated_by = VALUES(updated_by)")->execute([$appointmentId, $userId]);
        $taskHistoryMessage = $appointmentWasCompleted ? 'До завершеного прийому додано послуги. Створено рахунок ' . $invoiceNumber . '.' : 'Прийом завершено після підтвердження послуг. Створено рахунок ' . $invoiceNumber . '.';
        $pdo->prepare('INSERT INTO task_history (id, task_id, user_id, action, message) VALUES (?, ?, ?, ?, ?)')->execute([dentalId(), $appointmentId, $userId, 'appointment_completed_with_invoice', $taskHistoryMessage]);
        dentalAudit($pdo, $userId, 'appointment', $appointmentId, 'treatment_completed', ['plan_ids' => $planIds, 'performed_service_ids' => $performedIds, 'invoice_id' => $invoiceId, 'invoice_number' => $invoiceNumber, 'total_amount' => $amount]);
        dentalAudit($pdo, $userId, 'invoice', $invoiceId, 'issued', ['invoice_number' => $invoiceNumber, 'total_amount' => $amount, 'appointment_id' => $appointmentId]);
        $pdo->commit();
        jsonResponse(true, 'Прийом завершено. Виконані послуги та рахунок створено.', ['invoice_id' => $invoiceId, 'invoice_number' => $invoiceNumber, 'total_amount' => $amount, 'performed_count' => count($performedIds)]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(false, $error->getMessage(), [], 422);
    }
}

if ($action === 'perform_service') {
    $appointmentId = trim((string)($data['appointment_id'] ?? ''));
    $appointment = dentalAppointment($pdo, $appointmentId, $patientId, true);
    $catalog = dentalCatalogService($pdo, $ownerId, (string)($data['service_id'] ?? ''));
    $quantity = min(100, max(1, (int)($data['quantity'] ?? 1)));
    $tooth = isset($data['tooth_number']) && (int)$data['tooth_number'] > 0 ? (int)$data['tooth_number'] : null;
    if ($tooth !== null && !in_array($tooth, $allowedTeeth, true)) jsonResponse(false, 'Некоректний номер зуба.', [], 422);
    $planItemId = trim((string)($data['treatment_plan_item_id'] ?? ''));
    $materialUsageProvided = $inventoryEnabled && array_key_exists('material_usage', $data);
    $materialUsage = $materialUsageProvided ? ($data['material_usage'] ?? []) : [];
    if ($materialUsageProvided && !is_array($materialUsage)) jsonResponse(false, 'Некоректні дані фактичного використання матеріалів.', [], 422);
    $unitCents = dentalCents($catalog['client_price']); $totalCents = $unitCents * $quantity;
    $performedId = dentalId();
    $pdo->beginTransaction();
    try {
        if ($planItemId !== '') {
            $planQuery = $pdo->prepare('SELECT i.id, i.plan_id, i.quantity, i.completed_quantity FROM dental_treatment_plan_items i JOIN dental_treatment_plans p ON p.id = i.plan_id WHERE i.id = ? AND p.patient_id = ? AND p.status = \'active\' FOR UPDATE');
            $planQuery->execute([$planItemId, $patientId]); $planItem = $planQuery->fetch();
            if (!$planItem || (int)$planItem['completed_quantity'] + $quantity > (int)$planItem['quantity']) throw new RuntimeException('Кількість перевищує залишок у плані лікування.');
            $planService = $pdo->prepare('SELECT service_id, tooth_number FROM dental_treatment_plan_items WHERE id = ?');
            $planService->execute([$planItemId]); $planService = $planService->fetch();
            if ((string)($planService['service_id'] ?? '') !== (string)$catalog['id']) throw new RuntimeException('Виконана послуга не відповідає позиції плану лікування.');
            if (!empty($planService['tooth_number']) && (int)$planService['tooth_number'] !== (int)$tooth) throw new RuntimeException('Вибраний зуб не відповідає позиції плану лікування.');
            $newDone = (int)$planItem['completed_quantity'] + $quantity;
            $pdo->prepare('UPDATE dental_treatment_plan_items SET completed_quantity = ?, status = ? WHERE id = ?')->execute([$newDone, $newDone >= (int)$planItem['quantity'] ? 'completed' : 'in_progress', $planItemId]);
            $remaining = $pdo->prepare("SELECT COUNT(*) FROM dental_treatment_plan_items WHERE plan_id = ? AND status <> 'completed'");
            $remaining->execute([$planItem['plan_id']]);
            if ((int)$remaining->fetchColumn() === 0) $pdo->prepare("UPDATE dental_treatment_plans SET status = 'completed' WHERE id = ?")->execute([$planItem['plan_id']]);
        }
        $doctorId = (string)($appointment['assignee_id'] ?: $userId);
        $pdo->prepare('INSERT INTO dental_performed_services (id, patient_id, appointment_id, doctor_id, service_id, service_name, treatment_plan_item_id, tooth_number, quantity, unit_price, total_price, performed_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$performedId, $patientId, $appointmentId, $doctorId, $catalog['id'], $catalog['name'], $planItemId ?: null, $tooth, $quantity, dentalMoney($unitCents), dentalMoney($totalCents), $dateTime($data['performed_at'] ?? $appointment['due_date']), $userId]);
        $materialCost = $materialUsageProvided ? dentalConsumeServiceMaterials($pdo, $ownerId, $userId, (string)$catalog['id'], $planItemId, $performedId, $materialUsage) : 0.0;
        $costCents = (int)round($materialCost*100);
        if ($costCents > 0) dentalErpPostJournal($pdo,$ownerId,$userId,'procedure_materials',$performedId,date('Y-m-d'),'Списання матеріалів · '.$catalog['name'],[
            ['account'=>'material_expense','debit_cents'=>$costCents],['account'=>'inventory','credit_cents'=>$costCents],
        ]);
        dentalAudit($pdo, $userId, 'performed_service', $performedId, 'created', ['appointment_id' => $appointmentId, 'total_price' => dentalMoney($totalCents)]);
        $pdo->commit();
        jsonResponse(true, 'Виконану послугу збережено окремо від оплати.', ['id' => $performedId, 'unit_price' => dentalMoney($unitCents), 'total_price' => dentalMoney($totalCents)]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(false, $error->getMessage(), [], 422);
    }
}

if ($action === 'create_invoice') {
    if (!$canManageFinance) jsonResponse(false, 'Формувати рахунки може менеджер або адміністратор простору.', [], 403);
    $performedIds = array_values(array_unique(array_filter(array_map(static fn($id): string => trim((string)$id), (array)($data['performed_service_ids'] ?? [])))));
    if (!$performedIds) jsonResponse(false, 'Оберіть виконані послуги для рахунку.', [], 422);
    foreach ($performedIds as $performedId) if (!preg_match('/^[a-f0-9]{32}$/i', $performedId)) jsonResponse(false, 'Некоректний список виконаних послуг.', [], 422);
    $invoiceId = dentalId();
    $pdo->beginTransaction();
    try {
        $patientLock = $pdo->prepare('SELECT id FROM clients WHERE id = ? FOR UPDATE'); $patientLock->execute([$patientId]);
        $marks = implode(',', array_fill(0, count($performedIds), '?'));
        $query = $pdo->prepare("SELECT s.* FROM dental_performed_services s JOIN tasks t ON t.id = s.appointment_id LEFT JOIN dental_invoice_items ii ON ii.performed_service_id = s.id WHERE s.patient_id = ? AND s.id IN ({$marks}) AND t.status IN ('done','archived') AND ii.id IS NULL FOR UPDATE");
        $query->execute(array_merge([$patientId], $performedIds));
        $services = $query->fetchAll();
        if (count($services) !== count($performedIds)) throw new RuntimeException('Для рахунку доступні лише виконані послуги із завершених прийомів, які ще не виставлялися.');
        $totalCents = array_sum(array_map(static fn(array $service): int => dentalCents($service['total_price']), $services));
        if ($totalCents <= 0) throw new RuntimeException('Нульовий рахунок створити неможливо.');
        $invoiceNumber = 'DN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $pdo->prepare("INSERT INTO dental_invoices (id, invoice_number, patient_id, subtotal, total_amount, remaining_amount, status, created_by) VALUES (?, ?, ?, ?, ?, ?, 'issued', ?)")
            ->execute([$invoiceId, $invoiceNumber, $patientId, dentalMoney($totalCents), dentalMoney($totalCents), dentalMoney($totalCents), $userId]);
        $insert = $pdo->prepare('INSERT INTO dental_invoice_items (id, invoice_id, performed_service_id, service_name, tooth_number, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($services as $service) $insert->execute([dentalId(), $invoiceId, $service['id'], $service['service_name'], $service['tooth_number'], $service['quantity'], $service['unit_price'], $service['total_price']]);
        dentalErpPostJournal($pdo, $ownerId, $userId, 'patient_invoice', $invoiceId, date('Y-m-d'), 'Рахунок пацієнта ' . $invoiceNumber, [
            ['account' => 'receivable', 'debit_cents' => $totalCents, 'credit_cents' => 0],
            ['account' => 'service_income', 'debit_cents' => 0, 'credit_cents' => $totalCents],
        ]);
        dentalAudit($pdo, $userId, 'invoice', $invoiceId, 'issued', ['invoice_number' => $invoiceNumber, 'total_amount' => dentalMoney($totalCents)]);
        $pdo->commit();
        jsonResponse(true, 'Рахунок створено з виконаних послуг.', ['id' => $invoiceId, 'invoice_number' => $invoiceNumber, 'total_amount' => dentalMoney($totalCents)]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(false, $error->getMessage(), [], 422);
    }
}

if ($action === 'cancel_invoice') {
    if (!$canManageFinance) jsonResponse(false, 'Скасувати рахунок може менеджер або адміністратор простору.', [], 403);
    $invoiceId = trim((string)($data['id'] ?? ''));
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT id, total_amount, paid_amount, status FROM dental_invoices WHERE id = ? AND patient_id = ? FOR UPDATE');
        $query->execute([$invoiceId, $patientId]); $invoice = $query->fetch();
        if (!$invoice) throw new RuntimeException('Рахунок не знайдено.');
        if ((float)$invoice['paid_amount'] > 0) throw new RuntimeException('Спочатку зареєструйте повернення оплати, потім скасовуйте рахунок.');
        if ($invoice['status'] !== 'cancelled') {
            $pdo->prepare("UPDATE dental_invoices SET status = 'cancelled', remaining_amount = 0 WHERE id = ?")->execute([$invoiceId]);
            $amountCents = dentalCents($invoice['total_amount']);
            if ($amountCents > 0) dentalErpPostJournal($pdo, $ownerId, $userId, 'patient_invoice_cancel', $invoiceId, date('Y-m-d'), 'Сторно скасованого рахунку пацієнта', [
                ['account' => 'service_income', 'debit_cents' => $amountCents, 'credit_cents' => 0],
                ['account' => 'receivable', 'debit_cents' => 0, 'credit_cents' => $amountCents],
            ]);
            dentalAudit($pdo, $userId, 'invoice', $invoiceId, 'cancelled');
        }
        $pdo->commit();
        jsonResponse(true, 'Рахунок скасовано зі збереженням історії.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(false, $error->getMessage(), [], 422);
    }
}

if ($action === 'create_payment' || $action === 'refund_payment') {
    if (!$canManageFinance) jsonResponse(false, 'Реєструвати оплату чи повернення може менеджер або адміністратор простору.', [], 403);
    $paymentMethod = trim((string)($data['payment_method'] ?? 'cash'));
    if (!in_array($paymentMethod, ['cash', 'card', 'bank_transfer'], true)) jsonResponse(false, 'Оберіть коректний спосіб оплати.', [], 422);
    $amountCents = dentalCents($data['amount'] ?? null);
    if ($amountCents <= 0) jsonResponse(false, 'Сума платежу має бути більшою за нуль.', [], 422);
    $allocations = array_values((array)($data['allocations'] ?? []));
    $allocations = array_values(array_filter($allocations, static fn($row): bool => is_array($row) && !empty($row['invoice_id'])));
    $allocationCents = array_sum(array_map(static fn(array $row): int => dentalCents($row['amount'] ?? 0), $allocations));
    if ($allocationCents > $amountCents) jsonResponse(false, 'Розподілена сума не може перевищувати платіж.', [], 422);
    if ($action === 'refund_payment' && $allocations && $allocationCents !== $amountCents) jsonResponse(false, 'Повернення має бути повністю прив’язане до рахунків.', [], 422);
    $idempotencyKey = trim((string)($data['idempotency_key'] ?? ''));
    if (!preg_match('/^[a-f0-9]{32}$/i', $idempotencyKey)) jsonResponse(false, 'Оновіть форму й повторіть операцію.', [], 422);
    $existingPayment = $pdo->prepare('SELECT id, patient_id, amount, payment_type FROM dental_payments WHERE idempotency_key = ? LIMIT 1');
    $existingPayment->execute([$idempotencyKey]);
    if ($existing = $existingPayment->fetch()) {
        if ((string)$existing['patient_id'] !== $patientId || (string)$existing['payment_type'] !== ($action === 'refund_payment' ? 'refund' : 'payment') || (int)round((float)$existing['amount'] * 100) !== $amountCents) jsonResponse(false, 'Ключ ідемпотентності вже використаний для іншої операції.', [], 409);
        jsonResponse(true, 'Платіж уже зареєстрований.', ['id' => $existing['id'], 'amount' => $existing['amount'], 'duplicate' => true]);
    }
    $paymentId = dentalId(); $type = $action === 'refund_payment' ? 'refund' : 'payment';
    $pdo->beginTransaction();
    try {
        $patientLock = $pdo->prepare('SELECT id FROM clients WHERE id = ? FOR UPDATE'); $patientLock->execute([$patientId]);
        $duplicateQuery = $pdo->prepare('SELECT id, patient_id, amount, payment_type FROM dental_payments WHERE idempotency_key = ? FOR UPDATE');
        $duplicateQuery->execute([$idempotencyKey]);
        if ($duplicate = $duplicateQuery->fetch()) {
            if ((string)$duplicate['patient_id'] !== $patientId || (string)$duplicate['payment_type'] !== $type || (int)round((float)$duplicate['amount'] * 100) !== $amountCents) throw new RuntimeException('Ключ ідемпотентності вже використаний для іншої операції.');
            $pdo->commit();
            jsonResponse(true, 'Платіж уже зареєстрований.', ['id' => $duplicate['id'], 'amount' => $duplicate['amount'], 'duplicate' => true]);
        }
        $lockedInvoices = [];
        foreach ($allocations as $allocation) {
            $invoiceId = trim((string)$allocation['invoice_id']);
            if (!preg_match('/^[a-f0-9]{32}$/i', $invoiceId)) throw new RuntimeException('Некоректний рахунок у платежі.');
            $invoiceQuery = $pdo->prepare('SELECT id, total_amount, paid_amount, remaining_amount, status FROM dental_invoices WHERE id = ? AND patient_id = ? FOR UPDATE');
            $invoiceQuery->execute([$invoiceId, $patientId]); $invoice = $invoiceQuery->fetch();
            if (!$invoice || $invoice['status'] === 'cancelled') throw new RuntimeException('Рахунок не знайдено або його скасовано.');
            $cents = dentalCents($allocation['amount'] ?? 0);
            if ($cents <= 0) throw new RuntimeException('Сума розподілу має бути більшою за нуль.');
            $available = (int)round((float)($type === 'refund' ? $invoice['paid_amount'] : $invoice['remaining_amount']) * 100);
            if ($cents > $available) throw new RuntimeException($type === 'refund' ? 'Повернення перевищує оплачену суму рахунку.' : 'Платіж перевищує залишок рахунку.');
            $lockedInvoices[] = [$invoiceId, $cents];
        }
        if ($type === 'refund') {
            $paidQuery = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN payment_type = 'refund' THEN -amount ELSE amount END), 0) FROM dental_payments WHERE patient_id = ? FOR UPDATE");
            $paidQuery->execute([$patientId]);
            $availableRefund = (int)round((float)$paidQuery->fetchColumn() * 100);
            if ($amountCents > $availableRefund) throw new RuntimeException('Повернення перевищує суму фактично отриманих коштів.');
            if (!$allocations) {
                $allocQuery = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN p.payment_type = 'refund' THEN -a.amount ELSE a.amount END), 0) FROM dental_payment_allocations a JOIN dental_payments p ON p.id = a.payment_id WHERE p.patient_id = ?");
                $allocQuery->execute([$patientId]);
                $advanceCents = $availableRefund - (int)round((float)$allocQuery->fetchColumn() * 100);
                if ($amountCents > $advanceCents) throw new RuntimeException('Повернення перевищує нерозподілений аванс пацієнта.');
            }
        }
        $dateValue = trim((string)($data['payment_date'] ?? ''));
        if ($dateValue !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $dateValue)) throw new RuntimeException('Некоректна дата платежу.');
        $pdo->prepare('INSERT INTO dental_payments (id, patient_id, amount, payment_method, payment_type, payment_date, idempotency_key, created_by, comment) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$paymentId, $patientId, dentalMoney($amountCents), $paymentMethod, $type, $dateTime($dateValue ?: date('Y-m-d H:i:s')), $idempotencyKey, $userId, trim((string)($data['comment'] ?? ''))]);
        $insertAllocation = $pdo->prepare('INSERT INTO dental_payment_allocations (id, payment_id, invoice_id, amount) VALUES (?, ?, ?, ?)');
        foreach ($lockedInvoices as [$invoiceId, $cents]) $insertAllocation->execute([dentalId(), $paymentId, $invoiceId, dentalMoney($cents)]);
        foreach (array_unique(array_column($lockedInvoices, 0)) as $invoiceId) dentalRecalculateInvoice($pdo, (string)$invoiceId);
        if ($type === 'payment') {
            $unallocatedCents = $amountCents - $allocationCents;
            $lines = [['account' => 'cash', 'debit_cents' => $amountCents, 'credit_cents' => 0]];
            if ($allocationCents > 0) $lines[] = ['account' => 'receivable', 'debit_cents' => 0, 'credit_cents' => $allocationCents];
            if ($unallocatedCents > 0) $lines[] = ['account' => 'patient_advance', 'debit_cents' => 0, 'credit_cents' => $unallocatedCents];
        } else {
            $lines = [];
            if ($allocationCents > 0) $lines[] = ['account' => 'receivable', 'debit_cents' => $allocationCents, 'credit_cents' => 0];
            if ($allocationCents === 0) $lines[] = ['account' => 'patient_advance', 'debit_cents' => $amountCents, 'credit_cents' => 0];
            $lines[] = ['account' => 'cash', 'debit_cents' => 0, 'credit_cents' => $amountCents];
        }
        dentalErpPostJournal($pdo, $ownerId, $userId, $type === 'refund' ? 'patient_refund' : 'patient_payment', $paymentId, $dateTime($dateValue ?: date('Y-m-d H:i:s')), $type === 'refund' ? 'Повернення пацієнту' : 'Оплата пацієнта', $lines);
        dentalAudit($pdo, $userId, 'payment', $paymentId, $type === 'refund' ? 'refunded' : 'received', ['amount' => dentalMoney($amountCents), 'allocations' => $lockedInvoices]);
        $pdo->commit();
        jsonResponse(true, $type === 'refund' ? 'Повернення коштів зареєстровано.' : 'Оплату зареєстровано.', ['id' => $paymentId, 'amount' => dentalMoney($amountCents), 'advance' => dentalMoney($type === 'payment' ? max(0, $amountCents - $allocationCents) : 0)]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(false, $error->getMessage(), [], 422);
    }
}

if ($action === 'allocate_advance') {
    if (!$canManageFinance) jsonResponse(false, 'Розподіляти аванс може менеджер або адміністратор простору.', [], 403);
    $paymentId = trim((string)($data['payment_id'] ?? ''));
    $invoiceId = trim((string)($data['invoice_id'] ?? ''));
    $amountCents = dentalCents($data['amount'] ?? null);
    if ($amountCents <= 0) jsonResponse(false, 'Сума зарахування має бути більшою за нуль.', [], 422);
    $pdo->beginTransaction();
    try {
        $patientLock = $pdo->prepare('SELECT id FROM clients WHERE id = ? FOR UPDATE'); $patientLock->execute([$patientId]);
        $source = $pdo->prepare("SELECT p.id, p.amount, COALESCE((SELECT SUM(a.amount) FROM dental_payment_allocations a WHERE a.payment_id = p.id), 0) AS allocated FROM dental_payments p WHERE p.id = ? AND p.patient_id = ? AND p.payment_type = 'payment' FOR UPDATE");
        $source->execute([$paymentId, $patientId]); $paymentRow = $source->fetch();
        if (!$paymentRow) throw new RuntimeException('Аванс не знайдено.');
        $available = (int)round(((float)$paymentRow['amount'] - (float)$paymentRow['allocated']) * 100);
        if ($amountCents > $available) throw new RuntimeException('Сума перевищує залишок авансу.');
        $invoiceQuery = $pdo->prepare("SELECT id, remaining_amount, status FROM dental_invoices WHERE id = ? AND patient_id = ? FOR UPDATE");
        $invoiceQuery->execute([$invoiceId, $patientId]); $invoice = $invoiceQuery->fetch();
        if (!$invoice || $invoice['status'] === 'cancelled') throw new RuntimeException('Рахунок не знайдено або скасовано.');
        if ($amountCents > (int)round((float)$invoice['remaining_amount'] * 100)) throw new RuntimeException('Зарахування перевищує борг за рахунком.');
        $allocationId = dentalId();
        $pdo->prepare('INSERT INTO dental_payment_allocations (id, payment_id, invoice_id, amount) VALUES (?, ?, ?, ?)')->execute([$allocationId, $paymentId, $invoiceId, dentalMoney($amountCents)]);
        dentalRecalculateInvoice($pdo, $invoiceId);
        dentalErpPostJournal($pdo, $ownerId, $userId, 'advance_allocation', $allocationId, date('Y-m-d'), 'Зарахування авансу пацієнта', [
            ['account' => 'patient_advance', 'debit_cents' => $amountCents, 'credit_cents' => 0],
            ['account' => 'receivable', 'debit_cents' => 0, 'credit_cents' => $amountCents],
        ]);
        dentalAudit($pdo, $userId, 'payment', $paymentId, 'advance_allocated', ['invoice_id' => $invoiceId, 'amount' => dentalMoney($amountCents)]);
        $pdo->commit();
        jsonResponse(true, 'Аванс зараховано на рахунок.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(false, $error->getMessage(), [], 422);
    }
}

jsonResponse(false, 'Невідома дія стоматологічного модуля.', [], 422);
