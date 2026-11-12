<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

$data = body();
$email = strtolower(trim((string)($data['email'] ?? '')));
$password = (string)($data['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    jsonResponse(false, 'Введіть пошту та пароль.', [], 422);
}

try {
    $query = db()->prepare('SELECT id, name, email, password_hash, role FROM users WHERE email = ? LIMIT 1');
    $query->execute([$email]);
    $user = $query->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        jsonResponse(false, 'Невірна пошта або пароль.', [], 401);
    }

    session_set_cookie_params(['httponly' => true, 'secure' => isset($_SERVER['HTTPS']), 'samesite' => 'Lax']);
    session_start();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];
    jsonResponse(true, 'Вхід виконано.', ['user' => ['name' => $user['name'], 'email' => $user['email'], 'role' => $user['role']]]);
} catch (Throwable $error) {
    error_log($error->getMessage());
    jsonResponse(false, 'Помилка бази даних під час входу.', [], 500);
}
