import { useCallback, useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import Icon from "../components/Icon.jsx";
import Layout from "../components/Layout.jsx";
import { api } from "../services/api.js";
import "./dentistry-executor-dashboard.css";

const pad = value => String(value).padStart(2, "0");
const dayKey = date => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
const timeLabel = value => String(value || "").match(/[ T](\d{2}:\d{2})/)?.[1] || "Час не вказано";
const appointmentStatuses = { scheduled: "Заплановано", confirmed: "Підтверджено", arrived: "Пацієнт прибув", completed: "Завершено", archived: "Архів", cancelled: "Скасовано" };
const statusTone = status => ({ confirmed: "confirmed", arrived: "arrived", completed: "completed", cancelled: "cancelled", archived: "archived" }[status] || "scheduled");
const activeAppointment = task => !["done", "cancelled", "archived"].includes(task.status) && !["completed", "cancelled", "archived"].includes(task.appointment_status);

export default function DentistryExecutorDashboard({ dashboard, calendarAvailable }) {
  const [appointments, setAppointments] = useState([]);
  const [error, setError] = useState("");
  const [search, setSearch] = useState("");
  const userId = String(dashboard.user.id);
  const refresh = useCallback(async () => {
    const result = await api("/api/tasks.php?scope=calendar");
    if (!result.ok) { setError(result.message || "Не вдалося завантажити ваші прийоми."); return; }
    setError("");
    setAppointments((result.tasks || []).filter(task => {
      const assignees = [...(Array.isArray(task.assignee_ids) ? task.assignee_ids.map(String) : String(task.assignee_ids || "").split(",").filter(Boolean)), String(task.assignee_id || "")];
      return Number(task.is_client_appointment) === 1 && assignees.includes(userId) && activeAppointment(task);
    }).sort((a, b) => String(a.due_date || "").localeCompare(String(b.due_date || ""))));
  }, [userId]);
  useEffect(() => {
    refresh();
    const interval = window.setInterval(refresh, 30000);
    window.addEventListener("focus", refresh);
    return () => { window.clearInterval(interval); window.removeEventListener("focus", refresh); };
  }, [refresh]);

  const today = dayKey(new Date());
  const nextWeek = dayKey(new Date(Date.now() + 7 * 86400000));
  const matchesSearch = task => `${task.client_names || task.client_name || ""} ${task.title || ""} ${task.appointment_reason || ""}`.toLocaleLowerCase("uk").includes(search.trim().toLocaleLowerCase("uk"));
  const todaysAppointments = useMemo(() => appointments.filter(task => String(task.due_date || "").slice(0, 10) === today && matchesSearch(task)), [appointments, today, search]);
  const upcomingAppointments = useMemo(() => appointments.filter(task => {
    const day = String(task.due_date || "").slice(0, 10);
    return day > today && day <= nextWeek && matchesSearch(task);
  }), [appointments, today, nextWeek, search]);
  const uniquePatients = new Set(todaysAppointments.flatMap(task => Array.isArray(task.client_ids) ? task.client_ids : [])).size;
  const todayLabel = new Date().toLocaleDateString("uk-UA", { weekday: "long", day: "numeric", month: "long" });
  const formatDay = value => new Date(`${String(value || "").slice(0, 10)}T00:00:00`).toLocaleDateString("uk-UA", { day: "numeric", month: "long" });

  function appointmentList(rows, emptyText) {
    return rows.length ? <div className="dental-executor-list">{rows.map(task => {
      const clientId = Array.isArray(task.client_ids) ? task.client_ids[0] : "";
      const status = task.appointment_status || (task.status === "in_progress" ? "arrived" : "scheduled");
      return <article className="dental-executor-appointment" key={task.id}>
        <span className="dental-executor-time">{timeLabel(task.due_date)}</span>
        <span className="dental-executor-calendar-icon"><Icon name="calendar" /></span>
        <div className="dental-executor-appointment-copy">
          <strong>{task.client_names || task.client_name || "Пацієнт"}</strong>
          <small>{task.title?.replace(/^Запис клієнта\s*[·\u2013\u2014-]?\s*/i, "") || task.appointment_reason || "Прийом"}{task.duration_minutes ? ` · ${task.duration_minutes} хв` : ""}</small>
          <span className={`dental-executor-status ${statusTone(status)}`}>{appointmentStatuses[status] || "Заплановано"}</span>
        </div>
        <div className="dental-executor-appointment-actions">
          {clientId && <Link className="dental-executor-patient-link" to={`/patients/${clientId}`} title="Відкрити картку пацієнта" aria-label="Відкрити картку пацієнта"><Icon name="user" /></Link>}
          <Link className="dental-executor-open-link" to={`/appointments/${task.id}`} aria-label="Відкрити прийом"><Icon name="right" /></Link>
        </div>
      </article>;
    })}</div> : <div className="dental-executor-empty"><span><Icon name="calendar" /></span><strong>{emptyText}</strong>{calendarAvailable && <Link to="/appointments">Переглянути календар</Link>}</div>;
  }

  return <Layout page="Мої прийоми" user={dashboard.user}>
    <main className="content dental-executor-dashboard">
      <section className="dental-executor-hero">
        <div><span className="dental-executor-kicker">РОБОЧИЙ ДЕНЬ · {todayLabel}</span><h1>Мої прийоми</h1><p>Ваш особистий розклад і швидкий доступ до карток пацієнтів.</p></div>
        {calendarAvailable && <Link className="dental-executor-calendar-button" to="/appointments"><Icon name="calendar" /> Мій календар</Link>}
      </section>
      {error && <div className="dental-executor-error" role="alert">{error}<button type="button" onClick={refresh}>Спробувати ще раз</button></div>}
      <section className="dental-executor-stats" aria-label="Мій розклад">
        <article><span><Icon name="calendar" /></span><strong>{todaysAppointments.length}</strong><small>Прийомів сьогодні</small></article>
        <article><span className="blue"><Icon name="clock" /></span><strong>{upcomingAppointments.length}</strong><small>Найближчі 7 днів</small></article>
        <article><span className="violet"><Icon name="users" /></span><strong>{uniquePatients}</strong><small>Пацієнтів сьогодні</small></article>
      </section>
      <section className="dental-executor-panel">
        <header className="dental-executor-panel-heading"><div><span className="dental-executor-kicker">ВАША ЗМІНА</span><h2>Розклад на сьогодні</h2><p>{todayLabel}</p></div><span className="dental-executor-count">{todaysAppointments.length}</span></header>
        <label className="dental-executor-search"><Icon name="search" /><input type="search" value={search} onChange={event => setSearch(event.target.value)} placeholder="Пошук пацієнта або послуги…" /></label>
        {appointmentList(todaysAppointments, "На сьогодні прийомів немає.")}
      </section>
      <section className="dental-executor-panel dental-executor-upcoming">
        <header className="dental-executor-panel-heading"><div><span className="dental-executor-kicker">ПЛАНУВАННЯ</span><h2>Наступні прийоми</h2><p>Ваші записи на найближчий тиждень.</p></div><span className="dental-executor-count">{upcomingAppointments.length}</span></header>
        {appointmentList(upcomingAppointments, "На найближчий тиждень прийомів немає.")}
      </section>
    </main>
  </Layout>;
}
