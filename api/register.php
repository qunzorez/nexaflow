<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

$data = body();
$name = trim((string)($data['name'] ?? ''));
$email = strtolower(trim((string)($data['email'] ?? '')));
$password = (string)($data['password'] ?? '');

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
    jsonResponse(false, 'Введіть ім’я, коректну пошту та пароль мінімум із 6 символів.', [], 422);
}

try {
    $check = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $check->execute([$email]);
    if ($check->fetch()) jsonResponse(false, 'Користувач з такою поштою вже існує.', [], 409);

    $id = bin2hex(random_bytes(16));
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $insert = db()->prepare('INSERT INTO users (id, name, email, password_hash) VALUES (?, ?, ?, ?)');
    $insert->execute([$id, $name, $email, $hash]);
    jsonResponse(true, 'Акаунт створено.');
} catch (Throwable $error) {
    error_log($error->getMessage());
    jsonResponse(false, 'Помилка бази даних під час реєстрації.', [], 500);
}
