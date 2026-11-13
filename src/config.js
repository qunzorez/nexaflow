// Builds always call the API of the installation serving the page.
const rawApiBaseUrl = '';

export const runtimeConfig = {
  environment: import.meta.env.VITE_APP_ENV || 'local',
  apiBaseUrl: rawApiBaseUrl.replace(/\/$/, ''),
};

export function apiUrl(path) {
  const normalizedPath = path.startsWith('/') ? path : `/${path}`;
  return `${runtimeConfig.apiBaseUrl}${normalizedPath}`;
}
