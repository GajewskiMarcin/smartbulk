<?php
/**
 * SmartBulk — ProductHealthScorer
 *
 * Computes the per-product health snapshot (per group score + top issue)
 * that powers the Health column in the native PS product grid. Mirrors
 * the rules used by ContentHealthService::scan() but operates on a single
 * product so it can be triggered from product update hooks.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Health;

use Db;

final class ProductHealthScorer
{
    /**
     * Compute and persist the snapshot for one product.
     * Returns the structure that was stored, in case the caller wants to use it.
     *
     * @return array<string,mixed>
     */
    public function recompute(int $idProduct, int $idShop, int $idLang): array
    {
        $snapshot = $this->compute($idProduct, $idShop, $idLang);
        if ($snapshot === null) {
            // Product no longer exists in this shop — drop any stale row.
            $this->delete($idProduct, $idShop, $idLang);
            return ['removed' => true];
        }
        $this->persist($idProduct, $idShop, $idLang, $snapshot);
        return $snapshot;
    }

    public function delete(int $idProduct, int $idShop, ?int $idLang = null): void
    {
        $prefix = _DB_PREFIX_;
        $where = "id_product = " . (int) $idProduct . " AND id_shop = " . (int) $idShop;
        if ($idLang !== null) $where .= " AND id_lang = " . (int) $idLang;
        Db::getInstance()->execute("DELETE FROM `{$prefix}smartbulk_health_product` WHERE {$where}");
    }

    /**
     * Compute the snapshot without persisting. Returns null if the product
     * is not visible in the given shop (so caller can drop the cache row).
     *
     * @return array<string,mixed>|null
     */
    public function compute(int $idProduct, int $idShop, int $idLang): ?array
    {
        $prefix = _DB_PREFIX_;

        $row = Db::getInstance()->getRow(
            "SELECT
                ps.active,
                p.reference, p.ean13,
                pl.name, pl.meta_title, pl.meta_description, pl.link_rewrite,
                pl.description_short, pl.description,
                CHAR_LENGTH(pl.meta_title)        AS mt_len,
                CHAR_LENGTH(pl.meta_description)  AS md_len,
                CHAR_LENGTH(TRIM(COALESCE(pl.description_short,''))) AS sd_len,
                CHAR_LENGTH(TRIM(COALESCE(pl.description,'')))       AS dl_len
             FROM `{$prefix}product` p
             JOIN `{$prefix}product_shop` ps ON ps.id_product = p.id_product AND ps.id_shop = " . (int) $idShop . "
             LEFT JOIN `{$prefix}product_lang` pl
                 ON pl.id_product = p.id_product AND pl.id_shop = " . (int) $idShop . " AND pl.id_lang = " . (int) $idLang . "
             WHERE p.id_product = " . (int) $idProduct
        );
        if (!$row) return null;

        // Image checks — count of images and any image with empty legend in this lang.
        $imgCount = (int) Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM `{$prefix}image_shop` WHERE id_product = " . (int) $idProduct . " AND id_shop = " . (int) $idShop
        );
        $missingAlt = $imgCount > 0
            ? (int) Db::getInstance()->getValue(
                "SELECT COUNT(*) FROM `{$prefix}image` i
                 LEFT JOIN `{$prefix}image_lang` il ON il.id_image = i.id_image AND il.id_lang = " . (int) $idLang . "
                 WHERE i.id_product = " . (int) $idProduct . "
                   AND (il.legend IS NULL OR TRIM(il.legend) = '')"
            )
            : 0;

        // -- Per-problem flags -----------------------------------------------
        $issues = [];
        $seoFails = $contentFails = $codesFails = 0;

        // SEO
        if ($this->blank($row['meta_title']))                        { $issues[] = 'missing_meta_title';        $seoFails++; }
        if ($this->blank($row['meta_description']))                  { $issues[] = 'missing_meta_description';  $seoFails++; }
        elseif ((int) $row['md_len'] < 70)                           { $issues[] = 'meta_desc_too_short';       $seoFails++; }
        elseif ((int) $row['md_len'] > 160)                          { $issues[] = 'meta_desc_too_long';        $seoFails++; }
        if (!$this->blank($row['meta_title'])) {
            if ((int) $row['mt_len'] < 30)                           { $issues[] = 'meta_title_too_short';      $seoFails++; }
            elseif ((int) $row['mt_len'] > 60)                       { $issues[] = 'meta_title_too_long';       $seoFails++; }
        }
        if ($this->blank($row['link_rewrite']))                      { $issues[] = 'missing_link_rewrite';      $seoFails++; }

        // Content
        if ($this->blank($row['description_short']))                 { $issues[] = 'missing_short_desc';        $contentFails++; }
        elseif ((int) $row['sd_len'] < 80)                           { $issues[] = 'short_desc_too_short';      $contentFails++; }
        if ($this->blank($row['description']))                       { $issues[] = 'missing_description';       $contentFails++; }
        elseif ((int) $row['dl_len'] < 300)                          { $issues[] = 'description_too_short';     $contentFails++; }
        if ($imgCount === 0)                                         { $issues[] = 'missing_main_image';        $contentFails++; }
        if ($missingAlt > 0)                                         { $issues[] = 'missing_alt_text';          $contentFails++; }

        // Codes
        if ($this->blank($row['reference']))                         { $issues[] = 'missing_reference';         $codesFails++; }
        if ($this->blank($row['ean13']))                             { $issues[] = 'missing_ean';               $codesFails++; }

        // -- Scores -----------------------------------------------------------
        // Each group is scored as (1 - fails / max_fails) * 100 with sensible caps
        // so that 1 missing field doesn't drop the score to zero.
        $seoMax     = 7;
        $contentMax = 6;
        $codesMax   = 2;
        $seoScore     = $this->groupScore($seoFails,     $seoMax);
        $contentScore = $this->groupScore($contentFails, $contentMax);
        $codesScore   = $this->groupScore($codesFails,   $codesMax);
        $compositeScore = (int) round(($seoScore + $contentScore + $codesScore) / 3);

        $top = $this->topIssue($issues);
        $labeled = $this->labelIssues($issues);

        return [
            'seo_score'       => $seoScore,
            'content_score'   => $contentScore,
            'codes_score'     => $codesScore,
            'composite_score' => $compositeScore,
            'issues_count'    => count($issues),
            'top_issue'       => $top,
            'all_issues'      => array_values($issues),
            // Pre-formatted human label list for the grid Health column tooltip.
            'all_issues_text' => implode(' • ', $labeled),
        ];
    }

    /**
     * @param string[] $issues
     * @return string[]
     */
    private function labelIssues(array $issues): array
    {
        // Reuse the same id→label map as topIssue() so labels stay consistent.
        static $rank = null;
        if ($rank === null) {
            $rank = [
                'missing_meta_title'        => 'Missing meta title',
                'missing_meta_description'  => 'Missing meta description',
                'missing_short_desc'        => 'Missing short description',
                'missing_main_image'        => 'No product images',
                'missing_link_rewrite'      => 'Missing friendly URL',
                'missing_reference'         => 'Missing reference (SKU)',
                'missing_description'       => 'Missing long description',
                'missing_ean'               => 'Missing EAN/GTIN',
                'missing_alt_text'          => 'Images without alt text',
                'meta_title_too_short'      => 'Meta title < 30 chars',
                'meta_title_too_long'       => 'Meta title > 60 chars',
                'meta_desc_too_short'       => 'Meta description < 70',
                'short_desc_too_short'      => 'Short description < 80',
                'description_too_short'     => 'Long description < 300',
                'meta_desc_too_long'        => 'Meta description > 160',
            ];
        }
        $out = [];
        foreach ($issues as $id) {
            $out[] = $rank[$id] ?? $id;
        }
        return $out;
    }

    private function blank(mixed $v): bool
    {
        if ($v === null) return true;
        if (is_string($v)) return trim($v) === '';
        return false;
    }

    private function groupScore(int $fails, int $max): int
    {
        if ($max <= 0) return 100;
        $ratio = min(1.0, $fails / $max);
        return (int) round((1 - $ratio) * 100);
    }

    /**
     * Pick the most actionable problem to surface in the grid. Severity ranking
     * mirrors ContentHealthService::problems() so the front-end shows the same
     * high/medium labels we already chose.
     *
     * @param string[] $issues
     * @return array{id:string,label:string,severity:string}|null
     */
    private function topIssue(array $issues): ?array
    {
        if (!$issues) return null;

        // Severity → label table — ordered for fast pick. Subset of full catalogue;
        // anything not listed here gets demoted to 'low'.
        static $rank = null;
        if ($rank === null) {
            $rank = [
                // high
                'missing_meta_title'        => ['Missing meta title',        'high'],
                'missing_meta_description'  => ['Missing meta description',  'high'],
                'missing_short_desc'        => ['Missing short description', 'high'],
                'missing_main_image'        => ['No product images',         'high'],
                'missing_link_rewrite'      => ['Missing friendly URL',      'high'],
                'missing_reference'         => ['Missing reference (SKU)',   'high'],
                // medium
                'missing_description'       => ['Missing long description',  'medium'],
                'missing_ean'               => ['Missing EAN/GTIN',          'medium'],
                'missing_alt_text'          => ['Images without alt text',   'medium'],
                'meta_title_too_short'      => ['Meta title < 30 chars',     'medium'],
                'meta_title_too_long'       => ['Meta title > 60 chars',     'medium'],
                'meta_desc_too_short'       => ['Meta description < 70',     'medium'],
                // low
                'short_desc_too_short'      => ['Short description < 80',    'low'],
                'description_too_short'     => ['Long description < 300',    'low'],
                'meta_desc_too_long'        => ['Meta description > 160',    'low'],
            ];
        }

        $ranking = ['high' => 0, 'medium' => 1, 'low' => 2];
        $best = null;
        foreach ($issues as $id) {
            $entry = $rank[$id] ?? [$id, 'low'];
            $sev = $entry[1];
            if ($best === null || $ranking[$sev] < $ranking[$best['severity']]) {
                $best = ['id' => $id, 'label' => $entry[0], 'severity' => $sev];
                if ($sev === 'high') break; // can't beat that
            }
        }
        return $best;
    }

    /** @param array<string,mixed> $snap */
    private function persist(int $idProduct, int $idShop, int $idLang, array $snap): void
    {
        $prefix = _DB_PREFIX_;
        $issuesJson = pSQL((string) json_encode($snap), true);
        Db::getInstance()->execute(
            "INSERT INTO `{$prefix}smartbulk_health_product`
                (id_product, id_shop, id_lang, seo_score, content_score, issues, updated_at)
             VALUES (" . (int) $idProduct . ", " . (int) $idShop . ", " . (int) $idLang . ",
                     " . (int) $snap['seo_score'] . ", " . (int) $snap['content_score'] . ",
                     '{$issuesJson}', NOW())
             ON DUPLICATE KEY UPDATE
                seo_score = VALUES(seo_score),
                content_score = VALUES(content_score),
                issues = VALUES(issues),
                updated_at = NOW()"
        );
    }
}
