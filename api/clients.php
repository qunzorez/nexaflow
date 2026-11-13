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
    foreach ($pdo->query('SELECT id, name, email, role FROM users')->fetchAll() as $serviceUser) $serviceUsers[(string)$serviceUser['id']] = $serviceUser;
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
    // Match accounting groups in PHP: older production tables can use different
    // collations for group names and user IDs, making the joined SQL query fail.
    $availableUsers = [];
    $managerTeamIds = [];
    if ($user['role'] === 'manager') {
        $teamUsersQuery = $pdo->prepare("SELECT DISTINCT u.id FROM users u WHERE u.id = ? OR EXISTS (SELECT 1 FROM project_invitations pi WHERE pi.email = u.email AND pi.invited_by = ? AND pi.status = 'accepted') OR EXISTS (SELECT 1 FROM user_invitations ui WHERE ui.email = u.email AND ui.invited_by = ? AND ui.status = 'accepted')");
        $teamUsersQuery->execute([$userId, $userId, $userId]);
        foreach ($teamUsersQuery->fetchAll(PDO::FETCH_COLUMN) as $teamUserId) $managerTeamIds[(string)$teamUserId] = true;
    }
    if ($canManage) {
        $accountingGroupIds = [];
        foreach ($pdo->query('SELECT id, name FROM project_groups WHERE project_id IS NULL')->fetchAll() as $group) {
            $name = (string)$group['name'];
            $nameHex = strtoupper(bin2hex($name));
            if (preg_match('/(?:бухгалт|бугалт|account)/iu', $name)
                || strpos($nameHex, 'C390C291C391C283C391C285C390C2B3C390C2B0C390C2BB') === 0
                || strpos($nameHex, 'C390C2B1C391C283C391C285C390C2B3C390C2B0C390C2BB') === 0) {
                $accountingGroupIds[(string)$group['id']] = true;
            }
        }
        if ($accountingGroupIds) {
            $placeholders = implode(',', array_fill(0, count($accountingGroupIds), '?'));
            $membershipQuery = $pdo->prepare("SELECT user_id FROM project_group_members WHERE group_id IN ($placeholders)");
            $membershipQuery->execute(array_keys($accountingGroupIds));
            foreach ($membershipQuery->fetchAll() as $membership) {
                $person = $serviceUsers[(string)$membership['user_id']] ?? null;
                $isAllowedManagerMember = $user['role'] !== 'manager' || isset($managerTeamIds[(string)$person['id']]);
                if ($person && in_array($person['role'], ['admin', 'owner', 'manager', 'user'], true) && $isAllowedManagerMember) {
                    $availableUsers[(string)$person['id']] = $person;
                }
            }
            $availableUsers = array_values($availableUsers);
            usort($availableUsers, static function (array $left, array $right): int {
                return strcmp($left['name'], $right['name']) ?: strcmp($left['email'], $right['email']);
            });
        }
    }
    $availableByClient = [];
    if ($canManage && $clients) {
        foreach ($clients as $clientRow) {
            $availableByClient[(string)$clientRow['id']] = [];
        }
        $clientIds = array_keys($availableByClient);
        $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
        $clientMembersQuery = $pdo->prepare("SELECT client_id, user_id FROM client_members WHERE client_id IN ($placeholders)");
        $clientMembersQuery->execute($clientIds);
        foreach ($clientMembersQuery->fetchAll() as $membership) {
            $person = $serviceUsers[(string)$membership['user_id']] ?? null;
            $isAllowedManagerMember = $user['role'] !== 'manager' || isset($managerTeamIds[(string)$person['id']]);
            if ($person && in_array($person['role'], ['admin', 'owner', 'manager', 'user'], true) && $isAllowedManagerMember) {
                $availableByClient[(string)$membership['client_id']][] = $person;
            }
        }
        foreach ($availableByClient as &$clientAccountants) {
            usort($clientAccountants, static function (array $left, array $right): int {
                return strcmp($left['name'], $right['name']) ?: strcmp($left['email'], $right['email']);
            });
        }
        unset($clientAccountants);
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
$requestedModule = (string)($data['module'] ?? 'accounting');
$module = in_array($requestedModule, ['accounting', 'dentistry', 'legal', 'real_estate'], true) ? $requestedModule : 'accounting';
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
$existingAccountantIds = [];
if ($action === 'update' && $id !== '') {
    $existingServicesQuery = $pdo->prepare('SELECT services FROM clients WHERE id = ? LIMIT 1');
    $existingServicesQuery->execute([$id]);
    $existingServices = json_decode((string)($existingServicesQuery->fetchColumn() ?: '[]'), true) ?: [];
    foreach ($existingServices as $existingService) {
        $existingAccountantId = trim((string)($existingService['accountant_id'] ?? ''));
        if ($existingAccountantId !== '') $existingAccountantIds[$existingAccountantId] = true;
    }
}
$requestedAccountantIds = array_values(array_unique(array_filter(array_map(static fn(array $service): string => (string)($service['accountant_id'] ?? ''), $services))));
if ($requestedAccountantIds) {
    $accountingGroupIds = [];
    foreach ($pdo->query('SELECT id, name FROM project_groups WHERE project_id IS NULL')->fetchAll() as $group) {
        $name = (string)$group['name'];
        $nameHex = strtoupper(bin2hex($name));
        if (preg_match('/(?:бухгалт|бугалт|account)/iu', $name)
            || strpos($nameHex, 'C390C291C391C283C391C285C390C2B3C390C2B0C390C2BB') === 0
            || strpos($nameHex, 'C390C2B1C391C283C391C285C390C2B3C390C2B0C390C2BB') === 0) {
            $accountingGroupIds[(string)$group['id']] = true;
        }
    }
    $allowedAccountantIds = [];
    if ($accountingGroupIds) {
        $groupMarks = implode(',', array_fill(0, count($accountingGroupIds), '?'));
        $membershipQuery = $pdo->prepare("SELECT DISTINCT user_id FROM project_group_members WHERE group_id IN ($groupMarks)");
        $membershipQuery->execute(array_keys($accountingGroupIds));
        $allowedAccountantIds = array_fill_keys(array_map('strval', $membershipQuery->fetchAll(PDO::FETCH_COLUMN)), true);
    }
    if ($user['role'] === 'manager' && $allowedAccountantIds) {
        $teamUsersQuery = $pdo->prepare("SELECT DISTINCT u.id FROM users u WHERE u.id = ? OR EXISTS (SELECT 1 FROM project_invitations pi WHERE pi.email = u.email AND pi.invited_by = ? AND pi.status = 'accepted') OR EXISTS (SELECT 1 FROM user_invitations ui WHERE ui.email = u.email AND ui.invited_by = ? AND ui.status = 'accepted')");
        $teamUsersQuery->execute([$userId, $userId, $userId]);
        $managerTeamIds = array_fill_keys(array_map('strval', $teamUsersQuery->fetchAll(PDO::FETCH_COLUMN)), true);
        $allowedAccountantIds = array_filter($allowedAccountantIds, static fn($_, $id): bool => isset($managerTeamIds[(string)$id]), ARRAY_FILTER_USE_BOTH);
    }
    foreach ($requestedAccountantIds as $accountantId) {
        // Keep legacy assignments after a claim/team change, but never allow
        // a new assignment to a person outside the current team.
        if (!isset($allowedAccountantIds[$accountantId]) && !isset($existingAccountantIds[$accountantId])) jsonResponse(false, 'Відповідального бухгалтера не знайдено у вашій команді.', [], 422);
    }
}
$servicesJson = json_encode($services, JSON_UNESCAPED_UNICODE);
if ($action === 'update' && $id !== '') {
    $existingClientQuery = $pdo->prepare('SELECT id FROM clients WHERE id = ? LIMIT 1');
    $existingClientQuery->execute([$id]);
    if (!$existingClientQuery->fetchColumn()) jsonResponse(false, 'Клієнта не знайдено.', [], 404);
    $update = $pdo->prepare('UPDATE clients SET name = ?, legal_form = ?, tax_system = ?, has_vat = ?, has_excise = ?, has_employees = ?, employee_count = ?, special_taxes = ?, services = ?, module = ?, module_data = ? WHERE id = ?');
    $update->execute([$name, $legalForm, $taxSystem, $hasVat, $hasExcise, $hasEmployees, $employeeCount, json_encode($specialTaxes, JSON_UNESCAPED_UNICODE), $servicesJson, $module, $moduleData, $id]);
    $savedClientQuery = $pdo->prepare('SELECT id, name FROM clients WHERE id = ? LIMIT 1');
    $savedClientQuery->execute([$id]);
    $savedClient = $savedClientQuery->fetch();
    jsonResponse(true, 'Дані клієнта оновлено.', ['client' => $savedClient]);
}
$newId = bin2hex(random_bytes(16));
$pdo->prepare('INSERT INTO clients (id, name, legal_form, tax_system, has_vat, has_excise, has_employees, employee_count, special_taxes, services, module, module_data, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$newId, $name, $legalForm, $taxSystem, $hasVat, $hasExcise, $hasEmployees, $employeeCount, json_encode($specialTaxes, JSON_UNESCAPED_UNICODE), $servicesJson, $module, $moduleData, $userId]);
jsonResponse(true, 'Клієнта створено.', ['id' => $newId]);
