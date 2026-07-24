<?php
/**
 * SmartBulk — ConditionTreeCompiler
 *
 * Compiles a recursive condition tree (groups + conditions, nested AND/OR)
 * into a single SQL WHERE fragment. Returns a string to be AND-ed with the
 * base product query built by SegmentService.
 *
 * Tree shape:
 *   {
 *     "combine": "and" | "or",
 *     "conditions": [
 *       { "kind": "cond", "field": "name", "op": "contains", "value": "foo", "id_lang": 1 },
 *       { "kind": "group", "combine": "or", "conditions": [ ... ] }
 *     ]
 *   }
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Segment;

final class ConditionTreeCompiler
{
    /**
     * @param array<string,mixed> $tree
     */
    public function compile(array $tree, int $idShop, int $idLang): string
    {
        $sql = $this->compileNode($tree, $idShop, $idLang);
        return $sql !== '' ? $sql : '1=1';
    }

    /** @param array<string,mixed> $node */
    private function compileNode(array $node, int $idShop, int $idLang): string
    {
        // Group node
        if (($node['kind'] ?? 'group') === 'group' || isset($node['conditions'])) {
            $combine = strtoupper((string) ($node['combine'] ?? 'and')) === 'OR' ? 'OR' : 'AND';
            $conditions = is_array($node['conditions'] ?? null) ? $node['conditions'] : [];
            $parts = [];
            foreach ($conditions as $child) {
                if (!is_array($child)) continue;
                $frag = $this->compileNode($child, $idShop, $idLang);
                if ($frag !== '') $parts[] = $frag;
            }
            if (empty($parts)) return '';
            return '(' . implode(" {$combine} ", $parts) . ')';
        }

        // Leaf: cond
        $field    = (string) ($node['field'] ?? '');
        $op       = (string) ($node['op'] ?? '');
        $value    = $node['value'] ?? null;
        $value2   = $node['value2'] ?? null;
        $condLang = isset($node['id_lang']) ? (int) $node['id_lang'] : $idLang;

        $def = FieldCatalog::find($field);
        if ($def === null) return '';

        return $this->compileCondition($def, $op, $value, $value2, $idShop, $condLang);
    }

    /**
     * @param array<string,mixed> $def
     * @param mixed $value
     * @param mixed $value2
     */
    private function compileCondition(array $def, string $op, $value, $value2, int $idShop, int $idLang): string
    {
        $prefix = _DB_PREFIX_;
        $id = (string) $def['id'];

        // --- GAP pseudo-conditions (evaluate as boolean "is_true") ---
        if ($def['value_type'] === 'gap' && $op === 'is_true') {
            return $this->gapFragment($id, $idShop, $idLang);
        }

        // --- Numeric length operators on text fields (length_*) ---
        if ($def['value_type'] === 'text' && str_starts_with($op, 'length_')) {
            $col = $this->resolveColumn($id, $idLang, $idShop);
            $lenExpr = "COALESCE(CHAR_LENGTH({$col}), 0)";
            return $this->numericExpr($lenExpr, $op, $value, $value2);
        }

        // --- Numeric operators on *_length gap-int fields (e.g. meta_title_length) ---
        if ($def['value_type'] === 'int' && str_ends_with($id, '_length')) {
            $base = substr($id, 0, -7); // meta_title_length → meta_title
            $col = $this->resolveColumn($base, $idLang, $idShop);
            $lenExpr = "COALESCE(CHAR_LENGTH({$col}), 0)";
            return $this->numericExpr($lenExpr, $op, $value, $value2);
        }

        // --- Related many (categories, brands, suppliers, tags, features, attributes) ---
        if ($def['value_type'] === 'related_many') {
            return $this->relatedExpr($id, $op, $value, $idShop, $idLang);
        }

        // --- Text ops on a concrete text column ---
        if ($def['value_type'] === 'text') {
            $col = $this->resolveColumn($id, $idLang, $idShop);
            return $this->textExpr($col, $op, $value);
        }

        // --- Numeric ops on product / product_shop / stock columns ---
        if ($def['value_type'] === 'float' || $def['value_type'] === 'int') {
            // Special cases for behavior-int fields
            if (in_array($id, ['sold_in_last_days', 'not_sold_in_last_days', 'out_of_stock_days', 'sales_rank_top', 'sales_rank_bottom'], true)) {
                return $this->behaviorExpr($id, $op, $value, $value2);
            }
            $col = $this->resolveColumn($id, $idLang, $idShop);
            return $this->numericExpr($col, $op, $value, $value2);
        }

        // --- Bool ---
        if ($def['value_type'] === 'bool') {
            $col = $this->resolveColumn($id, $idLang, $idShop);
            if ($op === 'is_true') return "{$col} = 1";
            if ($op === 'is_false') return "{$col} = 0";
            return '';
        }

        // --- Enum ---
        if ($def['value_type'] === 'enum') {
            $col = $this->resolveColumn($id, $idLang, $idShop);
            if ($op === 'equals' || $op === 'is_one_of') {
                $vals = is_array($value) ? $value : [$value];
                $vals = array_map(static fn ($v) => "'" . pSQL((string) $v) . "'", $vals);
                return count($vals) === 1 ? "{$col} = {$vals[0]}" : "{$col} IN (" . implode(',', $vals) . ')';
            }
            if ($op === 'not_equals' || $op === 'is_not_one_of') {
                $vals = is_array($value) ? $value : [$value];
                $vals = array_map(static fn ($v) => "'" . pSQL((string) $v) . "'", $vals);
                return count($vals) === 1 ? "{$col} <> {$vals[0]}" : "{$col} NOT IN (" . implode(',', $vals) . ')';
            }
            return '';
        }

        // --- Date ---
        if ($def['value_type'] === 'date') {
            $col = $this->resolveColumn($id, $idLang, $idShop);
            return $this->dateExpr($col, $op, $value, $value2);
        }

        return '';
    }

    // -------------- Column resolution (which table + alias) --------------

    private function resolveColumn(string $id, int $idLang, int $idShop): string
    {
        // product_lang
        if (in_array($id, [
            'name', 'description', 'description_short',
            'meta_title', 'meta_description', 'meta_keywords', 'link_rewrite',
            'available_now', 'available_later',
            'delivery_in_stock', 'delivery_out_stock',
        ], true)) {
            return "pl.`{$id}`";
        }
        // product_shop
        if (in_array($id, [
            'active', 'price', 'wholesale_price', 'visibility', 'minimal_quantity',
            'on_sale', 'available_for_order', 'show_price', 'online_only',
        ], true)) {
            return "ps.`{$id}`";
        }
        // stock
        if ($id === 'quantity') {
            return 'COALESCE(sa.quantity, 0)';
        }
        // default product.*
        return "p.`{$id}`";
    }

    // -------------- Operator renderers --------------

    /** @param mixed $value */
    private function textExpr(string $col, string $op, $value): string
    {
        $val = (string) ($value ?? '');
        $esc = pSQL($val);
        switch ($op) {
            case 'equals':        return "{$col} = '{$esc}'";
            case 'not_equals':    return "{$col} <> '{$esc}'";
            case 'contains':      return "{$col} LIKE '%{$esc}%'";
            case 'not_contains':  return "{$col} NOT LIKE '%{$esc}%'";
            case 'starts_with':   return "{$col} LIKE '{$esc}%'";
            case 'ends_with':     return "{$col} LIKE '%{$esc}'";
            case 'matches_regex': return "{$col} REGEXP '{$esc}'";
            case 'is_empty':      return "({$col} IS NULL OR {$col} = '')";
            case 'is_not_empty':  return "({$col} IS NOT NULL AND {$col} <> '')";
        }
        return '';
    }

    /** @param mixed $value @param mixed $value2 */
    private function numericExpr(string $expr, string $op, $value, $value2): string
    {
        $n = is_numeric($value) ? (float) $value : 0;
        switch ($op) {
            case 'eq':  return "{$expr} = {$n}";
            case 'neq': return "{$expr} <> {$n}";
            case 'lt':  case 'length_lt':       return "{$expr} < {$n}";
            case 'lte':                         return "{$expr} <= {$n}";
            case 'gt':  case 'length_gt':       return "{$expr} > {$n}";
            case 'gte':                         return "{$expr} >= {$n}";
            case 'length_eq':                   return "{$expr} = {$n}";
            case 'between':
            case 'length_between': {
                $n2 = is_numeric($value2) ? (float) $value2 : $n;
                $min = min($n, $n2); $max = max($n, $n2);
                return "{$expr} BETWEEN {$min} AND {$max}";
            }
            case 'not_between': {
                $n2 = is_numeric($value2) ? (float) $value2 : $n;
                $min = min($n, $n2); $max = max($n, $n2);
                return "({$expr} < {$min} OR {$expr} > {$max})";
            }
            case 'is_null':     return "{$expr} IS NULL";
            case 'is_not_null': return "{$expr} IS NOT NULL";
        }
        return '';
    }

    /** @param mixed $value @param mixed $value2 */
    private function dateExpr(string $col, string $op, $value, $value2): string
    {
        $v = pSQL((string) ($value ?? ''));
        switch ($op) {
            case 'before':       return "{$col} < '{$v}'";
            case 'after':        return "{$col} > '{$v}'";
            case 'on':           return "DATE({$col}) = '{$v}'";
            case 'between':
                $v2 = pSQL((string) ($value2 ?? $value));
                return "{$col} BETWEEN '{$v}' AND '{$v2}'";
            case 'last_n_days': {
                $n = max(0, (int) $value);
                return "{$col} >= DATE_SUB(NOW(), INTERVAL {$n} DAY)";
            }
            case 'next_n_days': {
                $n = max(0, (int) $value);
                return "{$col} <= DATE_ADD(NOW(), INTERVAL {$n} DAY) AND {$col} >= NOW()";
            }
            case 'is_null': return "{$col} IS NULL";
        }
        return '';
    }

    /** @param mixed $value */
    private function relatedExpr(string $field, string $op, $value, int $idShop, int $idLang): string
    {
        $prefix = _DB_PREFIX_;
        $ids = $this->intIds($value);

        switch ($field) {
            case 'category':
                return $this->relatedCountExpr(
                    "`{$prefix}category_product` cp WHERE cp.id_product = p.id_product AND cp.id_category IN (%s)",
                    $op, $ids
                );
            case 'brand':
                // Single column on product
                if ($op === 'has_no_value') return 'p.id_manufacturer = 0';
                if (!$ids) return '';
                if ($op === 'contains_none') return 'p.id_manufacturer NOT IN (' . implode(',', $ids) . ')';
                return 'p.id_manufacturer IN (' . implode(',', $ids) . ')';
            case 'tax_rule':
                // Single column on product (id_tax_rules_group). 0 = "No tax".
                if ($op === 'has_no_value') return 'p.id_tax_rules_group = 0';
                if (!$ids) return '';
                if ($op === 'contains_none') return 'p.id_tax_rules_group NOT IN (' . implode(',', $ids) . ')';
                return 'p.id_tax_rules_group IN (' . implode(',', $ids) . ')';
            case 'supplier':
                return $this->relatedCountExpr(
                    "`{$prefix}product_supplier` psup WHERE psup.id_product = p.id_product AND psup.id_supplier IN (%s)",
                    $op, $ids
                );
            case 'tag':
                return $this->relatedCountExpr(
                    "`{$prefix}product_tag` pt WHERE pt.id_product = p.id_product AND pt.id_tag IN (%s)",
                    $op, $ids
                );
            case 'attribute':
                // Through product_attribute + product_attribute_combination
                return $this->relatedCountExpr(
                    "`{$prefix}product_attribute` pa
                     JOIN `{$prefix}product_attribute_combination` pac ON pac.id_product_attribute = pa.id_product_attribute
                     WHERE pa.id_product = p.id_product AND pac.id_attribute IN (%s)",
                    $op, $ids
                );
            case 'feature': {
                // Value shape: array of { id_feature, id_feature_value }
                $pairs = is_array($value) ? $value : [];
                $byFeature = [];
                foreach ($pairs as $pair) {
                    if (is_array($pair)) {
                        $f = (int) ($pair['id_feature'] ?? 0);
                        $v = (int) ($pair['id_feature_value'] ?? 0);
                        if ($f > 0 && $v > 0) $byFeature[$f][$v] = $v;
                    }
                }
                if (empty($byFeature)) {
                    return $op === 'has_no_value'
                        ? "NOT EXISTS (SELECT 1 FROM `{$prefix}feature_product` fp0 WHERE fp0.id_product = p.id_product)"
                        : '';
                }
                $parts = [];
                foreach ($byFeature as $f => $vs) {
                    $in = implode(',', array_values($vs));
                    if ($op === 'contains_all') {
                        // Each (f, vX) must exist — express as AND of EXISTS per value
                        foreach ($vs as $vv) {
                            $parts[] = "EXISTS (SELECT 1 FROM `{$prefix}feature_product` fp
                                                 WHERE fp.id_product = p.id_product
                                                   AND fp.id_feature = {$f}
                                                   AND fp.id_feature_value = {$vv})";
                        }
                    } elseif ($op === 'contains_none') {
                        $parts[] = "NOT EXISTS (SELECT 1 FROM `{$prefix}feature_product` fp
                                                 WHERE fp.id_product = p.id_product
                                                   AND fp.id_feature = {$f}
                                                   AND fp.id_feature_value IN ({$in}))";
                    } else {
                        // contains_any (default): any of the values for this feature
                        $parts[] = "EXISTS (SELECT 1 FROM `{$prefix}feature_product` fp
                                             WHERE fp.id_product = p.id_product
                                               AND fp.id_feature = {$f}
                                               AND fp.id_feature_value IN ({$in}))";
                    }
                }
                return '(' . implode(' AND ', $parts) . ')';
            }
        }
        return '';
    }

    /**
     * Renders a related-many operator using a shared EXISTS/NOT EXISTS pattern.
     * $template must be an SQL fragment with one `%s` placeholder for the IN-list.
     * @param int[] $ids
     */
    private function relatedCountExpr(string $template, string $op, array $ids): string
    {
        if ($op === 'has_no_value') {
            $tableOnly = preg_replace('/ AND [^ ]+ IN \(%s\).*$/', '', $template) ?? '';
            // build minimal EXISTS with no IN clause
            $noFilter = preg_replace('/ AND cp\.id_category IN.*$/', '', $template) ?? '';
            return 'NOT EXISTS (SELECT 1 FROM ' . preg_replace('/ AND.*IN\s*\(%s\).*$/', '', $template) . ')';
        }
        if (!$ids) return '';
        $in = implode(',', $ids);
        $exists = sprintf($template, $in);

        switch ($op) {
            case 'contains_any':
                return "EXISTS (SELECT 1 FROM {$exists})";
            case 'contains_none':
                return "NOT EXISTS (SELECT 1 FROM {$exists})";
            case 'contains_all': {
                // AND of N single-value EXISTS
                $parts = [];
                foreach ($ids as $id) {
                    $parts[] = 'EXISTS (SELECT 1 FROM ' . sprintf($template, (string) $id) . ')';
                }
                return '(' . implode(' AND ', $parts) . ')';
            }
        }
        return '';
    }

    // -------------- Content gap pseudo-conditions --------------

    private function gapFragment(string $id, int $idShop, int $idLang): string
    {
        $prefix = _DB_PREFIX_;
        switch ($id) {
            case 'missing_meta_title':
                return "(pl.meta_title IS NULL OR pl.meta_title = '')";
            case 'missing_meta_description':
                return "(pl.meta_description IS NULL OR pl.meta_description = '')";
            case 'missing_focus_keyphrase':
                return "(pl.meta_keywords IS NULL OR pl.meta_keywords = '')";
            case 'missing_short_desc':
                return "(pl.description_short IS NULL OR pl.description_short = '')";
            case 'missing_description':
                return "(pl.description IS NULL OR pl.description = '')";
            case 'missing_main_image':
                return "NOT EXISTS (SELECT 1 FROM `{$prefix}image` i
                                    JOIN `{$prefix}image_shop` ish ON ish.id_image = i.id_image
                                    WHERE i.id_product = p.id_product AND ish.id_shop = {$idShop} AND ish.cover = 1)";
            case 'missing_any_image':
                return "NOT EXISTS (SELECT 1 FROM `{$prefix}image` i WHERE i.id_product = p.id_product)";
            case 'missing_alt_text':
                return "EXISTS (SELECT 1 FROM `{$prefix}image` i
                                LEFT JOIN `{$prefix}image_lang` il ON il.id_image = i.id_image AND il.id_lang = {$idLang}
                                WHERE i.id_product = p.id_product AND (il.legend IS NULL OR il.legend = ''))";
            case 'missing_reference':
                return "(p.reference IS NULL OR p.reference = '')";
            case 'missing_ean':
                return "(p.ean13 IS NULL OR p.ean13 = '')";
            case 'missing_upc':
                return "(p.upc IS NULL OR p.upc = '')";
            case 'missing_mpn':
                return "(p.mpn IS NULL OR p.mpn = '')";
            case 'missing_isbn':
                return "(p.isbn IS NULL OR p.isbn = '')";
            case 'missing_category':
                return "NOT EXISTS (SELECT 1 FROM `{$prefix}category_product` cp
                                    WHERE cp.id_product = p.id_product AND cp.id_category > 2)";
            case 'missing_features':
                return "NOT EXISTS (SELECT 1 FROM `{$prefix}feature_product` fp WHERE fp.id_product = p.id_product)";
            case 'missing_tags':
                return "NOT EXISTS (SELECT 1 FROM `{$prefix}product_tag` pt WHERE pt.id_product = p.id_product)";
            case 'missing_manufacturer':
                return 'p.id_manufacturer = 0';
            case 'missing_supplier':
                return "NOT EXISTS (SELECT 1 FROM `{$prefix}product_supplier` psup WHERE psup.id_product = p.id_product)";
            case 'missing_combinations':
                return "NOT EXISTS (SELECT 1 FROM `{$prefix}product_attribute` pa WHERE pa.id_product = p.id_product)";
            case 'active_no_stock':
                return 'ps.active = 1 AND COALESCE(sa.quantity, 0) <= 0';
            case 'never_sold':
                return "NOT EXISTS (SELECT 1 FROM `{$prefix}order_detail` od WHERE od.product_id = p.id_product)";
        }
        return '';
    }

    // -------------- Behavior expressions --------------

    /** @param mixed $value @param mixed $value2 */
    private function behaviorExpr(string $id, string $op, $value, $value2): string
    {
        $prefix = _DB_PREFIX_;
        $n = max(0, (int) $value);

        switch ($id) {
            case 'sold_in_last_days':
                return "EXISTS (SELECT 1 FROM `{$prefix}order_detail` od
                                JOIN `{$prefix}orders` o ON o.id_order = od.id_order
                                WHERE od.product_id = p.id_product
                                  AND o.date_add >= DATE_SUB(NOW(), INTERVAL {$n} DAY))";
            case 'not_sold_in_last_days':
                return "NOT EXISTS (SELECT 1 FROM `{$prefix}order_detail` od
                                    JOIN `{$prefix}orders` o ON o.id_order = od.id_order
                                    WHERE od.product_id = p.id_product
                                      AND o.date_add >= DATE_SUB(NOW(), INTERVAL {$n} DAY))";
            case 'out_of_stock_days':
                // Approximation: product currently has 0 stock AND date_upd of stock < N days ago
                return "(COALESCE(sa.quantity, 0) <= 0
                         AND sa.update_date IS NOT NULL
                         AND sa.update_date < DATE_SUB(NOW(), INTERVAL {$n} DAY))";
            case 'sales_rank_top':
            case 'sales_rank_bottom': {
                // Aggregate units sold, rank desc/asc within window of all products
                $order = $id === 'sales_rank_top' ? 'DESC' : 'ASC';
                return "p.id_product IN (
                    SELECT id_product FROM (
                        SELECT od.product_id AS id_product, COALESCE(SUM(od.product_quantity), 0) AS qty
                        FROM `{$prefix}order_detail` od
                        GROUP BY od.product_id
                        ORDER BY qty {$order}
                        LIMIT {$n}
                    ) AS rnk
                )";
            }
        }
        return '';
    }

    /** @param mixed $raw @return int[] */
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
}
