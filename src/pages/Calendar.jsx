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
const HOURS = Array.from({ length: 13 }, (_, index) => index + 8);

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

function parseDateTime(value) {
  if (!value) return null;
  const date = new Date(String(value).replace(" ", "T"));
  return Number.isNaN(date.getTime()) ? null : date;
}

function dateKey(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

function dateTimeKey(date, hour = null) {
  const base = dateKey(date);
  return hour === null ? base : `${base}T${String(hour).padStart(2, "0")}:00`;
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
  if (view === "month") return formatDate(date, { month: "long", year: "numeric" }).replace(/^./, char => char.toUpperCase());
  const start = mondayOf(date);
  const end = addDays(start, 6);
  const sameMonth = start.getMonth() === end.getMonth();
  if (sameMonth) return `${start.getDate()}–${formatDate(end, { day: "numeric", month: "long", year: "numeric" })}`;
  return `${formatDate(start, { day: "numeric", month: "short" })} – ${formatDate(end, { day: "numeric", month: "short", year: "numeric" })}`;
}

function EventChip({ event, onOpen, compact = false }) {
  return (
    <button type="button" className={`calendar-event-chip ${event.kind}${compact ? " compact" : ""}`} onClick={(clickEvent) => { clickEvent.stopPropagation(); onOpen(event); }} title={`${event.title} · ${event.subtitle}`}>
      {!compact && <Icon name={event.kind === "task" ? "check" : "calendar"} />}
      {compact ? <strong>{event.time ? `${event.time.toLocaleTimeString("uk-UA", { hour: "2-digit", minute: "2-digit" })} · ` : ""}{event.title}</strong> : <span>{event.title}</span>}
    </button>
  );
}

function TimeGrid({ days, eventsByDay, onOpen, onCreate }) {
  return <div className={`calendar-time-grid ${days.length === 1 ? "single-day" : ""}`}>
    <div className="calendar-time-head"><span>Час</span>{days.map((date, index) => <button type="button" key={dateKey(date)} className={dateKey(date) === dateKey(new Date()) ? "today" : ""} onClick={() => onCreate(date)}>{days.length > 1 ? `${WEEKDAYS[index]} · ` : ""}{date.getDate()} {date.toLocaleDateString("uk-UA", { month: "short" })}</button>)}</div>
    <div className="calendar-all-day-row"><span>Весь день</span>{days.map(date => <div key={dateKey(date)} role="button" tabIndex="0" onClick={() => onCreate(date)} onKeyDown={event => { if (event.key === "Enter" || event.key === " ") onCreate(date); }}>{(eventsByDay[dateKey(date)] || []).filter(event => !event.time).map(event => <EventChip key={event.id} event={event} onOpen={onOpen} />)}</div>)}</div>
    <div className="calendar-time-body"><div className="calendar-time-labels">{HOURS.map(hour => <span key={hour}>{String(hour).padStart(2, "0")}:00</span>)}</div><div className="calendar-time-columns">{days.map(date => <div className="calendar-time-column" key={dateKey(date)}>{HOURS.map(hour => <button type="button" className="calendar-hour-slot" key={hour} onClick={() => onCreate(date, hour)} aria-label={`Створити задачу ${dateTimeKey(date, hour)}`} />)}{(eventsByDay[dateKey(date)] || []).filter(event => event.time).map(event => { const minutes = event.time.getHours() * 60 + event.time.getMinutes() - 8 * 60; return <button type="button" className={`calendar-timed-event ${event.kind}`} style={{ top: `${Math.max(0, minutes)}px` }} key={event.id} onClick={() => onOpen(event)}><strong>{event.time.toLocaleTimeString("uk-UA", { hour: "2-digit", minute: "2-digit" })} · {event.title}</strong><small>{event.subtitle}</small></button>; })}</div>)}</div></div>
  </div>;
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
      .map(task => {
        const date = parseDate(task.due_date);
        const dueTime = /[T ]\d{2}:\d{2}/.test(String(task.due_date || "")) ? parseDateTime(task.due_date) : null;
        const reminder = parseDateTime(task.reminder_at);
        const time = dueTime || (reminder && date && dateKey(reminder) === dateKey(date) ? reminder : null);
        return { id: `task-${task.id}`, kind: "task", title: task.title, subtitle: task.primary_project_name || "Без проєкту", date, time, task };
      });
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

            {view === "month" && <><div className="calendar-weekdays">{WEEKDAYS.map(day => <span key={day}>{day}</span>)}</div><div className="calendar-grid month-grid">{calendarDays.map(date => { const dayEvents = eventsByDay[dateKey(date)] || []; const inMonth = date.getMonth() === cursor.getMonth(); return <div className={`calendar-day ${inMonth ? "" : "outside"} ${dateKey(date) === dateKey(new Date()) ? "today" : ""}`} key={dateKey(date)} onClick={() => navigate(`/tasks?create=1&due_date=${dateKey(date)}`)} role="button" tabIndex="0" onKeyDown={event => { if (event.key === "Enter" || event.key === " ") navigate(`/tasks?create=1&due_date=${dateKey(date)}`); }}><span className="calendar-day-number"><span>{date.getDate()}</span><button type="button" className="calendar-day-add" onClick={event => { event.stopPropagation(); navigate(`/tasks?create=1&due_date=${dateKey(date)}`); }} aria-label={`Створити задачу на ${dateKey(date)}`}><Icon name="plus" /></button></span><div className="calendar-day-events">{dayEvents.map(event => <EventChip key={event.id} event={event} onOpen={openEvent} compact />)}</div></div>; })}</div></>}

            {view === "week" && <TimeGrid days={calendarDays} eventsByDay={eventsByDay} onOpen={openEvent} onCreate={(date, hour = null) => navigate(`/tasks?create=1&due_date=${encodeURIComponent(dateTimeKey(date, hour))}`)} />}

            {view === "day" && <TimeGrid days={calendarDays} eventsByDay={eventsByDay} onOpen={openEvent} onCreate={(date, hour = null) => navigate(`/tasks?create=1&due_date=${encodeURIComponent(dateTimeKey(date, hour))}`)} />}
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
