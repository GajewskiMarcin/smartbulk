<?php
/**
 * SmartBulk — Dashboard API
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Controller\Api;

use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use SmartBulk\Service\Dashboard\DashboardService;
use Symfony\Component\HttpFoundation\JsonResponse;

final class DashboardController extends PrestaShopAdminController
{
    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function getAction(DashboardService $service): JsonResponse
    {
        try {
            return new JsonResponse(['ok' => true, 'data' => $service->build()]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
