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
      .then(result => setState({ loading: false, ok: result.ok, message: result.message }))
      .catch(() => setState({ loading: false, ok: false, message: 'Не вдалося перевірити посилання. Спробуйте відкрити його ще раз.' }));
  }, [params]);

  return <main className="auth-page auth-simple-page">
    <section className="auth-card auth-card-modern auth-result-card">
      <div className={`auth-result-icon ${state.ok ? 'success' : 'error'}`}>
        {state.loading ? '…' : state.ok ? <Icon name="check" /> : <Icon name="urgent" />}
      </div>
      <p className="eyebrow">NEXAFLOW · EMAIL</p>
      <h2>{state.ok ? 'Пошту підтверджено' : state.loading ? 'Перевірка пошти' : 'Не вдалося підтвердити пошту'}</h2>
      <p>{state.loading ? 'Зачекайте, підтверджуємо вашу адресу.' : state.ok ? 'Ваш акаунт активовано. Тепер увійдіть, щоб перейти до робочого простору.' : state.message}</p>
      {!state.loading && <Link className="primary-action auth-submit" to={state.ok ? '/login?verified=1' : '/login'}>
        {state.ok ? 'Увійти в NexaFlow' : state.loading ? 'До сторінки входу' : 'Повернутися до входу'}
        <span><Icon name="forward" /></span>
      </Link>}
      {!state.ok && !state.loading && <small className="auth-result-hint">Якщо посилання застаріло, увійдіть або надішліть лист підтвердження повторно.</small>}
    </section>
  </main>;
}
