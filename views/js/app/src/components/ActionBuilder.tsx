import { useId, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import Button from './Button';
import { Input, Select } from './Form';
import {
  FIELD_GROUPS,
  operatorLabel,
  type BulkAction,
  type FieldDefinition,
  type LookupKind,
  type MathOp,
  type Operator,
} from '../lib/bulk';
import { lookupsApi } from '../lib/segments';
import { cn } from '../lib/utils';
import { t, t2 } from '../lib/i18n';
import TemplatePicker from './bulk/TemplatePicker';
import PromptPicker from './bulk/PromptPicker';

interface Props {
  fields: FieldDefinition[];
  value: BulkAction[];
  onChange: (actions: BulkAction[]) => void;
  globalLang?: number | 'all';
}

export default function ActionBuilder({ fields, value, onChange, globalLang = 'all' }: Props) {
  const [pickerOpen, setPickerOpen] = useState(false);
  const [query, setQuery] = useState('');

  const usedFieldIds = useMemo(() => new Set(value.map((a) => a.field)), [value]);

  const availableByGroup = useMemo(() => {
    const q = query.toLowerCase().trim();
    const out: Record<string, FieldDefinition[]> = {};
    for (const f of fields) {
      if (usedFieldIds.has(f.id)) continue;
      if (q && !f.label.toLowerCase().includes(q) && !f.id.toLowerCase().includes(q)) continue;
      (out[f.group] ||= []).push(f);
    }
    return out;
  }, [fields, usedFieldIds, query]);

  const addAction = (field: FieldDefinition) => {
    const defaultOp: Operator = field.operators[0];
    const newAction: BulkAction = {
      field: field.id,
      operator: defaultOp,
      id_lang: field.lang ? null : undefined,
    };
    if (field.type === 'bool' && defaultOp === 'set') newAction.value = true;
    if (field.type === 'enum' && defaultOp === 'set' && field.options?.length) {
      newAction.value = field.options[0].value;
    }
    if (field.type === 'feature') {
      newAction.id_feature = undefined;
      newAction.id_feature_value = null;
      newAction.custom_value = '';
    }
    if (field.type === 'tags') {
      newAction.tags = [];
    }
    onChange([...value, newAction]);
    setPickerOpen(false);
    setQuery('');
  };

  const addFromTemplate = (tplActions: BulkAction[]) => {
    // Keep already-configured actions; append template entries but skip duplicates by field.
    const existingFields = new Set(value.map((a) => a.field));
    const additions = tplActions.filter((a) => !existingFields.has(a.field));
    onChange([...value, ...additions]);
  };

  const removeAction = (idx: number) => {
    onChange(value.filter((_, i) => i !== idx));
  };

  const updateAction = (idx: number, patch: Partial<BulkAction>) => {
    onChange(value.map((a, i) => (i === idx ? { ...a, ...patch } : a)));
  };

  return (
    <div className="flex flex-col gap-3">
      {value.length === 0 && (
        <div className="border border-dashed border-border rounded-md p-6 text-center text-muted-foreground text-[13px]">
          No actions yet. Click <strong>+ Add field</strong> below to start building your edit.
        </div>
      )}

      {value.map((action, idx) => {
        const def = fields.find((f) => f.id === action.field);
        if (!def) return null;
        return (
          <div key={`${action.field}-${idx}`} data-action-idx={idx}>
            <ActionRow
              def={def}
              action={action}
              globalLang={globalLang}
              onChange={(patch) => updateAction(idx, patch)}
              onRemove={() => removeAction(idx)}
            />
          </div>
        );
      })}

      <div className="flex items-center gap-2 relative">
        <Button onClick={() => setPickerOpen((o) => !o)}>
          {pickerOpen ? 'Close picker' : '+ Add field'}
        </Button>
        <TemplatePicker onPick={addFromTemplate} currentActions={value} />
        <div className="flex-1" />
        {pickerOpen && (
          <div className="absolute left-0 top-[calc(100%+4px)] z-20 bg-white border border-border rounded-md shadow-lg w-[380px] max-h-[400px] overflow-hidden flex flex-col">
            <input
              className="px-3 py-2 text-[13px] border-b border-border focus:outline-none"
              placeholder="Search fields…"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              autoFocus
            />
            <div className="overflow-y-auto flex-1">
              {(Object.keys(FIELD_GROUPS) as (keyof typeof FIELD_GROUPS)[]).map((g) => {
                const items = availableByGroup[g] ?? [];
                if (items.length === 0) return null;
                return (
                  <div key={g}>
                    <div className="px-3 py-1.5 text-[10px] uppercase tracking-wide font-semibold text-muted-foreground bg-muted">
                      {t(`bulk.group.${g}`, FIELD_GROUPS[g])}
                    </div>
                    {items.map((f) => (
                      <button
                        key={f.id}
                        type="button"
                        onClick={() => addAction(f)}
                        className="w-full text-left px-3 py-2 hover:bg-muted text-[13px] flex items-center justify-between gap-2"
                      >
                        <div className="min-w-0">
                          <div className="font-medium truncate">{f.label}</div>
                          <div className="text-[10px] text-muted-foreground">
                            {f.type}{f.lang ? ' · multilang' : ''}
                          </div>
                        </div>
                        {f.unit && <span className="text-[10px] text-muted-foreground flex-shrink-0">{f.unit}</span>}
                      </button>
                    ))}
                  </div>
                );
              })}
              {Object.keys(availableByGroup).length === 0 && (
                <div className="px-3 py-6 text-[12px] text-muted-foreground text-center">
                  {fields.length === value.length ? 'All fields already added.' : 'No fields match.'}
                </div>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

// ---------- Row for a single action ----------

function ActionRow({
  def,
  action,
  globalLang,
  onChange,
  onRemove,
}: {
  def: FieldDefinition;
  action: BulkAction;
  globalLang: number | 'all';
  onChange: (patch: Partial<BulkAction>) => void;
  onRemove: () => void;
}) {
  return (
    <div className="border border-border rounded-lg bg-white p-3">
      <div className="flex items-center gap-2 mb-2">
        <div className="font-semibold text-[13px] min-w-0 flex-1">
          {def.label}
          <span className="ml-2 text-[10px] font-normal text-muted-foreground uppercase">
            {def.type}{def.lang ? ' · multilang' : ''}{def.unit ? ` · ${def.unit}` : ''}
          </span>
        </div>
        <button
          type="button"
          onClick={onRemove}
          className="text-muted-foreground hover:text-destructive text-[16px] leading-none"
          title="Remove action"
        >
          ×
        </button>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-[170px_1fr] gap-2 items-start">
        {/* Operator */}
        <Select
          value={action.operator}
          onChange={(e) => {
            const op = e.target.value as Operator;
            const patch: Partial<BulkAction> = { operator: op };
            // reset op-specific fields when switching
            if (op !== 'replace') { patch.find = undefined; patch.replace = undefined; }
            if (op !== 'math') { patch.math_op = undefined; patch.math_value = undefined; }
            if (op !== 'ai_generate') { patch.prompt_id = undefined; patch.prompt_version_id = undefined; }
            if (op !== 'copy_from')   { patch.source_field = undefined; }
            onChange(patch);
          }}
        >
          {def.operators.map((op) => (
            <option key={op} value={op}>{t(`bulk.op.${op}`, operatorLabel(op))}</option>
          ))}
        </Select>

        {/* Operator-specific inputs */}
        {renderValueInput(def, action, onChange)}
      </div>

      {def.lang && action.operator !== 'skip' && action.operator !== 'clear' && (
        <ActionLanguage
          value={action.id_lang}
          globalLang={globalLang}
          onChange={(v) => onChange({ id_lang: v })}
        />
      )}

      {def.html && action.operator !== 'skip' && (
        <div className="mt-2 text-[11px] text-amber-700">
          ⚠ HTML field — use &lt;p&gt;, &lt;strong&gt;, &lt;ul&gt;, &lt;li&gt; tags where needed.
        </div>
      )}

      <FieldHints def={def} action={action} />
    </div>
  );
}

function FieldHints({ def, action }: { def: FieldDefinition; action: BulkAction }) {
  const op = action.operator;
  const hints: { tone: 'warn' | 'info' | 'error'; text: string }[] = [];

  if (def.format_hint && op !== 'skip' && op !== 'clear') {
    hints.push({ tone: 'info', text: def.format_hint });
  }

  if (def.pattern && op === 'set' && typeof action.value === 'string' && action.value !== '') {
    try {
      const re = new RegExp(def.pattern);
      if (!re.test(action.value)) {
        hints.push({ tone: 'error', text: `Value does not match the required format.` });
      }
    } catch {
      /* ignore bad pattern */
    }
  }

  if (def.char_limit && (op === 'set' || op === 'append' || op === 'prepend') && typeof action.value === 'string') {
    const len = action.value.length;
    const limit = def.char_limit;
    const warnAt = def.char_limit_warn ?? Math.floor(limit * 0.9);
    const tone = len > limit ? 'error' : len >= warnAt ? 'warn' : 'info';
    const text = len > limit
      ? `${len} / ${limit} chars — value will be truncated to ${limit}.`
      : `${len} / ${limit} chars${tone === 'warn' ? ' — approaching the limit.' : ''}`;
    hints.push({ tone, text });
  }

  if (def.warn_on_false && op === 'set' && action.value === false) {
    hints.push({ tone: 'warn', text: `⚠ ${def.warn_on_false}` });
  }

  if (def.warn_on_value && op === 'set' && typeof action.value === 'string') {
    const msg = def.warn_on_value[action.value];
    if (msg) hints.push({ tone: 'warn', text: `⚠ ${msg}` });
  }

  if (def.warn_same_value_on_batch && op === 'set' && typeof action.value === 'string' && action.value !== '') {
    hints.push({
      tone: 'warn',
      text: `⚠ "Set to" applies the same value to every product — ${def.id === 'reference' ? 'duplicate SKUs will be created' : 'duplicate codes will be created'}. Use "Replace" or skip on a batch unless intentional.`,
    });
  }

  if (hints.length === 0) return null;
  return (
    <div className="mt-2 flex flex-col gap-1">
      {hints.map((h, i) => (
        <div
          key={i}
          className={cn(
            'text-[11px] leading-relaxed',
            h.tone === 'error' && 'text-red-700',
            h.tone === 'warn' && 'text-amber-700',
            h.tone === 'info' && 'text-muted-foreground'
          )}
        >
          {h.text}
        </div>
      ))}
    </div>
  );
}

// ---------- Lookup dropdown (brands/suppliers/categories/tax_rules) ----------

function LookupSelect({
  kind,
  value,
  onChange,
}: {
  kind: LookupKind;
  value: string;
  onChange: (v: string) => void;
}) {
  const query = useQuery({
    queryKey: ['lookup', kind],
    queryFn: async () => {
      switch (kind) {
        case 'brands':     return (await lookupsApi.brands()).map((b) => ({ id: b.id_manufacturer, label: b.name }));
        case 'suppliers':  return (await lookupsApi.suppliers()).map((s) => ({ id: s.id_supplier, label: s.name }));
        case 'categories': return (await lookupsApi.categories()).map((c) => ({ id: c.id_category, label: `${'— '.repeat(Math.max(0, c.level_depth - 1))}${c.name}` }));
        case 'tax_rules':  return (await lookupsApi.taxRules()).map((t) => ({ id: t.id_tax_rules_group, label: t.name }));
      }
    },
    staleTime: 5 * 60 * 1000,
  });

  if (query.isLoading) {
    return <div className="text-[12px] text-muted-foreground py-2 px-3">Loading…</div>;
  }
  if (query.isError) {
    return <div className="text-[12px] text-red-700 py-2 px-3">Failed to load list.</div>;
  }
  const items = query.data ?? [];
  return (
    <Select value={value} onChange={(e) => onChange(e.target.value)}>
      <option value="">— select —</option>
      {kind === 'tax_rules' && <option value="0">No tax</option>}
      {items.map((it) => (
        <option key={it.id} value={String(it.id)}>{it.label}</option>
      ))}
    </Select>
  );
}

function renderValueInput(
  def: FieldDefinition,
  action: BulkAction,
  onChange: (patch: Partial<BulkAction>) => void
) {
  const op = action.operator;

  // Feature (virtual field) — needs a special two-step picker even for 'clear'
  // (you have to know WHICH feature to clear).
  if (def.type === 'feature') {
    return <FeatureValuePicker action={action} op={op} onChange={onChange} />;
  }

  // Tags (virtual field) — free-text tag chips with suggestions from existing tags.
  if (def.type === 'tags') {
    return <TagsPicker action={action} op={op} onChange={onChange} />;
  }

  if (op === 'skip' || op === 'clear') {
    return (
      <div className="text-[12px] text-muted-foreground py-2 px-3">
        {op === 'skip' ? 'No change.' : 'Field will be cleared.'}
      </div>
    );
  }

  if (op === 'generate_from_name') {
    const msg = def.id === 'meta_title'
      ? `Each product's meta title will be set to its name, truncated to ${def.char_limit ?? 60} characters. No value needed.`
      : def.id === 'link_rewrite'
        ? 'The URL will be auto-generated per product from its name in the selected language (same behavior as the "Generate from name" button in PrestaShop\'s product form). No value needed.'
        : 'The value will be auto-generated per product from its name. No value needed.';
    return (
      <div className="text-[12px] bg-amber-50 border border-amber-200 text-amber-900 rounded-md px-3 py-2 leading-relaxed">
        {msg}
      </div>
    );
  }

  if (op === 'generate_from_short_desc') {
    return (
      <div className="text-[12px] bg-amber-50 border border-amber-200 text-amber-900 rounded-md px-3 py-2 leading-relaxed">
        Each product's meta description will be generated from its short description (HTML stripped),
        truncated to {def.char_limit ?? 160} characters. Falls back to product name if short description is empty.
        No value needed.
      </div>
    );
  }

  if (op === 'copy_from') {
    return <CopyFromPicker def={def} value={action.source_field} onChange={(v) => onChange({ source_field: v })} />;
  }

  if (op === 'ai_generate') {
    return (
      <div className="flex flex-col gap-1.5">
        <PromptPicker
          fieldId={def.id}
          promptId={typeof action.value === 'number' ? action.value : action.prompt_id}
          promptVersionId={action.prompt_version_id}
          onChange={(pid, vid) => onChange({ prompt_id: pid, prompt_version_id: vid })}
        />
        <div className="text-[11px] text-muted-foreground leading-relaxed">
          Each matching product will be processed by the picked prompt at apply time.
          Preview shows a placeholder — real text is generated during Step 4.
        </div>
      </div>
    );
  }

  if (op === 'replace') {
    return (
      <div className="grid grid-cols-2 gap-2">
        <Input
          placeholder="Find"
          value={(action.find ?? '') as string}
          onChange={(e) => onChange({ find: e.target.value })}
        />
        <Input
          placeholder="Replace with"
          value={(action.replace ?? '') as string}
          onChange={(e) => onChange({ replace: e.target.value })}
        />
      </div>
    );
  }

  if (op === 'math') {
    return (
      <div className="grid grid-cols-[140px_1fr] gap-2">
        <Select
          value={(action.math_op ?? '+') as MathOp}
          onChange={(e) => onChange({ math_op: e.target.value as MathOp })}
        >
          <option value="+">+ add</option>
          <option value="-">− subtract</option>
          <option value="*">× multiply</option>
          <option value="/">÷ divide</option>
          <option value="+%">+ %</option>
          <option value="-%">− %</option>
        </Select>
        <Input
          type="number"
          step="any"
          placeholder="Value"
          value={action.math_value ?? ''}
          onChange={(e) => onChange({ math_value: e.target.value === '' ? undefined : Number(e.target.value) })}
        />
      </div>
    );
  }

  // Operator = set / append / prepend
  if (def.type === 'bool') {
    return (
      <Select
        value={action.value === true || action.value === 'true' ? 'true' : 'false'}
        onChange={(e) => onChange({ value: e.target.value === 'true' })}
      >
        <option value="true">Yes</option>
        <option value="false">No</option>
      </Select>
    );
  }
  if (def.type === 'enum') {
    if (def.lookup) {
      return (
        <LookupSelect
          kind={def.lookup}
          value={action.value === undefined ? '' : String(action.value)}
          onChange={(v) => onChange({ value: v })}
        />
      );
    }
    return (
      <Select
        value={String(action.value ?? def.options?.[0]?.value ?? '')}
        onChange={(e) => onChange({ value: e.target.value })}
      >
        {def.options?.map((opt) => (
          <option key={opt.value} value={opt.value}>{opt.label}</option>
        ))}
      </Select>
    );
  }
  if (def.type === 'numeric' || def.type === 'int') {
    return (
      <Input
        type="number"
        step={def.type === 'int' ? '1' : 'any'}
        min={def.min_value}
        placeholder={def.unit === 'currency' ? '0.00' : '0'}
        value={action.value === undefined ? '' : String(action.value)}
        onChange={(e) => onChange({ value: e.target.value === '' ? undefined : Number(e.target.value) })}
      />
    );
  }
  // text — plain or append/prepend
  const multiline = def.html || def.id === 'description' || def.id === 'description_short';
  return multiline ? (
    <textarea
      className={cn(
        'w-full px-3 py-2 border border-border rounded-md bg-white text-[13px]',
        'focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary',
        'min-h-[72px] resize-y'
      )}
      placeholder={op === 'set' ? 'New value' : op === 'append' ? 'Text to append' : 'Text to prepend'}
      value={(action.value as string) ?? ''}
      onChange={(e) => onChange({ value: e.target.value })}
    />
  ) : (
    <Input
      placeholder={op === 'set' ? 'New value' : op === 'append' ? 'Text to append' : 'Text to prepend'}
      value={(action.value as string) ?? ''}
      onChange={(e) => onChange({ value: e.target.value })}
    />
  );
}

// ---------- Language control per action (defers to global when unset) ----------

function ActionLanguage({
  value,
  globalLang,
  onChange,
}: {
  value: number | null | undefined;
  globalLang: number | 'all';
  onChange: (v: number | null | undefined) => void;
}) {
  const query = useQuery({
    queryKey: ['lookup', 'languages'],
    queryFn: () => lookupsApi.languages(),
    staleTime: 10 * 60 * 1000,
  });
  const langs = query.data ?? [];
  const isOverride = value !== null && value !== undefined;

  // Single-language shop or still loading → no per-action picker needed.
  if (query.isLoading || langs.length <= 1) return null;

  const globalLabel =
    globalLang === 'all'
      ? `all languages (${langs.length})`
      : (() => {
          const l = langs.find((x) => x.id_lang === globalLang);
          return l ? `${l.name} (${l.iso_code.toUpperCase()})` : `lang ${globalLang}`;
        })();

  return (
    <div className="mt-2 flex items-center gap-2 text-[11px] flex-wrap">
      {!isOverride ? (
        <>
          <span className="text-muted-foreground">
            Uses <strong className="text-slate-700">{globalLabel}</strong> from global tabs.
          </span>
          <button
            type="button"
            className="text-primary hover:underline"
            onClick={() => onChange(langs[0].id_lang)}
          >
            Override for this action
          </button>
        </>
      ) : (
        <>
          <span className="text-muted-foreground">Override:</span>
          <Select
            value={String(value)}
            onChange={(e) => onChange(Number(e.target.value))}
            className="max-w-[220px]"
          >
            {langs.map((l) => (
              <option key={l.id_lang} value={l.id_lang}>
                {l.name} ({l.iso_code.toUpperCase()})
              </option>
            ))}
          </Select>
          <button
            type="button"
            className="text-primary hover:underline"
            onClick={() => onChange(null)}
          >
            Use global
          </button>
        </>
      )}
    </div>
  );
}

// ---------- copy_from picker (source field compatible with target type) ----------

/**
 * Source-field candidates grouped per target field type. We enumerate only
 * the fields actually loaded by BulkEditorService::loadProductFields — picking
 * anything else would fail at apply time with `copy_from_invalid`.
 */
const COPY_FROM_SOURCES: Record<'text' | 'numeric' | 'int', { value: string; label: string }[]> = {
  text: [
    { value: 'name',              label: 'Product name' },
    { value: 'reference',         label: 'Reference (SKU)' },
    { value: 'meta_title',        label: 'Meta title' },
    { value: 'meta_description',  label: 'Meta description' },
    { value: 'description_short', label: 'Short description' },
    { value: 'description',       label: 'Long description' },
    { value: 'link_rewrite',      label: 'Friendly URL' },
    { value: 'ean13',             label: 'EAN-13' },
    { value: 'upc',               label: 'UPC' },
    { value: 'mpn',               label: 'MPN' },
    { value: 'isbn',              label: 'ISBN' },
    { value: 'available_now',     label: 'Label when in-stock' },
    { value: 'available_later',   label: 'Label when out of stock' },
  ],
  numeric: [
    { value: 'price',                    label: 'Price' },
    { value: 'wholesale_price',          label: 'Cost price' },
    { value: 'ecotax',                   label: 'Ecotax' },
    { value: 'weight',                   label: 'Weight' },
    { value: 'width',                    label: 'Width' },
    { value: 'height',                   label: 'Height' },
    { value: 'depth',                    label: 'Depth' },
    { value: 'additional_shipping_cost', label: 'Additional shipping' },
    { value: 'unit_price_ratio',         label: 'Unit price ratio' },
  ],
  int: [
    { value: 'quantity',            label: 'Stock quantity' },
    { value: 'minimal_quantity',    label: 'Min. qty for sale' },
    { value: 'low_stock_threshold', label: 'Low stock threshold' },
  ],
};

// ---------- Feature value picker (virtual _feature field) ----------

/**
 * Two-step picker: choose the feature, then choose either an existing value
 * from PrestaShop's feature library or type a custom value. For operator 'clear'
 * only the feature is needed (all values of that feature on the product will be
 * removed). PS allows multiple values for the same feature on one product, so
 * 'add' never overwrites — it appends.
 */
function FeatureValuePicker({
  action,
  op,
  onChange,
}: {
  action: BulkAction;
  op: Operator;
  onChange: (patch: Partial<BulkAction>) => void;
}) {
  const query = useQuery({
    queryKey: ['lookup', 'features'],
    queryFn: () => lookupsApi.features(),
    staleTime: 5 * 60 * 1000,
  });

  if (query.isLoading) {
    return <div className="text-[12px] text-muted-foreground py-2 px-3">{t('bulk.feature.loading', 'Loading features…')}</div>;
  }
  if (query.isError) {
    return <div className="text-[12px] text-red-700 py-2 px-3">{t('bulk.feature.load_failed', 'Failed to load features.')}</div>;
  }

  const features = query.data ?? [];
  const currentFeature = features.find((f) => f.id_feature === action.id_feature);
  const useCustom = (action.custom_value ?? '') !== '' || action.id_feature_value === 0;
  const selectedValueLabel = useCustom
    ? (action.custom_value || '…')
    : ((currentFeature?.values ?? []).find((v) => v.id_feature_value === action.id_feature_value)?.value ?? '…');

  return (
    <div className="flex flex-col gap-2">
      <Select
        value={action.id_feature ? String(action.id_feature) : ''}
        onChange={(e) => {
          const id = e.target.value === '' ? undefined : Number(e.target.value);
          onChange({ id_feature: id, id_feature_value: null, custom_value: '' });
        }}
      >
        <option value="">{t('bulk.feature.pick_feature', '— pick a feature —')}</option>
        {features.map((f) => (
          <option key={f.id_feature} value={f.id_feature}>{f.name}</option>
        ))}
      </Select>

      {op === 'clear' && action.id_feature && (
        <div className="text-[11px] text-amber-700 leading-relaxed">
          ⚠ {t2('bulk.feature.clear_warn', 'All values of {feature} assigned to each matching product will be removed.', { feature: currentFeature?.name ?? '' })}
        </div>
      )}

      {op === 'add' && action.id_feature && (
        <>
          <div className="flex items-center gap-3 text-[12px]">
            <label className="flex items-center gap-1.5">
              <input
                type="radio"
                checked={!useCustom}
                onChange={() => onChange({ custom_value: '', id_feature_value: null })}
              />
              {t('bulk.feature.from_library', 'From library')}
            </label>
            <label className="flex items-center gap-1.5">
              <input
                type="radio"
                checked={useCustom}
                onChange={() => onChange({ id_feature_value: 0, custom_value: action.custom_value ?? '' })}
              />
              {t('bulk.feature.custom_value', 'Custom value')}
            </label>
          </div>

          {!useCustom ? (
            <Select
              value={action.id_feature_value ? String(action.id_feature_value) : ''}
              onChange={(e) => onChange({ id_feature_value: Number(e.target.value), custom_value: '' })}
            >
              <option value="">{t('bulk.feature.pick_value', '— pick a value —')}</option>
              {(currentFeature?.values ?? []).map((v) => (
                <option key={v.id_feature_value} value={v.id_feature_value}>{v.value}</option>
              ))}
            </Select>
          ) : (
            <Input
              placeholder={t('bulk.feature.custom_placeholder', 'Type a custom value')}
              value={action.custom_value ?? ''}
              onChange={(e) => onChange({ custom_value: e.target.value, id_feature_value: 0 })}
            />
          )}
          <div className="text-[11px] text-muted-foreground leading-relaxed">
            {t2('bulk.feature.hint', 'Each matching product will get {feature} = {value}.', {
              feature: currentFeature?.name ?? '…',
              value: selectedValueLabel,
            })}
            {useCustom && ' ' + t('bulk.feature.hint_custom', 'A custom feature value will be created.')}
            {' ' + t('bulk.feature.hint_multi', 'PrestaShop allows multiple values for the same feature, so existing values are kept.')}
          </div>
        </>
      )}
    </div>
  );
}

// ---------- Tags picker (virtual _tags field) ----------

/**
 * Free-text tag entry with autocomplete suggestions from the shop's existing tags.
 * 'add' creates tags that don't exist yet; 'remove' unlinks matching tags; 'clear'
 * needs no value (all tags removed). Tags are per-language — the action's language
 * comes from the global language tabs / per-action override.
 */
function TagsPicker({
  action,
  op,
  onChange,
}: {
  action: BulkAction;
  op: Operator;
  onChange: (patch: Partial<BulkAction>) => void;
}) {
  const [draft, setDraft] = useState('');
  const listId = useId();
  const query = useQuery({
    queryKey: ['lookup', 'tags'],
    queryFn: () => lookupsApi.tags(),
    staleTime: 5 * 60 * 1000,
  });
  const tags = action.tags ?? [];
  const suggestions = (query.data ?? []).map((tg) => tg.name);

  const addTags = (raw: string) => {
    const parts = raw.split(',').map((s) => s.trim()).filter(Boolean);
    if (parts.length === 0) return;
    const seen = new Set(tags.map((tg) => tg.toLowerCase()));
    const next = [...tags];
    for (const p of parts) {
      if (!seen.has(p.toLowerCase())) { next.push(p); seen.add(p.toLowerCase()); }
    }
    onChange({ tags: next });
    setDraft('');
  };
  const removeChip = (idx: number) => onChange({ tags: tags.filter((_, i) => i !== idx) });

  if (op === 'clear') {
    return (
      <div className="text-[12px] text-amber-700 py-2 px-3 leading-relaxed">
        {t('bulk.tags.clear_warn', 'All tags will be removed from each matching product (in the selected language).')}
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-2">
      {tags.length > 0 && (
        <div className="flex flex-wrap gap-1.5">
          {tags.map((tg, i) => (
            <span key={`${tg}-${i}`} className="inline-flex items-center gap-1 bg-muted rounded-md px-2 py-0.5 text-[12px]">
              {tg}
              <button
                type="button"
                onClick={() => removeChip(i)}
                className="text-muted-foreground hover:text-destructive leading-none"
                title={t('bulk.tags.remove_chip', 'Remove')}
              >
                ×
              </button>
            </span>
          ))}
        </div>
      )}
      <input
        list={listId}
        className={cn(
          'w-full px-3 py-2 border border-border rounded-md bg-white text-[13px]',
          'focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary'
        )}
        placeholder={
          op === 'add'
            ? t('bulk.tags.add_ph', 'Type a tag and press Enter…')
            : t('bulk.tags.remove_ph', 'Type a tag to remove and press Enter…')
        }
        value={draft}
        onChange={(e) => setDraft(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            addTags(draft);
          } else if (e.key === 'Backspace' && draft === '' && tags.length > 0) {
            removeChip(tags.length - 1);
          }
        }}
        onBlur={() => { if (draft.trim()) addTags(draft); }}
      />
      <datalist id={listId}>
        {suggestions.map((s) => (
          <option key={s} value={s} />
        ))}
      </datalist>
      <div className="text-[11px] text-muted-foreground leading-relaxed">
        {op === 'add'
          ? t('bulk.tags.add_hint', 'Each matching product gets these tags. Tags that do not exist yet are created; ones the product already has are skipped.')
          : t('bulk.tags.remove_hint', 'These tags are unlinked from each matching product. The tags themselves stay in your shop.')}
      </div>
    </div>
  );
}

function CopyFromPicker({
  def,
  value,
  onChange,
}: {
  def: FieldDefinition;
  value: string | undefined;
  onChange: (v: string) => void;
}) {
  const type = (def.type === 'text' || def.type === 'numeric' || def.type === 'int') ? def.type : 'text';
  const sources = COPY_FROM_SOURCES[type].filter((s) => s.value !== def.id);

  return (
    <div className="flex flex-col gap-1.5">
      <Select value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
        <option value="">— pick source field —</option>
        {sources.map((s) => (
          <option key={s.value} value={s.value}>{s.label}</option>
        ))}
      </Select>
      <div className="text-[11px] text-muted-foreground leading-relaxed">
        Each product's <strong>{def.label}</strong> will be set to its{' '}
        <strong>{value ? (sources.find((s) => s.value === value)?.label ?? value) : '…'}</strong>.
        Empty source values will leave the target unchanged (flagged in preview).
      </div>
    </div>
  );
}
