<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_once __DIR__ . '/plans.php';
require __DIR__ . '/mailer.php';

session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);

$pdo = db();
$userId = (string)$_SESSION['user_id'];
$pdo->exec("CREATE TABLE IF NOT EXISTS client_members (client_id CHAR(32) NOT NULL, user_id CHAR(32) NOT NULL, assigned_by CHAR(32) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (client_id, user_id), INDEX idx_client_members_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
function projectInviteUrl(string $token): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'nexaflow.top';
    return appUrl('/accept-invite?token=') . urlencode($token);
}
$current = $pdo->prepare('SELECT id, role FROM users WHERE id = ? LIMIT 1');
$current->execute([$_SESSION['user_id']]);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$data = $method === 'POST' ? body() : [];
$action = (string)($data['action'] ?? '');
$currentUser = $current->fetch();
$planContext = $currentUser ? workspacePlan($pdo, (string)$_SESSION['user_id'], (string)$currentUser['role']) : null;
if ($planContext && ($planContext['workspace_role'] ?? '') === 'manager') {
    normalizeProjectLeadAssignmentsForPlan($pdo, $planContext, (string)$planContext['key']);
}
$canManage = $currentUser && in_array($planContext['workspace_role'] ?? '', ['admin', 'owner', 'manager', 'project_lead'], true);
$isProjectLead = ($planContext['workspace_role'] ?? '') === 'project_lead';
$isAdmin = $currentUser && in_array($currentUser['role'], ['admin', 'owner'], true);
if (!$currentUser || !$canManage) {
    jsonResponse(false, 'Недостатньо прав для керування командою.', [], 403);
}
try {
    if ($method === 'GET') {
        if ($isAdmin) {
            $users = $pdo->query('SELECT id, name, email, role, created_at FROM users ORDER BY name')->fetchAll();
        } else {
            $workspaceLeadIds = array_map('strval', $planContext['lead_actor_ids'] ?? []);
            $inviterIds = $isProjectLead ? [$userId] : array_values(array_unique(array_merge([(string)$planContext['owner_id']], $workspaceLeadIds)));
            $inviterMarks = implode(',', array_fill(0, count($inviterIds), '?'));
            $usersQuery = $pdo->prepare("SELECT DISTINCT u.id, u.name, u.email, u.role, u.created_at, (SELECT pgm.group_id FROM project_group_members pgm JOIN project_groups pgg ON pgg.id = pgm.group_id WHERE pgm.user_id = u.id AND pgg.project_id IS NULL LIMIT 1) AS group_id, (EXISTS (SELECT 1 FROM project_invitations pi2 WHERE pi2.email = u.email AND pi2.invited_by = ? AND pi2.status = 'accepted') OR EXISTS (SELECT 1 FROM user_invitations ui2 WHERE ui2.email = u.email AND ui2.invited_by = ? AND ui2.status = 'accepted')) AS manageable_by_actor FROM users u WHERE EXISTS (SELECT 1 FROM project_invitations pi WHERE pi.email = u.email AND pi.invited_by IN ({$inviterMarks}) AND pi.status = 'accepted') OR EXISTS (SELECT 1 FROM user_invitations ui WHERE ui.email = u.email AND ui.invited_by IN ({$inviterMarks}) AND ui.status = 'accepted') ORDER BY u.created_at DESC");
            $usersQuery->execute(array_merge([$userId, $userId], $inviterIds, $inviterIds));
            $users = $usersQuery->fetchAll();
        }
        if ($isAdmin) {
            $pendingTeamInvites = $pdo->query("SELECT id, email, group_id, expires_at, invited_by, CASE WHEN expires_at <= NOW() THEN 'expired' ELSE 'pending' END AS invite_status, 'team' AS invite_type, NULL AS project_name FROM user_invitations WHERE status = 'pending' ORDER BY created_at DESC")->fetchAll();
            $pendingProjectInvites = $pdo->query("SELECT pi.id, pi.email, pi.group_id, pi.expires_at, pi.invited_by, CASE WHEN pi.expires_at <= NOW() THEN 'expired' ELSE 'pending' END AS invite_status, 'project' AS invite_type, p.name AS project_name FROM project_invitations pi JOIN projects p ON p.id = pi.project_id WHERE pi.status = 'pending' ORDER BY pi.created_at DESC")->fetchAll();
        } else {
            $pendingActors = $isProjectLead ? [$userId] : array_values(array_unique(array_merge([(string)$planContext['owner_id']], array_map('strval', $planContext['lead_actor_ids'] ?? []))));
            $pendingMarks = implode(',', array_fill(0, count($pendingActors), '?'));
            $pendingTeamQuery = $pdo->prepare("SELECT id, email, group_id, expires_at, invited_by, CASE WHEN expires_at <= NOW() THEN 'expired' ELSE 'pending' END AS invite_status, 'team' AS invite_type, NULL AS project_name FROM user_invitations WHERE invited_by IN ({$pendingMarks}) AND status = 'pending' ORDER BY created_at DESC");
            $pendingTeamQuery->execute($pendingActors);
            $pendingTeamInvites = $pendingTeamQuery->fetchAll();
            $pendingProjectQuery = $pdo->prepare("SELECT pi.id, pi.email, pi.group_id, pi.expires_at, pi.invited_by, CASE WHEN pi.expires_at <= NOW() THEN 'expired' ELSE 'pending' END AS invite_status, 'project' AS invite_type, p.name AS project_name FROM project_invitations pi JOIN projects p ON p.id = pi.project_id WHERE pi.invited_by IN ({$pendingMarks}) AND pi.status = 'pending' AND p.owner_id = ? ORDER BY pi.created_at DESC");
            $pendingProjectQuery->execute(array_merge($pendingActors, [(string)$planContext['owner_id']]));
            $pendingProjectInvites = $pendingProjectQuery->fetchAll();
        }
        $pendingInvites = array_merge($pendingTeamInvites, $pendingProjectInvites);
        usort($pendingInvites, static fn(array $left, array $right): int => strcmp((string)$right['expires_at'], (string)$left['expires_at']));
        if ($isAdmin) {
            $projects = $pdo->query('SELECT id, name FROM projects ORDER BY name')->fetchAll();
            $members = $pdo->query('SELECT pm.project_id, pm.user_id, pm.role, p.name AS project_name, u.name AS user_name, u.email AS user_email FROM project_members pm JOIN projects p ON p.id = pm.project_id JOIN users u ON u.id = pm.user_id ORDER BY p.name, u.name')->fetchAll();
        } else {
            $projectsQuery = $pdo->prepare("SELECT DISTINCT p.id, p.name FROM projects p LEFT JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ? WHERE p.owner_id = ? OR pm.user_id IS NOT NULL ORDER BY p.name");
            $projectsQuery->execute([$_SESSION['user_id'], $planContext['owner_id']]);
            $projects = $projectsQuery->fetchAll();
            $membersQuery = $pdo->prepare("SELECT pm.project_id, pm.user_id, pm.role, p.name AS project_name, u.name AS user_name, u.email AS user_email FROM project_members pm JOIN projects p ON p.id = pm.project_id JOIN users u ON u.id = pm.user_id JOIN project_invitations pi ON pi.project_id = pm.project_id AND pi.email = u.email WHERE pi.invited_by = ? AND pi.status = 'accepted' ORDER BY p.name, u.name");
            $membersQuery->execute([$_SESSION['user_id']]);
            $members = $membersQuery->fetchAll();
        }
        if ($isAdmin) {
            $groups = $pdo->query("SELECT pg.id, pg.project_id, pg.name, pg.role_name, pg.permissions, COALESCE(p.name, 'Глобальна група') AS project_name, COUNT(DISTINCT pgm.user_id) AS member_count FROM project_groups pg LEFT JOIN projects p ON p.id = pg.project_id LEFT JOIN project_group_members pgm ON pgm.group_id = pg.id GROUP BY pg.id, pg.project_id, pg.name, pg.role_name, pg.permissions, p.name ORDER BY project_name, pg.name")->fetchAll();
        } else {
            $groupsQuery = $pdo->prepare("SELECT pg.id, pg.project_id, pg.name, pg.role_name, pg.permissions, COALESCE(p.name, 'Глобальна група') AS project_name, COUNT(DISTINCT pgm.user_id) AS member_count FROM project_groups pg LEFT JOIN projects p ON p.id = pg.project_id LEFT JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ? LEFT JOIN project_group_members pgm ON pgm.group_id = pg.id WHERE (pg.project_id IS NULL AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(pg.permissions) THEN pg.permissions ELSE '{}' END, '$.owner_id')) IN (?, ?)) OR p.owner_id = ? GROUP BY pg.id, pg.project_id, pg.name, pg.role_name, pg.permissions, p.name ORDER BY project_name, pg.name");
            $groupsQuery->execute([$_SESSION['user_id'], $planContext['owner_id'], $_SESSION['user_id'], $planContext['owner_id']]);
            $groups = $groupsQuery->fetchAll();
        }
        foreach ($groups as &$group) $group = exposeGroupPolicy($group);
        unset($group);
        $groups = array_values(array_filter($groups, static function ($group) use ($isAdmin, $isProjectLead, $planContext): bool { return $group['project_id'] === null && ($isAdmin || $group['group_owner_id'] === (string)$_SESSION['user_id'] || ($isProjectLead && $group['group_owner_id'] === (string)$planContext['owner_id'])); }));
        foreach ($users as &$teamUser) {
            $teamUser['group_id'] = '';
            foreach ($groups as $visibleGroup) {
                $membership = $pdo->prepare('SELECT 1 FROM project_group_members WHERE group_id = ? AND user_id = ?');
                $membership->execute([$visibleGroup['id'], $teamUser['id']]);
                if ($membership->fetchColumn()) { $teamUser['group_id'] = $visibleGroup['id']; break; }
            }
        }
        unset($teamUser);
        if ($isAdmin) {
            $clients = $pdo->query('SELECT id, name, created_by FROM clients ORDER BY name')->fetchAll();
        } else {
            $clientsQuery = $pdo->prepare('SELECT DISTINCT c.id, c.name, c.created_by FROM clients c LEFT JOIN client_members cm ON cm.client_id = c.id AND cm.user_id = ? WHERE c.created_by IN (' . implode(',', array_fill(0, count($planContext['member_ids']), '?')) . ') OR cm.user_id IS NOT NULL ORDER BY c.name');
            $clientsQuery->execute(array_merge([$_SESSION['user_id']], $planContext['member_ids']));
            $clients = $clientsQuery->fetchAll();
        }
        $clientAssignments = [];
        if ($clients) {
            $clientIds = array_column($clients, 'id');
            $marks = implode(',', array_fill(0, count($clientIds), '?'));
            $assignmentsQuery = $pdo->prepare("SELECT client_id, user_id FROM client_members WHERE client_id IN ({$marks})");
            $assignmentsQuery->execute($clientIds);
            foreach ($assignmentsQuery->fetchAll() as $assignment) $clientAssignments[(string)$assignment['user_id']][] = (string)$assignment['client_id'];
        }
        foreach ($users as &$teamUser) $teamUser['client_ids'] = $clientAssignments[(string)$teamUser['id']] ?? [];
        unset($teamUser);
        $assignableGroups = $groups;
        if ($isProjectLead) {
            $assignableGroupsQuery = $pdo->prepare("SELECT pg.id, pg.project_id, pg.name, pg.role_name, pg.permissions, 'Глобальна група' AS project_name, COUNT(DISTINCT pgm.user_id) AS member_count FROM project_groups pg LEFT JOIN project_group_members pgm ON pgm.group_id = pg.id WHERE pg.project_id IS NULL AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(pg.permissions) THEN pg.permissions ELSE '{}' END, '$.owner_id')) = ? GROUP BY pg.id, pg.project_id, pg.name, pg.role_name, pg.permissions");
            $assignableGroupsQuery->execute([$planContext['owner_id']]);
            $assignableGroups = $assignableGroupsQuery->fetchAll();
            foreach ($assignableGroups as &$assignableGroup) $assignableGroup = exposeGroupPolicy($assignableGroup);
            unset($assignableGroup);
        }
        jsonResponse(true, 'Дані команди завантажено.', ['users' => $users, 'pending_invites' => $pendingInvites, 'projects' => $projects, 'members' => $members, 'groups' => $groups, 'assignable_groups' => $assignableGroups, 'clients' => $clients, 'permissions' => ['admin' => $isAdmin, 'project_lead' => $isProjectLead]]);
    }

    if ($method !== 'POST') jsonResponse(false, 'Метод не підтримується.', [], 405);
    if ($action === 'create_project') {
        if ($isProjectLead && ($data['invite_role'] ?? 'member') === 'manager') jsonResponse(false, 'Керівник проєкту може запрошувати учасників, але не призначати роль менеджера.', [], 403);
        requirePlanCapacity($planContext, 'projects');
        if (!empty($data['invite_emails']) && is_array($data['invite_emails'])) {
            $emailsToInvite = array_unique(array_filter(array_map(static fn($value): string => strtolower(trim((string)$value)), $data['invite_emails']), static fn(string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL)));
            if ($emailsToInvite) requirePlanCapacity($planContext, 'participants', count($emailsToInvite));
            if (!$isAdmin && ($data['invite_role'] ?? 'member') === 'manager') {
                acquirePlanCapacityLock($planContext, 'project_leads');
                $leadLimit = (int)$planContext['plan']['project_leads'];
                $currentLeads = planProjectLeadCount($pdo, $planContext);
                if ($currentLeads + count($emailsToInvite) > $leadLimit) jsonResponse(false, 'Ліміт керівників проєктів вашого плану вичерпано.', ['code' => 'plan_limit_reached', 'metric' => 'project_leads', 'limit' => $leadLimit, 'current' => $currentLeads, 'plan' => $planContext['key']], 403);
            }
        }
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') jsonResponse(false, 'Введіть назву проєкту.', [], 422);
        $description = trim((string)($data['description'] ?? ''));
        $status = in_array(($data['status'] ?? 'active'), ['active', 'draft', 'paused', 'completed'], true) ? (string)$data['status'] : 'active';
        $visibility = in_array(($data['visibility'] ?? 'team'), ['private', 'team'], true) ? (string)$data['visibility'] : 'team';
        if ($isProjectLead && !empty($data['groups']) && is_array($data['groups'])) jsonResponse(false, 'Керівник проєкту не може створювати або призначати нові claims.', [], 403);
        $id = bin2hex(random_bytes(16));
        $pdo->beginTransaction();
        $insert = $pdo->prepare('INSERT INTO projects (id, name, description, owner_id, status, visibility) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute([$id, $name, $description, $planContext['owner_id'], $status, $visibility]);
        $member = $pdo->prepare("INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, 'admin')");
        $member->execute([$id, $_SESSION['user_id']]);
        if ($canManage && !empty($data['members']) && is_array($data['members'])) {
            $addMember = $pdo->prepare('INSERT IGNORE INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)');
            foreach ($data['members'] as $selected) {
                $memberId = trim((string)($selected['user_id'] ?? ''));
                $memberRole = trim((string)($selected['role'] ?? 'member'));
                if ($isProjectLead) {
                    $memberCheck = $pdo->prepare("SELECT 1 FROM users u WHERE u.id = ? AND (EXISTS (SELECT 1 FROM user_invitations i WHERE i.email = u.email AND i.invited_by = ? AND i.status = 'accepted') OR EXISTS (SELECT 1 FROM project_invitations i WHERE i.email = u.email AND i.invited_by = ? AND i.status = 'accepted')) LIMIT 1");
                    $memberCheck->execute([$memberId, $userId, $userId]);
                    if (!$memberCheck->fetchColumn() || in_array($memberRole, ['admin', 'manager'], true)) continue;
                }
                if ($memberId !== $_SESSION['user_id'] && in_array($memberRole, ['admin', 'manager', 'member', 'viewer'], true)) {
                    $addMember->execute([$id, $memberId, $memberRole]);
                }
            }
        }
        if ($canManage && !empty($data['groups']) && is_array($data['groups'])) {
            $groupInsert = $pdo->prepare('INSERT INTO project_groups (id, project_id, name) VALUES (?, ?, ?)');
            $groupMemberInsert = $pdo->prepare('INSERT IGNORE INTO project_group_members (group_id, user_id) VALUES (?, ?)');
            foreach ($data['groups'] as $group) {
                $groupName = trim((string)($group['name'] ?? ''));
                if ($groupName === '') continue;
                $groupId = bin2hex(random_bytes(16));
                $groupInsert->execute([$groupId, $id, mb_substr($groupName, 0, 120)]);
                foreach ((array)($group['user_ids'] ?? []) as $groupUserId) {
                    $groupUserId = trim((string)$groupUserId);
                    if ($groupUserId !== '') $groupMemberInsert->execute([$groupId, $groupUserId]);
                }
            }
        }
        $invited = 0;
        $sent = 0;
        $failed = 0;
        if ($canManage && !empty($data['invite_emails']) && is_array($data['invite_emails'])) {
            $inviteRole = trim((string)($data['invite_role'] ?? 'member'));
            if (!in_array($inviteRole, ['manager', 'member', 'viewer'], true)) $inviteRole = 'member';
            if ($isProjectLead && $inviteRole === 'manager') jsonResponse(false, 'Керівник проєкту може запрошувати учасників, але не призначати роль менеджера.', [], 403);
            $revoke = $pdo->prepare("UPDATE project_invitations SET status = 'revoked' WHERE project_id = ? AND email = ? AND status = 'pending'");
            $inviteInsert = $pdo->prepare('INSERT INTO project_invitations (id, project_id, invited_by, email, role, token_hash, expires_at) VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))');
            foreach ($data['invite_emails'] as $rawEmail) {
                $inviteEmail = strtolower(trim((string)$rawEmail));
                if (!filter_var($inviteEmail, FILTER_VALIDATE_EMAIL)) continue;
                $revoke->execute([$id, $inviteEmail]);
                $token = bin2hex(random_bytes(32));
                $inviteInsert->execute([bin2hex(random_bytes(16)), $id, $_SESSION['user_id'], $inviteEmail, $inviteRole, hash('sha256', $token)]);
                $subject = 'Вас запросили до проєкту NexaFlow';
                $message = "Вас запросили до проєкту «{$name}» у NexaFlow.\n\nПрийняти запрошення: " . projectInviteUrl($token) . "\n\nПосилання дійсне 7 днів.";
                $safeProjectName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
                $safeInviteUrl = htmlspecialchars(projectInviteUrl($token), ENT_QUOTES, 'UTF-8');
                $htmlMessage = "<div style=\"font-family:Arial,sans-serif;max-width:600px;color:#17201e\"><h2>Вас запросили до проєкту</h2><p>Вас запросили до проєкту <strong>«{$safeProjectName}»</strong> у NexaFlow.</p><p><a href=\"{$safeInviteUrl}\" style=\"display:inline-block;padding:12px 20px;background:#1f8a68;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold\">Приєднатись до проєкту</a></p><p style=\"color:#7b8983;font-size:12px\">Посилання дійсне 7 днів.</p></div>";
                $existingInviteUser = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                $existingInviteUser->execute([$inviteEmail]);
                $existingInviteUser = $existingInviteUser->fetch();
                if ($existingInviteUser && $existingInviteUser['id'] !== $_SESSION['user_id']) {
                    $pdo->prepare("INSERT INTO notifications (id, user_id, sender_id, type, title, message, entity_type, entity_id) VALUES (?, ?, ?, 'project_invite', ?, ?, 'project', ?)")->execute([bin2hex(random_bytes(16)), $existingInviteUser['id'], $_SESSION['user_id'], 'Запрошення до нового проєкту', "Вас запросили до проєкту «{$name}». Відкрийте лист і натисніть «Приєднатись до проєкту».", $id]);
                }
                // An invitation is transactional; send it even when the recipient
                // has disabled optional email notifications.
                $emailSent = sendProjectEmail($inviteEmail, $subject, $message, $htmlMessage);
                if ($emailSent) $sent++;
                else $failed++;
                $invited++;
            }
        }
        $pdo->commit();
        releasePlanCapacityLocks((string)$planContext['owner_id']);
        $notificationUsersQuery = $pdo->prepare('SELECT user_id AS id FROM project_members WHERE project_id = ? UNION SELECT pgm.user_id AS id FROM project_group_members pgm JOIN project_groups pg ON pg.id = pgm.group_id WHERE pg.project_id = ?');
        $notificationUsersQuery->execute([$id, $id]);
        $notificationUsers = $notificationUsersQuery->fetchAll();
        $notificationInsert = $pdo->prepare("INSERT INTO notifications (id, user_id, sender_id, type, title, message, entity_type, entity_id) VALUES (?, ?, ?, 'project_created', ?, ?, 'project', ?)");
        foreach ($notificationUsers as $recipient) {
            $notificationInsert->execute([bin2hex(random_bytes(16)), $recipient['id'], $_SESSION['user_id'], 'Створено новий проєкт', "Проєкт «{$name}» додано до робочого простору.", $id]);
        }
        $inviteMessage = $invited ? " Запрошень створено: {$invited}; успішно передано поштовому серверу: {$sent}." : '';
        if ($failed) $inviteMessage .= " Не вдалося передати: {$failed}. Перевірте Mailpit або SMTP-конфігурацію.";
        $projectPlanLimit = $planContext['plan']['projects'] ?? null;
        if ($projectPlanLimit !== null && (int)$planContext['counts']['projects'] + 1 >= (int)$projectPlanLimit) notifyPlanCapacityReached($planContext, 'projects', (int)$projectPlanLimit, (int)$projectPlanLimit);
        $participantPlanLimit = $planContext['plan']['participants'] ?? null;
        if ($participantPlanLimit !== null && !empty($emailsToInvite) && (int)$planContext['counts']['participants'] + count($emailsToInvite) >= (int)$participantPlanLimit) notifyPlanCapacityReached($planContext, 'participants', (int)$participantPlanLimit, (int)$participantPlanLimit);
        if (!$isAdmin && !empty($emailsToInvite) && ($data['invite_role'] ?? 'member') === 'manager' && $leadLimit > 0 && $currentLeads + count($emailsToInvite) >= $leadLimit) notifyPlanCapacityReached($planContext, 'project_leads', $leadLimit, $leadLimit);
        jsonResponse(true, 'Проєкт створено.' . $inviteMessage, ['project' => ['id' => $id, 'name' => $name], 'invited' => $invited, 'sent' => $sent, 'failed' => $failed]);
    }

    if ($action === 'assign_role') {
        $projectId = trim((string)($data['project_id'] ?? ''));
        $targetUserId = trim((string)($data['user_id'] ?? ''));
        $role = trim((string)($data['role'] ?? 'member'));
        if ($projectId === '' || $targetUserId === '' || !in_array($role, ['admin', 'manager', 'member', 'viewer'], true)) {
            jsonResponse(false, 'Оберіть проєкт, користувача та коректну роль.', [], 422);
        }
        if (!$isAdmin && $role === 'manager' && $planContext['plan']['project_leads'] !== null) {
            acquirePlanCapacityLock($planContext, 'project_leads');
            $oldProjectRole = $pdo->prepare('SELECT role FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1');
            $oldProjectRole->execute([$projectId, $targetUserId]);
            $wasProjectLead = $oldProjectRole->fetchColumn() === 'manager';
            if (!$wasProjectLead) {
                $currentLeads = planProjectLeadCount($pdo, $planContext);
                if ($currentLeads >= (int)$planContext['plan']['project_leads']) { notifyPlanCapacityReached($planContext, 'project_leads', $currentLeads, (int)$planContext['plan']['project_leads']); jsonResponse(false, 'Ліміт керівників проєктів вашого плану вичерпано.', ['code' => 'plan_limit_reached', 'metric' => 'project_leads', 'limit' => (int)$planContext['plan']['project_leads'], 'current' => $currentLeads, 'plan' => $planContext['key']], 403); }
            }
        }

        // A manager may only change roles in projects they own. Administrators
        // retain global management access, but the target project and user must
        // still exist so this endpoint cannot create orphan memberships.
        $projectCheck = $pdo->prepare('SELECT owner_id FROM projects WHERE id = ? LIMIT 1');
        $projectCheck->execute([$projectId]);
        $projectOwnerId = $projectCheck->fetchColumn();
        $projectCheck->closeCursor();
        if (!$projectOwnerId) jsonResponse(false, 'Проєкт не знайдено.', [], 404);
        if (!$isAdmin && (string)$projectOwnerId !== (string)$_SESSION['user_id']) {
            jsonResponse(false, 'Керувати ролями можна лише у власному проєкті.', [], 403);
        }

        $targetCheck = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
        $targetCheck->execute([$targetUserId]);
        if (!$targetCheck->fetchColumn()) jsonResponse(false, 'Користувача не знайдено.', [], 404);
        $previousRoleQuery = $pdo->prepare('SELECT role FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1');
        $previousRoleQuery->execute([$projectId, $targetUserId]);
        $previousRole = $previousRoleQuery->fetchColumn();
        $upsert = $pdo->prepare('INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE role = VALUES(role), updated_at = CURRENT_TIMESTAMP');
        $upsert->execute([$projectId, $targetUserId, $role]);
        releasePlanCapacityLocks((string)$planContext['owner_id']);
        if ($targetUserId !== (string)$_SESSION['user_id'] && ($previousRole === false || $previousRole !== $role)) {
            $projectNameQuery = $pdo->prepare('SELECT name FROM projects WHERE id = ?');
            $projectNameQuery->execute([$projectId]);
            $projectName = (string)$projectNameQuery->fetchColumn();
            $pdo->prepare("INSERT INTO notifications (id, user_id, sender_id, type, title, message, entity_type, entity_id) VALUES (?, ?, ?, 'project_assigned', ?, ?, 'project', ?)")
                ->execute([bin2hex(random_bytes(16)), $targetUserId, $_SESSION['user_id'], 'Вас додано до проєкту', "Вас додано до проєкту «{$projectName}».", $projectId]);
        }
        jsonResponse(true, 'Роль користувача оновлено.');
    }

    if ($action === 'assign_clients') {
        $targetUserId = trim((string)($data['user_id'] ?? ''));
        $requestedClientIds = array_values(array_unique(array_filter(array_map(static fn($item): string => trim((string)$item), (array)($data['client_ids'] ?? [])))));
        if ($targetUserId === '') jsonResponse(false, 'Оберіть користувача.', [], 422);
        if (!$isAdmin) {
            $memberCheck = $pdo->prepare("SELECT 1 FROM users u WHERE u.id = ? AND (EXISTS (SELECT 1 FROM project_invitations pi WHERE pi.email = u.email AND pi.invited_by = ? AND pi.status = 'accepted') OR EXISTS (SELECT 1 FROM user_invitations ui WHERE ui.email = u.email AND ui.invited_by = ? AND ui.status = 'accepted')) LIMIT 1");
            $memberCheck->execute([$targetUserId, $_SESSION['user_id'], $_SESSION['user_id']]);
            if (!$memberCheck->fetchColumn()) jsonResponse(false, 'Цей користувач не входить до вашої команди.', [], 403);
        }
        $ownedClientsQuery = $isAdmin
            ? $pdo->query('SELECT id FROM clients')
            : $pdo->prepare('SELECT id FROM clients WHERE created_by IN (' . implode(',', array_fill(0, count($planContext['member_ids']), '?')) . ')');
        if (!$isAdmin) $ownedClientsQuery->execute($planContext['member_ids']);
        $manageableClientIds = array_map('strval', array_column($ownedClientsQuery->fetchAll(), 'id'));
        $selectedClientIds = array_values(array_intersect($requestedClientIds, $manageableClientIds));
        $previousAssignments = [];
        if ($manageableClientIds) {
            $marks = implode(',', array_fill(0, count($manageableClientIds), '?'));
            $previousQuery = $pdo->prepare("SELECT client_id FROM client_members WHERE user_id = ? AND client_id IN ({$marks})");
            $previousQuery->execute(array_merge([$targetUserId], $manageableClientIds));
            $previousAssignments = array_map('strval', $previousQuery->fetchAll(PDO::FETCH_COLUMN));
            $delete = $pdo->prepare("DELETE FROM client_members WHERE user_id = ? AND client_id IN ({$marks})");
            $delete->execute(array_merge([$targetUserId], $manageableClientIds));
        }
        $insert = $pdo->prepare('INSERT IGNORE INTO client_members (client_id, user_id, assigned_by) VALUES (?, ?, ?)');
        foreach ($selectedClientIds as $clientId) $insert->execute([$clientId, $targetUserId, $_SESSION['user_id']]);
        if ($targetUserId !== (string)$_SESSION['user_id']) {
            $clientNameQuery = $pdo->prepare('SELECT name FROM clients WHERE id = ? LIMIT 1');
            $notifyClient = $pdo->prepare("INSERT INTO notifications (id, user_id, sender_id, type, title, message, entity_type, entity_id) VALUES (?, ?, ?, 'client_assigned', ?, ?, 'client', ?)");
            foreach (array_diff($selectedClientIds, $previousAssignments) as $clientId) {
                $clientNameQuery->execute([$clientId]);
                $clientName = (string)$clientNameQuery->fetchColumn();
                $notifyClient->execute([bin2hex(random_bytes(16)), $targetUserId, $_SESSION['user_id'], 'Вам надали доступ до клієнта', "Вам надали доступ до клієнта «{$clientName}».", $clientId]);
            }
        }
        jsonResponse(true, 'Доступ до клієнтів оновлено.');
    }

    if ($action === 'set_user_role') {
        if (!$isAdmin) jsonResponse(false, 'Тільки адміністратор може змінювати глобальні ролі.', [], 403);
        $userId = trim((string)($data['user_id'] ?? ''));
        $role = trim((string)($data['role'] ?? 'user'));
        if ($userId === '' || !in_array($role, ['admin', 'manager', 'user'], true)) jsonResponse(false, 'Оберіть коректну глобальну роль.', [], 422);
        if ($role === 'manager' && $planContext['plan']['managers'] !== null) {
            $existingRole = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
            $existingRole->execute([$userId]);
            if ($existingRole->fetchColumn() !== 'manager') {
                $managerCount = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE u.role = 'manager' AND (EXISTS (SELECT 1 FROM user_invitations i WHERE i.invited_by = ? AND i.email = u.email AND i.status = 'accepted') OR EXISTS (SELECT 1 FROM project_invitations i WHERE i.invited_by = ? AND i.email = u.email AND i.status = 'accepted'))");
                $managerCount->execute([$planContext['owner_id'], $planContext['owner_id']]);
                $currentManagers = (int)$managerCount->fetchColumn() + 1;
                if ($currentManagers >= (int)$planContext['plan']['managers']) { notifyPlanCapacityReached($planContext, 'managers', $currentManagers, (int)$planContext['plan']['managers']); jsonResponse(false, 'Ліміт менеджерів вашого плану вичерпано.', ['code' => 'plan_limit_reached', 'metric' => 'managers', 'limit' => (int)$planContext['plan']['managers'], 'current' => $currentManagers, 'plan' => $planContext['key']], 403); }
            }
        }
        $update = $pdo->prepare('UPDATE users SET role = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $update->execute([$role, $userId]);
        jsonResponse(true, 'Глобальну роль оновлено.');
    }

    jsonResponse(false, 'Невідома дія.', [], 400);
} catch (Throwable $error) {
    error_log($error->getMessage());
    jsonResponse(false, 'Помилка під час роботи з командою.', [], 500);
}
