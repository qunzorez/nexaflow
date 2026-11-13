import { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import Layout from "../components/Layout.jsx";
import { api, post } from "../services/api.js";
import { ClientFormModal, emptyForm, serviceTaskPayload } from "./Clients.jsx";
import "./clients.css";

export default function ClientEditPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [user, setUser] = useState(null);
  const [form, setForm] = useState(null);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [availableUsers, setAvailableUsers] = useState([]);
  const [availableUsersByClient, setAvailableUsersByClient] = useState({});

  useEffect(() => {
    api("/api/clients.php").then((result) => {
      if (!result.ok) return navigate("/", { replace: true });
      if (!result.permissions?.manage) return navigate("/clients", { replace: true });
      const client = result.clients.find((item) => String(item.id) === String(id));
      if (!client) return navigate("/clients", { replace: true });
      setUser(result.user);
      setAvailableUsers(result.available_users || []);
      setAvailableUsersByClient(result.available_users_by_client || {});
      setForm({
        ...emptyForm,
        ...client,
        has_vat: Boolean(Number(client.has_vat)),
        has_excise: Boolean(Number(client.has_excise)),
        has_employees: Boolean(Number(client.has_employees)),
        employee_count: client.employee_count || "",
        special_taxes: client.special_taxes || [],
        module: client.module || window.localStorage.getItem("nexaflow.client.module") || "accounting",
        module_data: client.module_data || {},
      });
    });
  }, [id, navigate]);

  async function save(event) {
    event.preventDefault();
    setError("");
    setNotice("");
    const previousForm = form;
    const result = await post("/api/clients.php", { ...form, action: "update", id });
    if (!result.ok) return setError(result.message);
    const services = [...(form.services || [])];
    let taskError = "";
    let tasksChanged = false;
    let tasksCreated = 0;
    const createdTaskIds = [];
    const rollback = async () => {
      await Promise.all(createdTaskIds.map((taskId) => post("/api/tasks.php", { action: "delete", id: taskId })));
      await post("/api/clients.php", { ...previousForm, action: "update", id });
    };
    for (let index = 0; index < services.length; index += 1) {
      const service = services[index];
      if (!service.create_task || !service.name || service.task_id) continue;
      const taskResult = await post("/api/tasks.php", serviceTaskPayload(form, service, id));
      if (!taskResult.ok) {
        taskError = taskResult.message;
        continue;
      }
      if (taskResult.task_id) createdTaskIds.push(taskResult.task_id);
      services[index] = { ...service, task_id: taskResult.task_id || "" };
      tasksChanged = true;
      tasksCreated += 1;
    }
    if (tasksChanged) {
      const taskSave = await post("/api/clients.php", { ...form, services, action: "update", id });
      if (!taskSave.ok) {
        await rollback();
        return setError(`Не вдалося завершити збереження клієнта: ${taskSave.message}`);
      }
      setForm((current) => ({ ...current, services }));
    }
    if (taskError) {
      await rollback();
      setError(`Зміни скасовано, оскільки задачу не створено: ${taskError}`);
      return;
    }
    setNotice(tasksCreated > 0 ? `Зміни збережено. Створено задач: ${tasksCreated}.` : result.message);
  }

  async function addService(nextForm, service) {
    setError("");
    setNotice("");
    const previousForm = form;
    const result = await post("/api/clients.php", { ...nextForm, action: "update", id });
    if (!result.ok) {
      setError(result.message);
      return false;
    }
    if (service?.create_task && !service.task_id) {
      const taskResult = await post("/api/tasks.php", serviceTaskPayload(nextForm, service, id));
      if (!taskResult.ok) {
        await post("/api/clients.php", { ...previousForm, action: "update", id });
        setError(`Зміни скасовано, оскільки задачу не створено: ${taskResult.message}`);
      }
      else {
        const servicesWithTask = nextForm.services.map((item, itemIndex) => itemIndex === nextForm.services.indexOf(service) ? { ...item, task_id: taskResult.task_id || "" } : item);
        const taskSave = await post("/api/clients.php", { ...nextForm, services: servicesWithTask, action: "update", id });
        if (!taskSave.ok) {
          await post("/api/tasks.php", { action: "delete", id: taskResult.task_id });
          await post("/api/clients.php", { ...previousForm, action: "update", id });
          setError(`Зміни скасовано: ${taskSave.message}`);
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
        <ClientFormModal form={form} setForm={setForm} editing={form} availableUsers={availableUsers} availableUsersByClient={availableUsersByClient} clientId={id} onSave={save} onAddService={addService} onClose={() => navigate("/clients")} pageMode />
      </div>
    </Layout>
  );
}
