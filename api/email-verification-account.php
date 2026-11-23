<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_once __DIR__ . '/verification.php';
require_once __DIR__ . '/rate-limit.php';

session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonResponse(false, 'Метод не підтримується.', [], 405);
$pdo = db();
$userId = (string)$_SESSION['user_id'];
$query = $pdo->prepare('SELECT id, name, email, email_verified_at, password_hash FROM users WHERE id = ? LIMIT 1');
$query->execute([$userId]);
$user = $query->fetch();
if (!$user) jsonResponse(false, 'Користувача не знайдено.', [], 401);
$data = body();
$action = (string)($data['action'] ?? '');

if ($action === 'resend') {
    if ($user['email_verified_at']) jsonResponse(false, 'Email уже підтверджено.', [], 409);
    enforcePublicRateLimit($pdo, 'account_verification_' . $userId, (string)$user['email'], 10, 3, 3600);
    $sent = issueEmailVerification($pdo, $userId, (string)$user['email'], (string)$user['name']);
    jsonResponse($sent, $sent ? 'Лист підтвердження надіслано. Перевірте також папку «Спам».' : 'Не вдалося надіслати лист. Спробуйте пізніше.', [], $sent ? 200 : 502);
}
if ($action === 'change_email') {
    $email = mb_strtolower(trim((string)($data['email'] ?? '')));
    $password = (string)($data['current_password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) jsonResponse(false, 'Введіть коректну адресу email.', [], 422);
    if (!password_verify($password, (string)$user['password_hash'])) jsonResponse(false, 'Поточний пароль неправильний.', [], 422);
    if ($email === mb_strtolower((string)$user['email'])) jsonResponse(false, 'Введіть нову адресу email.', [], 422);
    enforcePublicRateLimit($pdo, 'account_email_change_' . $userId, $email, 10, 3, 3600);
    try {
        $pdo->beginTransaction();
        $update = $pdo->prepare('UPDATE users SET email = ?, email_verified_at = NULL WHERE id = ? AND email = ?');
        $update->execute([$email, $userId, $user['email']]);
        if ($update->rowCount() !== 1) { $pdo->rollBack(); jsonResponse(false, 'Адреса змінилася. Оновіть сторінку.', [], 409); }
        $pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id = ?')->execute([$userId]);
        $pdo->commit();
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((int)($error->errorInfo[1] ?? 0) === 1062) jsonResponse(false, 'Ця адреса вже використовується.', [], 409);
        throw $error;
    }
    $sent = issueEmailVerification($pdo, $userId, $email, (string)$user['name']);
    jsonResponse(true, $sent ? 'Адресу змінено. Лист підтвердження надіслано на нову пошту.' : 'Адресу змінено, але лист не вдалося надіслати. Повторіть надсилання в налаштуваннях.', ['email' => $email, 'email_verified_at' => null, 'email_sent' => $sent]);
}
jsonResponse(false, 'Невідома дія.', [], 422);
