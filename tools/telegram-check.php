<?php
declare(strict_types=1);

require __DIR__ . '/../api/db.php';

$email = '';
$sendTest = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--send') $sendTest = true;
    elseif ($argument !== '') $email = trim($argument);
}

function line(string $label, string $value): void { echo str_pad($label, 28) . $value . PHP_EOL; }
function telegramRequest(string $token, string $method): array
{
    if (!function_exists('curl_init')) return ['ok' => false, 'description' => 'PHP cURL не увімкнений'];
    $curl = curl_init('https://api.telegram.org/bot' . rawurlencode($token) . '/' . $method);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
    $body = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $result = is_string($body) ? json_decode($body, true) : null;
    return is_array($result) ? $result + ['http_code' => $status] : ['ok' => false, 'description' => $error ?: 'Некоректна відповідь Telegram', 'http_code' => $status];
}
function sendTest(string $token, string $chatId): array
{
    $curl = curl_init('https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage');
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['chat_id' => $chatId, 'text' => "NexaFlow ✅\n\nЦе тестове повідомлення. Telegram-підключення працює."], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
    $body = curl_exec($curl); $error = curl_error($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
    $result = is_string($body) ? json_decode($body, true) : null;
    return is_array($result) ? $result + ['http_code' => $status] : ['ok' => false, 'description' => $error ?: 'Некоректна відповідь Telegram', 'http_code' => $status];
}

echo "NexaFlow Telegram diagnostic\n============================\n";
if ($email === '') { echo "Використання: php tools/telegram-check.php user@example.com [--send]\n"; exit(1); }

try {
    $localConfig = __DIR__ . '/../api/config.local.php';
    $config = require file_exists($localConfig) ? $localConfig : (__DIR__ . '/../api/config.php');
    $token = trim((string)($config['telegram_bot_token'] ?? ''));
    line('Email', $email);
    line('Token', $token !== '' ? 'налаштований' : 'ВІДСУТНІЙ');
    if ($token === '') { echo "\nПричина: у config.local.php відсутній telegram_bot_token.\n"; exit(2); }
    $me = telegramRequest($token, 'getMe');
    line('Telegram API', !empty($me['ok']) ? 'OK' : 'ПОМИЛКА: ' . (string)($me['description'] ?? 'невідома'));
    $webhook = telegramRequest($token, 'getWebhookInfo');
    if (!empty($webhook['ok'])) {
        $info = $webhook['result'] ?? [];
        line('Webhook URL', (string)($info['url'] ?? 'не заданий'));
        line('Pending updates', (string)($info['pending_update_count'] ?? 0));
        line('Webhook error', (string)($info['last_error_message'] ?? 'немає'));
    }

    $pdo = db();
    $columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'")->fetchAll(PDO::FETCH_COLUMN);
    $needed = ['telegram_tag', 'telegram_chat_id', 'telegram_notifications_enabled', 'telegram_connect_token'];
    foreach ($needed as $column) line('Column ' . $column, in_array($column, $columns, true) ? 'є' : 'ВІДСУТНЯ');
    $select = ['id', 'name', 'email'];
    foreach (['telegram_tag', 'telegram_chat_id', 'telegram_notifications_enabled', 'telegram_connect_token'] as $column) if (in_array($column, $columns, true)) $select[] = $column;
    $query = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
    $query->execute([$email]);
    $user = $query->fetch();
    if (!$user) { line('Користувач', 'НЕ ЗНАЙДЕНИЙ'); exit(3); }
    line('Користувач', (string)$user['name']);
    line('Telegram tag', (string)($user['telegram_tag'] ?? 'порожній'));
    line('Chat ID', (string)($user['telegram_chat_id'] ?? 'порожній'));
    line('Notifications', (int)($user['telegram_notifications_enabled'] ?? 0) === 1 ? 'УВІМКНЕНО' : 'ВИМКНЕНО');
    if ($sendTest) {
        $chatId = trim((string)($user['telegram_chat_id'] ?? ''));
        if ($chatId === '') { echo "\nТест не відправлено: chat ID порожній. Натисніть «Підключити Telegram» і /start.\n"; exit(4); }
        $sent = sendTest($token, $chatId);
        echo "\nТестове повідомлення: " . (!empty($sent['ok']) ? 'ВІДПРАВЛЕНО' : 'ПОМИЛКА') . PHP_EOL;
        if (empty($sent['ok'])) echo 'Telegram: ' . (string)($sent['description'] ?? 'невідома помилка') . PHP_EOL;
    }
} catch (Throwable $error) {
    echo "\nПомилка діагностики: " . $error->getMessage() . PHP_EOL;
    exit(5);
}
