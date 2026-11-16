import { useEffect, useMemo, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import Icon from "../components/Icon.jsx";
import Layout from "../components/Layout.jsx";
import DateTimePicker from "../components/DateTimePicker.jsx";
import SearchableSelect from "../components/SearchableSelect.jsx";
import { api, post } from "../services/api.js";
import "./appointments.css";

const pad = value => String(value).padStart(2, "0");
const dayKey = date => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
const slots = Array.from({ length: 64 }, (_, index) => { const minutes = 480 + index * 15; return `${pad(Math.floor(minutes / 60))}:${pad(minutes % 60)}`; });

export default function AppointmentEditPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [data, setData] = useState(null);
  const [task, setTask] = useState(null);
  const [clientId, setClientId] = useState("");
  const [responsibleId, setResponsibleId] = useState("");
  const [serviceIndex, setServiceIndex] = useState("");
  const [date, setDate] = useState(dayKey(new Date()));
  const [time, setTime] = useState("09:00");
  const [duration, setDuration] = useState(60);
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let active = true;
    Promise.all([api(`/api/tasks.php?id=${encodeURIComponent(id)}`), api("/api/clients.php"), api("/api/tasks.php?scope=calendar")]).then(([detail, clients, calendar]) => {
      if (!active) return;
      if (!detail.ok || Number(detail.task?.is_client_appointment) !== 1 || !clients.ok || clients.active_client_module !== "dentistry") return navigate("/appointments", { replace: true });
      const current = detail.task;
      const dueDate = String(current.due_date || "").replace(" ", "T");
      const currentClientId = (current.client_ids || [])[0] || "";
      const patient = (clients.clients || []).find(item => String(item.id) === String(currentClientId));
      const cleanTitle = String(current.title || "").replace(/^Запис клієнта\s*[·—-]?\s*/, "");
      const selectedServiceIndex = (patient?.services || []).findIndex(service => cleanTitle.startsWith(service.name));
      setTask(current);
      setData({ ...clients, tasks: calendar.ok ? calendar.tasks || [] : [] });
      setClientId(String(currentClientId));
      setResponsibleId(String((current.assignee_ids || [])[0] || current.assignee_id || ""));
      setServiceIndex(selectedServiceIndex >= 0 ? String(selectedServiceIndex) : "");
      if (dueDate.length >= 16) { setDate(dueDate.slice(0, 10)); setTime(dueDate.slice(11, 16)); }
      setDuration(Math.max(15, Number(current.duration_minutes || 60)));
    });
    return () => { active = false; };
  }, [id, navigate]);

  const clients = data?.clients || [];
  const client = clients.find(item => String(item.id) === String(clientId));
  const services = client?.services || [];
  const selectedService = serviceIndex === "" ? null : services[Number(serviceIndex)];
  const tasksOnDate = useMemo(() => (data?.tasks || []).filter(item => Number(item.is_client_appointment) === 1 && String(item.id) !== String(id) && item.due_date && String(item.due_date).slice(0, 10) === date && !["done", "cancelled", "archived"].includes(item.status)), [data, date, id]);
  const availableTimes = useMemo(() => slots.filter(slot => {
    const [hour, minute] = slot.split(":").map(Number);
    const start = hour * 60 + minute;
    if (date === dayKey(new Date()) && start <= new Date().getHours() * 60 + new Date().getMinutes()) return false;
    if (start + duration > 23 * 60 + 59) return false;
    return !tasksOnDate.some(item => {
      const match = String(item.due_date).match(/[ T](\d{2}):(\d{2})/);
      if (!match) return false;
      const otherStart = Number(match[1]) * 60 + Number(match[2]);
      const otherDuration = Math.max(15, Number(item.duration_minutes || 60));
      return start < otherStart + otherDuration && otherStart < start + duration;
    });
  }), [tasksOnDate, duration, date]);

  useEffect(() => { if (availableTimes.length && !availableTimes.includes(time)) setTime(availableTimes[0]); }, [availableTimes, time]);

  async function save(event) {
    event.preventDefault();
    if (!client) return setError("Оберіть клієнта.");
    if (!availableTimes.includes(time)) return setError("Цей час уже зайнятий. Оберіть вільний слот.");
    setSaving(true); setError("");
    const serviceName = selectedService ? `${selectedService.name}${selectedService.tooth_numbers?.length ? ` · зуби ${selectedService.tooth_numbers.join(", ")}` : ""}` : "";
    const result = await post("/api/tasks.php", {
      action: "update", id, title: serviceName ? `Запис клієнта · ${serviceName}` : "Запис клієнта",
      description: task.description || "", client_name: client.name, client_ids: [client.id],
      assignee_ids: responsibleId ? [responsibleId] : [], due_date: `${date}T${time}`,
      duration_minutes: Number(duration), appointment_booking: true, status: task.status || "todo", priority: task.priority || "normal",
      reminder_at: task.reminder_at || "", creator_reminder: task.creator_reminder || 0,
    });
    setSaving(false);
    if (!result.ok) return setError(result.message || "Не вдалося оновити запис.");
    navigate(`/tasks/${id}`);
  }

  if (!data || !task) return <div className="loading-screen">Завантаження запису…</div>;
  return <Layout page="Редагувати запис" user={data.user}>
    <div className="content appointments-page">
      <button className="page-back" type="button" onClick={() => navigate(`/tasks/${id}`)}><Icon name="back" /> Назад до запису</button>
      <section className="welcome-row appointments-hero"><div><p className="eyebrow">СТОМАТОЛОГІЯ</p><h1>Редагувати запис</h1><p className="subtext">Оновіть пацієнта, послугу або час прийому.</p></div></section>
      <section className="panel appointment-create-panel appointment-edit-panel">
        {error && <div className="appointment-alert error" role="alert">{error}</div>}
        <form className="appointment-form" onSubmit={save}>
          <label>Клієнт<SearchableSelect options={clients.map(item => ({ id: item.id, name: item.name, email: [item.module_data?.phone, item.module_data?.email].filter(Boolean).join(" · ") }))} value={clientId} placeholder="Оберіть клієнта" onChange={value => { const next = clients.find(item => String(item.id) === String(value)); const doctorId = String(next?.module_data?.doctor_id || ""); setClientId(value); setServiceIndex(""); setResponsibleId((data.team_users || []).some(person => String(person.id) === doctorId) ? doctorId : ""); }} /></label>
          {client && <><div className="appointment-client-summary"><div><strong>{client.name}</strong><small>{[client.module_data?.phone, client.module_data?.email].filter(Boolean).join(" · ") || "Контактні дані не вказані"}</small></div><Link to={`/clients/${client.id}/edit`} target="_blank" rel="noreferrer" className="appointment-add-service"><Icon name="plus" /> Додати послугу</Link></div><div className="appointment-client-services"><div className="appointment-service-head"><div><strong>Послуги клієнта</strong><small>Оберіть послугу або залиште без вибору</small></div></div>{services.length ? <div className="appointment-service-list">{services.map((service, index) => <button type="button" className={`appointment-service-option${String(index) === serviceIndex ? " selected" : ""}`} key={`${service.name}-${index}`} onClick={() => setServiceIndex(current => current === String(index) ? "" : String(index))}><span><strong>{service.name}</strong>{service.tooth_numbers?.length > 0 && <small>Зуби: {service.tooth_numbers.join(", ")}</small>}</span><b>{Number(service.price || 0).toLocaleString("uk-UA")} грн</b></button>)}</div> : <p className="appointment-no-services">Послуг клієнта ще не додано.</p>}</div></>}
          <div className="appointment-fields"><label>Відповідальний<SearchableSelect options={[{ id: "", name: "Не призначено" }, ...(data.team_users || []).map(person => ({ id: person.id, name: person.name, email: person.email }))]} value={responsibleId} placeholder="Оберіть відповідального" onChange={setResponsibleId} /></label><label>Дата запису<DateTimePicker value={date} onChange={setDate} required /></label><label>Тривалість<select value={duration} onChange={event => setDuration(Number(event.target.value))}>{[15, 30, 45, 60, 90, 120, 150, 180, 240, 300, 360, 480].map(minutes => <option key={minutes} value={minutes}>{minutes < 60 ? `${minutes} хв` : `${minutes / 60} год${minutes % 60 ? ` ${minutes % 60} хв` : ""}`}</option>)}</select></label><label>Вільний час<select value={time} onChange={event => setTime(event.target.value)} disabled={!availableTimes.length}>{availableTimes.length ? availableTimes.map(slot => <option key={slot} value={slot}>{slot}</option>) : <option value="">Немає вільних слотів</option>}</select></label></div>
          {!availableTimes.length && <p className="appointment-no-slots">На цю дату немає вільного часу для обраної тривалості.</p>}
          <div className="appointment-form-footer"><small>Графік записів: 08:00–23:59 · крок 15 хв</small><div><button className="outline-btn" type="button" onClick={() => navigate(`/tasks/${id}`)}>Скасувати</button><button className="primary-button" type="submit" disabled={saving || !client || !availableTimes.length}>{saving ? "Зберігаємо…" : "Зберегти запис"}</button></div></div>
        </form>
      </section>
    </div>
  </Layout>;
}
