import { useEffect, useMemo, useState } from "react";
import Layout from "../components/Layout.jsx";
import SearchableSelect from "../components/SearchableSelect.jsx";
import DateTimePicker from "../components/DateTimePicker.jsx";
import { api, post } from "../services/api.js";
import "./accounting-payouts.css";

const PAGE_SIZE = 10;
const labels = { pending_manager: "Очікує рішення менеджера", payment_requested: "Запит на виплату", approved: "Схвалено", paid: "Виплачено", rejected: "Відхилено" };
const money = value => Number(value || 0).toLocaleString("uk-UA", { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + " грн";
const today = () => new Intl.DateTimeFormat("sv-SE", { timeZone: "Europe/Kyiv" }).format(new Date());
const isArchived = item => ["paid", "rejected"].includes(item.status);

export default function AccountingPayouts() {
  const [data, setData] = useState(null);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState("");
  const [archive, setArchive] = useState(false);
  const [page, setPage] = useState(1);
  const [historyItem, setHistoryItem] = useState(null);
  const [filters, setFilters] = useState({ accountant: "", client: "", service: "", period: "", status: "" });

  const load = async () => {
    const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => value));
    const result = await api("/api/accounting-payouts.php?" + query);
    if (!result.ok) { setError(result.message || "Не вдалося завантажити виплати."); return; }
    setData(result);
    setError("");
  };

  useEffect(() => { load(); }, [filters.accountant, filters.client, filters.service, filters.period, filters.status]);
  useEffect(() => { setPage(1); }, [archive, filters.accountant, filters.client, filters.service, filters.period, filters.status]);
  useEffect(() => {
    if (!notice && !error) return;
    const timer = window.setTimeout(() => { setNotice(""); setError(""); }, 5000);
    return () => window.clearTimeout(timer);
  }, [notice, error]);

  const services = useMemo(() => [...new Set((data?.accruals || []).map(item => item.service_name).filter(Boolean))], [data]);
  const act = async (action, id, extra = {}) => {
    setBusy(id);
    const result = await post("/api/accounting-payouts.php", { action, id, ...extra });
    setBusy("");
    if (!result.ok) { setError(result.message || "Дію не виконано."); return; }
    setNotice(result.message);
    await load();
  };

  if (!data && !error) return <div className="loading-screen">Завантажуємо виплати бухгалтерів…</div>;
  if (!data) return <div className="payout-load-error"><strong>{error}</strong><button className="primary-action" onClick={load}>Спробувати ще раз</button></div>;

  const isManager = Boolean(data.can_manage);
  const activeAccruals = (data.accruals || []).filter(item => !isArchived(item));
  const archivedAccruals = (data.accruals || []).filter(isArchived);
  const visibleAccruals = archive ? archivedAccruals : activeAccruals;
  const pageCount = Math.max(1, Math.ceil(visibleAccruals.length / PAGE_SIZE));
  const currentPage = Math.min(page, pageCount);
  const tableRows = visibleAccruals.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);
  const setFilter = (key, value) => setFilters(current => ({ ...current, [key]: value }));

  return <Layout page={isManager ? "Виплати бухгалтерам" : "Виплати"} user={data.user}>
    <main className={"content accounting-payouts-page" + (archive ? " payouts-archive" : "")}>
      <header className="payouts-hero">
        <div><p className="eyebrow">БУХГАЛТЕРІЯ · РОЗРАХУНКИ КОМАНДИ</p><h1>{isManager ? "Виплати бухгалтерам" : "Мої нарахування"}</h1><p className="subtext">Нарахування створюються лише після повної підтвердженої оплати клієнта.</p></div>
        <button className="outline-btn" type="button" onClick={load} disabled={Boolean(busy)}>Оновити дані</button>
      </header>
      {error && <div className="form-notice accounting-toast" role="alert">{error}</div>}
      {notice && <div className="team-notice accounting-toast" role="status">{notice}</div>}
      <section className="payout-summary-grid">{[["pending_manager", "Очікують рішення"], ["payment_requested", "Запитані"], ["approved", "Схвалені"], ["paid", "Виплачені"]].map(([status, title]) => <article key={status} className={"payout-summary payout-summary-" + status}><small>{title}</small><strong>{money(data.totals?.[status])}</strong></article>)}</section>
      {isManager && <section className="panel payout-filters"><div><h2>Фільтри</h2><p>Пошук по всіх нарахуваннях робочого простору.</p></div><div className="payout-filter-fields">
        <label>Бухгалтер<SearchableSelect options={[{ id: "", name: "Усі бухгалтери" }, ...(data.accountants || []).map(person => ({ id: person.id, name: person.name, email: person.email }))]} value={filters.accountant} onChange={value => setFilter("accountant", value)} /></label>
        <label>Клієнт<SearchableSelect options={[{ id: "", name: "Усі клієнти" }, ...(data.clients || []).map(client => ({ id: client.id, name: client.name }))]} value={filters.client} onChange={value => setFilter("client", value)} /></label>
        <label>Послуга<SearchableSelect options={[{ id: "", name: "Усі послуги" }, ...services.map(service => ({ id: service, name: service }))]} value={filters.service} onChange={value => setFilter("service", value)} /></label>
        <label>Період<DateTimePicker monthOnly value={filters.period} onChange={value => setFilter("period", value)} /></label>
        <label>Статус<SearchableSelect options={[{ id: "", name: "Усі статуси" }, ...Object.entries(labels).map(([id, name]) => ({ id, name }))]} value={filters.status} onChange={value => setFilter("status", value)} /></label>
      </div></section>}
      {isManager && data.manual_reviews?.length > 0 && <section className="panel payout-manual-review"><div><h2>Потребують ручного рішення</h2><p>Автоматичне нарахування не створено, щоб не виплатити повну суму помилково.</p></div>{data.manual_reviews.map(item => <article key={item.payment_id + item.reason}><strong>{item.client_name} · {item.period_key}</strong><span>{money(item.payment_amount)} з рахунка на {money(item.invoice_total)}</span><small>{item.reason}</small></article>)}</section>}
      <div className="payout-view-switcher" role="tablist" aria-label="Перегляд нарахувань">
        <button type="button" role="tab" aria-selected={!archive} className={!archive ? "active" : ""} onClick={() => setArchive(false)}>До виплати <b>{activeAccruals.length}</b></button>
        <button type="button" role="tab" aria-selected={archive} className={archive ? "active" : ""} onClick={() => setArchive(true)}>Архів <b>{archivedAccruals.length}</b></button>
      </div>
      <section className="panel payout-list-panel">
        <div className="payout-list-heading"><div><h2>{archive ? "Архів виплат" : (isManager ? "Нарахування до виплати" : "Мої нарахування до виплати")}</h2><p>{visibleAccruals.length} {visibleAccruals.length === 1 ? "запис" : "записів"}</p></div></div>
        {tableRows.length ? <><div className="payout-table-wrap"><table className="payout-table"><thead><tr><th>Клієнт / послуга</th>{isManager && <th>Відповідальний</th>}<th>Період</th><th>Клієнтська оплата</th><th>Нараховано</th><th>Статус</th><th aria-label="Дії" /></tr></thead><tbody>{tableRows.map(item => <tr key={item.id}>
          <td><strong>{item.client_name}</strong><span>{item.service_name}</span><small>Рахунок № {item.invoice_no || "—"}</small></td>
          {isManager && <td>{item.accountant_name}</td>}<td>{item.period_key}</td><td>{money(item.source_payment_amount)}<small>{item.paid_on || "—"}</small></td><td className="payout-cell-amount">{money(item.amount)}</td><td><span className={"payout-status payout-status-" + item.status}>{labels[item.status] || item.status}</span></td>
          <td className="payout-table-actions">{!archive && <>{!isManager && item.status === "pending_manager" && <button className="primary-action" disabled={busy === item.id} onClick={() => act("request", item.id)}>Запросити</button>}{isManager && item.status === "pending_manager" && <button className="primary-action" disabled={busy === item.id} onClick={() => { const paid_at = window.prompt("Дата виплати (РРРР-ММ-ДД)", today()); if (!paid_at) return; const comment = window.prompt("Коментар до виплати (необов’язково)") || ""; act("mark_paid", item.id, { paid_at, comment }); }}>Виплатити без запиту</button>}{isManager && item.status === "payment_requested" && <><button className="primary-action" disabled={busy === item.id} onClick={() => act("approve", item.id)}>Схвалити</button><button className="outline-btn" disabled={busy === item.id} onClick={() => { const comment = window.prompt("Коментар до відхилення"); if (comment?.trim()) act("reject", item.id, { comment }); }}>Відхилити</button></>}{isManager && item.status === "approved" && <button className="primary-action" disabled={busy === item.id} onClick={() => { const paid_at = window.prompt("Дата виплати (РРРР-ММ-ДД)", today()); if (!paid_at) return; const comment = window.prompt("Коментар до виплати (необов’язково)") || ""; act("mark_paid", item.id, { paid_at, comment }); }}>Виплачено</button>}</>}{data.history?.[item.id]?.length > 0 && <button type="button" className="payout-history-button" onClick={() => setHistoryItem(item)}>↗ Історія</button>}</td>
        </tr>)}</tbody></table></div>
          {pageCount > 1 && <nav className="payout-pagination" aria-label="Сторінки списку"><button type="button" className="outline-btn" disabled={currentPage === 1} onClick={() => setPage(current => current - 1)}>Назад</button><span>Сторінка <b>{currentPage}</b> з <b>{pageCount}</b></span><button type="button" className="outline-btn" disabled={currentPage === pageCount} onClick={() => setPage(current => current + 1)}>Далі</button></nav>}</> : <div className="payout-empty"><strong>{archive ? "Архів поки порожній" : "Нарахувань до виплати немає"}</strong><p>{archive ? "Тут з’являться виплачені або відхилені нарахування." : "Тут відображаються лише суми, за якими ще потрібно прийняти рішення або провести виплату."}</p></div>}
      </section>
      {historyItem && <div className="payout-history-modal-backdrop" role="presentation"><section className="payout-history-modal" role="dialog" aria-modal="true" aria-labelledby="payout-history-title"><button type="button" className="payout-history-modal-close" onClick={() => setHistoryItem(null)} aria-label="Закрити історію">×</button><p className="eyebrow">ІСТОРІЯ НАРАХУВАННЯ</p><h2 id="payout-history-title">{historyItem.client_name}</h2><p className="payout-history-modal-subtitle">{historyItem.service_name} · {historyItem.period_key} · <strong>{money(historyItem.amount)}</strong></p><div className="payout-history-events">{(data.history?.[historyItem.id] || []).map(event => <article key={event.id}><span className="payout-history-dot" /><div><strong>{event.user_name || "Користувач"}</strong><p>{event.action}{event.comment ? ` · ${event.comment}` : ""}</p><time>{new Date(String(event.created_at).replace(" ", "T")).toLocaleString("uk-UA")}</time></div></article>)}</div><button type="button" className="outline-btn" onClick={() => setHistoryItem(null)}>Закрити</button></section></div>}
    </main>
  </Layout>;
}
