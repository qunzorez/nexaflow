import { useMemo, useState } from 'react';
import { post } from '../../services/api.js';
import Icon from '../../components/Icon.jsx';
import SearchableMultiSelect from '../../components/SearchableMultiSelect.jsx';
import SearchableSelect from '../../components/SearchableSelect.jsx';
import './manager-team.css';
import './manager-group-table.css';
import './manager-group-actions.css';
import './manager-group-dropdown.css';
import './manager-recent-users.css';
import './client-assignment.css';

const permissionLabels = { read: 'Читати', update: 'Оновлювати статус', edit: 'Редагувати поля', delete: 'Видаляти' };
const permissionDescriptions = { read: 'Перегляд доступного контенту', update: 'Зміна статусу та прогресу', edit: 'Зміна назви, опису й полів', delete: 'Архівування або видалення' };
const permissionIcons = { read: 'eye', update: 'refresh', edit: 'edit', delete: 'archive' };
const initials = name => String(name || '?').split(' ').map(part => part[0]).slice(0, 2).join('').toUpperCase();
const claimRolePresets = [
  { name: 'Бухгалтер', permissions: ['read', 'update', 'edit'] },
  { name: 'Модератор', permissions: ['read', 'update', 'edit', 'delete'] },
  { name: 'Виконавець', permissions: ['read', 'update'] },
  { name: 'Переглядач', permissions: ['read'] },
  { name: 'Керівник проєкту', permissions: ['read', 'update', 'edit', 'delete'] },
];

function GroupSummary({ item, onEdit, onDelete }) {
  return <div className="managed-group">
    <div><strong>{item.name}</strong><small>{item.member_count || 0} користувачів · {(item.permissions || []).map(permission => permissionLabels[permission]).join(' · ') || 'Без прав'}</small></div>
    <button type="button" className="outline-btn" onClick={() => onEdit(item)}>Редагувати claims</button>
    <button type="button" className="remove-member" onClick={() => onDelete(item.id)}>Видалити</button>
  </div>;
}

