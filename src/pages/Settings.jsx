import Icon from '../components/Icon.jsx';
import { useEffect, useState } from "react";
import Layout from "../components/Layout.jsx";
import { api, post } from "../services/api.js";
import "./settings.css";
import "./settings-contact.css";
import "./settings-modern.css";

const roleNames = {
  admin: "Адміністратор",
  owner: "Власник",
  manager: "Менеджер",
  user: "Користувач",
  viewer: "Переглядач",
};
export default function Settings() {
  const [user, setUser] = useState(null);
  const [subscription, setSubscription] = useState(null);
  const [name, setName] = useState("");
  const [avatar, setAvatar] = useState("");
  const [phone, setPhone] = useState("");
  const [telegramTag, setTelegramTag] = useState("");
  const [telegramChatId, setTelegramChatId] = useState("");
  const [telegramConnectUrl, setTelegramConnectUrl] = useState("");
  const [telegramEnabled, setTelegramEnabled] = useState(false);
  const [emailEnabled, setEmailEnabled] = useState(false);
  const [emailSaving, setEmailSaving] = useState(false);
  const [bio, setBio] = useState("");
  const [clientModule, setClientModule] = useState(
    () => window.localStorage.getItem("nexaflow.client.module") || "accounting",
  );
  const [showPasswordFields, setShowPasswordFields] = useState(false);
  const [notice, setNotice] = useState("");
  const [error, setError] = useState("");
  const [payingPlan, setPayingPlan] = useState("");
  const [cancelingPlan, setCancelingPlan] = useState(false);
  const [verifyingPlan, setVerifyingPlan] = useState(false);
  const requestedPlan = new URLSearchParams(window.location.search).get("plan") || "";
  async function startPlanCheckout(planKey) {
    if (payingPlan) return;
    setPayingPlan(planKey);
    setError("");
    setNotice("");
    try {
      const result = await post("/api/liqpay.php", { action: "select_plan", plan: planKey });
      if (!result.ok) throw new Error(result.message || "Не вдалося обрати план.");
      if (result.plan_selected) {
        window.localStorage.removeItem("nexaflow.pending-plan");
        const profile = await api("/api/profile.php");
        if (profile.ok) {
          setSubscription(profile.subscription || null);
          setUser(current => current ? { ...current, subscription: profile.subscription || null } : current);
        }
        setNotice(result.message || "План підключено без списання коштів.");
        setPayingPlan("");
        return;
      }
      if (!result.checkout) throw new Error(result.message || "Не вдалося створити оплату.");
      window.localStorage.removeItem("nexaflow.pending-plan");
      const form = document.createElement("form");
      form.method = "POST";
      form.action = result.checkout.url;
      for (const [name, value] of Object.entries({ data: result.checkout.data, signature: result.checkout.signature })) {
        const input = document.createElement("input");
        input.type = "hidden";
        input.name = name;
        input.value = value;
        form.appendChild(input);
      }
      document.body.appendChild(form);
      form.submit();
    } catch (checkoutError) {
      setError(checkoutError.message || "Не вдалося перейти до оплати.");
      setPayingPlan("");
    }
  }
  async function cancelPlanSubscription() {
    if (!subscription?.key || cancelingPlan) return;
    setCancelingPlan(true);
    setError("");
    setNotice("");
    try {
      const result = await post("/api/liqpay.php", { action: "cancel", plan: subscription.key });
      if (!result.ok) throw new Error(result.message || "Не вдалося скасувати підписку.");
      const profile = await api("/api/profile.php");
      if (profile.ok) {
        setSubscription(profile.subscription || null);
        setUser(current => current ? { ...current, subscription: profile.subscription || null } : current);
      }
      setNotice(result.message);
    } catch (cancelError) {
      setError(cancelError.message || "Не вдалося скасувати підписку.");
    } finally {
      setCancelingPlan(false);
    }
  }
  async function verifyPlanPayment() {
    if (verifyingPlan) return;
    setVerifyingPlan(true);
    setError("");
    try {
      const result = await post("/api/liqpay.php", { action: "verify" });
      const profile = await api("/api/profile.php");
      if (profile.ok) {
        setSubscription(profile.subscription || null);
        setUser(current => current ? { ...current, subscription: profile.subscription || null } : current);
      }
      setNotice(result.message || "Перевірку платежу завершено.");
    } catch (verificationError) {
      setError(verificationError.message || "Не вдалося перевірити платіж.");
    } finally {
      setVerifyingPlan(false);
    }
  }
  useEffect(() => {
    api("/api/profile.php").then(async (result) => {
      if (!result.ok) return;
      const isPaymentReturn = new URLSearchParams(window.location.search).get("payment") === "return";
      let paymentCheck = null;
      if (isPaymentReturn && result.user?.role === "manager" && result.subscription?.billing?.status === "pending") {
        try {
          paymentCheck = await post("/api/liqpay.php", { action: "verify" });
          if (paymentCheck.status === "active" || paymentCheck.status === "failed") {
            const refreshed = await api("/api/profile.php");
            if (refreshed.ok) result = refreshed;
          }
        } catch (verificationError) {
          paymentCheck = { ok: false, message: verificationError.message };
        }
      }
      setUser({ ...result.user, subscription: result.subscription || null });
      setSubscription(result.subscription || null);
      setName(result.user.name);
      setAvatar(result.user.avatar_url || "");
      setPhone(result.user.phone || "");
      setTelegramTag(result.user.telegram_tag || "");
      setTelegramChatId(result.user.telegram_chat_id || "");
      setTelegramEnabled(Boolean(Number(result.user.telegram_notifications_enabled)));
      setEmailEnabled(result.user.email_notifications_enabled === undefined ? true : Boolean(Number(result.user.email_notifications_enabled)));
      setTelegramConnectUrl(result.user.telegram_connect_url || "https://t.me/nexaflows_bot");
      setBio(result.user.bio || "");
      const billing = result.subscription?.billing;
      if (isPaymentReturn) {
        if (billing?.status === "active") setNotice("Платіж підтверджено. Тариф «" + (result.subscription?.name || "") + "» активний.");
        else if (billing?.status === "failed") setNotice("Платіж не пройшов. Можете повторити спробу, обравши план нижче.");
        else setNotice(paymentCheck?.message || "Повернулися з LiqPay. Платіж ще обробляється; оновіть сторінку трохи пізніше.");
      } else if (billing?.status === "pending") setNotice("Очікуємо завершення оплати тарифу.");
    });
  }, []);
  useEffect(() => {
    if (user?.role !== "manager" || !["growth", "team", "business"].includes(requestedPlan)) return;
    startPlanCheckout(requestedPlan);
    window.history.replaceState({}, "", "/settings");
  }, [user?.role]);
  function chooseAvatar(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    if (!file.type.startsWith("image/"))
      return setError("Оберіть файл зображення.");
    if (file.size > 5 * 1024 * 1024)
      return setError("Фото має бути не більше 5 МБ.");
    const reader = new FileReader();
    reader.onload = () => setAvatar(String(reader.result));
    reader.readAsDataURL(file);
  }
  async function save(event) {
    event.preventDefault();
    setNotice("");
    setError("");
    const form = new FormData(event.currentTarget);
    const result = await post("/api/profile.php", {
      name,
      avatar_url: avatar,
      phone,
      telegram_tag: telegramTag,
      telegram_chat_id: telegramChatId,
      telegram_notifications_enabled: telegramEnabled,
      bio,
      current_password: form.get("current_password"),
      new_password: form.get("new_password"),
    });
    if (!result.ok) return setError(result.message);
    const nextSubscription = result.subscription || subscription;
    setUser({ ...result.user, subscription: nextSubscription });
    setSubscription(nextSubscription);
    setAvatar(result.user.avatar_url || "");
    setPhone(result.user.phone || "");
    setTelegramTag(result.user.telegram_tag || "");
    setTelegramChatId(result.user.telegram_chat_id || "");
    setTelegramEnabled(Boolean(Number(result.user.telegram_notifications_enabled)));
    setEmailEnabled(Boolean(Number(result.user.email_notifications_enabled)));
    setTelegramConnectUrl(result.user.telegram_connect_url || "https://t.me/nexaflows_bot");
    setBio(result.user.bio || "");
    window.dispatchEvent(
      new CustomEvent("nexaflow:profile-updated", { detail: result.user }),
    );
    setNotice(result.message);
    event.currentTarget.reset();
    window.localStorage.setItem("nexaflow.client.module", clientModule);
  }
  async function toggleEmailNotifications(event) {
    if (emailSaving) return;
    const enabled = event.target.checked;
    setEmailSaving(true);
    setError("");
    setNotice("");
    try {
      const result = await post("/api/email-preference.php", { enabled });
      if (!result.ok) throw new Error(result.message || "Не вдалося зберегти налаштування email.");
      setEmailEnabled(result.enabled === true);
      setNotice("Налаштування email-сповіщень збережено.");
    } catch (error) {
      setError(error.message || "Помилка з’єднання. Налаштування не збережено.");
    } finally {
      setEmailSaving(false);
    }
  }
  if (!user) return <div className="loading-screen">Завантаження профілю…</div>;
  const avatarView = avatar ? (
    <img src={avatar} alt="Фото профілю" />
  ) : (
    user.name.charAt(0).toUpperCase()
  );
  return (
    <Layout page="Налаштування профілю" user={user}>
      <div className="content settings-page">
        <section className="welcome-row">
          <div>
            <p className="eyebrow">ОБЛІКОВИЙ ЗАПИС</p>
            <h1>Налаштування профілю</h1>
            <p className="subtext">
              Керуйте особистими даними та доступом до NexaFlow.
            </p>
          </div>
        </section>
        {notice && <div className="team-notice success">{notice}</div>}
        {error && <div className="form-notice">{error}</div>}
        <form className="settings-grid-page" onSubmit={save}>
          <section className="panel settings-profile-card">
            <div className="settings-section-title">
              <div>
                <h2>
                  <span className="settings-section-icon"><Icon name="user" /></span>Профіль
                </h2>
                <p>Основна інформація користувача.</p>
              </div>
            </div>
            <div className="profile-photo-row">
              <span className="settings-avatar">{avatarView}</span>
              <div>
                <strong>{user.name}</strong>
                <small>{roleNames[user.role] || "Користувач"}</small>
                <label className="outline-btn photo-button">
                  Завантажити фото
                  <input type="file" accept="image/*" onChange={chooseAvatar} />
                </label>
              </div>
            </div>
            <label>
              Ім’я користувача
              <input
                value={name}
                onChange={(event) => setName(event.target.value)}
                required
              />
            </label>
            <label>
              Email
              <input value={user.email} disabled />
            </label>
          </section>
          <section className="panel settings-contact-card">
            <div className="settings-section-title">
              <div>
                <h2>
                  <span className="settings-section-icon"><Icon name="contact" /></span>Контактна
                  інформація
                </h2>
                <p>Додайте дані, за якими команда зможе з вами зв’язатися.</p>
              </div>
            </div>
            <div className="settings-contact-grid">
              <label>
                Номер телефону
                <input
                  name="phone"
                  type="tel"
                  value={phone}
                  onChange={(event) => setPhone(event.target.value)}
                  placeholder="+380 67 123 45 67"
                />
              </label>
              <label>
                Telegram-тег
                <input
                  name="telegram_tag"
                  value={telegramTag}
                  onChange={(event) => setTelegramTag(event.target.value)}
                  placeholder="@username"
                />
              </label>
            </div>
            <div className="telegram-connect-box">
              <div>
                <strong>Telegram-сповіщення</strong>
                <small>{subscription?.features.telegram ? "Отримуйте задачі та повідомлення прямо в Telegram." : "Telegram-сповіщення доступні починаючи з плану «Зростання»."}</small>
              </div>
              <label className="telegram-toggle">
                <input type="checkbox" checked={telegramEnabled} disabled={!subscription?.features.telegram} onChange={(event) => setTelegramEnabled(event.target.checked)} />
                <span />
                <b>{telegramEnabled ? "Увімкнено" : "Вимкнено"}</b>
              </label>
              <a className={`outline-btn telegram-bot-link${subscription?.features.telegram ? "" : " is-disabled"}`} href={subscription?.features.telegram ? telegramConnectUrl : undefined} aria-disabled={!subscription?.features.telegram} tabIndex={subscription?.features.telegram ? 0 : -1} target="_blank" rel="noreferrer">Підключити Telegram <Icon name="forward" /></a>
            </div>
            <div className="telegram-connect-box email-notification-box">
              <div>
                <strong>Отримувати email-сповіщення</strong>
                <small>Отримуйте листи про нагадування, призначення та зміни задач.</small>
              </div>
              <label className="telegram-toggle">
                <input type="checkbox" checked={emailEnabled} disabled={emailSaving} onChange={toggleEmailNotifications} />
                <span />
                <b>{emailSaving ? "Збереження…" : emailEnabled ? "Увімкнено" : "Вимкнено"}</b>
              </label>
            </div>
          </section>
          <section
            className={`panel password-card${showPasswordFields ? " is-open" : ""}`}
          >
            <div className="settings-section-title password-section-heading">
              <div>
                <h2>
                  <span className="settings-section-icon"><Icon name="security" /></span>Безпека
                </h2>
                <p>Змініть пароль, якщо потрібно.</p>
              </div>
              <button
                type="button"
                className={`settings-switch${showPasswordFields ? " is-on" : ""}`}
                aria-expanded={showPasswordFields}
                onClick={() => setShowPasswordFields((value) => !value)}
              >
                <span />
                {showPasswordFields ? "Сховати" : "Змінити пароль"}
              </button>
            </div>
            {showPasswordFields && (
              <>
                <label>
                  Поточний пароль
                  <input
                    type="password"
                    name="current_password"
                    autoComplete="current-password"
                    placeholder="Введіть поточний пароль"
                  />
                </label>
                <label>
                  Новий пароль
                  <input
                    type="password"
                    name="new_password"
                    minLength="8"
                    autoComplete="new-password"
                    placeholder="Мінімум 8 символів"
                  />
                </label>
                <small className="settings-hint">
                  Залиште поля порожніми, якщо не хочете змінювати пароль.
                </small>
              </>
            )}
          </section>
          <section className="panel account-info-card">
            <div className="settings-section-title">
              <div>
                <h2>
                  <span className="settings-section-icon"><Icon name="sparkle" /></span>Про акаунт
                </h2>
                <p>Ваш поточний статус у NexaFlow.</p>
              </div>
            </div>
            <div className="account-info-list">
              <div>
                <span>Роль</span>
                <strong>{roleNames[user.role] || "Користувач"}</strong>
              </div>
              <div>
                <span>Пошта</span>
                <strong className="account-email">{user.email}</strong>
              </div>
              <div>
                <span>Статус пошти</span>
                <strong className="account-verified"><Icon name="check" /> Підтверджено</strong>
              </div>
            </div>
          </section>
          <section className="panel subscription-card">
            <div className="subscription-main">
              <div className="settings-section-title">
                <div>
                  <h2>
                    <span className="settings-section-icon"><Icon name="subscription" /></span>Підписка
                  </h2>
                  <p>Керуйте доступом до можливостей NexaFlow.</p>
                </div>
              </div>
              <div className={`subscription-current-plan ${subscription?.billing?.status === "active" ? "is-paid" : ""}`}>
                <div className="subscription-current-icon"><Icon name="subscription" /></div>
                <div className="subscription-current-copy">
                  <span className="subscription-current-eyebrow">ВАШ ПОТОЧНИЙ ПЛАН</span>
                  <strong>{subscription?.name || "Старт"}</strong>
                  <small>{subscription?.price || "Безкоштовно"}{subscription?.billing?.status === "active" ? " · щомісячна підписка" : ""}</small>
                </div>
                <span className={`subscription-state ${subscription?.billing?.status === "active" ? "is-active" : ""}`}>
                  {subscription?.billing_enabled === false ? (subscription?.key === "start" ? "Безкоштовний план" : "Активний · без списань") : subscription?.billing?.status === "active" ? "Оплачено" : subscription?.billing?.status === "cancelled" ? "Скасовано · доступ активний" : subscription?.billing?.status === "failed" ? "Оплату відхилено" : subscription?.key === "start" ? "Безкоштовний план" : "Потрібна оплата"}
                </span>
              </div>
              {subscription?.pending_plan && <div className="subscription-pending-plan">
                <span><Icon name="subscription" /></span>
                <div><strong>Очікує активації: {subscription.pending_plan.name}</strong><small>{subscription.pending_plan.price} · тариф увімкнеться після остаточного підтвердження LiqPay</small></div>
                {user?.role === "manager" && <button type="button" onClick={verifyPlanPayment} disabled={verifyingPlan}>{verifyingPlan ? "Перевіряємо…" : "Перевірити оплату"}</button>}
              </div>}
              {user?.role === "admin" && <div className="subscription-module-select">
                <div className="subscription-module-label">
                  <span><Icon name="sparkle" /></span>
                  <div>
                    <strong>Модуль клієнтів</strong>
                    <small>Оберіть напрям для форми клієнта</small>
                  </div>
                </div>
                <label className="subscription-module-control">
                  <select
                    value={clientModule}
                    onChange={(event) => {
                      setClientModule(event.target.value);
                      window.localStorage.setItem(
                        "nexaflow.client.module",
                        event.target.value,
                      );
                    }}
                  >
                    <option value="accounting">Бухгалтерія</option>
                    <option value="dentistry">Стоматологія</option>
                    <option value="legal">Юридичні послуги</option>
                    <option value="real_estate">Нерухомість</option>
                  </select>
                  <span><Icon name="down" /></span>
                </label>
              </div>}
            </div>
            <div className="subscription-meta">
              <div>
                <span>{subscription?.billing?.status === "cancelled" ? "Доступ до" : "Наступне оновлення"}</span>
                <strong>{subscription?.billing_enabled === false ? (subscription?.key === "start" ? "Не застосовується" : "Щомісячне списання вимкнено") : ["active", "cancelled"].includes(subscription?.billing?.status) && subscription?.billing?.current_period_end ? new Date(String(subscription.billing.current_period_end).replace(" ", "T") + "Z").toLocaleDateString("uk-UA") : subscription?.billing?.status === "pending" ? "Після підтвердження платежу" : subscription?.billing?.status === "failed" ? "Спробуйте оплатити ще раз" : "Немає активних оплат"}</strong>
              </div>
              <div>
                <span>Спосіб оплати</span>
                <b>{subscription?.billing_enabled === false ? "Онлайн-оплата поки не підключена" : ["active", "cancelled"].includes(subscription?.billing?.status) ? "LiqPay · щомісяця" : subscription?.billing?.status === "pending" ? "LiqPay · очікується" : "Не потрібна"}</b>
              </div>
            </div>
            <div className="plan-usage-grid">
              {(subscription?.limits || []).map((item) => {
                const ratio = item.limit ? Math.min(100, Math.round(item.used / item.limit * 100)) : 0;
                return <div className="plan-usage" key={item.key}>
                  <div><span>{item.label}</span><strong>{item.used} / {item.limit ?? "∞"}</strong></div>
                  <i><b style={{ width: `${item.limit ? ratio : 0}%` }} /></i>
                </div>;
              })}
            </div>
            <div className="plan-feature-list">
              <span className={subscription?.features.calendar ? "enabled" : "locked"}>{subscription?.features.calendar ? "✓" : "×"} Календар</span>
              <span className={subscription?.features.analytics ? "enabled" : "locked"}>{subscription?.features.analytics ? "✓" : "×"} Аналітика</span>
              <span className={subscription?.features.services ? "enabled" : "locked"}>{subscription?.features.services ? "✓" : "×"} Створення послуг і пакетів</span>
              <span className={subscription?.features.telegram ? "enabled" : "locked"}>{subscription?.features.telegram ? "✓" : "×"} Telegram-сповіщення</span>
            </div>
            {!subscription?.billing_enabled && <div className="billing-disabled-note"><strong>Оплата тарифів поки не підключена</strong><span>Оберіть план — ми відкриємо його можливості без списання коштів.</span></div>}
            <div className="plan-comparison-heading"><strong>Порівняти плани</strong><span>Оберіть рівень для свого робочого простору</span></div>
            <div className="plan-comparison-grid">
              {(subscription?.available_plans || []).map((plan) => <article className={plan.key === subscription?.key ? "current" : ""} key={plan.key}>
                <header><strong>{plan.name}</strong><span>{plan.price}</span></header>
                <p>{plan.limits.participants === 49 ? "До 50 учасників загалом · до 5 менеджерів" : `${plan.limits.managers} менеджер · до ${plan.limits.participants} учасників`}</p>
                <small>{plan.limits.projects ?? "Без ліміту"} проєктів · {plan.limits.tasks_month ?? "Без ліміту"} задач/міс. · {plan.limits.clients ?? "Без ліміту"} клієнтів</small>
                <small>{plan.features.calendar ? "Календар" : "Без календаря"} · {plan.features.analytics ? "Аналітика" : "Без аналітики"} · {plan.features.services ? "Послуги й пакети" : "Без керування послугами"} · {plan.features.telegram ? "Telegram" : "Без Telegram"}{plan.limits.moderators ? " · до 5 модераторів" : ""}{plan.limits.project_leads ? " · до 5 керівників проєктів" : ""}</small>
                {plan.key === subscription?.key && <b className="current-plan-label">Ваш план</b>}
                {plan.key !== "start" && user?.role === "manager" && <button type="button" className="plan-select-button" disabled={Boolean(payingPlan) || (plan.key === subscription?.key && (subscription?.billing?.status === "active" || !subscription?.billing_enabled))} onClick={() => startPlanCheckout(plan.key)}>{payingPlan === plan.key ? (subscription?.billing_enabled ? "Готуємо оплату…" : "Підключаємо…") : plan.key === subscription?.key && (subscription?.billing?.status === "active" || !subscription?.billing_enabled) ? "Поточний план" : subscription?.billing_enabled ? "Обрати план" : "Підключити план"}</button>}
              </article>)}
            </div>
            {subscription?.billing_enabled && user?.role === "manager" && subscription?.billing?.status === "active" && <button type="button" className="outline-btn subscription-button" disabled={cancelingPlan} onClick={cancelPlanSubscription}>{cancelingPlan ? "Скасовуємо…" : "Скасувати автопродовження"}</button>}
            {user?.role !== "manager" && <small className="billing-manager-note">Змінювати тариф робочого простору може його менеджер.</small>}
          </section>
          <div className="settings-actions">
            <button className="primary-action" disabled={emailSaving}>Зберегти зміни</button>
          </div>
        </form>
      </div>
    </Layout>
  );
}
