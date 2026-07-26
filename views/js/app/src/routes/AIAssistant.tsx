import { useEffect, useMemo, useRef, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import PageHead from '../components/PageHead';
import PageCard from '../components/PageCard';
import Card from '../components/Card';
import SectionHeader from '../components/SectionHeader';
import Button from '../components/Button';
import Badge from '../components/Badge';
import { Field, Select } from '../components/Form';
import ScopePicker, { scopeToFilterSpec, type ScopeValue } from '../components/ScopePicker';
import ApprovalQueue from '../components/ApprovalQueue';
import { useToast } from '../components/Toast';
import { useConfirm } from '../components/ConfirmDialog';
import { promptsApi, TASK_TYPE_LABELS, type Prompt, type TaskType } from '../lib/prompts';
import {
  aiApi,
  productsApi,
  sortPromptsForTask,
  taskTypeSupportsAutoApply,
  taskTypeNeedsTargetField,
  taskTypeNeedsTargetLang,
  TARGET_FIELDS,
  type AiRun,
  type BatchStatus,
} from '../lib/ai';
import { segmentsApi, lookupsApi, type FilterSpec } from '../lib/segments';
import { cn } from '../lib/utils';
import { t, t2 } from '../lib/i18n';

const TASK_CARDS: { type: TaskType; emoji: string; blurb: string }[] = [
  { type: 'meta_title',       emoji: '🏷️', blurb: 'SEO title ≤ 60 chars' },
  { type: 'meta_description', emoji: '📝', blurb: 'SEO description ≤ 160 chars' },
  { type: 'short_desc',       emoji: '📋', blurb: 'Punchy 1–3 sentences' },
  { type: 'long_desc',        emoji: '📄', blurb: 'Full HTML description' },
  { type: 'translate',        emoji: '🌐', blurb: 'Cross-language (manual apply)' },
  { type: 'alt_text',         emoji: '🖼️', blurb: 'Image alt text' },
  { type: 'seo_rewrite',      emoji: '🔄', blurb: 'Improve existing text' },
  { type: 'tagging',          emoji: '🏷️', blurb: 'Suggest tags & categories' },
];

// Rough average cost per item, for "estimate before running" hints.
const COST_ESTIMATES: Record<string, number> = {
  'claude-haiku-4-5':  0.002,
  'claude-sonnet-4-6': 0.010,
  'claude-opus-4-7':   0.050,
  'gpt-4o-mini':       0.001,
  'gpt-4o':            0.008,
};

export default function AIAssistant() {
  const toast = useToast();

  const location = useLocation();
  const handoff = (location.state as { ai_handoff?: { productId: number; taskType: string } } | null)?.ai_handoff;

  const [taskType, setTaskType] = useState<TaskType>(
    (handoff?.taskType as TaskType) || 'meta_description'
  );
  const [selectedPromptId, setSelectedPromptId] = useState<number | null>(null);
  const [scope, setScope] = useState<ScopeValue>(() =>
    handoff?.productId
      ? { mode: 'single', single: { id_product: handoff.productId, name: '', reference: '', price: 0 } }
      : { mode: 'segment', customSpec: { filters: [] } }
  );
  const [targetField, setTargetField] = useState<string>('');
  const [targetLang, setTargetLang]   = useState<number | ''>('');

  // After mount, when we got a productId from handoff, fetch its real metadata
  // (name/reference/price) so the ProductPicker shows it nicely instead of a stub.
  useEffect(() => {
    if (!handoff?.productId) return;
    const id = handoff.productId;
    // Cleanup state so reload doesn't re-trigger prefill.
    window.history.replaceState({}, '');
    (async () => {
      try {
        const matches = await productsApi.search(String(id), 1);
        const found = matches.find((p) => p.id_product === id) ?? matches[0];
        if (found) setScope((prev) => ({ ...prev, mode: 'single', single: found }));
      } catch {
        /* keep stub */
      }
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const [singleResult, setSingleResult] = useState<AiRun | null>(null);
  const [activeBatchId, setActiveBatchId] = useState<number | null>(null);

  const promptsQuery = useQuery({
    queryKey: ['prompts', '', ''],
    queryFn: () => promptsApi.list(),
  });
  const promptsForTask = useMemo(
    () => (promptsQuery.data ? sortPromptsForTask(promptsQuery.data, taskType) : []),
    [promptsQuery.data, taskType]
  );

  useEffect(() => {
    if (promptsForTask.length === 0) { setSelectedPromptId(null); return; }
    if (!promptsForTask.some((p) => p.id_prompt === selectedPromptId)) {
      setSelectedPromptId(promptsForTask[0].id_prompt);
    }
  }, [promptsForTask, selectedPromptId]);

  const selectedPrompt: Prompt | null =
    promptsForTask.find((p) => p.id_prompt === selectedPromptId) ?? null;

  const filterSpec = useMemo<FilterSpec | null>(() => scopeToFilterSpec(scope), [scope]);

  const countQuery = useQuery({
    queryKey: ['segments', 'count', filterSpec],
    queryFn: () => (filterSpec ? segmentsApi.count(filterSpec) : Promise.resolve(0)),
    enabled: filterSpec !== null,
  });

  const plannedCount = scope.mode === 'single'
    ? (scope.single ? 1 : 0)
    : (countQuery.data ?? 0);

  // Live cost estimate from /ai/estimate when a prompt is selected. Falls back
  // to the per-model heuristic if the request fails / is in flight.
  const estimateQuery = useQuery({
    queryKey: ['ai', 'estimate', selectedPrompt?.id_prompt, plannedCount, scope.single?.id_product ?? null],
    queryFn: () => aiApi.estimate({
      id_prompt: selectedPrompt!.id_prompt,
      product_count: plannedCount,
      sample_product_id: scope.single?.id_product,
    }),
    enabled: !!selectedPrompt && plannedCount > 0,
    staleTime: 60_000,
  });
  const fallbackPerItem = selectedPrompt?.current_model ? (COST_ESTIMATES[selectedPrompt.current_model] ?? 0.005) : 0.005;
  const estTotalCost = estimateQuery.data?.total_cost_usd ?? plannedCount * fallbackPerItem;

  const singleMutation = useMutation({
    mutationFn: () => {
      if (!selectedPrompt) throw new Error('Pick a prompt first');
      if (!scope.single) throw new Error('Pick a product first');
      return aiApi.generate({
        id_prompt: selectedPrompt.id_prompt,
        id_product: scope.single.id_product,
        target_field: targetField || undefined,
        target_lang:  targetLang === '' ? undefined : targetLang,
      });
    },
    onSuccess: (run) => {
      setSingleResult(run);
      toast.show('Generated — review below', 'success');
    },
    onError: (e: Error) => toast.show(e.message || 'Generation failed', 'error'),
  });

  const batchCreateMutation = useMutation({
    mutationFn: () => {
      if (!selectedPrompt) throw new Error('Pick a prompt first');
      if (!filterSpec) throw new Error('Pick a scope first');
      return aiApi.createBatch({
        id_prompt: selectedPrompt.id_prompt,
        filter_spec: filterSpec,
        target_field: targetField || undefined,
        target_lang:  targetLang === '' ? undefined : targetLang,
      });
    },
    onSuccess: (status) => {
      setActiveBatchId(status.id_batch);
      toast.show(`Batch #${status.id_batch} started with ${status.total} products`, 'info');
    },
    onError: (e: Error) => toast.show(e.message || 'Could not start batch', 'error'),
  });

  if (activeBatchId !== null) {
    return (
      <PageCard>
        <BatchRunner
          idBatch={activeBatchId}
          onExit={() => setActiveBatchId(null)}
        />
      </PageCard>
    );
  }

  return (
    <PageCard>
      <PageHead
        title={t('ai.title', 'AI Assistant')}
        subtitle={t('ai.subtitle', 'Generate product content with Claude or OpenAI')}
      />

      {/* 1. Task chooser */}
      <Card padding="md" className="mb-4">
        <SectionHeader title={t('ai.choose_operation', '1. Choose operation')} size="sm" />
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
          {TASK_CARDS.map((card) => (
            <button
              key={card.type}
              type="button"
              onClick={() => setTaskType(card.type)}
              className={cn(
                'text-left border rounded-lg p-3 transition-colors',
                taskType === card.type
                  ? 'border-primary bg-primary-50'
                  : 'border-border bg-white hover:bg-muted'
              )}
            >
              <div className="text-[18px] mb-1">{card.emoji}</div>
              <div className="font-semibold text-[13px]">{t('task.' + card.type, TASK_TYPE_LABELS[card.type])}</div>
              <div className="text-[11px] text-muted-foreground">{card.blurb}</div>
            </button>
          ))}
        </div>
      </Card>

      {/* 2. Prompt */}
      <Card className="mb-4">
        <SectionHeader
          title={t2('ai.prompt_for', '2. Prompt for {task}', { task: t('task.' + taskType, TASK_TYPE_LABELS[taskType]) })}
          subtitle={
            promptsForTask.length === 0
              ? t('ai.no_prompts', 'No prompts for this task type yet — create one in Prompt Library.')
              : t2('ai.n_prompts_available', '{n} prompt{plural} available', { n: promptsForTask.length, plural: promptsForTask.length !== 1 ? 's' : '' })
          }
        />
        <Field label={t('ai.field_prompt', 'Prompt')}>
          <Select
            value={selectedPromptId ?? ''}
            onChange={(e) => setSelectedPromptId(Number(e.target.value) || null)}
            disabled={promptsForTask.length === 0}
          >
            {promptsForTask.length === 0 && <option>—</option>}
            {promptsForTask.map((p) => (
              <option key={p.id_prompt} value={p.id_prompt}>
                {p.name}
                {p.current_version_number ? ` (v${p.current_version_number})` : ''}
                {p.current_model ? ` — ${p.current_model}` : ''}
              </option>
            ))}
          </Select>
        </Field>

        <TaskTypeParams
          taskType={taskType}
          targetField={targetField}
          targetLang={targetLang}
          onTargetFieldChange={setTargetField}
          onTargetLangChange={setTargetLang}
        />
      </Card>

      {/* 3. Scope */}
      <Card className="mb-4">
        <SectionHeader
          title={t('ai.scope', '3. Scope')}
          subtitle={t('ai.scope_subtitle', 'Pick products to run for')}
          action={
            plannedCount > 0 ? (
              <>
                <Badge variant="primary">{t2('ai.scope_n_products', '{n} products', { n: plannedCount.toLocaleString() })}</Badge>
                {scope.mode !== 'single' && (
                  <Badge variant="muted">{t2('ai.scope_est_cost', '~${cost} est.', { cost: estTotalCost.toFixed(2) })}</Badge>
                )}
              </>
            ) : null
          }
        />
        <ScopePicker value={scope} onChange={setScope} />
      </Card>

      {/* Action footer */}
      <div className="flex items-center justify-end gap-2 pt-4 border-t border-border">
        <div className="text-[12px] text-muted-foreground mr-auto">
          {scope.mode === 'single'
            ? (scope.single
                ? t2('ai.scope_generate_once', 'Generate once for #{id}', { id: scope.single.id_product })
                : t('ai.scope_pick_product', 'Pick a product'))
            : plannedCount === 0
              ? t('ai.scope_pick_first', 'Pick a scope first')
              : t2('ai.scope_n_products', '{n} products', { n: plannedCount.toLocaleString() }) + ` · ${t2('ai.scope_est_cost', '~${cost} est.', { cost: estTotalCost.toFixed(2) })}`}
        </div>

        {scope.mode === 'single' ? (
          <Button
            variant="primary"
            disabled={!selectedPrompt || !scope.single || singleMutation.isPending}
            onClick={() => singleMutation.mutate()}
          >
            {singleMutation.isPending ? t('ai.generating', 'Generating…') : '✨ ' + t('ai.generate', 'Generate')}
          </Button>
        ) : (
          <Button
            variant="primary"
            disabled={!selectedPrompt || plannedCount === 0 || batchCreateMutation.isPending}
            onClick={() => batchCreateMutation.mutate()}
          >
            {batchCreateMutation.isPending ? t('bulk.starting', 'Starting…') : '🚀 ' + t('ai.start_batch', 'Start batch')}
          </Button>
        )}
      </div>

      {/* Single-result card (test mode) */}
      {scope.mode === 'single' && singleResult && (
        <div className="mt-4">
          <SingleResultCard
            run={singleResult}
            onAccept={async () => {
              try {
                await aiApi.accept(singleResult.id_run);
                toast.show('Applied to product', 'success');
                setSingleResult({ ...singleResult, status: 'accepted' });
              } catch (e) {
                toast.show((e as Error).message, 'error');
              }
            }}
            onReject={async () => {
              try {
                await aiApi.reject(singleResult.id_run);
                toast.show('Rejected', 'info');
                setSingleResult({ ...singleResult, status: 'rejected' });
              } catch (e) {
                toast.show((e as Error).message, 'error');
              }
            }}
            onRegenerate={() => singleMutation.mutate()}
            regenerating={singleMutation.isPending}
          />
        </div>
      )}
    </PageCard>
  );
}

// =========================================================================
// Batch runner — progress + approval queue, driven by client polling
// =========================================================================

function BatchRunner({ idBatch, onExit }: { idBatch: number; onExit: () => void }) {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();

  const [autoProcess, setAutoProcess] = useState(true);
  const [chunkSize] = useState(5);
  const [acceptingId, setAcceptingId] = useState<number | null>(null);
  const [rejectingId, setRejectingId] = useState<number | null>(null);
  const [bulkBusy, setBulkBusy] = useState(false);
  const [bulkProgress, setBulkProgress] = useState<{ mode: 'accept' | 'reject'; done: number; total: number; failed: number } | null>(null);
  const bulkCancelRef = useRef(false);
  const processingRef = useRef(false);

  const batchQuery = useQuery({
    queryKey: ['ai', 'batch', idBatch],
    queryFn: () => aiApi.getBatch(idBatch, true),
    refetchInterval: (q) => {
      const d = q.state.data;
      if (!d) return 2000;
      return d.remaining > 0 ? 2000 : false;
    },
  });

  const processMutation = useMutation({
    mutationFn: () => aiApi.processNext(idBatch, chunkSize),
    onSuccess: (batch) => {
      // Budget / rate limit hit → the batch paused itself; stop hammering and tell the user.
      if (batch?.paused) {
        setAutoProcess(false);
        toast.show(batch.pause_reason || 'Paused — AI limit reached. Try again later.', 'info');
      }
      qc.invalidateQueries({ queryKey: ['ai', 'batch', idBatch] });
    },
    onError: (e: Error) => toast.show(e.message || 'Processing failed', 'error'),
  });

  // Auto-drive the queue
  useEffect(() => {
    if (!autoProcess) return;
    const d = batchQuery.data;
    if (!d || d.remaining <= 0 || (d.status !== 'pending' && d.status !== 'running')) return;
    if (processMutation.isPending || processingRef.current) return;

    processingRef.current = true;
    processMutation.mutate(undefined, {
      onSettled: () => { processingRef.current = false; },
    });
  }, [autoProcess, batchQuery.data, processMutation]);

  const batch = batchQuery.data;
  if (!batch) {
    return <div className="text-muted-foreground">{t('common.loading', 'Loading…')}</div>;
  }

  const progressPct = batch.total === 0 ? 0 : Math.round((batch.done / batch.total) * 100);
  const running = (batch.status === 'pending' || batch.status === 'running') && batch.remaining > 0;

  const handleAccept = async (idRun: number) => {
    setAcceptingId(idRun);
    try {
      await aiApi.accept(idRun);
      toast.show('Applied', 'success');
      await qc.invalidateQueries({ queryKey: ['ai', 'batch', idBatch] });
    } catch (e) { toast.show((e as Error).message, 'error'); }
    finally { setAcceptingId(null); }
  };

  const handleReject = async (idRun: number) => {
    setRejectingId(idRun);
    try {
      await aiApi.reject(idRun);
      toast.show('Rejected', 'info');
      await qc.invalidateQueries({ queryKey: ['ai', 'batch', idBatch] });
    } catch (e) { toast.show((e as Error).message, 'error'); }
    finally { setRejectingId(null); }
  };

  // Chunked bulk accept — loops over /accept-all?limit=N until the server reports
  // remaining=0. Each chunk is small enough to finish well under any PHP/LiteSpeed
  // timeout. User can stop between chunks via the progress widget.
  const handleAcceptAll = async () => {
    if (bulkBusy) return;
    const totalPending = (batch.runs ?? []).filter((r) => r.status === 'pending').length;
    if (totalPending === 0) return;
    setBulkBusy(true);
    bulkCancelRef.current = false;
    setBulkProgress({ mode: 'accept', done: 0, total: totalPending, failed: 0 });
    let done = 0;
    let failed = 0;
    try {
      while (!bulkCancelRef.current) {
        const res = await aiApi.acceptAllBatch(idBatch, 25);
        done += res.accepted;
        failed += res.failed;
        setBulkProgress({ mode: 'accept', done, total: totalPending, failed });
        qc.invalidateQueries({ queryKey: ['ai', 'batch', idBatch] });
        if (res.remaining === 0) break;
        if (res.accepted === 0 && res.failed > 0) {
          // Whole chunk failed — bail out, don't spin forever
          toast.show(`Chunk failed entirely (${res.failed}). Stopping.`, 'error');
          break;
        }
      }
      if (bulkCancelRef.current) {
        toast.show(`Stopped at ${done}/${totalPending}`, 'info');
      } else if (failed > 0) {
        toast.show(`Accepted ${done} (${failed} failed)`, 'info');
      } else {
        toast.show(`Accepted ${done}`, 'success');
      }
    } catch (e) {
      toast.show((e as Error).message || 'Accept all failed', 'error');
    } finally {
      setBulkBusy(false);
      setBulkProgress(null);
      bulkCancelRef.current = false;
    }
  };

  const handleRejectAll = async () => {
    const ok = await confirm({
      title: t('confirm.reject_pending_title', 'Reject pending runs?'),
      message: t('confirm.reject_pending_msg', 'Reject all pending runs in this batch. Generated outputs will be discarded.'),
      confirmLabel: t('confirm.reject_all', 'Reject all'),
      tone: 'destructive',
    });
    if (!ok) return;
    const totalPending = (batch.runs ?? []).filter((r) => r.status === 'pending').length;
    if (totalPending === 0) return;
    setBulkBusy(true);
    bulkCancelRef.current = false;
    setBulkProgress({ mode: 'reject', done: 0, total: totalPending, failed: 0 });
    let done = 0;
    try {
      while (!bulkCancelRef.current) {
        const res = await aiApi.rejectAllBatch(idBatch, 50);
        done += res.rejected;
        setBulkProgress({ mode: 'reject', done, total: totalPending, failed: 0 });
        qc.invalidateQueries({ queryKey: ['ai', 'batch', idBatch] });
        if (res.remaining === 0) break;
        if (res.rejected === 0) break; // nothing happened — stop to avoid loop
      }
      toast.show(bulkCancelRef.current ? `Stopped at ${done}/${totalPending}` : `Rejected ${done}`, 'info');
    } catch (e) {
      toast.show((e as Error).message || 'Reject all failed', 'error');
    } finally {
      setBulkBusy(false);
      setBulkProgress(null);
      bulkCancelRef.current = false;
    }
  };

  const handleStopBulk = () => {
    bulkCancelRef.current = true;
  };

  return (
    <>
      <PageHead
        title={`Batch #${batch.id_batch}`}
        subtitle={
          <>
            {t('task.' + batch.task_type, TASK_TYPE_LABELS[batch.task_type])} · {batch.prompt.name} ·{' '}
            <strong>${batch.total_cost_usd.toFixed(5)}</strong>
          </>
        }
        actions={<Button onClick={onExit}>{t('ai.batch_back', '← Back to AI Assistant')}</Button>}
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
          <div
            className="h-full bg-primary transition-all"
            style={{ width: `${progressPct}%` }}
          />
        </div>
        <div className="mt-2 flex items-center justify-between text-[12px]">
          <div className="text-muted-foreground">{t2('bulk.percent_complete', '{pct}% complete', { pct: progressPct })}</div>
          <div className="flex items-center gap-3">
            <label className="flex items-center gap-2 cursor-pointer">
              <input
                type="checkbox"
                checked={autoProcess}
                onChange={(e) => setAutoProcess(e.target.checked)}
                className="accent-primary"
              />
              <span className="text-slate-700">{t('bulk.auto_process', 'Auto-process')}</span>
            </label>
            {running && !autoProcess && (
              <Button
                size="sm"
                onClick={() => processMutation.mutate()}
                disabled={processMutation.isPending}
              >
                {processMutation.isPending ? '…' : t2('bulk.process_next', 'Process next {n}', { n: chunkSize })}
              </Button>
            )}
          </div>
        </div>

        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4">
          <Stat label="Pending approval" value={batch.counts.pending} tone="primary" />
          <Stat label="Accepted"          value={batch.counts.accepted} tone="success" />
          <Stat label="Rejected"          value={batch.counts.rejected} tone="destructive" />
          <Stat label="Failed"            value={batch.counts.failed}   tone="destructive" />
        </div>
      </Card>

      {bulkProgress && (
        <Card className="mb-4 bg-blue-50 border-blue-200">
          <div className="flex items-center justify-between mb-2">
            <div className="font-semibold text-[13px] text-slate-900">
              {bulkProgress.mode === 'accept'
                ? t2('ai.bulk_accept_progress', 'Accepting in queue… {done}/{total}', { done: bulkProgress.done, total: bulkProgress.total })
                : t2('ai.bulk_reject_progress', 'Rejecting in queue… {done}/{total}', { done: bulkProgress.done, total: bulkProgress.total })}
              {bulkProgress.failed > 0 && (
                <span className="ml-2 text-amber-700">
                  {t2('ai.bulk_failed', '({n} failed)', { n: bulkProgress.failed })}
                </span>
              )}
            </div>
            <Button size="sm" variant="destructive" onClick={handleStopBulk}>
              {t('ai.bulk_stop', '■ Stop')}
            </Button>
          </div>
          <div className="h-2 bg-white rounded-full overflow-hidden border border-blue-200">
            <div
              className="h-full bg-blue-600 transition-all"
              style={{ width: `${bulkProgress.total === 0 ? 0 : Math.round((bulkProgress.done / bulkProgress.total) * 100)}%` }}
            />
          </div>
          <div className="mt-1 text-[11px] text-slate-600">
            {t('ai.bulk_progress_hint', 'Processing in chunks of 25 — safe to leave the page open, will continue automatically.')}
          </div>
        </Card>
      )}

      <Card>
        <SectionHeader
          title="Approval queue"
          subtitle="Per-item review or bulk actions"
        />
        <ApprovalQueue
          runs={batch.runs ?? []}
          onAccept={handleAccept}
          onReject={handleReject}
          onAcceptAll={handleAcceptAll}
          onRejectAll={handleRejectAll}
          accepting={acceptingId}
          rejecting={rejectingId}
          bulkBusy={bulkBusy}
        />
      </Card>
    </>
  );
}

function Stat({ label, value, tone }: { label: string; value: number; tone: 'primary' | 'success' | 'destructive' }) {
  const classes =
    tone === 'primary' ? 'border-primary-50 bg-primary-50 text-primary-700' :
    tone === 'success' ? 'border-green-200 bg-green-50 text-green-800' :
    'border-red-200 bg-red-50 text-red-800';
  return (
    <div className={cn('border rounded-md p-3', classes)}>
      <div className="text-[11px] uppercase tracking-wide font-semibold">{label}</div>
      <div className="text-[20px] font-bold leading-tight mt-0.5">{value}</div>
    </div>
  );
}

function BatchStatusBadge({ status }: { status: BatchStatus['status'] }) {
  if (status === 'running')  return <Badge variant="primary">running</Badge>;
  if (status === 'success')  return <Badge variant="success">✓ done</Badge>;
  if (status === 'partial')  return <Badge variant="warning">partial</Badge>;
  if (status === 'failed')   return <Badge variant="destructive">failed</Badge>;
  return <Badge variant="muted">{status}</Badge>;
}

// =========================================================================
// Single-result card (test mode)
// =========================================================================

function SingleResultCard({
  run,
  onAccept,
  onReject,
  onRegenerate,
  regenerating,
}: {
  run: AiRun;
  onAccept: () => void;
  onReject: () => void;
  onRegenerate: () => void;
  regenerating: boolean;
}) {
  const supportsAutoApply = taskTypeSupportsAutoApply(run.prompt.task_type);
  const isFinal = run.status === 'accepted' || run.status === 'rejected';

  return (
    <Card>
      <SectionHeader
        title="Test result"
        subtitle={
          <>
            {run.model} · {run.tokens_in} in / {run.tokens_out} out ·{' '}
            <strong>${run.cost_usd.toFixed(6)}</strong> · {run.latency_ms}ms
          </>
        }
        action={
          <Badge variant={
            run.status === 'accepted' ? 'success'
              : run.status === 'rejected' ? 'destructive'
              : run.status === 'failed' ? 'destructive'
              : 'primary'
          }>
            {run.status}
          </Badge>
        }
      />
      <div className="border border-border rounded-md bg-muted p-4 mb-4">
        <div className="text-[11px] uppercase tracking-wide text-muted-foreground font-semibold mb-1">
          Generated output
        </div>
        <div className="whitespace-pre-wrap font-mono text-[12px] leading-relaxed text-slate-900 break-words">
          {run.output}
        </div>
        <div className="text-[11px] text-muted-foreground mt-2">{run.output.length} chars</div>
      </div>

      {!supportsAutoApply && run.status === 'pending' && (
        <div className="mb-4 p-3 rounded-md border border-amber-200 bg-amber-50 text-amber-900 text-[12px]">
          <strong>Note:</strong> Auto-apply is not available for {TASK_TYPE_LABELS[run.prompt.task_type]} yet.
          Accept will mark the run as accepted but won't write to the product.
        </div>
      )}

      <div className="flex items-center justify-end gap-2 pt-4 border-t border-border">
        <Button onClick={onRegenerate} disabled={regenerating}>
          {regenerating ? 'Regenerating…' : '↻ Regenerate'}
        </Button>
        <Button onClick={onReject} variant="destructive" disabled={isFinal || regenerating}>
          ✗ Reject
        </Button>
        <Button onClick={onAccept} variant="primary" disabled={isFinal || regenerating}>
          {supportsAutoApply ? '✓ Accept & apply' : '✓ Accept'}
        </Button>
      </div>
    </Card>
  );
}

// -------------------- Task-type-specific param pickers --------------------

function TaskTypeParams({
  taskType,
  targetField,
  targetLang,
  onTargetFieldChange,
  onTargetLangChange,
}: {
  taskType: TaskType;
  targetField: string;
  targetLang: number | '';
  onTargetFieldChange: (v: string) => void;
  onTargetLangChange: (v: number | '') => void;
}) {
  const needsField = taskTypeNeedsTargetField(taskType);
  const needsLang  = taskTypeNeedsTargetLang(taskType);

  const langsQuery = useQuery({
    queryKey: ['lookup', 'languages'],
    queryFn: () => lookupsApi.languages(),
    staleTime: 10 * 60 * 1000,
    enabled: needsLang,
  });

  if (!needsField && !needsLang) return null;

  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3 pt-3 border-t border-border">
      {needsField && (
        <Field label="Target field" hint="Which product field the output will be written to.">
          <Select value={targetField} onChange={(e) => onTargetFieldChange(e.target.value)}>
            <option value="">— use prompt default —</option>
            {TARGET_FIELDS.map((f) => (
              <option key={f.value} value={f.value}>{f.label}</option>
            ))}
          </Select>
        </Field>
      )}
      {needsLang && (
        <Field label="Target language" hint="Destination lang — prompt reads source from scope's current lang.">
          <Select
            value={targetLang === '' ? '' : String(targetLang)}
            onChange={(e) => onTargetLangChange(e.target.value === '' ? '' : Number(e.target.value))}
            disabled={langsQuery.isLoading}
          >
            <option value="">— same as source —</option>
            {(langsQuery.data ?? []).map((l) => (
              <option key={l.id_lang} value={l.id_lang}>
                {l.name} ({l.iso_code.toUpperCase()})
              </option>
            ))}
          </Select>
        </Field>
      )}
    </div>
  );
}
