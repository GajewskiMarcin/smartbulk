<?php
/**
 * SmartBulk — Content Health API
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Controller\Api;

use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use SmartBulk\Service\Health\ContentHealthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class HealthController extends PrestaShopAdminController
{
    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function scanAction(Request $request, ContentHealthService $health): JsonResponse
    {
        $idLang = $request->query->has('id_lang') ? (int) $request->query->get('id_lang') : null;
        $idShop = $request->query->has('id_shop') ? (int) $request->query->get('id_shop') : null;

        try {
            return new JsonResponse(['ok' => true, 'report' => $health->scan($idShop, $idLang)]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function productsAction(Request $request, ContentHealthService $health): JsonResponse
    {
        $problem = (string) $request->query->get('problem', '');
        $limit   = (int) $request->query->get('limit', 20);
        $offset  = (int) $request->query->get('offset', 0);
        $search  = (string) $request->query->get('search', '');
        $idLang  = $request->query->has('id_lang') ? (int) $request->query->get('id_lang') : null;
        $idShop  = $request->query->has('id_shop') ? (int) $request->query->get('id_shop') : null;

        if ($problem === '') {
            return new JsonResponse(['ok' => false, 'error' => 'Missing problem id'], 422);
        }

        try {
            $res = $health->listProducts($problem, $idShop, $idLang, [
                'limit'  => $limit,
                'offset' => $offset,
                'search' => $search,
            ]);
            return new JsonResponse([
                'ok'    => true,
                'items' => $res['items'],
                'total' => $res['total'],
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function snapshotsAction(Request $request, ContentHealthService $health): JsonResponse
    {
        $limit = (int) $request->query->get('limit', 30);
        $idShop = $request->query->has('id_shop') ? (int) $request->query->get('id_shop') : null;
        try {
            return new JsonResponse(['ok' => true, 'snapshots' => $health->listSnapshots($idShop, $limit)]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function productIdsAction(Request $request, ContentHealthService $health): JsonResponse
    {
        $problem = (string) $request->query->get('problem', '');
        $limit   = (int) $request->query->get('limit', 5000);
        $idLang  = $request->query->has('id_lang') ? (int) $request->query->get('id_lang') : null;
        $idShop  = $request->query->has('id_shop') ? (int) $request->query->get('id_shop') : null;

        if ($problem === '') {
            return new JsonResponse(['ok' => false, 'error' => 'Missing problem id'], 422);
        }

        try {
            return new JsonResponse([
                'ok'          => true,
                'problem'     => $problem,
                'product_ids' => $health->productIdsFor($problem, $idShop, $idLang, $limit),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
