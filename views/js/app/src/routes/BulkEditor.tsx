import { useEffect, useRef, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import PageHead from '../components/PageHead';
import PageCard from '../components/PageCard';
import Card from '../components/Card';
import SectionHeader from '../components/SectionHeader';
import Button from '../components/Button';
import Badge from '../components/Badge';
import ActionBuilder from '../components/ActionBuilder';
import ConditionGroupView from '../components/conditions/ConditionGroupView';
import MatchingProducts from '../components/conditions/MatchingProducts';
import QueryPreview from '../components/conditions/QueryPreview';
import PreviewPanel from '../components/bulk/PreviewPanel';
import GlobalLanguageTabs from '../components/bulk/GlobalLanguageTabs';
import ActionSummarySidebar from '../components/bulk/ActionSummarySidebar';
import { useToast } from '../components/Toast';
import { useConfirm } from '../components/ConfirmDialog';
import { conditionsApi, emptyTree, type FieldCatalog, type TreeSpec } from '../lib/conditions';
import { segmentsApi, isTreeSegment } from '../lib/segments';
import { Input } from '../components/Form';
import { t, t2 } from '../lib/i18n';
import {
  bulkApi,
  type BulkAction,
  type BulkBatchStatus,
  type PreviewResponse,
} from '../lib/bulk';
import { cn } from '../lib/utils';
import { api } from '../lib/api';

type Step = 1 | 2 | 3 | 4;

export default function BulkEditor() {
  const toast = useToast();
  const location = useLocation();

  const [step, setStep] = useState<Step>(1);
  const [tree, setTree] = useState<TreeSpec>(emptyTree());
  const [scope, setScope] = useState<'single' | 'group' | 'all'>('single');
  const [actions, setActions] = useState<BulkAction[]>([]);
  const [globalLang, setGlobalLang] = useState<number | 'all'>('all');
  const [preview, setPreview] = useState<PreviewResponse | null>(null);
  const [activeBatchId, setActiveBatchId] = useState<number | null>(null);
  const [dryRun, setDryRun] = useState(false);
  const [handoff, setHandoff] = useState<{ productIds: number[]; label: string; source: string } | null>(null);

  // Handoff from Content Health (or any other screen) — a pre-computed list of
  // product IDs to operate on, bypassing Step 1's condition builder entirely.
  useEffect(() => {
    const raw = (location.state as { handoff?: { productIds: number[]; label: string; source: string } } | null)?.handoff;
    if (raw && Array.isArray(raw.productIds) && raw.productIds.length > 0) {
      setHandoff({ productIds: raw.productIds, label: raw.label, source: raw.source });
      setStep(2);
      // Clear state from history so a refresh doesn't re-apply it.
      window.history.replaceState({}, '');
    }
  }, [location.state]);

  // Resume an interrupted batch from History — jump straight into the runner.
  useEffect(() => {
    const resumeId = (location.state as { resumeBatchId?: number } | null)?.resumeBatchId;
    if (typeof resumeId === 'number' && resumeId > 0) {
      setActiveBatchId(resumeId);
      window.history.replaceState({}, '');
    }
  }, [location.state]);

  const catalogQuery = useQuery({
    queryKey: ['field-catalog'],
    queryFn: conditionsApi.fieldCatalog,
  });

  const fieldsQuery = useQuery({
    queryKey: ['bulk', 'fields'],
    queryFn: bulkApi.fields,
  });

  const countQuery = useQuery({
    queryKey: ['matching-count-main', tree],
    queryFn: () => segmentsApi.count(tree as never),
    enabled: !!catalogQuery.data,
  });

  const plannedCount = countQuery.data ?? 0;

  const previewMutation = useMutation({
    mutationFn: () => {
      if (actions.length === 0) throw new Error('Add at least one action');
      const payload = {
        actions,
        id_lang: globalLang === 'all' ? undefined : globalLang,
        ...(handoff
          ? { product_ids: handoff.productIds }
          : { filter_spec: tree as never }),
      };
      return bulkApi.preview(payload);
    },
    onSuccess: (res) => {
      setPreview(res);
      setStep(3);
    },
    onError: (e: Error) => toast.show(e.message || 'Preview failed', 'error'),
  });

  const createBatchMutation = useMutation({
    mutationFn: () =>
      bulkApi.createBatch({
        actions,
        id_lang: globalLang === 'all' ? undefined : globalLang,
        mode: dryRun ? 'dry_run' : 'apply',
        ...(handoff
          ? { product_ids: handoff.productIds }
          : { filter_spec: tree as never }),
      }),
    onSuccess: (batch) => {
      setActiveBatchId(batch.id_batch);
      setStep(4);
      toast.show(`Batch #${batch.id_batch} started`, 'info');
    },
    onError: (e: Error) => toast.show(e.message || 'Could not start batch', 'error'),
  });

  if (activeBatchId !== null) {
    return (
      <PageCard>
        <BatchRunner
          idBatch={activeBatchId}
          onExit={() => {
            setActiveBatchId(null);
            setPreview(null);
            setStep(1);
            setActions([]);
            setTree(emptyTree());
            setHandoff(null);
          }}
        />
      </PageCard>
    );
  }

  const canGoStep2 = plannedCount > 0 || !!handoff;
  const aiGenerateMissingPrompt = actions.some((a) => a.operator === 'ai_generate' && !a.prompt_id);
  const copyFromMissingSource   = actions.some((a) => a.operator === 'copy_from'   && !a.source_field);
  const canGoStep3 =
    actions.length > 0 &&
    actions.some((a) => a.operator !== 'skip') &&
    !aiGenerateMissingPrompt &&
    !copyFromMissingSource;

  return (
    <PageCard>
      <PageHead
        title={t('bulk.title', 'Bulk Editor')}
        actions={
          step === 1 ? (
            <SegmentBar tree={tree} onLoadTree={(tr) => setTree(tr)} />
          ) : null
        }
      />

      <Stepper step={step} />

      {step === 1 && (
        <>
          {catalogQuery.isLoading && (
            <Card><div className="text-muted-foreground">Loading field catalog…</div></Card>
          )}
          {catalogQuery.data && (
            <Step1ConditionBuilder
              catalog={catalogQuery.data}
              tree={tree}
              onTreeChange={setTree}
              scope={scope}
              onScopeChange={setScope}
            />
          )}
        </>
      )}

      {step === 2 && handoff && (
        <div className="bg-blue-50 border border-blue-200 rounded-md px-3 py-2 mb-3 flex items-center justify-between gap-3 text-[12px]">
          <div>
            <span className="text-slate-700">{t2('bulk.handoff_banner', 'From Content Health: {label}', { label: handoff.label })}</span>
          </div>
          <button
            type="button"
            className="text-[11px] text-primary hover:underline"
            onClick={() => { setHandoff(null); setStep(1); }}
          >
            {t('bulk.handoff_clear', 'Clear — build filter manually')}
          </button>
        </div>
      )}

      {step === 2 && (
        <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_320px] gap-4 mb-4">
          <Card className="min-w-0">
            <SectionHeader
              title={t('bulk.section_actions_title', 'Step 2 · Define actions')}
              subtitle={t('bulk.section_actions_subtitle', 'Add one entry per field. Empty value → skipped.')}
            />
            <GlobalLanguageTabs value={globalLang} onChange={setGlobalLang} className="mb-3" />
            {fieldsQuery.isLoading && <div className="text-muted-foreground text-[13px]">Loading fields…</div>}
            {fieldsQuery.data && (
              <ActionBuilder fields={fieldsQuery.data} value={actions} onChange={setActions} globalLang={globalLang} />
            )}
          </Card>
          <div className="min-w-0">
            {fieldsQuery.data && (
              <ActionSummarySidebar
                fields={fieldsQuery.data}
                actions={actions}
                globalLang={globalLang}
                onScrollTo={(idx) => {
                  const el = document.querySelector(`[data-action-idx="${idx}"]`) as HTMLElement | null;
                  el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                  el?.classList.add('ring-2', 'ring-primary');
                  setTimeout(() => el?.classList.remove('ring-2', 'ring-primary'), 1500);
                }}
              />
            )}
          </div>
        </div>
      )}

      {step === 3 && preview && (
        <Card className="mb-4">
          <SectionHeader
            title={t('bulk.section_review_title', 'Step 3 · Review changes')}
            subtitle={t2('bulk.section_review_subtitle', '{willChange} of {total} products will change', {
              willChange: preview.summary.will_change.toLocaleString(),
              total: preview.summary.total_scope.toLocaleString(),
            })}
          />
          <PreviewPanel
            data={preview}
            onDownloadCsv={() => downloadPreviewCsv(preview)}
          />
        </Card>
      )}

      <div className="flex items-center justify-between pt-4 border-t border-border">
        <Button
          onClick={() => setStep(Math.max(1, step - 1) as Step)}
          disabled={step === 1 || previewMutation.isPending || createBatchMutation.isPending}
        >
          ← Back
        </Button>

        <div className="text-[12px] text-muted-foreground">
          {step === 1 && (plannedCount > 0
            ? t2('bulk.products_in_scope', '{n} products in scope', { n: plannedCount.toLocaleString() })
            : t('bulk.add_conditions_or_all', 'Add conditions or leave empty for all products'))}
          {step === 2 && (
            aiGenerateMissingPrompt
              ? <span className="text-red-700 font-medium">{t('bulk.warn_ai_no_prompt', '⚠ An AI generate action is missing a prompt selection')}</span>
              : copyFromMissingSource
                ? <span className="text-red-700 font-medium">{t('bulk.warn_copyfrom_no_source', '⚠ A Copy-from action is missing a source field')}</span>
                : handoff
                  ? t2('bulk.handoff_status', '{n} products (handoff) · {actions} active action(s)', {
                      n: handoff.productIds.length.toLocaleString(),
                      actions: actions.filter((a) => a.operator !== 'skip').length,
                    })
                  : t2('bulk.active_actions', '{n} active action(s)', { n: actions.filter((a) => a.operator !== 'skip').length })
          )}
          {step === 3 && preview && (
            t2('bulk.preview_status', '{willChange} of {total} will change', {
              willChange: preview.summary.will_change.toLocaleString(),
              total: preview.summary.total_scope.toLocaleString(),
            })
            + (preview.summary.with_flags > 0
                ? ' · ' + t2('bulk.preview_attention', '{n} need attention', { n: preview.summary.with_flags })
                : '')
          )}
        </div>

        <div className="flex gap-2">
          {step === 1 && (
            <Button variant="primary" disabled={!canGoStep2} onClick={() => setStep(2)}>
              {t('bulk.next_actions', 'Next: actions →')}
            </Button>
          )}
          {step === 2 && (
            <Button
              variant="primary"
              disabled={!canGoStep3 || previewMutation.isPending}
              onClick={() => previewMutation.mutate()}
            >
              {previewMutation.isPending ? t('bulk.computing', 'Computing…') : t('bulk.next_preview', 'Next: preview →')}
            </Button>
          )}
          {step === 3 && (
            <>
              <label className="flex items-center gap-1.5 text-[12px] text-muted-foreground mr-2 cursor-pointer" title={t('bulk.dry_run_tooltip', 'Dry-run logs every change to History without writing to products.')}>
                <input type="checkbox" checked={dryRun} onChange={(e) => setDryRun(e.target.checked)} />
                <span>{t('bulk.dry_run_label', 'Dry-run (no writes)')}</span>
              </label>
              <Button
                variant="primary"
                disabled={createBatchMutation.isPending}
                onClick={() => createBatchMutation.mutate()}
              >
                {createBatchMutation.isPending
                  ? t('bulk.starting', 'Starting…')
                  : (dryRun
                      ? t2('bulk.dry_run_to_n', '🧪 Dry-run on {n} products', { n: preview?.summary.will_change.toLocaleString() ?? 0 })
                      : t2('bulk.apply_to_n', '🚀 Apply to {n} products', { n: preview?.summary.will_change.toLocaleString() ?? 0 }))}
              </Button>
            </>
          )}
        </div>
      </div>
    </PageCard>
  );
}

