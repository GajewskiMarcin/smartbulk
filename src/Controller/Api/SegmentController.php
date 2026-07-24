<?php
/**
 * SmartBulk — Segment API controller (filter evaluation + saved segments)
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Controller\Api;

use Context;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use SmartBulk\Repository\SegmentRepository;
use SmartBulk\Service\Segment\PresetSegments;
use SmartBulk\Service\Segment\SegmentService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class SegmentController extends PrestaShopAdminController
{
    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function presetsAction(PresetSegments $presets, SegmentService $segments): JsonResponse
    {
        $out = [];
        foreach ($presets->list() as $preset) {
            $count = 0;
            try {
                $count = $segments->count(['filters' => $preset['filters']]);
            } catch (\Throwable $e) {
                // tolerate individual preset errors
            }
            $out[] = array_merge($preset, ['count' => $count]);
        }
        return new JsonResponse(['ok' => true, 'presets' => $out]);
    }

    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function countAction(Request $request, SegmentService $segments): JsonResponse
    {
        $spec = $this->jsonBody($request);
        return new JsonResponse(['ok' => true, 'count' => $segments->count($spec)]);
    }

    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function sampleAction(Request $request, SegmentService $segments): JsonResponse
    {
        $spec  = $this->jsonBody($request);
        $limit = max(1, min(50, (int) ($spec['limit'] ?? 10)));
        return new JsonResponse(['ok' => true, 'products' => $segments->sample($spec, $limit)]);
    }

    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function productIdsAction(Request $request, SegmentService $segments): JsonResponse
    {
        $spec  = $this->jsonBody($request);
        $limit = isset($spec['limit']) ? (int) $spec['limit'] : null;
        return new JsonResponse(['ok' => true, 'product_ids' => $segments->listProductIds($spec, null, null, $limit)]);
    }

    // ---- Saved segments CRUD ----

    #[AdminSecurity("is_granted('read', 'AdminSmartBulk')")]
    public function listAction(SegmentRepository $repo): JsonResponse
    {
        $idShop = (int) Context::getContext()->shop->id;
        $rows = $repo->listForShop($idShop);
        return new JsonResponse([
            'ok'       => true,
            'segments' => array_map([$this, 'normalize'], $rows),
        ]);
    }

    #[AdminSecurity("is_granted('create', 'AdminSmartBulk')")]
    public function createAction(Request $request, SegmentRepository $repo): JsonResponse
    {
        $payload = $this->jsonBody($request);
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return new JsonResponse(['ok' => false, 'error' => 'Name is required'], 422);
        }
        $conditions = $payload['conditions'] ?? ['filters' => []];
        if (!is_array($conditions)) {
            $conditions = ['filters' => []];
        }
        $ctx = Context::getContext();
        $id = $repo->create([
            'id_shop'     => (int) $ctx->shop->id,
            'name'        => $name,
            'description' => (string) ($payload['description'] ?? ''),
            'conditions'  => $conditions,
            'created_by'  => $ctx->employee ? (int) $ctx->employee->id : null,
        ]);
        $row = $repo->find($id);
        return new JsonResponse(['ok' => true, 'segment' => $row ? $this->normalize($row) : null], 201);
    }

    #[AdminSecurity("is_granted('delete', 'AdminSmartBulk')")]
    public function deleteAction(int $id, SegmentRepository $repo): JsonResponse
    {
        $repo->delete($id);
        return new JsonResponse(['ok' => true]);
    }

    /** @param array<string,mixed> $row */
    private function normalize(array $row): array
    {
        return [
            'id_segment'    => (int) $row['id_segment'],
            'name'          => (string) $row['name'],
            'description'   => (string) ($row['description'] ?? ''),
            'conditions'    => $row['conditions'] ? json_decode((string) $row['conditions'], true) : ['filters' => []],
            'combine_logic' => (string) $row['combine_logic'],
            'created_at'    => $row['created_at'],
            'updated_at'    => $row['updated_at'],
        ];
    }

    /** @return array<string,mixed> */
    private function jsonBody(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
