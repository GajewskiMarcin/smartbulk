import { useQuery } from '@tanstack/react-query';
import { lookupsApi } from '../../lib/segments';
import { cn } from '../../lib/utils';
import { t, t2 } from '../../lib/i18n';

interface Props {
  value: number | 'all';
  onChange: (v: number | 'all') => void;
  className?: string;
}

/**
 * Shop-language tabs shown above the action list. Applied as a default id_lang
 * to all multilang actions that don't have a per-action override.
 */
export default function GlobalLanguageTabs({ value, onChange, className }: Props) {
  const query = useQuery({
    queryKey: ['lookup', 'languages'],
    queryFn: lookupsApi.languages,
    staleTime: 10 * 60 * 1000,
  });

  const langs = query.data ?? [];
  // Hide the component entirely for single-language shops — no choice to make.
  if (query.isLoading || langs.length <= 1) return null;

  return (
    <div className={cn('flex items-center gap-1 border-b border-border', className)}>
      <span className="text-[11px] uppercase tracking-wide text-muted-foreground mr-2">{t('lang_tabs.target_language', 'Target language:')}</span>
      <Tab active={value === 'all'} onClick={() => onChange('all')}>
        {t2('lang_tabs.all_n', 'All ({n})', { n: langs.length })}
      </Tab>
      {langs.map((l) => (
        <Tab key={l.id_lang} active={value === l.id_lang} onClick={() => onChange(l.id_lang)}>
          {l.iso_code.toUpperCase()} — {l.name}
        </Tab>
      ))}
    </div>
  );
}

function Tab({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        'px-3 py-1.5 text-[12px] font-medium border-b-2 -mb-px transition-colors',
        active ? 'border-primary text-primary' : 'border-transparent text-slate-600 hover:text-slate-900'
      )}
    >
      {children}
    </button>
  );
}
