<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_once __DIR__ . '/plans.php';
require __DIR__ . '/mailer.php';

session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$data = $method === 'POST' ? body() : [];
$action = (string)($data['action'] ?? ($_GET['action'] ?? ''));

function invitationUrl(string $token): string
{
    return appUrl('/accept-invite?token=') . urlencode($token);
}

if ($action === 'details' && $method === 'GET') {
    $token = trim((string)($_GET['token'] ?? ''));
    $hash = hash('sha256', $token);
    $query = $pdo->prepare('SELECT pi.email, pi.role, pi.expires_at, p.name AS project_name, u.name AS inviter_name FROM project_invitations pi JOIN projects p ON p.id = pi.project_id JOIN users u ON u.id = pi.invited_by WHERE pi.token_hash = ? AND pi.status = \'pending\' AND pi.expires_at > NOW() LIMIT 1');
    $query->execute([$hash]);
    $invite = $query->fetch();
    if (!$invite) jsonResponse(false, 'Запрошення недійсне або вже завершилось.', [], 404);
    jsonResponse(true, 'Запрошення знайдено.', ['invitation' => $invite]);
}

if ($method !== 'POST' || !in_array($action, ['send', 'resend'], true)) jsonResponse(false, 'Невірний запит.', [], 405);

$actorRoleQuery = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
$actorRoleQuery->execute([$_SESSION['user_id']]);
$actorRole = (string)$actorRoleQuery->fetchColumn();
$actorRoleQuery->closeCursor();
$planContext = workspacePlan($pdo, (string)$_SESSION['user_id'], $actorRole);
$actorAdmin = in_array($actorRole, ['admin', 'owner'], true);
$invitationOwnerId = (string)$_SESSION['user_id'];
$requestedWorkspaceOwnerId = trim((string)($data['workspace_owner_id'] ?? ''));
if ($actorAdmin && $action === 'send' && $requestedWorkspaceOwnerId !== '') {
    $workspaceCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role IN ('manager','admin','owner') LIMIT 1");
    $workspaceCheck->execute([$requestedWorkspaceOwnerId]);
    if (!$workspaceCheck->fetchColumn()) jsonResponse(false, 'Оберіть наявний робочий простір.', [], 422);
    $invitationOwnerId = $requestedWorkspaceOwnerId;
}
$isProjectLead = ($planContext['workspace_role'] ?? '') === 'project_lead';
if (!$actorAdmin && !in_array($planContext['workspace_role'], ['admin', 'owner', 'manager', 'project_lead'], true)) jsonResponse(false, 'Керувати запрошеннями може лише менеджер або керівник проєкту активного простору.', [], 403);

