import { useEffect, useState } from "react";
import Icon from "../components/Icon.jsx";
import Layout from "../components/Layout.jsx";
import { api, post } from "../services/api.js";
import "./services.css";

const emptyPackage = { name: "", module: "accounting", description: "" };
const emptyService = { name: "", package_id: "", client_price: "", responsible_amount: "", responsible_user_id: "" };
const money = value => `${Number(value || 0).toLocaleString("uk-UA", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} грн`;

export default function Services() {
  const [data, setData] = useState(null);
  const [notice, setNotice] = useState("");
  const [error, setError] = useState("");
  const [search, setSearch] = useState("");
  const [packageForm, setPackageForm] = useState(null);
  const [serviceForm, setServiceForm] = useState(null);
  const [saving, setSaving] = useState(false);

  async function load() {
    const result = await api("/api/services.php");
    if (result.ok) setData(result); else setError(result.message || "Не вдалося завантажити послуги.");
  }
  useEffect(() => { load(); }, []);
  async function save(action, payload, close) {
    setSaving(true); setError("");
    const result = await post("/api/services.php", { action, ...payload });
    setSaving(false);
    if (!result.ok) return setError(result.message || "Не вдалося зберегти зміни.");
    setNotice(result.message); close(null); await load();
    window.setTimeout(() => setNotice(""), 2600);
  }
  async function remove(action, id, label) {
    if (!window.confirm(`Видалити ${label}?`)) return;
    const result = await post("/api/services.php", { action, id });
    if (!result.ok) return setError(result.message || "Не вдалося видалити.");
    setNotice(result.message); await load(); window.setTimeout(() => setNotice(""), 2600);
  }
  if (!data) return <div className="loading-screen">Завантаження послуг…</div>;
  const canManage = data.permissions?.manage;
  const filteredPackages = data.packages.filter((item) => {
    const query = search.trim().toLocaleLowerCase("uk");
    return !query || item.name.toLocaleLowerCase("uk").includes(query) || item.services.some(service => service.name.toLocaleLowerCase("uk").includes(query));
  });
  return <Layout page="Послуги" user={data.user}>
    <div className="content services-page">
      <div className="services-hero">
        <div><p className="eyebrow">МОДУЛЬ КЛІЄНТІВ</p><h1>Послуги</h1><p>Створюйте пакети та керуйте цінами для клієнтів і виплатами відповідальним.</p></div>
        {canManage && <button className="primary-action" type="button" onClick={() => setPackageForm({ ...emptyPackage })}><Icon name="plus" /> Новий пакет</button>}
      </div>
      {!canManage && <div className="services-readonly"><Icon name="eye" /> Перегляд каталогу послуг доступний у вашій ролі без редагування.</div>}
      {notice && <div className="services-notice">{notice}</div>}
      {error && <div className="services-error">{error}</div>}
      <section className="panel services-catalog-panel">
        <div className="services-toolbar"><div><h2>Пакети послуг <span>{data.packages.length}</span></h2><p>Готові набори для швидкого додавання клієнтам.</p></div><label className="services-search"><Icon name="search" /><input value={search} onChange={event => setSearch(event.target.value)} placeholder="Пошук пакета або послуги…" /></label></div>
        {filteredPackages.length ? <div className="service-packages">{filteredPackages.map(pkg => <article className="service-package" key={pkg.id}>
          <header className="service-package-head"><div><span className="service-package-icon"><Icon name="subscription" /></span><div><h3>{pkg.name}</h3><small>{pkg.module_label} · {pkg.services_count} послуг</small></div></div>{canManage && <div className="service-package-actions"><button type="button" onClick={() => setPackageForm({ id: pkg.id, name: pkg.name, module: pkg.module, description: pkg.description || "" })} aria-label="Редагувати пакет"><Icon name="edit" /></button><button type="button" onClick={() => remove("delete_package", pkg.id, "цей пакет разом із послугами")} aria-label="Видалити пакет"><Icon name="close" /></button></div>}</header>
          {pkg.description && <p className="service-package-description">{pkg.description}</p>}
          <div className="service-items">{pkg.services.length ? pkg.services.map(service => <div className="service-item" key={service.id}><div className="service-item-name"><strong>{service.name}</strong><small>{service.responsible_name ? `Відповідальний: ${service.responsible_name}` : "Відповідальний не призначений"}</small></div><div className="service-item-money"><span><small>Для клієнта</small><b>{money(service.client_price)}</b></span><span><small>Виплата</small><b>{money(service.responsible_amount)}</b></span></div>{canManage && <div className="service-item-actions"><button type="button" onClick={() => setServiceForm({ id: service.id, name: service.name, package_id: service.package_id, client_price: service.client_price, responsible_amount: service.responsible_amount, responsible_user_id: service.responsible_user_id || "" })} aria-label="Редагувати послугу"><Icon name="edit" /></button><button type="button" onClick={() => remove("delete_service", service.id, "цю послугу")} aria-label="Видалити послугу"><Icon name="close" /></button></div>}</div>) : <p className="service-empty">У цьому пакеті ще немає послуг.</p>}</div>
          {canManage && <button className="service-add-button" type="button" onClick={() => setServiceForm({ ...emptyService, package_id: pkg.id })}><Icon name="plus" /> Додати послугу</button>}
        </article>)}</div> : <div className="services-empty"><Icon name="subscription" /><h3>{search ? "Нічого не знайдено" : "Пакетів ще немає"}</h3><p>{search ? "Спробуйте змінити пошуковий запит." : "Створіть перший пакет послуг для модуля клієнтів."}</p></div>}
      </section>
    </div>
    {packageForm && <div className="services-modal-backdrop"><form className="services-modal" onSubmit={event => { event.preventDefault(); save(packageForm.id ? "update_package" : "create_package", packageForm, setPackageForm); }}><button type="button" className="services-modal-close" onClick={() => setPackageForm(null)}><Icon name="close" /></button><p className="eyebrow">ПАКЕТ ПОСЛУГ</p><h2>{packageForm.id ? "Редагувати пакет" : "Новий пакет"}</h2><label>Назва пакета<input autoFocus required value={packageForm.name} onChange={event => setPackageForm({ ...packageForm, name: event.target.value })} placeholder="Наприклад, Бухгалтерія Старт" /></label><label>Модуль клієнтів<select value={packageForm.module} onChange={event => setPackageForm({ ...packageForm, module: event.target.value })}>{data.modules.map(module => <option key={module.value} value={module.value}>{module.label}</option>)}</select></label><label>Опис пакета<textarea rows="3" value={packageForm.description} onChange={event => setPackageForm({ ...packageForm, description: event.target.value })} placeholder="Коротко опишіть склад пакета" /></label><div className="services-modal-actions"><button className="outline-btn" type="button" onClick={() => setPackageForm(null)}>Скасувати</button><button className="primary-action" disabled={saving} type="submit">{saving ? "Збереження…" : "Зберегти"}</button></div></form></div>}
    {serviceForm && <div className="services-modal-backdrop"><form className="services-modal" onSubmit={event => { event.preventDefault(); save(serviceForm.id ? "update_service" : "create_service", serviceForm, setServiceForm); }}><button type="button" className="services-modal-close" onClick={() => setServiceForm(null)}><Icon name="close" /></button><p className="eyebrow">ПОСЛУГА</p><h2>{serviceForm.id ? "Редагувати послугу" : "Нова послуга"}</h2><label>Назва послуги<input autoFocus required value={serviceForm.name} onChange={event => setServiceForm({ ...serviceForm, name: event.target.value })} placeholder="Наприклад, Ведення обліку" /></label><label>Пакет<select required value={serviceForm.package_id} onChange={event => setServiceForm({ ...serviceForm, package_id: event.target.value })}>{data.packages.map(pkg => <option key={pkg.id} value={pkg.id}>{pkg.name}</option>)}</select></label><div className="service-form-grid"><label>Ціна для клієнта<input type="number" min="0" step="0.01" required value={serviceForm.client_price} onChange={event => setServiceForm({ ...serviceForm, client_price: event.target.value })} placeholder="0.00" /></label><label>Виплата відповідальному<input type="number" min="0" step="0.01" required value={serviceForm.responsible_amount} onChange={event => setServiceForm({ ...serviceForm, responsible_amount: event.target.value })} placeholder="0.00" /></label></div><label>Відповідальний виконавець<select value={serviceForm.responsible_user_id} onChange={event => setServiceForm({ ...serviceForm, responsible_user_id: event.target.value })}><option value="">Не призначено</option>{data.users.map(person => <option key={person.id} value={person.id}>{person.name}</option>)}</select></label><div className="services-modal-actions"><button className="outline-btn" type="button" onClick={() => setServiceForm(null)}>Скасувати</button><button className="primary-action" disabled={saving} type="submit">{saving ? "Збереження…" : "Зберегти"}</button></div></form></div>}
  </Layout>;
}
