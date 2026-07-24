<?php
/**
 * SmartBulk — Configuration export / import API
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Controller\Api;

use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use SmartBulk\Service\Config\ConfigPortabilityService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class ConfigController extends PrestaShopAdminController
{
    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function exportAction(ConfigPortabilityService $service): JsonResponse
    {
        try {
            return new JsonResponse(['ok' => true, 'config' => $service->export()]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[AdminSecurity("is_granted('update', 'AdminSmartBulk')")]
    public function importAction(Request $request, ConfigPortabilityService $service): JsonResponse
    {
        $payload = $this->jsonBody($request);
        $config = is_array($payload['config'] ?? null) ? $payload['config'] : null;
        if ($config === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Missing "config" payload'], 422);
        }
        $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];
        try {
            return new JsonResponse(['ok' => true, 'report' => $service->import($config, $options)]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** @return array<string,mixed> */
    private function jsonBody(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
