<?php
declare(strict_types=1);
require_once __DIR__ . '/runtime-config.php';

function sendProjectEmail(string $to, string $subject, string $message, string $html = ''): bool
{
    $from = 'no-reply@nexaflow.top';
    $config = appConfig();
    $mailTransport = (string)($config['mail_transport'] ?? 'mail');
    if ($mailTransport === 'mailpit') {
        $socket = @fsockopen($config['mail_host'] ?? '127.0.0.1', (int)($config['mail_port'] ?? 1025), $errorCode, $errorMessage, 3);
        if (!$socket) return false;
        $read = static function () use ($socket): string { return (string)fgets($socket, 2048); };
        $write = static function (string $command) use ($socket): void { fwrite($socket, $command . "\r\n"); };
        $read();
        $write('EHLO localhost'); $read();
        $write('MAIL FROM:<' . $from . '>'); $read();
        $write('RCPT TO:<' . $to . '>'); $read();
        $write('DATA'); $read();
        $headers = 'From: NexaFlow <' . $from . ">\r\n" . 'To: <' . $to . ">\r\n" . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n";
        if ($html !== '') {
            $boundary = 'nexaflow_' . bin2hex(random_bytes(8));
            $headers .= "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
            $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($message), 76, "\r\n") . "\r\n--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html), 76, "\r\n") . "\r\n--{$boundary}--\r\n";
        } else {
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
            $body = chunk_split(base64_encode($message), 76, "\r\n");
        }
        $body = preg_replace('/\r?\n/', "\r\n", $body) ?? $body;
        $body = preg_replace('/^\./m', '..', $body) ?? $body;
        fwrite($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
        $response = $read();
        $write('QUIT');
        fclose($socket);
        return substr(ltrim($response), 0, 3) === '250';
    }
    if ($html !== '') {
        $boundary = 'nexaflow_' . bin2hex(random_bytes(8));
        // mail() generates the recipient header itself from its first argument.
        // Adding another To header makes Gmail reject the message as malformed.
        $headers = "From: NexaFlow <{$from}>\r\nReply-To: {$from}\r\nDate: " . date('r') . "\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($message), 76, "\r\n") . "\r\n--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html), 76, "\r\n") . "\r\n--{$boundary}--\r\n";
    } else {
        $headers = "From: NexaFlow <{$from}>\r\nReply-To: {$from}\r\nDate: " . date('r') . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
        $body = chunk_split(base64_encode($message), 76, "\r\n");
    }
    $sent = mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    if (!$sent) {
        $lastError = error_get_last();
        error_log('NexaFlow email failed: to=' . $to . '; subject=' . $subject . '; error=' . (string)($lastError['message'] ?? 'mail() returned false'));
    }
    return $sent;
}

function sendUserNotificationEmail(PDO $pdo, string $userId, string $to, string $subject, string $message, string $html = ''): bool
{
    static $columnChecked = [];
    $connectionKey = spl_object_id($pdo);
    if (!isset($columnChecked[$connectionKey])) {
        $columnQuery = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_notifications_enabled'");
        if (!$columnQuery->fetchColumn()) {
            $pdo->exec("ALTER TABLE users ADD email_notifications_enabled TINYINT(1) NOT NULL DEFAULT 0");
        }
        $columnChecked[$connectionKey] = true;
    }
    // The user ID is authoritative. Re-read the address immediately before
    // sending so a stale email from a previous query cannot receive the mail.
    $preference = $pdo->prepare('SELECT email, email_notifications_enabled FROM users WHERE id = ? LIMIT 1');
    $preference->execute([$userId]);
    $recipient = $preference->fetch(PDO::FETCH_ASSOC);
    if (!$recipient || (int)$recipient['email_notifications_enabled'] !== 1) return false;
    $recipientEmail = strtolower(trim((string)$recipient['email']));
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) return false;
    return sendProjectEmail($recipientEmail, $subject, $message, $html);
}
