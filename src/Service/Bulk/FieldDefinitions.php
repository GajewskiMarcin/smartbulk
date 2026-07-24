<?php
/**
 * SmartBulk — Bulk editable field catalogue
 *
 * Metadata for all product fields the Bulk Editor can operate on.
 * Each field declares: type, multi-lang flag, supported operators, unit hint,
 * and optional enum options.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Bulk;

final class FieldDefinitions
{
    public const TEXT_OPERATORS    = ['set', 'append', 'prepend', 'replace', 'copy_from', 'clear', 'skip'];
    public const AI_TEXT_OPERATORS = ['set', 'append', 'prepend', 'replace', 'copy_from', 'clear', 'ai_generate', 'skip'];
    public const NUMERIC_OPERATORS = ['set', 'math', 'copy_from', 'clear', 'skip'];
    public const INT_OPERATORS     = ['set', 'math', 'copy_from', 'skip'];
    public const BOOL_OPERATORS    = ['set', 'skip'];
    public const ENUM_OPERATORS    = ['set', 'skip'];

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array
    {
        // Run labels through the module's $this->l() so they're picked up
        // by PrestaShop's translation scanner and resolved against translations/<iso>.php.
        $module = \Module::getInstanceByName('smartbulk');
        $l = static fn (string $s): string => $module ? $module->l($s, 'smartbulk') : $s;

        return [
            // ----- Basic -----
            ['id' => 'name',                 'label' => $l('Product name'),        'group' => 'basic',    'type' => 'text',    'lang' => true,  'operators' => self::AI_TEXT_OPERATORS],
            ['id' => 'reference',            'label' => $l('Reference (SKU)'),     'group' => 'basic',    'type' => 'text',    'lang' => false, 'operators' => self::TEXT_OPERATORS,
                'warn_same_value_on_batch' => true, // set=X on many products → duplicate SKUs
            ],
            // Relation fields — rendered as lookup dropdowns in UI
            ['id' => 'id_manufacturer',      'label' => $l('Brand (manufacturer)'), 'group' => 'basic',   'type' => 'enum', 'lang' => false, 'operators' => ['set', 'skip'], 'lookup' => 'brands'],
            ['id' => 'id_supplier',          'label' => $l('Default supplier'),     'group' => 'basic',   'type' => 'enum', 'lang' => false, 'operators' => ['set', 'skip'], 'lookup' => 'suppliers'],
            ['id' => 'id_category_default',  'label' => $l('Default category'),     'group' => 'basic',   'type' => 'enum', 'lang' => false, 'operators' => ['set', 'skip'], 'lookup' => 'categories'],
            ['id' => 'id_tax_rules_group',   'label' => $l('Tax rule'),             'group' => 'pricing', 'type' => 'enum', 'lang' => false, 'operators' => ['set', 'skip'], 'lookup' => 'tax_rules'],
            ['id' => 'active',               'label' => $l('Active'),              'group' => 'basic',    'type' => 'bool',    'lang' => false, 'operators' => self::BOOL_OPERATORS,
                'warn_on_false' => 'Products will be deactivated — orders will be denied.',
            ],
            ['id' => 'available_for_order',  'label' => $l('Available for order'), 'group' => 'basic',    'type' => 'bool',    'lang' => false, 'operators' => self::BOOL_OPERATORS],
            ['id' => 'show_price',           'label' => $l('Show price'),          'group' => 'basic',    'type' => 'bool',    'lang' => false, 'operators' => self::BOOL_OPERATORS],
            ['id' => 'online_only',          'label' => $l('Online only'),         'group' => 'basic',    'type' => 'bool',    'lang' => false, 'operators' => self::BOOL_OPERATORS],
            ['id' => 'visibility',           'label' => $l('Visibility'),          'group' => 'basic',    'type' => 'enum',    'lang' => false, 'operators' => self::ENUM_OPERATORS,
                'options' => [
                    ['value' => 'both',    'label' => $l('Everywhere')],
                    ['value' => 'catalog', 'label' => $l('Catalog only')],
                    ['value' => 'search',  'label' => $l('Search only')],
                    ['value' => 'none',    'label' => $l('Nowhere')],
                ],
                'warn_on_value' => [
                    'none' => 'Products will become invisible to customers (hidden from catalog and search).',
                ]],
            ['id' => 'condition',            'label' => $l('Condition'),           'group' => 'basic',    'type' => 'enum',    'lang' => false, 'operators' => self::ENUM_OPERATORS,
                'options' => [
                    ['value' => 'new',         'label' => $l('New')],
                    ['value' => 'used',        'label' => $l('Used')],
                    ['value' => 'refurbished', 'label' => $l('Refurbished')],
                ]],
            ['id' => 'on_sale',              'label' => $l('Display "On sale!" flag'), 'group' => 'basic', 'type' => 'bool',   'lang' => false, 'operators' => self::BOOL_OPERATORS],

            // ----- Descriptions -----
            ['id' => 'description_short',    'label' => $l('Short description'),   'group' => 'content', 'type' => 'text',    'lang' => true,  'operators' => self::AI_TEXT_OPERATORS, 'html' => true],
            ['id' => 'description',          'label' => $l('Long description'),    'group' => 'content', 'type' => 'text',    'lang' => true,  'operators' => self::AI_TEXT_OPERATORS, 'html' => true],

            // ----- SEO -----
            ['id' => 'meta_title',           'label' => $l('Meta title'),          'group' => 'seo',     'type' => 'text',    'lang' => true,
                'operators' => ['set', 'append', 'prepend', 'replace', 'copy_from', 'clear', 'generate_from_name', 'ai_generate', 'skip'],
                'char_limit' => 60,
                'char_limit_warn' => 55,
            ],
            ['id' => 'meta_description',     'label' => $l('Meta description'),    'group' => 'seo',     'type' => 'text',    'lang' => true,
                'operators' => ['set', 'append', 'prepend', 'replace', 'copy_from', 'clear', 'generate_from_short_desc', 'ai_generate', 'skip'],
                'char_limit' => 160,
                'char_limit_warn' => 150,
            ],
            // link_rewrite: unique-per-lang constraint means set/clear are unsafe on
            // batches. Allowed ops: generate_from_name (slugify name), replace (find+replace),
            // append/prepend, or skip. "Set to value" is intentionally excluded.
            ['id' => 'link_rewrite',         'label' => $l('Friendly URL'),        'group' => 'seo',     'type' => 'text',    'lang' => true,  'operators' => ['generate_from_name', 'replace', 'append', 'prepend', 'skip']],

            // ----- Pricing -----
            ['id' => 'price',                'label' => $l('Price (tax excl.)'),   'group' => 'pricing', 'type' => 'numeric', 'lang' => false, 'operators' => self::NUMERIC_OPERATORS, 'unit' => 'currency', 'min_value' => 0],
            ['id' => 'wholesale_price',      'label' => $l('Cost price (tax excl.)'), 'group' => 'pricing', 'type' => 'numeric', 'lang' => false, 'operators' => self::NUMERIC_OPERATORS, 'unit' => 'currency', 'min_value' => 0],
            ['id' => 'unit_price_ratio',     'label' => $l('Unit price ratio'),    'group' => 'pricing', 'type' => 'numeric', 'lang' => false, 'operators' => self::NUMERIC_OPERATORS, 'min_value' => 0],
            ['id' => 'ecotax',               'label' => $l('Ecotax'),              'group' => 'pricing', 'type' => 'numeric', 'lang' => false, 'operators' => self::NUMERIC_OPERATORS, 'unit' => 'currency', 'min_value' => 0],

            // ----- Stock -----
            ['id' => 'quantity',             'label' => $l('Stock quantity'),      'group' => 'stock',   'type' => 'int',     'lang' => false, 'operators' => self::INT_OPERATORS, 'min_value' => 0],
            ['id' => 'minimal_quantity',     'label' => $l('Min. qty for sale'),   'group' => 'stock',   'type' => 'int',     'lang' => false, 'operators' => self::INT_OPERATORS, 'min_value' => 1,
                'warn_if_greater_than' => ['field' => 'quantity', 'message' => 'Min. qty higher than current stock — product will be unavailable.'],
            ],
            ['id' => 'low_stock_threshold',  'label' => $l('Low stock threshold'), 'group' => 'stock',   'type' => 'int',     'lang' => false, 'operators' => self::INT_OPERATORS, 'min_value' => 0],
            ['id' => 'low_stock_alert',      'label' => $l('Enable low-stock alert email'), 'group' => 'stock', 'type' => 'bool', 'lang' => false, 'operators' => self::BOOL_OPERATORS],
            ['id' => 'available_now',        'label' => $l('Label when in-stock'), 'group' => 'stock',   'type' => 'text',    'lang' => true,  'operators' => self::TEXT_OPERATORS],
            ['id' => 'available_later',      'label' => $l('Label when out of stock'), 'group' => 'stock', 'type' => 'text',  'lang' => true,  'operators' => self::TEXT_OPERATORS],
            ['id' => 'available_date',       'label' => $l('Availability date (YYYY-MM-DD)'), 'group' => 'stock', 'type' => 'text', 'lang' => false, 'operators' => ['set', 'clear', 'skip']],
            ['id' => 'delivery_in_stock',    'label' => $l('Delivery time (in stock)'), 'group' => 'stock', 'type' => 'text', 'lang' => true, 'operators' => self::TEXT_OPERATORS],
            ['id' => 'delivery_out_stock',   'label' => $l('Delivery time (out of stock)'), 'group' => 'stock', 'type' => 'text', 'lang' => true, 'operators' => self::TEXT_OPERATORS],

            // ----- Product codes -----
            ['id' => 'ean13',                'label' => $l('EAN-13 / JAN barcode'),'group' => 'codes',   'type' => 'text',    'lang' => false, 'operators' => self::TEXT_OPERATORS,
                'format_hint' => 'EAN-13: exactly 13 digits. JAN: also 13 digits.',
                'pattern' => '^\d{13}$',
                'warn_same_value_on_batch' => true,
            ],
            ['id' => 'upc',                  'label' => $l('UPC barcode'),         'group' => 'codes',   'type' => 'text',    'lang' => false, 'operators' => self::TEXT_OPERATORS,
                'format_hint' => 'UPC-A: exactly 12 digits.',
                'pattern' => '^\d{12}$',
                'warn_same_value_on_batch' => true,
            ],
            ['id' => 'mpn',                  'label' => $l('MPN'),                 'group' => 'codes',   'type' => 'text',    'lang' => false, 'operators' => self::TEXT_OPERATORS,
                'format_hint' => 'Manufacturer Part Number: up to 40 characters.',
                'char_limit' => 40,
            ],
            ['id' => 'isbn',                 'label' => $l('ISBN'),                'group' => 'codes',   'type' => 'text',    'lang' => false, 'operators' => self::TEXT_OPERATORS,
                'format_hint' => 'ISBN-10 (10 digits, last may be X) or ISBN-13 (13 digits).',
                'pattern' => '^(\d{9}[\dX]|\d{13})$',
                'warn_same_value_on_batch' => true,
            ],

            // ----- Shipping -----
            ['id' => 'weight',               'label' => $l('Weight'),              'group' => 'shipping','type' => 'numeric', 'lang' => false, 'operators' => self::NUMERIC_OPERATORS, 'unit' => 'kg', 'min_value' => 0],
            ['id' => 'width',                'label' => $l('Width'),               'group' => 'shipping','type' => 'numeric', 'lang' => false, 'operators' => self::NUMERIC_OPERATORS, 'unit' => 'cm', 'min_value' => 0],
            ['id' => 'height',               'label' => $l('Height'),              'group' => 'shipping','type' => 'numeric', 'lang' => false, 'operators' => self::NUMERIC_OPERATORS, 'unit' => 'cm', 'min_value' => 0],
            ['id' => 'depth',                'label' => $l('Depth'),               'group' => 'shipping','type' => 'numeric', 'lang' => false, 'operators' => self::NUMERIC_OPERATORS, 'unit' => 'cm', 'min_value' => 0],
            ['id' => 'additional_shipping_cost', 'label' => $l('Additional shipping'), 'group' => 'shipping', 'type' => 'numeric', 'lang' => false, 'operators' => self::NUMERIC_OPERATORS, 'unit' => 'currency', 'min_value' => 0],

            // ----- Features (virtual field — writes to feature_product, not a product column) -----
            // Operator 'add' appends a new (feature, value) pair. PS allows multiple values for the
            // same feature on one product (e.g. Color = Red AND Color = Blue), so we never overwrite.
            // Operator 'clear' removes all values of a given feature from the product.
            ['id' => '_feature', 'label' => $l('Feature value'), 'group' => 'features', 'type' => 'feature', 'lang' => false, 'operators' => ['add', 'clear', 'skip']],
        ];
    }

    /**
     * @return array<int,array<string,mixed>> Fields grouped by 'group' key.
     */
    public static function byGroup(): array
    {
        $grouped = [];
        foreach (self::all() as $field) {
            $grouped[$field['group']][] = $field;
        }
        return $grouped;
    }

    /** @return array<string,mixed>|null */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $field) {
            if ($field['id'] === $id) return $field;
        }
        return null;
    }

    /**
     * Which table holds the field? Returns 'product', 'product_lang', 'product_shop',
     * 'stock_available', or 'feature_product' (virtual — writes to feature_product /
     * feature_value / feature_value_lang, handled specially in BulkEditorService).
     */
    public static function storageTable(string $id): string
    {
        // Virtual fields (custom apply logic, not a plain UPDATE)
        if ($id === '_feature') {
            return 'feature_product';
        }
        // product_lang-only
        if (in_array($id, [
            'name', 'description', 'description_short', 'meta_title', 'meta_description',
            'meta_keywords', 'link_rewrite', 'available_now', 'available_later',
            'delivery_in_stock', 'delivery_out_stock',
        ], true)) {
            return 'product_lang';
        }
        // product_shop overrides
        if (in_array($id, ['active', 'price', 'wholesale_price', 'available_for_order', 'show_price', 'online_only', 'visibility', 'minimal_quantity', 'redirect_type', 'on_sale'], true)) {
            return 'product_shop';
        }
        // stock_available
        if ($id === 'quantity') {
            return 'stock_available';
        }
        // default: product table
        return 'product';
    }
}
