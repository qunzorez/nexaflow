import { useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import Layout from "../components/Layout.jsx";
import Icon from "../components/Icon.jsx";
import { api } from "../services/api.js";
import "./faq.css";

const sections = [
  {
    title: "Перші кроки",
    items: [
      { question: "З чого почати роботу?", answer: "Відкрийте «Клієнти» й додайте першого клієнта. Потім створіть проєкт, додайте задачі та призначте відповідальних. На сторінці «Огляд» ви побачите поточну роботу команди.", link: "/clients", linkText: "Перейти до клієнтів" },
      { question: "Як змінити робочий простір?", answer: "Якщо ви входите до кількох команд, виберіть потрібний простір у перемикачі «Мої простори» у верхній частині бічного меню. Дані й доступні дії залежать від обраного простору." },
      { question: "Де змінити свої дані та налаштування?", answer: "Відкрийте налаштування профілю, щоб оновити особисті дані та параметри сповіщень. Керування тарифом доступне менеджеру простору.", link: "/settings", linkText: "Відкрити налаштування" },
    ],
  },
  {
    title: "Клієнти й команда",
    items: [
      { question: "Як додати клієнта?", answer: "У розділі «Клієнти» натисніть кнопку створення. Заповніть назву та потрібні поля, після чого збережіть картку. Надалі її можна відкрити для редагування й доповнення.", link: "/clients", linkText: "Відкрити клієнтів" },
      { question: "Як запросити колегу?", answer: "Менеджер відкриває «Команда», вказує адресу електронної пошти та надсилає запрошення. Колега має прийняти його за посиланням із листа. Кількість учасників залежить від тарифу.", link: "/team", linkText: "Відкрити команду" },
      { question: "Чому колега не бачить клієнта або проєкт?", answer: "Перевірте активний робочий простір, участь колеги в команді та його доступ до конкретного клієнта чи проєкту. Менеджер може призначити учасників у відповідних розділах." },
    ],
  },
  {
    title: "Проєкти, задачі й календар",
    items: [
      { question: "Як створити проєкт?", answer: "У розділі «Проєкти» створіть проєкт, додайте опис і учасників. Проєкт об’єднує пов’язані задачі та допомагає стежити за перебігом роботи.", link: "/projects", linkText: "Відкрити проєкти" },
      { question: "Як поставити задачу користувачу?", answer: "Відкрийте «Задачі» та натисніть «Створити». Укажіть назву, відповідального, за потреби проєкт, пріоритет і дедлайн. Після збереження задача з’явиться у відповідальному списку.", link: "/tasks", linkText: "Відкрити задачі" },
      { question: "Як відстежувати виконання задач?", answer: "У списку задач відкрийте потрібну задачу, щоб переглянути її деталі та змінити статус. Для планування за датами використовуйте календар, якщо він доступний у вашому тарифі.", link: "/tasks", linkText: "Переглянути задачі" },
      { question: "Що показує календар?", answer: "Календар показує події з датами: задачі, бухгалтерські послуги та податкові дедлайни, доступні у вашому просторі. Перемикайтеся між місяцем, тижнем і днем. Доступ до календаря залежить від тарифу." },
    ],
  },
  {
    title: "Допомога",
    items: [
      { question: "Де знайти податкові налаштування?", answer: "Якщо у вашому просторі увімкнений бухгалтерський модуль, відкрийте «Податкові налаштування» в бічному меню. Цей розділ допомагає керувати податковими подіями та зобов’язаннями клієнтів." },
      { question: "Куди звернутися, якщо щось не працює?", answer: "Напишіть на support@nexaflow.top та вкажіть, що саме не вдалося зробити. Якщо у вашому тарифі доступна онлайн підтримка, скористайтеся відповідним пунктом меню.", link: "mailto:support@nexaflow.top", linkText: "Написати підтримці" },
    ],
  },
];

export default function FAQ() {
  const [user, setUser] = useState(null);
  const navigate = useNavigate();

  useEffect(() => {
    let active = true;
    api("/api/me.php").then(result => {
      if (!active) return;
      if (!result.ok) navigate("/login", { replace: true });
      else setUser(result.user);
    });
    return () => { active = false; };
  }, [navigate]);

  if (!user) return <div className="loading-screen">Завантаження довідки…</div>;

  return <Layout page="FAQ" user={user}>
    <div className="content faq-page">
      <header className="faq-hero">
        <div>
          <p className="eyebrow">ДОВІДКА NEXAFLOW</p>
          <h1>Як користуватися NexaFlow</h1>
          <p>Від першого клієнта до спільної роботи команди - відповіді на найчастіші запитання.</p>
        </div>
        <span className="faq-hero-icon"><Icon name="question" /></span>
      </header>
      <nav className="faq-shortcuts" aria-label="Швидкий перехід до розділів">
        {sections.map((section, index) => <a key={section.title} href={`#faq-section-${index}`}>{section.title}</a>)}
      </nav>
      <div className="faq-sections">
        {sections.map((section, index) => <section className="faq-section" id={`faq-section-${index}`} key={section.title}>
          <div className="faq-section-heading"><span>{String(index + 1).padStart(2, "0")}</span><h2>{section.title}</h2></div>
          <div className="faq-items">
            {section.items.map(item => <details className="faq-item" key={item.question}>
              <summary>{item.question}<Icon name="down" /></summary>
              <div className="faq-answer"><p>{item.answer}</p>{item.link && (item.link.startsWith("mailto:") ? <a href={item.link}>{item.linkText} <Icon name="forward" /></a> : <Link to={item.link}>{item.linkText} <Icon name="forward" /></Link>)}</div>
            </details>)}
          </div>
        </section>)}
      </div>
    </div>
  </Layout>;
}
