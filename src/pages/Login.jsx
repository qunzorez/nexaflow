import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { post } from '../services/api.js';
import './auth.css';

export default function Login() {
  const [mode, setMode] = useState('login');
  const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '', requested_role: 'user', terms: false });
  const [notice, setNotice] = useState('');
  const [verificationEmail, setVerificationEmail] = useState('');
  const navigate = useNavigate();

  const update = event => setForm({ ...form, [event.target.name]: event.target.type === 'checkbox' ? event.target.checked : event.target.value });
  function switchMode(next) { setMode(next); setNotice(''); setVerificationEmail(''); }

  async function submit(event) {
    event.preventDefault();
    setNotice('');
    if (mode === 'register' && !form.terms) return setNotice('Підтвердіть умови використання.');
    if (mode === 'register' && form.password !== form.password_confirmation) return setNotice('Паролі не співпадають.');
    const result = await post(`/api/${mode === 'login' ? 'login' : 'register'}.php`, form);
    if (!result.ok) {
      if (result.code === 'email_not_verified') {
        setVerificationEmail(result.email || form.email);
        setNotice('Спочатку підтвердіть email. Перевірте пошту або надішліть лист повторно.');
      } else setNotice(result.message);
      return;
    }
    if (result.requires_verification) {
      const email = result.email || form.email;
      setMode('login');
      setForm(current => ({ ...current, email, password: '', password_confirmation: '', terms: false }));
      setVerificationEmail(email);
      setNotice(`Акаунт створено. Лист підтвердження надіслано на ${email}. Підтвердьте email перед входом.`);
      return;
    }
    navigate('/dashboard');
  }

  async function resend() {
    const result = await post('/api/resend-verification.php', { email: verificationEmail });
    setNotice(result.message);
  }

  return <main className="auth-page auth-modern">
    <section className="auth-brand"><div className="auth-orb orb-one" /><div className="auth-orb orb-two" /><div className="brand-mark"><span>+</span></div><p className="eyebrow">NEXAFLOW · SRM SYSTEM</p><h1>Ваша команда.<br /><em>Один простір.</em></h1><p>Керуйте клієнтами, проєктами та командою в одному зрозумілому робочому просторі.</p><div className="auth-floating-card"><span>✓</span><div><strong>Все під контролем</strong><small>Команда працює разом</small></div></div></section>
    <section className="auth-card auth-card-modern"><div className="auth-card-top"><span className="eyebrow">БЕЗПЕЧНИЙ ДОСТУП</span><span className="auth-status-dot">● Онлайн</span></div><div className="tabs"><button type="button" className={mode === 'login' ? 'active' : ''} onClick={() => switchMode('login')}>Вхід</button><button type="button" className={mode === 'register' ? 'active' : ''} onClick={() => switchMode('register')}>Реєстрація</button></div><h2>{mode === 'login' ? 'З поверненням!' : 'Створіть акаунт'}</h2><p>{mode === 'login' ? 'Увійдіть, щоб продовжити роботу.' : 'Почніть керувати клієнтами вже сьогодні.'}</p>
      <form onSubmit={submit} key={mode}>
        {mode === 'register' && <label>Ваше ім’я<input name="name" value={form.name} onChange={update} required placeholder="Введіть ваше ім’я" /></label>}
        <label>Робоча пошта<input name="email" type="email" value={form.email} onChange={update} required placeholder="name@company.com" /></label>
        <label>Пароль<input name="password" type="password" value={form.password} onChange={update} required minLength={mode === 'register' ? 8 : 6} placeholder={mode === 'register' ? 'Мінімум 8 символів' : 'Введіть пароль'} /></label>
        {mode === 'register' && <><label>Підтвердження пароля<input name="password_confirmation" type="password" value={form.password_confirmation} onChange={update} required minLength="8" placeholder="Повторіть пароль" /></label><label>Тип роботи<select name="requested_role" value={form.requested_role} onChange={update}><option value="user">Виконавець — працюю із задачами</option><option value="manager">Менеджер — створюю проєкти</option></select><small className="auth-field-hint">Роль менеджера має підтвердити адміністратор.</small></label></>}
        {mode === 'login' && <button type="button" className="forgot-link" onClick={() => navigate(`/reset-password?email=${encodeURIComponent(form.email)}`)}>Забули пароль?</button>}
        {mode === 'register' && <label className="terms"><input name="terms" type="checkbox" checked={form.terms} onChange={update} /> Я погоджуюсь з умовами використання</label>}
        <button className="primary-action auth-submit">{mode === 'login' ? 'Увійти в систему' : 'Створити акаунт'} <span>→</span></button>
        {notice && <div className="form-notice">{notice}</div>}
        {verificationEmail && <button type="button" className="resend-button" onClick={resend}>↻ Відправити лист підтвердження ще раз</button>}
      </form><small className="auth-footer">Ваші дані захищені шифруванням</small></section>
  </main>;
}
