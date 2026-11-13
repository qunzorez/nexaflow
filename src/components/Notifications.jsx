import Icon from './Icon.jsx';
import { useEffect, useRef, useState } from 'react';
import { api, post } from '../services/api.js';
import DOMPurify from 'dompurify';
import './notifications.css';

export default function Notifications() {
  const [open, setOpen] = useState(false); const [selected, setSelected] = useState(null);
  const [items, setItems] = useState([]);
  const [unread, setUnread] = useState(0);
  const wrapRef = useRef(null);
  const bannerShown = useRef(false);

  async function load() {
    const result = await api('/api/notifications.php');
    if (result.ok) {
      const nextItems = result.notifications || [];
      setItems(nextItems); setUnread(Number(result.unread || 0));
      const banner = nextItems.find(item => Number(item.is_banner) && !item.banner_dismissed_at && Number(item.banner_view_count || 0) < 3);
      if (banner && !bannerShown.current) { bannerShown.current = true; setSelected(banner); await post('/api/notifications.php', { action: 'banner_viewed', id: banner.id }); }
    }
  }
  useEffect(() => { load(); const interval = window.setInterval(load, 15000); const refreshOnFocus = () => load(); window.addEventListener('focus', refreshOnFocus); return () => { window.clearInterval(interval); window.removeEventListener('focus', refreshOnFocus); }; }, []);
  useEffect(() => { const closeOnOutsideClick = event => { if (open && wrapRef.current && !wrapRef.current.contains(event.target)) setOpen(false); }; document.addEventListener('pointerdown', closeOnOutsideClick); return () => document.removeEventListener('pointerdown', closeOnOutsideClick); }, [open]);
  async function mark(item) { setOpen(false); setSelected(item); if (!Number(item.is_read)) { await post('/api/notifications.php', { action: 'read', id: item.id }); load(); } }
  async function markAll() { await post('/api/notifications.php', { action: 'read_all' }); load(); }
  async function dismissBanner() { if (!selected) return; await post('/api/notifications.php', { action: 'dismiss_banner', id: selected.id }); setItems(current => current.map(item => item.id === selected.id ? { ...item, banner_dismissed_at: new Date().toISOString() } : item)); setSelected(null); }
  const preview = value => String(value || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
  const notificationHtml = item => item.content_html || (/<[a-z][\s\S]*>/i.test(String(item.message || '')) ? item.message : `<p>${String(item.message || '').replace(/[&<>'"]/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[character]))}</p>`);
  return <div className="notification-wrap" ref={wrapRef}>
    <button className={`icon-btn notification-button${unread > 0 ? ' has-unread' : ''}`} aria-label="Сповіщення" onClick={() => { setOpen(!open); if (!open) load(); }}><Icon name="bell" />{unread > 0 && <span className="notification-count">{unread > 99 ? '99+' : unread}</span>}</button>
    {open && <div className="notifications-panel">
      <div className="notifications-head"><strong>Сповіщення</strong><button type="button" onClick={markAll}>Позначити все прочитаним</button></div>
      <div className="notification-list">{items.length ? items.map(item => <button key={item.id} className={`notification-item ${Number(item.is_read) ? '' : 'unread'}`} onClick={() => mark(item)} type="button"><span className="notification-icon">{item.type === 'broadcast' ? <Icon name="sparkle" /> : item.type === 'user_created' ? <Icon name="plus" /> : <Icon name="project" />}</span><span className="notification-copy"><strong>{item.title}</strong><small>{preview(item.message) || 'Відкрити повне повідомлення…'}</small><time>{new Date(String(item.created_at).replace(' ', 'T')).toLocaleString('uk-UA')}</time></span></button>) : <div className="notification-empty">Нових повідомлень поки немає.</div>}</div>
    </div>}
    {selected && <div className="notification-modal-backdrop" onMouseDown={event => event.target === event.currentTarget && setSelected(null)}><article className={`notification-modal${Number(selected.is_banner) ? ' notification-modal-banner' : ''}`}><button className="notification-modal-close" type="button" onClick={() => setSelected(null)} aria-label="Закрити"><Icon name="close" /></button><p className="eyebrow">{Number(selected.is_banner) ? 'СПЕЦІАЛЬНА ПРОПОЗИЦІЯ' : 'СПОВІЩЕННЯ'}</p><h2>{selected.title}</h2><time>{new Date(String(selected.created_at).replace(' ', 'T')).toLocaleString('uk-UA')}</time><div className="notification-content" dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(notificationHtml(selected), { USE_PROFILES: { html: true } }) }} />{Number(selected.is_banner) > 0 && <button className="banner-dismiss" type="button" onClick={dismissBanner}>Більше не показувати</button>}</article></div>}
  </div>;
}
