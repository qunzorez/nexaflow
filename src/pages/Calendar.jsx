import { useEffect, useMemo, useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import Layout from "../components/Layout.jsx";
import Icon from "../components/Icon.jsx";
import { api, post } from "../services/api.js";
import "./calendar.css";
import "./calendar-appointments.css";
import "./calendar-accounting.css";
import "./calendar-filters.css";

const WEEKDAYS = ["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Нд"];
const VIEWS = [
  { id: "month", label: "Місяць" },
  { id: "week", label: "Тиждень" },
  { id: "day", label: "День" },
];
const HOURS = Array.from({ length: 16 }, (_, index) => index + 8);

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
    <button type="button" className={`calendar-event-chip ${event.kind}${event.archived ? " archived" : ""}${compact ? " compact" : ""}`} onClick={(clickEvent) => { clickEvent.stopPropagation(); onOpen(event); }} title={`${event.title} · ${event.subtitle}${event.archived ? " · В архіві" : ""}`}>
      {!compact && <Icon name={event.kind === "task" ? "check" : "calendar"} />}
      {compact ? <strong>{event.time ? `${event.time.toLocaleTimeString("uk-UA", { hour: "2-digit", minute: "2-digit" })} · ` : ""}{event.title}{event.archived ? " · Архів" : ""}</strong> : <span>{event.title}{event.archived ? " · В архіві" : ""}</span>}
    </button>
  );
}

function TimeGrid({ days, eventsByDay, onOpen, onCreate }) {
  return <div className={`calendar-time-grid ${days.length === 1 ? "single-day" : ""}`}>
    <div className="calendar-time-head"><span>Час</span>{days.map((date, index) => <button type="button" key={dateKey(date)} className={dateKey(date) === dateKey(new Date()) ? "today" : ""} onClick={() => onCreate(date)}>{days.length > 1 ? `${WEEKDAYS[index]} · ` : ""}{date.getDate()} {date.toLocaleDateString("uk-UA", { month: "short" })}</button>)}</div>
    <div className="calendar-all-day-row"><span>Весь день</span>{days.map(date => <div key={dateKey(date)} role="button" tabIndex="0" onClick={() => onCreate(date)} onKeyDown={event => { if (event.key === "Enter" || event.key === " ") onCreate(date); }}>{(eventsByDay[dateKey(date)] || []).filter(event => !event.time).map(event => <EventChip key={event.id} event={event} onOpen={onOpen} />)}</div>)}</div>
    <div className="calendar-time-body"><div className="calendar-time-labels">{HOURS.map(hour => <span key={hour}>{String(hour).padStart(2, "0")}:00</span>)}</div><div className="calendar-time-columns">{days.map(date => <div className="calendar-time-column" key={dateKey(date)}>{HOURS.map(hour => <button type="button" className="calendar-hour-slot" key={hour} onClick={() => onCreate(date, hour)} aria-label={`Створити задачу ${dateTimeKey(date, hour)}`} />)}{(eventsByDay[dateKey(date)] || []).filter(event => event.time).map(event => { const minutes = event.time.getHours() * 60 + event.time.getMinutes() - 8 * 60; return <button type="button" className={`calendar-timed-event ${event.kind}${event.archived ? " archived" : ""}`} style={{ top: `${Math.max(0, minutes)}px`, height: `${Math.max(20, Number(event.task?.duration_minutes || 60))}px` }} key={event.id} onClick={() => onOpen(event)}><strong>{event.time.toLocaleTimeString("uk-UA", { hour: "2-digit", minute: "2-digit" })}{event.task?.duration_minutes ? `–${new Date(event.time.getTime() + Number(event.task.duration_minutes) * 60000).toLocaleTimeString("uk-UA", { hour: "2-digit", minute: "2-digit" })}` : ""} · {event.title}{event.archived ? " · Архів" : ""}</strong>{Number(event.task?.duration_minutes || 60) >= 45 && <small>{event.subtitle}{event.archived ? " · Запис завершено" : ""}</small>}</button>; })}</div>)}</div></div>
  </div>;
}

