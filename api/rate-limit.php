<?php
declare(strict_types=1);

/** Fixed-window limiter with atomic counters; only trusts REMOTE_ADDR. */
function enforcePublicRateLimit(PDO $pdo, string $action, string $email, int $ipLimit, int $emailLimit, int $windowSeconds): void
{
    static $schemaReady = false;
    if (!$schemaReady) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS api_rate_limits (
            bucket_hash CHAR(64) NOT NULL PRIMARY KEY,
            request_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            INDEX idx_api_rate_limits_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $schemaReady = true;
    }

    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $now = time();
    $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
    $expiresAt = $windowStart + $windowSeconds;
    $upsert = $pdo->prepare('INSERT INTO api_rate_limits (bucket_hash, request_count, expires_at) VALUES (?, 1, FROM_UNIXTIME(?)) ON DUPLICATE KEY UPDATE request_count = request_count + 1');
    $readCount = $pdo->prepare('SELECT request_count FROM api_rate_limits WHERE bucket_hash = ? LIMIT 1');
    $buckets = [['key' => hash('sha256', $action . '|ip|' . $ip . '|' . $windowStart), 'limit' => $ipLimit]];
    if ($email !== '') $buckets[] = ['key' => hash('sha256', $action . '|email|' . $ip . '|' . strtolower(trim($email)) . '|' . $windowStart), 'limit' => $emailLimit];

    foreach ($buckets as $bucket) {
        $upsert->execute([$bucket['key'], $expiresAt]);
        $readCount->execute([$bucket['key']]);
        if ((int)$readCount->fetchColumn() > $bucket['limit']) {
            $retryAfter = max(1, $windowStart + $windowSeconds - $now);
            header('Retry-After: ' . $retryAfter);
            jsonResponse(false, 'Забагато спроб. Зачекайте ' . $retryAfter . ' с і спробуйте ще раз.', ['retry_after' => $retryAfter], 429);
        }
    }

    if (random_int(1, 100) === 1) $pdo->exec('DELETE FROM api_rate_limits WHERE expires_at < NOW()');
}
