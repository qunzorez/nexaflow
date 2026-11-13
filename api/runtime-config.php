<?php
declare(strict_types=1);

// No host-name guessing: HTTP requests and cron use the same environment.
function appConfig(): array
{
    static $config;
    if (is_array($config)) return $config;
    $file = dirname(__DIR__) . '/appsettings.json';
    $settings = json_decode((string)@file_get_contents($file), true);
    $environment = $settings['environment'] ?? '';
    if (!in_array($environment, ['develop', 'web'], true)) {
        throw new RuntimeException('Invalid or missing appsettings.json environment.');
    }
    $profile = $settings['profiles'][$environment] ?? null;
    if (!is_array($profile) || empty($profile['base_url'])) {
        throw new RuntimeException('Missing application environment profile.');
    }
    if ($environment === 'develop') {
        $credentials = __DIR__ . '/config.local.php';
        if (!is_file($credentials)) {
            throw new RuntimeException('Create api/config.local.php for the local database.');
        }
    } else {
        // Keep compatibility with the existing hosting configuration.
        $credentials = __DIR__ . '/config.web.php';
        if (!is_file($credentials)) $credentials = __DIR__ . '/config.local.php';
        if (!is_file($credentials)) $credentials = __DIR__ . '/config.php';
    }
    $secrets = require $credentials;
    if (!is_array($secrets)) throw new RuntimeException('Invalid database configuration.');
    if ($environment === 'develop' && !in_array($secrets['host'] ?? '', ['localhost', '127.0.0.1', '::1'], true)) {
        throw new RuntimeException('Develop mode requires a local database host.');
    }
    $config = array_merge($secrets, $profile, ['environment' => $environment]);
    if (empty($config['telegram_enabled'])) $config['telegram_bot_token'] = '';
    return $config;
}

function appUrl(string $path = ''): string
{
    return rtrim(appConfig()['base_url'], '/') . '/' . ltrim($path, '/');
}