if ($action === 'resend') {
    $inviteId = trim((string)($data['invite_id'] ?? ''));
    $inviteType = (string)($data['invite_type'] ?? '');
    if ($inviteId === '' || !in_array($inviteType, ['team', 'project'], true)) jsonResponse(false, 'Не вказано коректне запрошення.', [], 422);
    if ($inviteType === 'team') {
        $inviteQuery = $pdo->prepare("SELECT i.*, g.name AS group_name FROM user_invitations i LEFT JOIN project_groups g ON g.id = i.group_id WHERE i.id = ? AND i.status = 'pending' AND (? = 1 OR i.invited_by = ? OR (? = 1 AND i.invited_by IN (SELECT gm.user_id FROM project_groups pg JOIN project_group_members gm ON gm.group_id = pg.id WHERE pg.project_id IS NULL AND LOWER(pg.name) = LOWER('Керівник проєкту') AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(pg.permissions) THEN pg.permissions ELSE '{}' END, '$.owner_id')) = ?))) LIMIT 1");
        $inviteQuery->execute([$inviteId, $actorAdmin ? 1 : 0, $_SESSION['user_id'], $isProjectLead ? 0 : 1, $planContext['owner_id']]);
        $invite = $inviteQuery->fetch();
        if (!$invite) jsonResponse(false, 'Очікуване запрошення не знайдено.', [], 404);
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('UPDATE user_invitations SET token_hash = ?, expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ?')->execute([hash('sha256', $token), $inviteId]);
        $subject = 'Повторне запрошення до NexaFlow';
        $context = !empty($invite['group_name']) ? ' групи «' . $invite['group_name'] . '»' : ' команди';
        $plain = "Вас запросили до{$context} NexaFlow.\n\nПрийняти запрошення: " . invitationUrl($token) . "\n\nПосилання дійсне 7 днів.";
        $title = 'Запрошення до NexaFlow';
        $message = 'Вас запросили приєднатися до' . $context . '.';
        $entityType = 'invitation';
        $entityId = $inviteId;
        $notificationType = 'team_invite';
    } else {
        $inviteQuery = $pdo->prepare("SELECT i.*, p.name AS project_name, g.name AS group_name FROM project_invitations i JOIN projects p ON p.id = i.project_id LEFT JOIN project_groups g ON g.id = i.group_id WHERE i.id = ? AND i.status = 'pending' AND (? = 1 OR i.invited_by = ? OR (? = 1 AND p.owner_id = ? AND i.invited_by IN (SELECT gm.user_id FROM project_groups pg JOIN project_group_members gm ON gm.group_id = pg.id WHERE pg.project_id IS NULL AND LOWER(pg.name) = LOWER('Керівник проєкту') AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(pg.permissions) THEN pg.permissions ELSE '{}' END, '$.owner_id')) = ?))) LIMIT 1");
        $inviteQuery->execute([$inviteId, $actorAdmin ? 1 : 0, $_SESSION['user_id'], $isProjectLead ? 0 : 1, $planContext['owner_id'], $planContext['owner_id']]);
        $invite = $inviteQuery->fetch();
        if (!$invite) jsonResponse(false, 'Очікуване запрошення не знайдено.', [], 404);
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('UPDATE project_invitations SET token_hash = ?, expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ?')->execute([hash('sha256', $token), $inviteId]);
        $subject = 'Повторне запрошення до проєкту NexaFlow';
        $context = !empty($invite['group_name']) ? ' групи «' . $invite['group_name'] . '» у проєкті «' . $invite['project_name'] . '»' : ' проєкту «' . $invite['project_name'] . '»';
        $plain = "Вас запросили до{$context} у NexaFlow.\n\nПрийняти запрошення: " . invitationUrl($token) . "\n\nПосилання дійсне 7 днів.";
        $title = 'Запрошення до проєкту «' . $invite['project_name'] . '»';
        $message = 'Вас запросили до' . $context . '.';
        $entityType = 'project';
        $entityId = $invite['project_id'];
        $notificationType = 'project_invite';
    }
    $url = htmlspecialchars(invitationUrl($token), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<!doctype html><html lang="uk"><body style="margin:0;padding:32px 12px;background:#f2f7f4;font-family:Arial,sans-serif;color:#17201e"><div style="max-width:560px;margin:auto;padding:32px;background:#fff;border:1px solid #dce9e1;border-radius:18px"><div style="color:#168b68;font-size:20px;font-weight:bold">NexaFlow</div><h1 style="font-size:24px;margin:24px 0 12px">' . $safeTitle . '</h1><p style="color:#718078;line-height:1.6">' . $safeMessage . '</p><a href="' . $url . '" style="display:inline-block;margin-top:12px;padding:13px 20px;background:#168b68;color:#fff;border-radius:9px;text-decoration:none;font-weight:bold">Прийняти запрошення →</a><p style="margin-top:22px;color:#98a59e;font-size:12px">Посилання дійсне 7 днів.</p></div></body></html>';
    // Invitations are transactional emails and must not depend on the recipient's
    // optional notification preferences.
    $existingUserQuery = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $existingUserQuery->execute([$invite['email']]);
    $existingUserId = (string)($existingUserQuery->fetchColumn() ?: '');
    $sent = sendProjectEmail((string)$invite['email'], $subject, $plain, $html);
    if ($existingUserId !== '' && $existingUserId !== (string)$_SESSION['user_id']) {
        $pdo->prepare('INSERT INTO notifications (id, user_id, sender_id, type, title, message, entity_type, entity_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([bin2hex(random_bytes(16)), $existingUserId, $_SESSION['user_id'], $notificationType, $title, $message, $entityType, $entityId]);
    }
    jsonResponse(true, $sent ? 'Запрошення повторно надіслано. Термін дії посилання — 7 днів.' : 'Посилання оновлено, але поштовий сервер не підтвердив надсилання.', ['sent' => $sent]);
}

$email = strtolower(trim((string)($data['email'] ?? '')));
$projectId = trim((string)($data['project_id'] ?? ''));
$role = trim((string)($data['role'] ?? 'member'));
$groupId = trim((string)($data['group_id'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['admin', 'manager', 'member', 'viewer'], true)) jsonResponse(false, 'Введіть коректний email і роль.', [], 422);
if ($actorAdmin && $requestedWorkspaceOwnerId !== '' && $groupId !== '') {
    $workspaceGroup = $pdo->prepare("SELECT id FROM project_groups WHERE id = ? AND project_id IS NULL AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(permissions) THEN permissions ELSE '{}' END, '$.owner_id')) = ? LIMIT 1");
    $workspaceGroup->execute([$groupId, $requestedWorkspaceOwnerId]);
    if (!$workspaceGroup->fetchColumn()) jsonResponse(false, 'Обрана група не належить цьому простору.', [], 422);
}
if ($isProjectLead && in_array($role, ['admin', 'manager'], true)) jsonResponse(false, 'Керівник проєкту може запросити учасника, але не призначати системні ролі.', [], 403);
$emailUser = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$emailUser->execute([$email]);
$existingId = (string)($emailUser->fetchColumn() ?: '');
$alreadyMember = false;
if ($existingId !== '') {
    $teamMembership = $pdo->prepare("SELECT 1 WHERE EXISTS (SELECT 1 FROM user_invitations WHERE email = ? AND invited_by = ? AND status = 'accepted') OR EXISTS (SELECT 1 FROM project_invitations pi JOIN projects p ON p.id = pi.project_id WHERE pi.email = ? AND pi.invited_by = ? AND p.owner_id = ? AND pi.status = 'accepted') LIMIT 1");
    $teamMembership->execute([$email, $invitationOwnerId, $email, $invitationOwnerId, $invitationOwnerId]);
    $alreadyMember = (bool)$teamMembership->fetchColumn();
}
if (!$actorAdmin) {
    if (!$alreadyMember) requirePlanCapacity($planContext, 'participants');
    if ($role === 'manager') {
        $managerLimit = (int)($planContext['plan']['project_leads'] ?? 0);
        acquirePlanCapacityLock($planContext, 'project_leads');
        $managerCount = planProjectLeadCount($pdo, $planContext);
        $knownManager = false;
        if ($existingId !== '') {
            $memberMarks = implode(',', array_fill(0, count($planContext['member_ids']), '?'));
            $known = $pdo->prepare("SELECT 1 FROM project_members pm JOIN projects p ON p.id = pm.project_id WHERE p.owner_id IN ($memberMarks) AND pm.user_id = ? AND pm.role = 'manager' LIMIT 1");
            $known->execute(array_merge($planContext['member_ids'], [$existingId]));
            $knownManager = (bool)$known->fetchColumn();
        }
        if (!$knownManager && $managerCount >= $managerLimit) { notifyPlanCapacityReached($planContext, 'project_leads', $managerCount, $managerLimit); jsonResponse(false, 'Ліміт керівників проєктів вашого плану вичерпано.', ['code' => 'plan_limit_reached', 'metric' => 'project_leads', 'limit' => $managerLimit, 'current' => $managerCount, 'plan' => $planContext['key']], 403); }
    }
}

if ($projectId === '' && $groupId !== '') {
    $groupProject = $pdo->prepare('SELECT project_id FROM project_groups WHERE id = ? LIMIT 1');
    $groupProject->execute([$groupId]);
    $projectId = (string)($groupProject->fetchColumn() ?: '');
}

if ($projectId === '') {
    $current = $pdo->prepare("SELECT role FROM users WHERE id = ? AND role IN ('admin', 'owner', 'manager') LIMIT 1");
    $current->execute([$_SESSION['user_id']]);
    if (!$current->fetch() && !$isProjectLead) jsonResponse(false, 'Недостатньо прав для загального запрошення.', [], 403);
    if ($groupId !== '') {
        $groupCheck = $pdo->prepare('SELECT id FROM project_groups WHERE id = ? AND project_id IS NULL LIMIT 1');
        $groupCheck->execute([$groupId]);
        if (!$groupCheck->fetch()) jsonResponse(false, 'Обрана група не є глобальною.', [], 422);
        if (!$actorAdmin) {
            if ($isProjectLead) {
                $groupCheck = $pdo->prepare("SELECT id FROM project_groups WHERE id = ? AND project_id IS NULL AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(permissions) THEN permissions ELSE '{}' END, '$.owner_id')) = ? LIMIT 1");
                $groupCheck->execute([$groupId, $planContext['owner_id']]);
                if (!$groupCheck->fetchColumn()) jsonResponse(false, 'Можна запрошувати лише з claims активного простору.', [], 403);
            } else manageableGroup($pdo, $groupId, (string)$_SESSION['user_id'], false);
        }
    }
    if ($existingId !== '' && $alreadyMember) jsonResponse(false, 'Цей користувач уже приєднався до вашої команди.', ['code' => 'already_team_member'], 409);
    $pendingDuplicate = $pdo->prepare("SELECT id FROM user_invitations WHERE invited_by = ? AND email = ? AND status = 'pending' LIMIT 1");
    $pendingDuplicate->execute([$invitationOwnerId, $email]);
    if ($pendingId = $pendingDuplicate->fetchColumn()) jsonResponse(false, 'Для цього користувача вже є очікуване запрошення. Повторно надішліть його зі списку нижче.', ['code' => 'invite_pending', 'invite_id' => $pendingId], 409);
    try {
        $pdo->prepare("UPDATE user_invitations SET status = 'revoked' WHERE invited_by = ? AND email = ? AND status = 'pending'")->execute([$invitationOwnerId, $email]);
        $token = bin2hex(random_bytes(32));
        $invitationId = bin2hex(random_bytes(16));
        $insert = $pdo->prepare('INSERT INTO user_invitations (id, invited_by, email, role, group_id, token_hash, expires_at) VALUES (?, ?, ?, \'user\', ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))');
        $insert->execute([$invitationId, $invitationOwnerId, $email, $groupId !== '' ? $groupId : null, hash('sha256', $token)]);
        releasePlanCapacityLocks((string)$planContext['owner_id']);
        $existingInviteUser = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $existingInviteUser->execute([$email]);
        $existingInviteUser = $existingInviteUser->fetch();
        if ($existingInviteUser && $existingInviteUser['id'] !== $_SESSION['user_id']) {
            $groupName = '';
            if ($groupId !== '') {
                $groupNameQuery = $pdo->prepare('SELECT name FROM project_groups WHERE id = ? LIMIT 1');
                $groupNameQuery->execute([$groupId]);
                $groupName = (string)$groupNameQuery->fetchColumn();
            }
            $title = $groupName !== '' ? 'Запрошення до групи' : 'Запрошення до команди';
            $message = $groupName !== '' ? "Вас запросили до групи «{$groupName}». Перевірте лист із запрошенням, щоб приєднатися." : 'Вас запросили до команди NexaFlow. Перевірте лист із запрошенням, щоб приєднатися.';
            $pdo->prepare("INSERT INTO notifications (id, user_id, sender_id, type, title, message, entity_type, entity_id) VALUES (?, ?, ?, 'team_invite', ?, ?, 'invitation', ?)")
                ->execute([bin2hex(random_bytes(16)), $existingInviteUser['id'], $_SESSION['user_id'], $title, $message, $invitationId]);
        }
        $url = invitationUrl($token);
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $logo = htmlspecialchars(appUrl('/assets/logo-color-text.png'), ENT_QUOTES, 'UTF-8');
        $subject = 'Запрошення до NexaFlow';
        $message = "Вас запросили приєднатися до робочого простору NexaFlow.\n\nСтворіть акаунт або увійдіть, щоб прийняти запрошення:\n{$url}\n\nПосилання дійсне 7 днів. Якщо ви не очікували на запрошення, просто проігноруйте цей лист.";
        $htmlMessage = '<!doctype html><html lang="uk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Запрошення до NexaFlow</title></head><body style="margin:0;padding:0;background:#f2f7f4;font-family:Arial,Helvetica,sans-serif;color:#17201e"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f2f7f4;padding:36px 14px"><tr><td align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;background:#fff;border:1px solid #dce9e1;border-radius:20px;overflow:hidden"><tr><td style="height:5px;background:#168b68;font-size:0;line-height:0">&nbsp;</td></tr><tr><td style="padding:32px 36px 34px"><img src="' . $logo . '" width="166" alt="NexaFlow" style="display:block;width:166px;max-width:60%;height:auto;margin:0 0 30px;border:0"><div style="display:inline-block;padding:6px 10px;background:#e9f6ef;border-radius:999px;color:#168b68;font-size:10px;font-weight:bold;letter-spacing:1.2px">ЗАПРОШЕННЯ ДО КОМАНДИ</div><h1 style="margin:16px 0 10px;font-size:25px;line-height:1.25;color:#17201e">Вас запросили до NexaFlow</h1><p style="margin:0 0 24px;font-size:14px;line-height:1.7;color:#718078">Приєднайтеся до робочого простору, щоб бачити проєкти, задачі та працювати разом із командою. Створіть акаунт або увійдіть, якщо вже зареєстровані.</p><table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" style="border-radius:10px;background:#168b68"><a href="' . $safeUrl . '" style="display:inline-block;padding:14px 22px;border:1px solid #168b68;border-radius:10px;color:#fff;text-decoration:none;font-size:14px;font-weight:bold">Переглянути запрошення&nbsp; →</a></td></tr></table><p style="margin:22px 0 7px;font-size:12px;line-height:1.6;color:#718078">Запрошення дійсне 7 днів. Якщо кнопка не відкривається, скористайтеся посиланням:</p><p style="margin:0;word-break:break-all;font-size:11px;line-height:1.6"><a href="' . $safeUrl . '" style="color:#168b68;text-decoration:underline">' . $safeUrl . '</a></p><div style="height:1px;margin:24px 0 16px;background:#e5eee8"></div><p style="margin:0;font-size:11px;line-height:1.6;color:#98a59e">Якщо ви не очікували на запрошення, просто проігноруйте цей лист.</p></td></tr></table><p style="margin:16px 0 0;font-size:10px;color:#9aa8a1">NexaFlow · Один простір для роботи команди</p></td></tr></table></body></html>';
        $sent = sendProjectEmail($email, $subject, $message, $htmlMessage);
        $participantLimit = $planContext['plan']['participants'] ?? null;
        if (!$actorAdmin && !$alreadyMember && $participantLimit !== null && (int)$planContext['counts']['participants'] + 1 >= (int)$participantLimit) notifyPlanCapacityReached($planContext, 'participants', (int)$participantLimit, (int)$participantLimit);
        jsonResponse(true, $sent ? 'Загальне запрошення відправлено на email.' : 'Запрошення створено, але поштовий сервер не підтвердив відправку.', ['sent' => $sent]);
    } catch (Throwable $error) {
        error_log($error->getMessage());
        jsonResponse(false, 'Не вдалося створити загальне запрошення.', [], 500);
    }
}

$access = $pdo->prepare("SELECT p.name FROM projects p LEFT JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ? JOIN users cu ON cu.id = ? WHERE p.id = ? AND (cu.role IN ('admin', 'owner') OR p.owner_id = ? OR p.owner_id = ? OR pm.role IN ('admin', 'manager')) LIMIT 1");
$access->execute([$_SESSION['user_id'], $_SESSION['user_id'], $projectId, $_SESSION['user_id'], $planContext['owner_id']]);
$project = $access->fetch();
if (!$project) jsonResponse(false, 'У вас немає прав запрошувати людей у цей проєкт.', [], 403);
$existingProjectMember = $pdo->prepare("SELECT 1 FROM project_members pm JOIN users u ON u.id = pm.user_id WHERE pm.project_id = ? AND u.email = ? LIMIT 1");
$existingProjectMember->execute([$projectId, $email]);
if ($existingProjectMember->fetchColumn()) jsonResponse(false, 'Цей користувач уже є учасником проєкту.', ['code' => 'already_project_member'], 409);
$pendingProjectDuplicate = $pdo->prepare("SELECT id FROM project_invitations WHERE project_id = ? AND invited_by = ? AND email = ? AND status = 'pending' LIMIT 1");
$pendingProjectDuplicate->execute([$projectId, $_SESSION['user_id'], $email]);
if ($pendingProjectId = $pendingProjectDuplicate->fetchColumn()) jsonResponse(false, 'Для цього користувача вже є очікуване запрошення. Повторно надішліть його зі списку нижче.', ['code' => 'invite_pending', 'invite_id' => $pendingProjectId], 409);
if ($groupId !== '') {
    $groupCheck = $pdo->prepare('SELECT id, project_id FROM project_groups WHERE id = ? AND (project_id = ? OR project_id IS NULL) LIMIT 1');
    $groupCheck->execute([$groupId, $projectId]);
    $selectedGroup = $groupCheck->fetch();
    if (!$selectedGroup) jsonResponse(false, 'Обрана група не належить цьому проєкту.', [], 422);
    if (!$actorAdmin && $selectedGroup['project_id'] === null) {
        if ($isProjectLead) {
            $groupPermissions = $pdo->prepare('SELECT permissions FROM project_groups WHERE id = ? LIMIT 1');
            $groupPermissions->execute([$groupId]);
            $groupOwner = groupPolicy((string)($groupPermissions->fetchColumn() ?: ''))['owner_id'] ?? '';
            if ((string)$groupOwner !== (string)$planContext['owner_id']) jsonResponse(false, 'Можна використовувати лише claims активного простору.', [], 403);
        } else manageableGroup($pdo, $groupId, (string)$_SESSION['user_id'], false);
    }
}

try {
    $pdo->prepare("UPDATE project_invitations SET status = 'revoked' WHERE project_id = ? AND invited_by = ? AND email = ? AND status = 'pending'")->execute([$projectId, $_SESSION['user_id'], $email]);
    $token = bin2hex(random_bytes(32));
    $id = bin2hex(random_bytes(16));
    $insert = $pdo->prepare('INSERT INTO project_invitations (id, project_id, invited_by, email, role, group_id, token_hash, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))');
    $insert->execute([$id, $projectId, $_SESSION['user_id'], $email, $role, $groupId !== '' ? $groupId : null, hash('sha256', $token)]);
    releasePlanCapacityLocks((string)$planContext['owner_id']);
    $url = invitationUrl($token);
    $subject = 'Вас запросили до проєкту NexaFlow';
    $message = "Вас запросили до проєкту «{$project['name']}» у NexaFlow.\n\nРоль: {$role}\n\nПрийняти запрошення: {$url}\n\nПосилання дійсне 7 днів.";
    $safeProject = htmlspecialchars($project['name'], ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $htmlMessage = "<div style=\"font-family:Arial,sans-serif;max-width:600px;color:#17201e\"><h2>Вас запросили до проєкту</h2><p>Вас запросили до проєкту <strong>«{$safeProject}»</strong> у NexaFlow.</p><p>Роль: {$role}</p><p><a href=\"{$safeUrl}\" style=\"display:inline-block;padding:12px 20px;background:#1f8a68;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold\">Приєднатись до проєкту</a></p><p style=\"color:#7b8983;font-size:12px\">Посилання дійсне 7 днів.</p></div>";
    $existingUserQuery = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $existingUserQuery->execute([$email]);
    $existingUser = $existingUserQuery->fetch();
    if ($existingUser && $existingUser['id'] !== $_SESSION['user_id']) {
        $pdo->prepare("INSERT INTO notifications (id, user_id, sender_id, type, title, message, entity_type, entity_id) VALUES (?, ?, ?, 'project_invite', ?, ?, 'project', ?)")->execute([bin2hex(random_bytes(16)), $existingUser['id'], $_SESSION['user_id'], 'Запрошення до нового проєкту', "Вас запросили до проєкту «{$project['name']}». Відкрийте лист і натисніть «Приєднатись до проєкту».", $projectId]);
    }
    $sent = sendProjectEmail($email, $subject, $message, $htmlMessage);
    $participantLimit = $planContext['plan']['participants'] ?? null;
    if (!$actorAdmin && !$alreadyMember && $participantLimit !== null && (int)$planContext['counts']['participants'] + 1 >= (int)$participantLimit) notifyPlanCapacityReached($planContext, 'participants', (int)$participantLimit, (int)$participantLimit);
    jsonResponse(true, $sent ? 'Запрошення відправлено на email.' : 'Запрошення створено, але хостинг не підтвердив відправку email.', ['sent' => $sent, 'expires_in_days' => 7]);
} catch (Throwable $error) {
    error_log($error->getMessage());
    jsonResponse(false, 'Не вдалося створити запрошення.', [], 500);
}