export default function ManagerTeamView({ data, onReload, isAdmin = false, isProjectLead = false, currentUserId = '', subscription = null }) {
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');
  const [clientSearch, setClientSearch] = useState('');
  const [groupSearch, setGroupSearch] = useState('');
  const [group, setGroup] = useState({ name: '', permissions: ['read'] });
  const [editing, setEditing] = useState(null);
  const [editingUser, setEditingUser] = useState(null);
  const [inviteProject, setInviteProject] = useState('');
  const [inviteGroup, setInviteGroup] = useState('');
  const [resendingInvite, setResendingInvite] = useState('');
  const [savingWorkspaceGroup, setSavingWorkspaceGroup] = useState('');
  const [workspaceRosterSearch, setWorkspaceRosterSearch] = useState('');
  const users = useMemo(() => data.users.filter(user => (!isProjectLead || Boolean(Number(user.manageable_by_actor))) && `${user.name} ${user.email}`.toLowerCase().includes(search.toLowerCase().trim())), [data.users, search, isProjectLead]);
  const ownRoleNames = useMemo(() => new Set(data.groups.filter(item => item.project_id == null && String(item.group_owner_id || '') === String(currentUserId)).map(item => String(item.name).trim().toLocaleLowerCase('uk'))), [data.groups, currentUserId]);
  const selectedRoleExists = ownRoleNames.has(group.name.trim().toLocaleLowerCase('uk'));
  const participantLimit = subscription?.limits?.find(item => item.key === 'participants');
  const participantLimitReached = !isAdmin && participantLimit?.limit != null && Number(participantLimit.used) >= Number(participantLimit.limit);
  const projectLeadLimit = subscription?.limits?.find(item => item.key === 'project_leads');
  const projectLeadSlotsAvailable = isAdmin || (projectLeadLimit?.limit != null && Number(projectLeadLimit.limit) > Number(projectLeadLimit.used));
  const moderatorLimit = subscription?.limits?.find(item => item.key === 'moderators');
  const moderatorSlotsAvailable = isAdmin || (moderatorLimit?.limit != null && Number(moderatorLimit.limit) > Number(moderatorLimit.used));
  const assignableGroups = data.assignable_groups || data.groups;
  const isDentistryWorkspace = subscription?.module === 'dentistry';
  const visibleClaimRolePresets = isDentistryWorkspace
    ? [...claimRolePresets, { name: 'Лікар', permissions: ['read', 'update', 'edit'] }]
    : claimRolePresets;

  async function submitInvite(event) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const result = await post('/api/invite.php', { action: 'send', email: form.get('email'), project_id: form.get('project_id'), group_id: form.get('group_id'), role: 'member' });
    setNotice(result.message);
    if (result.ok) { event.currentTarget.reset(); setInviteProject(''); setInviteGroup(''); onReload(); }
  }
  async function resendInvite(invite) {
    const key = `${invite.invite_type}:${invite.id}`;
    setResendingInvite(key);
    const result = await post('/api/invite.php', { action: 'resend', invite_id: invite.id, invite_type: invite.invite_type });
    setNotice(result.message);
    setResendingInvite('');
    if (result.ok) onReload();
  }
  async function groupAction(payload) {
    setNotice(''); setError('');
    const result = await post('/api/project.php', payload);
    if (!result.ok) { setError(result.message); return false; }
    setNotice(result.message); setEditing(null); onReload(); return true;
  }
  async function createGroup(event) {
    event.preventDefault();
    if (await groupAction({ action: 'create_global_group', ...group })) setGroup({ name: '', permissions: ['read'] });
  }
  async function assignGroup(userId, groupId) {
    const result = await post('/api/project.php', { action: 'assign_global_group', user_id: userId, group_id: groupId });
    setNotice(result.message); if (result.ok) onReload();
  }
  async function assignClients(userId, clientIds) {
    const result = await post('/api/team.php', { action: 'assign_clients', user_id: userId, client_ids: clientIds });
    setNotice(result.message); if (result.ok) onReload();
  }
  async function removeFromWorkspace(user, selectedWorkspaceId = '') {
    const workspaceOwnerId = isAdmin ? String(selectedWorkspaceId || '') : '';
    if (isAdmin && !workspaceOwnerId) { setError('Оберіть простір, щоб керувати його учасниками.'); return; }
    if (!window.confirm(`Прибрати ${user.name} з простору? Обліковий запис користувача залишиться активним.`)) return;
    setNotice(''); setError('');
    const result = await post('/api/team.php', { action: 'remove_member', user_id: user.id, ...(workspaceOwnerId ? { workspace_owner_id: workspaceOwnerId } : {}) });
    setNotice(result.message || ''); setError(result.ok ? '' : (result.message || 'Не вдалося прибрати користувача.'));
    if (result.ok) onReload(workspaceOwnerId);
  }
  async function addMemberToWorkspace(event, workspace) {
    event.preventDefault();
    const formElement = event.currentTarget;
    const form = new FormData(formElement);
    const workspaceOwnerId = String(workspace.manager_id || '');
    setNotice(''); setError('');
    const result = await post('/api/invite.php', { action: 'send', email: form.get('email'), project_id: '', group_id: form.get('group_id') || '', role: 'member', workspace_owner_id: workspaceOwnerId });
    if (!result.ok) { setError(result.message || 'Не вдалося запросити користувача.'); return; }
    formElement.reset(); setNotice(result.message || 'Запрошення створено.'); onReload();
  }
  async function assignWorkspaceGroup(userId, groupId, workspaceOwnerId) {
    workspaceOwnerId = String(workspaceOwnerId || '');
    if (!workspaceOwnerId) return;
    const savingKey = `${workspaceOwnerId}:${userId}`;
    setSavingWorkspaceGroup(savingKey); setError(''); setNotice('');
    const result = await post('/api/project.php', { action: 'admin_assign_workspace_group', workspace_owner_id: workspaceOwnerId, user_id: userId, group_id: groupId });
    setSavingWorkspaceGroup('');
    if (!result.ok) { setError(result.message || 'Не вдалося змінити групу користувача.'); return; }
    setNotice(result.message || 'Групу користувача оновлено.'); onReload();
  }
  async function changeSystemRole(userId, role) {
    const result = await post('/api/team.php', { action: 'set_user_role', user_id: userId, role });
    setNotice(result.message); if (result.ok) onReload();
  }
  async function changeSupportRole(userId, enabled) {
    const result = await post('/api/team.php', { action: 'set_support_role', user_id: userId, enabled });
    setNotice(result.message); setError(result.ok ? '' : result.message); if (result.ok) onReload();
  }
  async function saveUser(event) {
    event.preventDefault();
    if (!editingUser) return;
    const result = await post('/api/team.php', { action: 'update_user', user_id: editingUser.id, name: editingUser.name, email: editingUser.email });
    setNotice(result.message); setError(result.ok ? '' : result.message);
    if (result.ok) { setEditingUser(null); onReload(); }
  }
  function togglePermission(key) {
    setGroup(current => ({ ...current, permissions: current.permissions.includes(key) ? current.permissions.filter(item => item !== key) : [...current.permissions, key] }));
  }
  function editPermission(key) {
    setEditing(current => ({ ...current, permissions: current.permissions.includes(key) ? current.permissions.filter(item => item !== key) : [...current.permissions, key] }));
  }
  function selectRolePreset(value) {
    const preset = visibleClaimRolePresets.find(item => item.name === value);
    if (preset) setGroup({ name: preset.name, permissions: [...preset.permissions] });
    else setGroup({ name: '', permissions: ['read'] });
  }
  const claimOptions = (value, toggle) => <div className="permission-list claims-grid">{Object.entries(permissionLabels).map(([key, label]) => <label className={value.includes(key) ? 'claim-card selected' : 'claim-card'} key={key}><input type="checkbox" checked={value.includes(key)} onChange={() => toggle(key)} /><span className="claim-icon"><Icon name={permissionIcons[key]} /></span><span className="claim-copy"><strong>{label}</strong><small>{permissionDescriptions[key]}</small></span><span className="claim-check"><Icon name="check" /></span></label>)}</div>;
  const editForm = editing && <div className="managed-group managed-group-edit"><div className="claims-edit-heading"><span>Редагування claims групи</span><strong>{editing.name}</strong><small>Назва та роль групи залишаються без змін. Налаштуйте лише дозволені дії.</small></div>{claimOptions(editing.permissions || [], editPermission)}<div className="group-edit-actions"><button type="button" className="primary-action" onClick={() => groupAction({ action: 'update_global_group', group_id: editing.id, name: editing.name, permissions: editing.permissions })}>Зберегти claims</button><button type="button" className="outline-btn" onClick={() => setEditing(null)}>Скасувати</button></div></div>;
  const groupsByOwner = useMemo(() => data.groups.reduce((result, item) => { const key = item.group_owner_id || 'unknown'; (result[key] ||= []).push(item); return result; }, {}), [data.groups]);
  const visibleGroupsByOwner = useMemo(() => Object.fromEntries(Object.entries(groupsByOwner).map(([ownerId, items]) => [ownerId, items.filter(item => `${item.name} ${data.users.find(user => String(user.id) === String(ownerId))?.name || ''} ${data.users.find(user => String(user.id) === String(ownerId))?.email || ''}`.toLowerCase().includes(groupSearch.trim().toLowerCase()))]).filter(([, items]) => items.length)), [groupsByOwner, data.users, groupSearch]);
  const adminGroupsPanel = isAdmin && <section className="panel global-groups-list admin-groups-panel"><div className="panel-heading"><div><h2>Групи claims</h2><p>Усі глобальні групи, згруповані за менеджером, який їх створив.</p></div><span className="count-pill">{data.groups.length}</span></div><label className="admin-role-search claims-group-search"><Icon name="search" /><input type="search" value={groupSearch} onChange={event => setGroupSearch(event.target.value)} placeholder="Пошук групи або менеджера…" /></label><div className="managed-groups claims-group-list">{editing ? editForm : Object.entries(visibleGroupsByOwner).map(([ownerId, items]) => <div className="admin-group-owner" key={ownerId}><div className="admin-group-owner-title"><strong>{data.users.find(user => String(user.id) === String(ownerId))?.name || 'Невідомий автор'}</strong><small>{data.users.find(user => String(user.id) === String(ownerId))?.email || 'Автор групи'}</small></div>{items.map(item => <GroupSummary key={item.id} item={item} onEdit={setEditing} onDelete={id => groupAction({ action: 'delete_global_group', group_id: id })} />)}</div>)}</div></section>;
  const visibleManagerGroups = useMemo(() => data.groups.filter(item => `${item.name} ${item.role_name || ''}`.toLowerCase().includes(groupSearch.trim().toLowerCase())), [data.groups, groupSearch]);
  const managerGroupsPanel = !isAdmin && !isProjectLead && <section className="panel global-groups-list manager-groups-panel"><div className="panel-heading"><div><h2>Мої групи claims</h2><p>Групи claims, які створили ви. Їх можна призначати запрошеним користувачам.</p></div><span className="count-pill">{data.groups.length}</span></div><label className="admin-role-search claims-group-search"><Icon name="search" /><input type="search" value={groupSearch} onChange={event => setGroupSearch(event.target.value)} placeholder="Пошук моїх груп…" /></label><div className="managed-groups claims-group-list">{editing ? editForm : visibleManagerGroups.map(item => <GroupSummary key={item.id} item={item} onEdit={setEditing} onDelete={id => groupAction({ action: 'delete_global_group', group_id: id })} />)}</div>{!editing && visibleManagerGroups.length === 0 && <p className="client-access-empty">У вас ще немає створених груп claims.</p>}</section>;
  const adminRolePanel = isAdmin && <section className="panel admin-role-panel"><div className="panel-heading"><div><h2>Користувачі та глобальні ролі</h2><p>Системна роль визначає доступ до NexaFlow, а додаткова роль підтримки надає доступ лише до чату.</p></div><span className="count-pill">{data.users.length}</span></div><label className="admin-role-search"><Icon name="search" /><input type="search" value={search} onChange={event => setSearch(event.target.value)} placeholder="Пошук за ім’ям або email…" /></label><div className="admin-role-table"><div className="admin-role-head"><span>Користувач</span><span>Email</span><span>Системна роль</span><span>Додаткова роль</span><span>Профіль</span></div>{data.users.filter(user => `${user.name} ${user.email}`.toLowerCase().includes(search.toLowerCase().trim())).sort((a, b) => ({ owner: 0, admin: 0, manager: 1, user: 2 }[a.role] ?? 3) - ({ owner: 0, admin: 0, manager: 1, user: 2 }[b.role] ?? 3)).map(user => editingUser?.id === user.id ? <form className="admin-role-row admin-user-edit-row" key={user.id} onSubmit={saveUser}><input aria-label="Ім’я користувача" value={editingUser.name} onChange={event => setEditingUser({ ...editingUser, name: event.target.value })} required maxLength={120} /><input aria-label="Email користувача" type="email" value={editingUser.email} onChange={event => setEditingUser({ ...editingUser, email: event.target.value })} required /><span className="admin-user-edit-actions"><button className="primary-action" type="submit">Зберегти</button><button className="outline-btn" type="button" onClick={() => setEditingUser(null)}>Скасувати</button></span></form> : <div className="admin-role-row" key={user.id}><div className="member-info"><strong>{user.name}</strong><small>{user.email}</small></div><span className="admin-user-email">{user.email}</span><select aria-label={`Системна роль: ${user.name}`} value={user.role === 'owner' ? 'admin' : user.role} onChange={event => changeSystemRole(user.id, event.target.value)} disabled={user.role === 'owner'}><option value="user">Користувач</option><option value="manager">Менеджер</option><option value="admin">Адміністратор</option></select><select aria-label={`Додаткова роль: ${user.name}`} value={['admin', 'owner'].includes(user.role) || Number(user.is_support_agent) === 1 ? 'support' : ''} onChange={event => changeSupportRole(user.id, event.target.value === 'support')} disabled={['admin', 'owner'].includes(user.role)}><option value="">Без додаткової ролі</option><option value="support">Підтримка</option></select><button type="button" className="outline-btn" onClick={() => { setError(''); setEditingUser({ id: user.id, name: user.name, email: user.email }); }}>Редагувати</button></div>)}</div></section>;
  const adminWorkspaceGroupPanel = isAdmin && <section className="panel admin-workspace-groups-panel"><div className="panel-heading"><div><h2>Менеджер - простір - група</h2><p>Усі робочі простори в одному списку. Запрошуйте людей, призначайте їх до групи або прибирайте з групи чи простору.</p></div><span className="count-pill">{(data.admin_workspace_roster || []).reduce((total, workspace) => total + workspace.members.length, 0)}</span></div><label className="admin-role-search"><Icon name="search" /><input type="search" value={workspaceRosterSearch} onChange={event => setWorkspaceRosterSearch(event.target.value)} placeholder="Пошук менеджера, простору або учасника…" /></label><div className="admin-workspace-roster">{(data.admin_workspace_roster || []).filter(workspace => `${workspace.manager_name} ${workspace.manager_email} ${workspace.workspace_name} ${workspace.members.map(member => `${member.name} ${member.email}`).join(' ')}`.toLowerCase().includes(workspaceRosterSearch.trim().toLowerCase())).map(workspace => <section className="admin-workspace-group" key={workspace.manager_id}><div className="admin-workspace-group-heading"><div><strong>{workspace.manager_name} <span>-</span> {workspace.workspace_name}</strong><small>{workspace.manager_email}</small></div><form className="admin-workspace-invite" onSubmit={event => addMemberToWorkspace(event, workspace)}><input name="email" type="email" required placeholder="Email для запрошення" aria-label={`Email для запрошення до простору ${workspace.workspace_name}`} /><select name="group_id" aria-label="Група для запрошення"><option value="">Без групи</option>{workspace.groups.map(group => <option key={group.id} value={group.id}>{group.name}</option>)}</select><button className="primary-action" type="submit">Запросити</button></form></div><div className="admin-workspace-table-wrap"><div className="admin-workspace-table admin-workspace-table-head"><span>Учасник</span><span>Група в просторі</span><span>Керування</span></div>{workspace.members.length ? workspace.members.map(member => { const savingKey = `${workspace.manager_id}:${member.id}`; return <div className="admin-workspace-table admin-workspace-table-row" key={member.id}><div className="member-info"><strong>{member.name}</strong><small>{member.email}</small></div><select aria-label={`Група для ${member.name} у просторі ${workspace.workspace_name}`} value={member.group_id || ''} onChange={event => assignWorkspaceGroup(member.id, event.target.value, workspace.manager_id)} disabled={savingWorkspaceGroup === savingKey}><option value="">Без групи</option>{workspace.groups.map(group => <option key={group.id} value={group.id}>{group.name}</option>)}</select><div className="admin-workspace-actions"><button type="button" className="remove-member" disabled={!member.group_id || savingWorkspaceGroup === savingKey} onClick={() => assignWorkspaceGroup(member.id, '', workspace.manager_id)}>Прибрати з групи</button><button type="button" className="outline-btn" onClick={() => removeFromWorkspace(member, workspace.manager_id)}>Видалити з простору</button></div></div>; }) : <div className="admin-workspace-empty">У просторі ще немає прийнятих учасників. Можна одразу надіслати запрошення вище.</div>}</div></section>)}</div></section>;
  const adminStats = data.admin_stats;
  const adminOverviewPanel = isAdmin && adminStats && <section className="panel admin-overview-panel"><div className="panel-heading"><div><h2>Огляд системи</h2><p>Користувачі, робочі простори та стан підписок.</p></div></div><div className="admin-overview-grid"><article><small>Усього користувачів</small><strong>{adminStats.users.total}</strong><span>{adminStats.users.verified} підтверджено · {adminStats.users.unverified} очікують</span></article><article><small>Менеджери</small><strong>{adminStats.users.managers}</strong><span>{adminStats.users.administrators} адміністраторів</span></article><article><small>Робочі простори</small><strong>{adminStats.workspaces.total}</strong><span>Старт {adminStats.workspaces.plans.start || 0} · Зростання {adminStats.workspaces.plans.growth || 0} · Команда {adminStats.workspaces.plans.team || 0} · Бізнес {adminStats.workspaces.plans.business || 0}</span></article><article><small>Обрали платний план</small><strong>{adminStats.workspaces.selected_paid}</strong><span>За тарифом робочого простору</span></article><article><small>Потенційна сума планів / міс.</small><strong>{new Intl.NumberFormat('uk-UA', { style: 'currency', currency: 'USD' }).format(adminStats.workspaces.potential_mrr)}</strong><span>Усі вибрані плани, зокрема без оплати</span></article><article><small>Підписки LiqPay</small><strong>{adminStats.subscriptions.active}</strong><span>{adminStats.subscriptions.paid} платних зараз · {adminStats.subscriptions.pending} очікують</span></article><article><small>Регулярна сума LiqPay / міс.</small><strong>{new Intl.NumberFormat('uk-UA', { style: 'currency', currency: 'USD' }).format(adminStats.subscriptions.mrr)}</strong><span>Лише активні платіжні підписки</span></article><article><small>Проєкти</small><strong>{adminStats.projects}</strong><span>У системі</span></article><article><small>Клієнти</small><strong>{adminStats.clients}</strong><span>У системі</span></article></div><p className="admin-overview-note">Вибраний платний план не означає отриманий платіж. Фактичну історію надходжень можна показати після підключення окремого журналу платежів.</p></section>;

  const pendingInvites = data.pending_invites || [];
  const pendingInvitesPanel = pendingInvites.length > 0 && <section className="panel pending-invites-panel"><div className="panel-heading"><div><h2>Очікують підтвердження</h2><p>Запрошені користувачі ще не приєдналися до команди або проєкту.</p></div><span className="count-pill">{pendingInvites.length}</span></div><div className="pending-invites-list">{pendingInvites.map(invite => { const key = `${invite.invite_type}:${invite.id}`; return <div className="pending-invite-row" key={key}><div><strong>{invite.email}</strong><small>{invite.invite_type === 'project' ? `Проєкт: ${invite.project_name}` : 'Запрошення до команди'} · {invite.invite_status === 'expired' ? 'Термін минув' : 'Ще не підтверджено'}</small></div><button type="button" className="outline-btn" disabled={resendingInvite === key} onClick={() => resendInvite(invite)}>{resendingInvite === key ? 'Надсилання…' : 'Надіслати ще раз'}</button></div>; })}</div></section>;
  return <div className="manager-team-view">
    <div className="welcome-row"><div><p className="eyebrow">КЕРУВАННЯ КОМАНДОЮ</p><h1>{isAdmin ? 'Команда та доступи' : 'Моя команда'}</h1><p className="subtext">{isAdmin ? 'Повне керування користувачами, claims, клієнтами та проєктами.' : 'Запрошуйте користувачів, призначайте клієнтів і визначайте claims.'}</p></div></div>
     {notice && <div className="team-notice success">{notice}</div>}{error && <div className="form-notice">{error}</div>}{adminOverviewPanel}{adminRolePanel}{adminWorkspaceGroupPanel}
    <div className="manager-team-grid">
      {!isAdmin && <section className="panel"><div className="panel-heading"><div><h2>Запросити користувача</h2><p>Користувач з’явиться у вашій команді після прийняття запрошення.</p></div></div><form className="invite-form" onSubmit={submitInvite}><label>Email користувача<input name="email" type="email" required placeholder="name@company.com" /></label><label>Проєкт<SearchableSelect name="project_id" options={[{ id: '', name: 'Без проєкту - загальне запрошення' }, ...data.projects]} value={inviteProject} onChange={setInviteProject} placeholder="Без проєкту - загальне запрошення" /></label><label>Група claims - можна змінити після прийняття<SearchableSelect name="group_id" options={[{ id: '', name: 'Без групи' }, ...data.groups]} value={inviteGroup} onChange={setInviteGroup} placeholder="Без групи" /></label><button className={`primary-action${participantLimitReached ? ' invite-limit-button' : ''}`} disabled={participantLimitReached}>{participantLimitReached ? 'Досягнуто ліміту учасників' : 'Відправити запрошення'}</button>{participantLimitReached && <p className="invite-limit-note">Досягнуто ліміту плану «{subscription.name}»: {participantLimit.used} з {participantLimit.limit} учасників. Щоб запросити ще, змініть план у налаштуваннях.</p>}</form></section>}
      {!isProjectLead && <section className="panel manager-group-builder"><div className="panel-heading"><div><h2>Створити групу claims</h2><p>Кожну готову роль можна створити один раз. Наявні групи можна відредагувати вище.</p></div><span className="count-pill">{data.groups.length}</span></div><form onSubmit={createGroup}><div className="group-create-grid group-claims-name"><label>Готова роль<select value={group.name} onChange={event => selectRolePreset(event.target.value)} required><option value="">Оберіть роль</option>{claimRolePresets.filter(item => item.name !== 'Керівник проєкту' || projectLeadSlotsAvailable).filter(item => item.name !== 'Модератор' || moderatorSlotsAvailable).map(item => { const exists = ownRoleNames.has(item.name.toLocaleLowerCase('uk')); return <option value={item.name} key={item.name} disabled={exists}>{item.name}{exists ? ' - вже створена' : ''}</option>; })}</select></label></div>{projectLeadLimit && <small className="claims-role-availability">Керівники проєктів: {projectLeadLimit.used} із {projectLeadLimit.limit} місць використано.</small>}{moderatorLimit && <small className="claims-role-availability">Модератори: {moderatorLimit.used} із {moderatorLimit.limit} місць використано.</small>}{selectedRoleExists && <small className="form-notice">Цю роль уже створено. Відредагуйте наявну групу claims.</small>}{claimOptions(group.permissions, togglePermission)}<button className="primary-action" disabled={!group.name.trim() || selectedRoleExists}>Створити групу</button></form></section>}
    </div>
    {pendingInvitesPanel}
    <section className="panel client-assignment-panel"><div className="panel-heading"><div><h2>Доступ до клієнтів</h2><p>{isAdmin ? 'Адміністратор може призначати будь-яких клієнтів усім користувачам.' : 'Призначайте клієнтів користувачам через мультиселект.'}</p></div><span className="count-pill">{(data.clients || []).length}</span></div><label className="client-access-search"><Icon name="search" /><input type="search" value={clientSearch} onChange={event => setClientSearch(event.target.value)} placeholder="Пошук користувача або клієнта…" /></label><div className="client-access-table"><div className="client-access-head"><span>Користувач</span><span>Email</span><span>Доступні клієнти</span></div>{users.length && data.clients?.length ? users.filter(user => (user.name + ' ' + user.email + ' ' + (user.client_ids || []).map(id => data.clients.find(client => client.id === id)?.name || '').join(' ')).toLowerCase().includes(clientSearch.trim().toLowerCase())).map(user => <div className="client-access-row" key={user.id}><div className="member-info"><strong>{user.name}</strong></div><span>{user.email}</span><SearchableMultiSelect options={data.clients} value={user.client_ids || []} onChange={ids => assignClients(user.id, ids)} placeholder="Оберіть клієнтів" name={'client_ids_' + user.id} portal /></div>) : <div className="client-access-empty">Створіть клієнта і запросіть користувача, щоб призначити доступ.</div>}</div></section>
    <section className="panel global-assignment-panel"><div className="panel-heading"><div><h2>Надати claims запрошеним користувачам</h2><p>{isAdmin ? 'Адміністратор може призначати claims усім користувачам системи.' : isProjectLead ? 'Призначайте наявні claims лише користувачам, яких запросили ви.' : 'Менеджер може змінювати групу тільки учасникам власної команди.'}</p></div><span className="count-pill">{users.length}</span></div><div className="group-table-toolbar"><strong>Користувачі</strong><input value={search} onChange={event => setSearch(event.target.value)} placeholder="Пошук за ім’ям або email…" /></div><div className="group-users-table assignment-table"><div className="group-users-head"><span>Користувач</span><span>Email</span><span>Група claims</span><span>Дія</span></div>{users.map(user => <div className="group-user-row" key={user.id}><span><i className="member-avatar">{initials(user.name)}</i>{user.name}</span><span>{user.email}</span><select value={user.group_id || ''} onChange={event => assignGroup(user.id, event.target.value)}><option value="">Без групи - тільки читання</option>{assignableGroups.map(item => <option key={item.id} value={item.id} disabled={(item.name === 'Керівник проєкту' && !projectLeadSlotsAvailable || item.name === 'Модератор' && !moderatorSlotsAvailable) && user.group_id !== item.id}>{item.name}</option>)}</select>{!isAdmin && !isProjectLead ? <button type="button" className="remove-member" onClick={() => removeFromWorkspace(user)}>Прибрати</button> : <span>-</span>}</div>)}</div></section>
    {adminGroupsPanel}{managerGroupsPanel}
  </div>;
}








