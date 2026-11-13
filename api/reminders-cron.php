<?php
declare(strict_types=1);

// This endpoint is intentionally CLI-only. It is executed by cPanel cron.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/db.php';
require __DIR__ . '/telegram.php';
require __DIR__ . '/mailer.php';

$pdo = db();
$notificationColumns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME IN ('telegram_sent_at', 'email_sent_at')")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('telegram_sent_at', $notificationColumns, true)) $pdo->exec("ALTER TABLE notifications ADD telegram_sent_at DATETIME NULL");
if (!in_array('email_sent_at', $notificationColumns, true)) $pdo->exec("ALTER TABLE notifications ADD email_sent_at DATETIME NULL");
$log = static function (string $message): void {
    error_log('[NexaFlow reminders] ' . $message);
};

$tasks = $pdo->query(
    "SELECT t.id, t.title, t.status, t.reminder_at, t.due_date, t.created_by,
            t.creator_reminder, p.name AS project_name
     FROM tasks t
     LEFT JOIN projects p ON p.id = t.project_id
     WHERE t.reminder_at IS NOT NULL
       AND t.reminder_at <= NOW()
       AND t.status IN ('todo', 'in_progress')
     ORDER BY t.reminder_at ASC
     LIMIT 200"
)->fetchAll();

$alreadySent = $pdo->prepare(
    "SELECT id, telegram_sent_at, email_sent_at FROM notifications
     WHERE user_id = ? AND type = 'task_reminder'
       AND entity_type = 'task' AND entity_id = ?
       AND created_at >= ? LIMIT 1"
);
$insertNotification = $pdo->prepare(
    "INSERT INTO notifications
       (id, user_id, type, title, message, entity_type, entity_id)
     VALUES (?, ?, 'task_reminder', ?, ?, 'task', ?)"
);
$assignees = $pdo->prepare(
    'SELECT user_id FROM task_assignees WHERE task_id = ?'
);
$recipient = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');

$sent = 0;
$skipped = 0;
foreach ($tasks as $task) {
    $recipientIds = [];
    if ((int)($task['creator_reminder'] ?? 0) === 1) {
        $recipientIds[] = (string)$task['created_by'];
    }
    if (!empty($task['created_by'])) {
        // A creator may also be an assignee; array_unique below prevents duplicates.
    }

    $assignees->execute([$task['id']]);
    foreach ($assignees->fetchAll(PDO::FETCH_COLUMN) as $assigneeId) {
        $recipientIds[] = (string)$assigneeId;
    }

    // Keep compatibility with the legacy single-assignee column.
    $legacy = $pdo->prepare('SELECT assignee_id FROM tasks WHERE id = ? LIMIT 1');
    $legacy->execute([$task['id']]);
    $legacyAssignee = (string)($legacy->fetchColumn() ?: '');
    if ($legacyAssignee !== '') $recipientIds[] = $legacyAssignee;

    foreach (array_unique(array_filter($recipientIds)) as $userId) {
        $alreadySent->execute([$userId, $task['id'], $task['reminder_at']]);
        $existingNotification = $alreadySent->fetch(PDO::FETCH_ASSOC);
        if ($existingNotification && !empty($existingNotification['telegram_sent_at'])) {
            $skipped++;
            continue;
        }

        $notificationId = bin2hex(random_bytes(16));
        if (!$existingNotification) {
            $title = 'Нагадування про задачу';
            $message = 'Нагадування: задача «' . (string)$task['title'] . '» потребує вашої уваги.';
            $insertNotification->execute([
                $notificationId,
                $userId,
                $title,
                $message,
                $task['id'],
            ]);
        } else {
            $notificationId = (string)$existingNotification['id'];
        }

        if (sendTelegramReminderToUser($pdo, $userId, $task)) {
            $pdo->prepare('UPDATE notifications SET telegram_sent_at = NOW() WHERE id = ?')->execute([$notificationId]);
        }
        $recipient->execute([$userId]);
        $recipientEmail = (string)($recipient->fetchColumn() ?: '');
        if ($recipientEmail !== '' && empty($existingNotification['email_sent_at'] ?? null)) {
            $title = 'Нагадування про задачу';
            $safeTitle = htmlspecialchars((string)$task['title'], ENT_QUOTES, 'UTF-8');
            $safeProject = htmlspecialchars(trim((string)($task['project_name'] ?? '')) ?: 'Без проєкту', ENT_QUOTES, 'UTF-8');
            $safeDue = htmlspecialchars(trim((string)($task['due_date'] ?? '')) ?: 'Не встановлено', ENT_QUOTES, 'UTF-8');
            $taskUrl = appUrl('/tasks/') . rawurlencode((string)$task['id']);
            $message = 'Нагадування: задача «' . (string)$task['title'] . '» потребує вашої уваги.' . "\n\n" . 'Відкрийте задачу: ' . $taskUrl;
            $html = '<div style="font-family:Arial,sans-serif;max-width:620px;margin:auto;color:#17312b">'
                . '<div style="background:#0f1720;padding:24px;text-align:center"><img src="' . htmlspecialchars(appUrl('/assets/logo-telegram.png'), ENT_QUOTES, 'UTF-8') . '" alt="NexaFlow" style="max-width:220px;max-height:90px"></div>'
                . '<div style="padding:30px;background:#ffffff"><p style="color:#168b70;font-weight:700;letter-spacing:1px">NEXAFLOW • НАГАДУВАННЯ</p>'
                . '<h1 style="font-size:26px">Час повернутися до задачі</h1><p>Задача <strong>«' . $safeTitle . '»</strong> очікує вашої уваги.</p>'
                . '<p style="background:#eef8f3;padding:16px;border-radius:10px">Проєкт: <strong>' . $safeProject . '</strong><br>Дедлайн: <strong>' . $safeDue . '</strong></p>'
                . '<a href="' . htmlspecialchars($taskUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#198f70;color:#fff;padding:13px 20px;border-radius:8px;text-decoration:none;font-weight:700">Відкрити задачу →</a>'
                . '<p style="color:#71847e;font-size:13px;margin-top:28px">Це автоматичне повідомлення NexaFlow.</p></div></div>';
            if (sendUserNotificationEmail($pdo, $userId, $recipientEmail, $title . ' — NexaFlow', $message, $html)) {
                $pdo->prepare('UPDATE notifications SET email_sent_at = NOW() WHERE id = ?')->execute([$notificationId]);
            }
        }
        $sent++;
    }
}

$log("processed=" . count($tasks) . " sent={$sent} skipped={$skipped}");
echo "NexaFlow reminders: tasks=" . count($tasks) . " sent={$sent} skipped={$skipped}" . PHP_EOL;
