<?php
declare(strict_types=1);

// This endpoint is intentionally CLI-only. It is executed by cPanel cron.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/db.php';
require __DIR__ . '/telegram.php';

$pdo = db();
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
    "SELECT id FROM notifications
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
        if ($alreadySent->fetchColumn()) {
            $skipped++;
            continue;
        }

        $title = 'Нагадування про задачу';
        $message = 'Нагадування: задача «' . (string)$task['title'] . '» потребує вашої уваги.';
        $insertNotification->execute([
            bin2hex(random_bytes(16)),
            $userId,
            $title,
            $message,
            $task['id'],
        ]);

        sendTelegramReminderToUser($pdo, $userId, $task);
        $sent++;
    }
}

$log("processed=" . count($tasks) . " sent={$sent} skipped={$skipped}");
echo "NexaFlow reminders: tasks=" . count($tasks) . " sent={$sent} skipped={$skipped}" . PHP_EOL;
