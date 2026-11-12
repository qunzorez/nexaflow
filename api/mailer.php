<?php
declare(strict_types=1);

function sendProjectEmail(string $to, string $subject, string $message): bool
{
    $from = 'no-reply@nexaflow.top';
    if (file_exists(__DIR__ . '/config.local.php')) {
        $socket = @fsockopen('127.0.0.1', 1025, $errorCode, $errorMessage, 3);
        if (!$socket) return false;
        $read = static function () use ($socket): string { return (string)fgets($socket, 2048); };
        $write = static function (string $command) use ($socket): void { fwrite($socket, $command . "\r\n"); };
        $read();
        $write('EHLO localhost'); $read();
        $write('MAIL FROM:<' . $from . '>'); $read();
        $write('RCPT TO:<' . $to . '>'); $read();
        $write('DATA'); $read();
        $headers = 'From: NexaFlow <' . $from . ">\r\n" . 'To: <' . $to . ">\r\n" . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n" . "Content-Type: text/plain; charset=UTF-8\r\n" . "Content-Transfer-Encoding: 8bit\r\n";
        $body = preg_replace('/\r?\n/', "\r\n", $message) ?? $message;
        $body = preg_replace('/^\./m', '..', $body) ?? $body;
        fwrite($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
        $response = $read();
        $write('QUIT');
        fclose($socket);
        return str_starts_with(trim($response), '250');
    }
    $headers = "From: NexaFlow <{$from}>\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $message, $headers, '-f' . $from);
}
