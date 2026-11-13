import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import Layout from "../components/Layout.jsx";
import { api, post } from "../services/api.js";
import { ClientFormModal, emptyForm, serviceTaskPayload } from "./Clients.jsx";
import "./clients.css";

export default function ClientCreatePage() {
  const navigate = useNavigate();
  const [user, setUser] = useState(null);
  const [availableUsers, setAvailableUsers] = useState([]);
  const [availableUsersByClient, setAvailableUsersByClient] = useState({});
  const [form, setForm] = useState({
    ...emptyForm,
    module: window.localStorage.getItem("nexaflow.client.module") || "accounting",
  });
  const [error, setError] = useState("");

  useEffect(() => {
    api("/api/clients.php").then((result) => {
      if (!result.ok) return navigate("/", { replace: true });
      if (!result.permissions?.manage) return navigate("/clients", { replace: true });
      setUser(result.user);
      setAvailableUsers(result.available_users || []);
      setAvailableUsersByClient(result.available_users_by_client || {});
    });
  }, [navigate]);

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
    const submittedForm = { ...form, name: typeof submittedName === "string" ? submittedName.trim() : form.name };
    const result = await post("/api/clients.php", { ...submittedForm, action: "create" });
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
      if (!service.create_task || !service.name) continue;
      const taskResult = await post("/api/tasks.php", serviceTaskPayload(submittedForm, service, clientId));
      if (!taskResult.ok) {
        await rollback();
        return setError(`Не вдалося зберегти клієнта і пов’язані задачі: ${taskResult.message}`);
      }
      if (taskResult.task_id) createdTaskIds.push(taskResult.task_id);
      services[index] = { ...service, task_id: taskResult.task_id || "" };
    }
    if (services.some((service, index) => service.task_id !== (form.services || [])[index]?.task_id)) {
      const updateResult = await post("/api/clients.php", { ...submittedForm, services, action: "update", id: clientId });
      if (!updateResult.ok) {
        await rollback();
        return setError(`Не вдалося завершити збереження клієнта: ${updateResult.message}`);
      }
    }
    navigate("/clients", { state: { notice: result.message } });
  }

  if (!user) return <div className="loading-screen">Завантаження форми клієнта…</div>;
  return (
    <Layout page="Новий клієнт" user={user}>
      <div className="content clients-page client-create-page">
        <div className="client-create-topline"><button type="button" className="back-link" onClick={() => navigate("/clients")}>← До клієнтів</button></div>
        <section className="welcome-row clients-hero">
          <div><p className="eyebrow">РОБОЧИЙ ПРОСТІР</p><h1>Створити клієнта</h1><p className="subtext">Заповніть дані клієнта за полями активного модуля.</p></div>
        </section>
        {error && <div className="form-notice">{error}</div>}
        <ClientFormModal form={form} setForm={setForm} editing={null} availableUsers={availableUsers} availableUsersByClient={availableUsersByClient} onSave={save} onAddService={addService} onClose={() => navigate("/clients")} pageMode />
      </div>
    </Layout>
  );
}
