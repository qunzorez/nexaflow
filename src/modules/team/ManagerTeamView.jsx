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

export default function ManagerTeamView({ data, onReload, isAdmin = false }) {
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');
  const [clientSearch, setClientSearch] = useState('');
  const [groupSearch, setGroupSearch] = useState('');
  const [group, setGroup] = useState({ name: '', permissions: ['read'] });
  const [editing, setEditing] = useState(null);
  const [inviteProject, setInviteProject] = useState('');
  const [inviteGroup, setInviteGroup] = useState('');
  const users = useMemo(() => data.users.filter(user => `${user.name} ${user.email}`.toLowerCase().includes(search.toLowerCase().trim())), [data.users, search]);

  async function submitInvite(event) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const result = await post('/api/invite.php', { action: 'send', email: form.get('email'), project_id: form.get('project_id'), group_id: form.get('group_id'), role: 'member' });
    setNotice(result.message);
    if (result.ok) { event.currentTarget.reset(); setInviteProject(''); setInviteGroup(''); }
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
  async function changeSystemRole(userId, role) {
    const result = await post('/api/team.php', { action: 'set_user_role', user_id: userId, role });
    setNotice(result.message); if (result.ok) onReload();
  }
  function togglePermission(key) {
    setGroup(current => ({ ...current, permissions: current.permissions.includes(key) ? current.permissions.filter(item => item !== key) : [...current.permissions, key] }));
  }
  function editPermission(key) {
    setEditing(current => ({ ...current, permissions: current.permissions.includes(key) ? current.permissions.filter(item => item !== key) : [...current.permissions, key] }));
  }
  function selectRolePreset(value) {
    const preset = claimRolePresets.find(item => item.name === value);
    if (preset) setGroup({ name: preset.name, permissions: [...preset.permissions] });
  }
  function selectEditRolePreset(value) {
    const preset = claimRolePresets.find(item => item.name === value);
    if (preset) setEditing(current => ({ ...current, name: preset.name, permissions: [...preset.permissions] }));
  }
  const claimOptions = (value, toggle) => <div className="permission-list claims-grid">{Object.entries(permissionLabels).map(([key, label]) => <label className={value.includes(key) ? 'claim-card selected' : 'claim-card'} key={key}><input type="checkbox" checked={value.includes(key)} onChange={() => toggle(key)} /><span className="claim-icon"><Icon name={permissionIcons[key]} /></span><span className="claim-copy"><strong>{label}</strong><small>{permissionDescriptions[key]}</small></span><span className="claim-check"><Icon name="check" /></span></label>)}</div>;
  const editForm = editing && <div className="managed-group managed-group-edit"><div className="group-create-grid group-claims-name"><label>Роль групи<select value={editing.name} onChange={event => selectEditRolePreset(event.target.value)}><option value="">Оберіть роль</option>{!claimRolePresets.some(item => item.name === editing.name) && <option value={editing.name}>{editing.name}</option>}{claimRolePresets.map(item => <option value={item.name} key={item.name}>{item.name}</option>)}</select></label></div>{claimOptions(editing.permissions || [], editPermission)}<div className="group-edit-actions"><button type="button" className="primary-action" onClick={() => groupAction({ action: 'update_global_group', group_id: editing.id, name: editing.name, permissions: editing.permissions })}>Зберегти claims</button><button type="button" className="outline-btn" onClick={() => setEditing(null)}>Скасувати</button></div></div>;
  const groupsByOwner = useMemo(() => data.groups.reduce((result, item) => { const key = item.group_owner_id || 'unknown'; (result[key] ||= []).push(item); return result; }, {}), [data.groups]);
  const visibleGroupsByOwner = useMemo(() => Object.fromEntries(Object.entries(groupsByOwner).map(([ownerId, items]) => [ownerId, items.filter(item => `${item.name} ${data.users.find(user => String(user.id) === String(ownerId))?.name || ''} ${data.users.find(user => String(user.id) === String(ownerId))?.email || ''}`.toLowerCase().includes(groupSearch.trim().toLowerCase()))]).filter(([, items]) => items.length)), [groupsByOwner, data.users, groupSearch]);
  const adminGroupsPanel = isAdmin && <section className="panel global-groups-list admin-groups-panel"><div className="panel-heading"><div><h2>Групи claims</h2><p>Усі глобальні групи, згруповані за менеджером, який їх створив.</p></div><span className="count-pill">{data.groups.length}</span></div><label className="admin-role-search claims-group-search"><Icon name="search" /><input type="search" value={groupSearch} onChange={event => setGroupSearch(event.target.value)} placeholder="Пошук групи або менеджера…" /></label><div className="managed-groups claims-group-list">{editing ? editForm : Object.entries(visibleGroupsByOwner).map(([ownerId, items]) => <div className="admin-group-owner" key={ownerId}><div className="admin-group-owner-title"><strong>{data.users.find(user => String(user.id) === String(ownerId))?.name || 'Невідомий автор'}</strong><small>{data.users.find(user => String(user.id) === String(ownerId))?.email || 'Автор групи'}</small></div>{items.map(item => <GroupSummary key={item.id} item={item} onEdit={setEditing} onDelete={id => groupAction({ action: 'delete_global_group', group_id: id })} />)}</div>)}</div></section>;
  const visibleManagerGroups = useMemo(() => data.groups.filter(item => `${item.name} ${item.role_name || ''}`.toLowerCase().includes(groupSearch.trim().toLowerCase())), [data.groups, groupSearch]);
  const managerGroupsPanel = !isAdmin && <section className="panel global-groups-list manager-groups-panel"><div className="panel-heading"><div><h2>Мої групи claims</h2><p>Групи claims, які створили ви. Їх можна призначати запрошеним користувачам.</p></div><span className="count-pill">{data.groups.length}</span></div><label className="admin-role-search claims-group-search"><Icon name="search" /><input type="search" value={groupSearch} onChange={event => setGroupSearch(event.target.value)} placeholder="Пошук моїх груп…" /></label><div className="managed-groups claims-group-list">{editing ? editForm : visibleManagerGroups.map(item => <GroupSummary key={item.id} item={item} onEdit={setEditing} onDelete={id => groupAction({ action: 'delete_global_group', group_id: id })} />)}</div>{!editing && visibleManagerGroups.length === 0 && <p className="client-access-empty">У вас ще немає створених груп claims.</p>}</section>;
  const adminRolePanel = isAdmin && <section className="panel admin-role-panel"><div className="panel-heading"><div><h2>Глобальні ролі користувачів</h2><p>Адміністратор має повний доступ і може призначати адміністраторів, менеджерів та користувачів.</p></div><span className="count-pill">{data.users.length}</span></div><label className="admin-role-search"><Icon name="search" /><input type="search" value={search} onChange={event => setSearch(event.target.value)} placeholder="Пошук за ім’ям або email…" /></label><div className="admin-role-table"><div className="admin-role-head"><span>Користувач</span><span>Email</span><span>Системна роль</span></div>{data.users.filter(user => `${user.name} ${user.email}`.toLowerCase().includes(search.toLowerCase().trim())).sort((a, b) => ({ owner: 0, admin: 0, manager: 1, user: 2 }[a.role] ?? 3) - ({ owner: 0, admin: 0, manager: 1, user: 2 }[b.role] ?? 3)).map(user => <div className="admin-role-row" key={user.id}><div className="member-info"><strong>{user.name}</strong><small>{user.email}</small></div><select value={user.role === 'owner' ? 'admin' : user.role} onChange={event => changeSystemRole(user.id, event.target.value)} disabled={user.role === 'owner'}><option value="user">Користувач</option><option value="manager">Менеджер</option><option value="admin">Адміністратор</option></select></div>)}</div></section>;

  return <div className="manager-team-view">
    <div className="welcome-row"><div><p className="eyebrow">КЕРУВАННЯ КОМАНДОЮ</p><h1>{isAdmin ? 'Команда та доступи' : 'Моя команда'}</h1><p className="subtext">{isAdmin ? 'Повне керування користувачами, claims, клієнтами та проєктами.' : 'Запрошуйте користувачів, призначайте клієнтів і визначайте claims.'}</p></div></div>
    {notice && <div className="team-notice success">{notice}</div>}{error && <div className="form-notice">{error}</div>}{adminRolePanel}
    <div className="manager-team-grid">
      <section className="panel"><div className="panel-heading"><div><h2>Запросити користувача</h2><p>Користувач з’явиться у вашій команді після прийняття запрошення.</p></div></div><form className="invite-form" onSubmit={submitInvite}><label>Email користувача<input name="email" type="email" required placeholder="name@company.com" /></label><label>Проєкт<SearchableSelect name="project_id" options={[{ id: '', name: 'Без проєкту — загальне запрошення' }, ...data.projects]} value={inviteProject} onChange={setInviteProject} placeholder="Без проєкту — загальне запрошення" /></label><label>Група claims — можна змінити після прийняття<SearchableSelect name="group_id" options={[{ id: '', name: 'Без групи' }, ...data.groups]} value={inviteGroup} onChange={setInviteGroup} placeholder="Без групи" /></label><button className="primary-action">Відправити запрошення</button></form></section>
      <section className="panel manager-group-builder"><div className="panel-heading"><div><h2>Створити групу claims</h2><p>Оберіть готову роль — назва і базові права будуть встановлені без помилок.</p></div><span className="count-pill">{data.groups.length}</span></div><form onSubmit={createGroup}><div className="group-create-grid group-claims-name"><label>Готова роль<select value={group.name} onChange={event => selectRolePreset(event.target.value)} required><option value="">Оберіть роль</option>{claimRolePresets.map(item => <option value={item.name} key={item.name}>{item.name}</option>)}</select></label></div>{claimOptions(group.permissions, togglePermission)}<button className="primary-action" disabled={!group.name.trim()}>Створити групу</button></form></section>
    </div>
    <section className="panel client-assignment-panel"><div className="panel-heading"><div><h2>Доступ до клієнтів</h2><p>{isAdmin ? 'Адміністратор може призначати будь-яких клієнтів усім користувачам.' : 'Призначайте клієнтів користувачам через мультиселект.'}</p></div><span className="count-pill">{(data.clients || []).length}</span></div><label className="client-access-search"><Icon name="search" /><input type="search" value={clientSearch} onChange={event => setClientSearch(event.target.value)} placeholder="Пошук користувача або клієнта…" /></label><div className="client-access-table"><div className="client-access-head"><span>Користувач</span><span>Email</span><span>Доступні клієнти</span></div>{data.users.length && data.clients?.length ? data.users.filter(user => (user.name + ' ' + user.email + ' ' + (user.client_ids || []).map(id => data.clients.find(client => client.id === id)?.name || '').join(' ')).toLowerCase().includes(clientSearch.trim().toLowerCase())).map(user => <div className="client-access-row" key={user.id}><div className="member-info"><strong>{user.name}</strong></div><span>{user.email}</span><SearchableMultiSelect options={data.clients} value={user.client_ids || []} onChange={ids => assignClients(user.id, ids)} placeholder="Оберіть клієнтів" name={'client_ids_' + user.id} /></div>) : <div className="client-access-empty">Створіть клієнта і запросіть користувача, щоб призначити доступ.</div>}</div></section>
    <section className="panel global-assignment-panel"><div className="panel-heading"><div><h2>Надати claims запрошеним користувачам</h2><p>{isAdmin ? 'Адміністратор може призначати claims усім користувачам системи.' : 'Менеджер може змінювати групу тільки учасникам власної команди.'}</p></div><span className="count-pill">{data.users.length}</span></div><div className="group-table-toolbar"><strong>Користувачі</strong><input value={search} onChange={event => setSearch(event.target.value)} placeholder="Пошук за ім’ям або email…" /></div><div className="group-users-table assignment-table"><div className="group-users-head"><span>Користувач</span><span>Email</span><span>Група claims</span></div>{users.map(user => <div className="group-user-row" key={user.id}><span><i className="member-avatar">{initials(user.name)}</i>{user.name}</span><span>{user.email}</span><select value={user.group_id || ''} onChange={event => assignGroup(user.id, event.target.value)}><option value="">Без групи — тільки читання</option>{data.groups.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></div>)}</div></section>
    {adminGroupsPanel}{managerGroupsPanel}
  </div>;
}








