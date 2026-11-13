import Icon from '../components/Icon.jsx';
import { useEffect, useState } from 'react';
import { useSearchParams, useNavigate } from 'react-router-dom';
import { api, post } from '../services/api.js';

export default function AcceptInvite() {
  const [params] = useSearchParams(); const [info, setInfo] = useState(null); const [form, setForm] = useState({ name: '', password: '' }); const [notice, setNotice] = useState('Перевіряємо запрошення…'); const navigate = useNavigate();
  useEffect(() => { api(`/api/accept-invite.php?token=${encodeURIComponent(params.get('token') || '')}`).then(result => { setInfo(result.ok ? result : null); setNotice(result.message); }); }, [params]);
  async function submit(event) { event.preventDefault(); const result = await post('/api/accept-invite.php', { token: params.get('token'), ...form }); if (!result.ok) return setNotice(result.message); navigate('/dashboard'); }
  const existing = info?.existing_user;
  return <main className="invite-page"><div className="invite-card"><div className="brand-mark"><span><Icon name="plus" /></span></div><p className="eyebrow">NEXAFLOW · ЗАПРОШЕННЯ</p><h1>Приєднайтесь до команди</h1><p>{notice}</p>{info && <form onSubmit={submit}>{existing ? <p className="readonly-note">Ця пошта вже зареєстрована. Натисніть кнопку, щоб приєднатися до проєкту після входу в систему.</p> : <><label>Ваше ім’я<input required value={form.name} onChange={event => setForm({ ...form, name: event.target.value })} /></label><label>Створіть пароль<input type="password" minLength="6" required value={form.password} onChange={event => setForm({ ...form, password: event.target.value })} /></label></>}<button className="primary-action">{existing ? 'Прийняти запрошення' : 'Створити акаунт і прийняти'} <Icon name="forward" /></button></form>}</div></main>;
}
