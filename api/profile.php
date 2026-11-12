<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);
$pdo = db(); $userId = (string)$_SESSION['user_id'];
$query = $pdo->prepare('SELECT id, name, email, role, avatar_url, password_hash FROM users WHERE id = ? LIMIT 1'); $query->execute([$userId]); $user = $query->fetch();
if (!$user) jsonResponse(false, 'Користувача не знайдено.', [], 401);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') { unset($user['password_hash']); jsonResponse(true, 'Профіль завантажено.', ['user' => $user]); }
$data = body(); $name = trim((string)($data['name'] ?? '')); $avatarUrl = trim((string)($data['avatar_url'] ?? '')); $currentPassword = (string)($data['current_password'] ?? ''); $newPassword = (string)($data['new_password'] ?? '');
if ($name === '') jsonResponse(false, 'Введіть ім’я.', [], 422);
if ($avatarUrl !== '' && substr($avatarUrl, 0, 11) !== 'data:image/') jsonResponse(false, 'Фото профілю має бути зображенням.', [], 422);
if (strlen($avatarUrl) > 7 * 1024 * 1024) jsonResponse(false, 'Фото профілю завелике. Максимальний розмір — 5 МБ.', [], 422);
if ($newPassword !== '') { if (strlen($newPassword) < 8) jsonResponse(false, 'Новий пароль має містити щонайменше 8 символів.', [], 422); if (!password_verify($currentPassword, $user['password_hash'])) jsonResponse(false, 'Поточний пароль введено неправильно.', [], 422); $pdo->prepare('UPDATE users SET name = ?, avatar_url = ?, password_hash = ? WHERE id = ?')->execute([$name, $avatarUrl ?: null, password_hash($newPassword, PASSWORD_DEFAULT), $userId]); } else { $pdo->prepare('UPDATE users SET name = ?, avatar_url = ? WHERE id = ?')->execute([$name, $avatarUrl ?: null, $userId]); }
$updated = $pdo->prepare('SELECT id, name, email, role, avatar_url FROM users WHERE id = ? LIMIT 1'); $updated->execute([$userId]); jsonResponse(true, 'Профіль оновлено.', ['user' => $updated->fetch()]);