export default function Calendar() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const [data, setData] = useState(null);
  const [cursor, setCursor] = useState(() => {
    const raw = searchParams.get("date");
    const date = raw ? new Date(`${raw}T12:00:00`) : new Date();
    return Number.isNaN(date.getTime()) ? new Date() : date;
  });
  const [view, setView] = useState(() => VIEWS.some(option => option.id === searchParams.get("view")) ? searchParams.get("view") : "month");
  const [doctorFilter, setDoctorFilter] = useState("");
  const [taxFilter, setTaxFilter] = useState({ system: "", employees: "0", year: String(new Date().getFullYear()), quarter: String(Math.floor(new Date().getMonth() / 3) + 1) });
  const [selectedTaxTypes, setSelectedTaxTypes] = useState(null);

  useEffect(() => {
    let active = true;
    (async () => {
      let [clientsResult, tasksResult] = await Promise.all([api("/api/clients.php"), api("/api/tasks.php?scope=calendar")]);
      if (!clientsResult.ok) return navigate("/");
      let accountingResult = null;
      if (clientsResult.active_client_module === "accounting") {
        accountingResult = await api("/api/accounting.php");
        if (accountingResult.ok && accountingResult.can_manage) {
          const generated = await post("/api/accounting.php", { action: "generate" });
          if (generated.ok) {
            [tasksResult, accountingResult] = await Promise.all([api("/api/tasks.php?scope=calendar"), api("/api/accounting.php")]);
          }
        }
      }
      if (active) setData({ ...clientsResult, tasks: tasksResult.ok ? (tasksResult.tasks || []) : [], taxObligations: accountingResult?.ok ? (accountingResult.obligations || []) : [], taxRules: accountingResult?.ok ? (accountingResult.rules || []) : [] });
    })();
    return () => { active = false; };
  }, [navigate]);

  const events = useMemo(() => {
    if (!data) return [];
    const taxTaskIds = new Set((data.taxObligations || []).map(item => String(item.task_id || "")).filter(Boolean));
    const tasksById = new Map((data.tasks || []).map(task => [String(task.id), task]));
    const taxTypes = [...new Set([...(data.taxRules || []).map(rule => rule.tax_type), ...(data.taxObligations || []).map(item => item.tax_type)].filter(Boolean))];
    const selectedTypes = selectedTaxTypes === null ? taxTypes : selectedTaxTypes;
    const taxEvents = (data.taxObligations || []).filter(item => {
      if (item.status === "not_applicable" || !selectedTypes.includes(item.tax_type)) return false;
      const client = (data.clients || []).find(candidate => String(candidate.id) === String(item.client_id));
      const systemCode = taxSystemCode(client || item);
      if (taxFilter.system && systemCode !== taxFilter.system) return false;
      if (taxFilter.employees === "1" && Number(item.has_employees) !== 1) return false;
      if (taxFilter.employees === "0" && Number(item.has_employees) === 1) return false;
      const date = parseDate(item.official_due);
      if (!date || String(date.getFullYear()) !== taxFilter.year) return false;
      if (taxFilter.quarter !== "all" && String(Math.floor(date.getMonth() / 3) + 1) !== taxFilter.quarter) return false;
      return true;
    }).map(item => ({
      id: `tax-${item.id}`, kind: "tax", title: item.title,
      subtitle: `${item.client_name} · ${item.tax_type} · ${item.period_key} · ${item.accountant_name ? `відповідальний: ${item.accountant_name} · ` : ""}внутр. ${item.internal_due ? formatDate(parseDate(item.internal_due)) : "—"}`,
      date: parseDate(item.official_due), official: parseDate(item.official_due),
      task: tasksById.get(String(item.task_id || "")) || (item.task_id ? { id: item.task_id } : null),
      obligation: item,
    }));
    // Dentistry treatments become calendar events only through linked appointment tasks.
    const serviceEvents = (data.clients || []).filter(client => client.module !== "dentistry").flatMap(client => (client.services || [])
      .filter(service => service.name)
      .map((service, index) => {
        const official = parseDate(service.official_due_date) || nextDeadline(service.period);
        const internal = new Date(official);
        internal.setDate(internal.getDate() - Number(service.internal_days_before || 0));
        const accountant = (data.available_users || []).find(person => String(person.id) === String(service.accountant_id));
        return { id: `service-${client.id}-${index}`, kind: "service", title: service.name, subtitle: client.name, date: internal, official, client, accountant: accountant || (service.accountant_name ? { name: service.accountant_name } : null) };
      }));
    const taskEvents = (data.tasks || [])
      .filter(task => !taxTaskIds.has(String(task.id)) && task.due_date && (!['archived', 'cancelled'].includes(task.status) || (data.active_client_module === "dentistry" && Number(task.is_client_appointment) === 1 && task.status === "archived")))
      .map(task => {
        const date = parseDate(task.due_date);
        const dueTime = /[T ]\d{2}:\d{2}/.test(String(task.due_date || "")) ? parseDateTime(task.due_date) : null;
        const reminder = parseDateTime(task.reminder_at);
        const time = dueTime || (reminder && date && dateKey(reminder) === dateKey(date) ? reminder : null);
        const isAppointment = Number(task.is_client_appointment) === 1;
        return { id: `task-${task.id}`, kind: isAppointment ? "appointment" : "task", title: task.title, subtitle: task.client_names || task.client_name || task.primary_project_name || "Без проєкту", date, time, archived: isAppointment && task.status === "archived", task };
      });
    return [...serviceEvents, ...taxEvents, ...taskEvents]
      .filter(item => item.date && (!doctorFilter || !item.task || (item.task.assignee_ids || []).some(personId => String(personId) === String(doctorFilter))))
      .sort((a, b) => a.date - b.date);
  }, [data, doctorFilter, selectedTaxTypes, taxFilter]);

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

  const openEvent = event => navigate(event.obligation
    ? `/accounting?client=${encodeURIComponent(event.obligation.client_id)}&obligation=${encodeURIComponent(event.obligation.id)}`
    : event.task ? `/tasks/${event.task.id}` : `/clients/${event.client.id}/edit`);
  const createTaskUrl = (date, hour = null) => data.active_client_module === "dentistry"
    ? `/appointments?date=${dateKey(date)}${hour === null ? "" : `&time=${String(hour).padStart(2, "0")}:00`}`
    : `/tasks?create=1&due_date=${encodeURIComponent(dateTimeKey(date, hour))}&return_view=${view}&return_date=${dateKey(date)}`;
  if (!data) return <div className="loading-screen">Завантаження календаря…</div>;

  return (
    <Layout page="Календар" user={data.user}>
      <div className="content calendar-page">
        <section className="welcome-row calendar-hero">
          <div>
            <p className="eyebrow">РОБОЧІ ІНСТРУМЕНТИ</p>
            <h1>Календар</h1>
            <p className="subtext">{data.active_client_module === "dentistry" ? "Записи клієнтів, робочі задачі та найближчі прийоми." : "Задачі, бухгалтерські послуги та найближчі дедлайни в одному місці."}</p>
          </div>
        </section>

        {data.active_client_module === "accounting" && <TaxCalendarFilters data={data} filters={taxFilter} setFilters={setTaxFilter} selectedTypes={selectedTaxTypes} setSelectedTypes={setSelectedTaxTypes} />}

        {data.active_client_module === "dentistry" && <label className="calendar-doctor-filter">Лікар у календарі<select value={doctorFilter} onChange={event => setDoctorFilter(event.target.value)}><option value="">Уся команда</option>{(data.team_users || []).map(person => <option key={person.id} value={person.id}>{person.name}</option>)}</select></label>}

        <div className="calendar-layout">
          <section className="panel big-calendar-panel">
            <div className={`calendar-toolbar calendar-toolbar-${view}`}>
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
              {data.active_client_module === "dentistry" && <span className="calendar-legend-item"><i className="calendar-legend-dot appointment" />Записи</span>}
              <span className="calendar-legend-item"><i className="calendar-legend-dot service" />Бухгалтерські послуги</span>
              <span className="calendar-legend-item"><i className="calendar-legend-dot tax" />Податкова звітність</span>
            </div>

            {view === "month" && <><div className="calendar-weekdays">{WEEKDAYS.map(day => <span key={day}>{day}</span>)}</div><div className="calendar-grid month-grid">{calendarDays.map(date => { const dayEvents = eventsByDay[dateKey(date)] || []; const inMonth = date.getMonth() === cursor.getMonth(); return <div className={`calendar-day ${inMonth ? "" : "outside"} ${dateKey(date) === dateKey(new Date()) ? "today" : ""}`} key={dateKey(date)} onClick={() => navigate(createTaskUrl(date))} role="button" tabIndex="0" onKeyDown={event => { if (event.key === "Enter" || event.key === " ") navigate(createTaskUrl(date)); }}><span className="calendar-day-number"><span>{date.getDate()}</span><button type="button" className="calendar-day-add" onClick={event => { event.stopPropagation(); navigate(createTaskUrl(date)); }} aria-label={`Створити задачу на ${dateKey(date)}`}><Icon name="plus" /></button></span><div className="calendar-day-events">{dayEvents.map(event => <EventChip key={event.id} event={event} onOpen={openEvent} compact />)}</div></div>; })}</div></>}

            {view === "week" && <TimeGrid days={calendarDays} eventsByDay={eventsByDay} onOpen={openEvent} onCreate={(date, hour = null) => navigate(createTaskUrl(date, hour))} />}

            {view === "day" && <TimeGrid days={calendarDays} eventsByDay={eventsByDay} onOpen={openEvent} onCreate={(date, hour = null) => navigate(createTaskUrl(date, hour))} />}
          </section>

          <section className="panel upcoming-deadlines-panel">
            <div className="panel-heading"><div><h2>Найближчі дедлайни</h2><p>Задачі та послуги, які потребують уваги.</p></div><span className="task-count-badge">{upcoming.length}</span></div>
            <div className="upcoming-deadlines">{upcoming.length ? upcoming.map(event => <button type="button" className="upcoming-deadline" key={event.id} onClick={() => openEvent(event)}>
              <span className={`upcoming-icon ${event.kind}`}><Icon name={event.kind === "task" ? "check" : "calendar"} /></span>
              <span className="upcoming-copy"><strong>{event.title}</strong><small>{event.subtitle}{event.detail ? ` · ${event.detail}` : ""}</small></span><time>{formatDate(event.date)}</time>
            </button>) : <div className="calendar-empty"><Icon name="calendar" /><p>Найближчих дедлайнів немає.</p></div>}</div>
          </section>
        </div>
      </div>
    </Layout>
  );
}

