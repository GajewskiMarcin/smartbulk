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

use SmartBulk\Controller\CompatAdminController;
use SmartBulk\Service\Config\ConfigPortabilityService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class ConfigController extends CompatAdminController
{
    public function exportAction(ConfigPortabilityService $service): JsonResponse
    {
        $this->assertAccess('read');
        try {
            return new JsonResponse(['ok' => true, 'config' => $service->export()]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function importAction(Request $request, ConfigPortabilityService $service): JsonResponse
    {
        $this->assertAccess('update');
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