// =========================================================================
// Step 1 — nested condition builder + matching products sidebar
// =========================================================================

function Step1ConditionBuilder({
  catalog, tree, onTreeChange, scope, onScopeChange,
}: {
  catalog: FieldCatalog;
  tree: TreeSpec;
  onTreeChange: (t: TreeSpec) => void;
  scope: 'single' | 'group' | 'all';
  onScopeChange: (s: 'single' | 'group' | 'all') => void;
}) {
  return (
    <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_320px] gap-4 mb-4">
      <div className="flex flex-col gap-4 min-w-0">
        <Card>
          <SectionHeader
            title={t('bulk.section_filter_title', 'Filter conditions')}
            subtitle={t('bulk.section_filter_subtitle', 'Combine conditions with AND/OR. Use groups for nested logic.')}
          />
          <ConditionGroupView
            catalog={catalog}
            group={tree}
            onChange={(g) => onTreeChange(g)}
          />

          <div className="mt-4">
            <QueryPreview tree={tree} fields={catalog.fields} />
          </div>

          <div className="flex items-center justify-between gap-2 mt-4 pt-3 border-t border-border">
            <label className="flex items-center gap-2 text-[12px] text-muted-foreground">
              {t('bulk.scope_label', 'Scope:')}
              <select
                className="px-2 py-1 border border-border rounded text-[12px] bg-white"
                value={scope}
                onChange={(e) => onScopeChange(e.target.value as 'single' | 'group' | 'all')}
              >
                <option value="single">{t('bulk.scope_single', 'Current shop only')}</option>
                <option value="group">{t('bulk.scope_group', 'Current shop group')}</option>
                <option value="all">{t('bulk.scope_all', 'All shops')}</option>
              </select>
            </label>
          </div>
        </Card>
      </div>

      <div className="min-w-0">
        <MatchingProducts tree={tree} />
      </div>
    </div>
  );
}

