<?php
declare(strict_types=1);

/** NexaFlow workspace plan limits and billing entitlements. */
function planDefinitions(): array
{
    return [
        'start' => ['name' => 'Старт', 'price' => 'Безкоштовно', 'projects' => 1, 'tasks_month' => 50, 'clients' => 3, 'participants' => 2, 'services' => false, 'services_per_client' => 2, 'calendar' => false, 'analytics' => false, 'telegram' => false, 'managers' => 1, 'project_leads' => 0, 'moderators' => 0],
        'growth' => ['name' => 'Зростання', 'price' => '$7.99 / місяць', 'amount' => '7.99', 'projects' => 5, 'tasks_month' => 100, 'clients' => 30, 'participants' => 5, 'services' => true, 'services_per_client' => null, 'calendar' => false, 'analytics' => false, 'telegram' => true, 'managers' => 1, 'project_leads' => 0, 'moderators' => 0],
        'team' => ['name' => 'Команда', 'price' => '$14.99 / місяць', 'amount' => '14.99', 'projects' => 50, 'tasks_month' => 300, 'clients' => 100, 'participants' => 10, 'services' => true, 'services_per_client' => null, 'calendar' => true, 'analytics' => true, 'telegram' => true, 'managers' => 1, 'project_leads' => 0, 'moderators' => 0],
        'business' => ['name' => 'Бізнес', 'price' => '$24.99 / місяць', 'amount' => '24.99', 'projects' => 100, 'tasks_month' => 1000, 'clients' => 500, 'participants' => 49, 'services' => true, 'services_per_client' => null, 'calendar' => true, 'analytics' => true, 'telegram' => true, 'managers' => 5, 'project_leads' => 5, 'moderators' => 5],
    ];
}