function TaxCalendarFilters({ data, filters, setFilters, selectedTypes, setSelectedTypes }) {
  const eventGroups = {
    "1": ["Єдиний податок", "Єдиний соціальний внесок", "Звіт по доходу", "Розрахунок 4ДФ", "Зміна групи", "Військовий збір"],
    "2": ["Єдиний податок", "Єдиний соціальний внесок", "Звіт по доходу", "ЄСВ за працівника", "Звітність акциз", "Зміна групи", "МПЗ", "Об'єднаний звіт 4ДФ", "ПДФО + ВЗ", "Сплата акцизу", "Військовий збір"],
    "3": ["Єдиний податок", "Єдиний соціальний внесок", "Звіт по доходу", "ЄСВ за працівника", "Звітність акциз", "Зміна групи", "МПЗ", "Об'єднаний звіт 4ДФ", "ПДФО + ВЗ", "Сплата акцизу", "Військовий збір"],
    "4": ["Єдиний податок", "Єдиний соціальний внесок", "Звіт по доходу", "ЄСВ за працівника", "Звітність акциз", "Зміна групи", "МПЗ", "Об'єднаний звіт 4ДФ", "ПДФО + ВЗ", "Реєстрація ПН / РК з 1 по 15 число", "Реєстрація ПН / РК з 16 по 31 число", "Сплата акцизу", "Сплата ПДВ", "Декларація ПДВ", "Військовий збір"],
    "5": ["Єдиний соціальний внесок", "Звіт по доходу", "ЄСВ за працівника", "Звітність акциз", "Зміна групи", "МПЗ", "Об'єднаний звіт 4ДФ", "ПДФО + ВЗ", "Сплата акцизу", "Сплата ПДФО", "Військовий збір"],
    "6": ["Єдиний соціальний внесок", "Звіт по доходу", "ЄСВ за працівника", "Звітність акциз", "Зміна групи", "МПЗ", "Об'єднаний звіт 4ДФ", "ПДФО + ВЗ", "Реєстрація ПН / РК з 1 по 15 число", "Реєстрація ПН / РК з 16 по 31 число", "Сплата акцизу", "Сплата ПДВ", "Декларація ПДВ", "Сплата ПДФО", "Військовий збір"],
  };
  const matched = filters.system ? (eventGroups[filters.system] || []) : Object.values(eventGroups).flat();
  const types = [...new Set([...matched, ...(data.taxRules || []).map(rule => rule.tax_type), ...(data.taxObligations || []).map(item => item.tax_type)].filter(Boolean))];
  const selected = selectedTypes === null ? types : selectedTypes;
  const systems = [["1","1 група"],["2","2 група"],["3","3 група 5%"],["4","3 група 3% + ПДВ"],["5","Загальна система"],["6","Загальна система (+ПДВ)"]];
  const years = ["2025","2026"];
  const toggle = type => setSelectedTypes(selected.includes(type) ? selected.filter(item=>item!==type) : [...selected,type]);
  return <div className="tax-calendar-filters" aria-label="Фільтри податкового календаря">
    <label>Система оподаткування<select value={filters.system} onChange={event=>{setFilters({...filters,system:event.target.value});setSelectedTypes(null)}}><option value="">Усі системи</option>{systems.map(([code,label])=><option key={code} value={code}>{label}</option>)}</select></label>
    <details className="tax-event-filter"><summary><span>{selected.length ? `Вибрані події ${selected.length}` : "Події не вибрані"}</span><span className="tax-filter-chevron">⌄</span></summary><div className="tax-event-options">{types.length?types.map(type=><label key={type}><input type="checkbox" checked={selected.includes(type)} onChange={()=>toggle(type)}/>{type}</label>):<span>Немає налаштованих видів подій</span>}</div></details>
    <label>Працівники<select value={filters.employees} onChange={event=>setFilters({...filters,employees:event.target.value})}><option value="0">Без найманих працівників</option><option value="1">З найманими працівниками</option></select></label>
    <label>Рік<select value={filters.year} onChange={event=>setFilters({...filters,year:event.target.value})}>{years.map(year=><option key={year}>{year}</option>)}</select></label>
    <label>Період<select value={filters.quarter} onChange={event=>setFilters({...filters,quarter:event.target.value})}><option value="1">1 квартал</option><option value="2">2 квартал</option><option value="3">3 квартал</option><option value="4">4 квартал</option></select></label>
  </div>;
}

function taxSystemCode(client) {
  if (!client) return "";
  const system = String(client.tax_system || "").toLowerCase();
  const legalForm = String(client.legal_form || "").toLowerCase();
  const vat = Number(client.has_vat) === 1 || client.has_vat === true;
  if (system.includes("1 група") || system.includes("1 group")) return "1";
  if (system.includes("2 група") || system.includes("2 group")) return "2";
  if (system.includes("3 група") || system.includes("3 group") || system.includes("єдиний податок, 3")) return vat ? "4" : "3";
  if (system.includes("загальна")) return vat ? "6" : "5";
  if (legalForm === "фоп" && system.includes("спрощ")) return vat ? "4" : "3";
  return "";
}
