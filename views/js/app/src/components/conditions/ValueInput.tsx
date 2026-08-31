import { useQuery } from '@tanstack/react-query';
import MultiSelect from '../MultiSelect';
import { Input } from '../Form';
import {
  conditionsApi,
  NO_VALUE_OPS,
  TWO_VALUE_OPS,
  type ConditionLeaf,
  type FieldDef,
  type Operator,
} from '../../lib/conditions';
import { lookupsApi } from '../../lib/segments';
import { t } from '../../lib/i18n';

interface Props {
  field: FieldDef;
  leaf: ConditionLeaf;
  onChange: (patch: Partial<ConditionLeaf>) => void;
}

/**
 * Renders the right-hand-side value input for a condition, chosen by
 * field.value_type + operator.  Single source of truth for "what input to show".
 */
export default function ValueInput({ field, leaf, onChange }: Props) {
  const op = leaf.op;

  if (NO_VALUE_OPS.includes(op)) {
    return <div className="text-[12px] text-muted-foreground py-2 px-3">{t('cond.no_value_needed', '(no value needed)')}</div>;
  }

  // Related many
  if (field.value_type === 'related_many') {
    return <RelatedValue field={field} leaf={leaf} onChange={onChange} />;
  }

  // Enum
  if (field.value_type === 'enum') {
    const options = field.options ?? [];
    const isMulti = op === 'is_one_of' || op === 'is_not_one_of';
    if (isMulti) {
      const current = Array.isArray(leaf.value) ? leaf.value.map(String) : [];
      return (
        <select
          className="w-full px-3 py-2 border border-border rounded-md bg-white text-[13px] min-h-[90px]"
          multiple
          value={current}
          onChange={(e) =>
            onChange({ value: Array.from(e.target.selectedOptions).map((o) => o.value) })
          }
        >
          {options.map((o) => (
            <option key={o.value} value={o.value}>{o.label}</option>
          ))}
        </select>
      );
    }
    return (
      <select
        className="w-full px-3 py-2 border border-border rounded-md bg-white text-[13px]"
        value={String(leaf.value ?? options[0]?.value ?? '')}
        onChange={(e) => onChange({ value: e.target.value })}
      >
        {options.map((o) => (
          <option key={o.value} value={o.value}>{o.label}</option>
        ))}
      </select>
    );
  }

  // Between (two values)
  if (TWO_VALUE_OPS.includes(op)) {
    const isText = op === 'length_between';
    return (
      <div className="grid grid-cols-2 gap-2">
        <Input
          type="number"
          placeholder={t('cond.from', 'From')}
          value={leaf.value === undefined || leaf.value === null ? '' : String(leaf.value)}
          onChange={(e) => onChange({ value: e.target.value === '' ? null : Number(e.target.value) })}
        />
        <Input
          type="number"
          placeholder={t('cond.to', 'To')}
          value={leaf.value2 === undefined || leaf.value2 === null ? '' : String(leaf.value2)}
          onChange={(e) => onChange({ value2: e.target.value === '' ? null : Number(e.target.value) })}
        />
        {isText && <div className="text-[11px] text-muted-foreground col-span-2">{t('cond.character_count', '(character count)')}</div>}
      </div>
    );
  }

  // Text / length single
  if (
    field.value_type === 'text'
    || op === 'length_lt' || op === 'length_gt' || op === 'length_eq'
  ) {
    const isLength = op.startsWith('length_');
    if (isLength) {
      return (
        <Input
          type="number"
          placeholder={t('cond.chars', 'chars')}
          value={leaf.value === undefined || leaf.value === null ? '' : String(leaf.value)}
          onChange={(e) => onChange({ value: e.target.value === '' ? null : Number(e.target.value) })}
        />
      );
    }
    return (
      <Input
        type="text"
        placeholder={op === 'matches_regex' ? '/pattern/' : t('cond.value', 'Value')}
        value={leaf.value === undefined || leaf.value === null ? '' : String(leaf.value)}
        onChange={(e) => onChange({ value: e.target.value })}
      />
    );
  }

  // Numeric
  if (field.value_type === 'int' || field.value_type === 'float') {
    return (
      <Input
        type="number"
        step={field.value_type === 'int' ? '1' : 'any'}
        placeholder={field.unit === 'currency' ? '0.00' : '0'}
        value={leaf.value === undefined || leaf.value === null ? '' : String(leaf.value)}
        onChange={(e) => onChange({ value: e.target.value === '' ? null : Number(e.target.value) })}
      />
    );
  }

  // Date
  if (field.value_type === 'date') {
    if (op === 'last_n_days' || op === 'next_n_days') {
      return (
        <Input
          type="number"
          min="0"
          placeholder={t('cond.n_days', 'N days')}
          value={leaf.value === undefined || leaf.value === null ? '' : String(leaf.value)}
          onChange={(e) => onChange({ value: e.target.value === '' ? null : Number(e.target.value) })}
        />
      );
    }
    return (
      <Input
        type="date"
        value={leaf.value === undefined || leaf.value === null ? '' : String(leaf.value)}
        onChange={(e) => onChange({ value: e.target.value })}
      />
    );
  }

  return null;
}

