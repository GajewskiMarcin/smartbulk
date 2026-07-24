import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import Button from '../Button';
import { Input } from '../Form';
import { useToast } from '../Toast';
import { useConfirm } from '../ConfirmDialog';
import { bulkApi, type ActionTemplate, type BulkAction } from '../../lib/bulk';
import { cn } from '../../lib/utils';
import { t } from '../../lib/i18n';

interface Props {
  onPick: (actions: BulkAction[]) => void;
  disabled?: boolean;
  // Current actions on the editor — needed for "Save as template".
  currentActions?: BulkAction[];
}

function groupLabel(g: string): string {
  switch (g) {
    case 'user':    return t('tpl_picker.group_user',    '⭐ Your templates');
    case 'seo':     return t('tpl_picker.group_seo',     'SEO');
    case 'stock':   return t('tpl_picker.group_stock',   'Stock');
    case 'basic':   return t('tpl_picker.group_basic',   'Basic');
    case 'content': return t('tpl_picker.group_content', 'Content');
    case 'ai':      return t('tpl_picker.group_ai',      'AI-powered');
    default:        return g;
  }
}

export default function TemplatePicker({ onPick, disabled, currentActions = [] }: Props) {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();

  const [open, setOpen] = useState(false);
  const [savingMode, setSavingMode] = useState(false);
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');

  const query = useQuery({
    queryKey: ['bulk', 'templates'],
    queryFn: bulkApi.templates,
    staleTime: 10 * 60 * 1000,
  });

  const saveMutation = useMutation({
    mutationFn: (input: { name: string; description?: string; actions: BulkAction[] }) =>
      bulkApi.saveTemplate(input),
    onSuccess: () => {
      toast.show(t('tpl_picker.toast_saved', 'Template saved'), 'success');
      qc.invalidateQueries({ queryKey: ['bulk', 'templates'] });
      setSavingMode(false);
      setName('');
      setDescription('');
    },
    onError: (e: Error) => toast.show(e.message || t('tpl_picker.toast_save_failed', 'Save failed'), 'error'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => bulkApi.deleteTemplate(id),
    onSuccess: () => {
      toast.show(t('tpl_picker.toast_deleted', 'Template deleted'), 'info');
      qc.invalidateQueries({ queryKey: ['bulk', 'templates'] });
    },
    onError: (e: Error) => toast.show(e.message || t('tpl_picker.toast_delete_failed', 'Delete failed'), 'error'),
  });

  const handleDelete = async (tpl: ActionTemplate) => {
    if (!tpl.id_user) return;
    const ok = await confirm({
      title: t('tpl_picker.confirm_delete_title', 'Delete') + ` "${tpl.name}"?`,
      message: t('tpl_picker.confirm_delete_msg', 'Built-in templates aren\'t affected. This only removes your saved template.'),
      confirmLabel: t('common.delete', 'Delete'),
      tone: 'destructive',
    });
    if (ok) deleteMutation.mutate(tpl.id_user);
  };

  const groups: Record<string, ActionTemplate[]> = {};
  (query.data ?? []).forEach((t) => {
    (groups[t.group] ||= []).push(t);
  });

  // Always show user group first.
  const orderedGroupKeys = ['user', ...Object.keys(groups).filter((k) => k !== 'user')];

  const canSave = currentActions.filter((a) => a.operator !== 'skip').length > 0;

  return (
    <div className="relative inline-block">
      <Button onClick={() => setOpen((o) => !o)} disabled={disabled || query.isLoading}>
        {open ? t('tpl_picker.close_templates', 'Close templates') : t('tpl_picker.load_template', '📋 Load template ▾')}
      </Button>
      {open && (
        <div className="absolute left-0 top-[calc(100%+4px)] z-20 bg-white border border-border rounded-md shadow-lg w-[400px] max-h-[500px] overflow-hidden flex flex-col">
          <div className="px-3 py-2 border-b border-border bg-muted/40 flex items-center justify-between gap-2">
            <div className="min-w-0">
              <div className="text-[12px] font-semibold text-slate-900">{t('tpl_picker.templates', 'Templates')}</div>
              <div className="text-[10px] text-muted-foreground">{t('tpl_picker.adds_actions', 'Adds the actions into the editor. You can still edit them.')}</div>
            </div>
            {!savingMode && canSave && (
              <Button size="sm" onClick={() => setSavingMode(true)}>{t('tpl_picker.save_current', '+ Save current')}</Button>
            )}
          </div>

          {savingMode && (
            <div className="px-3 py-2 border-b border-border bg-blue-50 flex flex-col gap-1.5">
              <Input
                placeholder={t('tpl_picker.template_name_placeholder', 'Template name (e.g. Q4 SEO refresh)')}
                value={name}
                onChange={(e) => setName(e.target.value)}
                autoFocus
              />
              <Input
                placeholder={t('tpl_picker.description_placeholder', 'Description (optional)')}
                value={description}
                onChange={(e) => setDescription(e.target.value)}
              />
              <div className="flex justify-end gap-1.5 mt-1">
                <Button size="sm" variant="ghost" onClick={() => { setSavingMode(false); setName(''); setDescription(''); }}>{t('common.cancel', 'Cancel')}</Button>
                <Button
                  size="sm"
                  variant="primary"
                  disabled={!name.trim() || saveMutation.isPending}
                  onClick={() => saveMutation.mutate({ name: name.trim(), description, actions: currentActions })}
                >
                  {saveMutation.isPending ? t('common.saving', 'Saving…') : t('tpl_picker.save_template', 'Save template')}
                </Button>
              </div>
            </div>
          )}

          <div className="overflow-y-auto flex-1">
            {orderedGroupKeys.map((g) => {
              const items = groups[g];
              if (!items || items.length === 0) return null;
              return (
                <div key={g}>
                  <div className="px-3 py-1.5 text-[10px] uppercase tracking-wide font-semibold text-muted-foreground bg-muted">
                    {groupLabel(g)}
                  </div>
                  {items.map((tpl) => (
                    <div key={tpl.id} className="flex items-start group hover:bg-muted border-b border-border/50">
                      <button
                        type="button"
                        onClick={() => { onPick(tpl.actions); setOpen(false); }}
                        className={cn(
                          'flex-1 text-left px-3 py-2 flex items-start gap-2',
                          'focus:outline-none'
                        )}
                      >
                        <span className="text-[16px] flex-shrink-0 leading-none mt-0.5">{tpl.emoji}</span>
                        <div className="min-w-0">
                          <div className="font-semibold text-[13px] truncate">{tpl.name}</div>
                          <div className="text-[11px] text-muted-foreground leading-snug">{tpl.description}</div>
                        </div>
                      </button>
                      {tpl.is_user && (
                        <button
                          type="button"
                          onClick={() => handleDelete(tpl)}
                          className="text-[12px] text-muted-foreground hover:text-red-700 px-2 py-2 opacity-0 group-hover:opacity-100"
                          title={t('tpl_picker.delete_tpl_tooltip', 'Delete this template')}
                        >
                          ✕
                        </button>
                      )}
                    </div>
                  ))}
                </div>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}
