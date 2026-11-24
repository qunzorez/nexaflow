import Icon from '../components/Icon.jsx';
import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { api } from '../services/api.js';
import './auth.css';

export default function VerifyEmail() {
  const [params] = useSearchParams();
  const [state, setState] = useState({ loading: true, ok: false, message: 'Перевіряємо посилання…' });

  useEffect(() => {
    api(`/api/verify-email.php?token=${encodeURIComponent(params.get('token') || '')}`)
      .then(async result => {
        const session = result.ok ? await api('/api/me.php?optional=1') : null;
        const invite = params.get('client_invite');
        const destination = invite ? `/client/login?token=${encodeURIComponent(invite)}` : session?.user?.client_portal_only ? '/accounting/portal' : session?.authenticated ? '/dashboard' : '/login?verified=1';
        setState({ loading: false, ok: result.ok, message: result.message, destination });
      })
      .catch(() => setState({ loading: false, ok: false, message: 'Не вдалося перевірити посилання. Спробуйте відкрити його ще раз.' }));
  }, [params]);

  return <main className="auth-page auth-simple-page">
    <section className="auth-card auth-card-modern auth-result-card">
      <div className={`auth-result-icon ${state.ok ? 'success' : 'error'}`}>
        {state.loading ? '…' : state.ok ? <Icon name="check" /> : <Icon name="urgent" />}
      </div>
      <p className="eyebrow">NEXAFLOW · EMAIL</p>
      <h2>{state.ok ? 'Пошту підтверджено' : state.loading ? 'Перевірка пошти' : 'Не вдалося підтвердити пошту'}</h2>
      <p>{state.loading ? 'Зачекайте, підтверджуємо вашу адресу.' : state.ok ? (state.destination === '/dashboard' ? 'Пошту підтверджено. Повний доступ уже відкрито.' : 'Пошту підтверджено. Увійдіть у свій акаунт.') : state.message}</p>
      {!state.loading && <Link className="primary-action auth-submit" to={state.ok ? state.destination : '/login'}>
        {state.ok ? (state.destination === '/dashboard' ? 'Перейти до робочого простору' : 'Увійти в NexaFlow') : 'Повернутися до входу'}
        <span><Icon name="forward" /></span>
      </Link>}
      {!state.ok && !state.loading && <small className="auth-result-hint">Якщо посилання застаріло, увійдіть або надішліть лист підтвердження повторно.</small>}
    </section>
  </main>;
}
