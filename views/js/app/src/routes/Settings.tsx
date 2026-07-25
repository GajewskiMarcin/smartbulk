import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import PageHead from '../components/PageHead';
import PageCard from '../components/PageCard';
import Card from '../components/Card';
import SectionHeader from '../components/SectionHeader';
import Button from '../components/Button';
import { Field, Input, PasswordInput, Select, Textarea, Checkbox } from '../components/Form';
import { useToast } from '../components/Toast';
import { useConfirm } from '../components/ConfirmDialog';
import { api } from '../lib/api';
import { t, t2 } from '../lib/i18n';

interface Settings {
  ai_provider: 'claude' | 'openai';
  daily_budget: number;
  rate_limit: number;
  mask_prices: boolean;
  brand_tone: string;
  retention_days: number;
  bulk_chunk: number;
  claude_api_key: string;     // masked preview when stored; empty when not set
  openai_api_key: string;
  has_claude_key: boolean;
  has_openai_key: boolean;
}

interface SettingsResponse {
  ok: boolean;
  settings: Settings;
}

interface SaveInput {
  ai_provider?: 'claude' | 'openai';
  daily_budget?: number;
  rate_limit?: number;
  mask_prices?: boolean;
  brand_tone?: string;
  retention_days?: number;
  bulk_chunk?: number;
  claude_api_key?: string | null;
  openai_api_key?: string | null;
}

