import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import Notifications from './Notifications.jsx';
import { api, post } from '../services/api.js';

const roleNames = { admin: 'Адміністратор', owner: 'Власник', manager: 'Менеджер', user: 'Користувач', viewer: 'Переглядач' };
export default function Header({ page, user, onMenu }) {
  const [profile, setProfile] = useState(user); const [open, setOpen] = useState(false); const menuRef = useRef(null); const navigate = useNavigate();
  useEffect(() => { setProfile(user); }, [user]);
  useEffect(() => { api('/api/me.php').then(result => result.ok && setProfile(result.user)); }, []);
  useEffect(() => { const close = event => { if (!menuRef.current?.contains(event.target)) setOpen(false); }; document.addEventListener('mousedown', close); return () => document.removeEventListener('mousedown', close); }, []);
  const canCreateProject = ['admin', 'owner', 'manager'].includes(profile?.role);
  async function logout() { await post('/api/logout.php', {}); navigate('/'); }
  const avatar = profile?.avatar_url ? <img src={profile.avatar_url} alt="" /> : (profile?.name || 'К').charAt(0).toUpperCase();
  return <header className="topbar"><div className="breadcrumb"><Link to="/dashboard">Огляд</Link><b>/</b><strong>{page}</strong></div><div className="top-actions"><div className="header-create-actions"><Link className="header-create-task" to="/tasks?create=1">＋ Задача</Link>{canCreateProject && <Link className="header-create-project" to="/projects/new">＋ Проєкт</Link>}</div><Notifications /><div className="profile-menu-wrap" ref={menuRef}><button className="user-chip" type="button" onClick={() => setOpen(value => !value)}><span className="user-avatar">{avatar}</span><span className="user-chip-copy"><strong>{profile?.name || 'Користувач'}</strong><small>{roleNames[profile?.role] || 'Користувач'}</small></span><span className="profile-chevron">⌄</span></button>{open && <div className="profile-dropdown"><div className="profile-dropdown-head"><span className="user-avatar large">{avatar}</span><div><strong>{profile?.name}</strong><small>{profile?.email}</small><em>{roleNames[profile?.role] || 'Користувач'}</em></div></div><div className="profile-dropdown-links"><Link to="/settings" onClick={() => setOpen(false)}>⚙ Налаштування профілю</Link><Link to="/dashboard" onClick={() => setOpen(false)}>⌂ Мій Dashboard</Link></div><button className="profile-logout" type="button" onClick={logout}>↪ Вийти</button></div>}</div></div></header>;
}
