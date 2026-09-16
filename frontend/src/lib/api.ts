import axios from 'axios';

const businessStorageKey = 'hospitality.activeBusinessId';

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? '/api/v1',
  headers: { Accept: 'application/json' },
  withCredentials: true,
});

api.interceptors.request.use((config) => {
  const businessId = localStorage.getItem(businessStorageKey);
  if (businessId) config.headers['X-Business-Id'] = businessId;
  return config;
});

export function setActiveBusinessId(businessId: string) {
  localStorage.setItem(businessStorageKey, businessId);
}

export function getActiveBusinessId() {
  return localStorage.getItem(businessStorageKey);
}
