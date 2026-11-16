import { useEffect, useMemo, useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import Icon from "../components/Icon.jsx";
import Layout from "../components/Layout.jsx";
import DateTimePicker from "../components/DateTimePicker.jsx";
import SearchableSelect from "../components/SearchableSelect.jsx";
import { api, post } from "../services/api.js";
import { serviceAppointmentTaskPayload } from "./Clients.jsx";
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
  const [clientId, setClientId] = useState("");
  const [serviceIndex, setServiceIndex] = useState("");
  const [responsibleId, setResponsibleId] = useState("");
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
  const clientServices = client?.services || [];
  const selectedService = serviceIndex === "" ? null : clientServices[Number(serviceIndex)];
  const appointmentById = useMemo(() => new Map((data?.tasks || []).map(task => [String(task.id), task])), [data?.tasks]);
  const isServiceAlreadyBooked = service => {
    if (service.is_package && !service.calendar_enabled) return true;
    const linkedAppointment = appointmentById.get(String(service.appointment_task_id || ""));
    return Boolean(linkedAppointment && linkedAppointment.status !== "cancelled");
  };
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
      const taskTime = String(task.due_date).match(/[ T](\d{2}):(\d{2})/);
      if (!taskTime) return false;
      const taskStart = Number(taskTime[1]) * 60 + Number(taskTime[2]);
      const taskDuration = Math.max(15, Number(task.duration_minutes || 60));
      return start < taskStart + taskDuration && taskStart < start + Number(duration);
    });
  }), [tasksOnDate, duration, date]);

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
    if (!availableTimes.includes(time)) return setError("Цей час уже зайнятий. Оберіть вільний слот.");
    setSaving(true);
    setError("");
    const appointmentAt = `${date}T${time}`;
    const serviceForBooking = selectedService ? { ...selectedService, add_to_calendar: true, appointment_at: appointmentAt, appointment_duration_minutes: Number(duration) } : null;
    const appointmentPayload = serviceForBooking
      ? { ...serviceAppointmentTaskPayload({ ...client, workspace_owner_id: client.created_by }, serviceForBooking, client.id), assignee_ids: responsibleId ? [responsibleId] : [] }
      : {
          action: "create",
          appointment_booking: true,
          workspace_owner_id: client.created_by || "",
          title: "Запис клієнта",
          description: `Загальний прийом пацієнта «${client.name}».`,
          client_name: client.name,
          client_ids: [client.id],
          assignee_ids: responsibleId ? [responsibleId] : [],
          project_ids: [],
          due_date: appointmentAt,
          duration_minutes: Number(duration),
          status: "todo",
          priority: "normal",
        };
    const result = await post("/api/tasks.php", appointmentPayload);
    if (!result.ok) {
      setSaving(false);
      return setError(result.message || "Не вдалося створити запис.");
    }
    if (selectedService && result.task_id) {
      const services = clientServices.map((service, index) => index === Number(serviceIndex)
        ? { ...serviceForBooking, appointment_task_id: result.task_id }
        : service);
      const linkedSave = await post("/api/clients.php", { ...client, workspace_owner_id: client.created_by || "", services, action: "update", id: client.id });
      if (!linkedSave.ok) {
        await post("/api/tasks.php", { action: "delete", id: result.task_id });
        setSaving(false);
        return setError(`Запис не збережено в картці послуг: ${linkedSave.message}`);
      }
    }
    setSaving(false);
    setNotice("Запис додано до календаря.");
    window.setTimeout(() => navigate("/calendar"), 600);
  }

  if (!data) return <div className="loading-screen">Завантаження записів…</div>;
  const appointments = data.tasks.filter(task => Number(task.is_client_appointment) === 1 && !["done", "archived", "cancelled"].includes(task.status));
  return <Layout page="Записи" user={data.user}>
    <div className="content appointments-page">
      <section className="welcome-row appointments-hero"><div><p className="eyebrow">СТОМАТОЛОГІЯ</p><h1>Записи</h1><p className="subtext">Плануйте прийоми клієнтів у вільні години календаря.</p></div><button className="primary-button" type="button" onClick={() => document.getElementById("appointment-form")?.scrollIntoView({ behavior: "smooth" })}><Icon name="plus" /> Новий запис</button></section>
      <div className="appointments-layout">
        <section className="panel appointment-create-panel" id="appointment-form">
          <div className="panel-heading"><div><h2>Новий запис</h2><p>Оберіть клієнта, відповідального та вільний час.</p></div><span className="appointment-heading-icon"><Icon name="calendar" /></span></div>
          {error && <div className="appointment-alert error" role="alert">{error}</div>}{notice && <div className="appointment-alert success" role="status">{notice}</div>}
          <form className="appointment-form" onSubmit={save}>
            <label>Клієнт<SearchableSelect options={clients.map(item => ({ id: item.id, name: item.name, email: [item.module_data?.phone, item.module_data?.email].filter(Boolean).join(" · ") }))} value={clientId} placeholder="Оберіть клієнта" onChange={value => { const nextClient = clients.find(item => String(item.id) === String(value)); const doctorId = String(nextClient?.module_data?.doctor_id || ""); const isTeamDoctor = (data.team_users || []).some(person => String(person.id) === doctorId); setClientId(value); setServiceIndex(""); setResponsibleId(isTeamDoctor ? doctorId : ""); }} /></label>
            {client && <><div className="appointment-client-summary"><div><strong>{client.name}</strong><small>{[client.module_data?.phone, client.module_data?.email].filter(Boolean).join(" · ") || "Контактні дані не вказані"}</small></div><Link to={`/clients/${client.id}/edit`} target="_blank" rel="noreferrer" className="appointment-add-service"><Icon name="plus" /> Додати послугу</Link></div><div className="appointment-client-services"><div className="appointment-service-head"><div><strong>Послуги клієнта</strong><small>{clientServices.length ? "Оберіть незаплановану послугу. Для повторного прийому додайте її ще раз у картці пацієнта." : "Наявних послуг немає"}</small></div></div>{clientServices.length ? <div className="appointment-service-list">{clientServices.map((service, index) => { const booked = isServiceAlreadyBooked(service); return <button type="button" disabled={booked} className={`appointment-service-option${String(index) === serviceIndex ? " selected" : ""}${booked ? " is-booked" : ""}`} key={`${service.name}-${index}`} onClick={() => { setServiceIndex(current => current === String(index) ? "" : String(index)); setDuration(Number(service.appointment_duration_minutes || 60)); }}><span><strong>{service.name}</strong>{service.tooth_numbers?.length > 0 && <small>Зуби: {service.tooth_numbers.join(", ")}</small>}{booked && <small>Прийом уже заплановано</small>}</span><b>{Number(service.price || service.client_price || 0).toLocaleString("uk-UA")} грн</b></button>; })}</div> : <p className="appointment-no-services">Додайте послугу в картці пацієнта або створіть загальний запис без прив’язки до послуги.</p>}</div></>}
            <div className="appointment-fields"><label>Відповідальний<SearchableSelect options={[{ id: "", name: "Не призначено" }, ...(data.team_users || []).map(person => ({ id: person.id, name: person.name, email: person.email }))]} value={responsibleId} placeholder="Оберіть відповідального" onChange={setResponsibleId} /></label><label>Дата запису<DateTimePicker value={date} onChange={setDate} required /></label><label>Тривалість<select value={duration} onChange={event => setDuration(Number(event.target.value))}>{[15, 30, 45, 60, 90, 120, 150, 180, 240, 300, 360, 480].map(minutes => <option key={minutes} value={minutes}>{minutes < 60 ? `${minutes} хв` : `${minutes / 60} год${minutes % 60 ? ` ${minutes % 60} хв` : ""}`}</option>)}</select></label><label>Вільний час<select value={time} onChange={event => setTime(event.target.value)} disabled={!availableTimes.length}>{availableTimes.length ? availableTimes.map(slot => <option key={slot} value={slot}>{slot}</option>) : <option value="">Немає вільних слотів</option>}</select></label></div>
            {!availableTimes.length && <p className="appointment-no-slots">На цю дату немає вільного часу для обраної тривалості.</p>}
            <div className="appointment-form-footer"><small>Графік записів: 08:00–23:59 · крок 15 хв</small><button className="primary-button" type="submit" disabled={saving || !client || !availableTimes.length}>{saving ? "Зберігаємо…" : "Додати запис"}</button></div>
          </form>
        </section>
        <section className="panel appointment-list-panel"><div className="panel-heading"><div><h2>Заплановані прийоми</h2><p>Записи, створені для стоматологічних клієнтів.</p></div><span className="appointment-count">{appointments.length}</span></div>
          {appointments.length ? <div className="appointment-list">{appointments.map(item => <Link to={`/tasks/${item.id}`} className="appointment-row" key={item.id}><span className="appointment-row-icon"><Icon name="calendar" /></span><div className="appointment-row-copy"><strong>Запис клієнта</strong><small>{[item.client_names || item.client_name || "Клієнт", item.assignee_names || item.assignee_name].filter(Boolean).join(" · ")}</small></div><div className="appointment-row-time"><strong>{item.due_date ? new Date(String(item.due_date).replace(" ", "T")).toLocaleTimeString("uk-UA", { hour: "2-digit", minute: "2-digit" }) : ""}</strong><small>{formatDate(item.due_date)}</small></div></Link>)}</div> : <div className="appointment-empty"><Icon name="calendar" /><strong>Записів поки немає</strong><span>Створіть перший прийом у формі поруч.</span></div>}
        </section>
      </div>
    </div>
  </Layout>;
}
