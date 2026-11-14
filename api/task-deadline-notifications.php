<?php
declare(strict_types=1);

function deliverTaskDeadlineNotification(PDO $pdo, string $userId, array $task): bool
{
    $due = new DateTimeImmutable((string)$task['due_date']);
    $dueLabel = $due->format('d.m.Y');
    if ($due->format('H:i') !== '00:00') $dueLabel .= ' о ' . $due->format('H:i');
    $message = 'Завтра дедлайн задачі «' . (string)$task['title'] . '» (' . $dueLabel . '). Перевірте прогрес, щоб вчасно завершити роботу.';

    $existing = $pdo->prepare("SELECT id FROM notifications WHERE user_id = ? AND type = 'task_deadline' AND entity_type = 'task' AND entity_id = ? AND message = ? LIMIT 1");
    $existing->execute([$userId, (string)$task['id'], $message]);
    if ($existing->fetchColumn()) return false;

    $insert = $pdo->prepare("INSERT INTO notifications (id, user_id, type, title, message, entity_type, entity_id) VALUES (?, ?, 'task_deadline', ?, ?, 'task', ?)");
    $insert->execute([bin2hex(random_bytes(16)), $userId, 'Завтра дедлайн задачі', $message, (string)$task['id']]);
    return true;
}
