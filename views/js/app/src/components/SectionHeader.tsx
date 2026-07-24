import type { ReactNode } from 'react';

interface SectionHeaderProps {
  title: string;
  subtitle?: ReactNode;
  action?: ReactNode;
  /** Use smaller variant inside widget cards */
  size?: 'md' | 'sm';
}

/**
 * Header row inside a Card — title + optional subtitle on the left, action on the right.
 * Used for "Content Health issues", "Recent activity", "AI spend today", etc.
 */
export default function SectionHeader({
  title,
  subtitle,
  action,
  size = 'md',
}: SectionHeaderProps) {
  return (
    <div className="flex items-start justify-between gap-3 mb-3">
      <div className="min-w-0">
        <div className={size === 'sm' ? 'font-semibold text-[14px]' : 'font-semibold text-[15px]'}>
          {title}
        </div>
        {subtitle && (
          <div className="text-muted-foreground text-[12px] mt-0.5">{subtitle}</div>
        )}
      </div>
      {action && <div className="flex-shrink-0 flex items-center gap-2">{action}</div>}
    </div>
  );
}