function ensurePlanSchema(PDO $pdo): void
{
    $exists = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'plan_key'")->fetchColumn();
    if (!(int)$exists) $pdo->exec("ALTER TABLE users ADD plan_key VARCHAR(24) NOT NULL DEFAULT 'start'");
    $pdo->exec("CREATE TABLE IF NOT EXISTS plan_subscriptions (
        id CHAR(32) NOT NULL PRIMARY KEY,
        owner_id CHAR(32) NOT NULL,
        plan_key VARCHAR(24) NOT NULL,
        order_id VARCHAR(64) NOT NULL UNIQUE,
        liqpay_subscription_id VARCHAR(100) NULL,
        liqpay_payment_id VARCHAR(100) NULL,
        amount DECIMAL(10,2) NOT NULL,
        currency CHAR(3) NOT NULL DEFAULT 'USD',
        status VARCHAR(24) NOT NULL DEFAULT 'pending',
        current_period_end DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_plan_subscription_payment (liqpay_payment_id),
        KEY idx_plan_subscription_owner (owner_id, status, current_period_end)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Return the teams this account has actually joined. A team is identified by its manager/owner account. */
function availableWorkspaces(PDO $pdo, string $userId, string $role): array
{
    if (in_array($role, ['admin', 'owner'], true)) return [];
    $query = $pdo->prepare("SELECT DISTINCT u.id, u.name, u.role, u.created_at FROM users u WHERE u.id <> ? AND u.role IN ('manager','owner','admin') AND (EXISTS (SELECT 1 FROM user_invitations i WHERE i.invited_by = u.id AND i.email = (SELECT email FROM users WHERE id = ?) AND i.status = 'accepted') OR EXISTS (SELECT 1 FROM project_invitations i WHERE i.invited_by = u.id AND i.email = (SELECT email FROM users WHERE id = ?) AND i.status = 'accepted')) ORDER BY u.created_at ASC");
    $query->execute([$userId, $userId, $userId]);
    $workspaces = [];
    if (in_array($role, ['manager', 'owner'], true)) {
        $self = $pdo->prepare('SELECT id, name FROM users WHERE id = ? LIMIT 1');
        $self->execute([$userId]);
        if ($row = $self->fetch()) $workspaces[] = ['id' => (string)$row['id'], 'name' => (string)$row['name'], 'is_own' => true];
    }
    foreach ($query->fetchAll() as $row) $workspaces[] = ['id' => (string)$row['id'], 'name' => (string)$row['name'], 'is_own' => false];
    if (!$workspaces) {
        $self = $pdo->prepare('SELECT id, name FROM users WHERE id = ? LIMIT 1');
        $self->execute([$userId]);
        if ($row = $self->fetch()) $workspaces[] = ['id' => (string)$row['id'], 'name' => (string)$row['name'], 'is_own' => true];
    }
    return $workspaces;
}

function activeWorkspaceId(PDO $pdo, string $userId, string $role): string
{
    $workspaces = availableWorkspaces($pdo, $userId, $role);
    $allowed = array_column($workspaces, 'id');
    $requested = (string)($_SESSION['active_workspace_id'] ?? '');
    if ($requested !== '' && in_array($requested, $allowed, true)) return $requested;
    // Keep the previous default for members while managers start in their own workspace.
    $default = (string)($workspaces[0]['id'] ?? $userId);
    $_SESSION['active_workspace_id'] = $default;
    return $default;
}

function workspacePlan(PDO $pdo, string $userId, string $role): array
{
    ensurePlanSchema($pdo);
    $workspaces = availableWorkspaces($pdo, $userId, $role);
    $ownerId = activeWorkspaceId($pdo, $userId, $role);
    $standaloneParticipant = $ownerId === $userId && !in_array($role, ['admin', 'owner', 'manager'], true);
    $planQuery = $pdo->prepare('SELECT plan_key FROM users WHERE id = ? LIMIT 1');
    $planQuery->execute([$ownerId]);
    $planKey = $standaloneParticipant ? 'start' : (string)($planQuery->fetchColumn() ?: 'start');
    $plans = planDefinitions();
    $subscription = null;
    $pendingSubscription = null;
    $billingEnabled = filter_var(appConfig()['billing_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($billingEnabled) {
      try {
        $subscriptionQuery = $pdo->prepare("SELECT id, plan_key, status, current_period_end, order_id FROM plan_subscriptions WHERE owner_id = ? ORDER BY created_at DESC LIMIT 1");
        $subscriptionQuery->execute([$ownerId]);
        $latestSubscription = $subscriptionQuery->fetch() ?: null;
        $pendingQuery = $pdo->prepare("SELECT id, plan_key, status, current_period_end, order_id FROM plan_subscriptions WHERE owner_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1");
        $pendingQuery->execute([$ownerId]);
        $pendingSubscription = $pendingQuery->fetch() ?: null;
        $accessQuery = $pdo->prepare("SELECT id, plan_key, status, current_period_end, order_id FROM plan_subscriptions WHERE owner_id = ? AND status IN ('active','cancelled') AND (current_period_end IS NULL OR current_period_end > CURRENT_TIMESTAMP) ORDER BY created_at DESC LIMIT 1");
        $accessQuery->execute([$ownerId]);
        $accessSubscription = $accessQuery->fetch() ?: null;
        $subscription = $accessSubscription ?: $latestSubscription;
        if ($accessSubscription) {
            $planKey = (string)$accessSubscription['plan_key'];
        } else {
            $expiredQuery = $pdo->prepare("SELECT id FROM plan_subscriptions WHERE owner_id = ? AND status IN ('active','cancelled') AND current_period_end <= CURRENT_TIMESTAMP ORDER BY created_at DESC LIMIT 1");
            $expiredQuery->execute([$ownerId]);
            $expiredId = $expiredQuery->fetchColumn();
            if ($expiredId) {
                $pdo->prepare("UPDATE plan_subscriptions SET status = 'expired' WHERE id = ? AND status IN ('active','cancelled')")->execute([$expiredId]);
                $pdo->prepare("UPDATE users SET plan_key = 'start' WHERE id = ?")->execute([$ownerId]);
                $planKey = 'start';
            }
        }
    } catch (Throwable $ignored) {
        $subscription = null;
        $pendingSubscription = null;
      }
    }
    if (!isset($plans[$planKey])) $planKey = 'start';
    $plan = $plans[$planKey];
    $ids = [$ownerId];
    $membersQuery = $pdo->prepare("SELECT DISTINCT u.id FROM users u WHERE EXISTS (SELECT 1 FROM user_invitations i WHERE i.invited_by = ? AND i.email = u.email AND i.status = 'accepted') OR EXISTS (SELECT 1 FROM project_invitations i WHERE i.invited_by = ? AND i.email = u.email AND i.status = 'accepted')");
    $membersQuery->execute([$ownerId, $ownerId]);
    foreach ($membersQuery->fetchAll(PDO::FETCH_COLUMN) as $memberId) $ids[] = (string)$memberId;
    $ids = array_values(array_unique($ids));
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $count = static function (string $sql) use ($pdo, $ids): int { $q = $pdo->prepare($sql); $q->execute($ids); return (int)$q->fetchColumn(); };
    $pending = $pdo->prepare("SELECT email FROM user_invitations WHERE invited_by = ? AND status = 'pending' UNION SELECT email FROM project_invitations WHERE invited_by = ? AND status = 'pending'");
    $pending->execute([$ownerId, $ownerId]);
    $knownEmails = [];
    if ($ids) { $idMarks = implode(',', array_fill(0, count($ids), '?')); $emailQuery = $pdo->prepare("SELECT email FROM users WHERE id IN ($idMarks)"); $emailQuery->execute($ids); $knownEmails = array_fill_keys(array_map('strtolower', $emailQuery->fetchAll(PDO::FETCH_COLUMN)), true); }
    $pendingCount = 0;
    foreach ($pending->fetchAll(PDO::FETCH_COLUMN) as $pendingEmail) if (!isset($knownEmails[strtolower((string)$pendingEmail)])) $pendingCount++;
    $counts = [
        'projects' => $count("SELECT COUNT(*) FROM projects WHERE owner_id IN ($marks)"),
        'clients' => $count("SELECT COUNT(*) FROM clients WHERE created_by IN ($marks)"),
        'tasks_month' => $count("SELECT COUNT(DISTINCT t.id) FROM tasks t WHERE t.created_by IN ($marks) AND t.status <> 'archived' AND ((t.created_at >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01') AND t.created_at < DATE_ADD(DATE_FORMAT(CURRENT_DATE, '%Y-%m-01'), INTERVAL 1 MONTH)) OR EXISTS (SELECT 1 FROM task_history h WHERE h.task_id = t.id AND h.action = 'restored_from_archive' AND h.created_at >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01') AND h.created_at < DATE_ADD(DATE_FORMAT(CURRENT_DATE, '%Y-%m-01'), INTERVAL 1 MONTH)))"),
        'participants' => max(0, count($ids) - 1) + $pendingCount,
    ];
    if (in_array($role, ['admin', 'owner'], true)) {
        foreach (['projects', 'clients', 'tasks_month', 'participants', 'services_per_client'] as $metric) $plan[$metric] = null;
        foreach (['services', 'calendar', 'analytics', 'telegram'] as $feature) $plan[$feature] = true;
        foreach (['managers', 'project_leads', 'moderators'] as $metric) $plan[$metric] = null;
    }
    return ['key' => $planKey, 'owner_id' => $ownerId, 'user_id' => $userId, 'role' => $role, 'workspace_role' => ($ownerId === $userId ? $role : 'user'), 'member_ids' => $ids, 'workspaces' => $workspaces, 'plan' => $plan, 'counts' => $counts, 'plans' => $plans, 'subscription' => $subscription, 'pending_subscription' => $pendingSubscription, 'billing_enabled' => $billingEnabled];
}

function requirePlanFeature(array $context, string $feature): void
{
    if (!empty($context['plan'][$feature])) return;
    jsonResponse(false, 'Ця можливість недоступна у вашому поточному плані. Перегляньте ліміти в Налаштуваннях.', ['code' => 'plan_feature_locked', 'feature' => $feature, 'plan' => $context['key']], 403);
}

function telegramPlanAvailable(PDO $pdo, string $userId): bool
{
    static $cache = [];
    ensurePlanSchema($pdo);
    $userQuery = $pdo->prepare('SELECT id, email, role FROM users WHERE id = ? LIMIT 1');
    $userQuery->execute([$userId]);
    $user = $userQuery->fetch();
    if (!$user) return $cache[$userId] = false;
    $context = workspacePlan($pdo, $userId, (string)$user['role']);
    $cacheKey = $userId . ':' . $context['owner_id'];
    if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];
    return $cache[$cacheKey] = !empty($context['plan']['telegram']);
}

function notifyPlanCapacityReached(array $context, string $metric, int $current, int $limit): void
{
    global $pdo;
    $labels = ['projects' => 'проєктів', 'clients' => 'клієнтів', 'tasks_month' => 'задач цього місяця', 'participants' => 'учасників', 'services_per_client' => 'окремих послуг на клієнта', 'managers' => 'менеджерів', 'project_leads' => 'керівників проєктів', 'moderators' => 'модераторів'];
    $title = 'Досягнуто ліміту плану';
    $message = 'У плані «' . $context['plan']['name'] . '» вичерпано ліміт ' . ($labels[$metric] ?? $metric) . ' (' . $current . ' з ' . $limit . '). Перегляньте доступні плани в Налаштуваннях.';
    try {
        $memberIds = array_values(array_unique(array_map('strval', $context['member_ids'] ?? [])));
        if (!$memberIds) $memberIds = [(string)$context['owner_id']];
        $marks = implode(',', array_fill(0, count($memberIds), '?'));
        $managers = $pdo->prepare("SELECT id FROM users WHERE id IN ({$marks}) AND role IN ('manager','admin','owner')");
        $managers->execute($memberIds);
        $recipients = $managers->fetchAll(PDO::FETCH_COLUMN);
        if (!$recipients) $recipients = [(string)$context['owner_id']];
        $exists = $pdo->prepare("SELECT id FROM notifications WHERE user_id = ? AND type = 'plan_limit' AND title = ? AND message = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 1");
        $insert = $pdo->prepare("INSERT INTO notifications (id, user_id, type, title, message) VALUES (?, ?, 'plan_limit', ?, ?)");
        foreach ($recipients as $recipientId) {
            $exists->execute([$recipientId, $title, $message]);
            if (!$exists->fetchColumn()) $insert->execute([bin2hex(random_bytes(16)), $recipientId, $title, $message]);
        }
    } catch (Throwable $notificationError) {
        error_log('Plan limit notification error: ' . $notificationError->getMessage());
    }
}

function planProjectLeadCount(PDO $pdo, array $context): int
{
    $ownerIds = array_values(array_unique(array_map('strval', $context['member_ids'] ?? [$context['owner_id']])));
    $marks = implode(',', array_fill(0, count($ownerIds), '?'));
    $existing = $pdo->prepare("SELECT DISTINCT pm.user_id FROM project_members pm JOIN projects p ON p.id = pm.project_id WHERE p.owner_id IN ({$marks}) AND pm.role = 'manager'");
    $existing->execute($ownerIds);
    $principals = array_fill_keys(array_map('strval', $existing->fetchAll(PDO::FETCH_COLUMN)), true);
    $pending = $pdo->prepare("SELECT DISTINCT pi.email, u.id AS user_id FROM project_invitations pi JOIN projects p ON p.id = pi.project_id LEFT JOIN users u ON LOWER(u.email) = LOWER(pi.email) WHERE p.owner_id IN ({$marks}) AND pi.status = 'pending' AND pi.role = 'manager'");
    $pending->execute($ownerIds);
    foreach ($pending->fetchAll() as $invitation) {
        $principal = !empty($invitation['user_id']) ? (string)$invitation['user_id'] : 'email:' . strtolower((string)$invitation['email']);
        $principals[$principal] = true;
    }
    return count($principals);
}

function acquirePlanCapacityLock(array $context, string $metric): void
{
    global $pdo, $heldPlanCapacityLocks;
    if (!isset($heldPlanCapacityLocks) || !is_array($heldPlanCapacityLocks)) $heldPlanCapacityLocks = [];
    $lockKey = (string)$context['owner_id'] . ':' . $metric;
    if (isset($heldPlanCapacityLocks[$lockKey])) return;
    $lockName = 'nexaflow-cap-' . substr(hash('sha256', $lockKey), 0, 50);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) jsonResponse(false, 'Не вдалося перевірити ліміт. Спробуйте ще раз.', ['code' => 'plan_capacity_busy'], 503);
    $heldPlanCapacityLocks[$lockKey] = $lockName;
}

function releasePlanCapacityLocks(?string $ownerId = null): void
{
    global $pdo, $heldPlanCapacityLocks;
    if (empty($heldPlanCapacityLocks) || !is_array($heldPlanCapacityLocks)) return;
    foreach ($heldPlanCapacityLocks as $key => $lockName) {
        if ($ownerId !== null && !str_starts_with($key, $ownerId . ':')) continue;
        try { $release = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$lockName]); } catch (Throwable $ignored) {}
        unset($heldPlanCapacityLocks[$key]);
    }
}

