import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import PageHead from '../components/PageHead';
import PageCard from '../components/PageCard';
import Card from '../components/Card';
import SectionHeader from '../components/SectionHeader';
import Button from '../components/Button';
import Badge from '../components/Badge';
import { Field, Input, Select, Textarea } from '../components/Form';
import { useToast } from '../components/Toast';
import { useConfirm } from '../components/ConfirmDialog';
import {
  promptsApi,
  TASK_TYPE_LABELS,
  MODELS_BY_PROVIDER,
  PLACEHOLDERS,
  type Prompt,
  type PromptDetail,
  type PromptVersion,
  type Provider,
  type TaskType,
} from '../lib/prompts';
import { cn } from '../lib/utils';
import { t, t2 } from '../lib/i18n';

export default function Prompts() {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();

  const [search, setSearch] = useState('');
  const [taskFilter, setTaskFilter] = useState<TaskType | ''>('');
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [creating, setCreating] = useState(false);

  const promptsQuery = useQuery({
    queryKey: ['prompts', search, taskFilter],
    queryFn: () => promptsApi.list({ search, task_type: taskFilter || undefined }),
  });

  const detailQuery = useQuery({
    queryKey: ['prompts', 'detail', selectedId],
    queryFn: () => promptsApi.detail(selectedId as number),
    enabled: selectedId !== null,
  });

  // Auto-select first prompt when list loads
  useEffect(() => {
    if (selectedId === null && promptsQuery.data && promptsQuery.data.length > 0) {
      setSelectedId(promptsQuery.data[0].id_prompt);
    }
  }, [promptsQuery.data, selectedId]);

  const deleteMutation = useMutation({
    mutationFn: (id: number) => promptsApi.delete(id),
    onSuccess: () => {
      toast.show(t('prompts.toast_deleted', 'Prompt deleted'), 'success');
      setSelectedId(null);
      qc.invalidateQueries({ queryKey: ['prompts'] });
    },
    onError: (e: Error) => toast.show(e.message || t('prompts.toast_delete_failed', 'Delete failed'), 'error'),
  });

  return (
    <PageCard>
      <PageHead
        title={t('prompts.title', 'Prompt Library')}
        subtitle={
          promptsQuery.data
            ? t2('prompts.count', '{n} prompts', { n: promptsQuery.data.length })
            : t('prompts.subtitle_default', 'Versioned AI prompts — edit, test, compare')
        }
        actions={
          <Button variant="primary" onClick={() => setCreating(true)}>
            {t('prompts.new_prompt', '+ New prompt')}
          </Button>
        }
      />

      <div className="grid grid-cols-1 lg:grid-cols-[280px_1fr] gap-4 min-w-0">
        {/* Sidebar list */}
        <Card padding="sm" className="h-fit lg:sticky lg:top-4">
          <div className="flex flex-col gap-2 mb-3">
            <Input
              placeholder={t('prompts.search_placeholder', '🔍 Search prompts...')}
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
            <Select
              value={taskFilter}
              onChange={(e) => setTaskFilter(e.target.value as TaskType | '')}
            >
              <option value="">{t('prompts.all_task_types', 'All task types')}</option>
              {(Object.entries(TASK_TYPE_LABELS) as [TaskType, string][]).map(([k, v]) => (
                <option key={k} value={k}>{v}</option>
              ))}
            </Select>
          </div>

          {promptsQuery.isLoading && (
            <div className="text-muted-foreground text-[12px] p-2">{t('common.loading', 'Loading…')}</div>
          )}
          {promptsQuery.isError && (
            <div className="text-destructive text-[12px] p-2">{t('prompts.load_failed', 'Failed to load prompts')}</div>
          )}
          {promptsQuery.data && promptsQuery.data.length === 0 && (
            <div className="text-muted-foreground text-[12px] p-2">
              {t('prompts.empty_hint', 'No prompts yet. Click + New prompt.')}
            </div>
          )}

          <div className="flex flex-col gap-0.5 max-h-[60vh] overflow-y-auto -mx-1 px-1">
            {promptsQuery.data?.map((p) => (
              <PromptListItem
                key={p.id_prompt}
                prompt={p}
                selected={selectedId === p.id_prompt}
                onClick={() => setSelectedId(p.id_prompt)}
              />
            ))}
          </div>
        </Card>

        {/* Detail / Editor */}
        <div className="min-w-0">
          {creating ? (
            <NewPromptForm
              onCancel={() => setCreating(false)}
              onCreated={(d) => {
                setCreating(false);
                qc.invalidateQueries({ queryKey: ['prompts'] });
                setSelectedId(d.prompt.id_prompt);
              }}
            />
          ) : selectedId === null ? (
            <Card>
              <div className="text-muted-foreground text-[13px] py-8 text-center">
                {t('prompts.select_or_create', 'Select a prompt from the list, or create a new one.')}
              </div>
            </Card>
          ) : detailQuery.isLoading ? (
            <Card>
              <div className="text-muted-foreground text-[13px] py-4">{t('prompts.loading_prompt', 'Loading prompt…')}</div>
            </Card>
          ) : detailQuery.data ? (
            <PromptDetailEditor
              key={selectedId}
              detail={detailQuery.data}
              onChanged={() => {
                qc.invalidateQueries({ queryKey: ['prompts'] });
                qc.invalidateQueries({ queryKey: ['prompts', 'detail', selectedId] });
              }}
              onDelete={async () => {
                const ok = await confirm({
                  title: t('prompts.confirm_delete_title', 'Delete prompt?'),
                  message: (
                    <>
                      {t2('prompts.confirm_delete_msg', 'Delete "{name}" and all its versions. This cannot be undone.', { name: detailQuery.data?.prompt.name ?? '' })}
                    </>
                  ),
                  confirmLabel: t('common.delete', 'Delete'),
                  tone: 'destructive',
                });
                if (ok) deleteMutation.mutate(selectedId);
              }}
            />
          ) : null}
        </div>
      </div>
    </PageCard>
  );
}

