// SmartBulk — History API client + types

import { api } from './api';

export type BatchKind = 'bulk_edit' | 'ai_batch' | string;
export type BatchStatus = 'pending' | 'running' | 'success' | 'partial' | 'failed' | 'undone';

export interface BatchSummary {
  id_batch: number;
  id_shop: number;
  kind: BatchKind;
  status: BatchStatus;
  mode: string;
  products_matched: number;
  products_changed: number;
  products_failed: number;
  started_at: string;
  finished_at: string | null;
  employee_id: number | null;
  employee_name: string;
  summary: string;
  scope_summary: string;
  has_undoable_changes: boolean;
}

export interface BulkLogRow {
  id_log: number;
  id_product: number;
  id_lang: number | null;
  field: string;
  old: string;
  new: string;
  status: 'changed' | 'unchanged' | 'failed' | 'skipped';
  error: string | null;
  changed_at: string;
}

export interface AiRunRow {
  id_run: number;
  id_product: number;
  product_name: string;
  id_lang: number | null;
  status: 'pending' | 'success' | 'failed' | 'rejected' | 'accepted';
  output: string;
  tokens_in: number | null;
  tokens_out: number | null;
  cost_usd: number | null;
  latency_ms: number | null;
  error: string | null;
  created_at: string;
}

export interface BatchDetail extends BatchSummary {
  action_snapshot: Record<string, unknown> | null;
  segment_snapshot: Record<string, unknown> | null;
  logs?: BulkLogRow[];
  runs?: AiRunRow[];
}

const BASE = '/modules/smartbulk/api/history';

export const historyApi = {
  list: (params: { kind?: 'all' | 'bulk' | 'ai'; status?: string; limit?: number; offset?: number } = {}) => {
    const qs = new URLSearchParams();
    if (params.kind)              qs.set('kind',   params.kind);
    if (params.status)            qs.set('status', params.status);
    if (params.limit  !== undefined) qs.set('limit',  String(params.limit));
    if (params.offset !== undefined) qs.set('offset', String(params.offset));
    return api
      .get<{ ok: boolean; items: BatchSummary[]; total: number; error?: string }>(`${BASE}${qs.toString() ? `?${qs}` : ''}`)
      .then((r) => {
        if (!r.ok) throw new Error(r.error || 'List failed');
        return { items: r.items, total: r.total };
      });
  },

  detail: (id: number) =>
    api
      .get<{ ok: boolean; batch: BatchDetail; error?: string }>(`${BASE}/${id}`)
      .then((r) => {
        if (!r.ok) throw new Error(r.error || 'Detail failed');
        return r.batch;
      }),
};

export const STATUS_TONE: Record<BatchStatus, 'success' | 'warning' | 'destructive' | 'muted' | 'primary'> = {
  pending: 'muted',
  running: 'primary',
  success: 'success',
  partial: 'warning',
  failed:  'destructive',
  undone:  'muted',
};
