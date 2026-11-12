import { useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { api, post } from "../services/api.js";
import "./sidebar-icons.css";

export default function Sidebar({ user, open }) {
  const navigate = useNavigate();
  const [counts, setCounts] = useState({
    clients: 0,
    projects: 0,
    tasks: 0,
    team: 0,
    notifications: 0,
  });
  const canManage = ["admin", "owner", "manager"].includes(user?.role);
  const isAdmin = ["admin", "owner"].includes(user?.role);
  useEffect(() => {
    let active = true;
    const loadCounts = async () => {
      const [dashboard, notifications, clients] = await Promise.all([
        api("/api/dashboard.php"),
        api("/api/notifications.php"),
        api("/api/clients.php"),
      ]);
      if (!active) return;
      setCounts({
        clients: Number(clients.clients?.length || 0),
        projects: Number(
          dashboard.metrics?.projects || dashboard.projects?.length || 0,
        ),
        tasks: Number(dashboard.metrics?.tasks || 0),
        team: Number(dashboard.users?.length || 0),
        notifications: Number(notifications.unread || 0),
      });
    };
    loadCounts();
    const interval = window.setInterval(loadCounts, 15000);
    return () => {
      active = false;
      window.clearInterval(interval);
    };
  }, [user?.id]);
  async function logout() {
    await post("/api/logout.php", {});
    navigate("/");
  }
  return (
    <aside className={`sidebar ${open ? "open" : ""}`}>
      <Link className="side-brand" to="/dashboard">
        <div className="brand-mark">
          <span>+</span>
        </div>
        <div>
          <strong>NEXAFLOW</strong>
          <small>SRM SYSTEM</small>
        </div>
      </Link>
      <nav>
        <p className="nav-label">ОСНОВНЕ</p>
        <Link className="nav-item" to="/dashboard">
          <span>⌂</span> Огляд <b>{counts.tasks}</b>
        </Link>
        <Link className="nav-item" to="/clients">
          <span>◎</span> Клієнти <b>{counts.clients}</b>
        </Link>
        <Link className="nav-item" to="/projects">
          <span>▣</span> Проєкти <b>{counts.projects}</b>
        </Link>
        <Link className="nav-item" to="/tasks">
          <span>✓</span> Задачі <b>{counts.tasks}</b>
        </Link>
        {canManage && (
          <Link className="nav-item" to="/team">
            <span className="nav-icon">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="9" cy="8" r="3" />
                <path d="M3.5 19a5.5 5.5 0 0 1 11 0M16 6.5a2.5 2.5 0 0 1 0 5M16 14a5 5 0 0 1 4.5 5" />
              </svg>
            </span>{" "}
            Команда <b>{counts.team}</b>
          </Link>
        )}
        {isAdmin && (
          <Link className="nav-item" to="/notifications">
            <span className="nav-icon">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" />
              </svg>
            </span>{" "}
            Сповіщення <b>{counts.notifications}</b>
          </Link>
        )}
        <p className="nav-label">РОБОЧІ ІНСТРУМЕНТИ</p>
        <a className="nav-item disabled" href="#">
          <span>◷</span> Календар <b>0</b>
        </a>
        <a className="nav-item disabled" href="#">
          <span>▤</span> Документи <b>0</b>
        </a>
        <a className="nav-item disabled" href="#">
          <span>⌁</span> Аналітика <b>0</b>
        </a>
      </nav>
      <div className="sidebar-bottom">
        <button className="logout" onClick={logout}>
          <span>↪</span> Вийти
        </button>
      </div>
    </aside>
  );
}
