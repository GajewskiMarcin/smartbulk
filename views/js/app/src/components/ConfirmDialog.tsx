import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import Button from './Button';
import { cn } from '../lib/utils';
import { t } from '../lib/i18n';

type Tone = 'default' | 'destructive' | 'warning';

export interface ConfirmOptions {
  title: string;
  message?: ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  tone?: Tone;
}

interface InternalState extends ConfirmOptions {
  resolve: (ok: boolean) => void;
}

interface ConfirmContextValue {
  confirm: (opts: ConfirmOptions) => Promise<boolean>;
}

const ConfirmContext = createContext<ConfirmContextValue | null>(null);

export function ConfirmProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<InternalState | null>(null);
  const confirmBtnRef = useRef<HTMLButtonElement>(null);

  const confirm = useCallback((opts: ConfirmOptions) => {
    return new Promise<boolean>((resolve) => {
      setState({ ...opts, resolve });
    });
  }, []);

  const close = (value: boolean) => {
    if (!state) return;
    state.resolve(value);
    setState(null);
  };

  useEffect(() => {
    if (!state) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') { e.preventDefault(); close(false); }
      if (e.key === 'Enter')  { e.preventDefault(); close(true);  }
    };
    window.addEventListener('keydown', onKey);
    // Autofocus the confirm button so Enter works immediately
    setTimeout(() => confirmBtnRef.current?.focus(), 0);
    return () => window.removeEventListener('keydown', onKey);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [state]);

  return (
    <ConfirmContext.Provider value={{ confirm }}>
      {children}
      {state && (
        <div
          className="fixed inset-0 z-[10000] flex items-center justify-center bg-black/40 p-4"
          onClick={() => close(false)}
        >
          <div
            className="bg-white rounded-lg shadow-xl border border-border max-w-[440px] w-full overflow-hidden"
            onClick={(e) => e.stopPropagation()}
            role="dialog"
            aria-modal="true"
            aria-labelledby="smartbulk-confirm-title"
          >
            <div className="px-5 py-4 border-b border-border">
              <h3 id="smartbulk-confirm-title" className="text-[15px] font-semibold text-slate-900">
                {state.title}
              </h3>
            </div>
            {state.message && (
              <div className={cn(
                'px-5 py-4 text-[13px] leading-relaxed text-slate-700',
                state.tone === 'destructive' && 'bg-red-50',
                state.tone === 'warning'     && 'bg-amber-50'
              )}>
                {state.message}
              </div>
            )}
            <div className="px-5 py-3 flex items-center justify-end gap-2 border-t border-border bg-muted/30">
              <Button variant="ghost" onClick={() => close(false)}>
                {state.cancelLabel ?? t('common.cancel', 'Cancel')}
              </Button>
              <Button
                ref={confirmBtnRef}
                variant={state.tone === 'destructive' ? 'destructive' : 'primary'}
                onClick={() => close(true)}
              >
                {state.confirmLabel ?? t('common.confirm', 'Confirm')}
              </Button>
            </div>
          </div>
        </div>
      )}
    </ConfirmContext.Provider>
  );
}

export function useConfirm(): ConfirmContextValue['confirm'] {
  const ctx = useContext(ConfirmContext);
  if (!ctx) throw new Error('useConfirm must be used within ConfirmProvider');
  return ctx.confirm;
}
