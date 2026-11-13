import Icon from './Icon.jsx';
import { useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { api, post } from "../services/api.js";
import "./sidebar-icons.css";

export default function Sidebar({ user, open }) {
  const navigate = useNavigate();
  const countsStorageKey = `nexaflow.sidebar.counts.${user?.id || "guest"}`;
  const [counts, setCounts] = useState(() => {
    try {
      return { clients: 0, projects: 0, tasks: 0, team: 0, notifications: 0, ...JSON.parse(window.localStorage.getItem(countsStorageKey) || "{}") };
    } catch {
      return { clients: 0, projects: 0, tasks: 0, team: 0, notifications: 0 };
    }
  });
  const userRole = String(user?.role || "").toLowerCase();
  const canManage = ["admin", "owner", "manager"].includes(userRole);
  const isAdmin = ["admin", "owner"].includes(userRole);
  useEffect(() => {
    let active = true;
    const loadCounts = async () => {
      const [dashboard, notifications, clients] = await Promise.all([
        api("/api/dashboard.php"),
        api("/api/notifications.php"),
        api("/api/clients.php"),
      ]);
      if (!active) return;
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
  }, [countsStorageKey, user?.id]);
  async function logout() {
    await post("/api/logout.php", {});
    navigate("/");
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
        {isAdmin && (
          <Link className="nav-item" to="/notifications">
            <span className="nav-icon">
              <Icon name="bell" />
            </span>{" "}
            Сповіщення <b>{counts.notifications}</b>
          </Link>
        )}
        <p className="nav-label">РОБОЧІ ІНСТРУМЕНТИ</p>
        {canManage && <Link className="nav-item" to="/services">
          <span><Icon name="subscription" /></span> Послуги
        </Link>}
        <Link className="nav-item" to="/calendar">
          <span><Icon name="calendar" /></span> Календар <b>0</b>
        </Link>
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
