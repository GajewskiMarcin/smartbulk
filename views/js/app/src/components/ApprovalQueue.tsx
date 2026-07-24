import { useMemo, useState } from 'react';
import Button from './Button';
import Badge from './Badge';
import { cn } from '../lib/utils';
import type { BatchRun } from '../lib/ai';
import { t, t2 } from '../lib/i18n';

const FILTER_LABELS: Record<'all' | 'pending' | 'accepted' | 'rejected' | 'failed', { key: string; def: string }> = {
  all:      { key: 'approval.filter_all',      def: 'all' },
  pending:  { key: 'approval.filter_pending',  def: 'pending' },
  accepted: { key: 'approval.filter_accepted', def: 'accepted' },
  rejected: { key: 'approval.filter_rejected', def: 'rejected' },
  failed:   { key: 'approval.filter_failed',   def: 'failed' },
};

interface Props {
  runs: BatchRun[];
  onAccept: (idRun: number) => void;
  onReject: (idRun: number) => void;
  onAcceptAll: () => void;
  onRejectAll: () => void;
  accepting?: number | null;
  rejecting?: number | null;
  bulkBusy?: boolean;
}

type StatusFilter = 'all' | 'pending' | 'accepted' | 'rejected' | 'failed';

export default function ApprovalQueue({
  runs,
  onAccept,
  onReject,
  onAcceptAll,
  onRejectAll,
  accepting,
  rejecting,
  bulkBusy,
}: Props) {
  const [filter, setFilter] = useState<StatusFilter>('pending');
  const [expanded, setExpanded] = useState<Record<number, boolean>>({});

  const counts = useMemo(() => {
    const c: Record<StatusFilter, number> = { all: runs.length, pending: 0, accepted: 0, rejected: 0, failed: 0 };
    for (const r of runs) {
      if (r.status in c) c[r.status as StatusFilter]++;
    }
    return c;
  }, [runs]);

  const filtered = useMemo(
    () => (filter === 'all' ? runs : runs.filter((r) => r.status === filter)),
    [runs, filter]
  );

  const pendingCount = counts.pending;

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap gap-1 p-1 bg-muted rounded-md">
          {(['pending', 'accepted', 'rejected', 'failed', 'all'] as StatusFilter[]).map((f) => (
            <button
              key={f}
              type="button"
              onClick={() => setFilter(f)}
              className={cn(
                'px-2.5 py-1 rounded text-[12px] font-medium capitalize transition-colors',
                filter === f ? 'bg-white text-primary shadow-sm' : 'text-slate-600 hover:text-slate-900'
              )}
            >
              {t(FILTER_LABELS[f].key, FILTER_LABELS[f].def)} <span className="text-muted-foreground">({counts[f]})</span>
            </button>
          ))}
        </div>

        <div className="flex gap-2">
          <Button
            onClick={onRejectAll}
            variant="destructive"
            size="sm"
            disabled={pendingCount === 0 || bulkBusy}
          >
            {bulkBusy ? '…' : t2('approval.reject_all_pending', '✗ Reject all pending ({n})', { n: pendingCount })}
          </Button>
          <Button
            onClick={onAcceptAll}
            variant="primary"
            size="sm"
            disabled={pendingCount === 0 || bulkBusy}
          >
            {bulkBusy ? '…' : t2('approval.accept_all_pending', '✓ Accept all pending ({n})', { n: pendingCount })}
          </Button>
        </div>
      </div>

      {filtered.length === 0 ? (
        <div className="text-muted-foreground text-[13px] text-center p-6 border border-dashed border-border rounded-md">
          {t('approval.no_match', 'No runs match the current filter.')}
        </div>
      ) : (
        <div className="border border-border rounded-md divide-y divide-border bg-white">
          {filtered.map((r) => (
            <RunRow
              key={r.id_run}
              run={r}
              expanded={!!expanded[r.id_run]}
              onToggle={() => setExpanded((prev) => ({ ...prev, [r.id_run]: !prev[r.id_run] }))}
              onAccept={() => onAccept(r.id_run)}
              onReject={() => onReject(r.id_run)}
              accepting={accepting === r.id_run}
              rejecting={rejecting === r.id_run}
            />
          ))}
        </div>
      )}
    </div>
  );
}

function RunRow({
  run,
  expanded,
  onToggle,
  onAccept,
  onReject,
  accepting,
  rejecting,
}: {
  run: BatchRun;
  expanded: boolean;
  onToggle: () => void;
  onAccept: () => void;
  onReject: () => void;
  accepting: boolean;
  rejecting: boolean;
}) {
  const isPending = run.status === 'pending';
  const preview = run.output.length > 140 ? run.output.slice(0, 140) + '…' : run.output;

  return (
    <div className="p-3">
      <div className="flex items-start gap-3">
        <button
          type="button"
          onClick={onToggle}
          className="flex-shrink-0 w-5 h-5 rounded border border-border text-[11px] text-muted-foreground hover:bg-muted"
          title={expanded ? t('approval.collapse', 'Collapse') : t('approval.expand', 'Expand')}
        >
          {expanded ? '−' : '+'}
        </button>

        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap">
            <span className="font-semibold text-[13px] truncate">
              #{run.id_product} · {run.product_name || t('approval.unnamed_product', '(unnamed product)')}
            </span>
            <Badge
              variant={
                run.status === 'accepted'  ? 'success'
                : run.status === 'rejected' ? 'destructive'
                : run.status === 'failed'   ? 'destructive'
                : 'primary'
              }
            >
              {t('status.' + run.status, run.status)}
            </Badge>
            <span className="text-[11px] text-muted-foreground">
              ${run.cost_usd.toFixed(5)} · {run.tokens_in}→{run.tokens_out} tok · {run.latency_ms}ms
            </span>
          </div>

          <div className="mt-1 text-[12px] text-slate-800 break-words whitespace-pre-wrap font-mono leading-relaxed">
            {expanded ? run.output : preview}
          </div>

          {run.error && (
            <div className="mt-1 text-[11px] text-destructive">{t('approval.error_label', 'Error')}: {run.error}</div>
          )}
        </div>

        <div className="flex flex-col gap-1 flex-shrink-0">
          <Button
            size="compact"
            variant="primary"
            onClick={onAccept}
            disabled={!isPending || accepting || rejecting}
          >
            {accepting ? '…' : '✓'}
          </Button>
          <Button
            size="compact"
            variant="destructive"
            onClick={onReject}
            disabled={!isPending || accepting || rejecting}
          >
            {rejecting ? '…' : '✗'}
          </Button>
        </div>
      </div>
    </div>
  );
}
