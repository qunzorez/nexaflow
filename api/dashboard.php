<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_once __DIR__ . '/plans.php';
require __DIR__ . '/telegram.php';
require_once __DIR__ . '/task-deadline-notifications.php';
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
$planContext = workspacePlan($pdo, (string)$user['id'], (string)$user['role']);
$memberMarks = implode(',', array_fill(0, count($planContext['member_ids']), '?'));
$workspaceMarks = $memberMarks;
$hasAnalytics = !empty($planContext['plan']['analytics']);
$user['subscription'] = planSettingsPayload($planContext);
$user['workspaces'] = $planContext['workspaces'];
$user['active_workspace_id'] = $planContext['owner_id'];
$user['workspace_role'] = $planContext['workspace_role'];
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
    $canManage = in_array($planContext['workspace_role'], ['admin', 'owner', 'manager'], true);
    $isAdmin = in_array($user['role'], ['admin', 'owner'], true);
    if ($isAdmin) {
        $projects = $pdo->query('SELECT p.id, p.name, p.description, p.owner_id, p.status, p.created_at, u.name AS owner_name, (SELECT COUNT(DISTINCT t.id) FROM tasks t LEFT JOIN task_projects tp ON tp.task_id = t.id WHERE (t.project_id = p.id OR tp.project_id = p.id)) AS task_count FROM projects p JOIN users u ON u.id = p.owner_id ORDER BY p.created_at DESC')->fetchAll();
    } else {
        $query = $pdo->prepare('SELECT DISTINCT p.id, p.name, p.description, p.owner_id, p.status, p.created_at, u.name AS owner_name, (SELECT COUNT(DISTINCT t.id) FROM tasks t LEFT JOIN task_projects tp ON tp.task_id = t.id WHERE (t.project_id = p.id OR tp.project_id = p.id)) AS task_count, CASE WHEN p.owner_id = ? THEN \'owner\' ELSE pm.role END AS project_role FROM projects p JOIN users u ON u.id = p.owner_id LEFT JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ? WHERE p.owner_id = ? OR (pm.user_id = ? AND p.owner_id = ?) ORDER BY p.created_at DESC');
        $query->execute([$planContext['owner_id'], $_SESSION['user_id'], $planContext['owner_id'], $_SESSION['user_id'], $planContext['owner_id']]);
        $projects = $query->fetchAll();
    }
    if ($isAdmin) {
        $users = $pdo->query('SELECT id, name, email, role FROM users ORDER BY name')->fetchAll();
    } elseif ($planContext['workspace_role'] === 'manager') {
        $memberMarks = implode(',', array_fill(0, count($planContext['member_ids']), '?'));
        $usersQuery = $pdo->prepare("SELECT id, name, email, role FROM users WHERE id IN ({$memberMarks}) ORDER BY name");
        $usersQuery->execute($planContext['member_ids']);
        $users = $usersQuery->fetchAll();
    } else {
        $users = [];
    }
    if ($isAdmin) {
        $tasksQuery = $pdo->query("SELECT t.id, t.title, t.status, t.priority, t.due_date, t.project_id, p.name AS project_name, a.name AS assignee_name FROM tasks t LEFT JOIN projects p ON p.id = t.project_id LEFT JOIN users a ON a.id = t.assignee_id ORDER BY t.due_date IS NULL, t.due_date, t.created_at DESC");
    } elseif ($canManage) {
        $tasksQuery = $pdo->prepare("SELECT DISTINCT t.id, t.title, t.status, t.priority, t.due_date, t.created_at, t.project_id, p.name AS project_name, a.name AS assignee_name FROM tasks t LEFT JOIN projects p ON p.id = t.project_id LEFT JOIN users a ON a.id = t.assignee_id WHERE t.created_by IN ({$memberMarks}) ORDER BY t.due_date IS NULL, t.due_date, t.created_at DESC");
        $params = $planContext['member_ids'];
        $tasksQuery->execute($params);
    } else {
        $tasksQuery = $pdo->prepare("SELECT DISTINCT t.id, t.title, t.status, t.priority, t.due_date, t.created_at, t.project_id, p.name AS project_name, a.name AS assignee_name FROM tasks t LEFT JOIN task_assignees ta0 ON ta0.task_id = t.id LEFT JOIN projects p ON p.id = t.project_id LEFT JOIN users a ON a.id = t.assignee_id WHERE t.created_by IN ({$memberMarks}) AND (t.created_by = ? OR t.assignee_id = ? OR ta0.user_id = ?) ORDER BY t.due_date IS NULL, t.due_date, t.created_at DESC");
        $params = array_merge($planContext['member_ids'], [$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
        $tasksQuery->execute($params);
    }
    $tasks = claimRows($pdo, (string)$_SESSION['user_id'], 'task', $tasksQuery->fetchAll());
    $projects = claimRows($pdo, (string)$_SESSION['user_id'], 'project', $projects);
    $remindersQuery = $pdo->prepare("SELECT t.id, t.title, t.status, t.priority, t.reminder_at, t.due_date, p.name AS project_name, CASE WHEN t.due_date >= DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND t.due_date < DATE_ADD(CURDATE(), INTERVAL 2 DAY) AND (t.assignee_id = ? OR EXISTS (SELECT 1 FROM task_assignees deadline_assignee WHERE deadline_assignee.task_id = t.id AND deadline_assignee.user_id = ?)) THEN 'deadline' ELSE NULL END AS dashboard_reminder_kind FROM tasks t LEFT JOIN projects p ON p.id = t.project_id WHERE t.created_by IN ({$memberMarks}) AND t.status IN ('todo', 'in_progress', 'review') AND ((t.reminder_at IS NOT NULL AND t.reminder_at <= NOW() AND ((t.created_by = ? AND t.creator_reminder = 1) OR t.assignee_id = ? OR EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id = ?))) OR (t.due_date >= DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND t.due_date < DATE_ADD(CURDATE(), INTERVAL 2 DAY) AND (t.assignee_id = ? OR EXISTS (SELECT 1 FROM task_assignees due_assignee WHERE due_assignee.task_id = t.id AND due_assignee.user_id = ?)))) ORDER BY COALESCE(t.reminder_at, DATE_SUB(t.due_date, INTERVAL 1 DAY)) ASC LIMIT 8");
    $remindersQuery->execute(array_merge(array_fill(0, 2, $_SESSION['user_id']), $planContext['member_ids'], array_fill(0, 5, $_SESSION['user_id'])));
    $reminders = claimRows($pdo, (string)$_SESSION['user_id'], 'task', $remindersQuery->fetchAll());
    foreach ($reminders as $reminder) if (!empty($reminder['reminder_at']) && $reminder['reminder_at'] <= date('Y-m-d H:i:s')) deliverDueReminder($pdo, (string)$_SESSION['user_id'], $reminder);
    $deadlineTasksQuery = $pdo->prepare("SELECT t.id, t.title, t.due_date FROM tasks t WHERE t.due_date >= DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND t.due_date < DATE_ADD(CURDATE(), INTERVAL 2 DAY) AND t.status IN ('todo', 'in_progress', 'review') AND (t.assignee_id = ? OR EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id = ?)) ORDER BY t.due_date ASC LIMIT 200");
    $deadlineTasksQuery->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
    foreach ($deadlineTasksQuery->fetchAll() as $deadlineTask) deliverTaskDeadlineNotification($pdo, (string)$_SESSION['user_id'], $deadlineTask);
    $taskCountQuery = $isAdmin ? $pdo->query("SELECT COUNT(*) FROM tasks WHERE status IN ('todo', 'in_progress', 'review')") : $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE status IN ('todo', 'in_progress', 'review') AND (created_by = ? OR assignee_id = ? OR EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = tasks.id AND ta.user_id = ?))");
    if (!$isAdmin) $taskCountQuery->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
    $taskCountQuery->fetchColumn(); $taskCount = count(array_filter($tasks, static function ($task): bool { return in_array($task['status'], ['todo', 'in_progress', 'review'], true); }));
    $timeQuery = $isAdmin ? $pdo->prepare('SELECT COALESCE(SUM(minutes), 0) FROM time_entries WHERE user_id = ?') : $pdo->prepare("SELECT COALESCE(SUM(te.minutes), 0) FROM time_entries te JOIN tasks t ON t.id = te.task_id WHERE te.user_id = ? AND t.created_by IN ({$workspaceMarks})"); $timeQuery->execute($isAdmin ? [$_SESSION['user_id']] : array_merge([$_SESSION['user_id']], $planContext['member_ids'])); $myMinutes = (int)$timeQuery->fetchColumn(); $teamTime = [];
    if ($canManage && $hasAnalytics) {
        if ($isAdmin) {
            $teamTimeQuery = $pdo->query('SELECT u.id, u.name, u.email, COALESCE(SUM(te.minutes), 0) AS minutes FROM users u LEFT JOIN time_entries te ON te.user_id = u.id GROUP BY u.id, u.name, u.email ORDER BY minutes DESC, u.name');
        } else {
            $teamTimeQuery = $pdo->prepare("SELECT DISTINCT u.id, u.name, u.email, COALESCE((SELECT SUM(te.minutes) FROM time_entries te JOIN tasks t ON t.id = te.task_id WHERE te.user_id = u.id AND t.created_by IN ({$workspaceMarks})), 0) AS minutes FROM users u WHERE u.id IN ({$memberMarks}) ORDER BY minutes DESC, u.name");
            $teamTimeQuery->execute(array_merge($planContext['member_ids'], $planContext['member_ids']));
        }
        $teamTime = $teamTimeQuery->fetchAll();
    }
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
        foreach (($canManage && $hasAnalytics) ? $projects : [] as $project) {
            $projectTaskQuery = $pdo->prepare("SELECT COUNT(DISTINCT t.id) AS total, SUM(t.status = 'done') AS done, SUM(t.status = 'in_progress') AS in_progress FROM tasks t LEFT JOIN task_projects tp ON tp.task_id = t.id WHERE (t.project_id = ? OR tp.project_id = ?) AND t.status <> 'archived'");
            $projectTaskQuery->execute([$project['id'], $project['id']]);
            $progress = $projectTaskQuery->fetch() ?: ['total' => 0, 'done' => 0, 'in_progress' => 0];
            $projectProgress[] = ['id' => $project['id'], 'name' => $project['name'], 'total' => (int)$progress['total'], 'done' => (int)$progress['done'], 'in_progress' => (int)$progress['in_progress'], 'status' => $project['status'] ?? 'active'];
        }
        if ($canManage && $hasAnalytics) {
        $workloadQuery = $isAdmin
            ? $pdo->query("SELECT u.id, u.name, u.email, u.avatar_url, COUNT(DISTINCT t.id) AS tasks FROM users u LEFT JOIN tasks t ON t.assignee_id = u.id AND t.status NOT IN ('done', 'cancelled', 'archived') GROUP BY u.id, u.name, u.email, u.avatar_url ORDER BY tasks DESC, u.name LIMIT 12")
            : $pdo->prepare("SELECT u.id, u.name, u.email, u.avatar_url, COUNT(DISTINCT t.id) AS tasks FROM users u LEFT JOIN tasks t ON t.assignee_id = u.id AND t.status NOT IN ('done', 'cancelled', 'archived') AND t.created_by IN ({$workspaceMarks}) WHERE u.id IN ({$workspaceMarks}) GROUP BY u.id, u.name, u.email, u.avatar_url ORDER BY tasks DESC, u.name LIMIT 12");
        if (!$isAdmin) $workloadQuery->execute(array_merge($planContext['member_ids'], $planContext['member_ids']));
        $recentQuery = $isAdmin
            ? $pdo->query("SELECT h.message, h.created_at, u.name AS user_name, t.id AS task_id, t.title AS task_title FROM task_history h JOIN tasks t ON t.id = h.task_id LEFT JOIN users u ON u.id = h.user_id ORDER BY h.created_at DESC LIMIT 8")
            : $pdo->prepare("SELECT h.message, h.created_at, u.name AS user_name, t.id AS task_id, t.title AS task_title FROM task_history h JOIN tasks t ON t.id = h.task_id LEFT JOIN users u ON u.id = h.user_id WHERE t.created_by IN ({$workspaceMarks}) OR EXISTS (SELECT 1 FROM projects p WHERE p.id = t.project_id AND p.owner_id = ?) ORDER BY h.created_at DESC LIMIT 8");
        if (!$isAdmin) $recentQuery->execute(array_merge($planContext['member_ids'], [$planContext['owner_id']]));
        $managerDashboard = ['attention' => $attention, 'upcoming_deadlines' => array_slice($upcomingDeadlines, 0, 6), 'project_progress' => $projectProgress, 'team_workload' => $workloadQuery->fetchAll(), 'recent_activity' => $recentQuery->fetchAll()];
        } else {
            $managerDashboard = ['attention' => $attention, 'upcoming_deadlines' => array_slice($upcomingDeadlines, 0, 6), 'project_progress' => [], 'team_workload' => [], 'recent_activity' => []];
        }
    }
    jsonResponse(true, 'Dashboard завантажено.', ['user' => $user, 'users' => $users, 'projects' => $projects, 'tasks' => $tasks, 'reminders' => $reminders, 'time_by_user' => $teamTime, 'manager_dashboard' => $managerDashboard, 'metrics' => array_merge(['projects' => count($projects), 'clients' => 0, 'tasks' => $taskCount, 'hours' => round($myMinutes / 60, 2)], $userMetrics), 'permissions' => ['manage' => $canManage, 'can_create_project' => $canManage]]);
} catch (Throwable $error) {
    error_log($error->getMessage());
    jsonResponse(false, 'Не вдалося завантажити Dashboard.', [], 500);
}
