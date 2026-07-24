import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import Button from '../Button';
import { segmentsApi } from '../../lib/segments';
import type { TreeSpec } from '../../lib/conditions';
import { t } from '../../lib/i18n';
import { formatPrice } from '../../lib/utils';

interface Props {
  tree: TreeSpec;
}

/**
 * Sticky live preview of products matching the current condition tree.
 * Shows total count and a sample of 5 products.
 */
export default function MatchingProducts({ tree }: Props) {
  // Cheap debounce via query key + enabled flag.
  const spec = useMemo(() => tree as unknown as { conditions: unknown[] }, [tree]);

  const countQuery = useQuery({
    queryKey: ['matching-count', spec],
    queryFn: () => segmentsApi.count(spec as never),
  });

  const sampleQuery = useQuery({
    queryKey: ['matching-sample', spec],
    queryFn: () => segmentsApi.sample(spec as never, 5),
  });

  const count = countQuery.data ?? 0;
  const sample = sampleQuery.data ?? [];

  return (
    <div className="bg-white border border-border rounded-lg p-4 sticky top-4">
      <div className="flex items-center justify-between mb-2">
        <div>
          <div className="font-semibold text-[13px]">{t('match.title', 'Matching products')}</div>
          <div className="text-[12px] text-muted-foreground">
            {countQuery.isFetching ? (
              <span>{t('match.counting', 'Counting…')}</span>
            ) : (
              <>
                <strong className="text-primary text-[16px]">{count.toLocaleString()}</strong>
                {' '}{t('match.matching', 'matching')}
              </>
            )}
          </div>
        </div>
        <Button
          size="sm"
          variant="ghost"
          onClick={() => {
            countQuery.refetch();
            sampleQuery.refetch();
          }}
        >
          ↻
        </Button>
      </div>

      <div className="divide-y divide-border">
        {sampleQuery.isLoading && (
          <div className="text-[12px] text-muted-foreground py-2">{t('match.loading_sample', 'Loading sample…')}</div>
        )}
        {sample.length === 0 && !sampleQuery.isLoading && (
          <div className="text-[12px] text-muted-foreground py-2">{t('match.no_match', 'No products match yet.')}</div>
        )}
        {sample.map((p) => (
          <div key={p.id_product} className="flex items-start gap-2 py-2 text-[12px]">
            <div className="w-9 h-9 bg-muted rounded flex-shrink-0" />
            <div className="min-w-0 flex-1">
              <div className="font-semibold truncate">{p.name}</div>
              <div className="text-muted-foreground truncate">
                #{p.id_product}
                {p.reference ? ` · REF ${p.reference}` : ''}
                {' · '}
                {formatPrice(p.price)}
              </div>
            </div>
          </div>
        ))}
      </div>

      <div className="text-[10px] text-muted-foreground mt-2 pt-2 border-t border-border">
        {t('match.live_count_hint', 'Live count updates as you edit conditions.')}
      </div>
    </div>
  );
}
