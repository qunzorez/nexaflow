import { Link } from 'react-router-dom';
import { useEffect } from 'react';
import Icon from '../components/Icon.jsx';
import './landing.css';

const modules = [
  { icon: 'contact', title: 'Клієнти', text: 'Профіль клієнта, послуги, пакети та вся історія роботи в одному місці.' },
  { icon: 'project', title: 'Проєкти', text: 'Плануйте етапи, учасників і доступи так, щоб команда бачила спільну картину.' },
  { icon: 'todo', title: 'Задачі', text: 'Дедлайни, статуси, відповідальні та календар без зайвих таблиць і чатів.' },
  { icon: 'users', title: 'Команда', text: 'Claims, ролі й права доступу для безпечної спільної роботи.' },
  { icon: 'calendar', title: 'Календар', text: 'Місячний, тижневий і денний вигляд для всіх важливих дедлайнів.' },
  { icon: 'chart', title: 'Аналітика', text: 'Показуйте завантаженість і прогрес без ручного зведення даних.' },
];

const plans = [
  { name: 'Старт', icon: 'home', price: 'Безкоштовно', note: 'Для знайомства з NexaFlow', features: ['Менеджер і до 2 учасників', 'Доступ до проєктів, задач і клієнтів', '1 проєкт', 'До 50 задач на місяць', 'До 3 клієнтів', 'До 2 послуг на кожного клієнта'], unavailable: ['Календар', 'Аналітика для менеджера', 'Telegram-сповіщення', 'Пакети послуг'] },
  { name: 'Зростання', icon: 'chart', price: '$7.99', note: 'Для невеликої команди з амбіціями', features: ['1 менеджер і до 5 учасників', 'До 5 проєктів', 'До 100 задач на місяць', 'До 30 клієнтів', 'Послуги та пакети'], unavailable: ['Календар', 'Аналітика для менеджера'] },
  { name: 'Команда', icon: 'users', price: '$14.99', note: 'Для команд, які ростуть', featured: true, features: ['1 менеджер і до 10 учасників', '1 керівник проєкту', 'До 3 модераторів', 'Аналітика для менеджера', 'Календар', 'До 50 проєктів', 'До 300 задач на місяць', 'До 100 клієнтів', 'Створення власних послуг і пакетів'] },
  { name: 'Бізнес', icon: 'subscription', price: '$24.99', note: 'Для команд і компаній, що масштабуються', features: ['До 50 учасників загалом', 'До 5 керівників проєктів', 'До 5 модераторів', 'Аналітика та календар', 'До 100 проєктів', 'До 1 000 задач на місяць', 'До 500 клієнтів', 'Створення послуг і пакетів', 'Доступ до інших модулів NexaFlow'] },
];

