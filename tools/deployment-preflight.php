<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || empty($argv[1])) {
    fwrite(STDERR, "Run this preflight from CLI and pass the deployed document root.\n");
    exit(1);
}

if (PHP_VERSION_ID < 80000) {
    fwrite(STDERR, "NexaFlow production requires PHP 8.0 or newer.\n");
    exit(2);
}

// fileinfo is used by web uploads and is enabled for the site's PHP Selector profile,
// but cPanel's standalone CLI binaries do not load that profile.
foreach (['pdo_mysql', 'curl', 'mbstring'] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "Production PHP is missing the required extension: {$extension}.\n");
        exit(3);
    }
}

$documentRoot = rtrim($argv[1], '/\\');
$settingsPath = dirname(__DIR__) . '/appsettings.json';
$settings = json_decode((string)@file_get_contents($settingsPath), true);
$webProfile = $settings['profiles']['web'] ?? null;
if (!is_array($webProfile) || ($webProfile['base_url'] ?? '') !== 'https://nexaflow.top') {
    fwrite(STDERR, "The deployed appsettings.json must contain the NexaFlow web profile.\n");
    exit(4);
}

$taxCalendarPath = dirname(__DIR__) . '/api/data/vchasno-tax-events-2026.json';
$taxCalendar = json_decode((string)@file_get_contents($taxCalendarPath), true);
if (!is_array($taxCalendar) || !is_array($taxCalendar['groups'] ?? null) || empty($taxCalendar['source'])) {
    fwrite(STDERR, "The release is missing a valid Vchasno tax calendar catalog.\n");
    exit(11);
}

$runtimeSchemas = [
    'dentistry_care.sql',
    'dentistry_patient_ledger.sql',
    'dentistry_erp.sql',
    'dentistry_erp_operations.sql',
];
foreach ($runtimeSchemas as $schemaFile) {
    if (!is_file(dirname(__DIR__) . '/database/' . $schemaFile)) {
        fwrite(STDERR, "The release is missing a runtime schema file: {$schemaFile}.\n");
        exit(10);
    }
}

$configFile = null;
foreach (['config.web.php', 'config.local.php', 'config.php'] as $candidate) {
    $path = $documentRoot . '/api/' . $candidate;
    if (is_file($path)) {
        $configFile = $path;
        break;
    }
}
if ($configFile === null) {
    fwrite(STDERR, "No server-side API config found. Create api/config.web.php before deploying.\n");
    exit(5);
}

$config = require $configFile;
if (!is_array($config)) {
    fwrite(STDERR, "The server-side API config must return an array.\n");
    exit(6);
}
foreach (['host', 'database', 'username', 'password'] as $key) {
    if (!isset($config[$key]) || trim((string)$config[$key]) === '' || str_contains((string)$config[$key], 'CHANGE_ME')) {
        fwrite(STDERR, "The server-side API config is missing a valid {$key}.\n");
        exit(7);
    }
}

try {
    $port = (int)($config['port'] ?? 3306);
    $charset = (string)($config['charset'] ?? 'utf8mb4');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $config['host'], $port, $config['database'], $charset);
    $pdo = new PDO($dsn, (string)$config['username'], (string)$config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->query('SELECT 1');
    $requiredTables = ['users', 'projects', 'tasks', 'clients', 'notifications', 'api_rate_limits'];
    $placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
    $tableQuery = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ({$placeholders})");
    $tableQuery->execute(array_merge([(string)$config['database']], $requiredTables));
    $availableTables = $tableQuery->fetchAll(PDO::FETCH_COLUMN);
    $missingTables = array_values(array_diff($requiredTables, $availableTables));
    if ($missingTables) {
        fwrite(STDERR, "Production database is missing required tables: " . implode(', ', $missingTables) . ". Apply database/live_migration.sql before deploying.\n");
        exit(9);
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Production database connection failed; no site files were copied. Check the server config and database availability.\n");
    exit(8);
}

echo "Production preflight passed: PHP extensions, web profile, server config, database connection, and core schema are ready.\n";
