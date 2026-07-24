import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import PageHead from '../components/PageHead';
import PageCard from '../components/PageCard';
import Card from '../components/Card';
import Button from '../components/Button';
import Badge from '../components/Badge';
import { Input, Select } from '../components/Form';
import { useToast } from '../components/Toast';
import { useConfirm } from '../components/ConfirmDialog';
import {
  historyApi,
  STATUS_TONE,
  type AiRunRow,
  type BatchKind,
  type BatchStatus,
  type BatchSummary,
  type BulkLogRow,
} from '../lib/history';
import { bulkApi } from '../lib/bulk';
import { t, t2 } from '../lib/i18n';

const PAGE_SIZE = 20;

export default function History() {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();

  const [page, setPage] = useState(0);
  const [kind, setKind] = useState<'all' | 'bulk' | 'ai'>('all');
  const [status, setStatus] = useState<'all' | BatchStatus>('all');
  const [search, setSearch] = useState('');
  const [expanded, setExpanded] = useState<number | null>(null);

  const listQuery = useQuery({
    queryKey: ['history', 'list', kind, status, page],
    queryFn: () => historyApi.list({
      kind,
      status: status === 'all' ? undefined : status,
      limit: PAGE_SIZE,
      offset: page * PAGE_SIZE,
    }),
    placeholderData: (prev) => prev,
  });

  const items = listQuery.data?.items ?? [];
  const total = listQuery.data?.total ?? 0;
  const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));

  const q = search.trim().toLowerCase();
  const visible = q
    ? items.filter((b) =>
        String(b.id_batch).includes(q) ||
        b.summary.toLowerCase().includes(q) ||
        b.employee_name.toLowerCase().includes(q)
      )
    : items;

  const undoMutation = useMutation({
    mutationFn: (id: number) => bulkApi.undo(id),
    onSuccess: (res) => {
      toast.show(`Restored ${res.restored} changes`, 'success');
      qc.invalidateQueries({ queryKey: ['history'] });
    },
    onError: (e: Error) => toast.show(e.message || 'Undo failed', 'error'),
  });

  const handleUndo = async (batch: BatchSummary) => {
    const ok = await confirm({
      title: t2('confirm.undo_batch_title', 'Undo batch #{id}?', { id: batch.id_batch }),
      message: t2('confirm.undo_batch_msg', 'This will revert {n} product change{plural} to the values stored before the batch ran. This operation itself cannot be undone.', {
        n: batch.products_changed,
        plural: batch.products_changed !== 1 ? 's' : '',
      }),
      confirmLabel: t('confirm.undo_batch_yes', 'Undo batch'),
      cancelLabel: t('confirm.undo_batch_no', 'Keep changes'),
      tone: 'destructive',
    });
    if (ok) undoMutation.mutate(batch.id_batch);
  };

  return (
    <PageCard>
      <PageHead
        title={t('history.title', 'History')}
        subtitle={listQuery.isLoading ? t('common.loading', 'Loading…') : t2('history.subtitle', '{n} operation{plural} recorded', { n: total.toLocaleString(), plural: total === 1 ? '' : 's' })}
        actions={
          <Button onClick={() => listQuery.refetch()} disabled={listQuery.isFetching}>
            {listQuery.isFetching ? `⟳ ${t('common.refresh', 'Refresh')}…` : t('history.refresh', '↻ Refresh')}
          </Button>
        }
      />

      <Card className="mb-3">
        <div className="flex flex-wrap items-center gap-2">
          <Select value={kind} onChange={(e) => { setKind(e.target.value as typeof kind); setPage(0); }} className="max-w-[160px]">
            <option value="all">{t('history.filter.all_kinds', 'All kinds')}</option>
            <option value="bulk">{t('history.filter.bulk', 'Bulk edits')}</option>
            <option value="ai">{t('history.filter.ai', 'AI batches')}</option>
          </Select>
          <Select value={status} onChange={(e) => { setStatus(e.target.value as typeof status); setPage(0); }} className="max-w-[160px]">
            <option value="all">{t('history.filter.all_statuses', 'All statuses')}</option>
            <option value="pending">{t('status.pending', 'pending')}</option>
            <option value="running">{t('status.running', 'running')}</option>
            <option value="success">{t('status.success', 'success')}</option>
            <option value="partial">{t('status.partial', 'partial')}</option>
            <option value="failed">{t('status.failed', 'failed')}</option>
            <option value="undone">{t('status.undone', 'undone')}</option>
          </Select>
          <Input
            placeholder={t('history.search_placeholder', 'Search by ID, summary, or employee…')}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="max-w-[320px]"
          />
          <div className="flex-1" />
          <span className="text-[11px] text-muted-foreground">{t2('history.page_of', 'Page {p} of {pages}', { p: page + 1, pages: totalPages })}</span>
          <Button size="sm" variant="ghost" disabled={page === 0} onClick={() => setPage(page - 1)}>
            {t('health.previous', '← Previous')}
          </Button>
          <Button size="sm" variant="ghost" disabled={page >= totalPages - 1} onClick={() => setPage(page + 1)}>
            {t('health.next', 'Next →')}
          </Button>
        </div>
      </Card>

      <Card>
        {listQuery.isLoading && <div className="text-[13px] text-muted-foreground py-8 text-center">{t('common.loading', 'Loading…')}</div>}
        {listQuery.isError && (
          <div className="text-[13px] text-red-700 py-6 text-center">{(listQuery.error as Error).message}</div>
        )}

        {!listQuery.isLoading && visible.length === 0 && (
          <div className="text-[13px] text-muted-foreground py-8 text-center">
            {total === 0 ? t('history.empty', 'No operations recorded yet.') : t('history.no_match', 'No rows match the current filters.')}
          </div>
        )}

        {visible.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-[13px]">
              <thead>
                <tr className="border-b border-border text-[10px] uppercase tracking-wide text-muted-foreground">
                  <th className="text-left px-2 py-2 w-8"></th>
                  <th className="text-left px-2 py-2 w-14">ID</th>
                  <th className="text-left px-2 py-2 w-24">{t('history.col.kind', 'Kind')}</th>
                  <th className="text-left px-2 py-2 w-28">{t('history.col.status', 'Status')}</th>
                  <th className="text-left px-2 py-2">{t('history.col.summary', 'Summary')}</th>
                  <th className="text-right px-2 py-2 w-28">{t('history.col.changed_failed', 'Changed / Failed')}</th>
                  <th className="text-left px-2 py-2 w-40">{t('history.col.started', 'Started')}</th>
                  <th className="text-left px-2 py-2 w-28">{t('history.col.employee', 'Employee')}</th>
                  <th className="text-right px-2 py-2 w-40">{t('history.col.actions', 'Actions')}</th>
                </tr>
              </thead>
              <tbody>
                {visible.map((b) => (
                  <BatchRow
                    key={b.id_batch}
                    batch={b}
                    expanded={expanded === b.id_batch}
                    onToggleExpand={() => setExpanded(expanded === b.id_batch ? null : b.id_batch)}
                    onUndo={() => handleUndo(b)}
                    undoing={undoMutation.isPending && undoMutation.variables === b.id_batch}
                  />
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </PageCard>
  );
}

function BatchRow({
  batch,
  expanded,
  onToggleExpand,
  onUndo,
  undoing,
}: {
  batch: BatchSummary;
  expanded: boolean;
  onToggleExpand: () => void;
  onUndo: () => void;
  undoing: boolean;
}) {
  // Undo drives off the massedit_log — works for both bulk batches and AI
  // batches where at least one accepted run wrote a field change.
  const canUndo =
    (batch.kind === 'bulk_edit' || batch.kind === 'ai_batch') &&
    (batch.status === 'success' || batch.status === 'partial') &&
    batch.has_undoable_changes;

  return (
    <>
      <tr className="border-b border-border hover:bg-muted/40">
        <td className="px-2 py-2 align-top">
          <button
            type="button"
            onClick={onToggleExpand}
            className="text-[11px] text-muted-foreground hover:text-slate-900 w-5 h-5 flex items-center justify-center"
            title={expanded ? 'Collapse' : 'Expand'}
          >
            {expanded ? '▾' : '▸'}
          </button>
        </td>
        <td className="px-2 py-2 align-top font-mono text-muted-foreground">#{batch.id_batch}</td>
        <td className="px-2 py-2 align-top">
          <KindBadge kind={batch.kind} />
        </td>
        <td className="px-2 py-2 align-top">
          <Badge variant={STATUS_TONE[batch.status] ?? 'muted'}>{t('status.' + batch.status, batch.status)}</Badge>
        </td>
        <td className="px-2 py-2 align-top min-w-0">
          <div className="font-medium text-slate-900 truncate">{batch.summary}</div>
          <div className="text-[11px] text-muted-foreground truncate">{batch.scope_summary}</div>
        </td>
        <td className="px-2 py-2 align-top text-right whitespace-nowrap">
          <span className="text-green-700 font-semibold">{batch.products_changed}</span>
          {batch.products_failed > 0 && (
            <> / <span className="text-red-700 font-semibold">{batch.products_failed}</span></>
          )}
          <div className="text-[10px] text-muted-foreground">of {batch.products_matched}</div>
        </td>
        <td className="px-2 py-2 align-top text-muted-foreground whitespace-nowrap">
          {formatDate(batch.started_at)}
        </td>
        <td className="px-2 py-2 align-top text-muted-foreground truncate">
          {batch.employee_name || '—'}
        </td>
        <td className="px-2 py-2 align-top text-right whitespace-nowrap">
          {canUndo && (
            <Button size="sm" variant="destructive" onClick={onUndo} disabled={undoing}>
              {undoing ? t('history.undoing', 'Undoing…') : t('history.undo_batch', '↶ Undo')}
            </Button>
          )}
          {batch.status === 'undone' && <span className="text-[11px] text-muted-foreground">{t('status.undone', 'undone')}</span>}
        </td>
      </tr>
      {expanded && (
        <tr className="bg-muted/20">
          <td colSpan={9} className="px-2 py-3">
            <BatchDetailView idBatch={batch.id_batch} />
          </td>
        </tr>
      )}
    </>
  );
}

function KindBadge({ kind }: { kind: BatchKind }) {
  if (kind === 'bulk_edit') return <Badge variant="primary">{t('history.kind.bulk', '📝 Bulk')}</Badge>;
  if (kind === 'ai_batch')  return <Badge variant="success">{t('history.kind.ai', '🤖 AI')}</Badge>;
  return <Badge variant="muted">{kind || '—'}</Badge>;
}

function BatchDetailView({ idBatch }: { idBatch: number }) {
  const query = useQuery({
    queryKey: ['history', 'detail', idBatch],
    queryFn: () => historyApi.detail(idBatch),
  });

  if (query.isLoading) return <div className="text-[12px] text-muted-foreground py-2">{t('common.loading', 'Loading…')}</div>;
  if (query.isError)   return <div className="text-[12px] text-red-700 py-2">{(query.error as Error).message}</div>;
  const b = query.data;
  if (!b) return null;

  return (
    <div className="flex flex-col gap-3">
      {b.kind === 'bulk_edit' && b.logs && <BulkLogTable rows={b.logs} />}
      {b.kind === 'ai_batch'  && b.runs && <AiRunsTable  rows={b.runs} />}
      {b.kind === 'ai_batch'  && b.logs && b.logs.length > 0 && (
        <>
          <div className="text-[11px] uppercase tracking-wide text-muted-foreground font-semibold pt-2">
            Applied changes ({b.logs.length})
          </div>
          <BulkLogTable rows={b.logs} />
        </>
      )}
    </div>
  );
}

function BulkLogTable({ rows }: { rows: BulkLogRow[] }) {
  if (rows.length === 0) {
    return <div className="text-[12px] text-muted-foreground">No log entries recorded.</div>;
  }
  return (
    <div className="overflow-x-auto border border-border rounded-md bg-white">
      <table className="w-full text-[12px]">
        <thead>
          <tr className="border-b border-border bg-muted/50 text-[10px] uppercase tracking-wide text-muted-foreground">
            <th className="text-left px-2 py-1.5 w-14">Prod.</th>
            <th className="text-left px-2 py-1.5 w-14">Lang</th>
            <th className="text-left px-2 py-1.5 w-36">Field</th>
            <th className="text-left px-2 py-1.5">Old</th>
            <th className="text-left px-2 py-1.5">New</th>
            <th className="text-left px-2 py-1.5 w-20">Status</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.id_log} className="border-b border-border last:border-0">
              <td className="px-2 py-1.5 font-mono text-muted-foreground">#{r.id_product}</td>
              <td className="px-2 py-1.5 text-muted-foreground">{r.id_lang ?? 'all'}</td>
              <td className="px-2 py-1.5 font-medium">{r.field}</td>
              <td className="px-2 py-1.5">
                <span className="bg-red-50 text-red-900 px-1.5 py-0.5 rounded line-through break-words">
                  {r.old || '(empty)'}
                </span>
              </td>
              <td className="px-2 py-1.5">
                <span className="bg-green-50 text-green-900 px-1.5 py-0.5 rounded break-words">
                  {r.new || '(empty)'}
                </span>
              </td>
              <td className="px-2 py-1.5">
                <Badge variant={r.status === 'changed' ? 'success' : r.status === 'failed' ? 'destructive' : 'muted'}>
                  {r.status}
                </Badge>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function AiRunsTable({ rows }: { rows: AiRunRow[] }) {
  if (rows.length === 0) {
    return <div className="text-[12px] text-muted-foreground">No runs recorded.</div>;
  }
  const totalCost   = rows.reduce((s, r) => s + (r.cost_usd ?? 0), 0);
  const totalTokens = rows.reduce((s, r) => s + (r.tokens_in ?? 0) + (r.tokens_out ?? 0), 0);
  return (
    <div className="flex flex-col gap-2">
      <div className="text-[11px] text-muted-foreground">
        {rows.length} run{rows.length === 1 ? '' : 's'} · {totalTokens.toLocaleString()} total tokens · ${totalCost.toFixed(4)} total
      </div>
      <div className="overflow-x-auto border border-border rounded-md bg-white">
        <table className="w-full text-[12px]">
          <thead>
            <tr className="border-b border-border bg-muted/50 text-[10px] uppercase tracking-wide text-muted-foreground">
              <th className="text-left px-2 py-1.5 w-14">Prod.</th>
              <th className="text-left px-2 py-1.5">Name</th>
              <th className="text-left px-2 py-1.5">Output</th>
              <th className="text-right px-2 py-1.5 w-20">Tokens</th>
              <th className="text-right px-2 py-1.5 w-20">Cost</th>
              <th className="text-left px-2 py-1.5 w-20">Status</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id_run} className="border-b border-border last:border-0">
                <td className="px-2 py-1.5 font-mono text-muted-foreground">#{r.id_product}</td>
                <td className="px-2 py-1.5 font-medium truncate max-w-[200px]">
                  {r.product_name || <em className="text-muted-foreground">(no name)</em>}
                </td>
                <td className="px-2 py-1.5 break-words max-w-[360px]">
                  {r.error
                    ? <span className="text-red-700">{r.error}</span>
                    : r.output
                      ? <span className="text-slate-700">{truncate(r.output, 200)}</span>
                      : <em className="text-muted-foreground">(empty)</em>}
                </td>
                <td className="px-2 py-1.5 text-right text-muted-foreground whitespace-nowrap">
                  {r.tokens_in !== null && r.tokens_out !== null
                    ? `${r.tokens_in} → ${r.tokens_out}`
                    : '—'}
                </td>
                <td className="px-2 py-1.5 text-right text-muted-foreground">
                  {r.cost_usd !== null ? `$${r.cost_usd.toFixed(4)}` : '—'}
                </td>
                <td className="px-2 py-1.5">
                  <Badge variant={r.status === 'accepted' || r.status === 'success' ? 'success' : r.status === 'failed' ? 'destructive' : 'muted'}>
                    {r.status}
                  </Badge>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function formatDate(s: string): string {
  if (!s) return '—';
  const d = new Date(s.replace(' ', 'T') + 'Z');
  if (isNaN(d.getTime())) return s;
  return d.toLocaleString();
}

function truncate(s: string, n: number): string {
  if (s.length <= n) return s;
  return s.slice(0, n) + '…';
}
