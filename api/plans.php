<?php
declare(strict_types=1);

/** NexaFlow workspace plan limits and billing entitlements. */
function planDefinitions(): array
{
    $accounting = [
        'start' => ['name' => 'Старт', 'price' => 'Безкоштовно', 'projects' => 1, 'tasks_month' => 50, 'clients' => 3, 'participants' => 2, 'accounting' => false, 'services' => false, 'services_per_client' => 2, 'calendar' => false, 'analytics' => false, 'telegram' => false, 'support' => false, 'managers' => 1, 'project_leads' => 0, 'moderators' => 0],
        'growth' => ['name' => 'Зростання', 'price' => '$7.99 / місяць', 'amount' => '7.99', 'projects' => 5, 'tasks_month' => 100, 'clients' => 30, 'participants' => 5, 'accounting' => true, 'services' => true, 'services_per_client' => null, 'calendar' => false, 'analytics' => false, 'telegram' => true, 'support' => false, 'managers' => 1, 'project_leads' => 0, 'moderators' => 0],
        'team' => ['name' => 'Команда', 'price' => '$14.99 / місяць', 'amount' => '14.99', 'projects' => 50, 'tasks_month' => 300, 'clients' => 100, 'participants' => 10, 'accounting' => true, 'services' => true, 'services_per_client' => null, 'calendar' => true, 'analytics' => true, 'telegram' => true, 'support' => true, 'managers' => 1, 'project_leads' => 1, 'moderators' => 3],
        'business' => ['name' => 'Бізнес', 'price' => '$24.99 / місяць', 'amount' => '24.99', 'projects' => 100, 'tasks_month' => 1000, 'clients' => 500, 'participants' => 49, 'accounting' => true, 'services' => true, 'services_per_client' => null, 'calendar' => true, 'analytics' => true, 'telegram' => true, 'support' => true, 'managers' => null, 'project_leads' => 5, 'moderators' => 5],
    ];
    $dentalFeatures = [
        'dental_medical_records' => true, 'dental_service_catalog' => true, 'dental_patient_invoices' => true,
        'dental_documents' => false,
        'dental_tooth_chart' => false, 'dental_treatment_plans' => false, 'dental_inventory' => false,
        'dental_basic_reports' => false, 'dental_procurement' => false,
        'dental_procedure_costing' => false, 'dental_doctor_payroll' => false, 'dental_financial_analytics' => false,
        'dental_equipment' => false, 'dental_advanced_analytics' => false, 'dental_management_accounting' => false,
        'dental_priority_support' => false, 'services' => true, 'calendar' => true,
    ];
    $dental = [
        'dental_start' => ['name' => 'Dental Start', 'price' => 'Безкоштовно', 'amount' => '0.00', 'doctors' => 1, 'managers' => 1, 'participants' => 1, 'patients' => 10, 'clients' => 10, 'appointments_month' => 10],
        'dental_growth' => ['name' => 'Dental Growth', 'price' => '$14.99 / місяць', 'amount' => '14.99', 'doctors' => 2, 'managers' => 2, 'patients' => 100, 'clients' => 100, 'appointments_month' => 100],
        'dental_team' => ['name' => 'Dental Team', 'price' => '$29.99 / місяць', 'amount' => '29.99', 'doctors' => 5, 'managers' => 5, 'patients' => 500, 'clients' => 500, 'appointments_month' => 1000],
        'dental_business' => ['name' => 'Dental Business', 'price' => '$100 / місяць', 'amount' => '100.00', 'doctors' => 10, 'managers' => 10, 'patients' => 3000, 'clients' => 3000, 'appointments_month' => 3000],
    ];
    $growth = ['dental_tooth_chart', 'dental_treatment_plans', 'dental_inventory', 'dental_basic_reports', 'dental_documents'];
    $team = ['dental_procurement', 'dental_procedure_costing', 'dental_doctor_payroll', 'dental_financial_analytics'];
    $business = ['dental_equipment', 'dental_advanced_analytics', 'dental_management_accounting', 'dental_priority_support'];
    foreach ($dental as $key => &$plan) {
        $plan += $dentalFeatures;
        if ($key === 'dental_start') $plan['dental_tooth_chart'] = true;
        if ($key === 'dental_start') $plan['dental_treatment_plans'] = true;
        if (in_array($key, ['dental_growth', 'dental_team', 'dental_business'], true)) foreach ($growth as $feature) $plan[$feature] = true;
        if (in_array($key, ['dental_team', 'dental_business'], true)) foreach ($team as $feature) $plan[$feature] = true;
        if ($key === 'dental_business') foreach ($business as $feature) $plan[$feature] = true;
    }
    unset($plan);
    foreach ($accounting as &$plan) $plan['module'] = 'accounting';
    unset($plan);
    foreach ($dental as &$plan) $plan['module'] = 'dentistry';
    unset($plan);
    return $accounting + $dental;
}

function planModuleForKey(string $key): string
{
    return str_starts_with($key, 'dental_') ? 'dentistry' : 'accounting';
}

function systemModuleEnabled(PDO $pdo, string $module): bool
{
    try {
        $query = $pdo->prepare('SELECT enabled FROM system_module_settings WHERE module_key = ? LIMIT 1');
        $query->execute([$module]);
        $value = $query->fetchColumn();
        return $value === false ? true : (bool)$value;
    } catch (Throwable $ignored) { return true; }
}

