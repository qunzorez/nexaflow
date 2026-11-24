<?php
declare(strict_types=1);

function accountingPortalOnlyUser(PDO $pdo, string $userId, string $role): bool
{
    if ($role !== 'user') return false;
    try {
        $query = $pdo->prepare("SELECT EXISTS(SELECT 1 FROM accounting_client_portal_access a WHERE a.user_id = ?) AS has_portal,
            EXISTS(SELECT 1 FROM client_members cm WHERE cm.user_id = ?) AS has_clients,
            EXISTS(SELECT 1 FROM project_members pm WHERE pm.user_id = ?) AS has_projects,
            EXISTS(SELECT 1 FROM user_invitations i JOIN users u ON LOWER(u.email) = LOWER(i.email) WHERE u.id = ? AND i.status = 'accepted') AS has_team_invite,
            EXISTS(SELECT 1 FROM project_invitations i JOIN users u ON LOWER(u.email) = LOWER(i.email) WHERE u.id = ? AND i.status = 'accepted') AS has_project_invite");
        $query->execute([$userId, $userId, $userId, $userId, $userId]);
        $access = $query->fetch();
        return $access && (int)$access['has_portal'] === 1 && (int)$access['has_clients'] === 0 && (int)$access['has_projects'] === 0 && (int)$access['has_team_invite'] === 0 && (int)$access['has_project_invite'] === 0;
    } catch (PDOException $error) {
        if ($error->getCode() === '42S02') return false;
        throw $error;
    }
}
