<?php
declare(strict_types=1);
require_once __DIR__ . '/maintenance.php';

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (!preg_match('~/api/([a-zA-Z0-9_-]+\.php)$~', (string)$path, $match)) { http_response_code(404); exit; }
$script = $match[1];
$private = ['maintenance-entry.php', 'maintenance.php', 'db.php', 'plans.php', 'claims.php', 'runtime-config.php', 'verification.php', 'mailer.php', 'rate-limit.php', 'task-deadline-notifications.php', 'dentistry-erp-schema.php', 'dentistry-erp-ledger.php', 'dentistry-erp-operations.php', 'telegram.php', 'config.php', 'config.local.php', 'config.web.php', 'config.example.php', 'liqpay.local.php'];
if (in_array($script, $private, true) || !is_file(__DIR__ . '/' . $script)) { http_response_code(404); exit; }
try {
    $pdo = maintenanceConnection();
    $state = maintenanceState($pdo);
    $allowed = !((bool)$state['enabled']) || maintenanceAdmin($pdo);
} catch (Throwable $error) { $allowed = false; }
if (!$allowed) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Retry-After: 300');
    echo json_encode(['ok' => false, 'code' => 'maintenance_mode', 'message' => 'NexaFlow тимчасово недоступний через технічні роботи.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$_SERVER['SCRIPT_NAME'] = '/api/' . $script;
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
require __DIR__ . '/' . $script;
