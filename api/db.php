<?php
declare(strict_types=1);
require_once __DIR__ . '/runtime-config.php';
require_once __DIR__ . '/claims.php';
require_once __DIR__ . '/maintenance.php';
require_once __DIR__ . '/accounting-portal-access.php';

function db(): PDO
{
    static $pdo;
    if (!($pdo instanceof PDO)) {
        $config = appConfig();
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $config['host'], $config['port'], $config['database'], $config['charset']);
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]);
    }
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (!in_array($script, ['login.php', 'logout.php', 'maintenance-status.php'], true)) {
        try {
            $maintenance = maintenanceState($pdo);
            if ((bool)$maintenance['enabled'] && !maintenanceAdmin($pdo)) jsonResponse(false, 'NexaFlow тимчасово недоступний через технічні роботи.', ['code' => 'maintenance_mode'], 503);
        } catch (PDOException $error) {
            if ($error->getCode() !== '42S02') jsonResponse(false, 'Сервіс тимчасово недоступний.', ['code' => 'maintenance_mode'], 503);
        }
    }
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
        $accountStatus = $pdo->prepare('SELECT role, is_blocked, blocked_reason, email_verified_at FROM users WHERE id = ? LIMIT 1');
        $accountStatus->execute([(string)$_SESSION['user_id']]);
        $status = $accountStatus->fetch();
        if (!$status) jsonResponse(false, 'Користувача не знайдено.', [], 401);
        if ($status && (int)$status['is_blocked'] === 1) jsonResponse(false, 'Обліковий запис заблоковано.', ['code' => 'account_blocked', 'reason' => (string)($status['blocked_reason'] ?? '')], 423);
        $selfService = ['me.php', 'profile.php', 'email-verification-account.php', 'accounting-client-invitation.php'];
        if ($status && empty($status['email_verified_at']) && !in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), $selfService, true)) {
            jsonResponse(false, 'Підтвердьте email у налаштуваннях профілю, щоб продовжити.', ['code' => 'email_verification_required'], 403);
        }
        $portalRoutes = ['me.php', 'profile.php', 'email-verification-account.php', 'accounting-client-invitation.php', 'accounting-portal.php', 'login.php', 'logout.php', 'forgot-password.php', 'reset-password.php'];
        if (accountingPortalOnlyUser($pdo, (string)$_SESSION['user_id'], (string)$status['role']) && !in_array($script, $portalRoutes, true)) {
            jsonResponse(false, 'Ця дія недоступна у кабінеті клієнта.', ['code' => 'client_portal_only'], 403);
        }
    }
    return $pdo;
}

function jsonResponse(bool $ok, string $message = '', array $extra = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function body(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}
