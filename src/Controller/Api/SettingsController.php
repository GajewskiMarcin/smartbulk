<?php
/**
 * SmartBulk — Settings API controller
 *
 * GET  /smartbulk/api/settings   → current settings (secrets masked)
 * POST /smartbulk/api/settings   → persist submitted settings (JSON body)
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Controller\Api;

use SmartBulk\Controller\CompatAdminController;
use SmartBulk\Service\SettingsService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class SettingsController extends CompatAdminController
{
    public function getAction(SettingsService $settings): JsonResponse
    {
        $this->assertAccess('read');
        return new JsonResponse(['ok' => true, 'settings' => $settings->getAllMasked()]);
    }

    public function saveAction(Request $request, SettingsService $settings): JsonResponse
    {
        $this->assertAccess('update');
        $payload = json_decode((string) $request->getContent(), true) ?: [];
        if (!is_array($payload)) {
            return new JsonResponse(['ok' => false, 'error' => 'Invalid JSON'], 400);
        }

        try {
            $settings->save($payload);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        return new JsonResponse([
            'ok'       => true,
            'settings' => $settings->getAllMasked(),
        ]);
    }
}
