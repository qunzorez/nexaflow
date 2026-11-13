<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/mailer.php';
$data = body(); $email = strtolower(trim((string)($data['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse(false, 'Введіть коректну пошту.', [], 422);
$pdo = db(); $query = $pdo->prepare('SELECT id, name, email FROM users WHERE email = ? LIMIT 1'); $query->execute([$email]); $user = $query->fetch();
if (!$user) jsonResponse(true, 'Якщо акаунт існує, інструкції вже відправлено на пошту.');
$token = bin2hex(random_bytes(32)); $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([$user['id']]); $pdo->prepare('INSERT INTO password_reset_tokens (id, user_id, token_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))')->execute([bin2hex(random_bytes(16)), $user['id'], hash('sha256', $token)]);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http'; $host = $_SERVER['HTTP_HOST'] ?? 'nexaflow.test'; $link = appUrl('/reset-password?token=') . urlencode($token); $subject = 'Відновлення пароля NexaFlow'; $message = "Відкрийте посилання для зміни пароля: {$link}\nПосилання дійсне 1 годину."; $html = '<div style="font-family:Arial,sans-serif;max-width:560px;padding:28px;color:#17201e"><h1 style="color:#1f8a68">NexaFlow</h1><h2>Відновлення пароля</h2><p>Натисніть кнопку, щоб створити новий пароль.</p><p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:13px 20px;background:#1f8a68;color:#fff;text-decoration:none;border-radius:8px">Змінити пароль</a></p><p style="color:#718078;font-size:13px">Посилання дійсне 1 годину.</p></div>';
sendProjectEmail($email, $subject, $message, $html); jsonResponse(true, 'Якщо акаунт існує, інструкції вже відправлено на пошту.');
