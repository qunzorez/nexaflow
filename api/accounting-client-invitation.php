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
if($action==='list'){$q=$pdo->prepare("SELECT id,email,status,expires_at,created_at,accepted_at,updated_at FROM accounting_client_invitations WHERE owner_id=? AND client_id=? AND status<>'revoked' ORDER BY created_at DESC");$q->execute([$owner,$client]);jsonResponse(true,'Запрошення завантажено.',['invitations'=>$q->fetchAll()]);}
if($action==='invite'||$action==='resend'){
 $email=mb_strtolower(trim((string)($data['email']??'')));if(!filter_var($email,FILTER_VALIDATE_EMAIL))jsonResponse(false,'Введіть коректний email.',[],422);
 $q=$pdo->prepare("SELECT created_at FROM accounting_client_invitations WHERE owner_id=? AND client_id=? AND email=? AND status='pending' ORDER BY created_at DESC LIMIT 1");$q->execute([$owner,$client,$email]);$last=$q->fetchColumn();if($last&&strtotime($last)>time()-60)jsonResponse(false,'Повторне надсилання доступне через хвилину.',[],429);
 $token=bin2hex(random_bytes(32));$id=bin2hex(random_bytes(16));
 $link=appUrl('/client/register').'?token='.urlencode($token);
 $safeLink=htmlspecialchars($link,ENT_QUOTES,'UTF-8');
 $safeClient=htmlspecialchars((string)$clientName,ENT_QUOTES,'UTF-8');
 $safeLogo=htmlspecialchars(appUrl('/assets/logo-color-text.png'),ENT_QUOTES,'UTF-8');
 $plain="Вас запросили до кабінету компанії «{$clientName}» у NexaFlow.\n\nПрийміть запрошення протягом 7 днів:\n{$link}\n\nУ кабінеті ви зможете переглядати запити документів, надсилати файли та бачити рахунки компанії.\n\nЯкщо ви не очікували запрошення, просто проігноруйте цей лист.";
 $html='<!doctype html><html lang="uk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Запрошення до NexaFlow</title></head><body style="margin:0;padding:0;background:#f2f7f4;font-family:Arial,Helvetica,sans-serif;color:#17201e"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f2f7f4;padding:36px 14px"><tr><td align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;background:#fff;border:1px solid #dce9e1;border-radius:20px;overflow:hidden"><tr><td style="height:5px;background:#168b68;font-size:0;line-height:0">&nbsp;</td></tr><tr><td style="padding:32px 36px 34px"><img src="'.$safeLogo.'" width="166" alt="NexaFlow" style="display:block;width:166px;max-width:60%;height:auto;margin:0 0 30px;border:0"><div style="display:inline-block;padding:6px 10px;background:#e9f6ef;border-radius:999px;color:#168b68;font-size:10px;font-weight:bold;letter-spacing:1.2px">КАБІНЕТ КЛІЄНТА</div><h1 style="margin:16px 0 10px;font-size:25px;line-height:1.25;color:#17201e">Вас запросили до кабінету</h1><p style="margin:0 0 10px;font-size:15px;line-height:1.6;color:#34423b">Компанія <strong>«'.$safeClient.'»</strong> надала вам доступ до свого клієнтського кабінету NexaFlow.</p><p style="margin:0 0 24px;font-size:14px;line-height:1.7;color:#718078">Переглядайте запити документів, надсилайте файли та стежте за рахунками в одному місці.</p><table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" style="border-radius:10px;background:#168b68"><a href="'.$safeLink.'" style="display:inline-block;padding:14px 22px;border:1px solid #168b68;border-radius:10px;color:#fff;text-decoration:none;font-size:14px;font-weight:bold">Прийняти запрошення&nbsp; →</a></td></tr></table><p style="margin:22px 0 7px;font-size:12px;line-height:1.6;color:#718078">Посилання дійсне 7 днів. Якщо кнопка не відкривається, скопіюйте адресу:</p><p style="margin:0;word-break:break-all;font-size:11px;line-height:1.6"><a href="'.$safeLink.'" style="color:#168b68;text-decoration:underline">'.$safeLink.'</a></p><div style="height:1px;margin:24px 0 16px;background:#e5eee8"></div><p style="margin:0;font-size:11px;line-height:1.6;color:#98a59e">Якщо ви не очікували запрошення, просто проігноруйте цей лист.</p></td></tr></table><p style="margin:16px 0 0;font-size:10px;color:#9aa8a1">NexaFlow · Кабінет клієнта</p></td></tr></table></body></html>';
 $pdo->beginTransaction();try{
  $pdo->prepare("INSERT INTO accounting_client_invitations(id,owner_id,client_id,email,token_hash,invited_by,expires_at) VALUES(?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 7 DAY))")->execute([$id,$owner,$client,$email,hash('sha256',$token),$uid]);
  $sent=sendProjectEmail($email,'Запрошення до кабінету клієнта NexaFlow',$plain,$html);
  if(!$sent){$pdo->rollBack();jsonResponse(false,'Не вдалося надіслати запрошення. Попереднє посилання залишається чинним.',[],503);}
  $pdo->prepare("UPDATE accounting_client_invitations SET status='revoked' WHERE owner_id=? AND client_id=? AND email=? AND status='pending' AND id<>?")->execute([$owner,$client,$email,$id]);
  clientInvitationAudit($pdo,$id,$uid,$action==='resend'?'resent':'created');$pdo->commit();
 }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
 jsonResponse(true,'Запрошення надіслано.',['id'=>$id]);
}
if($action==='revoke'){$id=(string)($data['id']??'');$q=$pdo->prepare("UPDATE accounting_client_invitations SET status='revoked' WHERE id=? AND owner_id=? AND client_id=? AND status='pending'");$q->execute([$id,$owner,$client]);if(!$q->rowCount())jsonResponse(false,'Чинне запрошення не знайдено.',[],404);clientInvitationAudit($pdo,$id,$uid,'revoked');jsonResponse(true,'Запрошення відкликано.');}
jsonResponse(false,'Невідома дія.',[],422);
