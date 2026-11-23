<?php
declare(strict_types=1);
require_once __DIR__ . '/api/maintenance.php';
try {
    $pdo = maintenanceConnection();
    $state = maintenanceState($pdo);
    $maintenance = (bool)$state['enabled'];
    $admin = $maintenance && maintenanceAdmin($pdo);
} catch (Throwable $error) {
    $maintenance = true; $admin = false;
    $state = ['title' => 'Технічні роботи', 'message' => 'Ми оновлюємо NexaFlow. Спробуйте зайти трохи пізніше.', 'estimated_end' => null, 'note' => null];
}
header('Cache-Control: no-store');
if (!$maintenance || $admin) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/dist/index.html');
    exit;
}
http_response_code(503);
header('Retry-After: 300');
header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: text/html; charset=utf-8');
function maintenanceText(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?><!doctype html><html lang="uk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= maintenanceText((string)$state['title']) ?> · NexaFlow</title><style>
:root{font-family:Inter,system-ui,sans-serif;color:#173126;background:#f3f8f5}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:radial-gradient(circle at 80% 15%,#dcf2e6,transparent 35%),#f3f8f5}.card{width:min(620px,100%);padding:clamp(28px,5vw,52px);border:1px solid #d8e9df;border-radius:24px;background:#fff;box-shadow:0 24px 70px #24563a16}.brand{display:flex;align-items:center;gap:10px;font-weight:900;letter-spacing:.08em;color:#168765}.mark{display:grid;place-items:center;width:36px;height:36px;border-radius:11px;background:#168765;color:#fff;font-size:24px}.eyebrow{margin-top:38px;color:#168765;font-size:11px;font-weight:800;letter-spacing:.15em;text-transform:uppercase}h1{font-size:clamp(28px,5vw,42px);line-height:1.12;letter-spacing:-.04em;margin:12px 0 18px}p{color:#61766b;line-height:1.65}.info{margin:26px 0;padding:17px 19px;border-radius:14px;background:#eef8f2;color:#286347}.info strong{display:block;margin-bottom:4px}.info span{font-size:14px}.actions{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-top:28px}button{border:0;border-radius:11px;background:#168765;color:#fff;padding:13px 20px;font:700 14px Inter,system-ui,sans-serif;cursor:pointer}button:hover{background:#117052}button:focus-visible,input:focus-visible{outline:3px solid #67be92;outline-offset:2px}.status{font-size:13px;color:#61766b}.admin{margin-top:32px;border-top:1px solid #e2ede6;padding-top:18px}.admin summary{cursor:pointer;color:#5d7568;font-size:13px}.admin form{display:grid;gap:10px;margin-top:15px}.admin input{width:100%;padding:12px;border:1px solid #cdded2;border-radius:10px;font:inherit}.admin button{justify-self:start}footer{margin-top:30px;color:#8ba197;font-size:12px}
</style></head><body><main class="card"><div class="brand"><span class="mark">N</span>NEXAFLOW</div><div class="eyebrow">Оновлення сервісу</div><h1><?= maintenanceText((string)$state['title']) ?></h1><p><?= nl2br(maintenanceText((string)$state['message'])) ?></p><div class="info"><strong>Ваші дані збережені</strong><span>Після завершення робіт ви зможете продовжити роботу.</span></div><?php if (!empty($state['estimated_end'])): ?><p><strong>Орієнтовне завершення:</strong> <time><?= maintenanceText((string)$state['estimated_end']) ?></time></p><?php endif; ?><?php if (!empty($state['note'])): ?><p><?= maintenanceText((string)$state['note']) ?></p><?php endif; ?><div class="actions"><button id="retry" type="button">Перевірити стан</button><span class="status" id="status" role="status"></span></div><details class="admin"><summary>Вхід адміністратора</summary><form id="admin-login"><input type="email" name="email" autocomplete="username" aria-label="Email" placeholder="Email" required><input type="password" name="password" autocomplete="current-password" aria-label="Пароль" placeholder="Пароль" required><button>Увійти</button><span class="status" id="login-status" role="status"></span></form></details><footer>Дякуємо за розуміння.</footer></main><script>
document.getElementById('retry').addEventListener('click',async()=>{const el=document.getElementById('status');el.textContent='Перевіряємо…';try{const r=await fetch('/api/maintenance-status.php',{cache:'no-store'});const d=await r.json();if(d.ok&&!d.enabled){location.reload();return}el.textContent='Роботи ще тривають.'}catch{el.textContent='Не вдалося перевірити стан. Спробуйте пізніше.'}});
document.getElementById('admin-login').addEventListener('submit',async e=>{e.preventDefault();const el=document.getElementById('login-status');el.textContent='Перевіряємо доступ…';const f=new FormData(e.currentTarget);try{const r=await fetch('/api/login.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({email:f.get('email'),password:f.get('password')})});const d=await r.json();if(d.ok){location.href='/admin';return}el.textContent=d.message||'Не вдалося увійти.'}catch{el.textContent='Не вдалося підключитися.'}});
</script></body></html>
