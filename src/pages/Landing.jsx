import { Link, useNavigate } from 'react-router-dom';
import { useEffect, useState } from 'react';
import Icon from '../components/Icon.jsx';
import { api } from '../services/api.js';
import './landing.css';

const modules = [
  { icon: 'contact', title: 'Клієнти', text: 'Профіль клієнта, послуги, пакети та вся історія роботи в одному місці.' },
  { icon: 'project', title: 'Проєкти', text: 'Плануйте етапи, учасників і доступи так, щоб команда бачила спільну картину.' },
  { icon: 'todo', title: 'Задачі', text: 'Дедлайни, статуси, відповідальні та календар без зайвих таблиць і чатів.' },
  { icon: 'users', title: 'Команда', text: 'Claims, ролі й права доступу для безпечної спільної роботи.' },
  { icon: 'calendar', title: 'Календар', text: 'Місячний, тижневий і денний вигляд для всіх важливих дедлайнів.' },
  { icon: 'chart', title: 'Аналітика', text: 'Показуйте завантаженість і прогрес без ручного зведення даних.' },
];

const featureLabels = {
  calendar: 'Календар', analytics: 'Аналітика', services: 'Послуги й пакети', telegram: 'Telegram-сповіщення', support: 'Онлайн-підтримка',
  dental_medical_records: 'Медичні картки', dental_service_catalog: 'Каталог послуг', dental_patient_invoices: 'Рахунки й оплати',
  dental_documents: 'Документи пацієнтів',
  dental_tooth_chart: 'Зубна формула', dental_treatment_plans: 'Плани лікування', dental_inventory: 'Складський облік',
  dental_basic_reports: 'Базові звіти', dental_procurement: 'Закупівлі', dental_procedure_costing: 'Собівартість процедур',
  dental_doctor_payroll: 'Зарплати лікарів', dental_financial_analytics: 'Фінансова аналітика', dental_equipment: 'Облік обладнання',
  dental_advanced_analytics: 'Розширена аналітика', dental_management_accounting: 'Управлінська бухгалтерія', dental_priority_support: 'Пріоритетна підтримка',
};
const planIcons = { start: 'home', growth: 'chart', team: 'users', business: 'subscription', dental_start: 'home', dental_growth: 'chart', dental_team: 'users', dental_business: 'subscription' };
const planNotes = { start: 'Для знайомства з NexaFlow', growth: 'Для невеликої команди з амбіціями', team: 'Для команд, які ростуть', business: 'Для команд і компаній, що масштабуються', dental_start: 'Базовий набір для приватного кабінету', dental_growth: 'Для клініки, що розвивається', dental_team: 'Для багатопрофільної клініки', dental_business: 'Для великої стоматологічної мережі' };
const accountingFeatures = ['calendar', 'analytics', 'services', 'telegram', 'support'];
const dentalFeatures = [...Object.keys(featureLabels).filter(key => key.startsWith('dental_')), 'calendar'];
const accountingCardCopy = {
  start: { icon: 'home', note: 'Для знайомства з NexaFlow', features: ['Менеджер і до 2 учасників', 'Доступ до проєктів, задач і клієнтів', '1 проєкт', 'До 50 задач на місяць', 'До 3 клієнтів', 'До 2 послуг на кожного клієнта'], unavailable: ['Бухгалтерський модуль', 'Календар', 'Аналітика для менеджера', 'Telegram-сповіщення', 'Пакети послуг', 'Онлайн-підтримка'] },
  growth: { icon: 'chart', note: 'Для невеликої команди з амбіціями', features: ['Бухгалтерський модуль', '1 менеджер і до 5 учасників', 'До 5 проєктів', 'До 100 задач на місяць', 'До 30 клієнтів', 'Послуги та пакети'], unavailable: ['Календар', 'Аналітика для менеджера', 'Онлайн-підтримка'] },
  team: { icon: 'users', note: 'Для команд, які ростуть', featured: true, features: ['Бухгалтерський модуль', '1 менеджер і до 10 учасників', '1 керівник проєкту', 'До 3 модераторів', 'Аналітика для менеджера', 'Календар', 'До 50 проєктів', 'До 300 задач на місяць', 'До 100 клієнтів', 'Створення власних послуг і пакетів', 'Онлайн-підтримка в чаті'] },
  business: { icon: 'subscription', note: 'Для команд і компаній, що масштабуються', features: ['Бухгалтерський модуль', 'До 50 учасників загалом', 'До 5 керівників проєктів', 'До 5 модераторів', 'Аналітика та календар', 'До 100 проєктів', 'До 1 000 задач на місяць', 'До 500 клієнтів', 'Створення послуг і пакетів', 'Доступ до інших модулів NexaFlow', 'Пріоритетна онлайн-підтримка в чаті'] },
};

