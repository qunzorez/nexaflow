const sidebar = document.querySelector('#sidebar');
const loginPath = window.location.pathname.startsWith('/dist/') ? '/dist/' : '/';
document.querySelector('#menu-btn')?.addEventListener('click', () => sidebar.classList.toggle('open'));
document.querySelector('#logout')?.addEventListener('click', async () => { await fetch('/api/logout.php', { method: 'POST', credentials: 'same-origin' }); window.location.href = loginPath; });

const notice = document.querySelector('#notice');
const users = [];
let data = { users: [], projects: [], members: [] };

function message(text, success = false) { notice.textContent = text; notice.className = `team-notice${success ? ' success' : ''}`; }
function roleName(role) { return { admin: 'Адміністратор', owner: 'Власник', manager: 'Менеджер', member: 'Учасник', viewer: 'Переглядач', user: 'Користувач' }[role] || role; }
function initials(name) { return name.split(' ').map(part => part[0]).slice(0, 2).join('').toUpperCase(); }

async function loadTeam() {
  const response = await fetch('/api/team.php', { credentials: 'same-origin' });
  const result = await response.json();
  if (!result.ok) { message(result.message); return; }
  data = result;
  document.querySelector('#user-count').textContent = result.users.length;
  const current = result.users.find(user => user.id === window.currentUserId);
  document.querySelector('#user-name').textContent = current?.name || 'Користувач';
  document.querySelector('#user-role').textContent = roleName(current?.role || 'user');
  document.querySelector('#user-avatar').textContent = initials(current?.name || 'К');
  document.querySelector('#member-list').innerHTML = result.users.map(user => `<div class="member-row"><span class="member-avatar">${initials(user.name)}</span><div class="member-info"><strong>${user.name}</strong><small>${user.email}</small></div><select class="system-role" data-user-id="${user.id}" ${result.permissions?.admin ? '' : 'disabled'}><option value="user" ${user.role === 'user' ? 'selected' : ''}>Користувач</option><option value="manager" ${user.role === 'manager' ? 'selected' : ''}>Менеджер</option><option value="admin" ${user.role === 'admin' ? 'selected' : ''}>Адміністратор</option></select></div>`).join('') || '<div class="empty-list">Користувачів ще немає.</div>';
  document.querySelectorAll('.system-role').forEach(select => select.addEventListener('change', async event => { const response = await fetch('/api/team.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'set_user_role', user_id: event.target.dataset.userId, role: event.target.value }) }); const result = await response.json(); message(result.message, result.ok); if (result.ok) loadTeam(); }));
  document.querySelector('#member').innerHTML = '<option value="">Оберіть користувача</option>' + result.users.map(user => `<option value="${user.id}">${user.name} — ${user.email}</option>`).join('');
  document.querySelector('#project').innerHTML = '<option value="">Оберіть проєкт</option>' + result.projects.map(project => `<option value="${project.id}">${project.name}</option>`).join('');
  document.querySelector('#invite-project').innerHTML = '<option value="">Оберіть проєкт</option>' + result.projects.map(project => `<option value="${project.id}">${project.name}</option>`).join('');
  document.querySelector('#assignments').innerHTML = result.members.map(member => `<div class="assignment-row"><span class="member-avatar">${initials(member.user_name || '?')}</span><div class="member-info"><strong>${member.user_name || member.user_id}</strong><small>${member.user_email || ''}</small></div><span class="assignment-project">${member.project_name}</span><span class="role-badge ${member.role}">${roleName(member.role)}</span></div>`).join('') || '<div class="empty-list">Ролі в проєктах ще не призначені.</div>';
}

fetch('/api/me.php', { credentials: 'same-origin' }).then(response => response.json()).then(result => { if (result.ok) window.currentUserId = result.user.id; loadTeam(); });
document.querySelector('#role-form').addEventListener('submit', async event => { event.preventDefault(); const projectId = document.querySelector('#project').value; const userId = document.querySelector('#member').value; const role = document.querySelector('#project-role').value; const response = await fetch('/api/team.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'assign_role', project_id: projectId, user_id: userId, role }) }); const result = await response.json(); message(result.message, result.ok); if (result.ok) loadTeam(); });
document.querySelector('#user-search')?.addEventListener('input', event => { const query = event.target.value.toLowerCase().trim(); document.querySelectorAll('.member-row').forEach(row => { row.hidden = query !== '' && !row.textContent.toLowerCase().includes(query); }); });
document.querySelector('#invite-form').addEventListener('submit', async event => { event.preventDefault(); const response = await fetch('/api/invite.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'send', email: document.querySelector('#invite-email').value.trim(), project_id: document.querySelector('#invite-project').value, role: document.querySelector('#invite-role').value }) }); const result = await response.json(); const notice = document.querySelector('#invite-notice'); notice.textContent = result.message; notice.className = `team-notice${result.ok ? ' success' : ''}`; if (result.ok) document.querySelector('#invite-form').reset(); });
document.querySelector('#new-project').addEventListener('click', async () => { const name = prompt('Назва нового проєкту:'); if (!name) return; const response = await fetch('/api/team.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'create_project', name }) }); const result = await response.json(); message(result.message, result.ok); if (result.ok) loadTeam(); });
