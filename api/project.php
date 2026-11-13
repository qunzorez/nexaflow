<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);
$pdo = db();
$userId = (string)$_SESSION['user_id'];
$projectId = trim((string)($_GET['id'] ?? ''));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$data = $method === 'POST' ? body() : [];
if ($method === 'POST') $projectId = trim((string)($data['id'] ?? $projectId));
$action = (string)($data['action'] ?? 'update');
$actorQuery = $pdo->prepare('SELECT role FROM users WHERE id = ?');
$actorQuery->execute([$userId]); $actorRole = (string)$actorQuery->fetchColumn(); $actorQuery->closeCursor();
$actorAdmin = in_array($actorRole, ['admin', 'owner'], true);
if ($method === 'POST' && in_array($action, ['update_global_group', 'delete_global_group'], true)) {
    $managedGroup = manageableGroup($pdo, trim((string)($data['group_id'] ?? '')), $userId, $actorAdmin);
}
if ($method === 'POST' && $action === 'assign_global_group') {
    $memberIdCheck = trim((string)($data['user_id'] ?? ''));
    if (!$actorAdmin) {
        $q = $pdo->prepare("SELECT u.id FROM users u WHERE u.id = ? AND u.role NOT IN ('admin', 'owner', 'manager') AND (EXISTS (SELECT 1 FROM user_invitations i WHERE i.email = u.email AND i.invited_by = ? AND i.status = 'accepted') OR EXISTS (SELECT 1 FROM project_invitations i WHERE i.email = u.email AND i.invited_by = ? AND i.status = 'accepted'))");
        $q->execute([$memberIdCheck, $userId, $userId]); $invited = $q->fetchColumn(); $q->closeCursor();
        if (!$invited) jsonResponse(false, 'Можна призначати групи лише запрошеним учасникам своєї команди.', [], 403);
    }
    if (!empty($data['group_id'])) manageableGroup($pdo, (string)$data['group_id'], $userId, $actorAdmin);
}

if ($method === 'POST' && $action === 'create_global_group') {
    $creator = $pdo->prepare("SELECT role FROM users WHERE id = ? AND role IN ('admin', 'owner', 'manager') LIMIT 1");
    $creator->execute([$userId]);
    if (!$creator->fetch()) jsonResponse(false, 'Недостатньо прав для створення групи.', [], 403);
    $groupName = trim((string)($data['name'] ?? ''));
    if ($groupName === '') jsonResponse(false, 'Введіть назву групи.', [], 422);
    $permissions = encodeGroupPolicy($data, $userId);
    $groupId = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO project_groups (id, project_id, name, role_name, permissions) VALUES (?, NULL, ?, ?, ?)')->execute([$groupId, $groupName, '', $permissions]);
    jsonResponse(true, 'Глобальну групу створено.', ['group_id' => $groupId]);
}
if ($method === 'POST' && in_array($action, ['update_global_group', 'delete_global_group'], true)) {
    $manager = $pdo->prepare("SELECT role FROM users WHERE id = ? AND role IN ('admin', 'owner', 'manager') LIMIT 1");
    $manager->execute([$userId]);
    if (!$manager->fetch()) jsonResponse(false, 'Недостатньо прав для керування групою.', [], 403);
    $groupId = trim((string)($data['group_id'] ?? ''));
    if ($groupId === '') jsonResponse(false, 'Групу не вказано.', [], 422);
    if ($action === 'delete_global_group') {
        $pdo->prepare('DELETE FROM project_groups WHERE id = ? AND project_id IS NULL')->execute([$groupId]);
        jsonResponse(true, 'Групу видалено.');
    }
    $groupName = trim((string)($data['name'] ?? ''));
    if ($groupName === '') jsonResponse(false, 'Введіть назву групи.', [], 422);
    $permissions = encodeGroupPolicy($data, (string)(groupPolicy($managedGroup['permissions'] ?? null)['owner_id'] ?? $userId));
    $pdo->prepare('UPDATE project_groups SET name = ?, role_name = ?, permissions = ? WHERE id = ? AND project_id IS NULL')->execute([$groupName, '', $permissions, $groupId]);
    jsonResponse(true, 'Групу оновлено.');
}
if ($method === 'POST' && $action === 'assign_global_group') {
    $manager = $pdo->prepare("SELECT role FROM users WHERE id = ? AND role IN ('admin', 'owner', 'manager') LIMIT 1");
    $manager->execute([$userId]);
    if (!$manager->fetch()) jsonResponse(false, 'Недостатньо прав для призначення груп.', [], 403);
    $memberId = trim((string)($data['user_id'] ?? ''));
    $groupId = trim((string)($data['group_id'] ?? ''));
    if ($memberId === '') jsonResponse(false, 'Користувача не вказано.', [], 422);
    if ($groupId !== '') {
        $groupCheck = $pdo->prepare('SELECT id FROM project_groups WHERE id = ? AND project_id IS NULL LIMIT 1');
        $groupCheck->execute([$groupId]);
        if (!$groupCheck->fetch()) jsonResponse(false, 'Глобальну групу не знайдено.', [], 422);
    }
    $ownedMemberships = $pdo->prepare('SELECT pg.id, pg.permissions FROM project_groups pg JOIN project_group_members gm ON gm.group_id = pg.id WHERE gm.user_id = ? AND pg.project_id IS NULL');
    $ownedMemberships->execute([$memberId]);
    foreach ($ownedMemberships->fetchAll() as $membership) {
        if ($actorAdmin || (groupPolicy($membership['permissions'])['owner_id'] ?? '') === $userId) $pdo->prepare('DELETE FROM project_group_members WHERE group_id = ? AND user_id = ?')->execute([$membership['id'], $memberId]);
    }
    if ($groupId !== '') $pdo->prepare('INSERT INTO project_group_members (group_id, user_id) VALUES (?, ?)')->execute([$groupId, $memberId]);
    jsonResponse(true, $groupId === '' ? 'Групу користувача скасовано.' : 'Групу користувача оновлено.');
}
if ($projectId === '') jsonResponse(false, 'Проєкт не знайдено.', [], 422);

