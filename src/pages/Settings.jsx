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
  useEffect(() => {
    api("/api/profile.php").then((result) => {
      if (!result.ok) return;
      setUser(result.user);
      setName(result.user.name);
      setAvatar(result.user.avatar_url || "");
      setPhone(result.user.phone || "");
      setTelegramTag(result.user.telegram_tag || "");
      setTelegramChatId(result.user.telegram_chat_id || "");
      setTelegramEnabled(Boolean(Number(result.user.telegram_notifications_enabled)));
      setEmailEnabled(result.user.email_notifications_enabled === undefined ? true : Boolean(Number(result.user.email_notifications_enabled)));
      setTelegramConnectUrl(result.user.telegram_connect_url || "https://t.me/nexaflows_bot");
      setBio(result.user.bio || "");
    });
  }, []);
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
    setUser(result.user);
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
                <small>Отримуйте задачі та повідомлення прямо в Telegram.</small>
              </div>
              <label className="telegram-toggle">
                <input type="checkbox" checked={telegramEnabled} onChange={(event) => setTelegramEnabled(event.target.checked)} />
                <span />
                <b>{telegramEnabled ? "Увімкнено" : "Вимкнено"}</b>
              </label>
              <a className="outline-btn telegram-bot-link" href={telegramConnectUrl} target="_blank" rel="noreferrer">Підключити Telegram <Icon name="forward" /></a>
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
              <div className="subscription-plan">
                <strong>Стартовий план</strong>
                <span>Поточний план</span>
              </div>
              <div className="subscription-module-select">
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
              </div>
            </div>
            <div className="subscription-meta">
              <div>
                <span>Статус</span>
                <strong><Icon name="check" /> Активна</strong>
              </div>
              <div>
                <span>Оплата</span>
                <b>Поки без оплати</b>
              </div>
            </div>
            <button
              type="button"
              className="outline-btn subscription-button"
              disabled
            >
              Керувати підпискою
            </button>
          </section>
          <div className="settings-actions">
            <button className="primary-action" disabled={emailSaving}>Зберегти зміни</button>
          </div>
        </form>
      </div>
    </Layout>
  );
}
