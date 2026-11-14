<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/plans.php';

function liqpayCredentials(): array
{
    $config = appConfig();
    $public = trim((string)($config['liqpay_public_key'] ?? ''));
    $private = trim((string)($config['liqpay_private_key'] ?? ''));
    if ($public === '' || $private === '') jsonResponse(false, 'Оплату ще не налаштовано.', [], 503);
    return [$public, $private];
}

function liqpaySignature(string $data, string $privateKey): string
{
    return base64_encode(hash('sha3-256', $privateKey . $data . $privateKey, true));
}

function liqpayApiRequest(array $params, string $privateKey): array
{
    $encoded = base64_encode((string)json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $curl = curl_init('https://www.liqpay.ua/api/request');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['data' => $encoded, 'signature' => liqpaySignature($encoded, $privateKey)]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $response = curl_exec($curl);
    $statusCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    $result = is_string($response) ? json_decode($response, true) : null;
    if ($curlError !== '' || $statusCode < 200 || $statusCode >= 300 || !is_array($result)) {
        error_log('LiqPay API request failed: ' . ($curlError ?: 'HTTP ' . $statusCode));
        throw new RuntimeException('Не вдалося зв’язатися з LiqPay. Спробуйте ще раз.');
    }
    return $result;
}

function liqpayFinishCallback(PDO $pdo, array $payload, string $publicKey): void
{
    $orderId = trim((string)($payload['order_id'] ?? ''));
    if ($orderId === '' || ($payload['public_key'] ?? '') !== $publicKey) jsonResponse(false, 'Некоректні дані LiqPay.', [], 400);
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT * FROM plan_subscriptions WHERE order_id = ? FOR UPDATE');
        $query->execute([$orderId]);
        $subscription = $query->fetch();
        if (!$subscription && ($payload['action'] ?? '') === 'regular' && !empty($payload['customer'])) {
            $query = $pdo->prepare("SELECT * FROM plan_subscriptions WHERE owner_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT 1 FOR UPDATE");
            $query->execute([(string)$payload['customer']]);
            $subscription = $query->fetch();
        }
        if (!$subscription) { $pdo->rollBack(); jsonResponse(false, 'Замовлення не знайдено.', [], 404); }
        if (($payload['currency'] ?? '') !== $subscription['currency'] || !is_numeric($payload['amount'] ?? null) || abs((float)$payload['amount'] - (float)$subscription['amount']) > 0.001) {
            $pdo->rollBack(); jsonResponse(false, 'Сума або валюта платежу не збігається із замовленням.', [], 400);
        }
        $status = (string)($payload['status'] ?? '');
        $paymentId = trim((string)($payload['payment_id'] ?? ''));
        if (in_array($status, ['sandbox', 'success', 'subscribed'], true) && $paymentId === '') {
            $pdo->rollBack(); jsonResponse(false, 'У callback відсутній ідентифікатор платежу.', [], 400);
        }
        if ($subscription['status'] === 'cancelled') { $pdo->commit(); http_response_code(200); echo 'OK'; exit; }
        if ($paymentId !== '' && $paymentId === (string)($subscription['liqpay_payment_id'] ?? '')) { $pdo->commit(); http_response_code(200); echo 'OK'; exit; }
        if (in_array($status, ['sandbox', 'success', 'subscribed'], true)) {
            $isRenewal = ($payload['action'] ?? '') === 'regular' || $subscription['status'] === 'active';
            if ($isRenewal) {
                $pdo->prepare("UPDATE plan_subscriptions SET liqpay_payment_id = ?, current_period_end = DATE_ADD(GREATEST(COALESCE(current_period_end, CURRENT_TIMESTAMP), CURRENT_TIMESTAMP), INTERVAL 1 MONTH), status = 'active' WHERE id = ?")
                    ->execute([$paymentId ?: null, $subscription['id']]);
            } elseif ($subscription['status'] === 'pending') {
            $pdo->prepare("UPDATE plan_subscriptions SET status = 'active', liqpay_payment_id = ?, liqpay_subscription_id = ?, current_period_end = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 1 MONTH) WHERE id = ?")
                    ->execute([$paymentId, (string)($payload['liqpay_order_id'] ?? ''), $subscription['id']]);
            }
            $pdo->prepare('UPDATE users SET plan_key = ? WHERE id = ?')->execute([$subscription['plan_key'], $subscription['owner_id']]);
        } elseif (in_array($status, ['error', 'failure', 'reversed'], true) && $subscription['status'] === 'pending') {
            $pdo->prepare("UPDATE plan_subscriptions SET status = 'failed', liqpay_payment_id = ? WHERE id = ?")->execute([$paymentId ?: null, $subscription['id']]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('LiqPay callback failed: ' . $error->getMessage());
        jsonResponse(false, 'Не вдалося обробити callback.', [], 500);
    }
    http_response_code(200);
    echo 'OK';
}

$action = (string)($_GET['action'] ?? '');
if ($action === 'callback') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonResponse(false, 'Метод не підтримується.', [], 405);
    if (!filter_var(appConfig()['billing_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) { http_response_code(200); echo 'OK'; exit; }
    [$publicKey, $privateKey] = liqpayCredentials();
    $data = (string)($_POST['data'] ?? '');
    $signature = (string)($_POST['signature'] ?? '');
    if ($data === '' || $signature === '' || !hash_equals(liqpaySignature($data, $privateKey), $signature)) jsonResponse(false, 'Підпис LiqPay недійсний.', [], 400);
    $decoded = base64_decode($data, true);
    $payload = $decoded === false ? null : json_decode($decoded, true);
    if (!is_array($payload)) jsonResponse(false, 'Некоректне повідомлення LiqPay.', [], 400);
    liqpayFinishCallback(db(), $payload, $publicKey);
    exit;
}

session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonResponse(false, 'Метод не підтримується.', [], 405);
$data = body();
$requestAction = (string)($data['action'] ?? '');
$planKey = trim((string)($data['plan'] ?? ''));
$plans = planDefinitions();
if (in_array($requestAction, ['', 'select_plan'], true) && (!isset($plans[$planKey]) || $planKey === 'start')) jsonResponse(false, 'Оберіть платний тариф.', [], 422);
$pdo = db();
$userQuery = $pdo->prepare('SELECT id, role, email FROM users WHERE id = ? LIMIT 1');
$userQuery->execute([(string)$_SESSION['user_id']]);
$user = $userQuery->fetch();
if (!$user || !in_array($user['role'], ['manager'], true)) jsonResponse(false, 'Тариф робочого простору може змінювати лише менеджер.', [], 403);
ensurePlanSchema($pdo);
$billingEnabled = filter_var(appConfig()['billing_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
if (in_array($requestAction, ['', 'select_plan'], true) && !$billingEnabled) {
    $pdo->prepare('UPDATE users SET plan_key = ? WHERE id = ?')->execute([$planKey, (string)$user['id']]);
    jsonResponse(true, 'План «' . $plans[$planKey]['name'] . '» підключено. Оплату наразі вимкнено, кошти не списуються.', ['plan_selected' => true, 'plan' => $planKey]);
}
if ($requestAction === 'select_plan') $requestAction = '';
if (in_array($requestAction, ['verify', 'cancel'], true) && !$billingEnabled) jsonResponse(false, 'Онлайн-оплата наразі вимкнена. Оберіть тариф без списання коштів.', ['code' => 'billing_disabled'], 409);
[$publicKey, $privateKey] = liqpayCredentials();
if ($requestAction === 'verify') {
    $pendingQuery = $pdo->prepare("SELECT * FROM plan_subscriptions WHERE owner_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1");
    $pendingQuery->execute([(string)$user['id']]);
    $pending = $pendingQuery->fetch();
    if (!$pending) jsonResponse(true, 'Немає платежу, що очікує підтвердження.', ['status' => 'none']);
    try {
        $payment = liqpayApiRequest(['public_key' => $publicKey, 'version' => 7, 'action' => 'status', 'order_id' => $pending['order_id']], $privateKey);
    } catch (Throwable $error) {
        error_log('LiqPay status verification failed: ' . $error->getMessage());
        jsonResponse(false, 'Не вдалося перевірити платіж у LiqPay. Спробуйте оновити сторінку трохи пізніше.', ['status' => 'pending'], 502);
    }
    if (($payment['public_key'] ?? '') !== $publicKey || ($payment['order_id'] ?? '') !== $pending['order_id'] || ($payment['currency'] ?? '') !== $pending['currency'] || !is_numeric($payment['amount'] ?? null) || abs((float)$payment['amount'] - (float)$pending['amount']) > 0.001) {
        error_log('LiqPay status response did not match pending order.');
        jsonResponse(false, 'Відповідь LiqPay не збігається із замовленням.', ['status' => 'pending'], 502);
    }
    $paymentStatus = (string)($payment['status'] ?? '');
    $paymentId = trim((string)($payment['payment_id'] ?? ''));
    if (in_array($paymentStatus, ['sandbox', 'success', 'subscribed'], true) && $paymentId !== '') {
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT status, plan_key FROM plan_subscriptions WHERE id = ? AND owner_id = ? FOR UPDATE');
            $lock->execute([$pending['id'], (string)$user['id']]);
            $lockedOrder = $lock->fetch();
            if ($lockedOrder && $lockedOrder['status'] === 'pending') {
                $pdo->prepare("UPDATE plan_subscriptions SET status = 'active', liqpay_payment_id = ?, liqpay_subscription_id = ?, current_period_end = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 1 MONTH) WHERE id = ?")
                    ->execute([$paymentId, (string)($payment['liqpay_order_id'] ?? ''), $pending['id']]);
                $pdo->prepare('UPDATE users SET plan_key = ? WHERE id = ?')->execute([$lockedOrder['plan_key'], (string)$user['id']]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('LiqPay verified payment activation failed: ' . $error->getMessage());
            jsonResponse(false, 'Платіж підтверджений, але тариф не вдалося активувати. Зверніться до підтримки.', ['status' => 'pending'], 500);
        }
        jsonResponse(true, 'Платіж підтверджено. Тариф активовано.', ['status' => 'active']);
    }
    if (in_array($paymentStatus, ['error', 'failure', 'reversed', 'try_again'], true)) {
        $pdo->prepare("UPDATE plan_subscriptions SET status = 'failed', liqpay_payment_id = ? WHERE id = ? AND owner_id = ? AND status = 'pending'")->execute([$paymentId ?: null, $pending['id'], (string)$user['id']]);
        jsonResponse(true, 'LiqPay повідомив, що платіж не пройшов.', ['status' => 'failed']);
    }
    if (in_array($paymentStatus, ['p24_verify', 'otp_verify', '3ds_verify', 'cvv_verify', 'phone_verify', 'pin_verify', 'senderapp_verify'], true)) {
        jsonResponse(true, 'Платіж очікує підтвердження у LiqPay або Privat24. Завершіть підтвердження, потім натисніть «Перевірити оплату».', ['status' => 'pending', 'gateway_status' => $paymentStatus]);
    }
    jsonResponse(true, 'LiqPay ще обробляє платіж. Оновіть сторінку трохи пізніше.', ['status' => 'pending', 'gateway_status' => $paymentStatus]);
}
if ($requestAction === 'cancel') {
    $currentQuery = $pdo->prepare("SELECT * FROM plan_subscriptions WHERE owner_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT 1");
    $currentQuery->execute([(string)$user['id']]);
    $current = $currentQuery->fetch();
    if (!$current) jsonResponse(false, 'Активну підписку не знайдено.', [], 404);
    try {
        $result = liqpayApiRequest(['public_key' => $publicKey, 'version' => 7, 'action' => 'unsubscribe', 'order_id' => $current['order_id']], $privateKey);
        if (($result['status'] ?? '') !== 'unsubscribed' && ($result['result'] ?? '') !== 'ok') jsonResponse(false, 'LiqPay не підтвердив скасування підписки.', ['gateway_status' => (string)($result['status'] ?? 'unknown')], 502);
        $pdo->prepare("UPDATE plan_subscriptions SET status = 'cancelled' WHERE id = ? AND status = 'active'")->execute([$current['id']]);
        jsonResponse(true, 'Автоматичне продовження вимкнено. Доступ збережеться до кінця оплаченого періоду.');
    } catch (Throwable $error) {
        error_log('LiqPay cancellation failed: ' . $error->getMessage());
        jsonResponse(false, $error->getMessage(), [], 502);
    }
}
if ($requestAction !== '') jsonResponse(false, 'Невідома дія.', [], 422);
$amount = (string)$plans[$planKey]['amount'];
$currentQuery = $pdo->prepare("SELECT * FROM plan_subscriptions WHERE owner_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT 1");
$currentQuery->execute([(string)$user['id']]);
$current = $currentQuery->fetch();
if ($current) {
    if ($current['plan_key'] === $planKey) jsonResponse(false, 'Цей тариф уже активний.', ['code' => 'plan_already_active'], 409);
    try {
        $cancelResult = liqpayApiRequest(['public_key' => $publicKey, 'version' => 7, 'action' => 'unsubscribe', 'order_id' => $current['order_id']], $privateKey);
        if (($cancelResult['status'] ?? '') !== 'unsubscribed' && ($cancelResult['result'] ?? '') !== 'ok') jsonResponse(false, 'Не вдалося зупинити попередню підписку. Новий тариф не виставлено.', ['gateway_status' => (string)($cancelResult['status'] ?? 'unknown')], 502);
        $pdo->prepare("UPDATE plan_subscriptions SET status = 'cancelled' WHERE id = ? AND status = 'active'")->execute([$current['id']]);
    } catch (Throwable $error) {
        error_log('LiqPay plan replacement failed: ' . $error->getMessage());
        jsonResponse(false, $error->getMessage(), [], 502);
    }
}
$orderId = 'nxf_' . bin2hex(random_bytes(20));
$subscriptionId = bin2hex(random_bytes(16));
$pdo->prepare("INSERT INTO plan_subscriptions (id, owner_id, plan_key, order_id, amount, currency, status) VALUES (?, ?, ?, ?, ?, 'USD', 'pending')")
    ->execute([$subscriptionId, (string)$user['id'], $planKey, $orderId, $amount]);
$baseUrl = rtrim((string)appConfig()['base_url'], '/');
$params = [
    'public_key' => $publicKey,
    'version' => 7,
    'action' => 'subscribe',
    'amount' => $amount,
    'currency' => 'USD',
    'description' => 'NexaFlow — тариф «' . $plans[$planKey]['name'] . '», щомісячна підписка',
    'order_id' => $orderId,
    'subscribe' => '1',
    'subscribe_date_start' => gmdate('Y-m-d H:i:s', time() - 60),
    'subscribe_periodicity' => 'month',
    'server_url' => $baseUrl . '/api/liqpay.php?action=callback',
    'result_url' => $baseUrl . '/settings?payment=return',
    'customer' => (string)$user['id'],
    'language' => 'uk',
    'sandbox' => str_starts_with($publicKey, 'sandbox_') ? '1' : '0',
];
$encoded = base64_encode((string)json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
jsonResponse(true, 'Перенаправляємо до безпечної оплати LiqPay.', ['checkout' => ['url' => 'https://www.liqpay.ua/api/3/checkout', 'data' => $encoded, 'signature' => liqpaySignature($encoded, $privateKey)], 'plan' => $planKey]);
