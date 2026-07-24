import type { ReactNode, HTMLAttributes } from 'react';
import { cn } from '../lib/utils';

type Padding = 'none' | 'sm' | 'md' | 'lg' | 'xl';
type Tone = 'default' | 'info' | 'warning' | 'success' | 'destructive';

interface CardProps extends HTMLAttributes<HTMLDivElement> {
  padding?: Padding;
  tone?: Tone;
  children: ReactNode;
}

const paddings: Record<Padding, string> = {
  none: 'p-0',
  sm:   'p-3',
  md:   'p-4',
  lg:   'p-5',
  xl:   'p-7',
};

const tones: Record<Tone, string> = {
  default:     'bg-white border-border text-slate-900',
  info:        'bg-blue-50 border-blue-200 text-blue-900',
  warning:     'bg-amber-50 border-amber-200 text-amber-900',
  success:     'bg-green-50 border-green-200 text-green-900',
  destructive: 'bg-red-50 border-red-200 text-red-900',
};

/**
 * Surface card used everywhere inside the module content area.
 * Consistent border-radius (8px), border, background, and padding scale.
 */
export default function Card({
  padding = 'lg',
  tone = 'default',
  className,
  children,
  ...rest
}: CardProps) {
  return (
    <div
      className={cn('border rounded-lg', paddings[padding], tones[tone], className)}
      {...rest}
    >
      {children}
    </div>
  );
}