const landingSections = [
  ['Початок', '.landing-hero'], ['Можливості', '#modules'], ['Як це працює', '#workflow'],
  ['Тарифи', '#pricing'], ['Безпека', '#security'], ['Контакти', '#contact'],
  ['Підтримка', '#support'], ['Створити простір', '.landing-cta'],
];

export default function Landing() {
  const [signedIn, setSignedIn] = useState(false);
  const [currentUser, setCurrentUser] = useState(null);
  const [plans, setPlans] = useState([]);
  const [billingEnabled, setBillingEnabled] = useState(false);
  const [plansLoading, setPlansLoading] = useState(true);
  const [selectedPlanModule, setSelectedPlanModule] = useState('accounting');
  const navigate = useNavigate();
  useEffect(() => {
    let active = true;
    Promise.all([api('/api/me.php'), api('/api/public-plans.php')]).then(([userResult, planResult]) => {
      if (!active) return;
      const user = userResult.ok ? userResult.user : null;
      setCurrentUser(user);
      setSignedIn(Boolean(user));
      if (user?.subscription?.module) setSelectedPlanModule(user.subscription.module);
      if (planResult.ok) {
        setPlans(planResult.plans || []);
        setBillingEnabled(Boolean(planResult.billing_enabled));
      }
    }).finally(() => { if (active) setPlansLoading(false); });
    return () => { active = false; };
  }, []);
  const canChoosePlan = !signedIn || ['admin', 'owner', 'manager'].includes(currentUser?.subscription?.workspace_role || currentUser?.workspace_role || currentUser?.role);
  function selectPlan(planKey) {
    navigate(signedIn ? `/settings?plan=${encodeURIComponent(planKey)}` : `/register?plan=${encodeURIComponent(planKey)}`);
  }
  const visiblePlans = plans.filter(plan => (plan.module || 'accounting') === selectedPlanModule);
  const scrollToSection = (event, id) => {
    event.preventDefault();
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };
  const scrollSections = direction => {
    const sections = [...document.querySelectorAll('.landing-hero, .landing-section, .landing-cta')];
    const current = sections.findIndex(section => {
      const rect = section.getBoundingClientRect();
      return rect.top <= window.innerHeight * 0.45 && rect.bottom > window.innerHeight * 0.45;
    });
    const next = sections[Math.max(0, Math.min(sections.length - 1, current + direction))];
    next?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };
  useEffect(() => {
    const sections = document.querySelectorAll('.landing-hero, .landing-section, .landing-cta');
    const observer = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        entry.target.classList.toggle('section-visible', entry.isIntersecting);
        if (entry.isIntersecting) {
          document.querySelectorAll('.section-nav-dot').forEach((dot, index) => dot.classList.toggle('active', sections[index] === entry.target));
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    sections.forEach(section => observer.observe(section));
    return () => observer.disconnect();
  }, []);

  return <main className="landing-page">
    <nav className="landing-nav">
      <Link to="/" className="landing-logo"><img src="/assets/logo-color-text.png" alt="NexaFlow" /></Link>
      <div className="landing-links"><a href="#modules" onClick={event => scrollToSection(event, 'modules')}>Можливості</a><a href="#workflow" onClick={event => scrollToSection(event, 'workflow')}>Як це працює</a><a href="#pricing" onClick={event => scrollToSection(event, 'pricing')}>Тарифи</a><a href="#security" onClick={event => scrollToSection(event, 'security')}>Безпека</a><a href="#contact" onClick={event => scrollToSection(event, 'contact')}>Контакти</a><a href="#support" onClick={event => scrollToSection(event, 'support')}>Підтримка</a></div>
      <div className="landing-actions"><Link to={signedIn ? "/dashboard" : "/login"} className="landing-login">{signedIn ? "Мій простір" : "Увійти"}</Link><Link to={signedIn ? "/dashboard" : "/register"} className="landing-button landing-button-small">{signedIn ? "До огляду" : "Спробувати безкоштовно"} <Icon name="forward" /></Link></div>
    </nav>
    <div className="section-navigation" aria-label="Навігація між секціями"><button type="button" aria-label="Попередня секція" onClick={() => scrollSections(-1)}>↑</button><div>{landingSections.map(([label, selector], index) => <button type="button" className={`section-nav-dot${index === 0 ? ' active' : ''}`} aria-label={label} title={label} key={label} onClick={() => document.querySelector(selector)?.scrollIntoView({ behavior: 'smooth', block: 'start' })} />)}</div><button type="button" aria-label="Наступна секція" onClick={() => scrollSections(1)}>↓</button></div>

    <section className="landing-hero">
      <div className="hero-copy"><div className="landing-kicker"><span className="kicker-dot" /> SRM ДЛЯ СУЧАСНИХ КОМАНД</div><h1>SRM-система для клієнтів, <em>проєктів і команди.</em></h1><p>Об’єднайте керування клієнтами, проєктами, задачами та командою в одному робочому просторі NexaFlow.</p><div className="hero-actions"><Link to={signedIn ? "/dashboard" : "/register"} className="landing-button">{signedIn ? "Відкрити робочий простір" : "Почати безкоштовно"} <Icon name="forward" /></Link><a href="#modules" className="landing-text-link">Дослідити можливості <span>↓</span></a></div><div className="hero-proof"><div className="proof-avatars"><span>А</span><span>М</span><span>Т</span><span>+</span></div><div><strong>Усе під контролем</strong><small>для вашої команди щодня</small></div></div></div>
      <div className="hero-visual"><div className="hero-glow" /><div className="dashboard-window"><div className="window-top"><div className="window-dots"><i /><i /><i /></div><span>nexaflow / dashboard</span><b>•••</b></div><div className="window-body"><aside><div className="mini-logo"><img src="/assets/logo-color.png" alt="" /></div><div className="mini-nav active">◈ <span>Огляд</span></div><div className="mini-nav">◫ <span>Клієнти</span></div><div className="mini-nav">▦ <span>Проєкти</span></div><div className="mini-nav">✓ <span>Задачі</span></div><div className="mini-nav">♧ <span>Команда</span></div></aside><div className="mock-content"><div className="mock-heading"><div><small>ВАШ РОБОЧИЙ ПРОСТІР</small><h3>Доброго дня, команда <span>✦</span></h3></div><div className="mock-action">+ Створити задачу</div></div><div className="mock-stats"><div><span>Проєкти</span><strong>12</strong><small>+18% цього місяця</small></div><div><span>Активні задачі</span><strong>48</strong><small>8 потребують уваги</small></div><div><span>Команда</span><strong>16</strong><small>онлайн зараз</small></div></div><div className="mock-panel"><div className="mock-panel-head"><strong>Найближчі дедлайни</strong><span>Переглянути все →</span></div><div className="mock-task"><b className="task-date">14<br /><small>ЛИСТ</small></b><div><strong>Підготовка квартального звіту</strong><small>Ауді Груп · Бухгалтерія</small></div><span className="task-status">На перевірці</span></div><div className="mock-task"><b className="task-date blue">16<br /><small>ЛИСТ</small></b><div><strong>Оновлення пакета послуг</strong><small>Тов Некса · Проєкт</small></div><span className="task-status done">У роботі</span></div></div></div></div></div><div className="floating-note note-one"><span>✓</span><div><strong>Задачу завершено</strong><small>Щойно</small></div></div><div className="floating-note note-two"><span>↗</span><div><strong>+18% продуктивності</strong><small>за цей місяць</small></div></div></div>
    </section>

    <section className="landing-section modules-section" id="modules"><div className="section-intro"><div><div className="landing-kicker">ОДИН ПРОСТІР · БІЛЬШЕ ФОКУСУ</div><h2>Усе, що потрібно<br /><em>вашій команді.</em></h2></div><p>Замість десятків розрізнених інструментів — одна зрозуміла система, яка підлаштовується під ваш процес.</p></div><div className="module-grid">{modules.map((module, index) => <article className="module-card" key={module.title} style={{ '--delay': `${Math.floor(index / 3) * 180}ms` }}><div className="module-icon"><Icon name={module.icon} /></div><span className="module-number">0{index + 1}</span><h3>{module.title}</h3><p>{module.text}</p><a href="#pricing">Дізнатися більше <span>↗</span></a></article>)}</div></section>

    <section className="landing-section workflow-section" id="workflow"><div className="workflow-visual"><div className="workflow-ring ring-one" /><div className="workflow-ring ring-two" /><div className="workflow-card"><div className="workflow-icon">✦</div><span>Ваш процес</span><strong>від заявки<br />до результату</strong><div className="workflow-line"><i /><i /><i /><i /></div><small>Клієнт · Проєкт · Команда · Звіт</small><div className="workflow-status status-client"><i>✓</i> Клієнта додано</div><div className="workflow-status status-project"><i>↗</i> Проєкт активний</div><div className="workflow-status status-done"><i>✓</i> Результат готовий</div></div></div><div className="workflow-copy"><div className="landing-kicker">ПРОСТО ПОЧАТИ · ЛЕГКО РОЗВИВАТИ</div><h2>Ваш процес.<br /><em>Одна система.</em></h2><p>NexaFlow допомагає перетворити щоденну рутину на зрозумілий процес: від першого клієнта до завершеного проєкту.</p><div className="workflow-step"><b>01</b><div><strong>Зберіть робочий простір</strong><span>Додайте команду, клієнтів і потрібні модулі.</span></div></div><div className="workflow-step"><b>02</b><div><strong>Працюйте в одному ритмі</strong><span>Розподіляйте задачі, відстежуйте дедлайни та прогрес.</span></div></div><div className="workflow-step"><b>03</b><div><strong>Розвивайтеся на даних</strong><span>Бачте завантаження команди й наступні кроки.</span></div></div></div></section>

    <section className="landing-section pricing-section" id="pricing"><div className="pricing-heading"><div className="landing-kicker">ТАРИФИ РОБОЧОГО ПРОСТОРУ</div><h2>Оберіть свій <em>наступний крок.</em></h2><p>{signedIn ? 'Перегляньте можливості планів і підключіть потрібний робочому простору.' : 'Оберіть напрям і тариф для нового робочого простору.'}</p><div className="landing-plan-switch" role="group" aria-label="Напрям тарифного плану"><button type="button" className={selectedPlanModule === 'accounting' ? 'active' : ''} onClick={() => setSelectedPlanModule('accounting')}>Бухгалтерія</button><button type="button" className={selectedPlanModule === 'dentistry' ? 'active' : ''} onClick={() => setSelectedPlanModule('dentistry')}>Стоматологія</button></div></div>{plansLoading ? <div className="landing-plans-state">Завантажуємо тарифи…</div> : !visiblePlans.length ? <div className="landing-plans-state">Не вдалося завантажити тарифи. Оновіть сторінку.</div> : <div className={`pricing-grid${selectedPlanModule === 'dentistry' ? ' dental-pricing-grid' : ''}`}>{visiblePlans.map((plan, index) => { const isDental = plan.module === 'dentistry'; const copy = accountingCardCopy[plan.key]; const metrics = [['doctors', 'лікарів'], ['patients', 'пацієнтів'], ['appointments_month', 'записів / міс.'], ...(plan.limits?.participants != null ? [['participants', 'виконавців']] : [])]; const activeFeatures = dentalFeatures.filter(feature => plan.features?.[feature]); const price = plan.price.replace(/\s*\/\s*місяць$/i, ''); if (!isDental) return <article className={`price-card${copy?.featured ? ' featured' : ''}`} key={plan.key} style={{ '--delay': `${index * 100}ms` }}><div className="price-card-top"><span>{plan.name}</span><i><Icon name={copy?.icon || planIcons[plan.key] || 'subscription'} /></i></div><p>{copy?.note}</p><div className="price"><strong>{price}</strong>{plan.amount > 0 && <small>/ місяць</small>}</div><button type="button" className={copy?.featured ? 'landing-button landing-plan-choose' : 'price-button landing-plan-choose'} disabled={!canChoosePlan} onClick={() => selectPlan(plan.key)}>{signedIn ? 'Переглянути план' : Number(plan.amount) === 0 ? 'Почати безкоштовно' : 'Обрати план'} <Icon name="forward" /></button><ul>{copy?.features.map(feature => <li key={feature}><span>✓</span>{feature}</li>)}</ul>{copy?.unavailable && <><div className="plan-limit-label">Недоступно у цьому плані</div><ul className="plan-unavailable">{copy.unavailable.map(feature => <li key={feature}><span>×</span>{feature}</li>)}</ul></>}</article>; const isCurrentPlan = currentUser?.subscription?.key === plan.key; return <article className={`price-card${index === 2 ? ' featured' : ''}${isCurrentPlan ? ' is-current' : ''}`} key={plan.key} style={{ '--delay': `${index * 100}ms` }}><div className="price-card-top"><span>{plan.name}</span><i><Icon name={planIcons[plan.key] || 'subscription'} /></i></div><p>{planNotes[plan.key] || 'План для стоматологічної практики'}</p><div className="price"><strong>{price}</strong>{plan.amount > 0 && <small>/ місяць</small>}</div><div className="landing-plan-metrics dental">{metrics.map(([key, label]) => <span key={key}><strong>{plan.limits?.[key] ?? '∞'}</strong><small>{label}</small></span>)}</div><button type="button" className={index === 2 ? 'landing-button landing-plan-choose' : 'price-button landing-plan-choose'} disabled={!canChoosePlan || isCurrentPlan} onClick={() => selectPlan(plan.key)}>{isCurrentPlan ? 'Ваш поточний план' : !canChoosePlan ? 'Обрати може менеджер' : plan.amount > 0 && billingEnabled ? 'Обрати план' : plan.amount > 0 ? 'Підключити план' : 'Почати безкоштовно'} <Icon name="forward" /></button><ul className={`dental-plan-feature-grid${activeFeatures.length > 5 ? ' has-many' : ''}`}>{activeFeatures.map(feature => <li key={feature}><span>✓</span>{featureLabels[feature] || feature}</li>)}</ul>{dentalFeatures.some(feature => !plan.features?.[feature]) && <><div className="plan-limit-label">Недоступно у цьому плані</div><ul className={`plan-unavailable dental-plan-feature-grid${dentalFeatures.filter(feature => !plan.features?.[feature]).length > 5 ? ' has-many' : ''}`}>{dentalFeatures.filter(feature => !plan.features?.[feature]).map(feature => <li key={feature}><span>×</span>{featureLabels[feature] || feature}</li>)}</ul></>}</article>; })}</div>}{!billingEnabled && <p className="landing-billing-note">Онлайн-оплата поки не підключена — платні можливості активуються без списання коштів.</p>}</section>

    <section className="landing-section security-section" id="security"><div className="section-intro"><div><div className="landing-kicker">БЕЗПЕКА · КОНТРОЛЬ ДОСТУПУ</div><h2>Дані команди<br /><em>під контролем.</em></h2></div><p>Доступ до робочого простору визначають ролі, права та налаштування вашої команди.</p></div><div className="security-grid"><article className="security-card"><span><Icon name="security" /></span><div><h3>Ролі й дозволи</h3><p>Менеджери керують простором, а учасники бачать лише доступні їм проєкти, задачі та клієнтів.</p></div><b>01</b></article><article className="security-card"><span><Icon name="users" /></span><div><h3>Окремі простори</h3><p>Дані різних команд розділені. Учасник працює в межах тих просторів, до яких його запросили.</p></div><b>02</b></article><article className="security-card"><span><Icon name="check" /></span><div><h3>Захищений обліковий запис</h3><p>Підтвердження email і парольний вхід допомагають контролювати доступ до облікового запису.</p></div><b>03</b></article></div><p className="security-note">Права доступу можуть залежати від ролі користувача, простору та вибраного тарифу.</p></section>

    <section className="landing-section contact-section" id="contact"><div className="contact-copy"><div className="landing-kicker">КОНТАКТИ</div><h2>На зв’язку,<br /><em>коли потрібно.</em></h2><p>Є запитання щодо NexaFlow, тарифів або роботи платформи? Напишіть команді підтримки — допоможемо знайти відповідь.</p></div><div className="contact-card"><span className="contact-icon"><Icon name="contact" /></span><div className="landing-kicker">КОМАНДА NEXAFLOW</div><h3>Зв’язатися з нами</h3><p>Для запитів щодо продукту, доступу до акаунта та співпраці.</p><a className="contact-email" href="mailto:support@nexaflow.top">support@nexaflow.top <Icon name="forward" /></a></div></section>

    <section className="landing-section support-section" id="support"><div className="section-intro"><div><div className="landing-kicker">ПІДТРИМКА</div><h2>Допомога на<br /><em>кожному кроці.</em></h2></div><p>Оберіть зручний спосіб продовжити — знайти відповідь або звернутися до команди NexaFlow.</p></div><div className="support-actions"><article><span><Icon name="question" /></span><div><h3>Питання про роботу сервісу?</h3><p>Напишіть на пошту підтримки й коротко опишіть, що сталося.</p></div><a href="mailto:support@nexaflow.top">Написати <Icon name="forward" /></a></article><article><span><Icon name="user" /></span><div><h3>Потрібна допомога з акаунтом?</h3><p>Увійдіть у NexaFlow, щоб керувати профілем і налаштуваннями робочого простору.</p></div><Link to={signedIn ? "/settings" : "/login"}>{signedIn ? "Налаштування" : "Увійти"} <Icon name="forward" /></Link></article></div></section>

    <section className="landing-cta"><img className="landing-cta-logo" src="/assets/logo-white-text.png" alt="NexaFlow" /><div><div className="landing-kicker">ГОТОВІ РУХАТИСЯ ВПЕРЕД?</div><h2>Менше хаосу.<br /><em>Більше NexaFlow.</em></h2></div><Link to={signedIn ? "/dashboard" : "/register"} className="landing-button landing-button-light">{signedIn ? "До робочого простору" : "Створити робочий простір"} <Icon name="forward" /></Link></section>
    <footer className="landing-footer"><Link to="/" className="landing-logo"><img src="/assets/logo-color-text.png" alt="NexaFlow" /></Link><span>SRM для команд, які створюють результат.</span><div><Link to={signedIn ? "/dashboard" : "/login"}>{signedIn ? "Мій простір" : "Увійти"}</Link>{!signedIn && <Link to="/register">Реєстрація</Link>}</div></footer>
  </main>;
}