$access = $pdo->prepare("SELECT p.id, p.name, p.description, p.owner_id, p.status, p.visibility, p.created_at, p.updated_at, u.name AS owner_name, cu.role AS user_role, pm.role AS project_role FROM projects p JOIN users u ON u.id = p.owner_id JOIN users cu ON cu.id = ? LEFT JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ? WHERE p.id = ? AND (cu.role IN ('admin', 'owner') OR p.owner_id = ? OR pm.user_id = ?) LIMIT 1");
$access->execute([$userId, $userId, $projectId, $userId, $userId]);
$project = $access->fetch();
if (!$project) jsonResponse(false, 'У вас немає доступу до цього проєкту.', [], 403);
$canEdit = in_array($project['user_role'], ['admin', 'owner'], true) || $project['owner_id'] === $userId || in_array($project['project_role'], ['admin', 'manager'], true);
$isAdmin = in_array($project['user_role'], ['admin', 'owner'], true);
$claims = resourceClaims($pdo, $userId, 'project', $projectId);
requireClaim($claims, 'read');
$isProjectOwner = $project['owner_id'] === $userId || $isAdmin;
$canEdit = $isProjectOwner || ($claims['assigned'] && hasClaim($claims, 'edit'));
$canUpdate = $isProjectOwner || ($claims['assigned'] && hasClaim($claims, 'update'));
$canDelete = $isProjectOwner || ($claims['assigned'] && hasClaim($claims, 'delete'));
$canManageTeam = $isProjectOwner;
if ($method === 'POST' && in_array($action, ['add_member', 'remove_member', 'create_group', 'delete_group', 'update_group', 'assign_global_group'], true)) {
    if (!$isProjectOwner) jsonResponse(false, 'Змінювати команду і групи може лише власник проєкту.', [], 403);
}


