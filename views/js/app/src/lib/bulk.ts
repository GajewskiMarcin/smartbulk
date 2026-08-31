// SmartBulk — Bulk Editor API client + types

import { api } from './api';

export type FieldType = 'text' | 'numeric' | 'int' | 'bool' | 'enum' | 'feature' | 'tags';
export type Operator =
  | 'set'
  | 'append'
  | 'prepend'
  | 'replace'
  | 'math'
  | 'clear'
  | 'copy_from'
  | 'generate_from_name'
  | 'generate_from_short_desc'
  | 'ai_generate'
  | 'add'
  | 'remove'
  | 'skip';
export type MathOp = '+' | '-' | '*' | '/' | '+%' | '-%';
export type LookupKind = 'brands' | 'suppliers' | 'categories' | 'tax_rules';

export interface FieldDefinition {
  id: string;
  label: string;
  group: 'basic' | 'content' | 'seo' | 'pricing' | 'stock' | 'codes' | 'shipping' | 'features';
  type: FieldType;
  lang: boolean;
  operators: Operator[];
  options?: { value: string; label: string }[];
  unit?: 'currency' | 'kg' | 'cm';
  html?: boolean;
  lookup?: LookupKind;
  min_value?: number;
  char_limit?: number;
  char_limit_warn?: number;
  format_hint?: string;
  pattern?: string;
  warn_on_false?: string;
  warn_on_value?: Record<string, string>;
  warn_same_value_on_batch?: boolean;
  warn_if_greater_than?: { field: string; message: string };
}

export const FIELD_GROUPS: Record<FieldDefinition['group'], string> = {
  basic:    'Basic',
  content:  'Descriptions',
  seo:      'SEO',
  pricing:  'Pricing',
  stock:    'Stock',
  codes:    'Product codes',
  shipping: 'Shipping',
  features: 'Features',
};

export interface BulkAction {
  field: string;
  operator: Operator;
  value?: string | number | boolean;
  find?: string;
  replace?: string;
  math_op?: MathOp;
  math_value?: number;
  id_lang?: number | null;
  prompt_id?: number;
  prompt_version_id?: number | null;
  source_field?: string;       // used by copy_from operator
  // Feature action (_feature virtual field): pick a feature + either an existing value
  // from the library (id_feature_value) or a free-text custom value.
  id_feature?: number;
  id_feature_value?: number | null;
  custom_value?: string;
  // Tags action (_tags virtual field): free-text tag names to add / remove.
  // Unused for the 'clear' operator (which removes all tags).
  tags?: string[];
}

export type DiffFlag =
  | 'truncated'
  | 'pattern_mismatch'
  | 'below_min_clamped'
  | 'empty_old'
  | 'conflict_gt'
  | 'same_value_batch'
  | 'fallback_to_name'
  | 'empty_source'
  | 'ai_generate'
  | 'copy_from_invalid';

export interface DiffChange {
  field: string;
  field_label: string;
  old: string;
  new: string;
  type: FieldType;
  flags: DiffFlag[];
  old_len: number | null;
  new_len: number | null;
}

export interface PreviewDiff {
  id_product: number;
  name: string;
  reference: string;
  price: number;
  changes: DiffChange[];
  has_flags: boolean;
}

export interface FieldSummary {
  field: string;
  field_label: string;
  type: FieldType;
  char_limit: number | null;
  changes: number;
  avg_old_len: number | null;
  avg_new_len: number | null;
  over_limit: number;
  clamped: number;
  mismatches: number;
}

export interface Collision {
  field: string;
  field_label: string;
  value: string;
  product_count: number;
  products: number[];
}

export interface PreviewSummary {
  total_scope: number;
  will_change: number;
  no_change: number;
  with_flags: number;
  flag_counts: Record<DiffFlag, number>;
  by_field: FieldSummary[];
  collisions: Collision[];
}

export interface PreviewResponse {
  summary: PreviewSummary;
  diffs: PreviewDiff[];
  total_scope: number;
  analyzed: number;
  truncated: boolean;
}

export interface BulkBatchStatus {
  id_batch: number;
  status: 'pending' | 'running' | 'success' | 'partial' | 'failed' | 'undone';
  mode: string;
  total: number;
  done: number;
  remaining: number;
  products_changed: number;
  products_failed: number;
  id_lang: number;
  actions: BulkAction[];
  started_at: string;
  finished_at: string | null;
  items_this_turn?: Array<{ id_product: number; changed: number; error?: string }>;
  items?: Array<{ id_product: number; change_count: number; changed: number; failed: number; last_change: string }>;
}

