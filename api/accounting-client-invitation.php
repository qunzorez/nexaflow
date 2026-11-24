<?php
declare(strict_types=1);
require __DIR__.'/db.php';
require_once __DIR__.'/plans.php';
require_once __DIR__.'/mailer.php';
require_once __DIR__.'/verification.php';
require_once __DIR__.'/rate-limit.php';
require_once __DIR__.'/accounting-client-invitations-lib.php';
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
$pdo=db();clientInvitationSchema($pdo);
$data=body();$action=(string)($data['action']??($_GET['action']??''));
function invitationRow(PDO $pdo,string $token,bool $lock=false): ?array {
 if(!preg_match('/^[a-f0-9]{64}$/',$token))return null;
 $q=$pdo->prepare('SELECT i.*,c.name client_name FROM accounting_client_invitations i JOIN clients c ON c.id=i.client_id AND c.module=\'accounting\' WHERE i.token_hash=? LIMIT 1'.($lock?' FOR UPDATE':''));
 $q->execute([hash('sha256',$token)]);return $q->fetch()?:null;
}
if($action==='inspect'){
 enforcePublicRateLimit($pdo,'client_invite_inspect','',60,30,900);
 $row=invitationRow($pdo,(string)($_GET['token']??''));
 if(!$row)jsonResponse(false,'Запрошення недійсне.',['code'=>'invalid'],404);
 $status=$row['status']==='pending'&&strtotime($row['expires_at'])<=time()?'expired':$row['status'];
 jsonResponse(true,'Статус запрошення.',['status'=>$status,'client_name'=>$row['client_name'],'email'=>$status==='pending'?$row['email']:null]);
}
if($action==='register'){
 $token=(string)($data['token']??'');$name=trim((string)($data['name']??''));$email=mb_strtolower(trim((string)($data['email']??'')));$password=(string)($data['password']??'');
 enforcePublicRateLimit($pdo,'client_register',$email,10,3,3600);
 $row=invitationRow($pdo,$token);
 if(!$row||$row['status']!=='pending'||strtotime($row['expires_at'])<=time())jsonResponse(false,'Запрошення недійсне або прострочене.',[],410);
 if(!hash_equals(mb_strtolower($row['email']),$email))jsonResponse(false,'Email не збігається з адресою запрошення.',[],422);
 if(mb_strlen($name)<2||strlen($password)<8||$password!==($data['password_confirmation']??''))jsonResponse(false,'Перевірте ім’я та пароль (щонайменше 8 символів).',[],422);
 $existing=$pdo->prepare('SELECT id FROM users WHERE LOWER(email)=? LIMIT 1');$existing->execute([$email]);
 if($existing->fetchColumn())jsonResponse(true,'Якщо акаунт уже існує, увійдіть із цією адресою. Інакше перевірте лист підтвердження.');
 $id=bin2hex(random_bytes(16));
 $pdo->prepare("INSERT INTO users(id,name,email,password_hash,role,requested_role,email_notifications_enabled,email_verified_at) VALUES(?,?,?,?, 'user','user',1,NULL)")->execute([$id,$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
 $pdo->prepare('INSERT INTO accounting_client_registrants(user_id) VALUES(?)')->execute([$id]);
 $sent=issueEmailVerification($pdo,$id,$email,$name,$token);
 jsonResponse(true,$sent?'Акаунт створено. Підтвердьте email, потім увійдіть за посиланням із запрошення.':'Акаунт створено. Повторіть надсилання листа підтвердження в налаштуваннях.',['requires_verification'=>true]);
}
if($action==='accept'){
 if(empty($_SESSION['user_id']))jsonResponse(false,'Увійдіть, щоб прийняти запрошення.',['code'=>'login_required'],401);
 $uid=(string)$_SESSION['user_id'];$pdo->beginTransaction();
 try{
  $row=invitationRow($pdo,(string)($data['token']??''),true);
  if(!$row||$row['status']!=='pending'||strtotime($row['expires_at'])<=time()){$pdo->rollBack();jsonResponse(false,'Запрошення недійсне або прострочене.',[],410);}
  $q=$pdo->prepare('SELECT email,email_verified_at FROM users WHERE id=?');$q->execute([$uid]);$user=$q->fetch();
  if(!$user||!hash_equals(mb_strtolower($row['email']),mb_strtolower($user['email']))) {$pdo->rollBack();jsonResponse(false,'Увійдіть із запрошеною адресою email.',[],403);}
  if(!$user['email_verified_at']){$pdo->rollBack();jsonResponse(false,'Підтвердьте email у налаштуваннях профілю.', ['code'=>'email_verification_required'],403);}
  $pdo->prepare('INSERT IGNORE INTO accounting_client_portal_access(owner_id,client_id,user_id,granted_by) VALUES(?,?,?,?)')->execute([$row['owner_id'],$row['client_id'],$uid,$row['invited_by']]);
  $pdo->prepare("UPDATE accounting_client_invitations SET status='accepted',accepted_by=?,accepted_at=NOW() WHERE id=?")->execute([$uid,$row['id']]);
  clientInvitationAudit($pdo,$row['id'],$uid,'accepted');$pdo->commit();
  jsonResponse(true,'Доступ до кабінету надано.',['client_id'=>$row['client_id']]);
 }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
if(empty($_SESSION['user_id']))jsonResponse(false,'Потрібна авторизація.',[],401);
$uid=(string)$_SESSION['user_id'];$q=$pdo->prepare('SELECT role FROM users WHERE id=?');$q->execute([$uid]);$role=(string)$q->fetchColumn();$ctx=workspacePlan($pdo,$uid,$role);
if(!in_array($ctx['workspace_role']??'', ['manager','admin','owner'],true)||($ctx['module']??'')!=='accounting')jsonResponse(false,'Доступ заборонено.',[],403);
$owner=(string)$ctx['owner_id'];$client=(string)($data['client_id']??($_GET['client_id']??''));$members=array_values(array_unique(array_map('strval',$ctx['member_ids']??[$owner])));
$q=$pdo->prepare("SELECT name FROM clients WHERE id=? AND module='accounting' AND created_by IN (".implode(',',array_fill(0,count($members),'?')).')');$q->execute(array_merge([$client],$members));$clientName=$q->fetchColumn();
if(!$clientName)jsonResponse(false,'Компанію не знайдено.',[],404);
if($action==='list'){$q=$pdo->prepare('SELECT id,email,status,expires_at,created_at,accepted_at,updated_at FROM accounting_client_invitations WHERE owner_id=? AND client_id=? ORDER BY created_at DESC');$q->execute([$owner,$client]);jsonResponse(true,'Запрошення завантажено.',['invitations'=>$q->fetchAll()]);}
if($action==='invite'||$action==='resend'){
 $email=mb_strtolower(trim((string)($data['email']??'')));if(!filter_var($email,FILTER_VALIDATE_EMAIL))jsonResponse(false,'Введіть коректний email.',[],422);
 $q=$pdo->prepare("SELECT created_at FROM accounting_client_invitations WHERE owner_id=? AND client_id=? AND email=? AND status='pending' ORDER BY created_at DESC LIMIT 1");$q->execute([$owner,$client,$email]);$last=$q->fetchColumn();if($last&&strtotime($last)>time()-60)jsonResponse(false,'Повторне надсилання доступне через хвилину.',[],429);
 $token=bin2hex(random_bytes(32));$id=bin2hex(random_bytes(16));
 $link=appUrl('/client/register').'?token='.urlencode($token);
 $pdo->beginTransaction();try{
  $pdo->prepare("INSERT INTO accounting_client_invitations(id,owner_id,client_id,email,token_hash,invited_by,expires_at) VALUES(?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 7 DAY))")->execute([$id,$owner,$client,$email,hash('sha256',$token),$uid]);
  $sent=sendProjectEmail($email,'Запрошення до кабінету клієнта NexaFlow',"Вас запросили до кабінету компанії {$clientName}. Перейдіть за посиланням протягом 7 днів:\n{$link}\nЯкщо ви не очікували запрошення, проігноруйте лист.");
  if(!$sent){$pdo->rollBack();jsonResponse(false,'Не вдалося надіслати запрошення. Попереднє посилання залишається чинним.',[],503);}
  $pdo->prepare("UPDATE accounting_client_invitations SET status='revoked' WHERE owner_id=? AND client_id=? AND email=? AND status='pending' AND id<>?")->execute([$owner,$client,$email,$id]);
  clientInvitationAudit($pdo,$id,$uid,$action==='resend'?'resent':'created');$pdo->commit();
 }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
 jsonResponse(true,'Запрошення надіслано.',['id'=>$id]);
}
if($action==='revoke'){$id=(string)($data['id']??'');$q=$pdo->prepare("UPDATE accounting_client_invitations SET status='revoked' WHERE id=? AND owner_id=? AND client_id=? AND status='pending'");$q->execute([$id,$owner,$client]);if(!$q->rowCount())jsonResponse(false,'Чинне запрошення не знайдено.',[],404);clientInvitationAudit($pdo,$id,$uid,'revoked');jsonResponse(true,'Запрошення відкликано.');}
jsonResponse(false,'Невідома дія.',[],422);
