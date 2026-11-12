<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/telegram.php';
session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);
$pdo = db();
$userQuery = $pdo->prepare('SELECT id, name, email, role, avatar_url FROM users WHERE id = ? LIMIT 1');
$userQuery->execute([$_SESSION['user_id']]);
$user = $userQuery->fetch();
if (!$user) jsonResponse(false, 'Користувача не знайдено.', [], 401);
function deliverDueReminder(PDO $pdo, string $userId, array $reminder): void
{
    $check = $pdo->prepare("SELECT id FROM notifications WHERE user_id = ? AND type = 'task_reminder' AND entity_type = 'task' AND entity_id = ? AND created_at >= ? LIMIT 1");
    $check->execute([$userId, $reminder['id'], $reminder['reminder_at']]);
    if ($check->fetchColumn()) return;
    $message = 'Нагадування: задача «' . $reminder['title'] . '» потребує вашої уваги.';
    $insert = $pdo->prepare("INSERT INTO notifications (id, user_id, type, title, message, entity_type, entity_id) VALUES (?, ?, 'task_reminder', ?, ?, 'task', ?)");
    $insert->execute([bin2hex(random_bytes(16)), $userId, 'Нагадування про задачу', $message, $reminder['id']]);
    sendTelegramToUser($pdo, $userId, "NexaFlow\n\nНагадування про задачу\n{$message}");
}
try {
    $canManage = in_array($user['role'], ['admin', 'owner', 'manager'], true);
    $isAdmin = in_array($user['role'], ['admin', 'owner'], true);
    if ($isAdmin) {
        $projects = $pdo->query('SELECT p.id, p.name, p.description, p.owner_id, p.status, p.created_at, u.name AS owner_name FROM projects p JOIN users u ON u.id = p.owner_id ORDER BY p.created_at DESC')->fetchAll();
    } else {
        $query = $pdo->prepare('SELECT DISTINCT p.id, p.name, p.description, p.owner_id, p.status, p.created_at, u.name AS owner_name, CASE WHEN p.owner_id = ? THEN \'owner\' ELSE pm.role END AS project_role FROM projects p JOIN users u ON u.id = p.owner_id LEFT JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ? WHERE p.owner_id = ? OR (p.visibility = \'team\' AND pm.user_id IS NOT NULL) ORDER BY p.created_at DESC');
        $query->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
        $projects = $query->fetchAll();
    }
    if ($isAdmin) {
        $users = $pdo->query('SELECT id, name, email, role FROM users ORDER BY name')->fetchAll();
    } elseif ($user['role'] === 'manager') {
        $usersQuery = $pdo->prepare("SELECT DISTINCT u.id, u.name, u.email, u.role FROM users u LEFT JOIN project_invitations pi ON pi.email = u.email AND pi.invited_by = ? AND pi.status = 'accepted' LEFT JOIN user_invitations ui ON ui.email = u.email AND ui.invited_by = ? AND ui.status = 'accepted' WHERE pi.id IS NOT NULL OR ui.id IS NOT NULL ORDER BY u.name");
        $usersQuery->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
        $users = $usersQuery->fetchAll();
    } else {
        $users = [];
    }
    if ($isAdmin) {
        $tasksQuery = $pdo->query("SELECT t.id, t.title, t.status, t.priority, t.due_date, t.project_id, p.name AS project_name, a.name AS assignee_name FROM tasks t LEFT JOIN projects p ON p.id = t.project_id LEFT JOIN users a ON a.id = t.assignee_id ORDER BY t.due_date IS NULL, t.due_date, t.created_at DESC");
    } else {
        $tasksQuery = $pdo->prepare("SELECT t.id, t.title, t.status, t.priority, t.due_date, t.project_id, p.name AS project_name, a.name AS assignee_name FROM tasks t LEFT JOIN projects p ON p.id = t.project_id LEFT JOIN users a ON a.id = t.assignee_id WHERE t.created_by = ? OR t.assignee_id = ? OR EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id = ?) ORDER BY t.due_date IS NULL, t.due_date, t.created_at DESC");
        $tasksQuery->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
    }
    $tasks = $tasksQuery->fetchAll();
    $remindersQuery = $pdo->prepare("SELECT t.id, t.title, t.status, t.priority, t.reminder_at, t.due_date, p.name AS project_name FROM tasks t LEFT JOIN projects p ON p.id = t.project_id WHERE t.reminder_at IS NOT NULL AND t.reminder_at <= NOW() AND t.status IN ('todo', 'in_progress') AND ((t.created_by = ? AND t.creator_reminder = 1) OR t.assignee_id = ? OR EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id = ?)) ORDER BY t.reminder_at ASC LIMIT 8");
    $remindersQuery->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
    $reminders = $remindersQuery->fetchAll();
    foreach ($reminders as $reminder) deliverDueReminder($pdo, (string)$_SESSION['user_id'], $reminder);
    $taskCountQuery = $isAdmin ? $pdo->query("SELECT COUNT(*) FROM tasks WHERE status <> 'archived'") : $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE status <> 'archived' AND (created_by = ? OR assignee_id = ? OR EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = tasks.id AND ta.user_id = ?))");
    if (!$isAdmin) $taskCountQuery->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
    $taskCount = (int)$taskCountQuery->fetchColumn();
    $timeQuery = $pdo->prepare('SELECT COALESCE(SUM(minutes), 0) FROM time_entries WHERE user_id = ?'); $timeQuery->execute([$_SESSION['user_id']]); $myMinutes = (int)$timeQuery->fetchColumn(); $teamTime = [];
    if ($canManage) { $teamTimeQuery = $pdo->query('SELECT u.id, u.name, u.email, COALESCE(SUM(te.minutes), 0) AS minutes FROM users u LEFT JOIN time_entries te ON te.user_id = u.id GROUP BY u.id, u.name, u.email ORDER BY minutes DESC, u.name'); $teamTime = $teamTimeQuery->fetchAll(); }
    jsonResponse(true, 'Dashboard завантажено.', ['user' => $user, 'users' => $users, 'projects' => $projects, 'tasks' => $tasks, 'reminders' => $reminders, 'time_by_user' => $teamTime, 'metrics' => ['projects' => count($projects), 'clients' => 0, 'tasks' => $taskCount, 'hours' => round($myMinutes / 60, 2)], 'permissions' => ['manage' => $canManage, 'can_create_project' => $canManage]]);
} catch (Throwable $error) {
    error_log($error->getMessage());
    jsonResponse(false, 'Не вдалося завантажити Dashboard.', [], 500);
}
