<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);
$pdo = db();
$userQuery = $pdo->prepare('SELECT id, name, email, role FROM users WHERE id = ? LIMIT 1');
$userQuery->execute([$_SESSION['user_id']]);
$user = $userQuery->fetch();
if (!$user) jsonResponse(false, 'Користувача не знайдено.', [], 401);
try {
    $canManage = in_array($user['role'], ['admin', 'owner', 'manager'], true);
    if ($canManage) {
        $projects = $pdo->query('SELECT p.id, p.name, p.description, p.owner_id, p.created_at, u.name AS owner_name FROM projects p JOIN users u ON u.id = p.owner_id ORDER BY p.created_at DESC')->fetchAll();
    } else {
        $query = $pdo->prepare('SELECT p.id, p.name, p.description, p.owner_id, p.created_at, u.name AS owner_name, pm.role AS project_role FROM projects p JOIN users u ON u.id = p.owner_id JOIN project_members pm ON pm.project_id = p.id WHERE pm.user_id = ? OR p.owner_id = ? ORDER BY p.created_at DESC');
        $query->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
        $projects = $query->fetchAll();
    }
    $users = $canManage ? $pdo->query('SELECT id, name, email, role FROM users ORDER BY name')->fetchAll() : [];
    jsonResponse(true, 'Dashboard завантажено.', ['user' => $user, 'users' => $users, 'projects' => $projects, 'metrics' => ['projects' => count($projects), 'clients' => 0, 'tasks' => 0, 'hours' => 0], 'permissions' => ['manage' => $canManage, 'can_create_project' => $canManage]]);
} catch (Throwable $error) {
    error_log($error->getMessage());
    jsonResponse(false, 'Не вдалося завантажити Dashboard.', [], 500);
}
