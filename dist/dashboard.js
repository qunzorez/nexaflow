const menuButton = document.querySelector('#menu-btn');
const sidebar = document.querySelector('#sidebar');
const logout = document.querySelector('#logout');

menuButton?.addEventListener('click', () => sidebar.classList.toggle('open'));
logout?.addEventListener('click', async () => {
  await fetch('/api/logout.php', { method: 'POST', credentials: 'same-origin' });
  window.location.href = 'index.html';
});

async function loadUser() {
  try {
    const response = await fetch('/api/me.php', { credentials: 'same-origin' });
    const result = await response.json();
    if (!result.ok) return;
    const name = result.user.name || 'командо';
    document.querySelector('#user-name').textContent = name;
    document.querySelector('#welcome-name').textContent = name.split(' ')[0];
    document.querySelector('#user-avatar').textContent = name.charAt(0).toUpperCase();
  } catch {
    // Dashboard remains usable as a visual foundation if the API is temporarily unavailable.
  }
}

loadUser();