/** Enforce a globally enabled product module before its API reads or mutates data. */
function requireSystemModuleEnabled(PDO $pdo, string $module): void
{
    if (systemModuleEnabled($pdo, $module)) return;
    jsonResponse(false, 'Цей модуль тимчасово вимкнений адміністратором.', ['code' => 'module_disabled', 'module' => $module], 403);
}

function ensureSystemModuleSettingsSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_module_settings (
        module_key VARCHAR(32) NOT NULL PRIMARY KEY,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        updated_by CHAR(32) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_system_module_enabled (enabled),
        CONSTRAINT fk_system_module_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT IGNORE INTO system_module_settings (module_key, enabled) VALUES ('accounting', 1), ('dentistry', 1)");
    $ready = true;
}

function dentalPlanFeatures(): array
{
    return ['dental_medical_records','dental_service_catalog','dental_patient_invoices','dental_documents','dental_tooth_chart','dental_treatment_plans','dental_inventory','dental_basic_reports','dental_procurement','dental_procedure_costing','dental_doctor_payroll','dental_financial_analytics','dental_equipment','dental_advanced_analytics','dental_management_accounting','dental_priority_support','services','calendar'];
}

function ensurePlanSchema(PDO $pdo): void
{
    $selectedWorkspaceColumn = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'selected_workspace_id'")->fetchColumn();
    if (!(int)$selectedWorkspaceColumn) $pdo->exec("ALTER TABLE users ADD selected_workspace_id CHAR(32) NULL");
    $moduleColumnExists = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'active_client_module'")->fetchColumn();
    if (!(int)$moduleColumnExists) $pdo->exec("ALTER TABLE users ADD active_client_module VARCHAR(40) NOT NULL DEFAULT 'accounting'");
    $exists = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'plan_key'")->fetchColumn();
    if (!(int)$exists) $pdo->exec("ALTER TABLE users ADD plan_key VARCHAR(24) NOT NULL DEFAULT 'start'");
    $supportRoleExists = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_support_agent'")->fetchColumn();
    if (!(int)$supportRoleExists) {
        $pdo->exec("ALTER TABLE users ADD is_support_agent TINYINT(1) NOT NULL DEFAULT 0");
        $pdo->exec("UPDATE users SET is_support_agent = 1 WHERE role IN ('admin', 'owner')");
    }
    // The billing table is provisioned by database/liqpay_billing.sql during
    // deployment. Do not issue CREATE TABLE on every authenticated API request.
    $subscriptionColumns = array_column($pdo->query("SHOW COLUMNS FROM plan_subscriptions")->fetchAll(), 'Field');
    if (!in_array('module_key', $subscriptionColumns, true)) {
        $pdo->exec("ALTER TABLE plan_subscriptions ADD module_key VARCHAR(24) NOT NULL DEFAULT 'accounting'");
        $pdo->exec("UPDATE plan_subscriptions SET module_key = 'dentistry' WHERE plan_key LIKE 'dental\\_%'");
        $usersColumns = array_column($pdo->query('SHOW COLUMNS FROM users')->fetchAll(), 'Field');
        if (in_array('active_client_module', $usersColumns, true)) {
            // Convert the former independent dental selector to the free dental tier.
            // Existing accounting subscriptions remain in history and retain their module.
            $pdo->exec("UPDATE users u SET plan_key = 'dental_start' WHERE u.active_client_module = 'dentistry' AND u.plan_key IN ('start','growth','team','business') AND NOT EXISTS (SELECT 1 FROM plan_subscriptions s WHERE s.owner_id = u.id AND s.module_key = 'accounting' AND s.plan_key <> 'start' AND s.status IN ('active','cancelled') AND (s.current_period_end IS NULL OR s.current_period_end > CURRENT_TIMESTAMP))");
            $pdo->exec("UPDATE plan_subscriptions SET module_key = 'accounting' WHERE plan_key IN ('start','growth','team','business')");
        }
    }
}

