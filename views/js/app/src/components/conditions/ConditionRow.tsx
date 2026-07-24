import { useQuery } from '@tanstack/react-query';
import FieldDropdown from './FieldDropdown';
import ValueInput from './ValueInput';
import {
  conditionsApi,
  createLeaf,
  OPERATOR_LABELS,
  type ConditionLeaf,
  type FieldCatalog,
  type Operator,
} from '../../lib/conditions';
import { t } from '../../lib/i18n';

interface Props {
  catalog: FieldCatalog;
  leaf: ConditionLeaf;
  onChange: (leaf: ConditionLeaf) => void;
  onRemove: () => void;
}

/**
 * Single row inside a condition group:  [ Field ] [ Operator ] [ Value ] [ × ]
 * Field-typed operator list + value input driven by FieldCatalog.
 */
export default function ConditionRow({ catalog, leaf, onChange, onRemove }: Props) {
  const field = catalog.fields.find((f) => f.id === leaf.field);
  const langsQuery = useQuery({
    queryKey: ['lookup', 'languages'],
    queryFn: conditionsApi.languages,
    enabled: !!field?.lang,
  });

  const handleFieldChange = (newFieldId: string) => {
    const newField = catalog.fields.find((f) => f.id === newFieldId);
    if (!newField) return;
    onChange(createLeaf(newField));
  };

  const handleOpChange = (op: Operator) => {
    onChange({ ...leaf, op, value: undefined, value2: undefined });
  };

  const updateLeaf = (patch: Partial<ConditionLeaf>) => {
    onChange({ ...leaf, ...patch });
  };

  return (
    <div className="bg-white border border-border rounded-md p-2.5">
      <div className="grid grid-cols-[1fr_180px_1.5fr_auto] gap-2 items-start">
        {/* Field */}
        <FieldDropdown
          fields={catalog.fields}
          groupLabels={catalog.group_labels}
          value={leaf.field}
          onChange={handleFieldChange}
        />

        {/* Operator */}
        <select
          className="w-full px-3 py-2 border border-border rounded-md bg-white text-[13px]"
          value={leaf.op}
          onChange={(e) => handleOpChange(e.target.value as Operator)}
          disabled={!field}
        >
          {(field?.operators ?? ['equals']).map((op) => (
            <option key={op} value={op}>{OPERATOR_LABELS[op] ?? op}</option>
          ))}
        </select>

        {/* Value */}
        <div className="min-w-0">
          {field ? (
            <ValueInput field={field} leaf={leaf} onChange={updateLeaf} />
          ) : (
            <div className="text-[12px] text-muted-foreground py-2 px-3">{t('cond.pick_field_first', 'Pick a field first')}</div>
          )}

          {/* Per-language selector for multilang fields */}
          {field?.lang && (
            <div className="flex items-center gap-2 mt-1.5 text-[11px] text-muted-foreground">
              <span>{t('cond.in_language', 'In language:')}</span>
              <select
                className="px-2 py-1 border border-border rounded text-[11px] bg-white"
                value={leaf.id_lang === null || leaf.id_lang === undefined ? 'any' : String(leaf.id_lang)}
                onChange={(e) =>
                  updateLeaf({ id_lang: e.target.value === 'any' ? null : Number(e.target.value) })
                }
              >
                <option value="any">{t('cond.any_current_lang', 'any (current)')}</option>
                {(langsQuery.data ?? []).map((l) => (
                  <option key={l.id_lang} value={l.id_lang}>
                    {l.iso_code.toUpperCase()} — {l.name}
                  </option>
                ))}
              </select>
            </div>
          )}
        </div>

        {/* Remove */}
        <button
          type="button"
          onClick={onRemove}
          className="text-muted-foreground hover:text-destructive text-[16px] leading-none px-1 py-2"
          title={t('cond.remove_condition', 'Remove condition')}
        >
          ✕
        </button>
      </div>
    </div>
  );
}
