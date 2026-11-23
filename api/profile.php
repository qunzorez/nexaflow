<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_once __DIR__ . '/plans.php';
session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);
$pdo = db(); ensurePlanSchema($pdo); $userId = (string)$_SESSION['user_id'];
$columnsQuery = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME IN ('phone', 'telegram_tag', 'telegram_chat_id', 'telegram_notifications_enabled', 'email_notifications_enabled', 'telegram_connect_token', 'bio')");
$columnsQuery->execute(); $existingColumns = array_column($columnsQuery->fetchAll(), 'COLUMN_NAME');
if (!in_array('phone', $existingColumns, true)) $pdo->exec("ALTER TABLE users ADD phone VARCHAR(40) NULL");
if (!in_array('telegram_tag', $existingColumns, true)) $pdo->exec("ALTER TABLE users ADD telegram_tag VARCHAR(80) NULL");
if (!in_array('telegram_chat_id', $existingColumns, true)) $pdo->exec("ALTER TABLE users ADD telegram_chat_id VARCHAR(80) NULL");
if (!in_array('telegram_notifications_enabled', $existingColumns, true)) { $pdo->exec("ALTER TABLE users ADD telegram_notifications_enabled TINYINT(1) NOT NULL DEFAULT 0"); $pdo->exec("UPDATE users SET telegram_notifications_enabled = 1 WHERE telegram_chat_id IS NOT NULL AND telegram_chat_id <> ''"); }
if (!in_array('email_notifications_enabled', $existingColumns, true)) $pdo->exec("ALTER TABLE users ADD email_notifications_enabled TINYINT(1) NOT NULL DEFAULT 1");
if (!in_array('telegram_connect_token', $existingColumns, true)) $pdo->exec("ALTER TABLE users ADD telegram_connect_token VARCHAR(64) NULL");
if (!in_array('bio', $existingColumns, true)) $pdo->exec("ALTER TABLE users ADD bio TEXT NULL");
$query = $pdo->prepare('SELECT id, name, email, email_verified_at, role, is_support_agent, avatar_url, phone, telegram_tag, telegram_chat_id, telegram_notifications_enabled, email_notifications_enabled, telegram_connect_token, bio, password_hash FROM users WHERE id = ? LIMIT 1'); $query->execute([$userId]); $user = $query->fetch();
if (!$user) jsonResponse(false, 'Користувача не знайдено.', [], 401);
$planContext = workspacePlan($pdo, $userId, (string)$user['role']);
$user['workspaces'] = $planContext['workspaces']; $user['active_workspace_id'] = $planContext['owner_id']; $user['workspace_role'] = $planContext['workspace_role'];
$telegramAllowed = !empty($user['email_verified_at']) && !empty($planContext['plan']['telegram']);
if ($telegramAllowed && empty($user['telegram_connect_token'])) { $user['telegram_connect_token'] = bin2hex(random_bytes(24)); $pdo->prepare('UPDATE users SET telegram_connect_token = ? WHERE id = ?')->execute([$user['telegram_connect_token'], $userId]); }
$user['telegram_connect_url'] = $telegramAllowed ? 'https://t.me/nexaflows_bot?start=' . $user['telegram_connect_token'] : null;
if (!$telegramAllowed) $user['telegram_connect_token'] = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') { if (!$telegramAllowed) $user['telegram_notifications_enabled'] = 0; unset($user['password_hash']); jsonResponse(true, 'Профіль завантажено.', ['user' => $user, 'subscription' => planSettingsPayload($planContext)]); }
$data = body(); $name = trim((string)($data['name'] ?? '')); $avatarUrl = trim((string)($data['avatar_url'] ?? '')); $phone = trim((string)($data['phone'] ?? '')); $telegramTag = trim((string)($data['telegram_tag'] ?? '')); $telegramChatId = trim((string)($data['telegram_chat_id'] ?? '')); if ($telegramChatId === '') $telegramChatId = trim((string)($user['telegram_chat_id'] ?? '')); $telegramEnabled = $telegramAllowed && !empty($data['telegram_notifications_enabled']) ? 1 : 0; $emailEnabled = array_key_exists('email_notifications_enabled', $data) ? (!empty($data['email_notifications_enabled']) ? 1 : 0) : (int)($user['email_notifications_enabled'] ?? 1); $bio = trim((string)($data['bio'] ?? '')); $currentPassword = (string)($data['current_password'] ?? ''); $newPassword = (string)($data['new_password'] ?? '');
if ($name === '') jsonResponse(false, 'Введіть ім’я.', [], 422);
if ($avatarUrl !== '' && substr($avatarUrl, 0, 11) !== 'data:image/') jsonResponse(false, 'Фото профілю має бути зображенням.', [], 422);
if (strlen($avatarUrl) > 7 * 1024 * 1024) jsonResponse(false, 'Фото профілю завелике. Максимальний розмір - 5 МБ.', [], 422);
if (strlen($phone) > 40) jsonResponse(false, 'Номер телефону занадто довгий.', [], 422);
if (strlen($telegramTag) > 80) jsonResponse(false, 'Telegram-тег занадто довгий.', [], 422);
if (strlen($telegramChatId) > 80) jsonResponse(false, 'Telegram chat ID занадто довгий.', [], 422);
if (strlen($bio) > 1000) jsonResponse(false, 'Інформація про користувача не може бути довшою за 1000 символів.', [], 422);
if ($newPassword !== '') { if (strlen($newPassword) < 8) jsonResponse(false, 'Новий пароль має містити щонайменше 8 символів.', [], 422); if (!password_verify($currentPassword, $user['password_hash'])) jsonResponse(false, 'Поточний пароль введено неправильно.', [], 422); $pdo->prepare('UPDATE users SET name = ?, avatar_url = ?, phone = ?, telegram_tag = ?, telegram_chat_id = ?, telegram_notifications_enabled = ?, email_notifications_enabled = ?, bio = ?, password_hash = ? WHERE id = ?')->execute([$name, $avatarUrl ?: null, $phone ?: null, $telegramTag ?: null, $telegramChatId ?: null, $telegramEnabled, $emailEnabled, $bio ?: null, password_hash($newPassword, PASSWORD_DEFAULT), $userId]); } else { $pdo->prepare('UPDATE users SET name = ?, avatar_url = ?, phone = ?, telegram_tag = ?, telegram_chat_id = ?, telegram_notifications_enabled = ?, email_notifications_enabled = ?, bio = ? WHERE id = ?')->execute([$name, $avatarUrl ?: null, $phone ?: null, $telegramTag ?: null, $telegramChatId ?: null, $telegramEnabled, $emailEnabled, $bio ?: null, $userId]); }
$updated = $pdo->prepare('SELECT id, name, email, email_verified_at, role, avatar_url, phone, telegram_tag, telegram_chat_id, telegram_notifications_enabled, email_notifications_enabled, telegram_connect_token, bio FROM users WHERE id = ? LIMIT 1'); $updated->execute([$userId]); $updatedUser = $updated->fetch(); $updatedUser["is_support_agent"] = (int)$user["is_support_agent"]; if (!$telegramAllowed) $updatedUser['telegram_notifications_enabled'] = 0; $updatedUser['telegram_connect_url'] = $telegramAllowed ? 'https://t.me/nexaflows_bot?start=' . $updatedUser['telegram_connect_token'] : null; if (!$telegramAllowed) $updatedUser['telegram_connect_token'] = null; $updatedUser['workspaces'] = $planContext['workspaces']; $updatedUser['active_workspace_id'] = $planContext['owner_id']; $updatedUser['workspace_role'] = $planContext['workspace_role']; jsonResponse(true, 'Профіль оновлено.', ['user' => $updatedUser, 'subscription' => planSettingsPayload($planContext)]);
