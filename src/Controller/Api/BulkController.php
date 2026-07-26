<?php
/**
 * SmartBulk — Bulk Editor API controller
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
use SmartBulk\Repository\ActionTemplateRepository;
use SmartBulk\Service\Bulk\ActionTemplates;
use SmartBulk\Service\Bulk\BulkEditorService;
use SmartBulk\Service\Bulk\FieldDefinitions;
use SmartBulk\Service\Segment\SegmentService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class BulkController extends PrestaShopAdminController
{
    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function fieldsAction(): JsonResponse
    {
        return new JsonResponse([
            'ok'     => true,
            'fields' => FieldDefinitions::all(),
        ]);
    }

    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function templatesAction(ActionTemplateRepository $repo): JsonResponse
    {
        $idShop = (int) \Context::getContext()->shop->id;
        return new JsonResponse([
            'ok'        => true,
            'templates' => ActionTemplates::allWithUserDefined($repo, $idShop),
        ]);
    }

    #[AdminSecurity("is_granted('create', 'AdminSmartBulk')")]
    public function createTemplateAction(Request $request, ActionTemplateRepository $repo): JsonResponse
    {
        $payload = $this->jsonBody($request);
        $name = trim((string) ($payload['name'] ?? ''));
        $actions = is_array($payload['actions'] ?? null) ? $payload['actions'] : [];
        if ($name === '' || !$actions) {
            return new JsonResponse(['ok' => false, 'error' => 'name and actions are required'], 422);
        }
        $ctx = \Context::getContext();
        $id = $repo->create([
            'id_shop'     => (int) $ctx->shop->id,
            'name'        => $name,
            'description' => (string) ($payload['description'] ?? ''),
            'actions'     => $actions,
            'created_by'  => $ctx->employee ? (int) $ctx->employee->id : null,
        ]);
        return new JsonResponse(['ok' => true, 'id' => $id], 201);
    }

    #[AdminSecurity("is_granted('delete', 'AdminSmartBulk')")]
    public function deleteTemplateAction(int $id, ActionTemplateRepository $repo): JsonResponse
    {
        $idShop = (int) \Context::getContext()->shop->id;
        $repo->delete($id, $idShop);
        return new JsonResponse(['ok' => true]);
    }

    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function previewAction(
        Request $request,
        BulkEditorService $service,
        SegmentService $segments
    ): JsonResponse {
        $payload = $this->jsonBody($request);
        $actions = is_array($payload['actions'] ?? null) ? $payload['actions'] : [];
        $idLang  = isset($payload['id_lang']) ? (int) $payload['id_lang'] : null;

        $productIds = [];
        if (isset($payload['product_ids']) && is_array($payload['product_ids'])) {
            $productIds = array_map('intval', $payload['product_ids']);
        } elseif (isset($payload['filter_spec']) && is_array($payload['filter_spec'])) {
            $productIds = $segments->listProductIds($payload['filter_spec']);
        }

        if (!$productIds) {
            return new JsonResponse(['ok' => false, 'error' => 'No products matched'], 422);
        }
        if (!$actions) {
            return new JsonResponse(['ok' => false, 'error' => 'No actions supplied'], 422);
        }

        try {
            $result = $service->previewSummary($productIds, $actions, $idLang);
            return new JsonResponse([
                'ok'          => true,
                'summary'     => $result['summary'],
                'diffs'       => $result['diffs'],
                'total_scope' => $result['total_scope'],
                'analyzed'    => $result['analyzed'],
                'truncated'   => $result['truncated'],
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[AdminSecurity("is_granted('update', 'AdminSmartBulk')")]
    public function createBatchAction(
        Request $request,
        BulkEditorService $service,
        SegmentService $segments
    ): JsonResponse {
        $payload = $this->jsonBody($request);
        $actions = is_array($payload['actions'] ?? null) ? $payload['actions'] : [];
        $idLang  = isset($payload['id_lang']) ? (int) $payload['id_lang'] : null;
        $mode    = (string) ($payload['mode'] ?? 'apply');

        $productIds = [];
        if (isset($payload['product_ids']) && is_array($payload['product_ids'])) {
            $productIds = array_map('intval', $payload['product_ids']);
        } elseif (isset($payload['filter_spec']) && is_array($payload['filter_spec'])) {
            $productIds = $segments->listProductIds($payload['filter_spec']);
        }

        $filterSpec = is_array($payload['filter_spec'] ?? null) ? $payload['filter_spec'] : null;

        try {
            return new JsonResponse([
                'ok'    => true,
                'batch' => $service->createBatch($productIds, $actions, $idLang, $filterSpec, $mode),
            ], 201);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[AdminSecurity("is_granted('update', 'AdminSmartBulk')")]
    public function processNextAction(int $id, Request $request, BulkEditorService $service): JsonResponse
    {
        $limit = max(1, min(1000, (int) $request->query->get('limit', 100)));
        try {
            return new JsonResponse(['ok' => true, 'batch' => $service->processNext($id, $limit)]);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function getBatchAction(int $id, Request $request, BulkEditorService $service): JsonResponse
    {
        try {
            $status = $service->getStatus($id);
            if ($request->query->get('include_items') === '1') {
                $status['items'] = $service->listItems($id);
            }
            return new JsonResponse(['ok' => true, 'batch' => $status]);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 404);
        }
    }

    #[AdminSecurity("is_granted('update', 'AdminSmartBulk')")]
    public function cancelAction(int $id, BulkEditorService $service): JsonResponse
    {
        try {
            return new JsonResponse(['ok' => true, 'batch' => $service->cancel($id)]);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[AdminSecurity("is_granted('update', 'AdminSmartBulk')")]
    public function resumeAction(int $id, BulkEditorService $service): JsonResponse
    {
        try {
            return new JsonResponse(['ok' => true, 'batch' => $service->resume($id)]);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[AdminSecurity("is_granted('update', 'AdminSmartBulk')")]
    public function undoAction(int $id, BulkEditorService $service): JsonResponse
    {
        try {
            return new JsonResponse(['ok' => true, 'result' => $service->undo($id)]);
        } catch (InvalidArgumentException $e) {
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
