import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import Layout from "../components/Layout.jsx";
import { api, post } from "../services/api.js";
import { ClientFormModal, emptyForm, serviceAppointmentTaskPayload, serviceTaskPayload, validateAppointmentServices } from "./Clients.jsx";
import { isPlanLimitReached, planLimitHint } from "../utils/planLimits.js";
import "./clients.css";

export default function ClientCreatePage() {
  const navigate = useNavigate();
  const [user, setUser] = useState(null);
  const [availableUsers, setAvailableUsers] = useState([]);
  const [teamUsers, setTeamUsers] = useState([]);
  const [availableUsersByClient, setAvailableUsersByClient] = useState({});
  const [adminWorkspaces, setAdminWorkspaces] = useState([]);
  const [workspaceOwnerId, setWorkspaceOwnerId] = useState("");
  const [servicePackages, setServicePackages] = useState([]);
  const [standaloneServices, setStandaloneServices] = useState([]);
  const [calendarTasks, setCalendarTasks] = useState([]);
  const [form, setForm] = useState({
    ...emptyForm,
    module: window.localStorage.getItem("nexaflow.client.module") || "accounting",
  });
  const [error, setError] = useState("");

  useEffect(() => {
    api("/api/clients.php").then(async (result) => {
      if (!result.ok) return navigate("/", { replace: true });
      if (!result.permissions?.manage) return navigate("/clients", { replace: true });
      setUser({ ...result.user, active_client_module: result.active_client_module || "accounting" });
      window.localStorage.setItem("nexaflow.client.module", result.active_client_module || "accounting");
      setAvailableUsers(result.available_users || []);
      setTeamUsers(result.team_users || []);
      setAvailableUsersByClient(result.available_users_by_client || {});
      const owners = result.admin_workspaces || [];
      setAdminWorkspaces(owners);
      setWorkspaceOwnerId(owners.find(owner => String(owner.id) === String(result.user?.id))?.id || owners[0]?.id || "");
    });
  }, [navigate]);

  useEffect(() => {
    if (!user) return;
    const isAdmin = ["admin", "owner"].includes(user.role);
    if (isAdmin && !workspaceOwnerId) return;
    const clientsUrl = isAdmin ? `/api/clients.php?workspace_owner_id=${encodeURIComponent(workspaceOwnerId)}` : "/api/clients.php";
    const activeModule = user.active_client_module || "accounting";
    const servicesUrl = isAdmin ? `/api/services.php?module=${encodeURIComponent(activeModule)}&scope=all&owner_id=${encodeURIComponent(workspaceOwnerId)}` : `/api/services.php?module=${encodeURIComponent(activeModule)}`;
    Promise.all([api(clientsUrl), api(servicesUrl), api("/api/tasks.php")]).then(([clientsResult, packagesResult, tasksResult]) => {
      if (clientsResult.ok) { setAvailableUsers(clientsResult.available_users || []); setTeamUsers(clientsResult.team_users || []); setAvailableUsersByClient(clientsResult.available_users_by_client || {}); }
      if (tasksResult.ok) setCalendarTasks(tasksResult.tasks || []);
    if (packagesResult.ok) {
      setServicePackages((packagesResult.packages || []).filter((item) => item.active !== false));
      setStandaloneServices((packagesResult.standalone_services || []).filter((item) => item.active !== false));
    }
    });
  }, [user?.id, user?.role, workspaceOwnerId]);

  // During creation the client does not have an id yet. Keep service additions
  // in the form locally; they will be persisted together with the client.
  async function addService(nextForm) {
    setForm(nextForm);
    return true;
  }

  async function save(event) {
    event.preventDefault();
    setError("");
    const submittedName = new FormData(event.currentTarget).get("name");
    if ((form.services || []).some((service) => service.add_to_calendar)) {
      if (!user?.subscription?.features?.calendar) return setError("У вашому тарифі немає доступу до календаря.");
      const latestTasks = await api("/api/tasks.php");
      const appointmentError = validateAppointmentServices(form.services, latestTasks.ok ? latestTasks.tasks || [] : calendarTasks, form.module_data);
      if (appointmentError) return setError(appointmentError);
    }
    const submittedForm = { ...form, name: typeof submittedName === "string" ? submittedName.trim() : form.name, workspace_owner_id: workspaceOwnerId };
    const result = await post("/api/clients.php", { ...submittedForm, workspace_owner_id: workspaceOwnerId, action: "create" });
    if (!result.ok) return setError(result.message);
    const clientId = result.id || result.data?.id;
    const services = [...(submittedForm.services || [])];
    const createdTaskIds = [];
    const rollback = async () => {
      await Promise.all(createdTaskIds.map((taskId) => post("/api/tasks.php", { action: "delete", id: taskId })));
      await post("/api/clients.php", { action: "delete", id: clientId });
    };
    for (let index = 0; index < services.length; index += 1) {
      const service = services[index];
      const createAppointment = Boolean(service.add_to_calendar && service.appointment_at && !service.appointment_task_id);
      const createServiceTask = Boolean(service.create_task && !service.task_id);
      if ((!createAppointment && !createServiceTask) || !service.name) continue;
      for (const kind of ["appointment", "service"]) {
        if (kind === "appointment" && !createAppointment) continue;
        if (kind === "service" && !createServiceTask) continue;
        const payload = kind === "appointment"
          ? serviceAppointmentTaskPayload(submittedForm, service, clientId)
          : serviceTaskPayload(submittedForm, service, clientId);
        const taskResult = await post("/api/tasks.php", payload);
        if (!taskResult.ok) {
          await rollback();
          return setError(`Не вдалося зберегти клієнта і пов’язаний запис або задачу: ${taskResult.message}`);
        }
        if (taskResult.task_id) createdTaskIds.push(taskResult.task_id);
        services[index] = kind === "appointment"
          ? { ...services[index], appointment_task_id: taskResult.task_id || "" }
          : { ...services[index], task_id: taskResult.task_id || "" };
      }
    }
    if (services.some((service, index) => service.task_id !== (form.services || [])[index]?.task_id || service.appointment_task_id !== (form.services || [])[index]?.appointment_task_id)) {
      const updateResult = await post("/api/clients.php", { ...submittedForm, services, action: "update", id: clientId });
      if (!updateResult.ok) {
        await rollback();
        return setError(`Не вдалося завершити збереження клієнта: ${updateResult.message}`);
      }
    }
    navigate("/clients", { state: { notice: result.message } });
  }

  if (!user) return <div className="loading-screen">Завантаження форми клієнта…</div>;
  const clientLimitReached = isPlanLimitReached(user.subscription, "clients");
  return (
    <Layout page="Новий клієнт" user={user}>
      <div className="content clients-page client-create-page">
        <div className="client-create-topline"><button type="button" className="back-link" onClick={() => navigate("/clients")}>← До клієнтів</button></div>
        <section className="welcome-row clients-hero">
          <div><p className="eyebrow">РОБОЧИЙ ПРОСТІР</p><h1>Створити клієнта</h1><p className="subtext">Заповніть дані клієнта за полями активного модуля.</p></div>
        </section>
        {error && <div className="form-notice">{error}</div>}
        {user && ["admin", "owner"].includes(user.role) && <label className="admin-client-workspace-select">Простір-власник клієнта<select value={workspaceOwnerId} onChange={event => setWorkspaceOwnerId(event.target.value)} required>{adminWorkspaces.map(owner => <option key={owner.id} value={owner.id}>{owner.name} · {owner.email}</option>)}</select></label>}
        {clientLimitReached && <div className="form-notice plan-limit-message">{planLimitHint(user.subscription, "clients")}</div>}
        <ClientFormModal form={form} setForm={setForm} editing={null} availableUsers={availableUsers} teamUsers={teamUsers} availableUsersByClient={availableUsersByClient} servicePackages={servicePackages} standaloneServices={standaloneServices} calendarTasks={calendarTasks} currentUserId={user?.id || ""} subscription={user?.subscription} onSave={save} onAddService={addService} onClose={() => navigate("/clients")} pageMode />
      </div>
    </Layout>
  );
}
