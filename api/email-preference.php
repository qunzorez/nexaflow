<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
session_start();
header('Cache-Control: no-store');
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Метод не підтримується.', [], 405);
$data = body();
if (!isset($data['enabled']) || !is_bool($data['enabled'])) jsonResponse(false, 'Некоректне налаштування.', [], 422);
$pdo = db();
$update = $pdo->prepare('UPDATE users SET email_notifications_enabled = ? WHERE id = ?');
$update->execute([$data['enabled'] ? 1 : 0, $_SESSION['user_id']]);
$query = $pdo->prepare('SELECT email_notifications_enabled FROM users WHERE id = ?');
$query->execute([$_SESSION['user_id']]);
$value = $query->fetchColumn();
if ($value === false) jsonResponse(false, 'Користувача не знайдено.', [], 401);
jsonResponse(true, 'Налаштування email збережено.', ['enabled' => (int)$value === 1]);