export default function Settings() {
  const toast = useToast();
  const qc = useQueryClient();

  const { data, isLoading, isError } = useQuery({
    queryKey: ['settings'],
    queryFn: () => api.get<SettingsResponse>('/modules/smartbulk/api/settings'),
  });

  const saveMutation = useMutation({
    mutationFn: (input: SaveInput) =>
      api.post<SettingsResponse>('/modules/smartbulk/api/settings', input),
    onSuccess: (res) => {
      qc.setQueryData(['settings'], res);
      toast.show(t('settings.toast.saved', 'Settings saved'), 'success');
      // Clear local key inputs so the stored value indicator reappears
      setClaudeKey('');
      setOpenaiKey('');
    },
    onError: (err: Error) => toast.show(err.message || t('settings.toast.save_failed', 'Save failed'), 'error'),
  });

  // Local form state — initialized from server data on first load
  const [aiProvider, setAiProvider] = useState<'claude' | 'openai'>('claude');
  const [dailyBudget, setDailyBudget] = useState<string>('25');
  const [rateLimit, setRateLimit] = useState<string>('30');
  const [maskPrices, setMaskPrices] = useState<boolean>(false);
  const [brandTone, setBrandTone] = useState<string>('');
  const [retentionDays, setRetentionDays] = useState<string>('90');
  const [bulkChunk, setBulkChunk] = useState<string>('100');
  const [claudeKey, setClaudeKey] = useState<string>('');
  const [openaiKey, setOpenaiKey] = useState<string>('');

  useEffect(() => {
    if (!data?.settings) return;
    const s = data.settings;
    setAiProvider(s.ai_provider);
    setDailyBudget(String(s.daily_budget));
    setRateLimit(String(s.rate_limit));
    setMaskPrices(s.mask_prices);
    setBrandTone(s.brand_tone);
    setRetentionDays(String(s.retention_days));
    setBulkChunk(String(s.bulk_chunk));
  }, [data?.settings]);

  const onSave = () => {
    const payload: SaveInput = {
      ai_provider: aiProvider,
      daily_budget: Number(dailyBudget) || 0,
      rate_limit: Number(rateLimit) || 0,
      mask_prices: maskPrices,
      brand_tone: brandTone,
      retention_days: Number(retentionDays) || 90,
      bulk_chunk: Number(bulkChunk) || 100,
    };
    // Only submit key fields if user typed something
    if (claudeKey !== '') payload.claude_api_key = claudeKey;
    if (openaiKey !== '') payload.openai_api_key = openaiKey;

    saveMutation.mutate(payload);
  };

  const onClearKey = (which: 'claude' | 'openai') => {
    const field = which === 'claude' ? 'claude_api_key' : 'openai_api_key';
    saveMutation.mutate({ [field]: '' } as SaveInput);
  };

  if (isLoading) {
    return (
      <PageCard>
        <PageHead title={t('settings.title', 'Settings')} subtitle={t('settings.loading', 'Loading...')} />
      </PageCard>
    );
  }

  if (isError || !data?.settings) {
    return (
      <PageCard>
        <PageHead title={t('settings.title', 'Settings')} subtitle={t('settings.load_failed', 'Failed to load settings')} />
        <Card tone="destructive">
          {t('settings.load_error', 'Could not fetch settings from the server. Check your session and try again.')}
        </Card>
      </PageCard>
    );
  }

  const s = data.settings;

  return (
    <PageCard>
      <PageHead
        title={t('settings.title', 'Settings')}
        subtitle={t('settings.subtitle', 'AI providers, brand tone, budgets, data retention')}
      />

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* AI Providers + Keys — spans full width */}
        <div className="lg:col-span-4">
          <Card>
            <SectionHeader
              title={t('settings.ai_providers', 'AI providers')}
              subtitle={t('settings.ai_providers_hint', 'API keys are encrypted at rest. They never leave this install.')}
            />
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <Field label={t('settings.default_provider', 'Default provider')} hint={t('settings.default_provider_hint', "Used when a prompt doesn't specify one")}>
                <Select value={aiProvider} onChange={(e) => setAiProvider(e.target.value as 'claude' | 'openai')}>
                  <option value="claude">Claude (Anthropic)</option>
                  <option value="openai">OpenAI</option>
                </Select>
              </Field>
              <div />

              <Field
                label={t('settings.claude_key', 'Claude API key')}
                hint={s.has_claude_key ? t('settings.claude_key_stored', 'Key stored. Type a new one to replace, or clear.') : t('settings.claude_key_get', 'Get yours at console.anthropic.com')}
              >
                <PasswordInput
                  value={claudeKey}
                  onChange={(e) => setClaudeKey(e.target.value)}
                  hasStoredValue={s.has_claude_key}
                  maskedPreview={s.claude_api_key}
                  placeholder="sk-ant-..."
                />
                {s.has_claude_key && (
                  <div className="flex gap-2 mt-1">
                    <Button size="sm" variant="ghost" onClick={() => onClearKey('claude')}>{t('settings.clear_key', 'Clear key')}</Button>
                  </div>
                )}
              </Field>

              <Field
                label={t('settings.openai_key', 'OpenAI API key')}
                hint={s.has_openai_key ? t('settings.openai_key_stored', 'Key stored.') : t('settings.openai_key_get', 'Get yours at platform.openai.com')}
              >
                <PasswordInput
                  value={openaiKey}
                  onChange={(e) => setOpenaiKey(e.target.value)}
                  hasStoredValue={s.has_openai_key}
                  maskedPreview={s.openai_api_key}
                  placeholder="sk-..."
                />
                {s.has_openai_key && (
                  <div className="flex gap-2 mt-1">
                    <Button size="sm" variant="ghost" onClick={() => onClearKey('openai')}>{t('settings.clear_key', 'Clear key')}</Button>
                  </div>
                )}
              </Field>
            </div>
          </Card>
        </div>

        {/* Brand tone — spans 2 cols */}
        <div className="lg:col-span-2">
          <Card className="h-full">
            <SectionHeader
              title={t('settings.brand_tone_title', 'Brand tone of voice')}
              subtitle={t('settings.brand_tone_subtitle', 'Prepended to every AI prompt so generated content matches your brand')}
            />
            <Field
              label=""
              hint={t('settings.brand_tone_example', 'Example: "Professional, concise, no buzzwords. For automotive parts — keep terms like DAF, Scania, Euro 6 unchanged."')}
            >
              <Textarea
                value={brandTone}
                onChange={(e) => setBrandTone(e.target.value)}
                className="min-h-[140px]"
                placeholder={t('settings.brand_tone_placeholder', 'Describe how the AI should write for your brand...')}
              />
            </Field>
          </Card>
        </div>

        {/* Budget + rate limit — spans 2 cols */}
        <div className="lg:col-span-2">
          <Card className="h-full">
            <SectionHeader
              title={t('settings.budget_title', 'Budget & rate limits')}
              subtitle={t('settings.budget_subtitle', 'Protect against runaway costs and API throttling')}
            />
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <Field label={t('settings.daily_budget', 'Daily budget (USD)')} hint={t('settings.daily_budget_hint_short', 'AI runs stop queuing when this is reached')}>
                <Input
                  type="number"
                  min="0"
                  step="0.5"
                  value={dailyBudget}
                  onChange={(e) => setDailyBudget(e.target.value)}
                />
              </Field>
              <Field label={t('settings.rate_limit', 'Rate limit (req/min)')} hint={t('settings.rate_limit_hint_short', '0 = no limit')}>
                <Input
                  type="number"
                  min="0"
                  value={rateLimit}
                  onChange={(e) => setRateLimit(e.target.value)}
                />
              </Field>
            </div>
            <div className="mt-4">
              <Checkbox
                label={t('settings.mask_prices', 'Mask prices when sending to AI')}
                hint={t('settings.mask_prices_hint', 'Replaces product prices with placeholders in prompts')}
                checked={maskPrices}
                onChange={(e) => setMaskPrices(e.target.checked)}
              />
            </div>
          </Card>
        </div>

        {/* Data retention — spans full width, compact */}
        <div className="lg:col-span-4">
          <Card padding="md">
            <SectionHeader
              title={t('settings.retention_title', 'Data retention')}
              subtitle={t('settings.retention_subtitle', 'How long to keep AI run history before auto-pruning')}
            />
            <Field label={t('settings.retention_days', 'Retention (days)')}>
              <Input
                type="number"
                min="1"
                value={retentionDays}
                onChange={(e) => setRetentionDays(e.target.value)}
                className="max-w-[160px]"
              />
            </Field>
          </Card>
        </div>

        {/* Bulk processing */}
        <div className="lg:col-span-4">
          <Card padding="md">
            <SectionHeader
              title={t('settings.bulk_title', 'Bulk processing')}
              subtitle={t('settings.bulk_subtitle', 'How many products are processed per request when applying a bulk edit. Higher = faster, but each request takes longer.')}
            />
            <Field label={t('settings.bulk_chunk', 'Products per batch')}>
              <Input
                type="number"
                min="10"
                max="1000"
                value={bulkChunk}
                onChange={(e) => setBulkChunk(e.target.value)}
                className="max-w-[160px]"
              />
            </Field>
          </Card>
        </div>

        {/* Configuration portability */}
        <div className="lg:col-span-4">
          <ConfigPortabilityCard />
        </div>
      </div>

      {/* Footer actions — consistent position across all forms */}
      <div className="flex items-center justify-end gap-2 mt-6 pt-4 border-t border-border">
        <Button
          onClick={() => qc.invalidateQueries({ queryKey: ['settings'] })}
          disabled={saveMutation.isPending}
        >
          {t('settings.reset', 'Reset')}
        </Button>
        <Button variant="primary" onClick={onSave} disabled={saveMutation.isPending}>
          {saveMutation.isPending ? t('settings.saving', 'Saving…') : t('settings.save', 'Save settings')}
        </Button>
      </div>
    </PageCard>
  );
}

