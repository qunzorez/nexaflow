<?php
declare(strict_types=1);

/** NexaFlow workspace plan rules. Paid plans stay locked until billing is connected. */
function planDefinitions(): array
{
    return [
        'start' => ['name' => 'Старт', 'price' => 'Безкоштовно', 'projects' => 1, 'tasks_month' => 50, 'clients' => 3, 'participants' => 2, 'services' => false, 'calendar' => false, 'analytics' => false, 'telegram' => false, 'managers' => 1, 'project_leads' => 0, 'moderators' => 0],
        'growth' => ['name' => 'Зростання', 'price' => '$7.99 / місяць', 'amount' => '7.99', 'projects' => 5, 'tasks_month' => 100, 'clients' => 30, 'participants' => 5, 'services' => true, 'calendar' => false, 'analytics' => false, 'telegram' => true, 'managers' => 1, 'project_leads' => 0, 'moderators' => 0],
        'team' => ['name' => 'Команда', 'price' => '$14.99 / місяць', 'amount' => '14.99', 'projects' => 50, 'tasks_month' => 300, 'clients' => 100, 'participants' => 10, 'services' => true, 'calendar' => true, 'analytics' => true, 'telegram' => true, 'managers' => 1, 'project_leads' => 0, 'moderators' => 0],
        'business' => ['name' => 'Бізнес', 'price' => '$24.99 / місяць', 'amount' => '24.99', 'projects' => 100, 'tasks_month' => 1000, 'clients' => 500, 'participants' => 49, 'services' => true, 'calendar' => true, 'analytics' => true, 'telegram' => true, 'managers' => 5, 'project_leads' => 5, 'moderators' => 5],
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

function workspacePlan(PDO $pdo, string $userId, string $role): array
{
    ensurePlanSchema($pdo);
    $ownerId = $userId;
    if (!in_array($role, ['admin', 'owner', 'manager'], true)) {
        $ownerQuery = $pdo->prepare("SELECT inviter.id FROM users inviter WHERE inviter.role IN ('manager','owner','admin') AND (EXISTS (SELECT 1 FROM user_invitations i WHERE i.invited_by = inviter.id AND i.email = (SELECT email FROM users WHERE id = ?) AND i.status = 'accepted') OR EXISTS (SELECT 1 FROM project_invitations i WHERE i.invited_by = inviter.id AND i.email = (SELECT email FROM users WHERE id = ?) AND i.status = 'accepted')) ORDER BY inviter.created_at ASC LIMIT 1");
        $ownerQuery->execute([$userId, $userId]);
        $ownerId = (string)($ownerQuery->fetchColumn() ?: $userId);
    }
    $planQuery = $pdo->prepare('SELECT plan_key FROM users WHERE id = ? LIMIT 1');
    $planQuery->execute([$ownerId]);
    $planKey = (string)($planQuery->fetchColumn() ?: 'start');
    $plans = planDefinitions();
    $subscription = null;
    try {
        $subscriptionQuery = $pdo->prepare("SELECT id, plan_key, status, current_period_end, order_id FROM plan_subscriptions WHERE owner_id = ? ORDER BY created_at DESC LIMIT 1");
        $subscriptionQuery->execute([$ownerId]);
        $subscription = $subscriptionQuery->fetch() ?: null;
        $accessQuery = $pdo->prepare("SELECT id, plan_key, status, current_period_end FROM plan_subscriptions WHERE owner_id = ? AND status IN ('active','cancelled') AND (current_period_end IS NULL OR current_period_end > CURRENT_TIMESTAMP) ORDER BY created_at DESC LIMIT 1");
        $accessQuery->execute([$ownerId]);
        $accessSubscription = $accessQuery->fetch() ?: null;
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
        foreach (['projects', 'clients', 'tasks_month', 'participants'] as $metric) $plan[$metric] = null;
        foreach (['services', 'calendar', 'analytics', 'telegram'] as $feature) $plan[$feature] = true;
        foreach (['managers', 'project_leads', 'moderators'] as $metric) $plan[$metric] = null;
    }
    return ['key' => $planKey, 'owner_id' => $ownerId, 'member_ids' => $ids, 'plan' => $plan, 'counts' => $counts, 'plans' => $plans, 'subscription' => $subscription];
}

function requirePlanFeature(array $context, string $feature): void
{
    if (!empty($context['plan'][$feature])) return;
    jsonResponse(false, 'Ця можливість недоступна у вашому поточному плані. Перегляньте ліміти в Налаштуваннях.', ['code' => 'plan_feature_locked', 'feature' => $feature, 'plan' => $context['key']], 403);
}

function telegramPlanAvailable(PDO $pdo, string $userId): bool
{
    static $cache = [];
    if (array_key_exists($userId, $cache)) return $cache[$userId];
    ensurePlanSchema($pdo);
    $userQuery = $pdo->prepare('SELECT id, email, role FROM users WHERE id = ? LIMIT 1');
    $userQuery->execute([$userId]);
    $user = $userQuery->fetch();
    if (!$user) return $cache[$userId] = false;
    if (in_array($user['role'], ['admin', 'owner'], true)) return $cache[$userId] = true;
    $ownerId = $userId;
    if ($user['role'] !== 'manager') {
        $ownerQuery = $pdo->prepare("SELECT inviter.id FROM users inviter WHERE inviter.role IN ('manager','owner','admin') AND (EXISTS (SELECT 1 FROM user_invitations i WHERE i.invited_by = inviter.id AND i.email = ? AND i.status = 'accepted') OR EXISTS (SELECT 1 FROM project_invitations i WHERE i.invited_by = inviter.id AND i.email = ? AND i.status = 'accepted')) ORDER BY inviter.created_at ASC LIMIT 1");
        $ownerQuery->execute([$user['email'], $user['email']]);
        $ownerId = (string)($ownerQuery->fetchColumn() ?: $userId);
    }
    $planQuery = $pdo->prepare('SELECT plan_key FROM users WHERE id = ? LIMIT 1');
    $planQuery->execute([$ownerId]);
    $key = (string)($planQuery->fetchColumn() ?: 'start');
    $plans = planDefinitions();
    return $cache[$userId] = !empty($plans[$key]['telegram']);
}

function requirePlanCapacity(array $context, string $metric, int $add = 1): void
{
    $limit = $context['plan'][$metric] ?? null;
    if ($limit === null) return;
    $current = (int)($context['counts'][$metric] ?? 0);
    if ($current + $add <= (int)$limit) return;
    jsonResponse(false, 'Досягнуто ліміту плану «' . $context['plan']['name'] . '». Перегляньте доступні плани в Налаштуваннях.', ['code' => 'plan_limit_reached', 'metric' => $metric, 'limit' => (int)$limit, 'current' => $current, 'plan' => $context['key']], 403);
}

function planSettingsPayload(array $context): array
{
    $labels = ['projects' => 'Проєкти', 'tasks_month' => 'Задачі цього місяця', 'clients' => 'Клієнти', 'participants' => 'Учасники'];
    $limits = [];
    foreach ($labels as $key => $label) $limits[] = ['key' => $key, 'label' => $label, 'limit' => $context['plan'][$key], 'used' => $context['counts'][$key]];
    $subscription = $context['subscription'] ?? null;
    return ['key' => $context['key'], 'name' => $context['plan']['name'], 'price' => $context['plan']['price'], 'billing' => $subscription ? ['status' => $subscription['status'], 'current_period_end' => $subscription['current_period_end'], 'order_id' => $subscription['order_id']] : null, 'limits' => $limits, 'features' => ['services' => (bool)$context['plan']['services'], 'calendar' => (bool)$context['plan']['calendar'], 'analytics' => (bool)$context['plan']['analytics'], 'telegram' => (bool)$context['plan']['telegram']], 'available_plans' => array_map(static fn(array $plan, string $key): array => ['key' => $key, 'name' => $plan['name'], 'price' => $plan['price'], 'amount' => $plan['amount'] ?? null, 'limits' => ['projects' => $plan['projects'], 'tasks_month' => $plan['tasks_month'], 'clients' => $plan['clients'], 'participants' => $plan['participants'], 'managers' => $plan['managers'], 'project_leads' => $plan['project_leads'], 'moderators' => $plan['moderators']], 'features' => ['services' => $plan['services'], 'calendar' => $plan['calendar'], 'analytics' => $plan['analytics'], 'telegram' => $plan['telegram']]], $context['plans'], array_keys($context['plans']))];
}
