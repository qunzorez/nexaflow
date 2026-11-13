<?php
declare(strict_types=1);

require __DIR__ . '/../api/db.php';

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

$users = [
    ['Олена Менеджер', 'a1000000000000000000000000000001'],
    ['Максим Менеджер', 'a1000000000000000000000000000002'],
    ['Ірина Менеджер', 'a1000000000000000000000000000003'],
];
$groups = [
    ['Операційний менеджер', 'b1000000000000000000000000000001'],
    ['Керівник проєктів', 'b1000000000000000000000000000002'],
    ['Фінансовий модератор', 'b1000000000000000000000000000003'],
];

$userUpdate = $pdo->prepare('UPDATE users SET name = ? WHERE id = ?');
foreach ($users as [$name, $id]) $userUpdate->execute([$name, $id]);

$groupUpdate = $pdo->prepare('UPDATE project_groups SET name = ? WHERE id = ?');
foreach ($groups as [$name, $id]) $groupUpdate->execute([$name, $id]);

echo "Локальні назви менеджерів і claims-груп виправлено.\n";
