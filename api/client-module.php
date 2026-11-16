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
$columns = array_column($pdo->query('SHOW COLUMNS FROM users')->fetchAll(), 'Field');
if (!in_array('active_client_module', $columns, true)) $pdo->exec("ALTER TABLE users ADD active_client_module VARCHAR(40) NOT NULL DEFAULT 'accounting'");
$allowed = ['accounting', 'dentistry', 'legal', 'real_estate'];
$ownerId = (string)$context['owner_id'];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!in_array($context['workspace_role'] ?? '', ['manager', 'admin', 'owner'], true) && !in_array($user['role'], ['admin', 'owner'], true)) jsonResponse(false, 'Змінювати модуль клієнтів може лише менеджер простору.', [], 403);
    $data = body();
    $module = (string)($data['module'] ?? '');
    if (!in_array($module, $allowed, true)) jsonResponse(false, 'Оберіть коректний модуль.', [], 422);
    $pdo->prepare('UPDATE users SET active_client_module = ? WHERE id = ?')->execute([$module, $ownerId]);
    jsonResponse(true, 'Модуль клієнтів збережено.', ['active_client_module' => $module]);
}
$query = $pdo->prepare('SELECT active_client_module FROM users WHERE id = ? LIMIT 1');
$query->execute([$ownerId]);
$module = (string)($query->fetchColumn() ?: 'accounting');
if (!in_array($module, $allowed, true)) $module = 'accounting';
jsonResponse(true, 'Модуль клієнтів завантажено.', ['active_client_module' => $module, 'can_manage' => in_array($context['workspace_role'] ?? '', ['manager', 'admin', 'owner'], true) || in_array($user['role'], ['admin', 'owner'], true)]);
