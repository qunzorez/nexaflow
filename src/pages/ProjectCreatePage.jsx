import Icon from '../components/Icon.jsx';
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import Layout from '../components/Layout.jsx';
import { api } from '../services/api.js';
import ProjectForm from '../modules/projects/ProjectForm.jsx';
import './project-create.css';

export default function ProjectCreatePage() {
  const [user, setUser] = useState(null); const [users, setUsers] = useState([]); const navigate = useNavigate();
  useEffect(() => { Promise.all([api('/api/me.php'), api('/api/team.php')]).then(([me, team]) => { if (!me.ok || !['admin', 'owner', 'manager'].includes(me.user.role)) return navigate('/projects', { replace: true }); setUser(me.user); setUsers(team.ok ? team.users : []); }); }, [navigate]);
  if (!user) return <div className="loading-screen">Перевірка прав доступу…</div>;
  const isAdmin = ['admin', 'owner'].includes(user.role);
  return <Layout page="Створити проєкт" user={user}><div className="content project-create-page"><div className="page-back"><button onClick={() => navigate('/projects')}><Icon name="back" /> Назад до проєктів</button></div><section className="project-create-layout"><div><p className="eyebrow">НОВИЙ ПРОЄКТ</p><h1>Створити проєкт</h1><p className="subtext">Опишіть проєкт, додайте команду та почніть роботу в одному просторі.</p></div><section className="panel"><ProjectForm users={users} isAdmin={isAdmin} /></section></section></div></Layout>;
}