// =========================================================================
// Configuration portability — export to JSON / import from JSON
// =========================================================================

interface ImportReport {
  settings:  { applied: number; skipped: number };
  prompts:   { created: number; updated: number; skipped: number; errors: string[] };
  segments:  { created: number; errors: string[] };
  schedules: { created: number; errors: string[] };
}

function ConfigPortabilityCard() {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const [overwritePrompts, setOverwritePrompts] = useState(false);
  const [report, setReport] = useState<ImportReport | null>(null);

  const exportMutation = useMutation({
    mutationFn: () => api.get<{ ok: boolean; config: Record<string, unknown>; error?: string }>('/modules/smartbulk/api/config/export'),
    onSuccess: (r) => {
      if (!r.ok || !r.config) {
        toast.show(r.error || t('settings.toast.export_failed', 'Export failed'), 'error');
        return;
      }
      const blob = new Blob([JSON.stringify(r.config, null, 2)], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `smartbulk-config-${new Date().toISOString().replace(/[:.]/g, '-')}.json`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      setTimeout(() => URL.revokeObjectURL(url), 1000);
      toast.show(t('settings.toast.exported', 'Configuration exported'), 'success');
    },
    onError: (e: Error) => toast.show(e.message || t('settings.toast.export_failed', 'Export failed'), 'error'),
  });

  const importMutation = useMutation({
    mutationFn: (config: Record<string, unknown>) =>
      api.post<{ ok: boolean; report: ImportReport; error?: string }>(
        '/modules/smartbulk/api/config/import',
        { config, options: { overwrite_prompts: overwritePrompts } }
      ),
    onSuccess: (r) => {
      if (!r.ok || !r.report) {
        toast.show(r.error || t('settings.toast.import_failed', 'Import failed'), 'error');
        return;
      }
      setReport(r.report);
      toast.show(t('settings.toast.imported', 'Configuration imported'), 'success');
      qc.invalidateQueries({ queryKey: ['settings'] });
      qc.invalidateQueries({ queryKey: ['prompts'] });
      qc.invalidateQueries({ queryKey: ['segments'] });
      qc.invalidateQueries({ queryKey: ['schedules'] });
    },
    onError: (e: Error) => toast.show(e.message || t('settings.toast.import_failed', 'Import failed'), 'error'),
  });

  const onFile = async (file: File) => {
    let parsed: Record<string, unknown>;
    try {
      parsed = JSON.parse(await file.text());
    } catch (e) {
      toast.show(t('settings.toast.invalid_json', 'File is not valid JSON'), 'error');
      return;
    }
    const counts = describeBundle(parsed);
    const containsTpl = t('settings.import_contains', 'The file contains: {counts}.');
    const [containsBefore, containsAfter] = containsTpl.split('{counts}');
    const ok = await confirm({
      title: t('settings.import_confirm_title', 'Import this configuration?'),
      message: (
        <>
          {containsBefore}<strong>{counts}</strong>{containsAfter}
          {overwritePrompts && <div className="mt-1 text-amber-700">{t('settings.import_overwrite_warn', '⚠ Existing prompts with matching slugs will get a new version on top.')}</div>}
          <div className="mt-1 text-muted-foreground">{t('settings.import_keys_note', 'API keys are never imported — re-enter them in Provider keys.')}</div>
        </>
      ),
      confirmLabel: t('settings.import_button', 'Import'),
      tone: 'warning',
    });
    if (ok) importMutation.mutate(parsed);
  };

  return (
    <Card padding="md">
      <SectionHeader
        title={t('settings.config_portability', 'Configuration portability')}
        subtitle={t('settings.config_long_subtitle', 'Export your settings, prompts, saved segments and schedules to a JSON file — useful for backups or moving between shops. API keys are never exported.')}
      />
      <div className="flex flex-wrap items-center gap-2">
        <Button onClick={() => exportMutation.mutate()} disabled={exportMutation.isPending}>
          {exportMutation.isPending ? t('settings.exporting', 'Exporting…') : t('settings.export_config', '⬇ Export config')}
        </Button>
        <label className="inline-block">
          <input
            type="file"
            accept="application/json,.json"
            className="hidden"
            onChange={(e) => {
              const f = e.target.files?.[0];
              if (f) onFile(f);
              e.target.value = '';
            }}
          />
          <span className="inline-flex items-center justify-center gap-1.5 rounded-md font-medium transition-colors bg-white border border-border text-slate-900 hover:bg-muted px-3.5 py-2 text-[13px] cursor-pointer">
            {t('settings.import_config', '⬆ Import config…')}
          </span>
        </label>

        <label className="flex items-center gap-2 text-[12px] ml-2">
          <input type="checkbox" checked={overwritePrompts} onChange={(e) => setOverwritePrompts(e.target.checked)} />
          <span>{t('settings.overwrite_prompts', 'Overwrite existing prompts (adds new version on top)')}</span>
        </label>
      </div>

      {report && (
        <div className="mt-4 border border-border rounded-md bg-muted/30 p-3 text-[12px]">
          <div className="font-semibold mb-2">{t('settings.import_report', 'Import report')}</div>
          <ul className="list-disc pl-5 space-y-0.5">
            <li>{t2('settings.report.settings', 'Settings: {applied} applied · {skipped} skipped', { applied: report.settings.applied, skipped: report.settings.skipped })}</li>
            <li>{t2('settings.report.prompts', 'Prompts: {created} created · {updated} updated · {skipped} skipped', { created: report.prompts.created, updated: report.prompts.updated, skipped: report.prompts.skipped })}</li>
            <li>{t2('settings.report.segments', 'Segments: {created} created', { created: report.segments.created })}</li>
            <li>{t2('settings.report.schedules', 'Schedules: {created} created', { created: report.schedules.created })}</li>
          </ul>
          {(report.prompts.errors.length + report.segments.errors.length + report.schedules.errors.length) > 0 && (
            <div className="mt-2 text-red-700">
              <div className="font-semibold">{t('settings.report.errors', 'Errors:')}</div>
              <ul className="list-disc pl-5">
                {report.prompts.errors.map((e, i) => <li key={`p${i}`}>{e}</li>)}
                {report.segments.errors.map((e, i) => <li key={`s${i}`}>{e}</li>)}
                {report.schedules.errors.map((e, i) => <li key={`sc${i}`}>{e}</li>)}
              </ul>
            </div>
          )}
        </div>
      )}
    </Card>
  );
}

function describeBundle(p: Record<string, unknown>): string {
  const parts: string[] = [];
  const settings = (p.settings ?? {}) as Record<string, unknown>;
  if (Object.keys(settings).length) parts.push(t2('settings.bundle.settings', '{n} settings', { n: Object.keys(settings).length }));
  if (Array.isArray(p.prompts))   parts.push(t2('settings.bundle.prompts',   '{n} prompts',   { n: (p.prompts   as unknown[]).length }));
  if (Array.isArray(p.segments))  parts.push(t2('settings.bundle.segments',  '{n} segments',  { n: (p.segments  as unknown[]).length }));
  if (Array.isArray(p.schedules)) parts.push(t2('settings.bundle.schedules', '{n} schedules', { n: (p.schedules as unknown[]).length }));
  return parts.length ? parts.join(', ') : t('settings.bundle.empty', 'nothing recognizable');
}