function requirePlanCapacity(array $context, string $metric, int $add = 1): void
{
    global $pdo;
    $fresh = $context;
    // Serialize checks for the same workspace and refresh usage only after the
    // lock is held; concurrent requests therefore cannot pass on stale counts.
    if (($context['plan'][$metric] ?? null) !== null) {
        acquirePlanCapacityLock($context, $metric);
        $fresh = workspacePlan($pdo, (string)$context['user_id'], (string)$context['role']);
        if ((string)$fresh['owner_id'] !== (string)$context['owner_id']) jsonResponse(false, 'Робочий простір змінився. Повторіть дію.', ['code' => 'workspace_changed'], 409);
    }
    $limit = $fresh['plan'][$metric] ?? null;
    if ($limit === null) return;
    $current = (int)($fresh['counts'][$metric] ?? 0);
    if ($current + $add <= (int)$limit) return;
    notifyPlanCapacityReached($fresh, $metric, max($current, (int)$limit), (int)$limit);
    jsonResponse(false, 'Досягнуто ліміту плану «' . $fresh['plan']['name'] . '». Перегляньте доступні плани в Налаштуваннях.', ['code' => 'plan_limit_reached', 'metric' => $metric, 'limit' => (int)$limit, 'current' => $current, 'plan' => $fresh['key']], 403);
}

