import { useEffect, useMemo, useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import Icon from "../components/Icon.jsx";
import Layout from "../components/Layout.jsx";
import DateTimePicker from "../components/DateTimePicker.jsx";
import SearchableSelect from "../components/SearchableSelect.jsx";
import { api, post } from "../services/api.js";
import "./appointments.css";

const pad = value => String(value).padStart(2, "0");
const isoDay = date => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
const formatDate = value => value ? new Date(`${String(value).slice(0, 10)}T12:00:00`).toLocaleDateString("uk-UA", { day: "numeric", month: "long", year: "numeric" }) : "Дата не вибрана";
const timeSlots = Array.from({ length: 64 }, (_, index) => {
  const minutes = 8 * 60 + index * 15;
  return `${pad(Math.floor(minutes / 60))}:${pad(minutes % 60)}`;
});

export default function Appointments() {
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const [data, setData] = useState(null);
  const [clientId, setClientId] = useState(params.get("client") || "");
  const [responsibleId, setResponsibleId] = useState("");
  const [doctorFilter, setDoctorFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("upcoming");
  const [search, setSearch] = useState("");
  const [cabinet, setCabinet] = useState("");
  const [reason, setReason] = useState("");
  const [comment, setComment] = useState("");
  const [date, setDate] = useState(() => /^\d{4}-\d{2}-\d{2}$/.test(params.get("date") || "") ? params.get("date") : isoDay(new Date()));
  const [time, setTime] = useState(params.get("time") || "09:00");
  const [duration, setDuration] = useState(60);
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState("");

  useEffect(() => {
    let active = true;
    Promise.all([api("/api/clients.php"), api("/api/tasks.php?scope=calendar")]).then(([clientsResult, tasksResult]) => {
      if (!active) return;
      if (!clientsResult.ok) return navigate("/");
      setData({ ...clientsResult, tasks: tasksResult.ok ? tasksResult.tasks || [] : [] });
    });
    return () => { active = false; };
  }, [navigate]);

  const clients = data?.clients || [];
  const client = clients.find(item => String(item.id) === String(clientId));
  const tasksOnDate = useMemo(() => (data?.tasks || []).filter(task =>
    Number(task.is_client_appointment) === 1 && task.due_date && String(task.due_date).slice(0, 10) === date && !["done", "cancelled", "archived"].includes(task.status)
  ), [data, date]);
  const availableTimes = useMemo(() => timeSlots.filter(slot => {
    const [hour, minute] = slot.split(":").map(Number);
    const start = hour * 60 + minute;
    const today = isoDay(new Date());
    if (date === today && start <= new Date().getHours() * 60 + new Date().getMinutes()) return false;
    if (start + Number(duration) > 23 * 60 + 59) return false;
    return !tasksOnDate.some(task => {
      const taskDoctors = (task.assignee_ids || [task.assignee_id]).filter(Boolean).map(String);
      if (responsibleId && !taskDoctors.includes(String(responsibleId)) && (!cabinet.trim() || String(task.cabinet_id || '').trim().toLocaleLowerCase() !== cabinet.trim().toLocaleLowerCase())) return false;
      const taskTime = String(task.due_date).match(/[ T](\d{2}):(\d{2})/);
      if (!taskTime) return false;
      const taskStart = Number(taskTime[1]) * 60 + Number(taskTime[2]);
      const taskDuration = Math.max(15, Number(task.duration_minutes || 60));
      return start < taskStart + taskDuration && taskStart < start + Number(duration);
    });
  }), [tasksOnDate, duration, date, responsibleId, cabinet]);

  useEffect(() => {
    if (availableTimes.length && !availableTimes.includes(time)) setTime(availableTimes[0]);
  }, [availableTimes, time]);

  useEffect(() => {
    if (!clientId) return undefined;
    let active = true;
    const refreshClient = async () => {
      if (document.visibilityState !== "visible") return;
      const result = await api("/api/clients.php");
      if (!active || !result.ok) return;
      setData(current => current ? { ...current, clients: result.clients || current.clients, team_users: result.team_users || current.team_users } : current);
    };
    window.addEventListener("focus", refreshClient);
    document.addEventListener("visibilitychange", refreshClient);
    const interval = window.setInterval(refreshClient, 8000);
    return () => {
      active = false;
      window.removeEventListener("focus", refreshClient);
      document.removeEventListener("visibilitychange", refreshClient);
      window.clearInterval(interval);
    };
  }, [clientId]);

  async function save(event) {
    event.preventDefault();
    if (!client) return setError("Оберіть клієнта.");
    if (!responsibleId) return setError("Оберіть відповідального лікаря.");
    if (!availableTimes.includes(time)) return setError("Цей час уже зайнятий. Оберіть вільний слот.");
    setSaving(true);
    setError("");
    const appointmentAt = `${date}T${time}`;
    const appointmentPayload = {
      action: "create", appointment_booking: true, workspace_owner_id: client.created_by || "",
      title: "Запис клієнта", description: reason ? `Причина прийому: ${reason}` : `Прийом пацієнта «${client.name}».`,
      client_name: client.name, client_ids: [client.id], assignee_ids: [responsibleId], project_ids: [],
      due_date: appointmentAt, duration_minutes: Number(duration), cabinet_id: cabinet,
      status: "todo", priority: "normal",
    };
    const result = await post("/api/tasks.php", appointmentPayload);
    if (!result.ok) {
      setSaving(false);
      return setError(result.message || "Не вдалося створити запис.");
    }
    const appointmentDetails = await post("/api/dentistry-care.php", { action: "save_appointment_details", patient_id: client.id, appointment_id: result.task_id, cabinet_id: cabinet, reason, comment });
    if (!appointmentDetails.ok) {
      await post("/api/tasks.php", { action: "delete", id: result.task_id });
      setSaving(false);
      return setError(`Запис не додано: ${appointmentDetails.message}`);
    }
    setSaving(false);
    setNotice("Запис додано до календаря.");
    window.setTimeout(() => navigate("/calendar"), 600);
  }

  if (!data) return <div className="loading-screen">Завантаження записів…</div>;
  const appointments = data.tasks.filter(task => {
    if (Number(task.is_client_appointment) !== 1) return false;
    const appointmentStatus = task.appointment_status || ({ archived: "completed", done: "completed", cancelled: "cancelled", in_progress: "arrived" }[task.status] || "scheduled");
    const active = ["scheduled", "confirmed", "arrived"].includes(appointmentStatus);
    const statusMatches = statusFilter === "upcoming" ? active : statusFilter === "archive" ? appointmentStatus === "completed" : appointmentStatus === statusFilter;
    const doctorMatches = !doctorFilter || (task.assignee_ids || [task.assignee_id]).some(personId => String(personId) === String(doctorFilter));
    const query = search.trim().toLocaleLowerCase("uk");
    const searchMatches = !query || `${task.client_names || task.client_name || ""} ${task.assignee_names || task.assignee_name || ""} ${task.appointment_reason || ""}`.toLocaleLowerCase("uk").includes(query);
    return statusMatches && doctorMatches && searchMatches;
  }).sort((a, b) => String(a.due_date || "").localeCompare(String(b.due_date || "")));
  return <Layout page="Записи" user={data.user}>
    <div className="content appointments-page">
      <section className="welcome-row appointments-hero"><div><p className="eyebrow">СТОМАТОЛОГІЯ</p><h1>Записи</h1><p className="subtext">Плануйте прийоми клієнтів у вільні години календаря.</p></div><button className="primary-button" type="button" onClick={() => document.getElementById("appointment-form")?.scrollIntoView({ behavior: "smooth" })}><Icon name="plus" /> Новий запис</button></section>
      <div className="appointments-layout">
        <section className="panel appointment-create-panel" id="appointment-form">
          <div className="panel-heading"><div><h2>Новий запис</h2><p>Оберіть клієнта, відповідального та вільний час.</p></div><span className="appointment-heading-icon"><Icon name="calendar" /></span></div>
          {error && <div className="appointment-alert error" role="alert">{error}</div>}{notice && <div className="appointment-alert success" role="status">{notice}</div>}
          <form className="appointment-form" onSubmit={save}>
            <label>Клієнт<SearchableSelect options={clients.map(item => ({ id: item.id, name: item.name, email: [item.module_data?.phone, item.module_data?.email].filter(Boolean).join(" · ") }))} value={clientId} placeholder="Оберіть пацієнта" onChange={value => { const nextClient = clients.find(item => String(item.id) === String(value)); const doctorId = String(nextClient?.module_data?.doctor_id || ""); const isTeamDoctor = (data.team_users || []).some(person => String(person.id) === doctorId); setClientId(value); setResponsibleId(isTeamDoctor ? doctorId : ""); }} /></label>
            {client && <div className="appointment-client-summary"><div><strong>{client.name}</strong><small>{[client.module_data?.phone, client.module_data?.email].filter(Boolean).join(" · ") || "Контактні дані не вказані"}</small></div><Link to={`/patients/${client.id}`} className="appointment-add-service">Відкрити картку пацієнта</Link></div>}
            <div className="appointment-fields"><label>Відповідальний<SearchableSelect options={[{ id: "", name: "Не призначено" }, ...(data.team_users || []).map(person => ({ id: person.id, name: person.name, email: person.email }))]} value={responsibleId} placeholder="Оберіть відповідального" onChange={setResponsibleId} /></label><label>Дата запису<DateTimePicker value={date} onChange={setDate} required /></label><label>Тривалість<select value={duration} onChange={event => setDuration(Number(event.target.value))}>{[15, 30, 45, 60, 90, 120, 150, 180, 240, 300, 360, 480].map(minutes => <option key={minutes} value={minutes}>{minutes < 60 ? `${minutes} хв` : `${minutes / 60} год${minutes % 60 ? ` ${minutes % 60} хв` : ""}`}</option>)}</select></label><label>Вільний час<select value={time} onChange={event => setTime(event.target.value)} disabled={!availableTimes.length}>{availableTimes.length ? availableTimes.map(slot => <option key={slot} value={slot}>{slot}</option>) : <option value="">Немає вільних слотів</option>}</select></label></div>
            <div className="appointment-fields"><label>Кабінет<input value={cabinet} onChange={event => setCabinet(event.target.value)} placeholder="Наприклад, Кабінет 2" /></label><label>Причина прийому<input value={reason} onChange={event => setReason(event.target.value)} placeholder="Консультація, огляд…" /></label><label className="appointment-field-wide">Коментар<textarea value={comment} onChange={event => setComment(event.target.value)} rows="2" /></label></div>
            {!availableTimes.length && <p className="appointment-no-slots">На цю дату немає вільного часу для обраної тривалості.</p>}
            <div className="appointment-form-footer"><small>Графік записів: 08:00–23:59 · крок 15 хв</small><button className="primary-button" type="submit" disabled={saving || !client || !availableTimes.length}>{saving ? "Зберігаємо…" : "Додати запис"}</button></div>
          </form>
        </section>
        <section className="panel appointment-list-panel"><div className="panel-heading"><div><h2>{statusFilter === "upcoming" ? "Найближчі прийоми" : "Прийоми"}</h2><p>Записи пацієнтів у стоматологічному календарі.</p></div><span className="appointment-count">{appointments.length}</span></div><div className="appointment-list-filters"><input aria-label="Пошук прийомів" value={search} onChange={event => setSearch(event.target.value)} placeholder="Пошук за пацієнтом, лікарем або причиною…" /><select aria-label="Фільтр статусу прийомів" value={statusFilter} onChange={event => setStatusFilter(event.target.value)}><option value="upcoming">Майбутні та активні</option><option value="scheduled">Заплановані</option><option value="confirmed">Підтверджені</option><option value="arrived">Пацієнт прибув</option><option value="archive">Завершені / архів</option><option value="cancelled">Скасовані</option></select><select aria-label="Фільтр лікаря" value={doctorFilter} onChange={event => setDoctorFilter(event.target.value)}><option value="">Усі лікарі</option>{(data.team_users || []).map(person => <option key={person.id} value={person.id}>{person.name}</option>)}</select></div>
          {appointments.length ? <div className="appointment-list">{appointments.map(item => { const itemStatus = item.appointment_status || ({ archived: "completed", done: "completed", cancelled: "cancelled", in_progress: "arrived" }[item.status] || "scheduled"); const labels = { scheduled: "Заплановано", confirmed: "Підтверджено", arrived: "Пацієнт прибув", completed: "Завершено", cancelled: "Скасовано" }; return <Link to={`/appointments/${item.id}`} className="appointment-row" key={item.id}><span className="appointment-row-icon"><Icon name="calendar" /></span><div className="appointment-row-copy"><strong>Запис клієнта <em className={`appointment-status-pill status-${itemStatus}`}>{labels[itemStatus]}</em></strong><small>{[item.client_names || item.client_name || "Клієнт", item.assignee_names || item.assignee_name].filter(Boolean).join(" · ")}</small></div><div className="appointment-row-time"><strong>{item.due_date ? new Date(String(item.due_date).replace(" ", "T")).toLocaleTimeString("uk-UA", { hour: "2-digit", minute: "2-digit" }) : ""}</strong><small>{formatDate(item.due_date)}</small></div></Link>; })}</div> : <div className="appointment-empty"><Icon name="calendar" /><strong>{statusFilter === "upcoming" ? "Найближчих прийомів немає" : "За цим фільтром записів немає"}</strong><span>{statusFilter === "upcoming" ? "Нові прийоми з’являться тут." : "Змініть фільтр або пошуковий запит."}</span></div>}
        </section>
      </div>
    </div>
  </Layout>;
}
