import Icon from '../components/Icon.jsx';
import { useEffect, useState } from "react";
import { createPortal } from "react-dom";
import { Link, useNavigate, useParams } from "react-router-dom";
import DOMPurify from "dompurify";
import Layout from "../components/Layout.jsx";
import TaskFormModal from "../modules/tasks/TaskFormModal.jsx";
import TimeLogModal from "../modules/tasks/TimeLogModal.jsx";
import TaskStatusDropdown from "../modules/tasks/TaskStatusDropdown.jsx";
import { api, post } from "../services/api.js";
import "./task-detail.css";
import "./task-detail-extra.css";
import "./task-review-status.css";
import "../modules/tasks/task-modal.css";
import "../modules/tasks/time-log.css";
import "../modules/tasks/task-status-dropdown.css";
import "../modules/tasks/task-priority.css";
import "./task-completion-prompt.css";

const statusLabels = {
  todo: "До виконання",
  in_progress: "У роботі",
  review: "На перевірці",
  done: "Завершено",
  cancelled: "Скасовано",
  archived: "Архів",
};
const formatMinutes = (value) => {
  const minutes = Number(value || 0);
  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;
  return hours ? `${hours} год${rest ? ` ${rest} хв` : ""}` : `${rest} хв`;
};
function descriptionInfo(value) {
  const holder = document.createElement("div");
  holder.innerHTML = String(value || "");
  return {
    text: (holder.textContent || "").replace(/\s+/g, " ").trim(),
    images: holder.querySelectorAll("img").length,
  };
}
function descriptionChangeMessage(oldValue, newValue) {
  const oldInfo = descriptionInfo(oldValue);
  const newInfo = descriptionInfo(newValue);
  const parts = [];
  if (!oldInfo.text && newInfo.text)
    parts.push(
      `додано текст опису «${newInfo.text.slice(0, 140)}${newInfo.text.length > 140 ? "…" : ""}»`,
    );
  else if (oldInfo.text && !newInfo.text) parts.push("видалено текст опису");
  else if (oldInfo.text !== newInfo.text)
    parts.push(
      `змінено текст опису на «${newInfo.text.slice(0, 140)}${newInfo.text.length > 140 ? "…" : ""}»`,
    );
  if (!oldInfo.images && newInfo.images)
    parts.push(`додано ${newInfo.images} зображення`);
  else if (oldInfo.images && !newInfo.images) parts.push("видалено зображення");
  else if (oldInfo.images !== newInfo.images)
    parts.push(
      `змінено кількість зображень: ${oldInfo.images} → ${newInfo.images}`,
    );
  return parts.length ? `${parts.join(" та ")}.` : "";
}

