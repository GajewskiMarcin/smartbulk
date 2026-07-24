import { createContext, useCallback, useContext, useState, type ReactNode } from 'react';
import { cn } from '../lib/utils';

type Variant = 'success' | 'error' | 'info';

interface Toast {
  id: number;
  message: string;
  variant: Variant;
}

interface ToastContextValue {
  show: (message: string, variant?: Variant) => void;
}

const ToastContext = createContext<ToastContextValue | null>(null);

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([]);

  const show = useCallback((message: string, variant: Variant = 'info') => {
    const id = Date.now() + Math.random();
    setToasts((prev) => [...prev, { id, message, variant }]);
    setTimeout(() => {
      setToasts((prev) => prev.filter((t) => t.id !== id));
    }, 4000);
  }, []);

  return (
    <ToastContext.Provider value={{ show }}>
      {children}
      <div className="fixed top-4 right-4 z-[9999] flex flex-col gap-2 pointer-events-none">
        {toasts.map((t) => (
          <div
            key={t.id}
            className={cn(
              'pointer-events-auto px-4 py-3 rounded-lg shadow-lg border text-[13px] font-medium min-w-[240px] max-w-[400px]',
              t.variant === 'success' && 'bg-green-50 border-green-200 text-green-900',
              t.variant === 'error'   && 'bg-red-50 border-red-200 text-red-900',
              t.variant === 'info'    && 'bg-blue-50 border-blue-200 text-blue-900'
            )}
          >
            {t.message}
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  );
}

export function useToast(): ToastContextValue {
  const ctx = useContext(ToastContext);
  if (!ctx) throw new Error('useToast must be used within ToastProvider');
  return ctx;
}
