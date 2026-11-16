<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_once __DIR__ . '/plans.php';
session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);

$pdo = db();
$userId = (string)$_SESSION['user_id'];
$userQuery = $pdo->prepare('SELECT id, name, email, role FROM users WHERE id = ? LIMIT 1');
$userQuery->execute([$userId]);
$user = $userQuery->fetch();
if (!$user) jsonResponse(false, 'Користувача не знайдено.', [], 401);
$planContext = workspacePlan($pdo, $userId, (string)$user['role']);
$userColumns = array_column($pdo->query('SHOW COLUMNS FROM users')->fetchAll(), 'Field');
if (!in_array('active_client_module', $userColumns, true)) $pdo->exec("ALTER TABLE users ADD active_client_module VARCHAR(40) NOT NULL DEFAULT 'accounting'");
$moduleQuery = $pdo->prepare('SELECT active_client_module FROM users WHERE id = ?'); $moduleQuery->execute([(string)$planContext['owner_id']]);
$workspaceClientModule = (string)($moduleQuery->fetchColumn() ?: 'accounting');
$user['subscription'] = planSettingsPayload($planContext); $user['workspaces'] = $planContext['workspaces']; $user['active_workspace_id'] = $planContext['owner_id']; $user['workspace_role'] = $planContext['workspace_role'];

