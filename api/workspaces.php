<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_once __DIR__ . '/plans.php';
session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);
$pdo = db();
$q = $pdo->prepare('SELECT id, role FROM users WHERE id = ? LIMIT 1');
$q->execute([$_SESSION['user_id']]);
$user = $q->fetch();
if (!$user) jsonResponse(false, 'Користувача не знайдено.', [], 401);
$workspaces = availableWorkspaces($pdo, (string)$user['id'], (string)$user['role']);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $data = body();
    $target = trim((string)($data['workspace_id'] ?? ''));
    if (!in_array($target, array_column($workspaces, 'id'), true)) jsonResponse(false, 'Цей робочий простір вам недоступний.', [], 403);
    $_SESSION['active_workspace_id'] = $target;
}
$active = activeWorkspaceId($pdo, (string)$user['id'], (string)$user['role']);
$context = workspacePlan($pdo, (string)$user['id'], (string)$user['role']);
jsonResponse(true, 'Робочий простір завантажено.', ['workspaces' => $workspaces, 'active_workspace_id' => $active, 'subscription' => planSettingsPayload($context)]);
