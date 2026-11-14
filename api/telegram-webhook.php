<?php
declare(strict_types=1);
require __DIR__ . '/telegram.php';

$update = json_decode(file_get_contents('php://input'), true);
$message = is_array($update) ? ($update['message'] ?? null) : null;
if (!is_array($message)) { http_response_code(200); echo 'ok'; exit; }
$chat = $message['chat'] ?? [];
$chatId = (string)($chat['id'] ?? '');
$text = trim((string)($message['text'] ?? ''));
$connectToken = strpos($text, '/start ') === 0 ? trim(substr($text, 7)) : '';
if ($chatId === '') { http_response_code(200); echo 'ok'; exit; }
// Do not use the public Telegram username or unverified webhook payload to
// link accounts. Only the high-entropy, per-user connect token is authoritative.
try {
    require __DIR__ . '/db.php';
    $pdo = db();
    $userId = '';
    $hasConnectToken = (bool)$pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'telegram_connect_token'")->fetchColumn();
    if ($hasConnectToken && $connectToken !== '') {
        $query = $pdo->prepare('SELECT id FROM users WHERE telegram_connect_token = ? LIMIT 1');
        $query->execute([$connectToken]);
        $userId = (string)($query->fetchColumn() ?: '');
    }
    if ($userId && ($text === '/start' || strpos($text, '/start ') === 0)) {
        if (telegramPlanAvailable($pdo, $userId)) {
            $pdo->prepare('UPDATE users SET telegram_chat_id = ?, telegram_notifications_enabled = 1 WHERE id = ?')->execute([$chatId, $userId]);
            sendTelegramMessage($chatId, "NexaFlow • Telegram підключено ✅\n\n🎉 Чат під’єднано до вашого профілю. Сюди надходитимуть сповіщення про задачі, нагадування та перевірки. Керувати ними можна в налаштуваннях NexaFlow.");
        } else {
            sendTelegramMessage($chatId, 'Telegram-сповіщення доступні починаючи з плану «Зростання». Перегляньте тарифи в налаштуваннях NexaFlow.');
        }
    }
} catch (Throwable $error) {
    error_log('Telegram webhook database error: ' . $error->getMessage());
}
http_response_code(200); echo 'ok';
