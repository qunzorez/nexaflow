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
  const [bio, setBio] = useState("");
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
      bio,
      current_password: form.get("current_password"),
      new_password: form.get("new_password"),
    });
    if (!result.ok) return setError(result.message);
    setUser(result.user);
    setAvatar(result.user.avatar_url || "");
    setPhone(result.user.phone || "");
    setTelegramTag(result.user.telegram_tag || "");
    setBio(result.user.bio || "");
    window.dispatchEvent(
      new CustomEvent("nexaflow:profile-updated", { detail: result.user }),
    );
    setNotice(result.message);
    event.currentTarget.reset();
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
                <h2><span className="settings-section-icon">◎</span>Профіль</h2>
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
                <h2><span className="settings-section-icon">⌁</span>Контактна інформація</h2>
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
            <label>
              Про себе
              <textarea
                name="bio"
                value={bio}
                onChange={(event) => setBio(event.target.value)}
                maxLength="1000"
                rows="4"
                placeholder="Коротко розкажіть про себе, свою роль або напрям роботи"
              />
            </label>
          </section>
          <section className={`panel password-card${showPasswordFields ? " is-open" : ""}`}>
            <div className="settings-section-title password-section-heading">
              <div>
                <h2><span className="settings-section-icon">▣</span>Безпека</h2>
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
            {showPasswordFields && <>
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
            </>}
          </section>
          <div className="settings-actions">
            <button className="primary-action">Зберегти зміни</button>
          </div>
        </form>
      </div>
    </Layout>
  );
}
