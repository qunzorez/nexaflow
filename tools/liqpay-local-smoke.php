<?php
declare(strict_types=1);

require dirname(__DIR__) . '/api/db.php';
require dirname(__DIR__) . '/api/plans.php';

$config = appConfig();
if (($config['environment'] ?? '') !== 'develop' || !str_starts_with((string)($config['liqpay_public_key'] ?? ''), 'sandbox_')) {
    fwrite(STDERR, "Refusing to run outside local sandbox.\n"); exit(2);
}
$base = rtrim((string)$config['base_url'], '/');
$pdo = db();
ensurePlanSchema($pdo);
$private = (string)$config['liqpay_private_key'];
$public = (string)$config['liqpay_public_key'];
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

function httpFormSmoke(string $url, array $form): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form), CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']]);
    $body = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); $error = curl_error($curl); curl_close($curl);
    if ($body === false) throw new RuntimeException('HTTP callback error: ' . $error);
    return [$status, (string)$body, json_decode((string)$body, true)];
}

function gatewayCheckoutSmoke(string $data, string $signature): array
{
    $curl = curl_init('https://www.liqpay.ua/api/3/checkout');
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['data' => $data, 'signature' => $signature]), CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']]);
    $body = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); $error = curl_error($curl); curl_close($curl);
    if ($body === false) throw new RuntimeException('LiqPay gateway connection failed: ' . $error);
    return [$status, (string)$body];
}

