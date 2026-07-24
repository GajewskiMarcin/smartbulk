<?php
/**
 * SmartBulk — FieldCatalog
 *
 * Central registry of filterable product fields + supported operators.
 * Used by the Condition Builder UI (as JSON over API) and by the SQL compiler.
 *
 * Each field has:
 *   - id            : stable identifier (e.g. 'name', 'brand', 'missing_meta_description')
 *   - label         : human-readable label for the dropdown
 *   - group         : 'basic'|'taxonomy'|'price_stock'|'content_gaps'|'behavior'|'date'
 *   - value_type    : 'text'|'int'|'float'|'bool'|'date'|'enum'|'related_many'|'gap'
 *   - lang          : whether this field has a per-language variant
 *   - options       : enum values (for value_type=enum)
 *   - operators     : list of allowed operator IDs
 *   - unit?         : 'currency'|'cm'|'kg' (display hint)
 *
 * Operators are grouped by value_type.  Every operator ID here is known to
 * ConditionTreeCompiler; adding a new operator requires adding it in both places.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Segment;

final class FieldCatalog
{
    // ---------- Operator sets per value type ----------

    public const TEXT_OPS = [
        'contains', 'not_contains',
        'starts_with', 'ends_with',
        'equals', 'not_equals',
        'matches_regex',
        'is_empty', 'is_not_empty',
        'length_lt', 'length_gt', 'length_eq', 'length_between',
    ];
    public const INT_OPS = [
        'eq', 'neq', 'lt', 'lte', 'gt', 'gte',
        'between', 'not_between',
        'is_null', 'is_not_null',
    ];
    public const FLOAT_OPS = self::INT_OPS;
    public const BOOL_OPS = ['is_true', 'is_false'];
    public const RELATED_OPS = ['contains_any', 'contains_all', 'contains_none', 'has_no_value'];
    public const ENUM_OPS = ['equals', 'not_equals', 'is_one_of', 'is_not_one_of'];
    public const DATE_OPS = [
        'before', 'after', 'on',
        'between', 'last_n_days', 'next_n_days',
        'is_null',
    ];
    public const GAP_OPS = ['is_true']; // content-gap pseudo-conditions evaluate as boolean

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        // Run labels through the module's $this->l() so they're picked up
        // by PrestaShop's translation scanner and resolved against translations/<iso>.php.
        $module = \Module::getInstanceByName('smartbulk');
        $l = static fn (string $s): string => $module ? $module->l($s, 'smartbulk') : $s;

        return [
            // ===== Basic =====
            self::field('id_product',    $l('ID'),                'basic', 'int',   self::INT_OPS),
            self::field('name',          $l('Product name'),      'basic', 'text',  self::TEXT_OPS, ['lang' => true]),
            self::field('reference',     $l('Reference (SKU)'),   'basic', 'text',  self::TEXT_OPS),
            self::field('active',        $l('Active'),            'basic', 'bool',  self::BOOL_OPS),
            self::field('on_sale',       $l('On sale flag'),      'basic', 'bool',  self::BOOL_OPS),
            self::field('available_for_order', $l('Available for order'), 'basic', 'bool', self::BOOL_OPS),
            self::field('visibility',    $l('Visibility'),        'basic', 'enum',  self::ENUM_OPS, [
                'options' => [
                    ['value' => 'both',    'label' => $l('Everywhere')],
                    ['value' => 'catalog', 'label' => $l('Catalog only')],
                    ['value' => 'search',  'label' => $l('Search only')],
                    ['value' => 'none',    'label' => $l('Nowhere')],
                ],
            ]),
            self::field('condition',     $l('Condition'),         'basic', 'enum',  self::ENUM_OPS, [
                'options' => [
                    ['value' => 'new',         'label' => $l('New')],
                    ['value' => 'used',        'label' => $l('Used')],
                    ['value' => 'refurbished', 'label' => $l('Refurbished')],
                ],
            ]),

            // ===== Taxonomy =====
            self::field('category',     $l('Category'),           'taxonomy', 'related_many', self::RELATED_OPS, ['lookup' => 'categories']),
            self::field('brand',        $l('Brand (manufacturer)'), 'taxonomy', 'related_many', self::RELATED_OPS, ['lookup' => 'brands']),
            self::field('supplier',     $l('Supplier'),           'taxonomy', 'related_many', self::RELATED_OPS, ['lookup' => 'suppliers']),
            self::field('feature',      $l('Feature'),            'taxonomy', 'related_many', self::RELATED_OPS, ['lookup' => 'features', 'pairs' => true]),
            self::field('tag',          $l('Tag'),                'taxonomy', 'related_many', self::RELATED_OPS, ['lookup' => 'tags']),
            self::field('attribute',    $l('Attribute (size, color, …)'), 'taxonomy', 'related_many', self::RELATED_OPS, ['lookup' => 'attributes']),

            // ===== Price & stock =====
            self::field('price',            $l('Price (tax excl.)'),   'price_stock', 'float', self::FLOAT_OPS, ['unit' => 'currency']),
            self::field('wholesale_price',  $l('Wholesale price'),     'price_stock', 'float', self::FLOAT_OPS, ['unit' => 'currency']),
            self::field('quantity',         $l('Stock quantity'),      'price_stock', 'int',   self::INT_OPS),
            self::field('low_stock_threshold', $l('Low stock threshold'), 'price_stock', 'int', self::INT_OPS),
            self::field('weight',           $l('Weight'),              'price_stock', 'float', self::FLOAT_OPS, ['unit' => 'kg']),
            self::field('available_now',     $l('Label when in-stock'),         'price_stock', 'text', self::TEXT_OPS, ['lang' => true]),
            self::field('available_later',   $l('Label when out of stock'),     'price_stock', 'text', self::TEXT_OPS, ['lang' => true]),
            self::field('delivery_in_stock', $l('Delivery time (in stock)'),    'price_stock', 'text', self::TEXT_OPS, ['lang' => true]),
            self::field('delivery_out_stock',$l('Delivery time (out of stock)'),'price_stock', 'text', self::TEXT_OPS, ['lang' => true]),
            self::field('low_stock_alert',   $l('Low-stock alert email'),       'price_stock', 'bool', self::BOOL_OPS),
            self::field('tax_rule',          $l('Tax rule'),                    'price_stock', 'related_many', self::RELATED_OPS, ['lookup' => 'tax_rules']),

            // ===== Content gaps =====
            // SEO
            self::field('missing_meta_title',       $l('Missing meta title'),         'content_gaps', 'gap', self::GAP_OPS, ['lang' => true]),
            self::field('missing_meta_description', $l('Missing meta description'),   'content_gaps', 'gap', self::GAP_OPS, ['lang' => true]),
            self::field('missing_focus_keyphrase',  $l('Missing focus keyphrase'),    'content_gaps', 'gap', self::GAP_OPS, ['lang' => true]),
            self::field('meta_title_length',        $l('Meta title length'),          'content_gaps', 'int', self::INT_OPS, ['lang' => true]),
            self::field('meta_description_length',  $l('Meta description length'),    'content_gaps', 'int', self::INT_OPS, ['lang' => true]),
            // Descriptions
            self::field('missing_short_desc',       $l('Missing short description'),  'content_gaps', 'gap', self::GAP_OPS, ['lang' => true]),
            self::field('missing_description',      $l('Missing long description'),   'content_gaps', 'gap', self::GAP_OPS, ['lang' => true]),
            self::field('short_desc_length',        $l('Short description length'),   'content_gaps', 'int', self::INT_OPS, ['lang' => true]),
            self::field('description_length',       $l('Long description length'),    'content_gaps', 'int', self::INT_OPS, ['lang' => true]),
            // Images
            self::field('missing_main_image',       $l('Missing main image'),         'content_gaps', 'gap', self::GAP_OPS),
            self::field('missing_any_image',        $l('No images at all'),           'content_gaps', 'gap', self::GAP_OPS),
            self::field('missing_alt_text',         $l('Images without alt text'),    'content_gaps', 'gap', self::GAP_OPS, ['lang' => true]),
            // Codes
            self::field('missing_reference',        $l('Missing reference (SKU)'),    'content_gaps', 'gap', self::GAP_OPS),
            self::field('missing_ean',              $l('Missing GTIN (EAN / JAN)'),   'content_gaps', 'gap', self::GAP_OPS),
            self::field('missing_upc',              $l('Missing UPC barcode'),        'content_gaps', 'gap', self::GAP_OPS),
            self::field('missing_mpn',              $l('Missing MPN'),                'content_gaps', 'gap', self::GAP_OPS),
            self::field('missing_isbn',             $l('Missing ISBN'),               'content_gaps', 'gap', self::GAP_OPS),
            // Relations
            self::field('missing_category',         $l('No category (only root)'),    'content_gaps', 'gap', self::GAP_OPS),
            self::field('missing_features',         $l('No features'),                'content_gaps', 'gap', self::GAP_OPS),
            self::field('missing_tags',             $l('No tags'),                    'content_gaps', 'gap', self::GAP_OPS, ['lang' => true]),
            self::field('missing_manufacturer',     $l('No brand / manufacturer'),    'content_gaps', 'gap', self::GAP_OPS),
            self::field('missing_supplier',         $l('No supplier'),                'content_gaps', 'gap', self::GAP_OPS),
            self::field('missing_combinations',     $l('No combinations'),            'content_gaps', 'gap', self::GAP_OPS),
            // Stock consistency
            self::field('active_no_stock',          $l('Active but out of stock'),    'content_gaps', 'gap', self::GAP_OPS),

            // ===== Behavior =====
            self::field('never_sold',               $l('Never sold'),                 'behavior', 'gap', self::GAP_OPS),
            self::field('sold_in_last_days',        $l('Sold in last N days'),        'behavior', 'int', ['eq','gte','lte','between']),
            self::field('not_sold_in_last_days',    $l('Not sold in last N days'),    'behavior', 'int', ['eq','gte','lte','between']),
            self::field('out_of_stock_days',        $l('Out of stock for ≥ N days'),  'behavior', 'int', ['gte','gt','eq']),
            self::field('sales_rank_top',           $l('Sales rank top N'),           'behavior', 'int', ['lte','lt']),
            self::field('sales_rank_bottom',        $l('Sales rank bottom N'),        'behavior', 'int', ['lte','lt']),

            // ===== Dates =====
            self::field('date_add',        $l('Date added'),     'date', 'date', self::DATE_OPS),
            self::field('date_upd',        $l('Date modified'),  'date', 'date', self::DATE_OPS),
            self::field('available_date',  $l('Availability date'), 'date', 'date', self::DATE_OPS),
        ];
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private static function field(
        string $id, string $label, string $group, string $valueType, array $operators, array $extra = []
    ): array {
        return array_merge([
            'id'         => $id,
            'label'      => $label,
            'group'      => $group,
            'value_type' => $valueType,
            'operators'  => $operators,
            'lang'       => false,
        ], $extra);
    }

    /** @return array<string,mixed>|null */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $f) {
            if ($f['id'] === $id) return $f;
        }
        return null;
    }

    /**
     * @return array<string,string>
     */
    public static function groupLabels(): array
    {
        $module = \Module::getInstanceByName('smartbulk');
        $l = static fn (string $s): string => $module ? $module->l($s, 'smartbulk') : $s;
        return [
            'basic'        => $l('Basic'),
            'taxonomy'     => $l('Taxonomy'),
            'price_stock'  => $l('Price & stock'),
            'content_gaps' => $l('Content gaps'),
            'behavior'     => $l('Behavior'),
            'date'         => $l('Dates'),
        ];
    }
}
