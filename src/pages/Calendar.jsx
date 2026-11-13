import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import Layout from "../components/Layout.jsx";
import Icon from "../components/Icon.jsx";
import { api } from "../services/api.js";
import "./calendar.css";

const WEEKDAYS = ["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Нд"];
const VIEWS = [
  { id: "month", label: "Місяць" },
  { id: "week", label: "Тиждень" },
  { id: "day", label: "День" },
];

function nextDeadline(period, now = new Date()) {
  const date = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  if (period === "Щогодини") date.setHours(date.getHours() + 1, 0, 0, 0);
  else if (period === "Щодня") date.setDate(date.getDate() + 1);
  else if (period === "Щотижня") date.setDate(date.getDate() + (7 - date.getDay() || 7));
  else if (period === "Квартал") date.setMonth(Math.floor(date.getMonth() / 3) * 3 + 3, 1);
  else if (period === "Рік") date.setFullYear(date.getFullYear() + 1, 1, 20);
  else date.setMonth(date.getMonth() + 1, 20);
  return date;
}

function parseDate(value) {
  if (!value) return null;
  const date = new Date(`${String(value).slice(0, 10)}T12:00:00`);
  return Number.isNaN(date.getTime()) ? null : date;
}

function dateKey(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

function formatDate(date, options = { day: "numeric", month: "long", year: "numeric" }) {
  return date.toLocaleDateString("uk-UA", options);
}

function mondayOf(date) {
  const monday = new Date(date.getFullYear(), date.getMonth(), date.getDate());
  monday.setDate(monday.getDate() - ((monday.getDay() + 6) % 7));
  return monday;
}

function addDays(date, count) {
  const next = new Date(date);
  next.setDate(next.getDate() + count);
  return next;
}

function viewTitle(date, view) {
  if (view === "day") return formatDate(date, { weekday: "long", day: "numeric", month: "long", year: "numeric" });
  if (view === "week") {
    const start = mondayOf(date);
    const end = addDays(start, 6);
    const sameMonth = start.getMonth() === end.getMonth();
    if (sameMonth) return `${start.getDate()}–${formatDate(end, { day: "numeric", month: "long", year: "numeric" })}`;
    return `${formatDate(start, { day: "numeric", month: "short" })} – ${formatDate(end, { day: "numeric", month: "short", year: "numeric" })}`;
  }
  return formatDate(date, { month: "long", year: "numeric" }).replace(/^./, char => char.toUpperCase());
}

function EventChip({ event, onOpen, compact = false }) {
  return (
    <button type="button" className={`calendar-event-chip ${event.kind}${compact ? " compact" : ""}`} onClick={() => onOpen(event)} title={`${event.title} · ${event.subtitle}`}>
      <Icon name={event.kind === "task" ? "check" : "calendar"} />
      <span>{event.title}</span>
    </button>
  );
}

export default function Calendar() {
  const navigate = useNavigate();
  const [data, setData] = useState(null);
  const [cursor, setCursor] = useState(() => new Date());
  const [view, setView] = useState("month");

  useEffect(() => {
    Promise.all([api("/api/clients.php"), api("/api/tasks.php")]).then(([clientsResult, tasksResult]) => {
      if (!clientsResult.ok) return navigate("/");
      setData({ ...clientsResult, tasks: tasksResult.ok ? (tasksResult.tasks || []) : [] });
    });
  }, [navigate]);

  const events = useMemo(() => {
    if (!data) return [];
    const serviceEvents = (data.clients || []).flatMap(client => (client.services || [])
      .filter(service => service.name)
      .map((service, index) => {
        const official = parseDate(service.official_due_date) || nextDeadline(service.period);
        const internal = new Date(official);
        internal.setDate(internal.getDate() - Number(service.internal_days_before || 0));
        const accountant = (data.available_users || []).find(person => String(person.id) === String(service.accountant_id));
        return { id: `service-${client.id}-${index}`, kind: "service", title: service.name, subtitle: client.name, date: internal, official, client, accountant: accountant || (service.accountant_name ? { name: service.accountant_name } : null) };
      }));
    const taskEvents = (data.tasks || [])
      .filter(task => task.due_date && !["archived", "cancelled"].includes(task.status))
      .map(task => ({ id: `task-${task.id}`, kind: "task", title: task.title, subtitle: task.primary_project_name || "Без проєкту", date: parseDate(task.due_date), task }));
    return [...serviceEvents, ...taskEvents].filter(item => item.date).sort((a, b) => a.date - b.date);
  }, [data]);

  const eventsByDay = useMemo(() => events.reduce((result, event) => {
    (result[dateKey(event.date)] ||= []).push(event);
    return result;
  }, {}), [events]);

  const calendarDays = useMemo(() => {
    if (view === "day") return [new Date(cursor.getFullYear(), cursor.getMonth(), cursor.getDate())];
    if (view === "week") {
      const start = mondayOf(cursor);
      return Array.from({ length: 7 }, (_, index) => addDays(start, index));
    }
    const first = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
    const start = new Date(first);
    start.setDate(1 - ((first.getDay() + 6) % 7));
    return Array.from({ length: 42 }, (_, index) => addDays(start, index));
  }, [cursor, view]);

  const upcoming = useMemo(() => events
    .filter(event => event.date >= new Date(new Date().setHours(0, 0, 0, 0)))
    .slice(0, 8), [events]);

  const move = amount => setCursor(current => {
    const next = new Date(current);
    if (view === "month") next.setMonth(next.getMonth() + amount);
    else next.setDate(next.getDate() + amount * (view === "week" ? 7 : 1));
    return next;
  });

  const openEvent = event => navigate(event.kind === "task" ? `/tasks/${event.task.id}` : `/clients/${event.client.id}/edit`);
  if (!data) return <div className="loading-screen">Завантаження календаря…</div>;

  return (
    <Layout page="Календар" user={data.user}>
      <div className="content calendar-page">
        <section className="welcome-row calendar-hero">
          <div>
            <p className="eyebrow">РОБОЧІ ІНСТРУМЕНТИ</p>
            <h1>Календар</h1>
            <p className="subtext">Задачі, бухгалтерські послуги та найближчі дедлайни в одному місці.</p>
          </div>
        </section>

        <div className="calendar-layout">
          <section className="panel big-calendar-panel">
            <div className="calendar-toolbar">
              <button className="calendar-nav-button" type="button" aria-label="Попередній період" onClick={() => move(-1)}><Icon name="chevron-left" /></button>
              <div className="calendar-period-title">
                <h2>{viewTitle(cursor, view)}</h2>
                <span>{events.length} подій у календарі</span>
              </div>
              <button className="calendar-nav-button" type="button" aria-label="Наступний період" onClick={() => move(1)}><Icon name="chevron-right" /></button>
              <button className="calendar-today-button" type="button" onClick={() => setCursor(new Date())}>Сьогодні</button>
              <div className="calendar-view-switch" role="group" aria-label="Режим календаря">
                {VIEWS.map(option => <button key={option.id} type="button" className={view === option.id ? "active" : ""} onClick={() => setView(option.id)}>{option.label}</button>)}
              </div>
            </div>

            <div className="calendar-legend">
              <span className="calendar-legend-item"><i className="calendar-legend-dot task" />Задачі</span>
              <span className="calendar-legend-item"><i className="calendar-legend-dot service" />Бухгалтерські послуги</span>
            </div>

            {view === "month" && <>
              <div className="calendar-weekdays">{WEEKDAYS.map(day => <span key={day}>{day}</span>)}</div>
              <div className="calendar-grid month-grid">
                {calendarDays.map(date => {
                  const dayEvents = eventsByDay[dateKey(date)] || [];
                  const inMonth = date.getMonth() === cursor.getMonth();
                  return <div className={`calendar-day ${inMonth ? "" : "outside"} ${dateKey(date) === dateKey(new Date()) ? "today" : ""}`} key={dateKey(date)}>
                    <span className="calendar-day-number">{date.getDate()}</span>
                    <div className="calendar-day-events">
                      {dayEvents.slice(0, 3).map(event => <EventChip key={event.id} event={event} onOpen={openEvent} compact />)}
                      {dayEvents.length > 3 && <small>+{dayEvents.length - 3} ще</small>}
                    </div>
                  </div>;
                })}
              </div>
            </>}

            {view === "week" && <div className="calendar-week-scroll"><div className="calendar-week-grid">
              {calendarDays.map((date, index) => {
                const dayEvents = eventsByDay[dateKey(date)] || [];
                return <section className={`calendar-week-column ${dateKey(date) === dateKey(new Date()) ? "today" : ""}`} key={dateKey(date)}>
                  <header><span>{WEEKDAYS[index]}</span><strong>{date.getDate()}</strong></header>
                  <div className="calendar-week-events">{dayEvents.length ? dayEvents.map(event => <EventChip key={event.id} event={event} onOpen={openEvent} />) : <span className="calendar-no-events">Немає подій</span>}</div>
                </section>;
              })}
            </div></div>}

            {view === "day" && <div className="calendar-day-agenda">
              <div className="calendar-agenda-date"><span>{formatDate(cursor, { weekday: "short" }).replace(/\.$/, "")}</span><strong>{cursor.getDate()}</strong><small>{formatDate(cursor, { month: "long", year: "numeric" })}</small></div>
              <div className="calendar-agenda-events">
                {(eventsByDay[dateKey(cursor)] || []).length ? (eventsByDay[dateKey(cursor)] || []).map(event => <button type="button" className={`calendar-agenda-event ${event.kind}`} key={event.id} onClick={() => openEvent(event)}>
                  <span className="calendar-agenda-icon"><Icon name={event.kind === "task" ? "check" : "calendar"} /></span>
                  <span className="calendar-agenda-copy"><strong>{event.title}</strong><small>{event.subtitle}</small></span>
                  <span className="calendar-agenda-kind">{event.kind === "task" ? "Задача" : "Послуга"}</span>
                  <Icon name="chevron-right" />
                </button>) : <div className="calendar-empty"><Icon name="calendar" /><p>На цей день подій немає.</p></div>}
              </div>
            </div>}
          </section>

          <section className="panel upcoming-deadlines-panel">
            <div className="panel-heading"><div><h2>Найближчі дедлайни</h2><p>Задачі та послуги, які потребують уваги.</p></div><span className="task-count-badge">{upcoming.length}</span></div>
            <div className="upcoming-deadlines">{upcoming.length ? upcoming.map(event => <button type="button" className="upcoming-deadline" key={event.id} onClick={() => openEvent(event)}>
              <span className={`upcoming-icon ${event.kind}`}><Icon name={event.kind === "task" ? "check" : "calendar"} /></span>
              <span className="upcoming-copy"><strong>{event.title}</strong><small>{event.subtitle}</small></span><time>{formatDate(event.date)}</time>
            </button>) : <div className="calendar-empty"><Icon name="calendar" /><p>Найближчих дедлайнів немає.</p></div>}</div>
          </section>
        </div>
      </div>
    </Layout>
  );
}
