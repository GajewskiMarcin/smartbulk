// SmartBulk — AI API client + types

import { api } from './api';
import type { Prompt, Provider, TaskType } from './prompts';

export interface ProductRef {
  id_product: number;
  name: string;
  reference: string;
  price: number;
}

export interface AiRun {
  id_run: number;
  status: 'pending' | 'accepted' | 'rejected' | 'failed';
  output: string;
  tokens_in: number;
  tokens_out: number;
  cost_usd: number;
  latency_ms: number;
  model: string;
  provider: Provider;
  prompt: {
    id_prompt: number;
    id_prompt_version: number;
    task_type: TaskType;
    name: string;
  };
  product: {
    id_product: number;
    id_lang: number;
    name: string;
  };
  rendered: {
    system: string;
    user: string;
  };
}

export type AppliedResult =
  | { field: string; lang: number; old: string; new: string }
  | { task: 'alt_text'; images_updated: number; lang: number; new: string }
  | { task: 'tagging'; tags_set: number; lang: number; replaced: boolean; value: string[] };

export interface AcceptedRun {
  id_run: number;
  status: 'accepted';
  applied: AppliedResult;
}

interface OkWrap<T> {
  ok: boolean;
  error?: string;
  run?: T;
}

interface ProductSearchResponse {
  ok: boolean;
  products: ProductRef[];
}

interface TestConnectionResponse {
  ok: boolean;
  provider: Provider;
  model: string;
  response: string;
  latency_ms: number;
  cost_usd: number;
  error?: string;
}

const AI_BASE = '/modules/smartbulk/api/ai';
const PROD_BASE = '/modules/smartbulk/api/products';

export const aiApi = {
  generate: (input: {
    id_prompt: number;
    id_product: number;
    id_lang?: number;
    id_version?: number;
    target_field?: string;
    target_lang?: number;
  }) =>
    api.post<OkWrap<AiRun>>(`${AI_BASE}/generate`, input).then((r) => {
      if (!r.run) throw new Error(r.error || 'No run returned');
      return r.run;
    }),

  accept: (idRun: number) =>
    api.post<OkWrap<AcceptedRun>>(`${AI_BASE}/runs/${idRun}/accept`, {}).then((r) => {
      if (!r.run) throw new Error(r.error || 'Accept failed');
      return r.run;
    }),

  reject: (idRun: number) =>
    api.post<OkWrap<{ id_run: number; status: 'rejected' }>>(`${AI_BASE}/runs/${idRun}/reject`, {}).then((r) => {
      if (!r.run) throw new Error(r.error || 'Reject failed');
      return r.run;
    }),

  testConnection: (provider: Provider) =>
    api.get<TestConnectionResponse>(`${AI_BASE}/test-connection?provider=${provider}`),

  estimate: (input: { id_prompt: number; product_count: number; sample_product_id?: number; id_version?: number }) =>
    api
      .post<{
        ok: boolean;
        estimate: {
          model: string;
          input_tokens: number;
          output_tokens: number;
          cost_per_run_usd: number;
          total_cost_usd: number;
          product_count: number;
          sample_product_id: number | null;
        };
        error?: string;
      }>(`${AI_BASE}/estimate`, input)
      .then((r) => {
        if (!r.ok || !r.estimate) throw new Error(r.error || 'Estimate failed');
        return r.estimate;
      }),

  // ---- Batch ----
  createBatch: (input: {
    id_prompt: number;
    id_version?: number;
    id_lang?: number;
    product_ids?: number[];
    filter_spec?: { filters: unknown[] };
    target_field?: string;
    target_lang?: number;
  }) =>
    api
      .post<{ ok: boolean; batch: BatchStatus; error?: string }>(`${AI_BASE}/batch`, input)
      .then((r) => {
        if (!r.ok || !r.batch) throw new Error(r.error || 'Could not create batch');
        return r.batch;
      }),

  getBatch: (idBatch: number, includeRuns = false) =>
    api
      .get<{ ok: boolean; batch: BatchStatus; error?: string }>(
        `${AI_BASE}/batch/${idBatch}${includeRuns ? '?include_runs=1' : ''}`
      )
      .then((r) => {
        if (!r.ok || !r.batch) throw new Error(r.error || 'Could not fetch batch');
        return r.batch;
      }),

  resumeBatch: (idBatch: number) =>
    api
      .post<{ ok: boolean; batch: BatchStatus; error?: string }>(`${AI_BASE}/batch/${idBatch}/resume`, {})
      .then((r) => {
        if (!r.ok || !r.batch) throw new Error(r.error || 'Resume failed');
        return r.batch;
      }),

  processNext: (idBatch: number, limit = 5) =>
    api
      .post<{ ok: boolean; batch: BatchStatus & { runs_this_turn?: BatchRun[] }; error?: string }>(
        `${AI_BASE}/batch/${idBatch}/process-next?limit=${limit}`,
        {}
      )
      .then((r) => {
        if (!r.ok || !r.batch) throw new Error(r.error || 'Processing failed');
        return r.batch;
      }),

  acceptAllBatch: (idBatch: number, limit = 50) =>
    api
      .post<{ ok: boolean; result: { accepted: number; failed: number; errors: Record<number, string>; remaining: number }; error?: string }>(
        `${AI_BASE}/batch/${idBatch}/accept-all?limit=${limit}`,
        {}
      )
      .then((r) => {
        if (!r.ok || !r.result) throw new Error(r.error || 'Accept-all failed');
        return r.result;
      }),

  rejectAllBatch: (idBatch: number, limit = 100) =>
    api
      .post<{ ok: boolean; result: { rejected: number; remaining: number }; error?: string }>(
        `${AI_BASE}/batch/${idBatch}/reject-all?limit=${limit}`,
        {}
      )
      .then((r) => {
        if (!r.ok || !r.result) throw new Error(r.error || 'Reject-all failed');
        return r.result;
      }),
};

