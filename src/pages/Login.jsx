import Icon from '../components/Icon.jsx';
import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { post } from '../services/api.js';
import './auth.css';

export default function Login({ initialMode = 'login' }) {
  const requestedPlan = new URLSearchParams(window.location.search).get('plan') || window.localStorage.getItem('nexaflow.pending-plan') || '';
  const supportedPlans = ['start', 'growth', 'team', 'business', 'dental_start', 'dental_growth', 'dental_team', 'dental_business'];
  if (supportedPlans.includes(requestedPlan)) window.localStorage.setItem('nexaflow.pending-plan', requestedPlan);
  const [mode, setMode] = useState(initialMode);
  const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '', requested_role: requestedPlan ? 'manager' : 'user', terms: false });
  const [notice, setNotice] = useState(() => new URLSearchParams(window.location.search).get('verified') === '1' ? 'Пошту підтверджено. Увійдіть, щоб відкрити свій робочий простір.' : '');
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
    const planToActivate = requestedPlan || window.localStorage.getItem('nexaflow.pending-plan') || '';
    window.localStorage.removeItem('nexaflow.pending-plan');
    if (supportedPlans.includes(planToActivate) && result.user?.role === 'manager') {
      navigate(`/settings?plan=${encodeURIComponent(planToActivate)}`, { replace: true });
      return;
    }
    navigate('/dashboard', { replace: true });
  }

  async function resend() {
    const result = await post('/api/resend-verification.php', { email: verificationEmail });
    setNotice(result.message);
  }

  return <main className="auth-page auth-modern">
    <header className="auth-site-header">
      <Link to="/" className="auth-site-logo" aria-label="NexaFlow — головна сторінка"><img src="/assets/logo-color-text.png" alt="NexaFlow" /></Link>
      <Link to="/" className="auth-home-link"><Icon name="back" /> На головну</Link>
    </header>
    <section className="auth-brand"><div className="auth-orb orb-one" /><div className="auth-orb orb-two" /><div className="brand-mark"><span><Icon name="plus" /></span></div><p className="eyebrow">NEXAFLOW · SRM SYSTEM</p><h1>Ваша команда.<br /><em>Один простір.</em></h1><p>Керуйте клієнтами, проєктами та командою в одному зрозумілому робочому просторі.</p><div className="auth-workflow-visual" aria-hidden="true"><div className="auth-workflow-heading"><span>РОБОЧИЙ ПРОСТІР</span><i><b /> LIVE</i></div><div className="auth-workflow-task"><span className="auth-workflow-icon"><Icon name="tasks" /></span><div className="auth-workflow-copy"><strong>Підготувати звіт</strong><small>Проєкт · Бухгалтерія</small></div><span className="auth-flow-status status-working"><i /> У роботі</span></div><div className="auth-client-toast"><span><Icon name="contact" /></span><div><strong>Новий клієнт</strong><small>Ауді Груп додано</small></div><b><Icon name="check" /></b></div></div></section>
    <section className={`auth-card auth-card-modern auth-mode-${mode}`}><div className="auth-card-top"><span className="eyebrow">БЕЗПЕЧНИЙ ДОСТУП</span><span className="auth-status-dot"><Icon name="todo" /> Онлайн</span></div><div className="tabs"><button type="button" className={mode === 'login' ? 'active' : ''} onClick={() => switchMode('login')}>Вхід</button><button type="button" className={mode === 'register' ? 'active' : ''} onClick={() => switchMode('register')}>Реєстрація</button></div><h2 key={`title-${mode}`}>{mode === 'login' ? 'З поверненням!' : 'Створіть акаунт'}</h2><p key={`description-${mode}`}>{mode === 'login' ? 'Увійдіть, щоб продовжити роботу.' : 'Почніть керувати клієнтами вже сьогодні.'}</p>
      <form onSubmit={submit} key={mode}>
        {mode === 'register' && <label>Ваше ім’я<input name="name" value={form.name} onChange={update} required placeholder="Введіть ваше ім’я" /></label>}
        <label>Робоча пошта<input name="email" type="email" value={form.email} onChange={update} required placeholder="name@company.com" /></label>
        <label>Пароль<input name="password" type="password" value={form.password} onChange={update} required minLength={mode === 'register' ? 8 : 6} placeholder={mode === 'register' ? 'Мінімум 8 символів' : 'Введіть пароль'} /></label>
        {mode === 'register' && <><label>Підтвердження пароля<input name="password_confirmation" type="password" value={form.password_confirmation} onChange={update} required minLength="8" placeholder="Повторіть пароль" /></label><label>Тип роботи<select name="requested_role" value={form.requested_role} onChange={update}><option value="user">Виконавець — працюю із задачами</option><option value="manager">Менеджер — створюю проєкти</option></select><small className="auth-field-hint">Вибрана роль буде призначена після підтвердження email.</small></label></>}
        {mode === 'login' && <button type="button" className="forgot-link" onClick={() => navigate(`/reset-password?email=${encodeURIComponent(form.email)}`)}>Забули пароль?</button>}
        {mode === 'register' && <label className="terms"><input name="terms" type="checkbox" checked={form.terms} onChange={update} /> Я погоджуюсь з умовами використання</label>}
        <button className="primary-action auth-submit">{mode === 'login' ? 'Увійти в систему' : 'Створити акаунт'} <span><Icon name="forward" /></span></button>
        {notice && <div className="form-notice">{notice}</div>}
        {verificationEmail && <button type="button" className="resend-button" onClick={resend}><Icon name="refresh" /> Відправити лист підтвердження ще раз</button>}
      </form><small className="auth-footer">Ваші дані захищені шифруванням</small></section>
  </main>;
}
