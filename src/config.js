const rawApiBaseUrl = String(import.meta.env.VITE_API_BASE_URL || '').trim();

export const runtimeConfig = {
  environment: import.meta.env.VITE_APP_ENV || 'local',
  apiBaseUrl: rawApiBaseUrl.replace(/\/$/, ''),
};

export function apiUrl(path) {
  const normalizedPath = path.startsWith('/') ? path : `/${path}`;
  return `${runtimeConfig.apiBaseUrl}${normalizedPath}`;
}
