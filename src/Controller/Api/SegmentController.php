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
use SmartBulk\Controller\CompatAdminController;
use SmartBulk\Repository\SegmentRepository;
use SmartBulk\Service\Segment\PresetSegments;
use SmartBulk\Service\Segment\SegmentService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class SegmentController extends CompatAdminController
{
    public function presetsAction(PresetSegments $presets, SegmentService $segments): JsonResponse
    {
        $this->assertAccess('read');
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

    public function countAction(Request $request, SegmentService $segments): JsonResponse
    {
        $this->assertAccess('read');
        try {
            $spec = $this->jsonBody($request);
            return new JsonResponse(['ok' => true, 'count' => $segments->count($spec)]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function sampleAction(Request $request, SegmentService $segments): JsonResponse
    {
        $this->assertAccess('read');
        try {
            $spec  = $this->jsonBody($request);
            $limit = max(1, min(50, (int) ($spec['limit'] ?? 10)));
            return new JsonResponse(['ok' => true, 'products' => $segments->sample($spec, $limit)]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function productIdsAction(Request $request, SegmentService $segments): JsonResponse
    {
        $this->assertAccess('read');
        try {
            $spec  = $this->jsonBody($request);
            $limit = isset($spec['limit']) ? (int) $spec['limit'] : null;
            return new JsonResponse(['ok' => true, 'product_ids' => $segments->listProductIds($spec, null, null, $limit)]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ---- Saved segments CRUD ----

    public function listAction(SegmentRepository $repo): JsonResponse
    {
        $this->assertAccess('read');
        $idShop = (int) Context::getContext()->shop->id;
        $rows = $repo->listForShop($idShop);
        return new JsonResponse([
            'ok'       => true,
            'segments' => array_map([$this, 'normalize'], $rows),
        ]);
    }

    public function createAction(Request $request, SegmentRepository $repo): JsonResponse
    {
        $this->assertAccess('create');
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

    public function deleteAction(int $id, SegmentRepository $repo): JsonResponse
    {
        $this->assertAccess('delete');
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
