import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import Layout from '../components/Layout.jsx';
import { api } from '../services/api.js';
import ManagerTeamView from '../modules/team/ManagerTeamView.jsx';
import '../modules/team/team-modern.css';

export default function Team() {
  const [data, setData] = useState(null);
  const [currentUser, setCurrentUser] = useState(null);
  const navigate = useNavigate();

  async function load() {
    const [result, me] = await Promise.all([api('/api/team.php'), api('/api/me.php')]);
    if (!me.ok || !['admin', 'owner', 'manager'].includes(me.user.role)) {
      navigate('/dashboard', { replace: true });
      return;
    }
    if (!result.ok) {
      navigate('/dashboard', { replace: true });
      return;
    }
    setData(result);
    setCurrentUser(me.user);
  }

  useEffect(() => { load(); }, []);

  if (!data) return <div className="loading-screen">Завантаження команди…</div>;

  return <Layout page="Команда" user={currentUser}>
    <div className="content">
      <ManagerTeamView data={data} onReload={load} isAdmin={Boolean(data.permissions?.admin)} />
    </div>
  </Layout>;
}
