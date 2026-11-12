<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET'; $token = trim((string)($_GET['token'] ?? ($_POST['token'] ?? ''))); if ($method === 'POST') { $data = body(); $token = trim((string)($data['token'] ?? '')); }
if ($token === '') jsonResponse(false, 'Посилання для відновлення відсутнє.', [], 422);
$pdo = db(); $query = $pdo->prepare('SELECT id, user_id FROM password_reset_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1'); $query->execute([hash('sha256', $token)]); $reset = $query->fetch(); if (!$reset) jsonResponse(false, 'Посилання недійсне або вже завершилось.', [], 410);
if ($method === 'GET') jsonResponse(true, 'Посилання дійсне.');
$data = body(); $password = (string)($data['password'] ?? ''); if (strlen($password) < 8) jsonResponse(false, 'Пароль має містити щонайменше 8 символів.', [], 422); $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]); $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?')->execute([$reset['id']]); jsonResponse(true, 'Пароль змінено. Тепер можна увійти.');
