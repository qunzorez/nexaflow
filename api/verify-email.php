<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
$token = trim((string)($_GET['token'] ?? '')); if ($token === '') jsonResponse(false, 'Токен підтвердження відсутній.', [], 422); $pdo = db(); $query = $pdo->prepare('SELECT id, user_id FROM email_verification_tokens WHERE token_hash = ? AND verified_at IS NULL AND expires_at > NOW() LIMIT 1'); $query->execute([hash('sha256', $token)]); $row = $query->fetch(); if (!$row) jsonResponse(false, 'Посилання недійсне або вже завершилось.', [], 410); $pdo->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = ?')->execute([$row['user_id']]); $pdo->prepare('UPDATE email_verification_tokens SET verified_at = NOW() WHERE id = ?')->execute([$row['id']]); jsonResponse(true, 'Email успішно підтверджено.');
