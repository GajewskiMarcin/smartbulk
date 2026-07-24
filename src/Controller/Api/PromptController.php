<?php
/**
 * SmartBulk — Prompt API controller
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
use SmartBulk\Service\Prompt\PromptService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class PromptController extends PrestaShopAdminController
{
    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function listAction(Request $request, PromptService $service): JsonResponse
    {
        $filters = [
            'task_type' => (string) $request->query->get('task_type', ''),
            'search'    => (string) $request->query->get('search', ''),
        ];
        $filters = array_filter($filters, static fn ($v) => $v !== '');
        return new JsonResponse(['ok' => true, 'prompts' => $service->listAll($filters)]);
    }

    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function detailAction(int $id, PromptService $service): JsonResponse
    {
        try {
            return new JsonResponse(['ok' => true, ...$service->getDetail($id)]);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 404);
        }
    }

    #[AdminSecurity("is_granted('create', 'AdminSmartBulk')")]
    public function createAction(Request $request, PromptService $service): JsonResponse
    {
        $payload = $this->jsonBody($request);
        try {
            return new JsonResponse(['ok' => true, ...$service->createPrompt($payload)], 201);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[AdminSecurity("is_granted('update', 'AdminSmartBulk')")]
    public function renameAction(int $id, Request $request, PromptService $service): JsonResponse
    {
        $payload = $this->jsonBody($request);
        try {
            $service->renamePrompt($id, (string) ($payload['name'] ?? ''));
            return new JsonResponse(['ok' => true, ...$service->getDetail($id)]);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    #[AdminSecurity("is_granted('update', 'AdminSmartBulk')")]
    public function createVersionAction(int $id, Request $request, PromptService $service): JsonResponse
    {
        $payload = $this->jsonBody($request);
        try {
            return new JsonResponse(['ok' => true, ...$service->createVersion($id, $payload)], 201);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    #[AdminSecurity("is_granted('update', 'AdminSmartBulk')")]
    public function activateVersionAction(int $id, int $versionId, PromptService $service): JsonResponse
    {
        try {
            return new JsonResponse(['ok' => true, ...$service->setCurrentVersion($id, $versionId)]);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    #[AdminSecurity("is_granted('delete', 'AdminSmartBulk')")]
    public function deleteAction(int $id, PromptService $service): JsonResponse
    {
        try {
            $service->deletePrompt($id);
            return new JsonResponse(['ok' => true]);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 404);
        }
    }

    /** @return array<string,mixed> */
    private function jsonBody(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
