<?php
/**
 * SmartBulk — Preset segment definitions
 *
 * Hardcoded presets shown in the AI Assistant scope picker. Each preset is a
 * filter spec that SegmentService understands. Counts are computed live.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Segment;

use SmartBulk\Service\Lang;

final class PresetSegments
{
    /**
     * @return array<int,array{id:string,name:string,description:string,filters:array<int,array<string,mixed>>}>
     */
    public function list(): array
    {
        return [
            [
                'id'          => 'preset:missing_meta_title',
                'name'        => Lang::t('Missing meta title'),
                'description' => Lang::t('Products with no meta_title set in the current language'),
                'filters'     => [
                    ['type' => 'content_gaps', 'value' => ['missing_meta_title']],
                    ['type' => 'status',       'value' => ['active']],
                ],
            ],
            [
                'id'          => 'preset:missing_meta_description',
                'name'        => Lang::t('Missing meta description'),
                'description' => Lang::t('Active products with no meta_description in current lang'),
                'filters'     => [
                    ['type' => 'content_gaps', 'value' => ['missing_meta_description']],
                    ['type' => 'status',       'value' => ['active']],
                ],
            ],
            [
                'id'          => 'preset:short_desc_too_short',
                'name'        => Lang::t('Short description < 80 chars'),
                'description' => Lang::t('Active products with a very short short_description'),
                'filters'     => [
                    ['type' => 'content_gaps', 'value' => ['short_desc_lt_80']],
                    ['type' => 'status',       'value' => ['active']],
                ],
            ],
            [
                'id'          => 'preset:missing_description',
                'name'        => Lang::t('Missing long description'),
                'description' => Lang::t('Active products with no long description in current lang'),
                'filters'     => [
                    ['type' => 'content_gaps', 'value' => ['missing_description']],
                    ['type' => 'status',       'value' => ['active']],
                ],
            ],
            [
                'id'          => 'preset:missing_main_image',
                'name'        => Lang::t('Missing main image'),
                'description' => Lang::t('Products without a cover image — visibility blocker'),
                'filters'     => [
                    ['type' => 'content_gaps', 'value' => ['missing_main_image']],
                    ['type' => 'status',       'value' => ['active']],
                ],
            ],
            [
                'id'          => 'preset:missing_alt_text',
                'name'        => Lang::t('Images without alt text'),
                'description' => Lang::t('Products with at least one image missing alt attribute'),
                'filters'     => [
                    ['type' => 'content_gaps', 'value' => ['missing_alt_text']],
                    ['type' => 'status',       'value' => ['active']],
                ],
            ],
            [
                'id'          => 'preset:missing_ean',
                'name'        => Lang::t('Missing EAN / barcode'),
                'description' => Lang::t('Products without an EAN-13 barcode'),
                'filters'     => [
                    ['type' => 'content_gaps', 'value' => ['missing_ean']],
                    ['type' => 'status',       'value' => ['active']],
                ],
            ],
            [
                'id'          => 'preset:active_out_of_stock',
                'name'        => Lang::t('Active, out of stock'),
                'description' => Lang::t('Products still visible to customers but with no stock'),
                'filters'     => [
                    ['type' => 'status', 'value' => ['active', 'out_of_stock']],
                ],
            ],
            [
                'id'          => 'preset:never_sold',
                'name'        => Lang::t('Never sold'),
                'description' => Lang::t('Active products that have never appeared in an order'),
                'filters'     => [
                    ['type' => 'content_gaps', 'value' => ['never_sold']],
                    ['type' => 'status',       'value' => ['active']],
                ],
            ],
        ];
    }
}
