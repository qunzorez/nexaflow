<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

session_start();
if (empty($_SESSION['user_id'])) {
    jsonResponse(false, 'Сесія не знайдена.', [], 401);
}

$query = db()->prepare('SELECT id, name, email, role FROM users WHERE id = ? LIMIT 1');
$query->execute([$_SESSION['user_id']]);
$user = $query->fetch();
if (!$user) jsonResponse(false, 'Користувача не знайдено.', [], 401);
jsonResponse(true, 'Користувач авторизований.', ['user' => $user]);
