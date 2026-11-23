<?php
declare(strict_types=1);
require_once __DIR__ . '/mailer.php';

function issueEmailVerification(PDO $pdo, string $userId, string $email, string $name): bool
{
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('INSERT INTO email_verification_tokens (id, user_id, token_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))')->execute([bin2hex(random_bytes(16)), $userId, hash('sha256', $token)]);
    $link = appUrl('/verify-email') . '?token=' . urlencode($token);
    $logo = appUrl('/assets/logo-color-text.png');
    $subject = 'Підтвердіть email у NexaFlow';
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $safeLogo = htmlspecialchars($logo, ENT_QUOTES, 'UTF-8');
    $message = "Вітаємо, {$name}!\n\nПідтвердіть email, щоб отримати повний доступ до NexaFlow:\n{$link}\n\nПосилання дійсне 24 години. Якщо ви не створювали акаунт, просто проігноруйте цей лист.";
    $html = '<!doctype html><html lang="uk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Підтвердження email · NexaFlow</title></head><body style="margin:0;padding:0;background:#f2f7f4;font-family:Arial,Helvetica,sans-serif;color:#17201e"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f2f7f4;padding:36px 14px"><tr><td align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;background:#fff;border:1px solid #dce9e1;border-radius:20px;overflow:hidden"><tr><td style="height:5px;background:#168b68;font-size:0;line-height:0">&nbsp;</td></tr><tr><td style="padding:32px 36px 34px"><img src="' . $safeLogo . '" width="166" alt="NexaFlow" style="display:block;width:166px;max-width:60%;height:auto;margin:0 0 30px;border:0"><div style="display:inline-block;padding:6px 10px;background:#e9f6ef;border-radius:999px;color:#168b68;font-size:10px;font-weight:bold;letter-spacing:1.2px">АКТИВАЦІЯ АКАУНТА</div><h1 style="margin:16px 0 10px;font-size:25px;line-height:1.25;color:#17201e">Підтвердіть свою пошту</h1><p style="margin:0 0 10px;font-size:15px;line-height:1.6;color:#34423b">Вітаємо, <strong>' . $safeName . '</strong>!</p><p style="margin:0 0 24px;font-size:14px;line-height:1.7;color:#718078">Підтвердіть email, щоб отримати повний доступ і перейти до робочого простору NexaFlow.</p><table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" style="border-radius:10px;background:#168b68"><a href="' . $safeLink . '" style="display:inline-block;padding:14px 22px;border:1px solid #168b68;border-radius:10px;color:#fff;text-decoration:none;font-size:14px;font-weight:bold">Підтвердити email&nbsp; →</a></td></tr></table><p style="margin:22px 0 7px;font-size:12px;line-height:1.6;color:#718078">Посилання дійсне 24 години. Якщо кнопка не відкривається, скопіюйте посилання:</p><p style="margin:0;word-break:break-all;font-size:11px;line-height:1.6"><a href="' . $safeLink . '" style="color:#168b68;text-decoration:underline">' . $safeLink . '</a></p><div style="height:1px;margin:24px 0 16px;background:#e5eee8"></div><p style="margin:0;font-size:11px;line-height:1.6;color:#98a59e">Якщо ви не створювали акаунт NexaFlow, просто проігноруйте цей лист.</p></td></tr></table><p style="margin:16px 0 0;font-size:10px;color:#9aa8a1">NexaFlow · Один простір для роботи команди</p></td></tr></table></body></html>';
    return sendProjectEmail($email, $subject, $message, $html);
}