// ---------------- Sidebar list item ----------------

function PromptListItem({
  prompt,
  selected,
  onClick,
}: {
  prompt: Prompt;
  selected: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        'text-left px-3 py-2.5 rounded-md border transition-colors',
        selected
          ? 'bg-primary-50 border-primary text-primary-700'
          : 'bg-transparent border-transparent hover:bg-muted'
      )}
    >
      <div className={cn('font-semibold text-[13px] truncate', selected && 'text-primary-700')}>
        {prompt.name}
      </div>
      <div className="text-[11px] text-muted-foreground mt-0.5 truncate">
        {TASK_TYPE_LABELS[prompt.task_type]}
        {prompt.current_version_number && ` · v${prompt.current_version_number}`}
        {prompt.current_provider && ` · ${prompt.current_provider}`}
      </div>
    </button>
  );
}

// ---------------- New prompt form ----------------

function NewPromptForm({
  onCancel,
  onCreated,
}: {
  onCancel: () => void;
  onCreated: (d: PromptDetail) => void;
}) {
  const toast = useToast();
  const [name, setName] = useState('');
  const [taskType, setTaskType] = useState<TaskType>('meta_description');
  const [provider, setProvider] = useState<Provider>('claude');
  const [model, setModel] = useState<string>(MODELS_BY_PROVIDER.claude[0].id);
  const [systemPrompt, setSystemPrompt] = useState('');
  const [userPrompt, setUserPrompt] = useState('');

  const createMutation = useMutation({
    mutationFn: () =>
      promptsApi.create({
        name,
        task_type: taskType,
        provider,
        model,
        system_prompt: systemPrompt,
        user_prompt: userPrompt,
        params: { temperature: 0.4, max_tokens: 256 },
      }),
    onSuccess: (d) => {
      toast.show(t('prompts.toast_created', 'Prompt created'), 'success');
      onCreated(d);
    },
    onError: (e: Error) => toast.show(e.message || t('prompts.toast_create_failed', 'Create failed'), 'error'),
  });

  return (
    <Card>
      <SectionHeader
        title={t('prompts.section_create', 'Create new prompt')}
        subtitle={t('prompts.section_create_sub', 'A v1 will be created with your initial content')}
      />
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <Field label={t('prompts.field_name', 'Name')} required>
          <Input value={name} onChange={(e) => setName(e.target.value)} placeholder={t('prompts.field_name_placeholder', 'e.g. Meta description — automotive')} />
        </Field>
        <Field label={t('prompts.field_task_type', 'Task type')} required>
          <Select value={taskType} onChange={(e) => setTaskType(e.target.value as TaskType)}>
            {(Object.entries(TASK_TYPE_LABELS) as [TaskType, string][]).map(([k, v]) => (
              <option key={k} value={k}>{v}</option>
            ))}
          </Select>
        </Field>
        <Field label={t('prompts.field_provider', 'Provider')}>
          <Select
            value={provider}
            onChange={(e) => {
              const p = e.target.value as Provider;
              setProvider(p);
              setModel(MODELS_BY_PROVIDER[p][0].id);
            }}
          >
            <option value="claude">{t('prompts.provider_claude', 'Claude (Anthropic)')}</option>
            <option value="openai">{t('prompts.provider_openai', 'OpenAI')}</option>
          </Select>
        </Field>
        <Field label={t('prompts.field_model', 'Model')}>
          <Select value={model} onChange={(e) => setModel(e.target.value)}>
            {MODELS_BY_PROVIDER[provider].map((m) => (
              <option key={m.id} value={m.id}>{m.label}</option>
            ))}
          </Select>
        </Field>
      </div>
      <div className="mt-4 grid grid-cols-1 gap-4">
        <PromptTextarea
          label={t('prompts.field_system', 'System prompt')}
          value={systemPrompt}
          onChange={setSystemPrompt}
          placeholder={t('prompts.field_system_placeholder', 'You are an SEO copywriter...')}
        />
        <PromptTextarea
          label={t('prompts.field_user', 'User prompt template')}
          value={userPrompt}
          onChange={setUserPrompt}
          placeholder={t('prompts.field_user_placeholder', 'Generate a meta description for: {name}, {category}...')}
          showPlaceholders
        />
      </div>

      <div className="flex items-center justify-end gap-2 mt-6 pt-4 border-t border-border">
        <Button onClick={onCancel}>{t('common.cancel', 'Cancel')}</Button>
        <Button
          variant="primary"
          disabled={!name || !systemPrompt || !userPrompt || createMutation.isPending}
          onClick={() => createMutation.mutate()}
        >
          {createMutation.isPending ? t('prompts.creating', 'Creating…') : t('prompts.create', 'Create')}
        </Button>
      </div>
    </Card>
  );
}

