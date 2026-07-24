<?php
/**
 * SmartBulk — Schedule API
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Controller\Api;

use InvalidArgumentException;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use SmartBulk\Service\Schedule\ScheduleService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class ScheduleController extends PrestaShopAdminController
{
    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function listAction(ScheduleService $service): JsonResponse
    {
        return new JsonResponse(['ok' => true, 'schedules' => $service->list()]);
    }

    #[AdminSecurity("is_granted('create', 'AdminSmartBulk')")]
    public function createAction(Request $request, ScheduleService $service): JsonResponse
    {
        $payload = $this->jsonBody($request);
        try {
            return new JsonResponse(['ok' => true, 'schedule' => $service->create($payload)], 201);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[AdminSecurity("is_granted('update', 'AdminSmartBulk')")]
    public function updateAction(int $id, Request $request, ScheduleService $service): JsonResponse
    {
        $payload = $this->jsonBody($request);
        try {
            return new JsonResponse(['ok' => true, 'schedule' => $service->update($id, $payload)]);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[AdminSecurity("is_granted('delete', 'AdminSmartBulk')")]
    public function deleteAction(int $id, ScheduleService $service): JsonResponse
    {
        try {
            $service->delete($id);
            return new JsonResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[AdminSecurity("is_granted('update', 'AdminSmartBulk')")]
    public function runNowAction(int $id, ScheduleService $service): JsonResponse
    {
        try {
            return new JsonResponse(['ok' => true, 'result' => $service->runNow($id)]);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Heartbeat — fires every due schedule. Designed to be hit by an
     * external cron (e.g. `* * * * * curl <URL>`) or by the BO heartbeat
     * hook. Read-only ACL is enough since the action is idempotent and
     * driven by next_run_at — calling it more often than needed is safe.
     */
    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function heartbeatAction(ScheduleService $service): JsonResponse
    {
        try {
            return new JsonResponse(['ok' => true, 'ran' => $service->runDue()]);
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
