import { useEffect, useState } from 'react';
import { api, post } from '../services/api.js';
import { apiUrl } from '../config';
import './accounting.css';

const dateLabel = value => value ? new Date(`${String(value).slice(0, 10)}T12:00:00`).toLocaleDateString('uk-UA') : 'Не вказано';
const invoiceStatus = { issued: 'Виставлено', partially_paid: 'Частково сплачено', paid: 'Сплачено', cancelled: 'Скасовано', overdue: 'Прострочено' };
const requestStatus = { waiting: 'Очікує', pending_review: 'На перевірці', received: 'Прийнято', rejected: 'Відхилено' };
const documentStatus = { pending_review: 'На перевірці', received: 'Прийнято', rejected: 'Потрібно виправити' };
const parseSnapshot = value => { if (!value) return null; if (typeof value === 'object') return value; try { return JSON.parse(value); } catch { return null; } }
const fileBadge = document => { const mime = document.mime_type || document.document_mime_type || ''; if (mime === 'image/jpeg' || /\.jpe?g$/i.test(document.name || document.document_name || '')) return 'JPG'; if (mime === 'image/png' || /\.png$/i.test(document.name || document.document_name || '')) return 'PNG'; if (mime === 'application/pdf' || /\.pdf$/i.test(document.name || document.document_name || '')) return 'PDF'; return 'FILE'; };
const money = amount => Number(amount || 0).toLocaleString('uk-UA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const safeFile = file => !file || file.size <= 15 * 1024 * 1024;
function sendFile(body, onProgress) {
  return new Promise(resolve => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', apiUrl('/api/accounting-portal.php'));
    xhr.withCredentials = true;
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.upload.onprogress = event => { if (event.lengthComputable) onProgress(Math.round(event.loaded * 100 / event.total)); };
    xhr.onload = () => { try { resolve(JSON.parse(xhr.responseText)); } catch { resolve({ ok: false, message: 'Сервер повернув некоректну відповідь.' }); } };
    xhr.onerror = () => resolve({ ok: false, message: 'Не вдалося завантажити файл. Спробуйте ще раз.' });
    xhr.send(body);
  });
}

