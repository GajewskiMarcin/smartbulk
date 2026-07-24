<?php
/**
 * SmartBulk — ContentHealthService
 *
 * Scans the product catalogue against a set of quality checks grouped into
 * SEO / Content / Codes. Returns per-problem counts and per-group scores.
 * Each problem maps to a content-gap field in FieldCatalog, so the UI can
 * hand the corresponding product_ids straight into the Bulk Editor.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Health;

use Context;
use Db;

final class ContentHealthService
{
    public function __construct(
        private readonly ProductHealthScorer $scorer,
    ) {
    }

    /**
     * Problem catalogue.
     *
     * Each entry: id, group, label, severity, fix_hint, and a 'condition_id'
     * that points to a FieldCatalog content_gap field so the UI can jump to
     * Bulk Editor with pre-filled conditions.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function problems(): array
    {
        return [
            // ----- SEO -----
            ['id' => 'missing_meta_title',        'group' => 'seo',     'label' => 'Missing meta title',              'severity' => 'high',   'condition_id' => 'missing_meta_title',       'fix_hint' => 'Run AI generate on meta_title for these products.'],
            ['id' => 'missing_meta_description',  'group' => 'seo',     'label' => 'Missing meta description',        'severity' => 'high',   'condition_id' => 'missing_meta_description', 'fix_hint' => 'Run AI generate or "Generate from short description".'],
            ['id' => 'meta_title_too_short',      'group' => 'seo',     'label' => 'Meta title < 30 chars',           'severity' => 'medium', 'condition_id' => null,                        'fix_hint' => 'Rewrite or regenerate via AI to fill to ~55 chars.'],
            ['id' => 'meta_title_too_long',       'group' => 'seo',     'label' => 'Meta title > 60 chars',           'severity' => 'medium', 'condition_id' => null,                        'fix_hint' => 'Will be truncated in Google SERP — trim to ≤ 60.'],
            ['id' => 'meta_desc_too_short',       'group' => 'seo',     'label' => 'Meta description < 70 chars',     'severity' => 'medium', 'condition_id' => null,                        'fix_hint' => 'Expand to 120–160 chars for better SERP CTR.'],
            ['id' => 'meta_desc_too_long',        'group' => 'seo',     'label' => 'Meta description > 160 chars',    'severity' => 'low',    'condition_id' => null,                        'fix_hint' => 'Trimming reduces truncation risk.'],
            ['id' => 'missing_link_rewrite',      'group' => 'seo',     'label' => 'Missing friendly URL',            'severity' => 'high',   'condition_id' => null,                        'fix_hint' => 'Use "Regenerate friendly URLs" template.'],

            // ----- Content -----
            ['id' => 'missing_short_desc',        'group' => 'content', 'label' => 'Missing short description',       'severity' => 'high',   'condition_id' => 'missing_short_desc',  'fix_hint' => 'AI-generate short_desc from name + features.'],
            ['id' => 'missing_description',       'group' => 'content', 'label' => 'Missing long description',        'severity' => 'medium', 'condition_id' => 'missing_description', 'fix_hint' => 'AI-generate long_desc from product context.'],
            ['id' => 'short_desc_too_short',      'group' => 'content', 'label' => 'Short description < 80 chars',    'severity' => 'low',    'condition_id' => null,                    'fix_hint' => 'Shallow short-desc hurts category-page conversion.'],
            ['id' => 'description_too_short',     'group' => 'content', 'label' => 'Long description < 300 chars',    'severity' => 'low',    'condition_id' => null,                    'fix_hint' => 'Product pages with thin content rank poorly.'],
            ['id' => 'missing_main_image',        'group' => 'content', 'label' => 'No product images',               'severity' => 'high',   'condition_id' => 'missing_any_image',     'fix_hint' => 'Upload images manually — not a bulk-editable field.'],
            ['id' => 'missing_alt_text',          'group' => 'content', 'label' => 'Images without alt text',         'severity' => 'medium', 'condition_id' => 'missing_alt_text',      'fix_hint' => 'Enable alt text AI generation (roadmap).'],

            // ----- Codes -----
            ['id' => 'missing_reference',         'group' => 'codes',   'label' => 'Missing reference (SKU)',         'severity' => 'high',   'condition_id' => 'missing_reference', 'fix_hint' => 'Set unique reference per product. Not safe in bulk set.'],
            ['id' => 'missing_ean',               'group' => 'codes',   'label' => 'Missing EAN/GTIN barcode',        'severity' => 'medium', 'condition_id' => 'missing_ean',       'fix_hint' => 'Required by Google Merchant Center for most categories.'],
        ];
    }

    /**
     * Run the full scan. Returns totals, per-problem counts, per-group scores.
     * @return array<string,mixed>
     */
    public function scan(?int $idShop = null, ?int $idLang = null): array
    {
        $ctx  = Context::getContext();
        $shop = $idShop ?? (int) $ctx->shop->id;
        $lang = $idLang ?? (int) $ctx->language->id;

        $prefix = _DB_PREFIX_;

        $total = (int) Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM `{$prefix}product_shop` WHERE id_shop = {$shop} AND active = 1"
        );

        if ($total === 0) {
            return [
                'generated_at' => date('c'),
                'id_shop'      => $shop,
                'id_lang'      => $lang,
                'total'        => 0,
                'problems'     => [],
                'groups'       => self::emptyGroups(),
            ];
        }

        // One combined query over product/product_shop/product_lang for SEO + Content text checks.
        $textRow = Db::getInstance()->getRow(
            "SELECT
                SUM(CASE WHEN TRIM(COALESCE(pl.meta_title,''))       = '' THEN 1 ELSE 0 END) AS missing_meta_title,
                SUM(CASE WHEN TRIM(COALESCE(pl.meta_description,'')) = '' THEN 1 ELSE 0 END) AS missing_meta_description,
                SUM(CASE WHEN CHAR_LENGTH(pl.meta_title) BETWEEN 1 AND 29 THEN 1 ELSE 0 END)     AS meta_title_too_short,
                SUM(CASE WHEN CHAR_LENGTH(pl.meta_title) > 60 THEN 1 ELSE 0 END)                 AS meta_title_too_long,
                SUM(CASE WHEN CHAR_LENGTH(pl.meta_description) BETWEEN 1 AND 69 THEN 1 ELSE 0 END)  AS meta_desc_too_short,
                SUM(CASE WHEN CHAR_LENGTH(pl.meta_description) > 160 THEN 1 ELSE 0 END)             AS meta_desc_too_long,
                SUM(CASE WHEN TRIM(COALESCE(pl.link_rewrite,''))     = '' THEN 1 ELSE 0 END) AS missing_link_rewrite,
                SUM(CASE WHEN TRIM(COALESCE(pl.description_short,'')) = '' THEN 1 ELSE 0 END) AS missing_short_desc,
                SUM(CASE WHEN TRIM(COALESCE(pl.description,''))       = '' THEN 1 ELSE 0 END) AS missing_description,
                SUM(CASE WHEN CHAR_LENGTH(TRIM(COALESCE(pl.description_short,''))) BETWEEN 1 AND 79 THEN 1 ELSE 0 END)  AS short_desc_too_short,
                SUM(CASE WHEN CHAR_LENGTH(TRIM(COALESCE(pl.description,'')))       BETWEEN 1 AND 299 THEN 1 ELSE 0 END) AS description_too_short,
                SUM(CASE WHEN TRIM(COALESCE(p.reference,'')) = '' THEN 1 ELSE 0 END) AS missing_reference,
                SUM(CASE WHEN TRIM(COALESCE(p.ean13,''))     = '' THEN 1 ELSE 0 END) AS missing_ean
             FROM `{$prefix}product` p
             JOIN `{$prefix}product_shop` ps ON ps.id_product = p.id_product AND ps.id_shop = {$shop}
             LEFT JOIN `{$prefix}product_lang` pl
                 ON pl.id_product = p.id_product AND pl.id_shop = {$shop} AND pl.id_lang = {$lang}
             WHERE ps.active = 1"
        );

        // Images: missing_main_image (no rows in image for the product) + missing_alt_text (any image without legend).
        $missingMainImage = (int) Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM `{$prefix}product_shop` ps
             LEFT JOIN `{$prefix}image_shop` imgs
                 ON imgs.id_product = ps.id_product AND imgs.id_shop = ps.id_shop
             WHERE ps.id_shop = {$shop} AND ps.active = 1
               AND imgs.id_image IS NULL"
        );

        $missingAltText = (int) Db::getInstance()->getValue(
            "SELECT COUNT(DISTINCT i.id_product)
             FROM `{$prefix}image_shop` imgs
             JOIN `{$prefix}image` i ON i.id_image = imgs.id_image
             JOIN `{$prefix}product_shop` ps ON ps.id_product = i.id_product AND ps.id_shop = {$shop}
             LEFT JOIN `{$prefix}image_lang` il
                 ON il.id_image = i.id_image AND il.id_lang = {$lang}
             WHERE imgs.id_shop = {$shop} AND ps.active = 1
               AND (il.legend IS NULL OR TRIM(il.legend) = '')"
        );

        $counts = [];
        foreach (self::problems() as $p) {
            $id = $p['id'];
            $counts[$id] = match ($id) {
                'missing_main_image' => $missingMainImage,
                'missing_alt_text'   => $missingAltText,
                default              => (int) ($textRow[$id] ?? 0),
            };
        }

        // Per-group affected products (distinct count with OR across all problems in the group).
        $groups = [
            'seo'     => [
                'label'    => 'SEO',
                'affected' => $this->countSeoAffected($shop, $lang),
                'total'    => $total,
            ],
            'content' => [
                'label'    => 'Content',
                'affected' => $this->countContentAffected($shop, $lang) + $missingMainImage,
                'total'    => $total,
            ],
            'codes'   => [
                'label'    => 'Product codes',
                'affected' => $this->countCodesAffected($shop),
                'total'    => $total,
            ],
        ];
        foreach ($groups as $gid => &$g) {
            $g['affected'] = min($g['affected'], $total);
            $g['healthy']  = $total - $g['affected'];
            $g['score']   = $total > 0 ? (int) round(($g['healthy'] / $total) * 100) : 100;
        }
        unset($g);

        // Build problem rows — include counts and group metadata.
        $problems = [];
        foreach (self::problems() as $p) {
            $n = $counts[$p['id']] ?? 0;
            $problems[] = [
                'id'           => $p['id'],
                'group'        => $p['group'],
                'label'        => $p['label'],
                'severity'     => $p['severity'],
                'condition_id' => $p['condition_id'],
                'fix_hint'     => $p['fix_hint'],
                'count'        => $n,
                'percent'      => $total > 0 ? round(($n / $total) * 100, 1) : 0,
            ];
        }

        // Refresh per-product cache so the native PS product grid can show
        // health badges without recomputing on every render. Capped to 5000
        // rows per scan to keep the request bounded; larger catalogues should
        // run this from a scheduled job.
        $this->refreshProductCache($shop, $lang);

        // Persist a shop-level snapshot so Dashboard can plot a trend.
        $this->writeSnapshot($shop, $total, $groups, $counts ?? []);

        return [
            'generated_at' => date('c'),
            'id_shop'      => $shop,
            'id_lang'      => $lang,
            'total'        => $total,
            'problems'     => $problems,
            'groups'       => $groups,
        ];
    }

    /**
     * Append a row to smartbulk_health_snapshot. Trims to keep the most
     * recent N entries per shop so the table doesn't grow forever.
     *
     * @param array<string,array<string,mixed>> $groups
     * @param array<string,int> $counts
     */
    private function writeSnapshot(int $idShop, int $total, array $groups, array $counts): void
    {
        $prefix = _DB_PREFIX_;
        $metrics = [
            'total'        => $total,
            'composite'    => count($groups) > 0 ? (int) round(array_sum(array_map(static fn ($g) => (int) $g['score'], $groups)) / count($groups)) : 100,
            'seo_score'    => (int) ($groups['seo']['score']     ?? 100),
            'content_score'=> (int) ($groups['content']['score'] ?? 100),
            'codes_score'  => (int) ($groups['codes']['score']   ?? 100),
            'issue_counts' => $counts,
        ];
        try {
            \Db::getInstance()->insert('smartbulk_health_snapshot', [
                'id_shop'        => $idShop,
                'taken_at'       => date('Y-m-d H:i:s'),
                'products_total' => $total,
                'metrics'        => pSQL((string) json_encode($metrics), true),
            ]);
            // Keep only the last 90 entries per shop.
            \Db::getInstance()->execute(
                "DELETE FROM `{$prefix}smartbulk_health_snapshot`
                 WHERE id_shop = " . (int) $idShop . "
                   AND id_snapshot NOT IN (
                       SELECT id_snapshot FROM (
                           SELECT id_snapshot FROM `{$prefix}smartbulk_health_snapshot`
                           WHERE id_shop = " . (int) $idShop . "
                           ORDER BY taken_at DESC LIMIT 90
                       ) keep
                   )"
            );
        } catch (\Throwable $e) {
            // Snapshot is best-effort — swallow.
        }
    }

    /**
     * @return array<int,array<string,mixed>>  Recent snapshots, oldest first.
     */
    public function listSnapshots(?int $idShop = null, int $limit = 30): array
    {
        $shop = $idShop ?? (int) Context::getContext()->shop->id;
        $prefix = _DB_PREFIX_;
        $rows = Db::getInstance()->executeS(
            "SELECT id_snapshot, taken_at, products_total, metrics
             FROM `{$prefix}smartbulk_health_snapshot`
             WHERE id_shop = " . (int) $shop . "
             ORDER BY taken_at DESC
             LIMIT " . (int) $limit
        ) ?: [];
        $rows = array_reverse($rows);
        return array_map(static function (array $r) {
            $m = $r['metrics'] ? json_decode((string) $r['metrics'], true) : [];
            return [
                'id_snapshot'    => (int) $r['id_snapshot'],
                'taken_at'       => (string) $r['taken_at'],
                'products_total' => (int) $r['products_total'],
                'composite'      => (int) ($m['composite']     ?? 0),
                'seo_score'      => (int) ($m['seo_score']     ?? 0),
                'content_score'  => (int) ($m['content_score'] ?? 0),
                'codes_score'    => (int) ($m['codes_score']   ?? 0),
            ];
        }, $rows);
    }

    /**
     * Recompute and persist the per-product health snapshot for every active
     * product × every active language in the shop. The product grid in BO
     * may render in a different language than the scan was triggered from,
     * so we precompute all of them. Bounded by MAX_CACHE_REFRESH so a giant
     * catalogue doesn't kill the request — caller can retry.
     */
    private function refreshProductCache(int $idShop, int $idLang): void
    {
        $prefix = _DB_PREFIX_;
        $rows = Db::getInstance()->executeS(
            "SELECT ps.id_product
             FROM `{$prefix}product_shop` ps
             WHERE ps.id_shop = " . (int) $idShop . " AND ps.active = 1
             ORDER BY ps.id_product ASC
             LIMIT 5000"
        ) ?: [];
        if (!$rows) return;

        // All active languages for this shop. Falls back to the trigger lang if the
        // helper isn't available in this PS minor version.
        $langs = [];
        if (class_exists('\Language')) {
            $langRows = \Language::getLanguages(true, $idShop) ?: [];
            foreach ($langRows as $l) $langs[] = (int) $l['id_lang'];
        }
        if (!$langs) $langs = [$idLang];

        foreach ($rows as $r) {
            $idProduct = (int) $r['id_product'];
            foreach ($langs as $idLangIter) {
                try {
                    $this->scorer->recompute($idProduct, $idShop, $idLangIter);
                } catch (\Throwable $e) {
                    // best-effort — never let cache write break the scan
                }
            }
        }
    }

    /**
     * Paginated, searchable drill-down of affected products for a single
     * problem. Each row carries a `preview` string showing the relevant field
     * value so the user can judge severity without leaving the Health view.
     *
     * @param array{limit?:int,offset?:int,search?:string} $opts
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public function listProducts(string $problemId, ?int $idShop = null, ?int $idLang = null, array $opts = []): array
    {
        $ctx  = Context::getContext();
        $shop = $idShop ?? (int) $ctx->shop->id;
        $lang = $idLang ?? (int) $ctx->language->id;
        $prefix = _DB_PREFIX_;

        $limit  = max(1, min(100, (int) ($opts['limit']  ?? 20)));
        $offset = max(0, (int) ($opts['offset'] ?? 0));
        $search = trim((string) ($opts['search'] ?? ''));

        [$from, $selectId, $searchAlias] = $this->fromForProblem($problemId, $shop, $lang);
        if ($from === null) {
            return ['items' => [], 'total' => 0];
        }

        $searchSql = '';
        if ($search !== '') {
            $esc = pSQL($search);
            // Numeric search → product id; otherwise name/reference LIKE
            if (ctype_digit($search)) {
                $searchSql = " AND {$searchAlias}.id_product = " . (int) $search;
            } else {
                $searchSql = " AND (pl.name LIKE '%{$esc}%' OR p.reference LIKE '%{$esc}%')";
            }
        }

        // Build reusable JOINs to read name/reference for any problem.
        $joinsForDisplay = "
            LEFT JOIN `{$prefix}product` p      ON p.id_product = {$searchAlias}.id_product
            LEFT JOIN `{$prefix}product_lang` pl ON pl.id_product = {$searchAlias}.id_product AND pl.id_shop = {$shop} AND pl.id_lang = {$lang}
        ";

        $total = (int) Db::getInstance()->getValue(
            "SELECT COUNT({$selectId}) {$from} {$joinsForDisplay} {$searchSql}"
        );

        if ($total === 0) return ['items' => [], 'total' => 0];

        $previewExpr = $this->previewExprForProblem($problemId);

        $rows = Db::getInstance()->executeS(
            "SELECT {$selectId} AS id_product,
                    pl.name       AS name,
                    p.reference   AS reference,
                    {$previewExpr} AS preview
             {$from} {$joinsForDisplay} {$searchSql}
             GROUP BY {$selectId}
             ORDER BY {$selectId} ASC
             LIMIT {$offset}, {$limit}"
        ) ?: [];

        return [
            'items' => array_map(static fn (array $r) => [
                'id_product' => (int) $r['id_product'],
                'name'       => (string) ($r['name'] ?? ''),
                'reference'  => (string) ($r['reference'] ?? ''),
                'preview'    => (string) ($r['preview'] ?? ''),
            ], $rows),
            'total' => $total,
        ];
    }

    /**
     * Resolve a list of product IDs affected by a single problem — used by the
     * "Fix in Bulk Editor" button to create a pre-filtered batch.
     *
     * @return int[]
     */
    public function productIdsFor(string $problemId, ?int $idShop = null, ?int $idLang = null, int $limit = 5000): array
    {
        $ctx  = Context::getContext();
        $shop = $idShop ?? (int) $ctx->shop->id;
        $lang = $idLang ?? (int) $ctx->language->id;
        $prefix = _DB_PREFIX_;

        $where = $this->whereForProblem($problemId);
        if ($where === null) return [];

        $from = "FROM `{$prefix}product` p
                 JOIN `{$prefix}product_shop` ps ON ps.id_product = p.id_product AND ps.id_shop = {$shop}
                 LEFT JOIN `{$prefix}product_lang` pl
                     ON pl.id_product = p.id_product AND pl.id_shop = {$shop} AND pl.id_lang = {$lang}
                 WHERE ps.active = 1 AND ({$where})";

        if ($problemId === 'missing_main_image') {
            $from = "FROM `{$prefix}product_shop` ps
                     LEFT JOIN `{$prefix}image_shop` imgs
                         ON imgs.id_product = ps.id_product AND imgs.id_shop = ps.id_shop
                     WHERE ps.id_shop = {$shop} AND ps.active = 1 AND imgs.id_image IS NULL";
            $select = 'SELECT ps.id_product';
        } elseif ($problemId === 'missing_alt_text') {
            $from = "FROM `{$prefix}image_shop` imgs
                     JOIN `{$prefix}image` i ON i.id_image = imgs.id_image
                     JOIN `{$prefix}product_shop` ps ON ps.id_product = i.id_product AND ps.id_shop = {$shop}
                     LEFT JOIN `{$prefix}image_lang` il
                         ON il.id_image = i.id_image AND il.id_lang = {$lang}
                     WHERE imgs.id_shop = {$shop} AND ps.active = 1
                       AND (il.legend IS NULL OR TRIM(il.legend) = '')";
            $select = 'SELECT DISTINCT i.id_product';
        } else {
            $select = 'SELECT p.id_product';
        }

        $rows = Db::getInstance()->executeS("{$select} {$from} LIMIT " . (int) $limit) ?: [];
        return array_map(static fn ($r) => (int) $r['id_product'], $rows);
    }

    // =====================================================================
    // Internals
    // =====================================================================

    private function countSeoAffected(int $shop, int $lang): int
    {
        $prefix = _DB_PREFIX_;
        return (int) Db::getInstance()->getValue(
            "SELECT COUNT(DISTINCT p.id_product)
             FROM `{$prefix}product` p
             JOIN `{$prefix}product_shop` ps ON ps.id_product = p.id_product AND ps.id_shop = {$shop}
             LEFT JOIN `{$prefix}product_lang` pl
                 ON pl.id_product = p.id_product AND pl.id_shop = {$shop} AND pl.id_lang = {$lang}
             WHERE ps.active = 1 AND (
                   TRIM(COALESCE(pl.meta_title,''))       = ''
                OR TRIM(COALESCE(pl.meta_description,'')) = ''
                OR CHAR_LENGTH(pl.meta_title) BETWEEN 1 AND 29
                OR CHAR_LENGTH(pl.meta_title) > 60
                OR CHAR_LENGTH(pl.meta_description) BETWEEN 1 AND 69
                OR CHAR_LENGTH(pl.meta_description) > 160
                OR TRIM(COALESCE(pl.link_rewrite,''))     = ''
             )"
        );
    }

    private function countContentAffected(int $shop, int $lang): int
    {
        $prefix = _DB_PREFIX_;
        return (int) Db::getInstance()->getValue(
            "SELECT COUNT(DISTINCT p.id_product)
             FROM `{$prefix}product` p
             JOIN `{$prefix}product_shop` ps ON ps.id_product = p.id_product AND ps.id_shop = {$shop}
             LEFT JOIN `{$prefix}product_lang` pl
                 ON pl.id_product = p.id_product AND pl.id_shop = {$shop} AND pl.id_lang = {$lang}
             WHERE ps.active = 1 AND (
                   TRIM(COALESCE(pl.description_short,'')) = ''
                OR TRIM(COALESCE(pl.description,''))       = ''
                OR CHAR_LENGTH(TRIM(COALESCE(pl.description_short,''))) BETWEEN 1 AND 79
                OR CHAR_LENGTH(TRIM(COALESCE(pl.description,'')))       BETWEEN 1 AND 299
             )"
        );
    }

    private function countCodesAffected(int $shop): int
    {
        $prefix = _DB_PREFIX_;
        return (int) Db::getInstance()->getValue(
            "SELECT COUNT(DISTINCT p.id_product)
             FROM `{$prefix}product` p
             JOIN `{$prefix}product_shop` ps ON ps.id_product = p.id_product AND ps.id_shop = {$shop}
             WHERE ps.active = 1 AND (
                   TRIM(COALESCE(p.reference,'')) = ''
                OR TRIM(COALESCE(p.ean13,''))     = ''
             )"
        );
    }

    /**
     * Builds the FROM clause for a problem. Returns [from, selectId, alias] or
     * [null, ...] if the problem id is unknown.
     * The "alias" is the table alias whose id_product is canonical for this query
     * (used by search/count to avoid ambiguity across joins).
     *
     * @return array{0:?string,1:string,2:string}
     */
    private function fromForProblem(string $id, int $shop, int $lang): array
    {
        $prefix = _DB_PREFIX_;
        $where = $this->whereForProblem($id);
        if ($where === null) return [null, '', ''];

        if ($id === 'missing_main_image') {
            return [
                "FROM `{$prefix}product_shop` ps
                 LEFT JOIN `{$prefix}image_shop` imgs
                     ON imgs.id_product = ps.id_product AND imgs.id_shop = ps.id_shop
                 WHERE ps.id_shop = {$shop} AND ps.active = 1 AND imgs.id_image IS NULL",
                'ps.id_product',
                'ps',
            ];
        }
        if ($id === 'missing_alt_text') {
            return [
                "FROM `{$prefix}image_shop` imgs
                 JOIN `{$prefix}image` i ON i.id_image = imgs.id_image
                 JOIN `{$prefix}product_shop` ps ON ps.id_product = i.id_product AND ps.id_shop = {$shop}
                 LEFT JOIN `{$prefix}image_lang` il
                     ON il.id_image = i.id_image AND il.id_lang = {$lang}
                 WHERE imgs.id_shop = {$shop} AND ps.active = 1
                   AND (il.legend IS NULL OR TRIM(il.legend) = '')",
                'i.id_product',
                'i',
            ];
        }

        // Text/code problems share the same base.
        return [
            "FROM `{$prefix}product_shop` ps
             WHERE ps.id_shop = {$shop} AND ps.active = 1 AND ({$where})",
            'ps.id_product',
            'ps',
        ];
    }

    /** A short, human-readable preview of the offending field value for a given problem. */
    private function previewExprForProblem(string $id): string
    {
        return match ($id) {
            'missing_meta_title',
            'missing_meta_description',
            'missing_link_rewrite',
            'missing_short_desc',
            'missing_description' => "''",

            'meta_title_too_short', 'meta_title_too_long' =>
                "CONCAT(IFNULL(pl.meta_title,''), ' · ', CAST(CHAR_LENGTH(pl.meta_title) AS CHAR), ' chars')",
            'meta_desc_too_short', 'meta_desc_too_long' =>
                "CONCAT(IFNULL(pl.meta_description,''), ' · ', CAST(CHAR_LENGTH(pl.meta_description) AS CHAR), ' chars')",
            'short_desc_too_short' =>
                "CONCAT(CAST(CHAR_LENGTH(TRIM(COALESCE(pl.description_short,''))) AS CHAR), ' chars')",
            'description_too_short' =>
                "CONCAT(CAST(CHAR_LENGTH(TRIM(COALESCE(pl.description,''))) AS CHAR), ' chars')",

            'missing_reference', 'missing_ean' => "''",
            default => "''",
        };
    }

    private function whereForProblem(string $id): ?string
    {
        return match ($id) {
            'missing_meta_title'       => "TRIM(COALESCE(pl.meta_title,'')) = ''",
            'missing_meta_description' => "TRIM(COALESCE(pl.meta_description,'')) = ''",
            'meta_title_too_short'     => "CHAR_LENGTH(pl.meta_title) BETWEEN 1 AND 29",
            'meta_title_too_long'      => "CHAR_LENGTH(pl.meta_title) > 60",
            'meta_desc_too_short'      => "CHAR_LENGTH(pl.meta_description) BETWEEN 1 AND 69",
            'meta_desc_too_long'       => "CHAR_LENGTH(pl.meta_description) > 160",
            'missing_link_rewrite'     => "TRIM(COALESCE(pl.link_rewrite,'')) = ''",
            'missing_short_desc'       => "TRIM(COALESCE(pl.description_short,'')) = ''",
            'missing_description'      => "TRIM(COALESCE(pl.description,'')) = ''",
            'short_desc_too_short'     => "CHAR_LENGTH(TRIM(COALESCE(pl.description_short,''))) BETWEEN 1 AND 79",
            'description_too_short'    => "CHAR_LENGTH(TRIM(COALESCE(pl.description,''))) BETWEEN 1 AND 299",
            'missing_reference'        => "TRIM(COALESCE(p.reference,'')) = ''",
            'missing_ean'              => "TRIM(COALESCE(p.ean13,'')) = ''",
            'missing_main_image'       => 'main_image',
            'missing_alt_text'         => 'alt_text',
            default                    => null,
        };
    }

    /** @return array<string,array<string,mixed>> */
    private static function emptyGroups(): array
    {
        return [
            'seo'     => ['label' => 'SEO',           'affected' => 0, 'healthy' => 0, 'score' => 100, 'total' => 0],
            'content' => ['label' => 'Content',       'affected' => 0, 'healthy' => 0, 'score' => 100, 'total' => 0],
            'codes'   => ['label' => 'Product codes', 'affected' => 0, 'healthy' => 0, 'score' => 100, 'total' => 0],
        ];
    }
}
