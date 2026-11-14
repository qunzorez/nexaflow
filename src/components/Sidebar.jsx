import Icon from './Icon.jsx';
import { useEffect, useRef, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { api, post } from "../services/api.js";
import "./sidebar-icons.css";

export default function Sidebar({ user, open }) {
  const navigate = useNavigate();
  const workspaces = user?.workspaces || [];
  const activeWorkspaceId = user?.active_workspace_id || user?.subscription?.workspace_id || "";
  const workspaceCacheKey = `${user?.id || "guest"}.${activeWorkspaceId || "default"}`;
  const countsStorageKey = `nexaflow.sidebar.counts.${workspaceCacheKey}`;
  const featuresStorageKey = `nexaflow.sidebar.features.${workspaceCacheKey}`;
  const [counts, setCounts] = useState(() => {
    try {
      return { clients: 0, projects: 0, tasks: 0, team: 0, notifications: 0, ...JSON.parse(window.localStorage.getItem(countsStorageKey) || "{}") };
    } catch {
      return { clients: 0, projects: 0, tasks: 0, team: 0, notifications: 0 };
    }
  });
  const [planFeatures, setPlanFeatures] = useState(() => {
    try {
      const cached = JSON.parse(window.localStorage.getItem(featuresStorageKey) || "{}");
      return { ...cached, ...(user?.subscription?.features || {}) };
    } catch {
      return user?.subscription?.features || {};
    }
  });
  const [switchingWorkspace, setSwitchingWorkspace] = useState(false);
  const [workspaceError, setWorkspaceError] = useState("");
  const [workspaceMenuOpen, setWorkspaceMenuOpen] = useState(false);
  const workspaceMenuRef = useRef(null);
  const userRole = String(user?.workspace_role || user?.role || "").toLowerCase();
  const canManage = ["admin", "owner", "manager", "project_lead"].includes(userRole);
  const isAdmin = ["admin", "owner"].includes(userRole);
  const activeWorkspace = workspaces.find(workspace => workspace.id === activeWorkspaceId);
  useEffect(() => {
    if (!workspaceMenuOpen) return undefined;
    const closeOnOutside = event => { if (!workspaceMenuRef.current?.contains(event.target)) setWorkspaceMenuOpen(false); };
    const closeOnEscape = event => { if (event.key === "Escape") setWorkspaceMenuOpen(false); };
    document.addEventListener("pointerdown", closeOnOutside);
    document.addEventListener("keydown", closeOnEscape);
    return () => {
      document.removeEventListener("pointerdown", closeOnOutside);
      document.removeEventListener("keydown", closeOnEscape);
    };
  }, [workspaceMenuOpen]);
  useEffect(() => {
    if (!user?.subscription?.features) return;
    setPlanFeatures(user.subscription.features);
    window.localStorage.setItem(featuresStorageKey, JSON.stringify(user.subscription.features));
  }, [featuresStorageKey, user?.subscription?.features]);
  useEffect(() => {
    let active = true;
    const loadCounts = async () => {
      const [dashboard, notifications, clients] = await Promise.all([
        api("/api/dashboard.php"),
        api("/api/notifications.php"),
        api("/api/clients.php"),
      ]);
      if (!active) return;
      if (dashboard.user?.subscription?.features) {
        const features = dashboard.user.subscription.features;
        setPlanFeatures(features);
        window.localStorage.setItem(featuresStorageKey, JSON.stringify(features));
      }
      const nextCounts = {
        clients: Number(clients.clients?.length || 0),
        projects: Number(
          dashboard.metrics?.projects || dashboard.projects?.length || 0,
        ),
        tasks: Number(dashboard.metrics?.tasks || 0),
        team: Number(dashboard.users?.length || 0),
        notifications: Number(notifications.unread || 0),
      };
      window.localStorage.setItem(countsStorageKey, JSON.stringify(nextCounts));
      setCounts(current => JSON.stringify(current) === JSON.stringify(nextCounts) ? current : nextCounts);
    };
    loadCounts();
    const interval = window.setInterval(loadCounts, 15000);
    return () => {
      active = false;
      window.clearInterval(interval);
    };
  }, [countsStorageKey, featuresStorageKey, user?.id]);
  async function logout() {
    await post("/api/logout.php", {});
    navigate("/");
  }
  async function switchWorkspace(workspaceId) {
    if (!workspaceId || workspaceId === activeWorkspaceId || switchingWorkspace) return;
    setSwitchingWorkspace(true);
    setWorkspaceError("");
    const result = await post("/api/workspaces.php", { workspace_id: workspaceId });
    if (!result.ok) {
      setWorkspaceError(result.message || "Не вдалося переключити простір.");
      setSwitchingWorkspace(false);
      return;
    }
    window.location.reload();
  }
  return (
    <aside className={`sidebar ${open ? "open" : ""}`}>
      <Link className="side-brand" to="/dashboard">
        <div className="brand-mark">
          <span><Icon name="plus" /></span>
        </div>
        <div>
          <strong>NEXAFLOW</strong>
          <small>SRM SYSTEM</small>
        </div>
      </Link>
      {workspaces.length > 0 && <section className={`my-workspaces${workspaceMenuOpen ? " is-open" : ""}`} aria-label="Мої простори" ref={workspaceMenuRef}>
        <p className="nav-label">МОЇ ПРОСТОРИ</p>
        <button type="button" className="workspace-trigger" aria-expanded={workspaceMenuOpen} aria-haspopup="listbox" onClick={() => setWorkspaceMenuOpen(open => !open)}>
          <span className="workspace-switcher-icon"><Icon name="project" /></span>
          <span className="workspace-option-copy"><strong>{activeWorkspace?.name || "Оберіть простір"}</strong><small>{activeWorkspace?.workspace_role || "Робочий простір"}</small></span>
          <Icon name="down" className={`workspace-trigger-chevron${workspaceMenuOpen ? " rotated" : ""}`} />
        </button>
        {workspaceMenuOpen && <div className="workspace-menu" role="listbox" aria-label="Оберіть робочий простір">
          {workspaces.map(workspace => <button key={workspace.id} type="button" role="option" aria-selected={workspace.id === activeWorkspaceId} className={`workspace-option${workspace.id === activeWorkspaceId ? " active" : ""}`} title={workspace.name} disabled={switchingWorkspace} onClick={() => { setWorkspaceMenuOpen(false); switchWorkspace(workspace.id); }}>
            <span className="workspace-switcher-icon"><Icon name="project" /></span>
            <span className="workspace-option-copy"><strong>{workspace.name}</strong><small>{workspace.workspace_role || "Учасник"}</small></span>
            {workspace.id === activeWorkspaceId && <span className="workspace-active-dot" aria-label="Активний" />}
          </button>)}
          {workspaceError && <small className="workspace-switcher-error" role="alert">{workspaceError}</small>}
          {switchingWorkspace && <small className="workspace-switcher-loading">Перемикаємо простір…</small>}
        </div>}
      </section>}
      <nav>
        <p className="nav-label">ОСНОВНЕ</p>
        <Link className="nav-item" to="/dashboard">
          <span><Icon name="home" /></span> Огляд <b>{counts.tasks}</b>
        </Link>
        <Link className="nav-item" to="/clients">
          <span><Icon name="users" /></span> Клієнти <b>{counts.clients}</b>
        </Link>
        <Link className="nav-item" to="/projects">
          <span><Icon name="project" /></span> Проєкти <b>{counts.projects}</b>
        </Link>
        <Link className="nav-item" to="/tasks">
          <span><Icon name="tasks" /></span> Задачі <b>{counts.tasks}</b>
        </Link>
        {canManage && (
          <Link className="nav-item" to="/team">
            <span className="nav-icon">
              <Icon name="users" />
            </span>{" "}
            Команда <b>{counts.team}</b>
          </Link>
        )}
        {canManage && planFeatures.services && <Link className="nav-item" to="/services">
          <span><Icon name="subscription" /></span> Послуги
        </Link>}
        {isAdmin && (
          <Link className="nav-item" to="/notifications">
            <span className="nav-icon">
              <Icon name="bell" />
            </span>{" "}
            Сповіщення <b>{counts.notifications}</b>
          </Link>
        )}
        <p className="nav-label">РОБОЧІ ІНСТРУМЕНТИ</p>
        {planFeatures.calendar && <Link className="nav-item" to="/calendar">
          <span><Icon name="calendar" /></span> Календар <b>0</b>
        </Link>}
        <span className="nav-item disabled" aria-disabled="true">
          <span><Icon name="document" /></span> Документи <b>0</b>
        </span>
        <span className="nav-item disabled" aria-disabled="true">
          <span><Icon name="chart" /></span> Аналітика <b>0</b>
        </span>
      </nav>
      <div className="sidebar-bottom">
        <button className="logout" onClick={logout}>
          <span><Icon name="logout" /></span> Вийти
        </button>
      </div>
    </aside>
  );
}
