import Icon from './Icon.jsx';
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faInstagram, faTiktok, faTelegram } from "@fortawesome/free-brands-svg-icons";
import { useEffect, useRef, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import { api, post } from "../services/api.js";
import "./sidebar-icons.css";
import "./support-nav.css";

export default function Sidebar({ user, open, onNavigate }) {
  const location = useLocation();
  const workspaces = user?.workspaces || [];
  const activeWorkspaceId = user?.active_workspace_id || user?.subscription?.workspace_id || "";
  const workspaceCacheKey = `${user?.id || "guest"}.${activeWorkspaceId || "default"}`;
  const countsStorageKey = `nexaflow.sidebar.counts.${workspaceCacheKey}`;
  const featuresStorageKey = `nexaflow.sidebar.features.${workspaceCacheKey}`;
  const [counts, setCounts] = useState(() => {
    try {
      return { clients: 0, projects: 0, tasks: 0, team: 0, notifications: 0, support: 0, ...JSON.parse(window.localStorage.getItem(countsStorageKey) || "{}") };
    } catch {
      return { clients: 0, projects: 0, tasks: 0, team: 0, notifications: 0, support: 0 };
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
  const [activeClientModule, setActiveClientModule] = useState(() => {
    try { return window.localStorage.getItem(`nexaflow.client.module.${workspaceCacheKey}`) || user?.active_client_module || ""; }
    catch { return user?.active_client_module || ""; }
  });
  const [workspaceMenuOpen, setWorkspaceMenuOpen] = useState(false);
  const workspaceMenuRef = useRef(null);
  const userRole = String(user?.workspace_role || user?.role || "").toLowerCase();
  const globalAdmin = ["admin", "owner"].includes(String(user?.role || "").toLowerCase());
  const canManage = ["admin", "owner", "manager", "project_lead"].includes(userRole) || globalAdmin;
  const canManageRequisites = ["admin", "owner", "manager"].includes(userRole) || globalAdmin;
  const isAdmin = ["admin", "owner"].includes(userRole) || globalAdmin;
  const canUseSupport = isAdmin || ["admin", "owner"].includes(String(user?.role || "").toLowerCase()) || Number(user?.is_support_agent) === 1 || Boolean(planFeatures.support);
  const dentalNavFeatures = ["dental_inventory", "dental_procurement", "dental_management_accounting", "dental_doctor_payroll", "dental_equipment"];
  const hasDentalNav = dentalNavFeatures.some(feature => Boolean(planFeatures[feature]));
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
      const [dashboard, notifications, clients, supportUnread] = await Promise.all([
        api("/api/dashboard.php"),
        api("/api/notifications.php"),
        api("/api/clients.php"),
        canUseSupport && location.pathname !== "/support" ? api("/api/support.php?action=unread") : Promise.resolve({ unread: 0 }),
      ]);
      if (!active) return;
      if (clients.active_client_module) {
        setActiveClientModule(clients.active_client_module);
        window.localStorage.setItem(`nexaflow.client.module.${workspaceCacheKey}`, clients.active_client_module);
      }
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
        support: window.location.pathname === "/support" ? 0 : Number(supportUnread.unread || 0),
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
  }, [canUseSupport, countsStorageKey, featuresStorageKey, location.pathname, user?.id, workspaceCacheKey]);
  function clearSupportUnread() {
    setCounts(current => {
      const next = { ...current, support: 0 };
      window.localStorage.setItem(countsStorageKey, JSON.stringify(next));
      return next;
    });
  }
  useEffect(() => {
    if (location.pathname === "/support") clearSupportUnread();
  }, [location.pathname, countsStorageKey]);
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
    <aside id="app-sidebar" className={`sidebar ${open ? "open" : ""}`} onClick={event => event.target.closest('a') && onNavigate?.()}>
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
          <span><Icon name="home" /></span> Огляд {activeClientModule !== "dentistry" && <b>{counts.tasks}</b>}
        </Link>
        <Link className="nav-item" to="/clients">
          <span><Icon name="users" /></span> Клієнти <b>{counts.clients}</b>
        </Link>
        {activeClientModule !== "dentistry" && <Link className="nav-item" to="/projects">
          <span><Icon name="project" /></span> Проєкти <b>{counts.projects}</b>
        </Link>}
        {activeClientModule !== "dentistry" && <Link className="nav-item" to="/tasks">
          <span><Icon name="tasks" /></span> Задачі <b>{counts.tasks}</b>
        </Link>}
        {activeClientModule === "dentistry" && planFeatures.calendar && <Link className="nav-item" to="/appointments">
          <span><Icon name="calendar" /></span> Записи
        </Link>}
        {(canManage || (userRole === "user" && String(activeWorkspaceId) !== String(user?.id))) && (
          <Link className="nav-item" to="/team">
            <span className="nav-icon">
              <Icon name="users" />
            </span>{" "}
            Команда <b>{counts.team}</b>
          </Link>
        )}
        {(isAdmin || (canManage && planFeatures.services)) && <Link className="nav-item" to="/services">
          <span><Icon name="subscription" /></span> Послуги
        </Link>}
        {activeClientModule === "accounting" && planFeatures.accounting && <Link className="nav-item" to="/accounting">
          <span><Icon name="settings" /></span> Податкові налаштування
        </Link>}
        {activeClientModule === "accounting" && planFeatures.accounting && canManageRequisites && <Link className="nav-item" to="/accounting/requisites">
          <span><Icon name="briefcase" /></span> Реквізити
        </Link>}
        {activeClientModule === "dentistry" && hasDentalNav && <>
          <p className="nav-label">ОБЛІК КЛІНІКИ</p>
          {planFeatures.dental_inventory && <Link className="nav-item" to="/dentistry/inventory"><span><Icon name="archive" /></span> Склад</Link>}
          {planFeatures.dental_procurement && <Link className="nav-item" to="/dentistry/purchases"><span><Icon name="briefcase" /></span> Закупівлі</Link>}
          {canManage && planFeatures.dental_management_accounting && <Link className="nav-item" to="/dentistry/accounting"><span><Icon name="chart" /></span> Бухгалтерія</Link>}
          {canManage && planFeatures.dental_doctor_payroll && <Link className="nav-item" to="/dentistry/payroll"><span><Icon name="users" /></span> Зарплати</Link>}
          {planFeatures.dental_equipment && <Link className="nav-item" to="/dentistry/equipment"><span><Icon name="settings" /></span> Обладнання</Link>}
        </>}
        {isAdmin && (
          <Link className="nav-item" to="/notifications">
            <span className="nav-icon">
              <Icon name="bell" />
            </span>{" "}
            Сповіщення <b>{counts.notifications}</b>
          </Link>
        )}
        {isAdmin && <Link className="nav-item" to="/admin"><span><Icon name="settings" /></span> Адмінпанель</Link>}
        <p className="nav-label">РОБОЧІ ІНСТРУМЕНТИ</p>
        {planFeatures.calendar && <Link className="nav-item" to="/calendar">
          <span><Icon name="calendar" /></span> Календар
        </Link>}
        {canUseSupport && <Link className="nav-item" to="/support" onClick={clearSupportUnread}>
          <span><Icon name="question" /></span> Онлайн підтримка {counts.support > 0 && <b className="support-unread-badge" aria-label={`${counts.support} непрочитаних повідомлень`}>{counts.support}</b>}
        </Link>}
        <Link className="nav-item" to="/faq">
          <span><Icon name="question" /></span> FAQ · Як користуватися
        </Link>
        <div className="sidebar-social-block">
          <p className="nav-label">СОЦІАЛЬНІ МЕРЕЖІ</p>
          <a href="https://www.instagram.com/nexaflow.top/" target="_blank" rel="noreferrer"><span aria-hidden="true"><FontAwesomeIcon icon={faInstagram} /></span> Instagram</a>
          <a href="https://www.tiktok.com/@nexaflowtop" target="_blank" rel="noreferrer"><span aria-hidden="true"><FontAwesomeIcon icon={faTiktok} /></span> TikTok</a>
          <a href="https://t.me/nexaflow_ua" target="_blank" rel="noreferrer"><span aria-hidden="true"><FontAwesomeIcon icon={faTelegram} /></span> Telegram</a>
        </div>
      </nav>
    </aside>
  );
}
