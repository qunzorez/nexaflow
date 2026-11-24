import { useEffect, useState } from 'react';
import { api, post } from '../services/api.js';

const labels = { pending: 'Очікує прийняття', accepted: 'Прийнято', revoked: 'Відкликано' };
export default function ClientInvitations({ clientId }) {
  const [items, setItems] = useState([]); const [email, setEmail] = useState(''); const [busy, setBusy] = useState(false); const [notice, setNotice] = useState('');
  async function load() { const result = await api(`/api/accounting-client-invitation.php?action=list&client_id=${encodeURIComponent(clientId)}`); if (result.ok) setItems(result.invitations || []); else setNotice(result.message); }
  useEffect(() => { load(); }, [clientId]);
  async function change(action, item) { setBusy(true); const result = await post('/api/accounting-client-invitation.php', { action, client_id: clientId, email: item?.email || email, id: item?.id }); setBusy(false); setNotice(result.message); if (result.ok) { if (!item) setEmail(''); await load(); } }
  return <div className="accounting-client-invites"><h3>Запрошення до кабінету</h3><p>Посилання дійсне 7 днів. Доступ з’явиться після прийняття запрошення та підтвердження email.</p><form onSubmit={event => { event.preventDefault(); change('invite'); }}><label>Email клієнта<input type="email" value={email} onChange={event => setEmail(event.target.value)} required placeholder="client@company.com" /></label><button className="secondary-action" disabled={busy}>Надіслати запрошення</button></form>{notice && <p role="status">{notice}</p>}<div className="accounting-client-invite-list">{items.map(item => { const expired = item.status === 'pending' && new Date(item.expires_at.replace(' ', 'T')).getTime() < Date.now(); return <div key={item.id}><strong>{item.email}</strong><span>{expired ? 'Строк минув' : labels[item.status] || item.status} · до {item.expires_at}</span>{item.status !== 'accepted' && <div><button type="button" disabled={busy} onClick={() => change('resend', item)}>Надіслати знову</button>{item.status === 'pending' && !expired && <button type="button" disabled={busy} onClick={() => change('revoke', item)}>Відкликати</button>}</div>}</div>; })}</div></div>;
}
