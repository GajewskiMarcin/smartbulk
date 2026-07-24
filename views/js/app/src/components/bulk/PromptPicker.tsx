import { useQuery } from '@tanstack/react-query';
import { Select } from '../Form';
import { promptsApi, TASK_TYPE_LABELS, type TaskType } from '../../lib/prompts';
import { t, t2 } from '../../lib/i18n';

interface Props {
  fieldId: string;              // target field — used to infer suggested task_type
  promptId: number | undefined;
  promptVersionId: number | null | undefined;
  onChange: (promptId: number | undefined, versionId: number | null) => void;
}

/**
 * Maps a bulk-editor target field to a suggested prompt task_type for pre-filtering.
 * Falls back to "all" when no strong match.
 */
function suggestedTaskType(fieldId: string): TaskType | null {
  switch (fieldId) {
    case 'meta_title':        return 'meta_title';
    case 'meta_description':  return 'meta_description';
    case 'description_short': return 'short_desc';
    case 'description':       return 'long_desc';
    default:                  return null;
  }
}

export default function PromptPicker({ fieldId, promptId, promptVersionId, onChange }: Props) {
  const suggested = suggestedTaskType(fieldId);

  // Load ALL prompts (not filtered) so user can pick anything if they need to.
  const query = useQuery({
    queryKey: ['prompts', 'all'],
    queryFn: () => promptsApi.list(),
    staleTime: 60_000,
  });

  const prompts = query.data ?? [];
  const matched  = suggested ? prompts.filter((p) => p.task_type === suggested) : [];
  const others   = suggested ? prompts.filter((p) => p.task_type !== suggested) : prompts;

  if (query.isLoading) {
    return <div className="text-[12px] text-muted-foreground py-2 px-3">{t('prompt_picker.loading', 'Loading prompts…')}</div>;
  }
  if (prompts.length === 0) {
    return (
      <div className="text-[12px] bg-amber-50 border border-amber-200 text-amber-900 rounded-md px-3 py-2 leading-relaxed">
        {t('prompt_picker.empty', 'No prompts defined yet. Go to Prompts to create one.')}
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-1.5">
      <Select
        value={promptId ? String(promptId) : ''}
        onChange={(e) => {
          const v = e.target.value;
          onChange(v === '' ? undefined : Number(v), null);
        }}
      >
        <option value="">{t('prompt_picker.select_prompt', '— select prompt —')}</option>
        {suggested && matched.length > 0 && (
          <optgroup label={t2('prompt_picker.suggested', 'Suggested: {label}', { label: TASK_TYPE_LABELS[suggested] })}>
            {matched.map((p) => (
              <option key={p.id_prompt} value={p.id_prompt}>
                {p.name}{p.current_model ? ` (${p.current_model})` : ''}
              </option>
            ))}
          </optgroup>
        )}
        {others.length > 0 && (
          <optgroup label={matched.length > 0 ? t('prompt_picker.other_prompts', 'Other prompts') : t('prompt_picker.all_prompts', 'All prompts')}>
            {others.map((p) => (
              <option key={p.id_prompt} value={p.id_prompt}>
                [{TASK_TYPE_LABELS[p.task_type]}] {p.name}
              </option>
            ))}
          </optgroup>
        )}
      </Select>

      {promptId && (
        <PromptVersionPicker
          promptId={promptId}
          promptVersionId={promptVersionId}
          onChange={(v) => onChange(promptId, v)}
        />
      )}
    </div>
  );
}

function PromptVersionPicker({
  promptId,
  promptVersionId,
  onChange,
}: {
  promptId: number;
  promptVersionId: number | null | undefined;
  onChange: (versionId: number | null) => void;
}) {
  const detailQuery = useQuery({
    queryKey: ['prompts', 'detail', promptId],
    queryFn: () => promptsApi.detail(promptId),
  });

  if (detailQuery.isLoading) return null;
  const detail = detailQuery.data;
  if (!detail) return null;

  return (
    <div className="flex items-center gap-2 text-[11px] text-muted-foreground">
      <span>{t('prompt_picker.version_label', 'Version:')}</span>
      <Select
        value={promptVersionId ? String(promptVersionId) : 'current'}
        onChange={(e) => onChange(e.target.value === 'current' ? null : Number(e.target.value))}
        className="max-w-[260px]"
      >
        <option value="current">
          {t('prompt_picker.always_current', 'Always use current')}
          {detail.prompt.current_version_number ? ` (v${detail.prompt.current_version_number})` : ''}
        </option>
        {detail.versions.map((v) => (
          <option key={v.id_prompt_version} value={v.id_prompt_version}>
            {t2('prompt_picker.pin_to', 'Pin to v{n} ({model})', { n: v.version_number, model: v.model })}
          </option>
        ))}
      </Select>
    </div>
  );
}
