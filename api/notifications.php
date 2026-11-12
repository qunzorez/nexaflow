<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);

$pdo = db();
$userId = (string)$_SESSION['user_id'];
$userQuery = $pdo->prepare('SELECT id, name, role FROM users WHERE id = ? LIMIT 1');
$userQuery->execute([$userId]);
$user = $userQuery->fetch();
if (!$user) jsonResponse(false, 'Користувача не знайдено.', [], 401);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$data = $method === 'POST' ? body() : [];

try {
    if ($method === 'GET') {
        $query = $pdo->prepare('SELECT id, type, title, message, content_html, entity_type, entity_id, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 40');
        $query->execute([$userId]);
        $notifications = $query->fetchAll();
        $unreadQuery = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $unreadQuery->execute([$userId]);
        $audienceCounts = $pdo->query("SELECT role, COUNT(*) AS total FROM users GROUP BY role")->fetchAll();
        jsonResponse(true, 'Сповіщення завантажено.', ['notifications' => $notifications, 'unread' => (int)$unreadQuery->fetchColumn(), 'can_broadcast' => in_array($user['role'], ['admin', 'owner'], true), 'audience_counts' => $audienceCounts]);
    }

    if ($method !== 'POST') jsonResponse(false, 'Метод не підтримується.', [], 405);
    $action = (string)($data['action'] ?? '');

    if ($action === 'read') {
        $notificationId = trim((string)($data['id'] ?? ''));
        if ($notificationId === '') jsonResponse(false, 'Сповіщення не вказано.', [], 422);
        $update = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
        $update->execute([$notificationId, $userId]);
        jsonResponse(true, 'Сповіщення позначено прочитаним.');
    }

    if ($action === 'read_all') {
        $update = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?');
        $update->execute([$userId]);
        jsonResponse(true, 'Усі сповіщення прочитані.');
    }

    if ($action === 'broadcast') {
        if (!in_array($user['role'], ['admin', 'owner'], true)) jsonResponse(false, 'Тільки адміністратор може відправляти повідомлення всім користувачам.', [], 403);
        $title = trim((string)($data['title'] ?? ''));
        $message = trim((string)($data['message'] ?? ''));
        $contentHtml = trim((string)($data['html'] ?? ''));
        $audiences = $data['audiences'] ?? [$data['audience'] ?? 'all'];
        if (!is_array($audiences)) $audiences = [$audiences];
        $audiences = array_values(array_unique(array_filter(array_map('strval', $audiences), static fn(string $value): bool => in_array($value, ['all', 'admin', 'manager', 'user'], true))));
        if (!$audiences) $audiences = ['all'];
        if (in_array('all', $audiences, true)) $audiences = ['all'];
        if ($title === '' || ($message === '' && $contentHtml === '')) jsonResponse(false, 'Вкажіть заголовок і текст повідомлення.', [], 422);
        if ($message === '') $message = trim(preg_replace('/\s+/', ' ', strip_tags($contentHtml)) ?? '');
        if (mb_strlen($title) > 180) $title = mb_substr($title, 0, 180);
        if (in_array('all', $audiences, true)) {
            $users = $pdo->query('SELECT id FROM users')->fetchAll();
        } else {
            $placeholders = implode(',', array_fill(0, count($audiences), '?'));
            $usersQuery = $pdo->prepare("SELECT id FROM users WHERE role IN ({$placeholders})");
            $usersQuery->execute($audiences);
            $users = $usersQuery->fetchAll();
        }
        $insert = $pdo->prepare("INSERT INTO notifications (id, user_id, sender_id, type, title, message, content_html) VALUES (?, ?, ?, 'broadcast', ?, ?, ?)");
        foreach ($users as $recipient) {
            $insert->execute([bin2hex(random_bytes(16)), $recipient['id'], $userId, $title, $message, $contentHtml !== '' ? $contentHtml : null]);
        }
        $audienceNames = ['all' => 'всім користувачам', 'admin' => 'адміністраторам', 'manager' => 'менеджерам', 'user' => 'користувачам'];
        $audienceName = implode(', ', array_map(static fn(string $value): string => $audienceNames[$value], $audiences));
        jsonResponse(true, "Повідомлення відправлено {$audienceName}.", ['recipients' => count($users), 'audiences' => $audiences]);
    }

    jsonResponse(false, 'Невідома дія.', [], 400);
} catch (Throwable $error) {
    error_log($error->getMessage());
    jsonResponse(false, 'Помилка під час роботи зі сповіщеннями.', [], 500);
}
