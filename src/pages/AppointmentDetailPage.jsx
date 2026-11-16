import { useEffect, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import Icon from "../components/Icon.jsx";
import Layout from "../components/Layout.jsx";
import { api, post } from "../services/api.js";
import "./appointments.css";

const durationLabel = value => {
  const minutes = Number(value || 60);
  return minutes < 60 ? `${minutes} хв` : `${Math.floor(minutes / 60)} год${minutes % 60 ? ` ${minutes % 60} хв` : ""}`;
};

export default function AppointmentDetailPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [data, setData] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  async function load() {
    const [detail, clients] = await Promise.all([
      api(`/api/tasks.php?id=${encodeURIComponent(id)}`),
      api("/api/clients.php"),
    ]);
    if (!detail.ok || Number(detail.task?.is_client_appointment) !== 1 || !clients.ok || clients.active_client_module !== "dentistry") {
      return navigate("/appointments", { replace: true });
    }
    const task = detail.task;
    const clientId = (task.client_ids || [])[0];
    const patient = (clients.clients || []).find(item => String(item.id) === String(clientId));
    setData({ ...detail, clients: clients.clients || [], patient });
  }

  useEffect(() => { load(); }, [id]);

  async function changeStatus(appointmentStatus) {
    setBusy(true);
    setError("");
    const result = await post("/api/tasks.php", { action: "set_appointment_status", id, appointment_status: appointmentStatus });
    setBusy(false);
    if (!result.ok) return setError(result.message || "Не вдалося змінити статус прийому.");
    setNotice(result.message || "Статус прийому змінено.");
    await load();
  }

  if (!data) return <div className="loading-screen">Завантаження прийому…</div>;
  const task = data.task;
  const patient = data.patient;
  const date = task.due_date ? new Date(String(task.due_date).replace(" ", "T")) : null;
  const appointmentStatus = task.appointment_status || ({ archived: "archived", done: "completed", cancelled: "cancelled", in_progress: "arrived" }[task.status] || "scheduled");
  const statusLabels = { scheduled: "Заплановано", confirmed: "Підтверджено", arrived: "Пацієнт прибув", completed: "Завершено", archived: "В архіві", cancelled: "Скасовано" };

  return <Layout page="Деталі прийому" user={data.user}>
    <div className="content appointments-page appointment-detail-page">
      {patient ? <Link className="page-back" to={`/patients/${patient.id}?tab=appointments`}><Icon name="back" /> До всіх записів</Link> : <button className="page-back" type="button" onClick={() => navigate("/appointments")}><Icon name="back" /> До всіх записів</button>}
      {notice && <div className="appointment-alert success" role="status">{notice}</div>}
      {error && <div className="appointment-alert error" role="alert">{error}</div>}
      <section className="welcome-row appointments-hero">
        <div><p className="eyebrow">ПРИЙОМ ПАЦІЄНТА</p><h1>{patient?.name || task.client_names || "Запис клієнта"}</h1><p className="subtext">Деталі прийому та пацієнта.</p></div>
        <div className="appointment-detail-actions">
          <label className="appointment-status-control">Статус прийому<select value={appointmentStatus} disabled={busy} onChange={event => changeStatus(event.target.value)}>{Object.entries(statusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
          {!['completed', 'archived', 'cancelled'].includes(appointmentStatus) && <Link className="outline-btn" to={`/appointments/${id}/edit`}><Icon name="edit" /> Редагувати запис</Link>}
        </div>
      </section>
      <div className="appointment-detail-grid">
        <section className="panel appointment-create-panel">
          <div className="panel-heading"><div><h2>Деталі прийому</h2><p>Дата, тривалість і відповідальний лікар.</p></div><span className="appointment-heading-icon"><Icon name="calendar" /></span></div>
          <div className="appointment-detail-facts">
            <div><small>Дата та час</small><strong>{date && !Number.isNaN(date.getTime()) ? date.toLocaleString("uk-UA", { day: "numeric", month: "long", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "Не заплановано"}</strong></div>
            <div><small>Тривалість</small><strong>{durationLabel(task.duration_minutes)}</strong></div>
            <div><small>Лікар</small><strong>{task.assignee_names || task.assignee_name || "Не призначено"}</strong></div>
            <div><small>Кабінет</small><strong>{task.cabinet_id || "Не вказано"}</strong></div>
            <div><small>Статус</small><strong>{statusLabels[appointmentStatus] || appointmentStatus}</strong></div>
            <div><small>Причина прийому</small><strong>{task.appointment_reason || "Не вказана"}</strong></div>
          </div>
          {task.appointment_comment && <p className="appointment-detail-comment">{task.appointment_comment}</p>}
        </section>
        <section className="panel appointment-create-panel">
          <div className="panel-heading"><div><h2>Пацієнт</h2><p>Контактна інформація та медична картка.</p></div><span className="appointment-heading-icon"><Icon name="user" /></span></div>
          <div className="appointment-patient-card"><strong>{patient?.name || task.client_names || task.client_name || "Пацієнт"}</strong><span>{patient?.module_data?.phone || "Телефон не вказаний"}</span><span>{patient?.module_data?.email || "Email не вказаний"}</span></div>
          {patient && <Link className="primary-button appointment-patient-link" to={`/patients/${patient.id}`}><Icon name="user" /> Відкрити картку пацієнта <Icon name="forward" /></Link>}
        </section>
      </div>
    </div>
  </Layout>;
}
