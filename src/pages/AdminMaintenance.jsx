import { useState } from 'react';
import { post } from '../services/api.js';
import './admin-maintenance.css';

export default function AdminMaintenance({ value, onSaved }) {
  const [form, setForm] = useState({ enabled: Boolean(Number(value?.requested_enabled)), notice_minutes: Number(value?.notice_minutes) || 5, title: value?.title || 'Технічні роботи', message: value?.message || '', estimated_end: value?.estimated_end ? String(value.estimated_end).replace(' ', 'T').slice(0, 16) : '', note: value?.note || '' });
  const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState('');
  const [error, setError] = useState('');
  const change = (key, next) => setForm(current => ({ ...current, [key]: next }));
  async function save(event) {
    event.preventDefault();
    if (form.enabled && !Boolean(Number(value?.requested_enabled)) && !window.confirm(`Запланувати технічні роботи через ${form.notice_minutes} хв? Користувачі отримають попередження, щоб зберегти введені дані. Після початку доступ залишиться лише адміністраторам.`)) return;
    setBusy(true); setFeedback(''); setError('');
    try {
      const result = await post('/api/admin.php', { action: 'update_maintenance', ...form, reason: 'Оновлення технічного режиму з адмінпанелі' });
      if (!result.ok) { setError(result.message || 'Не вдалося зберегти налаштування.'); return; }
      onSaved(result.maintenance);
      setFeedback('Налаштування збережено. Новий стан уже діє.');
    } catch { setError('Не вдалося підключитися до сервера.'); }
    finally { setBusy(false); }
  }
  const pending = Number(value?.requested_enabled) === 1 && !Number(value?.enabled);
  return <section className="admin-panel maintenance-settings"><div className="maintenance-settings-head"><div><h2>Технічні роботи</h2><p className="admin-muted">Користувачі побачать попередження й матимуть час зберегти роботу перед початком.</p></div><span className={`maintenance-state ${Number(value?.enabled) ? 'active' : ''}`}>{Number(value?.enabled) ? 'Увімкнено' : pending ? 'Заплановано' : 'Вимкнено'}</span></div>{pending && <p className="maintenance-pending">Початок: {value?.starts_at ? new Date(String(value.starts_at).replace(' ', 'T')).toLocaleString('uk-UA') : 'після попередження'}. Змініть тривалість і збережіть, щоб перенести початок. Щоб скасувати, вимкніть перемикач і збережіть.</p>}<p className="admin-muted">Остання зміна: {value?.updated_at ? new Date(String(value.updated_at).replace(' ', 'T')).toLocaleString('uk-UA') : 'ще не змінювали'}</p><form onSubmit={save}><label className="maintenance-switch"><input type="checkbox" checked={form.enabled} onChange={event => change('enabled', event.target.checked)} /><span>Увімкнути технічний режим</span></label><label className="maintenance-duration">Час до початку робіт <span className="maintenance-duration-control"><input type="number" min="1" max="1440" step="1" required value={form.notice_minutes} onChange={event => change('notice_minutes', event.target.value)} /><span>хвилин</span></span><small>Від 1 хвилини до 24 годин. Відлік починається після збереження налаштувань.</small></label><label>Заголовок<input required maxLength="160" value={form.title} onChange={event => change('title', event.target.value)} /></label><label>Повідомлення<textarea required maxLength="2000" rows="4" value={form.message} onChange={event => change('message', event.target.value)} /></label><div className="maintenance-fields"><label>Орієнтовне завершення<input type="datetime-local" value={form.estimated_end} onChange={event => change('estimated_end', event.target.value)} /></label><label>Коротка примітка<input maxLength="255" value={form.note} onChange={event => change('note', event.target.value)} /></label></div><button className="admin-button primary" disabled={busy}>{busy ? 'Зберігаємо…' : 'Зберегти технічний режим'}</button>{error && <p className="admin-alert error" role="alert">{error}</p>}{feedback && <p className="admin-alert success" role="status">{feedback}</p>}</form></section>;
}
