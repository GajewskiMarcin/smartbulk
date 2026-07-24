// SmartBulk — Schedules API client + types

import { api } from './api';

export type JobType = 'bulk_edit' | 'ai_batch';

export interface Schedule {
  id_schedule: number;
  name: string;
  id_shop: number;
  job_type: JobType;
  job_payload: Record<string, unknown> | null;
  cron_expression: string;
  is_active: boolean;
  last_run_at: string | null;
  next_run_at: string | null;
  created_by: number | null;
  employee_name: string;
  created_at: string;
  human_schedule: string;
}

export interface RunResult {
  id_schedule: number;
  name: string;
  job_type: JobType;
  manual: boolean;
  started_at: string;
  status: 'pending' | 'started' | 'failed';
  id_batch?: number | null;
  products?: number | null;
  error?: string;
}

const BASE = '/modules/smartbulk/api/schedules';

export const schedulesApi = {
  list: () =>
    api.get<{ ok: boolean; schedules: Schedule[] }>(BASE).then((r) => r.schedules),

  create: (input: {
    name: string;
    job_type: JobType;
    job_payload: Record<string, unknown>;
    cron_expression: string;
    is_active?: boolean;
  }) =>
    api
      .post<{ ok: boolean; schedule: Schedule; error?: string }>(BASE, input)
      .then((r) => {
        if (!r.ok || !r.schedule) throw new Error(r.error || 'Create failed');
        return r.schedule;
      }),

  update: (id: number, patch: Partial<{
    name: string;
    job_payload: Record<string, unknown>;
    cron_expression: string;
    is_active: boolean;
  }>) =>
    api
      .patch<{ ok: boolean; schedule: Schedule; error?: string }>(`${BASE}/${id}`, patch)
      .then((r) => {
        if (!r.ok || !r.schedule) throw new Error(r.error || 'Update failed');
        return r.schedule;
      }),

  delete: (id: number) => api.del<{ ok: boolean }>(`${BASE}/${id}`),

  runNow: (id: number) =>
    api
      .post<{ ok: boolean; result: RunResult; error?: string }>(`${BASE}/${id}/run-now`, {})
      .then((r) => {
        if (!r.ok || !r.result) throw new Error(r.error || 'Run failed');
        return r.result;
      }),
};

// ---------- Cron presets used by the Add/Edit form ----------

export type CronPreset =
  | { kind: 'hourly';  minute: number }
  | { kind: 'daily';   hour: number; minute: number }
  | { kind: 'weekly';  dow: number;  hour: number; minute: number }
  | { kind: 'monthly'; dom: number;  hour: number; minute: number }
  | { kind: 'custom';  expression: string };

export function presetToCron(p: CronPreset): string {
  const z = (n: number) => String(n);
  switch (p.kind) {
    case 'hourly':  return `${z(p.minute)} * * * *`;
    case 'daily':   return `${z(p.minute)} ${z(p.hour)} * * *`;
    case 'weekly':  return `${z(p.minute)} ${z(p.hour)} * * ${z(p.dow)}`;
    case 'monthly': return `${z(p.minute)} ${z(p.hour)} ${z(p.dom)} * *`;
    case 'custom':  return p.expression;
  }
}

/** Best-effort reverse parse for the form to round-trip a saved cron back to the preset UI. */
export function cronToPreset(expr: string): CronPreset {
  const f = expr.trim().split(/\s+/);
  if (f.length !== 5) return { kind: 'custom', expression: expr };
  const [m, h, dom, mon, dow] = f;
  const isDigit = (s: string) => /^\d+$/.test(s);

  if (mon === '*' && dom === '*' && dow === '*' && h === '*' && isDigit(m)) {
    return { kind: 'hourly', minute: +m };
  }
  if (mon === '*' && dom === '*' && dow === '*' && isDigit(h) && isDigit(m)) {
    return { kind: 'daily', hour: +h, minute: +m };
  }
  if (mon === '*' && dom === '*' && dow !== '*' && isDigit(h) && isDigit(m) && isDigit(dow)) {
    return { kind: 'weekly', dow: +dow, hour: +h, minute: +m };
  }
  if (mon === '*' && dow === '*' && dom !== '*' && isDigit(h) && isDigit(m) && isDigit(dom)) {
    return { kind: 'monthly', dom: +dom, hour: +h, minute: +m };
  }
  return { kind: 'custom', expression: expr };
}

export const DAYS_OF_WEEK = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
