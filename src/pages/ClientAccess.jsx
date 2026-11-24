import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api, post } from '../services/api.js';
import './auth.css';
import './client-access.css';

export default function ClientAccess({ mode }) {
  const token = new URLSearchParams(window.location.search).get('token') || '';
  const navigate = useNavigate();
  const [invite, setInvite] = useState(null);
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);
  const [registered, setRegistered] = useState(false);
  const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '' });
  useEffect(() => { if (mode !== 'register' || !token) return; api(`/api/accounting-client-invitation.php?action=inspect&token=${encodeURIComponent(token)}`).then(result => { setInvite(result); if (result.ok && result.status === 'pending' && result.email) setForm(current => ({ ...current, email: result.email })); else setMessage(result.status === 'revoked' ? 'Запрошення відкликано. Попросіть бухгалтера надіслати нове.' : result.status === 'expired' ? 'Строк дії запрошення минув. Попросіть бухгалтера надіслати нове.' : result.message); }); }, [mode, token]);
  async function submit(event) {
    event.preventDefault(); setBusy(true); setMessage('');
    const result = mode === 'register'
      ? await post('/api/accounting-client-invitation.php', { action: 'register', token, ...form })
      : await post('/api/login.php', { email: form.email, password: form.password });
    setBusy(false);
    if (!result.ok) { setMessage(result.message); return; }
    if (mode === 'register') { setRegistered(true); setMessage(result.message); return; }
    if (!result.user?.email_verified_at) { navigate('/settings?verify_email=1'); return; }
    if (token) {
      const accepted = await post('/api/accounting-client-invitation.php', { action: 'accept', token });
      if (!accepted.ok) { if (result.user?.client_portal_only) { navigate('/accounting/portal', { replace: true }); return; } setMessage(accepted.message); return; }
      navigate(`/accounting/portal?client=${encodeURIComponent(accepted.client_id)}`, { replace: true }); return;
    }
    navigate(result.user?.client_portal_only ? '/accounting/portal' : '/dashboard', { replace: true });
  }
  const valid = invite?.ok && invite.status === 'pending';
  return <main className="client-access-page"><section className="client-access-card"><Link className="client-access-logo" to="/">NexaFlow</Link><p className="eyebrow">КАБІНЕТ КЛІЄНТА</p><h1>{mode === 'register' ? 'Приєднатися до компанії' : 'Вхід для клієнтів'}</h1><p>{mode === 'register' ? (valid ? `Вас запросили до «${invite.client_name}». Створіть акаунт із запрошеною адресою.` : 'Перевіряємо запрошення…') : 'Увійдіть до кабінету бухгалтерського клієнта.'}</p>
  {message && <div className="client-access-message" role="status">{message}</div>}
  {registered && <Link className="primary-action client-access-next" to={`/client/login?token=${encodeURIComponent(token)}`}>Перейти до входу →</Link>}
  {(mode === 'login' || valid && !registered) && <form onSubmit={submit}>{mode === 'register' && <label>Ваше ім’я<input required minLength="2" value={form.name} onChange={event => setForm({ ...form, name: event.target.value })}/></label>}<label>Email<input type="email" required readOnly={mode === 'register'} value={form.email} onChange={event => setForm({ ...form, email: event.target.value })}/></label><label>Пароль<input type="password" required minLength={mode === 'register' ? 8 : 1} value={form.password} onChange={event => setForm({ ...form, password: event.target.value })}/></label>{mode === 'register' && <label>Підтвердження пароля<input type="password" required minLength="8" value={form.password_confirmation} onChange={event => setForm({ ...form, password_confirmation: event.target.value })}/></label>}<button className="primary-action" disabled={busy}>{busy ? 'Зачекайте…' : mode === 'register' ? 'Створити акаунт' : 'Увійти'}</button></form>}
  <div className="client-access-links"><Link to={token ? `/client/login?token=${encodeURIComponent(token)}` : '/client/login'}>Уже маєте акаунт?</Link><Link to="/reset-password">Забули пароль?</Link><Link to="/login">Вхід для команди</Link></div></section></main>;
}
