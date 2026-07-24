import Button from '../Button';
import ConditionRow from './ConditionRow';
import {
  type Combine,
  type ConditionGroup,
  type ConditionLeaf,
  type ConditionNode,
  type FieldCatalog,
  isGroup,
} from '../../lib/conditions';
import { cn } from '../../lib/utils';
import { t } from '../../lib/i18n';

interface Props {
  catalog: FieldCatalog;
  group: ConditionGroup;
  onChange: (group: ConditionGroup) => void;
  onRemove?: () => void;   // undefined for root
  depth?: number;
}

/**
 * Recursive condition group renderer.
 * Shows an AND/OR toggle, renders each child (leaf or nested group),
 * and exposes "+ Add condition" / "+ Add group" to extend.
 */
export default function ConditionGroupView({
  catalog, group, onChange, onRemove, depth = 0,
}: Props) {
  const nested = depth > 0;

  const update = (index: number, child: ConditionNode) => {
    const next = group.conditions.slice();
    next[index] = child;
    onChange({ ...group, conditions: next });
  };

  const remove = (index: number) => {
    onChange({ ...group, conditions: group.conditions.filter((_, i) => i !== index) });
  };

  const addCondition = () => {
    // Start with the first field so the row is immediately editable
    const first = catalog.fields[0];
    if (!first) return;
    const leaf: ConditionLeaf = { kind: 'cond', field: first.id, op: first.operators[0] ?? 'equals' };
    if (first.lang) leaf.id_lang = null;
    onChange({ ...group, conditions: [...group.conditions, leaf] });
  };

  const addGroup = () => {
    const nestedGroup: ConditionGroup = {
      kind: 'group',
      combine: 'and',
      conditions: [],
    };
    onChange({ ...group, conditions: [...group.conditions, nestedGroup] });
  };

  const setCombine = (combine: Combine) => onChange({ ...group, combine });

  return (
    <div
      className={cn(
        'rounded-md p-3',
        nested ? 'bg-slate-50 border border-dashed border-border' : 'bg-muted'
      )}
    >
      <div className="flex items-center gap-2 mb-2">
        <CombineToggle value={group.combine} onChange={setCombine} />
        <div className="flex-1" />
        {onRemove && (
          <button
            type="button"
            onClick={onRemove}
            className="text-muted-foreground hover:text-destructive text-[12px]"
            title={t('cond.remove_group_tooltip', 'Remove group')}
          >
            {t('cond.remove_group_btn', '✕ Remove group')}
          </button>
        )}
      </div>

      <div className="flex flex-col gap-2">
        {group.conditions.map((child, idx) => (
          <div key={idx}>
            {isGroup(child) ? (
              <ConditionGroupView
                catalog={catalog}
                group={child}
                onChange={(g) => update(idx, g)}
                onRemove={() => remove(idx)}
                depth={depth + 1}
              />
            ) : (
              <ConditionRow
                catalog={catalog}
                leaf={child}
                onChange={(leaf) => update(idx, leaf)}
                onRemove={() => remove(idx)}
              />
            )}
          </div>
        ))}
      </div>

      <div className="flex flex-wrap gap-2 mt-3">
        <Button size="sm" onClick={addCondition}>{t('cond.add_condition', '+ Add condition')}</Button>
        <Button size="sm" variant="ghost" onClick={addGroup}>{t('cond.add_group', '+ Add group')}</Button>
      </div>
    </div>
  );
}

function CombineToggle({ value, onChange }: { value: Combine; onChange: (v: Combine) => void }) {
  return (
    <div className="inline-flex gap-0.5 p-0.5 bg-white border border-border rounded">
      {(['and', 'or'] as Combine[]).map((c) => (
        <button
          key={c}
          type="button"
          onClick={() => onChange(c)}
          className={cn(
            'px-3 py-1 text-[11px] font-bold rounded transition-colors uppercase',
            value === c ? 'bg-primary text-white' : 'text-slate-600 hover:bg-muted'
          )}
        >
          {c}
        </button>
      ))}
    </div>
  );
}
