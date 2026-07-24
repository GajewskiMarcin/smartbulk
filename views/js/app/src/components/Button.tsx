import { forwardRef, type ButtonHTMLAttributes } from 'react';
import { cn } from '../lib/utils';

type Variant = 'default' | 'primary' | 'ghost' | 'destructive';
type Size = 'default' | 'sm' | 'compact';

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant;
  size?: Size;
}

const variants: Record<Variant, string> = {
  default:     'bg-white border border-border text-slate-900 hover:bg-muted',
  primary:     'bg-primary border border-primary text-white hover:bg-primary-700',
  ghost:       'bg-transparent border border-transparent text-slate-700 hover:bg-muted',
  destructive: 'bg-destructive border border-destructive text-white hover:opacity-90',
};

const sizes: Record<Size, string> = {
  default: 'px-3.5 py-2 text-[13px]',
  sm:      'px-2.5 py-1.5 text-[12px]',
  compact: 'px-2 py-1 text-[12px] whitespace-nowrap',
};

const Button = forwardRef<HTMLButtonElement, ButtonProps>(
  ({ variant = 'default', size = 'default', className, ...props }, ref) => (
    <button
      ref={ref}
      className={cn(
        'inline-flex items-center justify-center gap-1.5 rounded-md font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed',
        variants[variant],
        sizes[size],
        className
      )}
      {...props}
    />
  )
);
Button.displayName = 'Button';
export default Button;
