import { lazy, Suspense, useEffect, useLayoutEffect, useState } from "react";
import { Navigate, Route, Routes } from "react-router-dom";
import Icon from './components/Icon.jsx';
import './components/maintenance-warning.css';
const Login = lazy(() => import("./pages/Login.jsx"));
const ClientAccess = lazy(() => import("./pages/ClientAccess.jsx"));
const Dashboard = lazy(() => import("./pages/Dashboard.jsx"));
const Team = lazy(() => import("./pages/Team.jsx"));
const AcceptInvite = lazy(() => import("./pages/AcceptInvite.jsx"));
const NotificationsPage = lazy(() => import("./pages/NotificationsPage.jsx"));
const Projects = lazy(() => import("./pages/Projects.jsx"));
const ProjectCreatePage = lazy(() => import("./pages/ProjectCreatePage.jsx"));
const ProjectEditPage = lazy(() => import("./pages/ProjectEditPage.jsx"));
const Tasks = lazy(() => import("./pages/Tasks.jsx"));
const TaskDetail = lazy(() => import("./pages/TaskDetail.jsx"));
const Settings = lazy(() => import("./pages/Settings.jsx"));
const VerifyEmail = lazy(() => import("./pages/VerifyEmail.jsx"));
const ResetPassword = lazy(() => import("./pages/ResetPassword.jsx"));
const Clients = lazy(() => import("./pages/Clients.jsx"));
const ClientCreatePage = lazy(() => import("./pages/ClientCreatePage.jsx"));
const ClientEditPage = lazy(() => import("./pages/ClientEditPage.jsx"));
const Calendar = lazy(() => import("./pages/Calendar.jsx"));
const Services = lazy(() => import("./pages/Services.jsx"));
import Landing from "./pages/Landing.jsx";
import PlanFeatureRoute from "./components/PlanFeatureRoute.jsx";
import EmailVerifiedRoute from "./components/EmailVerifiedRoute.jsx";
import RouteErrorBoundary from "./components/RouteErrorBoundary.jsx";
const Support = lazy(() => import("./pages/Support.jsx"));
const Appointments = lazy(() => import("./pages/Appointments.jsx"));
const AppointmentEditPage = lazy(() => import("./pages/AppointmentEditPage.jsx"));
const AppointmentDetailPage = lazy(() => import("./pages/AppointmentDetailPage.jsx"));
import DentistryModuleRoute from "./components/DentistryModuleRoute.jsx";
const DentistryPatientPage = lazy(() => import("./pages/DentistryPatientPage.jsx"));
const DentistryERPPage = lazy(() => import("./pages/DentistryERPPage.jsx"));
const Accounting = lazy(() => import("./pages/Accounting.jsx"));
const AccountingRequisites = lazy(() => import("./pages/AccountingRequisites.jsx"));
const AccountingPortal = lazy(() => import("./pages/AccountingPortal.jsx"));
const AdminPage = lazy(() => import("./pages/AdminPage.jsx"));
const BannedPage = lazy(() => import("./pages/BannedPage.jsx"));
const FAQ = lazy(() => import("./pages/FAQ.jsx"));

