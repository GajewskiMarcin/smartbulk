import { useMemo } from 'react';
import type { FieldDef } from '../../lib/conditions';
import { t } from '../../lib/i18n';

interface Props {
  fields: FieldDef[];
  groupLabels: Record<string, string>;
  value: string;
  onChange: (fieldId: string) => void;
}

/**
 * Grouped <select> of all filterable fields.  Uses <optgroup> so users see
 * categories: Basic / Taxonomy / Price & stock / Content gaps / Behavior / Dates.
 */
export default function FieldDropdown({ fields, groupLabels, value, onChange }: Props) {
  const byGroup = useMemo(() => {
    const g: Record<string, FieldDef[]> = {};
    for (const f of fields) (g[f.group] ||= []).push(f);
    return g;
  }, [fields]);

  const groupOrder = ['basic', 'taxonomy', 'price_stock', 'content_gaps', 'behavior', 'date'];

  return (
    <select
      className="w-full px-3 py-2 border border-border rounded-md bg-white text-[13px]"
      value={value}
      onChange={(e) => onChange(e.target.value)}
    >
      <option value="">{t('cond.pick_field', '— Pick field —')}</option>
      {groupOrder.map((g) => {
        const items = byGroup[g] ?? [];
        if (items.length === 0) return null;
        return (
          <optgroup key={g} label={groupLabels[g] ?? g}>
            {items.map((f) => (
              <option key={f.id} value={f.id}>{f.label}</option>
            ))}
          </optgroup>
        );
      })}
    </select>
  );
}
