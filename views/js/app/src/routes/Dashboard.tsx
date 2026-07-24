import { Link, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import PageHead from '../components/PageHead';
import PageCard from '../components/PageCard';
import Card from '../components/Card';
import SectionHeader from '../components/SectionHeader';
import Button from '../components/Button';
import Badge from '../components/Badge';
import { useToast } from '../components/Toast';
import { useConfirm } from '../components/ConfirmDialog';
import { dashboardApi, type DashboardData, type DashboardIssue } from '../lib/dashboard';
import { healthApi } from '../lib/health';
import { bulkApi } from '../lib/bulk';
import { STATUS_TONE } from '../lib/history';
import { t, t2 } from '../lib/i18n';

const thCls = 'text-left text-[11px] uppercase tracking-wide text-muted-foreground font-semibold bg-muted px-3 py-2.5';
const tdCls = 'px-3 py-2.5 border-t border-border align-middle';

export default function Dashboard() {
  const user = window.SmartBulk.user;
  const query = useQuery({
    queryKey: ['dashboard'],
    queryFn: dashboardApi.get,
    staleTime: 60_000,
  });

  return (
    <PageCard>
      <PageHead
        title={`${t('dashboard.welcome', 'Welcome back')}, ${user.name || 'Marcin'}`}
        subtitle={
          query.data
            ? `${t('dashboard.last_scan', 'Last scan')}: ${formatRelative(query.data.last_scan_at)}`
            : t('dashboard.subtitle_loading', 'Loading…')
        }
        actions={
          <>
            <Button onClick={() => query.refetch()} disabled={query.isFetching}>
              {query.isFetching ? `⟳ ${t('common.loading', 'Loading…')}` : `↻ ${t('common.refresh', 'Refresh')}`}
            </Button>
            <Link to="/ai"><Button>{t('dashboard.run_ai_batch', 'Run AI batch')}</Button></Link>
            <Link to="/bulk-editor"><Button variant="primary">{t('dashboard.new_bulk_edit', '+ New bulk edit')}</Button></Link>
          </>
        }
      />

      {query.isLoading && (
        <Card><div className="text-[13px] text-muted-foreground py-8 text-center">Loading dashboard…</div></Card>
      )}
      {query.isError && (
        <Card><div className="text-[13px] text-red-700 py-6 text-center">{(query.error as Error).message}</div></Card>
      )}

      {query.data && <DashboardBody data={query.data} />}
    </PageCard>
  );
}

function DashboardBody({ data }: { data: DashboardData }) {
  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <KpiCard
        label={t('dashboard.kpi.products', 'Products')}
        value={data.kpis.products_total.toLocaleString()}
        delta={t2('dashboard.kpi.products_active_in_shop', '{n} active in current shop', { n: data.health_groups.seo?.total ?? 0 })}
      />
      <KpiCard
        label={t('dashboard.kpi.health_score', 'Health score')}
        value={String(data.kpis.health_score)}
        suffix="/100"
        delta={
          data.kpis.health_score >= 80 ? t('dashboard.health_strong', 'Strong')
            : data.kpis.health_score >= 50 ? t('dashboard.health_attention', 'Needs attention')
            : t('dashboard.health_critical', 'Critical')
        }
        deltaClass={
          data.kpis.health_score >= 80 ? 'text-green-600'
            : data.kpis.health_score >= 50 ? 'text-amber-600'
            : 'text-red-600'
        }
      />
      <KpiCard
        label={t('dashboard.kpi.ai_today', 'AI runs today')}
        value={data.kpis.ai_today.runs.toLocaleString()}
        delta={
          data.kpis.ai_today.budget > 0
            ? t2('dashboard.kpi.ai_today_spent', '${spent} / ${budget} budget', { spent: data.kpis.ai_today.cost_usd.toFixed(2), budget: data.kpis.ai_today.budget.toFixed(2) })
            : t2('dashboard.kpi.ai_today_no_budget', '${spent} (no budget set)', { spent: data.kpis.ai_today.cost_usd.toFixed(2) })
        }
      />
      <KpiCard
        label={t('dashboard.kpi.active_jobs', 'Active jobs')}
        value={String(data.kpis.jobs.active_schedules)}
        delta={
          data.kpis.jobs.running_batches > 0
            ? t2('dashboard.kpi.active_jobs_running', '{n} batch{plural} running', { n: data.kpis.jobs.running_batches, plural: data.kpis.jobs.running_batches === 1 ? '' : 'es' })
            : t('dashboard.kpi.active_jobs_idle', 'No running batches')
        }
      />

      <div className="lg:col-span-3 flex flex-col gap-4 min-w-0">
        <TopIssuesCard issues={data.top_issues} />
        <RecentActivityCard data={data} />
      </div>

      <div className="flex flex-col gap-4 min-w-0">
        <HealthTrendCard />
        <AiSpendCard data={data} />
        {data.tip && <TipCard tip={data.tip} />}
        <QuickActionsCard />
      </div>
    </div>
  );
}

