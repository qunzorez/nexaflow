import { lazy, Suspense } from "react";
import { Navigate, Route, Routes } from "react-router-dom";
const Login = lazy(() => import("./pages/Login.jsx"));
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
const Support = lazy(() => import("./pages/Support.jsx"));
const Appointments = lazy(() => import("./pages/Appointments.jsx"));
const AppointmentEditPage = lazy(() => import("./pages/AppointmentEditPage.jsx"));
const AppointmentDetailPage = lazy(() => import("./pages/AppointmentDetailPage.jsx"));
import DentistryModuleRoute from "./components/DentistryModuleRoute.jsx";
const DentistryPatientPage = lazy(() => import("./pages/DentistryPatientPage.jsx"));
const DentistryERPPage = lazy(() => import("./pages/DentistryERPPage.jsx"));
const Accounting = lazy(() => import("./pages/Accounting.jsx"));
const AdminPage = lazy(() => import("./pages/AdminPage.jsx"));
const BannedPage = lazy(() => import("./pages/BannedPage.jsx"));
const FAQ = lazy(() => import("./pages/FAQ.jsx"));

export default function App() {
  return (
    <Suspense fallback={<div className="loading-screen">Завантаження…</div>}><Routes>
      <Route path="/" element={<Landing />} />
      <Route path="/login" element={<Login initialMode="login" />} />
      <Route path="/register" element={<Login initialMode="register" />} />
      <Route path="/dashboard" element={<Dashboard />} />
      <Route path="/team" element={<Team />} />
      <Route path="/notifications" element={<NotificationsPage />} />
      <Route path="/clients" element={<Clients />} />
      <Route path="/clients/new" element={<ClientCreatePage />} />
      <Route path="/clients/:id/edit" element={<ClientEditPage />} />
      <Route path="/accounting" element={<PlanFeatureRoute feature="accounting"><Accounting /></PlanFeatureRoute>} />
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
      <Route path="/settings" element={<Settings />} />
      <Route path="/admin" element={<AdminPage />} />
      <Route path="/banned" element={<BannedPage />} />
      <Route path="/verify-email" element={<VerifyEmail />} />
      <Route path="/reset-password" element={<ResetPassword />} />
      <Route path="/accept-invite" element={<AcceptInvite />} />
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes></Suspense>
  );
}
