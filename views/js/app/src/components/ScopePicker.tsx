import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import Button from './Button';
import Badge from './Badge';
import { Field, Input } from './Form';
import FilterBuilder from './FilterBuilder';
import ProductPicker from './ProductPicker';
import {
  segmentsApi,
  type FilterItem,
  type FilterSpec,
} from '../lib/segments';
import type { ProductRef } from '../lib/ai';
import { cn } from '../lib/utils';
import { useConfirm } from './ConfirmDialog';
import { t, t2 } from '../lib/i18n';

export type ScopeMode = 'single' | 'segment' | 'custom';

/**
 * A segment is either a built-in preset (kind = 'builtin') or a user-saved one
 * (kind = 'saved'). Both expose a filter spec; the UI treats them uniformly.
 */
export type SegmentChoice =
  | { kind: 'builtin'; id: string; name: string; description: string; filters: FilterItem[]; count: number }
  | { kind: 'saved';   id: number; name: string; description: string; filters: FilterItem[] };

export interface ScopeValue {
  mode: ScopeMode;
  single?: ProductRef | null;
  segment?: SegmentChoice | null;
  customSpec?: FilterSpec;
}

interface ScopePickerProps {
  value: ScopeValue;
  onChange: (v: ScopeValue) => void;
}

const TABS: { id: ScopeMode; labelKey: string; labelDefault: string }[] = [
  { id: 'single',  labelKey: 'scope.single',  labelDefault: '🧪 Single (test)' },
  { id: 'segment', labelKey: 'scope.segment', labelDefault: '📋 Segment' },
  { id: 'custom',  labelKey: 'scope.custom',  labelDefault: '🔧 Custom filter' },
];

/**
 * Scope picker with three modes: single product, pick a segment (built-in preset
 * OR user-saved), or build a custom filter from scratch.
 */
export default function ScopePicker({ value, onChange }: ScopePickerProps) {
  const qc = useQueryClient();
  const confirm = useConfirm();

  const presetsQuery = useQuery({
    queryKey: ['segments', 'presets'],
    queryFn: segmentsApi.presets,
    enabled: value.mode === 'segment',
  });

  const savedQuery = useQuery({
    queryKey: ['segments', 'saved'],
    queryFn: segmentsApi.list,
    enabled: value.mode === 'segment',
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => segmentsApi.delete(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['segments', 'saved'] }),
  });

  const setMode = (mode: ScopeMode) => {
    if (mode === value.mode) return;
    onChange({
      mode,
      single: null,
      segment: null,
      customSpec: { filters: [] },
    });
  };

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap gap-1 p-1 bg-muted rounded-md w-fit">
        {TABS.map((tab) => (
          <button
            key={tab.id}
            type="button"
            onClick={() => setMode(tab.id)}
            className={cn(
              'px-3 py-1.5 rounded text-[12px] font-medium transition-colors',
              value.mode === tab.id
                ? 'bg-white text-primary shadow-sm'
                : 'text-slate-600 hover:text-slate-900'
            )}
          >
            {t(tab.labelKey, tab.labelDefault)}
          </button>
        ))}
      </div>

      {value.mode === 'single' && (
        <Field label={t('scope.test_product', 'Test product')} hint={t('scope.test_hint', 'Quick 1-off generation to check prompt quality (costs ~$0.003)')}>
          <ProductPicker
            value={value.single ?? null}
            onChange={(p) => onChange({ ...value, single: p })}
          />
        </Field>
      )}

      {value.mode === 'segment' && (
        <SegmentList
          presetsLoading={presetsQuery.isLoading}
          presets={presetsQuery.data ?? []}
          savedLoading={savedQuery.isLoading}
          saved={savedQuery.data ?? []}
          selected={value.segment ?? null}
          onSelect={(seg) => onChange({ ...value, segment: seg })}
          onDelete={async (id) => {
            const ok = await confirm({
              title: t('confirm.delete_segment_title', 'Delete saved segment?'),
              message: t('confirm.delete_segment_msg', 'The segment filter definition will be removed. Products themselves are not affected.'),
              confirmLabel: t('common.delete', 'Delete'),
              tone: 'destructive',
            });
            if (!ok) return;
            deleteMutation.mutate(id);
            if (value.segment?.kind === 'saved' && value.segment.id === id) {
              onChange({ ...value, segment: null });
            }
          }}
          deleting={deleteMutation.isPending ? deleteMutation.variables ?? null : null}
        />
      )}

      {value.mode === 'custom' && (
        <div className="flex flex-col gap-3">
          <FilterBuilder
            value={value.customSpec ?? { filters: [] }}
            onChange={(spec) => onChange({ ...value, customSpec: spec })}
          />
          <SaveSegmentRow spec={value.customSpec ?? { filters: [] }} />
        </div>
      )}
    </div>
  );
}

