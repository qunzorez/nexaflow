import Icon from '../components/Icon.jsx';
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
const dashboardStatusOrder = {
  review: 0,
  in_progress: 1,
  todo: 2,
  done: 3,
  cancelled: 4,
  archived: 5,
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
  const [reviewPanelOpen, setReviewPanelOpen] = useState(true);
  const [taskSearch, setTaskSearch] = useState("");
  const [taskFilter, setTaskFilter] = useState("all");
  const [teamSearch, setTeamSearch] = useState("");
  const [teamSort, setTeamSort] = useState("time");
  const [projectSearch, setProjectSearch] = useState(() => window.localStorage.getItem("nexaflow.dashboard.projectSearch") || "");
  const [projectProgressFilter, setProjectProgressFilter] = useState(() => window.localStorage.getItem("nexaflow.dashboard.projectProgressFilter") || "all");
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
  useEffect(() => {
    window.localStorage.setItem("nexaflow.dashboard.projectSearch", projectSearch);
    window.localStorage.setItem("nexaflow.dashboard.projectProgressFilter", projectProgressFilter);
  }, [projectSearch, projectProgressFilter]);
  if (!data)
    return <div className="loading-screen">Завантаження NexaFlow…</div>;
  const canManage = data.permissions.manage;
  const isAdmin = ["admin", "owner"].includes(data.user.role);
  const isManager = data.user.role === "manager";
  const showProjectInsights = !isAdmin;
  const adminMetrics = isAdmin
    ? [
        { value: data.metrics.users || 0, title: "Користувачі" },
        { value: data.metrics.online || 0, title: "Онлайн зараз" },
        { value: data.metrics.all_projects || 0, title: "Усі проєкти" },
        { value: data.metrics.open_tasks || 0, title: "Відкриті задачі" },
        { value: data.metrics.all_clients || 0, title: "Усі клієнти" },
      ]
    : null;
  const firstMetric = canManage
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
      )
      .sort((left, right) => {
        const statusDifference =
          (dashboardStatusOrder[left.status] ?? 99) -
          (dashboardStatusOrder[right.status] ?? 99);
        if (statusDifference) return statusDifference;
        const leftDue = left.due_date ? new Date(`${left.due_date}T00:00:00`).getTime() : Number.POSITIVE_INFINITY;
        const rightDue = right.due_date ? new Date(`${right.due_date}T00:00:00`).getTime() : Number.POSITIVE_INFINITY;
        if (leftDue !== rightDue) return leftDue - rightDue;
        return String(right.created_at || "").localeCompare(String(left.created_at || ""));
      }) || [];
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
  const managerDashboard = data.manager_dashboard || {};
  const managerAttention = managerDashboard.attention || {};
  const reviewTasks = isManager
    ? [...(data.tasks || [])]
        .filter((task) => task.status === "review")
        .sort((left, right) => {
          const leftDue = left.due_date ? new Date(`${left.due_date}T00:00:00`).getTime() : Number.POSITIVE_INFINITY;
          const rightDue = right.due_date ? new Date(`${right.due_date}T00:00:00`).getTime() : Number.POSITIVE_INFINITY;
          if (leftDue !== rightDue) return leftDue - rightDue;
          return String(right.created_at || "").localeCompare(String(left.created_at || ""));
        })
    : [];
  const visibleProjectProgress = (managerDashboard.project_progress || []).filter((project) => {
    const matchesName = String(project.name || "").toLocaleLowerCase("uk").includes(projectSearch.trim().toLocaleLowerCase("uk"));
    return matchesName;
  }).sort((a, b) => projectProgressFilter === "done"
    ? Number(b.done || 0) - Number(a.done || 0)
    : projectProgressFilter === "in_progress"
      ? Number(b.in_progress || 0) - Number(a.in_progress || 0)
      : 0);
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
              Доброго дня, <span>{data.user.name.split(" ")[0]}</span> <Icon name="sparkle" />
            </h1>
            <p className="subtext">
              Ваші проєкти, задачі та доступи — в одному робочому просторі.
            </p>
          </div>
          <div className="dashboard-actions">
            <Link className="primary-action" to="/tasks?create=1">
              <Icon name="plus" /> Створити задачу
            </Link>
            {canManage && (
              <Link className="outline-btn" to="/projects/new">
                <Icon name="plus" /> Створити проєкт
              </Link>
            )}
          </div>
        </section>
        {data.reminders?.length > 0 && (
          <section className="panel reminders-panel reminder-panel-enter">
            <div className="panel-heading">
              <div className="reminders-heading-copy">
                <span className="reminders-heading-icon" aria-hidden="true"><Icon name="clock" /></span>
                <div>
                  <h2>Нагадування</h2>
                  <p>Час повернутися до задач, які потребують вашої уваги.</p>
                </div>
              </div>
              <span className="task-count-badge reminder-count-pulse">{data.reminders.length}</span>
            </div>
            <div className="reminders-callout"><span><Icon name="sparkle" /></span><strong>У вас є важливі задачі</strong><small>Натисніть на задачу, щоб перенести або вимкнути нагадування.</small></div>
            <div className="reminder-list">
              {data.reminders.map((task) => (
                <button
                  className="reminder-row"
                  type="button"
                  onClick={() => setReminderTask(task)}
                  key={task.id}
                >
                  <span className="reminder-leading-icon" aria-hidden="true"><Icon name="clock" /></span>
                  <span className={`task-status status-${task.status}`}>
                      {task.status === "review" && <Icon name="urgent" />}
                    {statusLabels[task.status]}
                  </span>
                  <div>
                    <strong>{task.title}</strong>
                    <small>{task.project_name || "Без проєкту"}</small>
                  </div>
                  <time>{reminderLabel(task.reminder_at)}</time>
                  <span className="reminder-arrow"><Icon name="right" /></span>
                </button>
              ))}
            </div>
          </section>
        )}
        {isManager && reviewTasks.length > 0 && (
          <section className={`panel review-notification-panel${reviewPanelOpen ? " is-open" : " is-collapsed"}`}>
            <button
              type="button"
              className="review-notification-toggle"
              onClick={() => setReviewPanelOpen((value) => !value)}
              aria-expanded={reviewPanelOpen}
              aria-controls="review-notification-content"
            >
              <span className="review-notification-copy">
                <h2>Потрібна перевірка</h2>
                <p>Задачі зі статусом «На перевірці» очікують вашого рішення.</p>
              </span>
              <span className="review-notification-heading-actions">
                <span className="task-count-badge review-notification-count">{reviewTasks.length}</span>
                <Icon name="down" />
              </span>
            </button>
            {reviewPanelOpen && (
              <div id="review-notification-content" className="review-notification-content">
                <div className="review-notification-list">
                  {reviewTasks.map((task) => (
                    <Link className="review-notification-row" to={`/tasks/${task.id}`} key={task.id}>
                      <span className="task-status status-review"><Icon name="urgent" /> На перевірці</span>
                      <div><strong>{task.title}</strong><small>{task.project_name || "Без проєкту"}{task.due_date ? ` · до ${new Date(`${task.due_date}T00:00:00`).toLocaleDateString("uk-UA")}` : ""}</small></div>
                      <Icon name="right" />
                    </Link>
                  ))}
                </div>
                <Link className="outline-btn review-notification-action" to="/tasks?status=review">Переглянути всі задачі на перевірці</Link>
              </div>
            )}
          </section>
        )}
        <section className={`stat-grid${isAdmin ? " admin-stat-grid" : ""}`}>
          {(adminMetrics || [
            firstMetric,
            { value: data.metrics.projects, title: "Активні проєкти" },
            { value: data.metrics.tasks, title: "Відкриті задачі" },
            { value: `${data.metrics.hours} год`, title: "Мій час" },
          ]).map((metric) => <Stat key={metric.title} value={metric.value} title={metric.title} />)}
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
                  <Link className="project-row dashboard-project-link" key={project.id} to={`/projects/${project.id}`}>
                    <span className="project-icon"><Icon name="project" /></span>
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
                  </Link>
                ))}
              </div>
            ) : (
              <div className="empty-state">
                <div className="empty-illustration"><Icon name="project" /></div>
                <h3>Проєктів ще немає</h3>
                <p>
                  {canManage
                    ? "Створіть свій перший проєкт, щоб почати керувати роботою команди."
                    : "Коли менеджер додасть вас до проєкту, він з’явиться тут."}
                </p>
                {canManage && (
                  <Link className="outline-btn" to="/projects/new">
                    <Icon name="plus" /> Створити проєкт
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
                Усі задачі <Icon name="forward" />
              </Link>
            </div>
            <div className="dashboard-task-tools">
              <label>
                <span><Icon name="search" /></span>
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
                      {task.status === "review" && <Icon name="urgent" />}
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
                    <span><Icon name="right" /></span>
                  </Link>
                ))}
              </div>
            ) : (
              <div className="dashboard-task-empty">
                <span><Icon name="check" /></span>
                <p>Задач за цим фільтром немає.</p>
                <Link className="outline-btn" to="/tasks?create=1">
                  Створити задачу
                </Link>
              </div>
            )}
          </article>
        </section>
        {showProjectInsights && (
          <section className="manager-dashboard-grid">
            <article className="panel manager-attention-panel">
              <div className="panel-heading"><div><h2>Потребує уваги</h2><p>Ключові речі, які варто перевірити.</p></div><Icon name="urgent" /></div>
              <div className="attention-cards">
                <Link to="/tasks?status=overdue" className="attention-card attention-danger"><strong>{managerAttention.overdue || 0}</strong><span>Прострочені задачі</span></Link>
                <Link to="/tasks?status=review" className="attention-card attention-warning"><strong>{managerAttention.review || 0}</strong><span>На перевірці</span></Link>
                <Link to="/tasks?priority=urgent" className="attention-card attention-urgent"><strong>{managerAttention.urgent || 0}</strong><span>Термінові</span></Link>
              </div>
            </article>
            <article className="panel manager-widget"><div className="panel-heading"><div><h2>Найближчі дедлайни</h2><p>Задачі на наступні 7 днів.</p></div><Link className="panel-link" to="/tasks">Усі <Icon name="forward" /></Link></div><div className="manager-list">{(managerDashboard.upcoming_deadlines || []).length ? managerDashboard.upcoming_deadlines.map(task => <Link className="manager-list-row" to={`/tasks/${task.id}`} key={task.id}><span className={`task-status status-${task.status}`}>{statusLabels[task.status]}</span><div><strong>{task.title}</strong><small>{task.project_name || 'Без проєкту'}</small></div><time>{new Date(`${task.due_date}T00:00:00`).toLocaleDateString('uk-UA')}</time></Link>) : <p className="muted-note">Найближчих дедлайнів немає.</p>}</div></article>
            <article className="panel manager-widget"><div className="panel-heading manager-project-heading"><div><h2>Прогрес проєктів</h2><p>Ваші створені та запрошені проєкти.</p></div><Link className="panel-link" to="/projects">Усі <Icon name="forward" /></Link></div><div className="manager-project-tools"><label><Icon name="search" /><input type="search" value={projectSearch} onChange={(event) => setProjectSearch(event.target.value)} placeholder="Пошук проєкту…" /></label><select value={projectProgressFilter} onChange={(event) => setProjectProgressFilter(event.target.value)}><option value="all">За замовчуванням</option><option value="done">Більше виконано</option><option value="in_progress">Більше в роботі</option></select></div><div className="manager-list">{visibleProjectProgress.length ? visibleProjectProgress.map(project => { const donePercent = project.total ? Math.round(project.done / project.total * 100) : 0; const inProgressPercent = project.total ? Math.round((project.in_progress || 0) / project.total * 100) : 0; return <Link className="progress-row" to={`/projects/${project.id}`} key={project.id}><div><strong>{project.name}</strong><small>Усього задач: {project.total}</small></div><b>{donePercent}%</b><div className="project-progress-lines"><div className="project-progress-line"><span>Виконано</span><div className="progress-track"><i className="progress-done" style={{width: `${donePercent}%`}} /></div><b>{project.done}</b></div><div className="project-progress-line"><span>У роботі</span><div className="progress-track"><i className="progress-in-progress" style={{width: `${inProgressPercent}%`}} /></div><b>{project.in_progress || 0}</b></div></div></Link>}) : <p className="muted-note">Проєктів за цим пошуком немає.</p>}</div></article>
            {isManager && <><article className="panel manager-widget"><div className="panel-heading"><div><h2>Завантаженість команди</h2><p>Активні задачі за виконавцями.</p></div></div><div className="manager-list">{(managerDashboard.team_workload || []).slice(0, 6).map(person => <div className="manager-list-row" key={person.id}><span className="member-avatar"><Icon name="user" /></span><div><strong>{person.name}</strong><small>{person.email}</small></div><b>{person.tasks} задач</b></div>)}</div></article><article className="panel manager-widget"><div className="panel-heading"><div><h2>Остання активність</h2><p>Останні зміни у задачах.</p></div></div><div className="manager-list">{(managerDashboard.recent_activity || []).map(item => <Link className="activity-row" to={`/tasks/${item.task_id}`} key={`${item.task_id}-${item.created_at}`}><span className="activity-dot" /><div><strong>{item.user_name || 'Користувач'} · {item.task_title}</strong><small>{item.message}</small></div></Link>)}</div></article></>}
          </section>
        )}
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
                <span><Icon name="search" /></span>
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
    "Активні клієнти": <Icon name="users" />,
    "Усі клієнти": <Icon name="users" />,
    "Користувачі": <Icon name="users" />,
    "Онлайн зараз": <Icon name="pulse" />,
    "Моя команда": <Icon name="users" />,
    "Мої дедлайни": <Icon name="hourglass" />,
    "Активні проєкти": <Icon name="project" />,
    "Відкриті задачі": <Icon name="check" />,
    "Мій час": <Icon name="clock" />,
  };
  const links = {
    "Моя команда": "/team",
    "Користувачі": "/team",
    "Усі проєкти": "/projects",
    "Активні проєкти": "/projects",
    "Відкриті задачі": "/tasks",
  };
  const content = (
    <article className="stat-card dashboard-stat-card">
      <span className="stat-icon green">
        <span>{icons[title] || <Icon name="sparkle" />}</span>
      </span>
      <strong>{value}</strong>
      <span>{title}</span>
      <small>Дані робочого простору</small>
    </article>
  );
  return links[title] ? <Link className="dashboard-stat-link" to={links[title]}>{content}</Link> : content;
}
