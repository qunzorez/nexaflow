<?php
declare(strict_types=1);

function sendProjectEmail(string $to, string $subject, string $message, string $html = ''): bool
{
    $from = 'no-reply@nexaflow.top';
    $configFile = __DIR__ . '/config.local.php';
    $config = require file_exists($configFile) ? $configFile : (__DIR__ . '/config.php');
    $mailTransport = (string)($config['mail_transport'] ?? 'mail');
    if ($mailTransport === 'mailpit') {
        $socket = @fsockopen('127.0.0.1', 1025, $errorCode, $errorMessage, 3);
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
            $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$message}\r\n--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n--{$boundary}--\r\n";
        } else {
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
            $body = $message;
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
        $headers = "From: NexaFlow <{$from}>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$message}\r\n--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n--{$boundary}--\r\n";
    } else {
        $headers = "From: NexaFlow <{$from}>\r\nContent-Type: text/plain; charset=UTF-8\r\n";
        $body = $message;
    }
    return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, '-f' . $from);
}
