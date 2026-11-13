<?php
declare(strict_types=1);
require_once __DIR__ . '/runtime-config.php';
require_once __DIR__ . '/claims.php';

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;

    $config = appConfig();
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $config['host'], $config['port'], $config['database'], $config['charset']);
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
    // Normalize the legacy typo in existing claims groups so it is never shown again.
    // The try/catch keeps first-run database initialization safe before project_groups exists.
    try {
        $pdo->exec("UPDATE project_groups SET name = 'Бухгалтер' WHERE name = 'Бугалтер'");
    } catch (Throwable $ignored) {
        // The schema migration will handle it after the table is created.
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
