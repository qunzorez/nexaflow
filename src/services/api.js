export async function api(path, options = {}) {
  const response = await fetch(path, { credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...(options.headers || {}) }, ...options });
  const result = await response.json().catch(() => ({ ok: false, message: 'Некоректна відповідь сервера.' }));
  if (!response.ok && result.ok !== false) return { ok: false, message: `Помилка сервера (${response.status}).` };
  return result;
}

export const post = (path, payload) => api(path, { method: 'POST', body: JSON.stringify(payload) });