if ($method === 'GET') {
    $membersQuery = $pdo->prepare("SELECT pm.user_id, pm.role, u.name, u.email, GROUP_CONCAT(DISTINCT pgm.group_id) AS group_ids, GROUP_CONCAT(DISTINCT pg.name SEPARATOR ', ') AS group_names FROM project_members pm JOIN users u ON u.id = pm.user_id LEFT JOIN project_group_members pgm ON pgm.user_id = pm.user_id LEFT JOIN project_groups pg ON pg.id = pgm.group_id AND (pg.project_id = pm.project_id OR pg.project_id IS NULL) WHERE pm.project_id = ? AND pm.user_id <> ? GROUP BY pm.user_id, pm.role, u.name, u.email ORDER BY u.name");
    $membersQuery->execute([$projectId, $project['owner_id']]);
    $groupsQuery = $pdo->prepare('SELECT id, name, role_name, permissions FROM project_groups WHERE project_id = ? OR project_id IS NULL ORDER BY name');
    $groupsQuery->execute([$projectId]);
    $groups = $groupsQuery->fetchAll();
    foreach ($groups as &$group) $group = exposeGroupPolicy($group);
    unset($group);
    if (in_array($project['user_role'], ['admin', 'owner'], true)) {
        $usersQuery = $pdo->query('SELECT id, name, email, role FROM users ORDER BY name');
    } else {
        $usersQuery = $pdo->prepare("SELECT DISTINCT u.id, u.name, u.email, u.role FROM users u WHERE u.id <> ? AND (EXISTS (SELECT 1 FROM project_invitations pi WHERE pi.email = u.email AND pi.invited_by = ? AND pi.status = 'accepted') OR EXISTS (SELECT 1 FROM user_invitations ui WHERE ui.email = u.email AND ui.invited_by = ? AND ui.status = 'accepted')) ORDER BY u.name");
        $usersQuery->execute([$project['owner_id'], $userId, $userId]);
    }
    $members = $membersQuery->fetchAll();
    foreach ($members as &$memberItem) $memberItem['group_ids'] = $memberItem['group_ids'] ? explode(',', $memberItem['group_ids']) : [];
    unset($memberItem);
    $taskWhere = '(t.project_id = ? OR EXISTS (SELECT 1 FROM task_projects tp WHERE tp.task_id = t.id AND tp.project_id = ?))';
    $taskParams = [$projectId, $projectId];
    $projectTasksQuery = $pdo->prepare("SELECT DISTINCT t.id, t.title, t.status, t.priority, t.due_date, t.created_at FROM tasks t WHERE {$taskWhere} ORDER BY t.created_at DESC, t.due_date IS NULL, t.due_date");
    $projectTasksQuery->execute($taskParams);
    $projectTasks = $projectTasksQuery->fetchAll();
    jsonResponse(true, 'Проєкт завантажено.', ['project' => $project, 'members' => $members, 'groups' => $groups, 'project_tasks' => $projectTasks, 'available_users' => $usersQuery->fetchAll(), 'permissions' => ['edit' => $canEdit, 'update' => $canUpdate, 'delete' => $canDelete, 'manage_team' => $canManageTeam, 'admin' => $isAdmin]]);
}
if ($method !== 'POST') jsonResponse(false, 'Метод не підтримується.', [], 405);
if ($action === 'update' && !$canUpdate) jsonResponse(false, 'Недостатньо прав для оновлення цього проєкту.', [], 403);
if ($action === 'delete' && !$canDelete) jsonResponse(false, 'Недостатньо прав для видалення цього проєкту.', [], 403);
if ($method === 'POST' && $action === 'assign_global_group') {
    $manager = $pdo->prepare("SELECT role FROM users WHERE id = ? AND role IN ('admin', 'owner', 'manager') LIMIT 1");
    $manager->execute([$userId]);
    if (!$manager->fetch()) jsonResponse(false, 'Недостатньо прав для призначення груп.', [], 403);
    $memberId = trim((string)($data['user_id'] ?? ''));
    $groupId = trim((string)($data['group_id'] ?? ''));
    if ($memberId === '') jsonResponse(false, 'Користувача не вказано.', [], 422);
    if ($groupId !== '') {
        $groupCheck = $pdo->prepare('SELECT id FROM project_groups WHERE id = ? AND project_id IS NULL LIMIT 1');
        $groupCheck->execute([$groupId]);
        if (!$groupCheck->fetch()) jsonResponse(false, 'Глобальну групу не знайдено.', [], 422);
    }
    $ownedMemberships = $pdo->prepare('SELECT pg.id, pg.permissions FROM project_groups pg JOIN project_group_members gm ON gm.group_id = pg.id WHERE gm.user_id = ? AND pg.project_id IS NULL');
    $ownedMemberships->execute([$memberId]);
    foreach ($ownedMemberships->fetchAll() as $membership) {
        if ($actorAdmin || (groupPolicy($membership['permissions'])['owner_id'] ?? '') === $userId) {
            $pdo->prepare('DELETE FROM project_group_members WHERE group_id = ? AND user_id = ?')->execute([$membership['id'], $memberId]);
        }
    }
    if ($groupId !== '') $pdo->prepare('INSERT INTO project_group_members (group_id, user_id) VALUES (?, ?)')->execute([$groupId, $memberId]);
    jsonResponse(true, $groupId === '' ? 'Групу користувача скасовано.' : 'Групу користувача оновлено.');
}