function KpiCard({ label, value, suffix, delta, deltaClass }: {
  label: string; value: string; suffix?: string; delta: string; deltaClass?: string;
}) {
  return (
    <Card padding="md">
      <div className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{label}</div>
      <div className="text-[28px] font-bold leading-tight mt-1">
        {value}
        {suffix && <span className="text-[14px] text-muted-foreground font-normal ml-0.5">{suffix}</span>}
      </div>
      <div className={`text-[12px] mt-0.5 ${deltaClass ?? 'text-muted-foreground'}`}>{delta}</div>
    </Card>
  );
}

function TopIssuesCard({ issues }: { issues: DashboardIssue[] }) {
  const toast = useToast();
  const navigate = useNavigate();

  const fixMutation = useMutation({
    mutationFn: async (issue: DashboardIssue) => {
      const ids = await healthApi.productIds(issue.id, { limit: 5000 });
      return { issue, ids };
    },
    onSuccess: ({ issue, ids }) => {
      if (ids.length === 0) { toast.show('No matching products', 'info'); return; }
      const target = issue.fixable_by === 'ai' ? '/ai' : '/bulk-editor';
      navigate(target, {
        state: {
          handoff: {
            productIds: ids,
            label: `${issue.label} (${ids.length.toLocaleString()})`,
            source: 'dashboard',
          },
        },
      });
    },
    onError: (e: Error) => toast.show(e.message || 'Could not open', 'error'),
  });

  return (
    <Card>
      <SectionHeader
        title={t('dashboard.issues.title', 'Content Health issues')}
        subtitle={issues.length > 0 ? t('dashboard.issues.subtitle', 'Top problems affecting SEO and discoverability') : t('dashboard.issues.no_issues', '✓ All scanned checks pass. Run another health scan in the Health tab.')}
        action={<Link to="/health"><Button variant="ghost" size="sm">{t('dashboard.issues.view_all', 'View all →')}</Button></Link>}
      />
      {issues.length === 0 && (
        <div className="text-[13px] text-muted-foreground py-6 text-center">
          {t('dashboard.issues.no_issues', '✓ All scanned checks pass. Run another health scan in the Health tab.')}
        </div>
      )}
      {issues.length > 0 && (
        <div className="overflow-x-auto">
          <table className="w-full text-[13px]">
            <thead>
              <tr>
                <th className={thCls}>{t('common.operation', 'Issue')}</th>
                <th className={`${thCls} w-24`}>{t('common.products', 'Products')}</th>
                <th className={`${thCls} w-28`}>{t('common.priority', 'Priority')}</th>
                <th className={`${thCls} w-32 text-right`}>{t('common.action', 'Action')}</th>
              </tr>
            </thead>
            <tbody>
              {issues.map((issue) => (
                <tr key={issue.id} className="hover:bg-slate-50">
                  <td className={tdCls}>
                    <div className="font-semibold">{t('issue.' + issue.id, issue.label)}</div>
                    <div className="text-[11px] text-muted-foreground">{t('issue.hint.' + issue.id, issue.fix_hint)}</div>
                  </td>
                  <td className={`${tdCls} font-semibold`}>
                    {issue.count.toLocaleString()}
                    <span className="text-[10px] text-muted-foreground font-normal ml-1">({issue.percent}%)</span>
                  </td>
                  <td className={tdCls}>
                    <Badge variant={
                      issue.severity === 'high' ? 'destructive'
                        : issue.severity === 'medium' ? 'warning'
                        : 'muted'
                    }>
                      {t('severity.' + issue.severity, issue.severity)}
                    </Badge>
                  </td>
                  <td className={`${tdCls} text-right`}>
                    {issue.fixable_by === 'ai' ? (
                      <Button
                        variant="primary"
                        size="compact"
                        onClick={() => fixMutation.mutate(issue)}
                        disabled={fixMutation.isPending}
                      >
                        {t('dashboard.issues.fix_with_ai', '✨ Fix with AI')}
                      </Button>
                    ) : (
                      <Button
                        size="compact"
                        onClick={() => fixMutation.mutate(issue)}
                        disabled={fixMutation.isPending}
                      >
                        {t('dashboard.issues.bulk_edit', 'Bulk edit →')}
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  );
}

function RecentActivityCard({ data }: { data: DashboardData }) {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();

  const undoMutation = useMutation({
    mutationFn: (id: number) => bulkApi.undo(id),
    onSuccess: (res) => {
      toast.show(`Restored ${res.restored} changes`, 'success');
      qc.invalidateQueries({ queryKey: ['dashboard'] });
    },
    onError: (e: Error) => toast.show(e.message || 'Undo failed', 'error'),
  });

  const handleUndo = async (id: number, changed: number) => {
    const ok = await confirm({
      title: `Undo batch #${id}?`,
      message: <>Revert <strong>{changed}</strong> change{changed !== 1 ? 's' : ''}.</>,
      confirmLabel: 'Undo',
      tone: 'destructive',
    });
    if (ok) undoMutation.mutate(id);
  };

  return (
    <Card>
      <SectionHeader
        title={t('dashboard.activity.title', 'Recent activity')}
        subtitle={data.recent_activity.length === 0 ? t('dashboard.activity.empty', 'No batches yet — your runs will show up here.') : undefined}
        action={<Link to="/history"><Button variant="ghost" size="sm">{t('dashboard.activity.view_history', 'View history →')}</Button></Link>}
      />
      {data.recent_activity.length > 0 && (
        <div className="overflow-x-auto">
          <table className="w-full text-[13px]">
            <thead>
              <tr>
                <th className={`${thCls} w-32`}>{t('common.when', 'When')}</th>
                <th className={thCls}>{t('common.operation', 'Operation')}</th>
                <th className={`${thCls} w-24`}>{t('common.products', 'Products')}</th>
                <th className={`${thCls} w-28`}>{t('common.status', 'Status')}</th>
                <th className={`${thCls} w-24 text-right`}></th>
              </tr>
            </thead>
            <tbody>
              {data.recent_activity.map((b) => {
                const canUndo =
                  (b.kind === 'bulk_edit' || b.kind === 'ai_batch') &&
                  (b.status === 'success' || b.status === 'partial') &&
                  b.has_undoable_changes;
                return (
                  <tr key={b.id_batch} className="hover:bg-slate-50">
                    <td className={`${tdCls} text-[12px] text-muted-foreground whitespace-nowrap`}>{formatRelative(b.started_at)}</td>
                    <td className={tdCls}>
                      <span className="font-mono text-muted-foreground mr-1">#{b.id_batch}</span>
                      {b.kind === 'ai_batch' ? '🤖 ' : '📝 '}
                      {b.summary}
                    </td>
                    <td className={`${tdCls} font-semibold`}>{b.products_changed}</td>
                    <td className={tdCls}>
                      <Badge variant={STATUS_TONE[b.status] ?? 'muted'}>{t('status.' + b.status, b.status)}</Badge>
                    </td>
                    <td className={`${tdCls} text-right`}>
                      {canUndo && (
                        <Button size="compact" onClick={() => handleUndo(b.id_batch, b.products_changed)} disabled={undoMutation.isPending}>
                          {t('history.undo_batch', '↶ Undo')}
                        </Button>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  );
}

function AiSpendCard({ data }: { data: DashboardData }) {
  const s = data.ai_spend;
  const pct = s.daily_budget > 0 ? Math.min(100, Math.round((s.today_cost / s.daily_budget) * 100)) : 0;
  const overBudget = s.daily_budget > 0 && s.today_cost >= s.daily_budget;
  return (
    <Card>
      <SectionHeader title={t('dashboard.spend.title', 'AI spend today')} size="sm" />
      <div className="text-[24px] font-bold leading-tight">
        ${s.today_cost.toFixed(2)}
        {s.daily_budget > 0 && (
          <span className="text-[13px] text-muted-foreground font-normal"> / ${s.daily_budget.toFixed(2)}</span>
        )}
      </div>
      {s.daily_budget > 0 ? (
        <>
          <div className="h-1.5 bg-slate-100 rounded-full my-2 overflow-hidden">
            <div className={`h-full ${overBudget ? 'bg-red-500' : 'bg-primary'}`} style={{ width: `${pct}%` }} />
          </div>
          <div className={`text-[11px] ${overBudget ? 'text-red-700 font-semibold' : 'text-muted-foreground'}`}>
            {overBudget ? t('dashboard.spend.budget_reached', '⚠ Daily budget reached') : t2('dashboard.spend.percent_used', '{pct}% of daily budget', { pct })}
          </div>
        </>
      ) : (
        <div className="text-[11px] text-muted-foreground">{t('dashboard.spend.no_budget', 'No daily budget set — see Settings.')}</div>
      )}
      <div className="h-px bg-border my-3" />
      <div className="text-[11px] flex justify-between py-0.5"><span>{t('dashboard.spend.this_month', 'This month')}</span><strong>${s.month_cost.toFixed(2)}</strong></div>
      <div className="text-[11px] flex justify-between py-0.5"><span>{t('dashboard.spend.last_30d_runs', 'Last 30 d runs')}</span><strong>{s.last_30d_runs.toLocaleString()}</strong></div>
      <div className="text-[11px] flex justify-between py-0.5">
        <span>{t('dashboard.spend.avg_cost', 'Avg cost / run')}</span>
        <strong>{s.avg_cost_per_run > 0 ? `$${s.avg_cost_per_run.toFixed(4)}` : '—'}</strong>
      </div>
    </Card>
  );
}

function TipCard({ tip }: { tip: NonNullable<DashboardData['tip']> }) {
  const navigate = useNavigate();
  const toast = useToast();

  const fix = useMutation({
    mutationFn: () => healthApi.productIds(tip.problem_id, { limit: 5000 }),
    onSuccess: (ids) => {
      if (ids.length === 0) { toast.show('No matching products', 'info'); return; }
      const target = tip.fixable_by === 'ai' ? '/ai' : '/bulk-editor';
      navigate(target, {
        state: { handoff: { productIds: ids, label: `${tip.label} (${ids.length.toLocaleString()})`, source: 'dashboard-tip' } },
      });
    },
    onError: (e: Error) => toast.show(e.message || 'Could not open', 'error'),
  });

  return (
    <Card tone="info" padding="md" className="text-[12px] leading-normal">
      <strong>{t('dashboard.tip.title', '💡 Tip')}</strong>
      <div className="mt-1">
        <strong>{tip.count.toLocaleString()}</strong> {t('common.products', 'products').toLowerCase()}: <em>{t('issue.' + tip.problem_id, tip.label)}</em>.
        {tip.est_cost > 0 && <> {t2('dashboard.tip.cost', 'Estimated AI cost ≈ ${cost}.', { cost: tip.est_cost.toFixed(2) })}</>}
      </div>
      <button
        type="button"
        className="mt-2 text-primary underline font-medium hover:opacity-80"
        onClick={() => fix.mutate()}
        disabled={fix.isPending}
      >
        {fix.isPending ? t('dashboard.tip.loading', 'Loading…') : t('dashboard.tip.fix_now', 'Fix now →')}
      </button>
    </Card>
  );
}

function HealthTrendCard() {
  const query = useQuery({
    queryKey: ['health', 'snapshots'],
    queryFn: () => healthApi.snapshots({ limit: 30 }),
    staleTime: 5 * 60 * 1000,
  });
  const snapshots = query.data ?? [];
  if (snapshots.length < 2) {
    return (
      <Card padding="sm">
        <SectionHeader title={t('dashboard.trend.title', 'Health trend')} size="sm" />
        <div className="text-[12px] text-muted-foreground">
          {snapshots.length === 0
            ? t('dashboard.trend.no_data', 'No snapshots yet — run a Content Health scan to start tracking.')
            : t('dashboard.trend.need_more', 'Need at least 2 scans to plot a trend. Run another scan tomorrow.')}
        </div>
      </Card>
    );
  }
  const w = 260, h = 56, pad = 4;
  const values = snapshots.map((s) => s.composite);
  const min = Math.min(...values, 0);
  const max = Math.max(...values, 100);
  const range = Math.max(1, max - min);
  const stepX = (w - pad * 2) / (snapshots.length - 1);
  const path = values.map((v, i) => {
    const x = pad + i * stepX;
    const y = pad + ((max - v) / range) * (h - pad * 2);
    return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`;
  }).join(' ');
  const last = values[values.length - 1];
  const first = values[0];
  const delta = last - first;
  const trendColor = delta > 0 ? 'text-green-600' : delta < 0 ? 'text-red-600' : 'text-muted-foreground';
  const arrow = delta > 0 ? '▲' : delta < 0 ? '▼' : '→';

  return (
    <Card padding="sm">
      <SectionHeader title={t('dashboard.trend.title', 'Health trend')} size="sm" />
      <div className="text-[24px] font-bold leading-tight">
        {last}
        <span className="text-[13px] text-muted-foreground font-normal ml-1">/100</span>
        <span className={`text-[12px] font-medium ml-2 ${trendColor}`}>{arrow} {Math.abs(delta)}</span>
      </div>
      <svg width={w} height={h} className="mt-2">
        <path d={path} stroke="currentColor" strokeWidth="1.5" fill="none" className="text-primary" />
      </svg>
      <div className="text-[10px] text-muted-foreground flex justify-between">
        <span>{snapshots[0].taken_at.slice(0, 10)}</span>
        <span>{snapshots[snapshots.length - 1].taken_at.slice(0, 10)}</span>
      </div>
    </Card>
  );
}

function QuickActionsCard() {
  return (
    <Card padding="sm">
      <SectionHeader title={t('dashboard.quick.title', 'Quick actions')} size="sm" />
      <div className="flex flex-col gap-1">
        <Link to="/bulk-editor"><Button className="w-full justify-start">{t('dashboard.quick.bulk_edit', '⚙️ New bulk edit')}</Button></Link>
        <Link to="/ai"><Button className="w-full justify-start">{t('dashboard.quick.ai_batch', '✨ Run AI batch')}</Button></Link>
        <Link to="/health"><Button className="w-full justify-start">{t('dashboard.quick.health_scan', '💊 Run health scan')}</Button></Link>
        <Link to="/scheduler"><Button className="w-full justify-start">{t('dashboard.quick.schedule', '⏰ Schedule a job')}</Button></Link>
        <Link to="/prompts"><Button className="w-full justify-start">{t('dashboard.quick.prompts', '📝 Edit prompts')}</Button></Link>
      </div>
    </Card>
  );
}

// ---------- helpers ----------

function formatRelative(s: string | null): string {
  if (!s) return '—';
  const d = new Date(s.replace(' ', 'T') + (s.includes('T') ? '' : 'Z'));
  if (isNaN(d.getTime())) return s;
  const diff = (Date.now() - d.getTime()) / 1000;
  if (diff < 60)            return 'just now';
  if (diff < 3600)          return `${Math.floor(diff / 60)} min ago`;
  if (diff < 86400)         return `${Math.floor(diff / 3600)} h ago`;
  if (diff < 86400 * 7)     return `${Math.floor(diff / 86400)} d ago`;
  return d.toLocaleString();
}