/** Return the teams this account has actually joined. A team is identified by its manager/owner account. */
function availableWorkspaces(PDO $pdo, string $userId, string $role): array
{
    if (in_array($role, ['admin', 'owner'], true)) return [];
    $query = $pdo->prepare("SELECT DISTINCT u.id, u.name, u.role, u.created_at, COALESCE((SELECT pg.name FROM project_groups pg JOIN project_group_members gm ON gm.group_id = pg.id WHERE gm.user_id = ? AND pg.project_id IS NULL AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(pg.permissions) THEN pg.permissions ELSE '{}' END, '$.owner_id')) = u.id ORDER BY (LOWER(pg.name) = LOWER('Керівник проєкту')) DESC, pg.name LIMIT 1), (SELECT CASE pm.role WHEN 'manager' THEN 'Керівник проєкту' WHEN 'admin' THEN 'Адміністратор проєкту' ELSE NULL END FROM project_members pm JOIN projects p ON p.id = pm.project_id WHERE pm.user_id = ? AND p.owner_id = u.id AND pm.role IN ('manager', 'admin') ORDER BY (pm.role = 'manager') DESC LIMIT 1), 'Учасник') AS workspace_role FROM users u WHERE u.id <> ? AND u.role IN ('manager','owner','admin') AND (EXISTS (SELECT 1 FROM user_invitations i WHERE i.invited_by = u.id AND i.email = (SELECT email FROM users WHERE id = ?) AND i.status = 'accepted') OR EXISTS (SELECT 1 FROM project_invitations i WHERE i.invited_by = u.id AND i.email = (SELECT email FROM users WHERE id = ?) AND i.status = 'accepted') OR EXISTS (SELECT 1 FROM project_groups pg JOIN project_group_members gm ON gm.group_id = pg.id JOIN user_invitations i ON i.invited_by = gm.user_id JOIN users invited ON LOWER(invited.email) = LOWER(i.email) WHERE JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(pg.permissions) THEN pg.permissions ELSE '{}' END, '$.owner_id')) = u.id AND LOWER(pg.name) = LOWER('Керівник проєкту') AND invited.id = ? AND i.status = 'accepted') OR EXISTS (SELECT 1 FROM project_groups pg JOIN project_group_members gm ON gm.group_id = pg.id JOIN project_invitations i ON i.invited_by = gm.user_id JOIN users invited ON LOWER(invited.email) = LOWER(i.email) JOIN projects p ON p.id = i.project_id WHERE JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(pg.permissions) THEN pg.permissions ELSE '{}' END, '$.owner_id')) = u.id AND LOWER(pg.name) = LOWER('Керівник проєкту') AND invited.id = ? AND i.status = 'accepted' AND p.owner_id = u.id)) ORDER BY u.created_at ASC");
    $query->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId]);
    $workspaces = [];
    if (in_array($role, ['manager', 'owner'], true)) {
        $self = $pdo->prepare('SELECT id, name FROM users WHERE id = ? LIMIT 1');
        $self->execute([$userId]);
        if ($row = $self->fetch()) $workspaces[] = ['id' => (string)$row['id'], 'name' => (string)$row['name'], 'workspace_role' => $role === 'owner' ? 'Власник простору' : ($role === 'admin' ? 'Адміністратор' : 'Менеджер простору'), 'is_own' => true];
    }
    foreach ($query->fetchAll() as $row) $workspaces[] = ['id' => (string)$row['id'], 'name' => (string)$row['name'], 'workspace_role' => (string)$row['workspace_role'], 'is_own' => false];
    if (!$workspaces) {
        $self = $pdo->prepare('SELECT id, name FROM users WHERE id = ? LIMIT 1');
        $self->execute([$userId]);
        if ($row = $self->fetch()) $workspaces[] = ['id' => (string)$row['id'], 'name' => (string)$row['name'], 'workspace_role' => 'Особистий простір', 'is_own' => true];
    }
    return $workspaces;
}

