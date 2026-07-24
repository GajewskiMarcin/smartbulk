import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import PageHead from '../components/PageHead';
import PageCard from '../components/PageCard';
import Card from '../components/Card';
import Button from '../components/Button';
import { useToast } from '../components/Toast';
import { healthApi, SEVERITY_META, type AffectedProduct, type HealthProblem, type ProblemGroup } from '../lib/health';
import { Input } from '../components/Form';
import { t, t2 } from '../lib/i18n';
import { cn } from '../lib/utils';

const GROUP_META: Record<ProblemGroup, { labelKey: string; labelFallback: string; emoji: string; tint: string }> = {
  seo:     { labelKey: 'health.group.seo',     labelFallback: 'SEO',           emoji: '🎯', tint: 'bg-blue-50 border-blue-200' },
  content: { labelKey: 'health.group.content', labelFallback: 'Content',       emoji: '📝', tint: 'bg-emerald-50 border-emerald-200' },
  codes:   { labelKey: 'health.group.codes',   labelFallback: 'Product codes', emoji: '🔖', tint: 'bg-purple-50 border-purple-200' },
};

export default function Health() {
  const toast = useToast();
  const navigate = useNavigate();
  const [activeGroup, setActiveGroup] = useState<ProblemGroup | 'all'>('all');
  const [loadingProblem, setLoadingProblem] = useState<string | null>(null);

  const scanQuery = useQuery({
    queryKey: ['health', 'scan'],
    queryFn: () => healthApi.scan(),
    staleTime: 5 * 60 * 1000,
  });

  const report = scanQuery.data;

  const fixInBulk = async (problem: HealthProblem, explicitIds?: number[]) => {
    setLoadingProblem(problem.id);
    try {
      const ids = explicitIds ?? await healthApi.productIds(problem.id, { limit: 5000 });
      if (ids.length === 0) {
        toast.show('No matching products found', 'info');
        return;
      }
      navigate('/bulk-editor', {
        state: {
          handoff: {
            productIds: ids,
            label: `${problem.label} (${ids.length.toLocaleString()})`,
            source: 'content-health',
          },
        },
      });
    } catch (e) {
      toast.show((e as Error).message || 'Could not open in Bulk Editor', 'error');
    } finally {
      setLoadingProblem(null);
    }
  };

  const filteredProblems = useMemo(() => {
    if (!report) return [];
    return activeGroup === 'all'
      ? report.problems
      : report.problems.filter((p) => p.group === activeGroup);
  }, [report, activeGroup]);

  return (
    <PageCard>
      <PageHead
        title={t('health.title', 'Content Health')}
        subtitle={
          report
            ? `${t2('health.subtitle_scanned', '{n} active products scanned', { n: report.total.toLocaleString() })} · ${new Date(report.generated_at).toLocaleString()}`
            : t('health.subtitle_initial', 'Quality audit of product data')
        }
        actions={
          <Button
            variant="default"
            onClick={() => scanQuery.refetch()}
            disabled={scanQuery.isFetching}
          >
            {scanQuery.isFetching ? t('health.scanning', '⟳ Scanning…') : t('health.run_scan', '↻ Run scan')}
          </Button>
        }
      />

      {scanQuery.isLoading && (
        <Card>
          <div className="text-[13px] text-muted-foreground py-8 text-center">{t('health.scanning', '⟳ Scanning…')}</div>
        </Card>
      )}

      {scanQuery.isError && (
        <Card>
          <div className="text-[13px] text-red-700 py-6 text-center">
            {t('common.error', 'Error')}: {(scanQuery.error as Error).message}
          </div>
        </Card>
      )}

      {report && report.total === 0 && (
        <Card>
          <div className="text-[13px] text-muted-foreground py-8 text-center">
            {t('health.empty_shop', 'No active products in this shop. Nothing to scan.')}
          </div>
        </Card>
      )}

      {report && report.total > 0 && (
        <>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
            {(['seo', 'content', 'codes'] as ProblemGroup[]).map((g) => {
              const summary = report.groups[g];
              const meta = GROUP_META[g];
              return (
                <button
                  key={g}
                  type="button"
                  onClick={() => setActiveGroup((prev) => (prev === g ? 'all' : g))}
                  className={cn(
                    'text-left border rounded-lg p-4 transition-all',
                    meta.tint,
                    activeGroup === g ? 'ring-2 ring-primary' : 'hover:shadow-sm'
                  )}
                >
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <div className="text-[11px] uppercase tracking-wide text-muted-foreground">
                        {meta.emoji} {t(meta.labelKey, meta.labelFallback)}
                      </div>
                      <div className="text-[13px] text-slate-700 mt-1">
                        <strong>{summary.affected.toLocaleString()}</strong> / {summary.total.toLocaleString()} {t('common.products', 'products').toLowerCase()}
                      </div>
                    </div>
                    <ScoreRing value={summary.score} />
                  </div>
                </button>
              );
            })}
          </div>

          <Card>
            <div className="flex items-center justify-between mb-3">
              <div className="text-[13px] font-semibold">
                {activeGroup === 'all'
                  ? t2('health.all_problems', 'All problems ({n})', { n: report.problems.length })
                  : t2('health.problems_in_group', '{group} problems ({n})', {
                      group: t(GROUP_META[activeGroup].labelKey, GROUP_META[activeGroup].labelFallback),
                      n: filteredProblems.length,
                    })}
              </div>
              {activeGroup !== 'all' && (
                <button type="button" className="text-[11px] text-primary hover:underline" onClick={() => setActiveGroup('all')}>
                  {t('health.show_all_groups', 'Show all groups')}
                </button>
              )}
            </div>

            <div className="divide-y divide-border">
              {filteredProblems.map((p) => (
                <ProblemRow
                  key={p.id}
                  problem={p}
                  onFix={() => fixInBulk(p)}
                  loading={loadingProblem === p.id}
                />
              ))}
            </div>
          </Card>
        </>
      )}
    </PageCard>
  );
}

