import { useEffect, useState } from "react";
import { createPortal } from "react-dom";
import { useNavigate } from "react-router-dom";
import Layout from "../components/Layout.jsx";
import { api, post } from "../services/api.js";
import "./clients.css";

const specialTaxOptions = [
  ["tourist", "Туристичний збір"],
  ["land", "Земельний податок"],
  ["property", "Податок на нерухомість"],
  ["rent", "Рентна плата"],
  ["environmental", "Екологічний податок"],
  ["transport", "Транспортний податок"],
  ["retail_excise", "Роздрібний акциз"],
  ["other", "Інші специфічні податки"],
];
const legalForms = [
  "ТОВ",
  "ФОП",
  "ПрАТ",
  "АТ",
  "Неприбуткова організація",
  "Інше",
];
const taxSystems = [
  "Загальна система",
  "Єдиний податок 3 група",
  "Єдиний податок 5 група",
  "Єдиний податок 4 група",
  "Без системи оподаткування",
];
const emptyForm = {
  name: "",
  legal_form: "ТОВ",
  tax_system: "Загальна система",
  has_vat: false,
  has_excise: false,
  has_employees: false,
  employee_count: "",
  special_taxes: [],
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
function ClientFormModal({ form, setForm, editing, onSave, onClose }) {
  const set = (field, value) =>
    setForm((current) => ({ ...current, [field]: value }));
  const toggleTax = (value) =>
    set(
      "special_taxes",
      form.special_taxes.includes(value)
        ? form.special_taxes.filter((item) => item !== value)
        : [...form.special_taxes, value],
    );
  return createPortal(
    <div
      className="client-modal-backdrop"
      onMouseDown={(event) => event.target === event.currentTarget && onClose()}
    >
      <section className="client-modal">
        <button type="button" className="client-modal-close" onClick={onClose}>
          ×
        </button>
        <div className="client-modal-scroll">
          <p className="eyebrow">КЛІЄНТ</p>
          <h2>{editing ? "Редагувати клієнта" : "Створити клієнта"}</h2>
          <p className="client-modal-subtitle">
            Додайте організаційні та податкові дані клієнта.
          </p>
          <form className="client-form" onSubmit={onSave}>
            <div className="client-form-grid">
              <label>
                Назва клієнта
                <input
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
            <div className="client-form-actions">
              <button type="button" className="outline-btn" onClick={onClose}>
                Скасувати
              </button>
              <button className="primary-action">
                {editing ? "Зберегти зміни" : "Створити клієнта"}
              </button>
            </div>
          </form>
        </div>
      </section>
    </div>,
    document.body,
  );
}
function Info({ label, value }) {
  return (
    <div>
      <small>{label}</small>
      <strong>{value}</strong>
    </div>
  );
}
function ClientViewModal({ client, onClose }) {
  return createPortal(
    <div
      className="client-modal-backdrop"
      onMouseDown={(event) => event.target === event.currentTarget && onClose()}
    >
      <section className="client-modal client-view-modal">
        <button type="button" className="client-modal-close" onClick={onClose}>
          ×
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
  const [search, setSearch] = useState("");
  const [legalFilter, setLegalFilter] = useState("all");
  const [taxFilter, setTaxFilter] = useState("all");
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
  }, [search, legalFilter, taxFilter]);
  function openCreate() {
    setEditing(null);
    setForm({ ...emptyForm, special_taxes: [] });
    setModal(true);
  }
  function openEdit(client) {
    setEditing(client);
    setForm({
      ...client,
      has_vat: Boolean(Number(client.has_vat)),
      has_excise: Boolean(Number(client.has_excise)),
      has_employees: Boolean(Number(client.has_employees)),
      employee_count: client.employee_count || "",
      special_taxes: client.special_taxes || [],
    });
    setModal(true);
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
  });
  const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
  const visible = filtered.slice((page - 1) * pageSize, page * pageSize);
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
              className="primary-action"
              type="button"
              onClick={openCreate}
            >
              ＋ Новий клієнт
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
              ⌕
              <input
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
          </div>
          {visible.length ? (
            <>
              <div className="clients-table">
                <div className="clients-table-head">
                  <span>Клієнт</span>
                  <span>Форма</span>
                  <span>Оподаткування</span>
                  <span>Особливості</span>
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
                  {filtered.length}
                </small>
                <div>
                  <button
                    disabled={page === 1}
                    onClick={() => setPage((value) => value - 1)}
                  >
                    ‹
                  </button>
                  <b>{page}</b>
                  <button
                    disabled={page === totalPages}
                    onClick={() => setPage((value) => value + 1)}
                  >
                    ›
                  </button>
                </div>
              </div>
            </>
          ) : (
            <div className="clients-empty">
              <span>◎</span>
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
          onSave={save}
          onClose={() => setModal(false)}
        />
      )}
      {viewing && (
        <ClientViewModal client={viewing} onClose={() => setViewing(null)} />
      )}
    </Layout>
  );
}