export default function AccountingPortal() {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [clientId, setClientId] = useState(new URLSearchParams(location.search).get('client') || sessionStorage.getItem('accounting_portal_client') || '');
  const [busy, setBusy] = useState(false);
  const [progress, setProgress] = useState(null);
  useEffect(() => { if (!error) return undefined; const timer = window.setTimeout(() => setError(''), 5000); return () => window.clearTimeout(timer); }, [error]);
  useEffect(() => { if (!message) return undefined; const timer = window.setTimeout(() => setMessage(''), 5000); return () => window.clearTimeout(timer); }, [message]);
  const [requestView, setRequestView] = useState('active');
  const [invoiceView, setInvoiceView] = useState('active');

  function refresh(selected = clientId) {
    return api(`/api/accounting-portal.php?client_id=${encodeURIComponent(selected)}`).then(result => {
      if (result.ok) { setData(result); setError(''); }
      else if (selected && result.message === 'Немає доступу до цього клієнта.') { setClientId(''); setData(null); setError('Доступ до обраної компанії змінився. Оберіть доступну компанію.'); }
      else { setData(null); setError(result.message); }
      return result;
    });
  }
  useEffect(() => {
    setData(null);
    refresh(clientId);
    if (clientId) sessionStorage.setItem('accounting_portal_client', clientId);
    else sessionStorage.removeItem('accounting_portal_client');
    const url = new URL(window.location.href);
    if (clientId) url.searchParams.set('client', clientId); else url.searchParams.delete('client');
    window.history.replaceState(null, '', url);
  }, [clientId]);

  async function respond(id, event) {
    event.preventDefault();
    const form = event.currentTarget;
    const text = form.elements.message.value.trim();
    const body = new FormData(form);
    if (!safeFile(form.elements.file.files?.[0])) { setError('Максимальний розмір файла — 15 МБ.'); return; }
    body.set('action', 'respond');
    body.set('client_id', clientId || data.client.client_id);
    body.set('request_id', id);
    body.set('message', text);
    setBusy(true);
    setProgress(0);
    const result = await sendFile(body, setProgress);
    setBusy(false);
    setProgress(null);
    if (result.ok) {
      setMessage(result.message);
      form.reset();
      await refresh();
    } else setError(result.message);
  }

  async function notice(id, event) {
    event.preventDefault();
    const form = event.currentTarget;
    setBusy(true);
    if (!safeFile(form.elements.proof.files?.[0])) { setBusy(false); setError('Максимальний розмір файла — 15 МБ.'); return; }
    const body = new FormData(form);
    body.set('action', 'payment_notice');body.set('client_id', clientId || data.client.client_id);body.set('invoice_id', id);
    setProgress(0);
    const result = await sendFile(body, setProgress);
    setBusy(false);
    setProgress(null);
    if (result.ok) { setMessage(result.message); form.reset(); await refresh(); }
    else setError(result.message);
  }

  async function upload(event) {
    event.preventDefault();
    const form = event.currentTarget;
    if (!safeFile(form.elements.file.files?.[0])) { setError('Максимальний розмір файла — 15 МБ.'); return; }
    const body = new FormData(form);
    body.set('action', 'upload');
    body.set('client_id', clientId || data.client.client_id);
    setBusy(true);
    setProgress(0);
    const result = await sendFile(body, setProgress);
    setBusy(false);
    setProgress(null);
    if (result.ok) { setMessage(result.message); form.reset(); await refresh(); }
    else setError(result.message);
  }

  const activeRequests = (data?.requests || []).filter(request => request.status !== 'received');
  const archivedRequests = (data?.requests || []).filter(request => request.status === 'received');
  const visibleRequests = requestView === 'archive' ? archivedRequests : activeRequests;
  const activeInvoices = (data?.invoices || []).filter(invoice => !['paid', 'cancelled'].includes(invoice.status));
  const archivedInvoices = (data?.invoices || []).filter(invoice => ['paid', 'cancelled'].includes(invoice.status));
  const visibleInvoices = invoiceView === 'archive' ? archivedInvoices : activeInvoices;
  const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Kyiv', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
  const actions = data?.client ? [
    ...activeRequests.filter(request => request.status !== 'pending_review').map(request => ({ id: request.id, kind: 'request', title: request.status === 'rejected' ? `Виправити документ: ${request.title}` : `Надати документ: ${request.title}`, period: request.period_key, due: request.due_on, status: request.status, target: `request-${request.id}` })),
    ...activeInvoices.filter(invoice => !Number(invoice.payment_notice_pending)).map(invoice => ({ id: invoice.id, kind: 'invoice', title: `Переглянути рахунок № ${invoice.invoice_no} · ${money(invoice.total)} грн`, period: invoice.period_key, due: invoice.due_on, status: invoice.status, target: `invoice-${invoice.id}` }))
  ].sort((a, b) => (a.due || '9999-12-31').localeCompare(b.due || '9999-12-31')) : [];
  const upcoming = actions.filter(action => action.due && action.due >= today).slice(0, 4);
  const openAction = action => { if (action.kind === 'request') setRequestView('active'); else setInvoiceView('active'); requestAnimationFrame(() => document.getElementById(action.target)?.scrollIntoView({ behavior: 'smooth', block: 'center' })); };

  return <main className="accounting-page accounting-portal-page">
    <nav className="accounting-portal-topbar" aria-label="Кабінет клієнта"><a href="/accounting/portal" className="accounting-portal-brand"><span>N</span> NexaFlow</a><div><a href="/settings">Профіль</a><button type="button" onClick={async () => { await post('/api/logout.php', {}); sessionStorage.removeItem('accounting_portal_client'); window.location.replace('/login'); }}>Вийти</button></div></nav>
    <header className="accounting-portal-hero">
      <div><p className="eyebrow">NEXAFLOW · КАБІНЕТ КЛІЄНТА</p><h1>Кабінет клієнта</h1><p>Документи, запити та рахунки вашої компанії в одному місці.</p></div>
      {data?.client && <span className="accounting-portal-company-mark" aria-hidden="true">{data.client.name?.trim().charAt(0)?.toUpperCase() || 'К'}</span>}
    </header>
    {error && <div className="form-notice accounting-toast" role="alert">{error}</div>}
    {message && <div className="team-notice accounting-toast" role="status">{message}</div>}
    {progress !== null && <div className="accounting-portal-progress" role="status">Завантаження файла · {progress}%<progress value={progress} max="100"/></div>}
    {!data && !error && <section className="accounting-panel accounting-portal-loading" aria-live="polite">Завантажуємо кабінет…</section>}
    {data?.clients && !data.client && <section className="accounting-panel"><div className="accounting-portal-section-heading"><div><h2>Оберіть компанію</h2><p>Відкрийте кабінет клієнта, до якого вам надали доступ.</p></div></div><div className="accounting-portal-client-list">{data.clients.map(client => <button className="accounting-portal-client-option" key={client.client_id} onClick={() => setClientId(client.client_id)}><span>{client.name?.trim().charAt(0)?.toUpperCase() || 'К'}</span><strong>{client.name}</strong><b aria-hidden="true">→</b></button>)}</div></section>}
    {data?.client && <>
      <section className="accounting-portal-company"><div><small>КОМПАНІЯ</small><h2>{data.client.name}</h2></div><div className="accounting-portal-company-tools">{data.clients?.length > 1 && <label>Інша компанія<select aria-label="Оберіть компанію" value={data.client.client_id} onChange={event => setClientId(event.target.value)}>{data.clients.map(client => <option key={client.client_id} value={client.client_id}>{client.name}</option>)}</select></label>}{data.manager_view && <span className="accounting-portal-manager-tag">Перегляд менеджера</span>}</div></section>
      {data.manager_view && <div className="accounting-notice accounting-portal-manager-note">Це перегляд кабінету клієнта. Для змін використовуйте бухгалтерський модуль.</div>}

      <section className="accounting-panel accounting-portal-section accounting-portal-actions"><div className="accounting-portal-section-heading"><div><span className="accounting-portal-section-icon">✓</span><div><h2>Що потрібно зробити зараз</h2><p>Ваші відкриті запити та рахунки, починаючи з найближчого строку.</p></div></div><b className="accounting-portal-count">{actions.length}</b></div>{actions.length ? <div className="accounting-portal-action-list">{actions.map(action => <article className={`accounting-portal-action ${action.due && action.due < today ? 'is-late' : ''}`} key={`${action.kind}-${action.id}`}><div><strong>{action.title}</strong><span>{data.client.name} · період {action.period} · {action.due ? `до ${dateLabel(action.due)}` : 'без строку'}</span></div><span className={`accounting-portal-status is-${action.status}`}>{action.due && action.due < today ? 'Прострочено' : action.kind === 'request' ? requestStatus[action.status] : invoiceStatus[action.status]}</span><button type="button" className="secondary-action" onClick={() => openAction(action)}>Перейти до дії →</button></article>)}</div> : <div className="accounting-portal-empty"><strong>Зараз дій немає</strong><span>{data.messages?.[0]?.title || 'Нові запити та рахунки з’являться тут.'}</span></div>}</section>

      <section className="accounting-panel accounting-portal-section">
        <div className="accounting-portal-section-heading"><div><span className="accounting-portal-section-icon">✉</span><div><h2>Запити документів</h2><p>Документи, які бухгалтер попросив надати.</p></div></div><b className="accounting-portal-count">{data.requests.length}</b></div>
        {!!data.requests.length && <div className="accounting-portal-request-tabs" role="tablist" aria-label="Фільтр запитів документів"><button type="button" role="tab" aria-selected={requestView==='active'} className={requestView==='active'?'active':''} onClick={()=>setRequestView('active')}>Активні <span>{activeRequests.length}</span></button><button type="button" role="tab" aria-selected={requestView==='archive'} className={requestView==='archive'?'active':''} onClick={()=>setRequestView('archive')}>Архівні <span>{archivedRequests.length}</span></button></div>}
        {!data.requests.length ? <div className="accounting-portal-empty"><strong>Запитів поки немає</strong><span>Коли бухгалтер надішле запит, він з’явиться тут.</span></div> : !visibleRequests.length ? <div className="accounting-portal-empty"><strong>{requestView==='archive'?'Архів поки порожній':'Активних запитів немає'}</strong><span>{requestView==='archive'?'Прийняті запити документів з’являться тут.':'Нові запити від бухгалтера з’являться тут.'}</span></div> : <div className="accounting-portal-list">{visibleRequests.map(request => <article className="accounting-portal-request" id={`request-${request.id}`} key={request.id}>
          <div className="accounting-portal-item-heading"><div><h3>{request.title}</h3><p>Період {request.period_key}</p></div><span className={`accounting-portal-status is-${request.status}`}>{requestStatus[request.status] || request.status}</span></div>
          <p className="accounting-portal-deadline">Надати до: <strong>{dateLabel(request.due_on)}</strong></p>
          {data.responses?.filter(answer => answer.request_id === request.id).map((answer, index) => <div className="accounting-portal-response" key={`${answer.request_id}-${answer.created_at}-${index}`}><strong>{answer.user_name ? `Відповідь від ${answer.user_name}` : 'Ваша відповідь'}</strong><time>{dateLabel(answer.created_at)}</time><p>{answer.message}</p>{answer.document_id && <a className="accounting-portal-response-file" href={`/api/accounting-portal.php?client_id=${encodeURIComponent(clientId || data.client.client_id)}&document_id=${encodeURIComponent(answer.document_id)}`}><span aria-hidden="true">↧</span> {answer.document_name || 'Завантажити вкладення'}</a>}{answer.review_comment && <p className="accounting-portal-review-comment">Коментар бухгалтера: {answer.review_comment}</p>}</div>)}
          {!data.manager_view && request.status !== 'received' && <form className="accounting-portal-form accounting-portal-reply" onSubmit={event => respond(request.id, event)}><label htmlFor={`reply-${request.id}`}>Відповідь або коментар<textarea id={`reply-${request.id}`} name="message" rows="3" placeholder="Напишіть відповідь бухгалтеру…"/></label><label className="accounting-portal-attach">Прикріпити документ<input type="file" name="file" onChange={event => { const file = event.currentTarget.files?.[0]; if (file && file.size > 15 * 1024 * 1024) { event.currentTarget.value = ""; setError("Максимальний розмір файла — 15 МБ."); } else setError(""); }}/><small>Будь-який формат файла, до 15 МБ.</small></label><button className="primary-action" disabled={busy}>{busy ? 'Надсилання…' : 'Надіслати відповідь'}</button></form>}
        </article>)}</div>}
      </section>

      <section className="accounting-panel accounting-portal-section">
        <div className="accounting-portal-section-heading"><div><span className="accounting-portal-section-icon">▤</span><div><h2>Документи</h2><p>Завантажуйте потрібні файли та переглядайте надіслані.</p></div></div><b className="accounting-portal-count">{data.documents.length}</b></div>
        {!data.manager_view && <form className="accounting-portal-form accounting-portal-upload" onSubmit={upload}><label>Період<input type="month" name="period_key" required/></label><label className="accounting-portal-file-field">Файл<input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png" required/></label><button className="primary-action" disabled={busy}>{busy ? 'Завантаження…' : 'Надіслати документ'}</button></form>}
        {!data.documents.length ? <div className="accounting-portal-empty"><strong>Документів поки немає</strong><span>Надіслані або запитані файли з’являться тут.</span></div> : <div className="accounting-portal-document-list">{data.documents.map(document => <a className="accounting-portal-document" key={document.id} href={`/api/accounting-portal.php?client_id=${encodeURIComponent(clientId || data.client.client_id)}&document_id=${encodeURIComponent(document.id)}`}><span className={`accounting-portal-document-icon is-${fileBadge(document).toLowerCase()}`}>{fileBadge(document)}</span><span className="accounting-portal-document-copy"><strong>{document.name}</strong><small>{document.doc_type} · період {document.period_key} · {dateLabel(document.created_at)}{Number(document.version)>1?` · версія ${document.version}`:''}</small>{document.review_comment&&<small className="accounting-portal-review-comment">Коментар бухгалтера: {document.review_comment}</small>}</span><span className={`accounting-portal-status is-${document.status}`}>{documentStatus[document.status] || document.status}</span><b aria-hidden="true">↓</b></a>)}</div>}
      </section>

      <section className="accounting-panel accounting-portal-section">
        <div className="accounting-portal-section-heading"><div><span className="accounting-portal-section-icon">₴</span><div><h2>Рахунки</h2><p>Рахунки за бухгалтерські послуги.</p></div></div><b className="accounting-portal-count">{data.invoices.length}</b></div>
        {!!data.invoices.length && <div className="accounting-portal-request-tabs" role="tablist" aria-label="Фільтр рахунків"><button type="button" role="tab" aria-selected={invoiceView==='active'} className={invoiceView==='active'?'active':''} onClick={()=>setInvoiceView('active')}>Активні <span>{activeInvoices.length}</span></button><button type="button" role="tab" aria-selected={invoiceView==='archive'} className={invoiceView==='archive'?'active':''} onClick={()=>setInvoiceView('archive')}>Архівні <span>{archivedInvoices.length}</span></button></div>}
        {!data.invoices.length ? <div className="accounting-portal-empty"><strong>Рахунків поки немає</strong><span>Нові рахунки відобразяться тут.</span></div> : !visibleInvoices.length ? <div className="accounting-portal-empty"><strong>{invoiceView==='archive'?'Архів рахунків поки порожній':'Активних рахунків немає'}</strong><span>{invoiceView==='archive'?'Оплачені та скасовані рахунки з’являться тут.':'Нові активні рахунки з’являться тут.'}</span></div> : <div className="accounting-portal-list">{visibleInvoices.map(invoice => <article className="accounting-portal-invoice" id={`invoice-${invoice.id}`} key={invoice.id}>
          <div className="accounting-portal-item-heading"><div><small>РАХУНОК {invoice.invoice_no ? `№ ${invoice.invoice_no}` : ''}</small><h3>{Number(invoice.total).toLocaleString('uk-UA', { minimumFractionDigits: 2 })} грн</h3></div><span className={`accounting-portal-status is-${invoice.status}`}>{invoiceStatus[invoice.status] || invoice.status}</span></div>
          <div className="accounting-portal-invoice-meta"><span>Період <strong>{invoice.period_key}</strong></span><span>Виставлено <strong>{dateLabel(invoice.issued_on)}</strong></span><span>Сплатити до <strong>{dateLabel(invoice.due_on)}</strong></span></div>
          <details className="accounting-portal-issuer"><summary>Переглянути рахунок</summary><div>{(Array.isArray(parseSnapshot(invoice.items)) ? parseSnapshot(invoice.items) : []).map((item,index)=><span key={index}>{item.name || item.service || 'Послуга'} · {money(item.amount ?? item.total ?? item.price)} грн</span>)}<strong>Разом: {money(invoice.total)} грн</strong></div></details>
          {(() => { const issuer=parseSnapshot(invoice.issuer_snapshot); return issuer ? <details className="accounting-portal-issuer"><summary>Реквізити надавача послуг</summary><div><strong>{issuer.display_name}</strong><span>{issuer.legal_form} · ЄДРПОУ/РНОКПП {issuer.tax_identifier}</span><span>Адреса: {issuer.legal_address}</span>{issuer.postal_address&&<span>Поштова адреса: {issuer.postal_address}</span>}<span>IBAN: {issuer.iban}</span><span>Банк: {issuer.bank_name}{issuer.bank_details?` · ${issuer.bank_details}`:''}</span>{(issuer.contact_phone||issuer.contact_email)&&<span>Контакти: {[issuer.contact_phone,issuer.contact_email].filter(Boolean).join(' · ')}</span>}{issuer.tax_status&&<span>Податковий статус: {issuer.tax_status}</span>}{issuer.additional_info&&<span>{issuer.additional_info}</span>}</div></details> : <small className="accounting-portal-issuer-legacy">Реквізити не зберігалися в рахунках, створених до оновлення.</small>; })()}
          {invoice.payment_review_comment && <p className="accounting-portal-review-comment">Коментар бухгалтера щодо оплати: {invoice.payment_review_comment}</p>}
          {!data.manager_view && !['cancelled', 'paid'].includes(invoice.status) && (invoice.payment_notice_pending ? <p className="accounting-portal-payment-pending" role="status">Повідомлення про оплату надіслано й очікує перевірки бухгалтером.</p> : <form className="accounting-portal-form accounting-portal-payment" onSubmit={event => notice(invoice.id, event)}><label>Сума оплати · залишок {money(Number(invoice.total)-Number(invoice.paid_amount || 0))} грн<input name="amount" type="number" min="0.01" max={Math.max(0, Number(invoice.total)-Number(invoice.paid_amount || 0)).toFixed(2)} step="0.01" required placeholder="0,00"/></label><label>Коментар<input name="comment" placeholder="Необов’язково"/></label><label className="accounting-portal-proof">Підтвердження оплати<input name="proof" type="file" accept=".pdf,.jpg,.jpeg,.png"/><small>PDF, JPG або PNG · до 15 МБ</small></label><button className="secondary-action" disabled={busy}>{busy ? 'Надсилання…' : 'Повідомити бухгалтера'}</button></form>)}
        </article>)}</div>}
      </section>
      <section className="accounting-panel accounting-portal-section"><div className="accounting-portal-section-heading"><div><span className="accounting-portal-section-icon">↗</span><div><h2>Найближчі дії</h2><p>Лише строки, що стосуються вашої компанії.</p></div></div></div>{upcoming.length ? <div className="accounting-portal-nearest">{upcoming.map(action => <button key={`${action.kind}-${action.id}`} type="button" onClick={() => openAction(action)}><span>{dateLabel(action.due)}</span><strong>{action.title}</strong><b>→</b></button>)}</div> : <div className="accounting-portal-empty">Найближчих строків немає.</div>}</section>
      <section className="accounting-panel accounting-portal-section"><div className="accounting-portal-section-heading"><div><span className="accounting-portal-section-icon">✉</span><div><h2>Повідомлення</h2><p>Повідомлення бухгалтера для цієї компанії.</p></div></div></div>{data.messages?.length ? <div className="accounting-portal-messages">{data.messages.map(item => <article key={item.id}><strong>{item.title}</strong><time>{dateLabel(item.created_at)}</time><p>{item.message}</p></article>)}</div> : <div className="accounting-portal-empty">Повідомлень поки немає.</div>}</section>
    </>}
  </main>;
}
