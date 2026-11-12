import { Link, useNavigate } from 'react-router-dom';
import { post } from '../services/api.js';
import './sidebar-icons.css';

export default function Sidebar({ user, open }) {
  const navigate = useNavigate();
  const canManage = ['admin', 'owner', 'manager'].includes(user?.role);
  const isAdmin = ['admin', 'owner'].includes(user?.role);
  async function logout() { await post('/api/logout.php', {}); navigate('/'); }
  return <aside className={`sidebar ${open ? 'open' : ''}`}><Link className="side-brand" to="/dashboard"><div className="brand-mark"><span>+</span></div><div><strong>NEXAFLOW</strong><small>SRM SYSTEM</small></div></Link><div className="workspace"><span className="workspace-dot" /><div><small>Робочий простір</small><strong>Моя команда</strong></div><span className="chevron">⌄</span></div><nav><p className="nav-label">ОСНОВНЕ</p><Link className="nav-item" to="/dashboard"><span>⌂</span> Огляд</Link><a className="nav-item disabled" href="#"><span>◎</span> Клієнти <b>0</b></a><Link className="nav-item" to="/projects"><span>▣</span> Проєкти</Link><Link className="nav-item" to="/tasks"><span>✓</span> Задачі</Link>{canManage && <Link className="nav-item" to="/team"><span className="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0M16 6.5a2.5 2.5 0 0 1 0 5M16 14a5 5 0 0 1 4.5 5"/></svg></span> Команда</Link>}{isAdmin && <Link className="nav-item" to="/notifications"><span className="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg></span> Сповіщення</Link>}<p className="nav-label">РОБОЧІ ІНСТРУМЕНТИ</p><a className="nav-item disabled" href="#"><span>◷</span> Календар</a><a className="nav-item disabled" href="#"><span>▤</span> Документи</a><a className="nav-item disabled" href="#"><span>⌁</span> Аналітика</a></nav><div className="sidebar-bottom"><button className="logout" onClick={logout}><span>↪</span> Вийти</button></div></aside>;
}
