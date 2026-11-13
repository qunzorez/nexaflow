<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/telegram.php';
session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);
$pdo = db();
$lastSeenColumns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_seen_at'")->fetchAll(PDO::FETCH_COLUMN);
if (!$lastSeenColumns) $pdo->exec("ALTER TABLE users ADD last_seen_at DATETIME NULL");
$pdo->prepare('UPDATE users SET last_seen_at = NOW() WHERE id = ?')->execute([$_SESSION['user_id']]);
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
    sendTelegramReminderToUser($pdo, $userId, $reminder);
}
try {
    $canManage = in_array($user['role'], ['admin', 'owner', 'manager'], true);
    $isAdmin = in_array($user['role'], ['admin', 'owner'], true);
    if ($isAdmin) {
        $projects = $pdo->query('SELECT p.id, p.name, p.description, p.owner_id, p.status, p.created_at, u.name AS owner_name, (SELECT COUNT(DISTINCT t.id) FROM tasks t LEFT JOIN task_projects tp ON tp.task_id = t.id WHERE (t.project_id = p.id OR tp.project_id = p.id)) AS task_count FROM projects p JOIN users u ON u.id = p.owner_id ORDER BY p.created_at DESC')->fetchAll();
    } else {
        $query = $pdo->prepare('SELECT DISTINCT p.id, p.name, p.description, p.owner_id, p.status, p.created_at, u.name AS owner_name, (SELECT COUNT(DISTINCT t.id) FROM tasks t LEFT JOIN task_projects tp ON tp.task_id = t.id WHERE (t.project_id = p.id OR tp.project_id = p.id)) AS task_count, CASE WHEN p.owner_id = ? THEN \'owner\' ELSE pm.role END AS project_role FROM projects p JOIN users u ON u.id = p.owner_id LEFT JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ? WHERE p.owner_id = ? OR pm.user_id IS NOT NULL ORDER BY p.created_at DESC');
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
    $tasks = claimRows($pdo, (string)$_SESSION['user_id'], 'task', $tasksQuery->fetchAll());
    $projects = claimRows($pdo, (string)$_SESSION['user_id'], 'project', $projects);
    $remindersQuery = $pdo->prepare("SELECT t.id, t.title, t.status, t.priority, t.reminder_at, t.due_date, p.name AS project_name FROM tasks t LEFT JOIN projects p ON p.id = t.project_id WHERE t.reminder_at IS NOT NULL AND t.reminder_at <= NOW() AND t.status IN ('todo', 'in_progress') AND ((t.created_by = ? AND t.creator_reminder = 1) OR t.assignee_id = ? OR EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id = ?)) ORDER BY t.reminder_at ASC LIMIT 8");
    $remindersQuery->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
    $reminders = claimRows($pdo, (string)$_SESSION['user_id'], 'task', $remindersQuery->fetchAll());
    foreach ($reminders as $reminder) deliverDueReminder($pdo, (string)$_SESSION['user_id'], $reminder);
    $taskCountQuery = $isAdmin ? $pdo->query("SELECT COUNT(*) FROM tasks WHERE status <> 'archived'") : $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE status <> 'archived' AND (created_by = ? OR assignee_id = ? OR EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = tasks.id AND ta.user_id = ?))");
    if (!$isAdmin) $taskCountQuery->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
    $taskCountQuery->fetchColumn(); $taskCount = count(array_filter($tasks, static function ($task): bool { return $task['status'] !== 'archived'; }));
    $timeQuery = $pdo->prepare('SELECT COALESCE(SUM(minutes), 0) FROM time_entries WHERE user_id = ?'); $timeQuery->execute([$_SESSION['user_id']]); $myMinutes = (int)$timeQuery->fetchColumn(); $teamTime = [];
    if ($canManage) { $teamTimeQuery = $pdo->query('SELECT u.id, u.name, u.email, COALESCE(SUM(te.minutes), 0) AS minutes FROM users u LEFT JOIN time_entries te ON te.user_id = u.id GROUP BY u.id, u.name, u.email ORDER BY minutes DESC, u.name'); $teamTime = $teamTimeQuery->fetchAll(); }
    $userMetrics = [];
    if ($isAdmin) {
        $userMetrics = [
            'users' => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'online' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE last_seen_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetchColumn(),
            'all_projects' => (int)$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn(),
            'open_tasks' => (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status IN ('todo', 'in_progress', 'review')")->fetchColumn(),
            'all_clients' => (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn(),
        ];
    }
    $managerDashboard = [];
    if (!$isAdmin) {
        $today = new DateTimeImmutable('today');
        $weekEnd = $today->modify('+7 days');
        $attention = ['overdue' => 0, 'review' => 0, 'urgent' => 0, 'pending_invites' => 0];
        $upcomingDeadlines = [];
        foreach ($tasks as $task) {
            $status = (string)($task['status'] ?? '');
            $due = !empty($task['due_date']) ? new DateTimeImmutable((string)$task['due_date']) : null;
            if ($status === 'review') $attention['review']++;
            if ($due && $due < $today && !in_array($status, ['done', 'cancelled', 'archived'], true)) $attention['overdue']++;
            if (($task['priority'] ?? '') === 'urgent' && !in_array($status, ['done', 'cancelled', 'archived'], true)) $attention['urgent']++;
            if ($due && $due >= $today && $due <= $weekEnd && !in_array($status, ['done', 'cancelled', 'archived'], true)) $upcomingDeadlines[] = $task;
        }
        $pendingInvitesQuery = $isAdmin
            ? $pdo->query("SELECT COUNT(*) FROM project_invitations WHERE status = 'pending'")
            : $pdo->prepare("SELECT (SELECT COUNT(*) FROM project_invitations WHERE invited_by = ? AND status = 'pending') + (SELECT COUNT(*) FROM user_invitations WHERE invited_by = ? AND status = 'pending')");
        if (!$isAdmin) $pendingInvitesQuery->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
        $attention['pending_invites'] = (int)$pendingInvitesQuery->fetchColumn();
        usort($upcomingDeadlines, static fn(array $a, array $b): int => strcmp((string)($a['due_date'] ?? ''), (string)($b['due_date'] ?? '')));

        $projectProgress = [];
        foreach ($projects as $project) {
            $projectTaskQuery = $pdo->prepare("SELECT COUNT(DISTINCT t.id) AS total, SUM(t.status = 'done') AS done, SUM(t.status = 'in_progress') AS in_progress FROM tasks t LEFT JOIN task_projects tp ON tp.task_id = t.id WHERE (t.project_id = ? OR tp.project_id = ?) AND t.status <> 'archived'");
            $projectTaskQuery->execute([$project['id'], $project['id']]);
            $progress = $projectTaskQuery->fetch() ?: ['total' => 0, 'done' => 0, 'in_progress' => 0];
            $projectProgress[] = ['id' => $project['id'], 'name' => $project['name'], 'total' => (int)$progress['total'], 'done' => (int)$progress['done'], 'in_progress' => (int)$progress['in_progress'], 'status' => $project['status'] ?? 'active'];
        }
        $workloadQuery = $isAdmin
            ? $pdo->query("SELECT u.id, u.name, u.email, COUNT(DISTINCT t.id) AS tasks FROM users u LEFT JOIN tasks t ON t.assignee_id = u.id AND t.status NOT IN ('done', 'cancelled', 'archived') GROUP BY u.id, u.name, u.email ORDER BY tasks DESC, u.name LIMIT 12")
            : $pdo->prepare("SELECT u.id, u.name, u.email, COUNT(DISTINCT t.id) AS tasks FROM users u LEFT JOIN tasks t ON t.assignee_id = u.id AND t.status NOT IN ('done', 'cancelled', 'archived') WHERE u.id IN (SELECT DISTINCT pm.user_id FROM project_members pm JOIN projects p ON p.id = pm.project_id WHERE p.owner_id = ? OR pm.user_id = ?) OR u.id = ? GROUP BY u.id, u.name, u.email ORDER BY tasks DESC, u.name LIMIT 12");
        if (!$isAdmin) $workloadQuery->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
        $recentQuery = $isAdmin
            ? $pdo->query("SELECT h.message, h.created_at, u.name AS user_name, t.id AS task_id, t.title AS task_title FROM task_history h JOIN tasks t ON t.id = h.task_id LEFT JOIN users u ON u.id = h.user_id ORDER BY h.created_at DESC LIMIT 8")
            : $pdo->prepare("SELECT h.message, h.created_at, u.name AS user_name, t.id AS task_id, t.title AS task_title FROM task_history h JOIN tasks t ON t.id = h.task_id LEFT JOIN users u ON u.id = h.user_id WHERE t.created_by = ? OR EXISTS (SELECT 1 FROM projects p WHERE p.id = t.project_id AND p.owner_id = ?) ORDER BY h.created_at DESC LIMIT 8");
        if (!$isAdmin) $recentQuery->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
        $managerDashboard = ['attention' => $attention, 'upcoming_deadlines' => array_slice($upcomingDeadlines, 0, 6), 'project_progress' => $projectProgress, 'team_workload' => $workloadQuery->fetchAll(), 'recent_activity' => $recentQuery->fetchAll()];
    }
    jsonResponse(true, 'Dashboard завантажено.', ['user' => $user, 'users' => $users, 'projects' => $projects, 'tasks' => $tasks, 'reminders' => $reminders, 'time_by_user' => $teamTime, 'manager_dashboard' => $managerDashboard, 'metrics' => ['projects' => count($projects), 'clients' => 0, 'tasks' => $taskCount, 'hours' => round($myMinutes / 60, 2), ...$userMetrics], 'permissions' => ['manage' => $canManage, 'can_create_project' => $canManage]]);
} catch (Throwable $error) {
    error_log($error->getMessage());
    jsonResponse(false, 'Не вдалося завантажити Dashboard.', [], 500);
}