// =========================================================================
// Stepper
// =========================================================================

function Stepper({ step }: { step: Step }) {
  const steps = [
    { n: 1, label: t('bulk.steps.filter',  'Filter products') },
    { n: 2, label: t('bulk.steps.actions', 'Define actions') },
    { n: 3, label: t('bulk.steps.preview', 'Preview diff') },
    { n: 4, label: t('bulk.steps.apply',   'Apply') },
  ];
  return (
    <div className="flex items-center gap-2 mb-4">
      {steps.map((s, i) => (
        <div key={s.n} className="flex items-center gap-2">
          <div
            className={cn(
              'w-7 h-7 rounded-full flex items-center justify-center text-[12px] font-semibold',
              s.n < step ? 'bg-green-600 text-white'
                : s.n === step ? 'bg-primary text-white'
                : 'bg-muted text-muted-foreground'
            )}
          >
            {s.n < step ? '✓' : s.n}
          </div>
          <span
            className={cn(
              'text-[13px]',
              s.n === step ? 'font-semibold text-slate-900' : 'text-muted-foreground'
            )}
          >
            {s.label}
          </span>
          {i < steps.length - 1 && <div className="w-6 h-px bg-border mx-1" />}
        </div>
      ))}
    </div>
  );
}

// =========================================================================
// CSV export of the preview
// =========================================================================