$pdo->exec("CREATE TABLE IF NOT EXISTS service_packages (
  id CHAR(32) PRIMARY KEY, name VARCHAR(190) NOT NULL, module VARCHAR(40) NOT NULL DEFAULT 'accounting',
  description VARCHAR(500) NULL, client_price DECIMAL(12,2) NOT NULL DEFAULT 0, responsible_amount DECIMAL(12,2) NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1,
  calendar_enabled TINYINT(1) NOT NULL DEFAULT 0,
  created_by CHAR(32) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_service_packages_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$packageColumns = array_column($pdo->query("SHOW COLUMNS FROM service_packages")->fetchAll(), 'Field');
$addedPackagePrices = false;
if (!in_array('client_price', $packageColumns, true)) { $pdo->exec("ALTER TABLE service_packages ADD client_price DECIMAL(12,2) NOT NULL DEFAULT 0"); $addedPackagePrices = true; }
if (!in_array('responsible_amount', $packageColumns, true)) { $pdo->exec("ALTER TABLE service_packages ADD responsible_amount DECIMAL(12,2) NOT NULL DEFAULT 0"); $addedPackagePrices = true; }
if (!in_array('active', $packageColumns, true)) $pdo->exec("ALTER TABLE service_packages ADD active TINYINT(1) NOT NULL DEFAULT 1");
if (!in_array('calendar_enabled', $packageColumns, true)) $pdo->exec("ALTER TABLE service_packages ADD calendar_enabled TINYINT(1) NOT NULL DEFAULT 0");
$pdo->exec("CREATE TABLE IF NOT EXISTS service_catalog (
  id CHAR(32) PRIMARY KEY, package_id CHAR(32) NOT NULL, name VARCHAR(190) NOT NULL,
  client_price DECIMAL(12,2) NOT NULL DEFAULT 0, responsible_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  responsible_user_id CHAR(32) NULL, active TINYINT(1) NOT NULL DEFAULT 1,
  created_by CHAR(32) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_service_catalog_package (package_id), INDEX idx_service_catalog_responsible (responsible_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$catalogColumns = array_column($pdo->query('SHOW COLUMNS FROM service_catalog')->fetchAll(), 'Field');
if (!in_array('module', $catalogColumns, true)) $pdo->exec("ALTER TABLE service_catalog ADD module VARCHAR(40) NOT NULL DEFAULT 'accounting'");
if (in_array('package_id', $catalogColumns, true)) $pdo->exec('ALTER TABLE service_catalog MODIFY package_id CHAR(32) NULL');
if ($addedPackagePrices) {
  $pdo->exec("UPDATE service_packages p SET client_price = COALESCE((SELECT SUM(s.client_price) FROM service_catalog s WHERE s.package_id = p.id AND s.active = 1), 0), responsible_amount = COALESCE((SELECT SUM(s.responsible_amount) FROM service_catalog s WHERE s.package_id = p.id AND s.active = 1), 0)");
}

$canManage = in_array($planContext['workspace_role'], ['admin', 'owner', 'manager', 'project_lead'], true);
$canViewWorkspaceCatalog = in_array($planContext['workspace_role'] ?? '', ['user', 'project_lead'], true);
$isAdmin = in_array($user['role'], ['admin', 'owner'], true);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$data = $method === 'POST' ? body() : [];
$modules = ['all', 'accounting', 'dentistry', 'legal', 'real_estate'];
$requestedModule = (string)($_GET['module'] ?? ($method === 'POST' ? ($data['module'] ?? $workspaceClientModule) : $workspaceClientModule));
$activeModule = in_array($requestedModule, ['accounting', 'dentistry', 'legal', 'real_estate'], true) ? $requestedModule : $workspaceClientModule;
$newId = static fn(): string => bin2hex(random_bytes(16));
$moduleLabel = static fn(string $module): string => [
    'all' => 'Усі модулі клієнтів', 'accounting' => 'Бухгалтерія', 'dentistry' => 'Стоматологія',
    'legal' => 'Юридичні послуги', 'real_estate' => 'Нерухомість',
][$module] ?? 'Бухгалтерія';

if ($method === 'GET') {
    if (!$canManage && !$canViewWorkspaceCatalog) jsonResponse(false, 'Каталог послуг недоступний у цьому просторі.', [], 403);
    // Workspace members need the read-only catalog for client cards and dental
    // treatment plans. Keep the subscription gate on management pages/actions.
    if (!$isAdmin && !in_array($planContext['workspace_role'] ?? '', ['user', 'project_lead'], true)) requirePlanFeature($planContext, 'services');
    $globalScope = $isAdmin && ($_GET['scope'] ?? '') === 'all';
    if ($globalScope) {
        $moduleFilter = (string)($_GET['module'] ?? 'all');
        $ownerFilter = trim((string)($_GET['owner_id'] ?? ''));
        if ($ownerFilter !== '') {
            $ownerCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role IN ('manager','admin','owner') LIMIT 1");
            $ownerCheck->execute([$ownerFilter]);
            if (!$ownerCheck->fetchColumn()) jsonResponse(false, 'Простір не знайдено.', [], 404);
        }
        $sql = "SELECT p.id, p.name, p.module, p.description, p.client_price, p.responsible_amount, p.calendar_enabled, p.active, p.created_at, p.created_by AS owner_id, u.name AS owner_name, u.email AS owner_email FROM service_packages p LEFT JOIN users u ON u.id = p.created_by";
        if ($ownerFilter !== '' && in_array($moduleFilter, ['accounting', 'dentistry', 'legal', 'real_estate'], true)) {
            $packageQuery = $pdo->prepare($sql . " WHERE p.created_by = ? AND p.module IN (?, 'all') ORDER BY p.client_price, p.name");
            $packageQuery->execute([$ownerFilter, $moduleFilter]);
        } elseif ($ownerFilter !== '') {
            $packageQuery = $pdo->prepare($sql . ' WHERE p.created_by = ? ORDER BY p.client_price, p.name');
            $packageQuery->execute([$ownerFilter]);
        } elseif (in_array($moduleFilter, ['accounting', 'dentistry', 'legal', 'real_estate'], true)) {
            $packageQuery = $pdo->prepare($sql . " WHERE p.module IN (?, 'all') ORDER BY p.client_price, p.name");
            $packageQuery->execute([$moduleFilter]);
        } else {
            $packageQuery = $pdo->query($sql . ' ORDER BY p.client_price, p.name');
        }
    } elseif (($_GET['scope'] ?? '') === 'all' && $requestedModule === 'all') {
        // Managers can browse every module in their own workspace, never other workspaces.
        $packageQuery = $pdo->prepare("SELECT p.id, p.name, p.module, p.description, p.client_price, p.responsible_amount, p.calendar_enabled, p.active, p.created_at, p.created_by AS owner_id, u.name AS owner_name, u.email AS owner_email FROM service_packages p LEFT JOIN users u ON u.id = p.created_by WHERE p.created_by = ? ORDER BY p.module, p.name");
        $packageQuery->execute([$planContext['owner_id']]);
    } else {
        $moduleForWorkspace = in_array($requestedModule, ['accounting', 'dentistry', 'legal', 'real_estate'], true) ? $requestedModule : $activeModule;
        $packageQuery = $pdo->prepare("SELECT p.id, p.name, p.module, p.description, p.client_price, p.responsible_amount, p.calendar_enabled, p.active, p.created_at, p.created_by AS owner_id, u.name AS owner_name, u.email AS owner_email FROM service_packages p LEFT JOIN users u ON u.id = p.created_by WHERE p.created_by = ? AND p.module IN (?, 'all') ORDER BY p.name");
        $packageQuery->execute([$planContext['owner_id'], $moduleForWorkspace]);
    }
    $packages = $packageQuery->fetchAll();
    $standaloneSql = "SELECT s.id, s.name, s.module, s.client_price, s.responsible_amount, s.active, s.created_by AS owner_id, u.name AS owner_name, u.email AS owner_email FROM service_catalog s LEFT JOIN users u ON u.id = s.created_by WHERE s.package_id IS NULL AND s.active = 1";
    if ($globalScope && !empty($ownerFilter)) {
        $standaloneQuery = $pdo->prepare($standaloneSql . ' AND s.created_by = ? AND s.module = ? ORDER BY s.name');
        $standaloneQuery->execute([$ownerFilter, in_array($moduleFilter, ['accounting', 'dentistry', 'legal', 'real_estate'], true) ? $moduleFilter : $activeModule]);
    } elseif ($globalScope && in_array($moduleFilter, ['accounting', 'dentistry', 'legal', 'real_estate'], true)) {
        $standaloneQuery = $pdo->prepare($standaloneSql . ' AND s.module = ? ORDER BY s.name');
        $standaloneQuery->execute([$moduleFilter]);
    } elseif ($globalScope) {
        $standaloneQuery = $pdo->query($standaloneSql . ' ORDER BY s.name');
    } elseif (($_GET['scope'] ?? '') === 'all' && $requestedModule === 'all') {
        $standaloneQuery = $pdo->prepare($standaloneSql . ' AND s.created_by = ? ORDER BY s.module, s.name');
        $standaloneQuery->execute([$planContext['owner_id']]);
    } else {
        $moduleForWorkspace = in_array($requestedModule, ['accounting', 'dentistry', 'legal', 'real_estate'], true) ? $requestedModule : $activeModule;
        $standaloneQuery = $pdo->prepare($standaloneSql . ' AND s.created_by = ? AND s.module = ? ORDER BY s.name');
        $standaloneQuery->execute([$planContext['owner_id'], $moduleForWorkspace]);
    }
    $standaloneServices = $standaloneQuery->fetchAll();
    $packageIds = array_column($packages, 'id');
    $items = [];
    if ($packageIds) {
        $packageMarks = implode(',', array_fill(0, count($packageIds), '?'));
        $itemsQuery = $pdo->prepare("SELECT s.id, s.package_id, s.name, s.client_price, s.responsible_amount, s.responsible_user_id, s.active, u.name AS responsible_name FROM service_catalog s LEFT JOIN users u ON u.id = s.responsible_user_id WHERE s.active = 1 AND s.package_id IN ($packageMarks) ORDER BY s.name");
        $itemsQuery->execute($packageIds);
        $items = $itemsQuery->fetchAll();
    }
    if ($globalScope && !empty($ownerFilter)) {
        $membersQuery = $pdo->prepare("SELECT DISTINCT u.id FROM users u WHERE u.id = ? OR EXISTS (SELECT 1 FROM user_invitations i WHERE i.invited_by = ? AND LOWER(i.email) = LOWER(u.email) AND i.status = 'accepted') OR EXISTS (SELECT 1 FROM project_invitations i JOIN projects p ON p.id = i.project_id WHERE i.invited_by = ? AND p.owner_id = ? AND LOWER(i.email) = LOWER(u.email) AND i.status = 'accepted')");
        $membersQuery->execute([$ownerFilter, $ownerFilter, $ownerFilter, $ownerFilter]);
        $memberIds = array_values(array_unique(array_map('strval', $membersQuery->fetchAll(PDO::FETCH_COLUMN))));
        if ($memberIds) {
            $memberMarks = implode(',', array_fill(0, count($memberIds), '?'));
            $usersQuery = $pdo->prepare("SELECT id, name, email, role FROM users WHERE id IN ({$memberMarks}) ORDER BY name, email");
            $usersQuery->execute($memberIds);
        } else {
            $usersQuery = $pdo->query("SELECT id, name, email, role FROM users WHERE 1 = 0");
        }
    } elseif ($globalScope) {
        $usersQuery = $pdo->query("SELECT id, name, email, role FROM users WHERE role IN ('admin','owner','manager','user') ORDER BY name, email");
    } else {
        $memberMarks = implode(',', array_fill(0, count($planContext['member_ids']), '?'));
        $usersQuery = $pdo->prepare("SELECT id, name, email, role FROM users WHERE role IN ('admin','owner','manager','user') AND id IN ({$memberMarks}) ORDER BY name, email");
        $usersQuery->execute($planContext['member_ids']);
    }
    $adminWorkspaces = $isAdmin ? $pdo->query("SELECT id, name, email, role FROM users WHERE role IN ('manager','admin','owner') ORDER BY name")->fetchAll() : [];
    $users = $usersQuery->fetchAll();
    if (($planContext['workspace_role'] ?? '') === 'project_lead') {
        $invitedIdsQuery = $pdo->prepare("SELECT DISTINCT u.id FROM users u WHERE EXISTS (SELECT 1 FROM user_invitations i WHERE i.email = u.email AND i.invited_by = ? AND i.status = 'accepted') OR EXISTS (SELECT 1 FROM project_invitations i WHERE i.email = u.email AND i.invited_by = ? AND i.status = 'accepted')");
        $invitedIdsQuery->execute([$userId, $userId]);
        $invitedIds = array_fill_keys(array_map('strval', $invitedIdsQuery->fetchAll(PDO::FETCH_COLUMN)), true);
        $users = array_values(array_filter($users, static fn(array $person): bool => isset($invitedIds[(string)$person['id']])));
    }
    foreach ($packages as &$package) {
        $package['module_label'] = $moduleLabel((string)$package['module']);
        $package['services'] = array_values(array_filter($items, static fn(array $item): bool => (string)$item['package_id'] === (string)$package['id']));
        $package['client_price'] = (float)$package['client_price'];
        $package['responsible_amount'] = (float)$package['responsible_amount'];
        $package['active'] = (bool)$package['active'];
        $package['calendar_enabled'] = (bool)$package['calendar_enabled'];
        $package['services_count'] = count($package['services']);
    }
    unset($package);
    foreach ($standaloneServices as &$standalone) { $standalone['client_price'] = (float)$standalone['client_price']; $standalone['responsible_amount'] = (float)$standalone['responsible_amount']; $standalone['active'] = (bool)$standalone['active']; $standalone['module_label'] = $moduleLabel((string)$standalone['module']); }
    unset($standalone);
    jsonResponse(true, 'Послуги завантажено.', ['user' => $user, 'packages' => $packages, 'standalone_services' => $standaloneServices, 'users' => $users, 'admin_workspaces' => $adminWorkspaces, 'active_module' => $workspaceClientModule, 'module_filter' => $activeModule, 'global_scope' => $globalScope, 'is_admin' => $isAdmin, 'modules' => array_map(fn(string $value): array => ['value' => $value, 'label' => $moduleLabel($value)], $modules), 'permissions' => ['manage' => $canManage]]);
}

if (!$canManage) jsonResponse(false, 'Керувати послугами може лише керівник простору.', [], 403);
if (!$isAdmin) requirePlanFeature($planContext, 'services');
$action = (string)($data['action'] ?? '');
$packageId = trim((string)($data['package_id'] ?? $data['id'] ?? ''));
$serviceId = trim((string)($data['service_id'] ?? $data['id'] ?? ''));

if (in_array($action, ['create_standalone_service', 'update_standalone_service'], true)) {
    $name = trim((string)($data['name'] ?? ''));
    $module = in_array((string)($data['module'] ?? ''), ['accounting', 'dentistry', 'legal', 'real_estate'], true) ? (string)$data['module'] : $activeModule;
    if (!$isAdmin) $module = $activeModule;
    $clientPrice = max(0, round((float)($data['client_price'] ?? 0), 2));
    $responsibleAmount = max(0, round((float)($data['responsible_amount'] ?? 0), 2));
    if ($name === '') jsonResponse(false, 'Вкажіть назву послуги.', [], 422);
    if ($action === 'update_standalone_service') {
        $owned = $pdo->prepare('SELECT id, created_by FROM service_catalog WHERE id = ? AND package_id IS NULL' . ($isAdmin ? '' : ' AND created_by = ?') . ' LIMIT 1');
        $values = [$serviceId]; if (!$isAdmin) $values[] = $planContext['owner_id']; $owned->execute($values); $existing = $owned->fetch();
        if (!$existing) jsonResponse(false, 'Послугу не знайдено або її створив інший простір.', [], 404);
        $ownerId = (string)$existing['created_by'];
        if ($isAdmin && !empty($data['owner_id'])) $ownerId = (string)$data['owner_id'];
        if ($isAdmin) { $ownerCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role IN ('manager','admin','owner') LIMIT 1"); $ownerCheck->execute([$ownerId]); if (!$ownerCheck->fetchColumn()) jsonResponse(false, 'Оберіть наявний простір для послуги.', [], 422); }
        $duplicate = $pdo->prepare('SELECT id FROM service_catalog WHERE package_id IS NULL AND created_by = ? AND module = ? AND LOWER(name) = LOWER(?) AND active = 1 AND id <> ? LIMIT 1');
        $duplicate->execute([$ownerId, $module, $name, $serviceId]);
        if ($duplicate->fetchColumn()) jsonResponse(false, 'Така послуга вже є у каталозі цього простору.', [], 422);
        $pdo->prepare('UPDATE service_catalog SET name = ?, module = ?, client_price = ?, responsible_amount = ?, created_by = ? WHERE id = ?')->execute([$name, $module, $clientPrice, $responsibleAmount, $ownerId, $serviceId]);
        jsonResponse(true, 'Послугу оновлено.');
    }
    $ownerId = (string)$planContext['owner_id'];
    if ($isAdmin && !empty($data['owner_id'])) $ownerId = (string)$data['owner_id'];
    if ($isAdmin) { $ownerCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role IN ('manager','admin','owner') LIMIT 1"); $ownerCheck->execute([$ownerId]); if (!$ownerCheck->fetchColumn()) jsonResponse(false, 'Оберіть наявний простір для послуги.', [], 422); }
    $duplicate = $pdo->prepare('SELECT id FROM service_catalog WHERE package_id IS NULL AND created_by = ? AND module = ? AND LOWER(name) = LOWER(?) AND active = 1 LIMIT 1');
    $duplicate->execute([$ownerId, $module, $name]);
    if ($duplicate->fetchColumn()) jsonResponse(false, 'Така послуга вже є у вашому каталозі.', [], 422);
    $pdo->prepare('INSERT INTO service_catalog (id, package_id, name, module, client_price, responsible_amount, created_by) VALUES (?, NULL, ?, ?, ?, ?, ?)')->execute([$newId(), $name, $module, $clientPrice, $responsibleAmount, $ownerId]);
    jsonResponse(true, 'Послугу створено.');
}
if ($action === 'delete_standalone_service') {
    $delete = $pdo->prepare('DELETE FROM service_catalog WHERE id = ? AND package_id IS NULL' . ($isAdmin ? '' : ' AND created_by = ?'));
    $values = [$serviceId]; if (!$isAdmin) $values[] = $planContext['owner_id']; $delete->execute($values);
    if (!$delete->rowCount()) jsonResponse(false, 'Послугу не знайдено або її створив інший простір.', [], 404);
    jsonResponse(true, 'Послугу видалено.');
}

if (in_array($action, ['create_package', 'update_package'], true)) {
    $name = trim((string)($data['name'] ?? ''));
    $module = in_array((string)($data['module'] ?? ''), $modules, true) ? (string)$data['module'] : 'accounting';
    if (!$isAdmin) $module = $activeModule;
    $description = trim((string)($data['description'] ?? ''));
    $clientPrice = max(0, round((float)($data['client_price'] ?? 0), 2));
    $responsibleAmount = max(0, round((float)($data['responsible_amount'] ?? 0), 2));
    if ($name === '') jsonResponse(false, 'Вкажіть назву пакета.', [], 422);
    if ($action === 'update_package') {
        if ($packageId === '') jsonResponse(false, 'Пакет не знайдено.', [], 422);
        $ownedPackage = $pdo->prepare('SELECT id, created_by FROM service_packages WHERE id = ?' . ($isAdmin ? '' : ' AND created_by = ?') . ' LIMIT 1');
        $ownedValues = [$packageId];
        if (!$isAdmin) $ownedValues[] = $planContext['owner_id'];
        $ownedPackage->execute($ownedValues);
        $existingPackage = $ownedPackage->fetch();
        if (!$existingPackage) jsonResponse(false, 'Пакет не знайдено або його створив інший користувач.', [], 404);
        $ownerId = (string)$existingPackage['created_by'];
        if ($isAdmin && !empty($data['owner_id'])) {
            $ownerCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role IN ('manager','admin','owner') LIMIT 1"); $ownerCheck->execute([(string)$data['owner_id']]);
            if (!$ownerCheck->fetchColumn()) jsonResponse(false, 'Оберіть наявний простір для пакета.', [], 422);
            $ownerId = (string)$data['owner_id'];
        }
        $calendarEnabled = !empty($data['calendar_enabled']) ? 1 : 0;
        $query = $pdo->prepare('UPDATE service_packages SET name = ?, module = ?, description = ?, client_price = ?, responsible_amount = ?, calendar_enabled = ?, created_by = ? WHERE id = ?' . ($isAdmin ? '' : ' AND created_by = ?'));
        $updateValues = [$name, $module, $description ?: null, $clientPrice, $responsibleAmount, $calendarEnabled, $ownerId, $packageId];
        if (!$isAdmin) $updateValues[] = $planContext['owner_id'];
        $query->execute($updateValues);
        if ($isAdmin && $ownerId !== (string)$existingPackage['created_by']) {
            $pdo->prepare('UPDATE service_catalog SET created_by = ? WHERE package_id = ?')->execute([$ownerId, $packageId]);
        }
        jsonResponse(true, 'Пакет оновлено.');
    }
    $ownerId = (string)$planContext['owner_id'];
    if ($isAdmin && !empty($data['owner_id'])) {
        $ownerCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role IN ('manager','admin','owner') LIMIT 1"); $ownerCheck->execute([(string)$data['owner_id']]);
        if (!$ownerCheck->fetchColumn()) jsonResponse(false, 'Оберіть наявний простір для пакета.', [], 422);
        $ownerId = (string)$data['owner_id'];
    }
    $calendarEnabled = !empty($data['calendar_enabled']) ? 1 : 0;
    $query = $pdo->prepare('INSERT INTO service_packages (id, name, module, description, client_price, responsible_amount, calendar_enabled, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $query->execute([$newId(), $name, $module, $description ?: null, $clientPrice, $responsibleAmount, $calendarEnabled, $ownerId]);
    jsonResponse(true, 'Пакет створено.');
}

if ($action === 'toggle_package') {
    if ($packageId === '') jsonResponse(false, 'Пакет не знайдено.', [], 422);
    $ownedPackage = $pdo->prepare('SELECT id FROM service_packages WHERE id = ?' . ($isAdmin ? '' : ' AND created_by = ?') . ' LIMIT 1');
    $ownedValues = [$packageId];
    if (!$isAdmin) $ownedValues[] = $planContext['owner_id'];
    $ownedPackage->execute($ownedValues);
    if (!$ownedPackage->fetchColumn()) jsonResponse(false, 'Пакет не знайдено або його створив інший користувач.', [], 404);
    $active = !empty($data['active']) ? 1 : 0;
    $query = $pdo->prepare('UPDATE service_packages SET active = ? WHERE id = ?' . ($isAdmin ? '' : ' AND created_by = ?'));
    $values = [$active, $packageId];
    if (!$isAdmin) $values[] = $planContext['owner_id'];
    $query->execute($values);
    jsonResponse(true, $active ? 'Пакет активовано.' : 'Пакет деактивовано.');
}

if ($action === 'delete_package') {
    if ($packageId === '') jsonResponse(false, 'Пакет не знайдено.', [], 422);
    $ownedPackage = $pdo->prepare('SELECT id FROM service_packages WHERE id = ?' . ($isAdmin ? '' : ' AND created_by = ?') . ' LIMIT 1');
    $ownedValues = [$packageId];
    if (!$isAdmin) $ownedValues[] = $planContext['owner_id'];
    $ownedPackage->execute($ownedValues);
    if (!$ownedPackage->fetchColumn()) jsonResponse(false, 'Пакет не знайдено або його створив інший користувач.', [], 404);
    $pdo->prepare('DELETE FROM service_catalog WHERE package_id = ?')->execute([$packageId]);
    $pdo->prepare('DELETE FROM service_packages WHERE id = ?')->execute([$packageId]);
    jsonResponse(true, 'Пакет і його послуги видалено.');
}

if (in_array($action, ['create_service', 'update_service'], true)) {
    $name = trim((string)($data['name'] ?? ''));
    $packageId = trim((string)($data['package_id'] ?? ''));
    $clientPrice = max(0, round((float)($data['client_price'] ?? 0), 2));
    $responsibleAmount = max(0, round((float)($data['responsible_amount'] ?? 0), 2));
    $responsibleUserId = null;
    if ($name === '' || $packageId === '') jsonResponse(false, 'Вкажіть назву послуги та пакет.', [], 422);
    $packageCheck = $pdo->prepare('SELECT id, created_by FROM service_packages WHERE id = ?' . ($isAdmin ? '' : ' AND created_by = ?') . ' LIMIT 1');
    $packageValues = [$packageId];
    if (!$isAdmin) $packageValues[] = $planContext['owner_id'];
    $packageCheck->execute($packageValues);
    $packageRow = $packageCheck->fetch();
    if (!$packageRow) jsonResponse(false, 'Пакет не знайдено.', [], 422);
    $duplicateCheck = $pdo->prepare('SELECT id FROM service_catalog WHERE package_id = ? AND name = ? AND active = 1 LIMIT 1');
    $duplicateCheck->execute([$packageId, $name]);
    if ($duplicateCheck->fetch()) jsonResponse(false, 'Ця послуга вже є в пакеті.', [], 422);
    if ($responsibleUserId !== null) {
        $userCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role IN ('admin','owner','manager','user') LIMIT 1");
        $userCheck->execute([$responsibleUserId]);
        if (!$userCheck->fetch()) jsonResponse(false, 'Відповідального користувача не знайдено.', [], 422);
    }
    if ($action === 'update_service') {
        if ($serviceId === '') jsonResponse(false, 'Послугу не знайдено.', [], 422);
        $serviceOwned = $pdo->prepare('SELECT s.id FROM service_catalog s JOIN service_packages p ON p.id = s.package_id WHERE s.id = ? AND s.package_id = ?' . ($isAdmin ? '' : ' AND p.created_by = ?') . ' LIMIT 1');
        $serviceValues = [$serviceId, $packageId];
        if (!$isAdmin) $serviceValues[] = $planContext['owner_id'];
        $serviceOwned->execute($serviceValues);
        if (!$serviceOwned->fetchColumn()) jsonResponse(false, 'Послугу не знайдено в цьому пакеті.', [], 404);
        $query = $pdo->prepare('UPDATE service_catalog SET package_id = ?, name = ?, client_price = ?, responsible_amount = ?, responsible_user_id = ?, created_by = ? WHERE id = ?');
        $query->execute([$packageId, $name, $clientPrice, $responsibleAmount, $responsibleUserId, (string)$packageRow['created_by'], $serviceId]);
        jsonResponse(true, 'Послугу оновлено.');
    }
    $query = $pdo->prepare('INSERT INTO service_catalog (id, package_id, name, client_price, responsible_amount, responsible_user_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $query->execute([$newId(), $packageId, $name, $clientPrice, $responsibleAmount, $responsibleUserId, (string)$packageRow['created_by']]);
    jsonResponse(true, 'Послугу створено.');
}

if ($action === 'delete_service') {
    if ($serviceId === '') jsonResponse(false, 'Послугу не знайдено.', [], 422);
    $delete = $pdo->prepare('DELETE s FROM service_catalog s JOIN service_packages p ON p.id = s.package_id WHERE s.id = ?' . ($isAdmin ? '' : ' AND p.created_by = ?'));
    $deleteValues = [$serviceId];
    if (!$isAdmin) $deleteValues[] = $planContext['owner_id'];
    $delete->execute($deleteValues);
    if ($delete->rowCount() === 0) jsonResponse(false, 'Послугу не знайдено або її пакет створив інший користувач.', [], 404);
    jsonResponse(true, 'Послугу видалено.');
}

jsonResponse(false, 'Невідома дія.', [], 422);
