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
  const add = (title, date, detail = "") => events.push({ title, date, detail });
  if (/^ФОП [12] група$/.test(system)) {
    add("Річна декларація платника ЄП та додаток ЄСВ", nextPeriodDeadline(now, "year", 60));
  } else if (system === "ФОП 3 група" || system === "Єдиний податок 3 група") {
    add("Декларація платника ЄП (квартал)", nextPeriodDeadline(now, "quarter", 40));
  } else if (system === "ФОП на загальній системі" || (system === "Загальна система" && client.legal_form === "ФОП")) {
    let date = nextWorkingDay(new Date(now.getFullYear(), 3, 30));
    if (date < now) date = nextWorkingDay(new Date(now.getFullYear() + 1, 3, 30));
    add("Декларація про майновий стан і доходи ФОП", date);
  } else if (system === "ТОВ на єдиному податку (3 група)" || system === "Єдиний податок 5 група") {
    add("Декларація платника ЄП (юрособа, квартал)", nextPeriodDeadline(now, "quarter", 40));
  } else if (system === "ТОВ на загальній системі" || (system === "Загальна система" && client.legal_form === "ТОВ")) {
    const quarterly = Boolean(client.module_data?.tax_income_over_40m);
    add("Декларація з податку на прибуток і фінансова звітність", nextPeriodDeadline(now, quarterly ? "quarter" : "year", quarterly ? 40 : 60), quarterly ? "Квартальний період за доходу попереднього року понад 40 млн грн." : "Річний період за доходу попереднього року до 40 млн грн.");
    let statisticsDate = nextWorkingDay(new Date(now.getFullYear(), 1, 28));
    if (statisticsDate < now) statisticsDate = nextWorkingDay(new Date(now.getFullYear() + 1, 1, 28));
    add("Річна фінансова звітність до Держстату", statisticsDate);
  }
  const isFop = client.legal_form === "ФОП";
  if (client.has_employees) {
    const date = isFop ? nextPeriodDeadline(now, "quarter", 40) : nextPeriodDeadline(now, "month", 20);
    add("Податковий розрахунок ПДФО, військового збору та ЄСВ за працівників", date, isFop ? "Квартальний звіт для ФОП." : "Місячний звіт для юридичних осіб.");
  }
  if (client.has_vat) add("Декларація з ПДВ", nextPeriodDeadline(now, "month", 0, 20));
  if (client.has_excise) add("Декларація з акцизного податку", nextPeriodDeadline(now, "month", 0, 20));
  const special = client.special_taxes || [];
  if (special.includes("retail_excise") && !client.has_excise) add("Декларація з роздрібного акцизу", nextPeriodDeadline(now, "month", 0, 20));
  return events.map((event, index) => ({ ...event, id: `tax-${client.id}-${index}`, client }));
}
