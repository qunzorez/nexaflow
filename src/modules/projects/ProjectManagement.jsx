import Icon from '../../components/Icon.jsx';
import { useState } from 'react';
import { post } from '../../services/api.js';
import SearchableUserSelect from './SearchableUserSelect.jsx';
import './project-management.css';
import './project-management-buttons.css';
import './project-management-cleanup.css';
import './project-member-add.css';

const initials = name => String(name || '?').split(' ').map(part => part[0]).slice(0, 2).join('').toUpperCase();

export default function ProjectManagement({ projectId, canManageTeam = false, members = [], groups = [], availableUsers = [], onChanged }) {
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');
  const [showAdd, setShowAdd] = useState(false);
  const [mode, setMode] = useState('existing');
  const [selectedUser, setSelectedUser] = useState('');

  async function action(payload) {
    setNotice(''); setError('');
    const result = await post('/api/project.php', { id: projectId, ...payload });
    if (!result.ok) return setError(result.message);
    setNotice(result.message); onChanged();
  }

  function addExisting() {
    if (!selectedUser) return setError('Оберіть користувача.');
    action({ action: 'add_member', user_id: selectedUser, role: 'member', group_id: '' });
    setSelectedUser(''); setShowAdd(false);
  }
  async function inviteNew(event) {
    event.preventDefault(); setNotice(''); setError('');
    const form = new FormData(event.currentTarget);
    const result = await post('/api/invite.php', { action: 'send', email: form.get('email'), project_id: projectId, role: 'member' });
    if (!result.ok) return setError(result.message);
    setNotice(result.message); event.currentTarget.reset(); setShowAdd(false);
  }

  const availableForProject = availableUsers.filter(item => !members.some(member => member.user_id === item.id));

  return <div className="project-management">
    <div className="management-title"><p className="eyebrow">КЕРУВАННЯ ПРОЄКТОМ</p><h2>Команда проєкту</h2><p>Додавайте учасників до цього проєкту. Глобальні claims створюються та призначаються на сторінці «Команда».</p></div>
    {notice && <div className="team-notice success">{notice}</div>}{error && <div className="form-notice">{error}</div>}
    <section className="management-section">
      <div className="management-section-head"><div><h3>Учасники проєкту</h3><p>Переглядайте учасників і призначені їм claims. Керування виконується на сторінці «Команда».</p></div><div className="section-head-actions"><button type="button" className="primary-action add-member-btn" disabled={!canManageTeam} onClick={() => setShowAdd(value => !value)}><Icon name="plus" /> Додати учасника</button><span>{members.length}</span></div></div>
      {showAdd && canManageTeam && <div className="member-add-panel"><div className="add-mode-tabs"><button type="button" className={mode === 'existing' ? 'active' : ''} onClick={() => setMode('existing')}>Додати наявного</button><button type="button" className={mode === 'invite' ? 'active' : ''} onClick={() => setMode('invite')}>Запросити по email</button></div>{mode === 'existing' ? <div className="add-member-row existing-member-row"><SearchableUserSelect users={availableForProject} value={selectedUser} onChange={setSelectedUser} /><button type="button" className="primary-action" onClick={addExisting}>Додати</button></div> : <form className="add-member-row" onSubmit={inviteNew}><input type="email" name="email" required placeholder="name@company.com" /><button className="primary-action">Відправити запрошення</button></form>}</div>}
      <div className="member-management-list">{members.map(item => <div className="managed-member" key={item.user_id}><span className="member-avatar">{initials(item.name)}</span><div className="member-info"><strong>{item.name}</strong><small>{item.email}</small></div><span className="role-badge project-member-claim">{item.group_names || 'Учасник'}</span><button type="button" className="remove-member" disabled={!canManageTeam} onClick={() => action({ action: 'remove_member', user_id: item.user_id })}>Видалити</button></div>)}</div>
    </section>
  </div>;
}
