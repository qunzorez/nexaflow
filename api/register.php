<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/verification.php';

$data = body();
$name = trim((string)($data['name'] ?? ''));
$email = strtolower(trim((string)($data['email'] ?? '')));
$password = (string)($data['password'] ?? '');
$passwordConfirmation = (string)($data['password_confirmation'] ?? '');
$requestedRole = ($data['requested_role'] ?? 'user') === 'manager' ? 'manager' : 'user';

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
    jsonResponse(false, 'Введіть ім’я, коректну пошту та пароль мінімум із 8 символів.', [], 422);
}
if ($password !== $passwordConfirmation) jsonResponse(false, 'Паролі не співпадають.', [], 422);

try {
    $pdo = db();
    $check = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $check->execute([$email]);
    if ($check->fetch()) jsonResponse(false, 'Користувач з такою поштою вже існує.', [], 409);

    $id = bin2hex(random_bytes(16));
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $insert = $pdo->prepare('INSERT INTO users (id, name, email, password_hash, role, requested_role, email_verified_at) VALUES (?, ?, ?, ?, ?, ?, NULL)');
    $insert->execute([$id, $name, $email, $hash, 'user', $requestedRole]);
    $notificationUsers = $pdo->query('SELECT id FROM users WHERE id <> ' . $pdo->quote($id))->fetchAll();
    $notificationInsert = $pdo->prepare("INSERT INTO notifications (id, user_id, sender_id, type, title, message, entity_type, entity_id) VALUES (?, ?, ?, 'user_created', ?, ?, 'user', ?)");
    foreach ($notificationUsers as $recipient) {
        $notificationInsert->execute([bin2hex(random_bytes(16)), $recipient['id'], $id, 'Новий користувач', "До NexaFlow приєднався користувач {$name}.", $id]);
    }
    if ($requestedRole === 'manager') {
        $admins = $pdo->query("SELECT id FROM users WHERE role IN ('admin', 'owner') AND id <> " . $pdo->quote($id))->fetchAll();
        foreach ($admins as $admin) $notificationInsert->execute([bin2hex(random_bytes(16)), $admin['id'], $id, 'Запит на роль менеджера', "Користувач {$name} просить надати роль менеджера.", $id]);
    }
    $sent = issueEmailVerification($pdo, $id, $email, $name);
    jsonResponse(true, $sent ? 'Акаунт створено. Перевірте пошту для підтвердження.' : 'Акаунт створено, але лист не вдалося відправити. Спробуйте повторити відправлення.', ['requires_verification' => true, 'email' => $email]);
} catch (Throwable $error) {
    error_log($error->getMessage());
    jsonResponse(false, 'Помилка бази даних під час реєстрації.', [], 500);
}
