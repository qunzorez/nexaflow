import Icon from '../components/Icon.jsx';
import { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { useNavigate } from "react-router-dom";
import Layout from "../components/Layout.jsx";
import { api, post } from "../services/api.js";
import DateTimePicker from "../components/DateTimePicker.jsx";
import SearchableSelect from "../components/SearchableSelect.jsx";
import "./clients.css";

export const specialTaxOptions = [
  ["tourist", "Туристичний збір"],
  ["land", "Земельний податок"],
  ["property", "Податок на нерухомість"],
  ["rent", "Рентна плата"],
  ["environmental", "Екологічний податок"],
  ["transport", "Транспортний податок"],
  ["retail_excise", "Роздрібний акциз"],
  ["other", "Інші специфічні податки"],
];
export const legalForms = [
  "ТОВ",
  "ФОП",
  "ПрАТ",
  "АТ",
  "Неприбуткова організація",
  "Інше",
];
export const taxSystems = [
  "Загальна система",
  "Єдиний податок 3 група",
  "Єдиний податок 5 група",
  "Єдиний податок 4 група",
  "Без системи оподаткування",
];
export const serviceOptions = [
  "Ведення бухгалтерського обліку",
  "Податкова звітність",
  "Податкова звітність ТОВ",
  "Єдиний податок та декларація ФОП",
  "ПДВ та податкові накладні",
  "Реєстрація платника ПДВ",
  "Зарплата і кадровий облік",
  "ЄСВ та звітність за працівників",
  "Первинні документи",
  "Фінансова звітність",
  "Управлінський облік",
  "Банківські операції та платежі",
  "Облік основних засобів",
  "Податкові консультації",
  "Підготовка до перевірки",
  "Відновлення обліку",
  "Реєстрація та закриття ФОП",
  "Реєстрація та зміни ТОВ",
  "Інше",
];
export const serviceOptionsByLegalForm = {
  ФОП: [
    "Ведення обліку ФОП",
    "Єдиний податок та декларація ФОП",
    "Реєстрація та закриття ФОП",
    "Податкова звітність",
    "ЄСВ та звітність за працівників",
    "ПДВ та податкові накладні",
    "Реєстрація платника ПДВ",
    "Зарплата і кадровий облік",
    "Первинні документи",
    "Банківські операції та платежі",
    "Податкові консультації",
    "Підготовка до перевірки",
    "Відновлення обліку",
    "Інше",
  ],
  ТОВ: [
    "Ведення бухгалтерського обліку",
    "Податкова звітність ТОВ",
    "Реєстрація та зміни ТОВ",
    "ПДВ та податкові накладні",
    "Реєстрація платника ПДВ",
    "Фінансова звітність",
    "Управлінський облік",
    "Зарплата і кадровий облік",
    "ЄСВ та звітність за працівників",
    "Первинні документи",
    "Банківські операції та платежі",
    "Облік основних засобів",
    "Податкові консультації",
    "Підготовка до перевірки",
    "Відновлення обліку",
    "Інше",
  ],
  ПрАТ: serviceOptions,
  АТ: serviceOptions,
  "Неприбуткова організація": serviceOptions,
  Інше: serviceOptions,
};
function officialDateForService(name = "", period = "Місяць") {
  const today = new Date();
  const lower = name.toLocaleLowerCase("uk");
  const result = new Date(today.getFullYear(), today.getMonth(), today.getDate());
  if (lower.includes("туристич") || lower.includes("земельн") || lower.includes("нерухом") || lower.includes("транспорт")) {
    const quarterEndMonth = Math.floor(result.getMonth() / 3) * 3 + 2;
    const quarterEnd = new Date(result.getFullYear(), quarterEndMonth + 1, 0);
    result.setTime(quarterEnd.getTime());
    result.setDate(result.getDate() + (lower.includes("туристич") ? 40 : 30));
  } else if (lower.includes("зарплат") || lower.includes("єсв")) {
    result.setMonth(result.getMonth() + 1, 7);
  } else if (period === "Квартал") {
    const quarterEndMonth = Math.floor(result.getMonth() / 3) * 3 + 2;
    result.setTime(new Date(result.getFullYear(), quarterEndMonth + 1, 20).getTime());
  } else if (period === "Рік") {
    result.setFullYear(result.getFullYear() + 1, 1, 20);
  } else if (period === "Щотижня") result.setDate(result.getDate() + (7 - result.getDay() || 7));
  else if (period === "Щодня") result.setDate(result.getDate() + 1);
  else if (period === "Щогодини") result.setHours(result.getHours() + 1, 0, 0, 0);
  else result.setMonth(result.getMonth() + 1, 20);
  return result.toISOString().slice(0, 10);
}
const servicePriceDefaultsKey = "nexaflow.accounting.servicePriceDefaults";
const readServicePriceDefaults = () => {
  try { return JSON.parse(window.localStorage.getItem(servicePriceDefaultsKey) || "{}"); } catch { return {}; }
};
const responsibleAmountFor = (price, percent) => Math.max(0, Math.round(Number(price || 0) * Number(percent || 0) / 100 * 100) / 100);
export function downloadClientInvoice(client) {
  const services = (client.services || []).filter((service) => service.name);
  const total = services.reduce((sum, service) => sum + Number(service.price || 0), 0);
  const escape = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;" }[char]));
  const invoiceNumber = `NF-${new Date().toISOString().slice(0, 10).replaceAll("-", "")}-${Math.floor(1000 + Math.random() * 9000)}`;
  const invoiceDate = new Date().toLocaleDateString("uk-UA");
  const rows = services.map((service, index) => `<tr><td style="text-align:center">${index + 1}</td><td>${escape(service.name)}</td><td style="text-align:center">1</td><td>${escape(service.period || "Місяць")}</td><td style="text-align:right">${Number(service.price || 0).toLocaleString("uk-UA", { minimumFractionDigits: 2 })} грн</td></tr>`).join("");
  const html = `<!doctype html><html><head><meta charset="utf-8"><title>Рахунок ${invoiceNumber}</title><style>body{font-family:Arial,sans-serif;color:#1f2b26;margin:36px;font-size:12px}h1{margin:0;color:#168968;font-size:24px}h2{margin:28px 0 6px;font-size:18px}p{margin:6px 0}.top{display:flex;justify-content:space-between;border-bottom:3px solid #168968;padding-bottom:18px}.muted{color:#718078}.meta{margin-top:22px;display:grid;grid-template-columns:1fr 1fr;gap:24px}.box{border:1px solid #d9e5de;border-radius:6px;padding:14px}.label{color:#718078;font-size:10px;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px}table{width:100%;border-collapse:collapse;margin-top:22px}th{background:#eaf6ef;color:#315d4b;text-align:left}th,td{border:1px solid #cbdad1;padding:10px}tfoot td{font-weight:bold;font-size:14px;background:#f5faf6}.note{margin-top:24px;padding:13px;background:#f5faf6;border-left:4px solid #168968}.footer{margin-top:40px;color:#718078;font-size:10px}</style></head><body><div class="top"><div><h1>NexaFlow</h1><p class="muted">Рахунок на оплату послуг</p></div><div style="text-align:right"><strong>Рахунок № ${invoiceNumber}</strong><p>від ${invoiceDate}</p></div></div><div class="meta"><div class="box"><div class="label">Постачальник</div><strong>NexaFlow</strong><p class="muted">Реквізити будуть додані пізніше</p></div><div class="box"><div class="label">Платник</div><strong>${escape(client.name || "Новий клієнт")}</strong><p>${escape(client.legal_form || "")}</p><p class="muted">Система: ${escape(client.tax_system || "Не вказано")}</p></div></div><h2>Перелік послуг</h2><table><thead><tr><th style="width:45px">№</th><th>Назва послуги</th><th style="width:70px">К-сть</th><th style="width:120px">Період</th><th style="width:150px">Сума</th></tr></thead><tbody>${rows || '<tr><td colspan="5">Послуги не додані</td></tr>'}</tbody><tfoot><tr><td colspan="4" style="text-align:right">Разом до сплати:</td><td style="text-align:right">${total.toLocaleString("uk-UA", { minimumFractionDigits: 2 })} грн</td></tr></tfoot></table><div class="note"><strong>Оподаткування:</strong> ${client.has_vat ? "ПДВ включено" : "Без ПДВ"}</div><div class="footer">Документ сформовано в NexaFlow. Реквізити для оплати будуть додані після налаштування профілю компанії.</div></body></html>`;
  const blob = new Blob([`\ufeff${html}`], { type: "application/vnd.ms-excel;charset=utf-8" });
  const link = document.createElement("a"); link.href = URL.createObjectURL(blob); link.download = `rahunok-${String(client.name || "client").replace(/[^a-zа-яіїє0-9]+/gi, "-").replace(/^-|-$/g, "") || "client"}.xls`; link.click(); URL.revokeObjectURL(link.href);
}
export function serviceTaskPayload(client, service, clientId) {
  const normalizeId = (value) => {
    const id = String(value || "").trim();
    return /^[a-f0-9]{32}$/i.test(id) ? id : "";
  };
  const normalizedClientId = normalizeId(clientId);
  const rawDueDate = String(service.official_due_date || "").trim();
  const dueDate = /^\d{4}-\d{2}-\d{2}$/.test(rawDueDate)
    ? rawDueDate
    : (/^(\d{2})\.(\d{2})\.(\d{4})$/.test(rawDueDate)
      ? rawDueDate.replace(/^(\d{2})\.(\d{2})\.(\d{4})$/, "$3-$2-$1")
      : null);
  let reminderAt = null;
  if (service.reminders_enabled !== false && dueDate) {
    const reminderDate = new Date(`${dueDate}T12:00:00`);
    reminderDate.setDate(reminderDate.getDate() - Number(service.internal_days_before ?? 5));
    const date = reminderDate.toISOString().slice(0, 10);
    reminderAt = `${date}T09:00`;
  }
  const taxes = (client.special_taxes || []).join(", ") || "немає";
  const employeeText = client.has_employees ? `так, ${client.employee_count || "кількість не вказана"}` : "ні";
  const recurrenceByPeriod = {
    "Щогодини": "hourly",
    "Щодня": "daily",
    "Щотижня": "weekly",
    "Місяць": "monthly",
    "Квартал": "quarterly",
    "Рік": "yearly",
    "Разово": "none",
  };
  const recurrenceRule = recurrenceByPeriod[service.period || "Разово"] || "none";
  const packageDetails = service.is_package && (service.package_services || []).length
    ? `\nПакет містить послуги: ${service.package_services.join(", ")}.`
    : "";
  return {
    action: "create",
    title: `${service.name} — ${client.name}`,
    description: `Послуга для клієнта «${client.name}».\n\nФорма власності: ${client.legal_form || "не вказано"}\nСистема оподаткування: ${client.tax_system || "не вказано"}\nПДВ: ${client.has_vat ? "так" : "ні"}\nАкциз: ${client.has_excise ? "так" : "ні"}\nНаймані працівники: ${employeeText}\nСпецифічні податки: ${taxes}\n\nПослуга: ${service.name}.${packageDetails}\nПеріодичність: ${service.period || "не вказано"}\nОфіційна дата: ${dueDate || "не вказано"}\nВнутрішній дедлайн: ${service.internal_days_before ?? 5} дн. до офіційної дати.`,
    client_ids: normalizedClientId ? [normalizedClientId] : [],
    assignee_ids: normalizeId(service.accountant_id) ? [normalizeId(service.accountant_id)] : [],
    project_ids: [],
    status: "todo",
    priority: "normal",
    due_date: dueDate,
    reminder_at: reminderAt,
    creator_reminder: false,
    recurrence_rule: recurrenceRule,
  };
}
export const emptyForm = {
  name: "",
  legal_form: "ТОВ",
  tax_system: "Загальна система",
  has_vat: false,
  has_excise: false,
  has_employees: false,
  employee_count: "",
  special_taxes: [],
  module: window.localStorage.getItem("nexaflow.client.module") || "accounting",
  module_data: {},
  services: [],
};
const moduleNames = {
  accounting: "Бухгалтерія",
  dentistry: "Стоматологія",
  legal: "Юридичні послуги",
  real_estate: "Нерухомість",
};

function Toggle({ label, value, onChange }) {
  return (
    <label className={`client-toggle ${value ? "is-active" : ""}`}>
      <input
        type="checkbox"
        checked={value}
        onChange={(event) => onChange(event.target.checked)}
      />
      <span>{label}</span>
      <small>{value ? "Є" : "Немає"}</small>
    </label>
  );
}
function ServiceSelect({ value, onChange, legalForm }) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState("");
  const selectRef = useRef(null);
  const legalFormOptions = serviceOptionsByLegalForm[legalForm] || serviceOptions;
  const options = [...new Set(value && !legalFormOptions.includes(value) ? [value, ...legalFormOptions] : legalFormOptions)]
    .filter((option) => option.toLocaleLowerCase("uk").includes(search.toLocaleLowerCase("uk")));
  useEffect(() => {
    if (!open) return undefined;
    const closeOnOutsideClick = (event) => {
      if (!selectRef.current?.contains(event.target)) setOpen(false);
    };
    document.addEventListener("mousedown", closeOnOutsideClick);
    return () => document.removeEventListener("mousedown", closeOnOutsideClick);
  }, [open]);
  return <div ref={selectRef} className="service-select">
    <button type="button" className="service-select-trigger" onClick={() => setOpen((current) => !current)}><span>{value || "Оберіть послугу"}</span><Icon name="down" /></button>
    {open && <div className="service-select-menu"><div className="service-select-search"><Icon name="search" /><input autoFocus value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Пошук послуги…" /></div><div className="service-select-options">{options.length ? options.map((option) => <button type="button" key={option} onClick={() => { onChange(option); setOpen(false); setSearch(""); }}>{option}{value === option && <Icon name="check" />}</button>) : <span>Послуг не знайдено</span>}</div></div>}
  </div>;
}
function AccountantSelect({ value, onChange, options, emptyMessage }) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState("");
  const selectRef = useRef(null);
  const selected = options.find((person) => person.id === value);
  const filtered = options.filter((person) => `${person.name} ${person.email}`.toLocaleLowerCase("uk").includes(search.toLocaleLowerCase("uk")));
  useEffect(() => {
    if (!open) return undefined;
    const close = (event) => { if (!selectRef.current?.contains(event.target)) setOpen(false); };
    document.addEventListener("mousedown", close);
    return () => document.removeEventListener("mousedown", close);
  }, [open]);
  return <div ref={selectRef} className="service-select accountant-select">
    <button type="button" className="service-select-trigger" onClick={() => setOpen((current) => !current)}><span>{selected ? selected.name : "Оберіть виконавця"}</span><Icon name="down" /></button>
    {open && <div className="service-select-menu"><div className="service-select-search"><Icon name="search" /><input autoFocus value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Пошук бухгалтера…" /></div><div className="service-select-options">{!options.length ? <span>{emptyMessage}</span> : filtered.length ? filtered.map((person) => <button type="button" key={person.id} onClick={() => { onChange(person.id); setOpen(false); setSearch(""); }}>{person.name}{value === person.id && <Icon name="check" />}</button>) : <span>Бухгалтера не знайдено</span>}</div></div>}
  </div>;
}
function FieldTip({ children }) {
  const [open, setOpen] = useState(false);
  const tipId = useRef(`field-tip-${Math.random().toString(36).slice(2)}`);
  const tipRef = useRef(null);
  useEffect(() => {
    const closeOtherTips = (event) => {
      if (event.detail !== tipId.current) setOpen(false);
    };
    const closeOnOutsideClick = (event) => {
      if (tipRef.current && !tipRef.current.contains(event.target)) setOpen(false);
    };
    window.addEventListener("nexaflow:field-tip-open", closeOtherTips);
    document.addEventListener("mousedown", closeOnOutsideClick);
    return () => {
      window.removeEventListener("nexaflow:field-tip-open", closeOtherTips);
      document.removeEventListener("mousedown", closeOnOutsideClick);
    };
  }, []);
  const toggle = () => setOpen((current) => {
    const next = !current;
    if (next) window.dispatchEvent(new CustomEvent("nexaflow:field-tip-open", { detail: tipId.current }));
    return next;
  });
  return <span ref={tipRef} className="client-field-tip">
    <button type="button" aria-label="Показати підказку" aria-expanded={open} onClick={toggle}><Icon name="circle-question" /></button>
    <span className={`client-field-tooltip${open ? " is-open" : ""}`} role="tooltip">{children}</span>
  </span>;
}
export function ClientFormModal({ form, setForm, editing, onSave, onClose, onAddService, pageMode = false, availableUsers = [], availableUsersByClient = {}, clientId = "", servicePackages = [] }) {
  const [savingServiceIndex, setSavingServiceIndex] = useState(null);
  const [selectedPackageId, setSelectedPackageId] = useState("");
  const formElementRef = useRef(null);
  const set = (field, value) =>
    setForm((current) => {
      const nameInput = formElementRef.current?.elements?.namedItem("name");
      const currentName = typeof nameInput?.value === "string" ? nameInput.value : current.name;
      return { ...current, [field]: value, name: field === "name" ? value : currentName };
    });
  const toggleTax = (value) =>
    set(
      "special_taxes",
      form.special_taxes.includes(value)
        ? form.special_taxes.filter((item) => item !== value)
        : [...form.special_taxes, value],
    );
  const updateService = (index, field, value) => set("services", (form.services || []).map((service, itemIndex) => {
    if (itemIndex !== index) return service;
    if (field === "name") return { ...service, name: value, price: value ? (readServicePriceDefaults()[value] ?? 0) : 0, official_due_date: value ? officialDateForService(value, service.period) : "" };
    if (field === "period") return { ...service, period: value, official_due_date: service.name ? officialDateForService(service.name, value) : "" };
    if (field === "price" && service.responsible_mode === "percent") return { ...service, price: value, responsible_amount: responsibleAmountFor(value, service.responsible_percent) };
    if (field === "responsible_mode") return { ...service, responsible_mode: value, responsible_amount: value === "percent" ? responsibleAmountFor(service.price, service.responsible_percent) : service.responsible_amount };
    if (field === "responsible_percent") return { ...service, responsible_percent: value, responsible_amount: responsibleAmountFor(service.price, value) };
    return { ...service, [field]: value };
  }));
  const addService = () => set("services", [{ name: "", price: 0, responsible_amount: 0, responsible_mode: "amount", responsible_percent: "", period: "Місяць", official_due_date: "", accountant_id: "", internal_days_before: 5, parameters: [], reminders_enabled: true, create_task: false, _isNew: true }, ...(form.services || [])]);
  const addPackage = () => {
    const packageItem = servicePackages.find((item) => String(item.id) === String(selectedPackageId));
    if (!packageItem) return;
    const packageServiceNames = (packageItem.services || []).map((service) => service.name).filter(Boolean);
    set("services", [{ name: packageItem.name, price: Number(packageItem.client_price || 0), responsible_amount: Number(packageItem.responsible_amount || 0), responsible_mode: "amount", responsible_percent: "", period: "Місяць", official_due_date: "", accountant_id: "", internal_days_before: 5, parameters: packageServiceNames, package_id: packageItem.id, package_name: packageItem.name, package_services: packageServiceNames, package_responsible_amount: Number(packageItem.responsible_amount || 0), is_package: true, reminders_enabled: true, create_task: false, _isNew: true }, ...(form.services || [])]);
    setSelectedPackageId("");
  };
  const saveNewService = async (index) => {
    const nameInput = formElementRef.current?.elements?.namedItem("name");
    const currentForm = {
      ...form,
      name: typeof nameInput?.value === "string" ? nameInput.value : form.name,
    };
    const service = currentForm.services?.[index];
    if (!service?.name || !onAddService || savingServiceIndex !== null) return;
    if (Number(service.price || 0) === 0 && !window.confirm(`У послуги «${service.name}» вказана ціна 0 грн. Послуга дійсно коштує 0 грн?`)) return;
    setSavingServiceIndex(index);
    const services = (currentForm.services || []).map((item, itemIndex) => {
      const { _isNew, ...clean } = item;
      return itemIndex === index ? clean : (_isNew ? clean : item);
    });
    rememberServicePrice(services[index]?.name, services[index]?.price);
    const saved = await onAddService({ ...currentForm, services }, services[index]);
    if (saved) set("services", services);
    setSavingServiceIndex(null);
  };
  // Create and edit must use the same manager-scoped list. The per-client
  // membership list is narrower and made the edit form behave differently.
  const eligibleAccountants = availableUsers;
  const removeService = (index) => set("services", (form.services || []).filter((_, itemIndex) => itemIndex !== index));
  const packagePriceTiers = ["gray", "cyan", "blue", "purple", "red", "gold"];
  const packageTier = (service) => {
    if (!service.is_package) return "";
    const prices = servicePackages.map((item) => Number(item.client_price || 0)).sort((left, right) => left - right);
    if (prices.length <= 1) return "gold";
    const price = Number(service.price || 0);
    const index = Math.max(0, prices.findIndex((item) => item >= price));
    return packagePriceTiers[Math.round(index / (prices.length - 1) * (packagePriceTiers.length - 1))];
  };
  const serviceTotal = (form.services || []).reduce((sum, service) => sum + Number(service.price || 0), 0);
  const rememberServicePrice = (name, price) => {
    if (form.module !== "accounting" || !name) return;
    const defaults = readServicePriceDefaults();
    defaults[name] = price === "" ? 0 : price;
    window.localStorage.setItem(servicePriceDefaultsKey, JSON.stringify(defaults));
  };
  const rememberServicePrices = () => {
    if (form.module !== "accounting") return;
    const defaults = readServicePriceDefaults();
    (form.services || []).forEach((service) => { if (service.name && service.price !== "") defaults[service.name] = service.price; });
    window.localStorage.setItem(servicePriceDefaultsKey, JSON.stringify(defaults));
  };
  const content = (
    <div
      className={pageMode ? "client-page-form-shell" : "client-modal-backdrop"}
      onMouseDown={(event) => !pageMode && event.target === event.currentTarget && onClose()}
    >
      <section className={`client-modal ${pageMode ? "client-page-form" : ""}`}>
        <button type="button" className="client-modal-close" onClick={onClose} aria-label="Закрити">
          <Icon name="close" />
        </button>
        <div className="client-modal-scroll">
          <p className="eyebrow">КЛІЄНТ</p>
          <h2>{editing ? "Редагувати клієнта" : "Створити клієнта"}</h2>
          <p className="client-modal-subtitle">
            Додайте організаційні та податкові дані клієнта.
          </p>
          <div className="client-module-badge">
            Модуль:{" "}
            <strong>
              {moduleNames[form.module] || moduleNames.accounting}
            </strong>
          </div>
          <form ref={formElementRef} className="client-form" onSubmit={(event) => { rememberServicePrices(); onSave(event); }}>
            {form.module !== "accounting" && (
              <div className="client-module-fields">
                <h3>Дані модуля</h3>
                <div className="client-form-grid">
                  <label>
                    Контактна особа
                    <input
                      value={form.module_data?.contact_person || ""}
                      onChange={(event) =>
                        set("module_data", {
                          ...form.module_data,
                          contact_person: event.target.value,
                        })
                      }
                      placeholder="Ім’я та прізвище"
                    />
                  </label>
                  <label>
                    Телефон
                    <input
                      value={form.module_data?.phone || ""}
                      onChange={(event) =>
                        set("module_data", {
                          ...form.module_data,
                          phone: event.target.value,
                        })
                      }
                      placeholder="+380…"
                    />
                  </label>
                  <label>
                    Email
                    <input
                      type="email"
                      value={form.module_data?.email || ""}
                      onChange={(event) =>
                        set("module_data", {
                          ...form.module_data,
                          email: event.target.value,
                        })
                      }
                      placeholder="client@example.com"
                    />
                  </label>
                  {form.module === "dentistry" && (
                    <label>
                      Дата народження
                      <DateTimePicker
                        value={form.module_data?.birth_date || ""}
                        onChange={(birth_date) =>
                          set("module_data", { ...form.module_data, birth_date })
                        }
                      />
                    </label>
                  )}
                  {form.module === "dentistry" && (
                    <label>
                      Тип клієнта
                      <select
                        value={form.module_data?.patient_type || "Пацієнт"}
                        onChange={(event) =>
                          set("module_data", {
                            ...form.module_data,
                            patient_type: event.target.value,
                          })
                        }
                      >
                        <option>Пацієнт</option>
                        <option>Дитина</option>
                        <option>Сім’я</option>
                      </select>
                    </label>
                  )}
                  {form.module === "legal" && (
                    <label>
                      Тип справи
                      <input
                        value={form.module_data?.case_type || ""}
                        onChange={(event) =>
                          set("module_data", {
                            ...form.module_data,
                            case_type: event.target.value,
                          })
                        }
                        placeholder="Договір, судова справа…"
                      />
                    </label>
                  )}
                  {form.module === "real_estate" && (
                    <label>
                      Тип об’єкта
                      <input
                        value={form.module_data?.property_type || ""}
                        onChange={(event) =>
                          set("module_data", {
                            ...form.module_data,
                            property_type: event.target.value,
                          })
                        }
                        placeholder="Квартира, будинок, офіс…"
                      />
                    </label>
                  )}
                </div>
              </div>
            )}
            <div className="client-form-grid">
              <label>
                Назва клієнта
                <input
                  name="name"
                  value={form.name}
                  onChange={(event) => set("name", event.target.value)}
                  required
                  placeholder="Наприклад, ТОВ «Некса»"
                />
              </label>
              <label>
                Форма власності
                <select
                  value={form.legal_form}
                  onChange={(event) => set("legal_form", event.target.value)}
                >
                  {legalForms.map((item) => (
                    <option key={item}>{item}</option>
                  ))}
                </select>
              </label>
              <label>
                Система оподаткування
                <select
                  value={form.tax_system}
                  onChange={(event) => set("tax_system", event.target.value)}
                >
                  {taxSystems.map((item) => (
                    <option key={item}>{item}</option>
                  ))}
                </select>
              </label>
            </div>
            <div className="client-toggles">
              <Toggle
                label="ПДВ"
                value={form.has_vat}
                onChange={(value) => set("has_vat", value)}
              />
              <Toggle
                label="Акциз"
                value={form.has_excise}
                onChange={(value) => set("has_excise", value)}
              />
              <Toggle
                label="Наймані працівники"
                value={form.has_employees}
                onChange={(value) => set("has_employees", value)}
              />
              {form.has_employees && (
                <label className="employee-count">
                  Кількість працівників
                  <input
                    type="number"
                    min="1"
                    value={form.employee_count}
                    onChange={(event) =>
                      set("employee_count", event.target.value)
                    }
                    required
                  />
                </label>
              )}
            </div>
            <fieldset>
              <legend>Специфічні податки</legend>
              <div className="tax-checkbox-grid">
                {specialTaxOptions.map(([value, label]) => (
                  <label
                    key={value}
                    className={
                      form.special_taxes.includes(value) ? "checked" : ""
                    }
                  >
                    <input
                      type="checkbox"
                      checked={form.special_taxes.includes(value)}
                      onChange={() => toggleTax(value)}
                    />
                    <span>{label}</span>
                  </label>
                ))}
              </div>
            </fieldset>
            {form.module === "accounting" && <fieldset className="client-services-fieldset">
              <legend>Необхідні бухгалтерські послуги</legend>
              <p className="client-field-hint">Додайте послуги для цього клієнта та їхню вартість.</p>
              <div className="client-services-add-box"><div><strong>Додати окрему послугу</strong><small>Оберіть послугу — дата заповниться автоматично за відомим строком.</small></div><button type="button" className="primary-action" onClick={addService}><Icon name="plus" /> Додати послугу</button></div>
              {servicePackages.length > 0 && <div className="client-services-package-box"><div><strong>Додати пакет послуг</strong><small>Пакет додасться однією позицією із загальною ціною та відповідальним.</small></div><div className="client-services-package-controls"><SearchableSelect options={servicePackages.map((packageItem) => ({ id: packageItem.id, name: `${packageItem.name} · ${Number(packageItem.client_price || 0).toLocaleString("uk-UA", { minimumFractionDigits: 2 })} грн` }))} value={selectedPackageId} onChange={setSelectedPackageId} placeholder="Оберіть пакет" /><button type="button" className="outline-btn" disabled={!selectedPackageId} onClick={addPackage}><Icon name="plus" /> Додати пакет</button></div></div>}
              <div className="client-services-list-label">Додані послуги <span>{(form.services || []).length}</span></div>
              <div className="client-services-list">
                {(form.services || []).map((service, index) => <div className={`client-service-card${service._isNew ? " client-service-card-new" : ""}${service.is_package ? ` client-package-card price-tier-${packageTier(service)}` : ""}`} key={`${index}-${service.name}`}>
                  <div className={`client-service-row${service.is_package ? " is-package" : ""}`}>
                    <div className="client-service-field client-service-name-field"><small>Послуга</small>{service.is_package ? <div className="client-package-name"><span className="client-package-badge"><Icon name="subscription" /> ПАКЕТ</span><strong>{service.package_name || service.name}</strong></div> : <ServiceSelect value={service.name} legalForm={form.legal_form} onChange={(value) => updateService(index, "name", value)} />}</div>
                    <div className="client-service-field"><small>Ціна для клієнта</small><span className={`client-service-price${service.is_package ? " is-readonly" : ""}`}><input type="number" min="0" step="0.01" value={service.price} readOnly={Boolean(service.is_package)} onChange={(event) => updateService(index, "price", event.target.value)} onBlur={(event) => rememberServicePrice(service.name, event.target.value)} placeholder="0" aria-label={service.is_package ? "Ціна пакета" : "Ціна послуги"} /><b>грн</b></span></div>
                    {service.is_package ? <div className="client-service-field client-package-payout-field"><small>Виплата виконавцю</small><span className="client-service-price is-readonly"><input type="number" value={service.package_responsible_amount ?? service.responsible_amount ?? 0} readOnly aria-label="Виплата за пакет" /><b>грн</b></span></div> : <div className="client-service-field"><small>Виплата виконавцю</small><span className="client-service-payout-inline"><input type="number" min="0" max={service.responsible_mode === "percent" ? 100 : undefined} step="0.01" value={service.responsible_mode === "percent" ? (service.responsible_percent ?? "") : (service.responsible_amount ?? 0)} onChange={(event) => updateService(index, service.responsible_mode === "percent" ? "responsible_percent" : "responsible_amount", event.target.value)} placeholder={service.responsible_mode === "percent" ? "0" : "0.00"} aria-label="Виплата виконавцю" /><select value={service.responsible_mode || "amount"} onChange={(event) => updateService(index, "responsible_mode", event.target.value)} aria-label="Тип виплати"><option value="amount">грн</option><option value="percent">%</option></select>{service.responsible_mode === "percent" && <small>= {responsibleAmountFor(service.price, service.responsible_percent).toLocaleString("uk-UA", { minimumFractionDigits: 2 })} грн</small>}</span></div>}
                    <div className="client-service-field client-service-period-field"><small>Період</small><select value={service.period || "Місяць"} onChange={(event) => updateService(index, "period", event.target.value)} aria-label="Періодичність"><option>Щогодини</option><option>Щодня</option><option>Щотижня</option><option>Місяць</option><option>Квартал</option><option>Рік</option><option>Разово</option></select></div>
                    <button type="button" className="client-service-remove" onClick={() => removeService(index)} aria-label="Видалити послугу"><Icon name="close" /></button>
                  </div>
                  <div className="client-service-schedule">
                    <label><span className="client-field-label">Офіційна дата<FieldTip>Дата взята з офіційних джерел і типових строків. За потреби ви можете змінити її вручну.</FieldTip></span><DateTimePicker value={service.official_due_date || (service.name ? officialDateForService(service.name, service.period) : "")} onChange={(value) => updateService(index, "official_due_date", value)} /></label>
                    <label><span className="client-field-label">Відповідальний бухгалтер<FieldTip>Тут показуються лише учасники вашої команди.</FieldTip></span><AccountantSelect value={service.accountant_id || ""} options={eligibleAccountants} emptyMessage="У вашій команді немає доступних бухгалтерів." onChange={(value) => updateService(index, "accountant_id", value)} /></label>
                    <label><span className="client-field-label">Внутрішній дедлайн (днів до офіційного)<FieldTip>За скільки днів до офіційної дати нагадати виконавцю.</FieldTip></span><input type="number" min="0" max="90" value={service.internal_days_before ?? 5} onChange={(event) => updateService(index, "internal_days_before", event.target.value)} /></label>
                    <div className="client-service-reminders"><label className="service-reminder-switch"><input type="checkbox" checked={service.reminders_enabled !== false} onChange={(event) => updateService(index, "reminders_enabled", event.target.checked)} /><span className="service-reminder-slider" />Нагадувати про цю послугу</label><label className="service-reminder-switch"><input type="checkbox" checked={Boolean(service.create_task)} onChange={(event) => updateService(index, "create_task", event.target.checked)} /><span className="service-reminder-slider" />Створити задачу для відповідального бухгалтера</label></div>
                  </div>
                  {service._isNew && onAddService && <button type="button" className="client-service-save" disabled={!service.name || savingServiceIndex !== null} onClick={() => saveNewService(index)}><Icon name="check" /> {savingServiceIndex === index ? "Додавання…" : "Додати послугу"}</button>}
                </div>)}
              </div>
              <div className="client-services-total"><span>Разом за послуги</span><strong>{serviceTotal.toLocaleString("uk-UA", { minimumFractionDigits: 2 })} грн</strong><button type="button" className="outline-btn" onClick={() => downloadClientInvoice({ ...form, name: form.name || "Новий клієнт" })}><Icon name="download" /> Згенерувати рахунок</button></div>
            </fieldset>}
            <div className="client-form-actions">
              <button type="button" className="outline-btn" onClick={onClose}>
                Скасувати
              </button>
              <button className={`primary-action client-submit-action ${editing ? "is-save" : "is-create"}`}>
                {editing ? "Зберегти зміни" : "Створити клієнта"}
              </button>
            </div>
          </form>
        </div>
      </section>
    </div>
  );
  return pageMode ? content : createPortal(content, document.body);
}
function Info({ label, value }) {
  return (
    <div>
      <small>{label}</small>
      <strong>{value}</strong>
    </div>
  );
}
function ClientViewModal({ client, onClose, user }) {
  const isSimpleUser = user?.role === "user";
  const servicePayoutAmount = (service) => service.is_package
    ? Number(service.package_responsible_amount ?? service.responsible_amount ?? 0)
    : Number(service.responsible_amount ?? 0);
  const serviceDisplayAmount = (service) => isSimpleUser ? servicePayoutAmount(service) : Number(service.price || 0);
  return createPortal(
    <div
      className="client-modal-backdrop"
      onMouseDown={(event) => event.target === event.currentTarget && onClose()}
    >
      <section className="client-modal client-view-modal">
        <button type="button" className="client-modal-close" onClick={onClose} aria-label="Закрити">
          <Icon name="close" />
        </button>
        <div className="client-modal-scroll">
          <p className="eyebrow">ПРОФІЛЬ КЛІЄНТА</p>
          <h2>{client.name}</h2>
          <p className="client-modal-subtitle">
            Детальна інформація та податковий профіль.
          </p>
          <div className="client-view-grid">
            <Info label="Форма власності" value={client.legal_form} />
            <Info label="Система оподаткування" value={client.tax_system} />
            <Info label="ПДВ" value={client.has_vat ? "Є" : "Немає"} />
            <Info label="Акциз" value={client.has_excise ? "Є" : "Немає"} />
            <Info
              label="Наймані працівники"
              value={
                client.has_employees ? `Є — ${client.employee_count}` : "Немає"
              }
            />
            <Info label="Створив" value={client.creator_name || "—"} />
          </div>
          <div className="client-view-taxes">
            <small>СПЕЦИФІЧНІ ПОДАТКИ</small>
            <div>
              {client.special_taxes?.length ? (
                client.special_taxes.map((key) => (
                  <span key={key}>
                    {specialTaxOptions.find(([value]) => value === key)?.[1] ||
                      key}
                  </span>
                ))
              ) : (
                <em>Не вибрано</em>
              )}
            </div>
          </div>
          {client.module === "accounting" && <><div className="client-view-services"><small>БУХГАЛТЕРСЬКІ ПОСЛУГИ</small>{client.services?.length ? client.services.map((service, index) => <div className="client-view-service" key={`${service.name}-${index}`}><strong>{service.name}<small>{service.official_due_date ? `Офіційний дедлайн: ${new Date(`${service.official_due_date}T12:00:00`).toLocaleDateString("uk-UA")}` : "Офіційний дедлайн не вказано"}</small></strong>{isSimpleUser ? <span className="client-view-service-amounts client-view-service-personal"><small>Ваша виплата</small><b>{serviceDisplayAmount(service).toLocaleString("uk-UA", { minimumFractionDigits: 2 })} грн</b><em>{service.period}</em></span> : <span className="client-view-service-amounts"><small>Для клієнта: {Number(service.price || 0).toLocaleString("uk-UA", { minimumFractionDigits: 2 })} грн</small><small>Бухгалтеру: {servicePayoutAmount(service).toLocaleString("uk-UA", { minimumFractionDigits: 2 })} грн</small><em>{service.period}</em></span>}</div>) : <em>Послуги ще не додані.</em>}</div><div className="client-invoice-summary"><strong>{isSimpleUser ? "Разом до виплати: " : "Разом для клієнта: "}{(client.services || []).reduce((sum, service) => sum + (isSimpleUser ? serviceDisplayAmount(service) : Number(service.price || 0)), 0).toLocaleString("uk-UA", { minimumFractionDigits: 2 })} грн{!isSimpleUser && <small>Бухгалтеру: {(client.services || []).reduce((sum, service) => sum + servicePayoutAmount(service), 0).toLocaleString("uk-UA", { minimumFractionDigits: 2 })} грн</small>}</strong>{!isSimpleUser && <button type="button" className="primary-action" onClick={() => downloadClientInvoice(client)}><Icon name="download" /> Згенерувати рахунок</button>}</div></>}
          <button
            className="outline-btn client-view-close"
            type="button"
            onClick={onClose}
          >
            Закрити
          </button>
        </div>
      </section>
    </div>,
    document.body,
  );
}

export default function Clients() {
  const [data, setData] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [modal, setModal] = useState(false);
  const [editing, setEditing] = useState(null);
  const [viewing, setViewing] = useState(null);
  const [search, setSearch] = useState(() => window.localStorage.getItem("nexaflow.clients.search") || "");
  const [legalFilter, setLegalFilter] = useState(() => window.localStorage.getItem("nexaflow.clients.legal") || "all");
  const [taxFilter, setTaxFilter] = useState(() => window.localStorage.getItem("nexaflow.clients.tax") || "all");
  const [sort, setSort] = useState(() => window.localStorage.getItem("nexaflow.clients.sort") || "date_desc");
  const [page, setPage] = useState(1);
  const [notice, setNotice] = useState("");
  const [error, setError] = useState("");
  const navigate = useNavigate();
  const pageSize = 10;
  async function load() {
    const result = await api("/api/clients.php");
    if (!result.ok) return navigate("/");
    setData(result);
  }
  useEffect(() => {
    load();
  }, []);
  useEffect(() => {
    setPage(1);
  }, [search, legalFilter, taxFilter, sort]);
  useEffect(() => {
    window.localStorage.setItem("nexaflow.clients.sort", sort);
  }, [sort]);
  useEffect(() => { window.localStorage.setItem("nexaflow.clients.search", search); }, [search]);
  useEffect(() => { window.localStorage.setItem("nexaflow.clients.legal", legalFilter); }, [legalFilter]);
  useEffect(() => { window.localStorage.setItem("nexaflow.clients.tax", taxFilter); }, [taxFilter]);
  function openCreate() {
    navigate("/clients/new");
  }
  function openEdit(client) {
    navigate(`/clients/${client.id}/edit`);
  }
  async function save(event) {
    event.preventDefault();
    setNotice("");
    setError("");
    const result = await post("/api/clients.php", {
      ...form,
      action: editing ? "update" : "create",
      id: editing?.id,
    });
    if (!result.ok) return setError(result.message);
    setNotice(result.message);
    setModal(false);
    setEditing(null);
    load();
  }
  async function remove(client) {
    if (!window.confirm(`Видалити клієнта «${client.name}»?`)) return;
    const result = await post("/api/clients.php", {
      action: "delete",
      id: client.id,
    });
    if (!result.ok) return setError(result.message);
    setNotice(result.message);
    load();
  }
  if (!data)
    return <div className="loading-screen">Завантаження клієнтів…</div>;
  const filtered = data.clients.filter((client) => {
    const text =
      `${client.name} ${client.legal_form} ${client.tax_system}`.toLowerCase();
    return (
      text.includes(search.trim().toLowerCase()) &&
      (legalFilter === "all" || client.legal_form === legalFilter) &&
      (taxFilter === "all" || client.tax_system === taxFilter)
    );
  }).sort((left, right) => {
    if (sort === "name_asc" || sort === "name_desc") {
      const comparison = String(left.name || "").localeCompare(String(right.name || ""), "uk");
      return sort === "name_asc" ? comparison : -comparison;
    }
    const leftDate = new Date(left.created_at || 0).getTime();
    const rightDate = new Date(right.created_at || 0).getTime();
    return sort === "date_asc" ? leftDate - rightDate : rightDate - leftDate;
  });
  const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
  const visible = filtered.slice((page - 1) * pageSize, page * pageSize);
  const pageItems = totalPages <= 5
    ? Array.from({ length: totalPages }, (_, index) => index + 1)
    : page <= 3
      ? [1, 2, 3, "ellipsis", totalPages]
      : page >= totalPages - 2
        ? [1, "ellipsis", totalPages - 2, totalPages - 1, totalPages]
        : [1, "ellipsis-left", page - 1, page, page + 1, "ellipsis-right", totalPages];
  return (
    <Layout page="Клієнти" user={data.user}>
      <div className="content clients-page">
        <section className="welcome-row clients-hero">
          <div>
            <p className="eyebrow">РОБОЧИЙ ПРОСТІР</p>
            <h1>Клієнти</h1>
            <p className="subtext">
              Керуйте клієнтами та їхніми податковими особливостями в одному
              місці.
            </p>
          </div>
          {data.permissions?.manage && (
            <button
              className="primary-action client-create-action"
              type="button"
              onClick={openCreate}
            >
              <Icon name="plus" /> Новий клієнт
            </button>
          )}
        </section>
        {notice && <div className="team-notice success">{notice}</div>}
        {error && <div className="form-notice">{error}</div>}
        <section className="panel clients-list-panel">
          <div className="panel-heading">
            <div>
              <div className="heading-with-count">
                <h2>Усі клієнти</h2>
                <span className="task-count-badge">{filtered.length}</span>
              </div>
              <p>Пошук, фільтрація та керування клієнтами.</p>
            </div>
          </div>
          <div className="clients-toolbar">
            <label className="client-search">
              <Icon name="search" />
              <input
                type="search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Пошук за назвою, формою або системою…"
              />
            </label>
            <select
              value={legalFilter}
              onChange={(event) => setLegalFilter(event.target.value)}
            >
              <option value="all">Усі форми</option>
              {legalForms.map((item) => (
                <option key={item}>{item}</option>
              ))}
            </select>
            <select
              value={taxFilter}
              onChange={(event) => setTaxFilter(event.target.value)}
            >
              <option value="all">Усі системи</option>
              {taxSystems.map((item) => (
                <option key={item}>{item}</option>
              ))}
            </select>
            <select value={sort} onChange={(event) => setSort(event.target.value)}>
              <option value="date_desc">Нові спочатку</option>
              <option value="date_asc">Старі спочатку</option>
              <option value="name_asc">Назва: А — Я</option>
              <option value="name_desc">Назва: Я — А</option>
            </select>
          </div>
          {visible.length ? (
            <>
              <div className="clients-table">
                <div className="clients-table-head">
                  <span>Клієнт</span>
                  <span>Форма</span>
                  <span>Оподаткування</span>
                  <span>Особливості</span>
                  <span>Послуги</span>
                  <span>Дії</span>
                </div>
                {visible.map((client) => (
                  <div className="client-row" key={client.id}>
                    <button
                      className="client-name-button"
                      type="button"
                      onClick={() => setViewing(client)}
                    >
                      <strong>{client.name}</strong>
                      <small>Створив: {client.creator_name}</small>
                    </button>
                    <span>{client.legal_form}</span>
                    <span>{client.tax_system}</span>
                    <span className="client-tags">
                      {client.has_vat ? <i>ПДВ</i> : null}
                      {client.has_excise ? <i>Акциз</i> : null}
                      {client.has_employees ? (
                        <i>{client.employee_count} працівн.</i>
                      ) : null}
                      {(client.special_taxes || []).map((key) => (
                        <i key={key}>
                          {specialTaxOptions.find(
                            ([value]) => value === key,
                          )?.[1] || key}
                        </i>
                      ))}
                    </span>
                    <span className="client-tags client-service-tags">
                      {client.services?.length ? client.services.map((service, index) => <i key={`${service.name}-${index}`} title={`${service.name} — ${service.period}`}>{service.name}</i>) : <em>Не додано</em>}
                    </span>
                    <div className="client-actions">
                      <button
                        type="button"
                        className="client-view-btn"
                        onClick={() => setViewing(client)}
                      >
                        Перегляд
                      </button>
                      {data.permissions?.manage && (
                        <>
                          <button
                            type="button"
                            className="client-edit"
                            onClick={() => openEdit(client)}
                          >
                            Редагувати
                          </button>
                          <button
                            className="client-delete"
                            type="button"
                            onClick={() => remove(client)}
                          >
                            Видалити
                          </button>
                        </>
                      )}
                    </div>
                  </div>
                ))}
              </div>
              <div className="clients-pagination">
                <small>
                  Показано {(page - 1) * pageSize + 1}–
                  {Math.min(page * pageSize, filtered.length)} з{" "}
                  {filtered.length} · Сторінка {page} з {totalPages}
                </small>
                <div className="clients-page-buttons">
                  <button
                    disabled={page === 1}
                    onClick={() => setPage((value) => value - 1)}
                   aria-label="Попередня сторінка">
                    <Icon name="left" />
                  </button>
                  {pageItems.map((item, index) => item.toString().startsWith("ellipsis") ? (
                    <span className="clients-page-ellipsis" key={`${item}-${index}`}>…</span>
                  ) : (
                    <button
                      type="button"
                      className={item === page ? "is-current" : ""}
                      aria-current={item === page ? "page" : undefined}
                      onClick={() => setPage(item)}
                      key={item}
                    >{item}</button>
                  ))}
                  <button
                    disabled={page === totalPages}
                    onClick={() => setPage((value) => value + 1)}
                   aria-label="Наступна сторінка">
                    <Icon name="right" />
                  </button>
                </div>
              </div>
            </>
          ) : (
            <div className="clients-empty">
              <span><Icon name="user" /></span>
              <h3>Клієнтів не знайдено</h3>
              <p>Змініть фільтри або додайте нового клієнта.</p>
            </div>
          )}
        </section>
      </div>
      {modal && (
        <ClientFormModal
          form={form}
          setForm={setForm}
          editing={editing}
          availableUsers={data?.available_users || []}
          availableUsersByClient={data?.available_users_by_client || {}}
          onSave={save}
          onClose={() => setModal(false)}
        />
      )}
      {viewing && (
        <ClientViewModal client={viewing} user={data.user} onClose={() => setViewing(null)} />
      )}
    </Layout>
  );
}
