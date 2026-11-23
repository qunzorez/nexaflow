import { useEffect, useMemo, useState } from 'react';
import { Navigate, useNavigate } from 'react-router-dom';
import Layout from '../components/Layout.jsx';
import Icon from '../components/Icon.jsx';
import { api, post } from '../services/api.js';
import './admin.css';

const tabs = [['overview', 'Огляд'], ['online', 'Онлайн'], ['users', 'Користувачі'], ['workspaces', 'Простори'], ['groups', 'Групи'], ['invitations', 'Запрошення'], ['subscriptions', 'Підписки'], ['security', 'Безпека'], ['roles', 'Ролі та права'], ['settings', 'Системні налаштування'], ['audit', 'Аудит']];
const roleLabels = { user: 'Користувач', manager: 'Менеджер', admin: 'Адміністратор', owner: 'Власник' };

export default function AdminPage() {
  const navigate = useNavigate();
  const [me, setMe] = useState(null);
  const [data, setData] = useState(null);
  const [tab, setTab] = useState('overview');
  const [search, setSearch] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [saving, setSaving] = useState('');
  const [resendingVerification, setResendingVerification] = useState('');
  const [drafts, setDrafts] = useState({});
  const [accessDenied, setAccessDenied] = useState(false);
  const load = () => {
    if (!data) setLoading(true);
    Promise.all([api('/api/me.php'), api(`/api/admin.php?page=${page}&search=${encodeURIComponent(search)}`)]).then(([userResult, adminResult]) => {
      if (!userResult.ok) { setAccessDenied(true); navigate('/login', { replace: true }); return; }
      if (!['admin', 'owner'].includes(userResult.user?.role)) { setAccessDenied(true); navigate('/dashboard', { replace: true }); return; }
      if (!adminResult.ok) { setAccessDenied(true); navigate('/dashboard', { replace: true }); return; }
      setMe(userResult.user); setData(adminResult); setError('');
      const next = {}; (adminResult.users || []).forEach(user => { next[user.id] = { name: user.name, email: user.email, role: user.role, plan_key: user.plan_key, active_client_module: user.active_client_module, is_support_agent: Number(user.is_support_agent) === 1 }; }); setDrafts(next);
    }).finally(() => setLoading(false));
  };
  useEffect(() => { const timer = window.setTimeout(() => { setPage(1); setSearch(searchInput.trim()); }, 300); return () => window.clearTimeout(timer); }, [searchInput]);
  useEffect(load, [page, search]);
  const stats = data?.stats || {};
  const planNames = useMemo(() => Object.fromEntries((data?.plans || []).map(plan => [plan.key, plan.name])), [data]);
  async function saveUser(user) {
    const draft = drafts[user.id]; if (!draft) return;
    if (draft.role !== user.role && !window.confirm(`Підтвердити зміну ролі для ${user.name}?`)) return;
    setSaving(user.id); setNotice('');
    const result = await post('/api/admin.php', { action: 'update_user', user_id: user.id, ...draft, reason: 'Зміна через адміністративну панель' });
    setSaving(''); if (!result.ok) { setError(result.message || 'Не вдалося зберегти.'); return; } setNotice('Зміни збережено.'); load();
  }
  async function revokeSessions(user) {
    if (!window.confirm(`Завершити всі активні сесії користувача ${user.name}?`)) return;
    const result = await post('/api/admin.php', { action: 'revoke_sessions', user_id: user.id, reason: 'Завершення сесій адміністратором' });
    if (!result.ok) setError(result.message || 'Операція не виконана.'); else setNotice(result.message);
  }
  async function resendVerification(user) {
    if (user.email_verified_at || resendingVerification) return;
    setResendingVerification(user.id); setError(''); setNotice('');
    const result = await post('/api/admin.php', { action: 'resend_email_verification', user_id: user.id, reason: 'Повторне підтвердження email з адмінпанелі' });
    setResendingVerification('');
    if (!result.ok) { setError(result.message || 'Не вдалося надіслати лист підтвердження.'); return; }
    setNotice(result.message || 'Лист підтвердження надіслано.');
  }
  async function setUserBlocked(user) {
    const blocked = !Number(user.is_blocked);
    const reason = blocked ? window.prompt(`Причина блокування облікового запису ${user.name}:`) : window.prompt(`Причина розблокування облікового запису ${user.name}:`);
    if (reason === null) return;
    if (!reason.trim()) { setError('Вкажіть причину дії.'); return; }
    if (!window.confirm(`${blocked ? 'Заблокувати' : 'Розблокувати'} ${user.name}? ${blocked ? 'Користувача буде негайно від’єднано від API та перенаправлено на сторінку блокування.' : 'Користувач зможе знову увійти до системи.'}`)) return;
    const result = await post('/api/admin.php', { action: 'set_user_blocked', user_id: user.id, blocked, reason: reason.trim() });
    if (!result.ok) { setError(result.message || 'Не вдалося змінити статус.'); return; }
    setNotice(result.message); load();
  }
  const userRows = data?.users || [];
  if (accessDenied || !me && !loading) return <Navigate to="/dashboard" replace />;
  if (loading || !me || !data) return <main className="admin-page"><section className="admin-empty" role="status">Перевіряємо адміністративний доступ…</section></main>;
  return <Layout page="Адмінпанель" user={me}>
    <main className="admin-page">
      <header className="admin-heading"><div><p className="eyebrow">КЕРУВАННЯ СИСТЕМОЮ</p><h1>Адміністративна панель</h1><p>Централізований контроль NexaFlow з серверною перевіркою прав і аудитом дій.</p></div><span className="admin-lock"><Icon name="settings" /> Лише адміністратори</span></header>
      {error && <div className="admin-alert error" role="alert">{error}</div>}{notice && <div className="admin-alert success" role="status">{notice}</div>}
      <nav className="admin-tabs" aria-label="Розділи адмінпанелі">{tabs.map(([value, label]) => <button key={value} type="button" className={tab === value ? 'active' : ''} onClick={() => setTab(value)}>{label}</button>)}</nav>
      {loading ? <section className="admin-empty">Завантажуємо дані…</section> : <>
        {tab === 'overview' && <section className="admin-overview"><div className="admin-stat-grid">{[['users', 'Користувачі'], ['workspaces', 'Робочі простори'], ['clients', 'Клієнти'], ['tasks', 'Активні задачі'], ['projects', 'Проєкти'], ['active_subscriptions', 'Активні підписки']].map(([key, label]) => <article className="admin-stat" key={key}><span>{label}</span><strong>{stats[key] ?? 0}</strong></article>)}</div><div className="admin-panel"><div className="admin-panel-heading"><div><h2>Останні адміністративні дії</h2><p>Зміни облікових записів і доступів із фіксацією попередніх та нових значень.</p></div><button type="button" className="admin-link" onClick={() => setTab('audit')}>Відкрити журнал</button></div><AuditTable rows={(data.audit || []).slice(0, 6)} /></div></section>}
        {tab === 'users' && <section className="admin-panel"><div className="admin-toolbar"><label className="admin-search"><Icon name="search" /><input value={searchInput} onChange={event => setSearchInput(event.target.value)} placeholder="Пошук за ім’ям, email або роллю" /></label><span>{data.pagination?.total || 0} користувачів</span></div><div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Користувач</th><th>Роль</th><th>План / модуль</th><th>Статус / активність</th><th>Дії</th></tr></thead><tbody>{userRows.map(user => { const draft = drafts[user.id] || {}; return <tr key={user.id}><td><strong>{user.name}</strong><small>{user.email}</small><small>{user.email_verified_at ? 'Email підтверджено' : 'Email не підтверджено'}</small></td><td><select value={draft.role || user.role} onChange={event => setDrafts(current => ({ ...current, [user.id]: { ...current[user.id], role: event.target.value } }))}>{Object.entries(roleLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></td><td><select value={draft.plan_key || user.plan_key} onChange={event => setDrafts(current => ({ ...current, [user.id]: { ...current[user.id], plan_key: event.target.value } }))}>{(data.plans || []).map(plan => <option key={plan.key} value={plan.key}>{plan.name}</option>)}</select><small>{user.active_client_module === 'dentistry' ? 'Стоматологія' : 'Бухгалтерія'}</small></td><td>{Number(user.is_blocked) ? <><strong className="admin-user-blocked">Заблоковано</strong><small>{user.blocked_reason}</small></> : <strong className="admin-user-active">Активний</strong>}<small>{user.last_seen_at ? new Date(user.last_seen_at.replace(' ', 'T')).toLocaleString('uk-UA') : 'Ще не входив'}</small></td><td className="admin-actions"><button type="button" className="admin-button primary" disabled={saving === user.id} onClick={() => saveUser(user)}>{saving === user.id ? 'Зберігаємо…' : 'Зберегти'}</button>{!user.email_verified_at && <button type="button" className="admin-button" disabled={resendingVerification === user.id} onClick={() => resendVerification(user)}>{resendingVerification === user.id ? 'Надсилаємо…' : 'Надіслати підтвердження'}</button>}<button type="button" className="admin-button" onClick={() => revokeSessions(user)}>Завершити сесії</button><button type="button" className="admin-button" onClick={() => setUserBlocked(user)}>{Number(user.is_blocked) ? 'Розблокувати' : 'Заблокувати'}</button></td></tr>; })}</tbody></table></div><Pagination page={data.pagination?.page || 1} total={data.pagination?.total || 0} perPage={data.pagination?.per_page || 25} onChange={setPage} /></section>}
        {tab === 'online' && <section className="admin-panel"><h2>Онлайн-користувачі</h2><p className="admin-muted">Онлайн означає активність протягом останніх 5 хвилин.</p><SimpleTable headers={['Користувач', 'Роль', 'Остання активність']} rows={(data.online || []).map(item => [<><strong>{item.name}</strong><small>{item.email}</small></>, roleLabels[item.role] || item.role, item.last_seen_at])} /></section>}
        {tab === 'groups' && <section className="admin-panel"><h2>Групи</h2><p className="admin-muted">Групи та кількість учасників за наявною моделлю доступу.</p><SimpleTable headers={['Назва', 'Проєкт / простір', 'Роль', 'Учасники']} rows={(data.groups || []).map(item => [item.name, item.project_name || 'Робочий простір', item.role_name || '-', item.member_count])} /></section>}
        {tab === 'invitations' && <section className="admin-panel"><h2>Запрошення</h2><p className="admin-muted">Очікувані, прийняті та відкликані запрошення.</p><SimpleTable headers={['Email', 'Тип', 'Проєкт / група', 'Запросив', 'Статус', 'Дійсне до']} rows={(data.invitations || []).map(item => [item.email, item.invite_type === 'team' ? 'Команда' : 'Проєкт', item.project_name || item.group_name || '-', item.inviter_name || '-', item.status === 'pending' && new Date(item.expires_at) < new Date() ? 'Прострочене' : item.status, item.expires_at])} /></section>}
        {tab === 'security' && <><section className="admin-panel"><h2>Журнал входів</h2><p className="admin-muted">Фіксуємо успішні й невдалі входи без IP та паролів.</p><SimpleTable headers={['Користувач', 'Email', 'Подія', 'Результат', 'Час']} rows={(data.auth_events || []).map(item => [item.user_name || 'Невідомий користувач', item.email, item.event_type, item.result === 'success' ? 'Успішно' : 'Неуспішно', item.created_at])} /></section><section className="admin-panel"><h2>Спроби відкрити адмінпанель</h2><p className="admin-muted">Записуються авторизовані облікові записи, яким сервер відмовив у доступі.</p><SimpleTable headers={['Користувач', 'Email', 'Шлях', 'Результат', 'Час']} rows={(data.admin_access_attempts || []).map(item => [item.user_name, item.email, item.path, 'Доступ заборонено', item.created_at])} /></section></>}
        {tab === 'workspaces' && <section className="admin-panel"><h2>Робочі простори</h2><p className="admin-muted">Власники просторів, активний модуль і використання основних ресурсів.</p><div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Власник</th><th>План</th><th>Модуль</th><th>Учасники</th><th>Клієнти</th><th>Проєкти</th><th>Задачі</th></tr></thead><tbody>{(data.workspaces || []).map(workspace => <tr key={workspace.id}><td><strong>{workspace.name}</strong><small>{workspace.email}</small></td><td>{planNames[workspace.plan_key] || workspace.plan_key}</td><td>{workspace.active_client_module === 'dentistry' ? 'Стоматологія' : 'Бухгалтерія'}</td><td>{workspace.members}</td><td>{workspace.clients}</td><td>{workspace.projects}</td><td>{workspace.tasks}</td></tr>)}</tbody></table></div><WorkspaceManager workspaces={data.workspaces || []} onNotice={setNotice} onError={setError} /></section>}
        {tab === 'subscriptions' && <section className="admin-panel"><h2>Підписки й тарифи</h2><p className="admin-muted">Дані з наявної платіжної логіки. Платіжні реквізити не відображаються.</p><div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Простір</th><th>План</th><th>Статус</th><th>Сума</th><th>Період до</th></tr></thead><tbody>{(data.subscriptions || []).map(item => <tr key={item.id}><td>{item.owner_name}</td><td>{planNames[item.plan_key] || item.plan_key}</td><td><span className={`admin-status ${item.status}`}>{item.status}</span></td><td>{item.amount} {item.currency}</td><td>{item.current_period_end || 'Безстроково'}</td></tr>)}</tbody></table></div></section>}
        {tab === 'audit' && <section className="admin-panel"><h2>Журнал аудиту</h2><p className="admin-muted">Журнал доступний для пошуку й перегляду та не має операцій редагування.</p><AuditTable rows={data.audit || []} /></section>}
        {tab === 'roles' && <section className="admin-panel"><h2>Ролі та права</h2><p className="admin-muted">Системні ролі перевіряються сервером для кожної операції.</p><div className="admin-role-grid">{Object.entries(roleLabels).map(([role, label]) => <article key={role}><strong>{label}</strong><span className="admin-role-key">{role}</span><p>{role === 'admin' || role === 'owner' ? 'Глобальний адміністративний доступ.' : role === 'manager' ? 'Керування власним робочим простором.' : 'Доступ до призначених просторів.'}</p></article>)}</div></section>}
        {tab === 'settings' && <section className="admin-panel"><h2>Системні налаштування</h2><p className="admin-muted">Секрети інтеграцій не повертаються з API.</p><div className="admin-settings-grid"><article><span>Платіжна інтеграція</span><strong>{data.settings?.billing_enabled ? 'Увімкнена' : 'Вимкнена'}</strong></article><article><span>Модулі</span><strong>Бухгалтерія · Стоматологія</strong></article><article><span>Аудит адміністратора</span><strong>Увімкнений</strong></article></div></section>}
      </>}
    </main>
  </Layout>;
}

function SimpleTable({ headers, rows }) { return <div className="admin-table-wrap"><table className="admin-table"><thead><tr>{headers.map(item => <th key={item}>{item}</th>)}</tr></thead><tbody>{rows.length ? rows.map((row, index) => <tr key={index}>{row.map((cell, i) => <td key={i}>{cell}</td>)}</tr>) : <tr><td colSpan={headers.length} className="admin-no-results">Записів не знайдено.</td></tr>}</tbody></table></div>; }
function AuditTable({ rows }) { return <div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Час</th><th>Хто</th><th>Операція</th><th>Сутність</th><th>Результат</th><th>Причина</th></tr></thead><tbody>{rows.length ? rows.map(row => <tr key={row.id}><td>{new Date(String(row.created_at).replace(' ', 'T')).toLocaleString('uk-UA')}</td><td><strong>{row.actor_name}</strong><small>{row.actor_email}</small></td><td>{row.action}</td><td>{row.entity_type} · {row.entity_id}</td><td>{row.result}</td><td>{row.reason || '-'}</td></tr>) : <tr><td colSpan="6" className="admin-no-results">Дій ще немає.</td></tr>}</tbody></table></div>; }
function WorkspaceManager({ workspaces, onNotice, onError }) {
  const [workspaceId, setWorkspaceId] = useState('');
  const [roster, setRoster] = useState(null);
  const [email, setEmail] = useState('');
  const [busy, setBusy] = useState(false);
  useEffect(() => { if (!workspaceId) { setRoster(null); return; } api(`/api/team.php?workspace_owner_id=${encodeURIComponent(workspaceId)}`).then(result => { if (result.ok) setRoster(result); else onError(result.message || 'Не вдалося завантажити учасників.'); }); }, [workspaceId]);
  async function addMember(event) {
    event.preventDefault(); if (!email.trim()) return;
    setBusy(true); onError('');
    const result = await post('/api/invite.php', { action: 'send', email: email.trim(), role: 'member', workspace_owner_id: workspaceId });
    setBusy(false);
    if (!result.ok) { onError(result.message || 'Не вдалося надіслати запрошення.'); return; }
    setEmail(''); onNotice(result.message || 'Запрошення надіслано.');
  }
  async function removeMember(member) {
    const consequences = `Учасника буде прибрано з простору «${workspaces.find(item => item.id === workspaceId)?.name}». Призначення в групах і проєктах цього простору буде очищено; обліковий запис і створені ним дані залишаться. Продовжити?`;
    if (!window.confirm(consequences)) return;
    setBusy(true);
    const result = await post('/api/team.php', { action: 'remove_member', user_id: member.id, workspace_owner_id: workspaceId });
    setBusy(false);
    if (!result.ok) { onError(result.message || 'Не вдалося прибрати учасника.'); return; }
    onNotice(result.message || 'Учасника прибрано.');
    const refreshed = await api(`/api/team.php?workspace_owner_id=${encodeURIComponent(workspaceId)}`); if (refreshed.ok) setRoster(refreshed);
  }
  return <div className="admin-workspace-manager"><h3>Учасники простору</h3><label className="admin-workspace-select">Оберіть простір<select value={workspaceId} onChange={event => setWorkspaceId(event.target.value)}><option value="">Оберіть…</option>{workspaces.map(item => <option key={item.id} value={item.id}>{item.name} · {item.email}</option>)}</select></label>{workspaceId && <><form className="admin-add-member" onSubmit={addMember}><label>Email нового учасника<input type="email" required value={email} onChange={event => setEmail(event.target.value)} placeholder="name@example.com" /></label><button className="admin-button primary" disabled={busy}>{busy ? 'Обробка…' : 'Надіслати запрошення'}</button></form><p className="admin-muted">Запрошення надійде через чинний механізм NexaFlow. Роль за замовчуванням - учасник.</p>{!roster ? <p className="admin-muted">Завантажуємо список…</p> : <div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Учасник</th><th>Група</th><th>Дія</th></tr></thead><tbody>{(roster.admin_workspace_memberships || []).length ? roster.admin_workspace_memberships.map(member => <tr key={member.id}><td><strong>{member.name}</strong><small>{member.email}</small></td><td>{member.group_name || 'Без групи'}</td><td><button type="button" className="admin-button" disabled={busy} onClick={() => removeMember(member)}>Прибрати з простору</button></td></tr>) : <tr><td colSpan="3" className="admin-no-results">У просторі поки немає учасників.</td></tr>}</tbody></table></div>}</>}</div>;
}
function Pagination({ page, total, perPage, onChange }) { const pages = Math.max(1, Math.ceil(total / perPage)); return <div className="admin-pagination"><span>Сторінка {page} з {pages}</span><div><button type="button" disabled={page <= 1} onClick={() => onChange(page - 1)}>Назад</button><button type="button" disabled={page >= pages} onClick={() => onChange(page + 1)}>Далі</button></div></div>; }
