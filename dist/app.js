const state = { mode: 'login' };
const form = document.querySelector('#auth-form');
const tabs = document.querySelectorAll('.tab');
const registerFields = document.querySelectorAll('.register-only');
const title = document.querySelector('#form-title');
const subtitle = document.querySelector('#form-subtitle');
const submitLabel = document.querySelector('#submit-label');
const notice = document.querySelector('#notice');
const password = document.querySelector('#password');

function setMode(mode) {
  state.mode = mode;
  tabs.forEach(tab => tab.classList.toggle('active', tab.dataset.mode === mode));
  registerFields.forEach(field => field.classList.toggle('hidden', mode !== 'register'));
  title.textContent = mode === 'login' ? 'З поверненням!' : 'Створіть акаунт';
  subtitle.textContent = mode === 'login' ? 'Увійдіть, щоб продовжити роботу.' : 'Почніть керувати клієнтами вже сьогодні.';
  submitLabel.textContent = mode === 'login' ? 'Увійти в систему' : 'Створити акаунт';
  notice.textContent = '';
  notice.className = 'notice';
  form.reset();
}

tabs.forEach(tab => tab.addEventListener('click', () => setMode(tab.dataset.mode)));
document.querySelector('#toggle-password').addEventListener('click', () => {
  password.type = password.type === 'password' ? 'text' : 'password';
});
document.querySelector('#forgot').addEventListener('click', event => {
  event.preventDefault();
  notice.className = 'notice success';
  notice.textContent = 'Для демо введіть будь-яку зареєстровану пошту — відновлення підключимо на наступному етапі.';
});

form.addEventListener('submit', async event => {
  event.preventDefault();
  const email = document.querySelector('#email').value.trim().toLowerCase();
  const passwordValue = password.value;
  if (state.mode === 'register') {
    const name = document.querySelector('#name').value.trim();
    if (!name) return showError('Будь ласка, введіть ваше ім’я.');
    if (!document.querySelector('#terms').checked) return showError('Підтвердіть умови використання.');
    const result = await request('/api/register.php', { name, email, password: passwordValue });
    if (!result.ok) return showError(result.message || 'Не вдалося створити акаунт.');
    window.location.href = 'dashboard';
    return;
  }

  const result = await request('/api/login.php', { email, password: passwordValue });
  if (!result.ok) return showError(result.message || 'Невірна пошта або пароль.');
  window.location.href = 'dashboard';
});

function showError(message) { notice.className = 'notice'; notice.textContent = message; }
function showSuccess(message) { notice.className = 'notice success'; notice.textContent = message; }

async function request(url, payload) {
  try {
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(payload)
    });
    return await response.json();
  } catch {
    return { ok: false, message: 'Сервер авторизації недоступний. Перевірте підключення до БД.' };
  }
}