function ScoreRing({ value }: { value: number }) {
  const size = 56;
  const stroke = 6;
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  const dash = (value / 100) * c;
  const color = value >= 80 ? 'stroke-green-500' : value >= 50 ? 'stroke-amber-500' : 'stroke-red-500';
  return (
    <svg width={size} height={size} className="flex-shrink-0 -rotate-90">
      <circle cx={size / 2} cy={size / 2} r={r} strokeWidth={stroke} className="stroke-white/80" fill="none" />
      <circle
        cx={size / 2} cy={size / 2} r={r}
        strokeWidth={stroke}
        strokeDasharray={`${dash} ${c}`}
        strokeLinecap="round"
        className={color}
        fill="none"
      />
      <text
        x="50%" y="50%"
        transform={`rotate(90 ${size / 2} ${size / 2})`}
        textAnchor="middle"
        dominantBaseline="central"
        className={cn('text-[14px] font-bold', value >= 80 ? 'fill-green-700' : value >= 50 ? 'fill-amber-700' : 'fill-red-700')}
      >
        {value}
      </text>
    </svg>
  );
}

function ProblemRow({
  problem,
  onFix,
  loading,
}: {
  problem: HealthProblem;
  onFix: (explicitIds?: number[]) => void;
  loading: boolean;
}) {
  const sev = SEVERITY_META[problem.severity];
  const affected = problem.count > 0;
  const [expanded, setExpanded] = useState(false);

  return (
    <div className="py-2.5">
      <div className="flex items-center gap-3">
        {affected ? (
          <span className={cn(
            'text-[10px] font-semibold uppercase tracking-wide px-1.5 py-0.5 rounded',
            problem.severity === 'high' ? 'bg-red-100 text-red-800'
              : problem.severity === 'medium' ? 'bg-amber-100 text-amber-800'
              : 'bg-slate-100 text-slate-600'
          )}>
            {t('severity.' + problem.severity, sev.label)}
          </span>
        ) : (
          <span className="text-[10px] font-semibold uppercase tracking-wide px-1.5 py-0.5 rounded bg-green-100 text-green-700">
            {t('health.clean', '✓ Clean')}
          </span>
        )}
        <div className="min-w-0 flex-1">
          <div className="text-[13px] font-medium text-slate-900 truncate">{t('issue.' + problem.id, problem.label)}</div>
          <div className="text-[11px] text-muted-foreground truncate">{t('issue.hint.' + problem.id, problem.fix_hint)}</div>
        </div>

        {affected ? (
          <button
            type="button"
            onClick={() => setExpanded((x) => !x)}
            className={cn(
              'text-[13px] font-semibold whitespace-nowrap hover:underline focus:outline-none',
              sev.color
            )}
          >
            {problem.count.toLocaleString()}
            <span className="text-[10px] text-muted-foreground ml-1">({problem.percent}%)</span>
            <span className="ml-1 text-[10px]">{expanded ? '▾' : '▸'}</span>
          </button>
        ) : (
          <div className="text-[13px] font-semibold whitespace-nowrap text-green-700">
            0 <span className="text-[10px] text-muted-foreground ml-1">(0%)</span>
          </div>
        )}

        {affected ? (
          <Button
            size="sm"
            variant="primary"
            disabled={loading}
            onClick={() => onFix()}
          >
            {loading ? '…' : t2('health.fix_all', 'Fix all {n} →', { n: problem.count })}
          </Button>
        ) : (
          <div className="text-[12px] text-green-700 px-2 whitespace-nowrap">{t('health.no_issues', 'No issues')}</div>
        )}
      </div>

      {expanded && affected && (
        <ProblemDrillDown problem={problem} onFixSelected={onFix} />
      )}
    </div>
  );
}

