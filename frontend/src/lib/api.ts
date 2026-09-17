import axios from 'axios';

const businessStorageKey = 'hospitality.activeBusinessId';
const locationStorageKey = 'hospitality.activeLocationId';

export const api = axios.create({ baseURL: import.meta.env.VITE_API_URL ?? '/api/v1', headers: { Accept: 'application/json' }, withCredentials: true });
export const csrf = axios.create({ baseURL: import.meta.env.VITE_BACKEND_URL ?? '', withCredentials: true, headers: { Accept: 'application/json' } });

api.interceptors.request.use(config => {
  const businessId = localStorage.getItem(businessStorageKey);
  if (businessId) config.headers['X-Business-Id'] = businessId;
  return config;
});

export function setActiveBusinessId(id: string) { localStorage.setItem(businessStorageKey, id); }
export function getActiveBusinessId() { return localStorage.getItem(businessStorageKey); }
export function setActiveLocationId(id: string) { localStorage.setItem(locationStorageKey, id); }
export function getActiveLocationId() { return localStorage.getItem(locationStorageKey); }
export function clearWorkspaceContext() { localStorage.removeItem(businessStorageKey); localStorage.removeItem(locationStorageKey); }
export async function initializeCsrf() { await csrf.get('/sanctum/csrf-cookie'); }
