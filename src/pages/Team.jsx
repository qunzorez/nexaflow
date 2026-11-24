import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import Layout from '../components/Layout.jsx';
import Icon from '../components/Icon.jsx';
import { api, post } from '../services/api.js';
import ManagerTeamView from '../modules/team/ManagerTeamView.jsx';
import '../modules/team/team-modern.css';

export default function Team() {
  const [data, setData] = useState(null);
  const [currentUser, setCurrentUser] = useState(null);
  const [leaving, setLeaving] = useState(false);
  const [message, setMessage] = useState('');
  const [leaveMessage, setLeaveMessage] = useState('');
  const navigate = useNavigate();

  async function load(workspaceOwnerId = '') {
    const url = workspaceOwnerId ? `/api/team.php?workspace_owner_id=${encodeURIComponent(workspaceOwnerId)}` : '/api/team.php';
    const [result, me] = await Promise.all([api(url), api('/api/me.php')]);
    const isGlobalAdmin = ['admin', 'owner'].includes(me.user?.role);
    if (!me.ok || (!isGlobalAdmin && !['admin', 'owner', 'manager', 'project_lead', 'user'].includes(me.user.workspace_role || me.user.role))) {
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

  if (!data.permissions?.admin && !['admin', 'owner', 'manager', 'project_lead'].includes(currentUser?.workspace_role || currentUser?.role)) return <Layout page="Команда" user={currentUser}><div className="content"><section className="panel workspace-membership-panel"><div className="workspace-membership-copy"><span className="workspace-membership-icon"><Icon name="project" /></span><span><strong>Ви в просторі «{data.membership?.workspace_name || 'Робочий простір'}»</strong><small>Вийдіть, щоб припинити доступ до його даних. Ваш обліковий запис залишиться активним.</small>{leaveMessage && <small role="status">{leaveMessage}</small>}</span></div><button type="button" className="workspace-leave-button" disabled={leaving} onClick={async () => { if (!window.confirm(`Вийти з простору «${data.membership?.workspace_name || 'Робочий простір'}»?`)) return; setLeaving(true); const result = await post('/api/team.php', { action: 'leave_workspace' }); setLeaving(false); setLeaveMessage(result.message || ''); if (result.ok) window.location.assign('/dashboard'); }}><Icon name={leaving ? 'loading' : 'logout'} />{leaving ? 'Виходимо…' : 'Вийти з простору'}</button></section></div></Layout>;

  const canLeaveCurrentWorkspace = Boolean(data.permissions?.admin && data.current_workspace && !data.current_workspace.is_own && String(data.current_workspace.id) !== String(currentUser?.id));
  async function leaveWorkspace() {
    if (!window.confirm(`Вийти з простору «${data.current_workspace?.name || 'робочий простір'}»? Ваш обліковий запис залишиться активним.`)) return;
    setLeaving(true); setLeaveMessage('');
    const result = await post('/api/team.php', { action: 'leave_workspace' });
    setLeaving(false); setLeaveMessage(result.message || '');
    if (result.ok) window.location.assign('/dashboard');
  }

  return <Layout page="Команда" user={currentUser}>
    <div className="content">
      {canLeaveCurrentWorkspace && <section className="panel workspace-membership-panel"><div className="workspace-membership-copy"><span className="workspace-membership-icon"><Icon name="project" /></span><span><strong>Ви переглядаєте простір «{data.current_workspace.name || 'Робочий простір'}»</strong><small>Вихід закриє доступ до цього простору, але не видалить ваш обліковий запис.</small>{leaveMessage && <small role="status">{leaveMessage}</small>}</span></div><button type="button" className="workspace-leave-button" disabled={leaving} onClick={leaveWorkspace}><Icon name={leaving ? 'loading' : 'logout'} />{leaving ? 'Виходимо…' : 'Вийти з простору'}</button></section>}
      <ManagerTeamView data={data} onReload={load} isAdmin={Boolean(data.permissions?.admin)} isProjectLead={Boolean(data.permissions?.project_lead)} currentUserId={currentUser?.id} subscription={currentUser?.subscription} />
    </div>
  </Layout>;
}
