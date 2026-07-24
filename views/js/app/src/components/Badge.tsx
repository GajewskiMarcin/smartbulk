import type { ReactNode } from 'react';
import { cn } from '../lib/utils';

type Variant = 'muted' | 'primary' | 'success' | 'warning' | 'destructive';

interface BadgeProps {
  variant?: Variant;
  children: ReactNode;
  className?: string;
}

const variants: Record<Variant, string> = {
  muted:       'bg-slate-100 text-slate-600',
  primary:     'bg-primary-50 text-primary-700',
  success:     'bg-green-100 text-green-800',
  warning:     'bg-amber-100 text-amber-800',
  destructive: 'bg-red-100 text-red-800',
};

export default function Badge({ variant = 'muted', children, className }: BadgeProps) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium leading-[1.5]',
        variants[variant],
        className
      )}
    >
      {children}
    </span>
  );
}
