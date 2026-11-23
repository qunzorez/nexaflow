<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require_once __DIR__ . '/plans.php';
require_once __DIR__ . '/accounting-requisites-lib.php';
session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);
$pdo = db();
$userId = (string)$_SESSION['user_id'];
$userQuery = $pdo->prepare('SELECT id, name, email, avatar_url, role FROM users WHERE id = ? LIMIT 1');
$userQuery->execute([$userId]);
$user = $userQuery->fetch();
if (!$user) jsonResponse(false, 'Користувача не знайдено.', [], 401);
$context = workspacePlan($pdo, $userId, (string)$user['role']);
$workspaceRole = (string)($context['workspace_role'] ?? '');
$canManage = in_array($workspaceRole, ['manager', 'admin', 'owner'], true) || in_array((string)$user['role'], ['admin', 'owner'], true);
if (!$canManage) jsonResponse(false, 'Реквізити доступні лише менеджерам і адміністраторам.', [], 403);
if (($context['module'] ?? '') !== 'accounting') jsonResponse(false, 'Реквізити доступні лише в бухгалтерському просторі.', [], 403);
$ownerId = (string)$context['owner_id'];
ensureAccountingRequisitesSchema($pdo);
$data = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? body() : [];
$action = (string)($data['action'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $user['workspaces'] = $context['workspaces'] ?? [];
    $user['active_workspace_id'] = $ownerId;
    $user['workspace_role'] = $workspaceRole;
    $user['subscription'] = planSettingsPayload($context);
    $query = $pdo->prepare('SELECT * FROM accounting_requisites WHERE owner_id = ? ORDER BY active DESC, display_name ASC');
    $query->execute([$ownerId]);
    $historyQuery = $pdo->prepare('SELECT h.id,h.requisites_id,h.action,h.old_values,h.new_values,h.created_at,u.name user_name FROM accounting_requisites_history h LEFT JOIN users u ON u.id=h.user_id WHERE h.owner_id=? ORDER BY h.created_at DESC LIMIT 100');
    $historyQuery->execute([$ownerId]);
    jsonResponse(true, 'Реквізити завантажено.', ['user' => $user, 'requisites' => $query->fetchAll(), 'history' => $historyQuery->fetchAll(), 'can_manage' => true]);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') jsonResponse(false, 'Метод не підтримується.', [], 405);

if ($action === 'save') {
    $id = trim((string)($data['id'] ?? ''));
    $values = [
        'display_name' => mb_substr(trim((string)($data['display_name'] ?? '')), 0, 190),
        'legal_form' => mb_substr(trim((string)($data['legal_form'] ?? '')), 0, 80),
        'tax_identifier' => preg_replace('/\s+/', '', (string)($data['tax_identifier'] ?? '')),
        'legal_address' => mb_substr(trim((string)($data['legal_address'] ?? '')), 0, 500),
        'postal_address' => mb_substr(trim((string)($data['postal_address'] ?? '')), 0, 500),
        'iban' => strtoupper(preg_replace('/\s+/', '', (string)($data['iban'] ?? ''))),
        'bank_name' => mb_substr(trim((string)($data['bank_name'] ?? '')), 0, 190),
        'bank_details' => mb_substr(trim((string)($data['bank_details'] ?? '')), 0, 500),
        'contact_phone' => mb_substr(trim((string)($data['contact_phone'] ?? '')), 0, 50),
        'contact_email' => mb_substr(trim((string)($data['contact_email'] ?? '')), 0, 190),
        'tax_status' => mb_substr(trim((string)($data['tax_status'] ?? '')), 0, 190),
        'additional_info' => mb_substr(trim((string)($data['additional_info'] ?? '')), 0, 5000),
    ];
    if (!$values['display_name'] || !$values['legal_form'] || !$values['legal_address'] || !$values['bank_name']) jsonResponse(false, 'Заповніть назву, форму власності, юридичну адресу та назву банку.', [], 422);
    if (!preg_match('/^(\d{8}|\d{10})$/', $values['tax_identifier'])) jsonResponse(false, 'Код ЄДРПОУ або РНОКПП має містити 8 або 10 цифр.', [], 422);
    if (!preg_match('/^UA\d{27}$/', $values['iban'])) jsonResponse(false, 'IBAN має бути українського формату: UA і 27 цифр.', [], 422);
    if ($values['contact_email'] && !filter_var($values['contact_email'], FILTER_VALIDATE_EMAIL)) jsonResponse(false, 'Перевірте контактну електронну адресу.', [], 422);
    $duplicate = $pdo->prepare('SELECT id FROM accounting_requisites WHERE owner_id=? AND LOWER(display_name)=LOWER(?) AND id<>? LIMIT 1');
    $duplicate->execute([$ownerId, $values['display_name'], $id]);
    if ($duplicate->fetchColumn()) jsonResponse(false, 'Набір реквізитів із такою назвою вже існує.', [], 409);

    $pdo->beginTransaction();
    if ($id) {
        $existingQuery = $pdo->prepare('SELECT * FROM accounting_requisites WHERE id=? AND owner_id=? FOR UPDATE');
        $existingQuery->execute([$id, $ownerId]);
        $existing = $existingQuery->fetch();
        if (!$existing) jsonResponse(false, 'Реквізити не знайдено в цьому робочому просторі.', [], 404);
        $active = array_key_exists('active', $data) ? (int)(bool)$data['active'] : (int)$existing['active'];
        $pdo->prepare('UPDATE accounting_requisites SET display_name=?,legal_form=?,tax_identifier=?,legal_address=?,postal_address=?,iban=?,bank_name=?,bank_details=?,contact_phone=?,contact_email=?,tax_status=?,additional_info=?,active=?,updated_by=? WHERE id=? AND owner_id=?')->execute(array_merge(array_values($values), [$active, $userId, $id, $ownerId]));
        $newValues = array_merge($values, ['active' => $active]);
        $oldValues = $existing;
        $historyAction = 'updated';
    } else {
        $id = bin2hex(random_bytes(16));
        $active = 1;
        $pdo->prepare('INSERT INTO accounting_requisites(id,owner_id,display_name,legal_form,tax_identifier,legal_address,postal_address,iban,bank_name,bank_details,contact_phone,contact_email,tax_status,additional_info,active,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_merge([$id, $ownerId], array_values($values), [$active, $userId, $userId]));
        $newValues = array_merge($values, ['active' => $active]);
        $oldValues = null;
        $historyAction = 'created';
    }
    $pdo->prepare('INSERT INTO accounting_requisites_history(id,owner_id,requisites_id,user_id,action,old_values,new_values) VALUES(?,?,?,?,?,?,?)')->execute([bin2hex(random_bytes(16)), $ownerId, $id, $userId, $historyAction, $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null, json_encode($newValues, JSON_UNESCAPED_UNICODE)]);
    $pdo->commit();
    jsonResponse(true, $historyAction === 'created' ? 'Реквізити збережено.' : 'Реквізити оновлено.', ['id' => $id]);
}
if (in_array($action, ['deactivate', 'activate'], true)) {
    $id = trim((string)($data['id'] ?? ''));
    $query = $pdo->prepare('SELECT * FROM accounting_requisites WHERE id=? AND owner_id=?');
    $query->execute([$id, $ownerId]);
    $existing = $query->fetch();
    if (!$existing) jsonResponse(false, 'Реквізити не знайдено в цьому робочому просторі.', [], 404);
    $nextActive = $action === 'activate' ? 1 : 0;
    if ((int)$existing['active'] === $nextActive) jsonResponse(false, $nextActive ? 'Ці реквізити вже активні.' : 'Ці реквізити вже вимкнено.', [], 409);
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE accounting_requisites SET active=?,updated_by=? WHERE id=? AND owner_id=?')->execute([$nextActive, $userId, $id, $ownerId]);
    $event = $nextActive ? 'activated' : 'deactivated';
    $pdo->prepare('INSERT INTO accounting_requisites_history(id,owner_id,requisites_id,user_id,action,old_values,new_values) VALUES(?,?,?,?,?,?,?)')->execute([bin2hex(random_bytes(16)), $ownerId, $id, $userId, $event, json_encode($existing, JSON_UNESCAPED_UNICODE), json_encode(array_merge($existing, ['active' => $nextActive]), JSON_UNESCAPED_UNICODE)]);
    $pdo->commit();
    jsonResponse(true, $nextActive ? 'Реквізити активовано.' : 'Реквізити вимкнено. Раніше сформовані рахунки збережуть свою копію даних.');
}
jsonResponse(false, 'Невідома дія.', [], 422);
