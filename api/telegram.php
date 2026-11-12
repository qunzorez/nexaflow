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

function sendTelegramPhoto(string $chatId, string $photoUrl, string $caption, string $buttonUrl = ''): bool
{
    if ($chatId === '' || $photoUrl === '') return false;
    $localConfig = __DIR__ . '/config.local.php';
    $config = require file_exists($localConfig) ? $localConfig : (__DIR__ . '/config.php');
    $token = trim((string)($config['telegram_bot_token'] ?? ''));
    if ($token === '' || !function_exists('curl_init')) return false;
    $fields = ['chat_id' => $chatId, 'photo' => $photoUrl, 'caption' => $caption, 'parse_mode' => 'HTML'];
    if ($buttonUrl !== '') $fields['reply_markup'] = json_encode(['inline_keyboard' => [[['text' => 'Відкрити задачу →', 'url' => $buttonUrl]]]], JSON_UNESCAPED_UNICODE);
    $curl = curl_init('https://api.telegram.org/bot' . rawurlencode($token) . '/sendPhoto');
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
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

function sendTelegramReminderToUser(PDO $pdo, string $userId, array $reminder): void
{
    try {
        $query = $pdo->prepare('SELECT telegram_chat_id, telegram_notifications_enabled FROM users WHERE id = ? LIMIT 1');
        $query->execute([$userId]);
        $user = $query->fetch();
        if (!$user || (int)$user['telegram_notifications_enabled'] !== 1 || !$user['telegram_chat_id']) return;
        $project = trim((string)($reminder['project_name'] ?? '')) ?: 'Без проєкту';
        $due = trim((string)($reminder['due_date'] ?? '')) ?: 'Не встановлено';
        $title = htmlspecialchars((string)$reminder['title'], ENT_QUOTES, 'UTF-8');
        $project = htmlspecialchars($project, ENT_QUOTES, 'UTF-8');
        $due = htmlspecialchars($due, ENT_QUOTES, 'UTF-8');
        $caption = "<b>NexaFlow • Нагадування</b>\n\n🔔 Час повернутися до задачі\n\n<b>{$title}</b>\n\n📁 Проєкт: {$project}\n📅 Дедлайн: {$due}\n\nЗадача все ще очікує вашої уваги. Відкрийте її, щоб переглянути деталі та продовжити роботу.";
        sendTelegramPhoto((string)$user['telegram_chat_id'], 'https://nexaflow.top/assets/logo-telegram.png', $caption, 'https://nexaflow.top/tasks/' . rawurlencode((string)$reminder['id']));
    } catch (Throwable $error) {
        error_log('Telegram reminder error: ' . $error->getMessage());
    }
}
