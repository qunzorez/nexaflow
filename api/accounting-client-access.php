<?php
declare(strict_types=1);
require_once __DIR__.'/claims.php';

function accountingCanManageClient(PDO $pdo, array $ctx, string $uid, string $clientId): bool {
    if ($clientId === '' || ($ctx['module'] ?? '') !== 'accounting') return false;
    $members = array_values(array_unique(array_map('strval', $ctx['member_ids'] ?? [])));
    if (!$members) return false;
    $marks = implode(',', array_fill(0, count($members), '?'));
    $manager = in_array((string)($ctx['workspace_role'] ?? ''), ['manager','admin','owner'], true);
    $sql = "SELECT c.module_data,c.services FROM clients c WHERE c.id=? AND c.module='accounting' AND c.created_by IN ({$marks})";
    if (!$manager) $sql .= ' AND (c.created_by=? OR EXISTS(SELECT 1 FROM client_members cm WHERE cm.client_id=c.id AND cm.user_id=?))';
    $query = $pdo->prepare($sql);
    $query->execute(array_merge([$clientId], $members, $manager ? [] : [$uid, $uid]));
    $client = $query->fetch(PDO::FETCH_ASSOC);
    if (!$client) return false;
    if ($manager || hasClaim(resourceClaims($pdo, $uid, 'client', $clientId), 'edit')) return true;
    $module = json_decode((string)($client['module_data'] ?? '{}'), true) ?: [];
    if (in_array($uid, array_map('strval', (array)($module['accountant_ids'] ?? [])), true)) return true;
    foreach (json_decode((string)($client['services'] ?? '[]'), true) ?: [] as $service) {
        if ((string)($service['accountant_id'] ?? '') === $uid || in_array($uid, array_map('strval', (array)($service['accountant_ids'] ?? [])), true)) return true;
    }
    return false;
}