export interface BatchRun {
  id_run: number;
  status: 'pending' | 'accepted' | 'rejected' | 'failed';
  id_product: number;
  id_lang: number;
  output: string;
  tokens_in: number;
  tokens_out: number;
  cost_usd: number;
  latency_ms: number;
  error: string | null;
  product_name: string;
  created_at: string;
}

export interface BatchStatus {
  id_batch: number;
  status: 'pending' | 'running' | 'success' | 'partial' | 'failed' | 'undone';
  task_type: TaskType;
  prompt: { id_prompt: number; id_prompt_version: number; name: string };
  id_lang: number;
  total: number;
  done: number;
  remaining: number;
  counts: { pending: number; accepted: number; rejected: number; failed: number };
  total_cost_usd: number;
  started_at: string;
  finished_at: string | null;
  runs?: BatchRun[];
  paused?: boolean;        // set by process-next when a budget/rate limit blocks the batch
  pause_reason?: string;
}

export const productsApi = {
  search: (q: string, limit = 10) =>
    api
      .get<ProductSearchResponse>(`${PROD_BASE}/search?q=${encodeURIComponent(q)}&limit=${limit}`)
      .then((r) => r.products),
};

export function taskTypeSupportsAutoApply(t: TaskType): boolean {
  // Now covers all task types — translate/alt_text/tagging/seo_rewrite got auto-apply handlers.
  return [
    'meta_title', 'meta_description', 'short_desc', 'long_desc',
    'translate', 'alt_text', 'tagging', 'seo_rewrite',
  ].includes(t);
}

/** Target fields selectable for translate + seo_rewrite (where user chooses which product field to (re)write). */
export const TARGET_FIELDS: { value: string; label: string }[] = [
  { value: 'name',              label: 'Product name' },
  { value: 'meta_title',        label: 'Meta title' },
  { value: 'meta_description',  label: 'Meta description' },
  { value: 'description_short', label: 'Short description' },
  { value: 'description',       label: 'Long description' },
];

/** Task types that need a target field selector in the UI. */
export function taskTypeNeedsTargetField(t: TaskType): boolean {
  return t === 'translate' || t === 'seo_rewrite';
}

/** Task types that need a target language selector (different from source id_lang). */
export function taskTypeNeedsTargetLang(t: TaskType): boolean {
  return t === 'translate';
}

export function sortPromptsForTask(prompts: Prompt[], taskType: TaskType): Prompt[] {
  return prompts
    .filter((p) => p.task_type === taskType)
    .sort((a, b) => a.name.localeCompare(b.name));
}
