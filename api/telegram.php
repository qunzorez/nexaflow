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
    $query = $pdo->prepare('SELECT telegram_chat_id FROM users WHERE id = ? LIMIT 1');
    $query->execute([$userId]);
    $chatId = (string)($query->fetchColumn() ?: '');
    if ($chatId !== '') sendTelegramMessage($chatId, $message);
}
