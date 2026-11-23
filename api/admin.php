<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/plans.php';
require_once __DIR__ . '/verification.php';

session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);

$pdo = db();
ensurePlanSchema($pdo);
$pdo->exec("CREATE TABLE IF NOT EXISTS admin_access_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id CHAR(32) NULL,
    email VARCHAR(190) NULL,
    path VARCHAR(190) NOT NULL DEFAULT '/admin',
    result VARCHAR(20) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_access_created (created_at), INDEX idx_admin_access_user (user_id, created_at),
    CONSTRAINT fk_admin_access_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$actorId = (string)$_SESSION['user_id'];
$actorQuery = $pdo->prepare("SELECT id, name, email, role FROM users WHERE id = ? LIMIT 1");
$actorQuery->execute([$actorId]);
$actor = $actorQuery->fetch();
if (!$actor || !in_array((string)$actor['role'], ['admin', 'owner'], true)) {
    $attempt = $pdo->prepare("INSERT INTO admin_access_log (user_id, email, path, result) VALUES (?, ?, '/admin', 'denied')");
    $attempt->execute([$actorId !== '' ? $actorId : null, is_array($actor) ? (string)$actor['email'] : null]);
    jsonResponse(false, 'Адміністративний доступ заборонено.', [], 403);
}

$pdo->exec("CREATE TABLE IF NOT EXISTS admin_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor_id CHAR(32) NOT NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id VARCHAR(64) NOT NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    reason VARCHAR(500) NULL,
    result VARCHAR(20) NOT NULL DEFAULT 'success',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_audit_created (created_at),
    INDEX idx_admin_audit_entity (entity_type, entity_id),
    INDEX idx_admin_audit_actor (actor_id),
    CONSTRAINT fk_admin_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS auth_event_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id CHAR(32) NULL,
    email VARCHAR(190) NULL,
    event_type VARCHAR(40) NOT NULL,
    result VARCHAR(20) NOT NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'web',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_auth_event_created (created_at), INDEX idx_auth_event_user (user_id, created_at), INDEX idx_auth_event_type (event_type, result),
    CONSTRAINT fk_auth_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function adminAudit(PDO $pdo, string $actorId, string $action, string $entityType, string $entityId, ?array $before, ?array $after, ?string $reason = null, string $result = 'success'): void
{
    $query = $pdo->prepare('INSERT INTO admin_audit_log (actor_id, action, entity_type, entity_id, before_json, after_json, reason, result) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $query->execute([$actorId, $action, $entityType, $entityId, $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE), $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE), $reason, $result]);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    $data = body();
    $action = (string)($data['action'] ?? '');
    $reason = trim((string)($data['reason'] ?? '')) ?: null;
    if ($action === 'resend_email_verification') {
        $userId = trim((string)($data['user_id'] ?? ''));
        if ($userId === '') jsonResponse(false, 'Користувача не вказано.', [], 422);
        $query = $pdo->prepare('SELECT id, name, email, email_verified_at FROM users WHERE id = ? LIMIT 1');
        $query->execute([$userId]);
        $target = $query->fetch();
        if (!$target) jsonResponse(false, 'Користувача не знайдено.', [], 404);
        if ($target['email_verified_at']) jsonResponse(false, 'Email цього користувача вже підтверджено.', [], 409);

        $sent = issueEmailVerification($pdo, (string)$target['id'], (string)$target['email'], (string)$target['name']);
        adminAudit(
            $pdo,
            $actorId,
            'resend_email_verification',
            'user',
            $userId,
            ['email' => (string)$target['email'], 'email_verified_at' => null],
            ['email' => (string)$target['email'], 'sent' => $sent],
            $reason,
            $sent ? 'success' : 'failed'
        );
        if (!$sent) jsonResponse(false, 'Не вдалося відправити лист підтвердження. Перевірте налаштування пошти.', [], 502);
        jsonResponse(true, 'Лист підтвердження повторно надіслано на ' . (string)$target['email'] . '.');
    }
    if ($action === 'update_user') {
        $userId = trim((string)($data['user_id'] ?? ''));
        if ($userId === '') jsonResponse(false, 'Користувача не вказано.', [], 422);
        $query = $pdo->prepare('SELECT id, name, email, role, requested_role, is_support_agent, plan_key, active_client_module FROM users WHERE id = ? LIMIT 1');
        $query->execute([$userId]);
        $before = $query->fetch();
        if (!$before) jsonResponse(false, 'Користувача не знайдено.', [], 404);
        $allowedRoles = ['user', 'manager', 'admin', 'owner'];
        $nextRole = array_key_exists('role', $data) ? trim((string)$data['role']) : (string)$before['role'];
        if (!in_array($nextRole, $allowedRoles, true)) jsonResponse(false, 'Недопустима роль.', [], 422);
        if ($userId === $actorId && $nextRole !== (string)$before['role']) jsonResponse(false, 'Не можна змінити власну системну роль.', [], 422);
        if (in_array((string)$before['role'], ['admin', 'owner'], true) && !in_array($nextRole, ['admin', 'owner'], true)) {
            $admins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('admin','owner')")->fetchColumn();
            if ($admins <= 1) jsonResponse(false, 'Неможливо позбавити останнього адміністратора доступу.', [], 422);
        }
        $name = array_key_exists('name', $data) ? trim((string)$data['name']) : (string)$before['name'];
        $email = array_key_exists('email', $data) ? strtolower(trim((string)$data['email'])) : (string)$before['email'];
        if ($name === '' || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse(false, 'Перевірте ім’я та email.', [], 422);
        $planKey = array_key_exists('plan_key', $data) ? trim((string)$data['plan_key']) : (string)$before['plan_key'];
        $plans = planDefinitions();
        if (!isset($plans[$planKey])) jsonResponse(false, 'Недопустимий тарифний план.', [], 422);
        $module = array_key_exists('active_client_module', $data) ? trim((string)$data['active_client_module']) : (string)$before['active_client_module'];
        if (!in_array($module, ['accounting', 'dentistry'], true)) jsonResponse(false, 'Недопустимий модуль.', [], 422);
        $support = array_key_exists('is_support_agent', $data) ? (int)(bool)$data['is_support_agent'] : (int)$before['is_support_agent'];
        try {
            $update = $pdo->prepare('UPDATE users SET name = ?, email = ?, role = ?, plan_key = ?, active_client_module = ?, is_support_agent = ? WHERE id = ?');
            $update->execute([$name, $email, $nextRole, $planKey, $module, $support, $userId]);
        } catch (PDOException $error) {
            if ((int)$error->errorInfo[1] === 1062) jsonResponse(false, 'Цей email уже використовується.', [], 409);
            throw $error;
        }
        $after = ['id' => $userId, 'name' => $name, 'email' => $email, 'role' => $nextRole, 'plan_key' => $planKey, 'active_client_module' => $module, 'is_support_agent' => $support];
        adminAudit($pdo, $actorId, 'update_user', 'user', $userId, $before, $after, $reason);
        jsonResponse(true, 'Користувача оновлено.', ['user' => $after]);
    }
    if ($action === 'revoke_sessions') {
        $userId = trim((string)($data['user_id'] ?? ''));
        if ($userId === '') jsonResponse(false, 'Користувача не вказано.', [], 422);
        $tableExists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions'")->fetchColumn();
        if (!$tableExists) jsonResponse(false, 'Завершення сесій не підтримується поточною схемою.', [], 409);
        $query = $pdo->prepare('DELETE FROM sessions WHERE user_id = ?');
        $query->execute([$userId]);
        adminAudit($pdo, $actorId, 'revoke_sessions', 'user', $userId, null, ['revoked' => $query->rowCount()], $reason);
        jsonResponse(true, 'Сесії завершено.', ['revoked' => $query->rowCount()]);
    }
    if ($action === 'set_user_blocked') {
        $userId = trim((string)($data['user_id'] ?? ''));
        $blocked = !empty($data['blocked']);
        $reason = trim((string)($data['reason'] ?? ''));
        if ($userId === '' || $reason === '' || mb_strlen($reason) > 500) jsonResponse(false, 'Вкажіть користувача та причину (до 500 символів).', [], 422);
        if ($userId === $actorId && $blocked) jsonResponse(false, 'Не можна заблокувати власний обліковий запис.', [], 422);
        $query = $pdo->prepare('SELECT id, name, email, role, is_blocked, blocked_reason FROM users WHERE id = ? LIMIT 1');
        $query->execute([$userId]); $before = $query->fetch();
        if (!$before) jsonResponse(false, 'Користувача не знайдено.', [], 404);
        if ($blocked && in_array((string)$before['role'], ['admin', 'owner'], true)) {
            $activeAdmins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('admin','owner') AND is_blocked = 0")->fetchColumn();
            if ($activeAdmins <= 1) jsonResponse(false, 'Неможливо заблокувати останнього доступного адміністратора.', [], 422);
        }
        $nextReason = $blocked ? $reason : null;
        $pdo->prepare('UPDATE users SET is_blocked = ?, blocked_at = ?, blocked_reason = ? WHERE id = ?')->execute([$blocked ? 1 : 0, $blocked ? date('Y-m-d H:i:s') : null, $nextReason, $userId]);
        $after = ['id' => $userId, 'name' => $before['name'], 'email' => $before['email'], 'is_blocked' => $blocked ? 1 : 0, 'blocked_reason' => $nextReason];
        adminAudit($pdo, $actorId, $blocked ? 'block_user' : 'unblock_user', 'user', $userId, $before, $after, $reason);
        jsonResponse(true, $blocked ? 'Обліковий запис заблоковано.' : 'Обліковий запис розблоковано.', ['user' => $after]);
    }
    jsonResponse(false, 'Невідома адміністративна операція.', [], 422);
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
$offset = ($page - 1) * $perPage;
$search = trim((string)($_GET['search'] ?? ''));
$like = '%' . $search . '%';
$userCount = $pdo->prepare("SELECT COUNT(*) FROM users WHERE (? = '' OR name LIKE ? OR email LIKE ? OR role LIKE ?)");
$userCount->execute([$search, $like, $like, $like]);
$totalUsers = (int)$userCount->fetchColumn();
$users = $pdo->prepare("SELECT id, name, email, role, requested_role, is_support_agent, is_blocked, blocked_reason, plan_key, active_client_module, email_verified_at, created_at, updated_at, last_seen_at FROM users WHERE (? = '' OR name LIKE ? OR email LIKE ? OR role LIKE ?) ORDER BY (email_verified_at IS NOT NULL) ASC, created_at DESC, id DESC LIMIT ? OFFSET ?");
$users->bindValue(1, $search); $users->bindValue(2, $like); $users->bindValue(3, $like); $users->bindValue(4, $like); $users->bindValue(5, $perPage, PDO::PARAM_INT); $users->bindValue(6, $offset, PDO::PARAM_INT); $users->execute();
$userRows = $users->fetchAll();
if ($userRows) {
    $ids = array_column($userRows, 'id');
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $deliveryQuery = $pdo->prepare("SELECT a.entity_id, a.result, a.created_at FROM admin_audit_log a JOIN (SELECT entity_id, MAX(id) AS latest_id FROM admin_audit_log WHERE action = 'resend_email_verification' AND entity_type = 'user' AND entity_id IN ({$marks}) GROUP BY entity_id) latest ON latest.latest_id = a.id");
    $deliveryQuery->execute($ids);
    $deliveries = [];
    foreach ($deliveryQuery->fetchAll() as $delivery) $deliveries[(string)$delivery['entity_id']] = ['result' => (string)$delivery['result'], 'created_at' => (string)$delivery['created_at']];
    foreach ($userRows as &$userRow) $userRow['verification_delivery'] = $deliveries[(string)$userRow['id']] ?? null;
    unset($userRow);
}
$workspaceQuery = $pdo->query("SELECT u.id, u.name, u.email, u.role, u.plan_key, u.active_client_module, u.created_at, (SELECT COUNT(*) FROM users m WHERE m.id = u.id OR m.selected_workspace_id = u.id) AS members, (SELECT COUNT(*) FROM clients c WHERE c.created_by = u.id) AS clients, (SELECT COUNT(*) FROM projects p WHERE p.owner_id = u.id) AS projects, (SELECT COUNT(*) FROM tasks t WHERE t.created_by = u.id) AS tasks FROM users u WHERE u.role IN ('admin','owner','manager') ORDER BY u.created_at DESC");
$workspaces = $workspaceQuery->fetchAll();
$subscriptions = [];
try { $subscriptions = $pdo->query('SELECT s.id, s.owner_id, u.name AS owner_name, s.plan_key, s.module_key, s.status, s.amount, s.currency, s.current_period_end, s.created_at, s.updated_at FROM plan_subscriptions s JOIN users u ON u.id = s.owner_id ORDER BY s.created_at DESC LIMIT 100')->fetchAll(); } catch (Throwable $e) { $subscriptions = []; }
$auditPage = max(1, (int)($_GET['audit_page'] ?? 1));
$auditOffset = ($auditPage - 1) * $perPage;
$audit = $pdo->prepare('SELECT a.id, a.action, a.entity_type, a.entity_id, a.before_json, a.after_json, a.reason, a.result, a.created_at, u.name AS actor_name, u.email AS actor_email FROM admin_audit_log a JOIN users u ON u.id = a.actor_id ORDER BY a.created_at DESC LIMIT ? OFFSET ?');
$audit->bindValue(1, $perPage, PDO::PARAM_INT); $audit->bindValue(2, $auditOffset, PDO::PARAM_INT); $audit->execute();
$stats = [
    'users' => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'workspaces' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('admin','owner','manager')")->fetchColumn(),
    'clients' => (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn(),
    'tasks' => (int)$pdo->query('SELECT COUNT(*) FROM tasks')->fetchColumn(),
    'projects' => (int)$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn(),
    'active_subscriptions' => (int)$pdo->query("SELECT COUNT(*) FROM plan_subscriptions WHERE status = 'active' AND (current_period_end IS NULL OR current_period_end > CURRENT_TIMESTAMP)")->fetchColumn(),
];
$online = $pdo->query("SELECT id, name, email, role, last_seen_at FROM users WHERE last_seen_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) ORDER BY last_seen_at DESC LIMIT 100")->fetchAll();
$authEvents = $pdo->query('SELECT e.id, e.user_id, COALESCE(u.name, e.email) AS user_name, e.email, e.event_type, e.result, e.source, e.created_at FROM auth_event_log e LEFT JOIN users u ON u.id = e.user_id ORDER BY e.created_at DESC LIMIT 100')->fetchAll();
$accessAttempts = $pdo->query("SELECT a.id, a.user_id, COALESCE(u.name, a.email, 'Невідомий користувач') AS user_name, a.email, a.path, a.result, a.created_at FROM admin_access_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.created_at DESC LIMIT 100")->fetchAll();
$authEvents = $pdo->query('SELECT e.id, e.user_id, COALESCE(u.name, e.email) AS user_name, e.email, e.event_type, e.result, e.source, e.created_at FROM auth_event_log e LEFT JOIN users u ON u.id = e.user_id ORDER BY e.created_at DESC LIMIT 100')->fetchAll();
$groups = $pdo->query("SELECT g.id, g.name, g.role_name, g.project_id, p.name AS project_name, COUNT(gm.user_id) AS member_count FROM project_groups g LEFT JOIN projects p ON p.id = g.project_id LEFT JOIN project_group_members gm ON gm.group_id = g.id GROUP BY g.id, g.name, g.role_name, g.project_id, p.name ORDER BY g.name LIMIT 200")->fetchAll();
$invitations = $pdo->query("SELECT i.id, i.email, i.role, i.group_id, g.name AS group_name, i.status, i.expires_at, i.created_at, u.name AS inviter_name, 'team' AS invite_type, NULL AS project_name FROM user_invitations i LEFT JOIN project_groups g ON g.id = i.group_id LEFT JOIN users u ON u.id = i.invited_by UNION ALL SELECT i.id, i.email, i.role, i.group_id, g.name AS group_name, i.status, i.expires_at, i.created_at, u.name AS inviter_name, 'project' AS invite_type, p.name AS project_name FROM project_invitations i JOIN projects p ON p.id = i.project_id LEFT JOIN project_groups g ON g.id = i.group_id LEFT JOIN users u ON u.id = i.invited_by ORDER BY created_at DESC LIMIT 100")->fetchAll();
jsonResponse(true, 'Адміністративні дані завантажено.', ['stats' => $stats, 'users' => $userRows, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $totalUsers], 'workspaces' => $workspaces, 'subscriptions' => $subscriptions, 'audit' => $audit->fetchAll(), 'online' => $online, 'auth_events' => $authEvents, 'admin_access_attempts' => $accessAttempts, 'groups' => $groups, 'invitations' => $invitations, 'settings' => ['billing_enabled' => filter_var(appConfig()['billing_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)], 'plans' => array_map(static fn(array $plan, string $key): array => ['key' => $key, 'name' => $plan['name'], 'price' => $plan['price'], 'module' => $plan['module']], planDefinitions(), array_keys(planDefinitions()))]);