// =========================================================================
// Combined segment list — built-in presets + user saved segments
// =========================================================================

function SegmentList({
  presetsLoading,
  presets,
  savedLoading,
  saved,
  selected,
  onSelect,
  onDelete,
  deleting,
}: {
  presetsLoading: boolean;
  presets: { id: string; name: string; description: string; filters: FilterItem[]; count: number }[];
  savedLoading: boolean;
  saved: { id_segment: number; name: string; description: string; conditions: unknown }[];
  selected: SegmentChoice | null;
  onSelect: (seg: SegmentChoice) => void;
  onDelete: (id: number) => void;
  deleting: number | null;
}) {
  const builtinItems: SegmentChoice[] = useMemo(
    () => presets.map((p) => ({
      kind: 'builtin' as const,
      id: p.id,
      name: p.name,
      description: p.description,
      filters: p.filters,
      count: p.count,
    })),
    [presets]
  );

  const savedItems: SegmentChoice[] = useMemo(
    // Only flat-format segments (FilterSpec with `.filters`) are usable by AI Assistant.
    // Tree-format segments (Bulk Editor nested AND/OR) are hidden here.
    () => saved
      .filter((s) => s.conditions && typeof s.conditions === 'object' && Array.isArray((s.conditions as { filters?: unknown }).filters))
      .map((s) => ({
        kind: 'saved' as const,
        id: s.id_segment,
        name: s.name,
        description: s.description,
        filters: ((s.conditions as { filters?: FilterItem[] }).filters ?? []),
      })),
    [saved]
  );

  const loading = presetsLoading || savedLoading;

  return (
    <div className="flex flex-col gap-4">
      {/* Built-in presets */}
      <div>
        <div className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground mb-2">
          {t('scope.builtin_segments', 'Built-in (ready to use)')}
        </div>
        {loading && builtinItems.length === 0 && (
          <div className="text-[12px] text-muted-foreground">{t('common.loading', 'Loading…')}</div>
        )}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
          {builtinItems.map((item) => (
            <SegmentTile
              key={`builtin:${item.id}`}
              item={item}
              selected={selected?.kind === 'builtin' && selected.id === item.id}
              onSelect={() => onSelect(item)}
            />
          ))}
        </div>
      </div>

      {/* User-saved */}
      <div>
        <div className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground mb-2 flex items-center justify-between">
          <span>{t('scope.your_segments', 'Your saved segments')}</span>
          {savedItems.length > 0 && (
            <span className="text-muted-foreground normal-case tracking-normal">
              {t('scope.build_new_in_custom', 'Build new ones in the "Custom filter" tab')}
            </span>
          )}
        </div>
        {savedItems.length === 0 && !savedLoading && (
          <div className="text-[12px] text-muted-foreground p-4 text-center border border-dashed border-border rounded-md">
            {t('scope.no_saved_segments_long', 'No saved segments yet. Build a custom filter and save it to reuse here.')}
          </div>
        )}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
          {savedItems.map((item) => (
            <SegmentTile
              key={`saved:${item.id}`}
              item={item}
              selected={selected?.kind === 'saved' && selected.id === item.id}
              onSelect={() => onSelect(item)}
              onDelete={item.kind === 'saved' ? () => onDelete(item.id as number) : undefined}
              deleting={item.kind === 'saved' && deleting === (item.id as number)}
            />
          ))}
        </div>
      </div>
    </div>
  );
}

