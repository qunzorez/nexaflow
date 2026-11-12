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
$pdo->exec("CREATE TABLE IF NOT EXISTS clients (id CHAR(32) PRIMARY KEY, name VARCHAR(190) NOT NULL, legal_form VARCHAR(40) NOT NULL, tax_system VARCHAR(80) NOT NULL, has_vat TINYINT(1) NOT NULL DEFAULT 0, has_excise TINYINT(1) NOT NULL DEFAULT 0, has_employees TINYINT(1) NOT NULL DEFAULT 0, employee_count INT NULL, special_taxes TEXT NULL, created_by CHAR(32) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_clients_created_by (created_by)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$data = $method === 'POST' ? body() : [];
$canManage = in_array($user['role'], ['admin', 'owner', 'manager'], true);
if ($method === 'GET') {
    $clients = $pdo->query('SELECT c.*, u.name AS creator_name FROM clients c JOIN users u ON u.id = c.created_by ORDER BY c.name')->fetchAll();
    foreach ($clients as &$client) $client['special_taxes'] = $client['special_taxes'] ? (json_decode($client['special_taxes'], true) ?: []) : [];
    unset($client);
    jsonResponse(true, 'Клієнтів завантажено.', ['user' => $user, 'clients' => $clients, 'permissions' => ['manage' => $canManage]]);
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
if ($action === 'update' && $id !== '') {
    $pdo->prepare('UPDATE clients SET name = ?, legal_form = ?, tax_system = ?, has_vat = ?, has_excise = ?, has_employees = ?, employee_count = ?, special_taxes = ? WHERE id = ?')->execute([$name, $legalForm, $taxSystem, $hasVat, $hasExcise, $hasEmployees, $employeeCount, json_encode($specialTaxes, JSON_UNESCAPED_UNICODE), $id]);
    jsonResponse(true, 'Дані клієнта оновлено.');
}
$newId = bin2hex(random_bytes(16));
$pdo->prepare('INSERT INTO clients (id, name, legal_form, tax_system, has_vat, has_excise, has_employees, employee_count, special_taxes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$newId, $name, $legalForm, $taxSystem, $hasVat, $hasExcise, $hasEmployees, $employeeCount, json_encode($specialTaxes, JSON_UNESCAPED_UNICODE), $userId]);
jsonResponse(true, 'Клієнта створено.', ['id' => $newId]);
