// SmartBulk — Content Health API client + types

import { api } from './api';

export type Severity = 'low' | 'medium' | 'high';
export type ProblemGroup = 'seo' | 'content' | 'codes';

export interface HealthProblem {
  id: string;
  group: ProblemGroup;
  label: string;
  severity: Severity;
  condition_id: string | null;   // maps to FieldCatalog content_gap id
  fix_hint: string;
  count: number;
  percent: number;
}

export interface HealthGroupSummary {
  label: string;
  affected: number;
  healthy: number;
  score: number; // 0..100
  total: number;
}

export interface HealthReport {
  generated_at: string;
  id_shop: number;
  id_lang: number;
  total: number;
  problems: HealthProblem[];
  groups: Record<ProblemGroup, HealthGroupSummary>;
}

export interface AffectedProduct {
  id_product: number;
  name: string;
  reference: string;
  preview: string;
}

export interface HealthSnapshot {
  id_snapshot: number;
  taken_at: string;
  products_total: number;
  composite: number;
  seo_score: number;
  content_score: number;
  codes_score: number;
}

const BASE = '/modules/smartbulk/api/health';

export const healthApi = {
  scan: (params: { id_lang?: number; id_shop?: number } = {}) => {
    const qs = new URLSearchParams();
    if (params.id_lang !== undefined) qs.set('id_lang', String(params.id_lang));
    if (params.id_shop !== undefined) qs.set('id_shop', String(params.id_shop));
    return api
      .get<{ ok: boolean; report: HealthReport; error?: string }>(`${BASE}/scan${qs.toString() ? `?${qs}` : ''}`)
      .then((r) => {
        if (!r.ok) throw new Error(r.error || 'Scan failed');
        return r.report;
      });
  },

  products: (problem: string, params: { limit?: number; offset?: number; search?: string; id_lang?: number; id_shop?: number } = {}) => {
    const qs = new URLSearchParams({ problem });
    if (params.limit  !== undefined) qs.set('limit',  String(params.limit));
    if (params.offset !== undefined) qs.set('offset', String(params.offset));
    if (params.search)               qs.set('search', params.search);
    if (params.id_lang !== undefined) qs.set('id_lang', String(params.id_lang));
    if (params.id_shop !== undefined) qs.set('id_shop', String(params.id_shop));
    return api
      .get<{ ok: boolean; items: AffectedProduct[]; total: number; error?: string }>(`${BASE}/products?${qs}`)
      .then((r) => {
        if (!r.ok) throw new Error(r.error || 'Lookup failed');
        return { items: r.items, total: r.total };
      });
  },

  snapshots: (params: { limit?: number } = {}) => {
    const qs = new URLSearchParams();
    if (params.limit !== undefined) qs.set('limit', String(params.limit));
    return api
      .get<{ ok: boolean; snapshots: HealthSnapshot[]; error?: string }>(`${BASE}/snapshots${qs.toString() ? `?${qs}` : ''}`)
      .then((r) => {
        if (!r.ok) throw new Error(r.error || 'Snapshots failed');
        return r.snapshots;
      });
  },

  productIds: (problem: string, params: { id_lang?: number; id_shop?: number; limit?: number } = {}) => {
    const qs = new URLSearchParams({ problem });
    if (params.id_lang !== undefined) qs.set('id_lang', String(params.id_lang));
    if (params.id_shop !== undefined) qs.set('id_shop', String(params.id_shop));
    if (params.limit !== undefined)   qs.set('limit',   String(params.limit));
    return api
      .get<{ ok: boolean; product_ids: number[]; error?: string }>(`${BASE}/product-ids?${qs}`)
      .then((r) => {
        if (!r.ok) throw new Error(r.error || 'Lookup failed');
        return r.product_ids;
      });
  },
};

export const SEVERITY_META: Record<Severity, { label: string; color: string; ring: string }> = {
  high:   { label: 'High',   color: 'text-red-700',    ring: 'stroke-red-500'    },
  medium: { label: 'Medium', color: 'text-amber-700',  ring: 'stroke-amber-500'  },
  low:    { label: 'Low',    color: 'text-slate-600',  ring: 'stroke-slate-400'  },
};