function SegmentTile({
  item,
  selected,
  onSelect,
  onDelete,
  deleting,
}: {
  item: SegmentChoice;
  selected: boolean;
  onSelect: () => void;
  onDelete?: () => void;
  deleting?: boolean;
}) {
  return (
    <div
      className={cn(
        'text-left p-3 border rounded-lg transition-colors flex items-start gap-2',
        selected ? 'border-primary bg-primary-50' : 'border-border bg-white hover:bg-muted'
      )}
    >
      <button type="button" onClick={onSelect} className="flex-1 min-w-0 text-left">
        <div className="flex items-center gap-2 mb-1 flex-wrap">
          <span className="font-semibold text-[13px]">{item.name}</span>
          {item.kind === 'builtin' ? (
            <>
              <Badge variant="muted">{t('scope.badge_builtin', 'Built-in')}</Badge>
              <Badge variant={item.count > 0 ? 'primary' : 'muted'}>{item.count}</Badge>
            </>
          ) : (
            <Badge variant="muted">{t('scope.badge_saved', 'Saved')}</Badge>
          )}
        </div>
        {item.description && (
          <div className="text-[11px] text-muted-foreground">{item.description}</div>
        )}
        {item.kind === 'saved' && item.filters.length > 0 && (
          <div className="text-[11px] text-muted-foreground mt-0.5">
            {t2('scope.n_filters', '{n} filter{plural}', { n: item.filters.length, plural: item.filters.length !== 1 ? 's' : '' })}
          </div>
        )}
      </button>
      {onDelete && (
        <button
          type="button"
          onClick={onDelete}
          disabled={deleting}
          className="text-muted-foreground hover:text-destructive text-[14px] px-1 leading-none"
          title={t('scope.delete_segment_title', 'Delete segment')}
        >
          {deleting ? '…' : '✕'}
        </button>
      )}
    </div>
  );
}

// =========================================================================
// Save-as-segment row inside the Custom filter tab
// =========================================================================

function SaveSegmentRow({ spec }: { spec: FilterSpec }) {
  const [name, setName] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState<string | null>(null);
  const qc = useQueryClient();

  const disabled = spec.filters.length === 0 || !name.trim() || saving;

  const save = async () => {
    if (disabled) return;
    setSaving(true);
    setError(null);
    setSaved(null);
    try {
      const seg = await segmentsApi.create({ name: name.trim(), conditions: spec });
      setSaved(seg.name);
      setName('');
      await qc.invalidateQueries({ queryKey: ['segments', 'saved'] });
    } catch (e) {
      setError((e as Error).message || t('scope.save_failed', 'Save failed'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="flex items-end gap-2 pt-2 border-t border-border">
      <Field label={t('scope.save_filter_as_segment', 'Save this filter as a segment')} className="flex-1">
        <Input value={name} onChange={(e) => setName(e.target.value)} placeholder={t('scope.save_segment_placeholder', 'e.g. DAF products without meta')} />
      </Field>
      <Button onClick={save} disabled={disabled}>{saving ? t('common.saving', 'Saving…') : t('scope.save_segment_btn', 'Save segment')}</Button>
      {saved && <Badge variant="success">{t2('scope.saved_label', 'Saved: {name}', { name: saved })}</Badge>}
      {error && <Badge variant="destructive">{error}</Badge>}
    </div>
  );
}

// =========================================================================
// Derive filter spec from scope (for batch create calls)
// =========================================================================

export function scopeToFilterSpec(v: ScopeValue): FilterSpec | null {
  if (v.mode === 'segment' && v.segment) return { filters: v.segment.filters };
  if (v.mode === 'custom' && v.customSpec && v.customSpec.filters.length > 0) return v.customSpec;
  return null;
}
