import { useMemo, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import Icon from "../components/Icon.jsx";
import Layout from "../components/Layout.jsx";
import "./dentistry-tasks.css";

const statusNames = { todo: "До виконання", in_progress: "У роботі", review: "На перевірці", done: "Завершено", cancelled: "Скасовано", archived: "Архів" };
const statusFilters = [["all", "Усі"], ["todo", "До виконання"], ["in_progress", "У роботі"], ["review", "На перевірці"], ["done", "Завершені"], ["overdue", "Прострочені"], ["cancelled", "Скасовані"], ["archived", "Архів"]];
const recordFilters = [["all", "Усі записи"], ["upcoming", "Майбутні"], ["today", "Сьогодні"], ["past", "Минулі"], ["archive", "Архів"]];
const dateTime = value => {
  if (!value) return "Час не встановлено";
  const date = new Date(String(value).replace(" ", "T"));
  return Number.isNaN(date.getTime()) ? "Час не встановлено" : date.toLocaleString("uk-UA", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
};
const taskDeadline = value => {
  if (!value) return "Без терміну";
  const raw = String(value).replace(" ", "T");
  const date = new Date(raw);
  if (Number.isNaN(date.getTime())) return "Без терміну";
  const hasTime = /[T ]\d{2}:\d{2}/.test(String(value)) && date.getHours() + date.getMinutes() > 0;
  return date.toLocaleDateString("uk-UA", hasTime ? { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" } : { day: "numeric", month: "short", year: "numeric" });
};
const overdue = task => task.due_date && !["done", "cancelled", "archived"].includes(task.status) && new Date(`${String(task.due_date).slice(0, 10)}T00:00:00`) < new Date(new Date().setHours(0, 0, 0, 0));
function pageNumbers(total, current) {
  if (total <= 5) return Array.from({ length: total }, (_, index) => index + 1);
  if (current <= 3) return [1, 2, 3, 4, "…", total];
  if (current >= total - 2) return [1, "…", total - 3, total - 2, total - 1, total];
  return [1, "…", current - 1, current, current + 1, "…", total];
}

export default function DentistryTasksPage({ data, onCreate, onEdit, modal }) {
  const navigate = useNavigate();
  const clients = data.clients || [];
  const tasks = data.tasks || [];
  const records = tasks.filter(task => Number(task.is_client_appointment) === 1);
  const workTasks = tasks.filter(task => Number(task.is_client_appointment) !== 1);
  const [recordSearch, setRecordSearch] = useState("");
  const [taskSearch, setTaskSearch] = useState("");
  const [recordFilter, setRecordFilter] = useState("all");
  const [taskFilter, setTaskFilter] = useState("all");
  const [section, setSection] = useState("appointments");

  const makeVisible = (items, query, filter, recordMode = false) => items.filter(task => {
    const client = clients.find(item => (task.client_ids || []).some(clientId => String(clientId) === String(item.id)));
    const details = client ? `${client.name || ""} ${Object.values(client.module_data || {}).join(" ")}` : "";
    const haystack = `${task.title} ${task.appointment_reason || ""} ${task.client_names || task.client_name || ""} ${task.assignee_name || ""} ${task.cabinet_id || ""} ${details}`.toLocaleLowerCase("uk");
    const matchesSearch = !query.trim() || haystack.includes(query.trim().toLocaleLowerCase("uk"));
    const due = task.due_date ? new Date(String(task.due_date).replace(" ", "T")) : null;
    const activeRecord = !["done", "cancelled", "archived"].includes(task.status);
    const today = new Date();
    const isToday = due && due.getFullYear() === today.getFullYear() && due.getMonth() === today.getMonth() && due.getDate() === today.getDate();
    const matchesFilter = recordMode
      ? filter === "all" ? activeRecord
        : filter === "upcoming" ? Boolean(due && due >= today && activeRecord && task.status !== "done")
          : filter === "today" ? Boolean(isToday && activeRecord)
            : filter === "past" ? Boolean(due && due < today && activeRecord)
              : filter === "archive" ? ["done", "cancelled", "archived"].includes(task.status)
              : true
      : filter === "all" ? task.status !== "archived" : filter === "overdue" ? overdue(task) : task.status === filter;
    return matchesSearch && matchesFilter;
  }).sort((a, b) => {
    const left = new Date(String(a.due_date || "9999").replace(" ", "T")).getTime();
    const right = new Date(String(b.due_date || "9999").replace(" ", "T")).getTime();
    if (recordMode && filter === "past") return right - left;
    if (recordMode && filter === "all") {
      const now = Date.now();
      const leftUpcoming = left >= now;
      const rightUpcoming = right >= now;
      if (leftUpcoming !== rightUpcoming) return leftUpcoming ? -1 : 1;
      return leftUpcoming ? left - right : right - left;
    }
    return left - right;
  });

  return <Layout page="Задачі та записи" user={data.user}>
    <main className="content dentistry-tasks-page">
      <header className="dentistry-tasks-hero"><div><span className="dentistry-task-kicker">СТОМАТОЛОГІЧНА КЛІНІКА</span><h1>Робота з пацієнтами</h1><p>Записи до лікаря та внутрішні задачі команди ведуться окремо.</p></div></header>
      <div className="dentistry-task-tabs" role="tablist" aria-label="Оберіть розділ"><button type="button" role="tab" aria-selected={section === "tasks"} className={section === "tasks" ? "active" : ""} onClick={() => setSection("tasks")}><span className="dentistry-task-tab-icon"><Icon name="tasks" /></span><span className="dentistry-task-tab-copy"><strong>Задачі команди</strong><small>Робочі доручення та терміни</small></span><b>{workTasks.filter(task => task.status !== "archived").length}</b></button><button type="button" role="tab" aria-selected={section === "appointments"} className={section === "appointments" ? "active" : ""} onClick={() => setSection("appointments")}><span className="dentistry-task-tab-icon appointments"><Icon name="calendar" /></span><span className="dentistry-task-tab-copy"><strong>Записи пацієнтів</strong><small>Прийоми та час у календарі</small></span><b>{records.filter(task => !["done", "cancelled", "archived"].includes(task.status)).length}</b></button></div>
      <div className="dentistry-task-sections">
        <TaskSection hidden={section !== "appointments"} type="appointments" title="Записи пацієнтів" description="Прийоми, відповідальні лікарі та час у календарі." icon="calendar" items={makeVisible(records, recordSearch, recordFilter, true)} search={recordSearch} setSearch={setRecordSearch} filter={recordFilter} setFilter={setRecordFilter} searchPlaceholder="Пошук за пацієнтом, причиною чи лікарем…" onCreate={() => navigate("/appointments")} onEditRecord={id => navigate(`/appointments/${id}/edit`)} clients={clients} />
        <TaskSection hidden={section !== "tasks"} type="tasks" title="Задачі команди" description="Робочі доручення, дедлайни та виконавці." icon="tasks" items={makeVisible(workTasks, taskSearch, taskFilter)} search={taskSearch} setSearch={setTaskSearch} filter={taskFilter} setFilter={setTaskFilter} searchPlaceholder="Пошук за назвою, пацієнтом чи виконавцем…" onCreate={onCreate} onEdit={onEdit} clients={clients} />
      </div>
    </main>
    {modal}
  </Layout>;
}

function TaskSection({ hidden = false, type, title, description, icon, items, search, setSearch, filter, setFilter, searchPlaceholder, onCreate, onEdit, onEditRecord, clients }) {
  const [page, setPage] = useState(1);
  const pageSize = 8;
  const totalPages = Math.max(1, Math.ceil(items.length / pageSize));
  const currentPage = Math.min(page, totalPages);
  const visibleItems = items.slice((currentPage - 1) * pageSize, currentPage * pageSize);
  const recordMode = type === "appointments";
  const resetPage = setter => value => { setter(value); setPage(1); };
  const findClient = task => clients.find(client => (task.client_ids || []).some(id => String(id) === String(client.id))) || null;
  return <section hidden={hidden} className={`panel dentistry-task-panel dentistry-task-section ${recordMode ? "records-section" : "work-section"}`}>
    <header className="dentistry-task-section-heading"><div className={`dentistry-task-section-icon ${recordMode ? "records" : "work"}`}><Icon name={icon} /></div><div className="dentistry-task-section-title"><p>{recordMode ? "КАЛЕНДАР ПАЦІЄНТІВ" : "РОБОТА КОМАНДИ"}</p><h2>{title}</h2><small>{description}</small></div><div className="dentistry-task-section-total"><strong>{items.length}</strong><span>{recordMode ? "записів" : "задач"}</span></div><button className={`dentistry-section-create ${recordMode ? "records" : "work"}`} type="button" onClick={onCreate}><Icon name="plus" />{recordMode ? "Новий запис" : "Створити задачу"}</button></header>
    <div className="dentistry-task-toolbar"><label><Icon name="search" /><input value={search} onChange={event => resetPage(setSearch)(event.target.value)} placeholder={searchPlaceholder} /></label><select value={filter} onChange={event => resetPage(setFilter)(event.target.value)}>{(recordMode ? recordFilters : statusFilters).map(([value, label]) => <option key={value} value={value}>{!recordMode && value === "all" ? "Усі задачі" : label}</option>)}</select></div>
    {visibleItems.length ? recordMode ? <div className="dentistry-task-list">{visibleItems.map(task => <AppointmentTaskCard key={task.id} task={task} client={findClient(task)} onEdit={() => onEditRecord(task.id)} />)}</div> : <div className="dentistry-work-table-wrap"><div className="dentistry-work-table-head"><span>Задача</span><span>Пацієнт</span><span>Термін</span><span>Виконавець</span><span>Статус</span><span /></div>{visibleItems.map(task => <WorkTaskRow key={task.id} task={task} client={findClient(task)} onEdit={() => onEdit(task)} />)}</div> : <div className="dentistry-task-empty"><Icon name={icon} /><h3>{search ? "Нічого не знайдено" : recordMode ? "Записів поки немає" : "Задач поки немає"}</h3><p>{search ? "Змініть пошук або фільтр." : recordMode ? "Створіть запис пацієнта, щоб він з’явився тут." : "Створіть задачу для команди, щоб вона з’явилася тут."}</p></div>}
    {items.length > 0 && <footer className="dentistry-task-pagination"><span>Показано {(currentPage - 1) * pageSize + 1}–{Math.min(currentPage * pageSize, items.length)} із {items.length}</span><div className="dentistry-pagination-pages"><button type="button" disabled={currentPage <= 1} onClick={() => setPage(currentPage - 1)} aria-label="Попередня сторінка"><Icon name="left" /></button>{pageNumbers(totalPages, currentPage).map((pageNumber, index) => pageNumber === "…" ? <span className="dentistry-pagination-ellipsis" key={`ellipsis-${index}`}>…</span> : <button type="button" key={pageNumber} className={pageNumber === currentPage ? "active" : ""} aria-current={pageNumber === currentPage ? "page" : undefined} onClick={() => setPage(pageNumber)}>{pageNumber}</button>)}<button type="button" disabled={currentPage >= totalPages} onClick={() => setPage(currentPage + 1)} aria-label="Наступна сторінка"><Icon name="right" /></button></div></footer>}
  </section>;
}

function AppointmentTaskCard({ task, client, onEdit }) {
  const moduleData = client?.module_data || {};
  return <article className="dentistry-task-card dentistry-appointment-card" key={task.id}>
    <header className="dentistry-appointment-card-head"><Link className="dentistry-task-type appointment" to={`/tasks/${task.id}`}><Icon name="calendar" /></Link><Link className="dentistry-appointment-card-title" to={`/tasks/${task.id}`}><span><strong>Прийом пацієнта</strong></span><small>{client?.name || task.client_names || task.client_name || "Пацієнта не вказано"}{moduleData.patient_type ? ` · ${moduleData.patient_type}` : ""}{moduleData.medical_record ? ` · картка ${moduleData.medical_record}` : ""}</small></Link><button className="dentistry-task-edit" type="button" onClick={onEdit} aria-label="Редагувати запис"><Icon name="edit" /></button></header>
    <div className="dentistry-appointment-facts"><div><small>Дата й час</small><strong>{dateTime(task.due_date)}</strong></div><div><small>Тривалість</small><strong>{Number(task.duration_minutes || 60)} хв</strong></div><div><small>Відповідальний лікар</small><strong>{task.assignee_name || "Не призначено"}</strong></div>{task.cabinet_id && <div><small>Кабінет</small><strong>{task.cabinet_id}</strong></div>}</div>
    {(task.appointment_reason || task.appointment_comment || moduleData.phone || moduleData.email) && <div className="dentistry-appointment-extras">{(moduleData.phone || moduleData.email) && <div className="dentistry-appointment-contacts"><span><Icon name="contact" />{[moduleData.phone, moduleData.email].filter(Boolean).join(" · ")}</span></div>}{task.appointment_reason && <div className="dentistry-appointment-services"><small>Причина прийому</small><div><span>{task.appointment_reason}</span></div></div>}{task.appointment_comment && <div className="dentistry-client-next-appointment">{task.appointment_comment}</div>}</div>}
  </article>;
}

function WorkTaskRow({ task, client, onEdit }) {
  const moduleData = client?.module_data || {};
  return <div className="dentistry-work-row"><Link className="dentistry-work-task-cell" to={`/tasks/${task.id}`}><span className="dentistry-work-task-icon"><Icon name="tasks" /></span><span><strong>{task.title}</strong><small>{task.description ? task.description.replace(/<[^>]*>/g, " ").replace(/\s+/g, " ").trim().slice(0, 90) : "Без опису"}</small></span></Link><Link className="dentistry-work-patient-cell" to={client ? `/clients/${client.id}/edit` : `/tasks/${task.id}`}><strong>{client?.name || task.client_names || task.client_name || "Без пацієнта"}</strong>{moduleData.medical_record && <small>Картка {moduleData.medical_record}</small>}</Link><span className="dentistry-work-due-cell">{taskDeadline(task.due_date)}</span><span className="dentistry-work-assignee-cell">{task.assignee_name || "Не призначено"}</span><i className={`dentistry-task-status ${task.status}`}>{statusNames[task.status] || statusNames.todo}</i><button className="dentistry-task-edit" type="button" onClick={onEdit} aria-label="Редагувати задачу"><Icon name="edit" /></button></div>;
}