function activeWorkspaceId(PDO $pdo, string $userId, string $role): string
{
    $workspaces = availableWorkspaces($pdo, $userId, $role);
    $allowed = array_column($workspaces, 'id');
    $selectedQuery = $pdo->prepare('SELECT selected_workspace_id FROM users WHERE id = ? LIMIT 1');
    $selectedQuery->execute([$userId]);
    $stored = (string)($selectedQuery->fetchColumn() ?: '');
    $requested = $stored !== '' ? $stored : (string)($_SESSION['active_workspace_id'] ?? '');
    if ($requested !== '' && in_array($requested, $allowed, true)) {
        if ($requested !== $stored) {
            $pdo->prepare('UPDATE users SET selected_workspace_id = ? WHERE id = ?')->execute([$requested, $userId]);
        }
        $_SESSION['active_workspace_id'] = $requested;
        return $requested;
    }
    // Keep the previous default for members while managers start in their own workspace.
    $default = (string)($workspaces[0]['id'] ?? $userId);
    $pdo->prepare('UPDATE users SET selected_workspace_id = ? WHERE id = ?')->execute([$default, $userId]);
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
        $moduleKey = planModuleForKey($planKey);
        $subscriptionQuery = $pdo->prepare("SELECT id, plan_key, module_key, status, current_period_end, order_id FROM plan_subscriptions WHERE owner_id = ? AND module_key = ? ORDER BY created_at DESC LIMIT 1");
        $subscriptionQuery->execute([$ownerId, $moduleKey]);
        $latestSubscription = $subscriptionQuery->fetch() ?: null;
        $pendingQuery = $pdo->prepare("SELECT id, plan_key, status, current_period_end, order_id FROM plan_subscriptions WHERE owner_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1");
        $pendingQuery->execute([$ownerId]);
        $pendingSubscription = $pendingQuery->fetch() ?: null;
        $accessQuery = $pdo->prepare("SELECT id, plan_key, module_key, status, current_period_end, order_id FROM plan_subscriptions WHERE owner_id = ? AND module_key = ? AND status IN ('active','cancelled') AND (current_period_end IS NULL OR current_period_end > CURRENT_TIMESTAMP) ORDER BY created_at DESC LIMIT 1");
        $accessQuery->execute([$ownerId, $moduleKey]);
        $accessSubscription = $accessQuery->fetch() ?: null;
        $subscription = $accessSubscription ?: $latestSubscription;
        if ($accessSubscription) {
            $planKey = (string)$accessSubscription['plan_key'];
        } else {
            $expiredQuery = $pdo->prepare("SELECT id FROM plan_subscriptions WHERE owner_id = ? AND module_key = ? AND status IN ('active','cancelled') AND current_period_end <= CURRENT_TIMESTAMP ORDER BY created_at DESC LIMIT 1");
            $expiredQuery->execute([$ownerId, $moduleKey]);
            $expiredId = $expiredQuery->fetchColumn();
            if ($expiredId) {
                $pdo->prepare("UPDATE plan_subscriptions SET status = 'expired' WHERE id = ? AND status IN ('active','cancelled')")->execute([$expiredId]);
                $planKey = $moduleKey === 'dentistry' ? 'dental_start' : 'start';
                $pdo->prepare('UPDATE users SET plan_key = ?, active_client_module = ? WHERE id = ?')->execute([$planKey, $moduleKey, $ownerId]);
            }
        }
    } catch (Throwable $ignored) {
        $subscription = null;
        $pendingSubscription = null;
      }
    }
    if (!isset($plans[$planKey])) $planKey = 'start';
    $plan = $plans[$planKey];
    $moduleKey = planModuleForKey($planKey);
    $moduleColumn = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'active_client_module'")->fetchColumn();
    if ($moduleColumn) $pdo->prepare('UPDATE users SET active_client_module = ? WHERE id = ? AND active_client_module <> ?')->execute([$moduleKey, $ownerId, $moduleKey]);
    $leadActorsQuery = $pdo->prepare("SELECT DISTINCT gm.user_id FROM project_groups pg JOIN project_group_members gm ON gm.group_id = pg.id WHERE pg.project_id IS NULL AND LOWER(pg.name) = LOWER('Керівник проєкту') AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(pg.permissions) THEN pg.permissions ELSE '{}' END, '$.owner_id')) = ?");
    $leadActorsQuery->execute([$ownerId]);
    $leadActorIds = array_map('strval', $leadActorsQuery->fetchAll(PDO::FETCH_COLUMN));
    $invitationActorIds = array_values(array_unique(array_merge([$ownerId], $leadActorIds)));
    $ids = array_values(array_unique(array_merge([$ownerId], $leadActorIds)));
    // Leads can invite under the same workspace. Walk accepted invitations
    // from workspace members so their invitees, projects and clients share its plan.
    for ($depth = 0; $depth < 8; $depth++) {
        $memberMarks = implode(',', array_fill(0, count($ids), '?'));
        $membersQuery = $pdo->prepare("SELECT DISTINCT u.id FROM users u WHERE EXISTS (SELECT 1 FROM user_invitations i WHERE i.invited_by IN ({$memberMarks}) AND i.email = u.email AND i.status = 'accepted') OR EXISTS (SELECT 1 FROM project_invitations i WHERE i.invited_by IN ({$memberMarks}) AND i.email = u.email AND i.status = 'accepted')");
        $membersQuery->execute(array_merge($ids, $ids));
        $nextIds = array_values(array_unique(array_merge($ids, array_map('strval', $membersQuery->fetchAll(PDO::FETCH_COLUMN)))));
        if (count($nextIds) === count($ids)) break;
        $ids = $nextIds;
    }
    $ids = array_values(array_unique($ids));
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $count = static function (string $sql) use ($pdo, $ids): int { $q = $pdo->prepare($sql); $q->execute($ids); return (int)$q->fetchColumn(); };
    $projectCount = $pdo->prepare('SELECT COUNT(*) FROM projects WHERE owner_id = ?');
    $projectCount->execute([$ownerId]);
    $inviterMarks = implode(',', array_fill(0, count($invitationActorIds), '?'));
    $pending = $pdo->prepare("SELECT email FROM user_invitations WHERE invited_by IN ({$inviterMarks}) AND status = 'pending' UNION SELECT email FROM project_invitations WHERE invited_by IN ({$inviterMarks}) AND status = 'pending'");
    $pending->execute(array_merge($invitationActorIds, $invitationActorIds));
    $knownEmails = [];
    if ($ids) { $idMarks = implode(',', array_fill(0, count($ids), '?')); $emailQuery = $pdo->prepare("SELECT email FROM users WHERE id IN ($idMarks)"); $emailQuery->execute($ids); $knownEmails = array_fill_keys(array_map('strtolower', $emailQuery->fetchAll(PDO::FETCH_COLUMN)), true); }
    $pendingCount = 0;
    foreach ($pending->fetchAll(PDO::FETCH_COLUMN) as $pendingEmail) if (!isset($knownEmails[strtolower((string)$pendingEmail)])) $pendingCount++;
    $counts = [
        'projects' => (int)$projectCount->fetchColumn(),
        'clients' => $count("SELECT COUNT(*) FROM clients WHERE created_by IN ($marks)"),
        'tasks_month' => $count("SELECT COUNT(DISTINCT t.id) FROM tasks t WHERE t.created_by IN ($marks) AND t.status <> 'archived' AND ((t.created_at >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01') AND t.created_at < DATE_ADD(DATE_FORMAT(CURRENT_DATE, '%Y-%m-01'), INTERVAL 1 MONTH)) OR EXISTS (SELECT 1 FROM task_history h WHERE h.task_id = t.id AND h.action = 'restored_from_archive' AND h.created_at >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01') AND h.created_at < DATE_ADD(DATE_FORMAT(CURRENT_DATE, '%Y-%m-01'), INTERVAL 1 MONTH)))"),
        'participants' => max(0, count($ids) - 1) + $pendingCount,
    ];
    if ($moduleKey === 'dentistry') {
        $patientCount = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE created_by = ? AND module = 'dentistry'");
        $patientCount->execute([$ownerId]);
        $counts['patients'] = (int)$patientCount->fetchColumn();
        $counts['clients'] = $counts['patients'];
        $monthStart = (new DateTimeImmutable('first day of this month 00:00:00', new DateTimeZone('Europe/Kyiv')))->format('Y-m-d H:i:s');
        $nextMonth = (new DateTimeImmutable('first day of next month 00:00:00', new DateTimeZone('Europe/Kyiv')))->format('Y-m-d H:i:s');
        try {
            $appointments = $pdo->prepare("SELECT COUNT(DISTINCT t.id) FROM tasks t JOIN task_clients tc ON tc.task_id=t.id JOIN clients c ON c.id=tc.client_id LEFT JOIN dental_appointment_details ad ON ad.appointment_id=t.id WHERE c.created_by=? AND c.module='dentistry' AND t.is_client_appointment=1 AND t.status <> 'cancelled' AND COALESCE(ad.appointment_status,'scheduled') <> 'cancelled' AND t.due_date >= ? AND t.due_date < ?");
            $appointments->execute([$ownerId, $monthStart, $nextMonth]);
            $counts['appointments_month'] = (int)$appointments->fetchColumn();
        } catch (Throwable $ignored) { $counts['appointments_month'] = 0; }
        try {
            $doctorCount = $pdo->prepare("SELECT COUNT(DISTINCT assigned.user_id) FROM (SELECT t.assignee_id user_id,t.id FROM tasks t WHERE t.is_client_appointment=1 AND t.assignee_id IS NOT NULL UNION SELECT ta.user_id,ta.task_id FROM task_assignees ta JOIN tasks t ON t.id=ta.task_id WHERE t.is_client_appointment=1) assigned JOIN task_clients tc ON tc.task_id=assigned.id JOIN clients c ON c.id=tc.client_id WHERE c.created_by=? AND c.module='dentistry'");
            $doctorCount->execute([$ownerId]);
            $counts['doctors'] = max(1, (int)$doctorCount->fetchColumn());
        } catch (Throwable $ignored) { $counts['doctors'] = 0; }
    }
    $managerCount = $pdo->prepare("SELECT COUNT(DISTINCT u.id) FROM users u WHERE u.role = 'manager' AND (EXISTS (SELECT 1 FROM user_invitations i WHERE i.email = u.email AND i.invited_by = ? AND i.status = 'accepted') OR EXISTS (SELECT 1 FROM project_invitations i WHERE i.email = u.email AND i.invited_by = ? AND i.status = 'accepted'))");
    $managerCount->execute([$ownerId, $ownerId]);
    $counts['managers'] = (int)$managerCount->fetchColumn() + 1;
    if ($moduleKey === 'dentistry') $counts['doctors'] = $counts['managers'];
    foreach (['project_leads' => 'Керівник проєкту', 'moderators' => 'Модератор'] as $metric => $groupName) {
        if ($metric === 'project_leads') {
            $counts[$metric] = planProjectLeadCount($pdo, ['owner_id' => $ownerId, 'member_ids' => $ids]);
        } else {
            $roleCount = $pdo->prepare("SELECT COUNT(DISTINCT gm.user_id) FROM project_groups pg JOIN project_group_members gm ON gm.group_id = pg.id WHERE pg.project_id IS NULL AND LOWER(pg.name) = LOWER(?) AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(pg.permissions) THEN pg.permissions ELSE '{}' END, '$.owner_id')) = ?");
            $roleCount->execute([$groupName, $ownerId]);
            $counts[$metric] = (int)$roleCount->fetchColumn();
        }
    }
    $workspaceRole = $ownerId === $userId ? $role : 'user';
    if ($workspaceRole === 'user' && (int)($plan['project_leads'] ?? 0) > 0 && $counts['project_leads'] <= (int)$plan['project_leads']) {
        $leadMembership = $pdo->prepare("SELECT 1 FROM project_groups pg JOIN project_group_members gm ON gm.group_id = pg.id WHERE pg.project_id IS NULL AND LOWER(pg.name) = LOWER('Керівник проєкту') AND gm.user_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(pg.permissions) THEN pg.permissions ELSE '{}' END, '$.owner_id')) = ? LIMIT 1");
        $leadMembership->execute([$userId, $ownerId]);
        if ($leadMembership->fetchColumn()) $workspaceRole = 'project_lead';
    }
    if (in_array($role, ['admin', 'owner'], true) && $moduleKey === 'accounting') {
        foreach (['projects', 'clients', 'tasks_month', 'participants', 'services_per_client'] as $metric) $plan[$metric] = null;
        foreach (['services', 'calendar', 'analytics', 'telegram'] as $feature) $plan[$feature] = true;
        foreach (['managers', 'project_leads', 'moderators'] as $metric) $plan[$metric] = null;
    }
    return ['key' => $planKey, 'module' => $moduleKey, 'owner_id' => $ownerId, 'user_id' => $userId, 'role' => $role, 'workspace_role' => $workspaceRole, 'member_ids' => $ids, 'lead_actor_ids' => $leadActorIds, 'workspaces' => $workspaces, 'plan' => $plan, 'counts' => $counts, 'plans' => $plans, 'subscription' => $subscription, 'pending_subscription' => $pendingSubscription, 'billing_enabled' => $billingEnabled];
}

