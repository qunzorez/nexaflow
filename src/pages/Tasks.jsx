import Icon from '../components/Icon.jsx';
import { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { useNavigate, useSearchParams } from "react-router-dom";
import Layout from "../components/Layout.jsx";
import TaskFormModal from "../modules/tasks/TaskFormModal.jsx";
import { api, post } from "../services/api.js";
import "./tasks.css";
import "./tasks-multiselect.css";
import "./tasks-page-extra.css";
import "./tasks-status.css";
import "./tasks-table.css";
import "./tasks-table-layout.css";
import "./tasks-fixed-table.css";
import "./tasks-clients.css";
import "./tasks-dropdown-fix.css";
import "./tasks-status-centering.css";
import "./task-actions.css";
import "../modules/tasks/task-modal.css";
import "./task-completion-prompt.css";

const statusOptions = {
  todo: { label: "До виконання", icon: <Icon name="todo" /> },
  in_progress: { label: "У роботі", icon: <Icon name="progress" /> },
  review: { label: "На перевірці", icon: <Icon name="urgent" /> },
  done: { label: "Завершено", icon: <Icon name="check" /> },
  cancelled: { label: "Скасовано", icon: <Icon name="close" /> },
  archived: { label: "Архів", icon: <Icon name="archive" /> },
};
const priorityLabels = {
  low: "Низький",
  normal: "Звичайний",
  high: "Високий",
  urgent: "Терміновий",
};
const priorityRank = { urgent: 0, high: 1, normal: 2, low: 3 };
const pageSize = 10;
const nonOverdueStatuses = new Set(["done", "cancelled", "archived"]);
function isOverdueTask(task) {
  if (!task.due_date || nonOverdueStatuses.has(task.status)) return false;
  const dueDate = new Date(`${task.due_date}T00:00:00`);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return !Number.isNaN(dueDate.getTime()) && dueDate < today;
}
function taskSort(a, b) {
  const priorityDifference =
    (priorityRank[a.priority] ?? 9) - (priorityRank[b.priority] ?? 9);
  if (priorityDifference) return priorityDifference;
  const dueA = a.due_date
    ? new Date(`${a.due_date}T00:00:00`).getTime()
    : Number.POSITIVE_INFINITY;
  const dueB = b.due_date
    ? new Date(`${b.due_date}T00:00:00`).getTime()
    : Number.POSITIVE_INFINITY;
  if (dueA !== dueB) return dueA - dueB;
  return String(b.created_at || "").localeCompare(String(a.created_at || ""));
}
const descriptionPreview = (value) => {
  const holder = document.createElement("div");
  holder.innerHTML = String(value || "");
  holder
    .querySelectorAll("img")
    .forEach((image) => image.replaceWith("Зображення"));
  return (holder.textContent || "").replace(/\s+/g, " ").trim().slice(0, 140);
};

function StatusMenu({ task, onChange }) {
  const [open, setOpen] = useState(false);
  const [menuStyle, setMenuStyle] = useState({});
  const rootRef = useRef(null);
  const triggerRef = useRef(null);
  const optionsRef = useRef(null);
  const current = statusOptions[task.status] || statusOptions.todo;
  const canManage = task.canManage !== false;
  const visibleOptions = Object.entries(statusOptions).filter(
    ([value]) =>
      canManage || !["review", "cancelled", "archived"].includes(value),
  );
  useEffect(() => {
    const close = (event) => {
      if (rootRef.current?.contains(event.target) || optionsRef.current?.contains(event.target)) return;
      setOpen(false);
    };
    document.addEventListener("mousedown", close);
    return () => document.removeEventListener("mousedown", close);
  }, []);
  useEffect(() => {
    if (!open || !triggerRef.current) return undefined;
    const updatePosition = () => {
      const rect = triggerRef.current.getBoundingClientRect();
      const width = 170;
      const height = visibleOptions.length * 38 + 12;
      const left = Math.min(Math.max(8, rect.right - width), window.innerWidth - width - 8);
      const below = rect.bottom + 7;
      const top = below + height <= window.innerHeight - 8 ? below : Math.max(8, rect.top - height - 7);
      setMenuStyle({ left, top, width });
    };
    updatePosition();
    window.addEventListener("resize", updatePosition);
    window.addEventListener("scroll", updatePosition, true);
    return () => {
      window.removeEventListener("resize", updatePosition);
      window.removeEventListener("scroll", updatePosition, true);
    };
  }, [open, visibleOptions.length]);
  return (
    <div
      className={`status-menu status-menu-${task.status}${open ? " status-menu-open" : ""}`}
      onClick={(event) => event.stopPropagation()}
      ref={rootRef}
    >
      <button
        type="button"
        className="status-menu-trigger"
        ref={triggerRef}
        onClick={() => setOpen((value) => !value)}
      >
        <span className="status-menu-icon">{current.icon}</span>
        <span>{current.label}</span>
        <span className="status-menu-chevron"><Icon name="down" /></span>
      </button>
      {open && (
        createPortal(<>
          <button
            className="status-menu-overlay"
            type="button"
            aria-label="Закрити меню"
            onClick={() => setOpen(false)}
          />
          <div ref={optionsRef} className="status-menu-options" style={menuStyle}>
            {visibleOptions.map(([value, option]) => (
              <button
                type="button"
                className={value === task.status ? "active" : ""}
                key={value}
                onClick={() => {
                  setOpen(false);
                  onChange(task, value);
                }}
              >
                <span className={`status-menu-icon status-icon-${value}`}>
                  {option.icon}
                </span>
                <span>{option.label}</span>
                {value === task.status && <b><Icon name="check" /></b>}
              </button>
            ))}
          </div>
        </>, document.body)
      )}
    </div>
  );
}

export default function Tasks() {
  const [data, setData] = useState(null);
  const [notice, setNotice] = useState("");
  const [error, setError] = useState("");
  const [modalError, setModalError] = useState("");
  const [params] = useSearchParams();
  const [filter, setFilter] = useState(
    () => params.get("status") || (params.get("priority") === "urgent" ? "urgent" : null) || window.localStorage.getItem("nexaflow.tasks.filter") || "all",
  );
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState(null);
  const [modalOpen, setModalOpen] = useState(false);
  const [projectIds, setProjectIds] = useState([]);
  const [assigneeIds, setAssigneeIds] = useState([]);
  const [clientIds, setClientIds] = useState([]);
  const [archivePrompt, setArchivePrompt] = useState(null);
  const navigate = useNavigate();
  const openedFromQuery = useRef(false);
  async function load() {
    const [result, clients] = await Promise.all([
      api("/api/tasks.php"),
      api("/api/clients.php"),
    ]);
    if (!result.ok) return navigate("/");
    setData({ ...result, clients: clients.ok ? clients.clients || [] : [] });
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
  }, []);
  useEffect(() => {
    if (data && params.get("create") === "1" && !openedFromQuery.current) {
      openedFromQuery.current = true;
      createTask();
    }
  }, [data, params]);
  useEffect(() => {
    setPage(1);
  }, [filter, search]);
  useEffect(() => {
    window.localStorage.setItem("nexaflow.tasks.filter", filter);
  }, [filter]);
  function createTask() {
    setModalError("");
    setEditing(null);
    setProjectIds([]);
    const canManage = ["admin", "owner", "manager"].includes(data?.user?.role);
    setAssigneeIds(!canManage && data?.user?.id ? [data.user.id] : []);
    setClientIds([]);
    setModalOpen(true);
  }
  function editTask(task) {
    setModalError("");
    setEditing(task);
    setProjectIds(task.project_ids || []);
    setAssigneeIds(task.assignee_ids || []);
    setClientIds(task.client_ids || []);
    setModalOpen(true);
  }
  function closeModal() {
    setModalError("");
    setModalOpen(false);
    setEditing(null);
    setProjectIds([]);
    setAssigneeIds([]);
    setClientIds([]);
    if (params.get("create") === "1") navigate("/tasks", { replace: true });
  }
  async function save(event) {
    event.preventDefault();
    setNotice("");
    setError("");
    setModalError("");
    const form = new FormData(event.currentTarget);
    const payload = Object.fromEntries(form.entries());
    payload.project_ids = projectIds;
    payload.assignee_ids = assigneeIds;
    payload.client_ids = clientIds;
    payload.action = editing ? "update" : "create";
    if (editing) payload.id = editing.id;
    const result = await post("/api/tasks.php", payload);
    if (!result.ok) return setModalError(result.message || "Не вдалося зберегти задачу.");
    setNotice(result.message);
    closeModal();
    load();
  }
  async function changeStatus(task, status) {
    const result = await post("/api/tasks.php", {
      action: "set_status",
      id: task.id,
      status,
    });
    if (!result.ok) return setError(result.message);
    setNotice(result.message);
    load();
    if (status === "done" && ["admin", "owner", "manager"].includes(data?.user?.role)) setArchivePrompt(task);
  }
  async function archiveCompletedTask() {
    if (!archivePrompt) return;
    const result = await post("/api/tasks.php", { action: "set_status", id: archivePrompt.id, status: "archived" });
    if (!result.ok) return setError(result.message);
    setArchivePrompt(null);
    setNotice("Задачу переміщено до архіву.");
    load();
  }
  async function archive(task) {
    if (
      !window.confirm(
        "Перемістити задачу до архіву? Час та історія збережуться.",
      )
    )
      return;
    changeStatus(task, "archived");
  }
  if (!data) return <div className="loading-screen">Завантаження задач…</div>;
  const canArchive = (task) =>
    ["admin", "owner"].includes(data.user?.role) ||
    task.created_by === data.user?.id;
  const normalizedSearch = search.trim().toLowerCase();
  const filteredTasks = data.tasks
    .filter((task) => {
      const matchesFilter =
        filter === "all"
          ? task.status !== "archived"
          : filter === "overdue"
            ? isOverdueTask(task)
            : filter === "urgent"
              ? task.priority === "urgent" && !nonOverdueStatuses.has(task.status)
            : task.status === filter;
      const haystack =
        `${task.title} ${task.client_names || task.client_name || ""} ${task.project_name || ""} ${task.assignee_name || ""}`.toLowerCase();
      return (
        matchesFilter &&
        (!normalizedSearch || haystack.includes(normalizedSearch))
      );
    })
    .sort(taskSort);
  const totalPages = Math.max(1, Math.ceil(filteredTasks.length / pageSize));
  const currentPage = Math.min(page, totalPages);
  const pageTasks = filteredTasks
    .slice((currentPage - 1) * pageSize, currentPage * pageSize)
    .map((task) => ({
      ...task,
      canManage: ["admin", "owner", "manager"].includes(data.user?.role),
    }));
  const firstRow = filteredTasks.length ? (currentPage - 1) * pageSize + 1 : 0;
  const lastRow = Math.min(currentPage * pageSize, filteredTasks.length);
  return (
    <Layout page="Задачі" user={data.user}>
      <div className="content tasks-page tasks-redesign">
        <section className="welcome-row tasks-hero">
          <div>
            <p className="eyebrow">РОБОЧИЙ ПРОСТІР</p>
            <h1>Задачі</h1>
            <p className="subtext">
              Плануйте роботу, контролюйте дедлайни та тримайте команду в одному
              ритмі.
            </p>
          </div>
          <button
            className="primary-action hero-create"
            type="button"
            onClick={createTask}
          >
            <Icon name="plus" /> Створити задачу
          </button>
        </section>
        {notice && <div className="team-notice success">{notice}</div>}
        {error && <div className="form-notice">{error}</div>}
        <section className="panel task-table-panel">
          <div className="task-table-heading">
            <div>
              <div className="heading-with-count">
                <h2>Усі задачі</h2>
                <span className="task-count-badge">{filteredTasks.length}</span>
              </div>
              <p>Знайдіть потрібну задачу та змініть її статус в один клік.</p>
            </div>
          </div>
          <div className="task-toolbar">
            <label className="task-search">
              <span><Icon name="search" /></span>
              <input
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Пошук за назвою, клієнтом або виконавцем…"
              />
            </label>
            <select
              className="task-filter task-filter-large"
              value={filter}
              onChange={(event) => setFilter(event.target.value)}
            >
              <option value="all">Активні задачі</option>
              <option value="overdue">Прострочені</option>
              <option value="urgent">Термінові</option>
              <option value="todo">До виконання</option>
              <option value="in_progress">У роботі</option>
              <option value="review">На перевірці</option>
              <option value="done">Завершені</option>
              <option value="cancelled">Скасовані</option>
              <option value="archived">Архів</option>
            </select>
          </div>
          <div className="task-table-wrap">
            <table className="task-table">
              <thead>
                <tr>
                  <th>Задача</th>
                  <th>Проєкт</th>
                  <th>Клієнти</th>
                  <th>Виконавці</th>
                  <th>Пріоритет</th>
                  <th>Дедлайн</th>
                  <th>Статус</th>
                  <th aria-label="Дії" />
                </tr>
              </thead>
              <tbody>
                {pageTasks.map((task) => (
                  <tr
                    className="task-table-row"
                    key={task.id}
                    onClick={() => navigate(`/tasks/${task.id}`)}
                  >
                    <td>
                      <div className="table-task-title">
                        <strong>{task.title}</strong>
                        {task.description && (
                          <small>
                            {descriptionPreview(task.description)}
                            {descriptionPreview(task.description).length >= 140
                              ? "…"
                              : ""}
                          </small>
                        )}
                      </div>
                    </td>
                    <td>
                      <div className="table-secondary">
                        <span>{task.project_name || "Без проєкту"}</span>
                      </div>
                    </td>
                    <td>
                      <div className="table-clients">
                        <span className="table-client-icon"><Icon name="users" /></span>
                        <div className="table-client-list">
                          {(
                            task.client_names ||
                            task.client_name ||
                            "Без клієнта"
                          )
                            .split(",")
                            .map((name) => name.trim())
                            .filter(Boolean)
                            .map((name) => (
                              <b key={name}>{name}</b>
                            ))}
                        </div>
                      </div>
                    </td>
                    <td>
                      <span className="table-assignee">
                        {task.assignee_name || "Не призначено"}
                      </span>
                    </td>
                    <td>
                      <span
                        className={`priority-pill priority-${task.priority}`}
                      >
                        {priorityLabels[task.priority]}
                      </span>
                    </td>
                    <td>
                      <span className="table-date">
                        {task.due_date
                          ? new Date(
                              `${task.due_date}T00:00:00`,
                            ).toLocaleDateString("uk-UA")
                          : "—"}
                      </span>
                    </td>
                    <td className="task-status-cell">
                      <StatusMenu task={task} onChange={changeStatus} />
                    </td>
                    <td className="task-actions-cell">
                      <button
                        className="table-more"
                        type="button"
                        onClick={(event) => {
                          event.stopPropagation();
                          editTask(task);
                        }}
                        aria-label="Редагувати"
                      >
                        <Icon name="edit" />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {!pageTasks.length && (
              <div className="task-table-empty">
                <div className="empty-illustration"><Icon name="check" /></div>
                <h3>
                  {search
                    ? "Нічого не знайдено"
                    : filter === "overdue"
                      ? "Прострочених задач немає"
                    : filter === "archived"
                      ? "Архів порожній"
                      : "Задач поки немає"}
                </h3>
                <p>
                  {search
                    ? "Спробуйте змінити пошуковий запит."
                    : "Створіть першу задачу кнопкою вище."}
                </p>
              </div>
            )}
          </div>
          <div className="task-table-footer">
            <span>
              Показано {firstRow}–{lastRow} із {filteredTasks.length}
            </span>
            <div className="pagination">
              <button
                type="button"
                disabled={currentPage === 1}
                onClick={() => setPage((value) => value - 1)}
               aria-label="Попередня сторінка">
                <Icon name="left" />
              </button>
              {Array.from({ length: totalPages }, (_, index) => index + 1)
                .slice(Math.max(0, currentPage - 3), currentPage + 2)
                .map((number) => (
                  <button
                    type="button"
                    className={number === currentPage ? "active" : ""}
                    key={number}
                    onClick={() => setPage(number)}
                  >
                    {number}
                  </button>
                ))}
              <button
                type="button"
                disabled={currentPage === totalPages}
                onClick={() => setPage((value) => value + 1)}
               aria-label="Наступна сторінка">
                <Icon name="right" />
              </button>
            </div>
          </div>
        </section>
        {modalOpen && (
          <TaskFormModal
            data={data}
            editing={editing}
            error={modalError}
            projectIds={projectIds}
            assigneeIds={assigneeIds}
            clientIds={clientIds}
            setProjectIds={setProjectIds}
            setAssigneeIds={setAssigneeIds}
            setClientIds={setClientIds}
            onSubmit={save}
            onClose={closeModal}
          />
        )}
        {archivePrompt && createPortal(
          <div className="completion-prompt-backdrop" role="presentation" onMouseDown={event => { if (event.target === event.currentTarget) setArchivePrompt(null); }}>
            <section className="completion-prompt" role="dialog" aria-modal="true" aria-labelledby="tasks-completion-prompt-title">
              <button className="completion-prompt-close" type="button" onClick={() => setArchivePrompt(null)} aria-label="Закрити"><Icon name="close" /></button>
              <div className="completion-prompt-icon"><Icon name="check" /></div>
              <p className="eyebrow">ЗАДАЧУ ЗАВЕРШЕНО</p>
              <h2 id="tasks-completion-prompt-title">Перенести задачу в архів?</h2>
              <p>Завершена задача більше не відображатиметься серед активних. Її можна буде знайти в архіві.</p>
              <div className="completion-prompt-actions">
                <button className="outline-btn" type="button" onClick={() => setArchivePrompt(null)}>Залишити завершеною</button>
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
