<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);
$pdo = db();
$userId = (string)$_SESSION['user_id'];
$userQuery = $pdo->prepare('SELECT id, name, email, role, avatar_url FROM users WHERE id = ? LIMIT 1');
$userQuery->execute([$userId]);
$user = $userQuery->fetch();
if (!$user) jsonResponse(false, 'Користувача не знайдено.', [], 401);
$pdo->exec("CREATE TABLE IF NOT EXISTS clients (id CHAR(32) PRIMARY KEY, name VARCHAR(190) NOT NULL, legal_form VARCHAR(40) NOT NULL, tax_system VARCHAR(80) NOT NULL, has_vat TINYINT(1) NOT NULL DEFAULT 0, has_excise TINYINT(1) NOT NULL DEFAULT 0, has_employees TINYINT(1) NOT NULL DEFAULT 0, employee_count INT NULL, special_taxes TEXT NULL, services TEXT NULL, module VARCHAR(40) NOT NULL DEFAULT 'accounting', module_data TEXT NULL, created_by CHAR(32) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_clients_created_by (created_by)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS client_members (client_id CHAR(32) NOT NULL, user_id CHAR(32) NOT NULL, assigned_by CHAR(32) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (client_id, user_id), INDEX idx_client_members_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$columns = $pdo->query("SHOW COLUMNS FROM clients")->fetchAll(); $columnNames = array_column($columns, 'Field'); if (!in_array('module', $columnNames, true)) $pdo->exec("ALTER TABLE clients ADD module VARCHAR(40) NOT NULL DEFAULT 'accounting'"); if (!in_array('module_data', $columnNames, true)) $pdo->exec("ALTER TABLE clients ADD module_data TEXT NULL"); if (!in_array('services', $columnNames, true)) $pdo->exec("ALTER TABLE clients ADD services TEXT NULL");
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$data = $method === 'POST' ? body() : [];
$canManage = in_array($user['role'], ['admin', 'owner', 'manager'], true);
$clientAction = (string)($data['action'] ?? 'create');
if ($method === 'POST' && in_array($clientAction, ['update', 'delete'], true)) {
    if (!$canManage) jsonResponse(false, 'Звичайний користувач може лише переглядати клієнтів.', [], 403);
    $clientId = trim((string)($data['id'] ?? ''));
    $claims = resourceClaims($pdo, $userId, 'client', $clientId);
    requireClaim($claims, $clientAction === 'delete' ? 'delete' : 'edit');
}

if ($method === 'GET') {
    if (in_array($user['role'], ['admin', 'owner'], true)) {
        $clientQuery = $pdo->query('SELECT c.*, u.name AS creator_name FROM clients c JOIN users u ON u.id = c.created_by ORDER BY c.name');
    } elseif ($canManage) {
        $clientQuery = $pdo->prepare('SELECT DISTINCT c.*, u.name AS creator_name FROM clients c JOIN users u ON u.id = c.created_by LEFT JOIN client_members cm ON cm.client_id = c.id AND cm.user_id = ? WHERE c.created_by = ? OR cm.user_id IS NOT NULL ORDER BY c.name');
        $clientQuery->execute([$userId, $userId]);
    } else {
        $clientQuery = $pdo->prepare('SELECT c.*, u.name AS creator_name FROM clients c JOIN users u ON u.id = c.created_by JOIN client_members cm ON cm.client_id = c.id AND cm.user_id = ? ORDER BY c.name');
        $clientQuery->execute([$userId]);
    }
    $clients = $clientQuery->fetchAll();
    $clients = claimRows($pdo, $userId, 'client', $clients);
    $serviceUsers = [];
    foreach ($pdo->query('SELECT id, name, email FROM users')->fetchAll() as $serviceUser) $serviceUsers[(string)$serviceUser['id']] = $serviceUser;
    foreach ($clients as &$client) {
        $client['special_taxes'] = $client['special_taxes'] ? (json_decode($client['special_taxes'], true) ?: []) : [];
        $client['services'] = $client['services'] ? (json_decode($client['services'], true) ?: []) : [];
        foreach ($client['services'] as &$service) {
            $person = $serviceUsers[(string)($service['accountant_id'] ?? '')] ?? null;
            if ($person) { $service['accountant_name'] = $person['name']; $service['accountant_email'] = $person['email']; }
        }
        unset($service);
        $client['module_data'] = $client['module_data'] ? (json_decode($client['module_data'], true) ?: []) : [];
    }
    unset($client);
    // Only members of a global accounting group can be assigned to a client service.
    // Some older live records contain a double-encoded Ukrainian group name, so match
    // both the normal UTF-8 prefix and its legacy representation.
    $accountingGroupPrefix = 'c390c2b1c391c283c391c285c390c2b3c390c2b0c390c2bb';
    $availableUsers = $canManage ? $pdo->query("SELECT DISTINCT u.id, u.name, u.email, u.role FROM users u JOIN project_group_members pgm ON pgm.user_id = u.id JOIN project_groups pg ON pg.id = pgm.group_id AND pg.project_id IS NULL WHERE u.role IN ('admin', 'owner', 'manager', 'user') AND (LOWER(pg.name) LIKE '%бухгал%' OR LOWER(pg.name) LIKE '%account%' OR HEX(pg.name) LIKE '{$accountingGroupPrefix}%') ORDER BY u.name, u.email")->fetchAll() : [];
    $availableByClient = [];
    if ($canManage && $clients) {
        // A service accountant is selected from the manager's eligible accounting
        // team; the accountant does not need to be a client_member first.
        foreach ($clients as $clientRow) {
            $availableByClient[(string)$clientRow['id']] = $availableUsers;
        }
    }
    jsonResponse(true, 'Клієнтів завантажено.', ['user' => $user, 'clients' => $clients, 'available_users' => $availableUsers, 'available_users_by_client' => $availableByClient, 'permissions' => ['manage' => $canManage]]);
}
if (!$canManage) jsonResponse(false, 'Створювати клієнтів можуть лише адміністратор або менеджер.', [], 403);
$action = (string)($data['action'] ?? 'create');
if ($action === 'delete') {
    $id = trim((string)($data['id'] ?? ''));
    $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
    jsonResponse(true, 'Клієнта видалено.');
}
$id = trim((string)($data['id'] ?? ''));
$name = trim((string)($data['name'] ?? ''));
$legalForm = trim((string)($data['legal_form'] ?? ''));
$taxSystem = trim((string)($data['tax_system'] ?? ''));
$allowedForms = ['ТОВ', 'ФОП', 'ПрАТ', 'АТ', 'Неприбуткова організація', 'Інше'];
$allowedSystems = ['Загальна система', 'Єдиний податок 3 група', 'Єдиний податок 5 група', 'Єдиний податок 4 група', 'Без системи оподаткування'];
if ($name === '' || !in_array($legalForm, $allowedForms, true) || !in_array($taxSystem, $allowedSystems, true)) jsonResponse(false, 'Заповніть назву, форму власності та систему оподаткування.', [], 422);
$hasVat = !empty($data['has_vat']) ? 1 : 0; $hasExcise = !empty($data['has_excise']) ? 1 : 0; $hasEmployees = !empty($data['has_employees']) ? 1 : 0;
$employeeCount = $hasEmployees ? max(1, (int)($data['employee_count'] ?? 0)) : null;
$specialTaxes = array_values(array_intersect((array)($data['special_taxes'] ?? []), ['tourist', 'land', 'property', 'rent', 'environmental', 'transport', 'retail_excise', 'other']));
$module = in_array((string)($data['module'] ?? 'accounting'), ['accounting', 'dentistry', 'legal', 'real_estate'], true) ? (string)$data['module'] : 'accounting';
$moduleData = json_encode((array)($data['module_data'] ?? []), JSON_UNESCAPED_UNICODE);
$services = array_values(array_filter(array_map(static function ($service): array {
    if (!is_array($service)) return [];
    $allowedPeriods = ['Щогодини', 'Щодня', 'Щотижня', 'Місяць', 'Квартал', 'Рік', 'Разово'];
    $parameters = array_values(array_filter(array_map(static fn($item): string => trim((string)$item), (array)($service['parameters'] ?? [])), static fn(string $item): bool => $item !== ''));
    return [
        'name' => trim((string)($service['name'] ?? '')),
        'price' => max(0, (float)($service['price'] ?? 0)),
        'period' => in_array((string)($service['period'] ?? 'Місяць'), $allowedPeriods, true) ? (string)$service['period'] : 'Місяць',
        'official_due_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($service['official_due_date'] ?? '')) ? (string)$service['official_due_date'] : '',
        'accountant_id' => trim((string)($service['accountant_id'] ?? '')),
        'internal_days_before' => min(90, max(0, (int)($service['internal_days_before'] ?? 5))),
        'parameters' => $parameters,
        'reminders_enabled' => array_key_exists('reminders_enabled', $service) ? !empty($service['reminders_enabled']) : true,
        'create_task' => !empty($service['create_task']),
        'task_id' => preg_match('/^[a-f0-9]{32}$/', (string)($service['task_id'] ?? '')) ? (string)$service['task_id'] : '',
    ];
}, (array)($data['services'] ?? [])), static fn(array $service): bool => $service['name'] !== ''));
$servicesJson = json_encode($services, JSON_UNESCAPED_UNICODE);
if ($action === 'update' && $id !== '') {
    $pdo->prepare('UPDATE clients SET name = ?, legal_form = ?, tax_system = ?, has_vat = ?, has_excise = ?, has_employees = ?, employee_count = ?, special_taxes = ?, services = ?, module = ?, module_data = ? WHERE id = ?')->execute([$name, $legalForm, $taxSystem, $hasVat, $hasExcise, $hasEmployees, $employeeCount, json_encode($specialTaxes, JSON_UNESCAPED_UNICODE), $servicesJson, $module, $moduleData, $id]);
    jsonResponse(true, 'Дані клієнта оновлено.');
}
$newId = bin2hex(random_bytes(16));
$pdo->prepare('INSERT INTO clients (id, name, legal_form, tax_system, has_vat, has_excise, has_employees, employee_count, special_taxes, services, module, module_data, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$newId, $name, $legalForm, $taxSystem, $hasVat, $hasExcise, $hasEmployees, $employeeCount, json_encode($specialTaxes, JSON_UNESCAPED_UNICODE), $servicesJson, $module, $moduleData, $userId]);
jsonResponse(true, 'Клієнта створено.', ['id' => $newId]);
