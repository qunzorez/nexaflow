<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
session_start();
$data = body();
$token = trim((string)($data['token'] ?? ($_GET['token'] ?? '')));
$name = trim((string)($data['name'] ?? ''));
$password = (string)($data['password'] ?? '');
if ($token === '') jsonResponse(false, 'Запрошення не знайдено.', [], 422);
$pdo = db();
$query = $pdo->prepare("SELECT pi.*, p.name AS project_name FROM project_invitations pi JOIN projects p ON p.id = pi.project_id WHERE pi.token_hash = ? AND pi.status = 'pending' AND pi.expires_at > NOW() LIMIT 1");
$query->execute([hash('sha256', $token)]);
$invite = $query->fetch();
$generalQuery = $pdo->prepare("SELECT * FROM user_invitations WHERE token_hash = ? AND status = 'pending' AND expires_at > NOW() LIMIT 1");
$generalQuery->execute([hash('sha256', $token)]);
$generalInvite = $generalQuery->fetch();
if (!$invite && $generalInvite) {
    $existingGeneralQuery = $pdo->prepare('SELECT id, name FROM users WHERE email = ? LIMIT 1');
    $existingGeneralQuery->execute([$generalInvite['email']]);
    $existingGeneral = $existingGeneralQuery->fetch();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') jsonResponse(true, 'Загальне запрошення дійсне.', ['general' => true, 'email' => $generalInvite['email'], 'existing_user' => (bool)$existingGeneral]);
    if (!$existingGeneral && ($name === '' || strlen($password) < 6)) jsonResponse(false, 'Введіть ім’я та пароль мінімум із 6 символів.', [], 422);
    try {
        $pdo->beginTransaction();
        $user = $existingGeneral;
        if (!$user) {
            $userId = bin2hex(random_bytes(16));
            $insert = $pdo->prepare("INSERT INTO users (id, name, email, password_hash, role, email_verified_at) VALUES (?, ?, ?, ?, 'user', NOW())");
            $insert->execute([$userId, $name, $generalInvite['email'], password_hash($password, PASSWORD_DEFAULT)]);
        } else $userId = $user['id'];
        if (!empty($generalInvite['group_id'])) {
            $groupMember = $pdo->prepare('INSERT IGNORE INTO project_group_members (group_id, user_id) VALUES (?, ?)');
            $groupMember->execute([$generalInvite['group_id'], $userId]);
        }
        $done = $pdo->prepare("UPDATE user_invitations SET status = 'accepted', accepted_at = NOW() WHERE id = ?");
        $done->execute([$generalInvite['id']]);
        $pdo->commit();
        session_set_cookie_params(['httponly' => true, 'secure' => isset($_SERVER['HTTPS']), 'samesite' => 'Lax']);
        session_regenerate_id(true); $_SESSION['user_id'] = $userId; $_SESSION['user_name'] = $user['name'] ?? $name; $_SESSION['user_role'] = 'user';
        jsonResponse(true, 'Акаунт створено. Ласкаво просимо до NexaFlow.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($error->getMessage()); jsonResponse(false, 'Не вдалося прийняти загальне запрошення.', [], 500);
    }
}
if (!$invite) {
    $statusQuery = $pdo->prepare("SELECT pi.status, pi.expires_at, p.name AS project_name FROM project_invitations pi JOIN projects p ON p.id = pi.project_id WHERE pi.token_hash = ? LIMIT 1");
    $statusQuery->execute([hash('sha256', $token)]);
    $oldInvite = $statusQuery->fetch();
    if (!$oldInvite) jsonResponse(false, 'Запрошення не знайдено. Попросіть менеджера надіслати його ще раз.', [], 404);
    if ($oldInvite['status'] === 'accepted') jsonResponse(false, 'Це запрошення вже прийнято. Увійдіть у NexaFlow під своєю поштою.', ['project_name' => $oldInvite['project_name']], 409);
    if ($oldInvite['status'] === 'revoked') jsonResponse(false, 'Це запрошення замінене новим. Відкрийте останній лист.', [], 410);
    jsonResponse(false, 'Термін дії запрошення завершився. Попросіть надіслати його ще раз.', [], 410);
}
$existingUserQuery = $pdo->prepare('SELECT id, name FROM users WHERE email = ? LIMIT 1');
$existingUserQuery->execute([$invite['email']]);
$existingUser = $existingUserQuery->fetch();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') jsonResponse(true, 'Запрошення дійсне.', ['project_name' => $invite['project_name'], 'email' => $invite['email'], 'existing_user' => (bool)$existingUser]);
if (!$existingUser && ($name === '' || strlen($password) < 6)) jsonResponse(false, 'Введіть ім’я та пароль мінімум із 6 символів.', [], 422);
try {
    $pdo->beginTransaction();
    $user = $existingUser;
    if (!$user) {
        $userId = bin2hex(random_bytes(16));
        $insert = $pdo->prepare('INSERT INTO users (id, name, email, password_hash, role, email_verified_at) VALUES (?, ?, ?, ?, \'user\', NOW())');
        $insert->execute([$userId, $name, $invite['email'], password_hash($password, PASSWORD_DEFAULT)]);
        $user = ['id' => $userId, 'name' => $name];
    } else {
        $userId = $user['id'];
    }
    $member = $pdo->prepare('INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE role = VALUES(role)');
    $member->execute([$invite['project_id'], $userId, $invite['role']]);
    if (!empty($invite['group_id'])) {
        $groupMember = $pdo->prepare('INSERT IGNORE INTO project_group_members (group_id, user_id) VALUES (?, ?)');
        $groupMember->execute([$invite['group_id'], $userId]);
    }
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
