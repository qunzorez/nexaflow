<?php
declare(strict_types=1);

// Run only against the deployed document root, never the source checkout.
if (PHP_SAPI !== 'cli' || empty($argv[1])) exit(1);
$file = rtrim($argv[1], '/\\') . '/appsettings.json';
$settings = json_decode((string)file_get_contents($file), true);
if (!is_array($settings) || !isset($settings['profiles']['web'])) exit(2);
$settings['environment'] = 'web';
if (file_put_contents($file, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) exit(3);
echo "Deployment environment: web\n";
