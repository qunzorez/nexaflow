<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);

$pdo = db();
$userId = (string)$_SESSION['user_id'];
$userQuery = $pdo->prepare('SELECT id, name, email, role FROM users WHERE id = ? LIMIT 1');
$userQuery->execute([$userId]);
$user = $userQuery->fetch();
if (!$user) jsonResponse(false, 'Користувача не знайдено.', [], 401);

$pdo->exec("CREATE TABLE IF NOT EXISTS service_packages (
  id CHAR(32) PRIMARY KEY, name VARCHAR(190) NOT NULL, module VARCHAR(40) NOT NULL DEFAULT 'accounting',
  description VARCHAR(500) NULL, client_price DECIMAL(12,2) NOT NULL DEFAULT 0, responsible_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_by CHAR(32) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_service_packages_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$packageColumns = array_column($pdo->query("SHOW COLUMNS FROM service_packages")->fetchAll(), 'Field');
$addedPackagePrices = false;
if (!in_array('client_price', $packageColumns, true)) { $pdo->exec("ALTER TABLE service_packages ADD client_price DECIMAL(12,2) NOT NULL DEFAULT 0"); $addedPackagePrices = true; }
if (!in_array('responsible_amount', $packageColumns, true)) { $pdo->exec("ALTER TABLE service_packages ADD responsible_amount DECIMAL(12,2) NOT NULL DEFAULT 0"); $addedPackagePrices = true; }
$pdo->exec("CREATE TABLE IF NOT EXISTS service_catalog (
  id CHAR(32) PRIMARY KEY, package_id CHAR(32) NOT NULL, name VARCHAR(190) NOT NULL,
  client_price DECIMAL(12,2) NOT NULL DEFAULT 0, responsible_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  responsible_user_id CHAR(32) NULL, active TINYINT(1) NOT NULL DEFAULT 1,
  created_by CHAR(32) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_service_catalog_package (package_id), INDEX idx_service_catalog_responsible (responsible_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
if ($addedPackagePrices) {
  $pdo->exec("UPDATE service_packages p SET client_price = COALESCE((SELECT SUM(s.client_price) FROM service_catalog s WHERE s.package_id = p.id AND s.active = 1), 0), responsible_amount = COALESCE((SELECT SUM(s.responsible_amount) FROM service_catalog s WHERE s.package_id = p.id AND s.active = 1), 0)");
}

$canManage = in_array($user['role'], ['admin', 'owner', 'manager'], true);
$isAdmin = in_array($user['role'], ['admin', 'owner'], true);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$data = $method === 'POST' ? body() : [];
$modules = ['all', 'accounting', 'dentistry', 'legal', 'real_estate'];
$requestedModule = (string)($_GET['module'] ?? $data['module'] ?? 'accounting');
$activeModule = in_array($requestedModule, ['accounting', 'dentistry', 'legal', 'real_estate'], true) ? $requestedModule : 'accounting';
$newId = static fn(): string => bin2hex(random_bytes(16));
$moduleLabel = static fn(string $module): string => [
    'all' => 'Усі модулі клієнтів', 'accounting' => 'Бухгалтерія', 'dentistry' => 'Стоматологія',
    'legal' => 'Юридичні послуги', 'real_estate' => 'Нерухомість',
][$module] ?? 'Бухгалтерія';

if ($method === 'GET') {
    if (!$canManage) jsonResponse(false, 'Сторінка послуг доступна лише менеджерам і адміністраторам.', [], 403);
    $packageQuery = $isAdmin
        ? $pdo->query('SELECT id, name, module, description, client_price, responsible_amount, created_at FROM service_packages ORDER BY name')
        : $pdo->prepare("SELECT id, name, module, description, client_price, responsible_amount, created_at FROM service_packages WHERE module IN (?, 'all') ORDER BY name");
    if (!$isAdmin) $packageQuery->execute([$activeModule]);
    $packages = $packageQuery->fetchAll();
    $items = $pdo->query('SELECT s.id, s.package_id, s.name, s.client_price, s.responsible_amount, s.responsible_user_id, s.active, u.name AS responsible_name FROM service_catalog s LEFT JOIN users u ON u.id = s.responsible_user_id WHERE s.active = 1 ORDER BY s.name')->fetchAll();
    $users = $pdo->query("SELECT id, name, email, role FROM users WHERE role IN ('admin','owner','manager','user') ORDER BY name, email")->fetchAll();
    foreach ($packages as &$package) {
        $package['module_label'] = $moduleLabel((string)$package['module']);
        $package['services'] = array_values(array_filter($items, static fn(array $item): bool => (string)$item['package_id'] === (string)$package['id']));
        $package['client_price'] = (float)$package['client_price'];
        $package['responsible_amount'] = (float)$package['responsible_amount'];
        $package['services_count'] = count($package['services']);
    }
    unset($package);
    jsonResponse(true, 'Послуги завантажено.', ['user' => $user, 'packages' => $packages, 'users' => $users, 'active_module' => $activeModule, 'is_admin' => $isAdmin, 'modules' => array_map(fn(string $value): array => ['value' => $value, 'label' => $moduleLabel($value)], $modules), 'permissions' => ['manage' => $canManage]]);
}

if (!$canManage) jsonResponse(false, 'Керувати послугами може лише менеджер або адміністратор.', [], 403);
$action = (string)($data['action'] ?? '');
$packageId = trim((string)($data['package_id'] ?? $data['id'] ?? ''));
$serviceId = trim((string)($data['service_id'] ?? $data['id'] ?? ''));

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
        $query = $pdo->prepare('UPDATE service_packages SET name = ?, module = ?, description = ?, client_price = ?, responsible_amount = ? WHERE id = ?');
        $query->execute([$name, $module, $description ?: null, $clientPrice, $responsibleAmount, $packageId]);
        jsonResponse(true, 'Пакет оновлено.');
    }
    $query = $pdo->prepare('INSERT INTO service_packages (id, name, module, description, client_price, responsible_amount, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $query->execute([$newId(), $name, $module, $description ?: null, $clientPrice, $responsibleAmount, $userId]);
    jsonResponse(true, 'Пакет створено.');
}

if ($action === 'delete_package') {
    if ($packageId === '') jsonResponse(false, 'Пакет не знайдено.', [], 422);
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
    $packageCheck = $pdo->prepare('SELECT id FROM service_packages WHERE id = ? LIMIT 1');
    $packageCheck->execute([$packageId]);
    if (!$packageCheck->fetch()) jsonResponse(false, 'Пакет не знайдено.', [], 422);
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
        $query = $pdo->prepare('UPDATE service_catalog SET package_id = ?, name = ?, client_price = ?, responsible_amount = ?, responsible_user_id = ? WHERE id = ?');
        $query->execute([$packageId, $name, $clientPrice, $responsibleAmount, $responsibleUserId, $serviceId]);
        jsonResponse(true, 'Послугу оновлено.');
    }
    $query = $pdo->prepare('INSERT INTO service_catalog (id, package_id, name, client_price, responsible_amount, responsible_user_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $query->execute([$newId(), $packageId, $name, $clientPrice, $responsibleAmount, $responsibleUserId, $userId]);
    jsonResponse(true, 'Послугу створено.');
}

if ($action === 'delete_service') {
    if ($serviceId === '') jsonResponse(false, 'Послугу не знайдено.', [], 422);
    $pdo->prepare('DELETE FROM service_catalog WHERE id = ?')->execute([$serviceId]);
    jsonResponse(true, 'Послугу видалено.');
}

jsonResponse(false, 'Невідома дія.', [], 422);
