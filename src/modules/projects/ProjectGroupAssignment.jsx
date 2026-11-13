import { useMemo, useState } from 'react';
import './project-group-assignment.css';

const initials = name => String(name || '?').split(' ').map(part => part[0]).slice(0, 2).join('').toUpperCase();
const avatarSource = value => {
  const source = String(value || '').trim();
  if (!source) return '';
  return /^(?:data:|blob:|https?:|\/)/i.test(source) ? source : `/${source}`;
};

function MemberAvatar({ member }) {
  const [imageFailed, setImageFailed] = useState(false);
  const source = avatarSource(member.avatar_url);
  return <i className="member-avatar">{source && !imageFailed ? <img src={source} alt="" onError={() => setImageFailed(true)} /> : initials(member.name)}</i>;
}

export default function ProjectGroupAssignment({ members }) {
  const [search, setSearch] = useState('');
  const visible = useMemo(() => members.filter(item => `${item.name} ${item.email}`.toLowerCase().includes(search.toLowerCase().trim())), [members, search]);
  return <section className="management-section project-group-assignment"><div className="management-section-head"><div><h3>Учасники проєкту</h3><p>Переглядайте учасників і їхні призначені claims. Керування доступом виконується на сторінці «Команда».</p></div><span>{members.length}</span></div><div className="group-table-toolbar"><strong>Користувачі проєкту</strong><input value={search} onChange={event => setSearch(event.target.value)} placeholder="Пошук за ім’ям або email…" /></div><div className="group-users-table assignment-table"><div className="group-users-head"><span>Користувач</span><span>Email</span><span>Група claims</span></div>{visible.map(item => <div className="group-user-row" key={item.user_id}><span><MemberAvatar member={item} />{item.name}</span><span>{item.email}</span><span className={`role-badge project-member-claim${item.group_names ? '' : ' no-claim'}`}>{item.group_names || 'Без групи — тільки читання'}</span></div>)}</div></section>;
}
