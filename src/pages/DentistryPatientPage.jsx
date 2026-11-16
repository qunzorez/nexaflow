import { useCallback, useEffect, useMemo, useState } from "react";
import { Link, useNavigate, useParams, useSearchParams } from "react-router-dom";
import Icon from "../components/Icon.jsx";
import Layout from "../components/Layout.jsx";
import SearchableSelect from "../components/SearchableSelect.jsx";
import { api, post } from "../services/api.js";
import { apiUrl } from "../config.js";
import { DentalToothChart } from "./Clients.jsx";
import "./dentistry-patient.css";

const money = value => `${Number(value || 0).toLocaleString("uk-UA", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} грн`;
const dateTime = value => {
  if (!value) return "—";
  const date = new Date(String(value).replace(" ", "T"));
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString("uk-UA", { day: "numeric", month: "long", year: "numeric", hour: "2-digit", minute: "2-digit" });
};
const appointmentStatusLabel = item => ({ scheduled: "Заплановано", confirmed: "Підтверджено", arrived: "Пацієнт прибув", completed: "Завершено", archived: "В архіві", cancelled: "Скасовано" }[item.appointment_status] || ({ archived: "В архіві", done: "Завершено", cancelled: "Скасовано", in_progress: "Пацієнт прибув" }[item.status] || "Заплановано"));
const newKey = () => window.crypto?.getRandomValues ? Array.from(window.crypto.getRandomValues(new Uint8Array(16)), value => value.toString(16).padStart(2, "0")).join("") : `${Date.now().toString(16).padStart(16, "0")}${Math.random().toString(16).slice(2).padEnd(16, "0")}`.slice(0, 32);
function printInvoice(invoice, patient) {
  const escape = value => String(value ?? "").replace(/[&<>"']/g, character => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[character]);
  const rows = (invoice.items || []).map((item, index) => `<tr><td>${index + 1}</td><td>${escape(item.service_name)}${item.tooth_number ? `<small>Зуб ${escape(item.tooth_number)}</small>` : ""}</td><td>${escape(item.quantity)}</td><td>${Number(item.unit_price || 0).toLocaleString("uk-UA", { minimumFractionDigits: 2 })} грн</td><td>${Number(item.total_price || 0).toLocaleString("uk-UA", { minimumFractionDigits: 2 })} грн</td></tr>`).join("");
  const popup = window.open("", "_blank", "width=900,height=700");
  if (!popup) return;
  popup.document.write(`<!doctype html><html lang="uk"><meta charset="utf-8"><title>${escape(invoice.invoice_number)}</title><style>body{font-family:Arial,sans-serif;color:#17231d;margin:40px}h1{color:#168665}.meta{display:flex;justify-content:space-between;margin:24px 0;padding:16px;background:#f5faf7;border-radius:10px}table{width:100%;border-collapse:collapse}th,td{padding:10px;border:1px solid #dbe6df;text-align:left}th{background:#edf7f1}small{display:block;color:#718078;margin-top:4px}.total{text-align:right;font-size:18px;font-weight:bold;margin-top:20px}@media print{button{display:none}}</style><h1>NexaFlow · Рахунок</h1><strong>${escape(invoice.invoice_number)}</strong><div class="meta"><div><b>Пацієнт</b><br>${escape(patient.name)}<br>${escape(patient.module_data?.phone || "")}</div><div><b>Дата</b><br>${escape(dateTime(invoice.created_at))}</div></div><table><thead><tr><th>№</th><th>Послуга</th><th>К-сть</th><th>Ціна за од.</th><th>Сума</th></tr></thead><tbody>${rows}</tbody></table><p class="total">До сплати: ${Number(invoice.total_amount || 0).toLocaleString("uk-UA", { minimumFractionDigits: 2 })} грн</p><p>Оплачено: ${Number(invoice.paid_amount || 0).toLocaleString("uk-UA", { minimumFractionDigits: 2 })} грн · Залишок: ${Number(invoice.remaining_amount || 0).toLocaleString("uk-UA", { minimumFractionDigits: 2 })} грн</p><script>window.onload=()=>window.print()</script></html>`);
  popup.document.close();
}
const tabs = ["Огляд", "Прийоми", "Медична карта", "Зубна формула", "План лікування", "Виконані послуги", "Рахунки", "Оплати", "Документи"];

const emptyRecord = { id: "", appointment_id: "", complaints: "", diagnosis: "", examination: "", recommendations: "", tooth_numbers: [] };
const emptyPlan = () => ({ title: "", comment: "", items: [{ service_id: "", quantity: 1, tooth_number: "", comment: "" }] });
const emptyPerformed = { appointment_id: "" };
const emptyPayment = { type: "payment", payment_method: "cash", amount: "", invoice_id: "", allocated_amount: "", comment: "" };
const readPatientDraft = patientId => {
  try {
    const stored = sessionStorage.getItem(`dentistry-patient-draft:${patientId}`);
    return stored ? JSON.parse(stored) : {};
  } catch { return {}; }
};
const validDentalTeeth = new Set([11,12,13,14,15,16,17,18,21,22,23,24,25,26,27,28,31,32,33,34,35,36,37,38,41,42,43,44,45,46,47,48]);
const normalizeTeeth = value => {
  if (Array.isArray(value)) return value.map(Number).filter(tooth => validDentalTeeth.has(tooth));
  if (typeof value === "string") {
    try { return normalizeTeeth(JSON.parse(value)); } catch {
      return value.split(/[\s,;]+/).map(Number).filter(tooth => validDentalTeeth.has(tooth));
    }
  }
  if (typeof value === "number") return validDentalTeeth.has(value) ? [value] : [];
  if (value && typeof value === "object") return normalizeTeeth(Object.values(value));
  return [];
};

export default function DentistryPatientPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const [loadedDraftFor, setLoadedDraftFor] = useState(id);
  const [initialDraft] = useState(() => readPatientDraft(id));
  const requestedTab = searchParams.get("tab") === "appointments" ? "Прийоми" : "";
  const [user, setUser] = useState(null);
  const [patient, setPatient] = useState(null);
  const [teamUsers, setTeamUsers] = useState([]);
  const [care, setCare] = useState(null);
  const [services, setServices] = useState([]);
  const [tab, setTab] = useState(requestedTab || initialDraft.tab || tabs[0]);
  const [record, setRecord] = useState(initialDraft.record || emptyRecord);
  const [plan, setPlan] = useState(initialDraft.plan || emptyPlan());
  const [planTeeth, setPlanTeeth] = useState(initialDraft.planTeeth || []);
  const [performed, setPerformed] = useState(initialDraft.performed || emptyPerformed);
  const [selectedPlanIds, setSelectedPlanIds] = useState(initialDraft.selectedPlanIds || []);
  const [payment, setPayment] = useState(initialDraft.payment || emptyPayment);
  const [advanceAllocation, setAdvanceAllocation] = useState(initialDraft.advanceAllocation || { payment_id: "", invoice_id: "", amount: "" });
  const [documentFile, setDocumentFile] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  useEffect(() => {
    if (loadedDraftFor === id) return;
    const draft = readPatientDraft(id);
    setTab(draft.tab || tabs[0]);
    setRecord(draft.record || emptyRecord);
    setPlan(draft.plan || emptyPlan());
    setPlanTeeth(draft.planTeeth || []);
    setPerformed(draft.performed || emptyPerformed);
    setSelectedPlanIds(draft.selectedPlanIds || []);
    setPayment(draft.payment || emptyPayment);
    setAdvanceAllocation(draft.advanceAllocation || { payment_id: "", invoice_id: "", amount: "" });
    setLoadedDraftFor(id);
  }, [id, loadedDraftFor]);

  useEffect(() => {
    if (loadedDraftFor !== id) return;
    try {
      sessionStorage.setItem(`dentistry-patient-draft:${id}`, JSON.stringify({ tab, record, plan, planTeeth, performed, selectedPlanIds, payment, advanceAllocation }));
    } catch { /* Draft saving should never block patient care. */ }
  }, [id, loadedDraftFor, tab, record, plan, planTeeth, performed, selectedPlanIds, payment, advanceAllocation]);

  const refresh = useCallback(async () => {
    const [clientsResult, careResult, serviceResult] = await Promise.all([
      api("/api/clients.php"),
      api(`/api/dentistry-care.php?patient_id=${encodeURIComponent(id)}`),
      api("/api/services.php?module=dentistry"),
    ]);
    if (!clientsResult.ok || clientsResult.active_client_module !== "dentistry") return navigate("/clients", { replace: true });
    const nextPatient = (clientsResult.clients || []).find(item => String(item.id) === String(id));
    if (!nextPatient || !careResult.ok) return navigate("/clients", { replace: true });
    setUser(clientsResult.user);
    setTeamUsers(clientsResult.team_users || []);
    setPatient(nextPatient);
    setCare(careResult);
    setServices((serviceResult.standalone_services || []).filter(item => item.active !== false));
  }, [id, navigate]);

  useEffect(() => { refresh(); }, [refresh]);

  const availableAppointments = useMemo(() => (care?.appointments || []).filter(item => !["cancelled", "done", "archived"].includes(item.status)), [care]);
  const recordAppointments = useMemo(() => (care?.appointments || []).filter(item => !["cancelled", "archived"].includes(item.status) && !["cancelled", "archived"].includes(item.appointment_status)), [care]);
  const uninvoiced = useMemo(() => (care?.performed_services || []).filter(item => !item.invoice_id), [care]);
  const openInvoices = useMemo(() => (care?.invoices || []).filter(item => item.status !== "cancelled" && Number(item.remaining_amount) > 0), [care]);
  const paidInvoices = useMemo(() => (care?.invoices || []).filter(item => item.status !== "cancelled" && Number(item.paid_amount) > 0), [care]);
  const availableAdvances = useMemo(() => (care?.payments || []).filter(item => item.payment_type === "payment" && Number(item.amount) > Number(item.allocated_amount || 0)), [care]);
  const performablePlans = useMemo(() => (care?.treatment_plans || []).filter(item => item.status === "active" && (item.items || []).some(row => row.status !== "completed" && Number(row.quantity) > Number(row.completed_quantity || 0))), [care]);
  const selectedPlans = useMemo(() => performablePlans.filter(item => selectedPlanIds.includes(item.id)), [performablePlans, selectedPlanIds]);
  const planExecutionEstimate = useMemo(() => selectedPlans.reduce((total, item) => total + (item.items || []).reduce((sum, row) => {
    const remaining = Math.max(0, Number(row.quantity) - Number(row.completed_quantity || 0));
    return sum + Number(row.planned_price || 0) * remaining;
  }, 0), 0), [selectedPlans]);
  const planEstimate = useMemo(() => plan.items.reduce((total, item) => {
    const service = services.find(entry => String(entry.id) === String(item.service_id));
    return total + Number(service?.client_price || 0) * Math.max(1, Number(item.quantity || 1)) * Math.max(1, planTeeth.length);
  }, 0), [plan.items, planTeeth.length, services]);

  async function runAction(action, payload, successText) {
    setBusy(true); setError(""); setNotice("");
    const result = await post("/api/dentistry-care.php", { action, patient_id: id, ...payload });
    setBusy(false);
    if (!result.ok) { setError(result.message || "Не вдалося зберегти зміни."); return false; }
    setNotice(successText || result.message || "Зміни збережено.");
    await refresh();
    return result;
  }

  async function saveRecord(event) {
    event.preventDefault();
    const result = await runAction("save_medical_record", record, record.id ? "Медичний запис оновлено." : "Медичний запис додано.");
    if (result) setRecord(emptyRecord);
  }

  async function createPlan(event) {
    event.preventDefault();
    const items = plan.items.flatMap(item => (planTeeth.length ? planTeeth : [null]).map(tooth => ({ ...item, tooth_number: tooth ? String(tooth) : "" })));
    const result = await runAction("create_treatment_plan", { ...plan, items }, "План лікування створено.");
    if (result) {
      setPlan(emptyPlan());
      setPlanTeeth([]);
    }
  }

  async function performService(event) {
    event.preventDefault();
    const result = await runAction("perform_treatment_plans", { appointment_id: performed.appointment_id, treatment_plan_ids: selectedPlanIds });
    if (result) {
      setNotice(`Прийом завершено. Рахунок ${result.invoice_number} на ${money(result.total_amount)} сформовано автоматично.`);
      setPerformed(emptyPerformed);
      setSelectedPlanIds([]);
    }
  }

  async function createInvoice() {
    const ids = uninvoiced.map(item => item.id);
    if (!ids.length) return;
    const result = await runAction("create_invoice", { performed_service_ids: ids }, "Рахунок створено з виконаних послуг.");
    if (result) setTab("Рахунки");
  }

  async function createPayment(event) {
    event.preventDefault();
    const allocations = payment.invoice_id && Number(payment.allocated_amount) > 0
      ? [{ invoice_id: payment.invoice_id, amount: payment.allocated_amount }]
      : [];
    const action = payment.type === "refund" ? "refund_payment" : "create_payment";
    const result = await runAction(action, { ...payment, allocations: payment.type === "refund" && payment.invoice_id ? [{ invoice_id: payment.invoice_id, amount: payment.amount }] : allocations, idempotency_key: newKey() }, payment.type === "refund" ? "Повернення зареєстровано." : "Оплату зареєстровано.");
    if (result) setPayment({ type: "payment", payment_method: "cash", amount: "", invoice_id: "", allocated_amount: "", comment: "" });
  }

  async function allocateAdvance(event) {
    event.preventDefault();
    const result = await runAction("allocate_advance", advanceAllocation, "Аванс зараховано на рахунок.");
    if (result) setAdvanceAllocation({ payment_id: "", invoice_id: "", amount: "" });
  }

  async function uploadDocument(event) {
    event.preventDefault();
    if (!documentFile) return;
    const form = new FormData(); form.append("action", "upload_document"); form.append("patient_id", id); form.append("file", documentFile);
    const result = await post("/api/dentistry-care.php", form);
    if (!result.ok) { setError(result.message || "Не вдалося завантажити документ."); return; }
    setDocumentFile(null); event.currentTarget.reset(); setNotice("Документ завантажено."); await refresh();
  }

  if (!patient || !care) return <div className="loading-screen">Завантаження картки пацієнта…</div>;
  const moduleData = patient.module_data || {};
  const upcoming = (care.appointments || []).filter(item => !["done", "cancelled", "archived"].includes(item.status) && new Date(String(item.due_date).replace(" ", "T")).getTime() >= Date.now()).sort((a, b) => String(a.due_date).localeCompare(String(b.due_date)))[0];

  return <Layout page="Картка пацієнта" user={user}>
    <main className="content dentistry-patient-page">
      <Link className="dentistry-back-link" to="/clients"><Icon name="back" /> До пацієнтів</Link>
      <section className="dentistry-patient-profile">
        <div className="dentistry-profile-main"><span className="dentistry-profile-avatar">{patient.name?.trim().charAt(0)?.toLocaleUpperCase("uk") || "П"}</span><div><span className="dentistry-kicker">КАРТКА ПАЦІЄНТА</span><h1>{patient.name}</h1><p>{[moduleData.phone, moduleData.email].filter(Boolean).join(" · ") || "Контактні дані не вказані"}</p>{moduleData.address && <small>Адреса: {moduleData.address}</small>}{moduleData.medical_record && <small>Медична картка № {moduleData.medical_record}</small>}</div></div>
        <div className="dentistry-profile-actions"><Link className="outline-btn" to={`/clients/${patient.id}/edit`}><Icon name="edit" /> Редагувати дані</Link><button className="primary-action" type="button" onClick={() => navigate(`/appointments?client=${patient.id}`)}><Icon name="plus" /> Записати на прийом</button></div>
        <div className="dentistry-profile-stats"><div><small>Наступний прийом</small><strong>{upcoming ? dateTime(upcoming.due_date) : "Не заплановано"}</strong></div><div><small>Візитів</small><strong>{care.appointments?.length || 0}</strong></div>{care.financial_access && <><div><small>Рахунки</small><strong>{money(care.summary?.invoiced)}</strong></div><div><small>Оплачено</small><strong>{money(care.summary?.paid)}</strong></div><div><small>Борг</small><strong className={Number(care.summary?.debt) > 0 ? "is-debt" : ""}>{money(care.summary?.debt)}</strong></div></>}</div>
      </section>
      {notice && <div className="dentistry-care-alert success" role="status">{notice}</div>}{error && <div className="dentistry-care-alert error" role="alert">{error}</div>}
      <nav className="dentistry-patient-tabs" aria-label="Розділи картки пацієнта">{tabs.filter(name => care.financial_access || !["Рахунки", "Оплати"].includes(name)).map(name => <button key={name} type="button" className={tab === name ? "active" : ""} onClick={() => setTab(name)}>{name}{name === "Рахунки" && care.invoices?.length > 0 && <span>{care.invoices.length}</span>}</button>)}</nav>

      {tab === "Огляд" && <div className="dentistry-care-grid"><section className="dentistry-care-panel"><header><div><h2>Найближчі прийоми</h2><p>Візити цього пацієнта в календарі.</p></div><button className="outline-btn" type="button" onClick={() => setTab("Прийоми")}>Усі прийоми</button></header>{care.appointments?.filter(item => !["cancelled", "archived", "done"].includes(item.status)).slice(0, 4).map(item => <Link className="dentistry-care-row visit" to={`/appointments/${item.id}`} key={item.id}><span className="dentistry-row-icon"><Icon name="calendar" /></span><div><strong>{item.title}</strong><small>{dateTime(item.due_date)} · {item.doctor_name || "Лікар не призначений"} · {Number(item.duration_minutes || 60)} хв{item.cabinet_id ? ` · ${item.cabinet_id}` : ""}</small>{item.reason && <small>Причина: {item.reason}</small>}</div><span className="dentistry-state">{appointmentStatusLabel(item)}</span></Link>)}{!care.appointments?.length && <p className="dentistry-muted-empty">Прийомів поки немає.</p>}</section><section className="dentistry-care-panel"><header><div><h2>Медична карта</h2><p>Останні огляди й рекомендації.</p></div><button className="outline-btn" type="button" onClick={() => { setRecord(emptyRecord); setTab("Медична карта"); }}>Додати огляд</button></header>{care.medical_records?.slice(0, 3).map(item => <article className="dentistry-history-entry" key={item.id}><strong>{item.diagnosis || "Огляд пацієнта"}</strong><small>{dateTime(item.created_at)} · {item.doctor_name || "Лікар"}</small><p>{item.recommendations || item.complaints || "Деталі не додані."}</p></article>)}{!care.medical_records?.length && <p className="dentistry-muted-empty">Медичних записів поки немає.</p>}</section><section className="dentistry-care-panel"><header><div><h2>План лікування</h2><p>Заплановані роботи не формують борг.</p></div><button className="outline-btn" type="button" onClick={() => setTab("План лікування")}>Створити план</button></header>{care.treatment_plans?.slice(0, 3).map(item => <article className="dentistry-care-row" key={item.id}><span className="dentistry-row-icon plan"><Icon name="subscription" /></span><div><strong>{item.title}</strong><small>{item.items?.length || 0} позицій · {money(item.estimated_total)}</small></div><span className="dentistry-state">{appointmentStatusLabel(item)}</span></article>)}{!care.treatment_plans?.length && <p className="dentistry-muted-empty">Планів лікування поки немає.</p>}</section>{care.financial_access && <section className="dentistry-care-panel"><header><div><h2>Фінанси</h2><p>Борг виникає лише після рахунку за виконані послуги.</p></div><button className="outline-btn" type="button" onClick={() => setTab("Оплати")}>Оплата</button></header><div className="dentistry-finance-callout"><span>Нерозподілений аванс</span><strong>{money(care.summary?.advance)}</strong></div><div className="dentistry-finance-callout"><span>Заборгованість</span><strong>{money(care.summary?.debt)}</strong></div></section>}</div>}

      {tab === "Прийоми" && <section className="dentistry-care-panel"><header><div><h2>Історія прийомів</h2><p>Календарні записи залишаються окремими від робочих задач.</p></div><button className="primary-action" type="button" onClick={() => navigate(`/appointments?client=${patient.id}`)}><Icon name="plus" /> Новий запис</button></header>{care.appointments?.length ? care.appointments.map(item => <Link className="dentistry-care-row visit" to={`/appointments/${item.id}`} key={item.id}><span className="dentistry-row-icon"><Icon name="calendar" /></span><div><strong>{item.title}</strong><small>{dateTime(item.due_date)} · {item.doctor_name || "Лікар не призначений"} · {Number(item.duration_minutes || 60)} хв{item.cabinet_id ? ` · ${item.cabinet_id}` : ""}</small>{item.reason && <small>Причина: {item.reason}</small>}</div><span className={`dentistry-state ${item.status === "cancelled" ? "cancelled" : ""}`}>{appointmentStatusLabel(item)}</span></Link>) : <p className="dentistry-muted-empty">Прийомів поки немає.</p>}</section>}

      {tab === "Медична карта" && <div className="dentistry-care-grid"><form className="dentistry-care-panel dentistry-care-form" onSubmit={saveRecord}><header><div><h2>{record.id ? "Редагувати огляд" : "Новий огляд"}</h2><p>Запис прив’язується до пацієнта й, за потреби, до прийому.</p></div></header><label>Прийом<SearchableSelect options={[{ id: "", name: "Без прийому" }, ...recordAppointments.map(item => ({ id: item.id, name: `${dateTime(item.due_date)} · ${item.title}`, email: `${appointmentStatusLabel(item)} · ${item.doctor_name || "Лікар не призначений"}` }))]} value={record.appointment_id} placeholder="Оберіть прийом" onChange={value => setRecord({ ...record, appointment_id: value })} /></label><label>Лікар<SearchableSelect options={[{ id: "", name: user?.name ? `${user.name} (поточний користувач)` : "Поточний користувач" }, ...teamUsers.map(person => ({ id: person.id, name: person.name, email: person.email }))]} value={record.doctor_id || ""} placeholder="Оберіть лікаря" onChange={value => setRecord({ ...record, doctor_id: value })} /></label><label>Скарги<textarea value={record.complaints} onChange={event => setRecord({ ...record, complaints: event.target.value })} rows="2" /></label><label>Огляд<textarea value={record.examination} onChange={event => setRecord({ ...record, examination: event.target.value })} rows="3" /></label><label>Діагноз<textarea value={record.diagnosis} onChange={event => setRecord({ ...record, diagnosis: event.target.value })} rows="2" /></label><label>Рекомендації<textarea value={record.recommendations} onChange={event => setRecord({ ...record, recommendations: event.target.value })} rows="2" /></label><DentalToothChart value={normalizeTeeth(record.tooth_numbers)} onChange={teeth => setRecord({ ...record, tooth_numbers: teeth })} /><button className="primary-action" disabled={busy} type="submit">Зберегти огляд</button></form><section className="dentistry-care-panel"><header><div><h2>Історія оглядів</h2><p>Зміни медичних записів журналюються.</p></div></header>{care.medical_records?.map(item => { const teeth = normalizeTeeth(item.tooth_numbers); return <article className="dentistry-history-entry" key={item.id}><div className="dentistry-history-heading"><strong>{item.diagnosis || "Огляд пацієнта"}</strong><button type="button" onClick={() => { setRecord({ ...emptyRecord, ...item, tooth_numbers: teeth }); window.scrollTo({ top: 0, behavior: "smooth" }); }}>Редагувати</button></div><small>{dateTime(item.created_at)} · {item.doctor_name || "Лікар"}</small>{teeth.length > 0 && <p>Зуби: {teeth.join(", ")}</p>}{item.complaints && <p><b>Скарги:</b> {item.complaints}</p>}{item.examination && <p><b>Огляд:</b> {item.examination}</p>}{item.recommendations && <p><b>Рекомендації:</b> {item.recommendations}</p>}</article>})}</section></div>}

      {tab === "Зубна формула" && <section className="dentistry-care-panel"><header><div><h2>Зубна формула пацієнта</h2><p>Схема показує зуби, зазначені в оглядах і виконаних послугах.</p></div></header><DentalToothChart value={[...new Set([...(care.medical_records || []).flatMap(item => normalizeTeeth(item.tooth_numbers)), ...(care.performed_services || []).map(item => Number(item.tooth_number)).filter(Boolean)])]} readOnly /></section>}

      {tab === "План лікування" && <div className="dentistry-care-grid dentistry-treatment-plan-grid">
        <form className="dentistry-care-panel dentistry-care-form" onSubmit={createPlan}>
          <header><div><h2>Створити план лікування</h2><p>Оберіть зуби один раз — усі додані послуги застосуються до них. Чернетка зберігається автоматично й відновиться після оновлення сторінки.</p></div></header>
          <label>Назва плану<input required value={plan.title} onChange={event => setPlan({ ...plan, title: event.target.value })} placeholder="Наприклад, лікування карієсу" /></label>
          <label>Коментар<textarea value={plan.comment} onChange={event => setPlan({ ...plan, comment: event.target.value })} rows="2" /></label>
          <label>Відповідальний лікар<SearchableSelect options={[{ id: "", name: user?.name ? `${user.name} (поточний користувач)` : "Поточний користувач" }, ...teamUsers.map(person => ({ id: person.id, name: person.name, email: person.email }))]} value={plan.doctor_id || ""} placeholder="Оберіть лікаря" onChange={value => setPlan({ ...plan, doctor_id: value })} /></label>
          <section className="dentistry-plan-tooth-scope">
            <div><h3>Зубна формула</h3><p>{planTeeth.length ? `Вибрано ${planTeeth.length} зубів. Кожна послуга буде додана для кожного з них.` : "Виберіть кілька зубів або залиште формулу порожньою для загальної послуги."}</p></div>
            <DentalToothChart value={planTeeth} onChange={setPlanTeeth} />
          </section>
          <button className="primary-action dentistry-add-plan-service" type="button" onClick={() => setPlan(current => ({ ...current, items: [...current.items, { service_id: "", quantity: 1, tooth_number: "", comment: "" }] }))}><Icon name="plus" /> Додати послугу</button>
          <div className="dentistry-plan-service-heading"><h3>Послуги плану</h3><small>{planTeeth.length ? `Сума рахується на ${planTeeth.length} зубів` : "Сума рахується один раз для кожної послуги"}</small></div>
          {plan.items.map((item, index) => {
            const unitPrice = Number(services.find(service => String(service.id) === String(item.service_id))?.client_price || 0);
            const lineTotal = unitPrice * Math.max(1, Number(item.quantity || 1)) * Math.max(1, planTeeth.length);
            return <div className="dentistry-plan-item" key={index}>
              <label>Послуга<select required value={item.service_id} onChange={event => setPlan(current => ({ ...current, items: current.items.map((row, rowIndex) => rowIndex === index ? { ...row, service_id: event.target.value } : row) }))}><option value="">Оберіть зі свого каталогу</option>{services.map(service => <option key={service.id} value={service.id}>{service.name} · {money(service.client_price)}{planTeeth.length ? " / зуб" : ""}</option>)}</select></label>
              <label>{planTeeth.length ? "Кількість на зуб" : "Кількість"}<input type="number" min="1" max="100" value={item.quantity} onChange={event => setPlan(current => ({ ...current, items: current.items.map((row, rowIndex) => rowIndex === index ? { ...row, quantity: event.target.value } : row) }))} /></label>
              <div className="dentistry-plan-line-total"><small>{planTeeth.length ? `${money(unitPrice)} × ${item.quantity || 1} × ${planTeeth.length} зубів` : `${money(unitPrice)} × ${item.quantity || 1}`}</small><strong>{money(lineTotal)}</strong></div>
              <button className="dentistry-remove-plan-service" type="button" aria-label={`Видалити послугу ${index + 1}`} title="Видалити послугу" onClick={() => setPlan(current => ({ ...current, items: current.items.filter((_, rowIndex) => rowIndex !== index) }))}><Icon name="close" /></button>
            </div>;
          })}
          <div className="dentistry-plan-total"><span>Орієнтовна сума плану</span><strong>{money(planEstimate)}</strong></div>
          <button className="primary-action" disabled={busy || !services.length || !plan.items.length} type="submit">Створити план</button>
        </form>
        <section className="dentistry-care-panel"><header><div><h2>Плани пацієнта</h2><p>Виконання позицій оновлюється фактичними послугами.</p></div></header>{care.treatment_plans?.map(item => <article className="dentistry-plan-card" key={item.id}><header><div><strong>{item.title}</strong><small>{item.doctor_name || "Лікар"} · {dateTime(item.created_at)}</small></div><b>{money(item.estimated_total)}</b></header><div className="dentistry-plan-lines">{item.items?.map(row => <div key={row.id}><span>{row.service_name}{row.tooth_number ? ` · зуб ${row.tooth_number}` : ""} · {row.completed_quantity}/{row.quantity}</span><small>{money(row.planned_price)} · {row.status}</small></div>)}</div><footer><span>Статус: {item.status}</span>{item.status === "active" && <button type="button" onClick={() => runAction("update_treatment_plan", { id: item.id, status: "cancelled" }, "План скасовано.")}>Скасувати план</button>}</footer></article>)}</section>
      </div>}
      {tab === "Виконані послуги" && <div className="dentistry-care-grid"><form className="dentistry-care-panel dentistry-care-form" onSubmit={performService}>
        <header><div><h2>Завершити прийом і сформувати рахунок</h2><p>Оберіть прийом і один або кілька планів лікування. У рахунок потраплять усі послуги, що залишилися в цих планах.</p></div></header>
        <label>Прийом пацієнта<select required value={performed.appointment_id} onChange={event => setPerformed({ appointment_id: event.target.value })}><option value="">Оберіть прийом</option>{availableAppointments.map(item => <option key={item.id} value={item.id}>{dateTime(item.due_date)} · {item.title} · {item.doctor_name || "Лікар"}</option>)}</select></label>
        <fieldset className="dentistry-plan-selection"><legend>Плани лікування</legend>
          {performablePlans.length ? performablePlans.map(item => <label className={`dentistry-plan-choice ${selectedPlanIds.includes(item.id) ? "selected" : ""}`} key={item.id}>
            <input type="checkbox" checked={selectedPlanIds.includes(item.id)} onChange={event => setSelectedPlanIds(current => event.target.checked ? [...new Set([...current, item.id])] : current.filter(planId => planId !== item.id))} />
            <span className="dentistry-plan-choice-content"><strong>{item.title}</strong><small>{item.doctor_name || "Лікар"} · {item.items?.filter(row => row.status !== "completed").length || 0} послуг залишилось</small>
              <span className="dentistry-plan-choice-items">{item.items?.filter(row => row.status !== "completed").map(row => <span key={row.id}>{row.service_name}{row.tooth_number ? ` · зуб ${row.tooth_number}` : ""} · залишок {Math.max(0, Number(row.quantity) - Number(row.completed_quantity || 0))} · {money(Number(row.planned_price) * Math.max(0, Number(row.quantity) - Number(row.completed_quantity || 0)))}</span>)}</span>
            </span>
          </label>) : <p className="dentistry-muted-empty">Немає активних планів із послугами, що очікують виконання.</p>}
        </fieldset>
        <div className="dentistry-plan-total"><span>Сума автоматичного рахунку</span><strong>{money(planExecutionEstimate)}</strong></div>
        <p className="dentistry-appointment-finish-note">Після підтвердження послуги зафіксуються, плани оновляться, прийом перейде в архів, а рахунок буде створено автоматично.</p>
        <button className="primary-action" disabled={busy || !performed.appointment_id || !selectedPlanIds.length || planExecutionEstimate <= 0} type="submit">Підтвердити послуги й створити рахунок</button>
      </form><section className="dentistry-care-panel"><header><div><h2>Надані послуги</h2><p>Послуги зберігаються окремо, рахунок створюється під час підтвердження плану.</p></div>{uninvoiced.length > 0 && <button className="primary-action" type="button" disabled={busy} onClick={createInvoice}><Icon name="download" /> Рахунок · {money(uninvoiced.reduce((sum, item) => sum + Number(item.total_price), 0))}</button>}</header>{care.performed_services?.map(item => <article className="dentistry-care-row" key={item.id}><span className="dentistry-row-icon done"><Icon name="check" /></span><div><strong>{item.service_name}{item.tooth_number ? ` · зуб ${item.tooth_number}` : ""}</strong><small>{dateTime(item.performed_at)} · {item.doctor_name || "Лікар"} · {item.quantity} од.{item.invoice_id ? ` · рахунок ${item.invoice_id}` : " · не виставлено"}</small></div><b>{money(item.total_price)}</b></article>)}{!care.performed_services?.length && <p className="dentistry-muted-empty">Виконаних послуг поки немає.</p>}</section></div>}
      {tab === "Рахунки" && <section className="dentistry-care-panel"><header><div><h2>Рахунки</h2><p>Рахунки містять виконані послуги та збережені ціни на момент виконання.</p></div></header>{care.invoices?.length ? care.invoices.map(invoice => <article className="dentistry-invoice-card" key={invoice.id}><header><div><strong>{invoice.invoice_number}</strong><small>{dateTime(invoice.created_at)} · {invoice.status}</small></div><b>{money(invoice.total_amount)}</b></header><div className="dentistry-invoice-items">{invoice.items?.map(item => <div key={item.id}><span>{item.service_name}{item.tooth_number ? ` · зуб ${item.tooth_number}` : ""} · {item.quantity} од.</span><b>{money(item.total_price)}</b></div>)}</div><footer><span>Оплачено: {money(invoice.paid_amount)} · Залишок: {money(invoice.remaining_amount)}</span><div className="dentistry-invoice-actions"><button type="button" className="outline-btn" onClick={() => printInvoice(invoice, patient)}>Друк</button>{invoice.status !== "cancelled" && Number(invoice.paid_amount) === 0 && <button type="button" className="outline-btn" onClick={() => runAction("cancel_invoice", { id: invoice.id }, "Рахунок скасовано зі збереженням історії.")}>Скасувати рахунок</button>}</div></footer></article>) : <p className="dentistry-muted-empty">Рахунків поки немає. Створити їх можна з виконаних послуг.</p>}</section>}

      {tab === "Оплати" && <div className="dentistry-care-grid"><form className="dentistry-care-panel dentistry-care-form" onSubmit={createPayment}><header><div><h2>Фінансова операція</h2><p>Оплата зменшує борг, а повернення фіксується окремою операцією.</p></div></header><label>Тип операції<select value={payment.type} onChange={event => setPayment({ ...payment, type: event.target.value, invoice_id: "", allocated_amount: "" })}><option value="payment">Прийняти оплату</option><option value="refund">Повернути кошти</option></select></label><label>Сума платежу<input required type="number" min="0.01" step="0.01" value={payment.amount} onChange={event => setPayment({ ...payment, amount: event.target.value })} /></label><label>Спосіб оплати<select value={payment.payment_method} onChange={event => setPayment({ ...payment, payment_method: event.target.value })}><option value="cash">Готівка</option><option value="card">Картка</option><option value="bank_transfer">Банківський переказ</option></select></label><label>Зарахувати на рахунок<select value={payment.invoice_id} onChange={event => setPayment({ ...payment, invoice_id: event.target.value, allocated_amount: event.target.value ? payment.amount : "" })}><option value="">Не розподіляти · залишити авансом</option>{(payment.type === "refund" ? paidInvoices : openInvoices).map(invoice => <option key={invoice.id} value={invoice.id}>{invoice.invoice_number} · {payment.type === "refund" ? `оплачено ${money(invoice.paid_amount)}` : `залишок ${money(invoice.remaining_amount)}`}</option>)}</select></label>{payment.invoice_id && <label>Сума зарахування<input type="number" min="0.01" step="0.01" max={payment.amount} value={payment.allocated_amount} onChange={event => setPayment({ ...payment, allocated_amount: event.target.value })} /></label>}<label>Коментар<textarea rows="2" value={payment.comment} onChange={event => setPayment({ ...payment, comment: event.target.value })} /></label><button className="primary-action" disabled={busy || !payment.amount} type="submit">{payment.type === "refund" ? "Підтвердити повернення" : "Прийняти оплату"}</button></form><section className="dentistry-care-panel"><header><div><h2>Історія оплат</h2><p>Платежі зберігаються окремо та не видаляються.</p></div><div className="dentistry-advance-chip">Аванс {money(care.summary?.advance)}</div></header>{care.payments?.map(item => <article className="dentistry-care-row" key={item.id}><span className={`dentistry-row-icon ${item.payment_type === "refund" ? "cancel" : "done"}`}><Icon name={item.payment_type === "refund" ? "close" : "check"} /></span><div><strong>{item.payment_type === "refund" ? "Повернення" : "Оплата"} · {money(item.amount)}</strong><small>{dateTime(item.payment_date)} · {({ cash: "Готівка", card: "Картка", bank_transfer: "Переказ" })[item.payment_method] || item.payment_method}{item.comment ? ` · ${item.comment}` : ""}</small></div><small>Розподілено {money(item.allocated_amount)}</small></article>)}{!care.payments?.length && <p className="dentistry-muted-empty">Платежів поки немає.</p>}{availableAdvances.length > 0 && openInvoices.length > 0 && <form className="dentistry-allocate-advance" onSubmit={allocateAdvance}><strong>Зарахувати аванс на рахунок</strong><div className="dentistry-form-inline"><label>Аванс<select required value={advanceAllocation.payment_id} onChange={event => setAdvanceAllocation({ ...advanceAllocation, payment_id: event.target.value })}><option value="">Оберіть аванс</option>{availableAdvances.map(item => <option key={item.id} value={item.id}>{dateTime(item.payment_date)} · залишок {money(Number(item.amount) - Number(item.allocated_amount || 0))}</option>)}</select></label><label>Рахунок<select required value={advanceAllocation.invoice_id} onChange={event => setAdvanceAllocation({ ...advanceAllocation, invoice_id: event.target.value })}><option value="">Оберіть рахунок</option>{openInvoices.map(invoice => <option key={invoice.id} value={invoice.id}>{invoice.invoice_number} · {money(invoice.remaining_amount)}</option>)}</select></label><label>Сума<input required type="number" min="0.01" step="0.01" value={advanceAllocation.amount} onChange={event => setAdvanceAllocation({ ...advanceAllocation, amount: event.target.value })} /></label></div><button className="outline-btn" type="submit" disabled={busy}>Зарахувати аванс</button></form>}</section></div>}

      {tab === "Документи" && <div className="dentistry-care-grid"><form className="dentistry-care-panel dentistry-care-form" onSubmit={uploadDocument}><header><div><h2>Додати документ</h2><p>PDF, JPG, PNG або WEBP. До 10 МБ.</p></div></header><label>Файл<input type="file" accept="application/pdf,image/jpeg,image/png,image/webp" onChange={event => setDocumentFile(event.target.files?.[0] || null)} /></label><button className="primary-action" disabled={busy || !documentFile} type="submit">Завантажити</button></form><section className="dentistry-care-panel"><header><div><h2>Документи пацієнта</h2><p>Файли зберігаються поза публічною директорією та завантажуються з перевіркою доступу.</p></div></header>{care.documents?.map(item => <a className="dentistry-care-row" href={apiUrl(`/api/dentistry-care.php?action=download_document&id=${encodeURIComponent(item.id)}`)} key={item.id}><span className="dentistry-row-icon"><Icon name="download" /></span><div><strong>{item.original_name}</strong><small>{item.mime_type} · {(Number(item.file_size) / 1024).toFixed(0)} КБ · {dateTime(item.created_at)}</small></div></a>)}{!care.documents?.length && <p className="dentistry-muted-empty">Документів поки немає.</p>}</section></div>}
    </main>
  </Layout>;
}
