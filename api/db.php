<?php
declare(strict_types=1);
require_once __DIR__ . '/runtime-config.php';
require_once __DIR__ . '/claims.php';

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
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
        $accountStatus = $pdo->prepare('SELECT is_blocked, blocked_reason, email_verified_at FROM users WHERE id = ? LIMIT 1');
        $accountStatus->execute([(string)$_SESSION['user_id']]);
        $status = $accountStatus->fetch();
        if (!$status) jsonResponse(false, 'Користувача не знайдено.', [], 401);
        if ($status && (int)$status['is_blocked'] === 1) jsonResponse(false, 'Обліковий запис заблоковано.', ['code' => 'account_blocked', 'reason' => (string)($status['blocked_reason'] ?? '')], 423);
        $selfService = ['me.php', 'profile.php', 'email-verification-account.php'];
        if ($status && empty($status['email_verified_at']) && !in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), $selfService, true)) {
            jsonResponse(false, 'Підтвердьте email у налаштуваннях профілю, щоб продовжити.', ['code' => 'email_verification_required'], 403);
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
