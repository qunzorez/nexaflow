import { Link } from 'react-router-dom';
import Icon from '../components/Icon.jsx';
import { post } from '../services/api.js';
import './auth.css';

export default function BannedPage() {
  let blockReason = '';
  try { blockReason = window.sessionStorage.getItem('nexaflow.block_reason') || ''; } catch {}
  async function signOut() {
    await post('/api/logout.php', {});
    window.location.assign('/login');
  }
  return <main className="banned-page auth-modern"><div className="banned-scene" aria-hidden="true"><span className="banned-orb orb-a" /><span className="banned-orb orb-b" /><span className="banned-orb orb-c" /><span className="banned-grid" /><span className="banned-ring ring-a" /><span className="banned-ring ring-b" /></div><section className="banned-card auth-card-modern"><Link className="banned-brand" to="/" aria-label="NexaFlow — головна"><span className="brand-mark"><span><Icon name="plus" /></span></span><strong>NEXAFLOW</strong></Link><div className="banned-card-content"><div className="banned-card-top"><span className="eyebrow">БЕЗПЕКА ОБЛІКОВОГО ЗАПИСУ</span><span className="banned-status"><i /> Доступ обмежено</span></div><div className="banned-illustration" aria-hidden="true"><div className="banned-illustration-glow" /><div className="banned-shield"><Icon name="settings" /></div><span className="banned-spark spark-one">✦</span><span className="banned-spark spark-two">✧</span><span className="banned-spark spark-three">·</span></div><p className="banned-kicker">ОБЛІКОВИЙ ЗАПИС</p><h1>Доступ тимчасово<br /><em>обмежено</em></h1><p className="banned-description">Ваш акаунт наразі заблокований.{blockReason ? <> Причина: <strong className="banned-reason">{blockReason}</strong>.</> : null} Якщо ви вважаєте, що це сталося помилково, напишіть нам:</p><a className="banned-contact" href="mailto:support@nexaflow.top">support@nexaflow.top <Icon name="forward" /></a><div className="banned-divider"><span /><i>NF</i><span /></div><button className="banned-signout" type="button" onClick={signOut}>Вийти з акаунта <Icon name="logout" /></button><Link className="banned-home" to="/">Повернутися на головну <Icon name="forward" /></Link><small className="banned-footnote">NexaFlow · Ваш робочий простір під захистом</small></div></section><div className="banned-floating-note note-one"><span><Icon name="settings" /></span><div><strong>Захист акаунта</strong><small>Доступ призупинено</small></div></div><div className="banned-floating-note note-two"><i /><span>Безпека даних</span></div></main>;
}