if ($action === 'delete') {
    $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$projectId]);
    jsonResponse(true, 'Проєкт видалено.');
}
if ($action === 'add_member') {
    $memberId = trim((string)($data['user_id'] ?? ''));
    $role = trim((string)($data['role'] ?? 'member'));
    if ($memberId === '' || !in_array($role, ['admin', 'manager', 'member', 'viewer'], true)) jsonResponse(false, 'Вкажіть користувача і коректну роль.', [], 422);
    if ($role === 'admin' && !$isAdmin) jsonResponse(false, 'Роль адміністратора може надавати лише адміністратор.', [], 403);
    $groupId = trim((string)($data['group_id'] ?? ''));
    if ($groupId !== '') {
        $groupCheck = $pdo->prepare('SELECT id FROM project_groups WHERE id = ? AND (project_id = ? OR project_id IS NULL) LIMIT 1');
        $groupCheck->execute([$groupId, $projectId]);
        if (!$groupCheck->fetch()) jsonResponse(false, 'Обрана група не належить цьому проєкту.', [], 422);
        $role = 'member';
    }
    $pdo->prepare('INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE role = VALUES(role), updated_at = CURRENT_TIMESTAMP')->execute([$projectId, $memberId, $role]);
    $pdo->prepare('DELETE pgm FROM project_group_members pgm JOIN project_groups pg ON pg.id = pgm.group_id WHERE pg.project_id = ? AND pgm.user_id = ?')->execute([$projectId, $memberId]);
    if ($groupId !== '') $pdo->prepare('INSERT INTO project_group_members (group_id, user_id) VALUES (?, ?)')->execute([$groupId, $memberId]);
    jsonResponse(true, 'Користувача додано до проєкту.');
}
if ($action === 'remove_member') {
    $memberId = trim((string)($data['user_id'] ?? ''));
    if ($memberId === $project['owner_id']) jsonResponse(false, 'Власника проєкту не можна видалити з команди.', [], 422);
    $pdo->prepare('DELETE FROM project_members WHERE project_id = ? AND user_id = ?')->execute([$projectId, $memberId]);
    $pdo->prepare('DELETE pgm FROM project_group_members pgm JOIN project_groups pg ON pg.id = pgm.group_id WHERE pg.project_id = ? AND pgm.user_id = ?')->execute([$projectId, $memberId]);
    jsonResponse(true, 'Користувача видалено з проєкту.');
}
if ($action === 'create_group') {
    $groupName = trim((string)($data['name'] ?? ''));
    if ($groupName === '') jsonResponse(false, 'Введіть назву групи.', [], 422);
    $permissions = encodeGroupPolicy($data, $userId);
    $groupId = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO project_groups (id, project_id, name, role_name, permissions) VALUES (?, NULL, ?, ?, ?)')->execute([$groupId, $groupName, '', $permissions]);
    $insert = $pdo->prepare('INSERT IGNORE INTO project_group_members (group_id, user_id) VALUES (?, ?)');
    foreach ([] as $memberId) $insert->execute([$groupId, trim((string)$memberId)]);
    jsonResponse(true, 'Групу створено.');
}
if ($action === 'delete_group') {
    $pdo->prepare('DELETE FROM project_groups WHERE id = ? AND (project_id = ? OR project_id IS NULL)')->execute([trim((string)($data['group_id'] ?? '')), $projectId]);
    jsonResponse(true, 'Групу видалено.');
}
if ($action === 'update_group') {
    $groupId = trim((string)($data['group_id'] ?? ''));
    $groupName = trim((string)($data['name'] ?? ''));
    if ($groupId === '' || $groupName === '') jsonResponse(false, 'Введіть назву групи.', [], 422);
    $permissions = encodeGroupPolicy($data, $userId);
    $updateGroup = $pdo->prepare('UPDATE project_groups SET name = ?, role_name = ?, permissions = ? WHERE id = ? AND (project_id = ? OR project_id IS NULL)');
    $updateGroup->execute([$groupName, '', $permissions, $groupId, $projectId]);
    jsonResponse(true, 'Групу оновлено.');
}

$name = trim((string)($data['name'] ?? ''));
$description = trim((string)($data['description'] ?? ''));
$status = trim((string)($data['status'] ?? 'active'));
$visibility = trim((string)($data['visibility'] ?? 'private'));
if ($name === '') jsonResponse(false, 'Введіть назву проєкту.', [], 422);
if (!in_array($status, ['active', 'paused', 'completed', 'draft'], true)) $status = 'active';
if (!in_array($visibility, ['private', 'team'], true)) $visibility = 'private';
$update = $pdo->prepare('UPDATE projects SET name = ?, description = ?, status = ?, visibility = ? WHERE id = ?');
$update->execute([$name, $description, $status, $visibility, $projectId]);
jsonResponse(true, 'Зміни збережено.', ['project' => ['id' => $projectId, 'name' => $name, 'description' => $description, 'status' => $status, 'visibility' => $visibility]]);
