<?php
declare(strict_types=1);
require_once __DIR__ . '/runtime-config.php';

function sendTelegramMessage(string $chatId, string $message, string $buttonUrl = ''): bool
{
    if ($chatId === '') return false;
    $config = appConfig();
    $token = trim((string)($config['telegram_bot_token'] ?? ''));
    if ($token === '' || !function_exists('curl_init')) return false;
    $curl = curl_init('https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage');
    $fields = ['chat_id' => $chatId, 'text' => $message, 'disable_web_page_preview' => true];
    if ($buttonUrl !== '') $fields['reply_markup'] = json_encode(['inline_keyboard' => [[['text' => 'Відкрити задачу →', 'url' => $buttonUrl]]]], JSON_UNESCAPED_UNICODE);
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
    $result = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
    return $status === 200 && is_string($result) && (json_decode($result, true)['ok'] ?? false) === true;
}

function sendTelegramPhoto(string $chatId, string $photoUrl, string $caption, string $buttonUrl = ''): bool
{
    if ($chatId === '' || $photoUrl === '') return false;
    $config = appConfig();
    $token = trim((string)($config['telegram_bot_token'] ?? ''));
    if ($token === '' || !function_exists('curl_init')) return false;
    $mime = function_exists('mime_content_type') ? (string)(mime_content_type($photoUrl) ?: 'image/png') : 'image/png';
    $photo = is_file($photoUrl)
        ? new CURLFile($photoUrl, $mime, basename($photoUrl))
        : $photoUrl;
    $fields = ['chat_id' => $chatId, 'photo' => $photo, 'caption' => $caption, 'parse_mode' => 'HTML'];
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
            if (!$columns) { $pdo->exec("ALTER TABLE users ADD telegram_notifications_enabled TINYINT(1) NOT NULL DEFAULT 0"); $pdo->exec("UPDATE users SET telegram_notifications_enabled = 1 WHERE telegram_chat_id IS NOT NULL AND telegram_chat_id <> ''"); }
            $columnReady = true;
        }
        $query = $pdo->prepare('SELECT telegram_chat_id, telegram_notifications_enabled FROM users WHERE id = ? LIMIT 1');
        $query->execute([$userId]);
        $user = $query->fetch();
        if (!$user || (int)$user['telegram_notifications_enabled'] !== 1) return;
        $chatId = (string)($user['telegram_chat_id'] ?? '');
        if ($chatId === '') return;
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $caption = "<b>NexaFlow • Нове сповіщення</b>\n\n🔔 <b>Важлива інформація</b>\n\n{$safeMessage}\n\nПерейдіть у NexaFlow, щоб переглянути деталі.";
        $localLogo = __DIR__ . '/../assets/logo-telegram.png';
        if (!is_file($localLogo)) $localLogo = __DIR__ . '/../public/assets/logo-telegram.png';
        $logo = is_file($localLogo) ? $localLogo : appUrl('/assets/logo-telegram.png');
        if (!sendTelegramPhoto($chatId, $logo, $caption)) sendTelegramMessage($chatId, "NexaFlow\n\n{$message}");
    } catch (Throwable $error) {
        error_log('Telegram notification error: ' . $error->getMessage());
    }
}

function sendTelegramTaskAssignment(PDO $pdo, string $userId, string $title, string $taskUrl): void
{
    try {
        $query = $pdo->prepare('SELECT telegram_chat_id, telegram_notifications_enabled FROM users WHERE id = ? LIMIT 1');
        $query->execute([$userId]);
        $user = $query->fetch();
        if (!$user || (int)$user['telegram_notifications_enabled'] !== 1 || !$user['telegram_chat_id']) return;
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $caption = "<b>NexaFlow • Нова задача</b>\n\n👤 <b>Вам призначили задачу</b>\n\n📌 <b>{$safeTitle}</b>\n\nВідкрийте задачу, щоб переглянути опис, дедлайн і почати роботу.";
        $localLogo = __DIR__ . '/../assets/logo-telegram.png';
        if (!is_file($localLogo)) $localLogo = __DIR__ . '/../public/assets/logo-telegram.png';
        $logo = is_file($localLogo) ? $localLogo : appUrl('/assets/logo-telegram.png');
        if (!sendTelegramPhoto((string)$user['telegram_chat_id'], $logo, $caption, $taskUrl)) {
            sendTelegramMessage((string)$user['telegram_chat_id'], "NexaFlow • Нова задача\n\n👤 Вам призначили задачу\n\n📌 {$title}\n\nВідкрити задачу: {$taskUrl}", $taskUrl);
        }
    } catch (Throwable $error) {
        error_log('Telegram assignment error: ' . $error->getMessage());
    }
}

