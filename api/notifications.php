<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/mailer.php';

session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);

$pdo = db();
// Старі інсталяції можуть ще не мати полів рекламного показу — додаємо їх
// безпечно під час першого звернення до API.
$notificationColumns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME IN ('is_banner', 'banner_dismissed_at', 'banner_view_count')")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('is_banner', $notificationColumns, true)) $pdo->exec("ALTER TABLE notifications ADD is_banner TINYINT(1) NOT NULL DEFAULT 0 AFTER content_html");
if (!in_array('banner_dismissed_at', $notificationColumns, true)) $pdo->exec("ALTER TABLE notifications ADD banner_dismissed_at DATETIME NULL AFTER is_banner");
if (!in_array('banner_view_count', $notificationColumns, true)) $pdo->exec("ALTER TABLE notifications ADD banner_view_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER banner_dismissed_at");
// Зберігаємо історію сповіщень лише останні 30 днів.
// Очищення виконується перед кожним зверненням до API, тому не потребує
// окремого ручного запуску для користувачів, які відкривають сповіщення.
$pdo->exec("DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
$userId = (string)$_SESSION['user_id'];
$userQuery = $pdo->prepare('SELECT id, name, role FROM users WHERE id = ? LIMIT 1');
$userQuery->execute([$userId]);
$user = $userQuery->fetch();
if (!$user) jsonResponse(false, 'Користувача не знайдено.', [], 401);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$data = $method === 'POST' ? body() : [];

try {
    if ($method === 'GET') {
        $query = $pdo->prepare('SELECT id, type, title, message, content_html, entity_type, entity_id, is_read, is_banner, banner_dismissed_at, banner_view_count, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 40');
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

    if ($action === 'dismiss_banner') {
        $notificationId = trim((string)($data['id'] ?? ''));
        if ($notificationId === '') jsonResponse(false, 'Сповіщення не вказано.', [], 422);
        $update = $pdo->prepare('UPDATE notifications SET banner_dismissed_at = NOW() WHERE id = ? AND user_id = ? AND is_banner = 1');
        $update->execute([$notificationId, $userId]);
        jsonResponse(true, 'Банер більше не показуватиметься.');
    }

    if ($action === 'banner_viewed') {
        $notificationId = trim((string)($data['id'] ?? ''));
        if ($notificationId === '') jsonResponse(false, 'Сповіщення не вказано.', [], 422);
        $update = $pdo->prepare('UPDATE notifications SET banner_view_count = LEAST(banner_view_count + 1, 3) WHERE id = ? AND user_id = ? AND is_banner = 1 AND banner_dismissed_at IS NULL AND banner_view_count < 3');
        $update->execute([$notificationId, $userId]);
        jsonResponse(true, 'Показ банера зафіксовано.');
    }

    if ($action === 'broadcast') {
        if (!in_array($user['role'], ['admin', 'owner'], true)) jsonResponse(false, 'Тільки адміністратор може відправляти повідомлення всім користувачам.', [], 403);
        $title = trim((string)($data['title'] ?? ''));
        $message = trim((string)($data['message'] ?? ''));
        $contentHtml = trim((string)($data['html'] ?? ''));
        $isBanner = !empty($data['is_banner']) ? 1 : 0;
        $sendEmail = !empty($data['send_email']);
        $audiences = $data['audiences'] ?? [$data['audience'] ?? 'all'];
        if (!is_array($audiences)) $audiences = [$audiences];
        $audiences = array_values(array_unique(array_filter(array_map('strval', $audiences), static fn(string $value): bool => in_array($value, ['all', 'admin', 'manager', 'user'], true))));
        if (!$audiences) $audiences = ['all'];
        if (in_array('all', $audiences, true)) $audiences = ['all'];
        if ($title === '' || ($message === '' && $contentHtml === '')) jsonResponse(false, 'Вкажіть заголовок і текст повідомлення.', [], 422);
        if ($message === '') $message = trim(preg_replace('/\s+/', ' ', strip_tags($contentHtml)) ?? '');
        if (mb_strlen($title) > 180) $title = mb_substr($title, 0, 180);
        if (in_array('all', $audiences, true)) {
            $users = $pdo->query('SELECT id, email FROM users')->fetchAll();
        } else {
            $placeholders = implode(',', array_fill(0, count($audiences), '?'));
            $usersQuery = $pdo->prepare("SELECT id, email FROM users WHERE role IN ({$placeholders})");
            $usersQuery->execute($audiences);
            $users = $usersQuery->fetchAll();
        }
        $insert = $pdo->prepare("INSERT INTO notifications (id, user_id, sender_id, type, title, message, content_html, is_banner) VALUES (?, ?, ?, 'broadcast', ?, ?, ?, ?)");
        $emailSent = 0;
        foreach ($users as $recipient) {
            $insert->execute([bin2hex(random_bytes(16)), $recipient['id'], $userId, $title, $message, $contentHtml !== '' ? $contentHtml : null, $isBanner]);
            if ($sendEmail && sendUserNotificationEmail($pdo, (string)$recipient['id'], (string)$recipient['email'], $title . ' — NexaFlow', $message, notificationEmailHtml($title, $contentHtml !== '' ? $contentHtml : '<p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'))) {
                $emailSent++;
            }
        }
        $audienceNames = ['all' => 'всім користувачам', 'admin' => 'адміністраторам', 'manager' => 'менеджерам', 'user' => 'користувачам'];
        $audienceName = implode(', ', array_map(static fn(string $value): string => $audienceNames[$value], $audiences));
        jsonResponse(true, "Повідомлення відправлено {$audienceName}.", ['recipients' => count($users), 'audiences' => $audiences, 'email_requested' => $sendEmail, 'email_sent' => $emailSent]);
    }

    jsonResponse(false, 'Невідома дія.', [], 400);
} catch (Throwable $error) {
    error_log($error->getMessage());
    jsonResponse(false, 'Помилка під час роботи зі сповіщеннями.', [], 500);
}

function notificationEmailHtml(string $title, string $content): string
{
    return '<!doctype html><html lang="uk"><body style="margin:0;background:#f4f8f5;padding:28px 12px;font-family:Arial,sans-serif;color:#17221e"><div style="max-width:620px;margin:0 auto;background:#fff;border:1px solid #dce9e1;border-radius:18px;overflow:hidden"><div style="background:#080b0a;padding:24px;text-align:center;color:#fff"><div style="font-size:24px;font-weight:800;letter-spacing:1px;color:#34d49e">NexaFlow</div><div style="margin-top:5px;font-size:11px;color:#c9d8d1">SRM SYSTEM</div></div><div style="padding:30px"><div style="font-size:11px;font-weight:700;letter-spacing:1px;color:#168b69">НОВЕ ПОВІДОМЛЕННЯ</div><h1 style="font-size:25px;line-height:1.25;margin:12px 0 18px">' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1><div style="font-size:15px;line-height:1.65;color:#566961">' . $content . '</div><div style="margin-top:26px;padding-top:18px;border-top:1px solid #e3ece7;font-size:11px;color:#91a099">Це повідомлення надіслано з NexaFlow.</div></div></div></body></html>';
}