export default function Landing() {
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
      <div className="landing-links"><a href="#modules" onClick={event => scrollToSection(event, 'modules')}>Можливості</a><a href="#workflow" onClick={event => scrollToSection(event, 'workflow')}>Як це працює</a><a href="#pricing" onClick={event => scrollToSection(event, 'pricing')}>Тарифи</a></div>
      <div className="landing-actions"><Link to="/login" className="landing-login">Увійти</Link><Link to="/register" className="landing-button landing-button-small">Спробувати безкоштовно <Icon name="forward" /></Link></div>
    </nav>
    <div className="section-navigation" aria-label="Навігація між секціями"><button type="button" aria-label="Попередня секція" onClick={() => scrollSections(-1)}>↑</button><div>{['Початок', 'Можливості', 'Як це працює', 'Тарифи', 'Створити простір'].map((label, index) => <button type="button" className={`section-nav-dot${index === 0 ? ' active' : ''}`} aria-label={label} key={label} onClick={() => document.querySelectorAll('.landing-hero, .landing-section, .landing-cta')[index]?.scrollIntoView({ behavior: 'smooth', block: 'start' })} />)}</div><button type="button" aria-label="Наступна секція" onClick={() => scrollSections(1)}>↓</button></div>

    <section className="landing-hero">
      <div className="hero-copy"><div className="landing-kicker"><span className="kicker-dot" /> SRM ДЛЯ СУЧАСНИХ КОМАНД</div><h1>SRM-система для клієнтів, <em>проєктів і команди.</em></h1><p>Об’єднайте керування клієнтами, проєктами, задачами та командою в одному робочому просторі NexaFlow.</p><div className="hero-actions"><Link to="/register" className="landing-button">Почати безкоштовно <Icon name="forward" /></Link><a href="#modules" className="landing-text-link">Дослідити можливості <span>↓</span></a></div><div className="hero-proof"><div className="proof-avatars"><span>А</span><span>М</span><span>Т</span><span>+</span></div><div><strong>Усе під контролем</strong><small>для вашої команди щодня</small></div></div></div>
      <div className="hero-visual"><div className="hero-glow" /><div className="dashboard-window"><div className="window-top"><div className="window-dots"><i /><i /><i /></div><span>nexaflow / dashboard</span><b>•••</b></div><div className="window-body"><aside><div className="mini-logo"><img src="/assets/logo-color.png" alt="" /></div><div className="mini-nav active">◈ <span>Огляд</span></div><div className="mini-nav">◫ <span>Клієнти</span></div><div className="mini-nav">▦ <span>Проєкти</span></div><div className="mini-nav">✓ <span>Задачі</span></div><div className="mini-nav">♧ <span>Команда</span></div></aside><div className="mock-content"><div className="mock-heading"><div><small>ВАШ РОБОЧИЙ ПРОСТІР</small><h3>Доброго дня, команда <span>✦</span></h3></div><div className="mock-action">+ Створити задачу</div></div><div className="mock-stats"><div><span>Проєкти</span><strong>12</strong><small>+18% цього місяця</small></div><div><span>Активні задачі</span><strong>48</strong><small>8 потребують уваги</small></div><div><span>Команда</span><strong>16</strong><small>онлайн зараз</small></div></div><div className="mock-panel"><div className="mock-panel-head"><strong>Найближчі дедлайни</strong><span>Переглянути все →</span></div><div className="mock-task"><b className="task-date">14<br /><small>ЛИСТ</small></b><div><strong>Підготовка квартального звіту</strong><small>Ауді Груп · Бухгалтерія</small></div><span className="task-status">На перевірці</span></div><div className="mock-task"><b className="task-date blue">16<br /><small>ЛИСТ</small></b><div><strong>Оновлення пакета послуг</strong><small>Тов Некса · Проєкт</small></div><span className="task-status done">У роботі</span></div></div></div></div></div><div className="floating-note note-one"><span>✓</span><div><strong>Задачу завершено</strong><small>Щойно</small></div></div><div className="floating-note note-two"><span>↗</span><div><strong>+18% продуктивності</strong><small>за цей місяць</small></div></div></div>
    </section>

    <section className="landing-section modules-section" id="modules"><div className="section-intro"><div><div className="landing-kicker">ОДИН ПРОСТІР · БІЛЬШЕ ФОКУСУ</div><h2>Усе, що потрібно<br /><em>вашій команді.</em></h2></div><p>Замість десятків розрізнених інструментів — одна зрозуміла система, яка підлаштовується під ваш процес.</p></div><div className="module-grid">{modules.map((module, index) => <article className="module-card" key={module.title} style={{ '--delay': `${Math.floor(index / 3) * 180}ms` }}><div className="module-icon"><Icon name={module.icon} /></div><span className="module-number">0{index + 1}</span><h3>{module.title}</h3><p>{module.text}</p><a href="#pricing">Дізнатися більше <span>↗</span></a></article>)}</div></section>

    <section className="landing-section workflow-section" id="workflow"><div className="workflow-visual"><div className="workflow-ring ring-one" /><div className="workflow-ring ring-two" /><div className="workflow-card"><div className="workflow-icon">✦</div><span>Ваш процес</span><strong>від заявки<br />до результату</strong><div className="workflow-line"><i /><i /><i /><i /></div><small>Клієнт · Проєкт · Команда · Звіт</small><div className="workflow-status status-client"><i>✓</i> Клієнта додано</div><div className="workflow-status status-project"><i>↗</i> Проєкт активний</div><div className="workflow-status status-done"><i>✓</i> Результат готовий</div></div></div><div className="workflow-copy"><div className="landing-kicker">ПРОСТО ПОЧАТИ · ЛЕГКО РОЗВИВАТИ</div><h2>Ваш процес.<br /><em>Одна система.</em></h2><p>NexaFlow допомагає перетворити щоденну рутину на зрозумілий процес: від першого клієнта до завершеного проєкту.</p><div className="workflow-step"><b>01</b><div><strong>Зберіть робочий простір</strong><span>Додайте команду, клієнтів і потрібні модулі.</span></div></div><div className="workflow-step"><b>02</b><div><strong>Працюйте в одному ритмі</strong><span>Розподіляйте задачі, відстежуйте дедлайни та прогрес.</span></div></div><div className="workflow-step"><b>03</b><div><strong>Розвивайтеся на даних</strong><span>Бачте завантаження команди й наступні кроки.</span></div></div></div></section>

    <section className="landing-section pricing-section" id="pricing"><div className="pricing-heading"><div className="landing-kicker">ПРОЗОРІ ТАРИФИ · БЕЗ СЮРПРИЗІВ</div><h2>Оберіть свій <em>наступний крок.</em></h2><p>Створіть акаунт менеджера, щоб оформити тариф для робочого простору.</p></div><div className="pricing-grid">{plans.map(plan => <article className={`price-card${plan.featured ? ' featured' : ''}`} key={plan.name}>{plan.featured && <div className="popular-badge">НАЙПОПУЛЯРНІШИЙ</div>}<div className="price-card-top"><span>{plan.name}</span><i><Icon name={plan.icon} /></i></div><p>{plan.note}</p><div className="price"><strong>{plan.price}</strong>{plan.price !== 'Безкоштовно' && <small>/ місяць</small>}</div><Link to={plan.price === 'Безкоштовно' ? '/register' : `/register?plan=${plan.name === 'Зростання' ? 'growth' : plan.name === 'Команда' ? 'team' : 'business'}`} className={plan.featured ? 'landing-button' : 'price-button'}>{plan.price === 'Безкоштовно' ? 'Почати безкоштовно' : 'Обрати план'} <Icon name="forward" /></Link><ul>{plan.features.map(feature => <li key={feature}><span>✓</span>{feature}</li>)}</ul>{plan.unavailable && <><div className="plan-limit-label">Недоступно у цьому плані</div><ul className="plan-unavailable">{plan.unavailable.map(feature => <li key={feature}><span>×</span>{feature}</li>)}</ul></>}</article>)}</div></section>

    <section className="landing-cta"><img className="landing-cta-logo" src="/assets/logo-white-text.png" alt="NexaFlow" /><div><div className="landing-kicker">ГОТОВІ РУХАТИСЯ ВПЕРЕД?</div><h2>Менше хаосу.<br /><em>Більше NexaFlow.</em></h2></div><Link to="/register" className="landing-button landing-button-light">Створити робочий простір <Icon name="forward" /></Link></section>
    <footer className="landing-footer"><Link to="/" className="landing-logo"><img src="/assets/logo-color-text.png" alt="NexaFlow" /></Link><span>SRM для команд, які створюють результат.</span><div><Link to="/login">Увійти</Link><Link to="/register">Реєстрація</Link></div></footer>
  </main>;
}
