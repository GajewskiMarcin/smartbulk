import type { FieldDef, TreeSpec } from '../../lib/conditions';
import { renderQueryPreview } from '../../lib/conditions';
import { t } from '../../lib/i18n';

interface Props {
  tree: TreeSpec;
  fields: FieldDef[];
}

/**
 * Human-readable SQL-like preview of the condition tree.
 */
export default function QueryPreview({ tree, fields }: Props) {
  const text = renderQueryPreview(tree, fields);
  const isEmpty = text.trim() === '';

  return (
    <div>
      <div className="text-[10px] uppercase tracking-wide font-semibold text-muted-foreground mb-1">
        {t('cond.query_preview', 'Query preview')}
      </div>
      <pre className="bg-muted rounded-md px-3 py-2 text-[11px] leading-relaxed text-slate-700 overflow-x-auto whitespace-pre-wrap font-mono m-0">
        {isEmpty ? t('cond.no_conditions', '(no conditions yet — matches ALL products)') : text}
      </pre>
    </div>
  );
}
