// SmartBulk — thin fetch wrapper around /smartbulk/api/*
//
// Admin-dev URLs carry a _token query param added by PS for CSRF. We read it off
// the current location so API calls inherit the session automatically.

function withAdminToken(path: string): string {
  const base = window.location.pathname;
  // PrestaShop's admin folder takes two forms:
  //   1. Production install — "admin" + random hash, no dash: /admin0882fzfi8ee8acyer8j/
  //   2. Dev install        — "admin-dev" (or similar with dash):   /admin-dev/
  // Match both. Anchored at start; first path segment only.
  const adminPrefix = base.match(/^\/admin[-_a-z0-9]+/i)?.[0] ?? '';
  const token = new URLSearchParams(window.location.search).get('_token') ?? '';
  const url = new URL(adminPrefix + path, window.location.origin);
  if (token) url.searchParams.set('_token', token);
  return url.toString();
}

export interface ApiError extends Error {
  status: number;
  payload?: unknown;
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const res = await fetch(withAdminToken(path), {
    credentials: 'include',
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      ...(init.headers ?? {}),
    },
    ...init,
  });

  let payload: unknown = null;
  try {
    payload = await res.json();
  } catch {
    // ignore — not JSON
  }

  if (!res.ok) {
    const err = new Error(
      (payload as { error?: string })?.error ?? `HTTP ${res.status}`
    ) as ApiError;
    err.status = res.status;
    err.payload = payload;
    throw err;
  }
  return payload as T;
}

export const api = {
  get:    <T,>(path: string) => request<T>(path, { method: 'GET' }),
  post:   <T,>(path: string, body: unknown) =>
    request<T>(path, { method: 'POST', body: JSON.stringify(body) }),
  patch:  <T,>(path: string, body: unknown) =>
    request<T>(path, { method: 'PATCH', body: JSON.stringify(body) }),
  del:    <T,>(path: string) => request<T>(path, { method: 'DELETE' }),
};