function requirePlanFeature(array $context, string $feature): void
{
    if (!empty($context['plan'][$feature])) return;
    $requiredPlan = null;
    foreach (($context['plans'] ?? planDefinitions()) as $candidate) {
        if (($candidate['module'] ?? 'accounting') === ($context['module'] ?? 'accounting') && !empty($candidate[$feature])) { $requiredPlan = $candidate['name']; break; }
    }
    jsonResponse(false, 'Можливість недоступна у плані «' . ($context['plan']['name'] ?? '') . '».' . ($requiredPlan ? ' Її відкриває план «' . $requiredPlan . '».' : ' Перегляньте доступ до функцій у налаштуваннях.'), ['code' => 'plan_feature_locked', 'feature' => $feature, 'plan' => $context['key'], 'required_plan' => $requiredPlan], 403);
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
    $labels = ['projects' => 'проєктів', 'clients' => 'клієнтів', 'patients' => 'пацієнтів', 'appointments_month' => 'прийомів цього місяця', 'doctors' => 'лікарів', 'tasks_month' => 'задач цього місяця', 'participants' => 'учасників', 'services_per_client' => 'окремих послуг на клієнта', 'managers' => 'менеджерів', 'project_leads' => 'керівників проєктів', 'moderators' => 'модераторів'];
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
    $ownerIds = [(string)$context['owner_id']];
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
    $groupCount = $pdo->prepare("SELECT DISTINCT gm.user_id FROM project_groups pg JOIN project_group_members gm ON gm.group_id = pg.id WHERE pg.project_id IS NULL AND LOWER(pg.name) = LOWER('Керівник проєкту') AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(pg.permissions) THEN pg.permissions ELSE '{}' END, '$.owner_id')) = ?");
    $groupCount->execute([(string)$context['owner_id']]);
    foreach ($groupCount->fetchAll(PDO::FETCH_COLUMN) as $userId) $principals[(string)$userId] = true;
    return count($principals);
}