const BASE = '/modules/smartbulk/api/bulk';

export const bulkApi = {
  fields: () =>
    api.get<{ ok: boolean; fields: FieldDefinition[] }>(`${BASE}/fields`).then((r) => r.fields),

  templates: () =>
    api.get<{ ok: boolean; templates: ActionTemplate[] }>(`${BASE}/templates`).then((r) => r.templates),

  saveTemplate: (input: { name: string; description?: string; actions: BulkAction[] }) =>
    api.post<{ ok: boolean; id: number; error?: string }>(`${BASE}/templates`, input).then((r) => {
      if (!r.ok) throw new Error(r.error || 'Save template failed');
      return r.id;
    }),

  deleteTemplate: (id: number) => api.del<{ ok: boolean }>(`${BASE}/templates/${id}`),

  preview: (input: {
    actions: BulkAction[];
    id_lang?: number;
    product_ids?: number[];
    filter_spec?: { filters: unknown[] };
  }) =>
    api
      .post<{
        ok: boolean;
        summary: PreviewSummary;
        diffs: PreviewDiff[];
        total_scope: number;
        analyzed: number;
        truncated: boolean;
        error?: string;
      }>(`${BASE}/preview`, input)
      .then((r): PreviewResponse => {
        if (!r.ok) throw new Error(r.error || 'Preview failed');
        return {
          summary: r.summary,
          diffs: r.diffs,
          total_scope: r.total_scope,
          analyzed: r.analyzed,
          truncated: r.truncated,
        };
      }),

  createBatch: (input: {
    actions: BulkAction[];
    id_lang?: number;
    product_ids?: number[];
    filter_spec?: { filters: unknown[] };
    mode?: 'apply' | 'dry_run';
  }) =>
    api
      .post<{ ok: boolean; batch: BulkBatchStatus; error?: string }>(`${BASE}/batch`, input)
      .then((r) => {
        if (!r.ok || !r.batch) throw new Error(r.error || 'Could not create batch');
        return r.batch;
      }),

  getBatch: (id: number, includeItems = false) =>
    api
      .get<{ ok: boolean; batch: BulkBatchStatus; error?: string }>(
        `${BASE}/batch/${id}${includeItems ? '?include_items=1' : ''}`
      )
      .then((r) => {
        if (!r.ok || !r.batch) throw new Error(r.error || 'Could not fetch batch');
        return r.batch;
      }),

  processNext: (id: number, limit = 10) =>
    api
      .post<{ ok: boolean; batch: BulkBatchStatus; error?: string }>(`${BASE}/batch/${id}/process-next?limit=${limit}`, {})
      .then((r) => {
        if (!r.ok || !r.batch) throw new Error(r.error || 'Process failed');
        return r.batch;
      }),

  undo: (id: number) =>
    api
      .post<{ ok: boolean; result: { id_batch: number; restored: number; errors: string[]; status: string }; error?: string }>(
        `${BASE}/batch/${id}/undo`,
        {}
      )
      .then((r) => {
        if (!r.ok || !r.result) throw new Error(r.error || 'Undo failed');
        return r.result;
      }),

  cancel: (id: number) =>
    api
      .post<{ ok: boolean; batch: BulkBatchStatus; error?: string }>(`${BASE}/batch/${id}/cancel`, {})
      .then((r) => {
        if (!r.ok || !r.batch) throw new Error(r.error || 'Cancel failed');
        return r.batch;
      }),

  resume: (id: number) =>
    api
      .post<{ ok: boolean; batch: BulkBatchStatus; error?: string }>(`${BASE}/batch/${id}/resume`, {})
      .then((r) => {
        if (!r.ok || !r.batch) throw new Error(r.error || 'Resume failed');
        return r.batch;
      }),
};

export function operatorLabel(op: Operator): string {
  return {
    set:                      'Set to',
    append:                   'Append',
    prepend:                  'Prepend',
    replace:                  'Replace',
    math:                     'Math',
    clear:                    'Clear',
    copy_from:                '📋 Copy from field',
    generate_from_name:       '✨ Generate from name',
    generate_from_short_desc: '✨ Generate from short description',
    ai_generate:              '🤖 AI generate (prompt)',
    add:                      'Add value',
    remove:                   'Remove value',
    skip:                     'Skip',
  }[op];
}

export interface ActionTemplate {
  id: string;
  name: string;
  description: string;
  emoji: string;
  group: string;
  actions: BulkAction[];
  is_user?: boolean;
  id_user?: number;
}
