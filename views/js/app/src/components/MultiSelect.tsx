import { useEffect, useMemo, useRef, useState } from 'react';
import { cn } from '../lib/utils';

export interface MultiSelectOption {
  id: number;
  label: string;
  sublabel?: string;
}

interface MultiSelectProps {
  options: MultiSelectOption[];
  value: number[];
  onChange: (ids: number[]) => void;
  placeholder?: string;
  searchable?: boolean;
  emptyLabel?: string;
  maxHeight?: number;
}

export default function MultiSelect({
  options,
  value,
  onChange,
  placeholder = 'Select…',
  searchable = true,
  emptyLabel = 'No options',
  maxHeight = 240,
}: MultiSelectProps) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const wrapRef = useRef<HTMLDivElement>(null);

  const filtered = useMemo(() => {
    if (!query.trim()) return options;
    const q = query.toLowerCase();
    return options.filter((o) => o.label.toLowerCase().includes(q) || (o.sublabel ?? '').toLowerCase().includes(q));
  }, [options, query]);

  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, []);

  const selectedLabels = useMemo(
    () => options.filter((o) => value.includes(o.id)).map((o) => o.label),
    [options, value]
  );

  const toggle = (id: number) => {
    if (value.includes(id)) onChange(value.filter((v) => v !== id));
    else onChange([...value, id]);
  };

  return (
    <div ref={wrapRef} className="relative">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className={cn(
          'w-full text-left px-3 py-2 border border-border rounded-md bg-white text-[13px]',
          'flex items-center justify-between gap-2',
          open && 'ring-2 ring-primary border-primary'
        )}
      >
        {selectedLabels.length === 0 ? (
          <span className="text-muted-foreground">{placeholder}</span>
        ) : selectedLabels.length <= 2 ? (
          <span className="truncate">{selectedLabels.join(', ')}</span>
        ) : (
          <span>
            {selectedLabels[0]}, <span className="text-muted-foreground">+{selectedLabels.length - 1} more</span>
          </span>
        )}
        <span className="text-muted-foreground text-[12px]">▾</span>
      </button>

      {open && (
        <div
          className="absolute left-0 right-0 top-[calc(100%+4px)] z-30 bg-white border border-border rounded-md shadow-lg flex flex-col"
          style={{ maxHeight }}
        >
          {searchable && (
            <input
              className="px-3 py-2 text-[13px] border-b border-border focus:outline-none"
              placeholder="Search…"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              autoFocus
            />
          )}
          <div className="overflow-y-auto flex-1">
            {filtered.length === 0 ? (
              <div className="px-3 py-4 text-[12px] text-muted-foreground text-center">{emptyLabel}</div>
            ) : (
              filtered.map((o) => {
                const selected = value.includes(o.id);
                return (
                  <button
                    key={o.id}
                    type="button"
                    onClick={() => toggle(o.id)}
                    className={cn(
                      'w-full text-left px-3 py-2 text-[13px] flex items-center gap-2 hover:bg-muted',
                      selected && 'bg-primary-50'
                    )}
                  >
                    <span
                      className={cn(
                        'w-4 h-4 border rounded flex items-center justify-center flex-shrink-0',
                        selected ? 'border-primary bg-primary text-white' : 'border-slate-300 bg-white'
                      )}
                    >
                      {selected && <span className="text-[10px] leading-none">✓</span>}
                    </span>
                    <span className="flex-1 min-w-0 truncate">{o.label}</span>
                    {o.sublabel && (
                      <span className="text-[11px] text-muted-foreground flex-shrink-0">{o.sublabel}</span>
                    )}
                  </button>
                );
              })
            )}
          </div>
          {value.length > 0 && (
            <div className="border-t border-border px-3 py-1.5 text-[11px] flex items-center justify-between bg-muted">
              <span>{value.length} selected</span>
              <button type="button" onClick={() => onChange([])} className="text-primary hover:underline">
                Clear
              </button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
