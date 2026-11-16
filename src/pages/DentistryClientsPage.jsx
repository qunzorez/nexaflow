import { useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import Layout from "../components/Layout.jsx";
import Icon from "../components/Icon.jsx";
import { isPlanLimitReached, planLimitHint } from "../utils/planLimits.js";
import "./dentistry-clients.css";

const money = (value) => `${Number(value || 0).toLocaleString("uk-UA", { maximumFractionDigits: 2 })} грн`;
const visitDate = (value) => {
  const date = new Date(String(value || "").replace(" ", "T"));
  if (Number.isNaN(date.getTime())) return "";
  return date.toLocaleString("uk-UA", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
};

export default function DentistryClientsPage({ data, onCreate, onEdit, onDelete, notice, error }) {
  const navigate = useNavigate();
  const [search, setSearch] = useState("");
  const [filter, setFilter] = useState("all");
  const now = Date.now();
  const clientLimitReached = isPlanLimitReached(data.user?.subscription, "clients");
  const patients = useMemo(() => (data.clients || []).map((client) => {
    const moduleData = client.module_data || {};
    const appointments = (client.services || []).filter((service) => service.appointment_task_id && service.add_to_calendar && service.appointment_at)
      .map((service) => ({ ...service, appointmentDate: new Date(String(service.appointment_at).replace(" ", "T")) }))
      .filter((service) => !Number.isNaN(service.appointmentDate.getTime()))
      .sort((left, right) => left.appointmentDate - right.appointmentDate);
    return { ...client, moduleData, appointments, nextAppointment: appointments.find((service) => service.appointmentDate.getTime() >= now) || null };
  }).filter((patient) => {
    const query = search.trim().toLocaleLowerCase("uk");
    const haystack = [patient.name, patient.moduleData.phone, patient.moduleData.email, patient.moduleData.doctor, patient.moduleData.medical_record, ...(patient.services || []).map((service) => service.name)].join(" ").toLocaleLowerCase("uk");
    const matchesQuery = !query || haystack.includes(query);
    const matchesFilter = filter === "all" || (filter === "upcoming" ? Boolean(patient.nextAppointment) : !patient.nextAppointment);
    return matchesQuery && matchesFilter;
  }).sort((left, right) => left.name.localeCompare(right.name, "uk")), [data.clients, filter, now, search]);
  const upcomingCount = (data.clients || []).reduce((total, client) => total + (client.services || []).filter((service) => service.appointment_task_id && service.add_to_calendar && service.appointment_at && new Date(String(service.appointment_at).replace(" ", "T")).getTime() >= now).length, 0);
  const serviceCount = (data.clients || []).reduce((total, client) => total + (client.services || []).length, 0);

  return <Layout page="Пацієнти" user={data.user}>
    <main className="content dentistry-clients-page">
      <section className="dentistry-clients-hero">
        <div className="dentistry-clients-hero-copy"><span className="dentistry-kicker">СТОМАТОЛОГІЧНА КЛІНІКА</span><h1>Пацієнти</h1><p>Картки пацієнтів, стоматологічні послуги та найближчі прийоми.</p></div>
        {data.permissions?.manage && <button type="button" className="primary-action" disabled={clientLimitReached} title={clientLimitReached ? planLimitHint(data.user?.subscription, "clients") : undefined} onClick={onCreate}><Icon name="plus" /> {clientLimitReached ? "Ліміт пацієнтів досягнуто" : "Додати пацієнта"}</button>}
      </section>
      {notice && <div className="team-notice success">{notice}</div>}{error && <div className="form-notice">{error}</div>}
      <section className="dentistry-stats">
        <article><span>Пацієнти</span><strong>{data.clients?.length || 0}</strong></article>
        <article><span>Майбутні прийоми</span><strong>{upcomingCount}</strong></article>
        <article><span>Послуги в картках</span><strong>{serviceCount}</strong></article>
      </section>
      <section className="dentistry-patient-panel">
        <div className="dentistry-patient-toolbar">
          <label className="dentistry-patient-search"><Icon name="search" /><input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Пошук пацієнта, телефону, лікаря чи послуги…" /></label>
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
          {patient.nextAppointment && <div className="dentistry-next-visit"><span><Icon name="calendar" /> Найближчий прийом</span><strong>{visitDate(patient.nextAppointment.appointment_at)}</strong><small>{patient.nextAppointment.name} · {Number(patient.nextAppointment.appointment_duration_minutes || 60)} хв</small></div>}
          <div className="dentistry-patient-services"><div className="dentistry-section-label">Послуги <b>{patient.services?.length || 0}</b></div>{patient.services?.length ? <div className="dentistry-service-list">{patient.services.slice(0, 3).map((service, index) => <div className="dentistry-service-item" key={`${service.name}-${index}`}><span><strong>{service.name}</strong>{service.tooth_numbers?.length > 0 && <small>Зуби: {service.tooth_numbers.join(", ")}</small>}{service.appointment_task_id && service.appointment_at && <small>Прийом: {visitDate(service.appointment_at)}</small>}</span><b>{money(service.price)}</b></div>)}{patient.services.length > 3 && <small className="dentistry-more-services">Ще {patient.services.length - 3} послуг</small>}</div> : <p className="dentistry-no-services">Послуги ще не додані.</p>}</div>
          <footer className="dentistry-patient-actions"><button type="button" className="outline-btn" onClick={() => navigate(`/patients/${patient.id}`)}><Icon name="users" /> Картка пацієнта</button>{data.permissions?.manage && <button type="button" className="outline-btn" onClick={() => onEdit(patient)}><Icon name="edit" /> Редагувати</button>}{data.permissions?.delete && <button type="button" className="dentistry-delete-patient" onClick={() => onDelete(patient)} aria-label={`Видалити пацієнта ${patient.name}`}><Icon name="close" /></button>}</footer>
        </article>)}</div> : <div className="dentistry-patient-empty"><span><Icon name="users" /></span><h2>{search ? "Пацієнтів не знайдено" : "Пацієнтів ще немає"}</h2><p>{search ? "Спробуйте змінити пошуковий запит або фільтр." : "Додайте першого пацієнта, щоб вести його послуги та прийоми в одному місці."}</p>{data.permissions?.manage && !search && <button type="button" className="primary-action" disabled={clientLimitReached} title={clientLimitReached ? planLimitHint(data.user?.subscription, "clients") : undefined} onClick={onCreate}><Icon name="plus" /> Додати пацієнта</button>}</div>}
      </section>
    </main>
  </Layout>;
}
