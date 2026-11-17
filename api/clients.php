<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_once __DIR__ . '/plans.php';
session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);
$pdo = db();
$userId = (string)$_SESSION['user_id'];
$userQuery = $pdo->prepare('SELECT id, name, email, role, avatar_url FROM users WHERE id = ? LIMIT 1');
$userQuery->execute([$userId]);
$user = $userQuery->fetch();
if (!$user) jsonResponse(false, 'Користувача не знайдено.', [], 401);
$planContext = workspacePlan($pdo, $userId, (string)$user['role']);
$userColumns = array_column($pdo->query('SHOW COLUMNS FROM users')->fetchAll(), 'Field');
if (!in_array('active_client_module', $userColumns, true)) $pdo->exec("ALTER TABLE users ADD active_client_module VARCHAR(40) NOT NULL DEFAULT 'accounting'");
$moduleQuery = $pdo->prepare('SELECT active_client_module FROM users WHERE id = ?'); $moduleQuery->execute([(string)$planContext['owner_id']]);
$activeClientModule = (string)($moduleQuery->fetchColumn() ?: 'accounting');
if (in_array($user['role'], ['admin', 'owner'], true) && trim((string)($_GET['workspace_owner_id'] ?? '')) !== '') {
    $selectedOwnerQuery = $pdo->prepare("SELECT active_client_module FROM users WHERE id = ? AND role IN ('manager','admin','owner') LIMIT 1");
    $selectedOwnerQuery->execute([trim((string)$_GET['workspace_owner_id'])]);
    $selectedOwnerModule = $selectedOwnerQuery->fetchColumn();
    if ($selectedOwnerModule !== false) $activeClientModule = (string)$selectedOwnerModule;
}
if (!in_array($activeClientModule, ['accounting', 'dentistry', 'legal', 'real_estate'], true)) $activeClientModule = 'accounting';
$user['subscription'] = planSettingsPayload($planContext);
$user['workspaces'] = $planContext['workspaces']; $user['active_workspace_id'] = $planContext['owner_id']; $user['workspace_role'] = $planContext['workspace_role'];
$pdo->exec("CREATE TABLE IF NOT EXISTS clients (id CHAR(32) PRIMARY KEY, name VARCHAR(190) NOT NULL, legal_form VARCHAR(40) NOT NULL, tax_system VARCHAR(80) NOT NULL, has_vat TINYINT(1) NOT NULL DEFAULT 0, has_excise TINYINT(1) NOT NULL DEFAULT 0, has_employees TINYINT(1) NOT NULL DEFAULT 0, employee_count INT NULL, special_taxes TEXT NULL, services TEXT NULL, module VARCHAR(40) NOT NULL DEFAULT 'accounting', module_data TEXT NULL, created_by CHAR(32) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_clients_created_by (created_by)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS client_members (client_id CHAR(32) NOT NULL, user_id CHAR(32) NOT NULL, assigned_by CHAR(32) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (client_id, user_id), INDEX idx_client_members_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$columns = $pdo->query("SHOW COLUMNS FROM clients")->fetchAll(); $columnNames = array_column($columns, 'Field'); if (!in_array('module', $columnNames, true)) $pdo->exec("ALTER TABLE clients ADD module VARCHAR(40) NOT NULL DEFAULT 'accounting'"); if (!in_array('module_data', $columnNames, true)) $pdo->exec("ALTER TABLE clients ADD module_data TEXT NULL"); if (!in_array('services', $columnNames, true)) $pdo->exec("ALTER TABLE clients ADD services TEXT NULL");
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$data = $method === 'POST' ? body() : [];
$canManage = in_array($planContext['workspace_role'], ['admin', 'owner', 'manager', 'project_lead'], true);
$isAdmin = in_array($user['role'], ['admin', 'owner'], true);
$isProjectLead = $planContext['workspace_role'] === 'project_lead';
$clientAction = (string)($data['action'] ?? 'create');
if ($method === 'POST' && in_array($clientAction, ['update', 'delete'], true)) {
    if (!$canManage) jsonResponse(false, 'Звичайний користувач може лише переглядати клієнтів.', [], 403);
    if ($isProjectLead && $clientAction === 'delete') jsonResponse(false, 'Керівник проєкту може керувати клієнтами, але не видаляти їх.', [], 403);
    $clientId = trim((string)($data['id'] ?? ''));
    if (!in_array($user['role'], ['admin', 'owner'], true)) {
        $ownerMarks = implode(',', array_fill(0, count($planContext['member_ids']), '?'));
        $scope = $pdo->prepare("SELECT id FROM clients WHERE id = ? AND created_by IN ({$ownerMarks}) LIMIT 1");
        $scope->execute(array_merge([$clientId], $planContext['member_ids']));
        if (!$scope->fetchColumn()) jsonResponse(false, 'Клієнта не знайдено в активному просторі.', [], 404);
    }
    $claims = resourceClaims($pdo, $userId, 'client', $clientId);
    requireClaim($claims, $clientAction === 'delete' ? 'delete' : 'edit');
}

if ($method === 'GET') {
    $workspaceIds = array_values(array_unique(array_map('strval', $planContext['member_ids'] ?? [$userId])));
    if (!$workspaceIds) $workspaceIds = [$userId];
    $workspaceMarks = implode(',', array_fill(0, count($workspaceIds), '?'));
    if (in_array($user['role'], ['admin', 'owner'], true)) {
        $clientQuery = $pdo->query('SELECT c.*, u.name AS creator_name FROM clients c JOIN users u ON u.id = c.created_by ORDER BY c.name');
    } elseif ($canManage) {
        $clientQuery = $pdo->prepare("SELECT DISTINCT c.*, u.name AS creator_name FROM clients c JOIN users u ON u.id = c.created_by LEFT JOIN client_members cm ON cm.client_id = c.id AND cm.user_id = ? WHERE c.created_by IN ({$workspaceMarks}) OR (cm.user_id IS NOT NULL AND c.created_by IN ({$workspaceMarks})) ORDER BY c.name");
        $clientQuery->execute(array_merge([$userId], $workspaceIds, $workspaceIds));
    } else {
        $clientQuery = $pdo->prepare("SELECT c.*, u.name AS creator_name FROM clients c JOIN users u ON u.id = c.created_by JOIN client_members cm ON cm.client_id = c.id AND cm.user_id = ? WHERE c.created_by IN ({$workspaceMarks}) ORDER BY c.name");
        $clientQuery->execute(array_merge([$userId], $workspaceIds));
    }
    $clients = $clientQuery->fetchAll();
    $clients = claimRows($pdo, $userId, 'client', $clients);
    $serviceUsers = [];
    foreach ($pdo->query('SELECT id, name, email, role FROM users')->fetchAll() as $serviceUser) $serviceUsers[(string)$serviceUser['id']] = $serviceUser;
    $catalogResponsibleAmounts = [];
    try {
        $catalogQuery = $pdo->prepare('SELECT name, responsible_amount FROM service_catalog WHERE active = 1 AND created_by = ?');
        $catalogQuery->execute([$planContext['owner_id']]);
        foreach ($catalogQuery->fetchAll() as $catalogService) {
            $catalogResponsibleAmounts[mb_strtolower(trim((string)$catalogService['name']))] = (float)$catalogService['responsible_amount'];
        }
    } catch (Throwable $exception) {
        // The service catalog is optional for older installations.
    }
    foreach ($clients as &$client) {
        $client['special_taxes'] = $client['special_taxes'] ? (json_decode($client['special_taxes'], true) ?: []) : [];
        $client['services'] = $client['services'] ? (json_decode($client['services'], true) ?: []) : [];
        foreach ($client['services'] as &$service) {
            if (!array_key_exists('responsible_amount', $service)) {
                $serviceNameKey = mb_strtolower(trim((string)($service['name'] ?? '')));
                $service['responsible_amount'] = $catalogResponsibleAmounts[$serviceNameKey] ?? null;
            }
            $person = $serviceUsers[(string)($service['accountant_id'] ?? '')] ?? null;
            if ($person) { $service['accountant_name'] = $person['name']; $service['accountant_email'] = $person['email']; }
        }
        unset($service);
        $client['module_data'] = $client['module_data'] ? (json_decode($client['module_data'], true) ?: []) : [];
    }
    unset($client);
    $appointmentStatuses = [];
    $appointmentTaskIds = [];
    foreach ($clients as $client) {
        if (($client['module'] ?? '') !== 'dentistry') continue;
        foreach ((array)($client['services'] ?? []) as $service) {
            $taskId = trim((string)($service['appointment_task_id'] ?? ''));
            if (preg_match('/^[a-f0-9]{32}$/i', $taskId)) $appointmentTaskIds[$taskId] = true;
        }
    }
    if ($appointmentTaskIds) {
        try {
            $taskIds = array_keys($appointmentTaskIds);
            $taskMarks = implode(',', array_fill(0, count($taskIds), '?'));
            $taskStatusQuery = $pdo->prepare("SELECT id, status FROM tasks WHERE is_client_appointment = 1 AND id IN ({$taskMarks})");
            $taskStatusQuery->execute($taskIds);
            foreach ($taskStatusQuery->fetchAll() as $task) $appointmentStatuses[(string)$task['id']] = (string)$task['status'];
        } catch (Throwable $ignored) {
            // Older installations can load clients before the task appointment migration runs.
        }
        foreach ($clients as &$client) {
            if (($client['module'] ?? '') !== 'dentistry') continue;
            foreach ($client['services'] as &$service) {
                $taskId = (string)($service['appointment_task_id'] ?? '');
                if ($taskId !== '') $service['appointment_status'] = $appointmentStatuses[$taskId] ?? null;
            }
            unset($service);
        }
        unset($client);
    }
    // Responsible accountants can be any member of the workspace team,
    // including its manager. Keep assignment options scoped to that workspace.
    $availableUsers = [];
    $managerTeamIds = array_fill_keys($workspaceIds, true);
    $manageableTeamIds = $managerTeamIds;
    if ($isProjectLead) {
        $invitedUsers = $pdo->prepare("SELECT DISTINCT u.id FROM users u WHERE EXISTS (SELECT 1 FROM user_invitations i WHERE i.email = u.email AND i.invited_by = ? AND i.status = 'accepted') OR EXISTS (SELECT 1 FROM project_invitations i WHERE i.email = u.email AND i.invited_by = ? AND i.status = 'accepted')");
        $invitedUsers->execute([$userId, $userId]);
        $manageableTeamIds = array_fill_keys(array_map('strval', $invitedUsers->fetchAll(PDO::FETCH_COLUMN)), true);
    }
    $accountantTeamIds = $workspaceIds;
    if ($isAdmin && trim((string)($_GET['workspace_owner_id'] ?? '')) !== '') {
        $selectedOwnerId = trim((string)$_GET['workspace_owner_id']);
        $ownerCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role IN ('manager','admin','owner') LIMIT 1");
        $ownerCheck->execute([$selectedOwnerId]);
        if (!$ownerCheck->fetchColumn()) jsonResponse(false, 'Простір не знайдено.', [], 404);
        $selectedOwnerRoleQuery = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
        $selectedOwnerRoleQuery->execute([$selectedOwnerId]);
        $selectedOwnerContext = workspacePlan($pdo, $selectedOwnerId, (string)($selectedOwnerRoleQuery->fetchColumn() ?: 'manager'));
        $accountantTeamIds = array_map('strval', $selectedOwnerContext['member_ids'] ?? [$selectedOwnerId]);
        $manageableTeamIds = array_fill_keys($accountantTeamIds, true);
    } elseif ($isAdmin) {
        $accountantTeamIds = array_keys($serviceUsers);
    }
    if ($canManage) {
        $accountantTeamLookup = array_fill_keys(array_map('strval', $accountantTeamIds), true);
        $availableUsers = array_values(array_filter($serviceUsers, static fn(array $person): bool => isset($accountantTeamLookup[(string)$person['id']]) && in_array($person['role'], ['admin','owner','manager','user'], true)));
        usort($availableUsers, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']) ?: strcmp($a['email'], $b['email']));
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
            $isAllowedManagerMember = $person && (!in_array($planContext['workspace_role'], ['manager', 'project_lead'], true) || isset($manageableTeamIds[(string)$person['id']]));
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
    $teamMemberIds = array_fill_keys($workspaceIds, true);
    if ($isAdmin) {
        $selectedTeamOwnerId = trim((string)($_GET['workspace_owner_id'] ?? ''));
        if ($selectedTeamOwnerId !== '') {
            $selectedOwnerRoleQuery = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
            $selectedOwnerRoleQuery->execute([$selectedTeamOwnerId]);
            $selectedOwnerRole = (string)($selectedOwnerRoleQuery->fetchColumn() ?: 'manager');
            $selectedOwnerContext = workspacePlan($pdo, $selectedTeamOwnerId, $selectedOwnerRole);
            $teamMemberIds = array_fill_keys(array_map('strval', $selectedOwnerContext['member_ids'] ?? [$selectedTeamOwnerId]), true);
        } else {
            $teamMemberIds = array_fill_keys(array_keys($serviceUsers), true);
        }
    }
    $teamUsers = array_values(array_filter($serviceUsers, static fn(array $person): bool => isset($teamMemberIds[(string)$person['id']]) && in_array($person['role'], ['admin', 'owner', 'manager', 'user'], true)));
    $doctorIds = [];
    if ($activeClientModule === 'dentistry') {
        $doctorWorkspaceOwner = $isAdmin && trim((string)($_GET['workspace_owner_id'] ?? '')) !== ''
            ? trim((string)$_GET['workspace_owner_id'])
            : (string)$planContext['owner_id'];
        foreach ($pdo->query('SELECT id, name, permissions FROM project_groups WHERE project_id IS NULL')->fetchAll() as $group) {
            $policy = groupPolicy($group['permissions'] ?? null);
            if (($policy['owner_id'] ?? '') !== $doctorWorkspaceOwner || mb_strtolower(trim((string)$group['name']), 'UTF-8') !== 'лікар') continue;
            $doctorGroupQuery = $pdo->prepare('SELECT user_id FROM project_group_members WHERE group_id = ?');
            $doctorGroupQuery->execute([(string)$group['id']]);
            foreach ($doctorGroupQuery->fetchAll(PDO::FETCH_COLUMN) as $doctorId) $doctorIds[(string)$doctorId] = true;
        }
    }
    foreach ($teamUsers as &$teamUser) $teamUser['is_doctor'] = isset($doctorIds[(string)$teamUser['id']]);
    unset($teamUser);
    usort($teamUsers, static fn(array $left, array $right): int => strcmp($left['name'], $right['name']) ?: strcmp($left['email'], $right['email']));
    $adminWorkspaces = $isAdmin ? $pdo->query("SELECT id, name, email, role FROM users WHERE role IN ('manager','admin','owner') ORDER BY name")->fetchAll() : [];
    jsonResponse(true, 'Клієнтів завантажено.', ['user' => $user, 'clients' => $clients, 'active_client_module' => $activeClientModule, 'available_users' => $availableUsers, 'team_users' => $teamUsers, 'available_users_by_client' => $availableByClient, 'admin_workspaces' => $adminWorkspaces, 'permissions' => ['manage' => $canManage, 'delete' => !$isProjectLead]]);
}
if (!$canManage) jsonResponse(false, 'Створювати клієнтів можуть лише адміністратор, менеджер або керівник проєкту.', [], 403);
$action = (string)($data['action'] ?? 'create');
if ($action === 'delete') {
    $id = trim((string)($data['id'] ?? ''));
    $patientQuery = $pdo->prepare('SELECT module FROM clients WHERE id = ? LIMIT 1');
    $patientQuery->execute([$id]);
    if ((string)$patientQuery->fetchColumn() === 'dentistry') {
        $taskColumns = array_column($pdo->query('SHOW COLUMNS FROM tasks')->fetchAll(), 'Field');
        if (!in_array('is_client_appointment', $taskColumns, true)) $pdo->exec("ALTER TABLE tasks ADD is_client_appointment TINYINT(1) NOT NULL DEFAULT 0");
        $pdo->exec("CREATE TABLE IF NOT EXISTS task_clients (task_id CHAR(32) NOT NULL, client_id CHAR(32) NOT NULL, PRIMARY KEY (task_id, client_id), INDEX idx_task_clients_client (client_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $visitCount = $pdo->prepare('SELECT COUNT(*) FROM tasks t JOIN task_clients tc ON tc.task_id = t.id WHERE tc.client_id = ? AND t.is_client_appointment = 1');
        $visitCount->execute([$id]);
        if ((int)$visitCount->fetchColumn() > 0) jsonResponse(false, 'Картку пацієнта з історією прийомів не можна видалити. Збережіть її як архівну картку.', ['code' => 'patient_has_history'], 409);
        $careTables = ['dental_medical_records', 'dental_treatment_plans', 'dental_performed_services', 'dental_invoices', 'dental_payments', 'dental_documents'];
        foreach ($careTables as $table) {
            $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $exists->execute([$table]);
            if ((int)$exists->fetchColumn() === 0) continue;
            $count = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE patient_id = ?");
            $count->execute([$id]);
            if ((int)$count->fetchColumn() > 0) jsonResponse(false, 'Картку пацієнта з медичною або фінансовою історією не можна видалити. Збережіть її як архівну картку.', ['code' => 'patient_has_history'], 409);
        }
    }
    $pdo->prepare('DELETE FROM client_members WHERE client_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
    jsonResponse(true, 'Клієнта видалено.');
}
$id = trim((string)($data['id'] ?? ''));
$name = trim((string)($data['name'] ?? ''));
$legalForm = trim((string)($data['legal_form'] ?? ''));
$taxSystem = trim((string)($data['tax_system'] ?? ''));
$allowedForms = ['ТОВ', 'ФОП', 'ПрАТ', 'АТ', 'Неприбуткова організація', 'Інше'];
$allowedSystems = ['1 група', '2 група', '3 група', '4 група', 'Загальна система', 'Без системи оподаткування', 'ФОП 1 група', 'ФОП 2 група', 'ФОП 3 група', 'ФОП на загальній системі', 'ТОВ на єдиному податку (3 група)', 'ТОВ на загальній системі', 'Єдиний податок 1 група', 'Єдиний податок 2 група', 'Єдиний податок 3 група', 'Єдиний податок 4 група', 'Єдиний податок, 3 група', 'Єдиний податок, 4 група', 'Неприбутковий статус'];
if ($name === '' || !in_array($legalForm, $allowedForms, true) || !in_array($taxSystem, $allowedSystems, true)) jsonResponse(false, 'Заповніть назву, форму власності та систему оподаткування.', [], 422);
$hasVat = !empty($data['has_vat']) ? 1 : 0; $hasExcise = !empty($data['has_excise']) ? 1 : 0; $hasEmployees = !empty($data['has_employees']) ? 1 : 0;
$employeeCount = $hasEmployees ? max(1, (int)($data['employee_count'] ?? 0)) : null;
$specialTaxes = array_values(array_intersect((array)($data['special_taxes'] ?? []), ['tourist', 'land', 'property', 'rent', 'environmental', 'transport', 'retail_excise', 'other']));
$requestedModule = (string)($data['module'] ?? 'accounting');
$module = in_array($requestedModule, ['accounting', 'dentistry', 'legal', 'real_estate'], true) ? $requestedModule : 'accounting';
$moduleDataValues = (array)($data['module_data'] ?? []);
$globalAccountantIds = array_values(array_unique(array_filter(array_map(static fn($value): string => trim((string)$value), (array)($moduleDataValues['accountant_ids'] ?? [])), static fn(string $value): bool => preg_match('/^[a-f0-9]{32}$/i', $value) === 1)));
if ($module === 'accounting') $moduleDataValues['accountant_ids'] = $globalAccountantIds; else unset($moduleDataValues['accountant_ids']);
$moduleData = json_encode($moduleDataValues, JSON_UNESCAPED_UNICODE);
$services = array_values(array_filter(array_map(static function ($service) use ($pdo): array {
    if (!is_array($service)) return [];
    $allowedPeriods = ['Щогодини', 'Щодня', 'Щотижня', 'Місяць', 'Квартал', 'Рік', 'Разово'];
    $parameters = array_values(array_filter(array_map(static fn($item): string => trim((string)$item), (array)($service['parameters'] ?? [])), static fn(string $item): bool => $item !== ''));
    $allowedTeeth = [11,12,13,14,15,16,17,18,21,22,23,24,25,26,27,28,31,32,33,34,35,36,37,38,41,42,43,44,45,46,47,48];
    $toothNumbers = array_values(array_unique(array_intersect($allowedTeeth, array_map('intval', (array)($service['tooth_numbers'] ?? [])))));
    $packageId = trim((string)($service['package_id'] ?? ''));
    $isPackage = !empty($service['is_package']) || $packageId !== '';
    $calendarEnabled = false;
    if ($packageId !== '' && preg_match('/^[a-f0-9]{32}$/i', $packageId)) {
        $packageCalendar = $pdo->prepare('SELECT calendar_enabled FROM service_packages WHERE id = ? LIMIT 1');
        $packageCalendar->execute([$packageId]);
        $calendarEnabled = (bool)$packageCalendar->fetchColumn();
    }
    return [
        'name' => trim((string)($service['name'] ?? '')),
        'price' => max(0, (float)($service['price'] ?? 0)),
        'unit_price' => max(0, (float)($service['unit_price'] ?? ($service['price'] ?? 0))),
        'responsible_amount' => max(0, (float)(($service['responsible_mode'] ?? 'amount') === 'percent' ? ((float)($service['price'] ?? 0) * min(100, max(0, (float)($service['responsible_percent'] ?? 0))) / 100) : ($service['responsible_amount'] ?? 0))),
        'responsible_mode' => in_array((string)($service['responsible_mode'] ?? 'amount'), ['amount', 'percent'], true) ? (string)$service['responsible_mode'] : 'amount',
        'responsible_percent' => min(100, max(0, (float)($service['responsible_percent'] ?? 0))),
        'period' => in_array((string)($service['period'] ?? 'Місяць'), $allowedPeriods, true) ? (string)$service['period'] : 'Місяць',
        'official_due_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($service['official_due_date'] ?? '')) ? (string)$service['official_due_date'] : '',
        'accountant_id' => trim((string)($service['accountant_id'] ?? '')),
        'accountant_ids' => array_values(array_unique(array_filter(array_map(static fn($value): string => trim((string)$value), (array)($service['accountant_ids'] ?? [])), static fn(string $value): bool => preg_match('/^[a-f0-9]{32}$/i', $value) === 1))),
        'is_package' => $isPackage,
        'package_id' => $packageId,
        'package_name' => trim((string)($service['package_name'] ?? '')),
        'package_services' => array_values(array_filter(array_map(static fn($item): string => trim((string)$item), (array)($service['package_services'] ?? [])), static fn(string $item): bool => $item !== '')),
        'package_responsible_amount' => max(0, (float)($service['package_responsible_amount'] ?? 0)),
        'internal_days_before' => min(90, max(0, (int)($service['internal_days_before'] ?? 5))),
        'parameters' => $parameters,
        'tooth_numbers' => $toothNumbers,
        'reminders_enabled' => array_key_exists('reminders_enabled', $service) ? !empty($service['reminders_enabled']) : true,
        'create_task' => !empty($service['create_task']),
        'task_id' => preg_match('/^[a-f0-9]{32}$/', (string)($service['task_id'] ?? '')) ? (string)$service['task_id'] : '',
        'calendar_enabled' => $isPackage && $calendarEnabled,
        'add_to_calendar' => !empty($service['add_to_calendar']) && (!$isPackage || $calendarEnabled),
        'appointment_at' => preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', (string)($service['appointment_at'] ?? '')) ? (string)$service['appointment_at'] : '',
        'appointment_duration_minutes' => in_array((int)($service['appointment_duration_minutes'] ?? 60), [15, 30, 45, 60, 90, 120, 150, 180, 240, 300, 360, 480], true) ? (int)($service['appointment_duration_minutes'] ?? 60) : 60,
        'appointment_reminder_minutes' => in_array((int)($service['appointment_reminder_minutes'] ?? 1440), [0, 15, 60, 1440, 4320], true) ? (int)($service['appointment_reminder_minutes'] ?? 1440) : 1440,
        'appointment_task_id' => preg_match('/^[a-f0-9]{32}$/', (string)($service['appointment_task_id'] ?? '')) ? (string)$service['appointment_task_id'] : '',
    ];
}, (array)($data['services'] ?? [])), static fn(array $service): bool => $service['name'] !== ''));
$preserveDentistryLegacyServices = $module === 'dentistry' && $action === 'update';
if ($module === 'dentistry') $services = [];
$existingAccountantIds = [];
$existingPackageKeys = [];
$previousServices = [];
if ($action === 'update' && $id !== '') {
    $existingServicesQuery = $pdo->prepare('SELECT services FROM clients WHERE id = ? LIMIT 1');
    $existingServicesQuery->execute([$id]);
    $previousServices = json_decode((string)($existingServicesQuery->fetchColumn() ?: '[]'), true) ?: [];
    foreach ($previousServices as $existingService) {
        $existingAccountantId = trim((string)($existingService['accountant_id'] ?? ''));
        if ($existingAccountantId !== '') $existingAccountantIds[$existingAccountantId] = true;
        $existingPackageId = trim((string)($existingService['package_id'] ?? ''));
        if ($existingPackageId !== '') $existingPackageKeys[$existingPackageId . '|' . trim((string)($existingService['name'] ?? ''))] = true;
    }
}
$seenServiceKeys = [];
foreach ($services as $service) {
    $packageId = (string)$service['package_id'];
    $serviceKey = $packageId !== ''
        ? 'package:' . $packageId
        : 'service:' . mb_strtolower(trim((string)$service['name']), 'UTF-8');
    if (isset($seenServiceKeys[$serviceKey]) && !($module === 'dentistry' && $packageId === '')) {
        $duplicateLabel = $packageId !== '' ? (string)($service['package_name'] ?: $service['name']) : (string)$service['name'];
        jsonResponse(false, '«' . $duplicateLabel . '» уже додано до клієнта. Приберіть повторну позицію.', ['code' => 'duplicate_client_service'], 422);
    }
    $seenServiceKeys[$serviceKey] = true;
}
$packageIds = array_values(array_unique(array_filter(array_map(static fn(array $service): string => (string)($service['package_id'] ?? ''), $services))));
if ($packageIds) {
    $packageMarks = implode(',', array_fill(0, count($packageIds), '?'));
    $ownedPackagesQuery = $pdo->prepare("SELECT id FROM service_packages WHERE ".($isAdmin ? '' : 'created_by = ? AND ')."id IN ({$packageMarks})");
    $ownedPackagesQuery->execute($isAdmin ? $packageIds : array_merge([$planContext['owner_id']], $packageIds));
    $ownedPackageIds = array_fill_keys(array_map('strval', $ownedPackagesQuery->fetchAll(PDO::FETCH_COLUMN)), true);
    foreach ($services as $service) {
        $packageId = (string)$service['package_id'];
        if ($packageId === '' || isset($ownedPackageIds[$packageId])) continue;
        if (!isset($existingPackageKeys[$packageId . '|' . $service['name']])) jsonResponse(false, 'Можна додавати лише пакети, створені вами.', ['code' => 'package_not_owned'], 403);
    }
}
$requestedAccountantIds = array_values(array_unique(array_filter(array_merge($globalAccountantIds, ...array_map(static fn(array $service): array => array_merge([(string)($service['accountant_id'] ?? '')], (array)($service['accountant_ids'] ?? [])), $services)))));
if ($requestedAccountantIds) {
    $allowedAccountantIds = array_fill_keys(array_map('strval', $planContext['member_ids'] ?? [$userId]), true);
    foreach ($requestedAccountantIds as $accountantId) {
        // Keep legacy assignments after a claim/team change, but never allow
        // a new assignment to a person outside the current team.
        if (!$isAdmin && !isset($allowedAccountantIds[$accountantId]) && !isset($existingAccountantIds[$accountantId])) jsonResponse(false, 'Відповідального бухгалтера не знайдено у вашій команді.', [], 422);
    }
}
$servicesJson = json_encode($services, JSON_UNESCAPED_UNICODE);
$serviceKey = static fn(array $service): string => (string)($service['name'] ?? '') . '|' . (string)($service['package_id'] ?? '');
$previousServiceCounts = [];
foreach ($previousServices as $previousService) {
    $key = $serviceKey($previousService);
    $previousServiceCounts[$key] = ($previousServiceCounts[$key] ?? 0) + 1;
}
$currentServiceCounts = [];
$addedStandalone = 0;
foreach ($services as $service) {
    $key = $serviceKey($service);
    $currentServiceCounts[$key] = ($currentServiceCounts[$key] ?? 0) + 1;
    if ($currentServiceCounts[$key] <= ($previousServiceCounts[$key] ?? 0)) continue;
    if (empty($planContext['plan']['services']) && $service['package_id'] !== '') requirePlanFeature($planContext, 'services');
    $addedStandalone++;
}
$serviceLimit = $planContext['plan']['services_per_client'] ?? null;
$standaloneCount = count(array_filter($services, static fn(array $service): bool => $service['package_id'] === ''));
if ($action === 'update' && $serviceLimit !== null && $addedStandalone > 0) {
    acquirePlanCapacityLock($planContext, 'services_per_client');
    if ($standaloneCount > (int)$serviceLimit) jsonResponse(false, 'У плані «' . $planContext['plan']['name'] . '» можна додати не більше ' . (int)$serviceLimit . ' послуг на одного клієнта.', ['code' => 'plan_limit_reached', 'metric' => 'services_per_client', 'limit' => (int)$serviceLimit, 'current' => (int)$serviceLimit, 'plan' => $planContext['key']], 403);
}
if (empty($planContext['plan']['services']) && $serviceLimit === null && $addedStandalone > 0) requirePlanFeature($planContext, 'services');
if ($action === 'update' && $id !== '') {
    $existingClientQuery = $pdo->prepare('SELECT id, legal_form, tax_system, has_vat, has_excise, has_employees, employee_count, special_taxes, module_data, module FROM clients WHERE id = ? LIMIT 1');
    $existingClientQuery->execute([$id]);
    $previousProfile = $existingClientQuery->fetch();
    if (!$previousProfile) jsonResponse(false, 'Клієнта не знайдено.', [], 404);
    $ownerId = null;
    if ($isAdmin && !empty($data['workspace_owner_id'])) {
        $ownerCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role IN ('manager','admin','owner') LIMIT 1");
        $ownerCheck->execute([(string)$data['workspace_owner_id']]);
        if (!$ownerCheck->fetchColumn()) jsonResponse(false, 'Оберіть наявний простір для клієнта.', [], 422);
        $ownerId = (string)$data['workspace_owner_id'];
    }
    $updateSql = 'UPDATE clients SET name = ?, legal_form = ?, tax_system = ?, has_vat = ?, has_excise = ?, has_employees = ?, employee_count = ?, special_taxes = ?'.($preserveDentistryLegacyServices ? '' : ', services = ?').', module = ?, module_data = ?'.($ownerId !== null ? ', created_by = ?' : '').' WHERE id = ?';
    $updateValues = [$name, $legalForm, $taxSystem, $hasVat, $hasExcise, $hasEmployees, $employeeCount, json_encode($specialTaxes, JSON_UNESCAPED_UNICODE)];
    if (!$preserveDentistryLegacyServices) $updateValues[] = $servicesJson;
    $updateValues[] = $module; $updateValues[] = $moduleData;
    if ($ownerId !== null) $updateValues[] = $ownerId;
    $updateValues[] = $id;
    $pdo->prepare($updateSql)->execute($updateValues);
    if (($previousProfile['module'] ?? '') === 'accounting' && $module === 'accounting') {
        $oldData = json_decode((string)($previousProfile['module_data'] ?? '{}'), true) ?: [];
        $newData = json_decode((string)$moduleData, true) ?: [];
        $oldProfile = ['legal_form'=>$previousProfile['legal_form'], 'tax_system'=>$previousProfile['tax_system'], 'has_vat'=>(bool)$previousProfile['has_vat'], 'has_excise'=>(bool)$previousProfile['has_excise'], 'has_employees'=>(bool)$previousProfile['has_employees'], 'employee_count'=>$previousProfile['employee_count'], 'special_taxes'=>json_decode((string)($previousProfile['special_taxes'] ?? '[]'), true) ?: [], 'tax_income_over_40m'=>(bool)($oldData['tax_income_over_40m'] ?? false), 'tax_group'=>$oldData['tax_group'] ?? null, 'tax_profile_extra'=>$oldData['tax_profile_extra'] ?? []];
        $newProfile = ['legal_form'=>$legalForm, 'tax_system'=>$taxSystem, 'has_vat'=>(bool)$hasVat, 'has_excise'=>(bool)$hasExcise, 'has_employees'=>(bool)$hasEmployees, 'employee_count'=>$employeeCount, 'special_taxes'=>$specialTaxes, 'tax_income_over_40m'=>(bool)($newData['tax_income_over_40m'] ?? false), 'tax_group'=>$newData['tax_group'] ?? null, 'tax_profile_extra'=>$newData['tax_profile_extra'] ?? []];
        if ($oldProfile !== $newProfile) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS accounting_profile_history (id CHAR(32) PRIMARY KEY, owner_id CHAR(32) NOT NULL, client_id CHAR(32) NOT NULL, user_id CHAR(32) NOT NULL, old_values JSON NOT NULL, new_values JSON NOT NULL, reason VARCHAR(1000) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_accounting_profile_history (client_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->prepare('INSERT INTO accounting_profile_history (id,owner_id,client_id,user_id,old_values,new_values,reason) VALUES (?,?,?,?,?,?,?)')->execute([bin2hex(random_bytes(16)), (string)$planContext['owner_id'], $id, $userId, json_encode($oldProfile, JSON_UNESCAPED_UNICODE), json_encode($newProfile, JSON_UNESCAPED_UNICODE), mb_substr(trim((string)($data['profile_change_reason'] ?? $newData['profile_change_reason'] ?? '')), 0, 1000) ?: null]);
        }
    }
    if ($module === 'accounting' && $requestedAccountantIds) {
        $assignMember = $pdo->prepare('INSERT IGNORE INTO client_members (client_id, user_id, assigned_by) VALUES (?, ?, ?)');
        foreach ($requestedAccountantIds as $accountantId) $assignMember->execute([$id, $accountantId, $userId]);
    }
    releasePlanCapacityLocks((string)$planContext['owner_id']);
    $savedClientQuery = $pdo->prepare('SELECT id, name FROM clients WHERE id = ? LIMIT 1');
    $savedClientQuery->execute([$id]);
    $savedClient = $savedClientQuery->fetch();
    if ($serviceLimit !== null && $addedStandalone > 0 && $standaloneCount >= (int)$serviceLimit) notifyPlanCapacityReached($planContext, 'services_per_client', (int)$serviceLimit, (int)$serviceLimit);
    jsonResponse(true, 'Дані клієнта оновлено.', ['client' => $savedClient]);
}
$ownerId = $userId;
if ($isAdmin && !empty($data['workspace_owner_id'])) {
    $ownerCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role IN ('manager','admin','owner') LIMIT 1");
    $ownerCheck->execute([(string)$data['workspace_owner_id']]);
    if (!$ownerCheck->fetchColumn()) jsonResponse(false, 'Оберіть наявний простір для клієнта.', [], 422);
    $ownerId = (string)$data['workspace_owner_id'];
}
$requestedClientMemberIds = array_values(array_unique(array_filter(array_map('strval', (array)($data['client_member_ids'] ?? [])))));
$doctorId = trim((string)($moduleDataValues['doctor_id'] ?? ''));
if ($doctorId !== '') $requestedClientMemberIds[] = $doctorId;
foreach ($globalAccountantIds as $accountantId) $requestedClientMemberIds[] = $accountantId;
foreach ($services as $service) {
    $accountantId = trim((string)($service['accountant_id'] ?? ''));
    if ($accountantId !== '') $requestedClientMemberIds[] = $accountantId;
    foreach ((array)($service['accountant_ids'] ?? []) as $serviceAccountantId) $requestedClientMemberIds[] = (string)$serviceAccountantId;
}
$requestedClientMemberIds = array_values(array_unique(array_filter($requestedClientMemberIds)));
if ($requestedClientMemberIds) {
    if ($isAdmin && !empty($data['workspace_owner_id'])) {
        $memberRoleQuery = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
        $memberRoleQuery->execute([$ownerId]);
        $memberWorkspace = workspacePlan($pdo, $ownerId, (string)($memberRoleQuery->fetchColumn() ?: 'manager'));
        $allowedClientMemberIds = array_fill_keys(array_map('strval', $memberWorkspace['member_ids'] ?? [$ownerId]), true);
    } elseif ($isAdmin) {
        $allowedClientMemberIds = array_fill_keys(array_map('strval', $pdo->query("SELECT id FROM users WHERE role IN ('admin','owner','manager','user')")->fetchAll(PDO::FETCH_COLUMN)), true);
    } else {
        $allowedClientMemberIds = array_fill_keys(array_map('strval', $planContext['member_ids'] ?? [$userId]), true);
    }
    foreach ($requestedClientMemberIds as $memberId) {
        if (!isset($allowedClientMemberIds[$memberId])) jsonResponse(false, 'Вибраного виконавця не знайдено в команді цього простору.', [], 422);
    }
}
if (!$isAdmin) requirePlanCapacity($planContext, ($planContext['module'] ?? '') === 'dentistry' ? 'patients' : 'clients');
$newId = bin2hex(random_bytes(16));
$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO clients (id, name, legal_form, tax_system, has_vat, has_excise, has_employees, employee_count, special_taxes, services, module, module_data, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$newId, $name, $legalForm, $taxSystem, $hasVat, $hasExcise, $hasEmployees, $employeeCount, json_encode($specialTaxes, JSON_UNESCAPED_UNICODE), $servicesJson, $module, $moduleData, $ownerId]);
    if ($requestedClientMemberIds) {
        $assignMember = $pdo->prepare('INSERT IGNORE INTO client_members (client_id, user_id, assigned_by) VALUES (?, ?, ?)');
        foreach ($requestedClientMemberIds as $memberId) $assignMember->execute([$newId, $memberId, $userId]);
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}
releasePlanCapacityLocks((string)$planContext['owner_id']);
    $clientMetric = ($planContext['module'] ?? '') === 'dentistry' ? 'patients' : 'clients';
    $clientPlanLimit = $planContext['plan'][$clientMetric] ?? null;
    if ($clientPlanLimit !== null && (int)($planContext['counts'][$clientMetric] ?? 0) + 1 >= (int)$clientPlanLimit) notifyPlanCapacityReached($planContext, $clientMetric, (int)$clientPlanLimit, (int)$clientPlanLimit);
    $clientServiceLimit = $planContext['plan']['services_per_client'] ?? null;
    $clientStandaloneServiceCount = count(array_filter($services, static fn(array $service): bool => $service['package_id'] === ''));
    if ($clientServiceLimit !== null && $clientStandaloneServiceCount >= (int)$clientServiceLimit) notifyPlanCapacityReached($planContext, 'services_per_client', (int)$clientServiceLimit, (int)$clientServiceLimit);
    jsonResponse(true, 'Клієнта створено.', ['id' => $newId]);