function planSettingsPayload(array $context): array
{
    $labels = ['projects' => 'Проєкти', 'tasks_month' => 'Задачі цього місяця', 'clients' => 'Клієнти', 'participants' => 'Учасники'];
    $limits = [];
    foreach ($labels as $key => $label) $limits[] = ['key' => $key, 'label' => $label, 'limit' => $context['plan'][$key], 'used' => $context['counts'][$key]];
    $subscription = $context['subscription'] ?? null;
    $pending = $context['pending_subscription'] ?? null;
    $pendingPlan = $pending && $pending['plan_key'] !== $context['key'] ? ['key' => $pending['plan_key'], 'name' => $context['plans'][$pending['plan_key']]['name'] ?? '', 'price' => $context['plans'][$pending['plan_key']]['price'] ?? '', 'order_id' => $pending['order_id']] : null;
    return ['key' => $context['key'], 'name' => $context['plan']['name'], 'price' => $context['plan']['price'], 'workspace_id' => $context['owner_id'], 'workspace_role' => $context['workspace_role'] ?? 'user', 'services_per_client' => $context['plan']['services_per_client'] ?? null, 'billing_enabled' => (bool)($context['billing_enabled'] ?? false), 'billing' => $subscription ? ['status' => $subscription['status'], 'current_period_end' => $subscription['current_period_end'], 'order_id' => $subscription['order_id']] : null, 'pending_plan' => $pendingPlan, 'limits' => $limits, 'features' => ['services' => (bool)$context['plan']['services'], 'calendar' => (bool)$context['plan']['calendar'], 'analytics' => (bool)$context['plan']['analytics'], 'telegram' => (bool)$context['plan']['telegram']], 'available_plans' => array_map(static fn(array $plan, string $key): array => ['key' => $key, 'name' => $plan['name'], 'price' => $plan['price'], 'amount' => $plan['amount'] ?? null, 'limits' => ['projects' => $plan['projects'], 'tasks_month' => $plan['tasks_month'], 'clients' => $plan['clients'], 'participants' => $plan['participants'], 'services_per_client' => $plan['services_per_client'] ?? null, 'managers' => $plan['managers'], 'project_leads' => $plan['project_leads'], 'moderators' => $plan['moderators']], 'features' => ['services' => $plan['services'], 'calendar' => $plan['calendar'], 'analytics' => $plan['analytics'], 'telegram' => $plan['telegram']]], $context['plans'], array_keys($context['plans']))];
}
