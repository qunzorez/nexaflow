<?php
declare(strict_types=1);
require __DIR__ . '/telegram.php';

$update = json_decode(file_get_contents('php://input'), true);
$message = is_array($update) ? ($update['message'] ?? null) : null;
if (!is_array($message)) { http_response_code(200); echo 'ok'; exit; }
$chat = $message['chat'] ?? [];
$chatId = (string)($chat['id'] ?? '');
$username = strtolower(trim((string)($message['from']['username'] ?? '')));
$text = trim((string)($message['text'] ?? ''));
$connectToken = strpos($text, '/start ') === 0 ? trim(substr($text, 7)) : '';
if ($chatId === '') { http_response_code(200); echo 'ok'; exit; }

if ($text === '/start' || strpos($text, '/start ') === 0) {
    sendTelegramMessage($chatId, "NexaFlow підключено ✅\n\nТепер ви отримуватимете сповіщення про призначені задачі та перевірки.");
} elseif ($text === '/help') {
    sendTelegramMessage($chatId, "NexaFlow надсилає сюди робочі сповіщення. Керування виконується у вашому профілі на сайті.");
}
try {
    require __DIR__ . '/db.php';
    $pdo = db();
    $userId = '';
    if ($connectToken !== '') {
        $query = $pdo->prepare('SELECT id FROM users WHERE telegram_connect_token = ? LIMIT 1');
        $query->execute([$connectToken]);
        $userId = (string)($query->fetchColumn() ?: '');
    }
    if ($userId === '' && $username !== '') {
        $query = $pdo->prepare('SELECT id FROM users WHERE LOWER(REPLACE(telegram_tag, "@", "")) = ? LIMIT 1');
        $query->execute([$username]);
        $userId = $query->fetchColumn();
    }
    if ($userId) $pdo->prepare('UPDATE users SET telegram_chat_id = ?, telegram_notifications_enabled = 1 WHERE id = ?')->execute([$chatId, $userId]);
} catch (Throwable $error) {
    error_log('Telegram webhook database error: ' . $error->getMessage());
}
http_response_code(200); echo 'ok';
