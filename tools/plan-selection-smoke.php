<?php
declare(strict_types=1);

require dirname(__DIR__) . '/api/db.php';
require dirname(__DIR__) . '/api/plans.php';

$config = appConfig();
if (($config['environment'] ?? '') !== 'develop' || filter_var($config['billing_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR, "This smoke test only runs in local no-billing mode.\n"); exit(2);
}
$base = rtrim((string)$config['base_url'], '/');
$pdo = db();
ensurePlanSchema($pdo);
$users = [];
$cookies = [];
$checks = 0;

function assertSmoke(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

function httpSmoke(string $url, ?array $json = null, ?string $cookieFile = null): array
{
    $curl = curl_init($url);
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15];
    if ($json !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json', 'Accept: application/json'];
    }
    if ($cookieFile) { $options[CURLOPT_COOKIEJAR] = $cookieFile; $options[CURLOPT_COOKIEFILE] = $cookieFile; }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); $error = curl_error($curl); curl_close($curl);
    if ($body === false) throw new RuntimeException('HTTP error: ' . $error);
    return [$status, (string)$body, json_decode((string)$body, true)];
}

try {
    foreach (['growth', 'team', 'business'] as $plan) {
        $id = bin2hex(random_bytes(16)); $password = bin2hex(random_bytes(12)); $email = 'liqpay-smoke-' . $id . '@nexaflow.local';
        $users[] = $id;
        $pdo->prepare("INSERT INTO users (id,name,email,password_hash,role,requested_role,email_verified_at) VALUES (?,?,?,?,'manager','manager',CURRENT_TIMESTAMP)")
            ->execute([$id, 'Local LiqPay Smoke', $email, password_hash($password, PASSWORD_DEFAULT)]);
        $cookieFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nxf-liqpay-' . $id . '.cookies'; $cookies[] = $cookieFile;
        [$code, , $login] = httpSmoke($base . '/api/login.php', ['email' => $email, 'password' => $password], $cookieFile);
        assertSmoke($code === 200 && ($login['ok'] ?? false) && ($login['user']['role'] ?? '') === 'manager', "$plan: manager login failed");
        [$code, , $selection] = httpSmoke($base . '/api/liqpay.php', ['action' => 'select_plan', 'plan' => $plan], $cookieFile);
        assertSmoke($code === 200 && ($selection['ok'] ?? false) && ($selection['plan_selected'] ?? false), "$plan: no-charge plan selection failed");
        [$code, , $profile] = httpSmoke($base . '/api/profile.php', null, $cookieFile);
        assertSmoke($code === 200 && ($profile['subscription']['key'] ?? '') === $plan && ($profile['subscription']['billing_enabled'] ?? true) === false && empty($profile['subscription']['billing']), "$plan: selected plan is not active without billing");
        $subQuery = $pdo->prepare('SELECT COUNT(*) FROM plan_subscriptions WHERE owner_id = ?'); $subQuery->execute([$id]);
        assertSmoke((int)$subQuery->fetchColumn() === 0, "$plan: no-charge selection unexpectedly created a payment subscription");
        echo strtoupper($plan) . ": selected and activated with no payment or subscription record\n";
    }
    $id = bin2hex(random_bytes(16)); $password = bin2hex(random_bytes(12)); $email = 'liqpay-smoke-user-' . $id . '@nexaflow.local';
    $users[] = $id;
    $pdo->prepare("INSERT INTO users (id,name,email,password_hash,role,requested_role,email_verified_at) VALUES (?,?,?,?,'user','user',CURRENT_TIMESTAMP)")
        ->execute([$id, 'Local LiqPay Smoke', $email, password_hash($password, PASSWORD_DEFAULT)]);
    $cookieFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nxf-liqpay-' . $id . '.cookies'; $cookies[] = $cookieFile;
    httpSmoke($base . '/api/login.php', ['email' => $email, 'password' => $password], $cookieFile);
    [$code, , $denied] = httpSmoke($base . '/api/liqpay.php', ['plan' => 'growth'], $cookieFile);
    assertSmoke($code === 403 && ($denied['ok'] ?? true) === false, 'non-manager was allowed to buy a workspace plan');
    [$code, , $denied] = httpSmoke($base . '/api/liqpay.php', ['action' => 'select_plan', 'plan' => 'growth'], $cookieFile);
    assertSmoke($code === 403 && ($denied['ok'] ?? true) === false, 'non-manager was allowed to select a workspace plan');
    echo "PASS: $checks no-charge plan selection assertions\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    $exit = 1;
} finally {
    if ($users) { $marks = implode(',', array_fill(0, count($users), '?')); $pdo->prepare("DELETE FROM plan_subscriptions WHERE owner_id IN ($marks)")->execute($users); $pdo->prepare("DELETE FROM users WHERE id IN ($marks)")->execute($users); }
    foreach ($cookies as $cookieFile) if (is_file($cookieFile)) @unlink($cookieFile);
}
if (isset($exit)) exit($exit);
