<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require_once __DIR__ . '/plans.php';
require_once __DIR__ . '/claims.php';

session_start();
if (empty($_SESSION['user_id'])) jsonResponse(false, 'Потрібна авторизація.', [], 401);

$pdo = db();
$userId = (string)$_SESSION['user_id'];
$userQuery = $pdo->prepare('SELECT id, name, email, role FROM users WHERE id = ? LIMIT 1');
$userQuery->execute([$userId]);
$user = $userQuery->fetch();
if (!$user) jsonResponse(false, 'Користувача не знайдено.', [], 401);

$context = workspacePlan($pdo, $userId, (string)$user['role']);
$ownerId = (string)$context['owner_id'];
$isManager = in_array((string)($context['workspace_role'] ?? ''), ['manager', 'admin', 'owner'], true)
    || in_array((string)$user['role'], ['admin', 'owner'], true);

if (($context['module'] ?? '') !== 'accounting') jsonResponse(false, 'Розділ доступний лише в бухгалтерському просторі.', [], 403);
if (empty($context['plan']['accountant_payouts'])) {
    jsonResponse(false, 'Облік виплат бухгалтерам доступний у пакетах «Команда» та «Бізнес».', ['code' => 'plan_feature_locked'], 403);
}

$pdo->exec("CREATE TABLE IF NOT EXISTS accounting_accountant_accruals (
    id CHAR(32) PRIMARY KEY, owner_id CHAR(32) NOT NULL, client_id CHAR(32) NOT NULL, invoice_id CHAR(32) NOT NULL,
    payment_id CHAR(32) NOT NULL, service_key VARCHAR(128) NOT NULL, service_name VARCHAR(190) NOT NULL,
    period_key VARCHAR(20) NOT NULL, accountant_id CHAR(32) NOT NULL, amount DECIMAL(14,2) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending_manager', source_payment_amount DECIMAL(14,2) NOT NULL,
    source_invoice_total DECIMAL(14,2) NOT NULL, calculation_snapshot JSON NOT NULL, requested_at DATETIME NULL,
    requested_by CHAR(32) NULL, decided_at DATETIME NULL, decided_by CHAR(32) NULL, decision_comment VARCHAR(1000) NULL,
    paid_at DATE NULL, paid_by CHAR(32) NULL, paid_comment VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_accountant_accrual_source (owner_id, payment_id, accountant_id, service_key),
    KEY idx_accountant_accrual_owner_status (owner_id, status, created_at),
    KEY idx_accountant_accrual_accountant (owner_id, accountant_id, status),
    KEY idx_accountant_accrual_client_period (owner_id, client_id, period_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS accounting_accountant_accrual_history (
    id CHAR(32) PRIMARY KEY, owner_id CHAR(32) NOT NULL, accrual_id CHAR(32) NOT NULL, action VARCHAR(32) NOT NULL,
    old_status VARCHAR(24) NULL, new_status VARCHAR(24) NULL, old_amount DECIMAL(14,2) NULL, new_amount DECIMAL(14,2) NULL,
    comment VARCHAR(1000) NULL, user_id CHAR(32) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_accountant_accrual_history (owner_id, accrual_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function payoutId(): string { return bin2hex(random_bytes(16)); }
function payoutText(mixed $value, int $limit = 1000): string { return mb_substr(trim((string)$value), 0, $limit); }
function payoutHistory(PDO $pdo, string $ownerId, string $accrualId, string $action, ?string $oldStatus, ?string $newStatus, ?float $oldAmount, ?float $newAmount, string $comment, string $userId): void {
    $pdo->prepare('INSERT INTO accounting_accountant_accrual_history(id,owner_id,accrual_id,action,old_status,new_status,old_amount,new_amount,comment,user_id) VALUES(?,?,?,?,?,?,?,?,?,?)')
        ->execute([payoutId(), $ownerId, $accrualId, $action, $oldStatus, $newStatus, $oldAmount, $newAmount, $comment ?: null, $userId]);
}
function payoutNotifyAccountant(PDO $pdo, array $accrual, string $senderId): void {
    $recipientId = (string)($accrual['accountant_id'] ?? '');
    if ($recipientId === '' || $recipientId === $senderId) return;
    $amount = number_format((float)($accrual['amount'] ?? 0), 2, ',', ' ');
    $message = 'Виплату '.$amount.' грн за послугу «'.(string)($accrual['service_name'] ?? '').'» позначено як виконану.';
    $pdo->prepare("INSERT INTO notifications(id,user_id,sender_id,type,title,message,entity_type,entity_id) VALUES(?,?,?,'accounting','Виплату проведено',?,'client',?)")
        ->execute([payoutId(), $recipientId, $senderId, $message, (string)($accrual['client_id'] ?? '')]);
}
function payoutServicePeople(array $service, array $fallback): array {
    $ids = array_merge((array)($service['accountant_ids'] ?? []), [(string)($service['accountant_id'] ?? '')]);
    $ids = array_values(array_unique(array_filter(array_map(static fn($id) => trim((string)$id), $ids))));
    return $ids ?: $fallback;
}
function payoutSync(PDO $pdo, string $ownerId, array $members, string $actorId): array {
    if (!$members) return ['created' => 0, 'manual' => []];
    $marks = implode(',', array_fill(0, count($members), '?'));
    $payments = $pdo->prepare("SELECT p.id payment_id,p.amount payment_amount,p.paid_on,i.id invoice_id,i.client_id,i.period_key,i.total,i.status invoice_status,i.items,c.name client_name,c.services,c.module_data
        FROM accounting_service_payments p
        JOIN accounting_service_invoices i ON i.id=p.invoice_id AND i.owner_id=p.owner_id
        JOIN clients c ON c.id=i.client_id AND c.module='accounting'
        WHERE p.owner_id=? AND p.reversed_at IS NULL AND c.created_by IN ({$marks})
        ORDER BY p.created_at ASC");
    $payments->execute(array_merge([$ownerId], $members));
    $created = 0; $manual = [];
    $insert = $pdo->prepare("INSERT IGNORE INTO accounting_accountant_accruals(id,owner_id,client_id,invoice_id,payment_id,service_key,service_name,period_key,accountant_id,amount,status,source_payment_amount,source_invoice_total,calculation_snapshot)
        VALUES(?,?,?,?,?,?,?,?,?,?, 'pending_manager',?,?,?)");
    foreach ($payments->fetchAll() as $payment) {
        $total = (float)$payment['total']; $paymentAmount = (float)$payment['payment_amount'];
        if ((string)$payment['invoice_status'] !== 'paid' || abs($paymentAmount - $total) > 0.009) {
            $manual[] = ['payment_id' => $payment['payment_id'], 'invoice_id' => $payment['invoice_id'], 'client_name' => $payment['client_name'], 'period_key' => $payment['period_key'], 'payment_amount' => $paymentAmount, 'invoice_total' => $total, 'reason' => 'Оплату сформовано частинами — виплата потребує ручного рішення менеджера.'];
            continue;
        }
        $items = json_decode((string)$payment['items'], true); $services = json_decode((string)$payment['services'], true); $moduleData = json_decode((string)$payment['module_data'], true);
        if (!is_array($items) || !is_array($services)) continue;
        $fallback = array_values(array_unique(array_filter(array_map('strval', (array)($moduleData['accountant_ids'] ?? [])))));
        foreach ($items as $index => $item) {
            $name = payoutText($item['name'] ?? '', 190); if ($name === '') continue;
            $matches = array_values(array_filter($services, static fn($service) => mb_strtolower(trim((string)($service['name'] ?? ''))) === mb_strtolower($name)));
            if (count($matches) !== 1) { $manual[] = ['payment_id'=>$payment['payment_id'],'invoice_id'=>$payment['invoice_id'],'client_name'=>$payment['client_name'],'period_key'=>$payment['period_key'],'payment_amount'=>$paymentAmount,'invoice_total'=>$total,'reason'=>"Не вдалося однозначно визначити послугу «{$name}». "]; continue; }
            $service = $matches[0]; $amount = round((float)($service['responsible_amount'] ?? 0), 2); $people = payoutServicePeople($service, $fallback);
            if ($amount <= 0 || !$people) { $manual[] = ['payment_id'=>$payment['payment_id'],'invoice_id'=>$payment['invoice_id'],'client_name'=>$payment['client_name'],'period_key'=>$payment['period_key'],'payment_amount'=>$paymentAmount,'invoice_total'=>$total,'reason'=>"Для послуги «{$name}» не задано суму або відповідального бухгалтера."]; continue; }
            foreach ($people as $accountantId) {
                if (!in_array($accountantId, $members, true)) continue;
                $snapshot = ['service' => $service, 'invoice_item' => $item, 'calculated_from' => 'full_confirmed_payment'];
                $id = payoutId();
                $insert->execute([$id,$ownerId,$payment['client_id'],$payment['invoice_id'],$payment['payment_id'],(string)($service['id'] ?? $index),$name,$payment['period_key'],$accountantId,$amount,$paymentAmount,$total,json_encode($snapshot, JSON_UNESCAPED_UNICODE)]);
                if ($insert->rowCount()) { $created++; payoutHistory($pdo,$ownerId,$id,'created',null,'pending_manager',null,$amount,'Нараховано за підтвердженою оплатою клієнта.',$actorId); }
            }
        }
    }
    return ['created' => $created, 'manual' => array_values(array_unique($manual, SORT_REGULAR))];
}

$members = array_values(array_unique(array_map('strval', $context['member_ids'] ?? [$ownerId])));
$data = body();
$action = (string)($data['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $syncResult = payoutSync($pdo, $ownerId, $members, $userId);
    if ($action === 'sync') {
        if (!$isManager) jsonResponse(false, 'Оновлювати нарахування може менеджер.', [], 403);
        jsonResponse(true, 'Нарахування за підтвердженими оплатами оновлено.', $syncResult);
    }
    $id = payoutText($data['id'] ?? '', 32);
    if (!in_array($action, ['request', 'approve', 'reject', 'mark_paid'], true)) jsonResponse(false, 'Невідома дія.', [], 422);
    $rowQuery = $pdo->prepare('SELECT * FROM accounting_accountant_accruals WHERE id=? AND owner_id=? LIMIT 1');
    $rowQuery->execute([$id, $ownerId]); $row = $rowQuery->fetch();
    if (!$row) jsonResponse(false, 'Нарахування не знайдено.', [], 404);
    if (!$isManager && (string)$row['accountant_id'] !== $userId) jsonResponse(false, 'Ви можете працювати лише з власними нарахуваннями.', [], 403);
    $comment = payoutText($data['comment'] ?? ''); $oldStatus = (string)$row['status'];
    if ($action === 'request') {
        if ((string)$row['accountant_id'] !== $userId || $oldStatus !== 'pending_manager') jsonResponse(false, 'Запит на виплату зараз недоступний.', [], 409);
        $pdo->prepare("UPDATE accounting_accountant_accruals SET status='payment_requested',requested_at=NOW(),requested_by=? WHERE id=? AND owner_id=?")->execute([$userId,$id,$ownerId]);
        payoutHistory($pdo,$ownerId,$id,'requested',$oldStatus,'payment_requested',(float)$row['amount'],(float)$row['amount'],$comment,$userId);
        jsonResponse(true, 'Запит на виплату надіслано менеджеру.');
    }
    if (!$isManager) jsonResponse(false, 'Ця дія доступна менеджеру.', [], 403);
    if ($action === 'approve' || $action === 'reject') {
        if ($oldStatus !== 'payment_requested') jsonResponse(false, 'Рішення можна прийняти лише за надісланим запитом.', [], 409);
        if ($action === 'reject' && mb_strlen($comment) < 2) jsonResponse(false, 'Вкажіть коментар до відхилення.', [], 422);
        $next = $action === 'approve' ? 'approved' : 'rejected';
        $pdo->prepare('UPDATE accounting_accountant_accruals SET status=?,decided_at=NOW(),decided_by=?,decision_comment=? WHERE id=? AND owner_id=?')->execute([$next,$userId,$comment ?: null,$id,$ownerId]);
        payoutHistory($pdo,$ownerId,$id,$action,$oldStatus,$next,(float)$row['amount'],(float)$row['amount'],$comment,$userId);
        jsonResponse(true, $next === 'approved' ? 'Запит схвалено.' : 'Запит відхилено.');
    }
    if (!in_array($oldStatus, ['approved', 'pending_manager'], true)) jsonResponse(false, 'Позначити виплату можна після схвалення або безпосередньо з нового нарахування.', [], 409);
    $paidAt = payoutText($data['paid_at'] ?? '', 10);
    $validDate = DateTimeImmutable::createFromFormat('!Y-m-d', $paidAt);
    if (!$validDate || $validDate->format('Y-m-d') !== $paidAt) jsonResponse(false, 'Вкажіть фактичну дату виплати.', [], 422);
    $direct = $oldStatus === 'pending_manager';
    $historyComment = $comment ?: ($direct ? 'Виплачено менеджером без запиту бухгалтера.' : '');
    if ($direct) {
        $pdo->prepare("UPDATE accounting_accountant_accruals SET status='paid',decided_at=NOW(),decided_by=?,decision_comment=?,paid_at=?,paid_by=?,paid_comment=? WHERE id=? AND owner_id=?")->execute([$userId,$historyComment,$paidAt,$userId,$comment ?: null,$id,$ownerId]);
    } else {
        $pdo->prepare("UPDATE accounting_accountant_accruals SET status='paid',paid_at=?,paid_by=?,paid_comment=? WHERE id=? AND owner_id=?")->execute([$paidAt,$userId,$comment ?: null,$id,$ownerId]);
    }
    payoutHistory($pdo,$ownerId,$id,$direct ? 'paid_directly' : 'paid',$oldStatus,'paid',(float)$row['amount'],(float)$row['amount'],$historyComment,$userId);
    payoutNotifyAccountant($pdo, $row, $userId);
    jsonResponse(true, $direct ? 'Виплату проведено без запиту бухгалтера.' : 'Виплату позначено як виконану.');
}

$sync = payoutSync($pdo, $ownerId, $members, $userId);
$filters = [
    'accountant' => payoutText($_GET['accountant'] ?? '', 32), 'client' => payoutText($_GET['client'] ?? '', 32),
    'service' => payoutText($_GET['service'] ?? '', 190), 'period' => payoutText($_GET['period'] ?? '', 7), 'status' => payoutText($_GET['status'] ?? '', 24),
];
$where = ['a.owner_id=?']; $values = [$ownerId];
if (!$isManager) { $where[] = 'a.accountant_id=?'; $values[] = $userId; }
foreach (['accountant'=>'a.accountant_id','client'=>'a.client_id','period'=>'a.period_key','status'=>'a.status'] as $key=>$column) if ($filters[$key] !== '') { $where[] = "{$column}=?"; $values[] = $filters[$key]; }
if ($filters['service'] !== '') { $where[] = 'a.service_name=?'; $values[] = $filters['service']; }
$sql = 'SELECT a.*,c.name client_name,u.name accountant_name,i.invoice_no,p.paid_on FROM accounting_accountant_accruals a JOIN clients c ON c.id=a.client_id JOIN users u ON u.id=a.accountant_id JOIN accounting_service_invoices i ON i.id=a.invoice_id JOIN accounting_service_payments p ON p.id=a.payment_id WHERE '.implode(' AND ', $where).' ORDER BY a.created_at DESC LIMIT 500';
$list = $pdo->prepare($sql); $list->execute($values); $accruals = $list->fetchAll();
$history = [];
if ($accruals) { $ids = array_column($accruals, 'id'); $marks = implode(',', array_fill(0, count($ids), '?')); $hq = $pdo->prepare("SELECT h.*,u.name user_name FROM accounting_accountant_accrual_history h LEFT JOIN users u ON u.id=h.user_id WHERE h.owner_id=? AND h.accrual_id IN ({$marks}) ORDER BY h.created_at DESC"); $hq->execute(array_merge([$ownerId],$ids)); foreach($hq->fetchAll() as $entry) $history[(string)$entry['accrual_id']][]=$entry; }
$totals = ['pending_manager'=>0,'payment_requested'=>0,'approved'=>0,'paid'=>0,'rejected'=>0]; foreach ($accruals as $row) $totals[$row['status']] = ($totals[$row['status']] ?? 0) + (float)$row['amount'];
$people = $pdo->prepare('SELECT id,name,email FROM users WHERE id IN ('.implode(',',array_fill(0,count($members),'?')).') ORDER BY name'); $people->execute($members);
$clients = $pdo->prepare('SELECT id,name FROM clients WHERE module=\'accounting\' AND created_by IN ('.implode(',',array_fill(0,count($members),'?')).') ORDER BY name'); $clients->execute($members);
$payloadUser = ['id'=>$userId,'name'=>$user['name'],'email'=>$user['email'],'role'=>$user['role'],'workspace_role'=>$context['workspace_role'] ?? 'user','active_workspace_id'=>$ownerId,'subscription'=>planSettingsPayload($context)];
jsonResponse(true, 'Нарахування завантажено.', ['user'=>$payloadUser,'accruals'=>$accruals,'history'=>$history,'totals'=>$totals,'manual_reviews'=>$isManager?$sync['manual']:[],'accountants'=>$people->fetchAll(),'clients'=>$clients->fetchAll(),'can_manage'=>$isManager,'plan_key'=>$context['key']]);
