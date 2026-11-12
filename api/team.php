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
            $usersQuery = $pdo->prepare("SELECT DISTINCT u.id, u.name, u.email, u.role, u.created_at, (SELECT pgm.group_id FROM project_group_members pgm JOIN project_groups pgg ON pgg.id = pgm.group_id WHERE pgm.user_id = u.id AND pgg.project_id IS NULL LIMIT 1) AS group_id FROM users u LEFT JOIN project_invitations pi ON pi.email = u.email AND pi.invited_by = ? AND pi.status = 'accepted' LEFT JOIN user_invitations ui ON ui.email = u.email AND ui.invited_by = ? AND ui.status = 'accepted' WHERE pi.id IS NOT NULL OR ui.id IS NOT NULL ORDER BY u.created_at DESC");
            $usersQuery->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
            $users = $usersQuery->fetchAll();
        }
        if ($isAdmin) {
            $projects = $pdo->query('SELECT id, name FROM projects ORDER BY name')->fetchAll();
            $members = $pdo->query('SELECT pm.project_id, pm.user_id, pm.role, p.name AS project_name, u.name AS user_name, u.email AS user_email FROM project_members pm JOIN projects p ON p.id = pm.project_id JOIN users u ON u.id = pm.user_id ORDER BY p.name, u.name')->fetchAll();
        } else {
            $projectsQuery = $pdo->prepare("SELECT DISTINCT p.id, p.name FROM projects p LEFT JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ? WHERE p.owner_id = ? OR (p.visibility = 'team' AND pm.user_id IS NOT NULL) ORDER BY p.name");
            $projectsQuery->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
            $projects = $projectsQuery->fetchAll();
            $membersQuery = $pdo->prepare("SELECT pm.project_id, pm.user_id, pm.role, p.name AS project_name, u.name AS user_name, u.email AS user_email FROM project_members pm JOIN projects p ON p.id = pm.project_id JOIN users u ON u.id = pm.user_id JOIN project_invitations pi ON pi.project_id = pm.project_id AND pi.email = u.email WHERE pi.invited_by = ? AND pi.status = 'accepted' ORDER BY p.name, u.name");
            $membersQuery->execute([$_SESSION['user_id']]);
            $members = $membersQuery->fetchAll();
        }
        if ($isAdmin) {
            $groups = $pdo->query("SELECT pg.id, pg.project_id, pg.name, pg.role_name, pg.permissions, COALESCE(p.name, 'Глобальна група') AS project_name, COUNT(DISTINCT pgm.user_id) AS member_count FROM project_groups pg LEFT JOIN projects p ON p.id = pg.project_id LEFT JOIN project_group_members pgm ON pgm.group_id = pg.id GROUP BY pg.id, pg.project_id, pg.name, pg.role_name, pg.permissions, p.name ORDER BY project_name, pg.name")->fetchAll();
        } else {
            $groupsQuery = $pdo->prepare("SELECT pg.id, pg.project_id, pg.name, pg.role_name, pg.permissions, COALESCE(p.name, 'Глобальна група') AS project_name, COUNT(DISTINCT pgm.user_id) AS member_count FROM project_groups pg LEFT JOIN projects p ON p.id = pg.project_id LEFT JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ? LEFT JOIN project_group_members pgm ON pgm.group_id = pg.id WHERE pg.project_id IS NULL OR p.owner_id = ? OR pm.user_id IS NOT NULL GROUP BY pg.id, pg.project_id, pg.name, pg.role_name, pg.permissions, p.name ORDER BY project_name, pg.name");
            $groupsQuery->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
            $groups = $groupsQuery->fetchAll();
        }
        foreach ($groups as &$group) $group['permissions'] = $group['permissions'] ? (json_decode($group['permissions'], true) ?: []) : [];
        unset($group);
        jsonResponse(true, 'Дані команди завантажено.', ['users' => $users, 'projects' => $projects, 'members' => $members, 'groups' => $groups, 'permissions' => ['admin' => $isAdmin]]);
    }

    if ($method !== 'POST') jsonResponse(false, 'Метод не підтримується.', [], 405);
    if ($action === 'create_project') {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') jsonResponse(false, 'Введіть назву проєкту.', [], 422);
        $description = trim((string)($data['description'] ?? ''));
        $status = in_array(($data['status'] ?? 'active'), ['active', 'draft', 'paused', 'completed'], true) ? (string)$data['status'] : 'active';
        $visibility = in_array(($data['visibility'] ?? 'private'), ['private', 'team'], true) ? (string)$data['visibility'] : 'private';
        $id = bin2hex(random_bytes(16));
        $pdo->beginTransaction();
        $insert = $pdo->prepare('INSERT INTO projects (id, name, description, owner_id, status, visibility) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute([$id, $name, $description, $_SESSION['user_id'], $status, $visibility]);
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
        if ($canManage && !empty($data['groups']) && is_array($data['groups'])) {
            $groupInsert = $pdo->prepare('INSERT INTO project_groups (id, project_id, name) VALUES (?, ?, ?)');
            $groupMemberInsert = $pdo->prepare('INSERT IGNORE INTO project_group_members (group_id, user_id) VALUES (?, ?)');
            foreach ($data['groups'] as $group) {
                $groupName = trim((string)($group['name'] ?? ''));
                if ($groupName === '') continue;
                $groupId = bin2hex(random_bytes(16));
                $groupInsert->execute([$groupId, $id, mb_substr($groupName, 0, 120)]);
                foreach ((array)($group['user_ids'] ?? []) as $groupUserId) {
                    $groupUserId = trim((string)$groupUserId);
                    if ($groupUserId !== '') $groupMemberInsert->execute([$groupId, $groupUserId]);
                }
            }
        }
        $invited = 0;
        $sent = 0;
        $failed = 0;
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
                $safeProjectName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
                $safeInviteUrl = htmlspecialchars(projectInviteUrl($token), ENT_QUOTES, 'UTF-8');
                $htmlMessage = "<div style=\"font-family:Arial,sans-serif;max-width:600px;color:#17201e\"><h2>Вас запросили до проєкту</h2><p>Вас запросили до проєкту <strong>«{$safeProjectName}»</strong> у NexaFlow.</p><p><a href=\"{$safeInviteUrl}\" style=\"display:inline-block;padding:12px 20px;background:#1f8a68;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold\">Приєднатись до проєкту</a></p><p style=\"color:#7b8983;font-size:12px\">Посилання дійсне 7 днів.</p></div>";
                $existingInviteUser = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                $existingInviteUser->execute([$inviteEmail]);
                $existingInviteUser = $existingInviteUser->fetch();
                if ($existingInviteUser && $existingInviteUser['id'] !== $_SESSION['user_id']) {
                    $pdo->prepare("INSERT INTO notifications (id, user_id, sender_id, type, title, message, entity_type, entity_id) VALUES (?, ?, ?, 'project_invite', ?, ?, 'project', ?)")->execute([bin2hex(random_bytes(16)), $existingInviteUser['id'], $_SESSION['user_id'], 'Запрошення до нового проєкту', "Вас запросили до проєкту «{$name}». Відкрийте лист і натисніть «Приєднатись до проєкту».", $id]);
                }
                $emailSent = $existingInviteUser && $existingInviteUser['id'] !== $_SESSION['user_id']
                    ? sendUserNotificationEmail($pdo, (string)$existingInviteUser['id'], $inviteEmail, $subject, $message, $htmlMessage)
                    : sendProjectEmail($inviteEmail, $subject, $message, $htmlMessage);
                if ($emailSent) $sent++;
                else $failed++;
                $invited++;
            }
        }
        $pdo->commit();
        $notificationUsers = $pdo->query('SELECT id FROM users')->fetchAll();
        $notificationInsert = $pdo->prepare("INSERT INTO notifications (id, user_id, sender_id, type, title, message, entity_type, entity_id) VALUES (?, ?, ?, 'project_created', ?, ?, 'project', ?)");
        foreach ($notificationUsers as $recipient) {
            $notificationInsert->execute([bin2hex(random_bytes(16)), $recipient['id'], $_SESSION['user_id'], 'Створено новий проєкт', "Проєкт «{$name}» додано до робочого простору.", $id]);
        }
        $inviteMessage = $invited ? " Запрошень створено: {$invited}; успішно передано поштовому серверу: {$sent}." : '';
        if ($failed) $inviteMessage .= " Не вдалося передати: {$failed}. Перевірте Mailpit або SMTP-конфігурацію.";
        jsonResponse(true, 'Проєкт створено.' . $inviteMessage, ['project' => ['id' => $id, 'name' => $name], 'invited' => $invited, 'sent' => $sent, 'failed' => $failed]);
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