function planClaimRoleCount(PDO $pdo, array $context, string $roleName): int
{
    $query = $pdo->prepare("SELECT COUNT(DISTINCT gm.user_id) FROM project_groups pg JOIN project_group_members gm ON gm.group_id = pg.id WHERE pg.project_id IS NULL AND LOWER(pg.name) = LOWER(?) AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(pg.permissions) THEN pg.permissions ELSE '{}' END, '$.owner_id')) = ?");
    $query->execute([$roleName, (string)$context['owner_id']]);
    return (int)$query->fetchColumn();
}

function planRoleLimitOverages(PDO $pdo, array $context, string $targetPlanKey): array
{
    $plans = $context['plans'] ?? planDefinitions();
    if (!isset($plans[$targetPlanKey])) return [];
    $counts = [
        'managers' => (int)($context['counts']['managers'] ?? 1),
        'moderators' => planClaimRoleCount($pdo, $context, 'Модератор'),
    ];
    $overages = [];
    foreach ($counts as $metric => $used) {
        $limit = $plans[$targetPlanKey][$metric] ?? null;
        if ($limit !== null && $used > (int)$limit) $overages[] = ['metric' => $metric, 'used' => $used, 'limit' => (int)$limit];
    }
    return $overages;
}

function normalizeProjectLeadAssignmentsForPlan(PDO $pdo, array $context, string $targetPlanKey): void
{
    $plans = $context['plans'] ?? planDefinitions();
    if (!isset($plans[$targetPlanKey])) return;
    $limit = $plans[$targetPlanKey]['project_leads'] ?? null;
    if ($limit === null) return;

    $ownerId = (string)$context['owner_id'];
    $principals = [];
    $groupQuery = $pdo->prepare("SELECT DISTINCT gm.user_id FROM project_groups pg JOIN project_group_members gm ON gm.group_id = pg.id WHERE pg.project_id IS NULL AND LOWER(pg.name) = LOWER('Керівник проєкту') AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(pg.permissions) THEN pg.permissions ELSE '{}' END, '$.owner_id')) = ?");
    $groupQuery->execute([$ownerId]);
    foreach ($groupQuery->fetchAll(PDO::FETCH_COLUMN) as $userId) $principals['user:' . (string)$userId] = ['user_id' => (string)$userId, 'email' => null];

    $projectQuery = $pdo->prepare("SELECT DISTINCT pm.user_id FROM project_members pm JOIN projects p ON p.id = pm.project_id WHERE p.owner_id = ? AND pm.role = 'manager'");
    $projectQuery->execute([$ownerId]);
    foreach ($projectQuery->fetchAll(PDO::FETCH_COLUMN) as $userId) $principals['user:' . (string)$userId] = ['user_id' => (string)$userId, 'email' => null];

    $inviteQuery = $pdo->prepare("SELECT DISTINCT LOWER(pi.email) AS email, u.id AS user_id FROM project_invitations pi JOIN projects p ON p.id = pi.project_id LEFT JOIN users u ON LOWER(u.email) = LOWER(pi.email) WHERE p.owner_id = ? AND pi.status = 'pending' AND pi.role = 'manager'");
    $inviteQuery->execute([$ownerId]);
    foreach ($inviteQuery->fetchAll() as $invitation) {
        $userId = (string)($invitation['user_id'] ?? '');
        $email = strtolower((string)$invitation['email']);
        $key = $userId !== '' ? 'user:' . $userId : 'email:' . $email;
        $principals[$key] = ['user_id' => $userId !== '' ? $userId : null, 'email' => $email];
    }
    ksort($principals, SORT_STRING);
    if (count($principals) <= (int)$limit) return;

    $demotePrincipals = array_slice($principals, (int)$limit, null, true);
    $demoteIds = array_values(array_unique(array_filter(array_column($demotePrincipals, 'user_id'))));
    $demoteEmails = array_values(array_unique(array_filter(array_column($demotePrincipals, 'email'))));
    $ownedGroupQuery = $pdo->prepare("SELECT pg.id FROM project_groups pg WHERE pg.project_id IS NULL AND LOWER(pg.name) = LOWER('Керівник проєкту') AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(pg.permissions) THEN pg.permissions ELSE '{}' END, '$.owner_id')) = ?");
    $ownedGroupQuery->execute([$ownerId]);
    $groupIds = array_map('strval', $ownedGroupQuery->fetchAll(PDO::FETCH_COLUMN));
    if ($groupIds) {
        $groupMarks = implode(',', array_fill(0, count($groupIds), '?'));
        if ($demoteIds) {
            $userMarks = implode(',', array_fill(0, count($demoteIds), '?'));
            $pdo->prepare("DELETE FROM project_group_members WHERE user_id IN ({$userMarks}) AND group_id IN ({$groupMarks})")->execute(array_merge($demoteIds, $groupIds));
        }
    }
    if ($demoteIds) {
        $userMarks = implode(',', array_fill(0, count($demoteIds), '?'));
        $pdo->prepare("UPDATE project_members pm JOIN projects p ON p.id = pm.project_id SET pm.role = 'member' WHERE p.owner_id = ? AND pm.role = 'manager' AND pm.user_id IN ({$userMarks})")->execute(array_merge([$ownerId], $demoteIds));
    }
    if ($demoteEmails) {
        $emailMarks = implode(',', array_fill(0, count($demoteEmails), '?'));
        $pdo->prepare("UPDATE project_invitations pi JOIN projects p ON p.id = pi.project_id SET pi.role = 'member' WHERE p.owner_id = ? AND pi.status = 'pending' AND pi.role = 'manager' AND LOWER(pi.email) IN ({$emailMarks})")->execute(array_merge([$ownerId], $demoteEmails));
    }
}

