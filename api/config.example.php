<?php
declare(strict_types=1);

// Copy this file to config.web.php on the hosting server and fill in real values.
// Never commit a real password or bot token.
return [
    'host' => 'localhost',
    'port' => 3306,
    'database' => 'nexaflow_srm',
    'username' => 'nexaflow_srmuser',
    'password' => 'CHANGE_ME_DB_PASSWORD',
    'charset' => 'utf8mb4',
    'telegram_bot_token' => '',
    'mail_transport' => 'mail',
];
