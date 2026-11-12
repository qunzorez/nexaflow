import { useEffect, useState } from 'react';
import { api, post } from '../services/api.js';
import DOMPurify from 'dompurify';
import './notifications.css';

export default function Notifications() {
  const [open, setOpen] = useState(false); const [selected, setSelected] = useState(null);
  const [items, setItems] = useState([]);
  const [unread, setUnread] = useState(0);

  async function load() {
    const result = await api('/api/notifications.php');
    if (result.ok) { setItems(result.notifications || []); setUnread(Number(result.unread || 0)); }
  }
  useEffect(() => { load(); }, []);
  async function mark(item) { setSelected(item); if (!Number(item.is_read)) { await post('/api/notifications.php', { action: 'read', id: item.id }); load(); } }
  async function markAll() { await post('/api/notifications.php', { action: 'read_all' }); load(); }
  const preview = value => String(value || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
  const notificationHtml = item => item.content_html || (/<[a-z][\s\S]*>/i.test(String(item.message || '')) ? item.message : `<p>${String(item.message || '').replace(/[&<>'"]/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[character]))}</p>`);
  return <div className="notification-wrap">
    <button className="icon-btn notification-button" aria-label="Сповіщення" onClick={() => { setOpen(!open); if (!open) load(); }}><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" /></svg>{unread > 0 && <span className="notification-count">{unread > 99 ? '99+' : unread}</span>}</button>
    {open && <div className="notifications-panel">
      <div className="notifications-head"><strong>Сповіщення</strong><button type="button" onClick={markAll}>Позначити все прочитаним</button></div>
      <div className="notification-list">{items.length ? items.map(item => <button key={item.id} className={`notification-item ${Number(item.is_read) ? '' : 'unread'}`} onClick={() => mark(item)} type="button"><span className="notification-icon">{item.type === 'broadcast' ? '✦' : item.type === 'user_created' ? '＋' : '▣'}</span><span className="notification-copy"><strong>{item.title}</strong><small>{preview(item.message) || 'Відкрити повне повідомлення…'}</small><time>{new Date(String(item.created_at).replace(' ', 'T')).toLocaleString('uk-UA')}</time></span></button>) : <div className="notification-empty">Нових повідомлень поки немає.</div>}</div>
    </div>}
    {selected && <div className="notification-modal-backdrop" onMouseDown={event => event.target === event.currentTarget && setSelected(null)}><article className="notification-modal"><button className="notification-modal-close" type="button" onClick={() => setSelected(null)} aria-label="Закрити">×</button><p className="eyebrow">СПОВІЩЕННЯ</p><h2>{selected.title}</h2><time>{new Date(String(selected.created_at).replace(' ', 'T')).toLocaleString('uk-UA')}</time><div className="notification-content" dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(notificationHtml(selected), { USE_PROFILES: { html: true } }) }} /></article></div>}
  </div>;
}
