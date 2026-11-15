<?php
declare(strict_types=1);

// Run on the hosting server from the repository checkout. Pass --apply only
// after reviewing the dry run. Original values are stored before each update.
if (PHP_SAPI !== 'cli') exit(1);
$apply = in_array('--apply', $argv, true);
$config = require '/home3/nexaflow/public_html/api/config.local.php';
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'] ?? 3306, $config['database']);
$pdo = new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$columns = [
    'users' => ['name', 'bio'],
    'projects' => ['name', 'description'],
    'tasks' => ['title', 'description', 'client_name'],
    'clients' => ['name'],
    'project_groups' => ['name', 'role_name'],
    'notifications' => ['title', 'message'],
    'task_history' => ['message'],
];

function repaired(string $value): ?string
{
    if (!str_contains($value, 'Ð') && !str_contains($value, 'Ñ') && !str_contains($value, 'Ã')) return null;
    $decoded = mb_convert_encoding($value, 'Windows-1252', 'UTF-8');
    if (!mb_check_encoding($decoded, 'UTF-8')) return null;
    $before = preg_match_all('/[А-Яа-яІіЇїЄєҐґ]/u', $value);
    $after = preg_match_all('/[А-Яа-яІіЇїЄєҐґ]/u', $decoded);
    return $after > $before && $after >= 2 ? $decoded : null;
}

$pdo->exec('CREATE TABLE IF NOT EXISTS encoding_repair_backup (table_name VARCHAR(64) NOT NULL, row_id VARCHAR(64) NOT NULL, column_name VARCHAR(64) NOT NULL, old_value LONGBLOB NOT NULL, repaired_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (table_name, row_id, column_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdo->beginTransaction();
try {
    foreach ($columns as $table => $fields) {
        $existing = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($fields as $field) {
            if (!in_array($field, $existing, true)) continue;
            $count = 0;
            $rows = $pdo->query("SELECT id, `$field` AS value FROM `$table` WHERE `$field` IS NOT NULL");
            $backup = $pdo->prepare('INSERT IGNORE INTO encoding_repair_backup (table_name, row_id, column_name, old_value) VALUES (?, ?, ?, ?)');
            $update = $pdo->prepare("UPDATE `$table` SET `$field` = ? WHERE id = ?");
            while ($row = $rows->fetch()) {
                $fixed = repaired((string)$row['value']);
                if ($fixed === null) continue;
                $count++;
                if ($apply) {
                    $backup->execute([$table, $row['id'], $field, $row['value']]);
                    $update->execute([$fixed, $row['id']]);
                }
            }
            if ($count) echo "$table.$field: $count\n";
        }
    }
    if ($apply) { $pdo->commit(); echo "Committed.\n"; }
    else { $pdo->rollBack(); echo "Dry run; no values changed.\n"; }
} catch (Throwable $error) {
    $pdo->rollBack();
    throw $error;
}
