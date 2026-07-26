<?php
/**
 * SmartBulk — SegmentService
 *
 * Builds product queries from filter specs. Filters are combined with AND across
 * categories; multi-value within a filter = OR. Supported filter types:
 *   - category, brand, supplier, product_ids, tag, feature
 *   - content_gaps (missing_meta_title/missing_meta_description/missing_focus_keyphrase/
 *                   missing_main_image/missing_alt_text/short_desc_lt_N/desc_lt_N)
 *   - status (active / inactive / in_stock / out_of_stock)
 *   - price_between (min..max)
 *   - date_added_after_days
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Segment;

use Context;
use Db;

final class SegmentService
{
    public function __construct(
        private readonly ConditionTreeCompiler $tree = new ConditionTreeCompiler(),
    ) {
    }

    /**
     * @param array<string,mixed> $spec   Either the legacy `{filters:[]}` format OR
     *                                    the new tree format `{combine, conditions}`.
     * @return int Number of products matching.
     */
    public function count(array $spec, ?int $idShop = null, ?int $idLang = null): int
    {
        $ctx = $this->resolveContext($idShop, $idLang);
        [$sql, $where] = $this->buildQuery($spec, $ctx['id_shop'], $ctx['id_lang']);
        $count = Db::getInstance()->getValue(
            "SELECT COUNT(DISTINCT p.id_product) FROM {$sql} WHERE {$where}"
        );
        return (int) $count;
    }

    /**
     * @param array<string,mixed> $spec
     * @return int[] Product IDs.
     */
    public function listProductIds(array $spec, ?int $idShop = null, ?int $idLang = null, ?int $limit = null): array
    {
        $ctx = $this->resolveContext($idShop, $idLang);
        [$sql, $where] = $this->buildQuery($spec, $ctx['id_shop'], $ctx['id_lang']);
        $q = "SELECT DISTINCT p.id_product FROM {$sql} WHERE {$where} ORDER BY p.id_product ASC";
        if ($limit !== null && $limit > 0) {
            $q .= ' LIMIT ' . (int) $limit;
        }
        $rows = Db::getInstance()->executeS($q);
        return $rows ? array_map(static fn ($r) => (int) $r['id_product'], $rows) : [];
    }

    /**
     * Random sample of products (up to $limit) for "test on N samples" preview.
     * @param array<string,mixed> $spec
     * @return array<int,array<string,mixed>>  ['id_product','name','reference','price']
     */
    public function sample(array $spec, int $limit, ?int $idShop = null, ?int $idLang = null): array
    {
        $ctx = $this->resolveContext($idShop, $idLang);
        [$sql, $where] = $this->buildQuery($spec, $ctx['id_shop'], $ctx['id_lang']);
        $q = "SELECT DISTINCT p.id_product, pl.name, p.reference, p.price
              FROM {$sql} WHERE {$where}
              ORDER BY RAND() LIMIT " . max(1, $limit);
        $rows = Db::getInstance()->executeS($q);
        if (!$rows) {
            return [];
        }
        return array_map(static fn (array $r) => [
            'id_product' => (int) $r['id_product'],
            'name'       => (string) $r['name'],
            'reference'  => (string) $r['reference'],
            'price'      => (float) $r['price'],
        ], $rows);
    }

    /**
     * @param array<string,mixed> $spec
     * @return array{0:string,1:string}  [from_clause_with_joins, where_clause]
     */
    private function buildQuery(array $spec, int $idShop, int $idLang): array
    {
        $from = '`' . _DB_PREFIX_ . 'product` p'
            . ' JOIN `' . _DB_PREFIX_ . 'product_shop` ps'
            . '      ON ps.id_product = p.id_product AND ps.id_shop = ' . $idShop
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl'
            . '      ON pl.id_product = p.id_product AND pl.id_lang = ' . $idLang . ' AND pl.id_shop = ' . $idShop
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'stock_available` sa'
            . '      ON sa.id_product = p.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = ' . $idShop;

        // Tree format: { combine, conditions: [...] }
        if (isset($spec['conditions']) && is_array($spec['conditions'])) {
            $where = $this->tree->compile($spec, $idShop, $idLang);
            return [$from, $where !== '' ? $where : '1=1'];
        }

        // Legacy flat format: { filters: [...] } — kept for backward compat
        $filters = isset($spec['filters']) && is_array($spec['filters']) ? $spec['filters'] : [];
        $conditions = ['1=1'];

        foreach ($filters as $filter) {
            if (!is_array($filter) || !isset($filter['type'])) {
                continue;
            }
            $condition = $this->buildFilterCondition($filter, $idShop, $idLang);
            if ($condition !== null) {
                $conditions[] = $condition;
            }
        }

        return [$from, implode(' AND ', $conditions)];
    }

    /** @param array<string,mixed> $filter */
    private function buildFilterCondition(array $filter, int $idShop, int $idLang): ?string
    {
        $prefix = _DB_PREFIX_;
        $type = (string) $filter['type'];

        switch ($type) {
            case 'category': {
                $ids = $this->intIds($filter['ids'] ?? []);
                if (!$ids) return null;
                $in = implode(',', $ids);
                return "EXISTS (SELECT 1 FROM `{$prefix}category_product` cp
                                WHERE cp.id_product = p.id_product AND cp.id_category IN ({$in}))";
            }
            case 'brand': {
                $ids = $this->intIds($filter['ids'] ?? []);
                if (!$ids) return null;
                return 'p.id_manufacturer IN (' . implode(',', $ids) . ')';
            }
            case 'supplier': {
                $ids = $this->intIds($filter['ids'] ?? []);
                if (!$ids) return null;
                $in = implode(',', $ids);
                return "EXISTS (SELECT 1 FROM `{$prefix}product_supplier` psup
                                WHERE psup.id_product = p.id_product AND psup.id_supplier IN ({$in}))";
            }
            case 'product_ids': {
                $ids = $this->intIds($filter['ids'] ?? []);
                if (!$ids) return null;
                return 'p.id_product IN (' . implode(',', $ids) . ')';
            }
            case 'tag': {
                $ids = $this->intIds($filter['ids'] ?? []);
                if (!$ids) return null;
                $in = implode(',', $ids);
                return "EXISTS (SELECT 1 FROM `{$prefix}product_tag` pt
                                WHERE pt.id_product = p.id_product AND pt.id_tag IN ({$in}))";
            }
            case 'feature': {
                // Group pairs by id_feature: within one feature values are OR,
                // across different features AND. So "Euro ∈ {6,6.5} AND Compat = DAF XF".
                $pairs = is_array($filter['pairs'] ?? null) ? $filter['pairs'] : [];
                $byFeature = [];
                foreach ($pairs as $pair) {
                    $f = (int) ($pair['id_feature'] ?? 0);
                    $v = (int) ($pair['id_feature_value'] ?? 0);
                    if ($f > 0 && $v > 0) {
                        $byFeature[$f][$v] = $v;
                    }
                }
                if (!$byFeature) return null;
                $groupConds = [];
                foreach ($byFeature as $f => $values) {
                    $in = implode(',', array_map('intval', array_values($values)));
                    $groupConds[] = "EXISTS (SELECT 1 FROM `{$prefix}feature_product` fp
                                             WHERE fp.id_product = p.id_product
                                               AND fp.id_feature = {$f}
                                               AND fp.id_feature_value IN ({$in}))";
                }
                return '(' . implode(' AND ', $groupConds) . ')';
            }
            case 'content_gaps': {
                $values = $filter['value'] ?? [];
                if (!is_array($values)) $values = [$values];
                $gapLang = isset($filter['id_lang']) ? (int) $filter['id_lang'] : $idLang;
                return $this->contentGapCondition($values, $gapLang, $idShop);
            }
            case 'status': {
                $values = $filter['value'] ?? [];
                if (!is_array($values)) $values = [$values];
                $parts = [];
                foreach ($values as $v) {
                    switch ($v) {
                        case 'active':       $parts[] = 'ps.active = 1'; break;
                        case 'inactive':     $parts[] = 'ps.active = 0'; break;
                        case 'in_stock':     $parts[] = 'COALESCE(sa.quantity,0) > 0'; break;
                        case 'out_of_stock': $parts[] = 'COALESCE(sa.quantity,0) <= 0'; break;
                    }
                }
                if (!$parts) return null;
                // All selected status conditions must hold (AND)
                return '(' . implode(' AND ', $parts) . ')';
            }
            case 'price_between': {
                $min = isset($filter['min']) ? (float) $filter['min'] : null;
                $max = isset($filter['max']) ? (float) $filter['max'] : null;
                $parts = [];
                if ($min !== null) $parts[] = 'p.price >= ' . $min;
                if ($max !== null) $parts[] = 'p.price <= ' . $max;
                if (!$parts) return null;
                return '(' . implode(' AND ', $parts) . ')';
            }
            case 'date_added_after_days': {
                $days = (int) ($filter['value'] ?? 0);
                if ($days <= 0) return null;
                return 'p.date_add >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)';
            }
            default:
                return null;
        }
    }

    /** @param string[] $values */
    private function contentGapCondition(array $values, int $idLang, int $idShop): ?string
    {
        $prefix = _DB_PREFIX_;
        $parts = [];
        foreach ($values as $gap) {
            $gap = (string) $gap;
            if (strncmp($gap, 'short_desc_lt_', 14) === 0) {
                $n = (int) substr($gap, 14);
                if ($n > 0) $parts[] = "(COALESCE(CHAR_LENGTH(pl.description_short), 0) < {$n})";
                continue;
            }
            if (strncmp($gap, 'desc_lt_', 8) === 0) {
                $n = (int) substr($gap, 8);
                if ($n > 0) $parts[] = "(COALESCE(CHAR_LENGTH(pl.description), 0) < {$n})";
                continue;
            }
            switch ($gap) {
                case 'missing_meta_title':
                    $parts[] = "(pl.meta_title IS NULL OR pl.meta_title = '')";
                    break;
                case 'missing_meta_description':
                    $parts[] = "(pl.meta_description IS NULL OR pl.meta_description = '')";
                    break;
                case 'missing_short_desc':
                    $parts[] = "(pl.description_short IS NULL OR pl.description_short = '')";
                    break;
                case 'missing_description':
                    $parts[] = "(pl.description IS NULL OR pl.description = '')";
                    break;
                case 'missing_main_image':
                    $parts[] = "NOT EXISTS (SELECT 1 FROM `{$prefix}image` i
                                            JOIN `{$prefix}image_shop` ish ON ish.id_image = i.id_image
                                            WHERE i.id_product = p.id_product
                                              AND ish.id_shop = {$idShop}
                                              AND ish.cover = 1)";
                    break;
                case 'missing_alt_text':
                    $parts[] = "EXISTS (SELECT 1 FROM `{$prefix}image` i
                                        LEFT JOIN `{$prefix}image_lang` il
                                             ON il.id_image = i.id_image AND il.id_lang = {$idLang}
                                        WHERE i.id_product = p.id_product
                                          AND (il.legend IS NULL OR il.legend = ''))";
                    break;
                case 'missing_ean':
                    $parts[] = "(p.ean13 IS NULL OR p.ean13 = '')";
                    break;
                case 'missing_reference':
                    $parts[] = "(p.reference IS NULL OR p.reference = '')";
                    break;
                case 'missing_isbn':
                    $parts[] = "(p.isbn IS NULL OR p.isbn = '')";
                    break;
                case 'missing_mpn':
                    $parts[] = "(p.mpn IS NULL OR p.mpn = '')";
                    break;
                case 'missing_upc':
                    $parts[] = "(p.upc IS NULL OR p.upc = '')";
                    break;
                case 'never_sold':
                    $parts[] = "NOT EXISTS (SELECT 1 FROM `{$prefix}order_detail` od
                                            WHERE od.product_id = p.id_product)";
                    break;
            }
        }
        if (!$parts) return null;
        // Multiple gaps within a single filter = OR
        return '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * @param mixed $raw
     * @return int[]
     */
    private function intIds($raw): array
    {
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $v) {
            $n = (int) $v;
            if ($n > 0) $out[] = $n;
        }
        return array_values(array_unique($out));
    }

    /** @return array{id_shop:int,id_lang:int} */
    private function resolveContext(?int $idShop, ?int $idLang): array
    {
        $ctx = Context::getContext();
        return [
            'id_shop' => $idShop ?? (int) $ctx->shop->id,
            'id_lang' => $idLang ?? (int) $ctx->language->id,
        ];
    }
}
