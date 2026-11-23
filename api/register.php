<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/verification.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonResponse(false, 'Метод не підтримується.', [], 405);

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
    require_once __DIR__ . '/rate-limit.php';
    enforcePublicRateLimit($pdo, 'register', $email, 10, 3, 3600);
    $check = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $check->execute([$email]);
    if ($check->fetch()) jsonResponse(true, 'Якщо адресу можна зареєструвати, лист підтвердження буде надіслано.', ['requires_verification' => true]);

    $id = bin2hex(random_bytes(16));
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $insert = $pdo->prepare('INSERT INTO users (id, name, email, password_hash, role, requested_role, email_notifications_enabled, email_verified_at) VALUES (?, ?, ?, ?, ?, ?, 1, NULL)');
    $insert->execute([$id, $name, $email, $hash, $requestedRole, $requestedRole]);
    $notificationUsers = $pdo->prepare("SELECT id FROM users WHERE role IN ('admin', 'owner') AND id <> ?");
    $notificationUsers->execute([$id]);
    $notificationInsert = $pdo->prepare("INSERT INTO notifications (id, user_id, sender_id, type, title, message, entity_type, entity_id) VALUES (?, ?, ?, 'user_created', ?, ?, 'user', ?)");
    foreach ($notificationUsers->fetchAll() as $recipient) {
        $notificationInsert->execute([bin2hex(random_bytes(16)), $recipient['id'], $id, 'Новий користувач', "До NexaFlow приєднався користувач {$name}.", $id]);
    }

    $userInvites = $pdo->prepare("SELECT i.id, i.group_id, g.name AS group_name FROM user_invitations i LEFT JOIN project_groups g ON g.id = i.group_id WHERE i.email = ? AND i.status = 'pending' AND i.expires_at > NOW()");
    $userInvites->execute([$email]);
    foreach ($userInvites->fetchAll() as $invite) {
        $groupName = trim((string)($invite['group_name'] ?? ''));
        $title = $groupName !== '' ? 'Вас запросили до групи' : 'Вас запросили до команди';
        $message = $groupName !== '' ? "Вас запросили до групи «{$groupName}». Перевірте лист із запрошенням, щоб приєднатися." : 'Вас запросили до команди NexaFlow. Перевірте лист із запрошенням, щоб приєднатися.';
        $pdo->prepare("INSERT INTO notifications (id, user_id, sender_id, type, title, message, entity_type, entity_id) VALUES (?, ?, NULL, 'team_invite', ?, ?, 'invitation', ?)")
            ->execute([bin2hex(random_bytes(16)), $id, $title, $message, $invite['id']]);
    }
    $projectInvites = $pdo->prepare("SELECT i.id, i.project_id, i.group_id, p.name AS project_name, g.name AS group_name FROM project_invitations i JOIN projects p ON p.id = i.project_id LEFT JOIN project_groups g ON g.id = i.group_id WHERE i.email = ? AND i.status = 'pending' AND i.expires_at > NOW()");
    $projectInvites->execute([$email]);
    foreach ($projectInvites->fetchAll() as $invite) {
        $groupName = trim((string)($invite['group_name'] ?? ''));
        $message = $groupName !== ''
            ? "Вас запросили до групи «{$groupName}» у проєкті «{$invite['project_name']}». Перевірте лист із запрошенням."
            : "Вас запросили до проєкту «{$invite['project_name']}». Перевірте лист із запрошенням.";
        $pdo->prepare("INSERT INTO notifications (id, user_id, sender_id, type, title, message, entity_type, entity_id) VALUES (?, ?, NULL, 'project_invite', ?, ?, 'project', ?)")
            ->execute([bin2hex(random_bytes(16)), $id, 'Запрошення до проєкту', $message, $invite['project_id']]);
    }
    $sent = issueEmailVerification($pdo, $id, $email, $name);
    jsonResponse(true, $sent ? 'Акаунт створено. Перевірте пошту для підтвердження.' : 'Акаунт створено, але лист не вдалося відправити. Спробуйте повторити відправлення.', ['requires_verification' => true, 'email' => $email]);
} catch (Throwable $error) {
    error_log($error->getMessage());
    jsonResponse(false, 'Помилка бази даних під час реєстрації.', [], 500);
}
