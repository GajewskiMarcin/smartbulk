// SmartBulk — Dashboard API client + types

import { api } from './api';
import type { BatchSummary } from './history';
import type { Severity, ProblemGroup } from './health';

export interface DashboardKpiAi {
  runs: number;
  cost_usd: number;
  budget: number;
}

export interface DashboardKpis {
  products_total: number;
  health_score: number;          // 0..100 composite
  ai_today: DashboardKpiAi;
  jobs: { running_batches: number; active_schedules: number };
}

export interface DashboardAiSpend {
  daily_budget: number;
  today_runs: number;
  today_cost: number;
  month_cost: number;
  last_30d_runs: number;
  last_30d_cost: number;
  avg_cost_per_run: number;
}

export interface DashboardIssue {
  id: string;
  group: ProblemGroup;
  label: string;
  severity: Severity;
  count: number;
  percent: number;
  fix_hint: string;
  fixable_by: 'ai' | 'bulk';
}

export interface DashboardTip {
  problem_id: string;
  label: string;
  count: number;
  fix_hint: string;
  est_cost: number;
  fixable_by: 'ai' | 'bulk';
}

export interface DashboardData {
  shop: { id_shop: number; id_lang: number };
  kpis: DashboardKpis;
  ai_spend: DashboardAiSpend;
  top_issues: DashboardIssue[];
  recent_activity: BatchSummary[];
  tip: DashboardTip | null;
  health_groups: Record<ProblemGroup, { label: string; affected: number; healthy: number; score: number; total: number }>;
  last_scan_at: string;
}

const BASE = '/modules/smartbulk/api/dashboard';

export const dashboardApi = {
  get: () =>
    api
      .get<{ ok: boolean; data: DashboardData; error?: string }>(BASE)
      .then((r) => {
        if (!r.ok || !r.data) throw new Error(r.error || 'Failed to load dashboard');
        return r.data;
      }),
};
