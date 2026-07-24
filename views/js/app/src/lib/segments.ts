// SmartBulk — Segments + lookups API client + types

import { api } from './api';

export type FilterType =
  | 'category' | 'brand' | 'supplier' | 'product_ids'
  | 'tag' | 'feature' | 'content_gaps' | 'status'
  | 'price_between' | 'date_added_after_days';

export interface FilterSpec {
  filters: FilterItem[];
}

export type FilterItem =
  | { type: 'category';    ids: number[] }
  | { type: 'brand';       ids: number[] }
  | { type: 'supplier';    ids: number[] }
  | { type: 'product_ids'; ids: number[] }
  | { type: 'tag';         ids: number[] }
  | { type: 'feature';     pairs: { id_feature: number; id_feature_value: number }[] }
  | { type: 'content_gaps'; value: string[]; id_lang?: number }
  | { type: 'status';      value: Array<'active' | 'inactive' | 'in_stock' | 'out_of_stock'> }
  | { type: 'price_between'; min?: number | null; max?: number | null }
  | { type: 'date_added_after_days'; value: number };

export interface Preset {
  id: string;
  name: string;
  description: string;
  filters: FilterItem[];
  count: number;
}

export interface SavedSegment {
  id_segment: number;
  name: string;
  description: string;
  // conditions can be either the legacy flat FilterSpec (AI Assistant) or the
  // nested TreeSpec used by Bulk Editor's Step 1. Consumers decide by sniffing
  // the presence of a `kind` key — see isTreeSegment().
  conditions: unknown;
  combine_logic: string;
  created_at: string;
  updated_at: string;
}

/** True when a saved segment's conditions look like a Bulk-Editor TreeSpec (nested AND/OR). */
export function isTreeSegment(c: unknown): boolean {
  return !!c && typeof c === 'object' && (c as { kind?: unknown }).kind === 'group';
}

/** True when a saved segment's conditions look like a flat FilterSpec (AI Assistant). */
export function isFlatSegment(c: unknown): boolean {
  return !!c && typeof c === 'object' && Array.isArray((c as { filters?: unknown }).filters);
}

export interface CategoryLookup {
  id_category: number;
  id_parent: number;
  name: string;
  level_depth: number;
}
export interface BrandLookup    { id_manufacturer: number; name: string }
export interface SupplierLookup { id_supplier: number; name: string }
export interface TagLookup      { id_tag: number; name: string }
export interface TaxRuleLookup  { id_tax_rules_group: number; name: string }
export interface LanguageLookup { id_lang: number; name: string; iso_code: string }
export interface FeatureLookup {
  id_feature: number;
  name: string;
  values: { id_feature_value: number; value: string }[];
}

// Grouped for the UI — the first-level `group` lets us draw a sub-header above each cluster.
export interface ContentGapOption {
  value: string;
  label: string;
  group: 'seo' | 'content' | 'images' | 'codes' | 'behavior';
}

export const CONTENT_GAP_GROUPS: Record<ContentGapOption['group'], string> = {
  seo:      'SEO / meta',
  content:  'Descriptions',
  images:   'Images',
  codes:    'Product codes',
  behavior: 'Behavior',
};

export const CONTENT_GAP_OPTIONS: ContentGapOption[] = [
  // SEO / meta
  { value: 'missing_meta_title',       label: 'Missing meta title',             group: 'seo' },
  { value: 'missing_meta_description', label: 'Missing meta description',       group: 'seo' },
  { value: 'missing_focus_keyphrase',  label: 'Missing focus keyphrase',        group: 'seo' },

  // Descriptions
  { value: 'missing_short_desc',       label: 'Missing short description',      group: 'content' },
  { value: 'missing_description',      label: 'Missing long description',       group: 'content' },
  { value: 'short_desc_lt_80',         label: 'Short description < 80 chars',   group: 'content' },
  { value: 'short_desc_lt_150',        label: 'Short description < 150 chars',  group: 'content' },
  { value: 'desc_lt_300',              label: 'Long description < 300 chars',   group: 'content' },

  // Images
  { value: 'missing_main_image',       label: 'Missing main image',             group: 'images' },
  { value: 'missing_alt_text',         label: 'Images without alt text',        group: 'images' },

  // Product codes — order matches PrestaShop "Details" tab naming
  { value: 'missing_reference',        label: 'Missing reference (SKU)',        group: 'codes' },
  { value: 'missing_mpn',              label: 'Missing MPN',                    group: 'codes' },
  { value: 'missing_upc',              label: 'Missing UPC barcode',            group: 'codes' },
  { value: 'missing_ean',              label: 'Missing GTIN (EAN / JAN)',       group: 'codes' },
  { value: 'missing_isbn',             label: 'Missing ISBN',                   group: 'codes' },

  // Behavior
  { value: 'never_sold',               label: 'Never sold',                     group: 'behavior' },
];

const SEG_BASE = '/modules/smartbulk/api/segments';
const LOOKUP_BASE = '/modules/smartbulk/api/lookups';

export const segmentsApi = {
  presets:       () => api.get<{ ok: boolean; presets: Preset[] }>(`${SEG_BASE}/presets`).then((r) => r.presets),
  count:         (spec: FilterSpec) =>
    api.post<{ ok: boolean; count: number }>(`${SEG_BASE}/count`, spec).then((r) => r.count),
  sample:        (spec: FilterSpec, limit = 5) =>
    api.post<{ ok: boolean; products: Array<{ id_product: number; name: string; reference: string; price: number }> }>(
      `${SEG_BASE}/sample`, { ...spec, limit }
    ).then((r) => r.products),
  productIds:    (spec: FilterSpec, limit?: number) =>
    api.post<{ ok: boolean; product_ids: number[] }>(
      `${SEG_BASE}/product-ids`, limit ? { ...spec, limit } : spec
    ).then((r) => r.product_ids),
  list:          () => api.get<{ ok: boolean; segments: SavedSegment[] }>(SEG_BASE).then((r) => r.segments),
  create:        (input: { name: string; description?: string; conditions: unknown }) =>
    api.post<{ ok: boolean; segment: SavedSegment }>(SEG_BASE, input).then((r) => r.segment),
  delete:        (id: number) => api.del<{ ok: boolean }>(`${SEG_BASE}/${id}`),
};

export const lookupsApi = {
  categories: () => api.get<{ ok: boolean; categories: CategoryLookup[] }>(`${LOOKUP_BASE}/categories`).then((r) => r.categories),
  brands:     () => api.get<{ ok: boolean; brands: BrandLookup[] }>(`${LOOKUP_BASE}/brands`).then((r) => r.brands),
  suppliers:  () => api.get<{ ok: boolean; suppliers: SupplierLookup[] }>(`${LOOKUP_BASE}/suppliers`).then((r) => r.suppliers),
  features:   () => api.get<{ ok: boolean; features: FeatureLookup[] }>(`${LOOKUP_BASE}/features`).then((r) => r.features),
  tags:       () => api.get<{ ok: boolean; tags: TagLookup[] }>(`${LOOKUP_BASE}/tags`).then((r) => r.tags),
  taxRules:   () => api.get<{ ok: boolean; tax_rules: TaxRuleLookup[] }>(`${LOOKUP_BASE}/tax-rules`).then((r) => r.tax_rules),
  languages:  () => api.get<{ ok: boolean; languages: LanguageLookup[] }>(`${LOOKUP_BASE}/languages`).then((r) => r.languages),
};