// ---------- Related-many value picker ----------

function RelatedValue({ field, leaf, onChange }: Props) {
  switch (field.lookup) {
    case 'categories': return <CategoriesValue leaf={leaf} onChange={onChange} />;
    case 'brands':     return <BrandsValue leaf={leaf} onChange={onChange} />;
    case 'suppliers':  return <SuppliersValue leaf={leaf} onChange={onChange} />;
    case 'tags':       return <TagsValue leaf={leaf} onChange={onChange} />;
    case 'features':   return <FeaturesValue leaf={leaf} onChange={onChange} />;
    case 'attributes': return <AttributesValue leaf={leaf} onChange={onChange} />;
    case 'tax_rules':  return <TaxRulesValue leaf={leaf} onChange={onChange} />;
    default:           return null;
  }
}

function TaxRulesValue({ leaf, onChange }: Omit<Props, 'field'>) {
  const q = useQuery({ queryKey: ['lookup', 'tax_rules'], queryFn: lookupsApi.taxRules });
  const options = (q.data ?? []).map((t) => ({ id: t.id_tax_rules_group, label: t.name }));
  // Allow picking "No tax" (id=0) explicitly so users can build "no-tax products" segments.
  const optionsWithNoTax = [{ id: 0, label: t('cond.no_tax', 'No tax') }, ...options];
  const value = Array.isArray(leaf.value) ? leaf.value.map(Number) : [];
  return <MultiSelect options={optionsWithNoTax} value={value} onChange={(ids) => onChange({ value: ids })} placeholder={t('cond.pick_tax_rules', 'Pick tax rules…')} />;
}

function CategoriesValue({ leaf, onChange }: Omit<Props, 'field'>) {
  const q = useQuery({ queryKey: ['lookup', 'categories'], queryFn: lookupsApi.categories });
  const options = (q.data ?? []).map((c) => ({
    id: c.id_category,
    label: '— '.repeat(Math.max(0, c.level_depth - 1)) + c.name,
    sublabel: `#${c.id_category}`,
  }));
  const value = Array.isArray(leaf.value) ? leaf.value.map(Number) : [];
  return (
    <MultiSelect
      options={options}
      value={value}
      onChange={(ids) => onChange({ value: ids })}
      placeholder={t('cond.pick_categories', 'Pick categories…')}
    />
  );
}

function BrandsValue({ leaf, onChange }: Omit<Props, 'field'>) {
  const q = useQuery({ queryKey: ['lookup', 'brands'], queryFn: lookupsApi.brands });
  const options = (q.data ?? []).map((b) => ({ id: b.id_manufacturer, label: b.name }));
  const value = Array.isArray(leaf.value) ? leaf.value.map(Number) : [];
  return <MultiSelect options={options} value={value} onChange={(ids) => onChange({ value: ids })} placeholder={t('cond.pick_brands', 'Pick brands…')} />;
}

function SuppliersValue({ leaf, onChange }: Omit<Props, 'field'>) {
  const q = useQuery({ queryKey: ['lookup', 'suppliers'], queryFn: lookupsApi.suppliers });
  const options = (q.data ?? []).map((s) => ({ id: s.id_supplier, label: s.name }));
  const value = Array.isArray(leaf.value) ? leaf.value.map(Number) : [];
  return <MultiSelect options={options} value={value} onChange={(ids) => onChange({ value: ids })} placeholder={t('cond.pick_suppliers', 'Pick suppliers…')} />;
}

