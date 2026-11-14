import { useCallback, useEffect, useRef, useState } from 'react';
import Layout from '../components/Layout.jsx';
import Icon from '../components/Icon.jsx';
import { api, post } from '../services/api.js';
import { apiUrl } from '../config.js';
import './support.css';
import './support-attachments.css';
import './support-sessions.css';

export default function Support() {
  const [user, setUser] = useState(null);
  const [sessions, setSessions] = useState([]);
  const [session, setSession] = useState(null);
  const [messages, setMessages] = useState([]);
  const [closedSessions, setClosedSessions] = useState([]);
  const [sessionId, setSessionId] = useState('');
  const [view, setView] = useState('open');
  const [showArchive, setShowArchive] = useState(false);
  const [text, setText] = useState('');
  const [selectedFile, setSelectedFile] = useState(null);
  const [error, setError] = useState('');
  const [sending, setSending] = useState(false);
  const [creating, setCreating] = useState(false);
  const bottomRef = useRef(null);
  const fileInputRef = useRef(null);
  const isAdmin = ['admin', 'owner'].includes(user?.role);
  const isSupportStaff = isAdmin || Number(user?.is_support_agent) === 1;

  useEffect(() => { api('/api/me.php').then(result => { if (result.ok) setUser(result.user); }); }, []);

  const loadChat = useCallback(async () => {
    const query = isSupportStaff
      ? `?status=${view === 'closed' ? 'closed' : 'open'}${sessionId ? `&session_id=${encodeURIComponent(sessionId)}` : ''}`
      : (sessionId ? `?session_id=${encodeURIComponent(sessionId)}` : '');
    const result = await api(`/api/support.php${query}`);
    if (!result.ok) { setError(result.message || 'Не вдалося завантажити чат.'); return; }
    setError('');
    if (result.staff) {
      setSessions(result.sessions || []);
      if (!sessionId && result.sessions?.length) setSessionId(result.sessions[0].id);
      if (sessionId && !result.session) { setSession(null); setMessages([]); }
    } else {
      setSession(result.session || null);
      setMessages(result.messages || []);
      setClosedSessions(result.closed_sessions || []);
      setSessionId(result.session?.id || '');
    }
    if (result.session) { setSession(result.session); setMessages(result.messages || []); }
  }, [isSupportStaff, sessionId, view]);

  useEffect(() => {
    if (!user) return undefined;
    loadChat();
    const interval = window.setInterval(loadChat, 8000);
    return () => window.clearInterval(interval);
  }, [user, loadChat]);

  useEffect(() => { bottomRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' }); }, [messages]);

  async function openSession() {
    if (creating) return;
    setCreating(true); setError('');
    const result = await post('/api/support.php', { action: 'create_session' });
    if (!result.ok) setError(result.message || 'Не вдалося відкрити звернення.');
    else { setShowArchive(false); setSessionId(result.session_id); setSession({ id: result.session_id, status: 'open' }); setMessages([]); }
    setCreating(false);
  }

  async function closeSession() {
    if (!sessionId || sending) return;
    const result = await post('/api/support.php', { action: 'close_session', session_id: sessionId, ...(isSupportStaff ? { workspace_id: session?.workspace_id } : {}) });
    if (!result.ok) return setError(result.message || 'Не вдалося закрити звернення.');
    if (isSupportStaff) {
      setSessions(current => current.filter(item => item.id !== sessionId));
      setSessionId(''); setSession(null); setMessages([]);
    } else {
      setSession(null); setSessionId(''); setMessages([]); setShowArchive(true);
      setClosedSessions(current => [{ id: sessionId, closed_at: new Date().toISOString() }, ...current]);
    }
  }

  async function send(event) {
    event.preventDefault();
    const message = text.trim();
    if ((!message && !selectedFile) || sending || !sessionId) return;
    if (selectedFile && selectedFile.size > 8 * 1024 * 1024) return setError('Максимальний розмір зображення — 8 МБ.');
    setSending(true); setError('');
    const payload = new FormData();
    payload.set('action', 'message'); payload.set('session_id', sessionId); payload.set('message', message);
    if (isSupportStaff && session?.workspace_id) payload.set('workspace_id', session.workspace_id);
    if (selectedFile) payload.set('file', selectedFile);
    const result = await post('/api/support.php', payload);
    if (result.ok) { setMessages(current => [...current, result.message_item]); setText(''); setSelectedFile(null); if (fileInputRef.current) fileInputRef.current.value = ''; }
    else setError(result.message || 'Не вдалося надіслати повідомлення.');
    setSending(false);
  }

  if (!user) return <div className="loading-screen">Завантаження чату…</div>;
  const activeSession = sessions.find(item => item.id === sessionId) || session;
  const assignedToOther = isSupportStaff && activeSession?.assigned_to && String(activeSession.assigned_to) !== String(user.id);
  const fileUrl = id => apiUrl(`/api/support-file.php?id=${encodeURIComponent(id)}`);
  const sessionRows = isSupportStaff ? sessions : closedSessions;
  return <Layout page="Онлайн підтримка" user={user}>
    <div className={`content support-page${isSupportStaff ? ' support-admin' : ''}`}>
      <section className="welcome-row"><div><p className="eyebrow">NEXAFLOW · ДОПОМОГА</p><h1>Онлайн підтримка</h1><p className="subtext">{isSupportStaff ? 'Звернення клієнтів та історія вирішених питань.' : 'Напишіть нам — команда підтримки допоможе розібратися.'}</p></div><span className="support-online"><i /> На зв’язку</span></section>
      <section className="panel support-chat-panel">
        {isSupportStaff && <aside className="support-inbox">
          <div className="support-inbox-heading"><strong>{view === 'closed' ? 'Архів звернень' : 'Відкриті звернення'}</strong><span>{sessions.length}</span></div>
          <div className="support-session-tabs"><button type="button" className={view === 'open' ? 'active' : ''} onClick={() => { setView('open'); setSessionId(''); setSession(null); setMessages([]); }}>Відкриті</button><button type="button" className={view === 'closed' ? 'active' : ''} onClick={() => { setView('closed'); setSessionId(''); setSession(null); setMessages([]); }}>Архів</button></div>
          {sessions.length ? sessions.map(item => <button type="button" key={item.id} className={`support-thread${sessionId === item.id ? ' active' : ''}`} onClick={() => setSessionId(item.id)}><span className="support-thread-icon"><Icon name="users" /></span><span className="support-thread-copy"><span className="support-thread-title"><strong>{item.workspace_name || 'Робочий простір'}</strong><i className={`support-plan-tag ${item.plan_key === 'business' ? 'business' : 'team'}`}>{item.plan_key === 'business' ? 'Бізнес · пріоритет' : 'Команда'}</i></span><small>{item.assigned_name ? `Відповідає: ${item.assigned_name} · ` : ''}{item.last_message || 'Нове звернення'}{view === 'closed' ? ' · Архів' : ''}</small><time>{new Date(String(item.last_message_at || item.created_at).replace(' ', 'T')).toLocaleString('uk-UA')}</time></span></button>) : <p className="support-inbox-empty">{view === 'closed' ? 'Архів поки порожній.' : 'Нових звернень поки немає.'}</p>}
        </aside>}
        <div className="support-conversation">
          <header className="support-conversation-head"><span className="support-avatar"><Icon name={isSupportStaff ? 'users' : 'security'} /></span><div><strong>{isSupportStaff ? activeSession?.workspace_name || 'Оберіть звернення' : session ? 'Команда підтримки NexaFlow' : 'Онлайн підтримка'}</strong><small>{isSupportStaff ? (activeSession?.assigned_name ? `Відповідає: ${activeSession.assigned_name}` : activeSession?.workspace_email || (activeSession?.status === 'closed' ? 'Архівне звернення' : 'Звернення робочого простору')) : session ? 'Звернення відкрите' : 'Кожне питання — окрема сесія'}</small></div>{sessionId && activeSession?.status !== 'closed' && !assignedToOther && <button type="button" className="support-close-session" onClick={closeSession} disabled={sending}><Icon name="check" /> Закрити звернення</button>}</header>
          <div className="support-messages" aria-live="polite">{messages.length ? messages.map(item => <article key={item.id} className={`support-message${String(item.sender_id) === String(user.id) ? ' own' : ''}`}><small>{item.sender_name} · {new Date(String(item.created_at).replace(' ', 'T')).toLocaleString('uk-UA')}</small>{item.message && <p>{item.message}</p>}{item.attachments?.map(file => <div className="support-attachment" key={file.id}><a href={fileUrl(file.id)} target="_blank" rel="noreferrer"><img src={fileUrl(file.id)} alt={file.original_name} loading="lazy" /></a></div>)}</article>) : <div className="support-empty"><span><Icon name="question" /></span><strong>{isSupportStaff ? (activeSession ? 'Поки немає повідомлень' : 'Оберіть звернення') : session ? 'Опишіть ваше питання' : 'Чим можемо допомогти?'}</strong><p>{isSupportStaff ? (view === 'closed' ? 'Переглядайте історію закритого звернення.' : 'Відповідайте клієнтам у відкритій сесії.') : session ? 'Додайте опис або зображення, щоб команда підтримки могла допомогти.' : 'Створіть звернення, щоб почати окрему розмову з підтримкою.'}</p></div>}<div ref={bottomRef} /></div>
          {error && <div className="support-error" role="alert">{error}</div>}
          {!isSupportStaff && !session && <div className="support-session-actions"><button type="button" className="primary-action" onClick={openSession} disabled={creating}>{creating ? 'Відкриваємо…' : <><Icon name="plus" /> Нове звернення</>}</button>{closedSessions.length > 0 && <button type="button" className="outline-btn" onClick={() => setShowArchive(value => !value)}>{showArchive ? 'Приховати архів' : `Архів звернень · ${closedSessions.length}`}</button>}{showArchive && <div className="support-customer-archive">{closedSessions.map(item => <button type="button" key={item.id} onClick={() => setSessionId(item.id)}><span><strong>Закрите звернення</strong><small>Закрито {item.closed_at ? new Date(String(item.closed_at).replace(' ', 'T')).toLocaleDateString('uk-UA') : '—'}</small></span><small>До {new Date(String(item.expires_at).replace(' ', 'T')).toLocaleDateString('uk-UA')}</small></button>)}</div>}</div>}
          {!isSupportStaff && session && session.status === 'open' && <div className="support-session-actions"><button type="button" className="outline-btn" onClick={closeSession} disabled={sending}><Icon name="check" /> Позначити вирішеним і закрити</button><small>Після закриття чат зберігається в архіві 60 днів.</small></div>}
          {!isSupportStaff && session?.status === 'closed' && <div className="support-session-actions"><button type="button" className="outline-btn" onClick={() => { setSessionId(''); setSession(null); setMessages([]); setShowArchive(true); }}>← Повернутися до звернень</button><small>Архів видаляється через 60 днів.</small></div>}
          {isSupportStaff && view === 'closed' && activeSession && <div className="support-session-actions"><small>Закрито {activeSession.closed_at ? new Date(String(activeSession.closed_at).replace(' ', 'T')).toLocaleDateString('uk-UA') : ''} · архів зберігається 60 днів</small></div>}
          {assignedToOther && activeSession?.status === 'open' && <div className="support-assigned-notice">Звернення вже опрацьовує <strong>{activeSession.assigned_name || 'інший фахівець підтримки'}</strong>. Ви можете переглядати листування.</div>}
          {sessionId && activeSession?.status !== 'closed' && !assignedToOther && <form className="support-compose" onSubmit={send}><input ref={fileInputRef} className="support-file-input" type="file" accept="image/jpeg,image/png,image/gif,image/webp,image/avif,image/bmp,image/heic,image/heif,image/tiff" onChange={event => { setSelectedFile(event.target.files?.[0] || null); setError(''); }} disabled={sending} /><button className="support-attach-button" type="button" onClick={() => fileInputRef.current?.click()} disabled={sending} aria-label="Прикріпити зображення" title="Лише зображення до 8 МБ"><Icon name="attachment" /></button><div className="support-compose-fields">{selectedFile && <span className="support-selected-file"><Icon name="contact" /> {selectedFile.name}<button type="button" onClick={() => { setSelectedFile(null); if (fileInputRef.current) fileInputRef.current.value = ''; }} aria-label="Прибрати зображення">×</button></span>}<textarea value={text} onChange={event => setText(event.target.value)} maxLength={4000} rows={2} placeholder="Напишіть повідомлення…" disabled={sending} /></div><button className="primary-action" type="submit" disabled={sending || (!text.trim() && !selectedFile)} aria-label="Надіслати повідомлення">{sending ? <Icon name="loading" /> : <Icon name="forward" />}</button></form>}
        </div>
      </section>
    </div>
  </Layout>;
}