function ProblemDrillDown({
  problem,
  onFixSelected,
}: {
  problem: HealthProblem;
  onFixSelected: (ids: number[]) => void;
}) {
  const PAGE_SIZE = 20;
  const [page, setPage] = useState(0);
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState<Set<number>>(new Set());

  const query = useQuery({
    queryKey: ['health', 'products', problem.id, page, search],
    queryFn: () => healthApi.products(problem.id, { limit: PAGE_SIZE, offset: page * PAGE_SIZE, search }),
  });

  const items: AffectedProduct[] = query.data?.items ?? [];
  const total = query.data?.total ?? 0;
  const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));

  const toggleAll = (on: boolean) => {
    setSelected((prev) => {
      const next = new Set(prev);
      items.forEach((it) => on ? next.add(it.id_product) : next.delete(it.id_product));
      return next;
    });
  };
  const toggleOne = (id: number) => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const allOnPageSelected = items.length > 0 && items.every((it) => selected.has(it.id_product));

  return (
    <div className="mt-2 ml-0 border border-border rounded-md bg-muted/20 p-3">
      <div className="flex items-center gap-2 mb-2">
        <Input
          placeholder={t('health.search_placeholder', 'Search by product ID, name, or REF…')}
          value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(0); }}
          className="max-w-[280px]"
        />
        <div className="flex-1" />
        {selected.size > 0 && (
          <Button
            size="sm"
            variant="primary"
            onClick={() => onFixSelected(Array.from(selected))}
          >
            {t2('health.fix_selected', 'Fix {n} selected →', { n: selected.size })}
          </Button>
        )}
      </div>

      {query.isLoading && (
        <div className="text-[12px] text-muted-foreground py-4 text-center">{t('common.loading', 'Loading…')}</div>
      )}

      {!query.isLoading && items.length === 0 && (
        <div className="text-[12px] text-muted-foreground py-4 text-center">{t('health.no_products_match', 'No products match the search.')}</div>
      )}

      {items.length > 0 && (
        <>
          <div className="overflow-x-auto">
            <table className="w-full text-[12px]">
              <thead>
                <tr className="border-b border-border text-[10px] uppercase tracking-wide text-muted-foreground">
                  <th className="text-left px-2 py-1.5 w-6">
                    <input
                      type="checkbox"
                      checked={allOnPageSelected}
                      onChange={(e) => toggleAll(e.target.checked)}
                    />
                  </th>
                  <th className="text-left px-2 py-1.5 w-14">{t('health.col.id', 'ID')}</th>
                  <th className="text-left px-2 py-1.5">{t('health.col.name', 'Name')}</th>
                  <th className="text-left px-2 py-1.5 w-32">{t('health.col.reference', 'Reference')}</th>
                  <th className="text-left px-2 py-1.5">{t('health.col.current_value', 'Current value')}</th>
                </tr>
              </thead>
              <tbody>
                {items.map((it) => {
                  const isSel = selected.has(it.id_product);
                  return (
                    <tr
                      key={it.id_product}
                      className={cn(
                        'border-b border-border last:border-0 hover:bg-white/60 cursor-pointer',
                        isSel && 'bg-primary/5'
                      )}
                      onClick={() => toggleOne(it.id_product)}
                    >
                      <td className="px-2 py-1.5">
                        <input type="checkbox" checked={isSel} onChange={() => toggleOne(it.id_product)} onClick={(e) => e.stopPropagation()} />
                      </td>
                      <td className="px-2 py-1.5 text-muted-foreground">#{it.id_product}</td>
                      <td className="px-2 py-1.5 font-medium truncate max-w-[320px]">{it.name || <em className="text-muted-foreground">(no name)</em>}</td>
                      <td className="px-2 py-1.5 text-muted-foreground truncate">{it.reference || '—'}</td>
                      <td className="px-2 py-1.5 text-muted-foreground truncate max-w-[360px]">
                        {it.preview || <em className="text-red-700">(empty)</em>}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          {totalPages > 1 && (
            <div className="flex items-center justify-between pt-2 text-[11px] text-muted-foreground">
              <Button size="sm" variant="ghost" disabled={page === 0} onClick={() => setPage(page - 1)}>
                {t('health.previous', '← Previous')}
              </Button>
              <span>
                {t2('health.page_of', 'Page {p} of {pages} · {total} total', { p: page + 1, pages: totalPages, total: total.toLocaleString() })}
              </span>
              <Button size="sm" variant="ghost" disabled={page >= totalPages - 1} onClick={() => setPage(page + 1)}>
                {t('health.next', 'Next →')}
              </Button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
