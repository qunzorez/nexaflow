<?php
declare(strict_types=1);
require_once __DIR__ . '/runtime-config.php';

function maintenanceConnection(): PDO
{
    $config = appConfig();
    return new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $config['host'], $config['port'], $config['database'], $config['charset']), $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
}

function ensureMaintenanceSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_maintenance (id TINYINT UNSIGNED NOT NULL PRIMARY KEY, enabled TINYINT(1) NOT NULL DEFAULT 0, starts_at DATETIME NULL, title VARCHAR(160) NOT NULL DEFAULT 'Технічні роботи', message TEXT NOT NULL, estimated_end DATETIME NULL, note VARCHAR(255) NULL, updated_by CHAR(32) NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY idx_maintenance_updated_by (updated_by), CONSTRAINT fk_maintenance_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!$pdo->query("SHOW COLUMNS FROM system_maintenance LIKE 'starts_at'")->fetch()) $pdo->exec('ALTER TABLE system_maintenance ADD COLUMN starts_at DATETIME NULL AFTER enabled');
    $pdo->exec("INSERT IGNORE INTO system_maintenance (id, enabled, title, message) VALUES (1, 0, 'Технічні роботи', 'Ми оновлюємо NexaFlow. Спробуйте зайти трохи пізніше.')");
}

function maintenanceState(PDO $pdo): array
{
    try {
        try { $row = $pdo->query('SELECT enabled AS requested_enabled, CASE WHEN enabled = 1 AND (starts_at IS NULL OR starts_at <= NOW()) THEN 1 ELSE 0 END AS enabled, starts_at, UNIX_TIMESTAMP(starts_at) AS starts_at_epoch, title, message, estimated_end, note, updated_by, updated_at FROM system_maintenance WHERE id = 1')->fetch(); }
        catch (PDOException $error) {
            if ($error->getCode() !== '42S22') throw $error;
            $row = $pdo->query('SELECT enabled AS requested_enabled, enabled, NULL AS starts_at, NULL AS starts_at_epoch, title, message, estimated_end, note, updated_by, updated_at FROM system_maintenance WHERE id = 1')->fetch();
        }
        return $row ?: ['enabled' => 0, 'requested_enabled' => 0, 'starts_at' => null, 'starts_at_epoch' => null, 'title' => 'Технічні роботи', 'message' => 'Ми оновлюємо NexaFlow. Спробуйте зайти трохи пізніше.', 'estimated_end' => null, 'note' => null, 'updated_by' => null, 'updated_at' => null];
    } catch (PDOException $error) {
        if ($error->getCode() === '42S02') return ['enabled' => 0, 'requested_enabled' => 0, 'starts_at' => null, 'starts_at_epoch' => null, 'title' => 'Технічні роботи', 'message' => '', 'estimated_end' => null, 'note' => null, 'updated_at' => null];
        throw $error;
    }
}

function maintenanceAdmin(PDO $pdo): bool
{
    $started = session_status() !== PHP_SESSION_ACTIVE;
    if ($started) session_start();
    $userId = (string)($_SESSION['user_id'] ?? '');
    if ($started) session_write_close();
    if ($userId === '') return false;
    $query = $pdo->prepare('SELECT role FROM users WHERE id = ? AND is_blocked = 0 LIMIT 1');
    $query->execute([$userId]);
    return in_array($query->fetchColumn(), ['admin', 'owner'], true);
}