// ---------------- Detail / Editor ----------------

function PromptDetailEditor({
  detail,
  onChanged,
  onDelete,
}: {
  detail: PromptDetail;
  onChanged: () => void;
  onDelete: () => void;
}) {
  const toast = useToast();
  const { prompt, versions } = detail;

  const currentVersion = useMemo(
    () => versions.find((v) => v.id_prompt_version === prompt.current_version) ?? versions[0],
    [versions, prompt.current_version]
  );

  // Local editing state — initialized from current version, dirty-tracked
  const [name, setName] = useState(prompt.name);
  const [systemPrompt, setSystemPrompt] = useState(currentVersion?.system_prompt ?? '');
  const [userPrompt, setUserPrompt] = useState(currentVersion?.user_prompt ?? '');
  const [provider, setProvider] = useState<Provider>(currentVersion?.provider ?? 'claude');
  const [model, setModel] = useState<string>(currentVersion?.model ?? MODELS_BY_PROVIDER.claude[0].id);
  const [temperature, setTemperature] = useState<string>(
    String((currentVersion?.params as { temperature?: number } | null)?.temperature ?? 0.4)
  );
  const [maxTokens, setMaxTokens] = useState<string>(
    String((currentVersion?.params as { max_tokens?: number } | null)?.max_tokens ?? 256)
  );
  const [notes, setNotes] = useState('');

  // Re-init when prompt or current version changes
  useEffect(() => {
    setName(prompt.name);
    setSystemPrompt(currentVersion?.system_prompt ?? '');
    setUserPrompt(currentVersion?.user_prompt ?? '');
    setProvider(currentVersion?.provider ?? 'claude');
    setModel(currentVersion?.model ?? MODELS_BY_PROVIDER.claude[0].id);
    setTemperature(
      String((currentVersion?.params as { temperature?: number } | null)?.temperature ?? 0.4)
    );
    setMaxTokens(
      String((currentVersion?.params as { max_tokens?: number } | null)?.max_tokens ?? 256)
    );
    setNotes('');
  }, [prompt.id_prompt, prompt.name, currentVersion?.id_prompt_version]);

  const renameMutation = useMutation({
    mutationFn: () => promptsApi.rename(prompt.id_prompt, name),
    onSuccess: () => { toast.show(t('prompts.toast_name_updated', 'Name updated'), 'success'); onChanged(); },
    onError: (e: Error) => toast.show(e.message || t('prompts.toast_rename_failed', 'Rename failed'), 'error'),
  });

  const versionMutation = useMutation({
    mutationFn: () =>
      promptsApi.createVersion(prompt.id_prompt, {
        system_prompt: systemPrompt,
        user_prompt: userPrompt,
        provider,
        model,
        params: {
          temperature: Number(temperature) || 0,
          max_tokens: Number(maxTokens) || 256,
        },
        notes,
      }),
    onSuccess: () => { toast.show(t('prompts.toast_version_saved', 'New version saved & set as current'), 'success'); onChanged(); },
    onError: (e: Error) => toast.show(e.message || t('prompts.toast_save_failed', 'Save failed'), 'error'),
  });

  const activateMutation = useMutation({
    mutationFn: (versionId: number) => promptsApi.activateVersion(prompt.id_prompt, versionId),
    onSuccess: () => { toast.show(t('prompts.toast_activated', 'Version activated'), 'success'); onChanged(); },
    onError: (e: Error) => toast.show(e.message || t('prompts.toast_activate_failed', 'Activate failed'), 'error'),
  });

  const isDirty =
    name !== prompt.name
    || systemPrompt !== (currentVersion?.system_prompt ?? '')
    || userPrompt !== (currentVersion?.user_prompt ?? '')
    || provider !== (currentVersion?.provider ?? 'claude')
    || model !== (currentVersion?.model ?? '')
    || Number(temperature) !== ((currentVersion?.params as { temperature?: number } | null)?.temperature ?? 0.4)
    || Number(maxTokens) !== ((currentVersion?.params as { max_tokens?: number } | null)?.max_tokens ?? 256);

  return (
    <div className="flex flex-col gap-4 min-w-0">
      <Card>
        <SectionHeader
          title={prompt.name}
          subtitle={
            <>
              <span className="font-mono text-[11px]">{prompt.slug}</span>
              {' · '}
              {TASK_TYPE_LABELS[prompt.task_type]}
              {currentVersion && ` · ${t2('prompts.current_v', 'current: v{v}', { v: currentVersion.version_number })}`}
            </>
          }
          action={
            <>
              <Button onClick={onDelete} variant="destructive" size="sm">{t('prompts.delete_btn', '🗑 Delete')}</Button>
            </>
          }
        />

        <div className="grid grid-cols-1 md:grid-cols-[1fr_auto] gap-3 items-end mb-4">
          <Field label={t('prompts.field_name', 'Name')}>
            <Input value={name} onChange={(e) => setName(e.target.value)} />
          </Field>
          <Button
            disabled={name === prompt.name || renameMutation.isPending}
            onClick={() => renameMutation.mutate()}
          >
            {renameMutation.isPending ? t('common.saving', 'Saving…') : t('prompts.save_name', 'Save name')}
          </Button>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <Field label={t('prompts.field_provider', 'Provider')}>
            <Select
              value={provider}
              onChange={(e) => {
                const p = e.target.value as Provider;
                setProvider(p);
                if (!MODELS_BY_PROVIDER[p].some((m) => m.id === model)) {
                  setModel(MODELS_BY_PROVIDER[p][0].id);
                }
              }}
            >
              <option value="claude">{t('prompts.provider_claude_short', 'Claude')}</option>
              <option value="openai">{t('prompts.provider_openai', 'OpenAI')}</option>
            </Select>
          </Field>
          <Field label={t('prompts.field_model', 'Model')}>
            <Select value={model} onChange={(e) => setModel(e.target.value)}>
              {MODELS_BY_PROVIDER[provider].map((m) => (
                <option key={m.id} value={m.id}>{m.label}</option>
              ))}
            </Select>
          </Field>
          <Field label={t('prompts.field_temperature', 'Temperature')} hint={t('prompts.temp_hint', '0 = deterministic, 1 = creative')}>
            <Input
              type="number" min="0" max="2" step="0.1"
              value={temperature}
              onChange={(e) => setTemperature(e.target.value)}
            />
          </Field>
          <Field label={t('prompts.field_max_tokens', 'Max tokens')} hint={t('prompts.max_tokens_hint', 'Output length cap')}>
            <Input
              type="number" min="1"
              value={maxTokens}
              onChange={(e) => setMaxTokens(e.target.value)}
            />
          </Field>
        </div>

        <div className="grid grid-cols-1 gap-4 mt-4">
          <PromptTextarea label={t('prompts.field_system', 'System prompt')} value={systemPrompt} onChange={setSystemPrompt} />
          <PromptTextarea label={t('prompts.field_user', 'User prompt template')} value={userPrompt} onChange={setUserPrompt} showPlaceholders />
        </div>

        <div className="mt-4">
          <Field label={t('prompts.field_changelog', 'Changelog (for the new version)')} hint={t('prompts.changelog_hint', 'What changed and why?')}>
            <Textarea
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              className="min-h-[64px]"
              placeholder={t('prompts.changelog_placeholder', 'e.g. Tightened the character limit. Switched model to Haiku for cost.')}
            />
          </Field>
        </div>

        <div className="flex items-center justify-between mt-4 pt-4 border-t border-border">
          <div className={cn('text-[12px]', isDirty ? 'text-amber-700 font-medium' : 'text-muted-foreground')}>
            {isDirty
              ? t('prompts.dirty_msg', '● Unsaved changes — saving creates a new version.')
              : t('prompts.clean_msg', 'No content changes since current version. Saving will create a duplicate version (useful for locking in a changelog note).')}
          </div>
          <Button
            variant="primary"
            disabled={!systemPrompt || !userPrompt || versionMutation.isPending}
            onClick={() => versionMutation.mutate()}
          >
            {versionMutation.isPending ? t('common.saving', 'Saving…') : t('prompts.save_new_version', '💾 Save as new version')}
          </Button>
        </div>
      </Card>

      {/* Version history */}
      <Card>
        <SectionHeader
          title={t('prompts.version_history', 'Version history')}
          subtitle={t2('prompts.versions_count', '{n} version{plural}', { n: versions.length, plural: versions.length !== 1 ? 's' : '' })}
        />
        <div className="flex flex-col gap-2">
          {versions.map((v) => (
            <VersionRow
              key={v.id_prompt_version}
              version={v}
              isCurrent={v.id_prompt_version === prompt.current_version}
              onActivate={() => activateMutation.mutate(v.id_prompt_version)}
              activating={activateMutation.isPending}
            />
          ))}
        </div>
      </Card>
    </div>
  );
}

