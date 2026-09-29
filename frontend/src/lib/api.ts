import axios from 'axios';

const businessStorageKey = 'hospitality.activeBusinessId';
const locationStorageKey = 'hospitality.activeLocationId';
export const authExpiredEvent = 'hospitality:auth-expired';
export const authExpiredStorageKey = 'hospitality.sessionExpired';

export const api = axios.create({ baseURL: import.meta.env.VITE_API_URL ?? '/api/v1', headers: { Accept: 'application/json' }, withCredentials: true });
export const csrf = axios.create({ baseURL: import.meta.env.VITE_BACKEND_URL ?? '', withCredentials: true, headers: { Accept: 'application/json' } });

api.interceptors.request.use(config => {
  const businessId = localStorage.getItem(businessStorageKey);
  if (businessId) config.headers['X-Business-Id'] = businessId;
  return config;
});

api.interceptors.response.use(
  response => response,
  async error => {
    const status = error?.response?.status;
    const requestConfig = error?.config as (
      NonNullable<typeof error.config> & { __csrfRetried?: boolean }
    ) | undefined;

    if (status === 419 && requestConfig && !requestConfig.__csrfRetried) {
      requestConfig.__csrfRetried = true;

      try {
        await initializeCsrf();
        return await api.request(requestConfig);
      } catch {
        // Fall through to the original request error. A single retry avoids loops
        // while recovering the normal stale-CSRF-cookie case.
      }
    }

    if (status === 401) {
      clearWorkspaceContext();
      if (typeof window !== 'undefined') {
        window.sessionStorage.setItem(authExpiredStorageKey, '1');
        window.dispatchEvent(new Event(authExpiredEvent));
      }
    }

    return Promise.reject(error);
  },
);

export function setActiveBusinessId(id: string) { localStorage.setItem(businessStorageKey, id); }
export function getActiveBusinessId() { return localStorage.getItem(businessStorageKey); }
export function setActiveLocationId(id: string) { localStorage.setItem(locationStorageKey, id); }
export function getActiveLocationId() { return localStorage.getItem(locationStorageKey); }
export function clearWorkspaceContext() { localStorage.removeItem(businessStorageKey); localStorage.removeItem(locationStorageKey); }
export async function initializeCsrf() { await csrf.get('/sanctum/csrf-cookie'); }