try {
    foreach (['growth' => '7.99', 'team' => '14.99', 'business' => '24.99'] as $plan => $amount) {
        $id = bin2hex(random_bytes(16)); $password = bin2hex(random_bytes(12)); $email = 'liqpay-smoke-' . $id . '@nexaflow.local';
        $users[] = $id;
        $pdo->prepare("INSERT INTO users (id,name,email,password_hash,role,requested_role,email_verified_at) VALUES (?,?,?,?,'manager','manager',CURRENT_TIMESTAMP)")
            ->execute([$id, 'Local LiqPay Smoke', $email, password_hash($password, PASSWORD_DEFAULT)]);
        $cookieFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nxf-liqpay-' . $id . '.cookies'; $cookies[] = $cookieFile;
        [$code, , $login] = httpSmoke($base . '/api/login.php', ['email' => $email, 'password' => $password], $cookieFile);
        assertSmoke($code === 200 && ($login['ok'] ?? false) && ($login['user']['role'] ?? '') === 'manager', "$plan: manager login failed");
        [$code, , $verification] = httpSmoke($base . '/api/liqpay.php', ['action' => 'verify'], $cookieFile);
        assertSmoke($code === 200 && ($verification['ok'] ?? false) && ($verification['status'] ?? '') === 'none', "$plan: payment verification action rejected when plan was omitted");

        [$code, , $checkout] = httpSmoke($base . '/api/liqpay.php', ['plan' => $plan], $cookieFile);
        assertSmoke($code === 200 && ($checkout['ok'] ?? false), "$plan: checkout creation failed");
        $checkoutData = json_decode((string)base64_decode((string)$checkout['checkout']['data'], true), true);
        assertSmoke(is_array($checkoutData) && ($checkoutData['amount'] ?? '') === $amount && ($checkoutData['currency'] ?? '') === 'USD' && ($checkoutData['action'] ?? '') === 'subscribe' && ($checkoutData['subscribe_periodicity'] ?? '') === 'month', "$plan: checkout parameters mismatch");
        assertSmoke(hash_equals(base64_encode(hash('sha3-256', $private . $checkout['checkout']['data'] . $private, true)), (string)$checkout['checkout']['signature']), "$plan: checkout signature invalid");
        [$gatewayCode, $gatewayBody] = gatewayCheckoutSmoke((string)$checkout['checkout']['data'], (string)$checkout['checkout']['signature']);
        assertSmoke($gatewayCode >= 200 && $gatewayCode < 400 && stripos($gatewayBody, 'invalid signature') === false, "$plan: LiqPay rejected the generated checkout request (HTTP $gatewayCode)");
        $orderId = (string)$checkoutData['order_id'];

        $callback = ['public_key' => $public, 'version' => 7, 'action' => 'subscribe', 'status' => 'sandbox', 'order_id' => $orderId, 'liqpay_order_id' => 'sandbox-' . $orderId, 'payment_id' => 'smoke-' . $orderId, 'customer' => $id, 'amount' => $amount, 'currency' => 'USD'];
        $callbackData = base64_encode((string)json_encode($callback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        [$code] = httpFormSmoke($base . '/api/liqpay.php?action=callback', ['data' => $callbackData, 'signature' => 'invalid-signature']);
        assertSmoke($code === 400, "$plan: invalid callback signature was not rejected");

        $tampered = $callback; $tampered['amount'] = number_format((float)$amount + 1, 2, '.', '');
        $tamperedData = base64_encode((string)json_encode($tampered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $tamperedSignature = base64_encode(hash('sha3-256', $private . $tamperedData . $private, true));
        [$code] = httpFormSmoke($base . '/api/liqpay.php?action=callback', ['data' => $tamperedData, 'signature' => $tamperedSignature]);
        assertSmoke($code === 400, "$plan: wrong callback amount was not rejected");
        $pending = $pdo->prepare('SELECT status FROM plan_subscriptions WHERE order_id = ?'); $pending->execute([$orderId]);
        assertSmoke($pending->fetchColumn() === 'pending', "$plan: rejected callback changed plan state");

        $signature = base64_encode(hash('sha3-256', $private . $callbackData . $private, true));
        [$code, $callbackBody] = httpFormSmoke($base . '/api/liqpay.php?action=callback', ['data' => $callbackData, 'signature' => $signature]);
        assertSmoke($code === 200 && trim($callbackBody) === 'OK', "$plan: valid sandbox callback failed");
        $periodQuery = $pdo->prepare('SELECT current_period_end FROM plan_subscriptions WHERE order_id = ?'); $periodQuery->execute([$orderId]); $periodEnd = $periodQuery->fetchColumn();
        [$code, $callbackBody] = httpFormSmoke($base . '/api/liqpay.php?action=callback', ['data' => $callbackData, 'signature' => $signature]);
        $periodQuery->execute([$orderId]);
        assertSmoke($code === 200 && trim($callbackBody) === 'OK' && $periodQuery->fetchColumn() === $periodEnd, "$plan: duplicate callback changed subscription period");
        [$code, , $profile] = httpSmoke($base . '/api/profile.php', null, $cookieFile);
        assertSmoke($code === 200 && ($profile['subscription']['key'] ?? '') === $plan && ($profile['subscription']['billing']['status'] ?? '') === 'active', "$plan: paid tier did not activate");
        echo strtoupper($plan) . ": LiqPay sandbox checkout accepted, signature checks, callback, active plan OK\n";
    }
    $id = bin2hex(random_bytes(16)); $password = bin2hex(random_bytes(12)); $email = 'liqpay-smoke-user-' . $id . '@nexaflow.local';
    $users[] = $id;
    $pdo->prepare("INSERT INTO users (id,name,email,password_hash,role,requested_role,email_verified_at) VALUES (?,?,?,?,'user','user',CURRENT_TIMESTAMP)")
        ->execute([$id, 'Local LiqPay Smoke', $email, password_hash($password, PASSWORD_DEFAULT)]);
    $cookieFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nxf-liqpay-' . $id . '.cookies'; $cookies[] = $cookieFile;
    httpSmoke($base . '/api/login.php', ['email' => $email, 'password' => $password], $cookieFile);
    [$code, , $denied] = httpSmoke($base . '/api/liqpay.php', ['plan' => 'growth'], $cookieFile);
    assertSmoke($code === 403 && ($denied['ok'] ?? true) === false, 'non-manager was allowed to buy a workspace plan');
    [$code, , $denied] = httpSmoke($base . '/api/liqpay.php', ['action' => 'verify'], $cookieFile);
    assertSmoke($code === 403 && ($denied['ok'] ?? true) === false, 'non-manager was allowed to verify workspace billing');
    echo "PASS: $checks assertions\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    $exit = 1;
} finally {
    if ($users) { $marks = implode(',', array_fill(0, count($users), '?')); $pdo->prepare("DELETE FROM plan_subscriptions WHERE owner_id IN ($marks)")->execute($users); $pdo->prepare("DELETE FROM users WHERE id IN ($marks)")->execute($users); }
    foreach ($cookies as $cookieFile) if (is_file($cookieFile)) @unlink($cookieFile);
}
if (isset($exit)) exit($exit);
