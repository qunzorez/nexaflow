import Icon from './Icon.jsx';
import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import Notifications from './Notifications.jsx';
import { api, post } from '../services/api.js';
import { isPlanLimitReached, planLimitHint } from '../utils/planLimits.js';

const roleNames = { admin: 'Адміністратор', owner: 'Власник', manager: 'Менеджер', project_lead: 'Керівник проєкту', user: 'Користувач', viewer: 'Переглядач' };
export default function Header({ page, user, onMenu }) {
  const [profile, setProfile] = useState(user); const [open, setOpen] = useState(false); const menuRef = useRef(null); const navigate = useNavigate();
  const moduleCacheKey = `${user?.id || "guest"}.${user?.active_workspace_id || user?.subscription?.workspace_id || "default"}`;
  const [activeClientModule, setActiveClientModule] = useState(() => {
    try { return window.localStorage.getItem(`nexaflow.client.module.${moduleCacheKey}`) || user?.active_client_module || ""; }
    catch { return user?.active_client_module || ""; }
  });
  useEffect(() => { setProfile(user); }, [user]);
  useEffect(() => { api('/api/me.php').then(result => result.ok && setProfile(result.user)); const syncProfile = event => event.detail && setProfile(event.detail); window.addEventListener('nexaflow:profile-updated', syncProfile); return () => window.removeEventListener('nexaflow:profile-updated', syncProfile); }, []);
  useEffect(() => {
    let active = true;
    try { setActiveClientModule(window.localStorage.getItem(`nexaflow.client.module.${moduleCacheKey}`) || user?.active_client_module || ""); } catch {}
    api("/api/clients.php").then(result => {
      if (!active || !result.ok || !result.active_client_module) return;
      setActiveClientModule(result.active_client_module);
      try { window.localStorage.setItem(`nexaflow.client.module.${moduleCacheKey}`, result.active_client_module); } catch {}
    });
    return () => { active = false; };
  }, [moduleCacheKey, user?.active_client_module]);
  useEffect(() => { const close = event => { if (!menuRef.current?.contains(event.target)) setOpen(false); }; document.addEventListener('mousedown', close); return () => document.removeEventListener('mousedown', close); }, []);
  const activeRole = profile?.workspace_role || profile?.role;
  const canCreateProject = ['admin', 'owner', 'manager', 'project_lead'].includes(activeRole);
  const taskLimitReached = isPlanLimitReached(profile?.subscription, 'tasks_month');
  const projectLimitReached = isPlanLimitReached(profile?.subscription, 'projects');
  const dentistryCalendarUnavailable = activeClientModule === "dentistry" && profile?.subscription?.features?.calendar === false;
  async function logout() { await post('/api/logout.php', {}); navigate('/'); }
  const avatar = profile?.avatar_url ? <img src={profile.avatar_url} alt="" /> : (profile?.name || 'К').charAt(0).toUpperCase();
  return <header className="topbar"><div className="breadcrumb"><Link to="/dashboard">Огляд</Link><b>/</b><strong>{page}</strong></div><div className="top-actions"><div className="header-create-actions">{activeClientModule === "dentistry" && <Link aria-disabled={dentistryCalendarUnavailable} title={dentistryCalendarUnavailable ? "Календар недоступний у вашому тарифі" : undefined} onClick={event => dentistryCalendarUnavailable && event.preventDefault()} className={`header-create-appointment${dentistryCalendarUnavailable ? ' plan-limit-disabled' : ''}`} to="/appointments"><Icon name="plus" /> Новий запис</Link>}<Link aria-disabled={taskLimitReached} title={taskLimitReached ? planLimitHint(profile?.subscription, 'tasks_month') : undefined} onClick={event => taskLimitReached && event.preventDefault()} className={`header-create-task${taskLimitReached ? ' plan-limit-disabled' : ''}`} to="/tasks?create=1"><Icon name="plus" /> Задача</Link>{canCreateProject && <Link aria-disabled={projectLimitReached} title={projectLimitReached ? planLimitHint(profile?.subscription, 'projects') : undefined} onClick={event => projectLimitReached && event.preventDefault()} className={`header-create-project${projectLimitReached ? ' plan-limit-disabled' : ''}`} to="/projects/new"><Icon name="plus" /> Проєкт</Link>}</div><Notifications /><div className="profile-menu-wrap" ref={menuRef}><button className="user-chip" type="button" onClick={() => setOpen(value => !value)}><span className="user-avatar">{avatar}</span><span className="user-chip-copy"><strong>{profile?.name || 'Користувач'}</strong><small>{roleNames[activeRole] || 'Користувач'}</small></span><span className="profile-chevron"><Icon name="down" /></span></button>{open && <div className="profile-dropdown"><div className="profile-dropdown-head"><span className="user-avatar large">{avatar}</span><div><strong>{profile?.name}</strong><small>{profile?.email}</small><em>{roleNames[activeRole] || 'Користувач'}</em></div></div><div className="profile-dropdown-links"><Link to="/settings" onClick={() => setOpen(false)}><Icon name="settings" /> Налаштування профілю</Link><Link to="/dashboard" onClick={() => setOpen(false)}><Icon name="home" /> Мій Dashboard</Link></div><button className="profile-logout" type="button" onClick={logout}><Icon name="logout" /> Вийти</button></div>}</div></div></header>;
}
