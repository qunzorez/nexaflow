<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_once __DIR__ . '/maintenance.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonResponse(false, 'Метод не підтримується.', [], 405);

$data = body();
$email = strtolower(trim((string)($data['email'] ?? '')));
$password = (string)($data['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    jsonResponse(false, 'Введіть пошту та пароль.', [], 422);
}

try {
    $pdo = db();
    require_once __DIR__ . '/rate-limit.php';
    enforcePublicRateLimit($pdo, 'login', $email, 30, 10, 900);
    $query = $pdo->prepare('SELECT id, name, email, password_hash, role, email_verified_at, is_blocked, blocked_reason FROM users WHERE email = ? LIMIT 1');
    $query->execute([$email]);
    $user = $query->fetch();
    if ($user && (int)($user['is_blocked'] ?? 0) === 1) jsonResponse(false, 'Обліковий запис заблоковано.', ['code' => 'account_blocked', 'reason' => (string)($user['blocked_reason'] ?? '')], 423);
    // Old/imported accounts can contain an empty or malformed hash. Treat
    // those records as invalid credentials instead of allowing PHP 7.4 to
    // throw a TypeError and return an opaque HTTP 500 response.
    $passwordHash = is_array($user) ? (string)($user['password_hash'] ?? '') : '';
    if (!$user || $passwordHash === '' || !password_verify($password, $passwordHash)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS auth_event_log (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id CHAR(32) NULL, email VARCHAR(190) NULL, event_type VARCHAR(40) NOT NULL, result VARCHAR(20) NOT NULL, source VARCHAR(40) NOT NULL DEFAULT 'web', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_auth_event_created (created_at), INDEX idx_auth_event_user (user_id, created_at), INDEX idx_auth_event_type (event_type, result), CONSTRAINT fk_auth_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $event = $pdo->prepare("INSERT INTO auth_event_log (user_id, email, event_type, result) VALUES (?, ?, 'login', 'failed')");
        $event->execute([$user['id'] ?? null, $email]);
        jsonResponse(false, 'Невірна пошта або пароль.', [], 401);
    }
    if ((bool)maintenanceState($pdo)['enabled'] && !in_array((string)$user['role'], ['admin', 'owner'], true)) jsonResponse(false, 'Зараз тривають технічні роботи. Спробуйте увійти пізніше.', ['code' => 'maintenance_mode'], 503);
    session_set_cookie_params(['httponly' => true, 'secure' => isset($_SERVER['HTTPS']), 'samesite' => 'Lax']);
    session_start();
    session_regenerate_id(true);
    $pdo->exec("CREATE TABLE IF NOT EXISTS auth_event_log (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id CHAR(32) NULL, email VARCHAR(190) NULL, event_type VARCHAR(40) NOT NULL, result VARCHAR(20) NOT NULL, source VARCHAR(40) NOT NULL DEFAULT 'web', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_auth_event_created (created_at), INDEX idx_auth_event_user (user_id, created_at), INDEX idx_auth_event_type (event_type, result), CONSTRAINT fk_auth_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];
    $event = $pdo->prepare("INSERT INTO auth_event_log (user_id, email, event_type, result) VALUES (?, ?, 'login', 'success')");
    $event->execute([$user['id'], $user['email']]);
    jsonResponse(true, 'Вхід виконано.', ['user' => ['name' => $user['name'], 'email' => $user['email'], 'role' => $user['role'], 'email_verified_at' => $user['email_verified_at'], 'client_portal_only' => accountingPortalOnlyUser($pdo, (string)$user['id'], (string)$user['role'])]]);
} catch (Throwable $error) {
    error_log($error->getMessage());
    jsonResponse(false, 'Помилка бази даних під час входу.', [], 500);
}
