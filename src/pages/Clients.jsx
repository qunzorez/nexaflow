import Icon from '../components/Icon.jsx';
import { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { useNavigate } from "react-router-dom";
import Layout from "../components/Layout.jsx";
import { api, post } from "../services/api.js";
import { isPlanLimitReached, planLimitHint } from "../utils/planLimits.js";
import DateTimePicker from "../components/DateTimePicker.jsx";
import SearchableSelect from "../components/SearchableSelect.jsx";
import DentistryClientsPage from "./DentistryClientsPage.jsx";
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
export const taxSystems = ["1 група", "2 група", "3 група", "4 група", "Загальна система", "Без системи оподаткування"];
const taxSystemsByLegalForm = {
  ФОП: ["1 група", "2 група", "3 група", "4 група", "Загальна система"],
  ТОВ: ["Єдиний податок, 3 група", "Єдиний податок, 4 група", "Загальна система"],
  ПрАТ: ["Єдиний податок, 3 група", "Єдиний податок, 4 група", "Загальна система"],
  АТ: ["Єдиний податок, 3 група", "Єдиний податок, 4 група", "Загальна система"],
  "Неприбуткова організація": ["Неприбутковий статус", "Загальна система"],
  Інше: ["Єдиний податок, 3 група", "Єдиний податок, 4 група", "Загальна система", "Неприбутковий статус"],
};
function canonicalTaxSystem(value, legalForm = "ФОП") {
  const normalized = ({
    "ФОП 1 група": "1 група", "ФОП 2 група": "2 група", "ФОП 3 група": "3 група",
    "ФОП на загальній системі": "Загальна система", "ТОВ на загальній системі": "Загальна система",
    "ТОВ на єдиному податку (3 група)": "3 група", "Єдиний податок 1 група": "1 група",
    "Єдиний податок 2 група": "2 група", "Єдиний податок 3 група": "3 група",
    "Єдиний податок 4 група": "4 група",
  })[value] || value;
  return legalForm !== "ФОП" && /^[34] група$/.test(normalized) ? `Єдиний податок, ${normalized}` : normalized;
}
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
const DENTAL_TEETH = {
  upperRight: [18, 17, 16, 15, 14, 13, 12, 11],
  upperLeft: [21, 22, 23, 24, 25, 26, 27, 28],
  lowerRight: [48, 47, 46, 45, 44, 43, 42, 41],
  lowerLeft: [31, 32, 33, 34, 35, 36, 37, 38],
};
const DENTAL_TOOTH_SHAPES = {
  front: "M-9 1 C-14 3 -15 10 -12 16 C-10 20 -7 22 -5 25 C-5 37 -8 53 -4 66 C-2 72 2 71 4 64 L7 27 C11 24 14 20 15 14 C17 6 12 1 8 2 C2 3 -3 3 -9 1 Z",
  canine: "M-9 4 C-14 8 -14 15 -10 20 C-8 23 -5 25 -5 29 C-6 42 -9 56 -4 67 C-2 72 2 71 4 64 L7 29 C10 26 14 22 15 16 C17 8 12 4 8 5 C4 6 2 3 0 0 C-2 3 -5 6 -9 4 Z",
  premolar: "M-13 3 C-17 7 -15 16 -11 21 C-9 24 -6 25 -6 29 C-5 40 -12 55 -10 66 C-9 71 -5 71 -2 64 L2 45 L6 65 C8 71 13 69 14 63 C16 52 8 39 9 29 C13 25 17 21 17 14 C18 7 13 2 8 3 C3 4 -2 5 -5 3 C-8 1 -11 1 -13 3 Z",
  molar: "M-15 3 C-19 7 -17 16 -13 21 C-10 25 -7 26 -7 30 C-7 39 -14 53 -12 65 C-11 71 -6 72 -3 65 L2 47 L6 66 C8 72 13 71 15 64 C18 53 9 39 9 30 C13 26 18 23 19 16 C20 8 15 2 9 3 C4 4 1 7 -3 5 C-7 2 -11 1 -15 3 Z",
};
const moduleServiceOptions = {
  dentistry: ["Первинна консультація", "Діагностика", "Лікування", "Профілактичний огляд", "Гігієнічна чистка", "Терапевтична стоматологія", "Ортодонтія", "Імплантація", "Протезування", "Хірургічна стоматологія", "Інше"],
  legal: ["Юридична консультація", "Підготовка договору", "Представництво інтересів", "Правовий аналіз", "Судовий супровід", "Реєстрація бізнесу", "Перевірка документів", "Інше"],
  real_estate: ["Консультація з нерухомості", "Оцінка об’єкта", "Підготовка документів", "Супровід угоди", "Пошук нерухомості", "Оренда нерухомості", "Управління об’єктом", "Інше"],
};
export function DentalToothChart({ value = [], onChange, editingServiceName = "", onFinishEditing, readOnly = false }) {
  const selected = new Set((value || []).map(Number));
  const toggleTooth = (tooth) => { if (!readOnly) onChange?.(selected.has(tooth) ? [...selected].filter((item) => item !== tooth) : [...selected, tooth].sort((a, b) => a - b)); };
  const toggleGroup = (teeth) => {
    const allSelected = teeth.every((tooth) => selected.has(tooth));
    if (!readOnly) onChange?.([...new Set(allSelected ? [...selected].filter((tooth) => !teeth.includes(tooth)) : [...selected, ...teeth])].sort((a, b) => a - b));
  };
  const upperCenters = [42, 83, 126, 160, 194, 230, 266, 297, 340, 376, 411, 445, 478, 517, 558, 600];
  const lowerCenters = [44, 84, 126, 164, 209, 244, 277, 304, 336, 372, 408, 440, 473, 510, 551, 598];
  const upperTeeth = [...DENTAL_TEETH.upperRight, ...DENTAL_TEETH.upperLeft];
  const lowerTeeth = [...DENTAL_TEETH.lowerRight, ...DENTAL_TEETH.lowerLeft];
  const renderTooth = (tooth, center, lower) => {
    const digit = tooth % 10;
    const kind = digit >= 6 ? "molar" : digit >= 4 ? "premolar" : digit === 3 ? "canine" : "front";
    const scaleX = kind === "molar" ? 0.9 : kind === "premolar" ? 0.72 : 0.62;
    const scaleY = lower ? 0.63 : 0.74;
    const transform = lower ? `translate(${center} 144) scale(${scaleX} ${scaleY})` : `translate(${center} 104) scale(${scaleX} ${-scaleY})`;
    const toggle = () => toggleTooth(tooth);
    return <g key={tooth} className={`odontogram-tooth is-${kind}${selected.has(tooth) ? " is-selected" : ""}${readOnly ? " is-readonly" : ""}`} role={readOnly ? undefined : "button"} tabIndex={readOnly ? -1 : 0} aria-label={`Зуб ${tooth}${selected.has(tooth) ? ", вибрано" : ""}`} aria-pressed={readOnly ? undefined : selected.has(tooth)} onClick={readOnly ? undefined : toggle} onKeyDown={readOnly ? undefined : (event) => { if (event.key === "Enter" || event.key === " ") { event.preventDefault(); toggle(); } }}>
      <g transform={transform}><path className="odontogram-tooth-outline" d={DENTAL_TOOTH_SHAPES[kind]} /><path className="odontogram-tooth-detail" d={kind === "molar" ? "M-12 15 C-7 20 -2 19 2 15 C6 19 11 19 15 14 M-3 19 L-4 29 M4 19 L5 29" : kind === "premolar" ? "M-10 15 C-5 20 5 20 11 14 M0 19 L0 27" : kind === "canine" ? "M-8 15 C-3 19 5 19 10 14" : "M-8 15 C-3 18 4 18 10 14"} /></g>
      <text className="odontogram-tooth-number" x={center} y={lower ? 137 : 118} textAnchor="middle">{tooth}</text>
    </g>;
  };
  return <div className="dental-tooth-selector">
    <div className="dental-tooth-heading"><strong>{editingServiceName ? `Зуби для послуги «${editingServiceName}»` : readOnly ? "Позначені зуби пацієнта" : "Виберіть зуби, якщо послуга стосується конкретних зубів"}</strong><span>FDI · {selected.size} вибрано</span>{editingServiceName && onFinishEditing ? <button type="button" onClick={onFinishEditing}>Готово</button> : !readOnly && selected.size > 0 && <button type="button" onClick={() => onChange?.([])}>Очистити</button>}</div>
    <svg className="dental-odontogram" viewBox="0 0 640 198" role="group" aria-label="Інтерактивна схема 32 зубів, система FDI">
      <line className="odontogram-divider" x1="28" x2="612" y1="123" y2="123" /><line className="odontogram-midline" x1="320" x2="320" y1="3" y2="195" />
      {upperTeeth.map((tooth, index) => renderTooth(tooth, upperCenters[index], false))}
      {lowerTeeth.map((tooth, index) => renderTooth(tooth, lowerCenters[index], true))}
    </svg>
    <div className="dental-tooth-groups">{[["Верхній правий бік", DENTAL_TEETH.upperRight], ["Верхній лівий бік", DENTAL_TEETH.upperLeft], ["Нижній правий бік", DENTAL_TEETH.lowerRight], ["Нижній лівий бік", DENTAL_TEETH.lowerLeft]].map(([label, teeth]) => <button type="button" key={label} disabled={readOnly} className={teeth.every((tooth) => selected.has(tooth)) ? "is-selected" : ""} onClick={() => toggleGroup(teeth)}>{label}</button>)}</div>
  </div>;
}
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
const servicePriceDefaultsKey = (userId) => `nexaflow.accounting.servicePriceDefaults:${userId || "anonymous"}`;
const readServicePriceDefaults = (userId) => {
  try { return JSON.parse(window.localStorage.getItem(servicePriceDefaultsKey(userId)) || "{}"); } catch { return {}; }
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
function downloadDentistryClientInvoice(client) {
  const services = (client.services || []).filter(service => service.name && service.appointment_task_id && ["done", "archived"].includes(service.appointment_status));
  if (!services.length) return;
  const escape = value => String(value ?? "").replace(/[&<>"']/g, char => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[char]));
  const money = value => Number(value || 0).toLocaleString("uk-UA", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const total = services.reduce((sum, service) => sum + Number(service.price || 0), 0);
  const invoiceNumber = `NF-${new Date().toLocaleDateString("sv-SE").replaceAll("-", "")}-${Math.floor(1000 + Math.random() * 9000)}`;
  const rows = services.map((service, index) => {
    const teeth = !service.is_package && service.tooth_numbers?.length ? service.tooth_numbers : [];
    const quantity = teeth.length || 1;
    const amount = Number(service.price || 0);
    const unitPrice = teeth.length ? Number(service.unit_price ?? amount / quantity) : amount;
    const appointmentAt = service.add_to_calendar && service.appointment_task_id && service.appointment_at
      ? new Date(String(service.appointment_at).replace("T", " ")).toLocaleString("uk-UA", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" })
      : "";
    const details = [
      teeth.length ? `Зуби: ${teeth.join(", ")}` : "",
      service.is_package && service.package_services?.length ? `Пакет: ${service.package_services.join(", ")}` : "",
      appointmentAt ? `Прийом: ${appointmentAt}` : "",
    ].filter(Boolean).map(escape).join("<br>");
    return `<tr><td style="text-align:center">${index + 1}</td><td><strong>${escape(service.name)}</strong>${details ? `<small class="details">${details}</small>` : ""}</td><td style="text-align:center">${quantity}</td><td style="text-align:right">${money(unitPrice)} грн</td><td style="text-align:right">${money(amount)} грн</td></tr>`;
  }).join("");
  const patient = client.module_data || {};
  const html = `<!doctype html><html lang="uk"><head><meta charset="utf-8"><title>Рахунок ${invoiceNumber}</title><style>body{font-family:Arial,sans-serif;color:#1f2b26;margin:36px;font-size:12px}h1{margin:0;color:#168968;font-size:24px}.top{display:flex;justify-content:space-between;border-bottom:3px solid #168968;padding-bottom:18px}.muted{color:#718078}.meta{margin-top:22px;display:grid;grid-template-columns:1fr 1fr;gap:24px}.box{border:1px solid #d9e5de;border-radius:8px;padding:14px}.label{color:#718078;font-size:10px;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px}.box p{margin:6px 0}table{width:100%;border-collapse:collapse;margin-top:24px}th{background:#eaf6ef;color:#315d4b;text-align:left}th,td{border:1px solid #cbdad1;padding:10px}tfoot td{font-weight:bold;font-size:14px;background:#f5faf6}.details{display:block;margin-top:5px;color:#718078;line-height:1.5}.footer{margin-top:34px;color:#718078;font-size:10px}</style></head><body><div class="top"><div><h1>NexaFlow</h1><p class="muted">Рахунок за стоматологічні послуги</p></div><div style="text-align:right"><strong>Рахунок № ${invoiceNumber}</strong><p>від ${new Date().toLocaleDateString("uk-UA")}</p></div></div><div class="meta"><div class="box"><div class="label">Постачальник</div><strong>NexaFlow</strong><p class="muted">Реквізити будуть додані пізніше</p></div><div class="box"><div class="label">Пацієнт</div><strong>${escape(client.name || "Пацієнт")}</strong><p>${escape(patient.phone || "")}</p><p>${escape(patient.email || "")}</p>${patient.medical_record ? `<p>Медична картка: ${escape(patient.medical_record)}</p>` : ""}</div></div><h2>Надані послуги</h2><table><thead><tr><th style="width:45px">№</th><th>Послуга</th><th style="width:65px">К-сть</th><th style="width:130px">Ціна за одиницю</th><th style="width:130px">Сума</th></tr></thead><tbody>${rows || '<tr><td colspan="5">Послуг поки не додано</td></tr>'}</tbody><tfoot><tr><td colspan="4" style="text-align:right">Разом до сплати:</td><td style="text-align:right">${money(total)} грн</td></tr></tfoot></table><div class="footer">Сформовано в NexaFlow для пацієнта ${escape(client.name || "")}. Рахунок містить стоматологічні послуги з його картки.</div></body></html>`;
  const blob = new Blob([`\ufeff${html}`], { type: "application/vnd.ms-excel;charset=utf-8" });
  const link = document.createElement("a"); link.href = URL.createObjectURL(blob); link.download = `rahunok-${String(client.name || "patient").replace(/[^a-zа-яіїє0-9]+/gi, "-").replace(/^-|-$/g, "") || "patient"}.xls`; link.click(); URL.revokeObjectURL(link.href);
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
  const accountantIds = [...new Set((service.accountant_ids || (service.accountant_id ? [service.accountant_id] : client.module_data?.accountant_ids || [])).map(normalizeId).filter(Boolean))];
  return {
    action: "create",
    workspace_owner_id: client.workspace_owner_id || "",
    title: `${service.name} — ${client.name}`,
    description: `Послуга для клієнта «${client.name}».\n\nФорма власності: ${client.legal_form || "не вказано"}\nСистема оподаткування: ${client.tax_system || "не вказано"}\nПДВ: ${client.has_vat ? "так" : "ні"}\nАкциз: ${client.has_excise ? "так" : "ні"}\nНаймані працівники: ${employeeText}\nСпецифічні податки: ${taxes}\n\nПослуга: ${service.name}.${packageDetails}\nПеріодичність: ${service.period || "не вказано"}\nОфіційна дата: ${dueDate || "не вказано"}\nВнутрішній дедлайн: ${service.internal_days_before ?? 5} дн. до офіційної дати.`,
    client_ids: normalizedClientId ? [normalizedClientId] : [],
    assignee_ids: accountantIds,
    project_ids: [],
    status: "todo",
    priority: "normal",
    due_date: dueDate,
    reminder_at: reminderAt,
    creator_reminder: false,
    recurrence_rule: recurrenceRule,
  };
}
export function serviceAppointmentTaskPayload(client, service, clientId) {
  const normalizeId = (value) => {
    const id = String(value || "").trim();
    return /^[a-f0-9]{32}$/i.test(id) ? id : "";
  };
  const appointmentAt = String(service.appointment_at || "").trim();
  const appointmentDate = new Date(`${appointmentAt}:00`);
  let reminderAt = null;
  const reminderMinutes = Number(service.appointment_reminder_minutes || 0);
  if (reminderMinutes > 0 && !Number.isNaN(appointmentDate.getTime())) {
    appointmentDate.setMinutes(appointmentDate.getMinutes() - reminderMinutes);
    const pad = (value) => String(value).padStart(2, "0");
    reminderAt = `${appointmentDate.getFullYear()}-${pad(appointmentDate.getMonth() + 1)}-${pad(appointmentDate.getDate())}T${pad(appointmentDate.getHours())}:${pad(appointmentDate.getMinutes())}`;
  }
  const doctorId = normalizeId(client.module_data?.doctor_id);
  const accountantIds = [...new Set((service.accountant_ids || (service.accountant_id ? [service.accountant_id] : client.module_data?.accountant_ids || [])).map(normalizeId).filter(Boolean))];
  const teeth = (service.tooth_numbers || []).join(", ");
  return {
    action: "create",
    appointment_booking: true,
    workspace_owner_id: client.workspace_owner_id || "",
    title: `Запис клієнта · ${client.name} — ${service.name}`,
    description: `Запис клієнта «${client.name}» на послугу «${service.name}».${teeth ? `\nЗуби: ${teeth}.` : ""}${client.module_data?.doctor ? `\nЛікар: ${client.module_data.doctor}.` : ""}`,
    client_name: client.name || "",
    client_ids: normalizeId(clientId) ? [normalizeId(clientId)] : [],
    assignee_ids: client.module === "accounting" ? accountantIds : (doctorId ? [doctorId] : []),
    project_ids: [],
    status: "todo",
    priority: "normal",
    due_date: appointmentAt,
    duration_minutes: Number(service.appointment_duration_minutes || 60),
    reminder_at: reminderAt,
    creator_reminder: true,
    recurrence_rule: "none",
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
  module: "accounting",
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

function availableAppointmentTimes(dateValue, durationMinutes, tasks, assigneeId, excludeTaskId, services = [], excludeServiceIndex = -1) {
  const date = String(dateValue || "").slice(0, 10);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return [];
  const now = new Date();
  const todayKey = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`;
  if (date < todayKey) return [];
  const assignedId = String(assigneeId || "");
  const busy = (tasks || []).filter((task) => {
    if (Number(task.is_client_appointment) !== 1 || !task.due_date || ["archived", "cancelled", "done"].includes(task.status) || String(task.id) === String(excludeTaskId || "")) return false;
    if (assignedId) {
      const taskDoctors = (task.assignee_ids || [task.assignee_id]).filter(Boolean).map(String);
      if (!taskDoctors.includes(assignedId)) return false;
    }
    const due = String(task.due_date).replace("T", " ");
    if (due.slice(0, 10) !== date || !/\d{2}:\d{2}/.test(due.slice(10))) return false;
    const match = due.match(/(\d{2}):(\d{2})/);
    const start = Number(match[1]) * 60 + Number(match[2]);
    return { start, end: start + Math.max(15, Number(task.duration_minutes || 60)) };
  }).filter(Boolean);
  for (const [index, service] of (services || []).entries()) {
    if (index === excludeServiceIndex || !service.add_to_calendar || !service.appointment_at) continue;
    const appointment = String(service.appointment_at);
    if (appointment.slice(0, 10) !== date || !/^\d{2}:\d{2}$/.test(appointment.slice(11, 16))) continue;
    const [hour, minute] = appointment.slice(11, 16).split(":").map(Number);
    const start = hour * 60 + minute;
    busy.push({ start, end: start + Math.max(15, Number(service.appointment_duration_minutes || 60)) });
  }
  const selectedDuration = Math.max(15, Number(durationMinutes || 60));
  const isToday = date === todayKey;
  const times = [];
  for (let minute = 8 * 60; minute + selectedDuration <= 23 * 60 + 59; minute += 15) {
    if (isToday && minute <= now.getHours() * 60 + now.getMinutes()) continue;
    if (busy.some((item) => minute < item.end && minute + selectedDuration > item.start)) continue;
    times.push(`${String(Math.floor(minute / 60)).padStart(2, "0")}:${String(minute % 60).padStart(2, "0")}`);
  }
  return times;
}
export function validateAppointmentServices(services, tasks, moduleData = {}) {
  for (const [index, service] of (services || []).entries()) {
    if (!service.add_to_calendar) continue;
    const match = String(service.appointment_at || "").match(/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})$/);
    if (!match) return "Виберіть доступні дату й час для кожного запису в календарі.";
    const assigneeId = moduleData.doctor_id || "";
    if (!assigneeId) return "Спочатку призначте відповідального лікаря в картці пацієнта.";
    const slots = availableAppointmentTimes(match[1], service.appointment_duration_minutes, tasks, assigneeId, service.appointment_task_id, services, index);
    if (!slots.includes(match[2])) return "Обраний час уже зайнятий або недоступний. Виберіть інший час.";
  }
  return "";
}
function ServiceSelect({ value, onChange, legalForm, customServices = [], options: providedOptions = null, autoOpen = false, onAutoOpen, perTooth = false }) {
  const [open, setOpen] = useState(false);
  const [dropUp, setDropUp] = useState(false);
  const [search, setSearch] = useState("");
  const selectRef = useRef(null);
  const legalFormOptions = [...new Set([...customServices.map((service) => service.name), ...(providedOptions || serviceOptionsByLegalForm[legalForm] || serviceOptions)])];
  const options = [...new Set(value && !legalFormOptions.includes(value) ? [value, ...legalFormOptions] : legalFormOptions)]
    .filter((option) => option.toLocaleLowerCase("uk").includes(search.toLocaleLowerCase("uk")));
  useEffect(() => {
    if (autoOpen) { setOpen(true); onAutoOpen?.(); }
  }, [autoOpen, onAutoOpen]);
  useEffect(() => {
    if (!open) return undefined;
    const closeOnOutsideClick = (event) => {
      if (!selectRef.current?.contains(event.target)) setOpen(false);
    };
    document.addEventListener("mousedown", closeOnOutsideClick);
    return () => document.removeEventListener("mousedown", closeOnOutsideClick);
  }, [open]);
  useEffect(() => {
    if (!open) return undefined;
    const updatePlacement = () => {
      const rect = selectRef.current?.getBoundingClientRect();
      if (!rect) return;
      const spaceBelow = window.innerHeight - rect.bottom;
      setDropUp(spaceBelow < 285 && rect.top > spaceBelow);
    };
    updatePlacement();
    window.addEventListener("resize", updatePlacement);
    window.addEventListener("scroll", updatePlacement, true);
    return () => {
      window.removeEventListener("resize", updatePlacement);
      window.removeEventListener("scroll", updatePlacement, true);
    };
  }, [open]);
  return <div ref={selectRef} className="service-select">
    <button type="button" className="service-select-trigger" onClick={() => setOpen((current) => !current)}><span>{value || "Оберіть послугу"}</span><Icon name="down" /></button>
    {open && <div className={`service-select-menu${dropUp ? " is-upward" : ""}`}><div className="service-select-search"><Icon name="search" /><input autoFocus value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Пошук послуги…" /></div><div className="service-select-options">{options.length ? options.map((option) => { const custom = customServices.find((service) => service.name === option); return <button type="button" key={option} onClick={() => { onChange(option); setOpen(false); setSearch(""); }}><span>{option}{custom && <small> · {Number(custom.client_price || 0).toLocaleString("uk-UA")} грн{perTooth ? " / зуб" : ""}</small>}</span>{value === option && <Icon name="check" />}</button>; }) : <span>Послуг не знайдено</span>}</div></div>}
  </div>;
}
function AccountantSelect({ value, onChange, options, emptyMessage, placeholder = "Оберіть виконавця", searchPlaceholder = "Пошук бухгалтера…", notFoundMessage = "Бухгалтера не знайдено" }) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState("");
  const selectRef = useRef(null);
  const selected = options.find((person) => String(person.id) === String(value));
  const filtered = options.filter((person) => `${person.name} ${person.email}`.toLocaleLowerCase("uk").includes(search.toLocaleLowerCase("uk")));
  useEffect(() => {
    if (!open) return undefined;
    const close = (event) => { if (!selectRef.current?.contains(event.target)) setOpen(false); };
    document.addEventListener("mousedown", close);
    return () => document.removeEventListener("mousedown", close);
  }, [open]);
  return <div ref={selectRef} className="service-select accountant-select">
    <button type="button" className="service-select-trigger" onClick={() => setOpen((current) => !current)}><span>{selected ? selected.name : placeholder}</span><Icon name="down" /></button>
    {open && <div className="service-select-menu"><div className="service-select-search"><Icon name="search" /><input autoFocus value={search} onChange={(event) => setSearch(event.target.value)} placeholder={searchPlaceholder} /></div><div className="service-select-options">{!options.length ? <span>{emptyMessage}</span> : filtered.length ? filtered.map((person) => <button type="button" key={person.id} onClick={() => { onChange(person.id); setOpen(false); setSearch(""); }}>{person.name}{String(value) === String(person.id) && <Icon name="check" />}</button>) : <span>{notFoundMessage}</span>}</div></div>}
  </div>;
}
function AccountantMultiSelect({ value = [], onChange, options, emptyMessage, placeholder = "Оберіть бухгалтерів", searchPlaceholder = "Пошук бухгалтера…", notFoundMessage = "Бухгалтерів не знайдено" }) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState("");
  const selectRef = useRef(null);
  const selectedIds = [...new Set((Array.isArray(value) ? value : []).map(String))];
  const selectedNames = options.filter(person => selectedIds.includes(String(person.id))).map(person => person.name);
  const filtered = options.filter(person => `${person.name} ${person.email}`.toLocaleLowerCase("uk").includes(search.toLocaleLowerCase("uk")));
  useEffect(() => {
    if (!open) return undefined;
    const close = event => { if (!selectRef.current?.contains(event.target)) setOpen(false); };
    document.addEventListener("mousedown", close);
    return () => document.removeEventListener("mousedown", close);
  }, [open]);
  return <div ref={selectRef} className="service-select accountant-select accountant-multi-select">
    <button type="button" className="service-select-trigger" onClick={() => setOpen(current => !current)}><span>{selectedNames.length ? selectedNames.join(", ") : placeholder}</span><Icon name="down" /></button>
    {open && <div className="service-select-menu"><div className="service-select-search"><Icon name="search" /><input autoFocus value={search} onChange={event => setSearch(event.target.value)} placeholder={searchPlaceholder} /></div><div className="service-select-options">{!options.length ? <span>{emptyMessage}</span> : filtered.length ? filtered.map(person => <button type="button" key={person.id} aria-pressed={selectedIds.includes(String(person.id))} onClick={() => { const id = String(person.id); onChange(selectedIds.includes(id) ? selectedIds.filter(item => item !== id) : [...selectedIds, id]); }}><span>{person.name}</span>{selectedIds.includes(String(person.id)) && <Icon name="check" />}</button>) : <span>{notFoundMessage}</span>}</div></div>}
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
export function ClientFormModal({ form, setForm, editing, onSave, onClose, onAddService, onSaveAppointment, pageMode = false, availableUsers = [], teamUsers = [], availableUsersByClient = {}, clientId = "", servicePackages = [], standaloneServices = [], calendarTasks = [], currentUserId = "", subscription = null }) {
  const clientCreationLimitReached = !editing && isPlanLimitReached(subscription, "clients");
  const [savingServiceIndex, setSavingServiceIndex] = useState(null);
  const [savingAppointmentIndex, setSavingAppointmentIndex] = useState(null);
  const serviceSaveQueue = useRef(Promise.resolve());
  const [selectedPackageId, setSelectedPackageId] = useState("");
  const [dentalTeeth, setDentalTeeth] = useState([]);
  const [editingDentalServiceIndex, setEditingDentalServiceIndex] = useState(null);
  const [servicePickerIndex, setServicePickerIndex] = useState(null);
  const [duplicateNotice, setDuplicateNotice] = useState("");
  const [duplicatePrompt, setDuplicatePrompt] = useState(null);
  const duplicateNoticeTimer = useRef(null);
  const formElementRef = useRef(null);
  async function saveAppointment(index) {
    if (!onSaveAppointment || savingAppointmentIndex !== null) return;
    setSavingAppointmentIndex(index);
    try { await onSaveAppointment(form, index); }
    finally { setSavingAppointmentIndex(null); }
  }
  const showDuplicateNotice = (message) => {
    setDuplicateNotice(message);
    window.clearTimeout(duplicateNoticeTimer.current);
    duplicateNoticeTimer.current = window.setTimeout(() => setDuplicateNotice(""), 3800);
  };
  useEffect(() => () => window.clearTimeout(duplicateNoticeTimer.current), []);
  const clientServiceLimit = subscription?.services_per_client ?? null;
  const calendarFeatureAvailable = Boolean(subscription?.features?.calendar);
  const standaloneServiceCount = (form.services || []).filter((service) => !service.is_package && !service.package_id).length;
  const serviceLimitReached = clientServiceLimit !== null && standaloneServiceCount >= Number(clientServiceLimit);
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
  async function persistSelectedService(services, index, reason = "service") {
    if (!clientId || !onAddService || !services[index]?.name) return;
    const cleanServices = services.map(({ _isNew, ...service }) => service);
    const service = cleanServices[index];
    rememberServicePrice(service?.name, service?.price);
    setSavingServiceIndex(index);
    const saveOperation = serviceSaveQueue.current.then(() => onAddService({ ...form, services: cleanServices }, service, reason));
    serviceSaveQueue.current = saveOperation.catch(() => false);
    const saved = await saveOperation;
    if (saved) setForm((current) => ({ ...current, services: cleanServices }));
    setSavingServiceIndex(null);
  }
  const updateService = (index, field, value, allowDuplicate = false) => {
    const services = form.services || [];
    if (field === "name" && value && !allowDuplicate && services.some((service, itemIndex) => itemIndex !== index && !service.is_package && !service.package_id && service.name.trim().toLocaleLowerCase("uk") === value.trim().toLocaleLowerCase("uk"))) {
      if (form.module === "dentistry") setDuplicatePrompt({ index, value });
      else showDuplicateNotice(`Послугу «${value}» уже додано до цього клієнта.`);
      return;
    }
    const nextServices = services.map((service, itemIndex) => {
      if (itemIndex !== index) return service;
      if (field && typeof field === "object") return { ...service, ...field };
      if (field === "name") {
        const catalogService = standaloneServices.find((item) => item.name === value);
        const unitPrice = catalogService ? Number(catalogService.client_price || 0) : (value ? Number(readServicePriceDefaults(currentUserId)[value] ?? 0) : 0);
        const dentalRate = form.module === "dentistry" && !service.is_package;
        const price = dentalRate ? unitPrice * Math.max(service.tooth_numbers?.length || 0, 1) : unitPrice;
        return { ...service, name: value, price, ...(dentalRate ? { unit_price: unitPrice } : {}), responsible_amount: catalogService ? Number(catalogService.responsible_amount || 0) : service.responsible_amount, official_due_date: value ? officialDateForService(value, service.period) : "" };
      }
      if (field === "tooth_numbers") {
        if (form.module !== "dentistry" || service.is_package) return { ...service, tooth_numbers: value };
        const catalogService = standaloneServices.find((item) => item.name === service.name);
        const fallbackUnitPrice = service.tooth_numbers?.length ? Number(service.price || 0) / service.tooth_numbers.length : Number(service.price || 0);
        const unitPrice = Number(service.unit_price ?? catalogService?.client_price ?? fallbackUnitPrice);
        return { ...service, tooth_numbers: value, unit_price: unitPrice, price: unitPrice * Math.max(value?.length || 0, 1) };
      }
      if (field === "period") return { ...service, period: value, official_due_date: service.name ? officialDateForService(service.name, value) : "" };
      if (field === "price" && form.module === "dentistry" && !service.is_package) {
        const total = Number(value || 0);
        const unitPrice = total / Math.max(service.tooth_numbers?.length || 0, 1);
        return { ...service, price: value, unit_price: unitPrice, ...(service.responsible_mode === "percent" ? { responsible_amount: responsibleAmountFor(total, service.responsible_percent) } : {}) };
      }
      if (field === "price" && service.responsible_mode === "percent") return { ...service, price: value, responsible_amount: responsibleAmountFor(value, service.responsible_percent) };
      if (field === "responsible_mode") return { ...service, responsible_mode: value, responsible_amount: value === "percent" ? responsibleAmountFor(service.price, service.responsible_percent) : service.responsible_amount };
      if (field === "responsible_percent") return { ...service, responsible_percent: value, responsible_amount: responsibleAmountFor(service.price, value) };
      return { ...service, [field]: value };
    });
    set("services", nextServices);
    if (((field === "name" && value) || field === "tooth_numbers") && clientId) persistSelectedService(nextServices, index, field === "tooth_numbers" ? "teeth" : "service");
  };
  const addService = () => {
    if (serviceLimitReached) {
      showDuplicateNotice(`У плані «${subscription.name}» дозволено до ${clientServiceLimit} послуг на одного клієнта.`);
      return;
    }
    set("services", [{ name: "", price: 0, responsible_amount: 0, responsible_mode: "amount", responsible_percent: "", period: "Місяць", official_due_date: "", accountant_ids: [...globalAccountantIds], accountant_id: globalAccountantIds[0] || "", internal_days_before: 5, parameters: [], tooth_numbers: form.module === "dentistry" ? [...dentalTeeth] : [], add_to_calendar: false, appointment_at: "", appointment_duration_minutes: 60, appointment_reminder_minutes: 1440, appointment_task_id: "", reminders_enabled: true, create_task: false, _isNew: true }, ...(form.services || [])]);
    if (form.module === "dentistry") setServicePickerIndex(0);
  };
  const addPackage = () => {
    const packageItem = servicePackages.find((item) => String(item.id) === String(selectedPackageId));
    if (!packageItem) return;
    if ((form.services || []).some((service) => String(service.package_id || "") === String(packageItem.id))) {
      showDuplicateNotice(`Пакет «${packageItem.name}» уже додано до цього клієнта.`);
      return;
    }
    if (packageItem.module && packageItem.module !== form.module) set("module", packageItem.module);
    const packageServiceNames = (packageItem.services || []).map((service) => service.name).filter(Boolean);
    const nextServices = [{ name: packageItem.name, price: Number(packageItem.client_price || 0), responsible_amount: Number(packageItem.responsible_amount || 0), responsible_mode: "amount", responsible_percent: "", period: "Місяць", official_due_date: "", accountant_ids: [...globalAccountantIds], accountant_id: globalAccountantIds[0] || "", internal_days_before: 5, parameters: packageServiceNames, tooth_numbers: form.module === "dentistry" ? [...dentalTeeth] : [], add_to_calendar: false, appointment_at: "", appointment_duration_minutes: 60, appointment_reminder_minutes: 1440, appointment_task_id: "", package_id: packageItem.id, package_name: packageItem.name, package_services: packageServiceNames, package_responsible_amount: Number(packageItem.responsible_amount || 0), calendar_enabled: Boolean(packageItem.calendar_enabled), is_package: true, reminders_enabled: true, create_task: false, _isNew: true }, ...(form.services || [])];
    set("services", nextServices);
    if (clientId) persistSelectedService(nextServices, 0, "package");
    setSelectedPackageId("");
  };
  const editServiceTeeth = (index) => {
    setDentalTeeth([...(form.services?.[index]?.tooth_numbers || [])]);
    setEditingDentalServiceIndex(index);
    window.requestAnimationFrame(() => document.querySelector(".dental-tooth-selector")?.scrollIntoView({ behavior: "smooth", block: "center" }));
  };
  const finishEditingServiceTeeth = () => {
    setEditingDentalServiceIndex(null);
    setDentalTeeth([]);
  };
  const updateDentalSelection = (value) => {
    setDentalTeeth(value);
    if (editingDentalServiceIndex !== null) updateService(editingDentalServiceIndex, "tooth_numbers", value);
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
  const globalAccountantIds = Array.isArray(form.module_data?.accountant_ids) ? form.module_data.accountant_ids.map(String) : [];
  const setGlobalAccountants = (accountantIds) => setForm(current => ({
    ...current,
    module_data: { ...current.module_data, accountant_ids: accountantIds },
    services: current.module === "accounting" ? (current.services || []).map(service => ({ ...service, accountant_ids: accountantIds, accountant_id: accountantIds[0] || "" })) : current.services,
  }));
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
  const isCatalogPriceLocked = (service) => Boolean(service.is_package || service.catalog_price_locked || standaloneServices.some((item) => item.name === service.name && item.client_price !== null && item.client_price !== undefined && item.client_price !== ""));
  const rememberServicePrice = (name, price) => {
    if (form.module !== "accounting" || !name) return;
    const defaults = readServicePriceDefaults(currentUserId);
    defaults[name] = price === "" ? 0 : price;
    window.localStorage.setItem(servicePriceDefaultsKey(currentUserId), JSON.stringify(defaults));
  };
  const rememberServicePrices = () => {
    if (form.module !== "accounting") return;
    const defaults = readServicePriceDefaults(currentUserId);
    (form.services || []).forEach((service) => { if (service.name && service.price !== "") defaults[service.name] = service.price; });
    window.localStorage.setItem(servicePriceDefaultsKey(currentUserId), JSON.stringify(defaults));
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
          <p className="client-modal-subtitle">{form.module === "accounting" ? "Додайте організаційні та податкові дані клієнта." : `Заповніть дані клієнта для модуля «${moduleNames[form.module]}».`}</p>
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
                    {form.module === "dentistry" ? "Ім’я пацієнта" : "Контактна особа"}
                    <input
                      name={form.module === "dentistry" ? "name" : undefined}
                      value={form.module === "dentistry" ? form.name : (form.module_data?.contact_person || "")}
                      onChange={(event) => {
                        if (form.module === "dentistry") {
                          set("name", event.target.value);
                          set("module_data", { ...form.module_data, contact_person: event.target.value });
                        } else {
                          set("module_data", { ...form.module_data, contact_person: event.target.value });
                        }
                      }}
                      placeholder={form.module === "dentistry" ? "Ім’я та прізвище пацієнта" : "Ім’я та прізвище"}
                      required={form.module === "dentistry"}
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
                  {form.module === "dentistry" && <label>Лікар / стоматолог<AccountantSelect value={form.module_data?.doctor_id || teamUsers.find((person) => person.name === form.module_data?.doctor)?.id || ""} options={teamUsers.filter((person) => person.is_doctor || ["manager", "owner"].includes(String(person.role || "").toLowerCase()))} emptyMessage="У команді поки немає лікарів. Призначте учаснику роль «Лікар» у налаштуваннях команди." placeholder="Оберіть лікаря" searchPlaceholder="Пошук лікаря…" notFoundMessage="Лікаря не знайдено" onChange={(doctorId) => { const doctor = teamUsers.find((person) => String(person.id) === String(doctorId)); set("module_data", { ...form.module_data, doctor: doctor?.name || "", doctor_id: doctor ? String(doctor.id) : "" }); }} /></label>}
                  {form.module === "dentistry" && <label>Номер медичної картки<input value={form.module_data?.medical_record || ""} onChange={(event) => set("module_data", { ...form.module_data, medical_record: event.target.value })} placeholder="Номер картки" /></label>}{form.module === "dentistry" && <label>Адреса<input value={form.module_data?.address || ""} onChange={(event) => set("module_data", { ...form.module_data, address: event.target.value })} placeholder="Адреса пацієнта" /></label>}{form.module === "dentistry" && <label className="client-form-grid-wide">Медичні примітки<textarea value={form.module_data?.medical_notes || ""} onChange={(event) => set("module_data", { ...form.module_data, medical_notes: event.target.value })} rows="3" placeholder="Алергії, важливі особливості, примітки…" /></label>}
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
                  {form.module === "legal" && <label>Номер справи<input value={form.module_data?.case_number || ""} onChange={(event) => set("module_data", { ...form.module_data, case_number: event.target.value })} placeholder="Номер або внутрішній код" /></label>}
                  {form.module === "legal" && <label>Статус справи<select value={form.module_data?.case_status || "Нова"} onChange={(event) => set("module_data", { ...form.module_data, case_status: event.target.value })}><option>Нова</option><option>В роботі</option><option>Очікує клієнта</option><option>Завершена</option></select></label>}
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
                  {form.module === "real_estate" && <label>Тип взаємодії<select value={form.module_data?.client_role || "Покупець"} onChange={(event) => set("module_data", { ...form.module_data, client_role: event.target.value })}><option>Покупець</option><option>Продавець</option><option>Орендар</option><option>Орендодавець</option><option>Власник</option></select></label>}
                  {form.module === "real_estate" && <label>Адреса об’єкта<input value={form.module_data?.property_address || ""} onChange={(event) => set("module_data", { ...form.module_data, property_address: event.target.value })} placeholder="Місто, вулиця, номер" /></label>}
                  {form.module === "real_estate" && <label>Бюджет / вартість<input type="number" min="0" step="0.01" value={form.module_data?.budget || ""} onChange={(event) => set("module_data", { ...form.module_data, budget: event.target.value })} placeholder="0" /></label>}
                </div>
              </div>
            )}
            <div className="client-form-grid">
              {form.module !== "dentistry" && <label>
                Назва клієнта
                <input
                  name="name"
                  value={form.name}
                  onChange={(event) => set("name", event.target.value)}
                  required
                  placeholder="Наприклад, ТОВ «Некса»"
                />
              </label>}
              {form.module === "accounting" && <label>
                Форма власності
                <select
                  value={form.legal_form}
                  onChange={(event) => {
                    const legalForm = event.target.value;
                    const options = taxSystemsByLegalForm[legalForm] || taxSystems;
                    const system = canonicalTaxSystem(form.tax_system, legalForm);
                    setForm(current => ({ ...current, legal_form: legalForm, tax_system: options.includes(system) ? system : "Загальна система" }));
                  }}
                >
                  {legalForms.map((item) => (
                    <option key={item}>{item}</option>
                  ))}
                </select>
              </label>}
              {form.module === "accounting" && <label>
                Система оподаткування
                <select
                  value={canonicalTaxSystem(form.tax_system, form.legal_form)}
                  onChange={(event) => set("tax_system", event.target.value)}
                >
                  <option value="" disabled>Оберіть систему оподаткування</option>
                  {(taxSystemsByLegalForm[form.legal_form] || taxSystems).map((item) => (
                    <option key={item}>{item}</option>
                  ))}
                </select>
                {form.module === "accounting" && <small className="client-tax-hint">Для цього режиму дедлайни декларацій з’являться в календарі. Додайте ПДВ, акциз, працівників і специфічні податки нижче, щоб бачити пов’язані строки.</small>}
              </label>}
            </div>
            {form.module === "accounting" && <fieldset className="client-accountants-fieldset"><legend>Відповідальні бухгалтери</legend><p className="client-field-hint">Обрані бухгалтери автоматично додаються до всіх бухгалтерських послуг і пакетів та призначаються виконавцями задач.</p><AccountantMultiSelect value={globalAccountantIds} options={eligibleAccountants} emptyMessage="У вашій команді немає доступних бухгалтерів." onChange={setGlobalAccountants} /><small>{globalAccountantIds.length ? `Обрано: ${globalAccountantIds.length}` : "Можна обрати одного або кількох бухгалтерів."}</small></fieldset>}
            {form.module === "accounting" && <><div className="client-toggles">
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
              {form.module === "accounting" && ["ТОВ", "ПрАТ", "АТ"].includes(form.legal_form) && (form.tax_system === "ТОВ на загальній системі" || form.tax_system === "Загальна система") && <label className={`client-toggle client-income-toggle${form.module_data?.tax_income_over_40m ? " is-active" : ""}`}><input type="checkbox" checked={Boolean(form.module_data?.tax_income_over_40m)} onChange={(event) => set("module_data", { ...form.module_data, tax_income_over_40m: event.target.checked })} /><span>Дохід за попередній рік понад 40 млн грн</span></label>}
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
              {form.special_taxes.length > 0 && <p className="client-field-hint">Для обраного податку додайте бухгалтерську послугу та вкажіть її офіційний строк: період звітності залежить від виду податку й об’єкта.</p>}
            </fieldset></>}
            {form.module === "accounting" && <fieldset className="client-services-fieldset">
              <legend>Бухгалтерські послуги</legend>
              <p className="client-field-hint">Спочатку ваші послуги із збереженими цінами, нижче — стандартні пропозиції.</p>
              <div className="client-services-add-box"><div><strong>Додати окрему послугу</strong><small>{clientServiceLimit !== null ? `До ${clientServiceLimit} послуг на клієнта в плані «${subscription.name}».` : "Оберіть послугу — дата заповниться автоматично за відомим строком."}</small></div><button type="button" className="primary-action" disabled={serviceLimitReached} onClick={addService}><Icon name="plus" /> {serviceLimitReached ? "Ліміт послуг досягнуто" : "Додати послугу"}</button></div>
              {servicePackages.length > 0 && <div className="client-services-package-box"><div><strong>Додати пакет послуг</strong><small>Вибір пакета автоматично встановить його модуль клієнта.</small></div><div className="client-services-package-controls"><SearchableSelect options={servicePackages.map((packageItem) => ({ id: packageItem.id, name: `${packageItem.name} · ${Number(packageItem.client_price || 0).toLocaleString("uk-UA", { minimumFractionDigits: 2 })} грн · ${moduleNames[packageItem.module] || moduleNames.accounting}` }))} value={selectedPackageId} onChange={(id) => { setSelectedPackageId(id); const packageItem = servicePackages.find((item) => String(item.id) === String(id)); if (packageItem?.module) set("module", packageItem.module); }} placeholder="Оберіть пакет" /><button type="button" className="outline-btn" disabled={!selectedPackageId} onClick={addPackage}><Icon name="plus" /> Додати пакет</button></div></div>}
              <div className="client-services-list-label">Додані послуги <span>{standaloneServiceCount}{clientServiceLimit !== null ? ` / ${clientServiceLimit}` : ""}</span></div>
              <div className="client-services-list">
                {(form.services || []).map((service, index) => <div className={`client-service-card${service._isNew ? " client-service-card-new" : ""}${service.is_package ? ` client-package-card price-tier-${packageTier(service)}` : ""}`} key={`${index}-${service.name}`}>
                  <div className={`client-service-row${service.is_package ? " is-package" : ""}`}>
                    <div className="client-service-field client-service-name-field"><small>Послуга</small>{service.is_package ? <div className="client-package-name"><span className="client-package-badge"><Icon name="subscription" /> ПАКЕТ</span><strong>{service.package_name || service.name}</strong></div> : <ServiceSelect value={service.name} legalForm={form.legal_form} customServices={standaloneServices} onChange={(value) => updateService(index, "name", value)} />}</div>
                    <div className="client-service-field"><small>Ціна для клієнта</small><span className={`client-service-price${isCatalogPriceLocked(service) ? " is-readonly" : ""}`}><input type="number" min="0" step="0.01" value={service.price} readOnly={isCatalogPriceLocked(service)} onChange={(event) => updateService(index, "price", event.target.value)} onBlur={(event) => rememberServicePrice(service.name, event.target.value)} placeholder="0" aria-label={isCatalogPriceLocked(service) ? "Зафіксована ціна послуги" : "Ціна послуги"} /><b>грн</b></span></div>
                    {service.is_package ? <div className="client-service-field client-package-payout-field"><small>Виплата виконавцю</small><span className="client-service-price is-readonly"><input type="number" value={service.package_responsible_amount ?? service.responsible_amount ?? 0} readOnly aria-label="Виплата за пакет" /><b>грн</b></span></div> : <div className="client-service-field"><small>Виплата виконавцю</small><span className="client-service-payout-inline"><input type="number" min="0" max={service.responsible_mode === "percent" ? 100 : undefined} step="0.01" value={service.responsible_mode === "percent" ? (service.responsible_percent ?? "") : (service.responsible_amount ?? 0)} onChange={(event) => updateService(index, service.responsible_mode === "percent" ? "responsible_percent" : "responsible_amount", event.target.value)} placeholder={service.responsible_mode === "percent" ? "0" : "0.00"} aria-label="Виплата виконавцю" /><select value={service.responsible_mode || "amount"} onChange={(event) => updateService(index, "responsible_mode", event.target.value)} aria-label="Тип виплати"><option value="amount">грн</option><option value="percent">%</option></select>{service.responsible_mode === "percent" && <small>= {responsibleAmountFor(service.price, service.responsible_percent).toLocaleString("uk-UA", { minimumFractionDigits: 2 })} грн</small>}</span></div>}
                    <div className="client-service-field client-service-period-field"><small>Період</small><select value={service.period || "Місяць"} onChange={(event) => updateService(index, "period", event.target.value)} aria-label="Періодичність"><option>Щогодини</option><option>Щодня</option><option>Щотижня</option><option>Місяць</option><option>Квартал</option><option>Рік</option><option>Разово</option></select></div>
                    <button type="button" className="client-service-remove" onClick={() => removeService(index)} aria-label="Видалити послугу"><Icon name="close" /></button>
                  </div>
                  <div className="client-service-schedule">
                    <label><span className="client-field-label">Офіційна дата<FieldTip>Дата взята з офіційних джерел і типових строків. За потреби ви можете змінити її вручну.</FieldTip></span><DateTimePicker value={service.official_due_date || (service.name ? officialDateForService(service.name, service.period) : "")} onChange={(value) => updateService(index, "official_due_date", value)} /></label>
                    <label><span className="client-field-label">Відповідальні бухгалтери<FieldTip>За замовчуванням призначені вгорі. Тут можна змінити склад виконавців для окремої послуги.</FieldTip></span><AccountantMultiSelect value={service.accountant_ids || (service.accountant_id ? [service.accountant_id] : globalAccountantIds)} options={eligibleAccountants} emptyMessage="У вашій команді немає доступних бухгалтерів." onChange={(ids) => updateService(index, { accountant_ids: ids, accountant_id: ids[0] || "" })} /></label>
                    <label><span className="client-field-label">Внутрішній дедлайн (днів до офіційного)<FieldTip>За скільки днів до офіційної дати нагадати виконавцю.</FieldTip></span><input type="number" min="0" max="90" value={service.internal_days_before ?? 5} onChange={(event) => updateService(index, "internal_days_before", event.target.value)} /></label>
                    <div className="client-service-reminders"><label className="service-reminder-switch"><input type="checkbox" checked={service.reminders_enabled !== false} onChange={(event) => updateService(index, "reminders_enabled", event.target.checked)} /><span className="service-reminder-slider" />Нагадувати про цю послугу</label><label className="service-reminder-switch"><input type="checkbox" checked={Boolean(service.create_task)} onChange={(event) => updateService(index, "create_task", event.target.checked)} /><span className="service-reminder-slider" />Створити задачу для відповідального бухгалтера</label></div>
                  </div>
                  {service._isNew && onAddService && (!clientId || service.name) && <button type="button" className="client-service-save" disabled={!service.name || savingServiceIndex !== null} onClick={() => saveNewService(index)}><Icon name="check" /> {savingServiceIndex === index ? "Збереження…" : clientId ? "Повторити збереження" : "Додати послугу"}</button>}
                </div>)}
              </div>
              <div className="client-services-total"><span>Разом за послуги</span><strong>{serviceTotal.toLocaleString("uk-UA", { minimumFractionDigits: 2 })} грн</strong><button type="button" className="outline-btn" onClick={() => downloadClientInvoice({ ...form, name: form.name || "Новий клієнт" })}><Icon name="download" /> Згенерувати рахунок</button></div>
            </fieldset>}
            {form.module !== "accounting" && form.module !== "dentistry" && <fieldset className="client-services-fieldset">
              <legend>Послуги та пакети · {moduleNames[form.module]}</legend>
              <p className="client-field-hint">{form.module === "dentistry" ? "Кожен рядок — окрема послуга зі своєю ціною та часом прийому. Для повторного прийому додайте цю послугу ще раз." : "Спочатку ваші послуги із збереженими цінами, нижче — стандартні пропозиції. Можна також додати пакет із каталогу «Послуги»."}</p>
              {form.module === "dentistry" && <div className="dental-service-scope"><DentalToothChart value={editingDentalServiceIndex !== null ? (form.services?.[editingDentalServiceIndex]?.tooth_numbers || []) : dentalTeeth} onChange={editingDentalServiceIndex !== null ? updateDentalSelection : setDentalTeeth} editingServiceName={editingDentalServiceIndex !== null ? (form.services?.[editingDentalServiceIndex]?.name || "Послуга") : ""} onFinishEditing={finishEditingServiceTeeth} /></div>}
              <div className="client-services-add-box"><div><strong>Додати послугу</strong><small>Оберіть напрямну послугу та вкажіть ціну.</small></div><button type="button" className="primary-action" disabled={serviceLimitReached} onClick={addService}><Icon name="plus" /> Додати послугу</button></div>
              {servicePackages.length > 0 && <div className="client-services-package-box"><div><strong>Додати пакет послуг</strong><small>Пакети активного модуля.</small></div><div className="client-services-package-controls"><SearchableSelect options={servicePackages.map((item) => ({ id: item.id, name: `${item.name} · ${Number(item.client_price || 0).toLocaleString("uk-UA")} грн` }))} value={selectedPackageId} onChange={setSelectedPackageId} placeholder="Оберіть пакет" /><button type="button" className="outline-btn" disabled={!selectedPackageId} onClick={addPackage}><Icon name="plus" /> Додати пакет</button></div></div>}
              <div className="client-services-list">{(form.services || []).map((service, index) => <div className="client-service-card" key={`${index}-${service.name}`}>
                <div className="client-service-row client-service-row-module"><div className="client-service-field client-service-name-field"><small>Послуга</small>{service.is_package ? <strong>{service.package_name || service.name} · пакет</strong> : <ServiceSelect value={service.name} customServices={standaloneServices} options={[...new Set([...(moduleServiceOptions[form.module] || []), ...servicePackages.flatMap((item) => (item.services || []).map((entry) => entry.name)), service.name].filter(Boolean))]} onChange={(value) => updateService(index, "name", value)} autoOpen={servicePickerIndex === index} onAutoOpen={() => setServicePickerIndex(null)} perTooth={form.module === "dentistry"} />}</div><div className="client-service-field"><small>Ціна для клієнта</small><span className={`client-service-price${isCatalogPriceLocked(service) ? " is-readonly" : ""}`}><input type="number" min="0" step="0.01" value={service.price ?? 0} readOnly={isCatalogPriceLocked(service)} onChange={(event) => updateService(index, "price", event.target.value)} /><b>грн</b></span></div><button type="button" className="client-service-remove" onClick={() => removeService(index)} aria-label="Видалити послугу"><Icon name="close" /></button></div>
                {form.module === "dentistry" && service.name && <div className="client-service-tooth-tools">
                  {service.tooth_numbers?.length > 0 && <div className="client-service-tooth-numbers" aria-label={`Зуби: ${service.tooth_numbers.join(", ")}`}>{service.tooth_numbers.map((tooth) => <span key={tooth}>{tooth}</span>)}</div>}
                  <button type="button" className="client-service-tooth-edit" onClick={() => editServiceTeeth(index)} disabled={editingDentalServiceIndex !== null && editingDentalServiceIndex !== index}>{service.tooth_numbers?.length ? "Змінити зуби" : "Додати зуби"}</button>
                </div>}
                {calendarFeatureAvailable && service.name && (!service.is_package || service.calendar_enabled) && <div className="client-service-appointment">
                  <label className={`service-reminder-switch${!calendarFeatureAvailable ? " is-disabled" : ""}`} title={!calendarFeatureAvailable ? "Календар недоступний у вашому тарифі." : undefined}><input type="checkbox" checked={Boolean(service.add_to_calendar)} disabled={!calendarFeatureAvailable && !service.add_to_calendar} onChange={(event) => updateService(index, "add_to_calendar", event.target.checked)} /><span className="service-reminder-slider" />Додати запис клієнта в календар</label>
                  {!calendarFeatureAvailable && !service.add_to_calendar && <small className="client-service-calendar-locked">Календар недоступний у вашому тарифі.</small>}
                  {service.add_to_calendar && <div className="client-service-appointment-fields">
                    <p className="client-service-availability-note">Показані вільні слоти з 08:00 до 23:59 з кроком 15 хвилин.</p>
                    <label className="client-service-appointment-date">Дата запису<DateTimePicker value={String(service.appointment_at || "").slice(0, 10)} onChange={(date) => { const assigneeId = form.module_data?.doctor_id || ""; const slots = availableAppointmentTimes(date, service.appointment_duration_minutes, calendarTasks, assigneeId, service.appointment_task_id, form.services, index); const currentTime = String(service.appointment_at || "").slice(11, 16); updateService(index, "appointment_at", `${date}T${slots.includes(currentTime) ? currentTime : (slots[0] || "")}`); }} required disabled={!calendarFeatureAvailable} /></label>
                    <label>Тривалість послуги<select disabled={!calendarFeatureAvailable} value={service.appointment_duration_minutes || 60} onChange={(event) => { const duration = Number(event.target.value); const date = String(service.appointment_at || "").slice(0, 10); const assigneeId = form.module_data?.doctor_id || ""; const slots = availableAppointmentTimes(date, duration, calendarTasks, assigneeId, service.appointment_task_id, form.services, index); const currentTime = String(service.appointment_at || "").slice(11, 16); updateService(index, { appointment_duration_minutes: duration, ...(date && !slots.includes(currentTime) ? { appointment_at: `${date}T${slots[0] || ""}` } : {}) }); }}><option value={15}>15 хв</option><option value={30}>30 хв</option><option value={45}>45 хв</option><option value={60}>1 година</option><option value={90}>1 год 30 хв</option><option value={120}>2 години</option><option value={180}>3 години</option><option value={240}>4 години</option></select></label>
                    <label>Доступний час<select disabled={!calendarFeatureAvailable || !String(service.appointment_at || "").slice(0, 10)} value={String(service.appointment_at || "").slice(11, 16)} onChange={(event) => updateService(index, "appointment_at", `${String(service.appointment_at || "").slice(0, 10)}T${event.target.value}`)}><option value="" disabled>Оберіть час</option>{(() => { const date = String(service.appointment_at || "").slice(0, 10); const assigneeId = form.module_data?.doctor_id || ""; const slots = availableAppointmentTimes(date, service.appointment_duration_minutes, calendarTasks, assigneeId, service.appointment_task_id, form.services, index); const currentTime = String(service.appointment_at || "").slice(11, 16); return <>{currentTime && !slots.includes(currentTime) && <option value={currentTime}>{currentTime} · вже зайнято</option>}{!slots.length && !currentTime && <option value="" disabled>Немає вільного часу</option>}{slots.map((time) => <option key={time} value={time}>{time}</option>)}</>; })()}</select></label>
                    <label>Нагадати на дашборді<select disabled={!calendarFeatureAvailable} value={service.appointment_reminder_minutes ?? 1440} onChange={(event) => updateService(index, "appointment_reminder_minutes", Number(event.target.value))}><option value={0}>Без нагадування</option><option value={15}>За 15 хвилин</option><option value={60}>За 1 годину</option><option value={1440}>За 1 день</option><option value={4320}>За 3 дні</option></select></label>
                  </div>}
                  {service.add_to_calendar && clientId && onSaveAppointment && <button type="button" className="client-service-appointment-save" disabled={!calendarFeatureAvailable || !service.appointment_at || savingAppointmentIndex !== null} onClick={() => saveAppointment(index)}>{savingAppointmentIndex === index ? "Додаємо в календар…" : service.appointment_task_id ? "Оновити запис у календарі" : "Додати запис до календаря"}</button>}
                  {service.add_to_calendar && !clientId && <small className="client-service-calendar-locked">Запис буде додано після створення клієнта.</small>}
                </div>}
              </div>)}</div>
              <div className="client-services-total"><span>Разом за послуги</span><strong>{serviceTotal.toLocaleString("uk-UA", { minimumFractionDigits: 2 })} грн</strong></div>
            </fieldset>}
            <div className="client-form-actions">
              <button type="button" className="outline-btn" onClick={onClose}>
                Скасувати
              </button>
              <button className={`primary-action client-submit-action ${editing ? "is-save" : "is-create"}`} disabled={clientCreationLimitReached} title={clientCreationLimitReached ? planLimitHint(subscription, "clients") : undefined}>
                {editing ? "Зберегти зміни" : clientCreationLimitReached ? "Ліміт клієнтів досягнуто" : "Створити клієнта"}
              </button>
            </div>
          </form>
        </div>
      </section>
    </div>
  );
  return <>{pageMode ? content : createPortal(content, document.body)}{duplicateNotice && createPortal(<div className="form-notice" role="status">{duplicateNotice}</div>, document.body)}{duplicatePrompt && createPortal(<div className="client-duplicate-backdrop" role="presentation"><section className="client-duplicate-dialog" role="dialog" aria-modal="true" aria-labelledby="client-duplicate-title"><span className="client-duplicate-icon"><Icon name="subscription" /></span><p className="eyebrow">СТОМАТОЛОГІЯ</p><h2 id="client-duplicate-title">Послугу вже додано</h2><p>У цього пацієнта вже є послуга «{duplicatePrompt.value}». Бажаєте додати її ще раз?</p><div className="client-duplicate-actions"><button type="button" className="outline-btn" onClick={() => setDuplicatePrompt(null)}>Скасувати</button><button type="button" className="primary-action" onClick={() => { const prompt = duplicatePrompt; setDuplicatePrompt(null); updateService(prompt.index, "name", prompt.value, true); }}>Так, додати ще раз</button></div></section></div>, document.body)}</>;
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
  const isSimpleUser = (user?.workspace_role || user?.role) === "user";
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
          <p className="client-modal-subtitle">{client.module === "accounting" ? "Детальна інформація та податковий профіль." : `Профіль модуля «${moduleNames[client.module] || "Клієнти"}».`}</p>
          {client.module !== "accounting" && <div className="client-view-grid">{Object.entries(client.module_data || {}).filter(([key, value]) => key !== "doctor_id" && value !== "" && value != null).map(([key, value]) => <Info key={key} label={({ contact_person: "Контактна особа", phone: "Телефон", email: "Email", birth_date: "Дата народження", patient_type: "Тип клієнта", doctor: "Лікар", medical_record: "Медична картка", case_type: "Тип справи", case_number: "Номер справи", case_status: "Статус справи", property_type: "Тип об’єкта", client_role: "Роль клієнта", property_address: "Адреса об’єкта", budget: "Бюджет / вартість" })[key] || key} value={value} />)}</div>}
          <div className="client-view-grid">
            {client.module === "accounting" && <><Info label="Форма власності" value={client.legal_form} />
            <Info label="Система оподаткування" value={client.tax_system} />
            <Info label="ПДВ" value={client.has_vat ? "Є" : "Немає"} />
            <Info label="Акциз" value={client.has_excise ? "Є" : "Немає"} />
            <Info
              label="Наймані працівники"
              value={
                client.has_employees ? `Є — ${client.employee_count}` : "Немає"
              }
            /></>}
            <Info label="Створив" value={client.creator_name || "—"} />
          </div>
          {client.module === "accounting" && <div className="client-view-taxes">
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
          </div>}
          {client.module !== "accounting" && client.module !== "dentistry" && <div className="client-view-services"><small>ПОСЛУГИ ТА ПАКЕТИ</small>{client.services?.length ? client.services.map((service, index) => <div className="client-view-service" key={`${service.name}-${index}`}><strong>{service.name}{service.is_package && <small>Пакет · {(service.package_services || []).join(", ")}</small>}{service.appointment_task_id && service.add_to_calendar && service.appointment_at && <small className="client-view-appointment-time">Прийом · {new Date(service.appointment_at).toLocaleString("uk-UA", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" })}</small>}</strong><span className="client-view-service-amounts"><small>{Number(service.price || 0).toLocaleString("uk-UA", { minimumFractionDigits: 2 })} грн</small><em>{service.period || "Разово"}</em></span></div>) : <em>Послуги ще не додані.</em>}</div>}
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
  if (data.active_client_module === "dentistry") return <DentistryClientsPage data={data} onCreate={openCreate} onEdit={openEdit} onDelete={remove} notice={notice} error={error} />;
  const clientLimitReached = isPlanLimitReached(data.user?.subscription, "clients");
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
              disabled={clientLimitReached}
              title={clientLimitReached ? planLimitHint(data.user.subscription, "clients") : undefined}
              onClick={openCreate}
            >
              <Icon name="plus" /> {clientLimitReached ? "Ліміт клієнтів досягнуто" : "Новий клієнт"}
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
                          {data.permissions?.delete !== false && <button
                            className="client-delete"
                            type="button"
                            onClick={() => remove(client)}
                          >Видалити</button>}
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
          currentUserId={data.user?.id || ""}
          subscription={data.user?.subscription}
          availableUsers={data?.available_users || []}
          teamUsers={data?.team_users || []}
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
