import { useCallback, useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import Icon from "../components/Icon.jsx";
import Layout from "../components/Layout.jsx";
import { api } from "../services/api.js";
import "./dentistry-manager-dashboard.css";

const pad = value => String(value).padStart(2, "0");
const dayKey = date => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
const timeLabel = value => String(value || "").match(/[ T](\d{2}:\d{2})/)?.[1] || "Час не вказано";
const activeAppointment = task => !["done", "cancelled", "archived"].includes(task.status) && !["completed", "cancelled", "archived"].includes(task.appointment_status);

export default function DentistryManagerDashboard({ dashboard, calendarAvailable }) {
  const [clinic, setClinic] = useState(null);
  const [loadError, setLoadError] = useState("");
  const refresh = useCallback(async () => {
    const [clientsResult, tasksResult] = await Promise.all([api("/api/clients.php"), api("/api/tasks.php?scope=calendar")]);
    if (!clientsResult.ok) { setLoadError(clientsResult.message || "Не вдалося завантажити дані клініки."); return; }
    setLoadError("");
    setClinic({ clients: clientsResult.clients || [], team: clientsResult.team_users || [], tasks: tasksResult.ok ? tasksResult.tasks || [] : [] });
  }, []);
  useEffect(() => {
    refresh();
    const interval = window.setInterval(refresh, 30000);
    window.addEventListener("focus", refresh);
    return () => { window.clearInterval(interval); window.removeEventListener("focus", refresh); };
  }, [refresh]);

  const today = dayKey(new Date());
  const appointments = useMemo(() => (clinic?.tasks || []).filter(task => Number(task.is_client_appointment) === 1 && activeAppointment(task)).sort((a, b) => String(a.due_date || "").localeCompare(String(b.due_date || ""))), [clinic]);
  const todaysAppointments = appointments.filter(task => String(task.due_date || "").slice(0, 10) === today);
  const nextWeekAppointments = appointments.filter(task => {
    const date = String(task.due_date || "").slice(0, 10);
    return date >= today && date <= dayKey(new Date(Date.now() + 7 * 86400000));
  });
  const recentPatients = [...(clinic?.clients || [])].sort((a, b) => String(b.created_at || "").localeCompare(String(a.created_at || ""))).slice(0, 5);
  const doctorWorkload = (clinic?.team || []).map(person => ({ ...person, appointments: nextWeekAppointments.filter(task => (task.assignee_ids || [task.assignee_id]).some(id => String(id) === String(person.id))).length })).filter(person => ["manager", "owner"].includes(String(person.role || "").toLowerCase())).sort((a, b) => b.appointments - a.appointments);
  const todayLabel = new Date().toLocaleDateString("uk-UA", { weekday: "long", day: "numeric", month: "long" });

  function appointmentRows(rows, emptyText) {
    return rows.length ? <div className="dental-dashboard-appointment-list">{rows.map(task => <Link className="dental-dashboard-appointment" to={`/appointments/${task.id}`} key={task.id}>
      <span className="dental-dashboard-time">{timeLabel(task.due_date)}</span>
      <span className="dental-dashboard-appointment-icon"><Icon name="calendar" /></span>
      <span className="dental-dashboard-appointment-info"><strong>{task.client_names || task.client_name || "Пацієнт"}</strong><small>{task.assignee_names || task.assignee_name || "Лікаря не призначено"}{task.appointment_reason ? ` · ${task.appointment_reason}` : ""}</small></span>
      <Icon name="right" />
    </Link>)}</div> : <div className="dental-dashboard-empty"><span><Icon name="calendar" /></span><p>{emptyText}</p>{calendarAvailable && <Link to="/appointments">Створити запис</Link>}</div>;
  }

  return <Layout page="Панель клініки" user={dashboard.user}>
    <main className="content dental-manager-dashboard">
      <section className="dental-dashboard-hero">
        <div><p className="eyebrow">СТОМАТОЛОГІЧНА КЛІНІКА · {todayLabel}</p><h1>Панель керування</h1><p className="subtext">Записи, пацієнти та робота команди - все важливе на сьогодні.</p></div>
        <div className="dental-dashboard-actions"><Link className="dental-dashboard-secondary" to="/clients"><Icon name="users" /> Пацієнти</Link>{calendarAvailable && <Link className="primary-action" to="/appointments"><Icon name="plus" /> Записати пацієнта</Link>}</div>
      </section>

      {loadError && <div className="dental-dashboard-error" role="alert">{loadError}<button type="button" onClick={refresh}>Спробувати ще раз</button></div>}

      <section className="dental-dashboard-stats" aria-label="Показники клініки">
        <Link to="/appointments" className="dental-dashboard-stat dental-stat-green"><span><Icon name="calendar" /></span><strong>{todaysAppointments.length}</strong><small>Прийомів сьогодні</small></Link>
        <Link to="/appointments" className="dental-dashboard-stat dental-stat-blue"><span><Icon name="clock" /></span><strong>{nextWeekAppointments.length}</strong><small>Записів за 7 днів</small></Link>
        <Link to="/clients" className="dental-dashboard-stat dental-stat-violet"><span><Icon name="users" /></span><strong>{clinic?.clients.length ?? "-"}</strong><small>Пацієнтів у клініці</small></Link>
      </section>

      <section className="dental-dashboard-main-grid">
        <article className="panel dental-dashboard-panel dental-dashboard-today">
          <header className="dental-dashboard-panel-heading"><div><span className="dental-dashboard-kicker">РОЗКЛАД</span><h2>Сьогодні в клініці</h2><p>{todayLabel}</p></div><Link to="/appointments">Усі записи <Icon name="forward" /></Link></header>
          {appointmentRows(todaysAppointments, "На сьогодні записів немає.")}
        </article>
        <article className="panel dental-dashboard-panel">
          <header className="dental-dashboard-panel-heading"><div><span className="dental-dashboard-kicker">КОМАНДА</span><h2>Завантаженість лікарів</h2><p>Заплановані прийоми на найближчі 7 днів.</p></div><Link to="/team">Команда <Icon name="forward" /></Link></header>
          {doctorWorkload.length ? <div className="dental-dashboard-doctor-list">{doctorWorkload.slice(0, 6).map(person => <div className="dental-dashboard-doctor" key={person.id}><span className="dental-dashboard-doctor-avatar">{person.name?.trim()?.[0] || <Icon name="user" />}</span><span><strong>{person.name}</strong><small>{person.email}</small></span><b>{person.appointments}<small> записів</small></b></div>)}</div> : <p className="dental-dashboard-muted">Список команди поки порожній.</p>}
        </article>
      </section>

      <section className="dental-dashboard-lower-grid">
        <article className="panel dental-dashboard-panel">
          <header className="dental-dashboard-panel-heading"><div><span className="dental-dashboard-kicker">ПЛАНУВАННЯ</span><h2>Найближчі прийоми</h2><p>Записи на найближчий тиждень.</p></div><span className="dental-dashboard-count">{nextWeekAppointments.length}</span></header>
          {appointmentRows(nextWeekAppointments.slice(0, 7), "Майбутніх прийомів поки немає.")}
        </article>
        <article className="panel dental-dashboard-panel dental-dashboard-patients">
          <header className="dental-dashboard-panel-heading"><div><span className="dental-dashboard-kicker">КАРТКИ КЛІЄНТІВ</span><h2>Нові пацієнти</h2><p>Останні додані до клініки.</p></div><Link to="/clients">Усі пацієнти <Icon name="forward" /></Link></header>
          {recentPatients.length ? <div className="dental-dashboard-patient-list">{recentPatients.map(patient => <Link to={`/patients/${patient.id}`} key={patient.id}><span className="dental-dashboard-patient-avatar"><Icon name="user" /></span><span><strong>{patient.name}</strong><small>{patient.module_data?.phone || patient.phone || "Контакт не вказано"}</small></span><Icon name="right" /></Link>)}</div> : <p className="dental-dashboard-muted">Пацієнтів ще не додано.</p>}
          <Link className="dental-dashboard-add-patient" to="/clients/new"><Icon name="plus" /> Додати пацієнта</Link>
        </article>
      </section>
    </main>
  </Layout>;
}
