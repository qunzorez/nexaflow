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
    // Match accounting groups in PHP: older production tables can use different
    // collations for group names and user IDs, making the joined SQL query fail.
    $availableUsers = [];
    $managerTeamIds = array_fill_keys($workspaceIds, true);
    $manageableTeamIds = $managerTeamIds;
    if ($isProjectLead) {
        $invitedUsers = $pdo->prepare("SELECT DISTINCT u.id FROM users u WHERE EXISTS (SELECT 1 FROM user_invitations i WHERE i.email = u.email AND i.invited_by = ? AND i.status = 'accepted') OR EXISTS (SELECT 1 FROM project_invitations i WHERE i.email = u.email AND i.invited_by = ? AND i.status = 'accepted')");
        $invitedUsers->execute([$userId, $userId]);
        $manageableTeamIds = array_fill_keys(array_map('strval', $invitedUsers->fetchAll(PDO::FETCH_COLUMN)), true);
    }
    if ($isAdmin && $canManage) {
        $availableUsers = $pdo->query("SELECT id, name, email, role FROM users WHERE role IN ('admin','owner','manager','user') ORDER BY name, email")->fetchAll();
    } elseif ($canManage) {
        $accountingGroupIds = [];
        foreach ($pdo->query('SELECT id, name, permissions FROM project_groups WHERE project_id IS NULL')->fetchAll() as $group) {
            if (!in_array($user['role'], ['admin', 'owner'], true) && (groupPolicy($group['permissions'] ?? null)['owner_id'] ?? '') !== $planContext['owner_id']) continue;
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
                $isAllowedManagerMember = $person && (!in_array($planContext['workspace_role'], ['manager', 'project_lead'], true) || isset($manageableTeamIds[(string)$person['id']]));
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
    if ($isAdmin && trim((string)($_GET['workspace_owner_id'] ?? '')) !== '') {
        $selectedOwnerId = trim((string)$_GET['workspace_owner_id']);
        $ownerCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role IN ('manager','admin','owner') LIMIT 1");
        $ownerCheck->execute([$selectedOwnerId]);
        if (!$ownerCheck->fetchColumn()) jsonResponse(false, 'Простір не знайдено.', [], 404);
        $accountingGroupIds = [];
        foreach ($pdo->query('SELECT id, name, permissions FROM project_groups WHERE project_id IS NULL')->fetchAll() as $group) {
            if ((groupPolicy($group['permissions'] ?? null)['owner_id'] ?? '') !== $selectedOwnerId) continue;
            $groupName = (string)$group['name']; $nameHex = strtoupper(bin2hex($groupName));
            if (preg_match('/(?:бухгалт|бугалт|account)/iu', $groupName) || strpos($nameHex, 'C390C291C391C283C391C285C390C2B3C390C2B0C390C2BB') === 0 || strpos($nameHex, 'C390C2B1C391C283C391C285C390C2B3C390C2B0C390C2BB') === 0) $accountingGroupIds[(string)$group['id']] = true;
        }
        $accountantIds = [];
        if ($accountingGroupIds) {
            $groupMarks = implode(',', array_fill(0, count($accountingGroupIds), '?'));
            $accountantQuery = $pdo->prepare("SELECT DISTINCT user_id FROM project_group_members WHERE group_id IN ({$groupMarks})");
            $accountantQuery->execute(array_keys($accountingGroupIds));
            $accountantIds = array_fill_keys(array_map('strval', $accountantQuery->fetchAll(PDO::FETCH_COLUMN)), true);
        }
        $availableUsers = array_values(array_filter($serviceUsers, static fn(array $person): bool => isset($accountantIds[(string)$person['id']]) && in_array($person['role'], ['admin','owner','manager','user'], true)));
        usort($availableUsers, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']) ?: strcmp($a['email'], $b['email']));
        $manageableTeamIds = $accountantIds;
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
    $adminWorkspaces = $isAdmin ? $pdo->query("SELECT id, name, email, role FROM users WHERE role IN ('manager','admin','owner') ORDER BY name")->fetchAll() : [];
    jsonResponse(true, 'Клієнтів завантажено.', ['user' => $user, 'clients' => $clients, 'available_users' => $availableUsers, 'available_users_by_client' => $availableByClient, 'admin_workspaces' => $adminWorkspaces, 'permissions' => ['manage' => $canManage, 'delete' => !$isProjectLead]]);
}
if (!$canManage) jsonResponse(false, 'Створювати клієнтів можуть лише адміністратор, менеджер або керівник проєкту.', [], 403);
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
        'responsible_amount' => max(0, (float)(($service['responsible_mode'] ?? 'amount') === 'percent' ? ((float)($service['price'] ?? 0) * min(100, max(0, (float)($service['responsible_percent'] ?? 0))) / 100) : ($service['responsible_amount'] ?? 0))),
        'responsible_mode' => in_array((string)($service['responsible_mode'] ?? 'amount'), ['amount', 'percent'], true) ? (string)$service['responsible_mode'] : 'amount',
        'responsible_percent' => min(100, max(0, (float)($service['responsible_percent'] ?? 0))),
        'period' => in_array((string)($service['period'] ?? 'Місяць'), $allowedPeriods, true) ? (string)$service['period'] : 'Місяць',
        'official_due_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($service['official_due_date'] ?? '')) ? (string)$service['official_due_date'] : '',
        'accountant_id' => trim((string)($service['accountant_id'] ?? '')),
        'is_package' => !empty($service['is_package']) || trim((string)($service['package_id'] ?? '')) !== '',
        'package_id' => trim((string)($service['package_id'] ?? '')),
        'package_name' => trim((string)($service['package_name'] ?? '')),
        'package_services' => array_values(array_filter(array_map(static fn($item): string => trim((string)$item), (array)($service['package_services'] ?? [])), static fn(string $item): bool => $item !== '')),
        'package_responsible_amount' => max(0, (float)($service['package_responsible_amount'] ?? 0)),
        'internal_days_before' => min(90, max(0, (int)($service['internal_days_before'] ?? 5))),
        'parameters' => $parameters,
        'reminders_enabled' => array_key_exists('reminders_enabled', $service) ? !empty($service['reminders_enabled']) : true,
        'create_task' => !empty($service['create_task']),
        'task_id' => preg_match('/^[a-f0-9]{32}$/', (string)($service['task_id'] ?? '')) ? (string)$service['task_id'] : '',
    ];
}, (array)($data['services'] ?? [])), static fn(array $service): bool => $service['name'] !== ''));
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
    if (isset($seenServiceKeys[$serviceKey])) {
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
$requestedAccountantIds = array_values(array_unique(array_filter(array_map(static fn(array $service): string => (string)($service['accountant_id'] ?? ''), $services))));
if ($requestedAccountantIds) {
    $accountingGroupIds = [];
    foreach ($pdo->query('SELECT id, name, permissions FROM project_groups WHERE project_id IS NULL')->fetchAll() as $group) {
        if (!in_array($user['role'], ['admin', 'owner'], true) && (groupPolicy($group['permissions'] ?? null)['owner_id'] ?? '') !== $planContext['owner_id']) continue;
        $groupName = (string)$group['name'];
        $nameHex = strtoupper(bin2hex($groupName));
        if (preg_match('/(?:бухгалт|бугалт|account)/iu', $groupName)
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
    if ($planContext['workspace_role'] === 'manager' && $allowedAccountantIds) {
        $managerTeamIds = array_fill_keys(array_map('strval', $planContext['member_ids']), true);
        $allowedAccountantIds = array_filter($allowedAccountantIds, static fn($_, $id): bool => isset($managerTeamIds[(string)$id]), ARRAY_FILTER_USE_BOTH);
    }
    foreach ($requestedAccountantIds as $accountantId) {
        // Keep legacy assignments after a claim/team change, but never allow
        // a new assignment to a person outside the current team.
        if (!$isAdmin && !isset($allowedAccountantIds[$accountantId]) && !isset($existingAccountantIds[$accountantId])) jsonResponse(false, 'Відповідального бухгалтера не знайдено у вашій команді.', [], 422);
    }
}
$servicesJson = json_encode($services, JSON_UNESCAPED_UNICODE);
if ($action === 'update' && ($planContext['plan']['services_per_client'] ?? null) !== null && $addedStandalone > 0) {
    acquirePlanCapacityLock($planContext, 'services_per_client');
    $standaloneCount = count(array_filter($services, static fn(array $service): bool => $service['package_id'] === ''));
    if ($standaloneCount > (int)$planContext['plan']['services_per_client']) jsonResponse(false, 'У плані «' . $planContext['plan']['name'] . '» можна додати не більше ' . (int)$planContext['plan']['services_per_client'] . ' послуг на одного клієнта.', ['code' => 'plan_limit_reached', 'metric' => 'services_per_client', 'limit' => (int)$planContext['plan']['services_per_client'], 'current' => (int)$planContext['plan']['services_per_client'], 'plan' => $planContext['key']], 403);
}
if (empty($planContext['plan']['services'])) {
    $serviceKey = static fn(array $service): string => (string)($service['name'] ?? '') . '|' . (string)($service['package_id'] ?? '');
    $previousKeys = array_fill_keys(array_map($serviceKey, $previousServices), true);
    $addedStandalone = 0;
    foreach ($services as $service) {
        $key = $serviceKey($service);
        if (isset($previousKeys[$key])) continue;
        if ($service['package_id'] !== '') requirePlanFeature($planContext, 'services');
        $addedStandalone++;
    }
    $serviceLimit = $planContext['plan']['services_per_client'] ?? null;
    if ($serviceLimit !== null) {
        $standaloneCount = count(array_filter($services, static fn(array $service): bool => $service['package_id'] === ''));
        if ($standaloneCount > (int)$serviceLimit && $addedStandalone > 0) {
            notifyPlanCapacityReached($planContext, 'services_per_client', (int)$serviceLimit, (int)$serviceLimit);
            jsonResponse(false, 'У плані «' . $planContext['plan']['name'] . '» можна додати не більше ' . (int)$serviceLimit . ' послуг на одного клієнта.', ['code' => 'plan_limit_reached', 'metric' => 'services_per_client', 'limit' => (int)$serviceLimit, 'current' => (int)$serviceLimit, 'plan' => $planContext['key']], 403);
        }
    } elseif ($addedStandalone > 0) {
        requirePlanFeature($planContext, 'services');
    }
}
if ($action === 'update' && $id !== '') {
    $existingClientQuery = $pdo->prepare('SELECT id FROM clients WHERE id = ? LIMIT 1');
    $existingClientQuery->execute([$id]);
    if (!$existingClientQuery->fetchColumn()) jsonResponse(false, 'Клієнта не знайдено.', [], 404);
    $ownerId = null;
    if ($isAdmin && !empty($data['workspace_owner_id'])) {
        $ownerCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role IN ('manager','admin','owner') LIMIT 1");
        $ownerCheck->execute([(string)$data['workspace_owner_id']]);
        if (!$ownerCheck->fetchColumn()) jsonResponse(false, 'Оберіть наявний простір для клієнта.', [], 422);
        $ownerId = (string)$data['workspace_owner_id'];
    }
    $updateSql = 'UPDATE clients SET name = ?, legal_form = ?, tax_system = ?, has_vat = ?, has_excise = ?, has_employees = ?, employee_count = ?, special_taxes = ?, services = ?, module = ?, module_data = ?'.($ownerId !== null ? ', created_by = ?' : '').' WHERE id = ?';
    $updateValues = [$name, $legalForm, $taxSystem, $hasVat, $hasExcise, $hasEmployees, $employeeCount, json_encode($specialTaxes, JSON_UNESCAPED_UNICODE), $servicesJson, $module, $moduleData];
    if ($ownerId !== null) $updateValues[] = $ownerId;
    $updateValues[] = $id;
    $pdo->prepare($updateSql)->execute($updateValues);
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
if (!$isAdmin) requirePlanCapacity($planContext, 'clients');
$newId = bin2hex(random_bytes(16));
$pdo->prepare('INSERT INTO clients (id, name, legal_form, tax_system, has_vat, has_excise, has_employees, employee_count, special_taxes, services, module, module_data, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$newId, $name, $legalForm, $taxSystem, $hasVat, $hasExcise, $hasEmployees, $employeeCount, json_encode($specialTaxes, JSON_UNESCAPED_UNICODE), $servicesJson, $module, $moduleData, $ownerId]);
releasePlanCapacityLocks((string)$planContext['owner_id']);
    $clientPlanLimit = $planContext['plan']['clients'] ?? null;
    if ($clientPlanLimit !== null && (int)$planContext['counts']['clients'] + 1 >= (int)$clientPlanLimit) notifyPlanCapacityReached($planContext, 'clients', (int)$clientPlanLimit, (int)$clientPlanLimit);
    $clientServiceLimit = $planContext['plan']['services_per_client'] ?? null;
    $clientStandaloneServiceCount = count(array_filter($services, static fn(array $service): bool => $service['package_id'] === ''));
    if ($clientServiceLimit !== null && $clientStandaloneServiceCount >= (int)$clientServiceLimit) notifyPlanCapacityReached($planContext, 'services_per_client', (int)$clientServiceLimit, (int)$clientServiceLimit);
    jsonResponse(true, 'Клієнта створено.', ['id' => $newId]);
