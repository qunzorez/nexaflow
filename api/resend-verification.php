<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/verification.php';
$data = body(); $email = strtolower(trim((string)($data['email'] ?? ''))); if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse(false, 'Введіть коректну пошту.', [], 422); $pdo = db(); $query = $pdo->prepare('SELECT id, name, email, email_verified_at FROM users WHERE email = ? LIMIT 1'); $query->execute([$email]); $user = $query->fetch(); if (!$user || $user['email_verified_at']) jsonResponse(true, 'Якщо акаунт потребує підтвердження, новий лист уже відправлено.'); $sent = issueEmailVerification($pdo, $user['id'], $user['email'], $user['name']); jsonResponse(true, $sent ? 'Лист відправлено повторно.' : 'Не вдалося відправити лист.');
