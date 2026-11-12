<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/mailer.php';

session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$data = $method === 'POST' ? body() : [];
$action = (string)($data['action'] ?? ($_GET['action'] ?? ''));

function invitationUrl(string $token): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'nexaflow.top';
    return $scheme . '://' . $host . '/accept-invite?token=' . urlencode($token);
}

if ($action === 'details' && $method === 'GET') {
    $token = trim((string)($_GET['token'] ?? ''));
    $hash = hash('sha256', $token);
    $query = $pdo->prepare('SELECT pi.email, pi.role, pi.expires_at, p.name AS project_name, u.name AS inviter_name FROM project_invitations pi JOIN projects p ON p.id = pi.project_id JOIN users u ON u.id = pi.invited_by WHERE pi.token_hash = ? AND pi.status = \'pending\' AND pi.expires_at > NOW() LIMIT 1');
    $query->execute([$hash]);
    $invite = $query->fetch();
    if (!$invite) jsonResponse(false, 'Запрошення недійсне або вже завершилось.', [], 404);
    jsonResponse(true, 'Запрошення знайдено.', ['invitation' => $invite]);
}

if ($method !== 'POST' || $action !== 'send') jsonResponse(false, 'Невірний запит.', [], 405);

$email = strtolower(trim((string)($data['email'] ?? '')));
$projectId = trim((string)($data['project_id'] ?? ''));
$role = trim((string)($data['role'] ?? 'member'));
$groupId = trim((string)($data['group_id'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['admin', 'manager', 'member', 'viewer'], true)) jsonResponse(false, 'Введіть коректний email і роль.', [], 422);

if ($projectId === '' && $groupId !== '') {
    $groupProject = $pdo->prepare('SELECT project_id FROM project_groups WHERE id = ? LIMIT 1');
    $groupProject->execute([$groupId]);
    $projectId = (string)($groupProject->fetchColumn() ?: '');
}

if ($projectId === '') {
    $current = $pdo->prepare("SELECT role FROM users WHERE id = ? AND role IN ('admin', 'owner', 'manager') LIMIT 1");
    $current->execute([$_SESSION['user_id']]);
    if (!$current->fetch()) jsonResponse(false, 'Недостатньо прав для загального запрошення.', [], 403);
    if ($groupId !== '') {
        $groupCheck = $pdo->prepare('SELECT id FROM project_groups WHERE id = ? AND project_id IS NULL LIMIT 1');
        $groupCheck->execute([$groupId]);
        if (!$groupCheck->fetch()) jsonResponse(false, 'Обрана група не є глобальною.', [], 422);
    }
    try {
        $pdo->prepare("UPDATE user_invitations SET status = 'revoked' WHERE email = ? AND status = 'pending'")->execute([$email]);
        $token = bin2hex(random_bytes(32));
        $insert = $pdo->prepare('INSERT INTO user_invitations (id, invited_by, email, role, group_id, token_hash, expires_at) VALUES (?, ?, ?, \'user\', ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))');
        $insert->execute([bin2hex(random_bytes(16)), $_SESSION['user_id'], $email, $groupId !== '' ? $groupId : null, hash('sha256', $token)]);
        $sent = sendProjectEmail($email, 'Запрошення до NexaFlow', "Вас запросили приєднатися до NexaFlow.\n\nСтворити акаунт: " . invitationUrl($token) . "\n\nПосилання дійсне 7 днів.");
        jsonResponse(true, $sent ? 'Загальне запрошення відправлено на email.' : 'Запрошення створено, але поштовий сервер не підтвердив відправку.', ['sent' => $sent]);
    } catch (Throwable $error) {
        error_log($error->getMessage());
        jsonResponse(false, 'Не вдалося створити загальне запрошення.', [], 500);
    }
}

$access = $pdo->prepare("SELECT p.name FROM projects p LEFT JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ? JOIN users cu ON cu.id = ? WHERE p.id = ? AND (cu.role IN ('admin', 'owner') OR p.owner_id = ? OR pm.role IN ('admin', 'manager')) LIMIT 1");
$access->execute([$_SESSION['user_id'], $_SESSION['user_id'], $projectId, $_SESSION['user_id']]);
$project = $access->fetch();
if (!$project) jsonResponse(false, 'У вас немає прав запрошувати людей у цей проєкт.', [], 403);
if ($groupId !== '') {
    $groupCheck = $pdo->prepare('SELECT id FROM project_groups WHERE id = ? AND (project_id = ? OR project_id IS NULL) LIMIT 1');
    $groupCheck->execute([$groupId, $projectId]);
    if (!$groupCheck->fetch()) jsonResponse(false, 'Обрана група не належить цьому проєкту.', [], 422);
}

try {
    $pdo->prepare("UPDATE project_invitations SET status = 'revoked' WHERE project_id = ? AND email = ? AND status = 'pending'")->execute([$projectId, $email]);
    $token = bin2hex(random_bytes(32));
    $id = bin2hex(random_bytes(16));
    $insert = $pdo->prepare('INSERT INTO project_invitations (id, project_id, invited_by, email, role, group_id, token_hash, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))');
    $insert->execute([$id, $projectId, $_SESSION['user_id'], $email, $role, $groupId !== '' ? $groupId : null, hash('sha256', $token)]);
    $url = invitationUrl($token);
    $subject = 'Вас запросили до проєкту NexaFlow';
    $message = "Вас запросили до проєкту «{$project['name']}» у NexaFlow.\n\nРоль: {$role}\n\nПрийняти запрошення: {$url}\n\nПосилання дійсне 7 днів.";
    $safeProject = htmlspecialchars($project['name'], ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $htmlMessage = "<div style=\"font-family:Arial,sans-serif;max-width:600px;color:#17201e\"><h2>Вас запросили до проєкту</h2><p>Вас запросили до проєкту <strong>«{$safeProject}»</strong> у NexaFlow.</p><p>Роль: {$role}</p><p><a href=\"{$safeUrl}\" style=\"display:inline-block;padding:12px 20px;background:#1f8a68;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold\">Приєднатись до проєкту</a></p><p style=\"color:#7b8983;font-size:12px\">Посилання дійсне 7 днів.</p></div>";
    $existingUserQuery = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $existingUserQuery->execute([$email]);
    $existingUser = $existingUserQuery->fetch();
    if ($existingUser && $existingUser['id'] !== $_SESSION['user_id']) {
        $pdo->prepare("INSERT INTO notifications (id, user_id, sender_id, type, title, message, entity_type, entity_id) VALUES (?, ?, ?, 'project_invite', ?, ?, 'project', ?)")->execute([bin2hex(random_bytes(16)), $existingUser['id'], $_SESSION['user_id'], 'Запрошення до нового проєкту', "Вас запросили до проєкту «{$project['name']}». Відкрийте лист і натисніть «Приєднатись до проєкту».", $projectId]);
    }
    $sent = $existingUser
        ? sendUserNotificationEmail($pdo, (string)$existingUser['id'], $email, $subject, $message, $htmlMessage)
        : sendProjectEmail($email, $subject, $message, $htmlMessage);
    jsonResponse(true, $sent ? 'Запрошення відправлено на email.' : 'Запрошення створено, але хостинг не підтвердив відправку email.', ['sent' => $sent, 'expires_in_days' => 7]);
} catch (Throwable $error) {
    error_log($error->getMessage());
    jsonResponse(false, 'Не вдалося створити запрошення.', [], 500);
}