function TagsValue({ leaf, onChange }: Omit<Props, 'field'>) {
  const q = useQuery({ queryKey: ['lookup', 'tags'], queryFn: lookupsApi.tags });
  const options = (q.data ?? []).map((tagItem) => ({ id: tagItem.id_tag, label: tagItem.name }));
  const value = Array.isArray(leaf.value) ? leaf.value.map(Number) : [];
  return <MultiSelect options={options} value={value} onChange={(ids) => onChange({ value: ids })} placeholder={t('cond.pick_tags', 'Pick tags…')} />;
}

// Feature — value is array of {id_feature, id_feature_value}
function FeaturesValue({ leaf, onChange }: Omit<Props, 'field'>) {
  const q = useQuery({ queryKey: ['lookup', 'features'], queryFn: lookupsApi.features });
  const pairs = Array.isArray(leaf.value) ? (leaf.value as Array<{ id_feature: number; id_feature_value: number }>) : [];
  const selectedFeatureIds = Array.from(new Set(pairs.map((p) => p.id_feature))).filter((n) => n > 0);
  const pickedFeatureId = selectedFeatureIds[0] ?? 0;
  const group = q.data?.find((f) => f.id_feature === pickedFeatureId);
  const valueIds = pairs.filter((p) => p.id_feature === pickedFeatureId).map((p) => p.id_feature_value);

  return (
    <div className="grid grid-cols-2 gap-2">
      <select
        className="px-3 py-2 border border-border rounded-md bg-white text-[13px]"
        value={pickedFeatureId}
        onChange={(e) => {
          const id = Number(e.target.value);
          onChange({ value: id > 0 ? [] : [] });
          // Reset values when switching feature (keeps id_feature via pairs when values picked)
          if (id > 0) {
            // placeholder row until user picks values — store a sentinel zero-value that
            // compiler skips (id_feature_value=0 is filtered out).
          }
        }}
      >
        <option value={0}>{t('filter.pick_feature', '— Pick feature —')}</option>
        {q.data?.map((f) => (
          <option key={f.id_feature} value={f.id_feature}>{f.name}</option>
        ))}
      </select>

      <MultiSelect
        options={group?.values.map((v) => ({ id: v.id_feature_value, label: v.value })) ?? []}
        value={valueIds}
        onChange={(ids) => {
          if (pickedFeatureId === 0) return;
          const nextPairs = ids.map((vid) => ({ id_feature: pickedFeatureId, id_feature_value: vid }));
          onChange({ value: nextPairs });
        }}
        placeholder={group ? t('filter.any_value', 'Any value') : t('filter.pick_feature_first', 'Pick a feature first')}
        emptyLabel={group ? t('filter.no_values', 'No values') : t('filter.pick_feature_first', 'Pick a feature first')}
      />
    </div>
  );
}

// Attribute — single group + multi values
function AttributesValue({ leaf, onChange }: Omit<Props, 'field'>) {
  const q = useQuery({ queryKey: ['lookup', 'attributes'], queryFn: conditionsApi.attributes });
  const value = Array.isArray(leaf.value) ? leaf.value.map(Number) : [];
  // Flatten all options across groups for simplicity; show "Group: Value".
  const options: { id: number; label: string; sublabel?: string }[] = [];
  for (const g of q.data ?? []) {
    for (const v of g.values) {
      options.push({ id: v.id_attribute, label: v.value, sublabel: g.name });
    }
  }
  return (
    <MultiSelect
      options={options}
      value={value}
      onChange={(ids) => onChange({ value: ids })}
      placeholder={t('cond.pick_attributes', 'Pick attributes…')}
    />
  );
}

/**
 * Helper for callers that need to know if an operator supports multi-valued input.
 */
export function isMultiOp(op: Operator): boolean {
  return ['is_one_of', 'is_not_one_of', 'contains_any', 'contains_all', 'contains_none'].includes(op);
}
