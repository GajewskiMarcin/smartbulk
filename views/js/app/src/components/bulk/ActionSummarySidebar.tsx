import { operatorLabel, type BulkAction, type FieldDefinition } from '../../lib/bulk';
import { t, t2 } from '../../lib/i18n';

interface Props {
  fields: FieldDefinition[];
  actions: BulkAction[];
  globalLang: number | 'all';
  onScrollTo: (idx: number) => void;
}

/**
 * Sticky summary of the currently configured actions. Each line: field · op · short value.
 * Clicking scrolls the action row into view.
 */
export default function ActionSummarySidebar({ fields, actions, globalLang, onScrollTo }: Props) {
  const active = actions.filter((a) => a.operator !== 'skip');

  return (
    <div className="bg-white border border-border rounded-lg p-4 sticky top-4">
      <div className="flex items-center justify-between mb-2">
        <div className="font-semibold text-[13px]">{t('action_sidebar.title', 'Action summary')}</div>
        <span className="text-[11px] text-muted-foreground">{t2('action_sidebar.n_active', '{n} active', { n: active.length })}</span>
      </div>

      {actions.length === 0 && (
        <div className="text-[12px] text-muted-foreground leading-relaxed">
          {t('action_sidebar.empty', 'No actions yet. Use Load template or + Add field to start.')}
        </div>
      )}

      <div className="flex flex-col divide-y divide-border">
        {actions.map((a, idx) => {
          const def = fields.find((f) => f.id === a.field);
          if (!def) return null;
          return (
            <button
              key={idx}
              type="button"
              onClick={() => onScrollTo(idx)}
              className="text-left py-2 first:pt-0 last:pb-0 hover:bg-muted/40 rounded -mx-1 px-1 focus:outline-none focus:bg-muted"
            >
              <div className="text-[12px] font-semibold text-slate-900 truncate">{def.label}</div>
              <div className="text-[11px] text-muted-foreground truncate">
                {operatorLabel(a.operator)}
                {describeValue(a)}
                {def.lang && describeLanguage(a.id_lang, globalLang)}
              </div>
            </button>
          );
        })}
      </div>
    </div>
  );
}

function describeValue(a: BulkAction): string {
  if (a.operator === 'skip' || a.operator === 'clear') return '';
  if (a.operator === 'math') {
    if (a.math_op === undefined || a.math_value === undefined) return ' · …';
    return ` · ${a.math_op} ${a.math_value}`;
  }
  if (a.operator === 'replace') {
    return ` · "${truncate(a.find ?? '')}" → "${truncate(a.replace ?? '')}"`;
  }
  if (a.operator === 'ai_generate') {
    return a.prompt_id ? ` · ${t2('action_sidebar.prompt_n', 'prompt #{n}', { n: a.prompt_id })}` : ' · ' + t('action_sidebar.no_prompt', '⚠ no prompt picked');
  }
  if (a.operator === 'copy_from') {
    return a.source_field ? ` · ← ${a.source_field}` : ' · ' + t('action_sidebar.no_source', '⚠ no source picked');
  }
  if (a.operator === 'generate_from_name' || a.operator === 'generate_from_short_desc') return '';
  if (a.value === undefined || a.value === null || a.value === '') return ' · …';
  if (typeof a.value === 'boolean') return ` · ${a.value ? t('common.yes', 'Yes') : t('common.no', 'No')}`;
  return ` · "${truncate(String(a.value))}"`;
}

function describeLanguage(perAction: number | null | undefined, global: number | 'all'): string {
  // Per-action overrides are rendered explicitly; otherwise defer to global.
  if (perAction !== null && perAction !== undefined) return ' · ' + t2('action_sidebar.lang_n', 'lang {n}', { n: perAction });
  return global === 'all' ? ' · ' + t('action_sidebar.all_langs', 'all langs') : ' · ' + t2('action_sidebar.lang_n', 'lang {n}', { n: global });
}

function truncate(s: string, n = 24): string {
  if (s.length <= n) return s;
  return s.slice(0, n) + '…';
}
