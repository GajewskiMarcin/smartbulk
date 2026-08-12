<?php
/**
 * SmartBulk — Product API (lightweight product lookup for pickers)
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Controller\Api;

use Context;
use Db;
use SmartBulk\Controller\CompatAdminController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class ProductController extends CompatAdminController
{
    public function searchAction(Request $request): JsonResponse
    {
        $this->assertAccess('read');
        $q = trim((string) $request->query->get('q', ''));
        $limit = min(20, max(1, (int) $request->query->get('limit', 10)));

        $ctx = Context::getContext();
        $idLang = (int) $ctx->language->id;
        $idShop = (int) $ctx->shop->id;

        $where = ['p.id_product > 0'];
        if ($q !== '') {
            $like = pSQL($q);
            if (ctype_digit($q)) {
                $where[] = '(p.id_product = ' . (int) $q . ' OR pl.name LIKE "%' . $like . '%" OR p.reference LIKE "%' . $like . '%")';
            } else {
                $where[] = '(pl.name LIKE "%' . $like . '%" OR p.reference LIKE "%' . $like . '%")';
            }
        }

        $sql = 'SELECT p.id_product, p.reference, p.price, pl.name
                FROM `' . _DB_PREFIX_ . 'product` p
                JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                     ON ps.id_product = p.id_product AND ps.id_shop = ' . $idShop . '
                JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                     ON pl.id_product = p.id_product AND pl.id_lang = ' . $idLang . ' AND pl.id_shop = ' . $idShop . '
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY p.id_product DESC
                LIMIT ' . $limit;

        $rows = Db::getInstance()->executeS($sql) ?: [];
        $products = array_map(static fn (array $r) => [
            'id_product' => (int) $r['id_product'],
            'name'       => (string) $r['name'],
            'reference'  => (string) $r['reference'],
            'price'      => (float) $r['price'],
        ], $rows);

        return new JsonResponse(['ok' => true, 'products' => $products]);
    }
}