function sendTelegramTaskStatus(PDO $pdo, string $userId, string $title, string $message, string $taskUrl, string $event = 'updated'): void
{
    try {
        $query = $pdo->prepare('SELECT telegram_chat_id, telegram_notifications_enabled FROM users WHERE id = ? LIMIT 1');
        $query->execute([$userId]);
        $user = $query->fetch();
        if (!$user || (int)$user['telegram_notifications_enabled'] !== 1 || !$user['telegram_chat_id']) return;
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        if ($event === 'task_review_approved') {
            $heading = '✅ Виконання задачі підтверджено';
        } elseif ($event === 'task_review_rejected') {
            $heading = '🔧 Задачу повернуто на доопрацювання';
        } else {
            $heading = '🔄 Статус задачі оновлено';
        }
        $caption = "<b>NexaFlow • Оновлення задачі</b>\n\n<b>{$heading}</b>\n\n📌 <b>{$safeTitle}</b>\n\n{$safeMessage}\n\nПерегляньте актуальний статус і деталі задачі.";
        $localLogo = __DIR__ . '/../assets/logo-telegram.png';
        if (!is_file($localLogo)) $localLogo = __DIR__ . '/../public/assets/logo-telegram.png';
        $logo = is_file($localLogo) ? $localLogo : appUrl('/assets/logo-telegram.png');
        if (!sendTelegramPhoto((string)$user['telegram_chat_id'], $logo, $caption, $taskUrl)) {
            sendTelegramMessage((string)$user['telegram_chat_id'], "NexaFlow • Оновлення задачі\n\n{$heading}\n\n📌 {$title}\n\n{$message}\n\nВідкрити задачу: {$taskUrl}", $taskUrl);
        }
    } catch (Throwable $error) {
        error_log('Telegram task status error: ' . $error->getMessage());
    }
}

function sendTelegramTaskReview(PDO $pdo, string $userId, string $title, string $taskUrl): void
{
    try {
        $query = $pdo->prepare('SELECT telegram_chat_id, telegram_notifications_enabled FROM users WHERE id = ? LIMIT 1');
        $query->execute([$userId]);
        $user = $query->fetch();
        if (!$user || (int)$user['telegram_notifications_enabled'] !== 1 || !$user['telegram_chat_id']) return;
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $caption = "<b>NexaFlow • Потрібна перевірка</b>\n\n🔎 <b>Задача очікує вашої перевірки</b>\n\n📌 <b>{$safeTitle}</b>\n\nПерегляньте результат, підтвердьте виконання або поверніть задачу на доопрацювання.";
        $localLogo = __DIR__ . '/../assets/logo-telegram.png';
        if (!is_file($localLogo)) $localLogo = __DIR__ . '/../public/assets/logo-telegram.png';
        $logo = is_file($localLogo) ? $localLogo : appUrl('/assets/logo-telegram.png');
        if (!sendTelegramPhoto((string)$user['telegram_chat_id'], $logo, $caption, $taskUrl)) {
            sendTelegramMessage((string)$user['telegram_chat_id'], strip_tags(str_replace(['<b>', '</b>'], '', $caption)), $taskUrl);
        }
    } catch (Throwable $error) {
        error_log('Telegram task review error: ' . $error->getMessage());
    }
}

function sendTelegramReminderToUser(PDO $pdo, string $userId, array $reminder): bool
{
    try {
        $query = $pdo->prepare('SELECT telegram_chat_id, telegram_notifications_enabled FROM users WHERE id = ? LIMIT 1');
        $query->execute([$userId]);
        $user = $query->fetch();
        if (!$user || (int)$user['telegram_notifications_enabled'] !== 1 || !$user['telegram_chat_id']) return false;
        $project = trim((string)($reminder['project_name'] ?? '')) ?: 'Без проєкту';
        $due = trim((string)($reminder['due_date'] ?? '')) ?: 'Не встановлено';
        $title = htmlspecialchars((string)$reminder['title'], ENT_QUOTES, 'UTF-8');
        $project = htmlspecialchars($project, ENT_QUOTES, 'UTF-8');
        $due = htmlspecialchars($due, ENT_QUOTES, 'UTF-8');
        $caption = "<b>NexaFlow • Нагадування</b>\n\n🔔 Час повернутися до задачі\n\n<b>{$title}</b>\n\n📁 Проєкт: {$project}\n📅 Дедлайн: {$due}\n\nЗадача все ще очікує вашої уваги. Відкрийте її, щоб переглянути деталі та продовжити роботу.";
        $chatId = (string)$user['telegram_chat_id'];
        $taskUrl = appUrl('/tasks/') . rawurlencode((string)$reminder['id']);
        $localLogo = __DIR__ . '/../assets/logo-telegram.png';
        if (!is_file($localLogo)) $localLogo = __DIR__ . '/../public/assets/logo-telegram.png';
        $logoSource = is_file($localLogo) ? $localLogo : appUrl('/assets/logo-telegram.png');
        $sent = sendTelegramPhoto($chatId, $logoSource, $caption, $taskUrl);
        if (!$sent) $sent = sendTelegramMessage($chatId, strip_tags(str_replace(['<b>', '</b>'], '', $caption)), $taskUrl);
        return $sent;
    } catch (Throwable $error) {
        error_log('Telegram reminder error: ' . $error->getMessage());
        return false;
    }
}
