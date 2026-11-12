<?php
declare(strict_types=1);

function sendTelegramMessage(string $chatId, string $message): bool
{
    if ($chatId === '') return false;
    $localConfig = __DIR__ . '/config.local.php';
    $config = require file_exists($localConfig) ? $localConfig : (__DIR__ . '/config.php');
    $token = trim((string)($config['telegram_bot_token'] ?? ''));
    if ($token === '' || !function_exists('curl_init')) return false;
    $curl = curl_init('https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage');
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['chat_id' => $chatId, 'text' => $message, 'disable_web_page_preview' => true], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
    $result = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
    return $status === 200 && is_string($result) && (json_decode($result, true)['ok'] ?? false) === true;
}

function sendTelegramToUser(PDO $pdo, string $userId, string $message): void
{
    try {
        static $columnReady = false;
        if (!$columnReady) {
            $columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'telegram_notifications_enabled'")->fetchColumn();
            if (!$columns) $pdo->exec("ALTER TABLE users ADD telegram_notifications_enabled TINYINT(1) NOT NULL DEFAULT 0");
            $columnReady = true;
        }
        $query = $pdo->prepare('SELECT telegram_chat_id, telegram_notifications_enabled FROM users WHERE id = ? LIMIT 1');
        $query->execute([$userId]);
        $user = $query->fetch();
        if (!$user || (int)$user['telegram_notifications_enabled'] !== 1) return;
        $chatId = (string)($user['telegram_chat_id'] ?? '');
        if ($chatId !== '') sendTelegramMessage($chatId, $message);
    } catch (Throwable $error) {
        error_log('Telegram notification error: ' . $error->getMessage());
    }
}
