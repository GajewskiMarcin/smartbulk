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
use SmartBulk\Controller\CompatAdminController;
use SmartBulk\Service\Prompt\PromptService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class PromptController extends CompatAdminController
{
    public function listAction(Request $request, PromptService $service): JsonResponse
    {
        $this->assertAccess('read');
        $filters = [
            'task_type' => (string) $request->query->get('task_type', ''),
            'search'    => (string) $request->query->get('search', ''),
        ];
        $filters = array_filter($filters, static fn ($v) => $v !== '');
        return new JsonResponse(['ok' => true, 'prompts' => $service->listAll($filters)]);
    }

    public function detailAction(int $id, PromptService $service): JsonResponse
    {
        $this->assertAccess('read');
        try {
            return new JsonResponse(['ok' => true, ...$service->getDetail($id)]);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 404);
        }
    }

    public function createAction(Request $request, PromptService $service): JsonResponse
    {
        $this->assertAccess('create');
        $payload = $this->jsonBody($request);
        try {
            return new JsonResponse(['ok' => true, ...$service->createPrompt($payload)], 201);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function renameAction(int $id, Request $request, PromptService $service): JsonResponse
    {
        $this->assertAccess('update');
        $payload = $this->jsonBody($request);
        try {
            $service->renamePrompt($id, (string) ($payload['name'] ?? ''));
            return new JsonResponse(['ok' => true, ...$service->getDetail($id)]);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function createVersionAction(int $id, Request $request, PromptService $service): JsonResponse
    {
        $this->assertAccess('update');
        $payload = $this->jsonBody($request);
        try {
            return new JsonResponse(['ok' => true, ...$service->createVersion($id, $payload)], 201);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function activateVersionAction(int $id, int $versionId, PromptService $service): JsonResponse
    {
        $this->assertAccess('update');
        try {
            return new JsonResponse(['ok' => true, ...$service->setCurrentVersion($id, $versionId)]);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function deleteAction(int $id, PromptService $service): JsonResponse
    {
        $this->assertAccess('delete');
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
