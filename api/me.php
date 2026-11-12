<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

session_start();
if (empty($_SESSION['user_id'])) {
    jsonResponse(false, 'Сесія не знайдена.', [], 401);
}

jsonResponse(true, 'Користувач авторизований.', [
    'user' => ['name' => $_SESSION['user_name'] ?? '', 'id' => $_SESSION['user_id']],
]);
