<?php
declare(strict_types=1);
require_once __DIR__ . '/mailer.php';

function issueEmailVerification(PDO $pdo, string $userId, string $email, string $name): bool
{
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('INSERT INTO email_verification_tokens (id, user_id, token_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))')->execute([bin2hex(random_bytes(16)), $userId, hash('sha256', $token)]);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'nexaflow.test';
    $link = "{$scheme}://{$host}/verify-email?token=" . urlencode($token);
    $subject = 'Підтвердіть email у NexaFlow';
    $message = "Вітаємо, {$name}! Підтвердіть email за посиланням: {$link}\nПосилання дійсне 24 години.";
    $html = '<div style="font-family:Arial,sans-serif;max-width:560px;padding:28px;color:#17201e"><h1 style="color:#1f8a68">NexaFlow</h1><h2>Підтвердіть вашу пошту</h2><p>Вітаємо, ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '!</p><p>Натисніть кнопку, щоб активувати акаунт.</p><p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:13px 20px;background:#1f8a68;color:#fff;text-decoration:none;border-radius:8px">Підтвердити email</a></p><p style="color:#718078;font-size:13px">Посилання дійсне 24 години.</p></div>';
    return sendProjectEmail($email, $subject, $message, $html);
}
