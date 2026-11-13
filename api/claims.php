<?php
declare(strict_types=1);

const GROUP_CLAIMS = ['read', 'update', 'edit', 'delete'];

function groupPolicy($raw): ?array
{
    $policy = is_string($raw) ? json_decode($raw, true) : $raw;
    return is_array($policy) && ($policy['version'] ?? null) === 1 && isset($policy['claims']) && is_array($policy['claims']) ? $policy : null;
}

function encodeGroupPolicy(array $data, string $ownerId): string
{
    $claims = $data['permissions'] ?? [];
    if (!is_array($claims) || array_diff($claims, GROUP_CLAIMS)) jsonResponse(false, 'Некоректний список прав групи.', [], 422);
    return json_encode(['version' => 1, 'owner_id' => $ownerId, 'claims' => array_values(array_unique($claims))], JSON_UNESCAPED_UNICODE);
}

function resolveClaims(string $role, array $policies): array
{
    if (in_array($role, ['admin', 'owner', 'manager'], true)) return ['assigned' => false, 'allowed' => GROUP_CLAIMS];
    $allowed = []; $assigned = false;
    foreach ($policies as $raw) {
        $policy = groupPolicy($raw);
        if ($policy === null) continue; // Old functional groups keep existing role behaviour until edited.
        $assigned = true;
        $allowed = array_merge($allowed, array_intersect($policy['claims'], GROUP_CLAIMS));
    }
    return ['assigned' => $assigned, 'allowed' => $assigned ? array_values(array_unique($allowed)) : ['read']];
}

function userClaims(PDO $pdo, string $userId, ?array $ownerIds = null): array
{
    $query = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $query->execute([$userId]); $role = (string)$query->fetchColumn(); $query->closeCursor();
    $query = $pdo->prepare('SELECT pg.permissions FROM project_groups pg JOIN project_group_members gm ON gm.group_id = pg.id WHERE gm.user_id = ? AND pg.project_id IS NULL');
    $query->execute([$userId]); $policies = $query->fetchAll(PDO::FETCH_COLUMN); $query->closeCursor();
    if ($ownerIds !== null) $policies = array_filter($policies, static function ($raw) use ($ownerIds): bool {
        $policy = groupPolicy($raw);
        return $policy !== null && in_array($policy['owner_id'] ?? '', $ownerIds, true);
    });
    return resolveClaims($role, $policies);
}

function resourceClaims(PDO $pdo, string $userId, string $type, string $id): array
{
    if ($type === 'project') $sql = 'SELECT owner_id FROM projects WHERE id = ?';
    elseif ($type === 'client') $sql = 'SELECT created_by FROM clients WHERE id = ?';
    else $sql = 'SELECT DISTINCT COALESCE(p.owner_id, t.created_by) FROM tasks t LEFT JOIN task_projects tp ON tp.task_id = t.id LEFT JOIN projects p ON p.id = COALESCE(tp.project_id, t.project_id) WHERE t.id = ?';
    $query = $pdo->prepare($sql); $query->execute([$id]); $owners = $query->fetchAll(PDO::FETCH_COLUMN); $query->closeCursor();
    // For shared resources, every participating manager policy must permit the action.
    // A user without an assigned group keeps the default read-only access.
    if (!$owners) return userClaims($pdo, $userId);
    $result = null;
    foreach ($owners as $owner) {
        $claims = userClaims($pdo, $userId, [(string)$owner]);
        if ($result === null) $result = $claims;
        else {
            $result['assigned'] = $result['assigned'] || $claims['assigned'];
            $result['allowed'] = array_values(array_intersect($result['allowed'], $claims['allowed']));
        }
    }
    return $result ?? userClaims($pdo, $userId);
}

function claimRows(PDO $pdo, string $userId, string $type, array $rows): array
{
    $visible = [];
    foreach ($rows as $row) {
        $row['claims'] = resourceClaims($pdo, $userId, $type, (string)$row['id']);
        if (hasClaim($row['claims'], 'read')) $visible[] = $row;
    }
    return $visible;
}

function hasClaim(array $claims, string $claim): bool
{
    return in_array($claim, $claims['allowed'], true);
}

function requireClaim(array $claims, string $claim): void
{
    if (!hasClaim($claims, 'read') || !hasClaim($claims, $claim)) jsonResponse(false, 'Ваша група не має права виконувати цю дію.', [], 403);
}

function exposeGroupPolicy(array $group): array
{
    $policy = groupPolicy($group['permissions'] ?? null);
    $group['permissions'] = $policy['claims'] ?? [];
    $group['claims_enabled'] = $policy !== null;
    $group['group_owner_id'] = $policy['owner_id'] ?? null;
    return $group;
}

function manageableGroup(PDO $pdo, string $groupId, string $actorId, bool $admin): array
{
    $query = $pdo->prepare('SELECT * FROM project_groups WHERE id = ? AND project_id IS NULL');
    $query->execute([$groupId]); $group = $query->fetch(); $query->closeCursor();
    if (!$group) jsonResponse(false, 'Групу не знайдено.', [], 404);
    $policy = groupPolicy($group['permissions']);
    if (!$admin && ($policy['owner_id'] ?? '') !== $actorId) jsonResponse(false, 'Керувати цією групою може її автор або адміністратор.', [], 403);
    return $group;
}
