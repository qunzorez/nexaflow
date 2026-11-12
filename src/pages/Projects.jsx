import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import Layout from '../components/Layout.jsx';
import { api } from '../services/api.js';
import ProjectsModule from '../modules/projects/ProjectsModule.jsx';

const canCreate = role => ['admin', 'owner', 'manager'].includes(role);

export default function Projects() {
  const [data, setData] = useState(null); const navigate = useNavigate();
  async function load() { const result = await api('/api/dashboard.php'); if (!result.ok) return navigate('/'); setData(result); }
  useEffect(() => { load(); }, []);
  if (!data) return <div className="loading-screen">Завантаження проєктів…</div>;
  return <Layout page="Проєкти" user={data.user}><div className="content projects-page"><ProjectsModule user={data.user} projects={data.projects} /></div></Layout>;
}
