<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
session_start();
$data = body();
$token = trim((string)($data['token'] ?? ''));
$name = trim((string)($data['name'] ?? ''));
$password = (string)($data['password'] ?? '');
if ($token === '' || $name === '' || strlen($password) < 6) jsonResponse(false, 'Введіть ім’я та пароль мінімум із 6 символів.', [], 422);
$pdo = db();
$query = $pdo->prepare("SELECT pi.*, p.name AS project_name FROM project_invitations pi JOIN projects p ON p.id = pi.project_id WHERE pi.token_hash = ? AND pi.status = 'pending' AND pi.expires_at > NOW() LIMIT 1");
$query->execute([hash('sha256', $token)]);
$invite = $query->fetch();
if (!$invite) jsonResponse(false, 'Запрошення недійсне або вже завершилось.', [], 404);
try {
    $pdo->beginTransaction();
    $find = $pdo->prepare('SELECT id, name, password_hash FROM users WHERE email = ? LIMIT 1');
    $find->execute([$invite['email']]);
    $user = $find->fetch();
    if (!$user) {
        $userId = bin2hex(random_bytes(16));
        $insert = $pdo->prepare('INSERT INTO users (id, name, email, password_hash, role) VALUES (?, ?, ?, ?, \'user\')');
        $insert->execute([$userId, $name, $invite['email'], password_hash($password, PASSWORD_DEFAULT)]);
        $user = ['id' => $userId, 'name' => $name];
    } else {
        $userId = $user['id'];
    }
    $member = $pdo->prepare('INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE role = VALUES(role)');
    $member->execute([$invite['project_id'], $userId, $invite['role']]);
    $done = $pdo->prepare("UPDATE project_invitations SET status = 'accepted', accepted_at = NOW() WHERE id = ?");
    $done->execute([$invite['id']]);
    $pdo->commit();
    session_set_cookie_params(['httponly' => true, 'secure' => isset($_SERVER['HTTPS']), 'samesite' => 'Lax']);
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_name'] = $user['name'] ?? $name;
    $_SESSION['user_role'] = 'user';
    jsonResponse(true, 'Запрошення прийнято.', ['project_name' => $invite['project_name']]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log($error->getMessage());
    jsonResponse(false, 'Не вдалося прийняти запрошення.', [], 500);
}
