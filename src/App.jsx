import { Navigate, Route, Routes } from "react-router-dom";
import Login from "./pages/Login.jsx";
import Dashboard from "./pages/Dashboard.jsx";
import Team from "./pages/Team.jsx";
import AcceptInvite from "./pages/AcceptInvite.jsx";
import NotificationsPage from "./pages/NotificationsPage.jsx";
import Projects from "./pages/Projects.jsx";
import ProjectCreatePage from "./pages/ProjectCreatePage.jsx";
import ProjectEditPage from "./pages/ProjectEditPage.jsx";
import Tasks from "./pages/Tasks.jsx";
import TaskDetail from "./pages/TaskDetail.jsx";
import Settings from "./pages/Settings.jsx";
import VerifyEmail from "./pages/VerifyEmail.jsx";
import ResetPassword from "./pages/ResetPassword.jsx";
import Clients from "./pages/Clients.jsx";
import ClientCreatePage from "./pages/ClientCreatePage.jsx";
import ClientEditPage from "./pages/ClientEditPage.jsx";
import Calendar from "./pages/Calendar.jsx";
import Services from "./pages/Services.jsx";
import Landing from "./pages/Landing.jsx";
import PlanFeatureRoute from "./components/PlanFeatureRoute.jsx";
import Support from "./pages/Support.jsx";
import Appointments from "./pages/Appointments.jsx";
import AppointmentEditPage from "./pages/AppointmentEditPage.jsx";
import AppointmentDetailPage from "./pages/AppointmentDetailPage.jsx";
import DentistryModuleRoute from "./components/DentistryModuleRoute.jsx";
import DentistryPatientPage from "./pages/DentistryPatientPage.jsx";
import DentistryERPPage from "./pages/DentistryERPPage.jsx";

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<Landing />} />
      <Route path="/login" element={<Login initialMode="login" />} />
      <Route path="/register" element={<Login initialMode="register" />} />
      <Route path="/dashboard" element={<Dashboard />} />
      <Route path="/team" element={<Team />} />
      <Route path="/notifications" element={<NotificationsPage />} />
      <Route path="/clients" element={<Clients />} />
      <Route path="/clients/new" element={<ClientCreatePage />} />
      <Route path="/clients/:id/edit" element={<ClientEditPage />} />
      <Route path="/patients/:id" element={<DentistryModuleRoute><DentistryPatientPage /></DentistryModuleRoute>} />
      <Route path="/dentistry-erp" element={<DentistryModuleRoute><DentistryERPPage /></DentistryModuleRoute>} />
      <Route path="/calendar" element={<PlanFeatureRoute feature="calendar"><Calendar /></PlanFeatureRoute>} />
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
      <Route path="/verify-email" element={<VerifyEmail />} />
      <Route path="/reset-password" element={<ResetPassword />} />
      <Route path="/accept-invite" element={<AcceptInvite />} />
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
