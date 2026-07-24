import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import PageHead from '../components/PageHead';
import PageCard from '../components/PageCard';
import Card from '../components/Card';
import Button from '../components/Button';
import Badge from '../components/Badge';
import { Field, Input, Select } from '../components/Form';
import { useToast } from '../components/Toast';
import { useConfirm } from '../components/ConfirmDialog';
import {
  schedulesApi,
  cronToPreset,
  presetToCron,
  DAYS_OF_WEEK,
  type CronPreset,
  type JobType,
  type Schedule,
} from '../lib/schedules';
import { promptsApi, TASK_TYPE_LABELS } from '../lib/prompts';
import { segmentsApi, lookupsApi, isFlatSegment, type SavedSegment } from '../lib/segments';
import { TARGET_FIELDS } from '../lib/ai';
import { bulkApi, type ActionTemplate } from '../lib/bulk';
import { t, t2 } from '../lib/i18n';

export default function Scheduler() {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();

  const listQuery = useQuery({
    queryKey: ['schedules', 'list'],
    queryFn: schedulesApi.list,
  });

  const [editing, setEditing] = useState<Schedule | 'new' | null>(null);

  const deleteMutation = useMutation({
    mutationFn: (id: number) => schedulesApi.delete(id),
    onSuccess: () => { toast.show(t('scheduler.toast.deleted', 'Schedule deleted'), 'success'); qc.invalidateQueries({ queryKey: ['schedules'] }); },
    onError:   (e: Error) => toast.show(e.message || t('scheduler.toast.delete_failed', 'Delete failed'), 'error'),
  });

  const toggleMutation = useMutation({
    mutationFn: ({ id, active }: { id: number; active: boolean }) => schedulesApi.update(id, { is_active: active }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['schedules'] }),
    onError:   (e: Error) => toast.show(e.message || t('scheduler.toast.toggle_failed', 'Toggle failed'), 'error'),
  });

  const runMutation = useMutation({
    mutationFn: (id: number) => schedulesApi.runNow(id),
    onSuccess: (res) => {
      toast.show(
        res.status === 'started'
          ? t2('scheduler.toast.batch_started', 'Started: batch #{id} ({n} products)', { id: res.id_batch ?? 0, n: res.products ?? 0 })
          : res.error || t('scheduler.toast.run_finished', 'Run finished'),
        res.status === 'failed' ? 'error' : 'success'
      );
      qc.invalidateQueries({ queryKey: ['schedules'] });
    },
    onError: (e: Error) => toast.show(e.message || t('scheduler.toast.run_failed', 'Run failed'), 'error'),
  });

  const onDelete = async (s: Schedule) => {
    const ok = await confirm({
      title: t2('confirm.delete_schedule_title', 'Delete schedule "{name}"?', { name: s.name }),
      message: t('confirm.delete_schedule_msg', 'It will stop firing immediately. Past runs and their batches stay in History.'),
      confirmLabel: t('common.delete', 'Delete'),
      tone: 'destructive',
    });
    if (ok) deleteMutation.mutate(s.id_schedule);
  };

  const items = listQuery.data ?? [];

  return (
    <PageCard>
      <PageHead
        title={t('scheduler.title', 'Scheduler')}
        subtitle={
          listQuery.isLoading
            ? t('common.loading', 'Loading…')
            : t2('scheduler.subtitle', '{n} schedule{plural} configured', { n: items.length, plural: items.length === 1 ? '' : 's' })
        }
        actions={
          <Button variant="primary" onClick={() => setEditing('new')}>
            {t('scheduler.add', '+ Add schedule')}
          </Button>
        }
      />

      <CrontabHelpBanner />

      <Card>
        {listQuery.isLoading && <div className="text-[13px] text-muted-foreground py-8 text-center">{t('common.loading', 'Loading…')}</div>}
        {!listQuery.isLoading && items.length === 0 && (
          <div className="text-[13px] text-muted-foreground py-8 text-center">
            {t('scheduler.empty', 'No schedules yet. Click + Add schedule to create one.')}
          </div>
        )}
        {items.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-[13px]">
              <thead>
                <tr className="border-b border-border text-[10px] uppercase tracking-wide text-muted-foreground">
                  <th className="text-left px-2 py-2 w-10">{t('scheduler.col.on', 'On')}</th>
                  <th className="text-left px-2 py-2">{t('scheduler.col.name', 'Name')}</th>
                  <th className="text-left px-2 py-2 w-24">{t('scheduler.col.type', 'Type')}</th>
                  <th className="text-left px-2 py-2 w-44">{t('scheduler.col.schedule', 'Schedule')}</th>
                  <th className="text-left px-2 py-2 w-40">{t('scheduler.col.next_run', 'Next run')}</th>
                  <th className="text-left px-2 py-2 w-40">{t('scheduler.col.last_run', 'Last run')}</th>
                  <th className="text-right px-2 py-2 w-56">{t('common.action', 'Actions')}</th>
                </tr>
              </thead>
              <tbody>
                {items.map((s) => (
                  <tr key={s.id_schedule} className="border-b border-border last:border-0 hover:bg-muted/40">
                    <td className="px-2 py-2">
                      <input
                        type="checkbox"
                        checked={s.is_active}
                        onChange={(e) => toggleMutation.mutate({ id: s.id_schedule, active: e.target.checked })}
                      />
                    </td>
                    <td className="px-2 py-2 font-medium">{s.name}</td>
                    <td className="px-2 py-2">
                      <Badge variant={s.job_type === 'ai_batch' ? 'success' : 'primary'}>
                        {s.job_type === 'ai_batch' ? t('scheduler.kind.ai', '🤖 AI') : t('scheduler.kind.bulk', '📝 Bulk')}
                      </Badge>
                    </td>
                    <td className="px-2 py-2 text-muted-foreground">{s.human_schedule}</td>
                    <td className="px-2 py-2 text-muted-foreground whitespace-nowrap">{formatDate(s.next_run_at)}</td>
                    <td className="px-2 py-2 text-muted-foreground whitespace-nowrap">{formatDate(s.last_run_at)}</td>
                    <td className="px-2 py-2 text-right whitespace-nowrap flex gap-1 justify-end">
                      <Button size="sm" variant="primary" onClick={() => runMutation.mutate(s.id_schedule)} disabled={runMutation.isPending}>
                        {t('scheduler.run_now', '▶ Run now')}
                      </Button>
                      <Button size="sm" onClick={() => setEditing(s)}>{t('common.edit', 'Edit')}</Button>
                      <Button size="sm" variant="destructive" onClick={() => onDelete(s)}>✕</Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {editing !== null && (
        <ScheduleFormModal
          initial={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); qc.invalidateQueries({ queryKey: ['schedules'] }); }}
        />
      )}
    </PageCard>
  );
}

// =============================================================
// Crontab help banner
// =============================================================

function CrontabHelpBanner() {
  const heartbeatUrl = `${window.location.origin}/modules/smartbulk/api/schedules/heartbeat`;
  const [copied, setCopied] = useState(false);
  return (
    <Card className="mb-3 bg-blue-50 border-blue-200">
      <div className="flex items-start gap-3 text-[12px] text-slate-700">
        <span className="text-[18px]">⏰</span>
        <div className="flex-1">
          <div className="font-semibold text-slate-900 mb-1">{t('scheduler.crontab.title', 'Reliable scheduling needs an external cron')}</div>
          <p>{t('scheduler.crontab.body', 'SmartBulk fires due jobs whenever you open any back-office page (every ~60 s). For nightly jobs you should add this to your server\'s crontab:')}</p>
          <div className="mt-2 flex items-center gap-2">
            <code className="bg-white border border-border rounded px-2 py-1 text-[11px] flex-1 truncate">
              * * * * * curl -s "{heartbeatUrl}" &gt; /dev/null
            </code>
            <Button
              size="sm"
              variant="ghost"
              onClick={() => {
                navigator.clipboard.writeText(`* * * * * curl -s "${heartbeatUrl}" > /dev/null`);
                setCopied(true);
                setTimeout(() => setCopied(false), 1500);
              }}
            >
              {copied ? t('scheduler.crontab.copied', '✓ Copied') : t('scheduler.crontab.copy', 'Copy')}
            </Button>
          </div>
        </div>
      </div>
    </Card>
  );
}

// =============================================================
// Create/edit modal
// =============================================================

function ScheduleFormModal({
  initial,
  onClose,
  onSaved,
}: {
  initial: Schedule | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const toast = useToast();
  const isEdit = initial !== null;

  const [name, setName] = useState(initial?.name ?? '');
  const [jobType, setJobType] = useState<JobType>(initial?.job_type ?? 'ai_batch');
  const [preset, setPreset] = useState<CronPreset>(
    initial ? cronToPreset(initial.cron_expression) : { kind: 'daily', hour: 3, minute: 0 }
  );
  const [active, setActive] = useState(initial?.is_active ?? true);

  // ai_batch payload state
  const initPayload = (initial?.job_payload ?? {}) as Record<string, unknown>;
  const [idPrompt, setIdPrompt]         = useState<number | ''>(typeof initPayload.id_prompt === 'number' ? initPayload.id_prompt : '');
  const [idSegment, setIdSegment]       = useState<number | ''>(typeof initPayload.id_segment === 'number' ? initPayload.id_segment : '');
  const [targetField, setTargetField]   = useState<string>(typeof initPayload.target_field === 'string' ? initPayload.target_field : '');
  const [targetLang, setTargetLang]     = useState<number | ''>(typeof initPayload.target_lang === 'number' ? initPayload.target_lang : '');
  const [autoAccept, setAutoAccept]     = useState<boolean>(!!initPayload.auto_accept);

  // bulk_edit payload state — uses saved Action Template + segment.
  const [bulkTemplateId, setBulkTemplateId] = useState<string>(typeof initPayload.template_id === 'string' ? initPayload.template_id : '');
  const [bulkDryRun, setBulkDryRun]         = useState<boolean>(typeof initPayload.mode === 'string' && initPayload.mode === 'dry_run');

  const promptsQuery   = useQuery({ queryKey: ['prompts', 'list'], queryFn: () => promptsApi.list() });
  const segmentsQuery  = useQuery({ queryKey: ['segments', 'list'], queryFn: () => segmentsApi.list() });
  const langsQuery     = useQuery({ queryKey: ['lookup', 'languages'], queryFn: lookupsApi.languages });
  const templatesQuery = useQuery({ queryKey: ['bulk', 'templates'], queryFn: () => bulkApi.templates(), enabled: jobType === 'bulk_edit' });

  // Saved segments: only "flat" ones can be resolved by the AI batch path.
  const flatSegments: SavedSegment[] = useMemo(
    () => (segmentsQuery.data ?? []).filter((s) => isFlatSegment(s.conditions)),
    [segmentsQuery.data]
  );

  // Auto-pick first prompt/segment/lang on first open if none preset
  useEffect(() => {
    if (idPrompt === '' && (promptsQuery.data?.length ?? 0) > 0) {
      setIdPrompt(promptsQuery.data![0].id_prompt);
    }
  }, [promptsQuery.data, idPrompt]);

  const saveMutation = useMutation({
    mutationFn: () => {
      const cron = presetToCron(preset);
      const segment = flatSegments.find((s) => s.id_segment === idSegment);
      const filterSpec = segment ? segment.conditions : undefined;

      let payload: Record<string, unknown>;
      if (jobType === 'ai_batch') {
        payload = {
          id_prompt:    typeof idPrompt === 'number' ? idPrompt : 0,
          id_segment:   typeof idSegment === 'number' ? idSegment : null,
          filter_spec:  filterSpec,
          target_field: targetField || undefined,
          target_lang:  targetLang === '' ? undefined : targetLang,
          auto_accept:  autoAccept,
        };
      } else {
        // bulk_edit
        const template = (templatesQuery.data ?? []).find((tpl) => tpl.id === bulkTemplateId);
        payload = {
          template_id: bulkTemplateId,
          actions:     template ? template.actions : [],
          id_segment:  typeof idSegment === 'number' ? idSegment : null,
          filter_spec: filterSpec,
          mode:        bulkDryRun ? 'dry_run' : 'apply',
        };
      }

      const body = {
        name: name.trim(),
        job_type: jobType,
        job_payload: payload,
        cron_expression: cron,
        is_active: active,
      };
      return isEdit
        ? schedulesApi.update(initial!.id_schedule, body)
        : schedulesApi.create(body);
    },
    onSuccess: () => { toast.show(isEdit ? t('scheduler.toast.updated', 'Schedule updated') : t('scheduler.toast.created', 'Schedule created'), 'success'); onSaved(); },
    onError:   (e: Error) => toast.show(e.message || t('scheduler.toast.save_failed', 'Save failed'), 'error'),
  });

  const canSave =
    name.trim() !== '' && idSegment !== '' &&
    (jobType === 'ai_batch' ? idPrompt !== '' : bulkTemplateId !== '');

  return (
    <div className="fixed inset-0 z-[10000] flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
      <div className="bg-white rounded-lg shadow-xl border border-border max-w-[640px] w-full max-h-[90vh] overflow-hidden flex flex-col" onClick={(e) => e.stopPropagation()}>
        <div className="px-5 py-3 border-b border-border flex items-center justify-between">
          <h3 className="text-[15px] font-semibold">{isEdit ? t2('scheduler.modal.edit', 'Edit schedule #{id}', { id: initial!.id_schedule }) : t('scheduler.modal.new', 'New schedule')}</h3>
          <button type="button" onClick={onClose} className="text-muted-foreground hover:text-slate-900 text-[18px] leading-none">×</button>
        </div>

        <div className="px-5 py-4 overflow-y-auto flex-1 flex flex-col gap-3">
          <Field label={t('scheduler.modal.name', 'Name')} required>
            <Input value={name} onChange={(e) => setName(e.target.value)} placeholder={t('scheduler.modal.name_placeholder', 'e.g. Nightly meta-title regen for low-CTR products')} />
          </Field>

          <Field label={t('scheduler.modal.job_type', 'Job type')}>
            <Select value={jobType} onChange={(e) => setJobType(e.target.value as JobType)}>
              <option value="ai_batch">{t('scheduler.modal.job_ai', '🤖 AI batch (run a prompt against a segment)')}</option>
              <option value="bulk_edit">{t('scheduler.modal.job_bulk', '📝 Bulk edit (apply a saved Action Template against a segment)')}</option>
            </Select>
          </Field>

          <CronPicker preset={preset} onChange={setPreset} />

          {jobType === 'ai_batch' && (
            <>
              <Field label={t('scheduler.modal.prompt', 'Prompt')} required hint={t('scheduler.modal.prompt_hint', 'Pick a prompt from the Prompt Library.')}>
                <Select value={idPrompt} onChange={(e) => setIdPrompt(Number(e.target.value) || '')}>
                  <option value="">{t('scheduler.modal.prompt_pick', '— pick prompt —')}</option>
                  {(promptsQuery.data ?? []).map((p) => (
                    <option key={p.id_prompt} value={p.id_prompt}>
                      [{t('task.' + p.task_type, TASK_TYPE_LABELS[p.task_type])}] {p.name}
                    </option>
                  ))}
                </Select>
              </Field>

              <Field label={t('scheduler.modal.segment', 'Segment')} required hint={t('scheduler.modal.segment_hint', "Saved segment in flat format (created from AI Assistant). Tree-format segments aren't yet supported here.")}>
                <Select value={idSegment} onChange={(e) => setIdSegment(Number(e.target.value) || '')}>
                  <option value="">{t('scheduler.modal.segment_pick', '— pick segment —')}</option>
                  {flatSegments.map((s) => (
                    <option key={s.id_segment} value={s.id_segment}>{s.name}</option>
                  ))}
                </Select>
                {flatSegments.length === 0 && (
                  <div className="text-[11px] text-amber-700 mt-1">
                    {t('scheduler.modal.no_segments', 'No compatible segments yet. Save one from AI Assistant → Custom filter → Save segment.')}
                  </div>
                )}
              </Field>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <Field label={t('scheduler.modal.target_field', 'Target field (optional)')} hint={t('scheduler.modal.target_field_hint', "Override prompt's default target field.")}>
                  <Select value={targetField} onChange={(e) => setTargetField(e.target.value)}>
                    <option value="">{t('scheduler.modal.target_field_default', '— use prompt default —')}</option>
                    {TARGET_FIELDS.map((f) => (<option key={f.value} value={f.value}>{f.label}</option>))}
                  </Select>
                </Field>
                <Field label={t('scheduler.modal.target_lang', 'Target language (optional)')} hint={t('scheduler.modal.target_lang_hint', 'For translate prompts.')}>
                  <Select value={targetLang === '' ? '' : String(targetLang)} onChange={(e) => setTargetLang(e.target.value === '' ? '' : Number(e.target.value))}>
                    <option value="">{t('scheduler.modal.target_lang_default', '— same as source —')}</option>
                    {(langsQuery.data ?? []).map((l) => (
                      <option key={l.id_lang} value={l.id_lang}>{l.name} ({l.iso_code.toUpperCase()})</option>
                    ))}
                  </Select>
                </Field>
              </div>

              <label className="flex items-center gap-2 text-[13px] mt-1">
                <input type="checkbox" checked={autoAccept} onChange={(e) => setAutoAccept(e.target.checked)} />
                <span>{t('scheduler.modal.auto_accept', 'Auto-accept generated outputs (write to product without manual review)')}</span>
              </label>
              {autoAccept && (
                <div className="text-[11px] text-amber-700 leading-relaxed">
                  {t('scheduler.modal.auto_accept_warn', "⚠ With auto-accept on, AI output is applied straight to products. Use only with prompts you've reviewed.")}
                </div>
              )}
            </>
          )}

          {jobType === 'bulk_edit' && (
            <>
              <Field label={t('scheduler.modal.template', 'Action template')} required hint={t('scheduler.modal.template_hint', 'Pick a built-in or saved template.')}>
                <Select value={bulkTemplateId} onChange={(e) => setBulkTemplateId(e.target.value)}>
                  <option value="">{t('scheduler.modal.template_pick', '— pick template —')}</option>
                  {(templatesQuery.data ?? []).map((tpl: ActionTemplate) => (
                    <option key={tpl.id} value={tpl.id}>
                      {tpl.is_user ? '⭐ ' : ''}{tpl.emoji} {tpl.name}
                    </option>
                  ))}
                </Select>
                {(templatesQuery.data ?? []).length === 0 && (
                  <div className="text-[11px] text-muted-foreground mt-1">{t('scheduler.modal.template_none', 'Loading templates…')}</div>
                )}
              </Field>

              <Field label={t('scheduler.modal.segment', 'Segment')} required hint={t('scheduler.modal.bulk_segment_hint', 'Saved segment determines which products the template runs against.')}>
                <Select value={idSegment} onChange={(e) => setIdSegment(Number(e.target.value) || '')}>
                  <option value="">{t('scheduler.modal.segment_pick', '— pick segment —')}</option>
                  {flatSegments.map((s) => (
                    <option key={s.id_segment} value={s.id_segment}>{s.name}</option>
                  ))}
                </Select>
                {flatSegments.length === 0 && (
                  <div className="text-[11px] text-amber-700 mt-1">
                    {t('scheduler.modal.no_segments', 'No compatible segments yet. Save one from AI Assistant → Custom filter → Save segment.')}
                  </div>
                )}
              </Field>

              <label className="flex items-center gap-2 text-[13px] mt-1">
                <input type="checkbox" checked={bulkDryRun} onChange={(e) => setBulkDryRun(e.target.checked)} />
                <span>{t('scheduler.modal.bulk_dry_run', "Dry-run only (log changes, don't write to products)")}</span>
              </label>
            </>
          )}

          <label className="flex items-center gap-2 text-[13px] mt-1">
            <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} />
            <span>{t('scheduler.modal.enabled', 'Enabled')}</span>
          </label>
        </div>

        <div className="px-5 py-3 border-t border-border bg-muted/30 flex items-center justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>{t('common.cancel', 'Cancel')}</Button>
          <Button variant="primary" onClick={() => saveMutation.mutate()} disabled={!canSave || saveMutation.isPending}>
            {saveMutation.isPending ? t('scheduler.modal.saving', 'Saving…') : (isEdit ? t('scheduler.modal.save_changes', 'Save changes') : t('scheduler.modal.create', 'Create schedule'))}
          </Button>
        </div>
      </div>
    </div>
  );
}

// =============================================================
// Cron preset picker
// =============================================================

function CronPicker({ preset, onChange }: { preset: CronPreset; onChange: (p: CronPreset) => void }) {
  const kind = preset.kind;
  return (
    <div className="border border-border rounded-md p-3">
      <div className="text-[11px] uppercase tracking-wide text-muted-foreground font-semibold mb-2">{t('scheduler.cron.kind', 'Schedule')}</div>
      <div className="flex flex-wrap items-center gap-2">
        <Select
          value={kind}
          onChange={(e) => {
            const k = e.target.value as CronPreset['kind'];
            if (k === 'hourly')  onChange({ kind: 'hourly',  minute: 0 });
            if (k === 'daily')   onChange({ kind: 'daily',   hour: 3, minute: 0 });
            if (k === 'weekly')  onChange({ kind: 'weekly',  dow: 1, hour: 3, minute: 0 });
            if (k === 'monthly') onChange({ kind: 'monthly', dom: 1, hour: 3, minute: 0 });
            if (k === 'custom')  onChange({ kind: 'custom',  expression: '0 3 * * *' });
          }}
          className="max-w-[160px]"
        >
          <option value="hourly">{t('scheduler.cron.hourly', 'Hourly')}</option>
          <option value="daily">{t('scheduler.cron.daily', 'Daily')}</option>
          <option value="weekly">{t('scheduler.cron.weekly', 'Weekly')}</option>
          <option value="monthly">{t('scheduler.cron.monthly', 'Monthly')}</option>
          <option value="custom">{t('scheduler.cron.custom', 'Custom (cron)')}</option>
        </Select>

        {kind === 'hourly' && (
          <span className="flex items-center gap-1 text-[12px]">
            {t('scheduler.cron.at_minute', 'at minute')} <NumInput max={59} value={preset.minute} onChange={(v) => onChange({ kind: 'hourly', minute: v })} />
          </span>
        )}
        {kind === 'daily' && (
          <span className="flex items-center gap-1 text-[12px]">
            {t('scheduler.cron.at_time', 'at')} <TimeInput hour={preset.hour} minute={preset.minute} onChange={(h, m) => onChange({ kind: 'daily', hour: h, minute: m })} />
          </span>
        )}
        {kind === 'weekly' && (
          <span className="flex items-center gap-1 text-[12px]">
            {t('scheduler.cron.on_dow', 'on')}
            <Select
              value={preset.dow}
              onChange={(e) => onChange({ ...preset, dow: Number(e.target.value) })}
              className="max-w-[110px]"
            >
              {DAYS_OF_WEEK.map((d, i) => <option key={i} value={i}>{d}</option>)}
            </Select>
            {t('scheduler.cron.at_time', 'at')} <TimeInput hour={preset.hour} minute={preset.minute} onChange={(h, m) => onChange({ ...preset, hour: h, minute: m })} />
          </span>
        )}
        {kind === 'monthly' && (
          <span className="flex items-center gap-1 text-[12px]">
            {t('scheduler.cron.on_day', 'on day')} <NumInput max={28} min={1} value={preset.dom} onChange={(v) => onChange({ ...preset, dom: v })} />
            {t('scheduler.cron.at_time', 'at')} <TimeInput hour={preset.hour} minute={preset.minute} onChange={(h, m) => onChange({ ...preset, hour: h, minute: m })} />
          </span>
        )}
        {kind === 'custom' && (
          <Input
            value={preset.expression}
            onChange={(e) => onChange({ kind: 'custom', expression: e.target.value })}
            placeholder={t('scheduler.cron.custom_placeholder', 'min hour day-of-month month day-of-week')}
            className="font-mono max-w-[280px]"
          />
        )}
      </div>
      <div className="text-[10px] text-muted-foreground mt-2">
        {t('scheduler.cron.cron_label', 'Cron:')} <code className="bg-muted px-1 rounded">{presetToCron(preset)}</code>
      </div>
    </div>
  );
}

function NumInput({ value, onChange, min = 0, max = 99 }: { value: number; onChange: (n: number) => void; min?: number; max?: number }) {
  return (
    <input
      type="number"
      min={min}
      max={max}
      value={value}
      onChange={(e) => {
        const n = Math.max(min, Math.min(max, Number(e.target.value) || 0));
        onChange(n);
      }}
      className="w-[60px] px-2 py-1 border border-border rounded-md text-[12px] bg-white"
    />
  );
}

function TimeInput({ hour, minute, onChange }: { hour: number; minute: number; onChange: (h: number, m: number) => void }) {
  return (
    <span className="flex items-center gap-0.5">
      <NumInput max={23} value={hour} onChange={(h) => onChange(h, minute)} />:
      <NumInput max={59} value={minute} onChange={(m) => onChange(hour, m)} />
    </span>
  );
}

function formatDate(s: string | null): string {
  if (!s) return '—';
  const d = new Date(s.replace(' ', 'T') + 'Z');
  if (isNaN(d.getTime())) return s;
  return d.toLocaleString();
}
