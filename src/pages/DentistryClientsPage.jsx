import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import Layout from "../components/Layout.jsx";
import Icon from "../components/Icon.jsx";
import { isPlanLimitReached, planLimitHint } from "../utils/planLimits.js";
import { api } from "../services/api.js";
import "./dentistry-clients.css";

const visitDate = (value) => {
  const date = new Date(String(value || "").replace(" ", "T"));
  if (Number.isNaN(date.getTime())) return "";
  return date.toLocaleString("uk-UA", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
};

export default function DentistryClientsPage({ data, onCreate, onEdit, onDelete, notice, error }) {
  const navigate = useNavigate();
  const [search, setSearch] = useState("");
  const [filter, setFilter] = useState("all");
  const [appointments, setAppointments] = useState([]);
  const now = Date.now();
  useEffect(() => {
    let active = true;
    api("/api/tasks.php?scope=calendar").then(result => {
      if (active && result.ok) setAppointments((result.tasks || []).filter(task => Number(task.is_client_appointment) === 1));
    });
    return () => { active = false; };
  }, []);
  const clientLimitReached = isPlanLimitReached(data.user?.subscription, "clients");
  const patients = useMemo(() => (data.clients || []).map((client) => {
    const moduleData = client.module_data || {};
    const patientAppointments = appointments.filter(task => (task.client_ids || []).some(id => String(id) === String(client.id)) && !["done", "cancelled", "archived"].includes(task.status))
      .map(task => ({ ...task, appointmentDate: new Date(String(task.due_date || "").replace(" ", "T")) }))
      .filter(task => !Number.isNaN(task.appointmentDate.getTime()))
      .sort((left, right) => left.appointmentDate - right.appointmentDate);
    return { ...client, moduleData, appointments: patientAppointments, nextAppointment: patientAppointments.find(task => task.appointmentDate.getTime() >= now) || null };
  }).filter((patient) => {
    const query = search.trim().toLocaleLowerCase("uk");
    const haystack = [patient.name, patient.moduleData.phone, patient.moduleData.email, patient.moduleData.doctor, patient.moduleData.medical_record].join(" ").toLocaleLowerCase("uk");
    const matchesQuery = !query || haystack.includes(query);
    const matchesFilter = filter === "all" || (filter === "upcoming" ? Boolean(patient.nextAppointment) : !patient.nextAppointment);
    return matchesQuery && matchesFilter;
  }).sort((left, right) => left.name.localeCompare(right.name, "uk")), [data.clients, filter, now, search, appointments]);
  const upcomingCount = appointments.filter(task => task.appointmentDate?.getTime?.() >= now || new Date(String(task.due_date || "").replace(" ", "T")).getTime() >= now).filter(task => !["done", "cancelled", "archived"].includes(task.status)).length;

  return <Layout page="Пацієнти" user={data.user}>
    <main className="content dentistry-clients-page">
      <section className="dentistry-clients-hero">
        <div className="dentistry-clients-hero-copy"><span className="dentistry-kicker">СТОМАТОЛОГІЧНА КЛІНІКА</span><h1>Пацієнти</h1><p>Картки пацієнтів, медична історія та заплановані прийоми.</p></div>
        {data.permissions?.manage && <button type="button" className="primary-action" disabled={clientLimitReached} title={clientLimitReached ? planLimitHint(data.user?.subscription, "clients") : undefined} onClick={onCreate}><Icon name="plus" /> {clientLimitReached ? "Ліміт пацієнтів досягнуто" : "Додати пацієнта"}</button>}
      </section>
      {notice && <div className="team-notice success">{notice}</div>}{error && <div className="form-notice">{error}</div>}
      <section className="dentistry-stats">
        <article><span>Пацієнти</span><strong>{data.clients?.length || 0}</strong></article>
        <article><span>Майбутні прийоми</span><strong>{upcomingCount}</strong></article>
        <article><span>Записи в календарі</span><strong>{appointments.length}</strong></article>
      </section>
      <section className="dentistry-patient-panel">
        <div className="dentistry-patient-toolbar">
          <label className="dentistry-patient-search"><Icon name="search" /><input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Пошук пацієнта, телефону чи лікаря…" /></label>
          <div className="dentistry-patient-filters" aria-label="Фільтр пацієнтів">
            <button type="button" className={filter === "all" ? "active" : ""} onClick={() => setFilter("all")}>Усі</button>
            <button type="button" className={filter === "upcoming" ? "active" : ""} onClick={() => setFilter("upcoming")}>Є прийом</button>
            <button type="button" className={filter === "none" ? "active" : ""} onClick={() => setFilter("none")}>Без прийому</button>
          </div>
        </div>
        {patients.length ? <div className="dentistry-patient-grid">{patients.map((patient) => <article className="dentistry-patient-card" key={patient.id}>
          <header className="dentistry-patient-card-head"><span className="dentistry-patient-avatar">{patient.name?.trim().charAt(0).toLocaleUpperCase("uk") || "П"}</span><div><h2>{patient.name}</h2><small>{patient.moduleData.patient_type || "Пацієнт"}{patient.moduleData.medical_record ? ` · Картка ${patient.moduleData.medical_record}` : ""}</small></div><span className={`dentistry-patient-status${patient.nextAppointment ? " has-appointment" : ""}`}>{patient.nextAppointment ? "Заплановано" : "Без запису"}</span></header>
          <div className="dentistry-patient-details">
            {patient.moduleData.phone && <span><Icon name="contact" />{patient.moduleData.phone}</span>}
            {patient.moduleData.email && <span><Icon name="contact" />{patient.moduleData.email}</span>}
            {patient.moduleData.doctor && <span><Icon name="users" />{patient.moduleData.doctor}</span>}
          </div>
          {patient.nextAppointment && <div className="dentistry-next-visit"><span><Icon name="calendar" /> Найближчий прийом</span><strong>{visitDate(patient.nextAppointment.due_date)}</strong><small>{patient.nextAppointment.cabinet_id ? `Кабінет ${patient.nextAppointment.cabinet_id} · ` : ""}{Number(patient.nextAppointment.duration_minutes || 60)} хв · {patient.nextAppointment.assignee_name || "Лікаря не вказано"}</small></div>}
          {!patient.nextAppointment && <div className="dentistry-next-visit is-empty"><span><Icon name="calendar" /> Немає запланованого прийому</span><button type="button" onClick={() => navigate(`/appointments?client=${patient.id}`)}>Записати пацієнта</button></div>}
          <footer className="dentistry-patient-actions"><button type="button" className="outline-btn" onClick={() => navigate(`/patients/${patient.id}`)}><Icon name="users" /> Картка пацієнта</button>{data.permissions?.manage && <button type="button" className="outline-btn" onClick={() => onEdit(patient)}><Icon name="edit" /> Редагувати</button>}{data.permissions?.delete && <button type="button" className="dentistry-delete-patient" onClick={() => onDelete(patient)} aria-label={`Видалити пацієнта ${patient.name}`}><Icon name="close" /></button>}</footer>
        </article>)}</div> : <div className="dentistry-patient-empty"><span><Icon name="users" /></span><h2>{search ? "Пацієнтів не знайдено" : "Пацієнтів ще немає"}</h2><p>{search ? "Спробуйте змінити пошуковий запит або фільтр." : "Додайте першого пацієнта, щоб вести медичну історію, прийоми та фінанси в одному місці."}</p>{data.permissions?.manage && !search && <button type="button" className="primary-action" disabled={clientLimitReached} title={clientLimitReached ? planLimitHint(data.user?.subscription, "clients") : undefined} onClick={onCreate}><Icon name="plus" /> Додати пацієнта</button>}</div>}
      </section>
    </main>
  </Layout>;
}
