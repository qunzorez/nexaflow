<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/verification.php';
require_once __DIR__ . '/rate-limit.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonResponse(false, 'Метод не підтримується.', [], 405);
$data = body(); $email = strtolower(trim((string)($data['email'] ?? ''))); if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse(false, 'Введіть коректну пошту.', [], 422); $pdo = db(); enforcePublicRateLimit($pdo, 'resend_verification', $email, 10, 3, 3600); $query = $pdo->prepare('SELECT id, name, email, email_verified_at FROM users WHERE email = ? LIMIT 1'); $query->execute([$email]); $user = $query->fetch(); if ($user && !$user['email_verified_at']) issueEmailVerification($pdo, $user['id'], $user['email'], $user['name']); jsonResponse(true, 'Якщо адреса потребує підтвердження, лист буде надіслано.');
