const params = new URLSearchParams(window.location.search);
const token = params.get('token') || '';
const text = document.querySelector('#invite-text');
const form = document.querySelector('#accept-form');
const error = document.querySelector('#invite-error');
async function loadInvite() {
  const response = await fetch(`/api/invite.php?action=details&token=${encodeURIComponent(token)}`);
  const result = await response.json();
  if (!result.ok) { text.textContent = result.message; return; }
  text.textContent = `Вас запросили до проєкту «${result.invitation.project_name}» на email ${result.invitation.email}.`;
  form.classList.remove('hidden');
}
form.addEventListener('submit', async event => {
  event.preventDefault();
  const response = await fetch('/api/accept-invite.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ token, name: document.querySelector('#name').value.trim(), password: document.querySelector('#password').value }) });
  const result = await response.json();
  if (!result.ok) { error.textContent = result.message; return; }
  window.location.href = 'dashboard';
});
loadInvite();
