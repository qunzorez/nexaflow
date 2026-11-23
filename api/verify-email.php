<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_once __DIR__ . '/rate-limit.php';
$token = trim((string)($_GET['token'] ?? ''));
if (!preg_match('/^[a-f0-9]{64}$/', $token)) jsonResponse(false, 'Посилання недійсне.', ['code' => 'invalid_token'], 422);
$pdo = db();
enforcePublicRateLimit($pdo, 'verify_email', '', 60, 60, 900);
$pdo->beginTransaction();
try {
    $query = $pdo->prepare('SELECT id, user_id, verified_at, (expires_at <= NOW()) AS is_expired FROM email_verification_tokens WHERE token_hash = ? LIMIT 1 FOR UPDATE');
    $query->execute([hash('sha256', $token)]);
    $row = $query->fetch();
    if (!$row) { $pdo->rollBack(); jsonResponse(false, 'Посилання недійсне.', ['code' => 'invalid_token'], 410); }
    if ($row['verified_at']) { $pdo->rollBack(); jsonResponse(false, 'Це посилання вже використано.', ['code' => 'used_token'], 410); }
    if ($row['is_expired']) { $pdo->rollBack(); jsonResponse(false, 'Термін дії посилання завершився.', ['code' => 'expired_token'], 410); }
    $userQuery = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
    $userQuery->execute([(string)$row['user_id']]);
    if (!$userQuery->fetch()) { $pdo->rollBack(); jsonResponse(false, 'Посилання недійсне.', ['code' => 'invalid_token'], 410); }
    $pdo->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = ?')->execute([(string)$row['user_id']]);
    $pdo->prepare('UPDATE email_verification_tokens SET verified_at = NOW() WHERE id = ? AND verified_at IS NULL')->execute([(string)$row['id']]);
    $pdo->commit();
    jsonResponse(true, 'Email успішно підтверджено.');
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
