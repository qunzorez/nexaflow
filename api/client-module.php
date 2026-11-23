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
$activeModule = (string)($context['module'] ?? 'accounting');
if (!systemModuleEnabled($pdo, $activeModule)) jsonResponse(false, 'Цей модуль тимчасово вимкнений адміністратором.', ['code' => 'module_disabled', 'module' => $activeModule], 403);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    jsonResponse(false, 'Модуль змінюється лише разом із підключенням відповідного тарифу в налаштуваннях підписки.', ['code' => 'select_plan_to_change_module', 'active_client_module' => $context['module'] ?? 'accounting'], 409);
}
jsonResponse(true, 'Активний модуль визначається тарифом робочого простору.', ['active_client_module' => $activeModule, 'can_manage' => in_array($context['workspace_role'] ?? '', ['manager', 'admin', 'owner'], true) || in_array($user['role'], ['admin', 'owner'], true)]);
