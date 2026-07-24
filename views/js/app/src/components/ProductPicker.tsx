import { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { productsApi, type ProductRef } from '../lib/ai';
import { cn, formatPrice } from '../lib/utils';
import { t } from '../lib/i18n';

interface ProductPickerProps {
  value: ProductRef | null;
  onChange: (p: ProductRef | null) => void;
  placeholder?: string;
}

export default function ProductPicker({ value, onChange, placeholder }: ProductPickerProps) {
  const [query, setQuery] = useState('');
  const [open, setOpen] = useState(false);
  const wrapRef = useRef<HTMLDivElement>(null);

  const searchQuery = useQuery({
    queryKey: ['product-search', query],
    queryFn: () => productsApi.search(query),
    enabled: open,
    staleTime: 5000,
  });

  // Close on outside click
  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, []);

  const inputBase =
    'w-full px-3 py-2 border border-border rounded-md bg-white text-[13px] text-slate-900 ' +
    'focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary';

  return (
    <div ref={wrapRef} className="relative">
      {value ? (
        <div className={cn(inputBase, 'flex items-center justify-between gap-2 cursor-pointer')} onClick={() => { onChange(null); setOpen(true); }}>
          <div className="min-w-0 flex-1">
            <div className="font-semibold truncate">#{value.id_product} · {value.name}</div>
            <div className="text-[11px] text-muted-foreground truncate">
              {value.reference && `REF ${value.reference} · `}{formatPrice(value.price)}
            </div>
          </div>
          <button
            type="button"
            onClick={(e) => { e.stopPropagation(); onChange(null); }}
            className="text-muted-foreground hover:text-destructive text-[16px] leading-none px-1"
            title={t('product_picker.clear', 'Clear')}
          >
            ×
          </button>
        </div>
      ) : (
        <input
          className={inputBase}
          placeholder={placeholder || t('product_picker.search_placeholder', 'Search product by name, ID, or reference...')}
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          onFocus={() => setOpen(true)}
        />
      )}

      {open && !value && (
        <div className="absolute left-0 right-0 top-[calc(100%+4px)] z-20 bg-white border border-border rounded-md shadow-lg max-h-[300px] overflow-y-auto">
          {searchQuery.isLoading && (
            <div className="px-3 py-2 text-[12px] text-muted-foreground">{t('product_picker.searching', 'Searching…')}</div>
          )}
          {searchQuery.isError && (
            <div className="px-3 py-2 text-[12px] text-destructive">{t('product_picker.search_failed', 'Failed to search')}</div>
          )}
          {searchQuery.data && searchQuery.data.length === 0 && (
            <div className="px-3 py-2 text-[12px] text-muted-foreground">{t('product_picker.no_products', 'No products found')}</div>
          )}
          {searchQuery.data?.map((p) => (
            <button
              key={p.id_product}
              type="button"
              onClick={() => { onChange(p); setOpen(false); setQuery(''); }}
              className="w-full text-left px-3 py-2 hover:bg-muted border-b border-border last:border-b-0"
            >
              <div className="font-semibold text-[13px]">#{p.id_product} · {p.name}</div>
              <div className="text-[11px] text-muted-foreground">
                {p.reference && `REF ${p.reference} · `}{formatPrice(p.price)}
              </div>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
