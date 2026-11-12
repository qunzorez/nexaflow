<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/telegram.php';

$update = json_decode(file_get_contents('php://input'), true);
$message = is_array($update) ? ($update['message'] ?? null) : null;
if (!is_array($message)) { http_response_code(200); echo 'ok'; exit; }
$chat = $message['chat'] ?? [];
$chatId = (string)($chat['id'] ?? '');
$username = strtolower(trim((string)($message['from']['username'] ?? '')));
$text = trim((string)($message['text'] ?? ''));
if ($chatId === '') { http_response_code(200); echo 'ok'; exit; }

$pdo = db();
if ($username !== '') {
    $tag = '@' . ltrim($username, '@');
    $query = $pdo->prepare('SELECT id FROM users WHERE LOWER(REPLACE(telegram_tag, "@", "")) = ? LIMIT 1');
    $query->execute([$username]);
    $userId = $query->fetchColumn();
    if ($userId) $pdo->prepare('UPDATE users SET telegram_chat_id = ? WHERE id = ?')->execute([$chatId, $userId]);
}

if ($text === '/start' || str_starts_with($text, '/start ')) {
    sendTelegramMessage($chatId, "NexaFlow підключено ✅\n\nТепер ви отримуватимете сповіщення про призначені задачі та перевірки.");
} elseif ($text === '/help') {
    sendTelegramMessage($chatId, "NexaFlow надсилає сюди робочі сповіщення. Керування виконується у вашому профілі на сайті.");
}
http_response_code(200); echo 'ok';