function requirePlanRoleCapacity(PDO $pdo, array $context, string $targetPlanKey): void
{
    $overages = planRoleLimitOverages($pdo, $context, $targetPlanKey);
    if (!$overages) return;
    $labels = ['managers' => 'менеджерів', 'project_leads' => 'керівників проєктів', 'moderators' => 'модераторів'];
    $details = array_map(static fn(array $item): string => ($labels[$item['metric']] ?? $item['metric']) . ': ' . $item['used'] . ' із ' . $item['limit'], $overages);
    jsonResponse(false, 'Не можна перейти на цей план: кількість ролей перевищує його ліміти (' . implode('; ', $details) . '). Спершу змініть або зніміть зайві ролі в розділі «Команда».', ['code' => 'plan_role_limit_exceeded', 'roles' => $overages, 'target_plan' => $targetPlanKey], 409);
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

function requireDentalAppointmentCapacity(PDO $pdo, array $context, string $scheduledAt, int $add = 1): void
{
    if (($context['module'] ?? '') !== 'dentistry') return;
    $limit = $context['plan']['appointments_month'] ?? null;
    if ($limit === null) return;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', str_replace('T', ' ', substr($scheduledAt, 0, 19)), new DateTimeZone('Europe/Kyiv'));
    if (!$date) jsonResponse(false, 'Вкажіть коректну дату прийому.', [], 422);
    $monthStart = $date->modify('first day of this month')->setTime(0, 0)->format('Y-m-d H:i:s');
    $monthEnd = $date->modify('first day of next month')->setTime(0, 0)->format('Y-m-d H:i:s');
    $metric = 'appointments_month:' . $date->format('Y-m');
    acquirePlanCapacityLock($context, $metric);
    $query = $pdo->prepare("SELECT COUNT(DISTINCT t.id) FROM tasks t JOIN task_clients tc ON tc.task_id=t.id JOIN clients c ON c.id=tc.client_id LEFT JOIN dental_appointment_details ad ON ad.appointment_id=t.id WHERE c.created_by=? AND c.module='dentistry' AND t.is_client_appointment=1 AND t.status <> 'cancelled' AND COALESCE(ad.appointment_status,'scheduled') <> 'cancelled' AND t.due_date >= ? AND t.due_date < ?");
    $query->execute([(string)$context['owner_id'], $monthStart, $monthEnd]);
    $current = (int)$query->fetchColumn();
    if ($current + $add <= (int)$limit) return;
    notifyPlanCapacityReached($context, 'appointments_month', max($current, (int)$limit), (int)$limit);
    jsonResponse(false, 'Вичерпано ліміт прийомів на ' . $date->format('m.Y') . ' у плані «' . $context['plan']['name'] . '».', ['code' => 'plan_limit_reached', 'metric' => 'appointments_month', 'month' => $date->format('Y-m'), 'limit' => (int)$limit, 'current' => $current, 'plan' => $context['key']], 403);
}

function requireDentalDoctors(PDO $pdo, array $context, array $doctorIds): void
{
    $doctorIds = array_values(array_unique(array_filter(array_map('strval', $doctorIds))));
    if (!$doctorIds || ($context['module'] ?? '') !== 'dentistry') return;
    $workspaceMembers = array_fill_keys(array_map('strval', $context['member_ids'] ?? [$context['owner_id']]), true);
    $doctorMarks = implode(',', array_fill(0, count($doctorIds), '?'));
    $eligibleQuery = $pdo->prepare("SELECT id FROM users WHERE id IN ({$doctorMarks}) AND role IN ('manager','owner')");
    $eligibleQuery->execute($doctorIds);
    $eligible = array_fill_keys(array_map('strval', $eligibleQuery->fetchAll(PDO::FETCH_COLUMN)), true);
    foreach ($pdo->query('SELECT id, name, permissions FROM project_groups WHERE project_id IS NULL')->fetchAll() as $group) {
        $policy = groupPolicy($group['permissions'] ?? null);
        if (($policy['owner_id'] ?? '') !== (string)$context['owner_id'] || mb_strtolower(trim((string)$group['name']), 'UTF-8') !== 'лікар') continue;
        $groupMembers = $pdo->prepare('SELECT user_id FROM project_group_members WHERE group_id = ?');
        $groupMembers->execute([(string)$group['id']]);
        foreach ($groupMembers->fetchAll(PDO::FETCH_COLUMN) as $memberId) $eligible[(string)$memberId] = true;
    }
    foreach ($doctorIds as $doctorId) {
        if (!isset($workspaceMembers[$doctorId]) || !isset($eligible[$doctorId])) jsonResponse(false, 'Відповідальним можна обрати лише лікаря з команди цього простору.', ['code' => 'appointment_doctor_role_invalid'], 422);
    }
}

function planSettingsPayload(array $context): array
{
    $isDental = ($context['module'] ?? 'accounting') === 'dentistry';
    $labels = $isDental ? ['doctors' => 'Лікарі', 'participants' => 'Виконавці', 'patients' => 'Пацієнти', 'appointments_month' => 'Записи цього місяця'] : ['projects' => 'Проєкти', 'tasks_month' => 'Задачі цього місяця', 'clients' => 'Клієнти', 'participants' => 'Учасники', 'managers' => 'Менеджери', 'project_leads' => 'Керівники проєктів', 'moderators' => 'Модератори'];
    $limits = [];
    foreach ($labels as $key => $label) $limits[] = ['key' => $key, 'label' => $label, 'limit' => $context['plan'][$key] ?? null, 'used' => $context['counts'][$key] ?? 0];
    $subscription = $context['subscription'] ?? null;
    $pending = $context['pending_subscription'] ?? null;
    $pendingPlan = $pending && $pending['plan_key'] !== $context['key'] ? ['key' => $pending['plan_key'], 'module' => $pending['module_key'] ?? planModuleForKey((string)$pending['plan_key']), 'name' => $context['plans'][$pending['plan_key']]['name'] ?? '', 'price' => $context['plans'][$pending['plan_key']]['price'] ?? '', 'order_id' => $pending['order_id']] : null;
    $availablePlans = [];
    $moduleAvailability = [
        'accounting' => systemModuleEnabled(db(), 'accounting'),
        'dentistry' => systemModuleEnabled(db(), 'dentistry'),
    ];
    foreach ($context['plans'] as $key => $plan) {
        $planIsDental = ($plan['module'] ?? 'accounting') === 'dentistry';
        if (empty($moduleAvailability[$planIsDental ? 'dentistry' : 'accounting'])) continue;
        $planLabels = $planIsDental ? ['doctors' => 'Лікарі', 'participants' => 'Виконавці', 'patients' => 'Пацієнти', 'appointments_month' => 'Записи цього місяця'] : ['projects' => 'Проєкти', 'tasks_month' => 'Задачі цього місяця', 'clients' => 'Клієнти', 'participants' => 'Учасники', 'managers' => 'Менеджери', 'project_leads' => 'Керівники проєктів', 'moderators' => 'Модератори'];
        $planLimits = [];
        foreach ($planLabels as $metric => $label) $planLimits[$metric] = $plan[$metric] ?? null;
        $planFeatures = [];
        foreach ($planIsDental ? dentalPlanFeatures() : ['accounting','services','calendar','analytics','telegram','support'] as $feature) $planFeatures[$feature] = (bool)($plan[$feature] ?? false);
        $availablePlans[] = ['key' => $key, 'module' => $plan['module'] ?? 'accounting', 'name' => $plan['name'], 'price' => $plan['price'], 'amount' => $plan['amount'] ?? null, 'limits' => $planLimits, 'features' => $planFeatures];
    }
    $features = [];
    foreach ($isDental ? dentalPlanFeatures() : ['accounting','services','calendar','analytics','telegram','support'] as $feature) $features[$feature] = (bool)($context['plan'][$feature] ?? false);
    return ['key' => $context['key'], 'module' => $context['module'] ?? 'accounting', 'name' => $context['plan']['name'], 'price' => $context['plan']['price'], 'workspace_id' => $context['owner_id'], 'workspace_role' => $context['workspace_role'] ?? 'user', 'services_per_client' => $context['plan']['services_per_client'] ?? null, 'billing_enabled' => (bool)($context['billing_enabled'] ?? false), 'modules' => $moduleAvailability, 'billing' => $subscription ? ['status' => $subscription['status'], 'current_period_end' => $subscription['current_period_end'], 'order_id' => $subscription['order_id']] : null, 'pending_plan' => $pendingPlan, 'limits' => $limits, 'features' => $features, 'available_plans' => $availablePlans];
}
