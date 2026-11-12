<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/mailer.php';

session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);

$pdo = db();
function projectInviteUrl(string $token): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'nexaflow.top';
    return $scheme . '://' . $host . '/accept-invite?token=' . urlencode($token);
}
$current = $pdo->prepare('SELECT id, role FROM users WHERE id = ? LIMIT 1');
$current->execute([$_SESSION['user_id']]);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$data = $method === 'POST' ? body() : [];
$action = (string)($data['action'] ?? '');
$currentUser = $current->fetch();
$canManage = $currentUser && in_array($currentUser['role'], ['admin', 'owner', 'manager'], true);
$isAdmin = $currentUser && in_array($currentUser['role'], ['admin', 'owner'], true);
if (!$currentUser || !$canManage) {
    jsonResponse(false, 'Недостатньо прав для керування командою.', [], 403);
}

try {
    if ($method === 'GET') {
        if ($isAdmin) {
            $users = $pdo->query('SELECT id, name, email, role, created_at FROM users ORDER BY name')->fetchAll();
        } else {
            $usersQuery = $pdo->prepare("SELECT DISTINCT u.id, u.name, u.email, u.role, u.created_at FROM users u JOIN project_invitations pi ON pi.email = u.email WHERE pi.invited_by = ? AND pi.status = 'accepted' ORDER BY u.name");
            $usersQuery->execute([$_SESSION['user_id']]);
            $users = $usersQuery->fetchAll();
        }
        if ($isAdmin) {
            $projects = $pdo->query('SELECT id, name FROM projects ORDER BY name')->fetchAll();
            $members = $pdo->query('SELECT pm.project_id, pm.user_id, pm.role, p.name AS project_name, u.name AS user_name, u.email AS user_email FROM project_members pm JOIN projects p ON p.id = pm.project_id JOIN users u ON u.id = pm.user_id ORDER BY p.name, u.name')->fetchAll();
        } else {
            $projectsQuery = $pdo->prepare("SELECT DISTINCT p.id, p.name FROM projects p JOIN project_members pm ON pm.project_id = p.id WHERE pm.user_id = ? AND pm.role IN ('admin', 'manager') ORDER BY p.name");
            $projectsQuery->execute([$_SESSION['user_id']]);
            $projects = $projectsQuery->fetchAll();
            $membersQuery = $pdo->prepare("SELECT pm.project_id, pm.user_id, pm.role, p.name AS project_name, u.name AS user_name, u.email AS user_email FROM project_members pm JOIN projects p ON p.id = pm.project_id JOIN users u ON u.id = pm.user_id JOIN project_invitations pi ON pi.project_id = pm.project_id AND pi.email = u.email WHERE pi.invited_by = ? AND pi.status = 'accepted' ORDER BY p.name, u.name");
            $membersQuery->execute([$_SESSION['user_id']]);
            $members = $membersQuery->fetchAll();
        }
        jsonResponse(true, 'Дані команди завантажено.', ['users' => $users, 'projects' => $projects, 'members' => $members, 'permissions' => ['admin' => $isAdmin]]);
    }

    if ($method !== 'POST') jsonResponse(false, 'Метод не підтримується.', [], 405);
    if ($action === 'create_project') {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') jsonResponse(false, 'Введіть назву проєкту.', [], 422);
        $description = trim((string)($data['description'] ?? ''));
        $id = bin2hex(random_bytes(16));
        $pdo->beginTransaction();
        $insert = $pdo->prepare('INSERT INTO projects (id, name, description, owner_id) VALUES (?, ?, ?, ?)');
        $insert->execute([$id, $name, $description, $_SESSION['user_id']]);
        $member = $pdo->prepare("INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, 'admin')");
        $member->execute([$id, $_SESSION['user_id']]);
        if ($canManage && !empty($data['members']) && is_array($data['members'])) {
            $addMember = $pdo->prepare('INSERT IGNORE INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)');
            foreach ($data['members'] as $selected) {
                $memberId = trim((string)($selected['user_id'] ?? ''));
                $memberRole = trim((string)($selected['role'] ?? 'member'));
                if ($memberId !== $_SESSION['user_id'] && in_array($memberRole, ['admin', 'manager', 'member', 'viewer'], true)) {
                    $addMember->execute([$id, $memberId, $memberRole]);
                }
            }
        }
        $invited = 0;
        if ($canManage && !empty($data['invite_emails']) && is_array($data['invite_emails'])) {
            $inviteRole = trim((string)($data['invite_role'] ?? 'member'));
            if (!in_array($inviteRole, ['manager', 'member', 'viewer'], true)) $inviteRole = 'member';
            $revoke = $pdo->prepare("UPDATE project_invitations SET status = 'revoked' WHERE project_id = ? AND email = ? AND status = 'pending'");
            $inviteInsert = $pdo->prepare('INSERT INTO project_invitations (id, project_id, invited_by, email, role, token_hash, expires_at) VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))');
            foreach ($data['invite_emails'] as $rawEmail) {
                $inviteEmail = strtolower(trim((string)$rawEmail));
                if (!filter_var($inviteEmail, FILTER_VALIDATE_EMAIL)) continue;
                $revoke->execute([$id, $inviteEmail]);
                $token = bin2hex(random_bytes(32));
                $inviteInsert->execute([bin2hex(random_bytes(16)), $id, $_SESSION['user_id'], $inviteEmail, $inviteRole, hash('sha256', $token)]);
                $subject = 'Вас запросили до проєкту NexaFlow';
                $message = "Вас запросили до проєкту «{$name}» у NexaFlow.\n\nПрийняти запрошення: " . projectInviteUrl($token) . "\n\nПосилання дійсне 7 днів.";
                sendProjectEmail($inviteEmail, $subject, $message);
                $invited++;
            }
        }
        $pdo->commit();
        jsonResponse(true, $invited ? "Проєкт створено. Запрошень відправлено: {$invited}." : 'Проєкт створено.', ['project' => ['id' => $id, 'name' => $name], 'invited' => $invited]);
    }

    if ($action === 'assign_role') {
        $projectId = trim((string)($data['project_id'] ?? ''));
        $userId = trim((string)($data['user_id'] ?? ''));
        $role = trim((string)($data['role'] ?? 'member'));
        if ($projectId === '' || $userId === '' || !in_array($role, ['admin', 'manager', 'member', 'viewer'], true)) {
            jsonResponse(false, 'Оберіть проєкт, користувача та коректну роль.', [], 422);
        }
        $upsert = $pdo->prepare('INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE role = VALUES(role), updated_at = CURRENT_TIMESTAMP');
        $upsert->execute([$projectId, $userId, $role]);
        jsonResponse(true, 'Роль користувача оновлено.');
    }

    if ($action === 'set_user_role') {
        if (!$isAdmin) jsonResponse(false, 'Тільки адміністратор може змінювати глобальні ролі.', [], 403);
        $userId = trim((string)($data['user_id'] ?? ''));
        $role = trim((string)($data['role'] ?? 'user'));
        if ($userId === '' || !in_array($role, ['admin', 'manager', 'user'], true)) jsonResponse(false, 'Оберіть коректну глобальну роль.', [], 422);
        $update = $pdo->prepare('UPDATE users SET role = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $update->execute([$role, $userId]);
        jsonResponse(true, 'Глобальну роль оновлено.');
    }

    jsonResponse(false, 'Невідома дія.', [], 400);
} catch (Throwable $error) {
    error_log($error->getMessage());
    jsonResponse(false, 'Помилка під час роботи з командою.', [], 500);
}