export default function App() {
  const [maintenanceStarts, setMaintenanceStarts] = useState(null);
  const [maintenanceNoticeMinutes, setMaintenanceNoticeMinutes] = useState(5);
  const [now, setNow] = useState(Date.now());
  useEffect(() => {
    let active = true;
    const check = () => fetch('/api/maintenance-status.php', { cache: 'no-store', credentials: 'same-origin' })
      .then(response => response.json())
      .then(state => {
        if (!active) return;
        if (!state.ok || (state.enabled && !state.is_admin)) { window.location.replace('/maintenance-page.php'); return; }
        setMaintenanceStarts(state.starts_at_epoch ? Number(state.starts_at_epoch) * 1000 : null);
        setMaintenanceNoticeMinutes(Math.max(1, Number(state.notice_minutes) || 5));
      })
      .catch(() => { if (active) window.location.replace('/maintenance-page.php'); });
    check();
    const poll = window.setInterval(check, 15000);
    const clock = window.setInterval(() => setNow(Date.now()), 1000);
    return () => { active = false; window.clearInterval(poll); window.clearInterval(clock); };
  }, []);
  useLayoutEffect(() => {
    const reloadForStaleBundle = (event) => {
      event.preventDefault();
      const key = `nexaflow.bundle-reload:${window.location.pathname}`;
      if (window.sessionStorage.getItem(key)) return;
      window.sessionStorage.setItem(key, '1');
      window.location.reload();
    };
    window.addEventListener('vite:preloadError', reloadForStaleBundle);
    return () => window.removeEventListener('vite:preloadError', reloadForStaleBundle);
  }, []);

  useLayoutEffect(() => {
    document.documentElement.classList.remove('nf-spa-route-loading');
  }, []);

  const secondsLeft = maintenanceStarts ? Math.max(0, Math.ceil((maintenanceStarts - now) / 1000)) : 0;
  return (
    <>{maintenanceStarts && <aside className={`maintenance-warning${secondsLeft <= 60 ? ' maintenance-warning-urgent' : ''}`} aria-label="Попередження про технічні роботи">
      <div className="maintenance-warning-main">
        <span className="maintenance-warning-icon"><Icon name="hourglass" /></span>
        <div className="maintenance-warning-copy"><strong>Незабаром технічні роботи</strong><span>Збережіть введені дані та завершіть поточну дію.</span></div>
        <span className="maintenance-warning-timer" aria-label={`До початку ${Math.floor(secondsLeft / 60)} хвилин ${secondsLeft % 60} секунд`}><Icon name="clock" />{Math.floor(secondsLeft / 60)}:{String(secondsLeft % 60).padStart(2, '0')}</span>
      </div>
      <div className="maintenance-warning-track" aria-hidden="true"><span style={{ width: `${Math.min(100, secondsLeft / (maintenanceNoticeMinutes * 60) * 100)}%` }} /></div>
    </aside>}
    <RouteErrorBoundary><Suspense fallback={<div className="loading-screen" role="status">Завантажуємо сторінку…</div>}><Routes>
      <Route path="/" element={<Landing />} />
      <Route path="/login" element={<Login initialMode="login" />} />
      <Route path="/client/login" element={<ClientAccess mode="login" />} />
      <Route path="/client/register" element={<ClientAccess mode="register" />} />
      <Route path="/register" element={<Login initialMode="register" />} />
      <Route path="/settings" element={<Settings />} />
      <Route element={<EmailVerifiedRoute />}>
      <Route path="/dashboard" element={<Dashboard />} />
      <Route path="/team" element={<Team />} />
      <Route path="/notifications" element={<NotificationsPage />} />
      <Route path="/clients" element={<Clients />} />
      <Route path="/clients/new" element={<ClientCreatePage />} />
      <Route path="/clients/:id/edit" element={<ClientEditPage />} />
      <Route path="/accounting" element={<PlanFeatureRoute feature="accounting"><Accounting /></PlanFeatureRoute>} />
      <Route path="/accounting/requisites" element={<PlanFeatureRoute feature="accounting"><AccountingRequisites /></PlanFeatureRoute>} />
      <Route path="/accounting/portal" element={<AccountingPortal />} />
      <Route path="/patients/:id" element={<DentistryModuleRoute><DentistryPatientPage /></DentistryModuleRoute>} />
      <Route path="/dentistry-erp" element={<DentistryModuleRoute><PlanFeatureRoute feature="dental_inventory"><DentistryERPPage /></PlanFeatureRoute></DentistryModuleRoute>} />
      <Route path="/dentistry/inventory" element={<DentistryModuleRoute><PlanFeatureRoute feature="dental_inventory"><DentistryERPPage key="inventory" module="inventory" /></PlanFeatureRoute></DentistryModuleRoute>} />
      <Route path="/dentistry/purchases" element={<DentistryModuleRoute><PlanFeatureRoute feature="dental_procurement"><DentistryERPPage key="procurement" module="procurement" /></PlanFeatureRoute></DentistryModuleRoute>} />
      <Route path="/dentistry/accounting" element={<DentistryModuleRoute><PlanFeatureRoute feature="dental_management_accounting"><DentistryERPPage key="accounting" module="accounting" /></PlanFeatureRoute></DentistryModuleRoute>} />
      <Route path="/dentistry/payroll" element={<DentistryModuleRoute><PlanFeatureRoute feature="dental_doctor_payroll"><DentistryERPPage key="payroll" module="payroll" /></PlanFeatureRoute></DentistryModuleRoute>} />
      <Route path="/dentistry/equipment" element={<DentistryModuleRoute><PlanFeatureRoute feature="dental_equipment"><DentistryERPPage key="equipment" module="equipment" /></PlanFeatureRoute></DentistryModuleRoute>} />
      <Route path="/calendar" element={<PlanFeatureRoute feature="calendar"><Calendar /></PlanFeatureRoute>} />
      <Route path="/faq" element={<FAQ />} />
      <Route path="/appointments" element={<PlanFeatureRoute feature="calendar"><DentistryModuleRoute><Appointments /></DentistryModuleRoute></PlanFeatureRoute>} />
      <Route path="/appointments/:id" element={<PlanFeatureRoute feature="calendar"><DentistryModuleRoute><AppointmentDetailPage /></DentistryModuleRoute></PlanFeatureRoute>} />
      <Route path="/appointments/:id/edit" element={<PlanFeatureRoute feature="calendar"><DentistryModuleRoute><AppointmentEditPage /></DentistryModuleRoute></PlanFeatureRoute>} />
      <Route path="/support" element={<PlanFeatureRoute feature="support"><Support /></PlanFeatureRoute>} />
      <Route path="/services" element={<PlanFeatureRoute feature="services"><Services /></PlanFeatureRoute>} />
      <Route path="/projects" element={<Projects />} />
      <Route path="/projects/new" element={<ProjectCreatePage />} />
      <Route path="/projects/:id" element={<ProjectEditPage />} />
      <Route path="/tasks" element={<Tasks />} />
      <Route path="/tasks/:id" element={<TaskDetail />} />
      <Route path="/admin" element={<AdminPage />} />
      </Route>
      <Route path="/banned" element={<BannedPage />} />
      <Route path="/verify-email" element={<VerifyEmail />} />
      <Route path="/reset-password" element={<ResetPassword />} />
      <Route path="/accept-invite" element={<AcceptInvite />} />
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes></Suspense></RouteErrorBoundary></>
  );
}
