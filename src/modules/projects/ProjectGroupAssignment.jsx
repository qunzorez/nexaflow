import { useMemo, useState } from 'react';
import { post } from '../../services/api.js';
import './project-group-assignment.css';

const initials = name => String(name || '?').split(' ').map(part => part[0]).slice(0, 2).join('').toUpperCase();

export default function ProjectGroupAssignment({ projectId, members, groups, onChanged }) {
  const [search, setSearch] = useState(''); const [notice, setNotice] = useState('');
  const visible = useMemo(() => members.filter(item => `${item.name} ${item.email}`.toLowerCase().includes(search.toLowerCase().trim())), [members, search]);
  async function assign(userId, groupId) { const result = await post('/api/project.php', { id: projectId, action: 'add_member', user_id: userId, role: 'member', group_id: groupId }); setNotice(result.message); if (result.ok) onChanged(); }
  return <section className="management-section project-group-assignment"><div className="management-section-head"><div><h3>Призначити групи учасникам</h3><p>Окремо призначайте глобальні групи учасникам цього проєкту.</p></div><span>{members.length}</span></div><div className="group-table-toolbar"><strong>Користувачі проєкту</strong><input value={search} onChange={event => setSearch(event.target.value)} placeholder="Пошук за ім’ям або email…" /></div>{notice && <div className="team-notice success">{notice}</div>}<div className="group-users-table assignment-table"><div className="group-users-head"><span>Користувач</span><span>Email</span><span>Група</span></div>{visible.map(item => <div className="group-user-row" key={item.user_id}><span><i className="member-avatar">{initials(item.name)}</i>{item.name}</span><span>{item.email}</span><select value={item.group_ids?.[0] || ''} onChange={event => assign(item.user_id, event.target.value)}><option value="">Учасник</option>{groups.map(group => <option key={group.id} value={group.id}>{group.role_name || group.name}</option>)}</select></div>)}</div></section>;
}
