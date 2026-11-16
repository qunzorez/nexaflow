function addDays(date, days) {
  const result = new Date(date.getFullYear(), date.getMonth(), date.getDate());
  result.setDate(result.getDate() + days);
  return result;
}

function nextWorkingDay(date) {
  const result = new Date(date);
  while (result.getDay() === 0 || result.getDay() === 6) result.setDate(result.getDate() + 1);
  return result;
}

function afterPeriod(end, days) {
  return nextWorkingDay(addDays(end, days));
}

function nextPeriodDeadline(now, kind, days, monthDay = null) {
  const year = now.getFullYear();
  const month = now.getMonth();
  if (kind === "year") {
    let result = afterPeriod(new Date(year - 1, 11, 31), days);
    if (result < now) result = afterPeriod(new Date(year, 11, 31), days);
    return result;
  }
  if (kind === "month") {
    let result = nextWorkingDay(new Date(year, month, monthDay));
    if (result < now) result = nextWorkingDay(new Date(year, month + 1, monthDay));
    return result;
  }
  let quarter = Math.floor(month / 3);
  let result = afterPeriod(new Date(year, quarter * 3 + 3, 0), days);
  if (result < now) {
    quarter += 1;
    result = afterPeriod(new Date(year, quarter * 3 + 3, 0), days);
  }
  return result;
}

export function clientTaxDeadlines(client, now = new Date()) {
  if (client.module && client.module !== "accounting") return [];
  const system = String(client.tax_system || "");
  const events = [];
  const add = (title, date, detail = "", recurrenceRule = "none") => events.push({ title, date, detail, recurrenceRule });
  if (/^ФОП [12] група$/.test(system) || (client.legal_form === "ФОП" && ["1 група", "2 група"].includes(system))) {
    add("Річна декларація платника ЄП та додаток ЄСВ", nextPeriodDeadline(now, "year", 60), "", "yearly");
  } else if (system === "ФОП 3 група" || system === "Єдиний податок 3 група" || (system === "3 група" && client.legal_form === "ФОП")) {
    add("Декларація платника ЄП (квартал)", nextPeriodDeadline(now, "quarter", 40), "", "quarterly");
  } else if (system === "ФОП на загальній системі" || (system === "Загальна система" && client.legal_form === "ФОП")) {
    let date = nextWorkingDay(new Date(now.getFullYear(), 3, 30));
    if (date < now) date = nextWorkingDay(new Date(now.getFullYear() + 1, 3, 30));
    add("Декларація про майновий стан і доходи ФОП", date, "", "yearly");
  } else if (system === "ТОВ на єдиному податку (3 група)" || system === "Єдиний податок, 3 група" || (system === "3 група" && client.legal_form === "ТОВ")) {
    add("Декларація платника ЄП (юрособа, квартал)", nextPeriodDeadline(now, "quarter", 40), "", "quarterly");
  } else if (system === "ТОВ на загальній системі" || (system === "Загальна система" && client.legal_form === "ТОВ")) {
    const quarterly = Boolean(client.module_data?.tax_income_over_40m);
    add("Декларація з податку на прибуток і фінансова звітність", nextPeriodDeadline(now, quarterly ? "quarter" : "year", quarterly ? 40 : 60), quarterly ? "Квартальний період за доходу попереднього року понад 40 млн грн." : "Річний період за доходу попереднього року до 40 млн грн.", quarterly ? "quarterly" : "yearly");
    let statisticsDate = nextWorkingDay(new Date(now.getFullYear(), 1, 28));
    if (statisticsDate < now) statisticsDate = nextWorkingDay(new Date(now.getFullYear() + 1, 1, 28));
    add("Річна фінансова звітність до Держстату", statisticsDate, "", "yearly");
  }
  const isFop = client.legal_form === "ФОП";
  if (client.has_employees) {
    const date = isFop ? nextPeriodDeadline(now, "quarter", 40) : nextPeriodDeadline(now, "month", 20);
    add("Податковий розрахунок ПДФО, військового збору та ЄСВ за працівників", date, isFop ? "Квартальний звіт для ФОП." : "Місячний звіт для юридичних осіб.", isFop ? "quarterly" : "monthly");
  }
  if (client.has_vat) add("Декларація з ПДВ", nextPeriodDeadline(now, "month", 0, 20), "", "monthly");
  if (client.has_excise) add("Декларація з акцизного податку", nextPeriodDeadline(now, "month", 0, 20), "", "monthly");
  const special = client.special_taxes || [];
  if (special.includes("retail_excise") && !client.has_excise) add("Декларація з роздрібного акцизу", nextPeriodDeadline(now, "month", 0, 20), "", "monthly");
  return events.map((event, index) => ({
    ...event,
    id: `tax-${client.id}-${index}`,
    client,
    reminderAt: new Date(event.date.getFullYear(), event.date.getMonth(), event.date.getDate() - 1, 9),
  }));
}

export function taxDeadlineTaskPayload(client, event) {
  const dateText = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
  return {
    action: "create",
    title: event.title,
    description: [event.detail, `Подання звітності для клієнта «${client.name}».`].filter(Boolean).join("\n\n"),
    client_name: client.name,
    client_ids: [client.id],
    assignee_ids: (client.module_data?.accountant_ids || []).map(String),
    due_date: `${dateText(event.date)} 17:00:00`,
    reminder_at: `${dateText(event.reminderAt)} 09:00:00`,
    creator_reminder: true,
    priority: "high",
    status: "todo",
    recurrence_rule: event.recurrenceRule || "none",
    workspace_owner_id: client.created_by || undefined,
  };
}
