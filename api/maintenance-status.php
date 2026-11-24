<?php
declare(strict_types=1);
require_once __DIR__ . '/maintenance.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    $pdo = maintenanceConnection();
    $state = maintenanceState($pdo);
    echo json_encode(['ok' => true, 'enabled' => (bool)$state['enabled'], 'starts_at' => (int)$state['requested_enabled'] === 1 && !(bool)$state['enabled'] ? $state['starts_at'] : null, 'starts_at_epoch' => (int)$state['requested_enabled'] === 1 && !(bool)$state['enabled'] ? $state['starts_at_epoch'] : null, 'notice_minutes' => (int)$state['notice_minutes'], 'is_admin' => (bool)$state['enabled'] && maintenanceAdmin($pdo), 'title' => $state['title'], 'message' => $state['message'], 'estimated_end' => $state['estimated_end'], 'estimated_end_epoch' => $state['estimated_end_epoch'], 'note' => $state['note']], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'enabled' => true, 'message' => 'Стан сервісу тимчасово недоступний.'], JSON_UNESCAPED_UNICODE);
}