function VersionRow({
  version,
  isCurrent,
  onActivate,
  activating,
}: {
  version: PromptVersion;
  isCurrent: boolean;
  onActivate: () => void;
  activating: boolean;
}) {
  return (
    <div
      className={cn(
        'flex items-start justify-between gap-3 p-3 rounded-md border',
        isCurrent ? 'border-primary bg-primary-50' : 'border-border bg-white'
      )}
    >
      <div className="min-w-0">
        <div className="flex items-center gap-2 flex-wrap">
          <strong className="text-[13px]">v{version.version_number}</strong>
          {isCurrent && <Badge variant="primary">{t('prompts.badge_current', 'current')}</Badge>}
          <span className="text-[11px] text-muted-foreground">
            {version.provider} · {version.model} · {new Date(version.created_at).toLocaleString()}
          </span>
        </div>
        {version.notes && (
          <div className="text-[12px] text-slate-700 mt-1 break-words">{version.notes}</div>
        )}
      </div>
      {!isCurrent && (
        <Button size="sm" onClick={onActivate} disabled={activating}>
          {t('prompts.set_current', 'Set as current')}
        </Button>
      )}
    </div>
  );
}

// ---------------- Reusable prompt textarea ----------------

function PromptTextarea({
  label,
  value,
  onChange,
  placeholder,
  showPlaceholders,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  placeholder?: string;
  showPlaceholders?: boolean;
}) {
  return (
    <Field label={label}>
      <Textarea
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        className="min-h-[140px] font-mono text-[12px] leading-relaxed"
      />
      {showPlaceholders && (
        <div className="flex flex-wrap gap-1 mt-1.5">
          {PLACEHOLDERS.map((p) => (
            <button
              type="button"
              key={p}
              onClick={() => onChange(value + (value && !value.endsWith(' ') ? ' ' : '') + p)}
              className="px-2 py-0.5 rounded-full text-[10px] font-mono bg-primary-50 text-primary-700 hover:bg-primary-100 transition-colors"
              title={t('prompts.click_to_insert', 'Click to insert at end')}
            >
              {p}
            </button>
          ))}
        </div>
      )}
    </Field>
  );
}
