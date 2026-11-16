<?php
declare(strict_types=1);

if (!isset($pdo,$ownerId,$userId,$method)) { http_response_code(404); exit; }

// Loaded only after dentistry workspace authorization and schema initialization.
function dentalErpRows(PDO $pdo, string $sql, array $args): array
{
    $q = $pdo->prepare($sql); $q->execute($args); return $q->fetchAll();
}
function dentalErpNumber($value, bool $positive = false): string
{
    if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < ($positive ? 0.0001 : 0) || (float)$value > 999999999.9999) throw new DomainException('Вкажіть коректну кількість або суму.');
    return number_format((float)$value, 4, '.', '');
}
function dentalErpDate($value): string
{
    $value = (string)$value; $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) throw new DomainException('Некоректна дата.');
    return $value;
}
function dentalErpRecommendations(PDO $pdo, string $owner): array
{
    $rows = dentalErpRows($pdo, "SELECT i.id item_id,i.name,i.minimum_stock,i.optimal_stock,i.default_supplier_id supplier_id,u.symbol unit_symbol,s.name supplier_name,
      COALESCE((SELECT SUM(b.available_quantity) FROM dental_erp_inventory_batches b WHERE b.owner_id=i.owner_id AND b.item_id=i.id AND b.is_blocked=0 AND (b.expiration_date IS NULL OR b.expiration_date>=CURRENT_DATE)),0) available,
      COALESCE((SELECT SUM(r.quantity) FROM dental_erp_stock_reservations r JOIN dental_erp_inventory_batches b ON b.id=r.batch_id AND b.owner_id=r.owner_id WHERE r.owner_id=i.owner_id AND b.item_id=i.id AND r.status='active' AND b.is_blocked=0 AND (b.expiration_date IS NULL OR b.expiration_date>=CURRENT_DATE)),0) reserved,
      COALESCE((SELECT SUM(p.quantity-p.received_quantity) FROM dental_erp_purchase_order_items p JOIN dental_erp_purchase_orders o ON o.id=p.purchase_order_id AND o.owner_id=p.owner_id WHERE p.owner_id=i.owner_id AND p.inventory_item_id=i.id AND o.status IN ('approved','ordered','partially_received')),0) incoming,
      COALESCE((SELECT SUM(m.quantity)/30 FROM dental_erp_stock_movements m WHERE m.owner_id=i.owner_id AND m.item_id=i.id AND m.movement_type='consumption' AND m.created_at>=CURRENT_DATE-INTERVAL 30 DAY),0) daily_usage,
      COALESCE((SELECT lead_days FROM dental_erp_procurement_settings WHERE owner_id=i.owner_id),7) lead_days
      FROM dental_erp_inventory_items i JOIN dental_erp_inventory_units u ON u.id=i.unit_id AND u.owner_id=i.owner_id LEFT JOIN dental_erp_suppliers s ON s.id=i.default_supplier_id AND s.owner_id=i.owner_id WHERE i.owner_id=? AND i.is_active=1", [$owner]);
    $result = [];
    foreach ($rows as $row) {
        $projected = (float)$row['available'] - (float)$row['reserved'] + (float)$row['incoming'] - (float)$row['daily_usage'] * (int)$row['lead_days'];
        if ($projected >= (float)$row['minimum_stock']) continue;
        $row['projected'] = $projected; $row['optimal'] = $row['optimal_stock']; $row['minimum'] = $row['minimum_stock'];
        $row['suggested_quantity'] = max(0, (float)$row['optimal_stock'] - $projected); $result[] = $row;
    }
    return $result;
}
function dentalErpProfitability(PDO $pdo, string $owner, string $from, string $to): array
{
    $procedures = dentalErpRows($pdo, "SELECT s.id,s.service_id,s.service_name,s.doctor_id,u.name doctor_name,s.total_price revenue,s.performed_at,
      COALESCE((SELECT SUM(m.total_cost-COALESCE((SELECT SUM(r.quantity*r.unit_cost) FROM dental_erp_stock_movements r WHERE r.owner_id=m.owner_id AND r.source_type='material_return' AND r.source_id=m.stock_movement_id),0)) FROM dental_erp_performed_service_materials m WHERE m.owner_id=? AND m.performed_service_id=s.id),0) material_cost,
      d.id cost_id,COALESCE(d.laboratory_cost,0) laboratory_cost,COALESCE(d.labor_cost,0) labor_cost,COALESCE(d.other_cost,0) other_cost,
      COALESCE((SELECT SUM(p.amount) FROM dental_erp_procedure_cost_payments p WHERE p.owner_id=d.owner_id AND p.cost_id=d.id),0) direct_paid,
      COALESCE((SELECT SUM(ii.total_price * (1-i.discount_amount/NULLIF(i.subtotal,0))) FROM dental_invoice_items ii JOIN dental_invoices i ON i.id=ii.invoice_id WHERE ii.performed_service_id=s.id AND i.status<>'cancelled'),0) invoiced
      FROM dental_performed_services s JOIN clients c ON c.id=s.patient_id JOIN users u ON u.id=s.doctor_id LEFT JOIN dental_erp_procedure_costs d ON d.performed_service_id=s.id AND d.owner_id=? WHERE c.created_by=? AND DATE(s.performed_at) BETWEEN ? AND ? ORDER BY s.performed_at DESC", [$owner,$owner,$owner,$from,$to]);
    $services=[]; $doctors=[];
    foreach ($procedures as &$p) {
        $p['direct_cost']=(float)$p['material_cost']+(float)$p['laboratory_cost']+(float)$p['labor_cost']+(float)$p['other_cost'];
        $p['margin']=(float)$p['revenue']-$p['direct_cost'];
        foreach ([['service', (string)($p['service_id'] ?: $p['service_name']), $p['service_name']], ['doctor',$p['doctor_id'],$p['doctor_name']]] as [$type,$key,$name]) {
            $group = &$services; if ($type==='doctor') $group = &$doctors;
            if (!isset($group[$key])) $group[$key]=['id'=>$key,'name'=>$name,'count'=>0,'revenue'=>0,'material_cost'=>0,'direct_cost'=>0,'margin'=>0];
            $group[$key]['count']++; foreach (['revenue','material_cost','direct_cost','margin'] as $field) $group[$key][$field]+=(float)$p[$field]; unset($group);
        }
    } unset($p);
    $byMargin=static fn($a,$b)=>$b['margin']<=>$a['margin']; uasort($services,$byMargin); uasort($doctors,$byMargin);
    return ['procedures'=>$procedures,'services'=>array_values($services),'doctors'=>array_values($doctors)];
}

$dentalOperationFeatures = [
    'save_location' => 'dental_inventory', 'set_location_active' => 'dental_inventory', 'receive_inventory' => 'dental_inventory', 'stock_operation' => 'dental_inventory', 'reserve_stock' => 'dental_inventory', 'release_reservation' => 'dental_inventory',
    'supplier_refund' => 'dental_procurement', 'save_procurement_settings' => 'dental_procurement', 'generate_purchase_drafts' => 'dental_procurement', 'cancel_purchase_order' => 'dental_procurement',
    'create_expense_request' => 'dental_financial_analytics', 'generate_recurring_expenses' => 'dental_financial_analytics', 'stop_recurring_expense' => 'dental_financial_analytics', 'approve_expense' => 'dental_financial_analytics', 'reject_expense' => 'dental_financial_analytics',
    'record_procedure_cost' => 'dental_procedure_costing', 'pay_procedure_cost' => 'dental_procedure_costing', 'post_depreciation' => 'dental_equipment', 'payroll_adjustment' => 'dental_doctor_payroll',
    'reverse_journal' => 'dental_management_accounting', 'upload_expense_document' => 'dental_financial_analytics',
];
if (isset($dentalOperationFeatures[$action])) requirePlanFeature($context, $dentalOperationFeatures[$action]);
if ($method === 'GET' && $action === 'alerts') requirePlanFeature($context, 'dental_inventory');
if ($method === 'GET' && $action === 'operations') requirePlanFeature($context, 'dental_basic_reports');
if ($method === 'GET' && $action === 'expense_document') requirePlanFeature($context, 'dental_financial_analytics');

if ($method==='GET' && in_array($action,['overview','operations','alerts'],true)) {
    $pdo->prepare("UPDATE dental_erp_stock_reservations r JOIN tasks t ON t.id=r.appointment_id SET r.status='released' WHERE r.owner_id=? AND r.status='active' AND t.status IN ('done','archived','cancelled')")->execute([$ownerId]);
}
if ($method==='GET' && $action==='alerts') {
    $manage();
    $expiring=dentalErpRows($pdo,"SELECT i.name,b.batch_number,b.expiration_date,b.available_quantity FROM dental_erp_inventory_batches b JOIN dental_erp_inventory_items i ON i.id=b.item_id AND i.owner_id=b.owner_id WHERE b.owner_id=? AND b.available_quantity>0 AND b.expiration_date<=CURRENT_DATE+INTERVAL 30 DAY ORDER BY b.expiration_date",[$ownerId]);
    jsonResponse(true,'Попередження складу.',['recommendations'=>dentalErpRecommendations($pdo,$ownerId),'expiring'=>$expiring]);
}

if ($method==='GET' && $action==='operations') {
    $manage();
    $canProcure=!empty($context['plan']['dental_procurement']); $canCost=!empty($context['plan']['dental_procedure_costing']);
    $canAdvancedFinance=!empty($context['plan']['dental_financial_analytics']); $canPayroll=!empty($context['plan']['dental_doctor_payroll']);
    $canEquipment=!empty($context['plan']['dental_equipment']); $canManagementAccounting=!empty($context['plan']['dental_management_accounting']);
    $batches=dentalErpRows($pdo,"SELECT b.*,i.name item_name,u.symbol unit_symbol,l.name location_name,COALESCE((SELECT SUM(r.quantity) FROM dental_erp_stock_reservations r WHERE r.owner_id=b.owner_id AND r.batch_id=b.id AND r.status='active'),0) reserved FROM dental_erp_inventory_batches b JOIN dental_erp_inventory_items i ON i.id=b.item_id AND i.owner_id=b.owner_id JOIN dental_erp_inventory_units u ON u.id=i.unit_id AND u.owner_id=i.owner_id JOIN dental_erp_inventory_locations l ON l.id=b.location_id AND l.owner_id=b.owner_id WHERE b.owner_id=? ORDER BY i.name,b.expiration_date,b.id",[$ownerId]);
    $reports=!empty($context['plan']['dental_procedure_costing']) ? dentalErpProfitability($pdo,$ownerId,$reportFrom,$reportTo) : ['procedures'=>[],'services'=>[],'doctors'=>[]];
    $balances=dentalErpRows($pdo,"SELECT a.account_code,a.name,a.account_type,COALESCE(SUM(CASE WHEN j.entry_date<? THEN e.debit-e.credit ELSE 0 END),0) opening_balance,COALESCE(SUM(CASE WHEN j.entry_date BETWEEN ? AND ? THEN e.debit ELSE 0 END),0) debit,COALESCE(SUM(CASE WHEN j.entry_date BETWEEN ? AND ? THEN e.credit ELSE 0 END),0) credit,COALESCE(SUM(CASE WHEN j.entry_date<=? THEN e.debit-e.credit ELSE 0 END),0) closing_balance FROM dental_erp_accounting_accounts a LEFT JOIN dental_erp_accounting_entries e ON e.account_id=a.id AND e.owner_id=a.owner_id LEFT JOIN dental_erp_accounting_journals j ON j.id=e.journal_id AND j.owner_id=e.owner_id WHERE a.owner_id=? GROUP BY a.id ORDER BY a.account_code",[$reportFrom,$reportFrom,$reportTo,$reportFrom,$reportTo,$reportTo,$ownerId]);
    jsonResponse(true,'Операційні дані завантажено.',[
      'batches'=>$batches,'locations'=>dentalErpRows($pdo,"SELECT * FROM dental_erp_inventory_locations WHERE owner_id=? ORDER BY is_active DESC, (name='Основний склад') DESC, name",[$ownerId]),
      'reservations'=>dentalErpRows($pdo,"SELECT r.*,i.name item_name,b.batch_number FROM dental_erp_stock_reservations r JOIN dental_erp_inventory_batches b ON b.id=r.batch_id AND b.owner_id=r.owner_id JOIN dental_erp_inventory_items i ON i.id=b.item_id AND i.owner_id=b.owner_id WHERE r.owner_id=? ORDER BY r.created_at DESC",[$ownerId]),
      'appointments'=>dentalErpRows($pdo,"SELECT DISTINCT t.id,t.title,t.due_date,c.name patient_name FROM tasks t JOIN task_clients tc ON tc.task_id=t.id JOIN clients c ON c.id=tc.client_id WHERE c.created_by=? AND c.module='dentistry' AND t.is_client_appointment=1 AND t.status NOT IN ('cancelled','archived') ORDER BY t.due_date DESC LIMIT 500",[$ownerId]),
      'counts'=>dentalErpRows($pdo,'SELECT n.*,i.name item_name FROM dental_erp_inventory_counts n JOIN dental_erp_inventory_batches b ON b.id=n.batch_id AND b.owner_id=n.owner_id JOIN dental_erp_inventory_items i ON i.id=b.item_id AND i.owner_id=b.owner_id WHERE n.owner_id=? ORDER BY n.created_at DESC',[$ownerId]),
      'consumptions'=>dentalErpRows($pdo,"SELECT m.*,i.name item_name,COALESCE((SELECT SUM(r.quantity) FROM dental_erp_stock_movements r WHERE r.owner_id=m.owner_id AND r.source_type='material_return' AND r.source_id=m.id),0) returned FROM dental_erp_stock_movements m JOIN dental_erp_inventory_items i ON i.owner_id=m.owner_id AND i.id=m.item_id WHERE m.owner_id=? AND m.movement_type='consumption' ORDER BY m.created_at DESC",[$ownerId]),
      'returns'=>$canProcure?dentalErpRows($pdo,'SELECT r.*,i.invoice_number FROM dental_erp_supplier_returns r JOIN dental_erp_supplier_invoices i ON i.id=r.supplier_invoice_id AND i.owner_id=r.owner_id WHERE r.owner_id=? ORDER BY r.created_at DESC',[$ownerId]):[],
      'supplier_refunds'=>$canProcure?dentalErpRows($pdo,'SELECT * FROM dental_erp_supplier_refunds WHERE owner_id=? ORDER BY created_at DESC',[$ownerId]):[],
      'invoice_items'=>$canProcure?dentalErpRows($pdo,'SELECT l.*,i.name item_name FROM dental_erp_supplier_invoice_items l JOIN dental_erp_inventory_items i ON i.id=l.inventory_item_id AND i.owner_id=l.owner_id WHERE l.owner_id=?',[$ownerId]):[],
      'supplier_payments'=>$canProcure?dentalErpRows($pdo,'SELECT p.*,i.invoice_number,s.name supplier_name FROM dental_erp_supplier_payments p JOIN dental_erp_supplier_invoices i ON i.id=p.supplier_invoice_id AND i.owner_id=p.owner_id JOIN dental_erp_suppliers s ON s.id=i.supplier_id AND s.owner_id=i.owner_id WHERE p.owner_id=? ORDER BY p.payment_date DESC',[$ownerId]):[],
      'expense_requests'=>$canAdvancedFinance?dentalErpRows($pdo,'SELECT r.*,c.name category_name FROM dental_erp_expense_requests r JOIN dental_erp_expense_categories c ON c.id=r.category_id AND c.owner_id=r.owner_id WHERE r.owner_id=? ORDER BY r.expense_date DESC,r.created_at DESC',[$ownerId]):[],
      'recurring_expenses'=>$canAdvancedFinance?dentalErpRows($pdo,'SELECT * FROM dental_erp_recurring_expenses WHERE owner_id=? ORDER BY next_due',[$ownerId]):[],
      'expense_documents'=>$canAdvancedFinance?dentalErpRows($pdo,'SELECT id,expense_id,original_name,file_size FROM dental_erp_expense_documents WHERE owner_id=? ORDER BY created_at DESC',[$ownerId]):[],
      'depreciation'=>$canEquipment?dentalErpRows($pdo,'SELECT d.*,e.name equipment_name FROM dental_erp_depreciation d JOIN dental_erp_equipment e ON e.id=d.equipment_id AND e.owner_id=d.owner_id WHERE d.owner_id=? ORDER BY d.period_date DESC',[$ownerId]):[],
      'payroll_adjustments'=>$canPayroll?dentalErpRows($pdo,'SELECT * FROM dental_erp_payroll_adjustments WHERE owner_id=? ORDER BY created_at DESC',[$ownerId]):[],
      'payroll_payments'=>$canPayroll?dentalErpRows($pdo,'SELECT p.*,u.name user_name FROM dental_erp_payroll_payments p JOIN dental_erp_payroll_accruals a ON a.id=p.accrual_id AND a.owner_id=p.owner_id JOIN users u ON u.id=a.user_id WHERE p.owner_id=? ORDER BY p.payment_date DESC',[$ownerId]):[],
      'settings'=>!empty($context['plan']['dental_procurement'])?(dentalErpRows($pdo,'SELECT auto_drafts,lead_days FROM dental_erp_procurement_settings WHERE owner_id=?',[$ownerId])[0]??['auto_drafts'=>0,'lead_days'=>7]):['auto_drafts'=>0,'lead_days'=>7],
      'profitability'=>$canCost?$reports:['procedures'=>[],'services'=>[],'doctors'=>[]],'balances'=>$canManagementAccounting?$balances:[],
      'audit'=>dentalErpRows($pdo,'SELECT a.*,u.name actor_name FROM dental_erp_audit a LEFT JOIN users u ON u.id=a.actor_id WHERE a.owner_id=? AND (?=1 OR a.entity_type IN (\'inventory_item\',\'inventory_batch\',\'stock_movement\',\'stock_reservation\',\'inventory_count\')) ORDER BY a.created_at DESC LIMIT 500',[$ownerId,$canAdvancedFinance?1:0]),
    ]);
}

$erpPrivateFiles=(getenv('NEXAFLOW_PRIVATE_STORAGE') ?: dirname(__DIR__,2).DIRECTORY_SEPARATOR.'nexaflow-private').DIRECTORY_SEPARATOR.'dentistry-erp';
if ($method==='GET' && $action==='expense_document') {
    $manage(); $doc=dentalErpRows($pdo,'SELECT * FROM dental_erp_expense_documents WHERE id=? AND owner_id=?',[(string)($_GET['id']??''),$ownerId])[0]??null;
    if (!$doc) jsonResponse(false,'Документ не знайдено.',[],404);
    $path=$erpPrivateFiles.DIRECTORY_SEPARATOR.$doc['storage_name']; if (!is_file($path)) jsonResponse(false,'Файл не знайдено.',[],404);
    header('Content-Type: '.$doc['mime_type']); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
    header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($doc['original_name'])); readfile($path); exit;
}

$operationActions=['save_location','set_location_active','receive_inventory','stock_operation','reserve_stock','release_reservation','save_procurement_settings','generate_purchase_drafts','create_expense_request','approve_expense','reject_expense','generate_recurring_expenses','stop_recurring_expense','record_procedure_cost','post_depreciation','payroll_adjustment','reverse_journal','upload_expense_document','cancel_purchase_order','supplier_refund','pay_procedure_cost'];
if ($method==='POST' && in_array($action,$operationActions,true)) {
    $manage(); $key=(string)($data['idempotency_key']??'');
    if (!preg_match('/^[a-f0-9]{32}$/i',$key)) jsonResponse(false,'Відсутній ключ повторного запиту.',[],422);
    $storedFile=null;
    try {
        $pdo->beginTransaction();
        // One workspace lock gives all cross-batch stock operations the same lock order.
        $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE'); $lock->execute([$ownerId]);
        $previous=dentalErpRows($pdo,'SELECT action,result FROM dental_erp_operations WHERE owner_id=? AND request_key=?',[$ownerId,$key])[0]??null;
        if ($previous) {
            if ($previous['action']!==$action) throw new DomainException('Ключ запиту вже використано для іншої операції.');
            $pdo->commit(); jsonResponse(true,'Операцію вже виконано.',['result'=>json_decode($previous['result'],true),'idempotent_replay'=>true]);
        }
        $opId=$id(); $result=[];
        $reason=trim((string)($data['reason']??$data['description']??''));
        $owned=static function(string $table,string $entityId) use($pdo,$ownerId): array {
            $rows=dentalErpRows($pdo,"SELECT * FROM {$table} WHERE id=? AND owner_id=? FOR UPDATE",[$entityId,$ownerId]);
            if (!$rows) throw new DomainException('Запис не знайдено у цьому просторі.'); return $rows[0];
        };
        $move=static function(array $batch,string $type,string $qty,string $source,string $sourceId,string $note) use($pdo,$ownerId,$userId,$id): void {
            $pdo->prepare('INSERT INTO dental_erp_stock_movements(id,owner_id,item_id,batch_id,location_id,movement_type,quantity,unit_cost,source_type,source_id,note,actor_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id(),$ownerId,$batch['item_id'],$batch['id'],$batch['location_id'],$type,$qty,$batch['purchase_unit_cost'],$source,$sourceId,$note,$userId]);
        };
        if ($action==='save_location') {
            $name=trim((string)($data['name']??'')); if ($name==='') throw new DomainException('Вкажіть назву складу.');
            $pdo->prepare('INSERT INTO dental_erp_inventory_locations(id,owner_id,name) VALUES(?,?,?)')->execute([$opId,$ownerId,mb_substr($name,0,120)]);
        }
        if ($action==='set_location_active') {
            $location=$owned('dental_erp_inventory_locations',(string)($data['location_id']??''));
            $active=filter_var($data['active']??null,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
            if ($active===null) throw new DomainException('Некоректний статус складу.');
            if (!$active && $location['name']==='Основний склад') throw new DomainException('Основний склад потрібен системі для обліку матеріалів і не може бути видалений.');
            if (!$active) {
                $stock=dentalErpRows($pdo,'SELECT COALESCE(SUM(available_quantity),0) quantity FROM dental_erp_inventory_batches WHERE owner_id=? AND location_id=?',[$ownerId,$location['id']])[0]['quantity']??0;
                $reserved=dentalErpRows($pdo,"SELECT COALESCE(SUM(r.quantity),0) reserved FROM dental_erp_stock_reservations r JOIN dental_erp_inventory_batches b ON b.owner_id=r.owner_id AND b.id=r.batch_id WHERE r.owner_id=? AND b.location_id=? AND r.status='active'",[$ownerId,$location['id']])[0]['reserved']??0;
                if ((float)$stock>0 || (float)$reserved>0) throw new DomainException('На складі є матеріали або резерви. Спочатку перемістіть залишок на інший склад і звільніть резерви.');
            }
            $pdo->prepare('UPDATE dental_erp_inventory_locations SET is_active=? WHERE id=? AND owner_id=?')->execute([$active?1:0,$location['id'],$ownerId]);
            $result=['location_id'=>$location['id'],'active'=>$active];
        }
        if ($action==='receive_inventory') {
            $item=$owned('dental_erp_inventory_items',(string)($data['item_id']??''));
            $location=$owned('dental_erp_inventory_locations',(string)($data['location_id']??''));
            if (!$item['is_active'] || !$location['is_active']) throw new DomainException('Оберіть активний матеріал і склад.');
            $qty=dentalErpNumber($data['quantity']??'',true);
            $unitCost=dentalErpNumber($data['unit_cost']??$item['purchase_unit_cost']);
            $batchNumber=trim((string)($data['batch_number']??''));
            $expiration=trim((string)($data['expiration_date']??''));
            if ($reason==='') throw new DomainException('Вкажіть підставу надходження.');
            if ($expiration!=='') $expiration=dentalErpDate($expiration);
            $batchId=$id();
            $pdo->prepare('INSERT INTO dental_erp_inventory_batches(id,owner_id,item_id,location_id,batch_number,expiration_date,received_quantity,available_quantity,purchase_unit_cost,is_blocked) VALUES(?,?,?,?,?,?,?,?,?,0)')->execute([$batchId,$ownerId,$item['id'],$location['id'],$batchNumber?:null,$expiration?:null,$qty,$qty,$unitCost]);
            $pdo->prepare("INSERT INTO dental_erp_stock_movements(id,owner_id,item_id,batch_id,location_id,movement_type,quantity,unit_cost,source_type,source_id,note,actor_id) VALUES(?,?,?,?,?,'receipt',?,?, 'manual_receipt',?,?,?)")->execute([$id(),$ownerId,$item['id'],$batchId,$location['id'],$qty,$unitCost,$opId,mb_substr($reason,0,1000),$userId]);
            $cents=(int)round((float)$qty*(float)$unitCost*100);
            if ($cents>0) dentalErpPostJournal($pdo,$ownerId,$userId,'manual_inventory_receipt',$opId,date('Y-m-d'),$reason,[['account'=>'inventory','debit_cents'=>$cents],['account'=>'stock_adjustment','credit_cents'=>$cents]]);
            $result=['batch_id'=>$batchId,'quantity'=>$qty];
        }
        if ($action==='stock_operation') {
            $batch=$owned('dental_erp_inventory_batches',(string)($data['batch_id']??'')); $kind=(string)($data['kind']??'');
            if ($reason==='') throw new DomainException('Вкажіть причину складської операції.');
            if ($kind==='block' || $kind==='unblock') {
                if ($kind==='unblock' && $batch['expiration_date'] && $batch['expiration_date']<date('Y-m-d')) throw new DomainException('Прострочену партію не можна розблокувати.');
                $pdo->prepare('UPDATE dental_erp_inventory_batches SET is_blocked=? WHERE id=? AND owner_id=?')->execute([$kind==='block'?1:0,$batch['id'],$ownerId]);
                $pdo->prepare("UPDATE dental_erp_stock_reservations SET status='released' WHERE owner_id=? AND batch_id=? AND status='active'")->execute([$ownerId,$batch['id']]);
            } else {
                $qty=dentalErpNumber($data['quantity']??'', $kind!=='count');
                $reserved=dentalErpRows($pdo,"SELECT COALESCE(SUM(quantity),0) qty FROM dental_erp_stock_reservations WHERE owner_id=? AND batch_id=? AND status='active'",[$ownerId,$batch['id']])[0]['qty'];
                if ($kind==='material_return') {
                    $original=$owned('dental_erp_stock_movements',(string)($data['movement_id']??''));
                    if ($original['movement_type']!=='consumption' || $original['batch_id']!==$batch['id']) throw new DomainException('Оберіть списання цієї партії.');
                    $returned=dentalErpRows($pdo,"SELECT COALESCE(SUM(quantity),0) qty FROM dental_erp_stock_movements WHERE owner_id=? AND source_type='material_return' AND source_id=?",[$ownerId,$original['id']])[0]['qty'];
                    if ((float)$qty>(float)$original['quantity']-(float)$returned+0.000001) throw new DomainException('Повернення перевищує списану кількість.');
                    $pdo->prepare('UPDATE dental_erp_inventory_batches SET available_quantity=available_quantity+?,received_quantity=GREATEST(received_quantity,available_quantity+0) WHERE id=? AND owner_id=?')->execute([$qty,$batch['id'],$ownerId]);
                    $move($batch,'return',$qty,'material_return',$original['id'],$reason);
                    $cents=(int)round((float)$qty*(float)$original['unit_cost']*100);
                    if ($cents) dentalErpPostJournal($pdo,$ownerId,$userId,'material_return',$opId,date('Y-m-d'),$reason,[['account'=>'inventory','debit_cents'=>$cents],['account'=>'material_expense','credit_cents'=>$cents]]);
                } elseif ($kind==='count') {
                    $expected=dentalErpNumber($data['expected_quantity']??'');
                    if (abs((float)$expected-(float)$batch['available_quantity'])>0.00001) throw new DomainException('Після відкриття форми залишок змінився. Оновіть дані інвентаризації.');
                    $pdo->prepare('INSERT INTO dental_erp_inventory_counts(id,owner_id,batch_id,expected_quantity,counted_quantity,reason,created_by) VALUES(?,?,?,?,?,?,?)')->execute([$opId,$ownerId,$batch['id'],$expected,$qty,mb_substr($reason,0,1000),$userId]);
                    $delta=(float)$qty-(float)$expected;
                    if ((float)$qty<(float)$reserved) throw new DomainException('Спочатку звільніть резерв, який перевищує фактичний залишок.');
                    if ($delta!=0) {
                        $pdo->prepare('UPDATE dental_erp_inventory_batches SET available_quantity=?,received_quantity=GREATEST(received_quantity,?) WHERE id=? AND owner_id=?')->execute([$qty,$qty,$batch['id'],$ownerId]);
                        $move($batch,$delta>0?'adjustment_in':'adjustment_out',number_format(abs($delta),4,'.',''),'inventory_count',$opId,$reason);
                        $cents=(int)round(abs($delta)*(float)$batch['purchase_unit_cost']*100);
                        if ($cents) dentalErpPostJournal($pdo,$ownerId,$userId,'inventory_count',$opId,date('Y-m-d'),$reason,[['account'=>$delta>0?'inventory':'material_expense','debit_cents'=>$cents],['account'=>$delta>0?'stock_adjustment':'inventory','credit_cents'=>$cents]]);
                    }
                } elseif (in_array($kind,['transfer','write_off','supplier_return'],true)) {
                    if ((float)$qty>(float)$batch['available_quantity']-(float)$reserved+0.000001) throw new DomainException('Недостатньо вільного залишку; зарезервовані матеріали не можна переміщувати або списувати.');
                    $pdo->prepare('UPDATE dental_erp_inventory_batches SET available_quantity=available_quantity-? WHERE id=? AND owner_id=?')->execute([$qty,$batch['id'],$ownerId]);
                    $cents=(int)round((float)$qty*(float)$batch['purchase_unit_cost']*100);
                    if ($kind==='transfer') {
                        $target=$owned('dental_erp_inventory_locations',(string)($data['location_id']??''));
                        if ($target['id']===$batch['location_id'] || !$target['is_active']) throw new DomainException('Оберіть інший активний склад.');
                        $newBatch=$id();
                        $pdo->prepare('INSERT INTO dental_erp_inventory_batches(id,owner_id,item_id,location_id,batch_number,expiration_date,received_quantity,available_quantity,purchase_unit_cost,is_blocked,received_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$newBatch,$ownerId,$batch['item_id'],$target['id'],$batch['batch_number'],$batch['expiration_date'],$qty,$qty,$batch['purchase_unit_cost'],$batch['is_blocked'],$batch['received_at']]);
                        $move($batch,'transfer_out',$qty,'transfer',$opId,$reason); $batch['id']=$newBatch; $batch['location_id']=$target['id']; $move($batch,'transfer_in',$qty,'transfer',$opId,$reason);
                    } elseif ($kind==='supplier_return') {
                        $invoice=$owned('dental_erp_supplier_invoices',(string)($data['supplier_invoice_id']??''));
                        $sourceBatch=$batch['id']; $origin=null;
                        for ($depth=0;$depth<100;$depth++) {
                            $origin=dentalErpRows($pdo,'SELECT r.purchase_order_id FROM dental_erp_goods_receipt_items ri JOIN dental_erp_goods_receipts r ON r.id=ri.receipt_id AND r.owner_id=ri.owner_id WHERE ri.owner_id=? AND ri.batch_id=?',[$ownerId,$sourceBatch])[0]??null;
                            if ($origin) break;
                            $transfer=dentalErpRows($pdo,"SELECT o.batch_id FROM dental_erp_stock_movements m JOIN dental_erp_stock_movements o ON o.source_id=m.source_id AND o.owner_id=m.owner_id AND o.movement_type='transfer_out' WHERE m.owner_id=? AND m.batch_id=? AND m.movement_type='transfer_in' LIMIT 1",[$ownerId,$sourceBatch])[0]??null;
                            if (!$transfer) break; $sourceBatch=$transfer['batch_id'];
                        }
                        if (!$origin || $origin['purchase_order_id']!==$invoice['purchase_order_id']) throw new DomainException('Партія має належати поставці цього рахунку постачальника.');
                        $credited=dentalErpRows($pdo,'SELECT COALESCE(SUM(credit_amount),0) amount FROM dental_erp_supplier_returns WHERE owner_id=? AND supplier_invoice_id=?',[$ownerId,$invoice['id']])[0]['amount'];
                        if ($cents>(int)round(((float)$invoice['amount']-(float)$credited)*100)) throw new DomainException('Повернення перевищує первісну вартість рахунку.');
                        if ($invoice['status']==='cancelled') throw new DomainException('Рахунок скасовано.');
                        $pdo->prepare('INSERT INTO dental_erp_supplier_returns(id,owner_id,supplier_invoice_id,batch_id,quantity,credit_amount,reason,created_by) VALUES(?,?,?,?,?,?,?,?)')->execute([$opId,$ownerId,$invoice['id'],$batch['id'],$qty,number_format($cents/100,2,'.',''),$reason,$userId]);
                        $move($batch,'write_off',$qty,'supplier_return',$opId,$reason);
                        // Preserve invoice face value and payments: credit notes reduce the remaining payable in reports/payments.
                        if ($cents) dentalErpPostJournal($pdo,$ownerId,$userId,'supplier_return',$opId,date('Y-m-d'),$reason,[['account'=>'payable','debit_cents'=>$cents],['account'=>'inventory','credit_cents'=>$cents]]);
                    } else {
                        $move($batch,'write_off',$qty,'write_off',$opId,$reason);
                        if ($cents) dentalErpPostJournal($pdo,$ownerId,$userId,'write_off',$opId,date('Y-m-d'),$reason,[['account'=>'material_expense','debit_cents'=>$cents],['account'=>'inventory','credit_cents'=>$cents]]);
                    }
                } else throw new DomainException('Невідома складська операція.');
            }
        }
        if ($action==='supplier_refund') {
            $invoice=$owned('dental_erp_supplier_invoices',(string)($data['invoice_id']??''));
            $credits=dentalErpRows($pdo,'SELECT COALESCE(SUM(credit_amount),0) amount FROM dental_erp_supplier_returns WHERE owner_id=? AND supplier_invoice_id=?',[$ownerId,$invoice['id']])[0]['amount'];
            $refunded=dentalErpRows($pdo,'SELECT COALESCE(SUM(amount),0) amount FROM dental_erp_supplier_refunds WHERE owner_id=? AND invoice_id=?',[$ownerId,$invoice['id']])[0]['amount'];
            $amount=dentalErpNumber($data['amount']??'',true); $cents=(int)round((float)$amount*100);
            $available=max(0,(int)round(((float)$credits-((float)$invoice['amount']-(float)$invoice['paid_amount'])-(float)$refunded)*100));
            if ($reason==='' || $cents>$available) throw new DomainException('Сума перевищує кошти до повернення постачальником або не вказана причина.');
            $pdo->prepare('INSERT INTO dental_erp_supplier_refunds(id,owner_id,invoice_id,amount,reason,created_by) VALUES(?,?,?,?,?,?)')->execute([$opId,$ownerId,$invoice['id'],$amount,$reason,$userId]);
            dentalErpPostJournal($pdo,$ownerId,$userId,'supplier_refund',$opId,date('Y-m-d'),$reason,[['account'=>'cash','debit_cents'=>$cents],['account'=>'payable','credit_cents'=>$cents]]);
        }
        if ($action==='reserve_stock') {
            $batch=$owned('dental_erp_inventory_batches',(string)($data['batch_id']??'')); $qty=dentalErpNumber($data['quantity']??'',true); $visit=(string)($data['appointment_id']??'');
            $visits=dentalErpRows($pdo,"SELECT t.id FROM tasks t JOIN task_clients tc ON tc.task_id=t.id JOIN clients c ON c.id=tc.client_id WHERE t.id=? AND c.created_by=? AND c.module='dentistry' AND t.is_client_appointment=1 AND t.status NOT IN ('done','cancelled','archived')",[$visit,$ownerId]);
            if (!$visits) throw new DomainException('Оберіть активний прийом цього простору.');
            if ($batch['is_blocked'] || ($batch['expiration_date'] && $batch['expiration_date']<date('Y-m-d'))) throw new DomainException('Не можна резервувати заблоковану або прострочену партію.');
            $used=dentalErpRows($pdo,"SELECT COALESCE(SUM(quantity),0) qty FROM dental_erp_stock_reservations WHERE owner_id=? AND batch_id=? AND status='active'",[$ownerId,$batch['id']])[0]['qty'];
            if ((float)$qty>(float)$batch['available_quantity']-(float)$used+0.000001) throw new DomainException('Недостатньо вільного залишку для резерву.');
            $pdo->prepare('INSERT INTO dental_erp_stock_reservations(id,owner_id,batch_id,appointment_id,quantity,created_by) VALUES(?,?,?,?,?,?)')->execute([$opId,$ownerId,$batch['id'],$visit,$qty,$userId]);
        }
        if ($action==='release_reservation') {
            $r=$owned('dental_erp_stock_reservations',(string)($data['reservation_id']??''));
            if ($r['status']==='active') $pdo->prepare("UPDATE dental_erp_stock_reservations SET status='released' WHERE id=? AND owner_id=?")->execute([$r['id'],$ownerId]);
        }
        if ($action==='save_procurement_settings') {
            $days=filter_var($data['lead_days']??7,FILTER_VALIDATE_INT); if ($days===false || $days<0 || $days>365) throw new DomainException('Строк поставки: від 0 до 365 днів.');
            $pdo->prepare('INSERT INTO dental_erp_procurement_settings(owner_id,auto_drafts,lead_days,updated_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE auto_drafts=VALUES(auto_drafts),lead_days=VALUES(lead_days),updated_by=VALUES(updated_by)')->execute([$ownerId,!empty($data['auto_drafts'])?1:0,$days,$userId]);
        }
        if ($action==='generate_purchase_drafts') {
            $settings=dentalErpRows($pdo,'SELECT auto_drafts FROM dental_erp_procurement_settings WHERE owner_id=?',[$ownerId])[0]??[];
            if (empty($settings['auto_drafts'])) throw new DomainException('Спочатку увімкніть створення заявок за рекомендаціями.');
            $groups=[];
            foreach (dentalErpRecommendations($pdo,$ownerId) as $r) if ($r['supplier_id']) $groups[$r['supplier_id']][]=$r;
            $created=0;
            foreach ($groups as $supplier=>$lines) {
                $existing=dentalErpRows($pdo,"SELECT id FROM dental_erp_purchase_orders WHERE owner_id=? AND supplier_id=? AND status IN ('draft','pending_approval')",[$ownerId,$supplier]);
                if ($existing) continue; // Never duplicate pending recommendations on a second click.
                $orderId=$id(); $total=0;
                foreach ($lines as &$line) { $item=$owned('dental_erp_inventory_items',$line['item_id']); $line['cost']=$item['purchase_unit_cost']; $total+=(int)round($line['suggested_quantity']*(float)$line['cost']*100); } unset($line);
                $pdo->prepare("INSERT INTO dental_erp_purchase_orders(id,owner_id,supplier_id,order_number,order_date,status,total_amount,notes,created_by) VALUES(?,?,?,?,CURRENT_DATE,'pending_approval',?,?,?)")->execute([$orderId,$ownerId,$supplier,'AUTO-'.strtoupper(substr($orderId,0,12)),number_format($total/100,2,'.',''),'За рекомендаціями складу; потребує погодження.',$userId]);
                foreach ($lines as $line) $pdo->prepare('INSERT INTO dental_erp_purchase_order_items(id,owner_id,purchase_order_id,inventory_item_id,quantity,unit_price,total_price) VALUES(?,?,?,?,?,?,?)')->execute([$id(),$ownerId,$orderId,$line['item_id'],dentalErpNumber($line['suggested_quantity'],true),$line['cost'],number_format(round($line['suggested_quantity']*(float)$line['cost']*100)/100,2,'.','')]);
                $created++;
            } $result['created']=$created;
        }
        if ($action==='create_expense_request') {
            $category=$owned('dental_erp_expense_categories',(string)($data['category_id']??'')); $supplier=(string)($data['supplier_id']??''); if ($supplier!=='') $owned('dental_erp_suppliers',$supplier);
            $amount=dentalErpNumber($data['amount']??'',true); $date=dentalErpDate($data['expense_date']??'');
            if ($reason==='') throw new DomainException('Вкажіть опис витрати.'); $recurring=null;
            if (!empty($data['recurring'])) {
                $months=filter_var($data['interval_months']??1,FILTER_VALIDATE_INT); if (!$months || $months<1 || $months>12) throw new DomainException('Інтервал: 1–12 місяців.');
                $recurring=$id(); $next=(new DateTimeImmutable($date))->modify('first day of +'.$months.' month'); $day=min((int)substr($date,8,2),(int)$next->format('t')); $next=$next->setDate((int)$next->format('Y'),(int)$next->format('m'),$day)->format('Y-m-d');
                $pdo->prepare('INSERT INTO dental_erp_recurring_expenses(id,owner_id,category_id,supplier_id,description,amount,next_due,interval_months,created_by) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$recurring,$ownerId,$category['id'],$supplier?:null,$reason,$amount,$next,$months,$userId]);
            }
            $pdo->prepare('INSERT INTO dental_erp_expense_requests(id,owner_id,category_id,supplier_id,description,amount,expense_date,recurring_id,created_by) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$opId,$ownerId,$category['id'],$supplier?:null,$reason,$amount,$date,$recurring,$userId]);
        }
        if ($action==='generate_recurring_expenses') {
            $until=dentalErpDate($data['until']??date('Y-m-d')); if ($until>date('Y-m-d')) throw new DomainException('Формуйте регулярні витрати до поточної дати.');
            $created=0; $recurring=dentalErpRows($pdo,'SELECT * FROM dental_erp_recurring_expenses WHERE owner_id=? AND is_active=1 AND next_due<=? FOR UPDATE',[$ownerId,$until]);
            foreach ($recurring as $r) {
                $due=$r['next_due']; $iterations=0;
                while ($due<=$until && $iterations++<120) {
                    $pdo->prepare('INSERT IGNORE INTO dental_erp_expense_requests(id,owner_id,category_id,supplier_id,description,amount,expense_date,recurring_id,created_by) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$id(),$ownerId,$r['category_id'],$r['supplier_id'],$r['description'],$r['amount'],$due,$r['id'],$userId]); $created++;
                    $base=new DateTimeImmutable($due); $next=$base->modify('first day of +'.(int)$r['interval_months'].' month'); $due=$next->setDate((int)$next->format('Y'),(int)$next->format('m'),min((int)$base->format('d'),(int)$next->format('t')))->format('Y-m-d');
                }
                $pdo->prepare('UPDATE dental_erp_recurring_expenses SET next_due=? WHERE id=? AND owner_id=?')->execute([$due,$r['id'],$ownerId]);
            } $result['created']=$created;
        }
        if ($action==='stop_recurring_expense') {
            $r=$owned('dental_erp_recurring_expenses',(string)($data['recurring_id']??'')); $pdo->prepare('UPDATE dental_erp_recurring_expenses SET is_active=0 WHERE id=? AND owner_id=?')->execute([$r['id'],$ownerId]);
        }
        if ($action==='approve_expense' || $action==='reject_expense') {
            $r=$owned('dental_erp_expense_requests',(string)($data['request_id']??'')); if ($r['status']!=='pending') throw new DomainException('Витрата вже розглянута.');
            if ($action==='approve_expense') {
                $pdo->prepare("INSERT INTO dental_erp_expenses(id,owner_id,category_id,supplier_id,amount,description,expense_date,payment_status,created_by) VALUES(?,?,?,?,?,?,?,'unpaid',?)")->execute([$opId,$ownerId,$r['category_id'],$r['supplier_id'],$r['amount'],$r['description'],$r['expense_date'],$userId]);
                $cents=(int)round((float)$r['amount']*100);
                dentalErpPostJournal($pdo,$ownerId,$userId,'expense',$opId,$r['expense_date'],$r['description'],[['account'=>'operating_expense','debit_cents'=>$cents],['account'=>'payable','credit_cents'=>$cents]]);
            }
            $pdo->prepare('UPDATE dental_erp_expense_requests SET status=?,expense_id=?,approved_by=? WHERE id=? AND owner_id=?')->execute([$action==='approve_expense'?'approved':'rejected',$action==='approve_expense'?$opId:null,$userId,$r['id'],$ownerId]);
        }
        if ($action==='record_procedure_cost') {
            $performed=(string)($data['performed_service_id']??'');
            $rows=dentalErpRows($pdo,'SELECT s.id FROM dental_performed_services s JOIN clients c ON c.id=s.patient_id WHERE s.id=? AND c.created_by=? FOR UPDATE',[$performed,$ownerId]); if (!$rows) throw new DomainException('Виконану послугу не знайдено.');
            if ($reason==='') throw new DomainException('Вкажіть підставу прямих витрат.');
            $lab=dentalErpNumber($data['laboratory_cost']??0); $labor=dentalErpNumber($data['labor_cost']??0); $other=dentalErpNumber($data['other_cost']??0);
            $pdo->prepare('INSERT INTO dental_erp_procedure_costs(id,owner_id,performed_service_id,laboratory_cost,labor_cost,other_cost,note,created_by) VALUES(?,?,?,?,?,?,?,?)')->execute([$opId,$ownerId,$performed,$lab,$labor,$other,$reason,$userId]);
            // Labor is an allocation of accrued payroll, not an additional expense.
            $cents=(int)round(((float)$lab+(float)$other)*100);
            if ($cents) dentalErpPostJournal($pdo,$ownerId,$userId,'procedure_direct_cost',$opId,date('Y-m-d'),$reason,[['account'=>'direct_expense','debit_cents'=>$cents],['account'=>'payable','credit_cents'=>$cents]]);
        }
        if ($action==='pay_procedure_cost') {
            $c=$owned('dental_erp_procedure_costs',(string)($data['cost_id']??'')); $amount=dentalErpNumber($data['amount']??'',true);
            $paid=dentalErpRows($pdo,'SELECT COALESCE(SUM(amount),0) amount FROM dental_erp_procedure_cost_payments WHERE owner_id=? AND cost_id=?',[$ownerId,$c['id']])[0]['amount'];
            if ((float)$amount>(float)$c['laboratory_cost']+(float)$c['other_cost']-(float)$paid+0.000001) throw new DomainException('Оплата перевищує залишок прямих витрат.');
            $pdo->prepare('INSERT INTO dental_erp_procedure_cost_payments(id,owner_id,cost_id,amount,created_by) VALUES(?,?,?,?,?)')->execute([$opId,$ownerId,$c['id'],$amount,$userId]);
            $cents=(int)round((float)$amount*100); dentalErpPostJournal($pdo,$ownerId,$userId,'procedure_cost_payment',$opId,date('Y-m-d'),'Оплата прямих витрат процедури',[['account'=>'payable','debit_cents'=>$cents],['account'=>'cash','credit_cents'=>$cents]]);
        }
        if ($action==='post_depreciation') {
            $e=$owned('dental_erp_equipment',(string)($data['equipment_id']??'')); $date=dentalErpDate($data['period_date']??''); $date=substr($date,0,7).'-01';
            if (!$e['purchase_date'] || !$e['useful_life_months'] || $e['status']==='written_off') throw new DomainException('Для амортизації вкажіть дату придбання і строк використання обладнання.');
            $start=(new DateTimeImmutable($e['purchase_date']))->modify('first day of next month');
            if ($date<$start->format('Y-m-d') || $date>date('Y-m-01')) throw new DomainException('Амортизація починається з місяця після придбання і не проводиться наперед.');
            $posted=dentalErpRows($pdo,'SELECT COALESCE(SUM(amount),0) amount,COUNT(*) months FROM dental_erp_depreciation WHERE owner_id=? AND equipment_id=?',[$ownerId,$e['id']])[0];
            $next=$start->modify('+'.(int)$posted['months'].' months')->format('Y-m-d'); if ($date!==$next) throw new DomainException('Проводьте амортизацію послідовно; наступний місяць: '.substr($next,0,7));
            $remaining=(int)round(((float)$e['purchase_cost']-(float)$posted['amount'])*100); $cents=min($remaining,(int)round((float)$e['purchase_cost']*100/(int)$e['useful_life_months']));
            if ((int)$posted['months']+1===(int)$e['useful_life_months']) $cents=$remaining;
            if ($cents<=0 || (int)$posted['months']>=(int)$e['useful_life_months']) throw new DomainException('Обладнання повністю амортизоване.');
            $pdo->prepare('INSERT INTO dental_erp_depreciation(id,owner_id,equipment_id,period_date,amount,created_by) VALUES(?,?,?,?,?,?)')->execute([$opId,$ownerId,$e['id'],$date,number_format($cents/100,2,'.',''),$userId]);
            dentalErpPostJournal($pdo,$ownerId,$userId,'equipment_depreciation',$opId,$date,'Амортизація · '.$e['name'],[['account'=>'depreciation_expense','debit_cents'=>$cents],['account'=>'depreciation','credit_cents'=>$cents]]);
        }
        if ($action==='payroll_adjustment') {
            $a=$owned('dental_erp_payroll_accruals',(string)($data['accrual_id']??'')); $type=(string)($data['adjustment_type']??''); $amount=dentalErpNumber($data['amount']??'',true); $cents=(int)round((float)$amount*100);
            if (!in_array($type,['bonus','deduction'],true) || $reason==='') throw new DomainException('Оберіть тип і вкажіть причину коригування.');
            $p=$owned('dental_erp_payroll_periods',$a['period_id']); if ($p['status']==='closed') throw new DomainException('Зарплатний період закрито.');
            $next=(int)round((float)$a['accrued_amount']*100)+($type==='bonus'?$cents:-$cents); if ($next<(int)round((float)$a['paid_amount']*100)) throw new DomainException('Після утримання сума не може бути меншою за виплачену.');
            $pdo->prepare('INSERT INTO dental_erp_payroll_adjustments(id,owner_id,accrual_id,adjustment_type,amount,reason,created_by) VALUES(?,?,?,?,?,?,?)')->execute([$opId,$ownerId,$a['id'],$type,$amount,$reason,$userId]);
            $pdo->prepare('UPDATE dental_erp_payroll_accruals SET accrued_amount=? WHERE id=? AND owner_id=?')->execute([number_format($next/100,2,'.',''),$a['id'],$ownerId]);
            dentalErpPostJournal($pdo,$ownerId,$userId,'payroll_adjustment',$opId,date('Y-m-d'),$reason,[['account'=>$type==='bonus'?'labor_expense':'payroll_payable','debit_cents'=>$cents],['account'=>$type==='bonus'?'payroll_payable':'labor_expense','credit_cents'=>$cents]]);
        }
        if ($action==='reverse_journal') {
            $j=$owned('dental_erp_accounting_journals',(string)($data['journal_id']??'')); if ($j['status']!=='posted' || $reason==='') throw new DomainException('Вкажіть причину; проведення можна сторнувати один раз.');
            $date=dentalErpDate($data['entry_date']??date('Y-m-d')); $lines=[]; $accounts=dentalErpLedgerAccounts($pdo,$ownerId); $lookup=array_flip($accounts);
            foreach (dentalErpRows($pdo,'SELECT * FROM dental_erp_accounting_entries WHERE owner_id=? AND journal_id=?',[$ownerId,$j['id']]) as $line) {
                if (!isset($lookup[$line['account_id']])) throw new DomainException('Рахунок не підтримує автоматичне сторно.');
                $lines[]=['account'=>$lookup[$line['account_id']],'debit_cents'=>(int)round((float)$line['credit']*100),'credit_cents'=>(int)round((float)$line['debit']*100)];
            }
            dentalErpPostJournal($pdo,$ownerId,$userId,'journal_reversal',$j['id'],$date,'Сторно: '.$reason,$lines);
            $pdo->prepare("UPDATE dental_erp_accounting_journals SET status='reversed' WHERE id=? AND owner_id=?")->execute([$j['id'],$ownerId]);
        }
        if ($action==='upload_expense_document') {
            $expense=$owned('dental_erp_expenses',(string)($data['expense_id']??'')); $file=$_FILES['file']??null;
            if (!$file || $file['error']!==UPLOAD_ERR_OK || $file['size']>20*1024*1024) throw new DomainException('Оберіть файл до 20 МБ.');
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']); if (!in_array($mime,['application/pdf','image/jpeg','image/png','image/webp'],true)) throw new DomainException('Дозволено PDF, JPG, PNG та WebP.');
            if (!is_dir($erpPrivateFiles) && !mkdir($erpPrivateFiles,0700,true) && !is_dir($erpPrivateFiles)) throw new RuntimeException('Не вдалося створити сховище.');
            $storedFile=$erpPrivateFiles.DIRECTORY_SEPARATOR.$opId;
            if (!move_uploaded_file($file['tmp_name'],$storedFile)) throw new RuntimeException('Не вдалося зберегти файл.');
            $pdo->prepare('INSERT INTO dental_erp_expense_documents(id,owner_id,expense_id,original_name,mime_type,storage_name,file_size,created_by) VALUES(?,?,?,?,?,?,?,?)')->execute([$opId,$ownerId,$expense['id'],mb_substr(basename($file['name']),0,255),$mime,$opId,$file['size'],$userId]);
        }
        if ($action==='cancel_purchase_order') {
            $o=$owned('dental_erp_purchase_orders',(string)($data['purchase_order_id']??'')); if (!in_array($o['status'],['draft','pending_approval','approved','ordered'],true)) throw new DomainException('Не можна скасувати отримане замовлення.');
            $pdo->prepare("UPDATE dental_erp_purchase_orders SET status='cancelled' WHERE id=? AND owner_id=?")->execute([$o['id'],$ownerId]);
        }
        $audit('erp_operation',$opId,$action,['request_key'=>$key,'reason'=>$reason,'result'=>$result]);
        $pdo->prepare('INSERT INTO dental_erp_operations(id,owner_id,action,request_key,result,created_by) VALUES(?,?,?,?,?,?)')->execute([$opId,$ownerId,$action,$key,json_encode($result,JSON_THROW_ON_ERROR),$userId]);
        $pdo->commit(); jsonResponse(true,'Операцію виконано.',['result'=>$result]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack(); if ($storedFile && is_file($storedFile)) unlink($storedFile);
        if (!($e instanceof DomainException)) error_log('Dentistry ERP '.$action.': '.$e->getMessage());
        jsonResponse(false,$e instanceof DomainException?$e->getMessage():'Операцію не збережено. Перевірте дані та повторіть запит.',[], $e instanceof DomainException?422:500);
    }
}