function downloadPreviewCsv(preview: PreviewResponse): void {
  const rows: string[][] = [['id_product', 'name', 'reference', 'field', 'old', 'new', 'flags']];
  for (const d of preview.diffs) {
    if (d.changes.length === 0) {
      rows.push([String(d.id_product), d.name, d.reference, '', '', '', 'no_change']);
      continue;
    }
    for (const c of d.changes) {
      rows.push([
        String(d.id_product),
        d.name,
        d.reference,
        c.field,
        c.old,
        c.new,
        c.flags.join('|'),
      ]);
    }
  }
  const csv = rows.map((row) => row.map(csvCell).join(',')).join('\r\n');
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `smartbulk-preview-${new Date().toISOString().replace(/[:.]/g, '-')}.csv`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function csvCell(v: string): string {
  if (v == null) return '';
  const s = String(v);
  if (/[",\r\n]/.test(s)) return '"' + s.replace(/"/g, '""') + '"';
  return s;
}

// =========================================================================
// Batch runner (step 4) — unchanged for Phase 1
// =========================================================================

function BatchRunner({ idBatch, onExit }: { idBatch: number; onExit: () => void }) {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const [autoProcess, setAutoProcess] = useState(true);
  const [undoing, setUndoing] = useState(false);
  const processingRef = useRef(false);

  const batchQuery = useQuery({
    queryKey: ['bulk', 'batch', idBatch],
    queryFn: () => bulkApi.getBatch(idBatch, true),
    refetchInterval: (q) => {
      const d = q.state.data;
      if (!d) return 2000;
      if (d.remaining <= 0) return false;
      return d.total > 5000 ? 5000 : 2000; // ease off polling on big batches
    },
  });

  const settingsQuery = useQuery({
    queryKey: ['settings'],
    queryFn: () => api.get<{ ok: boolean; settings: { bulk_chunk: number } }>('/modules/smartbulk/api/settings'),
    staleTime: 60000,
  });
  const chunkSize = settingsQuery.data?.settings?.bulk_chunk ?? 100;

  const processMutation = useMutation({
    mutationFn: () => bulkApi.processNext(idBatch, chunkSize),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['bulk', 'batch', idBatch] }),
    onError: (e: Error) => toast.show(e.message || 'Processing failed', 'error'),
  });

  const cancelMutation = useMutation({
    mutationFn: () => bulkApi.cancel(idBatch),
    onSuccess: () => {
      toast.show('Batch stopped', 'info');
      setAutoProcess(false);
      qc.invalidateQueries({ queryKey: ['bulk', 'batch', idBatch] });
    },
    onError: (e: Error) => toast.show(e.message || 'Cancel failed', 'error'),
  });

  const resumeMutation = useMutation({
    mutationFn: () => bulkApi.resume(idBatch),
    onSuccess: () => {
      setAutoProcess(true);
      qc.invalidateQueries({ queryKey: ['bulk', 'batch', idBatch] });
    },
    onError: (e: Error) => toast.show(e.message || 'Resume failed', 'error'),
  });

  useEffect(() => {
    if (!autoProcess) return;
    const d = batchQuery.data;
    if (!d || d.remaining <= 0 || (d.status !== 'pending' && d.status !== 'running')) return;
    if (processMutation.isPending || processingRef.current) return;
    processingRef.current = true;
    processMutation.mutate(undefined, { onSettled: () => { processingRef.current = false; } });
  }, [autoProcess, batchQuery.data, processMutation]);

  const batch = batchQuery.data;
  if (!batch) return <div className="text-muted-foreground">Loading batch…</div>;

  const progressPct = batch.total === 0 ? 0 : Math.round((batch.done / batch.total) * 100);
  const running = (batch.status === 'pending' || batch.status === 'running') && batch.remaining > 0;

  const handleUndo = async () => {
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
    if (!ok) return;
    setUndoing(true);
    try {
      const res = await bulkApi.undo(idBatch);
      toast.show(`Restored ${res.restored} changes`, 'success');
      await qc.invalidateQueries({ queryKey: ['bulk', 'batch', idBatch] });
    } catch (e) {
      toast.show((e as Error).message, 'error');
    } finally {
      setUndoing(false);
    }
  };

  const handleCancel = async () => {
    const ok = await confirm({
      title: t('confirm.stop_batch_title', 'Stop this batch?'),
      message: t2('confirm.stop_batch_msg', 'Already-processed products stay applied (and you can still undo afterwards). The remaining {n} won\'t be touched.', { n: batch.remaining }),
      confirmLabel: t('confirm.stop_batch_yes', 'Stop batch'),
      cancelLabel: t('common.cancel', 'Cancel'),
      tone: 'warning',
    });
    if (ok) cancelMutation.mutate();
  };

  return (
    <>
      <PageHead
        title={`Bulk Batch #${batch.id_batch}`}
        subtitle={<>{batch.products_changed} changed · {batch.products_failed} failed · {batch.total} total</>}
        actions={
          <>
            {running && (
              <Button onClick={handleCancel} variant="destructive" disabled={cancelMutation.isPending}>
                {cancelMutation.isPending ? t('bulk.stopping', 'Stopping…') : t('bulk.stop_batch', '■ Stop batch')}
              </Button>
            )}
            {!running && batch.remaining > 0 && batch.status !== 'undone' && (
              <Button onClick={() => resumeMutation.mutate()} variant="primary" disabled={resumeMutation.isPending}>
                {resumeMutation.isPending ? t('bulk.resuming', 'Resuming…') : t('bulk.resume_batch', '▶ Resume')}
              </Button>
            )}
            {batch.status !== 'undone' && batch.done > 0 && (
              <Button onClick={handleUndo} variant="destructive" disabled={undoing || running}>
                {undoing ? t('bulk.undoing', 'Undoing…') : t('bulk.undo_batch', '↶ Undo batch')}
              </Button>
            )}
            <Button onClick={onExit}>{t('bulk.back_to_editor', '← Back to Bulk Editor')}</Button>
          </>
        }
      />
      <Card className="mb-4">
        <SectionHeader
          title={t('bulk.progress_title', 'Progress')}
          subtitle={t2('bulk.progress_subtitle', '{done} of {total} processed · {remaining} remaining', {
            done: batch.done, total: batch.total, remaining: batch.remaining,
          })}
          action={<BatchStatusBadge status={batch.status} />}
        />
        <div className="h-2 bg-muted rounded-full overflow-hidden">
          <div className="h-full bg-primary transition-all" style={{ width: `${progressPct}%` }} />
        </div>
        <div className="mt-2 flex items-center justify-between text-[12px]">
          <div className="text-muted-foreground">{t2('bulk.percent_complete', '{pct}% complete', { pct: progressPct })}</div>
          <div className="flex items-center gap-3">
            {running && (
              <label className="flex items-center gap-2 cursor-pointer">
                <input
                  type="checkbox"
                  checked={autoProcess}
                  onChange={(e) => setAutoProcess(e.target.checked)}
                  className="accent-primary"
                />
                <span className="text-slate-700">{t('bulk.auto_process', 'Auto-process')}</span>
              </label>
            )}
            {running && !autoProcess && (
              <Button size="sm" onClick={() => processMutation.mutate()} disabled={processMutation.isPending}>
                {processMutation.isPending ? '…' : t2('bulk.process_next', 'Process next {n}', { n: chunkSize })}
              </Button>
            )}
          </div>
        </div>
      </Card>

      <Card>
        <SectionHeader title="Processed items" subtitle="Recent products modified by this batch" />
        {!batch.items || batch.items.length === 0 ? (
          <div className="text-muted-foreground text-[13px]">No items processed yet.</div>
        ) : (
          <div className="border border-border rounded-md overflow-hidden">
            <table className="w-full text-[13px]">
              <thead>
                <tr className="text-left text-[11px] uppercase tracking-wide text-muted-foreground bg-muted">
                  <th className="px-3 py-2 font-semibold w-24">Product</th>
                  <th className="px-3 py-2 font-semibold">Field changes</th>
                  <th className="px-3 py-2 font-semibold w-28">Status</th>
                  <th className="px-3 py-2 font-semibold w-36">When</th>
                </tr>
              </thead>
              <tbody>
                {batch.items.slice(0, 200).map((it) => (
                  <tr key={it.id_product} className="border-t border-border hover:bg-slate-50">
                    <td className="px-3 py-2 font-mono text-[12px]">#{it.id_product}</td>
                    <td className="px-3 py-2">{it.change_count}</td>
                    <td className="px-3 py-2">
                      {it.failed > 0 ? (
                        <Badge variant="destructive">{it.failed} failed</Badge>
                      ) : (
                        <Badge variant="success">✓ {it.changed} changed</Badge>
                      )}
                    </td>
                    <td className="px-3 py-2 text-muted-foreground text-[12px]">{it.last_change}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </>
  );
}

function BatchStatusBadge({ status }: { status: BulkBatchStatus['status'] }) {
  if (status === 'running') return <Badge variant="primary">running</Badge>;
  if (status === 'success') return <Badge variant="success">✓ done</Badge>;
  if (status === 'partial') return <Badge variant="warning">partial</Badge>;
  if (status === 'failed')  return <Badge variant="destructive">failed</Badge>;
  if (status === 'undone')  return <Badge variant="muted">undone</Badge>;
  return <Badge variant="muted">{status}</Badge>;
}

// =========================================================================
// SegmentBar — Load saved / Save as new in Step 1 header
// =========================================================================

function SegmentBar({ tree, onLoadTree }: { tree: TreeSpec; onLoadTree: (t: TreeSpec) => void }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [mode, setMode] = useState<'idle' | 'load' | 'save'>('idle');
  const [name, setName] = useState('');

  const listQuery = useQuery({
    queryKey: ['segments', 'list'],
    queryFn: () => segmentsApi.list(),
    enabled: mode === 'load',
  });

  const createMutation = useMutation({
    mutationFn: (n: string) => segmentsApi.create({ name: n, conditions: tree as unknown }),
    onSuccess: (s) => {
      toast.show(`Segment "${s.name}" saved`, 'success');
      qc.invalidateQueries({ queryKey: ['segments', 'list'] });
      setMode('idle'); setName('');
    },
    onError: (e: Error) => toast.show(e.message || 'Could not save segment', 'error'),
  });

  const hasConditions = tree.conditions.length > 0;

  if (mode === 'save') {
    return (
      <div className="flex items-center gap-2">
        <Input
          placeholder="Segment name"
          value={name}
          onChange={(e) => setName(e.target.value)}
          autoFocus
          className="max-w-[200px]"
          onKeyDown={(e) => {
            if (e.key === 'Enter' && name.trim()) createMutation.mutate(name.trim());
            if (e.key === 'Escape') { setMode('idle'); setName(''); }
          }}
        />
        <Button
          variant="primary"
          onClick={() => createMutation.mutate(name.trim())}
          disabled={!name.trim() || createMutation.isPending}
        >
          {createMutation.isPending ? 'Saving…' : 'Save'}
        </Button>
        <Button variant="ghost" onClick={() => { setMode('idle'); setName(''); }}>Cancel</Button>
      </div>
    );
  }

  if (mode === 'load') {
    const treeSegments = (listQuery.data ?? []).filter((s) => isTreeSegment(s.conditions));
    return (
      <div className="relative">
        <Button onClick={() => setMode('idle')}>Close ▴</Button>
        <div className="absolute right-0 top-[calc(100%+4px)] z-20 bg-white border border-border rounded-md shadow-lg w-[320px] max-h-[360px] overflow-hidden flex flex-col">
          <div className="px-3 py-2 border-b border-border bg-muted/40">
            <div className="text-[12px] font-semibold">Load saved segment</div>
            <div className="text-[10px] text-muted-foreground">Only nested AND/OR segments (Bulk Editor format) are listed here.</div>
          </div>
          <div className="overflow-y-auto flex-1">
            {listQuery.isLoading && <div className="px-3 py-4 text-[12px] text-muted-foreground">Loading…</div>}
            {!listQuery.isLoading && treeSegments.length === 0 && (
              <div className="px-3 py-4 text-[12px] text-muted-foreground text-center">
                No saved Bulk-Editor segments yet.
              </div>
            )}
            {treeSegments.map((s) => (
              <button
                key={s.id_segment}
                type="button"
                onClick={() => { onLoadTree(s.conditions as TreeSpec); setMode('idle'); toast.show(`Loaded "${s.name}"`, 'info'); }}
                className="w-full text-left px-3 py-2 hover:bg-muted border-b border-border/50"
              >
                <div className="font-semibold text-[13px] truncate">{s.name}</div>
                {s.description && <div className="text-[11px] text-muted-foreground truncate">{s.description}</div>}
              </button>
            ))}
          </div>
        </div>
      </div>
    );
  }

  return (
    <>
      <Button onClick={() => setMode('load')}>{t('bulk.load_segment', 'Load saved segment ▾')}</Button>
      <Button
        onClick={() => setMode('save')}
        disabled={!hasConditions}
        title={hasConditions ? undefined : t('bulk.save_segment_disabled', 'Add at least one condition to save')}
      >
        {t('bulk.save_segment', 'Save as segment')}
      </Button>
    </>
  );
}
