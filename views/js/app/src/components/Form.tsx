import { forwardRef, useState, type InputHTMLAttributes, type SelectHTMLAttributes, type TextareaHTMLAttributes, type ReactNode } from 'react';
import { cn } from '../lib/utils';

// ---------- Label + Field wrapper ----------

interface FieldProps {
  label: string;
  hint?: string;
  error?: string;
  required?: boolean;
  children: ReactNode;
  className?: string;
}

export function Field({ label, hint, error, required, children, className }: FieldProps) {
  return (
    <div className={cn('flex flex-col gap-1.5', className)}>
      <label className="text-[12px] font-medium text-slate-700">
        {label}
        {required && <span className="text-destructive ml-0.5">*</span>}
      </label>
      {children}
      {error ? (
        <div className="text-[11px] text-destructive">{error}</div>
      ) : hint ? (
        <div className="text-[11px] text-muted-foreground">{hint}</div>
      ) : null}
    </div>
  );
}

// ---------- Text input ----------

const inputBase =
  'w-full px-3 py-2 border border-border rounded-md bg-white text-[13px] text-slate-900 ' +
  'focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-0 focus:border-primary ' +
  'disabled:opacity-60 disabled:cursor-not-allowed';

type InputProps = InputHTMLAttributes<HTMLInputElement>;

export const Input = forwardRef<HTMLInputElement, InputProps>(
  ({ className, ...props }, ref) => (
    <input ref={ref} className={cn(inputBase, className)} {...props} />
  )
);
Input.displayName = 'Input';

// ---------- Password input with show/hide + optional "stored" indicator ----------

interface PasswordInputProps extends Omit<InputProps, 'type'> {
  hasStoredValue?: boolean;
  maskedPreview?: string;
}

export function PasswordInput({ hasStoredValue, maskedPreview, placeholder, ...rest }: PasswordInputProps) {
  const [visible, setVisible] = useState(false);
  const effectivePlaceholder =
    hasStoredValue && !rest.value
      ? `Stored: ${maskedPreview || '••••••••'} — leave blank to keep`
      : placeholder;

  return (
    <div className="relative">
      <input
        type={visible ? 'text' : 'password'}
        className={cn(inputBase, 'pr-16 font-mono')}
        placeholder={effectivePlaceholder}
        autoComplete="off"
        spellCheck={false}
        {...rest}
      />
      <button
        type="button"
        onClick={() => setVisible((v) => !v)}
        className="absolute right-2 top-1/2 -translate-y-1/2 text-[11px] font-medium text-slate-500 hover:text-slate-900 px-1.5 py-0.5 rounded"
        tabIndex={-1}
      >
        {visible ? 'Hide' : 'Show'}
      </button>
    </div>
  );
}

// ---------- Select ----------

type SelectProps = SelectHTMLAttributes<HTMLSelectElement>;

export const Select = forwardRef<HTMLSelectElement, SelectProps>(
  ({ className, children, ...props }, ref) => (
    <select ref={ref} className={cn(inputBase, 'pr-8 cursor-pointer', className)} {...props}>
      {children}
    </select>
  )
);
Select.displayName = 'Select';

// ---------- Textarea ----------

type TextareaProps = TextareaHTMLAttributes<HTMLTextAreaElement>;

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(
  ({ className, ...props }, ref) => (
    <textarea
      ref={ref}
      className={cn(inputBase, 'min-h-[96px] resize-y leading-relaxed', className)}
      {...props}
    />
  )
);
Textarea.displayName = 'Textarea';

// ---------- Checkbox ----------

interface CheckboxProps extends Omit<InputProps, 'type'> {
  label: string;
  hint?: string;
}

export function Checkbox({ label, hint, className, ...rest }: CheckboxProps) {
  return (
    <label className={cn('flex items-start gap-2.5 cursor-pointer select-none', className)}>
      <input
        type="checkbox"
        className="mt-0.5 w-4 h-4 accent-primary cursor-pointer"
        {...rest}
      />
      <div className="flex flex-col">
        <span className="text-[13px] text-slate-700">{label}</span>
        {hint && <span className="text-[11px] text-muted-foreground">{hint}</span>}
      </div>
    </label>
  );
}
