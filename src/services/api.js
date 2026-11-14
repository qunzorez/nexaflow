import { apiUrl, runtimeConfig } from '../config';

export async function api(path, options = {}) {
  try {
    const isFormData = typeof FormData !== 'undefined' && options.body instanceof FormData;
    const response = await fetch(apiUrl(path), { cache: 'no-store', credentials: runtimeConfig.apiBaseUrl ? 'include' : 'same-origin', headers: { ...(!isFormData ? { 'Content-Type': 'application/json' } : {}), Accept: 'application/json', ...(options.headers || {}) }, ...options });
    const raw = await response.text();
    let result;
    try {
      result = raw ? JSON.parse(raw) : {};
    } catch {
      result = { ok: false, message: `Сервер повернув некоректну відповідь (${response.status}).` };
    }
    if (!result || typeof result !== 'object') result = { ok: false, message: `Сервер повернув некоректну відповідь (${response.status}).` };
    if (!response.ok && result.ok !== false) return { ok: false, message: `Помилка сервера (${response.status}).` };
    return result;
  } catch {
    return { ok: false, code: 'network_error', message: 'Не вдалося підключитися до сервера. Перевірте мережу та спробуйте ще раз.' };
  }
}

export const post = (path, payload) => api(path, { method: 'POST', body: typeof FormData !== 'undefined' && payload instanceof FormData ? payload : JSON.stringify(payload) });
