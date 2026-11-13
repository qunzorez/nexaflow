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
    // Keep the API usable when the live database predates the time-tracking
    // migration. Dashboard reads this table before the Tasks page gets a
    // chance to create it, so initialize it centrally during DB bootstrap.
    // Only run this after the tasks table exists; this keeps first-run auth
    // working while the rest of the schema is being bootstrapped.
    $hasTasksTable = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks'")->fetchColumn();
    if ($hasTasksTable > 0) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS time_entries (
            id CHAR(32) PRIMARY KEY,
            task_id CHAR(32) NOT NULL,
            user_id CHAR(32) NOT NULL,
            minutes INT UNSIGNED NOT NULL,
            note VARCHAR(500) NULL,
            logged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_time_entries_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            CONSTRAINT fk_time_entries_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    // Normalize the legacy typo in existing claims groups so it is never shown again.
    // The try/catch keeps first-run database initialization safe before project_groups exists.
    try {
        $pdo->exec("UPDATE project_groups SET name = 'Бухгалтер' WHERE name = 'Бугалтер'");
        // Repair legacy records that were stored as double-encoded UTF-8.
        // HEX() avoids collation/charset errors on older MySQL installations.
        $pdo->exec("UPDATE project_groups SET name = 'Бухгалтер' WHERE project_id IS NULL AND (HEX(name) LIKE 'C390C291C391C283C391C285C390C2B3C390C2B0C390C2BB%' OR HEX(name) LIKE 'C390C2B1C391C283C391C285C390C2B3C390C2B0C390C2BB%')");
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
