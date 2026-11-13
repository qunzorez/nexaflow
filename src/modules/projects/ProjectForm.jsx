import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { createProject } from './projectApi.js';
import './project-form.css';

const initials = name => String(name || '?').split(' ').map(part => part[0]).slice(0, 2).join('').toUpperCase();

export default function ProjectForm({ users = [], isAdmin = false }) {
  const [error, setError] = useState('');
  const [selectedUsers, setSelectedUsers] = useState([]);
  const [userSearch, setUserSearch] = useState('');
  const navigate = useNavigate();
  const visibleUsers = users.filter(user => `${user.name} ${user.email}`.toLowerCase().includes(userSearch.toLowerCase().trim()));

  function toggleUser(id) { setSelectedUsers(current => current.includes(id) ? current.filter(item => item !== id) : [...current, id]); }
  async function submit(event) { event.preventDefault(); setError(''); const form = new FormData(event.currentTarget); const result = await createProject({ name: form.get('name'), description: form.get('description'), members: selectedUsers.map(user_id => ({ user_id, role: 'member' })), visibility: form.get('visibility'), status: form.get('status') }); if (!result.ok) return setError(result.message); navigate('/projects'); }

  return <form className="project-create-form" onSubmit={submit}>
    <section className="create-section"><div className="section-heading"><span>01</span><div><h3>Основна інформація</h3><p>Назва та короткий опис нового проєкту.</p></div></div><label>Назва проєкту<input name="name" required placeholder="Наприклад, Редизайн сайту" /></label><label>Опис<textarea name="description" rows="5" placeholder="Коротко про цілі, результат і дедлайн" /></label></section>
    <section className="create-section"><div className="section-heading"><span>02</span><div><h3>Додати користувачів</h3><p>Учасників і групи можна налаштувати після створення проєкту.</p></div></div>{isAdmin && <input className="user-search" value={userSearch} onChange={event => setUserSearch(event.target.value)} placeholder="Пошук користувача або email…" />}<div className="user-picker">{visibleUsers.length ? visibleUsers.map(user => <label className={`picker-user ${selectedUsers.includes(user.id) ? 'selected' : ''}`} key={user.id}><input type="checkbox" checked={selectedUsers.includes(user.id)} onChange={() => toggleUser(user.id)} /><span className="member-avatar">{initials(user.name)}</span><span className="member-info"><strong>{user.name}</strong><small>{user.email}</small></span></label>) : <p className="form-help">{isAdmin ? 'Користувачів за вашим пошуком не знайдено.' : 'Менеджер ще нікого не запросив.'}</p>}</div></section>
    <section className="create-section"><div className="section-heading"><span>03</span><div><h3>Налаштування проєкту</h3><p>Базові параметри доступу та стану.</p></div></div><div className="settings-grid"><label>Статус<select name="status" defaultValue="active"><option value="active">Активний</option><option value="draft">Чернетка</option></select></label><label>Видимість<select name="visibility" defaultValue="team"><option value="private">Приватний</option><option value="team">Для команди</option></select></label></div></section>
    {error && <div className="form-notice">{error}</div>}<div className="form-actions"><button type="button" className="outline-btn" onClick={() => navigate('/projects')}>Скасувати</button><button className="primary-action">Створити проєкт</button></div>
  </form>;
}
