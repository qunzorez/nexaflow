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
$maintenanceIcons = require __DIR__ . '/api/maintenance-icons.php';
?><!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title><?= maintenanceText((string)$state['title']) ?> · NexaFlow</title>
  <style>
    :root{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#19352b;background:#f2f8f4}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:32px 20px;overflow-x:hidden;background:radial-gradient(circle at 13% 88%,#d8f1e4 0,transparent 34%),radial-gradient(circle at 86% 8%,#e5f7ed 0,transparent 33%),#f2f8f4}.glow{position:fixed;width:320px;height:320px;border-radius:50%;filter:blur(4px);background:#d6f2e4;opacity:.4;pointer-events:none;animation:float 12s ease-in-out infinite}.glow.one{top:-140px;right:-80px}.glow.two{bottom:-190px;left:-120px;animation-delay:-5s}.shell{position:relative;width:min(930px,100%);overflow:hidden;border:1px solid #d9e9df;border-radius:28px;background:#fff;box-shadow:0 30px 85px #174f3520;animation:arrive .55s cubic-bezier(.2,.8,.2,1) both}.shell:before{content:"";position:absolute;inset:0 0 auto;height:5px;background:linear-gradient(90deg,#168765,#57c692,#c8f0d8)}.inner{padding:clamp(25px,4vw,48px)}.brand{display:inline-flex;align-items:center;gap:11px;color:#168765;font-size:13px;font-weight:900;letter-spacing:.1em}.mark{display:grid;place-items:center;width:39px;height:39px;border-radius:12px;background:#168765;color:white;font-size:22px;letter-spacing:0}.content{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(290px,.9fr);align-items:center;gap:clamp(26px,4vw,52px);margin-top:41px}.eyebrow{display:inline-flex;align-items:center;gap:9px;color:#168765;font-size:11px;font-weight:800;letter-spacing:.14em;text-transform:uppercase}.pulse{width:8px;height:8px;border-radius:50%;background:#22b47b;box-shadow:0 0 0 5px #dff5e9;animation:pulse 2.4s ease-in-out infinite}h1{margin:16px 0 16px;font-size:clamp(35px,5vw,52px);line-height:1.06;letter-spacing:-.045em}.lead{margin:0;color:#62766c;font-size:16px;line-height:1.65;white-space:pre-line}.note{margin:19px 0 0;color:#60776b;font-size:13px;line-height:1.5}.safe{display:flex;align-items:flex-start;gap:12px;margin-top:27px;padding:16px;border:1px solid #dceee2;border-radius:15px;background:#f2faf5}.safe-icon{display:grid;place-items:center;flex:none;width:34px;height:34px;border-radius:10px;background:#dff3e8;color:#168765}.safe strong{display:block;font-size:13px;color:#245e42}.safe span{display:block;margin-top:3px;color:#648074;font-size:12px;line-height:1.5}.countdown{position:relative;overflow:hidden;min-height:265px;padding:25px;border:1px solid #dceee4;border-radius:22px;background:linear-gradient(155deg,#eef9f2,#f9fcfa)}.countdown:after{content:"";position:absolute;right:-52px;top:-70px;width:195px;height:195px;border:1px solid #ccebd9;border-radius:50%;box-shadow:0 0 0 28px #e4f5eb,0 0 0 55px #eef9f2;opacity:.7;animation:float 10s ease-in-out infinite}.countdown>*{position:relative;z-index:1}.countdown-heading{display:flex;align-items:center;gap:10px;color:#2c7553;font-size:12px;font-weight:800;letter-spacing:.04em}.clock-icon{display:grid;place-items:center;width:34px;height:34px;border-radius:10px;background:#d7efdf}.countdown-label{margin:25px 0 15px;color:#5f7868;font-size:13px}.digits{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:7px}.digit{padding:11px 3px;border:1px solid #d4e8da;border-radius:12px;background:#fff;text-align:center;box-shadow:0 4px 12px #15573c0b}.digit b{display:block;color:#184d37;font-size:clamp(19px,2.7vw,29px);font-variant-numeric:tabular-nums;line-height:1.1}.digit small{display:block;margin-top:7px;color:#849b8c;font-size:10px}.end-time{margin-top:19px;color:#456a54;font-size:12px;line-height:1.4}.end-time time{font-weight:800}.estimate-note{margin:10px 0 0;color:#7b9485;font-size:11px;line-height:1.4}.countdown-empty{display:none;margin:25px 0 8px;color:#496e58;font-size:20px;font-weight:800;line-height:1.3}.countdown.no-time .digits,.countdown.no-time .end-time{display:none}.countdown.no-time .countdown-empty{display:block}.countdown.overdue .countdown-label{color:#a36a2a}.countdown.overdue .digit b{color:#a36a2a}.divider{height:1px;margin:37px 0 25px;background:#e7efe9}.bottom{display:flex;align-items:flex-start;justify-content:space-between;gap:20px}.actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap}button{display:inline-flex;align-items:center;justify-content:center;gap:9px;min-height:42px;border:0;border-radius:11px;background:#168765;color:#fff;padding:11px 17px;font:700 13px inherit;cursor:pointer;transition:background .2s,transform .2s,box-shadow .2s}button:hover{background:#117052;transform:translateY(-2px);box-shadow:0 8px 20px #1687652d}button:active{transform:translateY(0)}button:focus-visible,input:focus-visible,summary:focus-visible{outline:3px solid #71c99b;outline-offset:3px}.status{min-height:20px;color:#678074;font-size:12px;line-height:1.4}.admin{max-width:280px}.admin summary{cursor:pointer;color:#668173;font-size:12px}.admin form{display:grid;gap:10px;margin-top:13px}.admin input{width:100%;padding:11px 12px;border:1px solid #cdded2;border-radius:10px;font:inherit}.admin button{justify-self:start}footer{margin-top:27px;color:#8ba197;font-size:11px}@keyframes arrive{from{opacity:0;transform:translateY(20px) scale(.985)}to{opacity:1;transform:translateY(0) scale(1)}}@keyframes pulse{50%{box-shadow:0 0 0 9px #dff5e97a;opacity:.7}}@keyframes float{50%{transform:translateY(22px) translateX(-10px)}}@media(max-width:760px){.content{grid-template-columns:1fr;gap:25px}.countdown{min-height:0}.bottom{display:grid}.admin{max-width:none}}@media(max-width:460px){body{padding:15px}.shell{border-radius:19px}.inner{padding:24px 20px}.content{margin-top:30px}.countdown{padding:19px}.digits{gap:5px}.digit{padding:9px 2px}.digit small{font-size:9px}.divider{margin:27px 0 21px}}@media(prefers-reduced-motion:reduce){.glow,.shell,.pulse,.countdown:after{animation:none}button{transition:none}button:hover{transform:none}}
    .safe .safe-icon{display:grid;place-items:center;margin-top:0}.safe-icon svg{display:block;width:18px;height:18px}.clock-icon svg{width:16px;height:16px}.actions button svg{width:14px;height:14px}button{font-family:Inter,system-ui,sans-serif;font-size:13px;font-weight:700}
    .admin{width:300px;max-width:100%;border:1px solid #dcebe1;border-radius:17px;background:#f8fcf9;box-shadow:0 7px 22px #174f350b;transition:border-color .2s,box-shadow .2s}.admin:hover,.admin[open]{border-color:#b8dcca;box-shadow:0 12px 28px #174f3514}.admin summary{display:flex;align-items:center;gap:11px;min-height:66px;padding:12px 14px;cursor:pointer;list-style:none}.admin summary::-webkit-details-marker{display:none}.admin-summary-icon{display:grid;place-items:center;flex:none;width:36px;height:36px;border-radius:11px;background:#e3f3e9;color:#168765}.admin-summary-icon svg{width:15px;height:15px}.admin-summary-copy{display:grid;gap:2px;min-width:0;flex:1}.admin-summary-copy strong{color:#245b42;font-size:12px}.admin-summary-copy small{color:#83998b;font-size:10px}.admin-chevron{color:#8fa99a}.admin-chevron svg{width:11px;height:11px;transition:transform .2s}.admin[open] .admin-chevron svg{transform:rotate(90deg)}.admin form{display:grid;gap:13px;margin:0;padding:16px;border-top:1px solid #e0ede4;animation:admin-open .25s ease-out both}.admin-label{display:grid;gap:6px;color:#516e5c;font-size:11px;font-weight:700}.admin input{width:100%;height:42px;padding:10px 12px;border:1px solid #d2e2d7;border-radius:10px;background:white;color:#19352b;font:inherit;transition:border-color .2s,box-shadow .2s}.admin input:focus{border-color:#59b987;box-shadow:0 0 0 3px #c9efda;outline:0}.admin input::placeholder{color:#a0b0a5}.admin button{width:100%;margin-top:2px;justify-content:space-between}.admin button svg{width:13px;height:13px}.admin button:disabled{opacity:.65;cursor:wait;transform:none;box-shadow:none}.admin .status{min-height:0}.admin .status:empty{display:none}@keyframes admin-open{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}@media(max-width:760px){.admin{width:min(380px,100%)}}@media(prefers-reduced-motion:reduce){.admin form{animation:none}.admin,.admin-chevron svg,.admin input{transition:none}}
  </style>
</head>
<body>
  <div class="glow one" aria-hidden="true"></div><div class="glow two" aria-hidden="true"></div>
  <main class="shell"><div class="inner">
    <div class="brand"><span class="mark">N</span>NEXAFLOW</div>
    <div class="content">
      <section>
        <div class="eyebrow"><span class="pulse" aria-hidden="true"></span>Оновлення сервісу</div>
        <h1 id="page-title"><?= maintenanceText((string)$state['title']) ?></h1>
        <p class="lead" id="page-message"><?= maintenanceText((string)$state['message']) ?></p>
        <div class="safe"><span class="safe-icon"><?= $maintenanceIcons['shield'] ?></span><div><strong>Ваші дані збережені</strong><span>Після завершення робіт ви зможете продовжити роботу.</span></div></div>
        <p class="note" id="page-note"<?= empty($state['note']) ? ' hidden' : '' ?>><?= maintenanceText((string)($state['note'] ?? '')) ?></p>
      </section>
      <section class="countdown<?= empty($state['estimated_end_epoch']) ? ' no-time' : '' ?>" id="countdown" data-end-epoch="<?= (int)($state['estimated_end_epoch'] ?? 0) ?>" aria-label="Орієнтовний час завершення">
        <div class="countdown-heading"><span class="clock-icon"><?= $maintenanceIcons['clock'] ?></span>ОРІЄНТОВНЕ ЗАВЕРШЕННЯ</div>
        <p class="countdown-label" id="countdown-label">Залишилося приблизно</p>
        <div class="digits" aria-label="Відлік до завершення"><div class="digit"><b id="days">00</b><small>днів</small></div><div class="digit"><b id="hours">00</b><small>годин</small></div><div class="digit"><b id="minutes">00</b><small>хвилин</small></div><div class="digit"><b id="seconds">00</b><small>секунд</small></div></div>
        <p class="countdown-empty">Час завершення уточнюється</p>
        <div class="end-time">До <time id="end-time"><?= maintenanceText((string)($state['estimated_end'] ?? '')) ?></time></div>
        <p class="estimate-note">Це орієнтовний час. Доступ відновиться після завершення робіт.</p>
      </section>
    </div>
    <div class="divider"></div>
    <div class="bottom"><div><div class="actions"><button id="retry" type="button"><?= $maintenanceIcons['refresh'] ?>Перевірити стан</button><span class="status" id="status" role="status"></span></div><footer>Дякуємо за розуміння.</footer></div><details class="admin"><summary><span class="admin-summary-icon"><?= $maintenanceIcons['lock'] ?></span><span class="admin-summary-copy"><strong>Вхід адміністратора</strong><small>Лише для керування сервісом</small></span><span class="admin-chevron"><?= $maintenanceIcons['next'] ?></span></summary><form id="admin-login"><label class="admin-label">Email<input type="email" name="email" autocomplete="username" placeholder="admin@example.com" required></label><label class="admin-label">Пароль<input type="password" name="password" autocomplete="current-password" placeholder="Введіть пароль" required></label><button type="submit" id="admin-submit">Увійти до адмінпанелі <?= $maintenanceIcons['arrow'] ?></button><span class="status" id="login-status" role="status"></span></form></details></div>
  </div></main>
  <script>
    const panel = document.getElementById('countdown');
    const status = document.getElementById('status');
    let endEpoch = Number(panel.dataset.endEpoch) || 0;
    function updateCountdown() {
      if (!endEpoch) return;
      const left = Math.max(0, Math.ceil(endEpoch - Date.now() / 1000));
      const values = [Math.floor(left / 86400), Math.floor(left % 86400 / 3600), Math.floor(left % 3600 / 60), left % 60];
      ['days', 'hours', 'minutes', 'seconds'].forEach((id, index) => { document.getElementById(id).textContent = String(values[index]).padStart(2, '0'); });
      panel.classList.toggle('overdue', left === 0);
      document.getElementById('countdown-label').textContent = left === 0 ? 'Орієнтовний час минув — роботи ще тривають' : 'Залишилося приблизно';
    }
    function updateContent(data) {
      endEpoch = Number(data.estimated_end_epoch) || 0;
      panel.classList.toggle('no-time', !endEpoch);
      document.getElementById('page-title').textContent = data.title || 'Технічні роботи';
      document.getElementById('page-message').textContent = data.message || '';
      const note = document.getElementById('page-note'); note.textContent = data.note || ''; note.hidden = !data.note;
      if (endEpoch) document.getElementById('end-time').textContent = new Date(endEpoch * 1000).toLocaleString('uk-UA', { dateStyle: 'medium', timeStyle: 'short' });
      updateCountdown();
    }
    async function checkState(manual) {
      if (manual) status.textContent = 'Перевіряємо…';
      try {
        const response = await fetch('/api/maintenance-status.php', { cache: 'no-store', credentials: 'same-origin' });
        const data = await response.json();
        if (!data.ok) throw new Error('status');
        if (!data.enabled || data.is_admin) { location.reload(); return; }
        updateContent(data);
        if (manual) status.textContent = 'Роботи ще тривають.';
      } catch { if (manual) status.textContent = 'Не вдалося перевірити стан. Спробуйте пізніше.'; }
    }
    if (endEpoch) document.getElementById('end-time').textContent = new Date(endEpoch * 1000).toLocaleString('uk-UA', { dateStyle: 'medium', timeStyle: 'short' });
    updateCountdown();
    setInterval(updateCountdown, 1000);
    setInterval(() => checkState(false), 30000);
    document.getElementById('retry').addEventListener('click', () => checkState(true));
    document.getElementById('admin-login').addEventListener('submit', async event => {
      event.preventDefault();
      const el = document.getElementById('login-status'); const submit = document.getElementById('admin-submit');
      submit.disabled = true; el.textContent = 'Перевіряємо доступ…';
      const form = new FormData(event.currentTarget);
      try {
        const response = await fetch('/api/login.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ email: form.get('email'), password: form.get('password') }) });
        const data = await response.json();
        if (data.ok) { location.href = '/admin'; return; }
        el.textContent = data.message || 'Не вдалося увійти.';
      } catch { el.textContent = 'Не вдалося підключитися.'; }
      finally { submit.disabled = false; }
    });
  </script>
</body>
</html>
