<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

session_start();
if (empty($_SESSION['user_id'])) {
    jsonResponse(false, 'Сесія не знайдена.', [], 401);
}

$pdo = db();
$columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_seen_at'")->fetchAll(PDO::FETCH_COLUMN);
if (!$columns) $pdo->exec("ALTER TABLE users ADD last_seen_at DATETIME NULL");
$pdo->prepare('UPDATE users SET last_seen_at = NOW() WHERE id = ?')->execute([$_SESSION['user_id']]);
$query = $pdo->prepare('SELECT id, name, email, role, avatar_url FROM users WHERE id = ? LIMIT 1');
$query->execute([$_SESSION['user_id']]);
$user = $query->fetch();
if (!$user) jsonResponse(false, 'Користувача не знайдено.', [], 401);
jsonResponse(true, 'Користувач авторизований.', ['user' => $user]);
