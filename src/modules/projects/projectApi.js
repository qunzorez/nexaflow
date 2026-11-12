import { post } from '../../services/api.js';

export function createProject(payload) {
  return post('/api/team.php', { action: 'create_project', ...payload });
}
