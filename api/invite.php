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
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $projectId === '' || !in_array($role, ['admin', 'manager', 'member', 'viewer'], true)) jsonResponse(false, 'Введіть email, проєкт і коректну роль.', [], 422);

$access = $pdo->prepare("SELECT p.name FROM projects p LEFT JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ? JOIN users cu ON cu.id = ? WHERE p.id = ? AND (cu.role IN ('admin', 'owner') OR pm.role IN ('admin', 'manager')) LIMIT 1");
$access->execute([$_SESSION['user_id'], $_SESSION['user_id'], $projectId]);
$project = $access->fetch();
if (!$project) jsonResponse(false, 'У вас немає прав запрошувати людей у цей проєкт.', [], 403);

try {
    $pdo->prepare("UPDATE project_invitations SET status = 'revoked' WHERE project_id = ? AND email = ? AND status = 'pending'")->execute([$projectId, $email]);
    $token = bin2hex(random_bytes(32));
    $id = bin2hex(random_bytes(16));
    $insert = $pdo->prepare('INSERT INTO project_invitations (id, project_id, invited_by, email, role, token_hash, expires_at) VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))');
    $insert->execute([$id, $projectId, $_SESSION['user_id'], $email, $role, hash('sha256', $token)]);
    $url = invitationUrl($token);
    $subject = 'Вас запросили до проєкту NexaFlow';
    $message = "Вас запросили до проєкту «{$project['name']}» у NexaFlow.\n\nРоль: {$role}\n\nПрийняти запрошення: {$url}\n\nПосилання дійсне 7 днів.";
    $sent = sendProjectEmail($email, $subject, $message);
    jsonResponse(true, $sent ? 'Запрошення відправлено на email.' : 'Запрошення створено, але хостинг не підтвердив відправку email.', ['sent' => $sent, 'expires_in_days' => 7]);
} catch (Throwable $error) {
    error_log($error->getMessage());
    jsonResponse(false, 'Не вдалося створити запрошення.', [], 500);
}
