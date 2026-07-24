import type { ReactNode } from 'react';

interface PageHeadProps {
  title: string;
  subtitle?: ReactNode;
  actions?: ReactNode;
}

export default function PageHead({ title, subtitle, actions }: PageHeadProps) {
  return (
    <div className="flex items-end justify-between gap-4 mb-6">
      <div>
        <h1 className="m-0 text-[22px] font-bold leading-tight">{title}</h1>
        {subtitle && <div className="text-muted-foreground text-[13px] mt-1">{subtitle}</div>}
      </div>
      {actions && <div className="flex gap-2 items-center flex-shrink-0">{actions}</div>}
    </div>
  );
}
