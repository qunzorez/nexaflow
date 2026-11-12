import { useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import Layout from "../components/Layout.jsx";
import { api, post } from "../services/api.js";
import ReminderModal from "../modules/tasks/ReminderModal.jsx";
import "./task-review-status.css";
import "./dashboard-tasks.css";
import "./dashboard-modern.css";

const statusLabels = {
  todo: "До виконання",
  in_progress: "У роботі",
  review: "На перевірці",
  done: "Завершено",
  cancelled: "Скасовано",
  archived: "Архів",
};
const projectStatusLabels = {
  active: "Активний",
  paused: "Призупинений",
  completed: "Завершений",
  draft: "Чернетка",
};
const timeLabel = (minutes) => {
  const value = Number(minutes || 0);
  return `${Math.floor(value / 60)} год ${value % 60} хв`;
};
const reminderLabel = (value) =>
  new Date(String(value || "").replace(" ", "T")).toLocaleString("uk-UA", {
    day: "2-digit",
    month: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  });

export default function Dashboard() {
  const [data, setData] = useState(null);
  const [reminderTask, setReminderTask] = useState(null);
  const [notice, setNotice] = useState("");
  const [taskSearch, setTaskSearch] = useState("");
  const [taskFilter, setTaskFilter] = useState("all");
  const [teamSearch, setTeamSearch] = useState("");
  const [teamSort, setTeamSort] = useState("time");
  const navigate = useNavigate();
  useEffect(() => {
    const refresh = () =>
      api("/api/dashboard.php").then((result) =>
        result.ok ? setData(result) : navigate("/"),
      );
    refresh();
    const interval = window.setInterval(refresh, 15000);
    const refreshOnFocus = () => refresh();
    window.addEventListener("focus", refreshOnFocus);
    return () => {
      window.clearInterval(interval);
      window.removeEventListener("focus", refreshOnFocus);
    };
  }, [navigate]);
  if (!data)
    return <div className="loading-screen">Завантаження NexaFlow…</div>;
  const canManage = data.permissions.manage;
  const isAdmin = ["admin", "owner"].includes(data.user.role);
  const firstMetric = isAdmin
    ? { value: data.metrics.clients, title: "Активні клієнти" }
    : canManage
      ? { value: data.users?.length || 0, title: "Моя команда" }
      : {
          value:
            data.tasks?.filter(
              (task) => task.status !== "archived" && task.due_date,
            ).length || 0,
          title: "Мої дедлайни",
        };
  const dashboardTasks =
    data.tasks
      ?.filter((task) => task.status !== "archived")
      .filter((task) => taskFilter === "all" || task.status === taskFilter)
      .filter((task) =>
        `${task.title} ${task.project_name || ""} ${task.client_name || ""}`
          .toLowerCase()
          .includes(taskSearch.trim().toLowerCase()),
      ) || [];
  const teamTimeRows = (data.time_by_user || [])
    .filter((person) =>
      `${person.name} ${person.email}`
        .toLowerCase()
        .includes(teamSearch.trim().toLowerCase()),
    )
    .sort((a, b) =>
      teamSort === "name"
        ? String(a.name).localeCompare(String(b.name), "uk")
        : Number(b.minutes || 0) - Number(a.minutes || 0),
    );
  const productivityRows = [...(data.time_by_user || [])]
    .sort((a, b) => Number(b.minutes || 0) - Number(a.minutes || 0))
    .slice(0, 5);
  const maxTeamMinutes = Math.max(
    ...productivityRows.map((person) => Number(person.minutes || 0)),
    1,
  );
  async function saveReminder(reminderAt) {
    const result = await post("/api/tasks.php", {
      action: "set_reminder",
      task_id: reminderTask.id,
      reminder_at: reminderAt,
    });
    if (!result.ok) return setNotice(result.message);
    setNotice(result.message);
    setReminderTask(null);
    const refreshed = await api("/api/dashboard.php");
    if (refreshed.ok) setData(refreshed);
  }
  return (
    <Layout page="Dashboard" user={data.user}>
      <div className="content dashboard-page">
        {notice && <div className="team-notice success">{notice}</div>}
        <section className="welcome-row">
          <div>
            <p className="eyebrow">ВАШ РОБОЧИЙ ПРОСТІР</p>
            <h1>
              Доброго дня, <span>{data.user.name.split(" ")[0]}</span> ✦
            </h1>
            <p className="subtext">
              Ваші проєкти, задачі та доступи — в одному робочому просторі.
            </p>
          </div>
          <div className="dashboard-actions">
            <Link className="primary-action" to="/tasks?create=1">
              ＋ Створити задачу
            </Link>
            {canManage && (
              <Link className="outline-btn" to="/projects/new">
                ＋ Створити проєкт
              </Link>
            )}
          </div>
        </section>
        {data.reminders?.length > 0 && (
          <section className="panel reminders-panel reminder-panel-enter">
            <div className="panel-heading">
              <div className="reminders-heading-copy">
                <span className="reminders-heading-icon" aria-hidden="true">◷</span>
                <div>
                  <h2>Нагадування</h2>
                  <p>Час повернутися до задач, які потребують вашої уваги.</p>
                </div>
              </div>
              <span className="task-count-badge reminder-count-pulse">{data.reminders.length}</span>
            </div>
            <div className="reminders-callout"><span>✦</span><strong>У вас є важливі задачі</strong><small>Натисніть на задачу, щоб перенести або вимкнути нагадування.</small></div>
            <div className="reminder-list">
              {data.reminders.map((task) => (
                <button
                  className="reminder-row"
                  type="button"
                  onClick={() => setReminderTask(task)}
                  key={task.id}
                >
                  <span className="reminder-leading-icon" aria-hidden="true">◷</span>
                  <span className={`task-status status-${task.status}`}>
                    {statusLabels[task.status]}
                  </span>
                  <div>
                    <strong>{task.title}</strong>
                    <small>{task.project_name || "Без проєкту"}</small>
                  </div>
                  <time>{reminderLabel(task.reminder_at)}</time>
                  <span className="reminder-arrow">›</span>
                </button>
              ))}
            </div>
          </section>
        )}
        <section className="stat-grid">
          <Stat value={firstMetric.value} title={firstMetric.title} />
          <Stat value={data.metrics.projects} title="Активні проєкти" />
          <Stat value={data.metrics.tasks} title="Відкриті задачі" />
          <Stat value={`${data.metrics.hours} год`} title="Мій час" />
        </section>
        <section className="grid-two">
          <article className="panel">
            <div className="panel-heading">
              <div>
                <h2>Останні проєкти</h2>
                <p>Слідкуйте за прогресом ваших проєктів</p>
              </div>
            </div>
            {data.projects.length ? (
              <div
                className={`project-list${data.projects.length > 5 ? " is-scrollable" : ""}`}
              >
                {data.projects.map((project) => (
                  <div className="project-row" key={project.id}>
                    <span className="project-icon">▣</span>
                    <div>
                      <strong>{project.name}</strong>
                      <small>
                        {project.description ||
                          `Власник: ${project.owner_name}`}
                      </small>
                    </div>
                    <span
                      className={`project-status project-status-${project.status || "active"}`}
                    >
                      {projectStatusLabels[project.status] ||
                        projectStatusLabels.active}
                    </span>
                  </div>
                ))}
              </div>
            ) : (
              <div className="empty-state">
                <div className="empty-illustration">▣</div>
                <h3>Проєктів ще немає</h3>
                <p>
                  {canManage
                    ? "Створіть свій перший проєкт, щоб почати керувати роботою команди."
                    : "Коли менеджер додасть вас до проєкту, він з’явиться тут."}
                </p>
                {canManage && (
                  <Link className="outline-btn" to="/projects/new">
                    ＋ Створити проєкт
                  </Link>
                )}
              </div>
            )}
          </article>
          <article className="panel dashboard-tasks-panel">
            <div className="panel-heading">
              <div>
                <h2>Мої задачі</h2>
                <p>Найближча робота та призначені вам задачі.</p>
              </div>
              <Link className="panel-link" to="/tasks">
                Усі задачі →
              </Link>
            </div>
            <div className="dashboard-task-tools">
              <label>
                <span>⌕</span>
                <input
                  value={taskSearch}
                  onChange={(event) => setTaskSearch(event.target.value)}
                  placeholder="Пошук задач…"
                />
              </label>
              <select
                value={taskFilter}
                onChange={(event) => setTaskFilter(event.target.value)}
              >
                <option value="all">Усі активні</option>
                <option value="todo">До виконання</option>
                <option value="in_progress">У роботі</option>
                <option value="review">На перевірці</option>
                <option value="done">Завершені</option>
                <option value="cancelled">Скасовані</option>
              </select>
            </div>
            {dashboardTasks.length ? (
              <div className="dashboard-task-list">
                {dashboardTasks.map((task) => (
                  <Link
                    className="dashboard-task-row"
                    to={`/tasks/${task.id}`}
                    key={task.id}
                  >
                    <span className={`task-status status-${task.status}`}>
                      {statusLabels[task.status]}
                    </span>
                    <div>
                      <strong>{task.title}</strong>
                      <small>
                        {task.project_name || "Без проєкту"}
                        {task.due_date
                          ? ` · до ${new Date(`${task.due_date}T00:00:00`).toLocaleDateString("uk-UA")}`
                          : ""}
                      </small>
                    </div>
                    <span>›</span>
                  </Link>
                ))}
              </div>
            ) : (
              <div className="dashboard-task-empty">
                <span>✓</span>
                <p>Задач за цим фільтром немає.</p>
                <Link className="outline-btn" to="/tasks?create=1">
                  Створити задачу
                </Link>
              </div>
            )}
          </article>
        </section>
        {canManage && (
          <section className="panel team-time-panel">
            <div className="panel-heading">
              <div>
                <h2>Час команди</h2>
                <p>Скільки часу кожен користувач залогував у задачах.</p>
              </div>
            </div>
            <div className="team-time-tools">
              <label>
                <span>⌕</span>
                <input
                  value={teamSearch}
                  onChange={(event) => setTeamSearch(event.target.value)}
                  placeholder="Пошук користувача…"
                />
              </label>
              <select
                value={teamSort}
                onChange={(event) => setTeamSort(event.target.value)}
              >
                <option value="time">За витраченим часом</option>
                <option value="name">За іменем</option>
              </select>
            </div>
            <div className="team-time-overview">
              <div className="team-hours-chart">
                <div className="dashboard-section-kicker">ГОДИНИ КОМАНДИ</div>
                <div className="team-chart-bars">
                  {productivityRows.length ? (
                    productivityRows.map((person) => (
                      <div className="team-chart-column" key={person.id}>
                        <div className="team-chart-value">
                          {Math.round(Number(person.minutes || 0) / 6) / 10} год
                        </div>
                        <div className="team-chart-bar-track">
                          <span
                            style={{
                              height: `${Math.max(8, Math.round((Number(person.minutes || 0) / maxTeamMinutes) * 100))}%`,
                            }}
                          />
                        </div>
                        <small>{String(person.name || "").split(" ")[0]}</small>
                      </div>
                    ))
                  ) : (
                    <p className="muted-note">Дані про час ще не додані.</p>
                  )}
                </div>
              </div>
              <div className="productivity-card">
                <div className="dashboard-section-kicker">ТОП ПРОДУКТИВНИХ</div>
                {productivityRows.length ? (
                  productivityRows.map((person, index) => (
                    <div className="productivity-row" key={person.id}>
                      <span>{index + 1}</span>
                      <div>
                        <strong>{person.name}</strong>
                        <small>{person.email}</small>
                      </div>
                      <b>{timeLabel(person.minutes)}</b>
                    </div>
                  ))
                ) : (
                  <p className="muted-note">Поки немає даних.</p>
                )}
              </div>
            </div>
            <div className="team-time-list">
              {teamTimeRows.length ? (
                teamTimeRows.map((person) => (
                  <div className="team-time-row" key={person.id}>
                    <div>
                      <strong>{person.name}</strong>
                      <small>{person.email}</small>
                    </div>
                    <b>{timeLabel(person.minutes)}</b>
                  </div>
                ))
              ) : (
                <p className="muted-note">Користувачів не знайдено.</p>
              )}
            </div>
          </section>
        )}
        {reminderTask && (
          <ReminderModal
            task={reminderTask}
            onSubmit={saveReminder}
            onClose={() => setReminderTask(null)}
          />
        )}
      </div>
    </Layout>
  );
}
function Stat({ value, title }) {
  const icons = {
    "Активні клієнти": "◉",
    "Моя команда": "♧",
    "Мої дедлайни": "⌛",
    "Активні проєкти": "▣",
    "Відкриті задачі": "✓",
    "Мій час": "◷",
  };
  return (
    <article className="stat-card dashboard-stat-card">
      <span className="stat-icon green">
        <span>{icons[title] || "✦"}</span>
      </span>
      <strong>{value}</strong>
      <span>{title}</span>
      <small>Дані робочого простору</small>
    </article>
  );
}
