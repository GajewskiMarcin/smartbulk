import { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Field, Input, Checkbox } from './Form';
import MultiSelect from './MultiSelect';
import {
  lookupsApi,
  CONTENT_GAP_OPTIONS,
  CONTENT_GAP_GROUPS,
  type ContentGapOption,
  type FilterItem,
  type FilterSpec,
} from '../lib/segments';
import { t } from '../lib/i18n';

interface FilterBuilderProps {
  value: FilterSpec;
  onChange: (spec: FilterSpec) => void;
}

/**
 * Composable filter UI. Maintains a FilterSpec with one entry per filter type.
 * All filters are AND-combined. Multi-value within a single filter acts as OR
 * (the SegmentService matches that semantic).
 */
export default function FilterBuilder({ value, onChange }: FilterBuilderProps) {
  const categories = useQuery({ queryKey: ['lookup', 'categories'], queryFn: lookupsApi.categories });
  const brands     = useQuery({ queryKey: ['lookup', 'brands'],     queryFn: lookupsApi.brands });
  const suppliers  = useQuery({ queryKey: ['lookup', 'suppliers'],  queryFn: lookupsApi.suppliers });
  const features   = useQuery({ queryKey: ['lookup', 'features'],   queryFn: lookupsApi.features });
  const tags       = useQuery({ queryKey: ['lookup', 'tags'],       queryFn: lookupsApi.tags });

  // Helpers — read/update individual filter types
  const find = <T extends FilterItem>(type: T['type']): T | undefined =>
    value.filters.find((f) => f.type === type) as T | undefined;

  const set = (type: FilterItem['type'], next: FilterItem | null) => {
    const others = value.filters.filter((f) => f.type !== type);
    onChange({ filters: next ? [...others, next] : others });
  };

  const cat = find<Extract<FilterItem, { type: 'category' }>>('category');
  const brand = find<Extract<FilterItem, { type: 'brand' }>>('brand');
  const supplier = find<Extract<FilterItem, { type: 'supplier' }>>('supplier');
  const tag = find<Extract<FilterItem, { type: 'tag' }>>('tag');
  const feature = find<Extract<FilterItem, { type: 'feature' }>>('feature');
  const contentGaps = find<Extract<FilterItem, { type: 'content_gaps' }>>('content_gaps');
  const status = find<Extract<FilterItem, { type: 'status' }>>('status');
  const priceBetween = find<Extract<FilterItem, { type: 'price_between' }>>('price_between');
  const dateAdded = find<Extract<FilterItem, { type: 'date_added_after_days' }>>('date_added_after_days');
  const productIds = find<Extract<FilterItem, { type: 'product_ids' }>>('product_ids');

  // Build "Parent › Child › Grandchild" path so users see where each category lives.
  // Without parent context, multiple categories with same name (e.g. "Testery" under
  // different parents) are indistinguishable. id_parent is supplied by the backend.
  const categoryOptions = useMemo(() => {
    const rows = categories.data ?? [];
    const byId = new Map<number, { name: string; id_parent: number; level_depth: number }>();
    for (const c of rows) byId.set(c.id_category, { name: c.name, id_parent: c.id_parent, level_depth: c.level_depth });
    const pathOf = (id: number): string => {
      const parts: string[] = [];
      let cur: number | undefined = id;
      const seen = new Set<number>();
      while (cur !== undefined && !seen.has(cur)) {
        seen.add(cur);
        const node = byId.get(cur);
        // Stop at root (level_depth <= 1) or unknown ancestor
        if (!node || node.level_depth <= 1) break;
        parts.unshift(node.name);
        cur = node.id_parent;
      }
      return parts.join(' › ');
    };
    return rows.map((c) => ({
      id: c.id_category,
      label: pathOf(c.id_category) || c.name,
      sublabel: `#${c.id_category}`,
    }));
  }, [categories.data]);
  const brandOptions = useMemo(
    () => (brands.data ?? []).map((b) => ({ id: b.id_manufacturer, label: b.name })),
    [brands.data]
  );
  const supplierOptions = useMemo(
    () => (suppliers.data ?? []).map((s) => ({ id: s.id_supplier, label: s.name })),
    [suppliers.data]
  );
  const tagOptions = useMemo(
    () => (tags.data ?? []).map((t) => ({ id: t.id_tag, label: t.name })),
    [tags.data]
  );

  // Feature filter — kept in LOCAL state so the UI remembers an "in-progress" row
  // (feature picked, values not yet chosen). Only rows with values flow upward to
  // the filter spec; that prevents "select resets to 0" when the row is incomplete.
  type FeatureRow = { id_feature: number; value_ids: number[] };
  const [featureRows, setFeatureRows] = useState<FeatureRow[]>([{ id_feature: 0, value_ids: [] }]);

  // External sync: when the filter spec's feature filter changes (e.g. load saved
  // segment / switch scope tab), rebuild local rows from pairs.
  const featureKey = useMemo(
    () => (feature ? feature.pairs.map((p) => `${p.id_feature}:${p.id_feature_value}`).sort().join(',') : ''),
    [feature]
  );
  useEffect(() => {
    if (!feature || feature.pairs.length === 0) {
      // Only reset if the local state has no in-progress (id_feature=0) row either.
      setFeatureRows((prev) => {
        const hasCompleted = prev.some((r) => r.id_feature > 0 && r.value_ids.length > 0);
        return hasCompleted ? [{ id_feature: 0, value_ids: [] }] : prev;
      });
      return;
    }
    const map = new Map<number, number[]>();
    for (const p of feature.pairs) {
      const list = map.get(p.id_feature) ?? [];
      list.push(p.id_feature_value);
      map.set(p.id_feature, list);
    }
    const rebuilt = Array.from(map.entries()).map(([id_feature, value_ids]) => ({ id_feature, value_ids }));
    setFeatureRows(rebuilt.length ? rebuilt : [{ id_feature: 0, value_ids: [] }]);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [featureKey]);

  // Push rows to parent filter spec (only rows that have BOTH feature and values)
  const pushFeatureRows = (rows: FeatureRow[]) => {
    const pairs: { id_feature: number; id_feature_value: number }[] = [];
    for (const r of rows) {
      if (r.id_feature > 0) {
        for (const v of r.value_ids) {
          if (v > 0) pairs.push({ id_feature: r.id_feature, id_feature_value: v });
        }
      }
    }
    set('feature', pairs.length ? { type: 'feature', pairs } : null);
  };

  const updateFeatureRows = (rows: FeatureRow[]) => {
    setFeatureRows(rows);
    pushFeatureRows(rows);
  };

  const productIdsText = productIds?.ids.join(', ') ?? '';

  return (
    <div className="flex flex-col gap-4">
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <Field label={t('filter.categories', 'Categories')} hint={t('filter.categories_hint', 'Products in any selected category')}>
          <MultiSelect
            options={categoryOptions}
            value={cat?.ids ?? []}
            onChange={(ids) => set('category', ids.length ? { type: 'category', ids } : null)}
            placeholder={t('filter.all_categories', 'All categories')}
          />
        </Field>

        <Field label={t('filter.brands', 'Brands')} hint={t('filter.brands_hint', 'Products from any selected manufacturer')}>
          <MultiSelect
            options={brandOptions}
            value={brand?.ids ?? []}
            onChange={(ids) => set('brand', ids.length ? { type: 'brand', ids } : null)}
            placeholder={t('filter.all_brands', 'All brands')}
          />
        </Field>

        <Field label={t('filter.suppliers', 'Suppliers')}>
          <MultiSelect
            options={supplierOptions}
            value={supplier?.ids ?? []}
            onChange={(ids) => set('supplier', ids.length ? { type: 'supplier', ids } : null)}
            placeholder={t('filter.all_suppliers', 'All suppliers')}
          />
        </Field>

        <Field label={t('filter.tags', 'Tags')}>
          <MultiSelect
            options={tagOptions}
            value={tag?.ids ?? []}
            onChange={(ids) => set('tag', ids.length ? { type: 'tag', ids } : null)}
            placeholder={t('filter.any_tags', 'Any tags')}
          />
        </Field>
      </div>

      {/* Feature picker — multi-row: each row = one feature + its selected values */}
      <Field
        label={t('filter.features', 'Features')}
        hint={t('filter.features_hint', 'Multiple rows combine with AND ("Euro ∈ {6} AND Compat = DAF XF"). Values within a row combine with OR.')}
      >
        <div className="flex flex-col gap-2">
          {featureRows.map((row, idx) => {
            const group = features.data?.find((f) => f.id_feature === row.id_feature);
            const availableFeatures = features.data ?? [];
            return (
              <div key={idx} className="grid grid-cols-1 md:grid-cols-[1fr_1fr_auto] gap-2 items-start">
                <select
                  className="w-full px-3 py-2 border border-border rounded-md bg-white text-[13px]"
                  value={row.id_feature}
                  onChange={(e) => {
                    const id = Number(e.target.value);
                    const next = featureRows.map((r, i) =>
                      i === idx ? { id_feature: id, value_ids: [] } : r
                    );
                    updateFeatureRows(next);
                  }}
                >
                  <option value={0}>{t('filter.pick_feature', '— Pick feature —')}</option>
                  {availableFeatures.map((f) => (
                    <option
                      key={f.id_feature}
                      value={f.id_feature}
                      disabled={
                        f.id_feature !== row.id_feature &&
                        featureRows.some((r) => r.id_feature === f.id_feature)
                      }
                    >
                      {f.name}
                    </option>
                  ))}
                </select>

                <MultiSelect
                  options={group?.values.map((v) => ({ id: v.id_feature_value, label: v.value })) ?? []}
                  value={row.value_ids}
                  onChange={(ids) => {
                    const next = featureRows.map((r, i) => (i === idx ? { ...r, value_ids: ids } : r));
                    updateFeatureRows(next);
                  }}
                  placeholder={group ? t('filter.any_value', 'Any value') : t('filter.pick_feature_first', 'Pick a feature first')}
                  emptyLabel={group ? t('filter.no_values', 'No values') : t('filter.pick_feature_first', 'Pick a feature first')}
                />

                <button
                  type="button"
                  onClick={() => {
                    if (featureRows.length === 1) {
                      updateFeatureRows([{ id_feature: 0, value_ids: [] }]);
                    } else {
                      updateFeatureRows(featureRows.filter((_, i) => i !== idx));
                    }
                  }}
                  className="px-2 py-2 text-muted-foreground hover:text-destructive text-[16px] leading-none"
                  title={t('filter.remove_feature', 'Remove this feature')}
                >
                  ×
                </button>
              </div>
            );
          })}

          <div>
            <button
              type="button"
              onClick={() => updateFeatureRows([...featureRows, { id_feature: 0, value_ids: [] }])}
              className="text-[12px] text-primary hover:underline font-medium"
              disabled={
                (features.data?.length ?? 0) <= featureRows.filter((r) => r.id_feature > 0).length
              }
            >
              {t('filter.add_another_feature', '+ Add another feature')}
            </button>
          </div>
        </div>
      </Field>

      {/* Content gaps — grouped under sub-headers */}
      <Field label={t('filter.content_gaps', 'Content gaps')} hint={t('filter.content_gaps_hint', 'Match products missing any of the selected data')}>
        <div className="flex flex-col gap-4">
          {(Object.keys(CONTENT_GAP_GROUPS) as ContentGapOption['group'][]).map((groupKey) => {
            const items = CONTENT_GAP_OPTIONS.filter((o) => o.group === groupKey);
            if (items.length === 0) return null;
            return (
              <div key={groupKey}>
                <div className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground mb-1.5">
                  {CONTENT_GAP_GROUPS[groupKey]}
                </div>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-1.5">
                  {items.map((gap) => {
                    const selected = contentGaps?.value.includes(gap.value) ?? false;
                    return (
                      <Checkbox
                        key={gap.value}
                        label={gap.label}
                        checked={selected}
                        onChange={(e) => {
                          const current = contentGaps?.value ?? [];
                          const next = e.target.checked
                            ? Array.from(new Set([...current, gap.value]))
                            : current.filter((v) => v !== gap.value);
                          set('content_gaps', next.length ? { type: 'content_gaps', value: next } : null);
                        }}
                      />
                    );
                  })}
                </div>
              </div>
            );
          })}
        </div>
      </Field>

      {/* Status */}
      <Field label={t('filter.status', 'Status')}>
        <div className="flex flex-wrap gap-4">
          {(['active', 'inactive', 'in_stock', 'out_of_stock'] as const).map((s) => {
            const selected = status?.value.includes(s) ?? false;
            return (
              <Checkbox
                key={s}
                label={
                  s === 'in_stock' ? t('filter.in_stock', 'In stock')
                  : s === 'out_of_stock' ? t('filter.out_of_stock', 'Out of stock')
                  : s === 'active' ? t('filter.active', 'Active')
                  : t('filter.inactive', 'Inactive')
                }
                checked={selected}
                onChange={(e) => {
                  const current = status?.value ?? [];
                  const next = e.target.checked
                    ? Array.from(new Set([...current, s]))
                    : current.filter((v) => v !== s);
                  set('status', next.length ? { type: 'status', value: next as Array<'active' | 'inactive' | 'in_stock' | 'out_of_stock'> } : null);
                }}
              />
            );
          })}
        </div>
      </Field>

      {/* Price range + date */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <Field label={t('filter.price_min', 'Price ≥')} hint={t('filter.price_min_hint', 'Min price, tax excl.')}>
          <Input
            type="number" step="0.01" min="0"
            value={priceBetween?.min ?? ''}
            onChange={(e) => {
              const min = e.target.value === '' ? null : Number(e.target.value);
              const max = priceBetween?.max ?? null;
              set('price_between', min === null && max === null ? null : { type: 'price_between', min, max });
            }}
            placeholder="—"
          />
        </Field>
        <Field label={t('filter.price_max', 'Price ≤')} hint={t('filter.price_max_hint', 'Max price, tax excl.')}>
          <Input
            type="number" step="0.01" min="0"
            value={priceBetween?.max ?? ''}
            onChange={(e) => {
              const max = e.target.value === '' ? null : Number(e.target.value);
              const min = priceBetween?.min ?? null;
              set('price_between', min === null && max === null ? null : { type: 'price_between', min, max });
            }}
            placeholder="—"
          />
        </Field>
        <Field label={t('filter.added_last_n_days', 'Added in last N days')} hint={t('filter.added_last_n_days_hint', '0 = any time')}>
          <Input
            type="number" min="0"
            value={dateAdded?.value ?? ''}
            onChange={(e) => {
              const n = Number(e.target.value);
              set('date_added_after_days', n > 0 ? { type: 'date_added_after_days', value: n } : null);
            }}
            placeholder="—"
          />
        </Field>
      </div>

      {/* Specific IDs */}
      <Field label={t('filter.product_ids', 'Specific product IDs')} hint={t('filter.product_ids_hint', 'Comma-separated list, e.g. 12, 15, 301')}>
        <Input
          value={productIdsText}
          onChange={(e) => {
            const ids = e.target.value
              .split(/[,\s]+/)
              .map((x) => Number(x.trim()))
              .filter((n) => Number.isInteger(n) && n > 0);
            set('product_ids', ids.length ? { type: 'product_ids', ids } : null);
          }}
          placeholder={t('filter.product_ids_placeholder', 'e.g. 12, 15, 301')}
        />
      </Field>
    </div>
  );
}