export default function TaskDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [data, setData] = useState(null);
  const [listData, setListData] = useState(null);
  const [modal, setModal] = useState(false);
  const [timeModal, setTimeModal] = useState(false);
  const [archivePrompt, setArchivePrompt] = useState(false);
  const [notice, setNotice] = useState("");
  const [error, setError] = useState("");
  const [projectIds, setProjectIds] = useState([]);
  const [assigneeIds, setAssigneeIds] = useState([]);
  const [clientIds, setClientIds] = useState([]);
  async function load() {
    const [detail, list, clients] = await Promise.all([
      api(`/api/tasks.php?id=${encodeURIComponent(id)}`),
      api("/api/tasks.php"),
      api("/api/clients.php"),
    ]);
    if (!detail.ok) return navigate("/tasks");
    setData(detail);
    setListData({ ...list, clients: clients.ok ? clients.clients || [] : [], active_client_module: clients.ok ? clients.active_client_module : "accounting" });
  }
  useEffect(() => {
    load();
    const interval = window.setInterval(load, 15000);
    const refreshOnFocus = () => load();
    window.addEventListener("focus", refreshOnFocus);
    return () => {
      window.clearInterval(interval);
      window.removeEventListener("focus", refreshOnFocus);
    };
  }, [id]);
  useEffect(() => {
    if (!data?.task || listData?.active_client_module !== "dentistry") return;
    if (Number(data.task.is_client_appointment) === 1) navigate(`/appointments/${data.task.id}`, { replace: true });
  }, [data, listData, navigate]);
  function edit() {
    setProjectIds(data.task.project_ids || []);
    setAssigneeIds(data.task.assignee_ids || []);
    setClientIds(data.task.client_ids || []);
    setModal(true);
  }
  async function save(event) {
    event.preventDefault();
    setError("");
    const form = new FormData(event.currentTarget);
    const payload = Object.fromEntries(form.entries());
    payload.action = "update";
    payload.id = id;
    payload.project_ids = projectIds;
    payload.assignee_ids = assigneeIds;
    payload.client_ids = clientIds;
    const result = await post("/api/tasks.php", payload);
    if (!result.ok) return setError(result.message);
    setNotice(result.message);
    setModal(false);
    load();
  }
  async function changeStatus(status) {
    setError("");
    const result = await post("/api/tasks.php", {
      action: "set_status",
      id,
      status,
    });
    if (!result.ok) return setError(result.message);
    setNotice(result.message);
    load();
    if (status === "done" && ["admin", "owner", "manager", "project_lead"].includes(data?.user?.workspace_role || data?.user?.role)) setArchivePrompt(true);
  }
  async function archiveCompletedTask() {
    setError("");
    const result = await post("/api/tasks.php", { action: "set_status", id, status: "archived" });
    if (!result.ok) return setError(result.message);
    setArchivePrompt(false);
    setNotice("Задачу переміщено до архіву.");
    load();
  }
  async function deleteTask() {
    if (!window.confirm("Видалити задачу назавжди? Її історію та пов’язані записи буде видалено.")) return;
    setError("");
    const result = await post("/api/tasks.php", { action: "delete", id });
    if (!result.ok) return setError(result.message || "Не вдалося видалити задачу.");
    navigate("/tasks", { replace: true, state: { notice: result.message } });
  }
  async function logTime(event) {
    event.preventDefault();
    setError("");
    const form = new FormData(event.currentTarget);
    const minutes =
      Number(form.get("hours") || 0) * 60 + Number(form.get("minutes") || 0);
    const result = await post("/api/tasks.php", {
      action: "log_time",
      task_id: id,
      minutes,
      note: form.get("note"),
    });
    if (!result.ok) return setError(result.message);
    setNotice(result.message);
    setTimeModal(false);
    load();
  }
  if (!data || !listData)
    return <div className="loading-screen">Завантаження задачі…</div>;
  const task = data.task;
  const isDentistry = listData.active_client_module === "dentistry";
  const isAppointment = Number(task.is_client_appointment) === 1 || (isDentistry && /^Запис клієнта\b/i.test(String(task.title || "")));
  if (isDentistry && isAppointment) return <div className="loading-screen">Відкриваємо запис…</div>;
  const canManage = ["admin", "owner", "manager", "project_lead"].includes(data.user?.workspace_role || data.user?.role);
  const priorityInfo = {
    low: ["Низький", <Icon name="low" />],
    normal: ["Звичайний", <Icon name="normal" />],
    high: ["Високий", <Icon name="high" />],
    urgent: ["Терміновий", <Icon name="urgent" />],
  }[task.priority] || ["Звичайний", <Icon name="normal" />];
  const descriptionHtml = task.description
    ? /<[a-z][\s\S]*>/i.test(task.description)
      ? task.description
      : `<p>${String(task.description)
          .replace(
            /[&<>'"]/g,
            (character) =>
              ({
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                "'": "&#039;",
                '"': "&quot;",
              })[character],
          )
          .replace(/\n/g, "<br />")}</p>`
    : "<p>Опис задачі ще не додано.</p>";
  return (
    <Layout page="Деталі задачі" user={data.user}>
      <div className="content task-detail-page">
        <button
          className="page-back"
          type="button"
          onClick={() => navigate("/tasks")}
        >
          <Icon name="back" /> Назад до задач
        </button>
        {notice && <div className="team-notice success">{notice}</div>}
        {error && <div className="form-notice">{error}</div>}
        <section className="task-detail-header">
          <div>
            <p className="eyebrow">ЗАДАЧА</p>
            <h1>{task.title}</h1>
            <div className="detail-badges">
              <TaskStatusDropdown
                task={task}
                canManage={canManage}
                onChange={changeStatus}
              />
              <span
                className={`detail-priority detail-priority-${task.priority}`}
              >
                <span className="detail-priority-icon">{priorityInfo[1]}</span>
                {priorityInfo[0]}
              </span>
            </div>
          </div>
          <div className="task-detail-actions">
            <button
              className="outline-btn"
              type="button"
              onClick={() => setTimeModal(true)}
            >
              <Icon name="plus" /> Лог часу
            </button>
            <button className="primary-action" type="button" onClick={edit}>
              Редагувати задачу
            </button>
            {canManage && !isAppointment && <button className="outline-btn task-delete-action" type="button" onClick={deleteTask}><Icon name="trash" /> Видалити</button>}
          </div>
        </section>
        <div className="task-detail-grid">
          <main>
            <section className="panel task-description-panel">
              <h2>Опис задачі</h2>
              <div
                className="detail-description"
                dangerouslySetInnerHTML={{
                  __html: DOMPurify.sanitize(descriptionHtml, {
                    USE_PROFILES: { html: true },
                  }),
                }}
              />
              <div className="detail-info-grid">
                <Info
                  label="Клієнти"
                  value={task.client_names || task.client_name || "Не вказано"}
                />
                <Info
                  label="Проєкти"
                  value={task.project_name || "Без проєкту"}
                />
                <Info
                  label="Виконавці"
                  value={task.assignee_name || "Не призначено"}
                />
                <Info
                  label="Дедлайн"
                  value={
                    task.due_date
                      ? new Date(
                          `${String(task.due_date).slice(0, 10)}T00:00:00`,
                        ).toLocaleDateString("uk-UA")
                      : "Не встановлено"
                  }
                />
                <Info
                  label="Нагадування"
                  value={task.reminder_at || "Не встановлено"}
                />
              </div>
            </section>
            <section className="panel time-panel">
              <div className="panel-heading">
                <div>
                  <h2>Лог часу</h2>
                  <p>Час, який користувачі додали до цієї задачі.</p>
                </div>
              </div>
              <div className="time-total">
                Всього: {formatMinutes(data.total_minutes)}
              </div>
              <div className="time-entry-list">
                {data.time_entries?.length ? (
                  data.time_entries.map((item) => (
                    <div className="time-entry-row" key={item.id}>
                      <div>
                        <strong>{item.user_name}</strong>
                        <small>
                          {item.note || "Без примітки"} ·{" "}
                          {new Date(
                            String(item.logged_at).replace(" ", "T"),
                          ).toLocaleString("uk-UA")}
                        </small>
                      </div>
                      <b>{formatMinutes(item.minutes)}</b>
                    </div>
                  ))
                ) : (
                  <p className="muted-note">Час ще не додано.</p>
                )}
              </div>
            </section>
          </main>
          <aside>
            <section className="panel history-panel">
              <div className="panel-heading">
                <div>
                  <h2>Історія змін</h2>
                  <p>Хто і що змінював у задачі.</p>
                </div>
              </div>
              <div className="history-list">
                {data.history.length ? (
                  data.history.map((item) => (
                    <div className="history-item" key={item.id}>
                      <span className="history-dot" />
                      <div>
                        <strong>
                          {item.user_name || "Система"}:{" "}
                          {String(item.message).replace(/^Ви:\s*/, "")}
                        </strong>
                        <small>
                          {new Date(
                            String(item.created_at).replace(" ", "T"),
                          ).toLocaleString("uk-UA")}
                        </small>
                      </div>
                    </div>
                  ))
                ) : (
                  <p className="muted-note">Історія поки порожня.</p>
                )}
              </div>
            </section>
          </aside>
        </div>
        {modal && (
          <TaskFormModal
            data={listData}
            editing={task}
            projectIds={projectIds}
            assigneeIds={assigneeIds}
            clientIds={clientIds}
            setProjectIds={setProjectIds}
            setAssigneeIds={setAssigneeIds}
            setClientIds={setClientIds}
            onSubmit={save}
            onClose={() => setModal(false)}
          />
        )}
        {timeModal && (
          <TimeLogModal
            onSubmit={logTime}
            onClose={() => setTimeModal(false)}
          />
        )}
        {archivePrompt && createPortal(
          <div className="completion-prompt-backdrop" role="presentation" onMouseDown={event => { if (event.target === event.currentTarget) setArchivePrompt(false); }}>
            <section className="completion-prompt" role="dialog" aria-modal="true" aria-labelledby="completion-prompt-title">
              <button className="completion-prompt-close" type="button" onClick={() => setArchivePrompt(false)} aria-label="Закрити"><Icon name="close" /></button>
              <div className="completion-prompt-icon"><Icon name="check" /></div>
              <p className="eyebrow">ЗАДАЧУ ЗАВЕРШЕНО</p>
              <h2 id="completion-prompt-title">Перенести задачу в архів?</h2>
              <p>Завершена задача більше не відображатиметься серед активних. Її можна буде знайти в архіві.</p>
              <div className="completion-prompt-actions">
                <button className="outline-btn" type="button" onClick={() => setArchivePrompt(false)}>Залишити завершеною</button>
                <button className="primary-action" type="button" onClick={archiveCompletedTask}>Перенести в архів <Icon name="archive" /></button>
              </div>
            </section>
          </div>,
          document.body,
        )}
      </div>
    </Layout>
  );
}
function Info({ label, value }) {
  return (
    <div className="detail-info">
      <small>{label}</small>
      <strong>{value}</strong>
    </div>
  );
}

