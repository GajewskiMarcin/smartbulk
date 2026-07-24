// SmartBulk — Condition Tree types + operator labels + API

import { api } from './api';

export type ValueType = 'text' | 'int' | 'float' | 'bool' | 'date' | 'enum' | 'related_many' | 'gap';

export type Operator =
  // text
  | 'contains' | 'not_contains' | 'starts_with' | 'ends_with'
  | 'equals' | 'not_equals' | 'matches_regex'
  | 'is_empty' | 'is_not_empty'
  | 'length_lt' | 'length_gt' | 'length_eq' | 'length_between'
  // numeric
  | 'eq' | 'neq' | 'lt' | 'lte' | 'gt' | 'gte'
  | 'between' | 'not_between' | 'is_null' | 'is_not_null'
  // bool
  | 'is_true' | 'is_false'
  // related
  | 'contains_any' | 'contains_all' | 'contains_none' | 'has_no_value'
  // enum
  | 'is_one_of' | 'is_not_one_of'
  // date
  | 'before' | 'after' | 'on' | 'last_n_days' | 'next_n_days';

export interface FieldDef {
  id: string;
  label: string;
  group: 'basic' | 'taxonomy' | 'price_stock' | 'content_gaps' | 'behavior' | 'date';
  value_type: ValueType;
  operators: Operator[];
  lang?: boolean;
  options?: { value: string; label: string }[];
  lookup?: 'categories' | 'brands' | 'suppliers' | 'features' | 'tags' | 'attributes' | 'tax_rules';
  pairs?: boolean;   // for feature — value is {id_feature, id_feature_value}[]
  unit?: 'currency' | 'cm' | 'kg';
}

export interface FieldCatalog {
  fields: FieldDef[];
  group_labels: Record<string, string>;
}

// ---------- Tree types ----------

export type Combine = 'and' | 'or';

export interface ConditionLeaf {
  kind: 'cond';
  field: string;
  op: Operator;
  value?: unknown;
  value2?: unknown; // for between
  id_lang?: number | null;
}

export interface ConditionGroup {
  kind: 'group';
  combine: Combine;
  conditions: ConditionNode[];
}

export type ConditionNode = ConditionLeaf | ConditionGroup;

/** Root filter spec — identical shape to a ConditionGroup so the recursive
 *  renderer can treat the root as just another group. */
export type TreeSpec = ConditionGroup;

export function emptyTree(): TreeSpec {
  return { kind: 'group', combine: 'and', conditions: [] };
}

export function isGroup(node: ConditionNode): node is ConditionGroup {
  return node.kind === 'group';
}

// ---------- Operator human-readable labels ----------

export const OPERATOR_LABELS: Record<Operator, string> = {
  contains:         'contains',
  not_contains:     'does not contain',
  starts_with:      'starts with',
  ends_with:        'ends with',
  equals:           'equals',
  not_equals:       'does not equal',
  matches_regex:    'matches regex',
  is_empty:         'is empty',
  is_not_empty:     'is not empty',
  length_lt:        'length <',
  length_gt:        'length >',
  length_eq:        'length =',
  length_between:   'length between',
  eq:               '=',
  neq:              '≠',
  lt:               '<',
  lte:              '≤',
  gt:               '>',
  gte:              '≥',
  between:          'between',
  not_between:      'not between',
  is_null:          'is empty',
  is_not_null:      'is not empty',
  is_true:          'is yes',
  is_false:         'is no',
  contains_any:     'is one of',
  contains_all:     'contains all of',
  contains_none:    'is none of',
  has_no_value:     'has no value',
  is_one_of:        'is one of',
  is_not_one_of:    'is not one of',
  before:           'before',
  after:            'after',
  on:               'on',
  last_n_days:      'in last N days',
  next_n_days:      'in next N days',
};

// Operators that take no value input
export const NO_VALUE_OPS: Operator[] = [
  'is_empty', 'is_not_empty', 'is_null', 'is_not_null',
  'is_true', 'is_false', 'has_no_value',
];

// Operators with two values (from/to)
export const TWO_VALUE_OPS: Operator[] = ['between', 'not_between', 'length_between'];

// ---------- API ----------

const LOOKUP = '/modules/smartbulk/api/lookups';

export const conditionsApi = {
  fieldCatalog: () =>
    api.get<{ ok: boolean; fields: FieldDef[]; group_labels: Record<string, string> }>(
      `${LOOKUP}/field-catalog`
    ).then((r) => ({ fields: r.fields, group_labels: r.group_labels } as FieldCatalog)),

  attributes: () =>
    api.get<{ ok: boolean; attributes: { id_attribute_group: number; name: string; values: { id_attribute: number; value: string }[] }[] }>(
      `${LOOKUP}/attributes`
    ).then((r) => r.attributes),

  languages: () =>
    api.get<{ ok: boolean; languages: { id_lang: number; name: string; iso_code: string }[] }>(
      `${LOOKUP}/languages`
    ).then((r) => r.languages),
};

// ---------- Helpers ----------

export function defaultOperatorFor(field: FieldDef): Operator {
  return field.operators[0] ?? 'equals';
}

export function createLeaf(field: FieldDef): ConditionLeaf {
  const op = defaultOperatorFor(field);
  const leaf: ConditionLeaf = { kind: 'cond', field: field.id, op };
  if (field.lang) leaf.id_lang = null; // null = any/current lang
  return leaf;
}

/** Render the tree as a simple SQL-like preview for the query panel. */
export function renderQueryPreview(
  tree: TreeSpec,
  fields: FieldDef[]
): string {
  return renderNode(tree, fields, 0);
}

function renderNode(node: TreeSpec | ConditionNode, fields: FieldDef[], depth: number): string {
  if ('conditions' in node) {
    const children = node.conditions
      .map((c) => renderNode(c, fields, depth + 1))
      .filter((s) => s.length > 0);
    if (children.length === 0) return '';
    if (children.length === 1) return children[0];
    const sep = `\n${'  '.repeat(depth)}${node.combine.toUpperCase()} `;
    const body = children.join(sep);
    return depth === 0 ? body : `(\n${'  '.repeat(depth + 1)}${body}\n${'  '.repeat(depth)})`;
  }
  const def = fields.find((f) => f.id === node.field);
  const label = def?.label ?? node.field;
  const op = OPERATOR_LABELS[node.op] ?? node.op;
  const val = NO_VALUE_OPS.includes(node.op)
    ? ''
    : ' ' + formatValue(node, def);
  const lang = def?.lang && node.id_lang !== null && node.id_lang !== undefined
    ? ` [lang ${node.id_lang}]`
    : '';
  return `${label} ${op}${val}${lang}`;
}

function formatValue(leaf: ConditionLeaf, _def?: FieldDef): string {
  const v = leaf.value;
  if (v === undefined || v === null) return '?';
  if (Array.isArray(v)) return '[' + v.map(String).join(', ') + ']';
  if (TWO_VALUE_OPS.includes(leaf.op)) {
    return `${String(v)} … ${String(leaf.value2 ?? '?')}`;
  }
  return String(v);
}
