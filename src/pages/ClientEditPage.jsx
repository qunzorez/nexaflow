import { useEffect, useRef, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import Layout from "../components/Layout.jsx";
import { api, post } from "../services/api.js";
import { ClientFormModal, emptyForm, serviceAppointmentTaskPayload, serviceTaskPayload, validateAppointmentServices } from "./Clients.jsx";
import "./clients.css";

export default function ClientEditPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [user, setUser] = useState(null);
  const [form, setForm] = useState(null);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [availableUsers, setAvailableUsers] = useState([]);
  const [teamUsers, setTeamUsers] = useState([]);
  const [availableUsersByClient, setAvailableUsersByClient] = useState({});
  const [servicePackages, setServicePackages] = useState([]);
  const [standaloneServices, setStandaloneServices] = useState([]);
  const [calendarTasks, setCalendarTasks] = useState([]);
  const [adminWorkspaces, setAdminWorkspaces] = useState([]);
  const [workspaceOwnerId, setWorkspaceOwnerId] = useState("");
  const originalAppointmentTaskIds = useRef([]);

  useEffect(() => {
    api("/api/clients.php").then(async (result) => {
      if (!result.ok) return navigate("/", { replace: true });
      if (!result.permissions?.manage) return navigate("/clients", { replace: true });
      const client = result.clients.find((item) => String(item.id) === String(id));
      if (!client) return navigate("/clients", { replace: true });
      setUser(result.user);
      setAvailableUsers(result.available_users || []);
      setTeamUsers(result.team_users || []);
      setAvailableUsersByClient(result.available_users_by_client || {});
      setAdminWorkspaces(result.admin_workspaces || []);
      setWorkspaceOwnerId(client.created_by || "");
      setForm({
        ...emptyForm,
        ...client,
        has_vat: Boolean(Number(client.has_vat)),
        has_excise: Boolean(Number(client.has_excise)),
        has_employees: Boolean(Number(client.has_employees)),
        employee_count: client.employee_count || "",
        special_taxes: client.special_taxes || [],
        module: client.module || "accounting",
        module_data: {
          ...(client.module_data || {}),
          accountant_ids: client.module_data?.accountant_ids?.length
            ? client.module_data.accountant_ids.map(String)
            : [...new Set((client.services || []).flatMap(service => (service.accountant_ids || (service.accountant_id ? [service.accountant_id] : [])).map(String)))],
        },
        services: (client.services || []).map((service) => ({ ...service, accountant_ids: (service.accountant_ids || (service.accountant_id ? [service.accountant_id] : client.module_data?.accountant_ids || [])).map(String), is_package: Boolean(service.is_package || service.package_id) })),
      });
      originalAppointmentTaskIds.current = (client.services || []).map((service) => service.appointment_task_id).filter(Boolean);
    });
  }, [id, navigate]);

  useEffect(() => {
    if (!user || !form) return;
    const isAdmin = ["admin", "owner"].includes(user.role);
    if (isAdmin && !workspaceOwnerId) return;
    const clientsUrl = isAdmin ? `/api/clients.php?workspace_owner_id=${encodeURIComponent(workspaceOwnerId)}` : "/api/clients.php";
    const servicesUrl = isAdmin ? `/api/services.php?module=${encodeURIComponent(form.module || "accounting")}&scope=all&owner_id=${encodeURIComponent(workspaceOwnerId)}` : `/api/services.php?module=${encodeURIComponent(form.module || "accounting")}`;
    Promise.all([api(clientsUrl), api(servicesUrl), api("/api/tasks.php")]).then(([clientsResult, packagesResult, tasksResult]) => {
      if (clientsResult.ok) {
        setAvailableUsers(clientsResult.available_users || []);
        setTeamUsers(clientsResult.team_users || []);
        setAvailableUsersByClient(clientsResult.available_users_by_client || {});
      }
      if (packagesResult.ok) {
        setServicePackages((packagesResult.packages || []).filter((item) => item.active !== false));
        setStandaloneServices((packagesResult.standalone_services || []).filter((item) => item.active !== false));
      }
      if (tasksResult.ok) setCalendarTasks(tasksResult.tasks || []);
    });
  }, [user?.id, user?.role, form?.module, workspaceOwnerId, Boolean(form)]);

  async function save(event) {
    event.preventDefault();
    setError("");
    setNotice("");
    const submittedName = new FormData(event.currentTarget).get("name");
    if (form.module !== "dentistry" && (form.services || []).some((service) => service.add_to_calendar)) {
      if (!user?.subscription?.features?.calendar) return setError("У вашому тарифі немає доступу до календаря.");
      const latestTasks = await api("/api/tasks.php");
      const appointmentError = validateAppointmentServices(form.services, latestTasks.ok ? latestTasks.tasks || [] : calendarTasks, form.module_data);
      if (appointmentError) return setError(appointmentError);
    }
    const submittedForm = { ...form, name: typeof submittedName === "string" ? submittedName.trim() : form.name, workspace_owner_id: workspaceOwnerId };
    const result = await post("/api/clients.php", { ...submittedForm, workspace_owner_id: workspaceOwnerId, action: "update", id });
    if (!result.ok) return setError(result.message);
    if (result.client?.name) setForm((current) => ({ ...current, name: result.client.name }));
    if (form.module === "dentistry") {
      setNotice(result.message);
      return;
    }
    const services = [...(submittedForm.services || [])];
    let taskError = "";
    let tasksChanged = false;
    let tasksCreated = 0;
    const createdTaskIds = [];
    const cancelledTaskIds = new Set();
    const removeCreatedTasks = async () => Promise.all(createdTaskIds.map((taskId) => post("/api/tasks.php", { action: "delete", id: taskId })));
    for (let index = 0; index < services.length; index += 1) {
      let service = services[index];
      if (!service.name) continue;
      if (service.add_to_calendar && service.appointment_at) {
        const appointmentPayload = serviceAppointmentTaskPayload({ ...submittedForm, workspace_owner_id: workspaceOwnerId }, service, id);
        const appointmentResult = service.appointment_task_id
          ? await post("/api/tasks.php", { ...appointmentPayload, action: "update", id: service.appointment_task_id })
          : await post("/api/tasks.php", appointmentPayload);
        if (!appointmentResult.ok) {
          taskError = appointmentResult.message;
          continue;
        }
        if (!service.appointment_task_id && appointmentResult.task_id) {
          createdTaskIds.push(appointmentResult.task_id);
          service = { ...service, appointment_task_id: appointmentResult.task_id };
          services[index] = service;
          tasksChanged = true;
          tasksCreated += 1;
        }
      } else if (service.appointment_task_id) {
        const appointmentTaskId = service.appointment_task_id;
        const cancelResult = await post("/api/tasks.php", { action: "set_status", id: appointmentTaskId, status: "cancelled" });
        if (!cancelResult.ok) {
          taskError = cancelResult.message;
          continue;
        }
        service = { ...service, appointment_task_id: "" };
        services[index] = service;
        cancelledTaskIds.add(appointmentTaskId);
        tasksChanged = true;
      }
      if (service.create_task && !service.task_id) {
        const taskResult = await post("/api/tasks.php", serviceTaskPayload(submittedForm, service, id));
        if (!taskResult.ok) {
          taskError = taskResult.message;
          continue;
        }
        if (taskResult.task_id) createdTaskIds.push(taskResult.task_id);
        services[index] = { ...service, task_id: taskResult.task_id || "" };
        tasksChanged = true;
        tasksCreated += 1;
      }
    }
    const retainedAppointmentIds = new Set(services.map((service) => service.appointment_task_id).filter(Boolean));
    for (const appointmentTaskId of originalAppointmentTaskIds.current) {
      if (retainedAppointmentIds.has(appointmentTaskId) || cancelledTaskIds.has(appointmentTaskId)) continue;
      const cancelResult = await post("/api/tasks.php", { action: "set_status", id: appointmentTaskId, status: "cancelled" });
      if (!cancelResult.ok) taskError = cancelResult.message;
    }
    if (tasksChanged) {
      const taskSave = await post("/api/clients.php", { ...submittedForm, workspace_owner_id: workspaceOwnerId, services, action: "update", id });
      if (!taskSave.ok) {
        await removeCreatedTasks();
        return setError(`Дані клієнта збережено, але не вдалося прив’язати створені задачі: ${taskSave.message}`);
      }
      setForm((current) => ({ ...current, services }));
    }
    if (taskError) {
      setForm((current) => ({ ...current, services }));
      setError(`Дані клієнта збережено, але запис у календарі або пов’язану задачу не вдалося синхронізувати: ${taskError}`);
      return;
    }
    originalAppointmentTaskIds.current = services.map((service) => service.appointment_task_id).filter(Boolean);
    setNotice(tasksCreated > 0 ? `Зміни збережено. Створено задач: ${tasksCreated}.` : result.message);
  }

  async function saveAppointment(nextForm, serviceIndex) {
    const service = nextForm.services?.[serviceIndex];
    if (!service?.name || !service.add_to_calendar) return false;
    setError("");
    setNotice("");
    if (!user?.subscription?.features?.calendar) {
      setError("У вашому тарифі немає доступу до календаря.");
      return false;
    }
    const latestTasks = await api("/api/tasks.php");
    const appointmentError = validateAppointmentServices(nextForm.services, latestTasks.ok ? latestTasks.tasks || [] : calendarTasks, nextForm.module_data);
    if (appointmentError) {
      setError(appointmentError);
      return false;
    }
    const payload = serviceAppointmentTaskPayload({ ...nextForm, workspace_owner_id: workspaceOwnerId }, service, id);
    const taskResult = service.appointment_task_id
      ? await post("/api/tasks.php", { ...payload, action: "update", id: service.appointment_task_id })
      : await post("/api/tasks.php", payload);
    if (!taskResult.ok) {
      setError(`Запис у календар не додано, зміни послуги не збережено: ${taskResult.message}`);
      return false;
    }
    const taskId = service.appointment_task_id || taskResult.task_id || "";
    const services = nextForm.services.map((item, index) => index === serviceIndex ? { ...item, appointment_task_id: taskId } : item);
    const linkedSave = await post("/api/clients.php", { ...nextForm, workspace_owner_id: workspaceOwnerId, services, action: "update", id });
    if (!linkedSave.ok) {
      if (!service.appointment_task_id && taskId) {
        await post("/api/tasks.php", { action: "delete", id: taskId });
      } else if (service.appointment_task_id) {
        const previousService = form?.services?.[serviceIndex];
        if (previousService) {
          const rollbackPayload = serviceAppointmentTaskPayload({ ...form, workspace_owner_id: workspaceOwnerId }, previousService, id);
          await post("/api/tasks.php", { ...rollbackPayload, action: "update", id: service.appointment_task_id });
        }
      }
      setError(`Не вдалося прив’язати запис до послуги: ${linkedSave.message}`);
      return false;
    }
    setForm((current) => ({ ...current, services }));
    originalAppointmentTaskIds.current = [...new Set([...originalAppointmentTaskIds.current, taskId].filter(Boolean))];
    const refreshedTasks = await api("/api/tasks.php");
    if (refreshedTasks.ok) setCalendarTasks(refreshedTasks.tasks || []);
    setNotice(service.appointment_task_id ? "Запис у календарі оновлено." : "Запис додано до календаря.");
    return true;
  }

  async function addService(nextForm, service, reason = "service") {
    setError("");
    setNotice("");
    const result = await post("/api/clients.php", { ...nextForm, workspace_owner_id: workspaceOwnerId, action: "update", id });
    if (!result.ok) {
      setError(result.message);
      return false;
    }
    setNotice(reason === "teeth" ? `Для послуги «${service?.name || ""}» збережено вибір зубів.` : service?.is_package || reason === "package" ? `Пакет «${service.package_name || service.name}» додано автоматично.` : `Послугу «${service?.name || ""}» додано автоматично.`);
    if (service?.create_task && !service.task_id) {
      const taskResult = await post("/api/tasks.php", serviceTaskPayload({ ...nextForm, workspace_owner_id: workspaceOwnerId }, service, id));
      if (!taskResult.ok) {
        setForm(nextForm);
        setError(`Послугу збережено, але задачу не створено: ${taskResult.message}`);
        return true;
      }
      else {
        const servicesWithTask = nextForm.services.map((item, itemIndex) => itemIndex === nextForm.services.indexOf(service) ? { ...item, task_id: taskResult.task_id || "" } : item);
        const taskSave = await post("/api/clients.php", { ...nextForm, workspace_owner_id: workspaceOwnerId, services: servicesWithTask, action: "update", id });
        if (!taskSave.ok) {
          await post("/api/tasks.php", { action: "delete", id: taskResult.task_id });
          setForm(nextForm);
          setError(`Послугу збережено, але задачу не вдалося прив’язати: ${taskSave.message}`);
          return true;
        }
        else {
          setForm((current) => ({ ...current, services: servicesWithTask }));
          setNotice(`Послугу збережено. Створено задачу: «${service.name}».`);
        }
      }
    }
    return true;
  }

  if (!user || !form) return <div className="loading-screen">Завантаження клієнта…</div>;
  return (
    <Layout page="Редагування клієнта" user={user}>
      <div className="content clients-page client-create-page">
        <div className="client-create-topline"><button type="button" className="back-link" onClick={() => navigate("/clients")}>← До клієнтів</button></div>
        <section className="welcome-row clients-hero"><div><p className="eyebrow">РОБОЧИЙ ПРОСТІР</p><h1>Редагувати клієнта</h1><p className="subtext">Оновіть дані клієнта в полях активного модуля.</p></div></section>
        {notice && <div className="team-notice success">{notice}</div>}
        {error && <div className="form-notice">{error}</div>}
        {user && ["admin", "owner"].includes(user.role) && <label className="admin-client-workspace-select">Простір-власник клієнта<select value={workspaceOwnerId} onChange={event => setWorkspaceOwnerId(event.target.value)} required>{adminWorkspaces.map(owner => <option key={owner.id} value={owner.id}>{owner.name} · {owner.email}</option>)}</select></label>}
        <ClientFormModal form={form} setForm={setForm} editing={form} availableUsers={availableUsers} teamUsers={teamUsers} availableUsersByClient={availableUsersByClient} servicePackages={servicePackages} standaloneServices={standaloneServices} calendarTasks={calendarTasks} clientId={id} currentUserId={user?.id || ""} subscription={user?.subscription} onSave={save} onSaveAppointment={saveAppointment} onAddService={addService} onClose={() => navigate("/clients")} pageMode />
      </div>
    </Layout>
  );
}
