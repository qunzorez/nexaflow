const menuButton = document.querySelector('#menu-btn');
const sidebar = document.querySelector('#sidebar');
const modal = document.querySelector('#project-modal');
const form = document.querySelector('#project-form');
const loginPath = window.location.pathname.startsWith('/dist/') ? '/dist/' : '/';

menuButton?.addEventListener('click', () => sidebar.classList.toggle('open'));
document.querySelector('#logout')?.addEventListener('click', async () => {
  await fetch('/api/logout.php', { method: 'POST', credentials: 'same-origin' });
  window.location.href = loginPath;
});

const roleName = role => ({ admin: 'Адміністратор', owner: 'Власник', manager: 'Менеджер', user: 'Користувач', viewer: 'Переглядач' }[role] || role);
const initials = name => name.split(' ').map(part => part[0]).slice(0, 2).join('').toUpperCase();

function openProjectModal() {
  document.querySelector('#modal-error').textContent = '';
  modal.classList.remove('hidden');
  document.querySelector('#project-name').focus();
}
function closeProjectModal() { modal.classList.add('hidden'); form.reset(); }

function renderProjects(projects) {
  const list = document.querySelector('.empty-state');
  if (!list || !projects.length) return;
  list.className = 'project-list';
  list.innerHTML = projects.map(project => `<div class="project-row"><span class="project-icon">▣</span><div><strong>${project.name}</strong><small>${project.description || `Власник: ${project.owner_name}`}</small></div><span class="project-status">Активний</span></div>`).join('');
}

function renderProjectUsers(users, currentUserId) {
  const target = document.querySelector('#project-users');
  if (!users.length) { target.innerHTML = '<span class="loading">Додаткових користувачів ще немає.</span>'; return; }
  target.innerHTML = users.filter(user => user.id !== currentUserId).map(user => `<label class="project-user"><input type="checkbox" value="${user.id}" /><span class="member-avatar">${initials(user.name)}</span><span class="member-info"><strong>${user.name}</strong><small>${user.email}</small></span><select data-user-role="${user.id}"><option value="manager">Менеджер</option><option value="member" selected>Учасник</option><option value="viewer">Переглядач</option></select></label>`).join('');
}

async function loadDashboard() {
  try {
    const response = await fetch('/api/dashboard.php', { credentials: 'same-origin' });
    const result = await response.json();
    if (!result.ok) { window.location.href = loginPath; return; }
    const user = result.user;
    document.querySelector('.user-chip small').textContent = roleName(user.role);
    document.querySelectorAll('.stat-card>strong')[1].textContent = result.metrics.projects;
    document.querySelector('#user-name').textContent = user.name;
    document.querySelector('#welcome-name').textContent = user.name.split(' ')[0];
    document.querySelector('#user-avatar').textContent = user.name.charAt(0).toUpperCase();
    document.querySelectorAll('.admin-only, .manage-only').forEach(element => element.classList.toggle('hidden', !result.permissions.manage));
    document.body.classList.toggle('user-dashboard', !result.permissions.manage);
    if (!result.permissions.manage) {
      document.querySelector('.subtext').textContent = 'Ваші проєкти, задачі та доступи — в одному робочому просторі.';
      document.querySelector('.eyebrow').textContent = 'ВАШ РОБОЧИЙ ПРОСТІР';
      document.querySelector('.empty-state p').textContent = 'Коли менеджер додасть вас до проєкту, він з’явиться тут.';
    }
    renderProjects(result.projects);
    renderProjectUsers(result.permissions.manage ? (result.users || []) : [], user.id);
  } catch { window.location.href = loginPath; }
}

document.querySelectorAll('.primary-action, #create-project').forEach(button => button.addEventListener('click', openProjectModal));
document.querySelector('#close-project')?.addEventListener('click', closeProjectModal);
document.querySelector('#cancel-project')?.addEventListener('click', closeProjectModal);
modal?.addEventListener('click', event => { if (event.target === modal) closeProjectModal(); });

form?.addEventListener('submit', async event => {
  event.preventDefault();
  const error = document.querySelector('#modal-error');
  const members = [...document.querySelectorAll('#project-users input:checked')].map(input => ({ user_id: input.value, role: document.querySelector(`[data-user-role="${input.value}"]`).value }));
  const emails = document.querySelector('#project-invite-emails').value.split(/[\s,;]+/).map(email => email.trim().toLowerCase()).filter(Boolean);
  const response = await fetch('/api/team.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'create_project', name: document.querySelector('#project-name').value.trim(), description: document.querySelector('#project-description').value.trim(), members, invite_emails: emails, invite_role: document.querySelector('#project-invite-role').value }) });
  const result = await response.json();
  if (!result.ok) { error.textContent = result.message; return; }
  closeProjectModal();
  window.location.reload();
});

loadDashboard();