function DentistryTaskOverview({ task, client, isAppointment }) {
  const patient = client?.module_data || {};
  const scheduledAt = task.due_date ? new Date(String(task.due_date).replace(" ", "T")) : null;
  const patientFacts = [
    ["Телефон", patient.phone],
    ["Email", patient.email],
    ["Дата народження", patient.birth_date],
    ["Тип пацієнта", patient.patient_type],
    ["Медична картка", patient.medical_record],
    ["Лікар", patient.doctor],
  ].filter(([, value]) => value);
  return <>
    <section className="panel dentistry-task-detail-patient">
      <div className="dentistry-task-detail-heading"><span className="dentistry-task-detail-avatar"><Icon name="user" /></span><div><p>КАРТКА ПАЦІЄНТА</p><h2>{client?.name || task.client_names || task.client_name || "Пацієнта не вказано"}</h2></div>{client && <Link to={`/patients/${client.id}`} className="outline-btn"><Icon name="users" /> Відкрити картку пацієнта</Link>}</div>
      {patientFacts.length ? <div className="dentistry-task-detail-facts">{patientFacts.map(([label, value]) => <Info key={label} label={label} value={value} />)}</div> : <p className="muted-note">Додаткові дані пацієнта ще не заповнені.</p>}
    </section>
    {isAppointment && <section className="panel dentistry-task-detail-visit"><div className="panel-heading"><div><h2>Деталі прийому</h2><p>Запис пацієнта до лікаря. Послуги фіксуються в картці після огляду.</p></div><span className="dentistry-visit-icon"><Icon name="calendar" /></span></div><div className="dentistry-task-detail-facts"><Info label="Дата та час" value={scheduledAt && !Number.isNaN(scheduledAt.getTime()) ? scheduledAt.toLocaleString("uk-UA", { day: "numeric", month: "long", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "Не заплановано"} /><Info label="Тривалість" value={formatMinutes(task.duration_minutes)} /><Info label="Відповідальний лікар" value={task.assignee_name || "Не призначено"} /><Info label="Кабінет" value={task.cabinet_id || "Не вказано"} />{task.appointment_reason && <Info label="Причина прийому" value={task.appointment_reason} />}</div>{task.appointment_comment && <div className="dentistry-task-detail-description">{task.appointment_comment}</div>}{!task.appointment_reason && task.description && <div className="dentistry-task-detail-description" dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(task.description, { USE_PROFILES: { html: true } }) }} />}{client && <div className="dentistry-task-detail-actions"><Link className="primary-action" to={`/patients/${client.id}`}><Icon name="users" /> Медична картка · послуги · рахунки</Link></div>}</section>}
  </>;
}
