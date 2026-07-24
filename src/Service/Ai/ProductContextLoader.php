<?php
/**
 * SmartBulk — ProductContextLoader
 *
 * Collects the fields used as prompt placeholders for a given product + lang:
 *   {name}, {category}, {brand}, {features}, {short_description},
 *   {description}, {focus_keyphrase}, {existing_text}, {price}.
 *
 * Returns strings only — empty string when a field is not available.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Ai;

use Category;
use Configuration;
use Context;
use Db;
use Language;
use Manufacturer;
use Product;

final class ProductContextLoader
{
    /**
     * @param int|null $targetLang Resolves the {target_lang} placeholder (ISO code).
     *                             If null, falls back to $idLang. Useful for translate tasks
     *                             where the product is read in the source lang but the
     *                             prompt needs to know the destination lang.
     * @return array<string,string>
     */
    public function load(int $idProduct, int $idLang, ?int $targetLang = null): array
    {
        if ($idProduct <= 0) {
            return $this->empty();
        }

        $product = new Product($idProduct, false, $idLang);
        if (!$product->id) {
            return $this->empty();
        }

        $idShop = (int) Context::getContext()->shop->id;

        $name = $this->str($product->name);
        $shortDesc = $this->stripTags((string) $product->description_short);
        $longDesc  = $this->stripTags((string) $product->description);
        $metaTitle = $this->str($product->meta_title);
        $metaDesc  = $this->str($product->meta_description);
        $metaKw    = $this->str($product->meta_keywords);
        $reference = (string) ($product->reference ?? '');
        $price     = $this->formatPrice((float) $product->price);

        $brand = '';
        $idManufacturer = (int) ($product->id_manufacturer ?? 0);
        if ($idManufacturer > 0) {
            $m = new Manufacturer($idManufacturer);
            $brand = (string) ($m->name ?? '');
        }

        $category = '';
        $idCategory = (int) ($product->id_category_default ?? 0);
        if ($idCategory > 0) {
            $cat = new Category($idCategory, $idLang);
            $category = (string) ($cat->name ?? '');
        }

        $features = $this->loadFeatures($idProduct, $idLang);
        $tags     = $this->loadTags($idProduct, $idLang);

        $langIso   = $this->langIso($idLang);
        $targetIso = $targetLang !== null ? $this->langIso($targetLang) : $langIso;

        return [
            'name'              => $name,
            'category'          => $category,
            'brand'             => $brand,
            'features'          => $features,
            'tags'              => $tags,
            'short_description' => $shortDesc,
            'description'       => $longDesc,
            'meta_title'        => $metaTitle,
            'meta_description'  => $metaDesc,
            'focus_keyphrase'   => $metaKw, // PS uses meta_keywords as focus keyphrase proxy
            'existing_text'     => '',       // caller can override (e.g. for translate)
            'reference'         => $reference,
            'price'             => $price,
            'target_lang'       => $targetIso,
            'source_lang'       => $langIso,
            'id_product'        => (string) $idProduct,
            'id_shop'           => (string) $idShop,
            'id_lang'           => (string) $idLang,
        ];
    }

    private function loadFeatures(int $idProduct, int $idLang): string
    {
        $rows = Db::getInstance()->executeS(
            'SELECT fl.name AS feature_name, fvl.value AS feature_value
             FROM `' . _DB_PREFIX_ . 'feature_product` fp
             JOIN `' . _DB_PREFIX_ . 'feature_lang` fl
                  ON fl.id_feature = fp.id_feature AND fl.id_lang = ' . (int) $idLang . '
             JOIN `' . _DB_PREFIX_ . 'feature_value_lang` fvl
                  ON fvl.id_feature_value = fp.id_feature_value AND fvl.id_lang = ' . (int) $idLang . '
             WHERE fp.id_product = ' . (int) $idProduct
        );
        if (!$rows) {
            return '';
        }
        $lines = [];
        foreach ($rows as $r) {
            $lines[] = trim((string) $r['feature_name']) . ': ' . trim((string) $r['feature_value']);
        }
        return implode('; ', $lines);
    }

    private function loadTags(int $idProduct, int $idLang): string
    {
        $rows = Db::getInstance()->executeS(
            'SELECT t.name
             FROM `' . _DB_PREFIX_ . 'product_tag` pt
             JOIN `' . _DB_PREFIX_ . 'tag` t
                  ON t.id_tag = pt.id_tag AND t.id_lang = ' . (int) $idLang . '
             WHERE pt.id_product = ' . (int) $idProduct
        );
        if (!$rows) {
            return '';
        }
        return implode(', ', array_map(static fn ($r) => trim((string) $r['name']), $rows));
    }

    private function langIso(int $idLang): string
    {
        $row = Db::getInstance()->getRow(
            'SELECT iso_code FROM `' . _DB_PREFIX_ . 'lang` WHERE id_lang = ' . (int) $idLang
        );
        return strtolower((string) ($row['iso_code'] ?? ''));
    }

    private function str(mixed $v): string
    {
        if (is_array($v)) {
            return '';
        }
        return trim((string) ($v ?? ''));
    }

    private function stripTags(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        // Collapse whitespace
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private function formatPrice(float $price): string
    {
        $currency = Context::getContext()->currency;
        $sign = $currency ? (string) $currency->sign : '';
        return number_format($price, 2, '.', ' ') . ($sign !== '' ? ' ' . $sign : '');
    }

    /**
     * @return array<string,string>
     */
    private function empty(): array
    {
        return [
            'name' => '', 'category' => '', 'brand' => '', 'features' => '', 'tags' => '',
            'short_description' => '', 'description' => '',
            'meta_title' => '', 'meta_description' => '',
            'focus_keyphrase' => '', 'existing_text' => '',
            'reference' => '', 'price' => '',
            'target_lang' => '', 'source_lang' => '',
            'id_product' => '0', 'id_shop' => '0', 'id_lang' => '0',
        ];
    }
}
